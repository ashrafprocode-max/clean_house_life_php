<?php
// ============================================================
// ملف: admin/customers/add_company_account.php
// الغرض: إنشاء حساب مستخدم كامل + تسجيل شركته في خطوة واحدة
//        من لوحة الإدارة، بلا أن يدخل صاحب الشركة التطبيق إطلاقاً
// الطريقة: POST (multipart/form-data لأن فيه ملفات PDF)
//
// ⭐⭐ يعمل بوضعين:
//
//   الوضع 1 — حساب جديد (create):
//     users_full_name + phone إجباريان
//     → INSERT users + INSERT company_registering
//     → يُرجع كلمة مرور مؤقتة يُسلّمها المندوب لصاحب الشركة
//
//   الوضع 2 — ربط بحساب موجود (link):
//     link_users_id إجباري
//     → INSERT company_registering فقط (لا حساب جديد)
//     → للحالة التي كشفها check_phone.php: "exists_no_company"
//
// ⚠️⚠️ كل شيء داخل معاملة واحدة (transaction)
//   لأن نجاح users وفشل company يعني حساباً يتيماً
//   يحجز رقم الهاتف نهائياً بسبب قيد UNIQUE
//
// الصلاحية المطلوبة: companies.create
// ============================================================

// تضمين ملف الاتصال (مستويان للوراء لأن الملف داخل admin/customers)
include "../../connect.php";

// ============================================================
// الخطوة 1: التحقق من التوكن والصلاحية
//
// ⭐ companies.create يملكها: owner و manager و seller
//    ولا يملكها: support و accountant
//    وهذا بالضبط ما طلبته: المندوب يُضيف ولا يُعدّل
// ============================================================
// التحقق من التوكن وجلب بيانات الموظف مع صلاحياته
$currentAdmin = checkAdminToken();

// ⭐ فرض صلاحية إضافة شركة
requirePermission($currentAdmin, 'companies.create');

// معرّف الموظف المنفّذ مأخوذ من التوكن لا من الطلب
$adminId = (int) $currentAdmin['admin_id'];

// ============================================================
// الخطوة 2: استقبال بيانات الحساب
// ============================================================
// ⭐ معرّف حساب موجود للربط (فارغ = إنشاء حساب جديد)
$linkUserId = filterRequest("link_users_id");

// الاسم الكامل لصاحب الشركة - إجباري في وضع الإنشاء
$ownerName  = trim(filterRequest("users_full_name"));
// رقم هاتف صاحب الشركة - إجباري في وضع الإنشاء
$ownerPhone = filterRequest("users_phone");
// دولة صاحب الحساب - اختياري
$ownerCountry = trim(filterRequest("users_country"));
// منطقة صاحب الحساب - اختياري
$ownerRegion  = trim(filterRequest("users_region"));
// ⭐ كلمة مرور يكتبها الموظف - اختياري (نُولّدها إن لم يُرسلها)
$ownerPassword = trim(filterRequest("users_password"));

// ============================================================
// الخطوة 3: استقبال بيانات النشاط
// ============================================================
// نوع النشاط: company أو factory أو commercial_activity - إجباري
$businessType = filterRequest("company_business_type");
// اسم الشركة أو المصنع أو النشاط - إجباري
$companyName  = trim(filterRequest("company_name"));
// عنوان المقر بالتفصيل - إجباري
$address      = trim(filterRequest("company_headquarters_address"));
// رقم هاتف الشركة الأساسي - إجباري
$companyPhone = trim(filterRequest("company_phone_number"));
// رقم هاتف إضافي للشركة - اختياري
$secondPhone  = trim(filterRequest("company_secondary_phone"));

// ============================================================
// ⭐ الخطوة 4: تحديد وضع التشغيل
// ============================================================
// ⭐ الوضع = ربط إن أُرسل معرّف حساب موجود
$isLinkMode = ($linkUserId !== '' && (int)$linkUserId > 0);

// تحويل معرّف الربط لرقم صحيح
$linkUserId = (int) $linkUserId;

