<?php
// ============================================================
// ملف: payments/customer/create_payment.php
// الغرض: إنشاء عملية دفع جديدة لحجز معيّن عبر Paymob
//
// مَن يستدعي هذا الملف؟
//   تطبيق Flutter عند ضغط العميل على زر «ادفع الآن».
//
// ماذا يفعل بالترتيب؟
//   1) يتحقق من المدخلات ومن ملكية العميل للحجز.
//   2) يمنع الدفع إذا لم توافق الإدارة على الحجز.
//   3) يمنع الدفع المكرر إذا كان الحجز مدفوعاً بالفعل.
//   4) يحسب رسوم الدفع (1% بحد أدنى 5 جنيه، قابلة للتعديل).
//   5) ينشئ صفاً في جدول payments بحالة pending.
//   6) ينشئ Order وPayment Key لدى Paymob.
//   7) يُعيد للعميل رابط الدفع (IFrame للكارت أو صفحة المحفظة).
//
// الطريقة: POST
// ============================================================

// ربط قاعدة البيانات والدوال العامة (filterRequest / printSuccess / printFailure)
include "../../connect.php";

// تضمين الدوال المساعدة للدفع
include "../includes/payment_helper.php";

// ============================================================
// الخطوة 1: استقبال المدخلات
// ============================================================

// معرّف العميل صاحب الحجز (إجباري)
$payment_user_id = (int) filterRequest("payment_user_id");

// معرّف الحجز المطلوب دفعه (إجباري)
$payment_booking_id = (int) filterRequest("payment_booking_id");

// وسيلة الدفع: card = كارت، wallet = محفظة إلكترونية
$payment_method = strtolower(trim(filterRequest("payment_method")));

// معرّف كارت محفوظ (اختياري) - عند الدفع بكارت سبق حفظه
$payment_card_id = (int) filterRequest("payment_card_id");

// هل يطلب العميل حفظ الكارت بعد نجاح الدفع؟ (1 = نعم)
$payment_save_card = filterRequest("payment_save_card") === "1";

// رقم المحفظة الإلكترونية (إجباري عند الدفع بالمحفظة)
$payment_wallet_phone = trim(filterRequest("payment_wallet_phone"));

// ============================================================
// الخطوة 2: التحقق من صحة المدخلات الأساسية
// ============================================================
if ($payment_user_id <= 0 || $payment_booking_id <= 0) {
    // رسالة واضحة تفهمها Flutter
    printFailure("payment_user_id and payment_booking_id are required");
    exit;
}

// وسيلة الدفع يجب أن تكون card أو wallet
if (!in_array($payment_method, ['card', 'wallet'], true)) {
    printFailure("payment_method must be card or wallet");
    exit;
}

// الدفع بالمحفظة يتطلب رقم محفظة
if ($payment_method === 'wallet' && $payment_wallet_phone === '') {
    printFailure("payment_wallet_phone is required for wallet payment");
    exit;
}

// ============================================================
// الخطوة 3: التحقق من المستخدم
// ============================================================
$stmtUser = $con->prepare(
    "SELECT users_id, users_full_name, users_phone, users_type
     FROM users
     WHERE users_id = ? AND users_is_active = 1 AND users_approve = 1
     LIMIT 1"
);
$stmtUser->execute([$payment_user_id]);
$user = $stmtUser->fetch();

// المستخدم غير موجود أو غير مفعّل
if (!$user) {
    printFailure("User not found or account is not active", 404);
    exit;
}

// ============================================================
// الخطوة 4: قراءة الحجز والتأكد من ملكيته
// ============================================================
$stmtBooking = $con->prepare(
    "SELECT b.booking_id, b.booking_users_id, b.booking_ps_id,
            b.booking_total_price, b.booking_status, b.booking_approval,
            b.booking_payment_status, ps.ps_user_id
     FROM bookings b
     INNER JOIN provider_services ps ON ps.ps_id = b.booking_ps_id
     WHERE b.booking_id = ?
     LIMIT 1"
);
$stmtBooking->execute([$payment_booking_id]);
$booking = $stmtBooking->fetch();

// الحجز غير موجود
if (!$booking) {
    printFailure("Booking not found", 404);
    exit;
}

