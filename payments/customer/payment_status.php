<?php
// ============================================================
// ملف: payments/customer/payment_status.php
// الغرض: استعلام تطبيق Flutter عن حالة عملية دفع معيّنة
//
// متى يُستخدم؟
//   شاشة الدفع تفتح صفحة Paymob في المتصفح، ثم تعود للتطبيق
//   وتستدعي هذا الملف كل ثانيتين حتى تعرف النتيجة النهائية
//   (لأن تأكيد الدفع قد يتأخر ثوانٍ بسبب الـ Webhook).
//
// المدخلات:
//   - payment_reference أو payment_id
//   - payment_user_id (للتحقق من الملكية)
//
// الطريقة: GET أو POST
// ============================================================

// ربط قاعدة البيانات والدوال العامة
include "../../connect.php";

// دوال الدفع المساعدة
include "../includes/payment_helper.php";

// ============================================================
// الخطوة 1: استقبال المدخلات
// ============================================================
$reference = trim(filterRequest("payment_reference"));
$paymentId = (int) filterRequest("payment_id");
$userId = (int) filterRequest("payment_user_id");

// يجب وجود مرجع أو معرّف
if ($reference === '' && $paymentId <= 0) {
    printFailure("payment_reference or payment_id is required");
    exit;
}

// يجب وجود المستخدم للتحقق من الملكية
if ($userId <= 0) {
    printFailure("payment_user_id is required");
    exit;
}

// ============================================================
// الخطوة 2: قراءة العملية مع التحقق من ملكيتها
// ============================================================
if ($reference !== '') {
    // البحث بالرقم المرجعي
    $stmt = $con->prepare(
        "SELECT payment_id, payment_user_id, payment_booking_id, payment_reference,
                payment_method, payment_amount, payment_fee_amount, payment_total_amount,
                payment_currency, payment_state, payment_failure_reason,
                payment_paid_at, payment_created_at
         FROM payments
         WHERE payment_reference = ? AND payment_user_id = ?
         LIMIT 1"
    );
    $stmt->execute([$reference, $userId]);
} else {
    // البحث بالمعرّف
    $stmt = $con->prepare(
        "SELECT payment_id, payment_user_id, payment_booking_id, payment_reference,
                payment_method, payment_amount, payment_fee_amount, payment_total_amount,
                payment_currency, payment_state, payment_failure_reason,
                payment_paid_at, payment_created_at
         FROM payments
         WHERE payment_id = ? AND payment_user_id = ?
         LIMIT 1"
    );
    $stmt->execute([$paymentId, $userId]);
}

// قراءة الصف
$payment = $stmt->fetch();

// العملية غير موجودة أو لا تخص المستخدم
if (!$payment) {
    printFailure("Payment not found", 404);
    exit;
}

// ============================================================
// الخطوة 3: قراءة حالة الحجز المرتبطة (للتأكيد المزدوج)
// ============================================================
$stmtBooking = $con->prepare(
    "SELECT booking_id, booking_status, booking_payment_status, booking_paid_at
     FROM bookings
     WHERE booking_id = ? LIMIT 1"
);
$stmtBooking->execute([(int) $payment['payment_booking_id']]);
$booking = $stmtBooking->fetch() ?: [];

// ============================================================
// الخطوة 4: إرجاع الحالة لـ Flutter
// ============================================================
printSuccess([
    "payment_id"            => (int) $payment['payment_id'],
    "payment_reference"     => $payment['payment_reference'],
    "payment_booking_id"    => (int) $payment['payment_booking_id'],
    "payment_method"        => $payment['payment_method'],
    "payment_state"         => $payment['payment_state'],
    "payment_amount"        => (float) $payment['payment_amount'],
    "payment_fee_amount"    => (float) $payment['payment_fee_amount'],
    "payment_total_amount"  => (float) $payment['payment_total_amount'],
    "payment_currency"      => $payment['payment_currency'],
    "payment_failure_reason" => $payment['payment_failure_reason'],
    "payment_paid_at"       => $payment['payment_paid_at'],
    "payment_created_at"    => $payment['payment_created_at'],
    "booking_payment_status" => $booking['booking_payment_status'] ?? null,
    "booking_status"        => $booking['booking_status'] ?? null,
    "booking_paid_at"       => $booking['booking_paid_at'] ?? null,
]);
