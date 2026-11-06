<?php
 /**
 * إعدادات الاتصال بقاعدة البيانات - نسخة مطورة 2026
 * تدعم الـ Flavors (Development & Production)
 * مخصصة لتطبيق Clean House Life
 */

 

 // ================================
 //****** 1. تفعيل وضع اكتشاف الأخطاء (Debugging)
 // ================================
 //3 اسطر لمعرفة سبب الخطاء في consoldebug في حالة وجوده
 // الفائدة: بدونها، إذا حدث خطأ في الكود، ستظهر لك صفحة بيضاء فارغة ولن تعرف السبب. هي ضرورية جداً أثناء مرحلة التطوير.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ================================
// *. معالج الأخطاء العالمي للتطبيق
// أي Exception غير معالج سيتم التقاطه هنا
// ================================
set_exception_handler(function ($e) {

    // إرجاع HTTP Status Code
    http_response_code(500);

    // إرجاع رسالة JSON موحدة
    echo json_encode([
        "message" => "Internal Server Error",
        "code" => 500,
        "data" => [
            "exception" => $e->getMessage()
        ]
    ]);

    exit;
});

/*
//DEFINER=`root`@`localhost` يجب ازالة هذه العبارة من ملف Export المربوط بقاعدة البيانات]
 $dsn = "mysql:host=localhost;dbname=Ashraf_2025_";
 $user = "Ashraf_2025";
 //ملحوظة هامة جدا يجب وضع الباص بين ' وليس "
 $pass = 'AGAi0c4thjr&t8$j';
*/

// ****** 2. منطق الـ Flavors (التنقل الآلي بين السيرفر والـ Local)
// الوظيفة: هنا نسأل السيرفر: "هل أنا أعمل الآن على جهاز الكمبيوتر الشخصي؟".
//الفائدة: إذا كانت الإجابة "نعم"، سيستخدم الكود بيانات قاعدة الـ dev تلقائياً. وإذا رفعت الملف للسيرفر (Plesk)
//، سيتعرف على ذلك ويستخدم بيانات الـ prod دون أن تتدخل أنت لتعديل الكود يدوياً كل مرة.
// 1. تحديد البيئة الحالية بناءً على اسم السيرفر أو الـ Request
// تحديد البيئة الحالية لدعم الـ Flavors (Dev & Prod)
// يمكنك تغيير 'localhost' حسب بيئة العمل لديك
$serverName = $_SERVER['SERVER_NAME'] ?? '';
$serverAddr = $_SERVER['SERVER_ADDR'] ?? '';
$hostHeader = $_SERVER['HTTP_HOST'] ?? '';

// بيئة التطوير: localhost أو 127.0.0.1 أو IP المحلي (مثل 192.168.100.5)
// أو نطاق dev على Plesk (مثل dev.yourdomain.com أو great-faraday-xxx.plesk.page/clean_house_life_dev)
//$devHosts = ['localhost', '127.0.0.1', '192.168.100.5', '::1'];
// بعد التعديل (أضف الـ IP الجديد للماك):
$devHosts = ['localhost', '127.0.0.1', '192.168.100.5', '192.168.100.200', '::1'];
$isDevByHost = in_array($serverName, $devHosts) || in_array($serverAddr, $devHosts);
$isDevBySubdomain = (strpos($hostHeader, 'dev.') === 0 || strpos($hostHeader, 'clean_house_life_dev') !== false);
$isDevelopment = $isDevByHost || $isDevBySubdomain;

