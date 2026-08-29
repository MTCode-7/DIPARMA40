<?php
/**
 * BIN Lookup API — يكشف معلومات البطاقة من أول 6-8 أرقام
 */
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$bin = trim($_GET['bin'] ?? '');
$bin = preg_replace('/\D/', '', $bin);

if (strlen($bin) < 6) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'BIN too short. Minimum 6 digits required.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$bin6 = substr($bin, 0, 6);

// ── 1. قاعدة بيانات BIN محلية (الأشهر) ──────────────────────
$localBins = [
    // Visa
    '4'  => ['scheme' => 'Visa',       'type' => 'credit', 'brand' => 'Visa', 'color' => '#1a1f71', 'icon' => 'fab fa-cc-visa'],

    // Mastercard
    '51' => ['scheme' => 'Mastercard', 'type' => 'credit', 'brand' => 'Mastercard', 'color' => '#eb001b', 'icon' => 'fab fa-cc-mastercard'],
    '52' => ['scheme' => 'Mastercard', 'type' => 'credit', 'brand' => 'Mastercard', 'color' => '#eb001b', 'icon' => 'fab fa-cc-mastercard'],
    '53' => ['scheme' => 'Mastercard', 'type' => 'credit', 'brand' => 'Mastercard', 'color' => '#eb001b', 'icon' => 'fab fa-cc-mastercard'],
    '54' => ['scheme' => 'Mastercard', 'type' => 'credit', 'brand' => 'Mastercard', 'color' => '#eb001b', 'icon' => 'fab fa-cc-mastercard'],
    '55' => ['scheme' => 'Mastercard', 'type' => 'credit', 'brand' => 'Mastercard', 'color' => '#eb001b', 'icon' => 'fab fa-cc-mastercard'],
    '2221' => ['scheme' => 'Mastercard', 'type' => 'credit', 'brand' => 'Mastercard', 'color' => '#eb001b', 'icon' => 'fab fa-cc-mastercard'],
    '2720' => ['scheme' => 'Mastercard', 'type' => 'credit', 'brand' => 'Mastercard', 'color' => '#eb001b', 'icon' => 'fab fa-cc-mastercard'],

    // Amex
    '34' => ['scheme' => 'Amex', 'type' => 'credit', 'brand' => 'American Express', 'color' => '#007bc1', 'icon' => 'fab fa-cc-amex'],
    '37' => ['scheme' => 'Amex', 'type' => 'credit', 'brand' => 'American Express', 'color' => '#007bc1', 'icon' => 'fab fa-cc-amex'],

    // Discover
    '6011' => ['scheme' => 'Discover', 'type' => 'credit', 'brand' => 'Discover', 'color' => '#ff6600', 'icon' => 'fab fa-cc-discover'],
    '644'  => ['scheme' => 'Discover', 'type' => 'credit', 'brand' => 'Discover', 'color' => '#ff6600', 'icon' => 'fab fa-cc-discover'],
    '65'   => ['scheme' => 'Discover', 'type' => 'credit', 'brand' => 'Discover', 'color' => '#ff6600', 'icon' => 'fab fa-cc-discover'],

    // UnionPay
    '62' => ['scheme' => 'UnionPay', 'type' => 'debit', 'brand' => 'UnionPay', 'color' => '#e21e28', 'icon' => 'fas fa-credit-card'],

    // Mada (Saudi)
    '588845' => ['scheme' => 'Mada',  'type' => 'debit',  'brand' => 'Mada - Saudi Arabia', 'color' => '#00843d', 'icon' => 'fas fa-credit-card', 'bank' => 'Saudi Banks', 'country' => 'SA'],
    '968201' => ['scheme' => 'Mada',  'type' => 'debit',  'brand' => 'Mada',                'color' => '#00843d', 'icon' => 'fas fa-credit-card', 'bank' => 'Al Rajhi Bank', 'country' => 'SA'],
    '968202' => ['scheme' => 'Mada',  'type' => 'debit',  'brand' => 'Mada',                'color' => '#00843d', 'icon' => 'fas fa-credit-card', 'bank' => 'Al Rajhi Bank', 'country' => 'SA'],

    // KNET (Kuwait)
    '888822' => ['scheme' => 'KNET', 'type' => 'debit', 'brand' => 'KNET - Kuwait', 'color' => '#1d5ea8', 'icon' => 'fas fa-credit-card', 'bank' => 'Kuwait Banks', 'country' => 'KW'],

    // Meeza (Egypt)
    '507803' => ['scheme' => 'Meeza', 'type' => 'debit', 'brand' => 'Meeza - Egypt', 'color' => '#007a4d', 'icon' => 'fas fa-credit-card', 'bank' => 'Egyptian Banks', 'country' => 'EG'],
];

