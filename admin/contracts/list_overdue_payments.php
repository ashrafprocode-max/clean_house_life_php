<?php
// ============================================================
// ملف: admin/contracts/list_overdue_payments.php
// الغرض: قائمة دفعات العقود المتأخرة للإدارة
// الصلاحية المطلوبة: payments.view
// الطريقة: POST
// ============================================================

// اتصال قاعدة البيانات
include "../../connect.php";
// ترجمة الحالة والطرف ووسيلة التحصيل
include "../../contracts/includes/contract_helper.php";

// جلسة الموظف إلزامية
$currentAdmin = checkAdminToken();
// العرض يحتاج payments.view وليس الإخفاء في الواجهة فقط
requirePermission($currentAdmin, 'payments.view');
// زر التحصيل يظهر لمن يملك payments.manage
$canRecord = adminCan($currentAdmin, 'payments.manage');

// رقم الصفحة لا يقل عن 1
$page = max(1, (int) (filterRequest("page") ?: 1));
// حد أعلى 50 حتى لا تُحمَّل اللوحة دفعة كبيرة
$limit = min(50, max(1, (int) (filterRequest("limit") ?: 20)));
$offset = ($page - 1) * $limit;
$partyType = trim(filterRequest("party_type"));

// المتأخرة على عقود موقّعة أو سارية فقط وليست المسودات
$where = [
    "i.installment_status = 'overdue'",
    "c.contract_status IN ('signed', 'active')",
];
$params = [];
if ($partyType === 'company' || $partyType === 'individual') {
    $where[] = "c.contract_party_type = ?";
    $params[] = $partyType;
}
$whereSql = implode(' AND ', $where);

// عدد الصفوف للتصفح بنفس الفلاتر
$countStmt = $con->prepare(
    "SELECT COUNT(*)
     FROM contract_installments i
     INNER JOIN contracts c ON c.contract_id = i.installment_contract_id
     WHERE $whereSql"
);
$countStmt->execute($params);
$totalCount = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalCount / $limit));

// الأقدم استحقاقاً أولاً حتى تُعالج الحالات الأخطر قبل الأحدث
$listStmt = $con->prepare(
    "SELECT i.*, c.contract_code, c.contract_party_type, c.contract_title_ar,
            cr.company_name, u.users_full_name,
            DATEDIFF(CURDATE(), i.installment_due_date) AS days_overdue
     FROM contract_installments i
     INNER JOIN contracts c ON c.contract_id = i.installment_contract_id
     LEFT JOIN company_registering cr ON cr.company_id = c.contract_company_id
     LEFT JOIN users u ON u.users_id = c.contract_users_id
     WHERE $whereSql
     ORDER BY i.installment_due_date ASC, i.installment_id ASC
     LIMIT $limit OFFSET $offset"
);
$listStmt->execute($params);
$rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// إحصائيات عامة لكل المتأخرين وليس الصفحة الحالية فقط
$statsStmt = $con->query(
    "SELECT
        COUNT(*) AS overdue_count,
        COALESCE(SUM(i.installment_amount), 0) AS overdue_total,
        COALESCE(SUM(i.installment_commission), 0) AS overdue_commission
     FROM contract_installments i
     INNER JOIN contracts c ON c.contract_id = i.installment_contract_id
     WHERE i.installment_status = 'overdue'
       AND c.contract_status IN ('signed', 'active')"
);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

// can_record من صلاحية الإدارة وليس من حالة الصف لأن كل الصفوف متأخرة
$data = array_map(function ($r) use ($canRecord) {
    return [
        'installment_id' => (int) $r['installment_id'],
        'contract_id' => (int) $r['installment_contract_id'],
        'contract_code' => $r['contract_code'],
        'party_type' => $r['contract_party_type'],
        'party_type_ar' => contractPartyAr($r['contract_party_type']),
        'title_ar' => $r['contract_title_ar'],
        'party_name' => $r['company_name'] ?: $r['users_full_name'],
        'number' => (int) $r['installment_number'],
        'due_date' => $r['installment_due_date'],
        'days_overdue' => (int) ($r['days_overdue'] ?? 0),
        'amount' => (float) $r['installment_amount'],
        'commission' => (float) $r['installment_commission'],
        'status' => $r['installment_status'],
        'status_ar' => contractInstallmentStatusAr($r['installment_status']),
        'paid_at' => $r['installment_paid_at'],
        'paid_via' => $r['installment_paid_via'],
        'method' => $r['installment_method'],
        'method_ar' => contractMethodAr($r['installment_method']),
        'can_record' => $canRecord,
    ];
}, $rows);

// حقول unpaid/paid تُملأ لتتوافق شاشة المدفوعات العامة مع بطاقة المتأخرين
echo json_encode([
    'status' => 'success',
    'data' => $data,
    'stats' => [
        'overdue_count' => (int) ($stats['overdue_count'] ?? 0),
        'overdue_total' => (float) ($stats['overdue_total'] ?? 0),
        'overdue_commission' => (float) ($stats['overdue_commission'] ?? 0),
        'unpaid_count' => (int) ($stats['overdue_count'] ?? 0),
        'paid_count' => 0,
        'paid_total' => 0,
        'commission_total' => (float) ($stats['overdue_commission'] ?? 0),
        'app_paid_count' => 0,
        'admin_paid_count' => 0,
    ],
    'can_record' => $canRecord,
    'pagination' => [
        'current_page' => $page,
        'per_page' => $limit,
        'total_count' => $totalCount,
        'total_pages' => $totalPages,
        'has_next_page' => $page < $totalPages,
    ],
]);
