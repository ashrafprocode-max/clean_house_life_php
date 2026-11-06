<?php
// ============================================================
// ملف: admin/accounting/edit_expense.php
// الغرض: تعديل مصروف شركة (تحديث جزئي)
//
// ⚠️ مصروف الراتب المرحَّل تلقائياً لا يُعدَّل يدوياً
// الحقل الإجباري: expense_id
// الصلاحية: expenses.edit
// الطريقة: POST
// ============================================================

// ربط ملف الاتصال بقاعدة البيانات
include "../../connect.php";

// التحقق من التوكن وجلب بيانات الموظف مع صلاحياته
$currentAdmin = checkAdminToken();

// فرض صلاحية تعديل مصروف
requirePermission($currentAdmin, 'expenses.edit');

// معرّف المصروف
$expense_id = (int)filterRequest("expense_id");
// العنوان الجديد
$expense_title = trim(filterRequest("expense_title"));
// المبلغ الجديد
$expense_amount = filterRequest("expense_amount");
// التاريخ الجديد
$expense_date = trim(filterRequest("expense_date"));
// التصنيف الجديد
$expense_category = trim(filterRequest("expense_category"));
// الحالة الجديدة
$expense_status = trim(filterRequest("expense_status"));
// الملاحظات الجديدة
$expense_notes = trim(filterRequest("expense_notes"));

// إذا لم يُرسل المعرّف نوقف التنفيذ
if ($expense_id <= 0) {
    // رسالة أن المعرّف مطلوب
    printFailure("expense_id is required");
    // إيقاف التنفيذ
    exit;
}

// جلب الصف الحالي
$stmt = $con->prepare("SELECT * FROM company_expenses WHERE expense_id = ? LIMIT 1");
// تنفيذ الجلب
$stmt->execute([$expense_id]);
// قراءة الصف
$current = $stmt->fetch(PDO::FETCH_ASSOC);

// إن لم يوجد المصروف نرفض
if (!$current) {
    // رسالة واضحة
    printFailure("المصروف غير موجود", 404);
    // إيقاف التنفيذ
    exit;
}

// منع تعديل مصروف مرتبط براتب
if ($current['expense_salary_id'] !== null) {
    // رسالة التوضيح
    printFailure("لا يمكن تعديل مصروف مُرحَّل من راتب — عدّل الراتب من قسم الموارد البشرية", 409);
    // إيقاف التنفيذ
    exit;
}

// التصنيفات المسموحة يدوياً
$allowedCategories = [
    'rent', 'utilities', 'marketing',
    'supplies', 'transport', 'maintenance', 'other',
];
// الحالات المسموحة
$allowedStatuses = ['pending', 'paid', 'cancelled'];

// مصفوفة التحديث
$updateData = [];

// العنوان إن أُرسل
if ($expense_title !== '') {
    // التحقق من الطول
    if (mb_strlen($expense_title) > 150) {
        // رسالة الحد
        printFailure("expense_title must not exceed 150 characters");
        // إيقاف التنفيذ
        exit;
    }
    // إضافة العنوان
    $updateData["expense_title"] = $expense_title;
}

// المبلغ إن أُرسل
if ($expense_amount !== '') {
    // تحويل لرقم
    $amount = (float)$expense_amount;
    // رفض غير الموجب
    if ($amount <= 0) {
        // رسالة التوضيح
        printFailure("expense_amount must be greater than 0");
        // إيقاف التنفيذ
        exit;
    }
    // إضافة المبلغ
    $updateData["expense_amount"] = $amount;
}

// التاريخ إن أُرسل
if ($expense_date !== '') {
    // التحقق من الصيغة
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expense_date)) {
        // رسالة الصيغة
        printFailure("expense_date must be Y-m-d");
        // إيقاف التنفيذ
        exit;
    }
    // إضافة التاريخ
    $updateData["expense_date"] = $expense_date;
}

// التصنيف إن أُرسل
if ($expense_category !== '') {
    // رفض تصنيف غير مسموح
    if (!in_array($expense_category, $allowedCategories, true)) {
        // رسالة القيم
        printFailure("expense_category must be: rent, utilities, marketing, supplies, transport, maintenance or other");
        // إيقاف التنفيذ
        exit;
    }
    // إضافة التصنيف
    $updateData["expense_category"] = $expense_category;
}

// الحالة إن أُرسلت
if ($expense_status !== '') {
    // رفض حالة غير معروفة
    if (!in_array($expense_status, $allowedStatuses, true)) {
        // رسالة القيم
        printFailure("expense_status must be: pending, paid or cancelled");
        // إيقاف التنفيذ
        exit;
    }
    // إضافة الحالة
    $updateData["expense_status"] = $expense_status;
}

// الملاحظات إن أُرسلت
if ($expense_notes !== '') {
    // إضافة الملاحظات
    $updateData["expense_notes"] = $expense_notes;
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
$bind[] = $expense_id;

// تنفيذ التحديث
$upd = $con->prepare("UPDATE company_expenses SET " . implode(', ', $setParts) . " WHERE expense_id = ?");
// تنفيذ الربط
$upd->execute($bind);

// تسجيل النشاط
logAdminActivity($currentAdmin, 'edit_expense', 'expense', $expense_id, array_keys($updateData));

// إرسال الرد
echo json_encode([
    "status"  => "success",
    "message" => "تم تعديل المصروف",
    "data"    => array_merge(["expense_id" => $expense_id], $updateData),
], JSON_UNESCAPED_UNICODE);
