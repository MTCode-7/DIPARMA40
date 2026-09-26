<?php
/**
 * DI PARMA | Ledger CHECKOUT
 * بوابة مستقلة: الخصم يتم على بوابة مضافة هنا، والصافي يصل إلى Ledger.
 * صفحات البوابات الأخرى تُبقي المبلغ على نفس البوابة.
 */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/gateways.php';
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/activity_flow.php';
require_once __DIR__ . '/includes/gateway_channel_bar.php';

$reqChannel = strtolower(trim((string) ($_GET['channel'] ?? '')));
if ($reqChannel === 'pos') {
    header('Location: pos/ledger.php', true, 302);
    exit;
}
if ($reqChannel === 'link') {
    header('Location: link/ledger.php', true, 302);
    exit;
}

$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'ar' ? 'ar' : 'en';
$ar   = ($lang === 'ar');
$dir  = $ar ? 'rtl' : 'ltr';

$allGateways = [
    'payram'        => ['name' => 'PayRam',            'icon' => 'fas fa-server',         'color' => '#10B981'],
    'diparma'       => ['name' => 'DI PARMA',          'icon' => 'fas fa-coins',          'color' => '#FFD700'],
    'nuvei'         => ['name' => 'Nuvei',             'icon' => 'fas fa-credit-card',    'color' => '#F97316'],
    'stripe'        => ['name' => 'Stripe',            'icon' => 'fab fa-stripe-s',       'color' => '#6772e5'],
    'square'        => ['name' => 'Square 1',          'icon' => 'fas fa-square',         'color' => '#006AFF'],
    'square_online' => ['name' => 'Square 2 · Online', 'icon' => 'fas fa-store',          'color' => '#006AFF'],
    'paypal'        => ['name' => 'PayPal',            'icon' => 'fab fa-paypal',         'color' => '#003087'],
    'wise'          => ['name' => 'Wise',              'icon' => 'fas fa-exchange-alt',   'color' => '#9fe870'],
    'myfatoorah'    => ['name' => 'MyFatoorah',        'icon' => 'fas fa-money-bill-wave','color' => '#00b09b'],
    'binance'       => ['name' => 'Binance',           'icon' => 'fas fa-coins',          'color' => '#F3BA2F'],
    'gate_io'       => ['name' => 'Gate.io',           'icon' => 'fas fa-coins',          'color' => '#E8112D'],
    'mashreq'       => ['name' => 'Mashreq Bank',      'icon' => 'fas fa-university',     'color' => '#FF6600'],
    'hsbc_uae'      => ['name' => 'HSBC UAE',          'icon' => 'fas fa-university',     'color' => '#DB0011'],
    'nbe_egypt'     => ['name' => 'NBE Egypt',         'icon' => 'fas fa-landmark',       'color' => '#006633'],
    'jpmorgan'      => ['name' => 'JP Morgan Chase',   'icon' => 'fas fa-landmark',       'color' => '#003087'],
    'whop'          => ['name' => 'Whop',              'icon' => 'fas fa-bolt',           'color' => '#7C3AED'],
];

$gatewayRoutes = [];
foreach (array_keys($allGateways) as $code) {
    $routeFile = activity_checkout_route($code);
    if ($routeFile !== '' && $routeFile !== 'checkout_ledger.php') {
        $gatewayRoutes[$code] = $routeFile;
    }
}

$gatewayState = [];
$attached = [];
try {
    $db = db();
    if (isset($db) && is_object($db) && method_exists($db, 'query')) {
        $gatewayRows = $db->query("SELECT code,status,connection_status,config,credentials,settings FROM dp_payment_gateways WHERE status != 'deleted'");
        foreach (($gatewayRows ?? []) as $row) {
            $code = function_exists('dp_gateway_normalize_code')
                ? dp_gateway_normalize_code((string) ($row['code'] ?? ''))
                : strtolower((string) ($row['code'] ?? ''));
            if ($code !== '') {
                $row['code'] = $code;
                $gatewayState[$code] = $row;
            }
        }
    }
} catch (Throwable $e) {
    $gatewayState = [];
}

foreach ($gatewayRoutes as $code => $routeFile) {
    if (!isset($allGateways[$code])) {
        continue;
    }
    $row = $gatewayState[$code] ?? null;
    if ($row === null && $code === 'square_online') {
        $row = $gatewayState['square'] ?? null;
    }
    if ($row === null) {
        continue;
    }
    $row['code'] = $code;
    if (isGatewayVisibleInCheckout($row)) {
        $attached[$code] = $allGateways[$code] + ['route' => $routeFile];
    }
}

