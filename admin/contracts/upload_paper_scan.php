<?php
// ============================================================
// ملف: admin/contracts/upload_paper_scan.php
// الغرض: رفع صورة/PDF العقد الورقي الموقّع يدوياً
// الصلاحية المطلوبة: contracts.edit
// الطريقة: POST multipart (paper_scan)
// يُستدعى من تفاصيل العقد عند أرشفة النسخة الورقية.
// يُرجع اسم الملف والحالة. قد يحوّل المسودة إلى موقّع.
// ============================================================

// اتصال قاعدة البيانات
include "../../connect.php";
// ترجمة الحالة الجديدة في الاستجابة
include "../../contracts/includes/contract_helper.php";

// جلسة الموظف إلزامية
$currentAdmin = checkAdminToken();
// رفع المسح تعديل على العقد وليس صلاحية عرض فقط
requirePermission($currentAdmin, 'contracts.edit');

// العقد المستهدف
$contractId = (int) filterRequest("contract_id");
// اسم الموقّع الورقي إن عُرف عند الأرشفة
$signedName = trim(filterRequest("contract_signed_name"));
if ($contractId <= 0) {
    printFailure("contract_id is required");
}

// الحالة الحالية لتقرير التحويل إلى موقّع
$stmt = $con->prepare(
    "SELECT contract_id, contract_status FROM contracts WHERE contract_id = ? LIMIT 1"
);
$stmt->execute([$contractId]);
$contract = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$contract) {
    printFailure("العقد غير موجود", 404);
}

// مجلد صور العقود منفصل عن إثباتات الدفع
$uploadDir = __DIR__ . "/../../upload/contracts";
// نفس دالة رفع الصور المستخدمة في بقية اللوحة
$uploaded = imageUploadtwo($uploadDir, 'paper_scan');
// fail/empty يعني لا ملف صالح للأرشفة
if ($uploaded === 'fail' || $uploaded === 'empty' || $uploaded === null) {
    printFailure("ارفع صورة أو ملف PDF للعقد الورقي", 422);
}

// الإبقاء على الحالة إن كان العقد موقّعاً أو سارياً أصلاً
$newStatus = $contract['contract_status'];
// المسودة أو انتظار التوقيع تُعتبر موقّعة بعد أرشفة الورق
if (in_array($newStatus, ['draft', 'pending_signature'], true)) {
    $newStatus = 'signed';
}

// حفظ الملف والاسم دون مسح اسم سابق إن جاء الطلب بلا اسم
$upd = $con->prepare(
    "UPDATE contracts SET
        contract_paper_scan = ?,
        contract_signed_name = COALESCE(NULLIF(?, ''), contract_signed_name),
        contract_signed_at = COALESCE(contract_signed_at, NOW()),
        contract_status = ?,
        contract_updated_by = ?
     WHERE contract_id = ?"
);
$upd->execute([
    $uploaded,
    $signedName,
    $newStatus,
    (int) ($currentAdmin['admin_id'] ?? 0),
    $contractId,
]);

// سجل توقيع ورقي إن وُجد اسم حتى يظهر في تبويب التوقيعات
if ($signedName !== '') {
    $con->prepare(
        "INSERT INTO contract_signatures (
            sign_contract_id, sign_admin_id, sign_name, sign_accepted, sign_channel
         ) VALUES (?, ?, ?, 1, 'admin_paper')"
    )->execute([$contractId, (int) ($currentAdmin['admin_id'] ?? 0), $signedName]);
}

// تأكيد الأرشفة للوحة لعرض الصورة والحالة
printSuccess([
    'contract_id' => $contractId,
    'paper_scan' => $uploaded,
    'status' => $newStatus,
    'status_ar' => contractStatusAr($newStatus),
    'message' => 'تم أرشفة صورة العقد الورقي',
]);
