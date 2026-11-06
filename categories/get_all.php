<?php
// ============================================================
// ملف: categories/get_all.php
// الغرض: جلب جميع الأقسام الرئيسية لعرضها في DropDown
// الطريقة: GET
// لا يحتاج أي معاملات
// ============================================================

// تضمين ملف الاتصال والدوال
include "../connect.php";

// ============================================================
// الخطوة 1: جلب جميع الأقسام مرتبة أبجدياً
// ============================================================
$stmt = $con->prepare(
    "SELECT
        categories_id,
        categories_name_ar,
        categories_name_en,
        categories_image
     FROM categories
     ORDER BY categories_id ASC"
);
$stmt->execute();
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 2: التحقق من وجود بيانات
// ============================================================
if (empty($categories)) {
    printFailure("No categories found");
    exit;
}

// ============================================================
// الخطوة 3: بناء رابط الصور وإرسال الرد
// ============================================================
// $baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
//     . '://' . $_SERVER['HTTP_HOST']
//     . str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME']);
// $baseUrl = rtrim($baseUrl, '/');

// // تحويل البيانات لتضم روابط الصور كاملة
// $result = array_map(function ($cat) use ($baseUrl) {
//     return [
//         "categories_id"      => (int)$cat['categories_id'],
//         "categories_name_ar" => $cat['categories_name_ar'],
//         "categories_name_en" => $cat['categories_name_en'],
//         // رابط الصورة كامل
//         "categories_image"   => $cat['categories_image']
//             ? $baseUrl . '/upload/categories/' . $cat['categories_image']
//             : null,
//     ];
// }, $categories);


// ============================================================
// الخطوة 3: بناء رابط الصور وإرسال الرد
// ============================================================
/*
 * SHARH EL-TETLEEL / شرح سبب التكرار والتعديل:
 * ------------------------------------------------------------
 * الكود القديم كان يستخرج مسار الملف الحالي عبر SCRIPT_NAME والذي يعطي:
 * "/clean_house_life_php/categories/get_all.php"
 * وعند إزالة اسم الملف فقط (get_all.php)، يصبح $baseUrl يحتوي على "/clean_house_life_php/categories".
 * وبالتالي عند إضافة "/upload/categories/" لاحقاً، كان ينتج رابط مكرر يحتوي على كلمة (categories/) مرتين!
 * 
 * الحل:
 * تم استخدام dirname() مرتين للرجوع للمجلد الرئيسي للمشروع (Root Directory) 
 * وهو "/clean_house_life_php"، ليكون $baseUrl نظيفاً ويتجه للمجلد الرئيسي مباشرة دون تكرار.
 */

$scriptDir = dirname(dirname($_SERVER['SCRIPT_NAME'])); // يعود من categories إلى المجلد الرئيسي للمشروع
$baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . ($scriptDir === '/' || $scriptDir === '\\' ? '' : $scriptDir);

$baseUrl = rtrim($baseUrl, '/');

// تحويل البيانات لتضم روابط الصور كاملة
$result = array_map(function ($cat) use ($baseUrl) {
    return [
        "categories_id"      => (int)$cat['categories_id'],
        "categories_name_ar" => $cat['categories_name_ar'],
        "categories_name_en" => $cat['categories_name_en'],
        // رابط الصورة كامل
        "categories_image"   => $cat['categories_image']
            ? $baseUrl . '/upload/categories/' . $cat['categories_image']
            : null,
    ];
}, $categories);

// ============================================================
// الخطوة 4: إرسال الرد
// ============================================================
echo json_encode([
    "status"  => "success",
    "message" => "Categories fetched successfully",
    "count"   => count($result),
    "data"    => $result,
]);
?>