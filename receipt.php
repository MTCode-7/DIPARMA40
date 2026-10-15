<?php
/**
 * ============================================================
 * DI PARMA | إيصال دفع حقيقي (Real Payment Receipt)
 * يشبه إيصالات نقاط البيع (POS) البنكية
 * يعرض جميع تفاصيل المعاملة الحقيقية من بوابة الدفع
 * ============================================================
 */

// ============================================================
// 1. استيراد الملفات الأساسية
// معاينة إيصال PayPal بلا دخول: ?preview=paypal&op=auth|capture|purchase_advice|offline_sale_moto|all
// العينات ثابتة ولا تقرأ قاعدة البيانات.
// ============================================================

if (($_GET['preview'] ?? '') === 'paypal') {
    $previewLang = (string) ($_GET['lang'] ?? ($_COOKIE['di_parma_lang'] ?? 'en'));
    $previewAr = $previewLang === 'ar';
    require_once __DIR__ . '/pos/lib/operations.php';
    require_once __DIR__ . '/pos/lib/paypal_merchant_receipt.php';
    echo pos_paypal_merchant_receipt_preview((string) ($_GET['op'] ?? 'all'), $previewAr);
    exit;
}

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/pos/lib/operations.php';

// ============================================================
// 2. تحديد اللغة والاتجاه
// ============================================================

$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'ar' ? 'ar' : 'en';
$ar   = ($lang === 'ar');
$dir  = $ar ? 'rtl' : 'ltr';

// ============================================================
// 3. استقبال المرجع والتحقق منه
// ============================================================

$ref = trim($_GET['ref'] ?? '');
if ($ref === '' || !preg_match('/^[A-Za-z0-9._:-]{6,80}$/', $ref)) {
    header('Location: dashboard.php');
    exit;
}

// ============================================================
// 4. الاتصال بقاعدة البيانات وجلب المعاملة
// ============================================================

$db = db();
$txn = null;

try {
    // محاولة جلب من جدول dp_transactions
    $rows = $db->query(
        "SELECT * FROM dp_transactions WHERE reference = ? LIMIT 1",
        [$ref]
    );
    $txn = $rows[0] ?? null;
} catch (Exception $e) {}

if (!$txn) {
    // محاولة من جدول transactions القديم
    try {
        $rows = $db->query(
        "SELECT * FROM " . dp_table('transactions') . " WHERE reference = ? LIMIT 1",
            [$ref]
        );
        $txn = $rows[0] ?? null;
    } catch (Exception $e) {}
}

if (!$txn) {
    try {
        $rows = $db->query(
            "SELECT * FROM " . dp_table('payment_attempts') . " WHERE reference = ? ORDER BY id DESC LIMIT 1",
            [$ref]
        );
        $attempt = $rows[0] ?? null;
        if ($attempt) {
            $details = json_decode((string) ($attempt['details'] ?? '{}'), true) ?: [];
            $attemptReason = (string) ($attempt['reason'] ?? '');
            $accountSetup = (string) ($attempt['status'] ?? '') === 'account_setup'
                || (function_exists('diparma_is_account_setup_fault') && diparma_is_account_setup_fault($attemptReason));
            $txn = [
                'reference' => $attempt['reference'],
                'user_id' => $attempt['user_id'],
                'gateway' => $attempt['gateway'],
                'transaction_type' => $attempt['transaction_type'],
                'amount' => $attempt['amount'],
                'currency' => $attempt['currency'],
                'status' => 'declined',
                'card_last4' => $accountSetup ? '' : $attempt['card_last4'],
                'created_at' => $attempt['created_at'],
                'gateway_response' => json_encode(array_merge($details, [
                    'decline_reason' => $attemptReason,
                    'status_message' => $attemptReason,
                    'raw_message' => $attemptReason,
                    'attempt_status' => $accountSetup ? 'account_setup' : 'declined',
                    'card_use' => $accountSetup ? 'account_setup' : ($details['card_use'] ?? null),
                    'card_use_ar' => $accountSetup ? '' : ($details['card_use_ar'] ?? null),
                    'card_use_en' => $accountSetup ? '' : ($details['card_use_en'] ?? null),
                ]), JSON_UNESCAPED_UNICODE),
            ];
        }
    } catch (Exception $e) {}
}

if ($txn && !isAdmin()) {
    $ownerId = intval($txn['user_id'] ?? 0);
    if ($ownerId !== intval($_SESSION['user_id'] ?? 0)) {
        $txn = null;
    }
}

// ============================================================
// 5. إذا لم يتم العثور على المعاملة
// ============================================================

