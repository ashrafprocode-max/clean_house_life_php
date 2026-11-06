<?php
// ============================================================
// ملف: provider_services_details/edit_provider_details.php
// الغرض: تعديل تفاصيل خدمة موجودة في جدول provider_services
// الحقول الإجبارية: ps_id, ps_user_id
// ============================================================

include "../connect.php";

// ============================================================
// الخطوة 1: استقبال البيانات
// ============================================================
$ps_id           = filterRequest("ps_id");             // ID الخدمة المراد تعديلها
$user_id         = filterRequest("ps_user_id");        // للتحقق من الملكية
$price           = filterRequest("ps_price");
$price_estimate  = filterRequest("ps_price_estimate");
$negotiable      = filterRequest("ps_negotiable");
$commission_type = filterRequest("ps_commission_type");
$commission_value= filterRequest("ps_commission_value");

// ============================================================
// الخطوة 2: التحقق من الحقول الإجبارية
// ============================================================
if (empty($ps_id) || empty($user_id)) {
    printFailure("ps_id and ps_user_id are required");
    exit;
}

// ============================================================
// الخطوة 3: التحقق من ملكية الخدمة
// مقدم الخدمة يعدل خدمته فقط
// ============================================================
$stmtCheck = $con->prepare(
    "SELECT ps_id FROM provider_services
     WHERE ps_id = ? AND ps_user_id = ? LIMIT 1"
);
$stmtCheck->execute([$ps_id, $user_id]);

if ($stmtCheck->rowCount() === 0) {
    printFailure("Service not found or you don't have permission to edit it");
    exit;
}

// ============================================================
// الخطوة 4: بناء بيانات التحديث ديناميكياً
// نُحدّث فقط الحقول المُرسَلة
// ============================================================
$updateData = [];

if (!empty($price))           $updateData["ps_price"]           = $price;
if (!is_null($price_estimate) && $price_estimate !== '')
                               $updateData["ps_price_estimate"]  = $price_estimate;
if (!is_null($negotiable))     $updateData["ps_negotiable"]      = (int)$negotiable;
if (!empty($commission_type))  $updateData["ps_commission_type"] = $commission_type;
if (!empty($commission_value)) $updateData["ps_commission_value"]= $commission_value;

if (empty($updateData)) {
    printFailure("No fields to update");
    exit;
}

// ============================================================
// الخطوة 5: تنفيذ التحديث
// ============================================================
$result = updateData(
    "provider_services",
    $updateData,
    "ps_id = $ps_id AND ps_user_id = $user_id",
    false
);

// ============================================================
// الخطوة 6: إرسال الرد
// ============================================================
if ($result > 0) {
    echo json_encode([
        "status"  => "success",
        "message" => "Service updated successfully",
        "data"    => ["ps_id" => (int)$ps_id],
    ]);
} else {
    printFailure("No changes were made");
}
?>