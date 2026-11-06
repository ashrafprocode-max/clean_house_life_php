<?php
// ============================================================
// ملف: admin/owner/list_expenses.php
// الغرض: قائمة مصروفات الشركة مع فلترة وصفحات
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
if (in_array($status, ['pending', 'paid', 'cancelled'], true)) {
    $where .= " AND expense_status = ?";
    $params[] = $status;
}
$allowedCat = ['salary', 'rent', 'utilities', 'marketing', 'supplies', 'transport', 'maintenance', 'other'];
if (in_array($category, $allowedCat, true)) {
    $where .= " AND expense_category = ?";
    $params[] = $category;
}
if ($dateFrom !== '') {
    $where .= " AND expense_date >= ?";
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where .= " AND expense_date <= ?";
    $params[] = $dateTo;
}
if ($search !== '') {
    $where .= " AND expense_title LIKE ?";
    $params[] = '%' . $search . '%';
}

$statsStmt = $con->prepare(
    "SELECT
        COUNT(expense_id) AS all_count,
        SUM(CASE WHEN expense_status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN expense_status = 'paid' THEN 1 ELSE 0 END) AS paid_count,
        SUM(CASE WHEN expense_status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
        COALESCE(SUM(CASE WHEN expense_status = 'paid' THEN expense_amount ELSE 0 END), 0) AS paid_total
     FROM company_expenses"
);
$statsStmt->execute();
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

$countStmt = $con->prepare("SELECT COUNT(expense_id) FROM company_expenses $where");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$sumStmt = $con->prepare(
    "SELECT COALESCE(SUM(expense_amount), 0) FROM company_expenses $where AND expense_status = 'paid'"
);
$sumParams = $params;
if (strpos($where, 'expense_status') === false) {
    $sumStmt = $con->prepare(
        "SELECT COALESCE(SUM(CASE WHEN expense_status = 'paid' THEN expense_amount ELSE 0 END), 0)
         FROM company_expenses $where"
    );
}
$sumStmt->execute($params);
$filteredTotal = (float) $sumStmt->fetchColumn();

$sql = "SELECT
            expense_id,
            expense_category,
            expense_title,
            expense_amount,
            expense_date,
            expense_status,
            expense_salary_id,
            expense_notes,
            expense_created_at
        FROM company_expenses
        $where
        ORDER BY expense_date DESC, expense_id DESC
        LIMIT $limit OFFSET $offset";
$stmt = $con->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$catAr = [
    'salary' => 'رواتب',
    'rent' => 'إيجار',
    'utilities' => 'مرافق',
    'marketing' => 'تسويق',
    'supplies' => 'مستلزمات',
    'transport' => 'انتقالات',
    'maintenance' => 'صيانة',
    'other' => 'أخرى',
];
$statusAr = [
    'pending' => 'معلّق',
    'paid' => 'مدفوع',
    'cancelled' => 'ملغى',
];

$data = array_map(function ($r) use ($catAr, $statusAr) {
    return [
        "expense_id" => (int) $r['expense_id'],
        "expense_category" => $r['expense_category'],
        "expense_category_ar" => $catAr[$r['expense_category']] ?? $r['expense_category'],
        "expense_title" => $r['expense_title'],
        "expense_amount" => (float) $r['expense_amount'],
        "expense_date" => $r['expense_date'],
        "expense_status" => $r['expense_status'],
        "expense_status_ar" => $statusAr[$r['expense_status']] ?? $r['expense_status'],
        "expense_salary_id" => $r['expense_salary_id'] !== null ? (int) $r['expense_salary_id'] : null,
        "from_salary" => $r['expense_salary_id'] !== null,
        "expense_notes" => $r['expense_notes'],
        "expense_created_at" => $r['expense_created_at'],
        "can_edit" => true,
    ];
}, $rows);

echo json_encode([
    "status" => "success",
    "data" => $data,
    "stats" => [
        "all_count" => (int) ($stats['all_count'] ?? 0),
        "pending_count" => (int) ($stats['pending_count'] ?? 0),
        "paid_count" => (int) ($stats['paid_count'] ?? 0),
        "cancelled_count" => (int) ($stats['cancelled_count'] ?? 0),
        "paid_total" => (float) ($stats['paid_total'] ?? 0),
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
