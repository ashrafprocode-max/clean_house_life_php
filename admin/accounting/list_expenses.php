<?php
// ============================================================
// ملف: admin/accounting/list_expenses.php
// الغرض: قائمة مصروفات الشركة مع فلترة التاريخ والحالة والتصنيف
//
// الفلاتر: status / category / date_from / date_to / search
// الصلاحية: expenses.view
// الطريقة: POST أو GET
// ============================================================

// ربط ملف الاتصال بقاعدة البيانات
include "../../connect.php";

// التحقق من التوكن وجلب بيانات الموظف مع صلاحياته
$currentAdmin = checkAdminToken();

// فرض صلاحية عرض المصروفات
requirePermission($currentAdmin, 'expenses.view');

// هل يملك إضافة؟
$canCreate = adminCan($currentAdmin, 'expenses.create');
// هل يملك تعديل؟
$canEdit   = adminCan($currentAdmin, 'expenses.edit');

// فلتر الحالة: all / pending / paid / cancelled
$status = trim(filterRequest("status"));
// فلتر التصنيف
$category = trim(filterRequest("category"));
// تاريخ البداية
$dateFrom = trim(filterRequest("date_from"));
// تاريخ النهاية
$dateTo = trim(filterRequest("date_to"));
// نص البحث
$search = trim(filterRequest("search"));
// رقم الصفحة
$page  = max(1, (int)(filterRequest("page") ?: 1));
// عدد النتائج
$limit = min(50, max(1, (int)(filterRequest("limit") ?: 20)));
// إزاحة SQL
$offset = ($page - 1) * $limit;

// التصنيفات المسموحة
$allowedCategories = [
    'salary', 'rent', 'utilities', 'marketing',
    'supplies', 'transport', 'maintenance', 'other',
];
// الحالات المسموحة
$allowedStatuses = ['pending', 'paid', 'cancelled'];

// شرط ثابت
$whereConditions = ["1 = 1"];
// معاملات الاستعلام
$params = [];

// فلتر الحالة
if (in_array($status, $allowedStatuses, true)) {
    // إضافة شرط الحالة
    $whereConditions[] = "e.expense_status = ?";
    // إضافة القيمة
    $params[] = $status;
}

// فلتر التصنيف
if (in_array($category, $allowedCategories, true)) {
    // إضافة شرط التصنيف
    $whereConditions[] = "e.expense_category = ?";
    // إضافة القيمة
    $params[] = $category;
}

// فلتر تاريخ البداية
if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    // المصروفات من هذا التاريخ
    $whereConditions[] = "e.expense_date >= ?";
    // إضافة القيمة
    $params[] = $dateFrom;
}

// فلتر تاريخ النهاية
if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    // المصروفات حتى هذا التاريخ
    $whereConditions[] = "e.expense_date <= ?";
    // إضافة القيمة
    $params[] = $dateTo;
}

// فلتر البحث
if ($search !== '') {
    // البحث في العنوان والملاحظات
    $whereConditions[] = "(e.expense_title LIKE ? OR e.expense_notes LIKE ?)";
    // نمط LIKE
    $like = '%' . $search . '%';
    // إضافة مرتين
    $params[] = $like;
    $params[] = $like;
}

// دمج الشروط
$whereSql = implode(' AND ', $whereConditions);

