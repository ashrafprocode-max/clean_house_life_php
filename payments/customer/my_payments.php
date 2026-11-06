<?php
// ============================================================
// ملف: payments/customer/my_payments.php
// الغرض: قائمة مدفوعات العميل (أو مدفوعات حجز واحد)
//
// لماذا نحتاجه؟
//   تطبيق Flutter يحتاج معرفة أي الحجوزات مدفوعة حتى:
//     - يُخفي زر «ادفع الآن» بعد الدفع.
//     - يُظهر زر «إعادة الحجز» للحجوزات المكتملة/المدفوعة.
//   ولا نريد تعديل استعلامات قائمة الحجوزات القديمة،
//   لذلك نُقدّم هذه النقطة المستقلة.
//
// المدخلات:
//   - payment_user_id (إجباري)
//   - booking_id (اختياري) لتصفية حجز واحد
//
// الطريقة: GET أو POST
// ============================================================

// ربط قاعدة البيانات والدوال العامة
include "../../connect.php";

// ============================================================
// الخطوة 1: استقبال المدخلات
// ============================================================
$userId = (int) filterRequest("payment_user_id");
$bookingId = (int) filterRequest("booking_id");

// معرّف المستخدم إجباري
if ($userId <= 0) {
    printFailure("payment_user_id is required");
    exit;
}

// ============================================================
// الخطوة 2: بناء الاستعلام حسب وجود فلتر الحجز
// ============================================================
if ($bookingId > 0) {
    // مدفوعات حجز واحد فقط
    $stmt = $con->prepare(
        "SELECT p.payment_id, p.payment_booking_id, p.payment_reference,
                p.payment_method, p.payment_state, p.payment_amount,
                p.payment_fee_amount, p.payment_total_amount,
                p.payment_currency, p.payment_paid_at, p.payment_created_at
         FROM payments p
         WHERE p.payment_user_id = ? AND p.payment_booking_id = ?
         ORDER BY p.payment_id DESC"
    );
    $stmt->execute([$userId, $bookingId]);
} else {
    // كل مدفوعات المستخدم
    $stmt = $con->prepare(
        "SELECT p.payment_id, p.payment_booking_id, p.payment_reference,
                p.payment_method, p.payment_state, p.payment_amount,
                p.payment_fee_amount, p.payment_total_amount,
                p.payment_currency, p.payment_paid_at, p.payment_created_at
         FROM payments p
         WHERE p.payment_user_id = ?
         ORDER BY p.payment_id DESC
         LIMIT 500"
    );
    $stmt->execute([$userId]);
}

// ============================================================
// الخطوة 3: تجهيز القائمة للتطبيق
// ============================================================
$items = [];

foreach ($stmt->fetchAll() as $row) {
    $items[] = [
        "payment_id"           => (int) $row['payment_id'],
        "payment_booking_id"   => (int) $row['payment_booking_id'],
        "payment_reference"    => (string) $row['payment_reference'],
        "payment_method"       => (string) $row['payment_method'],
        "payment_state"        => (string) $row['payment_state'],
        "payment_amount"       => (float) $row['payment_amount'],
        "payment_fee_amount"   => (float) $row['payment_fee_amount'],
        "payment_total_amount" => (float) $row['payment_total_amount'],
        "payment_currency"     => (string) $row['payment_currency'],
        "payment_paid_at"      => $row['payment_paid_at'],
        "payment_created_at"   => $row['payment_created_at'],
    ];
}

// إرجاع القائمة
printSuccess([
    "payments" => $items,
    "count"    => count($items),
]);
