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
 *   POST /api/checkout_charge.php
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

ob_start(static function (string $buffer): string {
    return (string) preg_replace('/^\xEF\xBB\xBF+/', '', $buffer);
});

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
if ((!function_exists('square_request_is_diparma_offline') || !function_exists('paypal_request_is_diparma_offline')) && is_file(POS_APP_ROOT . '/includes/gateways.php')) {
    require_once POS_APP_ROOT . '/includes/gateways.php';
}
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
if ($requestedGateway === 'diparma' && function_exists('dp_diparma_iso_host') && dp_diparma_iso_host() === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'DI PARMA ISO host is not configured. The charge was not sent.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
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
$cardCVV = trim((string)($data['card_cvv'] ?? $data['cvv'] ?? $data['cvv2'] ?? $data['cvc'] ?? $data['cvc2'] ?? $data['cc_cvv'] ?? ''));
$cardType = strtoupper(trim((string)($data['card_type'] ?? $data['card_type_selected'] ?? 'LIVE')));
$cardNetwork = pos_normalize_card_network((string)($data['card_network'] ?? $data['card_scheme'] ?? $extraEarly['card_network'] ?? 'auto'));
$cloudToken = trim((string)($data['cloud_token'] ?? $data['payment_token'] ?? $data['source_id'] ?? ''));
$sourceId = trim((string)($data['source_id'] ?? $cloudToken));
$typedBankRrn = (string) ($data['bank_rrn'] ?? $extraEarly['bank_rrn'] ?? '');
$typedRrn = (string) ($data['rrn'] ?? '');
$typedOrig = (string) ($data['orig_ref'] ?? $data['refund_reference'] ?? '');
$bankRrn = pos_first_rrn([$typedBankRrn, $typedRrn, $typedOrig]);
$origRef = $bankRrn;
$typedBankNorm = pos_normalize_rrn($typedBankRrn);
if (pos_is_valid_rrn($typedBankNorm)) {
    $bankRrn = $typedBankNorm;
    $origRef = $typedBankNorm;
}
$paymentId = trim((string)($data['payment_id'] ?? $data['nuvei_txn_id'] ?? $data['transaction_id'] ?? $extraEarly['payment_id'] ?? ''));
$gatewayApprovalCode = trim((string)($data['gateway_approval_code'] ?? $data['auth_code'] ?? $extraEarly['gateway_approval_code'] ?? ''));
$ledgerAddr = trim((string) LEDGER_TRC20_ADDRESS);
$hotWalletAddr = trim($data['hot_wallet_address'] ?? HOT_WALLET_TRC20_ADDRESS);
$autoTransfer = true;
$legacyLedgerCheckout = !empty($data['ledger_checkout']);
$extra = is_array($data['extra'] ?? null) ? $data['extra'] : [];
if (!function_exists('pos_merchant_lines')) {
    require_once POS_APP_ROOT . '/lib/merchant.php';
}
$activityLine = strtolower(trim((string) ($data['activity_line'] ?? $data['line'] ?? $data['merchant_line'] ?? $extra['activity_line'] ?? '')));
if (!isset(pos_merchant_lines()[$activityLine])) {
    $activityLine = '';
}
$extra['activity_line'] = $activityLine;
$targetRaw = (string) ($data['settlement_target'] ?? $data['destination'] ?? $extra['settlement_target'] ?? '');
if ($targetRaw === '' && $legacyLedgerCheckout) {
    $targetRaw = 'ledger';
}
if ($targetRaw === '') {
    $targetRaw = 'gateway';
}
$targetChoices = function_exists('activity_settlement_target_choices')
    ? activity_settlement_target_choices($posGateway, 'checkout')
    : ['gateway' => ['type' => 'same'], 'ledger' => ['type' => 'ledger']];
$settlementTarget = function_exists('activity_normalize_settlement_target')
    ? activity_normalize_settlement_target($targetRaw, $posGateway, $targetChoices)
    : (in_array(strtolower(trim($targetRaw)), ['gateway', 'ledger', 'ledger_trx'], true) ? strtolower(trim($targetRaw)) : '');
if ($settlementTarget === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Settlement destination is invalid or not connected.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$destination = $settlementTarget;
$settlementPath = $posGateway . '_to_' . $settlementTarget;
$ledgerCheckout = $settlementTarget === 'ledger';
$autoTransfer = $settlementTarget !== 'gateway';
$extra['settlement_target'] = $settlementTarget;
$arrival = function_exists('activity_normalize_arrival')
    ? activity_normalize_arrival((string) ($data['arrival'] ?? $extra['arrival'] ?? 'gateway'))
    : 'gateway';
$payoutVia = str_starts_with($settlementTarget, 'gateway:')
    ? substr($settlementTarget, strlen('gateway:'))
    : '';
$extra['arrival'] = $arrival;
$extra['payout_via'] = $payoutVia;
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
    } elseif ($posType === 'web' || $posType === 'desktop_browser' || stripos((string) $posDevice, 'web') !== false) {
        $entryChannel = 'web';
    } else {
        $entryChannel = 'pos';
    }
}
$isWebCharge = in_array($entryChannel, ['checkout', 'link', 'web'], true);
if ($isWebCharge && ($posModel === '' || empty($resolvedDevice['accepted']))) {
    $resolvedDevice = pos_device_resolve([
        'pos_model' => 'web_pos',
        'pos_device' => 'web_pos',
        'terminal_id' => (string) ($resolvedDevice['terminal_id'] ?? ''),
    ]);
    $posDevice = $resolvedDevice['code'];
    $posModel = $resolvedDevice['model'];
    $posType = $resolvedDevice['type'];
}
$cardNetwork = pos_normalize_card_network((string)($data['card_network'] ?? $data['card_scheme'] ?? ($extra['card_network'] ?? $cardNetwork ?? 'auto')));
if ($cardNetwork === 'auto' && $cardNumber !== '') {
    $cardNetwork = pos_detect_card_network($cardNumber);
}
$userId = intval($_SESSION['user_id'] ?? 0);

if ($userId > 0) {
    $burst = pos_txn_burst_guard($userId);
    if ($burst) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => $burst]);
        exit;
    }
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

