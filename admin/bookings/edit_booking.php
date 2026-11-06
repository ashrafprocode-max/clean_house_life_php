<?php
// ============================================================
// ملف: bookings/admin/edit_booking.php
// الغرض: تعديل أي حقل من حقول جدول bookings بواسطة الإدارة
//
// ⭐ سيناريو العمل:
//   1. العميل يرسل الحجز فتكون حالته pending وسعره من provider_services
//   2. الإدارة تراجع تفاصيل الطلب وتتواصل مع العميل ومقدم الخدمة
//   3. الإدارة تعدّل السعر أو العمولة بناءً على الاتفاق
//   4. الإدارة تحدد booking_vailability_status وتضع booking_approval = 1
//
// ⭐ الحقل الإجباري الوحيد: booking_id
//   وباقي الحقول اختيارية، ولا نُحدّث إلا ما أُرسل فعلاً
//
// الطريقة: POST
// ============================================================

// بدء مخزن مؤقت لإخفاء أي تحذير HTML قبل رد JSON
ob_start();
// ربط ملف الاتصال بقاعدة البيانات
include "../../connect.php";
// منع طباعة تحذيرات PHP بهيئة HTML حتى لا يكسر تحليل JSON في فلاتر
ini_set('html_errors', '0');
// مسار ملف خطافات الإشعارات من جذر المشروع
$notificationHooksPath = __DIR__ . "/../../notifications/includes/notification_hooks.php";
if (!file_exists($notificationHooksPath)) {
    $notificationHooksPath = __DIR__ . "/../../notifications/notification_hooks.php";
}
// نضمّن الملف فقط إن وُجد حتى لا تُطبع صفحة HTML تحذير قبل JSON
if (file_exists($notificationHooksPath)) {
    // تحميل دوال إشعار العميل ومقدم الخدمة بعد اعتماد الحجز
    include_once $notificationHooksPath;
}
// تفريغ أي مخرجات عرضية من ملفات التضمين قبل الرد
if (ob_get_length()) {
    ob_clean();
}

// ⭐ حماية ملف الإدارة: يجب إرسال المفتاح السري في هيدر X-Admin-Key
// ⚠️ لو أردت تعطيل الحماية مؤقتاً أثناء التطوير ضع // قبل السطر التالي فقط
//checkAdminKey();

// ============================================================
// الخطوة 1: التحقق من التوكن والصلاحية
//
// ⭐bookings.edit من حق  owner و manager و support
// ============================================================
// التحقق من التوكن وجلب بيانات الموظف مع صلاحياته
$currentAdmin = checkAdminToken();

// ⭐ فرض صلاحية تعديل الحجوزات
requirePermission($currentAdmin, 'bookings.edit');

// ⭐ التعديل لـ owner / manager / support فقط
$allowedRoles = ['owner', 'manager', 'support'];
$roleKey = $currentAdmin['role_key'] ?? ($currentAdmin['admin_role'] ?? '');
if (!in_array($roleKey, $allowedRoles, true)) {
    printFailure("ليس لديك صلاحية تنفيذ هذا الإجراء", 403);
    exit;
}

// معرّف الموظف المنفّذ مأخوذ من التوكن لا من الطلب
$adminId = (int) $currentAdmin['admin_id'];


