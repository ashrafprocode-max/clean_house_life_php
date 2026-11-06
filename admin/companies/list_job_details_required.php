<?php
// ============================================================
// ملف: admin/companies/list_job_details_required.php
// الغرض: طابور مراجعة طلبات العمالة اليومي للإدارة
//        قائمة مسطَّحة: كل طلب صف مستقل والشركة معلومة داخله
//
// ⭐ البحث الموحّد (search) يبحث في ستة حقول معاً:
//   1. اسم المهنة المطلوبة   → required_job_title
//   2. شرح المواصفات         → required_job_description
//   3. اسم الشركة            → company_name
//   4. هاتف الشركة           → company_phone_number + secondary
//   5. اسم العميل صاحب الشركة → users_full_name
//   6. هاتف العميل           → users_phone
//
// ⭐ الفلاتر:
//   status             → pending / approved / rejected / all
//   business_type      → company / factory / commercial_activity / all
//   is_verified_worker → verified / unverified / all
//   overdue            → yes (المعلّقة أكثر من 24 ساعة فقط)
//   start_passed       → yes (فات موعد بدء عملها وما زالت معلّقة)
//   sort               → oldest (افتراضي) / newest / start_date / workers
//
// الطريقة: POST أو GET
// ============================================================

// تضمين ملف الاتصال (مستويان للوراء لأن الملف داخل admin/companies)
include "../../connect.php";

// ⭐ التحقق من توكن الإدارة — أي موظف مسجّل دخوله يستطيع القراءة
$currentAdmin = checkAdminToken();

// الحماية الحقيقية على السيرفر وليست في واجهة التطبيق فقط
requirePermission($currentAdmin, 'jobs.view');

// ============================================================
// الخطوة 1: استقبال معاملات البحث والفلترة
// ============================================================
// حالة الموافقة المطلوبة
$status = filterRequest("status");
// نص البحث الموحّد
$search = trim(filterRequest("search"));
// فلتر نوع النشاط
$businessType = filterRequest("business_type");
// فلتر حالة توثيق العامل المطلوب
$verifiedWork = filterRequest("is_verified_worker");
// فلتر الطلبات المتأخرة عن المهلة
$overdueOnly = filterRequest("overdue");
// فلتر الطلبات التي فات موعد بدء عملها
$startPassed = filterRequest("start_passed");
// ترتيب النتائج
$sort = filterRequest("sort");

// ============================================================
// الخطوة 2: معاملات Pagination
// max() و min() لضمان قيم منطقية دائماً
// ============================================================
// رقم الصفحة الحالية - لا تقل عن 1
$page = max(1, (int) (filterRequest("page") ?: 1));
// عدد النتائج - لا تقل عن 1 ولا تزيد عن 50
$limit = min(50, max(1, (int) (filterRequest("limit") ?: 20)));
// نقطة البداية في قاعدة البيانات
// مثال: page=2, limit=20 → offset=20 (ابدأ من السجل 21)
$offset = ($page - 1) * $limit;

// ============================================================
// الخطوة 3: بناء شروط WHERE المشتركة بين الاستعلامين
// ============================================================
// نبدأ بشرط ثابت دائماً صحيح لتبسيط دمج الشروط بعده
$whereConditions = ["1 = 1"];
// مصفوفة معاملات الاستعلام الرئيسي
$params = [];
// مصفوفة معاملات استعلام العدد الإجمالي
$countParams = [];

// ============================================================
// الخطوة 4: فلتر حالة الموافقة
// ============================================================
// قائمة الحالات المسموحة (نفس قيم الـ ENUM حرفياً)
$allowedStatus = ['pending', 'approved', 'rejected'];

// نُضيف الشرط فقط إذا كانت الحالة معروفة (all تعني بلا فلترة)
if ($status !== '' && in_array($status, $allowedStatus, true)) {
    // إضافة شرط الحالة
    $whereConditions[] = "j.required_approval_status = ?";
    // تمرير القيمة للاستعلامين
    $params[] = $status;
    $countParams[] = $status;
}

