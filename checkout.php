<?php
// Legacy entry point retained as a simple alias to the public checkout router.
header('Location: checkout_router.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
exit();

/**
 * DI PARMA | Checkout â€” ط¥ط¯ط®ط§ظ„ ط§ظ„ط¨ط·ط§ظ‚ط© + ط´ط±ط§ط، USDT
 * ط§ظ„طھط¯ظپظ‚: ط¨ظٹط§ظ†ط§طھ ط§ظ„ط¨ط·ط§ظ‚ط© â†’ RiskEngine â†’ KYC â†’ CardPayment â†’ Webhook â†’ USDT
 */
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/gateways.php';
require_once __DIR__ . '/includes/crypto_schema.php';
require_once __DIR__ . '/lib/ExchangeRateService.php';
require_once __DIR__ . '/lib/KYCService.php';
require_once __DIR__ . '/lib/RiskEngine.php';
// lang.php ظ…ظڈط­ظ…ظژظ‘ظ„ ظ…ظ† auth_check.php طھظ„ظ‚ط§ط¦ظٹط§ظ‹

dp_create_crypto_tables();
RiskEngine::ensureTables();

$userId     = intval($_SESSION['user_id'] ?? 0);
$db         = db();
$csrfToken  = generateCsrfToken();
// ط§ظ„ظ„ط؛ط© ظ…ظ† auth_check.php (ظ…ط­ظ…ظ‘ظ„ ظ…ط³ط¨ظ‚ط§ظ‹)
if (!isset($currentLang)) {
    $currentLang = (isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'ar') ? 'ar' : 'en';
    $pageDir     = $currentLang === 'en' ? 'ltr' : 'rtl';
}

// â”€â”€ ط­ط§ظ„ط© KYC â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$kyc = KYCService::getInstance()->getStatus($userId);

// â”€â”€ ط§ظ„ط³ط¹ط± ط§ظ„ط­ط§ظ„ظٹ â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$rates = [];
try {
    $fx = ExchangeRateService::getInstance();
    foreach (['USDT','BTC','ETH','BNB','TRX'] as $coin) {
        $rates[$coin] = $fx->getRate($coin, 'AED');
    }
} catch (Exception $e) {}

// â”€â”€ ط¨ظˆط§ط¨ط§طھ ط§ظ„ط¯ظپط¹ â€” ظ„ط§ ظٹظˆط¬ط¯ ط§ظپطھط±ط§ط¶ظٹطŒ ط§ظ„ظ…ط³طھط®ط¯ظ… ظٹط®طھط§ط± â”€â”€â”€â”€â”€
$stripePublic = getenv('STRIPE_PUBLIC_KEY') ?: '';
$cardProvider = ''; // ظ„ط§ ط¨ظˆط§ط¨ط© ظ…ط®طھط§ط±ط© ط§ظپطھط±ط§ط¶ظٹط§ظ‹

// â”€â”€ ط¬ظ„ط¨ ط§ظ„ط¨ظˆط§ط¨ط§طھ ط§ظ„ظ†ط´ط·ط© ظپظ‚ط· ظ…ظ† ط¬ط¯ظˆظ„ ط§ظ„ط¨ظˆط§ط¨ط§طھ ط§ظ„ظ…ظڈط¯ط§ط± ظ…ظ† ظ‚ظگط¨ظ„ ظ…ط´ط±ظپ ط§ظ„ظ†ط¸ط§ظ…
$gatewayRows = $db->query(
    "SELECT code, name, type, status, config, credentials, settings,
            connection_status, gateway_type, supports_2d, supports_3d,
            supports_hold, supports_capture
     FROM dp_payment_gateways
     WHERE LOWER(status) = 'active'
       AND LOWER(connection_status) = 'verified'
       AND LOWER(type) IN ('electronic', 'bank', 'wallet')
       AND code NOT IN ('integrated','crypto_deposit')
     ORDER BY
       CASE type
         WHEN 'electronic' THEN 1
         WHEN 'bank'       THEN 2
         WHEN 'wallet'     THEN 3
         ELSE 4
       END,
       sort_order ASC, name ASC"
);

$activeGateways = [];
foreach ($gatewayRows as $row) {
    $code = trim(strtolower($row['code'] ?? ''));
    if ($code === '') {
        continue;
    }

    // âœ… ط§ظ„ط¨ظˆط§ط¨ط© verified = ط§ط®طھط¨ط§ط±ظ‡ط§ ظ†ط¬ط­ = طھظڈط¶ط§ظپ ظ…ط¨ط§ط´ط±ط©
    $cfg = json_decode($row['config'] ?? '{}', true) ?: [];
    $activeGateways[] = [
        'code'             => $code,
        'name'             => $row['name'],
        'type'             => $row['type'],
        'gateway_type'     => $row['gateway_type']     ?? 'card',
        'supports_2d'      => intval($row['supports_2d']      ?? 0),
        'supports_3d'      => intval($row['supports_3d']      ?? 0),
        'supports_hold'    => intval($row['supports_hold']     ?? 0),
        'supports_capture' => intval($row['supports_capture']  ?? 0),
        'config'           => json_encode($cfg),
    ];
}

// ط£ظٹظ‚ظˆظ†ط§طھ ظˆط£ظ„ظˆط§ظ† ظ„ظƒظ„ ط¨ظˆط§ط¨ط© ظˆظ…ط­ظپط¸ط©
$gatewayMeta = [
    // ط¨ظˆط§ط¨ط§طھ ط¯ظپط¹
    'myfatoorah'     => ['icon'=>'fas fa-money-bill-wave',    'color'=>'#00b09b'],
    'stripe'         => ['icon'=>'fab fa-stripe-s',           'color'=>'#6772e5'],
    'wise'           => ['icon'=>'fas fa-exchange-alt',       'color'=>'#9fe870'],
    'paypal'         => ['icon'=>'fab fa-paypal',             'color'=>'#003087'],
    'moonpay'        => ['icon'=>'fas fa-moon',               'color'=>'#7b56e8'],
    'transak'        => ['icon'=>'fas fa-rocket',             'color'=>'#1a73e8'],
    'banxa'          => ['icon'=>'fas fa-coins',              'color'=>'#f4a100'],
    'mercuryo'       => ['icon'=>'fas fa-globe',              'color'=>'#00c2ff'],
    'simplex'        => ['icon'=>'fas fa-credit-card',        'color'=>'#2ecc71'],
    'ramp'           => ['icon'=>'fas fa-arrow-up-right-dots','color'=>'#ff6b35'],
    'checkout'       => ['icon'=>'fas fa-shopping-cart',      'color'=>'#0070df'],
    // Custodial Wallets
    'binance'        => ['icon'=>'fab fa-bitcoin',            'color'=>'#f3ba2f'],
    'coinbase_ex'    => ['icon'=>'fas fa-circle-dollar-sign', 'color'=>'#0052ff'],
    'kraken'         => ['icon'=>'fas fa-anchor',             'color'=>'#5741d9'],
    'bybit'          => ['icon'=>'fas fa-chart-line',         'color'=>'#f7a600'],
    'okx'            => ['icon'=>'fas fa-coins',              'color'=>'#333333'],
    'kucoin'         => ['icon'=>'fas fa-share-nodes',        'color'=>'#23af91'],
    'gate_io'        => ['icon'=>'fas fa-door-open',          'color'=>'#e8112d'],
    'whop'           => ['icon'=>'fas fa-bolt',               'color'=>'#4F46E5'],
    'gemini'         => ['icon'=>'fas fa-gem',                'color'=>'#00dcfa'],
    'bitfinex'       => ['icon'=>'fas fa-infinity',           'color'=>'#16b157'],
    'mexc'           => ['icon'=>'fas fa-coins',              'color'=>'#2354e6'],
    // Non-Custodial Wallets
    'trust_wallet'   => ['icon'=>'fas fa-shield-halved',      'color'=>'#3375bb'],
    'metamask'       => ['icon'=>'fas fa-fox',                'color'=>'#f6851b'],
    'phantom'        => ['icon'=>'fas fa-ghost',              'color'=>'#ab9ff2'],
    'ledger_live'    => ['icon'=>'fas fa-hard-drive',         'color'=>'#555555'],
    'exodus'         => ['icon'=>'fas fa-door-closed',        'color'=>'#0b46f9'],
    'electrum'       => ['icon'=>'fab fa-bitcoin',            'color'=>'#1a9ed4'],
    'coinbase_wallet'=> ['icon'=>'fas fa-wallet',             'color'=>'#0052ff'],
    'zengo'          => ['icon'=>'fas fa-key',                'color'=>'#5a4fff'],
    'rabby'          => ['icon'=>'fas fa-shield-alt',         'color'=>'#7a7cff'],
    'safepal'        => ['icon'=>'fas fa-shield',             'color'=>'#444444'],
];

// ط¥ط°ط§ ظ„ظ… طھظˆط¬ط¯ ط¨ظˆط§ط¨ط§طھ ظ†ط´ط·ط© ظپط¹ظ„ظٹط§ظ‹ â€” ظ„ط§ طھط¹ط±ط¶ ط£ظٹ ط¨ظˆط§ط¨ط© ط§ظپطھط±ط§ط¶ظٹط©
if (empty($activeGateways)) {
    $activeGateways = [];
}

// طھط£ظƒط¯ ط£ظ† cardProvider ظ…ظˆط¬ظˆط¯ ظپظٹ ط§ظ„ظ†ط´ط·ط©
$validCodes = array_column($activeGateways, 'code');
if (!in_array($cardProvider, $validCodes, true) && !empty($validCodes)) {
    $cardProvider = $validCodes[0];
} else {
    $cardProvider = '';
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | <?= __('checkout') ?></title>
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<!-- Always load Stripe JS if key exists -->
<?php if (!empty($stripePublic)): ?>
<script src="https://js.stripe.com/v3/"></script>
<?php endif; ?>
<!-- PayPal SDK v6 â€” ظٹظڈط­ظ…ظژظ‘ظ„ ط¨ط´ظƒظ„ ط؛ظٹط± ظ…طھط²ط§ظ…ظ† ظ„ط§ ظٹط¹ط·ظ„ JS -->
<?php
// PayPal â€” ظ†ط­ط³ط¨ ط§ظ„ظ…طھط؛ظٹط±ط§طھ ظ‚ط¨ظ„ ط§ظ„ظ€ script
$_ppClientId = addslashes(getenv('PAYPAL_CLIENT_ID') ?: '');
$_ppLocale   = (($currentLang ?? 'ar') === 'en') ? 'en-US' : 'ar-AE';
?>
<?php if (!empty($_ppClientId)): ?>
<script>
window.addEventListener('load', function() {
    var s = document.createElement('script');
    s.src = 'https://www.paypal.com/web-sdk/v6/core';
    s.async = true;
    s.onload = function() {
        if(typeof window.paypal === 'undefined') return;
        try {
            window.paypal.createInstance({
                clientId: '<?= $_ppClientId ?>',
                components: ['paypal-payments'],
                pageType: 'checkout',
                locale: '<?= $_ppLocale ?>'
            }).then(function(inst){ window.paypalSDKInstance = inst; })
              .catch(function(e){ console.warn('PayPal SDK:', e); });
        } catch(e){ console.warn('PayPal SDK init:', e); }
    };
    s.onerror = function(){ console.warn('PayPal SDK failed to load'); };
    document.head.appendChild(s);
});
</script>
<?php endif; ?>
<!-- MyFatoorah JS SDK -->
<?php
$mfEnv = getenv('MYFAOORAH_ENVIRONMENT') ?: (defined('APP_ENV') && APP_ENV === 'production' ? 'live' : '');
$mfJsUrl = $mfEnv === 'live'
    ? 'https://portal.myfatoorah.com/Files/API/myfatoorah.js'
    : 'https://portal.myfatoorah.com/Files/API/myfatoorah.js';
?>
<script>
window.addEventListener('load', function() {
    var mf = document.createElement('script');
    mf.src = '<?= $mfJsUrl ?>';
    mf.async = true;
    document.head.appendChild(mf);
});
</script>
<!-- Checkout.com Frames -->
<?php if (!empty(getenv('CHECKOUT_PUBLIC_KEY'))): ?>
<script>
window.addEventListener('load', function() {
    var ck = document.createElement('script');
    ck.src = 'https://cdn.checkout.com/js/framesv2.min.js';
    ck.async = true;
    document.head.appendChild(ck);
});
</script>
<?php endif; ?>
<style>
.checkout-wrap { max-width:920px; margin:32px auto; padding:0 20px;
    display:grid; grid-template-columns:1fr 360px; gap:28px; }
.co-card { background:var(--bg-card); border:1px solid var(--border-gold);
    border-radius:20px; padding:28px; }
.field-wrap { margin-bottom:18px; }
.field-wrap label { display:block; color:var(--text-muted); font-size:.82rem; margin-bottom:7px; }
.field-wrap input, .field-wrap select {
    width:100%; padding:13px 16px;
    background:rgba(255,255,255,.04);
    border:1.5px solid var(--border-gold);
    border-radius:11px; color:var(--text-light);
    font-size:.95rem; outline:none; transition:border-color .2s; }
.field-wrap input:focus, .field-wrap select:focus { border-color:var(--gold); }
.field-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.card-icons { display:flex; gap:8px; margin-bottom:20px; }
.card-icon { width:42px; height:28px; border-radius:5px; display:flex;
    align-items:center; justify-content:center; font-size:.75rem; font-weight:800; }
.order-row { display:flex; justify-content:space-between;
    padding:9px 0; border-bottom:1px solid var(--border-light); font-size:.88rem; }
.order-row:last-child { border-bottom:none; font-weight:700; color:var(--gold); font-size:1rem; }
.pay-btn { width:100%; padding:16px; border-radius:14px; border:none; cursor:pointer;
    font-size:1.05rem; font-weight:800; background:var(--gold-gradient); color:#000;
    box-shadow:var(--shadow-gold); transition:all .3s; letter-spacing:.3px; }
.pay-btn:disabled { opacity:.5; cursor:not-allowed; }
.pay-btn:hover:not(:disabled) { transform:translateY(-1px); box-shadow:var(--shadow-gold-hover); }
.security-row { display:flex; align-items:center; gap:8px; margin-top:14px;
    color:var(--text-muted); font-size:.78rem; }
.stripe-element { padding:14px 16px; background:rgba(255,255,255,.04);
    border:1.5px solid var(--border-gold); border-radius:11px; }
.net-pill { padding:6px 16px; border-radius:20px; border:1.5px solid var(--border-gold);
    background:transparent; color:var(--text-muted); cursor:pointer;
    font-size:.82rem; font-weight:600; transition:all .2s; }
.net-pill.active { border-color:var(--gold); color:var(--gold); background:rgba(255,215,0,.08); }
.kyc-banner { background:rgba(240,173,78,.08); border:1px solid rgba(240,173,78,.25);
    border-radius:12px; padding:14px 18px; margin-bottom:20px; }
.step-indicator { display:flex; gap:0; margin-bottom:28px; }
.step { flex:1; text-align:center; font-size:.78rem; color:var(--text-muted);
    padding-bottom:8px; border-bottom:2px solid rgba(255,255,255,.1); }
.step.active { color:var(--gold); border-bottom-color:var(--gold); font-weight:700; }
.step.done   { color:#4CAF50; border-bottom-color:#4CAF50; }
@media(max-width:768px){ .checkout-wrap{grid-template-columns:1fr} .field-row{grid-template-columns:1fr} }
</style>

<script>
// ?????? DI PARMA | Early Function Definitions ??????
// ?????????????? ???????????? ?????? ???????? ?????????? ?????? ?????? onclick events

function selectGateway(code){
    var gwPages = {
        'stripe':        'checkout_stripe.php',
        'paypal':        'checkout_paypal.php',
        'wise':          'checkout_wise.php',
        'myfatoorah':    'checkout_myfatoorah.php',
        'binance':       'checkout_binance.php',
        'gate_io':       'checkout_gate.php',
        'whop':          'checkout_whop.php',
        'nuvei':         'checkout_nuvei.php',
        'hsbc_uae':      'checkout_HSBCbankUUUAE.php',
        'nbe_egypt':     'checkout_NBEbank.php',
        'bank_transfer': 'checkout_HSBCbankUUUAE.php',
        'bank':          'checkout_HSBCbankUUUAE.php',
        'hsbc':          'checkout_HSBCbankUUUAE.php',
    };
    if (gwPages[code]) {
        window.location.href = gwPages[code];
        return;
    }
    // fallback: highlight only
    document.querySelectorAll('.gw-option').forEach(function(el){
        el.style.borderColor='rgba(255,215,0,0.25)';
        el.style.background='transparent';
    });
    var sel = document.getElementById('gw_'+code);
    if(sel){ sel.style.borderColor='var(--gold)'; sel.style.background='rgba(255,215,0,0.06)'; }
    var ci = document.getElementById('cardProviderInput');
    if(ci) ci.value = code;
}

function setPurchaseType(protocol, type){
    var pi = document.getElementById('protocolInput');
    var ta = document.getElementById('transactionAction');
    if(pi) pi.value = protocol;
    if(ta) ta.value = type;
    document.querySelectorAll('.purchase-type-btn').forEach(function(b){ b.classList.remove('active'); });
    var btn = document.getElementById('pt_'+type);
    if(btn) btn.classList.add('active');
    // ??????????/?????????? ???????????? ?????? ??????????
    var moto = document.getElementById('motoFields');
    var refund = document.getElementById('refundFields');
    var card = document.getElementById('cardInputFields');
    if(moto){
        moto.style.display = (type==='offline'||type==='capture'||type==='online_moto') ? '' : 'none';
    }
    if(refund){
        refund.style.display = (type==='refund'||type==='avoid') ? '' : 'none';
    }
    if(card){
        card.style.display = (type==='direct'||type==='hold') ? '' : 'none';
    }
}

function setSecureMode(mode){
    var sm = document.getElementById('secureModeInput');
    if(sm) sm.value = mode;
    var o2 = document.getElementById('opt2D');
    var o3 = document.getElementById('opt3D');
    if(o2) o2.classList.toggle('selected', mode==='2D');
    if(o3) o3.classList.toggle('selected', mode==='3D');
    // 3D: Stripe elements | 2D: manual card
    var sw = document.getElementById('stripeElementWrap');
    var ci = document.getElementById('cardInputFields');
    if(mode==='3D'){
        if(sw) sw.style.display='';
        if(ci) ci.style.display='none';
        if(typeof initStripeElements==='function') initStripeElements();
    } else {
        if(sw) sw.style.display='none';
        if(ci) ci.style.display='';
    }
}
</script>

<script>
var RATES       = <?= json_encode(array_map(function($r){return['final_rate'=>$r['final_rate']??0,'rate'=>$r['rate']??0,'_fiat'=>'AED'];},$rates),JSON_UNESCAPED_UNICODE) ?>;
var CSRF_TOKEN  = '<?= $csrfToken ?>';
var STRIPE_KEY  = '<?= addslashes($stripePublic) ?>';

// â”€â”€ Stripe â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
var stripe=null,stripeEl=null,stripeReady=false;
function initStripe(){
    if(stripeReady||!STRIPE_KEY||typeof Stripe==='undefined')return;
    stripe=Stripe(STRIPE_KEY);
    var els=stripe.elements();
    stripeEl=els.create('card',{style:{base:{color:'#fff',fontFamily:'Cairo,sans-serif',fontSize:'15px','::placeholder':{color:'#888'}},invalid:{color:'#ef5350'}},hidePostalCode:true});
    stripeEl.mount('#stripe-card-element');
    stripeEl.on('change',function(e){var el=document.getElementById('stripe-error');if(el)el.textContent=e.error?e.error.message:'';});
    stripeReady=true;
}

// â”€â”€ selectGateway â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function selectGateway(code){
    var gwPages = {
        'stripe':     'checkout_stripe.php',
        'paypal':     'checkout_paypal.php',
        'wise':       'checkout_wise.php',
        'myfatoorah': 'checkout_myfatoorah.php',
        'binance':    'checkout_binance.php',
        'gate_io':    'checkout_gate.php',
        'whop':       'checkout_whop.php',
        'nuvei':      'checkout_nuvei.php',
        'hsbc_uae':   'checkout_HSBCbankUUUAE.php',
        'nbe_egypt':  'checkout_NBEbank.php',
        'bank_transfer': 'checkout_HSBCbankUUUAE.php',
        'bank':       'checkout_HSBCbankUUUAE.php',
        'hsbc':       'checkout_HSBCbankUUUAE.php',
    };
    if (gwPages[code]) {
        window.location.href = gwPages[code];
        return;
    }

    document.getElementById('cardProviderInput').value=code;
    var GW={myfatoorah:'#00b09b',stripe:'#6772e5',wise:'#9fe870',paypal:'#003087',moonpay:'#7b56e8',transak:'#1a73e8',banxa:'#f4a100',mercuryo:'#00c2ff',simplex:'#2ecc71',ramp:'#ff6b35',checkout:'#0070df',paytabs:'#02a0db',authorizenet:'#2ea44f',binance:'#f3ba2f',coinbase_ex:'#0052ff',kraken:'#5741d9',bybit:'#f7a600',okx:'#333',kucoin:'#23af91',gate_io:'#e8112d',gemini:'#00dcfa',bitfinex:'#16b157',mexc:'#2354e6',trust_wallet:'#3375bb',metamask:'#f6851b',phantom:'#ab9ff2',ledger_live:'#555',exodus:'#0b46f9',electrum:'#1a9ed4',coinbase_wallet:'#0052ff',zengo:'#5a4fff',rabby:'#7a7cff',safepal:'#444'};
    document.querySelectorAll('.gw-option').forEach(function(el){el.style.borderColor='rgba(255,215,0,0.25)';el.style.background='transparent';});
    var sel=document.getElementById('gw_'+code);
    if(sel){sel.style.borderColor=GW[code]||'var(--gold)';sel.style.background='rgba(255,215,0,0.06)';}
    var si=document.getElementById('stripeElementWrap'),mf=document.getElementById('myfatoorahFields'),ci=document.getElementById('cardInputFields'),nt=document.getElementById('redirectNotice'),nm=document.getElementById('redirectMsg'),btn=document.getElementById('payBtn'),cn=document.getElementById('chooseGatewayNotice'),mo=document.getElementById('motoFields');

    // ط¨ظˆط§ط¨ط§طھ ط§ظ„ظ…ط­ط§ظپط¸
    var WALLETS=['binance','coinbase_ex','kraken','bybit','okx','kucoin','gate_io','gemini','bitfinex','mexc','trust_wallet','metamask','phantom','ledger_live','exodus','electrum','coinbase_wallet','zengo','rabby','safepal'];
    // ط¨ظˆط§ط¨ط§طھ ظ„ظ‡ط§ redirect ط®ط§طµ â€” طھظڈط®ظپظٹ Security Level
    var REDIRECT_GW=['wise','moonpay','transak','banxa','mercuryo','simplex','ramp'];
    // ط¬ظ…ظٹط¹ ط¨ظˆط§ط¨ط§طھ ط§ظ„ط¨ط·ط§ظ‚ط§طھ ط§ظ„ظ…ط¨ط§ط´ط±ط© â€” طھط¸ظ‡ط± 2D/3D
    var CARD_GW=['stripe','checkout','paytabs','authorizenet','myfatoorah','braintree','paypal'];
    // NO_SEC = ط¨ظˆط§ط¨ط§طھ ظ„ط§ طھط­طھط§ط¬ security level
    var NO_SEC=REDIRECT_GW.concat(WALLETS);

    if(si)si.style.display='none';if(mf)mf.style.display='none';if(nt)nt.style.display='none';
    if(ci)ci.style.display='none';if(cn)cn.style.display='none';if(mo)mo.style.display='none';

    // ط¥ط®ظپط§ط،/ط¥ط¸ظ‡ط§ط± Security Level
    var secLvl=document.getElementById('securityLevelSection');
    var purchaseType=document.getElementById('transactionAction')?document.getElementById('transactionAction').value:'direct';
    var showSec=CARD_GW.indexOf(code)!==-1 && purchaseType==='direct';
    if(secLvl)secLvl.style.display=showSec?'':'none';

    // ط¹ط±ط¶ ط§ظ„ط­ظ‚ظˆظ„ ط§ظ„ظ…ظ†ط§ط³ط¨ط© ظ„ظƒظ„ ط¨ظˆط§ط¨ط©
    var currentMode=document.getElementById('securityMode')?document.getElementById('securityMode').value:'3D';
    if(code==='stripe'){
        if(currentMode==='2D'){
            // 2D + Stripe â†’ ط­ظ‚ظˆظ„ ظٹط¯ظˆظٹط©
            if(ci)ci.style.display='';
            if(si)si.style.display='none';
            if(btn)btn.innerHTML='<i class="fas fa-lock"></i> ط§ط¯ظپط¹ ط§ظ„ط¢ظ† (2D)';
        } else {
            // 3D + Stripe â†’ Stripe Element
            if(si){si.style.display='';initStripe();}
            if(ci)ci.style.display='none';
            if(btn)btn.innerHTML='<i class="fas fa-lock"></i> Pay with Stripe';
        }
    } else if(code==='myfatoorah'){
        if(mf)mf.style.display='';
        if(btn)btn.innerHTML='<i class="fas fa-lock"></i> ط§ط¯ظپط¹ ط¹ط¨ط± MyFatoorah';
    } else if(code==='paypal'||code==='braintree'){
        // PayPal/Braintree â†’ ط¯ظپط¹ ظ…ط¨ط§ط´ط± ط¨ط§ظ„ط¨ط·ط§ظ‚ط© ط¨ط¯ظˆظ† redirect
        if(ci)ci.style.display='';
        if(btn)btn.innerHTML='<i class="fab fa-paypal"></i> ط§ط¯ظپط¹ ط¨ط¨ط·ط§ظ‚طھظƒ (ظ…ط¨ط§ط´ط±)';
    } else if(WALLETS.indexOf(code)!==-1){
        if(nt){nt.style.display='';nm.textContent='ط¹ظ†ظˆط§ظ† ط§ظ„ظ…ط­ظپط¸ط© ط³ظٹط³طھظ‚ط¨ظ„ USDT ظ…ط¨ط§ط´ط±ط©.';}
        if(btn)btn.innerHTML='<i class="fas fa-wallet"></i> طھط£ظƒظٹط¯ ط§ظ„طھط­ظˆظٹظ„';
    } else if(REDIRECT_GW.indexOf(code)!==-1){
        if(nt){nt.style.display='';nm.textContent='ط³ظٹطھظ… طھط­ظˆظٹظ„ظƒ ظ„ط¥طھظ…ط§ظ… ط§ظ„ط¯ظپط¹ ط¹ط¨ط± '+code+'.';}
        if(btn)btn.innerHTML='<i class="fas fa-external-link-alt"></i> ظ…طھط§ط¨ط¹ط© ط§ظ„ط¯ظپط¹';
    } else if(code==='checkout'){
        if(ci)ci.style.display='';
        if(btn)btn.innerHTML='<i class="fas fa-lock"></i> ط§ط¯ظپط¹ ط¹ط¨ط± Checkout.com';
    } else if(code==='paytabs'){
        if(ci)ci.style.display='';
        if(btn)btn.innerHTML='<i class="fas fa-lock"></i> ط§ط¯ظپط¹ ط¹ط¨ط± PayTabs';
    } else if(code==='authorizenet'){
        if(ci)ci.style.display='';
        if(btn)btn.innerHTML='<i class="fas fa-lock"></i> ط§ط¯ظپط¹ ط¹ط¨ط± Authorize.Net';
    } else {
        if(ci)ci.style.display='';
        if(btn)btn.innerHTML='<i class="fas fa-lock"></i> ط§ط¯ظپط¹ ط§ظ„ط¢ظ†';
    }
}

// â”€â”€ setPurchaseType â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function setPurchaseType(protocol,type){
    document.getElementById('protocolInput').value=protocol;
    document.getElementById('transactionAction').value=type;
    var T={
        direct:  {color:'var(--gold)', id:'pt_direct',  bg:'rgba(255,215,0,.08)',  desc:'ط´ط±ط§ط، ظ…ط¨ط§ط´ط± â€” ط®طµظ… ظپظˆط±ظٹ ظˆط¥ط±ط³ط§ظ„ USDT', btn:'<i class="fas fa-bolt"></i> ط´ط±ط§ط، ظ…ط¨ط§ط´ط± ط§ظ„ط¢ظ†'},
        hold:    {color:'#5bc0de',     id:'pt_hold',    bg:'rgba(91,192,222,.08)', desc:'ط­ط¬ط² (HOLD 101.1) â€” ط§ظ„ظ…ط¨ظ„ط؛ ظ…ط­ط¬ظˆط² ظˆظ„ظ… ظٹظڈط®طµظ…', btn:'<i class="fas fa-hand-holding-usd"></i> ط­ط¬ط² ط§ظ„ظ…ط¨ظ„ط؛'},
        capture: {color:'#9fe870',     id:'pt_capture', bg:'rgba(159,232,112,.08)',desc:'طھط³ظˆظٹط© (CAPTURE 101.1) â€” طھط­طµظٹظ„ ظ…ط¨ظ„ط؛ ظ…ط­ط¬ظˆط²', btn:'<i class="fas fa-check-double"></i> طھط³ظˆظٹط©'},
        offline: {color:'#f0ad4e',     id:'pt_offline', bg:'rgba(240,173,78,.08)', desc:'Offline Sales (201.3 MOTO) â€” ط¨ظٹط¹ ط®ظ„ظپظٹ', btn:'<i class="fas fa-server"></i> Offline Sale'},
        crypto:  {color:'#f7a600',     id:'pt_crypto',  bg:'rgba(247,166,0,.08)',  desc:'ط´ط±ط§ط، ط¨ط§ظ„ط¹ظ…ظ„ط§طھ ط§ظ„ط±ظ‚ظ…ظٹط© â€” BTC/ETH/USDT', btn:'<i class="fab fa-bitcoin"></i> ط§ط¯ظپط¹ ط¨ط§ظ„ظƒط±ظٹط¨طھظˆ'},
        avoid:   {color:'#888',        id:'pt_avoid',   bg:'rgba(136,136,136,.08)',desc:'Avoid â€” طھط¬ظ…ظٹط¯ ط§ظ„ط¹ظ…ظ„ظٹط© ظ…ط¤ظ‚طھط§ظ‹', btn:'<i class="fas fa-ban"></i> Avoid'},
        refund:  {color:'#5bc0de',     id:'pt_refund',  bg:'rgba(91,192,222,.08)', desc:'Refund â€” ط§ط³طھط±ط¯ط§ط¯ ط§ظ„ظ…ط¨ظ„ط؛ ظ„ظ„ط¹ظ…ظٹظ„', btn:'<i class="fas fa-undo"></i> Refund'}
    };
    var curr=T[type]||T.direct;
    Object.values(T).forEach(function(pt){var el=document.getElementById(pt.id);if(el){el.style.borderColor='rgba(255,215,0,0.25)';el.style.background='transparent';}});
    var selEl=document.getElementById(curr.id);if(selEl){selEl.style.borderColor=curr.color;selEl.style.background=curr.bg;}
    var desc=document.getElementById('purchaseTypeDesc');if(desc)desc.innerHTML='<i class="fas fa-info-circle" style="color:var(--gold)"></i> '+curr.desc;
    var btn=document.getElementById('payBtn');
    var saved=document.getElementById('selectedSavedCard');
    if(btn&&(!saved||!saved.value))btn.innerHTML=curr.btn;
    var aw=document.getElementById('authIdWrap');if(aw)aw.style.display=(type==='capture')?'':'none';
    var mo=document.getElementById('motoFields');if(mo)mo.style.display=(type==='offline')?'':'none';
    var cc=document.getElementById('cryptoCoinSelect');if(cc)cc.style.display=(type==='crypto')?'':'none';
    var rr=document.getElementById('refundRefWrap');if(rr)rr.style.display=(type==='refund')?'':'none';
    var ci=document.getElementById('cardInputFields');if(ci)ci.style.display=(type==='offline'||type==='crypto'||type==='avoid')?'none':'';

    // Security Level: ظٹط¸ظ‡ط± ظپظ‚ط· ط¹ظ†ط¯ ط´ط±ط§ط، ظ…ط¨ط§ط´ط± + ط¨ظˆط§ط¨ط§طھ ط§ظ„ط¨ط·ط§ظ‚ط§طھ
    // 101.1 â†’ 3D طھظ„ظ‚ط§ط¦ظٹط§ظ‹طŒ 201.3 â†’ 2D طھظ„ظ‚ط§ط¦ظٹط§ظ‹
    var secSection=document.getElementById('securityLevelSection');
    var currentGw=document.getElementById('cardProviderInput')?document.getElementById('cardProviderInput').value:'';
    var CARD_GW_P=['stripe','checkout','paytabs','authorizenet','myfatoorah','braintree','paypal'];
    var NO_SEC_P=['wise','moonpay','transak','banxa','mercuryo','simplex','ramp'];
    if(secSection){
        var canShowSec=CARD_GW_P.indexOf(currentGw)!==-1&&NO_SEC_P.indexOf(currentGw)===-1;
        if(type==='direct'&&canShowSec){
            secSection.style.display='';
        } else {
            secSection.style.display='none';
            // ط¶ط¨ط· طھظ„ظ‚ط§ط¦ظٹ ط­ط³ط¨ ط§ظ„ط¨ط±ظˆطھظˆظƒظˆظ„
            if(type==='offline'){
                setSecureMode('2D'); // 201.3 MOTO = 2D ط¯ط§ط¦ظ…ط§ظ‹
            } else {
                setSecureMode('3D'); // 101.1 = 3D ط¯ط§ط¦ظ…ط§ظ‹
            }
        }
    }
}

// â”€â”€ setSecureMode â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function setSecureMode(mode){
    document.getElementById('securityMode').value=mode;
    var o3=document.getElementById('opt3D'),o2=document.getElementById('opt2D'),sm=document.getElementById('summarySecureMode');
    var si=document.getElementById('stripeElementWrap');
    var ci=document.getElementById('cardInputFields');
    var provider=document.getElementById('cardProviderInput')?document.getElementById('cardProviderInput').value:'';
    var CARD_GW=['stripe','checkout','paytabs','authorizenet','braintree','paypal'];

    if(mode==='3D'){
        if(o3){o3.style.borderColor='var(--gold)';o3.style.background='rgba(255,215,0,.08)';}
        if(o2){o2.style.borderColor='rgba(255,215,0,0.25)';o2.style.background='transparent';}
        if(sm){sm.textContent='3D Secure âœ“';sm.style.color='var(--gold)';}
        // 3D + Stripe â†’ ط£ط¹ط¯ Stripe ElementطŒ ط£ط®ظپظگ ط§ظ„ط­ظ‚ظˆظ„ ط§ظ„ظٹط¯ظˆظٹط©
        if(provider==='stripe'){
            if(si)si.style.display='';
            if(ci)ci.style.display='none';
            initStripe();
        } else if(CARD_GW.indexOf(provider)!==-1){
            if(si)si.style.display='none';
            if(ci)ci.style.display='';
        }
    } else {
        if(o2){o2.style.borderColor='#5bc0de';o2.style.background='rgba(91,192,222,.08)';}
        if(o3){o3.style.borderColor='rgba(255,215,0,0.25)';o3.style.background='transparent';}
        if(sm){sm.textContent='2D (No OTP)';sm.style.color='#5bc0de';}
        // 2D â†’ ط£ط¸ظ‡ط± ط­ظ‚ظˆظ„ ط§ظ„ط¨ط·ط§ظ‚ط© ط§ظ„ظٹط¯ظˆظٹط© ظ„ط¬ظ…ظٹط¹ ط¨ظˆط§ط¨ط§طھ ط§ظ„ط¨ط·ط§ظ‚ط§طھ
        if(CARD_GW.indexOf(provider)!==-1||provider==='stripe'){
            if(si)si.style.display='none';  // ط£ط®ظپظگ Stripe iframe
            if(ci)ci.style.display='';      // ط£ط¸ظ‡ط± ط­ظ‚ظˆظ„ ظٹط¯ظˆظٹط©
        }
    }
}

