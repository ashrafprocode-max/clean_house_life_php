<?php
// ============================================================
// ملف: admin/bookings/booking_details.php
// الغرض: عرض كل بيانات حجز واحد للمراجعة التفصيلية
//        العميل + مقدم الخدمة + المهنة + السعر + المستندات
//
// ⚠️ هذا الملف للإدارة فقط ولا يُستدعى من تطبيق العميل إطلاقاً
//    لأنه يكشف: أرقام الهواتف + صور البطاقة + الفيش + إيصال المرافق
//
// ⭐ الصور تُرجَع كروابط بوّابة آمنة (secure_image.php) وليست روابط مباشرة
//
// الحقول المطلوبة: booking_id
// الطريقة: POST أو GET
// الصلاحية المطلوبة: bookings.view
// ============================================================

// ربط ملف الاتصال بقاعدة البيانات (مستويان للوراء لأن الملف داخل admin/bookings)
include "../../connect.php";

// ⭐ حماية ملف الإدارة: يجب إرسال المفتاح السري في هيدر X-Admin-Key
// ⚠️ لو أردت تعطيل الحماية مؤقتاً أثناء التطوير ضع // قبل السطر التالي فقط
// checkAdminKey();

// ============================================================
// الخطوة 1: التحقق من التوكن والصلاحية
//
// ⭐ bookings.view يملكها: owner / manager / support
// ============================================================
$currentAdmin = checkAdminToken();

// ⭐ فرض صلاحية عرض الحجوزات
requirePermission($currentAdmin, 'bookings.view');

// ⭐ التفاصيل لـ owner / manager / support فقط
$allowedRoles = ['owner', 'manager', 'support'];
$roleKey = $currentAdmin['role_key'] ?? ($currentAdmin['admin_role'] ?? '');
if (!in_array($roleKey, $allowedRoles, true)) {
    printFailure("ليس لديك صلاحية تنفيذ هذا الإجراء", 403);
    exit;
}

// ============================================================
// الخطوة 2: استقبال معرّف الحجز
// ============================================================
$booking_id = filterRequest("booking_id");

// ============================================================
// الخطوة 3: التحقق من الحقل الإجباري
// ============================================================
if (empty($booking_id)) {
    printFailure("booking_id is required");
    exit;
}

// تحويل المعرّف لرقم صحيح لحماية جملة WHERE
$booking_id = (int) $booking_id;

