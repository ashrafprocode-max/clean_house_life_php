<?php
// ============================================================
// ملف: client_profiles/update_client_profile.php
// الغرض: تحديث بيانات العميل الاختيارية مع معالجة تغيير الصورة القديمة
// الطلب: POST يحتوي على المعرف والحقول المراد تحديثها وصورة جديدة اختيارية
// ============================================================

// تضمين ملف الاتصال بقاعدة البيانات والدوال الأساسية للمشروع
include "../connect.php";

// استقبال المعرف الأساسي للمستخدم المراد تعديله
$users_id = filterRequest("clients_users_id");

// استقبال البيانات المراد تعديلها من واجهات فلاتر
$email            = filterRequest("clients_email");
$detailed_address = filterRequest("clients_detailed_address");
$birth_date       = filterRequest("clients_birth_date");
$nationality      = filterRequest("clients_nationality");

// التحقق من أن معرف المستخدم متوفر لتنفيذ الاستعلام بشكل صحيح
if (!empty($users_id)) {

    // جلب بيانات البروفايل الحالي لمعرفة اسم الصورة القديمة وحذفها من السيرفر عند التعديل
    $stmt = $con->prepare("SELECT clients_profile_image FROM client_profiles WHERE clients_users_id = ?");
    $stmt->execute([$users_id]);
    $currentProfile = $stmt->fetch(PDO::FETCH_ASSOC);

    // تصحيح مسار المجلد لمنع الشرطة المائلة المضاعفة
    $uploadDir = "../upload/client_profiles";

    $newProfileImage = null; // تعريف المتغير لتفادي أخطاء PHP

    // فحص ما إذا كان العميل قد أرسل صورة شخصية جديدة (اختيارية)
    if (isset($_FILES['clients_profile_image']) && 
        $_FILES['clients_profile_image']['error'] !== UPLOAD_ERR_NO_FILE) {

        // رفع الصورة الجديدة وحفظ اسم في متغير
        $newProfileImage = imageUploadTwo($uploadDir, "clients_profile_image");

        // إذا نجح الرفع (تم الملف وليس رسالة خطأ)، نقوم بإدراج الصورة الجديدة ثم حذف القديمة
        if ($newProfileImage === "fail" || $newProfileImage === "extension_error" || $newProfileImage === "size_error") {
            printFailure("Failed to upload new image due to: " . $newProfileImage);
            exit();
        }

        // التحقق من وجود صورة قديمة مخزنة في السيرفر لحذفها من المجلد (في حالة التحديث فقط)
        if ($currentProfile && !empty($currentProfile['clients_profile_image'])) {
            $oldFilePath = "../upload/client_profiles" . $currentProfile['clients_profile_image'];
            if (file_exists($oldFilePath)) {
                @unlink($oldFilePath);
            }
        }
    }

    // =========================================================
    // 💡 الحل الجذري: بناء مصفوفة البيانات وإضافة الحقول الممتلئة فقط
    // تجنباً لتمرير '' لحقول التاريخ في MySQL Strict Mode
    // =========================================================
    $data = array();

    if (!empty($email) && trim($email) !== '') {
        $data["clients_email"] = trim($email);
    }
    if (!empty($detailed_address) && trim($detailed_address) !== '') {
        $data["clients_detailed_address"] = trim($detailed_address);
    }
    if (!empty($birth_date) && trim($birth_date) !== '') {
        $data["clients_birth_date"] = trim($birth_date);
    }
    if (!empty($nationality) && trim($nationality) !== '') {
        $data["clients_nationality"] = trim($nationality);
    }

    // =========================================================
    // 💡 التحقق من وجود البروفايل (UPDATE أم INSERT):
    // =========================================================
if ($currentProfile) {

        // إذا تم إرسال صورة جديدة، نُضيفها إلى مصفوفة البيانات المراد تحديثها
        if ($newProfileImage != null) {
            // 1. حذف الصورة القديمة من مجلد الرفع إن وجدت
            if ($currentProfile && !empty($currentProfile['clients_profile_image'])) {
                deleteFile("../upload/client_profiles", $currentProfile['clients_profile_image']);
            }
            
            // 2. تعيين اسم الصورة الجديدة للتحديث
            $data['clients_profile_image'] = $newProfileImage;
        }

        // إذا كانت هناك بيانات مراد تعديلها (سواء نصوص أو صورة جديدة)
        if (!empty($data)) {
            $result = updateData(
                "client_profiles",
                $data,
                "clients_users_id = '$users_id'",
                false
            );
        }

        // تحديد اسم الصورة النهائية (الجديدة إن وجدت، أو القديمة من قواعد البيانات)
        $finalProfileImage = ($newProfileImage != null) 
            ? $newProfileImage 
            : ($currentProfile['clients_profile_image'] ?? null);

        // إرجاع استجابة النجاح دائماً مع اسم الصورة المحدث
        echo json_encode([
            "status"  => "success",
            "message" => "Client Profile updated successfully.",
            "data"    => [
                "clients_users_id"     => $users_id,
                "clients_profile_image" => $finalProfileImage
            ]
        ]);

    

    } else {

        // 2️⃣ حالة عدم وجود بروفايل للمستخدم (تم مسح الصف يدوياً) -> تنفيذ INSERT تلقائياً
        $data["clients_users_id"]      = $users_id;
        $data["clients_profile_image"] = $newProfileImage;

        // تنفيذ إدخال البيانات في الجدول بنفس الدالة المعتمدة لديك insertData
        $result = insertData("client_profiles", $data, false);

        if ($result > 0) {
            echo json_encode([
                "status"  => "success",
                "message" => "Client Profile created and updated successfully.",
                "data"    => [
                    "clients_users_id"      => $users_id,
                    "clients_profile_image" => $newProfileImage,
                ]
            ]);
        } else {
            // تنظيف الصورة المرفوعة في حالة فشل حفظ البيانات في DB
            if ($newProfileImage !== null) {
                deleteFile("../upload/client_profiles", $newProfileImage);
            }
            printFailure("Failed to save profile. Please try again.");
        }
    }

} else {
    // إرجاع رسالة خطأ في حالة عدم إرسال معرف المستخدم من التطبيق
    printFailure("Missing user ID");
}



