<?php
// ============================================================
// ملف: admin/contracts/secure_contract_file.php
// الغرض: بوابة آمنة لملفات العقود وإثباتات الدفع
// الصلاحية المطلوبة: contracts.view أو payments.view
// الطريقة: GET file=...
// يُستدعى من اللوحة عند فتح المسح أو إثبات التحصيل.
// يبث الملف inline. لا يُرجع JSON. يمنع path traversal.
// ============================================================

// اتصال قاعدة البيانات وجلسات الإدارة
include "../../connect.php";

// جلسة الموظف إلزامية
$currentAdmin = checkAdminToken();
// يكفي إحدى صلاحيتَي العرض لأن الملف قد يكون عقداً أو إثبات دفع
if (!adminCan($currentAdmin, 'contracts.view') && !adminCan($currentAdmin, 'payments.view')) {
    printFailure("ليس لديك صلاحية عرض ملفات العقود", 403);
}

// basename يمنع المسارات المطلقة و../ داخل الاسم
$file = basename((string) filterRequest("file"));
// اسم فارغ أو نقاط صريحة يُرفض حتى بعد basename
if ($file === '' || strpos($file, '..') !== false) {
    printFailure("اسم الملف غير صالح", 400);
}

// مجلدان فقط: مسح العقود وإثباتات التحصيل
$dirs = [
    __DIR__ . "/../../upload/contracts/" . $file,
    __DIR__ . "/../../upload/contract_payments/" . $file,
];

// أول ملف موجود يُستخدم حتى لا يُكشف إن وُجد في المجلدين
$path = null;
foreach ($dirs as $candidate) {
    if (is_file($candidate)) {
        $path = $candidate;
        break;
    }
}
if ($path === null) {
    printFailure("الملف غير موجود", 404);
}

// الامتداد يحدد نوع المحتوى للمتصفح
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
// الأنواع المعروضة داخل اللوحة دون تنزيل قسري
$types = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    'gif' => 'image/gif',
    'pdf' => 'application/pdf',
];
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
// inline للعرض داخل التبويب وليس كمرفق تنزيل
header('Content-Disposition: inline; filename="' . $file . '"');
readfile($path);
exit;
