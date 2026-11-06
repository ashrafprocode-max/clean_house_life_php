<?php
// ============================================================
// ملف: bookings/customer/my_booking_details.php
// الغرض: تفاصيل حجز واحد لصاحب الحجز (عميل أو مقدم خدمة يحجز خدمة)
//        بدون كشف أرقام هواتف أو مستندات حسّاسة تخص الإدارة
//
// الحقول الإجبارية: booking_id · users_id
// الطريقة: POST
// ============================================================

// ربط ملف الاتصال بقاعدة البيانات (مستويان للوراء لأن الملف داخل bookings/customer)
include "../../connect.php";

// ============================================================
// الخطوة 1: استقبال معرّف الحجز ومعرّف المستخدم
// ============================================================
// معرّف الحجز المطلوب عرضه
$booking_id = filterRequest("booking_id");
// معرّف المستخدم صاحب الجلسة (لمنع الاطلاع على حجوزات الآخرين)
$users_id = filterRequest("users_id");
// إن لم يُرسل users_id نجرّب الاسم البديل
if ($users_id === '') {
    // قراءة المعرّف بالحقل البديل
    $users_id = filterRequest("booking_users_id");
}

// ============================================================
// الخطوة 2: التحقق من الحقول الإجبارية
// ============================================================
// الحجز والمستخدم مطلوبان معاً
if (empty($booking_id) || empty($users_id)) {
    // رسالة توضح الحقول الناقصة
    printFailure("Required fields: booking_id, users_id");
    // إيقاف التنفيذ
    exit;
}

// تحويل المعرّفين لأرقام صحيحة
$booking_id = (int) $booking_id;
$users_id = (int) $users_id;

