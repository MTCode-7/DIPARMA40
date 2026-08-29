<?php
/**
 * ============================================================
 * DI PARMA | POS Transaction API
 * ============================================================
 * 
 * الربط: POS (Bitel IC3600) → Nuvei → Mashreq Bank → Ledger
 * 
 * ============================================================
 * نقاط النهاية (Endpoints):
 *   POST /api/pos_transaction.php
 * 
 * ============================================================
 * أنواع العمليات المدعومة:
 *   - purchase          : شراء عادي (مع 3D Secure)
 *   - purchase_2d       : شراء بدون 3D Secure (MOTO)
 *   - purchase_advice   : شراء إرشادي
 *   - auth              : تفويض (تجميد مبلغ)
 *   - auth_complete     : تأكيد التفويض
 *   - refund            : استرداد
 *   - void              : إلغاء
 *   - reversal          : عكس عملية
 *   - cash_advance      : سلفة نقدية
 *   - withdrawal_physical: سحب نقدي فيزيائي
 *   - withdrawal_manual : سحب نقدي يدوي
 *   - balance           : استعلام رصيد
 *   - settlement        : تسوية
 * ============================================================
 */

// ============================================================
// 1. إعدادات الرأس والأمان
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Key, X-Timestamp, X-Signature, X-POS-Device');

// معالجة طلبات OPTIONS (CORS Preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// قبول طلبات POST فقط
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Use POST.'
    ]);
    exit;
}

// ============================================================
// 2. استيراد الملفات المطلوبة
// ============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

// بدء الجلسة (إذا لم تكن مبدوءة)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// 3. تعريف الثوابت (إذا لم تكن معرفة)
// ============================================================

if (!defined('LEDGER_TRC20_ADDRESS')) {
    define('LEDGER_TRC20_ADDRESS', getenv('LEDGER_TRC20_ADDRESS') ?: 'TEwLFWlwK55b7PuFfzgH1H2f3xs3pLgLn2');
}

if (!defined('HOT_WALLET_TRC20_ADDRESS')) {
    define('HOT_WALLET_TRC20_ADDRESS', getenv('HOT_WALLET_TRC20_ADDRESS') ?: '');
}

// ============================================================
// 4. قراءة بيانات الطلب
// ============================================================

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON input'
    ]);
    exit;
}

// ============================================================
// 5. استخراج البيانات
// ============================================================

$txnType = $data['txn_type'] ?? 'purchase';
$amount = floatval($data['amount'] ?? 0);
$currency = strtoupper($data['currency'] ?? 'USD');
$cardNumber = preg_replace('/\D/', '', $data['card_number'] ?? '');
$cardName = trim($data['card_name'] ?? '');
$cardExpiry = $data['card_expiry'] ?? '';
$cardCVV = $data['card_cvv'] ?? '';
$origRef = $data['orig_ref'] ?? '';
$ledgerAddr = trim($data['ledger_address'] ?? LEDGER_TRC20_ADDRESS);
$hotWalletAddr = trim($data['hot_wallet_address'] ?? HOT_WALLET_TRC20_ADDRESS);
$autoTransfer = (bool)($data['auto_transfer'] ?? false);
$posDevice = $data['pos_device'] ?? 'BITEL_IC3600';
$extra = $data['extra'] ?? [];
$userId = intval($_SESSION['user_id'] ?? 0);

// بيانات إضافية
$manualApproval = $extra['approval_code'] ?? '';
$manualRRN = $extra['manual_rrn'] ?? '';
$manualBank = $extra['bank'] ?? 'BOMLAEADXXX';
$manualNotes = $extra['notes'] ?? '';
$terminalId = $extra['terminal_id'] ?? 'T0000001';
$merchantId = $extra['merchant_id'] ?? '';
$posLocation = $extra['pos_location'] ?? '';
$secMode = strtoupper($extra['sec_mode'] ?? $extra['processing_mode'] ?? '2D');

