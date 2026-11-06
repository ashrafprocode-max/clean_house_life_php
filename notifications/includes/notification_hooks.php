<?php
// ============================================================
// ملف: includes/notification_hooks.php
// الغرض: دوال أحداث الإشعارات تُستدعى بعد نجاح العمليات الحقيقية
//
// ⚠️ يُضمَّن من signup / إكمال الملف / الحجز / الشركة / طلب العمالة / الموافقة
// ============================================================

// تضمين المساعد الذي ينشئ صف الإشعار ويوزّعه على الصناديق
require_once __DIR__ . '/notification_helper.php';

// إشعار الإدارة بتسجيل حساب جديد (عميل أو مقدم خدمة)
function notifyUserRegistered(array $user): void
{
    // تحديد الدور النصي من نوع المستخدم
    $role = ($user['users_type'] ?? '') === 'provider' ? 'provider' : 'client';
    // ترجمة الدور للعربية لنص الإشعار
    $roleAr = $role === 'provider' ? 'مقدم خدمة' : 'عميل';
    // اسم صاحب الحساب للعرض في الصندوق
    $name = $user['users_full_name'] ?? 'مستخدم';
    // إنشاء الإشعار وبثّه لموظفي الإدارة
    notifyEvent([
        'type' => 'user_registered',
        'title' => 'حساب جديد',
        'body' => "قام {$roleAr} باسم {$name} بإنشاء حساب جديد",
        'audience' => 'admin',
        'actor_user_id' => (int) ($user['users_id'] ?? 0),
        'actor_name' => $name,
        'actor_role' => $role,
        'ref_table' => 'users',
        'ref_id' => (int) ($user['users_id'] ?? 0),
        'route_name' => 'userDetails',
        'notify_admins' => true,
    ]);
}

// إشعار الإدارة باكتمال الملف المهني وانتظار الموافقة
function notifyProviderProfileCompleted(array $user): void
{
    // اسم مقدم الخدمة للعرض
    $name = $user['users_full_name'] ?? 'مقدم خدمة';
    // إنشاء الإشعار وبثّه لموظفي الإدارة
    notifyEvent([
        'type' => 'provider_profile_completed',
        'title' => 'ملف مهني بانتظار الموافقة',
        'body' => "قام مقدم خدمة باسم {$name} بإكمال بياناته ومحتاج موافقة الإدارة",
        'audience' => 'admin',
        'actor_user_id' => (int) ($user['users_id'] ?? 0),
        'actor_name' => $name,
        'actor_role' => 'provider',
        'ref_table' => 'professional_profiles',
        'ref_id' => (int) ($user['users_id'] ?? 0),
        'route_name' => 'reviewProfessionalProfile',
        'notify_admins' => true,
    ]);
}

// إشعار الإدارة بإنشاء حجز جديد من عميل
function notifyBookingCreated(array $booking, array $client): void
{
    // اسم العميل صاحب الحجز
    $name = $client['users_full_name'] ?? 'عميل';
    // إنشاء الإشعار وبثّه لموظفي الإدارة
    notifyEvent([
        'type' => 'booking_created',
        'title' => 'حجز جديد',
        'body' => "قام عميل باسم {$name} بطلب حجز مقدم خدمة",
        'audience' => 'admin',
        'actor_user_id' => (int) ($client['users_id'] ?? 0),
        'actor_name' => $name,
        'actor_role' => 'client',
        'ref_table' => 'bookings',
        'ref_id' => (int) ($booking['booking_id'] ?? 0),
        'route_name' => 'bookingDetails',
        'notify_admins' => true,
        'extra' => [
            'booking_ps_id' => (int) ($booking['booking_ps_id'] ?? 0),
        ],
    ]);
}

// إشعار الإدارة بتسجيل شركة أو نشاط جديد
function notifyCompanyRegistered(array $company, array $owner): void
{
    // اسم صاحب الحساب المرتبط بالشركة
    $ownerName = $owner['users_full_name'] ?? 'عميل';
    // اسم الشركة كما سُجّل في الجدول
    $companyName = $company['company_name'] ?? 'شركة';
    // إنشاء الإشعار وبثّه لموظفي الإدارة
    notifyEvent([
        'type' => 'company_registered',
        'title' => 'تسجيل شركة جديدة',
        'body' => "قام العميل {$ownerName} بتسجيل شركته اللي اسمها {$companyName}",
        'audience' => 'admin',
        'actor_user_id' => (int) ($owner['users_id'] ?? 0),
        'actor_name' => $ownerName,
        'actor_role' => 'company',
        'ref_table' => 'company_registering',
        'ref_id' => (int) ($company['company_id'] ?? 0),
        'route_name' => 'editCompany',
        'notify_admins' => true,
    ]);
}

