<?php
/**
 * ========================================
 * ملف تسجيل الدخول - Clean House Life
 * الإصدار: 2026
 * ========================================
 * 
 * نقاط النهاية (Endpoint): POST /auth/login.php
 * 
 * البيانات المطلوبة (JSON Body):
 * {
 *     "users_phone": "01122449170",
 *     "users_password": "123456"
 * }
 * 
 * الاستجابة عند النجاح:
 * {
 *     "status": "success",
 *     "message": "Login successful",
 *     "token": "abc123...",
 *     "data": {
 *         "users_id": 1,
 *         "users_full_name": "أشرف طلعت",
 *         "users_phone": "01122449170",
 *         "users_type": "client"
 *     }
 * }
 */

// ========== 1. تضمين ملف الاتصال ==========
// يحتوي على اتصال قاعدة البيانات والدوال المساعدة
include "../connect.php";

// ========== 2. استقبال البيانات وتنظيفها ==========
// filterRequest تقوم بتنظيف البيانات من أي أكواد ضارة
$phone    = filterRequest("users_phone");
$password = filterRequest("users_password");

// ========== 3. التحقق من وجود البيانات المطلوبة ==========
if (empty($phone) || empty($password)) {
    // إرسال رسالة خطأ إذا كانت البيانات ناقصة
    printFailure("Phone and password are required");
    exit;
}

// ========== 4. تنظيف رقم الهاتف ==========
// إزالة أي أحرف غير رقمية ماعدا +
$phone = preg_replace('/[^0-9+]/', '', $phone);

// ========== 5. البحث عن المستخدم في قاعدة البيانات ==========
// نبحث برقم الهاتف فقط (ليس بكلمة المرور لأنها مشفرة)
// هام: نستخدم false لمنع طباعة JSON تلقائياً
global $con;

$stmt = $con->prepare("SELECT * FROM users WHERE users_phone = ?");
$stmt->execute([$phone]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$count = $stmt->rowCount();

// ========== 6. التحقق من وجود المستخدم ==========
if ($count == 0 || !$user) {
    // المستخدم غير موجود
    printFailure("Invalid phone or password");
    exit;
}

// ========== 7. التحقق من كلمة المرور ==========
// password_verify تقارن كلمة المرور المُدخلة مع الـ Hash المحفوظ
// هذه الطريقة أكثر أماناً من المقارنة المباشرة
if (!password_verify($password, $user['users_password'])) {
    // كلمة المرور خاطئة
    printFailure("Invalid phone or password");
    exit;
}

// ========== 8. التحقق من حالة الحساب ==========

if ($user['users_is_active'] != 1) {
    // الحساب غير مفعّل
   // الحالة: الحساب محظور من الإدارة (Ban)
    printFailure("Account is pending approval. Please wait for admin approval." );
    exit;
}
// هل الحساب مفعّل؟ (users_is_active = 1)
    if ($user['users_approve'] == "0") {
        // الحالة: مسجل ولكن لم يدخل الـ OTP
        printFailure("Account is not activated. Please verify your phone first.");
        exit;
    }
// ========== 9. إنشاء أو تحديث Token الجلسة ==========
// نبحث عن توكن موجود للمستخدم
$stmtToken = $con->prepare("SELECT tokens_id FROM users_tokens WHERE tokens_users_id = ?");
$stmtToken->execute([$user['users_id']]);
$existingToken = $stmtToken->fetch(PDO::FETCH_ASSOC);
// توليد توكن جديد (64 حرف hex = 32 bytes)
$new_token = bin2hex(random_bytes(32));
if ($existingToken) {
    // ========== تحديث التوكن الموجود ==========
    $updateStmt = $con->prepare("UPDATE users_tokens SET tokens_value = ? WHERE tokens_users_id = ?");
    $updateStmt->execute([$new_token, $user['users_id']]);
} else {
    // ========== إنشاء توكن جديد ==========
    $insertStmt = $con->prepare("INSERT INTO users_tokens (tokens_users_id, tokens_value) VALUES (?, ?)");
    $insertStmt->execute([$user['users_id'], $new_token]);
}
// ========== 10. تحديث آخر وقت تسجيل دخول (اختياري) ==========
// يمكنك إضافة عمود users_last_login في جدول users
// $updateLogin = $con->prepare("UPDATE users SET users_last_login = NOW() WHERE users_id = ?");
// $updateLogin->execute([$user['users_id']]);
// ========== 11. إرسال استجابة النجاح ==========
// نُرجع بيانات المستخدم الأساسية + التوكن
echo json_encode([
    "status" => "success",
    "message" => "Login successful",
    "token" => $new_token,
    "data" => [
        "users_id" => $user['users_id'],
        "users_full_name" => $user['users_full_name'],
        "users_phone" => $user['users_phone'],
        "users_type" => $user['users_type'],
        "users_country" => $user['users_country'] ?? null,
        "users_region" => $user['users_region'] ?? null
    ]
]);