<?php
// ============================================================
// ملف: payments/includes/payment_helper.php
// الغرض: كل الدوال المشتركة بين ملفات الدفع (Paymob)
//
// لماذا ملف مساعد واحد؟
//   حتى لا تتكرر نفس الأكواد في 6 ملفات API، وحتى نعدّل مكاناً
//   واحداً فقط عند تغيير الإعدادات أو طريقة حساب الرسوم.
//
// ما الذي يقدمه؟
//   1) قراءة إعدادات Paymob السرية من config/paymob_config.php.
//   2) قراءة إعدادات العمولات والرسوم من جدول app_settings.
//   3) حساب رسوم الدفع (1% بحد أدنى 5 جنيه افتراضياً).
//   4) استدعاء Paymob: توثيق، إنشاء طلب، مفتاح دفع، دفع محفظة.
//   5) التحقق من توقيع HMAC لكل Callback/Webhook.
//   6) حفظ نتيجة الدفع على جدول payments وbookings بشكل ذرّي.
//   7) حفظ الكارت (Token) بعد أول دفعة ناجحة.
//   8) إرسال إشعارات للعميل والإدارة بعد الدفع.
// ============================================================

// تضمين أحداث الإشعارات (يحمّل helper أيضاً) حتى نستخدم notifyEvent والـ hooks
require_once __DIR__ . '/../../notifications/includes/notification_hooks.php';

// ============================================================
// 1) قراءة إعدادات Paymob
// ============================================================
function paymobConfig(): array
{
    // static تعني أن الملف يُقرأ مرة واحدة فقط خلال تنفيذ الطلب
    static $config = null;

    // إذا قرأناه سابقاً نُعيده مباشرة (توفير للقرص)
    if ($config !== null) {
        return $config;
    }

    // مسار ملف الإعدادات (خارج مجلد payments بمستويين)
    $path = __DIR__ . '/../../config/paymob_config.php';

    // إذا لم يوجد الملف نُعيد مصفوفة فارغة مع قيم افتراضية آمنة
    if (!is_file($path)) {
        $config = [];
        return $config;
    }

    // include يُنفّذ الملف ويعيد المصفوفة عبر return
    $loaded = include $path;

    // نضمن أن الناتج مصفوفة حتى لا يحدث خطأ في بقية الدوال
    $config = is_array($loaded) ? $loaded : [];

    // إرجاع الإعدادات
    return $config;
}

// ============================================================
// 2) قراءة قيمة واحدة من إعدادات Paymob مع قيمة بديلة
// ============================================================
function paymobConfigValue(string $key, $default = '')
{
    // قراءة المصفوفة كاملة
    $config = paymobConfig();

    // إذا كان المفتاح موجوداً نُعيد قيمته
    if (array_key_exists($key, $config)) {
        return $config[$key];
    }

    // وإلا نُعيد القيمة البديلة
    return $default;
}

