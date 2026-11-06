-- ============================================================
-- ملف: database/commissions_v2.sql
-- الغرض: نظام «العمولتين» الجديد في التطبيق
--
-- ⭐ الفكرة:
--   العمولة (1) عمولة مقدم الخدمة = A  → المخفية عن العميل تماماً
--     يحدّدها مقدم الخدمة كنسبة أو مبلغ، وتراجعها/تعدّلها الإدارة،
--     ثم تُضاف على سعر الخدمة فيظهر للعميل السعر شاملاً هذه العمولة.
--     مثال: سعر 10 ج + عمولة 10% (= 1 ج) ⇒ العميل يرى 11 ج.
--
--   العمولة (2) عمولة العميل = B  → الظاهرة في صفحة الحجز
--     تحدّدها الإدارة (نسبة أو مبلغ ثابت) وتُخزَّن مع كل حجز،
--     وصفحة الحجز تقرأها من أعمدة الحجز وليس من عمولة مقدم الخدمة.
--     مثال: خدمة 11 ج + عمولة 10% (= 1 ج) ⇒ الإجمالي 12 ج.
--
--   الكوبون يخصم من عمولة العميل (B) فقط ولا يمسّ سعر الخدمة.
--
-- ⚠️ نفّذ هذا الملف على clean_house_life_dev و clean_house_life_prod معاً
-- ⚠️ إذا ظهر «Duplicate column name» فتجاهل العمود لأنه موجود مسبقاً
-- ============================================================

-- ============================================================
-- 1) جدول إعدادات التطبيق العام (app_settings)
--    يُخزّن هنا قيمة عمولة العميل الافتراضية التي تحددها الإدارة
--    عند الإطلاق: إما 0% أو 10%
-- ============================================================
CREATE TABLE IF NOT EXISTS `app_settings` (
  `setting_key`        VARCHAR(100)  COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'مفتاح الإعداد',
  `setting_value`      VARCHAR(255)  COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'قيمة الإعداد كنص',
  `setting_updated_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ ووقت آخر تعديل',
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- القيم الافتراضية لعمولة العميل عند الإطلاق (percent + 0)
-- ملاحظة: تم تغيير الافتراضي من 10% إلى 0% بقرار الإدارة،
-- ويظل بمقدور الإدارة رفعها أو تعديلها في أي وقت.
-- ON DUPLICATE KEY UPDATE يجعل السكربت آمناً عند التنفيذ أكثر من مرة
INSERT INTO `app_settings` (`setting_key`, `setting_value`) VALUES
  ('client_commission_type',  'percent'),
  ('client_commission_value', '0')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

-- ============================================================
-- 2) جدول provider_services — عمولة مقدم الخدمة (A)
--    نُخزّن مبلغ العمولة ثابتاً بالجنيه لسهولة الجمع على السعر،
--    حتى يظهر للعميل سعر الخدمة = ps_price + ps_commission_amount
-- ============================================================
ALTER TABLE `provider_services`
  ADD COLUMN `ps_commission_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00
  COMMENT 'مبلغ عمولة مقدم الخدمة (A) بالجنيه — مخفية عن العميل وتُضاف على السعر'
  AFTER `ps_commission_value`;

-- ============================================================
-- 3) جدول bookings — عمولتا الحجز (A المخفية + B الظاهرة)
-- ============================================================

-- (أ) مبلغ عمولة مقدم الخدمة المحسوب والمحفوظ مع الحجز (A)
ALTER TABLE `bookings`
  ADD COLUMN `booking_ps_commission_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00
  COMMENT 'مبلغ عمولة مقدم الخدمة (A) بالجنيه — مخفية عن العميل'
  AFTER `booking_ps_commission_value`;

-- (ب) نوع عمولة العميل (B): fixed أو percent
ALTER TABLE `bookings`
  ADD COLUMN `booking_client_commission_type` ENUM('fixed','percent')
  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'percent'
  COMMENT 'نوع عمولة العميل (B) الظاهرة في الحجز'
  AFTER `booking_ps_commission_amount`;

-- (ج) قيمة/نسبة عمولة العميل (B) كما حددتها الإدارة
ALTER TABLE `bookings`
  ADD COLUMN `booking_client_commission_value` DECIMAL(10,2) NOT NULL DEFAULT 0.00
  COMMENT 'قيمة أو نسبة عمولة العميل (B)'
  AFTER `booking_client_commission_type`;

-- (د) مبلغ عمولة العميل بالجنيه (B) الظاهر في فاتورة العميل
ALTER TABLE `bookings`
  ADD COLUMN `booking_client_commission_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00
  COMMENT 'مبلغ عمولة العميل (B) بالجنيه — الظاهر في فاتورة العميل'
  AFTER `booking_client_commission_value`;
