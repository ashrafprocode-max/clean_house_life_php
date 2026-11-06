<?php
// ============================================================
// ملف: admin/notifications/mark_read.php
// الغرض: تعليم إشعار واحد أو الكل كمقروء للموظف الحالي
// ============================================================

// ربط الاتصال بقاعدة البيانات والدوال العامة
include "../../connect.php";

// التحقق من توكن الموظف وجلب بياناته
$currentAdmin = checkAdminToken();
// فرض صلاحية عرض الإشعارات
requirePermission($currentAdmin, 'notifications.view');

// معرّف الموظف الحالي من التوكن
$adminId = (int) $currentAdmin['admin_id'];
// معرّف صف الصندوق المطلوب تعليمه
$inboxId = (int) filterRequest("inbox_id");
// 1 = تعليم الكل كمقروء
$markAll = (int) filterRequest("mark_all");

// تعليم كل إشعارات الموظف غير المقروءة
if ($markAll === 1) {
    // تحديث كل الصفوف غير المقروءة لهذا الموظف
    $stmt = $con->prepare(
        "UPDATE notification_inbox
         SET inbox_is_read = 1, inbox_read_at = NOW()
         WHERE inbox_recipient_kind = 'admin'
           AND inbox_admin_id = ?
           AND inbox_is_read = 0"
    );
    // تنفيذ التحديث بمعرّف الموظف
    $stmt->execute([$adminId]);
    // إرسال عدد الصفوف المحدَّثة
    echo json_encode([
        "status" => "success",
        "message" => "تم تعليم كل الإشعارات كمقروءة",
        "data" => ["updated" => (int) $stmt->rowCount()],
    ]);
    // إيقاف التنفيذ بعد الرد
    exit;
}

// رفض الطلب إن لم يُرسل معرّف صالح
if ($inboxId <= 0) {
    // رسالة توضح أن المعرّف مطلوب
    printFailure("inbox_id مطلوب");
}

// تحديث صف واحد بشرط أنه يخص هذا الموظف
$stmt = $con->prepare(
    "UPDATE notification_inbox
     SET inbox_is_read = 1, inbox_read_at = NOW()
     WHERE inbox_id = ?
       AND inbox_recipient_kind = 'admin'
       AND inbox_admin_id = ?"
);
// تنفيذ التحديث بمعرّف الصندوق ومعرّف الموظف
$stmt->execute([$inboxId, $adminId]);

// إرسال تأكيد التعليم
echo json_encode([
    "status" => "success",
    "message" => "تم تعليم الإشعار كمقروء",
    "data" => ["inbox_id" => $inboxId],
]);