/*

include "../connect.php";

// استقبال المعرف الأساسي للمستخدم لتحديد الحساب المراد تحديثه
$users_id = filterRequest("clients_users_id");

// استقبال البيانات المراد تعديلها من واجهات فلاتر
$email = filterRequest("clients_email");
$detailed_address = filterRequest("clients_detailed_address");
$birth_date = filterRequest("clients_birth_date");
$nationality = filterRequest("clients_nationality");

// التحقق من أن معرف المستخدم متوفر لتنفيذ الاستعلام بشكل صحيح
if (!empty($users_id)) {

// جلب بيانات البروفايل الحالي لمعرفة اسم الصورة القديمة وحذفها من السيرفر عند التغيير
$stmt = $con->prepare("SELECT clients_profile_image FROM client_profiles WHERE clients_users_id = ?");
$stmt->execute(array($users_id));
$currentProfile = $stmt->fetch(PDO::FETCH_ASSOC);

// بناء مصفوفة التحديث وتعبئتها بالبيانات النصية القادمة
// ✅ 2. معالجة التواريخ والحقول الفارغة بشكل صحيح تماماً في PHP
$clients_email = (isset($email) && trim($email) !== '') ? trim($email) : null;
$clients_detailed_address = (isset($detailed_address) && trim($detailed_address) !== '') ? trim($detailed_address) :
null;
$clients_birth_date = (isset($birth_date) && trim($birth_date) !== '') ? trim($birth_date) : null;
$clients_nationality = (isset($nationality) && trim($nationality) !== '') ? trim($nationality) : null;

$data = array(
"clients_email" => $clients_email,
"clients_detailed_address" => $clients_detailed_address,
"clients_birth_date" => $clients_birth_date,
"clients_nationality" => $clients_nationality,
);

// $data = array(
// "clients_email" => !empty($email) ? $email : null,
// "clients_detailed_address" => !empty($detailed_address) ? $detailed_address : null,
// "clients_birth_date" => !empty($birth_date) ? $birth_date : null,
// "clients_nationality" => !empty($nationality) ? $nationality : null,
// );



// تصحيح مسار المجلد لمنع الشرطة المائلة المضاعفة
$uploadDir = "../upload/client_profiles/";

$newProfileImage = null; // تعريف المتغير لتفادي أخطاء PHP

// فحص ما إذا كان العميل قد أرسل صورة شخصية جديدة (اختيارية)
if (isset($_FILES['clients_profile_image']) &&
$_FILES['clients_profile_image']['error'] !== UPLOAD_ERR_NO_FILE) {

// رفع الصورة الجديدة وحفظ اسم في متغير
$newProfileImage = imageUploadTwo($uploadDir, "clients_profile_image");

// إذا نجح الرفع (تم الملف وليس رسالة خطأ)، نقوم بإدراج الصورة الجديدة ثم حذف القديمة
if ($newProfileImage === "fail" || $newProfileImage === "extension_error" || $newProfileImage === "size_error") {
printFailure("Failed to upload new image due to: " . $newProfileImage);
exit();
}

// إدراج الصورة الجديدة دائماً في مصفوفة البيانات سواء كانت توجد قيمة أو لا
$data["clients_profile_image"] = $newProfileImage;

// التحقق من وجود صورة قديمة مخزنة في السيرفر لحذفها من المجلد
// حذف الصورة القديمة لتوفير مساحة السيرفر
if (!empty($currentProfile['clients_profile_image'])) {
$oldFilePath = "../upload/client_profiles/" . $currentProfile['clients_profile_image'];
if (file_exists($oldFilePath)) {
@unlink($oldFilePath);
}
}
}

// تنفيذ عملية التحديث الآمنة في قاعدة البيانات بناءً على معرف المستخدم
$result = updateData("client_profiles",
$data,
"clients_users_id = $users_id",
false
);

// ============================================================
// إرسال الرد
// ============================================================
if ($result > 0 || $newProfileImage !== null) {
echo json_encode([
"status" => "success",
"message" => "Client Profile updated successfully.",
"data" => [
"clients_users_id" => $users_id,
"clients_profile_image" => $data["clients_profile_image"] ?? $currentProfile['clients_profile_image'] ?? null,
],
]);
} else {
echo json_encode([
"status" => "success",
"message" => "No changes were made",
]);
}

} else {
// إرجاع رسالة خطأ في حال عدم إرسال معرف المستخدم من التطبيق
printFailure("Missing user ID");
}
*/
?>