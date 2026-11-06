<?php
// ============================================================
// ملف: admin/owner/list_employees.php
// الغرض: قائمة الموظفين التشغيليين مع بحث وصفحات
// الطريقة: POST — للمالك فقط
// ============================================================

// تمهيد قسم المالك وفرض دور owner
include "_owner_boot.php";

// حالة التفعيل: all / active / inactive
$status = trim(filterRequest("status"));
// نص البحث بالاسم أو الهاتف أو كود الوظيفة
$search = trim(filterRequest("search"));
// رقم الصفحة
$page = (int) filterRequest("page");
// حجم الصفحة
$limit = (int) filterRequest("limit");
// تصحيح الصفحة
if ($page < 1) {
    $page = 1;
}
// تصحيح الحجم
if ($limit < 1 || $limit > 100) {
    $limit = 20;
}
// إزاحة الصفوف
$offset = ($page - 1) * $limit;

// شروط الاستعلام
$where = "WHERE 1=1";
$params = [];

// تصفية حسب التفعيل
if ($status === 'active') {
    $where .= " AND employee_is_active = 1";
} elseif ($status === 'inactive') {
    $where .= " AND employee_is_active = 0";
}

// بحث حر
if ($search !== '') {
    $where .= " AND (employee_full_name LIKE ? OR employee_phone LIKE ? OR employee_job_code LIKE ? OR employee_job_title LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

// إحصائيات أعلى الصفحة
$statsStmt = $con->prepare(
    "SELECT
        COUNT(employee_id) AS all_count,
        SUM(CASE WHEN employee_is_active = 1 THEN 1 ELSE 0 END) AS active_count,
        SUM(CASE WHEN employee_is_active = 0 THEN 1 ELSE 0 END) AS inactive_count
     FROM employees"
);
$statsStmt->execute();
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// عدد الصفوف المطابقة للفلتر
$countStmt = $con->prepare("SELECT COUNT(employee_id) FROM employees $where");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

// جلب الصفحة الحالية
$sql = "SELECT
            employee_id,
            employee_full_name,
            employee_phone,
            employee_whatsapp,
            employee_job_code,
            employee_job_title,
            employee_national_id,
            employee_hire_date,
            employee_employment_type,
            employee_base_salary,
            employee_bank_account,
            employee_notes,
            employee_id_card_front,
            employee_id_card_back,
            employee_criminal_record,
            employee_contract_pdf,
            employee_is_active,
            employee_created_at
        FROM employees
        $where
        ORDER BY employee_is_active DESC, employee_created_at DESC
        LIMIT $limit OFFSET $offset";
$stmt = $con->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// تحويل الصفوف لشكل Flutter
$data = array_map(function ($r) {
    $phone = (string) $r['employee_phone'];
    $wa = $r['employee_whatsapp'] !== null && $r['employee_whatsapp'] !== ''
        ? (string) $r['employee_whatsapp']
        : $phone;
    return [
        "employee_id" => (int) $r['employee_id'],
        "employee_full_name" => $r['employee_full_name'],
        "employee_phone" => $phone,
        "employee_whatsapp" => $wa,
        "whatsapp_url" => "https://wa.me/" . preg_replace('/\D+/', '', $wa),
        "employee_job_code" => $r['employee_job_code'],
        "employee_job_title" => $r['employee_job_title'],
        "employee_national_id" => $r['employee_national_id'],
        "employee_hire_date" => $r['employee_hire_date'],
        "employee_employment_type" => $r['employee_employment_type'],
        "employee_base_salary" => (float) $r['employee_base_salary'],
        "employee_bank_account" => $r['employee_bank_account'],
        "employee_notes" => $r['employee_notes'],
        "employee_id_card_front" => $r['employee_id_card_front'],
        "employee_id_card_back" => $r['employee_id_card_back'],
        "employee_criminal_record" => $r['employee_criminal_record'],
        "employee_contract_pdf" => $r['employee_contract_pdf'],
        "employee_is_active" => (int) $r['employee_is_active'],
        "employee_created_at" => $r['employee_created_at'],
        "can_edit" => true,
        "can_pay_salary" => true,
    ];
}, $rows);

echo json_encode([
    "status" => "success",
    "data" => $data,
    "stats" => [
        "all_count" => (int) ($stats['all_count'] ?? 0),
        "active_count" => (int) ($stats['active_count'] ?? 0),
        "inactive_count" => (int) ($stats['inactive_count'] ?? 0),
    ],
    "pagination" => [
        "current_page" => $page,
        "limit" => $limit,
        "total" => $total,
        "has_next_page" => ($offset + count($data)) < $total,
    ],
    "can_create" => true,
    "can_edit" => true,
]);
