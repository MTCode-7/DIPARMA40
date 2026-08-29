<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Starting Database Setup...\n";

// Load config
$rootPath = __DIR__;
require_once $rootPath . '/includes/config.php';
require_once $rootPath . '/includes/database.php';

echo "1. Config loaded\n";
echo "   DB_HOST: " . DB_HOST . "\n";
echo "   DB_NAME: " . DB_NAME . "\n";
echo "   DB_USER: " . DB_USER . "\n";

try {
    echo "\n2. Connecting to database...\n";
    $db = db();
    echo "   ✓ Connected successfully\n";
    
    echo "\n3. Reading SQL file...\n";
    $sqlFile = $rootPath . '/setup_database.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("SQL file not found: $sqlFile");
    }
    
    $sqlContent = file_get_contents($sqlFile);
    $lines = explode("\n", $sqlContent);
    $statement = '';
    $executed = 0;
    
    echo "   ✓ SQL file loaded (" . count($lines) . " lines)\n";
    
    echo "\n4. Executing SQL statements...\n";
    foreach ($lines as $line) {
        $line = trim($line);
        
        // Skip comments and empty lines
        if (empty($line) || strpos($line, '--') === 0) {
            continue;
        }
        
        $statement .= ' ' . $line;
        
        // Execute on semicolon
        if (substr($line, -1) === ';') {
            $statement = trim($statement);
            if (!empty($statement)) {
                try {
                    $db->exec($statement);
                    $executed++;
                    $preview = substr($statement, 0, 50);
                    echo "   ✓ [" . $executed . "] " . $preview . "...\n";
                } catch (Exception $e) {
                    echo "   ⚠ Error: " . $e->getMessage() . "\n";
                }
            }
            $statement = '';
        }
    }
    
    echo "\n✓ Database setup completed!\n";
    echo "   Executed: $executed statements\n";
    
} catch (Exception $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . "\n";
    echo "   Line: " . $e->getLine() . "\n";
    exit(1);
}
?>
