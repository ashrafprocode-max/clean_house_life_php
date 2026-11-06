===============================================
-جدول التخصصات الدقيقة  
- sub_categories
===============================================
CREATE TABLE `sub_categories` (
  `sub_id` INT(11) NOT NULL AUTO_INCREMENT,
  `sub_category_id` INT(11) NOT NULL,
  `sub_name_ar` VARCHAR(100) NOT NULL,
  `sub_name_en` VARCHAR(100) NOT NULL,
  PRIMARY KEY (`sub_id`),
  FOREIGN KEY (`sub_category_id`) REFERENCES `categories`(`categories_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;