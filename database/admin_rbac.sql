-- ============================================================
-- ملف: database/admin_rbac.sql
-- الغرض: نظام الأدوار والصلاحيات + أعمدة تتبّع من أنشأ كل حساب
-- كلمة RBAC هي اختصار لمصطلح Role-Based Access Control (التحكم في الوصول القائم على الأدوار).
--
-- ⚠️ يُنفَّذ على clean_house_life_dev و clean_house_life_prod معاً
--
-- ⭐ آمن تماماً: لا يحذف بيانات ولا يُعطّل شيئاً
--    و admin_role enum القديم يبقى كما هو للتوافق
-- ============================================================

-- ============================================================
-- الخطوة 1: جدول الأدوار
-- ============================================================
CREATE TABLE IF NOT EXISTS `admin_roles` (
  -- المعرّف الرقمي للدور
  `role_id`         TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- المفتاح النصي الذي يستخدمه الكود (owner / manager / seller)
  `role_key`        VARCHAR(30)      NOT NULL,
  -- اسم الدور بالعربية لعرضه في اللوحة
  `role_name_ar`    VARCHAR(50)      NOT NULL,
  -- اسم الدور بالإنجليزية
  `role_name_en`    VARCHAR(50)      NOT NULL,
  -- شرح مختصر لمهام الدور
  `role_description_ar` VARCHAR(255) DEFAULT NULL,
  -- ⭐⭐ مستوى الدور: الأعلى يُدير الأقل فقط (حماية من تصعيد الصلاحية)
  `role_level`      TINYINT UNSIGNED NOT NULL DEFAULT 10,
  -- ⚠️ 1 = دور نظامي لا يُحذف (owner لا يُحذف إطلاقاً)
  `role_is_system`  TINYINT(1)       NOT NULL DEFAULT 0,
  -- هل الدور مفعّل؟ (نعطّله بدل حذفه)
  `role_is_active`  TINYINT(1)       NOT NULL DEFAULT 1,
  -- تاريخ الإنشاء
  `role_created_at` TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`role_id`),
  -- ⚠️ المفتاح النصي فريد لأن الكود يعتمد عليه
  UNIQUE KEY `uq_role_key` (`role_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- الخطوة 2: كتالوج الصلاحيات
-- ============================================================
CREATE TABLE IF NOT EXISTS `admin_permissions` (
  -- المعرّف الرقمي للصلاحية
  `perm_id`       SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- ⭐ المفتاح النصي بصيغة resource.action مثل companies.create
  `perm_key`      VARCHAR(50)       NOT NULL,
  -- اسم الصلاحية بالعربية لعرضها في شاشة الصلاحيات
  `perm_name_ar`  VARCHAR(100)      NOT NULL,
  -- ⭐ مجموعة الصلاحية لتجميعها في اللوحة (الشركات / الحجوزات ...)
  `perm_group_ar` VARCHAR(50)       NOT NULL,
  -- ترتيب العرض داخل المجموعة
  `perm_sort`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`perm_id`),
  -- ⚠️ المفتاح النصي فريد لأن الكود يفحصه بالاسم
  UNIQUE KEY `uq_perm_key` (`perm_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- الخطوة 3: مصفوفة صلاحيات كل دور (يعدّلها owner من اللوحة)
-- ============================================================
CREATE TABLE IF NOT EXISTS `admin_role_permissions` (
  -- الدور
  `rp_role_id` TINYINT UNSIGNED  NOT NULL,
  -- الصلاحية
  `rp_perm_id` SMALLINT UNSIGNED NOT NULL,
  -- ⚠️ مفتاح مركّب يمنع تكرار نفس الصلاحية لنفس الدور
  PRIMARY KEY (`rp_role_id`, `rp_perm_id`),
  -- حذف الدور يحذف صلاحياته
  CONSTRAINT `fk_rp_role` FOREIGN KEY (`rp_role_id`)
    REFERENCES `admin_roles` (`role_id`) ON DELETE CASCADE,
  -- حذف الصلاحية يحذفها من كل الأدوار
  CONSTRAINT `fk_rp_perm` FOREIGN KEY (`rp_perm_id`)
    REFERENCES `admin_permissions` (`perm_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ⭐⭐ الخطوة 4: استثناءات موظف واحد (منح أو سحب فوق دوره)
--
-- ⭐ هذا الجدول هو ما يجعل النظام مرناً حقاً:
--    مندوب متميّز نمنحه companies.edit وحده
--    بلا تحويله لخدمة عملاء وبلا تغيير صلاحيات باقي المندوبين
-- ============================================================
CREATE TABLE IF NOT EXISTS `admin_user_permissions` (
  -- الموظف المستهدف
  `up_admin_id`   INT(11)           NOT NULL,
  -- الصلاحية المعنية
  `up_perm_id`    SMALLINT UNSIGNED NOT NULL,
  -- ⭐ 1 = مُنحت فوق دوره · 0 = سُحبت منه رغم أن دوره يمنحها
  `up_is_granted` TINYINT(1)        NOT NULL DEFAULT 1,
  -- من نفّذ هذا الاستثناء (للمساءلة)
  `up_granted_by` INT(11)           DEFAULT NULL,
  -- تاريخ التنفيذ
  `up_created_at` TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- ⚠️ مفتاح مركّب يمنع تكرار نفس الاستثناء
  PRIMARY KEY (`up_admin_id`, `up_perm_id`),
  -- حذف الموظف يحذف استثناءاته
  CONSTRAINT `fk_up_admin` FOREIGN KEY (`up_admin_id`)
    REFERENCES `admin_users` (`admin_id`) ON DELETE CASCADE,
  -- حذف الصلاحية يحذف استثناءاتها
  CONSTRAINT `fk_up_perm` FOREIGN KEY (`up_perm_id`)
    REFERENCES `admin_permissions` (`perm_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ⭐ الخطوة 5: سجل النشاط الإداري
--
-- ⚠️ إلزامي لأن المندوبين سيُنشئون حسابات بأنفسهم
--    فلا بد من معرفة من أنشأ ماذا ومتى ومن أي جهاز
-- ============================================================
CREATE TABLE IF NOT EXISTS `admin_activity_log` (
  -- معرّف السجل
  `log_id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- الموظف المنفّذ
  `log_admin_id`    INT(11)         NOT NULL,
  -- اسم الموظف وقت التنفيذ (نُثبّته حتى لو تغيّر لاحقاً)
  `log_admin_name`  VARCHAR(100)    DEFAULT NULL,
  -- ⭐ نوع الإجراء: create_company / create_user / approve_job ...
  `log_action`      VARCHAR(50)     NOT NULL,
  -- نوع الكيان المتأثر: user / company / job_request / provider
  `log_entity_type` VARCHAR(30)     DEFAULT NULL,
  -- معرّف الكيان المتأثر
  `log_entity_id`   INT(11)         DEFAULT NULL,
  -- ⭐ تفاصيل الإجراء بصيغة JSON (القيم قبل وبعد)
  `log_details`     TEXT            DEFAULT NULL,
  -- عنوان IP لمعرفة مكان التنفيذ
  `log_ip`          VARCHAR(45)     DEFAULT NULL,
  -- تاريخ ووقت التنفيذ
  `log_created_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  -- فهرس على الموظف لتصفية سجله بسرعة
  KEY `idx_log_admin` (`log_admin_id`),
  -- فهرس على الكيان لتتبّع تاريخ صف معيّن
  KEY `idx_log_entity` (`log_entity_type`, `log_entity_id`),
  -- فهرس على التاريخ لعرض آخر النشاطات
  KEY `idx_log_date` (`log_created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- الخطوة 6: أعمدة جديدة على admin_users
-- ============================================================
-- ⭐ ربط الموظف بدوره الجديد (NULL يعني استخدم admin_role القديم)
ALTER TABLE `admin_users`
  ADD COLUMN `admin_role_id` TINYINT UNSIGNED DEFAULT NULL
    COMMENT 'الدور الجديد من admin_roles' AFTER `admin_role`;

-- من عيّن هذا الموظف (للمساءلة)
ALTER TABLE `admin_users`
  ADD COLUMN `admin_created_by` INT(11) DEFAULT NULL
    COMMENT 'معرّف الموظف الذي أنشأ هذا الحساب' AFTER `admin_is_active`;

-- ============================================================
-- ⭐ الخطوة 7: أعمدة تتبّع الحسابات المُنشأة من الإدارة
-- ============================================================
-- ⭐ من أنشأ حساب المستخدم (NULL = سجّل نفسه من التطبيق)
ALTER TABLE `users`
  ADD COLUMN `users_created_by_admin` INT(11) DEFAULT NULL
    COMMENT 'معرّف الموظف الذي أنشأ الحساب نيابة عن المستخدم'
    AFTER `users_type`;

-- ⚠️ 1 = كلمة المرور مؤقتة ويجب تغييرها في أول دخول
ALTER TABLE `users`
  ADD COLUMN `users_password_is_temp` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'كلمة مرور مؤقتة من الإدارة — يجب تغييرها'
    AFTER `users_created_by_admin`;

-- من أنشأ صف الشركة
ALTER TABLE `company_registering`
  ADD COLUMN `company_created_by_admin` INT(11) DEFAULT NULL
    COMMENT 'معرّف الموظف الذي سجّل الشركة نيابة عن صاحبها'
    AFTER `company_tax_card_pdf`;

-- من أنشأ صف طلب العمالة
ALTER TABLE `job_details_required`
  ADD COLUMN `required_created_by_admin` INT(11) DEFAULT NULL
    COMMENT 'معرّف الموظف الذي أضاف الطلب نيابة عن الشركة'
    AFTER `required_review_note`;

-- ============================================================
-- الخطوة 8: إدخال الأدوار الخمسة
-- ============================================================
INSERT INTO `admin_roles`
  (`role_key`, `role_name_ar`, `role_name_en`, `role_description_ar`,
   `role_level`, `role_is_system`)
VALUES
  -- المالك: كل الصلاحيات ويُدير قواعد الصلاحيات نفسها
  ('owner', 'المالك', 'Owner',
   'كل الصلاحيات — يُعيّن المديرين ويُعدّل مصفوفة الصلاحيات', 100, 1),
  -- المدير: كل شيء عدا تعديل مصفوفة الأدوار والتقارير المالية
  ('manager', 'مدير', 'Manager',
   'يُدير العمليات والموظفين الأقل منه — لا يُعدّل مصفوفة الأدوار', 80, 1),
  -- المحاسب: قراءة كل شيء + التقارير المالية والكوبونات
  ('accountant', 'محاسب', 'Accountant',
   'التقارير المالية والكوبونات — قراءة فقط لباقي الأقسام', 60, 0),
  -- خدمة العملاء: مراجعة وتعديل بلا إنشاء حسابات
  ('support', 'خدمة العملاء', 'Customer Service',
   'مراجعة الطلبات وتعديل البيانات — لا يُنشئ حسابات جديدة', 40, 1),
  -- ⭐ المندوب: إنشاء فقط بلا تعديل
  ('seller', 'مندوب مبيعات', 'Seller',
   'إضافة الشركات والعملاء ومقدمي الخدمة — لا يُعدّل بياناتهم', 20, 0)
ON DUPLICATE KEY UPDATE `role_name_ar` = VALUES(`role_name_ar`);

-- ============================================================
-- الخطوة 9: إدخال كتالوج الصلاحيات (30 صلاحية)
-- ============================================================
INSERT INTO `admin_permissions`
  (`perm_key`, `perm_name_ar`, `perm_group_ar`, `perm_sort`)
VALUES
  -- ===== الشركات =====
  ('companies.view',      'عرض الشركات',            'الشركات', 1),
  ('companies.create',    'إضافة شركة جديدة',        'الشركات', 2),
  ('companies.edit',      'تعديل بيانات الشركات',    'الشركات', 3),
  ('companies.delete',    'حذف شركة',                'الشركات', 4),
  -- ===== طلبات العمالة =====
  ('jobs.view',           'عرض طلبات العمالة',       'طلبات العمالة', 1),
  ('jobs.create',         'إضافة طلب عمالة',         'طلبات العمالة', 2),
  ('jobs.edit',           'تعديل طلبات العمالة',     'طلبات العمالة', 3),
  ('jobs.approve',        'قبول ورفض الطلبات',       'طلبات العمالة', 4),
  -- ===== مقدمو الخدمة =====
  ('providers.view',      'عرض مقدمي الخدمة',        'مقدمو الخدمة', 1),
  ('providers.create',    'إضافة مقدم خدمة',         'مقدمو الخدمة', 2),
  ('providers.edit',      'تعديل مقدمي الخدمة',      'مقدمو الخدمة', 3),
  ('providers.approve',   'توثيق ورفض المزودين',     'مقدمو الخدمة', 4),
  ('providers.documents', 'عرض مستندات المزودين',    'مقدمو الخدمة', 5),
  -- ===== العملاء =====
  ('clients.view',        'عرض العملاء',             'العملاء', 1),
  ('clients.create',      'إضافة عميل',              'العملاء', 2),
  ('clients.edit',        'تعديل بيانات العملاء',    'العملاء', 3),
  -- ===== الحجوزات =====
  ('bookings.view',       'عرض الحجوزات',            'الحجوزات', 1),
  ('bookings.edit',       'تعديل الحجوزات',          'الحجوزات', 2),
  ('bookings.cancel',     'إلغاء حجز',               'الحجوزات', 3),
  -- ===== الأقسام والتوقيتات =====
  ('catalog.view',        'عرض الأقسام والتوقيتات',  'الأقسام', 1),
  ('catalog.manage',      'إدارة الأقسام والتوقيتات','الأقسام', 2),
  -- ===== الكوبونات =====
  ('coupons.view',        'عرض الكوبونات',           'الكوبونات', 1),
  ('coupons.manage',      'إدارة الكوبونات',         'الكوبونات', 2),
  -- ===== الموظفون =====
  ('staff.view',          'عرض الموظفين',            'الموظفون', 1),
  ('staff.create',        'تعيين موظف جديد',         'الموظفون', 2),
  ('staff.edit',          'تعديل وتعطيل الموظفين',   'الموظفون', 3),
  ('staff.permissions',   'تحديد صلاحيات الموظفين',  'الموظفون', 4),
  -- ===== التقارير =====
  ('reports.view',        'عرض التقارير',            'التقارير', 1),
  ('reports.financial',   'عرض التقارير المالية',    'التقارير', 2),
  -- ===== الأدوار والسجل =====
  ('roles.manage',        'إدارة الأدوار والصلاحيات','النظام', 1),
  ('audit.view',          'عرض سجل النشاط',          'النظام', 2)
ON DUPLICATE KEY UPDATE `perm_name_ar` = VALUES(`perm_name_ar`);

-- ============================================================
-- الخطوة 10: ربط الصلاحيات بالأدوار
-- ============================================================
-- ⭐ المالك: كل الصلاحيات بلا استثناء
INSERT IGNORE INTO `admin_role_permissions` (`rp_role_id`, `rp_perm_id`)
SELECT r.role_id, p.perm_id
FROM `admin_roles` r
CROSS JOIN `admin_permissions` p
WHERE r.role_key = 'owner';

-- ⭐ المدير: كل شيء عدا roles.manage و reports.financial و companies.delete
INSERT IGNORE INTO `admin_role_permissions` (`rp_role_id`, `rp_perm_id`)
SELECT r.role_id, p.perm_id
FROM `admin_roles` r
CROSS JOIN `admin_permissions` p
WHERE r.role_key = 'manager'
  AND p.perm_key NOT IN ('roles.manage', 'reports.financial',
                         'companies.delete');

-- ⭐ المحاسب: قراءة كل شيء + المالية والكوبونات
INSERT IGNORE INTO `admin_role_permissions` (`rp_role_id`, `rp_perm_id`)
SELECT r.role_id, p.perm_id
FROM `admin_roles` r
CROSS JOIN `admin_permissions` p
WHERE r.role_key = 'accountant'
  AND p.perm_key IN ('companies.view', 'jobs.view', 'providers.view',
                     'clients.view', 'bookings.view', 'catalog.view',
                     'coupons.view', 'coupons.manage',
                     'reports.view', 'reports.financial', 'audit.view');

-- ⭐ خدمة العملاء: مراجعة وتعديل بلا إنشاء حسابات
INSERT IGNORE INTO `admin_role_permissions` (`rp_role_id`, `rp_perm_id`)
SELECT r.role_id, p.perm_id
FROM `admin_roles` r
CROSS JOIN `admin_permissions` p
WHERE r.role_key = 'support'
  AND p.perm_key IN ('companies.view', 'companies.edit',
                     'jobs.view', 'jobs.edit', 'jobs.approve',
                     'providers.view', 'providers.edit',
                     'providers.approve', 'providers.documents',
                     'clients.view', 'clients.edit',
                     'bookings.view', 'bookings.edit',
                     'catalog.view', 'coupons.view');

-- ⭐⭐ المندوب: إنشاء فقط بلا أي تعديل (كما طلبت حرفياً)
INSERT IGNORE INTO `admin_role_permissions` (`rp_role_id`, `rp_perm_id`)
SELECT r.role_id, p.perm_id
FROM `admin_roles` r
CROSS JOIN `admin_permissions` p
WHERE r.role_key = 'seller'
  AND p.perm_key IN ('companies.view', 'companies.create',
                     'jobs.view', 'jobs.create',
                     'providers.view', 'providers.create',
                     'clients.view', 'clients.create',
                     'catalog.view');

-- ============================================================
-- ⭐ الخطوة 11: ترقية الموظفين الحاليين للأدوار الجديدة
-- ============================================================
-- ربط كل موظف بدور يطابق قيمة admin_role القديمة
UPDATE `admin_users` au
JOIN `admin_roles` r ON r.role_key = au.admin_role
SET au.admin_role_id = r.role_id
WHERE au.admin_role_id IS NULL;

-- ============================================================
-- ⚠️⚠️ الخطوة 12: ترقية admin_id = 1 إلى مالك
--
-- ⚠️ نفّذ هذا السطر بعد التأكد أن admin_id = 1 هو حسابك أنت
--    وإلا سيحصل شخص آخر على كل الصلاحيات
-- ============================================================
UPDATE `admin_users`
SET `admin_role_id` = (SELECT role_id FROM `admin_roles`
                       WHERE role_key = 'owner')
WHERE `admin_id` = 1;


-- ============================================================
-- ⭐ الخطوة 2: منح clients.create لدور خدمة العملاء
--
-- ⭐ المالك والمدير يملكانها أصلاً تلقائياً (منح شامل في ملف RBAC)
--   فالملف يهتم بنقص خدمة العملاء فقط.
-- ============================================================
INSERT IGNORE INTO `admin_role_permissions` (`rp_role_id`, `rp_perm_id`)
SELECT r.role_id, p.perm_id
FROM `admin_roles` r
CROSS JOIN `admin_permissions` p
WHERE r.role_key = 'support'
  AND p.perm_key = 'clients.create';

-- ============================================================
-- ⭐ الخطوة 3: ضمان صلاحيات القسم الجديد كاملة لخدمة العملاء
--
-- ⭐ القسم يحتاج: إنشاء عميل + عرض/تعديل الحجوزات + عرض الكوبونات
--   والثلاثة الأخيرة موجودة أصلاً، وهذا الحفظ احتياطي لا يضر.
-- ============================================================
INSERT IGNORE INTO `admin_role_permissions` (`rp_role_id`, `rp_perm_id`)
SELECT r.role_id, p.perm_id
FROM `admin_roles` r
CROSS JOIN `admin_permissions` p
WHERE r.role_key = 'support'
  AND p.perm_key IN ('clients.view', 'clients.create', 'clients.edit',
                     'bookings.view', 'bookings.edit',
                     'coupons.view', 'providers.view');

-- ============================================================
-- ⭐ الخطوة 4: تحقق سريع — يعرض ما مُنح فعلاً لدور support
-- يمكن تجاهل نتيجته، فهو للاطمئنان فقط.
-- ============================================================
-- SELECT p.perm_key
-- FROM `admin_roles` r
-- JOIN `admin_role_permissions` rp ON rp.rp_role_id = r.role_id
-- JOIN `admin_permissions` p      ON p.perm_id     = rp.rp_perm_id
-- WHERE r.role_key = 'support'
--   AND p.perm_key LIKE 'clients.%';