<?php
/**
 * ============================================================
 * DI PARMA | POST /api/v1/charge
 * ============================================================
 * سحب من البطاقة البنكية → Nuvei → المبلغ يصل Ledger TRX
 * ============================================================
 * Headers مطلوبة:
 *   X-Api-Key:    dpk_xxxx
 *   X-Timestamp:  unix timestamp
 *   X-Signature:  HMAC-SHA256(secret, "api_key:timestamp:sha256(body)")
 *   Content-Type: application/json
 *
 * Request Body:
 * {
 *   "amount":       100.00,
 *   "currency":     "USD",
 *   "card_number":  "REAL_PAN",
 *   "card_name":    "CARDHOLDER",
 *   "card_expiry":  "MM/YY",
 *   "card_cvv":     "CVV",
 *   "txn_type":     "purchase|auth|refund|void",
 *   "sec_mode":     "3D|2D",
 *   "ledger_address": "TEwLFW...",   // اختياري — يستخدم الـ default
 *   "reference":    "ORDER-001",      // اختياري
 *   "metadata":     {}                // اختياري
 * }
 * ============================================================
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/ApiAuth.php';
require_once __DIR__ . '/../../lib/MySystem/ChargeHub.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

$startTime = microtime(true);
$rawBody   = file_get_contents('php://input');

// ── فقط POST ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'error'=>'method_not_allowed','message'=>'Use POST']);
    exit;
}

// ── Auth ─────────────────────────────────────────────────────
$client = ApiAuth::verify();

// ── Parse Body ───────────────────────────────────────────────
$data = json_decode($rawBody, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'invalid_json','message'=>'Invalid JSON body']);
    exit;
}

// ── Validation ───────────────────────────────────────────────
$amount      = floatval($data['amount']      ?? 0);
$currency    = strtoupper(trim($data['currency']   ?? 'USD'));
$cardNumber  = preg_replace('/\D/', '', $data['card_number'] ?? '');
$cardName    = trim($data['card_name']   ?? '');
$cardExpiry  = trim($data['card_expiry'] ?? '');
$cardCVV     = trim($data['card_cvv']    ?? '');
$txnType     = strtolower(trim($data['txn_type']  ?? 'purchase'));
$secMode     = strtoupper(trim($data['sec_mode']  ?? '3D'));
$ledgerAddr  = trim($data['ledger_address'] ?? $client['ledger_address'] ?? (defined('LEDGER_TRC20_ADDRESS') ? LEDGER_TRC20_ADDRESS : ''));
$reference   = trim($data['reference'] ?? '') ?: ('API-' . strtoupper(substr(uniqid(), 0, 8)));
$metadata    = $data['metadata'] ?? [];
$gateway     = strtolower(trim((string) ($data['gateway'] ?? $data['card_provider'] ?? 'nuvei')));

$errors = [];
if ($amount <= 0)          $errors[] = 'amount must be > 0';
if (strlen($cardNumber) < 13) $errors[] = 'invalid card_number';
if (empty($cardName))      $errors[] = 'card_name required';
if (!preg_match('/^\d{2}\/\d{2,4}$/', $cardExpiry)) $errors[] = 'card_expiry must be MM/YY';
if (strlen($cardCVV) < 3)  $errors[] = 'invalid card_cvv';
if (!in_array($currency, ['USD','AED','EUR','GBP','SAR','KWD','QAR','EGP']))
    $errors[] = 'unsupported currency';
if (!in_array($txnType, ['purchase','auth','refund','void','capture']))
    $errors[] = 'invalid txn_type';

if (!empty($errors)) {
    http_response_code(422);
    $resp = json_encode(['success'=>false,'error'=>'validation_error','errors'=>$errors]);
    ApiAuth::log($client['id'],$client['api_key'],'/api/v1/charge','POST',$rawBody,422,$resp,$reference);
    echo $resp;
    exit;
}

// ── Process via ChargeHub (POS pipe or adapter) ─────────────
$params = [
    'amount'       => $amount,
    'currency'     => $currency,
    'card_number'  => $cardNumber,
    'card_name'    => $cardName,
    'card_expiry'  => $cardExpiry,
    'card_cvv'     => $cardCVV,
    'email'        => $data['email'] ?? ('api_' . $client['mid'] . '@diparmas.com'),
    'country'      => 'AE',
    'processing_mode' => $secMode,
    'reference'    => $reference,
    'user_token_id'=> 'api_client_' . $client['id'],
    'pos_device'   => 'API_' . strtoupper($client['mid']),
    'related_transaction_id' => $data['orig_reference'] ?? '',
    'orig_ref' => $data['orig_reference'] ?? '',
    'channel' => 'api_v1',
    'destination' => 'ledger',
    'ledger_address' => $ledgerAddr,
    'user_id' => (int) ($client['user_id'] ?? 0),
];

try {
    $result = DiParmaChargeHub::charge($gateway, $txnType, $params);
} catch (Exception $e) {
    $resp = json_encode(['success'=>false,'error'=>'gateway_error','message'=>$e->getMessage()]);
    http_response_code(502);
    ApiAuth::log($client['id'],$client['api_key'],'/api/v1/charge','POST',$rawBody,502,$resp,$reference);
    echo $resp;
    exit;
}

$success = !empty($result['success']) && empty($result['requires_3ds']) && empty($result['redirect_url']) && empty($result['checkout_url']);
$pending3ds = !empty($result['requires_3ds']) || !empty($result['redirect_url']) || !empty($result['checkout_url']);

// ── حفظ في DB — الناجح أو انتظار 3DS فقط ─────────────────────
$db = db();
try {
    if (diparma_should_persist_charge($success, $pending3ds)) {
    $db->insert('transactions', [
        'reference'       => $reference,
        'gateway'         => $gateway,
        'amount'          => $amount,
        'currency'        => $currency,
        'status'          => $success ? 'completed' : 'pending',
        'protocol'        => $txnType,
        'customer_name'   => $cardName,
        'gateway_response'=> json_encode([
            'nuvei_txn_id'  => $result['nuvei_txn_id']  ?? null,
            'approval_code' => $result['approval_code'] ?? null,
            'rrn'           => $result['rrn']           ?? null,
            'api_client'    => $client['mid'],
            'ledger_target' => $ledgerAddr,
            'metadata'      => $metadata,
        ]),
        'created_at'      => date('Y-m-d H:i:s'),
    ]);

    // تحديث إحصائيات العميل
    if ($success) {
        $db->execute(
            "UPDATE dp_api_clients SET total_charged=total_charged+?, total_txns=total_txns+1 WHERE id=?",
            [$amount, $client['id']]
        );
    }
    }
} catch (Exception $e) {
    error_log('[API/charge] DB: ' . $e->getMessage());
}

// ── تسوية فورية للصافي → Ledger (خصم نسبة البوابة فقط) ──
$ledgerTxid   = null;
$ledgerStatus = 'pending';
$ledgerSettle = null;

if ($success && $txnType !== 'auth' && $txnType !== 'void' && $txnType !== 'refund') {
    try {
        require_once __DIR__ . '/../../lib/LedgerSettlementService.php';
        $ledgerSettle = LedgerSettlementService::getInstance()->settleToLedger([
            'reference'      => $reference,
            'amount'         => $amount,
            'currency'       => $currency,
            'gateway'        => $gateway,
            'ledger_address' => $ledgerAddr,
            'user_id'        => (int)($client['user_id'] ?? 0),
            'txn_type'       => $txnType === 'purchase' ? 'purchase_2d' : $txnType,
            'destination'    => 'ledger',
        ]);
        $ledgerTxid = $ledgerSettle['txid'] ?? null;
        if (!empty($ledgerSettle['success']) && empty($ledgerSettle['skipped'])) {
            $ledgerStatus = 'completed';
        } elseif (!empty($ledgerSettle['queued'])) {
            $ledgerStatus = 'queued';
        } elseif (!empty($ledgerSettle['skipped'])) {
            $ledgerStatus = 'skipped';
        } else {
            $ledgerStatus = 'failed';
        }
    } catch (Throwable $e) {
        $ledgerStatus = 'failed';
        error_log('[API/charge] Ledger: ' . $e->getMessage());
    }
}

// ── إرسال Webhook ────────────────────────────────────────────
if (!empty($client['webhook_url'])) {
    $webhookEvent = $success ? 'charge.completed' : 'charge.failed';
    $webhookPayload = [
        'event'      => $webhookEvent,
        'reference'  => $reference,
        'amount'     => $amount,
        'currency'   => $currency,
        'txn_type'   => $txnType,
        'status'     => $success ? 'completed' : 'failed',
        'approval_code' => $result['approval_code'] ?? null,
        'rrn'        => $result['rrn'] ?? null,
        'ledger_status' => $ledgerStatus,
        'timestamp'  => time(),
    ];

    try {
        $db->insert('api_webhooks', [
            'client_id' => $client['id'],
            'event'     => $webhookEvent,
            'payload'   => json_encode($webhookPayload),
            'status'    => 'pending',
            'created_at'=> date('Y-m-d H:i:s'),
        ]);
    } catch (Exception $e) {}

    // إرسال فوري (async)
    $webhookSig = hash_hmac('sha256', json_encode($webhookPayload), $client['webhook_secret'] ?? '');
    @(function() use ($client, $webhookPayload, $webhookSig) {
        $ch = curl_init($client['webhook_url']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($webhookPayload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-DiParma-Signature: ' . $webhookSig,
                'X-DiParma-Event: ' . $webhookPayload['event'],
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
        ]);
        curl_exec($ch);
        curl_close($ch);
    })();
}

// ── Response ─────────────────────────────────────────────────
$durationMs = (int)((microtime(true) - $startTime) * 1000);
$httpCode   = $success ? 200 : 402;

$response = [
    'success'        => $success,
    'reference'      => $reference,
    'txn_type'       => $txnType,
    'amount'         => $amount,
    'currency'       => $currency,
    'status'         => $success ? 'completed' : 'failed',
    'approval_code'  => $result['approval_code'] ?? null,
    'rrn'            => $result['rrn']           ?? null,
    'nuvei_txn_id'   => $result['nuvei_txn_id']  ?? null,
    'message'        => $result['message']        ?? ($success ? 'Approved' : 'Declined'),
    'ledger_address' => $ledgerAddr,
    'ledger_status'  => $ledgerStatus,
    'ledger_txid'    => $ledgerTxid,
    'gateway_fee'    => $ledgerSettle['fee'] ?? null,
    'net_amount'     => $ledgerSettle['net_fiat'] ?? null,
    'ledger_usdt'    => $ledgerSettle['usdt_amount'] ?? null,
    'duration_ms'    => $durationMs,
    'timestamp'      => date('c'),
];

http_response_code($httpCode);
$respBody = json_encode($response);
ApiAuth::log($client['id'], $client['api_key'], '/api/v1/charge', 'POST',
    $rawBody, $httpCode, $respBody, $reference, $durationMs);
echo $respBody;
