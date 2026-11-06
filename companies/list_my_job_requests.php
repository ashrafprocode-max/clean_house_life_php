<?php
// ============================================================
// ملف: companies/list_my_job_requests.php
// الغرض: تغذية بطاقة الشركات في home_tap وقائمة طلبات صاحب الشركة
//
// ⭐⭐ الفكرة الأساسية: السيرفر يُقرّر ماذا تعرض البطاقة
//
//   box_state = "offer"    → لم يُسجّل نشاطاً بعد
//                            نعرض بوكس العرض الترويجي وزر "أوافق"
//
//   box_state = "no_request" → سجّل نشاطاً ولم يُرسل طلب عمالة
//                            نعرض بطاقة "أكمل طلبك"
//
//   box_state = "pending"  → لديه طلب قيد المراجعة
//                            نعرض بطاقة "طلباتك: قيد المراجعة"
//
//   box_state = "approved" → طلبه مقبول
//                            نعرض بطاقة "تم قبول طلبك"
//
//   box_state = "rejected" → طلبه مرفوض
//                            نعرض بطاقة "طلبك مرفوض — اضغط للتصحيح"
//
// ⚠️ لماذا نحسبها في السيرفر لا في فلاتر؟
//   لأن تغيير منطق العرض لاحقاً لا يحتاج تحديث التطبيق في المتاجر
//   ولأن نفس المنطق سيُستخدم في الويب وويندوز بلا تكرار
//
// ⭐ الرد خفيف مقصوداً لأنه يُطلب في كل مرة تُفتح الرئيسية
//
// الحقل الإجباري: company_user_id
// الحقول الاختيارية: status (للفلترة) / limit (لعدد الطلبات)
// الطريقة: POST أو GET
// ============================================================

// تضمين ملف الاتصال بقاعدة البيانات والدوال المساعدة
include "../connect.php";

// ============================================================
// الخطوة 1: استقبال البيانات
// ============================================================
// معرّف المستخدم صاحب النشاط - إجباري
$user_id = filterRequest("company_user_id");
// فلتر حالة الطلبات - اختياري
$status  = filterRequest("status");

// ============================================================
// الخطوة 2: التحقق من الحقل الإجباري
// ============================================================
// إذا لم يُرسل معرّف المستخدم نوقف التنفيذ
if (empty($user_id)) {
    // رسالة توضح أن التوثيق مطلوب
    printFailure("الحقل company_user_id مطلوب", 401);
    // إيقاف التنفيذ
    exit;
}

// تحويل معرّف المستخدم لرقم صحيح لحماية جملة WHERE
$user_id = (int) $user_id;

// عدد الطلبات المطلوب جلبها (لا يقل عن 1 ولا يزيد عن 50)
$limit = min(50, max(1, (int)(filterRequest("limit") ?: 20)));

// ⚠️⚠️ تنبيه أمني مهم:
//   هذا الملف يثق في company_user_id القادم من الطلب
//   لأن checkAuthenticate() معطّلة في connect.php (السطر ~138)
//   فيستطيع أي شخص تمرير معرّف مستخدم آخر ورؤية طلبات شركته
//   والحل الجذري هو تفعيل توكن المستخدم (مؤجَّل بطلبك)

