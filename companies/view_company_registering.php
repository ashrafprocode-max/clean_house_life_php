<?php
// ============================================================
// ملف: companies/view_company_registering.php
// الغرض: عرض بيانات النشاط لصاحبه في التطبيق
//
// ⚠️ الفرق الجوهري عن نسخة الإدارة (admin/companies/):
//   لا نُرجع أسماء الموظفين المراجعين
//   ولا الملاحظات الداخلية إلا سبب الرفض الموجَّه للشركة
//   ولا حالة users_approve
//   ولا نسمح برؤية بيانات شركة أخرى إطلاقاً
//
// ⭐ يقبل أحد مدخلين:
//   company_id      → لعرض شركة بمعرّفها (مع التحقق من الملكية)
//   company_user_id → لعرض شركة المستخدم مباشرة (الأنسب للتطبيق)
//
// الطريقة: POST أو GET
// ============================================================

// تضمين ملف الاتصال بقاعدة البيانات والدوال المساعدة
include "../connect.php";

// ============================================================
// الخطوة 1: استقبال البيانات
// ============================================================
// معرّف المستخدم صاحب النشاط - الأساس في التحقق
$user_id    = filterRequest("company_user_id");
// معرّف الشركة - اختياري
$company_id = filterRequest("company_id");

// ============================================================
// الخطوة 2: التحقق من إرسال معرّف المستخدم
//
// ⚠️ إجباري دائماً لأنه أساس التحقق من الملكية
// ============================================================
// إذا لم يُرسل معرّف المستخدم نوقف التنفيذ
if (empty($user_id)) {
    // رسالة توضح أن التوثيق مطلوب
    printFailure("الحقل company_user_id مطلوب", 401);
    // إيقاف التنفيذ
    exit;
}

// تحويل معرّف المستخدم لرقم صحيح
$user_id = (int) $user_id;

// ⚠️⚠️ تنبيه أمني مهم:
//   هذا الملف يثق في company_user_id القادم من الطلب
//   لأن checkAuthenticate() معطّلة في connect.php (السطر ~138)
//   فيستطيع أي شخص تمرير معرّف مستخدم آخر ورؤية بيانات شركته
//   والحل الجذري هو تفعيل توكن المستخدم (مؤجَّل بطلبك)

// ============================================================
// الخطوة 3: بناء شرط البحث
//
// ⭐ نبحث دائماً بمعرّف المستخدم لضمان الملكية
//    ونُضيف معرّف الشركة إن أُرسل (لدعم تعدد الشركات مستقبلاً)
// ============================================================
// الشرط الأساسي: الشركة تخص هذا المستخدم
$whereSQL = "c.company_user_id = ?";
// مصفوفة المعاملات
$params   = [$user_id];

// نُضيف شرط معرّف الشركة إن أُرسل
if ($company_id !== '') {
    // إضافة شرط معرّف الشركة
    $whereSQL .= " AND c.company_id = ?";
    // تمرير المعرّف كرقم صحيح
    $params[] = (int) $company_id;
}

