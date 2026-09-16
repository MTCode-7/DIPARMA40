<?php
/**
 * ============================================================
 * DI PARMA | POS Transaction API
 * ============================================================
 *
 * SUNMI / Bitel / Web POS / VX 675
 *         ↓
 *    /pos/index.php   ← MY POS · CHECKOUT · LINK · WEB
 *         ↓
 *    /pos/api/transaction.php  (أو /api/pos_transaction.php)
 *         ↓
 *    pos_run_payment_orchestrator()  → Orders wrap
 *         ↓
 *    pos_run_standalone_gateway()
 *         ↓
 *    Adapter (Nuvei / Square / Stripe / …)
 *         ↓
 *    Card Network / بوابة الدفع
 *         ↓
 *    Ledger USDT
 *
 * ============================================================
 * نقاط النهاية (Endpoints):
 *   POST /api/pos_transaction.php
 *   POST /pos/api/transaction.php
 *
 * ============================================================
 * أنواع العمليات المدعومة (معيار POS):
 *   - purchase_2d / purchase_3d
 *   - online_sale_moto / offline_sale_moto
 *   - auth / capture / purchase_advice
 *   - refund / avoid
 *   - withdrawal_pos / withdrawal_nfc  (واجهة النظام — كل أنواع الكروت والشركات)
 * ============================================================
 */

// ============================================================
// 1. إعدادات الرأس والأمان
// ============================================================

header('Content-Type: application/json; charset=utf-8');
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

require_once dirname(__DIR__) . '/bootstrap.php';
pos_restore_operator();
require_once POS_APP_ROOT . '/api/v1/ApiAuth.php';

// بدء الجلسة (إذا لم تكن مبدوءة)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// 3. تعريف الثوابت (إذا لم تكن معرفة)
// ============================================================

if (!defined('LEDGER_TRC20_ADDRESS')) {
    define('LEDGER_TRC20_ADDRESS', getenv('LEDGER_TRC20_ADDRESS') ?: '');
}

if (!defined('HOT_WALLET_TRC20_ADDRESS')) {
    define('HOT_WALLET_TRC20_ADDRESS', getenv('HOT_WALLET_TRC20_ADDRESS') ?: '');
}

// ============================================================
// 4. قراءة بيانات الطلب
// ============================================================

$rawInput = file_get_contents('php://input');
$data = $GLOBALS['POS_FORWARDED_BODY'] ?? json_decode($rawInput, true);

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

