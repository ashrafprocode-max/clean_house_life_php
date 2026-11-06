<?php
// ============================================================
// ملف: admin/customers/check_booking_coupon.php
// الغرض: فحص صلاحية كوبون خصم وحساب معاينة السعر في اللوحة
//        قبل تأكيد الحجز — نفس منطق bookings/customer/checkcoupon.php
//        لكن بصلاحيات الإدارة ومسارها
// الطريقة: POST
//
// ⭐⭐ قاعدة الخصم الحاسمة في المشروع:
//   نسبة الكوبون تُخصم من «عمولة التطبيق» فقط
//   ولا تُخصم من سعر مقدم الخدمة إطلاقاً.
//   الإجمالي = السعر + (العمولة - خصم الكوبون من العمولة)
//
// الحقول:
//   coupon_name  إجباري (اسم الكوبون كما كتبه الموظف)
//   ps_id        اختياري (لاعرض معاينة السعر مع الخصم)
//
// الصلاحية المطلوبة: coupons.view
// ============================================================

// تضمين ملف الاتصال (مستويان للوراء لأن الملف داخل admin/customers)
include "../../connect.php";

// ============================================================
// الخطوة 1: التحقق من توكن الإدارة والصلاحية
// ============================================================
// التحقق من التوكن وجلب بيانات الموظف مع صلاحياته
$currentAdmin = checkAdminToken();

// ⭐ فرض صلاحية عرض الكوبونات
requirePermission($currentAdmin, 'coupons.view');

// ============================================================
// الخطوة 2: استقبال الحقول
// ============================================================
// اسم الكوبون كما كتبه الموظف - إجباري
$couponName = trim(filterRequest("coupon_name"));
// معرّف الخدمة - اختياري (لحساب المعاينة)
$psId = (int) filterRequest("ps_id");

// إذا لم يُرسل اسم نوقف التنفيذ
if ($couponName === '') {
    // رسالة توضح الحقل المطلوب
    printFailure("أدخل اسم الكوبون أولاً");
    // إيقاف التنفيذ
    exit;
}

