<?php
// ============================================================
// ملف: admin/customers/list_service_providers.php
// الغرض: قائمة مقدمي الخدمة في لوحة الإدارة مع فلترة وبحث
//        وصفحات (Pagination) — يقرأها تاب «طلب خدمة» ليعرض
//        بطاقات المقدمين بعد اختيار القسم والضغط على بحث
// الطريقة: POST
//
// ⭐⭐ يخدم حالتين:
//   1. القسم الرئيسي + الفرعي المختارَين في الـ Dropdown
//   2. زر البحث النصي (اسم مقدم الخدمة / اسم المنطقة)
//
// الحقول الاختيارية: cat_id · sub_cat_id · search · governorate · area
//                    · gender · page · limit
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
// الخطوة 2: استقبال الفلاتر (كلها اختيارية)
// ============================================================
// معرّف القسم الرئيسي المختار
$catId = filterRequest("cat_id");
// معرّف القسم الفرعي المختار
$subCatId = filterRequest("sub_cat_id");
// ⭐ نص البحث الحر (اسم مقدم الخدمة)
$search = trim(filterRequest("search"));
// فلتر المحافظة
$governorate = trim(filterRequest("governorate"));
// فلتر المنطقة
$area = trim(filterRequest("area"));
// فلتر النوع (ذكر / أنثى)
$gender = trim(filterRequest("gender"));

// ============================================================
// الخطوة 3: تجهيز الصفحات
// ============================================================
// رقم الصفحة المطلوبة (نبدأ من 1)
$page = (int) filterRequest("page");
// إذا لم تُرسل صفحة أو كانت أقل من 1 نعتبرها الأولى
if ($page < 1)
    $page = 1;

// ⭐ حجم الصفحة بحدّه الأقصى 50 حتى لا يتعطل السيرفر
$limit = (int) filterRequest("limit");
// القيمة الافتراضية 10 والحد الأقصى 50
if ($limit < 1)
    $limit = 10;
if ($limit > 50)
    $limit = 50;

// ⭐ حساب الإزاحة: كم صفاً نتجاهل قبل أول صف في الصفحة
$offset = ($page - 1) * $limit;

// ============================================================
// الخطوة 4: بناء شرط WHERE ديناميكياً
//
// ⚠️ نبني الاستعلام بمعاملات مُسمّاة (placeholders) لا بدمج نصوص
//    وهكذا لا يوجد أي احتمال لحقن SQL
// ============================================================
// شروط الاستعلام
$conditions = [];
// قيم المعاملات
$params = [];

// ⭐ لا نعرض إلا الحسابات المفعلة والمتقدمة
$conditions[] = "u.users_is_active = 1";

// ⭐ فلتر القسم الرئيسي
if ($catId !== '' && (int) $catId > 0) {
    // ربط الخدمة بالقسم الرئيسي
    $conditions[] = "ps.ps_cat_id = :cat_id";
    // تخزين القيمة
    $params[':cat_id'] = (int) $catId;
}

// ⭐ فلتر القسم الفرعي
if ($subCatId !== '' && (int) $subCatId > 0) {
    // ربط الخدمة بالقسم الفرعي
    $conditions[] = "ps.ps_sub_cat_id = :sub_cat_id";
    // تخزين القيمة
    $params[':sub_cat_id'] = (int) $subCatId;
}

// ⭐ فلتر البحث بالاسم أو المنطقة
if ($search !== '') {
    // نبحث في اسم مقدم الخدمة أو عنوان إقامته
    $conditions[] = "(u.users_full_name LIKE :search
                     OR pp.providers_current_governorate LIKE :search
                     OR pp.providers_current_area LIKE :search)";
    // ⭐ نُحيط النص بعلامات % من الجهتين
    $params[':search'] = '%' . $search . '%';
}

// ⭐ فلتر المحافظة
if ($governorate !== '') {
    // مطابقة جزئية للمحافظة
    $conditions[] = "pp.providers_current_governorate LIKE :governorate";
    // تخزين القيمة
    $params[':governorate'] = '%' . $governorate . '%';
}

// ⭐ فلتر المنطقة
if ($area !== '') {
    // مطابقة جزئية للمنطقة
    $conditions[] = "pp.providers_current_area LIKE :area";
    // تخزين القيمة
    $params[':area'] = '%' . $area . '%';
}

// ⭐ فلتر النوع
if ($gender !== '') {
    // مطابقة النوع
    $conditions[] = "pp.providers_gender = :gender";
    // تخزين القيمة
    $params[':gender'] = $gender;
}