// إحصائيات عامة (بدون فلترة الصفحات)
$statsSQL = "
    SELECT
        COUNT(expense_id) AS all_count,
        SUM(CASE WHEN expense_status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN expense_status = 'paid' THEN 1 ELSE 0 END) AS paid_count,
        SUM(CASE WHEN expense_status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
        COALESCE(SUM(CASE WHEN expense_status = 'paid' THEN expense_amount ELSE 0 END), 0) AS paid_total
    FROM company_expenses
";
// تنفيذ الإحصائيات
$statsStmt = $con->prepare($statsSQL);
// بدون معاملات
$statsStmt->execute();
// قراءة الإحصائيات
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// عدد النتائج بعد الفلترة
$countSQL = "SELECT COUNT(e.expense_id) FROM company_expenses e WHERE $whereSql";
// تجهيز العدّ
$countStmt = $con->prepare($countSQL);
// تنفيذ العدّ
$countStmt->execute($params);
// الإجمالي
$totalCount = (int)$countStmt->fetchColumn();
// عدد الصفحات
$totalPages = $totalCount > 0 ? (int)ceil($totalCount / $limit) : 1;

// مجموع المبالغ المفلترة
$sumSQL = "SELECT COALESCE(SUM(e.expense_amount), 0) FROM company_expenses e WHERE $whereSql";
// تجهيز المجموع
$sumStmt = $con->prepare($sumSQL);
// تنفيذ المجموع
$sumStmt->execute($params);
// مجموع الفلتر
$filteredTotal = (float)$sumStmt->fetchColumn();

// جلب القائمة
$sql = "
    SELECT
        e.expense_id,
        e.expense_category,
        e.expense_title,
        e.expense_amount,
        e.expense_date,
        e.expense_status,
        e.expense_salary_id,
        e.expense_notes,
        e.expense_created_by,
        e.expense_created_at,
        creator.admin_full_name AS created_by_name
    FROM company_expenses e
    LEFT JOIN admin_users creator ON creator.admin_id = e.expense_created_by
    WHERE $whereSql
    ORDER BY e.expense_date DESC, e.expense_id DESC
    LIMIT $limit OFFSET $offset
";
// تجهيز الاستعلام
$stmt = $con->prepare($sql);
// تنفيذ بمعاملات الفلترة
$stmt->execute($params);
// جلب الصفوف
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ترجمة التصنيف للعربية
$catAr = [
    'salary'      => 'رواتب',
    'rent'        => 'إيجار',
    'utilities'   => 'مرافق',
    'marketing'   => 'تسويق',
    'supplies'    => 'مستلزمات',
    'transport'   => 'انتقالات',
    'maintenance' => 'صيانة',
    'other'       => 'أخرى',
];

// ترجمة الحالة للعربية
$statusAr = [
    'pending'   => 'معلّق',
    'paid'      => 'مدفوع',
    'cancelled' => 'ملغى',
];

// تحويل الصفوف
$resultData = array_map(function ($row) use ($catAr, $statusAr, $canEdit) {
    // هل المصروف مرتبط براتب؟ (لا يُعدَّل يدوياً إلا بحذر)
    $fromSalary = $row['expense_salary_id'] !== null;
    // إرجاع صف المصروف
    return [
        "expense_id"         => (int)$row['expense_id'],
        "expense_category"   => $row['expense_category'],
        "expense_category_ar"=> $catAr[$row['expense_category']] ?? $row['expense_category'],
        "expense_title"      => $row['expense_title'],
        "expense_amount"     => (float)$row['expense_amount'],
        "expense_date"       => $row['expense_date'],
        "expense_status"     => $row['expense_status'],
        "expense_status_ar"  => $statusAr[$row['expense_status']] ?? $row['expense_status'],
        "expense_salary_id"  => $row['expense_salary_id'] !== null ? (int)$row['expense_salary_id'] : null,
        "from_salary"        => $fromSalary,
        "expense_notes"      => $row['expense_notes'],
        "created_by_name"    => $row['created_by_name'],
        "expense_created_at" => $row['expense_created_at'],
        "can_edit"           => $canEdit && !$fromSalary,
    ];
}, $rows);

// إرسال الرد
echo json_encode([
    "status"     => "success",
    "message"    => "Expenses list fetched successfully",
    "data"       => $resultData,
    "can_create" => $canCreate,
    "can_edit"   => $canEdit,
    "filtered_total" => $filteredTotal,
    "stats" => [
        "all_count"       => (int)($stats['all_count'] ?? 0),
        "pending_count"   => (int)($stats['pending_count'] ?? 0),
        "paid_count"      => (int)($stats['paid_count'] ?? 0),
        "cancelled_count" => (int)($stats['cancelled_count'] ?? 0),
        "paid_total"      => (float)($stats['paid_total'] ?? 0),
    ],
    "pagination" => [
        "current_page"  => $page,
        "per_page"      => $limit,
        "total_count"   => $totalCount,
        "total_pages"   => $totalPages,
        "has_next_page" => ($page < $totalPages),
        "has_prev_page" => ($page > 1),
    ],
], JSON_UNESCAPED_UNICODE);
