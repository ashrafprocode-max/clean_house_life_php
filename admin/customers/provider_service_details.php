<?php
// ============================================================
// ملف: admin/customers/provider_service_details.php
// الغرض: كل تفاصيل مقدم الخدمة لخدمة واحدة (ps_id)
//        يجمع جدول users + professional_profiles + provider_services
//        في رد واحد تعرضه صفحة تفاصيل المقدم في اللوحة
// الطريقة: POST
//
// ⭐⭐ الحقل الإجباري: ps_id
//   وهو معرّف الصف في provider_services (الخدمة نفسها)
//   وليس معرّف المستخدم — لأن مقدم الخدمة قد يقدّم عدة خدمات
//   ونريد عرض تفاصيل الخدمة المختارة بعينها (السعر/العمولة/التوقيت).
//
// الصلاحية المطلوبة: providers.view
// ============================================================

// تضمين ملف الاتصال (مستويان للوراء لأن الملف داخل admin/customers)
include "../../connect.php";

// ============================================================
// الخطوة 1: التحقق من توكن الإدارة والصلاحية
// ============================================================
// التحقق من التوكن وجلب بيانات الموظف مع صلاحياته
$currentAdmin = checkAdminToken();

// ⭐ فرض صلاحية عرض مقدمي الخدمة
requirePermission($currentAdmin, 'providers.view');

// ============================================================
// الخطوة 2: استقبال معرّف الخدمة
// ============================================================
// معرّف الصف في provider_services - إجباري
$psId = (int) filterRequest("ps_id");

