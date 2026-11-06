<?php
// ============================================================
// ملف: admin/coupons/list_coupon.php
// الغرض: عرض قائمة كوبونات الخصم للإدارة
//        مع فلترة الحالة + البحث + Pagination
//
// ⭐ حالات الفلترة (status):
//   all      = الكل (افتراضي)
//   active   = فعّال وغير منتهي
//   expired  = انتهى تاريخه
//   inactive = معطّل (حذف ناعم)
//
// ⭐ لكل كوبون نُرجِع أيضاً بيانات الاستخدام:
//   used_count        = عدد الحجوزات التي استخدمته
//   remaining_count   = المتبقي من coupon_count
//   total_discount    = إجمالي قيمة الخصم المُطبَّق على العمولات
//   last_client_*     = آخر عميل استخدم الكوبون (الاسم/الهاتف/واتساب)
//
// ⭐ الخصم يُطبَّق على العمولة فقط لا على سعر مقدم الخدمة
//
// أمثلة الاستخدام:
//   status=all&page=1&limit=20
//   status=active&search=خصم
//
// الطريقة: POST أو GET
// الصلاحية المطلوبة: coupons.manage
// ============================================================

// ربط ملف الاتصال بقاعدة البيانات (مستويان للوراء لأن الملف داخل admin/coupons)
include "../../connect.php";

// ⭐ حماية ملف الإدارة: يجب إرسال المفتاح السري في هيدر X-Admin-Key
// ⚠️ لو أردت تعطيل الحماية مؤقتاً أثناء التطوير ضع // قبل السطر التالي فقط
// checkAdminKey();

// ============================================================
// الخطوة 1: التحقق من التوكن والصلاحية
//
// ⭐ coupons.manage يملكها: owner و manager فقط
//    لأن إدارة الكوبونات من شأن المالك والمدير
// ============================================================
// التحقق من التوكن وجلب بيانات الموظف مع صلاحياته
$currentAdmin = checkAdminToken();

// ⭐ فرض صلاحية إدارة الكوبونات
requirePermission($currentAdmin, 'coupons.manage');

// ============================================================
// الخطوة 2: استقبال معاملات الفلترة والبحث
// ============================================================
// حالة الكوبون المطلوبة
$status = filterRequest("status");
// نص البحث في اسم الكوبون أو اسم/هاتف العميل
$search = trim(filterRequest("search"));

// ============================================================
// الخطوة 3: معاملات Pagination
// max() و min() لضمان قيم منطقية
// ============================================================
// رقم الصفحة الحالية - لا تقل عن 1
$page  = max(1, (int)(filterRequest("page") ?: 1));
// عدد النتائج - لا يقل عن 1 ولا يزيد عن 50
$limit = min(50, max(1, (int)(filterRequest("limit") ?: 20)));
// نقطة البداية في قاعدة البيانات
$offset = ($page - 1) * $limit;

// ============================================================
// الخطوة 4: بناء شروط WHERE المشتركة بين الاستعلامين
// ============================================================
// شرط ثابت دائماً صحيح لتبسيط دمج الشروط بعده
$whereConditions = ["1 = 1"];

// مصفوفتان منفصلتان للمعاملات: واحدة للعدد وأخرى للبيانات
$params      = [];
$countParams = [];

// ============================================================
// الخطوة 5: فلتر حالة الكوبون
// ============================================================
// قائمة الحالات المسموحة
$allowedStatuses = ['all', 'active', 'expired', 'inactive'];

if ($status !== '' && in_array($status, $allowedStatuses, true)) {
    if ($status === 'active') {
        // ⭐ فعّال: غير معطّل ولم ينتهِ تاريخه
        $whereConditions[] = "c.coupon_is_active = 1 AND c.coupon_expiredate > NOW()";
    } elseif ($status === 'expired') {
        // ⭐ منتهي الصلاحية بغض النظر عن التفعيل
        $whereConditions[] = "c.coupon_expiredate <= NOW()";
    } elseif ($status === 'inactive') {
        // ⭐ معطّل بالحذف الناعم
        $whereConditions[] = "c.coupon_is_active = 0";
    }
    // all تعني بلا فلترة
}

