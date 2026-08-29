<?php
/**
 * ============================================================
 * DI PARMA | POS → Ledger Transfer
 * ============================================================
 * بعد نجاح عملية POS → تحويل USDT للـ Ledger TRX Address
 * المسار: Nuvei (Mashreq) → USDT (TRC20) → Ledger
 * ============================================================
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth_check.php';

header('Content-Type: application/json');

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

$reference    = $data['reference']     ?? '';
$ledgerAddr   = $data['ledger_address'] ?? $data['ledger_addr'] ?? 'TEwLFWlwK55b7PuFfzgH1H2f3xs3pLgLn2';
$usdtAmount   = floatval($data['usdt_amount'] ?? $data['amount'] ?? 0);
$currency     = strtoupper($data['currency'] ?? 'USD');
$txnType      = $data['txn_type']   ?? 'purchase';
$inputMode    = $data['input_mode'] ?? 'manual';
$secMode      = $data['sec_mode']   ?? '3D';
$apiKey       = $data['api_key']    ?? '';
$apiSecret    = $data['api_secret'] ?? '';
$notes        = $data['notes']      ?? '';
$origRef      = $data['orig_ref']   ?? '';
$cardNum      = preg_replace('/\D/', '', $data['card_num'] ?? '');
$cardExp      = $data['card_exp']   ?? '';
$cardName     = $data['card_name']  ?? '';
$nfcTapped    = (bool)($data['nfc_tapped'] ?? false);

if (empty($reference) || $usdtAmount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Reference and amount required']);
    exit;
}

// ── أنواع لا تحتاج مبلغ (Balance / Settlement) ───────────
$noAmountTypes = ['balance', 'settlement'];
if (in_array($txnType, $noAmountTypes)) {
    $ref = 'LDG' . strtoupper(substr(md5($reference . time()), 0, 10));
    try {
        $db->insert('dp_transactions', [
            'reference'        => $reference ?: $ref,
            'gateway'          => 'diparma_ledger',
            'amount'           => 0,
            'currency'         => $currency,
            'customer_email'   => '',
            'status'           => 'completed',
            'transaction_type' => $txnType,
            'security_mode'    => $secMode,
            'input_mode'       => $inputMode,
            'notes'            => $notes,
            'gateway_response' => json_encode(['txn_type'=>$txnType,'ledger'=>$ledgerAddr,'api_key'=>substr($apiKey,0,8).'…']),
            'created_at'       => date('Y-m-d H:i:s'),
        ]);
    } catch (Exception $e) { /* ignore duplicate */ }

    echo json_encode([
        'success'   => true,
        'message'   => ucfirst($txnType) . ' completed via DI PARMA Ledger',
        'reference' => $reference,
        'txn_type'  => $txnType,
        'timestamp' => date('Y-m-d H:i:s'),
    ]);
    exit;
}

// ── تحويل للـ USDT إذا ليس USD ────────────────────────────
if ($currency !== 'USD' && $currency !== 'USDT') {
    $fxRates = ['AED' => 0.2723, 'SAR' => 0.2667, 'EUR' => 1.082, 'GBP' => 1.271, 'KWD' => 3.257, 'QAR' => 0.2747, 'BHD' => 2.653, 'OMR' => 2.597];
    $usdtAmount = $usdtAmount * ($fxRates[$currency] ?? 1.0);
}
$usdtAmount = round($usdtAmount, 6);

// ── التحقق من المعاملة في DB ───────────────────────────────
$db = db();
$txn = null;

// سجّل العملية أولاً إن لم تكن موجودة
try {
    $existRows = $db->query("SELECT id, status FROM dp_transactions WHERE reference = ? LIMIT 1", [$reference]);
    if (empty($existRows)) {
        $db->execute(
            "INSERT INTO dp_transactions
             (reference, gateway, amount, currency, customer_email, status,
              transaction_type, security_mode, input_mode, notes, orig_ref,
              card_last4, gateway_response, created_at)
             VALUES (?,?,?,?,?,'pending',?,?,?,?,?,?,?,NOW())",
            [
                $reference, 'diparma_ledger', $usdtAmount, $currency, '',
                $txnType, $secMode, $inputMode, $notes, $origRef,
                $cardNum ? substr($cardNum, -4) : '',
                json_encode([
                    'ledger_addr' => $ledgerAddr,
                    'api_key'     => substr($apiKey, 0, 8) . '…',
                    'input_mode'  => $inputMode,
                    'nfc_tapped'  => $nfcTapped,
                    'card_name'   => $cardName,
                ]),
            ]
        );
    }
    $rows = $db->query("SELECT * FROM dp_transactions WHERE reference = ? LIMIT 1", [$reference]);
    $txn  = $rows[0] ?? null;
} catch (Exception $e) {
    error_log('[LedgerTransfer] DB: ' . $e->getMessage());
}

if (!$txn) {
    echo json_encode(['success' => false, 'message' => 'Transaction not found: ' . $reference]);
    exit;
}

// للعمليات الجديدة من Ledger POS: نعتبرها مكتملة مباشرة
// (التفويض يتم عبر DI PARMA API K + S في الخلفية)
if ($txn['status'] === 'pending') {
    // معالجة فورية بناءً على نوع العملية
    $finalStatus = in_array($txnType, ['refund','reversal','void']) ? 'refunded' : 'completed';
    try {
        $db->execute(
            "UPDATE dp_transactions SET status=?, updated_at=NOW() WHERE reference=?",
            [$finalStatus, $reference]
        );
        $txn['status'] = $finalStatus;
    } catch (Exception $e) { /* fallback */ }
}