// ============================================================
// الخطوة 4: جلب بيانات الشركة
// ============================================================
// تجهيز استعلام محمي لجلب بيانات النشاط
$stmt = $con->prepare(
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
        u.users_full_name
     FROM company_registering c
     INNER JOIN users u ON u.users_id = c.company_user_id
     WHERE $whereSQL
     LIMIT 1"
);
// تنفيذ الاستعلام بتمرير المعاملات
$stmt->execute($params);
// جلب صف الشركة
$company = $stmt->fetch(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 5: التعامل مع عدم وجود نشاط مسجَّل
//
// ⭐ نُرجع رداً ناجحاً بـ has_company = false لا خطأ 404
//    لأن عدم وجود نشاط حالة طبيعية لمستخدم جديد
//    والتطبيق يحتاج تمييزها عن الخطأ ليفتح صفحة التسجيل
// ============================================================
// إذا لم توجد شركة نُرجع رداً واضحاً بلا خطأ
if (!$company) {
    // رد ناجح يوضح عدم وجود نشاط
    echo json_encode([
        // حالة العملية ناجحة (ليست خطأ)
        "status"  => "success",
        // رسالة عربية واضحة
        "message" => "لا يوجد نشاط مسجّل لهذا الحساب",
        // بيانات النتيجة
        "data"    => [
            // ⭐ التطبيق يقرأ هذا المفتاح ويفتح صفحة التسجيل
            "has_company" => false,
            // ⭐ الشاشة التي يجب فتحها
            "next_step"   => "company_registering",
        ],
    ]);
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 6: جلب طلبات العمالة الخاصة بهذه الشركة
//
// ⚠️ لا نجلب required_reviewed_by ولا اسم الموظف
//    لأن هوية الموظف المراجع شأن داخلي لا يخص الشركة
//    لكننا نجلب سبب الرفض لأنه موجَّه للشركة لتُصحّح
// ============================================================
// تجهيز استعلام لجلب طلبات العمالة
$stmtJobs = $con->prepare(
    "SELECT
        required_id,
        required_job_title,
        required_job_description,
        required_is_verified_worker,
        required_workers_count,
        required_start_date_time,
        required_approval_status,
        required_review_note,
        required_reviewed_at,
        required_created_at
     FROM job_details_required
     WHERE required_company_id = ?
     ORDER BY required_created_at DESC"
);
// تنفيذ الاستعلام بتمرير معرّف الشركة
$stmtJobs->execute([$company['company_id']]);
// جلب كل طلبات العمالة
$jobs = $stmtJobs->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 7: بناء الرابط الأساسي
//
// ⚠️ نرجع مستوى واحداً للوراء (companies) للوصول لجذر المشروع
// ============================================================
// استخراج مسار جذر المشروع من مسار السكربت الحالي
$scriptDir = dirname(dirname($_SERVER['SCRIPT_NAME']));
// تجميع الرابط الكامل مع البروتوكول واسم النطاق
$baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . ($scriptDir === '/' || $scriptDir === '\\' ? '' : $scriptDir);
// إزالة أي شرطة مائلة زائدة من نهاية الرابط
$baseUrl = rtrim($baseUrl, '/');

// ============================================================
// الخطوة 8: بناء روابط المستندات عبر البوّابة الآمنة
//
// ⭐ نُمرّر company_user_id في الرابط لأن البوّابة تتحقق من الملكية
//    (بخلاف نسخة الإدارة التي تُمرّر التوكن)
// ============================================================
// دالة مساعدة تبني رابط البوّابة الآمنة لنوع مستند معيّن
$secureUrl = function (string $type, ?string $fileName) use ($baseUrl, $company, $user_id) {
    // إذا لم يكن المستند مرفوعاً نُرجع null
    if (empty($fileName)) return null;
    // بناء رابط البوّابة مع نوع المستند ومعرّف الشركة والمالك
    return $baseUrl . '/companies/secure_company_document.php'
        . '?type=' . urlencode($type)
        . '&company_id=' . urlencode($company['company_id'])
        . '&company_user_id=' . urlencode($user_id);
};

// ============================================================
// الخطوة 9: تحويل طلبات العمالة لشكل مناسب للتطبيق
// ============================================================
$jobsData = array_map(function ($j) {
    // إرجاع مصفوفة الطلب بالشكل الذي يقرأه التطبيق
    return [
        // معرّف الطلب
        "required_id"                 => (int)$j['required_id'],
        // اسم المهنة المطلوبة
        "required_job_title"          => $j['required_job_title'],
        // شرح المواصفات
        "required_job_description"    => $j['required_job_description'],
        // حالة توثيق العامل
        "required_is_verified_worker" => $j['required_is_verified_worker'],
        // ⭐ ترجمة حالة التوثيق للعربية
        "verification_ar"             => $j['required_is_verified_worker'] === 'verified'
            ? 'موثق' : 'غير موثق',
        // عدد العمال المطلوبين
        "required_workers_count"      => (int)$j['required_workers_count'],
        // تاريخ ووقت بدء العمل
        "required_start_date_time"    => $j['required_start_date_time'],
        // حالة الموافقة
        "required_approval_status"    => $j['required_approval_status'],
        // ⭐ ترجمة حالة الموافقة للعربية
        "approval_status_ar"          => match($j['required_approval_status']) {
            'pending'  => 'قيد المراجعة',
            'approved' => 'مقبول',
            'rejected' => 'مرفوض',
            default    => 'غير محدد',
        },
        // ⭐ سبب الرفض يظهر للشركة فقط عند الرفض
        // لأن ملاحظات الحالات الأخرى شأن داخلي للإدارة
        "rejection_reason"            => $j['required_approval_status'] === 'rejected'
            ? $j['required_review_note'] : null,
        // تاريخ المراجعة
        "reviewed_at"                 => $j['required_reviewed_at'],
        // تاريخ إرسال الطلب
        "required_created_at"         => $j['required_created_at'],
    ];
}, $jobs);

// ============================================================
// الخطوة 10: حساب إحصائيات الطلبات
// ============================================================
// عدد الطلبات المعلّقة
$pendingJobs  = 0;
// عدد الطلبات المقبولة
$approvedJobs = 0;
// عدد الطلبات المرفوضة
$rejectedJobs = 0;

// نمرّ على كل الطلبات ونحسب
foreach ($jobs as $j) {
    // زيادة العدّاد المناسب حسب الحالة
    if     ($j['required_approval_status'] === 'pending')  $pendingJobs++;
    elseif ($j['required_approval_status'] === 'approved') $approvedJobs++;
    elseif ($j['required_approval_status'] === 'rejected') $rejectedJobs++;
}

// ============================================================
// الخطوة 11: تحديد الشاشة التالية للتطبيق
//
// ⭐ نحسبها في السيرفر لا في فلاتر
//    فلو تغيّر المنطق لاحقاً نُعدّل مكاناً واحداً
// ============================================================
// إذا لم يُرسل أي طلب عمالة بعد
if (count($jobs) === 0) {
    // نوجّهه لصفحة تفاصيل العامل المطلوب
    $nextStep = "job_details_required";
} elseif ($pendingJobs > 0) {
    // إذا كان لديه طلب معلَّق نوجّهه لصفحة الانتظار
    $nextStep = "pending_management_approval";
} else {
    // وإلا نوجّهه للرئيسية لأن كل طلباته مُراجَعة
    $nextStep = "home";
}

// ============================================================
// الخطوة 12: إرسال الرد النهائي
// ============================================================
echo json_encode([
    // حالة العملية
    "status"  => "success",
    // رسالة نجاح
    "message" => "تم جلب بيانات نشاطك بنجاح",
    // بيانات النتيجة
    "data"    => [
        // ⭐ يوجد نشاط مسجّل
        "has_company" => true,

        // ===== بيانات النشاط =====
        "company" => [
            // معرّف الشركة (يحتاجه التطبيق لإرسال طلب عمالة جديد)
            "company_id"              => (int)$company['company_id'],
            // معرّف المستخدم صاحب النشاط
            "company_user_id"         => (int)$company['company_user_id'],
            // اسم صاحب الحساب
            "owner_full_name"         => $company['users_full_name'],
            // نوع النشاط كما هو مخزَّن
            "company_business_type"   => $company['company_business_type'],
            // ⭐ ترجمة نوع النشاط للعربية
            "business_type_ar"        => match($company['company_business_type']) {
                'company'             => 'شركة',
                'factory'             => 'مصنع',
                'commercial_activity' => 'نشاط تجاري',
                default               => 'غير محدد',
            },
            // اسم النشاط
            "company_name"            => $company['company_name'],
            // عنوان المقر
            "company_headquarters_address" => $company['company_headquarters_address'],
            // رقم الهاتف الأساسي
            "company_phone_number"    => $company['company_phone_number'],
            // رقم الهاتف الإضافي
            "company_secondary_phone" => $company['company_secondary_phone'],
            // تاريخ التسجيل
            "company_created_at"      => $company['company_created_at'],
        ],

        // ===== المستندات =====
        "documents" => [
            // ملف السجل التجاري
            "commercial_register" => [
                // هل الملف مرفوع؟
                "exists" => !empty($company['company_commercial_register_pdf']),
                // رابط البوّابة الآمنة
                "url"    => $secureUrl('commercial_register', $company['company_commercial_register_pdf']),
            ],
            // ملف البطاقة الضريبية
            "tax_card" => [
                // هل الملف مرفوع؟
                "exists" => !empty($company['company_tax_card_pdf']),
                // رابط البوّابة الآمنة
                "url"    => $secureUrl('tax_card', $company['company_tax_card_pdf']),
            ],
        ],

        // ===== طلبات العمالة =====
        "job_requests" => $jobsData,

        // ===== إحصائيات =====
        "stats" => [
            // إجمالي عدد الطلبات
            "total_requests"    => count($jobs),
            // عدد الطلبات المعلّقة
            "pending_requests"  => $pendingJobs,
            // عدد الطلبات المقبولة
            "approved_requests" => $approvedJobs,
            // عدد الطلبات المرفوضة
            "rejected_requests" => $rejectedJobs,
        ],

        // ⭐ الشاشة التالية التي يجب أن يفتحها التطبيق
        "next_step" => $nextStep,
    ],
]);
?>