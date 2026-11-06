<?php
// ============================================================
// ملف: admin/staff/list_staff.php
// الغرض: عرض قائمة موظفي الإدارة مع أدوارهم وحالتهم
//
// ⚠️ للمدير (manager) فقط — موظف الدعم لا يرى قائمة الموظفين
//    حتى لا يعرف أرقام هواتف زملائه فيحاول تخمين كلمات مرورهم
//
// الطريقة: POST أو GET
// ============================================================

// ربط ملف الاتصال بقاعدة البيانات (مستويان للوراء لأن الملف داخل admin/staff)
include "../../connect.php";

// ⭐ التحقق من التوكن مع فرض دور المدير (المالك يمرّ أيضاً)
$currentAdmin = checkAdminToken('manager');
// هل الداخل مالك؟ المالك يرى نفسه وكل الموظفين
$isOwnerViewer = (($currentAdmin['admin_role'] ?? '') === 'owner');

// ============================================================
// الخطوة 1: جلب الموظفين
// ⚠️ المدير لا يرى حساب المالك — المالك يرى نفسه والجميع
// ⚠️ لا نجلب عمود admin_password إطلاقاً حتى لا يُرسَل بالخطأ
// ============================================================
$stmt = $con->prepare(
    "SELECT
        au.admin_id,
        au.admin_full_name,
        au.admin_phone,
        au.admin_role,
        au.admin_is_active,
        au.admin_last_login,
        au.admin_created_at,

        -- عدد الطلبات التي راجعها هذا الموظف (مؤشر أداء)
        (SELECT COUNT(pp.providers_id)
         FROM professional_profiles pp
         WHERE pp.providers_reviewed_by = au.admin_id) AS reviews_count

     FROM admin_users au
     WHERE (? = 1 OR au.admin_role <> 'owner')
     ORDER BY
        -- المديرون أولاً
        CASE WHEN au.admin_role = 'manager' THEN 0 ELSE 1 END ASC,
        -- ثم الأقدم إنشاءً
        au.admin_created_at ASC"
);
// تنفيذ الاستعلام: 1 = المالك يرى الجميع، 0 = إخفاء المالك عن المدير
$stmt->execute([$isOwnerViewer ? 1 : 0]);
// جلب كل الصفوف كمصفوفة
$staff = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 2: تحويل كل سجل لشكل مناسب لـ Flutter
// ============================================================
$resultData = array_map(function ($s) use ($currentAdmin) {
    // إرجاع مصفوفة الموظف بالشكل الذي يقرأه موديل Flutter
    return [
        // معرّف الموظف
        "admin_id"        => (int)$s['admin_id'],
        // الاسم الكامل
        "admin_full_name" => $s['admin_full_name'],
        // رقم الهاتف الذي يستخدمه للدخول
        "admin_phone"     => $s['admin_phone'],
        // الدور (owner / manager / accountant / support / seller)
        "admin_role"      => $s['admin_role'],
        // ترجمة الدور للعربية من الخريطة الموحدة
        "admin_role_ar"   => adminRoleAr($s['admin_role']),
        // هل الحساب مفعّل؟
        "admin_is_active" => (int)$s['admin_is_active'],
        // تاريخ آخر دخول (null إن لم يدخل بعد)
        "admin_last_login"=> $s['admin_last_login'],
        // تاريخ إنشاء الحساب
        "admin_created_at"=> $s['admin_created_at'],
        // عدد الطلبات التي راجعها
        "reviews_count"   => (int)$s['reviews_count'],
        // ⭐ هل هذا هو الموظف المسجّل دخوله حالياً؟
        // اللوحة تستخدمها لتعطيل زر حذف الحساب الحالي
        "is_current_user" => ((int)$s['admin_id'] === (int)$currentAdmin['admin_id']),
    ];
}, $staff);

// ============================================================
// الخطوة 3: حساب إحصائيات سريعة لأعلى الصفحة
// ============================================================
$statsSQL = "
    SELECT
        -- عدد المديرين
        SUM(CASE WHEN admin_role = 'manager' THEN 1 ELSE 0 END) AS managers_count,
        -- عدد موظفي خدمة العملاء
        SUM(CASE WHEN admin_role = 'support' THEN 1 ELSE 0 END) AS support_count,
        -- عدد الحسابات المفعّلة
        SUM(CASE WHEN admin_is_active = 1 THEN 1 ELSE 0 END) AS active_count,
        -- العدد الكلي
        COUNT(admin_id) AS all_count
    FROM admin_users
    WHERE (? = 1 OR admin_role <> 'owner')
";
// تنفيذ استعلام الإحصائيات بنفس فلتر الرؤية
$statsStmt = $con->prepare($statsSQL);
$statsStmt->execute([$isOwnerViewer ? 1 : 0]);
// قراءة صف الإحصائيات
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 4: إرسال الرد
// ============================================================
echo json_encode([
    // حالة العملية
    "status"  => "success",
    // رسالة نجاح
    "message" => "Staff list fetched successfully",
    // مصفوفة الموظفين
    "data"    => $resultData,
    // إحصائيات للعرض في أعلى الصفحة
    "stats"   => [
        // عدد المديرين
        "managers_count" => (int)($stats['managers_count'] ?? 0),
        // عدد موظفي خدمة العملاء
        "support_count"  => (int)($stats['support_count']  ?? 0),
        // عدد الحسابات المفعّلة
        "active_count"   => (int)($stats['active_count']   ?? 0),
        // العدد الكلي
        "all_count"      => (int)($stats['all_count']      ?? 0),
    ],
]);
?>