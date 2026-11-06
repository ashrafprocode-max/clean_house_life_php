<?php
// ============================================================
// ملف: admin/providers/review_list.php
// الغرض: عرض قائمة طلبات مقدمي الخدمة لمراجعتها من خدمة العملاء
//        مع دعم الفلترة بالحالة + البحث بالاسم/الهاتف + Pagination
//
// ⭐ الحالات (providers_is_verified):
//   0 = pending  (في انتظار المراجعة)
//   1 = verified (تمت الموافقة)
//   2 = rejected (مرفوض ويحتاج تعديل)
//
// ⚠️ هذا الملف لا يُرجع صور البطاقة أو الفيش إطلاقاً
//    لأن القائمة للعرض السريع فقط، والصور في review_details.php
//
// أمثلة الاستخدام:
//   status=pending&page=1&limit=20     → الطلبات المعلّقة فقط
//   status=all&search=أشرف              → بحث في كل الحالات
//
// الطريقة: POST أو GET
// ============================================================

// ربط ملف الاتصال بقاعدة البيانات (مستويان للوراء لأن الملف داخل admin/providers)
include "../../connect.php";

// ⭐ حماية ملف الإدارة: يجب إرسال المفتاح السري في هيدر X-Admin-Key
// ⚠️ لو أردت تعطيل الحماية مؤقتاً أثناء التطوير ضع // قبل السطر التالي فقط
// checkAdminKey();
// ⭐ التحقق من توكن الإدارة وجلب بيانات الموظف الحالي
// أي موظف مسجّل دخوله (مدير أو خدمة عملاء) يستطيع القراءة
$currentAdmin = checkAdminToken();

// ============================================================
// الخطوة 1: استقبال معاملات الفلترة والبحث
// ============================================================
// حالة المراجعة المطلوبة: pending / verified / rejected / all
$status = filterRequest("status");
// نص البحث في اسم مقدم الخدمة أو رقم هاتفه
$search = trim(filterRequest("search"));

// ============================================================
// الخطوة 2: معاملات Pagination
// max() و min() لضمان قيم منطقية
// ============================================================
// رقم الصفحة الحالية - لا تقل عن 1
$page  = max(1, (int)(filterRequest("page") ?: 1));
// عدد النتائج - لا تقل عن 1 ولا تزيد عن 50
$limit = min(50, max(1, (int)(filterRequest("limit") ?: 20)));
// نقطة البداية في قاعدة البيانات
// مثال: page=2, limit=20 → offset=20 (ابدأ من السجل 21)
$offset = ($page - 1) * $limit;

// ============================================================
// الخطوة 3: بناء شروط WHERE المشتركة بين الاستعلامين
// ============================================================
// نبدأ بشرط ثابت: نعرض مقدمي الخدمة فقط (وليس العملاء)
$whereConditions = [
    "u.users_type = 'provider'",
];

// مصفوفتان منفصلتان للمعاملات: واحدة للعدد وأخرى للبيانات
$params      = [];
$countParams = [];

// ============================================================
// الخطوة 4: فلتر حالة المراجعة
// نحوّل النص القادم من الواجهة لرقم العمود في قاعدة البيانات
// ============================================================
// خريطة تحويل النص لرقم الحالة
$statusMap = [
    'pending'  => 0,
    'verified' => 1,
    'rejected' => 2,
];

// نُضيف الشرط فقط إذا كانت الحالة معروفة (all تعني بلا فلترة)
if ($status !== '' && isset($statusMap[$status])) {
    // إضافة شرط الحالة لجملة WHERE
    $whereConditions[] = "pp.providers_is_verified = ?";
    // تمرير الرقم للاستعلام الرئيسي
    $params[]      = $statusMap[$status];
    // وتمريره كذلك لاستعلام العدد الإجمالي
    $countParams[] = $statusMap[$status];
}

// ============================================================
// الخطوة 5: فلتر البحث بالاسم أو رقم الهاتف
// ============================================================
// نُضيف الشرط فقط إذا أرسلت الواجهة نصاً للبحث
if ($search !== '') {
    // نبحث في الاسم أو الهاتف معاً بـ OR داخل أقواس
    // الأقواس مهمة حتى لا يتعارض OR مع شروط AND الأخرى
    $whereConditions[] = "(u.users_full_name LIKE ? OR u.users_phone LIKE ?)";
    // تجهيز نص البحث مع % قبله وبعده للبحث الجزئي
    $searchTerm = "%{$search}%";
    // تمرير النص مرتين (مرة للاسم ومرة للهاتف)
    $params[]      = $searchTerm;
    $params[]      = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
}

// دمج كل الشروط في جملة WHERE واحدة مفصولة بـ AND
$whereSQL = "WHERE " . implode(" AND ", $whereConditions);

// ============================================================
// الخطوة 6: استعلام العدد الإجمالي
// مهم لحساب عدد الصفحات وعرض "صفحة 1 من 5" في الواجهة
// ============================================================
$countSQL = "
    SELECT COUNT(pp.providers_id) AS total
    FROM professional_profiles pp
    INNER JOIN users u
        ON u.users_id = pp.providers_users_id
    $whereSQL
