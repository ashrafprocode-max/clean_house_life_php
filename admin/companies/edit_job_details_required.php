<?php
// ============================================================
// ملف: admin/companies/edit_job_details_required.php
// الغرض: تعديل كل تفاصيل العامل المطلوب من الشركة
//        وتنفيذ قرار الإدارة على الطلب (قبول / رفض / إعادة للانتظار)
// الطريقة: POST
//
// الحقل الإجباري الوحيد: required_id
// وكل الحقول الأخرى اختيارية — نُحدّث ما أُرسل فقط
//
// ⭐ سجل المراجعة (نسخة 2026):
//   required_reviewed_by → معرّف الموظف من التوكن
//   required_reviewed_at → تاريخ ووقت القرار
//   required_review_note → سبب الرفض أو ملاحظة الموظف
//
// ⚠️ سبب الرفض إجباري (5 أحرف على الأقل) مثل review_action.php
//    لأن الشركة يجب أن تعرف ماذا تُصحّح، ولأن الخلاف مع شركة
//    بلا سبب مكتوب يعني كلمة موظف ضد كلمة شركة
//
// ⚠️ الدرجة "ج" مُفعَّلة: خدمة العملاء تعدّل كل شيء
//    وكود الدرجة "أ" (المدير فقط) موجود في الخطوة 1 معلَّقاً
// ============================================================

// تضمين ملف الاتصال (مستويان للوراء لأن الملف داخل admin/companies)
include "../../connect.php";

// ============================================================
// الخطوة 1: التحقق من توكن الإدارة وجلب بيانات الموظف
//
// ⭐ التوكن يحمل هوية الموظف فلا نستقبل admin_id من الطلب
//    وإلا استطاع موظف انتحال هوية موظف آخر في سجل المراجعة
// ============================================================
// التحقق من التوكن وإرجاع بيانات الموظف الموثّق
$currentAdmin = checkAdminToken();

// ⚠️ الخيار البديل (غير مُفعَّل): قصر القرار على المدير فقط
// ولتفعيله ضع // قبل السطر أعلاه وأزل التعليق عن السطر التالي
// $currentAdmin = checkAdminToken('manager');

// معرّف الموظف المنفّذ مأخوذ من التوكن لا من الطلب
$admin_id = (int) $currentAdmin['admin_id'];

// ============================================================
// الخطوة 2: استقبال معرّف الطلب والتحقق منه
// ============================================================
// معرّف طلب العمالة المراد تعديله - إجباري
$required_id = filterRequest("required_id");

// إذا لم يُرسل المعرّف نوقف التنفيذ فوراً
if (empty($required_id)) {
    // رسالة توضح الحقل المطلوب
    printFailure("الحقل required_id مطلوب");
    // إيقاف التنفيذ
    exit;
}

// تحويل المعرّف لرقم صحيح لحماية جملة WHERE من أي محتوى غير رقمي
$required_id = (int) $required_id;

// ============================================================
// الخطوة 3: جلب بيانات الطلب الحالية
//
// ⭐ نحتاجها للتأكد أن الطلب موجود ولمعرفة حالته قبل التعديل
//    حتى نُرجعها في الرد فيرى الموظف ما تغيّر بالضبط
// ============================================================
// تجهيز استعلام محمي لجلب الصف الحالي مع بيانات الشركة
$stmtCurrent = $con->prepare(
    "SELECT
        j.required_id,
        j.required_company_id,
        j.required_approval_status,
        j.required_review_note,
        c.company_name,
        c.company_phone_number
     FROM job_details_required j
     INNER JOIN company_registering c ON c.company_id = j.required_company_id
     WHERE j.required_id = ?
     LIMIT 1"
);
// تنفيذ الاستعلام بتمرير معرّف الطلب
$stmtCurrent->execute([$required_id]);
// جلب الصف الحالي
$current = $stmtCurrent->fetch(PDO::FETCH_ASSOC);

