-- جدول أكواد OTP للتحقق من صحة رقم الهاتف
-- نفذ هذا الاستعلام في قاعدة البيانات (dev و prod)

CREATE TABLE IF NOT EXISTS `otp_codes` (
  `otp_id` int(11) NOT NULL AUTO_INCREMENT,
  `otp_phone` varchar(20) NOT NULL,
  `otp_code` varchar(6) NOT NULL,
  `otp_expires_at` datetime NOT NULL,
  `otp_verified` tinyint(1) DEFAULT 0 COMMENT '0=لم يُتحقق، 1=تم التحقق',
  `otp_created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`otp_id`),
  KEY `idx_phone_expires` (`otp_phone`, `otp_expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- الغرض: تمييز سبب الـ OTP حتى لا يختلط reset مع signup/login
ALTER TABLE otp_codes
ADD COLUMN otp_purpose ENUM('signup','login','reset_password') NOT NULL DEFAULT 'signup' AFTER otp_code;

-- الغرض: فهرس يسرّع البحث عن آخر OTP صالح لرقم معيّن وغرض معيّن
CREATE INDEX idx_otp_phone_purpose_created ON otp_codes (otp_phone, otp_purpose, otp_created_at);
