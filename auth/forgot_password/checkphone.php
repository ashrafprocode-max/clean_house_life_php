<?php
// ربط الاتصال بقاعدة البيانات
require_once __DIR__ . '/../../connect.php';

// تحميل دوال الطلبات والردود وإرسال SMS
require_once __DIR__ . '/../../functions.php';

// قراءة رقم الهاتف من الطلب بعد التعقيم
$phone = filterRequest('users_phone');

// التحقق من أن الرقم غير فارغ
if ($phone === '' || $phone === null) {
    // إرجاع خطأ واضح للموبايل
    printFailure('رقم الهاتف مطلوب', 400);
    // إيقاف التنفيذ
    exit;
}
// ============================================================
// الخطوة 3: تنظيف البيانات من الرموز غير الضرورية
// ============================================================
$phone = preg_replace('/[^0-9+]/', '', $phone);

// ============================================================
// الخطوة 4: إنشاء الصيغتين (الدولية والمحلية)
// الدولية: +201122449170 — للبحث في otp_codes
// المحلية: 01122449170  — للبحث في users
// ============================================================
$phone_international = $phone;

// تحويل لصيغة دولية إذا لزم
if (strpos($phone_international, '+') !== 0) {
    if (substr($phone_international, 0, 2) === '20') {
        $phone_international = '+' . $phone_international;
    } elseif (substr($phone_international, 0, 1) === '0') {
        $phone_international = '+20' . substr($phone_international, 1);
    } else {
        $phone_international = '+20' . $phone_international;
    }
}

// تحويل لصيغة محلية
$phone_local = $phone_international;
if (strpos($phone_local, '+20') === 0) {
    $phone_local = '0' . substr($phone_local, 3);
}

/*
// إزالة أي رموز غير رقمية من الرقم
$phoneDigits = preg_replace('/[^0-9]/', '', $phone);

// التحقق من أن الرقم بعد التنظيف ليس فارغًا
if ($phoneDigits === '') {
    // إرجاع خطأ بصيغة الرقم
    printFailure('صيغة رقم الهاتف غير صحيحة', 400);
    // إيقاف التنفيذ
    exit;
}

// تحويل الرقم إلى صيغة دولية مصرية عند الحاجة (+20...)
$phoneIntl = $phoneDigits;
// إذا كان الرقم يبدأ بـ 01 وطوله 11
if (str_starts_with($phoneDigits, '01') && strlen($phoneDigits) === 11) {
    // استبدال الصفر الأول بمقدمة مصر
    $phoneIntl = '+2' . $phoneDigits;
}
// إذا كان الرقم يبدأ بـ 1 وطوله 10 (بدون صفر)
elseif (str_starts_with($phoneDigits, '1') && strlen($phoneDigits) === 10) {
    // إضافة مقدمة مصر الكاملة
    $phoneIntl = '+20' . $phoneDigits;
}
// إذا كان الرقم يبدأ بـ + بالفعل
elseif (str_starts_with($phone, '+')) {
    // استخدام الرقم كما أرسله العميل
    $phoneIntl = '+' . ltrim($phoneDigits, '+');
}

// إنشاء صيغة محلية للمطابقة داخل جدول users (مثل 011...)
$phoneLocal = $phoneDigits;
// إذا الرقم الدولي يبدأ بـ +201
if (str_starts_with($phoneIntl, '+201')) {
    // تحويله إلى صيغة محلية تبدأ بـ 0
    $phoneLocal = '0' . substr($phoneIntl, 4);
}
// إذا الرقم الدولي يبدأ بـ +20 بدون 1 إضافية (حالات نادرة)
elseif (str_starts_with($phoneIntl, '+20') && strlen($phoneIntl) >= 12) {
    // إعادة بناء محلي تقريبي
    $phoneLocal = '0' . substr($phoneIntl, 3);
}
*/

// استعلام التحقق من وجود المستخدم بالرقم المحلي أو الدولي
$sqlUser = "SELECT users_id FROM users WHERE users_phone = ? OR users_phone = ? LIMIT 1";
// تجهيز الاستعلام
$stmtUser = $con->prepare($sqlUser);
// تنفيذ الاستعلام بالقيم
$stmtUser->execute([$phone_local, $phone_international]);
// جلب النتيجة
$rowUser = $stmtUser->fetch(PDO::FETCH_ASSOC);

// إذا المستخدم غير موجود
if ($rowUser === false) {
    // إرجاع رسالة عامة لتقليل Enumeration
    printFailure('لا يوجد حساب مرتبط بهذا الرقم', 404);
    // إيقاف التنفيذ
    exit;
}

// توليد كود OTP مكوّن من 6 أرقام
$otpCode = (string) random_int(100000, 999999);

// حساب وقت انتهاء الصلاحية بعد 5 دقائق
$expiresAt = (new DateTimeImmutable('now'))->modify('+5 minutes')->format('Y-m-d H:i:s');

// حذف أي أكواد قديمة لنفس الرقم ولنفس الغرض لتجنب التضارب
$sqlDel = "DELETE FROM otp_codes WHERE otp_phone IN (?, ?) AND otp_purpose = 'reset_password'";
// تجهيز الحذف
$stmtDel = $con->prepare($sqlDel);
// تنفيذ الحذف
$stmtDel->execute([$phone_local, $phone_international]);

// إدراج OTP جديد بغرض إعادة التعيين
$sqlIns = "INSERT INTO otp_codes (otp_phone, otp_code, otp_purpose, otp_expires_at, otp_verified, otp_created_at)
           VALUES (?, ?, 'reset_password', ?, 0, NOW())";
// تجهيز الإدراج
$stmtIns = $con->prepare($sqlIns);
// تنفيذ الإدراج على الرقم المحلي (موحّد مع باقي الملفات)
$stmtIns->execute([$phone_local, $otpCode, $expiresAt]);

// إرسال نفس الكود عبر SMS وواتساب معاً
$otpChannels = sendOtpChannels($phone_international, $otpCode);
$smsOk = !empty($otpChannels['ok']);
// ========================================================
//  يجب ارجاع النتيجة المحصلة من الدالة في production =========================
// ========================================================
// إذا فشل الإرسال هيفشل في الاثنين لذلك ما في داعي نتحقق من الصيغتين   
// if ($smsOk !== true) {
//     // إرجاع خطأ للموبايل
//     printFailure('تعذر إرسال رمز التحقق، حاول لاحقًا', 502);
//     // إيقاف التنفيذ
//     exit;
// }

// نجاح العملية بدون كشف الكود في الرد
printSuccess([
    'message' => 'تم إرسال رمز التحقق إلى هاتفك عبر الرسائل وواتساب',
    'channels' => [
        'sms' => !empty($otpChannels['sms']),
        'whatsapp' => !empty($otpChannels['whatsapp']),
    ],
]);