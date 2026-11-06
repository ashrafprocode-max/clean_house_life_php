<?php
// ============================================================
// ملف: admin/owner/upsert_period.php
// الغرض: فتح فترة مالية شهرية أو إغلاقها
// ============================================================

include "_owner_boot.php";

$year = (int) filterRequest("period_year");
$month = (int) filterRequest("period_month");
$action = trim(filterRequest("period_action"));
$notes = trim(filterRequest("period_notes"));

if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
    printFailure("سنة وشهر الفترة غير صالحين");
    exit;
}

if ($action !== 'close' && $action !== 'open') {
    $action = 'open';
}

$find = $con->prepare(
    "SELECT period_id, period_status
     FROM company_financial_periods
     WHERE period_year = ? AND period_month = ?
     LIMIT 1"
);
$find->execute([$year, $month]);
$row = $find->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    $insertData = [
        "period_year" => $year,
        "period_month" => $month,
        "period_status" => $action === 'close' ? 'closed' : 'open',
        "period_notes" => $notes !== '' ? $notes : null,
        "period_created_by" => $ownerId,
        "period_closed_by" => $action === 'close' ? $ownerId : null,
        "period_closed_at" => $action === 'close' ? date('Y-m-d H:i:s') : null,
    ];
    $result = insertData("company_financial_periods", $insertData, false);
    if ($result > 0) {
        echo json_encode([
            "status" => "success",
            "message" => $action === 'close' ? 'تم فتح الفترة ثم إغلاقها' : 'تم فتح الفترة المالية',
            "data" => ["period_id" => (int) $con->lastInsertId()],
        ]);
        exit;
    }
    printFailure("تعذّر إنشاء الفترة");
    exit;
}

$periodId = (int) $row['period_id'];
$update = [
    "period_status" => $action === 'close' ? 'closed' : 'open',
];
if ($notes !== '') {
    $update["period_notes"] = $notes;
}
if ($action === 'close') {
    $update["period_closed_by"] = $ownerId;
    $update["period_closed_at"] = date('Y-m-d H:i:s');
} else {
    $update["period_closed_by"] = null;
    $update["period_closed_at"] = null;
}

$result = updateData("company_financial_periods", $update, "period_id = $periodId", false);
if ($result >= 0) {
    echo json_encode([
        "status" => "success",
        "message" => $action === 'close' ? 'تم إغلاق الفترة' : 'تم إعادة فتح الفترة',
    ]);
    exit;
}

printFailure("تعذّر تحديث الفترة");
