<?php
// ============================================================
// ملف: contracts/includes/contract_helper.php
// الغرض: دوال حساب عمولة الشركات وتوليد الدفعات وحالات العقد
// يُستدعى من مسارات إدارة العقود ومسارات العميل والـ cron.
// لا يطبع استجابة وليس نقطة نهاية. يُضمَّن بـ include فقط.
//
// القاعدة:
//   إجمالي تكلفة العمال = عدد العمال × تكلفة العامل الشهرية × مدة العقد بالأشهر
//   عمولة التطبيق مخفية، حدها الأدنى 10%، والإدارة ترفعها فقط
//   قيمة الدفعة = إجمالي التكلفة ÷ عدد الدفعات
//   عمولة الدفعة = إجمالي العمولة ÷ عدد الدفعات
// ============================================================

// ============================================================
// قراءة مفتاح من app_settings مع قيمة بديلة
// ============================================================
function contractGetAppSetting(PDO $con, string $key, string $fallback = ''): string
{
    // استعلام المفتاح الواحد حتى لا تُحمَّل كل الإعدادات
    $stmt = $con->prepare(
        "SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1"
    );
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();

    // إن لم يوجد الصف نُرجع البديل حتى تعمل العقود بلا صف إعدادات
    if ($value === false || $value === null) {
        return $fallback;
    }

    return (string) $value;
}

function contractCompanyCommissionSettings(PDO $con): array
{
    // القيم الافتراضية: مفعّلة، نسبة، 10%، حد أدنى 10%
    $defaults = [
        'enabled' => true,
        'type' => 'percent',
        'value' => 10.0,
        'min' => 10.0,
    ];

    // قراءة المفاتيح الأربعة من app_settings
    $enabled = contractGetAppSetting($con, 'company_commission_enabled', '1');
    $type = contractGetAppSetting($con, 'company_commission_type', 'percent');
    $value = (float) contractGetAppSetting($con, 'company_commission_value', '10');
    $min = (float) contractGetAppSetting($con, 'company_commission_min', '10');

    // الحد الأدنى لا ينخفض عن 10% حتى لو غُيّر الإعداد خطأ
    if ($min < 10) {
        $min = 10;
    }

    // النسبة المختارة لا تنخفض عن الحد الأدنى
    if ($value < $min) {
        $value = $min;
    }

    // تجاهل $defaults بعد الدمج لأنها مرجع توثيقي للقيم الابتدائية
    unset($defaults);

    return [
        'enabled' => $enabled === '1' || $enabled === 1 || $enabled === true,
        'type' => $type === 'fixed' ? 'fixed' : 'percent',
        'value' => $value,
        'min' => $min,
    ];
}

// ============================================================
// حساب إجمالي تكلفة العمال وعمولة التطبيق وجدول الدفعات
// ============================================================
function contractComputeFinancials(
    int $workersCount,
    float $workerMonthlyCost,
    int $durationMonths,
    float $commissionPercent,
    string $paymentCycle
): array {
    // عدد العمال لا يقل عن صفر حتى لا ينقلب الإجمالي سالباً
    if ($workersCount < 0) {
        $workersCount = 0;
    }

    // المدة لا تقل عن شهر واحد
    if ($durationMonths < 1) {
        $durationMonths = 1;
    }

    // النسبة لا تقل عن 10% حتى لو استُدعيت الدالة مباشرة
    if ($commissionPercent < 10) {
        $commissionPercent = 10;
    }

    // إجمالي تكلفة العمال = العدد × التكلفة الشهرية × المدة
    $laborTotal = round($workersCount * $workerMonthlyCost * $durationMonths, 2);

    // إجمالي عمولة التطبيق المخفية عن الشركة
    $commissionAmount = round(($laborTotal * $commissionPercent) / 100, 2);

    // عدد الدفعات حسب الدورة
    $installmentsCount = contractInstallmentsCount($durationMonths, $paymentCycle);

    // قيمة الدفعة الواحدة التي تسددها الشركة (بدون إظهار العمولة)
    $installmentAmount = $installmentsCount > 0
        ? round($laborTotal / $installmentsCount, 2)
        : $laborTotal;

    // عمولة كل دفعة تُضاف للإيرادات عند التحصيل
    $installmentCommission = $installmentsCount > 0
        ? round($commissionAmount / $installmentsCount, 2)
        : $commissionAmount;

    return [
        'workers_count' => $workersCount,
        'worker_monthly_cost' => round($workerMonthlyCost, 2),
        'duration_months' => $durationMonths,
        'labor_total' => $laborTotal,
        'commission_percent' => $commissionPercent,
        'commission_amount' => $commissionAmount,
        'payment_cycle' => $paymentCycle,
        'installments_count' => $installmentsCount,
        'installment_amount' => $installmentAmount,
        'installment_commission' => $installmentCommission,
    ];
}

