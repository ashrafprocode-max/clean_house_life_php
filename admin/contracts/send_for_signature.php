<?php
// ============================================================
// ملف: admin/contracts/send_for_signature.php
// الغرض: تحويل المسودة إلى بانتظار التوقيع الإلكتروني
// الصلاحية المطلوبة: contracts.approve
// الطريقة: POST
// يُستدعى من تفاصيل العقد بعد اكتمال البنود.
// يُرجع الحالة الجديدة بالعربية. لا يُرسل إشعاراً هنا.
// ============================================================

// اتصال قاعدة البيانات
include "../../connect.php";
// ترجمة حالة pending_signature للعربية
include "../../contracts/includes/contract_helper.php";

// جلسة الموظف إلزامية
$currentAdmin = checkAdminToken();
// الإرسال للتوقيع اعتماد وليس مجرد تعديل مسودة
requirePermission($currentAdmin, 'contracts.approve');

// معرّف العقد المطلوب تحويله
$contractId = (int) filterRequest("contract_id");
// بلا معرّف لا يوجد صف للتحديث
if ($contractId <= 0) {
    printFailure("contract_id is required");
}

// قراءة الحالة الحالية فقط حتى لا يُعتمد عقد موقّع
$stmt = $con->prepare(
    "SELECT contract_id, contract_status FROM contracts WHERE contract_id = ? LIMIT 1"
);
$stmt->execute([$contractId]);
$contract = $stmt->fetch(PDO::FETCH_ASSOC);
// 404 بدل رسالة عامة حتى تميّز الواجهة الحذف
if (!$contract) {
    printFailure("العقد غير موجود", 404);
}
// المسودة وحدها تُرسل؛ غير ذلك تضارب حالة
if ($contract['contract_status'] !== 'draft') {
    printFailure("يُرسل للتوقيع من حالة المسودة فقط", 409);
}

// عقد بلا بنود لا يُوقَّع حتى لا يوافق الطرف على فراغ
$clauses = $con->prepare(
    "SELECT COUNT(*) FROM contract_clauses WHERE clause_contract_id = ?"
);
$clauses->execute([$contractId]);
if ((int) $clauses->fetchColumn() < 1) {
    printFailure("أضف بنداً واحداً على الأقل قبل طلب التوقيع", 422);
}

// تحديث الحالة ومن عدّلها للمراجعة
$upd = $con->prepare(
    "UPDATE contracts
     SET contract_status = 'pending_signature', contract_updated_by = ?
     WHERE contract_id = ?"
);
$upd->execute([(int) ($currentAdmin['admin_id'] ?? 0), $contractId]);

// تأكيد للوحة لإخفاء زر الإرسال وإظهار انتظار الطرف
printSuccess([
    'contract_id' => $contractId,
    'status' => 'pending_signature',
    'status_ar' => contractStatusAr('pending_signature'),
    'message' => 'العقد بانتظار موافقة الطرف بعلامة صح',
]);
