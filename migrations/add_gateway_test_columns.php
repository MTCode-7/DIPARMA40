<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

$database = db();
$columns = [];
foreach ($database->query('SHOW COLUMNS FROM dp_payment_gateways') as $column) {
    $columns[$column['Field']] = true;
}

$definitions = [
    'last_tested' => 'DATETIME DEFAULT NULL',
    'test_response_ms' => 'INT DEFAULT NULL',
    'test_message' => 'TEXT DEFAULT NULL',
];

foreach ($definitions as $name => $definition) {
    if (!isset($columns[$name])) {
        $database->query("ALTER TABLE dp_payment_gateways ADD COLUMN `$name` $definition");
    }
}

echo "gateway test columns ready\n";
