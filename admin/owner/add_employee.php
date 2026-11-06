<?php
// ============================================================
// ملف: admin/owner/add_employee.php
// الغرض: إضافة موظف تشغيلي مع رفع مستنداته
// الحقول الإجبارية: employee_full_name, employee_phone
// الطريقة: POST multipart — للمالك فقط
// ============================================================

// تمهيد قسم المالك
include "_owner_boot.php";

// الاسم الكامل
$fullName = trim(filterRequest("employee_full_name"));
// رقم الهاتف
$phone = trim(filterRequest("employee_phone"));
// واتساب اختياري
$whatsapp = trim(filterRequest("employee_whatsapp"));
// كود الوظيفة
$jobCode = trim(filterRequest("employee_job_code"));
// المسمى الوظيفي
$jobTitle = trim(filterRequest("employee_job_title"));
// الرقم القومي
$nationalId = trim(filterRequest("employee_national_id"));
// تاريخ التعيين
$hireDate = ownerDateOrEmpty(filterRequest("employee_hire_date"));
// نوع التعاقد
$employmentType = trim(filterRequest("employee_employment_type"));
// الراتب الأساسي
$baseSalary = ownerMoney(filterRequest("employee_base_salary"));
// الحساب البنكي
$bankAccount = trim(filterRequest("employee_bank_account"));
// ملاحظات
$notes = trim(filterRequest("employee_notes"));

// الحقول الإجبارية
if ($fullName === '' || $phone === '') {
    printFailure("الحقول المطلوبة: الاسم ورقم الهاتف");
    exit;
}

// طول الاسم
if (mb_strlen($fullName) > 100) {
    printFailure("الاسم يجب ألا يتجاوز 100 حرف");
    exit;
}

// صيغة الهاتف
if (!preg_match('/^[0-9]{10,15}$/', $phone)) {
    printFailure("رقم الهاتف يجب أن يكون من 10 إلى 15 رقماً");
    exit;
}

// نوع التعاقد المسموح
$allowedTypes = ['full_time', 'part_time', 'contract'];
if ($employmentType === '' || !in_array($employmentType, $allowedTypes, true)) {
    $employmentType = 'full_time';
}

// منع تكرار الهاتف
$dup = $con->prepare("SELECT employee_id FROM employees WHERE employee_phone = ? LIMIT 1");
$dup->execute([$phone]);
if ($dup->rowCount() > 0) {
    printFailure("رقم الهاتف مستخدم لموظف آخر", 409);
    exit;
}

// منع تكرار كود الوظيفة إن وُجد
if ($jobCode !== '') {
    $dupCode = $con->prepare("SELECT employee_id FROM employees WHERE employee_job_code = ? LIMIT 1");
    $dupCode->execute([$jobCode]);
    if ($dupCode->rowCount() > 0) {
        printFailure("كود الوظيفة مستخدم لموظف آخر", 409);
        exit;
    }
}

// مجلد رفع مستندات الموظفين
$uploadDir = __DIR__ . "/../../upload/employees_criminal_records/";

// رفع البطاقة الأمامية
$idFront = ownerUploadFile($uploadDir, "employee_id_card_front");
if ($idFront === "extension_error" || $idFront === "size_error" || $idFront === "fail") {
    printFailure("فشل رفع صورة البطاقة الأمامية: $idFront");
    exit;
}
if ($idFront === "empty") {
    $idFront = null;
}

// رفع البطاقة الخلفية
$idBack = ownerUploadFile($uploadDir, "employee_id_card_back");
if ($idBack === "extension_error" || $idBack === "size_error" || $idBack === "fail") {
    if ($idFront) {
        deleteFile(rtrim($uploadDir, "/"), $idFront);
    }
    printFailure("فشل رفع صورة البطاقة الخلفية: $idBack");
    exit;
}
if ($idBack === "empty") {
    $idBack = null;
}

// رفع الفيش
$criminal = ownerUploadFile($uploadDir, "employee_criminal_record");
if ($criminal === "extension_error" || $criminal === "size_error" || $criminal === "fail") {
    $criminal = null;
}
if ($criminal === "empty") {
    $criminal = null;
}

// رفع عقد العمل
$contract = ownerUploadFile($uploadDir, "employee_contract_pdf");
if ($contract === "extension_error" || $contract === "size_error" || $contract === "fail") {
    $contract = null;
}
if ($contract === "empty") {
    $contract = null;
}

// بيانات الإدخال
$insertData = [
    "employee_full_name" => $fullName,
    "employee_phone" => $phone,
    "employee_whatsapp" => $whatsapp !== '' ? $whatsapp : null,
    "employee_job_code" => $jobCode !== '' ? $jobCode : null,
    "employee_job_title" => $jobTitle !== '' ? $jobTitle : null,
    "employee_national_id" => $nationalId !== '' ? $nationalId : null,
    "employee_hire_date" => $hireDate !== '' ? $hireDate : null,
    "employee_employment_type" => $employmentType,
    "employee_base_salary" => $baseSalary,
    "employee_bank_account" => $bankAccount !== '' ? $bankAccount : null,
    "employee_notes" => $notes !== '' ? $notes : null,
    "employee_id_card_front" => $idFront,
    "employee_id_card_back" => $idBack,
    "employee_criminal_record" => $criminal,
    "employee_contract_pdf" => $contract,
    "employee_is_active" => 1,
    "employee_created_by" => $ownerId,
];

$result = insertData("employees", $insertData, false);

if ($result > 0) {
    echo json_encode([
        "status" => "success",
        "message" => "تم إضافة الموظف بنجاح",
        "data" => [
            "employee_id" => (int) $con->lastInsertId(),
            "employee_full_name" => $fullName,
            "employee_phone" => $phone,
        ],
    ]);
    exit;
}

printFailure("تعذّر إضافة الموظف");
