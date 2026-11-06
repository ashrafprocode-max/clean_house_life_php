<?php
// ============================================================
// ملف: provider_services_details/list_view_provider_details.php
// الغرض: عرض قائمة مقدمي الخدمة مع السعر والعمولة
//        مع دعم الفلترة بالقسم والتوقيت والقسم الفرعي
//
// مثال الاستخدام:
//   العميل يبحث عن: "شغالة" (cat=2) + "تنظيف وطبخ" (sub=8) + "إقامة كاملة" (timing=1)
//   الطلب: GET view_provider_details.php?cat_id=2&sub_cat_id=8&timing_id=1
//
// مثال النتيجة:
//   [{ اسم: أحمد, السعر: 3000, العمولة: 3000, الإجمالي: 6000, قابل للتفاوض: نعم }]
// ============================================================

// ============================================================
// ملف: provider_services_details/view_provider_details.php
//
// ⭐ شرح Pagination كاملاً:
//   المشكلة: لو عندنا 500 مقدم خدمة وجلبناهم دفعة واحدة:
//     - الـ API يكون بطيء جداً (ممكن 10 ثواني)
//     - Flutter يستهلك ذاكرة كبيرة
//     - المستخدم لا يرى الفرق (لا يرى 500 بطاقة مرة واحدة)
//
//   الحل - Pagination (التصفح بالصفحات):
//     نجلب 10 نتائج فقط في كل مرة
//     Flutter يطلب page=1 أول مرة
//     عند scroll للأسفل يطلب page=2 وهكذا
//
//   معاملات API:
//     page  = رقم الصفحة (يبدأ من 1)
//     limit = عدد النتائج في الصفحة (افتراضي 10)
//
//   مثال:
//     ?cat_id=2&page=1&limit=10  → أول 10 نتائج
//     ?cat_id=2&page=2&limit=10  → النتائج 11-20
//
//   SQL المستخدم:
//     LIMIT 10 OFFSET 0  → الصفحة الأولى
//     LIMIT 10 OFFSET 10 → الصفحة الثانية
//     الـ offset = (page - 1) * limit
//          offset = (1 - 1) * 10 = 0  (ابدأ من السجل 1)
//          offset = (2 - 1) * 10 = 10 (ابدأ من السجل11)
// ============================================================

include "../connect.php";

// ============================================================
// الخطوة 1: معاملات الفلترة (كلها اختيارية)
// ============================================================
$cat_id     = filterRequest("cat_id");      // القسم الرئيسي
$sub_cat_id = filterRequest("sub_cat_id");  // القسم الفرعي
$timing_id  = filterRequest("timing_id");   // التوقيت
$user_id    = filterRequest("user_id");     // لعرض خدمات مقدم محدد
// قراءة فلاتر البحث المتقدم القادمة من شاشة Search في Flutter
// كل منها اختياري: إذا أُرسل يُضاف لشرط WHERE، وإذا لم يُرسل يُتجاهل
$gender      = filterRequest("gender");      // النوع: ذكر / أنثى (اسم عربي)
$country     = filterRequest("country");     // الدولة: اسم عربي (مثل: مصر)
$governorate = filterRequest("governorate"); // المحافظة: اسم عربي (مثل: القاهرة)
$area        = filterRequest("area");        // المنطقة: اسم عربي (مثل: مدينة نصر)

// ============================================================
// الخطوة 2: معاملات Pagination
// max() و min() لضمان قيم منطقية
// ============================================================
// رقم الصفحة الحالية - لا تقل عن 1
$page  = max(1, (int)(filterRequest("page")  ?: 1));
// عدد النتائج - لا تقل عن 1 ولا تزيد عن 50
$limit = min(50, max(1, (int)(filterRequest("limit") ?: 10)));
// نقطة البداية في قاعدة البيانات
// مثال: page=3, limit=10 → offset=20 (ابدأ من السجل 21)
// $offset = (3-1) * 10 = 20 (ابدأ من السجل 21) 
//  $offset = (1 - 1) * 10 = 0 (ابدأ من السجل 1);
$offset = ($page - 1) * $limit;


// ============================================================
// الخطوة 3: بناء شروط WHERE المشتركة بين الاستعلامين
// ============================================================
$whereConditions = [
    "u.users_approve = 1",    // حسابات موافق عليها فقط
    "u.users_is_active = 1",  // حسابات مفعلة فقط
];

$params      = [];
$countParams = [];

