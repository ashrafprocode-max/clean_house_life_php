<?php
// ============================================================
// ملف: admin/owner/edit_partner.php
// الغرض: تعديل شريك أو تعطيله
// ============================================================

include "_owner_boot.php";

$partnerId = (int) filterRequest("partner_id");
if ($partnerId <= 0) {
    printFailure("معرّف الشريك مطلوب");
    exit;
}

$stmt = $con->prepare("SELECT * FROM partners WHERE partner_id = ? LIMIT 1");
$stmt->execute([$partnerId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    printFailure("الشريك غير موجود", 404);
    exit;
}

$name = trim(filterRequest("partner_full_name"));
$phone = trim(filterRequest("partner_phone"));
$shareRaw = trim(filterRequest("partner_share_percent"));
$notes = trim(filterRequest("partner_notes"));
$isActiveRaw = trim(filterRequest("partner_is_active"));

$update = [];
if ($name !== '') {
    if (mb_strlen($name) > 100) {
        printFailure("اسم الشريك يجب ألا يتجاوز 100 حرف");
        exit;
    }
    $update["partner_full_name"] = $name;
}
if (isset($_POST['partner_phone']) || $phone !== '') {
    $update["partner_phone"] = $phone !== '' ? $phone : null;
}
if ($shareRaw !== '') {
    $share = (float) str_replace(',', '', $shareRaw);
    if ($share < 0 || $share > 100) {
        printFailure("نسبة الحصة يجب أن تكون بين 0 و 100");
        exit;
    }
    $sumStmt = $con->prepare(
        "SELECT COALESCE(SUM(partner_share_percent), 0)
         FROM partners WHERE partner_is_active = 1 AND partner_id <> ?"
    );
    $sumStmt->execute([$partnerId]);
    $current = (float) $sumStmt->fetchColumn();
    $willBeActive = $isActiveRaw === ''
        ? (int) $row['partner_is_active']
        : ((int) $isActiveRaw === 1 ? 1 : 0);
    if ($willBeActive === 1 && ($current + $share) > 100.01) {
        printFailure("مجموع الحصص النشطة سيتجاوز 100%");
        exit;
    }
    $update["partner_share_percent"] = $share;
}
if (isset($_POST['partner_notes']) || $notes !== '') {
    $update["partner_notes"] = $notes !== '' ? $notes : null;
}
if ($isActiveRaw !== '') {
    $update["partner_is_active"] = ((int) $isActiveRaw === 1) ? 1 : 0;
}

if (empty($update)) {
    printFailure("لا توجد بيانات للتعديل");
    exit;
}

$result = updateData("partners", $update, "partner_id = $partnerId", false);
if ($result >= 0) {
    echo json_encode([
        "status" => "success",
        "message" => "تم تعديل بيانات الشريك",
    ]);
    exit;
}

printFailure("تعذّر تعديل الشريك");
