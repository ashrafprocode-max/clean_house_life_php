<?php
// ============================================================
// ملف: admin/owner/list_revenues.php
// الغرض: قائمة الإيرادات اليدوية مع فلترة وصفحات
// ============================================================

include "_owner_boot.php";

$status = trim(filterRequest("status"));
$category = trim(filterRequest("category"));
$dateFrom = ownerDateOrEmpty(filterRequest("date_from"));
$dateTo = ownerDateOrEmpty(filterRequest("date_to"));
$search = trim(filterRequest("search"));
$page = (int) filterRequest("page");
$limit = (int) filterRequest("limit");
if ($page < 1) {
    $page = 1;
}
if ($limit < 1 || $limit > 100) {
    $limit = 20;
}
$offset = ($page - 1) * $limit;

$where = "WHERE 1=1";
$params = [];
if (in_array($status, ['pending', 'received', 'cancelled'], true)) {
    $where .= " AND revenue_status = ?";
    $params[] = $status;
}
$allowedCat = ['commission', 'booking', 'partnership', 'other'];
if (in_array($category, $allowedCat, true)) {
    $where .= " AND revenue_category = ?";
    $params[] = $category;
}
if ($dateFrom !== '') {
    $where .= " AND revenue_date >= ?";
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where .= " AND revenue_date <= ?";
    $params[] = $dateTo;
}
if ($search !== '') {
    $where .= " AND revenue_title LIKE ?";
    $params[] = '%' . $search . '%';
}

$statsStmt = $con->prepare(
    "SELECT
        COUNT(revenue_id) AS all_count,
        SUM(CASE WHEN revenue_status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN revenue_status = 'received' THEN 1 ELSE 0 END) AS received_count,
        SUM(CASE WHEN revenue_status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
        COALESCE(SUM(CASE WHEN revenue_status = 'received' THEN revenue_amount ELSE 0 END), 0) AS received_total
     FROM company_revenues"
);
$statsStmt->execute();
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

$countStmt = $con->prepare("SELECT COUNT(revenue_id) FROM company_revenues $where");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$sumStmt = $con->prepare(
    "SELECT COALESCE(SUM(CASE WHEN revenue_status = 'received' THEN revenue_amount ELSE 0 END), 0)
     FROM company_revenues $where"
);
$sumStmt->execute($params);
$filteredTotal = (float) $sumStmt->fetchColumn();

$sql = "SELECT
            revenue_id,
            revenue_category,
            revenue_title,
            revenue_amount,
            revenue_date,
            revenue_status,
            revenue_booking_id,
            revenue_notes,
            revenue_created_at
        FROM company_revenues
        $where
        ORDER BY revenue_date DESC, revenue_id DESC
        LIMIT $limit OFFSET $offset";
$stmt = $con->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$catAr = [
    'commission' => 'عمولة',
    'booking' => 'حجز',
    'partnership' => 'شراكة',
    'other' => 'أخرى',
];
$statusAr = [
    'pending' => 'معلّق',
    'received' => 'مقبوض',
    'cancelled' => 'ملغى',
];

$data = array_map(function ($r) use ($catAr, $statusAr) {
    return [
        "revenue_id" => (int) $r['revenue_id'],
        "revenue_category" => $r['revenue_category'],
        "revenue_category_ar" => $catAr[$r['revenue_category']] ?? $r['revenue_category'],
        "revenue_title" => $r['revenue_title'],
        "revenue_amount" => (float) $r['revenue_amount'],
        "revenue_date" => $r['revenue_date'],
        "revenue_status" => $r['revenue_status'],
        "revenue_status_ar" => $statusAr[$r['revenue_status']] ?? $r['revenue_status'],
        "revenue_booking_id" => $r['revenue_booking_id'] !== null ? (int) $r['revenue_booking_id'] : null,
        "revenue_notes" => $r['revenue_notes'],
        "revenue_created_at" => $r['revenue_created_at'],
        "can_edit" => true,
    ];
}, $rows);

echo json_encode([
    "status" => "success",
    "data" => $data,
    "stats" => [
        "all_count" => (int) ($stats['all_count'] ?? 0),
        "pending_count" => (int) ($stats['pending_count'] ?? 0),
        "received_count" => (int) ($stats['received_count'] ?? 0),
        "cancelled_count" => (int) ($stats['cancelled_count'] ?? 0),
        "received_total" => (float) ($stats['received_total'] ?? 0),
    ],
    "filtered_total" => $filteredTotal,
    "pagination" => [
        "current_page" => $page,
        "limit" => $limit,
        "total" => $total,
        "has_next_page" => ($offset + count($data)) < $total,
    ],
    "can_create" => true,
    "can_edit" => true,
]);
