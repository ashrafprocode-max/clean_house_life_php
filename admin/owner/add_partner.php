<?php
// ============================================================
// ملف: admin/owner/add_partner.php
// الغرض: إضافة شريك جديد
// الحقول الإجبارية: partner_full_name, partner_share_percent
// ============================================================

include "_owner_boot.php";

$name = trim(filterRequest("partner_full_name"));
$phone = trim(filterRequest("partner_phone"));
$share = (float) str_replace(',', '', filterRequest("partner_share_percent"));
$notes = trim(filterRequest("partner_notes"));

if ($name === '') {
    printFailure("اسم الشريك مطلوب");
    exit;
}
if (mb_strlen($name) > 100) {
    printFailure("اسم الشريك يجب ألا يتجاوز 100 حرف");
    exit;
}
if ($share < 0 || $share > 100) {
    printFailure("نسبة الحصة يجب أن تكون بين 0 و 100");
    exit;
}

$sumStmt = $con->prepare(
    "SELECT COALESCE(SUM(partner_share_percent), 0)
     FROM partners WHERE partner_is_active = 1"
);
$sumStmt->execute();
$current = (float) $sumStmt->fetchColumn();
if (($current + $share) > 100.01) {
    printFailure("مجموع الحصص النشطة سيتجاوز 100%");
    exit;
}

$insertData = [
    "partner_full_name" => $name,
    "partner_phone" => $phone !== '' ? $phone : null,
    "partner_share_percent" => $share,
    "partner_notes" => $notes !== '' ? $notes : null,
    "partner_is_active" => 1,
    "partner_created_by" => $ownerId,
];

$result = insertData("partners", $insertData, false);
if ($result > 0) {
    echo json_encode([
        "status" => "success",
        "message" => "تم إضافة الشريك",
        "data" => [
            "partner_id" => (int) $con->lastInsertId(),
            "partner_full_name" => $name,
        ],
    ]);
    exit;
}

printFailure("تعذّر إضافة الشريك");