// تجميع الشروط في جملة واحدة
$whereSql = "WHERE " . implode(" AND ", $conditions);

// ============================================================
// الخطوة 5: حساب الإجمالي لبناء أرقام الصفحات
// ⭐ استعلام مستقل لأن LIMIT لا يسمح بمعرفة الإجمالي
// ============================================================
// استعلام العدّ الإجمالي
$countStmt = $con->prepare(
    "SELECT COUNT(DISTINCT ps.ps_id) AS total
     FROM provider_services ps
     INNER JOIN users u                 ON u.users_id = ps.ps_user_id
     INNER JOIN professional_profiles pp ON pp.providers_users_id = u.users_id
     $whereSql"
);
// تنفيذ الاستعلام بنفس المعاملات
$countStmt->execute($params);
// قراءة الإجمالي
$totalCount = (int) $countStmt->fetchColumn();

// ⭐ حساب عدد الصفحات مع التقريب للأعلى
$totalPages = $limit > 0 ? (int) ceil($totalCount / $limit) : 1;
// إذا لم توجد نتائج نُعيد صفحة واحدة منطقياً
if ($totalPages < 1)
    $totalPages = 1;

// ⭐ هل توجد صفحة تالية؟
$hasNextPage = $page < $totalPages;

// ============================================================
// الخطوة 6: جلب الصفحة الحالية
// ============================================================
// استعلام الصفحة الحالية بكل بيانات العرض
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
        -- ===== بيانات الحساب =====
        u.users_full_name,
        u.users_phone,
        -- ===== بيانات الملف المهني =====
        pp.providers_profile_image,
        pp.providers_rating,
        pp.providers_experience,
        pp.providers_age,
        pp.providers_gender,
        pp.providers_nationality,
        pp.providers_current_country,
        pp.providers_current_governorate,
        pp.providers_current_area,
        pp.providers_current_address,
        pp.providers_bio,
        pp.providers_is_verified,
        -- ===== أسماء القسم والفرع والتوقيت =====
        c.categories_name_ar,
        c.categories_name_en,
        sc.sub_name_ar,
        sc.sub_name_en,
        st.timing_name_ar,
        st.timing_name_en
     FROM provider_services ps
     INNER JOIN users u                 ON u.users_id = ps.ps_user_id
     INNER JOIN professional_profiles pp ON pp.providers_users_id = u.users_id
     LEFT  JOIN categories c            ON c.categories_id = ps.ps_cat_id
     LEFT  JOIN sub_categories sc       ON sc.sub_id = ps.ps_sub_cat_id
     LEFT  JOIN service_timing st       ON st.timing_id = ps.ps_timing_id
     $whereSql
     ORDER BY pp.providers_is_verified DESC, pp.providers_rating DESC, ps.ps_id DESC
     LIMIT $limit OFFSET $offset"
);
// ⚠️ LIMIT/OFFSET رقميان محسوبان في PHP فلا يحتاجان معاملات
$stmt->execute($params);
// جلب كل صفوف الصفحة
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// ⭐ الخطوة 7: بناء الرابط الأساسي لصور الملفات
// ============================================================
// استخراج مسار جذر المشروع بالرجوع مستويين
$scriptDir = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'])));

// بناء الرابط الكامل مع البروتوكول والمضيف
$baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . ($scriptDir === '/' || $scriptDir === '\\' ? '' : $scriptDir);

// إزالة أي شرطة مائلة زائدة من النهاية
$baseUrl = rtrim($baseUrl, '/');

// ============================================================
// ⭐ الخطوة 8: تجهيز كل بطاقة مع السعر والعمولة والإجمالي
// ============================================================
// مصفوفة النتائج
$providers = [];

// ============================================================
// ⭐ قراءة عمولة العميل (B) من إعدادات التطبيق مرة واحدة
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

