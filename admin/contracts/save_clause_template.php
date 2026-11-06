<?php
// ============================================================
// ملف: admin/contracts/save_clause_template.php
// الغرض: إضافة أو تعديل قالب بند من لوحة الإدارة
// الصلاحية المطلوبة: contracts.create
// الطريقة: POST
// يُستدعى من شاشة إدارة قوالب البنود.
// يُرجع template_id بعد الإدراج أو التحديث. لا يحذف قوالب.
// ============================================================

// اتصال قاعدة البيانات
include "../../connect.php";

// جلسة الموظف إلزامية
$currentAdmin = checkAdminToken();
// إنشاء القوالب بنفس صلاحية إنشاء العقود
requirePermission($currentAdmin, 'contracts.create');

// أكبر من صفر يعني تحديث قالب قائم
$templateId = (int) filterRequest("template_id");
// طرف القالب: شركة أو أفراد أو كلاهما
$partyType = trim(filterRequest("template_party_type"));
// عنوان القالب كما سيُنسخ إلى العقود
$title = trim(filterRequest("template_title_ar"));
// نص القالب الكامل
$body = trim(filterRequest("template_body_ar"));
// الإلزامي يُنسخ تلقائياً عند إنشاء العقد
$isRequired = (int) filterRequest("template_is_required") === 1 ? 1 : 0;
// ترتيب الظهور في القائمة والعقد
$sort = (int) filterRequest("template_sort");

// قيمة غير معروفة تُعامل كمشترك حتى يبقى القالب قابلاً للاستخدام
if (!in_array($partyType, ['company', 'individual', 'both'], true)) {
    $partyType = 'both';
}
// قالب بلا عنوان أو نص لا يفيد عند النسخ
if ($title === '' || $body === '') {
    printFailure("عنوان البند ونصّه مطلوبان");
}

// تحديث صف موجود دون تغيير حالة التفعيل
if ($templateId > 0) {
    $stmt = $con->prepare(
        "UPDATE contract_clause_templates
         SET template_party_type = ?, template_title_ar = ?, template_body_ar = ?,
             template_is_required = ?, template_sort = ?
         WHERE template_id = ?"
    );
    $stmt->execute([$partyType, $title, $body, $isRequired, $sort, $templateId]);
} else {
    // إدراج قالب جديد نشط بالافتراضي حسب مخطط الجدول
    $stmt = $con->prepare(
        "INSERT INTO contract_clause_templates (
            template_party_type, template_title_ar, template_body_ar,
            template_is_required, template_sort
         ) VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->execute([$partyType, $title, $body, $isRequired, $sort]);
    // إعادة المعرّف للواجهة لربط التعديلات التالية
    $templateId = (int) $con->lastInsertId();
}

// تأكيد الحفظ دون إرجاع النص كاملاً فالواجهة تحتفظ به محلياً
printSuccess([
    'template_id' => $templateId,
    'message' => 'تم حفظ قالب البند',
]);
