==================================================
3. جدول العملاء (البيانات الإضافية)  -client_profiles
هذا الجدول يخزن البيانات الإضافية للعملاء، مثل العنوان وتاريخ الميلاد
لاحظ أننا لم نضع "الاسم" أو "الهاتف" هنا، بل ربطناه بجدول المستخدمين.
==================================================
CREATE TABLE `client_profiles` (
  `clients_id` INT(11) NOT NULL AUTO_INCREMENT,
  `clients_users_id` INT(11) NOT NULL,                                 -- ربط بجدول المستخدمين الأساسي (الأب)
  `clients_profile_image` VARCHAR(255) DEFAULT NULL,                    -- صورة شخصية للعميل
  `clients_email` VARCHAR(100) DEFAULT NULL,                           -- إيميل إضافي للتواصل أو الفواتير
  `clients_detailed_address` TEXT DEFAULT NULL,                         -- العنوان بالتفصيل (الشقة، الدور، المعالم)
  `clients_birth_date` DATE DEFAULT NULL,                              -- تاريخ الميلاد
  `clients_nationality` VARCHAR(50) DEFAULT NULL,                      -- الجنسية
  `clients_created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,            -- تاريخ وقت إنشاء البروفايل
  `clients_updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, -- تاريخ آخر تحديث للبيانات
  PRIMARY KEY (`clients_id`),
  -- ربط بمفتاح أجنبي مع جدول users مع ميزة CASCADE لضمان حذف البروفايل تلقائياً في حال حذف الحساب الأساسي
  CONSTRAINT `fk_users_client_profile` FOREIGN KEY (`clients_users_id`) REFERENCES `users`(`users_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;