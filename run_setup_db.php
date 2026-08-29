<?php
/**
 * Run Database Setup Script
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';

try {
    $db = db();
    $sqlFile = __DIR__ . '/setup_database.sql';
    
    if (!file_exists($sqlFile)) {
        die("Database setup file not found: $sqlFile\n");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Split by semicolon and execute each statement
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        fn($s) => !empty($s) && strpos($s, '--') !== 0
    );
    
    foreach ($statements as $statement) {
        if (!empty($statement)) {
            try {
                $db->exec($statement);
                echo "✓ Executed: " . substr($statement, 0, 60) . "...\n";
            } catch (Exception $e) {
                echo "⚠ Warning: " . $e->getMessage() . "\n";
            }
        }
    }
    
    echo "\n✓ Database setup completed successfully!\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
