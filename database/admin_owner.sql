-- ============================================================
-- ملف: database/admin_owner.sql
-- الغرض: جداول قسم المالك (موظفون تشغيليون، شركاء، فترات،
--        إيرادات، أصول) + توسيع المصروفات + صلاحيات المالك
--
-- ⚠️ نفّذ على clean_house_life_dev و clean_house_life_prod معاً
-- ⚠️ السكربت idempotent: تكراره لا يُنشئ أعمدة ولا صفوفاً مكررة
-- ⚠️ جدول admin_users يبقى لدخول اللوحة — employees منفصل عنه
-- ============================================================

SET @db := DATABASE();

-- ============================================================
-- الخطوة 1: جدول الموظفين التشغيليين (ليس مستخدمي اللوحة)
-- ============================================================
CREATE TABLE IF NOT EXISTS `employees` (
  `employee_id`                 INT(11)         NOT NULL AUTO_INCREMENT,
  `employee_full_name`          VARCHAR(100)    NOT NULL COMMENT 'الاسم الكامل',
  `employee_phone`              VARCHAR(20)     NOT NULL COMMENT 'رقم الهاتف',
  `employee_whatsapp`           VARCHAR(20)     DEFAULT NULL COMMENT 'واتساب — إن فُرغ يُستخدم الهاتف',
  `employee_job_code`           VARCHAR(30)     DEFAULT NULL COMMENT 'كود الوظيفة الفريد',
  `employee_job_title`          VARCHAR(100)    DEFAULT NULL COMMENT 'المسمى الوظيفي',
  `employee_national_id`        VARCHAR(20)     DEFAULT NULL COMMENT 'الرقم القومي',
  `employee_hire_date`          DATE            DEFAULT NULL COMMENT 'تاريخ التعيين',
  `employee_employment_type`    ENUM('full_time','part_time','contract')
                                NOT NULL DEFAULT 'full_time' COMMENT 'نوع التعاقد',
  `employee_base_salary`        DECIMAL(12,2)   NOT NULL DEFAULT 0.00 COMMENT 'الراتب الأساسي الشهري',
  `employee_bank_account`       VARCHAR(50)     DEFAULT NULL COMMENT 'رقم الحساب البنكي',
  `employee_notes`              TEXT            DEFAULT NULL COMMENT 'ملاحظات المالك',
  `employee_id_card_front`      VARCHAR(255)    DEFAULT NULL COMMENT 'صورة البطاقة الأمامية',
  `employee_id_card_back`       VARCHAR(255)    DEFAULT NULL COMMENT 'صورة البطاقة الخلفية',
  `employee_criminal_record`    VARCHAR(255)    DEFAULT NULL COMMENT 'فيش وتشبيه',
  `employee_contract_pdf`       VARCHAR(255)    DEFAULT NULL COMMENT 'عقد العمل PDF',
  `employee_is_active`          TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '1 مفعّل / 0 معطّل',
  `employee_created_by`         INT(11)         DEFAULT NULL COMMENT 'admin_id الذي أنشأ الصف',
  `employee_created_at`         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `employee_updated_at`         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`employee_id`),
  UNIQUE KEY `uq_employee_phone` (`employee_phone`),
  UNIQUE KEY `uq_employee_job_code` (`employee_job_code`),
  KEY `idx_employee_active` (`employee_is_active`),
  KEY `idx_employee_hire` (`employee_hire_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='موظفون تشغيليون يديرهم المالك — منفصلون عن admin_users';

-- ============================================================
-- الخطوة 2: أعمدة مستندات الموظف إن وُجد الجدول بلاها
-- ============================================================
SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'employee_id_card_front'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE `employees` ADD COLUMN `employee_id_card_front` VARCHAR(255) DEFAULT NULL COMMENT ''صورة البطاقة الأمامية'' AFTER `employee_notes`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'employee_id_card_back'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE `employees` ADD COLUMN `employee_id_card_back` VARCHAR(255) DEFAULT NULL COMMENT ''صورة البطاقة الخلفية'' AFTER `employee_id_card_front`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'employee_criminal_record'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE `employees` ADD COLUMN `employee_criminal_record` VARCHAR(255) DEFAULT NULL COMMENT ''فيش وتشبيه'' AFTER `employee_id_card_back`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'employee_contract_pdf'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE `employees` ADD COLUMN `employee_contract_pdf` VARCHAR(255) DEFAULT NULL COMMENT ''عقد العمل PDF'' AFTER `employee_criminal_record`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================
-- الخطوة 3: المعاملات المالية للموظف (راتب / سلفة / خصم / مكافأة)
-- ============================================================
CREATE TABLE IF NOT EXISTS `employee_financial_transactions` (
  `eft_id`            INT(11)         NOT NULL AUTO_INCREMENT,
  `eft_employee_id`   INT(11)         NOT NULL COMMENT 'الموظف المستحق',
  `eft_type`          ENUM('salary','advance','deduction','bonus','allowance')
                      NOT NULL DEFAULT 'salary' COMMENT 'نوع المعاملة',
  `eft_amount`        DECIMAL(12,2)   NOT NULL DEFAULT 0.00 COMMENT 'المبلغ',
  `eft_month`         TINYINT UNSIGNED DEFAULT NULL COMMENT 'شهر الاستحقاق 1-12',
  `eft_year`          SMALLINT UNSIGNED DEFAULT NULL COMMENT 'سنة الاستحقاق',
  `eft_date`          DATE            NOT NULL COMMENT 'تاريخ المعاملة',
  `eft_expense_id`    INT(11)         DEFAULT NULL COMMENT 'مصروف الشركة المرتبط إن وُجد',
  `eft_notes`         VARCHAR(255)    DEFAULT NULL,
  `eft_created_by`    INT(11)         DEFAULT NULL,
  `eft_created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`eft_id`),
  KEY `idx_eft_employee` (`eft_employee_id`),
  KEY `idx_eft_period` (`eft_year`, `eft_month`),
  KEY `idx_eft_type` (`eft_type`),
  CONSTRAINT `fk_eft_employee` FOREIGN KEY (`eft_employee_id`)
    REFERENCES `employees` (`employee_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='سجل رواتب وسلف وخصومات ومكافآت الموظفين التشغيليين';

-- ============================================================
-- الخطوة 4: الشركاء وحصصهم
-- ============================================================
CREATE TABLE IF NOT EXISTS `partners` (
  `partner_id`            INT(11)         NOT NULL AUTO_INCREMENT,
  `partner_full_name`     VARCHAR(100)    NOT NULL COMMENT 'اسم الشريك',
  `partner_phone`         VARCHAR(20)     DEFAULT NULL COMMENT 'هاتف الشريك',
  `partner_share_percent` DECIMAL(5,2)    NOT NULL DEFAULT 0.00 COMMENT 'نسبة الحصة من 0 إلى 100',
  `partner_notes`         TEXT            DEFAULT NULL,
  `partner_is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
  `partner_created_by`    INT(11)         DEFAULT NULL,
  `partner_created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `partner_updated_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`partner_id`),
  KEY `idx_partner_active` (`partner_is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='شركاء الشركة وحصصهم النسبية';

-- ============================================================
-- الخطوة 5: الفترات المالية الشهرية
-- ============================================================
CREATE TABLE IF NOT EXISTS `company_financial_periods` (
  `period_id`         INT(11)         NOT NULL AUTO_INCREMENT,
  `period_year`       SMALLINT UNSIGNED NOT NULL COMMENT 'سنة الفترة',
  `period_month`      TINYINT UNSIGNED NOT NULL COMMENT 'شهر الفترة 1-12',
  `period_status`     ENUM('open','closed') NOT NULL DEFAULT 'open',
  `period_notes`      VARCHAR(255)    DEFAULT NULL,
  `period_created_by` INT(11)         DEFAULT NULL,
  `period_closed_by`  INT(11)         DEFAULT NULL,
  `period_closed_at`  DATETIME        DEFAULT NULL,
  `period_created_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `period_updated_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`period_id`),
  UNIQUE KEY `uq_period_year_month` (`period_year`, `period_month`),
  KEY `idx_period_status` (`period_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='فترات مالية شهرية للأرباح والخسائر';

-- ============================================================
-- الخطوة 6: توسيع company_expenses الموجود بعمود الفترة
-- ============================================================
SET @exists := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'company_expenses'
);
SET @sql := IF(@exists = 0,
  'CREATE TABLE `company_expenses` (
     `expense_id` INT(11) NOT NULL AUTO_INCREMENT,
     `expense_category` ENUM(''salary'',''rent'',''utilities'',''marketing'',''supplies'',''transport'',''maintenance'',''other'') NOT NULL DEFAULT ''other'',
     `expense_title` VARCHAR(150) NOT NULL,
     `expense_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
     `expense_date` DATE NOT NULL,
     `expense_status` ENUM(''pending'',''paid'',''cancelled'') NOT NULL DEFAULT ''paid'',
     `expense_salary_id` INT(11) DEFAULT NULL,
     `expense_period_id` INT(11) DEFAULT NULL,
     `expense_notes` TEXT DEFAULT NULL,
     `expense_created_by` INT(11) DEFAULT NULL,
     `expense_created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
     `expense_updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
     PRIMARY KEY (`expense_id`),
     KEY `idx_expense_date` (`expense_date`),
     KEY `idx_expense_status` (`expense_status`),
     KEY `idx_expense_category` (`expense_category`)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'company_expenses' AND COLUMN_NAME = 'expense_period_id'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE `company_expenses` ADD COLUMN `expense_period_id` INT(11) DEFAULT NULL COMMENT ''الفترة المالية المرتبطة'' AFTER `expense_salary_id`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================
-- الخطوة 7: إيرادات الشركة اليدوية (بجانب عمولات الحجوزات)
-- ============================================================
CREATE TABLE IF NOT EXISTS `company_revenues` (
  `revenue_id`          INT(11)         NOT NULL AUTO_INCREMENT,
  `revenue_category`    ENUM('commission','booking','partnership','other')
                        NOT NULL DEFAULT 'other' COMMENT 'تصنيف الإيراد',
  `revenue_title`       VARCHAR(150)    NOT NULL,
  `revenue_amount`      DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `revenue_date`        DATE            NOT NULL,
  `revenue_status`      ENUM('pending','received','cancelled') NOT NULL DEFAULT 'received',
  `revenue_booking_id`  INT(11)         DEFAULT NULL COMMENT 'حجز مرتبط إن وُجد',
  `revenue_period_id`   INT(11)         DEFAULT NULL,
  `revenue_notes`       TEXT            DEFAULT NULL,
  `revenue_created_by`  INT(11)         DEFAULT NULL,
  `revenue_created_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `revenue_updated_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`revenue_id`),
  KEY `idx_revenue_date` (`revenue_date`),
  KEY `idx_revenue_status` (`revenue_status`),
  KEY `idx_revenue_category` (`revenue_category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='إيرادات يدوية يسجّلها المالك';

-- ============================================================
-- الخطوة 8: أصول الشركة
-- ============================================================
CREATE TABLE IF NOT EXISTS `company_assets` (
  `asset_id`            INT(11)         NOT NULL AUTO_INCREMENT,
  `asset_name`          VARCHAR(150)    NOT NULL,
  `asset_category`      ENUM('equipment','vehicle','furniture','electronics','property','other')
                        NOT NULL DEFAULT 'other',
  `asset_value`         DECIMAL(12,2)   NOT NULL DEFAULT 0.00 COMMENT 'قيمة الشراء أو الدفترية',
  `asset_purchase_date` DATE            DEFAULT NULL,
  `asset_status`        ENUM('active','sold','written_off') NOT NULL DEFAULT 'active',
  `asset_notes`         TEXT            DEFAULT NULL,
  `asset_created_by`    INT(11)         DEFAULT NULL,
  `asset_created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `asset_updated_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`asset_id`),
  KEY `idx_asset_status` (`asset_status`),
  KEY `idx_asset_category` (`asset_category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='أصول الشركة التي يتابعها المالك';

-- ============================================================
-- الخطوة 9: صلاحيات قسم المالك
-- الحماية الحقيقية تبقى checkAdminToken('owner')
-- ============================================================
INSERT INTO `admin_permissions`
  (`perm_key`, `perm_name_ar`, `perm_group_ar`, `perm_sort`)
VALUES
  ('owner.employees', 'إدارة الموظفين التشغيليين', 'المالك', 1),
  ('owner.partners',  'إدارة الشركاء',             'المالك', 2),
  ('owner.finance',   'فترات ومصروفات وإيرادات وأصول', 'المالك', 3),
  ('owner.stats',     'إحصاءات التشغيل والأرباح',  'المالك', 4)
ON DUPLICATE KEY UPDATE
  `perm_name_ar`  = VALUES(`perm_name_ar`),
  `perm_group_ar` = VALUES(`perm_group_ar`);

-- المالك يأخذ كل الصلاحيات بما فيها الجديدة
INSERT IGNORE INTO `admin_role_permissions` (`rp_role_id`, `rp_perm_id`)
SELECT r.role_id, p.perm_id
FROM `admin_roles` r
CROSS JOIN `admin_permissions` p
WHERE r.role_key = 'owner';
