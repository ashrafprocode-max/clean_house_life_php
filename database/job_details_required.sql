CREATE TABLE `job_details_required` (
  `required_id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'معرف فريد لطلب العمالة',
  `required_company_id` INT UNSIGNED NOT NULL COMMENT 'معرف الشركة أو النشاط الطالب للعمالة (مربوط بجدول company_registering)',
  `required_job_title` VARCHAR(150) NOT NULL COMMENT 'اسم المهنة أو الوظيفة المطلوبة مثل عامل تحميل أو موظف أمن (إجباري)',
  `required_job_description` TEXT NOT NULL COMMENT 'شرح مواصفات ومهام العامل المطلوبة من الشركة (إجباري)',
  `required_is_verified_worker` ENUM('verified', 'unverified') NOT NULL COMMENT 'حالة توثيق بيانات العامل مثل وجود فيش جنائي: موثق أم غير موثق (إجباري)',
  `required_workers_count` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'عدد العمال المطلوبين لهذه الوظيفة',
  `required_start_date_time` DATETIME NOT NULL COMMENT 'الوقت والتاريخ المحدد لبدء العمل',
  `required_approval_status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending' COMMENT 'حالة طلب الموافقة من الإدارة (افتراضي قيد الانتظار pending)',
  `required_created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ ووقت إرسال طلب العمالة للادارة',
  `required_updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ ووقت آخر تحديث للطلب',
  CONSTRAINT `fk_company_job_request` FOREIGN KEY (`required_company_id`) REFERENCES `company_registering` (`company_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='جدول تفاصيل طلبات العمالة والموظفين الخاصة بكل شركة';


-- إضافة أعمدة سجل مراجعة طلبات العمالة
ALTER TABLE `job_details_required`
  -- معرّف الموظف الذي نفّذ القرار
  ADD COLUMN `required_reviewed_by` INT NULL DEFAULT NULL
      COMMENT 'معرّف الموظف الذي نفّذ قرار الموافقة أو الرفض' AFTER `required_approval_status`,
  -- تاريخ ووقت تنفيذ القرار
  ADD COLUMN `required_reviewed_at` DATETIME NULL DEFAULT NULL
      COMMENT 'تاريخ ووقت تنفيذ قرار الإدارة' AFTER `required_reviewed_by`,
  -- سبب الرفض أو ملاحظة الموظف
  ADD COLUMN `required_review_note` TEXT NULL DEFAULT NULL
      COMMENT 'سبب الرفض أو ملاحظة الموظف على الطلب' AFTER `required_reviewed_at`;


-- ============================================================
-- إضافة حقول تفاصيل ساعات العمل والمرتب على job_details_required
-- الغرض: تحديد شروط العمل التي تعرضها الشركة على العامل
-- ============================================================
ALTER TABLE `job_details_required`
  -- عدد ساعات العمل اليومية للعامل
  ADD COLUMN `required_daily_work_hours` TINYINT UNSIGNED NOT NULL DEFAULT 8
      COMMENT 'عدد ساعات العمل اليومية للعامل'
      AFTER `required_workers_count`,

  -- عدد أيام العمل في الشهر
  ADD COLUMN `required_monthly_work_days` TINYINT UNSIGNED NOT NULL DEFAULT 26
      COMMENT 'عدد ايام العمل في الشهر'
      AFTER `required_daily_work_hours`,

  -- ⭐ نوع TIME لا VARCHAR لأنه يسمح بالمقارنة والترتيب في SQL
  ADD COLUMN `required_shift_start_time` TIME NOT NULL DEFAULT '08:00:00'
      COMMENT 'ساعة بداية العمل اليومي'
      AFTER `required_monthly_work_days`,

  -- ساعة نهاية العمل اليومي
  ADD COLUMN `required_shift_end_time` TIME NOT NULL DEFAULT '16:00:00'
      COMMENT 'ساعة نهاية العمل اليومي'
      AFTER `required_shift_start_time`,

  -- ⭐ نوع DECIMAL لا FLOAT لأن FLOAT يفقد دقة الكسور في الأموال
  ADD COLUMN `required_monthly_salary` DECIMAL(10,2) NOT NULL DEFAULT 0.00
      COMMENT 'مرتب العامل او الموظف في الشهر بالجنيه'
      AFTER `required_shift_end_time`;