// ============================================================
// الخطوة 5: ⭐ البحث الموحّد في ستة حقول معاً
//
// ⚠️ الأقواس حول شروط OR إجبارية
//    وإلا تعارض OR مع شروط AND الأخرى وأعطى نتائج خاطئة
// ============================================================
// نُضيف الشرط فقط إذا أرسلت الواجهة نصاً للبحث
if ($search !== '') {
    // بناء شرط البحث في سبعة حقول بـ OR
    $whereConditions[] = "("
        // اسم المهنة المطلوبة
        . " j.required_job_title LIKE ?"
        // شرح مواصفات العامل
        . " OR j.required_job_description LIKE ?"
        // اسم الشركة
        . " OR c.company_name LIKE ?"
        // هاتف الشركة الأساسي
        . " OR c.company_phone_number LIKE ?"
        // هاتف الشركة الإضافي
        . " OR c.company_secondary_phone LIKE ?"
        // اسم العميل صاحب الشركة
        . " OR u.users_full_name LIKE ?"
        // هاتف العميل
        . " OR u.users_phone LIKE ?"
        . ")";

    // تجهيز نص البحث مع % قبله وبعده للبحث الجزئي
    $searchTerm = "%{$search}%";

    // ⭐ نُمرّر النص سبع مرات (مرة لكل حقل بالترتيب)
    for ($i = 0; $i < 7; $i++) {
        // تمرير للاستعلام الرئيسي
        $params[] = $searchTerm;
        // وتمرير لاستعلام العدد الإجمالي
        $countParams[] = $searchTerm;
    }
}

// ============================================================
// الخطوة 6: فلتر نوع النشاط
// ============================================================
// قائمة أنواع النشاط المسموحة (نفس قيم الـ ENUM حرفياً)
$allowedBusinessTypes = ['company', 'factory', 'commercial_activity'];

// نُضيف الشرط فقط إذا كان النوع معروفاً
if ($businessType !== '' && in_array($businessType, $allowedBusinessTypes, true)) {
    // إضافة شرط نوع النشاط
    $whereConditions[] = "c.company_business_type = ?";
    // تمرير القيمة للاستعلامين
    $params[] = $businessType;
    $countParams[] = $businessType;
}

// ============================================================
// الخطوة 7: فلتر حالة توثيق العامل المطلوب
//
// ⭐ مفيد لأن العامل الموثق يحتاج بحثاً أطول من الإدارة
// ============================================================
// قائمة القيم المسموحة (نفس قيم الـ ENUM حرفياً)
$allowedVerification = ['verified', 'unverified'];

// نُضيف الشرط فقط إذا كانت القيمة معروفة
if ($verifiedWork !== '' && in_array($verifiedWork, $allowedVerification, true)) {
    // إضافة شرط حالة التوثيق
    $whereConditions[] = "j.required_is_verified_worker = ?";
    // تمرير القيمة للاستعلامين
    $params[] = $verifiedWork;
    $countParams[] = $verifiedWork;
}

// ============================================================
// الخطوة 8: ⭐ فلتر الطلبات المتأخرة عن المهلة المعلنة
//
// ⚠️ نستخدم TIMESTAMPDIFF داخل MySQL لا حساباً في PHP
//    لأن توقيت PHP (php.ini) يختلف عن توقيت MySQL (نظام السيرفر)
//    وحساب الفرق في PHP أنتج ساعات سالبة فتعطّل الفلتر تماماً
// ============================================================
// نُضيف الشرط فقط إذا طُلبت الطلبات المتأخرة
if ($overdueOnly === 'yes') {
    // شرط: معلَّق وتجاوز 24 ساعة
    $whereConditions[] = "("
        . " j.required_approval_status = 'pending'"
        . " AND TIMESTAMPDIFF(HOUR, j.required_created_at, NOW()) > 24"
        . ")";
}

// ============================================================
// الخطوة 9: ⭐⭐ فلتر الطلبات التي فات موعد بدء عملها
//
// هذا أخطر تنبيه في اللوحة: شركة تنتظر عمالاً لموعد قد فات
// والطلب ما زال معلَّقاً بلا قرار
// ============================================================
// نُضيف الشرط فقط إذا طُلبت هذه الطلبات
if ($startPassed === 'yes') {
    // شرط: معلَّق وموعد البدء في الماضي
    $whereConditions[] = "("
        . " j.required_approval_status = 'pending'"
        . " AND j.required_start_date_time < NOW()"
        . ")";
}

// دمج كل الشروط في جملة WHERE واحدة مفصولة بـ AND
$whereSQL = "WHERE " . implode(" AND ", $whereConditions);