// ============================================================
// الخطوة 4: جلب الحجز مع العميل والمزود والمهنة والكوبون
// ============================================================
$stmt = $con->prepare(
    "SELECT
        b.booking_id,
        b.booking_users_id,
        b.booking_ps_id,
        b.booking_order_details,
        b.booking_ps_price,
        b.booking_ps_negotiable,
        b.booking_ps_commission_type,
        b.booking_ps_commission_value,
        -- ⭐ عمولة مقدم الخدمة (A) المخفية عن العميل
        b.booking_ps_commission_amount,
        -- ⭐ عمولة العميل (B) الظاهرة والمُحصَّلة
        b.booking_client_commission_type,
        b.booking_client_commission_value,
        b.booking_client_commission_amount,
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

        -- ===== العميل من جدول users =====
        cu.users_id         AS client_users_id,
        cu.users_full_name  AS client_full_name,
        cu.users_phone      AS client_phone,
        cu.users_country    AS client_country,
        cu.users_region     AS client_region,
        cu.users_is_active  AS client_is_active,
        cu.users_created_at AS client_created_at,

        -- ===== مقدم الخدمة من جدول users =====
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
        pp.providers_id_card_front,
        pp.providers_id_card_back,
        pp.providers_criminal_record_image,
        pp.providers_utility_receipt_image,
        pp.providers_is_verified,
        pp.providers_review_note,
        pp.providers_reviewed_by,
        pp.providers_reviewed_at,

        -- ===== المهنة من الخدمة =====
        ps.ps_id,
        ps.ps_price         AS catalog_ps_price,
        ps.ps_negotiable    AS catalog_ps_negotiable,
        ps.ps_commission_type  AS catalog_commission_type,
        ps.ps_commission_value AS catalog_commission_value,
        -- ⭐ مبلغ عمولة مقدم الخدمة (A) المخزَّن على الخدمة
        ps.ps_commission_amount AS catalog_ps_commission_amount,
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
     LIMIT 1"
);
// تنفيذ الاستعلام بتمرير معرّف الحجز
$stmt->execute([$booking_id]);
// جلب صف البيانات كمصفوفة
$row = $stmt->fetch(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 5: التحقق من وجود الحجز
// ============================================================
if (!$row) {
    printFailure("Booking not found", 404);
    exit;
}

// ============================================================
// الخطوة 6: بناء الروابط
// ============================================================
$scriptDir = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'])));
$baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . ($scriptDir === '/' || $scriptDir === '\\' ? '' : $scriptDir);
$baseUrl = rtrim($baseUrl, '/');

// معرّف مقدم الخدمة لروابط المستندات الآمنة
$providerUsersId = (int) $row['provider_users_id'];

// دالة مساعدة تبني رابط البوّابة الآمنة لنوع مستند معيّن
$secureUrl = function (string $type, ?string $fileName) use ($baseUrl, $providerUsersId) {
    if (empty($fileName)) {
        return null;
    }
    return $baseUrl . '/admin/providers/secure_image.php'
        . '?type=' . urlencode($type)
        . '&providers_users_id=' . urlencode((string) $providerUsersId);
};

// دالة مساعدة تبني رابط واتساب من رقم الهاتف
$whatsappUrl = function (?string $phone): ?string {
    if ($phone === null || $phone === '') {
        return null;
    }
    $digits = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($digits) === 11 && strpos($digits, '0') === 0) {
        $digits = '20' . substr($digits, 1);
    } elseif (strlen($digits) === 10) {
        $digits = '20' . $digits;
    }
    if ($digits === '') {
        return null;
    }
    return 'https://wa.me/' . $digits;
};

// ============================================================
// الخطوة 7: حساب العمولتين وخصم الكوبون للعرض
//   A = عمولة مقدم الخدمة المخفية عن العميل (تُضاف للسعر الظاهر)
//   B = عمولة العميل الظاهرة (يُخصم منها الكوبون فقط)
//   أساس حساب B = سعر مقدم الخدمة الأساسي (بدون A)
// ============================================================
// السعر الأساسي لمقدم الخدمة (بدون عمولته)
$providerBasePrice = (float) $row['booking_ps_price'];
// ⭐ عمولة مقدم الخدمة (A) المخفية
$providerCommissionAmount = (float) $row['booking_ps_commission_amount'];
// السعر الظاهر للعميل = الأساسي + A
$servicePrice = round($providerBasePrice + $providerCommissionAmount, 2);

// ⭐ عمولة العميل (B) مع احتياط للأعمدة القديمة
$commissionType = $row['booking_client_commission_type'] !== null
    && $row['booking_client_commission_type'] !== ''
    ? $row['booking_client_commission_type']
    : $row['booking_ps_commission_type'];
$commissionValue = $row['booking_client_commission_value'] !== null
    ? (float) $row['booking_client_commission_value']
    : (float) $row['booking_ps_commission_value'];
$couponDiscount = $row['coupon_discount'] !== null ? (float) $row['coupon_discount'] : 0;

// أساس حساب عمولة العميل = السعر الأساسي لمقدم الخدمة
if ($commissionType === 'percent' && $providerBasePrice > 0) {
    $commissionAmount = ($providerBasePrice * $commissionValue) / 100;
} else {
    $commissionAmount = $commissionValue;
}

$couponDiscountAmount = 0;
if ($couponDiscount > 0 && $commissionAmount > 0) {
    $couponDiscountAmount = ($commissionAmount * $couponDiscount) / 100;
}

