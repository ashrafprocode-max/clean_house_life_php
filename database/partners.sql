-- ============================================================
-- 1. جدول الشركاء (محدث مع رأس المال)
-- ============================================================
CREATE TABLE IF NOT EXISTS `partners` (
  `partner_id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للشريك',
  `partner_name` VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'اسم الشريك',
  `partner_share_percentage` DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'نسبة حصة الشريك من الأرباح',
  `partner_capital` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'رأس مال الشريك المدفوع',
  `partner_phone` VARCHAR(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'رقم الهاتف',
  `partner_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ التسجيل',
  PRIMARY KEY (`partner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;