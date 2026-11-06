
-- ============================================================
-- جدول موظفي الإدارة (منفصل تماماً عن جدول users)
-- ============================================================
CREATE TABLE admin_users (
  admin_id INT(11) NOT NULL AUTO_INCREMENT,
  admin_full_name VARCHAR(100) NOT NULL,
  admin_phone VARCHAR(20) NOT NULL,
  admin_password VARCHAR(255) NOT NULL COMMENT 'مشفّرة بـ password_hash',
  admin_role ENUM('owner','manager','accountant','support','seller') NOT NULL DEFAULT 'support'
    COMMENT 'manager = كل الصلاحيات / support = محدودة',
  admin_is_active TINYINT(1) NOT NULL DEFAULT 1,
  admin_last_login DATETIME NULL,
  admin_created_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (admin_id),
  UNIQUE KEY uq_admin_phone (admin_phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- حقول تتبّع المراجعة على جدول مقدمي الخدمة
-- بدونها لا نعرف سبب الرفض ولا من راجع ولا متى
-- ============================================================
ALTER TABLE professional_profiles
  ADD COLUMN providers_review_note TEXT NULL
    COMMENT 'سبب الرفض أو ملاحظة المراجع',
  ADD COLUMN providers_reviewed_by INT(11) NULL
    COMMENT 'admin_id الذي راجع الطلب',
  ADD COLUMN providers_reviewed_at DATETIME NULL
    COMMENT 'تاريخ ووقت المراجعة';