========================================
2. جدول المستخدمين (نظام الـ Auth) -users
هذا الجدول هو "الأب" لكل من يتعامل مع التطبيق.
========================================
CREATE TABLE IF NOT EXISTS `users` (
  `users_id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للمستخدم',
  `users_full_name` VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'الاسم الكامل',
  `users_phone` VARCHAR(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'رقم الهاتـف',
  `users_password` VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'كلمة المرور المشفرة',
  `users_reset_token` VARCHAR(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'رمز إعادة تعيين كلمة المرور',
  `users_reset_token_expires` DATETIME DEFAULT NULL COMMENT 'تاريخ ووقت انتهاء صلاحية رمز إعادة التعيين',
  `users_country` VARCHAR(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'الدولة',
  `users_region` VARCHAR(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'المنطقة أو المحافظة',
  `users_is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'تفعيل/تعطيل الحساب من قبل الإدارة',
  `users_approve` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'OTP لتفعيل رقم الهاتف الجوال',
  `users_type` ENUM('client','provider') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'نوع المستخدم (عميل أم مقدم خدمة)',
  `users_created_by_admin` INT(11) DEFAULT NULL COMMENT 'معرف الموظف الذي أنشأ الحساب نيابة عن المستخدم',
  `users_password_is_temp` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'كلمة مرور مؤقتة من الإدارة - يجب تغيرها',
  `users_created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ ووقت إنشاء الحساب',
  PRIMARY KEY (`users_id`),
  UNIQUE KEY `phone` (`users_phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

