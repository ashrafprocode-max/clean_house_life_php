<?php
// ============================================================
// ملف: auth/verify_login.php
// الغرض: التحقق من OTP بعد تسجيل الدخول (Two-Factor Authentication 2FA)
// الاستخدام: بعد أن يُدخل المستخدم phone+password بنجاح،
//            نُرسل له OTP ونطلب منه إدخاله هنا للتأكيد
// الطريقة: POST
// الحقول المطلوبة: otp_phone, otp_code
//
// ⚠️ الفرق بين verify_login.php و verify_otp.php:
// verify_otp.php:    يُستخدم للتحقق من الهاتف عند التسجيل الجديد
// verify_login.php:  يُستخدم للتحقق عند تسجيل الدخول (2FA)
//                    يُحدِّث users_approve = 1 إذا كانت = 0
// ============================================================
// ============================================================
// ملف: auth/verify_login.php
// الغرض: التحقق من OTP لتفعيل الحساب بعد التسجيل (نفس فكرة 2FA)
//
// ⭐ المشكلة التي كانت موجودة (سبب token: null وdata: null):
//   - الـ query كان يبحث بـ users_phone بالصيغة الدولية +201122449170
//   - لكن جدول users يحفظ الرقم بصيغة 01122449170 (بدون +20)
//   - لذلك كان LEFT JOIN يُرجع NULL لكل حقول المستخدم!
//
// ✅ الحل: البحث في users بالصيغة المحلية (بدون +20)
// ============================================================
include "../connect.php";

// ============================================================
// الخطوة 1: استقبال البيانات
// otp_phone: رقم الهاتف
// otp_code:  الكود المدخل من المستخدم
// ============================================================
$phone = filterRequest("otp_phone");
$otp   = filterRequest("otp_code");

// ============================================================
// الخطوة 2: التحقق من وجود البيانات
// ============================================================
if (empty($phone) || empty($otp)) {
    printFailure("Phone and OTP code are required");
    exit;
}

// ============================================================
// الخطوة 3: تنظيف البيانات من الرموز غير الضرورية
// ============================================================
$phone = preg_replace('/[^0-9+]/', '', $phone);
$otp   = preg_replace('/[^0-9]/', '', $otp);

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

// ============================================================
// الخطوة 5: البحث عن OTP في جدول otp_codes بالصيغة الدولية
// ============================================================
$stmt = $con->prepare(
    "SELECT * FROM otp_codes
     WHERE otp_phone = ? AND otp_code = ?
     ORDER BY otp_created_at DESC
     LIMIT 1"
);
$stmt->execute([$phone_international, $otp]);
$count = $stmt->rowCount();

// ============================================================
// الخطوة 6: التحقق من نجاح المطابقة
// ============================================================
if ($count > 0) {

    // ============================================================
    // الخطوة 7: تحديث users_approve = 1 بالصيغة المحلية
    // جدول users يستخدم 01122449170 وليس +201122449170
    // ============================================================
    $stmtUpdate = $con->prepare(
        "UPDATE users SET users_approve = '1' WHERE users_phone = ?"
    );
    $stmtUpdate->execute([$phone_local]);

    // إذا لم يجد، جرب الصيغة الدولية
    if ($stmtUpdate->rowCount() === 0) {
        $stmtUpdate->execute([$phone_international]);
    }

    // ============================================================
    // الخطوة 8: حذف OTP المستخدم
    // ============================================================
    $stmtDelete = $con->prepare(
        "DELETE FROM otp_codes WHERE otp_phone = ?"
    );
    $stmtDelete->execute([$phone_international]);
    // ============================================================
    // الخطوة 9: ⭐ جلب بيانات المستخدم والتوكن بالصيغة المحلية
    // هذا هو الإصلاح الرئيسي لمشكلة token: null وdata: null
    // كنا نبحث بالصيغة الدولية، الآن نبحث بالمحلية
    // ============================================================
    $stmtUser = $con->prepare(
        "SELECT u.*, ut.tokens_value
         FROM users u
         LEFT JOIN users_tokens ut ON ut.tokens_users_id = u.users_id
         WHERE u.users_phone = ?
         LIMIT 1"
    );
    $stmtUser->execute([$phone_local]); // ⭐ نبحث بـ 01122449170 وليس +20
    // إذا لم يجد بالصيغة المحلية، جرب الدولية
    if ($stmtUser->rowCount() === 0) {
        $stmtUser->execute([$phone_international]);
    }
    $user = $stmtUser->fetch(PDO::FETCH_ASSOC);
    // ============================================================
    // الخطوة 10: إرسال رد النجاح مع التوكن وبيانات المستخدم
    // ============================================================
    echo json_encode([
        "status"  => "success",
        "message" => "Account verified and activated successfully.",
        "token"   => $user['tokens_value'] ?? null,
        "data"    => [
            "users_id"        => $user['users_id']        ?? null,
            "users_full_name" => $user['users_full_name'] ?? null,
            "users_phone"     => $user['users_phone']     ?? null,
            "users_type"      => $user['users_type']      ?? null,
        ],
    ]);
} else {
    // الكود غير صحيح أو منتهي الصلاحية
    printFailure("Invalid or expired OTP code, please try again.");
}
?>