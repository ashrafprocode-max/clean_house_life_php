-- ============================================================
-- ملف: notifications.sql
-- الغرض: جداول نظام الإشعارات + صلاحيات الإدارة
--
-- التنفيذ: مرة واحدة على قاعدة clean_house_life
-- ============================================================

-- ============================================================
-- 1. جدول محتوى الإشعار (صف واحد لكل حدث أو حملة)
-- ============================================================
CREATE TABLE IF NOT EXISTS `notifications` (
  `notification_id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للإشعار',
  `notification_type` VARCHAR(64) NOT NULL COMMENT 'نوع الإشعار لتصنيف الأحداث',
  `notification_title` VARCHAR(180) NOT NULL COMMENT 'عنوان الإشعار الرئيسي',
  `notification_body` VARCHAR(500) NOT NULL COMMENT 'محتوى أو نص الإشعار التفصيلي',
  `notification_audience` ENUM('admin','user','mixed') NOT NULL DEFAULT 'admin' COMMENT 'الفئة المستهدفة للإشعار',
  `notification_actor_user_id` INT NULL DEFAULT NULL COMMENT 'معرف المستخدم الذي تسبب في الحدث',
  `notification_actor_name` VARCHAR(100) NULL DEFAULT NULL COMMENT 'اسم المستخدم صاحب الفعل',
  `notification_actor_role` VARCHAR(20) NULL DEFAULT NULL COMMENT 'دور المستخدم (عميل، مقدم، إلخ)',
  `notification_ref_table` VARCHAR(40) NULL DEFAULT NULL COMMENT 'اسم الجدول المرتبط بالحدث',
  `notification_ref_id` INT NULL DEFAULT NULL COMMENT 'معرف السجل المرتبط في الجدول الآخر',
  `notification_route_name` VARCHAR(80) NULL DEFAULT NULL COMMENT 'اسم مسار الشاشة في تطبيق الفلاتر للتوجيه',
  `notification_extra_json` TEXT NULL COMMENT 'بيانات إضافية مرنة بصيغة JSON',
  `notification_created_by_admin` INT NULL DEFAULT NULL COMMENT 'معرف المشرف الذي أرسل الإشعار إن وجد',
  `notification_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ ووقت إنشاء الإشعار',
  PRIMARY KEY (`notification_id`),
  KEY `idx_notif_type_created` (`notification_type`, `notification_created_at`),
  KEY `idx_notif_actor` (`notification_actor_user_id`),
  KEY `idx_notif_ref` (`notification_ref_table`, `notification_ref_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. صندوق الوارد (صف لكل مستلم: موظف أو مستخدم)
-- ============================================================
CREATE TABLE IF NOT EXISTS `notification_inbox` (
  `inbox_id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد لسجل صندوق الوارد',
  `inbox_notification_id` INT UNSIGNED NOT NULL COMMENT 'معرف الإشعار الأساسي المرتبط بجدول notifications',
  `inbox_recipient_kind` ENUM('admin','user') NOT NULL COMMENT 'نوع المستلم: هل هو موظف إداري أم مستخدم عادي',
  `inbox_admin_id` INT NULL DEFAULT NULL COMMENT 'معرف الموظف الإداري المستلم للإشعار (إن وجد)',
  `inbox_user_id` INT NULL DEFAULT NULL COMMENT 'معرف المستخدم العادي المستلم للإشعار (إن وجد)',
  `inbox_is_read` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'حالة القراءة: 0 غير مقروء، 1 مقروء',
  `inbox_read_at` DATETIME NULL DEFAULT NULL COMMENT 'تاريخ ووقت قراءة الإشعار',
  `inbox_pushed_at` DATETIME NULL DEFAULT NULL COMMENT 'تاريخ ووقت إرسال الإشعار كـ Push Notification',
  `inbox_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ ووقت إضافة الإشعار لصندوق الوارد',
  PRIMARY KEY (`inbox_id`),
  KEY `idx_inbox_admin_unread` (`inbox_admin_id`, `inbox_is_read`, `inbox_id`),
  KEY `idx_inbox_user_unread` (`inbox_user_id`, `inbox_is_read`, `inbox_id`),
  KEY `idx_inbox_notification` (`inbox_notification_id`),
  CONSTRAINT `fk_inbox_notification`
    FOREIGN KEY (`inbox_notification_id`)
    REFERENCES `notifications` (`notification_id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. أجهزة FCM (موبايل / ويب). ويندوز لا يعتمد عليها
-- ============================================================
CREATE TABLE IF NOT EXISTS `notification_devices` (
  `device_id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد لجهاز المستخدم',
  `device_owner_kind` ENUM('admin','user') NOT NULL COMMENT 'نوع مالك الجهاز: هل هو موظف إداري أم مستخدم عادي',
  `device_admin_id` INT NULL DEFAULT NULL COMMENT 'معرف الموظف الإداري صاحب الجهاز (إن وجد)',
  `device_user_id` INT NULL DEFAULT NULL COMMENT 'معرف المستخدم العادي صاحب الجهاز (إن وجد)',
  `device_platform` ENUM('android','ios','web','windows') NOT NULL DEFAULT 'android' COMMENT 'نوع نظام التشغيل الخاص بالجهاز',
  `device_fcm_token` VARCHAR(255) NOT NULL COMMENT 'رمز التوكن الخاص بخدمة إشعارات فايربيس (FCM Token)',
  `device_is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'حالة الجهاز: 1 نشط لاستقبال الإشعارات، 0 متوقف',
  `device_updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ ووقت آخر تحديث لبيانات أو توكن الجهاز',
  PRIMARY KEY (`device_id`),
  UNIQUE KEY `uq_device_token` (`device_fcm_token`),
  KEY `idx_device_admin` (`device_admin_id`, `device_is_active`),
  KEY `idx_device_user` (`device_user_id`, `device_is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. صلاحيات القسم الجديد
--
-- ⚠️ admin_role_permissions أعمدته rp_role_id و rp_perm_id فقط
--    والمفاتيح النصية موجودة في admin_roles.role_key
--    و admin_permissions.perm_key
-- ⚠️ السكربت idempotent: تكراره لا يُنشئ صفاً مكرراً
-- ============================================================
INSERT INTO `admin_permissions` (`perm_key`, `perm_name_ar`, `perm_group_ar`)
SELECT 'notifications.view', 'عرض إشعارات الإدارة', 'الإشعارات'
WHERE NOT EXISTS (
  SELECT 1 FROM `admin_permissions` WHERE `perm_key` = 'notifications.view'
);

INSERT INTO `admin_permissions` (`perm_key`, `perm_name_ar`, `perm_group_ar`)
SELECT 'notifications.send', 'إرسال إشعارات ترويجية', 'الإشعارات'
WHERE NOT EXISTS (
  SELECT 1 FROM `admin_permissions` WHERE `perm_key` = 'notifications.send'
);

-- مكرر
INSERT IGNORE INTO `admin_permissions`
  (`perm_key`, `perm_name_ar`, `perm_group_ar`)
VALUES
  ('notifications.view', 'عرض إشعارات الإدارة', 'الإشعارات');

-- إنشاء مفتاح إرسال الحملات الترويجية إن لم يكن موجوداً
INSERT IGNORE INTO `admin_permissions`
  (`perm_key`, `perm_name_ar`, `perm_group_ar`)
VALUES
  ('notifications.send', 'إرسال إشعارات ترويجية', 'الإشعارات');

-- منح notifications.view للمالك والمدير وخدمة العملاء
INSERT IGNORE INTO `admin_role_permissions` (`rp_role_id`, `rp_perm_id`)
SELECT r.role_id, p.perm_id
FROM `admin_roles` r
CROSS JOIN `admin_permissions` p
WHERE r.role_key IN ('owner', 'manager', 'support')
  AND p.perm_key = 'notifications.view';

-- منح notifications.send للمالك والمدير فقط
INSERT IGNORE INTO `admin_role_permissions` (`rp_role_id`, `rp_perm_id`)
SELECT r.role_id, p.perm_id
FROM `admin_roles` r
CROSS JOIN `admin_permissions` p
WHERE r.role_key IN ('owner', 'manager')
  AND p.perm_key = 'notifications.send';
