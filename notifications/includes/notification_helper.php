<?php
// ============================================================
// ملف: includes/notification_helper.php
// الغرض: إنشاء الإشعار + توزيعه على الصناديق + محاولة FCM
//
// ⭐ يُستدعى من أحداث النظام ومن send_promo.php
// ⚠️ لا يطبع JSON — الملف المستدعي يطبع الرد
// ============================================================

function notificationJsonBody(): array
{
    // تخزين الجسم بعد أول قراءة لأن php://input يُستهلك مرة واحدة
    static $cached = null;
    // إن سبق فكّه نعيد النسخة المخزَّنة
    if ($cached !== null) {
        return $cached;
    }
    // قراءة الجسم الخام وفكّه كمصفوفة
    $decoded = json_decode((string) file_get_contents('php://input'), true);
    // حفظ المصفوفة أو مصفوفة فارغة عند الفشل
    $cached = is_array($decoded) ? $decoded : [];
    // إرجاع الجسم المخزَّن
    return $cached;
}

if (!defined('FCM_PROJECT_ID')) {
    // ⭐ معرّف مشروع Firebase — غيّره لمشروعك
    define('FCM_PROJECT_ID', 'YOUR_FIREBASE_PROJECT_ID');
}

if (!defined('FCM_SERVICE_ACCOUNT_PATH')) {
    // ⭐ مسار JSON الحساب الخدمي على السيرفر
    define('FCM_SERVICE_ACCOUNT_PATH', __DIR__ . '/../service-account.json');
}

