<?php
// ربط الاتصال بقاعدة البيانات
require_once __DIR__ . '/../../connect.php';

// تحميل دوال الطلبات والردود
require_once __DIR__ . '/../../functions.php';

// قراءة رقم الهاتف
$phone = filterRequest('users_phone');

// قراءة كود OTP
$otp = filterRequest('otp_code');

// التحقق من عدم فراغ الحقول
if ($phone === '' || $otp === '') {
    // خطأ حقول ناقصة
    printFailure('رقم الهاتف ورمز التحقق مطلوبان', 400);
    // إيقاف التنفيذ
    exit;
}

// تنظيف الرقم والكود رقميًا فقط
$phoneDigits = preg_replace('/[^0-9]/', '', $phone);

// تنظيف OTP
$otpDigits = preg_replace('/[^0-9]/', '', $otp);

// التحقق من طول OTP
if (strlen($otpDigits) !== 6) {
    // خطأ صيغة
    printFailure('صيغة رمز التحقق غير صحيحة', 400);
    // إيقاف التنفيذ
    exit;
}

// بناء صيغة محلية موحّدة
$phoneLocal = $phoneDigits;
// إذا كان الرقم يبدأ بـ +201 في الطلب الأصلي
if (str_starts_with($phone, '+201')) {
    // تحويل إلى محلي
    $phoneLocal = '0' . substr(preg_replace('/[^0-9]/', '', $phone), 2);
}

// جلب آخر OTP صالح لنفس الرقم ولغرض reset
$sql = "SELECT otp_id, otp_code, otp_expires_at
        FROM otp_codes
        WHERE otp_phone = ?
          AND otp_purpose = 'reset_password'
        ORDER BY otp_created_at DESC
        LIMIT 1";
// تجهيز الاستعلام
$stmt = $con->prepare($sql);
// تنفيذ الاستعلام
$stmt->execute([$phoneLocal]);
// جلب الصف
$row = $stmt->fetch(PDO::FETCH_ASSOC);

// إذا لا يوجد سجل
if ($row === false) {
    // فشل التحقق
    printFailure('رمز غير صحيح أو منتهي', 400);
    // إيقاف التنفيذ
    exit;
}

// قراءة وقت الانتهاء
$expiresAt = $row['otp_expires_at'];

// التحقق من انتهاء الصلاحية
if ((new DateTimeImmutable('now')) > new DateTimeImmutable($expiresAt)) {
    // حذف السجل المنتهي لتفادي إعادة استخدامه
    $stmtDelExpired = $con->prepare("DELETE FROM otp_codes WHERE otp_id = ?");
    // تنفيذ الحذف
    $stmtDelExpired->execute([(int)$row['otp_id']]);
    // إرجاع خطأ
    printFailure('رمز منتهي، اطلب رمزًا جديدًا', 400);
    // إيقاف التنفيذ
    exit;
}

// مطابقة الكود
if ((string)$row['otp_code'] !== (string)$otpDigits) {
    // فشل المطابقة
    printFailure('رمز غير صحيح', 400);
    // إيقاف التنفيذ
    exit;
}

// جلب المستخدم المرتبط بالرقم
$sqlUser = "SELECT users_id FROM users WHERE users_phone = ? LIMIT 1";
// تجهيز
$stmtUser = $con->prepare($sqlUser);
// تنفيذ
$stmtUser->execute([$phoneLocal]);
// جلب
$user = $stmtUser->fetch(PDO::FETCH_ASSOC);

// إذا لم يُعثر على مستخدم
if ($user === false) {
    // خطأ غير متوقع لكن آمن
    printFailure('تعذر إكمال العملية', 400);
    // إيقاف
    exit;
}

// حذف OTP بعد النجاح (مثل verify_otp.php عندك)
$stmtDelOtp = $con->prepare("DELETE FROM otp_codes WHERE otp_id = ?");
// تنفيذ الحذف
$stmtDelOtp->execute([(int)$row['otp_id']]);

// توليد توكن إعادة تعيين قوي
$resetToken = bin2hex(random_bytes(32));

// صلاحية التوكن 15 دقيقة
$resetExpires = (new DateTimeImmutable('now'))->modify('+15 minutes')->format('Y-m-d H:i:s');

// حفظ التوكن في المستخدم
$sqlUp = "UPDATE users
          SET users_reset_token = ?, users_reset_token_expires = ?
          WHERE users_id = ?";
// تجهيز
$stmtUp = $con->prepare($sqlUp);
// تنفيذ
$stmtUp->execute([$resetToken, $resetExpires, (int)$user['users_id']]);

// إرجاع التوكن للموبايل لاستخدامه في resetpassword.php
printSuccess([
    'message' => 'تم التحقق من الرمز',
    'reset_token' => $resetToken,
    'expires_at' => $resetExpires,
]);