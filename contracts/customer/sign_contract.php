<?php
// ============================================================
// ملف: contracts/customer/sign_contract.php
// الغرض: موافقة الطرف بعلامة صح على بنود العقد = توقيع إلكتروني
// الطريقة: POST
// يُستدعى من شاشة توقيع العقد في التطبيق بعد قراءة البنود.
// يُرجع الحالة signed. يفعّل الدفع لاحقاً وليس التحصيل هنا.
// ============================================================

// اتصال قاعدة البيانات
include "../../connect.php";
// ترجمة الحالة بعد التوقيع في الاستجابة
include "../includes/contract_helper.php";

// المستخدم الموقّع من جلسة التطبيق
$userId = (int) filterRequest("users_id");
// العقد المطلوب توقيعه
$contractId = (int) filterRequest("contract_id");
// يجب أن تكون 1؛ أي قيمة أخرى تُرفض حتى لا يُحفظ توقيع بلا موافقة
$accepted = (int) filterRequest("accepted");
// الاسم الظاهر في سجل التوقيع والطباعة
$signedName = trim(filterRequest("signed_name"));

if ($userId <= 0 || $contractId <= 0) {
    printFailure("users_id and contract_id are required");
}
// بلا موافقة صريحة لا يُعتبر توقيعاً إلكترونياً
if ($accepted !== 1) {
    printFailure("يجب الموافقة على البنود بعلامة صح لإتمام التوقيع الإلكتروني", 422);
}

// العقد مع اسم المستخدم كاحتياطي لاسم التوقيع
$stmt = $con->prepare(
    "SELECT c.*, u.users_full_name
     FROM contracts c
     LEFT JOIN users u ON u.users_id = c.contract_users_id
     LEFT JOIN company_registering cr ON cr.company_id = c.contract_company_id
     WHERE c.contract_id = ?
     LIMIT 1"
);
$stmt->execute([$contractId]);
$contract = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$contract) {
    printFailure("العقد غير موجود", 404);
}

// ملكية الأفراد: المستخدم هو طرف العقد
$ownsAsUser = (int) ($contract['contract_users_id'] ?? 0) === $userId;
// افتراض عدم ملكية الشركة حتى يثبت الاستعلام
$ownsAsCompany = false;
// ملكية الشركة: المستخدم مالك سجل الشركة المرتبط
if (!$ownsAsUser && !empty($contract['contract_company_id'])) {
    $own = $con->prepare(
        "SELECT company_id FROM company_registering
         WHERE company_id = ? AND company_user_id = ?
         LIMIT 1"
    );
    $own->execute([(int) $contract['contract_company_id'], $userId]);
    $ownsAsCompany = (bool) $own->fetchColumn();
}
if (!$ownsAsUser && !$ownsAsCompany) {
    printFailure("هذا العقد لا يخص هذا المستخدم", 403);
}

// لا يُوقَّع عقد ساري أو مكتمل مرة ثانية
if (!in_array($contract['contract_status'], ['draft', 'pending_signature'], true)) {
    printFailure("العقد موقّع مسبقاً أو غير قابل للتوقيع", 409);
}

// اسم المستخدم إن تُرك حقل الاسم فارغاً حتى لا يُحفظ توقيع بلا اسم
if ($signedName === '') {
    $signedName = (string) ($contract['users_full_name'] ?? 'الموقّع');
}

// سجل التوقيع وتحديث العقد معاً حتى لا يبقى أحدهما بلا الآخر
$con->beginTransaction();
try {
    $con->prepare(
        "INSERT INTO contract_signatures (
            sign_contract_id, sign_users_id, sign_name, sign_accepted,
            sign_channel, sign_ip, sign_user_agent
         ) VALUES (?, ?, ?, 1, 'app', ?, ?)"
    )->execute([
                $contractId,
                $userId,
                $signedName,
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);

    $con->prepare(
        "UPDATE contracts SET
            contract_e_signed = 1,
            contract_signed_name = ?,
            contract_signed_at = NOW(),
            contract_status = 'signed'
         WHERE contract_id = ?"
    )->execute([$signedName, $contractId]);

    $con->commit();
} catch (Throwable $e) {
    $con->rollBack();
    printFailure("تعذّر حفظ التوقيع: " . $e->getMessage(), 500);
}

printSuccess([
    'contract_id' => $contractId,
    'status' => 'signed',
    'status_ar' => contractStatusAr('signed'),
    'e_signed' => true,
    'signed_name' => $signedName,
    'message' => 'تم التوقيع الإلكتروني. يمكن الآن سداد الدفعة الأولى.',
]);