// ============================================================
// الخطوة 3: هل سجّل هذا المستخدم نشاطاً؟
//
// ⭐ هذا أول سؤال يُحدّد حالة البطاقة كلها
// ============================================================
// تجهيز استعلام محمي للبحث عن نشاط هذا المستخدم
$stmtCompany = $con->prepare(
    "SELECT
        company_id,
        company_business_type,
        company_name,
        company_phone_number,
        company_created_at
     FROM company_registering
     WHERE company_user_id = ?
     LIMIT 1"
);
// تنفيذ الاستعلام بتمرير معرّف المستخدم
$stmtCompany->execute([$user_id]);
// جلب صف الشركة إن وُجد
$company = $stmtCompany->fetch(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 4: ⭐ الحالة الأولى — لم يُسجّل نشاطاً بعد
//
// نُرجع رداً ناجحاً بـ box_state = offer
// فيعرض التطبيق بوكس العرض الترويجي وزر "أوافق"
// ============================================================
// إذا لم توجد شركة نُرجع حالة العرض الترويجي
if (!$company) {
    // رد ناجح يوضح أن البطاقة يجب أن تعرض العرض
    echo json_encode([
        // حالة العملية ناجحة (عدم وجود نشاط ليس خطأ)
        "status"  => "success",
        // رسالة توضيحية
        "message" => "لا يوجد نشاط مسجّل لهذا الحساب",
        // بيانات النتيجة
        "data"    => [
            // ⭐ حالة البطاقة: اعرض العرض الترويجي
            "box_state"    => "offer",
            // لا يوجد نشاط مسجّل
            "has_company"  => false,
            // لا توجد طلبات
            "requests"     => [],
            // ⭐ الشاشة التي تُفتح عند الضغط على الزر
            "target_screen" => "company_registering",
            // ⭐ نص العنوان الجاهز للعرض في البطاقة
            "box_title"    => "هل أنت صاحب شركة أو مصنع؟",
            // ⭐ نص الوصف الجاهز للعرض
            "box_message"  => "لو لديك شركة أو مصنع أو أي نشاط تجاري وتحتاج عمالاً أو موظفين، لدينا عروض خاصة لك",
            // ⭐ نص الزر الجاهز للعرض
            "box_action"   => "أوافق",
            // ⭐ لون البطاقة المقترح
            "box_color"    => "primary",
            // ⭐ أيقونة البطاقة المقترحة
            "box_icon"     => "business",
        ],
    ]);
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 5: بناء شرط فلترة الطلبات
// ============================================================
// الشرط الأساسي: طلبات هذه الشركة فقط
$whereSQL = "required_company_id = ?";
// مصفوفة المعاملات
$params   = [$company['company_id']];

// قائمة الحالات المسموحة (نفس قيم الـ ENUM حرفياً)
$allowedStatus = ['pending', 'approved', 'rejected'];

// نُضيف فلتر الحالة إن أُرسل وكان معروفاً
if ($status !== '' && in_array($status, $allowedStatus, true)) {
    // إضافة شرط الحالة
    $whereSQL .= " AND required_approval_status = ?";
    // تمرير القيمة
    $params[] = $status;
}

// ============================================================
// الخطوة 6: جلب طلبات العمالة الخاصة بهذه الشركة
//
// ⚠️ لا نجلب required_reviewed_by ولا اسم الموظف
//    لأن هوية الموظف المراجع شأن داخلي لا يخص الشركة
// ============================================================
// تجهيز استعلام محمي لجلب الطلبات
$stmtJobs = $con->prepare(
    "SELECT
        required_id,
        required_job_title,
        required_job_description,
        required_is_verified_worker,
        required_workers_count,
        required_daily_work_hours,
        required_monthly_work_days,
        required_shift_start_time,
        required_shift_end_time,
        required_monthly_salary,
        required_start_date_time,
        required_approval_status,
        required_review_note,
        required_reviewed_at,
        required_created_at,

        -- ⭐ عدد ساعات انتظار الطلب — محسوبة داخل MySQL
        -- ⚠️ توقيت PHP يختلف عن توقيت MySQL فحسابها في PHP يُنتج سالباً
        TIMESTAMPDIFF(HOUR, required_created_at, NOW()) AS waiting_hours,

        -- ⭐ عدد الأيام المتبقية حتى موعد بدء العمل
        TIMESTAMPDIFF(DAY, NOW(), required_start_date_time) AS days_until_start

     FROM job_details_required
     WHERE $whereSQL
     ORDER BY
        -- ⭐ المعلّقة أولاً لأنها ما ينتظره صاحب الشركة
        CASE WHEN required_approval_status = 'pending' THEN 0 ELSE 1 END ASC,
        -- ثم الأحدث إرسالاً
        required_created_at DESC
     LIMIT $limit"
);
// تنفيذ الاستعلام بتمرير المعاملات
$stmtJobs->execute($params);
// جلب كل الطلبات
$jobs = $stmtJobs->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 7: حساب إحصائيات الطلبات
//
// ⚠️ نحسبها باستعلام مستقل بلا فلتر الحالة
//    لأن الإحصائيات يجب أن تكون على كل الطلبات
//    وإلا أعطت أرقاماً خاطئة عند الفلترة
// ============================================================
// تجهيز استعلام الإحصائيات
$stmtStats = $con->prepare(
    "SELECT
        -- عدد الطلبات المعلّقة
        SUM(CASE WHEN required_approval_status = 'pending'  THEN 1 ELSE 0 END) AS pending_count,
        -- عدد الطلبات المقبولة
        SUM(CASE WHEN required_approval_status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
        -- عدد الطلبات المرفوضة
        SUM(CASE WHEN required_approval_status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count,
        -- الإجمالي الكلي
        COUNT(required_id) AS all_count,
        -- ⭐ إجمالي العمال في الطلبات المقبولة
        COALESCE(SUM(CASE WHEN required_approval_status = 'approved'
                          THEN required_workers_count ELSE 0 END), 0) AS approved_workers
     FROM job_details_required
     WHERE required_company_id = ?"
);
// تنفيذ الاستعلام بتمرير معرّف الشركة
$stmtStats->execute([$company['company_id']]);
// قراءة صف الإحصائيات
$stats = $stmtStats->fetch(PDO::FETCH_ASSOC);

// قراءة الأعداد كأرقام صحيحة
$pendingCount  = (int)($stats['pending_count']  ?? 0);
$approvedCount = (int)($stats['approved_count'] ?? 0);
$rejectedCount = (int)($stats['rejected_count'] ?? 0);
$allCount      = (int)($stats['all_count']      ?? 0);

// ============================================================
// الخطوة 8: تحويل الطلبات لشكل مناسب للتطبيق
// ============================================================
$jobsData = array_map(function ($j) {
    // ساعات الانتظار مع حماية من القيم السالبة
    $waitingHours = max(0, (int)($j['waiting_hours'] ?? 0));
    // الأيام المتبقية حتى بدء العمل
    $daysUntilStart = (int)($j['days_until_start'] ?? 0);
    // هل الطلب معلَّق؟
    $isPending = $j['required_approval_status'] === 'pending';

    // إرجاع مصفوفة الطلب بالشكل الذي يقرأه التطبيق
    return [
        // معرّف الطلب
        "required_id"                 => (int)$j['required_id'],
        // اسم المهنة المطلوبة
        "required_job_title"          => $j['required_job_title'],
        // ⭐ مقتطف من الشرح للعرض في القائمة
        "job_description_excerpt"     => mb_strlen($j['required_job_description']) > 100
            ? mb_substr($j['required_job_description'], 0, 100) . '…'
            : $j['required_job_description'],
        // حالة توثيق العامل
        "required_is_verified_worker" => $j['required_is_verified_worker'],
        // ⭐ ترجمة حالة التوثيق للعربية
        "verification_ar"             => $j['required_is_verified_worker'] === 'verified'
            ? 'موثق' : 'غير موثق',
        // عدد العمال المطلوبين
        "required_workers_count"      => (int)$j['required_workers_count'],
                // ===== ⭐ شروط العمل (إضافة 2026) =====
        // ⭐ المواعيد بصيغة قصيرة جاهزة للعرض
        "shift_time_display"          => substr($j['required_shift_start_time'], 0, 5)
                                       . ' - ' . substr($j['required_shift_end_time'], 0, 5),
        // عدد ساعات العمل اليومية
        "required_daily_work_hours"   => (int)$j['required_daily_work_hours'],
        // عدد أيام العمل في الشهر
        "required_monthly_work_days"  => (int)$j['required_monthly_work_days'],
        // ⭐ المرتب بصيغة منسّقة جاهزة للعرض
        "salary_display"              => number_format((float)$j['required_monthly_salary'], 0) . ' ج.م',
        // تاريخ ووقت بدء العمل
        "required_start_date_time"    => $j['required_start_date_time'],
        // تاريخ إرسال الطلب
        "required_created_at"         => $j['required_created_at'],

        // حالة الموافقة كما هي مخزَّنة
        "required_approval_status"    => $j['required_approval_status'],
        // ⭐ ترجمة حالة الموافقة للعربية
        "approval_status_ar"          => match($j['required_approval_status']) {
            'pending'  => 'قيد المراجعة',
            'approved' => 'مقبول',
            'rejected' => 'مرفوض',
            default    => 'غير محدد',
        },
        // ⭐ لون الشارة المقترح للواجهة
        "status_color"                => match($j['required_approval_status']) {
            'pending'  => 'warning',
            'approved' => 'success',
            'rejected' => 'danger',
            default    => 'muted',
        },

        // ⭐ سبب الرفض يظهر عند الرفض فقط
        // ⚠️ ملاحظات الحالات الأخرى شأن داخلي للإدارة
        "rejection_reason"            => $j['required_approval_status'] === 'rejected'
            ? $j['required_review_note'] : null,
        // تاريخ المراجعة
        "reviewed_at"                 => $j['required_reviewed_at'],
        // عدد ساعات الانتظار
        "waiting_hours"               => $waitingHours,
        // ⭐ هل تجاوز الطلب المهلة المعلنة؟
        "is_overdue"                  => $isPending && $waitingHours > 24,
        // عدد الأيام المتبقية حتى بدء العمل
        "days_until_start"            => $daysUntilStart,
        // ⭐ هل يستطيع تعديل الطلب وإعادة إرساله؟
        "can_resubmit"                => $j['required_approval_status'] === 'rejected',
    ];
}, $jobs);

// ============================================================
// الخطوة 9: ⭐⭐ تحديد حالة البطاقة ونصوصها
//
// هذا جوهر الملف: السيرفر يُقرّر ماذا تعرض البطاقة
// والأولوية بالترتيب: مرفوض ← معلَّق ← مقبول ← بلا طلبات
//
// ⚠️ لماذا الرفض أولاً؟ لأنه الحالة التي تحتاج فعلاً من الشركة
//    والمعلَّق ثانياً لأنه ما تنتظره
// ============================================================

// ---------- الحالة الثانية: سجّل نشاطاً ولم يُرسل طلباً ----------
if ($allCount === 0) {
    // حالة البطاقة: أكمل طلبك
    $boxState  = "no_request";
    // عنوان البطاقة
    $boxTitle  = "أكمل تسجيل طلبك";
    // نص البطاقة
    $boxMsg    = "سجّلت بيانات {$company['company_name']} بنجاح، أضف الآن تفاصيل العمال المطلوبين";
    // نص الزر
    $boxAction = "إضافة تفاصيل العمال";
    // لون البطاقة
    $boxColor  = "primary";
    // أيقونة البطاقة
    $boxIcon   = "add_task";
    // الشاشة التي تُفتح عند الضغط
    $target    = "job_details_required";

// ---------- الحالة الخامسة: لديه طلب مرفوض ----------
} elseif ($rejectedCount > 0 && $pendingCount === 0) {
    // حالة البطاقة: مرفوض
    $boxState  = "rejected";
    // عنوان البطاقة
    $boxTitle  = "طلبك يحتاج تصحيحاً";
    // نص البطاقة مع العدد
    $boxMsg    = $rejectedCount === 1
        ? "تم رفض طلبك، اضغط لمعرفة السبب وإعادة الإرسال"
        : "تم رفض {$rejectedCount} من طلباتك، اضغط لمعرفة الأسباب";
    // نص الزر
    $boxAction = "عرض التفاصيل";
    // لون البطاقة
    $boxColor  = "danger";
    // أيقونة البطاقة
    $boxIcon   = "error_outline";
    // الشاشة التي تُفتح عند الضغط
    $target    = "my_job_requests";

// ---------- الحالة الثالثة: لديه طلب قيد المراجعة ----------
} elseif ($pendingCount > 0) {
    // حالة البطاقة: قيد المراجعة
    $boxState  = "pending";
    // عنوان البطاقة
    $boxTitle  = "طلباتك: قيد المراجعة";
    // نص البطاقة مع العدد
    $boxMsg    = $pendingCount === 1
        ? "طلبك قيد المراجعة، سيتم التواصل معك خلال ٢٤ ساعة"
        : "لديك {$pendingCount} طلبات قيد المراجعة، سيتم التواصل معك خلال ٢٤ ساعة";
    // نص الزر
    $boxAction = "متابعة الطلب";
    // لون البطاقة
    $boxColor  = "warning";
    // أيقونة البطاقة
    $boxIcon   = "hourglass_top";
    // الشاشة التي تُفتح عند الضغط
    $target    = "my_job_requests";

// ---------- الحالة الرابعة: كل طلباته مقبولة ----------
} else {
    // حالة البطاقة: مقبول
    $boxState  = "approved";
    // عنوان البطاقة
    $boxTitle  = "تم قبول طلبك";
    // نص البطاقة مع عدد العمال
    $boxMsg    = "تم قبول طلبك بعدد "
               . (int)($stats['approved_workers'] ?? 0)
               . " عامل، سيتم التواصل معك لتحديد التفاصيل";
    // نص الزر
    $boxAction = "عرض الطلبات";
    // لون البطاقة
    $boxColor  = "success";
    // أيقونة البطاقة
    $boxIcon   = "check_circle";
    // الشاشة التي تُفتح عند الضغط
    $target    = "my_job_requests";
}

// ============================================================
// الخطوة 10: إرسال الرد النهائي
// ============================================================
echo json_encode([
    // حالة العملية
    "status"  => "success",
    // رسالة نجاح
    "message" => "تم جلب طلباتك بنجاح",
    // بيانات النتيجة
    "data"    => [

        // ===== ⭐⭐ حالة بطاقة home_tap =====
        // التطبيق يقرأ هذه المفاتيح ويرسم البطاقة بلا أي منطق شرطي
        "box_state"     => $boxState,
        // عنوان البطاقة
        "box_title"     => $boxTitle,
        // نص البطاقة
        "box_message"   => $boxMsg,
        // نص الزر
        "box_action"    => $boxAction,
        // لون البطاقة المقترح
        "box_color"     => $boxColor,
        // أيقونة البطاقة المقترحة
        "box_icon"      => $boxIcon,
        // الشاشة التي تُفتح عند الضغط على الزر
        "target_screen" => $target,

        // ===== يوجد نشاط مسجّل =====
        "has_company" => true,

        // ===== ملخّص الشركة =====
        "company" => [
            // معرّف الشركة (يحتاجه التطبيق لإرسال طلب جديد)
            "company_id"            => (int)$company['company_id'],
            // اسم النشاط
            "company_name"          => $company['company_name'],
            // نوع النشاط كما هو مخزَّن
            "company_business_type" => $company['company_business_type'],
            // ⭐ ترجمة نوع النشاط للعربية
            "business_type_ar"      => match($company['company_business_type']) {
                'company'             => 'شركة',
                'factory'             => 'مصنع',
                'commercial_activity' => 'نشاط تجاري',
                default               => 'غير محدد',
            },
            // رقم هاتف الشركة
            "company_phone_number"  => $company['company_phone_number'],
            // تاريخ تسجيل الشركة
            "company_created_at"    => $company['company_created_at'],
        ],

        // ===== قائمة الطلبات =====
        "requests" => $jobsData,

        // ===== إحصائيات =====
        "stats" => [
            // إجمالي عدد الطلبات
            "total_requests"    => $allCount,
            // عدد الطلبات المعلّقة
            "pending_requests"  => $pendingCount,
            // عدد الطلبات المقبولة
            "approved_requests" => $approvedCount,
            // عدد الطلبات المرفوضة
            "rejected_requests" => $rejectedCount,
            // ⭐ إجمالي العمال في الطلبات المقبولة
            "approved_workers"  => (int)($stats['approved_workers'] ?? 0),
        ],
    ],
]);
?>