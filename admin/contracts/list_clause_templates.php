<?php
// ============================================================
// ملف: admin/contracts/list_clause_templates.php
// الغرض: قائمة قوالب بنود العقود لإدراجها في عقد جديد
// الصلاحية المطلوبة: contracts.view
// الطريقة: POST
// يُستدعى من نموذج إنشاء/تعديل العقد لاختيار البنود.
// يُرجع القوالب النشطة فقط مرتبة. لا يكتب في القاعدة.
// ============================================================

// اتصال قاعدة البيانات
include "../../connect.php";

// جلسة الموظف إلزامية
$currentAdmin = checkAdminToken();
// العرض يحتاج contracts.view
requirePermission($currentAdmin, 'contracts.view');

// فلتر اختياري حسب طرف العقد
$partyType = trim(filterRequest("party_type"));

// النشطة فقط حتى لا تظهر قوالب موقوفة في الاختيار
$where = "template_is_active = 1";
$params = [];
// الشركة أو الأفراد مع قوالب both المشتركة
if ($partyType === 'company' || $partyType === 'individual') {
    $where .= " AND (template_party_type = ? OR template_party_type = 'both')";
    $params[] = $partyType;
}

// الحقول اللازمة للعرض والإدراج دون بيانات إدارية زائدة
$stmt = $con->prepare(
    "SELECT template_id, template_party_type, template_title_ar,
            template_body_ar, template_is_required, template_sort
     FROM contract_clause_templates
     WHERE $where
     ORDER BY template_sort ASC, template_id ASC"
);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// تحويل الأنواع للواجهة: أرقام ومنطق بدل نصوص القاعدة
$data = array_map(static function ($r) {
    return [
        'template_id' => (int) $r['template_id'],
        'party_type' => $r['template_party_type'],
        'title_ar' => $r['template_title_ar'],
        'body_ar' => $r['template_body_ar'],
        'is_required' => (int) $r['template_is_required'] === 1,
        'sort' => (int) $r['template_sort'],
    ];
}, $rows);

// قائمة جاهزة للقائمة المنسدلة أو شبكة القوالب
printSuccess($data);