// ============================================================
// الخطوة 10: تحديد ترتيب النتائج
//
// ⚠️ نستخدم قائمة ثابتة (whitelist) لا نص المستخدم مباشرة
//    وإلا فتحنا باب SQL Injection في جملة ORDER BY
//    (المعاملات المحمية لا تعمل في ORDER BY في MySQL)
// ============================================================
// خريطة الترتيبات المسموحة فقط
$sortMap = [
    // الأقدم إرسالاً أولاً (الافتراضي — الأطول انتظاراً)
    'oldest' => "j.required_created_at ASC",
    // الأحدث إرسالاً أولاً
    'newest' => "j.required_created_at DESC",
    // الأقرب موعد بدء أولاً (الأكثر إلحاحاً)
    'start_date' => "j.required_start_date_time ASC",
    // الأكثر عمالاً أولاً (الأكبر قيمة)
    'workers' => "j.required_workers_count DESC",
];

// قراءة الترتيب المطلوب أو الافتراضي إن كان غير معروف
$orderBy = $sortMap[$sort] ?? $sortMap['oldest'];

// ============================================================
// الخطوة 11: استعلام العدد الإجمالي
// مهم لحساب عدد الصفحات وعرض "صفحة 1 من 5" في الواجهة
// ============================================================
$countSQL = "
    SELECT COUNT(j.required_id) AS total
    FROM job_details_required j
    INNER JOIN company_registering c ON c.company_id = j.required_company_id
    INNER JOIN users u               ON u.users_id    = c.company_user_id
    $whereSQL
";
// تجهيز الاستعلام
$countStmt = $con->prepare($countSQL);
// تنفيذه بمعاملات العدد
$countStmt->execute($countParams);
// قراءة الرقم الناتج
$totalCount = (int) $countStmt->fetchColumn();

// حساب إجمالي الصفحات (نرفع الكسر دائماً)
// مثال: 45 نتيجة ÷ 20 لكل صفحة = 3 صفحات (2.25 → 3)
$totalPages = (int) ceil($totalCount / $limit);