// ============================================================
// الخطوة 6: فلتر البحث باسم الكوبون أو اسم/هاتف العميل
// ============================================================
if ($search !== '') {
    // الأقواس مهمة حتى لا يتعارض OR مع شروط AND الأخرى
    $whereConditions[] = "("
        . " c.coupon_name LIKE ?"
        . " OR EXISTS ("
        . "     SELECT 1 FROM bookings bs"
        . "     INNER JOIN users us ON us.users_id = bs.booking_users_id"
        . "     WHERE bs.booking_coupon = c.coupon_id"
        . "       AND bs.booking_coupon > 0"
        . "       AND (us.users_full_name LIKE ? OR us.users_phone LIKE ?)"
        . " )"
        . ")";
    // تجهيز نص البحث مع % قبله وبعده للبحث الجزئي
    $searchTerm = "%{$search}%";
    // تمرير النص ثلاث مرات (اسم الكوبون + اسم العميل + هاتف العميل)
    $params[]      = $searchTerm;
    $params[]      = $searchTerm;
    $params[]      = $searchTerm;
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
    SELECT COUNT(c.coupon_id) AS total
    FROM coupon c
    $whereSQL
";
// تجهيز الاستعلام
$countStmt = $con->prepare($countSQL);
// تنفيذه بمعاملات العدد
$countStmt->execute($countParams);
// قراءة الرقم الناتج
$totalCount = (int)$countStmt->fetchColumn();

// حساب إجمالي الصفحات (نرفع الكسر دائماً)
$totalPages = $limit > 0 ? (int)ceil($totalCount / $limit) : 0;

// ============================================================
// الخطوة 8: استعلام عدد الكوبونات في كل حالة
// لعرض شارات العدد على أزرار الفلترة في لوحة التحكم
// ============================================================
$statsSQL = "
    SELECT
        COUNT(*) AS all_count,
        SUM(CASE WHEN coupon_is_active = 1 AND coupon_expiredate > NOW()
                 THEN 1 ELSE 0 END) AS active_count,
        SUM(CASE WHEN coupon_expiredate <= NOW()
                 THEN 1 ELSE 0 END) AS expired_count,
        SUM(CASE WHEN coupon_is_active = 0
                 THEN 1 ELSE 0 END) AS inactive_count
    FROM coupon
";
// تنفيذ استعلام الإحصائيات بدون معاملات
$statsStmt = $con->prepare($statsSQL);
$statsStmt->execute();
// قراءة صف الإحصائيات
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 9: الاستعلام الرئيسي مع LIMIT و OFFSET
// ============================================================
$sql = "
    SELECT
        c.coupon_id,
        c.coupon_name,
        c.coupon_count,
        c.coupon_discount,
        c.coupon_expiredate,
        c.coupon_is_active,

        -- ===== عدد مرات الاستخدام =====
        COUNT(b.booking_id) AS used_count,

        -- ===== إجمالي قيمة الخصم المُطبَّق على العمولات =====
        COALESCE(SUM(
            CASE
                WHEN b.booking_ps_commission_type = 'percent'
                    THEN (b.booking_ps_price * b.booking_ps_commission_value / 100)
                ELSE b.booking_ps_commission_value
            END * c.coupon_discount / 100
        ), 0) AS total_discount,

        -- ===== آخر عميل استخدم الكوبون =====
        (SELECT u.users_full_name
           FROM bookings b2
           INNER JOIN users u ON u.users_id = b2.booking_users_id
          WHERE b2.booking_coupon = c.coupon_id AND b2.booking_coupon > 0
          ORDER BY b2.booking_created_at DESC
          LIMIT 1) AS last_client_name,
        (SELECT u.users_phone
           FROM bookings b2
           INNER JOIN users u ON u.users_id = b2.booking_users_id
          WHERE b2.booking_coupon = c.coupon_id AND b2.booking_coupon > 0
          ORDER BY b2.booking_created_at DESC
          LIMIT 1) AS last_client_phone,
        (SELECT b2.booking_created_at
           FROM bookings b2
          WHERE b2.booking_coupon = c.coupon_id AND b2.booking_coupon > 0
          ORDER BY b2.booking_created_at DESC
          LIMIT 1) AS last_booking_at

    FROM coupon c
    LEFT JOIN bookings b
        ON b.booking_coupon = c.coupon_id AND b.booking_coupon > 0
    $whereSQL
    GROUP BY
        c.coupon_id, c.coupon_name, c.coupon_count,
        c.coupon_discount, c.coupon_expiredate, c.coupon_is_active
    ORDER BY c.coupon_id DESC
    LIMIT $limit OFFSET $offset
";
// تجهيز الاستعلام الرئيسي
$stmt = $con->prepare($sql);
// تنفيذه بمعاملات البيانات
$stmt->execute($params);
// جلب كل الصفوف كمصفوفة
$coupons = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 10: دالة مساعدة تبني رابط واتساب من رقم الهاتف
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
    // إذا لم يتبقَّ أرقام نُرجع null
    if ($digits === '') {
        return null;
    }
    // رابط واتساب جاهز للفتح من اللوحة
    return 'https://wa.me/' . $digits;
};

