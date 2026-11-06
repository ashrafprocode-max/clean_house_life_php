<?php
// ============================================================
// ملف: complete_provider_profile/edit.php
// الغرض: تعديل بيانات مقدم الخدمة بعد طلب الإدارة إعادة الرفع
// الطريقة: POST (multipart/form-data لأن فيه صور اختيارية)
//
// ⭐ أي تعديل ناجح يُعيد providers_is_verified = 0
//    حتى تعيد الإدارة المراجعة خلال 24 ساعة
// ⭐ الصور اختيارية: نرفع الجديد فقط ونحذف القديم بعد نجاح الرفع
// ============================================================

// ربط ملف الاتصال بقاعدة البيانات والدوال المساعدة
include "../connect.php";

// تضمين دوال الإشعارات إن وُجد الملف حتى لا يتوقف التعديل بدونه
if (file_exists(__DIR__ . "/../includes/notification_hooks.php")) {
    // تحميل دوال إشعار الإدارة ومقدم الخدمة
    include "../includes/notification_hooks.php";
}

// ============================================================
// الخطوة 1: استقبال الحقول النصية من التطبيق
// ============================================================
// معرّف المستخدم صاحب الملف — إجباري
$users_id    = filterRequest("providers_users_id");
// سنوات الخبرة
$experience  = filterRequest("providers_experience");
// النبذة الشخصية (اختياري)
$bio         = filterRequest("providers_bio");
// العمر
$age         = filterRequest("providers_age");
// النوع: ذكر / أنثى
$gender      = filterRequest("providers_gender");
// الجنسية
$nationality = filterRequest("providers_nationality");
// الدولة الحالية
$country     = filterRequest("providers_current_country");
// المحافظة / الإقليم
$governorate = filterRequest("providers_current_governorate");
// المنطقة
$area        = filterRequest("providers_current_area");
// العنوان التفصيلي
$address     = filterRequest("providers_current_address");

// ============================================================
// الخطوة 2: التحقق من المعرّف الإجباري
// ============================================================
// بدون معرّف لا نعرف أي صف نحدّث
if (empty($users_id)) {
    // رسالة توضح الحقل المطلوب
    printFailure("providers_users_id is required");
    // إيقاف التنفيذ
    exit;
}

// تحويل المعرّف لرقم صحيح لحماية جملة WHERE
$users_id = (int) $users_id;

// ============================================================
// الخطوة 3: التحقق من وجود الملف الشخصي أولاً
// لا نسمح بالتعديل إن لم يُكمل مقدم الخدمة بياناته من قبل
// ============================================================
$stmtCheck = $con->prepare(
    "SELECT
        providers_id,
        providers_profile_image,
        providers_id_card_front,
        providers_id_card_back,
        providers_criminal_record_image,
        providers_utility_receipt_image,
        providers_is_verified
     FROM professional_profiles
     WHERE providers_users_id = ?
     LIMIT 1"
);
// تنفيذ الاستعلام بمعرّف المستخدم
$stmtCheck->execute([$users_id]);
// جلب الصف الحالي
$existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

