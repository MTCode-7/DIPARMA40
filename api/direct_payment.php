<?php
/**
 * Compat shim — legacy Direct Payment API.
 * Canonical charge path: /api/pos_transaction.php (ChargeHub / POS pipe).
 */
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}
$action = strtolower(trim((string) ($_GET['action'] ?? $payload['action'] ?? '')));

if ($action === 'gateways' || $action === 'public_keys') {
    echo json_encode([
        'success' => true,
        'deprecated' => true,
        'message' => 'Use POS / checkout pipe',
        'canonical' => '/api/pos_transaction.php',
        'diagram' => '/api/mysystem.php?action=diagram',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(410);
echo json_encode([
    'success' => false,
    'deprecated' => true,
    'message' => 'api/direct_payment.php is retired. Use /api/pos_transaction.php',
    'canonical' => '/api/pos_transaction.php',
], JSON_UNESCAPED_UNICODE);
exit;
