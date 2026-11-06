<?php
// ============================================================
// ملف: admin/owner/edit_revenue.php
// الغرض: تعديل إيراد يدوي
// ============================================================

include "_owner_boot.php";

$revenueId = (int) filterRequest("revenue_id");
if ($revenueId <= 0) {
    printFailure("معرّف الإيراد مطلوب");
    exit;
}

$stmt = $con->prepare("SELECT revenue_id FROM company_revenues WHERE revenue_id = ? LIMIT 1");
$stmt->execute([$revenueId]);
if (!$stmt->fetch()) {
    printFailure("الإيراد غير موجود", 404);
    exit;
}

$title = trim(filterRequest("revenue_title"));
$amountRaw = trim(filterRequest("revenue_amount"));
$date = ownerDateOrEmpty(filterRequest("revenue_date"));
$category = trim(filterRequest("revenue_category"));
$status = trim(filterRequest("revenue_status"));
$notes = trim(filterRequest("revenue_notes"));

$update = [];
if ($title !== '') {
    $update["revenue_title"] = $title;
}
if ($amountRaw !== '') {
    $amount = ownerMoney($amountRaw);
    if ($amount <= 0) {
        printFailure("المبلغ يجب أن يكون أكبر من صفر");
        exit;
    }
    $update["revenue_amount"] = $amount;
}
if ($date !== '') {
    $update["revenue_date"] = $date;
}
$allowedCat = ['commission', 'booking', 'partnership', 'other'];
if (in_array($category, $allowedCat, true)) {
    $update["revenue_category"] = $category;
}
if (in_array($status, ['pending', 'received', 'cancelled'], true)) {
    $update["revenue_status"] = $status;
}
if (isset($_POST['revenue_notes']) || $notes !== '') {
    $update["revenue_notes"] = $notes !== '' ? $notes : null;
}

if (empty($update)) {
    printFailure("لا توجد بيانات للتعديل");
    exit;
}

$result = updateData("company_revenues", $update, "revenue_id = $revenueId", false);
if ($result >= 0) {
    echo json_encode([
        "status" => "success",
        "message" => "تم تعديل الإيراد",
    ]);
    exit;
}

printFailure("تعذّر تعديل الإيراد");
