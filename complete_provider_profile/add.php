<?php
// ============================================================
// ملف: complete_provider_profile/add.php
// الغرض: إضافة / تحديث بيانات مقدم الخدمة في جدول professional_profiles
// الطريقة: POST (multipart/form-data لأن فيه صور)
// الحقول الإجبارية: providers_users_id, providers_experience,
//                    providers_age, providers_nationality,
//                    providers_current_address,
//                    profile_image, id_card_front, id_card_back
// الحقول الاختيارية: providers_bio, criminal_record_image
// ============================================================

// تضمين ملف الاتصال والدوال المساعدة
include "../connect.php";
// تضمين دوال أحداث الإشعارات بعد نجاح حفظ الملف المهني
include "../includes/notification_hooks.php";

// إجبار السيرفر على فتح الصلاحيات للمجلدات برمجياً
//chmod(__DIR__ . "/../upload/profiles/", 0777);
//chmod(__DIR__ . "/../upload/id_cards/", 0777);
//chmod(__DIR__ . "/../upload/criminal_records/", 0777);

// ============================================================
// الخطوة 1: استقبال البيانات النصية
// ============================================================
$users_id   = filterRequest("providers_users_id");           // ID المستخدم
$experience = filterRequest("providers_experience");         // سنوات الخبرة (إجباري)
$bio        = filterRequest("providers_bio");                // نبذة (اختياري)
$age        = filterRequest("providers_age");                // العمر (إجباري)
$gender     = filterRequest("providers_gender");             // النوع (إجباري)
$nationality= filterRequest("providers_nationality");        // الجنسية (إجباري)
$country    = filterRequest("providers_current_country");    // الدولة (إجباري)
$governorate= filterRequest("providers_current_governorate"); // المحافظة (إجباري)
$area       = filterRequest("providers_current_area");        // المنطقة (إجباري)
$address    = filterRequest("providers_current_address");     // العنوان (إجباري)

// ============================================================
// الخطوة 2: التحقق من الحقول الإجبارية النصية
// ============================================================
if (empty($users_id) || empty($experience) || empty($age) ||
    empty($gender) || empty($nationality) || empty($address) || 
    empty($country) || empty($governorate) || empty($area)) {
    printFailure("Please fill all required fields");
    exit;
}

// ============================================================
// الخطوة 3: التحقق من أن المستخدم موجود ونوعه provider
// ============================================================
$stmtUser = $con->prepare(
    "SELECT users_id, users_type FROM users WHERE users_id = ? LIMIT 1"
);
$stmtUser->execute([$users_id]);
$user = $stmtUser->fetch(PDO::FETCH_ASSOC);

if (!$user || $user['users_type'] !== 'provider') {
    printFailure("User not found or not a provider");
    exit;
}

// ============================================================
// الخطوة 4: رفع الصورة الشخصية (إجباري)
// نستخدم imageUploadTwo لأنها تدعم المسار + التحقق من الحجم
// ============================================================
$profileImageName = imageUploadTwo(__DIR__ ."/../upload/provider_profiles/", "profile_image");
if ($profileImageName === "fail" || $profileImageName === "empty" ||
    $profileImageName === "extension_error" || $profileImageName === "size_error") {
    printFailure("Profile image upload failed: $profileImageName");
    exit;
}

// ============================================================
// الخطوة 5: رفع صورة البطاقة الأمامية (إجباري)
// ============================================================
$idFrontName = imageUploadTwo(__DIR__ ."/../upload/id_cards/", "id_card_front");
if ($idFrontName === "fail" || $idFrontName === "empty" ||
    $idFrontName === "extension_error" || $idFrontName === "size_error") {
    // حذف الصورة الشخصية إذا فشل رفع البطاقة
    deleteFile(__DIR__ ."/../upload/provider_profiles/", $profileImageName);
    printFailure("ID card front upload failed: $idFrontName");
    exit;
}

// ============================================================
// الخطوة 6: رفع صورة البطاقة الخلفية (إجباري)
// ============================================================
$idBackName = imageUploadTwo(__DIR__ ."/../upload/id_cards/", "id_card_back");
if ($idBackName === "fail" || $idBackName === "empty" ||
    $idBackName === "extension_error" || $idBackName === "size_error") {
    deleteFile(__DIR__ ."/../upload/provider_profiles/", $profileImageName);
    deleteFile(__DIR__ ."/../upload/id_cards/", $idFrontName);
    printFailure("ID card back upload failed: $idBackName");
    exit;
}

// ============================================================
// الخطوة 7: رفع الصحيفة الجنائية (اختياري)
// إذا لم يُرفع، تُخزن القيمة NULL
// ============================================================
$criminalName = null;
// نتحقق من وجود الملف قبل المحاولة
if (isset($_FILES['criminal_record_image']) &&
    $_FILES['criminal_record_image']['error'] !== UPLOAD_ERR_NO_FILE) {
    $criminalName = imageUploadTwo(__DIR__ ."/../upload/criminal_records/", "criminal_record_image");
    if ($criminalName === "fail" || $criminalName === "extension_error" ||
        $criminalName === "size_error") {
        // الصحيفة اختيارية فلا نوقف العملية، فقط نتجاهلها
        $criminalName = null;
    }
}

