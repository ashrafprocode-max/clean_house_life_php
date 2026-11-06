<?php
// ============================================================
// ملف: companies/view_job_details_required.php
// الغرض: عرض تفاصيل طلب عمالة واحد لصاحب الشركة في التطبيق
//
// ⚠️ الفرق الجوهري عن نسخة الإدارة (admin/companies/):
//   1. لا نُرجع اسم الموظف المراجع ولا دوره (شأن داخلي)
//   2. الملاحظة تظهر فقط عند الرفض (لأنها موجَّهة للشركة)
//   3. لا نُرجع طلبات الشركة الأخرى (لتقليل حجم الرد)
//   4. لا نُرجع available_actions (الشركة لا تُقرّر)
//   5. ⭐ نتحقق من الملكية فلا يرى طلب شركة أخرى إطلاقاً
//
// الحقول الإجبارية: required_id, company_user_id
// الطريقة: POST أو GET
// ============================================================

// تضمين ملف الاتصال بقاعدة البيانات والدوال المساعدة
include "../connect.php";

// ============================================================
// الخطوة 1: استقبال البيانات
// ============================================================
// معرّف طلب العمالة المراد عرضه - إجباري
$required_id = filterRequest("required_id");
// معرّف المستخدم صاحب الشركة - إجباري للتحقق من الملكية
$user_id     = filterRequest("company_user_id");

// ============================================================
// الخطوة 2: التحقق من الحقول الإجبارية
// ============================================================
// إذا لم يُرسل معرّف الطلب نوقف التنفيذ
if (empty($required_id)) {
    // رسالة توضح الحقل المطلوب
    printFailure("الحقل required_id مطلوب");
    // إيقاف التنفيذ
    exit;
}

// ⚠️ معرّف المستخدم إجباري لأنه أساس التحقق من الملكية
if (empty($user_id)) {
    // رسالة توضح أن التوثيق مطلوب
    printFailure("الحقل company_user_id مطلوب", 401);
    // إيقاف التنفيذ
    exit;
}

// تحويل المعرّفين لأرقام صحيحة لحماية جملة WHERE
$required_id = (int) $required_id;
$user_id     = (int) $user_id;

// ⚠️⚠️ تنبيه أمني مهم:
//   هذا الملف يثق في company_user_id القادم من الطلب
//   لأن checkAuthenticate() معطّلة في connect.php (السطر ~138)
//   فيستطيع أي شخص تمرير معرّف مستخدم آخر ورؤية طلبات شركته
//   والحل الجذري هو تفعيل توكن المستخدم (مؤجَّل بطلبك)

