<?php
/**
 * DI PARMA | PayRam Webhook Handler
 * POST https://diparmas.com/api/payram_webhook.php
 *
 * يستقبل أحداث PayRam:
 * ─ Payment: OPEN, PARTIALLY_FILLED, FILLED, OVER_FILLED, CANCELLED
 * ─ Payout:  payout.sent, payout.processed, payout.failed
 *
 * Verification: X-Payram-Signature: sha256=<hex>
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../lib/PayRamAdapter.php';

$rawBody = file_get_contents('php://input');
$sigHeader = '';
foreach (['HTTP_X_PAYRAM_SIGNATURE','HTTP_X_PAYRAM_WEBHOOK_SIGNATURE','HTTP_X_WEBHOOK_SIGNATURE','HTTP_X_SIGNATURE'] as $key) {
    if (!empty($_SERVER[$key])) {
        $sigHeader = $_SERVER[$key];
        break;
    }
}
$apiKeyH = $_SERVER['HTTP_API_KEY'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';

/* ── التحقق من التوقيع ── */
$payram = new PayRamAdapter();

if ($sigHeader) {
    if (!$payram->verifyWebhook($rawBody, $sigHeader)) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }
} elseif ($apiKeyH) {
    $expectedKeys = array_values(array_filter([
        defined('PAYRAM_WEBHOOK_SECRET') ? PAYRAM_WEBHOOK_SECRET : '',
        defined('PAYRAM_API_KEY') ? PAYRAM_API_KEY : '',
        getenv('PAYRAM_WEBHOOK_SECRET') ?: '',
        getenv('PAYRAM_API_KEY') ?: '',
    ], static fn($v) => $v !== ''));
    if (!in_array($apiKeyH, $expectedKeys, true)) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid API key']);
        exit;
    }
} else {
    http_response_code(401);
    echo json_encode(['error' => 'Webhook authentication required']);
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$db = db();

/*
 * ── تطبيع الحدث ──────────────────────────────────────────────────────────
 * Payram قد يرسل الأحداث بصيغتين:
 *   A) { "reference_id": "...", "status": "FILLED", ... }          ← payment event كـ root fields
 *   B) { "event_type": "filled", "reference_id": "...", ... }      ← event_type صريح
 *
 * نوحّد الصيغتين هنا قبل المعالجة.
 */
$eventType = strtolower(trim($payload['event_type'] ?? $payload['type'] ?? ''));

// إذا جاء event_type يتعلق بالدفع → نعيّن الـ status الصحيح في الـ payload
$paymentEventMap = [
    'payment'           => '',           // يعني "تحديث عام" — نترك status كما هو
    'filled'            => 'FILLED',
    'partially_filled'  => 'PARTIALLY_FILLED',
    'cancelled'         => 'CANCELLED',
    'failed'            => 'CANCELLED',
];

if ($eventType && array_key_exists($eventType, $paymentEventMap)) {
    // تأكد وجود reference_id في root
    if (empty($payload['reference_id']) && !empty($payload['referenceID'])) {
        $payload['reference_id'] = $payload['referenceID'];
    }
    // اكتب الـ status المقابل إذا لم يكن موجوداً
    if (!empty($paymentEventMap[$eventType]) && empty($payload['status'])) {
        $payload['status'] = $paymentEventMap[$eventType];
    }
}

/* ── Payment Webhook ── */
$paymentRef = $payload['reference_id'] ?? $payload['referenceID'] ?? null;
if ($paymentRef !== null) {
    $refId   = (string)$paymentRef;
    $status  = $payload['status'] ?? $payload['paymentState'] ?? '';
    $filled  = (float)($payload['filled_amount_in_usd'] ?? $payload['filledAmountInUSD'] ?? 0);
    $amount  = (float)($payload['amount'] ?? $payload['amountInUSD'] ?? 0);
    $currency= $payload['currency'] ?? 'USDT';
    $paymentInfo = $payload['payment_info'] ?? ($payload['paymentInfo'] ?? []);
    $firstPayment = is_array($paymentInfo) && !empty($paymentInfo) ? $paymentInfo[0] : [];
    $txHash  = $firstPayment['transaction_hash'] ?? $firstPayment['txHash'] ?? null;
    $srcAddr = $firstPayment['source_address'] ?? $firstPayment['sourceAddress'] ?? null;
    $dstAddr = $firstPayment['destination_address'] ?? $firstPayment['destinationAddress'] ?? null;
    $confCur = (int)($payload['confirmation_current'] ?? $payload['confirmations'] ?? 0);
    $confReq = (int)($payload['confirmation_required'] ?? $payload['confirmations_required'] ?? 0);

    $dbStatus = match($status) {
        'FILLED', 'OVER_FILLED' => 'completed',
        'PARTIALLY_FILLED'      => 'pending',
        'CANCELLED'             => 'failed',
        default                 => 'pending',
    };

    try {
        /* تحديث العملية في DB */
        $existing = $db->query(
            "SELECT id FROM dp_transactions WHERE JSON_EXTRACT(gateway_response,'$.payram_ref')=? OR reference=? LIMIT 1",
            [$refId, $refId]
        );

        if (!empty($existing[0])) {
            $db->execute(
                "UPDATE dp_transactions
                 SET status=?,
                     gateway_response=JSON_SET(COALESCE(gateway_response,'{}'),
                       '$.payram_status', ?,
                       '$.filled_usd', ?,
                       '$.tx_hash', ?,
                       '$.confirmations', ?,
                       '$.confirmations_required', ?,
                       '$.src_address', ?,
                       '$.dst_address', ?
                     ),
                     updated_at=NOW()
                 WHERE id=?",
                [
                    $dbStatus, $status, $filled, $txHash,
                    $confCur, $confReq, $srcAddr, $dstAddr,
                    $existing[0]['id'],
                ]
            );
        } else {
            /* سجل جديد */
            $db->execute(
                "INSERT INTO dp_transactions
                 (reference, gateway, amount, currency, status, transaction_type, gateway_response, created_at)
                 VALUES (?,?,?,?,?,'payram_crypto',?,NOW())",
                [
                    $refId, 'payram',
                    $amount ?: $filled, $currency,
                    $dbStatus,
                    json_encode([
                        'payram_ref'            => $refId,
                        'payram_status'         => $status,
                        'filled_usd'            => $filled,
                        'tx_hash'               => $txHash,
                        'confirmations'         => $confCur,
                        'confirmations_required'=> $confReq,
                        'src_address'           => $srcAddr,
                        'dst_address'           => $dstAddr,
                        'invoice_id'            => $payload['invoice_id'] ?? null,
                        'customer_id'           => $payload['customer_id'] ?? null,
                    ]),
                ]
            );
        }
    } catch (Exception $e) {
        error_log('[PayRam Webhook] Payment DB: ' . $e->getMessage());
    }

    /* إذا FILLED → تحويل واحد إلى Ledger عبر PayRam payout */
    if (in_array($status, ['FILLED', 'OVER_FILLED']) && $txHash) {
        error_log("[PayRam] Payment FILLED: ref={$refId} amount={$filled} USD tx={$txHash}");
        $ledgerAddress = defined('LEDGER_TRC20_ADDRESS')
            ? LEDGER_TRC20_ADDRESS
            : (getenv('LEDGER_TRC20_ADDRESS') ?: '');
        if (!preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $ledgerAddress)) {
            error_log('[PayRam] Ledger payout skipped: invalid LEDGER_TRC20_ADDRESS');
        } else {
            $lockName = 'payram-ledger-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $refId);
            $lock = $db->query('SELECT GET_LOCK(?, 10) AS acquired', [$lockName]);
            if (!empty($lock[0]['acquired'])) {
                try {
                    $row = $db->query(
                        "SELECT id, gateway_response FROM dp_transactions
                         WHERE JSON_EXTRACT(gateway_response,'$.payram_ref')=? OR reference=? LIMIT 1",
                        [$refId, $refId]
                    );
                    $meta = !empty($row[0]['gateway_response'])
                        ? json_decode($row[0]['gateway_response'], true) : [];
                    if (!empty($row[0]) && empty($meta['ledger_payout_id']) && empty($meta['ledger_payout_requested'])) {
                        $db->execute(
                            "UPDATE dp_transactions SET gateway_response=JSON_SET(COALESCE(gateway_response,'{}'), '$.ledger_payout_requested', true) WHERE id=?",
                            [$row[0]['id']]
                        );
                        $payoutAmount = $payram->convertUsdToCrypto($filled, 'TRX', 'USDT');
                        if ($payoutAmount !== null && $payoutAmount > 0) {
                            $payout = $payram->createPayout([
                                'email'           => 'ledger@diparmas.com',
                                'blockchain_code' => 'TRX',
                                'currency_code'   => 'USDT',
                                'amount'          => $payoutAmount,
                                'to_address'      => $ledgerAddress,
                                'customer_id'     => 'ledger_' . $refId,
                                'idempotency_key' => 'ledger-' . $refId,
                            ]);
                            if ($payout['success']) {
                                $db->execute(
                                    "UPDATE dp_transactions SET gateway_response=JSON_SET(COALESCE(gateway_response,'{}'), '$.ledger_payout_id', ?, '$.ledger_payout_status', ?, '$.ledger_address', ?) WHERE id=?",
                                    [$payout['payout_id'], $payout['status'], $ledgerAddress, $row[0]['id']]
                                );
                            } else {
                                $db->execute(
                                    "UPDATE dp_transactions SET gateway_response=JSON_SET(COALESCE(gateway_response,'{}'), '$.ledger_payout_requested', false, '$.ledger_payout_error', ?) WHERE id=?",
                                    [$payout['raw']['message'] ?? 'PayRam payout failed', $row[0]['id']]
                                );
                            }
                        } else {
                            error_log('[PayRam] Ledger payout skipped: unable to convert filled amount to USDT');
                        }
                    }
                } catch (Exception $e) {
                    error_log('[PayRam] Ledger payout failed: ' . $e->getMessage());
                } finally {
                    $db->query('SELECT RELEASE_LOCK(?)', [$lockName]);
                }
            }
        }
    }

    echo json_encode(['received' => true, 'status' => $dbStatus]);
    exit;
}

