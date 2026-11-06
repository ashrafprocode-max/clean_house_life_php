<?php
// ============================================================
// ملف: auth/signup.php
// الغرض: تسجيل مستخدم جديد مع إرسال OTP للتحقق
// الطريقة: POST
// الحقول المطلوبة: users_full_name, users_phone, users_password, users_type
// الحقول الاختيارية: otp_code (عند تفعيل REQUIRE_OTP_VERIFICATION)
// ============================================================

// تضمين ملف الاتصال والوظائف****
include "../connect.php";
// تضمين دوال أحداث الإشعارات بعد نجاح التسجيل
include "../includes/notification_hooks.php";

// ============================================================
// الإعداد: هل نحتاج OTP للتحقق قبل إنشاء الحساب؟
// false = إنشاء حساب مباشرة بدون OTP
// true  = يجب التحقق من OTP أولاً قبل الإنشاء
// ============================================================
// تفعيل/تعطيل التحقق بـ OTP (ضع true بعد إنشاء جدول otp_codes)
define("REQUIRE_OTP_VERIFICATION", false);

// ============================================================
// الخطوة 1: استقبال البيانات من الطلب
// filterRequest() تنظف البيانات القادمة من الـ API
// ============================================================
$name = filterRequest("users_full_name");
$phone     = filterRequest("users_phone");
$password  = filterRequest("users_password");
$type = filterRequest("users_type"); // (client أو provider)
$otp_code  = filterRequest("otp_code"); // كود OTP (اختياري)


// ============================================================
// الخطوة 2: التحقق من وجود جميع الحقول الأساسية
// ============================================================
if (empty($name) || empty($phone) || empty($password)) {
    // إرسال رسالة خطأ إذا كانت البيانات ناقصة
    printFailure("Please fill all required fields");
    exit;
}                

// ============================================================
// الخطوة 2.1: إذا كان REQUIRE_OTP_VERIFICATION مفعلاً
// تحقق من OTP قبل إنشاء الحساب
// ============================================================
if (REQUIRE_OTP_VERIFICATION) {
    // التحقق من وجود otp_code في الطلب
    if (empty($otp_code)) {
        printFailure("OTP code is required. Please verify your phone first.");
        exit;
    }

    // تنظيف رقم الهاتف من أي مسافات أو رموز غير رقمية
    $phone_clean = preg_replace('/[^0-9+]/', '', $phone);

    // التحقق من صحة الـ OTP باستخدام دالة verifyOtp
    if (verifyOtp($phone_clean, $otp_code) === false) {
        printFailure("Invalid or expired OTP. Please request a new code.");
        exit;
    }
}
    // ============================================================
    // الخطوة 3. التحقق هل المستخدم (الإيميل أو الهاتف) موجود مسبقاً؟
    // استخدمنا دالتك getData لنتأكد من عدم تكرار الحسابات
    $checkUser = getData("users", "users_phone = ?", array($phone), false);

    if ($checkUser > 0) {
        // إذا وجدنا سجل، نرسل رسالة فشل بأن الحساب موجود
        printFailure("Phone already exists");
        exit;
}

// ============================================================
// الخطوة 4: تشفير كلمة المرور باستخدام password_hash
// PASSWORD_DEFAULT يستخدم bcrypt وهو آمن جداً
// ============================================================
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

// ============================================================
// الخطوة 5: تجهيز بيانات المستخدم للإدخال في جدول users
// ============================================================
        $data = array(
            "users_full_name" => $name,
            "users_phone"     => $phone,
            "users_password"  => $hashed_password,
            "users_is_active" => 1 , // موافقة الادارة علي الحساب تلقائيا
            "users_approve" => REQUIRE_OTP_VERIFICATION ? 0 : 1 , //0 لكي نُجبره على الذهاب لصفحة الـ OTP...    تفعيل الحساب تلقائياً أو يمكن جعله 0 لإرسال كود تفعيل
            "users_type"      => $type        // client أو provider
        );

      // ============================================================
      // الخطوة 6: إدخال البيانات باستخدام insertData بدون JSON encoding
      // false = لا نريد JSON بل نريد نتيجة الـ insert
      // ============================================================
        $result = insertData("users", $data, false);

        if ($result > 0) {
         // ============================================================
         // الخطوة 7: الحصول على الـ ID الجديد للمستخدم
         // ============================================================
            $new_user_id = $con->lastInsertId();

        // ============================================================
        // الخطوة 8: إنشاء Token للمستخدم الجديد
        // نستخدم دالة sha1 مع uniqid لضمان توكن فريد وصعب التخمين
        // ============================================================
            $token_value = bin2hex(random_bytes(32)); // توليد توكن قوي جداً
             // تجهيز بيانات التوكن للإدخال في جدول users_tokens
            $token_data = array(
                "tokens_users_id" => $new_user_id,  // ربط التوكن بالمستخدم
                "tokens_value"    => $token_value,  // قيمة التوكن العشوائية
            );
            
            // ============================================================
            // الخطوة 9: إدخال التوكن في جدول users_tokens
            // false = لا نريد JSON encoding
            // ============================================================
            insertData("users_tokens", $token_data, false);

             // إشعار الإدارة بحساب جديد بعد نجاح الإدخال والتوكن
            try {
                notifyUserRegistered([
                    "users_id"        => $new_user_id,
                    "users_full_name" => $name,
                    "users_type"      => $type,
                ]);
            } catch (Throwable $e) {
                // لا نوقف التسجيل إذا فشل الإشعار
            }
            
            // ============================================================
            // الخطوة 10: إرسال رد النجاح مع بيانات المستخدم والتوكن
            // Flutter يستخدم هذه البيانات لحفظ جلسة المستخدم
            // ============================================================
            echo json_encode(array(
                "status" => "success",
                "message" => "Account created successfully",
                "token" => $token_value,  // التوكن للمصادقة
                "data" => array(
                    "users_id" => $new_user_id, // ID المستخدم
                    "users_full_name" => $name,
                    "users_phone" => $phone,
                )
            ));
        } else {
            // فشل عملية إدخال البيانات في قاعدة البيانات
            printFailure("Something went wrong during signup");
        }
        ?>