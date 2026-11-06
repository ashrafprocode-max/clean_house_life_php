<?php
// ============================================================
// ملف: admin/owner/add_revenue.php
// الغرض: إضافة إيراد يدوي
// ============================================================

include "_owner_boot.php";

$title = trim(filterRequest("revenue_title"));
$amount = ownerMoney(filterRequest("revenue_amount"));
$date = ownerDateOrEmpty(filterRequest("revenue_date"));
$category = trim(filterRequest("revenue_category"));
$status = trim(filterRequest("revenue_status"));
$notes = trim(filterRequest("revenue_notes"));

if ($title === '' || $amount <= 0) {
    printFailure("العنوان والمبلغ مطلوبان");
    exit;
}
if ($date === '') {
    $date = date('Y-m-d');
}
$allowedCat = ['commission', 'booking', 'partnership', 'other'];
if (!in_array($category, $allowedCat, true)) {
    $category = 'other';
}
if (!in_array($status, ['pending', 'received', 'cancelled'], true)) {
    $status = 'received';
}

$insertData = [
    "revenue_category" => $category,
    "revenue_title" => $title,
    "revenue_amount" => $amount,
    "revenue_date" => $date,
    "revenue_status" => $status,
    "revenue_notes" => $notes !== '' ? $notes : null,
    "revenue_created_by" => $ownerId,
];

$result = insertData("company_revenues", $insertData, false);
if ($result > 0) {
    echo json_encode([
        "status" => "success",
        "message" => "تم إضافة الإيراد",
        "data" => ["revenue_id" => (int) $con->lastInsertId()],
    ]);
    exit;
}

printFailure("تعذّر إضافة الإيراد");
