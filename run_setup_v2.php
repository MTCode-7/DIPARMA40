<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Starting Database Setup (V2)...\n";

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';

echo "✓ Config loaded\n";

try {
    echo "✓ Connecting to database...\n";
    $pdo = db();
    
    echo "✓ Reading SQL file...\n";
    $sql = file_get_contents(__DIR__ . '/setup_database.sql');
    
    if (!$sql) {
        throw new Exception("Failed to read SQL file");
    }
    
    echo "✓ SQL file loaded\n";
    echo "✓ Parsing and executing statements...\n";
    
    // Split by semicolon
    $statements = array_filter(
        array_map(function($s) {
            return trim($s);
        }, explode(';', $sql)),
        function($s) {
            return !empty($s) && substr(trim($s), 0, 2) !== '--';
        }
    );
    
    $count = 0;
    $errors = 0;
    
    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if (empty($stmt)) continue;
        
        try {
            $pdo->exec($stmt);
            $count++;
            $preview = substr($stmt, 0, 60);
            echo "  [$count] ✓ " . str_replace(["\n", "\r"], " ", $preview) . "...\n";
        } catch (PDOException $e) {
            $errors++;
            echo "  ⚠ Error: " . $e->getMessage() . "\n";
            // Don't stop on errors, continue with next statement
        }
    }
    
    echo "\n";
    echo "✓ Database setup completed!\n";
    echo "  Total statements: " . count($statements) . "\n";
    echo "  Executed: $count\n";
    echo "  Errors: $errors\n";
    
    // Verify table creation
    echo "\nVerifying tables:\n";
    $result = $pdo->query("SELECT COUNT(*) as table_count FROM information_schema.tables WHERE table_schema = '" . DB_NAME . "'");
    $row = $result->fetch();
    echo "  Tables created: " . $row['table_count'] . "\n";
    
} catch (Exception $e) {
    echo "\n✗ Fatal Error:\n";
    echo "  " . $e->getMessage() . "\n";
    echo "  File: " . $e->getFile() . "\n";
    echo "  Line: " . $e->getLine() . "\n";
    exit(1);
}
?>