// إذا لم يوجد الطلب نرفض التعديل
if (!$current) {
    // رسالة توضح أن الطلب غير موجود
    printFailure("طلب العمالة غير موجود", 404);
    // إيقاف التنفيذ
    exit;
}

// حفظ الحالة القديمة قبل التعديل لإرجاعها في الرد
$oldStatus = $current['required_approval_status'];

// ============================================================
// الخطوة 4: استقبال الحقول القابلة للتعديل (كلها اختيارية)
// ============================================================
// اسم المهنة الجديد
$job_title = filterRequest("required_job_title");
// شرح المواصفات الجديد
$job_desc = filterRequest("required_job_description");
// حالة توثيق العامل الجديدة
$is_verified = filterRequest("required_is_verified_worker");
// عدد العمال الجديد
$workersCount = filterRequest("required_workers_count");
// تاريخ ووقت بدء العمل الجديد
$startDate = filterRequest("required_start_date_time");
// ============================================================
// ⭐ استقبال حقول شروط العمل (إضافة 2026 — كلها اختيارية في التعديل)
// ============================================================
// عدد ساعات العمل اليومية
$dailyHours = filterRequest("required_daily_work_hours");
// عدد أيام العمل في الشهر
$monthlyDays = filterRequest("required_monthly_work_days");
// ساعة بداية العمل
$shiftStart = filterRequest("required_shift_start_time");
// ساعة نهاية العمل
$shiftEnd = filterRequest("required_shift_end_time");
// المرتب الشهري
$salary = filterRequest("required_monthly_salary");
// ⭐ قرار الإدارة على الطلب
$approvalStatus = filterRequest("required_approval_status");
// ⭐ سبب الرفض أو ملاحظة الموظف
$reviewNote = trim(filterRequest("required_review_note"));

// هل أُرسلت حقول تعديل تفاصيل الطلب؟
$hasDataEdit = $job_title !== ''
    || $job_desc !== ''
    || $is_verified !== ''
    || $workersCount !== ''
    || $startDate !== ''
    || $dailyHours !== ''
    || $monthlyDays !== ''
    || $shiftStart !== ''
    || $shiftEnd !== ''
    || $salary !== '';

// تعديل بيانات الطلب يُفحص على السيرفر بصلاحية jobs.edit
if ($hasDataEdit) {
    requirePermission($currentAdmin, 'jobs.edit');
}

// قبول أو رفض الطلب يُفحص على السيرفر بصلاحية jobs.approve
if ($approvalStatus !== '') {
    requirePermission($currentAdmin, 'jobs.approve');
}

// إن لم يُرسل قرار ولا تفاصيل نمنع الطلب الفارغ بصلاحية التعديل
if ($approvalStatus === '' && !$hasDataEdit) {
    requirePermission($currentAdmin, 'jobs.edit');
}

// ============================================================
// الخطوة 5: بناء مصفوفة التحديث بالحقول المُرسَلة فقط
// ============================================================
// تهيئة مصفوفة فارغة سنملأها بما أرسله الموظف
$updateData = [];

// ---------- اسم المهنة ----------
// إضافة اسم المهنة إن أُرسل
if ($job_title !== '') {
    $updateData["required_job_title"] = $job_title;
}

// ---------- شرح المواصفات ----------
// إضافة الشرح إن أُرسل
if ($job_desc !== '') {
    $updateData["required_job_description"] = $job_desc;
}

// ---------- حالة توثيق العامل ----------
// نتحقق فقط إذا أُرسل الحقل
if ($is_verified !== '') {
    // قائمة القيم المسموحة (نفس قيم الـ ENUM حرفياً)
    $allowedVerification = ['verified', 'unverified'];

    // إذا كانت القيمة غير معروفة نرفض الطلب
    if (!in_array($is_verified, $allowedVerification, true)) {
        // رسالة توضح القيم المسموحة
        printFailure("حالة التوثيق غير صحيحة، القيم المسموحة: verified أو unverified");
        // إيقاف التنفيذ
        exit;
    }
    // إضافة حالة التوثيق للتحديث
    $updateData["required_is_verified_worker"] = $is_verified;
}

