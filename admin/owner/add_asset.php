<?php
// ============================================================
// ملف: admin/owner/add_asset.php
// الغرض: إضافة أصل للشركة
// ============================================================

include "_owner_boot.php";

$name = trim(filterRequest("asset_name"));
$category = trim(filterRequest("asset_category"));
$value = ownerMoney(filterRequest("asset_value"));
$date = ownerDateOrEmpty(filterRequest("asset_purchase_date"));
$notes = trim(filterRequest("asset_notes"));

if ($name === '') {
    printFailure("اسم الأصل مطلوب");
    exit;
}
$allowedCat = ['equipment', 'vehicle', 'furniture', 'electronics', 'property', 'other'];
if (!in_array($category, $allowedCat, true)) {
    $category = 'other';
}

$insertData = [
    "asset_name" => $name,
    "asset_category" => $category,
    "asset_value" => $value,
    "asset_purchase_date" => $date !== '' ? $date : null,
    "asset_status" => "active",
    "asset_notes" => $notes !== '' ? $notes : null,
    "asset_created_by" => $ownerId,
];

$result = insertData("company_assets", $insertData, false);
if ($result > 0) {
    echo json_encode([
        "status" => "success",
        "message" => "تم إضافة الأصل",
        "data" => ["asset_id" => (int) $con->lastInsertId()],
    ]);
    exit;
}

printFailure("تعذّر إضافة الأصل");
