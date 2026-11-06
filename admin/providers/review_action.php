<?php
// ============================================================
// ملف: admin/providers/review_action.php
// الغرض: تنفيذ قرار المراجعة على طلب مقدم الخدمة
//        قبول / رفض / إعادة للتعديل + تعديل البيانات
//
// ⭐ الإجراءات المتاحة (action):
//   approve → قبول: providers_is_verified=1 و users_approve=1
//   reject  → رفض:  providers_is_verified=2 و users_approve=0 (سبب إجباري)
//   pending → إعادة للانتظار: providers_is_verified=0
//   update  → تعديل بيانات مقدم الخدمة (الدرجة "ج")
//
// ⚠️ الدرجة المختارة حالياً: "ج" — خدمة العملاء تعدّل كل شيء
//    وكود الدرجة "أ" (قراءة + قرار فقط بلا تعديل) موجود
//    في الخطوة 6 معلَّقاً بـ // لتفعيله متى شئت
//
// الحقول الإجبارية: providers_users_id, action, admin_id
// الطريقة: POST
// ============================================================

// ربط ملف الاتصال بقاعدة البيانات (مستويان للوراء لأن الملف داخل admin/providers)
include "../../connect.php";
// تضمين دوال أحداث الإشعارات بعد موافقة الإدارة على مقدم الخدمة
include "../../includes/notification_hooks.php";

// ⭐ حماية ملف الإدارة: يجب إرسال المفتاح السري في هيدر X-Admin-Key
// ⚠️ لو أردت تعطيل الحماية مؤقتاً أثناء التطوير ضع // قبل السطر التالي فقط
// checkAdminKey();
// ⭐ التحقق من توكن الإدارة وجلب بيانات الموظف الحالي
$currentAdmin = checkAdminToken();

// ============================================================
// الخطوة 1: استقبال البيانات القادمة من لوحة التحكم
// ============================================================
// معرّف مقدم الخدمة المراد تنفيذ القرار عليه - إجباري
$users_id = filterRequest("providers_users_id");
// الإجراء المطلوب: approve / reject / pending / update - إجباري
$action = filterRequest("action");

// الحماية الحقيقية على السيرفر وليست في واجهة التطبيق فقط
if ($action === 'update') {
    requirePermission($currentAdmin, 'providers.edit');
} else {
    requirePermission($currentAdmin, 'providers.approve');
}

// معرّف الموظف الذي ينفّذ القرار (للتسجيل في سجل المراجعة) - إجباري
// $admin_id    = filterRequest("admin_id");
// ⚠️ لا نستقبل admin_id من الطلب إطلاقاً
// بل نأخذه من التوكن حتى لا ينتحل موظف هوية موظف آخر
$admin_id = (int) $currentAdmin['admin_id'];

// سبب الرفض أو ملاحظة المراجع (إجباري في حالة الرفض)
$review_note = trim(filterRequest("review_note"));

// ============================================================
// الخطوة 2: التحقق من الحقول الإجبارية الثلاثة
// ============================================================
// إذا نقص أي حقل نوقف التنفيذ فوراً
if (empty($users_id) || empty($action)) {
    // رسالة توضح الحقول المطلوبة
    printFailure("Required fields: providers_users_id, action, admin_id");
    // إيقاف التنفيذ
    exit;
}

// تحويل المعرّفات لأرقام صحيحة لحماية جملة WHERE من أي محتوى غير رقمي
$users_id = (int) $users_id;
$admin_id = (int) $admin_id;

// ============================================================
// الخطوة 3: التحقق من صحة الإجراء المطلوب
// ============================================================
// قائمة الإجراءات المسموحة
$allowedActions = ['approve', 'reject', 'pending', 'update'];