$ledgerAddr = activity_ledger_address();
$prefillAmount = trim((string) ($_GET['amount'] ?? ''));
$prefillCurrency = strtoupper(trim((string) ($_GET['currency'] ?? 'USD')));
if ($prefillCurrency === '') {
    $prefillCurrency = 'USD';
}
?><!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$dir?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | Ledger CHECKOUT</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--gold:#FFD700;--gold2:#FFB700;--bg:#030609;--card:#090f1e;--card2:#0b1224;--border:rgba(255,215,0,.12);--text:#edf0f7;--muted:#4a5568;--muted2:#718096;--green:#10B981}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.topbar{background:rgba(3,6,9,.97);border-bottom:1px solid var(--border);height:60px;display:flex;align-items:center;justify-content:space-between;padding:0 28px;position:sticky;top:0;z-index:100}
.tb-brand{color:var(--gold);font-weight:900;font-size:1.05rem;display:flex;align-items:center;gap:10px}
.tb-nav a{color:var(--muted2);font-size:.78rem;padding:6px 14px;border-radius:18px;text-decoration:none;transition:.2s}
.tb-nav a:hover,.tb-nav a.on{color:var(--gold)}
.wrap{max-width:1100px;margin:0 auto;padding:32px 24px}
.page-title{font-size:1.5rem;font-weight:900;background:linear-gradient(135deg,var(--gold),#fff8c0);-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:6px}
.page-sub{font-size:.82rem;color:var(--muted2);margin-bottom:22px;line-height:1.7}
.badge{display:inline-flex;align-items:center;gap:8px;border:1.5px solid var(--green);color:var(--green);border-radius:12px;padding:6px 14px;font-weight:800;font-size:.82rem;margin-bottom:16px}
.addr-box{background:var(--card);border:1px solid rgba(16,185,129,.35);border-radius:16px;padding:18px;margin-bottom:24px}
.addr-label{font-size:.72rem;font-weight:800;color:var(--green);letter-spacing:1px;margin-bottom:8px}
.addr{font-family:monospace;font-size:.88rem;word-break:break-all;color:var(--text)}
.gw-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px}
.gw-card{background:var(--card);border:1.5px solid var(--border);border-radius:16px;padding:16px;cursor:pointer;transition:.25s;text-decoration:none;color:inherit;display:block}
.gw-card:hover{transform:translateY(-2px);border-color:rgba(255,215,0,.35)}
.gw-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;margin-bottom:10px}
.gw-name{font-size:.88rem;font-weight:800;margin-bottom:4px}
.gw-desc{font-size:.7rem;color:var(--muted2);line-height:1.5}
.empty{border:1px solid var(--border);border-radius:14px;padding:22px;background:var(--card);text-align:center;color:var(--muted2)}
.fld-row{display:grid;grid-template-columns:1fr 160px;gap:12px;margin-bottom:20px}
.fld label{display:block;font-size:.72rem;color:var(--muted2);margin-bottom:5px;font-weight:700}
.fld input,.fld select{width:100%;background:rgba(255,255,255,.04);border:1.5px solid var(--border);border-radius:11px;padding:11px 14px;color:var(--text);font-family:'Cairo',sans-serif;font-size:.88rem}
.fld input:focus,.fld select:focus{outline:none;border-color:var(--gold)}
.note{font-size:.75rem;color:var(--muted2);margin-top:18px;line-height:1.7}
@media(max-width:600px){.gw-grid,.fld-row{grid-template-columns:1fr}}
</style>
</head>
<body>
<header class="topbar">
  <div class="tb-brand"><i class="fas fa-wallet"></i> DI PARMA <span style="color:var(--muted);margin:0 4px">|</span> <span style="color:var(--gold);font-size:.85rem">Ledger CHECKOUT</span></div>
  <div class="tb-nav">
    <a class="on" href="checkout_ledger.php"><i class="fas fa-wallet"></i> Ledger</a>
    <a href="checkout_router.php"><i class="fas fa-exchange-alt"></i> <?=$ar?'الدفع':'Checkout'?></a>
    <a href="dashboard.php"><i class="fas fa-th-large"></i> <?=$ar?'لوحة التحكم':'Dashboard'?></a>
  </div>
</header>

