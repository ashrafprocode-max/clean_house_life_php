<?php
// ==========================================================
//* ملف الوظائف العامة - نسخة مطورة 2026
//* مخصص لتطبيق Clean House Life
// ==========================================================

date_default_timezone_set("Africa/Cairo");

if (!function_exists('appBrandNameEn')) {
    function appBrandConfig(): array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $path = __DIR__ . '/config/app_brand.php';
        $loaded = is_file($path) ? include $path : [];
        $cached = is_array($loaded) ? $loaded : [
            'id' => 'clean_house_life',
            'name_ar' => 'حياة منزلية نظيفة',
            'name_en' => 'Clean House Life',
        ];
        return $cached;
    }

    function appBrandNameEn(): string
    {
        return (string) (appBrandConfig()['name_en'] ?? 'Clean House Life');
    }

    function appBrandNameAr(): string
    {
        return (string) (appBrandConfig()['name_ar'] ?? 'حياة منزلية نظيفة');
    }
}

/**
 * تعريف الثوابت
 */
define("MB", 1048576);
// define("MB", 1048576) يعني متغير ثابت مثل const فنضع define

/**
 * 1. دالة تنظيف البيانات (المدخلات)
 * التعديل: أضفنا فحصاً للتأكد من أن القيمة نصية قبل التنظيف لتجنب أخطاء PHP 8+
 */
function filterRequest($requestname): string
{
    // قراءة البيانات القادمة بصيغة JSON وتحويلها لمصفوفة
    // دعم استقبال البيانات من JSON body
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (isset($_POST[$requestname])) {
        // قراءة البيانات القادمة بصيغة JSON وتحويلها لمصفوفة
        return htmlspecialchars(strip_tags($_POST[$requestname]));
    } elseif (isset($data[$requestname])) {
        // تنظيف البيانات القادمة من JSON Body
        return htmlspecialchars(strip_tags($data[$requestname]));
    } elseif (isset($_GET[$requestname])) {
        // تنظيف البيانات القادمة من URL
        return htmlspecialchars(strip_tags($_GET[$requestname]));
    } else {
        return ""; // قيمة افتراضية فارغة بدل null
    }
}

/**
 * 2. دالة إرسال رد النجاح بتنسيق JSON
 * الإضافة: هذه الدالة أساسية لكي يفهم تطبيق Flutter أن العملية تمت بنجاح
 * السبب: توحيد شكل الرد يسهل عليك كتابة الـ Model في Dart
 * تدعم إرسال رسالة نصية أو مصفوفة بيانات كاملة لـ Flutter
 */
function printSuccess($messageOrData = "none")
{
    if (is_array($messageOrData)) {
        // إذا أرسلنا مصفوفة، تظهر تحت مفتاح data
        echo json_encode(array("status" => "success", "data" => $messageOrData));
    } else {
        // إذا أرسلنا نص، يظهر تحت مفتاح message
        echo json_encode(array("status" => "success", "message" => $messageOrData));
    }
}
/**
 * 3. دالة إرسال رد الفشل بتنسيق JSON
 * الإضافة: إرسال رسالة توضح سبب الفشل
 * السبب: لكي تظهر رسالة تنبيه للمستخدم في Flutter تخبره بالخطأ (مثلاً: الهاتف مستخدم من قبل)
 * توحد شكل الخطأ لسهولة التعامل معه في Flutter
 */
// function printFailure($message = "none") {
//     echo json_encode(array(
//         "status" => "failure",
//         "message" => $message
//     ));
// }
function printFailure($message = "Server Error", $code = 400, $errors = null)
{
    http_response_code($code);

    echo json_encode([
        "message" => $message,
        "code" => $code,
        "data" => $errors
    ]);

    exit;
}

/**
 * دالة إرسال OTP عبر SMS باستخدام Twilio
 * 
 * @param string $phone رقم الهاتف (يجب أن يكون بالصيغة الدولية مثل +201122449170)
 * @param string $code رمز OTP المكون من 6 أرقام
 * @return bool true إذا تم الإرسال بنجاح، false عند الفشل
 * 
 * ملاحظات هامة:
 * - في الحساب التجريبي: يجب أن يكون الرقم المستلم مُتحقق منه في Verified Caller IDs
 * - في الحساب المدفوع: يمكنك الإرسال لأي رقم
 */

function sendOtpSms($phone, $code)
{

    // ========== 1. تضمين ملف إعدادات Twilio ==========
    // نستخدم require_once لضمان تحميل الملف مرة واحدة فقط
    require_once __DIR__ . '/config/twilio_config.php';

    // ========== 2. التحقق من صحة رقم الهاتف ==========
    // يجب أن يكون الرقم بالصيغة الدولية (يبدأ بـ +)
    // مثال: +201122449170 (مصر) أو +966501234567 (السعودية)
    if (empty($phone) || $phone[0] !== '+') {
        // إذا لم يبدأ بـ + نفترض أنه رقم مصري ونضيف +20
        if (strlen($phone) === 11 && $phone[0] === '0') {
            // تحويل 01122449170 إلى +201122449170
            $phone = '+2' . $phone;
        } elseif (strlen($phone) === 10) {
            // تحويل 1122449170 إلى +201122449170
            $phone = '+20' . $phone;
        }
    }

    // ========== 3. تجهيز نص الرسالة ==========
    // نكتب الرسالة بالعربية والإنجليزية لضمان وصولها بشكل صحيح
    $message = appBrandNameEn() . " - كود التحقق الخاص بك هو: $code\n";
    $message .= "Your verification code is: $code\n";
    $message .= "صالح لمدة 5 دقائق فقط.";

    // ========== 4. تجهيز بيانات الطلب لـ Twilio API ==========
    // Twilio يستخدم POST request مع بيانات form-urlencoded
    $postData = [
        // الرقم المُرسِل (رقم Twilio الذي اشتريته)
        'From' => TWILIO_PHONE_NUMBER,
        // الرقم المستلم (رقم المستخدم)
        'To' => $phone,
        // نص الرسالة
        'Body' => $message
    ];

    // ========== 5. إعداد cURL لإرسال الطلب ==========
    // cURL هي مكتبة PHP للتواصل مع APIs الخارجية
    $ch = curl_init();

    // تعيين رابط API
    curl_setopt($ch, CURLOPT_URL, TWILIO_API_URL);

    // تفعيل طريقة POST
    curl_setopt($ch, CURLOPT_POST, true);

    // إرسال البيانات مع الطلب
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));

    // استقبال الرد كنص بدلاً من طباعته مباشرة
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // ========== 6. المصادقة مع Twilio (Basic Auth) ==========
    // Twilio يستخدم Account SID كـ username و Auth Token كـ password
    curl_setopt($ch, CURLOPT_USERPWD, TWILIO_ACCOUNT_SID . ':' . TWILIO_AUTH_TOKEN);

    // ========== 7. تنفيذ الطلب واستقبال الرد ==========
    $response = curl_exec($ch);

    // الحصول على كود حالة HTTP (200 = نجاح)
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    // التحقق من وجود خطأ في cURL نفسها
    $curlError = curl_error($ch);

    // إغلاق اتصال cURL
    curl_close($ch);

    // ========== 8. تسجيل النتيجة للمراجعة (Debugging) ==========
    // هذا السطر يساعدك في معرفة ما حدث عند وجود مشكلة
    // يمكنك العثور على الـ logs في: xampp/php/logs/php_error_log
    error_log("Twilio SMS Response - Phone: $phone, HTTP Code: $httpCode");

    // ========== 9. التحقق من نجاح الإرسال ==========
    // Twilio يُرجع كود 201 عند نجاح إنشاء الرسالة
    if ($httpCode === 201 || $httpCode === 200) {
        return true;
    }

    // ========== 10. معالجة الأخطاء ==========
    // في حالة الفشل، نسجل الخطأ للمراجعة
    if ($curlError) {
        error_log("Twilio cURL Error: $curlError");
    }

    return false;
}

/**
 * توحيد رقم الهاتف إلى الصيغة الدولية قبل إرسال واتساب
 *
 * @param string $phone الرقم كما وصل من التطبيق أو قاعدة البيانات
 * @return string رقم يبدأ بـ + مثل +201122449170
 */
function normalizeOtpPhone($phone)
{
    // حذف المسافات والرموز ما عدا الأرقام وعلامة +
    $phone = preg_replace('/[^0-9+]/', '', (string) $phone);

    // إن كان الرقم فارغاً بعد التنظيف نرجعه كما هو
    if ($phone === '') {
        return $phone;
    }

    // إن كان يبدأ بـ + فهو دولي بالفعل
    if (isset($phone[0]) && $phone[0] === '+') {
        return $phone;
    }

    // رقم محلي مصري بطول 11 يبدأ بصفر: 01122449170 → +201122449170
    if (strlen($phone) === 11 && $phone[0] === '0') {
        return '+2' . $phone;
    }

    // رقم مصري بدون صفر بطول 10: 1122449170 → +201122449170
    if (strlen($phone) === 10) {
        return '+20' . $phone;
    }

    // رقم يبدأ بكود مصر 20 بدون +
    if (substr($phone, 0, 2) === '20') {
        return '+' . $phone;
    }

    // أي حالة أخرى نضيف +20 احتياطاً للأرقام المصرية
    return '+20' . ltrim($phone, '0');
}

/**
 * إرسال OTP عبر واتساب باستخدام Twilio WhatsApp API
 *
 * نفس Endpoint الخاص بـ SMS: Messages.json
 * الفرق فقط: From و To يبدأان بـ whatsapp:
 *
 * ملاحظة التسعير:
 * - الصندوق التجريبي Sandbox مجاني للاختبار
 * - الإنتاج مدفوع بنظام المحادثة (WhatsApp Conversation)
 * - SMS أيضاً مدفوع؛ نبقي القناتين معاً كما طُلب
 *
 * @param string $phone رقم المستلم بأي صيغة محلية أو دولية
 * @param string $code كود OTP المكوّن من 6 أرقام
 * @return bool true عند HTTP 200/201 من Twilio
 */
