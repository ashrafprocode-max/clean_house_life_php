<?php
// ============================================================
// ملف: admin/contracts/create_contract.php
// الغرض: إنشاء عقد شركة أو أفراد مع بنود إلزامية وجدول دفعات
// الصلاحية المطلوبة: contracts.create
// الطريقة: POST
// يُستدعى من لوحة الإدارة عند حفظ مسودة عقد جديد.
// يُرجع معرّف العقد والرقم والكميات المالية وحالة draft.
// لا يُفعَّل العقد هنا؛ التوقيع ثم التحصيل يغيّران الحالة لاحقاً.
// ============================================================

// اتصال قاعدة البيانات والدوال العامة
include "../../connect.php";
// حساب العمولة والدفعات وترجمة الحالات
include "../../contracts/includes/contract_helper.php";

// جلسة الموظف إلزامية قبل أي كتابة
$currentAdmin = checkAdminToken();
// الإنشاء يحتاج contracts.create وليس الإخفاء في الواجهة فقط
requirePermission($currentAdmin, 'contracts.create');

// معرّف الموظف يُحفظ في contract_created_by للمراجعة
$adminId = (int) ($currentAdmin['admin_id'] ?? 0);

// نوع الطرف يحدد الشركة أو العميل وبادئة رقم العقد
$partyType = trim(filterRequest("contract_party_type"));
// رفض أي قيمة خارج القيمتين المعتمدتين
if ($partyType !== 'company' && $partyType !== 'individual') {
    // الرسالة بالعربية حتى تظهر مباشرة في اللوحة
    printFailure("حدد نوع العقد: شركة أو أفراد");
}

// العنوان يظهر في القائمة والطباعة
$title = trim(filterRequest("contract_title_ar"));
// بلا عنوان لا يمكن تمييز العقد لاحقاً
if ($title === '') {
    printFailure("عنوان العقد مطلوب");
}

// شركة الطرف لعقد الشركات؛ صفر يعني غير مربوط
$companyId = (int) filterRequest("contract_company_id");
// طلب التوظيف اختياري لربط العقد بمصدر العمالة
$jobRequestId = (int) filterRequest("contract_job_request_id");
// عميل الأفراد؛ صفر يعني غير مربوط
$usersId = (int) filterRequest("contract_users_id");
// حجز اختياري إن وُلد العقد من خدمة أفراد
$bookingId = (int) filterRequest("contract_booking_id");

// عقد الشركات بلا شركة يُنشئ طرفاً فارغاً
if ($partyType === 'company' && $companyId <= 0) {
    printFailure("معرّف الشركة مطلوب لعقد الشركات");
}
// عقد الأفراد بلا عميل يمنع التوقيع والدفع من التطبيق
if ($partyType === 'individual' && $usersId <= 0) {
    printFailure("معرّف العميل مطلوب لعقد الأفراد");
}

// عدد العمال يدخل معادلة إجمالي التكلفة
$workersCount = (int) filterRequest("contract_workers_count");
// إزالة الفواصل حتى لا تُقرأ التكلفة كنص
$workerMonthlyCost = (float) str_replace(',', '', filterRequest("contract_worker_monthly_cost"));
// مدة العقد بالأشهر تحدد عدد الدفعات
$durationMonths = (int) filterRequest("contract_duration_months");
// شهر واحد حد أدنى حتى لا يُحسب إجمالي صفر بمدة فارغة
if ($durationMonths < 1) {
    $durationMonths = 1;
}

// إعدادات العمولة من app_settings مع حد أدنى 10%
$settings = contractCompanyCommissionSettings($con);
// النسبة التي اختارتها الإدارة لهذا العقد
$commissionPercent = (float) str_replace(',', '', filterRequest("contract_commission_percent"));
// صفر يعني استخدم القيمة الافتراضية من الإعدادات
if ($commissionPercent <= 0) {
    $commissionPercent = $settings['value'];
}
// الإدارة ترفع النسبة فقط ولا تخفضها دون الحد الأدنى
if ($commissionPercent < $settings['min']) {
    printFailure(
        "عمولة التطبيق لا تقل عن " . $settings['min'] . "% من إجمالي تكلفة العمال",
        422
    );
}