// ---------- عدد العمال ----------
// نتحقق فقط إذا أُرسل الحقل
if ($workersCount !== '') {
    // تحويل العدد لرقم صحيح
    $workersCount = (int) $workersCount;

    // العدد يجب أن يكون واحداً على الأقل
    if ($workersCount < 1) {
        // رسالة توضح الحد الأدنى
        printFailure("عدد العمال المطلوبين يجب أن يكون 1 على الأقل");
        // إيقاف التنفيذ
        exit;
    }

    // ⚠️ حد أقصى للحماية من الأخطاء
    if ($workersCount > 500) {
        // رسالة توضح الحد الأقصى
        printFailure("عدد العمال المطلوبين كبير جداً، الحد الأقصى 500 عامل");
        // إيقاف التنفيذ
        exit;
    }
    // إضافة العدد للتحديث
    $updateData["required_workers_count"] = $workersCount;
}

// ---------- تاريخ ووقت بدء العمل ----------
// نتحقق فقط إذا أُرسل الحقل
if ($startDate !== '') {
    // استبدال حرف T الفاصل في صيغة ISO بمسافة
    $startDateClean = str_replace('T', ' ', trim($startDate));

    // ⚠️ إزالة أجزاء الثانية العشرية (.000) التي ترسلها فلاتر
    if (strpos($startDateClean, '.') !== false) {
        // قص النص عند أول نقطة
        $startDateClean = substr($startDateClean, 0, strpos($startDateClean, '.'));
    }

    // ⚠️ إزالة علامة المنطقة الزمنية Z إن وُجدت
    $startDateClean = rtrim($startDateClean, 'Zz');
    // إزالة أي مسافات زائدة بعد التنظيف
    $startDateClean = trim($startDateClean);

    // ⭐ إذا أُرسل التاريخ بلا وقت نُضيف بداية اليوم تلقائياً
    if (strlen($startDateClean) === 10) {
        $startDateClean .= ' 00:00:00';
    }

    // ⭐ إذا أُرسل بصيغة الساعة والدقيقة فقط نُضيف الثواني
    if (strlen($startDateClean) === 16) {
        $startDateClean .= ':00';
    }

    // التحقق أن التاريخ صالح فعلاً بالصيغة المطلوبة
    $dateObject = DateTime::createFromFormat('Y-m-d H:i:s', $startDateClean);

    // إذا فشل التحويل أو اختلف الناتج عن المُدخَل فالتاريخ غير صالح
    if (!$dateObject || $dateObject->format('Y-m-d H:i:s') !== $startDateClean) {
        // رسالة توضح الصيغة المطلوبة
        printFailure("تاريخ ووقت بدء العمل غير صحيح، الصيغة المطلوبة: YYYY-MM-DD HH:MM:SS");
        // إيقاف التنفيذ
        exit;
    }

    // ⚠️ لا نفرض أن التاريخ مستقبلي هنا بخلاف ملف الإضافة
    //    لأن الإدارة قد تحتاج تصحيح تاريخ طلب قديم بأثر رجعي

    // إضافة التاريخ للتحديث
    $updateData["required_start_date_time"] = $startDateClean;
}

// ============================================================
// ⭐ حقول شروط العمل (كلها اختيارية — نُحدّث ما أُرسل فقط)
// ============================================================

// ---------- عدد ساعات العمل اليومية ----------
// نتحقق فقط إذا أُرسل الحقل
if ($dailyHours !== '') {
    // تحويل القيمة لرقم صحيح
    $dailyHours = (int) $dailyHours;

    // التحقق من النطاق المنطقي (من ساعة إلى 24)
    if ($dailyHours < 1 || $dailyHours > 24) {
        // رسالة توضح النطاق المسموح
        printFailure("عدد ساعات العمل اليومية يجب أن يكون بين 1 و 24 ساعة");
        // إيقاف التنفيذ
        exit;
    }
    // إضافة الساعات للتحديث
    $updateData["required_daily_work_hours"] = $dailyHours;
}