if (!empty($cat_id)) {
    $whereConditions[] = "ps.ps_cat_id = ?";
    $params[]      = $cat_id;
    $countParams[] = $cat_id;
}
if (!empty($sub_cat_id)) {
    $whereConditions[] = "ps.ps_sub_cat_id = ?";
    $params[]      = $sub_cat_id;
    $countParams[] = $sub_cat_id;
}
if (!empty($timing_id)) {
    $whereConditions[] = "ps.ps_timing_id = ?";
    $params[]      = $timing_id;
    $countParams[] = $timing_id;
}
if (!empty($user_id)) {
    $whereConditions[] = "ps.ps_user_id = ?";
    $params[]      = $user_id;
    $countParams[] = $user_id;
}
// فلتر النوع: إذا أرسل Flutter "ذكر" أو "أنثى" نُضيف شرط على providers_gender
if (!empty($gender)) {
    $whereConditions[] = "pp.providers_gender = ?";
    $params[]          = $gender;
    $countParams[]     = $gender;
}
// فلتر الدولة: إذا أرسل Flutter اسم الدولة نُضيف شرط على providers_current_country
if (!empty($country)) {
    $whereConditions[] = "pp.providers_current_country = ?";
    $params[]          = $country;
    $countParams[]     = $country;
}
// فلتر المحافظة: نُضيف شرط على providers_current_governorate
if (!empty($governorate)) {
    $whereConditions[] = "pp.providers_current_governorate = ?";
    $params[]          = $governorate;
    $countParams[]     = $governorate;
}
// فلتر المنطقة: نُضيف شرط على providers_current_area
if (!empty($area)) {
    $whereConditions[] = "pp.providers_current_area = ?";
    $params[]          = $area;
    $countParams[]     = $area;
}
// دمج الشروط في جملة WHERE واحدة   
// الكود يبدأ بـ "مصفوفة شروط مسبقة"،
// وهي ألا يظهر في التطبيق إلا مقدم الخدمة المفعل والنشط إدارياً
// (بناءً على الشروط التي عالجناها سابقاً).
// بعد ذلك، يمر الكود على الفلاتر:
// إذا وجد أن العميل أرسل قسماً أو توقيتاً، يقوم بعمل push (إضافة)
//  للشرط داخل المصفوفة ويضع علامة الاستفهام ?
//  لحماية السيرفر من ثغرات الـ SQL Injection،
//  ثم يدمجهم بـ AND باستخدام دالة implode.
$whereSQL = "WHERE " . implode(" AND ", $whereConditions);

// ============================================================
// الخطوة 4: استعلام العدد الإجمالي
// مهم جداً لـ Flutter لحساب:
//   - إجمالي الصفحات = ceil(total / limit)
//   - هل توجد صفحة تالية؟ = page < totalPages
// ============================================================
$countSQL = "
    SELECT COUNT(DISTINCT ps.ps_id) AS total
    FROM provider_services ps
    INNER JOIN users u
        ON u.users_id = ps.ps_user_id
    INNER JOIN professional_profiles pp
        ON pp.providers_users_id = ps.ps_user_id
    $whereSQL
";
$countStmt = $con->prepare($countSQL);
$countStmt->execute($countParams);
$totalCount = (int)$countStmt->fetchColumn();

// حساب إجمالي الصفحات (نرفع الكسر دائماً)
// مثال: 25 نتيجة / 10 لكل صفحة = 3 صفحات (2.5 → 3)
$totalPages = (int)ceil($totalCount / $limit);

// ============================================================
// الخطوة 5: الاستعلام الرئيسي مع LIMIT و OFFSET
// ============================================================
$sql = "
    SELECT
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

        -- حساب قيمة العمولة الفعلية في SQL مباشرة
        CASE
            WHEN ps.ps_commission_type = 'percent'
            THEN ROUND((ps.ps_price * ps.ps_commission_value / 100), 2)
            ELSE ps.ps_commission_value
        END AS commission_amount,

        -- السعر الإجمالي للعميل = السعر + العمولة
        CASE
            WHEN ps.ps_commission_type = 'percent'
            THEN ROUND(ps.ps_price + (ps.ps_price * ps.ps_commission_value / 100), 2)
            ELSE ROUND(ps.ps_price + ps.ps_commission_value, 2)
        END AS total_price_for_client,
        
        -- بيانات المستخدم
        u.users_full_name,
        -- u.users_phone,
        
        -- بيانات الملف الشخصي (الظاهرة للعميل فقط)
        pp.providers_profile_image,
        pp.providers_rating,
        pp.providers_experience,
        pp.providers_nationality,
        pp.providers_is_verified,
        -- جلب نوع مقدم الخدمة (ذكر/أنثى) لعرضه في البطاقة
        pp.providers_gender,
        -- جلب المحافظة الحالية لعرضها بجانب المنطقة في البطاقة
        pp.providers_current_governorate,
        -- جلب المنطقة الحالية لعرضها بجانب المحافظة في البطاقة
        pp.providers_current_area,
        
        -- أسماء القسم والتوقيت
        c.categories_name_ar,
        c.categories_name_en,
        sc.sub_name_ar,
        sc.sub_name_en,
        st.timing_name_ar,
        st.timing_name_en

    FROM provider_services ps
    INNER JOIN users u
        ON u.users_id = ps.ps_user_id
    INNER JOIN professional_profiles pp
        ON pp.providers_users_id = ps.ps_user_id
    INNER JOIN categories c
        ON c.categories_id = ps.ps_cat_id
    INNER JOIN sub_categories sc
        ON sc.sub_id = ps.ps_sub_cat_id
    INNER JOIN service_timing st
        ON st.timing_id = ps.ps_timing_id
    $whereSQL
    ORDER BY
        pp.providers_is_verified DESC, -- الموثقون أولاً
        pp.providers_rating DESC        -- الأعلى تقييماً ثانياً
    LIMIT $limit OFFSET $offset
