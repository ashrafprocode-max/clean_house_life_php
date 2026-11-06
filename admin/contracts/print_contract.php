<?php
// ============================================================
// ملف: admin/contracts/print_contract.php
// الغرض: صفحة HTML للطباعة وتوليد PDF من المتصفح
// الصلاحية المطلوبة: contracts.view
// الطريقة: GET أو POST
// يُستدعى من تفاصيل العقد بزر الطباعة.
// يبث HTML وليس JSON. العمولة مخفية في الجدول المطبوع.
// ============================================================

// اتصال قاعدة البيانات
include "../../connect.php";
// ترجمة الدورة والتأمينات والحالة للعربية في الصفحة
include "../../contracts/includes/contract_helper.php";

// جلسة الموظف إلزامية حتى لا تُطبع العقود علناً
$currentAdmin = checkAdminToken();
requirePermission($currentAdmin, 'contracts.view');

// معرّف العقد المطلوب طباعته
$contractId = (int) filterRequest("contract_id");
if ($contractId <= 0) {
    printFailure("contract_id is required");
}

// العقد مع اسم الشركة أو العميل للترويسة
$stmt = $con->prepare(
    "SELECT c.*, cr.company_name, u.users_full_name
     FROM contracts c
     LEFT JOIN company_registering cr ON cr.company_id = c.contract_company_id
     LEFT JOIN users u ON u.users_id = c.contract_users_id
     WHERE c.contract_id = ?
     LIMIT 1"
);
$stmt->execute([$contractId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    printFailure("العقد غير موجود", 404);
}

// البنود مرتبة كما في الاتفاق
$clausesStmt = $con->prepare(
    "SELECT clause_title_ar, clause_body_ar
     FROM contract_clauses
     WHERE clause_contract_id = ?
     ORDER BY clause_sort ASC, clause_id ASC"
);
$clausesStmt->execute([$contractId]);
$clauses = $clausesStmt->fetchAll(PDO::FETCH_ASSOC);

// اسم الطرف الثاني للترويسة ومنطقة التوقيع
$partyName = $row['company_name'] ?: ($row['users_full_name'] ?: 'الطرف الثاني');
// htmlspecialchars يمنع كسر HTML من العنوان
$title = htmlspecialchars($row['contract_title_ar'], ENT_QUOTES, 'UTF-8');
$code = htmlspecialchars($row['contract_code'], ENT_QUOTES, 'UTF-8');
$partyNameSafe = htmlspecialchars($partyName, ENT_QUOTES, 'UTF-8');

// صفحة طباعة وليست JSON API
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <title><?php echo $code; ?></title>
  <style>
    body { font-family: Tahoma, Arial, sans-serif; margin: 24px; color: #222; }
    h1 { font-size: 22px; margin-bottom: 4px; }
    .meta { color: #555; margin-bottom: 18px; }
    table { width: 100%; border-collapse: collapse; margin: 12px 0 20px; }
    td, th { border: 1px solid #ccc; padding: 8px; text-align: right; }
    .clause { margin-bottom: 14px; }
    .clause h3 { margin: 0 0 6px; font-size: 16px; }
    .sign { margin-top: 40px; display: flex; justify-content: space-between; }
    .box { width: 45%; border-top: 1px solid #999; padding-top: 8px; }
    @media print { .no-print { display: none; } }
  </style>
</head>
<body>
  <button class="no-print" onclick="window.print()">طباعة / حفظ PDF</button>
  <h1><?php echo $title; ?></h1>
  <div class="meta">رقم العقد: <?php echo $code; ?> | الطرف: <?php echo $partyNameSafe; ?></div>
  <table>
    <tr><th>عدد العمال</th><td><?php echo (int) $row['contract_workers_count']; ?></td></tr>
    <tr><th>تكلفة العامل شهرياً</th><td><?php echo number_format((float) $row['contract_worker_monthly_cost'], 2); ?> ج.م</td></tr>
    <tr><th>مدة العقد</th><td><?php echo (int) $row['contract_duration_months']; ?> شهر</td></tr>
    <tr><th>إجمالي تكلفة العمال</th><td><?php echo number_format((float) $row['contract_labor_total'], 2); ?> ج.م</td></tr>
    <tr><th>دورة التحصيل</th><td><?php echo htmlspecialchars(contractCycleAr($row['contract_payment_cycle']), ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><th>عدد الدفعات</th><td><?php echo (int) $row['contract_installments_count']; ?></td></tr>
    <tr><th>قيمة الدفعة</th><td><?php echo number_format((float) $row['contract_installment_amount'], 2); ?> ج.م</td></tr>
    <tr><th>التأمينات</th><td><?php echo htmlspecialchars(contractInsuranceAr($row['contract_insurance_on']), ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><th>من</th><td><?php echo htmlspecialchars((string) $row['contract_start_date'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><th>إلى</th><td><?php echo htmlspecialchars((string) $row['contract_end_date'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
  </table>
  <?php foreach ($clauses as $clause): ?>
    <div class="clause">
      <h3><?php echo htmlspecialchars($clause['clause_title_ar'], ENT_QUOTES, 'UTF-8'); ?></h3>
      <p><?php echo nl2br(htmlspecialchars($clause['clause_body_ar'], ENT_QUOTES, 'UTF-8')); ?></p>
    </div>
  <?php endforeach; ?>
  <div class="sign">
    <div class="box">التطبيق / الطرف الأول</div>
    <div class="box">
      الطرف الثاني: <?php echo $partyNameSafe; ?><br>
      <?php if ((int) $row['contract_e_signed'] === 1): ?>
        موافقة إلكترونية بعلامة صح باسم: <?php echo htmlspecialchars((string) $row['contract_signed_name'], ENT_QUOTES, 'UTF-8'); ?>
      <?php else: ?>
        التوقيع الورقي: ________________
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
<?php
exit;