// ============================================================
// الخطوة 12: استعلام الإحصائيات العامة
//
// ⚠️ الإحصائيات على كل الطلبات بلا فلترة
//    لأنها لشارات أزرار الفلترة نفسها
// ============================================================
$statsSQL = "
    SELECT
        -- عدد الطلبات المعلّقة
        SUM(CASE WHEN required_approval_status = 'pending'  THEN 1 ELSE 0 END) AS pending_count,
        -- عدد الطلبات المقبولة
        SUM(CASE WHEN required_approval_status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
        -- عدد الطلبات المرفوضة
        SUM(CASE WHEN required_approval_status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count,
        -- الإجمالي الكلي
        COUNT(required_id) AS all_count,

        -- ⭐ عدد الطلبات المتأخرة عن المهلة (معلّقة أكثر من 24 ساعة)
        SUM(CASE WHEN required_approval_status = 'pending'
                  AND TIMESTAMPDIFF(HOUR, required_created_at, NOW()) > 24
                 THEN 1 ELSE 0 END) AS overdue_count,

        -- ⭐⭐ عدد الطلبات التي فات موعد بدء عملها وما زالت معلّقة
        SUM(CASE WHEN required_approval_status = 'pending'
                  AND required_start_date_time < NOW()
                 THEN 1 ELSE 0 END) AS start_passed_count,

        -- ⭐ إجمالي العمال المطلوبين في الطلبات المعلّقة
        -- (حجم العمل القادم على الإدارة)
        COALESCE(SUM(CASE WHEN required_approval_status = 'pending'
                          THEN required_workers_count ELSE 0 END), 0) AS pending_workers_total,

        -- ⭐ إجمالي العمال في الطلبات المقبولة (الالتزام الفعلي)
        COALESCE(SUM(CASE WHEN required_approval_status = 'approved'
                          THEN required_workers_count ELSE 0 END), 0) AS approved_workers_total,
                          
        -- ⭐ إجمالي المرتبات في الطلبات المعلّقة (حجم الالتزام القادم)
        COALESCE(SUM(CASE WHEN required_approval_status = 'pending'
                          THEN required_monthly_salary * required_workers_count
                          ELSE 0 END), 0) AS pending_salaries_total,

        -- ⭐ إجمالي المرتبات في الطلبات المقبولة (الالتزام الفعلي)
        COALESCE(SUM(CASE WHEN required_approval_status = 'approved'
                          THEN required_monthly_salary * required_workers_count
                          ELSE 0 END), 0) AS approved_salaries_total                  

    FROM job_details_required
";
// تنفيذ استعلام الإحصائيات بلا معاملات
$statsStmt = $con->prepare($statsSQL);
$statsStmt->execute();
// قراءة صف الإحصائيات
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 13: الاستعلام الرئيسي مع LIMIT و OFFSET
//
// ⚠️ لا نُرجع أسماء ملفات مستندات الشركة هنا إطلاقاً
//    نُرجع فقط هل هي موجودة أم لا (لعرض علامة ✓ في القائمة)
// ============================================================
$sql = "
    SELECT
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

        -- ⭐ عدد ساعات انتظار الطلب — محسوبة داخل MySQL
        TIMESTAMPDIFF(HOUR, j.required_created_at, NOW()) AS waiting_hours,

        -- ⭐ عدد الأيام المتبقية حتى موعد بدء العمل (سالبة = فات الموعد)
        TIMESTAMPDIFF(DAY, NOW(), j.required_start_date_time) AS days_until_start,

        -- ===== بيانات الشركة =====
        c.company_business_type,
        c.company_name,
        c.company_headquarters_address,
        c.company_phone_number,
        c.company_secondary_phone,

        -- ⚠️ لا نُرجع أسماء الملفات للأمان، بل وجودها فقط
        CASE WHEN c.company_commercial_register_pdf IS NOT NULL
             AND c.company_commercial_register_pdf <> ''
             THEN 1 ELSE 0 END AS has_commercial_register,
        CASE WHEN c.company_tax_card_pdf IS NOT NULL
             AND c.company_tax_card_pdf <> ''
             THEN 1 ELSE 0 END AS has_tax_card,

        -- ===== بيانات صاحب الحساب =====
        u.users_id,
        u.users_full_name,
        u.users_phone,

        -- ===== اسم الموظف المراجع =====
        au.admin_full_name AS reviewed_by_name

    FROM job_details_required j
    INNER JOIN company_registering c ON c.company_id = j.required_company_id
    INNER JOIN users u               ON u.users_id    = c.company_user_id
    LEFT  JOIN admin_users au        ON au.admin_id   = j.required_reviewed_by
    $whereSQL
    ORDER BY
        -- ⭐ المعلّقة أولاً دائماً لأنها الأهم لطابور المراجعة اليومي
        CASE WHEN j.required_approval_status = 'pending' THEN 0 ELSE 1 END ASC,
        -- ⭐⭐ ثم التي فات موعد بدء عملها (أخطر حالة)
        CASE WHEN j.required_approval_status = 'pending'
              AND j.required_start_date_time < NOW() THEN 0 ELSE 1 END ASC,
        -- ثم الترتيب الذي اختاره الموظف
        $orderBy
    LIMIT $limit OFFSET $offset
";
// تجهيز الاستعلام الرئيسي
$stmt = $con->prepare($sql);
// تنفيذه بمعاملات البيانات
$stmt->execute($params);
// جلب كل الصفوف كمصفوفة
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 14: تحويل كل سجل لشكل مناسب للواجهة
// ============================================================
$resultData = array_map(function ($j) {
    // ساعات الانتظار مع حماية من القيم السالبة
    $waitingHours = max(0, (int) ($j['waiting_hours'] ?? 0));
    // الأيام المتبقية حتى بدء العمل (قد تكون سالبة)
    $daysUntilStart = (int) ($j['days_until_start'] ?? 0);
    // هل الطلب معلَّق؟ (نستخدمه في عدة حسابات)
    $isPending = $j['required_approval_status'] === 'pending';

    // إرجاع مصفوفة السجل بالشكل الذي تقرأه الواجهة
    return [
        // ===== بيانات الطلب =====
        // معرّف الطلب (نحتاجه لفتح صفحة التفاصيل)
        "required_id" => (int) $j['required_id'],
        // معرّف الشركة (نحتاجه لفتح ملف الشركة)
        "required_company_id" => (int) $j['required_company_id'],
        // اسم المهنة أو الوظيفة المطلوبة
        "required_job_title" => $j['required_job_title'],
        // ⭐ مقتطف من شرح المواصفات للعرض في القائمة
        // نقص النص عند 120 حرفاً لأن القائمة لا تحتاج الشرح كاملاً
        "job_description_excerpt" => mb_strlen($j['required_job_description']) > 120
            ? mb_substr($j['required_job_description'], 0, 120) . '…'
            : $j['required_job_description'],
        // حالة توثيق العامل كما هي مخزَّنة
        "required_is_verified_worker" => $j['required_is_verified_worker'],
        // ⭐ ترجمة حالة التوثيق للعربية
        "verification_ar" => $j['required_is_verified_worker'] === 'verified'
            ? 'موثق' : 'غير موثق',
        // عدد العمال المطلوبين
        "required_workers_count" => (int) $j['required_workers_count'],
        // ===== ⭐ شروط العمل (إضافة 2026) =====
        // عدد ساعات العمل اليومية
        "required_daily_work_hours" => (int) $j['required_daily_work_hours'],
        // عدد أيام العمل في الشهر
        "required_monthly_work_days" => (int) $j['required_monthly_work_days'],
        // ⭐ المواعيد بصيغة قصيرة جاهزة للعرض في القائمة
        "shift_time_display" => substr($j['required_shift_start_time'], 0, 5)
            . ' - ' . substr($j['required_shift_end_time'], 0, 5),
        // المرتب الشهري كرقم
        "required_monthly_salary" => (float) $j['required_monthly_salary'],
        // ⭐ المرتب بصيغة منسّقة جاهزة للعرض
        "salary_display" => number_format((float) $j['required_monthly_salary'], 0) . ' ج.م',
        // ⭐ إجمالي المرتبات لكل العمال (أهم رقم في طابور المراجعة)
        "total_salaries" => (float) $j['required_monthly_salary']
            * (int) $j['required_workers_count'],
        // تاريخ ووقت بدء العمل
        "required_start_date_time" => $j['required_start_date_time'],
        // تاريخ إرسال الطلب
        "required_created_at" => $j['required_created_at'],

        // ===== حالة الموافقة =====
        // حالة الموافقة كما هي مخزَّنة
        "required_approval_status" => $j['required_approval_status'],
        // ⭐ ترجمة حالة الموافقة للعربية
        "approval_status_ar" => match ($j['required_approval_status']) {
            'pending' => 'قيد المراجعة',
            'approved' => 'مقبول',
            'rejected' => 'مرفوض',
            default => 'غير محدد',
        },
        // ⭐ لون الشارة المقترح للواجهة (نحدده في السيرفر للاتساق)
        "status_color" => match ($j['required_approval_status']) {
            'pending' => 'warning',
            'approved' => 'success',
            'rejected' => 'danger',
            default => 'muted',
        },

        // ===== ⭐ مؤشرات الإلحاح للموظف =====
        // عدد ساعات انتظار الطلب
        "waiting_hours" => $waitingHours,
        // ⚠️ هل تجاوز الطلب المهلة المعلنة (24 ساعة)؟
        "is_overdue" => $isPending && $waitingHours > 24,
        // عدد الأيام المتبقية حتى بدء العمل
        "days_until_start" => $daysUntilStart,
        // ⚠️⚠️ هل فات موعد بدء العمل والطلب ما زال معلَّقاً؟
        "start_date_passed" => $isPending && $daysUntilStart < 0,
        // ⭐ درجة الإلحاح لترتيب الاهتمام في الواجهة
        // critical = فات الموعد، high = تجاوز المهلة، normal = غير ذلك
        "urgency" => (!$isPending)
            ? 'none'
            : ($daysUntilStart < 0
                ? 'critical'
                : ($waitingHours > 24 ? 'high' : 'normal')),

        // ===== بيانات الشركة (مضمَّنة في كل صف) =====
        "company" => [
            // نوع النشاط كما هو مخزَّن
            "company_business_type" => $j['company_business_type'],
            // ⭐ ترجمة نوع النشاط للعربية
            "business_type_ar" => match ($j['company_business_type']) {
                'company' => 'شركة',
                'factory' => 'مصنع',
                'commercial_activity' => 'نشاط تجاري',
                default => 'غير محدد',
            },
            // اسم النشاط
            "company_name" => $j['company_name'],
            // عنوان المقر
            "company_headquarters_address" => $j['company_headquarters_address'],
            // ⭐ رقم هاتف الشركة ليتصل الموظف مباشرة من القائمة
            "company_phone_number" => $j['company_phone_number'],
            // رقم هاتف الشركة الإضافي
            "company_secondary_phone" => $j['company_secondary_phone'],
            // هل مستندات الشركة مكتملة؟
            "documents_complete" => (bool) $j['has_commercial_register']
                && (bool) $j['has_tax_card'],
        ],

        // ===== بيانات صاحب الحساب (مضمَّنة في كل صف) =====
        "owner" => [
            // معرّف المستخدم
            "users_id" => (int) $j['users_id'],
            // اسم العميل صاحب الشركة
            "users_full_name" => $j['users_full_name'],
            // ⚠️ رقم هاتف العميل يظهر للإدارة فقط
            "users_phone" => $j['users_phone'],
        ],

        // ===== سجل المراجعة =====
        "review" => [
            // هل تمت مراجعة الطلب؟
            "is_reviewed" => !empty($j['required_reviewed_at']),
            // سبب الرفض أو ملاحظة الموظف
            "review_note" => $j['required_review_note'],
            // تاريخ ووقت المراجعة
            "reviewed_at" => $j['required_reviewed_at'],
            // اسم الموظف المراجع
            "reviewed_by_name" => $j['reviewed_by_name'],
        ],
    ];
}, $requests);

// ============================================================
// الخطوة 15: إرسال الرد مع الإحصائيات وبيانات التصفح
// ============================================================
echo json_encode([
    // حالة العملية
    "status" => "success",
    // رسالة نجاح
    "message" => "تم جلب قائمة طلبات العمالة بنجاح",
    // مصفوفة الطلبات
    "data" => $resultData,

    // ===== إحصائيات لشارات أزرار الفلترة والتنبيهات =====
    "stats" => [
        // عدد الطلبات المعلّقة
        "pending_count" => (int) ($stats['pending_count'] ?? 0),
        // عدد الطلبات المقبولة
        "approved_count" => (int) ($stats['approved_count'] ?? 0),
        // عدد الطلبات المرفوضة
        "rejected_count" => (int) ($stats['rejected_count'] ?? 0),
        // الإجمالي الكلي
        "all_count" => (int) ($stats['all_count'] ?? 0),
        // ⭐ عدد الطلبات المتأخرة عن المهلة المعلنة
        "overdue_count" => (int) ($stats['overdue_count'] ?? 0),
        // ⭐⭐ عدد الطلبات التي فات موعد بدء عملها (أخطر تنبيه)
        "start_passed_count" => (int) ($stats['start_passed_count'] ?? 0),
        // ⭐ إجمالي العمال المطلوبين في الطلبات المعلّقة
        "pending_workers_total" => (int) ($stats['pending_workers_total'] ?? 0),
        // ⭐ إجمالي العمال في الطلبات المقبولة (الالتزام الفعلي)
        "approved_workers_total" => (int) ($stats['approved_workers_total'] ?? 0),
        // ⭐ إجمالي المرتبات في الطلبات المعلّقة
        "pending_salaries_total" => (float) ($stats['pending_salaries_total'] ?? 0),
        // ⭐ إجمالي المرتبات في الطلبات المقبولة (الالتزام المالي الفعلي)
        "approved_salaries_total" => (float) ($stats['approved_salaries_total'] ?? 0),
    ],

    // ===== بيانات التصفح =====
    "pagination" => [
        // الصفحة الحالية
        "current_page" => $page,
        // عدد النتائج في الصفحة
        "per_page" => $limit,
        // إجمالي النتائج المطابقة للفلترة
        "total_count" => $totalCount,
        // إجمالي الصفحات
        "total_pages" => $totalPages,
        // Flutter يتوقف عن التحميل عند false
        "has_next_page" => ($page < $totalPages),
        "has_prev_page" => ($page > 1),
    ],

    // ===== الفلاتر المُطبَّقة (لعرضها في الواجهة) =====
    "applied_filters" => [
        // فلتر الحالة
        "status" => $status !== '' ? $status : 'all',
        // نص البحث المُستخدم
        "search" => $search !== '' ? $search : null,
        // فلتر نوع النشاط
        "business_type" => $businessType !== '' ? $businessType : 'all',
        // فلتر حالة توثيق العامل
        "is_verified_worker" => $verifiedWork !== '' ? $verifiedWork : 'all',
        // فلتر الطلبات المتأخرة
        "overdue" => $overdueOnly === 'yes',
        // فلتر الطلبات التي فات موعدها
        "start_passed" => $startPassed === 'yes',
        // الترتيب المُطبَّق
        "sort" => isset($sortMap[$sort]) ? $sort : 'oldest',
    ],
]);
?>