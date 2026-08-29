<?php
/**
 * DI PARMA | API v1 Documentation
 * HTML for browsers, JSON when requested with ?format=json or Accept: application/json.
 */
$docs = [
    'name' => 'DI PARMA API',
    'version' => 'v1',
    'base_url' => 'https://diparmas.com/api/v1',
    'authentication' => [
        'X-Api-Key' => 'dpk_... API key',
        'X-Timestamp' => 'Unix timestamp in seconds',
        'X-Signature' => 'HMAC-SHA256(api_secret, "api_key:timestamp:sha256(body)")',
    ],
    'resources' => [
        ['method' => 'POST', 'path' => '/api/v1/charge', 'title' => 'Payments', 'description' => 'Create a DI PARMA payment and queue delivery to Ledger TRC20.'],
        ['method' => 'GET', 'path' => '/api/v1/balance', 'title' => 'Ledger', 'description' => 'Read Ledger balance and account statistics.'],
        ['method' => 'GET', 'path' => '/api/v1/transactions', 'title' => 'Transactions', 'description' => 'List account transactions with filters.'],
        ['method' => 'GET', 'path' => '/api/v1/docs', 'title' => 'Documentation', 'description' => 'Read this documentation as HTML or JSON.'],
    ],
    'webhooks' => [
        'signature' => 'X-DiParma-Signature: HMAC-SHA256(webhook_secret, payload)',
        'events' => ['charge.completed', 'charge.failed', 'ledger.transferred'],
    ],
];

$acceptsJson = strtolower((string)($_GET['format'] ?? '')) === 'json'
    || str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
if ($acceptsJson) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($docs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$anchor = static fn($path): string => 'endpoint-' . md5($path);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA API Documentation</title>
<style>
:root{--ink:#16233b;--muted:#68758a;--line:#e5eaf1;--blue:#2864e8;--soft:#f5f8fc;--green:#138a5b}
*{box-sizing:border-box}body{margin:0;color:var(--ink);font:15px/1.6 Segoe UI,Arial,sans-serif;background:#fff}
.shell{display:grid;grid-template-columns:260px minmax(0,900px);min-height:100vh;max-width:1240px;margin:auto}
aside{border-right:1px solid var(--line);padding:28px 18px;background:#fbfcfe;position:sticky;top:0;height:100vh}.brand{font-weight:800;font-size:19px;margin:0 10px 28px}.brand span{color:var(--blue)}
.side-title{color:#96a1b2;font-size:12px;font-weight:700;text-transform:uppercase;margin:24px 10px 8px}.side-link{display:flex;justify-content:space-between;padding:8px 10px;color:#526176;text-decoration:none;border-radius:6px}.side-link:hover{background:#edf3ff;color:var(--blue)}
main{padding:28px 48px 70px}.nav{display:flex;justify-content:flex-end;gap:26px;border-bottom:1px solid var(--line);padding:0 0 18px;margin-bottom:54px}.nav a{color:#526176;text-decoration:none}.nav a:last-child{color:var(--blue);font-weight:700}
.eyebrow{color:var(--blue);font-size:13px;font-weight:700;margin-bottom:12px}h1{font-size:38px;line-height:1.15;margin:0 0 12px}h2{font-size:22px;margin:42px 0 14px}p{color:var(--muted);max-width:760px}.intro{font-size:17px}
.info-grid,.resource-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:30px}.info,.resource{border:1px solid var(--line);border-radius:8px;padding:18px;background:#fff}.info strong,.resource b{display:block;margin-bottom:6px}.mono,pre{font:13px/1.7 Consolas,monospace}.mono{color:#4d5b70}.resource{text-decoration:none;color:var(--ink)}.resource:hover{border-color:#a8c0f7;box-shadow:0 4px 12px #e9eef8}.resource span{font-size:13px;color:var(--muted)}
.endpoint{border:1px solid var(--line);border-radius:8px;margin:12px 0;overflow:hidden}.endpoint-head{display:flex;align-items:center;gap:14px;padding:14px 16px;background:var(--soft)}.method{font:700 12px Consolas,monospace;color:var(--green)}.path{font:14px Consolas,monospace}.endpoint p{margin:0;padding:0 16px 13px;font-size:14px}.security{border-left:3px solid var(--blue);background:#f4f7ff;padding:14px 18px;margin-top:16px}.security code{font:13px Consolas,monospace}.footer{margin-top:48px;color:#8a96a8;font-size:13px;border-top:1px solid var(--line);padding-top:18px}
+@media(max-width:800px){.shell{display:block}aside{position:static;height:auto;border-right:0;border-bottom:1px solid var(--line)}main{padding:24px 20px}.nav{justify-content:flex-start;flex-wrap:wrap;margin-bottom:34px}.info-grid,.resource-grid{grid-template-columns:1fr}h1{font-size:30px}}
+</style>
+</head>
<body>
<div class="shell">
<aside>
<div class="brand">DI PARMA <span>API</span></div><a class="side-link" href="#overview">Overview</a>
<div class="side-title">Resources</div>
<?php foreach($docs['resources'] as $resource): ?><a class="side-link" href="#<?=$anchor($resource['path'])?>"><span><?=$e($resource['path'])?></span><small><?=$e($resource['method'])?></small></a><?php endforeach; ?>
<div class="side-title">Events</div><a class="side-link" href="#webhooks">Webhooks</a>
</aside>
<main>
<nav class="nav"><a href="#overview">Overview</a><a href="#resources">Resources</a><a href="?format=json">JSON Docs</a></nav>
<section id="overview"><div class="eyebrow">API / DOCUMENTATION</div><h1>DI PARMA API</h1><p class="intro">The official DI PARMA payment API for secure payments, account balances, transaction monitoring, and Ledger TRC20 delivery.</p>
<div class="info-grid"><div class="info"><strong>Base URL</strong><div class="mono"><?=$e($docs['base_url'])?></div></div><div class="info"><strong>Authentication</strong><div class="mono">Bearer YOUR_API_KEY + HMAC-SHA256</div></div></div></section>
<section id="resources"><h2>Resources</h2><div class="resource-grid"><?php foreach($docs['resources'] as $resource): ?><a class="resource" href="#<?=$anchor($resource['path'])?>"><b><?=$e($resource['title'])?></b><span><?=$e($resource['description'])?></span></a><?php endforeach; ?></div></section>
<section><h2>Authentication</h2><p>Every protected request must include the API key, a current Unix timestamp, and the HMAC signature.</p><div class="security"><code>X-Api-Key: dpk_...<br>X-Timestamp: 1753200000<br>X-Signature: HMAC-SHA256(api_secret, "api_key:timestamp:sha256(body)")</code></div></section>
<section><h2>Endpoints</h2><?php foreach($docs['resources'] as $resource): ?><article class="endpoint" id="<?=$anchor($resource['path'])?>"><div class="endpoint-head"><span class="method"><?=$e($resource['method'])?></span><span class="path"><?=$e($resource['path'])?></span></div><p><?=$e($resource['description'])?></p></article><?php endforeach; ?></section>
<section id="webhooks"><h2>Webhooks</h2><p>DI PARMA signs webhook payloads with your webhook secret.</p><div class="security"><code><?=$e($docs['webhooks']['signature'])?></code><br><br>Events: <?=$e(implode(', ',$docs['webhooks']['events']))?></div></section>
<div class="footer">DI PARMA API v1 · JSON specification available with <span class="mono">Accept: application/json</span></div>
</main></div>
</body></html>
