<?php
// ============================================================
// ملف: admin/bookings/list_booking.php
// الغرض: عرض قائمة الحجوزات للإدارة
//        مع فلترة الحالة + البحث بالاسم/الهاتف + Pagination
//
// ⭐ الحالات (booking_status):
//   pending      = قيد المراجعة
//   negotiating  = قيد التفاوض
//   approved     = موافق عليه
//   in_progress  = جارٍ التنفيذ
//   completed    = مكتمل
//   cancelled    = ملغى
//   all          = الكل (بدون فلتر حالة)
//
// ⭐ البحث: اسم أو هاتف العميل أو مقدم الخدمة
//
// ⚠️ القائمة للعرض السريع فقط
//    روابط المستندات الحساسة في booking_details.php
//
// أمثلة الاستخدام:
//   status=pending&page=1&limit=20
//   status=all&search=أشرف
//
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
//    والمحاسب يملكها للقراءة فقط
// ============================================================
// التحقق من التوكن وجلب بيانات الموظف مع صلاحياته
$currentAdmin = checkAdminToken();

// ⭐ فرض صلاحية عرض الحجوزات
requirePermission($currentAdmin, 'bookings.view');

// ⭐ القائمة لـ owner / manager / support فقط
$allowedRoles = ['owner', 'manager', 'support'];
$roleKey = $currentAdmin['role_key'] ?? ($currentAdmin['admin_role'] ?? '');
if (!in_array($roleKey, $allowedRoles, true)) {
    printFailure("ليس لديك صلاحية تنفيذ هذا الإجراء", 403);
    exit;
}

// ============================================================
// الخطوة 2: استقبال معاملات الفلترة والبحث
// ============================================================
// حالة الحجز المطلوبة
$status = filterRequest("status");
// نص البحث في اسم أو هاتف العميل / مقدم الخدمة
$search = trim(filterRequest("search"));

// ============================================================
// الخطوة 3: معاملات Pagination
// max() و min() لضمان قيم منطقية
// ============================================================
// رقم الصفحة الحالية - لا تقل عن 1
$page = max(1, (int) (filterRequest("page") ?: 1));
// عدد النتائج - لا تقل عن 1 ولا تزيد عن 50
$limit = min(50, max(1, (int) (filterRequest("limit") ?: 20)));
// نقطة البداية في قاعدة البيانات
$offset = ($page - 1) * $limit;

// ============================================================
// الخطوة 4: بناء شروط WHERE المشتركة بين الاستعلامين
// ============================================================
// شرط ثابت دائماً صحيح لتبسيط دمج الشروط بعده
$whereConditions = ["1 = 1"];

// مصفوفتان منفصلتان للمعاملات: واحدة للعدد وأخرى للبيانات
$params = [];
$countParams = [];

// ============================================================
// الخطوة 5: فلتر حالة الحجز
// ============================================================
// قائمة الحالات المسموحة في عمود enum
$allowedStatuses = [
    'pending',
    'negotiating',
    'approved',
    'in_progress',
    'completed',
    'cancelled',
];

// نُضيف الشرط فقط إذا كانت الحالة معروفة (all تعني بلا فلترة)
if ($status !== '' && $status !== 'all' && in_array($status, $allowedStatuses, true)) {
    // إضافة شرط الحالة لجملة WHERE
    $whereConditions[] = "b.booking_status = ?";
    // تمرير القيمة للاستعلام الرئيسي
    $params[] = $status;
    // وتمريره كذلك لاستعلام العدد الإجمالي
    $countParams[] = $status;
}

// ============================================================
// الخطوة 6: فلتر البحث بالاسم أو رقم الهاتف
//           نبحث في العميل ومقدم الخدمة معاً
// ============================================================
// نُضيف الشرط فقط إذا أرسلت الواجهة نصاً للبحث
if ($search !== '') {
    // الأقواس مهمة حتى لا يتعارض OR مع شروط AND الأخرى
    $whereConditions[] = "("
        . " cu.users_full_name LIKE ?"
        . " OR cu.users_phone LIKE ?"
        . " OR pu.users_full_name LIKE ?"
        . " OR pu.users_phone LIKE ?"
        . ")";
    // تجهيز نص البحث مع % قبله وبعده للبحث الجزئي
    $searchTerm = "%{$search}%";
    // تمرير النص أربع مرات (اسم/هاتف العميل + اسم/هاتف المزود)
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
}