$paymentLinkId = trim((string) ($data['link_id'] ?? $extra['link_id'] ?? $extraEarly['link_id'] ?? ''));
if ($paymentLinkId !== '') {
    if (!function_exists('isLinkValid')) {
        require_once dirname(__DIR__, 2) . '/includes/functions.php';
    }
    $paymentLink = db()->find('payment_links', ['link_id' => $paymentLinkId]);
    if (!$paymentLink) {
        $paymentLink = db()->find('payment_links', ['slug' => $paymentLinkId]);
    }
    if (!$paymentLink || !isLinkValid($paymentLink)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Payment link is missing or expired.']);
        exit;
    }
    $storedAmount = number_format((float) ($paymentLink['amount'] ?? 0), 2, '.', '');
    $requestAmount = number_format((float) ($data['amount'] ?? 0), 2, '.', '');
    $storedCurrency = strtoupper(trim((string) ($paymentLink['currency'] ?? '')));
    $requestCurrency = strtoupper(trim((string) ($data['currency'] ?? '')));
    if ($requestAmount !== $storedAmount || $requestCurrency !== $storedCurrency) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Amount or currency does not match the payment link.']);
        exit;
    }
    $amount = (float) $storedAmount;
    $currency = $storedCurrency;
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
$inputMode = strtolower(trim((string) ($extra['input_mode'] ?? $data['input_mode'] ?? '')));
$entryMode = strtolower(trim((string) ($extra['entry_mode'] ?? $data['entry_mode'] ?? '')));
$manualKeyed = $inputMode === 'manual' || $entryMode === 'keyed';
$manualOffline = $manualKeyed && pos_keyed_entry_forces_offline($txnType);
if ($manualOffline) {
    $extra['is_offline'] = true;
    $extra['input_mode'] = 'manual';
    $extra['entry_mode'] = 'keyed';
    $extra['processing_mode'] = 'OFFLINE';
    $extra['sec_mode'] = 'OFFLINE';
    $extra['moto_channel'] = 'offline';
    $extra['auth_channel'] = 'offline';
    if ($txnType === 'purchase_3d') {
        $txnType = 'purchase_2d';
        $opMeta = pos_operation_meta($txnType) ?? $opMeta;
    }
}
if ($txnType === 'offline_sale_moto' || $manualOffline) {
    $bankRrn = '';
    $origRef = '';
    $manualRRN = '';
    unset($extra['manual_rrn']);
    if ($txnType !== 'offline_sale_moto') {
        $bankApprovalCode = '';
        $gatewayApprovalCode = '';
        $manualApproval = '';
        $paymentId = '';
        unset(
            $extra['approval_code'],
            $extra['bank_approval_code'],
            $extra['gateway_approval_code'],
            $extra['auth_code'],
            $extra['internal_approval_code'],
            $extra['payment_id']
        );
    }
}
if ($paymentId === '') {
    $paymentId = trim((string)($extra['payment_id'] ?? $extra['nuvei_txn_id'] ?? ''));
}
if ($bankRrn !== '' && pos_is_valid_rrn($bankRrn)) {
    $origRef = $bankRrn;
}
$captureHoldLinked = false;
$holdId = (int) ($data['hold_id'] ?? $extraEarly['hold_id'] ?? 0);
if ($txnType === 'capture' && $holdId > 0) {
    try {
        $holdDb = db();
        $pfx = defined('DB_PREFIX') ? DB_PREFIX : 'dp_';
        $holdTypes = pos_open_hold_type_list();
        $holdStatuses = pos_open_hold_status_list();
        $typeSql = implode(',', array_fill(0, count($holdTypes), '?'));
        $statusSql = implode(',', array_fill(0, count($holdStatuses), '?'));
        $holdRows = $holdDb->query(
            "SELECT id, reference, rrn, auth_code, bank_approval_code, gateway, gateway_response
             FROM {$pfx}transactions
             WHERE id = ? AND user_id = ?
               AND (
                 LOWER(TRIM(COALESCE(transaction_type,''))) IN ({$typeSql})
                 OR (
                   LOWER(TRIM(COALESCE(gateway,''))) = 'paypal'
                   AND LOWER(COALESCE(transaction_type,'')) LIKE '%authoriz%'
                 )
               )
               AND LOWER(TRIM(COALESCE(status,''))) IN ({$statusSql})
             LIMIT 1",
            array_merge([$holdId, $userId], $holdTypes, $holdStatuses)
        ) ?: [];
        if (!empty($holdRows[0])) {
            $captureHoldLinked = true;
            $holdRow = $holdRows[0];
            $holdRrn = pos_normalize_rrn((string) ($holdRow['rrn'] ?? ''));
            if (!pos_is_valid_rrn($bankRrn) && pos_is_valid_rrn($holdRrn)) {
                $bankRrn = $holdRrn;
                $origRef = $holdRrn;
            }
            if ($paymentId === '') {
                $rawHold = [];
                if (!empty($holdRow['gateway_response'])) {
                    $decodedHold = json_decode((string) $holdRow['gateway_response'], true);
                    $rawHold = is_array($decodedHold) ? $decodedHold : [];
                }
                $paymentId = pos_hold_payment_id($rawHold, $holdRow);
            }
            if ($bankApprovalCode === '' && !empty($holdRow['bank_approval_code'])) {
                $bankApprovalCode = trim((string) $holdRow['bank_approval_code']);
            }
            if ($bankApprovalCode === '' && !empty($holdRow['auth_code'])) {
                $bankApprovalCode = trim((string) $holdRow['auth_code']);
            }
            $holdApprovalStored = trim((string) ($holdRow['bank_approval_code'] ?? ''));
            if ($holdApprovalStored === '') {
                $holdApprovalStored = trim((string) ($holdRow['auth_code'] ?? ''));
            }
            $bound = pos_bind_capture_pair([
                'rrn' => (string) ($holdRow['rrn'] ?? ''),
                'approval_code' => $holdApprovalStored,
                'auth_code' => (string) ($holdRow['auth_code'] ?? ''),
                'bank_approval_code' => (string) ($holdRow['bank_approval_code'] ?? ''),
            ], $origRef, $bankApprovalCode);
            if (!$bound['ok']) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $bound['message']], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if ($bound['rrn'] !== '') {
                $bankRrn = $bound['rrn'];
                $origRef = $bound['rrn'];
            }
            if ($bound['approval'] !== '') {
                $bankApprovalCode = $bound['approval'];
                $gatewayApprovalCode = $bound['approval'];
            }
        }
    } catch (Throwable $e) {
        $holdId = 0;
    }
}
$manualNotes = trim((string)($extra['notes'] ?? ''));
$terminalId = $resolvedDevice['terminal_id'] ?? pos_normalize_terminal_id((string)($extra['terminal_id'] ?? $data['tid'] ?? ''));
if ($terminalId === '' && function_exists('pos_session_terminal_id')) {
    $terminalId = pos_session_terminal_id((string) ($resolvedDevice['model'] ?? $posModel));
    if ($terminalId !== '') {
        $resolvedDevice['terminal_id'] = $terminalId;
        $extra['terminal_id'] = $terminalId;
    }
}
if ($isWebCharge && $terminalId === '' && $posGateway !== 'nuvei') {
    $terminalId = 'WEB' . strtoupper(bin2hex(random_bytes(4)));
    $resolvedDevice['terminal_id'] = $terminalId;
}
if ($terminalId === '' && $posGateway !== 'nuvei' && function_exists('pos_default_terminal_id')) {
    $terminalId = pos_default_terminal_id();
    if ($terminalId !== '') {
        $resolvedDevice['terminal_id'] = $terminalId;
        $extra['terminal_id'] = $terminalId;
    }
}
$merchantId = trim((string)($extra['merchant_id'] ?? $resolvedDevice['merchant_id'] ?? 'DIPARMA'));
if ($merchantId === '' || strcasecmp(str_replace(' ', '', $merchantId), 'DIPARMA') === 0) {
    $merchantId = 'DIPARMA';
}
$posLocation = trim((string)($extra['pos_location'] ?? ''));
$extra['merchant_id'] = $merchantId;
$extra['pos_location'] = $posLocation;
$extra['notes'] = $manualNotes;
$secMode = '2D';
if (!empty($extra['is_offline']) || strtoupper((string) ($extra['processing_mode'] ?? '')) === 'OFFLINE') {
    $secMode = 'OFFLINE';
} elseif ($cardType === 'CLOUD') {
    $secMode = '2D';
} elseif (in_array($txnType, ['withdrawal_pos', 'withdrawal_nfc'], true)) {
    $secMode = '2D';
} elseif ($txnType === 'purchase_3d' || strtoupper($opMeta['security'] ?? '') === '3D') {
    $secMode = '3D';
} else {
    $secMode = strtoupper($extra['sec_mode'] ?? $extra['processing_mode'] ?? ($opMeta['security'] ?? '2D'));
}
if ($secMode === '3D' && $cardType !== 'CLOUD') {
    require_once POS_APP_ROOT . '/lib/CardScaService.php';
    if (CardScaService::hasCompleted3ds($cardNumber, $cardExpiry, substr($cardNumber, -4))) {
        $secMode = '2D';
        if ($txnType === 'purchase_3d') {
            $txnType = 'purchase_2d';
        }
        $data['processing_mode'] = '2D';
        $data['security_mode'] = '2D';
        $extra['otp_policy'] = 'subsequent_skip';
    } else {
        $extra['otp_policy'] = 'first_challenge';
    }
}