// ============================================================
// 3) قراءة كل إعدادات التطبيق من app_settings
// ============================================================
function getAppSettings(PDO $con): array
{
    // static لكل اتصال: نقرأ الجدول مرة واحدة في الطلب الواحد
    static $cache = [];

    // مفتاح تمييز بسيط لكل اتصال (object hash) لتجنب خلط قواعد البيانات
    $cacheKey = spl_object_hash($con);

    // إذا قرأنا الإعدادات لهذا الاتصال سابقاً نُعيدها
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    // مصفوفة النتيجة: مفتاح الإعداد => القيمة
    $settings = [];

    // استعلام بسيط عن كل الصفوف
    $stmt = $con->query("SELECT setting_key, setting_value FROM app_settings");

    // المرور على كل صف وتحويله لعنصر بالمصفوفة
    foreach ($stmt->fetchAll() as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    // تخزين النتيجة في الكاش وإرجاعها
    $cache[$cacheKey] = $settings;
    return $settings;
}

// ============================================================
// 4) استخراج إعدادات رسوم الدفع من إعدادات التطبيق
// ============================================================
function getPaymentFeeSettings(array $settings): array
{
    // هل الرسوم مفعّلة؟ أي قيمة غير "0" تعني مفعّلة
    $enabled = (string) ($settings['payment_fee_enabled'] ?? '1') !== '0';

    // نوع الرسوم: percent أو fixed (أي قيمة غير fixed تُعامل كنسبة)
    $type = (string) ($settings['payment_fee_type'] ?? 'percent');
    $type = $type === 'fixed' ? 'fixed' : 'percent';

    // قيمة الرسوم (1 تعني 1%)
    $value = (float) ($settings['payment_fee_value'] ?? 1);

    // الحد الأدنى للرسوم بالجنيه (5 افتراضياً)
    $min = (float) ($settings['payment_fee_min'] ?? 5);

    // إرجاع الإعدادات بشكل واضح
    return [
        'enabled' => $enabled,
        'type' => $type,
        'value' => $value,
        'min' => $min,
    ];
}

// ============================================================
// 5) حساب رسوم الدفع
//    القاعدة المطلوبة:
//      - نسبة 1% من قيمة الحجز.
//      - بحد أدنى 5 جنيه.
//      - يمكن للإدارة تقليلها أو زيادتها أو إلغاؤها (enabled=0).
// ============================================================
function calculatePaymentFee(float $amount, array $feeSettings): array
{
    // إذا كانت الرسوم ملغاة من الإدارة نُعيد صفراً
    if (empty($feeSettings['enabled'])) {
        return [
            'type' => $feeSettings['type'],
            'value' => (float) $feeSettings['value'],
            'amount' => 0.00,
        ];
    }

    // حساب القيمة الأولية حسب النوع
    if ($feeSettings['type'] === 'fixed') {
        // مبلغ ثابت لكل عملية
        $fee = (float) $feeSettings['value'];
    } else {
        // نسبة مئوية من قيمة الحجز
        $fee = ($amount * (float) $feeSettings['value']) / 100;
    }

    // تطبيق الحد الأدنى فقط إذا كانت قيمة الحجز أكبر من صفر
    if ($amount > 0 && $fee < (float) $feeSettings['min']) {
        $fee = (float) $feeSettings['min'];
    }

    // تقريب لمئتين عشريتين (مثل 5.00)
    $fee = round($fee, 2);

    // إرجاع النوع والقيمة المحسوبة
    return [
        'type' => $feeSettings['type'],
        'value' => (float) $feeSettings['value'],
        'amount' => $fee,
    ];
}

// ============================================================
// 6) تقريب أي مبلغ مالي إلى منزلتين عشريتين
// ============================================================
function money(float $value): float
{
    // round($value, 2) يمنع أخطاء الفاصلة العائمة مثل 5.000000001
    return round($value, 2);
}

// ============================================================
// 7) استدعاء HTTP عام لـ Paymob باستخدام cURL
//    يُعيد: [رمز HTTP, مصفوفة الرد, النص الخام]
// ============================================================
function paymobHttp(string $method, string $url, ?array $payload = null): array
{
    // تهيئة cURL
    $ch = curl_init();

    // تجهيز الهيدرات: نُرسل JSON ونستقبل JSON
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    // إعدادات أساسية لكل الطلبات
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, (int) paymobConfigValue('http_timeout', 30));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

    // التحقق من شهادة SSL (يجب أن يظل true في الإنتاج)
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    // تحديد طريقة الطلب
    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        // تحويل المصفوفة إلى JSON وإرسالها في الجسم
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload ?? []));
    }

    // تنفيذ الطلب
    $raw = curl_exec($ch);

    // رمز الاستجابة HTTP
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    // رسالة خطأ cURL إن وُجدت
    $curlError = curl_error($ch);

    // إغلاق الجلسة لتحرير الموارد
    curl_close($ch);

    // تحويل النص إلى مصفوفة (فارغة إذا لم يكن JSON صالحاً)
    $body = $raw !== false ? json_decode($raw, true) : null;
    if (!is_array($body)) {
        $body = [];
    }

    // إذا فشل الاتصال نُعيد خطأ واضحاً للاستخدام في الأعلى
    if ($raw === false) {
        $body['_curl_error'] = $curlError;
    }

    // إرجاع الثلاثة معاً
    return [$status, $body, (string) $raw];
}

// ============================================================
// 8) الحصول على auth_token من Paymob (صالح ساعة تقريباً)
// ============================================================
function paymobAuthToken(): string
{
    // عنوان التوثيق
    $url = rtrim((string) paymobConfigValue('base_url', 'https://accept.paymob.com/api'), '/') . '/auth/tokens';

    // إرسال الـ API Key في الجسم
    [$status, $body] = paymobHttp('POST', $url, [
        'api_key' => (string) paymobConfigValue('api_key', ''),
    ]);

    // إذا لم يصل token نرمي استثناءاً ليُمسك في الملف المستدعي
    if ($status >= 400 || empty($body['token'])) {
        throw new RuntimeException('Paymob authentication failed');
    }

    // إرجاع التوكن
    return (string) $body['token'];
}

