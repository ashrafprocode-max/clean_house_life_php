<?php
// ============================================================
// ملف: admin/categories/list_category.php
// الغرض: عرض قائمة الأقسام الرئيسية للإدارة مع حالتها وترتيبها
//
// ⭐ الفرق عن categories/get_all.php:
//    get_all      → التطبيق: الأقسام المفعّلة فقط بلا توكن
//    هذا الملف    → اللوحة: كل الأقسام بما فيها المعطّلة + توكن
//
// ⭐ الفلاتر:
//   is_active → 1 مفعّل / 0 معطّل / all الكل (افتراضي)
//   search    → بحث في الاسم العربي أو الإنجليزي
//
// الطريقة: POST أو GET
// الصلاحية المطلوبة: catalog.view
// ============================================================

// ربط ملف الاتصال بقاعدة البيانات (مستويان للوراء لأن الملف داخل admin/categories)
include "../../connect.php";

// ============================================================
// الخطوة 1: التحقق من التوكن والصلاحية
//
// ⭐ catalog.view يملكها المدير وخدمة العملاء
//    لأن القائمة للعرض فقط ولا تغيّر شيئاً
// ============================================================
// التحقق من التوكن وجلب بيانات الموظف مع صلاحياته
$currentAdmin = checkAdminToken();

// ⭐ فرض صلاحية عرض الكتالوج
requirePermission($currentAdmin, 'catalog.view');

// ============================================================
// الخطوة 2: استقبال معاملات الفلترة والبحث
// ============================================================
// فلتر حالة التفعيل: 1 / 0 / all
$isActive = filterRequest("is_active");
// نص البحث في اسم القسم عربي أو إنجليزي
$search   = trim(filterRequest("search"));

// ============================================================
// الخطوة 3: بناء شروط WHERE المشتركة بين الاستعلامين
// ============================================================
// نبدأ بشرط ثابت دائماً صحيح لتبسيط دمج الشروط بعده
$whereConditions = ["1 = 1"];
// مصفوفة معاملات الاستعلام الرئيسي
$params      = [];
// مصفوفة معاملات استعلام الإحصائيات (نفس الفلاتر ما عدا is_active)
$countParams = [];

// ============================================================
// الخطوة 4: فلتر حالة التفعيل
// ============================================================
// نُضيف الشرط فقط إذا طُلب مفعّل أو معطّل صراحةً
if ($isActive === '1' || $isActive === '0') {
    // إضافة شرط التفعيل
    $whereConditions[] = "c.categories_is_active = ?";
    // تمرير القيمة للاستعلام
    $params[] = (int) $isActive;
}

