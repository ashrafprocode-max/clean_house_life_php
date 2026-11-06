<?php
// ============================================================
// ملف: admin/customers/add_customer_booking.php
// الغرض: إنشاء حجز خدمة نيابة عن عميل من لوحة الإدارة
//        الحجز لا يحتاج موافقة لاحقة (booking_approval = 1)
//        لأن الإدارة هي من أنشأته، لكن الحالة ليست مكتملة دائماً.
//        الحالة المسموحة عند الإنشاء: approved / in_progress / completed
//        الافتراضي: approved (موافق عليه وبانتظار التنفيذ).
// الطريقة: POST
//
// ⭐⭐ هذا الملف هو نهاية التدفق:
//   التاب 1: أنشأنا حساب العميل
//   التاب 2: اخترنا مقدم الخدمة وحددنا السعر والكوبون
//   وهنا: نكتب صف الحجز النهائي ونُنقص رصيد الكوبون.
//
// ⚠️⚠️ كل الحسابات تتكرر هنا على السيرفر ولا نثق بأرقام فلاتر:
//   السعر والعمولة والإجمالي نحسبها بأنفسنا، والقيم التي
//   عدّلها الموظف نقبلها كتجاوز صريح (override) لأن اللوحة
//   تسمح بتعديل السعر — لكن نتحقق من صحتها رقمياً.
//
// الحقول الإجبارية: users_id · ps_id · booking_order_details
// الصلاحية المطلوبة: bookings.edit
// ============================================================

// تضمين ملف الاتصال (مستويان للوراء لأن الملف داخل admin/customers)
include "../../connect.php";

// ============================================================
// الخطوة 1: التحقق من توكن الإدارة والصلاحية
//
// ⭐ bookings.edit يملكها: owner و manager و support
//    ولا يملكها: seller و accountant
//    وهذا هو المطلوب: خدمة العملاء تُنشئ حجزاً معتمَداً دون انتظار موافقة.
// ============================================================
// التحقق من التوكن وجلب بيانات الموظف مع صلاحياته
$currentAdmin = checkAdminToken();

// ⭐ فرض صلاحية تعديل/إنشاء الحجوزات من اللوحة
requirePermission($currentAdmin, 'bookings.edit');

// معرّف الموظف المنفّذ مأخوذ من التوكن لا من الطلب
$adminId = (int) $currentAdmin['admin_id'];

// ============================================================
// الخطوة 2: استقبال بيانات العميل والحجز
// ============================================================
// معرّف العميل من جدول users - إجباري
$usersId = (int) filterRequest("users_id");
// معرّف الخدمة من جدول provider_services - إجباري
$psId = (int) filterRequest("ps_id");
// ⭐ تفاصيل الطلب التي كتبها الموظف - إجبارية
$orderDetails = trim(filterRequest("booking_order_details"));
// تاريخ ووقت بدء العمل (اختياري)
$vailabilityStatus = trim(filterRequest("booking_vailability_status"));
// ⭐ ملاحظات الإدارة
$adminNotes = trim(filterRequest("booking_admin_notes"));

// ============================================================
// ⭐ الخطوة 3: استقبال القيم التي عدّلها الموظف (overrides)
//
// ⚠️ كلها اختيارية: إن أُرسلت نستخدمها، وإلا نحسب من provider_services
// ============================================================
// السعر الذي عدّله الموظف
$inputPrice           = filterRequest("booking_ps_price");
// نوع العمولة الذي اختاره
$inputCommissionType  = filterRequest("booking_ps_commission_type");
// قيمة العمولة التي حددها
$inputCommissionValue = filterRequest("booking_ps_commission_value");
// ⭐ الإجمالي الذي عدّله يدوياً (إن أُرسل نحترمه)
$inputTotalPrice      = filterRequest("booking_total_price");
// معرّف كوبون الخصم (0 = بدون)
$inputCouponId        = filterRequest("booking_coupon");
// عدد الطلبات السابقة (حقل إحصائي اختياري)
$inputRequestsCount   = filterRequest("booking_number_of_requests");
// هل السعر قابل للتفاوض؟ (0 أو 1)
$inputNegotiable      = filterRequest("booking_ps_negotiable");
// حالة الحجز التي اختارها الموظف (اختياري — الافتراضي approved)
$inputBookingStatus   = trim(filterRequest("booking_status"));

