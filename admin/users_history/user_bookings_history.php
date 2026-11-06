<?php
// ============================================================
// ملف: admin/users_history/user_bookings_history.php
// الغرض: سجل معاملات مستخدم واحد (كل الحجوزات التي طلبها)
//        مع Pagination وشارة التنفيذ الأخضر
//
// ⭐ الحجوزات مربوطة بـ booking_users_id = صاحب الطلب
//    سواء كان عميلاً أو مقدم خدمة يحجز خدمة لغيره
//
// الحقول:
//   users_id = معرّف المستخدم صاحب السجل (إجباري)
//   page     = رقم الصفحة (اختياري، افتراضي 1)
//   limit    = حجم الصفحة (اختياري، افتراضي 10، أقصى 50)
//
// الطريقة: POST أو GET
// الصلاحية المطلوبة: bookings.view
// الأدوار المسموحة: owner / manager / support
// ============================================================

// ربط ملف الاتصال بقاعدة البيانات
// نرجع مستويين لأن الملف داخل admin/users_history
include "../../connect.php";

// ============================================================
// الخطوة 1: التحقق من التوكن والصلاحية
// ============================================================
// التحقق من التوكن وجلب بيانات الموظف مع صلاحياته
$currentAdmin = checkAdminToken();

// فرض صلاحية عرض الحجوزات
requirePermission($currentAdmin, 'bookings.view');

