<?php
// ============================================================
// ملف: admin/owner/add_expense.php
// الغرض: إضافة مصروف شركة يدوي
// ============================================================

include "_owner_boot.php";

$title = trim(filterRequest("expense_title"));
$amount = ownerMoney(filterRequest("expense_amount"));
$date = ownerDateOrEmpty(filterRequest("expense_date"));
$category = trim(filterRequest("expense_category"));
$status = trim(filterRequest("expense_status"));
$notes = trim(filterRequest("expense_notes"));

if ($title === '' || $amount <= 0) {
    printFailure("العنوان والمبلغ مطلوبان");
    exit;
}
if ($date === '') {
    $date = date('Y-m-d');
}
$allowedCat = ['salary', 'rent', 'utilities', 'marketing', 'supplies', 'transport', 'maintenance', 'other'];
if (!in_array($category, $allowedCat, true)) {
    $category = 'other';
}
if (!in_array($status, ['pending', 'paid', 'cancelled'], true)) {
    $status = 'paid';
}

$insertData = [
    "expense_category" => $category,
    "expense_title" => $title,
    "expense_amount" => $amount,
    "expense_date" => $date,
    "expense_status" => $status,
    "expense_notes" => $notes !== '' ? $notes : null,
    "expense_created_by" => $ownerId,
];

$result = insertData("company_expenses", $insertData, false);
if ($result > 0) {
    echo json_encode([
        "status" => "success",
        "message" => "تم إضافة المصروف",
        "data" => ["expense_id" => (int) $con->lastInsertId()],
    ]);
    exit;
}

printFailure("تعذّر إضافة المصروف");
