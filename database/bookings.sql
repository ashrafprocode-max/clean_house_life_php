CREATE TABLE `bookings` (
  `booking_id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_users_id` int(11) NOT NULL COMMENT 'مرتبط بجدول users',
  `booking_ps_id` int(11) NOT NULL COMMENT 'مرتبط بجدول provider_services',
  `booking_order_details` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'تفاصيل الخدمة مثل تنظيف عميق - 3 غرف',
  `booking_ps_price` decimal(10,2) NOT NULL COMMENT 'السعر القادم تلقائيا من جدول provider_services',
  `booking_ps_negotiable` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'هل هناك تفاوض متاح 0 أو 1',
  `booking_ps_commission_type` enum('fixed','percent') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'percent' COMMENT 'نوع العمولة',
  `booking_ps_commission_value` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'قيمة أو نسبة العمولة',
  `booking_total_price` decimal(10,2) NOT NULL COMMENT 'إجمالي السعر بعد حساب العمولة',
  `booking_confirmation` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'تأكيد الحجز من قبل العميل 0 أو 1',
  `booking_vailability_status` datetime DEFAULT NULL COMMENT 'تاريخ ووقت بدء عمل مقدم الخدمة',
  `booking_approval` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'تأكيد الحجز من قبل الإدارة 0 أو 1',
  `booking_status` enum('pending','negotiating','approved','in_progress','completed','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT 'حالة الطلب العامة',
  `booking_admin_notes` text COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'ملاحظات الإدارة أو سبب تعديل السعر',
  `booking_created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `booking_updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`booking_id`),
  KEY `booking_users_id` (`booking_users_id`),
  KEY `booking_ps_id` (`booking_ps_id`),
  CONSTRAINT `fk_booking_user` FOREIGN KEY (`booking_users_id`) REFERENCES `users` (`users_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_booking_provider_service` FOREIGN KEY (`booking_ps_id`) REFERENCES `provider_services` (`ps_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



ALTER TABLE `bookings` 
ADD COLUMN `booking_number_of_requests` INT(11) NOT NULL DEFAULT 0 
COMMENT 'مجموع عدد المرات التي قام فيها العملاء بطلب وcompleted للخدمة' 
AFTER `booking_id`;

-- ============================================================
-- ملف: database/bookings_admin_columns.sql
-- الغرض: أعمدة الإدارة الناقصة على جدول bookings
--
-- ⚠️ نفّذ على clean_house_life_dev و clean_house_life_prod معاً
-- ⚠️ إذا ظهر Duplicate column name فتجاهل ذلك العمود لأنه موجود مسبقاً
-- ============================================================
 
-- ⭐ معرّف كوبون الخصم (0 = بدون كوبون)
-- يستخدمه edit_booking.php لإعادة حساب الإجمالي من العمولة فقط
ALTER TABLE `bookings`
  ADD COLUMN `booking_coupon` INT(11) NOT NULL DEFAULT 0
  COMMENT 'معرّف كوبون الخصم من جدول coupon — 0 بدون كوبون'
  AFTER `booking_total_price`;
 
-- ⭐ الموظف الذي راجع الحجز أو فتحه من اللوحة
ALTER TABLE `bookings`
  ADD COLUMN `booking_reviewed_by` INT(11) DEFAULT NULL
  COMMENT 'معرّف الموظف الذي راجع أو فتح الحجز'
  AFTER `booking_admin_notes`;
 
-- ⭐ تاريخ ووقت آخر مراجعة من الإدارة
ALTER TABLE `bookings`
  ADD COLUMN `booking_reviewed_at` DATETIME DEFAULT NULL
  COMMENT 'تاريخ ووقت آخر مراجعة من الإدارة'
  AFTER `booking_reviewed_by`;
 
-- فهرس حالة الحجز لتسريع تابات الفلترة
ALTER TABLE `bookings`
  ADD KEY `idx_booking_status` (`booking_status`);
 
-- فهرس الموظف المراجع
ALTER TABLE `bookings`
  ADD KEY `idx_booking_reviewed_by` (`booking_reviewed_by`);