// إن لم يوجد ملف نطلب منه الإكمال أولاً عبر add.php
if (!$existing) {
    // رسالة توضح أن الملف غير موجود
    printFailure("Profile not found. Please add your profile first.");
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 4: جلب اسم المستخدم لإشعار الإدارة بعد الحفظ
// ============================================================
$stmtUser = $con->prepare(
    "SELECT users_id, users_full_name, users_type
     FROM users
     WHERE users_id = ?
     LIMIT 1"
);
// تنفيذ الاستعلام
$stmtUser->execute([$users_id]);
// جلب بيانات الحساب
$user = $stmtUser->fetch(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 5: بناء بيانات التحديث
// نبدأ دائماً بإعادة حالة التوثيق إلى الانتظار
// ============================================================
$updateData = [
    // أي تعديل يعيد الحساب لمراجعة الإدارة
    "providers_is_verified" => 0,
];

// تحديث سنوات الخبرة إن أُرسلت
if ($experience !== '') {
    // نخزّن الخبرة كرقم صحيح
    $updateData["providers_experience"] = (int) $experience;
}
// تحديث العمر إن أُرسل
if ($age !== '') {
    // نخزّن العمر كرقم صحيح
    $updateData["providers_age"] = (int) $age;
}
// تحديث النوع إن أُرسل
if ($gender !== '') {
    // ذكر أو أنثى كما اختاره مقدم الخدمة
    $updateData["providers_gender"] = $gender;
}
// تحديث الجنسية إن أُرسلت
if ($nationality !== '') {
    // نص الجنسية كما هو
    $updateData["providers_nationality"] = $nationality;
}
// تحديث الدولة إن أُرسلت
if ($country !== '') {
    // الدولة الحالية المقيم فيها
    $updateData["providers_current_country"] = $country;
}
// تحديث المحافظة إن أُرسلت
if ($governorate !== '') {
    // المحافظة / الإقليم الحالي
    $updateData["providers_current_governorate"] = $governorate;
}
// تحديث المنطقة إن أُرسلت
if ($area !== '') {
    // المنطقة الحالية
    $updateData["providers_current_area"] = $area;
}
// تحديث العنوان إن أُرسل
if ($address !== '') {
    // العنوان التفصيلي
    $updateData["providers_current_address"] = $address;
}
// النبذة اختيارية ونقبل تفريغها أيضاً إن أُرسل الحقل
if (!is_null($bio) && $bio !== false) {
    // فراغ يُحوَّل إلى null في قاعدة البيانات
    $updateData["providers_bio"] = $bio !== '' ? $bio : null;
}

// ============================================================
// الخطوة 6: رفع الصور الجديدة اختيارياً
// نرفع فقط إذا أرسل التطبيق ملفاً جديداً
// وبعد نجاح الرفع نحذف الملف القديم لتوفير مساحة السيرفر
// ============================================================
// المسار الجذري لمجلد الرفع
$uploadDir = __DIR__ . "/../upload/";

// ---------- الصورة الشخصية ----------
if (isset($_FILES['profile_image']) &&
    $_FILES['profile_image']['error'] !== UPLOAD_ERR_NO_FILE) {
    // رفع الصورة الجديدة
    $newProfileImage = imageUploadTwo($uploadDir . "provider_profiles/", "profile_image");
    // نتابع فقط إذا نجح الرفع ولم يكن خطأ امتداد أو حجم
    if (!in_array($newProfileImage, ["fail", "empty", "extension_error", "size_error"], true)) {
        // حذف الصورة القديمة إن وُجدت
        if (!empty($existing['providers_profile_image'])) {
            // حذف الملف من مجلد الصور الشخصية
            deleteFile($uploadDir . "provider_profiles/", $existing['providers_profile_image']);
        }
        // حفظ اسم الملف الجديد في صف التحديث
        $updateData["providers_profile_image"] = $newProfileImage;
    }
}

// ---------- البطاقة الأمامية ----------
if (isset($_FILES['id_card_front']) &&
    $_FILES['id_card_front']['error'] !== UPLOAD_ERR_NO_FILE) {
    // رفع صورة البطاقة الأمامية الجديدة
    $newIdFront = imageUploadTwo($uploadDir . "id_cards/", "id_card_front");
    // نتابع فقط إذا نجح الرفع
    if (!in_array($newIdFront, ["fail", "empty", "extension_error", "size_error"], true)) {
        // حذف القديمة إن وُجدت
        if (!empty($existing['providers_id_card_front'])) {
            // حذف ملف البطاقة الأمامية القديم
            deleteFile($uploadDir . "id_cards/", $existing['providers_id_card_front']);
        }
        // حفظ الاسم الجديد
        $updateData["providers_id_card_front"] = $newIdFront;
    }
}

// ---------- البطاقة الخلفية ----------
if (isset($_FILES['id_card_back']) &&
    $_FILES['id_card_back']['error'] !== UPLOAD_ERR_NO_FILE) {
    // رفع صورة البطاقة الخلفية الجديدة
    $newIdBack = imageUploadTwo($uploadDir . "id_cards/", "id_card_back");
    // نتابع فقط إذا نجح الرفع
    if (!in_array($newIdBack, ["fail", "empty", "extension_error", "size_error"], true)) {
        // حذف القديمة إن وُجدت
        if (!empty($existing['providers_id_card_back'])) {
            // حذف ملف البطاقة الخلفية القديم
            deleteFile($uploadDir . "id_cards/", $existing['providers_id_card_back']);
        }
        // حفظ الاسم الجديد
        $updateData["providers_id_card_back"] = $newIdBack;
    }
}

// ---------- الصحيفة الجنائية (اختياري) ----------
if (isset($_FILES['criminal_record_image']) &&
    $_FILES['criminal_record_image']['error'] !== UPLOAD_ERR_NO_FILE) {
    // رفع الصحيفة الجنائية الجديدة
    $newCriminal = imageUploadTwo($uploadDir . "criminal_records/", "criminal_record_image");
    // نتابع فقط إذا نجح الرفع
    if (!in_array($newCriminal, ["fail", "empty", "extension_error", "size_error"], true)) {
        // حذف القديمة إن وُجدت
        if (!empty($existing['providers_criminal_record_image'])) {
            // حذف ملف الصحيفة القديم
            deleteFile($uploadDir . "criminal_records/", $existing['providers_criminal_record_image']);
        }
        // حفظ الاسم الجديد
        $updateData["providers_criminal_record_image"] = $newCriminal;
    }
}

// ---------- إيصال المرافق (اختياري) ----------
if (isset($_FILES['utility_receipt_image']) &&
    $_FILES['utility_receipt_image']['error'] !== UPLOAD_ERR_NO_FILE) {
    // رفع إيصال المرافق الجديد
    $newUtility = imageUploadTwo($uploadDir . "utility_receipt/", "utility_receipt_image");
    // نتابع فقط إذا نجح الرفع
    if (!in_array($newUtility, ["fail", "empty", "extension_error", "size_error"], true)) {
        // حذف القديم إن وُجد
        if (!empty($existing['providers_utility_receipt_image'])) {
            // حذف ملف الإيصال القديم
            deleteFile($uploadDir . "utility_receipt/", $existing['providers_utility_receipt_image']);
        }
        // حفظ الاسم في العمود الصحيح وليس عمود الصحيفة
        $updateData["providers_utility_receipt_image"] = $newUtility;
    }
}

// ============================================================
// الخطوة 7: تنفيذ التحديث على جدول professional_profiles
// ============================================================
$result = updateData(
    "professional_profiles",
    $updateData,
    "providers_users_id = $users_id",
    false
);

// ============================================================
// الخطوة 8: إشعار الإدارة بإعادة رفع البيانات بعد نجاح الحفظ
// ============================================================
if ($result > 0) {
    // نحاول إشعار الإدارة دون إيقاف رد النجاح لو فشل الإشعار
    try {
        // إن وُجدت دالة التحديث المخصّصة نستخدمها
        if (function_exists('insertNotify')) {
            // إشعار مقدم الخدمة نفسه بأنه عاد لقائمة انتظار الإدارة
            insertNotify(
                'تم حفظ التعديلات',
                'تم إرسال بياناتك المعدّلة. يرجى انتظار موافقة الإدارة خلال 24 ساعة',
                $users_id
            );
        }
        if (function_exists('notifyProviderProfileUpdated')) {
            // إشعار بأن مقدم الخدمة عدّل بياناته وينتظر إعادة المراجعة
            notifyProviderProfileUpdated([
                "users_id"        => $users_id,
                "users_full_name" => $user['users_full_name'] ?? '',
                "users_type"      => $user['users_type'] ?? 'provider',
            ]);
        } elseif (function_exists('notifyProviderProfileCompleted')) {
            // نعيد استخدام دالة اكتمال الملف إن لم توجد دالة التحديث
            notifyProviderProfileCompleted([
                "users_id"        => $users_id,
                "users_full_name" => $user['users_full_name'] ?? '',
                "users_type"      => $user['users_type'] ?? 'provider',
            ]);
        }
    } catch (Throwable $e) {
        // لا نوقف حفظ الملف إذا فشل الإشعار
    }
}

// ============================================================
// الخطوة 9: إرسال الرد للتطبيق
// ============================================================
if ($result > 0) {
    // نجاح حقيقي مع تغيير في الصف
    echo json_encode([
        "status"  => "success",
        "message" => "تم حفظ التعديلات. يرجى انتظار موافقة الإدارة خلال 24 ساعة",
        "data"    => [
            "providers_users_id"    => $users_id,
            "providers_is_verified" => 0,
            "verification_status"   => "pending",
        ],
    ]);
} else {
    // لم يتغير صف لكن العملية ليست فشلاً من جهة التطبيق
    echo json_encode([
        "status"  => "success",
        "message" => "تم استلام الطلب. يرجى انتظار موافقة الإدارة خلال 24 ساعة",
        "data"    => [
            "providers_users_id"    => $users_id,
            "providers_is_verified" => 0,
            "verification_status"   => "pending",
        ],
    ]);
}