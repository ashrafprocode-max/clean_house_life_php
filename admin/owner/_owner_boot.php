<?php
// ============================================================
// ملف: admin/owner/_owner_boot.php
// الغرض: تمهيد مشترك لكل ملفات قسم المالك
//        ربط القاعدة + فرض دور owner + دوال رفع ومساعدة
//
// ⚠️ يُضمَّن من ملفات admin/owner فقط ولا يُستدعى من المتصفح مباشرة
// ============================================================

// ربط ملف الاتصال بقاعدة البيانات (مستويان للوراء)
include "../../connect.php";

// فرض دور المالك — الحماية الحقيقية هنا وليست في الواجهة
$currentAdmin = checkAdminToken('owner');

// صلاحية قسم المالك من مصفوفة RBAC إن وُجدت
requirePermission($currentAdmin, 'owner.employees');

// معرّف المالك الحالي لاستخدامه في created_by
$ownerId = (int) $currentAdmin['admin_id'];

/**
 * رفع ملف موظف إلى مجلد محدد
 * تُعيد اسم الملف أو empty أو fail أو extension_error أو size_error
 */
function ownerUploadFile(string $dir, string $field): string
{
    // إذا لم يُرسل الملف نعيد empty دون فشل
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return "empty";
    }
    // اسم الملف الأصلي
    $imgName = $_FILES[$field]['name'] ?? '';
    // المسار المؤقت
    $imgTmp = $_FILES[$field]['tmp_name'] ?? '';
    // الحجم بالبايت
    $imgSize = (int) ($_FILES[$field]['size'] ?? 0);
    // الامتدادات المسموحة للصور والعقود
    $allowExt = array("jpg", "jpeg", "png", "webp", "gif", "pdf");
    // استخراج الامتداد
    $parts = explode(".", $imgName);
    $ext = strtolower((string) end($parts));
    // رفض الامتداد غير المسموح
    if ($imgName === '' || !in_array($ext, $allowExt, true)) {
        return "extension_error";
    }
    // رفض الحجم أكبر من 8 ميجا
    if ($imgSize > 8 * MB) {
        return "size_error";
    }
    // إنشاء المجلد إن لم يوجد
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    // اسم فريد يمنع التداخل
    $newName = "EMP_" . uniqid() . "_" . time() . "." . $ext;
    // نقل الملف من المؤقت إلى المجلد النهائي
    if (move_uploaded_file($imgTmp, rtrim($dir, "/") . "/" . $newName)) {
        return $newName;
    }
    return "fail";
}

/**
 * تحويل نص تاريخ إلى Y-m-d أو إرجاع فارغ
 */
function ownerDateOrEmpty(string $value): string
{
    // إزالة المسافات
    $value = trim($value);
    // رفض الفارغ
    if ($value === '') {
        return '';
    }
    // قبول الصيغة القياسية فقط
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return '';
    }
    return $value;
}

/**
 * تحويل مبلغ نصي إلى رقم عشري آمن
 */
function ownerMoney(string $value): float
{
    // إزالة الفواصل
    $clean = str_replace(',', '', trim($value));
    // تحويل لرقم
    $n = (float) $clean;
    // رفض السالب
    if ($n < 0) {
        return 0.0;
    }
    return $n;
}
