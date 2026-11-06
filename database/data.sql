-- ============================================================
-- ملف: database/seed_data.sql
-- الغرض: بيانات أولية لجداول categories و sub_categories و service_timing
-- نفذ هذا الملف مرة واحدة فقط لملء الجداول بالبيانات الافتراضية
-- ============================================================

-- ============================================================
-- 1. الأقسام الرئيسية (categories)
-- ============================================================
INSERT INTO `categories` (`categories_name_ar`, `categories_name_en`, `categories_image`) VALUES
('نظافة منزلية',    'House Cleaning',       'cat_cleaning.png'),
('ضيافة وطهي',      'Hospitality & Cooking','cat_cooking.png'),
('سباكة وكهرباء',  'Plumbing & Electric',  'cat_plumbing.png'),
('رعاية أطفال',    'Child Care',            'cat_childcare.png'),
('رعاية مسنين',    'Elderly Care',          'cat_elderly.png'),
('رعاية صحية',     'Healthcare',            'cat_healthcare.png'),
('تعليم وتدريس',   'Tutoring',              'cat_tutoring.png'),
('أعمال مكتبية',   'Office Work',           'cat_office.png');

-- ============================================================
-- 2. الأقسام الفرعية (sub_categories)
-- sub_category_id = ID القسم الرئيسي الأب
-- ============================================================
INSERT INTO `sub_categories` (`sub_category_id`, `sub_name_ar`, `sub_name_en`) VALUES
-- نظافة منزلية (cat_id=1)
(1, 'تنظيف عام',          'General Cleaning'),
(1, 'تنظيف عميق',         'Deep Cleaning'),
(1, 'تنظيف مطابخ',        'Kitchen Cleaning'),
(1, 'تنظيف سجاد وأرضيات', 'Carpet & Floor Cleaning'),
(1, 'غسيل ملابس',         'Laundry Service'),
-- ضيافة وطهي (cat_id=2)
(2, 'طبخ يومي',           'Daily Cooking'),
(2, 'طبخ ومناسبات',       'Catering & Events'),
(2, 'تنظيف وطبخ',         'Cleaning & Cooking'),
(2, 'إدارة منزل كامل',    'Full House Management'),
-- سباكة وكهرباء (cat_id=3)
(3, 'سباكة',              'Plumbing'),
(3, 'كهرباء',             'Electrical'),
(3, 'تكييف وتبريد',       'AC & Cooling'),
(3, 'نجارة',              'Carpentry'),
-- رعاية أطفال (cat_id=4)
(4, 'حضانة منزلية',       'Home Daycare'),
(4, 'جليسة أطفال',        'Babysitter'),
(4, 'مدرب أطفال',         'Child Coach'),
-- رعاية مسنين (cat_id=5)
(5, 'مرافق مسنين',        'Elderly Companion'),
(5, 'ممرض منزلي',         'Home Nurse'),
-- رعاية صحية (cat_id=6)
(6, 'ممرضة',              'Nurse'),
(6, 'فيزيوثيرابي',        'Physiotherapy'),
(6, 'مساعد طبي',          'Medical Assistant'),
-- تعليم وتدريس (cat_id=7)
(7, 'مدرس خصوصي',         'Private Tutor'),
(7, 'معلم لغات',          'Language Teacher'),
(7, 'تدريب مهني',         'Vocational Training'),
-- أعمال مكتبية (cat_id=8)
(8, 'سكرتارية',           'Secretary'),
(8, 'محاسبة',             'Accounting'),
(8, 'تصميم جرافيك',       'Graphic Design');

-- ============================================================
-- 3. أوقات الخدمة (service_timing)
-- ============================================================
INSERT INTO `service_timing` (`timing_name_ar`, `timing_name_en`) VALUES
('إقامة كاملة',         'Full Residence'),
('يومي (زيارة)',        'Daily Visit'),
('ساعات (مهمة محددة)', 'Hourly Task'),
('أسبوعي',              'Weekly'),
('شهري',                'Monthly');

-- ============================================================
-- قواعد العمولة المقترحة (للمرجعية فقط، تُطبق في PHP):
--   إقامة كاملة  → fixed,   value = راتب شهر كامل (يدخله مقدم الخدمة)
--   يومي         → fixed,   value = 50 ج.م لكل زيارة
--   ساعات        → percent, value = 10% من السعر
--   أسبوعي       → percent, value = 10% من الأجرة الأسبوعية
--   شهري         → fixed,   value = راتب شهر كامل
-- ============================================================