$extraEarly = is_array($data['extra'] ?? null) ? $data['extra'] : [];
$requestedGateway = pos_normalize_gateway((string)($data['gateway'] ?? $data['card_provider'] ?? $extraEarly['gateway'] ?? ''));
if ($requestedGateway === 'diparma_gateway') {
    $alt = pos_normalize_gateway((string)($data['card_provider'] ?? $extraEarly['card_provider'] ?? ''));
    if ($alt === 'diparma_gateway') {
        $alt = '';
    }
    $requestedGateway = $alt;
}
if ($requestedGateway === '' || !pos_is_charge_processor($requestedGateway) || !pos_gateway_is_live($requestedGateway)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Pick an enabled payment gateway. Ledger is the settlement destination, not a charge gateway.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$posGateway = $requestedGateway;
$gwMeta = pos_gateway_meta($posGateway) ?? [];
$cardRail = pos_gateway_requires_card($posGateway);

$txnTypeRaw = $data['txn_type'] ?? 'purchase_2d';
$txnType = pos_normalize_operation($txnTypeRaw);
$opMeta = pos_operation_meta($txnType) ?? [];
$amount = floatval($data['amount'] ?? 0);
$currency = strtoupper($data['currency'] ?? 'USD');
if (pos_gateway_requires_card($requestedGateway)) {
    $currency = pos_best_card_currency($currency);
}
$cardNumber = preg_replace('/\D/', '', $data['card_number'] ?? $data['cc_number'] ?? '');
$cardName = trim($data['card_name'] ?? $data['name'] ?? '');
$cardExpiry = trim((string)($data['card_expiry'] ?? $data['cc_expiry'] ?? ''));
$cardCVV = trim((string)($data['card_cvv'] ?? $data['cc_cvv'] ?? ''));
$cardType = strtoupper(trim((string)($data['card_type'] ?? $data['card_type_selected'] ?? 'LIVE')));
$cardNetwork = pos_normalize_card_network((string)($data['card_network'] ?? $data['card_scheme'] ?? $extraEarly['card_network'] ?? 'auto'));
$cloudToken = trim((string)($data['cloud_token'] ?? $data['payment_token'] ?? $data['source_id'] ?? ''));
$sourceId = trim((string)($data['source_id'] ?? $cloudToken));
$origRef = trim((string)($data['orig_ref'] ?? $data['rrn'] ?? $data['bank_rrn'] ?? $data['refund_reference'] ?? ''));
$bankRrn = pos_normalize_rrn((string)($data['bank_rrn'] ?? $extraEarly['bank_rrn'] ?? $origRef));
$paymentId = trim((string)($data['payment_id'] ?? $data['nuvei_txn_id'] ?? $data['transaction_id'] ?? $extraEarly['payment_id'] ?? ''));
$gatewayApprovalCode = trim((string)($data['gateway_approval_code'] ?? $data['auth_code'] ?? $extraEarly['gateway_approval_code'] ?? ''));
$ledgerAddr = trim((string) LEDGER_TRC20_ADDRESS);
$hotWalletAddr = trim($data['hot_wallet_address'] ?? HOT_WALLET_TRC20_ADDRESS);
$autoTransfer = true;
$destination = 'ledger';
if (in_array(strtolower(trim((string)($data['destination'] ?? ''))), ['bank', 'mashreq', 'iban', 'gateway'], true)) {
    $destination = 'ledger';
}
$extra = is_array($data['extra'] ?? null) ? $data['extra'] : [];
$arrival = function_exists('activity_normalize_arrival')
    ? activity_normalize_arrival((string) ($data['arrival'] ?? $extra['arrival'] ?? 'wallet'))
    : 'wallet';
$payoutVia = function_exists('activity_normalize_payout_rail')
    ? activity_normalize_payout_rail((string) ($data['payout_via'] ?? $extra['payout_via'] ?? ''))
    : '';
$extra['arrival'] = $arrival;
$extra['payout_via'] = $payoutVia;
$autoTransfer = $arrival !== 'payout';
$channels = pos_parse_channels($data, $txnType);
$channelNfc = in_array('nfc', $channels, true);
$channelPos = in_array('pos', $channels, true) || !$channelNfc;
$resolvedDevice = pos_device_resolve([
    'pos_model' => (string)($data['pos_model'] ?? $extra['pos_model'] ?? $data['pos_device'] ?? ''),
    'pos_device' => (string)($data['pos_device'] ?? ''),
    'terminal_id' => (string)($extra['terminal_id'] ?? $data['terminal_id'] ?? ''),
    'withdrawal_nfc' => $channelNfc || ($txnType === 'withdrawal_nfc'),
    'withdrawal_pos' => $channelPos || ($txnType === 'withdrawal_pos') || $channelPos,
]);
$posDevice = $resolvedDevice['code'];
$posModel = $resolvedDevice['model'];
$posType = $resolvedDevice['type'];
$entryChannel = strtolower(trim((string)($data['channel'] ?? $extra['channel'] ?? $extraEarly['channel'] ?? '')));
if ($entryChannel === 'pos_web' || $entryChannel === 'web_pos') {
    $entryChannel = 'web';
}
if (!in_array($entryChannel, ['pos', 'checkout', 'link', 'web'], true)) {
    if (!empty($data['link_id']) || !empty($extra['link_id']) || !empty($extraEarly['link_id'])) {
        $entryChannel = 'link';
    } elseif ($posType === 'web' || stripos((string) $posDevice, 'web') !== false) {
        $entryChannel = 'web';
    } else {
        $entryChannel = 'pos';
    }
}
$cardNetwork = pos_normalize_card_network((string)($data['card_network'] ?? $data['card_scheme'] ?? ($extra['card_network'] ?? $cardNetwork ?? 'auto')));
if ($cardNetwork === 'auto' && $cardNumber !== '') {
    $cardNetwork = pos_detect_card_network($cardNumber);
}
$userId = intval($_SESSION['user_id'] ?? 0);

if ($userId > 0) {
    if (!verifyCsrfToken($data['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
} else {
    try {
        $apiClient = ApiAuth::verify();
        $userId = (int)($apiClient['user_id'] ?? 0);
        if ($userId <= 0) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'API client is not assigned to a user account']);
            exit;
        }
    } catch (Throwable $e) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication required']);
        exit;
    }
}

// بيانات إضافية
$manualApproval = $extra['approval_code'] ?? '';
$bankApprovalCode = trim((string)($data['approval_code'] ?? $data['bank_approval_code'] ?? $manualApproval ?? ''));
$manualRRN = $extra['manual_rrn'] ?? $data['bank_rrn'] ?? $bankRrn;
if ($gatewayApprovalCode === '') {
    $gatewayApprovalCode = trim((string)($extra['gateway_approval_code'] ?? $extra['auth_code'] ?? ''));
}
if ($gatewayApprovalCode === '') {
    $gatewayApprovalCode = $bankApprovalCode;
}
if ($paymentId === '') {
    $paymentId = trim((string)($extra['payment_id'] ?? $extra['nuvei_txn_id'] ?? ''));
}
if ($bankRrn !== '') {
    $origRef = $bankRrn;
}
$manualNotes = $extra['notes'] ?? '';
$terminalId = $resolvedDevice['terminal_id'] ?? pos_normalize_terminal_id((string)($extra['terminal_id'] ?? $data['tid'] ?? ''));
$merchantId = $extra['merchant_id'] ?? '';
$posLocation = $extra['pos_location'] ?? '';
$secMode = '2D';
if ($cardType === 'CLOUD') {
    $secMode = '2D';
} elseif (in_array($txnType, ['withdrawal_pos', 'withdrawal_nfc'], true)) {
    $secMode = '2D';
} elseif ($txnType === 'purchase_3d' || strtoupper($opMeta['security'] ?? '') === '3D') {
    $secMode = '3D';
} else {
    $secMode = strtoupper($extra['sec_mode'] ?? $extra['processing_mode'] ?? ($opMeta['security'] ?? '2D'));
}

// توليد مرجع فريد
$reference = $data['reference'] ?? 'POS-' . strtoupper(substr($txnType, 0, 4)) . '-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));

