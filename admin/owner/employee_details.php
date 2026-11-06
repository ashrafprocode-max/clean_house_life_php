<?php
// ============================================================
// ملف: admin/owner/employee_details.php
// الغرض: تفاصيل موظف تشغيلي مع سجل معاملاته المالية
// الطريقة: POST — للمالك فقط
// ============================================================

include "_owner_boot.php";

$employeeId = (int) filterRequest("employee_id");
if ($employeeId <= 0) {
    printFailure("معرّف الموظف مطلوب");
    exit;
}

$stmt = $con->prepare(
    "SELECT
        employee_id,
        employee_full_name,
        employee_phone,
        employee_whatsapp,
        employee_job_code,
        employee_job_title,
        employee_national_id,
        employee_hire_date,
        employee_employment_type,
        employee_base_salary,
        employee_bank_account,
        employee_notes,
        employee_id_card_front,
        employee_id_card_back,
        employee_criminal_record,
        employee_contract_pdf,
        employee_is_active,
        employee_created_at
     FROM employees
     WHERE employee_id = ?
     LIMIT 1"
);
$stmt->execute([$employeeId]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$r) {
    printFailure("الموظف غير موجود", 404);
    exit;
}

$txStmt = $con->prepare(
    "SELECT
        eft_id,
        eft_employee_id,
        eft_type,
        eft_amount,
        eft_month,
        eft_year,
        eft_date,
        eft_expense_id,
        eft_notes,
        eft_created_at
     FROM employee_financial_transactions
     WHERE eft_employee_id = ?
     ORDER BY eft_date DESC, eft_id DESC
     LIMIT 50"
);
$txStmt->execute([$employeeId]);
$txs = $txStmt->fetchAll(PDO::FETCH_ASSOC);

$typeAr = [
    'salary' => 'راتب',
    'advance' => 'سلفة',
    'deduction' => 'خصم',
    'bonus' => 'مكافأة',
    'allowance' => 'بدل',
];

$transactions = array_map(function ($t) use ($typeAr) {
    return [
        "eft_id" => (int) $t['eft_id'],
        "eft_employee_id" => (int) $t['eft_employee_id'],
        "eft_type" => $t['eft_type'],
        "eft_type_ar" => $typeAr[$t['eft_type']] ?? $t['eft_type'],
        "eft_amount" => (float) $t['eft_amount'],
        "eft_month" => $t['eft_month'] !== null ? (int) $t['eft_month'] : null,
        "eft_year" => $t['eft_year'] !== null ? (int) $t['eft_year'] : null,
        "eft_date" => $t['eft_date'],
        "eft_expense_id" => $t['eft_expense_id'] !== null ? (int) $t['eft_expense_id'] : null,
        "eft_notes" => $t['eft_notes'],
        "eft_created_at" => $t['eft_created_at'],
    ];
}, $txs);

$phone = (string) $r['employee_phone'];
$wa = $r['employee_whatsapp'] !== null && $r['employee_whatsapp'] !== ''
    ? (string) $r['employee_whatsapp']
    : $phone;

echo json_encode([
    "status" => "success",
    "data" => [
        "employee_id" => (int) $r['employee_id'],
        "employee_full_name" => $r['employee_full_name'],
        "employee_phone" => $phone,
        "employee_whatsapp" => $wa,
        "whatsapp_url" => "https://wa.me/" . preg_replace('/\D+/', '', $wa),
        "employee_job_code" => $r['employee_job_code'],
        "employee_job_title" => $r['employee_job_title'],
        "employee_national_id" => $r['employee_national_id'],
        "employee_hire_date" => $r['employee_hire_date'],
        "employee_employment_type" => $r['employee_employment_type'],
        "employee_base_salary" => (float) $r['employee_base_salary'],
        "employee_bank_account" => $r['employee_bank_account'],
        "employee_notes" => $r['employee_notes'],
        "employee_id_card_front" => $r['employee_id_card_front'],
        "employee_id_card_back" => $r['employee_id_card_back'],
        "employee_criminal_record" => $r['employee_criminal_record'],
        "employee_contract_pdf" => $r['employee_contract_pdf'],
        "employee_is_active" => (int) $r['employee_is_active'],
        "employee_created_at" => $r['employee_created_at'],
        "can_edit" => true,
        "can_pay_salary" => true,
        "transactions" => $transactions,
    ],
]);
