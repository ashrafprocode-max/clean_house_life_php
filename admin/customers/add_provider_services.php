<?php
// ============================================================
// ملف: admin/customers/add_provider_services.php
// الغرض: إضافة خدمة لمقدم خدمة من لوحة الإدارة
//        (قسم رئيسي + قسم فرعي + توقيت + سعر + عمولة)
// الطريقة: POST
//
// ⭐ مقدم الخدمة الواحد قد يقدّم عدة خدمات
//   فيُستدعى هذا الملف مرة لكل خدمة
//
// ⭐⭐ الفرق الجوهري عن provider_services_details/add_provider_details.php:
//   ملف التطبيق: مقدم الخدمة يُضيف خدمته بنفسه
//   هذا الملف : الموظف يُضيفها نيابةً ويُسجَّل معرّفه
//
// ⚠️ ولا نفرض providers_is_verified = 1 هنا
//   لأن المندوب يُدخل الخدمات فوراً بعد إنشاء الملف
//   والتوثيق يأتي لاحقاً من قسم المراجعة
//
// الحقول الإجبارية:
//   ps_user_id · ps_cat_id · ps_sub_cat_id · ps_timing_id · ps_price
//
// الصلاحية المطلوبة: providers.create
// ============================================================

// تضمين ملف الاتصال (مستويان للوراء لأن الملف داخل admin/customers)
include "../../connect.php";

// ============================================================
// الخطوة 1: التحقق من التوكن والصلاحية
// ============================================================
// التحقق من التوكن وجلب بيانات الموظف مع صلاحياته
$currentAdmin = checkAdminToken();

// ⭐ فرض صلاحية إضافة مقدم خدمة (نفس صلاحية إنشاء الملف)
requirePermission($currentAdmin, 'providers.create');

// معرّف الموظف المنفّذ مأخوذ من التوكن لا من الطلب
$adminId = (int) $currentAdmin['admin_id'];

// ============================================================
// الخطوة 2: استقبال بيانات الخدمة
// ============================================================
// معرّف مقدم الخدمة - إجباري
$userId          = filterRequest("ps_user_id");
// معرّف القسم الرئيسي - إجباري
$catId           = filterRequest("ps_cat_id");
// معرّف القسم الفرعي - إجباري
$subCatId        = filterRequest("ps_sub_cat_id");
// معرّف التوقيت - إجباري
$timingId        = filterRequest("ps_timing_id");
// السعر المحدد - إجباري
$price           = filterRequest("ps_price");
// السعر المبدئي التقديري - اختياري
$priceEstimate   = filterRequest("ps_price_estimate");
// هل السعر قابل للتفاوض؟ - اختياري
$negotiable      = filterRequest("ps_negotiable");
// نوع العمولة: fixed أو percent - اختياري
$commissionType  = filterRequest("ps_commission_type");
// قيمة العمولة - اختياري
$commissionValue = filterRequest("ps_commission_value");