// إشعار الإدارة بطلب عمالة جديد من شركة
function notifyJobRequestCreated(array $job, array $company): void
{
    // اسم الشركة الطالبة
    $companyName = $company['company_name'] ?? 'شركة';
    // عنوان الوظيفة المطلوبة
    $jobTitle = $job['required_job_title'] ?? 'وظيفة';
    // إنشاء الإشعار وبثّه لموظفي الإدارة
    notifyEvent([
        'type' => 'job_request_created',
        'title' => 'طلب عمالة جديد',
        'body' => "قامت شركة {$companyName} بطلب عمالة: {$jobTitle}",
        'audience' => 'admin',
        'actor_user_id' => (int) ($company['company_user_id'] ?? 0),
        'actor_name' => $companyName,
        'actor_role' => 'company',
        'ref_table' => 'job_details_required',
        'ref_id' => (int) ($job['required_id'] ?? 0),
        'route_name' => 'editJobRequest',
        'notify_admins' => true,
    ]);
}

// إشعار مقدم الخدمة بالموافقة على حسابه
function notifyProviderApproved(array $provider): void
{
    // معرّف مقدم الخدمة المستلم
    $userId = (int) ($provider['users_id'] ?? 0);
    // لا نرسل إن لم يوجد معرّف صالح
    if ($userId <= 0) {
        return;
    }
    // إنشاء الإشعار لصندوق المستخدم فقط
    notifyEvent([
        'type' => 'provider_approved',
        'title' => 'تمت الموافقة على حسابك',
        'body' => 'تم الموافقة على حسابك من فضلك افتح التطبيق لإدخال تفاصيل مهنتك',
        'audience' => 'user',
        'actor_user_id' => $userId,
        'actor_name' => $provider['users_full_name'] ?? '',
        'actor_role' => 'provider',
        'ref_table' => 'professional_profiles',
        'ref_id' => $userId,
        'route_name' => 'addProviderServicesDetailsScreen',
        'user_ids' => [$userId],
        'notify_admins' => false,
    ]);
}

// إشعار المزود والعميل عند تأكيد/اعتماد الحجز من الإدارة
function notifyBookingConfirmed(array $booking, array $provider, array $client, string $adminWhatsapp = ''): void
{
    // تفاصيل العمل المعروضة في النص
    $jobTitle = $booking['booking_order_details'] ?? 'الوظيفة';
    // موعد بدء العمل إن وُجد
    $startAt = $booking['booking_vailability_status'] ?? '';
    // معرّف مقدم الخدمة المستلم
    $providerId = (int) ($provider['users_id'] ?? 0);
    // معرّف العميل المستلم
    $clientId = (int) ($client['users_id'] ?? 0);

    // إرسال إشعار لمقدم الخدمة إن وُجد
    if ($providerId > 0) {
        // جملة واتساب الإدارة أو نص بديل
        $wa = $adminWhatsapp !== '' ? " تواصل مع الإدارة: {$adminWhatsapp}" : ' تواصل مع الإدارة';
        // إنشاء إشعار المزود
        notifyEvent([
            'type' => 'booking_confirmed_provider',
            'title' => 'تم اختيارك لشغل وظيفة',
            'body' => "تم اختيارك لشغل وظيفة {$jobTitle}.{$wa}",
            'audience' => 'user',
            'actor_user_id' => $clientId,
            'actor_name' => $client['users_full_name'] ?? '',
            'actor_role' => 'client',
            'ref_table' => 'bookings',
            'ref_id' => (int) ($booking['booking_id'] ?? 0),
            'route_name' => 'viewProviderServicesDetailsScreen',
            'user_ids' => [$providerId],
            'notify_admins' => false,
            'extra' => ['admin_whatsapp' => $adminWhatsapp],
        ]);
    }

    // إرسال إشعار للعميل إن وُجد
    if ($clientId > 0) {
        // جملة موعد الإرسال إن وُجد وقت البدء
        $when = $startAt !== '' ? " بعد الدفع سيتم إرسال العامل في {$startAt}" : ' بعد الدفع سيتم إرسال العامل قريباً';
        // مبلغ الحجز إن وُجد لتوجيه شاشة الدفع
        $amount = (float) ($booking['booking_total_price'] ?? 0);
        // إنشاء إشعار العميل مع توجيه للدفع
        notifyEvent([
            'type' => 'booking_confirmed_client',
            'title' => 'تم اعتماد حجزك — ادفع الآن',
            'body' => "تم اعتماد حجزك. ادفع الآن لإتمام الطلب.{$when}",
            'audience' => 'user',
            'actor_user_id' => $providerId,
            'actor_name' => $provider['users_full_name'] ?? '',
            'actor_role' => 'provider',
            'ref_table' => 'bookings',
            'ref_id' => (int) ($booking['booking_id'] ?? 0),
            'route_name' => 'myBookingDetailsScreen',
            'user_ids' => [$clientId],
            'notify_admins' => false,
            'extra' => [
                'booking_id' => (int) ($booking['booking_id'] ?? 0),
                'amount' => $amount,
            ],
        ]);
    }
}

