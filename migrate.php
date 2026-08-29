<?php
/**
 * DI PARMA | Database Migration Script
 *
 * This helper ensures the current database schema contains the
 * expected transaction columns for the application.
 *
 * Usage:
 *   - Run from browser as an authenticated admin
 *   - Or run from CLI: php migrate.php
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';

$cliMode = (PHP_SAPI === 'cli');
$results = [];

if (!$cliMode) {
    require_once __DIR__ . '/includes/auth_check.php';
    requireAdmin();
}

$db = db();

function ensureTableExists(Database $db, string $table, string $createSql): string {
    $exists = false;
    try {
        $row = $db->query(
            "SELECT 1 AS cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1",
            [DB_NAME, DB_PREFIX . $table]
        );
        $exists = !empty($row[0]['cnt']);
    } catch (Exception $e) {
        return "failed to verify table {$table}: " . $e->getMessage();
    }

    if ($exists) {
        return "ok: table {$table} already exists";
    }

    try {
        $db->execute($createSql);
        return "ok: table {$table} created";
    } catch (Exception $e) {
        return "error: could not create table {$table} - " . $e->getMessage();
    }
}

function ensureColumnExists(Database $db, string $table, string $column, string $definition): string {
    $exists = false;
    try {
        $row = $db->query(
            "SELECT 1 AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1",
            [DB_NAME, DB_PREFIX . $table, $column]
        );
        $exists = !empty($row[0]['cnt']);
    } catch (Exception $e) {
        return "failed to verify column {$column} on {$table}: " . $e->getMessage();
    }

    if ($exists) {
        return "ok: {$column} already exists on {$table}";
    }

    try {
        $db->execute("ALTER TABLE " . DB_PREFIX . "{$table} ADD COLUMN {$column} {$definition}");
        return "ok: {$column} created on {$table}";
    } catch (Exception $e) {
        return "error: could not add {$column} to {$table} - " . $e->getMessage();
    }
}

$results[] = ensureTableExists($db, 'kyc_verifications', "CREATE TABLE IF NOT EXISTS " . DB_PREFIX . "kyc_verifications (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `provider` VARCHAR(50) NOT NULL DEFAULT 'manual',
    `applicant_id` VARCHAR(100) DEFAULT NULL,
    `level` TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
    `daily_limit` DECIMAL(12,2) NOT NULL DEFAULT 5000.00,
    `monthly_limit` DECIMAL(12,2) NOT NULL DEFAULT 50000.00,
    `country` VARCHAR(100) DEFAULT NULL,
    `address` TEXT DEFAULT NULL,
    `phone` VARCHAR(50) DEFAULT NULL,
    `document_type` VARCHAR(50) DEFAULT NULL,
    `document_file` VARCHAR(255) DEFAULT NULL,
    `selfie_file` VARCHAR(255) DEFAULT NULL,
    `rejection_reason` TEXT DEFAULT NULL,
    `verified_at` DATETIME DEFAULT NULL,
    `expires_at` DATETIME DEFAULT NULL,
    `updated_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_user` (`user_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

$results[] = ensureColumnExists($db, 'transactions', 'amount_usdt', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
$results[] = ensureColumnExists($db, 'transactions', 'card_type', 'VARCHAR(50) DEFAULT NULL');
$results[] = ensureColumnExists($db, 'transactions', 'card_last4', 'VARCHAR(16) DEFAULT NULL');
$results[] = ensureColumnExists($db, 'transactions', 'updated_at', 'DATETIME NULL DEFAULT NULL');
$results[] = ensureColumnExists($db, 'users', 'phone', 'VARCHAR(50) DEFAULT NULL');
$results[] = ensureColumnExists($db, 'users', 'country', 'VARCHAR(100) DEFAULT NULL');
$results[] = ensureColumnExists($db, 'users', 'address', 'TEXT DEFAULT NULL');
$results[] = ensureColumnExists($db, 'kyc_verifications', 'country', 'VARCHAR(100) DEFAULT NULL');
$results[] = ensureColumnExists($db, 'kyc_verifications', 'address', 'TEXT DEFAULT NULL');
$results[] = ensureColumnExists($db, 'kyc_verifications', 'phone', 'VARCHAR(50) DEFAULT NULL');
$results[] = ensureColumnExists($db, 'kyc_verifications', 'document_type', 'VARCHAR(50) DEFAULT NULL');
$results[] = ensureColumnExists($db, 'kyc_verifications', 'document_file', 'VARCHAR(255) DEFAULT NULL');
$results[] = ensureColumnExists($db, 'kyc_verifications', 'selfie_file', 'VARCHAR(255) DEFAULT NULL');
$results[] = ensureColumnExists($db, 'kyc_verifications', 'updated_at', 'DATETIME DEFAULT NULL');

if ($cliMode) {
    foreach ($results as $line) {
        echo $line . PHP_EOL;
    }
    exit(0);
}

?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DI PARMA | ترحيل قاعدة البيانات</title>
    <style>
        body { font-family: 'Cairo', sans-serif; background:#0b0d17; color:#f5f5f5; padding:24px; }
        .box { background:#111827; border:1px solid #444; border-radius:16px; padding:24px; max-width:820px; margin:auto; }
        h1 { margin-bottom:16px; color:#ffd700; }
        ul { list-style:none; padding:0; }
        li { margin-bottom:12px; padding:12px 16px; border-radius:12px; background:#151b2b; border:1px solid #2d3a54; }
        .ok { color:#9eea9e; }
        .error { color:#f78c8c; }
        .note { margin-top:18px; color:#a5b4fc; font-size:.95rem; }
    </style>
</head>
<body>
    <div class="box">
        <h1>ترحيل قاعدة البيانات</h1>
        <p>يتم تنفيذ التحقق من الجدول <strong>transactions</strong> وإضافة العمود <strong>amount_usdt</strong> إذا كان مفقوداً.</p>
        <ul>
            <?php foreach ($results as $line):
                $class = str_starts_with($line, 'ok:') ? 'ok' : 'error';
            ?>
                <li class="<?= $class ?>"><?= htmlspecialchars($line) ?></li>
            <?php endforeach; ?>
        </ul>
        <p class="note">إذا كنت ترغب في تشغيل هذا من سطر الأوامر، استخدم: <code>php migrate.php</code></p>
    </div>
</body>
</html>
