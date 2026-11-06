<?php
// ============================================================
// ملف: bookings/customer/checkcoupon.php
// الغرض: التحقق من صلاحية كوبون الخصم قبل تأكيد الحجز
//        يُستدعى من صفحة booking_checkout.dart عند ضغط زر "تطبيق الكوبون"
//
// ⭐ ثلاثة شروط لصلاحية الكوبون:
//   1. الاسم موجود في جدول coupon
//   2. تاريخ الانتهاء لم يمر بعد (coupon_expiredate > الآن)
//   3. عدد الاستخدامات لم ينفد (coupon_count > 0)
//
// ⭐ معامل اختياري booking_ps_id:
//   إذا أُرسل نحسب معاينة فورية للخصم والإجمالي الجديد
//   ليعرض Flutter للعميل: "وفّرت 10 ج.م - الإجمالي 1090 ج.م"
//
// ⚠️ هذا الملف للمعاينة فقط ولا يُنقص coupon_count
//    الإنقاص يحدث لحظة إنشاء الحجز فعلاً في add_booking.php
//
// الطريقة: POST
// ============================================================

// ربط ملف الاتصال بقاعدة البيانات (مستويان للوراء لأن الملف داخل bookings/customer)
include "../../connect.php";

// ============================================================
// الخطوة 1: استقبال البيانات
// ============================================================
// اسم الكوبون الذي كتبه العميل (نُنظّفه من المسافات الزائدة)
$coupon_name   = trim(filterRequest("coupon_name"));
// معرّف الخدمة (اختياري) لحساب معاينة الخصم على عمولتها الفعلية
$booking_ps_id = filterRequest("booking_ps_id");

