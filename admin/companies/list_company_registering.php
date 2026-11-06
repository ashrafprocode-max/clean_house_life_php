<?php
// ============================================================
// ملف: admin/companies/list_company_registering.php
// الغرض: عرض قائمة كل الشركات المسجّلة للإدارة
//        مع بحث متعدد الحقول + فلترة + Pagination + إحصائيات
//
// ⭐ البحث الموحّد (search) يبحث في أربعة حقول معاً:
//   1. اسم العميل صاحب الشركة  → users.users_full_name
//   2. اسم الشركة              → company_registering.company_name
//   3. رقم هاتف الشركة         → company_phone_number + secondary
//   4. رقم هاتف العميل         → users.users_phone
//
// ⭐ فلاتر إضافية:
//   business_type    → company / factory / commercial_activity / all
//   has_documents    → yes (لديه مستندات) / no (بلا مستندات) / all
//   request_status   → pending / approved / rejected (حالة طلباته)
//
// أمثلة الاستخدام:
//   search=جهينة&page=1&limit=20
//   business_type=factory&request_status=pending
//
// الطريقة: POST أو GET
// ============================================================

// تضمين ملف الاتصال (مستويان للوراء لأن الملف داخل admin/companies)
include "../../connect.php";

// ⭐ التحقق من توكن الإدارة — أي موظف مسجّل دخوله يستطيع القراءة
$currentAdmin = checkAdminToken();

// الحماية الحقيقية على السيرفر وليست في واجهة التطبيق فقط
requirePermission($currentAdmin, 'companies.view');

// ============================================================
// الخطوة 1: استقبال معاملات البحث والفلترة
// ============================================================
// نص البحث الموحّد في الاسم أو الشركة أو الهواتف
$search = trim(filterRequest("search"));
// فلتر نوع النشاط
$businessType = filterRequest("business_type");
// فلتر وجود المستندات
$hasDocuments = filterRequest("has_documents");
// فلتر حالة طلبات الشركة
$requestStatus = filterRequest("request_status");

// ============================================================
// الخطوة 2: معاملات Pagination
// max() و min() لضمان قيم منطقية دائماً
// ============================================================
// رقم الصفحة الحالية - لا تقل عن 1
$page = max(1, (int) (filterRequest("page") ?: 1));
// عدد النتائج - لا تقل عن 1 ولا تزيد عن 50
$limit = min(50, max(1, (int) (filterRequest("limit") ?: 20)));
// نقطة البداية في قاعدة البيانات
// مثال: page=2, limit=20 → offset=20 (ابدأ من السجل 21)
$offset = ($page - 1) * $limit;

// ============================================================
// الخطوة 3: بناء شروط WHERE المشتركة بين الاستعلامين
// ============================================================
// نبدأ بشرط ثابت دائماً صحيح لتبسيط دمج الشروط بعده
$whereConditions = ["1 = 1"];
// مصفوفة معاملات الاستعلام الرئيسي
$params = [];
// مصفوفة معاملات استعلام العدد الإجمالي
$countParams = [];

// ============================================================
// الخطوة 4: ⭐ البحث الموحّد في خمسة حقول معاً
//
// ⚠️ الأقواس حول شروط OR إجبارية
//    وإلا تعارض OR مع شروط AND الأخرى وأعطى نتائج خاطئة
// ============================================================
// نُضيف الشرط فقط إذا أرسلت الواجهة نصاً للبحث
if ($search !== '') {
    // بناء شرط البحث في خمسة حقول بـ OR
    $whereConditions[] = "("
        // اسم العميل صاحب الشركة من جدول users
        . " u.users_full_name LIKE ?"
        // رقم هاتف العميل من جدول users
        . " OR u.users_phone LIKE ?"
        // اسم الشركة
        . " OR c.company_name LIKE ?"
        // رقم هاتف الشركة الأساسي
        . " OR c.company_phone_number LIKE ?"
        // رقم هاتف الشركة الإضافي
        . " OR c.company_secondary_phone LIKE ?"
        . ")";

    // تجهيز نص البحث مع % قبله وبعده للبحث الجزئي
    $searchTerm = "%{$search}%";

    // ⭐ نُمرّر النص خمس مرات (مرة لكل حقل بالترتيب)
    for ($i = 0; $i < 5; $i++) {
        // تمرير للاستعلام الرئيسي
        $params[] = $searchTerm;
        // وتمرير لاستعلام العدد الإجمالي
        $countParams[] = $searchTerm;
    }
}

