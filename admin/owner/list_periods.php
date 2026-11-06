<?php
// ============================================================
// ملف: admin/owner/list_periods.php
// الغرض: قائمة الفترات المالية مع ملخص إيراد ومصروف لكل فترة
// ============================================================

include "_owner_boot.php";

$status = trim(filterRequest("status"));

$where = "WHERE 1=1";
$params = [];
if ($status === 'open' || $status === 'closed') {
    $where .= " AND period_status = ?";
    $params[] = $status;
}

$stmt = $con->prepare(
    "SELECT
        period_id,
        period_year,
        period_month,
        period_status,
        period_notes,
        period_closed_at,
        period_created_at
     FROM company_financial_periods
     $where
     ORDER BY period_year DESC, period_month DESC"
);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$data = [];
foreach ($rows as $r) {
    $year = (int) $r['period_year'];
    $month = (int) $r['period_month'];
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = date('Y-m-t', strtotime($start));

    $expStmt = $con->prepare(
        "SELECT COALESCE(SUM(expense_amount), 0)
         FROM company_expenses
         WHERE expense_status = 'paid'
           AND expense_date BETWEEN ? AND ?"
    );
    $expStmt->execute([$start, $end]);
    $expenses = (float) $expStmt->fetchColumn();

    $revStmt = $con->prepare(
        "SELECT COALESCE(SUM(revenue_amount), 0)
         FROM company_revenues
         WHERE revenue_status = 'received'
           AND revenue_date BETWEEN ? AND ?"
    );
    $revStmt->execute([$start, $end]);
    $manualRev = (float) $revStmt->fetchColumn();

    $bookStmt = $con->prepare(
        "SELECT COALESCE(SUM(
            CASE
              WHEN booking_ps_commission_type = 'percent'
                THEN booking_ps_price * booking_ps_commission_value / 100
              ELSE booking_ps_commission_value
            END
         ), 0)
         FROM bookings
         WHERE booking_status IN ('completed','approved','in_progress')
           AND DATE(booking_created_at) BETWEEN ? AND ?"
    );
    $bookStmt->execute([$start, $end]);
    $bookingComm = (float) $bookStmt->fetchColumn();

    $revenues = $manualRev + $bookingComm;
    $net = $revenues - $expenses;

    $data[] = [
        "period_id" => (int) $r['period_id'],
        "period_year" => $year,
        "period_month" => $month,
        "period_status" => $r['period_status'],
        "period_status_ar" => $r['period_status'] === 'closed' ? 'مغلقة' : 'مفتوحة',
        "period_notes" => $r['period_notes'],
        "period_closed_at" => $r['period_closed_at'],
        "period_created_at" => $r['period_created_at'],
        "expenses_total" => $expenses,
        "revenues_total" => $revenues,
        "net" => $net,
        "can_edit" => $r['period_status'] === 'open',
    ];
}

echo json_encode([
    "status" => "success",
    "data" => $data,
    "can_create" => true,
    "can_edit" => true,
]);
