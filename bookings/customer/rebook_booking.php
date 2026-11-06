<?php
// ============================================================
// ملف: bookings/customer/rebook_booking.php
// الغرض: إعادة حجز خدمة سبق للعميل تنفيذها
//
// قاعدة العمل المطلوبة:
//   لا تظهر إعادة الحجز إلا إذا كان الحجز القديم:
//     - مكتملاً (booking_status = completed)، أو
//     - مدفوعاً (booking_payment_status = paid).
//   وهذا يمنع إعادة الحجز أثناء انتظار الموافقة أو التنفيذ.
//
// ماذا يفعل؟
//   1) يتحقق من ملكية الحجز القديم.
//   2) يتحقق من استحقاق إعادة الحجز.
//   3) يقرأ السعر الحالي للخدمة وعمولة المقدم (A).
//   4) يقرأ عمولة العميل (B) من app_settings وقت الإنشاء.
//   5) يُنشئ حجزاً جديداً بحالة pending وغير مدفوع.
//
// ملاحظة:
//   نستخدم السعر الحالي للخدمة وليس السعر القديم، لأن الأسعار
//   والعمولات قد تتغير من الإدارة أو من مقدم الخدمة.
//
// الطريقة: POST
// ============================================================

// ربط قاعدة البيانات والدوال العامة
include "../../connect.php";

// تضمين دوال الإشعارات لإبلاغ الإدارة بالحجز الجديد
include "../../includes/notification_hooks.php";

// تضمين دوال الدفع للوصول إلى إعدادات app_settings بنفس الطريقة
include "../../payments/includes/payment_helper.php";

// ============================================================
// الخطوة 1: استقبال المدخلات
// ============================================================
// معرّف العميل صاحب الحجز القديم
$userId = (int) filterRequest("booking_users_id");

// معرّف الحجز القديم المطلوب إعادته
$oldBookingId = (int) filterRequest("booking_id");

// تفاصيل الطلب الجديدة (اختياري) - إن لم تُرسل نستخدم تفاصيل القديم
$newOrderDetails = trim(filterRequest("booking_order_details"));

// الحقلان إجباريان
if ($userId <= 0 || $oldBookingId <= 0) {
    printFailure("booking_users_id and booking_id are required");
    exit;
}

// ============================================================
// الخطوة 2: قراءة الحجز القديم والتحقق من الملكية
// ============================================================
$stmtOld = $con->prepare(
    "SELECT booking_id, booking_users_id, booking_ps_id, booking_order_details,
            booking_status, booking_payment_status
     FROM bookings
     WHERE booking_id = ?
     LIMIT 1"
);
$stmtOld->execute([$oldBookingId]);
$oldBooking = $stmtOld->fetch();

// الحجز غير موجود
if (!$oldBooking) {
    printFailure("Booking not found", 404);
    exit;
}

// لا يمكن إعادة حجز لا يخص هذا المستخدم
if ((int) $oldBooking['booking_users_id'] !== $userId) {
    printFailure("This booking does not belong to this user", 403);
    exit;
}

// ============================================================
// الخطوة 3: التحقق من استحقاق إعادة الحجز
// ============================================================
$isCompleted = $oldBooking['booking_status'] === 'completed';
$isPaid = $oldBooking['booking_payment_status'] === 'paid';

// إعادة الحجز متاحة فقط عند الاكتمال أو الدفع
if (!$isCompleted && !$isPaid) {
    printFailure("Booking must be completed or paid before rebooking", 409);
    exit;
}

// ============================================================
// الخطوة 4: قراءة الخدمة الحالية (السعر والعمولات)
// ============================================================
$psId = (int) $oldBooking['booking_ps_id'];

$stmtService = $con->prepare(
    "SELECT ps.ps_id, ps.ps_user_id, ps.ps_price, ps.ps_negotiable,
            ps.ps_commission_type, ps.ps_commission_value, ps.ps_commission_amount
     FROM provider_services ps
     INNER JOIN users u ON u.users_id = ps.ps_user_id
     WHERE ps.ps_id = ? AND u.users_is_active = 1 AND u.users_approve = 1
     LIMIT 1"
);
$stmtService->execute([$psId]);
$service = $stmtService->fetch();

// الخدمة غير متاحة حالياً
if (!$service) {
    printFailure("Service is not available for rebooking", 409);
    exit;
}

// منع حجز مقدم الخدمة لخدمته
if ((int) $service['ps_user_id'] === $userId) {
    printFailure("You cannot book your own service", 409);
    exit;
}

// ============================================================
// الخطوة 5: منع وجود حجز نشط لنفس الخدمة
// ============================================================
$stmtDup = $con->prepare(
    "SELECT booking_id FROM bookings
     WHERE booking_users_id = ? AND booking_ps_id = ?
       AND booking_status IN ('pending','negotiating','approved','in_progress')
     LIMIT 1"
);
$stmtDup->execute([$userId, $psId]);

// يوجد حجز نشط بالفعل
if ($stmtDup->fetch()) {
    printFailure("You already have an active booking for this service", 409);
    exit;
}

