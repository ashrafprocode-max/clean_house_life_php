<?php
// ============================================================
// ملف: admin/accounting/add_expense.php
// الغرض: إضافة مصروف شركة جديد
//
// الحقول الإجبارية: expense_title, expense_amount, expense_date
// الاختيارية: expense_category, expense_status, expense_notes
// الصلاحية: expenses.create
// الطريقة: POST
// ============================================================

// ربط ملف الاتصال بقاعدة البيانات
include "../../connect.php";

// التحقق من التوكن وجلب بيانات الموظف مع صلاحياته
$currentAdmin = checkAdminToken();

// فرض صلاحية إضافة مصروف
requirePermission($currentAdmin, 'expenses.create');

// عنوان المصروف
$expense_title = trim(filterRequest("expense_title"));
// المبلغ
$expense_amount = filterRequest("expense_amount");
// تاريخ المصروف
$expense_date = trim(filterRequest("expense_date"));
// التصنيف
$expense_category = trim(filterRequest("expense_category"));
// الحالة
$expense_status = trim(filterRequest("expense_status"));
// الملاحظات
$expense_notes = trim(filterRequest("expense_notes"));

// التصنيفات المسموحة (بدون salary — الرواتب تُرحَّل تلقائياً)
$allowedCategories = [
    'rent', 'utilities', 'marketing',
    'supplies', 'transport', 'maintenance', 'other',
];
// الحالات المسموحة
$allowedStatuses = ['pending', 'paid', 'cancelled'];

// التحقق من العنوان
if ($expense_title === '') {
    // رسالة أن العنوان مطلوب
    printFailure("expense_title is required");
    // إيقاف التنفيذ
    exit;
}

// التحقق من طول العنوان
if (mb_strlen($expense_title) > 150) {
    // رسالة الحد
    printFailure("expense_title must not exceed 150 characters");
    // إيقاف التنفيذ
    exit;
}

// المبلغ رقم أكبر من صفر
$amount = (float)$expense_amount;
// رفض غير الموجب
if ($amount <= 0) {
    // رسالة التوضيح
    printFailure("expense_amount must be greater than 0");
    // إيقاف التنفيذ
    exit;
}

// التحقق من التاريخ
if ($expense_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expense_date)) {
    // رسالة الصيغة
    printFailure("expense_date must be Y-m-d");
    // إيقاف التنفيذ
    exit;
}

// التصنيف الافتراضي
if ($expense_category === '') {
    // أخرى إن لم يُرسل
    $expense_category = 'other';
}
// رفض تصنيف غير مسموح (خصوصاً salary)
if (!in_array($expense_category, $allowedCategories, true)) {
    // رسالة القيم
    printFailure("expense_category must be: rent, utilities, marketing, supplies, transport, maintenance or other");
    // إيقاف التنفيذ
    exit;
}

// الحالة الافتراضية مدفوع
if ($expense_status === '') {
    // مدفوع إن لم يُرسل
    $expense_status = 'paid';
}
// رفض حالة غير معروفة
if (!in_array($expense_status, $allowedStatuses, true)) {
    // رسالة القيم
    printFailure("expense_status must be: pending, paid or cancelled");
    // إيقاف التنفيذ
    exit;
}

// مصفوفة الإدخال
$insertData = [
    "expense_category"   => $expense_category,
    "expense_title"      => $expense_title,
    "expense_amount"     => $amount,
    "expense_date"       => $expense_date,
    "expense_status"     => $expense_status,
    "expense_notes"      => $expense_notes !== '' ? $expense_notes : null,
    "expense_created_by" => (int)$currentAdmin['admin_id'],
];

// تنفيذ الإدخال بلا طباعة JSON مزدوجة
$result = insertData("company_expenses", $insertData, false);

// التحقق من النجاح
if ($result <= 0) {
    // رسالة فشل
    printFailure("فشل إضافة المصروف، يرجى المحاولة مرة أخرى");
    // إيقاف التنفيذ
    exit;
}

// المعرّف الجديد
$newId = (int)$con->lastInsertId();

// تسجيل النشاط
logAdminActivity($currentAdmin, 'create_expense', 'expense', $newId, [
    "title"  => $expense_title,
    "amount" => $amount,
]);

// إرسال الرد
echo json_encode([
    "status"  => "success",
    "message" => "تم إضافة المصروف بنجاح",
    "data"    => [
        "expense_id"       => $newId,
        "expense_category" => $expense_category,
        "expense_title"    => $expense_title,
        "expense_amount"   => $amount,
        "expense_date"     => $expense_date,
        "expense_status"   => $expense_status,
        "expense_notes"    => $expense_notes !== '' ? $expense_notes : null,
    ],
], JSON_UNESCAPED_UNICODE);
