<?php
// ============================================================
// ملف: admin/owner/pay_salary.php
// الغرض: صرف راتب أو سلفة أو خصم أو مكافأة لموظف تشغيلي
//        وترحيل الراتب كمصروف شركة عند النوع salary
// الطريقة: POST — للمالك فقط
// ============================================================

include "_owner_boot.php";

$employeeId = (int) filterRequest("employee_id");
$type = trim(filterRequest("eft_type"));
$amountRaw = trim(filterRequest("eft_amount"));
$month = (int) filterRequest("eft_month");
$year = (int) filterRequest("eft_year");
$date = ownerDateOrEmpty(filterRequest("eft_date"));
$notes = trim(filterRequest("eft_notes"));

if ($employeeId <= 0) {
    printFailure("معرّف الموظف مطلوب");
    exit;
}

$allowedTypes = ['salary', 'advance', 'deduction', 'bonus', 'allowance'];
if ($type === '' || !in_array($type, $allowedTypes, true)) {
    $type = 'salary';
}

$empStmt = $con->prepare(
    "SELECT employee_id, employee_full_name, employee_base_salary
     FROM employees WHERE employee_id = ? LIMIT 1"
);
$empStmt->execute([$employeeId]);
$emp = $empStmt->fetch(PDO::FETCH_ASSOC);
if (!$emp) {
    printFailure("الموظف غير موجود", 404);
    exit;
}

if ($date === '') {
    $date = date('Y-m-d');
}
if ($month < 1 || $month > 12) {
    $month = (int) date('n', strtotime($date));
}
if ($year < 2000 || $year > 2100) {
    $year = (int) date('Y', strtotime($date));
}

$amount = $amountRaw !== '' ? ownerMoney($amountRaw) : (float) $emp['employee_base_salary'];
if ($type === 'salary' && $amount <= 0) {
    $amount = (float) $emp['employee_base_salary'];
}
if ($amount <= 0) {
    printFailure("المبلغ يجب أن يكون أكبر من صفر");
    exit;
}

$expenseId = null;
if ($type === 'salary' || $type === 'bonus' || $type === 'allowance') {
    $title = ($type === 'salary' ? 'راتب ' : ($type === 'bonus' ? 'مكافأة ' : 'بدل '))
        . $emp['employee_full_name'];
    $expData = [
        "expense_category" => "salary",
        "expense_title" => $title,
        "expense_amount" => $amount,
        "expense_date" => $date,
        "expense_status" => "paid",
        "expense_notes" => $notes !== '' ? $notes : null,
        "expense_created_by" => $ownerId,
    ];
    $expResult = insertData("company_expenses", $expData, false);
    if ($expResult > 0) {
        $expenseId = (int) $con->lastInsertId();
    }
}

$insertData = [
    "eft_employee_id" => $employeeId,
    "eft_type" => $type,
    "eft_amount" => $amount,
    "eft_month" => $month,
    "eft_year" => $year,
    "eft_date" => $date,
    "eft_expense_id" => $expenseId,
    "eft_notes" => $notes !== '' ? $notes : null,
    "eft_created_by" => $ownerId,
];

$result = insertData("employee_financial_transactions", $insertData, false);
if ($result > 0) {
    echo json_encode([
        "status" => "success",
        "message" => "تم تسجيل المعاملة المالية",
        "data" => [
            "eft_id" => (int) $con->lastInsertId(),
            "eft_expense_id" => $expenseId,
        ],
    ]);
    exit;
}

printFailure("تعذّر تسجيل المعاملة");