// ============================================================
// 6. التحقق من صحة البيانات
// ============================================================

$errors = [];

// بدون حدود مبلغ عامة — فقط أكبر من صفر (ما عدا avoid بدون مبلغ)
if ($amount <= 0 && $txnType !== 'avoid') {
    $errors[] = 'Invalid amount. Must be greater than 0.';
}
// Bank account ceiling: Direct Advice / Purchase Advice capped at 5,000,000
if ($txnType === 'purchase_advice') {
    $bankCap = function_exists('pos_direct_advice_max_amount') ? pos_direct_advice_max_amount() : 5000000.00;
    if ($amount > $bankCap) {
        $errors[] = 'Bank account limit: amount cannot exceed ' . number_format($bankCap, 2, '.', ',') . '.';
    }
}
// Bank lock: AUTH Capture only — 5,000,000 USD
if ($txnType === 'capture') {
    $captureCap = function_exists('pos_capture_max_amount') ? pos_capture_max_amount() : 5000000.00;
    $capturePeek = floatval($data['capture_amount'] ?? $extra['capture_amount'] ?? 0);
    $captureCheck = $capturePeek > 0 ? $capturePeek : $amount;
    if ($captureCheck > $captureCap) {
        $errors[] = 'Bank capture limit: amount cannot exceed ' . number_format($captureCap, 2, '.', ',') . ' USD.';
    }
}
// Bank offline (SAF) ceiling: 2,000,000 per sale
if ($txnType === 'offline_sale_moto') {
    $offlineCap = function_exists('pos_offline_sale_max_amount') ? pos_offline_sale_max_amount() : 2000000.00;
    if ($amount > $offlineCap) {
        $errors[] = 'Offline bank limit: amount cannot exceed ' . number_format($offlineCap, 2, '.', ',') . '.';
    }
}
if ($cardNumber !== '' && pos_is_blocked_test_card($cardNumber)) {
    $errors[] = 'Test and dummy cards are blocked. Use a real card.';
}
if ($terminalId === '' || (function_exists('pos_dummy_terminal_ids') && in_array($terminalId, pos_dummy_terminal_ids(), true))) {
    $errors[] = 'Real Terminal ID (TID) is required. Dummy TIDs are rejected.';
}
if (empty($resolvedDevice['model']) || empty($resolvedDevice['accepted'])) {
    $errors[] = 'Select a real POS model from the catalog.';
}

if (!preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $ledgerAddr)) {
    $errors[] = 'LEDGER_TRC20_ADDRESS is required. POS settles gateway net to Ledger only.';
}

if (!empty($ledgerAddr) && !empty($hotWalletAddr) && strcasecmp($ledgerAddr, $hotWalletAddr) === 0) {
    $errors[] = 'Ledger address must be different from the Hot Wallet address.';
}

$requiresCard = !empty($opMeta['requires_card']);
$requiresCvv  = !empty($opMeta['requires_cvv']);
$requiresOrig = !empty($opMeta['requires_rrn']) || !empty($opMeta['requires_orig']);
$requiresApproval = !empty($opMeta['requires_approval']);
$chargeMode = trim((string)($data['charge_mode'] ?? $extra['charge_mode'] ?? $data['withdrawal_mode'] ?? ''));
$adviceChannel = strtolower(trim((string)($extra['advice_channel'] ?? $data['advice_channel'] ?? '')));

// لـ purchase_advice: طول Approval حسب القناة (online=4 / offline=6)
if ($txnType === 'purchase_advice' && $adviceChannel !== '') {
    // يُحقَّق أدناه عبر تعديل مؤقت لطول الموافقة
}

if ($cardType === 'CLOUD' && $cardRail && strlen($cloudToken) < 8 && !in_array($txnType, ['refund', 'avoid'], true)) {
    $errors[] = 'CLOUD token is required. Use a real gateway token (Nuvei UPO, Stripe pm_/tok_, PayPal vault).';
}

// قواعد الحقول المعيارية (RRN 12 / Approval 4|6 / بطاقة / انتهاء)
$fieldPayload = [
    'rrn' => $origRef !== '' ? $origRef : ($data['rrn'] ?? $manualRRN ?? ''),
    'orig_ref' => $origRef,
    'bank_rrn' => $bankRrn,
    'approval_code' => $bankApprovalCode,
    'bank_approval_code' => $bankApprovalCode,
    'payment_id' => $paymentId,
    'nuvei_txn_id' => $paymentId,
    'transaction_id' => $paymentId,
    'gateway_approval_code' => $gatewayApprovalCode,
    'auth_code' => $gatewayApprovalCode,
    'card_number' => $cardNumber,
    'card_expiry' => $cardExpiry,
    'charge_mode' => $chargeMode,
];
$nuveiVerix = false;
$inputMode = strtolower(trim((string)($data['input_mode'] ?? $extra['input_mode'] ?? 'manual')));
$entryMode = strtolower(trim((string)($extra['entry_mode'] ?? $data['entry_mode'] ?? '')));
$isPhysicalEntry = $inputMode === 'physical';
$nuveiTxnEarly = trim((string)($data['nuvei_txn_id'] ?? $extra['nuvei_txn_id'] ?? ''));
$nuveiVerix = pos_device_is_verix($resolvedDevice)
    && $cardNumber === ''
    && ($bankApprovalCode !== '' || trim((string) $origRef) !== '' || $nuveiTxnEarly !== '');
