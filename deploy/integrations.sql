-- ============================================================
-- DI PARMA | integrations.sql
-- هيكل جدول التكاملات
-- ============================================================

CREATE TABLE IF NOT EXISTS `dp_integrations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category` VARCHAR(48) NOT NULL COMMENT 'bank|gateway|wallet|otc|ramp',
  `subtype` VARCHAR(64) DEFAULT NULL COMMENT 'optional subtype or network',
  `code` VARCHAR(128) DEFAULT NULL COMMENT 'machine code or identifier',
  `name` VARCHAR(191) NOT NULL,
  `metadata` JSON DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_category` (`category`),
  INDEX `idx_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- البيانات الأولية
-- ============================================================

INSERT INTO `dp_integrations` (`category`, `code`, `name`, `metadata`) VALUES
('gateway', 'STRIPE', 'Stripe', JSON_OBJECT(
  'environment', 'live',
  'currencies', JSON_ARRAY('USD', 'EUR', 'GBP', 'AED'),
  'fees', JSON_OBJECT('percentage', 2.9, 'fixed', 0.30)
)),
('gateway', 'PAYPAL', 'PayPal', JSON_OBJECT(
  'environment', 'live',
  'currencies', JSON_ARRAY('USD', 'EUR', 'GBP', 'AED')
)),
('gateway', 'WISE', 'Wise', JSON_OBJECT(
  'environment', 'live',
  'currencies', JSON_ARRAY('USD', 'EUR', 'GBP', 'AED', 'SAR')
)),
('gateway', 'DIPARMA', 'DI PARMA Gateway', JSON_OBJECT(
  'environment', 'live',
  'currencies', JSON_ARRAY('USD', 'AED', 'SAR', 'EUR', 'GBP', 'USDT')
)),
('wallet', 'LEDGER_TRX', 'Ledger TRX (USDT)', JSON_OBJECT(
  'network', 'TRC20',
  'currency', 'USDT',
  'explorer', 'https://tronscan.org'
)),
('bank', 'MASHREQ', 'Mashreq Bank PSC', JSON_OBJECT(
  'country', 'AE',
  'swift', 'BOMLAEADXXX',
  'iban', 'AE300330000019101562722'
));