// الأدوار المسموح لها بهذا القسم
$allowedRoles = ['owner', 'manager', 'support'];
// قراءة مفتاح الدور من جلسة الموظف
$roleKey = $currentAdmin['role_key'] ?? ($currentAdmin['admin_role'] ?? '');
// إن لم يكن الدور ضمن القائمة نرفض الطلب
if (!in_array($roleKey, $allowedRoles, true)) {
    // رسالة عربية واضحة
    printFailure("ليس لديك صلاحية تنفيذ هذا الإجراء", 403);
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 2: استقبال معرّف المستخدم ومعاملات الصفحات
// ============================================================
// معرّف المستخدم صاحب السجل
$usersId = (int)filterRequest("users_id");
// رقم الصفحة الحالية ولا يقل عن 1
$page = max(1, (int)(filterRequest("page") ?: 1));
// حجم الصفحة بين 1 و 50 والافتراضي 10 كما طُلب
$limit = min(50, max(1, (int)(filterRequest("limit") ?: 10)));
// نقطة البداية في قاعدة البيانات
$offset = ($page - 1) * $limit;

// ============================================================
// الخطوة 3: التحقق من الحقل الإجباري
// ============================================================
// إن لم يُرسل معرّف صالح نرفض الطلب
if ($usersId < 1) {
    // رسالة توضح الحقل المطلوب
    printFailure("users_id is required");
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 4: التحقق من وجود المستخدم وجلب بطاقته المختصرة
// ============================================================
// تجهيز استعلام بطاقة المستخدم مع الأدوار الثلاثة
$userStmt = $con->prepare(
    "SELECT
        u.users_id,
        u.users_full_name,
        u.users_phone,
        u.users_type,
        u.users_is_active,
        cp.clients_id,
        cp.clients_profile_image,
        pp.providers_id,
        pp.providers_profile_image,
        cr.company_id,
        cr.company_name
     FROM users u
     LEFT JOIN client_profiles cp
        ON cp.clients_users_id = u.users_id
     LEFT JOIN professional_profiles pp
        ON pp.providers_users_id = u.users_id
     LEFT JOIN company_registering cr
        ON cr.company_user_id = u.users_id
     WHERE u.users_id = ?
     LIMIT 1"
);
// تنفيذ الاستعلام بمعرّف المستخدم
$userStmt->execute([$usersId]);
// جلب صف المستخدم
$userRow = $userStmt->fetch(PDO::FETCH_ASSOC);

// إن لم يوجد المستخدم نرفض الطلب
if (!$userRow) {
    // رسالة 404 واضحة
    printFailure("المستخدم غير موجود", 404);
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 5: استعلام العدد الإجمالي لحجوزات هذا المستخدم
// ============================================================
// تجهيز جملة العد على جدول الحجوزات
$countStmt = $con->prepare(
    "SELECT COUNT(booking_id) AS total
     FROM bookings
     WHERE booking_users_id = ?"
);
// تنفيذ العد بمعرّف المستخدم
$countStmt->execute([$usersId]);
// قراءة العدد
$totalCount = (int)$countStmt->fetchColumn();
// حساب عدد الصفحات مع رفع الكسر
$totalPages = $limit > 0 ? (int)ceil($totalCount / $limit) : 0;

// ============================================================
// الخطوة 6: إحصائيات حالات حجوزات هذا المستخدم فقط
// ============================================================
// تجهيز استعلام الإحصائيات
$statsStmt = $con->prepare(
    "SELECT
        SUM(CASE WHEN booking_status = 'pending' THEN 1 ELSE 0 END)      AS pending_count,
        SUM(CASE WHEN booking_status = 'negotiating' THEN 1 ELSE 0 END)  AS negotiating_count,
        SUM(CASE WHEN booking_status = 'approved' THEN 1 ELSE 0 END)     AS approved_count,
        SUM(CASE WHEN booking_status = 'in_progress' THEN 1 ELSE 0 END)  AS in_progress_count,
        SUM(CASE WHEN booking_status = 'completed' THEN 1 ELSE 0 END)    AS completed_count,
        SUM(CASE WHEN booking_status = 'cancelled' THEN 1 ELSE 0 END)    AS cancelled_count,
        COUNT(booking_id)                                                AS all_count
     FROM bookings
     WHERE booking_users_id = ?"
);
// تنفيذ الإحصائيات
$statsStmt->execute([$usersId]);
// قراءة صف الإحصائيات
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 7: الاستعلام الرئيسي لصفحة السجل
//
// ⭐ الأحدث أولاً حتى ترى خدمة العملاء آخر معاملة مباشرة
// ============================================================
$sql = "
    SELECT
        b.booking_id,
        b.booking_users_id,
        b.booking_ps_id,
        b.booking_order_details,
        b.booking_ps_price,
        b.booking_ps_negotiable,
        b.booking_ps_commission_type,
        b.booking_ps_commission_value,
        b.booking_total_price,
        b.booking_coupon,
        b.booking_confirmation,
        b.booking_vailability_status,
        b.booking_approval,
        b.booking_status,
        b.booking_admin_notes,
        b.booking_number_of_requests,
        b.booking_created_at,
        b.booking_updated_at,

        -- ===== مقدم الخدمة الذي طلبه العميل =====
        pu.users_id         AS provider_users_id,
        pu.users_full_name  AS provider_full_name,
        pu.users_phone      AS provider_phone,
        pp.providers_profile_image,
        pp.providers_rating,

        -- ===== المهنة =====
        c.categories_name_ar,
        sc.sub_name_ar,
        st.timing_name_ar,

        -- ===== الكوبون =====
        cp.coupon_name,
        cp.coupon_discount
    FROM bookings b
    INNER JOIN provider_services ps
        ON ps.ps_id = b.booking_ps_id
    INNER JOIN users pu
        ON pu.users_id = ps.ps_user_id
    LEFT JOIN professional_profiles pp
        ON pp.providers_users_id = ps.ps_user_id
    LEFT JOIN categories c
        ON c.categories_id = ps.ps_cat_id
    LEFT JOIN sub_categories sc
        ON sc.sub_id = ps.ps_sub_cat_id
    LEFT JOIN service_timing st
        ON st.timing_id = ps.ps_timing_id
    LEFT JOIN coupon cp
        ON cp.coupon_id = b.booking_coupon AND b.booking_coupon > 0
    WHERE b.booking_users_id = ?
    ORDER BY b.booking_created_at DESC
    LIMIT $limit OFFSET $offset
";
// تجهيز الاستعلام الرئيسي
$stmt = $con->prepare($sql);
// تنفيذه بمعرّف المستخدم
$stmt->execute([$usersId]);
// جلب كل صفوف الصفحة
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 8: بناء الروابط ودوال المساعدة
// ============================================================
// استخراج مسار جذر المشروع
$scriptDir = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'])));
// تجميع الرابط الكامل
$baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . ($scriptDir === '/' || $scriptDir === '\\' ? '' : $scriptDir);
// إزالة الشرطة المائلة الزائدة
$baseUrl = rtrim($baseUrl, '/');

// دالة تبني رابط واتساب من رقم الهاتف
$whatsappUrl = function (?string $phone): ?string {
    // إن لم يوجد رقم نُرجع null
    if ($phone === null || $phone === '') {
        return null;
    }
    // الإبقاء على الأرقام فقط
    $digits = preg_replace('/[^0-9]/', '', $phone);
    // تحويل 010xxxxxxxx إلى 2010xxxxxxxx
    if (strlen($digits) === 11 && strpos($digits, '0') === 0) {
        $digits = '20' . substr($digits, 1);
    } elseif (strlen($digits) === 10) {
        $digits = '20' . $digits;
    }
    // إن لم يتبقَّ أرقام نُرجع null
    if ($digits === '') {
        return null;
    }
    // رابط واتساب جاهز
    return 'https://wa.me/' . $digits;
};

// دالة تنظّف اسم ملف الصورة
$cleanFile = function (?string $name): string {
    // إن كانت القيمة فارغة نُرجع نصاً فارغاً
    if ($name === null) {
        return '';
    }
    // إزالة الأسطر والمسافات
    return trim(str_replace(["\n", "\r"], '', $name));
};

// خريطة تسميات الحالات بالعربية
$statusLabels = [
    'pending'     => 'قيد الانتظار',
    'negotiating' => 'قيد التفاوض',
    'approved'    => 'تمت الموافقة',
    'in_progress' => 'قيد التنفيذ',
    'completed'   => 'مكتملة',
    'cancelled'   => 'ملغاة',
];

// ============================================================
// الخطوة 9: بناء بطاقة المستخدم المختصرة أعلى السجل
// ============================================================
// تنظيف صور المستخدم
$clientImage = $cleanFile($userRow['clients_profile_image'] ?? null);
$providerImage = $cleanFile($userRow['providers_profile_image'] ?? null);
// رابط صورة العميل
$clientImageUrl = $clientImage !== ''
    ? $baseUrl . '/upload/client_profiles/' . $clientImage
    : null;
// رابط صورة المزود
$providerImageUrl = $providerImage !== ''
    ? $baseUrl . '/upload/provider_profiles/' . $providerImage
    : null;
// الصورة المعروضة
$profileImage = $clientImageUrl ?? $providerImageUrl;
// الأدوار
$isClient = ($userRow['users_type'] === 'client') || $userRow['clients_id'] !== null;
$isProvider = ($userRow['users_type'] === 'provider') || $userRow['providers_id'] !== null;
$isCompanyOwner = $userRow['company_id'] !== null;
$rolesAr = [];
if ($isClient) {
    $rolesAr[] = 'عميل';
}
if ($isProvider) {
    $rolesAr[] = 'مقدم خدمة';
}
if ($isCompanyOwner) {
    $rolesAr[] = 'صاحب شركة';
}
if (empty($rolesAr)) {
    $rolesAr[] = 'عميل';
}

// بطاقة المستخدم أعلى الصفحة
$userCard = [
    "users_id"         => (int)$userRow['users_id'],
    "users_full_name"  => $userRow['users_full_name'],
    "users_phone"      => $userRow['users_phone'],
    "users_type"       => $userRow['users_type'],
    "users_is_active"  => (int)$userRow['users_is_active'],
    "profile_image"    => $profileImage,
    "whatsapp_url"     => $whatsappUrl($userRow['users_phone']),
    "is_client"        => $isClient,
    "is_provider"      => $isProvider,
    "is_company_owner" => $isCompanyOwner,
    "roles_label_ar"   => implode(' / ', $rolesAr),
    "roles_ar"         => $rolesAr,
    "company_name"     => $userRow['company_name'],
];

// ============================================================
// الخطوة 10: تحويل صفوف الحجوزات لشكل فلاتر
// ============================================================
$resultData = array_map(function ($row) use ($baseUrl, $whatsappUrl, $cleanFile, $statusLabels) {
    // هل نُفِّذت المعاملة؟ completed فقط تأخذ علامة الصح الأخضر
    $isExecuted = ($row['booking_status'] === 'completed');
    // تنظيف صورة المزود
    $imageName = $cleanFile($row['providers_profile_image'] ?? null);
    // رمز الحالة
    $status = $row['booking_status'] ?? '';
    // إرجاع عنصر السجل
    return [
        // معرّف الحجز لفتح التفاصيل
        "booking_id"                 => (int)$row['booking_id'],
        "booking_users_id"           => (int)$row['booking_users_id'],
        "booking_ps_id"              => (int)$row['booking_ps_id'],
        "booking_order_details"      => $row['booking_order_details'],
        "booking_ps_price"           => (float)$row['booking_ps_price'],
        "booking_ps_negotiable"      => (bool)$row['booking_ps_negotiable'],
        "booking_ps_commission_type" => $row['booking_ps_commission_type'],
        "booking_ps_commission_value"=> (float)$row['booking_ps_commission_value'],
        "booking_total_price"        => (float)$row['booking_total_price'],
        "booking_coupon"             => (int)$row['booking_coupon'],
        "coupon_name"                => $row['coupon_name'],
        "coupon_discount"            => $row['coupon_discount'] !== null
            ? (int)$row['coupon_discount'] : null,
        "booking_confirmation"       => (int)$row['booking_confirmation'],
        "booking_vailability_status" => $row['booking_vailability_status'],
        "booking_approval"           => (int)$row['booking_approval'],
        "booking_status"             => $status,
        "booking_status_label_ar"    => $statusLabels[$status] ?? $status,
        "booking_admin_notes"        => $row['booking_admin_notes'],
        "booking_number_of_requests" => (int)$row['booking_number_of_requests'],
        "booking_created_at"         => $row['booking_created_at'],
        "booking_updated_at"         => $row['booking_updated_at'],
        // ⭐ علامة الصح الأخضر أمام المعاملات المنفَّذة
        "is_executed"                => $isExecuted,
        // ===== مقدم الخدمة =====
        "provider" => [
            "users_id"        => (int)$row['provider_users_id'],
            "users_full_name" => $row['provider_full_name'],
            "users_phone"     => $row['provider_phone'],
            "profile_image"   => $imageName !== ''
                ? $baseUrl . '/upload/provider_profiles/' . $imageName
                : null,
            "providers_rating"=> $row['providers_rating'] !== null
                ? (float)$row['providers_rating'] : null,
            "whatsapp_url"    => $whatsappUrl($row['provider_phone']),
        ],
        // ===== المهنة =====
        "service" => [
            "category_name"   => $row['categories_name_ar'],
            "profession_name" => $row['sub_name_ar'],
            "timing_name"     => $row['timing_name_ar'],
        ],
    ];
}, $bookings);

// ============================================================
// الخطوة 11: إرسال الرد
// ============================================================
echo json_encode([
    // حالة العملية
    "status"  => "success",
    // رسالة عربية
    "message" => $totalCount > 0
        ? "تم جلب سجل المعاملات"
        : "لا توجد معاملات سابقة لهذا المستخدم",
    // بطاقة المستخدم أعلى السجل
    "user"    => $userCard,
    // قائمة المعاملات في الصفحة الحالية
    "data"    => $resultData,
    // إحصائيات حالات هذا المستخدم
    "stats" => [
        "pending_count"     => (int)($stats['pending_count']     ?? 0),
        "negotiating_count" => (int)($stats['negotiating_count'] ?? 0),
        "approved_count"    => (int)($stats['approved_count']    ?? 0),
        "in_progress_count" => (int)($stats['in_progress_count'] ?? 0),
        "completed_count"   => (int)($stats['completed_count']   ?? 0),
        "cancelled_count"   => (int)($stats['cancelled_count']   ?? 0),
        "all_count"         => (int)($stats['all_count']         ?? 0),
    ],
    // بيانات التصفح
    "pagination" => [
        "current_page"  => $page,
        "per_page"      => $limit,
        "total_count"   => $totalCount,
        "total_pages"   => $totalPages,
        "has_next_page" => ($page < $totalPages),
        "has_prev_page" => ($page > 1),
    ],
]);
