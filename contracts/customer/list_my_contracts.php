<?php
// ============================================================
// ملف: contracts/customer/list_my_contracts.php
// الغرض: عقود الشركة أو العميل مع إمكانية دفع الدفعة الأولى
// الطريقة: POST
// يُستدعى من تطبيق الشركة/العميل بعد تسجيل الدخول.
// يُرجع العقود المرتبطة بالمستخدم أو بشركته وcan_sign/can_pay_first.
// لا يتحقق من توكن إدارة؛ يعتمد users_id من الطلب بعد طبقة التطبيق.
// ============================================================

// اتصال قاعدة البيانات والدوال العامة
include "../../connect.php";
// ترجمة الحالة والدورة وقرار دفع الدفعة الأولى
include "../includes/contract_helper.php";

// معرّف المستخدم من جلسة التطبيق وليس من توكن الإدارة
$userId = (int) filterRequest("users_id");
// بلا مستخدم لا تُعرض عقود غيره بالخطأ
if ($userId <= 0) {
    printFailure("users_id is required");
}

// صفر حتى يبقى شرط الشركة معطلاً إن لم تُوجد شركة
$companyId = 0;
// أحدث شركة يملكها المستخدم لربط عقود الشركات
$cStmt = $con->prepare(
    "SELECT company_id FROM company_registering
     WHERE company_user_id = ?
     ORDER BY company_id DESC
     LIMIT 1"
);
$cStmt->execute([$userId]);
// صفر إن لم يكن صاحب شركة فيُعرض عقود الأفراد فقط
$companyId = (int) $cStmt->fetchColumn();

// عقود الأفراد بالمستخدم أو عقود الشركة إن وُجدت شركة
$stmt = $con->prepare(
    "SELECT c.*
     FROM contracts c
     WHERE (c.contract_users_id = ?)
        OR (c.contract_company_id = ? AND ? > 0)
     ORDER BY c.contract_id DESC"
);
// الشرط الثالث يمنع مطابقة company_id = 0 لعقود بلا شركة
$stmt->execute([$userId, $companyId, $companyId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// تجميع البطاقات مع الدفعة الأولى لكل عقد
$data = [];
foreach ($rows as $row) {
    // الدفعة الأولى فقط لزر السداد في البطاقة دون جدول كامل
    $firstStmt = $con->prepare(
        "SELECT * FROM contract_installments
         WHERE installment_contract_id = ?
         ORDER BY installment_number ASC
         LIMIT 1"
    );
    $firstStmt->execute([$row['contract_id']]);
    $first = $firstStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $data[] = [
        'contract_id' => (int) $row['contract_id'],
        'contract_code' => $row['contract_code'],
        'title_ar' => $row['contract_title_ar'],
        'party_type' => $row['contract_party_type'],
        'status' => $row['contract_status'],
        'status_ar' => contractStatusAr($row['contract_status']),
        'workers_count' => (int) $row['contract_workers_count'],
        'duration_months' => (int) $row['contract_duration_months'],
        'labor_total' => (float) $row['contract_labor_total'],
        'installment_amount' => (float) $row['contract_installment_amount'],
        'installments_count' => (int) $row['contract_installments_count'],
        'payment_cycle_ar' => contractCycleAr($row['contract_payment_cycle']),
        'e_signed' => (int) $row['contract_e_signed'] === 1,
        'job_request_id' => $row['contract_job_request_id'] ? (int) $row['contract_job_request_id'] : null,
        'can_sign' => in_array($row['contract_status'], ['draft', 'pending_signature'], true),
        'can_pay_first' => contractCanPayFirstInstallment($row, $first),
        'first_installment' => $first ? [
            'installment_id' => (int) $first['installment_id'],
            'number' => (int) $first['installment_number'],
            'amount' => (float) $first['installment_amount'],
            'due_date' => $first['installment_due_date'],
            'status' => $first['installment_status'],
            'status_ar' => contractInstallmentStatusAr($first['installment_status']),
        ] : null,
    ];
}

printSuccess($data);