// ============================================================
// الخطوة 5: البحث في الاسم العربي والإنجليزي
//
// ⚠️ الأقواس حول شروط OR إجبارية
//    وإلا تعارض OR مع شروط AND الأخرى وأعطى نتائج خاطئة
// ============================================================
// نُضيف الشرط فقط إذا أرسلت الواجهة نصاً للبحث
if ($search !== '') {
    // بناء شرط البحث في الاسمين بـ OR
    $whereConditions[] = "("
        . " c.categories_name_ar LIKE ?"
        . " OR c.categories_name_en LIKE ?"
        . ")";
    // تجهيز نص البحث مع % قبله وبعده للبحث الجزئي
    $searchTerm = "%{$search}%";
    // تمرير النص مرتين (مرة لكل حقل)
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

// تجميع الشروط في جملة WHERE واحدة
$whereSQL = implode(" AND ", $whereConditions);

// ============================================================
// الخطوة 6: جلب الأقسام مع عدد التخصصات الفرعية لكل قسم
//
// ⭐ الترتيب: الأصغر في categories_sort أولاً ثم الأقدم إنشاءً
//    حتى تتحكم الإدارة في ترتيب ظهورها بلا تعديل كود
// ============================================================
// تجهيز استعلام القائمة
$stmt = $con->prepare(
    "SELECT
        c.categories_id,
        c.categories_name_ar,
        c.categories_name_en,
        c.categories_image,
        c.categories_is_active,
        c.categories_sort,
        c.categories_created,
        -- عدد التخصصات الدقيقة التابعة لهذا القسم
        (SELECT COUNT(sc.sub_id)
         FROM sub_categories sc
         WHERE sc.sub_category_id = c.categories_id) AS sub_count
     FROM categories c
     WHERE $whereSQL
     ORDER BY
        c.categories_sort ASC,
        c.categories_id ASC"
);
// تنفيذ الاستعلام بمعاملات الفلترة
$stmt->execute($params);
// جلب كل الصفوف كمصفوفة
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 7: بناء رابط الأيقونات بنفس أسلوب categories/get_all.php
// حتى لا يتكرر مسار categories/categories في الرابط
// ============================================================
// الرجوع من admin/categories إلى جذر المشروع
$scriptDir = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'])));
// بناء عنوان الأساس مع دعم https
$baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . ($scriptDir === '/' || $scriptDir === '\\' ? '' : $scriptDir);
// إزالة الشرطة المائلة الزائدة من نهاية الرابط
$baseUrl = rtrim($baseUrl, '/');

// ============================================================
// الخطوة 8: تحويل كل سجل لشكل مناسب لـ Flutter
// ============================================================
$resultData = array_map(function ($cat) use ($baseUrl) {
    // إرجاع مصفوفة القسم بالشكل الذي يقرأه موديل Flutter
    return [
        // معرّف القسم
        "categories_id"        => (int) $cat['categories_id'],
        // الاسم العربي
        "categories_name_ar"   => $cat['categories_name_ar'],
        // الاسم الإنجليزي
        "categories_name_en"   => $cat['categories_name_en'],
        // رابط الأيقونة الكامل أو null
        "categories_image"     => $cat['categories_image']
            ? $baseUrl . '/upload/categories/' . $cat['categories_image']
            : null,
        // حالة التفعيل: 1 ظاهر · 0 معطّل
        "categories_is_active" => (int) $cat['categories_is_active'],
        // ترتيب العرض
        "categories_sort"      => (int) $cat['categories_sort'],
        // تاريخ الإنشاء
        "categories_created"   => $cat['categories_created'],
        // عدد التخصصات الفرعية التابعة له
        "sub_count"            => (int) $cat['sub_count'],
    ];
}, $categories);

// ============================================================
// الخطوة 9: حساب إحصائيات سريعة لأعلى الصفحة
// ⚠️ الإحصائيات على الجدول كله لا على نتيجة الفلتر
//    حتى يبقى عدّاد المفعّل/المعطّل ثابتاً أثناء البحث
// ============================================================
$statsSQL = "
    SELECT
        -- عدد الأقسام المفعّلة
        SUM(CASE WHEN categories_is_active = 1 THEN 1 ELSE 0 END) AS active_count,
        -- عدد الأقسام المعطّلة
        SUM(CASE WHEN categories_is_active = 0 THEN 1 ELSE 0 END) AS inactive_count,
        -- العدد الكلي
        COUNT(categories_id) AS all_count
    FROM categories
";
// تنفيذ استعلام الإحصائيات
$statsStmt = $con->prepare($statsSQL);
$statsStmt->execute();
// قراءة صف الإحصائيات
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 10: إرسال الرد
// ============================================================
echo json_encode([
    // حالة العملية
    "status"  => "success",
    // رسالة نجاح
    "message" => "تم جلب قائمة الأقسام بنجاح",
    // عدد النتائج في هذه الصفحة
    "count"   => count($resultData),
    // مصفوفة الأقسام
    "data"    => $resultData,
    // إحصائيات للعرض في أعلى الصفحة
    "stats"   => [
        // عدد الأقسام المفعّلة
        "active_count"   => (int) ($stats['active_count']   ?? 0),
        // عدد الأقسام المعطّلة
        "inactive_count" => (int) ($stats['inactive_count'] ?? 0),
        // العدد الكلي
        "all_count"      => (int) ($stats['all_count']      ?? 0),
    ],
]);
?>