// دمج كل الشروط في جملة WHERE واحدة مفصولة بـ AND
$whereSQL = "WHERE " . implode(" AND ", $whereConditions);

// ============================================================
// الخطوة 7: استعلام العدد الإجمالي
// مهم لحساب عدد الصفحات وعرض "صفحة 1 من 5" في الواجهة
// ============================================================
$countSQL = "
    SELECT COUNT(b.booking_id) AS total
    FROM bookings b
    INNER JOIN users cu
        ON cu.users_id = b.booking_users_id
    INNER JOIN provider_services ps
        ON ps.ps_id = b.booking_ps_id
    INNER JOIN users pu
        ON pu.users_id = ps.ps_user_id
    $whereSQL
";
// تجهيز الاستعلام
$countStmt = $con->prepare($countSQL);
// تنفيذه بمعاملات العدد
$countStmt->execute($countParams);
// قراءة الرقم الناتج
$totalCount = (int) $countStmt->fetchColumn();

// حساب إجمالي الصفحات (نرفع الكسر دائماً)
$totalPages = $limit > 0 ? (int) ceil($totalCount / $limit) : 0;

// ============================================================
// الخطوة 8: استعلام عدد الحجوزات في كل حالة
// لعرض شارات العدد على أزرار الفلترة في لوحة التحكم
// ============================================================
$statsSQL = "
    SELECT
        SUM(CASE WHEN b.booking_status = 'pending' THEN 1 ELSE 0 END)      AS pending_count,
        SUM(CASE WHEN b.booking_status = 'negotiating' THEN 1 ELSE 0 END)  AS negotiating_count,
        SUM(CASE WHEN b.booking_status = 'approved' THEN 1 ELSE 0 END)     AS approved_count,
        SUM(CASE WHEN b.booking_status = 'in_progress' THEN 1 ELSE 0 END)  AS in_progress_count,
        SUM(CASE WHEN b.booking_status = 'completed' THEN 1 ELSE 0 END)    AS completed_count,
        SUM(CASE WHEN b.booking_status = 'cancelled' THEN 1 ELSE 0 END)    AS cancelled_count,
        COUNT(b.booking_id)                                                AS all_count
    FROM bookings b
";
// تنفيذ استعلام الإحصائيات بدون معاملات
$statsStmt = $con->prepare($statsSQL);
$statsStmt->execute();
// قراءة صف الإحصائيات
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 9: الاستعلام الرئيسي مع LIMIT و OFFSET
// ⚠️ لا نجلب أسماء ملفات البطاقة أو الفيش هنا
//    نجلب فقط هل هي موجودة أم لا (لعرض علامة ✓ في القائمة)
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

        -- ===== بيانات العميل =====
        cu.users_id         AS client_users_id,
        cu.users_full_name  AS client_full_name,
        cu.users_phone      AS client_phone,

        -- ===== بيانات مقدم الخدمة =====
        pu.users_id         AS provider_users_id,
        pu.users_full_name  AS provider_full_name,
        pu.users_phone      AS provider_phone,

        -- ===== الملف المهني =====
        pp.providers_id,
        pp.providers_profile_image,
        pp.providers_rating,

        -- ⚠️ لا نُرجع أسماء ملفات المستندات في القائمة للأمان
        CASE WHEN pp.providers_id_card_front IS NOT NULL
             AND pp.providers_id_card_front <> ''
             THEN 1 ELSE 0 END AS has_id_front,
        CASE WHEN pp.providers_id_card_back IS NOT NULL
             AND pp.providers_id_card_back <> ''
             THEN 1 ELSE 0 END AS has_id_back,
        CASE WHEN pp.providers_criminal_record_image IS NOT NULL
             AND pp.providers_criminal_record_image <> ''
             THEN 1 ELSE 0 END AS has_criminal_record,
        CASE WHEN pp.providers_utility_receipt_image IS NOT NULL
             AND pp.providers_utility_receipt_image <> ''
             THEN 1 ELSE 0 END AS has_utility_receipt,

        -- ===== المهنة والسعر من الخدمة =====
        c.categories_name_ar,
        sc.sub_name_ar,
        st.timing_name_ar,

        -- ===== الكوبون =====
        cp.coupon_name,
        cp.coupon_discount,

        -- اسم الموظف الذي راجع الحجز
        au.admin_full_name AS reviewed_by_name

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
    $whereSQL
    ORDER BY
        -- المعلّقة أولاً ثم قيد التفاوض لأنها الأهم لخدمة العملاء
        CASE b.booking_status
            WHEN 'pending'     THEN 0
            WHEN 'negotiating' THEN 1
            WHEN 'approved'    THEN 2
            WHEN 'in_progress' THEN 3
            WHEN 'completed'   THEN 4
            WHEN 'cancelled'   THEN 5
            ELSE 6
        END ASC,
        -- ثم الأقدم إنشاءً أولاً (الأطول انتظاراً)
        b.booking_created_at ASC
    LIMIT $limit OFFSET $offset
