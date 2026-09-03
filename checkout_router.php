<?php
/**
 * ============================================================
 * DI PARMA | Checkout Router
 * اختيار البوابة + وجهة المبلغ → صفحة checkout مستقلة
 * ============================================================
 */
// Checkout Router — اختيار البوابة والمبلغ
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/lib/PayRamAdapter.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// NOTE: auth check is required for checkout operations.

$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang']==='ar' ? 'ar' : 'en';
$ar   = ($lang === 'ar');
$dir  = $ar ? 'rtl' : 'ltr';
$csrf = generateCsrfToken();
$db   = db();

// ── البوابات المتاحة في الواجهة ───────────────────────────────────────
$allGateways = [
    'diparma'    => ['name'=>'DI PARMA',      'icon'=>'fas fa-coins',          'color'=>'#FFD700','type'=>'card',   'desc_ar'=>'DI PARMA Ultimate Gateway × Ledger','desc_en'=>'DI PARMA Ultimate Gateway × Ledger'],
    'nuvei'      => ['name'=>'Nuvei',        'icon'=>'fas fa-credit-card',   'color'=>'#F97316','type'=>'card',   'desc_ar'=>'بطاقة Visa/Mastercard عبر Mashreq','desc_en'=>'Visa/Mastercard via Mashreq'],
    'stripe'     => ['name'=>'Stripe',       'icon'=>'fab fa-stripe-s',       'color'=>'#6772e5','type'=>'card',   'desc_ar'=>'بطاقة Visa/Mastercard — مستقل','desc_en'=>'Visa/Mastercard — Independent'],
    'paypal'     => ['name'=>'PayPal',        'icon'=>'fab fa-paypal',         'color'=>'#003087','type'=>'card',   'desc_ar'=>'PayPal مباشر','desc_en'=>'Direct PayPal'],
    'wise'       => ['name'=>'Wise',          'icon'=>'fas fa-exchange-alt',   'color'=>'#9fe870','type'=>'bank',   'desc_ar'=>'تحويل بنكي دولي + API Live','desc_en'=>'International bank transfer API'],
    'myfatoorah' => ['name'=>'MyFatoorah',    'icon'=>'fas fa-money-bill-wave','color'=>'#00b09b','type'=>'card',   'desc_ar'=>'بوابة الشرق الأوسط','desc_en'=>'Middle East gateway'],
    'binance'    => ['name'=>'Binance',       'icon'=>'fas fa-coins',          'color'=>'#F3BA2F','type'=>'crypto', 'desc_ar'=>'دفع بالكريبتو','desc_en'=>'Crypto payment'],
    'gate_io'    => ['name'=>'Gate.io',       'icon'=>'fas fa-coins',          'color'=>'#E8112D','type'=>'crypto', 'desc_ar'=>'دفع بالكريبتو','desc_en'=>'Crypto payment'],
    'mashreq'    => ['name'=>'Mashreq Bank',  'icon'=>'fas fa-university',     'color'=>'#FF6600','type'=>'bank',   'desc_ar'=>'Mashreq — TRANSCENDIO FZ-LLC','desc_en'=>'Mashreq — TRANSCENDIO FZ-LLC'],
    'hsbc_uae'   => ['name'=>'HSBC UAE',      'icon'=>'fas fa-university',     'color'=>'#DB0011','type'=>'bank',   'desc_ar'=>'HSBC Bank Middle East','desc_en'=>'HSBC Bank Middle East'],
    'nbe_egypt'  => ['name'=>'NBE Egypt',     'icon'=>'fas fa-landmark',       'color'=>'#006633','type'=>'bank',   'desc_ar'=>'البنك الأهلي المصري','desc_en'=>'National Bank of Egypt'],
    'jpmorgan'   => ['name'=>'JP Morgan Chase','icon'=>'fas fa-landmark',      'color'=>'#003087','type'=>'bank',   'desc_ar'=>'JP Morgan IOLTA','desc_en'=>'JP Morgan IOLTA'],
    'whop'       => ['name'=>'Whop',          'icon'=>'fas fa-bolt',           'color'=>'#7C3AED','type'=>'digital','desc_ar'=>'Whop Marketplace','desc_en'=>'Whop Marketplace'],
    'payram'     => ['name'=>'PayRam',        'icon'=>'fas fa-server',         'color'=>'#10B981','type'=>'crypto', 'desc_ar'=>'PayRam — Crypto Self-hosted','desc_en'=>'PayRam — Self-hosted Crypto'],
];

$gateways = $allGateways;

