<?php
// ============================================================
// ملف: admin/coupons/edit_coupon.php
// الغرض: تعديل بيانات كوبون خصم (تحديث جزئي)
//
// ⭐ الحقل الإجباري: coupon_id
//    والبقية اختيارية ولا يُحدَّث إلا ما أُرسل فعلاً
//
// الحقول الاختيارية:
//   coupon_name / coupon_count / coupon_discount /
//   coupon_expiredate (Y-m-d H:i:s) / coupon_is_active (0 أو 1)
//
// ⭐ إرسال coupon_is_active = 1 يُعيد تفعيل كوبون معطّل
//
// الطريقة: POST
// الصلاحية المطلوبة: coupons.manage
// ============================================================

// ربط ملف الاتصال بقاعدة البيانات (مستويان للوراء لأن الملف داخل admin/coupons)
include "../../connect.php";

// ⭐ حماية ملف الإدارة: يجب إرسال المفتاح السري في هيدر X-Admin-Key
// ⚠️ لو أردت تعطيل الحماية مؤقتاً أثناء التطوير ضع // قبل السطر التالي فقط
// checkAdminKey();

// ============================================================
// الخطوة 1: التحقق من التوكن والصلاحية
//
// ⭐ coupons.manage يملكها: owner و manager فقط
// ============================================================
// التحقق من التوكن وجلب بيانات الموظف مع صلاحياته
$currentAdmin = checkAdminToken();

// فرض صلاحية إدارة الكوبونات
requirePermission($currentAdmin, 'coupons.manage');

// ============================================================
// الخطوة 2: استقبال معرّف الكوبون والتحقق منه
// ============================================================
$coupon_id = filterRequest("coupon_id");

// إذا لم يُرسل المعرّف نوقف التنفيذ فوراً
if ($coupon_id === '') {
    printFailure("الحقل coupon_id مطلوب لتعديل الكوبون");
    exit;
}

// تحويل المعرّف لرقم صحيح لحماية جملة WHERE
$coupon_id = (int) $coupon_id;

// لا نقبل صفراً لأن AUTO_INCREMENT يبدأ من 1
if ($coupon_id < 1) {
    printFailure("coupon_id غير صالح");
    exit;
}

// ============================================================
// الخطوة 3: جلب الكوبون للتأكد أنه موجود
// ============================================================
$stmtCoupon = $con->prepare(
    "SELECT coupon_id, coupon_name, coupon_count, coupon_discount,
            coupon_expiredate, coupon_is_active
     FROM coupon
     WHERE coupon_id = ?
     LIMIT 1"
);
// تنفيذ الاستعلام بتمرير معرّف الكوبون
$stmtCoupon->execute([$coupon_id]);
// جلب صف الكوبون
$targetCoupon = $stmtCoupon->fetch(PDO::FETCH_ASSOC);

// إذا لم يوجد الكوبون نرفض التعديل
if (!$targetCoupon) {
    printFailure("الكوبون غير موجود", 404);
    exit;
}

// ============================================================
// الخطوة 4: بناء مصفوفة الحقول المرسلة فعلاً
// ============================================================
$updateData = [];

// ===== اسم الكوبون =====
$coupon_name = trim(filterRequest("coupon_name"));
if ($coupon_name !== '') {
    // قياس الطول مع دعم العربية لأن العمود varchar(50)
    if (mb_strlen($coupon_name) > 50) {
        printFailure("coupon_name must not exceed 50 characters");
        exit;
    }
    // منع تكرار الاسم مع كوبون آخر
    $stmtDup = $con->prepare(
        "SELECT coupon_id FROM coupon WHERE coupon_name = ? AND coupon_id <> ? LIMIT 1"
    );
    $stmtDup->execute([$coupon_name, $coupon_id]);
    if ($stmtDup->rowCount() > 0) {
        printFailure("This coupon name already exists", 409);
        exit;
    }
    $updateData["coupon_name"] = $coupon_name;
}

// ===== عدد مرات الاستخدام =====
$coupon_count = filterRequest("coupon_count");
if ($coupon_count !== '') {
    $coupon_count = (int) $coupon_count;
    if ($coupon_count < 1) {
        printFailure("coupon_count must be at least 1");
        exit;
    }
    $updateData["coupon_count"] = $coupon_count;
}

