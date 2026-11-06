<?php
// ============================================================
// ملف: provider_services_details/View_provider_services_details_screen.php
// الغرض: عرض خدمات مقدم الخدمة (المسجل دخوله فقط) من جدول
//        provider_services دون عرض كل المقدمين كما في صفحة العميل
//
// الفرق بين هذا الملف و view_provider_details.php:
//   - view_provider_details.php: للعميل (يبحث عن كل المقدمين + Pagination)
//   - هذا الملف: للمقدم نفسه (يشاهد خدماته هو فقط بواسطة ps_user_id)
//
// مثال الاستخدام:
//   المقدم أحمد (user_id=5) دخل التطبيق وأكمل ملفه وأضاف خدماته
//   الطلب: GET View_provider_services_details_screen.php?ps_user_id=5
//
// مثال النتيجة:
//   [{
//     ps_id: 10,
//     ps_user_id: 5,
//     users_full_name: "أحمد",
//     category:     { id: 2, name_ar: "ضيافة وطهي", name_en: "Hospitality" },
//     sub_category: { id: 8, name_ar: "تنظيف وطبخ", name_en: "Cleaning" },
//     timing:       { id: 1, name_ar: "إقامة كاملة", name_en: "Full-time" },
//     pricing:      { ps_price: 3000, ... }
//   }]
// ============================================================
 
include "../connect.php";
 
// ============================================================
// الخطوة 1: استقبال معرّف المقدم (إجباري)
// الاسم الأساسي ps_user_id - ولدعم الكود القديم نقبل user_id أيضاً
// ============================================================
$user_id = filterRequest("ps_user_id") ?: filterRequest("user_id");
 
// ============================================================
// الخطوة 2: التحقق من الحقل الإجباري
// لا يمكن عرض الخدمات بدون معرفة صاحب الحساب المسجل دخوله
// ============================================================
if (empty($user_id)) {
    printFailure("ps_user_id is required");
    exit;
}
 
// ============================================================
// الخطوة 3: الاستعلام - نجلب خدمات هذا المقدم فقط
// بواسطة الشرط WHERE ps.ps_user_id = ? فيظهر للمقدم خدماته هو
// ============================================================
$sql = "
    SELECT
        ps.ps_id,
        ps.ps_user_id,
        ps.ps_cat_id,
        ps.ps_sub_cat_id,
        ps.ps_timing_id,
        ps.ps_price,
        ps.ps_price_estimate,
        ps.ps_negotiable,
        ps.ps_commission_type,
        ps.ps_commission_value,
 
        -- حساب قيمة العمولة الفعلية في SQL مباشرة
        CASE
            WHEN ps.ps_commission_type = 'percent'
            THEN ROUND((ps.ps_price * ps.ps_commission_value / 100), 2)
            ELSE ps.ps_commission_value
        END AS commission_amount,
 
        -- السعر الإجمالي للعميل = السعر + العمولة
        CASE
            WHEN ps.ps_commission_type = 'percent'
            THEN ROUND(ps.ps_price + (ps.ps_price * ps.ps_commission_value / 100), 2)
            ELSE ROUND(ps.ps_price + ps.ps_commission_value, 2)
        END AS total_price_for_client,
 
        -- اسم المقدم
        u.users_full_name,
 
        -- أسماء القسم الرئيسي والفرعي والتوقيت
        c.categories_name_ar,
        c.categories_name_en,
        sc.sub_name_ar,
        sc.sub_name_en,
        st.timing_name_ar,
        st.timing_name_en
 
    FROM provider_services ps
    INNER JOIN users u
        ON u.users_id = ps.ps_user_id
    INNER JOIN categories c
        ON c.categories_id = ps.ps_cat_id
    INNER JOIN sub_categories sc
        ON sc.sub_id = ps.ps_sub_cat_id
    INNER JOIN service_timing st
        ON st.timing_id = ps.ps_timing_id
    WHERE ps.ps_user_id = ?
    ORDER BY ps.ps_id DESC
";
 
$stmt = $con->prepare($sql);
$stmt->execute([$user_id]);
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);
 
// ============================================================
// الخطوة 4: تجهيز البيانات بالشكل المناسب لـ Flutter
// نضع كل خدمة داخل كائن واضح يسهل عرضه في البطاقات
// ============================================================
$resultData = array_map(function ($s) {
    return [
        "ps_id"           => (int)$s['ps_id'],
        "ps_user_id"      => (int)$s['ps_user_id'],
        "users_full_name" => $s['users_full_name'],
        "category" => [
            "id"      => (int)$s['ps_cat_id'],
            "name_ar" => $s['categories_name_ar'],
            "name_en" => $s['categories_name_en'],
        ],
        "sub_category" => [
            "id"      => (int)$s['ps_sub_cat_id'],
            "name_ar" => $s['sub_name_ar'],
            "name_en" => $s['sub_name_en'],
        ],
        "timing" => [
            "id"      => (int)$s['ps_timing_id'],
            "name_ar" => $s['timing_name_ar'],
            "name_en" => $s['timing_name_en'],
        ],
        "pricing" => [
            "ps_price"          => (float)$s['ps_price'],
            "ps_price_estimate" => $s['ps_price_estimate']
                ? (float)$s['ps_price_estimate'] : null,
            "negotiable"        => (bool)$s['ps_negotiable'],
            "commission_type"   => $s['ps_commission_type'],
            "commission_value"  => (float)$s['ps_commission_value'],
            "commission_amount" => (float)$s['commission_amount'],
            "total_for_client"  => (float)$s['total_price_for_client'],
        ],
    ];
}, $services);
 
// ============================================================
// الخطوة 5: إرسال الرد
// ============================================================
echo json_encode([
    "status"  => "success",
    "message" => "Provider services fetched successfully",
    "count"   => count($services),
    "data"    => $resultData,
]);
?>