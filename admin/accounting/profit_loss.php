<?php
// ============================================================
// ملف: admin/accounting/profit_loss.php
// الغرض: تقرير الأرباح والخسائر
//        صافي الربح = عمولات الحجوزات − مصروفات الشركة المدفوعة
//
// ⭐ العمولة تُحسب كما في add_booking.php:
//    percent: (booking_ps_price × commission_value) / 100
//    fixed:   commission_value
//    ثم يُخصم كوبون العمولة إن وُجد
//
// الفلاتر: date_from / date_to / booking_status
// الصلاحية: reports.pnl
// الطريقة: POST أو GET
// ============================================================

// ربط ملف الاتصال بقاعدة البيانات
include "../../connect.php";

// التحقق من التوكن وجلب بيانات الموظف مع صلاحياته
$currentAdmin = checkAdminToken();

// فرض صلاحية عرض الأرباح والخسائر
requirePermission($currentAdmin, 'reports.pnl');

// تاريخ البداية
$dateFrom = trim(filterRequest("date_from"));
// تاريخ النهاية
$dateTo = trim(filterRequest("date_to"));
// حالة الحجز للإيراد (افتراضي: الحجوزات المعتمدة والمكتملة والجارية)
$bookingStatus = trim(filterRequest("booking_status"));

// الحالات المسموحة للفلتر
$allowedBookingStatuses = [
    'pending', 'negotiating', 'approved', 'in_progress', 'completed', 'cancelled', 'all',
];

// إن لم تُرسل حالة نستخدم الإيراد الفعلي فقط
if ($bookingStatus === '') {
    // approved + in_progress + completed
    $bookingStatus = 'revenue';
}

// تصحيح تواريخ غير صالحة
if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    // إفراغ التاريخ غير الصالح
    $dateFrom = '';
}
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    // إفراغ التاريخ غير الصالح
    $dateTo = '';
}

// ============================================================
// شروط الحجوزات
// ============================================================
$bookingWhere = ["1 = 1"];
// معاملات الحجوزات
$bookingParams = [];

// فلتر الحالة
if ($bookingStatus === 'revenue') {
    // الإيراد من الحجوزات المؤكدة أو قيد التنفيذ أو المكتملة
    $bookingWhere[] = "b.booking_status IN ('approved', 'in_progress', 'completed')";
} elseif ($bookingStatus === 'all') {
    // كل الحالات ما عدا الملغى
    $bookingWhere[] = "b.booking_status <> 'cancelled'";
} elseif (in_array($bookingStatus, $allowedBookingStatuses, true) && $bookingStatus !== 'all') {
    // حالة واحدة محددة
    $bookingWhere[] = "b.booking_status = ?";
    // إضافة القيمة
    $bookingParams[] = $bookingStatus;
}

// تاريخ البداية على تاريخ إنشاء الحجز
if ($dateFrom !== '') {
    // من هذا اليوم
    $bookingWhere[] = "DATE(b.booking_created_at) >= ?";
    // إضافة القيمة
    $bookingParams[] = $dateFrom;
}
// تاريخ النهاية
if ($dateTo !== '') {
    // حتى هذا اليوم
    $bookingWhere[] = "DATE(b.booking_created_at) <= ?";
    // إضافة القيمة
    $bookingParams[] = $dateTo;
}

// دمج شروط الحجوزات
$bookingWhereSql = implode(' AND ', $bookingWhere);

// ============================================================
// حساب العمولة بعد الكوبون لكل حجز ثم التجميع في PHP
// لأن العمولة تعتمد على نوع fixed/percent وكوبون coupon.discount
// ============================================================
$bookingSQL = "
    SELECT
        b.booking_id,
        b.booking_ps_price,
        b.booking_ps_commission_type,
        b.booking_ps_commission_value,
        b.booking_total_price,
        b.booking_status,
        b.booking_coupon,
        b.booking_created_at,
        COALESCE(c.coupon_discount, 0) AS coupon_discount
    FROM bookings b
    LEFT JOIN coupon c ON c.coupon_id = b.booking_coupon AND b.booking_coupon > 0
    WHERE $bookingWhereSql
";
// تجهيز استعلام الحجوزات
$bStmt = $con->prepare($bookingSQL);
// تنفيذه
$bStmt->execute($bookingParams);
// جلب الصفوف
$bookings = $bStmt->fetchAll(PDO::FETCH_ASSOC);

// إجمالي العمولات بعد الكوبون
$commissionTotal = 0.0;
// عدد الحجوزات الداخلة في الإيراد
$bookingsCount = 0;
// تفصيل حسب الحالة
$statusBreakdown = [];

