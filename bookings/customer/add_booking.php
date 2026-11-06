<?php
// ============================================================
// ملف: bookings/customer/add_booking.php
// الغرض: إضافة حجز جديد في جدول bookings
//        يُستدعى من صفحة booking_checkout.dart في Flutter
//
// ⭐ فكرة الأمان الأساسية:
//   العميل يُرسل 3 حقول فقط: (من هو / أي خدمة / تفاصيل طلبه)
//   ويُرسل اسم الكوبون اختيارياً وليس نسبة الخصم
//   أما السعر والعمولة ونسبة الخصم فتُقرأ من قاعدة البيانات
//   حتى لا يستطيع العميل التلاعب بأي رقم من التطبيق
//
// ⭐ قاعدة خصم الكوبون في هذا المشروع:
//   نسبة الكوبون تُخصم من قيمة العمولة فقط ولا تُخصم من سعر مقدم الخدمة
//   مثال: سعر 1000 ج.م + عمولة 100 ج.م = 1100 ج.م
//         كوبون 10% ⇒ العمولة = 100 - 10 = 90 ج.م
//         الإجمالي = 1000 + 90 = 1090 ج.م
//
// الطريقة: POST
// ============================================================

// ربط ملف الاتصال بقاعدة البيانات (مستويان للوراء لأن الملف داخل bookings/customer)
include "../../connect.php";
// تضمين دوال أحداث الإشعارات بعد نجاح إنشاء الحجز
include "../../includes/notification_hooks.php";

// ============================================================
// الخطوة 1: استقبال البيانات القادمة من Flutter
// ============================================================
// معرّف المستخدم الذي يطلب الحجز (client أو provider) - إجباري
$booking_users_id = filterRequest("booking_users_id");
// معرّف الخدمة المطلوبة من جدول provider_services - إجباري
$booking_ps_id = filterRequest("booking_ps_id");
// تفاصيل الخدمة التي يكتبها العميل بنفسه (مثل: جليسة لثلاثة أطفال أول أكتوبر) - إجباري
$booking_order_details = filterRequest("booking_order_details");
// ⭐ اسم كوبون الخصم الاختياري كما كتبه العميل
// نستقبل الاسم وليس نسبة الخصم حتى لا يستطيع العميل التلاعب بقيمة الخصم
$coupon_name = trim(filterRequest("coupon_name"));

// ============================================================
// الخطوة 2: التحقق من الحقول الإجبارية الثلاثة
// ============================================================
// إذا نقص أي حقل من الثلاثة نوقف التنفيذ ونُرجع رسالة واضحة لـ Flutter
if (empty($booking_users_id) || empty($booking_ps_id) || empty($booking_order_details)) {
    // إرسال رسالة فشل موحدة يفهمها Flutter
    printFailure("Required fields: booking_users_id, booking_ps_id, booking_order_details");
    // إيقاف تنفيذ باقي الملف
    exit;
}

// ============================================================
// الخطوة 3: التحقق من أن المستخدم الطالب موجود وحسابه نشط
// ============================================================
// تجهيز استعلام آمن للبحث عن المستخدم بشرط أن يكون مفعلاً وموافقاً عليه
$stmtUser = $con->prepare(
    "SELECT users_id, users_full_name, users_type
     FROM users
     WHERE users_id = ? AND users_is_active = 1 AND users_approve = 1
     LIMIT 1"
);
// تنفيذ الاستعلام بتمرير معرّف المستخدم
$stmtUser->execute([$booking_users_id]);
// جلب صف المستخدم كمصفوفة
$requester = $stmtUser->fetch(PDO::FETCH_ASSOC);

