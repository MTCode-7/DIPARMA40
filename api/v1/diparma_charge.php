<?php
/**
 * Compat shim — legacy /api/v1/diparma_charge.php
 * Canonical: /api/v1/charge.php or /api/pos_transaction.php
 */
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Prefer the maintained public charge API when present
$charge = __DIR__ . '/charge.php';
if (is_file($charge) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require $charge;
    exit;
}

http_response_code(410);
echo json_encode([
    'success' => false,
    'deprecated' => true,
    'message' => 'api/v1/diparma_charge.php retired',
    'canonical' => [
        '/api/v1/charge.php',
        '/api/pos_transaction.php',
    ],
], JSON_UNESCAPED_UNICODE);