// المرور على كل حجز لحساب العمولة
foreach ($bookings as $row) {
    // سعر مقدم الخدمة
    $price = (float)$row['booking_ps_price'];
    // نوع العمولة
    $type = $row['booking_ps_commission_type'] ?: 'percent';
    // قيمة العمولة
    $value = (float)$row['booking_ps_commission_value'];
    // نسبة خصم الكوبون من العمولة
    $couponPct = (float)$row['coupon_discount'];

    // حساب العمولة قبل الكوبون
    if ($type === 'percent' && $price > 0) {
        // نسبة من سعر الخدمة
        $commissionBefore = ($price * $value) / 100;
    } else {
        // مبلغ ثابت
        $commissionBefore = $value;
    }

    // خصم الكوبون من العمولة فقط
    $couponAmount = 0.0;
    // إن وُجدت نسبة خصم
    if ($couponPct > 0 && $commissionBefore > 0) {
        // قيمة الخصم
        $couponAmount = ($commissionBefore * $couponPct) / 100;
    }
    // العمولة بعد الكوبون
    $commissionAfter = $commissionBefore - $couponAmount;
    // لا تقل عن صفر
    if ($commissionAfter < 0) {
        // تصحيح السالب
        $commissionAfter = 0;
    }

    // تجميع الإجمالي
    $commissionTotal += $commissionAfter;
    // زيادة العدد
    $bookingsCount++;

    // تجميع حسب الحالة
    $st = $row['booking_status'];
    // تهيئة الحالة إن لم توجد
    if (!isset($statusBreakdown[$st])) {
        // عدّاد ومبلغ ابتدائيان
        $statusBreakdown[$st] = ['count' => 0, 'commission' => 0.0];
    }
    // زيادة العدّاد
    $statusBreakdown[$st]['count']++;
    // زيادة مبلغ الحالة
    $statusBreakdown[$st]['commission'] += $commissionAfter;
}

// تقريب إجمالي العمولات
$commissionTotal = round($commissionTotal, 2);

// ============================================================
// شروط المصروفات المدفوعة فقط
// ============================================================
$expWhere = ["e.expense_status = 'paid'"];
// معاملات المصروفات
$expParams = [];

// تاريخ البداية على تاريخ المصروف
if ($dateFrom !== '') {
    // من هذا اليوم
    $expWhere[] = "e.expense_date >= ?";
    // إضافة القيمة
    $expParams[] = $dateFrom;
}
// تاريخ النهاية
if ($dateTo !== '') {
    // حتى هذا اليوم
    $expWhere[] = "e.expense_date <= ?";
    // إضافة القيمة
    $expParams[] = $dateTo;
}

// دمج شروط المصروفات
$expWhereSql = implode(' AND ', $expWhere);

// إجمالي المصروفات المدفوعة حسب التصنيف
$expSQL = "
    SELECT
        e.expense_category,
        COUNT(e.expense_id) AS items_count,
        COALESCE(SUM(e.expense_amount), 0) AS category_total
    FROM company_expenses e
    WHERE $expWhereSql
    GROUP BY e.expense_category
";
// تجهيز استعلام المصروفات
$eStmt = $con->prepare($expSQL);
// تنفيذه
$eStmt->execute($expParams);
// جلب الصفوف
$expRows = $eStmt->fetchAll(PDO::FETCH_ASSOC);

// ترجمة التصنيف
$catAr = [
    'salary'      => 'رواتب',
    'rent'        => 'إيجار',
    'utilities'   => 'مرافق',
    'marketing'   => 'تسويق',
    'supplies'    => 'مستلزمات',
    'transport'   => 'انتقالات',
    'maintenance' => 'صيانة',
    'other'       => 'أخرى',
];

// إجمالي المصروفات
$expensesTotal = 0.0;
// تفصيل التصنيفات
$categoryBreakdown = [];
// المرور على التصنيفات
foreach ($expRows as $er) {
    // مبلغ التصنيف
    $catTotal = (float)$er['category_total'];
    // تجميع الإجمالي
    $expensesTotal += $catTotal;
    // إضافة صف التفصيل
    $categoryBreakdown[] = [
        "category"    => $er['expense_category'],
        "category_ar" => $catAr[$er['expense_category']] ?? $er['expense_category'],
        "count"       => (int)$er['items_count'],
        "total"       => round($catTotal, 2),
    ];
}
// تقريب إجمالي المصروفات
$expensesTotal = round($expensesTotal, 2);

// صافي الربح = العمولات − المصروفات
$netProfit = round($commissionTotal - $expensesTotal, 2);

// تفصيل حالات الحجوزات للواجهة
$bookingStatusOut = [];
// المرور على الحالات
foreach ($statusBreakdown as $st => $info) {
    // إضافة صف الحالة
    $bookingStatusOut[] = [
        "status"      => $st,
        "count"       => (int)$info['count'],
        "commission"  => round($info['commission'], 2),
    ];
}

// إرسال الرد
echo json_encode([
    "status"  => "success",
    "message" => "Profit and loss report fetched successfully",
    "data"    => [
        "date_from"          => $dateFrom !== '' ? $dateFrom : null,
        "date_to"            => $dateTo !== '' ? $dateTo : null,
        "booking_status"     => $bookingStatus,
        "bookings_count"     => $bookingsCount,
        "commission_total"   => $commissionTotal,
        "expenses_total"     => $expensesTotal,
        "net_profit"         => $netProfit,
        "is_profit"          => $netProfit >= 0,
        "booking_breakdown"  => $bookingStatusOut,
        "expense_breakdown"  => $categoryBreakdown,
    ],
], JSON_UNESCAPED_UNICODE);
