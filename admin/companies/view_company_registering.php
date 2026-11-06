<?php
// ============================================================
// ملف: admin/companies/view_company_registering.php
// الغرض: عرض كل بيانات شركة واحدة بالتفصيل لصفحة مراجعة الإدارة
//
// ⭐ يُرجع أربع كتل:
//   1. account   → بيانات صاحب الحساب من جدول users
//   2. company   → بيانات النشاط كاملة
//   3. documents → المستندات عبر البوّابة الآمنة (لا روابط مباشرة)
//   4. job_requests → كل طلبات العمالة الخاصة بهذه الشركة
//
// ⚠️ لا نُرجع أسماء ملفات المستندات إطلاقاً
//    بل روابط secure_company_document.php فقط
//
// الحقل الإجباري: company_id
// الطريقة: POST أو GET
// ============================================================

// تضمين ملف الاتصال (مستويان للوراء لأن الملف داخل admin/companies)
include "../../connect.php";

// ⭐ التحقق من توكن الإدارة — أي موظف مسجّل دخوله يستطيع القراءة
$currentAdmin = checkAdminToken();

// الحماية الحقيقية على السيرفر وليست في واجهة التطبيق فقط
requirePermission($currentAdmin, 'companies.view');

// ============================================================
// الخطوة 1: استقبال معرّف الشركة والتحقق منه
// ============================================================
// معرّف الشركة المراد عرضها - إجباري
$company_id = filterRequest("company_id");

// إذا لم يُرسل المعرّف نوقف التنفيذ فوراً
if (empty($company_id)) {
    // رسالة توضح الحقل المطلوب
    printFailure("الحقل company_id مطلوب");
    // إيقاف التنفيذ
    exit;
}

// تحويل المعرّف لرقم صحيح لحماية جملة WHERE
$company_id = (int) $company_id;

// ============================================================
// الخطوة 2: جلب بيانات الشركة مع صاحب الحساب
//
// ⭐ INNER JOIN على users لأن كل شركة لها مالك إجبارياً
// ============================================================
// تجهيز استعلام محمي لجلب كل بيانات الشركة والمالك
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
        u.users_full_name,
        u.users_phone,
        u.users_country,
        u.users_region,
        u.users_type,
        u.users_is_active,
        u.users_approve,
        u.users_created_at
     FROM company_registering c
     INNER JOIN users u ON u.users_id = c.company_user_id
     WHERE c.company_id = ?
     LIMIT 1"
);
// تنفيذ الاستعلام بتمرير معرّف الشركة
$stmt->execute([$company_id]);
// جلب صف الشركة
$company = $stmt->fetch(PDO::FETCH_ASSOC);