// ============================================================
// عدد الدفعات من مدة العقد ودورة التحصيل
// ============================================================
function contractInstallmentsCount(int $durationMonths, string $paymentCycle): int
{
    // دفعة واحدة لكامل المدة
    if ($paymentCycle === 'once') {
        return 1;
    }

    // سنوي: دفعة كل 12 شهراً وبحد أدنى واحدة
    if ($paymentCycle === 'yearly') {
        return max(1, (int) ceil($durationMonths / 12));
    }

    // ربع سنوي: دفعة كل 3 أشهر
    if ($paymentCycle === 'quarterly') {
        return max(1, (int) ceil($durationMonths / 3));
    }

    // شهري: دفعة لكل شهر
    return max(1, $durationMonths);
}

// ============================================================
// توليد رقم عقد فريد مثل CHL-C-2026-0007
// ============================================================
function contractNextCode(PDO $con, string $partyType): string
{
    // البادئة حسب نوع الطرف: أفراد I وشركات C
    $prefix = $partyType === 'individual' ? 'CHL-I' : 'CHL-C';

    // السنة الحالية حتى يُعاد التسلسل كل عام
    $year = date('Y');

    // قراءة أعلى رقم في هذه السنة لهذه البادئة
    $like = $prefix . '-' . $year . '-%';
    $stmt = $con->prepare(
        "SELECT contract_code FROM contracts
         WHERE contract_code LIKE ?
         ORDER BY contract_id DESC
         LIMIT 1"
    );
    $stmt->execute([$like]);
    $last = $stmt->fetchColumn();

    // الرقم التسلسلي التالي يبدأ من 1 إن لم يوجد عقد هذه السنة
    $seq = 1;
    if ($last) {
        $parts = explode('-', (string) $last);
        $seq = ((int) end($parts)) + 1;
    }

    // تكوين الرقم مع أصفار بادئة لأربعة خانات
    return sprintf('%s-%s-%04d', $prefix, $year, $seq);
}

// ============================================================
// توليد صفوف الدفعات بعد إنشاء العقد أو تفعيله
// ============================================================
function contractGenerateInstallments(
    PDO $con,
    int $contractId,
    array $financials,
    string $startDate,
    ?int $collectionDay
): void {
    // حذف أي دفعات قديمة غير محصّلة حتى يمكن إعادة التوليد من المسودة
    $con->prepare(
        "DELETE FROM contract_installments
         WHERE installment_contract_id = ?
           AND installment_status IN ('pending','due')"
    )->execute([$contractId]);

    // تاريخ البداية ككائن لتعديل الأشهر بأمان
    $start = new DateTime($startDate ?: date('Y-m-d'));

    // يوم التحصيل داخل الشهر (1..28) لتجنب 29/30/31
    $day = $collectionDay !== null ? (int) $collectionDay : (int) $start->format('d');
    if ($day < 1) {
        $day = 1;
    }
    if ($day > 28) {
        $day = 28;
    }

    // خطوة الأشهر بين دفعة والتي تليها
    $cycle = $financials['payment_cycle'] ?? 'monthly';
    $stepMonths = 1;
    if ($cycle === 'quarterly') {
        $stepMonths = 3;
    } elseif ($cycle === 'yearly') {
        $stepMonths = 12;
    } elseif ($cycle === 'once') {
        $stepMonths = 0;
    }

    // عدد الدفعات والقيم المحسوبة مسبقاً
    $count = (int) $financials['installments_count'];
    $amount = (float) $financials['installment_amount'];
    $commission = (float) $financials['installment_commission'];

    // جملة الإدراج تُحضَّر مرة خارج الحلقة
    $insert = $con->prepare(
        "INSERT INTO contract_installments (
            installment_contract_id, installment_number, installment_due_date,
            installment_amount, installment_commission, installment_status
         ) VALUES (?, ?, ?, ?, ?, ?)"
    );

    // توليد كل دفعة بتاريخ استحقاق مستقل
    for ($i = 1; $i <= $count; $i++) {
        // نسخة من البداية حتى لا تتراكم التعديلات على نفس الكائن
        $due = clone $start;
        if ($stepMonths > 0 && $i > 1) {
            $due->modify('+' . (($i - 1) * $stepMonths) . ' months');
        }
        $due->setDate((int) $due->format('Y'), (int) $due->format('m'), $day);

        // الحالة: الأولى مستحقة والباقي معلّقة حتى يحين cron
        $status = $i === 1 ? 'due' : 'pending';

        $insert->execute([
            $contractId,
            $i,
            $due->format('Y-m-d'),
            $amount,
            $commission,
            $status,
        ]);
    }
}

