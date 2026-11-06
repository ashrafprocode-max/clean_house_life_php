
===================================================================================
جدول provider_services (تحديث)
هذا هو الجدول الذي يربط "المقدم" بـ "الخدمة" بـ "التوقيت". سنضيف فيه السعر والعمولة
===================================================================================
CREATE TABLE IF NOT EXISTS `provider_services` (
  `ps_id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد',
  `ps_user_id` INT(11) NOT NULL COMMENT 'من جدول users مقدم الخدمة',
  `ps_cat_id` INT(11) NOT NULL COMMENT 'ربط مقدم الخدمة بالقسم الرئيسي',
  `ps_sub_cat_id` INT(11) NOT NULL COMMENT 'الخدمة (القسم الفرعي كلي أو جزئي)',
  `ps_timing_id` INT(11) NOT NULL COMMENT 'التوقيت (مثل إقامة كاملة)',
  `ps_price_estimate` DECIMAL(10,2) DEFAULT NULL COMMENT 'السعر التقريبي',
  `ps_price` DECIMAL(10,2) NOT NULL COMMENT 'السعر الذي يحدده المقدم',
  `ps_negotiable` TINYINT(1) DEFAULT 0 COMMENT 'قابل للتفاوض: 0 = لا، 1 = نعم',
  `ps_commission_type` ENUM('fixed','percent') COLLATE utf8mb4_general_ci DEFAULT 'percent' COMMENT 'نوع العمولة',
  `ps_commission_value` DECIMAL(10,2) DEFAULT 10.00 COMMENT 'قيمة العمولة (مثلاً 10%)',
  PRIMARY KEY (`ps_id`),
  KEY `ps_user_id` (`ps_user_id`),
  KEY `ps_cat_id` (`ps_cat_id`),
  KEY `ps_sub_cat_id` (`ps_sub_cat_id`),
  KEY `ps_timing_id` (`ps_timing_id`),
  CONSTRAINT `provider_services_ibfk_1` FOREIGN KEY (`ps_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `provider_services_ibfk_2` FOREIGN KEY (`ps_sub_cat_id`) REFERENCES `sub_categories` (`sub_id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `provider_services_ibfk_3` FOREIGN KEY (`ps_timing_id`) REFERENCES `service_timing` (`timing_id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `provider_services_ibfk_4` FOREIGN KEY (`ps_cat_id`) REFERENCES `categories` (`categories_id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;   