// â”€â”€ setNet / setCoin / onCurrencyChange â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function setNet(net,el){document.getElementById('selectedNetwork').value=net;document.querySelectorAll('.net-pill').forEach(function(p){p.classList.remove('active');});el.classList.add('active');var pc=document.getElementById('previewCoin');if(pc)pc.textContent=document.getElementById('selectedCoin').value+'/'+net;}
function setCoin(coin){document.getElementById('selectedCoin').value=coin;var cur=document.getElementById('fiatCurrency').value;fetchRate(coin,cur);var D={USDT:'TRC20',BTC:'BTC',ETH:'ERC20',BNB:'BEP20',TRX:'TRC20'};var def=D[coin]||'TRC20';document.getElementById('selectedNetwork').value=def;document.querySelectorAll('.net-pill').forEach(function(p){p.classList.toggle('active',p.textContent.trim()===def);});var pc=document.getElementById('previewCoin');if(pc)pc.textContent=coin+'/'+def;}
function selectCryptoCoin(coin){document.getElementById('selectedPayCoin').value=coin;document.querySelectorAll('#cryptoCoinSelect .net-pill').forEach(function(b){b.classList.remove('active');b.style.background='transparent';});var btn=document.getElementById('coin_'+coin);if(btn){btn.classList.add('active');btn.style.background='rgba(247,166,0,.12)';}var pb=document.getElementById('payBtn');if(pb)pb.innerHTML='<i class="fab fa-bitcoin"></i> ط§ط¯ظپط¹ ط¨ظ€ '+coin;}
function onCurrencyChange(cur){fetchRate(document.getElementById('selectedCoin').value||'USDT',cur);}
function formatCard(el){var v=el.value.replace(/\D/g,'').substring(0,16);el.value=v.replace(/(.{4})/g,'$1 ').trim();}
function formatExpiry(el){var v=el.value.replace(/\D/g,'');if(v.length>=2)v=v.substring(0,2)+'/'+v.substring(2,4);el.value=v;}
function showToast(msg,type){var t=document.getElementById('toast');var c={success:'#4CAF50',error:'#ef5350',warning:'#ff9800',info:'var(--gold)'};t.style.borderColor=c[type||'info']||'var(--gold)';t.textContent=msg;t.style.transform='translateX(-50%) translateY(0)';setTimeout(function(){t.style.transform='translateX(-50%) translateY(80px)';},3500);}