";
// تجهيز الاستعلام الرئيسي
$stmt = $con->prepare($sql);
// تنفيذه بمعاملات البيانات
$stmt->execute($params);
// جلب كل الصفوف كمصفوفة
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 10: بناء رابط الصورة الشخصية
// نرجع مستويين للوراء للوصول لجذر المشروع
// ============================================================
// استخراج مسار مجلد جذر المشروع من مسار السكربت الحالي
$scriptDir = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'])));
// تجميع الرابط الكامل مع البروتوكول واسم النطاق
$baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . ($scriptDir === '/' || $scriptDir === '\\' ? '' : $scriptDir);
// إزالة أي شرطة مائلة زائدة من نهاية الرابط
$baseUrl = rtrim($baseUrl, '/');

// ============================================================
// دالة مساعدة تبني رابط واتساب من رقم الهاتف
// ============================================================
$whatsappUrl = function (?string $phone): ?string {
    // إذا لم يوجد رقم نُرجع null
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
    // إذا لم يتبقَّ أرقام نُرجع null
    if ($digits === '') {
        return null;
    }
    // رابط واتساب جاهز للفتح من اللوحة
    return 'https://wa.me/' . $digits;
};

// هل يملك الموظف تعديل أو إلغاء؟ (لأزرار الواجهة)
$canEdit = adminCan($currentAdmin, 'bookings.edit');
$canCancel = adminCan($currentAdmin, 'bookings.cancel');

