<?php
// ============================================================
// ملف: companies/add_company_registering.php
// الغرض: تسجيل بيانات شركة أو مصنع أو نشاط تجاري في جدول company_registering
// الطريقة: POST (multipart/form-data لأن فيه ملفات PDF)
//
// الحقول الإجبارية:
//   company_user_id                → معرّف المستخدم صاحب النشاط
//   company_business_type          → company أو factory أو commercial_activity
//   company_name                   → اسم الشركة أو المصنع أو النشاط
//   company_headquarters_address    → عنوان المقر
//   company_phone_number           → رقم الهاتف الأساسي
//
// الحقول الاختيارية:
//   company_secondary_phone        → رقم هاتف إضافي
//   commercial_register_pdf        → ملف السجل التجاري (PDF)
//   tax_card_pdf                   → ملف البطاقة الضريبية (PDF)
//
// ⚠️ تنبيه أمني: checkAuthenticate() معطّلة في connect.php
//    فهذا الملف مفتوح لأي شخص يعرف الرابط
//    ويستطيع تسجيل شركة بأي company_user_id
//    الحماية الحقيقية تحتاج تفعيل التوكن (مؤجَّل لما بعد التطوير)
// ============================================================

// تضمين ملف الاتصال بقاعدة البيانات والدوال المساعدة
include "../connect.php";
// تضمين دوال أحداث الإشعارات بعد نجاح تسجيل الشركة
include "../includes/notification_hooks.php";

// checkAuthenticate();

// ============================================================
// الخطوة 1: استقبال البيانات النصية القادمة من التطبيق
// ============================================================
// معرّف المستخدم صاحب الحساب المرتبط بهذا النشاط - إجباري
$user_id       = filterRequest("company_user_id");
// نوع النشاط: شركة أم مصنع أم نشاط تجاري - إجباري
$business_type = filterRequest("company_business_type");
// اسم الشركة أو المصنع أو النشاط التجاري - إجباري
$name          = filterRequest("company_name");
// عنوان مقر الشركة بالتفصيل - إجباري
$address       = filterRequest("company_headquarters_address");
// رقم الهاتف الأساسي للتواصل - إجباري
$phone         = filterRequest("company_phone_number");
// رقم هاتف إضافي آخر - اختياري
$secondPhone   = filterRequest("company_secondary_phone");

// ============================================================
// الخطوة 2: التحقق من الحقول الإجبارية النصية
// ============================================================
// إذا نقص أي حقل إجباري نوقف التنفيذ فوراً
if (empty($user_id) || empty($business_type) || empty($name) ||
    empty($address) || empty($phone)) {
    // رسالة عربية توضح للمستخدم الحقول المطلوبة
    printFailure("يرجى إدخال كل البيانات الإجبارية: نوع النشاط، الاسم، العنوان، رقم الهاتف");
    // إيقاف التنفيذ
    exit;
}

// تحويل معرّف المستخدم لرقم صحيح لحماية جملة WHERE من أي محتوى غير رقمي
$user_id = (int) $user_id;

// ============================================================
// الخطوة 3: التحقق من صحة نوع النشاط
//
// ⚠️ إجباري لأن العمود ENUM في MySQL
//    ولو أرسل التطبيق قيمة خارج القائمة على سيرفر STRICT
//    سيفشل الإدخال برسالة SQL غامضة للمستخدم
// ============================================================
// قائمة أنواع النشاط المسموحة (نفس قيم الـ ENUM حرفياً)
$allowedBusinessTypes = ['company', 'factory', 'commercial_activity'];