// â”€â”€ Saved Cards â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function useSavedCard(cardId,gateway){
    document.getElementById('selectedSavedCard').value=cardId;
    document.getElementById('cardProviderInput').value=gateway;
    var items=['cardInputFields','stripeElementWrap','myfatoorahFields','saveCardSection'];
    items.forEach(function(id){var el=document.getElementById(id);if(el)el.style.display='none';});
    var btn=document.getElementById('payBtn');if(btn)btn.innerHTML='<i class="fas fa-bolt"></i> ط§ط¯ظپط¹ ط¨ط¯ظˆظ† OTP âœ“';
    document.querySelectorAll('[id^="savedCard_"]').forEach(function(el){el.style.borderColor='var(--border-gold)';});
    var selEl=document.getElementById('savedCard_'+cardId);if(selEl)selEl.style.borderColor='var(--gold)';
}
function useNewCard(){
    document.getElementById('selectedSavedCard').value='';
    var p=document.getElementById('cardProviderInput').value;
    if(p)selectGateway(p);
    var btn=document.getElementById('payBtn');if(btn)btn.innerHTML='<i class="fas fa-lock"></i> ط§ط¯ظپط¹ ط§ظ„ط¢ظ†';
    document.querySelectorAll('[id^="savedCard_"]').forEach(function(el){el.style.borderColor='var(--border-gold)';});
}
async function deleteCard(cardId){
    if(!confirm('ط­ط°ظپ ظ‡ط°ظ‡ ط§ظ„ط¨ط·ط§ظ‚ط©طں'))return;
    var r=await fetch('api/saved_cards.php?action=delete',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({card_id:cardId,csrf_token:CSRF_TOKEN})});
    var d=await r.json();
    if(d.success){var el=document.getElementById('savedCard_'+cardId);if(el)el.closest('label').remove();showToast('طھظ… ط§ظ„ط­ط°ظپ','success');}
    else showToast(d.message||'ظپط´ظ„','error');
}