// ============================================================
// الخطوة 5: فلتر نوع النشاط
// ============================================================
// قائمة أنواع النشاط المسموحة (نفس قيم الـ ENUM حرفياً)
$allowedBusinessTypes = ['company', 'factory', 'commercial_activity'];

// نُضيف الشرط فقط إذا كان النوع معروفاً (all تعني بلا فلترة)
if ($businessType !== '' && in_array($businessType, $allowedBusinessTypes, true)) {
    // إضافة شرط نوع النشاط
    $whereConditions[] = "c.company_business_type = ?";
    // تمرير القيمة للاستعلامين
    $params[] = $businessType;
    $countParams[] = $businessType;
}

// ============================================================
// الخطوة 6: فلتر وجود المستندات
//
// ⭐ مفيد للموظف الذي يريد متابعة الشركات الناقصة مستنداتها
// ============================================================
// إذا طُلبت الشركات التي رفعت مستنداً واحداً على الأقل
if ($hasDocuments === 'yes') {
    // شرط وجود أحد الملفين على الأقل
    $whereConditions[] = "("
        . " (c.company_commercial_register_pdf IS NOT NULL AND c.company_commercial_register_pdf <> '')"
        . " OR (c.company_tax_card_pdf IS NOT NULL AND c.company_tax_card_pdf <> '')"
        . ")";
}
// أو طُلبت الشركات التي لم ترفع أي مستند
elseif ($hasDocuments === 'no') {
    // شرط خلو الملفين معاً
    $whereConditions[] = "("
        . " (c.company_commercial_register_pdf IS NULL OR c.company_commercial_register_pdf = '')"
        . " AND (c.company_tax_card_pdf IS NULL OR c.company_tax_card_pdf = '')"
        . ")";
}

// ============================================================
// الخطوة 7: فلتر حالة طلبات الشركة
//
// ⭐ نستخدم EXISTS لا JOIN حتى لا تتكرر الشركة
//    لو كان لها عدة طلبات بنفس الحالة
// ============================================================
// قائمة حالات الطلبات المسموحة (نفس قيم الـ ENUM حرفياً)
$allowedStatus = ['pending', 'approved', 'rejected'];

// نُضيف الشرط فقط إذا كانت الحالة معروفة
if ($requestStatus !== '' && in_array($requestStatus, $allowedStatus, true)) {
    // شرط وجود طلب واحد على الأقل بهذه الحالة
    $whereConditions[] = "EXISTS ("
        . " SELECT 1 FROM job_details_required jr"
        . " WHERE jr.required_company_id = c.company_id"
        . " AND jr.required_approval_status = ?"
        . ")";
    // تمرير الحالة للاستعلامين
    $params[] = $requestStatus;
    $countParams[] = $requestStatus;
}

// دمج كل الشروط في جملة WHERE واحدة مفصولة بـ AND
$whereSQL = "WHERE " . implode(" AND ", $whereConditions);

// ============================================================
// الخطوة 8: استعلام العدد الإجمالي
// مهم لحساب عدد الصفحات وعرض "صفحة 1 من 5" في الواجهة
// ============================================================
$countSQL = "
    SELECT COUNT(c.company_id) AS total
    FROM company_registering c
    INNER JOIN users u ON u.users_id = c.company_user_id
    $whereSQL
";
// تجهيز الاستعلام
$countStmt = $con->prepare($countSQL);
// تنفيذه بمعاملات العدد
$countStmt->execute($countParams);
// قراءة الرقم الناتج
$totalCount = (int) $countStmt->fetchColumn();

