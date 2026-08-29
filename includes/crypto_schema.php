<?php
/**
 * ============================================================
 * DI PARMA | Crypto Schema — إنشاء جداول البنية التحتية للأصول الرقمية
 * ============================================================
 * يُستدعى مرة واحدة لإنشاء الجداول إن لم تكن موجودة
 * ============================================================
 */

function dp_create_crypto_tables(): void {
    $db = db();

    // ── [1] محافظ المستخدمين ────────────────────────────────
    $db->execute("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "user_wallets` (
        `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `user_id`         INT UNSIGNED NOT NULL,
        `network`         VARCHAR(20)  NOT NULL COMMENT 'TRC20 | ERC20 | BEP20 | BTC',
        `coin`            VARCHAR(20)  NOT NULL DEFAULT 'USDT',
        `address`         VARCHAR(100) NOT NULL,
        `derivation_path` VARCHAR(100) DEFAULT NULL,
        `encrypted_key`   TEXT         DEFAULT NULL COMMENT 'مشفّر بـ AES-256',
        `status`          VARCHAR(20)  NOT NULL DEFAULT 'active',
        `created_at`      DATETIME     NOT NULL,
        UNIQUE KEY `uniq_user_network_coin` (`user_id`, `network`, `coin`),
        INDEX `idx_address` (`address`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── [2] أرصدة الـ Treasury (Hot/Cold) ───────────────────
    $db->execute("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "treasury_balances` (
        `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `coin`          VARCHAR(20)  NOT NULL COMMENT 'USDT | BTC | ETH',
        `network`       VARCHAR(20)  NOT NULL COMMENT 'TRC20 | ERC20 | BEP20',
        `hot_balance`   DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
        `cold_balance`  DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
        `reserved`      DECIMAL(20,8) NOT NULL DEFAULT 0.00000000 COMMENT 'محجوز لعمليات جارية',
        `min_hot`       DECIMAL(20,8) NOT NULL DEFAULT 1000.00000000 COMMENT 'الحد الأدنى للتنبيه',
        `updated_at`    DATETIME     NOT NULL,
        UNIQUE KEY `uniq_coin_network` (`coin`, `network`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── [3] أسعار الصرف ─────────────────────────────────────
    $db->execute("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "fx_rates` (
        `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `base_currency`  VARCHAR(20)  NOT NULL COMMENT 'USDT | BTC | ETH',
        `quote_currency` VARCHAR(10)  NOT NULL COMMENT 'AED | USD | SAR',
        `rate`           DECIMAL(20,8) NOT NULL,
        `bid`            DECIMAL(20,8) DEFAULT NULL,
        `ask`            DECIMAL(20,8) DEFAULT NULL,
        `source`         VARCHAR(50)  NOT NULL COMMENT 'coingecko | binance | aggregate',
        `margin_pct`     DECIMAL(8,4) NOT NULL DEFAULT 1.5000 COMMENT 'هامش المنصة %',
        `final_rate`     DECIMAL(20,8) NOT NULL COMMENT 'السعر بعد الهامش',
        `fetched_at`     DATETIME     NOT NULL,
        INDEX `idx_pair` (`base_currency`, `quote_currency`),
        INDEX `idx_fetched` (`fetched_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── [4] معاملات البلوكشين ────────────────────────────────
    $db->execute("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "blockchain_txns` (
        `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `reference`      VARCHAR(100) DEFAULT NULL COMMENT 'مرجع داخلي dp_transactions',
        `network`        VARCHAR(20)  NOT NULL COMMENT 'TRC20 | ERC20 | BEP20',
        `coin`           VARCHAR(20)  NOT NULL DEFAULT 'USDT',
        `tx_hash`        VARCHAR(100) DEFAULT NULL COMMENT 'Hash على البلوكشين',
        `from_address`   VARCHAR(100) NOT NULL,
        `to_address`     VARCHAR(100) NOT NULL,
        `amount`         DECIMAL(20,8) NOT NULL,
        `fee`            DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
        `confirmations`  INT UNSIGNED NOT NULL DEFAULT 0,
        `required_conf`  INT UNSIGNED NOT NULL DEFAULT 20 COMMENT 'تأكيدات مطلوبة',
        `direction`      VARCHAR(10)  NOT NULL DEFAULT 'out' COMMENT 'in | out',
        `status`         VARCHAR(20)  NOT NULL DEFAULT 'pending'
                         COMMENT 'pending | broadcasting | confirmed | failed',
        `raw_response`   MEDIUMTEXT   DEFAULT NULL,
        `created_at`     DATETIME     NOT NULL,
        `confirmed_at`   DATETIME     DEFAULT NULL,
        INDEX `idx_reference`  (`reference`),
        INDEX `idx_tx_hash`    (`tx_hash`),
        INDEX `idx_to_address` (`to_address`),
        INDEX `idx_status`     (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── [5] KYC ──────────────────────────────────────────────
    $db->execute("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "kyc_verifications` (
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
        `created_at`     DATETIME     NOT NULL,
        UNIQUE KEY `uniq_user` (`user_id`),
        INDEX `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── [6] سجل أحداث النظام (Event Bus بسيط) ───────────────
    $db->execute("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "event_log` (
        `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `event_type`  VARCHAR(100) NOT NULL COMMENT 'payment.approved | crypto.sent | ...',
        `reference`   VARCHAR(100) DEFAULT NULL,
        `user_id`     INT UNSIGNED DEFAULT NULL,
        `payload`     MEDIUMTEXT   DEFAULT NULL COMMENT 'JSON',
        `processed`   TINYINT(1)   NOT NULL DEFAULT 0,
        `created_at`  DATETIME     NOT NULL,
        INDEX `idx_event_type`  (`event_type`),
        INDEX `idx_reference`   (`reference`),
        INDEX `idx_processed`   (`processed`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── [7] بيانات Treasury الافتراضية إن لم تكن موجودة ─────
    $existing = $db->find('treasury_balances', ['coin' => 'USDT', 'network' => 'TRC20']);
    if (!$existing) {
        $now = date('Y-m-d H:i:s');
        foreach ([
            ['USDT', 'TRC20', 1000.0],
            ['USDT', 'ERC20', 500.0],
            ['USDT', 'BEP20', 500.0],
        ] as [$coin, $network, $minHot]) {
            $db->insert('treasury_balances', [
                'coin'       => $coin,
                'network'    => $network,
                'hot_balance'  => 0,
                'cold_balance' => 0,
                'reserved'     => 0,
                'min_hot'      => $minHot,
                'updated_at'   => $now,
            ]);
        }
    }
}
