<?php
// ============================================================
// ملف: admin/categories/edit_category.php
// الغرض: تعديل بيانات قسم رئيسي موجود في جدول categories
//
// الحقل الإجباري الوحيد: categories_id
// وكل الحقول الأخرى اختيارية — نُحدّث ما أُرسل فقط
//
// ⭐ لماذا التحديث الجزئي؟
//    لأن الموظف قد يريد تصحيح الاسم العربي وحده
//    ولو أرسلنا كل الحقول لأصبح أي حقل نسيه = مسح للبيانات
//
// الحقول القابلة للتعديل:
//   categories_name_ar
//   categories_name_en
//   categories_sort
//   categories_is_active  (1 لإعادة التفعيل بعد delete_category.php)
//   categories_image      (ملف أيقونة جديد)
//
// ⚠️ لا حذف فعلي هنا — التعطيل من delete_category.php
//    وهذا الملف يسمح بإعادة التفعيل فقط عبر is_active = 1
//
// الطريقة: POST (multipart/form-data إذا رُفعت أيقونة)
// الصلاحية المطلوبة: catalog.manage
// ============================================================

// ربط ملف الاتصال بقاعدة البيانات (مستويان للوراء لأن الملف داخل admin/categories)
include "../../connect.php";

// ============================================================
// الخطوة 1: التحقق من التوكن والصلاحية
// ============================================================
// التحقق من التوكن وجلب بيانات الموظف مع صلاحياته
$currentAdmin = checkAdminToken();

// ⭐ فرض صلاحية إدارة الكتالوج
requirePermission($currentAdmin, 'catalog.manage');

// ============================================================
// الخطوة 2: استقبال معرّف القسم والتحقق منه
// ============================================================
// معرّف القسم المراد تعديله - إجباري
$categories_id = filterRequest("categories_id");

// إذا لم يُرسل المعرّف نوقف التنفيذ فوراً
if ($categories_id === '') {
    // رسالة توضح الحقل المطلوب
    printFailure("الحقل categories_id مطلوب");
    // إيقاف التنفيذ
    exit;
}

// تحويل المعرّف لرقم صحيح لحماية جملة WHERE من أي محتوى غير رقمي
$categories_id = (int) $categories_id;

