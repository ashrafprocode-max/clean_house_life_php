<?php
// ============================================================
// ملف: admin/companies/view_job_details_required.php
// الغرض: عرض تفاصيل طلب عمالة واحد بالكامل لصفحة مراجعة الإدارة
//
// ⭐ يُرجع أربع كتل:
//   1. request  → تفاصيل الطلب كاملة + مدة الانتظار
//   2. company  → بيانات الشركة الطالبة + مستنداتها
//   3. account  → بيانات صاحب الحساب من جدول users
//   4. review   → سجل المراجعة (من راجع ومتى ولماذا)
//
// ⭐ ويُرجع كذلك sibling_requests: باقي طلبات نفس الشركة
//    ليرى الموظف السياق الكامل بلا طلب شبكة ثانٍ
//
// الحقل الإجباري: required_id
// الطريقة: POST أو GET
// ============================================================

// تضمين ملف الاتصال (مستويان للوراء لأن الملف داخل admin/companies)
include "../../connect.php";

// ⚠️ connect.php يفعّل display_errors فيطبع <br /> قبل JSON
//    وDio يتوقف بـ FormatException عند أول حرف <
ini_set('display_errors', '0');
// تعطيل تنسيق تحذيرات PHP كـ HTML
ini_set('html_errors', '0');
// ابتلاع أي مخرجات سابقة حتى يبقى الرد JSON خالصاً
while (ob_get_level() > 0) {
    // إفراغ الطبقة الحالية دون طباعتها
    ob_end_clean();
}
// بدء مخزن مؤقت لأي تحذير لاحق
ob_start();

// ⭐ التحقق من توكن الإدارة — أي موظف مسجّل دخوله يستطيع القراءة
$currentAdmin = checkAdminToken();

// الحماية الحقيقية على السيرفر وليست في واجهة التطبيق فقط
requirePermission($currentAdmin, 'jobs.view');

// ============================================================
// الخطوة 1: استقبال معرّف الطلب والتحقق منه
// ============================================================
// معرّف طلب العمالة المراد عرضه - إجباري
$required_id = filterRequest("required_id");

// إذا لم يُرسل المعرّف نوقف التنفيذ فوراً
if (empty($required_id)) {
    // تفريغ المخزن حتى لا يسبق HTML رد الفشل
    while (ob_get_level() > 0) {
        // إفراغ الطبقة دون طباعتها
        ob_end_clean();
    }
    // رسالة توضح الحقل المطلوب
    printFailure("الحقل required_id مطلوب");
    // إيقاف التنفيذ
    exit;
}

// تحويل المعرّف لرقم صحيح لحماية جملة WHERE من أي محتوى غير رقمي
$required_id = (int) $required_id;

// ============================================================
// الخطوة 2: جلب الطلب مع بيانات الشركة وصاحبها والمراجع
//
// ⭐ ثلاث وصلات:
//   INNER JOIN company_registering → كل طلب له شركة إجبارياً (FK)
//   INNER JOIN users               → كل شركة لها مالك إجبارياً
//   LEFT  JOIN admin_users         → المراجعة قد لم تحدث بعد
// ============================================================
// تجهيز استعلام محمي لجلب كل البيانات في استعلام واحد
$stmt = $con->prepare(
    "SELECT
        -- ===== بيانات الطلب =====
        j.required_id,
        j.required_company_id,
        j.required_job_title,
        j.required_job_description,
        j.required_is_verified_worker,
        j.required_workers_count,
        j.required_daily_work_hours,
        j.required_monthly_work_days,
        j.required_shift_start_time,
        j.required_shift_end_time,
        j.required_monthly_salary,
        j.required_start_date_time,
        j.required_approval_status,
        j.required_reviewed_by,
        j.required_reviewed_at,
        j.required_review_note,
        j.required_created_at,
        j.required_updated_at,

        -- ⭐ عدد ساعات انتظار الطلب — محسوبة داخل MySQL
        -- ⚠️ توقيت PHP يختلف عن توقيت MySQL فحسابها في PHP يُنتج سالباً
        TIMESTAMPDIFF(HOUR, j.required_created_at, NOW()) AS waiting_hours,

        -- ⭐ عدد الأيام المتبقية حتى موعد بدء العمل
        -- سالبة تعني أن الموعد فات (تنبيه مهم للموظف)
        TIMESTAMPDIFF(DAY, NOW(), j.required_start_date_time) AS days_until_start,

        -- ===== بيانات الشركة =====
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

        -- ===== بيانات صاحب الحساب =====
        u.users_id,
        u.users_full_name,
        u.users_phone,
        u.users_country,
        u.users_region,
        u.users_type,
        u.users_is_active,
        u.users_approve,
        u.users_created_at,

        -- ===== بيانات الموظف المراجع =====
        au.admin_full_name AS reviewed_by_name,
        au.admin_role      AS reviewed_by_role

     FROM job_details_required j
     INNER JOIN company_registering c ON c.company_id = j.required_company_id
     INNER JOIN users u              ON u.users_id    = c.company_user_id
     LEFT  JOIN admin_users au       ON au.admin_id   = j.required_reviewed_by
     WHERE j.required_id = ?
     LIMIT 1"
);
// تنفيذ الاستعلام بتمرير معرّف الطلب
$stmt->execute([$required_id]);
// جلب صف الطلب
$r = $stmt->fetch(PDO::FETCH_ASSOC);

