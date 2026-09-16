<?php
/**
 * DI PARMA | بدء صفحة بوابة مستقلة
 * كل بوابة تفتح صفحتها الخاصة وتعرض كل عمليات الشراء المعيارية.
 *
 * الاستخدام من checkout/xxx.php:
 *   $gwCode = 'paypal';
 *   require __DIR__ . '/../includes/start_gateway_checkout.php';
 */

if (!defined('DI_PARMA_CHECKOUT')) {
    define('DI_PARMA_CHECKOUT', true);
}

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/pos_operations.php';
require_once __DIR__ . '/gateways.php';

if (empty($gwCode)) {
    header('Location: ' . (isset($checkoutBase) ? $checkoutBase : '') . 'checkout_router.php');
    exit;
}

$gwCode = strtolower(trim((string)$gwCode));

// منع الوصول المباشر لصفحة بوابة غير مفعّلة أو غير متصلة أو بلا مفاتيح
try {
    $gwRow = db()->find('payment_gateways', ['code' => $gwCode]);
    if (!$gwRow || !isGatewayVisibleInCheckout(array_merge($gwRow, ['code' => $gwCode]))) {
        header('Location: ' . (isset($checkoutBase) ? $checkoutBase : '') . 'checkout_router.php?error=gateway_not_ready');
        exit;
    }
} catch (Throwable $e) {
    header('Location: ' . (isset($checkoutBase) ? $checkoutBase : '') . 'checkout_router.php?error=gateway_not_ready');
    exit;
}

$GATEWAY_META = [
    'diparma'    => ['name' => 'DI PARMA',         'icon' => 'fas fa-coins',           'color' => '#FFD700', 'currencies' => ['USD','AED','EUR','GBP','SAR']],
    'diparma_gateway' => ['name' => 'DIPARMA GATEWAY', 'icon' => 'fas fa-credit-card',  'color' => '#E8C547', 'currencies' => ['USD','AED','EUR','GBP','SAR','KWD','QAR','EGP','USDT']],
    'nuvei'      => ['name' => 'Nuvei',             'icon' => 'fas fa-credit-card',     'color' => '#F97316', 'currencies' => ['USD','AED','EUR','GBP','SAR']],
    'stripe'     => ['name' => 'Stripe',            'icon' => 'fab fa-stripe-s',        'color' => '#6772e5', 'currencies' => ['USD','EUR','GBP','AED']],
    'square'     => ['name' => 'Square',            'icon' => 'fas fa-square',          'color' => '#006AFF', 'currencies' => ['USD','EUR','GBP','CAD','AUD']],
    'paypal'     => ['name' => 'PayPal',            'icon' => 'fab fa-paypal',          'color' => '#003087', 'currencies' => ['USD','EUR','GBP','AED']],
    'wise'       => ['name' => 'Wise',              'icon' => 'fas fa-exchange-alt',    'color' => '#9fe870', 'currencies' => ['USD','EUR','GBP','AED']],
    'myfatoorah' => ['name' => 'MyFatoorah',        'icon' => 'fas fa-money-bill-wave', 'color' => '#00b09b', 'currencies' => ['KWD','SAR','AED','BHD','QAR','USD']],
    'binance'    => ['name' => 'Binance',           'icon' => 'fas fa-coins',           'color' => '#F3BA2F', 'currencies' => ['USDT','USD','EUR']],
    'gate_io'    => ['name' => 'Gate.io',           'icon' => 'fas fa-coins',           'color' => '#E8112D', 'currencies' => ['USDT','USD']],
    'mashreq'    => ['name' => 'Mashreq Bank',      'icon' => 'fas fa-university',      'color' => '#FF6600', 'currencies' => ['AED','USD','EUR','GBP']],
    'hsbc_uae'   => ['name' => 'HSBC UAE',          'icon' => 'fas fa-university',      'color' => '#DB0011', 'currencies' => ['AED','USD','EUR','GBP']],
    'nbe_egypt'  => ['name' => 'NBE Egypt',         'icon' => 'fas fa-landmark',        'color' => '#006633', 'currencies' => ['EGP','USD','EUR']],
    'jpmorgan'   => ['name' => 'JP Morgan Chase',   'icon' => 'fas fa-landmark',        'color' => '#003087', 'currencies' => ['USD','EUR','GBP']],
    'whop'       => ['name' => 'Whop',              'icon' => 'fas fa-bolt',            'color' => '#7C3AED', 'currencies' => ['USD','EUR']],
    'payram'     => ['name' => 'PayRam',            'icon' => 'fas fa-server',          'color' => '#10B981', 'currencies' => ['USDT','USD']],
];

$meta = $GATEWAY_META[$gwCode] ?? [
    'name' => strtoupper($gwCode),
    'icon' => 'fas fa-credit-card',
    'color' => '#FFD700',
    'currencies' => ['USD','EUR','GBP','AED'],
];