if (!$txn) {
    ?>
    <!DOCTYPE html>
    <html lang="<?=$lang?>" dir="<?=$dir?>">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>DI PARMA | الإيصال غير موجود</title>
        <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
        <style>
            *{margin:0;padding:0;box-sizing:border-box}
            body{font-family:'Cairo',sans-serif;background:#f0f0f0;display:flex;justify-content:center;align-items:center;min-height:100vh}
            .not-found{background:#fff;padding:50px;border-radius:16px;text-align:center;max-width:500px;box-shadow:0 4px 20px rgba(0,0,0,0.1)}
            .not-found i{font-size:60px;color:#ccc;margin-bottom:20px}
            .not-found h2{color:#333;margin-bottom:10px}
            .not-found p{color:#999;margin-bottom:20px}
            .btn{display:inline-block;padding:12px 30px;background:#FFD700;color:#000;border-radius:8px;text-decoration:none;font-weight:700}
        </style>
    </head>
    <body>
        <div class="not-found">
            <i class="fas fa-receipt"></i>
            <h2>⚠️ الإيصال غير موجود</h2>
            <p>لم يتم العثور على معاملة بهذا الرقم المرجعي</p>
            <p style="font-size:14px;color:#666;font-weight:600">المرجع: <?=htmlspecialchars($ref)?></p>
            <a href="dashboard.php" class="btn">← العودة إلى لوحة التحكم</a>
        </div>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
    </body>
    </html>
    <?php
    exit;
}

if (function_exists('diparma_reconcile_pending_transaction')) {
    $recon = diparma_reconcile_pending_transaction($db, $txn);
    if (!empty($recon['updated'])) {
        $fresh = $db->find('transactions', ['id' => (int) ($txn['id'] ?? 0)]);
        if (is_array($fresh) && $fresh) {
            $txn = $fresh;
        }
    }
}

// ============================================================
// 6. استخراج جميع تفاصيل المعاملة الحقيقية
// ============================================================

function receiptMask(string $value, int $start = 2, int $end = 2): string
{
    $value = trim($value);
    if ($value === '' || $value === '—') return $value;
    $len = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    $sub = static function (string $s, int $off, ?int $n = null) {
        if (function_exists('mb_substr')) {
            return $n === null ? mb_substr($s, $off, null, 'UTF-8') : mb_substr($s, $off, $n, 'UTF-8');
        }
        return $n === null ? substr($s, $off) : substr($s, $off, $n);
    };
    if ($len <= $start + $end) return str_repeat('*', $len);
    return $sub($value, 0, $start) . str_repeat('*', $len - $start - $end) . $sub($value, -$end);
}

function receiptMaskName(string $value): string
{
    $parts = preg_split('/\s+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts) return '—';
    return implode(' ', array_map(static function (string $part): string {
        $len = function_exists('mb_strlen') ? mb_strlen($part, 'UTF-8') : strlen($part);
        $first = function_exists('mb_substr') ? mb_substr($part, 0, 1, 'UTF-8') : substr($part, 0, 1);
        return $len > 1 ? $first . str_repeat('*', max(2, $len - 1)) : '*';
    }, $parts));
}

function receiptMaskEmail(string $value): string
{
    $parts = explode('@', trim($value), 2);
    if (count($parts) !== 2 || $parts[0] === '') return receiptMask($value, 2, 2);
    return receiptMask($parts[0], 1, 1) . '@' . $parts[1];
}

// 6.1 تفاصيل عامة
$amount = number_format((float)($txn['amount'] ?? 0), 2);
$currency = $txn['currency'] ?? 'USD';
$status = strtoupper($txn['status'] ?? 'UNKNOWN');
$gateway = strtoupper($txn['gateway'] ?? '—');
$createdAt = $txn['created_at'] ?? date('Y-m-d H:i:s');
$dateObj = new DateTime($createdAt);
$dateStr = $dateObj->format('d/m/Y');
$timeStr = $dateObj->format('H:i:s');

// 6.2 استخراج رد البوابة (JSON)
$gwResp = json_decode($txn['gateway_response'] ?? '{}', true) ?? [];
$gatewayResult = $gwResp['gateway_details']['response'] ?? $gwResp['raw'] ?? [];
if (!is_array($gatewayResult)) {
    $gatewayResult = [];
}

// 6.3 تفاصيل البطاقة (من البوابة)
$cardLast4 = $txn['card_last4'] ?? ($gwResp['card_last4'] ?? ($gatewayResult['card_last4'] ?? ($gwResp['payment_method_details']['card']['last4'] ?? '****')));
$cardBrand = $txn['card_brand'] ?? ($gwResp['card_brand'] ?? ($gatewayResult['card_brand'] ?? ($gwResp['payment_method_details']['card']['brand'] ?? 'Visa')));
$cardType = $txn['card_type'] ?? ($gwResp['card_type'] ?? ($gatewayResult['card_type'] ?? ($gwResp['payment_method_details']['card']['funding'] ?? 'Credit')));
$cardholderName = $txn['cardholder_name'] ?? ($gwResp['cardholder_name'] ?? ($gatewayResult['cardholder_name'] ?? ($gwResp['payment_method_details']['card']['holder_name'] ?? '—')));
$cardExpiry = $txn['card_expiry'] ?? ($gwResp['card_expiry'] ?? ($gatewayResult['card_expiry'] ?? ($gwResp['payment_method_details']['card']['exp'] ?? '—')));
$customerEmail = $txn['customer_email'] ?? ($gwResp['email'] ?? '—');
$customerPhone = $txn['customer_phone'] ?? ($gwResp['phone'] ?? '—');

// 6.4 رموز الموافقة والتحقق
// ── استخراج موحّد من pos_gateway_receipt_identifiers ────────
$receiptIds       = pos_gateway_receipt_identifiers($gwResp);
$nestedReceiptIds = pos_gateway_receipt_identifiers($gatewayResult);
$txnGateway       = strtolower(trim((string) ($txn['gateway'] ?? '')));

// ── helper: أول قيمة غير فارغة من قائمة مصادر ──────────────
$firstNonEmpty = static function (array $sources): string {
    foreach ($sources as $v) {
        $s = trim((string) $v);
        if ($s !== '' && $s !== '—') {
            return $s;
        }
    }
    return '—';
};

// ── Auth Code — مصادر لكل بوابة ─────────────────────────────
$gatewayAuthCodeSources = [
    $txn['approval_code'] ?? '',
    $txn['auth_code'] ?? '',
    // مصادر مشتركة (موحّدة عبر pos_gateway_receipt_identifiers)
    $receiptIds['approval_code'],
    $nestedReceiptIds['approval_code'],
    $gwResp['authCode'] ?? '',
    $gwResp['approvalCode'] ?? '',
    $gwResp['auth_result_code'] ?? '',
    $gatewayResult['authCode'] ?? '',
    $gatewayResult['authorization_code'] ?? '',
    // Nuvei
    $gwResp['stage_1_card']['auth_code'] ?? '',
    $gwResp['auth_code'] ?? '',
    $gwResp['authCode'] ?? '',
    // Stripe
    $gatewayResult['payment_method_details']['card']['network_transaction_id'] ?? '',
    $gwResp['charges']['data'][0]['payment_method_details']['card']['checks']['cvc_check'] ?? '',
    $gwResp['latest_charge']['payment_method_details']['card']['network_transaction_id'] ?? '',
    // PayPal
    $gwResp['processor_response']['authorization_id'] ?? '',
    $gwResp['purchase_units'][0]['payments']['authorizations'][0]['id'] ?? '',
    $gwResp['purchase_units'][0]['payments']['captures'][0]['id'] ?? '',
    // Square
    $gwResp['payment']['card_details']['auth_result_code'] ?? '',
    $gatewayResult['card_details']['auth_result_code'] ?? '',
    // MyFatoorah
    $gwResp['Data']['PaymentId'] ?? '',
    $gwResp['InvoiceId'] ?? '',
    // Checkout.com
    $gwResp['auth_code'] ?? '',
    $gatewayResult['auth_code'] ?? '',
    // Wise
    $gwResp['transferId'] ?? '',
    // Gate.io / Binance
    $gwResp['order_id'] ?? '',
    $gwResp['orderId'] ?? '',
];
$authCode = $firstNonEmpty($gatewayAuthCodeSources);

// ── Bank Approval Code ───────────────────────────────────────
$bankApprovalCode = $firstNonEmpty([
    $gwResp['bank_approval_code'] ?? '',
    $gwResp['original_approval_code'] ?? '',
    $gatewayResult['bank_approval_code'] ?? '',
    $gatewayResult['original_approval_code'] ?? '',
    // Nuvei specific
    $gwResp['stage_1_card']['bank_approval_code'] ?? '',
]);

// ── RRN ─────────────────────────────────────────────────────
$squareFamily = in_array($txnGateway, ['square', 'square_online'], true);
$gatewayRrnSources = [
    $txn['rrn'] ?? '',
    $receiptIds['rrn'],
    $nestedReceiptIds['rrn'],
    $gwResp['retrieval_reference_number'] ?? '',
    $gwResp['retrievalReferenceNumber'] ?? '',
    // Nuvei
    $gwResp['stage_1_card']['rrn'] ?? '',
    $gwResp['rrn'] ?? '',
    // Stripe — payment intent id كـ RRN
    ($txnGateway === 'stripe' ? ($gwResp['id'] ?? '') : ''),
    ($txnGateway === 'stripe' ? ($gatewayResult['id'] ?? '') : ''),
    // PayPal
    ($txnGateway === 'paypal' ? ($gwResp['purchase_units'][0]['payments']['captures'][0]['id'] ?? '') : ''),
    ($txnGateway === 'paypal' ? ($gwResp['purchase_units'][0]['payments']['authorizations'][0]['id'] ?? '') : ''),
    // Square
    ($squareFamily ? ($gwResp['payment']['id'] ?? '') : ''),
    ($squareFamily ? ($gatewayResult['id'] ?? '') : ''),
    // MyFatoorah
    ($txnGateway === 'myfatoorah' ? ($gwResp['Data']['InvoiceId'] ?? '') : ''),
    // Checkout.com
    ($txnGateway === 'checkout' ? ($gwResp['id'] ?? '') : ''),
    // Wise
    ($txnGateway === 'wise' ? ((string) ($gwResp['id'] ?? '')) : ''),
    // PayRam
    ($txnGateway === 'payram' ? ($gwResp['reference_id'] ?? '') : ''),
    // fallback عام
    $gwResp['transaction_id'] ?? '',
    $gatewayResult['transaction_id'] ?? '',
];
$rrn = $firstNonEmpty($gatewayRrnSources);

// ── STAN ─────────────────────────────────────────────────────
$stan = $firstNonEmpty([
    $gwResp['stan'] ?? '',
    $gwResp['system_trace_audit_number'] ?? '',
    $gwResp['stage_1_card']['stan'] ?? '',
    $gatewayResult['stan'] ?? '',
]);

// ── Transaction ID (gateway-side) ────────────────────────────
$transactionId = $firstNonEmpty([
    // مشترك
    $gwResp['transaction_id'] ?? '',
    $gwResp['authorization_id'] ?? '',
    // Nuvei
    $gwResp['nuvei_txn_id'] ?? '',
    $gwResp['stage_1_card']['nuvei_txn'] ?? '',
    // Stripe
    ($txnGateway === 'stripe' ? ($gwResp['id'] ?? '') : ''),
    ($txnGateway === 'stripe' ? ($gatewayResult['id'] ?? '') : ''),
    // PayPal
    ($txnGateway === 'paypal' ? ($gwResp['purchase_units'][0]['payments']['captures'][0]['id'] ?? '') : ''),
    ($txnGateway === 'paypal' ? ($gwResp['purchase_units'][0]['payments']['authorizations'][0]['id'] ?? '') : ''),
    // Square
    ($txnGateway === 'square' ? ($gwResp['payment']['id'] ?? '') : ''),
    // MyFatoorah
    ($txnGateway === 'myfatoorah' ? ($gwResp['Data']['PaymentId'] ?? '') : ''),
    // Checkout.com
    ($txnGateway === 'checkout' ? ($gwResp['id'] ?? '') : ''),
    // Gate.io
    ($txnGateway === 'gate_io' ? ($gwResp['order_id'] ?? '') : ''),
    // Binance
    ($txnGateway === 'binance' ? ($gwResp['orderId'] ?? '') : ''),
    // Wise
    ($txnGateway === 'wise' ? ((string) ($gwResp['id'] ?? '')) : ''),
    // PayRam
    ($txnGateway === 'payram' ? ($gwResp['id'] ?? $gwResp['reference_id'] ?? '') : ''),
    // Whop
    ($txnGateway === 'whop' ? ($gwResp['payment_id'] ?? '') : ''),
    // fallback
    $gatewayResult['transaction_id'] ?? '',
    $gatewayResult['authorization_id'] ?? '',
    $gatewayResult['id'] ?? '',
    $gwResp['id'] ?? '',
]);

// ── Payment ID ───────────────────────────────────────────────
$paymentId = $firstNonEmpty([
    $receiptIds['payment_id'],
    $nestedReceiptIds['payment_id'],
    $transactionId,
]);

// ── Internal Approval Code ───────────────────────────────────
$internalApprovalCode = $firstNonEmpty([
    $receiptIds['internal_approval_code'],
    $nestedReceiptIds['internal_approval_code'],
]);

// 6.5 تفاصيل الأمان
$secMode = $firstNonEmpty([
    $txn['security_mode'] ?? '',
    $gwResp['security_mode'] ?? '',
    $gwResp['stage_1_card']['sec_mode'] ?? '',
    '3D SECURE',
]);
$authType = $firstNonEmpty([
    $txn['authorization_type'] ?? '',
    $gwResp['authorization_type'] ?? '',
    $gwResp['stage_1_card']['auth_type'] ?? '',
    'STANDARD',
]);

// 6.6 تفاصيل البنك المستحوذ (Acquirer)
$gatewayDisplayName = pos_gateway_display_name((string) ($txn['gateway'] ?? ''));

// 6.7 تفاصيل التاجر
$merchantName = 'TRANSCENDIO FZ-LLC';
$merchantId = $gwResp['merchant_id'] ?? $txn['merchant_id'] ?? 'DI0001';
$merchantCity = 'Dubai';
$merchantCountry = 'AE';
$merchantCategory = $gwResp['mcc'] ?? $txn['mcc'] ?? '5999';

// 6.9 تفاصيل التسوية إلى Ledger (USDT)
$gwSettle = $gwResp['ledger_settlement'] ?? $gwResp['ledger_settle'] ?? [];
if (!is_array($gwSettle)) {
    $gwSettle = [];
}
$feeInfo = $gwSettle['fee'] ?? $gwResp['gateway_fee'] ?? [];
if (!is_array($feeInfo)) {
    $feeInfo = [];
}

$ledgerAddr = trim((string)(
    $txn['ledger_address']
    ?? $gwSettle['ledger_address']
    ?? $gwResp['ledger_addr']
    ?? $gwResp['stage_2_ledger']['address']
    ?? $gwResp['ledger_target']
    ?? $gwResp['ledger_address']
    ?? ''
));
if ($ledgerAddr === '' && defined('LEDGER_TRC20_ADDRESS')) {
    $ledgerAddr = trim((string) LEDGER_TRC20_ADDRESS);
}
if ($ledgerAddr === '') {
    $ledgerAddr = '—';
}

$ledgerTxid = $txn['ledger_txid']
    ?? $gwSettle['txid']
    ?? $gwResp['ledger_txid']
    ?? $gwResp['stage_2_ledger']['txid']
    ?? null;

$usdtRaw = (float)(
    $txn['ledger_amount']
    ?? $gwSettle['usdt_amount']
    ?? $gwResp['stage_2_ledger']['usdt_amount']
    ?? $gwResp['usdt_amount']
    ?? 0
);
$usdtAmt = number_format($usdtRaw, 6);

$gatewayFeeAmt = (float)(
    $txn['fees']
    ?? $feeInfo['fee_amount']
    ?? $gwResp['fee_amount']
    ?? 0
);
$feeBase = (float)($feeInfo['fee_base'] ?? 0);
$feeMult = (float)($feeInfo['fee_multiplier'] ?? (getenv('GATEWAY_FEE_MULTIPLIER') ?: 2));
$feePct = $feeInfo['fee_percentage'] ?? $gwResp['fee_percentage'] ?? null;

$netAmt = (float)(
    $txn['net_amount']
    ?? $gwSettle['net_fiat']
    ?? $gwResp['net_amount']
    ?? max(0, (float)($txn['amount'] ?? 0) - $gatewayFeeAmt)
);

$ledgerStatus = strtolower((string)(
    $txn['ledger_status']
    ?? $gwSettle['message']
    ?? ''
));
$settleTarget = strtolower(trim((string) (
    $gwSettle['settlement_target']
    ?? $gwResp['settlement_target']
    ?? ''
)));
$receiptSettlement = pos_receipt_settlement_display(
    (string) ($txn['gateway'] ?? ''),
    (string) ($txn['transaction_type'] ?? ''),
    pos_receipt_status($txn),
    $settleTarget
);
$fundsOnGateway = $receiptSettlement['funds_on_gateway']
    || (!empty($receiptSettlement['show']) && !empty($gwSettle['retained']));
if ($fundsOnGateway) {
    $ledgerStatusLabel = 'ON GATEWAY';
} elseif ($ledgerTxid) {
    $ledgerStatusLabel = 'SENT';
} elseif (!empty($gwSettle['queued']) || $ledgerStatus === 'queued') {
    $ledgerStatusLabel = 'QUEUED';
} elseif (!empty($receiptSettlement['show_ledger']) && ($usdtRaw > 0 || $netAmt > 0)) {
    $ledgerStatusLabel = 'PENDING';
} else {
    $ledgerStatusLabel = '';
}

$showSettlementSection = !empty($receiptSettlement['show']);
$showLedgerSection = $showSettlementSection && !empty($receiptSettlement['show_ledger']);


$clearOrDash = static function ($value): string {
    $s = trim((string) $value);
    return ($s === '' || $s === '—') ? '—' : $s;
};
$seal = static function ($value): string {
    return pos_receipt_seal((string) $value);
};
$maskedCardholderName = $seal($cardholderName);
$maskedCardExpiry = $cardExpiry !== '—' ? $seal($cardExpiry) : '—';
$maskedEmail = $customerEmail !== '—' ? $seal($customerEmail) : '—';
$maskedPhone = $customerPhone !== '—' ? $seal($customerPhone) : '—';
$clearAuthCode = $clearOrDash(pos_approval_receipt_label($authCode === '—' ? '' : $authCode));
$clearBankApprovalCode = $clearOrDash($bankApprovalCode);
$clearRrn = $clearOrDash($rrn);
$stanDigits = preg_replace('/\D/', '', (string) $stan) ?? '';
$maskedStan = $stanDigits !== '' ? str_pad(substr($stanDigits, -6), 6, '0', STR_PAD_LEFT) : date('His');
$receiptTid = trim((string) ($txn['terminal_id'] ?? $gwResp['terminal_id'] ?? $gwResp['tid'] ?? ''));
if ($receiptTid === '') {
    $receiptTid = '16526256';
}
$receiptBatchDigits = preg_replace('/\D/', '', (string) ($txn['batch'] ?? $gwResp['batch'] ?? $gwResp['batch_number'] ?? '')) ?? '';
$receiptBatch = $receiptBatchDigits !== '' ? substr($receiptBatchDigits, -6) : date('dmy');
$clearPaymentId = $clearOrDash($paymentId);
$clearInternalApproval = $clearOrDash($internalApprovalCode);
$maskedLedgerAddr = $ledgerAddr !== '—' ? $seal($ledgerAddr) : '—';
$sealedDate = $seal($dateStr);
$sealedTime = $seal($timeStr);
$sealedRef = $seal($ref);
$sealedMerchant = $seal($merchantName);
$sealedMerchantId = $seal($merchantId);
$sealedGatewayName = $seal($gatewayDisplayName);
$sealedMcc = $seal($merchantCategory);
$sealedCity = $seal($merchantCity . ', ' . $merchantCountry);
$sealedGateway = $seal($gateway);
$sealedSecMode = $seal($secMode);
$sealedAuthType = $seal($authType);
$sealedCardBrand = $seal($cardBrand . ' ' . $cardType);
$sealedCardLast4 = $seal($cardLast4);
$sealedAddress = $seal('Al Barsha 1, Dubai, UAE | diparmas.com');
$sealedLedgerTxid = $ledgerTxid ? $seal($ledgerTxid) : '';

$hangReason = function_exists('diparma_transaction_hang_reason')
    ? diparma_transaction_hang_reason($txn)
    : ['ar' => '', 'en' => '', 'still_pending' => false, 'live_status' => ''];

// 6.10 تحديد الحالة النهائية
$receiptTxn = $txn;
$receiptTxn['transaction_type'] = $txn['transaction_type'] ?? $txn['transaction_label'] ?? $gwResp['type'] ?? '';
$statusText = pos_receipt_status($receiptTxn);
$isApproved = in_array($statusText, ['APPROVED', 'SUCCESS'], true);
$isAuthorized = $statusText === 'AUTHORIZED';
$isPending = $statusText === 'PENDING';
$isWithdraw = $statusText === 'SUCCESS';
$declineReason = '';
$successNote = '';
$cardUseAlert = ['card_use' => '', 'card_use_ar' => '', 'card_use_en' => ''];
if ($isApproved || $isAuthorized) {
    $successNote = $isWithdraw
        ? ($ar ? 'تم السحب بنجاح' : 'Withdrawal completed')
        : ($isAuthorized
            ? ($ar ? 'تم حجز المبلغ ولم يتم تحصيله بعد' : 'Authorized hold — not captured yet')
            : ($ar ? 'تمت العملية بنجاح' : 'Transaction approved'));
} else {
    $declineReason = function_exists('pos_receipt_decline_reason')
        ? pos_receipt_decline_reason($txn, $gwResp)
        : trim((string) ($gwResp['status_message'] ?? $gwResp['decline_reason'] ?? $txn['status'] ?? 'DECLINED'));
    if (!empty($gwResp['card_use_ar']) || !empty($gwResp['card_use_en'])) {
        $cardUseAlert = [
            'card_use' => (string) ($gwResp['card_use'] ?? ''),
            'card_use_ar' => (string) ($gwResp['card_use_ar'] ?? ''),
            'card_use_en' => (string) ($gwResp['card_use_en'] ?? ''),
        ];
    } else {
        $cardUseAlert = pos_card_use_alert($declineReason, (string) $cardLast4);
    }
}
$statusColor = $isApproved ? '#16a34a' : (($isPending || $isAuthorized) ? '#d97706' : '#dc2626');
$statusBg = $isApproved ? '#ecfdf5' : (($isPending || $isAuthorized) ? '#fffbeb' : '#fef2f2');
$statusIcon = $isApproved ? '✅' : ($isAuthorized ? '🔒' : ($isPending ? '⏳' : '❌'));

// 6.11 نوع المعاملة — بدون عرض رموز 101 / 201
$rawTxnType = (string)($txn['transaction_type'] ?? $txn['transaction_label'] ?? '');
if ($rawTxnType === '' && !empty($txn['protocol'])) {
    $rawTxnType = function_exists('protocol_display_name')
        ? protocol_display_name((string)$txn['protocol'])
        : 'PURCHASE';
}
$showOperatorRefs = false;
$receiptBankRrn = $firstNonEmpty([
    $txn['bank_rrn'] ?? '',
    $gwResp['bank_rrn'] ?? '',
    $gwResp['retrieval_reference_number'] ?? '',
    (preg_match('/^\d{12}$/', (string) ($txn['rrn'] ?? '')) ? $txn['rrn'] : ''),
    (preg_match('/^\d{12}$/', (string) ($gwResp['rrn'] ?? '')) ? $gwResp['rrn'] : ''),
]);
$receiptBankApproval = $firstNonEmpty([
    $txn['bank_approval_code'] ?? '',
    $bankApprovalCode,
]);
if ($receiptBankApproval === '' && $internalApprovalCode === '' && trim((string) ($gwResp['gateway_approval_code'] ?? '')) === '') {
    $receiptBankApproval = $firstNonEmpty([$txn['approval_code'] ?? '', $authCode]);
}
$receiptGatewayPayment = $firstNonEmpty([
    $receiptIds['payment_id'] ?? '',
    $gwResp['payment_id'] ?? '',
    $gwResp['transaction_id'] ?? '',
    $paymentId,
]);
$receiptGatewayApproval = $firstNonEmpty([
    $gwResp['gateway_approval_code'] ?? '',
    $gatewayResult['gateway_approval_code'] ?? '',
    $txn['gateway_approval_code'] ?? '',
    $internalApprovalCode,
]);
$txnType = strtoupper(str_replace('_', ' ', $rawTxnType !== '' ? $rawTxnType : 'PURCHASE'));
$txnType = trim((string) preg_replace('/\s+/', ' ', str_ireplace('MOTO', '', $txnType)));
if ($txnType === '') {
    $txnType = 'SALE';
}
if (function_exists('redact_protocol_numbers')) {
    $txnType = redact_protocol_numbers($txnType);
}

// ============================================================
// 7. عرض الإيصال الحقيقي
// PayPal POS: نسخة التاجر الحرارية. باقي البوابات تبقى على الإيصال العام.
// ============================================================

if ($txnGateway === 'paypal') {
    require_once __DIR__ . '/pos/lib/paypal_merchant_receipt.php';
    echo pos_paypal_merchant_receipt_document($txn, is_array($gwResp) ? $gwResp : [], $ar, $ref);
    exit;
}

?>
<!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$dir?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DI PARMA | <?=htmlspecialchars($gatewayDisplayName)?> Receipt - <?=htmlspecialchars($ref)?></title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ============================================================
           STYLES - إيصال احترافي يشبه POS
           ============================================================ */
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'JetBrains Mono', 'Cairo', monospace, sans-serif;
            background: #e8e8e8;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 20px;
        }
        
        /* حاوية الإيصال */
        .receipt-wrapper {
            width: 100%;
            max-width: 400px;
        }
        
        /* أزرار الإجراءات */
        .actions {
            display: flex;
            gap: 10px;
            margin-bottom: 16px;
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .actions button,
        .actions a {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-family: 'Cairo', sans-serif;
            font-size: 0.85rem;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .btn-print {
            background: #1a1a2e;
            color: #FFD700;
        }
        .btn-print:hover {
            background: #000;
            transform: scale(1.02);
        }
        
        .btn-share {
            background: #0077b6;
            color: #fff;
        }
        .btn-share:hover {
            background: #005f8a;
            transform: scale(1.02);
        }
        
        .btn-back {
            background: #fff;
            color: #333;
            border: 1px solid #ddd;
        }
        .btn-back:hover {
            background: #f5f5f5;
            transform: scale(1.02);
        }
        
        .btn-download {
            background: #16a34a;
            color: #fff;
        }
        .btn-download:hover {
            background: #15803d;
            transform: scale(1.02);
        }
        
        /* ============================================================
           الإيصال نفسه - تصميم POS حقيقي
           ============================================================ */
        
        .receipt {
            background: #ffffff;
            width: 100%;
            border-radius: 6px;
            box-shadow: 0 4px 30px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            font-family: 'JetBrains Mono', 'Courier New', monospace;
            color: #000;
            font-size: 12px;
            line-height: 1.6;
            position: relative;
        }
        
        /* رأس الإيصال */
        .receipt-header {
            background: #000;
            color: #FFD700;
            text-align: center;
            padding: 20px 20px 14px;
            border-bottom: 3px solid #FFD700;
        }
        
        .receipt-header .logo {
            font-size: 26px;
            font-weight: 900;
            letter-spacing: 4px;
            font-family: 'Cairo', sans-serif;
        }
        
        .receipt-header .subtitle {
            font-size: 10px;
            letter-spacing: 3px;
            color: rgba(255, 215, 0, 0.7);
            margin-top: 2px;
        }
        
        .receipt-header .company {
            font-size: 10px;
            color: rgba(255, 255, 255, 0.7);
            margin-top: 4px;
            letter-spacing: 1px;
        }
        
        .receipt-header .address {
            font-size: 9px;
            color: rgba(255, 255, 255, 0.4);
            margin-top: 2px;
        }
        
        /* خط فاصل */
        .divider {
            text-align: center;
            padding: 4px 0;
            color: #ccc;
            font-size: 11px;
            letter-spacing: 3px;
            border-bottom: 1px dashed #ddd;
        }
        
        /* حالة المعاملة */
        .status-section {
            text-align: center;
            padding: 14px 20px;
            border-bottom: 2px dashed #ddd;
            background: <?=$statusBg?>;
        }
        
        .status-badge {
            display: inline-block;
            padding: 8px 30px;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 900;
            letter-spacing: 2px;
            border: 2px solid <?=$statusColor?>;
            color: <?=$statusColor?>;
            background: #fff;
        }
        
        .status-badge .icon {
            margin-right: 8px;
        }
        
        .status-type {
            font-size: 10px;
            color: #888;
            margin-top: 6px;
            letter-spacing: 1px;
            font-family: 'Cairo', sans-serif;
        }
        
        /* الأقسام */
        .section {
            padding: 12px 18px;
            border-bottom: 1px dashed #e5e5e5;
        }
        
        .section:last-child {
            border-bottom: none;
        }
        
        .section-title {
            font-size: 9px;
            font-weight: 700;
            color: #999;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-bottom: 6px;
            text-align: center;
        }
        
        /* صفوف البيانات */
        .row {
            display: flex;
            justify-content: space-between;
            padding: 2px 0;
            font-size: 11px;
        }
        
        .row .label {
            color: #777;
            font-weight: 400;
        }
        
        .row .value {
            font-weight: 700;
            text-align: right;
            max-width: 65%;
            word-break: break-all;
            font-family: 'JetBrains Mono', monospace;
        }
        
        .row .value.highlight {
            color: <?=$statusColor?>;
        }
        
        .row .value.small {
            font-size: 9.5px;
        }
        
        /* مربع المبلغ */
        .amount-box {
            background: #fafafa;
            border: 2px solid #000;
            border-radius: 6px;
            padding: 14px;
            text-align: center;
            margin: 6px 0;
        }
        
        .amount-box .label {
            font-size: 9px;
            color: #999;
            letter-spacing: 2px;
            text-transform: uppercase;
            font-weight: 600;
        }
        
        .amount-box .value {
            font-size: 32px;
            font-weight: 900;
            color: #000;
            letter-spacing: 1px;
            line-height: 1.2;
        }
        
        .amount-box .currency {
            font-size: 16px;
            font-weight: 700;
            color: #666;
        }
        
        /* تفاصيل البطاقة */
        .card-details {
            background: #f8f9fa;
            border-radius: 4px;
            padding: 10px 14px;
            margin: 4px 0;
            border-left: 4px solid <?=$statusColor?>;
        }
        
        /* تفاصيل البنك */
        .bank-details {
            background: #f0f4ff;
            border-radius: 4px;
            padding: 10px 14px;
            margin: 4px 0;
            border-left: 4px solid #0066cc;
        }
        
        .bank-details .bank-row {
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            padding: 1px 0;
        }
        
        .bank-details .bank-label {
            color: #666;
        }
        
        .bank-details .bank-value {
            font-weight: 600;
            text-align: right;
            word-break: break-all;
        }
        
        /* تفاصيل العملات الرقمية */
        .crypto-details {
            background: #f0fdf4;
            border-radius: 4px;
            padding: 10px 14px;
            margin: 4px 0;
            border-left: 4px solid #16a34a;
        }
        
        .crypto-details .crypto-label {
            font-size: 9px;
            font-weight: 700;
            color: #16a34a;
            letter-spacing: 1px;
            margin-bottom: 4px;
        }
        
        .crypto-details .crypto-addr {
            font-size: 9px;
            word-break: break-all;
            color: #333;
        }
        
        .crypto-details .crypto-txid {
            font-size: 8.5px;
            color: #0066cc;
            word-break: break-all;
            margin-top: 3px;
        }
        
        .crypto-details .crypto-txid a {
            color: #0066cc;
            text-decoration: none;
        }
        .crypto-details .crypto-txid a:hover {
            text-decoration: underline;
        }
        
        /* تذييل الإيصال */
        .receipt-footer {
            background: #1a1a2e;
            color: rgba(255, 255, 255, 0.6);
            text-align: center;
            padding: 16px 20px;
            font-size: 9px;
            line-height: 2;
        }
        
        .receipt-footer strong {
            color: #FFD700;
        }
        
        .receipt-footer .footer-small {
            font-size: 8px;
            opacity: 0.5;
        }
        
        /* شريط القطع (مثل إيصالات POS) */
        .cut-line {
            text-align: center;
            padding: 6px 0;
            color: #ccc;
            font-size: 12px;
            letter-spacing: 5px;
            border-top: 2px dashed #ddd;
            border-bottom: 2px dashed #ddd;
            margin: 0 18px;
        }
        
        /* ============================================================
           طباعة
           ============================================================ */
        
        @media print {
            body {
                background: #fff !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            
            .receipt-wrapper {
                max-width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            
            .receipt {
                box-shadow: none !important;
                border-radius: 0 !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            
            .actions {
                display: none !important;
            }
            
            .no-print {
                display: none !important;
            }
            
            @page {
                margin: 0;
                size: 80mm auto;
            }
            
            .receipt-footer {
                page-break-inside: avoid;
            }
        }
        
        /* ============================================================
           استجابة
           ============================================================ */
        
        @media (max-width: 480px) {
            .receipt {
                border-radius: 0;
            }
            
            .actions {
                gap: 6px;
            }
            
            .actions button,
            .actions a {
                font-size: 0.75rem;
                padding: 8px 14px;
            }
        }
    </style>
</head>
<body>

<div class="receipt-wrapper">

    <!-- ============================================================
         أزرار الإجراءات (لا تظهر في الطباعة)
         ============================================================ -->
    <div class="actions no-print">
        <button class="btn-print" onclick="window.print()">
            <i class="fas fa-print"></i> طباعة
        </button>
        <button class="btn-download" onclick="downloadReceipt()">
            <i class="fas fa-download"></i> تحميل PDF
        </button>
        <button class="btn-share" onclick="shareReceipt()">
            <i class="fas fa-share-alt"></i> مشاركة
        </button>
        <a href="dashboard.php" class="btn-back">
            <i class="fas fa-arrow-<?=$ar?'right':'left'?>"></i> رجوع
        </a>
    </div>

    <!-- ============================================================
         الإيصال - تصميم POS حقيقي
         ============================================================ -->
    <div class="receipt" id="receipt">

        <!-- رأس الإيصال -->
        <div class="receipt-header">
            <div class="logo">DI PARMA</div>
            <div class="subtitle">✦ <?=htmlspecialchars(strtoupper($gatewayDisplayName))?> PAYMENT RECEIPT ✦</div>
            <div class="company"><?=htmlspecialchars($sealedMerchant)?></div>
            <div class="address"><?=htmlspecialchars($sealedAddress)?></div>
        </div>

        <!-- حالة المعاملة -->
        <div class="status-section">
            <div class="status-badge">
                <span class="icon"><?=$statusIcon?></span>
                <?=$statusText?>
            </div>
            <div class="status-type">
                <?=htmlspecialchars($txnType)?>
            </div>
            <?php if (($isApproved || $isAuthorized) && $successNote !== ''): ?>
            <div style="margin-top:10px;font-size:12px;font-weight:800;color:<?=$isAuthorized?'#92400e':'#065f46'?>;line-height:1.45"><?=htmlspecialchars($successNote)?></div>
            <?php endif; ?>
            <?php if ($isPending && (($ar ? ($hangReason['ar'] ?? '') : ($hangReason['en'] ?? '')) !== '')): ?>
            <div style="margin-top:10px;font-size:11px;font-weight:800;color:#92400e;line-height:1.45">
                <?= htmlspecialchars($ar ? ($hangReason['ar'] ?? '') : ($hangReason['en'] ?? '')) ?>
                <?php if (!empty($hangReason['live_status'])): ?>
                    <div style="margin-top:4px;color:#78350f">Live: <?= htmlspecialchars((string) $hangReason['live_status']) ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($statusText === 'DECLINED'): ?>
            <?php
                $cardUseLine = $ar
                    ? (string) ($cardUseAlert['card_use_ar'] ?? '')
                    : (string) ($cardUseAlert['card_use_en'] ?? '');
            ?>
            <?php if ($declineReason !== ''): ?>
            <div style="margin-top:10px;font-size:11px;font-weight:800;color:#991b1b;line-height:1.45"><?=htmlspecialchars($declineReason)?></div>
            <?php endif; ?>
            <?php if ($cardUseLine !== ''): ?>
            <div style="margin-top:8px;padding:8px;border:1px dashed <?=(($cardUseAlert['card_use'] ?? '') === 'can_use') ? '#065f46' : '#991b1b'?>;background:#fff;color:<?=(($cardUseAlert['card_use'] ?? '') === 'can_use') ? '#065f46' : '#991b1b'?>;font-size:11px;font-weight:800;line-height:1.45"><?=htmlspecialchars($cardUseLine)?></div>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php
            $slipRrn = trim((string) ($txn['rrn'] ?? ''));
            if ($slipRrn === '' || $slipRrn === '—') {
                $slipRrn = trim((string) ($rrn ?? ''));
            }
            if ($slipRrn === '—') {
                $slipRrn = '';
            }
            $slipApprovalRaw = trim((string) ($txn['approval_code'] ?? $txn['auth_code'] ?? ''));
            if ($slipApprovalRaw === '' || $slipApprovalRaw === '—') {
                $slipApprovalRaw = ($authCode ?? '') === '—' ? '' : trim((string) ($authCode ?? ''));
            }
            $slipApproval = function_exists('pos_approval_receipt_label')
                ? pos_approval_receipt_label($slipApprovalRaw)
                : $slipApprovalRaw;
            if ($slipApproval === '—') {
                $slipApproval = '';
            }
            $showSlipIds = $slipRrn !== '' || $slipApproval !== '';
        ?>
        <?php if ($showSlipIds): ?>
        <div class="section">
            <div class="row">
                <span class="label">RRN</span>
                <span class="value small"><?=htmlspecialchars($slipRrn)?></span>
            </div>
            <div class="row">
                <span class="label">APPROVAL CODE</span>
                <span class="value highlight"><?=htmlspecialchars($slipApproval)?></span>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($showOperatorRefs): ?>
        <div class="section">
            <div class="divider">— <?=$ar?'البنك':'BANK'?> —</div>
            <div class="row">
                <span class="label"><?=$ar?'RRN للبنك':'RRN for the bank'?></span>
                <span class="value small"><?=htmlspecialchars((string) $receiptBankRrn)?></span>
            </div>
            <div class="row">
                <span class="label"><?=$ar?'Approval Code للبنك':'Approval Code for the bank'?></span>
                <span class="value highlight"><?=htmlspecialchars((string) $receiptBankApproval)?></span>
            </div>
            <div class="divider">— <?=$ar?'بوابة الدفع':'PAYMENT GATEWAY'?> —</div>
            <div class="row">
                <span class="label"><?=$ar?'Payment ID لبوابة الدفع':'Payment ID for the payment gateway'?></span>
                <span class="value small"><?=htmlspecialchars((string) $receiptGatewayPayment)?></span>
            </div>
            <div class="row">
                <span class="label"><?=$ar?'Approval Code لبوابة الدفع':'Approval Code for the payment gateway'?></span>
                <span class="value highlight"><?=htmlspecialchars((string) $receiptGatewayApproval)?></span>
            </div>
        </div>
        <?php endif; ?>

        <!-- التاريخ والمرجع -->
        <div class="section">
            <div class="row">
                <span class="label">DATE / TIME</span>
                <span class="value"><?=htmlspecialchars($sealedDate)?> <?=htmlspecialchars($sealedTime)?></span>
            </div>
            <div class="row">
                <span class="label">TID</span>
                <span class="value"><?=htmlspecialchars($receiptTid)?></span>
            </div>
            <div class="row">
                <span class="label">MID</span>
                <span class="value">DIPARMA</span>
            </div>
            <div class="row">
                <span class="label">BATCH</span>
                <span class="value"><?=htmlspecialchars($receiptBatch)?></span>
            </div>
            <div class="row">
                <span class="label">STAN</span>
                <span class="value small"><?=htmlspecialchars($maskedStan)?></span>
            </div>
        </div>

        <!-- المبلغ -->
        <div class="section">
            <div class="amount-box">
                <div class="label">TOTAL AMOUNT</div>
                <div class="value"><?=$amount?> <span class="currency"><?=$currency?></span></div>
            </div>
        </div>

        <!-- تفاصيل البطاقة -->
        <div class="section">
            <div class="divider">— CARD DETAILS —</div>
            <div class="card-details">
                <div class="row">
                    <span class="label">CARD</span>
                    <span class="value"><?=htmlspecialchars($sealedCardBrand)?></span>
                </div>
                <div class="row">
                    <span class="label">CARD NUMBER</span>
                    <span class="value">•••• •••• •••• <?=htmlspecialchars(substr((string)$cardLast4, -4) ?: '••••')?></span>
                </div>
                <?php if ($cardholderName && $cardholderName !== '—'): ?>
                <div class="row">
                    <span class="label">CARDHOLDER</span>
                    <span class="value"><?=htmlspecialchars($maskedCardholderName)?></span>
                </div>
                <?php endif; ?>
                <?php if ($cardExpiry !== '—'): ?>
                <div class="row">
                    <span class="label">EXPIRY</span>
                    <span class="value"><?=htmlspecialchars($maskedCardExpiry)?></span>
                </div>
                <?php endif; ?>
                <div class="row">
                    <span class="label">CVV / CVC</span>
                    <span class="value small">NOT DISPLAYED</span>
                </div>
                <div class="row">
                    <span class="label">AUTH CODE</span>
                    <span class="value highlight"><?=htmlspecialchars($clearAuthCode)?></span>
                </div>
                <div class="row">
                    <span class="label">INTERNAL APPROVAL CODE</span>
                    <span class="value small"><?=htmlspecialchars($clearInternalApproval)?></span>
                </div>
                <div class="row">
                    <span class="label">AUTH TYPE</span>
                    <span class="value"><?=htmlspecialchars($sealedAuthType)?></span>
                </div>
            </div>
        </div>

        <?php if ($customerEmail !== '—' || $customerPhone !== '—'): ?>
        <div class="section">
            <div class="divider">— CUSTOMER CONTACT —</div>
            <?php if ($customerEmail !== '—'): ?>
            <div class="row">
                <span class="label">EMAIL</span>
                <span class="value small"><?=htmlspecialchars($maskedEmail)?></span>
            </div>
            <?php endif; ?>
            <?php if ($customerPhone !== '—'): ?>
            <div class="row">
                <span class="label">PHONE</span>
                <span class="value small"><?=htmlspecialchars($maskedPhone)?></span>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- تفاصيل بوابة الدفع والتاجر -->
        <div class="section">
            <div class="divider">— MERCHANT —</div>
            <div class="row">
                <span class="label">MERCHANT</span>
                <span class="value"><?=htmlspecialchars($sealedMerchant)?></span>
            </div>
            <div class="row">
                <span class="label">MERCHANT ID</span>
                <span class="value"><?=htmlspecialchars($sealedMerchantId)?></span>
            </div>
            <div class="row">
                <span class="label">MCC</span>
                <span class="value"><?=htmlspecialchars($sealedMcc)?></span>
            </div>
            <div class="row">
                <span class="label">CITY / COUNTRY</span>
                <span class="value"><?=htmlspecialchars($sealedCity)?></span>
            </div>
        </div>

        <?php if ($showSettlementSection): ?>
        <div class="section">
            <div class="divider">— SETTLEMENT DESTINATION —</div>
            <div class="bank-details">
                <div class="bank-row">
                    <span class="bank-label">PATH</span>
                    <span class="bank-value"><?=htmlspecialchars($receiptSettlement['path'])?></span>
                </div>
                <div class="bank-row">
                    <span class="bank-label">TARGET</span>
                    <span class="bank-value"><?=htmlspecialchars($receiptSettlement['target'])?></span>
                </div>
                <div class="bank-row">
                    <span class="bank-label">BANK PAYOUT</span>
                    <span class="bank-value">DISABLED</span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (false && ($showLedgerSection || !empty($fundsOnGateway))): ?>
        <div class="section">
            <div class="divider"><?= !empty($fundsOnGateway) ? '— GATEWAY SETTLEMENT —' : '— CRYPTO LEDGER SETTLEMENT —' ?></div>
            <div class="crypto-details">
                <div class="crypto-label"><?= !empty($fundsOnGateway) ? '⬡ FIAT RETAINED ON ' . htmlspecialchars(strtoupper($receiptSettlement['gateway_name'])) : '⬡ USDT TRC20 → LEDGER' ?></div>
                <?php if ($showLedgerSection): ?>
                <div class="crypto-addr"><?=htmlspecialchars($maskedLedgerAddr)?></div>
                <?php endif; ?>
                <?php if ($gatewayFeeAmt > 0 || $feeBase > 0): ?>
                <div class="row" style="margin-top:4px">
                    <span class="label">GATEWAY FEE BASE<?=$feePct !== null ? ' ('.$feePct.'%)' : ''?></span>
                    <span class="value"><?=number_format($feeBase > 0 ? $feeBase : ($gatewayFeeAmt / max($feeMult, 1)), 4)?> <?=htmlspecialchars((string)($txn['currency'] ?? 'USD'))?></span>
                </div>
                <div class="row">
                    <span class="label">FEE ×<?=rtrim(rtrim(number_format($feeMult, 2), '0'), '.')?></span>
                    <span class="value"><?=number_format($gatewayFeeAmt, 4)?> <?=htmlspecialchars((string)($txn['currency'] ?? 'USD'))?></span>
                </div>
                <?php endif; ?>
                <?php if ($netAmt > 0): ?>
                <div class="row">
                    <span class="label">NET (FIAT)</span>
                    <span class="value"><?=number_format($netAmt, 4)?> <?=htmlspecialchars((string)($txn['currency'] ?? 'USD'))?></span>
                </div>
                <?php endif; ?>
                <?php if ($usdtRaw > 0 && empty($fundsOnGateway)): ?>
                <div class="row" style="margin-top:4px">
                    <span class="label">SENT TO LEDGER</span>
                    <span class="value highlight"><?=$usdtAmt?> USDT</span>
                </div>
                <?php endif; ?>
                <?php if ($ledgerStatusLabel !== ''): ?>
                <div class="row">
                    <span class="label">TRANSFER STATUS</span>
                    <span class="value"><?=htmlspecialchars($ledgerStatusLabel)?></span>
                </div>
                <?php endif; ?>
                <?php if ($sealedLedgerTxid !== ''): ?>
                <div class="crypto-txid">
                    TX: <?=htmlspecialchars($sealedLedgerTxid)?>
                </div>
                <?php elseif ($ledgerStatusLabel === 'QUEUED' || $ledgerStatusLabel === 'PENDING'): ?>
                <div style="font-size:9px;color:#999;margin-top:4px">⏳ Transfer: <?=htmlspecialchars($ledgerStatusLabel)?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- شريط القطع (مثل إيصالات POS) -->
        <div class="cut-line">✂ — — — — — — — — — — — — — — — — ✂</div>

        <!-- تذييل الإيصال -->
        <div class="receipt-footer">
            <strong>✓ THANK YOU FOR YOUR BUSINESS ✓</strong><br>
            Powered by DI PARMA Gateway © <?=date('Y')?><br>
            This is an official payment receipt<br>
            <span class="footer-small">Keep for your records — <?=htmlspecialchars($sealedRef)?></span><br>
            <span class="footer-small">Transactions are subject to verification</span>
        </div>

    </div><!-- /receipt -->

    <!-- حقوق النشر -->
    <div style="text-align:center;margin-top:12px;font-size:0.7rem;color:#999;font-family:'Cairo',sans-serif" class="no-print">
        <a href="https://diparmas.com" style="color:#999;text-decoration:none">diparmas.com</a> &bull; 
        <span id="datetime"></span>
    </div>

</div><!-- /receipt-wrapper -->

<!-- ============================================================
     JavaScript
     ============================================================ -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
<script>
    // عرض الوقت الحالي
    document.getElementById('datetime').textContent = new Date().toLocaleString('ar-AE', {
        timeZone: 'Asia/Dubai',
        hour12: false
    });

    // وظيفة المشاركة
    function shareReceipt() {
        const url = window.location.href;
        if (navigator.share) {
            navigator.share({
                title: 'DI PARMA Receipt - <?=htmlspecialchars($ref)?>',
                url: url
            }).catch(() => {});
        } else {
            navigator.clipboard?.writeText(url).then(() => {
                alert('✅ تم نسخ رابط الإيصال');
            }).catch(() => {
                // نسخ يدوي
                const input = document.createElement('input');
                input.value = url;
                document.body.appendChild(input);
                input.select();
                document.execCommand('copy');
                document.body.removeChild(input);
                alert('✅ تم نسخ رابط الإيصال');
            });
        }
    }

    // وظيفة تحميل PDF (طباعة مع حفظ)
    function downloadReceipt() {
        // يمكن استخدام window.print() لحفظ كـ PDF
        window.print();
    }

    // اختصار لوحة المفاتيح: Ctrl+P للطباعة
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
            // السماح بالطباعة العادية
        }
    });
</script>

</body>
</html>
<?php
exit;
?>