// ============================================================
// الخطوة 3: التحقق من الحقول الإجبارية
// ============================================================
// إذا نقص أي حقل إجباري نوقف التنفيذ فوراً
if ($userId === '' || $catId === '' || $subCatId === '' ||
    $timingId === '' || $price === '') {
    // رسالة عربية توضح الحقول المطلوبة
    printFailure(
        "يرجى إدخال كل البيانات الإجبارية: مقدم الخدمة، "
        . "القسم الرئيسي، القسم الفرعي، التوقيت، السعر"
    );
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 4: تحويل المعرّفات لأرقام صحيحة
//
// ⚠️ إلزامي لحماية جملة WHERE من أي محتوى غير رقمي
// ============================================================
// معرّف مقدم الخدمة
$userId    = (int) $userId;
// معرّف القسم الرئيسي
$catId     = (int) $catId;
// معرّف القسم الفرعي
$subCatId  = (int) $subCatId;
// معرّف التوقيت
$timingId  = (int) $timingId;

// ⭐ تحويل السعر لرقم عشري بعد إزالة الفواصل
// ⚠️ لأن الموظف قد يكتب 1,500 والتحويل يرفض الفاصلة
$price     = (float) str_replace(',', '', $price);

// ============================================================
// ⭐ الخطوة 5: التحقق من السعر
// ============================================================
// السعر يجب أن يكون أكبر من صفر
if ($price <= 0) {
    // رسالة توضح أن السعر إجباري وموجب
    printFailure("السعر يجب أن يكون أكبر من صفر");
    // إيقاف التنفيذ
    exit;
}

// ⚠️ الحد الأقصى يحمي عمود DECIMAL(10,2)
if ($price > 9999999) {
    // رسالة توضح الحد الأقصى
    printFailure("السعر كبير جداً، الحد الأقصى 9,999,999 جنيه");
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// ⭐ الخطوة 6: التحقق من وجود مقدم الخدمة وملفه المهني
//
// ⭐ نجلب حالة التوثيق لنُنبّه الموظف في الرد
//    لكن لا نمنع الإضافة لأن التوثيق خطوة لاحقة
// ============================================================
// تجهيز استعلام يجمع الحساب وملفه المهني
$stmtProvider = $con->prepare(
    "SELECT
        u.users_id,
        u.users_full_name,
        u.users_phone,
        u.users_type,
        u.users_is_active,
        -- ⭐ بيانات الملف المهني
        pp.providers_id,
        pp.providers_is_verified
     FROM users u
     -- ربط الملف المهني
     LEFT JOIN professional_profiles pp
            ON pp.providers_users_id = u.users_id
     WHERE u.users_id = ?
     LIMIT 1"
);
// تنفيذ الاستعلام بمعرّف المستخدم
$stmtProvider->execute([$userId]);
// جلب الصف
$provider = $stmtProvider->fetch(PDO::FETCH_ASSOC);

// إذا لم يوجد الحساب نرفض الطلب
if (!$provider) {
    // رسالة توضح أن الحساب غير موجود
    printFailure("مقدم الخدمة غير موجود", 404);
    // إيقاف التنفيذ
    exit;
}

// ⚠️ التحقق أن نوع الحساب provider
if ($provider['users_type'] !== 'provider') {
    // رسالة توضح المشكلة والحل
    printFailure(
        "هذا الحساب نوعه (" . $provider['users_type'] . ") "
        . "ولا يمكن إضافة خدمات له — يجب أن يكون provider",
        409
    );
    // إيقاف التنفيذ
    exit;
}

// ⚠️ التحقق أن الحساب مفعّل
if ((int)$provider['users_is_active'] !== 1) {
    // رسالة توضح أن الحساب معطّل
    printFailure("هذا الحساب معطّل — فعّله أولاً قبل إضافة خدمات", 403);
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// ⭐ الخطوة 7: التحقق من وجود الملف المهني
//
// ⚠️ لماذا نمنع الإضافة بلا ملف مهني؟
//   لأن شاشة مزودي الخدمة تعرض بيانات الملف مع الخدمة
//   فخدمة بلا ملف تظهر بلا صورة ولا عمر ولا موقع
// ============================================================
// نتحقق من وجود الملف المهني
if (empty($provider['providers_id'])) {
    // ⭐ رسالة توضح الخطوة الناقصة بدقة
    printFailure(
        "لا يوجد ملف مهني لهذا الحساب — "
        . "أنشئ الملف المهني أولاً من add_provider_account.php",
        409,
        [
            // معرّف المستخدم
            "users_id"  => $userId,
            // ⭐ الخطوة التي يجب تنفيذها أولاً
            "next_step" => "add_provider_account",
        ]
    );
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// ⭐ الخطوة 8: التحقق من وجود القسم الرئيسي وأنه مفعّل
//
// ⭐ نفحص categories_is_active لأنك وافقت على التعطيل بدل الحذف
// ⚠️ لكن نفحص وجود العمود أولاً حتى يعمل الملف قبل تنفيذ SQL
// ============================================================
// متغيّر يحمل بيانات القسم الرئيسي
$category = null;

// ⚠️ نحمي الفحص بـ try/catch لأن العمود قد لا يكون مُنفَّذاً بعد
try {
    // ⭐ محاولة الاستعلام مع عمود التفعيل
    $stmtCat = $con->prepare(
        "SELECT categories_id, categories_name_ar, categories_is_active
         FROM categories WHERE categories_id = ? LIMIT 1"
    );
    // تنفيذ الاستعلام بمعرّف القسم
    $stmtCat->execute([$catId]);
    // جلب صف القسم
    $category = $stmtCat->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // ⚠️ العمود غير موجود بعد فنستعلم بلا عمود التفعيل
    $stmtCat = $con->prepare(
        "SELECT categories_id, categories_name_ar
         FROM categories WHERE categories_id = ? LIMIT 1"
    );
    // تنفيذ الاستعلام بمعرّف القسم
    $stmtCat->execute([$catId]);
    // جلب صف القسم
    $category = $stmtCat->fetch(PDO::FETCH_ASSOC);
}

// إذا لم يوجد القسم نرفض الطلب
if (!$category) {
    // رسالة توضح أن القسم غير موجود
    printFailure("القسم الرئيسي غير موجود", 404);
    // إيقاف التنفيذ
    exit;
}

// ⚠️ نرفض القسم المعطّل إن كان العمود موجوداً
if (isset($category['categories_is_active']) &&
    (int)$category['categories_is_active'] !== 1) {
    // رسالة توضح أن القسم معطّل
    printFailure(
        "القسم الرئيسي (" . $category['categories_name_ar'] . ") "
        . "معطّل — لا يمكن إضافة خدمات فيه",
        409
    );
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// ⭐ الخطوة 9: التحقق أن القسم الفرعي ينتمي للرئيسي
//
// ⚠️⚠️ أهم فحص في الملف كله
//   لأن الباگ القديم كان خلط ps_sub_cat_id بـ sub_cat_id
//   فظهرت خدمات في أقسام لا تنتمي إليها
// ============================================================
// تجهيز استعلام يتحقق من الانتماء
$stmtSub = $con->prepare(
    "SELECT sub_id, sub_name_ar
     FROM sub_categories
     WHERE sub_id = ? AND sub_category_id = ?
     LIMIT 1"
);
// تنفيذ الاستعلام بمعرّف القسم الفرعي والرئيسي
$stmtSub->execute([$subCatId, $catId]);
// جلب صف القسم الفرعي
$subCategory = $stmtSub->fetch(PDO::FETCH_ASSOC);

// إذا لم ينتمِ الفرعي للرئيسي نرفض الطلب
if (!$subCategory) {
    // ⭐ رسالة توضح المشكلة بدقة
    printFailure(
        "القسم الفرعي لا ينتمي للقسم الرئيسي "
        . "(" . $category['categories_name_ar'] . ") — "
        . "تأكد من اختيار القسم الفرعي الصحيح",
        409
    );
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 10: التحقق من وجود التوقيت
// ============================================================
// تجهيز استعلام جلب التوقيت
$stmtTiming = $con->prepare(
    "SELECT timing_id, timing_name_ar
     FROM service_timing WHERE timing_id = ? LIMIT 1"
);
// تنفيذ الاستعلام بمعرّف التوقيت
$stmtTiming->execute([$timingId]);
// جلب صف التوقيت
$timing = $stmtTiming->fetch(PDO::FETCH_ASSOC);

// إذا لم يوجد التوقيت نرفض الطلب
if (!$timing) {
    // رسالة توضح أن التوقيت غير موجود
    printFailure("التوقيت غير موجود", 404);
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// ⭐ الخطوة 11: منع تكرار نفس الخدمة بنفس التوقيت
//
// ⭐ لماذا؟ لأن مقدم الخدمة قد يقدّم "تنظيف وطبخ" بتوقيتين مختلفين
//   وهذا مسموح، لكن نفس الخدمة بنفس التوقيت مرتين خطأ إدخال
// ============================================================
// تجهيز استعلام البحث عن تكرار
$stmtDup = $con->prepare(
    "SELECT ps_id, ps_price FROM provider_services
     WHERE ps_user_id = ? AND ps_sub_cat_id = ? AND ps_timing_id = ?
     LIMIT 1"
);
// تنفيذ الاستعلام بالثلاثة معاً
$stmtDup->execute([$userId, $subCatId, $timingId]);
// جلب الصف إن وُجد
$duplicate = $stmtDup->fetch(PDO::FETCH_ASSOC);

// إذا وُجدت الخدمة نفسها بنفس التوقيت نرفض الإضافة
if ($duplicate) {
    // ⭐ رد 409 يحمل معرّف الخدمة الموجودة وسعرها
    printFailure(
        "هذه الخدمة (" . $subCategory['sub_name_ar'] . ") "
        . "مضافة بالفعل بتوقيت (" . $timing['timing_name_ar'] . ") "
        . "بسعر " . number_format((float)$duplicate['ps_price'], 0, '.', ',')
        . " ج.م — عدّلها بدل إضافتها",
        409,
        [
            // معرّف الخدمة الموجودة
            "ps_id"    => (int) $duplicate['ps_id'],
            // سعرها الحالي
            "ps_price" => (float) $duplicate['ps_price'],
        ]
    );
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// ⭐ الخطوة 12: تجهيز قيم العمولة
// ============================================================
// ⭐ نوع العمولة الافتراضي نسبة مئوية
$commissionType = ($commissionType === 'fixed') ? 'fixed' : 'percent';

// ⭐ تحويل قيمة العمولة لرقم عشري
$commissionValue = (float) str_replace(',', '', $commissionValue);

// ⚠️ القيمة الافتراضية 10 (نفس default العمود في MySQL)
if ($commissionValue <= 0) {
    // نضع النسبة الافتراضية
    $commissionValue = 10;
}

// ⚠️ نسبة العمولة لا تتجاوز 100 بالمئة
if ($commissionType === 'percent' && $commissionValue > 100) {
    // رسالة توضح الحد الأقصى
    printFailure("نسبة العمولة لا تتجاوز 100%");
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// ⭐ الخطوة 12-أ: فرض الحد الأدنى لعمولة مقدم الخدمة (10%)
//
// قاعدة العمل:
//   عمولة المقدم A لا يمكن أن تقل عن 10% من سعر الخدمة،
//   سواء أدخلها كنسبة مئوية أو كمبلغ ثابت.
//   المبلغ الأدنى = سعر الخدمة × 10 ÷ 100.
//   يمكن زيادة العمولة عن ذلك، لكن لا يمكن تقليلها.
//
// لماذا نتحقق هنا أيضاً وليس في Flutter فقط؟
//   لأن أي شخص يمكنه استدعاء الـ API مباشرة، فلا بد من
//   حماية قاعدة العمل على السيرفر نفسه.
// ============================================================
// حساب الحد الأدنى بالمبلغ
$minCommissionAmount = round(($price * 10) / 100, 2);

// حساب مبلغ العمولة المدخلة كما سيدخل قاعدة البيانات
$enteredCommissionAmount = $commissionType === 'percent'
    // نسبة مئوية من السعر
    ? round(($price * $commissionValue) / 100, 2)
    // مبلغ ثابت كما هو
    : round($commissionValue, 2);

// رفض الطلب إذا كانت العمولة أقل من 10%
if ($enteredCommissionAmount < $minCommissionAmount) {
    // رسالة عربية تفهمها واجهة مقدم الخدمة
    printFailure(
        "الحد الأدنى لعمولة مقدم الخدمة هو 10% من السعر ("
        . number_format($minCommissionAmount, 2, '.', ',')
        . " ج.م). يمكنك زيادتها فقط.",
        422,
        [
            // الحد الأدنى المسموح
            "min_commission_amount" => $minCommissionAmount,
            // النسبة الدنيا عند اختيار النسبة
            "min_commission_percent" => 10,
        ]
    );
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// ⭐ الخطوة 13: حساب العمولة والسعر النهائي للعميل
//
// ⚠️ نحسبها في PHP لا في فلاتر
//    حتى تظهر بنفس القيم في اللوحة والتطبيق وPostman
// ============================================================
// متغيّر يحمل مبلغ العمولة
$commissionAmount = 0;

// ⭐ نحسب المبلغ حسب نوع العمولة
if ($commissionType === 'percent') {
    // ⭐ نسبة مئوية من السعر
    // مثال: 300 ج.م × 10% = 30 ج.م
    $commissionAmount = ($price * $commissionValue) / 100;
} else {
    // ⭐ مبلغ ثابت كما هو
    $commissionAmount = $commissionValue;
}

// ⭐ السعر الذي يراه العميل = سعر مقدم الخدمة + العمولة
$totalForClient = $price + $commissionAmount;

// ============================================================
// ⭐ الخطوة 14: تجهيز السعر المبدئي وحالة التفاوض
// ============================================================
// ⭐ تحويل السعر المبدئي لرقم إن أُرسل
$priceEstimateValue = null;

// نتحقق أن السعر المبدئي أُرسل فعلاً
if ($priceEstimate !== '') {
    // تحويله لرقم عشري بعد إزالة الفواصل
    $priceEstimateValue = (float) str_replace(',', '', $priceEstimate);
    // ⚠️ نتجاهله إن كان صفراً أو سالباً
    if ($priceEstimateValue <= 0) {
        // نُفرّغه فتُحفظ NULL في العمود
        $priceEstimateValue = null;
    }
}

// ⭐ حالة التفاوض: 1 أو 0 فقط
$negotiableValue = ((int)$negotiable === 1) ? 1 : 0;

// ============================================================
// الخطوة 15: إدخال الخدمة في جدول provider_services
// ============================================================
// بناء مصفوفة البيانات بأسماء أعمدة الجدول حرفياً
$insertData = [
    // معرّف مقدم الخدمة
    "ps_user_id"          => $userId,
    // معرّف القسم الرئيسي
    "ps_cat_id"           => $catId,
    // ⭐ معرّف القسم الفرعي (بعد التحقق من انتمائه)
    "ps_sub_cat_id"       => $subCatId,
    // معرّف التوقيت
    "ps_timing_id"        => $timingId,
    // السعر المبدئي (null إن لم يُرسل)
    "ps_price_estimate"   => $priceEstimateValue,
    // السعر المحدد
    "ps_price"            => $price,
    // هل قابل للتفاوض؟
    "ps_negotiable"       => $negotiableValue,
    // نوع العمولة
    "ps_commission_type"  => $commissionType,
    // قيمة العمولة
    "ps_commission_value" => $commissionValue,
    // ⭐ مبلغ عمولة مقدم الخدمة (A) بالجنيه بعد الحساب (مخفية عن العميل)
    "ps_commission_amount" => round((float) $commissionAmount, 2),
];

// ⚠️ نمرر false لمنع الدالة من طباعة JSON لأننا نبني رداً مخصصاً
$result = insertData("provider_services", $insertData, false);

// ============================================================
// الخطوة 16: التحقق من نجاح الإدخال
// ============================================================
// إذا فشل الإدخال نرفض الطلب برسالة واضحة
if ($result <= 0) {
    // رسالة توضح فشل الحفظ
    printFailure("فشل حفظ الخدمة، يرجى المحاولة مرة أخرى", 500);
    // إيقاف التنفيذ
    exit;
}

// ⭐ قراءة معرّف الخدمة الجديد
$newServiceId = (int) $con->lastInsertId();

// ============================================================
// ⭐ الخطوة 17: تسجيل الإجراء في سجل النشاط
// ============================================================
// تسجيل إجراء إضافة الخدمة
logAdminActivity(
    // بيانات الموظف المنفّذ
    $currentAdmin,
    // نوع الإجراء
    'create_provider_service',
    // نوع الكيان
    'provider_service',
    // معرّف الخدمة الجديدة
    $newServiceId,
    // ⭐ التفاصيل التي نحتاجها في المراجعة لاحقاً
    [
        // معرّف مقدم الخدمة
        "users_id"      => $userId,
        // اسم مقدم الخدمة
        "provider_name" => $provider['users_full_name'],
        // ⭐ اسم القسم الفرعي (الخدمة)
        "service_ar"    => $subCategory['sub_name_ar'],
        // ⭐ اسم التوقيت
        "timing_ar"     => $timing['timing_name_ar'],
        // السعر
        "price"         => $price,
        // نوع العمولة
        "commission"    => $commissionType . ' ' . $commissionValue,
    ]
);

// ============================================================
// ⭐ الخطوة 18: عدّ خدمات مقدم الخدمة بعد الإضافة
//
// ⭐ لماذا؟ ليعرف الموظف كم خدمة أضاف حتى الآن
//    فيقرر هل يُضيف المزيد أم ينتهي
// ============================================================
// تجهيز استعلام عدّ الخدمات
$stmtCount = $con->prepare(
    "SELECT COUNT(*) AS total FROM provider_services WHERE ps_user_id = ?"
);
// تنفيذ الاستعلام بمعرّف مقدم الخدمة
$stmtCount->execute([$userId]);
// قراءة العدد
$servicesCount = (int) $stmtCount->fetch(PDO::FETCH_ASSOC)['total'];

// ============================================================
// ⭐ الخطوة 19: إرسال الرد النهائي
// ============================================================
echo json_encode([
    // حالة العملية
    "status"  => "success",
    // ⭐ رسالة عربية توضح ما أُضيف
    "message" => "تم إضافة خدمة (" . $subCategory['sub_name_ar'] . ") "
                 . "بتوقيت (" . $timing['timing_name_ar'] . ") بنجاح",
    // بيانات النتيجة
    "data"    => [

        // ===== ⭐ بيانات الخدمة =====
        "service" => [
            // ⭐ معرّف الخدمة الجديد
            "ps_id"            => $newServiceId,
            // معرّف مقدم الخدمة
            "ps_user_id"       => $userId,
            // معرّف القسم الرئيسي
            "ps_cat_id"        => $catId,
            // ⭐ اسم القسم الرئيسي جاهزاً للعرض
            "category_ar"      => $category['categories_name_ar'],
            // معرّف القسم الفرعي
            "ps_sub_cat_id"    => $subCatId,
            // ⭐ اسم القسم الفرعي جاهزاً للعرض
            "sub_category_ar"  => $subCategory['sub_name_ar'],
            // معرّف التوقيت
            "ps_timing_id"     => $timingId,
            // ⭐ اسم التوقيت جاهزاً للعرض
            "timing_ar"        => $timing['timing_name_ar'],
        ],

        // ===== ⭐ التسعير والعمولة =====
        "pricing" => [
            // سعر مقدم الخدمة كرقم
            "ps_price"                 => (float) $price,
            // ⭐ السعر منسّقاً للعرض
            "price_display"            => number_format($price, 0, '.', ',')
                                          . ' ج.م',
            // السعر المبدئي (null إن لم يُرسل)
            "ps_price_estimate"        => $priceEstimateValue,
            // هل قابل للتفاوض؟
            "ps_negotiable"            => $negotiableValue,
            // ⭐ ترجمة حالة التفاوض
            "negotiable_ar"            => $negotiableValue === 1
                                          ? 'قابل للتفاوض' : 'سعر ثابت',
            // نوع العمولة
            "ps_commission_type"       => $commissionType,
            // ⭐ ترجمة نوع العمولة
            "commission_type_ar"       => $commissionType === 'percent'
                                          ? 'نسبة مئوية' : 'مبلغ ثابت',
            // قيمة العمولة كما أُدخلت
            "ps_commission_value"      => (float) $commissionValue,
            // ⭐ مبلغ العمولة المحسوب
            "commission_amount"        => round($commissionAmount, 2),
            // ⭐ مبلغ العمولة منسّقاً
            "commission_display"       => number_format($commissionAmount, 2, '.', ',')
                                          . ' ج.م',
            // ⭐⭐ السعر النهائي الذي يراه العميل
            "total_for_client"         => round($totalForClient, 2),
            // ⭐ السعر النهائي منسّقاً
            "total_for_client_display" => number_format($totalForClient, 0, '.', ',')
                                          . ' ج.م',
        ],

        // ===== ⭐ سياق مقدم الخدمة =====
        "provider" => [
            // معرّف المستخدم
            "users_id"        => $userId,
            // اسم مقدم الخدمة
            "users_full_name" => $provider['users_full_name'],
            // ⚠️ هاتفه (للإدارة فقط)
            "users_phone"     => $provider['users_phone'],
            // ⭐ عدد خدماته بعد هذه الإضافة
            "services_count"  => $servicesCount,
            // حالة التوثيق رقماً
            "is_verified"     => (int) $provider['providers_is_verified'],
            // ⭐ ترجمة حالة التوثيق
            "verification_ar" => match((int)$provider['providers_is_verified']) {
                0       => 'في انتظار المراجعة',
                1       => 'موثق',
                2       => 'مرفوض',
                default => 'غير محدد',
            },
            // ⭐⭐ تنبيه إن كان غير موثق
            // ⚠️ مهم جداً: خدماته لن تظهر للعملاء قبل التوثيق
            "notice_ar"       => (int)$provider['providers_is_verified'] !== 1
                ? "⚠️ مقدم الخدمة غير موثق بعد — "
                  . "خدماته لن تظهر للعملاء قبل توثيقه من قسم المراجعة"
                : null,
        ],

        // ===== سجل التنفيذ =====
        "created_by" => [
            // معرّف الموظف المنفّذ
            "admin_id"   => $adminId,
            // اسم الموظف المنفّذ
            "admin_name" => $currentAdmin['admin_full_name'],
            // ⭐ دور الموظف مترجماً
            "role_ar"    => $currentAdmin['role_name_ar'] ?? null,
        ],
    ],
]);
?>