<div class="wrap">
  <?= diparma_gateway_channel_bar('ledger', 'checkout', '', $ar) ?>
  <div class="badge"><i class="fas fa-wallet"></i> <?=$ar?'بوابة مستقلة':'Standalone gateway'?></div>
  <div class="page-title">Ledger CHECKOUT</div>
  <div class="page-sub">
    <?=$ar
      ? 'هذه بوابة خاصة لوحدها. اختر بوابة مضافة للخصم — بعد الموافقة يصل الصافي إلى عنوان Ledger. صفحات PayPal وStripe وNuvei وباقي البوابات تُبقي المبلغ على نفس البوابة.'
      : 'This is a standalone gateway. Pick an attached gateway to charge — after approval the net arrives at the Ledger address. PayPal, Stripe, Nuvei and the other pages keep funds on that same gateway.'?>
  </div>

  <div class="addr-box">
    <div class="addr-label">LEDGER TRC20</div>
    <div class="addr"><?=htmlspecialchars($ledgerAddr !== '' ? $ledgerAddr : 'LEDGER_TRC20_ADDRESS')?></div>
  </div>

  <div class="fld-row">
    <div class="fld">
      <label><?=$ar?'المبلغ (اختياري)':'Amount (optional)'?></label>
      <input type="number" id="lcAmount" min="0.01" step="0.01" value="<?=htmlspecialchars($prefillAmount)?>" placeholder="0.00">
    </div>
    <div class="fld">
      <label><?=$ar?'العملة':'Currency'?></label>
      <select id="lcCurrency">
        <?php foreach (['USD','EUR','GBP','AED','SAR','USDT'] as $cur): ?>
        <option value="<?=$cur?>"<?=$prefillCurrency===$cur?' selected':''?>><?=$cur?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div style="font-size:.75rem;font-weight:800;color:var(--muted);letter-spacing:1.2px;margin-bottom:12px;text-transform:uppercase">
    <?=$ar?'البوابات المضافة لـ Ledger CHECKOUT':'Gateways attached to Ledger CHECKOUT'?>
  </div>

  <?php if (empty($attached)): ?>
  <div class="empty">
    <?=$ar?'لا توجد بوابات متصلة جاهزة للخصم. فعّل بوابة من إدارة البوابات ثم عد إلى هذه الصفحة.':'No live charge gateways are attached yet. Enable a gateway in Gateways, then return here.'?>
    <div style="margin-top:12px"><a href="admin/gateway_manager.php" style="color:var(--gold)"><?=$ar?'إدارة البوابات':'Gateway manager'?></a></div>
  </div>
  <?php else: ?>
  <div class="gw-grid">
    <?php foreach ($attached as $code => $gw): ?>
    <div class="gw-card" data-code="<?=htmlspecialchars($code)?>">
      <div class="gw-icon" style="background:<?=$gw['color']?>22;color:<?=$gw['color']?>"><i class="<?=$gw['icon']?>"></i></div>
      <div class="gw-name"><?=htmlspecialchars($gw['name'])?></div>
      <div class="gw-desc"><?=$ar?'خصم على هذه البوابة ثم الصافي → Ledger':'Charge on this gateway, then net → Ledger'?></div>
      <div style="display:flex;gap:6px;margin-top:10px;flex-wrap:wrap">
        <a href="#" data-code="<?=htmlspecialchars($code)?>" data-ch="pos" onclick="return openLedgerGateway(this)" style="font-size:.68rem;font-weight:800;color:#FFD700;text-decoration:none">POS</a>
        <a href="#" data-code="<?=htmlspecialchars($code)?>" data-ch="checkout" data-route="<?=htmlspecialchars($gw['route'])?>" onclick="return openLedgerGateway(this)" style="font-size:.68rem;font-weight:800;color:#FFD700;text-decoration:none">CHECKOUT</a>
        <a href="#" data-code="<?=htmlspecialchars($code)?>" data-ch="link" onclick="return openLedgerGateway(this)" style="font-size:.68rem;font-weight:800;color:#FFD700;text-decoration:none">LINK</a>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <p class="note">
    <?=$ar
      ? 'PayPal تبقى صفحة PayPal. Stripe تبقى صفحة Stripe. Nuvei تبقى صفحة Nuvei. Ledger صفحة لوحدها — التحويل إلى المحفظة يتم فقط من هنا.'
      : 'PayPal stays on the PayPal page. Stripe stays on the Stripe page. Nuvei stays on the Nuvei page. Ledger is its own page — wallet settlement runs only from here.'?>
  </p>
</div>
<script>
const POS_MAP = <?=json_encode($attached === [] ? [] : array_combine(array_keys($attached), array_map('activity_pos_route', array_keys($attached))), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)?>;
const LINK_MAP = <?=json_encode($attached === [] ? [] : array_combine(array_keys($attached), array_map('activity_link_route', array_keys($attached))), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)?>;
function openLedgerGateway(el) {
  const code = el.getAttribute('data-code') || '';
  const ch = el.getAttribute('data-ch') || 'checkout';
  if (!code) return false;
  const amount = document.getElementById('lcAmount')?.value || '';
  const currency = document.getElementById('lcCurrency')?.value || 'USD';
  const q = new URLSearchParams({
    ledger_checkout: '1',
    dest: 'ledger',
    destination: 'ledger',
    gateway: code
  });
  if (parseFloat(amount) > 0) q.set('amount', amount);
  if (currency) q.set('currency', currency);
  let route = el.getAttribute('data-route') || '';
  if (ch === 'pos') route = POS_MAP[code] || ('pos/' + code + '.php');
  if (ch === 'link') route = LINK_MAP[code] || ('link/' + code + '.php');
  if (!route) return false;
  window.location.href = route + '?' + q.toString();
  return false;
}
</script>
</body>
</html>
