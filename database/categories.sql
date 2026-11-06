/***
*****هيكلية الجداول (Database Schema)
سنحتاج لـ 5 جداول أساسية لربط هذه المنظومة ببعضها:
1- categories: الأقسام الكبرى (ضيافة، رعاية، صيانة).
2- sub_categories: المهن الدقيقة (طباخ، ممرض، سباك).
3- service_timing: أنواع الدوام (إقامة، ساعات، مرة واحدة).
4- users: مقدمو الخدمة والعملاء (الذي أضفنا له users_approve).
5- provider_services: (الجدول السحري) الذي يربط مقدم الخدمة بمهنته وتوقيته.
***/
===============================================
- جدول الأقسام   
- categories
===============================================
CREATE TABLE `categories` (
  `categories_id` INT(11) NOT NULL AUTO_INCREMENT,
  `categories_name_ar` VARCHAR(100) NOT NULL,
  `categories_name_en` VARCHAR(100) NOT NULL,
  `categories_image` VARCHAR(255) DEFAULT NULL,
  `categories_created` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`categories_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

==================================================
إيقاف التحقق من القيود مؤقتاً (SQL) لحذف الجدول
==================================================	
-- الخطوة 1: إيقاف التحقق من القيود
SET FOREIGN_KEY_CHECKS = 0;

-- الخطوة 2: احذف الجدول الذي تريده
DROP TABLE IF EXISTS professional_profiles;
DROP TABLE IF EXISTS users; -- أو أي جدول آخر

-- الخطوة 3: إعادة تفعيل التحقق من القيود (مهم جداً!)
SET FOREIGN_KEY_CHECKS = 1;

-categories_icon_path عندما تضيف قسم "مكافحة حشرات" مثلاً من لوحة التحكم، ترفع معه أيقونة (SVG أو PNG).
لحذفه
DROP TABLE IF EXISTS categories;

مسح البيانات فقط مع بقاء الجدول (TRUNCATE)
إذا كان هدفك هو "تصفير" الجدول (مسح كل العمال والبيانات)
 ليبدأ الـ ID من رقم 1 مرة أخرى دون الحاجة لكتابة كود الإنشاء (CREATE) من جديد
TRUNCATE TABLE professional_profiles;

CREATE TABLE `categories` (
  `categories_id` INT(11) NOT NULL AUTO_INCREMENT,
  `categories_name_ar` VARCHAR(100) NOT NULL,
  `categories_name_en` VARCHAR(100) NOT NULL,
  `categories_image` VARCHAR(255) DEFAULT NULL,
  `categories_created` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`categories_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- ⭐ التعطيل بدل الحذف للأقسام والتوقيتات
--
-- ⚠️ لماذا لا نحذف؟
--   حذف قسم رئيسي يترك provider_services.ps_cat_id يشير لصف مفقود
--   فتظهر خدمات بلا اسم قسم في شاشة البحث، وقد ينهار الاستعلام
--   الذي يعتمد على JOIN مع categories
--
-- ⭐ والتعطيل يحل هذا تماماً: الصف يبقى فتعمل كل الـ JOINs
--   لكن القسم لا يظهر في قوائم الاختيار الجديدة
-- ============================================================

-- ⭐ عمود التفعيل للأقسام الرئيسية
ALTER TABLE `categories`
  ADD COLUMN `categories_is_active` TINYINT(1) NOT NULL DEFAULT 1
    COMMENT '1 مفعّل · 0 معطّل (لا يظهر للمستخدمين ولا يُختار)'
    AFTER `categories_image`;

-- ⭐ عمود التفعيل للأقسام الفرعية
ALTER TABLE `sub_categories`
  ADD COLUMN `sub_is_active` TINYINT(1) NOT NULL DEFAULT 1
    COMMENT '1 مفعّل · 0 معطّل'
    AFTER `sub_name_en`;

-- ⭐ عمود التفعيل للتوقيتات
ALTER TABLE `service_timing`
  ADD COLUMN `timing_is_active` TINYINT(1) NOT NULL DEFAULT 1
    COMMENT '1 مفعّل · 0 معطّل'
    AFTER `timing_name_en`;

-- ⭐ ترتيب العرض للأقسام الرئيسية
-- ⚠️ حتى تتحكم الإدارة في ترتيب ظهورها بلا تعديل كود
ALTER TABLE `categories`
  ADD COLUMN `categories_sort` SMALLINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'ترتيب العرض — الأصغر يظهر أولاً'
    AFTER `categories_is_active`;

-- ⭐ فهرس يُسرّع تصفية المفعّل فقط
ALTER TABLE `categories`
  ADD INDEX `idx_cat_active` (`categories_is_active`, `categories_sort`);