// هل يملك الموظف تعديل/حذف؟ (لأزرار الواجهة)
$canEdit   = adminCan($currentAdmin, 'coupons.manage');
$canDelete = adminCan($currentAdmin, 'coupons.manage');

// ============================================================
// الخطوة 11: تحويل كل سجل لشكل مناسب لـ Flutter
// ============================================================
$resultData = array_map(function ($row) use ($whatsappUrl, $canEdit, $canDelete) {
    // عدد مرات الاستخدام الفعلي
    $usedCount = (int)$row['used_count'];
    // إجمالي الاستخدامات المسموح بها
    $allowedCount = (int)$row['coupon_count'];
    // المتبقي لا يكون سالباً أبداً
    $remaining = $allowedCount - $usedCount;
    if ($remaining < 0) {
        $remaining = 0;
    }
    // هل انتهى تاريخ الكوبون؟
    $isExpired = strtotime($row['coupon_expiredate']) <= time();
    // هل الكوبون فعّال الآن؟
    $isActive = (int)$row['coupon_is_active'] === 1 && !$isExpired;

    // إرجاع مصفوفة السجل بالشكل الذي يقرأه موديل Flutter
    return [
        // معرّف الكوبون (نحتاجه للتعديل والحذف)
        "coupon_id"           => (int)$row['coupon_id'],
        // اسم الكوبون
        "coupon_name"         => $row['coupon_name'],
        // عدد مرات الاستخدام الكلي المسموح
        "coupon_count"        => $allowedCount,
        // نسبة الخصم من العمولة
        "coupon_discount"     => (int)$row['coupon_discount'],
        // تاريخ ووقت انتهاء الصلاحية
        "coupon_expiredate"   => $row['coupon_expiredate'],
        // 1 = فعّال, 0 = معطّل (حذف ناعم)
        "coupon_is_active"    => (int)$row['coupon_is_active'],
        // توضيح طريقة عمل الخصم
        "discount_applies_on" => "commission_only",

        // ===== ملخّص الاستخدام =====
        "used_count"          => $usedCount,
        "remaining_count"     => $remaining,
        // إجمالي قيمة الخصم المُطبَّق على العمولات
        "total_discount"      => round((float)$row['total_discount'], 2),

        // ===== حالة العرض =====
        "is_expired"          => $isExpired,
        "is_active"           => $isActive,

        // ===== آخر عميل استخدم الكوبون =====
        "last_client_name"    => $row['last_client_name'],
        "last_client_phone"   => $row['last_client_phone'],
        "last_client_whatsapp"=> $whatsappUrl($row['last_client_phone']),
        "last_booking_at"     => $row['last_booking_at'],

        // أزرار الواجهة حسب صلاحية الموظف الحالي
        "can_edit"            => $canEdit,
        "can_delete"          => $canDelete,
    ];
}, $coupons);

// ============================================================
// الخطوة 12: إرسال الرد مع بيانات Pagination والإحصائيات
// ============================================================
echo json_encode([
    // حالة العملية
    "status"  => "success",
    // رسالة نجاح واضحة
    "message" => "Coupons list fetched successfully",
    // مصفوفة الكوبونات
    "data"    => $resultData,

    // ===== إحصائيات لشارات أزرار الفلترة =====
    "stats" => [
        "all_count"      => (int)($stats['all_count']      ?? 0),
        "active_count"   => (int)($stats['active_count']   ?? 0),
        "expired_count"  => (int)($stats['expired_count']  ?? 0),
        "inactive_count" => (int)($stats['inactive_count'] ?? 0),
    ],

    // ===== بيانات التصفح =====
    "pagination" => [
        "current_page"  => $page,
        "per_page"      => $limit,
        "total_count"   => $totalCount,
        "total_pages"   => $totalPages,
        "has_next_page" => ($page < $totalPages),
        "has_prev_page" => ($page > 1),
    ],
]);
?>
