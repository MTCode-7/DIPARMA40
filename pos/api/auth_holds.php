<?php
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/bootstrap.php';
pos_restore_operator();

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'holds' => []]);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$holds = [];
try {
    $pfx = defined('DB_PREFIX') ? DB_PREFIX : 'dp_';
    $rows = db()->query(
        "SELECT id, reference, amount, currency, rrn, auth_code, bank_approval_code, gateway, card_last4, status, gateway_response, created_at
         FROM {$pfx}transactions
         WHERE user_id = ? AND transaction_type = 'auth'
           AND LOWER(COALESCE(status,'')) IN ('completed','authorized','hold','held','pending','success')
         ORDER BY id DESC LIMIT 40",
        [$userId]
    ) ?: [];
    foreach ($rows as $row) {
        $raw = [];
        if (!empty($row['gateway_response'])) {
            $decoded = json_decode((string) $row['gateway_response'], true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        $paymentId = (string) (
            $raw['nuvei_txn_id']
            ?? $raw['transaction_id']
            ?? $raw['payment_id']
            ?? ($raw['raw']['transactionId'] ?? '')
            ?? ($raw['gateway_details']['transaction_id'] ?? '')
        );
        $holds[] = [
            'id' => (int) $row['id'],
            'reference' => (string) $row['reference'],
            'amount' => (float) $row['amount'],
            'currency' => (string) ($row['currency'] ?: 'USD'),
            'rrn' => (string) ($row['rrn'] ?? ''),
            'bank_approval' => (string) ($row['bank_approval_code'] ?? $row['auth_code'] ?? ''),
            'gateway_approval' => (string) ($raw['approval_code'] ?? $row['auth_code'] ?? ''),
            'payment_id' => $paymentId,
            'gateway' => (string) ($row['gateway'] ?? ''),
            'card_last4' => (string) ($row['card_last4'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'holds' => [], 'message' => $e->getMessage()]);
    exit;
}

echo json_encode(['success' => true, 'holds' => $holds], JSON_UNESCAPED_UNICODE);