if ($isDevByHost) {
    // إعدادات التطوير المحلي (XAMPP) - قاعدة بيانات Dev
    $dbName = "clean_house_life_dev";
    $dbUser = "root";
    $dbPass = "";
    $host   = "localhost";
} elseif ($isDevBySubdomain) {
    // إعدادات بيئة التطوير على Plesk - قاعدة بيانات Dev
    // أنشئ قاعدة clean_house_life_dev في Plesk ونفس المستخدم يمكنه الوصول لها
    $dbName = "clean_house_life_dev";
    $dbUser = "clean_admin";      // أو مستخدم dev إن كان مختلفاً
    // $dbPass = "0g7Zhd0^5";
    $dbPass = "7bRj8o3$3";
    $host   = "localhost";
} else {
    // إعدادات بيئة الإنتاج - قاعدة بيانات Production
    $dbName = "clean_house_life_prod";
    $dbUser = "clean_admin";
    // $dbPass = "0g7Zhd0^5";
    $dbPass = "7bRj8o3$3";
    $host   = "localhost";
}
// 2. صياغة الـ DSN (Data Source Name) مع دعم الترميز الحديث الموحد utf8mb4 لدعم الـ Emojis 
//الـ DSN: هو عنوان الوصول الكامل للسيرفر.
//utf8mb4: هذا أهم جزء لعام 2026؛ فهو يضمن أن قاعدة البيانات ستقبل اللغة العربية بطلاقة، وأيضاً الـ Emojis التي قد يستخدمها المستخدمون في المحادثات أو التعليقات.
$dsn = "mysql:host=$host;dbname=$dbName;charset=utf8mb4";
 //سنعرف متغيرات الحماية
$auth_user = "ashraf"; 
$auth_pass = "clean123";

// ⭐ المفتاح السري لحماية ملفات الإدارة (add_coupon / edit_booking)
// ⚠️ غيّر هذه القيمة لقيمة عشوائية طويلة قبل نشر التطبيق على المتاجر
// ولا ترفع هذا الملف على GitHub أبداً وهو يحمل المفتاح الحقيقي
$admin_secret_key = "CHL_admin_2026_Xk9mP2vQ7wR4tY8n";

