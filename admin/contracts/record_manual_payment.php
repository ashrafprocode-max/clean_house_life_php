<?php
// ============================================================
// ملف: admin/contracts/record_manual_payment.php
// الغرض: تسجيل تحصيل دفعة يدوياً مع صورة الشيك أو سكرين التحويل
// الصلاحية المطلوبة: payments.manage
// الطريقة: POST multipart
// ============================================================

// اتصال قاعدة البيانات
include "../../connect.php";
// ترجمة الحالة ووسيلة التحصيل في الاستجابة
include "../../contracts/includes/contract_helper.php";
// إشعار تأكيد التحصيل اليدوي بعد نجاح الـ commit
$notificationHooksPath = __DIR__ . "/../../notifications/includes/notification_hooks.php";
if (file_exists($notificationHooksPath)) {
    include_once $notificationHooksPath;
}

// جلسة الموظف إلزامية
$currentAdmin = checkAdminToken();
// التحصيل يحتاج payments.manage وليس العرض فقط
requirePermission($currentAdmin, 'payments.manage');

// من سجّل التحصيل يُحفظ في installment_collected_by
$adminId = (int) ($currentAdmin['admin_id'] ?? 0);
$installmentId = (int) filterRequest("installment_id");
$method = trim(filterRequest("installment_method"));
$evidenceType = trim(filterRequest("installment_evidence_type"));
$note = trim(filterRequest("installment_admin_note"));

if ($installmentId <= 0) {
    printFailure("installment_id is required");
}

$allowedMethod = ['bank_transfer', 'check', 'cash', 'instapay', 'app_card', 'app_wallet'];
if (!in_array($method, $allowedMethod, true)) {
    printFailure("وسيلة التحصيل غير صحيحة");
}

$allowedEvidence = ['check', 'instapay', 'wallet', 'bank_transfer', 'cash', 'other'];
if ($evidenceType === '' || !in_array($evidenceType, $allowedEvidence, true)) {
    $evidenceType = $method === 'check' ? 'check' : 'other';
}

$stmt = $con->prepare(
    "SELECT i.*, c.contract_id, c.contract_status, c.contract_code, c.contract_users_id,
            cr.company_name, u.users_full_name
     FROM contract_installments i
     INNER JOIN contracts c ON c.contract_id = i.installment_contract_id
     LEFT JOIN company_registering cr ON cr.company_id = c.contract_company_id
     LEFT JOIN users u ON u.users_id = c.contract_users_id
     WHERE i.installment_id = ?
     LIMIT 1"
);
$stmt->execute([$installmentId]);
// صف الدفعة مع العقد والطرف للتحقق ثم الإشعار
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    printFailure("الدفعة غير موجودة", 404);
}
if ($row['installment_status'] === 'paid') {
    printFailure("هذه الدفعة محصّلة مسبقاً", 409);
}
if ($row['installment_status'] === 'cancelled') {
    printFailure("لا يمكن تحصيل دفعة ملغاة", 409);
}

// مجلد إثباتات التحصيل منفصل عن صور العقود
$uploadDir = __DIR__ . "/../../upload/contract_payments";
$evidenceFile = null;
if (isset($_FILES['evidence_file']) && ($_FILES['evidence_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $uploaded = imageUploadtwo($uploadDir, 'evidence_file');
    if ($uploaded === 'fail' || $uploaded === 'empty' || $uploaded === null) {
        printFailure("تعذّر رفع صورة الإثبات. الصيغ المسموحة: jpg png jpeg webp pdf", 422);
    }
    $evidenceFile = $uploaded;
}

// الشيك والتحويل وإنستاباي بلا صورة تُرفض حتى لا يُسجَّل تحصيل بلا دليل
if (in_array($method, ['check', 'instapay', 'bank_transfer'], true) && empty($evidenceFile)) {
    printFailure("صورة الشيك أو سكرين التحويل مطلوبة لهذا النوع من التحصيل", 422);
}

// معاملة واحدة: الدفعة + تفعيل العقد + الإكمال إن لزم
$con->beginTransaction();
try {
    $upd = $con->prepare(
        "UPDATE contract_installments SET
            installment_status = 'paid',
            installment_paid_at = NOW(),
            installment_paid_via = 'admin_manual',
            installment_method = ?,
            installment_evidence_file = COALESCE(?, installment_evidence_file),
            installment_evidence_type = ?,
            installment_admin_note = ?,
            installment_collected_by = ?
         WHERE installment_id = ?"
    );
    $upd->execute([
        $method,
        $evidenceFile,
        $evidenceType,
        $note !== '' ? $note : null,
        $adminId,
        $installmentId,
    ]);

    // أول تحصيل بعد التوقيع يفعّل العقد
    if ($row['contract_status'] === 'signed') {
        $con->prepare(
            "UPDATE contracts SET contract_status = 'active', contract_updated_by = ?
             WHERE contract_id = ?"
        )->execute([$adminId, $row['contract_id']]);
    }

    // إن لم تبقَ دفعات غير مدفوعة نُغلق العقد كمكتمل
    $left = $con->prepare(
        "SELECT COUNT(*) FROM contract_installments
         WHERE installment_contract_id = ?
           AND installment_status <> 'paid'
           AND installment_status <> 'cancelled'"
    );
    $left->execute([$row['contract_id']]);
    if ((int) $left->fetchColumn() === 0) {
        $con->prepare(
            "UPDATE contracts SET contract_status = 'completed', contract_updated_by = ?
             WHERE contract_id = ?"
        )->execute([$adminId, $row['contract_id']]);
    }

    if (function_exists('logAdminActivity')) {
        logAdminActivity(
            $currentAdmin,
            'payments.manage',
            'contract_installment',
            $installmentId,
            [
                'contract_id' => (int) $row['contract_id'],
                'amount' => $row['installment_amount'],
                'commission' => $row['installment_commission'],
                'method' => $method,
            ]
        );
    }

    $con->commit();
} catch (Throwable $e) {
    $con->rollBack();
    printFailure("تعذّر تسجيل التحصيل: " . $e->getMessage(), 500);
}

// الإشعار بعد الـ commit حتى لا يُرسل إن فشل الحفظ
if (function_exists('notifyManualPaymentConfirmed')) {
    try {
        notifyManualPaymentConfirmed($row, $method);
    } catch (Throwable $e) {
    }
}

printSuccess([
    'installment_id' => $installmentId,
    'status' => 'paid',
    'paid_via' => 'admin_manual',
    'commission' => (float) $row['installment_commission'],
    'evidence_file' => $evidenceFile,
    'message' => 'تم تسجيل التحصيل وإضافة عمولة الدفعة للإيرادات',
]);
