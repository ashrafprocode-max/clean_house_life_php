<?php
// ============================================================
// ملف: admin/users_history/search_users.php
// الغرض: بحث الإدارة عن أي مستخدم بالاسم أو رقم الهاتف
//        وإرجاع بطاقة فيها الصورة والاسم والهاتف وأنواع الحساب
//
// ⭐ أنواع الحساب تظهر معاً لأن الشخص قد يكون:
//    عميلاً + مقدم خدمة + صاحب شركة في نفس الوقت
//
// ⭐ البحث يبدأ بمجرد كتابة حرفين على الأقل
//    حتى لا نُفرغ جدول users بالكامل في الرد
//
// الحقول:
//   search  = الاسم أو رقم الهاتف (إجباري، حرفان على الأقل)
//   page    = رقم الصفحة (اختياري، افتراضي 1)
//   limit   = حجم الصفحة (اختياري، افتراضي 20، أقصى 50)
//
// الطريقة: POST أو GET
// الصلاحية المطلوبة: bookings.view
// الأدوار المسموحة: owner / manager / support
// ============================================================

// ربط ملف الاتصال بقاعدة البيانات
// نرجع مستويين لأن الملف داخل admin/users_history
include "../../connect.php";

// ============================================================
// الخطوة 1: التحقق من التوكن والصلاحية
//
// ⭐ bookings.view يملكها: owner / manager / support
//    والحماية الحقيقية هنا وليست في الواجهة فقط
// ============================================================
// التحقق من التوكن وجلب بيانات الموظف مع صلاحياته
$currentAdmin = checkAdminToken();

// فرض صلاحية عرض الحجوزات لأن السجل مرتبط بالمعاملات
requirePermission($currentAdmin, 'bookings.view');