// ============================================================
// إنشاء إشعار نظام وبثّه للمستلمين
// ============================================================
function notifyEvent(array $params): int
{
    // الاتصال العام بقاعدة البيانات
    global $con;

    // نوع الحدث (user_registered / booking_created / ...)
    $type = trim((string) ($params['type'] ?? ''));
    // عنوان الإشعار
    $title = trim((string) ($params['title'] ?? ''));
    // نص الإشعار
    $body = trim((string) ($params['body'] ?? ''));
    // جمهور الإشعار: admin / user / mixed
    $audience = $params['audience'] ?? 'admin';
    // معرّف من قام بالفعل (عميل أو مزود)
    $actorUserId = (int) ($params['actor_user_id'] ?? 0);
    // اسم من قام بالفعل للعرض
    $actorName = trim((string) ($params['actor_name'] ?? ''));
    // دور الفاعل: client / provider / company
    $actorRole = trim((string) ($params['actor_role'] ?? ''));
    // الجدول المرتبط (users / bookings / companies / ...)
    $refTable = trim((string) ($params['ref_table'] ?? ''));
    // معرّف الصف المرتبط
    $refId = (int) ($params['ref_id'] ?? 0);
    // اسم مسار Flutter عند الضغط
    $routeName = trim((string) ($params['route_name'] ?? ''));
    // بيانات إضافية تُحفظ JSON
    $extra = $params['extra'] ?? [];
    // موظف مرسل الحملة (0 للأحداث التلقائية)
    $createdByAdmin = (int) ($params['created_by_admin'] ?? 0);
    // مستلمون مستخدمون محددون (حملة أو إشعار فردي)
    $userIds = $params['user_ids'] ?? [];
    // هل نُرسل لكل موظفي الإدارة ذوي صلاحية العرض؟
    $notifyAdmins = !empty($params['notify_admins']);

    // لا نُنشئ صفاً بلا نوع أو عنوان
    if ($type === '' || $title === '' || $body === '') {
        return 0;
    }

    // تحويل البيانات الإضافية لنص JSON
    $extraJson = json_encode($extra, JSON_UNESCAPED_UNICODE);

    // إدخال صف المحتوى
    $stmt = $con->prepare(
        "INSERT INTO notifications (
            notification_type, notification_title, notification_body,
            notification_audience, notification_actor_user_id,
            notification_actor_name, notification_actor_role,
            notification_ref_table, notification_ref_id,
            notification_route_name, notification_extra_json,
            notification_created_by_admin
         ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    $stmt->execute([
        $type,
        mb_substr($title, 0, 180),
        mb_substr($body, 0, 500),
        in_array($audience, ['admin', 'user', 'mixed'], true) ? $audience : 'admin',
        $actorUserId > 0 ? $actorUserId : null,
        $actorName !== '' ? mb_substr($actorName, 0, 100) : null,
        $actorRole !== '' ? mb_substr($actorRole, 0, 20) : null,
        $refTable !== '' ? $refTable : null,
        $refId > 0 ? $refId : null,
        $routeName !== '' ? $routeName : null,
        $extraJson,
        $createdByAdmin > 0 ? $createdByAdmin : null,
    ]);

    // معرّف الإشعار الجديد
    $notificationId = (int) $con->lastInsertId();
    if ($notificationId <= 0) {
        return 0;
    }

    // توزيع على موظفي الإدارة إن طُلب
    if ($notifyAdmins) {
        notifyAdminsInbox($notificationId);
    }

    // توزيع على مستخدمين محددين إن وُجدوا
    if (is_array($userIds) && !empty($userIds)) {
        notifyUsersInbox($notificationId, $userIds);
    }

    // محاولة الدفع عبر FCM بلا إيقاف التدفق إن فشلت
    try {
        pushNotificationByFcm($notificationId);
    } catch (Throwable $e) {
        // نتجاهل فشل FCM لأن الصندوق موجود ويعمل الـ stream
    }

    return $notificationId;
}

// ============================================================
// إدخال صفوف inbox لكل موظف يملك notifications.view
// ============================================================
function notifyAdminsInbox(int $notificationId): void
{
    global $con;

    $sql = "SELECT DISTINCT a.admin_id
            FROM admin_users a
            LEFT JOIN admin_roles r
                ON r.role_id = a.admin_role_id
                OR (a.admin_role_id IS NULL AND r.role_key = a.admin_role)
            INNER JOIN admin_role_permissions rp
                ON rp.rp_role_id = r.role_id
            INNER JOIN admin_permissions p
                ON p.perm_id = rp.rp_perm_id
               AND p.perm_key = 'notifications.view'
            WHERE a.admin_is_active = 1";
    // الدور من admin_role_id أولاً ثم admin_role النصي للتوافق
    // الصلاحية عبر rp_role_id / rp_perm_id وليس أعمدة مفاتيح نصية
    $ids = $con->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    if (empty($ids)) {
        return;
    }

    $ins = $con->prepare(
        "INSERT INTO notification_inbox
            (inbox_notification_id, inbox_recipient_kind, inbox_admin_id)
         VALUES (?, 'admin', ?)"
    );
    foreach ($ids as $adminId) {
        $ins->execute([$notificationId, (int) $adminId]);
    }
}

// ============================================================
// إدخال صفوف inbox لمستخدمين
// ============================================================
function notifyUsersInbox(int $notificationId, array $userIds): void
{
    global $con;

    $ins = $con->prepare(
        "INSERT INTO notification_inbox
            (inbox_notification_id, inbox_recipient_kind, inbox_user_id)
         VALUES (?, 'user', ?)"
    );
    $seen = [];
    foreach ($userIds as $rawId) {
        $uid = (int) $rawId;
        if ($uid <= 0 || isset($seen[$uid])) {
            continue;
        }
        $seen[$uid] = true;
        $ins->execute([$notificationId, $uid]);
    }
}

// ============================================================
// قراءة صف الإشعار مع بيانات الصندوق
// ============================================================
function mapNotificationRow(array $row): array
{
    // بيانات التنقّل الإضافية من JSON المخزَّن مع الإشعار
    $extra = [];
    if (!empty($row['notification_extra_json'])) {
        $decoded = json_decode($row['notification_extra_json'], true);
        if (is_array($decoded)) {
            $extra = $decoded;
        }
    }

    // شكل موحّد يقرأه Flutter دون أسماء أعمدة SQL
    return [
        // معرّف صف الصندوق لصاحب الجهاز الحالي
        "inbox_id" => isset($row['inbox_id']) ? (int) $row['inbox_id'] : 0,
        // معرّف محتوى الإشعار المشترك بين المستلمين
        "notification_id" => (int) $row['notification_id'],
        // نوع الحدث لتمييز الأيقونة والتنقّل
        "type" => $row['notification_type'],
        // العنوان الظاهر في القائمة والجرس
        "title" => $row['notification_title'],
        // النص الظاهر تحت العنوان
        "body" => $row['notification_body'],
        // جمهور الإشعار: إدارة أو مستخدم أو مختلط
        "audience" => $row['notification_audience'],
        // من قام بالفعل إن وُجد
        "actor_user_id" => (int) ($row['notification_actor_user_id'] ?? 0),
        // اسم الفاعل للعرض دون استعلام إضافي
        "actor_name" => $row['notification_actor_name'],
        // الدور الإنجليزي المخزَّن
        "actor_role" => $row['notification_actor_role'],
        // ترجمة الدور للعربية في الصندوق
        "actor_role_ar" => actorRoleAr($row['notification_actor_role'] ?? ''),
        // الجدول المرتبط للتنقّل
        "ref_table" => $row['notification_ref_table'],
        // معرّف الصف المرتبط؛ صفر إن كان NULL
        "ref_id" => (int) ($row['notification_ref_id'] ?? 0),
        // مسار Flutter عند الضغط
        "route_name" => $row['notification_route_name'],
        // بيانات إضافية بعد فك JSON
        "extra" => $extra,
        // هل قرأ المستلم هذا الصف
        "is_read" => (int) ($row['inbox_is_read'] ?? 0),
        // وقت الإنشاء من المحتوى ثم من الصندوق
        "created_at" => $row['notification_created_at'] ?? ($row['inbox_created_at'] ?? null),
    ];
}

// ============================================================
// هل وُجد إشعار بنفس النوع والمرجع خلال عدد ساعات؟
// notifyEvent يخزّن ref_id=0 كـ NULL لذلك نطابق IS NULL لا = 0
// يُستدعى من hooks التذكير والتأخير وملخص المتأخرين لمنع التكرار
// ============================================================
function notificationExistsRecently(string $type, string $refTable, int $refId, int $hours): bool
{
    // اتصال PDO العام
    global $con;

    // ساعة واحدة حد أدنى حتى لا يُمرَّر صفر في INTERVAL
    if ($hours < 1) {
        $hours = 1;
    }

    // ملخص المتأخرين وغيره يُمرَّر بـ ref_id=0 فيُخزَّن NULL
    if ($refId <= 0) {
        // مطابقة NULL لا = 0 لأن العمود لا يساوي صفراً بعد الإدخال
        $stmt = $con->prepare(
            "SELECT 1 FROM notifications
             WHERE notification_type = ?
               AND notification_ref_table = ?
               AND notification_ref_id IS NULL
               AND notification_created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
             LIMIT 1"
        );
        // تنفيذ بالنوع والجدول ونافذة الساعات
        $stmt->execute([$type, $refTable, $hours]);
        // وجود أي صف يعني التكرار ممنوع
        return (bool) $stmt->fetchColumn();
    }

    // مرجع رقمي حقيقي: قسط أو حجز
    $stmt = $con->prepare(
        "SELECT 1 FROM notifications
         WHERE notification_type = ?
           AND notification_ref_table = ?
           AND notification_ref_id = ?
           AND notification_created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
         LIMIT 1"
    );
    // تنفيذ بالنوع والجدول والمعرّف والساعات
    $stmt->execute([$type, $refTable, $refId, $hours]);
    // تحويل النتيجة لمنطقي
    return (bool) $stmt->fetchColumn();
}

function checkUserToken(): array
{
    // الاتصال العام بقاعدة البيانات
    global $con;

    // قراءة التوكن من الحقل الذي يرسله التطبيق أولاً
    $token = trim((string) filterRequest("token"));
    // توافق مع الاسم القديم user_token
    if ($token === '') {
        $token = trim((string) filterRequest("user_token"));
    }
    // هيدر X-User-Token لأن Plesk غالباً يحذف Authorization
    if ($token === '') {
        $token = trim((string) ($_SERVER['HTTP_X_USER_TOKEN'] ?? ''));
    }
    // قراءة هيدر التفويض من Apache أو إعادة التوجيه
    if ($token === '') {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';
        if ($header === '' && function_exists('getallheaders')) {
            $all = getallheaders();
            $header = $all['Authorization'] ?? ($all['authorization'] ?? '');
        }
        if (preg_match('/Bearer\s+(\S+)/i', $header, $m)) {
            $token = $m[1];
        }
    }
    // رفض الطلب بلا توكن صالح
    if ($token === '' || strcasecmp($token, 'Bearer') === 0) {
        printFailure("مطلوب تسجيل الدخول", 401);
    }

    // ربط التوكن بالمستخدم النشط دون طباعة قيمته
    $stmt = $con->prepare(
        "SELECT u.users_id, u.users_full_name, u.users_phone,
                u.users_type, u.users_is_active
         FROM users_tokens t
         INNER JOIN users u ON u.users_id = t.tokens_users_id
         WHERE t.tokens_value = ?
         LIMIT 1"
    );
    // تنفيذ الاستعلام بقيمة التوكن
    $stmt->execute([$token]);
    // جلب صف المستخدم
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    // رفض التوكن غير الموجود
    if (!$user) {
        // رسالة 401
        printFailure("توكن غير صالح", 401);
    }
    // رفض الحساب المعطّل
    if ((int) $user['users_is_active'] !== 1) {
        // رسالة 403
        printFailure("هذا الحساب معطّل", 403);
    }
    // إرجاع بيانات المستخدم للمُستدعي
    return $user;
}

function actorRoleAr(?string $role): string
{
    // ترجمة دور الفاعل للعربية للعرض في الصندوق
    return match ($role) {
        'client' => 'عميل',
        'provider' => 'مقدم خدمة',
        'company' => 'صاحب شركة',
        default => $role ?? '',
    };
}

// ============================================================
// FCM HTTP v1 — يُتخطى بهدوء إن لم يُضبط المشروع
// ============================================================
function pushNotificationByFcm(int $notificationId): void
{
    // اتصال PDO العام
    global $con;

    // تخطّي الإرسال إن لم يُضبط معرّف المشروع بعد
    if (FCM_PROJECT_ID === 'YOUR_FIREBASE_PROJECT_ID') {
        return;
    }
    // تخطّي الإرسال إن ملف الحساب الخدمي غير موجود
    if (!is_file(FCM_SERVICE_ACCOUNT_PATH)) {
        return;
    }

    // صفوف الصندوق مع المحتوى لإرسال نسخة لكل مستلم
    $stmt = $con->prepare(
        "SELECT n.*, i.inbox_recipient_kind, i.inbox_admin_id, i.inbox_user_id, i.inbox_id
         FROM notification_inbox i
         INNER JOIN notifications n ON n.notification_id = i.inbox_notification_id
         WHERE i.inbox_notification_id = ?"
    );
    // تنفيذ بمعرّف الإشعار
    $stmt->execute([$notificationId]);
    // جلب كل المستلمين
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // لا أجهزة تُخاطَب إن لم يُوزَّع الصندوق
    if (empty($rows)) {
        return;
    }

    // توكن OAuth قصير العمر لـ FCM v1
    $accessToken = fcmAccessToken();
    // فشل التوقيع يعني لا إرسال دون كسر الصندوق
    if ($accessToken === '') {
        return;
    }

    // لكل مستلم في الصندوق
    foreach ($rows as $row) {
        // أجهزة هذا الموظف أو المستخدم
        $tokens = fcmTokensForRecipient(
            $row['inbox_recipient_kind'],
            (int) ($row['inbox_admin_id'] ?? 0),
            (int) ($row['inbox_user_id'] ?? 0)
        );
        // إرسال نسخة لكل جهاز نشط
        foreach ($tokens as $token) {
            fcmSendOne($accessToken, $token, $row);
        }
    }
}

function fcmTokensForRecipient(string $kind, int $adminId, int $userId): array
{
    // اتصال PDO العام
    global $con;
    // موظف إدارة: نستبعد ويندوز لأن لوحة الويب لا تستقبل FCM
    if ($kind === 'admin' && $adminId > 0) {
        $stmt = $con->prepare(
            "SELECT device_fcm_token FROM notification_devices
             WHERE device_owner_kind = 'admin' AND device_admin_id = ?
               AND device_is_active = 1 AND device_platform <> 'windows'"
        );
        // تنفيذ بمعرّف الموظف
        $stmt->execute([$adminId]);
        // عمود التوكن فقط أو قائمة فارغة
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }
    // مستخدم تطبيق: كل الأجهزة النشطة
    if ($kind === 'user' && $userId > 0) {
        $stmt = $con->prepare(
            "SELECT device_fcm_token FROM notification_devices
             WHERE device_owner_kind = 'user' AND device_user_id = ?
               AND device_is_active = 1"
        );
        // تنفيذ بمعرّف المستخدم
        $stmt->execute([$userId]);
        // عمود التوكن فقط أو قائمة فارغة
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }
    // نوع غير معروف أو معرّف صفر
    return [];
}

function fcmSendOne(string $accessToken, string $token, array $row): void
{
    // نفس شكل الصندوق حتى يفتح التطبيق المسار الصحيح
    $mapped = mapNotificationRow($row);
    // نقطة FCM HTTP v1 لهذا المشروع
    $url = 'https://fcm.googleapis.com/v1/projects/' . FCM_PROJECT_ID . '/messages:send';
    // جسم الرسالة: عرض + بيانات تنقّل كنصوص لأن FCM يفرض strings
    $payload = [
        'message' => [
            // توكن الجهاز المستهدف
            'token' => $token,
            // العنوان والنص في شريط النظام
            'notification' => [
                'title' => $mapped['title'],
                'body' => $mapped['body'],
            ],
            // حقول يقرأها التطبيق عند الضغط دون طباعة التوكن
            'data' => [
                'notification_id' => (string) $mapped['notification_id'],
                'inbox_id' => (string) $mapped['inbox_id'],
                'type' => (string) $mapped['type'],
                'route_name' => (string) ($mapped['route_name'] ?? ''),
                'ref_id' => (string) $mapped['ref_id'],
                'ref_table' => (string) ($mapped['ref_table'] ?? ''),
            ],
            // أولوية عالية لأندرويد حتى يظهر فوراً
            'android' => ['priority' => 'high'],
        ],
    ];

    // طلب POST قصير المهلة حتى لا يعلّق حدث النظام
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json; charset=utf-8',
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
    ]);
    // تجاهل جسم الرد؛ الصندوق هو المصدر المعتمد
    curl_exec($ch);
    // إغلاق المقبض
    curl_close($ch);
}