// لا نقبل صفراً لأن AUTO_INCREMENT يبدأ من 1
if ($categories_id < 1) {
    // رسالة توضح أن المعرّف غير صالح
    printFailure("categories_id غير صالح");
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 3: جلب بيانات القسم الحالية
//
// ⭐ نحتاجها لسببين:
//    1. التأكد أن القسم موجود قبل التعديل
//    2. معرفة اسم الأيقونة القديمة لحذفها بعد رفع الجديدة
// ============================================================
// تجهيز استعلام محمي لجلب الصف الحالي
$stmtCurrent = $con->prepare(
    "SELECT
        categories_id,
        categories_name_ar,
        categories_name_en,
        categories_image,
        categories_is_active,
        categories_sort
     FROM categories
     WHERE categories_id = ?
     LIMIT 1"
);
// تنفيذ الاستعلام بتمرير معرّف القسم
$stmtCurrent->execute([$categories_id]);
// جلب الصف الحالي
$current = $stmtCurrent->fetch(PDO::FETCH_ASSOC);

// إذا لم يوجد القسم نرفض التعديل
if (!$current) {
    // رسالة توضح أن القسم غير موجود
    printFailure("القسم غير موجود", 404);
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 4: استقبال الحقول القابلة للتعديل (كلها اختيارية)
// ============================================================
// الاسم العربي الجديد
$categories_name_ar   = trim(filterRequest("categories_name_ar"));
// الاسم الإنجليزي الجديد
$categories_name_en   = trim(filterRequest("categories_name_en"));
// ترتيب العرض الجديد
$categories_sort      = trim(filterRequest("categories_sort"));
// حالة التفعيل الجديدة (1 لإعادة التفعيل)
$categories_is_active = filterRequest("categories_is_active");

// ============================================================
// الخطوة 5: بناء مصفوفة التحديث بالحقول المُرسَلة فقط
// ============================================================
// تهيئة مصفوفة فارغة سنملأها بما أرسله الموظف
$updateData = [];

// ---------- الاسم العربي ----------
// نتحقق فقط إذا أُرسل الحقل
if ($categories_name_ar !== '') {
    // قياس الطول لأن العمود varchar(100)
    if (mb_strlen($categories_name_ar) > 100) {
        // رسالة توضح الحد الأقصى
        printFailure("categories_name_ar يجب ألا يتجاوز 100 حرف");
        // إيقاف التنفيذ
        exit;
    }
    // الحد الأدنى حتى لا يُحفظ حرف واحد بلا معنى
    if (mb_strlen($categories_name_ar) < 2) {
        // رسالة توضح الحد الأدنى
        printFailure("الاسم العربي يجب أن يكون حرفين على الأقل");
        // إيقاف التنفيذ
        exit;
    }
    // إضافة الاسم العربي للتحديث
    $updateData["categories_name_ar"] = $categories_name_ar;
}

// ---------- الاسم الإنجليزي ----------
// نتحقق فقط إذا أُرسل الحقل
if ($categories_name_en !== '') {
    // قياس الطول لأن العمود varchar(100)
    if (mb_strlen($categories_name_en) > 100) {
        // رسالة توضح الحد الأقصى
        printFailure("categories_name_en يجب ألا يتجاوز 100 حرف");
        // إيقاف التنفيذ
        exit;
    }
    // الحد الأدنى للاسم الإنجليزي
    if (mb_strlen($categories_name_en) < 2) {
        // رسالة توضح الحد الأدنى
        printFailure("الاسم الإنجليزي يجب أن يكون حرفين على الأقل");
        // إيقاف التنفيذ
        exit;
    }
    // إضافة الاسم الإنجليزي للتحديث
    $updateData["categories_name_en"] = $categories_name_en;
}

// ============================================================
// الخطوة 6: منع تكرار الاسم مع استثناء القسم الحالي
// ============================================================
// الاسم العربي الذي سنفحصه: الجديد إن أُرسل وإلا الاسم الحالي
$checkNameAr = $updateData["categories_name_ar"] ?? $current['categories_name_ar'];
// الاسم الإنجليزي الذي سنفحصه: الجديد إن أُرسل وإلا الاسم الحالي
$checkNameEn = $updateData["categories_name_en"] ?? $current['categories_name_en'];

// نفحص التكرار فقط إذا تغيّر أحد الاسمين فعلاً
if (isset($updateData["categories_name_ar"]) || isset($updateData["categories_name_en"])) {
    // استعلام يبحث عن نفس الاسم في صف آخر غير هذا القسم
    $stmtDup = $con->prepare(
        "SELECT categories_id FROM categories
         WHERE (categories_name_ar = ? OR categories_name_en = ?)
           AND categories_id <> ?
         LIMIT 1"
    );
    // تنفيذ الاستعلام بالاسمين ومعرّف القسم الحالي
    $stmtDup->execute([$checkNameAr, $checkNameEn, $categories_id]);
    // إذا وُجد قسم آخر بنفس الاسم نرفض التعديل
    if ($stmtDup->rowCount() > 0) {
        // رسالة توضح أن الاسم مستخدم
        printFailure("اسم القسم مستخدم بالفعل لقسم آخر", 409);
        // إيقاف التنفيذ
        exit;
    }
}

// ---------- ترتيب العرض ----------
// نقبل القيمة صفر لذلك نقارن بالنص الفارغ وليس empty
if ($categories_sort !== '') {
    // تحويل الترتيب لرقم صحيح
    $sortValue = (int) $categories_sort;
    // رفض القيم السالبة لأن العمود UNSIGNED
    if ($sortValue < 0) {
        // رسالة توضح أن الترتيب لا يكون سالباً
        printFailure("categories_sort يجب أن يكون صفراً أو أكبر");
        // إيقاف التنفيذ
        exit;
    }
    // إضافة الترتيب للتحديث
    $updateData["categories_sort"] = $sortValue;
}

// ---------- حالة التفعيل (إعادة تفعيل قسم معطّل) ----------
// نقبل القيمة صفر لذلك نقارن بالنص الفارغ وليس empty
if ($categories_is_active !== '') {
    // تحويل القيمة لرقم صحيح (0 أو 1 فقط)
    $updateData["categories_is_active"] = ((int) $categories_is_active === 1) ? 1 : 0;
}

// ============================================================
// الخطوة 7: رفع أيقونة جديدة (اختيارية)
//
// ⭐ نرفع الجديدة أولاً ثم نحذف القديمة بعد نجاح الرفع
//    وليس العكس، حتى لا نفقد الأيقونة القديمة لو فشل الرفع
// ============================================================
// مسار مجلد أيقونات الأقسام
$uploadDir = __DIR__ . "/../../upload/categories/";

// اسم الأيقونة الجديدة إن رُفعت
$newImageName = null;

// نتحقق أن الملف أُرسل فعلاً وليس حقلاً فارغاً
if (isset($_FILES['categories_image']) &&
    $_FILES['categories_image']['error'] !== UPLOAD_ERR_NO_FILE) {

    // استخراج امتداد الملف الأصلي وتحويله لحروف صغيرة
    $imgExt = strtolower(pathinfo(
        $_FILES['categories_image']['name'],
        PATHINFO_EXTENSION
    ));

    // ⭐ قائمة امتدادات الأيقونات المسموحة للأقسام
    $allowedIconExt = ['png', 'svg', 'jpg', 'jpeg', 'webp'];

    // إذا كان الامتداد غير مسموح نرفض الرفع
    if (!in_array($imgExt, $allowedIconExt, true)) {
        // رسالة توضح الامتدادات المسموحة
        printFailure("أيقونة القسم يجب أن تكون: png أو svg أو jpg أو jpeg أو webp");
        // إيقاف التنفيذ
        exit;
    }

    // رفع الأيقونة الجديدة بدالتك الموحدة
    $newImageName = imageUploadtwo(
        // مسار مجلد أيقونات الأقسام
        $uploadDir,
        // اسم حقل الملف في الطلب
        "categories_image"
    );

    // نتابع فقط إذا نجح الرفع
    if (!in_array($newImageName,
                  ["fail", "empty", "extension_error", "size_error"], true)) {
        // تخزين اسم الملف الجديد للتحديث
        $updateData["categories_image"] = $newImageName;
    } else {
        // ⚠️ نُفرّغ المتغيّر لأن الملف لم يُرفع فعلاً
        $newImageName = null;
        // رسالة توضح فشل الرفع
        printFailure("فشل رفع أيقونة القسم، تأكد أن حجمها أقل من 10 ميجا");
        // إيقاف التنفيذ
        exit;
    }
}

// ============================================================
// الخطوة 8: التأكد من وجود حقل واحد على الأقل للتحديث
// ============================================================
// إذا كانت المصفوفة فارغة فلا يوجد ما نُحدّثه
if (empty($updateData)) {
    // رسالة توضح عدم إرسال أي حقل
    printFailure("لم يتم إرسال أي حقل للتعديل");
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 9: تنفيذ التحديث باستخدام دالتك الموحدة
// ============================================================
// استدعاء دالة التحديث مع false لمنع طباعة JSON مرتين
$result = updateData(
    "categories",
    $updateData,
    "categories_id = $categories_id",
    false
);

// ============================================================
// الخطوة 10: حذف الأيقونة القديمة بعد نجاح حفظ الاسم الجديد
//
// ⚠️ نحذفها بعد التحديث لا قبله
//    حتى لو فشل SQL تبقى الأيقونة القديمة في القرص وفي العمود
// ============================================================
// نتحقق أن أيقونة جديدة رُفعت وأن القديمة مختلفة عنها
if ($newImageName !== null &&
    !empty($current['categories_image']) &&
    $current['categories_image'] !== $newImageName) {
    // حذف ملف الأيقونة القديمة من مجلد الأقسام
    deleteFile($uploadDir, $current['categories_image']);
}

// ============================================================
// الخطوة 11: جلب البيانات بعد التعديل
//
// ⚠️ updateData تُرجع rowCount
//    و MySQL تُرجع 0 إذا كانت القيم الجديدة مطابقة للقديمة
//    فلا نعتبر 0 فشلاً، بل نُعيد جلب الصف
// ============================================================
// تجهيز استعلام لجلب الصف بعد التعديل
$stmtAfter = $con->prepare(
    "SELECT
        categories_id,
        categories_name_ar,
        categories_name_en,
        categories_image,
        categories_is_active,
        categories_sort,
        categories_created
     FROM categories
     WHERE categories_id = ?
     LIMIT 1"
);
// تنفيذ الاستعلام بتمرير معرّف القسم
$stmtAfter->execute([$categories_id]);
// جلب الصف المحدَّث
$updated = $stmtAfter->fetch(PDO::FETCH_ASSOC);

// إذا فشل الجلب فهناك خطأ حقيقي في قاعدة البيانات
if (!$updated) {
    // رسالة توضح فشل جلب البيانات
    printFailure("تم التعديل لكن تعذّر جلب البيانات المحدَّثة", 500);
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 12: تسجيل الإجراء في سجل النشاط الإداري
// ============================================================
// تسجيل تعديل القسم
logAdminActivity(
    // بيانات الموظف المنفّذ
    $currentAdmin,
    // نوع الإجراء
    'edit_category',
    // نوع الكيان
    'category',
    // معرّف القسم
    $categories_id,
    // الحقول التي تغيّرت فقط
    [
        // مفاتيح الحقول المحدَّثة
        "updated_fields" => array_keys($updateData),
    ]
);

// ============================================================
// الخطوة 13: بناء رابط الأيقونة بنفس أسلوب categories/get_all.php
// ============================================================
// الرجوع من admin/categories إلى جذر المشروع
$scriptDir = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'])));
// بناء عنوان الأساس مع دعم https
$baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . ($scriptDir === '/' || $scriptDir === '\\' ? '' : $scriptDir);
// إزالة الشرطة المائلة الزائدة من نهاية الرابط
$baseUrl = rtrim($baseUrl, '/');

// ============================================================
// الخطوة 14: إرسال الرد النهائي
// ============================================================
// إرسال JSON يحتوي البيانات بعد التعديل
echo json_encode([
    // حالة العملية
    "status"  => "success",
    // رسالة نجاح بالعربية
    "message" => "تم تحديث بيانات القسم بنجاح",
    // بيانات القسم بعد التعديل
    "data"    => [
        // معرّف القسم
        "categories_id"        => (int) $updated['categories_id'],
        // الاسم العربي بعد التعديل
        "categories_name_ar"   => $updated['categories_name_ar'],
        // الاسم الإنجليزي بعد التعديل
        "categories_name_en"   => $updated['categories_name_en'],
        // رابط الأيقونة الكامل أو null
        "categories_image"     => $updated['categories_image']
            ? $baseUrl . '/upload/categories/' . $updated['categories_image']
            : null,
        // حالة التفعيل
        "categories_is_active" => (int) $updated['categories_is_active'],
        // ترتيب العرض
        "categories_sort"      => (int) $updated['categories_sort'],
        // تاريخ الإنشاء
        "categories_created"   => $updated['categories_created'],
        // من نفّذ التعديل
        "updated_by"           => $currentAdmin['admin_full_name'],
    ],
]);
?>
