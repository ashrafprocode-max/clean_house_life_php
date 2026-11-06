-- ============================================================
-- ملف: database/payments_v1.sql
-- الغرض: إنشاء جداول نظام المدفوعات (Paymob) وتحديث إعدادات العمولات
--
-- الفكرة العامة:
--   1) العميل يدفع إجمالي الحجز (booking_total_price) عبر Paymob.
--   2) تُضاف رسوم بوابة الدفع على العميل: 1% بحد أدنى 5 جنيه،
--      ويمكن للإدارة تعطيلها أو تعديل نسبتها أو حدها الأدنى.
--   3) يمكن حفظ الكارت (Token) لدى Paymob وإعادة استخدامه،
--      ويظهر للعميل آخر 4 أرقام فقط ويُطلب منه CVV عند الدفع.
--   4) الحجز لا يُعتبر مدفوعاً إلا بعد تأكيد المعاملة من البوابة
--      (Webhook / Callback) وليس من رد الموبايل فقط.
--   5) نظام الدفع مصمم بمرونة تسمح بإضافة بوابة أخرى مستقبلاً
--      (عمود payment_provider) مثل بوابة سعودية أو خليجية.
--
-- تنفيذ الملف:
--   - نفّذ على clean_house_life_dev و clean_house_life_prod معاً.
--   - إذا ظهر Duplicate column name فتجاهل السطر الخاص بهذا العمود.
--   - الملف آمن عند التنفيذ أكثر من مرة (IF NOT EXISTS / ON DUPLICATE KEY).
-- ============================================================

