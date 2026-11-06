<?php
// ============================================================
// ملف: cron/process_due_installments.php
// الغرض: تعليم الدفعات المتأخرة وإرسال تذكير 7 أيام / يوم / تأخير
//
// التشغيل: يومياً عبر crontab أو استدعاء محمي بالمفتاح
// الطريقة: GET أو POST
// الحماية: X-Admin-Key أو admin_key أو توكن موظف
// ============================================================

include __DIR__ . "/../connect.php";
include __DIR__ . "/../contracts/includes/contract_helper.php";
include_once __DIR__ . "/../notifications/includes/notification_hooks.php";

$sentKey = trim((string) ($_SERVER['HTTP_X_ADMIN_KEY'] ?? ''));
if ($sentKey === '') {
    $sentKey = trim((string) filterRequest("admin_key"));
}
$hasKey = $sentKey !== '' && isset($admin_secret_key) && hash_equals((string) $admin_secret_key, $sentKey);
if (!$hasKey) {
    $currentAdmin = checkAdminToken();
    requirePermission($currentAdmin, 'payments.view');
}

$markedOverdue = 0;
$reminded7 = 0;
$reminded1 = 0;
$overdueNotified = 0;

$mark = $con->prepare(
    "UPDATE contract_installments
     SET installment_status = 'overdue'
     WHERE installment_status IN ('pending', 'due')
       AND installment_due_date < CURDATE()"
);
$mark->execute();
$markedOverdue = $mark->rowCount();

$selectSql = "SELECT i.*, c.contract_id, c.contract_code, c.contract_users_id, c.contract_status,
                     cr.company_name, u.users_full_name
              FROM contract_installments i
              INNER JOIN contracts c ON c.contract_id = i.installment_contract_id
              LEFT JOIN company_registering cr ON cr.company_id = c.contract_company_id
              LEFT JOIN users u ON u.users_id = c.contract_users_id
              WHERE c.contract_status IN ('signed', 'active')
                AND i.installment_status IN ('pending', 'due', 'overdue')";
$rows = $con->query($selectSql)->fetchAll(PDO::FETCH_ASSOC);

$today = new DateTime('today');
$overdueCount = 0;
$overdueTotal = 0.0;

foreach ($rows as $row) {
    $dueRaw = (string) ($row['installment_due_date'] ?? '');
    if ($dueRaw === '') {
        continue;
    }
    try {
        $due = new DateTime($dueRaw);
    } catch (Throwable $e) {
        continue;
    }
    $due->setTime(0, 0, 0);
    $diff = (int) $today->diff($due)->format('%r%a');
    $status = (string) ($row['installment_status'] ?? '');

    if ($diff === 7 && in_array($status, ['pending', 'due'], true)) {
        notifyInstallmentDueReminder($row, 7);
        $reminded7++;
    } elseif ($diff === 1 && in_array($status, ['pending', 'due'], true)) {
        notifyInstallmentDueReminder($row, 1);
        $reminded1++;
    } elseif ($diff < 0 || $status === 'overdue') {
        notifyInstallmentOverdue($row);
        $overdueNotified++;
        $overdueCount++;
        $overdueTotal += (float) ($row['installment_amount'] ?? 0);
    }
}

if ($overdueCount > 0) {
    notifyAdminOverdueSummary($overdueCount, $overdueTotal);
}

printSuccess([
    'marked_overdue' => $markedOverdue,
    'reminded_7d' => $reminded7,
    'reminded_1d' => $reminded1,
    'overdue_notified' => $overdueNotified,
    'overdue_count' => $overdueCount,
    'overdue_total' => round($overdueTotal, 2),
    'message' => 'تم فحص الاستحقاقات وإرسال التذكيرات',
]);
