<?php
// ============================================================
// ملف: admin/notifications/list_inbox.php
// الغرض: التاب الثاني — قائمة إشعارات الإدارة مع الفلترة
// الفلاتر: all / clients / providers / companies
// الصلاحية: notifications.view
// ============================================================

// ربط الاتصال بقاعدة البيانات والدوال العامة
include "../../connect.php";
// ربط مساعد الإشعارات (mapNotificationRow)
include "../../includes/notification_helper.php";

// التحقق من توكن الموظف وجلب بياناته
$currentAdmin = checkAdminToken();
// فرض صلاحية عرض الإشعارات
requirePermission($currentAdmin, 'notifications.view');

// معرّف الموظف الحالي من التوكن
$adminId = (int) $currentAdmin['admin_id'];
// فلتر الدور: all / clients / providers / companies
$filter = trim((string) filterRequest("filter"));
// نص البحث في العنوان أو الجسم أو اسم الفاعل
$search = trim((string) filterRequest("search"));
// رقم الصفحة المطلوبة
$page = (int) filterRequest("page");
// عدد الصفوف في الصفحة
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
// حساب إزاحة SQL من رقم الصفحة
$offset = ($page - 1) * $limit;

// قائمة الفلاتر المسموحة
$allowed = ['all', 'clients', 'providers', 'companies'];
// إن كان الفلتر غير معروف نستخدم الكل
if (!in_array($filter, $allowed, true)) {
    // القيمة الافتراضية
    $filter = 'all';
}

// شروط الصندوق: إشعارات هذا الموظف فقط
$where = [
    "i.inbox_recipient_kind = 'admin'",
    "i.inbox_admin_id = ?",
];
// معاملات الاستعلام تبدأ بمعرّف الموظف
$params = [$adminId];

// فلترة حسب دور الفاعل
if ($filter === 'clients') {
    // إشعارات العملاء فقط
    $where[] = "n.notification_actor_role = 'client'";
} elseif ($filter === 'providers') {
    // إشعارات مقدمي الخدمة فقط
    $where[] = "n.notification_actor_role = 'provider'";
} elseif ($filter === 'companies') {
    // إشعارات الشركات فقط
    $where[] = "n.notification_actor_role = 'company'";
}

// إضافة شرط البحث إن وُجد نص
if ($search !== '') {
    // البحث في العنوان والنص واسم الفاعل
    $where[] = "(n.notification_title LIKE ? OR n.notification_body LIKE ? OR n.notification_actor_name LIKE ?)";
    // تجهيز نمط LIKE
    $like = '%' . $search . '%';
    // إضافة نمط البحث ثلاث مرات
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

// دمج الشروط بـ AND
$whereSql = implode(' AND ', $where);

// عدّ الصفوف للترقيم
$countSql = "SELECT COUNT(*)
             FROM notification_inbox i
             INNER JOIN notifications n ON n.notification_id = i.inbox_notification_id
             WHERE $whereSql";
// تجهيز استعلام العدّ
$countStmt = $con->prepare($countSql);
// تنفيذ العدّ بنفس المعاملات
$countStmt->execute($params);
// قراءة العدد الكلي
$totalCount = (int) $countStmt->fetchColumn();
// حساب عدد الصفحات
$totalPages = $totalCount > 0 ? (int) ceil($totalCount / $limit) : 1;

// جلب صفحة الصندوق مرتبة من الأحدث
$listSql = "SELECT i.inbox_id, i.inbox_is_read, i.inbox_created_at, n.*
            FROM notification_inbox i
            INNER JOIN notifications n ON n.notification_id = i.inbox_notification_id
            WHERE $whereSql
            ORDER BY i.inbox_id DESC
            LIMIT $limit OFFSET $offset";
// تجهيز استعلام القائمة
$listStmt = $con->prepare($listSql);
// تنفيذ الاستعلام
$listStmt->execute($params);
// جلب الصفوف
$rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// إحصاءات الفلاتر وعدد غير المقروء
$statsSql = "SELECT
    COUNT(*) AS all_count,
    SUM(CASE WHEN n.notification_actor_role = 'client' THEN 1 ELSE 0 END) AS clients_count,
    SUM(CASE WHEN n.notification_actor_role = 'provider' THEN 1 ELSE 0 END) AS providers_count,
    SUM(CASE WHEN n.notification_actor_role = 'company' THEN 1 ELSE 0 END) AS companies_count,
    SUM(CASE WHEN i.inbox_is_read = 0 THEN 1 ELSE 0 END) AS unread_count
 FROM notification_inbox i
 INNER JOIN notifications n ON n.notification_id = i.inbox_notification_id
 WHERE i.inbox_recipient_kind = 'admin' AND i.inbox_admin_id = ?";
// تجهيز استعلام الإحصاءات
$statsStmt = $con->prepare($statsSql);
// تنفيذ الإحصاءات بمعرّف الموظف
$statsStmt->execute([$adminId]);
// جلب صف الإحصاءات
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

// إرسال JSON للوحة التحكم
echo json_encode([
    "status" => "success",
    "message" => "تم جلب صندوق إشعارات الإدارة",
    "data" => array_map('mapNotificationRow', $rows),
    "stats" => [
        "all_count" => (int) ($stats['all_count'] ?? 0),
        "clients_count" => (int) ($stats['clients_count'] ?? 0),
        "providers_count" => (int) ($stats['providers_count'] ?? 0),
        "companies_count" => (int) ($stats['companies_count'] ?? 0),
        "unread_count" => (int) ($stats['unread_count'] ?? 0),
    ],
    "pagination" => [
        "current_page" => $page,
        "per_page" => $limit,
        "total_count" => $totalCount,
        "total_pages" => $totalPages,
        "has_next_page" => ($page < $totalPages),
        "has_prev_page" => ($page > 1),
    ],
]);