// حساب إجمالي الصفحات (نرفع الكسر دائماً)
// مثال: 45 نتيجة ÷ 20 لكل صفحة = 3 صفحات (2.25 → 3)
$totalPages = (int) ceil($totalCount / $limit);

// ============================================================
// الخطوة 9: استعلام الإحصائيات العامة
//
// ⚠️ الإحصائيات على كل الشركات بلا فلترة
//    لأنها لشارات أزرار الفلترة نفسها
// ============================================================
$statsSQL = "
    SELECT
        -- عدد الشركات
        SUM(CASE WHEN company_business_type = 'company' THEN 1 ELSE 0 END) AS company_count,
        -- عدد المصانع
        SUM(CASE WHEN company_business_type = 'factory' THEN 1 ELSE 0 END) AS factory_count,
        -- عدد الأنشطة التجارية
        SUM(CASE WHEN company_business_type = 'commercial_activity' THEN 1 ELSE 0 END) AS activity_count,
        -- الإجمالي الكلي
        COUNT(company_id) AS all_count
    FROM company_registering
";
// تنفيذ استعلام الإحصائيات بلا معاملات
$statsStmt = $con->prepare($statsSQL);
$statsStmt->execute();
// قراءة صف الإحصائيات
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 10: ⭐ استعلام عدد الطلبات المعلّقة إجمالاً
// لعرضه كتنبيه في أعلى شاشة قائمة الشركات
// ============================================================
$pendingSQL = "
    SELECT COUNT(required_id) AS pending_total
    FROM job_details_required
    WHERE required_approval_status = 'pending'
";
// تنفيذ الاستعلام
$pendingStmt = $con->prepare($pendingSQL);
$pendingStmt->execute();
// قراءة عدد الطلبات المعلّقة
$pendingTotal = (int) $pendingStmt->fetchColumn();

// ============================================================
// الخطوة 11: الاستعلام الرئيسي مع LIMIT و OFFSET
//
// ⚠️ لا نُرجع أسماء ملفات المستندات هنا إطلاقاً
//    نُرجع فقط هل هي موجودة أم لا (لعرض علامة ✓ في القائمة)
//
// ⭐ نستخدم استعلامات فرعية لعدّ الطلبات
//    لأن JOIN + GROUP BY يُعقّد الاستعلام ويُبطئه هنا
// ============================================================
$sql = "
    SELECT
        c.company_id,
        c.company_user_id,
        c.company_business_type,
        c.company_name,
        c.company_headquarters_address,
        c.company_phone_number,
        c.company_secondary_phone,
        c.company_created_at,
        c.company_updated_at,

        -- ⚠️ لا نُرجع أسماء الملفات للأمان، بل وجودها فقط
        CASE WHEN c.company_commercial_register_pdf IS NOT NULL
             AND c.company_commercial_register_pdf <> ''
             THEN 1 ELSE 0 END AS has_commercial_register,
        CASE WHEN c.company_tax_card_pdf IS NOT NULL
             AND c.company_tax_card_pdf <> ''
             THEN 1 ELSE 0 END AS has_tax_card,

        -- بيانات صاحب الحساب من جدول users
        u.users_full_name,
        u.users_phone,
        u.users_country,
        u.users_region,
        u.users_is_active,
        u.users_created_at,

        -- ⭐ عدد طلبات العمالة الإجمالي لهذه الشركة
        (SELECT COUNT(*) FROM job_details_required jr
          WHERE jr.required_company_id = c.company_id) AS total_requests,

        -- ⭐ عدد الطلبات المعلّقة لهذه الشركة
        (SELECT COUNT(*) FROM job_details_required jr
          WHERE jr.required_company_id = c.company_id
            AND jr.required_approval_status = 'pending') AS pending_requests,

        -- ⭐ عدد الطلبات المقبولة لهذه الشركة
        (SELECT COUNT(*) FROM job_details_required jr
          WHERE jr.required_company_id = c.company_id
            AND jr.required_approval_status = 'approved') AS approved_requests,

        -- ⭐ إجمالي العمال المطلوبين في الطلبات المقبولة
        (SELECT COALESCE(SUM(jr.required_workers_count), 0)
           FROM job_details_required jr
          WHERE jr.required_company_id = c.company_id
            AND jr.required_approval_status = 'approved') AS approved_workers_total,

                -- ⭐ تاريخ أقدم طلب معلَّق (للعرض في الواجهة)
        (SELECT MIN(jr.required_created_at) FROM job_details_required jr
          WHERE jr.required_company_id = c.company_id
            AND jr.required_approval_status = 'pending') AS oldest_pending_at,

        -- ⭐⭐ عدد ساعات انتظار أقدم طلب معلَّق — محسوبة داخل MySQL
        --
        -- ⚠️ لماذا نحسبها هنا لا في PHP؟
        --   لأن MySQL تكتب التواريخ بتوقيت نظام السيرفر (Africa/Cairo)
        --   بينما PHP يقرأ توقيته من php.ini (Europe/Berlin على جهازك)
        --   فحساب الفرق بـ time() في PHP أعطى ساعات سالبة (-3)
        --   و TIMESTAMPDIFF يقارن قيمتين من نفس المصدر فلا فرق زمني
        (SELECT TIMESTAMPDIFF(HOUR, MIN(jr.required_created_at), NOW())
           FROM job_details_required jr
          WHERE jr.required_company_id = c.company_id
            AND jr.required_approval_status = 'pending') AS pending_waiting_hours

    FROM company_registering c
    INNER JOIN users u ON u.users_id = c.company_user_id
    $whereSQL
    ORDER BY
        -- ⭐ الشركات التي لديها طلبات معلّقة أولاً لأنها الأهم للموظف
        CASE WHEN (SELECT COUNT(*) FROM job_details_required jr
                    WHERE jr.required_company_id = c.company_id
                      AND jr.required_approval_status = 'pending') > 0
             THEN 0 ELSE 1 END ASC,
        -- ثم الأحدث تسجيلاً
        c.company_created_at DESC
    LIMIT $limit OFFSET $offset