// ---------- عدد أيام العمل في الشهر ----------
// نتحقق فقط إذا أُرسل الحقل
if ($monthlyDays !== '') {
    // تحويل القيمة لرقم صحيح
    $monthlyDays = (int) $monthlyDays;

    // التحقق من النطاق المنطقي (من يوم إلى 31)
    if ($monthlyDays < 1 || $monthlyDays > 31) {
        // رسالة توضح النطاق المسموح
        printFailure("عدد أيام العمل في الشهر يجب أن يكون بين 1 و 31 يوماً");
        // إيقاف التنفيذ
        exit;
    }
    // إضافة الأيام للتحديث
    $updateData["required_monthly_work_days"] = $monthlyDays;
}

// ---------- ⭐ دالة تنظيف الوقت (نفس منطق ملف الإضافة) ----------
// ⚠️ نُعرّفها هنا أيضاً لأن كل ملف مستقل
$cleanTime = function (string $raw): ?string {
    // إزالة المسافات الزائدة من الطرفين
    $t = trim($raw);
    // كشف صيغة 12 ساعة
    $isPM = stripos($t, 'PM') !== false;
    $isAM = stripos($t, 'AM') !== false;
    // حذف نص AM أو PM
    $t = trim(str_ireplace(['AM', 'PM'], '', $t));
    // تقسيم الوقت عند النقطتين
    $parts = explode(':', $t);
    // يجب أن يحتوي ساعة ودقيقة على الأقل
    if (count($parts) < 2)
        return null;
    // قراءة الأجزاء كأرقام
    $h = (int) $parts[0];
    $m = (int) $parts[1];
    $s = isset($parts[2]) ? (int) $parts[2] : 0;
    // تحويل صيغة 12 ساعة لـ 24 ساعة
    if ($isPM && $h < 12)
        $h += 12;
    if ($isAM && $h === 12)
        $h = 0;
    // التحقق من النطاقات
    if ($h < 0 || $h > 23 || $m < 0 || $m > 59 || $s < 0 || $s > 59)
        return null;
    // إرجاع الوقت بصيغة HH:MM:SS
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
};

// ---------- ساعة بداية العمل ----------
// نتحقق فقط إذا أُرسل الحقل
if ($shiftStart !== '') {
    // تنظيف الوقت والتحقق منه
    $shiftStartClean = $cleanTime($shiftStart);

    // إذا فشل التنظيف فالصيغة غير صالحة
    if ($shiftStartClean === null) {
        // رسالة توضح الصيغة المطلوبة
        printFailure("ساعة بداية العمل غير صحيحة، الصيغة المطلوبة: HH:MM مثل 08:00");
        // إيقاف التنفيذ
        exit;
    }
    // إضافة ساعة البداية للتحديث
    $updateData["required_shift_start_time"] = $shiftStartClean;
}

// ---------- ساعة نهاية العمل ----------
// نتحقق فقط إذا أُرسل الحقل
if ($shiftEnd !== '') {
    // تنظيف الوقت والتحقق منه
    $shiftEndClean = $cleanTime($shiftEnd);

    // إذا فشل التنظيف فالصيغة غير صالحة
    if ($shiftEndClean === null) {
        // رسالة توضح الصيغة المطلوبة
        printFailure("ساعة نهاية العمل غير صحيحة، الصيغة المطلوبة: HH:MM مثل 16:00");
        // إيقاف التنفيذ
        exit;
    }
    // إضافة ساعة النهاية للتحديث
    $updateData["required_shift_end_time"] = $shiftEndClean;
}

