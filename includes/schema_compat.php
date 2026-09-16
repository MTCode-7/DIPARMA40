<?php
/**
 * Create missing tables/columns that older installs and wallet/POS code expect.
 * Safe to run repeatedly. Versioned so it does not ALTER on every request.
 */
if (!function_exists('dp_table')) {
    function dp_table(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9_]/', '', $name) ?? '';
        $pfx = defined('DB_PREFIX') ? (string) DB_PREFIX : 'dp_';
        if ($name === '') {
            throw new InvalidArgumentException('Invalid table name');
        }
        if ($pfx !== '' && str_starts_with($name, $pfx)) {
            return '`' . $name . '`';
        }
        return '`' . $pfx . $name . '`';
    }
}

function dp_ensure_schema_compat(Database $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    dp_seed_named_gateways($db);

    $p = defined('DB_PREFIX') ? (string) DB_PREFIX : 'dp_';
    $meta = $p . 'schema_compat';
    $version = '5';

    try {
        $db->execute(
            "CREATE TABLE IF NOT EXISTS `{$meta}` (
                `k` VARCHAR(64) NOT NULL PRIMARY KEY,
                `v` VARCHAR(64) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $row = $db->query("SELECT `v` FROM `{$meta}` WHERE `k` = 'version' LIMIT 1");
        if (($row[0]['v'] ?? '') === $version) {
            return;
        }
    } catch (Throwable $e) {
        // Continue and try to apply schema anyway.
    }

    $creates = [
        "CREATE TABLE IF NOT EXISTS `{$p}linked_wallets` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `provider` VARCHAR(32) NOT NULL,
            `network` VARCHAR(32) NOT NULL DEFAULT '',
            `address` VARCHAR(128) NOT NULL,
            `label` VARCHAR(120) DEFAULT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'active',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL DEFAULT NULL,
            UNIQUE KEY `uniq_user_provider` (`user_id`, `provider`),
            KEY `idx_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `{$p}user_crypto_wallets` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `coin` VARCHAR(16) NOT NULL,
            `network` VARCHAR(20) NOT NULL,
            `balance` DECIMAL(20,8) NOT NULL DEFAULT 0,
            `locked` DECIMAL(20,8) NOT NULL DEFAULT 0,
            `unlock_at` DATETIME NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'active',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_user_coin_network` (`user_id`, `coin`, `network`),
            KEY `idx_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `{$p}user_fiat_wallets` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `currency` VARCHAR(10) NOT NULL,
            `balance` DECIMAL(20,8) NOT NULL DEFAULT 0,
            `status` VARCHAR(20) NOT NULL DEFAULT 'active',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_user_currency` (`user_id`, `currency`),
            KEY `idx_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `{$p}company_wallets` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `wallet_type` VARCHAR(20) NOT NULL,
            `currency` VARCHAR(16) NOT NULL,
            `network` VARCHAR(20) NULL,
            `balance` DECIMAL(20,8) NOT NULL DEFAULT 0,
            `total_received` DECIMAL(20,8) NOT NULL DEFAULT 0,
            `total_sent` DECIMAL(20,8) NOT NULL DEFAULT 0,
            `updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_type_cur` (`wallet_type`, `currency`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `{$p}wallet_transactions` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `reference` VARCHAR(80) NOT NULL,
            `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `type` VARCHAR(32) NOT NULL,
            `wallet_type` VARCHAR(20) NOT NULL DEFAULT '',
            `coin` VARCHAR(16) NULL,
            `network` VARCHAR(20) NULL,
            `currency` VARCHAR(16) NULL,
            `amount` DECIMAL(20,8) NOT NULL DEFAULT 0,
            `fee` DECIMAL(20,8) NOT NULL DEFAULT 0,
            `net_amount` DECIMAL(20,8) NOT NULL DEFAULT 0,
            `rate` DECIMAL(20,8) NULL,
            `from_wallet` VARCHAR(32) NULL,
            `to_wallet` VARCHAR(32) NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `gateway` VARCHAR(50) NULL,
            `gateway_ref` VARCHAR(100) NULL,
            `tx_hash` VARCHAR(128) NULL,
            `to_address` VARCHAR(128) NULL,
            `note` TEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL,
            UNIQUE KEY `uniq_reference` (`reference`),
            KEY `idx_user` (`user_id`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `{$p}ledger_transfer_queue` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `reference` VARCHAR(100) NOT NULL,
            `ledger_address` VARCHAR(128) NOT NULL,
            `usdt_amount` DECIMAL(18,6) NOT NULL DEFAULT 0,
            `currency_orig` VARCHAR(16) DEFAULT NULL,
            `transaction_type` VARCHAR(50) NULL,
            `status` VARCHAR(32) NOT NULL DEFAULT 'queued',
            `message` TEXT NULL,
            `error_msg` TEXT NULL,
            `txid` VARCHAR(128) NULL,
            `attempts` INT NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL,
            KEY `idx_ref` (`reference`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `{$p}audit_logs` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NULL,
            `action` VARCHAR(100) NOT NULL,
            `resource` VARCHAR(100) NULL,
            `resource_id` VARCHAR(100) NULL,
            `details` JSON NULL,
            `ip_address` VARCHAR(45) NULL,
            `user_agent` TEXT NULL,
            `status` VARCHAR(20) DEFAULT 'success',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_user_id` (`user_id`),
            KEY `idx_action` (`action`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($creates as $sql) {
        try {
            $db->execute($sql);
        } catch (Throwable $e) {
            error_log('[schema_compat] create: ' . $e->getMessage());
        }
    }

    $txnCols = [
        'gateway_type' => 'VARCHAR(20) DEFAULT NULL',
        'transaction_label' => 'VARCHAR(100) DEFAULT NULL',
        'cardholder_name' => 'VARCHAR(100) DEFAULT NULL',
        'security_mode' => 'VARCHAR(20) DEFAULT NULL',
        'input_mode' => "VARCHAR(20) DEFAULT 'manual'",
        'orig_ref' => 'VARCHAR(100) DEFAULT NULL',
        'notes' => 'TEXT DEFAULT NULL',
        'ledger_txid' => 'VARCHAR(100) DEFAULT NULL',
        'ledger_transferred' => 'TINYINT(1) DEFAULT 0',
        'ledger_amount' => 'DECIMAL(15,6) DEFAULT NULL',
        'ledger_address' => 'VARCHAR(100) DEFAULT NULL',
        'auth_code' => 'VARCHAR(50) DEFAULT NULL',
        'rrn' => 'VARCHAR(50) DEFAULT NULL',
        'stan' => 'VARCHAR(50) DEFAULT NULL',
        'approval_code' => 'VARCHAR(50) DEFAULT NULL',
        'bank_approval_code' => 'VARCHAR(50) DEFAULT NULL',
        'error_code' => 'VARCHAR(50) DEFAULT NULL',
        'acquirer' => 'VARCHAR(100) DEFAULT NULL',
        'original_reference' => 'VARCHAR(50) DEFAULT NULL',
        'original_auth_code' => 'VARCHAR(50) DEFAULT NULL',
        'installment_count' => 'INT DEFAULT 0',
        'recurring_frequency' => 'VARCHAR(20) DEFAULT NULL',
        'moto_indicator' => 'VARCHAR(5) DEFAULT NULL',
        'is_advice' => 'TINYINT(1) DEFAULT 0',
        'is_offline' => 'TINYINT(1) DEFAULT 0',
        'updated_at' => 'DATETIME NULL DEFAULT NULL',
    ];
    dp_add_columns_if_missing($db, $p . 'transactions', $txnCols);
    dp_add_columns_if_missing($db, $p . 'user_wallets', [
        'updated_at' => 'DATETIME NULL DEFAULT NULL',
    ]);
    dp_add_columns_if_missing($db, $p . 'user_crypto_wallets', [
        'locked' => 'DECIMAL(20,8) NOT NULL DEFAULT 0',
        'unlock_at' => 'DATETIME NULL',
        'status' => "VARCHAR(20) NOT NULL DEFAULT 'active'",
        'updated_at' => 'DATETIME NULL',
    ]);
    dp_add_columns_if_missing($db, $p . 'wallet_transactions', [
        'currency' => 'VARCHAR(16) NULL',
        'gateway' => 'VARCHAR(50) NULL',
        'gateway_ref' => 'VARCHAR(100) NULL',
        'tx_hash' => 'VARCHAR(128) NULL',
        'to_address' => 'VARCHAR(128) NULL',
        'rate' => 'DECIMAL(20,8) NULL',
        'from_wallet' => 'VARCHAR(32) NULL',
        'to_wallet' => 'VARCHAR(32) NULL',
        'note' => 'TEXT NULL',
        'updated_at' => 'DATETIME NULL',
    ]);
    dp_add_columns_if_missing($db, $p . 'ledger_transfer_queue', [
        'transaction_type' => 'VARCHAR(50) NULL',
        'message' => 'TEXT NULL',
        'error_msg' => 'TEXT NULL',
        'attempts' => 'INT NOT NULL DEFAULT 0',
        'updated_at' => 'DATETIME NULL',
        'currency_orig' => 'VARCHAR(16) NULL',
    ]);
    dp_add_columns_if_missing($db, $p . 'company_wallets', [
        'total_sent' => 'DECIMAL(20,8) NOT NULL DEFAULT 0',
        'total_received' => 'DECIMAL(20,8) NOT NULL DEFAULT 0',
        'network' => 'VARCHAR(20) NULL',
        'updated_at' => 'DATETIME NULL',
    ]);

    try {
        $db->execute("REPLACE INTO `{$meta}` (`k`, `v`) VALUES ('version', ?)", [$version]);
    } catch (Throwable $e) {
        error_log('[schema_compat] version: ' . $e->getMessage());
    }
}

function dp_add_columns_if_missing(Database $db, string $table, array $columns): void
{
    $table = trim($table, '`');
    $existing = [];
    try {
        $rows = $db->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
        foreach ($rows as $row) {
            $field = strtolower((string) ($row['Field'] ?? ''));
            if ($field !== '') {
                $existing[$field] = true;
            }
        }
    } catch (Throwable $e) {
        return;
    }

    foreach ($columns as $name => $definition) {
        if (isset($existing[strtolower($name)])) {
            continue;
        }
        try {
            $db->execute(
                'ALTER TABLE `' . str_replace('`', '', $table) . '` ADD COLUMN `' . $name . '` ' . $definition
            );
        } catch (Throwable $e) {
            error_log('[schema_compat] alter ' . $table . '.' . $name . ': ' . $e->getMessage());
        }
    }
}

function dp_seed_named_gateways(Database $db): void
{
    try {
        $existing = $db->query(
            'SELECT id, type, gateway_type FROM ' . dp_table('payment_gateways') . ' WHERE code = ? LIMIT 1',
            ['diparma_gateway']
        );
        if (!empty($existing)) {
            $type = strtolower((string) ($existing[0]['type'] ?? ''));
            $id = (int) ($existing[0]['id'] ?? 0);
            if ($type === 'card' && $id > 0) {
                return;
            }
            if ($id > 0) {
                $db->update('payment_gateways', [
                    'name' => 'DIPARMA GATEWAY',
                    'type' => 'card',
                    'gateway_type' => 'card',
                    'config' => json_encode([
                        'region' => 'Global',
                        'icon' => 'fas fa-credit-card',
                        'setup_complete' => true,
                        'rail' => 'card',
                        'destination' => 'ledger',
                        'settlement_asset' => 'USDT',
                    ], JSON_UNESCAPED_UNICODE),
                ], ['id' => $id]);
            }
            return;
        }
        $db->insertAvailable('payment_gateways', [
            'code' => 'diparma_gateway',
            'name' => 'DIPARMA GATEWAY',
            'type' => 'card',
            'status' => 'active',
            'setup_complete' => 1,
            'gateway_type' => 'card',
            'connection_status' => 'untested',
            'config' => json_encode([
                'region' => 'Global',
                'icon' => 'fas fa-credit-card',
                'setup_complete' => true,
                'rail' => 'card',
                'destination' => 'ledger',
                'settlement_asset' => 'USDT',
            ], JSON_UNESCAPED_UNICODE),
            'credentials' => '{}',
            'settings' => '{}',
        ]);
    } catch (Throwable $e) {
        error_log('[schema_compat] seed diparma_gateway: ' . $e->getMessage());
    }
    dp_seed_square_gateway($db);
}

function dp_seed_square_gateway(Database $db): void
{
    try {
        $existing = $db->query(
            'SELECT id, status FROM ' . dp_table('payment_gateways') . ' WHERE code = ? LIMIT 1',
            ['square']
        );
        $creds = [
            'application_id' => getenv('SQUARE_APPLICATION_ID') ?: (getenv('SQUARE_API_KEY') ?: ''),
            'access_token' => getenv('SQUARE_ACCESS_TOKEN') ?: (getenv('SQUARE_SECRET_KEY') ?: ''),
            'location_id' => getenv('SQUARE_LOCATION_ID') ?: '',
            'environment' => getenv('SQUARE_ENVIRONMENT') ?: 'sandbox',
        ];
        $ready = $creds['application_id'] !== '' && $creds['access_token'] !== '' && $creds['location_id'] !== '';
        $payload = [
            'name' => 'Square',
            'type' => 'card',
            'gateway_type' => 'card',
            'status' => $ready ? 'active' : 'inactive',
            'setup_complete' => $ready ? 1 : 0,
            'connection_status' => $ready ? 'untested' : 'missing_credentials',
            'config' => json_encode([
                'region' => 'USA',
                'icon' => 'fas fa-square',
                'setup_complete' => $ready,
                'rail' => 'card',
                'destination' => 'ledger',
                'sdk' => 'web_payments',
            ], JSON_UNESCAPED_UNICODE),
            'credentials' => json_encode($creds, JSON_UNESCAPED_UNICODE),
        ];
        if (!empty($existing[0]['id'])) {
            $db->update('payment_gateways', $payload, ['id' => (int) $existing[0]['id']]);
            return;
        }
        $db->insertAvailable('payment_gateways', array_merge($payload, [
            'code' => 'square',
            'settings' => '{}',
        ]));
    } catch (Throwable $e) {
        error_log('[schema_compat] seed square: ' . $e->getMessage());
    }
}