";

$stmt = $con->prepare($sql);
$stmt->execute($params);
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 6: بناء رابط الصور
// ============================================================
// $baseUrl = rtrim(
//     (isset($_SERVER['HTTPS']) ? 'https' : 'http') .
//     '://' . $_SERVER['HTTP_HOST'] .
//     str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME']),
//     '/'
// );

// الرجوع لمجلد المشروع الرئيسي (مجلدين للوراء) لتجنب تكرار اسم المجلد الحالي
// حتى يصبح المسار: clean_house_life_php/upload/provider_profiles/ وليس
// clean_house_life_php/provider_services_details/upload/provider_profiles/
$scriptDir = dirname(dirname($_SERVER['SCRIPT_NAME']));
$baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . ($scriptDir === '/' || $scriptDir === '\\' ? '' : $scriptDir);
$baseUrl = rtrim($baseUrl, '/');

// تحويل كل سجل لشكل مناسب لـ Flutter
$resultData = array_map(function ($s) use ($baseUrl) {
    return [
        "ps_id"              => (int)$s['ps_id'],
        "ps_user_id"         => (int)$s['ps_user_id'],
        "users_full_name"    => $s['users_full_name'],
        // ⚠️ لا نُرجع رقم الهاتف في القائمة للحفاظ على الخصوصية
        // يظهر فقط بعد الحجز المؤكد
        // "profile_image_url"  => $s['providers_profile_image']
        //     ? $baseUrl . '/upload/profiles/' . $s['providers_profile_image']
        //     : null,
        // ⚠️ اسم المفتاح يجب أن يطابق ما يقرأه موديل Flutter (providers_profile_image)
        // والمسار الصحيح للصور هو upload/provider_profiles وليس upload/profiles
        // ⚠️ اسم المفتاح يجب أن يطابق ما يقرأه موديل Flutter (providers_profile_image)
        // نُضيف baseUrl الكامل حتى يتمكن Flutter من عرض الصورة مباشرة
        "providers_profile_image" => $s['providers_profile_image']
            ? $baseUrl . '/upload/provider_profiles/' . $s['providers_profile_image']
            : null,
        "providers_rating"    => (float)$s['providers_rating'],
        "providers_experience"=> $s['providers_experience'],
        "providers_nationality"=> $s['providers_nationality'],
        // إرسال نوع مقدم الخدمة (ذكر/أنثى) ليُعرض في شارة مخصصة داخل البطاقة
        "providers_gender"    => $s['providers_gender'],
        // إرسال المحافظة الحالية لعرضها بجانب المنطقة (مثل: القاهرة)
        "providers_current_governorate" => $s['providers_current_governorate'],
        // إرسال المنطقة الحالية لعرضها بجانب المحافظة (مثل: مدينة نصر)
        "providers_current_area"        => $s['providers_current_area'],
        // هل مقدم الخدمة موثق؟ يعرض Flutter علامة ✓
        "is_verified"         => (bool)$s['providers_is_verified'],
        "category" => [
            "id"      => (int)$s['ps_cat_id'],
            "name_ar" => $s['categories_name_ar'],
            "name_en" => $s['categories_name_en'],
        ],
        "sub_category" => [
            "id"      => (int)$s['ps_sub_cat_id'],
            "name_ar" => $s['sub_name_ar'],
            "name_en" => $s['sub_name_en'],
        ],
        "timing" => [
            "id"      => (int)$s['ps_timing_id'],
            "name_ar" => $s['timing_name_ar'],
            "name_en" => $s['timing_name_en'],
        ],
        "pricing" => [
            "ps_price"          => (float)$s['ps_price'],
            "ps_price_estimate" => $s['ps_price_estimate']
                ? (float)$s['ps_price_estimate'] : null,
            // العمولة المحسوبة
            "commission_amount" => (float)$s['commission_amount'],
            // هذا ما يدفعه العميل فعلاً
            "total_for_client"  => (float)$s['total_price_for_client'],
            // قابل للتفاوض؟ Flutter تعرض label "قابل للتفاوض"
            "negotiable"        => (bool)$s['ps_negotiable'],
            "commission_type"   => $s['ps_commission_type'],
            "commission_value"  => (float)$s['ps_commission_value'],
        ],
    ];
}, $services);

// ============================================================
// الخطوة 7: الرد مع بيانات Pagination
//
// Flutter يستخدم هذه البيانات لـ:
//   has_next_page → هل يُحمّل المزيد عند الـ scroll؟
//   total_pages   → عرض "صفحة 1 من 5"
// ============================================================
echo json_encode([
    "status"  => "success",
    "message" => "Providers fetched successfully",
    "data"    => $resultData,
    "pagination" => [
        "current_page"  => $page,
        "per_page"      => $limit,
        "total_count"   => $totalCount,
        "total_pages"   => $totalPages,
        // Flutter يتوقف عن التحميل عند false
        "has_next_page" => ($page < $totalPages),
        "has_prev_page" => ($page > 1),
    ],
]);
?>