function sendOtpWhatsApp($phone, $code)
{
    // تحميل ثوابت الحساب ورقم مرسل واتساب مرة واحدة
    require_once __DIR__ . '/config/twilio_config.php';

    // إن أُوقف واتساب من الإعداد نخرج فوراً دون طلب شبكة
    if (!defined('TWILIO_WHATSAPP_ENABLED') || TWILIO_WHATSAPP_ENABLED !== true) {
        return false;
    }

    // توحيد الرقم إلى +20... قبل إضافة بادئة whatsapp:
    $phone = normalizeOtpPhone($phone);

    // رفض الإرسال إن بقي الرقم بلا + بعد التوحيد
    if ($phone === '' || $phone[0] !== '+') {
        error_log('Twilio WhatsApp skipped: invalid phone after normalize');
        return false;
    }

    // نص الرسالة مطابق لـ SMS حتى يكون الكود واحداً على القناتين
    $message = appBrandNameEn() . " - كود التحقق الخاص بك هو: $code\n";
    $message .= "Your verification code is: $code\n";
    $message .= "صالح لمدة 5 دقائق فقط.";

    // Twilio يتطلب بادئة whatsapp: على المرسل والمستلم
    $postData = [
        'From' => TWILIO_WHATSAPP_FROM,
        'To' => 'whatsapp:' . $phone,
        'Body' => $message,
    ];

    // بدء جلسة cURL لطلب Twilio
    $ch = curl_init();

    // نفس رابط Messages.json المستخدم في SMS
    curl_setopt($ch, CURLOPT_URL, TWILIO_API_URL);

    // الإرسال بطريقة POST
    curl_setopt($ch, CURLOPT_POST, true);

    // ترميز الحقول application/x-www-form-urlencoded
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));

    // إرجاع الرد كنص بدلاً من طباعته
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // Basic Auth: Account SID + Auth Token
    curl_setopt($ch, CURLOPT_USERPWD, TWILIO_ACCOUNT_SID . ':' . TWILIO_AUTH_TOKEN);

    // تنفيذ الطلب
    $response = curl_exec($ch);

    // قراءة كود HTTP
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    // قراءة خطأ طبقة cURL إن وُجد
    $curlError = curl_error($ch);

    // إغلاق الجلسة
    curl_close($ch);

    // تسجيل النتيجة بدون طباعة الكود نفسه في السجل
    error_log("Twilio WhatsApp Response - Phone: $phone, HTTP Code: $httpCode");

    // 201 تعني إنشاء الرسالة بنجاح
    if ($httpCode === 201 || $httpCode === 200) {
        return true;
    }

    // تسجيل فشل cURL للمراجعة
    if ($curlError) {
        error_log("Twilio WhatsApp cURL Error: $curlError");
    }

    // تسجيل مقتطف من رد Twilio بدون بيانات حساسة زائدة
    if (is_string($response) && $response !== '') {
        error_log('Twilio WhatsApp body: ' . substr($response, 0, 300));
    }

    return false;
}

/**
 * إرسال نفس كود OTP على SMS وواتساب معاً
 *
 * SMS تبقى كما هي (قد تفشل قبل الاشتراك)
 * واتساب يعمل بجانبها
 * النجاح = نجاح أي قناة واحدة على الأقل
 *
 * @param string $phone رقم الهاتف
 * @param string $code كود OTP
 * @return array{sms:bool,whatsapp:bool,ok:bool}
 */
function sendOtpChannels($phone, $code)
{
    // تحميل مفاتيح التفعيل
    require_once __DIR__ . '/config/twilio_config.php';

    // نتيجة SMS الافتراضية: لم يُرسل
    $smsOk = false;

    // نتيجة واتساب الافتراضية: لم يُرسل
    $waOk = false;

    // إرسال SMS فقط إذا القناة مفعّلة — الدالة الأصلية بلا تعديل سلوكها الداخلي
    if (!defined('TWILIO_SMS_ENABLED') || TWILIO_SMS_ENABLED === true) {
        $smsOk = sendOtpSms($phone, $code);
    }

    // إرسال واتساب بجانب SMS
    if (defined('TWILIO_WHATSAPP_ENABLED') && TWILIO_WHATSAPP_ENABLED === true) {
        $waOk = sendOtpWhatsApp($phone, $code);
    }

    // يكفي نجاح قناة واحدة حتى لا نمنع الدخول إن فشل SMS غير المدفوع
    return [
        'sms' => $smsOk,
        'whatsapp' => $waOk,
        'ok' => ($smsOk || $waOk),
    ];
}

//دالة التحقق من OTP
function verifyOtp($phone, $otp_code)
{
    global $con;
    try {
        $stmt = $con->prepare("SELECT otp_code, otp_created_at FROM otp_codes WHERE otp_phone = ? ORDER BY otp_created_at DESC LIMIT 1");
        $stmt->execute(array($phone));
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            return false;
        }

        if ($result['otp_code'] !== $otp_code) {
            return false;
        }

        // التحقق من انتهاء صلاحية الـ OTP (صالح لمدة 10 دقائق)
        $otp_created_time = strtotime($result['otp_created_at']);
        $current_time = time();
        if (($current_time - $otp_created_time) > 600) { // 600 ثانية = 10 دقائق
            return false;
        }

        return true;
    } catch (Exception $e) {
        return false;
    }
}

// /**
//  * دالة إرسال OTP عبر SMS
//  * اربطها بمزود SMS (Twilio, Unifonic, VictoryLink, إلخ)
//  * @param string $phone رقم الهاتف
//  * @param string $code رمز OTP
//  * @return bool هل تم الإرسال بنجاح
//  */
// function sendOtpSms($phone, $code) {
//     // TODO: ربط بمزود SMS حقيقي
//     // مثال Twilio: 
//     // $client = new Twilio\Rest\Client($sid, $token);
//     // $client->messages->create($phone, ['from' => $twilio_number, 'body' => "كود التحقق: $code"]);
//     // مثال Unifonic مصر: استخدام API الخاص بهم
//     return false; // حتى تربط مزود SMS، أرجع false ليعمل الوضع التجريبي
// }



// /**
//  * التحقق من صحة OTP
//  * @param string $phone رقم الهاتف
//  * @param string $code رمز OTP
//  * @return bool|int false عند الفشل، otp_id عند النجاح
//  */
// function verifyOtp($phone, $code) {
//     global $con;
//     $stmt = $con->prepare("SELECT otp_id FROM otp_codes WHERE otp_phone = ? AND otp_code = ? AND otp_expires_at > NOW() AND otp_verified = 0");
//     $stmt->execute([$phone, $code]);
//     $row = $stmt->fetch(PDO::FETCH_ASSOC);
//     if ($row) {
//         // تعليم الرمز كمُتحقّق منه
//         $up = $con->prepare("UPDATE otp_codes SET otp_verified = 1 WHERE otp_id = ?");
//         $up->execute([$row['otp_id']]);
//         return (int) $row['otp_id'];
//     }
//     return false;
// }



/**
 * 4. دالة التحقق من الهوية (Authentication)
 * التعديل: قمنا بتطويرها لتدعم الـ Basic Auth كبداية حماية بسيطة
 * الأهمية: تمنع أي شخص من طلب الـ API مباشرة من المتصفح دون إذن التطبيق
 */
function checkAuthenticate()
{
    // هذه المتغيرات يجب أن تعرفها في ملف connect.php
    global $auth_user, $auth_pass;
    if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
        // يمكنك تغيير هذه القيم في ملف connect أو هنا
        if ($_SERVER['PHP_AUTH_USER'] == $auth_user && $_SERVER['PHP_AUTH_PW'] == $auth_pass) {
            return true;
        }
    }
    // إذا فشل التحقق، نرسل خطأ 401 (غير مصرح له)
    header('WWW-Authenticate: Basic realm="My Realm"');
    header('HTTP/1.0 401 Unauthorized');
    printFailure("Unauthorized Access");
    exit;
}

/**
 * 5. دالة رفع الصور (Image Upload) - نسخة 2026
 * الإضافة: دالة موحدة لرفع الصور (بطاقة، فيش، بروفايل)
 * الأهمية: توفر عليك تكرار كود الرفع في كل ملف، وتضمن تسمية الصور بشكل فريد لمنع التداخل
 */
function imageUpload(string $imageRequest): string
{
    if (isset($_FILES[$imageRequest])) {
        $imgName = $_FILES[$imageRequest]['name'];
        $imgTmp = $_FILES[$imageRequest]['tmp_name'];
        $imgSize = $_FILES[$imageRequest]['size'];

        // استخراج الامتداد وتحويله لحروف صغيرة
        $allowExt = array("jpg", "png", "gif", "mp3", "pdf", "svg", "jpeg");
        $strToArray = explode(".", $imgName);
        $ext = end($strToArray);
        $ext = strtolower($ext);

        // التحقق من الامتداد والحجم (أقل من 5 ميجا مثلاً)
        if (!empty($imgName) && !in_array($ext, $allowExt)) {
            return "extension_error";
        }
        if ($imgSize > 5 * MB) { // هنا استخدمنا الثابت MB الذي عرفناه
            return "size_error";
        }

        // إنشاء اسم فريد للصورة لمنع التكرار
        $newName = "CH_" . uniqid() . "_" . time() . "." . $ext;

        if (move_uploaded_file($imgTmp, "../upload/" . $newName)) {
            return $newName;
        }
    }
    return "fail";
}

/**
 * 6. دالة حذف الملفات
 * الأهمية: تستخدم عند تحديث صورة البروفايل لحذف الصورة القديمة وتوفير مساحة السيرفر
 */
function deleteFile(string $dir, string $imagename): void
{
    //التحقق من وجود الملف قبل حذفهfile_exists
    if (file_exists($dir . "/" . $imagename)) {
        //unlink بتقوم بحذف الملف
        unlink($dir . "/" . $imagename);
    }
}

/**
 * 7. دالة جلب كل البيانات (getAllData)
 * تستخدم لجلب سجلات متعددة (مثل قائمة العمال) مع شرط اختياري
 * دالة getAllData (بمنطقك الذي يدعم التبديل بين JSON والـ Array)
 */
function getAllData(string $table, $where = null, $values = null, bool $json = true): int
{
    global $con; // إضافة ضرورية للوصول للاتصال
    $data = array();
    if ($where == null) {
        $stmt = $con->prepare("SELECT  * FROM $table ");
    } else {
        $stmt = $con->prepare("SELECT  * FROM $table WHERE   $where ");
    }

    $stmt->execute($values);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $count = $stmt->rowCount();
    //لاننا مش عايزين نطبع json مرتين
    if ($json == true) {
        if ($count > 0) {
            printSuccess($data);
        } else {
            printFailure("No Data Found");
        }
        return $count;
    } else {
        // لو json مش true يعني في تغيير في القيمة
        if ($count > 0) {
            //اطبعلي الداتا return
            // return $data;
            return $count;
        } else {
            // اما "status" :"failure"
            // return json_encode(array("status" => "failure"));
            return $count;
        }
    }
}

/**
 * 8. دالة جلب سجل واحد (getData)
 * تستخدم مثلاً لجلب بيانات مستخدم واحد عند تسجيل الدخول
 * دالة getData (بإضافتك لـ $json للتحكم في نوع المخرج)
 */
