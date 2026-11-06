<?php
// ============================================================
// ملف: notifications/register_device.php
// الغرض: حفظ توكن FCM للعميل أو المزود
// ============================================================

// ربط الاتصال بقاعدة البيانات والدوال العامة
include "../connect.php";
// ربط مساعد الإشعارات والتحقق من توكن المستخدم
include "../includes/notification_helper.php";

// التحقق من توكن المستخدم وجلب بياناته
$currentUser = checkUserToken();
// معرّف المستخدم الحالي
$userId = (int)$currentUser['users_id'];
// توكن FCM القادم من الجهاز
$token = trim((string)filterRequest("fcm_token"));
// منصة الجهاز: android / ios / web / windows
$platform = trim((string)filterRequest("platform"));

// قائمة المنصات المسموحة
$allowed = ['android', 'ios', 'web', 'windows'];
// إن كانت المنصة غير معروفة نفترض أندرويد
if (!in_array($platform, $allowed, true)) {
    // القيمة الافتراضية لتطبيق الموبايل
    $platform = 'android';
}

// رفض الطلب إن لم يُرسل توكن
if ($token === '') {
    // رسالة توضح أن التوكن مطلوب
    printFailure("fcm_token مطلوب");
}

// إدخال أو تحديث صف الجهاز حسب التوكن الفريد
$stmt = $con->prepare(
    "INSERT INTO notification_devices
        (device_owner_kind, device_user_id, device_platform, device_fcm_token, device_is_active)
     VALUES ('user', ?, ?, ?, 1)
     ON DUPLICATE KEY UPDATE
        device_user_id = VALUES(device_user_id),
        device_owner_kind = 'user',
        device_platform = VALUES(device_platform),
        device_is_active = 1,
        device_updated_at = NOW()"
);
// تنفيذ الحفظ بمعرّف المستخدم والمنصة والتوكن
$stmt->execute([$userId, $platform, $token]);

// إرسال تأكيد الحفظ
echo json_encode([
    "status"  => "success",
    "message" => "تم حفظ جهاز المستخدم",
]);
