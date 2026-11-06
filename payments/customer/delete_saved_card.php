<?php
// ============================================================
// ملف: payments/customer/delete_saved_card.php
// الغرض: حذف كارت محفوظ من شاشة إدارة الكروت
//
// ملاحظة مهمة:
//   لا نحذف الصف من قاعدة البيانات حذفاً نهائياً، بل نجعل
//   card_is_active = 0، لأن الصف مرتبط بسجل مدفوعات قديم
//   (Foreign Key)، وحذفه قد يُفقد تاريخ العمليات المالية.
//
// المدخلات: card_id + payment_user_id
// الطريقة: POST
// ============================================================

// ربط قاعدة البيانات والدوال العامة
include "../../connect.php";

// ============================================================
// الخطوة 1: استقبال المدخلات
// ============================================================
$cardId = (int) filterRequest("card_id");
$userId = (int) filterRequest("payment_user_id");

// الحقلان إجباريان
if ($cardId <= 0 || $userId <= 0) {
    printFailure("card_id and payment_user_id are required");
    exit;
}

// ============================================================
// الخطوة 2: التأكد من أن الكارت يخص المستخدم
// ============================================================
$stmt = $con->prepare(
    "SELECT card_id, card_is_default
     FROM payment_cards
     WHERE card_id = ? AND card_user_id = ? AND card_is_active = 1
     LIMIT 1"
);
$stmt->execute([$cardId, $userId]);
$card = $stmt->fetch();

// الكارت غير موجود
if (!$card) {
    printFailure("Card not found", 404);
    exit;
}

// ============================================================
// الخطوة 3: تعطيل الكارت بدلاً من حذفه
// ============================================================
$update = $con->prepare(
    "UPDATE payment_cards SET card_is_active = 0, card_is_default = 0 WHERE card_id = ? AND card_user_id = ?"
);
$update->execute([$cardId, $userId]);

// ============================================================
// الخطوة 4: إذا كان الكارت الافتراضي نُعيّن أحدث كارت بديلاً
// ============================================================
if ((int) $card['card_is_default'] === 1) {
    // أحدث كارت نشط متبقٍ
    $next = $con->prepare(
        "SELECT card_id FROM payment_cards
         WHERE card_user_id = ? AND card_is_active = 1
         ORDER BY card_id DESC LIMIT 1"
    );
    $next->execute([$userId]);
    $nextId = $next->fetchColumn();

    // تعيينه افتراضياً إن وُجد
    if ($nextId !== false) {
        $setDefault = $con->prepare(
            "UPDATE payment_cards SET card_is_default = 1 WHERE card_id = ?"
        );
        $setDefault->execute([(int) $nextId]);
    }
}

// ============================================================
// الخطوة 5: تأكيد الحذف
// ============================================================
printSuccess("Card removed successfully");