// ============================================================
// الخطوة 2: التحقق من إرسال اسم الكوبون
// ============================================================
// إذا لم يُرسل الاسم نوقف التنفيذ فوراً
if ($coupon_name === '') {
    // رسالة توضح أن اسم الكوبون مطلوب
    printFailure("coupon_name is required");
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 3: البحث عن الكوبون بشروط الصلاحية الثلاثة
//   ⚠️ نستخدم prepared statement بعلامة الاستفهام
//   بدلاً من دمج الاسم في نص الاستعلام لحماية السيرفر من SQL Injection
// ============================================================
// الوقت الحالي لمقارنته بتاريخ انتهاء صلاحية الكوبون
$now = date("Y-m-d H:i:s");

// تجهيز استعلام آمن يجمع الشروط الثلاثة في جملة WHERE واحدة
$stmtCoupon = $con->prepare(
    "SELECT coupon_id, coupon_name, coupon_discount, coupon_count, coupon_expiredate
     FROM coupon
     WHERE coupon_name = ? AND coupon_expiredate > ? AND coupon_count > 0
     LIMIT 1"
);
// تنفيذ الاستعلام بتمرير اسم الكوبون والوقت الحالي
$stmtCoupon->execute([$coupon_name, $now]);
// جلب صف الكوبون كمصفوفة
$coupon = $stmtCoupon->fetch(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 4: إذا لم يوجد الكوبون نحدد السبب الحقيقي للعميل
//   نُعيد البحث بالاسم فقط لنعرف: غير موجود؟ منتهي؟ نفد؟
// ============================================================
// نتحقق فقط إذا فشل الاستعلام الأول
if (!$coupon) {
    // استعلام ثانٍ يبحث بالاسم فقط بدون شروط الصلاحية
    $stmtWhy = $con->prepare(
        "SELECT coupon_count, coupon_expiredate FROM coupon
         WHERE coupon_name = ? LIMIT 1"
    );
    // تنفيذ الاستعلام بتمرير الاسم فقط
    $stmtWhy->execute([$coupon_name]);
    // جلب الصف إن وُجد
    $why = $stmtWhy->fetch(PDO::FETCH_ASSOC);

    // إذا لم يوجد الاسم إطلاقاً فالكوبون غير صحيح
    if (!$why) {
        // رسالة توضح أن الكوبون غير موجود
        printFailure("Coupon not found", 404);
        // إيقاف التنفيذ
        exit;
    }
    // إذا وُجد الاسم لكن تاريخه مضى فالكوبون منتهي الصلاحية
    if (strtotime($why['coupon_expiredate']) <= time()) {
        // رسالة توضح انتهاء صلاحية الكوبون
        printFailure("Coupon has expired", 410);
        // إيقاف التنفيذ
        exit;
    }
    // وإلا فالسبب الوحيد المتبقي هو نفاد عدد الاستخدامات
    printFailure("Coupon usage limit reached", 409);
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 5: قراءة نسبة الخصم من الكوبون الصالح
// ============================================================
// نسبة الخصم المئوية التي ستُخصم من العمولة
$couponDiscountPercent = (float) $coupon['coupon_discount'];

// ============================================================
// الخطوة 6: معاينة اختيارية للخصم على خدمة محددة
//   نحسبها فقط إذا أرسل Flutter معرّف الخدمة
// ============================================================
// تهيئة مصفوفة المعاينة بقيمة null (لن تُرسل إن لم يُرسل معرّف الخدمة)
$preview = null;

// نتحقق فقط إذا أرسل Flutter معرّف الخدمة
if ($booking_ps_id !== '') {
    // استعلام يجلب السعر ونوع وقيمة العمولة للخدمة المطلوبة
    $stmtService = $con->prepare(
        "SELECT ps_price, ps_commission_type, ps_commission_value
         FROM provider_services
         WHERE ps_id = ? LIMIT 1"
    );
    // تنفيذ الاستعلام بتمرير معرّف الخدمة
    $stmtService->execute([$booking_ps_id]);
    // جلب صف الخدمة
    $service = $stmtService->fetch(PDO::FETCH_ASSOC);

    // نحسب المعاينة فقط إذا وُجدت الخدمة فعلاً
    if ($service) {
        // السعر الأساسي الذي حدده مقدم الخدمة
        $psPrice = (float) $service['ps_price'];
        // نوع العمولة: fixed مبلغ ثابت أو percent نسبة مئوية
        $commissionType = $service['ps_commission_type'] ?: 'percent';
        // قيمة العمولة (مبلغ أو نسبة حسب النوع أعلاه)
        $commissionValue = (float) $service['ps_commission_value'];

        // تهيئة متغير قيمة العمولة الفعلية بالجنيه
        $commissionAmount = 0;
        // إذا كان النوع نسبة مئوية نحسب النسبة من السعر
        if ($commissionType === 'percent' && $psPrice > 0) {
            // مثال: 1000 × 10 ÷ 100 = 100 ج.م عمولة
            $commissionAmount = ($psPrice * $commissionValue) / 100;
        } else {
            // في حالة المبلغ الثابت نستخدم القيمة كما هي
            $commissionAmount = $commissionValue;
        }

        // ⭐ قيمة الخصم تُحسب من العمولة فقط وليس من سعر الخدمة
        // مثال: 100 × 10 ÷ 100 = 10 ج.م خصم
        $couponDiscountAmount = ($commissionAmount * $couponDiscountPercent) / 100;

        // العمولة بعد خصم الكوبون (مثال: 100 - 10 = 90 ج.م)
        $commissionAfterCoupon = $commissionAmount - $couponDiscountAmount;
        // حماية منطقية: لا نسمح للعمولة بأن تصبح رقماً سالباً
        if ($commissionAfterCoupon < 0) {
            // تصفير العمولة في هذه الحالة الاستثنائية
            $commissionAfterCoupon = 0;
        }

        // تجميع بيانات المعاينة لإرسالها لـ Flutter
        $preview = [
            // سعر الخدمة كما حدده مقدم الخدمة (لا يتأثر بالكوبون)
            "service_price"             => round($psPrice, 2),
            // العمولة قبل تطبيق الكوبون
            "commission_before"         => round($commissionAmount, 2),
            // قيمة الخصم بالجنيه
            "coupon_discount_amount"    => round($couponDiscountAmount, 2),
            // العمولة بعد تطبيق الكوبون
            "commission_after"          => round($commissionAfterCoupon, 2),
            // الإجمالي بدون كوبون (لعرضه مشطوباً في الواجهة)
            "total_without_coupon"      => round($psPrice + $commissionAmount, 2),
            // الإجمالي النهائي بعد الكوبون
            "total_with_coupon"         => round($psPrice + $commissionAfterCoupon, 2),
        ];
    }
}

// ============================================================
// الخطوة 7: إرسال الرد النهائي
//   ⚠️ لا نُرجع coupon_count للعميل لأنه بيان إداري داخلي
// ============================================================
// إرسال JSON يحتوي على بيانات الكوبون الصالح والمعاينة إن وُجدت
echo json_encode([
    // حالة العملية
    "status"  => "success",
    // رسالة نجاح واضحة
    "message" => "Coupon is valid",
    // بيانات الكوبون
    "data"    => [
        // معرّف الكوبون الذي سيُخزَّن في حقل booking_coupon
        "coupon_id"           => (int) $coupon['coupon_id'],
        // اسم الكوبون كما هو مسجّل في قاعدة البيانات
        "coupon_name"         => $coupon['coupon_name'],
        // نسبة الخصم المئوية
        "coupon_discount"     => (int) $coupon['coupon_discount'],
        // تاريخ انتهاء الصلاحية لعرضه للعميل
        "coupon_expiredate"   => $coupon['coupon_expiredate'],
        // توضيح أن الخصم يُطبَّق على العمولة فقط
        "discount_applies_on" => "commission_only",
        // معاينة الحساب (تكون null إذا لم يُرسل booking_ps_id)
        "preview"             => $preview,
    ],
]);
?>