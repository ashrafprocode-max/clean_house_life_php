<?php
// ============================================================
// ملف: admin/owner/list_assets.php
// الغرض: قائمة أصول الشركة
// ============================================================

include "_owner_boot.php";

$status = trim(filterRequest("status"));
$category = trim(filterRequest("category"));
$search = trim(filterRequest("search"));

$where = "WHERE 1=1";
$params = [];
if (in_array($status, ['active', 'sold', 'written_off'], true)) {
    $where .= " AND asset_status = ?";
    $params[] = $status;
}
$allowedCat = ['equipment', 'vehicle', 'furniture', 'electronics', 'property', 'other'];
if (in_array($category, $allowedCat, true)) {
    $where .= " AND asset_category = ?";
    $params[] = $category;
}
if ($search !== '') {
    $where .= " AND asset_name LIKE ?";
    $params[] = '%' . $search . '%';
}

$statsStmt = $con->prepare(
    "SELECT
        COUNT(asset_id) AS all_count,
        SUM(CASE WHEN asset_status = 'active' THEN 1 ELSE 0 END) AS active_count,
        COALESCE(SUM(CASE WHEN asset_status = 'active' THEN asset_value ELSE 0 END), 0) AS active_value
     FROM company_assets"
);
$statsStmt->execute();
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

$stmt = $con->prepare(
    "SELECT
        asset_id,
        asset_name,
        asset_category,
        asset_value,
        asset_purchase_date,
        asset_status,
        asset_notes,
        asset_created_at
     FROM company_assets
     $where
     ORDER BY asset_status ASC, asset_purchase_date DESC, asset_id DESC"
);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$catAr = [
    'equipment' => 'معدات',
    'vehicle' => 'مركبة',
    'furniture' => 'أثاث',
    'electronics' => 'إلكترونيات',
    'property' => 'عقار',
    'other' => 'أخرى',
];
$statusAr = [
    'active' => 'نشط',
    'sold' => 'مباع',
    'written_off' => 'مُشطَب',
];

$data = array_map(function ($r) use ($catAr, $statusAr) {
    return [
        "asset_id" => (int) $r['asset_id'],
        "asset_name" => $r['asset_name'],
        "asset_category" => $r['asset_category'],
        "asset_category_ar" => $catAr[$r['asset_category']] ?? $r['asset_category'],
        "asset_value" => (float) $r['asset_value'],
        "asset_purchase_date" => $r['asset_purchase_date'],
        "asset_status" => $r['asset_status'],
        "asset_status_ar" => $statusAr[$r['asset_status']] ?? $r['asset_status'],
        "asset_notes" => $r['asset_notes'],
        "asset_created_at" => $r['asset_created_at'],
        "can_edit" => true,
    ];
}, $rows);

echo json_encode([
    "status" => "success",
    "data" => $data,
    "stats" => [
        "all_count" => (int) ($stats['all_count'] ?? 0),
        "active_count" => (int) ($stats['active_count'] ?? 0),
        "active_value" => (float) ($stats['active_value'] ?? 0),
    ],
    "can_create" => true,
    "can_edit" => true,
]);