// هل أُرسل إشعار بنفس النوع والمرجع خلال الساعات المحددة؟
function notificationExistsRecently(
    string $type,
    string $refTable,
    int $refId,
    int $hours = 20,
    string $extraNeedle = ''
): bool {
    // الاتصال العام بقاعدة البيانات
    global $con;
    // لا نكرر بلا نوع — المرجع صفر مسموح لملخص الإدارة
    if ($type === '' || $refId < 0) {
        return false;
    }
    // شرط النص الإضافي داخل JSON إن طُلب
    $extraSql = $extraNeedle !== '' ? ' AND notification_extra_json LIKE ?' : '';
    // المرجع صفر يُحفظ NULL في notifyEvent
    $refSql = $refId === 0 ? ' AND notification_ref_id IS NULL' : ' AND notification_ref_id = ?';
    // الاستعلام عن إشعار مشابه حديث
    $stmt = $con->prepare(
        "SELECT notification_id FROM notifications
         WHERE notification_type = ?
           AND notification_ref_table = ?
           {$refSql}
           AND notification_created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
           {$extraSql}
         LIMIT 1"
    );
    // تجهيز المعاملات
    $params = [$type, $refTable];
    if ($refId !== 0) {
        $params[] = $refId;
    }
    $params[] = $hours;
    // إضافة إبرة extra إن وُجدت
    if ($extraNeedle !== '') {
        $params[] = '%' . $extraNeedle . '%';
    }
    // تنفيذ البحث
    $stmt->execute($params);
    // وجود صف يعني تم الإرسال سابقاً
    return (bool) $stmt->fetchColumn();
}

// إشعار العميل والإدارة بنتيجة دفع حجز عبر التطبيق
function notifyBookingPaymentResult(array $booking, bool $success, float $amount, string $reference): void
{
    // اسم العميل
    $name = (string) ($booking['users_full_name'] ?? 'عميل');
    // رقم الحجز
    $bookingId = (int) ($booking['booking_id'] ?? 0);
    // معرّف العميل
    $userId = (int) ($booking['booking_users_id'] ?? 0);
    // نص المبلغ
    $amountText = number_format($amount, 2);
    // لا نرسل بلا مستلم
    if ($userId <= 0 || $bookingId <= 0) {
        return;
    }
    // إشعار العميل بنجاح أو فشل الدفع
    notifyEvent([
        'type' => $success ? 'booking_payment_paid' : 'booking_payment_failed',
        'title' => $success ? 'تم الدفع بنجاح' : 'فشل عملية الدفع',
        'body' => $success
            ? "تم دفع مبلغ {$amountText} جنيه للحجز رقم {$bookingId} بنجاح"
            : "لم تكتمل عملية دفع الحجز رقم {$bookingId}. يمكنك المحاولة مرة أخرى",
        'audience' => 'user',
        'actor_user_id' => $userId,
        'actor_name' => $name,
        'actor_role' => 'client',
        'ref_table' => 'bookings',
        'ref_id' => $bookingId,
        'route_name' => $success ? 'myBookingDetailsScreen' : 'paymentScreen',
        'user_ids' => [$userId],
        'extra' => [
            'payment_reference' => $reference,
            'booking_id' => $bookingId,
            'amount' => $amount,
        ],
    ]);
    // إشعار الإدارة عند نجاح الدفع فقط
    if ($success) {
        notifyEvent([
            'type' => 'booking_payment_received',
            'title' => 'دفعة حجز جديدة',
            'body' => "قام العميل {$name} بدفع {$amountText} جنيه للحجز رقم {$bookingId}",
            'audience' => 'admin',
            'actor_user_id' => $userId,
            'actor_name' => $name,
            'actor_role' => 'client',
            'ref_table' => 'bookings',
            'ref_id' => $bookingId,
            'route_name' => 'bookingDetails',
            'notify_admins' => true,
            'extra' => ['payment_reference' => $reference],
        ]);
    }
}

