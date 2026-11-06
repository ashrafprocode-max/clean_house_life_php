<?php
// ============================================================
// ملف: admin/contracts/add_contract_clause.php
// الغرض: إضافة بند مخصص أو من قالب إلى عقد مسودة
// الصلاحية المطلوبة: contracts.edit
// الطريقة: POST
// يُستدعى من شاشة تفاصيل العقد قبل التوقيع.
// يُرجع البند الجديد مع ترتيبه. لا يغيّر حالة العقد.
// ============================================================

// اتصال قاعدة البيانات
include "../../connect.php";
// المساعد غير مستخدم في الحساب هنا ويُبقى لتناسق مسارات العقود
include "../../contracts/includes/contract_helper.php";

// جلسة الموظف إلزامية
$currentAdmin = checkAdminToken();
// تعديل البنود يحتاج contracts.edit
requirePermission($currentAdmin, 'contracts.edit');

// العقد الذي يُضاف إليه البند
$contractId = (int) filterRequest("contract_id");
// عنوان البند كما سيظهر في الطباعة والتطبيق
$title = trim(filterRequest("clause_title_ar"));
// نص البند الكامل
$body = trim(filterRequest("clause_body_ar"));
// قالب اختياري لتعبئة العنوان والنص إن تُركا فارغين
$templateId = (int) filterRequest("clause_template_id");

// بلا عقد لا يُدرج بند يتيم
if ($contractId <= 0) {
    printFailure("contract_id is required");
}

// الحالة تمنع تعديل عقد موقّع أو ساري
$stmt = $con->prepare(
    "SELECT contract_id, contract_status FROM contracts WHERE contract_id = ? LIMIT 1"
);
$stmt->execute([$contractId]);
$contract = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$contract) {
    printFailure("العقد غير موجود", 404);
}

// المسودة وانتظار التوقيع فقط؛ بعد التوقيع البنود جزء من الاتفاق
if (!in_array($contract['contract_status'], ['draft', 'pending_signature'], true)) {
    printFailure("لا يمكن تعديل بنود عقد موقّع أو ساري", 409);
}

// افتراض بند مخصص ما لم يُملأ من قالب نشط
$isCustom = 1;
// إن وُجد قالب ونقص العنوان أو النص ننسخ من القالب
if ($templateId > 0 && ($title === '' || $body === '')) {
    $tpl = $con->prepare(
        "SELECT template_title_ar, template_body_ar
         FROM contract_clause_templates
         WHERE template_id = ? AND template_is_active = 1
         LIMIT 1"
    );
    $tpl->execute([$templateId]);
    $row = $tpl->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        printFailure("قالب البند غير موجود", 404);
    }
    // نسخ النص كما هو حتى يبقى مصدر القالب واضحاً
    $title = $row['template_title_ar'];
    $body = $row['template_body_ar'];
    // ليس مخصصاً لأنه منسوخ من قالب
    $isCustom = 0;
}

// بعد النسخ أو الإدخال اليدوي يجب اكتمال الحقلين
if ($title === '' || $body === '') {
    printFailure("عنوان البند ونصّه مطلوبان");
}

// الترتيب التالي بعد آخر بند حتى يظهر في النهاية
$sortStmt = $con->prepare(
    "SELECT COALESCE(MAX(clause_sort), 0) + 1
     FROM contract_clauses WHERE clause_contract_id = ?"
);
$sortStmt->execute([$contractId]);
$sort = (int) $sortStmt->fetchColumn();

// إدراج البند مع ربط القالب إن وُجد
$insert = $con->prepare(
    "INSERT INTO contract_clauses (
        clause_contract_id, clause_template_id, clause_title_ar,
        clause_body_ar, clause_sort, clause_is_custom
     ) VALUES (?, ?, ?, ?, ?, ?)"
);
$insert->execute([
    $contractId,
    $templateId > 0 ? $templateId : null,
    $title,
    $body,
    $sort,
    $isCustom,
]);

// الاستجابة لتحديث قائمة البنود في الشاشة دون إعادة تحميل العقد كاملاً
printSuccess([
    'clause_id' => (int) $con->lastInsertId(),
    'title_ar'  => $title,
    'body_ar'   => $body,
    'sort'      => $sort,
    'is_custom' => $isCustom === 1,
    'message'   => 'تمت إضافة البند',
]);