// ============================================================
// 9) إنشاء طلب (Order) لدى Paymob
// ============================================================
function paymobCreateOrder(string $authToken, float $amount, string $reference): int
{
    // عنوان إنشاء الطلب
    $url = rtrim((string) paymobConfigValue('base_url', 'https://accept.paymob.com/api'), '/') . '/ecommerce/orders';

    // Paymob يتعامل بالقروش وليس بالجنيه، لذلك نضرب في 100
    $amountCents = (int) round($amount * 100);

    // إرسال الطلب
    [$status, $body] = paymobHttp('POST', $url, [
        'auth_token' => $authToken,
        'delivery_needed' => false,
        'amount_cents' => $amountCents,
        'currency' => (string) paymobConfigValue('currency', 'EGP'),
        'merchant_order_id' => $reference,
        'items' => [],
    ]);

    // التحقق من وجود رقم الطلب
    if ($status >= 400 || empty($body['id'])) {
        throw new RuntimeException('Paymob create order failed');
    }

    // إرجاع رقم الطلب
    return (int) $body['id'];
}

// ============================================================
// 10) إنشاء payment_key لاستخدامه في IFrame أو المحفظة
//
// $cardToken: يُمرَّر فقط عند الدفع بكارت محفوظ سابقاً.
//             Paymob يقبل card_token داخل طلب payment_keys
//             وينفّذ الدفع دون إدخال بيانات الكارت مرة أخرى.
// ============================================================
function paymobCreatePaymentKey(
    string $authToken,
    int $orderId,
    float $amount,
    string $integrationId,
    int $userId,
    string $fullName,
    string $phone,
    bool $saveCard = false,
    ?string $cardToken = null
): string {
    // عنوان إنشاء مفتاح الدفع
    $url = rtrim((string) paymobConfigValue('base_url', 'https://accept.paymob.com/api'), '/') . '/acceptance/payment_keys';

    // تحويل المبلغ للقروش
    $amountCents = (int) round($amount * 100);

    // تجهيز بيانات العميل (إجباري في Paymob حتى لو غير مستخدمة)
    $billingData = [
        'first_name' => $fullName !== '' ? $fullName : 'Client',
        'last_name' => 'User',
        'phone_number' => $phone !== '' ? $phone : '01000000000',
        'email' => 'client' . $userId . '@cleanhouselife.app',
        'street' => 'NA',
        'building' => 'NA',
        'floor' => 'NA',
        'apartment' => 'NA',
        'city' => 'NA',
        'state' => 'NA',
        'country' => 'EG',
        'postal_code' => 'NA',
        'shipping_method' => 'NA',
    ];

    // جسم الطلب الأساسي
    $payload = [
        'auth_token' => $authToken,
        'amount_cents' => $amountCents,
        'expiration' => (int) paymobConfigValue('payment_key_expiration', 3600),
        'order_id' => $orderId,
        'billing_data' => $billingData,
        'currency' => (string) paymobConfigValue('currency', 'EGP'),
        'integration_id' => (int) $integrationId,
        'lock_order_when_paid' => true,
    ];

    // طلب حفظ الكارت بعد نجاح الدفع
    if ($saveCard) {
        $payload['save_card'] = true;
    }

    // الدفع بكارت محفوظ: نُمرّر التوكن بدلاً من بيانات الكارت
    if ($cardToken !== null && $cardToken !== '') {
        $payload['card_token'] = $cardToken;
    }

    // إرسال الطلب
    [$status, $body] = paymobHttp('POST', $url, $payload);

    // التحقق من وصول المفتاح
    if ($status >= 400 || empty($body['token'])) {
        throw new RuntimeException('Paymob payment key failed');
    }

    // إرجاع مفتاح الدفع
    return (string) $body['token'];
}

// ============================================================
// 11) بناء رابط الـ IFrame الذي يُعرض للعميل
// ============================================================
function paymobIframeUrl(string $paymentKey): string
{
    // رقم الـ IFrame من الإعدادات
    $iframeId = (string) paymobConfigValue('iframe_id', '');

    // إذا لم يُضبط نرمي استثناءاً واضحاً
    if ($iframeId === '') {
        throw new RuntimeException('Paymob iframe id is not configured');
    }

    // الرابط النهائي
    return rtrim((string) paymobConfigValue('iframe_url', 'https://accept.paymob.com/api/acceptance/iframes'), '/')
        . '/' . rawurlencode($iframeId)
        . '?payment_token=' . rawurlencode($paymentKey);
}

