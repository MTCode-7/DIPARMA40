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
 *   "card_number":  "4111111111111111",
 *   "card_name":    "JOHN DOE",
 *   "card_expiry":  "12/26",
 *   "card_cvv":     "123",
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
require_once __DIR__ . '/../../lib/NuveiAdapter.php';

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
$ledgerAddr  = trim($data['ledger_address'] ?? $client['ledger_address'] ?? 'TEwLFWlwK55b7PuFfzgH1H2f3xs3pLgLn2');
$reference   = trim($data['reference'] ?? '') ?: ('API-' . strtoupper(substr(uniqid(), 0, 8)));
$metadata    = $data['metadata'] ?? [];

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

// ── Process via Nuvei → Mashreq ─────────────────────────────
$nuvei  = new NuveiAdapter();
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
];

try {
    $result = match($txnType) {
        'purchase'  => $nuvei->purchase($params),
        'auth'      => $nuvei->authorize($params),
        'capture'   => $nuvei->capture($params),
        'refund'    => $nuvei->refund($params),
        'void'      => $nuvei->void($params),
        default     => $nuvei->purchase($params),
    };
} catch (Exception $e) {
    $resp = json_encode(['success'=>false,'error'=>'gateway_error','message'=>$e->getMessage()]);
    http_response_code(502);
    ApiAuth::log($client['id'],$client['api_key'],'/api/v1/charge','POST',$rawBody,502,$resp,$reference);
    echo $resp;
    exit;
}

$success = $result['success'] ?? false;

// ── حفظ في DB ────────────────────────────────────────────────
$db = db();
try {
    $db->insert('transactions', [
        'reference'       => $reference,
        'gateway'         => 'nuvei_api',
        'amount'          => $amount,
        'currency'        => $currency,
        'status'          => $success ? 'completed' : 'failed',
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
} catch (Exception $e) {
    error_log('[API/charge] DB: ' . $e->getMessage());
}

// ── تحويل للـ Ledger بعد النجاح ─────────────────────────────
$ledgerTxid   = null;
$ledgerStatus = 'pending';

if ($success && !empty($ledgerAddr)) {
    // إضافة لـ queue التحويل
    try {
        $db->insert('ledger_transfer_queue', [
            'reference'      => $reference,
            'ledger_address' => $ledgerAddr,
            'usdt_amount'    => $amount,
            'currency_orig'  => $currency,
            'status'         => 'queued',
            'txid'           => null,
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
        $ledgerStatus = 'queued';
    } catch (Exception $e) {
        $ledgerStatus = 'not_queued';
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
    'duration_ms'    => $durationMs,
    'timestamp'      => date('c'),
];

http_response_code($httpCode);
$respBody = json_encode($response);
ApiAuth::log($client['id'], $client['api_key'], '/api/v1/charge', 'POST',
    $rawBody, $httpCode, $respBody, $reference, $durationMs);
echo $respBody;
