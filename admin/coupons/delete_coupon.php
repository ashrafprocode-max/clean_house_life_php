<?php
// ============================================================
// ملف: admin/coupons/delete_coupon.php
// الغرض: تعطيل كوبون خصم من لوحة الإدارة (حذف ناعم)
//
// ⚠️⚠️ لا يوجد DELETE فعلي في هذا الملف إطلاقاً
//      لأن bookings.booking_coupon يشير إلى coupon.coupon_id
//      فلو حذفنا الصف لظهرت الحجوزات القديمة بخصم مفقود
//      ولكسر أي JOIN مستقبلي على جدول coupon
//
// ⭐ البديل المعتمد: التعطيل
//    coupon_is_active = 0  → الصف يبقى فتعمل كل الـ JOINs
//                             لكن الكوبون لا يُقبل في حجوزات جديدة
//
// ⭐ إعادة التفعيل تتم من edit_coupon.php بإرسال coupon_is_active = 1
//
// الحقل الإجباري: coupon_id
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
//    لأن تعطيل كوبون يؤثر على الخصومات المتاحة
// ============================================================
// التحقق من التوكن وجلب بيانات الموظف مع صلاحياته
$currentAdmin = checkAdminToken();

// فرض صلاحية إدارة الكوبونات
requirePermission($currentAdmin, 'coupons.manage');

// ============================================================
// الخطوة 2: استقبال معرّف الكوبون والتحقق منه
// ============================================================
// معرّف الكوبون المراد تعطيله - إجباري
$coupon_id = filterRequest("coupon_id");

// إذا لم يُرسل المعرّف نوقف التنفيذ فوراً
if ($coupon_id === '') {
    printFailure("الحقل coupon_id مطلوب لتعطيل الكوبون");
    exit;
}

// تحويل المعرّف لرقم صحيح لحماية جملة WHERE من أي محتوى غير رقمي
$coupon_id = (int) $coupon_id;

// لا نقبل صفراً لأن AUTO_INCREMENT يبدأ من 1
if ($coupon_id < 1) {
    printFailure("coupon_id غير صالح");
    exit;
}

// ============================================================
// الخطوة 3: جلب الكوبون للتأكد أنه موجود قبل التعطيل
// ============================================================
$stmtCoupon = $con->prepare(
    "SELECT coupon_id, coupon_name, coupon_discount,
            coupon_expiredate, coupon_is_active
     FROM coupon
     WHERE coupon_id = ?
     LIMIT 1"
);
// تنفيذ الاستعلام بتمرير معرّف الكوبون
$stmtCoupon->execute([$coupon_id]);
// جلب صف الكوبون
$targetCoupon = $stmtCoupon->fetch(PDO::FETCH_ASSOC);

// إذا لم يوجد الكوبون نرفض التعطيل
if (!$targetCoupon) {
    printFailure("الكوبون غير موجود", 404);
    exit;
}

// إذا كان معطّلاً مسبقاً نُخبر الموظف بدل تكرار التحديث
if (isset($targetCoupon['coupon_is_active']) &&
    (int) $targetCoupon['coupon_is_active'] === 0) {
    printFailure("الكوبون معطّل بالفعل", 409);
    exit;
}

// ============================================================
// الخطوة 4: التحديث — coupon_is_active = 0 فقط
// ============================================================
// تجهيز استعلام التعطيل المحمي
$stmtOff = $con->prepare(
    "UPDATE coupon
     SET coupon_is_active = 0
     WHERE coupon_id = ?
     LIMIT 1"
);
// تنفيذ التحديث بمعرّف الكوبون
$stmtOff->execute([$coupon_id]);

// ============================================================
// الخطوة 5: تسجيل الإجراء في سجل النشاط الإداري
// ============================================================
// تسجيل تعطيل الكوبون باسم الموظف المنفّذ
logAdminActivity(
    // بيانات الموظف المنفّذ
    $currentAdmin,
    // نوع الإجراء
    'deactivate_coupon',
    // نوع الكيان
    'coupon',
    // معرّف الكوبون المعطّل
    $coupon_id,
    // تفاصيل المراجعة
    [
        // اسم الكوبون
        "coupon_name"     => $targetCoupon['coupon_name'],
        // نسبة الخصم
        "coupon_discount" => (int)$targetCoupon['coupon_discount'],
    ]
);

// ============================================================
// الخطوة 6: إرسال رد نجاح التعطيل
// ============================================================
echo json_encode([
    // حالة العملية
    "status"  => "success",
    // رسالة عربية جاهزة للعرض في اللوحة
    "message" => "تم تعطيل الكوبون بنجاح — لم يُحذف من قاعدة البيانات",
    // بيانات الكوبون بعد التعطيل
    "data"    => [
        "coupon_id"        => $coupon_id,
        "coupon_name"      => $targetCoupon['coupon_name'],
        "coupon_count"     => 0,
        "coupon_discount"  => (int)$targetCoupon['coupon_discount'],
        "coupon_expiredate"=> $targetCoupon['coupon_expiredate'],
        // ⭐ صفر = معطّل ولا يُقبل في حجوزات جديدة
        "coupon_is_active" => 0,
        // من نفّذ التعطيل
        "deactivated_by"   => $currentAdmin['admin_full_name'],
    ],
]);
?>