// ============================================================
// 12) الدفع عبر المحفظة الإلكترونية
//     يُعيد رابط إعادة التوجيه الذي يفتحه العميل لإتمام الدفع.
// ============================================================
function paymobWalletPay(string $paymentKey, string $walletPhone): string
{
    // عنوان دفع المحفظة
    $url = rtrim((string) paymobConfigValue('base_url', 'https://accept.paymob.com/api'), '/') . '/acceptance/payments/pay';

    // تنظيف رقم المحفظة: Paymob يفضّل 01xxxxxxxxx بدون +20
    $identifier = preg_replace('/[^0-9]/', '', $walletPhone);
    if (strpos($identifier, '20') === 0 && strlen($identifier) > 11) {
        $identifier = '0' . substr($identifier, 2);
    }

    // جسم الطلب
    [$status, $body] = paymobHttp('POST', $url, [
        'source' => [
            'identifier' => $identifier,
            'subtype' => 'WALLET',
        ],
        'payment_token' => $paymentKey,
    ]);

    // التحقق من رابط إعادة التوجيه
    if ($status >= 400 || empty($body['redirect_url'])) {
        throw new RuntimeException('Paymob wallet payment failed');
    }

    // إرجاع الرابط
    return (string) $body['redirect_url'];
}

// ============================================================
// 13) بناء نص HMAC بالترتيب الذي يطلبه Paymob
//     أي خطأ في الترتيب يجعل التحقق يفشل دائماً.
// ============================================================
function paymobHmacString(array $obj): string
{
    // دالة مساعدة لقراءة قيمة متداخلة مثل order.id أو source_data.pan
    // ملاحظة: رد الـ Callback قد يصل مسطّحاً (order=123) أو متداخلاً (order.id)
    // لذلك نجرب أكثر من صيغة قبل إرجاع سلسلة فارغة.
    $get = function (string $path) use ($obj) {
        // (1) المفتاح المسطّح كما هو (order.id)
        if (array_key_exists($path, $obj)) {
            $value = $obj[$path];
            return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        // (2) المفتاح بنمط PHP في GET حيث تُحوّل النقاط إلى شرطات سفلية
        $underscored = str_replace('.', '_', $path);
        if (array_key_exists($underscored, $obj)) {
            $value = $obj[$underscored];
            return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        // (3) المسار المتداخل (order => ['id' => 123])
        $parts = explode('.', $path);
        $current = $obj;
        foreach ($parts as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                // (4) حالة order.id مع order مسطّح يحمل الرقم نفسه
                if ($part === 'id' && is_scalar($current)) {
                    return is_bool($current) ? ($current ? 'true' : 'false') : (string) $current;
                }
                return '';
            }
            $current = $current[$part];
        }

        // القيم المنطقية تُحوّل إلى true/false نصياً كما يرسلها Paymob
        if (is_bool($current)) {
            return $current ? 'true' : 'false';
        }

        // باقي القيم تُحوّل لنص
        return (string) $current;
    };

    // الحقول بالترتيب الرسمي (لا تغيّر الترتيب)
    $fields = [
        'amount_cents',
        'created_at',
        'currency',
        'error_occured',
        'has_parent_transaction',
        'id',
        'integration_id',
        'is_3d_secure',
        'is_auth',
        'is_capture',
        'is_refunded',
        'is_standalone_payment',
        'is_voided',
        'order.id',
        'owner',
        'pending',
        'source_data.pan',
        'source_data.sub_type',
        'source_data.type',
        'success',
    ];

    // تجميع القيم بلا فواصل (حسب توثيق Paymob)
    $concatenated = '';
    foreach ($fields as $field) {
        $concatenated .= $get($field);
    }

    // إرجاع النص الذي سيُوقّع
    return $concatenated;
}

// ============================================================
// 14) التحقق من صحة توقيع HMAC
// ============================================================
function paymobVerifyHmac(array $obj, string $receivedHmac): bool
{
    // المفتاح السري
    $secret = (string) paymobConfigValue('hmac_secret', '');

    // لا يمكن التحقق بدون مفتاح أو بدون توقيع
    if ($secret === '' || $receivedHmac === '') {
        return false;
    }

    // حساب التوقيع المتوقع
    $expected = hash_hmac('sha512', paymobHmacString($obj), $secret);

    // مقارنة آمنة ضد هجمات التوقيت
    return hash_equals($expected, strtolower($receivedHmac));
}

// ============================================================
// 15) توليد رقم مرجعي داخلي فريد لكل عملية دفع
// ============================================================
function generatePaymentReference(int $bookingId, int $userId): string
{
    // الطابع الزمني بالميلي ثانية
    $time = (int) round(microtime(true) * 1000);

    // 6 أرقام عشوائية لتقليل فرصة التكرار
    $random = random_int(100000, 999999);

    // الصيغة: CHL-معرف الحجز-معرف المستخدم-الوقت-العشوائي
    return 'CHL-' . $bookingId . '-' . $userId . '-' . $time . '-' . $random;
}

// ============================================================
// 16) صفحة النتيجة التي يُعاد توجيه العميل إليها
// ============================================================
function paymentResultUrl(string $reference): string
{
    // العنوان العام للسيرفر
    $base = rtrim((string) paymobConfigValue('callback_base', ''), '/');

    // مسار صفحة النتيجة
    $path = (string) paymobConfigValue('result_page', '/payments/result.php');

    // إضافة المرجع كباراميتر
    return $base . $path . '?reference=' . rawurlencode($reference);
}

// ============================================================
// 17) تسجيل أي إشعار قادم من Paymob قبل معالجته
// ============================================================
function logGatewayPayload(
    PDO $con,
    string $eventType,
    ?string $reference,
    string $rawPayload,
    bool $hmacValid
): void {
    // إدخال سجل جديد في جدول payment_gateway_logs
    $stmt = $con->prepare(
        "INSERT INTO payment_gateway_logs
            (log_provider, log_event_type, log_reference, log_payload, log_hmac_valid)
         VALUES ('paymob', ?, ?, ?, ?)"
    );

    // تنفيذ الإدخال (1 أو 0 للحقل المنطقي)
    $stmt->execute([
        $eventType,
        $reference,
        $rawPayload,
        $hmacValid ? 1 : 0,
    ]);
}

// ============================================================
// 18) حفظ الكارت (Token) بعد نجاح الدفع
//    لا نحفظ رقم الكارت أبداً، فقط التوكن وآخر 4 أرقام.
// ============================================================
function savePaymentCard(PDO $con, int $userId, array $card): void
{
    // التوكن إجباري
    $token = trim((string) ($card['token'] ?? ''));
    if ($token === '') {
        return;
    }

    // منع تكرار نفس الكارت لنفس المستخدم
    $check = $con->prepare(
        "SELECT card_id FROM payment_cards
         WHERE card_user_id = ? AND card_token = ? LIMIT 1"
    );
    $check->execute([$userId, $token]);

    // إذا كان محفوظاً سابقاً نُحدّث بياناته فقط
    if ($check->fetchColumn()) {
        $update = $con->prepare(
            "UPDATE payment_cards
             SET card_mask = ?, card_last4 = ?, card_brand = ?, card_is_active = 1
             WHERE card_user_id = ? AND card_token = ?"
        );
        $update->execute([
            (string) ($card['mask'] ?? ''),
            (string) ($card['last4'] ?? ''),
            (string) ($card['brand'] ?? ''),
            $userId,
            $token,
        ]);
        return;
    }

    // هل هذه أول بطاقة للمستخدم؟ إذن تصبح افتراضية تلقائياً
    $count = $con->prepare("SELECT COUNT(*) FROM payment_cards WHERE card_user_id = ? AND card_is_active = 1");
    $count->execute([$userId]);
    $isDefault = ((int) $count->fetchColumn()) === 0 ? 1 : 0;

    // إدخال الكارت الجديد
    $insert = $con->prepare(
        "INSERT INTO payment_cards
            (card_user_id, card_provider, card_token, card_mask, card_last4, card_brand, card_is_default, card_is_active)
         VALUES (?, 'paymob', ?, ?, ?, ?, ?, 1)"
    );
    $insert->execute([
        $userId,
        $token,
        (string) ($card['mask'] ?? ''),
        (string) ($card['last4'] ?? ''),
        (string) ($card['brand'] ?? ''),
        $isDefault,
    ]);
}

// ============================================================
// 19) استخراج بيانات الكارت من رد Paymob
// ============================================================
function extractCardFromTransaction(array $obj): ?array
{
    // توكن الكارت قد يكون في token أو card_token حسب نوع الحساب
    $token = trim((string) ($obj['token'] ?? $obj['card_token'] ?? ''));

    // لا يوجد توكن ⇒ لا يوجد ما نحفظه
    if ($token === '') {
        return null;
    }

    // بيانات المصدر (نوع الكارت وآخر أرقام)
    $source = is_array($obj['source_data'] ?? null) ? $obj['source_data'] : [];

    // رقم الكارت المقنّع كما يرسله Paymob مثل 5123 **** **** 1234
    $pan = (string) ($source['pan'] ?? '');

    // استخراج آخر 4 أرقام من النص
    $digits = preg_replace('/[^0-9]/', '', $pan);
    $last4 = $digits !== '' ? substr($digits, -4) : '';

    // نوع الكارت (Visa / MasterCard) من sub_type
    $brand = (string) ($source['sub_type'] ?? '');

    // إرجاع البيانات الموحّدة
    return [
        'token' => $token,
        'mask' => $pan,
        'last4' => $last4,
        'brand' => $brand,
    ];
}

// ============================================================
// 20) تحديث حالة الدفع والحجز بشكل ذرّي (Transaction)
//     يُستخدم من Callback وWebhook معاً حتى لا يحدث تضارب.
// ============================================================
function applyPaymentResult(
    PDO $con,
    string $reference,
    bool $success,
    ?string $txnId,
    array $obj
): array {
    // بدء معاملة: إما تُحفظ كل التحديثات أو لا شيء
    $con->beginTransaction();

    try {
        // قراءة صف الدفع مع قفله لمنع معالجة مزدوجة
        $stmt = $con->prepare("SELECT * FROM payments WHERE payment_reference = ? FOR UPDATE");
        $stmt->execute([$reference]);
        $payment = $stmt->fetch();

        // إذا لم يوجد صف نُلغي المعاملة ونُعيد خطأ
        if (!$payment) {
            $con->rollBack();
            return ['ok' => false, 'reason' => 'payment_not_found'];
        }

        // إذا كان مدفوعاً بالفعل نُعيد نجاحاً بلا تعديل (Idempotency)
        if ($payment['payment_state'] === 'paid') {
            $con->commit();
            return ['ok' => true, 'already_paid' => true, 'payment' => $payment];
        }

        // الحالة الجديدة
        $newState = $success ? 'paid' : 'failed';

        // تحديث صف الدفع
        $update = $con->prepare(
            "UPDATE payments
             SET payment_state = ?,
                 payment_gateway_txn_id = ?,
                 payment_gateway_response = ?,
                 payment_failure_reason = ?,
                 payment_paid_at = ?
             WHERE payment_reference = ?"
        );
        $update->execute([
            $newState,
            $txnId,
            json_encode($obj, JSON_UNESCAPED_UNICODE),
            $success ? null : (string) (
                is_array($obj['data'] ?? null)
                ? ($obj['data']['message'] ?? 'payment_failed')
                : ($obj['message'] ?? 'payment_failed')
            ),
            $success ? date('Y-m-d H:i:s') : null,
            $reference,
        ]);

        // معرّف الحجز إن وُجد (دفعة أفراد)
        $bookingId = (int) ($payment['payment_booking_id'] ?? 0);
        // معرّف دفعة العقد إن وُجد
        $installmentId = (int) ($payment['payment_installment_id'] ?? 0);
        // معرّف العقد إن وُجد
        $contractId = (int) ($payment['payment_contract_id'] ?? 0);

        // تحديث الحجز المرتبط فقط إذا كانت الدفعة لحجز
        if ($bookingId > 0) {
            $bookingUpdate = $con->prepare(
                "UPDATE bookings
                 SET booking_payment_status = ?,
                     booking_paid_at = ?
                 WHERE booking_id = ?"
            );
            $bookingUpdate->execute([
                $newState,
                $success ? date('Y-m-d H:i:s') : null,
                $bookingId,
            ]);
        }

        // تحديث دفعة العقد عند نجاح الدفع عبر التطبيق
        if ($success && $installmentId > 0) {
            $instUpdate = $con->prepare(
                "UPDATE contract_installments SET
                    installment_status = 'paid',
                    installment_paid_at = NOW(),
                    installment_paid_via = 'app',
                    installment_payment_id = ?
                 WHERE installment_id = ?
                   AND installment_status <> 'paid'"
            );
            $instUpdate->execute([
                (int) ($payment['payment_id'] ?? 0),
                $installmentId,
            ]);
            // تفعيل العقد الموقّع بعد أول دفعة
            if ($contractId > 0) {
                $con->prepare(
                    "UPDATE contracts SET contract_status = 'active'
                     WHERE contract_id = ? AND contract_status = 'signed'"
                )->execute([$contractId]);
            }
        }

        // قراءة الحجز والعميل لإرسال الإشعارات إن وُجد حجز
        $booking = [];
        if ($bookingId > 0) {
            $bookingStmt = $con->prepare(
                "SELECT b.*, u.users_full_name, u.users_phone
                 FROM bookings b
                 JOIN users u ON u.users_id = b.booking_users_id
                 WHERE b.booking_id = ? LIMIT 1"
            );
            $bookingStmt->execute([$bookingId]);
            $booking = $bookingStmt->fetch() ?: [];
        }

        // قراءة دفعة العقد لإشعار الشركة إن وُجدت
        $installment = [];
        if ($installmentId > 0) {
            $instStmt = $con->prepare(
                "SELECT i.*, c.contract_id, c.contract_code, c.contract_users_id,
                        cr.company_name, u.users_full_name
                 FROM contract_installments i
                 INNER JOIN contracts c ON c.contract_id = i.installment_contract_id
                 LEFT JOIN company_registering cr ON cr.company_id = c.contract_company_id
                 LEFT JOIN users u ON u.users_id = c.contract_users_id
                 WHERE i.installment_id = ?
                 LIMIT 1"
            );
            $instStmt->execute([$installmentId]);
            $installment = $instStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        }

        // حفظ الكارت إذا أرجعه Paymob (بعد نجاح الدفع فقط)
        if ($success) {
            $card = extractCardFromTransaction($obj);
            if ($card !== null) {
                savePaymentCard($con, (int) $payment['payment_user_id'], $card);
            }
        }

        // تثبيت المعاملة
        $con->commit();

        // إرسال الإشعارات بعد الحفظ (خارج المعاملة لتفادي تعليقها)
        if (!empty($booking)) {
            notifyPaymentResult($booking, $success, (float) $payment['payment_total_amount'], $reference);
        }
        if (!empty($installment) && function_exists('notifyContractAppPayment')) {
            notifyContractAppPayment(
                $installment,
                $success,
                (float) $payment['payment_total_amount'],
                $reference
            );
        }

        // إرجاع النتيجة
        return ['ok' => true, 'payment' => $payment, 'booking' => $booking];
    } catch (Throwable $e) {
        // أي خطأ ⇒ تراجع كامل
        if ($con->inTransaction()) {
            $con->rollBack();
        }

        // إرجاع الخطأ للمستدعي
        return ['ok' => false, 'reason' => $e->getMessage()];
    }
}

// ============================================================
// 21) إشعار العميل والإدارة بنتيجة الدفع
// ============================================================
function notifyPaymentResult(array $booking, bool $success, float $amount, string $reference): void
{
    // تفويض لدالة الأحداث الموحّدة حتى يبقى مسار الصندوق واحداً
    if (function_exists('notifyBookingPaymentResult')) {
        notifyBookingPaymentResult($booking, $success, $amount, $reference);
        return;
    }

    // مسار احتياطي إن لم يُحمَّل ملف الـ hooks
    $name = (string) ($booking['users_full_name'] ?? 'عميل');
    $bookingId = (int) ($booking['booking_id'] ?? 0);
    $amountText = number_format($amount, 2);
    notifyEvent([
        'type' => $success ? 'booking_payment_paid' : 'booking_payment_failed',
        'title' => $success ? 'تم الدفع بنجاح' : 'فشل عملية الدفع',
        'body' => $success
            ? "تم دفع مبلغ {$amountText} جنيه للحجز رقم {$bookingId} بنجاح"
            : "لم تكتمل عملية دفع الحجز رقم {$bookingId}. يمكنك المحاولة مرة أخرى",
        'audience' => 'user',
        'actor_user_id' => (int) ($booking['booking_users_id'] ?? 0),
        'actor_name' => $name,
        'actor_role' => 'client',
        'ref_table' => 'bookings',
        'ref_id' => $bookingId,
        'route_name' => $success ? 'myBookingDetailsScreen' : 'paymentScreen',
        'user_ids' => [(int) ($booking['booking_users_id'] ?? 0)],
        'extra' => ['payment_reference' => $reference, 'booking_id' => $bookingId],
    ]);
    if ($success) {
        notifyEvent([
            'type' => 'booking_payment_received',
            'title' => 'دفعة حجز جديدة',
            'body' => "قام العميل {$name} بدفع {$amountText} جنيه للحجز رقم {$bookingId}",
            'audience' => 'admin',
            'actor_user_id' => (int) ($booking['booking_users_id'] ?? 0),
            'actor_name' => $name,
            'actor_role' => 'client',
            'ref_table' => 'bookings',
            'ref_id' => $bookingId,
            'route_name' => 'bookingDetails',
            'notify_admins' => true,
            'extra' => ['payment_reference' => $reference],
        ]);
    }
}

// ============================================================
// 22) الوصول إلى الرقم المرجعي لعملية الدفع من رد البوابة
//     نحاول أولاً merchant_order_id، ثم رقم الطلب لدى البوابة.
// ============================================================
function resolvePaymentReference(PDO $con, array $obj): ?string
{
    // (أ) الرقم المرجعي الداخلي يصل مباشرة في بعض إصدارات الرد
    $reference = trim((string) ($obj['merchant_order_id'] ?? $obj['order']['merchant_order_id'] ?? ''));

    // إذا وُجد نُعيده مباشرة
    if ($reference !== '') {
        return $reference;
    }

    // (ب) وإلا نستخدم رقم الطلب لدى البوابة للبحث في جدول payments
    $gatewayOrderId = (string) ($obj['order']['id'] ?? $obj['order'] ?? $obj['order_id'] ?? '');

    // لا يوجد رقم طلب ⇒ لا يمكن تحديد العملية
    if ($gatewayOrderId === '') {
        return null;
    }

    // البحث في جدول المدفوعات
    $stmt = $con->prepare(
        "SELECT payment_reference FROM payments WHERE payment_gateway_order_id = ? LIMIT 1"
    );
    $stmt->execute([$gatewayOrderId]);

    // القيمة أو null
    $found = $stmt->fetchColumn();
    return $found !== false ? (string) $found : null;
}

// ============================================================
// 23) قراءة قيمة منطقية من رد البوابة
//     Paymob قد يرسلها true/false أو "true"/"false" أو 1/0.
// ============================================================
function gatewayBool($value): bool
{
    // المقارنة النصية تغطي كل الاحتمالات
    return in_array(strtolower((string) $value), ['true', '1', 'yes'], true);
}

// ============================================================
// 24) قراءة Query String الخام دون تغيير النقاط إلى شرطات سفلية
//     PHP تحوّل source_data.pan إلى source_data_pan في $_GET،
//     وهذا يُفسد حساب HMAC، لذلك نُحلّل النص يدوياً.
// ============================================================
function parseRawQuery(string $queryString): array
{
    // مصفوفة النتيجة
    $result = [];

    // إزالة علامة ? إن وُجدت
    $queryString = ltrim($queryString, '?');

    // لا يوجد شيء للتحليل
    if ($queryString === '') {
        return $result;
    }

    // تقسيم على &
    foreach (explode('&', $queryString) as $pair) {
        // تجاهل الأجزاء الفارغة
        if ($pair === '') {
            continue;
        }

        // تقسيم المفتاح عن القيمة عند أول =
        $parts = explode('=', $pair, 2);

        // فك ترميز المفتاح (يبقى النقطة كما هي)
        $key = urldecode($parts[0]);

        // فك ترميز القيمة إن وُجدت
        $value = isset($parts[1]) ? urldecode($parts[1]) : '';

        // تخزين القيمة
        $result[$key] = $value;
    }

    // إرجاع المصفوفة
    return $result;
}

// ============================================================
// 25) تجهيز Transaction Object موحّد من رد Callback المسطّح
//     حتى يصلح للحفظ في قاعدة البيانات وأيضاً للإشعارات.
// ============================================================
function normalizeCallbackObject(array $flat): array
{
    // نسخة من البيانات
    $obj = $flat;

    // تحويل order المسطّح إلى مصفوفة متداخلة أيضاً
    if (isset($flat['order']) && !is_array($flat['order'])) {
        $obj['order'] = ['id' => $flat['order']];
    }

    // نفس الشيء لبيانات المصدر
    $source = [];
    foreach (['pan', 'sub_type', 'type'] as $key) {
        // نحاول الصيغة المسطّحة بالنقطة أولاً
        if (isset($flat['source_data.' . $key])) {
            $source[$key] = $flat['source_data.' . $key];
        } elseif (isset($flat['source_data_' . $key])) {
            // ثم الصيغة التي أنتجها PHP
            $source[$key] = $flat['source_data_' . $key];
        }
    }
    if (!empty($source)) {
        $obj['source_data'] = $source;
    }

    // إرجاع الكائن الموحّد
    return $obj;
}