// لا يمكن دفع حجز لا يخص هذا العميل
if ((int) $booking['booking_users_id'] !== $payment_user_id) {
    printFailure("This booking does not belong to this user", 403);
    exit;
}

// لا يمكن دفع حجز ملغي
if ($booking['booking_status'] === 'cancelled') {
    printFailure("Cancelled booking cannot be paid", 409);
    exit;
}

// الدفع متاح فقط بعد موافقة الإدارة (قاعدة العمل المطلوبة)
if ((int) $booking['booking_approval'] !== 1) {
    printFailure("Booking is not approved yet", 409);
    exit;
}

// منع الدفع المكرر لنفس الحجز
if ($booking['booking_payment_status'] === 'paid') {
    printFailure("Booking is already paid", 409);
    exit;
}

// قيمة الحجز يجب أن تكون أكبر من صفر
$bookingAmount = (float) $booking['booking_total_price'];
if ($bookingAmount <= 0) {
    printFailure("Booking amount is invalid", 409);
    exit;
}

// ============================================================
// الخطوة 5: التحقق من الكارت المحفوظ إن اختير
// ============================================================
$savedCard = null;

if ($payment_card_id > 0) {
    // الكارت يجب أن يكون ملك العميل ونشطاً
    $stmtCard = $con->prepare(
        "SELECT card_id, card_token, card_mask, card_last4, card_brand
         FROM payment_cards
         WHERE card_id = ? AND card_user_id = ? AND card_is_active = 1
         LIMIT 1"
    );
    $stmtCard->execute([$payment_card_id, $payment_user_id]);
    $savedCard = $stmtCard->fetch();

    // كارت غير صالح
    if (!$savedCard) {
        printFailure("Saved card not found", 404);
        exit;
    }

    // الكارت المحفوظ يُعتبر دفعاً بالكارت
    $payment_method = 'card';
}

// ============================================================
// الخطوة 6: حساب رسوم الدفع
// ============================================================
$settings = getAppSettings($con);
$feeSettings = getPaymentFeeSettings($settings);
$fee = calculatePaymentFee($bookingAmount, $feeSettings);

// الإجمالي الذي سيُخصم فعلياً من العميل
$totalAmount = money($bookingAmount + (float) $fee['amount']);

// ============================================================
// الخطوة 7: إلغاء أي محاولة دفع معلقة سابقة لنفس الحجز
//         حتى لا تتراكم عمليات مفتوحة عند إعادة المحاولة.
// ============================================================
$cancelOld = $con->prepare(
    "UPDATE payments
     SET payment_state = 'cancelled'
     WHERE payment_booking_id = ? AND payment_user_id = ? AND payment_state IN ('pending','processing')"
);
$cancelOld->execute([$payment_booking_id, $payment_user_id]);

// ============================================================
// الخطوة 8: إنشاء رقم مرجعي داخلي وإدخال صف الدفع
// ============================================================
$reference = generatePaymentReference($payment_booking_id, $payment_user_id);

