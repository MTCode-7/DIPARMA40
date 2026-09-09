<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/gateways.php';
require_once __DIR__ . '/../includes/auth_check.php';

requireAdmin();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$db = db();
$code = strtolower(trim((string)($_GET['code'] ?? '')));
if ($code === '') {
    http_response_code(400);
    exit('Gateway code is required.');
}

$gateway = $db->find('payment_gateways', ['code' => $code]);
if (!$gateway) {
    http_response_code(404);
    exit('Gateway not found.');
}

$status = strtolower((string)($gateway['status'] ?? ''));
$connectionStatus = strtolower((string)($gateway['connection_status'] ?? ''));
$isConnected = $status === 'active' && $connectionStatus === 'verified';

$config = json_decode((string)($gateway['config'] ?? '{}'), true) ?: [];
$settings = json_decode((string)($gateway['settings'] ?? '{}'), true) ?: [];
$credentials = json_decode((string)($gateway['credentials'] ?? '{}'), true) ?: [];
$catalog = getGatewayConfig($code) ?: [];
$mergedConfig = array_replace_recursive($catalog, $config);
$environment = (string)($settings['environment'] ?? $mergedConfig['environment'] ?? 'live');
$endpoint = (string)($gateway['api_endpoint'] ?? $credentials['api_url'] ?? $mergedConfig['api_base'] ?? '');

function detailsCurl(string $method, string $url, array $headers = [], string $body = '', string $userPassword = ''): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($body !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    if ($userPassword !== '') curl_setopt($ch, CURLOPT_USERPWD, $userPassword);
    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'data' => json_decode((string)$raw, true) ?: [], 'error' => $error];
}

