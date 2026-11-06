<?php
// ============================================================
// ملف: admin/contracts/preview_financials.php
// الغرض: معاينة حساب تكلفة العمال والعمولة والدفعات قبل الحفظ
// الصلاحية المطلوبة: contracts.view
// الطريقة: POST
// يُستدعى من نموذج إنشاء العقد عند تغيّر العدد أو المدة أو النسبة.
// لا يكتب في القاعدة. يرفع النسبة إلى الحد الأدنى بدل الرفض.
// ============================================================

// اتصال قاعدة البيانات لقراءة إعدادات العمولة
include "../../connect.php";
// دوال الحساب وترجمة دورة التحصيل
include "../../contracts/includes/contract_helper.php";

// جلسة الموظف إلزامية
$currentAdmin = checkAdminToken();
// المعاينة عرض حسابي وليست إنشاء عقد
requirePermission($currentAdmin, 'contracts.view');

// مدخلات المعادلة كما في إنشاء العقد
$workersCount = (int) filterRequest("contract_workers_count");
// إزالة الفواصل حتى تُحسب التكلفة رقماً
$workerMonthlyCost = (float) str_replace(',', '', filterRequest("contract_worker_monthly_cost"));
$durationMonths = (int) filterRequest("contract_duration_months");
$commissionPercent = (float) str_replace(',', '', filterRequest("contract_commission_percent"));
$paymentCycle = trim(filterRequest("contract_payment_cycle"));

// الحد الأدنى والنسبة الافتراضية من الإعدادات
$settings = contractCompanyCommissionSettings($con);
// شهر واحد حتى لا تُعرض مدة صفر في المعادلة
if ($durationMonths < 1) {
    $durationMonths = 1;
}
// صفر يعني استخدم الافتراضي بدل معاينة عمولة صفر
if ($commissionPercent <= 0) {
    $commissionPercent = $settings['value'];
}
// دورة غير معروفة تُعرض كشهري لتطابق الإنشاء لاحقاً
$allowedCycle = ['monthly', 'quarterly', 'yearly', 'once'];
if (!in_array($paymentCycle, $allowedCycle, true)) {
    $paymentCycle = 'monthly';
}

// المعاينة ترفع النسبة وتنبّه الواجهة بدل رفض الطلب
$belowMin = $commissionPercent < $settings['min'];
if ($belowMin) {
    $commissionPercent = $settings['min'];
}

// نفس معادلة الإنشاء حتى لا يختلف المعروض عن المحفوظ
$financials = contractComputeFinancials(
    $workersCount,
    $workerMonthlyCost,
    $durationMonths,
    $commissionPercent,
    $paymentCycle
);

// commission_hidden يذكر الواجهة أن العمولة لا تُعرض للشركة
printSuccess([
    'workers_count' => $financials['workers_count'],
    'worker_monthly_cost' => $financials['worker_monthly_cost'],
    'duration_months' => $financials['duration_months'],
    'labor_total' => $financials['labor_total'],
    'commission_percent' => $financials['commission_percent'],
    'commission_amount' => $financials['commission_amount'],
    'commission_hidden' => true,
    'min_commission_percent' => $settings['min'],
    'commission_raised_to_min' => $belowMin,
    'payment_cycle' => $financials['payment_cycle'],
    'payment_cycle_ar' => contractCycleAr($financials['payment_cycle']),
    'installments_count' => $financials['installments_count'],
    'installment_amount' => $financials['installment_amount'],
    'installment_commission' => $financials['installment_commission'],
    'formula_ar' => $workersCount . ' عمال × ' . $workerMonthlyCost
        . ' ج.م × ' . $durationMonths . ' شهر = ' . $financials['labor_total'] . ' ج.م',
]);