// توليد مرجع فريد
$reference = $data['reference'] ?? 'POS-' . strtoupper(substr($txnType, 0, 4)) . '-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));

// ============================================================
// 6. التحقق من صحة البيانات
// ============================================================

$errors = [];

// التحقق من المبلغ
if ($amount <= 0 && !in_array($txnType, ['balance', 'settlement'])) {
    $errors[] = 'Invalid amount. Must be greater than 0.';
}

// التحقق من عنوان Ledger
if (!empty($ledgerAddr) && !preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $ledgerAddr)) {
    $errors[] = 'Invalid Tron address format. Must start with T and be 34 characters.';
}

// التحقق من أن Ledger مختلف عن Hot Wallet
if (!empty($ledgerAddr) && !empty($hotWalletAddr) && strcasecmp($ledgerAddr, $hotWalletAddr) === 0) {
    $errors[] = 'Ledger address must be different from the Hot Wallet address.';
}

// التحقق من بيانات البطاقة (لأنواع البطاقات)
$cardTypes = ['purchase', 'purchase_2d', 'purchase_advice', 'auth', 'auth_complete', 'cash_advance', 'withdrawal_physical', 'refund', 'void', 'reversal'];
if (in_array($txnType, $cardTypes)) {
    if (empty($cardNumber) || strlen($cardNumber) < 13) {
        $errors[] = 'Invalid card number. Must be 13-19 digits.';
    }
    if (!preg_match('/^(0[1-9]|1[0-2])\/([0-9]{2})$/', $cardExpiry)) {
        $errors[] = 'Invalid expiry date. Format: MM/YY.';
    }
    if (empty($cardCVV) || strlen($cardCVV) < 3) {
        $errors[] = 'Invalid CVV. Must be 3-4 digits.';
    }
}

// التحقق من المرجع الأصلي (للأنواع التي تتطلبه)
$origRequired = ['auth_complete', 'refund', 'void', 'reversal'];
if (in_array($txnType, $origRequired) && empty($origRef)) {
    $errors[] = 'Original reference is required for ' . $txnType . '.';
}

// التحقق من رمز الموافقة (للأنواع التي تتطلبه)
if ($txnType === 'withdrawal_manual' && empty($manualApproval)) {
    $errors[] = 'Approval code is required for manual withdrawal.';
}

// إذا كان هناك أخطاء، أعدها للمستخدم
if (!empty($errors)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Validation failed',
        'errors' => $errors
    ]);
    exit;
}

// ============================================================
// 7. الاتصال بقاعدة البيانات
// ============================================================

$db = db();

// ============================================================
// 8. معالجة عبر Nuvei (لأنواع البطاقات)
// ============================================================

$useNuvei = !in_array($txnType, ['balance', 'settlement']);
$success = false;
$message = 'PENDING';
$rrn = '';
$approvalCode = '';
$nuveiTxnId = null;
$gatewayResponse = [];
$requires3ds = false;
$redirectUrl = null;

