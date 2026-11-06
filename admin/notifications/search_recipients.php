<?php
// ============================================================
// ملف: admin/notifications/search_recipients.php
// الغرض: بحث مستلمين للحملة الترويجية
// الفلاتر: all / client / provider / company + بحث اسم/هاتف
// الصلاحية: notifications.send
// ============================================================

// ربط الاتصال بقاعدة البيانات والدوال العامة
include "../../connect.php";

// التحقق من توكن الموظف وجلب بياناته
$currentAdmin = checkAdminToken();
// فرض صلاحية إرسال الحملات
requirePermission($currentAdmin, 'notifications.send');

// جمهور البحث: all / client / provider / company
$audience = trim((string) filterRequest("audience"));
// نص البحث في الاسم أو الهاتف
$search = trim((string) filterRequest("search"));
// رقم الصفحة
$page = (int) filterRequest("page");
// عدد الصفوف
$limit = (int) filterRequest("limit");

// الصفحة لا تقل عن 1
if ($page < 1) {
    // تصحيح رقم الصفحة
    $page = 1;
}
// الحد بين 1 و 50
if ($limit < 1 || $limit > 50) {
    // القيمة الافتراضية
    $limit = 20;
}
// حساب إزاحة SQL
$offset = ($page - 1) * $limit;

// قائمة الجماهير المسموحة
$allowed = ['all', 'client', 'provider', 'company'];
// إن كان الجمهور غير معروف نستخدم الكل
if (!in_array($audience, $allowed, true)) {
    // القيمة الافتراضية
    $audience = 'all';
}

// الشرط الأساسي: حسابات مفعّلة فقط
$where = ["u.users_is_active = 1"];
// معاملات الاستعلام
$params = [];

// فلترة العملاء بدون شركة
if ($audience === 'client') {
    // نوع العميل
    $where[] = "u.users_type = 'client'";
    // استبعاد من سجّل شركة
    $where[] = "NOT EXISTS (SELECT 1 FROM company_registering c WHERE c.company_user_id = u.users_id)";
} elseif ($audience === 'provider') {
    // مقدمو الخدمة
    $where[] = "u.users_type = 'provider'";
} elseif ($audience === 'company') {
    // من لديه صف في company_registering
    $where[] = "EXISTS (SELECT 1 FROM company_registering c WHERE c.company_user_id = u.users_id)";
}

// إضافة بحث الاسم أو الهاتف
if ($search !== '') {
    // شرط LIKE على الاسم والهاتف
    $where[] = "(u.users_full_name LIKE ? OR u.users_phone LIKE ?)";
    // نمط البحث
    $like = '%' . $search . '%';
    // إضافة المعاملين
    $params[] = $like;
    $params[] = $like;
}

// دمج الشروط
$whereSql = implode(' AND ', $where);

// عدّ النتائج
$countStmt = $con->prepare("SELECT COUNT(*) FROM users u WHERE $whereSql");
// تنفيذ العدّ
$countStmt->execute($params);
// قراءة العدد
$totalCount = (int) $countStmt->fetchColumn();

// جلب صفحة المستلمين مع اسم الشركة إن وُجد
$sql = "SELECT u.users_id, u.users_full_name, u.users_phone, u.users_type,
               (SELECT c.company_name FROM company_registering c
                 WHERE c.company_user_id = u.users_id LIMIT 1) AS company_name
        FROM users u
        WHERE $whereSql
        ORDER BY u.users_id DESC
        LIMIT $limit OFFSET $offset";
// تجهيز الاستعلام
$stmt = $con->prepare($sql);
// تنفيذ الاستعلام
$stmt->execute($params);
// جلب الصفوف
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// تحويل الصفوف لشكل العرض في لوحة الإرسال
$data = array_map(function ($row) {
    // هل لهذا المستخدم شركة؟
    $hasCompany = !empty($row['company_name']);
    // دور الجمهور للعرض
    $role = $row['users_type'] === 'provider'
        ? 'provider'
        : ($hasCompany ? 'company' : 'client');
    // صف النتيجة
    return [
        "users_id" => (int) $row['users_id'],
        "users_full_name" => $row['users_full_name'],
        "users_phone" => $row['users_phone'],
        "users_type" => $row['users_type'],
        "audience_role" => $role,
        "audience_role_ar" => $role === 'provider' ? 'مقدم خدمة' : ($role === 'company' ? 'صاحب شركة' : 'عميل'),
        "company_name" => $row['company_name'],
    ];
}, $rows);

// إرسال JSON للوحة التحكم
echo json_encode([
    "status" => "success",
    "message" => "نتائج البحث عن المستلمين",
    "data" => $data,
    "pagination" => [
        "current_page" => $page,
        "per_page" => $limit,
        "total_count" => $totalCount,
        "has_next_page" => ($page * $limit) < $totalCount,
    ],
]);