// إذا لم توجد الشركة نرفض الطلب
if (!$company) {
    // رسالة توضح أن الشركة غير موجودة
    printFailure("الشركة غير موجودة", 404);
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 3: جلب كل طلبات العمالة الخاصة بهذه الشركة
//
// ⭐ لماذا نجلبها هنا؟ لأن الموظف الذي يفتح ملف الشركة
//    يحتاج رؤية طلباتها في نفس الشاشة بلا طلب شبكة ثانٍ
// ============================================================
// تجهيز استعلام لجلب طلبات العمالة مع اسم مراجع كل طلب
$stmtJobs = $con->prepare(
    "SELECT
        j.required_id,
        j.required_job_title,
        j.required_job_description,
        j.required_is_verified_worker,
        j.required_workers_count,
        j.required_start_date_time,
        j.required_approval_status,
        j.required_reviewed_at,
        j.required_review_note,
        j.required_created_at,
        -- ⭐⭐ عدد ساعات انتظار هذا الطلب — محسوبة داخل MySQL
        -- ⚠️ توقيت PHP يختلف عن توقيت MySQL فحسابها في PHP يُنتج سالباً
        TIMESTAMPDIFF(HOUR, j.required_created_at, NOW()) AS waiting_hours,

        -- اسم الموظف الذي راجع الطلب
        au.admin_full_name AS reviewed_by_name
     FROM job_details_required j
     LEFT JOIN admin_users au ON au.admin_id = j.required_reviewed_by
     WHERE j.required_company_id = ?
     ORDER BY
        -- ⭐ المعلّقة أولاً لأنها الأهم للموظف
        CASE WHEN j.required_approval_status = 'pending' THEN 0 ELSE 1 END ASC,
        -- ثم الأحدث إرسالاً
        j.required_created_at DESC"
);
// تنفيذ الاستعلام بتمرير معرّف الشركة
$stmtJobs->execute([$company_id]);
// جلب كل طلبات العمالة
$jobs = $stmtJobs->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 4: بناء الرابط الأساسي
//
// ⚠️ نرجع مستويين للوراء (admin/companies) للوصول لجذر المشروع
// ============================================================
// استخراج مسار جذر المشروع من مسار السكربت الحالي
$scriptDir = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'])));
// تجميع الرابط الكامل مع البروتوكول واسم النطاق
$baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . ($scriptDir === '/' || $scriptDir === '\\' ? '' : $scriptDir);
// إزالة أي شرطة مائلة زائدة من نهاية الرابط
$baseUrl = rtrim($baseUrl, '/');

// ============================================================
// الخطوة 5: ⭐ بناء روابط المستندات عبر البوّابة الآمنة
//
// لماذا لا نُرجع الرابط المباشر مثل upload/tax_cards/xxx.pdf ؟
//   لأن أي شخص يعرف اسم الملف يفتحه بلا أي تحقق
//   وهذه سجلات تجارية وبطاقات ضريبية
//
// الحل: رابط secure_company_document.php الذي يتحقق من الصلاحية
// ============================================================
// ⭐⭐ دالة مساعدة تبني رابطاً موقَّعاً قصير العمر للمستند
//
// ⚠️ لماذا التوقيع؟
//   المتصفح يفتح رابط PDF مباشرة فلا يستطيع إرسال هيدر X-Admin-Token
//   فكان الرابط القديم يرد 401 دائماً
//
// ⭐ والتوقيع صالح 15 دقيقة فقط ولهذا المستند وحده
//   فسرقته من سجل المتصفح لا تفيد المهاجم
$secureUrl = function (string $type, ?string $fileName) use ($baseUrl, $company_id, $currentAdmin) {

    // إذا لم يكن المستند مرفوعاً نُرجع null
    if (empty($fileName))
        return null;

    // ⭐ قراءة السر المُعرَّف في connect.php
    global $admin_secret_key;

    // ⭐ وقت انتهاء الصلاحية: 15 دقيقة من الآن
    // ⚠️ نستخدم time() لأن الطابع Unix لا يتأثر بالمنطقة الزمنية
    $expires = time() + 900;

    // معرّف الموظف الذي يطلب الرابط
    $adminId = (int) $currentAdmin['admin_id'];

    // ⭐ بناء نص التوقيع بنفس الترتيب المتوقَّع في البوّابة
    // ⚠️ أي اختلاف في الترتيب أو الفواصل يُفشل التحقق
    $payload = $type . '|' . $company_id . '|' . $adminId . '|' . $expires;

    // حساب التوقيع بالسر
    $sig = hash_hmac('sha256', $payload, $admin_secret_key);

    // ⭐ بناء الرابط الكامل مع التوقيع
    // ⚠️ لا نُمرّر اسم الملف حتى لا يُستخدم للتخمين
    return $baseUrl . '/companies/secure_company_document.php'
        . '?type=' . urlencode($type)
        . '&company_id=' . urlencode($company_id)
        . '&aid=' . $adminId
        . '&exp=' . $expires
        . '&sig=' . $sig;
};

// ============================================================
// الخطوة 6: حساب مدة انتظار أقدم طلب معلَّق
//
// ⭐ الساعات جاءت محسوبة من MySQL في الاستعلام أعلاه
//    فنأخذ أكبر قيمة بين الطلبات المعلّقة (أي أقدمها)
//
// ⚠️ لا نستخدم strtotime/time إطلاقاً
//    لأن توقيت PHP (Europe/Berlin) يختلف عن توقيت MySQL (Africa/Cairo)
//    وحسابها في PHP أعطى ساعات سالبة (-3) فتعطّل تنبيه التأخير
// ============================================================
// متغير يحمل أكبر عدد ساعات انتظار بين الطلبات المعلّقة
$waitingHours = 0;

// نمرّ على كل الطلبات لنجد أطولها انتظاراً
foreach ($jobs as $j) {
    // نتجاهل الطلبات التي تمت مراجعتها
    if ($j['required_approval_status'] !== 'pending')
        continue;
    // قراءة ساعات هذا الطلب مع حماية من القيم السالبة
    $h = max(0, (int) ($j['waiting_hours'] ?? 0));
    // نحفظ الأكبر لأنه الأقدم
    if ($h > $waitingHours)
        $waitingHours = $h;
}

// ============================================================
// الخطوة 7: تحويل طلبات العمالة لشكل مناسب للواجهة
// ============================================================
$jobsData = array_map(function ($j) {
    // إرجاع مصفوفة الطلب بالشكل الذي تقرأه الواجهة
    return [
        // معرّف الطلب
        "required_id" => (int) $j['required_id'],
        // اسم المهنة المطلوبة
        "required_job_title" => $j['required_job_title'],
        // شرح المواصفات
        "required_job_description" => $j['required_job_description'],
        // حالة توثيق العامل
        "required_is_verified_worker" => $j['required_is_verified_worker'],
        // ⭐ ترجمة حالة التوثيق للعربية
        "verification_ar" => $j['required_is_verified_worker'] === 'verified'
            ? 'موثق' : 'غير موثق',
        // عدد العمال المطلوبين
        "required_workers_count" => (int) $j['required_workers_count'],
        // تاريخ ووقت بدء العمل
        "required_start_date_time" => $j['required_start_date_time'],
        // حالة الموافقة
        "required_approval_status" => $j['required_approval_status'],
        // ⭐ ترجمة حالة الموافقة للعربية
        "approval_status_ar" => match ($j['required_approval_status']) {
            'pending' => 'قيد المراجعة',
            'approved' => 'مقبول',
            'rejected' => 'مرفوض',
            default => 'غير محدد',
        },
        // سبب الرفض أو ملاحظة الموظف
        "required_review_note" => $j['required_review_note'],
        // تاريخ ووقت المراجعة
        "required_reviewed_at" => $j['required_reviewed_at'],
        // اسم الموظف الذي راجع
        "reviewed_by_name" => $j['reviewed_by_name'],
        // تاريخ إرسال الطلب
        "required_created_at" => $j['required_created_at'],
    ];
}, $jobs);

// ============================================================
// الخطوة 8: حساب إحصائيات طلبات هذه الشركة
// ============================================================
// عدد الطلبات المعلّقة
$pendingJobs = 0;
// عدد الطلبات المقبولة
$approvedJobs = 0;
// عدد الطلبات المرفوضة
$rejectedJobs = 0;
// إجمالي العمال المطلوبين في الطلبات المقبولة
$totalWorkers = 0;

// نمرّ على كل الطلبات ونحسب
foreach ($jobs as $j) {
    // زيادة العدّاد المناسب حسب الحالة
    if ($j['required_approval_status'] === 'pending')
        $pendingJobs++;
    elseif ($j['required_approval_status'] === 'approved')
        $approvedJobs++;
    elseif ($j['required_approval_status'] === 'rejected')
        $rejectedJobs++;

    // ⭐ نجمع العمال المطلوبين في الطلبات المقبولة فقط
    // لأنها الالتزام الفعلي على الإدارة
    if ($j['required_approval_status'] === 'approved') {
        $totalWorkers += (int) $j['required_workers_count'];
    }
}

// ============================================================
// الخطوة 9: إرسال الرد النهائي
// ============================================================
echo json_encode([
    // حالة العملية
    "status" => "success",
    // رسالة نجاح
    "message" => "تم جلب بيانات النشاط بنجاح",
    // بيانات النتيجة مقسّمة لكتل واضحة
    "data" => [

        // ===== 1. بيانات صاحب الحساب =====
        "account" => [
            // معرّف المستخدم
            "users_id" => (int) $company['company_user_id'],
            // الاسم الكامل
            "users_full_name" => $company['users_full_name'],
            // ⚠️ رقم الهاتف يظهر للإدارة فقط
            "users_phone" => $company['users_phone'],
            // الدولة المسجّلة عند إنشاء الحساب
            "users_country" => $company['users_country'],
            // المنطقة المسجّلة
            "users_region" => $company['users_region'],
            // نوع الحساب (client أو provider)
            "users_type" => $company['users_type'],
            // هل الحساب مفعّل؟
            "users_is_active" => (int) $company['users_is_active'],
            // هل وافقت الإدارة على الحساب؟
            "users_approve" => (int) $company['users_approve'],
            // تاريخ إنشاء الحساب
            "users_created_at" => $company['users_created_at'],
        ],

        // ===== 2. بيانات النشاط =====
        "company" => [
            // معرّف الشركة
            "company_id" => (int) $company['company_id'],
            // نوع النشاط كما هو مخزَّن
            "company_business_type" => $company['company_business_type'],
            // ⭐ ترجمة نوع النشاط للعربية
            "business_type_ar" => match ($company['company_business_type']) {
                'company' => 'شركة',
                'factory' => 'مصنع',
                'commercial_activity' => 'نشاط تجاري',
                default => 'غير محدد',
            },
            // اسم النشاط
            "company_name" => $company['company_name'],
            // عنوان المقر
            "company_headquarters_address" => $company['company_headquarters_address'],
            // رقم الهاتف الأساسي
            "company_phone_number" => $company['company_phone_number'],
            // رقم الهاتف الإضافي
            "company_secondary_phone" => $company['company_secondary_phone'],
            // تاريخ التسجيل
            "company_created_at" => $company['company_created_at'],
            // تاريخ آخر تحديث
            "company_updated_at" => $company['company_updated_at'],
        ],

        // ===== 3. المستندات عبر البوّابة الآمنة =====
        "documents" => [
            // ملف السجل التجاري
            "commercial_register" => [
                // هل الملف مرفوع؟
                "exists" => !empty($company['company_commercial_register_pdf']),
                // رابط البوّابة الآمنة (null إن لم يُرفع)
                "url" => $secureUrl('commercial_register', $company['company_commercial_register_pdf']),
            ],
            // ملف البطاقة الضريبية
            "tax_card" => [
                // هل الملف مرفوع؟
                "exists" => !empty($company['company_tax_card_pdf']),
                // رابط البوّابة الآمنة (null إن لم يُرفع)
                "url" => $secureUrl('tax_card', $company['company_tax_card_pdf']),
            ],
            // ⭐ هل المستندان مرفوعان معاً؟
            // (كلاهما اختياري فهذا للعلم لا للمنع)
            "is_complete" => !empty($company['company_commercial_register_pdf'])
                && !empty($company['company_tax_card_pdf']),
        ],

        // ===== 4. طلبات العمالة الخاصة بهذه الشركة =====
        "job_requests" => $jobsData,

        // ===== 5. إحصائيات سريعة =====
        "stats" => [
            // إجمالي عدد الطلبات
            "total_requests" => count($jobs),
            // عدد الطلبات المعلّقة
            "pending_requests" => $pendingJobs,
            // عدد الطلبات المقبولة
            "approved_requests" => $approvedJobs,
            // عدد الطلبات المرفوضة
            "rejected_requests" => $rejectedJobs,
            // ⭐ إجمالي العمال المطلوبين في الطلبات المقبولة
            "approved_workers_total" => $totalWorkers,
            // عدد ساعات انتظار أقدم طلب معلَّق
            "waiting_hours" => $waitingHours,
            // ⭐ هل تجاوز الطلب المهلة المعلنة (24 ساعة)؟
            "is_overdue" => $waitingHours > 24,
        ],
    ],
]);
?>