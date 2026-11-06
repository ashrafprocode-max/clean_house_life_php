<?php
// ============================================================
// ملف: admin/staff/edit_staff.php
// الغرض: تعديل بيانات موظف / تغيير دوره / تعطيله / إعادة كلمة مروره
//
// ⚠️ للمدير (manager) فقط
//
// ⭐ ثلاث حمايات مهمة مبنية في هذا الملف:
//   1. المدير لا يستطيع تعطيل حساب نفسه (وإلا فقد الوصول للنظام)
//   2. المدير لا يستطيع تخفيض دور نفسه (نفس السبب)
//   3. لا يمكن تعطيل آخر مدير مفعّل في النظام (وإلا لا أحد يدير)
//
// الحقل الإجباري: admin_id — وباقي الحقول اختيارية
// الطريقة: POST
// ============================================================

// ربط ملف الاتصال بقاعدة البيانات (مستويان للوراء لأن الملف داخل admin/staff)
include "../../connect.php";

// ⭐ التحقق من التوكن مع فرض دور المدير
$currentAdmin = checkAdminToken('manager');
// if ($currentAdmin['role_level'] <= $targetLevel);

// ============================================================
// الخطوة 1: استقبال البيانات
// ============================================================
// معرّف الموظف المراد تعديله - إجباري
$admin_id = filterRequest("admin_id");
// الاسم الكامل الجديد (اختياري)
$admin_full_name = trim(filterRequest("admin_full_name"));
// رقم الهاتف الجديد (اختياري)
$admin_phone = trim(filterRequest("admin_phone"));
// كلمة مرور جديدة (اختياري — لإعادة تعيينها إذا نسيها الموظف)
$admin_password = trim(filterRequest("admin_password")); // "filter...d");
// الدور الجديد (اختياري)
$admin_role = filterRequest("admin_role");
// حالة التفعيل الجديدة: 0 أو 1 (اختياري)
$admin_is_active = filterRequest("admin_is_active");

// ============================================================
// الخطوة 2: التحقق من الحقل الإجباري
// ============================================================
// إذا لم يُرسل معرّف الموظف نوقف التنفيذ فوراً
if (empty($admin_id)) {
    // رسالة توضح أن المعرّف مطلوب
    printFailure("admin_id is required");
    // إيقاف التنفيذ
    exit;
}

// تحويل المعرّف لرقم صحيح لحماية جملة WHERE من أي محتوى غير رقمي
$admin_id = (int) $admin_id;

// ============================================================
// الخطوة 3: التحقق من وجود الموظف وجلب بياناته الحالية
// ============================================================
$stmtCheck = $con->prepare(
    "SELECT admin_id, admin_full_name, admin_phone, admin_role, admin_is_active
     FROM admin_users
     WHERE admin_id = ?
     LIMIT 1"
);
// تنفيذ الاستعلام بتمرير معرّف الموظف
$stmtCheck->execute([$admin_id]);
// جلب صف الموظف الحالي
$target = $stmtCheck->fetch(PDO::FETCH_ASSOC);

