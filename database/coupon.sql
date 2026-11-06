CREATE TABLE IF NOT EXISTS `coupon` (
  `coupon_id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للكوبون',
  `coupon_name` VARCHAR(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'اسم الكوبون',
  `coupon_count` INT(11) NOT NULL COMMENT 'عدد مرات استخدام الكوبون',
  `coupon_discount` SMALLINT(6) NOT NULL DEFAULT 0 COMMENT 'قيمة أو نسبة خصم الكوبون',
  `coupon_expiredate` DATETIME NOT NULL COMMENT 'تاريخ ووقت انتهاء صلاحية الكوبون',
  PRIMARY KEY (`coupon_id`),
  UNIQUE KEY `uq_coupon_name` (`coupon_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- ملف: admin/coupons/coupon_is_active.sql
-- الغرض: إضافة عمود الحذف الناعم لجدول الكوبونات
--
-- ⚠️ نفّذ هذا الملف مرة واحدة على قاعدة البيانات قبل رفع
--    ملفات list_coupon.php / edit_coupon.php / delete_coupon.php
--
-- ⭐ لماذا حذف ناعم بدل DELETE فعلي؟
--   bookings.booking_coupon يشير إلى coupon.coupon_id
--   فلو حذفنا الصف لظهرت الحجوزات القديمة بخصم مفقود
--   0 = معطّل (لا يظهر للمستخدمين) و 1 = فعّال
-- ============================================================

ALTER TABLE `coupon`
  ADD COLUMN `coupon_is_active` TINYINT(1) NOT NULL DEFAULT 1
  COMMENT '1 = فعّال, 0 = معطّل (حذف ناعم)' AFTER `coupon_expiredate`;

-- تحديث الصفوف القديمة إن وُجدت لتكون فعّالة
UPDATE `coupon` SET `coupon_is_active` = 1 WHERE `coupon_is_active` IS NULL;
