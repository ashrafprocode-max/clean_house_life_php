<?php
// ============================================================
// ملف: notifications/mark_read.php
// الغرض: تعليم إشعار واحد أو الكل كمقروء للمستخدم الحالي
// ============================================================

// ربط الاتصال بقاعدة البيانات والدوال العامة
include "../connect.php";
// ربط مساعد الإشعارات والتحقق من توكن المستخدم
include "../includes/notification_helper.php";

// التحقق من توكن المستخدم وجلب بياناته
$currentUser = checkUserToken();
// معرّف المستخدم الحالي
$userId = (int) $currentUser['users_id'];
// معرّف صف الصندوق المطلوب تعليمه
$inboxId = (int) filterRequest("inbox_id");
// 1 = تعليم الكل كمقروء
$markAll = (int) filterRequest("mark_all");

// تعليم كل إشعارات المستخدم غير المقروءة
if ($markAll === 1) {
    // تحديث كل الصفوف غير المقروءة لهذا المستخدم
    $stmt = $con->prepare(
        "UPDATE notification_inbox
         SET inbox_is_read = 1, inbox_read_at = NOW()
         WHERE inbox_recipient_kind = 'user'
           AND inbox_user_id = ?
           AND inbox_is_read = 0"
    );
    // تنفيذ التحديث بمعرّف المستخدم
    $stmt->execute([$userId]);
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

// تحديث صف واحد بشرط أنه يخص هذا المستخدم
$stmt = $con->prepare(
    "UPDATE notification_inbox
     SET inbox_is_read = 1, inbox_read_at = NOW()
     WHERE inbox_id = ?
       AND inbox_recipient_kind = 'user'
       AND inbox_user_id = ?"
);
// تنفيذ التحديث بمعرّف الصندوق ومعرّف المستخدم
$stmt->execute([$inboxId, $userId]);

// إرسال تأكيد التعليم
echo json_encode([
    "status" => "success",
    "message" => "تم تعليم الإشعار كمقروء",
    "data" => ["inbox_id" => $inboxId],
]);
