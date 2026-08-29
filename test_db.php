<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';

try {
    $db = db();
    $users = $db->query("SELECT COUNT(*) as cnt FROM dp_users");
    echo json_encode([
        'status' => 'success',
        'message' => 'Database connected',
        'user_count' => $users[0]['cnt'] ?? 0,
        'db_host' => DB_HOST,
        'db_name' => DB_NAME
    ]);
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'db_host' => DB_HOST,
        'db_name' => DB_NAME
    ]);
}
?>
