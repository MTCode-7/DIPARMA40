<?php
/**
 * DI PARMA | POS retry: Nuvei approved sale → net USDT → Ledger
 * Does not invent a transfer. Re-runs LedgerSettlementService for an existing Nuvei txn.
 */
require_once dirname(__DIR__) . '/bootstrap.php';
pos_require_operator();
require_once POS_APP_ROOT . '/lib/LedgerSettlementService.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

if (empty($data['csrf_token']) || !verifyCsrfToken($data['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF']);
    exit;
}

$reference = trim((string)($data['reference'] ?? ''));
if ($reference === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Reference is required']);
    exit;
}

$db = db();
$rows = $db->query(
    'SELECT * FROM ' . DB_PREFIX . 'transactions WHERE reference = ? LIMIT 1',
    [$reference]
);
$txn = $rows[0] ?? null;
if (!$txn) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Transaction not found: ' . $reference]);
    exit;
}

$gw = strtolower((string)($txn['gateway'] ?? ''));
if ($gw !== 'nuvei') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'This endpoint is Nuvei-only. Other gateways keep their own path.']);
    exit;
}

$status = strtolower((string)($txn['status'] ?? ''));
if (!in_array($status, ['completed', 'captured', 'approved', 'settled'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Nuvei sale is not approved yet. Ledger settle is blocked.']);
    exit;
}

$txnType = strtolower((string)($txn['transaction_type'] ?? ''));
$skipTypes = ['auth', 'auth_hold', 'hold', 'refund', 'avoid', 'void', 'reversal', 'balance', 'settlement'];
if (in_array($txnType, $skipTypes, true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'This operation type does not settle to Ledger']);
    exit;
}

$ledgerAddr = trim((string)(defined('LEDGER_TRC20_ADDRESS') ? LEDGER_TRC20_ADDRESS : ''));
if (!preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $ledgerAddr)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'LEDGER_TRC20_ADDRESS is required']);
    exit;
}

$result = LedgerSettlementService::getInstance()->settleToLedger([
    'reference'      => $reference,
    'amount'         => (float)($txn['amount'] ?? 0),
    'currency'       => strtoupper((string)($txn['currency'] ?? 'USD')),
    'gateway'        => 'nuvei',
    'ledger_address' => $ledgerAddr,
    'user_id'        => (int)($txn['user_id'] ?? ($_SESSION['user_id'] ?? 0)),
    'txn_type'       => $txnType,
    'transaction_id' => (int)($txn['id'] ?? 0) ?: null,
    'destination'    => 'ledger',
]);

$ok = !empty($result['success']) && empty($result['skipped']);
echo json_encode([
    'success'          => $ok,
    'queued'           => !empty($result['queued']),
    'txid'             => $result['txid'] ?? null,
    'usdt_amount'      => $result['usdt_amount'] ?? null,
    'ledger_addr'      => $ledgerAddr,
    'reference'        => $reference,
    'settlement_path'  => 'nuvei_to_ledger',
    'message'          => $result['message'] ?? ($ok ? 'Settled to Ledger' : 'Ledger settle failed'),
    'timestamp'        => date('c'),
], JSON_UNESCAPED_UNICODE);
