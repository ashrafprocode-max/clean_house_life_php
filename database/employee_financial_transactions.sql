CREATE TABLE IF NOT EXISTS `employee_financial_transactions` (
  `transaction_id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للحركة',
  `employee_id` INT UNSIGNED NOT NULL COMMENT 'معرف الموظف',
  `transaction_month` VARCHAR(7) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'شهر الحركة بصيغة YYYY-MM',
  `transaction_type` ENUM('advance','bonus','incentive','overtime','deduction') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'نوع الحركة المالية',
  `transaction_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'قيمة المبلغ',
  `transaction_notes` TEXT COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'ملاحظات أو بيان الحركة',
  `transaction_date` DATE NOT NULL COMMENT 'تاريخ حدوث الحركة',
  PRIMARY KEY (`transaction_id`),
  KEY `idx_trans_employee` (`employee_id`),
  KEY `idx_trans_month` (`transaction_month`),
  CONSTRAINT `fk_trans_employee`
    FOREIGN KEY (`employee_id`)
    REFERENCES `employees` (`employee_id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;