";
// تجهيز الاستعلام الرئيسي
$stmt = $con->prepare($sql);
// تنفيذه بمعاملات البيانات
$stmt->execute($params);
// جلب كل الصفوف كمصفوفة
$companies = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// الخطوة 12: تحويل كل سجل لشكل مناسب للواجهة
// ============================================================
$resultData = array_map(function ($c) {
    // ⭐ مدة انتظار أقدم طلب معلَّق بالساعات — جاءت محسوبة من MySQL
    //
    // ⚠️ لا نحسبها بـ strtotime/time في PHP إطلاقاً
    //    لأن اختلاف توقيت PHP عن توقيت MySQL يُنتج ساعات سالبة
    //
    // max(0, ...) حماية إضافية لو أُدخل صف بتاريخ مستقبلي يدوياً
    $waitingHours = max(0, (int) ($c['pending_waiting_hours'] ?? 0));

    // إرجاع مصفوفة السجل بالشكل الذي تقرأه الواجهة
    return [
        // معرّف الشركة (نحتاجه لفتح صفحة التفاصيل)
        "company_id" => (int) $c['company_id'],
        // معرّف المستخدم صاحب النشاط
        "company_user_id" => (int) $c['company_user_id'],

        // ===== بيانات صاحب الحساب =====
        // اسم العميل صاحب الشركة
        "users_full_name" => $c['users_full_name'],
        // ⚠️ رقم هاتف العميل يظهر للإدارة فقط
        "users_phone" => $c['users_phone'],
        // الدولة المسجّلة
        "users_country" => $c['users_country'],
        // المنطقة المسجّلة
        "users_region" => $c['users_region'],
        // هل الحساب مفعّل؟
        "users_is_active" => (int) $c['users_is_active'],
        // تاريخ إنشاء الحساب
        "users_created_at" => $c['users_created_at'],

        // ===== بيانات النشاط =====
        // نوع النشاط كما هو مخزَّن
        "company_business_type" => $c['company_business_type'],
        // ⭐ ترجمة نوع النشاط للعربية
        "business_type_ar" => match ($c['company_business_type']) {
            'company' => 'شركة',
            'factory' => 'مصنع',
            'commercial_activity' => 'نشاط تجاري',
            default => 'غير محدد',
        },
        // اسم النشاط
        "company_name" => $c['company_name'],
        // عنوان المقر
        "company_headquarters_address" => $c['company_headquarters_address'],
        // رقم هاتف الشركة الأساسي
        "company_phone_number" => $c['company_phone_number'],
        // رقم هاتف الشركة الإضافي
        "company_secondary_phone" => $c['company_secondary_phone'],
        // تاريخ التسجيل
        "company_created_at" => $c['company_created_at'],
        // تاريخ آخر تحديث
        "company_updated_at" => $c['company_updated_at'],

        // ===== المستندات (بدون كشف أسماء الملفات) =====
        "documents" => [
            // هل رفع السجل التجاري؟
            "has_commercial_register" => (bool) $c['has_commercial_register'],
            // هل رفع البطاقة الضريبية؟
            "has_tax_card" => (bool) $c['has_tax_card'],
            // هل رفع المستندين معاً؟
            "is_complete" => (bool) $c['has_commercial_register']
                && (bool) $c['has_tax_card'],
        ],

        // ===== إحصائيات طلبات هذه الشركة =====
        "requests" => [
            // إجمالي عدد الطلبات
            "total" => (int) $c['total_requests'],
            // عدد الطلبات المعلّقة
            "pending" => (int) $c['pending_requests'],
            // عدد الطلبات المقبولة
            "approved" => (int) $c['approved_requests'],
            // ⭐ إجمالي العمال المطلوبين في الطلبات المقبولة
            "approved_workers" => (int) $c['approved_workers_total'],
            // عدد ساعات انتظار أقدم طلب معلَّق
            "waiting_hours" => $waitingHours,
            // ⭐ هل تجاوز الطلب المهلة المعلنة (24 ساعة)؟
            "is_overdue" => $waitingHours > 24,
        ],
    ];
}, $companies);

