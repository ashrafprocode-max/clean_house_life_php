<?php
// ============================================================
// ملف: admin/hr/add_employee.php
// الغرض: إضافة / استكمال ملف موظف في الموارد البشرية
//
// ⭐ إن وُجد admin_id نحدّث ملف موظف قائم (بدون كلمة مرور)
//    وإلا نُنشئ حساباً جديداً بكلمة مرور مؤقتة
//
// الحقول الإجبارية عند الإنشاء:
//   admin_full_name, admin_phone, admin_password, admin_job_code, admin_job_title
// الصلاحية: employees.create
// الطريقة: POST
// ============================================================

// ربط ملف الاتصال بقاعدة البيانات
include "../../connect.php";

// التحقق من التوكن وجلب بيانات الموظف مع صلاحياته
$currentAdmin = checkAdminToken();

// فرض صلاحية إضافة موظف
requirePermission($currentAdmin, 'employees.create');

// معرّف موظف قائم للتحديث الجزئي (اختياري)
$admin_id            = (int)filterRequest("admin_id");
// الاسم الكامل
$admin_full_name     = trim(filterRequest("admin_full_name"));
// رقم الهاتف
$admin_phone         = trim(filterRequest("admin_phone"));
// كلمة المرور (إجبارية عند الإنشاء فقط)
$admin_password      = trim(filterRequest("admin_password"));
// الدور النصي (اختياري — الافتراضي support)
$admin_role          = trim(filterRequest("admin_role"));
// كود الوظيفة
$admin_job_code      = trim(filterRequest("admin_job_code"));
// المسمى الوظيفي
$admin_job_title     = trim(filterRequest("admin_job_title"));
// رقم الواتساب (اختياري)
$admin_whatsapp      = trim(filterRequest("admin_whatsapp"));
// الراتب الأساسي
$admin_base_salary   = filterRequest("admin_base_salary");
// تاريخ التعيين
$admin_hire_date     = trim(filterRequest("admin_hire_date"));
// نوع التعاقد
$admin_employment_type = trim(filterRequest("admin_employment_type"));
// الرقم القومي
$admin_national_id   = trim(filterRequest("admin_national_id"));
// الحساب البنكي
$admin_bank_account  = trim(filterRequest("admin_bank_account"));
// ملاحظات الموارد البشرية
$admin_hr_notes      = trim(filterRequest("admin_hr_notes"));

// أنواع التعاقد المسموحة
$allowedTypes = ['full_time', 'part_time', 'contract'];
// الأدوار المسموحة عند الإنشاء (بدون owner)
$allowedRoles = ['manager', 'accountant', 'hr', 'support', 'seller'];

// ============================================================
// مسار التحديث إن وُجد admin_id
// ============================================================
if ($admin_id > 0) {
    // فرض صلاحية التعديل أيضاً عند التحديث عبر هذا الملف
    requirePermission($currentAdmin, 'employees.edit');
    // التحقق من وجود الموظف
    $stmtExists = $con->prepare("SELECT admin_id FROM admin_users WHERE admin_id = ? LIMIT 1");
    // تنفيذ الفحص
    $stmtExists->execute([$admin_id]);
    // إن لم يوجد نرفض
    if ($stmtExists->rowCount() === 0) {
        // رسالة واضحة
        printFailure("الموظف غير موجود", 404);
        // إيقاف التنفيذ
        exit;
    }
}

// ============================================================
// التحقق من الحقول الإجبارية عند الإنشاء
// ============================================================
if ($admin_id <= 0) {
    // عند الإنشاء نطلب الاسم والهاتف وكلمة المرور والكود والمسمى
    if ($admin_full_name === '' || $admin_phone === '' ||
        $admin_password === '' || $admin_job_code === '' || $admin_job_title === '') {
        // رسالة توضح الحقول المطلوبة
        printFailure("Required fields: admin_full_name, admin_phone, admin_password, admin_job_code, admin_job_title");
        // إيقاف التنفيذ
        exit;
    }
}

// التحقق من طول الاسم
if ($admin_full_name !== '' && mb_strlen($admin_full_name) > 100) {
    // رسالة توضح الحد الأقصى
    printFailure("admin_full_name must not exceed 100 characters");
    // إيقاف التنفيذ
    exit;
}

// التحقق من رقم الهاتف إن أُرسل
if ($admin_phone !== '' && !preg_match('/^[0-9]{10,15}$/', $admin_phone)) {
    // رسالة توضح الصيغة
    printFailure("admin_phone must be 10 to 15 digits only");
    // إيقاف التنفيذ
    exit;
}

// التحقق من الواتساب إن أُرسل
if ($admin_whatsapp !== '' && !preg_match('/^[0-9]{10,15}$/', $admin_whatsapp)) {
    // رسالة توضح الصيغة
    printFailure("admin_whatsapp must be 10 to 15 digits only");
    // إيقاف التنفيذ
    exit;
}

