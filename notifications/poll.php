<?php
// ============================================================
// ملف: notifications/poll.php
// الغرض: جلب الإشعارات الجديدة للمستخدم بعد آخر معرّف
// يُستدعى كل 15 ثانية من NotificationsCubit.pollNew
//
// الطريقة: POST
// الحقول: after_id (اختياري) · limit (اختياري)
// التوكن: Authorization Bearer من جدول users_tokens
// ============================================================

// بدء مخزن مؤقت حتى لا يسبق أي تحذير HTML رد JSON
ob_start();
// ربط ملف الاتصال بقاعدة البيانات
include "../connect.php";
// منع طباعة تحذيرات PHP بهيئة HTML
ini_set('html_errors', '0');
// تفريغ أي مخرجات عرضية من ملفات التضمين
if (ob_get_length()) {
    ob_clean();
}

// دالة رد فارغ ناجح حتى لا يكسر الاستطلاع واجهة التطبيق
function pollEmptySuccess($lastId = 0)
{
    echo json_encode([
        "status" => "success",
        "data" => [],
        "unread_count" => 0,
        "last_id" => (int) $lastId,
        "pagination" => [
            "has_next_page" => false,
            "current_page" => 1,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    // ============================================================
    // الخطوة 1: قراءة توكن المستخدم
    // Plesk/FastCGI غالباً لا يمرّر هيدر Authorization إلى PHP
    // لذلك نقرأ من الجسم أولاً ثم من هيدر مخصص ثم من Authorization
    // ============================================================
    $token = trim(filterRequest('token'));
    if ($token === '') {
        $token = trim((string) ($_SERVER['HTTP_X_USER_TOKEN'] ?? ''));
    }
    if ($token === '') {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';
        if ($authHeader === '' && function_exists('getallheaders')) {
            $allHeaders = getallheaders();
            $authHeader = $allHeaders['Authorization']
                ?? ($allHeaders['authorization'] ?? '');
        }
        if ($authHeader === '' && function_exists('apache_request_headers')) {
            $reqHeaders = apache_request_headers();
            $authHeader = $reqHeaders['Authorization']
                ?? ($reqHeaders['authorization'] ?? '');
        }
        if (stripos($authHeader, 'Bearer ') === 0) {
            $token = trim(substr($authHeader, 7));
        }
    }
    if ($token === '' || strcasecmp($token, 'Bearer') === 0) {
        printFailure("مطلوب تسجيل الدخول", 401);
        exit;
    }

    // ============================================================
    // الخطوة 2: التحقق من التوكن وجلب معرّف المستخدم
    // ============================================================
    $stmtUser = $con->prepare(
        "SELECT u.users_id
         FROM users_tokens t
         INNER JOIN users u ON u.users_id = t.tokens_users_id
         WHERE t.tokens_value = ?
           AND u.users_is_active = 1
         LIMIT 1"
    );
    $stmtUser->execute([$token]);
    $user = $stmtUser->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        printFailure("توكن غير صالح", 401);
        exit;
    }
    $userId = (int) $user['users_id'];

    // ============================================================
    // الخطوة 3: استقبال after_id و limit
    // ============================================================
    $afterId = (int) filterRequest("after_id");
    if ($afterId < 0) {
        $afterId = 0;
    }
    $limit = (int) filterRequest("limit");
    if ($limit < 1) {
        $limit = 20;
    }
    if ($limit > 50) {
        $limit = 50;
    }

    // ============================================================
    // الخطوة 4: جلب الإشعارات الأحدث من آخر معرّف
    // LIMIT يُكتب رقماً مباشرة لأن PDO native لا يقبل LIMIT ?
    // ============================================================
    $sql = "SELECT
                i.inbox_id,
                n.notification_id,
                n.notification_type AS type,
                n.notification_title AS title,
                n.notification_body AS body,
                n.notification_actor_name AS actor_name,
                n.notification_actor_role AS actor_role,
                n.notification_ref_table AS ref_table,
                n.notification_ref_id AS ref_id,
                n.notification_route_name AS route_name,
                i.inbox_is_read AS is_read,
                i.inbox_created_at AS created_at
             FROM notification_inbox i
             INNER JOIN notifications n ON n.notification_id = i.inbox_notification_id
             WHERE i.inbox_user_id = ?
               AND i.inbox_recipient_kind = 'user'
               AND i.inbox_id > ?
             ORDER BY i.inbox_id ASC
             LIMIT $limit";
    $stmt = $con->prepare($sql);
    $stmt->execute([$userId, $afterId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ============================================================
    // الخطوة 5: عدد غير المقروء وآخر معرّف
    // ============================================================
    $stmtUnread = $con->prepare(
        "SELECT COUNT(*) FROM notification_inbox
         WHERE inbox_user_id = ?
           AND inbox_recipient_kind = 'user'
           AND inbox_is_read = 0"
    );
    $stmtUnread->execute([$userId]);
    $unreadCount = (int) $stmtUnread->fetchColumn();

    $roleAr = [
        'owner' => 'المالك',
        'manager' => 'مدير',
        'support' => 'خدمة العملاء',
        'client' => 'عميل',
        'provider' => 'مقدم خدمة',
    ];

    $items = [];
    $lastId = $afterId;
    foreach ($rows as $row) {
        $inboxId = (int) $row['inbox_id'];
        if ($inboxId > $lastId) {
            $lastId = $inboxId;
        }
        $roleKey = (string) ($row['actor_role'] ?? '');
        $items[] = [
            "inbox_id" => $inboxId,
            "notification_id" => (int) $row['notification_id'],
            "type" => (string) $row['type'],
            "title" => (string) $row['title'],
            "body" => (string) $row['body'],
            "actor_name" => $row['actor_name'],
            "actor_role_ar" => $roleAr[$roleKey] ?? $roleKey,
            "ref_table" => $row['ref_table'],
            "ref_id" => (int) ($row['ref_id'] ?? 0),
            "route_name" => $row['route_name'],
            "is_read" => (int) $row['is_read'],
            "created_at" => $row['created_at'],
        ];
    }

    echo json_encode([
        "status" => "success",
        "data" => $items,
        "unread_count" => $unreadCount,
        "last_id" => $lastId,
        "pagination" => [
            "has_next_page" => false,
            "current_page" => 1,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    // جدول الإشعارات غير منشأ أو أي عطل SQL: نرجع قائمة فارغة لا HTML
    pollEmptySuccess(0);
}