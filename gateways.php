<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth_check.php';

requireAdmin();
$gateways = db()->query('SELECT code, name, type, status, connection_status, last_tested, test_response_ms FROM ' . DB_PREFIX . 'payment_gateways ORDER BY status DESC, connection_status DESC, name ASC');
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DI PARMA | Payment Gateways</title>
<style>
:root{--bg:#071018;--panel:#101c27;--line:#213545;--text:#eaf2f7;--muted:#8fa5b4;--gold:#ffd34e;--green:#54d18b;--red:#ff7777;--blue:#71b7ff}*{box-sizing:border-box}body{margin:0;background:linear-gradient(145deg,#071018,#0c1722);color:var(--text);font-family:Arial,sans-serif}main{max-width:1250px;margin:0 auto;padding:28px}.top{display:flex;align-items:center;justify-content:space-between;margin-bottom:25px}.eyebrow{color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:1px}.top h1{margin:5px 0;font-size:30px}.back{color:var(--gold);text-decoration:none}.summary{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:18px}.summary span{background:var(--panel);border:1px solid var(--line);border-radius:8px;padding:11px 15px;color:var(--muted)}.summary strong{color:var(--gold);margin-right:6px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px}.section-divider{grid-column:1/-1;border-top:1px solid var(--gold);color:var(--gold);font-size:13px;font-weight:bold;padding:18px 4px 4px;margin-top:8px}.card{background:rgba(16,28,39,.94);border:1px solid var(--line);border-radius:12px;padding:18px}.card.connected{border-color:rgba(84,209,139,.55)}.card-head{display:flex;justify-content:space-between;gap:10px}.card h2{font-size:18px;margin:0 0 5px}.code{color:var(--muted);font:12px monospace}.badge{font-size:12px;white-space:nowrap}.connected .badge{color:var(--green)}.failed{color:var(--red)}.inactive{color:var(--muted)}.meta{display:grid;grid-template-columns:105px 1fr;gap:8px;padding:9px 0;border-bottom:1px solid rgba(33,53,69,.6);font-size:13px}.meta span{color:var(--muted)}.actions{display:flex;gap:8px;margin-top:16px}.button{display:inline-block;text-decoration:none;border:1px solid var(--gold);border-radius:7px;padding:9px 12px;color:#071018;background:var(--gold);font-size:13px;font-weight:bold}.button.secondary{background:transparent;color:var(--gold)}@media(max-width:600px){main{padding:16px}.top{align-items:flex-start;gap:12px;flex-direction:column}}
</style>
</head>
<body>
<main>
<div class="top"><div><div class="eyebrow">Direct Gateway Access</div><h1>Payment Gateways</h1></div><a class="back" href="admin/gateway_manager.php">Manage Gateways</a></div>
<?php
$connectedCount = count(array_filter($gateways, static fn($gateway): bool => $gateway['status'] === 'active' && $gateway['connection_status'] === 'verified'));
$activeCount = count(array_filter($gateways, static fn($gateway): bool => $gateway['status'] === 'active'));
$activeGateways = array_values(array_filter($gateways, static fn($gateway): bool => $gateway['status'] === 'active'));
$inactiveGateways = array_values(array_filter($gateways, static fn($gateway): bool => $gateway['status'] !== 'active'));
?>
<div class="summary"><span><strong><?= $connectedCount ?></strong>Connected</span><span><strong><?= $activeCount ?></strong>Active</span><span><strong><?= count($gateways) ?></strong>Total</span></div>
<div class="grid">
<?php foreach ([$activeGateways, $inactiveGateways] as $sectionIndex => $sectionGateways): ?>
<?php if ($sectionIndex === 1 && $sectionGateways): ?><div class="section-divider">Inactive Gateways</div><?php endif; ?>
<?php foreach ($sectionGateways as $gateway): $connected = $gateway['status'] === 'active' && $gateway['connection_status'] === 'verified'; $statusClass = $connected ? 'connected' : ($gateway['status'] === 'active' ? 'failed' : 'inactive'); ?>
<article class="card <?= $connected ? 'connected' : '' ?>">
<div class="card-head"><div><h2><?= $escape($gateway['name']) ?></h2><div class="code"><?= $escape($gateway['code']) ?></div></div><div class="badge <?= $statusClass ?>"><?= $escape(ucfirst((string)$gateway['status'])) ?> · <?= $escape(ucfirst((string)($gateway['connection_status'] ?: 'untested'))) ?></div></div>
<div class="meta"><span>Type</span><strong><?= $escape($gateway['type'] ?: 'Payment Gateway') ?></strong></div>
<div class="meta"><span>Last Test</span><strong><?= $escape($gateway['last_tested'] ?: 'Unavailable') ?></strong></div>
<div class="meta"><span>Response</span><strong><?= $gateway['test_response_ms'] !== null ? $escape($gateway['test_response_ms'] . ' ms') : 'Unavailable' ?></strong></div>
<div class="actions"><a class="button" href="admin/gateway_details.php?code=<?= urlencode((string)$gateway['code']) ?>">Open Details</a><?php if ($connected): ?><a class="button secondary" href="checkout_router.php?gateway=<?= urlencode((string)$gateway['code']) ?>">Checkout</a><?php endif; ?></div>
</article>
<?php endforeach; ?>
<?php endforeach; ?>
</div>
</main>
</body>
</html>