// رفض القيم غير الصالحة
if ($booking_id <= 0 || $users_id <= 0) {
    // رسالة أن المعرّفات غير صالحة
    printFailure("booking_id and users_id must be valid");
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 3: جلب الحجز مع المزود والمهنة والكوبون
// شرط الملكية: booking_users_id = المستخدم الحالي فقط
// ============================================================
$stmt = $con->prepare(
    "SELECT
        b.booking_id,
        b.booking_users_id,
        b.booking_ps_id,
        b.booking_order_details,
        b.booking_ps_price,
        b.booking_ps_negotiable,
        b.booking_ps_commission_type,
        b.booking_ps_commission_value,
        b.booking_ps_commission_amount,
        b.booking_client_commission_type,
        b.booking_client_commission_value,
        b.booking_client_commission_amount,
        b.booking_total_price,
        b.booking_coupon,
        b.booking_confirmation,
        b.booking_vailability_status,
        b.booking_approval,
        b.booking_status,
        b.booking_number_of_requests,
        b.booking_created_at,
        b.booking_updated_at,
        pu.users_id              AS provider_users_id,
        pu.users_full_name       AS provider_full_name,
        pp.providers_id,
        pp.providers_profile_image,
        pp.providers_rating,
        pp.providers_gender,
        pp.providers_experience,
        pp.providers_nationality,
        pp.providers_current_governorate,
        pp.providers_current_area,
        pp.providers_is_verified,
        ps.ps_id,
        ps.ps_user_id,
        ps.ps_price              AS catalog_ps_price,
        ps.ps_negotiable         AS catalog_ps_negotiable,
        ps.ps_commission_type    AS catalog_commission_type,
        ps.ps_commission_value   AS catalog_commission_value,
        ps.ps_commission_amount  AS catalog_commission_amount,
        ps.ps_sub_cat_id,
        c.categories_name_ar,
        c.categories_name_en,
        sc.sub_name_ar,
        sc.sub_name_en,
        st.timing_name_ar,
        st.timing_name_en,
        cp.coupon_id,
        cp.coupon_name,
        cp.coupon_discount
     FROM bookings b
     INNER JOIN provider_services ps
        ON ps.ps_id = b.booking_ps_id
     INNER JOIN users pu
        ON pu.users_id = ps.ps_user_id
     LEFT JOIN professional_profiles pp
        ON pp.providers_users_id = ps.ps_user_id
     LEFT JOIN categories c
        ON c.categories_id = ps.ps_cat_id
     LEFT JOIN sub_categories sc
        ON sc.sub_id = ps.ps_sub_cat_id
     LEFT JOIN service_timing st
        ON st.timing_id = ps.ps_timing_id
     LEFT JOIN coupon cp
        ON cp.coupon_id = b.booking_coupon AND b.booking_coupon > 0
     WHERE b.booking_id = ? AND b.booking_users_id = ?
     LIMIT 1"
);
// تنفيذ الاستعلام بتمرير معرّف الحجز ومعرّف المستخدم
$stmt->execute([$booking_id, $users_id]);
// جلب صف البيانات كمصفوفة
$row = $stmt->fetch(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 4: التحقق من وجود الحجز وملكيته
// ============================================================
if (!$row) {
    // إما الحجز غير موجود أو لا يخص هذا المستخدم
    printFailure("Booking not found", 404);
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 5: بناء رابط الصورة
// ============================================================
// استخراج مسار مجلد جذر المشروع
$scriptDir = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'])));
// تجميع الرابط الكامل
$baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . ($scriptDir === '/' || $scriptDir === '\\' ? '' : $scriptDir);
// إزالة الشرطة المائلة الزائدة
$baseUrl = rtrim($baseUrl, '/');

// ============================================================
// الخطوة 6: حساب العمولة وخصم الكوبون للعرض
// الخصم يُطبَّق على العمولة فقط كما في باقي المشروع
// ============================================================
// سعر مقدم الخدمة الأساسي وقت الحجز
$providerBasePrice = (float) $row['booking_ps_price'];
// ⭐ مبلغ عمولة مقدم الخدمة (A) المخفية المحفوظ في الحجز
$providerCommissionAmount = (float) $row['booking_ps_commission_amount'];
if ($providerCommissionAmount <= 0) {
    // احتياطياً للصفوف القديمة نحسبها من النوع والقيمة
    $pcType = $row['booking_ps_commission_type'];
    $pcValue = (float) $row['booking_ps_commission_value'];
    $providerCommissionAmount = ($pcType === 'percent' && $providerBasePrice > 0)
        ? ($providerBasePrice * $pcValue) / 100
        : $pcValue;
}
$providerCommissionAmount = round($providerCommissionAmount, 2);

// ⭐ سعر الخدمة الذي يراه العميل = الأساسي + عمولة مقدم الخدمة المخفية
$servicePrice = round($providerBasePrice + $providerCommissionAmount, 2);

// ⭐ عمولة العميل (B) الظاهرة في الحجز
$commissionType = $row['booking_client_commission_type'] ?: 'percent';
$commissionValue = (float) $row['booking_client_commission_value'];
// مبلغ عمولة العميل بالجنيه المحفوظ في الحجز
$commissionAmount = (float) $row['booking_client_commission_amount'];
if ($commissionAmount <= 0) {
    // احتياطياً نحسبها من السعر الأساسي والنوع والقيمة
    $commissionAmount = ($commissionType === 'percent' && $providerBasePrice > 0)
        ? ($providerBasePrice * $commissionValue) / 100
        : $commissionValue;
}

// نسبة خصم الكوبون إن وُجد
$couponDiscount = $row['coupon_discount'] !== null ? (float) $row['coupon_discount'] : 0;

// قيمة الخصم من عمولة العميل فقط
$couponDiscountAmount = 0;
// نخصم فقط إذا وُجد كوبون وعمولة موجبة
if ($couponDiscount > 0 && $commissionAmount > 0) {
    // نسبة الخصم من مبلغ عمولة العميل
    $couponDiscountAmount = ($commissionAmount * $couponDiscount) / 100;
}

// عمولة العميل بعد الخصم
$commissionAfterCoupon = $commissionAmount - $couponDiscountAmount;
// لا نسمح بعمولة سالبة
if ($commissionAfterCoupon < 0) {
    // تصفير العمولة بعد الخصم الزائد
    $commissionAfterCoupon = 0;
}

// الإجمالي بدون كوبون لعرضه مشطوباً إن وُجد خصم
// = سعر الخدمة الظاهر + عمولة العميل
$totalWithoutCoupon = $servicePrice + $commissionAmount;

// ============================================================
// الخطوة 7: خريطة الحالة العربية
// ============================================================
$statusMap = [
    'pending' => 'قيد الانتظار',
    'negotiating' => 'قيد التفاوض',
    'approved' => 'تمت الموافقة',
    'in_progress' => 'قيد التنفيذ',
    'completed' => 'مكتملة',
    'cancelled' => 'ملغاة',
];
// نص الحالة بالعربي
$statusLabelAr = $statusMap[$row['booking_status']] ?? (string) $row['booking_status'];

// هل يُسمح بإعادة الطلب؟ نعم دائماً من هذه الصفحة (السيرفر يمنع التكرار النشط)
$canRebook = true;

// ============================================================
// ⭐ الخطوة 7-أ: قراءة عمولة العميل (B) الحالية من إعدادات التطبيق
//   تُستخدم عند إعادة الحجز من هذه الصفحة لتطابق سعر الحجز الجديد
// ============================================================
$catalogClientCommissionType = 'percent';
$catalogClientCommissionValue = 10;
try {
    $stmtSettings = $con->prepare(
        "SELECT setting_key, setting_value FROM app_settings
         WHERE setting_key IN ('client_commission_type','client_commission_value')"
    );
    $stmtSettings->execute();
    foreach ($stmtSettings->fetchAll(PDO::FETCH_ASSOC) as $setting) {
        if ($setting['setting_key'] === 'client_commission_type') {
            $catalogClientCommissionType = $setting['setting_value'] ?: 'percent';
        } elseif ($setting['setting_key'] === 'client_commission_value') {
            $catalogClientCommissionValue = (float) $setting['setting_value'];
        }
    }
} catch (Throwable $e) {
    // إن لم يوجد الجدول بعد نستخدم القيمة الافتراضية
}
if ($catalogClientCommissionType !== 'fixed') {
    $catalogClientCommissionType = 'percent';
}

// ⭐ مبلغ عمولة مقدم الخدمة (A) الحالي في الكتالوج (الحاشية للتسعير الحالي)
$catalogProviderCommissionAmount = (float) ($row['catalog_commission_amount'] ?? 0);
if ($catalogProviderCommissionAmount <= 0) {
    $ccType = $row['catalog_commission_type'] ?: 'percent';
    $ccValue = (float) $row['catalog_commission_value'];
    $catalogBase = (float) $row['catalog_ps_price'];
    $catalogProviderCommissionAmount = ($ccType === 'percent' && $catalogBase > 0)
        ? ($catalogBase * $ccValue) / 100
        : $ccValue;
}
$catalogProviderCommissionAmount = round($catalogProviderCommissionAmount, 2);

// ============================================================
// الخطوة 8: إرسال الرد الكامل
// ============================================================
echo json_encode([
    // حالة العملية
    "status" => "success",
    // رسالة نجاح
    "message" => "Booking details fetched successfully",
    // البيانات التفصيلية
    "data" => [
        // ===== بيانات الحجز =====
        "booking" => [
            // كود الطلب
            "booking_id" => (int) $row['booking_id'],
            // صاحب الحجز
            "booking_users_id" => (int) $row['booking_users_id'],
            // معرّف الخدمة لإعادة الحجز
            "booking_ps_id" => (int) $row['booking_ps_id'],
            // تفاصيل الطلب
            "booking_order_details" => $row['booking_order_details'],
            // ⭐ سعر الخدمة الظاهر للعميل (شامل عمولة مقدم الخدمة المخفية)
            "booking_ps_price" => $servicePrice,
            // ⭐ سعر مقدم الخدمة الأساسي (للاستخدام الداخلي فقط)
            "booking_provider_base_price" => $providerBasePrice,
            // قابل للتفاوض؟
            "booking_ps_negotiable" => (bool) $row['booking_ps_negotiable'],
            // ⭐ مبلغ عمولة مقدم الخدمة (A) المخفية
            "booking_ps_commission_amount" => $providerCommissionAmount,
            // ⭐ نوع عمولة العميل (B)
            "booking_client_commission_type" => $commissionType,
            // ⭐ قيمة/نسبة عمولة العميل (B)
            "booking_client_commission_value" => $commissionValue,
            // ⭐ مبلغ عمولة العميل (B) بالجنيه
            "booking_client_commission_amount" => round($commissionAmount, 2),
            // الإجمالي النهائي
            "booking_total_price" => (float) $row['booking_total_price'],
            // معرّف الكوبون
            "booking_coupon" => (int) $row['booking_coupon'],
            // تأكيد العميل
            "booking_confirmation" => (int) $row['booking_confirmation'],
            // تاريخ ووقت بدء التنفيذ إن وُجد
            "booking_vailability_status" => $row['booking_vailability_status'],
            // موافقة الإدارة
            "booking_approval" => (int) $row['booking_approval'],
            // رمز الحالة
            "booking_status" => $row['booking_status'],
            // نص الحالة بالعربي
            "booking_status_label_ar" => $statusLabelAr,
            // عدد الطلبات السابق لمقدم الخدمة وقت الحجز
            "booking_number_of_requests" => (int) $row['booking_number_of_requests'],
            // تاريخ طلب الخدمة
            "booking_created_at" => $row['booking_created_at'],
            // تاريخ آخر تحديث
            "booking_updated_at" => $row['booking_updated_at'],
        ],
        // ===== ملخص السعر =====
        // ⭐ العميل يرى: سعر الخدمة الظاهر (شامل عمولة المقدم) + عمولة العميل
        "price_breakdown" => [
            // ⭐ سعر الخدمة الظاهر للعميل (شامل عمولة مقدم الخدمة المخفية)
            "service_price" => $servicePrice,
            // سعر مقدم الخدمة الأساسي (للاستخدام الداخلي)
            "provider_base_price" => $providerBasePrice,
            // ⭐ نوع عمولة العميل (B)
            "commission_type" => $commissionType,
            // ⭐ قيمة/نسبة عمولة العميل (B)
            "commission_value" => $commissionValue,
            // ⭐ مبلغ عمولة العميل (B) قبل الخصم
            "commission_before" => round($commissionAmount, 2),
            // مبلغ خصم الكوبون من عمولة العميل
            "coupon_discount_amount" => round($couponDiscountAmount, 2),
            // عمولة العميل بعد الخصم
            "commission_after" => round($commissionAfterCoupon, 2),
            // الإجمالي بدون كوبون
            "total_without_coupon" => round($totalWithoutCoupon, 2),
            // الإجمالي النهائي
            "total_price" => (float) $row['booking_total_price'],
            // قابل للتفاوض
            "negotiable" => (bool) $row['booking_ps_negotiable'],
        ],
        // ===== الكوبون =====
        "coupon" => [
            // معرّف الكوبون
            "coupon_id" => $row['coupon_id'] !== null ? (int) $row['coupon_id'] : 0,
            // اسم الكوبون
            "coupon_name" => $row['coupon_name'],
            // نسبة الخصم
            "coupon_discount" => $row['coupon_discount'] !== null
                ? (int) $row['coupon_discount'] : 0,
        ],
        // ===== مقدم الخدمة (بدون هاتف) =====
        "provider" => [
            // معرّف المستخدم مقدم الخدمة
            "users_id" => (int) $row['provider_users_id'],
            // الاسم الكامل
            "users_full_name" => $row['provider_full_name'],
            // رابط الصورة
            "profile_image" => $row['providers_profile_image']
                ? $baseUrl . '/upload/provider_profiles/' . $row['providers_profile_image']
                : null,
            // التقييم
            "providers_rating" => $row['providers_rating'] !== null
                ? (float) $row['providers_rating'] : 4.5,
            // النوع
            "providers_gender" => $row['providers_gender'],
            // الخبرة
            "providers_experience" => $row['providers_experience'],
            // الجنسية
            "providers_nationality" => $row['providers_nationality'],
            // المحافظة
            "providers_current_governorate" => $row['providers_current_governorate'],
            // المنطقة
            "providers_current_area" => $row['providers_current_area'],
            // هل موثّق؟
            "is_verified" => ((int) $row['providers_is_verified'] === 1),
        ],
        // ===== المهنة والخدمة لإعادة الحجز =====
        "service" => [
            // معرّف الخدمة
            "ps_id" => (int) $row['ps_id'],
            // معرّف مقدم الخدمة
            "ps_user_id" => (int) $row['ps_user_id'],
            // معرّف القسم الفرعي
            "ps_sub_cat_id" => $row['ps_sub_cat_id'] !== null
                ? (int) $row['ps_sub_cat_id'] : null,
            // سعر الكتالوج الحالي (قد يختلف عن سعر الحجز القديم)
            "catalog_ps_price" => $row['catalog_ps_price'] !== null
                ? (float) $row['catalog_ps_price'] : $servicePrice,
            // قابلية التفاوض الحالية
            "catalog_ps_negotiable" => (bool) $row['catalog_ps_negotiable'],
            // نوع عمولة مقدم الخدمة (A) بالكتالوج
            "catalog_commission_type" => $row['catalog_commission_type'],
            // قيمة عمولة مقدم الخدمة (A) بالكتالوج
            "catalog_commission_value" => $row['catalog_commission_value'] !== null
                ? (float) $row['catalog_commission_value'] : 0,
            // ⭐ مبلغ عمولة مقدم الخدمة (A) بالكتالوج
            "catalog_commission_amount" => $catalogProviderCommissionAmount,
            // ⭐ نوع عمولة العميل (B) الحالية لإعادة الحجز
            "catalog_client_commission_type" => $catalogClientCommissionType,
            // ⭐ قيمة/نسبة عمولة العميل (B) الحالية لإعادة الحجز
            "catalog_client_commission_value" => round($catalogClientCommissionValue, 2),
            // اسم القسم بالعربي
            "category_name_ar" => $row['categories_name_ar'],
            // اسم القسم بالإنجليزي
            "category_name_en" => $row['categories_name_en'],
            // اسم المهنة بالعربي
            "profession_name_ar" => $row['sub_name_ar'],
            // اسم المهنة بالإنجليزي
            "profession_name_en" => $row['sub_name_en'],
            // اسم التوقيت بالعربي
            "timing_name_ar" => $row['timing_name_ar'],
            // اسم التوقيت بالإنجليزي
            "timing_name_en" => $row['timing_name_en'],
        ],
        // هل يظهر زر إعادة الطلب
        "can_rebook" => $canRebook,
    ],
], JSON_UNESCAPED_UNICODE);