// ---------- المرتب الشهري ----------
// نتحقق فقط إذا أُرسل الحقل
if ($salary !== '') {
    // ⚠️ تنظيف الفواصل لأن (float) على "3,500" تُنتج 3 فقط
    $salaryClean = str_replace([',', ' ', '٫'], '', trim($salary));

    // التحقق أن القيمة رقمية فعلاً
    if (!is_numeric($salaryClean)) {
        // رسالة توضح أن المرتب يجب أن يكون رقماً
        printFailure("المرتب الشهري يجب أن يكون رقماً صحيحاً");
        // إيقاف التنفيذ
        exit;
    }

    // تحويل المرتب لرقم عشري
    $salaryClean = (float) $salaryClean;

    // ⚠️ المرتب لا يمكن أن يكون سالباً
    if ($salaryClean < 0) {
        // رسالة توضح أن المرتب لا يكون سالباً
        printFailure("المرتب الشهري لا يمكن أن يكون رقماً سالباً");
        // إيقاف التنفيذ
        exit;
    }

    // ⚠️ حد أقصى يحمي من العمود DECIMAL(10,2)
    if ($salaryClean > 9999999) {
        // رسالة توضح الحد الأقصى
        printFailure("المرتب الشهري كبير جداً، الحد الأقصى 9,999,999 جنيه");
        // إيقاف التنفيذ
        exit;
    }
    // إضافة المرتب للتحديث
    $updateData["required_monthly_salary"] = $salaryClean;
}

// ============================================================
// الخطوة 6: ⭐ معالجة قرار الإدارة وسجل المراجعة
// ============================================================
// متغير يوضح هل تغيّرت حالة الموافقة في هذا الطلب
$statusChanged = false;

// نتحقق فقط إذا أُرسلت حالة الموافقة
if ($approvalStatus !== '') {
    // قائمة حالات الموافقة المسموحة (نفس قيم الـ ENUM حرفياً)
    $allowedStatus = ['pending', 'approved', 'rejected'];

    // إذا كانت الحالة غير معروفة نرفض الطلب
    if (!in_array($approvalStatus, $allowedStatus, true)) {
        // رسالة توضح القيم المسموحة
        printFailure("حالة الموافقة غير صحيحة، القيم المسموحة: " . implode(', ', $allowedStatus));
        // إيقاف التنفيذ
        exit;
    }

    // ⚠️⚠️ سبب الرفض إجباري — نفس قاعدة review_action.php
    // لأن الشركة يجب أن تعرف ماذا تُصحّح
    if ($approvalStatus === 'rejected' && mb_strlen($reviewNote) < 5) {
        // رسالة توضح أن السبب مطلوب مع الحد الأدنى
        printFailure("سبب الرفض إجباري ويجب أن يكون 5 أحرف على الأقل");
        // إيقاف التنفيذ
        exit;
    }

    // إضافة حالة الموافقة للتحديث
    $updateData["required_approval_status"] = $approvalStatus;
    // رفع علم تغيّر الحالة
    $statusChanged = true;
}

// ---------- تسجيل من راجع ومتى وما ملاحظته ----------
// ⭐ نُسجّل سجل المراجعة في حالتين:
//   1. عند تغيير حالة الموافقة (قرار)
//   2. عند إرسال ملاحظة مع تعديل بيانات فقط
if ($statusChanged) {
    // تسجيل معرّف الموظف المنفّذ (من التوكن لا من الطلب)
    $updateData["required_reviewed_by"] = $admin_id;
    // تسجيل تاريخ ووقت القرار
    $updateData["required_reviewed_at"] = date("Y-m-d H:i:s");

    // ⭐ تسجيل الملاحظة أو مسحها
    // نص فارغ يُحوَّل لـ NULL حقيقية بفضل إصلاح updateData
    // فلو أُعيد الطلب للانتظار تُمسح ملاحظة الرفض القديمة
    $updateData["required_review_note"] = $reviewNote !== '' ? $reviewNote : null;

} elseif ($reviewNote !== '') {
    // في حالة التعديل بلا قرار نُسجّل الملاحظة فقط إن أُرسلت
    $updateData["required_review_note"] = $reviewNote;
    // ونُسجّل من عدّل ومتى
    $updateData["required_reviewed_by"] = $admin_id;
    $updateData["required_reviewed_at"] = date("Y-m-d H:i:s");
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
    "job_details_required",
    $updateData,
    "required_id = $required_id",
    false
);