// 3. خيارات الـ PDO لضمان أفضل أداء وأمان
// هذه المصفوفة هي التي تجعل الاتصال "احترافياً"
$options = array(
    // تفعيل وضع الأخطاء لرمي Exceptions عند حدوث مشكلة
    PDO::ATTR_ERRMODE             => PDO::ERRMODE_EXCEPTION,
    // جعل جلب البيانات يكون على شكل مصفوفة ترابطية افتراضياً
    PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_ASSOC,
    // تعطيل محاكاة الاستعلامات لزيادة الأمان ضد SQL Injection
    PDO::ATTR_EMULATE_PREPARES    => false,
    // التأكد من ضبط الترميز فور الاتصال لضمان دعم اللغة العربية والرموز
    PDO::MYSQL_ATTR_INIT_COMMAND  => "SET NAMES utf8mb4"
);
$countrowinpage = 9;
// الـ Try: يحاول تنفيذ الاتصال.
try {
    // 4. إنشاء كائن الاتصال PDO
    $con = new PDO($dsn, $dbUser, $dbPass, $options);
    // ضبط وضع الأخطاء لرمي Exceptions
    $con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 5. الهيدرز (Headers) للسماح بالوصول من تطبيق Flutter (CORS Policy) لعام 2026
    // نستخدم النجمة (*) للسماح لجميع المصادر، أو يمكنك تحديد رابط الـ Web الخاص بك
    //الوظيفة: تطبيق فلاتر (سواء موبايل أو ويب) يعتبر "مصدر خارجي" بالنسبة للسيرفر. بدون هذه الأسطر، سيقوم المتصفح أو نظام الأندرويد بحظر الطلب 
   // (Request) ولن تصل البيانات للتطبيق. نحن هنا نعطي إذن الدخول الرسمي للتطبيق.
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Admin-Token, X-Admin-Key, Access-Control-Allow-Origin, Access-Control-Allow-Headers");
    header("Access-Control-Allow-Methods: POST, OPTIONS, GET");
    // ================================
    // *. تحديد أن الاستجابة JSON
    // ================================
    header("Content-Type: application/json");

        // ============================================================
    // ⭐ *. معالجة طلب OPTIONS الاستكشافي (CORS Preflight) — 2026
    //
    // ما هي المشكلة التي تحلها هذه الكتلة؟
    //   المتصفح قبل إرسال أي طلب POST يحتوي هيدر Authorization
    //   يُرسل طلباً استكشافياً بالطريقة OPTIONS يسأل السيرفر: هل تسمح لي؟
    //   وطلب OPTIONS لا يحتوي body إطلاقاً
    //   فيصل للملف ولا يجد الحقول الإجبارية فيرد بـ 400
    //   والمتصفح يرى 400 فيعتبر السماح مرفوضاً ويُلغي طلب POST نهائياً
    //   فيظهر في Flutter Web خطأ DioExceptionType.connectionError
    //
    // ما الحل؟
    //   نرد على OPTIONS بـ 204 (نجاح بلا محتوى) ونوقف التنفيذ فوراً
    //   قبل أن يصل الطلب لأي كود تحقق من الحقول
    //
    // ⚠️ يجب أن تكون هذه الكتلة بعد أسطر header الخاصة بـ CORS
    //    وقبل include functions.php وقبل أي منطق في أي ملف
    //
    // ⭐ هذا الحل يُصلح كل ملفات المشروع دفعة واحدة
    //    لأن كل ملف يُضمّن connect.php في أول سطر
    // ============================================================
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        // السماح بالهيدرات المخصصة التي تستخدمها لوحة التحكم
        // ⚠️ X-Admin-Token و X-Admin-Key غير موجودين في سطر الهيدر أعلاه
        //    ولو أرسلتهما لوحة التحكم في الهيدر سيفشل الـ preflight مرة أخرى
        header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Admin-Token, X-Admin-Key, Access-Control-Allow-Origin, Access-Control-Allow-Headers");

        // السماح بكل الطرق التي يستخدمها المشروع
        header("Access-Control-Allow-Methods: POST, GET, OPTIONS");

        // ⭐ نطلب من المتصفح تذكّر نتيجة هذا الفحص 24 ساعة
        // فلا يُرسل OPTIONS قبل كل طلب مما يُسرّع اللوحة للضعف تقريباً
        header("Access-Control-Max-Age: 86400");

        // نرد بـ 204 = نجاح بلا محتوى (الرد الصحيح لطلب preflight)
        http_response_code(204);

        // ⭐ إيقاف التنفيذ فوراً حتى لا يصل الطلب لأي كود تحقق
        exit;
    }

    // 6. تضمين ملف الوظائف العامة
    include "functions.php";

    // 7. تفعيل الحماية والتحقق من الهوية (Authentication Check) إلا إذا تم تعريف متغير noAuth
   // وضعنا شرط الـ noAuth لكي نتمكن مستقبلاً من استثناء صفحات معينة
   //  (مثل صفحة تسجيل الدخول نفسها) من طلب "التوكن" أو الحماية، لأن المستخدم في هذه المرحلة لا يملك حساباً أصلاً.
    if (!isset($noAuth)) {
        // فعلها بعد كتابة دالة الحماية في ملف الوظائف
        // الحماية header مش مفعلين //
        // checkAuthenticate(); 
    }
    //الـ Catch: إذا فشل الاتصال (بسبب كلمة سر خطأ أو سيرفر متوقف)
   // ، يقوم بإمساك الخطأ فوراً ويمنع التطبيق من الانهيار (Crash)، بل يظهر رسالة "Service Unavailable".
} catch (PDOException $e) {
    // 8. التعامل مع أخطاء الاتصال بشكل احترافي
    // في بيئة التطوير نظهر الخطأ كاملاً، في الإنتاج نظهر رسالة عامة
    // if ($isDevelopment) {
    //     echo "Database Connection Failed: " . $e->getMessage();
    // } else {
    //     echo "Service Unavailable. Please try again later.";
    // }
    // exit;
        printFailure(
        "Database connection failed",
        500,
        [
            "error" => $e->getMessage()
        ]
    );
}

/*
$dsn = "mysql:host=localhost;dbname=goldinvest";
$user = "root";
$pass = "";
$option = array(
   PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES UTF8"
);
$countrowinpage = 9;
try {
   $con = new PDO($dsn, $user, $pass, $option);
   $con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
   // السماحhttp request للوصول للباك اند بدون اي مشاكل
   // حتي لايستطيع اي شخص الدخول Api واخذالمعلومات
   header("Access-Control-Allow-Origin: *");
   header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With, Access-Control-Allow-Origin");
   header("Access-Control-Allow-Methods: POST, OPTIONS , GET");
   include "functions.php";
   if (!isset($notAuth)) {
      //مش مفعلين header الحماية
      // checkAuthenticate();
   }
} catch (PDOException $e) {
   echo $e->getMessage();
}
*/