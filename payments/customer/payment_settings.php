<?php
// ============================================================
// ملف: payments/customer/payment_settings.php
// الغرض: إرجاع إعدادات رسوم الدفع الحالية إلى تطبيق Flutter
//
// لماذا؟
//   شاشة الدفع تعرض للعميل معاينة الفاتورة قبل فتح بوابة الدفع:
//     قيمة الحجز + رسوم الدفع = الإجمالي
//   والإدارة قد تُعدّل نسبة الرسوم أو حدها الأدنى أو تلغيها،
//   لذلك يقرأ التطبيق الإعدادات من السيرفر ولا يثبّت 1% في الكود.
//
// ملاحظة:
//   هذا للعرض فقط. الحساب النهائي يحدث داخل create_payment.php
//   ولا يمكن للعميل التلاعب به.
//
// الطريقة: GET أو POST
// ============================================================

// ربط قاعدة البيانات والدوال العامة
include "../../connect.php";

// دوال الدفع المساعدة
include "../includes/payment_helper.php";

// قراءة كل إعدادات التطبيق
$settings = getAppSettings($con);

// استخراج إعدادات الرسوم
$fee = getPaymentFeeSettings($settings);

// إرجاع الإعدادات
printSuccess([
    // هل الرسوم مفعّلة؟
    "payment_fee_enabled" => $fee['enabled'] ? 1 : 0,
    // نوع الرسوم: percent أو fixed
    "payment_fee_type" => $fee['type'],
    // قيمة/نسبة الرسوم
    "payment_fee_value" => (float) $fee['value'],
    // الحد الأدنى بالجنيه
    "payment_fee_min" => (float) $fee['min'],
    // العملة الحالية
    "payment_currency" => (string) paymobConfigValue('currency', 'EGP'),
    // هل بوابة Paymob مفعّلة في الإعدادات؟
    "paymob_enabled" => (string) ($settings['paymob_enabled'] ?? '1') === '1' ? 1 : 0,
]);
