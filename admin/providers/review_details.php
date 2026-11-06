<?php
// ============================================================
// ملف: admin/providers/review_details.php
// الغرض: عرض كل بيانات مقدم الخدمة للمراجعة التفصيلية
//        من جدولي users و professional_profiles معاً
//        بما فيها صور البطاقة والصحيفة الجنائية وإيصال المرافق
//
// ⚠️ هذا الملف للإدارة فقط ولا يُستدعى من تطبيق العميل إطلاقاً
//    لأنه يكشف: رقم الهاتف + صور البطاقة + الفيش + إيصال المرافق
//
// ⭐ الصور تُرجَع كروابط بوّابة آمنة (secure_image.php) وليست روابط مباشرة
//    حتى لا يستطيع أحد فتح صورة بطاقة بمعرفة اسم الملف
//
// الحقول المطلوبة: providers_users_id
// الطريقة: POST أو GET
// ============================================================

// ربط ملف الاتصال بقاعدة البيانات (مستويان للوراء لأن الملف داخل admin/providers)
include "../../connect.php";

// ⭐ حماية ملف الإدارة: يجب إرسال المفتاح السري في هيدر X-Admin-Key
// ⚠️ لو أردت تعطيل الحماية مؤقتاً أثناء التطوير ضع // قبل السطر التالي فقط
// checkAdminKey();
// ⭐ التحقق من توكن الإدارة وجلب بيانات الموظف الحالي
// أي موظف مسجّل دخوله (مدير أو خدمة عملاء) يستطيع القراءة
$currentAdmin = checkAdminToken();

// ============================================================
// الخطوة 1: استقبال معرّف مقدم الخدمة
// ============================================================
// معرّف المستخدم المراد مراجعة بياناته - إجباري
$users_id = filterRequest("providers_users_id");