$commissionAfterCoupon = $commissionAmount - $couponDiscountAmount;
if ($commissionAfterCoupon < 0) {
    $commissionAfterCoupon = 0;
}

// ============================================================
// الخطوة 8: حساب مدة الانتظار بالساعات
// ============================================================
$createdTimestamp = strtotime($row['booking_created_at']);
$waitingHours = $createdTimestamp
    ? (int) floor((time() - $createdTimestamp) / 3600)
    : 0;

// ============================================================
// الخطوة 9: إرسال الرد الكامل
// ============================================================
echo json_encode([
    "status" => "success",
    "message" => "Booking details fetched successfully",
    "data" => [

        // ===== 1. بيانات الحجز =====
        "booking" => [
            "booking_id" => (int) $row['booking_id'],
            "booking_users_id" => (int) $row['booking_users_id'],
            "booking_ps_id" => (int) $row['booking_ps_id'],
            "booking_order_details" => $row['booking_order_details'],
            "booking_ps_price" => $servicePrice,
            "booking_ps_negotiable" => (bool) $row['booking_ps_negotiable'],
            "booking_ps_commission_type" => $commissionType,
            "booking_ps_commission_value" => $commissionValue,
            // ⭐ عمولة مقدم الخدمة (A) المخفية عن العميل
            "booking_ps_commission_amount" => round($providerCommissionAmount, 2),
            // ⭐ عمولة العميل (B) الظاهرة والمُحصَّلة
            "booking_client_commission_type" => $commissionType,
            "booking_client_commission_value" => $commissionValue,
            "booking_client_commission_amount" => round($commissionAmount, 2),
            "booking_total_price" => (float) $row['booking_total_price'],
            "booking_coupon" => (int) $row['booking_coupon'],
            "booking_confirmation" => (int) $row['booking_confirmation'],
            "booking_vailability_status" => $row['booking_vailability_status'],
            "booking_approval" => (int) $row['booking_approval'],
            "booking_status" => $row['booking_status'],
            "booking_admin_notes" => $row['booking_admin_notes'],
            "booking_number_of_requests" => (int) $row['booking_number_of_requests'],
            "booking_created_at" => $row['booking_created_at'],
            "booking_updated_at" => $row['booking_updated_at'],
            "waiting_hours" => $waitingHours,
            "is_overdue" => ($waitingHours > 24
                && $row['booking_status'] === 'pending'),
        ],

        // ===== 2. العميل =====
        "client" => [
            "users_id" => (int) $row['client_users_id'],
            "users_full_name" => $row['client_full_name'],
            "users_phone" => $row['client_phone'],
            "users_country" => $row['client_country'],
            "users_region" => $row['client_region'],
            "users_is_active" => (int) $row['client_is_active'],
            "users_created_at" => $row['client_created_at'],
            "profile_image" => null,
            "whatsapp_url" => $whatsappUrl($row['client_phone']),
        ],

        // ===== 3. الحساب المهني لمقدم الخدمة =====
        "provider_account" => [
            "users_id" => (int) $row['provider_users_id'],
            "users_full_name" => $row['provider_full_name'],
            "users_phone" => $row['provider_phone'],
            "users_country" => $row['provider_country'],
            "users_region" => $row['provider_region'],
            "users_is_active" => (int) $row['provider_is_active'],
            "users_approve" => (int) $row['provider_approve'],
            "users_created_at" => $row['provider_created_at'],
            "whatsapp_url" => $whatsappUrl($row['provider_phone']),
        ],

        // ===== 4. الملف المهني =====
        "provider_profile" => $row['providers_id'] ? [
            "providers_id" => (int) $row['providers_id'],
            "providers_users_id" => (int) $row['providers_users_id'],
            "providers_experience" => $row['providers_experience'],
            "providers_bio" => $row['providers_bio'],
            "providers_age" => (int) $row['providers_age'],
            "providers_gender" => $row['providers_gender'],
            "providers_nationality" => $row['providers_nationality'],
            "providers_rating" => (float) $row['providers_rating'],
            "providers_current_country" => $row['providers_current_country'],
            "providers_current_governorate" => $row['providers_current_governorate'],
            "providers_current_area" => $row['providers_current_area'],
            "providers_current_address" => $row['providers_current_address'],
            "providers_profile_image" => $row['providers_profile_image']
                ? $baseUrl . '/upload/provider_profiles/' . $row['providers_profile_image']
                : null,
            "providers_is_verified" => (int) $row['providers_is_verified'],
        ] : null,

        // ===== 5. المستندات عبر البوّابة الآمنة =====
        "documents" => [
            "id_card_front" => [
                "exists" => !empty($row['providers_id_card_front']),
                "url" => $secureUrl('id_front', $row['providers_id_card_front']),
            ],
            "id_card_back" => [
                "exists" => !empty($row['providers_id_card_back']),
                "url" => $secureUrl('id_back', $row['providers_id_card_back']),
            ],
            "criminal_record" => [
                "exists" => !empty($row['providers_criminal_record_image']),
                "url" => $secureUrl('criminal', $row['providers_criminal_record_image']),
            ],
            "utility_receipt" => [
                "exists" => !empty($row['providers_utility_receipt_image']),
                "url" => $secureUrl('utility', $row['providers_utility_receipt_image']),
            ],
            "is_complete" => !empty($row['providers_id_card_front'])
                && !empty($row['providers_id_card_back']),
        ],

        // ===== 6. المهنة المحجوزة =====
        "service" => [
            "ps_id" => (int) $row['ps_id'],
            "category_name" => $row['categories_name_ar'],
            "profession_name" => $row['sub_name_ar'],
            "timing_name" => $row['timing_name_ar'],
            "catalog_ps_price" => (float) $row['catalog_ps_price'],
            "catalog_ps_negotiable" => (bool) $row['catalog_ps_negotiable'],
            "catalog_commission_type" => $row['catalog_commission_type'],
            "catalog_commission_value" => (float) $row['catalog_commission_value'],
            // ⭐ مبلغ عمولة مقدم الخدمة (A) المخزَّن على الخدمة
            "catalog_ps_commission_amount" => (float) $row['catalog_ps_commission_amount'],
        ],

        // ===== 7. ملخص السعر =====
        "price_breakdown" => [
            // ⭐ سعر الخدمة الظاهر للعميل = الأساسي + عمولة مقدم الخدمة (A)
            "service_price" => $servicePrice,
            // سعر مقدم الخدمة الأساسي بدون عمولته
            "provider_base_price" => round($providerBasePrice, 2),
            "commission_type" => $commissionType,
            "commission_value" => $commissionValue,
            "commission_before" => round($commissionAmount, 2),
            "coupon_discount_amount" => round($couponDiscountAmount, 2),
            "commission_after" => round($commissionAfterCoupon, 2),
            "total_price" => (float) $row['booking_total_price'],
            "negotiable" => (bool) $row['booking_ps_negotiable'],
        ],

        // ===== 8. الكوبون =====
        "coupon" => ((int) $row['booking_coupon'] > 0) ? [
            "coupon_id" => (int) $row['coupon_id'],
            "coupon_name" => $row['coupon_name'],
            "coupon_discount" => (int) $row['coupon_discount'],
            "coupon_expiredate" => $row['coupon_expiredate'],
            "discount_applies_on" => 'commission_only',
        ] : null,

        // ===== 9. مراجعة الحجز =====
        "review" => [
            "reviewed_by" => $row['booking_reviewed_by']
                ? (int) $row['booking_reviewed_by'] : null,
            "reviewed_by_name" => $row['reviewed_by_name'],
            "reviewed_by_role" => $row['reviewed_by_role'],
            "reviewed_at" => $row['booking_reviewed_at'],
        ],

        // أزرار الواجهة حسب صلاحية الموظف الحالي
        "can_edit" => adminCan($currentAdmin, 'bookings.edit'),
        "can_cancel" => adminCan($currentAdmin, 'bookings.cancel'),
    ],
]);
