<?php
// ============================================================
// ملف: admin/hr/pay_salary.php
// الغرض: صرف راتب لشهر معيّن وترحيله تلقائياً إلى company_expenses
//
// الحقول الإجبارية: admin_id, salary_month, salary_year
// الاختيارية: salary_allowances, salary_deductions, salary_notes
// الصلاحية: salaries.pay
// الطريقة: POST
// ============================================================

// ربط ملف الاتصال بقاعدة البيانات
include "../../connect.php";

// التحقق من التوكن وجلب بيانات الموظف مع صلاحياته
$currentAdmin = checkAdminToken();

// فرض صلاحية صرف الراتب
requirePermission($currentAdmin, 'salaries.pay');

// معرّف الموظف المستحق
$admin_id = (int)filterRequest("admin_id");
// شهر الاستحقاق
$salary_month = (int)filterRequest("salary_month");
// سنة الاستحقاق
$salary_year = (int)filterRequest("salary_year");
// البدلات
$salary_allowances = filterRequest("salary_allowances");
// الخصومات
$salary_deductions = filterRequest("salary_deductions");
// ملاحظات الصرف
$salary_notes = trim(filterRequest("salary_notes"));

// التحقق من المعرّف
if ($admin_id <= 0) {
    // رسالة أن المعرّف مطلوب
    printFailure("admin_id is required");
    // إيقاف التنفيذ
    exit;
}

// التحقق من الشهر
if ($salary_month < 1 || $salary_month > 12) {
    // رسالة القيم المسموحة
    printFailure("salary_month must be between 1 and 12");
    // إيقاف التنفيذ
    exit;
}

// التحقق من السنة
if ($salary_year < 2000 || $salary_year > 2100) {
    // رسالة القيم المسموحة
    printFailure("salary_year is invalid");
    // إيقاف التنفيذ
    exit;
}

// جلب الموظف والراتب الأساسي
$stmtEmp = $con->prepare(
    "SELECT admin_id, admin_full_name, admin_job_code, admin_base_salary, admin_is_active
     FROM admin_users WHERE admin_id = ? LIMIT 1"
);
// تنفيذ الجلب
$stmtEmp->execute([$admin_id]);
// قراءة الصف
$emp = $stmtEmp->fetch(PDO::FETCH_ASSOC);

// إن لم يوجد الموظف نرفض
if (!$emp) {
    // رسالة واضحة
    printFailure("الموظف غير موجود", 404);
    // إيقاف التنفيذ
    exit;
}

// منع صرف راتب لموظف معطّل
if ((int)$emp['admin_is_active'] !== 1) {
    // رسالة التوضيح
    printFailure("لا يمكن صرف راتب لموظف معطّل", 409);
    // إيقاف التنفيذ
    exit;
}

// البدلات رقم غير سالب
$allowances = $salary_allowances === '' ? 0.0 : (float)$salary_allowances;
// الخصومات رقم غير سالب
$deductions = $salary_deductions === '' ? 0.0 : (float)$salary_deductions;
// رفض السالب
if ($allowances < 0 || $deductions < 0) {
    // رسالة واضحة
    printFailure("البدلات والخصومات يجب ألا تكون سالبة");
    // إيقاف التنفيذ
    exit;
}

// الراتب الأساسي وقت الصرف
$base = (float)$emp['admin_base_salary'];
// الصافي = أساسي + بدلات − خصومات
$net = round($base + $allowances - $deductions, 2);
// رفض صافٍ سالب
if ($net < 0) {
    // رسالة التوضيح
    printFailure("صافي الراتب لا يمكن أن يكون سالباً");
    // إيقاف التنفيذ
    exit;
}

// منع تكرار صرف نفس الشهر
$stmtDup = $con->prepare(
    "SELECT salary_id, salary_status FROM admin_salaries
     WHERE salary_admin_id = ? AND salary_year = ? AND salary_month = ?
     LIMIT 1"
);
// تنفيذ الفحص
$stmtDup->execute([$admin_id, $salary_year, $salary_month]);
// قراءة صف سابق
$existing = $stmtDup->fetch(PDO::FETCH_ASSOC);

// إن وُجد راتب غير ملغى نرفض
if ($existing && $existing['salary_status'] !== 'cancelled') {
    // رسالة التكرار
    printFailure("تم صرف راتب هذا الشهر مسبقاً", 409);
    // إيقاف التنفيذ
    exit;
}

// بدء معاملة حتى يُربط الراتب بالمصروف معاً
$con->beginTransaction();

try {
    // إدخال صف الراتب بحالة paid
    $insSal = $con->prepare(
        "INSERT INTO admin_salaries
            (salary_admin_id, salary_month, salary_year,
             salary_base, salary_allowances, salary_deductions, salary_net,
             salary_status, salary_paid_at, salary_notes, salary_created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'paid', NOW(), ?, ?)"
    );
    // تنفيذ إدخال الراتب
    $insSal->execute([
        $admin_id,
        $salary_month,
        $salary_year,
        $base,
        $allowances,
        $deductions,
        $net,
        $salary_notes !== '' ? $salary_notes : null,
        (int)$currentAdmin['admin_id'],
    ]);
    // معرّف الراتب الجديد
    $salaryId = (int)$con->lastInsertId();

    // عنوان المصروف المرتبط
    $title = 'راتب ' . $emp['admin_full_name'] . ' — ' . sprintf('%02d/%d', $salary_month, $salary_year);

    // إدخال مصروف الشركة من الراتب المدفوع
    $insExp = $con->prepare(
        "INSERT INTO company_expenses
            (expense_category, expense_title, expense_amount, expense_date,
             expense_status, expense_salary_id, expense_notes, expense_created_by)
         VALUES ('salary', ?, ?, CURDATE(), 'paid', ?, ?, ?)"
    );
    // تنفيذ إدخال المصروف
    $insExp->execute([
        $title,
        $net,
        $salaryId,
        $salary_notes !== '' ? $salary_notes : null,
        (int)$currentAdmin['admin_id'],
    ]);
    // معرّف المصروف الجديد
    $expenseId = (int)$con->lastInsertId();

    // ربط الراتب بالمصروف
    $link = $con->prepare("UPDATE admin_salaries SET salary_expense_id = ? WHERE salary_id = ?");
    // تنفيذ الربط
    $link->execute([$expenseId, $salaryId]);

    // تثبيت المعاملة
    $con->commit();
} catch (Exception $e) {
    // التراجع عند أي خطأ
    $con->rollBack();
    // رسالة فشل عامة
    printFailure("فشل صرف الراتب، يرجى المحاولة مرة أخرى");
    // إيقاف التنفيذ
    exit;
}

// تسجيل النشاط
logAdminActivity($currentAdmin, 'pay_salary', 'salary', $salaryId, [
    "admin_id" => $admin_id,
    "month"    => $salary_month,
    "year"     => $salary_year,
    "net"      => $net,
]);

// إرسال الرد
echo json_encode([
    "status"  => "success",
    "message" => "تم صرف الراتب وترحيله إلى المصروفات",
    "data"    => [
        "salary_id"         => $salaryId,
        "salary_admin_id"   => $admin_id,
        "employee_name"     => $emp['admin_full_name'],
        "salary_month"      => $salary_month,
        "salary_year"       => $salary_year,
        "salary_base"       => $base,
        "salary_allowances" => $allowances,
        "salary_deductions" => $deductions,
        "salary_net"        => $net,
        "salary_status"     => "paid",
        "expense_id"        => $expenseId,
    ],
], JSON_UNESCAPED_UNICODE);