if ($useNuvei) {
    try {
        // تحميل NuveiAdapter
        require_once __DIR__ . '/../lib/NuveiAdapter.php';
        
        $nuvei = new NuveiAdapter();
        
        $params = [
            'amount' => $amount,
            'currency' => $currency,
            'card_number' => $cardNumber,
            'card_name' => $cardName ?: 'CARDHOLDER',
            'card_expiry' => $cardExpiry,
            'card_cvv' => $cardCVV,
            'email' => $data['email'] ?? 'pos@diparmas.com',
            'phone' => preg_replace('/\D/', '', $data['phone'] ?? '971501234567') ?: '971501234567',
            'country' => strtoupper($data['country'] ?? 'AE'),
            'city' => $data['city'] ?? 'Dubai',
            'address' => $data['address'] ?? 'Al Barsha 1, Dubai, UAE',
            'zip' => $data['zip'] ?? '00000',
            'ip_address' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '1.1.1.1',
            'user_token_id' => 'user_' . $userId . '_' . time(),
            'pos_device' => $posDevice,
            'related_transaction_id' => $origRef,
            'reference' => $reference,
            'terminal_id' => $terminalId,
            'merchant_id' => $merchantId,
        ];

        // تنفيذ العملية حسب النوع
        switch ($txnType) {
            case 'purchase':
            case 'purchase_advice':
                if ($secMode === '3D') {
                    $result = $nuvei->purchase3D($params);
                } else {
                    $result = $nuvei->purchase($params);
                }
                break;

            case 'purchase_2d':
                $result = $nuvei->purchase2D($params);
                break;

            case 'auth':
                $result = $nuvei->authorize($params);
                break;

            case 'auth_complete':
                if (empty($origRef)) {
                    throw new Exception('Original transaction ID required for capture');
                }
                $result = $nuvei->capture($params);
                break;

            case 'refund':
                if (empty($origRef)) {
                    throw new Exception('Original transaction ID required for refund');
                }
                $result = $nuvei->refund($params);
                break;

            case 'void':
            case 'reversal':
                if (empty($origRef)) {
                    throw new Exception('Original transaction ID required for void');
                }
                $result = $nuvei->void($params);
                break;

            case 'cash_advance':
            case 'withdrawal_physical':
                $result = $nuvei->cashAdvance($params);
                break;

            default:
                $result = $nuvei->purchase($params);
        }

        $success = $result['success'] ?? false;
        $message = $result['message'] ?? ($success ? 'APPROVED' : 'DECLINED');
        $approvalCode = $result['approval_code'] ?? '';
        $rrn = $result['rrn'] ?? '';
        $nuveiTxnId = $result['nuvei_txn_id'] ?? null;
        $requires3ds = $result['requires_3ds'] ?? false;
        $redirectUrl = $result['redirect_url'] ?? null;
        $gatewayResponse = $result;

    } catch (Exception $e) {
        error_log('[POS][Nuvei] Exception: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Gateway error: ' . $e->getMessage()
        ]);
        exit;
    }
}

// ============================================================
// 9. معالجة Balance / Settlement
// ============================================================

if ($txnType === 'balance') {
    try {
        require_once __DIR__ . '/../lib/NuveiAdapter.php';
        $nuvei = new NuveiAdapter();
        $result = $nuvei->balanceInquiry([]);
        $success = $result['success'] ?? true;
        $message = 'BALANCE_INQUIRY_OK';
        $gatewayResponse = $result;
    } catch (Exception $e) {
        $success = false;
        $message = $e->getMessage();
    }
}

if ($txnType === 'settlement') {
    $success = true;
    $message = 'SETTLEMENT_INITIATED';
    $gatewayResponse = [
        'type' => 'settlement',
        'bank' => 'MASHREQ',
        'iban' => 'AE300330000019101562722',
        'swift' => 'BOMLAEADXXX',
        'account_name' => 'TRANSCENDIO FZ-LLC',
    ];
}

// ============================================================
// 10. حفظ في قاعدة البيانات
// ============================================================