function gatewayLiveBalance(string $code): array
{
    $result = ['value' => null, 'message' => 'Balance API is not available for this gateway.'];
    if ($code === 'stripe') {
        $key = (string)(getenv('STRIPE_SECRET_KEY') ?: '');
        if ($key === '') return ['value' => null, 'message' => 'Stripe secret key is not configured.'];
        $response = detailsCurl('GET', 'https://api.stripe.com/v1/balance', ['Authorization: Bearer ' . $key]);
        if ($response['status'] === 200 && !empty($response['data']['available'])) {
            $parts = [];
            foreach ($response['data']['available'] as $balance) {
                $parts[] = number_format(((float)($balance['amount'] ?? 0)) / 100, 2) . ' ' . strtoupper((string)($balance['currency'] ?? ''));
            }
            return ['value' => implode(' | ', $parts), 'message' => 'Fetched from Stripe live balance API.'];
        }
        return ['value' => null, 'message' => 'Stripe balance request failed (HTTP ' . $response['status'] . ').'];
    }

    if ($code === 'paypal') {
        $clientId = (string)(getenv('PAYPAL_CLIENT_ID') ?: '');
        $secret = (string)(getenv('PAYPAL_CLIENT_SECRET') ?: getenv('PAYPAL_SECRET') ?: '');
        if ($clientId === '' || $secret === '') return ['value' => null, 'message' => 'PayPal live credentials are not configured.'];
        $tokenResponse = detailsCurl('POST', 'https://api-m.paypal.com/v1/oauth2/token', ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'], 'grant_type=client_credentials', $clientId . ':' . $secret);
        $token = (string)($tokenResponse['data']['access_token'] ?? '');
        if ($token === '') return ['value' => null, 'message' => 'PayPal live authentication failed.'];
        $balanceResponse = detailsCurl('GET', 'https://api-m.paypal.com/v1/reporting/balances', ['Authorization: Bearer ' . $token, 'Content-Type: application/json']);
        if ($balanceResponse['status'] === 200 && !empty($balanceResponse['data']['balances'])) {
            $parts = [];
            foreach ($balanceResponse['data']['balances'] as $balance) {
                $total = $balance['total_balance'] ?? [];
                $parts[] = number_format((float)($total['value'] ?? 0), 2) . ' ' . (string)($total['currency_code'] ?? '');
            }
            return ['value' => implode(' | ', $parts), 'message' => 'Fetched from PayPal live balance API.'];
        }
        return ['value' => null, 'message' => 'PayPal balance request failed (HTTP ' . $balanceResponse['status'] . ').'];
    }

    if ($code === 'wise') {
        $key = (string)(getenv('WISE_API_KEY') ?: '');
        if ($key === '') return ['value' => null, 'message' => 'Wise live API key is not configured.'];
        $headers = ['Authorization: Bearer ' . $key];
        $profiles = detailsCurl('GET', 'https://api.wise.com/v1/profiles', $headers);
        $profileId = (string)($profiles['data'][0]['id'] ?? '');
        if ($profileId === '') return ['value' => null, 'message' => 'Wise profile lookup failed (HTTP ' . $profiles['status'] . ').'];
        $balances = detailsCurl('GET', 'https://api.wise.com/v4/profiles/' . rawurlencode($profileId) . '/balances?types=STANDARD', $headers);
        if ($balances['status'] === 200 && is_array($balances['data'])) {
            $parts = [];
            foreach ($balances['data'] as $balance) {
                if (isset($balance['amount']['value'], $balance['amount']['currency'])) {
                    $parts[] = number_format((float)$balance['amount']['value'], 2) . ' ' . $balance['amount']['currency'];
                }
            }
            return ['value' => implode(' | ', $parts) ?: '0.00', 'message' => 'Fetched from Wise live balance API.'];
        }
        return ['value' => null, 'message' => 'Wise balance request failed (HTTP ' . $balances['status'] . ').'];
    }

    return $result;
}

$balance = $isConnected
    ? gatewayLiveBalance($code)
    : ['value' => null, 'message' => 'Unavailable until the gateway is active and connected.'];
$transactions = $db->query(
    'SELECT id, reference, amount, currency, transaction_type, status, created_at FROM ' . DB_PREFIX . 'transactions WHERE gateway = ? ORDER BY created_at DESC LIMIT 10',
    [$code]
);

$features = array_values(array_unique(array_merge(
    (array)($mergedConfig['features'] ?? []),
    !empty($gateway['supports_hold']) ? ['authorization'] : [],
    !empty($gateway['supports_capture']) ? ['capture'] : [],
    !empty($gateway['supports_2d']) ? ['online'] : [],
    !empty($gateway['supports_offline']) ? ['offline'] : []
)));
$capabilities = [
    'Purchase' => true,
    'Authorization / Hold' => in_array('authorization', $features, true) || !empty($gateway['supports_hold']),
    'Capture / Completion' => in_array('capture', $features, true) || !empty($gateway['supports_capture']),
    'Refund' => in_array('refund', $features, true),
    'Online' => in_array('online', $features, true) || empty($gateway['supports_offline']),
    'Offline' => in_array('offline', $features, true),
];
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DI PARMA | <?= $escape($gateway['name']) ?> Details</title>
<style>
:root{--bg:#071018;--panel:#101c27;--line:#213545;--text:#eaf2f7;--muted:#8fa5b4;--gold:#ffd34e;--green:#54d18b;--red:#ff7777;--blue:#71b7ff}*{box-sizing:border-box}body{margin:0;background:linear-gradient(145deg,#071018,#0c1722);color:var(--text);font-family:Arial,sans-serif}main{max-width:1280px;margin:0 auto;padding:28px}.top{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:24px}.back{color:var(--gold);text-decoration:none}.eyebrow{color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:1px}.title{display:flex;align-items:center;gap:14px}.title h1{margin:4px 0;font-size:30px}.code{color:var(--muted);font-family:monospace}.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}.panel{background:rgba(16,28,39,.92);border:1px solid var(--line);border-radius:12px;padding:18px}.metric{min-height:110px}.metric h3{margin:0 0 12px;font-size:13px;color:var(--muted)}.metric strong{font-size:22px;color:var(--gold);word-break:break-word}.layout{display:grid;grid-template-columns:1fr 1.35fr;gap:16px;margin-top:16px}.panel h2{font-size:17px;margin:0 0 16px}.meta{display:grid;grid-template-columns:150px 1fr;gap:10px;border-top:1px solid var(--line);padding:10px 0}.meta:first-of-type{border-top:0}.meta span:first-child{color:var(--muted)}.capabilities{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}.cap{border:1px solid var(--line);padding:11px;border-radius:8px;display:flex;justify-content:space-between}.yes{color:var(--green)}.no{color:var(--red)}table{width:100%;border-collapse:collapse;font-size:13px}th,td{text-align:left;padding:11px 8px;border-bottom:1px solid var(--line)}th{color:var(--muted);font-weight:normal}.status{color:var(--green)}.muted{color:var(--muted)}@media(max-width:850px){.grid,.layout{grid-template-columns:1fr 1fr}.layout{display:block}.layout>.panel{margin-top:16px}}@media(max-width:560px){main{padding:16px}.grid,.capabilities{grid-template-columns:1fr}.title h1{font-size:23px}.top{align-items:flex-start;flex-direction:column}.meta{grid-template-columns:120px 1fr}}
</style>
</head>
<body>
<main>
<div class="top"><div class="title"><div><div class="eyebrow">Payment Gateway Details</div><h1><?= $escape($gateway['name']) ?></h1><div class="code"><?= $escape($code) ?></div></div><span class="<?= $isConnected ? 'status' : 'muted' ?>"><?= $escape(ucfirst($status ?: 'unknown')) ?> · <?= $escape(ucfirst($connectionStatus ?: 'untested')) ?></span></div><a class="back" href="gateway_manager.php">Back to Gateway Manager</a></div>
<div class="grid">
<div class="panel metric"><h3>Live Balance</h3><strong><?= $balance['value'] !== null ? $escape($balance['value']) : 'Unavailable' ?></strong><div class="muted"><?= $escape($balance['message']) ?></div></div>
<div class="panel metric"><h3>Environment</h3><strong><?= $escape($environment ?: 'Not specified') ?></strong><div class="muted">No simulated values</div></div>
<div class="panel metric"><h3>Recent Transactions</h3><strong><?= count($transactions) ?></strong><div class="muted">Latest 10 records</div></div>
<div class="panel metric"><h3>Last Connection Test</h3><strong><?= $escape($gateway['last_tested'] ?? 'Unavailable') ?></strong><div class="muted"><?= $escape($gateway['test_response_ms'] ?? '') ?><?= !empty($gateway['test_response_ms']) ? ' ms' : '' ?></div></div>
</div>
<div class="layout">
<section class="panel"><h2>Gateway Information</h2><div class="meta"><span>Type</span><strong><?= $escape($gateway['type'] ?? 'Payment Gateway') ?></strong></div><div class="meta"><span>Connection</span><strong><?= $escape($gateway['connection_type'] ?? 'REST') ?></strong></div><div class="meta"><span>Endpoint</span><strong><?= $escape($endpoint ?: 'Unavailable') ?></strong></div><div class="meta"><span>Currencies</span><strong><?= $escape(implode(', ', (array)($mergedConfig['currencies'] ?? [])) ?: 'Unavailable') ?></strong></div><div class="meta"><span>Features</span><strong><?= $escape(implode(', ', $features) ?: 'Unavailable') ?></strong></div></section>
<section class="panel"><h2>Payment Operation Modes</h2><div class="capabilities"><?php foreach ($capabilities as $label => $supported): ?><div class="cap"><span><?= $escape($label) ?></span><strong class="<?= $supported ? 'yes' : 'no' ?>"><?= $supported ? 'Available' : 'Unavailable' ?></strong></div><?php endforeach; ?></div><p class="muted">Capabilities are derived from the gateway configuration and recorded transaction fields.</p></section>
</div>
<section class="panel" style="margin-top:16px"><h2>Last 10 Transactions</h2><?php if (!$transactions): ?><p class="muted">No transactions recorded for this gateway.</p><?php else: ?><table><thead><tr><th>Reference</th><th>Amount</th><th>Type</th><th>Status</th><th>Created</th></tr></thead><tbody><?php foreach ($transactions as $transaction): ?><tr><td><?= $escape($transaction['reference']) ?></td><td><?= $escape(number_format((float)$transaction['amount'], 2) . ' ' . $transaction['currency']) ?></td><td><?= $escape($transaction['transaction_type'] ?: 'Purchase') ?></td><td><?= $escape($transaction['status']) ?></td><td><?= $escape($transaction['created_at']) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></section>
</main>
</body>
</html>
