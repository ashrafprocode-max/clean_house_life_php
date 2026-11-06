<?php
// ============================================================
// ملف: notifications/list.php
// الغرض: قائمة إشعارات العميل/المزود مع ترقيم الصفحات
// ============================================================

// ربط الاتصال بقاعدة البيانات والدوال العامة
include "../connect.php";
// ربط مساعد الإشعارات والتحقق من توكن المستخدم
include "../includes/notification_helper.php";

// التحقق من توكن المستخدم وجلب بياناته
$currentUser = checkUserToken();
// معرّف المستخدم الحالي
$userId = (int) $currentUser['users_id'];
// رقم الصفحة المطلوبة
$page = (int) filterRequest("page");
// عدد الصفوف في الصفحة
$limit = (int) filterRequest("limit");
// 1 = غير المقروء فقط
$unreadOnly = (int) filterRequest("unread_only");

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

// شروط الصندوق: إشعارات هذا المستخدم فقط
$where = [
    "i.inbox_recipient_kind = 'user'",
    "i.inbox_user_id = ?",
];
// معاملات الاستعلام تبدأ بمعرّف المستخدم
$params = [$userId];
// إضافة شرط غير المقروء إن طُلب
if ($unreadOnly === 1) {
    // صفوف غير مقروءة فقط
    $where[] = "i.inbox_is_read = 0";
}
// دمج الشروط بـ AND
$whereSql = implode(' AND ', $where);

// عدّ الصفوف للترقيم
$countStmt = $con->prepare(
    "SELECT COUNT(*) FROM notification_inbox i WHERE $whereSql"
);
// تنفيذ العدّ
$countStmt->execute($params);
// قراءة العدد الكلي
$totalCount = (int) $countStmt->fetchColumn();
// حساب عدد الصفحات
$totalPages = $totalCount > 0 ? (int) ceil($totalCount / $limit) : 1;

// جلب صفحة الصندوق مرتبة من الأحدث
$listStmt = $con->prepare(
    "SELECT i.inbox_id, i.inbox_is_read, i.inbox_created_at, n.*
     FROM notification_inbox i
     INNER JOIN notifications n ON n.notification_id = i.inbox_notification_id
     WHERE $whereSql
     ORDER BY i.inbox_id DESC
     LIMIT $limit OFFSET $offset"
);
// تنفيذ استعلام القائمة
$listStmt->execute($params);
// جلب الصفوف
$rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// عدّ غير المقروء لشارة الجرس
$unreadStmt = $con->prepare(
    "SELECT COUNT(*) FROM notification_inbox
     WHERE inbox_recipient_kind = 'user'
       AND inbox_user_id = ?
       AND inbox_is_read = 0"
);
// تنفيذ العدّ بمعرّف المستخدم
$unreadStmt->execute([$userId]);

// إرسال JSON للتطبيق
echo json_encode([
    "status" => "success",
    "message" => "تم جلب قائمة الإشعارات",
    "data" => array_map('mapNotificationRow', $rows),
    "unread_count" => (int) $unreadStmt->fetchColumn(),
    "pagination" => [
        "current_page" => $page,
        "per_page" => $limit,
        "total_count" => $totalCount,
        "total_pages" => $totalPages,
        "has_next_page" => ($page < $totalPages),
        "has_prev_page" => ($page > 1),
    ],
]);