// ============================================================
// الخطوة 5: التحقق من الحقول الإجبارية لبيانات النشاط
// ============================================================
// إذا نقص أي حقل إجباري في بيانات النشاط نوقف التنفيذ
if ($businessType === '' || $companyName === '' ||
    $address === '' || $companyPhone === '') {
    // رسالة عربية توضح الحقول المطلوبة
    printFailure(
        "يرجى إدخال كل بيانات النشاط الإجبارية: "
        . "نوع النشاط، الاسم، العنوان، رقم الهاتف"
    );
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 6: التحقق من صحة نوع النشاط
//
// ⚠️ إجباري لأن العمود ENUM في MySQL
//    ولو أرسلنا قيمة خارج القائمة على سيرفر STRICT
//    سيفشل الإدخال برسالة SQL غامضة
// ============================================================
// قائمة أنواع النشاط المسموحة (نفس قيم الـ ENUM حرفياً)
$allowedBusinessTypes = ['company', 'factory', 'commercial_activity'];

// إذا كان النوع غير معروف نرفض الطلب برسالة واضحة
if (!in_array($businessType, $allowedBusinessTypes, true)) {
    // رسالة توضح القيم المسموحة
    printFailure(
        "نوع النشاط غير صحيح، القيم المسموحة: "
        . implode(', ', $allowedBusinessTypes)
    );
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 7: التحقق من طول اسم النشاط وعنوانه
// ============================================================
// ⚠️ الاسم لا يزيد على 255 حرفاً لأن العمود varchar(255)
if (mb_strlen($companyName) > 255) {
    // رسالة توضح الحد الأقصى
    printFailure("اسم النشاط يجب ألا يتجاوز 255 حرفاً");
    // إيقاف التنفيذ
    exit;
}

// العنوان لا يقل عن 5 أحرف ليكون مفيداً فعلاً
if (mb_strlen($address) < 5) {
    // رسالة توضح الحد الأدنى
    printFailure("عنوان المقر يجب أن يكون 5 أحرف على الأقل");
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// ⭐ الخطوة 8: التحقق من هاتف الشركة
//
// ⚠️ نُوحّد صيغته أيضاً حتى يكون البحث في اللوحة موحّداً
//    لكن لا نفرض UNIQUE عليه لأن عدة أنشطة قد تشترك في رقم واحد
// ============================================================
// توحيد صيغة هاتف الشركة
$companyPhone = normalizePhoneNumber($companyPhone);

// التحقق من صيغة هاتف الشركة
if (!preg_match('/^[0-9]{8,20}$/', $companyPhone)) {
    // رسالة توضح الصيغة المطلوبة
    printFailure("رقم هاتف الشركة غير صحيح");
    // إيقاف التنفيذ
    exit;
}

// ⭐ توحيد الهاتف الإضافي إن أُرسل
if ($secondPhone !== '') {
    // توحيد صيغة الرقم الإضافي
    $secondPhone = normalizePhoneNumber($secondPhone);
}

// ============================================================
// ⭐⭐ الخطوة 9: بدء المعاملة
//
// ⚠️⚠️ هذه أهم خطوة في الملف كله
//
// السيناريو الذي تمنعه:
//   INSERT users     ✅ نجح → الرقم 01055555555 صار محجوزاً (UNIQUE)
//   INSERT company   ❌ فشل
//   النتيجة بلا معاملة: حساب يتيم يحجز الرقم للأبد
//   والمندوب يعيد المحاولة فيرى "الرقم مستخدم" ولا يفهم السبب
//
// ⭐ مع المعاملة: rollBack يمحو الحساب فيعود الرقم متاحاً
//
// ⚠️ الجداول كلها InnoDB فالمعاملات تعمل ✅
//    (MyISAM لا تدعم المعاملات وتتجاهلها بصمت)
// ============================================================
// بدء المعاملة
$con->beginTransaction();

// ⭐ متغيّرات نحتاجها في كتلة catch لتنظيف الملفات المرفوعة
$commercialRegisterName = null;
$taxCardName            = null;

// ⚠️ نحيط كل عمليات الكتابة بـ try/catch لضمان التراجع عند أي خطأ
try {

    // ============================================================
    // ⭐ الخطوة 10: تجهيز حساب المستخدم (إنشاء أو ربط)
    // ============================================================
    // متغيّرات نتيجة الحساب
    $userId        = 0;
    $userFullName  = '';
    $userPhone     = '';
    $tempPassword  = null;
    $userToken     = null;
    // ⭐ يوضح للواجهة أي وضع نُفِّذ فعلاً
    $accountMode   = '';

    // ============================================================
    // ⭐ الوضع الأول: ربط النشاط بحساب موجود
    // ============================================================
    if ($isLinkMode) {
        // مفتاح الوضع للمنطق في فلاتر
        $accountMode = 'linked';

        // ⚠️ جلب الحساب الموجود والتأكد من صلاحيته للربط
        $stmtUser = $con->prepare(
            "SELECT users_id, users_full_name, users_phone,
                    users_type, users_is_active
             FROM users WHERE users_id = ? LIMIT 1"
        );
        // تنفيذ الاستعلام بمعرّف الحساب المُرسل
        $stmtUser->execute([$linkUserId]);
        // جلب صف الحساب
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

        // إذا لم يوجد الحساب نرفض الطلب
        if (!$user) {
            // ⚠️ إلغاء المعاملة قبل الخروج
            $con->rollBack();
            // رسالة توضح أن الحساب غير موجود
            printFailure("الحساب المطلوب ربطه غير موجود", 404);
            // إيقاف التنفيذ
            exit;
        }

        // ⚠️ لا نربط نشاطاً بحساب معطّل
        if ((int)$user['users_is_active'] !== 1) {
            // إلغاء المعاملة قبل الخروج
            $con->rollBack();
            // رسالة توضح أن الحساب معطّل
            printFailure(
                "هذا الحساب معطّل — فعّله أولاً قبل ربط نشاط به",
                403
            );
            // إيقاف التنفيذ
            exit;
        }

        // قراءة بيانات الحساب الموجود
        $userId       = (int) $user['users_id'];
        $userFullName = $user['users_full_name'];
        $userPhone    = $user['users_phone'];

    // ============================================================
    // ⭐⭐ الوضع الثاني: إنشاء حساب جديد بالكامل
    // ============================================================
    } else {
        // مفتاح الوضع للمنطق في فلاتر
        $accountMode = 'created';

        // ⚠️ التحقق من الحقلين الإجباريين لوضع الإنشاء
        if ($ownerName === '' || $ownerPhone === '') {
            // إلغاء المعاملة قبل الخروج
            $con->rollBack();
            // رسالة توضح الحقول المطلوبة
            printFailure(
                "يرجى إدخال اسم صاحب النشاط ورقم هاتفه، "
                . "أو أرسل link_users_id لربط النشاط بحساب موجود"
            );
            // إيقاف التنفيذ
            exit;
        }

        // ⭐⭐ استدعاء الدالة المشتركة لإنشاء الحساب
        // ⚠️ لا تُدير الدالة المعاملة — نحن نُديرها هنا
        $accountResult = adminCreateUserAccount([
            // الاسم الكامل لصاحب النشاط
            "full_name" => $ownerName,
            // رقم هاتفه (تُوحّده الدالة داخلياً)
            "phone"     => $ownerPhone,
            // ⭐ صاحب الشركة يُسجّل كـ client
            // ⚠️ لأن users_type enum يحتوي client و provider فقط
            //    ولا يوجد نوع company (قرار سابق مُتَّفق عليه)
            "user_type" => "client",
            // الدولة
            "country"   => $ownerCountry,
            // المنطقة
            "region"    => $ownerRegion,
            // كلمة المرور إن كتبها الموظف
            "password"  => $ownerPassword,
            // ⭐ معرّف الموظف المنشئ للمساءلة
            "admin_id"  => $adminId,
        ]);

        // ⚠️ إن كان الرقم مسجّلاً بالفعل نُرجع بياناته ونتراجع
        if ($accountResult['status'] === 'exists') {
            // إلغاء المعاملة قبل الخروج
            $con->rollBack();
            // ⭐ رد 409 يحمل معرّف الحساب الموجود ليربط به المندوب
            printFailure(
                $accountResult['message']
                . " — أرسل link_users_id لربط النشاط بحسابه",
                409,
                // بيانات الحساب الموجود
                $accountResult['data']
            );
            // إيقاف التنفيذ
            exit;
        }

        // ⚠️ إن فشل الإنشاء لأي سبب آخر نتراجع ونُرجع الرسالة
        if ($accountResult['status'] !== 'success') {
            // إلغاء المعاملة قبل الخروج
            $con->rollBack();
            // رسالة الفشل القادمة من الدالة
            printFailure($accountResult['message']);
            // إيقاف التنفيذ
            exit;
        }

        // ⭐ قراءة بيانات الحساب الجديد
        $userId       = (int) $accountResult['data']['users_id'];
        $userFullName = $accountResult['data']['users_full_name'];
        $userPhone    = $accountResult['data']['users_phone'];
        // ⭐⭐ كلمة المرور المؤقتة — تُعرض مرة واحدة فقط
        $tempPassword = $accountResult['data']['temp_password'];
        // توكن جلسة المستخدم
        $userToken    = $accountResult['data']['token'];
    }

    // ============================================================
    // ⭐ الخطوة 11: التحقق من عدم وجود نشاط سابق لهذا المستخدم
    //
    // ⚠️ إلزامي في الوضعين معاً
    //   لأن company_user_id بلا قيد UNIQUE
    //   فبدون هذا الفحص يُنشئ كل ضغط شركة مكرَّرة
    // ============================================================
    // تجهيز استعلام البحث عن نشاط سابق لنفس المستخدم
    $stmtExisting = $con->prepare(
        "SELECT company_id, company_name
         FROM company_registering
         WHERE company_user_id = ? LIMIT 1"
    );
    // تنفيذ الاستعلام بمعرّف المستخدم
    $stmtExisting->execute([$userId]);
    // جلب الصف إن وُجد
    $existingCompany = $stmtExisting->fetch(PDO::FETCH_ASSOC);

    // إذا وُجد نشاط مسجَّل نتراجع ونُرجع معرّفه
    if ($existingCompany) {
        // إلغاء المعاملة قبل الخروج
        $con->rollBack();
        // ⭐ رد 409 يحمل معرّف الشركة الموجودة
        printFailure(
            "هذا الحساب لديه نشاط مسجّل بالفعل باسم: "
            . $existingCompany['company_name'],
            409,
            [
                // ⭐ اللوحة تفتح صفحة التعديل بهذا المعرّف
                "company_id"   => (int) $existingCompany['company_id'],
                "company_name" => $existingCompany['company_name'],
                "users_id"     => $userId,
            ]
        );
        // إيقاف التنفيذ
        exit;
    }

    // ============================================================
    // الخطوة 12: رفع ملف السجل التجاري (اختياري)
    //
    // ⚠️ الملفات ترتفع للقرص لا لقاعدة البيانات
    //    فالمعاملة لا تتراجع عنها — لذلك نحذفها يدوياً في catch
    // ============================================================
    // نتحقق أن الملف أُرسل فعلاً وليس حقلاً فارغاً
    if (isset($_FILES['commercial_register_pdf']) &&
        $_FILES['commercial_register_pdf']['error'] !== UPLOAD_ERR_NO_FILE) {

        // استخراج امتداد الملف الأصلي وتحويله لحروف صغيرة
        $crExt = strtolower(pathinfo(
            $_FILES['commercial_register_pdf']['name'],
            PATHINFO_EXTENSION
        ));

        // ⭐ نقبل PDF فقط للسجل التجاري
        if ($crExt !== 'pdf') {
            // رمي استثناء ليعالجه catch ويتراجع عن المعاملة
            throw new Exception("ملف السجل التجاري يجب أن يكون بصيغة PDF فقط");
        }

        // رفع الملف لمجلد السجلات التجارية بدالتك الموحدة
        $commercialRegisterName = imageUploadtwo(
            __DIR__ . "/../../upload/commercial_registers/",
            "commercial_register_pdf"
        );

        // نتحقق من نجاح الرفع
        if (in_array($commercialRegisterName,
                     ["fail", "empty", "extension_error", "size_error"], true)) {
            // ⚠️ نُفرّغ المتغيّر لأن الملف لم يُرفع فعلاً
            $commercialRegisterName = null;
            // رمي استثناء يوضح فشل الرفع
            throw new Exception(
                "فشل رفع ملف السجل التجاري، تأكد أن حجمه أقل من 10 ميجا"
            );
        }
    }

    // ============================================================
    // الخطوة 13: رفع ملف البطاقة الضريبية (اختياري)
    // ============================================================
    // نتحقق أن الملف أُرسل فعلاً
    if (isset($_FILES['tax_card_pdf']) &&
        $_FILES['tax_card_pdf']['error'] !== UPLOAD_ERR_NO_FILE) {

        // استخراج امتداد الملف الأصلي
        $tcExt = strtolower(pathinfo(
            $_FILES['tax_card_pdf']['name'],
            PATHINFO_EXTENSION
        ));

        // ⭐ نقبل PDF فقط للبطاقة الضريبية
        if ($tcExt !== 'pdf') {
            // رمي استثناء ليعالجه catch (وهو يحذف السجل التجاري)
            throw new Exception("ملف البطاقة الضريبية يجب أن يكون بصيغة PDF فقط");
        }

        // رفع الملف لمجلد البطاقات الضريبية
        $taxCardName = imageUploadtwo(
            __DIR__ . "/../../upload/tax_cards/",
            "tax_card_pdf"
        );

        // نتحقق من نجاح الرفع
        if (in_array($taxCardName,
                     ["fail", "empty", "extension_error", "size_error"], true)) {
            // نُفرّغ المتغيّر لأن الملف لم يُرفع فعلاً
            $taxCardName = null;
            // رمي استثناء يوضح فشل الرفع
            throw new Exception(
                "فشل رفع ملف البطاقة الضريبية، تأكد أن حجمه أقل من 10 ميجا"
            );
        }
    }

    // ============================================================
    // ⭐ الخطوة 14: إدخال النشاط في جدول company_registering
    // ============================================================
    // بناء مصفوفة البيانات بأسماء أعمدة الجدول حرفياً
    $companyData = [
        // ⭐ معرّف المستخدم (الجديد أو المربوط)
        "company_user_id"                 => $userId,
        // نوع النشاط بعد التحقق من صحته
        "company_business_type"           => $businessType,
        // اسم الشركة أو المصنع أو النشاط
        "company_name"                    => $companyName,
        // عنوان المقر بالتفصيل
        "company_headquarters_address"    => $address,
        // ⭐ رقم الهاتف الأساسي بعد توحيد صيغته
        "company_phone_number"            => $companyPhone,
        // رقم الهاتف الإضافي (null إن لم يُرسل)
        "company_secondary_phone"         => $secondPhone !== '' ? $secondPhone : null,
        // اسم ملف السجل التجاري (null إن لم يُرفع)
        "company_commercial_register_pdf" => $commercialRegisterName,
        // اسم ملف البطاقة الضريبية (null إن لم يُرفع)
        "company_tax_card_pdf"            => $taxCardName,
        // ⭐⭐ معرّف الموظف الذي سجّل النشاط (للمساءلة)
        "company_created_by_admin"        => $adminId,
    ];

    // ⚠️ نمرر false لمنع الدالة من طباعة JSON لأننا نبني رداً مخصصاً
    $companyResult = insertData("company_registering", $companyData, false);

    // إذا فشل الإدخال نرمي استثناء ليتراجع catch عن كل شيء
    if ($companyResult <= 0) {
        // رمي استثناء يوضح فشل الحفظ
        throw new Exception("فشل حفظ بيانات النشاط في قاعدة البيانات");
    }

    // ⭐ قراءة معرّف الشركة الجديد
    $newCompanyId = (int) $con->lastInsertId();

    // ============================================================
    // ⭐⭐ الخطوة 15: الالتزام بالمعاملة
    //
    // ⭐ كل شيء نجح، فنُثبّت التغييرات نهائياً
    //   وقبل هذا السطر لم يُكتب أي شيء بشكل نهائي
    // ============================================================
    // تثبيت كل التغييرات
    $con->commit();

    // ============================================================
    // ⭐ الخطوة 16: تسجيل الإجراء في سجل النشاط
    //
    // ⚠️ بعد commit لا قبله
    //   لأن فشل السجل لا يجوز أن يُفشل إنشاء الشركة
    //   والدالة نفسها لا تُوقف التنفيذ عند الفشل
    // ============================================================
    // تسجيل إجراء إنشاء الشركة
    logAdminActivity(
        // بيانات الموظف المنفّذ
        $currentAdmin,
        // ⭐ نوع الإجراء يوضح الوضع (إنشاء أم ربط)
        $isLinkMode ? 'link_company_to_user' : 'create_company_account',
        // نوع الكيان
        'company',
        // معرّف الشركة الجديدة
        $newCompanyId,
        // ⭐ التفاصيل التي نحتاجها في المراجعة لاحقاً
        [
            // معرّف المستخدم المرتبط
            "users_id"      => $userId,
            // اسم صاحب النشاط
            "owner_name"    => $userFullName,
            // هاتف صاحب النشاط
            "owner_phone"   => $userPhone,
            // اسم النشاط
            "company_name"  => $companyName,
            // نوع النشاط
            "business_type" => $businessType,
            // ⭐ هل أُنشئ حساب جديد أم رُبط بموجود؟
            "account_mode"  => $accountMode,
            // ⚠️ لا نُسجّل كلمة المرور المؤقتة في السجل إطلاقاً
        ]
    );

    // ============================================================
    // الخطوة 17: بناء الرابط الأساسي لملفات المستندات
    //
    // ⚠️ نرجع مستويين للوراء لأن الملف داخل admin/customers
    //    وإلا لتكرر اسم المجلد في الرابط وأصبح 404
    // ============================================================
    // استخراج مسار جذر المشروع بالرجوع مستويين
    $scriptDir = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'])));

    // بناء الرابط الكامل مع البروتوكول والمضيف
    $baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
        . '://' . $_SERVER['HTTP_HOST']
        . ($scriptDir === '/' || $scriptDir === '\\' ? '' : $scriptDir);

    // إزالة أي شرطة مائلة زائدة من النهاية
    $baseUrl = rtrim($baseUrl, '/');

    // ============================================================
    // ⭐ الخطوة 18: إرسال الرد النهائي
    // ============================================================
    echo json_encode([
        // حالة العملية
        "status"  => "success",
        // ⭐ رسالة عربية تتغير حسب الوضع
        "message" => $isLinkMode
            ? "تم ربط النشاط بحساب " . $userFullName . " بنجاح"
            : "تم إنشاء الحساب وتسجيل النشاط بنجاح",
        // بيانات النتيجة
        "data"    => [

            // ===== ⭐ بيانات الحساب =====
            "account" => [
                // ⭐ مفتاح الوضع للمنطق في فلاتر
                "mode"            => $accountMode,
                // ⭐ ترجمة الوضع للعرض
                "mode_ar"         => $isLinkMode
                                     ? 'رُبط بحساب موجود'
                                     : 'حساب جديد',
                // معرّف المستخدم
                "users_id"        => $userId,
                // الاسم الكامل
                "users_full_name" => $userFullName,
                // ⭐ الرقم بعد التوحيد (قد يختلف عمّا كتبه الموظف)
                "users_phone"     => $userPhone,
                // نوع الحساب
                "users_type"      => "client",
            ],

            // ===== ⭐⭐ بيانات الدخول (في وضع الإنشاء فقط) =====
            // ⚠️⚠️ كلمة المرور المؤقتة تُعرض في هذا الرد وحده
            //    ولا تُرجَع في أي طلب آخر إطلاقاً — فالمندوب
            //    يجب أن يكتبها أو يُصوّر الشاشة قبل مغادرتها
            "credentials" => $isLinkMode ? null : [
                // رقم الهاتف = اسم المستخدم في التطبيق
                "login_phone"    => $userPhone,
                // ⭐⭐ كلمة المرور المؤقتة
                "temp_password"  => $tempPassword,
                // ⭐ تعليمات جاهزة يقرأها المندوب على صاحب الشركة
                "instructions_ar" =>
                    "سلّم صاحب النشاط هذه البيانات:\n"
                    . "• رقم الدخول: " . $userPhone . "\n"
                    . "• كلمة المرور المؤقتة: " . $tempPassword . "\n"
                    . "⚠️ يجب تغييرها من التطبيق عند أول دخول",
                // ⚠️ تنبيه أن الكلمة لن تظهر مرة أخرى
                "warning_ar"     =>
                    "⚠️ كلمة المرور تظهر هذه المرة فقط — "
                    . "لن تستطيع استرجاعها لاحقاً",
                // ⭐ توكن الجلسة (لا يُعرض للموظف، للتكامل فقط)
                "token"          => $userToken,
            ],

            // ===== ⭐ بيانات النشاط =====
            "company" => [
                // ⭐ معرّف الشركة الجديد — تحتاجه صفحة طلب العمالة
                "company_id"                   => $newCompanyId,
                // معرّف المستخدم صاحب النشاط
                "company_user_id"              => $userId,
                // نوع النشاط بالإنجليزية
                "company_business_type"        => $businessType,
                // ⭐ ترجمة نوع النشاط من PHP بلا منطق في فلاتر
                "business_type_ar"             => match($businessType) {
                    'company'             => 'شركة',
                    'factory'             => 'مصنع',
                    'commercial_activity' => 'نشاط تجاري',
                    default               => 'غير محدد',
                },
                // اسم النشاط
                "company_name"                 => $companyName,
                // عنوان المقر
                "company_headquarters_address"  => $address,
                // رقم الهاتف الأساسي
                "company_phone_number"         => $companyPhone,
                // رقم الهاتف الإضافي
                "company_secondary_phone"      => $secondPhone !== '' ? $secondPhone : null,
                // رابط ملف السجل التجاري (null إن لم يُرفع)
                "commercial_register_url"      => $commercialRegisterName
                    ? $baseUrl . '/upload/commercial_registers/' . $commercialRegisterName
                    : null,
                // رابط ملف البطاقة الضريبية (null إن لم يُرفع)
                "tax_card_url"                 => $taxCardName
                    ? $baseUrl . '/upload/tax_cards/' . $taxCardName
                    : null,
                // ⭐ هل المستندان مرفوعان معاً؟
                "documents_complete"           => ($commercialRegisterName && $taxCardName),
            ],

            // ===== ⭐ الخطوة التالية للتوجيه في اللوحة =====
            "next_step"    => "add_job_request",
            // ⭐ ترجمة الخطوة التالية
            "next_step_ar" => "أضف تفاصيل العمالة المطلوبة",

            // ===== سجل التنفيذ =====
            "created_by" => [
                // معرّف الموظف المنفّذ
                "admin_id"   => $adminId,
                // اسم الموظف المنفّذ
                "admin_name" => $currentAdmin['admin_full_name'],
                // ⭐ دور الموظف مترجماً
                "role_ar"    => $currentAdmin['role_name_ar'] ?? null,
            ],
        ],
    ]);

// ============================================================
// ⭐⭐ الخطوة 19: معالجة أي خطأ والتراجع الكامل
// ============================================================
} catch (Exception $e) {

    // ⚠️ التراجع عن المعاملة إن كانت ما زالت مفتوحة
    // ⭐ نفحص أولاً لأن rollBack على معاملة مغلقة يرمي استثناء آخر
    if ($con->inTransaction()) {
        // إلغاء كل ما كُتب في قاعدة البيانات
        $con->rollBack();
    }

    // ============================================================
    // ⚠️ حذف الملفات المرفوعة يدوياً
    //
    // ⭐ لماذا يدوياً؟ لأن المعاملة تحمي قاعدة البيانات فقط
    //   والملفات ترتفع للقرص خارج نطاقها
    //   فلو تركناها لبقيت ملفات لا يشير إليها أي صف
    // ============================================================
    // حذف السجل التجاري إن كان قد رُفع
    if ($commercialRegisterName) {
        // حذف الملف من مجلده
        deleteFile(
            __DIR__ . "/../../upload/commercial_registers/",
            $commercialRegisterName
        );
    }

    // حذف البطاقة الضريبية إن كانت قد رُفعت
    if ($taxCardName) {
        // حذف الملف من مجلده
        deleteFile(
            __DIR__ . "/../../upload/tax_cards/",
            $taxCardName
        );
    }

    // ⭐ تسجيل الخطأ في سجل PHP للتشخيص
    error_log("add_company_account failed: " . $e->getMessage());

    // ⚠️ إرجاع رسالة الخطأ للموظف
    printFailure(
        "تعذّر إتمام العملية: " . $e->getMessage(),
        500
    );
    // إيقاف التنفيذ
    exit;
}
?>