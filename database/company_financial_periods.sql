-- ============================================================
-- 4. جدول الأرباح والخسائر والفترات المالية الشهرية
-- ============================================================
CREATE TABLE IF NOT EXISTS `company_financial_periods` (
  `period_id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للفترة',
  `period_month` VARCHAR(7) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'الشهر المالي بصيغة YYYY-MM',
  `total_revenues` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'إجمالي الإيرادات',
  `total_expenses` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'إجمالي المصروفات',
  `total_salaries` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'إجمالي المرتبات',
  `total_taxes` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'إجمالي الضرائب',
  `net_profit` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'صافي الربح النهائي للتوزيع',
  `period_status` ENUM('open','closed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open' COMMENT 'حالة الفترة المالية',
  `period_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
  PRIMARY KEY (`period_id`),
  UNIQUE KEY `uq_period_month` (`period_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;