<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
$db = db();

$wallets = [
    ['binance',        'Binance',         'crypto',  '#f3ba2f', 'fab fa-bitcoin',            'custodial',     'منصة عالمية رائدة — محفظة مدمجة للتداول السريع'],
    ['coinbase_ex',    'Coinbase',        'crypto',  '#0052ff', 'fas fa-circle-dollar-sign', 'custodial',     'محفظة المنصة المركزية الشهيرة للمبتدئين'],
    ['kraken',         'Kraken',          'crypto',  '#5741d9', 'fas fa-anchor',             'custodial',     'معايير أمان عالية وحفظ مؤسسي'],
    ['bybit',          'Bybit',           'crypto',  '#f7a600', 'fas fa-chart-line',         'custodial',     'محفظة مرتبطة بمنصة المشتقات والتداول الفوري'],
    ['okx',            'OKX',             'crypto',  '#333333', 'fas fa-circle-o',           'custodial',     'خدمات حفظ وحسابات متكاملة'],
    ['kucoin',         'KuCoin',          'crypto',  '#23af91', 'fas fa-share-nodes',        'custodial',     'يدعم مئات العملات الرقمية'],
    ['gate_io',        'Gate.io',         'crypto',  '#e8112d', 'fas fa-door-open',          'custodial',     'حسابات وصاية لحفظ الأصول'],
    ['gemini',         'Gemini',          'crypto',  '#00dcfa', 'fas fa-gem',                'custodial',     'امتثال تنظيمي عالي وحماية متقدمة'],
    ['bitfinex',       'Bitfinex',        'crypto',  '#16b157', 'fas fa-infinity',           'custodial',     'خدمات محفظة للمتداولين المحترفين'],
    ['mexc',           'MEXC',            'crypto',  '#2354e6', 'fas fa-coins',              'custodial',     'تشكيلة واسعة من العملات'],
    ['trust_wallet',   'Trust Wallet',    'wallet',  '#3375bb', 'fas fa-shield-halved',      'non_custodial', 'يدعم 100+ شبكة بلوكتشين'],
    ['metamask',       'MetaMask',        'wallet',  '#f6851b', 'fas fa-fox',                'non_custodial', 'المعيار للتعامل مع Ethereum/EVM'],
    ['phantom',        'Phantom',         'wallet',  '#ab9ff2', 'fas fa-ghost',              'non_custodial', 'الأبرز لمنظومة شبكة Solana'],
    ['ledger_live',    'Ledger Live',     'wallet',  '#222222', 'fas fa-hard-drive',         'non_custodial', 'محفظة الأجهزة الباردة بدون إنترنت'],
    ['exodus',         'Exodus',          'wallet',  '#0b46f9', 'fas fa-door-closed',        'non_custodial', 'واجهة ممتازة على سطح المكتب والهاتف'],
    ['electrum',       'Electrum',        'wallet',  '#1a9ed4', 'fab fa-bitcoin',            'non_custodial', 'متخصصة حصرياً في Bitcoin'],
    ['coinbase_wallet','Coinbase Wallet', 'wallet',  '#0052ff', 'fas fa-wallet',             'non_custodial', 'النسخة اللامركزية للتحكم الكامل'],
    ['zengo',          'ZenGo',           'wallet',  '#5a4fff', 'fas fa-key',                'non_custodial', 'تقنية MPC بدون عبارة استرداد'],
    ['rabby',          'Rabby Wallet',    'wallet',  '#7a7cff', 'fas fa-shield-alt',         'non_custodial', 'مخصصة لـ DeFi مع فحص أمني'],
    ['safepal',        'SafePal',         'wallet',  '#1a1a1a', 'fas fa-shield',             'non_custodial', 'محفظة برمجية وأجهزة باردة'],
];

$added = 0; $skipped = 0;

foreach ($wallets as [$code, $name, $type, $color, $icon, $wallet_type, $desc]) {
    $exists = $db->find('payment_gateways', ['code' => $code]);
    if ($exists) { echo "skip: $name\n"; $skipped++; continue; }

    $db->insert('payment_gateways', [
        'code'        => $code,
        'name'        => $name,
        'type'        => $type,
        'status'      => 'inactive',
        'config'      => json_encode([
            'region'       => 'Global',
            'icon'         => $icon,
            'color'        => $color,
            'wallet_type'  => $wallet_type,
            'description'  => $desc,
            'currencies'   => ['USDT','BTC','ETH','BNB','SOL','TRX'],
            'features'     => $wallet_type === 'custodial'
                ? ['custodial','exchange','trading','api']
                : ['non_custodial','self_custody','web3','dex'],
            'fees'         => ['percentage' => 0, 'fixed' => 0],
            'limits'       => ['min' => 1, 'max_daily' => 999999999],
            'environment'  => 'mainnet',
        ]),
        'credentials' => json_encode([
            'api_key'    => '',
            'secret_key' => '',
            'wallet_id'  => '',
            'passphrase' => '',
        ]),
        'settings'    => json_encode([
            'environment' => 'mainnet',
            'webhook_url' => 'https://diparmas.com/api/webhook.php?gateway=' . $code,
        ]),
    ]);
    echo "added: $name ($wallet_type)\n";
    $added++;
}

echo "\nadded=$added skipped=$skipped total=" . ($added+$skipped) . "\n";