// بحث في القاعدة المحلية (من الأطول للأقصر)
$found = null;
foreach ([8, 7, 6, 5, 4, 3, 2, 1] as $len) {
    $prefix = substr($bin6, 0, $len);
    if (isset($localBins[$prefix])) {
        $found = $localBins[$prefix];
        break;
    }
}

// ── 2. إذا وُجد في القاعدة المحلية ──────────────────────────
if ($found) {
    echo json_encode([
        'success' => true,
        'source'  => 'local',
        'bin'     => $bin6,
        'scheme'  => $found['scheme'],
        'type'    => $found['type'],
        'brand'   => $found['brand'],
        'bank'    => $found['bank'] ?? null,
        'country' => $found['country'] ?? null,
        'color'   => $found['color'],
        'icon'    => $found['icon'],
        'prepaid' => false,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 3. استعلام binlist.net (مجاني، لا يحتاج مفتاح) ──────────
$url = 'https://lookup.binlist.net/' . $bin6;
$ctx = stream_context_create([
    'http' => [
        'timeout'        => 5,
        'user_agent'     => 'DI-PARMA/3.0',
        'ignore_errors'  => true,
    ],
    'ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
    ],
]);

$resp = @file_get_contents($url, false, $ctx);
if ($resp === false) {
    // fallback: استعلام bintable.com
    $resp2 = @file_get_contents('https://api.bintable.com/v1/' . $bin6 . '?api_key=free', false, $ctx);
    if ($resp2) $resp = $resp2;
}

if ($resp) {
    $data = json_decode($resp, true);
    if ($data && !isset($data['error'])) {
        // Normalize binlist.net response
        $scheme  = strtolower($data['scheme'] ?? $data['brand'] ?? '');
        $brand   = ucfirst($scheme);
        $type    = strtolower($data['type'] ?? 'credit');
        $bank    = $data['bank']['name'] ?? $data['issuer'] ?? null;
        $country = $data['country']['alpha2'] ?? $data['country_code'] ?? null;
        $countryName = $data['country']['name'] ?? null;
        $prepaid = ($data['prepaid'] ?? false) ? true : false;
        $luhn = $data['luhn'] ?? true;

        $icons = [
            'visa'       => 'fab fa-cc-visa',
            'mastercard' => 'fab fa-cc-mastercard',
            'amex'       => 'fab fa-cc-amex',
            'discover'   => 'fab fa-cc-discover',
            'jcb'        => 'fab fa-cc-jcb',
            'diners'     => 'fab fa-cc-diners-club',
            'unionpay'   => 'fas fa-credit-card',
            'mada'       => 'fas fa-credit-card',
        ];
        $colors = [
            'visa'       => '#1a1f71',
            'mastercard' => '#eb001b',
            'amex'       => '#007bc1',
            'discover'   => '#ff6600',
            'unionpay'   => '#e21e28',
            'mada'       => '#00843d',
        ];

        echo json_encode([
            'success'      => true,
            'source'       => 'binlist',
            'bin'          => $bin6,
            'scheme'       => $brand,
            'brand'        => $brand,
            'bank'         => $bank,
            'country'      => $country,
            'country_name' => $countryName,
            'color'        => $colors[$scheme] ?? '#FFD700',
            'icon'         => $icons[$scheme] ?? 'fas fa-credit-card',
            'prepaid'      => $prepaid,
            'luhn'         => $luhn,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ── 4. Fallback: كشف من الرقم الأول فقط ──────────────────────
$first = substr($bin6, 0, 1);
$fallbacks = [
    '4' => ['scheme' => 'Visa',       'icon' => 'fab fa-cc-visa',       'color' => '#1a1f71'],
    '5' => ['scheme' => 'Mastercard', 'icon' => 'fab fa-cc-mastercard', 'color' => '#eb001b'],
    '6' => ['scheme' => 'Discover',   'icon' => 'fab fa-cc-discover',   'color' => '#ff6600'],
    '3' => ['scheme' => 'Amex',       'icon' => 'fab fa-cc-amex',       'color' => '#007bc1'],
];

$fb = $fallbacks[$first] ?? ['scheme' => 'Unknown', 'icon' => 'fas fa-credit-card', 'color' => '#888'];
echo json_encode([
    'success' => true,
    'source'  => 'fallback',
    'bin'     => $bin6,
    'scheme'  => $fb['scheme'],
    'type'    => 'credit',
    'brand'   => $fb['scheme'],
    'bank'    => null,
    'country' => null,
    'color'   => $fb['color'],
    'icon'    => $fb['icon'],
    'prepaid' => false,
], JSON_UNESCAPED_UNICODE);
