<?php
// ============================================================
// ملف: notifications/stream.php
// الغرض: بث SSE لإشعارات العميل/المزود وهو داخل التطبيق
// ============================================================

// ربط الاتصال بقاعدة البيانات والدوال العامة
include "../connect.php";
// ربط مساعد الإشعارات والتحقق من توكن المستخدم
include "../includes/notification_helper.php";

// التحقق من توكن المستخدم وجلب بياناته
$currentUser = checkUserToken();
// معرّف المستخدم الحالي
$userId = (int)$currentUser['users_id'];
// آخر inbox_id وصله التطبيق
$afterId = (int)filterRequest("after_id");

// رأس نوع المحتوى لحدث السيرفر
header('Content-Type: text/event-stream; charset=utf-8');
// منع التخزين المؤقت
header('Cache-Control: no-cache');
// إبقاء الاتصال مفتوحاً
header('Connection: keep-alive');
// منع nginx من تجميع المخرجات
header('X-Accel-Buffering: no');

// تعطيل ضغط Apache إن وُجدت الدالة
if (function_exists('apache_setenv')) {
    // إيقاف gzip حتى تظهر الأحداث فوراً
    @apache_setenv('no-gzip', '1');
}
// إيقاف تجميع المخرجات في PHP
@ini_set('output_buffering', 'off');
// إيقاف ضغط zlib
@ini_set('zlib.output_compression', 0);
// تفريغ كل طبقات المخزن المؤقت
while (ob_get_level() > 0) {
    // إفراغ الطبقة الحالية
    ob_end_flush();
}
// تفعيل الإرسال الفوري
ob_implicit_flush(true);

// وقت بدء الحلقة
$started = time();
// أقصى مدة للاتصال بالثواني
$maxSeconds = 25;

// حلقة البث حتى انتهاء المهلة أو قطع العميل
while ((time() - $started) < $maxSeconds) {
    // الخروج إن أغلق العميل الاتصال
    if (connection_aborted()) {
        // إيقاف الحلقة
        break;
    }

    // جلب الإشعارات الجديدة منذ after_id
    $stmt = $con->prepare(
        "SELECT i.inbox_id, i.inbox_is_read, i.inbox_created_at, n.*
         FROM notification_inbox i
         INNER JOIN notifications n
            ON n.notification_id = i.inbox_notification_id
         WHERE i.inbox_recipient_kind = 'user'
           AND i.inbox_user_id = ?
           AND i.inbox_id > ?
         ORDER BY i.inbox_id ASC
         LIMIT 20"
    );
    // تنفيذ الاستعلام بمعرّف المستخدم وآخر معرّف
    $stmt->execute([$userId, $afterId]);
    // جلب الصفوف
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // إن وُجدت إشعارات نرسلها كأحداث
    if (!empty($rows)) {
        // المرور على كل صف
        foreach ($rows as $row) {
            // تحويل الصف لشكل الرد الموحّد
            $mapped = mapNotificationRow($row);
            // تحديث مؤشر آخر معرّف
            $afterId = (int)$mapped['inbox_id'];
            // اسم الحدث
            echo "event: notification\n";
            // بيانات JSON للحدث
            echo "data: " . json_encode($mapped, JSON_UNESCAPED_UNICODE) . "\n\n";
        }
    } else {
        // نبضة إبقاء الاتصال حياً
        echo "event: ping\n";
        // جسم النبضة
        echo "data: {\"ok\":true}\n\n";
    }

    // إرسال المخرجات فوراً
    @flush();
    // الانتظار ثلاث ثوانٍ قبل الدورة التالية
    sleep(3);
}

// إغلاق البث بسبب المهلة
echo "event: close\n";
// سبب الإغلاق
echo "data: {\"reason\":\"timeout\"}\n\n";
// إرسال آخر سطر
@flush();
