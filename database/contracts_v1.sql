-- ============================================================
-- ملف: database/contracts_v1.sql
-- الغرض: جداول العقود الإلكترونية/الورقية ودفعات الشركات والأفراد
--
-- الفكرة العامة:
--   1) عقد واحد لكل اتفاق مع شركة/مصنع/نشاط تجاري أو مع عميل أفراد.
--   2) عمولة التطبيق على الشركات عمولة واحدة مخفية، حدها الأدنى 10%
--      من إجمالي تكلفة العمال، والإدارة ترفعها ولا تخفضها عن 10%.
--   3) مثال الحساب:
--        10 عمال × 10 ج.م شهرياً × 12 شهراً = 1200 ج.م تكلفة العمال
--        عمولة 10% = 120 ج.م (تراها الإدارة فقط)
--        إن كان الدفع شهرياً فالمصنع يحوّل 100 ج.م كل شهر
--        وعمولة الشهر = 10 ج.م تُضاف للإيرادات.
--   4) الدفعات تُولَّد تلقائياً حسب دورة العقد (شهري/ربع سنوي/سنوي).
--   5) الدفع يتم إما من التطبيق (Paymob: كارت/محفظة) أو تسجّله الإدارة
--      يدوياً مع صورة الشيك أو سكرين إنستاباي/محفظة/تحويل بنكي.
--   6) العقد الإلكتروني مطابق للورقي، والعلامة (صح) = توقيع إلكتروني.
--      صورة العقد الورقي تُرفع اختيارياً للأرشيف.
--
-- تنفيذ الملف:
--   - نفّذ على clean_house_life_dev ثم clean_house_life_prod.
--   - الملف آمن عند التكرار (IF NOT EXISTS / ON DUPLICATE KEY).
--   - إن ظهر Duplicate column name فتجاهل سطر ذلك العمود.
-- ============================================================

-- ============================================================
-- 1) إعدادات عمولة الشركات (عمولة واحدة مخفية، حد أدنى 10%)
-- ============================================================
INSERT INTO `app_settings` (`setting_key`, `setting_value`) VALUES
  ('company_commission_enabled', '1'),
  ('company_commission_type',    'percent'),
  ('company_commission_value',   '10'),
  ('company_commission_min',     '10')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