// إذا كان الإجراء غير معروف نرفض الطلب
if (!in_array($action, $allowedActions, true)) {
    // رسالة توضح الإجراءات المسموحة
    printFailure("action must be one of: " . implode(', ', $allowedActions));
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 4: التحقق من الموظف المنفّذ وصلاحيته
// ============================================================
// ⭐ بيانات الموظف جاءت موثّقة من التوكن فلا نحتاج استعلاماً
$admin = $currentAdmin;

/*
$stmtAdmin = $con->prepare(
    "SELECT admin_id, admin_full_name, admin_role
     FROM admin_users
     WHERE admin_id = ? AND admin_is_active = 1
     LIMIT 1"
);
// تنفيذ الاستعلام بتمرير معرّف الموظف
$stmtAdmin->execute([$admin_id]);
// جلب بيانات الموظف
$admin = $stmtAdmin->fetch(PDO::FETCH_ASSOC);

// إذا لم يوجد الموظف أو حسابه معطّل نرفض الطلب
if (!$admin) {
    // رسالة توضح أن الموظف غير مصرّح له
    printFailure("Admin user not found or inactive", 403);
    // إيقاف التنفيذ
    exit;
}
*/


// ============================================================
// الخطوة 5: التحقق من وجود ملف مقدم الخدمة وجلب حالته الحالية
// ============================================================
$stmtCheck = $con->prepare(
    "SELECT
        pp.providers_id,
        pp.providers_is_verified,
        pp.providers_profile_image,
        pp.providers_id_card_front,
        pp.providers_id_card_back,
        pp.providers_criminal_record_image,
        pp.providers_utility_receipt_image,
        u.users_full_name,
        u.users_approve
     FROM professional_profiles pp
     INNER JOIN users u ON u.users_id = pp.providers_users_id
     WHERE pp.providers_users_id = ?
     LIMIT 1"
);
// تنفيذ الاستعلام بتمرير معرّف مقدم الخدمة
$stmtCheck->execute([$users_id]);
// جلب الصف الحالي
$current = $stmtCheck->fetch(PDO::FETCH_ASSOC);

// إذا لم يوجد الملف نرفض تنفيذ القرار
if (!$current) {
    // رسالة توضح أن الملف غير موجود
    printFailure("Provider profile not found", 404);
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 6: استقبال بيانات التعديل (الدرجة "ج" المُفعَّلة حالياً)
//
// ⚠️⚠️ للتحويل إلى الدرجة "أ" (قراءة + قرار فقط بلا تعديل):
//     أزل التعليق عن السطرين التاليين وستُرفَض أي محاولة تعديل
//
// if ($action === 'update') {
//     printFailure("Editing provider data is not allowed. Reject with a reason instead.", 403);
//     exit;
// }
// ============================================================

// الحقول النصية القابلة للتعديل من قبل خدمة العملاء
$experience = filterRequest("providers_experience");
$bio = filterRequest("providers_bio");
$age = filterRequest("providers_age");
$gender = filterRequest("providers_gender");
$nationality = filterRequest("providers_nationality");
$country = filterRequest("providers_current_country");
$governorate = filterRequest("providers_current_governorate");
$area = filterRequest("providers_current_area");
$address = filterRequest("providers_current_address");
// بيانات جدول users القابلة للتعديل
$fullName = filterRequest("users_full_name");
$phone = filterRequest("users_phone");

// ============================================================
// الخطوة 7: بناء بيانات تحديث جدول professional_profiles
// نُضيف الحقل فقط إذا أرسلته لوحة التحكم فعلاً
// ============================================================
// تهيئة مصفوفة فارغة سنملأها بالحقول المُرسَلة
$updateProfile = [];

// إضافة سنوات الخبرة إن أُرسلت
if ($experience !== '')
    $updateProfile["providers_experience"] = (int) $experience;
// إضافة النبذة إن أُرسلت (نقبل الفراغ لأنها اختيارية)
if ($bio !== '')
    $updateProfile["providers_bio"] = $bio;
// إضافة السن إن أُرسل
if ($age !== '')
    $updateProfile["providers_age"] = (int) $age;
// إضافة النوع إن أُرسل
if ($gender !== '')
    $updateProfile["providers_gender"] = $gender;
// إضافة الجنسية إن أُرسلت
if ($nationality !== '')
    $updateProfile["providers_nationality"] = $nationality;
// إضافة الدولة الحالية إن أُرسلت
if ($country !== '')
    $updateProfile["providers_current_country"] = $country;
// إضافة المحافظة الحالية إن أُرسلت
if ($governorate !== '')
    $updateProfile["providers_current_governorate"] = $governorate;
// إضافة المنطقة الحالية إن أُرسلت
if ($area !== '')
    $updateProfile["providers_current_area"] = $area;
// إضافة العنوان التفصيلي إن أُرسل
if ($address !== '')
    $updateProfile["providers_current_address"] = $address;

// ============================================================
// الخطوة 8: رفع الصور الجديدة (اختيارية — الدرجة "ج")
// نرفع فقط إذا أرسلت لوحة التحكم صورة جديدة
// ونحذف القديمة بعد نجاح الرفع لتوفير مساحة السيرفر
// ============================================================
// مسار مجلد الرفع (نرجع مستويين للوراء للوصول لجذر المشروع)
$uploadDir = __DIR__ . "/../../upload/";

// ---------- الصورة الشخصية ----------
// نتحقق أن الملف أُرسل فعلاً وليس حقلاً فارغاً
if (
    isset($_FILES['profile_image']) &&
    $_FILES['profile_image']['error'] !== UPLOAD_ERR_NO_FILE
) {
    // رفع الصورة الجديدة باستخدام دالتك الموحدة
    $newProfileImage = imageUploadTwo($uploadDir . "provider_profiles/", "profile_image");
    // نتابع فقط إذا نجح الرفع
    if (!in_array($newProfileImage, ["fail", "empty", "extension_error", "size_error"])) {
        // حذف الصورة القديمة إن وُجدت
        if ($current['providers_profile_image']) {
            deleteFile($uploadDir . "provider_profiles/", $current['providers_profile_image']);
        }
        // تخزين اسم الصورة الجديدة للتحديث
        $updateProfile["providers_profile_image"] = $newProfileImage;
    }
}

// ---------- البطاقة الأمامية ----------
if (
    isset($_FILES['id_card_front']) &&
    $_FILES['id_card_front']['error'] !== UPLOAD_ERR_NO_FILE
) {
    // رفع البطاقة الأمامية الجديدة
    $newIdFront = imageUploadTwo($uploadDir . "id_cards/", "id_card_front");
    // نتابع فقط إذا نجح الرفع
    if (!in_array($newIdFront, ["fail", "empty", "extension_error", "size_error"])) {
        // حذف القديمة إن وُجدت
        if ($current['providers_id_card_front']) {
            deleteFile($uploadDir . "id_cards/", $current['providers_id_card_front']);
        }
        // تخزين الاسم الجديد
        $updateProfile["providers_id_card_front"] = $newIdFront;
    }
}

// ---------- البطاقة الخلفية ----------
if (
    isset($_FILES['id_card_back']) &&
    $_FILES['id_card_back']['error'] !== UPLOAD_ERR_NO_FILE
) {
    // رفع البطاقة الخلفية الجديدة
    $newIdBack = imageUploadTwo($uploadDir . "id_cards/", "id_card_back");
    // نتابع فقط إذا نجح الرفع
    if (!in_array($newIdBack, ["fail", "empty", "extension_error", "size_error"])) {
        // حذف القديمة إن وُجدت
        if ($current['providers_id_card_back']) {
            deleteFile($uploadDir . "id_cards/", $current['providers_id_card_back']);
        }
        // تخزين الاسم الجديد
        $updateProfile["providers_id_card_back"] = $newIdBack;
    }
}

// ---------- الصحيفة الجنائية (اختيارية) ----------
if (
    isset($_FILES['criminal_record_image']) &&
    $_FILES['criminal_record_image']['error'] !== UPLOAD_ERR_NO_FILE
) {
    // رفع الصحيفة الجنائية الجديدة
    $newCriminal = imageUploadTwo($uploadDir . "criminal_records/", "criminal_record_image");
    // نتابع فقط إذا نجح الرفع
    if (!in_array($newCriminal, ["fail", "empty", "extension_error", "size_error"])) {
        // حذف القديمة إن وُجدت
        if ($current['providers_criminal_record_image']) {
            deleteFile($uploadDir . "criminal_records/", $current['providers_criminal_record_image']);
        }
        // تخزين الاسم الجديد
        $updateProfile["providers_criminal_record_image"] = $newCriminal;
    }
}

// ---------- إيصال المرافق (اختياري) ----------
if (
    isset($_FILES['utility_receipt_image']) &&
    $_FILES['utility_receipt_image']['error'] !== UPLOAD_ERR_NO_FILE
) {
    // رفع إيصال المرافق الجديد
    $newUtility = imageUploadTwo($uploadDir . "utility_receipt/", "utility_receipt_image");
    // نتابع فقط إذا نجح الرفع
    if (!in_array($newUtility, ["fail", "empty", "extension_error", "size_error"])) {
        // حذف القديم إن وُجد
        if ($current['providers_utility_receipt_image']) {
            deleteFile($uploadDir . "utility_receipt/", $current['providers_utility_receipt_image']);
        }
        // ⚠️ تنبيه: في ملف complete_provider_profile/edit.php السطر 132
        // يوجد خطأ يحفظ إيصال المرافق في عمود الصحيفة الجنائية
        // وهنا نحفظه في عموده الصحيح
        $updateProfile["providers_utility_receipt_image"] = $newUtility;
    }
}

// ============================================================
// الخطوة 9: تحديد قيم حالة المراجعة حسب الإجراء المطلوب
// ============================================================
// تهيئة متغيرات الحالة (null تعني لا تغيير)
$newVerified = null;
$newApprove = null;

// نحدد القيم حسب الإجراء المُرسَل
switch ($action) {
    case 'approve':
        // القبول: توثيق مقدم الخدمة والموافقة على حسابه
        $newVerified = 1;
        $newApprove = 1;
        break;

    case 'reject':
        // الرفض: سبب الرفض إجباري حتى يعرف مقدم الخدمة ماذا يصحّح
        if ($review_note === '') {
            // رسالة توضح أن السبب مطلوب
            printFailure("review_note is required when rejecting a provider");
            // إيقاف التنفيذ
            exit;
        }
        // الرفض: الحالة 2 وإلغاء الموافقة على الحساب
        $newVerified = 2;
        $newApprove = 0;
        break;

    case 'pending':
        // إعادة الطلب لقائمة الانتظار (مثلاً بعد تعديل مقدم الخدمة لبياناته)
        $newVerified = 0;
        $newApprove = 0;
        break;

    case 'update':
        // التعديل فقط بدون تغيير حالة المراجعة
        // نتحقق أن هناك ما نُحدّثه فعلاً
        if (empty($updateProfile) && $fullName === '' && $phone === '') {
            // رسالة توضح عدم إرسال أي حقل
            printFailure("No fields to update");
            // إيقاف التنفيذ
            exit;
        }
        break;
}

// ============================================================
// الخطوة 10: إضافة بيانات سجل المراجعة
// (من راجع ومتى وما ملاحظته) — تُسجَّل في كل الإجراءات
// ============================================================
// نُضيف بيانات المراجعة فقط إذا كان الإجراء قراراً (وليس تعديلاً مجرداً)
if ($action !== 'update') {
    // تحديث حالة التوثيق
    $updateProfile["providers_is_verified"] = $newVerified;
    // تسجيل معرّف الموظف الذي راجع
    $updateProfile["providers_reviewed_by"] = $admin_id;
    // تسجيل تاريخ ووقت المراجعة
    $updateProfile["providers_reviewed_at"] = date("Y-m-d H:i:s");
    // تسجيل الملاحظة أو سبب الرفض (نص فارغ يُحوَّل لـ null)
    $updateProfile["providers_review_note"] = $review_note !== '' ? $review_note : null;
} elseif ($review_note !== '') {
    // في حالة التعديل نُسجّل الملاحظة فقط إن أُرسلت
    $updateProfile["providers_review_note"] = $review_note;
    // ونُسجّل من عدّل ومتى
    $updateProfile["providers_reviewed_by"] = $admin_id;
    $updateProfile["providers_reviewed_at"] = date("Y-m-d H:i:s");
}

// ============================================================
// الخطوة 11: تنفيذ التحديث داخل Transaction
//   السبب: نُحدّث جدولين (professional_profiles و users)
//   ولو نجح أحدهما وفشل الآخر ستفقد قاعدة البيانات تناسقها
// ============================================================
// بدء المعاملة لضمان تنفيذ كل التحديثات معاً أو إلغائها معاً
$con->beginTransaction();

// نستخدم try/catch لضمان التراجع عند أي خطأ غير متوقع
try {
    // ---------- تحديث جدول professional_profiles ----------
    // نُنفّذ فقط إذا كان هناك حقول للتحديث
    if (!empty($updateProfile)) {
        // استدعاء دالتك الموحدة مع false لمنع طباعة JSON مرتين
        updateData(
            "professional_profiles",
            $updateProfile,
            "providers_users_id = $users_id",
            false
        );
    }

    // ---------- تحديث جدول users ----------
    // تهيئة مصفوفة تحديث جدول المستخدمين
    $updateUser = [];

    // إضافة حالة الموافقة إذا كان الإجراء قراراً
    if ($newApprove !== null) {
        $updateUser["users_approve"] = $newApprove;
    }
    // إضافة الاسم الكامل إن أُرسل (الدرجة "ج")
    if ($fullName !== '') {
        $updateUser["users_full_name"] = $fullName;
    }
    // إضافة رقم الهاتف إن أُرسل (الدرجة "ج")
    if ($phone !== '') {
        // ⚠️ رقم الهاتف عليه قيد UNIQUE، فنتحقق أنه غير مستخدم لمستخدم آخر
        $stmtPhone = $con->prepare(
            "SELECT users_id FROM users WHERE users_phone = ? AND users_id <> ? LIMIT 1"
        );
        // تنفيذ الاستعلام بتمرير الهاتف والمعرّف الحالي
        $stmtPhone->execute([$phone, $users_id]);
        // إذا وُجد مستخدم آخر بنفس الرقم نرفض التعديل
        if ($stmtPhone->rowCount() > 0) {
            // إلغاء كل ما حدث في المعاملة
            $con->rollBack();
            // رسالة توضح أن الرقم مستخدم
            printFailure("This phone number is already used by another user", 409);
            // إيقاف التنفيذ
            exit;
        }
        // تخزين الرقم الجديد للتحديث
        $updateUser["users_phone"] = $phone;
    }

    // تنفيذ تحديث جدول users إذا كان هناك ما نُحدّثه
    if (!empty($updateUser)) {
        // استدعاء دالتك الموحدة مع false لمنع طباعة JSON مرتين
        updateData("users", $updateUser, "users_id = $users_id", false);
    }

    // تثبيت المعاملة نهائياً في قاعدة البيانات
    $con->commit();

    // إشعار مقدم الخدمة بالموافقة بعد نجاح المعاملة فقط
    if ($action === 'approve') {
        try {
            // دالة الإشعار المخصّصة إن وُجدت في notification_hooks
            if (function_exists('notifyProviderApproved')) {
                notifyProviderApproved([
                    "users_id" => $users_id,
                    "users_full_name" => $current['users_full_name'] ?? '',
                ]);
            } elseif (function_exists('insertNotify')) {
                // حفظ إشعار الموافقة في جدول notification
                insertNotify(
                    'تمت الموافقة على بياناتك',
                    'وافقت الإدارة على بياناتك. يمكنك الدخول إلى الصفحة الرئيسية',
                    $users_id
                );
            }
        } catch (Throwable $e) {
            // لا نوقف قرار الموافقة إذا فشل الإشعار
        }
    }

    // إشعار مقدم الخدمة بطلب إعادة رفع المستندات بعد الرفض
    if ($action === 'reject') {
        try {
            // دالة الإشعار المخصّصة إن وُجدت
            if (function_exists('notifyProviderRejected')) {
                notifyProviderRejected([
                    "users_id" => $users_id,
                    "users_full_name" => $current['users_full_name'] ?? '',
                    "review_note" => $review_note,
                ]);
            } elseif (function_exists('insertNotify')) {
                // نص الإشعار يشمل سبب الإدارة ليظهر في صندوق الوارد
                $rejectBody = $review_note !== ''
                    ? $review_note
                    : 'يرجى تعديل بياناتك أو إعادة رفع المستندات ثم حفظ التعديلات';
                // حفظ إشعار طلب التعديل في جدول notification
                insertNotify(
                    'مطلوب تعديل بياناتك',
                    $rejectBody,
                    $users_id
                );
            }
        } catch (Throwable $e) {
            // لا نوقف قرار الرفض إذا فشل الإشعار
        }
    }

} catch (Exception $e) {
    // في حال وجود معاملة مفتوحة نتراجع عنها لحماية البيانات
    if ($con->inTransaction()) {
        // إلغاء كل ما حدث في المعاملة
        $con->rollBack();
    }
    // إرسال رسالة خطأ السيرفر مع سبب الاستثناء
    printFailure("Failed to apply review action", 500, ["error" => $e->getMessage()]);
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 12: جلب الحالة النهائية بعد التنفيذ لإرجاعها
// ============================================================
$stmtAfter = $con->prepare(
    "SELECT
        pp.providers_is_verified,
        pp.providers_review_note,
        pp.providers_reviewed_at,
        u.users_full_name,
        u.users_approve
     FROM professional_profiles pp
     INNER JOIN users u ON u.users_id = pp.providers_users_id
     WHERE pp.providers_users_id = ?
     LIMIT 1"
);
// تنفيذ الاستعلام بتمرير معرّف مقدم الخدمة
$stmtAfter->execute([$users_id]);
// جلب الصف المحدَّث
$updated = $stmtAfter->fetch(PDO::FETCH_ASSOC);

// ترجمة الحالة النهائية لنص واضح
$finalStatus = match ((int) $updated['providers_is_verified']) {
    0 => 'pending',
    1 => 'verified',
    2 => 'rejected',
    default => 'pending',
};

// ============================================================
// الخطوة 13: تجهيز رسالة عربية واضحة حسب الإجراء
// ============================================================
$actionMessage = match ($action) {
    'approve' => 'تم قبول مقدم الخدمة وتوثيق حسابه بنجاح',
    'reject' => 'تم رفض الطلب وإرسال السبب لمقدم الخدمة',
    'pending' => 'تم إرجاع الطلب لقائمة الانتظار',
    'update' => 'تم تحديث بيانات مقدم الخدمة بنجاح',
    default => 'تم تنفيذ الإجراء',
};

// ============================================================
// الخطوة 14: إرسال الرد النهائي
// ============================================================
echo json_encode([
    // حالة العملية
    "status" => "success",
    // رسالة نجاح بالعربية لعرضها مباشرة في لوحة التحكم
    "message" => $actionMessage,
    // بيانات النتيجة
    "data" => [
        // معرّف مقدم الخدمة
        "providers_users_id" => $users_id,
        // اسم مقدم الخدمة
        "users_full_name" => $updated['users_full_name'],
        // الإجراء المُنفَّذ
        "action" => $action,
        // الحالة النهائية كنص
        "verification_status" => $finalStatus,
        // الحالة النهائية كرقم
        "providers_is_verified" => (int) $updated['providers_is_verified'],
        // حالة الموافقة على الحساب
        "users_approve" => (int) $updated['users_approve'],
        // سبب الرفض أو الملاحظة
        "review_note" => $updated['providers_review_note'],
        // تاريخ ووقت المراجعة
        "reviewed_at" => $updated['providers_reviewed_at'],
        // بيانات الموظف المنفّذ
        "reviewed_by" => [
            "admin_id" => (int) $admin['admin_id'],
            "admin_name" => $admin['admin_full_name'],
            "admin_role" => $admin['admin_role'],
        ],
        // أسماء الحقول التي تم تعديلها فعلاً
        "updated_fields" => array_keys($updateProfile),
    ],
]);
?>