// ============================================================
// الخطوة 6: حساب السعر وعمولة المقدم (A)
// ============================================================
// السعر الأساسي لمقدم الخدمة
$providerBasePrice = (float) $service['ps_price'];

// نوع عمولة المقدم
$providerCommissionType = $service['ps_commission_type'] ?: 'percent';

// قيمة عمولة المقدم
$providerCommissionValue = (float) $service['ps_commission_value'];

// المبلغ المخزّن مسبقاً في الخدمة (إن وُجد)
$providerCommissionAmount = (float) ($service['ps_commission_amount'] ?? 0);

// حساب المبلغ عند عدم وجوده مخزناً
if ($providerCommissionAmount <= 0) {
    if ($providerCommissionType === 'percent') {
        $providerCommissionAmount = ($providerBasePrice * $providerCommissionValue) / 100;
    } else {
        $providerCommissionAmount = $providerCommissionValue;
    }
}

// تقريب مبلغ عمولة المقدم
$providerCommissionAmount = round((float) $providerCommissionAmount, 2);

// السعر الظاهر للعميل شامل عمولة المقدم
$servicePriceForClient = round($providerBasePrice + $providerCommissionAmount, 2);

// ============================================================
// الخطوة 7: قراءة عمولة العميل (B) من الإعدادات
// ============================================================
$settings = getAppSettings($con);
$feeSettings = getPaymentFeeSettings($settings);

// نوع عمولة العميل (النسبة الافتراضية 0%)
$clientCommissionType = (string) ($settings['client_commission_type'] ?? 'percent');
$clientCommissionType = $clientCommissionType === 'fixed' ? 'fixed' : 'percent';

// قيمة عمولة العميل
$clientCommissionValue = (float) ($settings['client_commission_value'] ?? 0);

// حساب مبلغ عمولة العميل من سعر مقدم الخدمة الأساسي
if ($clientCommissionType === 'percent') {
    $clientCommissionAmount = ($providerBasePrice * $clientCommissionValue) / 100;
} else {
    $clientCommissionAmount = $clientCommissionValue;
}

// تقريب عمولة العميل
$clientCommissionAmount = round((float) $clientCommissionAmount, 2);

// لا كوبون في إعادة الحجز افتراضياً
$couponDiscountAmount = 0;

// ============================================================
// الخطوة 8: حساب الإجمالي النهائي
// ============================================================
$totalPrice = round($servicePriceForClient + $clientCommissionAmount - $couponDiscountAmount, 2);

// ============================================================
// الخطوة 9: إدخال الحجز الجديد
// ============================================================
$insert = $con->prepare(
    "INSERT INTO bookings
        (booking_users_id, booking_ps_id, booking_order_details,
         booking_ps_price, booking_ps_negotiable,
         booking_ps_commission_type, booking_ps_commission_value, booking_ps_commission_amount,
         booking_client_commission_type, booking_client_commission_value, booking_client_commission_amount,
         booking_coupon, booking_total_price, booking_confirmation,
         booking_vailability_status, booking_approval, booking_status,
         booking_payment_status, booking_number_of_requests)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 1, ?, 0, 'pending', 'unpaid', 0)"
);

// تنفيذ الإدخال
$insert->execute([
    $userId,
    $psId,
    $newOrderDetails !== '' ? $newOrderDetails : (string) $oldBooking['booking_order_details'],
    $providerBasePrice,
    (int) $service['ps_negotiable'],
    $providerCommissionType,
    $providerCommissionValue,
    $providerCommissionAmount,
    $clientCommissionType,
    $clientCommissionValue,
    $clientCommissionAmount,
    $totalPrice,
    date("Y-m-d H:i:s"),
]);

// معرّف الحجز الجديد
$newBookingId = (int) $con->lastInsertId();

// ============================================================
// الخطوة 10: إشعار الإدارة بالحجز الجديد
// ============================================================
try {
    // قراءة اسم العميل للإشعار
    $stmtClient = $con->prepare(
        "SELECT users_id, users_full_name FROM users WHERE users_id = ? LIMIT 1"
    );
    $stmtClient->execute([$userId]);
    $client = $stmtClient->fetch() ?: [];

    // إرسال إشعار «حجز جديد» بنفس دالة الحجز العادية
    notifyBookingCreated(['booking_id' => $newBookingId, 'booking_ps_id' => $psId], $client);
} catch (Throwable $e) {
    // لا نُفشل العملية بسبب الإشعار
}

// ============================================================
// الخطوة 11: إرجاع بيانات الحجز الجديد لـ Flutter
// ============================================================
printSuccess([
    "booking_id"                  => $newBookingId,
    "booking_ps_id"               => $psId,
    "booking_status"              => "pending",
    "booking_payment_status"      => "unpaid",
    "booking_ps_price"            => $providerBasePrice,
    "booking_ps_commission_amount" => $providerCommissionAmount,
    "service_price_for_client"    => $servicePriceForClient,
    "booking_client_commission_type"  => $clientCommissionType,
    "booking_client_commission_value" => $clientCommissionValue,
    "booking_client_commission_amount" => $clientCommissionAmount,
    "booking_total_price"         => $totalPrice,
    "message"                     => "Rebooking created successfully",
]);
