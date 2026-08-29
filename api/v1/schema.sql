-- ============================================================
-- DI PARMA | قاعدة البيانات الكاملة
-- ============================================================
-- 
-- المحتويات:
-- 1. dp_api_clients      - عملاء API
-- 2. dp_api_logs         - سجلات API
-- 3. dp_api_webhooks     - قائمة انتظار Webhooks
-- 4. dp_transactions     - المعاملات
-- 5. dp_transaction_ledger - سجلات Ledger
-- 6. payment_gateways    - بوابات الدفع
-- 7. ledger_transfer_queue - قائمة انتظار تحويلات Ledger
-- 8. dp_users            - المستخدمين
-- 9. dp_sessions         - الجلسات
-- 10. dp_settings        - إعدادات النظام
-- 11. dp_notifications   - الإشعارات
-- 12. dp_fraud_logs      - سجلات الاحتيال
-- 13. dp_audit_logs      - سجلات التدقيق
-- 
-- ============================================================

-- ============================================================
-- 1. dp_api_clients - عملاء API
-- ============================================================

CREATE TABLE IF NOT EXISTS `dp_api_clients` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`            VARCHAR(255) NOT NULL COMMENT 'اسم التاجر/التطبيق',
    `api_key`         VARCHAR(64)  NOT NULL UNIQUE COMMENT 'API_K — مفتاح عام',
    `api_secret`      VARCHAR(255) NOT NULL COMMENT 'API_S — مفتاح سري (مشفر)',
    `webhook_secret`  VARCHAR(255) NOT NULL COMMENT 'WEBHOOK_S — لتوقيع الـ webhooks',
    `mid`             VARCHAR(32)  NOT NULL UNIQUE COMMENT 'Merchant ID',
    `tid`             VARCHAR(32)  NOT NULL COMMENT 'Terminal ID',
    `ledger_address`  VARCHAR(100) NOT NULL DEFAULT 'TEwLFWlwK55b7PuFfzgH1H2f3xs3pLgLn2',
    `webhook_url`     VARCHAR(500) DEFAULT NULL,
    `status`          ENUM('active','suspended','revoked') NOT NULL DEFAULT 'active',
    `permissions`     JSON DEFAULT NULL COMMENT '["charge","refund","balance","void"]',
    `daily_limit`     DECIMAL(12,2) DEFAULT 50000.00,
    `monthly_limit`   DECIMAL(14,2) DEFAULT 500000.00,
    `total_charged`   DECIMAL(14,2) DEFAULT 0.00,
    `total_txns`      INT UNSIGNED DEFAULT 0,
    `last_used_at`    DATETIME DEFAULT NULL,
    `last_ip`         VARCHAR(45)  DEFAULT NULL,
    `created_by`      INT UNSIGNED DEFAULT 0,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_api_key` (`api_key`),
    INDEX `idx_mid`     (`mid`),
    INDEX `idx_status`  (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='API Clients — بوابة DI PARMA';

-- ============================================================
-- 2. dp_api_logs - سجلات API
-- ============================================================

CREATE TABLE IF NOT EXISTS `dp_api_logs` (
    `id`           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `client_id`    INT UNSIGNED NOT NULL,
    `api_key`      VARCHAR(64)  NOT NULL,
    `endpoint`     VARCHAR(100) NOT NULL,
    `method`       VARCHAR(10)  NOT NULL,
    `request_body` TEXT DEFAULT NULL,
    `response_code`SMALLINT UNSIGNED NOT NULL DEFAULT 200,
    `response_body`TEXT DEFAULT NULL,
    `ip`           VARCHAR(45)  DEFAULT NULL,
    `duration_ms`  INT UNSIGNED DEFAULT NULL,
    `reference`    VARCHAR(100) DEFAULT NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_client`  (`client_id`),
    INDEX `idx_created` (`created_at`),
    INDEX `idx_reference` (`reference`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. dp_api_webhooks - قائمة انتظار Webhooks
-- ============================================================

CREATE TABLE IF NOT EXISTS `dp_api_webhooks` (
    `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `client_id`   INT UNSIGNED NOT NULL,
    `event`       VARCHAR(50) NOT NULL COMMENT 'charge.completed, charge.failed, refund.done',
    `payload`     JSON NOT NULL,
    `status`      ENUM('pending','delivered','failed') DEFAULT 'pending',
    `attempts`    TINYINT UNSIGNED DEFAULT 0,
    `next_retry`  DATETIME DEFAULT NULL,
    `delivered_at`DATETIME DEFAULT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_client_status` (`client_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. dp_transactions - المعاملات (الجدول الرئيسي)
-- ============================================================

CREATE TABLE IF NOT EXISTS `dp_transactions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `reference` VARCHAR(50) NOT NULL UNIQUE COMMENT 'مرجع المعاملة',
    `user_id` INT UNSIGNED DEFAULT NULL COMMENT 'معرف المستخدم',
    `client_id` INT UNSIGNED DEFAULT NULL COMMENT 'معرف عميل API',
    
    -- بيانات البوابة
    `gateway` VARCHAR(50) NOT NULL COMMENT 'اسم البوابة (wise, stripe, paypal, diparma)',
    `gateway_type` VARCHAR(20) DEFAULT NULL COMMENT 'card, bank, crypto, wallet',
    `gateway_transaction_id` VARCHAR(255) DEFAULT NULL COMMENT 'معرف المعاملة في البوابة',
    `gateway_transfer_id` VARCHAR(255) DEFAULT NULL COMMENT 'معرف التحويل (لـ Wise)',
    
    -- نوع المعاملة
    `transaction_type` VARCHAR(50) NOT NULL COMMENT 'purchase_3d, purchase_2d, refund, etc',
    `transaction_label` VARCHAR(100) DEFAULT NULL COMMENT 'تسمية العملية',
    `iso_msg_type` VARCHAR(10) DEFAULT NULL COMMENT '0200, 0100, 0220, 0400',
    `security_mode` VARCHAR(10) DEFAULT NULL COMMENT '3D, 2D',
    `moto_indicator` VARCHAR(5) DEFAULT NULL COMMENT 'M, T, F, E',
    `is_moto` TINYINT(1) DEFAULT 0,
    `is_advice` TINYINT(1) DEFAULT 0,
    `is_offline` TINYINT(1) DEFAULT 0,
    
    -- بيانات المبلغ
    `amount` DECIMAL(15,2) NOT NULL,
    `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
    `ledger_amount` DECIMAL(15,6) DEFAULT NULL COMMENT 'المبلغ المحول إلى USDT',
    
    -- بيانات البطاقة
    `card_number` VARCHAR(255) DEFAULT NULL COMMENT 'مشفر',
    `card_last4` VARCHAR(4) DEFAULT NULL,
    `card_brand` VARCHAR(20) DEFAULT NULL COMMENT 'Visa, Mastercard, Amex',
    `cardholder_name` VARCHAR(100) DEFAULT NULL,
    `card_expiry` VARCHAR(10) DEFAULT NULL COMMENT 'MM/YY (مشفر)',
    
    -- بيانات العميل
    `customer_name` VARCHAR(100) DEFAULT NULL,
    `customer_email` VARCHAR(100) DEFAULT NULL,
    `customer_phone` VARCHAR(50) DEFAULT NULL,
    `customer_ip` VARCHAR(45) DEFAULT NULL,
    `customer_country` VARCHAR(100) DEFAULT NULL,
    `customer_city` VARCHAR(100) DEFAULT NULL,
    `customer_address` TEXT DEFAULT NULL,
    
    -- بيانات التاجر
    `merchant_id` VARCHAR(50) DEFAULT NULL,
    `terminal_id` VARCHAR(50) DEFAULT NULL,
    `acquirer` VARCHAR(100) DEFAULT NULL COMMENT 'Mashreq Bank PSC, HSBC, JP Morgan',
    
    -- رموز الموافقة
    `auth_code` VARCHAR(50) DEFAULT NULL,
    `approval_code` VARCHAR(50) DEFAULT NULL,
    `rrn` VARCHAR(50) DEFAULT NULL,
    `stan` VARCHAR(50) DEFAULT NULL,
    `original_reference` VARCHAR(50) DEFAULT NULL,
    `original_auth_code` VARCHAR(50) DEFAULT NULL,
    
    -- بيانات التقسيط والاشتراك
    `installment_count` INT DEFAULT 0,
    `installment_amount` DECIMAL(15,2) DEFAULT 0.00,
    `recurring_frequency` VARCHAR(20) DEFAULT NULL COMMENT 'monthly, quarterly, yearly',
    `recurring_occurrences` INT DEFAULT 0,
    
    -- بيانات العملات الرقمية
    `crypto_currency` VARCHAR(10) DEFAULT NULL COMMENT 'USDT, BTC, ETH',
    `crypto_amount` DECIMAL(15,6) DEFAULT NULL,
    `crypto_address` VARCHAR(100) DEFAULT NULL,
    
    -- بيانات بطاقة الهدايا
    `gift_card_amount` DECIMAL(15,2) DEFAULT NULL,
    `recipient_email` VARCHAR(100) DEFAULT NULL,
    `recipient_name` VARCHAR(100) DEFAULT NULL,
    `gift_message` TEXT DEFAULT NULL,
    
    -- بيانات التحويل البنكي
    `bank_name` VARCHAR(100) DEFAULT NULL,
    `bank_account` VARCHAR(100) DEFAULT NULL,
    `bank_iban` VARCHAR(50) DEFAULT NULL,
    `bank_swift` VARCHAR(20) DEFAULT NULL,
    
    -- بيانات Ledger
    `ledger_address` VARCHAR(100) DEFAULT NULL,
    `ledger_txid` VARCHAR(100) DEFAULT NULL,
    `ledger_transferred` TINYINT(1) DEFAULT 0,
    `ledger_status` VARCHAR(20) DEFAULT NULL COMMENT 'pending, queued, sent, failed',
    
    -- الحالة
    `status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending, processing, completed, failed, cancelled, refunded, pending_ledger',
    `error_message` TEXT DEFAULT NULL,
    `error_code` VARCHAR(50) DEFAULT NULL,
    
    -- الرد من البوابة
    `gateway_response` JSON DEFAULT NULL,
    
    -- بيانات إضافية
    `notes` TEXT DEFAULT NULL,
    `metadata` JSON DEFAULT NULL,
    `pos_device` VARCHAR(50) DEFAULT NULL,
    `user_agent` TEXT DEFAULT NULL,
    
    -- الطوابع الزمنية
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `processed_at` DATETIME DEFAULT NULL,
    `settled_at` DATETIME DEFAULT NULL,
    
    -- الفهارس
    INDEX `idx_reference` (`reference`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_client_id` (`client_id`),
    INDEX `idx_gateway` (`gateway`),
    INDEX `idx_status` (`status`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_auth_code` (`auth_code`),
    INDEX `idx_rrn` (`rrn`),
    INDEX `idx_ledger_txid` (`ledger_txid`),
    INDEX `idx_original_reference` (`original_reference`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='المعاملات المالية';

-- ============================================================
-- 5. dp_transaction_ledger - سجلات Ledger
-- ============================================================

CREATE TABLE IF NOT EXISTS `dp_transaction_ledger` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `reference` VARCHAR(50) NOT NULL,
    `transaction_id` INT UNSIGNED NOT NULL,
    `ledger_address` VARCHAR(100) NOT NULL,
    `usdt_amount` DECIMAL(15,6) NOT NULL,
    `txid` VARCHAR(100) DEFAULT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending, sent, confirmed, failed',
    `confirmations` INT DEFAULT 0,
    `fee` DECIMAL(15,6) DEFAULT 0.00,
    `gas_fee` DECIMAL(15,6) DEFAULT 0.00,
    `network` VARCHAR(20) DEFAULT 'TRC20' COMMENT 'TRC20, ERC20, BEP20',
    `error_message` TEXT DEFAULT NULL,
    `attempts` TINYINT UNSIGNED DEFAULT 0,
    `first_attempt_at` DATETIME DEFAULT NULL,
    `last_attempt_at` DATETIME DEFAULT NULL,
    `sent_at` DATETIME DEFAULT NULL,
    `confirmed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_reference` (`reference`),
    INDEX `idx_transaction_id` (`transaction_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6. payment_gateways - بوابات الدفع
-- ============================================================

CREATE TABLE IF NOT EXISTS `payment_gateways` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE COMMENT 'stripe, paypal, wise, diparma',
    `name` VARCHAR(100) NOT NULL,
    `type` VARCHAR(50) DEFAULT 'electronic' COMMENT 'electronic, bank, crypto, game',
    `status` ENUM('active','inactive','maintenance') DEFAULT 'inactive',
    `gateway_type` VARCHAR(20) DEFAULT 'card' COMMENT 'card, wallet, crypto, bank, otc',
    `connection_type` VARCHAR(20) DEFAULT 'rest' COMMENT 'rest, soap, web3, manual',
    `api_endpoint` VARCHAR(255) DEFAULT NULL,
    `api_version` VARCHAR(50) DEFAULT NULL,
    
    -- بيانات الاعتماد (مشفرة)
    `credentials` JSON DEFAULT NULL,
    `settings` JSON DEFAULT NULL,
    `config` JSON DEFAULT NULL,
    
    -- الميزات
    `supports_2d` TINYINT(1) DEFAULT 1,
    `supports_3d` TINYINT(1) DEFAULT 1,
    `supports_hold` TINYINT(1) DEFAULT 0,
    `supports_capture` TINYINT(1) DEFAULT 0,
    `supports_refund` TINYINT(1) DEFAULT 1,
    `supports_void` TINYINT(1) DEFAULT 1,
    `supports_recurring` TINYINT(1) DEFAULT 0,
    `supports_installment` TINYINT(1) DEFAULT 0,
    `supports_crypto` TINYINT(1) DEFAULT 0,
    `supports_gift_card` TINYINT(1) DEFAULT 0,
    `supports_wire_transfer` TINYINT(1) DEFAULT 0,
    
    -- حالة الاتصال
    `connection_status` ENUM('untested','verified','failed') DEFAULT 'untested',
    `last_tested` DATETIME DEFAULT NULL,
    `test_response_ms` INT DEFAULT NULL,
    `test_message` TEXT DEFAULT NULL,
    
    -- الترتيب
    `sort_order` INT DEFAULT 0,
    `is_default` TINYINT(1) DEFAULT 0,
    
    -- الطوابع الزمنية
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX `idx_code` (`code`),
    INDEX `idx_status` (`status`),
    INDEX `idx_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 7. ledger_transfer_queue - قائمة انتظار تحويلات Ledger
-- ============================================================

CREATE TABLE IF NOT EXISTS `ledger_transfer_queue` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `reference` VARCHAR(50) NOT NULL,
    `transaction_id` INT UNSIGNED DEFAULT NULL,
    `ledger_address` VARCHAR(100) NOT NULL,
    `usdt_amount` DECIMAL(15,6) NOT NULL,
    `currency_orig` VARCHAR(10) DEFAULT NULL,
    `transaction_type` VARCHAR(50) DEFAULT NULL,
    `status` ENUM('queued','processing','completed','failed') DEFAULT 'queued',
    `txid` VARCHAR(100) DEFAULT NULL,
    `message` TEXT DEFAULT NULL,
    `attempts` TINYINT UNSIGNED DEFAULT 0,
    `max_attempts` TINYINT UNSIGNED DEFAULT 5,
    `next_retry_at` DATETIME DEFAULT NULL,
    `processed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_reference` (`reference`),
    INDEX `idx_status` (`status`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 8. dp_users - المستخدمين
-- ============================================================

CREATE TABLE IF NOT EXISTS `dp_users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `phone` VARCHAR(50) DEFAULT NULL,
    `full_name` VARCHAR(100) DEFAULT NULL,
    `role` VARCHAR(20) NOT NULL DEFAULT 'user' COMMENT 'admin, manager, user, viewer',
    `status` ENUM('active','inactive','suspended') DEFAULT 'active',
    `last_login` DATETIME DEFAULT NULL,
    `last_ip` VARCHAR(45) DEFAULT NULL,
    `two_factor_enabled` TINYINT(1) DEFAULT 0,
    `two_factor_secret` VARCHAR(255) DEFAULT NULL,
    `reset_token` VARCHAR(255) DEFAULT NULL,
    `reset_token_expiry` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_username` (`username`),
    INDEX `idx_email` (`email`),
    INDEX `idx_status` (`status`),
    INDEX `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 9. dp_sessions - الجلسات
-- ============================================================

CREATE TABLE IF NOT EXISTS `dp_sessions` (
    `id` VARCHAR(255) NOT NULL PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` TEXT DEFAULT NULL,
    `payload` TEXT NOT NULL,
    `last_activity` INT UNSIGNED NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_last_activity` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 10. dp_settings - إعدادات النظام
-- ============================================================

CREATE TABLE IF NOT EXISTS `dp_settings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(100) NOT NULL UNIQUE,
    `value` TEXT DEFAULT NULL,
    `group` VARCHAR(50) DEFAULT 'general',
    `type` VARCHAR(20) DEFAULT 'string' COMMENT 'string, int, float, boolean, json',
    `description` TEXT DEFAULT NULL,
    `is_encrypted` TINYINT(1) DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_key` (`key`),
    INDEX `idx_group` (`group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 11. dp_notifications - الإشعارات
-- ============================================================

CREATE TABLE IF NOT EXISTS `dp_notifications` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `type` VARCHAR(50) NOT NULL COMMENT 'email, sms, telegram, dashboard',
    `channel` VARCHAR(50) NOT NULL COMMENT 'payment, security, system, admin',
    `subject` VARCHAR(255) NOT NULL,
    `message` TEXT NOT NULL,
    `data` JSON DEFAULT NULL,
    `status` ENUM('pending','sent','failed','read') DEFAULT 'pending',
    `sent_at` DATETIME DEFAULT NULL,
    `read_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_type` (`type`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 12. dp_fraud_logs - سجلات الاحتيال
-- ============================================================

CREATE TABLE IF NOT EXISTS `dp_fraud_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `transaction_id` INT UNSIGNED DEFAULT NULL,
    `reference` VARCHAR(50) DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `email` VARCHAR(100) DEFAULT NULL,
    `card_number` VARCHAR(255) DEFAULT NULL COMMENT 'مشفر',
    `score` DECIMAL(5,2) DEFAULT 0.00 COMMENT 'نسبة المخاطر 0-100',
    `risk_level` VARCHAR(20) DEFAULT 'low' COMMENT 'low, medium, high, critical',
    `flags` JSON DEFAULT NULL COMMENT '["unusual_amount", "high_velocity", "suspicious_ip"]',
    `details` JSON DEFAULT NULL,
    `action` VARCHAR(50) DEFAULT 'allow' COMMENT 'allow, block, review',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_transaction_id` (`transaction_id`),
    INDEX `idx_reference` (`reference`),
    INDEX `idx_ip_address` (`ip_address`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_risk_level` (`risk_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 13. dp_audit_logs - سجلات التدقيق
-- ============================================================

CREATE TABLE IF NOT EXISTS `dp_audit_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `action` VARCHAR(100) NOT NULL COMMENT 'login, logout, charge, refund, void, update',
    `resource` VARCHAR(100) DEFAULT NULL COMMENT 'transaction, gateway, user, setting',
    `resource_id` VARCHAR(100) DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` TEXT DEFAULT NULL,
    `old_data` JSON DEFAULT NULL,
    `new_data` JSON DEFAULT NULL,
    `details` JSON DEFAULT NULL,
    `status` VARCHAR(20) DEFAULT 'success',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_action` (`action`),
    INDEX `idx_resource` (`resource`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 14. dp_currencies - العملات
-- ============================================================

CREATE TABLE IF NOT EXISTS `dp_currencies` (
    `code` VARCHAR(10) NOT NULL PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `symbol` VARCHAR(10) NOT NULL,
    `decimal` TINYINT UNSIGNED DEFAULT 2,
    `rate` DECIMAL(15,6) DEFAULT 1.000000 COMMENT 'سعر الصرف مقابل الدولار',
    `is_crypto` TINYINT(1) DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 15. dp_webhook_events - أحداث Webhook
-- ============================================================

CREATE TABLE IF NOT EXISTS `dp_webhook_events` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `event` VARCHAR(50) NOT NULL,
    `reference` VARCHAR(50) DEFAULT NULL,
    `payload` JSON NOT NULL,
    `status` ENUM('pending','processed','failed') DEFAULT 'pending',
    `attempts` TINYINT UNSIGNED DEFAULT 0,
    `error_message` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `processed_at` DATETIME DEFAULT NULL,
    INDEX `idx_event` (`event`),
    INDEX `idx_reference` (`reference`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 16. dp_balance_history - سجل الرصيد
-- ============================================================

CREATE TABLE IF NOT EXISTS `dp_balance_history` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `client_id` INT UNSIGNED NOT NULL,
    `amount` DECIMAL(15,2) NOT NULL,
    `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
    `type` VARCHAR(20) NOT NULL COMMENT 'charge, refund, fee, settlement',
    `reference` VARCHAR(50) DEFAULT NULL,
    `description` TEXT DEFAULT NULL,
    `balance_before` DECIMAL(15,2) NOT NULL,
    `balance_after` DECIMAL(15,2) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_client_id` (`client_id`),
    INDEX `idx_type` (`type`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- البيانات الأولية (Seed Data)
-- ============================================================

-- إضافة مستخدم مسؤول
INSERT INTO `dp_users` (`username`, `password_hash`, `email`, `full_name`, `role`, `status`)
VALUES (
    'admin',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- password = admin123
    'admin@diparmas.com',
    'Admin',
    'admin',
    'active'
);

-- إضافة العملات الأساسية
INSERT INTO `dp_currencies` (`code`, `name`, `symbol`, `decimal`, `rate`, `is_crypto`) VALUES
('USD', 'دولار أمريكي', '$', 2, 1.000000, 0),
('AED', 'درهم إماراتي', 'د.إ', 2, 0.272300, 0),
('SAR', 'ريال سعودي', 'ر.س', 2, 0.266700, 0),
('EUR', 'يورو', '€', 2, 1.082000, 0),
('GBP', 'جنيه إسترليني', '£', 2, 1.271000, 0),
('KWD', 'دينار كويتي', 'د.ك', 3, 3.257000, 0),
('QAR', 'ريال قطري', 'ر.ق', 2, 0.274700, 0),
('EGP', 'جنيه مصري', 'ج.م', 2, 0.020400, 0),
('USDT', 'Tether USD', '₮', 6, 1.000000, 1),
('BTC', 'Bitcoin', '₿', 8, 1.000000, 1),
('ETH', 'Ethereum', 'Ξ', 8, 1.000000, 1);

-- إضافة بوابة DI PARMA الافتراضية
INSERT INTO `payment_gateways` (`code`, `name`, `type`, `status`, `gateway_type`, `supports_3d`, `supports_2d`) VALUES
('diparma', 'DI PARMA Gateway', 'electronic', 'active', 'card', 1, 1),
('stripe', 'Stripe', 'electronic', 'inactive', 'card', 1, 1),
('paypal', 'PayPal', 'electronic', 'inactive', 'wallet', 1, 1),
('wise', 'Wise', 'bank', 'inactive', 'bank', 0, 1);

-- إعدادات النظام الأساسية
INSERT INTO `dp_settings` (`key`, `value`, `group`, `type`, `description`) VALUES
('site_name', 'DI PARMA', 'general', 'string', 'اسم الموقع'),
('site_url', 'https://diparmas.com', 'general', 'string', 'رابط الموقع'),
('timezone', 'Asia/Dubai', 'general', 'string', 'المنطقة الزمنية'),
('currency_default', 'USD', 'payment', 'string', 'العملة الافتراضية'),
('currency_decimals', '2', 'payment', 'int', 'عدد الأرقام العشرية'),
('txn_prefix', 'DP', 'payment', 'string', 'بادئة المرجع'),
('max_amount', '50000', 'payment', 'float', 'الحد الأقصى للمبلغ'),
('min_amount', '1', 'payment', 'float', 'الحد الأدنى للمبلغ'),
('ledger_enabled', '1', 'ledger', 'boolean', 'تفعيل Ledger'),
('ledger_network', 'TRC20', 'ledger', 'string', 'شبكة Ledger'),
('ledger_auto_transfer', '1', 'ledger', 'boolean', 'تحويل تلقائي'),
('notification_email', 'admin@diparmas.com', 'notifications', 'string', 'بريد الإشعارات'),
('notification_sms', '971501234567', 'notifications', 'string', 'رقم SMS'),
('security_encryption_key', '', 'security', 'string', 'مفتاح التشفير'),
('security_hmac_key', '', 'security', 'string', 'مفتاح HMAC'),
('api_rate_limit', '100', 'security', 'int', 'حد الطلبات في الدقيقة'),
('webhook_retry_attempts', '5', 'webhook', 'int', 'عدد محاولات إعادة Webhook'),
('webhook_retry_delay', '60', 'webhook', 'int', 'تأخير إعادة المحاولة (ثواني)');

-- ============================================================
-- الفهارس الإضافية
-- ============================================================

-- تحسين الأداء للاستعلامات المتكررة
CREATE INDEX idx_transactions_status_created ON dp_transactions (`status`, `created_at`);
CREATE INDEX idx_transactions_gateway_status ON dp_transactions (`gateway`, `status`);
CREATE INDEX idx_transactions_user_status ON dp_transactions (`user_id`, `status`);
CREATE INDEX idx_ledger_queue_status ON ledger_transfer_queue (`status`, `created_at`);

-- ============================================================
-- نهاية الملف
-- ============================================================