<?php
// ============================================================
// ملف: contracts/customer/create_installment_payment.php
// الغرض: بدء دفع دفعة عقد عبر التطبيق (كارت/محفظة) بنفس مسار الأفراد
// الطريقة: POST
// يُستدعى من شاشة سداد الدفعة في تطبيق الشركة/العميل.
// يُدرج صفاً في payments ويرجع checkout_url. لا يعلّم الدفعة مدفوعة هنا.
// ============================================================

// اتصال قاعدة البيانات
include "../../connect.php";
// التحقق من أحقيّة دفع الدفعة الأولى وحالة العقد
include "../includes/contract_helper.php";

// مساعد الرسوم وPaymob إن وُجد في نشر المدفوعات
$helperPath = __DIR__ . "/../../payments/includes/payment_helper.php";
if (file_exists($helperPath)) {
    include $helperPath;
}

// المستخدم الدافع من جلسة التطبيق
$userId = (int) filterRequest("users_id");
// الدفعة المستهدفة وليس العقد كله
$installmentId = (int) filterRequest("installment_id");
$method = trim(filterRequest("method"));
if ($method === '') {
    $method = 'card';
}

if ($userId <= 0 || $installmentId <= 0) {
    printFailure("users_id and installment_id are required");
}

// الدفعة مع العقد للتحقق من الملكية والحالة
$stmt = $con->prepare(
    "SELECT i.*, c.contract_id, c.contract_status, c.contract_users_id,
            c.contract_company_id, c.contract_code, c.contract_title_ar
     FROM contract_installments i
     INNER JOIN contracts c ON c.contract_id = i.installment_contract_id
     WHERE i.installment_id = ?
     LIMIT 1"
);
$stmt->execute([$installmentId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    printFailure("الدفعة غير موجودة", 404);
}

$ownsAsUser = (int) ($row['contract_users_id'] ?? 0) === $userId;
$ownsAsCompany = false;
if (!$ownsAsUser && !empty($row['contract_company_id'])) {
    $own = $con->prepare(
        "SELECT company_id FROM company_registering
         WHERE company_id = ? AND company_user_id = ?
         LIMIT 1"
    );
    $own->execute([(int) $row['contract_company_id'], $userId]);
    $ownsAsCompany = (bool) $own->fetchColumn();
}
if (!$ownsAsUser && !$ownsAsCompany) {
    printFailure("هذه الدفعة لا تخص هذا المستخدم", 403);
}

// الدفعة الأولى لها مسار خاص؛ غير الأولى تحتاج عقداً موقّعاً أو سارياً
if (!contractCanPayFirstInstallment($row, $row) && (int) $row['installment_number'] !== 1) {
    if (!in_array($row['contract_status'], ['signed', 'active'], true)) {
        printFailure("لا يمكن الدفع قبل توقيع العقد وتفعيله", 409);
    }
}
if ($row['installment_status'] === 'paid') {
    printFailure("هذه الدفعة محصّلة مسبقاً", 409);
}

$amount = (float) $row['installment_amount'];
$reference = 'CHL-CT-' . $row['contract_id'] . '-' . $installmentId . '-' . time();

$feeAmount = 0;
$totalAmount = $amount;
if (function_exists('paymentComputeFee')) {
    $fee = paymentComputeFee($con, $amount);
    $feeAmount = (float) ($fee['fee_amount'] ?? 0);
    $totalAmount = $amount + $feeAmount;
}

$payMethod = $method === 'wallet' ? 'wallet' : 'card';
$insert = $con->prepare(
    "INSERT INTO payments (
        payment_user_id, payment_booking_id, payment_contract_id,
        payment_installment_id, payment_method, payment_reference,
        payment_amount, payment_fee_amount, payment_total_amount,
        payment_currency, payment_state
     ) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, 'EGP', 'pending')"
);
$insert->execute([
    $userId,
    (int) $row['contract_id'],
    $installmentId,
    $payMethod,
    $reference,
    $amount,
    $feeAmount,
    $totalAmount,
]);
$paymentId = (int) $con->lastInsertId();

$con->prepare(
    "UPDATE contract_installments
     SET installment_status = 'due'
     WHERE installment_id = ? AND installment_status = 'pending'"
)->execute([$installmentId]);

$checkoutUrl = '';
if (function_exists('paymobCreateCheckout')) {
    $checkoutUrl = (string) paymobCreateCheckout([
        'user_id' => $userId,
        'amount' => $totalAmount,
        'reference' => $reference,
        'method' => $payMethod,
        'payment_id' => $paymentId,
    ]);
}

printSuccess([
    'payment_id' => $paymentId,
    'reference' => $reference,
    'method' => $payMethod,
    'amount' => $amount,
    'fee_amount' => $feeAmount,
    'total_amount' => $totalAmount,
    'currency' => 'EGP',
    'checkout_url' => $checkoutUrl,
    'contract_id' => (int) $row['contract_id'],
    'installment_id' => $installmentId,
    'message' => $checkoutUrl !== ''
        ? 'تم إنشاء عملية الدفع. أكمل من صفحة البوابة.'
        : 'تم إنشاء عملية الدفع. أكمل من شاشة الكارت/المحفظة.',
]);
