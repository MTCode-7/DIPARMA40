<?php
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/bootstrap.php';
pos_require_operator();
require_once POS_APP_ROOT . '/lib/Adapters/PayPalAdapter.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
$data = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($data) || empty($data['csrf_token']) || !verifyCsrfToken((string) $data['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF']);
    exit;
}
$reference = trim((string) ($data['reference'] ?? ''));
$authorizationId = trim((string) ($data['authorization_id'] ?? $data['payment_id'] ?? ''));
$amount = (float) ($data['amount'] ?? 0);
$currency = strtoupper(trim((string) ($data['currency'] ?? 'USD')));
if ($reference === '' || $authorizationId === '' || $amount <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Reference, authorization ID, and amount are required']);
    exit;
}
$db = db();
$rows = $db->query(
    'SELECT * FROM ' . DB_PREFIX . 'transactions WHERE user_id = ? AND reference = ? AND gateway = ? AND transaction_type = ? LIMIT 1',
    [(int) ($_SESSION['user_id'] ?? 0), $reference, 'paypal', 'auth']
);
$txn = $rows[0] ?? null;
if (!$txn) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'PayPal AUTH hold not found']);
    exit;
}
$createdTs = strtotime((string) ($txn['created_at'] ?? ''));
$ageDays = $createdTs !== false ? max(0, (int) floor((time() - $createdTs) / 86400)) : 999;
if ($ageDays < 3 || $ageDays >= 29) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $ageDays < 3 ? 'Reauthorize starts on day 4' : 'PayPal authorization is outside the 29-day period']);
    exit;
}
$result = (new PayPalAdapter())->reauthorize($authorizationId, $amount, $currency);
if (!empty($result['success'])) {
    $blob = json_decode((string) ($txn['gateway_response'] ?? ''), true) ?: [];
    $blob['paypal_reauthorization'] = $result;
    $blob['authorization_id'] = (string) ($result['authorization_id'] ?? $authorizationId);
    $db->update('transactions', [
        'auth_code' => (string) ($result['auth_code'] ?? $txn['auth_code'] ?? ''),
        'gateway_response' => json_encode($blob, JSON_UNESCAPED_UNICODE),
        'updated_at' => date('Y-m-d H:i:s'),
    ], ['id' => (int) $txn['id']]);
}
echo json_encode($result, JSON_UNESCAPED_UNICODE);