-- ============================================================
-- 1) جدول الكروت المحفوظة (payment_cards)
--    لا نحتفظ برقم الكارت أبداً، بل نحتفظ بـ Token من Paymob
--    ورقم مقنّع (Masked) لآخر 4 أرقام فقط لأغراض العرض.
-- ============================================================
CREATE TABLE IF NOT EXISTS `payment_cards` (
  `card_id`            INT(11)      NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للكارت المحفوظ',
  `card_user_id`       INT(11)      NOT NULL COMMENT 'صاحب الكارت - مرتبط بجدول users',
  `card_provider`      VARCHAR(30)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'paymob' COMMENT 'اسم بوابة الدفع (paymob حالياً وقابل للتوسع)',
  `card_token`         VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'توكن الكارت من البوابة - يُستخدم للدفع بدون إدخال بيانات الكارت',
  `card_mask`          VARCHAR(25)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'رقم الكارت المقنّع مثل 5123 **** **** 1234',
  `card_last4`         CHAR(4)      COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'آخر 4 أرقام من الكارت - هي الوحيدة التي تُعرض للعميل',
  `card_brand`         VARCHAR(30)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'نوع الكارت: Visa / MasterCard / Meeza',
  `card_expiry_month`  TINYINT(2)   DEFAULT NULL COMMENT 'شهر انتهاء صلاحية الكارت (اختياري للعرض)',
  `card_expiry_year`   SMALLINT(4)  DEFAULT NULL COMMENT 'سنة انتهاء صلاحية الكارت (اختياري للعرض)',
  `card_is_default`    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'هل هذا هو الكارت الافتراضي للعميل؟ 1 = نعم',
  `card_is_active`     TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'هل الكارت صالح للاستخدام؟ 0 = محذوف/ملغي',
  `card_created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ ووقت إضافة الكارت',
  `card_updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ ووقت آخر تعديل',
  PRIMARY KEY (`card_id`),
  KEY `idx_card_user` (`card_user_id`),
  KEY `idx_card_user_active` (`card_user_id`, `card_is_active`),
  CONSTRAINT `fk_payment_card_user` FOREIGN KEY (`card_user_id`) REFERENCES `users` (`users_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2) جدول المدفوعات (payments)
--    كل محاولة دفع = صف مستقل، حتى لو فشلت أو تكررت.
--    الربط بالحجز إجباري، وربط الكارت اختياري (دفع بكارت جديد).
-- ============================================================
CREATE TABLE IF NOT EXISTS `payments` (
  `payment_id`                INT(11)       NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد لعملية الدفع',
  `payment_user_id`           INT(11)       NOT NULL COMMENT 'العميل الذي قام بالدفع - مرتبط بجدول users',
  `payment_booking_id`        INT(11)       NOT NULL COMMENT 'الحجز المدفوع - مرتبط بجدول bookings',
  `payment_commission_id`     INT(11)       DEFAULT NULL COMMENT 'مرجع اختياري لسجل عمولة الإدارة إن وُجد',
  `payment_card_id`           INT(11)       DEFAULT NULL COMMENT 'الكارت المحفوظ المستخدم - NULL عند الدفع بكارت جديد',
  `payment_provider`          VARCHAR(30)   COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'paymob' COMMENT 'بوابة الدفع المستخدمة (paymob / بوابة أخرى مستقبلاً)',
  `payment_method`            ENUM('card','wallet','fawry','saved_card') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'card' COMMENT 'وسيلة الدفع: كارت / محفظة إلكترونية / فوري / كارت محفوظ',
  `payment_reference`         VARCHAR(100)  COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'الرقم المرجعي الداخلي للعملية (Merchant Order ID) - فريد',
  `payment_gateway_order_id`  VARCHAR(100)  COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'رقم الطلب لدى بوابة الدفع',
  `payment_gateway_txn_id`    VARCHAR(100)  COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'رقم المعاملة لدى بوابة الدفع - يُستخدم لمنع التكرار',
  `payment_amount`            DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'قيمة الحجز المدفوعة (بدون رسوم الدفع)',
  `payment_fee_type`          ENUM('percent','fixed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'percent' COMMENT 'نوع رسوم الدفع المطبقة وقت العملية',
  `payment_fee_value`         DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'قيمة/نسبة رسوم الدفع المطبقة وقت العملية',
  `payment_fee_amount`        DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'مبلغ رسوم الدفع المحسوب (1% بحد أدنى 5 جنيه افتراضياً)',
  `payment_total_amount`      DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'الإجمالي المخصوم من العميل = قيمة الحجز + رسوم الدفع',
  `payment_currency`          VARCHAR(3)    COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'EGP' COMMENT 'عملة الدفع',
  `payment_state`             ENUM('pending','processing','paid','failed','cancelled','refunded') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT 'حالة الدفع: معلق / قيد المعالجة / مدفوع / فشل / ملغي / مسترد',
  `payment_failure_reason`    VARCHAR(255)  COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'سبب فشل الدفع إن وُجد',
  `payment_gateway_response`  TEXT          COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'رد بوابة الدفع الكامل (JSON) لأغراض المراجعة والدعم',
  `payment_paid_at`           DATETIME      DEFAULT NULL COMMENT 'تاريخ ووقت تأكيد الدفع من البوابة',
  `payment_created_at`        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ ووقت إنشاء العملية',
  `payment_updated_at`        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ ووقت آخر تحديث',
  PRIMARY KEY (`payment_id`),
  UNIQUE KEY `uniq_payment_reference` (`payment_reference`),
  KEY `idx_payment_user` (`payment_user_id`),
  KEY `idx_payment_booking` (`payment_booking_id`),
  KEY `idx_payment_state` (`payment_state`),
  KEY `idx_payment_txn` (`payment_gateway_txn_id`),
  CONSTRAINT `fk_payment_user` FOREIGN KEY (`payment_user_id`) REFERENCES `users` (`users_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_payment_booking` FOREIGN KEY (`payment_booking_id`) REFERENCES `bookings` (`booking_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_payment_card` FOREIGN KEY (`payment_card_id`) REFERENCES `payment_cards` (`card_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3) جدول سجل إشعارات البوابة (payment_gateway_logs)
--    نخزّن كل Webhook/Callback قادم من Paymob قبل معالجته،
--    حتى يمكن تتبع أي عملية لم تُحدَّث حالتها لأي سبب.
-- ============================================================
CREATE TABLE IF NOT EXISTS `payment_gateway_logs` (
  `log_id`          BIGINT(20)    NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للسجل',
  `log_provider`    VARCHAR(30)   COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'paymob' COMMENT 'بوابة الدفع',
  `log_event_type`  VARCHAR(50)   COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'callback' COMMENT 'نوع الحدث: callback / webhook',
  `log_reference`   VARCHAR(100)  COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'الرقم المرجعي للعملية إن وُجد',
  `log_payload`     MEDIUMTEXT    COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'محتوى الطلب الكامل (JSON)',
  `log_hmac_valid`  TINYINT(1)    NOT NULL DEFAULT 0 COMMENT 'هل توقيع HMAC صحيح؟ 1 = نعم',
  `log_processed`   TINYINT(1)    NOT NULL DEFAULT 0 COMMENT 'هل تمت معالجة السجل بنجاح؟ 1 = نعم',
  `log_created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ ووقت الاستلام',
  PRIMARY KEY (`log_id`),
  KEY `idx_gateway_log_reference` (`log_reference`),
  KEY `idx_gateway_log_created` (`log_created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4) أعمدة الدفع على جدول bookings
--    نُكرّر حالة الدفع على الحجز لتسريع القوائم والفلترة،
--    وتُحدَّث فقط من Webhook/Callback بعد التحقق من التوقيع.
-- ============================================================
ALTER TABLE `bookings`
  ADD COLUMN `booking_payment_status` ENUM('unpaid','pending','paid','failed','refunded')
  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unpaid'
  COMMENT 'حالة دفع الحجز - تُحدَّث من بوابة الدفع فقط'
  AFTER `booking_status`;

ALTER TABLE `bookings`
  ADD COLUMN `booking_paid_at` DATETIME DEFAULT NULL
  COMMENT 'تاريخ ووقت تأكيد دفع الحجز'
  AFTER `booking_payment_status`;

-- فهرس حالة الدفع لتسريع تبويب «بانتظار الدفع» في قائمة الحجوزات
ALTER TABLE `bookings`
  ADD KEY `idx_booking_payment_status` (`booking_payment_status`);

-- ============================================================
-- 5) إعدادات عامة جديدة في app_settings
--    (أ) عمولة العميل الافتراضية = 0% (تعدّلها الإدارة)
--    (ب) رسوم الدفع = 1% بحد أدنى 5 جنيه، قابلة للتعطيل أو التعديل
--    (ج) إعدادات Paymob غير السرية (المفاتيح السرية في ملف config)
-- ============================================================

