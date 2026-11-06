<?php
// ============================================================
// ملف: admin/contracts/list_contracts.php
// الغرض: قائمة عقود الشركات أو الأفراد مع فلترة الحالة والبحث
// الصلاحية المطلوبة: contracts.view
// الطريقة: POST
// ============================================================

// ربط قاعدة البيانات والدوال العامة
include "../../connect.php";

// دوال حساب العمولة والدفعات
include "../../contracts/includes/contract_helper.php";

// التحقق من توكن الموظف
$currentAdmin = checkAdminToken();

// فرض صلاحية عرض العقود
requirePermission($currentAdmin, 'contracts.view');

// نوع الطرف: company أو individual أو all
$partyType = trim(filterRequest("party_type"));

// حالة العقد
$status = trim(filterRequest("status"));

// نص البحث في رقم العقد أو اسم الشركة أو العميل
$search = trim(filterRequest("search"));

// رقم الصفحة لا يقل عن 1
$page = max(1, (int) (filterRequest("page") ?: 1));

// عدد النتائج لا يزيد عن 50
$limit = min(50, max(1, (int) (filterRequest("limit") ?: 20)));

// نقطة البداية
$offset = ($page - 1) * $limit;

// شروط WHERE المشتركة
$where = ["1 = 1"];
$params = [];

// فلتر نوع الطرف
if ($partyType === 'company' || $partyType === 'individual') {
    $where[] = "c.contract_party_type = ?";
    $params[] = $partyType;
}

// فلتر الحالة
$allowedStatus = ['draft', 'pending_signature', 'signed', 'active', 'completed', 'cancelled'];
if (in_array($status, $allowedStatus, true)) {
    $where[] = "c.contract_status = ?";
    $params[] = $status;
}

// البحث بالرقم أو الاسم
if ($search !== '') {
    $where[] = "(c.contract_code LIKE ? OR c.contract_title_ar LIKE ? OR cr.company_name LIKE ? OR u.users_full_name LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSql = implode(' AND ', $where);

// إحصائيات الحالات حسب نوع الطرف الحالي
$statsSql = "SELECT
    SUM(c.contract_status = 'draft') AS draft_count,
    SUM(c.contract_status = 'pending_signature') AS pending_signature_count,
    SUM(c.contract_status = 'signed') AS signed_count,
    SUM(c.contract_status = 'active') AS active_count,
    SUM(c.contract_status = 'completed') AS completed_count,
    SUM(c.contract_status = 'cancelled') AS cancelled_count,
    COUNT(*) AS all_count
 FROM contracts c
 LEFT JOIN company_registering cr ON cr.company_id = c.contract_company_id
 LEFT JOIN users u ON u.users_id = c.contract_users_id
 WHERE 1 = 1";
$statsParams = [];
if ($partyType === 'company' || $partyType === 'individual') {
    $statsSql .= " AND c.contract_party_type = ?";
    $statsParams[] = $partyType;
}
$statsStmt = $con->prepare($statsSql);
$statsStmt->execute($statsParams);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

// عدد النتائج للتصفح
$countStmt = $con->prepare(
    "SELECT COUNT(*) FROM contracts c
     LEFT JOIN company_registering cr ON cr.company_id = c.contract_company_id
     LEFT JOIN users u ON u.users_id = c.contract_users_id
     WHERE $whereSql"
);
$countStmt->execute($params);
$totalCount = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalCount / $limit));

// قائمة العقود
$listStmt = $con->prepare(
    "SELECT c.*,
            cr.company_name,
            cr.company_type,
            u.users_full_name,
            (
              SELECT COUNT(*) FROM contract_installments i
              WHERE i.installment_contract_id = c.contract_id
            ) AS installments_total,
            (
              SELECT COUNT(*) FROM contract_installments i
              WHERE i.installment_contract_id = c.contract_id
                AND i.installment_status = 'paid'
            ) AS installments_paid
     FROM contracts c
     LEFT JOIN company_registering cr ON cr.company_id = c.contract_company_id
     LEFT JOIN users u ON u.users_id = c.contract_users_id
     WHERE $whereSql
     ORDER BY c.contract_id DESC
     LIMIT $limit OFFSET $offset"
);
$listStmt->execute($params);
$rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// هل يجوز إنشاء/تعديل؟
$canCreate = adminCan($currentAdmin, 'contracts.create');
$canEdit = adminCan($currentAdmin, 'contracts.edit');
$canPay = adminCan($currentAdmin, 'payments.manage');

$result = array_map(static function ($row) use ($canEdit, $canPay) {
    return [
        'contract_id' => (int) $row['contract_id'],
        'contract_code' => $row['contract_code'],
        'contract_party_type' => $row['contract_party_type'],
        'contract_party_type_ar' => contractPartyAr($row['contract_party_type']),
        'contract_title_ar' => $row['contract_title_ar'],
        'company_id' => $row['contract_company_id'] ? (int) $row['contract_company_id'] : null,
        'company_name' => $row['company_name'],
        'users_id' => $row['contract_users_id'] ? (int) $row['contract_users_id'] : null,
        'users_full_name' => $row['users_full_name'],
        'job_request_id' => $row['contract_job_request_id'] ? (int) $row['contract_job_request_id'] : null,
        'booking_id' => $row['contract_booking_id'] ? (int) $row['contract_booking_id'] : null,
        'workers_count' => (int) $row['contract_workers_count'],
        'duration_months' => (int) $row['contract_duration_months'],
        'labor_total' => (float) $row['contract_labor_total'],
        'commission_percent' => (float) $row['contract_commission_percent'],
        'commission_amount' => (float) $row['contract_commission_amount'],
        'payment_cycle' => $row['contract_payment_cycle'],
        'payment_cycle_ar' => contractCycleAr($row['contract_payment_cycle']),
        'installments_count' => (int) $row['contract_installments_count'],
        'installments_paid' => (int) $row['installments_paid'],
        'installments_total' => (int) $row['installments_total'],
        'start_date' => $row['contract_start_date'],
        'end_date' => $row['contract_end_date'],
        'status' => $row['contract_status'],
        'status_ar' => contractStatusAr($row['contract_status']),
        'e_signed' => (int) $row['contract_e_signed'] === 1,
        'has_paper_scan' => !empty($row['contract_paper_scan']),
        'has_pdf' => !empty($row['contract_pdf_file']),
        'can_edit' => $canEdit,
        'can_record_payment' => $canPay,
    ];
}, $rows);

echo json_encode([
    'status' => 'success',
    'message' => 'Contracts list fetched successfully',
    'data' => $result,
    'stats' => [
        'draft_count' => (int) ($stats['draft_count'] ?? 0),
        'pending_signature_count' => (int) ($stats['pending_signature_count'] ?? 0),
        'signed_count' => (int) ($stats['signed_count'] ?? 0),
        'active_count' => (int) ($stats['active_count'] ?? 0),
        'completed_count' => (int) ($stats['completed_count'] ?? 0),
        'cancelled_count' => (int) ($stats['cancelled_count'] ?? 0),
        'all_count' => (int) ($stats['all_count'] ?? 0),
    ],
    'can_create' => $canCreate,
    'can_edit' => $canEdit,
    'can_pay' => $canPay,
    'pagination' => [
        'current_page' => $page,
        'per_page' => $limit,
        'total_count' => $totalCount,
        'total_pages' => $totalPages,
        'has_next_page' => $page < $totalPages,
        'has_prev_page' => $page > 1,
    ],
]);