if ($nuveiVerix) {
    $requiresCard = false;
    $requiresCvv = false;
}
if ($cardRail && $cardType !== 'CLOUD' && $isPhysicalEntry && $requiresCard && (strlen($cardNumber) < 13 || $cardExpiry === '')) {
    $errors[] = 'Physical POS requires a real card from the reader. Simulation and placeholder cards are disabled.';
}
$fieldErrors = ($cardRail && $cardType !== 'CLOUD' && !$nuveiVerix) ? pos_validate_operation_fields($txnType, $fieldPayload) : [];
if (!$cardRail && in_array($txnType, ['refund', 'avoid'], true) && pos_normalize_rrn($origRef) === '' && trim($origRef) === '') {
    $fieldErrors[] = 'Original reference is required for refund/avoid.';
}

if ($cardRail && $txnType === 'purchase_advice') {
    if (!pos_is_valid_approval($bankApprovalCode, 6)) {
        $fieldErrors[] = 'Approval Code must be 4 or 6 digits (bank/other-terminal hold).';
        $fieldErrors = array_values(array_filter($fieldErrors, static function ($e) {
            return stripos($e, 'Approval Code is required') === false;
        }));
    }
}
$errors = array_merge($errors, $fieldErrors);

// CVV فقط عندما تطلبه العملية على سكة البطاقة (وليس CLOUD)
if ($cardRail && $cardType !== 'CLOUD' && $requiresCvv && (empty($cardCVV) || strlen($cardCVV) < 3)) {
    // لا تكرر خطأ البطاقة إن فشل التحقق العام
    if (!in_array('Card number must be 13–19 digits.', $errors, true)) {
        $errors[] = 'Invalid CVV. Must be 3-4 digits.';
    }
}

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Validation failed',
        'errors' => array_values(array_unique($errors))
    ]);
    exit;
}

// ============================================================
// 7. الاتصال بقاعدة البيانات
// ============================================================

$db = db();

$captureAmount = floatval($data['capture_amount'] ?? $extra['capture_amount'] ?? 0);
$refundAmount = floatval($data['refund_amount'] ?? $extra['refund_amount'] ?? 0);
if ($txnType === 'capture') {
    if ($captureAmount <= 0 && $refundAmount <= 0) {
        $captureAmount = $amount;
    }
    if ($captureAmount > 0) {
        $amount = $captureAmount;
    } elseif ($refundAmount > 0) {
        $amount = $refundAmount;
    }
}

// Capture فقط مرتبط بـ AUTH — advice مستقل
$authorizedAmount = null;
if ($txnType === 'capture' && ($origRef !== '' || $paymentId !== '')) {
    $originalRows = $db->query(
        "SELECT amount, transaction_type, status, rrn, reference FROM " . DB_PREFIX . "transactions
         WHERE reference = ? OR rrn = ? OR gateway_response LIKE ? LIMIT 1",
        [$origRef !== '' ? $origRef : $paymentId, pos_normalize_rrn($origRef), '%' . $paymentId . '%']
    );
    if (!empty($originalRows[0]['amount'])) {
        $authorizedAmount = (float)$originalRows[0]['amount'];
        // مبلغ مساوٍ أو أقل أو أكثر — الشبكة تقرر
    }
}

// ============================================================
// 8. معالجة عبر بوابة البطاقة المختارة فقط (بدون تبديل صامت)
// ============================================================

$useCardGateway = !in_array($txnType, ['balance', 'settlement']);
$success = false;
$message = 'PENDING';
$responseCode = '';
$rrn = '';
$originalRrn = $origRef;
$stan = '';
$approvalCode = '';
$nuveiTxnId = null;
$gatewayResponse = [];
$requires3ds = false;
$redirectUrl = null;
$result = [];
$orderPersistedByOrchestrator = false;
$orchestratorOrderId = null;