// ============================================================
// الخطوة 3: جلب الطلب مع التحقق من الملكية في نفس الاستعلام
//
// ⭐ لماذا نجمع التحقق مع الجلب؟
//   لأن شرط company_user_id في جملة WHERE يجعل الاستعلام
//   لا يُرجع شيئاً أصلاً لو كان الطلب لشركة أخرى
//   فلا يمكن أن تُسرَّب البيانات ولو بخطأ برمجي لاحق
// ============================================================
// تجهيز استعلام محمي يجلب الطلب ويتحقق من الملكية معاً
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
        j.required_review_note,
        j.required_reviewed_at,
        j.required_created_at,
        j.required_updated_at,

        -- ⭐ عدد ساعات انتظار الطلب — محسوبة داخل MySQL
        -- ⚠️ توقيت PHP يختلف عن توقيت MySQL فحسابها في PHP يُنتج سالباً
        TIMESTAMPDIFF(HOUR, j.required_created_at, NOW()) AS waiting_hours,

        -- ⭐ عدد الأيام المتبقية حتى موعد بدء العمل
        TIMESTAMPDIFF(DAY, NOW(), j.required_start_date_time) AS days_until_start,

        -- ===== بيانات الشركة =====
        c.company_id,
        c.company_user_id,
        c.company_business_type,
        c.company_name,
        c.company_phone_number

     FROM job_details_required j
     INNER JOIN company_registering c ON c.company_id = j.required_company_id
     WHERE j.required_id = ?
       -- ⭐ شرط الملكية: الشركة يجب أن تخص هذا المستخدم
       AND c.company_user_id = ?
     LIMIT 1"
);
// تنفيذ الاستعلام بتمرير معرّف الطلب ومعرّف المستخدم
$stmt->execute([$required_id, $user_id]);
// جلب صف الطلب
$r = $stmt->fetch(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 4: التعامل مع عدم وجود نتيجة
//
// ⚠️ نُرجع رسالة واحدة موحّدة سواء كان الطلب غير موجود
//    أو موجوداً لكنه لشركة أخرى
//    لأن التمييز بينهما يكشف للمهاجم أن الطلب موجود فعلاً
//    وهذا يُسمى منع تسريب المعلومات (Information Disclosure)
// ============================================================
// إذا لم توجد نتيجة نرفض الطلب برسالة موحّدة
if (!$r) {
    // رسالة لا تكشف هل الطلب موجود أم لا
    printFailure("طلب العمالة غير موجود أو لا تملك صلاحية عرضه", 404);
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 5: قراءة القيم الزمنية المحسوبة من MySQL
// ============================================================
// ساعات الانتظار مع حماية من القيم السالبة
$waitingHours = max(0, (int)($r['waiting_hours'] ?? 0));

// الأيام المتبقية حتى بدء العمل (قد تكون سالبة إذا فات الموعد)
$daysUntilStart = (int)($r['days_until_start'] ?? 0);

// ============================================================
// الخطوة 6: ⭐ بناء رسالة الحالة الموجَّهة للشركة
//
// نبنيها في السيرفر لا في فلاتر
// فلو غيّرنا نصها لاحقاً لا نحتاج تحديث التطبيق في المتاجر
// ============================================================
// نحدد الرسالة حسب حالة الموافقة
$statusMessage = match($r['required_approval_status']) {

    // ---------- الطلب قيد المراجعة ----------
    'pending' => $waitingHours > 24
        // ⚠️ تجاوز المهلة المعلنة فنُطمئنه ونعطيه رقم التواصل
        ? 'طلبك قيد المراجعة وتجاوز المهلة المعلنة، نعتذر عن التأخير وسيتم التواصل معك قريباً'
        // داخل المهلة فنُذكّره بها
        : 'سيتم مراجعة طلبك ويتم التواصل معك خلال ٢٤ ساعة للتأكيد',

    // ---------- الطلب مقبول ----------
    'approved' => 'تم قبول طلبك، سيتم التواصل معك لتحديد تفاصيل الاستلام',

    // ---------- الطلب مرفوض ----------
    'rejected' => 'تم رفض الطلب، يرجى مراجعة السبب الموضّح وإعادة الإرسال',

    // حالة افتراضية
    default => 'حالة الطلب غير محددة',
};

// ============================================================
// الخطوة 7: إرسال الرد النهائي
// ============================================================
echo json_encode([
    // حالة العملية
    "status"  => "success",
    // ⭐ رسالة الحالة الموجَّهة للشركة (جاهزة للعرض مباشرة)
    "message" => $statusMessage,
    // بيانات النتيجة
    "data"    => [

        // ===== تفاصيل الطلب =====
        "request" => [
            // معرّف الطلب
            "required_id"                 => (int)$r['required_id'],
            // معرّف الشركة
            "required_company_id"         => (int)$r['required_company_id'],
            // اسم المهنة أو الوظيفة المطلوبة
            "required_job_title"          => $r['required_job_title'],
            // شرح مواصفات ومهام العامل
            "required_job_description"    => $r['required_job_description'],
            // حالة توثيق العامل كما هي مخزَّنة
            "required_is_verified_worker" => $r['required_is_verified_worker'],
            // ⭐ ترجمة حالة التوثيق للعربية
            "verification_ar"             => $r['required_is_verified_worker'] === 'verified'
                ? 'موثق' : 'غير موثق',
            // عدد العمال المطلوبين
            "required_workers_count"      => (int)$r['required_workers_count'],
                        // ===== ⭐ شروط العمل (إضافة 2026) =====
            // عدد ساعات العمل اليومية
            "required_daily_work_hours"  => (int)$r['required_daily_work_hours'],
            // عدد أيام العمل في الشهر
            "required_monthly_work_days" => (int)$r['required_monthly_work_days'],
            // ساعة بداية العمل
            "required_shift_start_time"  => $r['required_shift_start_time'],
            // ساعة نهاية العمل
            "required_shift_end_time"    => $r['required_shift_end_time'],
            // ⭐ المواعيد بصيغة قصيرة جاهزة للعرض
            "shift_time_display"         => substr($r['required_shift_start_time'], 0, 5)
                                          . ' - ' . substr($r['required_shift_end_time'], 0, 5),
            // المرتب الشهري كرقم
            "required_monthly_salary"    => (float)$r['required_monthly_salary'],
            // ⭐ المرتب بصيغة منسّقة جاهزة للعرض
            "salary_display"             => number_format((float)$r['required_monthly_salary'], 0) . ' ج.م',
            // ⭐ إجمالي المرتبات لكل العمال
            "total_salaries"             => (float)$r['required_monthly_salary']
                                          * (int)$r['required_workers_count'],
            // تاريخ ووقت بدء العمل
            "required_start_date_time"    => $r['required_start_date_time'],
            // تاريخ إرسال الطلب
            "required_created_at"         => $r['required_created_at'],
            // تاريخ آخر تحديث
            "required_updated_at"         => $r['required_updated_at'],
        ],

        // ===== حالة الطلب =====
        "status_info" => [
            // حالة الموافقة كما هي مخزَّنة
            "approval_status"    => $r['required_approval_status'],
            // ⭐ ترجمة حالة الموافقة للعربية
            "approval_status_ar" => match($r['required_approval_status']) {
                'pending'  => 'قيد المراجعة',
                'approved' => 'مقبول',
                'rejected' => 'مرفوض',
                default    => 'غير محدد',
            },
            // ⭐ لون الشارة المقترح للواجهة (نحدده في السيرفر للاتساق)
            "status_color"       => match($r['required_approval_status']) {
                'pending'  => 'warning',
                'approved' => 'success',
                'rejected' => 'danger',
                default    => 'muted',
            },
            // ⭐ سبب الرفض يظهر عند الرفض فقط
            // ⚠️ ملاحظات الحالات الأخرى شأن داخلي للإدارة فلا تُرسَل
            "rejection_reason"   => $r['required_approval_status'] === 'rejected'
                ? $r['required_review_note'] : null,
            // تاريخ المراجعة
            "reviewed_at"        => $r['required_reviewed_at'],
            // عدد ساعات الانتظار
            "waiting_hours"      => $waitingHours,
            // المهلة المعلنة للعميل بالساعات
            "response_within_hours" => 24,
            // ⭐ هل تجاوز الطلب المهلة المعلنة؟
            "is_overdue"         => $waitingHours > 24
                                 && $r['required_approval_status'] === 'pending',
            // عدد الأيام المتبقية حتى بدء العمل
            "days_until_start"   => $daysUntilStart,
            // ⚠️ هل فات موعد بدء العمل؟
            "start_date_passed"  => $daysUntilStart < 0,
            // ⭐ هل يستطيع تعديل الطلب وإعادة إرساله؟
            // (متاح عند الرفض فقط لأنه يحتاج تصحيح ما رُفض)
            "can_resubmit"       => $r['required_approval_status'] === 'rejected',
        ],

        // ===== ملخّص الشركة =====
        "company" => [
            // معرّف الشركة (يحتاجه التطبيق لإرسال طلب جديد)
            "company_id"            => (int)$r['company_id'],
            // اسم النشاط
            "company_name"          => $r['company_name'],
            // نوع النشاط كما هو مخزَّن
            "company_business_type" => $r['company_business_type'],
            // ⭐ ترجمة نوع النشاط للعربية
            "business_type_ar"      => match($r['company_business_type']) {
                'company'             => 'شركة',
                'factory'             => 'مصنع',
                'commercial_activity' => 'نشاط تجاري',
                default               => 'غير محدد',
            },
            // رقم هاتف الشركة
            "company_phone_number"  => $r['company_phone_number'],
        ],
    ],
]);
?>