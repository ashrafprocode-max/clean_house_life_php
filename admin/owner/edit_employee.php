<?php
// ============================================================
// ملف: admin/owner/edit_employee.php
// الغرض: تعديل ملف موظف تشغيلي ومستنداته
// الطريقة: POST multipart — للمالك فقط
// ============================================================

include "_owner_boot.php";

$employeeId = (int) filterRequest("employee_id");
if ($employeeId <= 0) {
    printFailure("معرّف الموظف مطلوب");
    exit;
}

$stmt = $con->prepare("SELECT * FROM employees WHERE employee_id = ? LIMIT 1");
$stmt->execute([$employeeId]);
$emp = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$emp) {
    printFailure("الموظف غير موجود", 404);
    exit;
}

$fullName = trim(filterRequest("employee_full_name"));
$phone = trim(filterRequest("employee_phone"));
$whatsapp = trim(filterRequest("employee_whatsapp"));
$jobCode = trim(filterRequest("employee_job_code"));
$jobTitle = trim(filterRequest("employee_job_title"));
$nationalId = trim(filterRequest("employee_national_id"));
$hireDate = ownerDateOrEmpty(filterRequest("employee_hire_date"));
$employmentType = trim(filterRequest("employee_employment_type"));
$baseSalaryRaw = trim(filterRequest("employee_base_salary"));
$bankAccount = trim(filterRequest("employee_bank_account"));
$notes = trim(filterRequest("employee_notes"));
$isActiveRaw = trim(filterRequest("employee_is_active"));

$update = [];

if ($fullName !== '') {
    if (mb_strlen($fullName) > 100) {
        printFailure("الاسم يجب ألا يتجاوز 100 حرف");
        exit;
    }
    $update["employee_full_name"] = $fullName;
}

if ($phone !== '') {
    if (!preg_match('/^[0-9]{10,15}$/', $phone)) {
        printFailure("رقم الهاتف يجب أن يكون من 10 إلى 15 رقماً");
        exit;
    }
    $dup = $con->prepare(
        "SELECT employee_id FROM employees WHERE employee_phone = ? AND employee_id <> ? LIMIT 1"
    );
    $dup->execute([$phone, $employeeId]);
    if ($dup->rowCount() > 0) {
        printFailure("رقم الهاتف مستخدم لموظف آخر", 409);
        exit;
    }
    $update["employee_phone"] = $phone;
}

if (filterRequest("employee_whatsapp") !== '' || isset($_POST['employee_whatsapp'])) {
    $update["employee_whatsapp"] = $whatsapp !== '' ? $whatsapp : null;
}

if ($jobCode !== '') {
    $dupCode = $con->prepare(
        "SELECT employee_id FROM employees WHERE employee_job_code = ? AND employee_id <> ? LIMIT 1"
    );
    $dupCode->execute([$jobCode, $employeeId]);
    if ($dupCode->rowCount() > 0) {
        printFailure("كود الوظيفة مستخدم لموظف آخر", 409);
        exit;
    }
    $update["employee_job_code"] = $jobCode;
}

if ($jobTitle !== '') {
    $update["employee_job_title"] = $jobTitle;
}

if ($nationalId !== '') {
    $update["employee_national_id"] = $nationalId;
}

if ($hireDate !== '') {
    $update["employee_hire_date"] = $hireDate;
}

$allowedTypes = ['full_time', 'part_time', 'contract'];
if ($employmentType !== '' && in_array($employmentType, $allowedTypes, true)) {
    $update["employee_employment_type"] = $employmentType;
}

if ($baseSalaryRaw !== '') {
    $update["employee_base_salary"] = ownerMoney($baseSalaryRaw);
}

if ($bankAccount !== '') {
    $update["employee_bank_account"] = $bankAccount;
}

if (isset($_POST['employee_notes']) || filterRequest("employee_notes") !== '') {
    $update["employee_notes"] = $notes !== '' ? $notes : null;
}

if ($isActiveRaw !== '') {
    $update["employee_is_active"] = ((int) $isActiveRaw === 1) ? 1 : 0;
}

$uploadDir = __DIR__ . "/../../upload/employees_criminal_records/";

$idFront = ownerUploadFile($uploadDir, "employee_id_card_front");
if ($idFront !== "empty" && $idFront !== "extension_error" && $idFront !== "size_error" && $idFront !== "fail") {
    if (!empty($emp['employee_id_card_front'])) {
        deleteFile(rtrim($uploadDir, "/"), $emp['employee_id_card_front']);
    }
    $update["employee_id_card_front"] = $idFront;
}

$idBack = ownerUploadFile($uploadDir, "employee_id_card_back");
if ($idBack !== "empty" && $idBack !== "extension_error" && $idBack !== "size_error" && $idBack !== "fail") {
    if (!empty($emp['employee_id_card_back'])) {
        deleteFile(rtrim($uploadDir, "/"), $emp['employee_id_card_back']);
    }
    $update["employee_id_card_back"] = $idBack;
}

$criminal = ownerUploadFile($uploadDir, "employee_criminal_record");
if ($criminal !== "empty" && $criminal !== "extension_error" && $criminal !== "size_error" && $criminal !== "fail") {
    if (!empty($emp['employee_criminal_record'])) {
        deleteFile(rtrim($uploadDir, "/"), $emp['employee_criminal_record']);
    }
    $update["employee_criminal_record"] = $criminal;
}

$contract = ownerUploadFile($uploadDir, "employee_contract_pdf");
if ($contract !== "empty" && $contract !== "extension_error" && $contract !== "size_error" && $contract !== "fail") {
    if (!empty($emp['employee_contract_pdf'])) {
        deleteFile(rtrim($uploadDir, "/"), $emp['employee_contract_pdf']);
    }
    $update["employee_contract_pdf"] = $contract;
}

if (empty($update)) {
    printFailure("لا توجد بيانات للتعديل");
    exit;
}

$result = updateData("employees", $update, "employee_id = $employeeId", false);
if ($result >= 0) {
    echo json_encode([
        "status" => "success",
        "message" => "تم تعديل ملف الموظف",
    ]);
    exit;
}

printFailure("تعذّر تعديل الموظف");
