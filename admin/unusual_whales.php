<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../lib/UnusualWhalesClient.php';
requireAdmin();

$client = new UnusualWhalesClient();
$ticker = strtoupper(trim($_GET['ticker'] ?? 'AAPL'));
$gex = $client->gexLevels($ticker);
$darkpool = $client->darkpoolRecent(['limit' => 25, 'order' => 'desc']);
function uw_escape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?><!doctype html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>DI PARMA | Market Intelligence</title>
    <style>
        :root{font-family:Arial,sans-serif;color:#e8eef7;background:#07111f}*{box-sizing:border-box}body{margin:0}main{max-width:1200px;margin:auto;padding:28px}.top{display:flex;justify-content:space-between;gap:16px;align-items:end;border-bottom:1px solid #20344d;padding-bottom:20px;margin-bottom:22px}h1{margin:0;font-size:1.6rem}p{color:#9eb0c5}.toolbar{display:flex;gap:8px;align-items:center}.toolbar input,.toolbar button{padding:10px 12px;border-radius:8px;border:1px solid #2a405a;background:#0d1b2d;color:#fff}.toolbar button{background:#b8ed45;color:#101a08;font-weight:700;cursor:pointer}.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px}.card,.table{border:1px solid #20344d;background:#0d1b2d;border-radius:10px;padding:18px}.label{color:#8195ad;font-size:.8rem}.value{font-size:1.35rem;font-weight:700;margin-top:8px}.table{overflow:auto}table{width:100%;border-collapse:collapse;font-size:.85rem}th,td{text-align:left;padding:10px;border-bottom:1px solid #20344d;white-space:nowrap}th{color:#9eb0c5}.error{border-color:#7f3343;color:#ff9eae;padding:14px;border-radius:8px;margin-bottom:16px}@media(max-width:760px){main{padding:16px}.top{display:block}.toolbar{margin-top:16px}.grid{grid-template-columns:1fr 1fr}}
    </style>
</head>
<body><main>
    <header class="top"><div><p>DI PARMA ADMIN</p><h1>Market Intelligence</h1></div><form class="toolbar"><label for="ticker">Ticker</label><input id="ticker" name="ticker" value="<?=uw_escape($ticker)?>" maxlength="10" pattern="[A-Za-z0-9.\-]+"><button type="submit">Load</button></form></header>
    <?php if (!$client->isConfigured()): ?><div class="error">UNUSUAL_WHALES_API_KEY is not configured on this server.</div><?php endif; ?>
    <?php if (!$gex['success']): ?><div class="error">GEX: <?=uw_escape($gex['message'] ?? 'Request failed')?></div><?php else: $levels = is_array($gex['data']) ? $gex['data'] : []; ?>
    <section class="grid">
        <?php foreach (['call_wall'=>'Call wall','put_wall'=>'Put wall','gamma_flip'=>'Gamma flip','gamma_magnet'=>'Gamma magnet'] as $key=>$label): ?><div class="card"><div class="label"><?=uw_escape($label)?></div><div class="value"><?=uw_escape($levels[$key] ?? 'N/A')?></div></div><?php endforeach; ?>
    </section><?php endif; ?>
    <section class="table"><h2>Recent Dark Pool Trades</h2><?php if (!$darkpool['success']): ?><div class="error"><?=uw_escape($darkpool['message'] ?? 'Request failed')?></div><?php else: $trades = is_array($darkpool['data']) ? $darkpool['data'] : []; ?>
    <table><thead><tr><th>Ticker</th><th>Premium</th><th>Size</th><th>Price</th><th>Executed</th></tr></thead><tbody><?php foreach ($trades as $trade): ?><tr><td><?=uw_escape($trade['ticker'] ?? '')?></td><td><?=uw_escape($trade['premium'] ?? '')?></td><td><?=uw_escape($trade['size'] ?? '')?></td><td><?=uw_escape($trade['price'] ?? '')?></td><td><?=uw_escape($trade['executed_at'] ?? '')?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></section>
</main></body></html>