// إشعار الشركة/العميل والإدارة بنتيجة دفع دفعة عقد عبر التطبيق
function notifyContractAppPayment(array $installment, bool $success, float $amount, string $reference): void
{
    // معرّف المستخدم المستلم
    $userId = (int) ($installment['contract_users_id'] ?? $installment['users_id'] ?? 0);
    // رقم العقد للعرض
    $code = (string) ($installment['contract_code'] ?? '');
    // رقم الدفعة
    $number = (int) ($installment['installment_number'] ?? 0);
    // معرّف الدفعة
    $installmentId = (int) ($installment['installment_id'] ?? 0);
    // معرّف العقد
    $contractId = (int) ($installment['contract_id'] ?? $installment['installment_contract_id'] ?? 0);
    // اسم الطرف
    $name = (string) ($installment['company_name'] ?? $installment['users_full_name'] ?? 'عميل');
    // نص المبلغ
    $amountText = number_format($amount, 2);
    // لا نرسل بلا مرجع
    if ($installmentId <= 0) {
        return;
    }
    // إشعار المستخدم إن وُجد
    if ($userId > 0) {
        notifyEvent([
            'type' => $success ? 'contract_payment_paid' : 'contract_payment_failed',
            'title' => $success ? 'تم سداد الدفعة' : 'فشل سداد الدفعة',
            'body' => $success
                ? "تم سداد دفعة رقم {$number} من العقد {$code} بمبلغ {$amountText} جنيه"
                : "لم تكتمل عملية سداد دفعة رقم {$number} من العقد {$code}. حاول مرة أخرى",
            'audience' => 'user',
            'actor_user_id' => $userId,
            'actor_name' => $name,
            'actor_role' => 'company',
            'ref_table' => 'contract_installments',
            'ref_id' => $installmentId,
            'route_name' => 'myJobRequestsScreen',
            'user_ids' => [$userId],
            'extra' => [
                'contract_id' => $contractId,
                'installment_id' => $installmentId,
                'payment_reference' => $reference,
            ],
        ]);
    }
    // إشعار الإدارة عند النجاح
    if ($success) {
        notifyEvent([
            'type' => 'contract_payment_received',
            'title' => 'تحصيل دفعة عقد عبر التطبيق',
            'body' => "تم تحصيل دفعة رقم {$number} من العقد {$code} بمبلغ {$amountText} جنيه من {$name}",
            'audience' => 'admin',
            'actor_user_id' => $userId,
            'actor_name' => $name,
            'actor_role' => 'company',
            'ref_table' => 'contract_installments',
            'ref_id' => $installmentId,
            'route_name' => 'contractDetails',
            'notify_admins' => true,
            'extra' => [
                'contract_id' => $contractId,
                'installment_id' => $installmentId,
            ],
        ]);
    }
}

// إشعار الطرف بعد تأكيد الإدارة لتحصيل يدوي (شيك / سكرين)
function notifyManualPaymentConfirmed(array $installment, string $method): void
{
    // معرّف المستخدم
    $userId = (int) ($installment['contract_users_id'] ?? 0);
    // رقم العقد
    $code = (string) ($installment['contract_code'] ?? '');
    // رقم الدفعة
    $number = (int) ($installment['installment_number'] ?? 0);
    // معرّف الدفعة
    $installmentId = (int) ($installment['installment_id'] ?? 0);
    // معرّف العقد
    $contractId = (int) ($installment['contract_id'] ?? $installment['installment_contract_id'] ?? 0);
    // اسم الطرف
    $name = (string) ($installment['company_name'] ?? $installment['users_full_name'] ?? 'عميل');
    // المبلغ
    $amountText = number_format((float) ($installment['installment_amount'] ?? 0), 2);
    // تسمية الوسيلة
    $methodAr = function_exists('contractMethodAr') ? contractMethodAr($method) : $method;
    // لا نرسل بلا مستلم
    if ($userId <= 0 || $installmentId <= 0) {
        return;
    }
    // إشعار المستخدم بتأكيد التحصيل
    notifyEvent([
        'type' => 'contract_payment_manual_confirmed',
        'title' => 'تم تأكيد تحصيل الدفعة',
        'body' => "أكدت الإدارة تحصيل دفعة رقم {$number} من العقد {$code} بمبلغ {$amountText} جنيه عبر {$methodAr}",
        'audience' => 'user',
        'actor_user_id' => $userId,
        'actor_name' => $name,
        'actor_role' => 'company',
        'ref_table' => 'contract_installments',
        'ref_id' => $installmentId,
        'route_name' => 'myJobRequestsScreen',
        'user_ids' => [$userId],
        'extra' => [
            'contract_id' => $contractId,
            'installment_id' => $installmentId,
            'method' => $method,
        ],
    ]);
}