// ============================================================
// الخطوة 9: جلب البيانات المحدَّثة لإرجاعها
//
// ⚠️ لا نعتبر rowCount = 0 فشلاً
//    لأن MySQL تُرجع 0 إذا كانت القيم الجديدة مطابقة للقديمة تماماً
// ============================================================
// تجهيز استعلام لجلب الصف بعد التعديل مع بيانات الشركة والمراجع
$stmtAfter = $con->prepare(
    "SELECT
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
        c.company_name,
        c.company_business_type,
        c.company_phone_number,
        c.company_secondary_phone,
        u.users_full_name,
        u.users_phone,
        -- ⭐ اسم الموظف الذي راجع (LEFT JOIN لأن المراجعة قد لم تحدث)
        au.admin_full_name AS reviewed_by_name,
        au.admin_role      AS reviewed_by_role
     FROM job_details_required j
     INNER JOIN company_registering c ON c.company_id = j.required_company_id
     INNER JOIN users u ON u.users_id = c.company_user_id
     LEFT JOIN admin_users au ON au.admin_id = j.required_reviewed_by
     WHERE j.required_id = ?
     LIMIT 1"
);
// تنفيذ الاستعلام بتمرير معرّف الطلب
$stmtAfter->execute([$required_id]);
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
// الخطوة 10: تجهيز رسالة عربية واضحة حسب ما حدث
// ============================================================
// نبني الرسالة حسب الحالة الجديدة إن تغيّرت
if ($statusChanged) {
    // رسالة القرار حسب الحالة الجديدة
    $actionMessage = match ($updated['required_approval_status']) {
        'approved' => 'تم قبول طلب العمالة، يمكنك الآن التواصل مع الشركة',
        'rejected' => 'تم رفض طلب العمالة وإرسال السبب للشركة',
        'pending' => 'تم إرجاع الطلب لقائمة الانتظار',
        default => 'تم تنفيذ الإجراء',
    };
} else {
    // رسالة التعديل على البيانات بلا قرار
    $actionMessage = 'تم تحديث تفاصيل طلب العمالة بنجاح';
}