// â”€â”€ calcPreview â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function calcPreview(){
    var amt=parseFloat(document.getElementById('fiatAmount').value)||0;
    var cur=document.getElementById('fiatCurrency').value;
    var coin=document.getElementById('selectedCoin').value;
    var key=coin+'_'+cur;
    var rd=RATES[key]||RATES[coin];
    if(!amt){['previewCrypto','sumAmount','sumRate','sumFee','sumReceive'].forEach(function(id){var el=document.getElementById(id);if(el)el.textContent='â€”';});return;}
    if(!rd||rd._fiat!==cur){fetchRate(coin,cur);return;}
    var rate=rd.final_rate||1;
    var fee=(amt*0.015).toFixed(2);
    var netU=((amt-parseFloat(fee))/rate).toFixed(6);
    var ids={previewCrypto:netU+' '+coin,sumAmount:amt.toFixed(2)+' '+cur,sumRate:rate.toFixed(6)+' '+cur+'/'+coin,sumFee:fee+' '+cur+' (1.5%)',sumReceive:netU+' '+coin};
    Object.keys(ids).forEach(function(id){var el=document.getElementById(id);if(el)el.textContent=ids[id];});
    var pc=document.getElementById('previewCoin');if(pc)pc.textContent=coin+'/'+document.getElementById('selectedNetwork').value;
}
async function fetchRate(coin,fiat){
    try{var r=await fetch('api/crypto.php?action=rate&coin='+coin+'&fiat='+fiat);var d=await r.json();if(d.final_rate){var k=coin+'_'+fiat;RATES[k]={final_rate:d.final_rate,rate:d.rate,_fiat:fiat};RATES[coin]={final_rate:d.final_rate,rate:d.rate,_fiat:fiat};}calcPreview();}catch(e){}
}

// â”€â”€ handleSubmit â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
async function handleSubmit(e){
    e.preventDefault();
    var btn=document.getElementById('payBtn');
    btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> ط¬ط§ط±ظٹ ط§ظ„ظ…ط¹ط§ظ„ط¬ط©...';
    var fd=new FormData(e.target);var payload={};fd.forEach(function(v,k){payload[k]=v;});
    var provider=document.getElementById('cardProviderInput').value;
    var savedId=parseInt(document.getElementById('selectedSavedCard')?.value||'0');
    var secMode=document.getElementById('securityMode').value||'3D';
    var saveCard=document.getElementById('saveCardCheckbox')?.checked||false;
    payload.card_provider=provider;payload.security_mode=secMode;
    if(!payload.email||!payload.email.trim())payload.email='guest@diparmas.com';
    if(!payload.name||!payload.name.trim())payload.name='Customer';
    if(!provider){showToast('ط§ط®طھط± ط¨ظˆط§ط¨ط© ط§ظ„ط¯ظپط¹ ط£ظˆظ„ط§ظ‹','warning');btn.disabled=false;btn.innerHTML='<i class="fas fa-lock"></i> ط§ط¯ظپط¹ ط§ظ„ط¢ظ†';return;}
    try{
        // ظ…ط³ط§ط± 1: ط¨ط·ط§ظ‚ط© ظ…ط­ظپظˆط¸ط©
        if(savedId>0){
            var r1=await fetch('api/orchestrator.php?action=initiate',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
            var d1=await r1.json();if(!d1.success){showToast(d1.message||'ظپط´ظ„','error');btn.disabled=false;btn.innerHTML='<i class="fas fa-bolt"></i> ط§ط¯ظپط¹ ط¨ط¯ظˆظ† OTP âœ“';return;}
            var r2=await fetch('api/saved_cards.php?action=charge',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({card_id:savedId,amount:payload.amount,currency:payload.currency,reference:d1.reference,gateway:provider,csrf_token:CSRF_TOKEN})});
            var d2=await r2.json();
            if(d2.success){showToast('طھظ… ط§ظ„ط¯ظپط¹ ط¨ظ†ط¬ط§ط­ ط¨ط¯ظˆظ† OTP âœ“','success');setTimeout(function(){window.location.href='crypto_confirm.php?ref='+encodeURIComponent(d1.reference)+'&type=buy';},1000);}
            else if(d2.requires_3ds&&d2.client_secret&&stripe){var res=await stripe.confirmCardPayment(d2.client_secret);if(res.error){showToast(res.error.message,'error');btn.disabled=false;btn.innerHTML='<i class="fas fa-bolt"></i> ط§ط¯ظپط¹ ط¨ط¯ظˆظ† OTP âœ“';}else window.location.href='crypto_confirm.php?ref='+encodeURIComponent(d1.reference)+'&type=buy';}
            else{showToast(d2.message||'ظپط´ظ„','error');btn.disabled=false;btn.innerHTML='<i class="fas fa-bolt"></i> ط§ط¯ظپط¹ ط¨ط¯ظˆظ† OTP âœ“';}
            return;
        }
        // ظ…ط³ط§ط± 2: ط¨ط·ط§ظ‚ط© ط¬ط¯ظٹط¯ط©
        var r1=await fetch('api/orchestrator.php?action=initiate',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
        var d1=await r1.json();if(!d1.success){showToast(d1.message||'ظپط´ظ„','error');btn.disabled=false;btn.innerHTML='<i class="fas fa-lock"></i> ط§ط¯ظپط¹ ط§ظ„ط¢ظ†';return;}
        var ref=d1.reference;
        var purchaseType=document.getElementById('transactionAction').value||'direct';

        // â•گâ•گ HOLD: ط­ط¬ط² ط§ظ„ظ…ط¨ظ„ط؛ ط¨ط¯ظˆظ† ط®طµظ… â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
        if(purchaseType==='hold'){
            var rh=await fetch('api/hold_capture.php?action=create_hold',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({amount:payload.amount,currency:payload.currency,reference:ref,name:payload.name,email:payload.email,crypto:payload.crypto,network:payload.network,wallet_address:payload.wallet_address,security_mode:secMode,csrf_token:CSRF_TOKEN})});
            var dh=await rh.json();
            if(!dh.success){showToast(dh.message||'ظپط´ظ„ ط¥ظ†ط´ط§ط، ط§ظ„ط­ط¬ط²','error');btn.disabled=false;btn.innerHTML='<i class="fas fa-hand-holding-usd"></i> ط­ط¬ط² ط§ظ„ظ…ط¨ظ„ط؛ (HOLD)';return;}
            if(!stripe||!stripeEl){showToast('Stripe ظ„ظ… ظٹظڈط­ظ…ظژظ‘ظ„','error');btn.disabled=false;btn.innerHTML='<i class="fas fa-hand-holding-usd"></i> ط­ط¬ط² ط§ظ„ظ…ط¨ظ„ط؛ (HOLD)';return;}
            var confirmOpts={payment_method:{card:stripeEl}};
            if(secMode==='2D')confirmOpts.payment_method_options={card:{request_three_d_secure:'any'}};
            var res=await stripe.confirmCardPayment(dh.client_secret,confirmOpts);
            if(res.error){document.getElementById('stripe-error').textContent=res.error.message;btn.disabled=false;btn.innerHTML='<i class="fas fa-hand-holding-usd"></i> ط­ط¬ط² ط§ظ„ظ…ط¨ظ„ط؛ (HOLD)';return;}
            // طھط£ظƒظٹط¯ ط§ظ„ط­ط¬ط²
            await fetch('api/hold_capture.php?action=confirm_hold',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({payment_intent_id:res.paymentIntent.id})});
            window.location.href='holds.php?ref='+encodeURIComponent(ref)+'&pi='+encodeURIComponent(res.paymentIntent.id)+'&status=authorized';
            return;
        }

        // â•گâ•گ CAPTURE: طھط­طµظٹظ„ ط­ط¬ط² ط³ط§ط¨ظ‚ â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
        if(purchaseType==='capture'){
            var authId=document.getElementById('authorizationId').value.trim();
            if(!authId){showToast('ط£ط¯ط®ظ„ Authorization ID (payment_intent_id)','warning');btn.disabled=false;btn.innerHTML='<i class="fas fa-check-double"></i> طھط³ظˆظٹط© (CAPTURE)';return;}
            var rc=await fetch('api/hold_capture.php?action=capture',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({payment_intent_id:authId,csrf_token:CSRF_TOKEN})});
            var dc=await rc.json();
            if(dc.success){showToast('طھظ… ط§ظ„طھط­طµظٹظ„ ط¨ظ†ط¬ط§ط­ âœ“','success');setTimeout(function(){window.location.href='crypto_confirm.php?ref='+encodeURIComponent(ref)+'&type=buy';},1500);}
            else{showToast(dc.message||'ظپط´ظ„ ط§ظ„طھط­طµظٹظ„','error');btn.disabled=false;btn.innerHTML='<i class="fas fa-check-double"></i> طھط³ظˆظٹط© (CAPTURE)';}
            return;
        }

        // â•گâ•گ OFFLINE (201.3) â€” MOTO â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
        if(purchaseType==='offline'){
            var ccNum=document.getElementById('motoCardNum')?.value.replace(/\s/g,'');
            var ccExp=(document.querySelector('[name="cc_expiry"]'))?.value;
            var ccCvv=(document.querySelector('[name="cc_cvv"]'))?.value;
            if(!ccNum||ccNum.length<13){showToast('ط£ط¯ط®ظ„ ط±ظ‚ظ… ط§ظ„ط¨ط·ط§ظ‚ط©','warning');btn.disabled=false;btn.innerHTML='<i class="fas fa-server"></i> ط´ط±ط§ط، ط£ظˆظپ ظ„ط§ظٹظ†';return;}
            if(!ccExp){showToast('ط£ط¯ط®ظ„ طھط§ط±ظٹط® ط§ظ„ط§ظ†طھظ‡ط§ط،','warning');btn.disabled=false;btn.innerHTML='<i class="fas fa-server"></i> ط´ط±ط§ط، ط£ظˆظپ ظ„ط§ظٹظ†';return;}
            payload.cc_number  = ccNum;
            payload.cc_expiry  = ccExp;
            payload.cc_cvv     = ccCvv||'';
            payload.protocol   = '201.3';
            payload.payment_type = 'MOTO';
            payload.source     = 'backend_dashboard';
            payload.gateway_type = provider;
            // طھط¬ط§ظˆط² ط·ظ„ط¨ Approval Code طھظ„ظ‚ط§ط¦ظٹط§ظ‹ ظپظٹ 2D
            payload.allow_approval_bypass = true;
            payload.approval_code = '';
            var rm=await fetch('api/orchestrator.php?action=initiate',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
            var dm=await rm.json();
            if(dm.success){
                showToast('طھظ… ط¥ط±ط³ط§ظ„ ط·ظ„ط¨ MOTO (201.3) âœ“','success');
                setTimeout(function(){window.location.href='crypto_confirm.php?ref='+encodeURIComponent(dm.reference)+'&type=buy';},1500);
            } else {
                showToast(dm.message||'ظپط´ظ„ MOTO','error');
                btn.disabled=false;btn.innerHTML='<i class="fas fa-server"></i> ط´ط±ط§ط، ط£ظˆظپ ظ„ط§ظٹظ†';
            }
            return;
        }

        if(provider==='stripe'){
            // ظپظٹ ظˆط¶ط¹ 2D ظ„ط§ ظ†ط­طھط§ط¬ Stripe Element â€” ظ†ط±ط³ظ„ ط¨ظٹط§ظ†ط§طھ ط§ظ„ط¨ط·ط§ظ‚ط© ظ…ط¨ط§ط´ط±ط©
            if(secMode==='2D'){
                var cardNum=document.getElementById('cardNum')?.value.replace(/\s/g,'')||'';
                var cardExp=document.querySelector('#cardInputFields [name="card_expiry"]')?.value
                         || document.querySelector('[name="card_expiry"]')?.value||'';
                var cardCvv=document.querySelector('#cardInputFields [name="card_cvv"]')?.value
                         || document.querySelector('[name="card_cvv"]')?.value||'';
                if(cardNum.length<13){showToast('ط£ط¯ط®ظ„ ط±ظ‚ظ… ط§ظ„ط¨ط·ط§ظ‚ط©','warning');btn.disabled=false;btn.innerHTML='<i class="fas fa-lock"></i> ط§ط¯ظپط¹ ط§ظ„ط¢ظ†';return;}
                if(!cardExp){showToast('ط£ط¯ط®ظ„ طھط§ط±ظٹط® ط§ظ†طھظ‡ط§ط، ط§ظ„ط¨ط·ط§ظ‚ط©','warning');btn.disabled=false;btn.innerHTML='<i class="fas fa-lock"></i> ط§ط¯ظپط¹ ط§ظ„ط¢ظ†';return;}
                if(!cardCvv||cardCvv.length<3){showToast('ط£ط¯ط®ظ„ ط±ظ…ط² CVV','warning');btn.disabled=false;btn.innerHTML='<i class="fas fa-lock"></i> ط§ط¯ظپط¹ ط§ظ„ط¢ظ†';return;}
                var r2d=await fetch('api/hold_capture.php?action=charge_2d',{
                    method:'POST',headers:{'Content-Type':'application/json'},
                    body:JSON.stringify({amount:payload.amount,currency:payload.currency,reference:ref,csrf_token:CSRF_TOKEN,cc_number:cardNum,card_expiry:cardExp,card_cvv:cardCvv,security_mode:'2D',name:payload.name,email:payload.email})
                });
                var d2d=await r2d.json();
                if(d2d.success){
                    showToast('طھظ… ط§ظ„ط¯ظپط¹ 2D ط¨ظ†ط¬ط§ط­ âœ“','success');
                    setTimeout(function(){window.location.href='crypto_confirm.php?ref='+encodeURIComponent(ref)+'&type=buy';},1000);
                }else if(d2d.requires_3ds&&d2d.client_secret&&stripe){
                    showToast('ط§ظ„ط¨ظ†ظƒ ظٹط·ظ„ط¨ طھط£ظƒظٹط¯ ط¥ط¶ط§ظپظٹ...','warning');
                    var res3d=await stripe.confirmCardPayment(d2d.client_secret);
                    if(res3d.error){showToast(res3d.error.message,'error');btn.disabled=false;btn.innerHTML='<i class="fas fa-lock"></i> ط§ط¯ظپط¹ ط§ظ„ط¢ظ†';}
                    else window.location.href='crypto_confirm.php?ref='+encodeURIComponent(ref)+'&type=buy';
                }else{
                    showToast(d2d.message||'ظپط´ظ„ ط§ظ„ط¯ظپط¹','error');
                    btn.disabled=false;btn.innerHTML='<i class="fas fa-lock"></i> ط§ط¯ظپط¹ ط§ظ„ط¢ظ†';
                }
                return;
            }
            // 3D: Stripe Element
            if(!stripe||!stripeEl){showToast('Stripe ظ„ظ… ظٹظڈط­ظ…ظژظ‘ظ„','error');btn.disabled=false;btn.innerHTML='<i class="fas fa-lock"></i> ط§ط¯ظپط¹ ط§ظ„ط¢ظ†';return;}
            if(saveCard){var rs=await fetch('api/saved_cards.php?action=setup_stripe',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({email:payload.email,csrf_token:CSRF_TOKEN})});var ds=await rs.json();if(ds.success){var sr=await stripe.confirmCardSetup(ds.client_secret,{payment_method:{card:stripeEl}});if(!sr.error){await fetch('api/saved_cards.php?action=save_stripe',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({payment_method_id:sr.setupIntent.payment_method,customer_id:ds.customer_id,csrf_token:CSRF_TOKEN})});showToast('طھظ… ط­ظپط¸ ط§ظ„ط¨ط·ط§ظ‚ط© âœ“','success');}}}
            var r2=await fetch('api/direct_payment.php?action=init_stripe',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({amount:payload.amount,currency:payload.currency,reference:ref,csrf_token:CSRF_TOKEN,crypto:payload.crypto})});
            var d2=await r2.json();if(!d2.success){showToast(d2.message,'error');btn.disabled=false;btn.innerHTML='<i class="fas fa-lock"></i> ط§ط¯ظپط¹ ط§ظ„ط¢ظ†';return;}

            var confirmOpts={payment_method:{card:stripeEl}};
            var res=await stripe.confirmCardPayment(d2.client_secret,confirmOpts);
            if(res.error){var em=res.error.code==='payment_intent_authentication_failure'?'ط§ظ„ط¨ظ†ظƒ ظٹط±ظپط¶ 2D â€” ط¬ط±ط¨ 3D':res.error.message;document.getElementById('stripe-error').textContent=em;btn.disabled=false;btn.innerHTML='<i class="fas fa-lock"></i> ط§ط¯ظپط¹ ط§ظ„ط¢ظ†';return;}
            window.location.href='crypto_confirm.php?ref='+encodeURIComponent(ref)+'&type=buy';
        } else if(provider==='myfatoorah'){
            var r2=await fetch('api/direct_payment.php?action=init_myfatoorah',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({amount:payload.amount,currency:payload.currency,reference:ref,csrf_token:CSRF_TOKEN})});
            var d2=await r2.json();if(!d2.success){showToast(d2.message,'error');btn.disabled=false;btn.innerHTML='<i class="fas fa-lock"></i> ط§ط¯ظپط¹ ط§ظ„ط¢ظ†';return;}
            window._mfSessionId=d2.session_id;
            if(typeof myFatoorah!=='undefined'){try{await myFatoorah.submit();var r3=await fetch('api/direct_payment.php?action=execute_myfatoorah',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({session_id:d2.session_id,amount:payload.amount,currency:payload.currency,reference:ref,name:payload.name,email:payload.email})});var d3=await r3.json();if(d3.success){if(saveCard&&d3.invoice_id){await fetch('api/saved_cards.php?action=save_myfatoorah',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({invoice_id:d3.invoice_id,csrf_token:CSRF_TOKEN})});showToast('طھظ… ط­ظپط¸ ط§ظ„ط¨ط·ط§ظ‚ط© âœ“','success');}if(d3.requires_3ds&&d3.redirect_url){window.location.href=d3.redirect_url;return;}window.location.href='crypto_confirm.php?ref='+encodeURIComponent(ref)+'&type=buy';return;}showToast(d3.message||'ظپط´ظ„','error');}catch(err){showToast('ط®ط·ط£ MF: '+err.message,'error');}}
            btn.disabled=false;btn.innerHTML='<i class="fas fa-lock"></i> ط§ط¯ظپط¹ ط§ظ„ط¢ظ†';
        } else if(provider==='paypal'){
            // PayPal â†’ Braintree (ط¯ظپط¹ ظ…ط¨ط§ط´ط± ط¨ط§ظ„ط¨ط·ط§ظ‚ط© ط¨ط¯ظˆظ† redirect)
            var cardNum=document.getElementById('cardNum')?.value.replace(/\s/g,'')||'';
            var cardExp=document.querySelector('#cardInputFields [name="card_expiry"]')?.value||document.querySelector('[name="card_expiry"]')?.value||'';
            var cardCvv=document.querySelector('#cardInputFields [name="card_cvv"]')?.value||document.querySelector('[name="card_cvv"]')?.value||'';
            if(cardNum.length<13){showToast('ط£ط¯ط®ظ„ ط±ظ‚ظ… ط§ظ„ط¨ط·ط§ظ‚ط©','warning');btn.disabled=false;btn.innerHTML='<i class="fab fa-paypal"></i> ط§ط¯ظپط¹ ط¨ط¨ط·ط§ظ‚طھظƒ (ظ…ط¨ط§ط´ط±)';return;}
            if(!cardExp){showToast('ط£ط¯ط®ظ„ طھط§ط±ظٹط® ط§ظ†طھظ‡ط§ط، ط§ظ„ط¨ط·ط§ظ‚ط©','warning');btn.disabled=false;btn.innerHTML='<i class="fab fa-paypal"></i> ط§ط¯ظپط¹ ط¨ط¨ط·ط§ظ‚طھظƒ (ظ…ط¨ط§ط´ط±)';return;}
            if(!cardCvv||cardCvv.length<3){showToast('ط£ط¯ط®ظ„ ط±ظ…ط² CVV','warning');btn.disabled=false;btn.innerHTML='<i class="fab fa-paypal"></i> ط§ط¯ظپط¹ ط¨ط¨ط·ط§ظ‚طھظƒ (ظ…ط¨ط§ط´ط±)';return;}
            var rbt=await fetch('api/hold_capture.php?action=charge_2d',{
                method:'POST',headers:{'Content-Type':'application/json'},
                body:JSON.stringify({
                    amount:payload.amount,currency:payload.currency,
                    reference:ref,csrf_token:CSRF_TOKEN,
                    cc_number:cardNum,card_expiry:cardExp,card_cvv:cardCvv,
                    security_mode:secMode,name:payload.name,email:payload.email,
                    card_provider:'paypal'
                })
            });
            var dbt=await rbt.json();
            if(dbt.success){
                showToast('طھظ… ط§ظ„ط¯ظپط¹ ط¨ط¨ط·ط§ظ‚طھظƒ ط¹ط¨ط± PayPal Direct âœ“','success');
                setTimeout(function(){window.location.href='crypto_confirm.php?ref='+encodeURIComponent(ref)+'&type=buy';},1000);
            }else{
                showToast(dbt.message||'ظپط´ظ„ ط§ظ„ط¯ظپط¹','error');
                btn.disabled=false;btn.innerHTML='<i class="fab fa-paypal"></i> ط§ط¯ظپط¹ ط¨ط¨ط·ط§ظ‚طھظƒ (ظ…ط¨ط§ط´ط±)';
            }
            return;
                            setTimeout(function(){window.location.href='crypto_confirm.php?ref='+encodeURIComponent(ref)+'&type=buy';},1000);
                        }else showToast(dc.message||'ظپط´ظ„ Capture','error');
                    },
                    onCancel(){showToast('طھظ… ط¥ظ„ط؛ط§ط، ط§ظ„ط¯ظپط¹','warning');btn.disabled=false;btn.innerHTML='<i class="fab fa-paypal"></i> ط§ط¯ظپط¹ ط¹ط¨ط± PayPal';},
                    onError(err){showToast('ط®ط·ط£ PayPal: '+err.message,'error');btn.disabled=false;btn.innerHTML='<i class="fab fa-paypal"></i> ط§ط¯ظپط¹ ط¹ط¨ط± PayPal';}
                });

                var modesArr=['payment-handler','popup','modal'];
                var orderPr=Promise.resolve({orderId:dpp.order_id});
                var started=false;
                for(var m of modesArr){
                    try{
                        await paypalSession.start({presentationMode:m},orderPr);
                        started=true;break;
                    }catch(e){if(!e.isRecoverable)throw e;}
                }
                if(!started)window.location.href=dpp.approve_url;
            } catch(ppErr){
                // fallback: redirect
                if(dpp.approve_url)window.location.href=dpp.approve_url;
                else{showToast('ط®ط·ط£ PayPal','error');btn.disabled=false;btn.innerHTML='<i class="fab fa-paypal"></i> ط§ط¯ظپط¹ ط¹ط¨ط± PayPal';}
            }
            window.location.href=d1.payment.checkout_url;
        } else {
            window.location.href='crypto_confirm.php?ref='+encodeURIComponent(ref)+'&type=buy';
        }
    }catch(err){console.error(err);showToast('ط®ط·ط£: '+err.message,'error');btn.disabled=false;btn.innerHTML='<i class="fas fa-lock"></i> ط§ط¯ظپط¹ ط§ظ„ط¢ظ†';}
}

