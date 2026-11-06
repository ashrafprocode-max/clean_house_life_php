<?php
// ============================================================
// ملف: admin/hr/list_employees.php
// الغرض: قائمة موظفي الموارد البشرية
//        الاسم + كود الوظيفة + المسمى + واتساب
//
// الصلاحية: employees.view
// الأدوار المتوقعة: owner / manager / hr / accountant
// الطريقة: POST أو GET
// ============================================================

// ربط ملف الاتصال بقاعدة البيانات
include "../../connect.php";

// التحقق من التوكن وجلب بيانات الموظف مع صلاحياته
$currentAdmin = checkAdminToken();

// فرض صلاحية عرض ملفات الموظفين
requirePermission($currentAdmin, 'employees.view');

// هل يملك إضافة أو تعديل؟ (لأزرار الواجهة)
$canCreate = adminCan($currentAdmin, 'employees.create');
// صلاحية التعديل لعرض زر البطاقة
$canEdit   = adminCan($currentAdmin, 'employees.edit');

// نص البحث في الاسم أو الكود أو المسمى أو الهاتف
$search = trim(filterRequest("search"));
// فلتر الحالة: all / active / inactive
$status = trim(filterRequest("status"));
// رقم الصفحة الحالية
$page  = max(1, (int)(filterRequest("page") ?: 1));
// عدد النتائج في الصفحة
$limit = min(50, max(1, (int)(filterRequest("limit") ?: 20)));
// إزاحة SQL
$offset = ($page - 1) * $limit;

// شرط ثابت دائماً صحيح لتبسيط دمج الشروط
$whereConditions = ["1 = 1"];
// معاملات الاستعلام
$params = [];

// فلتر الحالة إن طُلب
if ($status === 'active') {
    // الموظفون المفعّلون فقط
    $whereConditions[] = "au.admin_is_active = 1";
} elseif ($status === 'inactive') {
    // الموظفون المعطّلون فقط
    $whereConditions[] = "au.admin_is_active = 0";
}

// فلتر البحث إن وُجد نص
if ($search !== '') {
    // البحث في الاسم والكود والمسمى والهاتف والواتساب
    $whereConditions[] = "(au.admin_full_name LIKE ?
        OR au.admin_job_code LIKE ?
        OR au.admin_job_title LIKE ?
        OR au.admin_phone LIKE ?
        OR au.admin_whatsapp LIKE ?)";
    // نمط LIKE
    $like = '%' . $search . '%';
    // إضافة النمط خمس مرات
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

// دمج الشروط في جملة WHERE واحدة
$whereSql = implode(' AND ', $whereConditions);

// ============================================================
// إحصائيات الشارات
// ============================================================
$statsSQL = "
    SELECT
        COUNT(admin_id) AS all_count,
        SUM(CASE WHEN admin_is_active = 1 THEN 1 ELSE 0 END) AS active_count,
        SUM(CASE WHEN admin_is_active = 0 THEN 1 ELSE 0 END) AS inactive_count
    FROM admin_users
";
// تنفيذ الإحصائيات
$statsStmt = $con->prepare($statsSQL);
// بدون معاملات
$statsStmt->execute();
// قراءة صف الإحصائيات
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// ============================================================
// عدد النتائج بعد الفلترة
// ============================================================
$countSQL = "SELECT COUNT(au.admin_id) FROM admin_users au WHERE $whereSql";
// تجهيز العدّ
$countStmt = $con->prepare($countSQL);
// تنفيذ العدّ
$countStmt->execute($params);
// إجمالي الصفوف
$totalCount = (int)$countStmt->fetchColumn();
// عدد الصفحات
$totalPages = $totalCount > 0 ? (int)ceil($totalCount / $limit) : 1;

// ============================================================
// جلب القائمة
// ============================================================
$sql = "
    SELECT
        au.admin_id,
        au.admin_full_name,
        au.admin_job_code,
        au.admin_job_title,
        au.admin_phone,
        au.admin_whatsapp,
        au.admin_role,
        au.admin_is_active,
        au.admin_base_salary,
        au.admin_hire_date,
        au.admin_employment_type,
        au.admin_created_at
    FROM admin_users au
    WHERE $whereSql
    ORDER BY au.admin_is_active DESC, au.admin_full_name ASC
    LIMIT $limit OFFSET $offset