// ============================================================
// الخطوة 11: إرسال الرد النهائي للوحة التحكم
// ============================================================
echo json_encode([
    // حالة العملية
    "status" => "success",
    // رسالة عربية جاهزة للعرض في لوحة التحكم
    "message" => $actionMessage,
    // بيانات النتيجة
    "data" => [
        // معرّف طلب العمالة
        "required_id" => (int) $updated['required_id'],
        // معرّف الشركة
        "required_company_id" => (int) $updated['required_company_id'],
        // اسم الشركة
        "company_name" => $updated['company_name'],
        // ⭐ ترجمة نوع النشاط للعربية
        "business_type_ar" => match ($updated['company_business_type']) {
            'company' => 'شركة',
            'factory' => 'مصنع',
            'commercial_activity' => 'نشاط تجاري',
            default => 'غير محدد',
        },
        // ⭐ هاتف الشركة ليتواصل الموظف معها مباشرة
        "company_phone_number" => $updated['company_phone_number'],
        // هاتف الشركة الإضافي
        "company_secondary_phone" => $updated['company_secondary_phone'],
        // اسم صاحب الحساب
        "owner_full_name" => $updated['users_full_name'],
        // هاتف صاحب الحساب
        "owner_phone" => $updated['users_phone'],
        // اسم المهنة المطلوبة
        "required_job_title" => $updated['required_job_title'],
        // شرح المواصفات
        "required_job_description" => $updated['required_job_description'],
        // حالة توثيق العامل كما هي مخزَّنة
        "required_is_verified_worker" => $updated['required_is_verified_worker'],
        // ⭐ ترجمة حالة التوثيق للعربية
        "verification_ar" => $updated['required_is_verified_worker'] === 'verified'
            ? 'موثق' : 'غير موثق',
        // عدد العمال المطلوبين
        "required_workers_count" => (int) $updated['required_workers_count'],

        // ===== ⭐ شروط العمل (إضافة 2026) =====
        // عدد ساعات العمل اليومية
        "required_daily_work_hours" => (int) $updated['required_daily_work_hours'],
        // عدد أيام العمل في الشهر
        "required_monthly_work_days" => (int) $updated['required_monthly_work_days'],
        // ساعة بداية العمل
        "required_shift_start_time" => $updated['required_shift_start_time'],
        // ساعة نهاية العمل
        "required_shift_end_time" => $updated['required_shift_end_time'],
        // ⭐ المواعيد بصيغة قصيرة جاهزة للعرض
        "shift_time_display" => substr($updated['required_shift_start_time'], 0, 5)
            . ' - ' . substr($updated['required_shift_end_time'], 0, 5),
        // المرتب الشهري كرقم
        "required_monthly_salary" => (float) $updated['required_monthly_salary'],
        // ⭐ المرتب بصيغة منسّقة جاهزة للعرض
        "salary_display" => number_format((float) $updated['required_monthly_salary'], 0) . ' ج.م',
        // ⭐ إجمالي المرتبات لكل العمال (الالتزام المالي)
        "total_salaries" => (float) $updated['required_monthly_salary']
            * (int) $updated['required_workers_count'],
        // ⭐ إجمالي ساعات العمل الشهرية للعامل الواحد
        "monthly_total_hours" => (int) $updated['required_daily_work_hours']
            * (int) $updated['required_monthly_work_days'],
        // تاريخ ووقت بدء العمل
        "required_start_date_time" => $updated['required_start_date_time'],

        // ===== حالة الموافقة =====
        // ⭐ الحالة قبل التعديل ليرى الموظف ما تغيّر
        "old_approval_status" => $oldStatus,
        // الحالة بعد التعديل
        "required_approval_status" => $updated['required_approval_status'],
        // ⭐ ترجمة حالة الموافقة للعربية
        "approval_status_ar" => match ($updated['required_approval_status']) {
            'pending' => 'قيد المراجعة',
            'approved' => 'مقبول',
            'rejected' => 'مرفوض',
            default => 'غير محدد',
        },
        // هل تغيّرت الحالة في هذا الطلب؟
        "status_changed" => $statusChanged,

        // ===== ⭐ سجل المراجعة =====
        "review" => [
            // سبب الرفض أو ملاحظة الموظف
            "review_note" => $updated['required_review_note'],
            // تاريخ ووقت المراجعة
            "reviewed_at" => $updated['required_reviewed_at'],
            // معرّف الموظف المراجع
            "reviewed_by_id" => $updated['required_reviewed_by']
                ? (int) $updated['required_reviewed_by'] : null,
            // اسم الموظف المراجع
            "reviewed_by_name" => $updated['reviewed_by_name'],
            // دور الموظف المراجع
            "reviewed_by_role" => $updated['reviewed_by_role'],
            // ⭐ ترجمة الدور للعربية
            "reviewed_by_role_ar" => $updated['reviewed_by_role'] === 'manager'
                ? 'مدير' : ($updated['reviewed_by_role'] ? 'خدمة عملاء' : null),
        ],

        // تاريخ إرسال الطلب
        "required_created_at" => $updated['required_created_at'],
        // تاريخ آخر تحديث
        "required_updated_at" => $updated['required_updated_at'],
        // ⭐ أسماء الحقول التي عُدِّلت فعلاً في هذا الطلب
        "updated_fields" => array_keys($updateData),
        // ⭐ عدد الصفوف المتأثرة (0 يعني أن القيم مطابقة للقديمة)
        "affected_rows" => $result,
        // ⭐ بيانات الموظف الذي نفّذ الإجراء من التوكن
        "edited_by" => [
            "admin_id" => (int) $currentAdmin['admin_id'],
            "admin_name" => $currentAdmin['admin_full_name'],
            "admin_role" => $currentAdmin['admin_role'],
        ],
    ],
]);
?>