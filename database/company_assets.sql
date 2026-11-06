-- ============================================================
-- 2. جدول أصول الشركة (محدث ليشمل أصول البرمجيات والتطوير)
-- ============================================================
CREATE TABLE IF NOT EXISTS `company_assets` (
  `asset_id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للأصل',
  `asset_name` VARCHAR(150) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'اسم الأصل (مثل: تطوير تطبيق Clean House Life، سيرفرات، معدات مكتبية)',
  `asset_type` ENUM('software_development','hardware','furniture','other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'software_development' COMMENT 'نوع الأصل',
  `asset_value` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'القيمة المالية للأصل',
  `asset_purchase_date` DATE NOT NULL COMMENT 'تاريخ التأسيس أو الشراء',
  `asset_notes` TEXT COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'ملاحظات وصفية',
  `asset_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإضافة بالنظام',
  PRIMARY KEY (`asset_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;