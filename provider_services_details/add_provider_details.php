<?php
// ============================================================
// ملف: provider_services_details/add_provider_details.php
// الغرض: إضافة تفاصيل خدمة مقدم الخدمة في جدول provider_services
//
// مثال عملي:
//   مقدم خدمة اسمه أحمد (user_id=5) يعمل في:
//   - ضيافة وطهي (cat_id=2) → تنظيف وطبخ (sub_cat_id=8) → إقامة كاملة (timing_id=1)
//   - ضيافة وطهي (cat_id=2) → تنظيف وطبخ (sub_cat_id=8) → يومي (timing_id=2)
//   سيتم عمل INSERT لصفين في جدول provider_services
//
// الطريقة: POST
// ============================================================

include "../connect.php";

// ============================================================
// الخطوة 1: استقبال البيانات
// ============================================================
$user_id = filterRequest("ps_user_id");        // ID مقدم الخدمة
$cat_id = filterRequest("ps_cat_id");          // القسم الرئيسي
$sub_cat_id = filterRequest("ps_sub_cat_id");      // القسم الفرعي
$timing_id = filterRequest("ps_timing_id");       // التوقيت
$price_estimate = filterRequest("ps_price_estimate"); // السعر المبدئي (اختياري)
$price = (float) filterRequest("ps_price");           // السعر المحدد
$negotiable = filterRequest("ps_negotiable");      // قابل للتفاوض؟ 0 أو 1
$commission_type = filterRequest("ps_commission_type"); // fixed أو percent
$commission_value = (float) filterRequest("ps_commission_value"); // قيمة العمولة
$commission_value = ($commission_value == "") ? 0 : (float) $commission_value;

// ============================================================
// الخطوة 2: التحقق من الحقول الإجبارية
// ============================================================
if (
    empty($user_id) || empty($cat_id) || empty($sub_cat_id) ||
    empty($timing_id) || empty($price)
) {
    printFailure("Required fields: user_id, cat_id, sub_cat_id, timing_id, price");
    exit;
}

// ============================================================
// الخطوة 3: التحقق من أن مقدم الخدمة أكمل ملفه الشخصي
// يجب أن يكون providers_is_verified = 1 قبل إضافة خدمات
// ============================================================
$stmtVerify = $con->prepare(
    "SELECT providers_is_verified FROM professional_profiles
     WHERE providers_users_id = ? LIMIT 1"
);
$stmtVerify->execute([$user_id]);
$profile = $stmtVerify->fetch(PDO::FETCH_ASSOC);

if (!$profile) {
    printFailure("Please complete your profile first");
    exit;
}

// ملاحظة: يمكن تخفيف هذا الشرط وإضافة الخدمات حتى قبل التحقق
// إذا أردت السماح بذلك، احذف هذا التحقق
// if ($profile['providers_is_verified'] != 1) {
//     printFailure("Profile not verified yet. Please wait for admin approval.");
//     exit;
// }

// ============================================================
// الخطوة 4: التحقق من أن الـ sub_category تنتمي للـ category الصحيحة
// ============================================================
$stmtSub = $con->prepare(
    "SELECT sub_id FROM sub_categories
     WHERE sub_id = ? AND sub_category_id = ? LIMIT 1"
);
$stmtSub->execute([$sub_cat_id, $cat_id]);
if ($stmtSub->rowCount() === 0) {
    printFailure("Sub category does not belong to the selected category");
    exit;
}

// ============================================================
// الخطوة 5: التحقق من عدم وجود تكرار
// مقدم الخدمة لا يمكنه إضافة نفس الخدمة + التوقيت مرتين
// ============================================================
$stmtDup = $con->prepare(
    "SELECT ps_id FROM provider_services
     WHERE ps_user_id = ? AND ps_sub_cat_id = ? AND ps_timing_id = ?
     LIMIT 1"
);
$stmtDup->execute([$user_id, $sub_cat_id, $timing_id]);
if ($stmtDup->rowCount() > 0) {
    printFailure("You already offer this service with this timing");
    exit;
}

// ============================================================
// الخطوة 6: حساب العمولة الفعلية (A)
// ⭐ عمولة مقدم الخدمة المخفية عن العميل:
//    إذا كانت النسبة مئوية → نحسب القيمة الفعلية بالجنيه
//    إذا كانت مبلغ ثابت    → نستخدمه مباشرة
// ⚠️ نُخزّن المبلغ ثابتاً (ps_commission_amount) لسهولة الجمع على السعر
// ============================================================
$commissionAmount = 0;
if ($commission_type === 'percent' && $price > 0) {
    // حساب نسبة العمولة من السعر
    // مثال: سعر 10 ج.م × 10% = 1 ج.م عمولة
    $commissionAmount = ($price * $commission_value) / 100;
} else {
    // مبلغ ثابت
    $commissionAmount = $commission_value;
}

// ⭐ تقريب مبلغ العمولة لخانتين عشريتين قبل تخزينه
$commissionAmount = round((float) $commissionAmount, 2);

// السعر الإجمالي الذي يراه العميل = السعر + العمولة (A)
// مثال: 10 + 1 = 11 ج.م
$totalPriceForClient = (float) $price + (float) $commissionAmount;

// ============================================================
// الخطوة 7: إدخال البيانات في جدول provider_services
// ============================================================
$insertData = [
    "ps_user_id" => $user_id,
    "ps_cat_id" => $cat_id,
    "ps_sub_cat_id" => $sub_cat_id,
    "ps_timing_id" => $timing_id,
    "ps_price_estimate" => $price_estimate ?: null,
    "ps_price" => $price,
    "ps_negotiable" => (int) ($negotiable ?? 0),
    "ps_commission_type" => $commission_type ?: 'percent',
    "ps_commission_value" => $commission_value ?: 10,
    // ⭐ مبلغ عمولة مقدم الخدمة (A) بالجنيه بعد الحساب (مخفية عن العميل)
    "ps_commission_amount" => $commissionAmount,
];

$result = insertData("provider_services", $insertData, false);

// ============================================================
// الخطوة 8: إرسال الرد مع تفاصيل العمولة
// ============================================================
if ($result > 0) {
    echo json_encode([
        "status" => "success",
        "message" => "Service added successfully",
        "data" => [
            "ps_id" => $con->lastInsertId(),
            "ps_price" => (float) $price,
            "commission_amount" => round($commissionAmount, 2),
            "total_for_client" => round($totalPriceForClient, 2),
            "ps_negotiable" => (int) ($negotiable ?? 0),
        ],
    ]);
} else {
    printFailure("Failed to add service. Please try again.");
}
?>