if ($useCardGateway) {
    try {
        $params = [
            'amount' => $amount,
            'currency' => $currency,
            'card_number' => $cardNumber,
            'card_name' => $cardName ?: 'CARDHOLDER',
            'card_expiry' => $cardExpiry,
            'card_cvv' => $cardCVV,
            'card_type' => $cardType,
            'card_network' => $cardNetwork,
            'cloud_token' => $cloudToken,
            'payment_token' => $cloudToken,
            'source_id' => $sourceId,
            'email' => $data['email'] ?? '',
            'phone' => preg_replace('/\D/', '', $data['phone'] ?? '') ?: '',
            'country' => strtoupper($data['country'] ?? 'AE'),
            'city' => $data['city'] ?? '',
            'address' => $data['address'] ?? '',
            'zip' => $data['zip'] ?? '',
            'ip_address' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null,
            'user_token_id' => 'user_' . $userId . '_' . time(),
            'pos_device' => $posDevice,
            'channel' => $entryChannel,
            'related_transaction_id' => $paymentId !== '' ? $paymentId : $origRef,
            'auth_code' => $gatewayApprovalCode !== '' ? $gatewayApprovalCode : ($data['auth_code'] ?? ($data['approval_code'] ?? ($extra['auth_code'] ?? $bankApprovalCode))),
            'client_unique_id' => $origRef !== '' ? $origRef : $reference,
            'payment_id' => $paymentId,
            'nuvei_txn_id' => $paymentId,
            'authorized_amount' => $authorizedAmount,
            'reference' => $reference,
            'terminal_id' => $terminalId,
            'merchant_id' => $merchantId,
            'moto_indicator' => $extra['moto_indicator'] ?? (in_array($txnType, ['auth', 'purchase_advice', 'offline_sale_moto', 'online_sale_moto'], true) ? 'M' : null),
            'is_moto' => !empty($extra['is_moto']) || !empty($opMeta['is_moto']) || in_array($txnType, ['auth', 'purchase_advice', 'offline_sale_moto', 'online_sale_moto'], true),
            'auth_channel' => $extra['auth_channel'] ?? $data['auth_channel'] ?? '',
            'moto_channel' => $extra['auth_channel'] ?? $data['auth_channel'] ?? '',
            'entry_mode' => $extra['entry_mode'] ?? ($opMeta['entry_mode'] ?? 'keyed'),
            'scheme_route' => $cardNetwork,
            'allow_amount_override' => !empty($opMeta['amount_flexible']),
            'ledger_addr' => $ledgerAddr,
            'ledger_address' => $ledgerAddr,
            'destination' => 'ledger',
            'settlement_target' => 'ledger',
        ];

        $runType = $txnType;
        if ($txnType === 'auth') {
            $params['is_moto'] = true;
            $params['moto_indicator'] = 'M';
            $params['card_cvv'] = '';
            $params['entry_mode'] = 'keyed';
            $params['card_present'] = false;
            $params['auth_channel'] = strtolower((string) ($extra['auth_channel'] ?? $data['auth_channel'] ?? 'online'));
            if ($params['auth_channel'] === 'offline') {
                $params['is_offline'] = true;
            }
        }
        if (in_array($txnType, ['withdrawal_pos', 'withdrawal_nfc'], true)) {
            $modes = pos_withdrawal_charge_modes();
            $mode = $modes[$chargeMode] ?? null;
            $runType = $mode['base'] ?? 'purchase_2d';
            if ($runType === 'purchase_3d') {
                $runType = 'purchase_2d';
            }
            $params['channels'] = $channels;
            $params['withdrawal'] = true;
            $params['charge_mode'] = $chargeMode;
            $params['input_mode'] = (string) ($data['input_mode'] ?? $extra['input_mode'] ?? 'manual');
            $params['is_physical'] = !empty($data['is_physical']) || $params['input_mode'] === 'physical';
            if ($params['input_mode'] === 'manual') {
                $params['entry_mode'] = 'keyed';
                $params['card_present'] = false;
            } else {
                $params['entry_mode'] = pos_channels_entry_mode($channels);
                $params['card_present'] = true;
            }
            $params['moto_channel'] = $mode['channel'] ?? null;
            if (!empty($mode['channel'])) {
                $params['is_moto'] = true;
                $params['moto_indicator'] = 'M';
            }
        }
        if ($params['email'] === '') {
            try {
                $urows = $db->query("SELECT email FROM " . DB_PREFIX . "users WHERE id=? LIMIT 1", [$userId]);
                if (!empty($urows[0]['email'])) {
                    $params['email'] = (string)$urows[0]['email'];
                }
            } catch (Throwable $e) {
            }
        }
        if (in_array($runType, ['refund', 'avoid'], true) && $origRef === '') {
            throw new Exception('Original transaction ID required for ' . $runType);
        }
        if ($cardRail && $runType === 'capture' && $origRef === '') {
            throw new Exception('Original AUTH RRN is required for capture');
        }
        if ($runType === 'purchase_advice') {
            $params['card_cvv'] = '';
            $params['linked_to_auth'] = false;
        }
        if ($txnType === 'capture') {
            $params['capture_amount'] = $captureAmount;
            $params['refund_amount'] = $refundAmount;
            $params['linked_to_auth'] = true;
            $capOk = true;
            $refOk = true;
            $result = ['success' => false, 'message' => 'Enter withdraw and/or refund amount'];
            if ($captureAmount > 0 && $refundAmount <= 0) {
                $params['amount'] = $captureAmount;
                $result = pos_run_payment_orchestrator($posGateway, 'capture', $params);
                $capOk = !empty($result['success']);
            } elseif ($captureAmount > 0) {
                $params['amount'] = $captureAmount;
                $result = pos_run_standalone_gateway($posGateway, 'capture', $params);
                $capOk = !empty($result['success']);
            }
            if ($refundAmount > 0) {
                $refundParams = $params;
                $refundParams['amount'] = $refundAmount;
                $refundType = $captureAmount <= 0 ? 'avoid' : 'refund';
                $refundResult = $captureAmount <= 0
                    ? pos_run_payment_orchestrator($posGateway, $refundType, $refundParams)
                    : pos_run_standalone_gateway($posGateway, $refundType, $refundParams);
                $refOk = !empty($refundResult['success']);
                $result['refund'] = $refundResult;
                if ($captureAmount <= 0) {
                    $result = array_merge($refundResult, ['refund' => $refundResult]);
                    $result['message'] = $refOk
                        ? ($refundType === 'avoid' ? 'HOLD RELEASED' : 'REFUND')
                        : ($refundResult['message'] ?? 'Refund failed');
                } elseif ($capOk && $refOk) {
                    $result['message'] = 'CAPTURED + REFUND';
                }
            }
            if ($captureAmount > 0 && !$capOk) {
                $result['success'] = false;
            } elseif ($captureAmount <= 0 && $refundAmount > 0) {
                $result['success'] = $refOk;
            } elseif ($captureAmount > 0 && $refundAmount > 0) {
                $result['success'] = $capOk && $refOk;
            }
        } else {
            // Gateway only — no order/accounting layer, no simulated approval
            $result = pos_run_payment_orchestrator($posGateway, $runType, $params);
        }

        if (!empty($result['order_persisted']) && !empty($result['order_id'])) {
            $orderPersistedByOrchestrator = true;
            $orchestratorOrderId = (int) $result['order_id'];
        }
        if (!empty($result['reference'])) {
            $reference = (string) $result['reference'];
            $params['reference'] = $reference;
        }

        $success = !empty($result['success']);
        $message = $success
            ? 'APPROVED'
            : pos_plain_host_message($result['raw'] ?? $result);
        $responseCode = trim((string) (
            $result['response_code']
            ?? $result['errCode']
            ?? $result['gwErrorCode']
            ?? (is_array($result['raw'] ?? null) ? ($result['raw']['gwErrorCode'] ?? $result['raw']['errCode'] ?? '') : '')
            ?? ''
        ));
        if ($responseCode === '' && preg_match('/\b(\d{4})\b/', $message, $mRc)) {
            $responseCode = $mRc[1];
        }
        $approvalCode = $result['approval_code'] ?? '';
        $rrn = $result['rrn'] ?? '';
        if (in_array($txnType, ['capture'], true) && $rrn === '') {
            $rrn = $originalRrn;
        }
        $stan = $result['stan'] ?? '';
        $nuveiTxnId = $result['nuvei_txn_id'] ?? null;
        $requires3ds = !empty($result['requires_3ds']);
        $redirectUrl = $result['redirect_url'] ?? null;
        $gatewayResponse = $result;
        if ($requires3ds) {
            $success = false;
            $message = '3DS_REQUIRED';
        }

    } catch (Exception $e) {
        error_log('[POS][Nuvei] Exception: ' . $e->getMessage());
        http_response_code(500);
        $gwErr = pos_plain_host_message($e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => $gwErr,
            'status_message' => $gwErr,
            'decline_reason' => $gwErr,
        ]);
        exit;
    }
}