function getData(string $table, $where = null, $values = null, $json = true)
{
    global $con;
    $data = array();
    $stmt = $con->prepare("SELECT  * FROM $table WHERE   $where ");
    $stmt->execute($values);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    $count = $stmt->rowCount();
    //لاننا مش عايزين نطبع json مرتين
    if ($json == true) {
        if ($count > 0) {
            printSuccess($data);
        } else {
            printFailure("No Data Found");
        }
    } else {
        return $count;
    }
}
/**
 * 9. دالة إدخال البيانات (insertData)
 * تقوم بعملية الـ INSERT بشكل آلي بمجرد تمرير اسم الجدول والمصفوفة
 */
function insertData(string $table, array $data, bool $json = true): int
{
    global $con;
    $ins = [];
    foreach ($data as $field => $v)
        $ins[] = ':' . $field;
    $ins = implode(',', $ins);
    $fields = implode(',', array_keys($data));
    $sql = "INSERT INTO $table ($fields) VALUES ($ins)";

    $stmt = $con->prepare($sql);
    foreach ($data as $f => $v) {
        $stmt->bindValue(':' . $f, $v);
    }
    $stmt->execute();
    $count = $stmt->rowCount();
    if ($json == true) {
        if ($count > 0) {
            printSuccess();
        } else {
            printFailure();
        }
    }
    return $count;
}

/**
 * 10. دالة updateData
 */

/**
 * 10. دالة updateData
 *
 * ⭐ تعديل 2026: إصلاح باگ فقدان NULL
 *
 * ما كانت المشكلة؟
 *   الكود القديم كان يفعل:  $vals[] = "$val";
 *   وعلامتا التنصيص تُجبران PHP على تحويل القيمة لنص
 *   و null عند تحويله لنص يصبح "" (نص فارغ) وليس NULL
 *   فكان مستحيلاً مسح أي عمود اختياري وإرجاعه لـ NULL
 *
 * ما الفرق العملي بين "" و NULL؟
 *   1. WHERE col IS NULL لا يجد الصفوف التي بها ""
 *   2. في PHP:  $row['col'] ?? 'بديل'  لا يعمل مع "" لأنها ليست null
 *   3. في فلاتر: col != null تكون true لنص فارغ فتُرسم واجهة فارغة
 *
 * ما الحل؟
 *   استخدام معاملات مُسمّاة (:column) مع bindValue وتحديد النوع
 *   PDO::PARAM_NULL للقيم الفارغة و PDO::PARAM_STR لغيرها
 *   وهذا نفس أسلوب دالة insertData الموجودة أعلاه فيصبح الملف متسقاً
 *
 * ⚠️ متوافقة تماماً مع كل الاستدعاءات القديمة (10 مواضع في المشروع)
 *    لأن الأرقام والنصوص تُكتب كما كانت، والتغيير الوحيد
 *    أن null صار يُكتب NULL حقيقية بدلاً من نص فارغ
 */