// المرور على كل صف وبناء بطاقته
foreach ($rows as $row) {
    // السعر الأساسي
    $price = (float) $row['ps_price'];
    // نوع العمولة
    $commissionType = $row['ps_commission_type'] ?: 'percent';
    // قيمة العمولة
    $commissionValue = (float) $row['ps_commission_value'];

    // ⭐ مبلغ عمولة مقدم الخدمة (A) بالجنيه (نفس معادلة add_booking.php)
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
    $commissionAmount = round($commissionAmount, 2);

    // ⭐ الإجمالي للعميل = السعر + عمولة مقدم الخدمة (المخفية)
    $totalForClient = $price + $commissionAmount;

    // ⭐ تنقية اسم الصورة من أي أسطر مسرَّبة
    $imageName = trim(str_replace(
        ["\n", "\r"],
        '',
        (string) $row['providers_profile_image']
    ));

    // بناء رابط الصورة الكامل أو null إن لم توجد
    $profileImageUrl = $imageName !== ''
        ? $baseUrl . '/upload/provider_profiles/' . $imageName
        : null;

    // ⭐ حالة التوثيق: 1 = موثق، 0 = قيد المراجعة، 2 = مرفوض
    $isVerified = (int) $row['providers_is_verified'];

    // إضافة البطاقة للنتائج
    $providers[] = [
        // ===== معرّفات =====
        "ps_id" => (int) $row['ps_id'],
        "ps_user_id" => (int) $row['ps_user_id'],
        "ps_sub_cat_id" => (int) $row['ps_sub_cat_id'],
        // ===== بيانات الاسم والصورة =====
        "users_full_name" => $row['users_full_name'],
        "users_phone" => $row['users_phone'],
        "providers_profile_image" => $profileImageUrl,
        // ===== بيانات الملف المهني =====
        "providers_rating" => (float) $row['providers_rating'],
        "providers_experience" => $row['providers_experience'] !== null
            ? (int) $row['providers_experience']
            : null,
        "providers_age" => $row['providers_age'] !== null
            ? (int) $row['providers_age']
            : null,
        "providers_gender" => $row['providers_gender'],
        "providers_nationality" => $row['providers_nationality'],
        "providers_current_governorate" => $row['providers_current_governorate'],
        "providers_current_area" => $row['providers_current_area'],
        // ⭐ 0 أو 1 للمنطق في فلاتر
        "is_verified" => $isVerified === 1 ? 1 : 0,
        // ⭐ ترجمة حالة التوثيق جاهزة من PHP
        "verification_ar" => match ($isVerified) {
            1 => 'موثّق',
            2 => 'مرفوض',
            default => 'قيد المراجعة',
        },
        // ===== أسماء الأقسام =====
        "category" => [
            "name_ar" => $row['categories_name_ar'],
            "name_en" => $row['categories_name_en'],
        ],
        "sub_category" => [
            "name_ar" => $row['sub_name_ar'],
            "name_en" => $row['sub_name_en'],
        ],
        "timing" => [
            "name_ar" => $row['timing_name_ar'],
            "name_en" => $row['timing_name_en'],
        ],
        // ===== التسعير والعمولة =====
        "pricing" => [
            // السعر الأساسي
            "ps_price" => round($price, 2),
            // السعر التقديري الاختياري
            "ps_price_estimate" => $row['ps_price_estimate'] !== null
                ? round((float) $row['ps_price_estimate'], 2)
                : null,
            // نوع العمولة
            "commission_type" => $commissionType,
            // قيمة العمولة كما هي في القاعدة
            "commission_value" => round($commissionValue, 2),
            // ⭐ العمولة محسوبة بالجنيه
            "commission_amount" => round($commissionAmount, 2),
            // ⭐ الإجمالي للعميل (شامل عمولة مقدم الخدمة المخفية)
            "total_for_client" => round($totalForClient, 2),
            // ⭐ عمولة العميل (B) التي تظهر له في صفحة الحجز
            "client_commission_type" => $clientCommissionType,
            "client_commission_value" => round($clientCommissionValue, 2),
            // هل السعر قابل للتفاوض؟
            "negotiable" => ((int) $row['ps_negotiable']) === 1,
        ],
    ];
}

// ============================================================
// الخطوة 9: إرسال الرد النهائي مع بيانات الصفحات
// ============================================================
echo json_encode([
    // حالة العملية
    "status" => "success",
    // ⭐ رسالة عربية جاهزة للعرض
    "message" => $totalCount > 0
        ? "تم العثور على " . $totalCount . " مقدم خدمة"
        : "لا يوجد مقدمو خدمة مطابقون لهذه الفلاتر",
    // ===== مصفوفة النتائج =====
    "data" => $providers,
    // ===== بيانات الصفحات =====
    "pagination" => [
        // الصفحة الحالية
        "current_page" => $page,
        // عدد النتائج في الصفحة
        "per_page" => $limit,
        // الإجمالي
        "total_count" => $totalCount,
        // عدد الصفحات
        "total_pages" => $totalPages,
        // هل توجد صفحة تالية؟
        "has_next_page" => $hasNextPage,
        // هل توجد صفحة سابقة؟
        "has_prev_page" => $page > 1,
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
