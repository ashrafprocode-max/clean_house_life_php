<?php
// ============================================================
// ملف: admin/companies/edit_company_registering.php
// الغرض: تعديل كل بيانات الشركة أو المصنع أو النشاط التجاري
//        في جدول company_registering من لوحة التحكم الإدارية
// الطريقة: POST (multipart/form-data لأن فيه ملفات PDF)
//
// الحقل الإجباري الوحيد: company_id
// وكل الحقول الأخرى اختيارية — نُحدّث ما أُرسل فقط
//
// ⭐ لماذا التحديث الجزئي؟
//    لأن الموظف قد يريد تصحيح رقم الهاتف وحده
//    ولو أرسلنا كل الحقول لأصبح أي حقل نسيه الموظف = مسح للبيانات
//
// ⚠️ الدرجة "ج" مُفعَّلة: خدمة العملاء تعدّل كل شيء بما فيه الملفات
//    وكود الدرجة "أ" (المدير فقط) موجود في الخطوة 2 معلَّقاً
// ============================================================

// تضمين ملف الاتصال (ثلاثة مستويات للوراء لأن الملف داخل admin/companies)
include "../../connect.php";

// ============================================================
// الخطوة 1: التحقق من توكن الإدارة وجلب بيانات الموظف
//
// ⭐ التوكن يحمل هوية الموظف فلا نستقبل admin_id من الطلب
//    وإلا استطاع موظف انتحال هوية موظف آخر في سجل التعديل
// ============================================================
// التحقق من التوكن وإرجاع بيانات الموظف الموثّق
$currentAdmin = checkAdminToken();

// الحماية الحقيقية على السيرفر وليست في واجهة التطبيق فقط
requirePermission($currentAdmin, 'companies.edit');

// ⚠️ الخيار البديل (غير مُفعَّل): قصر التعديل على المدير فقط
// ولتفعيله ضع // قبل السطر أعلاه وأزل التعليق عن السطر التالي
// $currentAdmin = checkAdminToken('manager');

// ============================================================
// الخطوة 2: استقبال معرّف الشركة والتحقق منه
// ============================================================
// معرّف الشركة المراد تعديلها - إجباري
$company_id = filterRequest("company_id");

// إذا لم يُرسل المعرّف نوقف التنفيذ فوراً
if (empty($company_id)) {
    // رسالة توضح الحقل المطلوب
    printFailure("الحقل company_id مطلوب");
    // إيقاف التنفيذ
    exit;
}

// تحويل المعرّف لرقم صحيح لحماية جملة WHERE من أي محتوى غير رقمي
$company_id = (int) $company_id;

// ============================================================
// الخطوة 3: جلب بيانات الشركة الحالية
//
// ⭐ نحتاجها لسببين:
//    1. التأكد أن الشركة موجودة قبل التعديل
//    2. معرفة أسماء الملفات القديمة لحذفها بعد رفع الجديدة
// ============================================================
// تجهيز استعلام محمي لجلب الصف الحالي
$stmtCurrent = $con->prepare(
    "SELECT
        company_id,
        company_user_id,
        company_business_type,
        company_name,
        company_headquarters_address,
        company_phone_number,
        company_secondary_phone,
        company_commercial_register_pdf,
        company_tax_card_pdf
     FROM company_registering
     WHERE company_id = ?
     LIMIT 1"
);
// تنفيذ الاستعلام بتمرير معرّف الشركة
$stmtCurrent->execute([$company_id]);
// جلب الصف الحالي
$current = $stmtCurrent->fetch(PDO::FETCH_ASSOC);

