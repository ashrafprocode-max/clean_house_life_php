<?php
// ============================================================
// ملف: admin/hr/edit_employee.php
// الغرض: تعديل ملف موظف في الموارد البشرية (تحديث جزئي)
//
// الحقل الإجباري: admin_id
// الصلاحية: employees.edit
// الطريقة: POST
// ============================================================

// ربط ملف الاتصال بقاعدة البيانات
include "../../connect.php";

// التحقق من التوكن وجلب بيانات الموظف مع صلاحياته
$currentAdmin = checkAdminToken();

// فرض صلاحية تعديل ملف موظف
requirePermission($currentAdmin, 'employees.edit');

// معرّف الموظف المراد تعديله
$admin_id = (int)filterRequest("admin_id");
// الاسم الكامل الجديد
$admin_full_name = trim(filterRequest("admin_full_name"));
// رقم الهاتف الجديد
$admin_phone = trim(filterRequest("admin_phone"));
// كود الوظيفة
$admin_job_code = trim(filterRequest("admin_job_code"));
// المسمى الوظيفي
$admin_job_title = trim(filterRequest("admin_job_title"));
// رقم الواتساب
$admin_whatsapp = trim(filterRequest("admin_whatsapp"));
// الراتب الأساسي
$admin_base_salary = filterRequest("admin_base_salary");
// تاريخ التعيين
$admin_hire_date = trim(filterRequest("admin_hire_date"));
// نوع التعاقد
$admin_employment_type = trim(filterRequest("admin_employment_type"));
// الرقم القومي
$admin_national_id = trim(filterRequest("admin_national_id"));
// الحساب البنكي
$admin_bank_account = trim(filterRequest("admin_bank_account"));
// ملاحظات الموارد البشرية
$admin_hr_notes = trim(filterRequest("admin_hr_notes"));
// حالة التفعيل 0 أو 1
$admin_is_active = filterRequest("admin_is_active");

// إذا لم يُرسل المعرّف نوقف التنفيذ
if ($admin_id <= 0) {
    // رسالة توضح أن المعرّف مطلوب
    printFailure("admin_id is required");
    // إيقاف التنفيذ
    exit;
}

// جلب الصف الحالي
$stmt = $con->prepare("SELECT * FROM admin_users WHERE admin_id = ? LIMIT 1");
// تنفيذ الجلب
$stmt->execute([$admin_id]);
// قراءة الصف
$current = $stmt->fetch(PDO::FETCH_ASSOC);

// إن لم يوجد الموظف نرفض
if (!$current) {
    // رسالة واضحة
    printFailure("الموظف غير موجود", 404);
    // إيقاف التنفيذ
    exit;
}

// أنواع التعاقد المسموحة
$allowedTypes = ['full_time', 'part_time', 'contract'];

// مصفوفة التحديث
$updateData = [];

// الاسم إن أُرسل
if ($admin_full_name !== '') {
    // التحقق من الطول
    if (mb_strlen($admin_full_name) > 100) {
        // رسالة الحد
        printFailure("admin_full_name must not exceed 100 characters");
        // إيقاف التنفيذ
        exit;
    }
    // إضافة الاسم
    $updateData["admin_full_name"] = $admin_full_name;
}

// الهاتف إن أُرسل
if ($admin_phone !== '') {
    // التحقق من الصيغة
    if (!preg_match('/^[0-9]{10,15}$/', $admin_phone)) {
        // رسالة الصيغة
        printFailure("admin_phone must be 10 to 15 digits only");
        // إيقاف التنفيذ
        exit;
    }
    // منع التكرار
    $dup = $con->prepare("SELECT admin_id FROM admin_users WHERE admin_phone = ? AND admin_id <> ? LIMIT 1");
    // تنفيذ الفحص
    $dup->execute([$admin_phone, $admin_id]);
    // إن وُجد نرفض
    if ($dup->rowCount() > 0) {
        // رسالة التكرار
        printFailure("رقم الهاتف مستخدم بالفعل لموظف آخر", 409);
        // إيقاف التنفيذ
        exit;
    }
    // إضافة الهاتف
    $updateData["admin_phone"] = $admin_phone;
}

