<?php
// ============================================================
// ملف: admin/owner/edit_asset.php
// الغرض: تعديل أصل أو تغيير حالته
// ============================================================

include "_owner_boot.php";

$assetId = (int) filterRequest("asset_id");
if ($assetId <= 0) {
    printFailure("معرّف الأصل مطلوب");
    exit;
}

$stmt = $con->prepare("SELECT asset_id FROM company_assets WHERE asset_id = ? LIMIT 1");
$stmt->execute([$assetId]);
if (!$stmt->fetch()) {
    printFailure("الأصل غير موجود", 404);
    exit;
}

$name = trim(filterRequest("asset_name"));
$category = trim(filterRequest("asset_category"));
$valueRaw = trim(filterRequest("asset_value"));
$date = ownerDateOrEmpty(filterRequest("asset_purchase_date"));
$status = trim(filterRequest("asset_status"));
$notes = trim(filterRequest("asset_notes"));

$update = [];
if ($name !== '') {
    $update["asset_name"] = $name;
}
$allowedCat = ['equipment', 'vehicle', 'furniture', 'electronics', 'property', 'other'];
if (in_array($category, $allowedCat, true)) {
    $update["asset_category"] = $category;
}
if ($valueRaw !== '') {
    $update["asset_value"] = ownerMoney($valueRaw);
}
if ($date !== '') {
    $update["asset_purchase_date"] = $date;
}
if (in_array($status, ['active', 'sold', 'written_off'], true)) {
    $update["asset_status"] = $status;
}
if (isset($_POST['asset_notes']) || $notes !== '') {
    $update["asset_notes"] = $notes !== '' ? $notes : null;
}

if (empty($update)) {
    printFailure("لا توجد بيانات للتعديل");
    exit;
}

$result = updateData("company_assets", $update, "asset_id = $assetId", false);
if ($result >= 0) {
    echo json_encode([
        "status" => "success",
        "message" => "تم تعديل الأصل",
    ]);
    exit;
}

printFailure("تعذّر تعديل الأصل");
