<?php
// ============================================================
// ملف: admin/contracts/view_contract.php
// الغرض: تفاصيل عقد واحد مع البنود والدفعات والتوقيع
// الصلاحية المطلوبة: contracts.view
// الطريقة: POST
// يُستدعى من شاشة تفاصيل العقد في اللوحة.
// يُرجع العقد والبنود والدفعات والتوقيعات وصلاحيات الأزرار.
// لا يطبع رقم الهاتف في السجلات؛ يُمرَّر للعرض فقط.
// ============================================================

// اتصال قاعدة البيانات
include "../../connect.php";
// ترجمة الحالات والدورة والتأمينات ووسيلة التحصيل
include "../../contracts/includes/contract_helper.php";

// جلسة الموظف إلزامية
$currentAdmin = checkAdminToken();
requirePermission($currentAdmin, 'contracts.view');

// معرّف العقد المطلوب
$contractId = (int) filterRequest("contract_id");
if ($contractId <= 0) {
    printFailure("contract_id is required");
}

// العقد مع اسم الشركة أو العميل ورقم الهاتف للتواصل من اللوحة
$stmt = $con->prepare(
    "SELECT c.*,
            cr.company_name, cr.company_type,
            u.users_full_name, u.users_phone
     FROM contracts c
     LEFT JOIN company_registering cr ON cr.company_id = c.contract_company_id
     LEFT JOIN users u ON u.users_id = c.contract_users_id
     WHERE c.contract_id = ?
     LIMIT 1"
);
$stmt->execute([$contractId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    printFailure("العقد غير موجود", 404);
}

// بنود الاتفاق مرتبة للعرض والتحرير قبل التوقيع
$clausesStmt = $con->prepare(
    "SELECT clause_id, clause_template_id, clause_title_ar, clause_body_ar,
            clause_sort, clause_is_custom
     FROM contract_clauses
     WHERE clause_contract_id = ?
     ORDER BY clause_sort ASC, clause_id ASC"
);
$clausesStmt->execute([$contractId]);
$clauses = array_map(static function ($c) {
    return [
        'clause_id' => (int) $c['clause_id'],
        'template_id' => $c['clause_template_id'] ? (int) $c['clause_template_id'] : null,
        'title_ar' => $c['clause_title_ar'],
        'body_ar' => $c['clause_body_ar'],
        'sort' => (int) $c['clause_sort'],
        'is_custom' => (int) $c['clause_is_custom'] === 1,
    ];
}, $clausesStmt->fetchAll(PDO::FETCH_ASSOC));

// جدول الدفعات بالترتيب الزمني
$instStmt = $con->prepare(
    "SELECT * FROM contract_installments
     WHERE installment_contract_id = ?
     ORDER BY installment_number ASC"
);
$instStmt->execute([$contractId]);
$installments = array_map(static function ($i) {
    return [
        'installment_id' => (int) $i['installment_id'],
        'number' => (int) $i['installment_number'],
        'due_date' => $i['installment_due_date'],
        'amount' => (float) $i['installment_amount'],
        'commission' => (float) $i['installment_commission'],
        'status' => $i['installment_status'],
        'status_ar' => contractInstallmentStatusAr($i['installment_status']),
        'paid_at' => $i['installment_paid_at'],
        'paid_via' => $i['installment_paid_via'],
        'method' => $i['installment_method'],
        'method_ar' => contractMethodAr($i['installment_method']),
        'evidence_file' => $i['installment_evidence_file'],
        'evidence_type' => $i['installment_evidence_type'],
        'admin_note' => $i['installment_admin_note'],
        'payment_id' => $i['installment_payment_id'] ? (int) $i['installment_payment_id'] : null,
        'can_pay' => in_array($i['installment_status'], ['pending', 'due', 'overdue'], true),
    ];
}, $instStmt->fetchAll(PDO::FETCH_ASSOC));

// سجل التوقيعات الإلكترونية والورقية دون IP في الاستجابة
$signStmt = $con->prepare(
    "SELECT sign_id, sign_name, sign_accepted, sign_channel, sign_created_at
     FROM contract_signatures
     WHERE sign_contract_id = ?
     ORDER BY sign_id DESC"
);
$signStmt->execute([$contractId]);
$signatures = array_map(static function ($s) {
    return [
        'sign_id' => (int) $s['sign_id'],
        'name' => $s['sign_name'],
        'accepted' => (int) $s['sign_accepted'] === 1,
        'channel' => $s['sign_channel'],
        'created_at' => $s['sign_created_at'],
    ];
}, $signStmt->fetchAll(PDO::FETCH_ASSOC));

// الدفعة الأولى لتحديد زر التحصيل الأول بعد التوقيع
$first = $installments[0] ?? null;

// حزمة واحدة للشاشة حتى لا تُطلب ثلاث نقاط نهاية
printSuccess([
    'contract' => [
        'contract_id' => (int) $row['contract_id'],
        'contract_code' => $row['contract_code'],
        'party_type' => $row['contract_party_type'],
        'party_type_ar' => contractPartyAr($row['contract_party_type']),
        'title_ar' => $row['contract_title_ar'],
        'company_id' => $row['contract_company_id'] ? (int) $row['contract_company_id'] : null,
        'company_name' => $row['company_name'],
        'users_id' => $row['contract_users_id'] ? (int) $row['contract_users_id'] : null,
        'users_full_name' => $row['users_full_name'],
        'users_phone' => $row['users_phone'] ?? null,
        'job_request_id' => $row['contract_job_request_id'] ? (int) $row['contract_job_request_id'] : null,
        'booking_id' => $row['contract_booking_id'] ? (int) $row['contract_booking_id'] : null,
        'workers_count' => (int) $row['contract_workers_count'],
        'worker_monthly_cost' => (float) $row['contract_worker_monthly_cost'],
        'duration_months' => (int) $row['contract_duration_months'],
        'labor_total' => (float) $row['contract_labor_total'],
        'commission_percent' => (float) $row['contract_commission_percent'],
        'commission_amount' => (float) $row['contract_commission_amount'],
        'insurance_on' => $row['contract_insurance_on'],
        'insurance_on_ar' => contractInsuranceAr($row['contract_insurance_on']),
        'payment_cycle' => $row['contract_payment_cycle'],
        'payment_cycle_ar' => contractCycleAr($row['contract_payment_cycle']),
        'installments_count' => (int) $row['contract_installments_count'],
        'installment_amount' => (float) $row['contract_installment_amount'],
        'collection_day' => $row['contract_collection_day'] ? (int) $row['contract_collection_day'] : null,
        'payment_method' => $row['contract_payment_method'],
        'payment_method_ar' => contractMethodAr($row['contract_payment_method']),
        'start_date' => $row['contract_start_date'],
        'end_date' => $row['contract_end_date'],
        'written_at' => $row['contract_written_at'],
        'signed_at' => $row['contract_signed_at'],
        'signed_name' => $row['contract_signed_name'],
        'e_signed' => (int) $row['contract_e_signed'] === 1,
        'paper_scan' => $row['contract_paper_scan'],
        'pdf_file' => $row['contract_pdf_file'],
        'status' => $row['contract_status'],
        'status_ar' => contractStatusAr($row['contract_status']),
        'notes' => $row['contract_notes'],
        'can_pay_first' => contractCanPayFirstInstallment($row, $first),
        'can_edit' => adminCan($currentAdmin, 'contracts.edit'),
        'can_record_payment' => adminCan($currentAdmin, 'payments.manage'),
        'can_approve' => adminCan($currentAdmin, 'contracts.approve'),
    ],
    'clauses' => $clauses,
    'installments' => $installments,
    'signatures' => $signatures,
]);