function fcmAccessToken(): string
{
    // قراءة JSON الحساب الخدمي دون طباعة المفتاح
    $json = json_decode((string) file_get_contents(FCM_SERVICE_ACCOUNT_PATH), true);
    // رفض الملف الناقص حتى لا يُرسل JWT فاسد
    if (!is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
        return '';
    }

    // وقت الإصدار لانتهاء التوكن خلال ساعة
    $now = time();
    // ترويسة JWT بخوارزمية RSA
    $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])), '+/', '-_'), '=');
    // ادّعاءات Google OAuth لنطاق FCM فقط
    $claims = rtrim(strtr(base64_encode(json_encode([
        'iss' => $json['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    ])), '+/', '-_'), '=');
    // الجزء غير الموقَّع
    $unsigned = $header . '.' . $claims;
    // توقيع بالمفتاح الخاص للحساب الخدمي
    openssl_sign($unsigned, $signature, $json['private_key'], 'SHA256');
    // JWT جاهز للاستبدال بتوكن وصول
    $jwt = $unsigned . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

    // استبدال JWT بتوكن وصول من Google
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
    ]);
    // فك الرد دون طباعة التوكن
    $resp = json_decode((string) curl_exec($ch), true);
    // إغلاق المقبض
    curl_close($ch);
    // سلسلة فارغة إن فشل التبادل
    return (string) ($resp['access_token'] ?? '');
}