// ============================================================
// الخطوة 11: تحويل كل سجل لشكل مناسب لـ Flutter
// ============================================================
$resultData = array_map(function ($row) use ($baseUrl, $whatsappUrl, $canEdit, $canCancel) {
    // إرجاع مصفوفة السجل بالشكل الذي يقرأه موديل Flutter
    return [
        // معرّف الحجز (نحتاجه لفتح التفاصيل والتعديل)
        "booking_id" => (int) $row['booking_id'],
        "booking_users_id" => (int) $row['booking_users_id'],
        "booking_ps_id" => (int) $row['booking_ps_id'],
        "booking_order_details" => $row['booking_order_details'],
        "booking_ps_price" => (float) $row['booking_ps_price'],
        "booking_ps_negotiable" => (bool) $row['booking_ps_negotiable'],
        "booking_ps_commission_type" => $row['booking_ps_commission_type'],
        "booking_ps_commission_value" => (float) $row['booking_ps_commission_value'],
        // ⭐ عمولة مقدم الخدمة (A) المخفية عن العميل
        "booking_ps_commission_amount" => (float) $row['booking_ps_commission_amount'],
        // ⭐ عمولة العميل (B) الظاهرة والمُحصَّلة
        "booking_client_commission_type" => $row['booking_client_commission_type'],
        "booking_client_commission_value" => (float) $row['booking_client_commission_value'],
        "booking_client_commission_amount" => (float) $row['booking_client_commission_amount'],
        "booking_total_price" => (float) $row['booking_total_price'],
        "booking_coupon" => (int) $row['booking_coupon'],
        "coupon_name" => $row['coupon_name'],
        "coupon_discount" => $row['coupon_discount'] !== null
            ? (int) $row['coupon_discount'] : null,
        "booking_confirmation" => (int) $row['booking_confirmation'],
        "booking_vailability_status" => $row['booking_vailability_status'],
        "booking_approval" => (int) $row['booking_approval'],
        "booking_status" => $row['booking_status'],
        "booking_admin_notes" => $row['booking_admin_notes'],
        "booking_number_of_requests" => (int) $row['booking_number_of_requests'],
        "booking_created_at" => $row['booking_created_at'],
        "booking_updated_at" => $row['booking_updated_at'],

        // ===== العميل =====
        "client" => [
            "users_id" => (int) $row['client_users_id'],
            "users_full_name" => $row['client_full_name'],
            // ⚠️ رقم الهاتف يظهر للإدارة فقط
            "users_phone" => $row['client_phone'],
            // جدول users لا يحتوي صورة شخصية للعميل
            "profile_image" => null,
            "whatsapp_url" => $whatsappUrl($row['client_phone']),
        ],

        // ===== مقدم الخدمة =====
        "provider" => [
            "users_id" => (int) $row['provider_users_id'],
            "users_full_name" => $row['provider_full_name'],
            // ⚠️ رقم الهاتف يظهر للإدارة فقط
            "users_phone" => $row['provider_phone'],
            "providers_id" => $row['providers_id'] !== null
                ? (int) $row['providers_id'] : null,
            "profile_image" => $row['providers_profile_image']
                ? $baseUrl . '/upload/provider_profiles/' . $row['providers_profile_image']
                : null,
            "providers_rating" => $row['providers_rating'] !== null
                ? (float) $row['providers_rating'] : null,
            "whatsapp_url" => $whatsappUrl($row['provider_phone']),
        ],

        // ===== المهنة =====
        "service" => [
            "category_name" => $row['categories_name_ar'],
            "profession_name" => $row['sub_name_ar'],
            "timing_name" => $row['timing_name_ar'],
        ],

        // ===== اكتمال المستندات (بدون كشف أسماء الملفات) =====
        "documents" => [
            "has_id_front" => (bool) $row['has_id_front'],
            "has_id_back" => (bool) $row['has_id_back'],
            "has_criminal_record" => (bool) $row['has_criminal_record'],
            "has_utility_receipt" => (bool) $row['has_utility_receipt'],
            "is_complete" => (bool) $row['has_id_front'] && (bool) $row['has_id_back'],
        ],

        // ===== المراجعة =====
        "review" => [
            "reviewed_by" => $row['booking_reviewed_by']
                ? (int) $row['booking_reviewed_by'] : null,
            "reviewed_by_name" => $row['reviewed_by_name'],
            "reviewed_at" => $row['booking_reviewed_at'],
        ],

        // أزرار الواجهة حسب صلاحية الموظف الحالي
        "can_edit" => $canEdit,
        "can_cancel" => $canCancel,
    ];
}, $bookings);

// ============================================================
// الخطوة 12: إرسال الرد مع بيانات Pagination والإحصائيات
// ============================================================
echo json_encode([
    // حالة العملية
    "status" => "success",
    // رسالة نجاح واضحة
    "message" => "Bookings list fetched successfully",
    // مصفوفة الحجوزات
    "data" => $resultData,

    // ===== إحصائيات لشارات أزرار الفلترة =====
    "stats" => [
        "pending_count" => (int) ($stats['pending_count'] ?? 0),
        "negotiating_count" => (int) ($stats['negotiating_count'] ?? 0),
        "approved_count" => (int) ($stats['approved_count'] ?? 0),
        "in_progress_count" => (int) ($stats['in_progress_count'] ?? 0),
        "completed_count" => (int) ($stats['completed_count'] ?? 0),
        "cancelled_count" => (int) ($stats['cancelled_count'] ?? 0),
        "all_count" => (int) ($stats['all_count'] ?? 0),
    ],

    // ===== بيانات التصفح =====
    "pagination" => [
        "current_page" => $page,
        "per_page" => $limit,
        "total_count" => $totalCount,
        "total_pages" => $totalPages,
        "has_next_page" => ($page < $totalPages),
        "has_prev_page" => ($page > 1),
    ],
]);
