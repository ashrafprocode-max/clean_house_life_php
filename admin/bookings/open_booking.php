<?php
// ============================================================
// ملف: admin/bookings/open_booking.php
// الغرض: فتح حجز للمراجعة من اللوحة
//
// ⭐ السيناريو:
//   الضغط على البطاقة أو رقم الهاتف في القائمة
//   إذا كانت الحالة pending نُحوّلها إلى negotiating
//   ونسجّل الموظف المراجع ووقت المراجعة
//   ثم نُرجع رابط واتساب للعميل ومقدم الخدمة
//
// ⚠️ الحالات الأخرى لا تُغيَّر — نُرجع البيانات فقط
//
// الحقول المطلوبة: booking_id
// الطريقة: POST
// الصلاحية المطلوبة: bookings.edit
// ============================================================

// ربط ملف الاتصال بقاعدة البيانات (مستويان للوراء لأن الملف داخل admin/bookings)
include "../../connect.php";

// ⭐ حماية ملف الإدارة: يجب إرسال المفتاح السري في هيدر X-Admin-Key
// ⚠️ لو أردت تعطيل الحماية مؤقتاً أثناء التطوير ضع // قبل السطر التالي فقط
// checkAdminKey();

// ============================================================
// الخطوة 1: التحقق من التوكن والصلاحية
//
// ⭐ bookings.edit يملكها: owner / manager / support
// ============================================================
$currentAdmin = checkAdminToken();

requirePermission($currentAdmin, 'bookings.edit');

// ⭐ الفتح لـ owner / manager / support فقط
$allowedRoles = ['owner', 'manager', 'support'];
$roleKey = $currentAdmin['role_key'] ?? ($currentAdmin['admin_role'] ?? '');
if (!in_array($roleKey, $allowedRoles, true)) {
    printFailure("ليس لديك صلاحية تنفيذ هذا الإجراء", 403);
    exit;
}

$adminId = (int) $currentAdmin['admin_id'];

// ============================================================
// الخطوة 2: استقبال معرّف الحجز
// ============================================================
$booking_id = filterRequest("booking_id");

if (empty($booking_id)) {
    printFailure("booking_id is required");
    exit;
}

$booking_id = (int) $booking_id;

// ============================================================
// الخطوة 3: جلب الحجز مع هواتف العميل والمزود
// ============================================================
$stmtCheck = $con->prepare(
    "SELECT
        b.booking_id,
        b.booking_status,
        b.booking_reviewed_by,
        cu.users_full_name AS client_full_name,
        cu.users_phone     AS client_phone,
        pu.users_id        AS provider_users_id,
        pu.users_full_name AS provider_full_name,
        pu.users_phone     AS provider_phone
     FROM bookings b
     INNER JOIN users cu
        ON cu.users_id = b.booking_users_id
     INNER JOIN provider_services ps
        ON ps.ps_id = b.booking_ps_id
     INNER JOIN users pu
        ON pu.users_id = ps.ps_user_id
     WHERE b.booking_id = ?
     LIMIT 1"
);
$stmtCheck->execute([$booking_id]);
$current = $stmtCheck->fetch(PDO::FETCH_ASSOC);

if (!$current) {
    printFailure("Booking not found", 404);
    exit;
}

$oldStatus = $current['booking_status'];
$newStatus = $oldStatus;
$statusChanged = false;

// ============================================================
// الخطوة 4: pending → negotiating فقط
// ============================================================
if ($oldStatus === 'pending') {
    $newStatus = 'negotiating';
    $statusChanged = true;

    $stmtUpdate = $con->prepare(
        "UPDATE bookings
         SET booking_status      = ?,
             booking_reviewed_by = ?,
             booking_reviewed_at = NOW()
         WHERE booking_id = ?
           AND booking_status = 'pending'
         LIMIT 1"
    );
    $stmtUpdate->execute([$newStatus, $adminId, $booking_id]);

    if ($stmtUpdate->rowCount() === 0) {
        $statusChanged = false;
        $newStatus = $oldStatus;
    } else {
        logAdminActivity(
            $currentAdmin,
            'open_booking',
            'booking',
            $booking_id,
            [
                "from_status" => $oldStatus,
                "to_status"   => $newStatus,
            ]
        );
    }
} else {
    // نسجّل من فتح الحجز حتى لو لم تتغيّر الحالة
    $stmtTouch = $con->prepare(
        "UPDATE bookings
         SET booking_reviewed_by = ?,
             booking_reviewed_at = NOW()
         WHERE booking_id = ?
         LIMIT 1"
    );
    $stmtTouch->execute([$adminId, $booking_id]);
}

// ============================================================
// الخطوة 5: بناء روابط واتساب
// ============================================================
$whatsappUrl = function (?string $phone): ?string {
    if ($phone === null || $phone === '') {
        return null;
    }
    $digits = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($digits) === 11 && strpos($digits, '0') === 0) {
        $digits = '20' . substr($digits, 1);
    } elseif (strlen($digits) === 10) {
        $digits = '20' . $digits;
    }
    if ($digits === '') {
        return null;
    }
    return 'https://wa.me/' . $digits;
};

echo json_encode([
    "status"  => "success",
    "message" => $statusChanged
        ? "Booking opened and moved to negotiating"
        : "Booking opened",
    "data"    => [
        "booking_id"       => $booking_id,
        "previous_status"  => $oldStatus,
        "booking_status"   => $newStatus,
        "status_changed"   => $statusChanged,
        "reviewed_by"      => $adminId,
        "reviewed_by_name" => $currentAdmin['admin_full_name'] ?? null,
        "client" => [
            "users_full_name" => $current['client_full_name'],
            "users_phone"     => $current['client_phone'],
            "whatsapp_url"    => $whatsappUrl($current['client_phone']),
        ],
        "provider" => [
            "users_id"        => (int)$current['provider_users_id'],
            "users_full_name" => $current['provider_full_name'],
            "users_phone"     => $current['provider_phone'],
            "whatsapp_url"    => $whatsappUrl($current['provider_phone']),
        ],
    ],
]);