function updateData(string $table, array $data, string $where, bool $json = true): int
{
    // الوصول لاتصال قاعدة البيانات المُعرَّف في connect.php
    global $con;

    // مصفوفة تحمل أجزاء جملة SET
    $cols = array();

    // نبني لكل عمود معاملاً مُسمّى باسمه
    foreach ($data as $key => $val) {
        // ⭐ معامل مُسمّى بدل علامة الاستفهام لنستطيع تحديد نوعه لاحقاً
        $cols[] = "`$key` = :$key";
    }

    // تجميع جملة التحديث الكاملة
    $sql = "UPDATE $table SET " . implode(', ', $cols) . " WHERE $where";

    // تجهيز الاستعلام
    $stmt = $con->prepare($sql);

    // ربط كل قيمة بمعاملها مع تحديد نوعها الصحيح
    foreach ($data as $f => $v) {
        // ⭐ هذا هو جوهر الإصلاح
        // نُخبر PDO صراحةً أن القيمة NULL وليست نصاً
        // فتُكتب في العمود NULL حقيقية بدلاً من ""
        $stmt->bindValue(
            ':' . $f,
            $v,
            $v === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
    }

    // تنفيذ الاستعلام
    $stmt->execute();

    // عدد الصفوف المتأثرة فعلاً
    $count = $stmt->rowCount();

    // طباعة رد JSON إذا طُلب ذلك
    if ($json == true) {
        if ($count > 0) {
            printSuccess();
        } else {
            printFailure();
        }
    }

    // إرجاع عدد الصفوف للملف المستدعي
    return $count;
}

/*
function updateData(string $table, array $data, string $where, bool $json = true): int
{
    global $con;
    $cols = array();
    $vals = array();

    foreach ($data as $key => $val) {
        $vals[] = "$val";
        $cols[] = "`$key` =  ? ";
    }
    $sql = "UPDATE $table SET " . implode(', ', $cols) . " WHERE $where";

    $stmt = $con->prepare($sql);
    $stmt->execute($vals);
    $count = $stmt->rowCount();
    if ($json == true) {
    if ($count > 0) {
        printSuccess();
    } else {
        printFailure();
    }
    }
    return $count;
}
*/

/**
 * 11. دالة deleteData
 */
function deleteData(string $table, string $where, bool $json = true)
{
    global $con;
    $stmt = $con->prepare("DELETE FROM $table WHERE $where");
    $stmt->execute();
    $count = $stmt->rowCount();
    if ($json == true) {
        if ($count > 0) {
            printSuccess();
        } else {
            printFailure();
        }
    }
    return $count;
}

/***********
 * 12. الدوال المتقدمة (الإشعارات و Firebase)
 * ملاحظة: هذه الدوال تحتاج لـ Service Account Key من Firebase Console
 */
/*
function getAccessToken($path_to_json_file) {
    // هذه الدالة تتطلب مكتبة Google Auth لمشروع 2026
    // سأضع لك الهيكل الأساسي وسنشغلها عند ربط الـ Notification فعلياً
    return "ACCESS_TOKEN_FROM_GOOGLE"; 
}

function sendNotification($title, $body, $topic, $pageid, $pagename) {
    // كود إرسال الإشعار عبر FCM V1 (النسخة الحديثة 2026)
    $url = 'https://fcm.googleapis.com/v1/projects/YOUR_PROJECT_ID/messages:send';
    // تفاصيل الـ Payload
}

function insertNotify($userid, $title, $body, $topic, $pageid, $pagename) {
    // تسجيل الإشعار في قاعدة البيانات لكي يراه المستخدم في "التنبيهات"
    $data = array(
        "notes_usersid" => $userid,
        "notes_title"   => $title,
        "notes_body"    => $body
    );
    insertData("notification", $data, false);
    sendNotification($title, $body, $topic, $pageid, $pagename);
}
*******************/


/*
function imageUpload($dir , $imageRequest)
{
    //global $msgError لكي نعرفه في الصفحة اللي هنستخدم فيها الفينكشن
  global $msgError;
  //لو يوجد صورة
  if (isset($_FILES[$imageRequest])) {
    // حطينا بجوار الاسم رقم عشوائي حتي لاتتشابه الاسامي
    $imagename  = rand(1000, 10000) . $_FILES[$imageRequest]['name'];
   // $imagename = preg_replace('/[^A-Za-z0-9_\-\.]/', '', $imagename); // تنظيف الاسم
    // المسار المؤقت اللي هنرفع منه الصورة
    $imagetmp   = $_FILES[$imageRequest]['tmp_name'];
    $imagesize  = $_FILES[$imageRequest]['size'];
    // الامتدادات المسموح بها
    $allowExt   = array("jpg", "png", "gif", "mp3", "pdf" ,"svg","jpeg");
    $strToArray = explode(".", $imagename);
    $ext        = end($strToArray);
    //لتحويل الامتداد الي احرف صغيرة
    $ext        = strtolower($ext);

    if (!empty($imagename) && !in_array($ext, $allowExt)) {
      $msgError = "EXT";
    }
    //يعني ليس اكبر من 2 ميجا حتي لاتظهر رسالة الخطاء
    if ($imagesize > 10 * MB) {
      $msgError = "size";
    }
//     if (!file_exists($dir)) {
//     mkdir($dir, 0777, true);
//    }
    if (empty($msgError)) {
        //لرفع الصورة في المسار اللي هنحدده
      move_uploaded_file($imagetmp,  $dir . "/" . $imagename);
      return $imagename;
    } else {
      return "fail";
    }
  }else {
    return "empty";
  }


}
*/

function imageUploadtwo(string $dir, string $imageRequest): array|string|null
{
    // التحقق من وجود الملف
    if (!isset($_FILES[$imageRequest])) {
        return "empty";
    }

    // التحقق من وجود خطأ في الرفع
    if ($_FILES[$imageRequest]['error'] !== UPLOAD_ERR_OK) {
        return "fail";
    }

    // إنشاء اسم فريد للملف
    $originalName = $_FILES[$imageRequest]["name"];
    $extension = pathinfo($originalName, PATHINFO_EXTENSION);
    $imagename = rand(1000, 10000000) . '_' . time() . '.' . $extension;

    // تنظيف الاسم
    $imagename = preg_replace('/[^A-Za-z0-9._-]/', '', $imagename);

    // الحصول على المسار المؤقت والحجم
    $imagetmp = $_FILES[$imageRequest]["tmp_name"];
    $imagesize = $_FILES[$imageRequest]["size"];

    // التحقق من الامتدادات المسموحة
    $allowExt = array("jpg", "png", "gif", "jpeg", "svg", "mp3", "pdf", "webp");
    $strToArray = explode(".", $imagename);
    $ext = strtolower(end($strToArray));

    // التحقق من صحة البيانات
    if (empty($imagename)) {
        return "fail";
    }
    if (!in_array($ext, $allowExt)) {
        return "fail";
    }
    if ($imagesize > 10 * 1024 * 1024) { // 10MB
        return "fail";
    }
    // إنشاء المجلد إذا لم يكن موجوداً
    if (!file_exists($dir)) {
        mkdir($dir, 0755, true);
    }
    // المسار الكامل للملف
    $fullPath = $dir . "/" . $imagename;
    // تسجيل محاولة النقل
    error_log("Trying to move file to: " . $fullPath);
    // نقل الملف
    if (move_uploaded_file($imagetmp, $fullPath)) {
        return $imagename;
    } else {
        error_log("Failed to move uploaded file");
        return "fail";
    }
}






/*
function printFailure($message = "none"){
    echo json_encode(array("status" => "failure","message"=> $message));
}

function printSuccess($message = "none"){
    echo json_encode(array("status" => "success","message"=> $message));
}
*/
function result($count)
{
    if ($count > 0) {
        printSuccess();
    } else {
        printFailure();
    }
}

function sendEmail(string $to, string $title, string $body)
{
    $header = "From: support@cleanhouse-life.com " . "\r\n" . "CC: hplanet5000@gmail.com";
    mail($to, $title, $body, $header);
    // تم حذفها لحدوث خطأ لانه
    //jsonDecode بتحول اللي علي هيئة map فقط
    //الموجودة في frontend
    // echo "Success=======================++++++++++++++++++++++" ; 
}



// ارسال الاشعار من خلال topics

//     function sendGCM($title, $message, $topic, $pageid, $pagename){
//     //هذاللينك خاص بارسال الاشعار من الفايربيز
//       //  $url = 'https://fcm.googleapis.com/fcm/send';
//         $url =   'https://fcm.googleapis.com/v1/projects/goldinvest-7da4a/messages:send';
//         $fields = array(
//             //'/topics/' يعني ارسال لكل المستخدمين او ارسال لشخص واحد واضافة usersid
//             "to" => '/topics/' . $topic,
//             'priority' => 'high',//اهمية الاشعار
//             'content_available' => true,
//             'notification' => array(
//                 "body" =>  $message,
//                 "title" =>  $title,
//                 "click_action" => "FLUTTER_NOTIFICATION_CLICK",//عشان لما نضغط علي نوتفيكاشن يفتح التطبيق
//                 "sound" => "default"//صوت النوتفيكاشن
//             ),
//             'data' => array(
//                 "pageid" => $pageid,
//                 "pagename" => $pagename
//             )
//         );
//         $fields = json_encode($fields);
//         $headers = array(
//             // اضافة key الربط مع firebase الموجودة  Cloud Messaging 
//             //هذا تم تغيره في التحديث
//             'Authorization: key=' . "AAAAcxmO584:APA91bHW__f98gDStGpfVvykXd-lpK7kXx9hiJLX0OIvfsXKqn5ZwXOSEvmYodS4yGtlz2_HoRehFNwjoiAycqIONoogByO4OKG465iEGn8axqbSkSlC4RgSg17doMqDOFiYHfSO_Tca",
//             'Content-Type: application/json'
//         );
//         $ch = curl_init();
//         curl_setopt($ch, CURLOPT_URL, $url);
//         curl_setopt($ch, CURLOPT_POST, true);
//         curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
//         curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
//         curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
//         $result = curl_exec($ch);
//         return $result;
//         curl_close($ch);
//     }

// تأكد من تحميل مكتبة Google API Client
// require_once __DIR__ . '/vendor/autoload.php';





use Google\Client as GoogleClient;

/*
function getAccessToken(): string {
    // مسار ملف JSON الخاص بService Account
    $credsPath = __DIR__ . '/service-account.json';
    // تحقق من وجود الملف قبل الاستدعاء
    if (! file_exists($credsPath)) {
        throw new \InvalidArgumentException(
            "JSON file for Service Account not found: $credsPath"
        );
    }
    //require_once 'vendor/autoload.php'; // Ensure the Google API Client library is loaded
    $client = new Google_Client();
    $client->setAuthConfig($credsPath);
    // نضيف الصلاحية الخاصة بإرسال رسائل Firebase
    $client->addScope('https://www.googleapis.com/auth/firebase.messaging');

    // نحصل على الـ access token
    $tokenArray = $client->fetchAccessTokenWithAssertion();
    if (isset($tokenArray['error'])) {
        throw new Exception("Error fetching access token: " . json_encode($tokenArray));
    }
    return $tokenArray['access_token'];
}/**
 * أرسل إشعاراً، وحفظ سجلّ الإشعار في قاعدة البيانات
 *
 * @param string $title     عنوان الإشعار
 * @param string $body      نصّ الإشعار
 * @param array  $tokens    مصفوفة Device Tokens (حالة الإرسال الفردي)
 * @param string $topic     اسم Topic في FCM (حالة البث الجماعي)
 * @return string           نصّ استجابة FCM أو رسالة خطأ
 */

/*
function sendNotification(
    string $title,
    string $body,
    array  $tokens = [],
    string $topic  = ''
): string {
    // 1) حفظ الإشعار في قاعدة البيانات
    //    – إذا وصلت توكنات فادّخر لكل مستخدم مرتبط بالتوكن
    //    – وإلّا (topic) فقم بإدخال سجلّ لكل مستخدم في الجدول
    global $con; // افترض أنّ connect.php يعرّف $con = new PDO(...);

    if (! empty($tokens)) {
        $stmtUser = $con->prepare("SELECT users_id FROM users WHERE users_fcmtoken = ?");
        $stmtIns  = $con->prepare("
            INSERT INTO notification
            (notification_title, notification_body, notification_userid)
            VALUES (?, ?, ?)
        ");
        foreach ($tokens as $t) {
            $stmtUser->execute([$t]);
            $userId = $stmtUser->fetchColumn() ?: 0;
            if ($userId) {
                $stmtIns->execute([$title, $body, $userId]);
            }
        }
    } elseif ($topic !== '') {
        // جد جميع المستخدمين
        $allUsers = $con->query("SELECT users_id  FROM users")->fetchAll(PDO::FETCH_COLUMN);
        $stmtIns  = $con->prepare("
            INSERT INTO notification
            (notification_title, notification_body, notification_userid)
            VALUES (?, ?, ?)
        ");
        foreach ($allUsers as $uid) {
            $stmtIns->execute([$title, $body, $uid]);
        }
    }

    // 2) بناء الـ Payload حسب FCM HTTP v1 API
    $message = [
        'message' => [
            // نستخدم token فردي أو topic
        ]
    ];
    if (! empty($tokens)) {
        // ملاحظة: HTTP v1 لا يدعم إرسال مصفوفة tokens دفعة واحدة
        // سنرسل للأول في المصفوفة، وإذا أردت البث المتزامن لكلّهم – استخدم Topic
        $message['message']['token'] = $tokens[0];
    } else {
        $message['message']['topic'] = $topic;
    }
    $message['message']['notification'] = [
        'title' => $title,
        'body'  => $body,
    ];

    // 3) الإرسال إلى FCM
    $accessToken = getAccessToken();
    $url = 'https://fcm.googleapis.com/v1/projects/goldinvest-7da4a/messages:send';

    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json; UTF-8',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($message),
        CURLOPT_RETURNTRANSFER => true,
    ]);

    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return "cURL Error: $err";
    }
    return $resp;
}
*/


// function sendNotification($title, $body, $token) {
//     $accessToken = getAccessToken();
//     $url = 'https://fcm.googleapis.com/v1/projects/goldinvest-7da4a/messages:send';

//     $headers = [
//         'Content-Type: application/json',
//         'Authorization: Bearer ' . $accessToken,
//     ];

//     // تعديل هيكل الـ payload ليتوافق مع FCM v1
//     $payload = json_encode([
//         'message' => [
//             'token' => $token,
//             'notification' => [
//                 'title' => $title,
//                 'body' => $body,
//             ],
//         ],
//     ]);

//     $ch = curl_init($url);
//     curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
//     curl_setopt($ch, CURLOPT_POST, true);
//     curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
//     curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

//     $response = curl_exec($ch);
//     $err = curl_error($ch);
//     curl_close($ch);

//     if ($err) {
//         throw new \Exception("cURL Error: $err");
//     }

//     return $response;
// }

// /**
//  * @param string $title     عنوان الإشعار
//  * @param string $body      نصّ الإشعار
//  * @param int    $userid    معرّف المستخدم لحفظ الإشعار
//  * @param array  $tokens    مصفوفة Device tokens (لحالة single user)
//  * @param string $topic     اسم Topic (لبث إعلاني)
//  * @param string $pageid    اختياري للتوجيه
//  * @param string $pagename  اختياري للتوجيه
//  * @return string           JSON استجابة FCM أو رسالة خطأ
//  */
// function sendGCM(
//     string $title,
//     string $body,
//     int    $userid,
//     array  $tokens   = [],
//     string $topic    = '',
//     string $pageid   = '',
//     string $pagename = ''
// ): string {
//     global $con; // PDO connection في ملف connect.php

//     // 1) حفظ سجلّ الإشعار في جدول notification
//     $stmt = $con->prepare(
//         "INSERT INTO notification
//          (notification_title, notification_body, notification_userid)
//          VALUES (?, ?, ?)"
//     );
//     $stmt->execute([$title, $body, $userid]);

//     try {
//         // 2) احصل على access token
//         $accessToken = getAccessToken();

//         // 3) جهّز عنوان الطلب
//         $projectId = 'goldinvest-7da4a';  // غيّره لـ Project ID الخاصّ بك
//         $url       = "https://fcm.googleapis.com/v1/projects/goldinvest-7da4a/messages:send";
//         $headers   = [
//             "Authorization: Bearer {$accessToken}",
//             "Content-Type: application/json; charset=utf-8",
//         ];

//         // 4) جهّز جسم الرسالة
//         $msg = [
//             'notification' => [
//                 'title' => $title,
//                 'body'  => $body,
//             ],
//             'data' => [
//                 'pageid'   => $pageid,
//                 'pagename' => $pagename,
//             ],
//         ];

//         // مستلم الإشعار: إما token أو topic
//         if (! empty($tokens)) {
//             $msg['token'] = $tokens[0];
//         } elseif ($topic !== '') {
//             $msg['topic'] = $topic;
//         } else {
//             throw new \Exception("Either tokens or topic must be provided.");
//         }

//         $payload = json_encode(['message' => $msg]);

//         // 5) نفّذ طلب cURL
//         $ch = curl_init($url);
//         curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
//         curl_setopt($ch, CURLOPT_POST, true);
//         curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
//         curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

//         $response = curl_exec($ch);
//         $err      = curl_error($ch);
//         curl_close($ch);

//         if ($err) {
//             throw new \Exception("cURL Error: $err");
//         }
//         return $response;

//     } catch (\Exception $ex) {
//         // ترجع نص الخطأ عوضاً عن التوقف
//         return "Error in sendGCM: " . $ex->getMessage();
//     }
// }


// function sendGCM($title, $message, $tokens = [], $topic = '', $pageid, $pagename): bool|string {
//     // نستخدم الـ Legacy API endpoint
//     $url = 'https://fcm.googleapis.com/v1/projects/goldinvest-7da4a/messages:send';
//     // بناء الحقل fields تبعاً لــ tokens أم topic
//     if (!empty($tokens)) {
//         // إرسال لعدة توكنز
//         $fields = [
//             'registration_ids' => $tokens,
//         ];
//     } elseif ($topic !== '') {
//         // إرسال للـ topic
//         $fields = [
//             'to' => '/topics/' . $topic,
//         ];
//     } else {
//         return false; // لا توكنز ولا Topic
//     }
//     // بقية الحقول الثابتة
//     $fields['priority'] = 'high';
//     $fields['notification'] = [
//         'title'        => $title,
//         'body'         => $message,
//         'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
//         'sound'        => 'default'
//     ];
//     $fields['data'] = [
//         'pageid'   => $pageid,
//         'pagename' => $pagename,
//     ];

//     // تشفير JSON
//     $payload = json_encode($fields);
//   // ج) الحصول على Access Token
//   $accessToken = getAccessToken();
//     // رؤوس الطلب
//     $headers = [
//         'Authorization: Bearer ' . $accessToken,
//         'Content-Type: application/json'
//     ];

//     // تنفيذ الطلب
//     $ch = curl_init();
//     curl_setopt($ch, CURLOPT_URL, $url);
//     curl_setopt($ch, CURLOPT_POST, true);
//     curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
//     curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
//     curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

//     $result = curl_exec($ch);
//     curl_close($ch);
//     return $result;
// }




// //تم عمل فينكشن واحدة لارسال الاشعار وحفظه
// function inserNotify($title,$body,$userid,$topic,$pageid,$pagename){
//     global $con;
//     $stmt = $con->prepare("INSERT INTO `notification`(`notification_title`, `notification_body`, `notification_userid`) VALUES (?,?,?)");
//     $stmt->execute(array($title , $body, $userid));
//     sendGCM($title , $body ,$topic, $pageid , $pagename);
//     $count = $stmt->rowCount();
// }




// حفظ الإشعار في قاعدة البيانات
function insertNotify($title, $body, $userid)
{
    global $con;
    $stmt = $con->prepare("
            INSERT INTO notification
            (notification_title, notification_body, notification_userid)
            VALUES (:title, :body, :userid)
        ");
    $stmt->execute([
        ':title' => $title,
        ':body' => $body,
        ':userid' => $userid
    ]);
    return $stmt->rowCount() > 0;
}


/********************************************************
 * 13. دالة حماية ملفات الإدارة (Admin Guard) - 2026
 *
 * ⭐ الغرض: منع أي شخص من الوصول لملفات الإدارة عبر الرابط مباشرة
 *    نستخدم مفتاحاً سرياً (Secret Key) يُرسل في الهيدر أو في الـ POST
 *
 * ⭐ لماذا لا نستخدم checkAuthenticate الحالية؟
 *    لأنها تعتمد على PHP_AUTH_USER الذي لا يعمل على كل استضافات Plesk
 *    (بعض إعدادات FastCGI لا تُمرِّر هيدر Authorization لـ PHP)
 *    والمفتاح السري أبسط وأضمن ويعمل في Postman بضغطة واحدة
 *
 * ⭐ طريقة الاستخدام: ضع هذا السطر في أول أي ملف إداري بعد include connect
 *    checkAdminKey();
 *
 * @return void لا تُرجع شيئاً، لكنها توقف التنفيذ فوراً عند فشل التحقق
 */
function checkAdminKey(): void
{
    // قراءة المفتاح السري المُعرَّف في ملف connect.php
    global $admin_secret_key;

    // ========== 1. قراءة المفتاح من الهيدر أولاً ==========
    // نبحث في X-Admin-Key وهو الهيدر المخصص الذي نُرسله من Postman
    $sentKey = $_SERVER['HTTP_X_ADMIN_KEY'] ?? '';

    // ========== 2. إن لم يوجد في الهيدر نبحث في بيانات الطلب ==========
    // هذا يسمح بإرساله كحقل عادي في POST لتسهيل الاختبار
    if ($sentKey === '') {
        // قراءة المفتاح من حقل admin_key في POST أو GET أو JSON
        $sentKey = filterRequest("admin_key");
    }

    // ========== 3. مقارنة المفتاح بطريقة آمنة ضد هجوم التوقيت ==========
    // hash_equals تقارن كل الأحرف دائماً بنفس الزمن
    // بينما == تتوقف عند أول حرف مختلف فيمكن تخمين المفتاح حرفاً حرفاً
    if ($sentKey !== '' && hash_equals($admin_secret_key, $sentKey)) {
        // المفتاح صحيح فنسمح بمرور التنفيذ لباقي الملف
        return;
    }

    // ========== 4. فشل التحقق: نرفض الطلب برسالة موحدة ==========
    // نستخدم 403 (ممنوع) وليس 401 لأننا لا نطلب تسجيل دخول بمتصفح
    printFailure("Forbidden: admin access required", 403);
    // إيقاف التنفيذ نهائياً حتى لا يُنفَّذ أي كود إداري بعدها
    exit;
}

/********************************************************************
 * 13ب. ترجمة مفتاح دور الإدارة إلى العربية
 *
 * ⭐ الغرض: مصدر واحد لـ admin_role_ar حتى لا يُترجم owner إلى «خدمة عملاء»
 *    عندما يكون الشرط الثنائي القديم: manager ? مدير : خدمة عملاء
 *
 * @param string|null $roleKey المفتاح النصي (owner / manager / hr / ...)
 * @return string الاسم العربي للعرض في اللوحة
 */
function adminRoleAr(?string $roleKey): string
{
    // توحيد المفتاح بحروف صغيرة بدون مسافات
    $key = strtolower(trim((string) $roleKey));
    // خريطة الأدوار المعروفة في RBAC + الموارد البشرية
    $map = [
        'owner' => 'المالك',
        'manager' => 'مدير',
        'accountant' => 'محاسب',
        'hr' => 'موارد بشرية',
        'support' => 'خدمة عملاء',
        'seller' => 'مندوب مبيعات',
    ];
    // إن وُجد المفتاح نُرجع ترجمته وإلا نُرجع المفتاح كما هو
    return $map[$key] ?? ($key !== '' ? $key : 'خدمة عملاء');
}

/********************************************************************
 * 14. دالة التحقق من توكن الإدارة (Admin Token Guard) - 2026
 *
 * ⭐ الغرض: التحقق من هوية الموظف عبر التوكن الصادر من login.php
 *    وتُرجع بياناته الكاملة (المعرّف والاسم والدور)
 *
 * ⭐ الفرق عن checkAdminKey:
 *    checkAdminKey  = مفتاح واحد ثابت للجميع ولا نعرف من نفّذ الإجراء
 *    checkAdminToken = لكل موظف توكن خاص، والسيرفر يعرف من هو ودوره
 *
 * ⭐ صيغة التوكن: "admin_id.hash"
 *    مثال: 1.3f8a9c2e7b...
 *
 * ⭐ طريقة الاستخدام في أي ملف إداري:
 *    $currentAdmin = checkAdminToken();
 *    ثم نستخدم $currentAdmin['admin_id'] بدلاً من قراءته من الطلب
 *
 * @param string|null $requiredRole إذا مُرّر 'manager' يُرفض موظفو الدعم
 * @return array بيانات الموظف: admin_id, admin_full_name, admin_role
 */
function checkAdminToken(?string $requiredRole = null): array
{
    // الوصول لاتصال قاعدة البيانات والسر المُعرَّفين في connect.php
    global $con, $admin_secret_key;

    // ========== 1. قراءة التوكن من الهيدر أولاً ==========
    // نبحث في X-Admin-Token وهو الهيدر المخصص للوحة التحكم
    $sentToken = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';

    // ========== 2. إن لم يوجد في الهيدر نبحث في بيانات الطلب ==========
    // هذا يسمح بإرساله كحقل عادي في POST لتسهيل الاختبار في Postman
    if ($sentToken === '') {
        // قراءة التوكن من حقل admin_token في POST أو GET أو JSON
        $sentToken = filterRequest("admin_token");
    }

    // ========== 3. التحقق من وجود التوكن ==========
    // إذا لم يُرسل أي توكن نرفض الطلب فوراً
    if ($sentToken === '') {
        // رسالة توضح أن تسجيل الدخول مطلوب
        printFailure("مطلوب تسجيل الدخول للوصول لهذه الصفحة", 401);
        // إيقاف التنفيذ
        exit;
    }

    // ========== 4. تفكيك التوكن لجزئيه (المعرّف والبصمة) ==========
    // نقسم النص عند أول نقطة فقط (البصمة لا تحتوي نقاطاً)
    $parts = explode('.', $sentToken, 2);

    // التوكن الصحيح يجب أن يحتوي جزئين
    if (count($parts) !== 2) {
        // رسالة توضح أن التوكن تالف
        printFailure("توكن غير صالح", 401);
        // إيقاف التنفيذ
        exit;
    }

    // معرّف الموظف من الجزء الأول
    $tokenAdminId = (int) $parts[0];
    // البصمة من الجزء الثاني
    $tokenHash = $parts[1];

    // ========== 5. جلب بيانات الموظف من قاعدة البيانات ==========
    $stmt = $con->prepare(
        "SELECT admin_id, admin_full_name, admin_phone,
                admin_password, admin_role, admin_is_active
         FROM admin_users
         WHERE admin_id = ?
         LIMIT 1"
    );
    // تنفيذ الاستعلام بتمرير معرّف الموظف من التوكن
    $stmt->execute([$tokenAdminId]);
    // جلب صف الموظف
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    // إذا لم يوجد الموظف نرفض الطلب
    if (!$admin) {
        // رسالة موحّدة لا تكشف تفاصيل
        printFailure("توكن غير صالح", 401);
        // إيقاف التنفيذ
        exit;
    }

    // ========== 6. التحقق من أن الحساب ما زال مفعّلاً ==========
    // ⭐ هذه الخطوة تجعل تعطيل الموظف يُبطل توكنه فوراً
    if ((int) $admin['admin_is_active'] !== 1) {
        // رسالة توضح أن الحساب معطّل
        printFailure("هذا الحساب معطّل، يرجى مراجعة المدير", 403);
        // إيقاف التنفيذ
        exit;
    }

    // ========== 7. إعادة حساب البصمة المتوقّعة ومقارنتها ==========
    // نبني نفس النص الذي استُخدم في login.php
    $expectedPayload = $admin['admin_id'] . '|'
        . $admin['admin_phone'] . '|'
        . $admin['admin_password'];

    // نحسب البصمة المتوقّعة بنفس السر
    $expectedHash = hash_hmac('sha256', $expectedPayload, $admin_secret_key);

    // ⭐ نستخدم hash_equals للمقارنة الآمنة ضد هجوم التوقيت
    // بينما == تتوقف عند أول حرف مختلف فيمكن تخمين البصمة حرفاً حرفاً
    if (!hash_equals($expectedHash, $tokenHash)) {
        // رسالة توضح أن التوكن غير صالح
        printFailure("توكن غير صالح، يرجى تسجيل الدخول مرة أخرى", 401);
        // إيقاف التنفيذ
        exit;
    }

    // ========== 8. التحقق من الدور المطلوب إن حُدِّد ==========
    // نتحقق فقط إذا طلب الملف دوراً معيّناً (مثل manager)
    // ⭐ المالك أعلى من المدير فيلبّي checkAdminToken('manager')
    if ($requiredRole !== null) {
        $actualRole = (string) $admin['admin_role'];
        $roleOk = ($actualRole === $requiredRole)
            || ($requiredRole === 'manager' && $actualRole === 'owner');
        if (!$roleOk) {
            // رسالة توضح أن الصلاحية غير كافية
            printFailure("ليس لديك صلاحية لتنفيذ هذا الإجراء", 403);
            // إيقاف التنفيذ
            exit;
        }
    }

    // ========== ⭐ 9. تحميل الدور والصلاحيات ==========
    //
    // ⭐ ما الجديد؟ الدالة كانت تُرجع بيانات الموظف فقط
    //   والآن تُرجع معها مستوى دوره وقائمة صلاحياته كاملة
    //   فيستطيع كل ملف أن يسأل: هل يملك هذا الموظف صلاحية كذا؟
    //
    // ⚠️ متوافقة تماماً مع الملفات القديمة
    //   لأننا نُضيف مفاتيح ولا نحذف شيئاً
    // ==================================================

    // ⭐ نتحقق أولاً هل جداول الصلاحيات مُنفَّذة على هذه القاعدة؟
    // ⚠️ لماذا؟ لتعمل الدالة على dev وprod حتى قبل تنفيذ admin_rbac.sql
    //    فلا تتعطّل اللوحة إن نُفِّذ الملف على قاعدة واحدة فقط
    $rbacReady = false;

    // نحمي الفحص بـ try/catch لأن SHOW TABLES قد تفشل على بعض الاستضافات
    try {
        // البحث عن جدول الصلاحيات
        $stmtCheck = $con->query("SHOW TABLES LIKE 'admin_permissions'");
        // إن وُجد الجدول فالنظام جاهز
        $rbacReady = ($stmtCheck && $stmtCheck->rowCount() > 0);
    } catch (Exception $e) {
        // في حالة الفشل نعتمد على المصفوفة الاحتياطية أدناه
        $rbacReady = false;
    }

    // تهيئة قائمة الصلاحيات ومستوى الدور بقيم افتراضية
    $permissions = [];
    // مستوى الدور (كلما زاد زادت السلطة)
    $roleLevel = 10;
    // مفتاح الدور النصي
    $roleKey = $admin['admin_role'];
    // اسم الدور بالعربية من الخريطة الموحدة (owner يظهر «المالك» لا «خدمة عملاء»)
    $roleNameAr = adminRoleAr($admin['admin_role']);

    // ============================================================
    // ⭐ الحالة الأولى: جداول الصلاحيات جاهزة
    // ============================================================
    if ($rbacReady) {
        // جلب بيانات الدور المرتبط بالموظف
        $stmtRole = $con->prepare(
            "SELECT r.role_id, r.role_key, r.role_name_ar, r.role_level
             FROM admin_roles r
             WHERE r.role_id = ?
             LIMIT 1"
        );
        // تنفيذ الاستعلام بمعرّف الدور المحفوظ في صف الموظف
        $stmtRole->execute([$admin['admin_role_id'] ?? 0]);
        // جلب صف الدور
        $role = $stmtRole->fetch(PDO::FETCH_ASSOC);

        // ⚠️ إن لم يُربط الموظف بدور بعد نُطابق دوره القديم بالمفتاح
        if (!$role) {
            // البحث بالمفتاح النصي القديم (manager أو support)
            $stmtRole = $con->prepare(
                "SELECT role_id, role_key, role_name_ar, role_level
                 FROM admin_roles WHERE role_key = ? LIMIT 1"
            );
            // تنفيذ الاستعلام بقيمة admin_role القديمة
            $stmtRole->execute([$admin['admin_role']]);
            // جلب الصف
            $role = $stmtRole->fetch(PDO::FETCH_ASSOC);
        }

        // إن وُجد الدور نقرأ بياناته
        if ($role) {
            // مفتاح الدور النصي
            $roleKey = $role['role_key'];
            // اسم الدور بالعربية للعرض
            $roleNameAr = $role['role_name_ar'];
            // ⭐ مستوى الدور لحماية التسلسل الوظيفي
            $roleLevel = (int) $role['role_level'];

            // ============================================================
            // ⭐⭐ جلب الصلاحيات الفعّالة: صلاحيات الدور + استثناءات الموظف
            //
            // ⭐ منطق الدمج:
            //   1. نبدأ بصلاحيات الدور
            //   2. نُضيف ما مُنح للموظف فوق دوره (up_is_granted = 1)
            //   3. نحذف ما سُحب منه رغم دوره (up_is_granted = 0)
            //
            // ⚠️ نفعلها في استعلام واحد لا ثلاثة لتقليل زمن الاستجابة
            // ============================================================
            $stmtPerms = $con->prepare(
                "SELECT p.perm_key
                 FROM admin_permissions p
                 WHERE
                   -- ⭐ صلاحيات الدور التي لم تُسحب من الموظف
                   (
                     p.perm_id IN (
                       SELECT rp_perm_id FROM admin_role_permissions
                       WHERE rp_role_id = ?
                     )
                     AND p.perm_id NOT IN (
                       SELECT up_perm_id FROM admin_user_permissions
                       WHERE up_admin_id = ? AND up_is_granted = 0
                     )
                   )
                   OR
                   -- ⭐ صلاحيات مُنحت للموظف فوق دوره
                   p.perm_id IN (
                     SELECT up_perm_id FROM admin_user_permissions
                     WHERE up_admin_id = ? AND up_is_granted = 1
                   )"
            );
            // تنفيذ الاستعلام بمعرّف الدور ومعرّف الموظف مرتين
            $stmtPerms->execute([
                $role['role_id'],
                $admin['admin_id'],
                $admin['admin_id'],
            ]);

            // ⭐ تحويل النتيجة لمصفوفة مفاتيح لسرعة الفحص بـ in_array
            $permissions = $stmtPerms->fetchAll(PDO::FETCH_COLUMN);
        }
    }

    // ============================================================
    // ⚠️ الحالة الثانية: الجداول غير مُنفَّذة بعد (مصفوفة احتياطية)
    //
    // ⭐ تضمن أن اللوحة تعمل الآن قبل تنفيذ admin_rbac.sql
    //    وتُلغى تلقائياً بمجرد تنفيذه
    // ============================================================
    if (empty($permissions)) {
        // المالك والمدير يحصلان على كل الصلاحيات الاحتياطية
        if ($admin['admin_role'] === 'owner' || $admin['admin_role'] === 'manager') {
            // قائمة كاملة تُطابق مصفوفة owner تقريباً
            $permissions = [
                'companies.view',
                'companies.create',
                'companies.edit',
                'jobs.view',
                'jobs.create',
                'jobs.edit',
                'jobs.approve',
                'providers.view',
                'providers.create',
                'providers.edit',
                'providers.approve',
                'providers.documents',
                'clients.view',
                'clients.create',
                'clients.edit',
                'bookings.view',
                'bookings.edit',
                'bookings.cancel',
                'catalog.view',
                'catalog.manage',
                'coupons.view',
                'coupons.manage',
                'staff.view',
                'staff.create',
                'staff.edit',
                'staff.permissions',
                'reports.view',
                'audit.view',
            ];
            // مستوى المدير 80
            $roleLevel = 80;
        } else {
            // خدمة العملاء تحصل على المراجعة والتعديل بلا إنشاء
            $permissions = [
                'companies.view',
                'companies.edit',
                'jobs.view',
                'jobs.edit',
                'jobs.approve',
                'providers.view',
                'providers.edit',
                'providers.approve',
                'providers.documents',
                'clients.view',
                'clients.edit',
                'bookings.view',
                'bookings.edit',
                'catalog.view',
                'coupons.view',
            ];
            // مستوى خدمة العملاء 40
            $roleLevel = 40;
        }
    }

    // ⭐ إضافة الصلاحيات ومعلومات الدور لمصفوفة الموظف المُرجَعة
    // ⚠️ نحذف هاش كلمة المرور قبل الإرجاع حتى لا يُطبع بالخطأ
    unset($admin['admin_password']);

    // قائمة مفاتيح الصلاحيات الفعّالة
    $admin['permissions'] = $permissions;
    // مفتاح الدور النصي
    $admin['role_key'] = $roleKey;
    // ⭐ اسم الدور بالعربية جاهزاً للعرض بلا منطق في فلاتر
    $admin['role_name_ar'] = $roleNameAr;
    // ⭐⭐ مستوى الدور لحماية التسلسل الوظيفي
    $admin['role_level'] = $roleLevel;

    // إرجاع بيانات الموظف الموثّق مع صلاحياته
    return $admin;
}


/////


/**
 * ============================================================
 * ⭐ 21. دالة adminCan — فحص صلاحية واحدة
 *
 * الغرض: تُجيب بنعم أو لا: هل يملك هذا الموظف هذه الصلاحية؟
 *
 * ⚠️ لا تُوقف التنفيذ ولا تطبع شيئاً — للفحص الصامت فقط
 *    (مثل: هل نُظهر زر التعديل في الرد أم لا؟)
 *
 * @param array  $admin    مصفوفة الموظف من checkAdminToken()
 * @param string $permKey  مفتاح الصلاحية مثل 'companies.create'
 * @return bool            true إن كان يملكها
 * ============================================================
 */
function adminCan(array $admin, string $permKey): bool
{
    // ⚠️ إن لم تُحمَّل قائمة الصلاحيات نرفض احتياطاً
    // ⭐ الرفض الافتراضي أأمن من السماح الافتراضي
    if (!isset($admin['permissions']) || !is_array($admin['permissions'])) {
        // لا صلاحية بلا قائمة
        return false;
    }

    // ⭐ الفحص بمقارنة صارمة داخل مصفوفة المفاتيح
    return in_array($permKey, $admin['permissions'], true);
}

/**
 * ============================================================
 * ⭐⭐ 22. دالة requirePermission — فرض صلاحية أو إيقاف الطلب
 *
 * الغرض: سطر واحد في أعلى أي ملف إداري يحميه بالكامل
 *
 * ⭐ الفرق عن adminCan: هذه تُوقف التنفيذ برد 403 واضح
 *
 * مثال الاستخدام:
 *   $currentAdmin = checkAdminToken();
 *   requirePermission($currentAdmin, 'companies.create');
 *
 * @param array  $admin   مصفوفة الموظف من checkAdminToken()
 * @param string $permKey مفتاح الصلاحية المطلوبة
 * @return void           تُوقف التنفيذ عند الرفض
 * ============================================================
 */
function requirePermission(array $admin, string $permKey): void
{
    // ⭐ إن كان يملك الصلاحية نخرج بلا أي إجراء
    if (adminCan($admin, $permKey)) {
        // السماح بالمرور
        return;
    }

    // ============================================================
    // ⚠️ الرفض: نُرجع 403 مع اسم الصلاحية الناقصة
    //
    // ⭐ لماذا نُرجع اسم الصلاحية؟
    //   لأن الموظف يقول للمدير "الشاشة ترفض" فلا يعرف المدير ماذا يمنح
    //   وذكر المفتاح يجعل حل المشكلة ثانية واحدة
    // ============================================================
    // بناء رسالة عربية توضح الدور والصلاحية الناقصة
    printFailure(
        "ليس لديك صلاحية تنفيذ هذا الإجراء — "
        . "دورك الحالي: " . ($admin['role_name_ar'] ?? 'غير محدد'),
        403,
        [
            // ⭐ مفتاح الصلاحية الناقصة (يُعرض للمدير لا للموظف)
            "required_permission" => $permKey,
            // دور الموظف الحالي
            "your_role" => $admin['role_key'] ?? null,
        ]
    );

    // إيقاف التنفيذ نهائياً
    exit;
}

/**
 * ============================================================
 * ⭐ 23. دالة logAdminActivity — تسجيل إجراء إداري
 *
 * الغرض: كتابة صف في admin_activity_log عند كل إجراء مؤثّر
 *
 * ⚠️ لا تُوقف التنفيذ عند الفشل إطلاقاً
 *    لأن فشل السجل لا يجوز أن يُفشل العملية الأصلية
 *    (لو فشل الجدول لخسرنا إنشاء شركة بسبب سجل!)
 *
 * @param array  $admin      مصفوفة الموظف من checkAdminToken()
 * @param string $action     نوع الإجراء مثل 'create_company'
 * @param string $entityType نوع الكيان مثل 'company'
 * @param int    $entityId   معرّف الكيان المتأثر
 * @param array  $details    تفاصيل إضافية تُحفظ كـ JSON
 * @return void
 * ============================================================
 */
function logAdminActivity(
    array $admin,
    string $action,
    string $entityType = '',
    int $entityId = 0,
    array $details = []
): void {
    // الوصول لاتصال قاعدة البيانات
    global $con;

    // ⚠️ نحمي العملية كلها بـ try/catch صامت
    try {
        // تجهيز استعلام الإدخال
        $stmt = $con->prepare(
            "INSERT INTO admin_activity_log
               (log_admin_id, log_admin_name, log_action,
                log_entity_type, log_entity_id, log_details, log_ip)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );

        // تنفيذ الإدخال بالبيانات
        $stmt->execute([
            // معرّف الموظف المنفّذ
            (int) $admin['admin_id'],
            // ⭐ اسمه وقت التنفيذ (نُثبّته حتى لو تغيّر لاحقاً)
            $admin['admin_full_name'] ?? null,
            // نوع الإجراء
            $action,
            // نوع الكيان (null إن لم يُحدَّد)
            $entityType !== '' ? $entityType : null,
            // معرّف الكيان (null إن كان صفراً)
            $entityId > 0 ? $entityId : null,
            // ⭐ التفاصيل بصيغة JSON مع دعم العربية بلا ترميز
            !empty($details)
            ? json_encode($details, JSON_UNESCAPED_UNICODE)
            : null,
            // ⭐ عنوان IP لمعرفة مكان التنفيذ
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Exception $e) {
        // ⚠️ نُسجّل الفشل في سجل PHP فقط ولا نُوقف العملية
        error_log("logAdminActivity failed: " . $e->getMessage());
    }
}

/**
 * ============================================================
 * ⭐ 24. دالة normalizePhoneNumber — توحيد صيغة رقم الهاتف
 *
 * ⚠️⚠️ الغرض الحقيقي: منع الحسابات المكرَّرة
 *
 * المشكلة: العمود users_phone عليه UNIQUE
 *   لكن UNIQUE لا يمنع هذه الصيغ من التعايش لنفس الشخص:
 *     01055555555   ·   +201****5555   ·   0105 555 5555
 *   فيصبح للشخص ثلاثة حسابات ولا يعرف بأيها يدخل
 *
 * الحل: نُوحّد الصيغة قبل الحفظ وقبل البحث معاً
 *
 * @param string $phone الرقم كما كتبه الموظف
 * @return string       الرقم بصيغة موحّدة تبدأ بصفر
 * ============================================================
 */
function normalizePhoneNumber(string $phone): string
{
    // ⭐ إزالة كل ما ليس رقماً (مسافات، شرطات، أقواس، +)
    $clean = preg_replace('/[^0-9]/', '', $phone);

    // ⭐ تحويل الصيغة الدولية المصرية لمحلية
    // ⚠️ 201055555555 (12 خانة تبدأ بـ 20) تصبح 01055555555
    if (strlen($clean) === 12 && strpos($clean, '20') === 0) {
        // إزالة كود الدولة وإضافة الصفر
        $clean = '0' . substr($clean, 2);
    }

    // ⭐ إضافة صفر للرقم الذي كُتب بلا صفر
    // ⚠️ 1055555555 (10 خانات تبدأ بـ 1) تصبح 01055555555
    if (strlen($clean) === 10 && strpos($clean, '1') === 0) {
        // إضافة الصفر في المقدمة
        $clean = '0' . $clean;
    }

    // إرجاع الرقم الموحّد
    return $clean;
}

/**
 * ============================================================
 * ⭐ 25. دالة generateTempPassword — كلمة مرور مؤقتة (مُصحَّحة 2026)
 *
 * الغرض: توليد كلمة مرور يُسلّمها المندوب لصاحب الحساب شفهياً
 *
 * ⭐⭐ الشروط الأربعة التي تفرضها شاشة فلاتر:
 *   1. حرف كابيتال واحد على الأقل  (A-Z)
 *   2. حرف سمول واحد على الأقل     (a-z)
 *   3. رقم واحد على الأقل           (0-9)
 *   4. رمز واحد على الأقل           (@ # ! $ % & *)
 *
 *   ⚠️ الإصدار السابق كان يُنتج kmtq4728 فترفضه الشاشة
 *      لغياب الكابيتال والرمز — وهذا الإصدار يضمن الأربعة
 *
 * ⭐ الشكل النهائي مثل: Kmtq@739  (8 خانات)
 *
 * ⭐ وشرط تصميم إضافي مهم عملياً:
 *   نستبعد الأحرف المتشابهة (l / I / 1 و O / 0) وحرف الصفر
 *   لأن المندوب يقرأ الكلمة في الهاتف فيخطئ صاحب الحساب في كتابتها
 *
 * @return string كلمة المرور المؤقتة (8 خانات)
 * ============================================================
 */
function generateTempPassword(): string
{
    // ⭐ حروف كابيتال بلا I و O لتفادي الخلط مع 1 و 0
    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    // ⭐ حروف سمول بلا l و o لنفس السبب
    $lower = 'abcdefghijkmnpqrstuvwxyz';
    // ⭐ أرقام بلا 0 و 1 لتفادي الخلط مع O و l
    $digits = '23456789';
    // ⭐⭐ رموز سهلة النطق ومتوفّرة في كل لوحات المفاتيح
    // ⚠️ نستبعد " و ' و \ و / لأنها تُسبب مشاكل في JSON وSQL وURL
    $symbols = '@#!$%&*';

    // ============================================================
    // ⭐ الخطوة 1: نضمن خانة واحدة من كل نوع أولاً
    //
    // ⚠️ لماذا نضمنها بدل الاعتماد على العشوائية؟
    //   لأن الاختيار العشوائي من مجموعة موحّدة قد يُنتج كلمة
    //   بلا رمز إطلاقاً، فترفضها الشاشة ويعود المندوب للمكتب
    // ============================================================
    // مصفوفة تجمع خانات كلمة المرور
    $chars = [];

    // حرف كابيتال مضمون
    $chars[] = $upper[random_int(0, strlen($upper) - 1)];
    // حرف سمول مضمون
    $chars[] = $lower[random_int(0, strlen($lower) - 1)];
    // رقم مضمون
    $chars[] = $digits[random_int(0, strlen($digits) - 1)];
    // ⭐ رمز مضمون
    $chars[] = $symbols[random_int(0, strlen($symbols) - 1)];

    // ============================================================
    // الخطوة 2: إكمال الطول لـ 8 خانات من كل الأنواع
    // ============================================================
    // ⭐ دمج كل المجموعات في مجموعة واحدة للخانات المتبقية
    $all = $upper . $lower . $digits . $symbols;

    // إضافة أربع خانات عشوائية (المجموع 8)
    for ($i = 0; $i < 4; $i++) {
        // ⭐ random_int عشوائية آمنة تشفيرياً بخلاف rand
        $chars[] = $all[random_int(0, strlen($all) - 1)];
    }

    // ============================================================
    // ⭐⭐ الخطوة 3: خلط الترتيب
    //
    // ⚠️ إلزامي جداً: بلا خلط تكون كل كلمة على النمط
    //    كابيتال ← سمول ← رقم ← رمز ← عشوائي
    //    فيصبح النمط معروفاً ويُقلّص مساحة التخمين كثيراً
    // ============================================================
    // خلط ترتيب الخانات عشوائياً
    shuffle($chars);

    // ⭐ تحويل المصفوفة لنص نهائي وإرجاعه
    return implode('', $chars);
}

/**
 * ============================================================
 * ⭐⭐ 26. دالة adminCreateUserAccount — إنشاء حساب مستخدم نيابةً
 *
 * الغرض: الدالة المشتركة لإنشاء صف users من لوحة الإدارة
 *
 * ⭐⭐ لماذا دالة مشتركة لا كود مكرّر؟
 *   لأننا سنُنشئ حسابات لثلاثة أنواع:
 *     صاحب شركة (client) · مقدم خدمة (provider) · عميل (client)
 *   والثلاثة يحتاجون نفس الخطوات بالضبط:
 *     توحيد الرقم ← فحص التكرار ← كلمة مرور مؤقتة ← INSERT ← توكن
 *   فلو كتبناها ثلاث مرات لصار تصحيح أي باگ ثلاث مرات
 *
 * ⚠️⚠️ لا تُدير هذه الدالة المعاملة (transaction) إطلاقاً
 *   بل يُديرها الملف المستدعي، لأن الملف يكتب في جدول ثانٍ بعدها
 *   فلا بد أن يكون الالتزام (commit) واحداً للجدولين معاً
 *
 * @param array $params مصفوفة تحتوي:
 *   full_name (إجباري) · phone (إجباري) · user_type (إجباري)
 *   country · region · password · approve · admin_id
 * @return array نتيجة العملية: status و message و data
 * ============================================================
 */
function adminCreateUserAccount(array $params): array
{
    // الوصول لاتصال قاعدة البيانات
    global $con;

    // ============================================================
    // الخطوة 1: قراءة المعاملات وتنظيفها
    // ============================================================
    // الاسم الكامل للمستخدم
    $fullName = trim($params['full_name'] ?? '');
    // ⭐ رقم الهاتف بعد توحيد صيغته
    $phone = normalizePhoneNumber($params['phone'] ?? '');
    // نوع الحساب: client أو provider
    $userType = $params['user_type'] ?? 'client';
    // الدولة (نص فارغ افتراضاً لأن العمود NOT NULL بـ default '')
    $country = trim($params['country'] ?? '');
    // المنطقة
    $region = trim($params['region'] ?? '');
    // معرّف الموظف المنشئ
    $adminId = (int) ($params['admin_id'] ?? 0);

    // ============================================================
    // الخطوة 2: التحقق من الحقول الإجبارية
    // ============================================================
    // الاسم لا يقل عن حرفين
    if (mb_strlen($fullName) < 2) {
        // إرجاع فشل مع رسالة واضحة
        return [
            "status" => "failure",
            "message" => "اسم المستخدم يجب أن يكون حرفين على الأقل",
        ];
    }

    // ⚠️ الاسم لا يزيد على 100 حرف لأن العمود varchar(100)
    if (mb_strlen($fullName) > 100) {
        // إرجاع فشل مع الحد الأقصى
        return [
            "status" => "failure",
            "message" => "اسم المستخدم يجب ألا يتجاوز 100 حرف",
        ];
    }

    // ⭐ التحقق من صيغة الهاتف بعد التوحيد
    if (!preg_match('/^[0-9]{10,15}$/', $phone)) {
        // إرجاع فشل يوضح الصيغة المطلوبة
        return [
            "status" => "failure",
            "message" => "رقم الهاتف غير صحيح — يجب أن يكون من 10 إلى 15 رقماً",
        ];
    }

    // ⚠️ نوع الحساب محدود بقيمتَي ENUM في قاعدة البيانات
    if (!in_array($userType, ['client', 'provider'], true)) {
        // إرجاع فشل يوضح القيم المسموحة
        return [
            "status" => "failure",
            "message" => "نوع الحساب يجب أن يكون client أو provider",
        ];
    }

    // ============================================================
    // ⭐ الخطوة 3: فحص تكرار رقم الهاتف
    //
    // ⚠️ نفحصه بأنفسنا ولا ننتظر خطأ UNIQUE من MySQL
    //    لأن خطأ MySQL نص إنجليزي غامض، ونحن نُرجع رسالة عربية
    //    ومعها بيانات الحساب الموجود ليقرر المندوب ما يفعل
    // ============================================================
    // تجهيز استعلام البحث عن الرقم
    $stmtDup = $con->prepare(
        "SELECT users_id, users_full_name, users_type, users_is_active
         FROM users WHERE users_phone = ? LIMIT 1"
    );
    // تنفيذ الاستعلام بالرقم الموحّد
    $stmtDup->execute([$phone]);
    // جلب الصف إن وُجد
    $existing = $stmtDup->fetch(PDO::FETCH_ASSOC);

    // إذا وُجد حساب بنفس الرقم نُرجع بياناته ولا نُنشئ حساباً جديداً
    if ($existing) {
        // إرجاع تعارض مع بيانات الحساب الموجود
        return [
            "status" => "exists",
            "message" => "رقم الهاتف مسجّل بالفعل باسم: "
                . $existing['users_full_name'],
            "data" => [
                // ⭐ معرّف الحساب الموجود (الملف المستدعي قد يربط به)
                "users_id" => (int) $existing['users_id'],
                // اسم صاحب الحساب
                "users_full_name" => $existing['users_full_name'],
                // نوع الحساب
                "users_type" => $existing['users_type'],
                // هل الحساب مفعّل؟
                "users_is_active" => (int) $existing['users_is_active'],
            ],
        ];
    }

    // ============================================================
    // ⭐ الخطوة 4: توليد كلمة المرور المؤقتة أو استخدام المُرسلة
    // ============================================================
    // قراءة كلمة المرور إن أرسلها الموظف بنفسه
    $plainPassword = trim($params['password'] ?? '');

    // ⭐ إن لم يُرسلها نُولّدها تلقائياً
    if ($plainPassword === '') {
        // توليد كلمة مرور مؤقتة قابلة للنطق
        $plainPassword = generateTempPassword();
    }

    // ⚠️ التحقق من طول كلمة المرور المُرسلة يدوياً
    if (strlen($plainPassword) < 6) {
        // إرجاع فشل يوضح الحد الأدنى
        return [
            "status" => "failure",
            "message" => "كلمة المرور يجب أن تكون 6 أحرف على الأقل",
        ];
    }

    // ⭐ تشفير كلمة المرور بنفس دالة signup.php و add_staff.php
    $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);

    // ============================================================
    // ⭐⭐ الخطوة 5: تحديد users_approve
    //
    // ⚠️ هذا القرار حاسم: login.php:85 يمنع الدخول إن كانت 0
    //
    // ⭐ الحالة المُفعَّلة: 1 (يستطيع الدخول فوراً)
    //   لأن المندوب قابل صاحب الشركة شخصياً وتحقّق من رقمه
    //   فلا معنى لطلب OTP من شخص المندوب معه الآن
    // ============================================================
    // القيمة الافتراضية 1 مع إمكانية تمرير 0
    $approve = isset($params['approve']) ? (int) $params['approve'] : 1;

    // ⚠️ الخيار البديل (غير مُفعَّل): إجبار التحقق بـ OTP قبل أول دخول
    // ولتفعيله ضع // قبل السطر أعلاه وأزل التعليق عن السطر التالي
    // $approve = 0;
    //
    // ⭐ الفرق العملي:
    //   1 = يدخل فوراً بكلمة المرور المؤقتة (أسرع، يعتمد على أمانة المندوب)
    //   0 = يجب أن يطلب OTP ويتحقق أولاً (أأمن، لكن خطوة إضافية)

    // ============================================================
    // الخطوة 6: إدخال المستخدم في جدول users
    // ============================================================
    // تجهيز مصفوفة البيانات بأسماء الأعمدة الحقيقية
    $insertData = [
        // الاسم الكامل
        "users_full_name" => $fullName,
        // ⭐ رقم الهاتف بعد التوحيد
        "users_phone" => $phone,
        // ⚠️ الهاش لا كلمة المرور نفسها
        "users_password" => $hashedPassword,
        // الدولة
        "users_country" => $country,
        // المنطقة
        "users_region" => $region,
        // الحساب مفعّل من لحظة إنشائه
        "users_is_active" => 1,
        // ⭐ قرار التحقق أعلاه
        "users_approve" => $approve,
        // نوع الحساب
        "users_type" => $userType,
        // ⭐ معرّف الموظف المنشئ (للمساءلة)
        "users_created_by_admin" => $adminId > 0 ? $adminId : null,
        // ⭐ علامة أن كلمة المرور مؤقتة ويجب تغييرها
        "users_password_is_temp" => 1,
    ];

    // ⚠️ نمرر false لمنع الدالة من طباعة JSON لأننا نبني رداً مخصصاً
    $result = insertData("users", $insertData, false);

    // إذا فشل الإدخال نُرجع فشلاً واضحاً
    if ($result <= 0) {
        // إرجاع فشل عام
        return [
            "status" => "failure",
            "message" => "فشل إنشاء حساب المستخدم",
        ];
    }

    // ⭐ قراءة معرّف المستخدم الجديد
    $newUserId = (int) $con->lastInsertId();

    // ============================================================
    // ⭐ الخطوة 7: إنشاء توكن للمستخدم
    //
    // ⭐ لماذا نُنشئه الآن ولم يدخل التطبيق بعد؟
    //   لأن signup.php يُنشئه، ولو لم نُنشئه هنا لاختلف الحسابان
    //   وأي ملف يفترض وجود صف في users_tokens سيفشل مع هذا الحساب
    // ============================================================
    // توليد توكن عشوائي قوي بنفس طريقة signup.php
    $tokenValue = bin2hex(random_bytes(32));

    // إدخال التوكن في جدول users_tokens
    insertData("users_tokens", [
        // ربط التوكن بالمستخدم الجديد
        "tokens_users_id" => $newUserId,
        // قيمة التوكن
        "tokens_value" => $tokenValue,
    ], false);

    // ============================================================
    // الخطوة 8: إرجاع النتيجة للملف المستدعي
    // ============================================================
    return [
        // حالة النجاح
        "status" => "success",
        // رسالة عربية
        "message" => "تم إنشاء حساب المستخدم بنجاح",
        // بيانات الحساب الجديد
        "data" => [
            // ⭐ معرّف المستخدم الذي يحتاجه الملف المستدعي
            "users_id" => $newUserId,
            // الاسم الكامل
            "users_full_name" => $fullName,
            // ⭐ الرقم بعد التوحيد (قد يختلف عمّا كتبه الموظف)
            "users_phone" => $phone,
            // نوع الحساب
            "users_type" => $userType,
            // حالة التحقق
            "users_approve" => $approve,
            // ⭐⭐ كلمة المرور المؤقتة — تُعرض مرة واحدة فقط
            // ⚠️ لا تُحفظ في أي مكان ولا تُرجَع في أي طلب آخر
            "temp_password" => $plainPassword,
            // توكن الجلسة
            "token" => $tokenValue,
        ],
    ];
}

// ========== 9. إرجاع بيانات الموظف للملف المستدعي ==========
// ⚠️ نحذف هاش كلمة المرور قبل الإرجاع حتى لا يُطبع بالخطأ
//     unset($admin['admin_password']);

//     // إرجاع بيانات الموظف الموثّق
//     return $admin;
// }


//     // دالة ترسل إشعاراً لمستخدم واحد
//     require_once "functions.php";

// function notifySingleUser($userid, $token, $title, $body) {
//     // أولاً، نحفظ الإشعار في الجدول
//     insertNotify($title, $body, $userid);

//     // ثمّ نرسله عبر FCM
//     $result = sendGCM($title, $body, [$token], '', '0', ''); 
//     return $result;
// }

//     //مثال على دالة ترسل إشعاراً للجميع (Topic)
//     require_once "functions.php";

//     function notifyAllUsers($title, $body) {
//         // افتراضياً، لا نحفظ إشعاراً لمستخدم بعينه هنا
//         $result = sendGCM($title, $body, [], 'allUsers', '0', '');
//         return $result;
//     }