// كود الوظيفة إن أُرسل
if ($admin_job_code !== '') {
    // منع التكرار
    $dupCode = $con->prepare("SELECT admin_id FROM admin_users WHERE admin_job_code = ? AND admin_id <> ? LIMIT 1");
    // تنفيذ الفحص
    $dupCode->execute([$admin_job_code, $admin_id]);
    // إن وُجد نرفض
    if ($dupCode->rowCount() > 0) {
        // رسالة التكرار
        printFailure("كود الوظيفة مستخدم بالفعل لموظف آخر", 409);
        // إيقاف التنفيذ
        exit;
    }
    // إضافة الكود
    $updateData["admin_job_code"] = $admin_job_code;
}

// المسمى إن أُرسل
if ($admin_job_title !== '') {
    // إضافة المسمى
    $updateData["admin_job_title"] = $admin_job_title;
}

// الواتساب إن أُرسل
if ($admin_whatsapp !== '') {
    // التحقق من الصيغة
    if (!preg_match('/^[0-9]{10,15}$/', $admin_whatsapp)) {
        // رسالة الصيغة
        printFailure("admin_whatsapp must be 10 to 15 digits only");
        // إيقاف التنفيذ
        exit;
    }
    // إضافة الواتساب
    $updateData["admin_whatsapp"] = $admin_whatsapp;
}

// الراتب إن أُرسل
if ($admin_base_salary !== '') {
    // تحويل لرقم
    $baseSalary = (float)$admin_base_salary;
    // رفض السالب
    if ($baseSalary < 0) {
        // رسالة واضحة
        printFailure("admin_base_salary must be 0 or greater");
        // إيقاف التنفيذ
        exit;
    }
    // إضافة الراتب
    $updateData["admin_base_salary"] = $baseSalary;
}

// تاريخ التعيين إن أُرسل
if ($admin_hire_date !== '') {
    // التحقق من الصيغة
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $admin_hire_date)) {
        // رسالة الصيغة
        printFailure("admin_hire_date must be Y-m-d");
        // إيقاف التنفيذ
        exit;
    }
    // إضافة التاريخ
    $updateData["admin_hire_date"] = $admin_hire_date;
}

// نوع التعاقد إن أُرسل
if ($admin_employment_type !== '') {
    // رفض نوع غير معروف
    if (!in_array($admin_employment_type, $allowedTypes, true)) {
        // رسالة القيم
        printFailure("admin_employment_type must be: full_time, part_time or contract");
        // إيقاف التنفيذ
        exit;
    }
    // إضافة النوع
    $updateData["admin_employment_type"] = $admin_employment_type;
}

// الرقم القومي إن أُرسل
if ($admin_national_id !== '') {
    // إضافة الرقم
    $updateData["admin_national_id"] = $admin_national_id;
}

// الحساب البنكي إن أُرسل
if ($admin_bank_account !== '') {
    // إضافة الحساب
    $updateData["admin_bank_account"] = $admin_bank_account;
}

// الملاحظات إن أُرسلت
if ($admin_hr_notes !== '') {
    // إضافة الملاحظات
    $updateData["admin_hr_notes"] = $admin_hr_notes;
}

// حالة التفعيل إن أُرسلت
if ($admin_is_active !== '') {
    // قبول 0 أو 1 فقط
    if ($admin_is_active !== '0' && $admin_is_active !== '1') {
        // رسالة القيم
        printFailure("admin_is_active must be 0 or 1");
        // إيقاف التنفيذ
        exit;
    }
    // إضافة الحالة
    $updateData["admin_is_active"] = (int)$admin_is_active;
}

// إن لم يُرسل أي حقل نرفض
if (empty($updateData)) {
    // رسالة الحاجة لحقل
    printFailure("لا توجد بيانات للتحديث");
    // إيقاف التنفيذ
    exit;
}

// بناء جملة SET
$setParts = [];
// قيم الربط
$bind = [];
// المرور على الحقول
foreach ($updateData as $col => $val) {
    // إضافة عمود = ?
    $setParts[] = "$col = ?";
    // إضافة القيمة
    $bind[] = $val;
}
// إضافة المعرّف
$bind[] = $admin_id;

// تنفيذ التحديث
$upd = $con->prepare("UPDATE admin_users SET " . implode(', ', $setParts) . " WHERE admin_id = ?");
// تنفيذ الربط
$upd->execute($bind);

// تسجيل النشاط
logAdminActivity($currentAdmin, 'edit_employee', 'employee', $admin_id, array_keys($updateData));

// إرسال الرد
echo json_encode([
    "status"  => "success",
    "message" => "تم تعديل ملف الموظف",
    "data"    => array_merge(["admin_id" => $admin_id], $updateData),
], JSON_UNESCAPED_UNICODE);