// التحقق من كود الوظيفة
if ($admin_job_code !== '' && mb_strlen($admin_job_code) > 30) {
    // رسالة توضح الحد
    printFailure("admin_job_code must not exceed 30 characters");
    // إيقاف التنفيذ
    exit;
}

// نوع التعاقد الافتراضي
if ($admin_employment_type === '') {
    // دوام كامل إن لم يُرسل
    $admin_employment_type = 'full_time';
}
// رفض نوع غير معروف
if (!in_array($admin_employment_type, $allowedTypes, true)) {
    // رسالة توضح القيم المسموحة
    printFailure("admin_employment_type must be: full_time, part_time or contract");
    // إيقاف التنفيذ
    exit;
}

// الدور الافتراضي عند الإنشاء
if ($admin_id <= 0 && $admin_role === '') {
    // خدمة عملاء إن لم يُحدَّد دور
    $admin_role = 'support';
}
// رفض دور غير مسموح عند الإنشاء
if ($admin_role !== '' && !in_array($admin_role, $allowedRoles, true)) {
    // رسالة توضح الأدوار
    printFailure("admin_role must be: manager, accountant, hr, support or seller");
    // إيقاف التنفيذ
    exit;
}

// الراتب رقم غير سالب
$baseSalary = $admin_base_salary === '' ? 0.0 : (float)$admin_base_salary;
// رفض الراتب السالب
if ($baseSalary < 0) {
    // رسالة واضحة
    printFailure("admin_base_salary must be 0 or greater");
    // إيقاف التنفيذ
    exit;
}

// تاريخ التعيين إن أُرسل يجب أن يكون Y-m-d
if ($admin_hire_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $admin_hire_date)) {
    // رسالة توضح الصيغة
    printFailure("admin_hire_date must be Y-m-d");
    // إيقاف التنفيذ
    exit;
}

// منع تكرار الهاتف
if ($admin_phone !== '') {
    // البحث عن هاتف مستخدم لموظف آخر
    $stmtDup = $con->prepare(
        "SELECT admin_id FROM admin_users WHERE admin_phone = ? AND admin_id <> ? LIMIT 1"
    );
    // تنفيذ الفحص
    $stmtDup->execute([$admin_phone, $admin_id]);
    // إن وُجد نرفض
    if ($stmtDup->rowCount() > 0) {
        // رسالة توضح التكرار
        printFailure("رقم الهاتف مستخدم بالفعل لموظف آخر", 409);
        // إيقاف التنفيذ
        exit;
    }
}

// منع تكرار كود الوظيفة
if ($admin_job_code !== '') {
    // البحث عن كود مستخدم لموظف آخر
    $stmtCode = $con->prepare(
        "SELECT admin_id FROM admin_users WHERE admin_job_code = ? AND admin_id <> ? LIMIT 1"
    );
    // تنفيذ الفحص
    $stmtCode->execute([$admin_job_code, $admin_id]);
    // إن وُجد نرفض
    if ($stmtCode->rowCount() > 0) {
        // رسالة توضح التكرار
        printFailure("كود الوظيفة مستخدم بالفعل لموظف آخر", 409);
        // إيقاف التنفيذ
        exit;
    }
}

