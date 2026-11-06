<?php
// ============================================================
// ملف: admin/hr/employee_details.php
// الغرض: تفاصيل موظف واحد + سجل رواتبه
//        يُفتح عند الضغط على صندوق القائمة
//
// الحقل الإجباري: admin_id
// الصلاحية: employees.view
// الطريقة: POST أو GET
// ============================================================

// ربط ملف الاتصال بقاعدة البيانات
include "../../connect.php";

// التحقق من التوكن وجلب بيانات الموظف مع صلاحياته
$currentAdmin = checkAdminToken();

// فرض صلاحية عرض ملفات الموظفين
requirePermission($currentAdmin, 'employees.view');

// معرّف الموظف المطلوب
$admin_id = (int)filterRequest("admin_id");

// إذا لم يُرسل المعرّف نوقف التنفيذ
if ($admin_id <= 0) {
    // رسالة توضح أن المعرّف مطلوب
    printFailure("admin_id is required");
    // إيقاف التنفيذ
    exit;
}

// جلب صف الموظف بدون كلمة المرور
$stmt = $con->prepare(
    "SELECT
        au.admin_id,
        au.admin_full_name,
        au.admin_job_code,
        au.admin_job_title,
        au.admin_phone,
        au.admin_whatsapp,
        au.admin_role,
        au.admin_is_active,
        au.admin_base_salary,
        au.admin_hire_date,
        au.admin_employment_type,
        au.admin_national_id,
        au.admin_bank_account,
        au.admin_hr_notes,
        au.admin_last_login,
        au.admin_created_at
     FROM admin_users au
     WHERE au.admin_id = ?
     LIMIT 1"
);
// تنفيذ الاستعلام بالمعرّف
$stmt->execute([$admin_id]);
// قراءة الصف
$emp = $stmt->fetch(PDO::FETCH_ASSOC);

// إذا لم يوجد الموظف نرفض الطلب
if (!$emp) {
    // رسالة واضحة
    printFailure("الموظف غير موجود", 404);
    // إيقاف التنفيذ
    exit;
}

// جلب سجل الرواتب مرتباً من الأحدث
$salStmt = $con->prepare(
    "SELECT
        salary_id,
        salary_admin_id,
        salary_month,
        salary_year,
        salary_base,
        salary_allowances,
        salary_deductions,
        salary_net,
        salary_status,
        salary_paid_at,
        salary_expense_id,
        salary_notes,
        salary_created_at
     FROM admin_salaries
     WHERE salary_admin_id = ?
     ORDER BY salary_year DESC, salary_month DESC, salary_id DESC"
);
// تنفيذ استعلام الرواتب
$salStmt->execute([$admin_id]);
// جلب كل الصفوف
$salaries = $salStmt->fetchAll(PDO::FETCH_ASSOC);

// دالة بناء رابط واتساب
$whatsappUrl = function (?string $phone): ?string {
    // إذا لم يوجد رقم نُرجع null
    if ($phone === null || $phone === '') {
        return null;
    }
    // الإبقاء على الأرقام فقط
    $digits = preg_replace('/[^0-9]/', '', $phone);
    // تحويل 010xxxxxxxx إلى 2010xxxxxxxx
    if (strlen($digits) === 11 && strpos($digits, '0') === 0) {
        $digits = '20' . substr($digits, 1);
    } elseif (strlen($digits) === 10) {
        $digits = '20' . $digits;
    }
    // إذا لم يتبقَّ أرقام نُرجع null
    if ($digits === '') {
        return null;
    }
    // رابط واتساب جاهز
    return 'https://wa.me/' . $digits;
};

// ترجمة الدور للعربية
$roleMap = [
    'owner'      => 'المالك',
    'manager'    => 'مدير',
    'accountant' => 'محاسب',
    'hr'         => 'موارد بشرية',
    'support'    => 'خدمة عملاء',
    'seller'     => 'مندوب مبيعات',
];

// رقم الواتساب المعروض
$waPhone = $emp['admin_whatsapp'] !== null && $emp['admin_whatsapp'] !== ''
    ? $emp['admin_whatsapp']
    : $emp['admin_phone'];

// تحويل سجل الرواتب
$salaryData = array_map(function ($s) {
    // إرجاع صف الراتب
    return [
        "salary_id"         => (int)$s['salary_id'],
        "salary_admin_id"   => (int)$s['salary_admin_id'],
        "salary_month"      => (int)$s['salary_month'],
        "salary_year"       => (int)$s['salary_year'],
        "salary_base"       => (float)$s['salary_base'],
        "salary_allowances" => (float)$s['salary_allowances'],
        "salary_deductions" => (float)$s['salary_deductions'],
        "salary_net"        => (float)$s['salary_net'],
        "salary_status"     => $s['salary_status'],
        "salary_paid_at"    => $s['salary_paid_at'],
        "salary_expense_id" => $s['salary_expense_id'] !== null ? (int)$s['salary_expense_id'] : null,
        "salary_notes"      => $s['salary_notes'],
        "salary_created_at" => $s['salary_created_at'],
    ];
}, $salaries);

// إرسال الرد
echo json_encode([
    // حالة العملية
    "status"  => "success",
    // رسالة نجاح
    "message" => "Employee details fetched successfully",
    // بيانات الموظف
    "data"    => [
        "admin_id"              => (int)$emp['admin_id'],
        "admin_full_name"       => $emp['admin_full_name'],
        "admin_job_code"        => $emp['admin_job_code'],
        "admin_job_title"       => $emp['admin_job_title'],
        "admin_phone"           => $emp['admin_phone'],
        "admin_whatsapp"        => $waPhone,
        "whatsapp_url"          => $whatsappUrl($waPhone),
        "admin_role"            => $emp['admin_role'],
        "admin_role_ar"         => $roleMap[$emp['admin_role']] ?? $emp['admin_role'],
        "admin_is_active"       => (int)$emp['admin_is_active'],
        "admin_base_salary"     => (float)$emp['admin_base_salary'],
        "admin_hire_date"       => $emp['admin_hire_date'],
        "admin_employment_type" => $emp['admin_employment_type'],
        "admin_national_id"     => $emp['admin_national_id'],
        "admin_bank_account"    => $emp['admin_bank_account'],
        "admin_hr_notes"        => $emp['admin_hr_notes'],
        "admin_last_login"      => $emp['admin_last_login'],
        "admin_created_at"      => $emp['admin_created_at'],
        "can_edit"              => adminCan($currentAdmin, 'employees.edit'),
        "can_pay_salary"        => adminCan($currentAdmin, 'salaries.pay'),
        "salaries"              => $salaryData,
    ],
], JSON_UNESCAPED_UNICODE);
