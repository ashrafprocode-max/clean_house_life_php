<?php
// ============================================================
// ملف: admin/users_history/user_booking_details.php
// الغرض: كل أعمدة جدول bookings لمعاملة واحدة من سجل المستخدم
//        مع اسم ورقم مقدم الخدمة (واتساب) والسعر وتفاصيل الخدمة
//
// ⭐ نتحقق أن الحجز يخص users_id المُرسَل حتى لا تُفتح معاملة غير مملوكة
//
// الحقول:
//   booking_id = معرّف الحجز (إجباري)
//   users_id   = صاحب السجل للتحقق من الملكية (إجباري)
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
// الخطوة 2: استقبال المعرّفات الإجبارية
// ============================================================
// معرّف الحجز
$bookingId = (int)filterRequest("booking_id");
// معرّف صاحب السجل للتحقق من الملكية
$usersId = (int)filterRequest("users_id");

// ============================================================
// الخطوة 3: التحقق من الحقول الإجبارية
// ============================================================
// إن نقص أحد المعرّفين نرفض الطلب
if ($bookingId < 1 || $usersId < 1) {
    // رسالة توضح الحقول المطلوبة
    printFailure("booking_id و users_id مطلوبان");
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 4: جلب الحجز مع العميل والمزود والمهنة والكوبون
//
// ⭐ شرط الملكية: booking_users_id = users_id
// ============================================================
$stmt = $con->prepare(
    "SELECT
        -- ===== كل أعمدة جدول bookings =====
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
        b.booking_reviewed_by,
        b.booking_reviewed_at,
        b.booking_created_at,
        b.booking_updated_at,

        -- ===== العميل صاحب الطلب =====
        cu.users_id         AS client_users_id,
        cu.users_full_name  AS client_full_name,
        cu.users_phone      AS client_phone,
        cu.users_country    AS client_country,
        cu.users_region     AS client_region,
        cu.users_is_active  AS client_is_active,
        cu.users_created_at AS client_created_at,

        -- ===== مقدم الخدمة الذي طلبه العميل =====
        pu.users_id         AS provider_users_id,
        pu.users_full_name  AS provider_full_name,
        pu.users_phone      AS provider_phone,
        pu.users_country    AS provider_country,
        pu.users_region     AS provider_region,
        pu.users_is_active  AS provider_is_active,
        pu.users_approve    AS provider_approve,
        pu.users_created_at AS provider_created_at,

        -- ===== الملف المهني =====
        pp.providers_id,
        pp.providers_users_id,
        pp.providers_experience,
        pp.providers_bio,
        pp.providers_rating,
        pp.providers_age,
        pp.providers_gender,
        pp.providers_nationality,
        pp.providers_profile_image,
        pp.providers_current_country,
        pp.providers_current_governorate,
        pp.providers_current_area,
        pp.providers_current_address,
        pp.providers_is_verified,

        -- ===== المهنة من الخدمة =====
        ps.ps_id,
        ps.ps_price            AS catalog_ps_price,
        ps.ps_negotiable       AS catalog_ps_negotiable,
        ps.ps_commission_type  AS catalog_commission_type,
        ps.ps_commission_value AS catalog_commission_value,
        c.categories_name_ar,
        sc.sub_name_ar,
        st.timing_name_ar,

        -- ===== الكوبون =====
        cp.coupon_id,
        cp.coupon_name,
        cp.coupon_discount,
        cp.coupon_expiredate,

        -- الموظف الذي راجع الحجز
        au.admin_full_name AS reviewed_by_name,
        au.admin_role      AS reviewed_by_role

     FROM bookings b
     INNER JOIN users cu
        ON cu.users_id = b.booking_users_id
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
     LEFT JOIN admin_users au
        ON au.admin_id = b.booking_reviewed_by
     WHERE b.booking_id = ?
       AND b.booking_users_id = ?
     LIMIT 1"
);
// تنفيذ الاستعلام بمعرّف الحجز ومعرّف صاحب السجل
$stmt->execute([$bookingId, $usersId]);
// جلب صف البيانات
$row = $stmt->fetch(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 5: التحقق من وجود الحجز وملكيته
// ============================================================
// إن لم يوجد صف نرفض الطلب
if (!$row) {
    // رسالة 404 لأن الحجز غير موجود أو لا يخص هذا المستخدم
    printFailure("الحجز غير موجود أو لا يخص هذا المستخدم", 404);
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 6: بناء الروابط
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

// ============================================================
// الخطوة 7: حساب العمولة وخصم الكوبون للعرض
//   الخصم يُطبَّق على العمولة فقط
// ============================================================
// سعر الخدمة المخزَّن في الحجز
$servicePrice = (float)$row['booking_ps_price'];
// نوع العمولة: fixed أو percent
$commissionType = $row['booking_ps_commission_type'];
// قيمة العمولة
$commissionValue = (float)$row['booking_ps_commission_value'];
// نسبة خصم الكوبون إن وُجد
$couponDiscount = $row['coupon_discount'] !== null
    ? (float)$row['coupon_discount']
    : 0;

// حساب مبلغ العمولة قبل الخصم
if ($commissionType === 'percent' && $servicePrice > 0) {
    // عمولة نسبة مئوية من سعر الخدمة
    $commissionAmount = ($servicePrice * $commissionValue) / 100;
} else {
    // عمولة ثابتة
    $commissionAmount = $commissionValue;
}

// مبلغ خصم الكوبون من العمولة فقط
$couponDiscountAmount = 0;
if ($couponDiscount > 0 && $commissionAmount > 0) {
    $couponDiscountAmount = ($commissionAmount * $couponDiscount) / 100;
}

// العمولة بعد الخصم ولا تقل عن صفر
$commissionAfterCoupon = $commissionAmount - $couponDiscountAmount;
if ($commissionAfterCoupon < 0) {
    $commissionAfterCoupon = 0;
}

// ============================================================
// الخطوة 8: تسمية الحالة بالعربية وعلامة التنفيذ
// ============================================================
// خريطة تسميات الحالات
$statusLabels = [
    'pending'     => 'قيد الانتظار',
    'negotiating' => 'قيد التفاوض',
    'approved'    => 'تمت الموافقة',
    'in_progress' => 'قيد التنفيذ',
    'completed'   => 'مكتملة',
    'cancelled'   => 'ملغاة',
];
// رمز الحالة
$status = $row['booking_status'] ?? '';
// هل نُفِّذت المعاملة؟
$isExecuted = ($status === 'completed');

// تنظيف اسم صورة المزود
$imageName = trim(str_replace(["\n", "\r"], '', (string)($row['providers_profile_image'] ?? '')));

// ============================================================
// الخطوة 9: إرسال الرد الكامل
// ============================================================
echo json_encode([
    // حالة العملية
    "status"  => "success",
    // رسالة عربية
    "message" => "تم جلب تفاصيل المعاملة",
    // البيانات
    "data"    => [

        // ===== 1. بيانات الحجز (كل أعمدة الجدول) =====
        "booking" => [
            "booking_id"                  => (int)$row['booking_id'],
            "booking_users_id"            => (int)$row['booking_users_id'],
            "booking_ps_id"               => (int)$row['booking_ps_id'],
            "booking_order_details"       => $row['booking_order_details'],
            "booking_ps_price"            => $servicePrice,
            "booking_ps_negotiable"       => (bool)$row['booking_ps_negotiable'],
            "booking_ps_commission_type"  => $commissionType,
            "booking_ps_commission_value" => $commissionValue,
            "booking_total_price"         => (float)$row['booking_total_price'],
            "booking_coupon"              => (int)$row['booking_coupon'],
            "booking_confirmation"        => (int)$row['booking_confirmation'],
            "booking_vailability_status"  => $row['booking_vailability_status'],
            "booking_approval"            => (int)$row['booking_approval'],
            "booking_status"              => $status,
            "booking_status_label_ar"     => $statusLabels[$status] ?? $status,
            "booking_admin_notes"         => $row['booking_admin_notes'],
            "booking_number_of_requests"  => (int)$row['booking_number_of_requests'],
            "booking_created_at"          => $row['booking_created_at'],
            "booking_updated_at"          => $row['booking_updated_at'],
            "is_executed"                 => $isExecuted,
        ],

        // ===== 2. العميل صاحب الطلب =====
        "client" => [
            "users_id"        => (int)$row['client_users_id'],
            "users_full_name" => $row['client_full_name'],
            "users_phone"     => $row['client_phone'],
            "users_country"   => $row['client_country'],
            "users_region"    => $row['client_region'],
            "users_is_active" => (int)$row['client_is_active'],
            "users_created_at"=> $row['client_created_at'],
            "whatsapp_url"    => $whatsappUrl($row['client_phone']),
        ],

        // ===== 3. مقدم الخدمة (الاسم + الهاتف + واتساب) =====
        "provider" => [
            "users_id"        => (int)$row['provider_users_id'],
            "users_full_name" => $row['provider_full_name'],
            "users_phone"     => $row['provider_phone'],
            "users_country"   => $row['provider_country'],
            "users_region"    => $row['provider_region'],
            "users_is_active" => (int)$row['provider_is_active'],
            "users_approve"   => (int)$row['provider_approve'],
            "users_created_at"=> $row['provider_created_at'],
            "profile_image"   => $imageName !== ''
                ? $baseUrl . '/upload/provider_profiles/' . $imageName
                : null,
            "providers_rating"=> $row['providers_rating'] !== null
                ? (float)$row['providers_rating'] : null,
            "whatsapp_url"    => $whatsappUrl($row['provider_phone']),
        ],

        // ===== 4. الملف المهني المختصر =====
        "provider_profile" => $row['providers_id'] ? [
            "providers_id"                  => (int)$row['providers_id'],
            "providers_users_id"            => (int)$row['providers_users_id'],
            "providers_experience"          => $row['providers_experience'],
            "providers_bio"                 => $row['providers_bio'],
            "providers_age"                 => $row['providers_age'] !== null
                ? (int)$row['providers_age'] : null,
            "providers_gender"              => $row['providers_gender'],
            "providers_nationality"         => $row['providers_nationality'],
            "providers_rating"              => $row['providers_rating'] !== null
                ? (float)$row['providers_rating'] : null,
            "providers_current_country"     => $row['providers_current_country'],
            "providers_current_governorate" => $row['providers_current_governorate'],
            "providers_current_area"        => $row['providers_current_area'],
            "providers_current_address"     => $row['providers_current_address'],
            "providers_is_verified"         => (int)$row['providers_is_verified'],
        ] : null,

        // ===== 5. المهنة المحجوزة =====
        "service" => [
            "ps_id"                    => (int)$row['ps_id'],
            "category_name"            => $row['categories_name_ar'],
            "profession_name"          => $row['sub_name_ar'],
            "timing_name"              => $row['timing_name_ar'],
            "catalog_ps_price"         => (float)$row['catalog_ps_price'],
            "catalog_ps_negotiable"    => (bool)$row['catalog_ps_negotiable'],
            "catalog_commission_type"  => $row['catalog_commission_type'],
            "catalog_commission_value" => (float)$row['catalog_commission_value'],
        ],

        // ===== 6. ملخص السعر =====
        "price_breakdown" => [
            "service_price"          => $servicePrice,
            "commission_type"        => $commissionType,
            "commission_value"       => $commissionValue,
            "commission_before"      => round($commissionAmount, 2),
            "coupon_discount_amount" => round($couponDiscountAmount, 2),
            "commission_after"       => round($commissionAfterCoupon, 2),
            "total_price"            => (float)$row['booking_total_price'],
            "negotiable"             => (bool)$row['booking_ps_negotiable'],
        ],

        // ===== 7. الكوبون =====
        "coupon" => ((int)$row['booking_coupon'] > 0) ? [
            "coupon_id"           => (int)$row['coupon_id'],
            "coupon_name"         => $row['coupon_name'],
            "coupon_discount"     => (int)$row['coupon_discount'],
            "coupon_expiredate"   => $row['coupon_expiredate'],
            "discount_applies_on" => 'commission_only',
        ] : null,

        // ===== 8. مراجعة الحجز =====
        "review" => [
            "reviewed_by"      => $row['booking_reviewed_by']
                ? (int)$row['booking_reviewed_by'] : null,
            "reviewed_by_name" => $row['reviewed_by_name'],
            "reviewed_by_role" => $row['reviewed_by_role'],
            "reviewed_at"      => $row['booking_reviewed_at'],
        ],
    ],
]);