$gwName     = $gwName     ?? $meta['name'];
$gwIcon     = $gwIcon     ?? $meta['icon'];
$gwColor    = $gwColor    ?? $meta['color'];
$currencies = $currencies ?? $meta['currencies'];
$csrfToken  = $csrfToken  ?? generateCsrfToken();
$checkoutOps = pos_operation_catalog();
$chargeGwCode = $chargeGwCode ?? $gwCode;

// PayRam = Card-to-Crypto onramp فقط (صفحة PayRam المستضافة / Base)
// https://docs.payram.com/features/card-to-crypto-fiat-onramp
if ($gwCode === 'payram') {
    $fullOps = pos_operation_catalog();
    $purchase = $fullOps['purchase_3d'] ?? reset($fullOps);
    $purchase['ar'] = 'بطاقة → كريبتو';
    $purchase['en'] = 'Card → Crypto';
    $purchase['desc_ar'] = 'Onramp عبر PayRam Wallet — البطاقة داخل صفحة PayRam (Base)';
    $purchase['desc_en'] = 'PayRam Wallet onramp — card on PayRam hosted page (Base)';
    $purchase['requires_card'] = false;
    $purchase['requires_cvv'] = false;
    $purchase['requires_otp'] = false;
    $checkoutOps = ['purchase' => $purchase];
    $currencies = ['USD', 'EUR', 'GBP', 'AED'];
}

if ($gwCode === 'diparma_gateway') {
    $fullOps = pos_operation_catalog();
    $checkoutOps = $fullOps;
    if (isset($fullOps['purchase_3d'])) {
        $checkoutOps = ['purchase_3d' => $fullOps['purchase_3d']] + $fullOps;
    }
    $currencies = ['USD', 'AED', 'EUR', 'GBP', 'SAR', 'KWD', 'QAR', 'EGP', 'USDT'];
    require_once __DIR__ . '/pos_gateways.php';
    $liveCharge = function_exists('pos_live_gateways') ? pos_live_gateways() : [];
    if (isset($liveCharge['diparma'])) {
        $chargeGwCode = 'diparma';
    } elseif (isset($liveCharge['nuvei'])) {
        $chargeGwCode = 'nuvei';
    } else {
        $liveKeys = array_keys($liveCharge);
        $chargeGwCode = $liveKeys[0] ?? '';
    }
}

// مسار نسبي للروابط (من داخل /checkout أو من الجذر)
$basePath = $checkoutBase ?? '';
if ($basePath === '' && strpos(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/checkout/') !== false) {
    $basePath = '../';
}

// استعلام اختياري من الراوتر
$prefillAmount   = floatval($_GET['amount'] ?? 0);
$prefillCurrency = strtoupper(trim((string)($_GET['currency'] ?? ($currencies[0] ?? 'USD'))));
$prefillDest     = 'ledger';
$prefillWallet   = trim((string)($_GET['wallet'] ?? ''));
if ($prefillWallet === '' && defined('LEDGER_TRC20_ADDRESS')) {
    $prefillWallet = (string) LEDGER_TRC20_ADDRESS;
}
$prefillOp = '';
if (function_exists('pos_normalize_operation')) {
    $prefillOp = pos_normalize_operation((string)($_GET['op'] ?? $_GET['txn_type'] ?? ''));
}
$activityLine = strtolower(trim((string)($_GET['line'] ?? '')));
if ($gwCode === 'diparma_gateway' || $prefillDest === 'ledger') {
    $prefillDest = 'ledger';
    if (defined('LEDGER_TRC20_ADDRESS')) {
        $prefillWallet = (string) LEDGER_TRC20_ADDRESS;
    }
}
$prefillRef      = trim((string)($_GET['ref'] ?? ''));
$prefillLink     = trim((string)($_GET['link'] ?? ''));
// MY POS channels: checkout | link | web — all feed the same POS transaction pipe
$checkoutChannel = $checkoutChannel ?? ($prefillLink !== '' ? 'link' : 'checkout');
if (!in_array($checkoutChannel, ['checkout', 'link', 'web'], true)) {
    $checkoutChannel = 'checkout';
}

// Stripe publishable key إن وُجد
if ($gwCode === 'stripe' && empty($stripeKey)) {
    $stripeKey = getenv('STRIPE_PUBLISHABLE_KEY') ?: (getenv('STRIPE_PK') ?: '');
    try {
        $row = db()->find('payment_gateways', ['code' => 'stripe']);
        $creds = json_decode($row['credentials'] ?? '{}', true) ?: [];
        if (!empty($creds['publishable_key'])) {
            $stripeKey = $creds['publishable_key'];
        }
    } catch (Throwable $e) {}
}

// Square Web Payments SDK
$squareSdk = [
    'application_id' => '',
    'location_id' => '',
    'environment' => 'production',
    'live' => false,
    'script_url' => '',
    'ready' => false,
];
if ($gwCode === 'square') {
    require_once __DIR__ . '/square_sdk.php';
    $squareSdk = square_sdk_config();
}

require __DIR__ . '/../checkout_template.php';