// ⚠️ العمود varchar(50) فالنص الأطول لا يطابق شيئاً أصلاً
if (mb_strlen($couponName) > 50) {
    // رسالة توضح الحد الأقصى
    printFailure("اسم الكوبون غير صحيح");
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// ⭐ الخطوة 3: البحث عن الكوبون مع فحص كل شروط صلاحيته
//
// ⭐ نفحص في استعلام واحد بدل ثلاثة:
//   1. الكوبون موجود بهذا الاسم؟
//   2. غير محذوف (coupon_is_active = 1)؟
//   3. لم تنتهِ صلاحيته ولم ينفد رصيده؟
// ============================================================
$stmt = $con->prepare(
    "SELECT
        coupon_id,
        coupon_name,
        coupon_count,
        coupon_discount,
        coupon_expiredate,
        coupon_is_active
     FROM coupon
     WHERE coupon_name = ?
     LIMIT 1"
);
// تنفيذ الاستعلام بالاسم
$stmt->execute([$couponName]);
// جلب الصف
$coupon = $stmt->fetch(PDO::FETCH_ASSOC);

// ============================================================
// ⭐ الحالة الأولى: الكوبون غير موجود
// ============================================================
if (!$coupon) {
    // رسالة توضح أن الاسم غير مسجّل
    printFailure("كوبون الخصم غير موجود", 404);
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// ⭐ الحالة الثانية: الكوبون محذوف (حذف ناعم)
// ⚠️ نفحصه قبل تاريخ الانتهاء لأن رسالته أوضح للموظف
// ============================================================
if ((int) $coupon['coupon_is_active'] !== 1) {
    // رسالة توضح أن الكوبون موقوف
    printFailure("كوبون الخصم موقوف وغير صالح للاستخدام", 409);
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// ⭐ الحالة الثالثة: نفاد رصيد الاستخدامات
// ============================================================
if ((int) $coupon['coupon_count'] <= 0) {
    // رسالة توضح نفاد الرصيد
    printFailure("انتهى رصيد كوبون الخصم — لم يبقَ استخدام متاح", 409);
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// ⭐ الحالة الرابعة: انتهت صلاحية الكوبون
//
// ⚠️ نقارن بالوقت الحالي بلغة PHP لا بـ NOW() في SQL
//    حتى تظهر الرسالة العربية الصحيحة بلا اعتماد على
//    فروق توقيت السيرفر وقاعدة البيانات.
// ============================================================
// تاريخ الانتهاء من القاعدة
$expireRaw = $coupon['coupon_expiredate'];

// هل التاريخ موجود فعلاً؟
if (!empty($expireRaw)) {
    // تحويل تاريخ الانتهاء لطابع زمني
    $expireTs = strtotime($expireRaw);

    // إذا كان التاريخ مفهوماً وكان في الماضي نرفض الكوبون
    if ($expireTs !== false && $expireTs < time()) {
        // رسالة توضح أن الصلاحية انتهت مع تاريخها
        printFailure(
            "انتهت صلاحية كوبون الخصم بتاريخ " . $expireRaw,
            410
        );
        // إيقاف التنفيذ
        exit;
    }
}

// ============================================================
// الخطوة 4: قراءة قيم الكوبون الصالح
// ============================================================
// معرّف الكوبون
$couponId = (int) $coupon['coupon_id'];
// اسمه
$couponNameDb = $coupon['coupon_name'];
// نسبة الخصم
$couponDiscount = (float) $coupon['coupon_discount'];
// تاريخ الانتهاء
$couponExpire = $coupon['coupon_expiredate'];
// الرصيد المتبقي
$couponRemaining = (int) $coupon['coupon_count'];

// ============================================================
// الخطوة 5: حساب المعاينة إن أُرسل معرّف الخدمة
// ============================================================
// تهيئة المعاينة بلا قيمة
$preview = null;

// نُجهّز المعاينة فقط إن أُرسل معرّف خدمة صحيح
if ($psId > 0) {
    // جلب سعر الخدمة وعمولتها من جدول provider_services
    $stmtService = $con->prepare(
        "SELECT ps_price, ps_commission_type, ps_commission_value, ps_commission_amount
         FROM provider_services
         WHERE ps_id = ?
         LIMIT 1"
    );
    // تنفيذ الاستعلام
    $stmtService->execute([$psId]);
    // جلب صف الخدمة
    $service = $stmtService->fetch(PDO::FETCH_ASSOC);

    // نحسب المعاينة فقط إن وُجدت الخدمة فعلاً
    if ($service) {
        // سعر مقدم الخدمة الأساسي
        $providerBasePrice = (float) $service['ps_price'];
        // نوع عمولة مقدم الخدمة (A)
        $providerCommissionType = $service['ps_commission_type'] ?: 'percent';
        // قيمة عمولة مقدم الخدمة (A)
        $providerCommissionValue = (float) $service['ps_commission_value'];

        // ⭐ مبلغ عمولة مقدم الخدمة (A) المخفية (نفس معادلة add_booking.php)
        $providerCommissionAmount = (float) ($service['ps_commission_amount'] ?? 0);
        if ($providerCommissionAmount <= 0) {
            if ($providerCommissionType === 'percent') {
                $providerCommissionAmount = ($providerBasePrice * $providerCommissionValue) / 100;
            } else {
                $providerCommissionAmount = $providerCommissionValue;
            }
        }
        $providerCommissionAmount = round($providerCommissionAmount, 2);

        // ⭐ سعر الخدمة الذي يراه العميل = الأساسي + عمولة مقدم الخدمة المخفية
        $servicePrice = round($providerBasePrice + $providerCommissionAmount, 2);

        // ============================================================
        // ⭐ قراءة عمولة العميل (B) من إعدادات التطبيق
        // ============================================================
        // النوع الافتراضي نسبة مئوية
        $clientCommissionType = 'percent';
        // القيمة الافتراضية 10%
        $clientCommissionValue = 10;
        try {
            // استعلام قراءة الإعدادين
            $stmtSettings = $con->prepare(
                "SELECT setting_key, setting_value FROM app_settings
                 WHERE setting_key IN ('client_commission_type','client_commission_value')"
            );
            // تنفيذ الاستعلام
            $stmtSettings->execute();
            // المرور على الإعدادين
            foreach ($stmtSettings->fetchAll(PDO::FETCH_ASSOC) as $setting) {
                if ($setting['setting_key'] === 'client_commission_type') {
                    $clientCommissionType = $setting['setting_value'] ?: 'percent';
                } elseif ($setting['setting_key'] === 'client_commission_value') {
                    $clientCommissionValue = (float) $setting['setting_value'];
                }
            }
        } catch (Throwable $e) {
            // إن لم يوجد الجدول بعد نستخدم الافتراضي
        }
        // تصحيح النوع
        if ($clientCommissionType !== 'fixed') {
            $clientCommissionType = 'percent';
        }

        // ⭐ مبلغ عمولة العميل (B) بالجنيه (من سعر مقدم الخدمة الأساسي)
        if ($clientCommissionType === 'percent') {
            $commissionBefore = ($providerBasePrice * $clientCommissionValue) / 100;
        } else {
            $commissionBefore = $clientCommissionValue;
        }
        $commissionBefore = round($commissionBefore, 2);

        // ⭐ خصم الكوبون بالجنيه — من عمولة العميل فقط
        $couponDiscountAmount = 0;
        if ($couponDiscount > 0 && $commissionBefore > 0) {
            // مثال: 1 × 10 ÷ 100 = 0.1 ج.م
            $couponDiscountAmount = ($commissionBefore * $couponDiscount) / 100;
        }

        // لا نجعل العمولة بعد الخصم سالبة
        $commissionAfter = $commissionBefore - $couponDiscountAmount;
        if ($commissionAfter < 0)
            $commissionAfter = 0;

        // الإجمالي بدون كوبون = سعر الخدمة الظاهر + عمولة العميل
        $totalWithoutCoupon = $servicePrice + $commissionBefore;

        // الإجمالي بعد خصم الكوبون من عمولة العميل
        $totalWithCoupon = $servicePrice + $commissionAfter;

        // بناء المعاينة
        $preview = [
            // سعر الخدمة الظاهر للعميل (شامل عمولة مقدم الخدمة)
            "service_price" => round($servicePrice, 2),
            // ⭐ نوع عمولة العميل
            "commission_type" => $clientCommissionType,
            // ⭐ قيمة/نسبة عمولة العميل
            "commission_value" => round($clientCommissionValue, 2),
            // عمولة العميل قبل الخصم
            "commission_before" => round($commissionBefore, 2),
            // ⭐ قيمة الخصم بالجنيه
            "coupon_discount_amount" => round($couponDiscountAmount, 2),
            // عمولة العميل بعد الخصم
            "commission_after" => round($commissionAfter, 2),
            // الإجمالي قبل الكوبون (لعرضه مشطوباً)
            "total_without_coupon" => round($totalWithoutCoupon, 2),
            // ⭐ الإجمالي النهائي بعد الكوبون
            "total_with_coupon" => round($totalWithCoupon, 2),
        ];
    }
}

// ============================================================
// الخطوة 6: إرسال الرد النهائي
// ============================================================
echo json_encode([
    // حالة العملية
    "status" => "success",
    // ⭐ رسالة عربية جاهزة للعرض
    "message" => "كوبون الخصم صالح للاستخدام",
    // بيانات الكوبون
    "data" => [
        // معرّف الكوبون (يُخزَّن في booking_coupon)
        "coupon_id" => $couponId,
        // اسم الكوبون
        "coupon_name" => $couponNameDb,
        // ⭐ نسبة الخصم
        "coupon_discount" => $couponDiscount,
        // تاريخ الانتهاء
        "coupon_expiredate" => $couponExpire,
        // ⭐ الرصيد المتبقي (معلومة للموظف)
        "remaining_count" => $couponRemaining,
        // ⭐ توضيح أن الخصم على العمولة فقط
        "discount_applies_on" => "commission_only",
        // ⭐ معاينة السعر (null إن لم يُرسل ps_id)
        "preview" => $preview,
    ],
    // ===== سجل التنفيذ =====
    "requested_by" => [
        // معرّف الموظف
        "admin_id" => (int) $currentAdmin['admin_id'],
        // اسم الموظف
        "admin_name" => $currentAdmin['admin_full_name'] ?? null,
    ],
]);
// إيقاف التنفيذ
exit;