// ============================================================
// مسار الإنشاء
// ============================================================
if ($admin_id <= 0) {
    // الحد الأدنى لكلمة المرور
    if (strlen($admin_password) < 8) {
        // رسالة توضح الحد
        printFailure("كلمة المرور يجب أن تكون 8 أحرف على الأقل");
        // إيقاف التنفيذ
        exit;
    }
    // يجب أن تحتوي حرفاً ورقماً
    if (!preg_match('/[A-Za-z]/', $admin_password) || !preg_match('/[0-9]/', $admin_password)) {
        // رسالة توضح الشرط
        printFailure("كلمة المرور يجب أن تحتوي حروفاً وأرقاماً");
        // إيقاف التنفيذ
        exit;
    }

    // تشفير كلمة المرور
    $hashedPassword = password_hash($admin_password, PASSWORD_DEFAULT);

    // مصفوفة الإدخال
    $insertData = [
        "admin_full_name"        => $admin_full_name,
        "admin_phone"            => $admin_phone,
        "admin_password"         => $hashedPassword,
        "admin_role"             => $admin_role,
        "admin_is_active"        => 1,
        "admin_created_by"       => (int)$currentAdmin['admin_id'],
        "admin_job_code"         => $admin_job_code,
        "admin_job_title"        => $admin_job_title,
        "admin_whatsapp"         => $admin_whatsapp !== '' ? $admin_whatsapp : $admin_phone,
        "admin_base_salary"      => $baseSalary,
        "admin_hire_date"        => $admin_hire_date !== '' ? $admin_hire_date : date('Y-m-d'),
        "admin_employment_type"  => $admin_employment_type,
        "admin_national_id"      => $admin_national_id !== '' ? $admin_national_id : null,
        "admin_bank_account"     => $admin_bank_account !== '' ? $admin_bank_account : null,
        "admin_hr_notes"         => $admin_hr_notes !== '' ? $admin_hr_notes : null,
    ];

    // ربط الدور بجدول admin_roles إن وُجد
    $stmtRole = $con->prepare("SELECT role_id FROM admin_roles WHERE role_key = ? LIMIT 1");
    // تنفيذ البحث
    $stmtRole->execute([$admin_role]);
    // قراءة المعرّف
    $roleId = $stmtRole->fetchColumn();
    // إن وُجد نضيفه
    if ($roleId) {
        // ربط الدور الجديد
        $insertData["admin_role_id"] = (int)$roleId;
    }

    // تنفيذ الإدخال بلا طباعة JSON مزدوجة
    $result = insertData("admin_users", $insertData, false);

    // التحقق من النجاح
    if ($result <= 0) {
        // رسالة فشل
        printFailure("فشل إضافة الموظف، يرجى المحاولة مرة أخرى");
        // إيقاف التنفيذ
        exit;
    }

    // المعرّف الجديد
    $newId = (int)$con->lastInsertId();

    // تسجيل النشاط
    logAdminActivity($currentAdmin, 'create_employee', 'employee', $newId, [
        "admin_full_name" => $admin_full_name,
        "admin_job_code"  => $admin_job_code,
    ]);

    // إرسال الرد
    echo json_encode([
        "status"  => "success",
        "message" => "تم إضافة الموظف بنجاح",
        "data"    => [
            "admin_id"              => $newId,
            "admin_full_name"       => $admin_full_name,
            "admin_phone"           => $admin_phone,
            "admin_job_code"        => $admin_job_code,
            "admin_job_title"       => $admin_job_title,
            "admin_role"            => $admin_role,
            "admin_base_salary"     => $baseSalary,
            "admin_employment_type" => $admin_employment_type,
        ],
    ], JSON_UNESCAPED_UNICODE);
    // إنهاء الملف
    exit;
}

// ============================================================
// مسار التحديث الجزئي لموظف قائم
// ============================================================
$updateData = [];
// الاسم إن أُرسل
if ($admin_full_name !== '') {
    $updateData["admin_full_name"] = $admin_full_name;
}
// الهاتف إن أُرسل
if ($admin_phone !== '') {
    $updateData["admin_phone"] = $admin_phone;
}
// كود الوظيفة إن أُرسل
if ($admin_job_code !== '') {
    $updateData["admin_job_code"] = $admin_job_code;
}
// المسمى إن أُرسل
if ($admin_job_title !== '') {
    $updateData["admin_job_title"] = $admin_job_title;
}
// الواتساب إن أُرسل
if ($admin_whatsapp !== '') {
    $updateData["admin_whatsapp"] = $admin_whatsapp;
}
// الراتب إن أُرسل
if ($admin_base_salary !== '') {
    $updateData["admin_base_salary"] = $baseSalary;
}
// تاريخ التعيين إن أُرسل
if ($admin_hire_date !== '') {
    $updateData["admin_hire_date"] = $admin_hire_date;
}
// نوع التعاقد دائماً مُصحَّح أعلاه
$updateData["admin_employment_type"] = $admin_employment_type;
// الرقم القومي
if ($admin_national_id !== '') {
    $updateData["admin_national_id"] = $admin_national_id;
}
// الحساب البنكي
if ($admin_bank_account !== '') {
    $updateData["admin_bank_account"] = $admin_bank_account;
}
// الملاحظات
if ($admin_hr_notes !== '') {
    $updateData["admin_hr_notes"] = $admin_hr_notes;
}

// إن لم يُرسل أي حقل نرفض
if (empty($updateData)) {
    // رسالة توضح الحاجة لحقل واحد على الأقل
    printFailure("لا توجد بيانات للتحديث");
    // إيقاف التنفيذ
    exit;
}

// بناء جملة SET بمعاملات مسمّاة
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
// إضافة المعرّف في النهاية
$bind[] = $admin_id;

// تنفيذ التحديث
$upd = $con->prepare("UPDATE admin_users SET " . implode(', ', $setParts) . " WHERE admin_id = ?");
// تنفيذ الربط
$upd->execute($bind);

// تسجيل النشاط
logAdminActivity($currentAdmin, 'update_employee', 'employee', $admin_id, array_keys($updateData));

// إرسال الرد
echo json_encode([
    "status"  => "success",
    "message" => "تم تحديث ملف الموظف",
    "data"    => array_merge(["admin_id" => $admin_id], $updateData),
], JSON_UNESCAPED_UNICODE);
