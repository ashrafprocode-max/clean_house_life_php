<?php
// ربط الاتصال بقاعدة البيانات
//لاستخدام مسارات مطلقة (Absolute Paths) باستخدام __DIR__ لضمان الوصول للمكان الصحيح
require_once __DIR__ . '/../../connect.php';

// تحميل دوال الطلبات والردود
require_once __DIR__ . '/../../functions.php';

// قراءة رقم الهاتف
$phone = filterRequest('users_phone');

// قراءة توكن إعادة التعيين
$resetToken = filterRequest('reset_token');

// قراءة كلمة المرور الجديدة
$newPassword = filterRequest('users_password');

// التحقق من الحقول
if ($phone === '' || $resetToken === '' || $newPassword === '') {
    // خطأ حقول
    printFailure('البيانات غير مكتملة', 400);
    // إيقاف
    exit;
}

// تنظيف الرقم
$phoneDigits = preg_replace('/[^0-9]/', '', $phone);

// صيغة محلية
$phoneLocal = $phoneDigits;

// التحقق من طول كلمة المرور (8 على الأقل كمثال)
if (strlen($newPassword) < 8) {
    // خطأ قوة كلمة المرور
    printFailure('كلمة المرور ضعيفة', 400);
    // إيقاف
    exit;
}

// جلب المستخدم والتحقق من التوكن وصلاحيته
$sql = "SELECT users_id, users_reset_token, users_reset_token_expires
        FROM users
        WHERE users_phone = ?
        LIMIT 1";
// تجهيز
$stmt = $con->prepare($sql);
// تنفيذ
$stmt->execute([$phoneLocal]);
// جلب
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// إذا لا يوجد مستخدم
if ($user === false) {
    // خطأ
    printFailure('تعذر إكمال العملية', 400);
    // إيقاف
    exit;
}

// التحقق من وجود توكن
if ($user['users_reset_token'] === null || $user['users_reset_token'] === '') {
    // لا يوجد توكن صالح
    printFailure('انتهت جلسة إعادة التعيين، أعد المحاولة', 400);
    // إيقاف
    exit;
}

// مقارنة التوكن بشكل آمن
if (!hash_equals((string)$user['users_reset_token'], (string)$resetToken)) {
    // توكن غير صحيح
    printFailure('رمز إعادة التعيين غير صحيح', 400);
    // إيقاف
    exit;
}

// التحقق من انتهاء صلاحية التوكن
if ((new DateTimeImmutable('now')) > new DateTimeImmutable($user['users_reset_token_expires'])) {
    // مسح التوكن المنتهي
    $stmtClr = $con->prepare("UPDATE users SET users_reset_token = NULL, users_reset_token_expires = NULL WHERE users_id = ?");
    // تنفيذ
    $stmtClr->execute([(int)$user['users_id']]);
    // خطأ
    printFailure('انتهت صلاحية إعادة التعيين', 400);
    // إيقاف
    exit;
}

// تشفير كلمة المرور الجديدة
$hash = password_hash($newPassword, PASSWORD_BCRYPT);

// تحديث كلمة المرور ومسح التوken
$sqlUp = "UPDATE users
          SET users_password = ?, users_reset_token = NULL, users_reset_token_expires = NULL
          WHERE users_id = ?";
// تجهيز
$stmtUp = $con->prepare($sqlUp);
// تنفيذ
$stmtUp->execute([$hash, (int)$user['users_id']]);

// نجاح
printSuccess(['message' => 'تم تغيير كلمة المرور بنجاح']);