// إذا لم يوجد الموظف نرفض التعديل
if (!$target) {
    // رسالة توضح أن الموظف غير موجود
    printFailure("الموظف غير موجود", 404);
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// ⭐ الخطوة 4: الحماية الأولى — المدير لا يُعطّل حساب نفسه
// لأنه سيفقد الوصول للنظام فوراً ولن يستطيع إعادة تفعيله
// ============================================================
// نتحقق أن الموظف المُعدَّل هو نفس المدير الحالي
$isEditingSelf = ((int) $currentAdmin['admin_id'] === $admin_id);

// نرفض تعطيل الحساب الحالي
if ($isEditingSelf && $admin_is_active !== '' && (int) $admin_is_active === 0) {
    // رسالة توضح سبب الرفض
    printFailure("لا يمكنك تعطيل حسابك الخاص", 403);
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// ⭐ الخطوة 5: الحماية الثانية — المدير لا يُخفّض دور نفسه
// لأنه سيفقد صلاحيات الإدارة فوراً
// ============================================================
// مستويات الأدوار من admin_roles.role_level
$roleLevels = [
    'owner' => 100,
    'manager' => 80,
    'accountant' => 60,
    'support' => 40,
    'seller' => 20,
];
$actorRole = $currentAdmin['admin_role'] ?? '';
$actorLevel = isset($currentAdmin['role_level'])
    ? (int) $currentAdmin['role_level']
    : ($roleLevels[$actorRole] ?? 0);
$currentLevel = $roleLevels[$target['admin_role']] ?? 0;

// ⚠️ المدير لا يعدّل حساب المالك إطلاقاً (ولا يرى كلمة مروره)
if ($actorRole !== 'owner' && ($target['admin_role'] ?? '') === 'owner') {
    printFailure("لا يمكنك تعديل حساب المالك", 403);
    exit;
}
$nextLevel = ($admin_role !== '' && isset($roleLevels[$admin_role]))
    ? $roleLevels[$admin_role]
    : $currentLevel;

// لا تعديل موظف بمستوى يساوي أو أعلى (عدا تعديل النفس)
if (!$isEditingSelf && $actorLevel <= $currentLevel) {
    printFailure("لا يمكنك تعديل موظف بمستوى يساوي أو أعلى من دورك", 403);
    exit;
}

// لا ترقية/تعيين دور بمستوى يساوي أو أعلى من المنفّذ
if ($admin_role !== '' && $actorLevel <= $nextLevel) {
    printFailure("لا يمكنك تعيين دور بمستوى يساوي أو أعلى من دورك", 403);
    exit;
}

// نرفض تخفيض دور الحساب الحالي لأي دور أقل مستوى
if ($isEditingSelf && $admin_role !== '' && $nextLevel < $currentLevel) {
    // رسالة توضح سبب الرفض
    printFailure("لا يمكنك تخفيض دور حسابك الخاص", 403);
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// ⭐ الخطوة 6: الحماية الثالثة — لا نُعطّل آخر مدير مفعّل
// وإلا يصبح النظام بلا أي مدير قادر على الإدارة
// ============================================================
// نتحقق فقط إذا كان الإجراء تعطيلاً أو تخفيض دور لمديرٍ حالي
$isDisablingManager = ($target['admin_role'] === 'manager')
    && (($admin_is_active !== '' && (int) $admin_is_active === 0)
        || ($admin_role !== '' && $admin_role !== 'manager' && $admin_role !== 'owner'));

// نُنفّذ الفحص فقط في هذه الحالة
if ($isDisablingManager) {
    // عدّ المديرين المفعّلين غير هذا الموظف
    $stmtManagers = $con->prepare(
        "SELECT COUNT(admin_id) AS c
         FROM admin_users
         WHERE admin_role = 'manager' AND admin_is_active = 1 AND admin_id <> ?"
    );
    // تنفيذ الاستعلام باستثناء الموظف الحالي
    $stmtManagers->execute([$admin_id]);
    // قراءة العدد
    $otherManagers = (int) $stmtManagers->fetchColumn();

    // إذا لم يبق أي مدير آخر نرفض العملية
    if ($otherManagers === 0) {
        // رسالة توضح سبب الرفض
        printFailure("لا يمكن تعطيل آخر مدير في النظام", 403);
        // إيقاف التنفيذ
        exit;
    }
}

// ============================================================
// الخطوة 7: بناء مصفوفة التحديث ديناميكياً
// نُضيف الحقل فقط إذا أرسله المدير فعلاً
// ============================================================
// تهيئة مصفوفة فارغة سنملأها بالحقول المُرسَلة
$updateData = [];

// ---------- الاسم الكامل ----------
if ($admin_full_name !== '') {
    // نتحقق من الطول قبل الإضافة
    if (mb_strlen($admin_full_name) > 100) {
        // رسالة توضح الحد الأقصى
        printFailure("admin_full_name must not exceed 100 characters");
        // إيقاف التنفيذ
        exit;
    }
    // إضافة الاسم للتحديث
    $updateData["admin_full_name"] = $admin_full_name;
}

// ---------- رقم الهاتف ----------
if ($admin_phone !== '') {
    // نتحقق من صيغة الرقم
    if (!preg_match('/^[0-9]{10,15}$/', $admin_phone)) {
        // رسالة توضح الصيغة المطلوبة
        printFailure("admin_phone must be 10 to 15 digits only");
        // إيقاف التنفيذ
        exit;
    }
    // ⚠️ نتحقق أن الرقم غير مستخدم لموظف آخر (العمود عليه UNIQUE)
    $stmtPhone = $con->prepare(
        "SELECT admin_id FROM admin_users WHERE admin_phone = ? AND admin_id <> ? LIMIT 1"
    );
    // تنفيذ الاستعلام بتمرير الرقم والمعرّف الحالي
    $stmtPhone->execute([$admin_phone, $admin_id]);
    // إذا وُجد موظف آخر بنفس الرقم نرفض التعديل
    if ($stmtPhone->rowCount() > 0) {
        // رسالة توضح أن الرقم مستخدم
        printFailure("رقم الهاتف مستخدم بالفعل لموظف آخر", 409);
        // إيقاف التنفيذ
        exit;
    }
    // إضافة الرقم للتحديث
    $updateData["admin_phone"] = $admin_phone;
}

// ---------- كلمة المرور (إعادة تعيين) ----------
if ($admin_password !== '') {
    // نتحقق من الحد الأدنى للطول
    if (strlen($admin_password) < 8) {
        // رسالة توضح الحد الأدنى
        printFailure("كلمة المرور يجب أن تكون 8 أحرف على الأقل");
        // إيقاف التنفيذ
        exit;
    }
    // نتحقق أنها تحتوي حروفاً وأرقاماً
    if (
        !preg_match('/[A-Za-z]/', $admin_password) ||
        !preg_match('/[0-9]/', $admin_password)
    ) {
        // رسالة توضح الشرط
        printFailure("كلمة المرور يجب أن تحتوي حروفاً وأرقاماً");
        // إيقاف التنفيذ
        exit;
    }
    // ⭐ تشفير كلمة المرور الجديدة قبل حفظها
    // ⚠️ هذا يُبطل توكن الموظف تلقائياً لأن الهاش جزء من معادلة التوكن
    //    فيُجبَر على تسجيل الدخول مرة أخرى بكلمة المرور الجديدة
    $updateData["admin_password"] = password_hash($admin_password, PASSWORD_DEFAULT);
}

// ---------- الدور ----------
if ($admin_role !== '') {
    // قائمة الأدوار المسموحة (الخمسة من RBAC)
    $allowedRoles = ['owner', 'manager', 'accountant', 'support', 'seller'];
    // نتحقق أن الدور معروف
    if (!in_array($admin_role, $allowedRoles, true)) {
        // رسالة توضح الأدوار المسموحة
        printFailure("admin_role must be: owner, manager, accountant, support or seller");
        // إيقاف التنفيذ
        exit;
    }
    // إضافة الدور للتحديث
    $updateData["admin_role"] = $admin_role;
    // مزامنة admin_role_id مع جدول admin_roles إن وُجد
    $stmtRole = $con->prepare(
        "SELECT role_id FROM admin_roles WHERE role_key = ? LIMIT 1"
    );
    $stmtRole->execute([$admin_role]);
    $roleId = $stmtRole->fetchColumn();
    if ($roleId) {
        $updateData["admin_role_id"] = (int) $roleId;
    }
}

// ---------- حالة التفعيل ----------
// نقبل القيمة صفر لذلك نقارن بالنص الفارغ وليس empty
if ($admin_is_active !== '') {
    // تحويل القيمة لرقم صحيح (0 أو 1 فقط)
    $updateData["admin_is_active"] = ((int) $admin_is_active === 1) ? 1 : 0;
}

// ============================================================
// الخطوة 8: التأكد من وجود حقل واحد على الأقل للتحديث
// ============================================================
// إذا كانت المصفوفة فارغة فلا يوجد ما نُحدّثه
if (empty($updateData)) {
    // رسالة توضح عدم إرسال أي حقل
    printFailure("No fields to update");
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 9: تنفيذ التحديث باستخدام دالتك الموحدة
// ============================================================
// استدعاء دالة التحديث مع false لمنع طباعة JSON مرتين
$result = updateData(
    "admin_users",
    $updateData,
    "admin_id = $admin_id",
    false
);

// ============================================================
// الخطوة 10: جلب البيانات بعد التعديل
// ============================================================
$stmtAfter = $con->prepare(
    "SELECT admin_id, admin_full_name, admin_phone,
            admin_role, admin_is_active, admin_last_login
     FROM admin_users
     WHERE admin_id = ?
     LIMIT 1"
);
// تنفيذ الاستعلام بتمرير معرّف الموظف
$stmtAfter->execute([$admin_id]);
// جلب الصف المحدَّث
$updated = $stmtAfter->fetch(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 11: إرسال الرد النهائي
// ============================================================
// التحقق من نجاح التحديث
if ($result > 0) {
    // إرسال JSON يحتوي البيانات بعد التعديل
    echo json_encode([
        // حالة العملية
        "status" => "success",
        // رسالة نجاح بالعربية
        "message" => "تم تحديث بيانات الموظف بنجاح",
        // بيانات النتيجة
        "data" => [
            // معرّف الموظف
            "admin_id" => (int) $updated['admin_id'],
            // الاسم بعد التعديل
            "admin_full_name" => $updated['admin_full_name'],
            // الهاتف بعد التعديل
            "admin_phone" => $updated['admin_phone'],
            // الدور بعد التعديل
            "admin_role" => $updated['admin_role'],
            // ترجمة الدور للعربية من الخريطة الموحدة
            "admin_role_ar" => adminRoleAr($updated['admin_role']),
            // حالة التفعيل بعد التعديل
            "admin_is_active" => (int) $updated['admin_is_active'],
            // ⭐ هل تغيّرت كلمة المرور؟ (اللوحة تُنبّه المدير أن يُسلّمها للموظف)
            "password_changed" => isset($updateData['admin_password']),
            // أسماء الحقول التي تم تعديلها فعلاً
            "updated_fields" => array_keys($updateData),
            // من نفّذ التعديل (للسجل)
            "updated_by" => $currentAdmin['admin_full_name'],
        ],
    ]);
} else {
    // إذا لم يتغيّر أي صف فالقيم المُرسَلة مطابقة للقيم الحالية
    printFailure("لم يتم تغيير أي بيانات (القيم مطابقة للحالية)");
}
?>