// ============================================================
// 9. معالجة Balance / Settlement
// ============================================================

if ($txnType === 'balance') {
    if (!pos_gateway_requires_card($posGateway)) {
        $success = false;
        $message = 'No bank balance. DIPARMA GATEWAY is Ledger USDT TRC20 only.';
        $gatewayResponse = ['type' => 'balance', 'rail' => 'ledger', 'no_bank' => true];
    } else {
        try {
            require_once POS_APP_ROOT . '/lib/Adapters/NuveiAdapter.php';
            $nuvei = new NuveiAdapter();
            $result = $nuvei->balanceInquiry([]);
            $success = !empty($result['success']);
            $message = $success ? 'BALANCE_INQUIRY_OK' : pos_plain_host_message($result['message'] ?? 'BALANCE_INQUIRY_FAILED');
            $gatewayResponse = $result;
        } catch (Exception $e) {
            $success = false;
            $message = pos_plain_host_message($e->getMessage());
        }
    }
}

if ($txnType === 'settlement') {
    $success = false;
    $message = pos_gateway_requires_card($posGateway)
        ? 'EOD settlement is not a POS charge. After each approved sale, net USDT is sent to Ledger.'
        : 'DIPARMA GATEWAY settles each transfer to Ledger immediately. No bank EOD.';
    $gatewayResponse = ['type' => 'settlement', 'settlement_target' => 'ledger', 'no_bank' => true];
}

$displayOperation = in_array($txnType, [
    'purchase_2d', 'purchase_3d', 'online_sale_moto', 'offline_sale_moto',
    'purchase_advice', 'capture', 'withdrawal_pos', 'withdrawal_nfc',
], true) ? 'SALE' : strtoupper(str_replace('_', ' ', $txnType));

if ($txnType === 'auth') {
    $displayOperation = 'AUTH';
} elseif ($txnType === 'refund') {
    $displayOperation = 'REFUND';
} elseif ($txnType === 'avoid') {
    $displayOperation = 'AVOID';
} elseif (pos_is_withdrawal($txnType)) {
    $displayOperation = 'CASH ADVANCE';
}

$gatewayDetails = [
    'operation_name' => $displayOperation,
    'transaction_type' => $txnType,
    'card_type' => $cardType,
    'transaction_id' => $nuveiTxnId,
    'auth_code' => $approvalCode,
    'rrn' => $rrn,
    'stan' => $stan,
    'original_rrn' => $originalRrn,
    'status' => $message,
    'success' => $success,
    'response' => $gatewayResponse,
];

// ============================================================
// 10. حفظ في قاعدة البيانات
// ============================================================

