<?php
// ============================================================
// ملف: admin/customers/add_job_request.php
// الغرض: إضافة طلب عمالة نيابة عن الشركة من لوحة الإدارة
//        بلا أن يدخل صاحب الشركة التطبيق
// الطريقة: POST
//
// ⭐⭐ الفرق الجوهري عن companies/add_job_details_required.php:
//
//   ملف العميل: الشركة تُرسل الطلب → pending → تنتظر مراجعة الإدارة
//   هذا الملف : الموظف يُدخل الطلب → approved فوراً
//
//   ⭐ لماذا approved مباشرة؟
//     لأن الموظف أخذ الطلب من الشركة هاتفياً أو شخصياً
//     فهو نفسه جهة المراجعة — ولا معنى لأن يُراجع طلباً كتبه بيده
//
//   ⚠️ ومع ذلك نُسجّل required_reviewed_by = معرّف الموظف
//     حتى يبقى واضحاً من وافق ومتى
//
// الحقول الإجبارية:
//   required_company_id · required_job_title · required_job_description
//   required_is_verified_worker · required_workers_count
//   required_start_date_time · required_daily_work_hours
//   required_monthly_work_days · required_shift_start_time
//   required_shift_end_time · required_monthly_salary
//
// الصلاحية المطلوبة: jobs.create
// ============================================================

// تضمين ملف الاتصال (مستويان للوراء لأن الملف داخل admin/customers)
include "../../connect.php";

// ============================================================
// الخطوة 1: التحقق من التوكن والصلاحية
//
// ⭐ jobs.create يملكها: owner و manager و seller
// ============================================================
// التحقق من التوكن وجلب بيانات الموظف مع صلاحياته
$currentAdmin = checkAdminToken();

// ⭐ فرض صلاحية إضافة طلب عمالة
requirePermission($currentAdmin, 'jobs.create');

// معرّف الموظف المنفّذ مأخوذ من التوكن لا من الطلب
$adminId = (int) $currentAdmin['admin_id'];

// ============================================================
// الخطوة 2: استقبال بيانات الطلب
// ============================================================
// معرّف الشركة الطالبة - إجباري
$companyId    = filterRequest("required_company_id");
// اسم المهنة أو الوظيفة - إجباري
$jobTitle     = trim(filterRequest("required_job_title"));
// شرح مواصفات العامل - إجباري
$jobDesc      = trim(filterRequest("required_job_description"));
// حالة توثيق العامل: verified أو unverified - إجباري
$isVerified   = filterRequest("required_is_verified_worker");
// عدد العمال المطلوبين - إجباري
$workersCount = filterRequest("required_workers_count");
// تاريخ ووقت بدء العمل - إجباري
$startDate    = trim(filterRequest("required_start_date_time"));

// ============================================================
// ⭐ الخطوة 3: استقبال شروط العمل الخمسة
// ============================================================
// عدد ساعات العمل اليومية - إجباري
$dailyHours   = filterRequest("required_daily_work_hours");
// عدد أيام العمل في الشهر - إجباري
$monthlyDays  = filterRequest("required_monthly_work_days");
// ساعة بداية الدوام - إجباري
$shiftStart   = trim(filterRequest("required_shift_start_time"));
// ساعة نهاية الدوام - إجباري
$shiftEnd     = trim(filterRequest("required_shift_end_time"));
// المرتب الشهري للعامل - إجباري
$salary       = filterRequest("required_monthly_salary");

// ⭐ ملاحظة الموظف على الطلب - اختياري
$adminNote    = trim(filterRequest("required_review_note"));

// ============================================================
// الخطوة 4: التحقق من الحقول الإجبارية
// ============================================================
// إذا نقص أي حقل إجباري نوقف التنفيذ فوراً
if ($companyId === '' || $jobTitle === '' || $jobDesc === '' ||
    $isVerified === '' || $workersCount === '' || $startDate === '' ||
    $dailyHours === '' || $monthlyDays === '' ||
    $shiftStart === '' || $shiftEnd === '' || $salary === '') {
    // رسالة عربية توضح كل الحقول المطلوبة
    printFailure(
        "يرجى إدخال كل البيانات الإجبارية: المهنة، المواصفات، "
        . "حالة التوثيق، عدد العمال، تاريخ البدء، ساعات العمل اليومية، "
        . "أيام العمل الشهرية، بداية الدوام، نهاية الدوام، المرتب الشهري"
    );
    // إيقاف التنفيذ
    exit;
}

// تحويل معرّف الشركة لرقم صحيح لحماية جملة WHERE
$companyId = (int) $companyId;