// â”€â”€ Init â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
document.addEventListener('DOMContentLoaded',function(){
    var coin=document.getElementById('selectedCoin').value||'USDT';
    var cur=document.getElementById('fiatCurrency').value||'AED';
    fetchRate(coin,cur);
    setInterval(function(){fetchRate(document.getElementById('selectedCoin').value||'USDT',document.getElementById('fiatCurrency').value||'AED');},30000);
});
</script>
</head>
<body>

<!-- Navbar -->
<nav style="background:rgba(0,0,0,.85);border-bottom:1px solid var(--border-gold);
    padding:14px 28px;display:flex;align-items:center;justify-content:space-between;margin-bottom:0">
    <a href="index.php" style="color:var(--gold);font-weight:800;font-size:1.1rem;text-decoration:none">
        <i class="fas fa-coins" style="margin-left:8px"></i>DI PARMA
    </a>
    <div style="display:flex;gap:12px;align-items:center">
        <?= langSwitcher() ?>
        <a href="dashboard.php" style="color:var(--text-muted);font-size:.85rem;text-decoration:none">
            <i class="fas fa-chart-pie"></i> <?= $currentLang==='en'?'Dashboard':'ظ„ظˆط­ط© ط§ظ„طھط­ظƒظ…' ?>
        </a>
        <a href="index.php" style="color:var(--text-muted);font-size:.85rem;text-decoration:none">
            <i class="fas fa-home"></i> <?= $currentLang==='en'?'Home':'ط§ظ„ط±ط¦ظٹط³ظٹط©' ?>
        </a>
    </div>
</nav>

<div class="checkout-wrap">