$saved = false;
$transactionId = null;
try {
    $txnPayload = [
        'reference' => $reference,
        'user_id' => $userId > 0 ? $userId : null,
        'gateway' => $posGateway,
        'gateway_type' => pos_gateway_requires_card($posGateway) ? 'card' : 'crypto',
        'transaction_type' => $txnType,
        'transaction_label' => $displayOperation,
        'amount' => $amount,
        'currency' => $currency,
        'card_last4' => substr($cardNumber, -4),
        'security_mode' => $secMode,
        'status' => $requires3ds ? 'pending' : ($success ? ($txnType === 'auth' ? 'authorized' : 'completed') : 'failed'),
        'gateway_response' => json_encode([
            'channel' => $entryChannel,
            'orchestrator' => !empty($result['orchestrator']) ? $result['orchestrator'] : null,
            'settlement_path' => $posGateway . '_to_ledger',
            'settlement_target' => 'ledger',
            'status_message' => $message,
            'rrn' => $rrn,
            'stan' => $stan,
            'original_rrn' => $originalRrn,
            'bank_approval_code' => $bankApprovalCode,
            'approval_code' => $approvalCode,
            'nuvei_txn_id' => $nuveiTxnId,
            'payram_ref' => $posGateway === 'payram' ? ($rrn ?: ($gatewayResponse['reference_id'] ?? null)) : null,
            'whop_payment_id' => $posGateway === 'whop' ? ($rrn ?: ($gatewayResponse['payment_id'] ?? null)) : null,
            'type' => $txnType,
            'card_type' => $cardType,
            'card_network' => $cardNetwork,
            'payment_method' => $cardType === 'CLOUD' ? 'cloud_token' : 'card',
            'pos_device' => $posDevice,
            'pos_model' => $posModel,
            'pos_type' => $posType,
            'terminal_id' => $terminalId,
            'acquirer' => $posGateway,
            'merchant' => 'TRANSCENDIO FZ-LLC',
            'ledger_address' => $ledgerAddr,
            'auto_transfer' => $autoTransfer,
            'requires_3ds' => $requires3ds,
            'redirect_url' => $redirectUrl,
            'extra' => $extra,
            'charge_mode' => $chargeMode ?: null,
            'capture_amount' => $txnType === 'capture' ? $captureAmount : null,
            'refund_amount' => $txnType === 'capture' ? $refundAmount : null,
            'linked_to_auth' => ($txnType === 'capture'),
            'advice_independent' => ($txnType === 'purchase_advice'),
            'entry_mode' => $extra['entry_mode'] ?? ($opMeta['entry_mode'] ?? null),
            'scheme_route' => $cardNetwork,
            'authorized_amount' => $authorizedAmount,
            'amount_vs_auth' => $authorizedAmount !== null
                ? ($amount == $authorizedAmount ? 'equal' : ($amount < $authorizedAmount ? 'less' : 'more'))
                : null,
            'operation_name' => $displayOperation,
            'display_type' => $displayOperation,
            'gateway_details' => $gatewayDetails,
            'raw' => $gatewayResponse,
        ]),
        'ledger_address' => $ledgerAddr,
        'auth_code' => $approvalCode,
        'rrn' => $rrn,
        'stan' => $stan,
        'bank_approval_code' => $bankApprovalCode,
        'acquirer' => $posGateway,
        'notes' => $entryChannel . '_order',
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    if ($orderPersistedByOrchestrator && $orchestratorOrderId) {
        $transactionId = $orchestratorOrderId;
        $db->update('transactions', $txnPayload, ['id' => $transactionId]);
        $saved = true;
    } else {
        $txnPayload['created_at'] = date('Y-m-d H:i:s');
        $transactionId = $db->insertAvailable('transactions', $txnPayload);
        $saved = !empty($transactionId);
    }
} catch (Throwable $e) {
    error_log('[POS][DB] ' . $e->getMessage());
}

// ============================================================
// 11. تسوية فورية: خصم نسبة البوابة → صافي USDT → Ledger
// ============================================================

$ledgerTransfer = false;
$ledgerTxid = null;
$ledgerStatus = 'pending';
$ledgerFee = null;
$ledgerNet = null;
$ledgerUsdt = null;
$ledgerTransferTypes = [
    'purchase_2d', 'purchase_3d', 'online_sale_moto', 'offline_sale_moto',
    'purchase_advice', 'capture', 'withdrawal_pos', 'withdrawal_nfc',
];
$feeGateway = $posGateway;
$alreadyLedger = (($gwMeta['adapter'] ?? '') === 'ledger')
    && $posGateway !== 'diparma_gateway'
    && !empty($gatewayResponse['no_bank']);

if ($success && $alreadyLedger) {
    $rawSettle = is_array($gatewayResponse['raw'] ?? null) ? $gatewayResponse['raw'] : $gatewayResponse;
    $ledgerTxid = $gatewayResponse['txid'] ?? $rawSettle['txid'] ?? $rawSettle['tx_hash'] ?? null;
    $ledgerFee = $rawSettle['fee'] ?? null;
    $ledgerNet = $rawSettle['net_fiat'] ?? null;
    $ledgerUsdt = $rawSettle['usdt_amount'] ?? null;
    $ledgerTransfer = $ledgerTxid !== null && $ledgerTxid !== '';
    $ledgerStatus = !empty($gatewayResponse['queued']) || !empty($rawSettle['queued'])
        ? 'queued'
        : ($ledgerTransfer ? 'completed' : ($success ? 'completed' : 'failed'));
} elseif ($success && !$requires3ds && $arrival === 'payout' && in_array($txnType, $ledgerTransferTypes, true)
    && ($txnType !== 'capture' || $captureAmount > 0)) {
    $ledgerTransfer = false;
    $ledgerStatus = 'queued';
    $ledgerTxid = null;
} elseif ($success && !$requires3ds && $autoTransfer && in_array($txnType, $ledgerTransferTypes, true)
    && ($txnType !== 'capture' || $captureAmount > 0)) {
    try {
        require_once POS_APP_ROOT . '/lib/LedgerSettlementService.php';
        $transferResult = LedgerSettlementService::getInstance()->settleToLedger([
            'reference'      => $reference,
            'amount'         => $amount,
            'currency'       => $currency,
            'gateway'        => $feeGateway,
            'ledger_address' => $ledgerAddr,
            'user_id'        => $userId,
            'txn_type'       => $txnType,
            'transaction_id' => !empty($transactionId) ? (int) $transactionId : null,
            'destination'    => 'ledger',
        ]);
        $ledgerTransfer = !empty($transferResult['success']) && empty($transferResult['skipped']);
        $ledgerTxid = $transferResult['txid'] ?? null;
        $ledgerFee = $transferResult['fee'] ?? null;
        $ledgerNet = $transferResult['net_fiat'] ?? null;
        $ledgerUsdt = $transferResult['usdt_amount'] ?? null;
        if (!empty($transferResult['skipped'])) {
            $ledgerStatus = 'skipped';
        } elseif ($ledgerTransfer) {
            $ledgerStatus = 'completed';
        } elseif (!empty($transferResult['queued'])) {
            $ledgerStatus = 'queued';
        } else {
            $ledgerStatus = 'failed';
        }
    } catch (Exception $e) {
        error_log('[POS][Ledger] ' . $e->getMessage());
        $ledgerStatus = 'failed';
    }
}

// مزامنة السحب مع العقدة الأخرى (محلي ↔ بعيد)
$peerSync = null;
if ($success && pos_is_withdrawal($txnType) && empty($data['_peer_mirror'])) {
    try {
        if (!function_exists('peer_request')) {
            require_once POS_APP_ROOT . '/includes/peer_link.php';
        }
        $peerSync = peer_request('sync_txn', [
            'reference'        => $reference,
            'gateway'          => $posGateway,
            'amount'           => $amount,
            'currency'         => $currency,
            'status'           => 'completed',
            'txn_type'         => $txnType,
            'gateway_response' => $gatewayDetails,
        ], 8);
        $withdrawOn = strtolower(trim((string)($data['withdraw_on'] ?? 'local')));
        if (in_array($withdrawOn, ['both', 'peer', 'remote'], true)) {
            $peerSync['withdraw'] = peer_request('withdraw', [
                'payload' => array_merge($data, [
                    '_peer_mirror' => 1,
                    'txn_type'     => $txnType,
                    'amount'       => $amount,
                    'currency'     => $currency,
                ]),
            ], 40);
        }
    } catch (Throwable $e) {
        $peerSync = ['success' => false, 'message' => $e->getMessage()];
    }
}

// ============================================================
// 12. الاستجابة النهائية
// ============================================================

http_response_code(200);
$message = pos_plain_host_message($message);
echo json_encode([
    'success' => $success,
    'reference' => $reference,
    'rrn' => $rrn,
    'stan' => $stan,
    'original_rrn' => $originalRrn,
    'approval_code' => $approvalCode,
    'bank_approval_code' => $bankApprovalCode,
    'nuvei_txn_id' => $nuveiTxnId,
    'txn_type' => $txnType,
    'operation_name' => $displayOperation,
    'transaction_label' => $displayOperation,
    'gateway_details' => $gatewayDetails,
    'amount' => $amount,
    'currency' => $currency,
    'response_code' => $responseCode,
    'card_last4' => $cardNumber !== '' ? substr($cardNumber, -4) : '',
    'status_message' => $message,
    'message' => $message,
    'decline_reason' => $success ? null : $message,
    'pos_device' => $posDevice,
    'pos_model' => $posModel,
    'pos_type' => $posType,
    'terminal_id' => $terminalId,
    'acquirer' => $posGateway,
    'merchant' => 'TRANSCENDIO FZ-LLC',
    'settlement_path' => $posGateway . '_to_ledger',
    'settlement_target' => 'ledger',
    'destination' => 'ledger',
    'peer_sync' => $peerSync,
    'ledger_transfer' => $ledgerTransfer,
    'ledger_txid' => $ledgerTxid,
    'ledger_address' => $ledgerAddr,
    'ledger_status' => $ledgerStatus,
    'gateway_fee' => $ledgerFee,
    'net_amount' => $ledgerNet,
    'ledger_usdt' => $ledgerUsdt,
    'requires_3ds' => $requires3ds,
    'redirect_url' => $redirectUrl,
    'saved' => $saved,
    'orchestrator' => $result['orchestrator'] ?? null,
    'channel' => $entryChannel,
    'order_id' => $transactionId,
    'timestamp' => date('c'),
], JSON_UNESCAPED_UNICODE);