-- (أ) تصفير عمولة العميل الافتراضية (تغيير عن 10%)
INSERT INTO `app_settings` (`setting_key`, `setting_value`) VALUES
  ('client_commission_type',  'percent'),
  ('client_commission_value', '0')
ON DUPLICATE KEY UPDATE
  `setting_value` = VALUES(`setting_value`);

-- (ب) رسوم الدفع: مفعّلة، نسبة 1%، بحد أدنى 5 جنيه
INSERT INTO `app_settings` (`setting_key`, `setting_value`) VALUES
  ('payment_fee_enabled', '1'),
  ('payment_fee_type',    'percent'),
  ('payment_fee_value',   '1'),
  ('payment_fee_min',     '5')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

-- (ج) إعدادات Paymob العامة (تُملأ من لوحة تحكم Paymob)
--     api_key و hmac_secret لا تُخزَّن هنا لأسباب أمنية،
--     بل في ملف config/paymob_config.php على السيرفر.
INSERT INTO `app_settings` (`setting_key`, `setting_value`) VALUES
  ('paymob_enabled',                   '1'),
  ('paymob_currency',                  'EGP'),
  ('paymob_country',                   'EG'),
  ('paymob_integration_id_card',       ''),
  ('paymob_integration_id_wallet',     ''),
  ('paymob_integration_id_saved_card', ''),
  ('paymob_iframe_id',                 '')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

-- ============================================================
-- 6) تعديل الافتراضيات على الأعمدة القديمة
--    حتى أي حجز جديد لا يأخذ 10% تلقائياً بعد الآن.
-- ============================================================
ALTER TABLE `bookings`
  MODIFY COLUMN `booking_client_commission_value` DECIMAL(10,2) NOT NULL DEFAULT 0.00
  COMMENT 'قيمة أو نسبة عمولة العميل (B)';

ALTER TABLE `app_settings`
  MODIFY COLUMN `setting_value` VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL
  COMMENT 'قيمة الإعداد كنص';