// ============================================================
// الخطوة 1: استقبال البيانات القادمة من لوحة الإدارة
// ============================================================
// معرّف الحجز المراد تعديله - إجباري
$booking_id = filterRequest("booking_id");
// تفاصيل الطلب (يمكن للإدارة تصحيحها أو توضيحها)
$booking_order_details = filterRequest("booking_order_details");
// السعر الأساسي بعد الاتفاق مع العميل
$booking_ps_price = filterRequest("booking_ps_price");
// هل ما زال السعر قابلاً للتفاوض (0 أو 1)
$booking_ps_negotiable = filterRequest("booking_ps_negotiable");
// نوع العمولة بعد الاتفاق (fixed أو percent)
$booking_ps_commission_type = filterRequest("booking_ps_commission_type");
// قيمة العمولة بعد الاتفاق
$booking_ps_commission_value = filterRequest("booking_ps_commission_value");
// ⭐ مبلغ عمولة مقدم الخدمة (A) بالجنيه كما عدّلته الإدارة
$booking_ps_commission_amount = filterRequest("booking_ps_commission_amount");
// ⭐ نوع عمولة العميل (B) الظاهرة في الحجز
$booking_client_commission_type = filterRequest("booking_client_commission_type");
// ⭐ قيمة/نسبة عمولة العميل (B)
$booking_client_commission_value = filterRequest("booking_client_commission_value");
// ⭐ معرّف كوبون الخصم (0 لإزالة الكوبون، أو معرّف كوبون آخر لتغييره)
// الإدارة ترسل المعرّف وليس الاسم لأن الحقل في الجدول من نوع int
$booking_coupon = filterRequest("booking_coupon");
// الإجمالي النهائي (اختياري - إن أرسلته الإدارة يدوياً يتم استخدامه كما هو)
$booking_total_price = filterRequest("booking_total_price");
// تأكيد العميل (0 أو 1)
$booking_confirmation = filterRequest("booking_confirmation");
// تاريخ ووقت بدء عمل مقدم الخدمة بصيغة Y-m-d H:i:s
$booking_vailability_status = filterRequest("booking_vailability_status");
// موافقة الإدارة على الحجز (0 أو 1)
$booking_approval = filterRequest("booking_approval");
// حالة الطلب العامة
$booking_status = filterRequest("booking_status");
// عدد الطلبات السابقة لمقدم الخدمة
$booking_number_of_requests = filterRequest("booking_number_of_requests");
// ملاحظات الإدارة أو سبب تعديل السعر
$booking_admin_notes = filterRequest("booking_admin_notes");

// ============================================================
// الخطوة 2: التحقق من الحقل الإجباري
// ============================================================
// إذا لم يُرسل معرّف الحجز نوقف التنفيذ فوراً
if (empty($booking_id)) {
    // رسالة توضح أن معرّف الحجز مطلوب
    printFailure("booking_id is required");
    // إيقاف التنفيذ
    exit;
}

// تحويل المعرّف لرقم صحيح لحماية جملة WHERE من أي محتوى غير رقمي
$booking_id = (int) $booking_id;

// ============================================================
// الخطوة 3: التحقق من وجود الحجز وجلب قيمه الحالية
//           نحتاج القيم الحالية لإعادة حساب الإجمالي بدقة
// ============================================================
// استعلام يجلب الحقول المؤثرة في حساب السعر
$stmtCheck = $con->prepare(
    "SELECT booking_id, booking_ps_price,
            booking_ps_commission_type, booking_ps_commission_value,
            booking_ps_commission_amount,
            booking_client_commission_type, booking_client_commission_value,
            booking_coupon, booking_status, booking_approval,
            booking_users_id, booking_ps_id,
            booking_order_details, booking_vailability_status
     FROM bookings
     WHERE booking_id = ? LIMIT 1"
);
// تنفيذ الاستعلام بتمرير معرّف الحجز
$stmtCheck->execute([$booking_id]);
// جلب صف الحجز الحالي
$current = $stmtCheck->fetch(PDO::FETCH_ASSOC);

