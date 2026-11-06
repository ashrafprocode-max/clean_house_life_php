<?php
// ============================================================
// ملف: client_profiles/view_client_profile.php
// الغرض: جلب البيانات الشخصية والأساسية للعميل لعرضها في التطبيق
// الطلب: GET أو POST يحتوي على clients_users_id
// ============================================================

// تضمين ملف الاتصال بقاعدة البيانات والدوال الأساسية للمشروع
include "../connect.php";

// استقبال معرف المستخدم المراد جلب بيانات ملفه الشخصي
$users_id = filterRequest("clients_users_id");

// التحقق من إرسال المعرف لضمان تنفيذ استعلام صحيح ومحدد
if (!empty($users_id)) {

    // كتابة استعلام دمج (INNER JOIN) لجلب البيانات الأساسية من جدول users والملف الشخصي الاختياري معاً
    $sql = "
        SELECT 
            u.users_id,
            u.users_full_name,
            u.users_phone,
            u.users_is_active,
            cp.clients_id,
            cp.clients_profile_image,
            cp.clients_email,
            cp.clients_detailed_address,
            cp.clients_birth_date,
            cp.clients_nationality
        FROM users u
        LEFT JOIN client_profiles cp 
            ON u.users_id = cp.clients_users_id
        WHERE u.users_id = ?
    ";

    // تحضير وتنفيذ استعلام قاعدة البيانات بشكل آمن
    $stmt = $con->prepare($sql);
    $stmt->execute(array($users_id));
    $clientData = $stmt->fetch(PDO::FETCH_ASSOC);

    // التحقق مما إذا كان المستخدم موجوداً بالفعل في النظام
    if ($clientData) {
        
        // بناء الـ Base URL الخاص بالسيرفر ديناميكياً لإنشاء روابط صور كاملة وصالحة للتطبيق
        // الرجوع للمجلد الرئيسي للمشروع تجنباً لتكرار client_profiles/
        $scriptDir = dirname(dirname($_SERVER['SCRIPT_NAME']));
        $baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
            . '://' . $_SERVER['HTTP_HOST']
            . ($scriptDir === '/' || $scriptDir === '\\' ? '' : $scriptDir);
        $baseUrl = rtrim($baseUrl, '/');

        // $baseUrl = rtrim(
        //     (isset($_SERVER['HTTPS']) ? 'https' : 'http') .
        //     '://' . $_SERVER['HTTP_HOST'] .
        //     str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME']),
        //     '/'
        // );

        // تجهيز رابط الصورة الشخصية الكامل؛ إذا لم توجد صورة نرجع قيمة null
        $profileImageUrl = null;
        if (!empty($clientData['clients_profile_image'])) {
            // دمج رابط السيرفر مع مسار مجلد صور العملاء واسم الصورة المخزن
            $profileImageUrl = $baseUrl . '/upload/client_profiles/' . $clientData['clients_profile_image'];
            // $profileImageUrl = $baseUrl . '/../upload/clients/' . $clientData['clients_profile_image'];
        }

        // إعادة صياغة المصفوفة النهائية بشكل منظم ومناسب ومريح لـ UI فلاتر
        $result = array(
            "users_id"                 => (int)$clientData['users_id'],
            "users_full_name"          => $clientData['users_full_name'],
            "users_phone"              => $clientData['users_phone'],
            "users_is_active"          => (int)$clientData['users_is_active'],
            "profile_details"          => array(
                "clients_id"               => $clientData['clients_id'] ? (int)$clientData['clients_id'] : null,
                "profile_image_url"        => $profileImageUrl, // رابط جاهز ومباشر للاستخدام في الـ Image.network
                "clients_email"            => $clientData['clients_email'],
                "clients_detailed_address" => $clientData['clients_detailed_address'],
                "clients_birth_date"       => $clientData['clients_birth_date'],
                "clients_nationality"      => $clientData['clients_nationality']
            )
        );

        // طباعة النتيجة بنجاح وتحويلها لصيغة JSON ليفهمها تطبيق الـ Flutter
        echo json_encode(array("status" => "success", "data" => $result));

    } else {
        // إرجاع رسالة تفيد بعدم العثور على هذا المستخدم في النظام
        
        echo json_encode(array("status" => "failure", "message" => "User not found"));
    }

} else {
    // إرجاع رسالة خطأ في حال عدم إرسال المعرف من التطبيق
     printFailure("Missing user ID");
}
?>