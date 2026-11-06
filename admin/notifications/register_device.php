<?php
// ============================================================
// ملف: admin/notifications/register_device.php
// الغرض: حفظ توكن FCM لموظف (ويب فقط عادةً)
// ويندوز يمرر platform=windows ويمكن تجاهل FCM
// ============================================================

// ربط الاتصال بقاعدة البيانات والدوال العامة
include "../../connect.php";

// التحقق من توكن الموظف وجلب بياناته
$currentAdmin = checkAdminToken();
// فرض صلاحية عرض الإشعارات
requirePermission($currentAdmin, 'notifications.view');

// معرّف الموظف الحالي من التوكن
$adminId = (int) $currentAdmin['admin_id'];
// توكن FCM القادم من الجهاز
$token = trim((string) filterRequest("fcm_token"));
// منصة الجهاز: android / ios / web / windows
$platform = trim((string) filterRequest("platform"));

// قائمة المنصات المسموحة
$allowed = ['android', 'ios', 'web', 'windows'];
// إن كانت المنصة غير معروفة نفترض ويب
if (!in_array($platform, $allowed, true)) {
    // القيمة الافتراضية لموظف اللوحة
    $platform = 'web';
}

// ويندوز يمكنه التسجيل بلا توكن FCM
if ($token === '' && $platform !== 'windows') {
    // رسالة توضح أن التوكن مطلوب لباقي المنصات
    printFailure("fcm_token مطلوب");
}

// تسجيل ويندوز بلا توكن ثم الخروج
if ($token === '') {
    // رد نجاح بدون حفظ صف جهاز
    echo json_encode([
        "status" => "success",
        "message" => "تم تسجيل منصة ويندوز بلا توكن FCM",
    ]);
    // إيقاف التنفيذ
    exit;
}

// إدخال أو تحديث صف الجهاز حسب التوكن الفريد
$stmt = $con->prepare(
    "INSERT INTO notification_devices
        (device_owner_kind, device_admin_id, device_platform, device_fcm_token, device_is_active)
     VALUES ('admin', ?, ?, ?, 1)
     ON DUPLICATE KEY UPDATE
        device_admin_id = VALUES(device_admin_id),
        device_owner_kind = 'admin',
        device_platform = VALUES(device_platform),
        device_is_active = 1,
        device_updated_at = NOW()"
);
// تنفيذ الحفظ بمعرّف الموظف والمنصة والتوكن
$stmt->execute([$adminId, $platform, $token]);

// إرسال تأكيد الحفظ
echo json_encode([
    "status" => "success",
    "message" => "تم حفظ جهاز الموظف",
]);