";
// تجهيز الاستعلام
$stmt = $con->prepare($sql);
// تنفيذه بمعاملات الفلترة
$stmt->execute($params);
// جلب الصفوف
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// دالة بناء رابط واتساب من رقم الهاتف
$whatsappUrl = function (?string $phone): ?string {
    // إذا لم يوجد رقم نُرجع null
    if ($phone === null || $phone === '') {
        return null;
    }
    // الإبقاء على الأرقام فقط
    $digits = preg_replace('/[^0-9]/', '', $phone);
    // تحويل 010xxxxxxxx إلى 2010xxxxxxxx
    if (strlen($digits) === 11 && strpos($digits, '0') === 0) {
        $digits = '20' . substr($digits, 1);
    } elseif (strlen($digits) === 10) {
        $digits = '20' . $digits;
    }
    // إذا لم يتبقَّ أرقام نُرجع null
    if ($digits === '') {
        return null;
    }
    // رابط واتساب جاهز للفتح من اللوحة
    return 'https://wa.me/' . $digits;
};

// ترجمة الدور للعربية
$roleAr = function (?string $role): string {
    // خريطة الأدوار المعروفة
    $map = [
        'owner'      => 'المالك',
        'manager'    => 'مدير',
        'accountant' => 'محاسب',
        'hr'         => 'موارد بشرية',
        'support'    => 'خدمة عملاء',
        'seller'     => 'مندوب مبيعات',
    ];
    // إرجاع الترجمة أو المفتاح كما هو
    return $map[$role] ?? (string)$role;
};

// تحويل كل سجل لشكل Flutter
$resultData = array_map(function ($row) use ($whatsappUrl, $roleAr, $canEdit) {
    // رقم الواتساب المعروض (الواتساب إن وُجد وإلا الهاتف)
    $waPhone = $row['admin_whatsapp'] !== null && $row['admin_whatsapp'] !== ''
        ? $row['admin_whatsapp']
        : $row['admin_phone'];
    // إرجاع صف القائمة
    return [
        // معرّف الموظف
        "admin_id"              => (int)$row['admin_id'],
        // الاسم الكامل
        "admin_full_name"       => $row['admin_full_name'],
        // كود الوظيفة
        "admin_job_code"        => $row['admin_job_code'],
        // المسمى الوظيفي
        "admin_job_title"       => $row['admin_job_title'],
        // رقم الهاتف
        "admin_phone"           => $row['admin_phone'],
        // رقم الواتساب
        "admin_whatsapp"        => $waPhone,
        // رابط واتساب جاهز
        "whatsapp_url"          => $whatsappUrl($waPhone),
        // الدور النصي
        "admin_role"            => $row['admin_role'],
        // الدور بالعربية
        "admin_role_ar"         => $roleAr($row['admin_role']),
        // حالة التفعيل
        "admin_is_active"       => (int)$row['admin_is_active'],
        // الراتب الأساسي
        "admin_base_salary"     => (float)$row['admin_base_salary'],
        // تاريخ التعيين
        "admin_hire_date"       => $row['admin_hire_date'],
        // نوع التعاقد
        "admin_employment_type" => $row['admin_employment_type'],
        // تاريخ الإنشاء
        "admin_created_at"      => $row['admin_created_at'],
        // هل يمكن فتح التعديل؟
        "can_edit"              => $canEdit,
    ];
}, $rows);

// إرسال الرد
echo json_encode([
    // حالة العملية
    "status"  => "success",
    // رسالة نجاح
    "message" => "Employees list fetched successfully",
    // قائمة الموظفين
    "data"    => $resultData,
    // أزرار الواجهة
    "can_create" => $canCreate,
    "can_edit"   => $canEdit,
    // إحصائيات الشارات
    "stats" => [
        "all_count"      => (int)($stats['all_count'] ?? 0),
        "active_count"   => (int)($stats['active_count'] ?? 0),
        "inactive_count" => (int)($stats['inactive_count'] ?? 0),
    ],
    // بيانات التصفح
    "pagination" => [
        "current_page"  => $page,
        "per_page"      => $limit,
        "total_count"   => $totalCount,
        "total_pages"   => $totalPages,
        "has_next_page" => ($page < $totalPages),
        "has_prev_page" => ($page > 1),
    ],
], JSON_UNESCAPED_UNICODE);