// ============================================================
// ⭐ الخطوة 5: التحقق من وجود الشركة وجلب بيانات صاحبها
//
// ⭐ نجلب بيانات المالك لأن الرد سيحملها
//    فيستطيع الموظف الاتصال به فوراً لتأكيد الطلب
// ============================================================
// تجهيز استعلام يجمع الشركة وصاحبها
$stmtCompany = $con->prepare(
    "SELECT
        c.company_id,
        c.company_user_id,
        c.company_name,
        c.company_business_type,
        c.company_phone_number,
        -- ⭐ هل مستندات الشركة مكتملة؟
        c.company_commercial_register_pdf,
        c.company_tax_card_pdf,
        -- بيانات صاحب الحساب
        u.users_full_name,
        u.users_phone,
        u.users_is_active
     FROM company_registering c
     -- ربط صاحب الحساب
     LEFT JOIN users u ON u.users_id = c.company_user_id
     WHERE c.company_id = ?
     LIMIT 1"
);
// تنفيذ الاستعلام بمعرّف الشركة
$stmtCompany->execute([$companyId]);
// جلب صف الشركة
$company = $stmtCompany->fetch(PDO::FETCH_ASSOC);

// إذا لم توجد الشركة نرفض الطلب
if (!$company) {
    // رسالة توضح أن الشركة غير موجودة
    printFailure("الشركة غير موجودة — تأكد من معرّف الشركة", 404);
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 6: التحقق من صحة حالة توثيق العامل
//
// ⚠️ إجباري لأن العمود ENUM في MySQL
// ============================================================
// قائمة الحالات المسموحة (نفس قيم الـ ENUM حرفياً)
$allowedVerification = ['verified', 'unverified'];

// إذا كانت الحالة غير معروفة نرفض الطلب
if (!in_array($isVerified, $allowedVerification, true)) {
    // رسالة توضح القيم المسموحة
    printFailure(
        "حالة توثيق العامل يجب أن تكون: verified أو unverified"
    );
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 7: التحقق من عدد العمال
// ============================================================
// تحويل العدد لرقم صحيح
$workersCount = (int) $workersCount;

// العدد بين 1 و 500 عاملاً
if ($workersCount < 1 || $workersCount > 500) {
    // رسالة توضح النطاق المسموح
    printFailure("عدد العمال يجب أن يكون بين 1 و 500");
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// ⭐ الخطوة 8: التحقق من شروط العمل الخمسة
// ============================================================
// تحويل ساعات العمل اليومية لرقم صحيح
$dailyHours = (int) $dailyHours;

// الساعات بين 1 و 24
if ($dailyHours < 1 || $dailyHours > 24) {
    // رسالة توضح النطاق المسموح
    printFailure("ساعات العمل اليومية يجب أن تكون بين 1 و 24 ساعة");
    // إيقاف التنفيذ
    exit;
}

// تحويل أيام العمل الشهرية لرقم صحيح
$monthlyDays = (int) $monthlyDays;

// الأيام بين 1 و 31
if ($monthlyDays < 1 || $monthlyDays > 31) {
    // رسالة توضح النطاق المسموح
    printFailure("أيام العمل الشهرية يجب أن تكون بين 1 و 31 يوماً");
    // إيقاف التنفيذ
    exit;
}

// ⭐ التحقق من صيغة ساعة بداية الدوام
// ⚠️ العمود TIME فيقبل HH:MM:SS أو HH:MM
if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $shiftStart)) {
    // رسالة توضح الصيغة المطلوبة
    printFailure("صيغة بداية الدوام غير صحيحة، الصيغة المطلوبة: HH:MM");
    // إيقاف التنفيذ
    exit;
}

// ⭐ التحقق من صيغة ساعة نهاية الدوام
if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $shiftEnd)) {
    // رسالة توضح الصيغة المطلوبة
    printFailure("صيغة نهاية الدوام غير صحيحة، الصيغة المطلوبة: HH:MM");
    // إيقاف التنفيذ
    exit;
}

// ⭐ تحويل المرتب لرقم عشري بعد إزالة الفواصل
// ⚠️ لأن الموظف قد يكتب 4,500 والدالة float ترفض الفاصلة
$salary = (float) str_replace(',', '', $salary);

// المرتب لا يكون سالباً
if ($salary < 0) {
    // رسالة توضح المشكلة
    printFailure("المرتب الشهري لا يكون سالباً");
    // إيقاف التنفيذ
    exit;
}

