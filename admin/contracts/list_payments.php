<?php
// ============================================================
// ملف: admin/contracts/list_payments.php
// الغرض: مدفوعات العقود: دفع عبر التطبيق + تحصيل يدوي من الإدارة
// الصلاحية المطلوبة: payments.view
// الطريقة: POST
// يُستدعى من شاشة مدفوعات العقود في اللوحة.
// يُرجع القائمة مع إحصائيات وcan_record. لا يحصّل دفعات.
// ============================================================

// اتصال قاعدة البيانات
include "../../connect.php";
// ترجمة الطرف والحالة ووسيلة التحصيل
include "../../contracts/includes/contract_helper.php";

// جلسة الموظف إلزامية
$currentAdmin = checkAdminToken();
// العرض يحتاج payments.view وليس الإخفاء في الواجهة فقط
requirePermission($currentAdmin, 'payments.view');

// مصدر التحصيل: تطبيق أو يدوي أو الكل
$source = trim(filterRequest("source"));
// حالة الدفعة للفلترة
$status = trim(filterRequest("status"));
// نوع طرف العقد
$partyType = trim(filterRequest("party_type"));
// رقم الصفحة لا يقل عن 1
$page = max(1, (int) (filterRequest("page") ?: 1));
// حد أعلى 50 حتى لا تُحمَّل اللوحة دفعة كبيرة
$limit = min(50, max(1, (int) (filterRequest("limit") ?: 20)));
// إزاحة التصفح
$offset = ($page - 1) * $limit;

// شرط دائم حتى يبقى implode صالحاً بلا فلاتر
$where = ["1 = 1"];
$params = [];

// الحالات الأربع المعروضة في التبويبات
if ($status === 'paid' || $status === 'pending' || $status === 'due' || $status === 'overdue') {
    $where[] = "i.installment_status = ?";
    $params[] = $status;
}
// دفع من التطبيق عبر Paymob أو المحفظة
if ($source === 'app') {
    $where[] = "i.installment_paid_via = 'app'";
} elseif ($source === 'admin_manual') {
    // تحصيل سجّلته الإدارة يدوياً
    $where[] = "i.installment_paid_via = 'admin_manual'";
}
// فلتر الشركة أو الأفراد
if ($partyType === 'company' || $partyType === 'individual') {
    $where[] = "c.contract_party_type = ?";
    $params[] = $partyType;
}
// دمج الشروط للاستعلامين التاليين
$whereSql = implode(' AND ', $where);

// عدد الصفوف للتصفح بنفس الفلاتر
$countStmt = $con->prepare(
    "SELECT COUNT(*)
     FROM contract_installments i
     INNER JOIN contracts c ON c.contract_id = i.installment_contract_id
     LEFT JOIN company_registering cr ON cr.company_id = c.contract_company_id
     LEFT JOIN users u ON u.users_id = c.contract_users_id
     WHERE $whereSql"
);
$countStmt->execute($params);
$totalCount = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalCount / $limit));

// القائمة مع اسم الشركة أو العميل للبطاقة
$listStmt = $con->prepare(
    "SELECT i.*, c.contract_code, c.contract_party_type, c.contract_title_ar,
            cr.company_name, u.users_full_name
     FROM contract_installments i
     INNER JOIN contracts c ON c.contract_id = i.installment_contract_id
     LEFT JOIN company_registering cr ON cr.company_id = c.contract_company_id
     LEFT JOIN users u ON u.users_id = c.contract_users_id
     WHERE $whereSql
     ORDER BY i.installment_due_date DESC, i.installment_id DESC
     LIMIT $limit OFFSET $offset"
);
$listStmt->execute($params);
$rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// إحصائيات عامة لكل الدفعات وليس الصفحة الحالية فقط
$statsStmt = $con->query(
    "SELECT
        SUM(installment_status = 'paid') AS paid_count,
        SUM(installment_status IN ('pending','due','overdue')) AS unpaid_count,
        SUM(installment_paid_via = 'app' AND installment_status = 'paid') AS app_paid_count,
        SUM(installment_paid_via = 'admin_manual' AND installment_status = 'paid') AS admin_paid_count,
        SUM(CASE WHEN installment_status = 'paid' THEN installment_amount ELSE 0 END) AS paid_total,
        SUM(CASE WHEN installment_status = 'paid' THEN installment_commission ELSE 0 END) AS commission_total
     FROM contract_installments"
);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

// تحويل الصفوف لحقول الواجهة مع أعلام التحصيل
$data = array_map(static function ($r) {
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
        'amount' => (float) $r['installment_amount'],
        'commission' => (float) $r['installment_commission'],
        'status' => $r['installment_status'],
        'status_ar' => contractInstallmentStatusAr($r['installment_status']),
        'paid_at' => $r['installment_paid_at'],
        'paid_via' => $r['installment_paid_via'],
        'paid_via_ar' => $r['installment_paid_via'] === 'app'
            ? 'دفع عبر التطبيق'
            : ($r['installment_paid_via'] === 'admin_manual' ? 'تحصيل بواسطة الإدارة' : null),
        'method' => $r['installment_method'],
        'method_ar' => contractMethodAr($r['installment_method']),
        'evidence_file' => $r['installment_evidence_file'],
        'evidence_type' => $r['installment_evidence_type'],
        'payment_id' => $r['installment_payment_id'] ? (int) $r['installment_payment_id'] : null,
        'can_record' => in_array($r['installment_status'], ['pending', 'due', 'overdue'], true),
    ];
}, $rows);

// JSON يدوي لإضافة الإحصائيات والتصفح بجانب القائمة
echo json_encode([
    'status' => 'success',
    'data' => $data,
    'stats' => [
        'paid_count' => (int) ($stats['paid_count'] ?? 0),
        'unpaid_count' => (int) ($stats['unpaid_count'] ?? 0),
        'app_paid_count' => (int) ($stats['app_paid_count'] ?? 0),
        'admin_paid_count' => (int) ($stats['admin_paid_count'] ?? 0),
        'paid_total' => (float) ($stats['paid_total'] ?? 0),
        'commission_total' => (float) ($stats['commission_total'] ?? 0),
    ],
    'can_record' => adminCan($currentAdmin, 'payments.manage'),
    'pagination' => [
        'current_page' => $page,
        'per_page' => $limit,
        'total_count' => $totalCount,
        'total_pages' => $totalPages,
        'has_next_page' => $page < $totalPages,
    ],
]);
