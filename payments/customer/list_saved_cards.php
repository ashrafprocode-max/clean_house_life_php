<?php
// ============================================================
// ملف: payments/customer/list_saved_cards.php
// الغرض: عرض الكروت المحفوظة للعميل داخل شاشة الدفع
//
// قاعدة الأمان المطلوبة:
//   - لا نُعيد رقم الكارت الكامل أبداً.
//   - لا نُعيد التوكن (card_token) إلى الموبايل إطلاقاً.
//   - ما يُعرض هو آخر 4 أرقام + نوع الكارت + المقنّع.
//   - عند اختيار الكارت يطلب التطبيق CVV (ثلاثة أرقام خلف الكارت)
//     ويُرسله إلى Paymob عبر صفحة الدفع الآمنة، لا إلى سيرفرنا.
//
// المدخلات: payment_user_id
// الطريقة: GET أو POST
// ============================================================

// ربط قاعدة البيانات والدوال العامة
include "../../connect.php";

// ============================================================
// الخطوة 1: استقبال معرّف المستخدم
// ============================================================
$userId = (int) filterRequest("payment_user_id");

// معرّف المستخدم إجباري
if ($userId <= 0) {
    printFailure("payment_user_id is required");
    exit;
}

// ============================================================
// الخطوة 2: قراءة الكروت النشطة فقط
// ============================================================
$stmt = $con->prepare(
    "SELECT card_id, card_mask, card_last4, card_brand,
            card_is_default, card_created_at
     FROM payment_cards
     WHERE card_user_id = ? AND card_is_active = 1
     ORDER BY card_is_default DESC, card_id DESC"
);
$stmt->execute([$userId]);

// تحويل كل صف إلى مصفوفة واضحة لـ Flutter
$cards = [];
foreach ($stmt->fetchAll() as $row) {
    $cards[] = [
        "card_id"         => (int) $row['card_id'],
        // الرقم المقنّع كما هو من البوابة (مثال: 5123 **** **** 1234)
        "card_mask"       => (string) $row['card_mask'],
        // آخر 4 أرقام فقط - هي المطلوب عرضها بجانب الكارت
        "card_last4"      => (string) $row['card_last4'],
        // Visa / MasterCard
        "card_brand"      => (string) $row['card_brand'],
        // هل هو الكارت الافتراضي؟
        "card_is_default" => (int) $row['card_is_default'] === 1,
        "card_created_at" => $row['card_created_at'],
    ];
}

// إرجاع القائمة (فارغة إن لم توجد كروت)
printSuccess([
    "cards" => $cards,
    "count" => count($cards),
]);
