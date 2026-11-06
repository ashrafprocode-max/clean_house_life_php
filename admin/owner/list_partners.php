<?php
// ============================================================
// ملف: admin/owner/list_partners.php
// الغرض: قائمة الشركاء وحصصهم
// الطريقة: POST — للمالك فقط
// ============================================================

include "_owner_boot.php";

$status = trim(filterRequest("status"));
$search = trim(filterRequest("search"));

$where = "WHERE 1=1";
$params = [];
if ($status === 'active') {
    $where .= " AND partner_is_active = 1";
} elseif ($status === 'inactive') {
    $where .= " AND partner_is_active = 0";
}
if ($search !== '') {
    $where .= " AND (partner_full_name LIKE ? OR partner_phone LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}

$stmt = $con->prepare(
    "SELECT
        partner_id,
        partner_full_name,
        partner_phone,
        partner_share_percent,
        partner_notes,
        partner_is_active,
        partner_created_at
     FROM partners
     $where
     ORDER BY partner_is_active DESC, partner_share_percent DESC"
);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sumStmt = $con->prepare(
    "SELECT COALESCE(SUM(partner_share_percent), 0)
     FROM partners WHERE partner_is_active = 1"
);
$sumStmt->execute();
$shareTotal = (float) $sumStmt->fetchColumn();

$data = array_map(function ($r) {
    return [
        "partner_id" => (int) $r['partner_id'],
        "partner_full_name" => $r['partner_full_name'],
        "partner_phone" => $r['partner_phone'],
        "partner_share_percent" => (float) $r['partner_share_percent'],
        "partner_notes" => $r['partner_notes'],
        "partner_is_active" => (int) $r['partner_is_active'],
        "partner_created_at" => $r['partner_created_at'],
        "can_edit" => true,
    ];
}, $rows);

echo json_encode([
    "status" => "success",
    "data" => $data,
    "stats" => [
        "all_count" => count($data),
        "share_total" => $shareTotal,
    ],
    "can_create" => true,
    "can_edit" => true,
]);