// إذا لم يوجد الطلب نرفض الطلب
if (!$r) {
    // تفريغ المخزن حتى لا يسبق HTML رد الفشل
    while (ob_get_level() > 0) {
        // إفراغ الطبقة دون طباعتها
        ob_end_clean();
    }
    // رسالة توضح أن الطلب غير موجود
    printFailure("طلب العمالة غير موجود", 404);
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 3: جلب باقي طلبات نفس الشركة (السياق)
//
// ⭐ لماذا؟ لأن الموظف قبل أن يوافق يحتاج معرفة
//    هل لهذه الشركة طلبات أخرى مقبولة أو مرفوضة
//    فقد تكون شركة مرفوضة مراراً لسبب متكرر
//
// ⚠️ نستثني الطلب الحالي بـ <> لأنه معروض بالتفصيل أعلاه
// ============================================================
// تجهيز استعلام لجلب طلبات الشركة الأخرى
$stmtSiblings = $con->prepare(
    "SELECT
        required_id,
        required_job_title,
        required_workers_count,
        required_daily_work_hours,
        required_monthly_work_days,
        required_shift_start_time,
        required_shift_end_time,
        required_monthly_salary,
        required_start_date_time,
        required_approval_status,
        required_review_note,
        required_created_at
     FROM job_details_required
     WHERE required_company_id = ?
       AND required_id <> ?
     ORDER BY required_created_at DESC
     LIMIT 20"
);
// تنفيذ الاستعلام بتمرير معرّف الشركة ومعرّف الطلب الحالي
$stmtSiblings->execute([$r['company_id'], $required_id]);
// جلب باقي الطلبات
$siblings = $stmtSiblings->fetchAll(PDO::FETCH_ASSOC);

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
// الخطوة 5: بناء روابط مستندات الشركة عبر البوّابة الآمنة
//
// ⭐ لا رابط مباشر إطلاقاً — البوّابة تتحقق من التوكن أولاً
// ============================================================
// ⭐⭐ دالة مساعدة تبني رابطاً موقَّعاً قصير العمر للمستند
//
// ⚠️ لماذا التوقيع؟
//   المتصفح يفتح رابط PDF مباشرة فلا يستطيع إرسال هيدر X-Admin-Token
//   فكان الرابط القديم يرد 401 دائماً
//
// ⭐ والتوقيع صالح 15 دقيقة فقط ولهذا المستند وحده
//   فسرقته من سجل المتصفح لا تفيد المهاجم
// ⭐ معرّف الشركة كرقم لا كمصفوفة الصف كاملة
// ⚠️ استخدام $r (مصفوفة) هنا يطبع Warning: Array to string conversion
//    فيسبق JSON بـ <br /> ويكسر شاشة المراجعة
$companyIdForDoc = (int) $r['company_id'];

$secureUrl = function (string $type, ?string $fileName) use ($baseUrl, $companyIdForDoc, $currentAdmin) {

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
    $payload = $type . '|' . $companyIdForDoc . '|' . $adminId . '|' . $expires;

    // حساب التوقيع بالسر
    $sig = hash_hmac('sha256', $payload, $admin_secret_key);

    // ⭐ بناء الرابط الكامل مع التوقيع
    // ⚠️ لا نُمرّر اسم الملف حتى لا يُستخدم للتخمين
    return $baseUrl . '/companies/secure_company_document.php'
        . '?type=' . urlencode($type)
        . '&company_id=' . urlencode((string) $companyIdForDoc)
        . '&aid=' . $adminId
        . '&exp=' . $expires
        . '&sig=' . $sig;
};

// ============================================================
// الخطوة 6: قراءة القيم الزمنية المحسوبة من MySQL
// ============================================================
// ساعات الانتظار مع حماية من القيم السالبة
$waitingHours = max(0, (int) ($r['waiting_hours'] ?? 0));

// الأيام المتبقية حتى بدء العمل (قد تكون سالبة إذا فات الموعد)
$daysUntilStart = (int) ($r['days_until_start'] ?? 0);

// ============================================================
// الخطوة 7: ترجمة القيم للعربية
// ============================================================
// ترجمة حالة الموافقة
$statusAr = match ($r['required_approval_status']) {
    'pending' => 'قيد المراجعة',
    'approved' => 'مقبول',
    'rejected' => 'مرفوض',
    default => 'غير محدد',
};

// ترجمة نوع النشاط
$businessTypeAr = match ($r['company_business_type']) {
    'company' => 'شركة',
    'factory' => 'مصنع',
    'commercial_activity' => 'نشاط تجاري',
    default => 'غير محدد',
};

// ============================================================
// الخطوة 8: تحويل طلبات الشركة الأخرى لشكل مناسب للواجهة
// ============================================================
$siblingsData = array_map(function ($s) {
    // إرجاع ملخّص كل طلب آخر
    return [
        // معرّف الطلب
        "required_id" => (int) $s['required_id'],
        // اسم المهنة
        "required_job_title" => $s['required_job_title'],
        // عدد العمال
        "required_workers_count" => (int) $s['required_workers_count'],
        // ===== ⭐ شروط العمل (إضافة 2026) =====
        // عدد ساعات العمل اليومية
        "required_daily_work_hours" => (int) $s['required_daily_work_hours'],
        // عدد أيام العمل في الشهر
        "required_monthly_work_days" => (int) $s['required_monthly_work_days'],
        // ساعة بداية العمل
        "required_shift_start_time" => $s['required_shift_start_time'],
        // ساعة نهاية العمل
        "required_shift_end_time" => $s['required_shift_end_time'],
        // ⭐ المواعيد بصيغة قصيرة جاهزة للعرض
        "shift_time_display" => substr((string) $s['required_shift_start_time'], 0, 5)
            . ' - ' . substr((string) $s['required_shift_end_time'], 0, 5),
        // المرتب الشهري كرقم
        "required_monthly_salary" => (float) $s['required_monthly_salary'],
        // ⭐ المرتب بصيغة منسّقة جاهزة للعرض
        "salary_display" => number_format((float) $s['required_monthly_salary'], 0) . ' ج.م',
        // ⭐ إجمالي المرتبات لكل العمال (أهم رقم للإدارة)
        "total_salaries" => (float) $s['required_monthly_salary']
            * (int) $s['required_workers_count'],
        // ⭐ إجمالي ساعات العمل الشهرية للعامل الواحد
        "monthly_total_hours" => (int) $s['required_daily_work_hours']
            * (int) $s['required_monthly_work_days'],
        // ⭐ متوسط أجر الساعة (مؤشر عدالة الأجر للمراجعة)
        "hourly_rate" => ((int) $s['required_daily_work_hours']
            * (int) $s['required_monthly_work_days']) > 0
            ? round((float) $s['required_monthly_salary']
                / ((int) $s['required_daily_work_hours']
                    * (int) $s['required_monthly_work_days']), 2)
            : 0,
        // تاريخ بدء العمل
        "required_start_date_time" => $s['required_start_date_time'],
        // حالة الموافقة
        "required_approval_status" => $s['required_approval_status'],
        // ⭐ ترجمة الحالة للعربية
        "approval_status_ar" => match ($s['required_approval_status']) {
            'pending' => 'قيد المراجعة',
            'approved' => 'مقبول',
            'rejected' => 'مرفوض',
            default => 'غير محدد',
        },
        // ملاحظة المراجعة (مفيدة لرؤية نمط الرفض المتكرر)
        "required_review_note" => $s['required_review_note'],
        // تاريخ الإرسال
        "required_created_at" => $s['required_created_at'],
    ];
}, $siblings);

// ============================================================
// الخطوة 9: إرسال الرد النهائي
// ============================================================
// تفريغ أي تحذير تسرب للمخزن حتى لا يسبق JSON حرف <
while (ob_get_level() > 0) {
    // إفراغ الطبقة دون طباعتها
    ob_end_clean();
}

echo json_encode([
    // حالة العملية
    "status" => "success",
    // رسالة نجاح
    "message" => "تم جلب تفاصيل طلب العمالة بنجاح",
    // بيانات النتيجة مقسّمة لكتل واضحة
    "data" => [

        // ===== 1. تفاصيل الطلب =====
        "request" => [
            // معرّف الطلب
            "required_id" => (int) $r['required_id'],
            // معرّف الشركة الطالبة
            "required_company_id" => (int) $r['required_company_id'],
            // اسم المهنة أو الوظيفة المطلوبة
            "required_job_title" => $r['required_job_title'],
            // شرح مواصفات ومهام العامل
            "required_job_description" => $r['required_job_description'],
            // حالة توثيق العامل كما هي مخزَّنة
            "required_is_verified_worker" => $r['required_is_verified_worker'],
            // ⭐ ترجمة حالة التوثيق للعربية
            "verification_ar" => $r['required_is_verified_worker'] === 'verified'
                ? 'موثق' : 'غير موثق',
            // عدد العمال المطلوبين
            "required_workers_count" => (int) $r['required_workers_count'],
            // عدد ساعات العمل اليومية
            "required_daily_work_hours" => (int) $r['required_daily_work_hours'],
            // عدد أيام العمل في الشهر
            "required_monthly_work_days" => (int) $r['required_monthly_work_days'],
            // ساعة بداية العمل
            "required_shift_start_time" => $r['required_shift_start_time'],
            // ساعة نهاية العمل
            "required_shift_end_time" => $r['required_shift_end_time'],
            // المواعيد بصيغة قصيرة جاهزة للعرض
            "shift_time_display" => substr((string) $r['required_shift_start_time'], 0, 5)
                . ' - ' . substr((string) $r['required_shift_end_time'], 0, 5),
            // المرتب الشهري كرقم
            "required_monthly_salary" => (float) $r['required_monthly_salary'],
            // المرتب بصيغة منسّقة جاهزة للعرض
            "salary_display" => number_format((float) $r['required_monthly_salary'], 0) . ' ج.م',
            // إجمالي المرتبات لكل العمال
            "total_salaries" => (float) $r['required_monthly_salary']
                * (int) $r['required_workers_count'],
            // إجمالي ساعات العمل الشهرية للعامل الواحد
            "monthly_total_hours" => (int) $r['required_daily_work_hours']
                * (int) $r['required_monthly_work_days'],
            // متوسط أجر الساعة
            "hourly_rate" => ((int) $r['required_daily_work_hours']
                * (int) $r['required_monthly_work_days']) > 0
                ? round((float) $r['required_monthly_salary']
                    / ((int) $r['required_daily_work_hours']
                        * (int) $r['required_monthly_work_days']), 2)
                : 0,
            // تاريخ ووقت بدء العمل
            "required_start_date_time" => $r['required_start_date_time'],
            // حالة الموافقة كما هي مخزَّنة
            "required_approval_status" => $r['required_approval_status'],
            // ⭐ ترجمة حالة الموافقة للعربية
            "approval_status_ar" => $statusAr,
            // لون الشارة المقترح للواجهة
            "status_color" => match ($r['required_approval_status']) {
                'pending' => 'warning',
                'approved' => 'success',
                'rejected' => 'danger',
                default => 'muted',
            },
            // سبب الرفض أو ملاحظة الموظف
            "required_review_note" => $r['required_review_note'],
            // تاريخ ووقت المراجعة
            "required_reviewed_at" => $r['required_reviewed_at'],
            // تاريخ إرسال الطلب
            "required_created_at" => $r['required_created_at'],
            // تاريخ آخر تحديث
            "required_updated_at" => $r['required_updated_at'],

            // ===== ⭐ مؤشرات زمنية للموظف =====
            // عدد ساعات انتظار الطلب
            "waiting_hours" => $waitingHours,
            // ⚠️ هل تجاوز الطلب المهلة المعلنة (24 ساعة)؟
            "is_overdue" => $waitingHours > 24
                && $r['required_approval_status'] === 'pending',
            // عدد الأيام المتبقية حتى بدء العمل
            "days_until_start" => $daysUntilStart,
            // ⚠️⚠️ هل فات موعد بدء العمل والطلب ما زال معلَّقاً؟
            // هذا أخطر تنبيه: الشركة تنتظر عمالاً لموعد قد فات
            "start_date_passed" => $daysUntilStart < 0
                && $r['required_approval_status'] === 'pending',
            // درجة الإلحاح
            "urgency" => ($r['required_approval_status'] !== 'pending')
                ? 'none'
                : ($daysUntilStart < 0
                    ? 'critical'
                    : ($waitingHours > 24 ? 'high' : 'normal')),
        ],

        // ===== 2. بيانات الشركة الطالبة =====
        "company" => [
            // معرّف الشركة
            "company_id" => (int) $r['company_id'],
            // نوع النشاط كما هو مخزَّن
            "company_business_type" => $r['company_business_type'],
            // ⭐ ترجمة نوع النشاط للعربية
            "business_type_ar" => $businessTypeAr,
            // اسم النشاط
            "company_name" => $r['company_name'],
            // عنوان المقر
            "company_headquarters_address" => $r['company_headquarters_address'],
            // ⭐ رقم هاتف الشركة ليتواصل الموظف معها مباشرة
            "company_phone_number" => $r['company_phone_number'],
            // رقم هاتف الشركة الإضافي
            "company_secondary_phone" => $r['company_secondary_phone'],
            // هل مستندات الشركة مكتملة؟
            "documents_complete" => !empty($r['company_commercial_register_pdf'])
                && !empty($r['company_tax_card_pdf']),
            // تاريخ تسجيل الشركة
            "company_created_at" => $r['company_created_at'],
        ],

        // ===== 3. مستندات الشركة عبر البوّابة الآمنة =====
        "documents" => [
            // ملف السجل التجاري
            "commercial_register" => [
                // هل الملف مرفوع؟
                "exists" => !empty($r['company_commercial_register_pdf']),
                // رابط البوّابة الآمنة (null إن لم يُرفع)
                "url" => $secureUrl('commercial_register', $r['company_commercial_register_pdf']),
            ],
            // ملف البطاقة الضريبية
            "tax_card" => [
                // هل الملف مرفوع؟
                "exists" => !empty($r['company_tax_card_pdf']),
                // رابط البوّابة الآمنة (null إن لم يُرفع)
                "url" => $secureUrl('tax_card', $r['company_tax_card_pdf']),
            ],
            // ⭐ هل المستندان مرفوعان معاً؟
            "is_complete" => !empty($r['company_commercial_register_pdf'])
                && !empty($r['company_tax_card_pdf']),
        ],

        // ===== 4. بيانات صاحب الحساب =====
        "account" => [
            // معرّف المستخدم
            "users_id" => (int) $r['users_id'],
            // الاسم الكامل
            "users_full_name" => $r['users_full_name'],
            // ⚠️ رقم الهاتف يظهر للإدارة فقط
            "users_phone" => $r['users_phone'],
            // الدولة المسجّلة عند إنشاء الحساب
            "users_country" => $r['users_country'],
            // المنطقة المسجّلة
            "users_region" => $r['users_region'],
            // نوع الحساب
            "users_type" => $r['users_type'] ?? null,
            // هل الحساب مفعّل؟
            "users_is_active" => (int) $r['users_is_active'],
            // هل وافقت الإدارة على الحساب؟
            "users_approve" => isset($r['users_approve']) ? (int) $r['users_approve'] : null,
            // تاريخ إنشاء الحساب
            "users_created_at" => $r['users_created_at'],
        ],

        // ===== 5. سجل المراجعة =====
        "review" => [
            // هل تمت مراجعة الطلب؟
            "is_reviewed" => !empty($r['required_reviewed_at']),
            // سبب الرفض أو ملاحظة الموظف
            "review_note" => $r['required_review_note'],
            // تاريخ ووقت المراجعة
            "reviewed_at" => $r['required_reviewed_at'],
            // معرّف الموظف المراجع
            "reviewed_by_id" => $r['required_reviewed_by']
                ? (int) $r['required_reviewed_by'] : null,
            // اسم الموظف المراجع
            "reviewed_by_name" => $r['reviewed_by_name'],
            // دور الموظف المراجع
            "reviewed_by_role" => $r['reviewed_by_role'],
            // ⭐ ترجمة الدور للعربية
            "reviewed_by_role_ar" => $r['reviewed_by_role'] === 'manager'
                ? 'مدير' : ($r['reviewed_by_role'] ? 'خدمة عملاء' : null),
        ],

        // ===== 6. باقي طلبات نفس الشركة (السياق) =====
        "sibling_requests" => $siblingsData,
        // عدد طلبات الشركة الأخرى
        "sibling_count" => count($siblingsData),

        // ===== 7. الإجراءات المتاحة للموظف الحالي =====
        // ⭐ نُرسلها من السيرفر لتُخفي الواجهة الأزرار غير المتاحة
        // ⚠️ هذا لتحسين التجربة فقط، والحماية الحقيقية في PHP
        "available_actions" => [
            // القبول متاح إن لم يكن مقبولاً بالفعل
            "can_approve" => $r['required_approval_status'] !== 'approved',
            // الرفض متاح إن لم يكن مرفوضاً بالفعل
            "can_reject" => $r['required_approval_status'] !== 'rejected',
            // الإرجاع للانتظار متاح إن كان قد رُوجع فعلاً
            "can_pending" => $r['required_approval_status'] !== 'pending',
            // التعديل متاح دائماً في الدرجة "ج"
            "can_edit" => true,
        ],
    ],
]);
?>