/* ── Payout Webhook ── */
if (isset($payload['event_type']) && (
    str_starts_with($payload['event_type'], 'payout') ||
    $payload['event_type'] === 'payout'
)) {
    $payoutId  = $payload['payout_id']    ?? null;
    $eventType = $payload['event_type'];
    $status    = $payload['status']       ?? '';
    $txHash    = $payload['tx_hash']      ?? null;
    $amount    = $payload['amount']       ?? null;
    $network   = $payload['network']      ?? null;
    $address   = $payload['address']      ?? null;

    try {
        $db->execute(
            "INSERT INTO dp_transactions
             (reference, gateway, amount, currency, status, transaction_type, gateway_response, created_at)
             VALUES (?,?,?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE status=VALUES(status), gateway_response=VALUES(gateway_response), updated_at=NOW()",
            [
                'PAYOUT-' . $payoutId, 'payram_payout',
                (float)$amount, $network ?: 'USDT',
                strtolower($status), 'payram_payout',
                json_encode([
                    'event_type'  => $eventType,
                    'payout_id'   => $payoutId,
                    'status'      => $status,
                    'tx_hash'     => $txHash,
                    'amount'      => $amount,
                    'network'     => $network,
                    'to_address'  => $address,
                    'from_address'=> $payload['from_address'] ?? null,
                    'failure'     => $payload['failure_reason'] ?? null,
                    'timestamp'   => $payload['timestamp'] ?? null,
                ]),
            ]
        );
    } catch (Exception $e) {
        error_log('[PayRam Webhook] Payout DB: ' . $e->getMessage());
    }

    echo json_encode(['received' => true, 'event' => $eventType]);
    exit;
}

/* Unknown */
echo json_encode(['received' => true, 'note' => 'unhandled event']);
