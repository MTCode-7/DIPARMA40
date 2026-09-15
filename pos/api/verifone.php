<?php
/**
 * DIPARMA host for Verifone VX 675.
 * Verix V + Nuvei Payment App + Nuvei key injection. No other gateway.
 *
 * GET  — commissioning status
 * POST — Nuvei Payment App result after on-device authorization
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Key, X-Timestamp, X-Signature, X-POS-Device');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once dirname(__DIR__) . '/bootstrap.php';
require_once POS_PATH . '/lib/verifone_vx675.php';

$commission = verifone_vx675_commission();
$status = [
    'success' => true,
    'device' => 'verifone_vx675',
    'os' => 'Verix V',
    'acquirer' => 'nuvei',
    'locked_gateway' => verifone_vx675_locked_gateway(),
    'payment_app' => 'Nuvei Payment App',
    'payment_app_installed' => !empty($commission['payment_app_installed']),
    'keys_injected' => !empty($commission['keys_injected']),
    'key_injection' => 'nuvei_rki',
    'ready' => !empty($commission['ready']),
    'host' => verifone_vx675_host_url(),
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    echo json_encode($status, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Use POST']);
    exit;
}

$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

$GLOBALS['POS_FORWARDED_BODY'] = verifone_vx675_to_pos($in);
require POS_PATH . '/api/transaction.php';