// تذكير باستحقاق دفعة عقد قبل 7 أيام أو يوم واحد
function notifyInstallmentDueReminder(array $installment, int $daysBefore): void
{
    // معرّف المستخدم
    $userId = (int) ($installment['contract_users_id'] ?? 0);
    // معرّف الدفعة
    $installmentId = (int) ($installment['installment_id'] ?? 0);
    // معرّف العقد
    $contractId = (int) ($installment['contract_id'] ?? $installment['installment_contract_id'] ?? 0);
    // رقم العقد
    $code = (string) ($installment['contract_code'] ?? '');
    // رقم الدفعة
    $number = (int) ($installment['installment_number'] ?? 0);
    // تاريخ الاستحقاق
    $due = (string) ($installment['installment_due_date'] ?? '');
    // المبلغ
    $amountText = number_format((float) ($installment['installment_amount'] ?? 0), 2);
    // نوع الحدث حسب المهلة
    $type = $daysBefore >= 7 ? 'installment_due_7d' : 'installment_due_1d';
    // لا نكرر نفس التذكير خلال يوم تقريباً
    if ($installmentId <= 0 || notificationExistsRecently($type, 'contract_installments', $installmentId, 20)) {
        return;
    }
    // عنوان مختلف ليوم واحد
    $title = $daysBefore >= 7 ? 'تذكير: دفعة تستحق خلال 7 أيام' : 'تذكير: دفعة تستحق غداً';
    // نص الجسم
    $body = "دفعة رقم {$number} من العقد {$code} بمبلغ {$amountText} جنيه تستحق في {$due}";
    // إشعار المستخدم إن وُجد
    if ($userId > 0) {
        notifyEvent([
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'audience' => 'user',
            'actor_user_id' => $userId,
            'actor_name' => (string) ($installment['company_name'] ?? $installment['users_full_name'] ?? ''),
            'actor_role' => 'company',
            'ref_table' => 'contract_installments',
            'ref_id' => $installmentId,
            'route_name' => 'myJobRequestsScreen',
            'user_ids' => [$userId],
            'extra' => [
                'contract_id' => $contractId,
                'installment_id' => $installmentId,
                'days_before' => $daysBefore,
                'due_date' => $due,
            ],
        ]);
    }
}

// إشعار تأخير دفعة للطرف وللإدارة
function notifyInstallmentOverdue(array $installment): void
{
    // معرّف المستخدم
    $userId = (int) ($installment['contract_users_id'] ?? 0);
    // معرّف الدفعة
    $installmentId = (int) ($installment['installment_id'] ?? 0);
    // معرّف العقد
    $contractId = (int) ($installment['contract_id'] ?? $installment['installment_contract_id'] ?? 0);
    // رقم العقد
    $code = (string) ($installment['contract_code'] ?? '');
    // رقم الدفعة
    $number = (int) ($installment['installment_number'] ?? 0);
    // الاسم
    $name = (string) ($installment['company_name'] ?? $installment['users_full_name'] ?? 'عميل');
    // المبلغ
    $amountText = number_format((float) ($installment['installment_amount'] ?? 0), 2);
    // تاريخ الاستحقاق
    $due = (string) ($installment['installment_due_date'] ?? '');
    // منع التكرار خلال يوم
    if ($installmentId <= 0 || notificationExistsRecently('installment_overdue', 'contract_installments', $installmentId, 20)) {
        return;
    }
    // إشعار المستخدم
    if ($userId > 0) {
        notifyEvent([
            'type' => 'installment_overdue',
            'title' => 'دفعة متأخرة',
            'body' => "دفعة رقم {$number} من العقد {$code} بمبلغ {$amountText} جنيه تجاوزت الاستحقاق في {$due}",
            'audience' => 'user',
            'actor_user_id' => $userId,
            'actor_name' => $name,
            'actor_role' => 'company',
            'ref_table' => 'contract_installments',
            'ref_id' => $installmentId,
            'route_name' => 'myJobRequestsScreen',
            'user_ids' => [$userId],
            'extra' => [
                'contract_id' => $contractId,
                'installment_id' => $installmentId,
                'due_date' => $due,
            ],
        ]);
    }
    // إشعار الإدارة لكل دفعة متأخرة جديدة
    notifyEvent([
        'type' => 'installment_overdue_admin',
        'title' => 'دفعة عقد متأخرة',
        'body' => "تأخر {$name} عن دفعة رقم {$number} من العقد {$code} بمبلغ {$amountText} جنيه",
        'audience' => 'admin',
        'actor_user_id' => $userId,
        'actor_name' => $name,
        'actor_role' => 'company',
        'ref_table' => 'contract_installments',
        'ref_id' => $installmentId,
        'route_name' => 'overduePayments',
        'notify_admins' => true,
        'extra' => [
            'contract_id' => $contractId,
            'installment_id' => $installmentId,
        ],
    ]);
}