// ===== نسبة الخصم من العمولة =====
$coupon_discount = filterRequest("coupon_discount");
if ($coupon_discount !== '') {
    $coupon_discount = (int) $coupon_discount;
    if ($coupon_discount < 1 || $coupon_discount > 100) {
        printFailure("coupon_discount must be a percentage between 1 and 100");
        exit;
    }
    $updateData["coupon_discount"] = $coupon_discount;
}

// ===== تاريخ ووقت الانتهاء =====
$coupon_expiredate = filterRequest("coupon_expiredate");
if ($coupon_expiredate !== '') {
    $expireTimestamp = strtotime($coupon_expiredate);
    if ($expireTimestamp === false) {
        printFailure("coupon_expiredate must be a valid datetime (Y-m-d H:i:s)");
        exit;
    }
    if ($expireTimestamp <= time()) {
        printFailure("coupon_expiredate must be a future date");
        exit;
    }
    $updateData["coupon_expiredate"] = date("Y-m-d H:i:s", $expireTimestamp);
}

// ===== حالة التفعيل (يُستخدم لإعادة تفعيل كوبون معطّل) =====
$coupon_is_active = filterRequest("coupon_is_active");
if ($coupon_is_active !== '') {
    $coupon_is_active = (int) $coupon_is_active;
    if ($coupon_is_active !== 0 && $coupon_is_active !== 1) {
        printFailure("coupon_is_active must be 0 or 1");
        exit;
    }
    $updateData["coupon_is_active"] = $coupon_is_active;
}

// ============================================================
// الخطوة 5: التأكد من وجود حقل واحد على الأقل للتعديل
// ============================================================
if (empty($updateData)) {
    printFailure("Provide at least one field to update", 422);
    exit;
}

// ============================================================
// الخطوة 6: تنفيذ التحديث
//
// ⚠️ rowCount() قد تُرجع صفراً لو أُرسلت نفس القيم
//    لذا لا نعتبرها فشلاً — التعديل ناجح طالما الكوبون موجود
// ============================================================
updateData("coupon", $updateData, "coupon_id = $coupon_id", false);

// ============================================================
// الخطوة 7: إعادة جلب البيانات بعد التحديث
// ============================================================
$stmtAfter = $con->prepare(
    "SELECT coupon_id, coupon_name, coupon_count, coupon_discount,
            coupon_expiredate, coupon_is_active
     FROM coupon
     WHERE coupon_id = ?
     LIMIT 1"
);
$stmtAfter->execute([$coupon_id]);
$after = $stmtAfter->fetch(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 8: تسجيل الإجراء في سجل النشاط الإداري
// ============================================================
logAdminActivity(
    // بيانات الموظف المنفّذ
    $currentAdmin,
    // نوع الإجراء
    'edit_coupon',
    // نوع الكيان
    'coupon',
    // معرّف الكوبون المعدّل
    $coupon_id,
    // الحقول التي تغيّرت
    [
        "updated_fields" => array_keys($updateData),
        "before"         => $targetCoupon,
        "after"          => $after,
    ]
);

// ============================================================
// الخطوة 9: إرسال رد النجاح
// ============================================================
echo json_encode([
    // حالة العملية
    "status"  => "success",
    // رسالة نجاح عربية جاهزة للعرض
    "message" => "تم تحديث الكوبون بنجاح",
    // بيانات الكوبون بعد التعديل
    "data"    => [
        "coupon_id"           => (int)$after['coupon_id'],
        "coupon_name"         => $after['coupon_name'],
        "coupon_count"        => (int)$after['coupon_count'],
        "coupon_discount"     => (int)$after['coupon_discount'],
        "coupon_expiredate"   => $after['coupon_expiredate'],
        "coupon_is_active"    => (int)$after['coupon_is_active'],
        "discount_applies_on" => "commission_only",
        // الحقول التي تغيّرت فعلاً
        "updated_fields"      => array_keys($updateData),
    ],
]);
?>