// من يتحمل التأمينات للعرض في العقد المطبوع
$insuranceOn = trim(filterRequest("contract_insurance_on"));
// القيم المسموحة تطابق عمود العقد
$allowedInsurance = ['app', 'company', 'shared', 'not_specified'];
// قيمة غير معروفة تُحفظ كغير محدد بدل رفض الطلب
if (!in_array($insuranceOn, $allowedInsurance, true)) {
    $insuranceOn = 'not_specified';
}

// دورة التحصيل تحدد عدد الدفعات وخطوة الأشهر
$paymentCycle = trim(filterRequest("contract_payment_cycle"));
// القيم الأربع المعتمدة في المساعد
$allowedCycle = ['monthly', 'quarterly', 'yearly', 'once'];
// الافتراضي شهري لأنه الأكثر استخداماً للشركات
if (!in_array($paymentCycle, $allowedCycle, true)) {
    $paymentCycle = 'monthly';
}

// يوم التحصيل داخل الشهر لتواريخ الاستحقاق
$collectionDay = (int) filterRequest("contract_collection_day");
// 1..28 يتجنب نهاية فبراير غير الموجودة
if ($collectionDay < 1 || $collectionDay > 28) {
    // يوم اليوم إن لم يُحدد يوم صالح
    $collectionDay = (int) date('d');
    // قصّ اليوم إلى 28 إن كان 29/30/31
    if ($collectionDay > 28) {
        $collectionDay = 28;
    }
}

// وسيلة التحصيل المتفق عليها تظهر في التفاصيل
$paymentMethod = trim(filterRequest("contract_payment_method"));
// mixed يغطي الاتفاق على أكثر من وسيلة
$allowedMethod = ['app_card', 'app_wallet', 'bank_transfer', 'check', 'cash', 'instapay', 'mixed'];
// قيمة غير معروفة لا تُرفض حتى لا يُعطَّل الإنشاء
if (!in_array($paymentMethod, $allowedMethod, true)) {
    $paymentMethod = 'mixed';
}

// تاريخ بداية السريان
$startDate = trim(filterRequest("contract_start_date"));
// اليوم إن تُرك فارغاً حتى لا يُحفظ تاريخ فارغ
if ($startDate === '') {
    $startDate = date('Y-m-d');
}
// نهاية العقد = البداية + المدة ناقص يوماً حتى لا تتداخل الأشهر
$endDate = date('Y-m-d', strtotime($startDate . ' +' . $durationMonths . ' months -1 day'));

// ملاحظات داخلية للإدارة وليست بنوداً للطرف
$notes = trim(filterRequest("contract_notes"));

// حساب الإجمالي والعمولة المخفية وقيمة كل دفعة
$financials = contractComputeFinancials(
    $workersCount,
    $workerMonthlyCost,
    $durationMonths,
    $commissionPercent,
    $paymentCycle
);

// رقم فريد مثل CHL-C-2026-0007 أو CHL-I حسب الطرف
$code = contractNextCode($con, $partyType);