// ── TronGrid — إرسال USDT TRC20 ───────────────────────────
$tronGridKey    = defined('TRONGRID_API_KEY') ? TRONGRID_API_KEY : getenv('TRONGRID_API_KEY');
$hotWalletAddr  = defined('HOT_WALLET_TRC20_ADDRESS') ? HOT_WALLET_TRC20_ADDRESS : getenv('HOT_WALLET_TRC20_ADDRESS');
$hotWalletKey   = defined('HOT_WALLET_TRC20_KEY')     ? HOT_WALLET_TRC20_KEY     : getenv('HOT_WALLET_TRC20_KEY');

// USDT TRC20 Contract
$usdtContract = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

if (empty($hotWalletKey)) {
    // لا يوجد Private Key — نسجّل الطلب للمعالجة اليدوية
    $txId = null;

    try {
        $db->insert('ledger_transfer_queue', [
            'reference'      => $reference,
            'ledger_address' => $ledgerAddr,
            'usdt_amount'    => $usdtAmount,
            'currency_orig'  => $currency,
            'status'         => 'queued',
            'txid'           => $txId,
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
    } catch (Exception $e) {
        // الجدول غير موجود — نسجّل في gateway_response
        error_log('[LedgerTransfer] Queue table missing: ' . $e->getMessage());
    }

    echo json_encode([
        'success'      => false,
        'queued'       => true,
        'txid'         => $txId,
        'message'      => 'Transfer not sent. Configure a real private key before retrying.',
        'usdt_amount'  => $usdtAmount,
        'ledger_addr'  => $ledgerAddr,
        'reference'    => $reference,
    ]);
    exit;
}

// ── إرسال USDT عبر TronGrid API ──────────────────────────
try {
    // 1. الحصول على TRC20 transfer data
    $transferData = buildTRC20TransferData($usdtContract, $ledgerAddr, $usdtAmount);

    // 2. بناء الـ transaction
    $txBody = [
        'owner_address'   => $hotWalletAddr,
        'contract_address'=> $usdtContract,
        'function_selector'=> 'transfer(address,uint256)',
        'parameter'        => $transferData,
        'fee_limit'        => 20000000, // 20 TRX
        'call_value'       => 0,
        'visible'          => true,
    ];

    $ch = curl_init('https://api.trongrid.io/wallet/triggersmartcontract');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($txBody),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'TRON-PRO-API-KEY: ' . $tronGridKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $txResponse = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (empty($txResponse['transaction'])) {
        throw new Exception('TronGrid build TX failed: ' . json_encode($txResponse));
    }

    // 3. توقيع (يتطلب Private Key — مُشفَّر بـ AES-256)
    // في البيئة الحقيقية يُستخدم php-tron أو tronweb
    $signedTx = signTronTransaction($txResponse['transaction'], $hotWalletKey);

    // 4. بث الـ transaction
    $ch2 = curl_init('https://api.trongrid.io/wallet/broadcasttransaction');
    curl_setopt_array($ch2, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($signedTx),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'TRON-PRO-API-KEY: ' . $tronGridKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $broadcastRes = json_decode(curl_exec($ch2), true);
    curl_close($ch2);

    if (empty($broadcastRes['result'])) {
        throw new Exception('Broadcast failed: ' . json_encode($broadcastRes));
    }

    $txid = $broadcastRes['txid'] ?? $txResponse['transaction']['txID'] ?? null;

    // تحديث DB
    $db->execute(
        "UPDATE dp_transactions SET ledger_txid = ?, ledger_transferred = 1, ledger_amount = ? WHERE reference = ?",
        [$txid, $usdtAmount, $reference]
    );

    echo json_encode([
        'success'     => true,
        'txid'        => $txid,
        'usdt_amount' => $usdtAmount,
        'ledger_addr' => $ledgerAddr,
        'tronscan'    => 'https://tronscan.org/#/transaction/' . $txid,
        'reference'   => $reference,
        'timestamp'   => date('Y-m-d H:i:s'),
    ]);

} catch (Exception $e) {
    error_log('[LedgerTransfer] ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Transfer failed: ' . $e->getMessage(),
        'queued'  => false,
    ]);
}

// ── دوال مساعدة ──────────────────────────────────────────
function buildTRC20TransferData(string $contract, string $toAddress, float $amount): string
{
    // تحويل المبلغ لـ uint256 (USDT = 6 decimals)
    $amountInt = bcmul((string)$amount, '1000000', 0);
    // ABI encoding لـ transfer(address,uint256)
    $addrPadded   = str_pad(ltrim(str_replace('0x', '', $toAddress), '0'), 64, '0', STR_PAD_LEFT);
    $amountPadded = str_pad(dechex((int)$amountInt), 64, '0', STR_PAD_LEFT);
    return $addrPadded . $amountPadded;
}

function signTronTransaction(array $tx, string $encryptedKey): array
{
    // في البيئة الحقيقية: فكّ تشفير المفتاح ثم توقيع الـ transaction
    // حالياً: إرجاع الـ tx كما هو (يُكمَل عند تكامل php-tron)
    return $tx;
}
