<?php
// ============================================================
// ملف: payments/result.php
// الغرض: صفحة عربية تظهر للعميل بعد العودة من بوابة الدفع
//
// لماذا صفحة ويب وليست شاشة Flutter؟
//   لأن الدفع يتم في المتصفح (IFrame/Mahfaza)، وبعد الانتهاء
//   يعود المتصفح لصفحة على السيرفر. هذه الصفحة تشرح النتيجة،
//   ثم يعود العميل للتطبيق الذي يستعلم عن الحالة تلقائياً.
//
// المدخلات: reference + state (paid / failed / pending / invalid)
// ============================================================

// قراءة الرقم المرجعي من الرابط
$reference = htmlspecialchars($_GET['reference'] ?? '', ENT_QUOTES, 'UTF-8');

// قراءة الحالة
$state = strtolower($_GET['state'] ?? 'pending');

// تجهيز النصوص حسب الحالة
if ($state === 'paid') {
    // نجاح الدفع
    $title = 'تم الدفع بنجاح';
    $message = 'تم استلام مبلغ الحجز بنجاح. يمكنك العودة إلى التطبيق لمتابعة تفاصيل الحجز.';
    $color = '#1B8A5A';
    $icon = '✓';
} elseif ($state === 'failed') {
    // فشل الدفع
    $title = 'لم تكتمل عملية الدفع';
    $message = 'لم يتم خصم المبلغ. يمكنك المحاولة مرة أخرى من شاشة الدفع في التطبيق.';
    $color = '#C0392B';
    $icon = '!';
} elseif ($state === 'invalid') {
    // توقيع غير صالح
    $title = 'تعذّر التحقق من العملية';
    $message = 'بيانات الدفع غير صحيحة. يرجى التواصل مع الدعم إذا تم خصم أي مبلغ.';
    $color = '#C0392B';
    $icon = '!';
} else {
    // قيد المعالجة
    $title = 'جارٍ تأكيد الدفع';
    $message = 'قد يستغرق التأكيد بضع ثوانٍ. ارجع إلى التطبيق وسيتم تحديث حالة الحجز تلقائياً.';
    $color = '#B7791F';
    $icon = '...';
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <!-- الترميز لضمان ظهور العربية بشكل صحيح -->
    <meta charset="UTF-8">
    <!-- عرض متجاوب على الموبايل -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- عنوان الصفحة -->
    <title><?php echo $title; ?></title>
    <style>
        /* إعدادات عامة للصفحة */
        body {
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #F5F7FA;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Tahoma, Arial, sans-serif;
        }
        /* بطاقة النتيجة */
        .card {
            background: #FFFFFF;
            width: 90%;
            max-width: 420px;
            border-radius: 20px;
            padding: 32px 24px;
            text-align: center;
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.08);
        }
        /* دائرة الأيقونة */
        .circle {
            width: 84px;
            height: 84px;
            border-radius: 50%;
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 42px;
            color: #FFFFFF;
            background: <?php echo $color; ?>;
        }
        /* العنوان */
        h1 {
            font-size: 22px;
            color: #1F2937;
            margin: 0 0 12px;
        }
        /* النص التوضيحي */
        p {
            font-size: 15px;
            line-height: 1.8;
            color: #4B5563;
            margin: 0 0 20px;
        }
        /* رقم العملية */
        .reference {
            font-size: 12px;
            color: #9CA3AF;
            direction: ltr;
            word-break: break-all;
        }
        /* زر العودة (إرشادي فقط لأن العودة للتطبيق يدوية) */
        .hint {
            display: inline-block;
            margin-top: 12px;
            padding: 12px 22px;
            border-radius: 12px;
            background: #EEF2FF;
            color: #3730A3;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="card">
        <!-- أيقونة الحالة -->
        <div class="circle"><?php echo $icon; ?></div>

        <!-- عنوان النتيجة -->
        <h1><?php echo $title; ?></h1>

        <!-- شرح النتيجة -->
        <p><?php echo $message; ?></p>

        <!-- الرقم المرجعي (يفيد الدعم الفني) -->
        <?php if ($reference !== ''): ?>
            <div class="reference"><?php echo $reference; ?></div>
        <?php endif; ?>

        <!-- إرشاد العودة -->
        <div class="hint">يمكنك الآن إغلاق هذه الصفحة والعودة إلى التطبيق</div>
    </div>
</body>
</html>