// ============================================================
// الخطوة 4: التحقق من الحقول الإجبارية
// ============================================================
// معرّف العميل إجباري
if ($usersId <= 0) {
    // رسالة توضح الحقل المطلوب
    printFailure("معرّف العميل users_id مطلوب");
    // إيقاف التنفيذ
    exit;
}

// معرّف الخدمة إجباري
if ($psId <= 0) {
    // رسالة توضح الحقل المطلوب
    printFailure("معرّف الخدمة ps_id مطلوب");
    // إيقاف التنفيذ
    exit;
}

// تفاصيل الطلب إجبارية ولا تقل عن 10 أحرف لتكون مفيدة
if (mb_strlen($orderDetails) < 10) {
    // رسالة توضح الحد الأدنى
    printFailure("تفاصيل الطلب يجب أن تكون 10 أحرف على الأقل");
    // إيقاف التنفيذ
    exit;
}

// ⚠️ العمود text فلا داعي لقياس 500 لكن نمنع النصوص الضخمة
if (mb_strlen($orderDetails) > 5000) {
    // رسالة توضح الحد الأقصى
    printFailure("تفاصيل الطلب طويلة جداً — الحد 5000 حرف");
    // إيقاف التنفيذ
    exit;
}

// ⭐ نوع العمولة محدود بقيمتَي ENUM في قاعدة البيانات
$allowedCommissionTypes = ['fixed', 'percent'];
if ($inputCommissionType !== ''
    && !in_array($inputCommissionType, $allowedCommissionTypes, true)) {
    // رسالة توضح القيم المسموحة
    printFailure("نوع العمولة يجب أن يكون fixed أو percent");
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// حالة الحجز عند إنشائه من اللوحة
// لا pending: الإدارة أنشأت الحجز فلا ينتظر موافقة
// لا cancelled: الإلغاء ليس حالة إنشاء
// ============================================================
$allowedCreateStatuses = ['approved', 'in_progress', 'completed'];
$statusLabelsAr = [
    'approved'    => 'موافق عليه',
    'in_progress' => 'جارٍ التنفيذ',
    'completed'   => 'مكتمل',
];
if ($inputBookingStatus === '') {
    $bookingStatus = 'approved';
} elseif (!in_array($inputBookingStatus, $allowedCreateStatuses, true)) {
    printFailure(
        "حالة الحجز يجب أن تكون approved أو in_progress أو completed"
    );
    exit;
} else {
    $bookingStatus = $inputBookingStatus;
}
$bookingStatusAr = $statusLabelsAr[$bookingStatus];

// ============================================================
// ⭐ الخطوة 5: التحقق من وجود العميل وأنه من نوع client
//
// ⚠️⚠️ إجباري: جدول الحجوزات يربط العميل بجدول users،
//   ولو سمحنا لحساب provider بالحجز لأصبح يطلب من نفسه
//   ولاختلطت الإحصاءات.
// ============================================================
// استعلام يجلب نوع العميل وحالته
$stmtUser = $con->prepare(
    "SELECT users_id, users_full_name, users_phone, users_type, users_is_active
     FROM users WHERE users_id = ? LIMIT 1"
);
// تنفيذ الاستعلام
$stmtUser->execute([$usersId]);
// جلب العميل
$client = $stmtUser->fetch(PDO::FETCH_ASSOC);

// إن لم يوجد العميل نُرجع 404
if (!$client) {
    // رسالة توضح أن العميل غير موجود
    printFailure("حساب العميل غير موجود", 404);
    // إيقاف التنفيذ
    exit;
}

// ⚠️ إن كان الحساب ليس عميلاً نرفض الحجز
if ($client['users_type'] !== 'client') {
    // رسالة توضح نوع الحساب الفعلي
    printFailure(
        "هذا الحساب نوعه (" . $client['users_type'] . ") ولا يمكن الحجز عليه",
        409
    );
    // إيقاف التنفيذ
    exit;
}

// ⚠️ الحساب المعطّل لا يُحجز عليه
if ((int) $client['users_is_active'] !== 1) {
    // رسالة توضح أن الحساب معطّل
    printFailure("حساب العميل معطّل — فعّله أولاً", 403);
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// ⭐ الخطوة 6: جلب الخدمة ومعرفة مقدمها وسعرها وعمولتها
// ============================================================
$stmtService = $con->prepare(
    "SELECT ps_id, ps_user_id, ps_price, ps_negotiable,
            ps_commission_type, ps_commission_value
     FROM provider_services
     WHERE ps_id = ? LIMIT 1"
);
// تنفيذ الاستعلام
$stmtService->execute([$psId]);
// جلب الخدمة
$service = $stmtService->fetch(PDO::FETCH_ASSOC);

// إن لم توجد الخدمة نُرجع 404
if (!$service) {
    // رسالة توضح أن الخدمة غير موجودة
    printFailure("الخدمة المطلوبة غير موجودة", 404);
    // إيقاف التنفيذ
    exit;
}

// ⚠️ منع عجيب لكنه لازم: لا يحجز العميل خدمته الخاصة
if ((int) $service['ps_user_id'] === $usersId) {
    // رسالة توضح المنع
    printFailure("لا يمكن حجز خدمة العميل على نفسه", 409);
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 7: تحديد السعر ونوع العمولة وقيمتها (override أو افتراضي)
// ============================================================
// السعر: قيمة الموظف إن أُرسلت وإلا سعر الخدمة
$price = $inputPrice !== '' ? (float) $inputPrice : (float) $service['ps_price'];

// ⚠️ السعر لا يكون سالباً
if ($price < 0) {
    // رسالة توضح المنع
    printFailure("السعر لا يكون سالباً");
    // إيقاف التنفيذ
    exit;
}

// ⚠️ الحد الأقصى يحمي عمود DECIMAL(10,2)
if ($price > 99999999) {
    // رسالة توضح الحد الأقصى
    printFailure("السعر كبير جداً");
    // إيقاف التنفيذ
    exit;
}

// نوع العمولة: اختيار الموظف إن أُرسل وإلا نوع الخدمة
$commissionType = $inputCommissionType !== ''
    ? $inputCommissionType
    : ($service['ps_commission_type'] ?: 'percent');

// قيمة العمولة: قيمة الموظف إن أُرسلت وإلا قيمة الخدمة
$commissionValue = $inputCommissionValue !== ''
    ? (float) $inputCommissionValue
    : (float) $service['ps_commission_value'];

// ⚠️ القيمة لا تكون سالبة
if ($commissionValue < 0) {
    // رسالة توضح المنع
    printFailure("قيمة العمولة لا تكون سالبة");
    // إيقاف التنفيذ
    exit;
}

// ⚠️ النسبة المئوية لا تزيد على 100 لأنها ستضاعف السعر بلا معنى
if ($commissionType === 'percent' && $commissionValue > 100) {
    // رسالة توضح الحد الأقصى
    printFailure("نسبة العمولة لا تتجاوز 100%");
    // إيقاف التنفيذ
    exit;
}

// حالة التفاوض: ما أرسله الموظف وإلا قيمة الخدمة من القاعدة
$negotiable = $inputNegotiable !== ''
    ? (int) $inputNegotiable
    : (int) $service['ps_negotiable'];
// ⭐ نُثبّتها على 0 أو 1 فقط
if ($negotiable !== 1) $negotiable = 0;

// ============================================================
// ⭐ الخطوة 8: فحص كوبون الخصم إن اختاره الموظف
// ============================================================
// معرّف الكوبون النهائي (0 = بدون كوبون)
$couponId = $inputCouponId !== '' ? (int) $inputCouponId : 0;
// نسبة خصم الكوبون (0 = لا خصم)
$couponDiscount = 0;
// ⭐ صف الكوبون (يبقى null إن لم يُختر كوبون — لتفادي تحذير المتغير غير المعرَّف)
$coupon = null;

// نفحص الكوبون فقط إن اختار الموظف واحداً
if ($couponId > 0) {
    // نقرأ الكوبون مع كل شروط صلاحيته في استعلام واحد
    $stmtCoupon = $con->prepare(
        "SELECT coupon_id, coupon_name, coupon_count, coupon_discount,
                coupon_expiredate, coupon_is_active
         FROM coupon WHERE coupon_id = ? LIMIT 1"
    );
    // تنفيذ الاستعلام
    $stmtCoupon->execute([$couponId]);
    // جلب الكوبون
    $coupon = $stmtCoupon->fetch(PDO::FETCH_ASSOC);

    // إن لم يوجد الكوبون نُرجع 404
    if (!$coupon) {
        // رسالة توضح أن الكوبون غير موجود
        printFailure("كوبون الخصم غير موجود", 404);
        // إيقاف التنفيذ
        exit;
    }

    // ⚠️ الكوبون المحذوف حذفاً ناعماً غير صالح
    if ((int) $coupon['coupon_is_active'] !== 1) {
        // رسالة توضح أن الكوبون موقوف
        printFailure("كوبون الخصم موقوف وغير صالح للاستخدام", 409);
        // إيقاف التنفيذ
        exit;
    }

    // ⚠️ نفاد الرصيد يمنع الاستخدام
    if ((int) $coupon['coupon_count'] <= 0) {
        // رسالة توضح نفاد الرصيد
        printFailure("انتهى رصيد كوبون الخصم", 409);
        // إيقاف التنفيذ
        exit;
    }

    // ⚠️ انتهاء الصلاحية يمنع الاستخدام
    if (!empty($coupon['coupon_expiredate'])) {
        // تحويل تاريخ الانتهاء لطابع زمني
        $expireTs = strtotime($coupon['coupon_expiredate']);
        // إن كان في الماضي نرفض
        if ($expireTs !== false && $expireTs < time()) {
            // رسالة توضح انتهاء الصلاحية
            printFailure(
                "انتهت صلاحية كوبون الخصم بتاريخ " . $coupon['coupon_expiredate'],
                410
            );
            // إيقاف التنفيذ
            exit;
        }
    }

    // ⭐ قراءة نسبة الخصم
    $couponDiscount = (float) $coupon['coupon_discount'];
}

// ============================================================
// ⭐ الخطوة 9: حساب العمولة والخصم والإجمالي
//
// ⭐ قاعدة المشروع: خصم الكوبون من العمولة فقط
//   الإجمالي = السعر + (العمولة - خصم الكوبون من العمولة)
// ============================================================
// العمولة قبل الخصم
if ($commissionType === 'percent') {
    // مثال: 5000 × 10 ÷ 100 = 500 ج.م
    $commissionBefore = ($price * $commissionValue) / 100;
} else {
    // في حالة المبلغ الثابت نستخدم القيمة كما هي
    $commissionBefore = $commissionValue;
}

// ⭐ خصم الكوبون بالجنيه — من العمولة فقط
$couponDiscountAmount = 0;
if ($couponDiscount > 0 && $commissionBefore > 0) {
    // مثال: 500 × 10 ÷ 100 = 50 ج.م
    $couponDiscountAmount = ($commissionBefore * $couponDiscount) / 100;
}

// العمولة بعد الخصم
$commissionAfter = $commissionBefore - $couponDiscountAmount;
// حماية منطقية: لا عمولة سالبة
if ($commissionAfter < 0) $commissionAfter = 0;

// ⭐ الإجمالي: لو أرسل الموظف إجمالياً يدوياً نحترمه، وإلا نحسبه
if ($inputTotalPrice !== '') {
    // الإجمالي الذي قرره الموظف بعد تقريبه
    $totalPrice = round((float) $inputTotalPrice, 2);
} else {
    // ⭐ الإجمالي = السعر + العمولة بعد خصم الكوبون
    $totalPrice = round($price + $commissionAfter, 2);
}

// ⚠️ الإجمالي لا يكون سالباً
if ($totalPrice < 0) {
    // رسالة توضح المنع
    printFailure("الإجمالي لا يكون سالباً");
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 10: تجهيز تاريخ بدء العمل
// ============================================================
// نتحقق من الصيغة فقط إن أُرسل تاريخ
$vailabilityValue = null;
if ($vailabilityStatus !== '') {
    // تحويل النص لطابع زمني
    $timestampCheck = strtotime($vailabilityStatus);
    // إن لم تُفهم الصيغة نرفض
    if ($timestampCheck === false) {
        // رسالة توضح الصيغة المطلوبة
        printFailure("صيغة تاريخ بدء العمل غير صحيحة");
        // إيقاف التنفيذ
        exit;
    }
    // ⭐ إعادة تنسيق التاريخ بالصيغة التي يقبلها العمود datetime
    $vailabilityValue = date("Y-m-d H:i:s", $timestampCheck);
}

// ============================================================
// الخطوة 10-ب: منع التنفيذ أو الإكمال عند الإنشاء قبل حلول موعد بدء العمل
// approved مسموح دائماً لأنه انتظار للتنفيذ وليس بدءاً فعلياً
// ============================================================
$restrictedCreateBeforeStart = ['in_progress', 'completed'];
if (in_array($bookingStatus, $restrictedCreateBeforeStart, true)) {
    // لا تنفيذ ولا إكمال بدون موعد بدء عمل
    if ($vailabilityValue === null) {
        printFailure(
            "لا يمكن تنفيذ أو إكمال الحجز قبل تحديد موعد بدء العمل",
            409
        );
        exit;
    }
    // تحويل الموعد لطابع زمني
    $createStartTimestamp = strtotime($vailabilityValue);
    // نرفض إذا كان الموعد في المستقبل
    if ($createStartTimestamp === false || $createStartTimestamp > time()) {
        printFailure(
            "لا يمكن تنفيذ أو إكمال الحجز قبل حلول موعد بدء العمل",
            409
        );
        exit;
    }
}

// ============================================================
// ⭐⭐ الخطوة 11: بدء المعاملة
//
// ⚠️ لماذا؟ ثلاثة كتابات مترابطة:
//   1. INSERT bookings
//   2. إنقاص رصيد الكوبون
//   3. تسجيل الإجراء في سجل الموظفين (خارج المعاملة عمداً)
//   فلو فشل إنقاص الكوبون بعد كتابة الحجز لصارت الأرقام غير متسقة.
//
// ⚠️ الجداول كلها InnoDB فالمعاملات تعمل ✅
// ============================================================
// بدء المعاملة
$con->beginTransaction();

// ⚠️ نحيط كل عمليات الكتابة بـ try/catch لضمان التراجع
try {

    // ============================================================
    // الخطوة 12: إنقاص رصيد الكوبون قبل كتابة الحجز
    //
    // ⚠️ الترتيب مقصود: الشرط coupon_count > 0 يمنع نزول الرصيد
    //   تحت الصفر، ولو فشل هنا نتراجع بلا أي كتابة للحجز.
    // ============================================================
    if ($couponId > 0) {
        // استعلام يخصم استخداماً واحداً بشرط وجود رصيد
        $stmtConsume = $con->prepare(
            "UPDATE coupon SET coupon_count = coupon_count - 1
             WHERE coupon_id = ? AND coupon_count > 0"
        );
        // تنفيذ الخصم
        $stmtConsume->execute([$couponId]);

        // ⚠️ إن لم يتغير أي صف فمعنى ذلك أن الرصيد نفد لحظياً
        if ($stmtConsume->rowCount() === 0) {
            // إلغاء المعاملة
            $con->rollBack();
            // رسالة توضح نفاد الرصيد
            printFailure("انتهى رصيد كوبون الخصم", 409);
            // إيقاف التنفيذ
            exit;
        }
    }

    // ============================================================
    // ⭐ الخطوة 13: إدخال صف الحجز معتمَداً دون انتظار موافقة
    // الحالة ليست مكتملة دائماً — الموظف يختارها أو تبقى approved
    // ============================================================
    $bookingData = [
        // ⭐ معرّف العميل
        "booking_users_id"          => $usersId,
        // ⭐ معرّف الخدمة
        "booking_ps_id"             => $psId,
        // عدد الطلبات: قيمة الموظف إن أُرسلت وإلا 1 لأن الإدارة أنشأت الحجز
        "booking_number_of_requests" => $inputRequestsCount !== ''
            ? (int) $inputRequestsCount
            : 1,
        // تفاصيل الطلب
        "booking_order_details"     => $orderDetails,
        // السعر الأساسي للخدمة
        "booking_ps_price"          => $price,
        // هل السعر قابل للتفاوض؟
        "booking_ps_negotiable"     => $negotiable,
        // نوع العمولة
        "booking_ps_commission_type"  => $commissionType,
        // قيمة العمولة
        "booking_ps_commission_value" => $commissionValue,
        // ⭐ الإجمالي النهائي بعد العمولة والخصم
        "booking_total_price"       => $totalPrice,
        // ⭐ معرّف الكوبون (0 = بدون)
        "booking_coupon"            => $couponId,
        // ⭐ العميل وافق لأن الموظف معه الآن
        "booking_confirmation"      => 1,
        // ⭐ لا يحتاج موافقة لاحقة لأن الإدارة هي من أنشأت الحجز
        "booking_approval"          => 1,
        // الحالة المختارة: approved أو in_progress أو completed
        "booking_status"            => $bookingStatus,
        // ملاحظات الإدارة
        "booking_admin_notes"       => $adminNotes !== '' ? $adminNotes : null,
        // ⭐ الموظف الذي أنشأ/راجع الحجز
        "booking_reviewed_by"       => $adminId,
        // ⭐ تاريخ المراجعة
        "booking_reviewed_at"       => date("Y-m-d H:i:s"),
    ];

    // ⭐ إضافة تاريخ بدء العمل فقط إن أُرسل
    if ($vailabilityValue !== null) {
        // تاريخ ووقت بدء عمل مقدم الخدمة
        $bookingData["booking_vailability_status"] = $vailabilityValue;
    }

    // ⚠️ نمرر false لمنع الدالة من طباعة JSON لأننا نبني رداً مخصصاً
    $insertResult = insertData("bookings", $bookingData, false);

    // إن فشل الإدخال نتراجع ونُرجع فشلاً واضحاً
    if ($insertResult <= 0) {
        // إلغاء المعاملة
        $con->rollBack();
        // رسالة توضح فشل الإدخال
        printFailure("فشل إنشاء الحجز");
        // إيقاف التنفيذ
        exit;
    }

    // ⭐ قراءة معرّف الحجز الجديد
    $bookingId = (int) $con->lastInsertId();

    // ============================================================
    // ⭐ الخطوة 14: تثبيت المعاملة
    // ============================================================
    // تثبيت كل الكتابات في قاعدة البيانات
    $con->commit();

    // ============================================================
    // ⭐ الخطوة 15: تسجيل الإجراء في سجل الموظفين
    // ⚠️ بعد commit عمداً: السجل لا يجب أن يُلغي حجزاً ناجحاً
    // ============================================================
    logAdminActivity(
        // الموظف المنفّذ
        $currentAdmin,
        // نوع الإجراء
        'create_customer_booking',
        // نوع الكيان
        'bookings',
        // معرّف الحجز الجديد
        $bookingId,
        // ⭐ تفاصيل تسهل المراجعة المالية لاحقاً
        [
            // اسم العميل
            "client_name"       => $client['users_full_name'],
            // هاتف العميل
            "client_phone"      => $client['users_phone'],
            // معرّف الخدمة
            "ps_id"             => $psId,
            // السعر
            "price"             => $price,
            // العمولة بعد الخصم
            "commission_after"  => round($commissionAfter, 2),
            // الإجمالي
            "total_price"       => $totalPrice,
            // معرّف الكوبون (0 = بدون)
            "coupon_id"         => $couponId,
            // ⭐ القيمة التي خصمها الكوبون فعلاً
            "coupon_discount_amount" => round($couponDiscountAmount, 2),
        ]
    );

    // ============================================================
    // الخطوة 16: إرسال الرد النهائي
    // ============================================================
    echo json_encode([
        // حالة العملية
        "status"  => "success",
        // ⭐ رسالة عربية جاهزة للعرض حسب الحالة المختارة
        "message" => "تم إنشاء الحجز بنجاح دون انتظار موافقة — الحالة: " . $bookingStatusAr,
        // بيانات النتيجة
        "data"    => [

            // ===== ⭐ بيانات الحجز =====
            "booking" => [
                // معرّف الحجز الجديد
                "booking_id"          => $bookingId,
                // معرّف العميل
                "booking_users_id"    => $usersId,
                // معرّف الخدمة
                "booking_ps_id"       => $psId,
                // تفاصيل الطلب
                "booking_order_details" => $orderDetails,
                // السعر الأساسي
                "booking_ps_price"    => round($price, 2),
                // هل قابل للتفاوض؟
                "booking_ps_negotiable" => $negotiable === 1,
                // تاريخ بدء العمل
                "booking_vailability_status" => $vailabilityValue,
                // الحالة المختارة عند الإنشاء (ليست مكتملة دائماً)
                "booking_status"      => $bookingStatus,
                // ترجمة الحالة جاهزة من PHP
                "booking_status_ar"   => $bookingStatusAr,
                // ملاحظات الإدارة
                "booking_admin_notes" => $adminNotes !== '' ? $adminNotes : null,
                // تاريخ الإنشاء
                "booking_created_at"  => date("Y-m-d H:i:s"),
            ],

            // ===== ⭐ ملخص السعر والعمولة =====
            "price_breakdown" => [
                // سعر الخدمة (لا يتأثر بالكوبون)
                "service_price"          => round($price, 2),
                // نوع العمولة
                "commission_type"        => $commissionType,
                // قيمة العمولة كما هي
                "commission_value"       => round($commissionValue, 2),
                // العمولة قبل الخصم
                "commission_before"      => round($commissionBefore, 2),
                // ⭐ قيمة خصم الكوبون بالجنيه
                "coupon_discount_amount" => round($couponDiscountAmount, 2),
                // العمولة بعد الخصم
                "commission_after"       => round($commissionAfter, 2),
                // الإجمالي لو لم يُستخدم كوبون
                "total_without_coupon"   => round($price + $commissionBefore, 2),
                // ⭐ الإجمالي النهائي
                "total_price"            => $totalPrice,
                // هل السعر قابل للتفاوض
                "negotiable"             => $negotiable === 1,
            ],

            // ===== ⭐ بيانات الكوبون المستخدم =====
            "coupon" => $couponId > 0 ? [
                // معرّف الكوبون
                "coupon_id"       => $couponId,
                // اسم الكوبون
                "coupon_name"     => $coupon['coupon_name'] ?? null,
                // نسبة الخصم
                "coupon_discount" => $couponDiscount,
            ] : null,

            // ===== ⭐ بيانات العميل =====
            "client" => [
                // معرّف المستخدم
                "users_id"        => (int) $client['users_id'],
                // الاسم الكامل
                "users_full_name" => $client['users_full_name'],
                // رقم الهاتف
                "users_phone"     => $client['users_phone'],
            ],

            // ===== سجل التنفيذ =====
            "created_by" => [
                // معرّف الموظف المنفّذ
                "admin_id"   => $adminId,
                // اسم الموظف المنفّذ
                "admin_name" => $currentAdmin['admin_full_name'] ?? null,
                // ⭐ دور الموظف مترجماً
                "role_ar"    => $currentAdmin['role_name_ar'] ?? null,
            ],
        ],
    ]);

// ============================================================
// ⭐⭐ الخطوة 17: معالجة أي خطأ والتراجع الكامل
// ============================================================
} catch (Exception $e) {

    // ⚠️ التراجع عن المعاملة إن كانت ما زالت مفتوحة
    // ⭐ نفحص أولاً لأن rollBack على معاملة مغلقة يرمي استثناء آخر
    if ($con->inTransaction()) {
        // إلغاء كل ما كُتب في قاعدة البيانات
        $con->rollBack();
    }

    // ⭐ تسجيل الخطأ في سجل PHP للتشخيص
    error_log("add_customer_booking failed: " . $e->getMessage());

    // ⚠️ إرجاع رسالة الخطأ للموظف
    printFailure(
        "تعذّر إتمام العملية: " . $e->getMessage(),
        500
    );
    // إيقاف التنفيذ
    exit;
}