";
// تجهيز الاستعلام
$countStmt = $con->prepare($countSQL);
// تنفيذه بمعاملات العدد
$countStmt->execute($countParams);
// قراءة الرقم الناتج
$totalCount = (int)$countStmt->fetchColumn();

// حساب إجمالي الصفحات (نرفع الكسر دائماً)
// مثال: 45 نتيجة ÷ 20 لكل صفحة = 3 صفحات (2.25 → 3)
$totalPages = (int)ceil($totalCount / $limit);

// ============================================================
// الخطوة 7: استعلام عدد الطلبات في كل حالة
// لعرض شارات العدد على أزرار الفلترة في لوحة التحكم
// (المعلّقة 12 | المقبولة 40 | المرفوضة 3)
// ============================================================
$statsSQL = "
    SELECT
        -- عدد الطلبات المعلّقة في انتظار المراجعة
        SUM(CASE WHEN pp.providers_is_verified = 0 THEN 1 ELSE 0 END) AS pending_count,
        -- عدد الطلبات التي تمت الموافقة عليها
        SUM(CASE WHEN pp.providers_is_verified = 1 THEN 1 ELSE 0 END) AS verified_count,
        -- عدد الطلبات المرفوضة
        SUM(CASE WHEN pp.providers_is_verified = 2 THEN 1 ELSE 0 END) AS rejected_count,
        -- الإجمالي الكلي لكل مقدمي الخدمة
        COUNT(pp.providers_id) AS all_count
    FROM professional_profiles pp
    INNER JOIN users u
        ON u.users_id = pp.providers_users_id
    WHERE u.users_type = 'provider'
";
// تنفيذ استعلام الإحصائيات بدون معاملات
$statsStmt = $con->prepare($statsSQL);
$statsStmt->execute();
// قراءة صف الإحصائيات
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 8: الاستعلام الرئيسي مع LIMIT و OFFSET
// ⚠️ لا نجلب أسماء ملفات البطاقة أو الفيش هنا
//    نجلب فقط هل هي موجودة أم لا (لعرض علامة ✓ في القائمة)
// ============================================================
$sql = "
    SELECT
        pp.providers_id,
        pp.providers_users_id,
        pp.providers_experience,
        pp.providers_age,
        pp.providers_gender,
        pp.providers_nationality,
        pp.providers_profile_image,
        pp.providers_current_country,
        pp.providers_current_governorate,
        pp.providers_current_area,
        pp.providers_rating,
        pp.providers_is_verified,
        pp.providers_review_note,
        pp.providers_reviewed_at,

        -- ⚠️ لا نُرجع أسماء ملفات المستندات في القائمة للأمان
        -- نُرجع فقط هل المستند مرفوع أم لا (1 أو 0)
        -- حتى تعرف خدمة العملاء أي طلب مكتمل المستندات
        CASE WHEN pp.providers_id_card_front IS NOT NULL
             AND pp.providers_id_card_front <> ''
             THEN 1 ELSE 0 END AS has_id_front,
        CASE WHEN pp.providers_id_card_back IS NOT NULL
             AND pp.providers_id_card_back <> ''
             THEN 1 ELSE 0 END AS has_id_back,
        CASE WHEN pp.providers_criminal_record_image IS NOT NULL
             AND pp.providers_criminal_record_image <> ''
             THEN 1 ELSE 0 END AS has_criminal_record,
        CASE WHEN pp.providers_utility_receipt_image IS NOT NULL
             AND pp.providers_utility_receipt_image <> ''
             THEN 1 ELSE 0 END AS has_utility_receipt,

        -- بيانات المستخدم من جدول users
        u.users_full_name,
        u.users_phone,
        u.users_approve,
        u.users_is_active,
        u.users_created_at,

        -- اسم الموظف الذي راجع الطلب (LEFT JOIN لأن المراجعة قد لم تحدث بعد)
        au.admin_full_name AS reviewed_by_name

    FROM professional_profiles pp
    INNER JOIN users u
        ON u.users_id = pp.providers_users_id
    LEFT JOIN admin_users au
        ON au.admin_id = pp.providers_reviewed_by
    $whereSQL
    ORDER BY
        -- المعلّقة أولاً لأنها الأهم لخدمة العملاء
        CASE WHEN pp.providers_is_verified = 0 THEN 0 ELSE 1 END ASC,
        -- ثم الأقدم تسجيلاً أولاً (الأطول انتظاراً)
        u.users_created_at ASC
    LIMIT $limit OFFSET $offset
