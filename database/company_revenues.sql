-- ============================================================
-- 2. جدول الإيرادات (عمولات الحجوزات، إعلانات، وأي عوائد أخرى)
-- ============================================================
CREATE TABLE IF NOT EXISTS `company_revenues` (
  `revenue_id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للإيراد',
  `revenue_source` ENUM('booking_commission','ads','featured_profile','subscription','other') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'مصدر الإيراد (عمولة حجز، إعلانات، ملف مميز، اشتراكات)',
  `revenue_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'قيمة الإيراد المحصل',
  `revenue_date` DATE NOT NULL COMMENT 'تاريخ تحصيل الإيراد',
  `reference_id` INT UNSIGNED DEFAULT NULL COMMENT 'معرف مرجعي اختياري (مثل booking_id إذا كان عمولة حجز)',
  `revenue_notes` TEXT COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'تفاصيل أو ملاحظات إضافية',
  `revenue_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ ووقت تسجيل الإيراد',
  PRIMARY KEY (`revenue_id`),
  KEY `idx_revenue_source` (`revenue_source`),
  KEY `idx_revenue_date` (`revenue_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;