<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function marketGet(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'DIPARMA-Market-Board/1.0',
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $status < 200 || $status >= 300) return null;
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

$result = ['updated_at' => gmdate('c'), 'crypto' => [], 'stocks' => [], 'forex' => [], 'commodities' => []];
$cryptoSymbols = ['BTCUSDT', 'ETHUSDT', 'BNBUSDT', 'TRXUSDT'];
$crypto = marketGet('https://api.binance.com/api/v3/ticker/24hr?symbols=' . rawurlencode(json_encode($cryptoSymbols)));
if (is_array($crypto)) {
    foreach ($crypto as $quote) {
        if (!isset($quote['symbol'], $quote['lastPrice'])) continue;
        $result['crypto'][] = [
            'symbol' => $quote['symbol'],
            'price' => (float)$quote['lastPrice'],
            'change' => (float)($quote['priceChangePercent'] ?? 0),
            'source' => 'Binance',
        ];
    }
}

$yahooSymbols = [
    'stocks' => ['AAPL', 'MSFT', 'AMZN', 'NVDA', 'TSLA'],
    'forex' => ['EURUSD=X', 'GBPUSD=X', 'USDJPY=X'],
    'commodities' => ['GC=F', 'CL=F'],
];
foreach ($yahooSymbols as $group => $symbols) {
    $query = rawurlencode(implode(',', $symbols));
    $data = marketGet('https://query1.finance.yahoo.com/v7/finance/quote?symbols=' . $query);
    $received = [];
    foreach (($data['quoteResponse']['result'] ?? []) as $quote) {
        if (!isset($quote['symbol'], $quote['regularMarketPrice'])) continue;
        $received[$quote['symbol']] = true;
        $result[$group][] = [
            'symbol' => $quote['symbol'],
            'price' => (float)$quote['regularMarketPrice'],
            'change' => (float)($quote['regularMarketChangePercent'] ?? 0),
            'source' => 'Yahoo Finance',
        ];
    }
    foreach ($symbols as $symbol) {
        if (isset($received[$symbol])) continue;
        $chart = marketGet('https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($symbol) . '?range=1d&interval=1m');
        $meta = $chart['chart']['result'][0]['meta'] ?? [];
        $price = $meta['regularMarketPrice'] ?? null;
        if ($price === null) continue;
        $previous = (float)($meta['chartPreviousClose'] ?? 0);
        $change = $previous > 0 ? (($price - $previous) / $previous) * 100 : 0;
        $result[$group][] = [
            'symbol' => $symbol,
            'price' => (float)$price,
            'change' => (float)$change,
            'source' => 'Yahoo Finance',
        ];
    }
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