// إذا كان النوع غير معروف نرفض الطلب برسالة واضحة
if (!in_array($business_type, $allowedBusinessTypes, true)) {
    // رسالة توضح القيم المسموحة
    printFailure("نوع النشاط غير صحيح، القيم المسموحة: " . implode(', ', $allowedBusinessTypes));
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 4: التحقق من وجود المستخدم وأن حسابه مفعّل
//
// ⚠️ لا نفرض users_type معيّناً لأن الـ ENUM يحتوي client و provider فقط
//    ولا يوجد نوع company، فصاحب الشركة يسجّل كـ client
// ============================================================
// تجهيز استعلام محمي للتحقق من المستخدم
$stmtUser = $con->prepare(
    "SELECT users_id, users_full_name, users_type, users_is_active
     FROM users
     WHERE users_id = ?
     LIMIT 1"
);
// تنفيذ الاستعلام بتمرير معرّف المستخدم
$stmtUser->execute([$user_id]);
// جلب صف المستخدم
$user = $stmtUser->fetch(PDO::FETCH_ASSOC);

// إذا لم يوجد المستخدم نرفض الطلب
if (!$user) {
    // رسالة توضح أن الحساب غير موجود
    printFailure("المستخدم غير موجود", 404);
    // إيقاف التنفيذ
    exit;
}

// إذا كان الحساب معطّلاً نرفض الطلب
if ((int)$user['users_is_active'] !== 1) {
    // رسالة توضح أن الحساب معطّل
    printFailure("هذا الحساب معطّل، يرجى التواصل مع الإدارة", 403);
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 5: التحقق من عدم تسجيل هذا المستخدم لنشاط سابق
//
// ⭐ لماذا؟ لأن العمود company_user_id بلا قيد UNIQUE
//    فبدون هذا الفحص سيُنشئ كل ضغط على الزر شركة جديدة مكرَّرة
//
// ⭐ نُرجع معرّف الشركة الموجودة في الرد
//    حتى يستطيع التطبيق الانتقال مباشرة لصفحة تفاصيل العامل
//    بدلاً من أن يتوقف عند رسالة خطأ بلا مخرج
// ============================================================
// تجهيز استعلام للبحث عن نشاط سابق لنفس المستخدم
$stmtExisting = $con->prepare(
    "SELECT company_id, company_name
     FROM company_registering
     WHERE company_user_id = ?
     LIMIT 1"
);
// تنفيذ الاستعلام بتمرير معرّف المستخدم
$stmtExisting->execute([$user_id]);
// جلب الصف إن وُجد
$existingCompany = $stmtExisting->fetch(PDO::FETCH_ASSOC);

// إذا وُجد نشاط مسجَّل مسبقاً نُرجع معرّفه ولا نُنشئ نشاطاً جديداً
if ($existingCompany) {
    // رد بحالة 409 (تعارض) مع معرّف الشركة الموجودة
    printFailure(
        "لديك نشاط مسجّل بالفعل باسم: " . $existingCompany['company_name'],
        409,
        [
            // ⭐ التطبيق يقرأ هذا المعرّف وينتقل لصفحة تفاصيل العامل
            "company_id"   => (int)$existingCompany['company_id'],
            "company_name" => $existingCompany['company_name'],
        ]
    );
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 6: رفع ملف السجل التجاري (اختياري)
//
// ⚠️ نتحقق من الامتداد قبل الرفع لأن imageUploadTwo
//    تقبل الصور والـ PDF معاً، ونحن نريد PDF فقط
//    لأن اسم العمود company_commercial_register_pdf
// ============================================================
// تهيئة المتغير بـ null (يعني لم يُرفع ملف)
$commercialRegisterName = null;

// نتحقق أن الملف أُرسل فعلاً وليس حقلاً فارغاً
if (isset($_FILES['commercial_register_pdf']) &&
    $_FILES['commercial_register_pdf']['error'] !== UPLOAD_ERR_NO_FILE) {

    // استخراج امتداد الملف الأصلي وتحويله لحروف صغيرة
    $crExt = strtolower(pathinfo($_FILES['commercial_register_pdf']['name'], PATHINFO_EXTENSION));

    // ⭐ نقبل PDF فقط للسجل التجاري
    if ($crExt !== 'pdf') {
        // رسالة توضح الصيغة المطلوبة
        printFailure("ملف السجل التجاري يجب أن يكون بصيغة PDF فقط");
        // إيقاف التنفيذ
        exit;
    }

    // ⚠️ الخيار البديل (غير مُفعَّل): قبول الصور أيضاً لمن يصوّر السجل بالكاميرا
    // ولتفعيله أزل التعليق عن السطرين التاليين واحذف كتلة if أعلاه
    // $allowedDocExt = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
    // if (!in_array($crExt, $allowedDocExt, true)) { printFailure("صيغة ملف السجل التجاري غير مدعومة"); exit; }

    // رفع الملف لمجلد السجلات التجارية باستخدام دالتك الموحدة
    $commercialRegisterName = imageUploadTwo(__DIR__ . "/../upload/commercial_registers/", "commercial_register_pdf");

    // نتحقق من نجاح الرفع
    if (in_array($commercialRegisterName, ["fail", "empty", "extension_error", "size_error"], true)) {
        // رسالة توضح فشل الرفع مع السبب
        printFailure("فشل رفع ملف السجل التجاري، تأكد أن حجمه أقل من 10 ميجا");
        // إيقاف التنفيذ
        exit;
    }
}

// ============================================================
// الخطوة 7: رفع ملف البطاقة الضريبية (اختياري)
// ============================================================
// تهيئة المتغير بـ null (يعني لم يُرفع ملف)
$taxCardName = null;

// نتحقق أن الملف أُرسل فعلاً وليس حقلاً فارغاً
if (isset($_FILES['tax_card_pdf']) &&
    $_FILES['tax_card_pdf']['error'] !== UPLOAD_ERR_NO_FILE) {

    // استخراج امتداد الملف الأصلي وتحويله لحروف صغيرة
    $tcExt = strtolower(pathinfo($_FILES['tax_card_pdf']['name'], PATHINFO_EXTENSION));

    // ⭐ نقبل PDF فقط للبطاقة الضريبية
    if ($tcExt !== 'pdf') {
        // ⚠️ حذف السجل التجاري المرفوع قبل الخروج حتى لا يبقى ملفاً معلَّقاً
        if ($commercialRegisterName) {
            deleteFile(__DIR__ . "/../upload/commercial_registers/", $commercialRegisterName);
        }
        // رسالة توضح الصيغة المطلوبة
        printFailure("ملف البطاقة الضريبية يجب أن يكون بصيغة PDF فقط");
        // إيقاف التنفيذ
        exit;
    }

    // رفع الملف لمجلد البطاقات الضريبية باستخدام دالتك الموحدة
    $taxCardName = imageUploadTwo(__DIR__ . "/../upload/tax_cards/", "tax_card_pdf");

    // نتحقق من نجاح الرفع
    if (in_array($taxCardName, ["fail", "empty", "extension_error", "size_error"], true)) {
        // ⚠️ حذف السجل التجاري المرفوع قبل الخروج
        if ($commercialRegisterName) {
            deleteFile(__DIR__ . "/../upload/commercial_registers/", $commercialRegisterName);
        }
        // رسالة توضح فشل الرفع
        printFailure("فشل رفع ملف البطاقة الضريبية، تأكد أن حجمه أقل من 10 ميجا");
        // إيقاف التنفيذ
        exit;
    }
}

// ============================================================
// الخطوة 8: تجهيز بيانات الإدخال وحفظها في قاعدة البيانات
// ============================================================
// بناء مصفوفة البيانات بأسماء أعمدة الجدول حرفياً
$insertData = [
    // معرّف المستخدم صاحب النشاط
    "company_user_id"                 => $user_id,
    // نوع النشاط بعد التحقق من صحته
    "company_business_type"           => $business_type,
    // اسم الشركة أو المصنع أو النشاط
    "company_name"                    => $name,
    // عنوان المقر بالتفصيل
    "company_headquarters_address"    => $address,
    // رقم الهاتف الأساسي
    "company_phone_number"            => $phone,
    // رقم الهاتف الإضافي (null إن لم يُرسل)
    "company_secondary_phone"         => $secondPhone !== '' ? $secondPhone : null,
    // اسم ملف السجل التجاري (null إن لم يُرفع)
    "company_commercial_register_pdf" => $commercialRegisterName,
    // اسم ملف البطاقة الضريبية (null إن لم يُرفع)
    "company_tax_card_pdf"            => $taxCardName,
];

// ⚠️ نمرر false لمنع الدالة من طباعة JSON لأننا سنبني رداً مخصصاً
$result = insertData("company_registering", $insertData, false);

// ============================================================
// الخطوة 9: التحقق من نجاح الإدخال وتنظيف الملفات عند الفشل
// ============================================================
// إذا فشل الإدخال في قاعدة البيانات
if ($result <= 0) {
    // ⚠️ حذف الملفات المرفوعة حتى لا تبقى ملفات بلا سجل في القاعدة
    if ($commercialRegisterName) {
        deleteFile(__DIR__ . "/../upload/commercial_registers/", $commercialRegisterName);
    }
    if ($taxCardName) {
        deleteFile(__DIR__ . "/../upload/tax_cards/", $taxCardName);
    }
    // رسالة توضح فشل الحفظ
    printFailure("فشل حفظ بيانات النشاط، يرجى المحاولة مرة أخرى", 500);
    // إيقاف التنفيذ
    exit;
}

// ⭐ قراءة معرّف الشركة الذي أنشأته قاعدة البيانات تلقائياً
// وهو الأهم في الرد لأن صفحة تفاصيل العامل تحتاجه
$newCompanyId = (int) $con->lastInsertId();

// إشعار الإدارة بتسجيل شركة جديدة بعد نجاح الإدخال
try {
    notifyCompanyRegistered(
        [
            "company_id"   => $newCompanyId,
            "company_name" => $name,
        ],
        [
            "users_id"        => $user['users_id'],
            "users_full_name" => $user['users_full_name'],
        ]
    );
} catch (Throwable $e) {
    // لا نوقف تسجيل الشركة إذا فشل الإشعار
}

// ============================================================
// الخطوة 10: بناء الرابط الأساسي لملفات المستندات
//
// ⚠️ نستخدم dirname مرتين للرجوع من مجلد companies لجذر المشروع
//    وإلا لتكرر اسم المجلد في الرابط وأصبح 404
//    (نفس المشكلة التي حدثت سابقاً في categories/get_all.php)
// ============================================================
// استخراج مسار جذر المشروع بالرجوع مستوى واحد للوراء
$scriptDir = dirname(dirname($_SERVER['SCRIPT_NAME']));

// بناء الرابط الكامل مع البروتوكول والمضيف
$baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . ($scriptDir === '/' || $scriptDir === '\\' ? '' : $scriptDir);

// إزالة أي شرطة مائلة زائدة من النهاية
$baseUrl = rtrim($baseUrl, '/');

// ============================================================
// الخطوة 11: إرسال الرد النهائي للتطبيق
// ============================================================
echo json_encode([
    // حالة العملية
    "status"  => "success",
    // رسالة عربية جاهزة للعرض في التطبيق
    "message" => "تم تسجيل بيانات النشاط بنجاح",
    // بيانات النتيجة
    "data"    => [
        // ⭐ معرّف الشركة الجديد — التطبيق يحفظه ويمرره لصفحة تفاصيل العامل
        "company_id"              => $newCompanyId,
        // معرّف المستخدم صاحب النشاط
        "company_user_id"         => $user_id,
        // اسم صاحب الحساب من جدول users
        "owner_full_name"         => $user['users_full_name'],
        // نوع النشاط كما هو مخزَّن في القاعدة
        "company_business_type"   => $business_type,
        // ⭐ ترجمة نوع النشاط للعربية لعرضها مباشرة بلا منطق في فلاتر
        "business_type_ar"        => match($business_type) {
            'company'             => 'شركة',
            'factory'             => 'مصنع',
            'commercial_activity' => 'نشاط تجاري',
            default               => 'غير محدد',
        },
        // اسم النشاط
        "company_name"            => $name,
        // عنوان المقر
        "company_headquarters_address" => $address,
        // رقم الهاتف الأساسي
        "company_phone_number"    => $phone,
        // رقم الهاتف الإضافي
        "company_secondary_phone" => $secondPhone !== '' ? $secondPhone : null,
        // رابط ملف السجل التجاري كاملاً (null إن لم يُرفع)
        "commercial_register_url" => $commercialRegisterName
            ? $baseUrl . '/upload/commercial_registers/' . $commercialRegisterName
            : null,
        // رابط ملف البطاقة الضريبية كاملاً (null إن لم يُرفع)
        "tax_card_url"            => $taxCardName
            ? $baseUrl . '/upload/tax_cards/' . $taxCardName
            : null,
        // ⭐ الخطوة التالية في التدفق لتسهيل التوجيه في فلاتر
        "next_step"               => "job_details_required",
    ],
]);
?>