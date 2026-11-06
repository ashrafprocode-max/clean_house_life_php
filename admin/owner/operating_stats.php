<?php
// ============================================================
// ملف: admin/owner/operating_stats.php
// الغرض: إحصاءات تشغيل + أرباح وخسائر مجمّعة للمالك
// ============================================================

include "_owner_boot.php";

$dateFrom = ownerDateOrEmpty(filterRequest("date_from"));
$dateTo = ownerDateOrEmpty(filterRequest("date_to"));
if ($dateFrom === '') {
    $dateFrom = date('Y-m-01');
}
if ($dateTo === '') {
    $dateTo = date('Y-m-d');
}

$usersStmt = $con->prepare(
    "SELECT
        SUM(CASE WHEN users_type = 'client' THEN 1 ELSE 0 END) AS clients_count,
        SUM(CASE WHEN users_type = 'provider' THEN 1 ELSE 0 END) AS providers_count
     FROM users"
);
$usersStmt->execute();
$users = $usersStmt->fetch(PDO::FETCH_ASSOC);

$provStmt = $con->prepare(
    "SELECT
        SUM(CASE WHEN providers_is_verified = 0 THEN 1 ELSE 0 END) AS pending_providers,
        SUM(CASE WHEN providers_is_verified = 1 THEN 1 ELSE 0 END) AS verified_providers,
        SUM(CASE WHEN providers_is_verified = 2 THEN 1 ELSE 0 END) AS rejected_providers
     FROM professional_profiles"
);
$provStmt->execute();
$prov = $provStmt->fetch(PDO::FETCH_ASSOC);

$compStmt = $con->query("SELECT COUNT(*) FROM company_registering");
$companiesCount = $compStmt ? (int) $compStmt->fetchColumn() : 0;

$jobsStmt = $con->prepare(
    "SELECT
        SUM(CASE WHEN required_approval_status = 'pending' THEN 1 ELSE 0 END) AS pending_jobs,
        SUM(CASE WHEN required_approval_status = 'approved' THEN 1 ELSE 0 END) AS approved_jobs,
        SUM(CASE WHEN required_approval_status = 'rejected' THEN 1 ELSE 0 END) AS rejected_jobs
     FROM job_details_required"
);
$jobsStmt->execute();
$jobs = $jobsStmt->fetch(PDO::FETCH_ASSOC);

$bookStmt = $con->prepare(
    "SELECT
        COUNT(booking_id) AS bookings_count,
        SUM(CASE WHEN booking_status = 'completed' THEN 1 ELSE 0 END) AS completed_bookings,
        SUM(CASE WHEN booking_status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_bookings,
        COALESCE(SUM(
            CASE
              WHEN booking_status IN ('completed','approved','in_progress')
               AND booking_ps_commission_type = 'percent'
                THEN booking_ps_price * booking_ps_commission_value / 100
              WHEN booking_status IN ('completed','approved','in_progress')
                THEN booking_ps_commission_value
              ELSE 0
            END
        ), 0) AS booking_commission
     FROM bookings
     WHERE DATE(booking_created_at) BETWEEN ? AND ?"
);
$bookStmt->execute([$dateFrom, $dateTo]);
$book = $bookStmt->fetch(PDO::FETCH_ASSOC);

$expStmt = $con->prepare(
    "SELECT COALESCE(SUM(expense_amount), 0)
     FROM company_expenses
     WHERE expense_status = 'paid' AND expense_date BETWEEN ? AND ?"
);
$expStmt->execute([$dateFrom, $dateTo]);
$expenses = (float) $expStmt->fetchColumn();

$revStmt = $con->prepare(
    "SELECT COALESCE(SUM(revenue_amount), 0)
     FROM company_revenues
     WHERE revenue_status = 'received' AND revenue_date BETWEEN ? AND ?"
);
$revStmt->execute([$dateFrom, $dateTo]);
$manualRev = (float) $revStmt->fetchColumn();

$empStmt = $con->query(
    "SELECT
        COUNT(employee_id) AS all_employees,
        SUM(CASE WHEN employee_is_active = 1 THEN 1 ELSE 0 END) AS active_employees,
        COALESCE(SUM(CASE WHEN employee_is_active = 1 THEN employee_base_salary ELSE 0 END), 0) AS monthly_payroll
     FROM employees"
);
$emp = $empStmt ? $empStmt->fetch(PDO::FETCH_ASSOC) : [];

$assetStmt = $con->query(
    "SELECT COALESCE(SUM(asset_value), 0)
     FROM company_assets WHERE asset_status = 'active'"
);
$assetsValue = $assetStmt ? (float) $assetStmt->fetchColumn() : 0;

$partnersStmt = $con->query(
    "SELECT COUNT(*) FROM partners WHERE partner_is_active = 1"
);
$partnersCount = $partnersStmt ? (int) $partnersStmt->fetchColumn() : 0;

$bookingCommission = (float) ($book['booking_commission'] ?? 0);
$revenues = $manualRev + $bookingCommission;
$net = $revenues - $expenses;

echo json_encode([
    "status" => "success",
    "data" => [
        "date_from" => $dateFrom,
        "date_to" => $dateTo,
        "clients_count" => (int) ($users['clients_count'] ?? 0),
        "providers_count" => (int) ($users['providers_count'] ?? 0),
        "pending_providers" => (int) ($prov['pending_providers'] ?? 0),
        "verified_providers" => (int) ($prov['verified_providers'] ?? 0),
        "rejected_providers" => (int) ($prov['rejected_providers'] ?? 0),
        "companies_count" => $companiesCount,
        "pending_jobs" => (int) ($jobs['pending_jobs'] ?? 0),
        "approved_jobs" => (int) ($jobs['approved_jobs'] ?? 0),
        "rejected_jobs" => (int) ($jobs['rejected_jobs'] ?? 0),
        "bookings_count" => (int) ($book['bookings_count'] ?? 0),
        "completed_bookings" => (int) ($book['completed_bookings'] ?? 0),
        "cancelled_bookings" => (int) ($book['cancelled_bookings'] ?? 0),
        "booking_commission" => $bookingCommission,
        "manual_revenues" => $manualRev,
        "revenues_total" => $revenues,
        "expenses_total" => $expenses,
        "net" => $net,
        "all_employees" => (int) ($emp['all_employees'] ?? 0),
        "active_employees" => (int) ($emp['active_employees'] ?? 0),
        "monthly_payroll" => (float) ($emp['monthly_payroll'] ?? 0),
        "assets_value" => $assetsValue,
        "partners_count" => $partnersCount,
    ],
]);