// إذا لم يوجد الحجز نرفض التعديل
if (!$current) {
    // رسالة توضح أن الحجز غير موجود
    printFailure("Booking not found");
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 4: التحقق من صحة قيم الحقول من نوع enum
//           لأن MySQL يرفض أي قيمة خارج القائمة المسموحة
// ============================================================
// قائمة أنواع العمولة المسموحة في الجدول
$allowedCommissionTypes = ['fixed', 'percent'];
// قائمة حالات الطلب المسموحة في الجدول
$allowedStatuses = ['pending', 'negotiating', 'approved', 'in_progress', 'completed', 'cancelled'];

// التحقق من نوع العمولة إن أُرسل
if (
    !empty($booking_ps_commission_type)
    && !in_array($booking_ps_commission_type, $allowedCommissionTypes, true)
) {
    // رسالة توضح القيم المسموحة لنوع العمولة
    printFailure("booking_ps_commission_type must be: fixed or percent");
    // إيقاف التنفيذ
    exit;
}

// التحقق من حالة الطلب إن أُرسلت
if (
    !empty($booking_status)
    && !in_array($booking_status, $allowedStatuses, true)
) {
    // رسالة توضح القيم المسموحة لحالة الطلب
    printFailure("booking_status must be one of: " . implode(', ', $allowedStatuses));
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 5: التحقق من صحة صيغة تاريخ بدء العمل إن أُرسل
// ============================================================
// نتحقق فقط إذا أرسلت الإدارة قيمة لهذا الحقل
if (!empty($booking_vailability_status)) {
    // محاولة تحويل النص لتاريخ صالح
    $timestampCheck = strtotime($booking_vailability_status);
    // إذا فشل التحويل فالصيغة غير صحيحة
    if ($timestampCheck === false) {
        // رسالة توضح الصيغة الصحيحة المطلوبة
        printFailure("booking_vailability_status must be a valid datetime (Y-m-d H:i:s)");
        // إيقاف التنفيذ
        exit;
    }
    // إعادة تنسيق التاريخ بالصيغة التي يقبلها عمود datetime في MySQL
    $booking_vailability_status = date("Y-m-d H:i:s", $timestampCheck);
}

// ============================================================
// الخطوة 5-أ: منع التحويل إلى تنفيذ أو إكمال أو إلغاء قبل حلول موعد بدء العمل
// نطبّق القيد فقط عند تغيير الحالة، لأن النموذج يرسل الحالة الحالية مع كل حفظ
$restrictedBeforeStart = ['in_progress', 'completed', 'cancelled'];
$currentStatus = (string) ($current['booking_status'] ?? '');
if (
    $booking_status !== ''
    && $booking_status !== $currentStatus
    && in_array($booking_status, $restrictedBeforeStart, true)
) {
    // الموعد المرسل من اللوحة إن وُجد وإلا الموعد المحفوظ في الحجز
    $finalVailabilityForGate = $booking_vailability_status !== ''
        ? $booking_vailability_status
        : (string) ($current['booking_vailability_status'] ?? '');
    // لا يُسمح بالتنفيذ أو الإكمال أو الإلغاء دون موعد بدء عمل صالح
    if ($finalVailabilityForGate === '' || $finalVailabilityForGate === '0000-00-00 00:00:00') {
        // رسالة واضحة لموظف الإدارة
        printFailure(
            "لا يمكن تنفيذ أو إكمال أو إلغاء الحجز قبل تحديد موعد بدء العمل",
            409
        );
        // إيقاف التنفيذ
        exit;
    }
    // تحويل موعد بدء العمل إلى طابع زمني للمقارنة مع الوقت الحالي
    $startTimestamp = strtotime($finalVailabilityForGate);
    // نرفض إذا تعذّر فهم الموعد أو إذا كان الموعد في المستقبل
    if ($startTimestamp === false || $startTimestamp > time()) {
        // رسالة توضح أن موعد بدء العمل لم يحل بعد
        printFailure(
            "لا يمكن تنفيذ أو إكمال أو إلغاء الحجز قبل حلول موعد بدء العمل",
            409
        );
        // إيقاف التنفيذ
        exit;
    }
}


// ============================================================
// ⭐ الخطوة 5-ب: التحقق من كوبون الخصم الجديد إن أرسلته الإدارة
//   نقرأ نسبة خصمه لأننا نحتاجها في إعادة حساب الإجمالي
// ============================================================
// معرّف الكوبون القديم المحفوظ في الحجز حالياً
$oldCouponId = (int) $current['booking_coupon'];
// معرّف الكوبون النهائي بعد التعديل (نبدأ بالقديم كقيمة افتراضية)
$finalCouponId = $oldCouponId;

// نتحقق فقط إذا أرسلت الإدارة قيمة لهذا الحقل
if ($booking_coupon !== '') {
    // تحويل القيمة لرقم صحيح لأن العمود من نوع int
    $finalCouponId = (int) $booking_coupon;

    // لا نسمح بقيمة سالبة لأن صفر تعني "بدون كوبون"
    if ($finalCouponId < 0) {
        // رسالة توضح القيم المسموحة
        printFailure("booking_coupon must be 0 (no coupon) or a valid coupon_id");
        // إيقاف التنفيذ
        exit;
    }

    // إذا كانت القيمة أكبر من صفر نتأكد أن الكوبون موجود فعلاً
    if ($finalCouponId > 0) {
        // استعلام آمن يبحث عن الكوبون بمعرّفه
        $stmtCouponExists = $con->prepare(
            "SELECT coupon_id FROM coupon WHERE coupon_id = ? LIMIT 1"
        );
        // تنفيذ الاستعلام بتمرير معرّف الكوبون
        $stmtCouponExists->execute([$finalCouponId]);
        // إذا لم يوجد الكوبون نرفض التعديل
        if ($stmtCouponExists->rowCount() === 0) {
            // رسالة توضح أن معرّف الكوبون غير موجود
            printFailure("Coupon not found", 404);
            // إيقاف التنفيذ
            exit;
        }
    }
}

// ============================================================
// ⭐ قراءة نسبة خصم الكوبون النهائي لاستخدامها في حساب الإجمالي
// ============================================================
// تهيئة نسبة الخصم بصفر (تعني لا يوجد خصم)
$finalCouponDiscount = 0;

// نقرأ النسبة فقط إذا كان هناك كوبون فعلي
if ($finalCouponId > 0) {
    // استعلام يجلب نسبة الخصم للكوبون النهائي
    $stmtCouponVal = $con->prepare(
        "SELECT coupon_discount FROM coupon WHERE coupon_id = ? LIMIT 1"
    );
    // تنفيذ الاستعلام بتمرير معرّف الكوبون
    $stmtCouponVal->execute([$finalCouponId]);
    // قراءة نسبة الخصم كرقم عشري
    $finalCouponDiscount = (float) $stmtCouponVal->fetchColumn();
}

// ============================================================
// الخطوة 6: بناء مصفوفة التحديث ديناميكياً
//           نُضيف الحقل فقط إذا أرسلته الإدارة فعلاً
// ============================================================
// تهيئة مصفوفة فارغة سنملأها بالحقول المُرسَلة
$updateData = [];

// إضافة تفاصيل الطلب إن أُرسلت
if ($booking_order_details !== '') {
    $updateData["booking_order_details"] = $booking_order_details;
}
// إضافة السعر الأساسي إن أُرسل
if ($booking_ps_price !== '') {
    $updateData["booking_ps_price"] = (float) $booking_ps_price;
}
// إضافة حالة التفاوض إن أُرسلت (نقبل 0 لذلك نقارن بالنص الفارغ)
if ($booking_ps_negotiable !== '') {
    $updateData["booking_ps_negotiable"] = (int) $booking_ps_negotiable;
}
// إضافة نوع العمولة إن أُرسل
if ($booking_ps_commission_type !== '') {
    $updateData["booking_ps_commission_type"] = $booking_ps_commission_type;
}
// إضافة قيمة العمولة إن أُرسلت
if ($booking_ps_commission_value !== '') {
    $updateData["booking_ps_commission_value"] = (float) $booking_ps_commission_value;
}
// ⭐ إضافة مبلغ عمولة مقدم الخدمة (A) إن أُرسل
if ($booking_ps_commission_amount !== '') {
    $updateData["booking_ps_commission_amount"] = (float) $booking_ps_commission_amount;
}
// ⭐ إضافة نوع عمولة العميل (B) إن أُرسل
if ($booking_client_commission_type !== '') {
    $updateData["booking_client_commission_type"] = $booking_client_commission_type;
}
// ⭐ إضافة قيمة/نسبة عمولة العميل (B) إن أُرسلت
if ($booking_client_commission_value !== '') {
    $updateData["booking_client_commission_value"] = (float) $booking_client_commission_value;
}
// إضافة تأكيد العميل إن أُرسل
if ($booking_confirmation !== '') {
    $updateData["booking_confirmation"] = (int) $booking_confirmation;
}
// إضافة تاريخ بدء العمل إن أُرسل
if ($booking_vailability_status !== '') {
    $updateData["booking_vailability_status"] = $booking_vailability_status;
}
// إضافة موافقة الإدارة إن أُرسلت
if ($booking_approval !== '') {
    $updateData["booking_approval"] = (int) $booking_approval;
}
// إضافة حالة الطلب إن أُرسلت
if ($booking_status !== '') {
    $updateData["booking_status"] = $booking_status;
}
// إضافة عدد الطلبات السابقة إن أُرسل
if ($booking_number_of_requests !== '') {
    $updateData["booking_number_of_requests"] = (int) $booking_number_of_requests;
}
// إضافة ملاحظات الإدارة إن أُرسلت
if ($booking_admin_notes !== '') {
    $updateData["booking_admin_notes"] = $booking_admin_notes;
}
// ⭐ إضافة معرّف الكوبون إن أرسلته الإدارة (نقبل الصفر لذلك نقارن بالنص الفارغ)
if ($booking_coupon !== '') {
    $updateData["booking_coupon"] = $finalCouponId;
}

// ============================================================
// الخطوة 7: إعادة حساب الإجمالي تلقائياً
//   إذا عدّلت الإدارة السعر أو العمولة أو الكوبون ولم تُرسل الإجمالي يدوياً
//   نحسبه بنفس معادلة add_booking.php لضمان التوافق التام
//
//   ⭐ قاعدة الخصم: نسبة الكوبون تُخصم من العمولة فقط
//      الإجمالي = سعر الخدمة + (العمولة - خصم الكوبون من العمولة)
// ============================================================
// تهيئة مبلغ عمولة مقدم الخدمة (A) بالجنيه
$providerCommissionAmount = 0;
// تهيئة مبلغ عمولة العميل (B) بالجنيه
$clientCommissionAmount = 0;
// تهيئة قيمة خصم الكوبون بالجنيه
$couponDiscountAmount = 0;
// تهيئة عمولة العميل بعد الخصم
$clientCommissionAfterCoupon = 0;
// إذا أرسلت الإدارة الإجمالي يدوياً نستخدمه كما هو ونحترم قرارها
if ($booking_total_price !== '') {
    // تخزين الإجمالي المُرسَل يدوياً بعد تقريبه
    $updateData["booking_total_price"] = round((float) $booking_total_price, 2);
}
// أما إذا لم تُرسله وعدّلت السعر أو العمولتين أو الكوبون فنعيد الحساب تلقائياً
elseif (
    isset($updateData["booking_ps_price"])
    || isset($updateData["booking_ps_commission_type"])
    || isset($updateData["booking_ps_commission_value"])
    || isset($updateData["booking_ps_commission_amount"])
    || isset($updateData["booking_client_commission_type"])
    || isset($updateData["booking_client_commission_value"])
    || isset($updateData["booking_coupon"])
) {
    // السعر الأساسي النهائي لمقدم الخدمة
    $finalPrice = isset($updateData["booking_ps_price"])
        ? (float) $updateData["booking_ps_price"]
        : (float) $current['booking_ps_price'];

    // ============================================================
    // ⭐ عمولة مقدم الخدمة (A) المخفية عن العميل
    // ============================================================
    if (isset($updateData["booking_ps_commission_amount"])) {
        // إن أرسلت الإدارة المبلغ مباشرة نستخدمه كما هو
        $providerCommissionAmount = (float) $updateData["booking_ps_commission_amount"];
    } else {
        // وإلا نحسبه من النوع والقيمة
        $finalProviderType = isset($updateData["booking_ps_commission_type"])
            ? $updateData["booking_ps_commission_type"]
            : $current['booking_ps_commission_type'];
        $finalProviderValue = isset($updateData["booking_ps_commission_value"])
            ? (float) $updateData["booking_ps_commission_value"]
            : (float) $current['booking_ps_commission_value'];
        if ($finalProviderType === 'percent' && $finalPrice > 0) {
            // مثال: 10 × 10 ÷ 100 = 1 ج.م
            $providerCommissionAmount = ($finalPrice * $finalProviderValue) / 100;
        } else {
            // مبلغ ثابت
            $providerCommissionAmount = $finalProviderValue;
        }
    }
    // تقريب وحفظ مبلغ عمولة مقدم الخدمة
    $providerCommissionAmount = round((float) $providerCommissionAmount, 2);
    $updateData["booking_ps_commission_amount"] = $providerCommissionAmount;

    // ⭐ سعر الخدمة الذي يراه العميل = الأساسي + عمولة مقدم الخدمة
    $servicePriceForClient = round($finalPrice + $providerCommissionAmount, 2);

    // ============================================================
    // ⭐ عمولة العميل (B) الظاهرة في الحجز
    // ============================================================
    $finalClientType = isset($updateData["booking_client_commission_type"])
        ? $updateData["booking_client_commission_type"]
        : $current['booking_client_commission_type'];
    $finalClientValue = isset($updateData["booking_client_commission_value"])
        ? (float) $updateData["booking_client_commission_value"]
        : (float) $current['booking_client_commission_value'];
    if ($finalClientType === 'percent' && $finalPrice > 0) {
        // مثال: 10 × 10 ÷ 100 = 1 ج.م
        $clientCommissionAmount = ($finalPrice * $finalClientValue) / 100;
    } else {
        // مبلغ ثابت
        $clientCommissionAmount = $finalClientValue;
    }
    // تقريب وحفظ مبلغ عمولة العميل
    $clientCommissionAmount = round((float) $clientCommissionAmount, 2);
    $updateData["booking_client_commission_amount"] = $clientCommissionAmount;

    // ⭐ خصم الكوبون من عمولة العميل فقط
    if ($finalCouponDiscount > 0 && $clientCommissionAmount > 0) {
        // مثال: 1 × 10 ÷ 100 = 0.1 ج.م خصم
        $couponDiscountAmount = ($clientCommissionAmount * $finalCouponDiscount) / 100;
    }
    // عمولة العميل بعد خصم الكوبون
    $clientCommissionAfterCoupon = $clientCommissionAmount - $couponDiscountAmount;
    // حماية منطقية: لا نسمح للعمولة بأن تصبح رقماً سالباً
    if ($clientCommissionAfterCoupon < 0) {
        $clientCommissionAfterCoupon = 0;
    }
    // الإجمالي = سعر الخدمة الظاهر + عمولة العميل بعد خصم الكوبون
    // مثال: 11 + 1 = 12 ج.م
    $updateData["booking_total_price"] = round($servicePriceForClient + $clientCommissionAfterCoupon, 2);
}

// ============================================================
// الخطوة 8: التأكد من وجود حقل واحد على الأقل للتحديث
// ============================================================
// إذا كانت المصفوفة فارغة فلا يوجد ما نُحدّثه
if (empty($updateData)) {
    // رسالة توضح عدم إرسال أي حقل قابل للتعديل
    printFailure("No fields to update");
    // إيقاف التنفيذ
    exit;
}

// ⭐ تسجيل الموظف المراجع ووقت المراجعة بعد التأكد أن هناك تعديلاً فعلياً
$updateData["booking_reviewed_by"] = $adminId;
$updateData["booking_reviewed_at"] = date("Y-m-d H:i:s");

// ============================================================
// ⭐ الخطوة 9: تنفيذ التحديث وضبط رصيد الكوبونات داخل Transaction
//   إذا غيّرت الإدارة الكوبون نُعيد 1 لرصيد الكوبون القديم
//   ونُنقص 1 من رصيد الكوبون الجديد، حتى يبقى العدّاد صحيحاً
// ============================================================
// بدء المعاملة لضمان تنفيذ كل العمليات معاً أو إلغائها معاً
$con->beginTransaction();

// نستخدم try/catch لضمان التراجع عند أي خطأ غير متوقع
try {
    // نضبط رصيد الكوبونات فقط إذا تغيّر الكوبون فعلاً عن قيمته السابقة
    if (isset($updateData["booking_coupon"]) && $finalCouponId !== $oldCouponId) {

        // إعادة استخدام واحد لرصيد الكوبون القديم لأنه لم يُستخدم فعلياً
        if ($oldCouponId > 0) {
            // زيادة العدّاد بواحد للكوبون القديم
            $stmtRestore = $con->prepare(
                "UPDATE coupon SET coupon_count = coupon_count + 1 WHERE coupon_id = ?"
            );
            // تنفيذ التحديث بتمرير معرّف الكوبون القديم
            $stmtRestore->execute([$oldCouponId]);
        }

        // خصم استخدام واحد من رصيد الكوبون الجديد
        if ($finalCouponId > 0) {
            // الشرط coupon_count > 0 يمنع نزول الرصيد تحت الصفر
            $stmtConsume = $con->prepare(
                "UPDATE coupon SET coupon_count = coupon_count - 1
                 WHERE coupon_id = ? AND coupon_count > 0"
            );
            // تنفيذ التحديث بتمرير معرّف الكوبون الجديد
            $stmtConsume->execute([$finalCouponId]);

            // إذا لم يتغيّر أي صف فمعنى ذلك أن رصيد الكوبون الجديد نفد
            if ($stmtConsume->rowCount() === 0) {
                // إلغاء كل ما حدث في المعاملة
                $con->rollBack();
                // رسالة توضح نفاد رصيد الكوبون
                printFailure("New coupon has no remaining usages", 409);
                // إيقاف التنفيذ
                exit;
            }
        }
    }

    // تنفيذ تحديث الحجز باستخدام دالتك الموحدة مع false لمنع طباعة JSON مرتين
    $result = updateData(
        "bookings",
        $updateData,
        "booking_id = $booking_id",
        false
    );

    // تثبيت المعاملة نهائياً في قاعدة البيانات
    $con->commit();

    // تسجيل التعديل في سجل النشاط الإداري
    logAdminActivity(
        $currentAdmin,
        'edit_booking',
        'booking',
        $booking_id,
        [
            "updated_fields" => array_keys($updateData),
            "from_status" => $current['booking_status'],
            "to_status" => $updateData["booking_status"] ?? $current['booking_status'],
        ]
    );

} catch (Exception $e) {
    // في حال وجود معاملة مفتوحة نتراجع عنها لحماية البيانات
    if ($con->inTransaction()) {
        // إلغاء كل ما حدث في المعاملة
        $con->rollBack();
    }
    // إرسال رسالة خطأ السيرفر مع سبب الاستثناء
    printFailure("Failed to update booking", 500, ["error" => $e->getMessage()]);
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 10: جلب الحجز بعد التعديل لإرجاع صورته النهائية
// ============================================================
// استعلام يجلب كل حقول الحجز بعد التحديث
$stmtAfter = $con->prepare(
    "SELECT * FROM bookings WHERE booking_id = ? LIMIT 1"
);
// تنفيذ الاستعلام بتمرير معرّف الحجز
$stmtAfter->execute([$booking_id]);
// جلب الصف المحدَّث
$updated = $stmtAfter->fetch(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 11: إرسال الرد النهائي
// ============================================================
// التحقق من نجاح التحديث (rowCount أكبر من صفر يعني تغيّر صف فعلاً)
if ($result > 0) {
    // هل أصبح الحجز معتمداً لأول مرة؟ (موافقة الإدارة أو الحالة approved)
    $becameApproved = (
        ((int) ($updated['booking_approval'] ?? 0) === 1
            && (int) ($current['booking_approval'] ?? 0) !== 1)
        || (
            ($updated['booking_status'] ?? '') === 'approved'
            && ($current['booking_status'] ?? '') !== 'approved'
        )
    );
    // إشعار العميل ومقدم الخدمة عند الاعتماد الأول فقط
    if ($becameApproved) {
        try {
            // جلب العميل ومقدم الخدمة المرتبطين بالحجز
            $stmtPeople = $con->prepare(
                "SELECT
                    b.booking_id, b.booking_order_details, b.booking_vailability_status,
                    b.booking_total_price,
                    client.users_id AS client_id,
                    client.users_full_name AS client_name,
                    provider.users_id AS provider_id,
                    provider.users_full_name AS provider_name
                 FROM bookings b
                 INNER JOIN users client ON client.users_id = b.booking_users_id
                 INNER JOIN provider_services ps ON ps.ps_id = b.booking_ps_id
                 INNER JOIN users provider ON provider.users_id = ps.ps_user_id
                 WHERE b.booking_id = ?
                 LIMIT 1"
            );
            // تنفيذ الاستعلام بمعرّف الحجز
            $stmtPeople->execute([$booking_id]);
            // جلب صف الأطراف
            $people = $stmtPeople->fetch(PDO::FETCH_ASSOC);
            // إن وُجد الصف نرسل الإشعارين
            if ($people && function_exists('notifyBookingConfirmed')) {
                notifyBookingConfirmed(
                    [
                        "booking_id" => $booking_id,
                        "booking_order_details" => $people['booking_order_details'],
                        "booking_vailability_status" => $people['booking_vailability_status'],
                        "booking_total_price" => $people['booking_total_price'],
                    ],
                    [
                        "users_id" => $people['provider_id'],
                        "users_full_name" => $people['provider_name'],
                    ],
                    [
                        "users_id" => $people['client_id'],
                        "users_full_name" => $people['client_name'],
                    ],
                    (string) ($currentAdmin['admin_phone'] ?? '')
                );
            }
        } catch (Throwable $e) {
            // لا نوقف تعديل الحجز إذا فشل الإشعار
        }
    }
    // إرسال JSON يحتوي على الحقول المعدَّلة والصورة النهائية للحجز
    echo json_encode([
        // حالة العملية
        "status" => "success",
        // رسالة نجاح واضحة
        "message" => "Booking updated successfully",
        // بيانات النتيجة
        "data" => [
            // معرّف الحجز الذي تم تعديله
            "booking_id" => $booking_id,
            // أسماء الحقول التي تم تعديلها فعلاً
            "updated_fields" => array_keys($updateData),
            // ملخص السعر بعد التعديل
            "price_breakdown" => [
                // ⭐ سعر الخدمة الظاهر للعميل = الأساسي + عمولة مقدم الخدمة (A)
                "service_price" => round((float) $updated['booking_ps_price'] + (float) $updated['booking_ps_commission_amount'], 2),
                // سعر مقدم الخدمة الأساسي (بدون عمولته)
                "provider_base_price" => (float) $updated['booking_ps_price'],
                // ⭐ نوع عمولة العميل (B)
                "commission_type" => $updated['booking_client_commission_type'],
                // ⭐ قيمة/نسبة عمولة العميل (B)
                "commission_value" => (float) $updated['booking_client_commission_value'],
                // ⭐ مبلغ عمولة العميل (B) قبل خصم الكوبون
                "commission_before" => (float) $updated['booking_client_commission_amount'],
                // ⭐ قيمة خصم الكوبون المخصومة من عمولة العميل فقط
                "coupon_discount_amount" => round((float) $couponDiscountAmount, 2),
                // ⭐ عمولة العميل بعد خصم الكوبون
                "commission_after" => round((float) $updated['booking_client_commission_amount'] - (float) $couponDiscountAmount, 2),
                // الإجمالي النهائي بعد التعديل
                "total_price" => (float) $updated['booking_total_price'],
                // هل ما زال السعر قابلاً للتفاوض
                "negotiable" => (bool) $updated['booking_ps_negotiable'],
            ],
            // ⭐ معرّف الكوبون المحفوظ في الحجز بعد التعديل (0 = بدون كوبون)
            "booking_coupon" => (int) $updated['booking_coupon'],
            // الحالة النهائية للحجز بعد التعديل
            "booking_status" => $updated['booking_status'],
            // موافقة الإدارة النهائية
            "booking_approval" => (int) $updated['booking_approval'],
            // تأكيد العميل
            "booking_confirmation" => (int) $updated['booking_confirmation'],
            // تاريخ ووقت بدء العمل
            "booking_vailability_status" => $updated['booking_vailability_status'],
            // ملاحظات الإدارة
            "booking_admin_notes" => $updated['booking_admin_notes'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} else {
    // إذا لم يتغيّر أي صف فمعنى ذلك أن القيم المُرسَلة مطابقة للقيم الحالية
    printFailure("No changes were made (values may be identical)");
}
?>