// توليد مرجع فريد
$reference = $data['reference'] ?? 'POS-' . strtoupper(substr($txnType, 0, 4)) . '-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));

// ============================================================
// 6. التحقق من صحة البيانات
// ============================================================

$errors = [];

// Capture / advice / offline keep a signed amount. Other types stay greater than zero.
$policyAmount = $amount;
if ($txnType === 'capture') {
    $capturePeekEarly = floatval($data['capture_amount'] ?? $extra['capture_amount'] ?? 0);
    if (abs($capturePeekEarly) >= 0.01) {
        $policyAmount = $capturePeekEarly;
    }
}
foreach (pos_amount_policy_errors($txnType, $policyAmount) as $policyError) {
    $errors[] = $policyError;
}
if (($txnType === 'offline_sale_moto' || !empty($manualOffline)) && function_exists('gateway_offline_daily_limit_usd')) {
    $dailyLimit = gateway_offline_daily_limit_usd($posGateway);
    if ($dailyLimit !== null) {
        try {
            $todayStart = date('Y-m-d 00:00:00');
            $dailyRows = db()->query(
                "SELECT COALESCE(SUM(amount), 0) AS total FROM " . DB_PREFIX . "transactions
                 WHERE gateway = ? AND transaction_type = ? AND created_at >= ?
                   AND LOWER(status) IN ('completed', 'captured', 'approved', 'authorized', 'settled', 'pending')",
                [$posGateway, $txnType, $todayStart]
            );
            $dailyTotal = (float) ($dailyRows[0]['total'] ?? 0);
            if ($dailyTotal + abs($amount) > $dailyLimit) {
                $errors[] = 'Offline daily limit exceeded for ' . strtoupper($posGateway) . ': ' . number_format($dailyLimit, 2, '.', ',') . ' USD.';
            }
        } catch (Throwable $e) {
            $errors[] = 'Offline daily limit could not be verified. Transaction blocked.';
        }
    }
}
// Bank ceilings for advice, capture, and offline are inside pos_amount_policy_errors.
// Keyed sales that are forced offline still use the offline absolute ceiling.
if (!empty($manualOffline) && $txnType !== 'offline_sale_moto') {
    $offlineCap = function_exists('pos_offline_sale_max_amount') ? pos_offline_sale_max_amount() : 2000000.00;
    if (abs($amount) > $offlineCap) {
        $errors[] = 'Offline bank limit: amount cannot exceed ' . number_format($offlineCap, 2, '.', ',') . '.';
    }
}
if ($posGateway === 'square' && function_exists('gateway_max_per_txn_usd')) {
    $squareCap = gateway_max_per_txn_usd('square');
    if ($squareCap !== null) {
        $squareCheck = $amount;
        if ($txnType === 'capture') {
            $capturePeek = floatval($data['capture_amount'] ?? $extra['capture_amount'] ?? 0);
            $squareCheck = $capturePeek > 0 ? $capturePeek : $amount;
        }
        if (in_array($txnType, ['capture', 'purchase_advice', 'offline_sale_moto'], true)) {
            $squareCheck = abs($squareCheck);
        }
        if (in_array($currency, ['USD', 'USDT', 'USDC'], true) && $squareCheck > $squareCap) {
            $errors[] = 'Square limit: amount cannot exceed ' . number_format($squareCap, 2, '.', ',') . ' USD per transaction (offline included).';
        }
    }
}
if ($cardNumber !== '' && pos_is_blocked_test_card($cardNumber)) {
    $errors[] = 'Test and dummy cards are blocked. Use a real card.';
}
if ($cardRail && !in_array($txnType, ['refund', 'avoid', 'capture'], true) && $cardType !== 'CLOUD') {
    $realName = function_exists('pos_real_card_name') ? pos_real_card_name($cardName) : trim($cardName);
    if ($realName === '' && $requiresCard && !($txnType === 'purchase_advice' && $isPhysicalEntry)) {
        $errors[] = 'Enter the name on the card. Placeholder names are rejected.';
    } elseif ($realName !== '') {
        $cardName = $realName;
    }
}
if (!$isWebCharge) {
    if ($terminalId === '' && strtolower((string) $posGateway) !== 'paypal') {
        $errors[] = 'Terminal ID (TID) is required.';
    }
    if (empty($resolvedDevice['model']) || empty($resolvedDevice['accepted'])) {
        $errors[] = 'Select a real POS model from the catalog.';
    }
}

if (!preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $ledgerAddr)) {
    $errors[] = 'LEDGER_TRC20_ADDRESS is required. POS settles gateway net to Ledger only.';
}

if (!empty($ledgerAddr) && !empty($hotWalletAddr) && strcasecmp($ledgerAddr, $hotWalletAddr) === 0) {
    $errors[] = 'Ledger address must be different from the Hot Wallet address.';
}

$requiresCard = !empty($opMeta['requires_card']);
$requiresCvv  = false;
$requiresOrig = !empty($opMeta['requires_rrn']) || !empty($opMeta['requires_orig']);
$requiresApproval = !empty($opMeta['requires_approval']);
$chargeMode = trim((string)($data['charge_mode'] ?? $extra['charge_mode'] ?? $data['withdrawal_mode'] ?? ''));
$adviceChannel = strtolower(trim((string)($extra['advice_channel'] ?? $data['advice_channel'] ?? '')));

$hasSquareSource = $sourceId !== '' && !pos_is_simulation_card_nonce($sourceId);
$hasRealCloudToken = pos_is_real_cloud_token($cloudToken) || pos_is_real_cloud_token($sourceId) || $hasSquareSource;
if (pos_is_simulation_card_nonce($cloudToken) || pos_is_simulation_card_nonce($sourceId)) {
    $errors[] = 'Square simulation nonce is rejected. Use a real Web Payments SDK token.';
} elseif ($hasRealCloudToken) {
    $cardType = 'CLOUD';
    $requiresCard = false;
    $requiresCvv = false;
}

