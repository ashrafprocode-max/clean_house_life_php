<?php
// ============================================================
// ملف: complete_provider_profile/view.php
// الغرض: عرض بيانات مقدم الخدمة + حالة التحقق والموافقة
// الطريقة: GET
// الحقول المطلوبة: providers_users_id (في الـ query string)
// ============================================================

include "../connect.php";

// ============================================================
// الخطوة 1: استقبال ID المستخدم
// ============================================================
$users_id = filterRequest("providers_users_id");

if (empty($users_id)) {
    printFailure("User ID is required");
    exit;
}

// ============================================================
// الخطوة 2: جلب بيانات الملف الشخصي لمقدم الخدمة من قاعدة البيانات
// JOIN مع users لجلب الاسم والهاتف
// ============================================================
$stmt = $con->prepare(
    "SELECT
        pp.providers_id,
        pp.providers_users_id,
        pp.providers_experience,
        pp.providers_bio,
        pp.providers_age,
        pp.providers_gender,
        pp.providers_nationality,
        pp.providers_profile_image,
        pp.providers_current_country,
        pp.providers_current_governorate,
        pp.providers_current_area,
        pp.providers_current_address,
        pp.providers_rating,
        pp.providers_is_verified,
        pp.providers_review_note,
        -- ⚠️ الأمان: لا نُرجع صور البطاقات أو الفيش والتشبيه او ايصال المرافق للتطبيق
        -- providers_id_card_front, providers_id_card_back,
        -- providers_criminal_record_image,providers_utility_receipt
        u.users_full_name,
        u.users_phone,
        u.users_approve,
        u.users_type
     FROM professional_profiles pp
     INNER JOIN users u ON u.users_id = pp.providers_users_id
     WHERE pp.providers_users_id = ?
     LIMIT 1"
);
$stmt->execute([$users_id]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 3: التحقق من وجود الملف الشخصي
// ============================================================
if (!$profile) {
    // لا يوجد ملف شخصي بعد
    echo json_encode([
        "status"  => "success",
        "message" => "Profile not found",
        "data"    => null,
        // هذا يخبر Flutter أن المستخدم لم يكمل بياناته بعد
        "profile_completed" => false,
    ]);
    exit;
}

// ============================================================
// الخطوة 4: بناء رابط الصورة الشخصية
// نُضيف baseUrl قبل اسم الصورة
// ============================================================
// $baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') .
//            '://' . $_SERVER['HTTP_HOST'] .
//            str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME']);

// الرجوع للمجلد الرئيسي للمشروع تجنباً لتكرار complete_provider_profile/
$scriptDir = dirname(dirname($_SERVER['SCRIPT_NAME']));
$baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . ($scriptDir === '/' || $scriptDir === '\\' ? '' : $scriptDir);
$baseUrl = rtrim($baseUrl, '/');

// رابط الصورة الشخصية فقط (الآمنة للعرض)
$profileImageUrl = $profile['providers_profile_image']
    ? $baseUrl . '/upload/provider_profiles/' . $profile['providers_profile_image']
    // ? $baseUrl . '/upload/profiles/' . $profile['providers_profile_image']
    : null;

// ============================================================
// الخطوة 5: تحديد حالة المراجعة بشكل واضح لـ Flutter
// ============================================================
$verificationStatus = match((int)$profile['providers_is_verified']) {
    0 => 'pending',   // في انتظار المراجعة
    1 => 'verified',  // تم التحقق والموافقة
    2 => 'rejected',  // مرفوض (يمكن إضافة هذه الحالة لاحقاً)
    default => 'pending',
};

// ============================================================
// الخطوة 6: إرسال الرد
// ============================================================
echo json_encode([
    "status"  => "success",
    "message" => "Profile fetched successfully",
    "profile_completed" => true,
    "data"    => [
        "providers_id"            => (int)$profile['providers_id'],
        "providers_users_id"      => (int)$profile['providers_users_id'],
        "users_full_name"         => $profile['users_full_name'],
        "providers_experience"    => $profile['providers_experience'],
        "providers_bio"           => $profile['providers_bio'],
        "providers_age"           => (int)$profile['providers_age'],
        "providers_gender"        => $profile['providers_gender'],
        "providers_nationality"   => $profile['providers_nationality'],
        "providers_current_country" => $profile['providers_current_country'],
        "providers_current_governorate" => $profile['providers_current_governorate'],
        "providers_current_area" => $profile['providers_current_area'], 
        "providers_current_address" => $profile['providers_current_address'],
        "providers_profile_image" => $profileImageUrl,
        "providers_rating"        => (float)$profile['providers_rating'],
        // حالة التحقق: pending / verified / rejected
        "verification_status"     => $verificationStatus,
        "providers_is_verified"   => (int)$profile['providers_is_verified'],
        // ملاحظة الإدارة عند طلب إعادة رفع مستند أو تصحيح بيان
        "providers_review_note"   => $profile['providers_review_note'],
        // حالة الموافقة على الحساب من قبل الأدمن
        "users_approve"           => (int)$profile['users_approve'],
    ],
]);
?>