// إدخال الصف بحالة pending قبل الاتصال بالبوابة
$insertPayment = $con->prepare(
    "INSERT INTO payments
        (payment_user_id, payment_booking_id, payment_card_id, payment_provider,
         payment_method, payment_reference, payment_amount,
         payment_fee_type, payment_fee_value, payment_fee_amount,
         payment_total_amount, payment_currency, payment_state)
     VALUES (?, ?, ?, 'paymob', ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
);
$insertPayment->execute([
    $payment_user_id,
    $payment_booking_id,
    $savedCard ? (int) $savedCard['card_id'] : null,
    $savedCard ? 'saved_card' : $payment_method,
    $reference,
    money($bookingAmount),
    $fee['type'],
    money((float) $fee['value']),
    money((float) $fee['amount']),
    $totalAmount,
    (string) paymobConfigValue('currency', 'EGP'),
]);

// معرّف صف الدفع الجديد
$paymentId = (int) $con->lastInsertId();

// تحديث حالة الحجز إلى pending (قيد الدفع)
$updateBooking = $con->prepare(
    "UPDATE bookings SET booking_payment_status = 'pending' WHERE booking_id = ?"
);
$updateBooking->execute([$payment_booking_id]);

// ============================================================
// الخطوة 9: اختيار Integration ID المناسب لوسيلة الدفع
// ============================================================
if ($savedCard) {
    // الدفع بكارت محفوظ
    $integrationId = (string) paymobConfigValue('integration_id_saved_card', '');
    if ($integrationId === '') {
        $integrationId = (string) ($settings['paymob_integration_id_saved_card'] ?? '');
    }
    if ($integrationId === '') {
        $integrationId = (string) paymobConfigValue('integration_id_card', '');
    }
} elseif ($payment_method === 'wallet') {
    // الدفع بمحفظة إلكترونية
    $integrationId = (string) paymobConfigValue('integration_id_wallet', '');
    if ($integrationId === '') {
        $integrationId = (string) ($settings['paymob_integration_id_wallet'] ?? '');
    }
} else {
    // الدفع بكارت جديد
    $integrationId = (string) paymobConfigValue('integration_id_card', '');
    if ($integrationId === '') {
        $integrationId = (string) ($settings['paymob_integration_id_card'] ?? '');
    }
}

// لا يمكن المتابعة بدون Integration ID
if ($integrationId === '') {
    printFailure("Payment gateway is not configured", 503);
    exit;
}

// ============================================================
// الخطوة 10: استدعاء Paymob
// ============================================================
try {
    // (أ) الحصول على توكن التوثيق
    $authToken = paymobAuthToken();

    // (ب) إنشاء الطلب بالقيمة الإجمالية (الحجز + الرسوم)
    $orderId = paymobCreateOrder($authToken, $totalAmount, $reference);

    // (ج) إنشاء مفتاح الدفع
    $paymentKey = paymobCreatePaymentKey(
        $authToken,
        $orderId,
        $totalAmount,
        $integrationId,
        $payment_user_id,
        (string) $user['users_full_name'],
        (string) $user['users_phone'],
        $payment_save_card && !$savedCard,
        $savedCard ? (string) $savedCard['card_token'] : null
    );

    // (د) تحديد رابط الدفع
    if ($payment_method === 'wallet') {
        // المحفظة: نطلب من Paymob إنشاء صفحة دفع على المحفظة
        $paymentUrl = paymobWalletPay($paymentKey, $payment_wallet_phone);
    } else {
        // الكارت: صفحة IFrame الرسمية
        $paymentUrl = paymobIframeUrl($paymentKey);
    }

    // (هـ) حفظ رقم الطلب لدى البوابة في صف الدفع
    $updateGateway = $con->prepare(
        "UPDATE payments SET payment_gateway_order_id = ?, payment_state = 'processing' WHERE payment_id = ?"
    );
    $updateGateway->execute([(string) $orderId, $paymentId]);

    // (و) إرجاع البيانات لتطبيق Flutter
    printSuccess([
        "payment_id" => $paymentId,
        "payment_reference" => $reference,
        "payment_method" => $savedCard ? 'saved_card' : $payment_method,
        "payment_amount" => money($bookingAmount),
        "payment_fee_amount" => money((float) $fee['amount']),
        "payment_fee_type" => $fee['type'],
        "payment_fee_value" => money((float) $fee['value']),
        "payment_total_amount" => $totalAmount,
        "payment_currency" => (string) paymobConfigValue('currency', 'EGP'),
        "payment_url" => $paymentUrl,
        "card_last4" => $savedCard ? (string) $savedCard['card_last4'] : null,
    ]);
} catch (Throwable $e) {
    // في حال فشل الاتصال بالبوابة نُحدّث الصف إلى failed
    $fail = $con->prepare(
        "UPDATE payments SET payment_state = 'failed', payment_failure_reason = ? WHERE payment_id = ?"
    );
    $fail->execute([$e->getMessage(), $paymentId]);

    // إعادة حالة الحجز إلى unpaid حتى يمكن المحاولة لاحقاً
    $reset = $con->prepare(
        "UPDATE bookings SET booking_payment_status = 'unpaid' WHERE booking_id = ?"
    );
    $reset->execute([$payment_booking_id]);

    // رسالة عامة للعميل (بدون كشف تفاصيل السيرفر)
    printFailure("Unable to start payment. Please try again later.", 502);
}