// لـ purchase_advice: طول Approval حسب القناة (online=4 / offline=6)
if ($txnType === 'purchase_advice' && $adviceChannel !== '') {
    // يُحقَّق أدناه عبر تعديل مؤقت لطول الموافقة
}

if ($cardType === 'CLOUD' && $cardRail && !$hasRealCloudToken && !in_array($txnType, ['refund', 'avoid'], true)) {
    $errors[] = 'CLOUD token is required. Use a real gateway token (Nuvei UPO, Stripe pm_/tok_, PayPal vault).';
}

// قواعد الحقول المعيارية (RRN 12 / Approval 4|6 / بطاقة / انتهاء)
$fieldPayload = [
    'rrn' => $origRef !== '' ? $origRef : ($data['rrn'] ?? $manualRRN ?? ''),
    'orig_ref' => $origRef,
    'bank_rrn' => $bankRrn,
    'hold_linked' => !empty($captureHoldLinked),
    'approval_code' => $bankApprovalCode,
    'bank_approval_code' => $bankApprovalCode,
    'payment_id' => $paymentId,
    'nuvei_txn_id' => $paymentId,
    'transaction_id' => $paymentId,
    'gateway_approval_code' => $gatewayApprovalCode,
    'auth_code' => $gatewayApprovalCode,
    'card_number' => $cardNumber,
    'card_expiry' => $cardExpiry,
    'cloud_token' => $cloudToken,
    'source_id' => $sourceId,
    'payment_token' => $cloudToken,
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
if ($txnType === 'purchase_advice'
    && ($cardType === 'CLOUD' || strlen($cardNumber) < 13 || $cardExpiry === '')) {
    $fieldErrors[] = 'Purchase Advice requires a real PAN and expiry from manual entry or a physical swipe.';
}
if (!$cardRail && in_array($txnType, ['refund', 'avoid'], true) && pos_normalize_rrn($origRef) === '' && trim($origRef) === '') {
    $fieldErrors[] = 'Original reference is required for refund/avoid.';
}

$errors = array_merge($errors, $fieldErrors);
if ($txnType === 'purchase_advice') {
    $originalTransactionDate = trim((string) (
        $extra['original_transaction_date']
        ?? $data['original_transaction_date']
        ?? ''
    ));
    $dateParts = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $originalTransactionDate, $dateMatch)
        ? array_map('intval', array_slice($dateMatch, 1))
        : [];
    if (count($dateParts) !== 3 || !checkdate($dateParts[1], $dateParts[2], $dateParts[0])) {
        $errors[] = 'Original transaction date must be a valid YYYY-MM-DD date.';
    } else {
        $extra['original_transaction_date'] = $originalTransactionDate;
    }
}

// CVV فقط عندما تطلبه العملية على سكة البطاقة (وليس CLOUD)
if ($cardRail && $cardType !== 'CLOUD' && $requiresCvv && (empty($cardCVV) || strlen($cardCVV) < 3)) {
    // لا تكرر خطأ البطاقة إن فشل التحقق العام
    if (!in_array('Card number must be 13–19 digits.', $errors, true)) {
        $errors[] = 'Invalid CVV. Must be 3-4 digits.';
    }
}

