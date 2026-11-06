-- ============================================================
-- 1. جدول المصروفات (محدث ليشمل التسويق بمختلف أنواعه)
-- ============================================================
CREATE TABLE IF NOT EXISTS `company_expenses` (
  `expense_id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للمصروف',
  `expense_category` ENUM('rent','servers_hosting','utilities','digital_marketing','offline_marketing','operational','other') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'تصنيف المصروف (إيجار، سيرفرات، مرافق، تسويق رقمي، تسويق ميداني/يافطات/تلفزيون، أخرى)',
  `expense_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'قيمة المبلغ المنصرف',
  `expense_date` DATE NOT NULL COMMENT 'تاريخ صرف المبلغ',
  `expense_title` VARCHAR(150) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'عنوان أو بيان المصروف',
  `expense_notes` TEXT COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'ملاحظات تفصيلية أو تفاصيل الحملة الإعلانية',
  `expense_created_by` INT UNSIGNED DEFAULT NULL COMMENT 'معرف الموظف الذي سجل المصروف',
  `expense_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ ووقت التسجيل',
  PRIMARY KEY (`expense_id`),
  KEY `idx_expense_category` (`expense_category`),
  KEY `idx_expense_date` (`expense_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;