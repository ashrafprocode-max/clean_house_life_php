<?php
// ============================================================
// ملف: bookings/customer/list_my_bookings.php
// الغرض: قائمة حجوزات المستخدم الحالي (عميل أو مقدم خدمة يحجز خدمة)
//        مع فلترة الحالة + Pagination بحجم 10 معاملات في الصفحة
//
// ⭐ الحالات (booking_status):
//   pending      = قيد الانتظار / قيد المراجعة
//   negotiating  = قيد التفاوض
//   approved     = تمت الموافقة
//   in_progress  = قيد التنفيذ
//   completed    = مكتملة
//   cancelled    = ملغاة
//   all          = الكل (بدون فلتر حالة)
//
// ⭐ القائمة تعرض حجوزات الطالب فقط (booking_users_id)
//    حتى لو كان المستخدم مقدّم خدمة وحجز خدمة لمقدّم آخر
//
// الحقول: users_id (إجباري) · status · page · limit
// الطريقة: POST
// ============================================================

// ربط ملف الاتصال بقاعدة البيانات (مستويان للوراء لأن الملف داخل bookings/customer)
include "../../connect.php";

// ============================================================
// الخطوة 1: استقبال معرّف المستخدم صاحب الحجوزات
// ============================================================
// معرّف المستخدم الحالي من التطبيق (client أو provider)
$users_id = filterRequest("users_id");
// إن لم يُرسل users_id نجرّب الاسم القديم المستخدم في add_booking
if ($users_id === '') {
    // قراءة المعرّف بالحقل البديل لضمان التوافق
    $users_id = filterRequest("booking_users_id");
}

// حالة الحجز المطلوبة للفلترة (all تعني بلا فلتر)
$status = trim(filterRequest("status"));

// ============================================================
// الخطوة 2: التحقق من الحقل الإجباري
// ============================================================
// لا نعرض حجوزات أحد بدون معرّف المستخدم
if (empty($users_id)) {
    // رسالة واضحة للتطبيق
    printFailure("users_id is required");
    // إيقاف التنفيذ
    exit;
}

// تحويل المعرّف لرقم صحيح لحماية جملة WHERE
$users_id = (int) $users_id;