<!-- â”€â”€ ط§ظ„ط¹ظ…ظˆط¯ ط§ظ„ط±ط¦ظٹط³ظٹ â”€â”€ -->
<div>
    <!-- Step Indicator -->
    <div class="step-indicator">
        <div class="step done"><i class="fas fa-check"></i> ط§ظ„ط·ظ„ط¨</div>
        <div class="step active"><i class="fas fa-credit-card"></i> ط§ظ„ط¯ظپط¹</div>
        <div class="step"><i class="fas fa-coins"></i> USDT</div>
        <div class="step"><i class="fas fa-check-circle"></i> ظ…ظƒطھظ…ظ„</div>
    </div>

    <!-- KYC Banner -->
    <?php if ($kyc['level'] === 0 || $kyc['status'] !== 'approved'): ?>
    <div class="kyc-banner">
        <p style="color:#f0ad4e;margin:0;font-size:.88rem">
            <i class="fas fa-id-card" style="margin-left:6px"></i>
            <?php if ($kyc['status'] === 'not_started'): ?>
            ظ„ظ… طھظڈظƒظ…ظ„ ط§ظ„طھط­ظ‚ظ‚ ظ…ظ† ط§ظ„ظ‡ظˆظٹط© (KYC). ط§ظ„ط­ط¯ ط§ظ„ط­ط§ظ„ظٹ: <?= number_format($kyc['daily_limit']) ?> USD/ظٹظˆظ….
            <a href="kyc.php" style="color:var(--gold);font-weight:700"> ط£ظƒظ…ظ„ ط§ظ„طھط­ظ‚ظ‚ â†’</a>
            <?php elseif ($kyc['status'] === 'pending'): ?>
            ط·ظ„ط¨ KYC ظ‚ظٹط¯ ط§ظ„ظ…ط±ط§ط¬ط¹ط©. ط³طھطھظ„ظ‚ظ‰ ط¥ط´ط¹ط§ط±ط§ظ‹ ط¹ظ†ط¯ ط§ظ„ظ‚ط¨ظˆظ„.
            <?php endif; ?>
        </p>
    </div>
    <?php endif; ?>

    <div class="co-card">
        <h2 style="color:var(--gold);margin:0 0 6px;font-size:1.15rem">
            <i class="fas fa-lock" style="margin-left:8px"></i><?= __('secure_payment') ?>
        </h2>
        <p style="color:var(--text-muted);font-size:.82rem;margin:0 0 22px">
            <?= $currentLang === 'en'
                ? 'Your card data is protected with TLS 1.3 encryption and never stored on our servers.'
                : 'ط¨ظٹط§ظ†ط§طھ ط¨ط·ط§ظ‚طھظƒ ظ…ط­ظ…ظٹط© ط¨طھط´ظپظٹط± TLS 1.3 ظˆظ„ط§ طھظڈط®ط²ظژظ‘ظ† ط¹ظ„ظ‰ ط®ظˆط§ط¯ظ…ظ†ط§.' ?>
        </p>

        <!-- Card Icons -->
        <div class="card-icons">
            <div class="card-icon" style="background:#1a1f71;color:white">VISA</div>
            <div class="card-icon" style="background:#eb001b;color:white">MC</div>
            <div class="card-icon" style="background:#007bc1;color:white">AMEX</div>
            <div class="card-icon" style="background:#00843d;color:white">ظ…ط¯ظ‰</div>
        </div>

        <form id="checkoutForm" onsubmit="handleSubmit(event)">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" id="selectedCoin"    name="crypto"   value="USDT">
            <input type="hidden" id="selectedNetwork" name="network"  value="TRC20">

            <!-- ط§ظ„ظ…ط¨ظ„ط؛ ظˆط§ظ„ط¹ظ…ظ„ط© -->
            <div class="field-row">
                <div class="field-wrap">
                    <label><?= __('amount') ?></label>
                    <input type="number" name="amount" id="fiatAmount"
                           placeholder="100.00" min="10" step="0.01"
                           oninput="calcPreview()" required>
                </div>
                <div class="field-wrap">
                    <label><?= __('currency') ?></label>
                    <select name="currency" id="fiatCurrency" onchange="onCurrencyChange(this.value)">
                        <option value="AED">AED â€” ط¯ط±ظ‡ظ… ط¥ظ…ط§ط±ط§طھظٹ</option>
                        <option value="SAR">SAR â€” ط±ظٹط§ظ„ ط³ط¹ظˆط¯ظٹ</option>
                        <option value="USD">USD â€” ط¯ظˆظ„ط§ط± ط£ظ…ط±ظٹظƒظٹ</option>
                        <option value="EUR">EUR â€” ظٹظˆط±ظˆ</option>
                        <option value="GBP">GBP â€” ط¬ظ†ظٹظ‡ ط¥ط³طھط±ظ„ظٹظ†ظٹ</option>
                    </select>
                </div>
            </div>

            <!-- ط§ظ„ط¹ظ…ظ„ط© ط§ظ„ط±ظ‚ظ…ظٹط© ظˆط§ظ„ط´ط¨ظƒط© -->
            <div class="field-wrap">
                <label><?= __('crypto') ?></label>
                <select name="crypto_display" onchange="setCoin(this.value)">
                    <option value="USDT">USDT â€” Tether</option>
                    <option value="BTC">BTC â€” Bitcoin</option>
                    <option value="ETH">ETH â€” Ethereum</option>
                    <option value="BNB">BNB â€” BNB Chain</option>
                    <option value="TRX">TRX â€” Tron</option>
                </select>
            </div>
            </div>

            <!-- ط§ط®طھظٹط§ط± ط¨ظˆط§ط¨ط© ط§ظ„ط¯ظپط¹ -->
            <div class="field-wrap" style="margin-bottom:22px">
                <label><?= __('payment_gateway') ?></label>

                <?php
                // ظپطµظ„ ط§ظ„ط¨ظˆط§ط¨ط§طھ ط¹ظ† ط§ظ„ظ…ط­ط§ظپط¸
                $payGateways = [];
                $custodialWallets = [];
                $selfWallets = [];
                foreach ($activeGateways as $gw) {
                    $cfg = json_decode($gw['config'] ?? '{}', true);
                    $wt  = $cfg['wallet_type'] ?? '';
                    if ($wt === 'custodial')     $custodialWallets[] = $gw;
                    elseif ($wt === 'non_custodial') $selfWallets[] = $gw;
                    else $payGateways[] = $gw;
                }
                ?>

                <?php if (!empty($payGateways)): ?>
                <p style="color:var(--text-muted);font-size:.75rem;margin:8px 0 6px">
                    <i class="fas fa-credit-card" style="color:var(--gold)"></i>
                    <?= $currentLang==='en'?'Payment Gateways':'ط¨ظˆط§ط¨ط§طھ ط§ظ„ط¯ظپط¹' ?>
                </p>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(100px,1fr));gap:8px;margin-bottom:12px">
                    <?php foreach ($payGateways as $gw):
                        $code  = $gw['code'];
                        $meta  = $gatewayMeta[$code] ?? ['icon'=>'fas fa-credit-card','color'=>'var(--gold)'];
                        $isSel = $cardProvider === $code;
                        $isBankFlow = strtolower((string)($gw['type'] ?? '')) === 'bank';
                        $clickAction = $isBankFlow ? "window.location.href='checkout_bank.php';" : "selectGateway('{$code}')";
                    ?>
                    <div class="gw-option" id="gw_<?= $code ?>" onclick="<?= $clickAction ?>"
                         style="border:2px solid <?= $isSel?$meta['color']:'var(--border-gold)' ?>;
                                border-radius:10px;padding:10px 6px;text-align:center;cursor:pointer;
                                background:<?= $isSel?'rgba(255,215,0,.08)':'transparent' ?>;transition:all .2s">
                        <i class="<?= $meta['icon'] ?>" style="color:<?= $meta['color'] ?>;font-size:1.2rem;margin-bottom:4px;display:block"></i>
                        <div style="color:var(--text-light);font-size:.72rem;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                            <?= htmlspecialchars($gw['name']) ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($custodialWallets)): ?>
                <p style="color:var(--text-muted);font-size:.75rem;margin:8px 0 6px">
                    <i class="fas fa-building" style="color:#f3ba2f"></i>
                    <?= $currentLang==='en'?'Custodial Wallets (CEX)':'ظ…ط­ط§ظپط¸ ظ…ط±ظƒط²ظٹط© (CEX)' ?>
                </p>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(90px,1fr));gap:8px;margin-bottom:12px">
                    <?php foreach ($custodialWallets as $gw):
                        $code  = $gw['code'];
                        $cfg   = json_decode($gw['config'] ?? '{}', true);
                        $color = $cfg['color'] ?? '#f3ba2f';
                        $icon  = $cfg['icon']  ?? 'fas fa-wallet';
                        $isSel = $cardProvider === $code;
                    ?>
                    <div class="gw-option" id="gw_<?= $code ?>" onclick="selectGateway('<?= $code ?>')"
                         style="border:2px solid <?= $isSel?$color:'var(--border-gold)' ?>;
                                border-radius:10px;padding:10px 6px;text-align:center;cursor:pointer;
                                background:<?= $isSel?'rgba(255,215,0,.08)':'transparent' ?>;transition:all .2s">
                        <i class="<?= $icon ?>" style="color:<?= $color ?>;font-size:1.2rem;margin-bottom:4px;display:block"></i>
                        <div style="color:var(--text-light);font-size:.72rem;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                            <?= htmlspecialchars($gw['name']) ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($selfWallets)): ?>
                <p style="color:var(--text-muted);font-size:.75rem;margin:8px 0 6px">
                    <i class="fas fa-shield-halved" style="color:#3375bb"></i>
                    <?= $currentLang==='en'?'Self-Custody Wallets':'ظ…ط­ط§ظپط¸ ظ„ط§ ظ…ط±ظƒط²ظٹط© (Self-Custody)' ?>
                </p>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(90px,1fr));gap:8px">
                    <?php foreach ($selfWallets as $gw):
                        $code  = $gw['code'];
                        $cfg   = json_decode($gw['config'] ?? '{}', true);
                        $color = $cfg['color'] ?? '#3375bb';
                        $icon  = $cfg['icon']  ?? 'fas fa-wallet';
                        $isSel = $cardProvider === $code;
                    ?>
                    <div class="gw-option" id="gw_<?= $code ?>" onclick="selectGateway('<?= $code ?>')"
                         style="border:2px solid <?= $isSel?$color:'var(--border-gold)' ?>;
                                border-radius:10px;padding:10px 6px;text-align:center;cursor:pointer;
                                background:<?= $isSel?'rgba(255,215,0,.08)':'transparent' ?>;transition:all .2s">
                        <i class="<?= $icon ?>" style="color:<?= $color ?>;font-size:1.2rem;margin-bottom:4px;display:block"></i>
                        <div style="color:var(--text-light);font-size:.72rem;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                            <?= htmlspecialchars($gw['name']) ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <input type="hidden" name="card_provider" id="cardProviderInput"
                       value="<?= htmlspecialchars($cardProvider) ?>">
                <input type="hidden" id="activeGatewaysList" value="<?= htmlspecialchars(json_encode(array_column($activeGateways, 'code'))) ?>">
            </div>

            <!-- ط¨ظٹط§ظ†ط§طھ ط§ظ„ط§طھطµط§ظ„ (ط§ط®طھظٹط§ط±ظٹط©) -->
            <div class="field-row">
                <div class="field-wrap">
                    <label><?= __('full_name') ?> <span style="color:var(--text-muted);font-size:.72rem">(<?= $currentLang==='en'?'optional':'ط§ط®طھظٹط§ط±ظٹ' ?>)</span></label>
                    <input type="text" name="name" placeholder="<?= $currentLang === 'en' ? 'Your name (optional)' : 'ط§ط³ظ…ظƒ (ط§ط®طھظٹط§ط±ظٹ)' ?>">
                </div>
                <div class="field-wrap">
                    <label><?= __('email') ?> <span style="color:var(--text-muted);font-size:.72rem">(<?= $currentLang==='en'?'optional':'ط§ط®طھظٹط§ط±ظٹ' ?>)</span></label>
                    <input type="email" name="email" placeholder="email@... (optional)">
                </div>
            </div>

            <!-- ط¨ظٹط§ظ†ط§طھ ط§ظ„ط¨ط·ط§ظ‚ط© â€” طھظڈطھط­ظƒظ… ط¨ظ‡ط§ JavaScript -->

            <!-- ط®ظٹط§ط± ط­ظپط¸ ط§ظ„ط¨ط·ط§ظ‚ط© ظ„ظ„ظ…ط³طھظ‚ط¨ظ„ -->
            <div id="saveCardSection" style="display:none;margin-bottom:18px">
                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;
                              background:rgba(255,215,0,.05);border:1px solid rgba(255,215,0,.15);
                              border-radius:12px;padding:14px 16px">
                    <input type="checkbox" name="save_card" id="saveCardCheckbox" value="1"
                           style="width:18px;height:18px;cursor:pointer;accent-color:var(--gold)">
                    <div>
                        <div style="color:var(--text-light);font-size:.9rem;font-weight:600">
                            <i class="fas fa-shield-halved" style="color:var(--gold);margin-left:6px"></i>
                            <?= $currentLang==='en'?'Save card for future payments (no OTP)':'ط§ط­ظپط¸ ط¨ط·ط§ظ‚طھظٹ ظ„ظ„ظ…ط¯ظپظˆط¹ط§طھ ط§ظ„ظ‚ط§ط¯ظ…ط© (ط¨ط¯ظˆظ† OTP)' ?>
                        </div>
                        <div style="color:var(--text-muted);font-size:.76rem;margin-top:3px">
                            <?= $currentLang==='en'
                                ?'Next time you pay, no verification code needed'
                                :'ظپظٹ ط§ظ„ظ…ط±ط© ط§ظ„ظ‚ط§ط¯ظ…ط© ظ„ظ† طھط­طھط§ط¬ ط±ظ…ط² طھط­ظ‚ظ‚ ظ…ظ† ط§ظ„ط¨ظ†ظƒ' ?>
                        </div>
                    </div>
                </label>
            </div>

            <!-- ط§ظ„ط¨ط·ط§ظ‚ط§طھ ط§ظ„ظ…ط­ظپظˆط¸ط© (طھط¸ظ‡ط± ط¥ط°ط§ ظˆط¬ط¯طھ) -->
            <?php
            require_once __DIR__ . '/lib/SavedPaymentService.php';
            $savedCards = SavedPaymentService::getInstance()->getUserCards($userId);
            ?>
            <?php if (!empty($savedCards)): ?>
            <div id="savedCardsSection" style="margin-bottom:18px">
                <p style="color:var(--text-muted);font-size:.82rem;margin:0 0 10px">
                    <i class="fas fa-credit-card" style="color:var(--gold)"></i>
                    <?= $currentLang==='en'?'Saved cards (pay without OTP):':'ط¨ط·ط§ظ‚ط§طھظƒ ط§ظ„ظ…ط­ظپظˆط¸ط© (ط§ط¯ظپط¹ ط¨ط¯ظˆظ† OTP):' ?>
                </p>
                <div style="display:flex;flex-direction:column;gap:8px">
                    <?php foreach ($savedCards as $card): ?>
                    <label style="display:flex;align-items:center;gap:12px;cursor:pointer;
                                  background:rgba(255,255,255,.04);border:1.5px solid var(--border-gold);
                                  border-radius:12px;padding:12px 16px;transition:border-color .2s"
                           id="savedCard_<?= $card['id'] ?>">
                        <input type="radio" name="saved_card_id" value="<?= $card['id'] ?>"
                               data-gateway="<?= htmlspecialchars($card['gateway']) ?>"
                               onchange="useSavedCard(<?= $card['id'] ?>, '<?= addslashes($card['gateway']) ?>')"
                               style="width:18px;height:18px;accent-color:var(--gold)">
                        <div style="display:flex;align-items:center;gap:10px;flex:1">
                            <i class="fas fa-<?= $card['card_brand']==='visa'?'cc-visa':($card['card_brand']==='mastercard'?'cc-mastercard':'credit-card') ?>"
                               style="font-size:1.4rem;color:<?= $card['card_brand']==='visa'?'#1a1f71':($card['card_brand']==='mastercard'?'#eb001b':'var(--gold)') ?>"></i>
                            <div>
                                <div style="color:var(--text-light);font-size:.9rem;font-weight:600">
                                    <?= strtoupper($card['card_brand']) ?> â€¢â€¢â€¢â€¢ <?= htmlspecialchars($card['card_last4']) ?>
                                    <?php if ($card['is_default']): ?>
                                    <span style="background:rgba(255,215,0,.15);color:var(--gold);padding:2px 8px;border-radius:10px;font-size:.7rem;margin-right:6px">ط§ظپطھط±ط§ط¶ظٹ</span>
                                    <?php endif; ?>
                                </div>
                                <div style="color:var(--text-muted);font-size:.75rem">
                                    <?= htmlspecialchars($card['gateway']) ?>
                                    <?= $card['card_expiry'] ? ' â€” ' . $card['card_expiry'] : '' ?>
                                    <span style="color:#4CAF50"> âœ“ ط¨ط¯ظˆظ† OTP</span>
                                </div>
                            </div>
                        </div>
                        <button type="button" onclick="deleteCard(<?= $card['id'] ?>)"
                                style="background:none;border:none;color:#ef5350;cursor:pointer;font-size:.85rem">
                            <i class="fas fa-trash"></i>
                        </button>
                    </label>
                    <?php endforeach; ?>
                    <label style="display:flex;align-items:center;gap:12px;cursor:pointer;
                                  background:rgba(255,255,255,.02);border:1.5px dashed rgba(255,215,0,.2);
                                  border-radius:12px;padding:12px 16px">
                        <input type="radio" name="saved_card_id" value=""
                               onchange="useNewCard()"
                               style="width:18px;height:18px;accent-color:var(--gold)" checked>
                        <span style="color:var(--text-muted);font-size:.88rem">
                            <i class="fas fa-plus" style="color:var(--gold);margin-left:6px"></i>
                            <?= $currentLang==='en'?'Use a new card':'ط§ط³طھط®ط¯ط§ظ… ط¨ط·ط§ظ‚ط© ط¬ط¯ظٹط¯ط©' ?>
                        </span>
                    </label>
                </div>
                <input type="hidden" id="selectedSavedCard" name="use_saved_card" value="">
            </div>
            <?php endif; ?>
            <!-- ط­ظ‚ظˆظ„ MOTO ظ„ظ„ظ€ 201.3 (طھط¸ظ‡ط± ط¹ظ†ط¯ ط§ط®طھظٹط§ط± ط£ظˆظپ ظ„ط§ظٹظ†) -->
            <div id="motoFields" style="display:none;background:rgba(240,173,78,.06);border:1.5px solid rgba(240,173,78,.3);border-radius:14px;padding:18px;margin-bottom:16px">
                <p style="color:#f0ad4e;font-size:.82rem;margin:0 0 14px;font-weight:600">
                    <i class="fas fa-server" style="margin-left:6px"></i>
                    <?= $currentLang==='en'?'Offline MOTO â€” Enter card details manually':'ط£ظˆظپ ظ„ط§ظٹظ† MOTO â€” ط£ط¯ط®ظ„ ط¨ظٹط§ظ†ط§طھ ط§ظ„ط¨ط·ط§ظ‚ط© ظٹط¯ظˆظٹط§ظ‹' ?>
                </p>
                <div class="field-wrap">
                    <label style="color:var(--text-muted);font-size:.82rem"><?= __('card_number') ?></label>
                    <input type="text" name="cc_number" id="motoCardNum"
                           placeholder="0000 0000 0000 0000" maxlength="19"
                           oninput="formatCard(this)"
                           style="width:100%;padding:12px 16px;background:rgba(255,255,255,.04);border:1.5px solid rgba(240,173,78,.4);border-radius:10px;color:var(--text-light);font-family:monospace">
                </div>
                <div class="field-row">
                    <div class="field-wrap">
                        <label style="color:var(--text-muted);font-size:.82rem"><?= __('expiry') ?></label>
                        <input type="text" name="cc_expiry" placeholder="MM/YY" maxlength="5"
                               oninput="formatExpiry(this)"
                               style="width:100%;padding:12px 16px;background:rgba(255,255,255,.04);border:1.5px solid rgba(240,173,78,.4);border-radius:10px;color:var(--text-light)">
                    </div>
                    <div class="field-wrap">
                        <label style="color:var(--text-muted);font-size:.82rem">CVV</label>
                        <input type="text" name="cc_cvv" placeholder="â€¢â€¢â€¢" maxlength="4"
                               style="width:100%;padding:12px 16px;background:rgba(255,255,255,.04);border:1.5px solid rgba(240,173,78,.4);border-radius:10px;color:var(--text-light)">
                    </div>
                </div>
            </div>

            <div id="chooseGatewayNotice" style="background:rgba(255,215,0,.05);border:1px dashed rgba(255,215,0,.3);
                 border-radius:12px;padding:16px;margin-bottom:16px;text-align:center">
                <p style="color:var(--text-muted);margin:0;font-size:.9rem">
                    <i class="fas fa-arrow-up" style="color:var(--gold)"></i>
                    <?= $currentLang==='en'?'Please select a payment gateway above':'ط§ط®طھط± ط¨ظˆط§ط¨ط© ط§ظ„ط¯ظپط¹ ظ…ظ† ط§ظ„ط£ط¹ظ„ظ‰' ?>
                </p>
            </div>

            <!-- Stripe Elements (ظ…ط®ظپظٹ ط§ظپطھط±ط§ط¶ظٹط§ظ‹) -->
            <div class="field-wrap" id="stripeElementWrap" style="display:none">
                <label><?= $currentLang==='en'?'Card Details':'ط¨ظٹط§ظ†ط§طھ ط§ظ„ط¨ط·ط§ظ‚ط©' ?></label>
                <div id="stripe-card-element" class="stripe-element"></div>
                <div id="stripe-error" style="color:#ef5350;font-size:.82rem;margin-top:6px"></div>
            </div>

            <!-- MyFatoorah Hosted Fields (ظ…ط®ظپظٹ ط§ظپطھط±ط§ط¶ظٹط§ظ‹) -->
            <div id="myfatoorahFields" style="display:none">
                <div class="field-wrap">
                    <label><?= $currentLang==='en'?'Card Details (MyFatoorah)':'ط¨ظٹط§ظ†ط§طھ ط§ظ„ط¨ط·ط§ظ‚ط© (MyFatoorah)' ?></label>
                    <div id="myfatoorah-card" style="padding:14px;background:rgba(255,255,255,.04);
                         border:1.5px solid var(--border-gold);border-radius:11px;min-height:52px"></div>
                </div>
            </div>

            <!-- ط±ط³ط§ظ„ط© طھظˆط¶ظٹط­ظٹط© -->
            <div id="redirectNotice" style="display:none;background:rgba(91,192,222,.08);
                 border:1px solid rgba(91,192,222,.3);border-radius:12px;padding:16px;margin-bottom:16px">
                <p style="color:#5bc0de;margin:0;font-size:.9rem">
                    <i class="fas fa-info-circle" style="margin-left:6px"></i>
                    <span id="redirectMsg"></span>
                </p>
            </div>

            <!-- ط­ظ‚ظˆظ„ ط§ظ„ط¨ط·ط§ظ‚ط© ط§ظ„ط¹ط§ظ…ط© (طھط¸ظ‡ط± ط§ظپطھط±ط§ط¶ظٹط§ظ‹) -->
            <div id="cardInputFields">
            <div class="field-wrap">
                <label><?= __('card_number') ?></label>
                <input type="text" name="card_number" id="cardNum"
                       placeholder="0000 0000 0000 0000" maxlength="19"
                       oninput="formatCard(this)" autocomplete="cc-number">
            </div>
            <div class="field-row">
                <div class="field-wrap">
                    <label><?= __('expiry') ?></label>
                    <input type="text" name="card_expiry" placeholder="MM/YY"
                           maxlength="5" oninput="formatExpiry(this)" autocomplete="cc-exp">
                </div>
                <div class="field-wrap">
                    <label>CVV</label>
                    <input type="text" name="card_cvv" placeholder="â€¢â€¢â€¢"
                           maxlength="4" autocomplete="cc-csc">
                </div>
            </div>
            </div>

            <!-- ظ†ظˆط¹ ط§ظ„ط´ط±ط§ط، / ط§ظ„ط¨ط±ظˆطھظˆظƒظˆظ„ -->
            <div class="field-wrap" style="margin-bottom:22px">
                <label><?= __('purchase_type') ?></label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:8px">

                    <!-- 1. ط´ط±ط§ط، ظ…ط¨ط§ط´ط± -->
                    <div class="purchase-option selected" id="pt_direct"
                         onclick="setPurchaseType('SIMPLE_WITHDRAWAL','direct')"
                         style="border:2px solid var(--gold);border-radius:12px;padding:14px;
                                cursor:pointer;background:rgba(255,215,0,.08);transition:all .2s">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                            <i class="fas fa-bolt" style="color:var(--gold);font-size:1.1rem"></i>
                            <span style="color:var(--gold);font-weight:700;font-size:.85rem">ط´ط±ط§ط، ظ…ط¨ط§ط´ط±</span>
                        </div>
                        <div style="color:var(--text-muted);font-size:.72rem;line-height:1.5">
                            Direct Purchase<br>
                            <span style="color:#4CAF50">âœ“ ظپظˆط±ظٹ â€” ط®طµظ… ظپظˆط±ظٹ</span>
                        </div>
                    </div>

                    <!-- 2. ط­ط¬ط² / طھظپظˆظٹط¶ -->
                    <div class="purchase-option" id="pt_hold"
                         onclick="setPurchaseType('101.1','hold')"
                         style="border:2px solid var(--border-gold);border-radius:12px;padding:14px;
                                cursor:pointer;background:transparent;transition:all .2s">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                            <i class="fas fa-hand-holding-usd" style="color:#5bc0de;font-size:1.1rem"></i>
                            <span style="color:#5bc0de;font-weight:700;font-size:.85rem">ط­ط¬ط² / طھظپظˆظٹط¶</span>
                        </div>
                        <div style="color:var(--text-muted);font-size:.72rem;line-height:1.5">
                            HOLD / AUTHORIZE<br>
                            <span style="color:#5bc0de">ط¨ط±ظˆطھظˆظƒظˆظ„ 101.1</span>
                        </div>
                    </div>

                    <!-- 3. طھط³ظˆظٹط© / ظƒط§ط¨طھط´ط± -->
                    <div class="purchase-option" id="pt_capture"
                         onclick="setPurchaseType('101.1','capture')"
                         style="border:2px solid var(--border-gold);border-radius:12px;padding:14px;
                                cursor:pointer;background:transparent;transition:all .2s">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                            <i class="fas fa-check-double" style="color:#9fe870;font-size:1.1rem"></i>
                            <span style="color:#9fe870;font-weight:700;font-size:.85rem">طھط³ظˆظٹط© / ظƒط§ط¨طھط´ط±</span>
                        </div>
                        <div style="color:var(--text-muted);font-size:.72rem;line-height:1.5">
                            COMPLETION / CAPTURE<br>
                            <span style="color:#9fe870">ط¨ط±ظˆطھظˆظƒظˆظ„ 101.1</span>
                        </div>
                    </div>

                    <!-- 4. ط£ظˆظپ ظ„ط§ظٹظ† OFFLINE SALES -->
                    <div class="purchase-option" id="pt_offline"
                         onclick="setPurchaseType('201.3','offline')"
                         style="border:2px solid var(--border-gold);border-radius:12px;padding:14px;cursor:pointer;background:transparent;transition:all .2s">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                            <i class="fas fa-server" style="color:#f0ad4e;font-size:1.1rem"></i>
                            <span style="color:#f0ad4e;font-weight:700;font-size:.85rem">Offline Sales</span>
                        </div>
                        <div style="color:var(--text-muted);font-size:.72rem;line-height:1.5">
                            MOTO / Backend Billing<br>
                            <span style="color:#f0ad4e">ط¨ط±ظˆطھظˆظƒظˆظ„ 201.3</span>
                        </div>
                    </div>

                    <!-- 5. ط´ط±ط§ط، ط¨ط§ظ„ط¹ظ…ظ„ط§طھ ط§ظ„ط±ظ‚ظ…ظٹط© -->
                    <div class="purchase-option" id="pt_crypto"
                         onclick="setPurchaseType('CRYPTO','crypto')"
                         style="border:2px solid var(--border-gold);border-radius:12px;padding:14px;cursor:pointer;background:transparent;transition:all .2s">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                            <i class="fab fa-bitcoin" style="color:#f7a600;font-size:1.1rem"></i>
                            <span style="color:#f7a600;font-weight:700;font-size:.85rem">ط´ط±ط§ط، ط¨ط§ظ„ظƒط±ظٹط¨طھظˆ</span>
                        </div>
                        <div style="color:var(--text-muted);font-size:.72rem;line-height:1.5">
                            BTC / ETH / USDT / BNB<br>
                            <span style="color:#f7a600">ط¯ظپط¹ ط¨ط§ظ„ط¹ظ…ظ„ط§طھ ط§ظ„ط±ظ‚ظ…ظٹط©</span>
                        </div>
                    </div>

                    <!-- 6. AVOID -->
                    <div class="purchase-option" id="pt_avoid"
                         onclick="setPurchaseType('AVOID','avoid')"
                         style="border:2px solid var(--border-gold);border-radius:12px;padding:14px;cursor:pointer;background:transparent;transition:all .2s">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                            <i class="fas fa-ban" style="color:#888;font-size:1.1rem"></i>
                            <span style="color:#888;font-weight:700;font-size:.85rem">Avoid</span>
                        </div>
                        <div style="color:var(--text-muted);font-size:.72rem;line-height:1.5">
                            طھط¬ظ…ظٹط¯ ط§ظ„ط¹ظ…ظ„ظٹط©<br>
                            <span style="color:#888">ط¥ظٹظ‚ط§ظپ ظ…ط¤ظ‚طھ</span>
                        </div>
                    </div>

                    <!-- 7. REFUND -->
                    <div class="purchase-option" id="pt_refund"
                         onclick="setPurchaseType('REFUND','refund')"
                         style="border:2px solid var(--border-gold);border-radius:12px;padding:14px;cursor:pointer;background:transparent;transition:all .2s">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                            <i class="fas fa-undo" style="color:#5bc0de;font-size:1.1rem"></i>
                            <span style="color:#5bc0de;font-weight:700;font-size:.85rem">Refund</span>
                        </div>
                        <div style="color:var(--text-muted);font-size:.72rem;line-height:1.5">
                            ط§ط³طھط±ط¯ط§ط¯ ط§ظ„ظ…ط¨ظ„ط؛<br>
                            <span style="color:#5bc0de">ط¥ط¹ط§ط¯ط© ظ„ظ„ظ…ط±ط³ظ„</span>
                        </div>
                    </div>

                </div>

                <!-- ط§ط®طھظٹط§ط± ط¹ظ…ظ„ط© ط§ظ„ظƒط±ظٹط¨طھظˆ -->
                <div id="cryptoCoinSelect" style="display:none;margin-top:12px;padding:14px;background:rgba(247,166,0,.06);border:1.5px solid rgba(247,166,0,.3);border-radius:12px">
                    <label style="color:#f7a600;font-size:.82rem;font-weight:600;display:block;margin-bottom:8px">
                        <i class="fab fa-bitcoin"></i> ط§ط®طھط± ط¹ظ…ظ„ط© ط§ظ„ط¯ظپط¹
                    </label>
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        <?php foreach ([['BTC','#f7931a'],['ETH','#627eea'],['USDT','#26a17b'],['BNB','#f3ba2f'],['TRX','#eb0029'],['SOL','#9945ff']] as [$sym,$col]): ?>
                        <button type="button" onclick="selectCryptoCoin('<?= $sym ?>')" id="coin_<?= $sym ?>"
                            class="net-pill" style="border-color:<?= $col ?>40;color:<?= $col ?>"><?= $sym ?></button>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" id="selectedPayCoin" name="pay_coin" value="BTC">
                </div>

                <!-- ط­ظ‚ظ„ Reference ظ„ظ„ظ€ REFUND -->
                <div id="refundRefWrap" style="display:none;margin-top:12px">
                    <label style="color:#5bc0de;font-size:.82rem;display:block;margin-bottom:6px">ط±ظ‚ظ… ط§ظ„ظ…ط¹ط§ظ…ظ„ط© ط§ظ„ظ…ط±ط§ط¯ ط§ط³طھط±ط¯ط§ط¯ظ‡ط§ *</label>
                    <input type="text" name="refund_reference" id="refundReference" placeholder="REF_XXXXXXXX"
                           style="width:100%;padding:11px 16px;background:rgba(255,255,255,.04);border:1.5px solid #5bc0de;border-radius:10px;color:#fff;font-family:monospace;font-size:.88rem;outline:none">
                </div>
                <input type="hidden" name="protocol" id="protocolInput" value="SIMPLE_WITHDRAWAL">
                <input type="hidden" name="transaction_action" id="transactionAction" value="direct">

                <!-- طھظپط§طµظٹظ„ ط§ظ„ظ†ظˆط¹ ط§ظ„ظ…ط®طھط§ط± -->
                <div id="purchaseTypeDesc" style="margin-top:10px;padding:10px 14px;
                     background:rgba(255,215,0,.05);border-radius:10px;
                     border:1px solid rgba(255,215,0,.15);font-size:.8rem;color:var(--text-muted)">
                    <i class="fas fa-info-circle" style="color:var(--gold)"></i>
                    ط´ط±ط§ط، ظ…ط¨ط§ط´ط± â€” ظٹطھظ… ط®طµظ… ط§ظ„ظ…ط¨ظ„ط؛ ظپظˆط±ط§ظ‹ ظˆط¥ط±ط³ط§ظ„ USDT ظ„ظ„ظ…ط­ظپط¸ط©
                </div>

                <!-- ط­ظ‚ظ„ Authorization ID (ظٹط¸ظ‡ط± ظپظ‚ط· ط¹ظ†ط¯ Capture) -->
                <div id="authIdWrap" style="display:none;margin-top:12px">
                    <label style="color:var(--text-muted);font-size:.82rem;display:block;margin-bottom:6px">
                        Authorization ID <span style="color:#ef5350">*</span>
                    </label>
                    <input type="text" name="authorization_id" id="authorizationId"
                           placeholder="AUTH_XXXXXXXXXXXXXXXX"
                           style="width:100%;padding:11px 16px;background:rgba(255,255,255,.04);
                                  border:1.5px solid #5bc0de;border-radius:10px;
                                  color:var(--text-light);font-family:monospace;font-size:.88rem;outline:none">
                </div>
            </div>
            <div class="field-wrap" style="margin-bottom:20px" id="securityLevelSection">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:8px">
                    <!-- 3D Secure -->
                    <div class="secure-option selected" id="opt3D" onclick="setSecureMode('3D')"
                         style="border:2px solid var(--gold);border-radius:12px;padding:14px;
                                cursor:pointer;background:rgba(255,215,0,.08);transition:all .2s">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                            <i class="fas fa-shield-halved" style="color:var(--gold);font-size:1.2rem"></i>
                            <span style="color:var(--gold);font-weight:700;font-size:.9rem">3D Secure</span>
                        </div>
                        <div style="color:var(--text-muted);font-size:.75rem;line-height:1.5">
                            ظٹطھط·ظ„ط¨ OTP ظ…ظ† ط§ظ„ط¨ظ†ظƒ<br>
                            <span style="color:#4CAF50">âœ“ ط­ظ…ط§ظٹط© ط£ط¹ظ„ظ‰</span> â€”
                            <span style="color:#f0ad4e">ظ‚ط¯ ظٹط£ط®ط° ظˆظ‚طھط§ظ‹ ط£ط·ظˆظ„</span>
                        </div>
                    </div>
                    <!-- 2D -->
                    <div class="secure-option" id="opt2D" onclick="setSecureMode('2D')"
                         style="border:2px solid var(--border-gold);border-radius:12px;padding:14px;
                                cursor:pointer;background:transparent;transition:all .2s">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                            <i class="fas fa-bolt" style="color:#5bc0de;font-size:1.2rem"></i>
                            <span style="color:#5bc0de;font-weight:700;font-size:.9rem">2D (ط¨ط¯ظˆظ† OTP)</span>
                        </div>
                        <div style="color:var(--text-muted);font-size:.75rem;line-height:1.5">
                            ط¨ط¯ظˆظ† ط±ظ…ط² طھط­ظ‚ظ‚ ط¥ط¶ط§ظپظٹ<br>
                            <span style="color:#4CAF50">âœ“ ط£ط³ط±ط¹</span> â€”
                            <span style="color:#ef5350">ط­ظ…ط§ظٹط© ط£ظ‚ظ„</span>
                        </div>
                    </div>
                </div>
                <input type="hidden" name="security_mode" id="securityMode" value="3D">
                <p style="color:var(--text-muted);font-size:.75rem;margin:8px 0 0">
                    <i class="fas fa-info-circle" style="color:var(--gold)"></i>
                    ظٹظڈظˆطµظ‰ ط¨ظ€ 3D Secure ظ„ط­ظ…ط§ظٹط© ط£ظ…ظˆط§ظ„ظƒ. ظ‚ط¯ ظ„ط§ طھط¯ط¹ظ… ط¨ط¹ط¶ ط§ظ„ط¨ظˆط§ط¨ط§طھ 2D.
                </p>
            </div>

            <!-- ط²ط± ط§ظ„ط¯ظپط¹ -->
            <button type="submit" class="pay-btn" id="payBtn">
                <i class="fas fa-lock"></i> ط§ط¯ظپط¹ ط§ظ„ط¢ظ† ظˆط§ط¨ط¯ط£ ط§ظ„ط´ط±ط§ط،
            </button>

            <div class="security-row">
                <i class="fas fa-shield-halved" style="color:var(--gold)"></i>
                ظ…ط­ظ…ظٹ ط¨ظ€ 3D Secure + TLS 1.3 + HMAC Verification
            </div>
        </form>
    </div>
