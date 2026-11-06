<?php
// ============================================================
// ملف: payments/customer/payment_webhook.php
// الغرض: استقبال إشعار الخادم إلى الخادم من Paymob (Webhook)
//
// لماذا هو الأهم؟
//   لأن العميل قد يُغلق التطبيق أو المتصفح بعد الدفع مباشرة،
//   فلا يصل الـ Callback. الـ Webhook يصله من Paymob مباشرة
//   إلى السيرفر، وبالتالي هو المرجع الموثوق لتأكيد الدفع.
//
// ملاحظات تنفيذية:
//   - يُسجّل في Paymob من: Developers → Webhooks
//   - الرابط: https://YOUR_DOMAIN.com/payments/customer/payment_webhook.php
//   - يجب أن يرد بـ HTTP 200 حتى لا يُعيد Paymob المحاولة.
//   - لا تُعِد أي HTML في الرد.
// ============================================================

// ربط قاعدة البيانات
include "../../connect.php";

// دوال الدفع المساعدة
include "../includes/payment_helper.php";

// ============================================================
// حماية من فتح الملف في المتصفح
//   الـ Webhook يستقبل POST JSON من سيرفر Paymob فقط.
//   لوحة Paymob كانت توجّه المتصفح (GET) إلى هذا الملف،
//   فيظهر {status:invalid_payload} بدل شاشة النجاح.
//   أي طلب غير POST نُحوّله إلى الـ callback ثم صفحة النتيجة.
// ============================================================
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    // الاستعلام القادم من Paymob في شريط العنوان
    $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');

    // هدف التحويل: ملف الـ callback الذي يفهم GET
    $target = function_exists('paymobPublicUrl')
        ? paymobPublicUrl('payments/customer/payment_callback.php')
        : 'payment_callback.php';

    // إرفاق نفس الباراميترات حتى يُحسب HMAC وتُحدَّث الحالة
    if ($qs !== '') {
        $target .= (strpos($target, '?') === false ? '?' : '&') . $qs;
    }

    // تحويل المتصفح لصفحة النتيجة عبر الـ callback
    header('Location: ' . $target);
    exit;
}

// ============================================================
// الخطوة 1: قراءة جسم الطلب الخام
// ============================================================
$rawBody = file_get_contents('php://input');

// تحويل JSON إلى مصفوفة
$payload = json_decode($rawBody, true);

// إذا لم يكن JSON صالحاً نرد بخطأ 400 ونوقف
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['status' => 'invalid_payload']);
    exit;
}

// ============================================================
// الخطوة 2: استخراج كائن العملية
//    الصيغة الشائعة: { "type": "TRANSACTION", "obj": { ... } }
//    وأحياناً يصل الكائن مباشرة في الجسم.
// ============================================================
$obj = is_array($payload['obj'] ?? null) ? $payload['obj'] : $payload;

// ============================================================
// الخطوة 3: قراءة توقيع HMAC
//    يصل غالباً في Query String (?hmac=...)
//    وندعم أيضاً وجوده داخل الجسم.
// ============================================================
$query = parseRawQuery($_SERVER['QUERY_STRING'] ?? '');
$receivedHmac = (string) ($query['hmac'] ?? $obj['hmac'] ?? $payload['hmac'] ?? '');

// إزالة hmac من الكائن قبل حساب التوقيع (ليس جزءاً من الحقول الموقعة)
unset($obj['hmac']);

// ============================================================
// الخطوة 4: التحقق من التوقيع
// ============================================================
$hmacValid = paymobVerifyHmac($obj, $receivedHmac);

// الرقم المرجعي للعملية
$reference = resolvePaymentReference($con, $obj);

// ============================================================
// الخطوة 5: تسجيل الوصول (قبل المعالجة) لأغراض التتبع
// ============================================================
try {
    logGatewayPayload(
        $con,
        'webhook',
        $reference,
        $rawBody,
        $hmacValid
    );
} catch (Throwable $e) {
    // التسجيل لا يجب أن يمنع الدفع
}

// ============================================================
// الخطوة 6: رفض التوقيع غير الصالح
// ============================================================
if (!$hmacValid) {
    // 401 يجعل Paymob يعيد المحاولة لاحقاً
    http_response_code(401);
    echo json_encode(['status' => 'invalid_hmac']);
    exit;
}

// ============================================================
// الخطوة 7: تجاهل الأحداث غير المتعلقة بالمعاملات
// ============================================================
$eventType = strtoupper((string) ($payload['type'] ?? 'TRANSACTION'));
if ($eventType !== 'TRANSACTION' && $eventType !== '') {
    // نرد 200 حتى لا يعيد Paymob الإرسال
    http_response_code(200);
    echo json_encode(['status' => 'ignored']);
    exit;
}

// ============================================================
// الخطوة 8: لا يمكن المتابعة بدون رقم مرجعي معروف
// ============================================================
if ($reference === null) {
    // 200 حتى لا يدخل Paymob في حلقة إعادة إرسال لا نهائية
    http_response_code(200);
    echo json_encode(['status' => 'unknown_reference']);
    exit;
}

// ============================================================
// الخطوة 9: تطبيق النتيجة على الدفع والحجز
// ============================================================
$success = gatewayBool($obj['success'] ?? false);
$txnId = (string) ($obj['id'] ?? '');

// التطبيق ذرّي ويكون آمناً عند تكرار نفس الـ Webhook
$result = applyPaymentResult($con, $reference, $success, $txnId, $obj);

// ============================================================
// الخطوة 10: الرد على Paymob
// ============================================================
http_response_code(200);
echo json_encode([
    'status' => !empty($result['ok']) ? 'ok' : 'failed',
    'reference' => $reference,
]);
exit;
