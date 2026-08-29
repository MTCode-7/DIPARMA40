<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Starting Database Setup (V3 - Direct PDO)...\n";

try {
    // Connect without specifying database first
    echo "1. Connecting to MySQL server...\n";
    
    $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', 'localhost', 3306);
    $pdo = new PDO($dsn, 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "   ✓ Connected to MySQL\n";
    
    // Create database
    echo "\n2. Creating database if not exists...\n";
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `diparma_gateway` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    echo "   ✓ Database ready\n";
    
    // Select database
    echo "\n3. Selecting database...\n";
    $pdo->exec("USE `diparma_gateway`");
    echo "   ✓ Database selected\n";
    
    // Read and execute SQL file
    echo "\n4. Reading setup file...\n";
    $sqlFile = __DIR__ . '/setup_database.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("SQL file not found: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    if (!$sql) {
        throw new Exception("Failed to read SQL file");
    }
    
    echo "   ✓ File loaded (" . strlen($sql) . " bytes)\n";
    
    // Execute all statements
    echo "\n5. Executing SQL statements...\n";
    
    // Remove everything before USE statement to avoid database switching issues
    $sqlLines = explode("\n", $sql);
    $useFound = false;
    $statements = [];
    $current = '';
    
    foreach ($sqlLines as $line) {
        // Skip lines before USE statement
        if (!$useFound && stripos(trim($line), 'USE ') === 0) {
            $useFound = true;
            continue;
        }
        if (!$useFound) continue;
        
        $line = rtrim($line);
        
        // Skip comments and empty lines
        if (empty($line) || strpos(trim($line), '--') === 0) {
            continue;
        }
        
        $current .= $line . " ";
        
        // Execute on semicolon
        if (substr(trim($line), -1) === ';') {
            $stmt = trim($current);
            if (!empty($stmt)) {
                $statements[] = $stmt;
            }
            $current = '';
        }
    }
    
    $executed = 0;
    $skipped = 0;
    
    foreach ($statements as $idx => $stmt) {
        $stmt = trim($stmt);
        if (empty($stmt)) continue;
        
        try {
            $pdo->exec($stmt);
            $executed++;
            $preview = substr(str_replace(["\n", "\r", "\t"], " ", $stmt), 0, 70);
            echo "   [$executed] ✓ " . $preview . "...\n";
        } catch (PDOException $e) {
            $errorMsg = $e->getMessage();
            // Check if it's a harmless "table already exists" error
            if (strpos($errorMsg, 'already exists') !== false) {
                $skipped++;
                echo "   [$executed] ⊘ (already exists) " . substr($stmt, 0, 50) . "...\n";
            } else {
                echo "   ✗ Error: " . $errorMsg . "\n";
                echo "      Statement: " . substr($stmt, 0, 100) . "...\n";
            }
        }
    }
    
    echo "\n";
    echo "✓ Database setup completed!\n";
    echo "  Statements parsed: " . count($statements) . "\n";
    echo "  Executed: $executed\n";
    echo "  Skipped: $skipped\n";
    
    // Verify
    echo "\n6. Verifying tables...\n";
    $result = $pdo->query("SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = 'diparma_gateway'");
    $row = $result->fetch();
    echo "   Total tables: " . $row['cnt'] . "\n";
    
    // List critical tables
    $criticalTables = ['dp_users', 'dp_transactions', 'dp_integrations', 'dp_bulk_batches'];
    $result = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'diparma_gateway' ORDER BY table_name");
    $tables = $result->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($criticalTables as $table) {
        $exists = in_array($table, $tables) ? '✓' : '✗';
        echo "   [$exists] $table\n";
    }
    
} catch (Exception $e) {
    echo "\n✗ Fatal Error:\n";
    echo "  Message: " . $e->getMessage() . "\n";
    echo "  Code: " . $e->getCode() . "\n";
    if ($e instanceof PDOException) {
        echo "  PDO Error: " . $e->getMessage() . "\n";
    }
    exit(1);
}

echo "\n✓ All done!\n";
?>
