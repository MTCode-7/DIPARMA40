<?php
/**
 * DI PARMA | PayRam Payment API
 * POST: create, assign_address
 * GET:  status
 */
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) session_start();
set_exception_handler(function($e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit; });

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../lib/PayRamAdapter.php';

$payram = new PayRamAdapter();
$ledgerAddress = defined('LEDGER_TRC20_ADDRESS')
    ? LEDGER_TRC20_ADDRESS
    : (getenv('LEDGER_TRC20_ADDRESS') ?: '');
$method = $_SERVER['REQUEST_METHOD'];

/* ── GET: status ── */
if ($method === 'GET') {
    $ref = trim($_GET['ref'] ?? '');
    if (!$ref) { echo json_encode(['success'=>false,'message'=>'ref required']); exit; }
    $result = $payram->getPaymentStatus($ref);
    echo json_encode($result);
    exit;
}

/* ── POST ── */
$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? 'create';

/* CSRF - التحقق الإجباري */
if (!isset($body['csrf_token']) || empty($body['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'❌ CSRF token مفقود / Missing CSRF token']); 
    exit;
}

if (!function_exists('verifyCsrfToken')) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'❌ دالة التحقق غير متاحة / Verification function unavailable']);
    exit;
}

if (!verifyCsrfToken($body['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'❌ Invalid CSRF token - فشل التحقق الأمني / Security verification failed. Session expired or invalid token.']); 
    exit;
}

switch ($action) {

    /* ── إنشاء دفعة ── */
    case 'create':
        $amount    = (float)($body['amount'] ?? 0);
        $email     = trim($body['email']   ?? 'client@diparmas.com');
        $custId    = trim($body['customer_id'] ?? 'dp_'.time());
        // Card onramp is handled by the PayRam payment page and currently requires Base.
        $chainCode = strtoupper(trim($body['blockchain_code'] ?? 'BASE'));
        $tokenCode = strtoupper(trim($body['currency_code'] ?? 'USDC'));
        $txnType   = strtolower(trim($body['txn_type'] ?? 'purchase'));
        $reference = trim($body['reference'] ?? '');

        if ($amount <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid amount']); exit; }
        if ($txnType !== 'purchase') {
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'message' => 'PayRam supports direct crypto purchase only; authorization, capture, refund, reversal, void, balance, settlement, cash advance, purchase advice, and quasi-cash require a separate card/bank integration.',
                'supported_types' => ['purchase'],
            ]);
            exit;
        }

        /* إنشاء الدفعة في PayRam */
        $result = $payram->createPayment([
            'amount'      => $amount,
            'email'       => $email,
            'customer_id' => $custId,
        ]);

        if (!$result['success']) {
            echo json_encode($result); exit;
        }

        $refId = $result['reference_id'];

        // Do not assign a TRX deposit address for card onramp. PayRam handles the
        // card flow in its hosted page and settles according to its project setup.
        $depositAddr = null;

        /* تسجيل في DB */
        try {
            db()->execute(
                "INSERT INTO dp_transactions
                 (reference, gateway, amount, currency, status,
                  transaction_type, gateway_response, notes, created_at)
                 VALUES (?,?,?,?,'pending','payram_crypto',?,?,NOW())",
                [
                    $reference ?: $refId,
                    'payram',
                    $amount,
                    'USD',
                    json_encode([
                        'payram_ref'    => $refId,
                        'invoice_id'    => $result['invoice_id'],
                        'chain'         => $chainCode,
                        'token'         => $tokenCode,
                        'txn_type'      => $txnType,
                        'destination'   => 'ledger_trx',
                        'ledger_address'=> $ledgerAddress,
                        'deposit_addr'  => $depositAddr,
                        'payram_url'    => $result['url'],
                        'email'         => $email,
                        'customer_id'   => $custId,
                    ]),
                    $body['notes'] ?? null,
                ]
            );
        } catch (Exception $e) { error_log('[PayRam] DB: '.$e->getMessage()); }

        /* تحديث stats */
        try {
            db()->execute("UPDATE dp_api_clients SET total_txns=total_txns+1, last_used_at=NOW() WHERE status='active' ORDER BY id DESC LIMIT 1", []);
        } catch(Exception $e) {}

        echo json_encode([
            'success'       => true,
            'reference_id'  => $refId,
            'invoice_id'    => $result['invoice_id'],
            'url'           => $result['url'],
            'deposit_address' => $depositAddr,
            'chain'         => $chainCode,
            'token'         => $tokenCode,
            'destination'   => 'ledger_trx',
            'ledger_address'=> $ledgerAddress,
            'amount_usd'    => $amount,
        ]);
        break;

    /* ── تعيين عنوان إيداع ── */
    case 'assign_address':
        $refId = trim($body['reference_id'] ?? '');
        $chain = strtoupper($body['blockchain_code'] ?? 'TRX');
        if (!$refId) { echo json_encode(['success'=>false,'message'=>'reference_id required']); exit; }
        $result = $payram->assignDepositAddress($refId, $chain);
        echo json_encode([
            'success' => isset($result['Address']) || isset($result['address']),
            'address' => $result['Address'] ?? $result['address'] ?? null,
            'family'  => $result['Family']  ?? null,
            'status'  => $result['Status']  ?? null,
            'raw'     => $result,
        ]);
        break;

    /* ── إنشاء payout ── */
    case 'payout':
        $result = $payram->createPayout([
            'email'          => $body['email']          ?? 'client@diparmas.com',
            'blockchain_code'=> $body['blockchain_code'] ?? 'TRX',
            'currency_code'  => $body['currency_code']   ?? 'USDT',
            'amount'         => (string)($body['amount'] ?? 0),
            'to_address'     => $body['to_address']      ?? '',
            'customer_id'    => $body['customer_id']     ?? 'dp_'.time(),
        ]);
        echo json_encode($result);
        break;

    default:
        echo json_encode(['success'=>false,'message'=>'Unknown action']);
}