// ⚠️ الحد الأقصى يحمي عمود DECIMAL(10,2)
if ($salary > 9999999) {
    // رسالة توضح الحد الأقصى
    printFailure("المرتب الشهري كبير جداً، الحد الأقصى 9,999,999 جنيه");
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// ⭐ الخطوة 9: التحقق من صيغة تاريخ بدء العمل
//
// ⚠️ العمود DATETIME فنقبل YYYY-MM-DD HH:MM:SS
// ============================================================
// نتحقق أن التاريخ قابل للتحويل فعلاً
$startTimestamp = strtotime($startDate);

// إذا فشل التحويل نرفض الطلب
if ($startTimestamp === false) {
    // رسالة توضح الصيغة المطلوبة
    printFailure(
        "صيغة تاريخ بدء العمل غير صحيحة، "
        . "الصيغة المطلوبة: YYYY-MM-DD HH:MM:SS"
    );
    // إيقاف التنفيذ
    exit;
}

// ⭐ توحيد صيغة التاريخ قبل الحفظ
$startDate = date("Y-m-d H:i:s", $startTimestamp);

// ============================================================
// ⭐⭐ الخطوة 10: تحديد حالة الموافقة
//
// ⭐ الحالة المُفعَّلة: approved فوراً
//   لأن الموظف أخذ الطلب من الشركة بنفسه فهو جهة المراجعة
//   ولا معنى لأن يُراجع طلباً كتبه بيده أو يتركه معلَّقاً في طابور
// ============================================================
// حالة الموافقة الافتراضية
$approvalStatus = 'approved';

// ⚠️ الخيار البديل (غير مُفعَّل): إدخال الطلب معلَّقاً لمراجعة ثانية
// ولتفعيله ضع // قبل السطر أعلاه وأزل التعليق عن السطر التالي
// $approvalStatus = 'pending';
//
// ⭐ الفرق العملي:
//   approved = الطلب جاهز للتنفيذ فوراً (المندوب مسؤول عن صحته)
//   pending  = يدخل طابور المراجعة فيراجعه مدير أو خدمة عملاء
//              (أأمن مع المندوبين الجدد، لكنه يُبطئ التنفيذ)

// ⭐ السماح للموظف بتجاوز القيمة الافتراضية إن أرسلها صراحةً
// ⚠️ لكن بشرط أن يملك صلاحية القبول والرفض
$requestedStatus = filterRequest("required_approval_status");

// نتحقق أن الحالة المُرسلة معروفة وأن الموظف يملك صلاحية القرار
if ($requestedStatus !== '' &&
    in_array($requestedStatus, ['pending', 'approved'], true)) {

    // ⭐ إن أراد approved فيجب أن يملك صلاحية jobs.approve
    // ⚠️ فلا يستطيع مندوب أن يوافق على طلب إن مُنع من الموافقة
    if ($requestedStatus === 'approved' &&
        !adminCan($currentAdmin, 'jobs.approve')) {
        // ⭐ نُخفّض الحالة تلقائياً بدل رفض الطلب كله
        $approvalStatus = 'pending';
    } else {
        // استخدام الحالة المطلوبة
        $approvalStatus = $requestedStatus;
    }
}

// ⭐ إن لم يملك صلاحية القبول أصلاً فالطلب معلَّق دائماً
// ⚠️ هذا يحوّل المندوب من "مُقرِّر" إلى "مُدخِل بيانات" تلقائياً
if (!adminCan($currentAdmin, 'jobs.approve')) {
    // إجبار الحالة على الانتظار
    $approvalStatus = 'pending';
}

// ============================================================
// ⭐ الخطوة 11: تجهيز بيانات سجل المراجعة
// ============================================================
// ⭐ معرّف المراجع ووقته يُسجَّلان فقط إن كان الطلب محسوماً
$reviewedBy = ($approvalStatus !== 'pending') ? $adminId : null;
// وقت المراجعة
$reviewedAt = ($approvalStatus !== 'pending')
              ? date("Y-m-d H:i:s") : null;

// ⭐ الملاحظة: إن لم يكتبها الموظف نُولّد ملاحظة توضح المصدر
// ⚠️ مهمة جداً لأن الطلب لم يأتِ من التطبيق
$reviewNote = $adminNote !== ''
    ? $adminNote
    : "طلب مُدخَل من الإدارة بواسطة: "
      . $currentAdmin['admin_full_name']
      . " (" . ($currentAdmin['role_name_ar'] ?? 'موظف') . ")";

// ============================================================
// الخطوة 12: إدخال الطلب في جدول job_details_required
// ============================================================
// بناء مصفوفة البيانات بأسماء أعمدة الجدول حرفياً
$insertData = [
    // معرّف الشركة الطالبة
    "required_company_id"         => $companyId,
    // اسم المهنة أو الوظيفة
    "required_job_title"          => $jobTitle,
    // شرح مواصفات العامل
    "required_job_description"    => $jobDesc,
    // حالة توثيق العامل بعد التحقق
    "required_is_verified_worker" => $isVerified,
    // عدد العمال المطلوبين
    "required_workers_count"      => $workersCount,
    // ⭐ ساعات العمل اليومية
    "required_daily_work_hours"   => $dailyHours,
    // ⭐ أيام العمل الشهرية
    "required_monthly_work_days"  => $monthlyDays,
    // ⭐ ساعة بداية الدوام
    "required_shift_start_time"   => $shiftStart,
    // ⭐ ساعة نهاية الدوام
    "required_shift_end_time"     => $shiftEnd,
    // ⭐ المرتب الشهري
    "required_monthly_salary"     => $salary,
    // تاريخ ووقت بدء العمل بعد توحيد صيغته
    "required_start_date_time"    => $startDate,
    // ⭐⭐ حالة الموافقة (approved أو pending)
    "required_approval_status"    => $approvalStatus,
    // ⭐ معرّف الموظف المراجع (null إن كان معلَّقاً)
    "required_reviewed_by"        => $reviewedBy,
    // وقت المراجعة (null إن كان معلَّقاً)
    "required_reviewed_at"        => $reviewedAt,
    // ⭐ ملاحظة توضح أن الطلب مُدخَل من الإدارة
    "required_review_note"        => $reviewNote,
    // ⭐⭐ معرّف الموظف الذي أدخل الطلب (للمساءلة)
    "required_created_by_admin"   => $adminId,
];

// ⚠️ نمرر false لمنع الدالة من طباعة JSON لأننا نبني رداً مخصصاً
$result = insertData("job_details_required", $insertData, false);

// ============================================================
// الخطوة 13: التحقق من نجاح الإدخال
// ============================================================
// إذا فشل الإدخال نرفض الطلب برسالة واضحة
if ($result <= 0) {
    // رسالة توضح فشل الحفظ
    printFailure("فشل حفظ طلب العمالة، يرجى المحاولة مرة أخرى", 500);
    // إيقاف التنفيذ
    exit;
}

// ⭐ قراءة معرّف الطلب الجديد
$newRequiredId = (int) $con->lastInsertId();

// ============================================================
// ⭐ الخطوة 14: تسجيل الإجراء في سجل النشاط
// ============================================================
// تسجيل إجراء إضافة طلب العمالة
logAdminActivity(
    // بيانات الموظف المنفّذ
    $currentAdmin,
    // نوع الإجراء
    'create_job_request',
    // نوع الكيان
    'job_request',
    // معرّف الطلب الجديد
    $newRequiredId,
    // ⭐ التفاصيل التي نحتاجها في المراجعة لاحقاً
    [
        // معرّف الشركة الطالبة
        "company_id"      => $companyId,
        // اسم الشركة
        "company_name"    => $company['company_name'],
        // اسم المهنة
        "job_title"       => $jobTitle,
        // عدد العمال
        "workers_count"   => $workersCount,
        // ⭐ المرتب الشهري
        "monthly_salary"  => $salary,
        // ⭐ إجمالي الالتزام المالي الشهري لهذا الطلب
        "total_salaries"  => $salary * $workersCount,
        // ⭐ حالة الموافقة المسجّلة
        "approval_status" => $approvalStatus,
    ]
);

// ============================================================
// ⭐ الخطوة 15: حساب الأرقام المشتقة للعرض
//
// ⚠️ نحسبها في PHP لا في فلاتر
//    حتى تظهر بنفس القيم في اللوحة وفي التطبيق وفي Postman
// ============================================================
// ⭐ إجمالي المرتبات لكل العمال (الالتزام المالي الشهري)
$totalSalaries = $salary * $workersCount;

// ⭐ إجمالي ساعات العمل الشهرية للعامل الواحد
$monthlyTotalHours = $dailyHours * $monthlyDays;

// ⭐ متوسط أجر الساعة (مؤشر عدالة الأجر)
// ⚠️ نتحقق من عدم القسمة على صفر
$hourlyRate = $monthlyTotalHours > 0
    ? round($salary / $monthlyTotalHours, 2)
    : 0;

// ⭐ عرض المواعيد بصيغة جاهزة مثل 08:00 - 16:00
$shiftTimeDisplay = substr($shiftStart, 0, 5) . ' - ' . substr($shiftEnd, 0, 5);

// ⭐ عرض المرتب منسّقاً بفواصل الآلاف
$salaryDisplay = number_format($salary, 0, '.', ',') . ' ج.م';

// ============================================================
// ⭐ الخطوة 16: إرسال الرد النهائي
// ============================================================
echo json_encode([
    // حالة العملية
    "status"  => "success",
    // ⭐ رسالة عربية تتغير حسب حالة الموافقة
    "message" => $approvalStatus === 'approved'
        ? "تم إضافة طلب العمالة وقبوله مباشرة"
        : "تم إضافة طلب العمالة وهو في انتظار مراجعة الإدارة",
    // بيانات النتيجة
    "data"    => [

        // ===== ⭐ بيانات الطلب =====
        "request" => [
            // ⭐ معرّف الطلب الجديد
            "required_id"        => $newRequiredId,
            // معرّف الشركة الطالبة
            "company_id"         => $companyId,
            // اسم المهنة
            "job_title"          => $jobTitle,
            // شرح المواصفات
            "job_description"    => $jobDesc,
            // حالة توثيق العامل بالإنجليزية
            "is_verified_worker" => $isVerified,
            // ⭐ ترجمة حالة التوثيق من PHP
            "verification_ar"    => $isVerified === 'verified'
                                    ? 'عامل موثق' : 'عامل غير موثق',
            // عدد العمال المطلوبين
            "workers_count"      => $workersCount,
            // تاريخ ووقت بدء العمل
            "start_date_time"    => $startDate,
        ],

        // ===== ⭐ شروط العمل والأرقام المشتقة =====
        "work_terms" => [
            // ساعات العمل اليومية
            "daily_work_hours"    => $dailyHours,
            // أيام العمل الشهرية
            "monthly_work_days"   => $monthlyDays,
            // ساعة بداية الدوام
            "shift_start_time"    => $shiftStart,
            // ساعة نهاية الدوام
            "shift_end_time"      => $shiftEnd,
            // ⭐ المواعيد جاهزة للعرض
            "shift_time_display"  => $shiftTimeDisplay,
            // المرتب الشهري كرقم
            "monthly_salary"      => (float) $salary,
            // ⭐ المرتب منسّقاً للعرض
            "salary_display"      => $salaryDisplay,
            // ⭐ إجمالي المرتبات لكل العمال
            "total_salaries"      => (float) $totalSalaries,
            // ⭐ إجمالي المرتبات منسّقاً
            "total_salaries_display" =>
                number_format($totalSalaries, 0, '.', ',') . ' ج.م',
            // ⭐ إجمالي ساعات العمل الشهرية
            "monthly_total_hours" => $monthlyTotalHours,
            // ⭐ متوسط أجر الساعة
            "hourly_rate"         => (float) $hourlyRate,
        ],

        // ===== ⭐ حالة الموافقة =====
        "approval" => [
            // حالة الموافقة بالإنجليزية
            "approval_status"    => $approvalStatus,
            // ⭐ ترجمة الحالة من PHP
            "approval_status_ar" => match($approvalStatus) {
                'pending'  => 'في انتظار المراجعة',
                'approved' => 'مقبول',
                'rejected' => 'مرفوض',
                default    => 'غير محدد',
            },
            // ⭐ لون الشارة المقترح للواجهة
            "status_color"       => $approvalStatus === 'approved'
                                    ? 'success' : 'warning',
            // ⭐ هل تمت المراجعة؟
            "is_reviewed"        => $reviewedBy !== null,
            // وقت المراجعة
            "reviewed_at"        => $reviewedAt,
            // ملاحظة الموظف أو الملاحظة المُولَّدة
            "review_note"        => $reviewNote,
        ],

        // ===== ⭐ سياق الشركة وصاحبها =====
        "company" => [
            // اسم الشركة
            "company_name"       => $company['company_name'],
            // نوع النشاط بالإنجليزية
            "business_type"      => $company['company_business_type'],
            // ⭐ ترجمة نوع النشاط
            "business_type_ar"   => match($company['company_business_type']) {
                'company'             => 'شركة',
                'factory'             => 'مصنع',
                'commercial_activity' => 'نشاط تجاري',
                default               => 'غير محدد',
            },
            // ⭐ هاتف الشركة ليتصل الموظف لتأكيد الطلب
            "company_phone"      => $company['company_phone_number'],
            // اسم صاحب الحساب
            "owner_name"         => $company['users_full_name'],
            // ⚠️ هاتف صاحب الحساب (للإدارة فقط)
            "owner_phone"        => $company['users_phone'],
            // ⭐ هل مستندات الشركة مكتملة؟
            "documents_complete" =>
                !empty($company['company_commercial_register_pdf'])
                && !empty($company['company_tax_card_pdf']),
        ],

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
?>