// معاملة واحدة: العقد + البنود الإلزامية + الدفعات
$con->beginTransaction();
try {
    // إدراج صف العقد كمسودة
    $stmt = $con->prepare(
        "INSERT INTO contracts (
            contract_code, contract_party_type, contract_company_id,
            contract_job_request_id, contract_users_id, contract_booking_id,
            contract_title_ar, contract_workers_count, contract_worker_monthly_cost,
            contract_duration_months, contract_labor_total, contract_commission_percent,
            contract_commission_amount, contract_insurance_on, contract_payment_cycle,
            contract_installments_count, contract_installment_amount, contract_collection_day,
            contract_payment_method, contract_start_date, contract_end_date,
            contract_written_at, contract_status, contract_notes, contract_created_by
         ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'draft', ?, ?
         )"
    );
    // ربط الحقول الاختيارية بـ null حتى لا تُحفظ أصفار كمعرفات
    $stmt->execute([
        $code,
        $partyType,
        $partyType === 'company' ? $companyId : null,
        $jobRequestId > 0 ? $jobRequestId : null,
        $partyType === 'individual' ? $usersId : null,
        $bookingId > 0 ? $bookingId : null,
        $title,
        $financials['workers_count'],
        $financials['worker_monthly_cost'],
        $financials['duration_months'],
        $financials['labor_total'],
        $financials['commission_percent'],
        $financials['commission_amount'],
        $insuranceOn,
        $paymentCycle,
        $financials['installments_count'],
        $financials['installment_amount'],
        $collectionDay,
        $paymentMethod,
        $startDate,
        $endDate,
        $notes !== '' ? $notes : null,
        $adminId,
    ]);
    // معرّف العقد الجديد لربط البنود والدفعات
    $contractId = (int) $con->lastInsertId();

    // قوالب البنود الإلزامية حسب نوع الطرف
    $templateParty = $partyType === 'individual' ? 'individual' : 'company';
    // النشطة والمطلوبة لهذا الطرف أو لكليهما
    $tplStmt = $con->prepare(
        "SELECT template_id, template_title_ar, template_body_ar, template_sort
         FROM contract_clause_templates
         WHERE template_is_active = 1
           AND (template_party_type = ? OR template_party_type = 'both')
           AND template_is_required = 1
         ORDER BY template_sort ASC, template_id ASC"
    );
    $tplStmt->execute([$templateParty]);
    // نسخ القوالب كنصوص ثابتة حتى لا يتغيّر العقد إن عُدّل القالب لاحقاً
    $templates = $tplStmt->fetchAll(PDO::FETCH_ASSOC);

    // بند منسوخ من قالب وليس مخصصاً
    $clauseInsert = $con->prepare(
        "INSERT INTO contract_clauses (
            clause_contract_id, clause_template_id, clause_title_ar,
            clause_body_ar, clause_sort, clause_is_custom
         ) VALUES (?, ?, ?, ?, ?, 0)"
    );
    // إدراج كل قالب إلزامي بنفس الترتيب
    foreach ($templates as $tpl) {
        $clauseInsert->execute([
            $contractId,
            $tpl['template_id'],
            $tpl['template_title_ar'],
            $tpl['template_body_ar'],
            $tpl['template_sort'],
        ]);
    }

    // توليد صفوف الدفعات حسب الدورة ويوم التحصيل
    contractGenerateInstallments(
        $con,
        $contractId,
        $financials,
        $startDate,
        $collectionDay
    );

    // سجل نشاط الإدارة إن وُجدت الدالة في المنصة
    if (function_exists('logAdminActivity')) {
        logAdminActivity(
            $currentAdmin,
            'contracts.create',
            'contract',
            $contractId,
            ['code' => $code, 'party_type' => $partyType]
        );
    }

    // تثبيت العقد والبنود والدفعات معاً
    $con->commit();
} catch (Throwable $e) {
    // إلغاء كل شيء حتى لا يبقى عقد بلا دفعات
    $con->rollBack();
    printFailure("تعذّر إنشاء العقد: " . $e->getMessage(), 500);
}

// الاستجابة للوحة لفتح التفاصيل وإكمال البنود
printSuccess([
    'contract_id' => $contractId,
    'contract_code' => $code,
    'labor_total' => $financials['labor_total'],
    'commission_percent' => $financials['commission_percent'],
    'commission_amount' => $financials['commission_amount'],
    'installments_count' => $financials['installments_count'],
    'installment_amount' => $financials['installment_amount'],
    'installment_commission' => $financials['installment_commission'],
    'status' => 'draft',
    'message' => 'تم إنشاء العقد كمسودة. أضف البنود ثم اطلب التوقيع.',
]);
