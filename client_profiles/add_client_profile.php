<?php
// ============================================================
// ملف: client_profiles/add_client_profile.php
// الغرض: إنشاء البروفايل الاختياري للعميل لأول مرة مع رفع الصورة
// الطلب: POST يحتوي على الحقول وصورة شخصية اختيارية
// ============================================================

// تضمين ملف الاتصال بقاعدة البيانات والدوال الأساسية للمشروع
include "../connect.php";

// استقبال المعرف الأساسي للمستخدم (مربوط بجدول users) وهو حقل إجباري للربط
$users_id = filterRequest("clients_users_id");

// استقبال البيانات الشخصية الاختيارية القادمة من تطبيق فلاتر
$email            = filterRequest("clients_email");
$detailed_address = filterRequest("clients_detailed_address");
$birth_date       = filterRequest("clients_birth_date");
$nationality      = filterRequest("clients_nationality");

// التحقق من أن معرف المستخدم ليس فارغاً لضمان سلامة الربط في قاعدة البيانات
if (!empty($users_id)) {

    // التحقق أولاً مما إذا كان هذا المستخدم يمتلك بروفايل بالفعل منعاً للتكرار
    $checkProfile = $con->prepare("SELECT * FROM client_profiles WHERE clients_users_id = ?");
    $checkProfile->execute(array($users_id));
    $count = $checkProfile->rowCount();

    // إذا كان البروفايل موجوداً مسبقاً، نرسل رسالة فشل لتوجيه التطبيق لاستخدام التحديث
    if ($count > 0) {
        printFailure("Profile already exists for this user");
        exit();
    }

// ============================================================
// الخطوة 3: التحقق من أن المستخدم موجود ونوعه provider
// ============================================================
$stmtUser = $con->prepare(
    "SELECT users_id, users_type FROM users WHERE users_id = ? LIMIT 1"
);
$stmtUser->execute([$users_id]);
$user = $stmtUser->fetch(PDO::FETCH_ASSOC);

if (!$user || $user['users_type'] !== 'client') {
    printFailure("User not found or not a client");
    exit;
}
 
    // تجهيز متغير الصورة الشخصية كـ NULL كقيمة افتراضية في حال لم يرفع العميل صورة
    $profileImageName = null;
// نتحقق من وجود الملف قبل المحاولة
if (isset($_FILES['clients_profile_image']) &&
    $_FILES['clients_profile_image']['error'] !== UPLOAD_ERR_NO_FILE) {
   
    $uploadResult = imageUploadTwo("../upload/client_profiles", "clients_profile_image");
   // $uploadResult = imageUploadTwo(__DIR__ ."/../upload/client_profiles/", "clients_profile_image");
    
    if ($uploadResult === "fail" || $uploadResult === "extension_error" ||
        $uploadResult === "size_error") {
        printFailure("Failed to upload image due to: " . $uploadResult);
        exit(); // إيقاف العملية لمنع تخزين اسم الصورة في قاعدة البيانات
        // الصورة اختيارية فلا نوقف العملية، فقط نتجاهلها
       // $profileImageName = null;
    }
    $profileImageName = $uploadResult;
} else {
    $profileImageName = null;
}
    // // التحقق من وجود ملف صورة مرفوع في الطلب (تحت اسم files) لرفعه للسيرفر
    // if (isset($_FILES['files'])) {
    //     // استخدام دالة رفع الصور المعتمدة في المشروع وتحديد مجلد حفظ صور العملاء
    //     $imageName = imageUpload('files');
    // }

    // بناء مصفوفة البيانات التي سيتم إدخالها في جدول بروفايل العميل
    // $data = array(
    //     "clients_users_id"         => $users_id,
    //     "clients_profile_image"    => $profileImageName, // ستكون اسم الملف المرفوع أو NULL
    //     "clients_email"            => !empty($email) ? $email : null,
    //     "clients_detailed_address" => !empty($detailed_address) ? $detailed_address : null,
    //     "clients_birth_date"       => !empty($birth_date) ? $birth_date : null,
    //     "clients_nationality"      => !empty($nationality) ? $nationality : null,
    // );


    // ✅ 2. تصحيح معالجة الحقول الفارغة والتواريخ لمنع خطأ SQLSTATE[22007]
    $data = array(
        "clients_users_id"         => $users_id,
        "clients_profile_image"    => $profileImageName,
        "clients_email"            => (isset($email) && trim($email) !== '') ? trim($email) : null,
        "clients_detailed_address" => (isset($detailed_address) && trim($detailed_address) !== '') ? trim($detailed_address) : null,
        "clients_birth_date"       => (isset($birth_date) && trim($birth_date) !== '') ? trim($birth_date) : null,
        "clients_nationality"      => (isset($nationality) && trim($nationality) !== '') ? trim($nationality) : null,
    );
    // تنفيذ عملية الإدخال الآمنة باستخدام الدالة الجاهزة insertData المتواجدة بالمشروع
   $result =  insertData("client_profiles", $data,false);

   // ============================================================
// الخطوة 9: إرسال الرد
// ============================================================
if ($result > 0) {
    echo json_encode([
        "status"  => "success",
        "message" => "Profile submitted successfully.",
        "data"    => [
            "clients_users_id"     => $users_id,
            "clients_profile_image"=> $profileImageName,
        ],
    ]);
} else {
    // تنظيف الصور المرفوعة في حالة فشل قاعدة البيانات
    if ($profileImageName) {
        deleteFile("../upload/client_profiles", $profileImageName);
    }
    printFailure("Failed to save profile. Please try again.");
}

} else {
    // إرجاع رسالة خطأ في حال عدم إرسال معرف المستخدم الأساسي من التطبيق
    printFailure("Missing user ID");
}
?>