<?php
// ============================================================
// ملف: auth/refresh_token.php
// الغرض: تجديد التوكن المنتهي الصلاحية بدون إعادة تسجيل الدخول
// الطريقة: POST
// الحقول المطلوبة: يُرسَل التوكن القديم في الـ Authorization Header
// Authorization: Bearer <old_token>
// ============================================================

// تضمين ملف الاتصال بقاعدة البيانات
include "../connect.php";

// ============================================================
// الخطوة 1: استخراج التوكن القديم من الـ Authorization Header
// نقرأ الـ header الخاص بالمصادقة
// ============================================================
$headers = getallheaders(); // جلب جميع الـ headers

// تحديد اسم الـ header (يختلف أحياناً بين Apache و Nginx)
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

// ============================================================
// الخطوة 2: التحقق من وجود الـ Authorization Header
// يجب أن يبدأ بـ "Bearer " متبوعاً بالتوكن
// ============================================================
if (empty($authHeader) || !str_starts_with($authHeader, 'Bearer ')) {
    printFailure("Authorization token is required", 401);
    exit;
}

// ============================================================
// الخطوة 3: استخراج قيمة التوكن من الـ Header
// نحذف كلمة "Bearer " من بداية النص
// ============================================================
$old_token = trim(str_replace('Bearer ', '', $authHeader));

// ============================================================
// الخطوة 4: البحث عن التوكن في قاعدة البيانات
// نتحقق من أن التوكن موجود في جدول users_tokens
// ============================================================
$stmt = $con->prepare(
    "SELECT ut.*, u.users_is_active, u.users_approve, u.users_type
     FROM users_tokens ut
     INNER JOIN users u ON u.users_id = ut.tokens_users_id
     WHERE ut.tokens_value = ?
     LIMIT 1"
);
$stmt->execute([$old_token]);
$tokenData = $stmt->fetch(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 5: التحقق من صحة التوكن
// ============================================================
if (!$tokenData) {
    // التوكن غير موجود في قاعدة البيانات
    printFailure("Invalid or expired token", 401);
    exit;
}

// ============================================================
// الخطوة 6: التحقق من حالة الحساب
// الحساب يجب أن يكون مفعلاً وموافقاً عليه
// ============================================================
if ($tokenData['users_is_active'] != 1) {
    printFailure("Account is disabled", 403);
    exit;
}

if ($tokenData['users_approve'] != 1) {
    printFailure("Account is pending approval", 403);
    exit;
}

// ============================================================
// الخطوة 7: توليد توكن جديد
// bin2hex(random_bytes(32)) يولد توكن عشوائي قوي 64 حرف
// ============================================================
$new_token = bin2hex(random_bytes(32));

// ============================================================
// الخطوة 8: تحديث التوكن في قاعدة البيانات
// نستبدل التوكن القديم بالجديد
// ============================================================
$updateStmt = $con->prepare(
    "UPDATE users_tokens SET tokens_value = ? WHERE tokens_users_id = ?"
);
$updateStmt->execute([$new_token, $tokenData['tokens_users_id']]);

// ============================================================
// الخطوة 9: إرسال رد النجاح مع التوكن الجديد
// Flutter يحفظ التوكن الجديد ويستخدمه في الطلبات القادمة
// ============================================================
echo json_encode(array(
    "status"  => "success",
    "message" => "Token refreshed successfully",
    "token"   => $new_token, // التوكن الجديد
));
?>