// ============================================================
// الخطوة 2: التحقق من الحقل الإجباري
// ============================================================
// إذا لم يُرسل المعرّف نوقف التنفيذ فوراً
if (empty($users_id)) {
    // رسالة توضح أن المعرّف مطلوب
    printFailure("providers_users_id is required");
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 3: جلب كل بيانات مقدم الخدمة من الجدولين
// ⭐ هنا نجلب كل الأعمدة بما فيها المستندات الحساسة
//    لأن هذا ملف الإدارة وليس ملف تطبيق العميل
// ============================================================
$stmt = $con->prepare(
    "SELECT
        -- ===== كل بيانات جدول professional_profiles =====
        pp.providers_id,
        pp.providers_users_id,
        pp.providers_experience,
        pp.providers_bio,
        pp.providers_rating,
        pp.providers_age,
        pp.providers_gender,
        pp.providers_nationality,
        pp.providers_profile_image,
        pp.providers_current_country,
        pp.providers_current_governorate,
        pp.providers_current_area,
        pp.providers_current_address,
        -- ⭐ المستندات الحساسة (تظهر للإدارة فقط)
        pp.providers_id_card_front,
        pp.providers_id_card_back,
        pp.providers_criminal_record_image,
        pp.providers_utility_receipt_image,
        -- حالة المراجعة وبياناتها
        pp.providers_is_verified,
        pp.providers_review_note,
        pp.providers_reviewed_by,
        pp.providers_reviewed_at,

        -- ===== كل بيانات جدول users (ما عدا كلمة المرور) =====
        u.users_id,
        u.users_full_name,
        u.users_phone,
        u.users_country,
        u.users_region,
        u.users_is_active,
        u.users_approve,
        u.users_type,
        u.users_created_at,

        -- اسم ودور الموظف الذي راجع الطلب
        au.admin_full_name AS reviewed_by_name,
        au.admin_role      AS reviewed_by_role

     FROM professional_profiles pp
     INNER JOIN users u
        ON u.users_id = pp.providers_users_id
     LEFT JOIN admin_users au
        ON au.admin_id = pp.providers_reviewed_by
     WHERE pp.providers_users_id = ?
     LIMIT 1"
);
// تنفيذ الاستعلام بتمرير معرّف المستخدم
$stmt->execute([$users_id]);
// جلب صف البيانات كمصفوفة
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 4: التحقق من وجود الملف الشخصي
// ============================================================
// إذا لم يوجد سجل فمقدم الخدمة لم يكمل بياناته بعد
if (!$profile) {
    // رسالة توضح أن الملف غير موجود
    printFailure("Provider profile not found", 404);
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 5: جلب خدمات مقدم الخدمة المسجّلة (المهن والأسعار)
// تساعد خدمة العملاء على معرفة ماذا يقدّم هذا المزود
// ============================================================
$stmtServices = $con->prepare(
    "SELECT
        ps.ps_id,
        ps.ps_price,
        ps.ps_negotiable,
        ps.ps_commission_type,
        ps.ps_commission_value,
        c.categories_name_ar,
        sc.sub_name_ar,
        st.timing_name_ar
     FROM provider_services ps
     INNER JOIN categories c
        ON c.categories_id = ps.ps_cat_id
     INNER JOIN sub_categories sc
        ON sc.sub_id = ps.ps_sub_cat_id
     INNER JOIN service_timing st
        ON st.timing_id = ps.ps_timing_id
     WHERE ps.ps_user_id = ?
     ORDER BY ps.ps_id ASC"
);
// تنفيذ الاستعلام بتمرير معرّف المستخدم
$stmtServices->execute([$users_id]);
// جلب كل خدمات هذا المزود
$services = $stmtServices->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 6: بناء الروابط
// نرجع مستويين للوراء للوصول لجذر المشروع
// ============================================================
// استخراج مسار مجلد جذر المشروع من مسار السكربت الحالي
$scriptDir = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'])));
// تجميع الرابط الكامل مع البروتوكول واسم النطاق
$baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . ($scriptDir === '/' || $scriptDir === '\\' ? '' : $scriptDir);
// إزالة أي شرطة مائلة زائدة من نهاية الرابط
$baseUrl = rtrim($baseUrl, '/');

// ============================================================
// ⭐ الخطوة 7: بناء روابط المستندات عبر البوّابة الآمنة
//
// لماذا لا نُرجع الرابط المباشر مثل upload/id_cards/xxx.jpg ؟
//   لأن أي شخص يعرف اسم الملف يفتحه من المتصفح بدون أي تحقق
//   وهذه صور بطاقات شخصية وصحائف جنائية
//
// الحل: نُرجع رابط secure_image.php الذي يتحقق من صلاحية الإدارة
//       أولاً ثم يقرأ الملف ويُرسله، فلا يوجد رابط مباشر أبداً
// ============================================================
// دالة مساعدة تبني رابط البوّابة الآمنة لنوع مستند معيّن
$secureUrl = function (string $type, ?string $fileName) use ($baseUrl, $users_id) {
    // إذا لم يكن المستند مرفوعاً نُرجع null
    if (empty($fileName)) return null;
    // بناء رابط البوّابة مع نوع المستند ومعرّف المستخدم
    // ⚠️ لا نُمرّر اسم الملف في الرابط حتى لا يُستخدم للتخمين
    return $baseUrl . '/admin/providers/secure_image.php'
        . '?type=' . urlencode($type)
        . '&providers_users_id=' . urlencode($users_id);
};

// ============================================================
// الخطوة 8: ترجمة حالة المراجعة لنص واضح
// ============================================================
$verificationStatus = match((int)$profile['providers_is_verified']) {
    0 => 'pending',   // في انتظار المراجعة
    1 => 'verified',  // تمت الموافقة
    2 => 'rejected',  // مرفوض ويحتاج تعديل
    default => 'pending',
};

// ============================================================
// الخطوة 9: حساب مدة الانتظار بالساعات
// لتنبيه خدمة العملاء إذا تجاوز الطلب 24 ساعة
// ============================================================
// تحويل تاريخ التسجيل لطابع زمني
$createdTimestamp = strtotime($profile['users_created_at']);
// حساب الفرق بالساعات بين الآن ووقت التسجيل
$waitingHours = $createdTimestamp
    ? (int)floor((time() - $createdTimestamp) / 3600)
    : 0;

// ============================================================
// الخطوة 10: إرسال الرد الكامل
// ============================================================
echo json_encode([
    // حالة العملية
    "status"  => "success",
    // رسالة نجاح واضحة
    "message" => "Provider details fetched successfully",
    // بيانات المراجعة الكاملة
    "data"    => [

        // ===== 1. بيانات الحساب من جدول users =====
        "account" => [
            // معرّف المستخدم
            "users_id"         => (int)$profile['users_id'],
            // الاسم الكامل
            "users_full_name"  => $profile['users_full_name'],
            // ⚠️ رقم الهاتف للإدارة فقط
            "users_phone"      => $profile['users_phone'],
            // الدولة المسجّلة عند إنشاء الحساب
            "users_country"    => $profile['users_country'],
            // المنطقة المسجّلة عند إنشاء الحساب
            "users_region"     => $profile['users_region'],
            // نوع المستخدم (provider)
            "users_type"       => $profile['users_type'],
            // هل الحساب مفعّل؟
            "users_is_active"  => (int)$profile['users_is_active'],
            // هل وافقت الإدارة على الحساب؟
            "users_approve"    => (int)$profile['users_approve'],
            // تاريخ التسجيل
            "users_created_at" => $profile['users_created_at'],
            // عدد ساعات الانتظار حتى الآن
            "waiting_hours"    => $waitingHours,
            // هل تجاوز الطلب مهلة الـ 24 ساعة المعلنة؟
            "is_overdue"       => ($waitingHours > 24
                                   && (int)$profile['providers_is_verified'] === 0),
        ],

        // ===== 2. بيانات الملف المهني من جدول professional_profiles =====
        "profile" => [
            // معرّف سجل الملف المهني
            "providers_id"           => (int)$profile['providers_id'],
            // معرّف المستخدم المرتبط
            "providers_users_id"     => (int)$profile['providers_users_id'],
            // سنوات الخبرة
            "providers_experience"   => $profile['providers_experience'],
            // نبذة عن مقدم الخدمة
            "providers_bio"          => $profile['providers_bio'],
            // السن
            "providers_age"          => (int)$profile['providers_age'],
            // النوع (ذكر / أنثى)
            "providers_gender"       => $profile['providers_gender'],
            // الجنسية
            "providers_nationality"  => $profile['providers_nationality'],
            // التقييم
            "providers_rating"       => (float)$profile['providers_rating'],
            // الدولة الحالية
            "providers_current_country"     => $profile['providers_current_country'],
            // المحافظة الحالية
            "providers_current_governorate" => $profile['providers_current_governorate'],
            // المنطقة الحالية
            "providers_current_area"        => $profile['providers_current_area'],
            // العنوان التفصيلي
            "providers_current_address"     => $profile['providers_current_address'],
            // الصورة الشخصية (عامة ولا مشكلة في رابطها المباشر)
            "providers_profile_image" => $profile['providers_profile_image']
                ? $baseUrl . '/upload/provider_profiles/' . $profile['providers_profile_image']
                : null,
        ],

        // ===== 3. المستندات (روابط البوّابة الآمنة) =====
        "documents" => [
            // البطاقة الأمامية — إجبارية
            "id_card_front" => [
                // هل مرفوعة؟
                "exists" => !empty($profile['providers_id_card_front']),
                // رابط البوّابة الآمنة للعرض
                "url"    => $secureUrl('id_front', $profile['providers_id_card_front']),
            ],
            // البطاقة الخلفية — إجبارية
            "id_card_back" => [
                "exists" => !empty($profile['providers_id_card_back']),
                "url"    => $secureUrl('id_back', $profile['providers_id_card_back']),
            ],
            // الصحيفة الجنائية — اختيارية
            "criminal_record" => [
                "exists" => !empty($profile['providers_criminal_record_image']),
                "url"    => $secureUrl('criminal', $profile['providers_criminal_record_image']),
            ],
            // إيصال المرافق — اختياري
            "utility_receipt" => [
                "exists" => !empty($profile['providers_utility_receipt_image']),
                "url"    => $secureUrl('utility', $profile['providers_utility_receipt_image']),
            ],
            // هل المستندات الإجبارية مكتملة؟
            "is_complete" => !empty($profile['providers_id_card_front'])
                          && !empty($profile['providers_id_card_back']),
        ],

        // ===== 4. حالة المراجعة =====
        "review" => [
            // النص الواضح للحالة
            "verification_status"   => $verificationStatus,
            // الرقم الأصلي في قاعدة البيانات
            "providers_is_verified" => (int)$profile['providers_is_verified'],
            // سبب الرفض أو ملاحظة المراجع
            "review_note"           => $profile['providers_review_note'],
            // معرّف الموظف الذي راجع
            "reviewed_by"           => $profile['providers_reviewed_by']
                                        ? (int)$profile['providers_reviewed_by'] : null,
            // اسم الموظف الذي راجع
            "reviewed_by_name"      => $profile['reviewed_by_name'],
            // دور الموظف (manager / support)
            "reviewed_by_role"      => $profile['reviewed_by_role'],
            // تاريخ ووقت المراجعة
            "reviewed_at"           => $profile['providers_reviewed_at'],
        ],

        // ===== 5. الخدمات (المهن) المسجّلة لهذا المزود =====
        "services" => array_map(function ($s) {
            // تحويل كل خدمة لشكل مناسب للعرض
            return [
                // معرّف الخدمة
                "ps_id"            => (int)$s['ps_id'],
                // اسم القسم الرئيسي
                "category_name"    => $s['categories_name_ar'],
                // اسم المهنة (القسم الفرعي)
                "profession_name"  => $s['sub_name_ar'],
                // وقت الخدمة
                "timing_name"      => $s['timing_name_ar'],
                // السعر الذي حدده مقدم الخدمة
                "ps_price"         => (float)$s['ps_price'],
                // هل قابل للتفاوض؟
                "ps_negotiable"    => (bool)$s['ps_negotiable'],
                // نوع العمولة
                "commission_type"  => $s['ps_commission_type'],
                // قيمة العمولة
                "commission_value" => (float)$s['ps_commission_value'],
            ];
        }, $services),

        // عدد الخدمات المسجّلة
        "services_count" => count($services),
    ],
]);
?>