-- ============================================================
-- 2) قوالب بنود العقد
--    الإدارة تضيف بنوداً ثابتة ثم تلصقها في أي عقد جديد.
-- ============================================================
CREATE TABLE IF NOT EXISTS `contract_clause_templates` (
  `template_id`          INT(11)      NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد لقالب البند',
  `template_party_type`  ENUM('company','individual','both') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'both' COMMENT 'لمن يصلح البند: شركات / أفراد / الاثنين',
  `template_title_ar`    VARCHAR(180) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'عنوان البند بالعربية',
  `template_body_ar`     TEXT         COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'نص البند الكامل بالعربية',
  `template_is_required` TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'هل يُدرج تلقائياً في كل عقد جديد؟ 1 = نعم',
  `template_sort`        SMALLINT     NOT NULL DEFAULT 0 COMMENT 'ترتيب العرض داخل العقد',
  `template_is_active`   TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'هل القالب مفعّل؟ 0 = مخفي دون حذف',
  `template_created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ إنشاء القالب',
  `template_updated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ آخر تعديل',
  PRIMARY KEY (`template_id`),
  KEY `idx_clause_template_party` (`template_party_type`, `template_is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='قوالب بنود العقود التي تضيفها الإدارة من لوحة التحكم';

-- ============================================================
-- 3) جدول العقود
-- ============================================================
CREATE TABLE IF NOT EXISTS `contracts` (
  `contract_id`                 INT(11)       NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للعقد',
  `contract_code`               VARCHAR(40)   COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'رقم العقد الظاهر للطباعة مثل CHL-C-2026-0001',
  `contract_party_type`         ENUM('company','individual') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'نوع الطرف: شركة/مصنع/نشاط أو عميل أفراد',
  `contract_company_id`         INT(11)       DEFAULT NULL COMMENT 'معرّف الشركة إن كان العقد تجارياً - مرتبط بـ company_registering',
  `contract_job_request_id`     INT(11)       DEFAULT NULL COMMENT 'طلب العمالة المرتبط إن وُجد - مرتبط بـ job_details_required',
  `contract_users_id`           INT(11)       DEFAULT NULL COMMENT 'معرّف العميل الأفراد إن كان العقد فردياً - مرتبط بـ users',
  `contract_booking_id`         INT(11)       DEFAULT NULL COMMENT 'الحجز الفردي المرتبط إن وُجد - مرتبط بـ bookings',
  `contract_title_ar`           VARCHAR(220)  COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'عنوان العقد بالعربية',
  `contract_workers_count`      INT(11)       NOT NULL DEFAULT 0 COMMENT 'عدد العمال المتفق عليه',
  `contract_worker_monthly_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'تكلفة العامل الواحد شهرياً بالجنيه',
  `contract_duration_months`    INT(11)       NOT NULL DEFAULT 1 COMMENT 'مدة العقد بالأشهر',
  `contract_labor_total`        DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'إجمالي تكلفة العمال = العدد × التكلفة الشهرية × المدة',
  `contract_commission_percent` DECIMAL(6,2)  NOT NULL DEFAULT 10.00 COMMENT 'نسبة عمولة التطبيق المخفية، لا تقل عن 10%',
  `contract_commission_amount`  DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'إجمالي عمولة التطبيق = تكلفة العمال × النسبة / 100 (مخفية عن الشركة)',
  `contract_insurance_on`       ENUM('app','company','shared','not_specified') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_specified' COMMENT 'على من تقع التأمينات: التطبيق / الشركة / مشتركة / غير محدد',
  `contract_payment_cycle`      ENUM('monthly','quarterly','yearly','once') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'monthly' COMMENT 'دورة التحصيل المتفق عليها',
  `contract_installments_count` INT(11)       NOT NULL DEFAULT 1 COMMENT 'عدد الدفعات خلال مدة العقد',
  `contract_installment_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'قيمة الدفعة الواحدة التي تسددها الشركة',
  `contract_collection_day`     TINYINT(2)    DEFAULT NULL COMMENT 'يوم الشهر المتفق عليه لاستلام الدفعة (1 إلى 28)',
  `contract_payment_method`     ENUM('app_card','app_wallet','bank_transfer','check','cash','instapay','mixed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'mixed' COMMENT 'وسيلة التحصيل المتفق عليها في العقد',
  `contract_start_date`         DATE          DEFAULT NULL COMMENT 'تاريخ بدء سريان العقد',
  `contract_end_date`           DATE          DEFAULT NULL COMMENT 'تاريخ انتهاء العقد',
  `contract_written_at`         DATETIME      DEFAULT NULL COMMENT 'تاريخ ووقت كتابة العقد',
  `contract_signed_at`          DATETIME      DEFAULT NULL COMMENT 'تاريخ ووقت التوقيع الإلكتروني أو الورقي',
  `contract_signed_name`        VARCHAR(180)  COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'اسم الموقّع الظاهر على العقد',
  `contract_e_signed`           TINYINT(1)    NOT NULL DEFAULT 0 COMMENT 'هل وافق الطرف بعلامة صح على البنود؟ 1 = توقيع إلكتروني',
  `contract_paper_scan`         VARCHAR(255)  COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'اسم ملف صورة/PDF العقد الورقي الموقّع يدوياً',
  `contract_pdf_file`           VARCHAR(255)  COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'اسم ملف PDF المولَّد إلكترونياً للطباعة والأرشيف',
  `contract_status`             ENUM('draft','pending_signature','signed','active','completed','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft' COMMENT 'حالة العقد: مسودة / بانتظار التوقيع / موقّع / ساري / منتهٍ / ملغي',
  `contract_notes`              TEXT          COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'ملاحظات داخلية للإدارة لا تظهر للطرف الآخر',
  `contract_created_by`         INT(11)       DEFAULT NULL COMMENT 'موظف الإدارة الذي أنشأ العقد',
  `contract_updated_by`         INT(11)       DEFAULT NULL COMMENT 'آخر موظف عدّل العقد',
  `contract_created_at`         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ إنشاء السجل',
  `contract_updated_at`         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ آخر تحديث',
  PRIMARY KEY (`contract_id`),
  UNIQUE KEY `uniq_contract_code` (`contract_code`),
  KEY `idx_contract_party` (`contract_party_type`, `contract_status`),
  KEY `idx_contract_company` (`contract_company_id`),
  KEY `idx_contract_job` (`contract_job_request_id`),
  KEY `idx_contract_user` (`contract_users_id`),
  KEY `idx_contract_booking` (`contract_booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='العقود الإلكترونية والورقية مع الشركات والأفراد';

-- ============================================================
-- 4) بنود العقد الفعلية (نسخة مثبتة وقت التوقيع)
-- ============================================================
CREATE TABLE IF NOT EXISTS `contract_clauses` (
  `clause_id`          INT(11)      NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للبند داخل العقد',
  `clause_contract_id` INT(11)      NOT NULL COMMENT 'العقد الذي ينتمي إليه البند',
  `clause_template_id` INT(11)      DEFAULT NULL COMMENT 'القالب المصدر إن وُجد، NULL إن كان بنداً مخصصاً',
  `clause_title_ar`    VARCHAR(180) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'عنوان البند كما سيظهر في العقد',
  `clause_body_ar`     TEXT         COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'نص البند كما سيظهر في العقد',
  `clause_sort`        SMALLINT     NOT NULL DEFAULT 0 COMMENT 'ترتيب البند داخل العقد',
  `clause_is_custom`   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = بند أضافته الإدارة يدوياً لهذا العقد',
  `clause_created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ إضافة البند',
  PRIMARY KEY (`clause_id`),
  KEY `idx_clause_contract` (`clause_contract_id`),
  CONSTRAINT `fk_clause_contract` FOREIGN KEY (`clause_contract_id`)
    REFERENCES `contracts` (`contract_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='بنود كل عقد بعد تثبيتها حتى لا يتغير النص بعد التوقيع';

-- ============================================================
-- 5) جدول دفعات العقد
-- ============================================================
CREATE TABLE IF NOT EXISTS `contract_installments` (
  `installment_id`              INT(11)       NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للدفعة',
  `installment_contract_id`     INT(11)       NOT NULL COMMENT 'العقد الذي تنتمي إليه الدفعة',
  `installment_number`          INT(11)       NOT NULL DEFAULT 1 COMMENT 'رقم الدفعة داخل العقد (1 = الدفعة الأولى)',
  `installment_due_date`        DATE          NOT NULL COMMENT 'تاريخ استحقاق تحصيل الدفعة من الشركة/العميل',
  `installment_amount`          DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'قيمة الدفعة المستحقة من الطرف',
  `installment_commission`      DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'عمولة التطبيق المخفية من هذه الدفعة (تُضاف للإيرادات عند التحصيل)',
  `installment_status`          ENUM('pending','due','paid','overdue','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT 'حالة الدفعة',
  `installment_paid_at`         DATETIME      DEFAULT NULL COMMENT 'تاريخ ووقت تأكيد التحصيل',
  `installment_paid_via`        ENUM('app','admin_manual') COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'من حصّل: الطرف عبر التطبيق أم الإدارة يدوياً',
  `installment_payment_id`      INT(11)       DEFAULT NULL COMMENT 'معرّف صف payments إن تم الدفع عبر Paymob',
  `installment_method`          ENUM('app_card','app_wallet','bank_transfer','check','cash','instapay') COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'وسيلة التحصيل الفعلية',
  `installment_evidence_file`   VARCHAR(255)  COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'صورة الشيك أو سكرين إنستاباي/المحفظة/التحويل عند الدفع اليدوي',
  `installment_evidence_type`   ENUM('check','instapay','wallet','bank_transfer','cash','other') COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'نوع مستند الإثبات',
  `installment_admin_note`      VARCHAR(255)  COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'ملاحظة الإدارة على التحصيل',
  `installment_collected_by`    INT(11)       DEFAULT NULL COMMENT 'موظف الإدارة الذي سجّل التحصيل اليدوي',
  `installment_created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ إنشاء جدول الدفعة',
  `installment_updated_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ آخر تحديث',
  PRIMARY KEY (`installment_id`),
  UNIQUE KEY `uniq_contract_installment_no` (`installment_contract_id`, `installment_number`),
  KEY `idx_installment_due` (`installment_due_date`, `installment_status`),
  CONSTRAINT `fk_installment_contract` FOREIGN KEY (`installment_contract_id`)
    REFERENCES `contracts` (`contract_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='جدول دفعات العقود على مدار مدة العقد مع عمولة كل دفعة';

-- ============================================================
-- 6) سجل التوقيع الإلكتروني (علامة صح = موافقة باسم الموقّع)
-- ============================================================
CREATE TABLE IF NOT EXISTS `contract_signatures` (
  `sign_id`          INT(11)      NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للتوقيع',
  `sign_contract_id` INT(11)      NOT NULL COMMENT 'العقد الموقَّع',
  `sign_users_id`    INT(11)      DEFAULT NULL COMMENT 'معرّف المستخدم إن وقّع من التطبيق',
  `sign_admin_id`    INT(11)      DEFAULT NULL COMMENT 'معرّف الموظف إن ثبّت التوقيع الورقي من اللوحة',
  `sign_name`        VARCHAR(180) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'الاسم الذي يُعد بمثابة التوقيع',
  `sign_accepted`    TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1 = وافق بعلامة صح على كل البنود',
  `sign_channel`     ENUM('app','admin_paper') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'app' COMMENT 'قناة التوقيع: تطبيق أو تثبيت ورقي من الإدارة',
  `sign_ip`          VARCHAR(45)  COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'عنوان IP وقت الموافقة الإلكترونية',
  `sign_user_agent`  VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'متصفح/جهاز الموقّع لأغراض الإثبات',
  `sign_created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ ووقت التوقيع',
  PRIMARY KEY (`sign_id`),
  KEY `idx_sign_contract` (`sign_contract_id`),
  CONSTRAINT `fk_sign_contract` FOREIGN KEY (`sign_contract_id`)
    REFERENCES `contracts` (`contract_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='إثبات الموافقة الإلكترونية على بنود العقد';

-- ============================================================
-- 7) ربط الدفعات بجدول payments الحالي دون كسر حجوزات الأفراد
-- ============================================================
ALTER TABLE `payments`
  ADD COLUMN `payment_contract_id` INT(11) DEFAULT NULL
    COMMENT 'معرّف العقد إن كانت العملية دفعة شركة/أفراد على عقد'
    AFTER `payment_booking_id`;

ALTER TABLE `payments`
  ADD COLUMN `payment_installment_id` INT(11) DEFAULT NULL
    COMMENT 'معرّف دفعة العقد المرتبطة بهذه العملية'
    AFTER `payment_contract_id`;

ALTER TABLE `payments`
  ADD KEY `idx_payment_contract` (`payment_contract_id`);

-- جعل الحجز اختيارياً حتى تُستخدم نفس الجدول لدفعة عقد بلا حجز
ALTER TABLE `payments`
  MODIFY COLUMN `payment_booking_id` INT(11) DEFAULT NULL
  COMMENT 'الحجز المدفوع إن وُجد - NULL عند دفع دفعة عقد شركة';

-- ============================================================
-- 8) صلاحيات الإدارة للعقود والمدفوعات
-- ============================================================
INSERT INTO `admin_permissions`
  (`perm_key`, `perm_name_ar`, `perm_group_ar`, `perm_sort`)
VALUES
  ('contracts.view',    'عرض العقود',           'العقود والمدفوعات', 1),
  ('contracts.create',  'إنشاء عقد وكتابة بنود', 'العقود والمدفوعات', 2),
  ('contracts.edit',    'تعديل العقود',          'العقود والمدفوعات', 3),
  ('contracts.approve', 'اعتماد وتفعيل العقود',  'العقود والمدفوعات', 4),
  ('payments.view',     'عرض مدفوعات العقود',    'العقود والمدفوعات', 5),
  ('payments.manage',   'تسجيل تحصيل يدوي',      'العقود والمدفوعات', 6)
ON DUPLICATE KEY UPDATE
  `perm_name_ar`  = VALUES(`perm_name_ar`),
  `perm_group_ar` = VALUES(`perm_group_ar`);

-- منح الصلاحيات للمالك والمدير
INSERT IGNORE INTO `admin_role_permissions` (`rp_role_id`, `rp_perm_id`)
SELECT r.role_id, p.perm_id
FROM `admin_roles` r
CROSS JOIN `admin_permissions` p
WHERE r.role_key IN ('owner', 'manager')
  AND p.perm_key IN (
    'contracts.view','contracts.create','contracts.edit','contracts.approve',
    'payments.view','payments.manage'
  );

-- المحاسب: عرض + تسجيل تحصيل
INSERT IGNORE INTO `admin_role_permissions` (`rp_role_id`, `rp_perm_id`)
SELECT r.role_id, p.perm_id
FROM `admin_roles` r
CROSS JOIN `admin_permissions` p
WHERE r.role_key = 'accountant'
  AND p.perm_key IN ('contracts.view', 'payments.view', 'payments.manage');

-- خدمة العملاء: عرض فقط
INSERT IGNORE INTO `admin_role_permissions` (`rp_role_id`, `rp_perm_id`)
SELECT r.role_id, p.perm_id
FROM `admin_roles` r
CROSS JOIN `admin_permissions` p
WHERE r.role_key = 'support'
  AND p.perm_key IN ('contracts.view', 'payments.view');

-- ============================================================
-- 9) بنود افتراضية تقترحها المنصة ويمكن للإدارة تعديلها
-- ============================================================
INSERT INTO `contract_clause_templates`
  (`template_party_type`, `template_title_ar`, `template_body_ar`, `template_is_required`, `template_sort`)
VALUES
  ('company', 'موضوع العقد',
   'اتفق الطرفان على أن يورّد التطبيق للشركة/المصنع/النشاط التجاري العمالة المتفق عليها وفق العدد والمدة والتكلفة المبينة في صدر هذا العقد.',
   1, 1),
  ('company', 'عدد العمال ومدة العمل',
   'يُحدَّد عدد العمال وإجمالي فترة عملهم وتكلفة العامل الشهرية في صدر العقد، ويُحسب إجمالي تكلفة العمال بضرب عدد العمال في التكلفة الشهرية ثم في مدة العقد بالأشهر.',
   1, 2),
  ('company', 'التأمينات والالتزامات النظامية',
   'يُحدَّد في صدر العقد على من تقع التأمينات الخاصة بالعمال (التطبيق أو الشركة أو بالتساوي). ويلتزم كل طرف بما يخصه من اشتراكات وتأمينات وفق القانون المصري.',
   1, 3),
  ('company', 'جدول الدفعات والتحصيل',
   'تسدد الشركة الدفعات في اليوم المتفق عليه من كل دورة (شهري/ربع سنوي/سنوي) بشيك بنكي مستحق في ذلك التاريخ أو بتحويل بنكي أو محفظة إلكترونية أو كارت عبر التطبيق.',
   1, 4),
  ('company', 'عمولة التطبيق',
   'عمولة التطبيق نسبة من إجمالي تكلفة العمال لا تقل عن 10%، وتُحدَّد بمعرفة الإدارة ولا تظهر للشركة في واجهة الاستخدام. تُحتسب عمولة كل دفعة عند تحصيلها وتُضاف لإيرادات التطبيق.',
   1, 5),
  ('company', 'السرية وعدم الإفصاح',
   'يلتزم الطرفان بعدم إفشاء أي بيانات عمالية أو مالية أو تعاقدية للغير إلا بما يقتضيه القانون أو موافقة كتابية مسبقة.',
   1, 6),
  ('company', 'فسخ العقد',
   'يجوز لأي طرف طلب فسخ العقد مع إخطار كتابي قبل ثلاثين يوماً، مع تسوية الدفعات المستحقة حتى تاريخ الفسخ.',
   0, 7),
  ('company', 'القانون الواجب التطبيق',
   'يخضع هذا العقد لأحكام القانون المصري، وتختص المحاكم المصرية بالنظر في أي نزاع ينشأ عنه.',
   1, 8),
  ('individual', 'موضوع العقد مع العميل',
   'اتفق الطرفان على تقديم الخدمة المنزلية المتفق عليها (مثل رعاية أطفال بإقامة كاملة أو ما يماثلها) وفق تفاصيل الحجز المرفق.',
   1, 1),
  ('individual', 'مدة الخدمة والأجر',
   'تُحدَّد مدة الخدمة والأجر المتفق عليه في صدر العقد وفي حجز العميل. أي تعديل يتم بموافقة الطرفين والإدارة.',
   1, 2),
  ('individual', 'التزامات العميل',
   'يلتزم العميل بتوفير بيئة عمل آمنة، وسداد المستحقات في مواعيدها، واحترام بنود الخدمة المتفق عليها.',
   1, 3),
  ('individual', 'التوقيع الإلكتروني',
   'تُعد الموافقة بعلامة صح على هذه البنود من داخل التطبيق بمثابة توقيع إلكتروني باسم العميل، ولها حجية التوقيع الورقي.',
   1, 4),
  ('both', 'نسخ العقد',
   'يُحرَّر هذا العقد إلكترونياً، ويجوز طبع نسخة ورقية للتوقيع اليدوي وأرشفتها كصورة أو ملف PDF بجانب النسخة الإلكترونية.',
   1, 99)
ON DUPLICATE KEY UPDATE `template_body_ar` = VALUES(`template_body_ar`);
