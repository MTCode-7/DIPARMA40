-- ============================================================
-- DI PARMA | إنشاء قاعدة البيانات والجداول
-- ============================================================

CREATE DATABASE IF NOT EXISTS `diparma_gateway`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_general_ci;

USE `diparma_gateway`;

-- ============================================================
-- جدول المستخدمين
-- ============================================================
CREATE TABLE IF NOT EXISTS `dp_users` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username`        VARCHAR(100) NOT NULL UNIQUE,
    `email`           VARCHAR(255) NOT NULL UNIQUE,
    `password_hash`   VARCHAR(255) NOT NULL,
    `first_name`      VARCHAR(100) DEFAULT NULL,
    `last_name`       VARCHAR(100) DEFAULT NULL,
    `phone`           VARCHAR(50)  DEFAULT NULL,
    `country`         VARCHAR(100) DEFAULT NULL,
    `subscription_plan` VARCHAR(20) DEFAULT NULL,
    `account_type`    VARCHAR(20) DEFAULT NULL,
    `brand_name`      VARCHAR(255) DEFAULT NULL,
    `brand_description` TEXT DEFAULT NULL,
    `brand_logo_path`  VARCHAR(500) DEFAULT NULL,
    `annual_revenue`  DECIMAL(18,2) DEFAULT NULL,
    `market_role`     VARCHAR(20) DEFAULT NULL,
    `address`         TEXT         DEFAULT NULL,
    `role`            ENUM('admin','user') NOT NULL DEFAULT 'user',
    `status`          ENUM('active','inactive','pending') NOT NULL DEFAULT 'active',
    `last_login`      DATETIME DEFAULT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email   (`email`),
    INDEX idx_role    (`role`),
    INDEX idx_status  (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- جدول المعاملات
-- ============================================================
CREATE TABLE IF NOT EXISTS `dp_transactions` (
    `id`                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `reference`                   VARCHAR(100) NOT NULL UNIQUE,
    `gateway`                     VARCHAR(50) NOT NULL,
    `protocol`                    VARCHAR(50) DEFAULT '101.1',
    `amount`                      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `amount_usdt`                 DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `currency`                    VARCHAR(10) NOT NULL DEFAULT 'AED',
    `card_type`                   VARCHAR(50) DEFAULT NULL,
    `card_last4`                  VARCHAR(16) DEFAULT NULL,
    `customer_name`               VARCHAR(255) DEFAULT NULL,
    `customer_email`              VARCHAR(255) DEFAULT NULL,
    `customer_phone`              VARCHAR(50) DEFAULT NULL,
    `status`                      VARCHAR(30) NOT NULL DEFAULT 'pending',
    `approval_status`             VARCHAR(20) NOT NULL DEFAULT 'pending',
    `payment_method`              VARCHAR(50) DEFAULT 'card',
    `transaction_type`            TEXT DEFAULT NULL,
    `description`                 TEXT DEFAULT NULL,
    `accept_terms`                TINYINT(1) NOT NULL DEFAULT 0,
    `contract_service_name`       VARCHAR(255) DEFAULT NULL,
    `contract_service_description` TEXT DEFAULT NULL,
    `contract_delivery_method`    VARCHAR(255) DEFAULT NULL,
    `contract_delivery_notes`     TEXT DEFAULT NULL,
    `user_id`                     INT UNSIGNED DEFAULT 0,
    `fees`                        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `net_amount`                  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `gateway_response`            TEXT DEFAULT NULL,
    `transaction_data`            TEXT DEFAULT NULL,
    `error_message`               TEXT DEFAULT NULL,
    `created_at`                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                  DATETIME NULL DEFAULT NULL,
    INDEX idx_reference  (`reference`),
    INDEX idx_gateway    (`gateway`),
    INDEX idx_status     (`status`),
    INDEX idx_user_id    (`user_id`),
    INDEX idx_created_at (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- جدول الفواتير
-- ============================================================
CREATE TABLE IF NOT EXISTS `dp_invoices` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `invoice_number` VARCHAR(100) NOT NULL UNIQUE,
    `reference`      VARCHAR(100) NOT NULL,
    `user_id`        INT UNSIGNED DEFAULT 0,
    `amount`         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `currency`       VARCHAR(10) NOT NULL DEFAULT 'AED',
    `status`         VARCHAR(30) NOT NULL DEFAULT 'pending',
    `terms_text`     TEXT DEFAULT NULL,
    `gateway`        VARCHAR(50) NOT NULL DEFAULT 'integrated',
    `description`    TEXT DEFAULT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reference    (`reference`),
    INDEX idx_user_id      (`user_id`),
    INDEX idx_status       (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- جدول المحافظ
-- ============================================================
CREATE TABLE IF NOT EXISTS `dp_wallets` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT UNSIGNED NOT NULL,
    `currency`   VARCHAR(10) NOT NULL DEFAULT 'AED',
    `balance`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `status`     VARCHAR(30) NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_wallet` (`user_id`, `currency`),
    INDEX idx_user_id (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- جدول التحقق من الهوية (KYC)
-- ============================================================
CREATE TABLE IF NOT EXISTS `dp_kyc_verifications` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`        INT UNSIGNED NOT NULL,
    `provider`       VARCHAR(50)  NOT NULL DEFAULT 'manual' COMMENT 'sumsub | jumio | manual',
    `applicant_id`   VARCHAR(100) DEFAULT NULL COMMENT 'ID من مزود KYC',
    `level`          TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1=أساسي 2=متوسط 3=مؤسسي',
    `status`         VARCHAR(20)  NOT NULL DEFAULT 'pending'
                         COMMENT 'pending | approved | rejected | expired',
    `daily_limit`    DECIMAL(12,2) NOT NULL DEFAULT 5000.00,
    `monthly_limit`  DECIMAL(12,2) NOT NULL DEFAULT 50000.00,
    `country`        VARCHAR(100) DEFAULT NULL,
    `address`        TEXT         DEFAULT NULL,
    `phone`          VARCHAR(50)  DEFAULT NULL,
    `document_type`  VARCHAR(50)  DEFAULT NULL,
    `document_file`  VARCHAR(255) DEFAULT NULL,
    `selfie_file`    VARCHAR(255) DEFAULT NULL,
    `rejection_reason` TEXT        DEFAULT NULL,
    `verified_at`    DATETIME     DEFAULT NULL,
    `expires_at`     DATETIME     DEFAULT NULL,
    `updated_at`     DATETIME     DEFAULT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_user` (`user_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- جدول دفتر الأستاذ
-- ============================================================
CREATE TABLE IF NOT EXISTS `dp_ledger` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT UNSIGNED NOT NULL,
    `type`        VARCHAR(20) NOT NULL,
    `amount`      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `currency`    VARCHAR(10) NOT NULL DEFAULT 'AED',
    `reference`   VARCHAR(100) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id   (`user_id`),
    INDEX idx_reference (`reference`),
    INDEX idx_type      (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- جدول طلبات الموافقة
-- ============================================================
CREATE TABLE IF NOT EXISTS `dp_approval_requests` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT UNSIGNED NOT NULL,
    `type`       VARCHAR(50) NOT NULL DEFAULT 'payment',
    `reference`  VARCHAR(100) NOT NULL,
    `amount`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `currency`   VARCHAR(10) NOT NULL DEFAULT 'AED',
    `status`     VARCHAR(30) NOT NULL DEFAULT 'pending',
    `reason`     TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id   (`user_id`),
    INDEX idx_reference (`reference`),
    INDEX idx_status    (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- بطاقات الدفع الآمنة: لا تخزن PAN أو CVV
-- ============================================================
CREATE TABLE IF NOT EXISTS `dp_payment_cards` (
    `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`             INT UNSIGNED NOT NULL,
    `provider`            VARCHAR(50) NOT NULL,
    `provider_token`      VARCHAR(255) NOT NULL,
    `card_brand`          VARCHAR(30) DEFAULT NULL,
    `card_last4`          VARCHAR(4) NOT NULL,
    `cardholder_name`     VARCHAR(100) DEFAULT NULL,
    `expiry_month`        TINYINT UNSIGNED DEFAULT NULL,
    `expiry_year`         SMALLINT UNSIGNED DEFAULT NULL,
    `verification_status` VARCHAR(20) NOT NULL DEFAULT 'pending',
    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME DEFAULT NULL,
    UNIQUE KEY `uniq_provider_token` (`provider`,`provider_token`),
    INDEX `idx_card_user` (`user_id`),
    INDEX `idx_card_status` (`verification_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- خدمة العملاء والمستندات الخاصة
-- ============================================================
CREATE TABLE IF NOT EXISTS `dp_support_tickets` (
    `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT UNSIGNED DEFAULT NULL,
    `name`        VARCHAR(150) NOT NULL,
    `email`       VARCHAR(255) NOT NULL,
    `subject`     VARCHAR(255) NOT NULL,
    `message`     TEXT NOT NULL,
    `status`      VARCHAR(20) NOT NULL DEFAULT 'open',
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME DEFAULT NULL,
    INDEX `idx_support_user` (`user_id`),
    INDEX `idx_support_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `dp_support_documents` (
    `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ticket_id`   BIGINT UNSIGNED NOT NULL,
    `user_id`     INT UNSIGNED DEFAULT NULL,
    `original_name` VARCHAR(255) NOT NULL,
    `stored_path`  VARCHAR(500) NOT NULL,
    `mime_type`    VARCHAR(100) NOT NULL,
    `file_size`    INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_document_ticket` (`ticket_id`),
    INDEX `idx_document_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- جدول العقود
-- ============================================================
CREATE TABLE IF NOT EXISTS `dp_contracts` (
    `id`                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `reference`            VARCHAR(100) NOT NULL,
    `service_name`         VARCHAR(255) DEFAULT NULL,
    `service_description`  TEXT DEFAULT NULL,
    `delivery_method`      VARCHAR(255) DEFAULT NULL,
    `delivery_notes`       TEXT DEFAULT NULL,
    `terms_text`           TEXT DEFAULT NULL,
    `accept_terms`         TINYINT(1) NOT NULL DEFAULT 0,
    `user_id`              INT UNSIGNED DEFAULT 0,
    `created_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_reference` (`reference`),
    INDEX idx_user_id (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- جدول بوابات الدفع
-- ============================================================
CREATE TABLE IF NOT EXISTS `dp_payment_gateways` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code`        VARCHAR(80) NOT NULL UNIQUE,
    `name`        VARCHAR(150) NOT NULL,
    `type`        VARCHAR(50) NOT NULL DEFAULT 'electronic',
    `status`      VARCHAR(20) NOT NULL DEFAULT 'inactive',
    `config`      TEXT DEFAULT NULL,
    `credentials` TEXT DEFAULT NULL,
    `settings`    TEXT DEFAULT NULL,
    `setup_complete` TINYINT(1) NOT NULL DEFAULT 0,
    `last_tested` DATETIME DEFAULT NULL,
    `test_response_ms` INT DEFAULT NULL,
    `test_message` TEXT DEFAULT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_code   (`code`),
    INDEX idx_status (`status`),
    INDEX idx_type   (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- جدول روابط الدفع
-- ============================================================
CREATE TABLE IF NOT EXISTS `dp_payment_links` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `link_id`      VARCHAR(100) NOT NULL UNIQUE,
    `token`        VARCHAR(255) NOT NULL,
    `slug`         VARCHAR(255) NOT NULL,
    `title`        VARCHAR(255) NOT NULL,
    `description`  TEXT DEFAULT NULL,
    `amount`       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `currency`     VARCHAR(10) NOT NULL DEFAULT 'AED',
    `gateway`      VARCHAR(50) NOT NULL DEFAULT 'integrated',
    `protocol`     VARCHAR(50) DEFAULT '101.1',
    `payment_type` VARCHAR(50) DEFAULT 'one_time',
    `customer_name`  VARCHAR(255) DEFAULT NULL,
    `customer_email` VARCHAR(255) DEFAULT NULL,
    `customer_phone` VARCHAR(50) DEFAULT NULL,
    `redirect_url`   TEXT DEFAULT NULL,
    `expiry_date`    DATETIME DEFAULT NULL,
    `max_uses`       INT DEFAULT 0,
    `uses_count`     INT DEFAULT 0,
    `status`         VARCHAR(30) NOT NULL DEFAULT 'active',
    `user_id`        INT UNSIGNED DEFAULT 0,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_link_id  (`link_id`),
    INDEX idx_token    (`token`),
    INDEX idx_slug     (`slug`),
    INDEX idx_user_id  (`user_id`),
    INDEX idx_status   (`status`),
    INDEX idx_expiry   (`expiry_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- جدول بوابات البنوك
-- ============================================================
CREATE TABLE IF NOT EXISTS `dp_bank_gateways` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `bank_code`  VARCHAR(80) NOT NULL UNIQUE,
    `bank_name`  VARCHAR(150) NOT NULL,
    `country`    VARCHAR(5) NOT NULL DEFAULT '',
    `region`     VARCHAR(50) NOT NULL DEFAULT '',
    `status`     VARCHAR(20) NOT NULL DEFAULT 'active',
    `notes`      TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_bank_code (`bank_code`),
    INDEX idx_region    (`region`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- جدول حسابات البنوك
-- ============================================================
CREATE TABLE IF NOT EXISTS `dp_bank_accounts` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `gateway_id` INT UNSIGNED NOT NULL,
    `label`      VARCHAR(120) NOT NULL,
    `fields`     TEXT NOT NULL,
    `status`     VARCHAR(20) NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_gateway_id (`gateway_id`),
    FOREIGN KEY (`gateway_id`) REFERENCES `dp_bank_gateways`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- جدول الإعدادات
-- ============================================================
CREATE TABLE IF NOT EXISTS `dp_settings` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key`        VARCHAR(100) NOT NULL UNIQUE,
    `value`      TEXT DEFAULT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- جدول الشروط والأحكام
-- ============================================================
CREATE TABLE IF NOT EXISTS `dp_site_terms` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `terms_text` TEXT DEFAULT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- جدول الإشعارات
-- ============================================================
CREATE TABLE IF NOT EXISTS `dp_notifications` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT UNSIGNED NOT NULL,
    `title`      VARCHAR(255) NOT NULL,
    `message`    TEXT DEFAULT NULL,
    `read`       TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (`user_id`),
    INDEX idx_read    (`read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- جدول رسائل ISO 8583
-- ============================================================
CREATE TABLE IF NOT EXISTS `dp_iso8583_messages` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `transaction_id` INT UNSIGNED DEFAULT NULL,
    `reference`      VARCHAR(50) NOT NULL,
    `mti`            VARCHAR(4) NOT NULL,
    `mti_name`       VARCHAR(100) DEFAULT NULL,
    `direction`      ENUM('REQUEST','RESPONSE') NOT NULL DEFAULT 'REQUEST',
    `fields_json`    MEDIUMTEXT NOT NULL,
    `raw_message`    TEXT DEFAULT NULL,
    `response_code`  VARCHAR(4) DEFAULT NULL,
    `response_desc`  VARCHAR(100) DEFAULT NULL,
    `stan`           VARCHAR(6) DEFAULT NULL,
    `rrn`            VARCHAR(12) DEFAULT NULL,
    `auth_code`      VARCHAR(6) DEFAULT NULL,
    `pan_masked`     VARCHAR(25) DEFAULT NULL,
    `amount`         DECIMAL(15,2) DEFAULT NULL,
    `currency`       VARCHAR(3) DEFAULT NULL,
    `gateway`        VARCHAR(50) DEFAULT NULL,
    `processing_ms`  INT DEFAULT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ref    (`reference`),
    INDEX idx_mti    (`mti`),
    INDEX idx_resp   (`response_code`),
    INDEX idx_txn    (`transaction_id`),
    INDEX idx_date   (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- جدول عملاء API
-- ============================================================
CREATE TABLE IF NOT EXISTS `dp_api_clients` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`        VARCHAR(255) NOT NULL,
    `api_key`     VARCHAR(100) NOT NULL UNIQUE,
    `api_secret`  VARCHAR(255) NOT NULL,
    `webhook_url` VARCHAR(255) DEFAULT NULL,
    `status`      VARCHAR(20) NOT NULL DEFAULT 'active',
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_api_key (`api_key`),
    INDEX idx_status  (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- إنشاء مستخدم Admin افتراضي
-- كلمة المرور الافتراضية: Admin@2026
-- ============================================================
INSERT IGNORE INTO `dp_users`
    (`username`, `email`, `password_hash`, `first_name`, `last_name`, `role`, `status`, `created_at`)
VALUES (
    'admin',
    'admin@diparma.local',
    '$2y$12$M1VUsf8jYQ4JxC5Ec0k5G.0xqG6jHKD2A6B1VY4b6.9QJbJf6qV7m',
    'Admin',
    'User',
    'admin',
    'active',
    NOW()
);

-- ============================================================
-- إعدادات افتراضية
-- ============================================================
INSERT IGNORE INTO `dp_settings` (`key`, `value`) VALUES
    ('site_name', 'DI PARMA Gateway'),
    ('timezone', 'Asia/Dubai'),
    ('default_currency', 'AED'),
    ('session_timeout', '3600');