// الأدوار المسموح لها بهذا القسم
$allowedRoles = ['owner', 'manager', 'support'];
// قراءة مفتاح الدور من جلسة الموظف
$roleKey = $currentAdmin['role_key'] ?? ($currentAdmin['admin_role'] ?? '');
// إن لم يكن الدور ضمن القائمة نرفض الطلب
if (!in_array($roleKey, $allowedRoles, true)) {
    // رسالة عربية واضحة
    printFailure("ليس لديك صلاحية تنفيذ هذا الإجراء", 403);
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 2: استقبال نص البحث ومعاملات الصفحات
// ============================================================
// نص البحث كما كتبه الموظف بعد إزالة المسافات الطرفية
$search = trim(filterRequest("search"));
// رقم الصفحة الحالية ولا يقل عن 1
$page = max(1, (int)(filterRequest("page") ?: 1));
// حجم الصفحة بين 1 و 50 والافتراضي 20
$limit = min(50, max(1, (int)(filterRequest("limit") ?: 20)));
// نقطة البداية في قاعدة البيانات
$offset = ($page - 1) * $limit;

// ============================================================
// الخطوة 3: التحقق من طول نص البحث
//
// ⭐ حرفان على الأقل يمنعان تفريغ كل المستخدمين
// ============================================================
// إذا كان النص أقصر من حرفين نرفض الطلب
if (mb_strlen($search) < 2) {
    // رسالة توضح الحد الأدنى
    printFailure("أدخل حرفين على الأقل للبحث بالاسم أو رقم الهاتف");
    // إيقاف التنفيذ
    exit;
}

// ============================================================
// الخطوة 4: تجهيز نص البحث للـ LIKE
//
// ⭐ نبحث في الاسم والهاتف معاً
//    ونضيف نسخة من الهاتف بدون رموز لالتقاط 010 و +20
// ============================================================
// نص البحث مع % للبحث الجزئي في الاسم والهاتف
$searchTerm = '%' . $search . '%';
// نسخة أرقام فقط من نص البحث (إن كتب الموظف هاتفاً)
$searchDigits = preg_replace('/[^0-9]/', '', $search);
// إن وُجدت أرقام نجهّز LIKE لها وإلا نُعيد استخدام النص الأصلي
$phoneTerm = $searchDigits !== '' ? '%' . $searchDigits . '%' : $searchTerm;

// ============================================================
// الخطوة 5: شروط WHERE المشتركة
// ============================================================
// شرط البحث في الاسم أو الهاتف
$whereSQL = "WHERE (u.users_full_name LIKE ? OR u.users_phone LIKE ?)";
// معاملات الاستعلام: الاسم ثم الهاتف
$params = [$searchTerm, $phoneTerm];

// ============================================================
// الخطوة 6: استعلام العدد الإجمالي لنتائج البحث
// ============================================================
// تجهيز جملة العد
$countSQL = "
    SELECT COUNT(u.users_id) AS total
    FROM users u
    $whereSQL
";
// تجهيز الاستعلام
$countStmt = $con->prepare($countSQL);
// تنفيذه بمعاملات البحث
$countStmt->execute($params);
// قراءة العدد
$totalCount = (int)$countStmt->fetchColumn();
// حساب عدد الصفحات مع رفع الكسر
$totalPages = $limit > 0 ? (int)ceil($totalCount / $limit) : 0;

// ============================================================
// الخطوة 7: الاستعلام الرئيسي
//
// ⭐ LEFT JOIN للملف الشخصي للعميل وصورة مقدم الخدمة والشركة
//    حتى نعرف الأدوار الثلاثة دفعة واحدة
// ============================================================
$sql = "
    SELECT
        -- ===== بيانات الحساب =====
        u.users_id,
        u.users_full_name,
        u.users_phone,
        u.users_type,
        u.users_is_active,
        u.users_approve,
        u.users_created_at,

        -- ===== ملف العميل (إن وُجد) =====
        cp.clients_id,
        cp.clients_profile_image,

        -- ===== الملف المهني (إن وُجد) =====
        pp.providers_id,
        pp.providers_profile_image,
        pp.providers_rating,

        -- ===== الشركة إن كان صاحب نشاط =====
        cr.company_id,
        cr.company_name
    FROM users u
    LEFT JOIN client_profiles cp
        ON cp.clients_users_id = u.users_id
    LEFT JOIN professional_profiles pp
        ON pp.providers_users_id = u.users_id
    LEFT JOIN company_registering cr
        ON cr.company_user_id = u.users_id
    $whereSQL
    ORDER BY u.users_full_name ASC
    LIMIT $limit OFFSET $offset
";
// تجهيز الاستعلام الرئيسي
$stmt = $con->prepare($sql);
// تنفيذه بمعاملات البحث نفسها
$stmt->execute($params);
// جلب كل الصفوف
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 8: بناء رابط الصور وواتساب
// ============================================================
// استخراج مسار جذر المشروع بالرجوع مستويين من admin/users_history
$scriptDir = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'])));
// تجميع الرابط الكامل
$baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . ($scriptDir === '/' || $scriptDir === '\\' ? '' : $scriptDir);
// إزالة الشرطة المائلة الزائدة
$baseUrl = rtrim($baseUrl, '/');

// دالة تبني رابط واتساب من رقم الهاتف
$whatsappUrl = function (?string $phone): ?string {
    // إن لم يوجد رقم نُرجع null
    if ($phone === null || $phone === '') {
        return null;
    }
    // الإبقاء على الأرقام فقط
    $digits = preg_replace('/[^0-9]/', '', $phone);
    // تحويل 010xxxxxxxx إلى 2010xxxxxxxx
    if (strlen($digits) === 11 && strpos($digits, '0') === 0) {
        $digits = '20' . substr($digits, 1);
    } elseif (strlen($digits) === 10) {
        $digits = '20' . $digits;
    }
    // إن لم يتبقَّ أرقام نُرجع null
    if ($digits === '') {
        return null;
    }
    // رابط واتساب جاهز للفتح من اللوحة
    return 'https://wa.me/' . $digits;
};

// دالة تنظّف اسم ملف الصورة من الأسطر والمسافات
$cleanFile = function (?string $name): string {
    // إن كانت القيمة فارغة نُرجع نصاً فارغاً
    if ($name === null) {
        return '';
    }
    // إزالة الأسطر والمسافات الطرفية
    return trim(str_replace(["\n", "\r"], '', $name));
};

// ============================================================
// الخطوة 9: تحويل كل صف لبطاقة مستخدم
// ============================================================
$resultData = array_map(function ($row) use ($baseUrl, $whatsappUrl, $cleanFile) {
    // تنظيف اسم صورة العميل
    $clientImage = $cleanFile($row['clients_profile_image'] ?? null);
    // تنظيف اسم صورة مقدم الخدمة
    $providerImage = $cleanFile($row['providers_profile_image'] ?? null);

    // رابط صورة العميل إن وُجدت
    $clientImageUrl = $clientImage !== ''
        ? $baseUrl . '/upload/client_profiles/' . $clientImage
        : null;
    // رابط صورة المزود إن وُجدت
    $providerImageUrl = $providerImage !== ''
        ? $baseUrl . '/upload/provider_profiles/' . $providerImage
        : null;
    // الصورة المعروضة: نفضّل صورة العميل ثم صورة المزود
    $profileImage = $clientImageUrl ?? $providerImageUrl;

    // هل لديه ملف عميل؟
    $hasClientProfile = $row['clients_id'] !== null;
    // هل لديه ملف مهني؟
    $hasProviderProfile = $row['providers_id'] !== null;
    // هل لديه شركة مسجّلة؟
    $hasCompany = $row['company_id'] !== null;
    // نوع الحساب الأساسي من جدول users
    $usersType = $row['users_type'] ?? '';

    // عميل: النوع client أو لديه ملف عميل
    $isClient = ($usersType === 'client') || $hasClientProfile;
    // مقدم خدمة: النوع provider أو لديه ملف مهني
    $isProvider = ($usersType === 'provider') || $hasProviderProfile;
    // صاحب شركة: صف في company_registering
    $isCompanyOwner = $hasCompany;

    // تجميع تسميات الأدوار العربية للعرض في البطاقة
    $rolesAr = [];
    // إضافة تسمية العميل إن انطبق
    if ($isClient) {
        $rolesAr[] = 'عميل';
    }
    // إضافة تسمية مقدم الخدمة إن انطبق
    if ($isProvider) {
        $rolesAr[] = 'مقدم خدمة';
    }
    // إضافة تسمية صاحب الشركة إن انطبق
    if ($isCompanyOwner) {
        $rolesAr[] = 'صاحب شركة';
    }
    // إن لم يُكتشف أي دور نعرض النوع الخام
    if (empty($rolesAr)) {
        $rolesAr[] = $usersType === 'provider' ? 'مقدم خدمة' : 'عميل';
    }

    // إرجاع بطاقة المستخدم
    return [
        // معرّف المستخدم لفتح سجل معاملاته
        "users_id"        => (int)$row['users_id'],
        // الاسم الكامل
        "users_full_name" => $row['users_full_name'],
        // رقم الهاتف للعرض وللواتساب
        "users_phone"     => $row['users_phone'],
        // النوع المخزَّن في جدول users
        "users_type"      => $usersType,
        // هل الحساب مفعّل؟
        "users_is_active" => (int)$row['users_is_active'],
        // هل تحقّق من رقمه؟
        "users_approve"   => (int)$row['users_approve'],
        // تاريخ إنشاء الحساب
        "users_created_at"=> $row['users_created_at'],
        // رابط الصورة المعروضة في البطاقة
        "profile_image"   => $profileImage,
        // رابط واتساب جاهز
        "whatsapp_url"    => $whatsappUrl($row['users_phone']),
        // ===== الأدوار المتعددة =====
        "is_client"         => $isClient,
        "is_provider"       => $isProvider,
        "is_company_owner"  => $isCompanyOwner,
        // تسميات عربية جاهزة للعرض مفصولة بـ /
        "roles_label_ar"    => implode(' / ', $rolesAr),
        // مصفوفة التسميات إن أرادت الواجهة شارات منفصلة
        "roles_ar"          => $rolesAr,
        // اسم الشركة إن وُجد
        "company_name"      => $row['company_name'],
        // تقييم المزود إن وُجد
        "providers_rating"  => $row['providers_rating'] !== null
            ? (float)$row['providers_rating']
            : null,
    ];
}, $rows);

// ============================================================
// الخطوة 10: إرسال الرد
// ============================================================
echo json_encode([
    // حالة العملية
    "status"  => "success",
    // رسالة عربية
    "message" => $totalCount > 0
        ? "تم العثور على $totalCount مستخدم"
        : "لا يوجد مستخدم مطابق للبحث",
    // قائمة البطاقات
    "data"    => $resultData,
    // بيانات التصفح
    "pagination" => [
        "current_page"  => $page,
        "per_page"      => $limit,
        "total_count"   => $totalCount,
        "total_pages"   => $totalPages,
        "has_next_page" => ($page < $totalPages),
        "has_prev_page" => ($page > 1),
    ],
]);
