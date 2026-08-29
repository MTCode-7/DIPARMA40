<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Creating missing tables...\n\n";

try {
    $dsn = 'mysql:host=localhost;port=3306;dbname=diparma_gateway;charset=utf8mb4';
    $pdo = new PDO($dsn, 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "✓ Connected to database\n\n";
    
    // Create dp_integrations table
    echo "1. Creating dp_integrations table...\n";
    $sql1 = "
    CREATE TABLE IF NOT EXISTS `dp_integrations` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `category` VARCHAR(50) NOT NULL,
        `subtype` VARCHAR(100) DEFAULT NULL,
        `code` VARCHAR(50) NOT NULL UNIQUE,
        `name` VARCHAR(255) NOT NULL,
        `metadata` JSON DEFAULT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NULL DEFAULT NULL,
        INDEX idx_category (`category`),
        INDEX idx_code (`code`),
        INDEX idx_active (`is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ";
    
    $pdo->exec($sql1);
    echo "   ✓ dp_integrations table created\n\n";
    
    // Create dp_bulk_batches table
    echo "2. Creating dp_bulk_batches table...\n";
    $sql2 = "
    CREATE TABLE IF NOT EXISTS `dp_bulk_batches` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `batch_number` VARCHAR(100) NOT NULL UNIQUE,
        `user_id` INT UNSIGNED DEFAULT NULL,
        `total_transactions` INT UNSIGNED DEFAULT 0,
        `successful_transactions` INT UNSIGNED DEFAULT 0,
        `failed_transactions` INT UNSIGNED DEFAULT 0,
        `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `currency` VARCHAR(10) NOT NULL DEFAULT 'AED',
        `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
        `file_path` VARCHAR(500) DEFAULT NULL,
        `gateway` VARCHAR(50) DEFAULT NULL,
        `notes` TEXT DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NULL DEFAULT NULL,
        `completed_at` DATETIME NULL DEFAULT NULL,
        INDEX idx_batch_number (`batch_number`),
        INDEX idx_user_id (`user_id`),
        INDEX idx_status (`status`),
        INDEX idx_gateway (`gateway`),
        INDEX idx_created_at (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ";
    
    $pdo->exec($sql2);
    echo "   ✓ dp_bulk_batches table created\n\n";
    
    // Create dp_bulk_transactions table (related to bulk batches)
    echo "3. Creating dp_bulk_transactions table...\n";
    $sql3 = "
    CREATE TABLE IF NOT EXISTS `dp_bulk_transactions` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `batch_id` INT UNSIGNED NOT NULL,
        `transaction_reference` VARCHAR(100) DEFAULT NULL,
        `recipient` VARCHAR(255) NOT NULL,
        `amount` DECIMAL(12,2) NOT NULL,
        `currency` VARCHAR(10) NOT NULL DEFAULT 'AED',
        `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
        `error_message` TEXT DEFAULT NULL,
        `processed_at` DATETIME NULL DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_batch_id (`batch_id`),
        INDEX idx_reference (`transaction_reference`),
        INDEX idx_status (`status`),
        FOREIGN KEY (`batch_id`) REFERENCES `dp_bulk_batches`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ";
    
    $pdo->exec($sql3);
    echo "   ✓ dp_bulk_transactions table created\n\n";
    
    // Verify creation
    echo "4. Verifying tables...\n";
    $result = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'diparma_gateway' AND table_name IN ('dp_integrations', 'dp_bulk_batches', 'dp_bulk_transactions') ORDER BY table_name");
    $tables = $result->fetchAll(PDO::FETCH_COLUMN);
    
    foreach (['dp_integrations', 'dp_bulk_batches', 'dp_bulk_transactions'] as $table) {
        $exists = in_array($table, $tables) ? '✓' : '✗';
        echo "   [$exists] $table\n";
    }
    
    echo "\n✓ All missing tables created successfully!\n";
    
} catch (Exception $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