if (!empty($errors)) {
    http_response_code(422);
    $uniq = array_values(array_unique($errors));
    $localMessage = implode(' · ', $uniq);
    $localAlert = function_exists('pos_card_use_alert')
        ? pos_card_use_alert($localMessage, '')
        : ['card_use' => 'local_validation', 'card_use_ar' => '', 'card_use_en' => ''];
    echo json_encode([
        'success' => false,
        'message' => $localMessage,
        'errors' => $uniq,
        'card_use' => $localAlert['card_use'] ?? 'local_validation',
        'card_use_ar' => $localAlert['card_use_ar'] ?? '',
        'card_use_en' => $localAlert['card_use_en'] ?? '',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// 7. الاتصال بقاعدة البيانات
// ============================================================

$db = db();
require_once POS_APP_ROOT . '/lib/HoldStoreSync.php';
HoldStoreSync::repairOnce(false);

$captureAmount = floatval($data['capture_amount'] ?? $extra['capture_amount'] ?? 0);
$refundAmount = floatval($data['refund_amount'] ?? $extra['refund_amount'] ?? 0);
if ($txnType === 'capture') {
    if (abs($captureAmount) < 0.01 && $refundAmount <= 0) {
        $captureAmount = $amount;
    }
    if (abs($captureAmount) >= 0.01) {
        $amount = $captureAmount;
    } elseif ($refundAmount > 0) {
        $amount = $refundAmount;
    }
} elseif ($txnType === 'purchase_advice' && $captureAmount > 0 && $amount >= 0) {
    $amount = $captureAmount;
}

// Capture looks up the AUTH hold in dp_transactions or dp_holds.
// purchase_advice is an independent charge and does not require a prior hold.
$authorizedAmount = null;
$priorCapturedTotal = 0.0;
$priorCaptureCount = 0;
$approvalLookup = trim($bankApprovalCode !== '' ? $bankApprovalCode : $gatewayApprovalCode);
if ($txnType === 'capture' && ($origRef !== '' || $paymentId !== '' || $approvalLookup !== '')) {
    $foundHold = HoldStoreSync::find($db, [
        'payment_id' => $paymentId,
        'transaction_id' => $paymentId,
        'orig_ref' => $origRef,
        'reference' => $origRef,
        'rrn' => pos_normalize_rrn($origRef),
        'approval' => $approvalLookup,
    ]);
    $original = $foundHold['txn'];
    $holdRow = $foundHold['hold'];
    if ($original === null && is_array($holdRow)) {
        $original = [
            'amount' => $holdRow['amount'] ?? 0,
            'gateway' => $holdRow['gateway'] ?? '',
            'rrn' => $holdRow['rrn'] ?? $origRef,
            'status' => $holdRow['status'] ?? '',
            'approval_code' => $holdRow['approval_code'] ?? '',
            'auth_code' => $holdRow['auth_code'] ?? '',
        ];
    }
    if (HoldStoreSync::isClosed(is_array($foundHold['txn']) ? $foundHold['txn'] : null, is_array($holdRow) ? $holdRow : null)) {
        $closedStatus = strtolower((string) (($holdRow['status'] ?? '') ?: ($foundHold['txn']['status'] ?? '')));
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error_code' => $closedStatus === 'cancelled' ? 'HOLD_CANCELLED' : 'ALREADY_CAPTURED',
            'message' => $closedStatus === 'cancelled'
                ? 'This authorization has been cancelled and cannot be captured.'
                : 'This authorization has already been fully captured.',
            'hold_status' => $closedStatus,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
        $storedHold = is_array($original) ? $original : [];
        if (is_array($holdRow ?? null)) {
            $storedHold = array_merge($storedHold, array_filter([
                'rrn' => $holdRow['rrn'] ?? '',
                'approval_code' => $holdRow['approval_code'] ?? '',
                'auth_code' => $holdRow['auth_code'] ?? '',
                'bank_approval_code' => $holdRow['bank_approval_code'] ?? '',
            ], static function ($value) {
                return trim((string) $value) !== '';
            }));
        }
        $boundLater = pos_bind_capture_pair($storedHold, $origRef, $approvalLookup);
        if (!$boundLater['ok']) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $boundLater['message']], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($boundLater['rrn'] !== '') {
            $origRef = $boundLater['rrn'];
            $bankRrn = $boundLater['rrn'];
        }
        if ($boundLater['approval'] !== '') {
            $bankApprovalCode = $boundLater['approval'];
            $gatewayApprovalCode = $boundLater['approval'];
            $approvalLookup = $boundLater['approval'];
        }
        if (!empty($original['amount'])) {
        $authorizedAmount = (float) $original['amount'];
        $authGateway = strtolower(trim((string) ($original['gateway'] ?? '')));
        if ($authGateway !== '' && $authGateway !== strtolower(trim($posGateway))) {
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'message' => 'Capture must use the same gateway as the original AUTH hold.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $authPid = $paymentId;
        $authRrn = (string) ($original['rrn'] ?? $origRef);
        $follow = function_exists('pos_auth_followup_totals')
            ? pos_auth_followup_totals($db, $authPid, $authRrn)
            : ['captured_total' => 0.0, 'capture_count' => 0];
        $priorCapturedTotal = (float) $follow['captured_total'];
        $priorCaptureCount = (int) $follow['capture_count'];
    }
    if (is_array($holdRow)) {
        $GLOBALS['_dp_hold_row'] = $holdRow;
    }
    $GLOBALS['_dp_capture_keys'] = [
        'payment_id' => $paymentId,
        'transaction_id' => $paymentId,
        'orig_ref' => $origRef,
        'reference' => $origRef,
        'rrn' => pos_normalize_rrn($origRef),
        'approval' => $approvalLookup,
    ];
}

// ============================================================
// 8. معالجة عبر بوابة البطاقة المختارة فقط (بدون تبديل صامت)
// ============================================================

$useCardGateway = !in_array($txnType, ['balance', 'settlement'])
    || $posGateway === 'diparma';
$success = false;
$message = 'PENDING';
$responseCode = '';
$rrn = '';
$providerPaymentId = '';
$receiptIds = [
    'rrn' => '',
    'approval_code' => '',
    'payment_id' => '',
    'internal_approval_code' => '',
];
$originalRrn = $origRef;
$stanDigits = preg_replace('/\D/', '', (string) ($extra['stan'] ?? $data['stan'] ?? '')) ?? '';
$stan = $stanDigits !== '' ? str_pad(substr($stanDigits, -6), 6, '0', STR_PAD_LEFT) : date('His');
$batchDigits = preg_replace('/\D/', '', (string) ($extra['batch'] ?? $data['batch'] ?? '')) ?? '';
$batch = $batchDigits !== '' ? substr($batchDigits, -6) : date('dmy');
$receiptStan = $stan;
$receiptBatch = $batch;
$approvalCode = '';
$nuveiTxnId = null;
$cardLast4 = '';
$gatewayResponse = [];
$requires3ds = false;
$redirectUrl = null;
$result = [];
$orderPersistedByOrchestrator = false;
$orchestratorOrderId = null;
$hostErrors = [];
$squareErrorCode = '';

if ($useCardGateway) {
    try {
        $params = [
            'amount' => $amount,
            'currency' => $currency,
            'card_number' => $cardNumber,
            'card_name' => $cardName,
            'card_expiry' => $cardExpiry,
            'card_cvv' => $cardCVV,
            'card_type' => $cardType,
            'card_network' => $cardNetwork,
            'cloud_token' => $cloudToken,
            'payment_token' => $cloudToken,
            'source_id' => $sourceId,
            'verification_token' => trim((string) ($data['verification_token'] ?? ($extra['verification_token'] ?? ''))),
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
            'tid' => $terminalId,
            'merchant_id' => $merchantId,
            'stan' => $stan,
            'batch' => $batch,
            'moto_indicator' => $extra['moto_indicator'] ?? (in_array($txnType, ['auth', 'purchase_advice', 'offline_sale_moto', 'online_sale_moto'], true) ? 'M' : null),
            'is_moto' => !empty($extra['is_moto']) || !empty($opMeta['is_moto']) || in_array($txnType, ['auth', 'purchase_advice', 'offline_sale_moto', 'online_sale_moto'], true),
            'auth_channel' => $extra['auth_channel'] ?? $data['auth_channel'] ?? '',
            'moto_channel' => $extra['auth_channel'] ?? $data['auth_channel'] ?? '',
            'entry_mode' => $extra['entry_mode'] ?? ($opMeta['entry_mode'] ?? 'keyed'),
            'input_mode' => $extra['input_mode'] ?? ($data['input_mode'] ?? ''),
            'is_offline' => !empty($extra['is_offline']),
            'processing_mode' => $extra['processing_mode'] ?? ($secMode ?? '2D'),
            'scheme_route' => $cardNetwork,
            'allow_amount_override' => !empty($opMeta['amount_flexible']) || in_array($txnType, ['capture', 'purchase_advice'], true),
            'amount_sign' => $amount < 0 ? 'minus' : 'plus',
            'signed_amount' => $amount,
            'ledger_addr' => $ledgerAddr,
            'ledger_address' => $ledgerAddr,
            'destination' => 'gateway',
            'settlement_target' => 'gateway',
        ];

        $runType = $txnType;
        if ($txnType === 'auth') {
            $params['card_cvv'] = '';
            if (pos_entry_is_present($params)) {
                $params['is_moto'] = false;
                $params['card_present'] = true;
                $params['is_offline'] = false;
                $params['processing_mode'] = '2D';
                unset($params['moto_indicator'], $params['moto_channel']);
            } else {
                $params['is_moto'] = true;
                $params['moto_indicator'] = 'M';
                $params['entry_mode'] = 'keyed';
                $params['card_present'] = false;
                $params['auth_channel'] = strtolower((string) ($extra['auth_channel'] ?? $data['auth_channel'] ?? 'online'));
                if ($params['auth_channel'] === 'offline') {
                    $params['is_offline'] = true;
                }
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
        if ($cardRail && $runType === 'capture' && $origRef === '' && $paymentId === '') {
            throw new Exception('Original AUTH RRN or Payment ID is required for capture');
        }
        if ($runType === 'purchase_advice') {
            $params['card_cvv'] = '';
            $params['linked_to_auth'] = ($paymentId !== '' || $origRef !== '');
            $params['allow_amount_override'] = true;
            $params['original_transaction_date'] = (string) ($extra['original_transaction_date'] ?? '');
            if (pos_entry_is_present($params)) {
                $params['is_moto'] = false;
                $params['card_present'] = true;
                $params['entry_mode'] = $params['entry_mode'] ?: 'pos_magstripe';
                unset($params['moto_indicator'], $params['moto_channel']);
            } else {
                $params['is_moto'] = true;
                $params['card_present'] = false;
                $params['entry_mode'] = 'keyed';
                $params['moto_indicator'] = 'M';
            }
        }
        if (in_array($txnType, ['capture', 'purchase_advice', 'offline_sale_moto'], true) && $amount < 0) {
            $params['amount'] = pos_host_debit_amount($txnType, $amount);
            $params['capture_amount'] = $txnType === 'capture' ? $params['amount'] : ($params['capture_amount'] ?? null);
        }
        if ($txnType === 'capture') {
            $params['capture_amount'] = abs($captureAmount) >= 0.01 ? pos_host_debit_amount('capture', $captureAmount) : ($params['capture_amount'] ?? 0);
            $params['refund_amount'] = $refundAmount;
            $params['linked_to_auth'] = true;
            $params['rrn'] = $origRef;
            $params['orig_ref'] = $origRef;
            $params['approval_code'] = $bankApprovalCode;
            $params['auth_code'] = $gatewayApprovalCode !== '' ? $gatewayApprovalCode : $bankApprovalCode;
            $capOk = true;
            $refOk = true;
            $result = ['success' => false, 'message' => 'Enter withdraw and/or refund amount'];
            $captureSends = abs($captureAmount) >= 0.01;
            if ($captureSends && $refundAmount <= 0) {
                $params['amount'] = pos_host_debit_amount('capture', $captureAmount);
                $result = pos_run_payment_orchestrator($posGateway, 'capture', $params);
                $capOk = !empty($result['success']);
            } elseif ($captureSends) {
                $params['amount'] = pos_host_debit_amount('capture', $captureAmount);
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
            if ($captureSends && !$capOk) {
                $result['success'] = false;
            } elseif (!$captureSends && $refundAmount > 0) {
                $result['success'] = $refOk;
            } elseif ($captureSends && $refundAmount > 0) {
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
            ? (pos_is_withdrawal($txnType) ? 'SUCCESS' : 'APPROVED')
            : pos_host_decline_line(is_array($result) ? $result : ['message' => (string) $result]);
        $hostErrors = $success ? [] : pos_public_host_errors(is_array($result) ? $result : []);
        $squareErrorCode = trim((string) ($result['square_error_code'] ?? ''));
        if ($squareErrorCode === '' && !empty($hostErrors[0]['code'])) {
            $squareErrorCode = trim((string) $hostErrors[0]['code']);
        }
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
        $receiptIds = pos_gateway_receipt_identifiers($result);
        $approvalCode = $receiptIds['approval_code'];
        $providerPaymentId = $receiptIds['payment_id'];
        $rrn = $receiptIds['rrn'];
        if ($approvalCode === '') {
            $approvalCode = trim((string) (
                $result['authCode']
                ?? $result['authorization_code']
                ?? $result['auth_result_code']
                ?? ''
            ));
        }
        $offlineDirect = $txnType === 'offline_sale_moto' || !empty($manualOffline);
        if ($rrn === '' && !$offlineDirect && $posGateway !== 'nuvei') {
            $rrn = trim((string) ($result['transaction_id'] ?? $result['payment_id'] ?? ''));
        }
        if ($rrn !== '') {
            $result['rrn'] = $rrn;
        }
        if ($approvalCode !== '') {
            $result['approval_code'] = $approvalCode;
            $result['auth_code'] = $result['auth_code'] ?? $approvalCode;
        }
        if ($success && empty($result['requires_3ds']) && $txnType === 'auth') {
            $pair = pos_auth_receipt_pair(
                is_array($result) ? $result : [],
                (string) $stan,
                (string) $reference,
                $posGateway !== 'nuvei'
            );
            if ($pair['rrn'] !== '') {
                $rrn = $pair['rrn'];
            } elseif ($posGateway !== 'nuvei') {
                $rrn = pos_normalize_rrn($rrn);
            }
            if ($pair['approval_code'] !== '') {
                $approvalCode = $pair['approval_code'];
            }
            $bankApprovalCode = $approvalCode;
            if ($rrn !== '') {
                $result['rrn'] = $rrn;
            }
            if ($approvalCode !== '') {
                $result['approval_code'] = $approvalCode;
                $result['auth_code'] = $approvalCode;
                $result['bank_approval_code'] = $approvalCode;
            }
            $receiptIds['rrn'] = $rrn;
            $receiptIds['approval_code'] = $approvalCode;
        }
        $cardLast4 = preg_replace('/\D+/', '', (string) ($result['card_last4'] ?? '')) ?? '';
        if ($cardLast4 === '' && $cardNumber !== '') {
            $cardLast4 = substr($cardNumber, -4);
        }
        if (strlen($cardLast4) > 4) {
            $cardLast4 = substr($cardLast4, -4);
        }
        if (in_array($txnType, ['capture'], true) && $rrn === '') {
            $rrn = $originalRrn;
        }
        $returnedStan = preg_replace('/\D/', '', (string) ($result['stan'] ?? '')) ?? '';
        if ($returnedStan !== '') {
            $stan = str_pad(substr($returnedStan, -6), 6, '0', STR_PAD_LEFT);
        }
        $receiptStan = $posGateway === 'nuvei'
            ? trim((string) ($result['stan'] ?? ''))
            : $stan;
        $receiptBatch = $posGateway === 'nuvei'
            ? trim((string) ($result['batch'] ?? $result['batch_number'] ?? ''))
            : $batch;
        $nuveiTxnId = $result['nuvei_txn_id'] ?? null;
        $requires3ds = !empty($result['requires_3ds']);
        $redirectUrl = $result['redirect_url'] ?? null;
        $stripeClientSecret = $posGateway === 'stripe' && $requires3ds
            ? trim((string) ($result['client_secret'] ?? ''))
            : '';
        $gatewayResponse = pos_redact_pci(is_array($result) ? $result : []);
        $cardCVV = '';
        if (isset($params['card_cvv'])) {
            $params['card_cvv'] = '';
        }
        unset($params['card_number']);
        if ($requires3ds) {
            $success = false;
            $message = '3DS_REQUIRED';
        } elseif ($success) {
            require_once POS_APP_ROOT . '/lib/CardScaService.php';
            $challengePerformed = $txnType === 'purchase_3d'
                && $secMode === '3D'
                && CardScaService::shouldChallenge([
                    'card_number' => $cardNumber,
                    'card_expiry' => $cardExpiry,
                    'card_last4' => $cardLast4,
                ]);
            if (CardScaService::shouldRecordCompletedChallenge($challengePerformed, $success)) {
                CardScaService::markCompleted($cardNumber, $cardExpiry, $cardLast4, (string) $reference);
            }
        }

    } catch (Exception $e) {
        pos_safe_log('[POS][Nuvei] Exception:', $e->getMessage());
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

if ($txnType === 'balance' && $posGateway !== 'diparma') {
    if (!pos_gateway_requires_card($posGateway)) {
        $success = false;
        $message = 'No bank balance. DIPARMA GATEWAY is Ledger USDT TRC20 only.';
        $gatewayResponse = ['type' => 'balance', 'rail' => 'ledger', 'no_bank' => true];
    } else {
        try {
            require_once POS_APP_ROOT . '/lib/Adapters/GatewayAdapterFactory.php';
            $nuvei = GatewayAdapterFactory::resolve('nuvei');
            $result = $nuvei->balanceInquiry([]);
            $success = !empty($result['success']);
            $message = $success ? 'BALANCE_INQUIRY_OK' : pos_plain_host_message($result['message'] ?? 'BALANCE_INQUIRY_FAILED');
            $gatewayResponse = pos_redact_pci(is_array($result) ? $result : []);
        } catch (Exception $e) {
            $success = false;
            $message = pos_plain_host_message($e->getMessage());
        }
    }
}

if ($txnType === 'settlement' && $posGateway !== 'diparma') {
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
$receiptPaymentId = $providerPaymentId !== ''
    ? $providerPaymentId
    : ($txnType === 'auth' ? '' : $paymentId);

$gatewayDetails = [
    'operation_name' => $displayOperation,
    'transaction_type' => $txnType,
    'card_type' => $cardType,
    'transaction_id' => $result['transaction_id'] ?? $nuveiTxnId,
    'payment_id' => $receiptPaymentId,
    'authorization_id' => $result['authorization_id'] ?? ($txnType === 'auth' ? $providerPaymentId : ''),
    'auth_code' => $approvalCode,
    'approval_code' => $approvalCode,
    'internal_approval_code' => $receiptIds['internal_approval_code'],
    'rrn' => $rrn,
    'stan' => $receiptStan,
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
$cardUseAlert = (!$success && !$requires3ds)
    ? pos_card_use_alert((string) $message, (string) $cardLast4)
    : ['card_use' => null, 'card_use_ar' => null, 'card_use_en' => null];
$persistCharge = diparma_should_persist_charge($success, $requires3ds);
try {
    if (!$persistCharge) {
        if (empty($result['decline_stored'])) {
        diparma_record_declined_attempt($db, [
            'reference' => $reference,
            'user_id' => $userId,
            'gateway' => $posGateway,
            'transaction_type' => $txnType,
            'amount' => $amount,
            'currency' => $currency,
            'reason' => $message,
            'card_last4' => $cardLast4,
            'details' => [
                'channel' => $entryChannel,
                'card_type' => $cardType,
                'card_network' => $cardNetwork,
                'rrn' => $rrn,
                'stan' => $receiptStan,
                'approval_code' => $approvalCode,
                'bank_approval_code' => $bankApprovalCode,
                'gateway_response' => $gatewayResponse,
                'pos_device' => $posDevice,
                'pos_model' => $posModel,
                'pos_type' => $posType,
                'terminal_id' => $terminalId,
                'customer_name' => $data['name'] ?? $data['card_name'] ?? null,
                'customer_email' => $data['email'] ?? null,
                'customer_phone' => $data['phone'] ?? null,
            ],
        ]);
        }
        diparma_discard_unsuccessful_transaction($db, (string) $reference);
        if (function_exists('logEvent')) {
            logEvent('POS unsuccessful charge omitted ref=' . $reference . ' gateway=' . preg_replace('/[^A-Za-z0-9_.:-]/', '', (string) $posGateway), 'info');
        }
    } else {
    $txnPayload = [
        'reference' => $reference,
        'user_id' => $userId > 0 ? $userId : null,
        'gateway' => $posGateway,
        'gateway_type' => pos_gateway_requires_card($posGateway) ? 'card' : 'crypto',
        'transaction_type' => $txnType,
        'transaction_label' => $displayOperation,
        'amount' => $amount,
        'currency' => $currency,
        'card_last4' => $cardLast4,
        'security_mode' => $secMode,
        'status' => $requires3ds ? 'pending' : ($success ? ($txnType === 'auth' ? 'authorized' : 'completed') : 'failed'),
        'gateway_response' => json_encode(pos_redact_pci([
            'channel' => $entryChannel,
            'orchestrator' => !empty($result['orchestrator']) ? $result['orchestrator'] : null,
            'settlement_path' => $settlementPath,
            'settlement_target' => $settlementTarget,
            'status_message' => $message,
            'decline_reason' => $success ? null : $message,
            'raw_message' => $success ? null : trim((string) ($result['raw_message'] ?? $message)),
            'error_code' => $success ? null : trim((string) ($squareErrorCode !== '' ? $squareErrorCode : ($result['error_code'] ?? $result['decline_code'] ?? ''))),
            'response_code' => $responseCode,
            'host_errors' => $success ? [] : $hostErrors,
            'success' => $success,
            'card_use' => $cardUseAlert['card_use'],
            'card_use_ar' => $cardUseAlert['card_use_ar'],
            'card_use_en' => $cardUseAlert['card_use_en'],
            'rrn' => $rrn,
            'stan' => $receiptStan,
            'batch' => $receiptBatch,
            'original_rrn' => $originalRrn,
            'bank_approval_code' => $bankApprovalCode,
            'approval_code' => $approvalCode,
            'payment_id' => $receiptPaymentId,
            'authorization_id' => $result['authorization_id'] ?? ($txnType === 'auth' ? $providerPaymentId : ''),
            'internal_approval_code' => $receiptIds['internal_approval_code'],
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
            'linked_to_auth' => $txnType === 'capture',
            'advice_independent' => ($txnType === 'purchase_advice' && $paymentId === '' && $origRef === ''),
            'capture_sequence' => in_array($txnType, ['capture', 'purchase_advice'], true) ? ($priorCaptureCount + 1) : null,
            'prior_captured_total' => in_array($txnType, ['capture', 'purchase_advice'], true) ? $priorCapturedTotal : null,
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
        ])),
        'ledger_address' => $ledgerAddr,
        'auth_code' => $approvalCode,
        'approval_code' => $approvalCode,
        'rrn' => $rrn,
        'stan' => $receiptStan,
        'bank_approval_code' => $bankApprovalCode,
        'acquirer' => $posGateway,
        'notes' => trim($entryChannel . '_order'
            . ($posLocation !== '' ? ' ' . $posLocation : '')
            . ($manualNotes !== '' ? ' ' . substr($manualNotes, 0, 120) : '')
            . ' mid=' . $merchantId),
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

    if ($success && $txnType === 'auth') {
        try {
            HoldStoreSync::syncAuthorized($db, [
                'reference' => $reference,
                'user_id' => $userId,
                'payment_intent_id' => $providerPaymentId !== '' ? $providerPaymentId : $reference,
                'amount' => $amount,
                'currency' => $currency,
                'gateway' => $posGateway,
                'status' => 'authorized',
                'rrn' => $rrn,
                'approval_code' => $approvalCode,
                'gateway_response' => $txnPayload['gateway_response'] ?? '',
                'mode' => $secMode ?? '2D',
            ]);
        } catch (Throwable $syncEx) {
            error_log('[POS][HoldSync] auth sync failed: ' . $syncEx->getMessage());
        }
    }
    if ($success && $txnType === 'capture') {
        try {
            $captureKeys = $GLOBALS['_dp_capture_keys'] ?? [
                'payment_id' => $paymentId,
                'orig_ref' => $origRef,
                'rrn' => $origRef,
                'approval' => $approvalCode,
            ];
            HoldStoreSync::markCaptured($db, $captureKeys, (float) ($captureAmount > 0 ? $captureAmount : $amount));
        } catch (Throwable $syncEx) {
            error_log('[POS][HoldSync] capture sync failed: ' . $syncEx->getMessage());
        }
    }
    if ($success && in_array($txnType, ['avoid', 'void', 'cancel'], true)) {
        try {
            HoldStoreSync::markCancelled($db, [
                'payment_id' => $paymentId,
                'transaction_id' => $paymentId,
                'orig_ref' => $origRef,
                'rrn' => $origRef,
                'approval' => $bankApprovalCode,
            ]);
        } catch (Throwable $syncEx) {
            error_log('[POS][HoldSync] void sync failed: ' . $syncEx->getMessage());
        }
    }
    }
    if ($saved && empty($data['_peer_mirror']) && function_exists('diparma_peer_push_txn')) {
        diparma_peer_push_txn([
            'reference' => $reference,
            'gateway' => $posGateway,
            'amount' => $amount,
            'currency' => $currency,
            'status' => $requires3ds ? 'pending' : ($success ? ($txnType === 'auth' ? 'authorized' : 'completed') : 'failed'),
            'transaction_type' => $txnType,
            'transaction_label' => $displayOperation,
            'created_at' => date('Y-m-d H:i:s'),
            'gateway_response' => is_array($gatewayDetails) ? pos_redact_pci($gatewayDetails) : [],
        ]);
    }
} catch (Throwable $e) {
    pos_safe_log('[POS][DB]', $e->getMessage());
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
} elseif ($success && !$requires3ds && in_array($txnType, $ledgerTransferTypes, true)
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
            'destination'    => $settlementTarget,
            'settlement_target' => $settlementTarget,
            'ledger_checkout' => $ledgerCheckout ? 1 : 0,
            'source'         => $ledgerCheckout ? 'ledger_checkout' : 'pos',
        ]);
        $ledgerTransfer = !empty($transferResult['success']) && empty($transferResult['skipped']) && empty($transferResult['retained']);
        $ledgerTxid = $transferResult['txid'] ?? null;
        $ledgerFee = $transferResult['fee'] ?? null;
        $ledgerNet = $transferResult['net_fiat'] ?? null;
        $ledgerUsdt = $transferResult['usdt_amount'] ?? null;
        if (!empty($transferResult['retained'])) {
            $ledgerStatus = 'retained';
        } elseif (!empty($transferResult['skipped'])) {
            $ledgerStatus = 'skipped';
        } elseif ($ledgerTransfer) {
            $ledgerStatus = 'completed';
        } elseif (!empty($transferResult['queued'])) {
            $ledgerStatus = 'queued';
        } else {
            $ledgerStatus = 'failed';
        }
    } catch (Exception $e) {
        pos_safe_log('[POS][Ledger]', $e->getMessage());
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
            'gateway_response' => pos_redact_pci($gatewayDetails),
        ], 8);
        $withdrawOn = strtolower(trim((string)($data['withdraw_on'] ?? 'local')));
        if (in_array($withdrawOn, ['both', 'peer', 'remote'], true)) {
            $peerBody = pos_redact_pci($data);
            unset($peerBody['card_number'], $peerBody['cc_number'], $peerBody['card_cvv'], $peerBody['cc_cvv'], $peerBody['card_name']);
            $peerSync['withdraw'] = peer_request('withdraw', [
                'payload' => array_merge($peerBody, [
                    '_peer_mirror' => 1,
                    'txn_type'     => $txnType,
                    'amount'       => $amount,
                    'currency'     => $currency,
                    'card_last4'   => $cardLast4,
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
if (!$success) {
    $message = pos_host_decline_line(is_array($result) ? array_merge($result, ['message' => $message]) : ['message' => $message]);
    if ($hostErrors === [] && is_array($result)) {
        $hostErrors = pos_public_host_errors($result);
    }
    if ($squareErrorCode === '' && !empty($hostErrors[0]['code'])) {
        $squareErrorCode = trim((string) $hostErrors[0]['code']);
    }
} else {
    $message = pos_plain_host_message($message);
}
if (!$success && !$requires3ds) {
    $cardUseAlert = pos_card_use_alert($message, (string) $cardLast4);
}
$rawMessageOut = null;
$errorCodeOut = null;
if (!$success) {
    $rawMessageOut = trim((string) ($result['raw_message'] ?? ''));
    if ($rawMessageOut === '') {
        $rawMessageOut = $message;
    }
    $errorCodeOut = $squareErrorCode !== ''
        ? $squareErrorCode
        : trim((string) ($result['error_code'] ?? $result['decline_code'] ?? ''));
    if ($errorCodeOut === '') {
        $errorCodeOut = null;
    }
}
echo json_encode([
    'success' => $success,
    'reference' => $reference,
    'rrn' => $rrn,
    'stan' => $receiptStan,
    'batch' => $receiptBatch,
    'original_rrn' => $originalRrn,
    'approval_code' => $approvalCode,
    'gateway_approval_code' => $approvalCode,
    'payment_id' => $receiptPaymentId,
    'authorization_id' => $result['authorization_id'] ?? ($txnType === 'auth' ? $providerPaymentId : ''),
    'internal_approval_code' => $receiptIds['internal_approval_code'],
    'bank_approval_code' => $bankApprovalCode,
    'nuvei_txn_id' => $nuveiTxnId,
    'txn_type' => $txnType,
    'operation_name' => $displayOperation,
    'transaction_label' => $displayOperation,
    'gateway_details' => pos_redact_pci($gatewayDetails),
    'amount' => $amount,
    'currency' => $currency,
    'response_code' => $responseCode,
    'card_last4' => $cardLast4,
    'status_message' => $message,
    'message' => $message,
    'decline_reason' => $success ? null : $message,
    'card_use' => $cardUseAlert['card_use'],
    'card_use_ar' => $cardUseAlert['card_use_ar'],
    'card_use_en' => $cardUseAlert['card_use_en'],
    'raw_message' => $rawMessageOut,
    'error_code' => $errorCodeOut,
    'square_error_code' => $success ? null : ($squareErrorCode !== '' ? $squareErrorCode : null),
    'host_errors' => $success ? [] : pos_redact_pci($hostErrors),
    'pos_device' => $posDevice,
    'pos_model' => $posModel,
    'pos_type' => $posType,
    'terminal_id' => $terminalId,
    'acquirer' => $posGateway,
    'gateway_rrn' => $rrn,
    'merchant' => 'TRANSCENDIO FZ-LLC',
    'settlement_path' => $settlementPath,
    'settlement_target' => $settlementTarget,
    'destination' => $settlementTarget,
    'peer_sync' => $peerSync,
    'ledger_transfer' => $ledgerTransfer,
    'ledger_txid' => $ledgerTxid,
    'ledger_address' => $ledgerAddr,
    'ledger_status' => $ledgerStatus,
    'gateway_fee' => $ledgerFee,
    'net_amount' => $ledgerNet,
    'ledger_usdt' => $ledgerUsdt,
    'requires_3ds' => $requires3ds,
    'payment' => $stripeClientSecret !== '' ? ['client_secret' => $stripeClientSecret] : null,
    'redirect_url' => $redirectUrl,
    'saved' => $saved,
    'orchestrator' => $result['orchestrator'] ?? null,
    'channel' => $entryChannel,
    'order_id' => $transactionId,
    'timestamp' => date('c'),
], JSON_UNESCAPED_UNICODE);