// ملخص يومي للإدارة بقائمة المتأخرين
function notifyAdminOverdueSummary(int $count, float $totalAmount): void
{
    // لا نرسل ملخصاً فارغاً
    if ($count <= 0) {
        return;
    }
    // منع تكرار الملخص خلال 12 ساعة
    if (notificationExistsRecently('overdue_payments_summary', 'contract_installments', 0, 12)) {
        return;
    }
    // نص المبلغ
    $amountText = number_format($totalAmount, 2);
    // إشعار الإدارة بقائمة المتأخرين
    notifyEvent([
        'type' => 'overdue_payments_summary',
        'title' => 'قائمة الدفعات المتأخرة',
        'body' => "يوجد {$count} دفعة متأخرة بإجمالي {$amountText} جنيه. اضغط لعرض القائمة",
        'audience' => 'admin',
        'ref_table' => 'contract_installments',
        'ref_id' => 0,
        'route_name' => 'overduePayments',
        'notify_admins' => true,
        'extra' => [
            'overdue_count' => $count,
            'overdue_total' => $totalAmount,
        ],
    ]);
}

// إشعار مقدم الخدمة برفض الملف ليعيد التعديل من شاشة الخدمات
function notifyProviderRejected(array $provider): void
{
    // بلا مستخدم لا يُرسل إشعار يتيم
    $userId = (int) ($provider['users_id'] ?? 0);
    if ($userId <= 0) {
        return;
    }
    // ملاحظة المراجع إن وُجدت وإلا نص افتراضي للتعديل
    $note = trim((string) ($provider['review_note'] ?? ''));
    $body = $note !== ''
        ? $note
        : 'يرجى تعديل بياناتك أو إعادة رفع المستندات ثم حفظ التعديلات';
    notifyEvent([
        'type' => 'provider_rejected',
        'title' => 'مطلوب تعديل بياناتك',
        'body' => $body,
        'audience' => 'user',
        'actor_user_id' => $userId,
        'actor_name' => $provider['users_full_name'] ?? '',
        'actor_role' => 'provider',
        'ref_table' => 'professional_profiles',
        'ref_id' => $userId,
        'route_name' => 'addProviderServicesDetailsScreen',
        'user_ids' => [$userId],
        'notify_admins' => false,
    ]);
}

// إشعار الإدارة بأن مقدم الخدمة عدّل الملف بعد الرفض وينتظر إعادة المراجعة
function notifyProviderProfileUpdated(array $user): void
{
    $name = $user['users_full_name'] ?? 'مقدم خدمة';
    notifyEvent([
        'type' => 'provider_profile_updated',
        'title' => 'تعديل ملف مهني بانتظار المراجعة',
        'body' => "قام مقدم خدمة باسم {$name} بتعديل بياناته وينتظر إعادة المراجعة",
        'audience' => 'admin',
        'actor_user_id' => (int) ($user['users_id'] ?? 0),
        'actor_name' => $name,
        'actor_role' => 'provider',
        'ref_table' => 'professional_profiles',
        'ref_id' => (int) ($user['users_id'] ?? 0),
        'route_name' => 'reviewProfessionalProfile',
        'notify_admins' => true,
    ]);
}