// إذا لم توجد الشركة نرفض التعديل
if (!$current) {
    // رسالة توضح أن الشركة غير موجودة
    printFailure("الشركة غير موجودة", 404);
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 4: استقبال الحقول القابلة للتعديل (كلها اختيارية)
// ============================================================
// نوع النشاط الجديد
$business_type = filterRequest("company_business_type");
// اسم النشاط الجديد
$name = filterRequest("company_name");
// عنوان المقر الجديد
$address = filterRequest("company_headquarters_address");
// رقم الهاتف الأساسي الجديد
$phone = filterRequest("company_phone_number");
// رقم الهاتف الإضافي الجديد
$secondPhone = filterRequest("company_secondary_phone");

// ============================================================
// الخطوة 5: بناء مصفوفة التحديث بالحقول المُرسَلة فقط
// ============================================================
// تهيئة مصفوفة فارغة سنملأها بما أرسله الموظف
$updateData = [];

// ---------- نوع النشاط ----------
// نتحقق فقط إذا أُرسل الحقل
if ($business_type !== '') {
    // قائمة أنواع النشاط المسموحة (نفس قيم الـ ENUM حرفياً)
    $allowedBusinessTypes = ['company', 'factory', 'commercial_activity'];

    // إذا كان النوع غير معروف نرفض الطلب
    if (!in_array($business_type, $allowedBusinessTypes, true)) {
        // رسالة توضح القيم المسموحة
        printFailure("نوع النشاط غير صحيح، القيم المسموحة: " . implode(', ', $allowedBusinessTypes));
        // إيقاف التنفيذ
        exit;
    }
    // إضافة نوع النشاط للتحديث
    $updateData["company_business_type"] = $business_type;
}

// ---------- اسم النشاط ----------
// إضافة الاسم إن أُرسل
if ($name !== '') {
    $updateData["company_name"] = $name;
}

// ---------- عنوان المقر ----------
// إضافة العنوان إن أُرسل
if ($address !== '') {
    $updateData["company_headquarters_address"] = $address;
}

// ---------- رقم الهاتف الأساسي ----------
// إضافة الهاتف إن أُرسل
if ($phone !== '') {
    $updateData["company_phone_number"] = $phone;
}

// ---------- رقم الهاتف الإضافي ----------
// إضافة الهاتف الإضافي إن أُرسل
if ($secondPhone !== '') {
    $updateData["company_secondary_phone"] = $secondPhone;
}

// ============================================================
// الخطوة 6: رفع الملفات الجديدة (اختيارية)
//
// ⭐ نرفع الجديد أولاً ثم نحذف القديم بعد نجاح الرفع
//    وليس العكس، حتى لا نفقد الملف القديم لو فشل الرفع
// ============================================================
// مسار مجلد الرفع (نرجع مستويين للوراء للوصول لجذر المشروع)
$uploadDir = __DIR__ . "/../../upload/";

// ---------- ملف السجل التجاري ----------
// نتحقق أن الملف أُرسل فعلاً وليس حقلاً فارغاً
if (
    isset($_FILES['commercial_register_pdf']) &&
    $_FILES['commercial_register_pdf']['error'] !== UPLOAD_ERR_NO_FILE
) {

    // استخراج امتداد الملف الأصلي وتحويله لحروف صغيرة
    $crExt = strtolower(pathinfo($_FILES['commercial_register_pdf']['name'], PATHINFO_EXTENSION));

    // ⭐ نقبل PDF فقط للسجل التجاري
    if ($crExt !== 'pdf') {
        // رسالة توضح الصيغة المطلوبة
        printFailure("ملف السجل التجاري يجب أن يكون بصيغة PDF فقط");
        // إيقاف التنفيذ
        exit;
    }

    // رفع الملف الجديد باستخدام دالتك الموحدة
    $newCommercialRegister = imageUploadTwo($uploadDir . "commercial_registers/", "commercial_register_pdf");

    // نتابع فقط إذا نجح الرفع
    if (!in_array($newCommercialRegister, ["fail", "empty", "extension_error", "size_error"], true)) {
        // حذف الملف القديم إن وُجد لتوفير مساحة السيرفر
        if ($current['company_commercial_register_pdf']) {
            deleteFile($uploadDir . "commercial_registers/", $current['company_commercial_register_pdf']);
        }
        // تخزين اسم الملف الجديد للتحديث
        $updateData["company_commercial_register_pdf"] = $newCommercialRegister;
    } else {
        // رسالة توضح فشل الرفع
        printFailure("فشل رفع ملف السجل التجاري، تأكد أن حجمه أقل من 10 ميجا");
        // إيقاف التنفيذ
        exit;
    }
}

// ---------- ملف البطاقة الضريبية ----------
// نتحقق أن الملف أُرسل فعلاً وليس حقلاً فارغاً
if (
    isset($_FILES['tax_card_pdf']) &&
    $_FILES['tax_card_pdf']['error'] !== UPLOAD_ERR_NO_FILE
) {

    // استخراج امتداد الملف الأصلي وتحويله لحروف صغيرة
    $tcExt = strtolower(pathinfo($_FILES['tax_card_pdf']['name'], PATHINFO_EXTENSION));

    // ⭐ نقبل PDF فقط للبطاقة الضريبية
    if ($tcExt !== 'pdf') {
        // رسالة توضح الصيغة المطلوبة
        printFailure("ملف البطاقة الضريبية يجب أن يكون بصيغة PDF فقط");
        // إيقاف التنفيذ
        exit;
    }

    // رفع الملف الجديد باستخدام دالتك الموحدة
    $newTaxCard = imageUploadTwo($uploadDir . "tax_cards/", "tax_card_pdf");

    // نتابع فقط إذا نجح الرفع
    if (!in_array($newTaxCard, ["fail", "empty", "extension_error", "size_error"], true)) {
        // حذف الملف القديم إن وُجد
        if ($current['company_tax_card_pdf']) {
            deleteFile($uploadDir . "tax_cards/", $current['company_tax_card_pdf']);
        }
        // تخزين اسم الملف الجديد للتحديث
        $updateData["company_tax_card_pdf"] = $newTaxCard;
    } else {
        // رسالة توضح فشل الرفع
        printFailure("فشل رفع ملف البطاقة الضريبية، تأكد أن حجمه أقل من 10 ميجا");
        // إيقاف التنفيذ
        exit;
    }
}

// ============================================================
// الخطوة 7: التحقق من وجود ما نُحدّثه فعلاً
// ============================================================
// إذا لم يُرسل الموظف أي حقل نوقف التنفيذ
if (empty($updateData)) {
    // رسالة توضح عدم إرسال أي حقل
    printFailure("لم يتم إرسال أي حقل للتعديل");
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 8: تنفيذ التحديث في قاعدة البيانات
// ============================================================
// ⚠️ نمرر false لمنع الدالة من طباعة JSON لأننا سنبني رداً مخصصاً
$result = updateData(
    "company_registering",
    $updateData,
    "company_id = $company_id",
    false
);

// ============================================================
// الخطوة 9: جلب البيانات المحدَّثة لإرجاعها
//
// ⚠️ ملاحظة مهمة: updateData تُرجع rowCount
//    و MySQL تُرجع 0 إذا كانت القيم الجديدة مطابقة للقديمة تماماً
//    فلا نعتبر 0 فشلاً، بل نُعيد جلب الصف ونتحقق منه
// ============================================================
// تجهيز استعلام لجلب الصف بعد التعديل مع اسم صاحب الحساب
$stmtAfter = $con->prepare(
    "SELECT
        c.company_id,
        c.company_user_id,
        c.company_business_type,
        c.company_name,
        c.company_headquarters_address,
        c.company_phone_number,
        c.company_secondary_phone,
        c.company_commercial_register_pdf,
        c.company_tax_card_pdf,
        c.company_created_at,
        c.company_updated_at,
        u.users_full_name,
        u.users_phone
     FROM company_registering c
     INNER JOIN users u ON u.users_id = c.company_user_id
     WHERE c.company_id = ?
     LIMIT 1"
);
// تنفيذ الاستعلام بتمرير معرّف الشركة
$stmtAfter->execute([$company_id]);
// جلب الصف المحدَّث
$updated = $stmtAfter->fetch(PDO::FETCH_ASSOC);

// إذا فشل الجلب فهناك خطأ حقيقي في قاعدة البيانات
if (!$updated) {
    // رسالة توضح فشل جلب البيانات
    printFailure("تم التعديل لكن تعذّر جلب البيانات المحدَّثة", 500);
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 10: بناء الرابط الأساسي لملفات المستندات
//
// ⚠️ نستخدم dirname ثلاث مرات للرجوع من admin/companies لجذر المشروع
//    مستوى للملف نفسه، ومستوى لمجلد companies، ومستوى لمجلد admin
// ============================================================
// استخراج مسار جذر المشروع
$scriptDir = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'])));

// بناء الرابط الكامل مع البروتوكول والمضيف
$baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . ($scriptDir === '/' || $scriptDir === '\\' ? '' : $scriptDir);

// إزالة أي شرطة مائلة زائدة من النهاية
$baseUrl = rtrim($baseUrl, '/');

// ============================================================
// الخطوة 11: إرسال الرد النهائي للوحة التحكم
// ============================================================
echo json_encode([
    // حالة العملية
    "status" => "success",
    // رسالة عربية جاهزة للعرض في لوحة التحكم
    "message" => "تم تحديث بيانات النشاط بنجاح",
    // بيانات النتيجة
    "data" => [
        // معرّف الشركة
        "company_id" => (int) $updated['company_id'],
        // معرّف المستخدم صاحب النشاط
        "company_user_id" => (int) $updated['company_user_id'],
        // اسم صاحب الحساب من جدول users
        "owner_full_name" => $updated['users_full_name'],
        // هاتف صاحب الحساب من جدول users
        "owner_phone" => $updated['users_phone'],
        // نوع النشاط كما هو مخزَّن
        "company_business_type" => $updated['company_business_type'],
        // ⭐ ترجمة نوع النشاط للعربية لعرضها مباشرة
        "business_type_ar" => match ($updated['company_business_type']) {
            'company' => 'شركة',
            'factory' => 'مصنع',
            'commercial_activity' => 'نشاط تجاري',
            default => 'غير محدد',
        },
        // اسم النشاط
        "company_name" => $updated['company_name'],
        // عنوان المقر
        "company_headquarters_address" => $updated['company_headquarters_address'],
        // رقم الهاتف الأساسي
        "company_phone_number" => $updated['company_phone_number'],
        // رقم الهاتف الإضافي
        "company_secondary_phone" => $updated['company_secondary_phone'],
        // رابط ملف السجل التجاري كاملاً
        "commercial_register_url" => $updated['company_commercial_register_pdf']
            ? $baseUrl . '/upload/commercial_registers/' . $updated['company_commercial_register_pdf']
            : null,
        // رابط ملف البطاقة الضريبية كاملاً
        "tax_card_url" => $updated['company_tax_card_pdf']
            ? $baseUrl . '/upload/tax_cards/' . $updated['company_tax_card_pdf']
            : null,
        // تاريخ التسجيل
        "company_created_at" => $updated['company_created_at'],
        // تاريخ آخر تحديث
        "company_updated_at" => $updated['company_updated_at'],
        // ⭐ أسماء الحقول التي عُدِّلت فعلاً في هذا الطلب
        "updated_fields" => array_keys($updateData),
        // ⭐ عدد الصفوف المتأثرة (0 يعني أن القيم الجديدة مطابقة للقديمة)
        "affected_rows" => $result,
        // ⭐ بيانات الموظف الذي نفّذ التعديل من التوكن
        "edited_by" => [
            // معرّف الموظف
            "admin_id" => (int) $currentAdmin['admin_id'],
            // اسم الموظف
            "admin_name" => $currentAdmin['admin_full_name'],
            // دور الموظف
            "admin_role" => $currentAdmin['admin_role'],
        ],
    ],
]);
?>