// ============================================================
// الخطوة 13: إرسال الرد مع الإحصائيات وبيانات التصفح
// ============================================================
echo json_encode([
    // حالة العملية
    "status" => "success",
    // رسالة نجاح
    "message" => "تم جلب قائمة الشركات بنجاح",
    // مصفوفة الشركات
    "data" => $resultData,

    // ===== إحصائيات لشارات أزرار الفلترة =====
    "stats" => [
        // عدد الشركات
        "company_count" => (int) ($stats['company_count'] ?? 0),
        // عدد المصانع
        "factory_count" => (int) ($stats['factory_count'] ?? 0),
        // عدد الأنشطة التجارية
        "activity_count" => (int) ($stats['activity_count'] ?? 0),
        // الإجمالي الكلي
        "all_count" => (int) ($stats['all_count'] ?? 0),
        // ⭐ إجمالي طلبات العمالة المعلّقة في النظام كله
        "pending_requests_total" => $pendingTotal,
    ],

    // ===== بيانات التصفح =====
    "pagination" => [
        // الصفحة الحالية
        "current_page" => $page,
        // عدد النتائج في الصفحة
        "per_page" => $limit,
        // إجمالي النتائج المطابقة للفلترة
        "total_count" => $totalCount,
        // إجمالي الصفحات
        "total_pages" => $totalPages,
        // Flutter يتوقف عن التحميل عند false
        "has_next_page" => ($page < $totalPages),
        "has_prev_page" => ($page > 1),
    ],

    // ===== الفلاتر المُطبَّقة (لعرضها في الواجهة) =====
    "applied_filters" => [
        // نص البحث المُستخدم
        "search" => $search !== '' ? $search : null,
        // فلتر نوع النشاط
        "business_type" => $businessType !== '' ? $businessType : 'all',
        // فلتر المستندات
        "has_documents" => $hasDocuments !== '' ? $hasDocuments : 'all',
        // فلتر حالة الطلبات
        "request_status" => $requestStatus !== '' ? $requestStatus : 'all',
    ],
]);
?>