// ============================================================
// ترجمة الحالات للعربية في اللوحة والتطبيق
// ============================================================
function contractStatusAr(string $status): string
{
    $map = [
        'draft' => 'مسودة',
        'pending_signature' => 'بانتظار التوقيع',
        'signed' => 'موقّع',
        'active' => 'ساري',
        'completed' => 'منتهٍ',
        'cancelled' => 'ملغي',
    ];
    return $map[$status] ?? $status;
}

function contractInstallmentStatusAr(string $status): string
{
    $map = [
        'pending' => 'معلّقة',
        'due' => 'مستحقة',
        'paid' => 'محصّلة',
        'overdue' => 'متأخرة',
        'cancelled' => 'ملغاة',
    ];
    return $map[$status] ?? $status;
}

function contractCycleAr(string $cycle): string
{
    $map = [
        'monthly' => 'شهري',
        'quarterly' => 'ربع سنوي',
        'yearly' => 'سنوي',
        'once' => 'دفعة واحدة',
    ];
    return $map[$cycle] ?? $cycle;
}

function contractPartyAr(string $party): string
{
    return $party === 'individual' ? 'أفراد' : 'شركة / مصنع / نشاط';
}

function contractInsuranceAr(string $on): string
{
    $map = [
        'app' => 'على التطبيق',
        'company' => 'على الشركة',
        'shared' => 'مشتركة',
        'not_specified' => 'غير محدد',
    ];
    return $map[$on] ?? $on;
}

function contractMethodAr(?string $method): string
{
    $map = [
        'app_card' => 'كارت عبر التطبيق',
        'app_wallet' => 'محفظة عبر التطبيق',
        'bank_transfer' => 'تحويل بنكي',
        'check' => 'شيك بنكي',
        'cash' => 'نقدي',
        'instapay' => 'إنستاباي',
        'mixed' => 'متعدد حسب الاتفاق',
    ];
    return $map[$method ?? ''] ?? ($method ?? '');
}

// ============================================================
// هل يجوز للشركة دفع الدفعة الأولى؟
// يظهر الزر فقط بعد توقيع/تفعيل العقد وعلى دفعة مستحقة غير مدفوعة
// ============================================================
function contractCanPayFirstInstallment(array $contract, ?array $firstInstallment): bool
{
    // لا عقد
    if (empty($contract)) {
        return false;
    }

    // الحالة تسمح بالتحصيل بعد التوقيع أو أثناء السريان
    $okStatus = in_array($contract['contract_status'] ?? '', ['signed', 'active'], true);
    if (!$okStatus) {
        return false;
    }

    // لا دفعة مولَّدة بعد
    if (!$firstInstallment) {
        return false;
    }

    // الدفعة الأولى وغير محصّلة
    $number = (int) ($firstInstallment['installment_number'] ?? 0);
    $status = $firstInstallment['installment_status'] ?? '';
    return $number === 1 && in_array($status, ['pending', 'due', 'overdue'], true);
}