// إذا لم يُرسل معرّف صحيح نوقف التنفيذ
if ($psId <= 0) {
    // رسالة توضح الحقل المطلوب
    printFailure("معرّف الخدمة ps_id مطلوب");
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// ⭐ الخطوة 3: جلب كل البيانات في استعلام واحد
//
// ⭐ لماذا استعلام واحد؟ لأن الموظف واقف أمام العميل،
//   وثلاثة استعلامات متتابعة على اتصال ضعيف تعني انتظاراً ملموساً.
// ============================================================
// استعلام يجمع الحساب والملف المهني والخدمة وأسماء الأقسام
$stmt = $con->prepare(
    "SELECT
        -- ===== بيانات الخدمة =====
        ps.ps_id,
        ps.ps_user_id,
        ps.ps_cat_id,
        ps.ps_sub_cat_id,
        ps.ps_timing_id,
        ps.ps_price,
        ps.ps_price_estimate,
        ps.ps_negotiable,
        ps.ps_commission_type,
        ps.ps_commission_value,
        ps.ps_commission_amount,
        -- ===== بيانات الحساب (users) =====
        u.users_id,
        u.users_full_name,
        u.users_phone,
        u.users_country,
        u.users_region,
        u.users_is_active,
        u.users_approve,
        u.users_type,
        u.users_created_at,
        -- ===== بيانات الملف المهني (professional_profiles) =====
        pp.providers_id,
        pp.providers_experience,
        pp.providers_bio,
        pp.providers_rating,
        pp.providers_age,
        pp.providers_gender,
        pp.providers_nationality,
        pp.providers_profile_image,
        pp.providers_current_country,
        pp.providers_current_governorate,
        pp.providers_current_area,
        pp.providers_current_address,
        pp.providers_id_card_front,
        pp.providers_id_card_back,
        pp.providers_criminal_record_image,
        pp.providers_utility_receipt_image,
        pp.providers_is_verified,
        pp.providers_review_note,
        pp.providers_reviewed_at,
        -- ===== أسماء القسم والفرع والتوقيت =====
        c.categories_name_ar,
        c.categories_name_en,
        sc.sub_name_ar,
        sc.sub_name_en,
        st.timing_name_ar,
        st.timing_name_en
     FROM provider_services ps
     INNER JOIN users u                 ON u.users_id = ps.ps_user_id
     LEFT  JOIN professional_profiles pp ON pp.providers_users_id = u.users_id
     LEFT  JOIN categories c            ON c.categories_id = ps.ps_cat_id
     LEFT  JOIN sub_categories sc       ON sc.sub_id = ps.ps_sub_cat_id
     LEFT  JOIN service_timing st       ON st.timing_id = ps.ps_timing_id
     WHERE ps.ps_id = ?
     LIMIT 1"
);
// تنفيذ الاستعلام بمعرّف الخدمة
$stmt->execute([$psId]);
// جلب الصف
$row = $stmt->fetch(PDO::FETCH_ASSOC);

// ⚠️ إن لم توجد الخدمة نُرجع 404
if (!$row) {
    // رسالة توضح أن الخدمة غير موجودة
    printFailure("الخدمة المطلوبة غير موجودة", 404);
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// ⭐ الخطوة 4: هل للحساب ملف مهني مكتمل؟
//
// ⭐ providers_id فارغ يعني أن الحساب أُنشئ بلا ملف مهني
//   فنعرض بيانات الخدمة فقط بلا بيانات شخصية.
// ============================================================
// هل الملف المهني موجود؟
$hasProfile = !empty($row['providers_id']);

// ============================================================
// ⭐ الخطوة 5: بناء الرابط الأساسي لكل الصور
// ============================================================
// استخراج مسار جذر المشروع بالرجوع مستويين
$scriptDir = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'])));

// بناء الرابط الكامل مع البروتوكول والمضيف
$baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . ($scriptDir === '/' || $scriptDir === '\\' ? '' : $scriptDir);

// إزالة أي شرطة مائلة زائدة من النهاية
$baseUrl = rtrim($baseUrl, '/');

// ⭐ دالة محلية صغيرة لبناء رابط ملف أو إرجاع null
$buildUrl = function ($folder, $fileName) use ($baseUrl) {
    // تنظيف الاسم من أي أسطر أو مسافات مسرَّبة
    $name = trim(str_replace(["\n", "\r"], '', (string) $fileName));
    // إن كان الاسم فارغاً لا رابط
    if ($name === '')
        return null;
    // ⭐ نُرجع الرابط الكامل مباشرة حتى لا يعتمد على منطق فلاتر
    return $baseUrl . '/upload/' . $folder . '/' . $name;
};

// ============================================================
// الخطوة 6: حساب العمولة والإجمالي من جدول provider_services
// ============================================================
// السعر الأساسي
$price = (float) $row['ps_price'];
// نوع العمولة
$commissionType = $row['ps_commission_type'] ?: 'percent';
// قيمة العمولة
$commissionValue = (float) $row['ps_commission_value'];

// ⭐ مبلغ عمولة مقدم الخدمة (A) بالجنيه (نفس معادلة add_booking.php)
// نُفضّل المبلغ المخزّن مسبقاً كما اعتمدته الإدارة
$commissionAmount = (float) ($row['ps_commission_amount'] ?? 0);
if ($commissionAmount <= 0) {
    if ($commissionType === 'percent') {
        // مثال: 10 × 10 ÷ 100 = 1 ج.م
        $commissionAmount = ($price * $commissionValue) / 100;
    } else {
        // في حالة المبلغ الثابت نستخدم القيمة كما هي
        $commissionAmount = $commissionValue;
    }
}
// تقريب مبلغ عمولة مقدم الخدمة
$commissionAmount = round($commissionAmount, 2);

// ⭐ الإجمالي للعميل = السعر + عمولة مقدم الخدمة (المخفية)
$totalForClient = $price + $commissionAmount;

// ============================================================
// ⭐ قراءة عمولة العميل (B) من إعدادات التطبيق
// ============================================================
$clientCommissionType = 'percent';
$clientCommissionValue = 10;
try {
    $stmtSettings = $con->prepare(
        "SELECT setting_key, setting_value FROM app_settings
         WHERE setting_key IN ('client_commission_type','client_commission_value')"
    );
    $stmtSettings->execute();
    foreach ($stmtSettings->fetchAll(PDO::FETCH_ASSOC) as $setting) {
        if ($setting['setting_key'] === 'client_commission_type') {
            $clientCommissionType = $setting['setting_value'] ?: 'percent';
        } elseif ($setting['setting_key'] === 'client_commission_value') {
            $clientCommissionValue = (float) $setting['setting_value'];
        }
    }
} catch (Throwable $e) {
    // إن لم يوجد الجدول بعد نستخدم القيمة الافتراضية
}
if ($clientCommissionType !== 'fixed') {
    $clientCommissionType = 'percent';
}

// ⭐ حالة التوثيق
$isVerified = (int) $row['providers_is_verified'];

// ============================================================
// الخطوة 7: إرسال الرد الكامل
// ============================================================
echo json_encode([
    // حالة العملية
    "status" => "success",
    // ⭐ رسالة عربية جاهزة للعرض
    "message" => "تم جلب تفاصيل مقدم الخدمة",
    // بيانات النتيجة
    "data" => [

        // ===== ⭐ بيانات الحساب (users) =====
        "account" => [
            // معرّف المستخدم
            "users_id" => (int) $row['users_id'],
            // الاسم الكامل
            "users_full_name" => $row['users_full_name'],
            // ⭐ رقم الهاتف (يُستخدم لزر الواتساب)
            "users_phone" => $row['users_phone'],
            // الدولة
            "users_country" => $row['users_country'],
            // المنطقة
            "users_region" => $row['users_region'],
            // هل الحساب مفعّل؟
            "users_is_active" => (int) $row['users_is_active'],
            // هل تحقّق من رقمه؟
            "users_approve" => (int) $row['users_approve'],
            // نوع الحساب
            "users_type" => $row['users_type'],
            // تاريخ إنشاء الحساب
            "users_created_at" => $row['users_created_at'],
        ],

        // ===== ⭐ بيانات الملف المهني (professional_profiles) =====
        "profile" => $hasProfile ? [
            // معرّف الملف المهني
            "providers_id" => (int) $row['providers_id'],
            // سنوات الخبرة
            "providers_experience" => $row['providers_experience'] !== null
                ? (int) $row['providers_experience']
                : null,
            // نبذة عنه
            "providers_bio" => $row['providers_bio'],
            // ⭐ التقييم
            "providers_rating" => (float) $row['providers_rating'],
            // العمر
            "providers_age" => $row['providers_age'] !== null
                ? (int) $row['providers_age']
                : null,
            // النوع
            "providers_gender" => $row['providers_gender'],
            // الجنسية
            "providers_nationality" => $row['providers_nationality'],
            // ⭐ رابط الصورة الشخصية الكامل
            "profile_image_url" => $buildUrl(
                'provider_profiles',
                $row['providers_profile_image']
            ),
            // الدولة الحالية
            "providers_current_country" => $row['providers_current_country'],
            // ⭐ المحافظة الحالية
            "providers_current_governorate" => $row['providers_current_governorate'],
            // ⭐ المنطقة الحالية
            "providers_current_area" => $row['providers_current_area'],
            // العنوان التفصيلي
            "providers_current_address" => $row['providers_current_address'],
            // ⭐ صور البطاقة (للاطلاع فقط في اللوحة)
            "id_card_front_url" => $buildUrl(
                'id_cards',
                $row['providers_id_card_front']
            ),
            "id_card_back_url" => $buildUrl(
                'id_cards',
                $row['providers_id_card_back']
            ),
            // صورة الصحيفة الجنائية
            "criminal_record_url" => $buildUrl(
                'criminal_records',
                $row['providers_criminal_record_image']
            ),
            // صورة إيصال المرافق
            "utility_receipt_url" => $buildUrl(
                'utility_receipt',
                $row['providers_utility_receipt_image']
            ),
            // ⭐ 0 / 1 / 2 لتحديد أيقونة التوثيق في فلاتر
            "is_verified" => $isVerified,
            // ⭐ ترجمة حالة التوثيق جاهزة من PHP
            "verification_ar" => match ($isVerified) {
                1 => 'موثّق',
                2 => 'مرفوض',
                default => 'قيد المراجعة',
            },
            // ملاحظة المراجعة
            "review_note" => $row['providers_review_note'],
            // تاريخ المراجعة
            "reviewed_at" => $row['providers_reviewed_at'],
        ] : null,

        // ===== ⭐ بيانات الخدمة (provider_services) =====
        "service" => [
            // معرّف الخدمة
            "ps_id" => (int) $row['ps_id'],
            // معرّف مقدم الخدمة
            "ps_user_id" => (int) $row['ps_user_id'],
            // معرّف القسم الرئيسي
            "ps_cat_id" => (int) $row['ps_cat_id'],
            // معرّف القسم الفرعي
            "ps_sub_cat_id" => (int) $row['ps_sub_cat_id'],
            // معرّف التوقيت
            "ps_timing_id" => (int) $row['ps_timing_id'],
            // ⭐ أسماء الأقسام والتوقيت للعرض
            "category_name_ar" => $row['categories_name_ar'],
            "category_name_en" => $row['categories_name_en'],
            "sub_category_name_ar" => $row['sub_name_ar'],
            "sub_category_name_en" => $row['sub_name_en'],
            "timing_name_ar" => $row['timing_name_ar'],
            "timing_name_en" => $row['timing_name_en'],
            // السعر الأساسي
            "ps_price" => round($price, 2),
            // السعر التقديري
            "ps_price_estimate" => $row['ps_price_estimate'] !== null
                ? round((float) $row['ps_price_estimate'], 2)
                : null,
            // ⭐ نوع العمولة
            "commission_type" => $commissionType,
            // ⭐ قيمة العمولة
            "commission_value" => round($commissionValue, 2),
            // ⭐ عمولة مقدم الخدمة (A) بالجنيه (محسوبة ومخفية عن العميل)
            "commission_amount" => round($commissionAmount, 2),
            // ⭐ الإجمالي للعميل (السعر + عمولة مقدم الخدمة المخفية)
            "total_for_client" => round($totalForClient, 2),
            // ⭐ عمولة العميل (B) التي تظهر له في صفحة الحجز
            "client_commission_type" => $clientCommissionType,
            "client_commission_value" => round($clientCommissionValue, 2),
            // هل السعر قابل للتفاوض؟
            "ps_negotiable" => ((int) $row['ps_negotiable']) === 1,
            // ⭐ نص جاهز يعرضه الموظف للعميل
            "price_display" => round($price, 2) . ' ج.م',
            "total_display" => round($totalForClient, 2) . ' ج.م',
        ],
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
