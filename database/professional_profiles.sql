==================================================
3. جدول مقدمي الخدمة (البيانات الإضافية)  -service_providers
لاحظ أننا لم نضع "الاسم" أو "الهاتف" هنا، بل ربطناه بجدول المستخدمين.
==================================================
CREATE TABLE IF NOT EXISTS `professional_profiles` (
  `providers_id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد',
  `providers_users_id` INT(11) NOT NULL COMMENT 'ربط جدول المستخدمين الأساسي',
  `providers_experience` INT(11) DEFAULT NULL COMMENT 'سنوات الخبرة',
  `providers_bio` TEXT COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'نبذة عن مقدم الخدمة',
  `providers_rating` DECIMAL(3,2) DEFAULT '0.00' COMMENT 'التقييم',
  `providers_age` INT(3) NOT NULL COMMENT 'العمر',
  `providers_gender` VARCHAR(10) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'النوع (ذكر/أنثى)',
  `providers_nationality` VARCHAR(50) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'الجنسية',
  `providers_profile_image` VARCHAR(255) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'الصورة الشخصية',
  `providers_current_country` VARCHAR(50) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'الدولة الحالية لمقيم فيها',
  `providers_current_governorate` VARCHAR(50) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'المحافظة / القرية الحالي المقيم فيها',
  `providers_current_area` VARCHAR(50) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'المنطقة / القرية الحالية لمقيم فيها',
  `providers_current_address` TEXT COLLATE utf8mb4_general_ci NOT NULL COMMENT 'عنوان الإقامة الحالي بالتفصيل',
  `providers_id_card_front` VARCHAR(255) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'صورة البطاقة الأمامية',
  `providers_id_card_back` VARCHAR(255) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'صورة البطاقة الخلفية',
  `providers_criminal_record_image` VARCHAR(255) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'الصحيفة الجنائية',
  `providers_utility_receipt_image` VARCHAR(255) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'إيصال مرافق سواء (كهرباء، أو غاز) ويكون إيصال حديث يثبت نفس الاسم التي في صورة البطاقة',
  `providers_is_verified` TINYINT(1) DEFAULT 0 COMMENT 'هل تم التحقق من الإدارة؟ 0 = pending, 1 = verified, 2 = rejected',
  `providers_review_note` TEXT COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'سبب رفض أو ملاحظة المراجع',
  `providers_reviewed_by` INT(11) DEFAULT NULL COMMENT 'الذي راجع الملف admin_id',
  `providers_reviewed_at` DATETIME DEFAULT NULL COMMENT 'تاريخ ووقت المراجعة',
  PRIMARY KEY (`providers_id`),
  KEY `providers_users_id` (`providers_users_id`),
  CONSTRAINT `professional_profiles_ibfk_1` FOREIGN KEY (`providers_users_id`) REFERENCES `users` (`users_id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-providers_users_id INT: هذا الحقل سيخزن رقم المستخدم الموجود في جدول الـ users.
-providers_descrip TEXT: استخدمنا TEXT بدلاً من VARCHAR لأن وصف الخبرة قد يكون طويلاً جداً.
-providers_is_available BOOLEAN: حقل "منطقي" (صح أو خطأ). العميل سيبحث فقط عن العمال الذين قيمتهم TRUE.
-FOREIGN KEY (providers_users_id) REFERENCES users(users_id): هذا هو سحر العلاقات. نحن نخبر السيرفر أن الـ user_id هنا هو "ابن" للـ id في جدول المستخدمين.
-price: (رقم) القيمة المالية.
-price_unit: (قائمة خيارات) تحدد هل هذا السعر (بالساعة، باليوم، بالشهر).
-visiting (زيارة) & live_in (إقامة كاملة) & both (كلاهما)
-في Flutter، سنضع منطق (Logic) يربط بينهما:
إذا اختار العامل full_time_live_in: سنجعل خيار وحدة السعر في الـ Dropdown يقتصر على (شهر أو أسبوع).
إذا اختار العامل hourly_visit: سنجعل الخيار يقتصر على (ساعة أو يوم).
إذا اختار flexible_both: نترك له حرية الاختيار من القائمة كاملة.
-ON DELETE CASCADE: تعني "إذا تم حذف المستخدم من جدول الأب (users)، فاحذف بياناته تلقائياً من جدول مقدمي الخدمة". هكذا تضمن نظافة قاعدة بياناتك دائماً.
