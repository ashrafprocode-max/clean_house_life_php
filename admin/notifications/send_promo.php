<?php
// ============================================================
// ملف: admin/notifications/send_promo.php
// الغرض: إرسال حملة ترويجية لمستخدمين محددين أو جمهور كامل
// الصلاحية: notifications.send
// ============================================================

// ربط الاتصال بقاعدة البيانات والدوال العامة
include "../../connect.php";
// ربط مساعد الإشعارات (notifyEvent + notificationJsonBody)
include "../../includes/notification_helper.php";

// قراءة جسم JSON قبل filterRequest لأن php://input قد يُستهلك مرة واحدة
$jsonBody = notificationJsonBody();
// نسخ الحقول النصية إلى POST حتى يجدها filterRequest و checkAdminToken
foreach (['admin_token', 'title', 'body', 'audience'] as $jsonKey) {
    // نملأ POST فقط إن وُجدت القيمة في JSON ولم تُرسل في النموذج
    if (!isset($_POST[$jsonKey]) && isset($jsonBody[$jsonKey]) && !is_array($jsonBody[$jsonKey])) {
        // وضع القيمة كنص في POST
        $_POST[$jsonKey] = (string) $jsonBody[$jsonKey];
    }
}

// التحقق من توكن الموظف وجلب بياناته
$currentAdmin = checkAdminToken();
// فرض صلاحية إرسال الحملات
requirePermission($currentAdmin, 'notifications.send');

// معرّف الموظف المرسل من التوكن
$adminId = (int) $currentAdmin['admin_id'];
// عنوان الحملة من النموذج أو من JSON المخزَّن
$title = trim((string) filterRequest("title"));
// إن فرّغ filterRequest الجسم نأخذ العنوان من JSON
if ($title === '' && isset($jsonBody['title'])) {
    // تنظيف عنوان JSON
    $title = trim(strip_tags((string) $jsonBody['title']));
}
// نص الحملة من النموذج أو من JSON المخزَّن
$body = trim((string) filterRequest("body"));
// إن فرّغ filterRequest الجسم نأخذ النص من JSON
if ($body === '' && isset($jsonBody['body'])) {
    // تنظيف نص JSON
    $body = trim(strip_tags((string) $jsonBody['body']));
}
// جمهور الإرسال: all / client / provider / company / selected
$audience = trim((string) filterRequest("audience"));
// إن فرّغ filterRequest الجسم نأخذ الجمهور من JSON
if ($audience === '' && isset($jsonBody['audience'])) {
    // تنظيف جمهور JSON
    $audience = trim(strip_tags((string) $jsonBody['audience']));
}

// قراءة معرّفات المستلمين من POST أو GET أولاً
$userIdsRaw = $_POST['user_ids'] ?? ($_GET['user_ids'] ?? null);
// إن لم توجد في النموذج نقرأ JSON بعد filterRequest
if ($userIdsRaw === null) {
    // استخراج user_ids من الجسم المخزَّن إن وُجد المفتاح
    if (array_key_exists('user_ids', $jsonBody)) {
        // القيمة قد تكون مصفوفة أو نصاً مفصولاً بفواصل
        $userIdsRaw = $jsonBody['user_ids'];
    }
}

// قائمة الجماهير المسموحة
$allowed = ['all', 'client', 'provider', 'company', 'selected'];
// إن كان الجمهور غير معروف نفترض مستلمين محددين
if (!in_array($audience, $allowed, true)) {
    // القيمة الافتراضية
    $audience = 'selected';
}

// رفض الحملة بلا عنوان أو نص
if ($title === '' || $body === '') {
    // رسالة توضح الحقول المطلوبة
    printFailure("العنوان والنص مطلوبان");
}

// تهيئة قائمة المعرّفات
$userIds = [];
// إن وصلت كمصفوفة نستخدمها كما هي
if (is_array($userIdsRaw)) {
    // نسخ المصفوفة
    $userIds = $userIdsRaw;
} elseif (is_string($userIdsRaw) && $userIdsRaw !== '') {
    // محاولة فك JSON النصي
    $decoded = json_decode($userIdsRaw, true);
    // إن نجح الفك كمصفوفة
    if (is_array($decoded)) {
        // استخدام المصفوفة المفكوكة
        $userIds = $decoded;
    } else {
        // تقسيم النص على الفواصل
        $userIds = preg_split('/\s*,\s*/', $userIdsRaw);
    }
}

// إن لم يكن الجمهور محدداً يدوياً نجلب كل المستخدمين المطابقين
if ($audience !== 'selected') {
    // الشرط الأساسي: حسابات مفعّلة
    $where = ["u.users_is_active = 1"];
    // فلترة العملاء بدون شركة
    if ($audience === 'client') {
        // نوع العميل
        $where[] = "u.users_type = 'client'";
        // استبعاد أصحاب الشركات
        $where[] = "NOT EXISTS (SELECT 1 FROM company_registering c WHERE c.company_user_id = u.users_id)";
    } elseif ($audience === 'provider') {
        // مقدمو الخدمة
        $where[] = "u.users_type = 'provider'";
    } elseif ($audience === 'company') {
        // من لديه صف شركة
        $where[] = "EXISTS (SELECT 1 FROM company_registering c WHERE c.company_user_id = u.users_id)";
    }
    // دمج الشروط
    $whereSql = implode(' AND ', $where);
    // جلب المعرّفات
    $idsStmt = $con->query("SELECT u.users_id FROM users u WHERE $whereSql");
    // قراءة العمود أو مصفوفة فارغة
    $userIds = $idsStmt ? $idsStmt->fetchAll(PDO::FETCH_COLUMN) : [];
}

// تحويل المعرّفات لأرقام صحيحة فريدة
$userIds = array_values(array_unique(array_map('intval', $userIds)));
// استبعاد الأصفار والقيم السالبة
$userIds = array_filter($userIds, static fn($id) => $id > 0);

// رفض الحملة بلا مستلمين
if (empty($userIds)) {
    // رسالة توضح عدم وجود مستلمين
    printFailure("لا يوجد مستلمون لهذه الحملة");
}

// إنشاء الإشعار وتوزيعه على صناديق المستخدمين
$notificationId = notifyEvent([
    'type' => 'promo',
    'title' => $title,
    'body' => $body,
    'audience' => 'user',
    'route_name' => 'notificationsScreen',
    'created_by_admin' => $adminId,
    'user_ids' => $userIds,
    'notify_admins' => false,
    'extra' => [
        'promo_audience' => $audience,
        'recipients' => count($userIds),
    ],
]);

// فشل الإدخال في قاعدة البيانات
if ($notificationId <= 0) {
    // رسالة فشل الإنشاء
    printFailure("فشل إنشاء الحملة");
}

// إرسال JSON بنتيجة الحملة
echo json_encode([
    "status" => "success",
    "message" => "تم إرسال الإشعار الترويجي",
    "data" => [
        "notification_id" => $notificationId,
        "recipients" => count($userIds),
        "audience" => $audience,
    ],
]);