// رفض المعرّف الصفري لأنه غير صالح
if ($users_id <= 0) {
    // رسالة أن المعرّف غير صالح
    printFailure("users_id is invalid");
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 3: التحقق من أن المستخدم موجود وحسابه نشط
// ============================================================
// تجهيز استعلام آمن للبحث عن المستخدم
$stmtUser = $con->prepare(
    "SELECT users_id, users_full_name, users_type
     FROM users
     WHERE users_id = ? AND users_is_active = 1
     LIMIT 1"
);
// تنفيذ الاستعلام بتمرير معرّف المستخدم
$stmtUser->execute([$users_id]);
// جلب صف المستخدم كمصفوفة
$user = $stmtUser->fetch(PDO::FETCH_ASSOC);

// إذا لم يوجد المستخدم أو حسابه غير مفعّل نرفض الطلب
if (!$user) {
    // رسالة توضح أن الحساب غير صالح
    printFailure("User not found or account is not active");
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 4: معاملات Pagination
// الافتراضي 10 كما طلبت الواجهة، والحد الأقصى 50
// ============================================================
// رقم الصفحة الحالية - لا تقل عن 1
$page = max(1, (int) (filterRequest("page") ?: 1));
// عدد النتائج في الصفحة - لا تقل عن 1 ولا تزيد عن 50
$limit = min(50, max(1, (int) (filterRequest("limit") ?: 10)));
// نقطة البداية في قاعدة البيانات
$offset = ($page - 1) * $limit;

// ============================================================
// الخطوة 5: بناء شروط WHERE
// الشرط الثابت: حجوزات هذا المستخدم فقط
// ============================================================
// مصفوفة الشروط المشتركة بين استعلام العدد واستعلام البيانات
$whereConditions = ["b.booking_users_id = ?"];
// معاملات الاستعلام الرئيسي
$params = [$users_id];
// معاملات استعلام العدد (نفس القيم)
$countParams = [$users_id];

// ============================================================
// الخطوة 6: فلتر حالة الحجز
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

// دمج كل الشروط في جملة WHERE واحدة مفصولة بـ AND
$whereSQL = "WHERE " . implode(" AND ", $whereConditions);

// ============================================================
// الخطوة 7: استعلام العدد الإجمالي
// مهم لحساب عدد الصفحات في الواجهة
// ============================================================
// جملة العد على الحجوزات المطابقة للفلتر
$countSQL = "
    SELECT COUNT(b.booking_id) AS total
    FROM bookings b
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
// الخطوة 8: استعلام عدد الحجوزات في كل حالة لهذا المستخدم
// لعرض شارات العدد على أزرار الفلترة في التطبيق
// ============================================================
// جملة الإحصائيات بدون فلتر حالة حتى تظهر الأعداد كاملة
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
    WHERE b.booking_users_id = ?
";
// تجهيز استعلام الإحصائيات
$statsStmt = $con->prepare($statsSQL);
// تنفيذه بمعرّف المستخدم فقط
$statsStmt->execute([$users_id]);
// قراءة صف الإحصائيات
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 9: الاستعلام الرئيسي مع LIMIT و OFFSET
// نجلب بيانات الحجز + اسم المزود + صورة + المهنة + السعر
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
        b.booking_ps_commission_amount,
        b.booking_client_commission_type,
        b.booking_client_commission_value,
        b.booking_client_commission_amount,
        b.booking_total_price,
        b.booking_coupon,
        b.booking_confirmation,
        b.booking_vailability_status,
        b.booking_approval,
        b.booking_status,
        b.booking_number_of_requests,
        b.booking_created_at,
        b.booking_updated_at,
        pu.users_id              AS provider_users_id,
        pu.users_full_name       AS provider_full_name,
        pp.providers_profile_image,
        pp.providers_rating,
        pp.providers_gender,
        c.categories_name_ar,
        c.categories_name_en,
        sc.sub_name_ar,
        sc.sub_name_en,
        st.timing_name_ar,
        st.timing_name_en,
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
    $whereSQL
    ORDER BY b.booking_created_at DESC
    LIMIT $limit OFFSET $offset
";
// تجهيز الاستعلام الرئيسي
$stmt = $con->prepare($sql);
// تنفيذه بمعاملات البيانات
$stmt->execute($params);
// جلب كل الصفوف كمصفوفة
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 10: بناء رابط الصورة الشخصية لمقدم الخدمة
// نرجع ثلاثة مستويات للوصول لجذر المشروع
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
// دالة مساعدة تحوّل رمز الحالة إلى نص عربي للواجهة
// ============================================================
$statusLabelAr = function (?string $statusKey): string {
    // خريطة الرموز إلى العناوين العربية
    $map = [
        'pending' => 'قيد الانتظار',
        'negotiating' => 'قيد التفاوض',
        'approved' => 'تمت الموافقة',
        'in_progress' => 'قيد التنفيذ',
        'completed' => 'مكتملة',
        'cancelled' => 'ملغاة',
    ];
    // إن لم تُعرف الحالة نُرجع النص كما هو أو فارغاً
    return $map[$statusKey] ?? (string) $statusKey;
};

// ============================================================
// الخطوة 11: تحويل كل سجل لشكل مناسب لـ Flutter
// ============================================================
$resultData = array_map(function ($row) use ($baseUrl, $statusLabelAr) {
    // إرجاع مصفوفة السجل بالشكل الذي يقرأه موديل Flutter
    return [
        // معرّف الحجز لفتح صفحة التفاصيل
        "booking_id" => (int) $row['booking_id'],
        // معرّف المستخدم صاحب الحجز
        "booking_users_id" => (int) $row['booking_users_id'],
        // معرّف الخدمة المحجوزة لإعادة الطلب لاحقاً
        "booking_ps_id" => (int) $row['booking_ps_id'],
        // تفاصيل الطلب كما كتبها المستخدم
        "booking_order_details" => $row['booking_order_details'],
        // ⭐ سعر الخدمة الظاهر للعميل = الأساسي + عمولة مقدم الخدمة المخفية
        "booking_ps_price" => round(
            (float) $row['booking_ps_price'] + (float) $row['booking_ps_commission_amount'],
            2
        ),
        // سعر مقدم الخدمة الأساسي (للاستخدام الداخلي)
        "provider_base_price" => (float) $row['booking_ps_price'],
        // هل السعر قابل للتفاوض
        "booking_ps_negotiable" => (bool) $row['booking_ps_negotiable'],
        // ⭐ نوع عمولة العميل (B)
        "booking_client_commission_type" => $row['booking_client_commission_type'],
        // ⭐ قيمة/نسبة عمولة العميل (B)
        "booking_client_commission_value" => (float) $row['booking_client_commission_value'],
        // ⭐ مبلغ عمولة العميل (B) بالجنيه
        "booking_client_commission_amount" => (float) $row['booking_client_commission_amount'],
        // الإجمالي النهائي
        "booking_total_price" => (float) $row['booking_total_price'],
        // تأكيد العميل
        "booking_confirmation" => (int) $row['booking_confirmation'],
        // تاريخ بدء تنفيذ الخدمة إن وُجد
        "booking_vailability_status" => $row['booking_vailability_status'],
        // موافقة الإدارة (0 أو 1)
        "booking_approval" => (int) $row['booking_approval'],
        // رمز حالة الحجز
        "booking_status" => $row['booking_status'],
        // نص الحالة بالعربي للعرض المباشر
        "booking_status_label_ar" => $statusLabelAr($row['booking_status']),
        // تاريخ إنشاء الطلب
        "booking_created_at" => $row['booking_created_at'],
        // تاريخ آخر تحديث
        "booking_updated_at" => $row['booking_updated_at'],
        // اسم الكوبون إن وُجد
        "coupon_name" => $row['coupon_name'],
        // بطاقة مقدم الخدمة المختصرة للصف في القائمة
        "provider" => [
            // معرّف مقدم الخدمة
            "users_id" => (int) $row['provider_users_id'],
            // اسم مقدم الخدمة
            "users_full_name" => $row['provider_full_name'],
            // رابط الصورة أو null
            "profile_image" => $row['providers_profile_image']
                ? $baseUrl . '/upload/provider_profiles/' . $row['providers_profile_image']
                : null,
            // التقييم
            "providers_rating" => $row['providers_rating'] !== null
                ? (float) $row['providers_rating'] : 4.5,
            // النوع
            "providers_gender" => $row['providers_gender'],
        ],
        // بيانات المهنة والقسم للعرض في البطاقة
        "service" => [
            // اسم القسم الرئيسي بالعربي
            "category_name_ar" => $row['categories_name_ar'],
            // اسم القسم الرئيسي بالإنجليزي
            "category_name_en" => $row['categories_name_en'],
            // اسم المهنة بالعربي
            "profession_name_ar" => $row['sub_name_ar'],
            // اسم المهنة بالإنجليزي
            "profession_name_en" => $row['sub_name_en'],
            // اسم التوقيت بالعربي
            "timing_name_ar" => $row['timing_name_ar'],
            // اسم التوقيت بالإنجليزي
            "timing_name_en" => $row['timing_name_en'],
        ],
    ];
}, $bookings);

// ============================================================
// الخطوة 12: إرسال الرد مع بيانات Pagination والإحصائيات
// ============================================================
echo json_encode([
    // حالة العملية
    "status" => "success",
    // رسالة نجاح واضحة
    "message" => "My bookings list fetched successfully",
    // مصفوفة الحجوزات
    "data" => $resultData,
    // إحصائيات لشارات أزرار الفلترة
    "stats" => [
        // عدد قيد الانتظار
        "pending_count" => (int) ($stats['pending_count'] ?? 0),
        // عدد قيد التفاوض
        "negotiating_count" => (int) ($stats['negotiating_count'] ?? 0),
        // عدد الموافق عليها
        "approved_count" => (int) ($stats['approved_count'] ?? 0),
        // عدد قيد التنفيذ
        "in_progress_count" => (int) ($stats['in_progress_count'] ?? 0),
        // عدد المكتملة
        "completed_count" => (int) ($stats['completed_count'] ?? 0),
        // عدد الملغاة
        "cancelled_count" => (int) ($stats['cancelled_count'] ?? 0),
        // العدد الكلي
        "all_count" => (int) ($stats['all_count'] ?? 0),
    ],
    // بيانات التصفح
    "pagination" => [
        // الصفحة الحالية
        "current_page" => $page,
        // حجم الصفحة
        "per_page" => $limit,
        // إجمالي السجلات
        "total_count" => $totalCount,
        // إجمالي الصفحات
        "total_pages" => $totalPages,
        // هل توجد صفحة تالية
        "has_next_page" => ($page < $totalPages),
        // هل توجد صفحة سابقة
        "has_prev_page" => ($page > 1),
    ],
], JSON_UNESCAPED_UNICODE);
