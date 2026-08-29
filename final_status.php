<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/gateways.php';
require_once __DIR__ . '/includes/crypto_schema.php';

dp_create_crypto_tables();

$db = db();

echo "=== DI PARMA | تقييم الوضع الحالي ===\n\n";

// ── 1. المفاتيح ──────────────────────────────────────
echo "--- [1] API Keys ---\n";
$keys = [
    'STRIPE_SECRET_KEY'        => ['label'=>'Stripe Secret',    'critical'=>true],
    'STRIPE_PUBLIC_KEY'        => ['label'=>'Stripe Public',    'critical'=>true],
    'MYFAOORAH_API_KEY'        => ['label'=>'MyFatoorah',       'critical'=>true],
    'WISE_API_KEY'             => ['label'=>'Wise',             'critical'=>true],
    'TRONGRID_API_KEY'         => ['label'=>'TronGrid',         'critical'=>true],
    'HOT_WALLET_TRC20_ADDRESS' => ['label'=>'Hot Wallet Addr',  'critical'=>true],
    'HOT_WALLET_TRC20_KEY'     => ['label'=>'Hot Wallet Key',   'critical'=>true],
    'SUMSUB_APP_TOKEN'         => ['label'=>'Sumsub KYC',       'critical'=>false],
    'TWILIO_SID'               => ['label'=>'Twilio SMS',       'critical'=>false],
    'EXCHANGE_API_KEY'         => ['label'=>'Exchange (Binance/OKX)', 'critical'=>false],
];
foreach ($keys as $env => [$label, $critical]) {
    $val = getenv($env);
    $ok  = !empty($val) && !in_array($val, ['your_api_key','your_secret','textbelt']);
    $icon = $ok ? '✓' : ($critical ? '✗' : '⚠');
    echo "$icon $label\n";
}

// ── 2. Hot Wallet رصيد ──────────────────────────────
echo "\n--- [2] Hot Wallet ---\n";
$hwAddr = getenv('HOT_WALLET_TRC20_ADDRESS');
if ($hwAddr) {
    $tgKey = getenv('TRONGRID_API_KEY');
    $ch    = curl_init("https://api.trongrid.io/v1/accounts/$hwAddr");
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5,
        CURLOPT_HTTPHEADER=>["TRON-PRO-API-KEY: $tgKey"]]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200) {
        $data    = json_decode($res, true);
        $trc20   = $data['data'][0]['trc20'] ?? [];
        $balance = 0;
        foreach ($trc20 as $c => $b) {
            if (strtolower($c) === 'tr7nhqjekqxgtci8q8zy4pl8otszgjlj6t') {
                $balance = $b / 1_000_000;
            }
        }
        $trxBal = ($data['data'][0]['balance'] ?? 0) / 1_000_000;
        echo ($balance > 0 ? '✓' : '✗') . " USDT: $balance\n";
        echo ($trxBal > 0 ? '✓' : '✗') . " TRX (Gas): $trxBal\n";
        if ($balance == 0) echo "  ← يجب إرسال USDT TRC20 للعنوان: $hwAddr\n";
        if ($trxBal < 10) echo "  ← يجب إرسال TRX للرسوم (Gas): $hwAddr\n";
    } else {
        echo "⚠ لا يمكن فحص الرصيد (HTTP $code)\n";
    }
}

// ── 3. بوابات نشطة ──────────────────────────────────
echo "\n--- [3] بوابات الدفع النشطة ---\n";
$active = $db->query("SELECT code, name FROM dp_payment_gateways WHERE status='active' AND code NOT IN ('integrated')");
foreach ($active as $g) echo "✓ {$g['name']} ({$g['code']})\n";

// ── 4. جداول DB ─────────────────────────────────────
echo "\n--- [4] قاعدة البيانات ---\n";
$tables = ['transactions','users','kyc_verifications','blockchain_txns','risk_logs','event_log','bulk_batches'];
foreach ($tables as $t) {
    try {
        $c = $db->query("SELECT COUNT(*) as c FROM dp_$t")[0]['c'] ?? 0;
        echo "✓ dp_$t = $c سجل\n";
    } catch (Exception $e) {
        echo "✗ dp_$t = غير موجود\n";
    }
}

// ── 5. إعدادات حرجة ─────────────────────────────────
echo "\n--- [5] إعدادات النظام ---\n";
echo (APP_ENV === 'production' ? '✓' : '⚠') . " APP_ENV = " . APP_ENV . "\n";
echo "✓ SITE_URL = " . SITE_URL . "\n";
echo (str_contains(SITE_URL,'https') ? '✓' : '⚠') . " HTTPS = " . (str_contains(SITE_URL,'https')?'مفعّل':'غير مفعّل') . "\n";

// ── 6. ما ينقص ──────────────────────────────────────
echo "\n=====================================\n";
echo "  ما ينقص النظام الآن:\n";
echo "=====================================\n";

$missing = [];

if (empty(getenv('HOT_WALLET_TRC20_ADDRESS'))) $missing[] = "CRITICAL: إعداد Hot Wallet TRC20";
if (APP_ENV !== 'production')                   $missing[] = "IMPORTANT: APP_ENV يجب أن يكون production على السيرفر";
if (empty(getenv('SUMSUB_APP_TOKEN')))          $missing[] = "KYC: ربط Sumsub للتحقق الرسمي";
if (empty(getenv('TWILIO_SID')))                $missing[] = "SMS: ربط Twilio لإرسال OTP";
if (empty(getenv('EXCHANGE_API_KEY')))          $missing[] = "Exchange: ربط Binance/OKX للشراء التلقائي";

if (empty($missing)) {
    echo "  ✓ النظام جاهز بالكامل!\n";
} else {
    foreach ($missing as $i => $m) {
        echo "  " . ($i+1) . ". $m\n";
    }
}
echo "=====================================\n";