";
// تجهيز الاستعلام الرئيسي
$stmt = $con->prepare($sql);
// تنفيذه بمعاملات البيانات
$stmt->execute($params);
// جلب كل الصفوف كمصفوفة
$providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 9: بناء رابط الصورة الشخصية
// نرجع مستويين للوراء للوصول لجذر المشروع
// حتى يصبح المسار: clean_house_life_php/upload/provider_profiles/
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
// الخطوة 10: تحويل كل سجل لشكل مناسب لـ Flutter
// ============================================================
$resultData = array_map(function ($p) use ($baseUrl) {
    // ترجمة رقم الحالة لنص واضح تفهمه الواجهة
    $verificationStatus = match((int)$p['providers_is_verified']) {
        0 => 'pending',   // في انتظار المراجعة
        1 => 'verified',  // تمت الموافقة
        2 => 'rejected',  // مرفوض
        default => 'pending',
    };

    // إرجاع مصفوفة السجل بالشكل الذي يقرأه موديل Flutter
    return [
        // معرّف سجل الملف المهني
        "providers_id"        => (int)$p['providers_id'],
        // معرّف المستخدم (نحتاجه لفتح صفحة التفاصيل)
        "providers_users_id"  => (int)$p['providers_users_id'],
        // اسم مقدم الخدمة الكامل
        "users_full_name"     => $p['users_full_name'],
        // ⚠️ رقم الهاتف يظهر للإدارة فقط (لا يظهر في تطبيق العميل)
        "users_phone"         => $p['users_phone'],
        // الصورة الشخصية برابط كامل جاهز للعرض
        "providers_profile_image" => $p['providers_profile_image']
            ? $baseUrl . '/upload/provider_profiles/' . $p['providers_profile_image']
            : null,
        // سنوات الخبرة
        "providers_experience" => $p['providers_experience'],
        // السن
        "providers_age"        => (int)$p['providers_age'],
        // النوع (ذكر / أنثى)
        "providers_gender"     => $p['providers_gender'],
        // الجنسية
        "providers_nationality"=> $p['providers_nationality'],
        // بيانات محل الإقامة الحالي
        "providers_current_country"      => $p['providers_current_country'],
        "providers_current_governorate"  => $p['providers_current_governorate'],
        "providers_current_area"         => $p['providers_current_area'],
        // التقييم
        "providers_rating"     => (float)$p['providers_rating'],

        // ===== حالة المراجعة =====
        // النص الواضح للحالة
        "verification_status"  => $verificationStatus,
        // الرقم الأصلي كما هو في قاعدة البيانات
        "providers_is_verified"=> (int)$p['providers_is_verified'],
        // سبب الرفض أو ملاحظة المراجع
        "providers_review_note"=> $p['providers_review_note'],
        // تاريخ ووقت المراجعة
        "providers_reviewed_at"=> $p['providers_reviewed_at'],
        // اسم الموظف الذي راجع الطلب
        "reviewed_by_name"     => $p['reviewed_by_name'],

        // ===== حالة الحساب في جدول users =====
        // هل وافقت الإدارة على الحساب؟
        "users_approve"        => (int)$p['users_approve'],
        // هل الحساب مفعّل؟
        "users_is_active"      => (int)$p['users_is_active'],
        // تاريخ التسجيل (لحساب مدة الانتظار)
        "users_created_at"     => $p['users_created_at'],

        // ===== اكتمال المستندات (بدون كشف أسماء الملفات) =====
        "documents" => [
            // هل رفع البطاقة الأمامية؟
            "has_id_front"        => (bool)$p['has_id_front'],
            // هل رفع البطاقة الخلفية؟
            "has_id_back"         => (bool)$p['has_id_back'],
            // هل رفع الصحيفة الجنائية؟ (اختيارية)
            "has_criminal_record" => (bool)$p['has_criminal_record'],
            // هل رفع إيصال المرافق؟ (اختياري)
            "has_utility_receipt" => (bool)$p['has_utility_receipt'],
            // هل المستندات الإجبارية مكتملة؟ (البطاقة بوجهيها)
            "is_complete"         => (bool)$p['has_id_front'] && (bool)$p['has_id_back'],
        ],
    ];
}, $providers);

// ============================================================
// الخطوة 11: إرسال الرد مع بيانات Pagination والإحصائيات
// ============================================================
echo json_encode([
    // حالة العملية
    "status"  => "success",
    // رسالة نجاح واضحة
    "message" => "Providers review list fetched successfully",
    // مصفوفة الطلبات
    "data"    => $resultData,

    // ===== إحصائيات لشارات أزرار الفلترة =====
    "stats" => [
        // عدد الطلبات المعلّقة
        "pending_count"  => (int)($stats['pending_count']  ?? 0),
        // عدد الطلبات المقبولة
        "verified_count" => (int)($stats['verified_count'] ?? 0),
        // عدد الطلبات المرفوضة
        "rejected_count" => (int)($stats['rejected_count'] ?? 0),
        // الإجمالي الكلي
        "all_count"      => (int)($stats['all_count']      ?? 0),
    ],

    // ===== بيانات التصفح =====
    "pagination" => [
        // الصفحة الحالية
        "current_page"  => $page,
        // عدد النتائج في الصفحة
        "per_page"      => $limit,
        // إجمالي النتائج المطابقة للفلترة
        "total_count"   => $totalCount,
        // إجمالي الصفحات
        "total_pages"   => $totalPages,
        // Flutter يتوقف عن التحميل عند false
        "has_next_page" => ($page < $totalPages),
        "has_prev_page" => ($page > 1),
    ],
]);
?>