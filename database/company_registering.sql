CREATE TABLE `company_registering` (
  `company_id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'معرف فريد لكل شركة أو نشاط تجاري مسجل',
  `company_user_id` INT UNSIGNED NOT NULL COMMENT 'معرف المستخدم صاحب الحساب المرتبط بهذا النشاط',
  `company_business_type` ENUM('company', 'factory', 'commercial_activity') NOT NULL COMMENT 'نوع النشاط: شركة، مصنع، أو نشاط تجاري',
  `company_name` VARCHAR(255) NOT NULL COMMENT 'اسم الشركة أو المصنع أو النشاط التجاري (إجباري)',
  `company_headquarters_address` TEXT NOT NULL COMMENT 'عنوان مقر الشركة أو النشاط بالتفصيل (إجباري)',
  `company_phone_number` VARCHAR(50) NOT NULL COMMENT 'رقم الهاتف الأساسي للتواصل مع النشاط (إجباري)',
  `company_secondary_phone` VARCHAR(50) DEFAULT NULL COMMENT 'رقم هاتف إضافي آخر للشركة (اختياري)',
  `company_commercial_register_pdf` VARCHAR(255) DEFAULT NULL COMMENT 'مسار أو رابط ملف السجل التجاري بصيغة PDF (اختياري)',
  `company_tax_card_pdf` VARCHAR(255) DEFAULT NULL COMMENT 'مسار أو رابط ملف البطاقة الضريبية بصيغة PDF (اختياري)',
  `company_created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ ووقت تسجيل البيانات',
  `company_updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ ووقت آخر تحديث لبيانات النشاط'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='جدول حفظ بيانات الشركات والمصانع والأنشطة التجارية';


-- ============================================================
-- ملف: docs/sql/fix_company_type_alias.sql
-- الغرض: إصلاح خطأ العقود 1054 Unknown column cr.company_type
--
-- السبب:
--   admin/contracts/list_contracts.php (وقائمة الدفعات)
--   يختار cr.company_type من جدول company_registering
--   بينما العمود الحقيقي منذ التسجيل هو company_business_type
--
-- الحل:
--   عمود ظاهري company_type يعيد نفس قيمة company_business_type
--   فلا نكسر ملفات التسجيل التي تكتب company_business_type
--
-- نفّذ مرة واحدة على قاعدة التطوير ثم الإنتاج من phpMyAdmin
-- ============================================================

SET @db := DATABASE();

SET @exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'company_registering'
    AND COLUMN_NAME = 'company_type'
);

SET @sql := IF(
  @exists = 0,
  'ALTER TABLE `company_registering`
     ADD COLUMN `company_type` VARCHAR(32)
       GENERATED ALWAYS AS (`company_business_type`) VIRTUAL
       COMMENT ''اسم بديل لـ company_business_type لتوافق استعلامات العقود''
     AFTER `company_business_type`',
  'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;