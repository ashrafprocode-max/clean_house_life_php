<?php
// ============================================================
// ملف: service_timing/get_all.php
// الغرض: جلب جميع أوقات الخدمة لعرضها في DropDown
// الطريقة: GET - لا يحتاج معاملات
// ============================================================

include "../connect.php";

// ============================================================
// الخطوة 1: جلب جميع الأوقات
// ============================================================
$stmt = $con->prepare(
    "SELECT timing_id, timing_name_ar, timing_name_en
     FROM service_timing
     ORDER BY timing_id ASC"
);
$stmt->execute();
$timings = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($timings)) {
    printFailure("No service timings found");
    exit;
}

// ============================================================
// الخطوة 2: إضافة معلومات العمولة الافتراضية لكل وقت
// هذه المعلومات تساعد Flutter في عرض الإرشادات للمقدم
// ============================================================
$commissionGuide = [
    // إقامة كاملة → عمولة = شهر راتب كامل (fixed = السعر كاملاً)
    'Full Residence' => ['type' => 'fixed',   'note' => 'Commission = 1 month salary'],
    // يومي → 50 ج.م لكل زيارة
    'Daily Visit'    => ['type' => 'fixed',   'note' => 'Commission = fixed 50 EGP per visit'],
    // ساعات → 10% من السعر
    'Hourly Task'    => ['type' => 'percent', 'note' => 'Commission = 10% of service price'],
    // أسبوعي → 10%
    'Weekly'         => ['type' => 'percent', 'note' => 'Commission = 10% of weekly rate'],
    // شهري → شهر كامل
    'Monthly'        => ['type' => 'fixed',   'note' => 'Commission = 1 month salary'],
];

// ============================================================
// الخطوة 3: إرسال الرد مع دليل العمولة
// ============================================================
echo json_encode([
    "status"  => "success",
    "message" => "Service timings fetched successfully",
    "count"   => count($timings),
    "data"    => array_map(function ($timing) use ($commissionGuide) {
        // جلب إرشاد العمولة لهذا الوقت
        $guide = $commissionGuide[$timing['timing_name_en']] ?? [
            'type' => 'percent',
            'note' => 'Commission = 10%',
        ];

        return [
            "timing_id"      => (int)$timing['timing_id'],
            "timing_name_ar" => $timing['timing_name_ar'],
            "timing_name_en" => $timing['timing_name_en'],
            // إرشاد العمولة الافتراضي (قابل للتعديل من مقدم الخدمة)
            "default_commission_type"  => $guide['type'],
            "commission_note"          => $guide['note'],
        ];
    }, $timings),
]);
?>