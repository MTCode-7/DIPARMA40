<?php
/** DI PARMA webhook receiver. */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/functions.php';

$rawInput = file_get_contents('php://input') ?: '';
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid payload']);
    exit;
}

$secret = trim((string)(getenv('WEBHOOK_SECRET') ?: getenv('WEBHOOK_HMAC_SECRET') ?: ''));
$signature = trim((string)($_SERVER['HTTP_X_DI_PARMA_SIGNATURE'] ?? ''));
if ($secret === '' || $signature === '' || !hash_equals(hash_hmac('sha256', $rawInput, $secret), $signature)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid signature']);
    exit;
}

$reference = trim((string)($data['reference'] ?? ''));
$event = trim((string)($data['event'] ?? ''));
if ($reference === '' || $event === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'reference and event are required']);
    exit;
}

$statusMap = [
    'charge.completed' => 'completed',
    'charge.captured' => 'completed',
    'charge.failed' => 'failed',
    'charge.refunded' => 'refunded',
    'charge.hold' => 'processing',
];
try {
    $db = db();
    if (isset($statusMap[$event])) {
        $db->update('transactions', [
            'status' => $statusMap[$event],
            'error_message' => $statusMap[$event] === 'failed' ? (string)($data['message'] ?? 'Payment failed') : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['reference' => $reference]);
    }
    if ($event === 'ledger.sent' || $event === 'ledger.failed') {
        $db->update('transactions', [
            'ledger_txid' => $data['txid'] ?? null,
            'ledger_transferred' => $event === 'ledger.sent' ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['reference' => $reference]);
    }
    echo json_encode(['success' => true, 'message' => 'Webhook processed successfully', 'timestamp' => date('c')]);
} catch (Throwable $e) {
    error_log('[DI PARMA Webhook] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Webhook processing failed']);
}
