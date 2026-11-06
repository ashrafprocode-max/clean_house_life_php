<?php
// ============================================================
// ملف: admin/notifications/user_details.php
// الغرض: تفاصيل صف users عند الضغط على إشعار تسجيل حساب
// الصلاحية: notifications.view
// ============================================================

// ربط الاتصال بقاعدة البيانات والدوال العامة
include "../../connect.php";

// التحقق من توكن الموظف وجلب بياناته
$currentAdmin = checkAdminToken();
// فرض صلاحية عرض الإشعارات
requirePermission($currentAdmin, 'notifications.view');

// معرّف المستخدم المطلوب
$usersId = (int) filterRequest("users_id");
// إن لم يُرسل users_id نستخدم ref_id من الإشعار
if ($usersId <= 0) {
    // قراءة معرّف المرجع
    $usersId = (int) filterRequest("ref_id");
}
// رفض الطلب بلا معرّف صالح
if ($usersId <= 0) {
    // رسالة توضح أن المعرّف مطلوب
    printFailure("users_id مطلوب");
}

// جلب المستخدم مع الشركة والملف المهني وملف العميل إن وُجدوا
$stmt = $con->prepare(
    "SELECT
        u.users_id, u.users_full_name, u.users_phone, u.users_type,
        u.users_is_active, u.users_approve, u.users_country, u.users_region,
        u.users_created_at, u.users_created_by_admin,
        a.admin_full_name AS created_by_admin_name,
        c.company_id, c.company_name, c.company_business_type,
        pp.providers_id, pp.providers_is_verified,
        cp.clients_id
     FROM users u
     LEFT JOIN admin_users a ON a.admin_id = u.users_created_by_admin
     LEFT JOIN company_registering c ON c.company_user_id = u.users_id
     LEFT JOIN professional_profiles pp ON pp.providers_users_id = u.users_id
     LEFT JOIN client_profiles cp ON cp.clients_users_id = u.users_id
     WHERE u.users_id = ?
     LIMIT 1"
);
// تنفيذ الاستعلام بمعرّف المستخدم
$stmt->execute([$usersId]);
// جلب الصف
$row = $stmt->fetch(PDO::FETCH_ASSOC);

// رفض الطلب إن لم يوجد المستخدم
if (!$row) {
    // رسالة 404
    printFailure("المستخدم غير موجود", 404);
}

// هل لهذا المستخدم شركة؟
$hasCompany = !empty($row['company_id']);
// تحديد دور العرض
$role = $row['users_type'] === 'provider'
    ? 'provider'
    : ($hasCompany ? 'company' : 'client');

// إرسال JSON بتفاصيل المستخدم ومسار التوجيه
echo json_encode([
    "status" => "success",
    "message" => "تفاصيل المستخدم",
    "data" => [
        "users_id" => (int) $row['users_id'],
        "users_full_name" => $row['users_full_name'],
        "users_phone" => $row['users_phone'],
        "users_type" => $row['users_type'],
        "audience_role" => $role,
        "audience_role_ar" => $role === 'provider' ? 'مقدم خدمة' : ($role === 'company' ? 'صاحب شركة' : 'عميل'),
        "users_is_active" => (int) $row['users_is_active'],
        "users_approve" => (int) $row['users_approve'],
        "users_country" => $row['users_country'],
        "users_region" => $row['users_region'],
        "users_created_at" => $row['users_created_at'],
        "created_by_admin" => $row['created_by_admin_name'],
        "company_id" => $row['company_id'] !== null ? (int) $row['company_id'] : null,
        "company_name" => $row['company_name'],
        "company_business_type" => $row['company_business_type'],
        "providers_id" => $row['providers_id'] !== null ? (int) $row['providers_id'] : null,
        "providers_is_verified" => $row['providers_is_verified'] !== null ? (int) $row['providers_is_verified'] : null,
        "clients_id" => $row['clients_id'] !== null ? (int) $row['clients_id'] : null,
        "route_name" => $role === 'provider'
            ? 'reviewProfessionalProfile'
            : ($role === 'company' ? 'editCompany' : 'userDetails'),
        "ref_id" => $role === 'company' && $row['company_id']
            ? (int) $row['company_id']
            : (int) $row['users_id'],
    ],
]);