// ── وجهات المبلغ ─────────────────────────────────────────────
$destinations = [
    // ── نفس البوابة ──────────────────────────────────────────
    'gateway'    => ['icon'=>'fas fa-exchange-alt',   'color'=>'#F97316', 'ar'=>'نفس بوابة الدفع',          'en'=>'Same Gateway'],
    // ── بوابات الدفع ─────────────────────────────────────────
    'stripe'     => ['icon'=>'fab fa-stripe-s',       'color'=>'#6772e5', 'ar'=>'Stripe Balance',            'en'=>'Stripe Balance'],
    'paypal'     => ['icon'=>'fab fa-paypal',          'color'=>'#003087', 'ar'=>'PayPal Balance',            'en'=>'PayPal Balance'],
    'nuvei'      => ['icon'=>'fas fa-credit-card',    'color'=>'#F97316', 'ar'=>'Nuvei (Mashreq)',            'en'=>'Nuvei (Mashreq)'],
    'wise'       => ['icon'=>'fas fa-exchange-alt',   'color'=>'#9fe870', 'ar'=>'Wise Balance',               'en'=>'Wise Balance'],
    'myfatoorah' => ['icon'=>'fas fa-money-bill-wave','color'=>'#00b09b', 'ar'=>'MyFatoorah',                 'en'=>'MyFatoorah'],
    'binance_ex' => ['icon'=>'fas fa-coins',          'color'=>'#F3BA2F', 'ar'=>'Binance Spot',              'en'=>'Binance Spot'],
    'gate_io'    => ['icon'=>'fas fa-coins',          'color'=>'#E8112D', 'ar'=>'Gate.io Balance',            'en'=>'Gate.io Balance'],
    'whop'       => ['icon'=>'fas fa-bolt',           'color'=>'#7C3AED', 'ar'=>'Whop Balance',               'en'=>'Whop Balance'],
    // ── بنوك ─────────────────────────────────────────────────
    'mashreq'    => ['icon'=>'fas fa-university',     'color'=>'#FF6600', 'ar'=>'Mashreq Bank (TRANSCENDIO)','en'=>'Mashreq Bank'],
    'hsbc'       => ['icon'=>'fas fa-university',     'color'=>'#DB0011', 'ar'=>'HSBC UAE',                  'en'=>'HSBC UAE'],
    'nbe'        => ['icon'=>'fas fa-landmark',       'color'=>'#006633', 'ar'=>'NBE Egypt',                 'en'=>'NBE Egypt'],
    'jpmorgan'   => ['icon'=>'fas fa-landmark',       'color'=>'#003087', 'ar'=>'JP Morgan IOLTA',           'en'=>'JP Morgan IOLTA'],
    // ── محافظ رقمية ──────────────────────────────────────────
    'ledger_trx' => ['icon'=>'fas fa-wallet',         'color'=>'#10B981', 'ar'=>'Ledger TRX (USDT)',         'en'=>'Ledger TRX (USDT)'],
    'tron_w'     => ['icon'=>'fas fa-wallet',         'color'=>'#EF4444', 'ar'=>'محفظة TRC20 مخصصة',        'en'=>'Custom TRC20'],
    'erc20_w'    => ['icon'=>'fas fa-wallet',         'color'=>'#3B82F6', 'ar'=>'محفظة ERC20 مخصصة',        'en'=>'Custom ERC20'],
    'btc_w'      => ['icon'=>'fab fa-bitcoin',        'color'=>'#F7931A', 'ar'=>'محفظة Bitcoin',             'en'=>'Bitcoin Wallet'],
];

  // نقاط الوصول المعروفة لكل بوابة. نعرضها دائمًا لتجنب صفحة checkout فارغة.
  $gatewayRoutes = [
    'nuvei'      => 'checkout/nuvei.php',
    'stripe'     => 'checkout/stripe.php',
    'paypal'     => 'checkout/paypal.php',
    'wise'       => 'checkout/wise.php',
    'myfatoorah' => 'checkout/myfatoorah.php',
    'binance'    => 'checkout/binance.php',
    'gate_io'    => 'checkout/gate_io.php',
    'mashreq'    => 'checkout/bank_mashreq.php',
    'hsbc_uae'   => 'checkout/bank_hsbc.php',
    'nbe_egypt'  => 'checkout/bank_nbe.php',
    'jpmorgan'   => 'checkout/bank_jpmorgan.php',
    'whop'       => 'checkout/whop.php',
    'payram'     => 'checkout_diparma.php',
    'diparma'    => 'checkout_diparma.php',
  ];

  $gatewayState = [];
  $filteredGateways = [];
  try {
    if (isset($db) && is_object($db) && method_exists($db, 'query')) {
      $gatewayRows = $db->query("SELECT code,status,connection_status,setup_complete,config,credentials,settings FROM dp_payment_gateways WHERE status != 'deleted'");
      foreach (($gatewayRows ?? []) as $row) {
        $code = strtolower((string)($row['code'] ?? ''));
        if ($code !== '') {
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

    if (!is_file(__DIR__ . '/' . $routeFile)) {
      // لا تمنع عرض البوابة إذا كانت الواجهة موجودة لكن ملف الخروج غير موجود.
      $filteredGateways[$code] = $allGateways[$code];
      continue;
    }

    $row = $gatewayState[$code] ?? null;
    if ($row === null) {
      $filteredGateways[$code] = $allGateways[$code];
      continue;
    }

    $status = strtolower((string)($row['status'] ?? ''));
    $connection = strtolower((string)($row['connection_status'] ?? ''));
    $setupReady = !empty($row['setup_complete']);
    $approvedStatus = $status === 'active' || $status === 'enabled' || $status === 'live' || $setupReady || in_array($connection, ['verified', 'connected', 'ready', 'success'], true);

    // إذا كان هناك أي تعارض في القيم أو تغيّرات في DB، نحتفظ بالبوابة بشكل آمن.
    $filteredGateways[$code] = $allGateways[$code];
    if (!$approvedStatus && strtolower((string)($row['status'] ?? '')) === 'inactive') {
      // لا نحذف البوابة من الواجهة إذا كانت منطقياً متاحة للتشغيل.
      $filteredGateways[$code] = $allGateways[$code];
    }
  }

  if (empty($filteredGateways)) {
    $filteredGateways = $allGateways;
  }

  $gateways = $filteredGateways;
?><!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$dir?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | <?=$ar?'الدفع':'Checkout'?></title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--gold:#FFD700;--gold2:#FFB700;--bg:#030609;--card:#090f1e;--card2:#0b1224;--border:rgba(255,215,0,.12);--border2:rgba(255,215,0,.28);--text:#edf0f7;--muted:#4a5568;--muted2:#718096;--green:#10B981;--red:#EF4444;--orange:#F97316}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.topbar{background:rgba(3,6,9,.97);border-bottom:1px solid var(--border);height:60px;display:flex;align-items:center;justify-content:space-between;padding:0 28px;position:sticky;top:0;z-index:100}
.tb-brand{color:var(--gold);font-weight:900;font-size:1.05rem;display:flex;align-items:center;gap:10px}
.tb-nav a{color:var(--muted2);font-size:.78rem;padding:6px 14px;border-radius:18px;text-decoration:none;transition:.2s}
.tb-nav a:hover{color:var(--gold)}
.wrap{max-width:1100px;margin:0 auto;padding:32px 24px}
.page-title{font-size:1.5rem;font-weight:900;background:linear-gradient(135deg,var(--gold),#fff8c0);-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:6px}
.page-sub{font-size:.82rem;color:var(--muted2);margin-bottom:28px}
/* Steps */
.steps-bar{display:flex;align-items:center;gap:0;margin-bottom:32px}
.step-item{display:flex;align-items:center;gap:10px;font-size:.8rem;font-weight:700}
.step-num{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:900;background:rgba(255,255,255,.07);color:var(--muted2);border:2px solid rgba(255,255,255,.1);transition:.3s}
.step-item.active .step-num{background:var(--gold);color:#000;border-color:var(--gold)}
.step-item.done .step-num{background:var(--green);color:#fff;border-color:var(--green)}
.step-label{color:var(--muted2);transition:.3s}
.step-item.active .step-label{color:var(--gold)}
.step-item.done .step-label{color:var(--green)}
.step-sep{flex:1;height:2px;background:rgba(255,255,255,.06);margin:0 12px}
/* Gateway Grid */
.section-title{font-size:.75rem;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:1.5px;margin-bottom:14px;display:flex;align-items:center;gap:8px}
.gw-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:28px}
.gw-card{background:var(--card);border:1.5px solid var(--border);border-radius:16px;padding:16px;cursor:pointer;transition:.25s;position:relative}
.gw-card:hover{transform:translateY(-2px);border-color:rgba(255,215,0,.25)}
.gw-card.selected{border-color:var(--gold);background:rgba(255,215,0,.05)}
.gw-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;margin-bottom:10px}
.gw-name{font-size:.88rem;font-weight:800;margin-bottom:3px}
.gw-desc{font-size:.7rem;color:var(--muted2);line-height:1.5}
.gw-type-badge{position:absolute;top:10px;right:10px;font-size:.6rem;font-weight:800;padding:2px 7px;border-radius:6px;text-transform:uppercase}
/* Destination */
.dest-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;margin-bottom:28px}
.dest-card{background:var(--card);border:1.5px solid var(--border);border-radius:14px;padding:14px;cursor:pointer;transition:.25s;display:flex;align-items:flex-start;gap:10px}
.dest-card:hover{border-color:rgba(255,215,0,.25)}
.dest-card.selected{border-color:var(--gold);background:rgba(255,215,0,.04)}
.dest-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:.9rem;flex-shrink:0}
.dest-name{font-size:.78rem;font-weight:800}
.dest-detail{font-size:.65rem;color:var(--muted2);margin-top:3px;line-height:1.5}
/* Custom wallet input */
.wallet-input-wrap{background:var(--card2);border:1.5px solid var(--border);border-radius:12px;padding:14px;margin-bottom:20px;display:none}
.wallet-input-wrap.show{display:block}
.wallet-input-wrap label{font-size:.75rem;color:var(--muted2);display:block;margin-bottom:6px;font-weight:700}
.wallet-input-wrap input{width:100%;background:rgba(255,255,255,.04);border:1.5px solid var(--border);border-radius:10px;padding:11px 14px;color:var(--text);font-family:'Cairo',sans-serif;font-size:.85rem}
.wallet-input-wrap input:focus{outline:none;border-color:var(--gold)}
/* Amount */
.amount-section{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:22px;margin-bottom:24px}
.fld-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.fld label{display:block;font-size:.72rem;color:var(--muted2);margin-bottom:5px;font-weight:700}
.fld input,.fld select{width:100%;background:rgba(255,255,255,.04);border:1.5px solid var(--border);border-radius:11px;padding:11px 14px;color:var(--text);font-family:'Cairo',sans-serif;font-size:.88rem;transition:.2s}
.fld input:focus,.fld select:focus{outline:none;border-color:var(--gold);background:rgba(255,215,0,.03)}
/* Continue Button */
.continue-btn{width:100%;padding:15px;border-radius:14px;border:none;cursor:pointer;font-family:'Cairo',sans-serif;font-size:1rem;font-weight:900;background:linear-gradient(135deg,var(--gold),var(--gold2));color:#000;box-shadow:0 8px 24px rgba(255,215,0,.2);transition:.3s;display:flex;align-items:center;justify-content:center;gap:10px}
.continue-btn:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 12px 32px rgba(255,215,0,.3)}
.continue-btn:disabled{opacity:.4;cursor:not-allowed;transform:none}
/* Summary */
.summary-bar{background:var(--card2);border:1px solid var(--border);border-radius:14px;padding:16px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.sum-item{display:flex;align-items:center;gap:8px;font-size:.8rem}
.sum-val{font-weight:800;color:var(--gold)}
/* Toast */
#toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(100px);background:var(--card);border:1px solid var(--border2);border-radius:14px;padding:12px 28px;font-size:.84rem;font-weight:700;z-index:9999;transition:.35s;color:var(--text)}
@media(max-width:600px){.gw-grid,.dest-grid{grid-template-columns:1fr 1fr}.fld-row{grid-template-columns:1fr}}
</style>
</head>
<body>
<header class="topbar">
  <div class="tb-brand"><i class="fas fa-coins"></i> DI PARMA <span style="color:var(--muted);margin:0 4px">|</span> <span style="color:var(--gold);font-size:.85rem"><?=$ar?'الدفع':'Checkout'?></span></div>
  <div class="tb-nav">
    <a href="dashboard.php"><i class="fas fa-th-large"></i> <?=$ar?'لوحة التحكم':'Dashboard'?></a>
  </div>
</header>

<div class="wrap">
  <div class="page-title"><i class="fas fa-credit-card"></i> <?=$ar?'إتمام الدفع':'Checkout'?></div>
  <div class="page-sub"><?=$ar?'اختر بوابة الدفع وحدد وجهة المبلغ':'Select payment gateway and amount destination'?></div>

  <!-- Steps Bar -->
  <div class="steps-bar">
    <div class="step-item active" id="step1-item">
      <div class="step-num">1</div>
      <div class="step-label"><?=$ar?'البوابة':'Gateway'?></div>
    </div>
    <div class="step-sep"></div>
    <div class="step-item" id="step2-item">
      <div class="step-num">2</div>
      <div class="step-label"><?=$ar?'الوجهة':'Destination'?></div>
    </div>
    <div class="step-sep"></div>
    <div class="step-item" id="step3-item">
      <div class="step-num">3</div>
      <div class="step-label"><?=$ar?'المبلغ':'Amount'?></div>
    </div>
    <div class="step-sep"></div>
    <div class="step-item" id="step4-item">
      <div class="step-num">4</div>
      <div class="step-label"><?=$ar?'تأكيد':'Confirm'?></div>
    </div>
  </div>

  <!-- ══ STEP 1: اختيار البوابة ══ -->
  <div id="sec-step1">
    <div class="section-title"><i class="fas fa-plug"></i> <?=$ar?'اختر بوابة الدفع':'Select Payment Gateway'?></div>

    <?php
    $types = ['card'=>($ar?'بطاقات':'Cards'),'bank'=>($ar?'تحويل بنكي':'Bank Transfer'),'crypto'=>($ar?'كريبتو':'Crypto'),'digital'=>($ar?'رقمي':'Digital')];
    foreach($types as $type=>$typeLabel):
        $filtered = array_filter($gateways, fn($g) => $g['type'] === $type);
        if(empty($filtered)) continue;
    ?>
    <div class="section-title" style="font-size:.65rem;margin-top:16px;color:var(--muted)">— <?=$typeLabel?> —</div>
    <div class="gw-grid">
    <?php foreach($filtered as $code => $gw): ?>
      <div class="gw-card" onclick="selectGateway('<?=$code?>',this)" id="gw-<?=$code?>">
        <div class="gw-type-badge" style="background:rgba(255,255,255,.06);color:var(--muted2)"><?=$type?></div>
        <div class="gw-icon" style="background:<?=$gw['color']?>22;color:<?=$gw['color']?>"><i class="<?=$gw['icon']?>"></i></div>
        <div class="gw-name"><?=$gw['name']?></div>
        <div class="gw-desc"><?=$ar?$gw['desc_ar']:$gw['desc_en']?></div>
      </div>
    <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <button class="continue-btn" id="btn-step1" onclick="goStep(2)" disabled>
      <?=$ar?'التالي — اختر وجهة المبلغ':'Next — Select Destination'?> <i class="fas fa-arrow-left"></i>
    </button>
  </div>

  <!-- ══ STEP 2: وجهة المبلغ ══ -->
  <div id="sec-step2" style="display:none">
    <div class="section-title"><i class="fas fa-map-marker-alt"></i> <?=$ar?'وجهة المبلغ':'Amount Destination'?></div>
    <div class="dest-grid">
    <?php foreach($destinations as $code => $dest): ?>
      <div class="dest-card" onclick="selectDestination('<?=$code?>',this)" id="dest-<?=$code?>">
        <div class="dest-icon" style="background:<?=$dest['color']?>22;color:<?=$dest['color']?>"><i class="<?=$dest['icon']?>"></i></div>
        <div>
          <div class="dest-name"><?=$ar?$dest['ar']:$dest['en']?></div>
          <?php if($code==='ledger_trx'): ?>
          <div class="dest-detail" style="font-family:monospace;font-size:.6rem">TEwLFWlwK55b7...</div>
          <?php elseif($code==='mashreq'): ?>
          <div class="dest-detail">AE300330000019101562722</div>
          <?php elseif($code==='jpmorgan'): ?>
          <div class="dest-detail">663525063665 — Routing: 111000614</div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    </div>

    <!-- حقل محفظة مخصصة -->
    <div class="wallet-input-wrap" id="customWalletWrap">
      <label id="customWalletLabel"><i class="fas fa-wallet"></i> <?=$ar?'عنوان المحفظة':'Wallet Address'?></label>
      <input type="text" id="customWalletAddr" placeholder="<?=$ar?'أدخل عنوان المحفظة':'Enter wallet address'?>">
    </div>

    <div style="display:flex;gap:12px">
      <button class="continue-btn" style="background:rgba(255,255,255,.06);color:var(--text);box-shadow:none;flex:0 0 120px" onclick="goStep(1)">
        <i class="fas fa-arrow-right"></i> <?=$ar?'رجوع':'Back'?>
      </button>
      <button class="continue-btn" id="btn-step2" onclick="goStep(3)" disabled>
        <?=$ar?'التالي — المبلغ':'Next — Amount'?> <i class="fas fa-arrow-left"></i>
      </button>
    </div>
  </div>

  <!-- ══ STEP 3: المبلغ والعملة ══ -->
  <div id="sec-step3" style="display:none">
    <div class="section-title"><i class="fas fa-dollar-sign"></i> <?=$ar?'المبلغ والعملة':'Amount & Currency'?></div>
    <div class="amount-section">

      <!-- 10 أنواع العمليات -->
      <div class="section-title" style="margin-bottom:12px"><i class="fas fa-list"></i> <?=$ar?'نوع العملية':'Transaction Type'?></div>
      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:20px" id="txnTypeGrid">
        <?php
        $txnTypesRouter = [
          'purchase_3d'     =>['ar'=>'شراء 3D',            'en'=>'Purchase 3D',       'icon'=>'fa-shield-alt',   'color'=>'#10B981','sub'=>'3D Secure',      'rrn'=>false],
          'purchase_moto'   =>['ar'=>'شراء 2D / MOTO',     'en'=>'Purchase 2D/MOTO',  'icon'=>'fa-credit-card',  'color'=>'#06B6D4','sub'=>'2D · MOTO',       'rrn'=>false],
          'auth'            =>['ar'=>'تفويض',              'en'=>'Authorization',     'icon'=>'fa-lock',         'color'=>'#3B82F6','sub'=>'Hold',            'rrn'=>false],
          'auth_complete'   =>['ar'=>'إتمام تفويض',        'en'=>'Auth Completion',   'icon'=>'fa-check-double', 'color'=>'#6366F1','sub'=>'Capture · RRN',   'rrn'=>true],
          'purchase_advice' =>['ar'=>'إشعار شراء',         'en'=>'Purchase Advice',   'icon'=>'fa-bell',         'color'=>'#F59E0B','sub'=>'Offline 2D · RRN','rrn'=>true],
          'offline_purchase'=>['ar'=>'شراء أوفلاين',       'en'=>'Offline Purchase',  'icon'=>'fa-server',       'color'=>'#F97316','sub'=>'MOTO 2D · RRN',   'rrn'=>true],
          'online_purchase' =>['ar'=>'شراء أونلاين',       'en'=>'Online Purchase',   'icon'=>'fa-globe',        'color'=>'#8B5CF6','sub'=>'MOTO 2D · RRN',   'rrn'=>true],
          'refund'          =>['ar'=>'استرداد',            'en'=>'Refund',            'icon'=>'fa-undo',         'color'=>'#EF4444','sub'=>'Return',          'rrn'=>false],
          'reversal'        =>['ar'=>'إلغاء عملية',       'en'=>'Reversal',          'icon'=>'fa-reply',        'color'=>'#EC4899','sub'=>'Same Day',        'rrn'=>false],
          'balance'         =>['ar'=>'استعلام رصيد',      'en'=>'Balance Inquiry',   'icon'=>'fa-wallet',       'color'=>'#8B5CF6','sub'=>'Inquiry',         'rrn'=>false],
          'cash_advance'    =>['ar'=>'سلفة نقدية',        'en'=>'Cash Advance',      'icon'=>'fa-money-bill',   'color'=>'#14B8A6','sub'=>'Advance',         'rrn'=>false],
          'void'            =>['ar'=>'إلغاء',              'en'=>'Void',              'icon'=>'fa-ban',          'color'=>'#6B7280','sub'=>'Pre-Settlement',  'rrn'=>false],
          'settlement'      =>['ar'=>'تسوية EOD',          'en'=>'Settlement',        'icon'=>'fa-university',   'color'=>'#FFD700','sub'=>'End of Day',      'rrn'=>false],
          'quasi_cash'      =>['ar'=>'شبه نقدي',          'en'=>'Quasi Cash',        'icon'=>'fa-coins',        'color'=>'#F97316','sub'=>'QC · حوالات',    'rrn'=>false],
          'transfer'        =>['ar'=>'تحويل P2P',          'en'=>'Transfer',          'icon'=>'fa-exchange-alt', 'color'=>'#06B6D4','sub'=>'P2P',             'rrn'=>false],
          'payment'         =>['ar'=>'دفع فاتورة',        'en'=>'Bill Payment',      'icon'=>'fa-file-invoice', 'color'=>'#A855F7','sub'=>'Bill',            'rrn'=>false],
        ];
        foreach($txnTypesRouter as $k=>$t): ?>
        <div onclick="selectTxnTypeRouter('<?=$k?>',this)" id="rtt-<?=$k?>"
          data-needs-rrn="<?=$t['rrn']?'1':'0'?>"
          style="background:var(--card);border:1.5px solid var(--border);border-radius:12px;padding:10px 6px;cursor:pointer;text-align:center;transition:.2s;position:relative;<?=$k==='purchase_3d'?'border-color:var(--gold);background:rgba(255,215,0,.05)':''?>">
          <?php if($t['rrn']): ?>
          <span style="position:absolute;top:3px;right:3px;font-size:.48rem;background:rgba(239,68,68,.2);color:#EF4444;padding:1px 4px;border-radius:3px;font-weight:800">RRN</span>
          <?php endif; ?>
          <div style="font-size:1rem;color:<?=$t['color']?>;margin-bottom:4px"><i class="fas <?=$t['icon']?>"></i></div>
          <div style="font-size:.65rem;font-weight:800;color:<?=$k==='purchase_3d'?'var(--gold)':'var(--muted2)'?>;line-height:1.3" class="rtt-name"><?=$ar?$t['ar']:$t['en']?></div>
          <div style="font-size:.58rem;color:var(--muted);margin-top:2px"><?=$t['sub']?></div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- 2D / 3D لـ Purchase -->
      <div id="secModeWrap" style="display:flex;gap:8px;margin-bottom:16px">
        <div onclick="selectSecMode('3D',this)" id="smode-3D"
          style="flex:1;padding:9px;border-radius:11px;border:1.5px solid var(--gold);background:rgba(255,215,0,.06);cursor:pointer;text-align:center;font-size:.78rem;font-weight:700;color:var(--gold)">
          <i class="fas fa-shield-alt"></i> 3D Secure
        </div>
        <div onclick="selectSecMode('2D',this)" id="smode-2D"
          style="flex:1;padding:9px;border-radius:11px;border:1.5px solid var(--border);background:rgba(255,255,255,.03);cursor:pointer;text-align:center;font-size:.78rem;font-weight:700;color:var(--muted2)">
          <i class="fas fa-credit-card"></i> 2D / MOTO
        </div>
      </div>

      <!-- حقول المرجع الأصلي وكود الموافقة (تظهر لبعض العمليات) -->
      <div id="origRefWrap" style="display:none;margin-bottom:16px">
        <div class="fld-row" style="margin-bottom:10px">
          <div class="fld">
            <label><i class="fas fa-hashtag"></i> <?=$ar?'رقم المرجع الأصلي (RRN)':'Original Reference (RRN)'?></label>
            <input type="text" id="txnOrigRef" placeholder="<?=$ar?'رقم العملية السابقة':'Previous transaction reference'?>">
          </div>
          <div class="fld">
            <label><i class="fas fa-check-circle"></i> <?=$ar?'كود الموافقة (Approval)':'Approval Code'?></label>
            <input type="text" id="txnApprovalCode" placeholder="<?=$ar?'رمز الموافقة':'Approval code'?>">
          </div>
        </div>
      </div>

      <div class="fld-row">
        <div class="fld">
          <label><?=$ar?'المبلغ':'Amount'?></label>
          <input type="number" id="txnAmount" min="1" step="0.01" placeholder="0.00" oninput="updateSummary()">
        </div>
        <div class="fld">
          <label><?=$ar?'العملة':'Currency'?></label>
          <select id="txnCurrency" onchange="updateSummary()">
            <option value="USD">USD</option>
            <option value="AED">AED</option>
            <option value="SAR">SAR</option>
            <option value="EUR">EUR</option>
            <option value="GBP">GBP</option>
            <option value="KWD">KWD</option>
            <option value="EGP">EGP</option>
            <option value="QAR">QAR</option>
          </select>
        </div>
      </div>
      <div class="fld" style="margin-top:10px">
        <label><?=$ar?'ملاحظات (اختياري)':'Notes (optional)'?></label>
        <input type="text" id="txnNotes" placeholder="<?=$ar?'رقم الفاتورة، اسم العميل...':'Invoice number, client name...'?>">
      </div>
    </div>

    <div style="display:flex;gap:12px">
      <button class="continue-btn" style="background:rgba(255,255,255,.06);color:var(--text);box-shadow:none;flex:0 0 120px" onclick="goStep(2)">
        <i class="fas fa-arrow-right"></i> <?=$ar?'رجوع':'Back'?>
      </button>
      <button class="continue-btn" id="btn-step3" onclick="goStep(4)" disabled>
        <?=$ar?'مراجعة وتأكيد':'Review & Confirm'?> <i class="fas fa-arrow-left"></i>
      </button>
    </div>
  </div>

  <!-- ══ STEP 4: التأكيد ══ -->
  <div id="sec-step4" style="display:none">
    <div class="section-title"><i class="fas fa-check-double"></i> <?=$ar?'مراجعة وتأكيد':'Review & Confirm'?></div>

    <!-- Summary -->
    <div id="summaryBox" class="amount-section" style="margin-bottom:20px;font-size:.85rem">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div><span style="color:var(--muted2)"><?=$ar?'البوابة:':'Gateway:'?></span> <span id="sum-gw" style="font-weight:800;color:var(--gold)">—</span></div>
        <div><span style="color:var(--muted2)"><?=$ar?'الوجهة:':'Destination:'?></span> <span id="sum-dest" style="font-weight:800;color:var(--green)">—</span></div>
        <div><span style="color:var(--muted2)"><?=$ar?'المبلغ:':'Amount:'?></span> <span id="sum-amount" style="font-weight:800">—</span></div>
        <div><span style="color:var(--muted2)"><?=$ar?'النوع:':'Type:'?></span> <span id="sum-type" style="font-weight:800">—</span></div>
        <div id="sum-wallet-row" style="display:none;grid-column:span 2"><span style="color:var(--muted2)"><?=$ar?'المحفظة:':'Wallet:'?></span> <span id="sum-wallet" style="font-family:monospace;font-size:.75rem;word-break:break-all">—</span></div>
      </div>
    </div>

    <div style="display:flex;gap:12px">
      <button class="continue-btn" style="background:rgba(255,255,255,.06);color:var(--text);box-shadow:none;flex:0 0 120px" onclick="goStep(3)">
        <i class="fas fa-arrow-right"></i> <?=$ar?'رجوع':'Back'?>
      </button>
      <button class="continue-btn" id="btn-proceed" onclick="proceedToCheckout()">
        <i class="fas fa-lock"></i> <?=$ar?'تأكيد والمتابعة للدفع':'Confirm & Proceed to Payment'?>
      </button>
    </div>
  </div>
</div>

<div id="toast"></div>

<script>
const AR   = <?=$ar?'true':'false'?>;
const CSRF = '<?=$csrf?>';

const STATE = {
  gateway: null,
  destination: null,
  walletAddr: '',
  amount: 0,
  currency: 'USD',
  txnType: 'purchase',
};

const GW_ROUTES = {
  nuvei:        'checkout/nuvei.php',
  stripe:       'checkout/stripe.php',
  paypal:       'checkout/paypal.php',
  wise:         'checkout/wise.php',
  myfatoorah:   'checkout/myfatoorah.php',
  binance:      'checkout/binance.php',
  gate_io:      'checkout/gate_io.php',
  hsbc_uae:     'checkout/bank_hsbc.php',
  nbe_egypt:    'checkout/bank_nbe.php',
  mashreq:      'checkout/bank_mashreq.php',
  jpmorgan:     'checkout/bank_jpmorgan.php',
  whop:         'checkout/whop.php',
  redotpay:     'checkout/redotpay.php',
  diparma:      'checkout_diparma.php',
  payram:       'checkout_diparma.php',
};

// ── Step Navigation ────────────────────────────────────────
function goStep(n) {
  // Validation
  if(n===2 && !STATE.gateway) { toast(AR?'اختر بوابة الدفع':'Select payment gateway','error'); return; }
  if(n===3 && !STATE.destination) { toast(AR?'اختر وجهة المبلغ':'Select amount destination','error'); return; }
  if(n===4) {
    const amt = parseFloat(document.getElementById('txnAmount').value)||0;
    if(amt<=0) { toast(AR?'أدخل المبلغ':'Enter amount','error'); return; }
    STATE.amount   = amt;
    STATE.currency = document.getElementById('txnCurrency').value;
    STATE.txnType  = STATE_TXN.type;
    updateConfirmSummary();
  }

  for(let i=1;i<=4;i++) {
    document.getElementById('sec-step'+i).style.display = i===n?'':'none';
    const item = document.getElementById('step'+i+'-item');
    item.className = 'step-item ' + (i<n?'done':i===n?'active':'');
  }
}

// ── Transaction Type (10 أنواع) ────────────────────────────
const STATE_TXN = { type: 'purchase_3d', secMode: '3D' };
const NEED_ORIG = ['auth_complete','refund','reversal','void','offline_purchase','online_purchase'];
const NO_AMOUNT  = ['balance','settlement'];

window.selectTxnTypeRouter = function(type, el) {
  STATE_TXN.type = type;
  document.querySelectorAll('#txnTypeGrid > div').forEach(d => {
    d.style.borderColor = 'var(--border)';
    d.style.background  = 'rgba(255,255,255,.03)';
    if(d.querySelector('.rtt-name')) d.querySelector('.rtt-name').style.color = 'var(--muted2)';
  });
  el.style.borderColor = 'var(--gold)';
  el.style.background  = 'rgba(255,215,0,.05)';
  if(el.querySelector('.rtt-name')) el.querySelector('.rtt-name').style.color = 'var(--gold)';

  const needsRrn = el.dataset.needsRrn === '1';
  document.getElementById('secModeWrap').style.display  = (type === 'purchase_3d' || type === 'purchase_moto') ? '' : 'none';
  document.getElementById('origRefWrap').style.display  = needsRrn ? '' : 'none';
  if(needsRrn) {
    document.getElementById('txnOrigRef').placeholder = 'Previous transaction reference';
    document.getElementById('txnApprovalCode').placeholder = 'Approval code';
  document.querySelectorAll('.gw-card').forEach(c => c.classList.remove('selected'));
  if (el) el.classList.add('selected');
  document.getElementById('btn-step1').disabled = false;
};

window.addEventListener('DOMContentLoaded', function() {
  const preferred = 'payram';
  const cardEl = document.getElementById('gw-' + preferred);
  if (cardEl) {
    selectGateway(preferred, cardEl);
    document.getElementById('btn-step1').disabled = false;
  }
});

// ── Destination Selection ──────────────────────────────────
window.selectDestination = function(code, el) {
  STATE.destination = code;
  document.querySelectorAll('.dest-card').forEach(c => c.classList.remove('selected'));
  el.classList.add('selected');
  document.getElementById('btn-step2').disabled = false;

  // إظهار حقل محفظة مخصصة
  const customCodes = ['tron_w','erc20_w','btc_w'];
  const wrap = document.getElementById('customWalletWrap');
  wrap.className = customCodes.includes(code) ? 'wallet-input-wrap show' : 'wallet-input-wrap';

  const labels = {
    tron_w:  AR?'عنوان TRC20':'TRC20 Address',
    erc20_w: AR?'عنوان ERC20':'ERC20 Address',
    btc_w:   AR?'عنوان Bitcoin':'Bitcoin Address',
  };
  if(labels[code]) document.getElementById('customWalletLabel').innerHTML =
    '<i class="fas fa-wallet"></i> ' + labels[code];
};

// ── Amount Update ──────────────────────────────────────────
function updateSummary() {
  const amt = parseFloat(document.getElementById('txnAmount').value)||0;
  document.getElementById('btn-step3').disabled = amt <= 0;
}

// ── Confirm Summary ────────────────────────────────────────
function updateConfirmSummary() {
  const gwNames = <?=json_encode(array_map(fn($g)=>$g['name'],$gateways))?>;
  const destNames = {
    gateway:AR?'نفس البوابة':'Same Gateway',
    stripe:'Stripe',paypal:'PayPal',nuvei:'Nuvei (Mashreq)',
    wise:'Wise',myfatoorah:'MyFatoorah',binance_ex:'Binance',gate_io:'Gate.io',whop:'Whop',
    mashreq:'Mashreq Bank',hsbc:'HSBC UAE',nbe:'NBE Egypt',jpmorgan:'JP Morgan',
    ledger_trx:'Ledger TRX',tron_w:'Custom TRC20',erc20_w:'Custom ERC20',btc_w:'Bitcoin'
  };
  const typeNames = {
    purchase_3d:      AR?'شراء 3D Secure':'Purchase 3D',
    purchase_moto:    AR?'شراء MOTO / 2D':'Purchase MOTO',
    auth:             AR?'تفويض':'Authorization',
    auth_complete:    AR?'إتمام تفويض MOTO':'Auth Completion',
    purchase_advice:  AR?'إشعار شراء':'Purchase Advice',
    offline_purchase: AR?'شراء أوفلاين MOTO':'Offline Purchase',
    online_purchase:  AR?'شراء أونلاين MOTO':'Online Purchase',
    refund:           AR?'استرداد':'Refund',
    reversal:         AR?'إلغاء عملية':'Reversal',
    balance:          AR?'استعلام رصيد':'Balance Inquiry',
    cash_advance:     AR?'سلفة نقدية':'Cash Advance',
    void:             AR?'إلغاء':'Void',
    settlement:       AR?'تسوية':'Settlement',
    quasi_cash:       AR?'شبه نقدي':'Quasi Cash',
    transfer:         AR?'تحويل P2P':'Transfer',
    payment:          AR?'دفع فاتورة':'Bill Payment',
  };

  document.getElementById('sum-gw').textContent     = gwNames[STATE.gateway]    || STATE.gateway;
  document.getElementById('sum-dest').textContent   = destNames[STATE.destination] || STATE.destination;
  document.getElementById('sum-amount').textContent = STATE.amount.toFixed(2) + ' ' + STATE.currency;
  document.getElementById('sum-type').textContent   = typeNames[STATE.txnType] || STATE.txnType;

  const walletRow = document.getElementById('sum-wallet-row');
  const customCodes = ['tron_w','erc20_w','btc_w'];
  const walletAddr  = document.getElementById('customWalletAddr').value.trim()
                   || (STATE.destination==='ledger_trx' ? 'TEwLFWlwK55b7PuFfzgH1H2f3xs3pLgLn2' : '');
  STATE.walletAddr = walletAddr;
  if(walletAddr) {
    walletRow.style.display = '';
    document.getElementById('sum-wallet').textContent = walletAddr;
  } else {
    walletRow.style.display = 'none';
  }
}

// ── Proceed to Checkout ────────────────────────────────────
window.proceedToCheckout = function() {
  const route = GW_ROUTES[STATE.gateway];
  if (!route) { toast(AR?'صفحة البوابة غير متاحة بعد':'Gateway page not available yet','error'); return; }

  const params = new URLSearchParams({
    gateway:     STATE.gateway,
    destination: STATE.destination,
    amount:      STATE.amount,
    currency:    STATE.currency,
    txn_type:    STATE_TXN.type,
    sec_mode:    STATE_TXN.secMode,
    orig_ref:    document.getElementById('txnOrigRef')?.value.trim() || '',
    approval_code: document.getElementById('txnApprovalCode')?.value.trim() || '',
// ── Toast ──────────────────────────────────────────────────
function toast(msg, type='info') {
  const t = document.getElementById('toast');
  const c = {success:'var(--green)',error:'var(--red)',info:'var(--gold)'};
  t.style.borderColor = c[type]||c.info;
  t.style.color = c[type]||c.info;
  t.textContent = msg;
  t.style.transform = 'translateX(-50%) translateY(0)';
  clearTimeout(t._t);
  t._t = setTimeout(()=>{ t.style.transform='translateX(-50%) translateY(100px)'; },4000);
}
</script>
</body>
</html>
