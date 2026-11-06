<?php
// ============================================================
// ملف: admin/notifications/poll.php
// الغرض: جلب الإشعارات الجديدة للموظف منذ آخر inbox_id
// الطريقة: POST — الخطة البديلة الموثوقة لـ SSE على Plesk
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
$adminId = (int)$currentAdmin['admin_id'];
// آخر inbox_id وصله العميل (صفر في أول طلب)
$afterId = (int)filterRequest("after_id");
// حد أقصى لعدد الصفوف في الرد
$limit = (int)filterRequest("limit");
// ضبط الحد بين 1 و 50
if ($limit < 1 || $limit > 50) {
    // القيمة الافتراضية إن لم يُرسل حد صالح
    $limit = 20;
}

// استعلام الإشعارات الأحدث من after_id لهذا الموظف
$sql = "SELECT i.inbox_id, i.inbox_is_read, i.inbox_created_at,
               n.*
        FROM notification_inbox i
        INNER JOIN notifications n
            ON n.notification_id = i.inbox_notification_id
        WHERE i.inbox_recipient_kind = 'admin'
          AND i.inbox_admin_id = ?
          AND i.inbox_id > ?
        ORDER BY i.inbox_id ASC
        LIMIT $limit";
// تجهيز الاستعلام المحمي
$stmt = $con->prepare($sql);
// تنفيذ الاستعلام بمعرّف الموظف وآخر معرّف
$stmt->execute([$adminId, $afterId]);
// جلب الصفوف كمصفوفة
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// تحويل صفوف القاعدة لشكل الرد الموحّد
$data = array_map('mapNotificationRow', $rows);

// عدّ الإشعارات غير المقروءة لشارة الجرس
$unreadStmt = $con->prepare(
    "SELECT COUNT(*) FROM notification_inbox
     WHERE inbox_recipient_kind = 'admin'
       AND inbox_admin_id = ?
       AND inbox_is_read = 0"
);
// تنفيذ العدّ بمعرّف الموظف
$unreadStmt->execute([$adminId]);

// إرسال JSON للوحة التحكم
echo json_encode([
    "status"  => "success",
    "message" => "تم جلب الإشعارات الجديدة",
    "data"    => $data,
    "unread_count" => (int)$unreadStmt->fetchColumn(),
    "last_id" => empty($data) ? $afterId : (int)$data[count($data) - 1]['inbox_id'],
]);
