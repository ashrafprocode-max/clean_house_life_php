<?php
// ============================================================
// ملف: admin/owner/edit_expense.php
// الغرض: تعديل مصروف شركة
// ============================================================

include "_owner_boot.php";

$expenseId = (int) filterRequest("expense_id");
if ($expenseId <= 0) {
    printFailure("معرّف المصروف مطلوب");
    exit;
}

$stmt = $con->prepare("SELECT expense_id FROM company_expenses WHERE expense_id = ? LIMIT 1");
$stmt->execute([$expenseId]);
if (!$stmt->fetch()) {
    printFailure("المصروف غير موجود", 404);
    exit;
}

$title = trim(filterRequest("expense_title"));
$amountRaw = trim(filterRequest("expense_amount"));
$date = ownerDateOrEmpty(filterRequest("expense_date"));
$category = trim(filterRequest("expense_category"));
$status = trim(filterRequest("expense_status"));
$notes = trim(filterRequest("expense_notes"));

$update = [];
if ($title !== '') {
    $update["expense_title"] = $title;
}
if ($amountRaw !== '') {
    $amount = ownerMoney($amountRaw);
    if ($amount <= 0) {
        printFailure("المبلغ يجب أن يكون أكبر من صفر");
        exit;
    }
    $update["expense_amount"] = $amount;
}
if ($date !== '') {
    $update["expense_date"] = $date;
}
$allowedCat = ['salary', 'rent', 'utilities', 'marketing', 'supplies', 'transport', 'maintenance', 'other'];
if (in_array($category, $allowedCat, true)) {
    $update["expense_category"] = $category;
}
if (in_array($status, ['pending', 'paid', 'cancelled'], true)) {
    $update["expense_status"] = $status;
}
if (isset($_POST['expense_notes']) || $notes !== '') {
    $update["expense_notes"] = $notes !== '' ? $notes : null;
}

if (empty($update)) {
    printFailure("لا توجد بيانات للتعديل");
    exit;
}

$result = updateData("company_expenses", $update, "expense_id = $expenseId", false);
if ($result >= 0) {
    echo json_encode([
        "status" => "success",
        "message" => "تم تعديل المصروف",
    ]);
    exit;
}

printFailure("تعذّر تعديل المصروف");
