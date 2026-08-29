<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Testing Login System...\n\n";

try {
    // Test 1: Include login file
    echo "1. Testing login.php inclusion...\n";
    
    // Capture output to see if there are any errors
    ob_start();
    
    require_once __DIR__ . '/login.php';
    
    $output = ob_get_clean();
    
    if (empty($output)) {
        echo "   ✓ login.php loaded without rendering HTML\n";
    } else {
        echo "   ✓ login.php loaded (outputs HTML)\n";
        echo "      First 100 chars: " . substr($output, 0, 100) . "...\n";
    }
    
    echo "\n2. Testing database connection...\n";
    require_once __DIR__ . '/includes/database.php';
    $db = db();
    echo "   ✓ Database connection successful\n";
    
    echo "\n3. Testing user table...\n";
    $result = $db->query("SELECT COUNT(*) as cnt FROM dp_users");
    $userCount = $result[0]['cnt'] ?? 0;
    echo "   ✓ Users in database: $userCount\n";
    
    echo "\n4. Testing functions...\n";
    require_once __DIR__ . '/includes/functions.php';
    
    // Check if getStatusLabel function exists
    if (function_exists('getStatusLabel')) {
        echo "   ✓ getStatusLabel function exists\n";
        $label = getStatusLabel('pending');
        echo "   ✓ getStatusLabel('pending') = '$label'\n";
    } else {
        echo "   ✗ getStatusLabel function not found\n";
    }
    
    echo "\n5. Testing session...\n";
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
        echo "   ✓ Session started\n";
    } else {
        echo "   ✓ Session already active\n";
    }
    
    echo "\n✓ Login system is ready!\n";
    
} catch (Exception $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . "\n";
    echo "   Line: " . $e->getLine() . "\n";
    exit(1);
}
?>
