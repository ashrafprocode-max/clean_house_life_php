================================================================
-جدول التخصصات الدقيقجدول أنواع التوقيت (إقامة، ساعات، إلخ) 
- service_timing
================================================================
CREATE TABLE `service_timing` (
  `timing_id` INT(11) NOT NULL AUTO_INCREMENT,
  `timing_name_ar` VARCHAR(100) NOT NULL, -- مثل: إقامة كاملة
  `timing_name_en` VARCHAR(100) NOT NULL, -- مثل: Full-time
  PRIMARY KEY (`timing_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;