try {
    $db->insert('dp_transactions', [
        'reference' => $reference,
        'user_id' => $userId > 0 ? $userId : null,
        'gateway' => 'nuvei_pos',
        'gateway_type' => 'card',
        'transaction_type' => $txnType,
        'transaction_label' => $data['transaction_label'] ?? strtoupper($txnType),
        'amount' => $amount,
        'currency' => $currency,
        'card_last4' => substr($cardNumber, -4),
        'cardholder_name' => $cardName ?: 'POS Client',
        'security_mode' => $secMode,
        'status' => $success ? 'completed' : 'failed',
        'gateway_response' => json_encode([
            'rrn' => $rrn,
            'approval_code' => $approvalCode,
            'nuvei_txn_id' => $nuveiTxnId,
            'type' => $txnType,
            'pos_device' => $posDevice,
            'terminal_id' => $terminalId,
            'acquirer' => 'Mashreq Bank PSC',
            'merchant' => 'TRANSCENDIO FZ-LLC',
            'bank_iban' => 'AE300330000019101562722',
            'ledger_address' => $ledgerAddr,
            'auto_transfer' => $autoTransfer,
            'requires_3ds' => $requires3ds,
            'redirect_url' => $redirectUrl,
            'extra' => $extra,
            'raw' => $gatewayResponse,
        ]),
        'ledger_address' => $ledgerAddr,
        'auth_code' => $approvalCode,
        'rrn' => $rrn,
        'acquirer' => 'Mashreq Bank PSC',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    
    $transactionId = $db->getPDO()->lastInsertId();
    
} catch (Exception $e) {
    error_log('[POS][DB] ' . $e->getMessage());
}

// ============================================================
// 11. تحويل تلقائي للـ Ledger
// ============================================================

$ledgerTransfer = false;
$ledgerTxid = null;
$ledgerStatus = 'pending';
$ledgerTransferTypes = ['purchase', 'purchase_2d', 'cash_advance', 'withdrawal_physical', 'withdrawal_manual'];

if ($autoTransfer && $success && !empty($ledgerAddr) && in_array($txnType, $ledgerTransferTypes)) {
    try {
        $transferResult = triggerLedgerTransfer($reference, $amount, $currency, $ledgerAddr);
        $ledgerTransfer = $transferResult['success'] ?? false;
        $ledgerTxid = $transferResult['txid'] ?? null;
        $ledgerStatus = $ledgerTransfer ? 'completed' : 'queued';
        
        // تحديث المعاملة بمعلومات Ledger
        if ($transactionId) {
            $db->update('dp_transactions', [
                'ledger_txid' => $ledgerTxid,
                'ledger_transferred' => $ledgerTransfer ? 1 : 0,
                'ledger_amount' => $transferResult['usdt_amount'] ?? 0,
                'ledger_address' => $ledgerAddr,
            ], ['id' => $transactionId]);
        }
    } catch (Exception $e) {
        error_log('[POS][Ledger] ' . $e->getMessage());
        $ledgerStatus = 'failed';
    }
}

// ============================================================
// 12. الاستجابة النهائية
// ============================================================

http_response_code(200);
echo json_encode([
    'success' => $success,
    'reference' => $reference,
    'rrn' => $rrn,
    'approval_code' => $approvalCode,
    'nuvei_txn_id' => $nuveiTxnId,
    'txn_type' => $txnType,
    'amount' => $amount,
    'currency' => $currency,
    'status_message' => $message,
    'pos_device' => $posDevice,
    'terminal_id' => $terminalId,
    'acquirer' => 'Mashreq Bank PSC',
    'merchant' => 'TRANSCENDIO FZ-LLC',
    'bank_iban' => 'AE300330000019101562722',
    'bank_swift' => 'BOMLAEADXXX',
    'ledger_transfer' => $ledgerTransfer,
    'ledger_txid' => $ledgerTxid,
    'ledger_address' => $ledgerAddr,
    'ledger_status' => $ledgerStatus,
    'requires_3ds' => $requires3ds,
    'redirect_url' => $redirectUrl,
    'timestamp' => date('c'),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

// ============================================================
// 13. دالة مساعدة: تشغيل تحويل Ledger
// ============================================================

function triggerLedgerTransfer(string $reference, float $amount, string $currency, string $ledgerAddr): array
{
    try {
        // تحويل المبلغ إلى USDT
        $usdtAmount = $amount;
        if ($currency !== 'USD') {
            $rates = [
                'AED' => 0.2723,
                'SAR' => 0.2667,
                'EUR' => 1.082,
                'GBP' => 1.271,
                'KWD' => 3.257,
                'QAR' => 0.2747,
                'EGP' => 0.0204,
            ];
            $usdtAmount = $amount * ($rates[$currency] ?? 1);
        }
        
        // استخدام TronGrid لإرسال USDT
        $tronApiKey = getenv('TRONGRID_API_KEY') ?: '';
        $hotWalletAddress = getenv('HOT_WALLET_TRC20_ADDRESS') ?: '';
        $hotWalletPrivateKey = getenv('HOT_WALLET_TRC20_KEY') ?: '';
        
        if (empty($tronApiKey) || empty($hotWalletPrivateKey)) {
            // إذا لم تكن الإعدادات مكتملة، ضع في قائمة الانتظار
            $db = db();
            $db->insert('ledger_transfer_queue', [
                'reference' => $reference,
                'ledger_address' => $ledgerAddr,
                'usdt_amount' => round($usdtAmount, 6),
                'currency_orig' => $currency,
                'status' => 'queued',
                'message' => 'TronGrid credentials missing. Queued for manual processing.',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            
            return [
                'success' => false,
                'message' => 'Transfer queued - TronGrid credentials missing',
                'usdt_amount' => round($usdtAmount, 6),
                'reference' => $reference,
                'txid' => null,
            ];
        }
        
        // إرسال USDT عبر TronGrid
        $sunAmount = (int)round($usdtAmount * 1000000);
        $toHex = base58ToHex($ledgerAddr);
        
        $transaction = [
            'owner_address' => $hotWalletAddress,
            'contract_address' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t', // USDT TRC20
            'function_selector' => 'transfer(address,uint256)',
            'parameter' => $toHex . str_pad(dechex($sunAmount), 64, '0', STR_PAD_LEFT),
            'fee_limit' => 20000000,
            'call_value' => 0,
            'visible' => true,
        ];
        
        $ch = curl_init('https://api.trongrid.io/wallet/triggersmartcontract');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($transaction),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'TRON-PRO-API-KEY: ' . $tronApiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $result = json_decode($response, true);
        
        if ($httpCode === 200 && isset($result['transaction']['txID'])) {
            return [
                'success' => true,
                'txid' => $result['transaction']['txID'],
                'usdt_amount' => round($usdtAmount, 6),
                'reference' => $reference,
                'message' => 'USDT sent successfully',
            ];
        } else {
            // فشل الإرسال - وضع في قائمة الانتظار
            $db = db();
            $db->insert('ledger_transfer_queue', [
                'reference' => $reference,
                'ledger_address' => $ledgerAddr,
                'usdt_amount' => round($usdtAmount, 6),
                'currency_orig' => $currency,
                'status' => 'failed',
                'message' => $result['message'] ?? 'Failed to send USDT',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            
            return [
                'success' => false,
                'message' => $result['message'] ?? 'Failed to send USDT',
                'usdt_amount' => round($usdtAmount, 6),
                'reference' => $reference,
                'txid' => null,
            ];
        }
        
    } catch (Exception $e) {
        error_log('[POS][Ledger] Error: ' . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage(),
            'usdt_amount' => 0,
            'reference' => $reference,
            'txid' => null,
        ];
    }
}

/**
 * تحويل عنوان Tron من Base58 إلى Hex
 */
function base58ToHex($address) {
    $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    $num = gmp_init(0);
    
    for ($i = 0; $i < strlen($address); $i++) {
        $pos = strpos($alphabet, $address[$i]);
        if ($pos === false) {
            throw new Exception('Invalid Tron address');
        }
        $num = gmp_add(gmp_mul($num, 58), $pos);
    }
    
    $hex = gmp_strval($num, 16);
    if (strlen($hex) % 2 !== 0) {
        $hex = '0' . $hex;
    }
    
    $addressHex = substr($hex, 2, strlen($hex) - 10);
    return str_pad($addressHex, 64, '0', STR_PAD_LEFT);
}

// ============================================================
// نهاية الملف
// ============================================================
?>