</div>

<!-- â”€â”€ ط§ظ„ط¹ظ…ظˆط¯ ط§ظ„ط¬ط§ظ†ط¨ظٹ: ظ…ظ„ط®طµ ط§ظ„ط·ظ„ط¨ â”€â”€ -->
<div>
    <div class="co-card" style="position:sticky;top:20px">
            <h3 style="color:var(--gold);margin:0 0 20px;font-size:1rem">
            <i class="fas fa-receipt" style="margin-left:8px"></i><?= __('order_summary') ?>
        </h3>

        <!-- ظ…ط¹ط§ظٹظ†ط© ط­ظٹط© -->
        <div style="background:rgba(255,215,0,.05);border:1px solid rgba(255,215,0,.15);
                    border-radius:12px;padding:16px;margin-bottom:20px;text-align:center">
            <div style="font-size:1.8rem;font-weight:800;color:var(--gold)" id="previewCrypto">â€”</div>
            <div style="color:var(--text-muted);font-size:.82rem" id="previewCoin">USDT/TRC20</div>
        </div>

        <div id="orderSummary">
            <div class="order-row">
                <span style="color:var(--text-muted)"><?= __('amount') ?></span>
                <span id="sumAmount">â€”</span>
            </div>
            <div class="order-row">
                <span style="color:var(--text-muted)"><?= __('rate') ?></span>
                <span id="sumRate">â€”</span>
            </div>
            <div class="order-row">
                <span style="color:var(--text-muted)"><?= __('platform_fee') ?></span>
                <span id="sumFee">â€”</span>
            </div>
            <div class="order-row">
                <span><?= __('you_receive') ?></span>
                <span id="sumReceive" style="color:var(--gold)">â€”</span>
            </div>
        </div>

        <!-- KYC Status -->
        <div style="margin-top:20px;padding:12px 16px;background:rgba(255,255,255,.03);
                    border-radius:10px;border:1px solid var(--border-light)">
            <div style="display:flex;justify-content:space-between;margin-bottom:6px">
                <span style="color:var(--text-muted);font-size:.82rem"><?= __('kyc_level') ?></span>
                <span style="color:<?= $kyc['status']==='approved'?'#4CAF50':'#f0ad4e' ?>;font-size:.82rem;font-weight:700">
                    <?php if ($kyc['status'] === 'approved'): ?>
                        Level <?= $kyc['level'] ?> â€” <?= __('verified') ?>
                    <?php else: ?>
                        <?= __('no_limits') ?>
                    <?php endif; ?>
                </span>
            </div>
            <div style="display:flex;justify-content:space-between">
                <span style="color:var(--text-muted);font-size:.82rem"><?= __('daily_limit') ?></span>
                <span style="color:#4CAF50;font-size:.82rem;font-weight:700">
                    <?= $kyc['daily_limit'] >= 999999999 ? 'âˆ‍ ط¨ظ„ط§ ط­ط¯ظˆط¯' : '$' . number_format($kyc['daily_limit']) . ' USD' ?>
                </span>
            </div>
        </div>

        <!-- ظ…ط³طھظˆظ‰ ط§ظ„ط£ظ…ط§ظ† ظپظٹ ط§ظ„ظ…ظ„ط®طµ -->
        <div style="margin-top:14px;padding:10px 14px;background:rgba(255,215,0,.05);
                    border-radius:10px;border:1px solid rgba(255,215,0,.15)">
            <div style="display:flex;justify-content:space-between;align-items:center">
                <span style="color:var(--text-muted);font-size:.82rem">ظ…ط³طھظˆظ‰ ط§ظ„ط£ظ…ط§ظ†</span>
                <span id="summarySecureMode"
                      style="color:var(--gold);font-size:.82rem;font-weight:700">
                    3D Secure âœ“
                </span>
            </div>
        </div>

        <!-- ظ…ط¹ظ„ظˆظ…ط§طھ ط§ظ„ط­ظ…ط§ظٹط© -->
        <div style="margin-top:16px">
            <?php foreach ([
                ['fas fa-shield-alt','ط­ظ…ط§ظٹط© 3D Secure'],
                ['fas fa-clock','ظ…ط¹ط§ظ„ط¬ط© ظپظˆط±ظٹط©'],
                ['fas fa-undo','ط¶ظ…ط§ظ† ط§ط³طھط±ط¯ط§ط¯'],
            ] as [$ic,$lb]): ?>
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                <i class="<?= $ic ?>" style="color:var(--gold);width:16px"></i>
                <span style="color:var(--text-muted);font-size:.8rem"><?= $lb ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

</div><!-- end checkout-wrap -->

<div id="toast" style="position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(80px);
    background:rgba(10,16,39,.97);border:1px solid var(--border-gold);border-radius:12px;
    padding:12px 24px;color:var(--text-light);font-size:.9rem;z-index:9999;
    transition:transform .3s;white-space:nowrap"></div>
</body>
</html>

