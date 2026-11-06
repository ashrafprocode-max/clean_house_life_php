<?php
// ============================================================
// ملف: sub_categories/get_by_category.php
// الغرض: جلب الأقسام الفرعية بناءً على القسم الرئيسي
// الطريقة: GET
// الحقول: category_id (إجباري)
//
// مثال: GET ?category_id=2
// يرجع: [طبخ يومي, طبخ ومناسبات, تنظيف وطبخ, ...]
// ============================================================

include "../connect.php";

// ============================================================
// الخطوة 1: استقبال ID القسم الرئيسي
// ============================================================
$category_id = filterRequest("category_id");

if (empty($category_id)) {
    printFailure("category_id is required");
    exit;
}

// ============================================================
// الخطوة 2: جلب الأقسام الفرعية المرتبطة بالقسم الرئيسي
// ============================================================
$stmt = $con->prepare(
    "SELECT
        sub_id,
        sub_category_id,
        sub_name_ar,
        sub_name_en
     FROM sub_categories
     WHERE sub_category_id = ?
     ORDER BY sub_id ASC"
);
$stmt->execute([$category_id]);
$subCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 3: التحقق من وجود بيانات
// ============================================================
if (empty($subCategories)) {
    printFailure("No sub-categories found for this category");
    exit;
}

// ============================================================
// الخطوة 4: إرسال الرد
// ============================================================
echo json_encode([
    "status"  => "success",
    "message" => "Sub-categories fetched successfully",
    "count"   => count($subCategories),
    "data"    => array_map(function ($sub) {
        return [
            "sub_id"          => (int)$sub['sub_id'],
            "sub_category_id" => (int)$sub['sub_category_id'],
            "sub_name_ar"     => $sub['sub_name_ar'],
            "sub_name_en"     => $sub['sub_name_en'],
        ];
    }, $subCategories),
]);
?>