// ============================================================
// الخطوة 8: رفع إيصال مرافق (اختياري)
// إذا لم يُرفع، تُخزن القيمة NULL
// ============================================================
$utilityName = null;
// نتحقق من وجود الملف قبل المحاولة
if (isset($_FILES['utility_receipt_image']) &&
    $_FILES['utility_receipt_image']['error'] !== UPLOAD_ERR_NO_FILE) {
    $utilityName = imageUploadTwo(__DIR__ ."/../upload/utility_receipt/", "utility_receipt_image");
    if ($utilityName === "fail" || $utilityName === "extension_error" ||
        $utilityName === "size_error") {
        // إيصال المرافق اختياري فلا نوقف العملية، فقط نتجاهلها
        $utilityName = null;
    }
}

// ============================================================
// الخطوة 9: التحقق من وجود سجل سابق لمقدم الخدمة
// إذا موجود → نُحدّث، إذا غير موجود → نُضيف جديد
// ============================================================
$stmtCheck = $con->prepare(
    "SELECT providers_id FROM professional_profiles
     WHERE providers_users_id = ? LIMIT 1"
);
$stmtCheck->execute([$users_id]);
$existingProfile = $stmtCheck->fetch(PDO::FETCH_ASSOC);

if ($existingProfile) {
    // ========== تحديث السجل الموجود ==========
    $updateData = [
        "providers_experience"            => $experience,
        "providers_bio"                   => $bio ?: null,
        "providers_age"                   => (int)$age,
        "providers_gender"                => $gender,
        "providers_nationality"           => $nationality,
        "providers_current_country"       => $country,
        "providers_current_governorate"   => $governorate,
        "providers_current_area"          => $area,
        "providers_current_address"       => $address,
        "providers_profile_image"         => $profileImageName,
        "providers_id_card_front"         => $idFrontName,
        "providers_id_card_back"          => $idBackName,
        // نُحدّث الصحيفة فقط إذا رُفعت
        "providers_criminal_record_image" => $criminalName,
        // نُحدّث إيصال المرافق فقط إذا رُفعت
        "providers_utility_receipt_image" => $utilityName,
        // إعادة تعيين providers_is_verified = 0 عند التحديث (مراجعة جديدة)
        "providers_is_verified"           => 0,
    ];

    // حذف NULL values من الـ update (لا نريد الكتابة فوق البيانات القديمة)
    if ($criminalName === null) {
        unset($updateData["providers_criminal_record_image"]);
    }

    if ($utilityName === null) {
        unset($updateData["providers_utility_receipt_image"]);
    }

    $result = updateData(
        "professional_profiles",
        $updateData,
        "providers_users_id = $users_id",
        false
    );

} else {
    // ========== إضافة سجل جديد ==========
    $insertData = [
        "providers_users_id"              => $users_id,
        "providers_experience"            => $experience,
        "providers_bio"                   => $bio ?: null,
        "providers_age"                   => (int)$age,
        "providers_gender"                => $gender,
        "providers_nationality"           => $nationality,
        "providers_current_country"       => $country,
        "providers_current_governorate"   => $governorate,
        "providers_current_area"          => $area,
        "providers_current_address"       => $address,
        "providers_profile_image"         => $profileImageName,
        "providers_id_card_front"         => $idFrontName,
        "providers_id_card_back"          => $idBackName,
        "providers_criminal_record_image" => $criminalName,
        "providers_utility_receipt_image" => $utilityName,
        // 0 = في انتظار مراجعة الأدمن
        "providers_is_verified"           => 0,
        // التقييم الابتدائي = 0
        "providers_rating"                => 0.00,
    ];

    $result = insertData("professional_profiles", $insertData, false);
}

// ============================================================
// الخطوة 10: إرسال الرد
// ============================================================
if ($result > 0) {
     // إشعار الإدارة باكتمال الملف المهني وانتظار الموافقة
    try {
        notifyProviderProfileCompleted([
            "users_id"        => $users_id,
            "users_full_name" => $user['users_full_name'] ?? '',
            "users_type"      => $user['users_type'] ?? 'provider',
        ]);
    } catch (Throwable $e) {
        // لا نوقف حفظ الملف إذا فشل الإشعار
    }
    echo json_encode([
        "status"  => "success",
        "message" => "Profile submitted successfully. Awaiting admin verification.",
        "data"    => [
            "providers_users_id"     => $users_id,
            "providers_profile_image"=> $profileImageName,
            // ⚠️ الأمان: لا نُرجع صور البطاقات للتطبيق
            // تظهر فقط في لوحة تحكم الأدمن
            "providers_is_verified"  => 0,
        ],
    ]);
} else {
    // تنظيف الصور المرفوعة في حالة فشل قاعدة البيانات
    deleteFile(__DIR__ ."/../upload/provider_profiles/",         $profileImageName);
    deleteFile(__DIR__ ."/../upload/id_cards/",         $idFrontName);
    deleteFile(__DIR__ ."/../upload/id_cards/",         $idBackName);
    if ($criminalName) {
        deleteFile(__DIR__ ."/../upload/criminal_records/", $criminalName);
    }
    if ($utilityName) {
        deleteFile(__DIR__ ."/../upload/utility_receipt/", $utilityName);
    }
    printFailure("Failed to save profile. Please try again.");
}
?>