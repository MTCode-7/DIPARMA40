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
require_once __DIR__ . '/../lib/MySystem/ChargeHub.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized', 'canonical' => '/api/pos_transaction.php']);
    exit;
}

$gateway = strtolower(trim((string) ($payload['gateway'] ?? $payload['card_provider'] ?? '')));
if (str_starts_with($action, 'init_')) {
    $gateway = $gateway !== '' ? $gateway : substr($action, 5);
}
$txnType = strtolower(trim((string) ($payload['txn_type'] ?? 'purchase_2d')));

if ($gateway === '' || !DiParmaChargeHub::canCharge($gateway)) {
    http_response_code(410);
    echo json_encode([
        'success' => false,
        'deprecated' => true,
        'message' => 'api/direct_payment.php is retired. Use /api/pos_transaction.php',
        'canonical' => '/api/pos_transaction.php',
        'gateway' => $gateway,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!function_exists('verifyCsrfToken') || !verifyCsrfToken((string) ($payload['csrf_token'] ?? ''))) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF']);
    exit;
}

$payload['user_id'] = (int) $_SESSION['user_id'];
$payload['channel'] = $payload['channel'] ?? 'direct_payment_compat';
$result = DiParmaChargeHub::charge($gateway, $txnType, $payload);
$result['deprecated'] = true;
$result['canonical'] = '/api/pos_transaction.php';
echo json_encode($result, JSON_UNESCAPED_UNICODE);