// إذا لم يوجد المستخدم أو حسابه غير مفعّل نرفض الطلب
if (!$requester) {
    // رسالة توضح أن الحساب غير صالح للحجز
    printFailure("User not found or account is not active");
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 4: جلب بيانات الخدمة والسعر والعمولة تلقائياً
//           من جدول provider_services مع بيانات مقدم الخدمة
// ============================================================
// استعلام يجمع الخدمة + بيانات المستخدم مقدم الخدمة + ملفه المهني + اسم القسم الفرعي
$stmtService = $con->prepare(
    "SELECT
        ps.ps_id,
        ps.ps_user_id,
        ps.ps_price,
        ps.ps_negotiable,
        ps.ps_commission_type,
        ps.ps_commission_value,
        ps.ps_commission_amount,
        u.users_full_name        AS provider_name,
        pp.providers_profile_image,
        pp.providers_rating,
        pp.providers_gender,
        sc.sub_name_ar,
        sc.sub_name_en
     FROM provider_services ps
     INNER JOIN users u
        ON u.users_id = ps.ps_user_id
     INNER JOIN professional_profiles pp
        ON pp.providers_users_id = ps.ps_user_id
     INNER JOIN sub_categories sc
        ON sc.sub_id = ps.ps_sub_cat_id
     WHERE ps.ps_id = ? AND u.users_is_active = 1 AND u.users_approve = 1
     LIMIT 1"
);
// تنفيذ الاستعلام بتمرير معرّف الخدمة
$stmtService->execute([$booking_ps_id]);
// جلب صف الخدمة كمصفوفة
$service = $stmtService->fetch(PDO::FETCH_ASSOC);

// إذا لم توجد الخدمة أو مقدمها غير نشط نرفض الحجز
if (!$service) {
    // رسالة توضح أن الخدمة غير متاحة للحجز
    printFailure("Service not found or provider is not active");
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 5: منع المستخدم من حجز خدمته الخاصة
// ============================================================
// مقارنة معرّف الطالب بمعرّف مقدم الخدمة
if ((int) $booking_users_id === (int) $service['ps_user_id']) {
    // رسالة ترفض حجز الشخص لخدمة نفسه
    printFailure("You cannot book your own service");
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 6: منع التكرار - حجز واحد نشط لكل مستخدم لكل خدمة
//           الحالات النشطة: pending / negotiating / approved / in_progress
// ============================================================
// استعلام يبحث عن حجز سابق ما زال قيد التنفيذ لنفس المستخدم ونفس الخدمة
$stmtDup = $con->prepare(
    "SELECT booking_id FROM bookings
     WHERE booking_users_id = ? AND booking_ps_id = ?
       AND booking_status IN ('pending','negotiating','approved','in_progress')
     LIMIT 1"
);
// تنفيذ الاستعلام بتمرير معرّف المستخدم ومعرّف الخدمة
$stmtDup->execute([$booking_users_id, $booking_ps_id]);
// إذا وُجد حجز نشط بالفعل نمنع إنشاء حجز مكرر
if ($stmtDup->rowCount() > 0) {
    // رسالة توضح وجود طلب سابق قيد المعالجة
    printFailure("You already have an active booking for this service");
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 7: نسخ السعر والعمولة من الخدمة (وليس من العميل)
// ============================================================
// السعر الأساسي الذي حدده مقدم الخدمة (مثال: 10 ج.م)
$booking_ps_price = (float) $service['ps_price'];
// هل الخدمة قابلة للتفاوض (0 أو 1)
$booking_ps_negotiable = (int) $service['ps_negotiable'];
// نوع عمولة مقدم الخدمة (A): fixed مبلغ ثابت أو percent نسبة مئوية
$booking_ps_commission_type = $service['ps_commission_type'] ?: 'percent';
// قيمة عمولة مقدم الخدمة (A) (مبلغ أو نسبة حسب النوع أعلاه)
$booking_ps_commission_value = (float) $service['ps_commission_value'];

// ============================================================
// الخطوة 7-أ: حساب مبلغ عمولة مقدم الخدمة (A) بالجنيه
// ⭐ هذه العمولة مخفية عن العميل تماماً
//   ونضيفها على السعر ليظهر للعميل السعر شاملاً العمولة
//   مثال: 10 ج + 10% (= 1 ج) ⇒ العميل يرى 11 ج
// ============================================================
// نُفضّل المبلغ المخزّن مسبقاً في الخدمة إن وُجد (كما اعتمدته الإدارة)
$providerCommissionAmount = (float) ($service['ps_commission_amount'] ?? 0);
// إن لم يكن مخزّناً بعد (خدمات قديمة) نحسبه من النوع والقيمة
if ($providerCommissionAmount <= 0) {
    // إذا كان النوع نسبة مئوية نحسبها من السعر
    if ($booking_ps_commission_type === 'percent' && $booking_ps_price > 0) {
        // مثال: 10 × 10 ÷ 100 = 1 ج.م
        $providerCommissionAmount = ($booking_ps_price * $booking_ps_commission_value) / 100;
    } else {
        // في حالة المبلغ الثابت نستخدم القيمة كما هي
        $providerCommissionAmount = $booking_ps_commission_value;
    }
}
// تقريب مبلغ عمولة مقدم الخدمة لخانتين عشريتين
$providerCommissionAmount = round((float) $providerCommissionAmount, 2);

// ⭐ السعر الذي يراه العميل للخدمة = سعر مقدم الخدمة + عمولته المخفية
// مثال: 10 + 1 = 11 ج.م
$servicePriceForClient = round($booking_ps_price + $providerCommissionAmount, 2);

// ============================================================
// ⭐ الخطوة 8: التحقق من كوبون الخصم (تحقق ثانٍ إجباري)
//   السبب: بين لحظة تحقق checkcoupon.php ولحظة ضغط زر التأكيد
//   قد تنتهي صلاحية الكوبون أو ينفد عدده بسبب عميل آخر
// ============================================================
// معرّف الكوبون المُخزَّن في حقل booking_coupon (صفر تعني لم يُستخدم كوبون)
$booking_coupon = 0;
// نسبة خصم الكوبون المئوية (صفر تعني لا يوجد خصم)
$couponDiscountPercent = 0;
// اسم الكوبون كما هو في قاعدة البيانات (لإرجاعه في الرد)
$appliedCouponName = null;

// نتحقق من الكوبون فقط إذا أرسل العميل اسماً غير فارغ
if ($coupon_name !== '') {
    // الوقت الحالي لمقارنته بتاريخ انتهاء صلاحية الكوبون
    $nowForCoupon = date("Y-m-d H:i:s");

    // استعلام آمن يجمع شروط الصلاحية الثلاثة: الاسم + التاريخ + العدد المتاح
    $stmtCoupon = $con->prepare(
        "SELECT coupon_id, coupon_name, coupon_discount
         FROM coupon
         WHERE coupon_name = ? AND coupon_expiredate > ? AND coupon_count > 0
         LIMIT 1"
    );
    // تنفيذ الاستعلام بتمرير اسم الكوبون والوقت الحالي
    $stmtCoupon->execute([$coupon_name, $nowForCoupon]);
    // جلب صف الكوبون كمصفوفة
    $couponRow = $stmtCoupon->fetch(PDO::FETCH_ASSOC);

    // إذا لم يوجد الكوبون أو انتهت صلاحيته أو نفد عدده نرفض الحجز
    if (!$couponRow) {
        // رسالة توضح أن الكوبون غير صالح للاستخدام الآن
        printFailure("Coupon is invalid, expired, or fully used", 409);
        // إيقاف التنفيذ
        exit;
    }

    // تخزين معرّف الكوبون ليُحفظ في حقل booking_coupon
    $booking_coupon = (int) $couponRow['coupon_id'];
    // تخزين نسبة الخصم لحساب قيمة الخصم من العمولة
    $couponDiscountPercent = (float) $couponRow['coupon_discount'];
    // تخزين اسم الكوبون الرسمي لإرجاعه لـ Flutter
    $appliedCouponName = $couponRow['coupon_name'];
}

// ============================================================
// ⭐ الخطوة 9: قراءة عمولة العميل (B) من إعدادات التطبيق
//   هذه العمولة تحدّدها الإدارة وتظهر للعميل في صفحة الحجز
//   عند الإطلاق: 0% أو 10%
// ============================================================
// نوع عمولة العميل الافتراضي نسبة مئوية
$booking_client_commission_type = 'percent';
// قيمة عمولة العميل الافتراضية 0% (تعدّلها الإدارة)
$booking_client_commission_value = 0;
try {
    // استعلام قراءة الإعدادين من جدول app_settings
    $stmtSettings = $con->prepare(
        "SELECT setting_key, setting_value FROM app_settings
         WHERE setting_key IN ('client_commission_type','client_commission_value')"
    );
    // تنفيذ الاستعلام
    $stmtSettings->execute();
    // المرور على الإعدادين وقراءتهما
    foreach ($stmtSettings->fetchAll(PDO::FETCH_ASSOC) as $setting) {
        if ($setting['setting_key'] === 'client_commission_type') {
            // نوع عمولة العميل
            $booking_client_commission_type = $setting['setting_value'] ?: 'percent';
        } elseif ($setting['setting_key'] === 'client_commission_value') {
            // قيمة/نسبة عمولة العميل
            $booking_client_commission_value = (float) $setting['setting_value'];
        }
    }
} catch (Throwable $e) {
    // إن لم يوجد جدول الإعدادات بعد نستخدم القيمة الافتراضية (percent 10)
}
// تصحيح النوع إن جاء غير متوقع
if ($booking_client_commission_type !== 'fixed') {
    // نعتبره نسبة مئوية في كل الحالات الأخرى
    $booking_client_commission_type = 'percent';
}

// ============================================================
// ⭐ الخطوة 9-أ: حساب مبلغ عمولة العميل (B) بالجنيه
//   نحسبها من سعر مقدم الخدمة الأساسي (نفس أساس عمولة مقدم الخدمة)
//   مثال: 10 × 10 ÷ 100 = 1 ج.م
// ============================================================
// تهيئة مبلغ عمولة العميل
$clientCommissionAmount = 0;
// نحسب بحسب النوع
if ($booking_client_commission_type === 'percent') {
    // نسبة مئوية من سعر مقدم الخدمة
    $clientCommissionAmount = ($booking_ps_price * $booking_client_commission_value) / 100;
} else {
    // مبلغ ثابت كما هو
    $clientCommissionAmount = $booking_client_commission_value;
}
// تقريب مبلغ عمولة العميل لخانتين عشريتين
$clientCommissionAmount = round((float) $clientCommissionAmount, 2);

// ============================================================
// ⭐ الخطوة 9-ب: خصم الكوبون من عمولة العميل (B) فقط
//   لا يمسّ سعر مقدم الخدمة إطلاقاً
// ============================================================
// تهيئة قيمة الخصم بصفر
$couponDiscountAmount = 0;
// نحسب الخصم فقط إذا وُجدت نسبة كوبون وعمولة عميل أكبر من صفر
if ($couponDiscountPercent > 0 && $clientCommissionAmount > 0) {
    // مثال: 1 × 10 ÷ 100 = 0.1 ج.م خصم
    $couponDiscountAmount = ($clientCommissionAmount * $couponDiscountPercent) / 100;
}

// عمولة العميل بعد خصم الكوبون (مثال: 1 - 0.1 = 0.9 ج.م)
$clientCommissionAfterCoupon = $clientCommissionAmount - $couponDiscountAmount;
// حماية منطقية: لا نسمح للعمولة بأن تصبح رقماً سالباً
if ($clientCommissionAfterCoupon < 0) {
    // تصفير العمولة في هذه الحالة الاستثنائية
    $clientCommissionAfterCoupon = 0;
}

// ============================================================
// ⭐ الخطوة 9-ج: الإجمالي النهائي
//   الإجمالي = سعر الخدمة الظاهر (شامل عمولة مقدم الخدمة A)
//              + عمولة العميل (B) بعد خصم الكوبون
//   مثال: 11 + 1 = 12 ج.م
// ============================================================
$booking_total_price = round($servicePriceForClient + $clientCommissionAfterCoupon, 2);

// ============================================================
// الخطوة 10: حساب عدد الطلبات السابقة الناجحة لمقدم الخدمة
//           (يظهر في بطاقة المقدم: "تم طلبه 15 مرة")
// ============================================================
// استعلام يعد الحجوزات المكتملة لكل خدمات هذا المقدم
$stmtCount = $con->prepare(
    "SELECT COUNT(b.booking_id) AS done_count
     FROM bookings b
     INNER JOIN provider_services ps2
        ON ps2.ps_id = b.booking_ps_id
     WHERE ps2.ps_user_id = ? AND b.booking_status = 'completed'"
);
// تنفيذ الاستعلام بتمرير معرّف مقدم الخدمة
$stmtCount->execute([$service['ps_user_id']]);
// قراءة الرقم الناتج من الاستعلام
$doneCount = (int) $stmtCount->fetchColumn();
// إذا لم توجد حجوزات مكتملة بعد نستخدم الرقم الافتراضي المطلوب حالياً وهو 3
$booking_number_of_requests = ($doneCount > 0) ? $doneCount : 3;

// ============================================================
// الخطوة 11: تحديد وقت التوفر الافتراضي
//   حالياً كل مقدمي الخدمة متاحون، لذلك نضع التاريخ والوقت الحالي
// ============================================================
// توليد التاريخ والوقت الحالي بصيغة datetime التي يقبلها MySQL
$booking_vailability_status = date("Y-m-d H:i:s");

// ============================================================
// الخطوة 12: تجهيز بيانات الحجز للإدخال
// ============================================================
// تجهيز مصفوفة البيانات بأسماء الأعمدة الحقيقية في الجدول
$insertData = [
    // معرّف المستخدم صاحب الحجز
    "booking_users_id" => $booking_users_id,
    // معرّف الخدمة المحجوزة
    "booking_ps_id" => $booking_ps_id,
    // تفاصيل الخدمة التي كتبها العميل
    "booking_order_details" => $booking_order_details,
    // السعر الأساسي المنسوخ من الخدمة
    "booking_ps_price" => $booking_ps_price,
    // هل الخدمة قابلة للتفاوض
    "booking_ps_negotiable" => $booking_ps_negotiable,
    // نوع العمولة fixed أو percent
    "booking_ps_commission_type" => $booking_ps_commission_type,
    // قيمة العمولة كما هي في الخدمة
    "booking_ps_commission_value" => $booking_ps_commission_value,
    // ⭐ مبلغ عمولة مقدم الخدمة (A) بالجنيه (مخفية عن العميل)
    "booking_ps_commission_amount" => $providerCommissionAmount,
    // ⭐ نوع عمولة العميل (B) الظاهرة في الحجز
    "booking_client_commission_type" => $booking_client_commission_type,
    // ⭐ قيمة/نسبة عمولة العميل (B)
    "booking_client_commission_value" => $booking_client_commission_value,
    // ⭐ مبلغ عمولة العميل (B) بالجنيه (الظاهر في الفاتورة)
    "booking_client_commission_amount" => $clientCommissionAmount,
    // ⭐ معرّف الكوبون المستخدم (صفر إن لم يُستخدم كوبون)
    // إرساله إجباري لأن العمود NOT NULL وبدون قيمة افتراضية
    "booking_coupon" => $booking_coupon,
    // الإجمالي النهائي بعد العمولتين وخصم الكوبون
    "booking_total_price" => $booking_total_price,
    // تأكيد العميل = 1 لأنه هو من ضغط زر تأكيد الطلب
    "booking_confirmation" => 1,
    // وقت بدء العمل الافتراضي (الوقت الحالي)
    "booking_vailability_status" => $booking_vailability_status,
    // موافقة الإدارة = 0 لأن الإدارة لم تراجع الطلب بعد
    "booking_approval" => 0,
    // حالة الطلب الأولية: قيد الانتظار
    "booking_status" => 'pending',
    // عدد الطلبات السابقة الناجحة لمقدم الخدمة
    "booking_number_of_requests" => $booking_number_of_requests,
];

// ============================================================
// ⭐ الخطوة 13: تنفيذ الإدخال وإنقاص عدد الكوبون داخل Transaction
//   السبب: لو نجح الإدخال وفشل إنقاص الكوبون (أو العكس)
//   ستفقد قاعدة البيانات تناسقها، لذلك نجعلهما عملية واحدة لا تنفصل
// ============================================================
// بدء المعاملة (Transaction) لضمان تنفيذ العمليتين معاً أو إلغائهما معاً
$con->beginTransaction();

// نستخدم try/catch لضمان التراجع (Rollback) عند أي خطأ غير متوقع
try {
    // إنقاص عدد استخدامات الكوبون فقط إذا استخدم العميل كوبوناً
    if ($booking_coupon > 0) {
        // ⚠️ الشرط coupon_count > 0 داخل جملة UPDATE نفسها
        // يمنع نزول العدد تحت الصفر حتى لو حجز عميلان في نفس اللحظة
        $stmtUseCoupon = $con->prepare(
            "UPDATE coupon
             SET coupon_count = coupon_count - 1
             WHERE coupon_id = ? AND coupon_count > 0 AND coupon_expiredate > ?"
        );
        // تنفيذ التحديث بتمرير معرّف الكوبون والوقت الحالي
        $stmtUseCoupon->execute([$booking_coupon, date("Y-m-d H:i:s")]);

        // إذا لم يتغيّر أي صف فمعنى ذلك أن الكوبون نفد أو انتهى في هذه اللحظة
        if ($stmtUseCoupon->rowCount() === 0) {
            // إلغاء كل ما حدث في المعاملة
            $con->rollBack();
            // رسالة توضح أن الكوبون لم يعد متاحاً
            printFailure("Coupon is no longer available. Please remove it and try again.", 409);
            // إيقاف التنفيذ
            exit;
        }
    }

    // تنفيذ عملية إدخال الحجز باستخدام دالتك الموحدة مع false لمنع طباعة JSON مرتين
    $result = insertData("bookings", $insertData, false);

    // إذا فشل الإدخال نتراجع عن إنقاص الكوبون أيضاً
    if ($result <= 0) {
        // إلغاء كل ما حدث في المعاملة
        $con->rollBack();
        // رسالة فشل واضحة
        printFailure("Failed to create booking. Please try again.");
        // إيقاف التنفيذ
        exit;
    }

    // قراءة معرّف الحجز الجديد قبل تثبيت المعاملة
    $newBookingId = (int) $con->lastInsertId();

    // تثبيت المعاملة نهائياً في قاعدة البيانات
    $con->commit();

} catch (Exception $e) {
    // في حال وجود معاملة مفتوحة نتراجع عنها لحماية البيانات
    if ($con->inTransaction()) {
        // إلغاء كل ما حدث في المعاملة
        $con->rollBack();
    }
    // إرسال رسالة خطأ السيرفر مع سبب الاستثناء
    printFailure("Failed to create booking", 500, ["error" => $e->getMessage()]);
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 14: بناء رابط صورة مقدم الخدمة
//   نرجع ثلاثة مستويات للوراء للوصول لجذر المشروع
//   حتى يصبح المسار: clean_house_life_php/upload/provider_profiles/
// ============================================================
// استخراج مسار مجلد جذر المشروع من مسار السكربت الحالي
$scriptDir = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'])));
// تجميع الرابط الكامل مع البروتوكول واسم النطاق
$baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . ($scriptDir === '/' || $scriptDir === '\\' ? '' : $scriptDir);
// إزالة أي شرطة مائلة زائدة من نهاية الرابط
$baseUrl = rtrim($baseUrl, '/');

// ============================================================
// الخطوة 15: إرسال الرد النهائي لـ Flutter
// ============================================================
// إشعار الإدارة بحجز جديد بعد نجاح المعاملة
try {
    notifyBookingCreated(
        [
            "booking_id" => $newBookingId,
            "booking_ps_id" => $booking_ps_id,
        ],
        [
            "users_id" => $requester['users_id'],
            "users_full_name" => $requester['users_full_name'],
        ]
    );
} catch (Throwable $e) {
    // لا نوقف إنشاء الحجز إذا فشل الإشعار
}

// إرسال JSON يحتوي على معرّف الحجز وبطاقة المقدم وملخص السعر
echo json_encode([
    // حالة العملية
    "status" => "success",
    // رسالة نجاح واضحة
    "message" => "Booking created successfully",
    // بيانات الحجز الكاملة
    "data" => [
        // معرّف الحجز الجديد الذي أنشأته قاعدة البيانات
        "booking_id" => $newBookingId,
        // معرّف الخدمة المحجوزة
        "booking_ps_id" => (int) $booking_ps_id,

        // ===== بطاقة مقدم الخدمة المختصرة لعرضها في booking_checkout.dart =====
        "provider_card" => [
            // معرّف مقدم الخدمة
            "provider_user_id" => (int) $service['ps_user_id'],
            // اسم مقدم الخدمة الكامل
            "provider_name" => $service['provider_name'],
            // رابط صورة مقدم الخدمة كاملاً أو null إن لم يرفع صورة
            "profile_image" => $service['providers_profile_image']
                ? $baseUrl . '/upload/provider_profiles/' . $service['providers_profile_image']
                : null,
            // التقييم الحقيقي وإن كان صفراً نُرجع 4.5 كقيمة افتراضية حالياً
            "rating" => ((float) $service['providers_rating'] > 0)
                ? (float) $service['providers_rating']
                : 4.5,
            // نوع مقدم الخدمة (ذكر / أنثى)
            "gender" => $service['providers_gender'],
            // اسم المهنة بالعربي (القسم الفرعي)
            "profession_ar" => $service['sub_name_ar'],
            // اسم المهنة بالإنجليزي
            "profession_en" => $service['sub_name_en'],
            // عدد المرات التي تم طلب هذا المقدم فيها بنجاح
            "requests_count" => $booking_number_of_requests,
        ],

        // ===== حالة التوفر لعرض العلامة الخضراء =====
        "availability" => [
            // حالياً كل المقدمين متاحون لذلك القيمة true دائماً
            "is_available" => true,
            // تاريخ ووقت بدء العمل المتوقع
            "available_at" => $booking_vailability_status,
        ],

        // ===== ملخص السعر لعرضه في قسم Price Breakdown =====
        // ⭐ العميل يرى: سعر الخدمة (شامل عمولة مقدم الخدمة المخفية) + عمولة العميل
        "price_breakdown" => [
            // ⭐ سعر الخدمة الذي يراه العميل = سعر مقدم الخدمة + عمولته المخفية (مثال: 11)
            "service_price" => round($servicePriceForClient, 2),
            // سعر مقدم الخدمة الأساسي بدون عمولته (للاستخدام الداخلي فقط)
            "provider_base_price" => round($booking_ps_price, 2),
            // ⭐ نوع عمولة العميل (B): fixed أو percent
            "commission_type" => $booking_client_commission_type,
            // ⭐ قيمة/نسبة عمولة العميل (B) كما هي
            "commission_value" => round($booking_client_commission_value, 2),
            // ⭐ مبلغ عمولة العميل (B) بالجنيه قبل خصم الكوبون
            "commission_before" => round($clientCommissionAmount, 2),
            // ⭐ قيمة خصم الكوبون المخصومة من عمولة العميل فقط
            "coupon_discount_amount" => round($couponDiscountAmount, 2),
            // ⭐ عمولة العميل بعد خصم الكوبون (هي المضافة فعلاً على السعر)
            "commission_after" => round($clientCommissionAfterCoupon, 2),
            // الإجمالي لو لم يُستخدم كوبون (لعرضه مشطوباً في الواجهة)
            "total_without_coupon" => round($servicePriceForClient + $clientCommissionAmount, 2),
            // الإجمالي النهائي الذي يدفعه العميل
            "total_price" => $booking_total_price,
            // هل السعر قابل للتفاوض
            "negotiable" => (bool) $booking_ps_negotiable,
        ],

        // ===== بيانات الكوبون المستخدم =====
        "coupon" => [
            // معرّف الكوبون المحفوظ في حقل booking_coupon (صفر = لا كوبون)
            "coupon_id" => $booking_coupon,
            // اسم الكوبون أو null إن لم يُستخدم
            "coupon_name" => $appliedCouponName,
            // نسبة الخصم المئوية المطبَّقة
            "coupon_discount" => (int) $couponDiscountPercent,
        ],

        // ===== حالة الطلب الحالية =====
        "booking_status" => 'pending',
        // تأكيد العميل
        "booking_confirmation" => 1,
        // موافقة الإدارة (لم تتم بعد)
        "booking_approval" => 0,
    ],
]);
?>