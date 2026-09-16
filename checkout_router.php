<?php
/**
 * ============================================================
 * DI PARMA | Checkout Router
 * اختيار البوابة + وجهة المبلغ → صفحة checkout مستقلة
 * ============================================================
 */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Checkout Router — اختيار البوابة والمبلغ
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/gateways.php';

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/activity_flow.php';

$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang']==='ar' ? 'ar' : 'en';
$ar   = ($lang === 'ar');
$dir  = $ar ? 'rtl' : 'ltr';
$csrf = generateCsrfToken();
$db   = db();

// ── البوابات المتاحة في الواجهة ───────────────────────────────────────
$allGateways = [
    'payram'     => ['name'=>'PayRam',        'icon'=>'fas fa-server',         'color'=>'#10B981','type'=>'crypto', 'desc_ar'=>'بعد الموافقة: الصافي USDT → Ledger','desc_en'=>'After approval: net USDT → Ledger'],
    'diparma'    => ['name'=>'DI PARMA',      'icon'=>'fas fa-coins',          'color'=>'#FFD700','type'=>'card',   'desc_ar'=>'بوابة مفعّلة تخصم البطاقة ثم الصافي → Ledger','desc_en'=>'Enabled gateway charges the card, then net → Ledger'],
    'nuvei'      => ['name'=>'Nuvei',        'icon'=>'fas fa-credit-card',   'color'=>'#F97316','type'=>'card',   'desc_ar'=>'خصم البطاقة على Nuvei إن كانت مفعّلة. الصافي → Ledger','desc_en'=>'Card charge on Nuvei if enabled. Net → Ledger'],
    'stripe'     => ['name'=>'Stripe',       'icon'=>'fab fa-stripe-s',       'color'=>'#6772e5','type'=>'card',   'desc_ar'=>'كل الشبكات والمُصدرين','desc_en'=>'All networks and issuers'],
    'square'     => ['name'=>'Square',       'icon'=>'fas fa-square',          'color'=>'#006AFF','type'=>'card',   'desc_ar'=>'كل الشبكات والمُصدرين','desc_en'=>'All networks and issuers'],
    'paypal'     => ['name'=>'PayPal',        'icon'=>'fab fa-paypal',         'color'=>'#003087','type'=>'card',   'desc_ar'=>'كل الشبكات والمُصدرين','desc_en'=>'All networks and issuers'],
    'wise'       => ['name'=>'Wise',          'icon'=>'fas fa-exchange-alt',   'color'=>'#9fe870','type'=>'digital','desc_ar'=>'كل الشبكات والمُصدرين عبر Wise','desc_en'=>'All networks and issuers via Wise'],
    'myfatoorah' => ['name'=>'MyFatoorah',    'icon'=>'fas fa-money-bill-wave','color'=>'#00b09b','type'=>'card',   'desc_ar'=>'كل الشبكات والمُصدرين — الشرق الأوسط','desc_en'=>'All networks and issuers — Middle East'],
    'binance'    => ['name'=>'Binance',       'icon'=>'fas fa-coins',          'color'=>'#F3BA2F','type'=>'crypto', 'desc_ar'=>'كريبتو + كل الشبكات والمُصدرين','desc_en'=>'Crypto + all networks and issuers'],
    'gate_io'    => ['name'=>'Gate.io',       'icon'=>'fas fa-coins',          'color'=>'#E8112D','type'=>'crypto', 'desc_ar'=>'كريبتو + كل الشبكات والمُصدرين','desc_en'=>'Crypto + all networks and issuers'],
    'mashreq'    => ['name'=>'Mashreq Bank',  'icon'=>'fas fa-university',     'color'=>'#FF6600','type'=>'bank',   'desc_ar'=>'تحويل بنكي + كل الشبكات والمُصدرين','desc_en'=>'Bank transfer + all networks and issuers'],
    'hsbc_uae'   => ['name'=>'HSBC UAE',      'icon'=>'fas fa-university',     'color'=>'#DB0011','type'=>'bank',   'desc_ar'=>'تحويل بنكي + كل الشبكات والمُصدرين','desc_en'=>'Bank transfer + all networks and issuers'],
    'nbe_egypt'  => ['name'=>'NBE Egypt',     'icon'=>'fas fa-landmark',       'color'=>'#006633','type'=>'bank',   'desc_ar'=>'تحويل بنكي + كل الشبكات والمُصدرين','desc_en'=>'Bank transfer + all networks and issuers'],
    'jpmorgan'   => ['name'=>'JP Morgan Chase','icon'=>'fas fa-landmark',      'color'=>'#003087','type'=>'bank',   'desc_ar'=>'تحويل بنكي + كل الشبكات والمُصدرين','desc_en'=>'Bank transfer + all networks and issuers'],
    'whop'       => ['name'=>'Whop',          'icon'=>'fas fa-bolt',           'color'=>'#7C3AED','type'=>'digital','desc_ar'=>'كل الشبكات والمُصدرين','desc_en'=>'All networks and issuers'],
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

  // كل بوابة تفتح صفحتها المستقلة وفيها جميع عمليات الشراء
  $gatewayRoutes = [];
  foreach (array_keys($allGateways) as $code) {
      $routeFile = activity_checkout_route($code);
      if ($routeFile !== '') {
          $gatewayRoutes[$code] = $routeFile;
      }
  }

  $gatewayState = [];
  $filteredGateways = [];
  try {
    if (isset($db) && is_object($db) && method_exists($db, 'query')) {
      $gatewayRows = $db->query("SELECT code,status,connection_status,config,credentials,settings FROM dp_payment_gateways WHERE status != 'deleted'");
      foreach (($gatewayRows ?? []) as $row) {
        $code = function_exists('dp_gateway_normalize_code')
          ? dp_gateway_normalize_code((string)($row['code'] ?? ''))
          : strtolower((string)($row['code'] ?? ''));
        if ($code !== '') {
          $row['code'] = $code;
          $gatewayState[$code] = $row;
        }
      }
    }
  } catch (Throwable $e) {
    $gatewayState = [];
  }

  // POS و Checkout: البوابات المفعّلة فقط (status = active)
  foreach ($gatewayRoutes as $code => $routeFile) {
    if (!isset($allGateways[$code])) {
      continue;
    }
    $row = $gatewayState[$code] ?? null;
    if ($row === null) {
      continue;
    }
    $row['code'] = $code;
    if (isGatewayVisibleInCheckout($row)) {
      $filteredGateways[$code] = $allGateways[$code];
    }
  }

  $gateways = $filteredGateways;
  $gatewayRoutes = array_intersect_key($gatewayRoutes, $gateways);
  $activityLines = pos_merchant_lines();
  $activityChannels = activity_channels();
  $activityOps = activity_operations();
  $ledgerAddr = activity_ledger_address();
  $connectedPos = activity_connected_gateways('pos');
  $connectedLink = activity_connected_gateways('link');
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
  <div class="page-title"><i class="fas fa-briefcase"></i> <?=$ar?'الدفع حسب النشاط':'Pay by activity'?></div>
  <div class="page-sub"><?=$ar?'نشاط الشركة → POS أو رابط → البوابة المتصلة → نوع العملية. المبلغ يصل لمحفظة Ledger.':'Business activity → POS or Link → connected gateway → operation. Amount arrives at the Ledger wallet.'?></div>

  <!-- Steps Bar -->
  <div class="steps-bar">
    <div class="step-item active" id="step1-item">
      <div class="step-num">1</div>
      <div class="step-label"><?=$ar?'النشاط':'Activity'?></div>
    </div>
    <div class="step-sep"></div>
    <div class="step-item" id="step2-item">
      <div class="step-num">2</div>
      <div class="step-label"><?=$ar?'POS أو رابط':'POS or Link'?></div>
    </div>
    <div class="step-sep"></div>
    <div class="step-item" id="step3-item">
      <div class="step-num">3</div>
      <div class="step-label"><?=$ar?'البوابة':'Gateway'?></div>
    </div>
    <div class="step-sep"></div>
    <div class="step-item" id="step4-item">
      <div class="step-num">4</div>
      <div class="step-label"><?=$ar?'العملية':'Operation'?></div>
    </div>
  </div>

  <!-- ══ STEP 1: النشاط ══ -->
  <div id="sec-step1">
    <div class="section-title"><i class="fas fa-briefcase"></i> <?=$ar?'نشاط الشركة':'Business activity'?></div>
    <div class="amount-section" style="margin:12px 0 18px">
      <label for="activitySelect" style="display:block;font-size:.75rem;font-weight:800;color:var(--muted2);margin-bottom:8px"><?=$ar?'قائمة الأنشطة (تُضاف لاحقاً)':'Activity list (add more later)'?></label>
      <select id="activitySelect" onchange="selectActivity(this.value)" style="width:100%;padding:14px 16px;border-radius:12px;border:1px solid var(--border);background:var(--card);color:var(--text);font-family:inherit;font-size:.95rem;font-weight:700">
        <option value=""><?=$ar?'— اختر النشاط —':'— Select activity —'?></option>
        <?php foreach ($activityLines as $lineKey => $lineRow): ?>
        <option value="<?=htmlspecialchars($lineKey)?>">
          <?=$ar ? htmlspecialchars($lineRow['ar']) : htmlspecialchars($lineRow['en'])?> · MCC <?=htmlspecialchars($lineRow['mcc'] ?? '')?>
        </option>
        <?php endforeach; ?>
      </select>
      <div style="font-size:.72rem;color:var(--muted2);margin-top:10px;line-height:1.6">
        <?=$ar
          ? 'حالياً: بترول · لوجستيك · حج · فنادق · معارض · سيارات · تأجير. أضف أنشطة جديدة من شاشة POS.'
          : 'Current: petroleum · logistics · hajj · hotels · expo · autos · rental. Add more from the POS screen.'?>
      </div>
    </div>
    <button class="continue-btn" id="btn-step1" onclick="goStep(2)" disabled>
      <?=$ar?'التالي — POS أو رابط':'Next — POS or Link'?> <i class="fas fa-arrow-left"></i>
    </button>
  </div>

  <!-- ══ STEP 2: القناة ══ -->
  <div id="sec-step2" style="display:none">
    <div class="section-title"><i class="fas fa-random"></i> <?=$ar?'نوع السحب':'Channel'?></div>
    <div class="gw-grid">
      <?php foreach ($activityChannels as $chKey => $ch): ?>
      <div class="gw-card" id="ch-<?=htmlspecialchars($chKey)?>" onclick="selectChannel('<?=htmlspecialchars($chKey)?>',this)">
        <div class="gw-icon" style="background:<?=$ch['color']?>22;color:<?=$ch['color']?>"><i class="fas <?=$ch['icon']?>"></i></div>
        <div class="gw-name"><?=$ar?$ch['ar']:$ch['en']?></div>
        <div class="gw-desc"><?=$ar?$ch['desc_ar']:$ch['desc_en']?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="amount-section" style="margin-top:8px">
      <div style="font-size:.75rem;font-weight:800;color:var(--green);margin-bottom:6px">LEDGER</div>
      <div style="font-family:monospace;font-size:.8rem;word-break:break-all"><?=htmlspecialchars($ledgerAddr !== '' ? $ledgerAddr : 'LEDGER_TRC20_ADDRESS')?></div>
      <div style="font-size:.7rem;color:var(--muted2);margin-top:8px"><?=$ar?'وجهة المبلغ ثابتة: عنوان المحفظة في LEDGER.':'Amount destination is fixed: the LEDGER wallet address.'?></div>
    </div>
    <div style="display:flex;gap:12px">
      <button class="continue-btn" style="background:rgba(255,255,255,.06);color:var(--text);box-shadow:none;flex:0 0 120px" onclick="goStep(1)"><i class="fas fa-arrow-right"></i> <?=$ar?'رجوع':'Back'?></button>
      <button class="continue-btn" id="btn-step2" onclick="goStep(3)" disabled><?=$ar?'التالي — البوابة':'Next — Gateway'?> <i class="fas fa-arrow-left"></i></button>
    </div>
  </div>

  <!-- ══ STEP 3: البوابة المتصلة ══ -->
  <div id="sec-step3" style="display:none">
    <div class="section-title"><i class="fas fa-plug"></i> <?=$ar?'مزود الخدمة — المتصل فقط':'Provider — connected only'?></div>

    <?php if (empty($gateways)): ?>
    <div style="border:1px solid var(--border);border-radius:14px;padding:22px;background:var(--card);margin:12px 0 18px;text-align:center">
      <div style="font-weight:800;margin-bottom:8px;color:var(--gold)">
        <?=$ar?'لا توجد بوابة متصلة للعمل الحقيقي':'No live connected gateway'?>
      </div>
      <div style="font-size:.82rem;color:var(--muted2);line-height:1.7">
        <?=$ar
          ? 'البوابات غير المتصلة تبقى في إدارة بوابات الدفع إلى أن تضيف مفاتيح الاتصال وتختبرها. بعد الاتصال تظهر هنا وفي POS فقط.'
          : 'Disconnected gateways stay in Payment Gateway Manager until you add connection keys and test them. After that they appear here and on POS.'?>
      </div>
      <a href="admin/gateway_manager.php" style="display:inline-block;margin-top:14px;color:#000;background:var(--gold);padding:10px 16px;border-radius:10px;text-decoration:none;font-weight:800;font-size:.82rem">
        <?=$ar?'فتح إدارة البوابات':'Open Gateway Manager'?>
      </a>
    </div>
    <?php endif; ?>

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

    <div style="display:flex;gap:12px">
      <button class="continue-btn" style="background:rgba(255,255,255,.06);color:var(--text);box-shadow:none;flex:0 0 120px" onclick="goStep(2)"><i class="fas fa-arrow-right"></i> <?=$ar?'رجوع':'Back'?></button>
      <button class="continue-btn" id="btn-step3gw" onclick="goStep(4)" disabled>
        <?=$ar?'التالي — نوع العملية':'Next — Operation'?> <i class="fas fa-arrow-left"></i>
      </button>
    </div>
  </div>

  <!-- ══ STEP 4: العملية ══ -->
  <div id="sec-step4" style="display:none">
    <div class="section-title"><i class="fas fa-dollar-sign"></i> <?=$ar?'المبلغ والعملة':'Amount & Currency'?></div>
    <div class="amount-section">

      <!-- 13 أنواع العمليات -->
      <div class="section-title" style="margin-bottom:12px"><i class="fas fa-list"></i> <?=$ar?'نوع العملية (13)':'Operation type (13)'?></div>
      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:20px" id="txnTypeGrid">
        <?php
        $txnTypesRouter = [];
        foreach ($activityOps as $op) {
            $pk = $op['pos_key'];
            $txnTypesRouter[$pk] = [
                'ar' => $op['ar'],
                'en' => $op['en'],
                'icon' => $op['icon'],
                'color' => $op['color'],
                'sub' => '',
                'rrn' => !empty($op['requires_rrn']),
            ];
        }
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
      <button class="continue-btn" style="background:rgba(255,255,255,.06);color:var(--text);box-shadow:none;flex:0 0 120px" onclick="goStep(3)">
        <i class="fas fa-arrow-right"></i> <?=$ar?'رجوع':'Back'?>
      </button>
      <button class="continue-btn" id="btn-step3" onclick="proceedToCheckout()">
        <?=$ar?'متابعة':'Continue'?> <i class="fas fa-arrow-left"></i>
      </button>
    </div>
  </div>

  <!-- review (unused — confirm is step 4 continue) -->
  <div id="sec-review" style="display:none">
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
  line: null,
  channel: null,
  gateway: null,
  destination: 'ledger_trx',
  walletAddr: <?=json_encode($ledgerAddr)?>,
  amount: 0,
  currency: 'USD',
  txnType: 'purchase_3d',
};
const LEDGER_ADDR = <?=json_encode($ledgerAddr)?>;
const POS_GWS = <?=json_encode(array_keys($connectedPos), JSON_UNESCAPED_UNICODE)?>;
const LINK_GWS = <?=json_encode(array_keys($connectedLink), JSON_UNESCAPED_UNICODE)?>;

const GW_ROUTES = <?=json_encode($gatewayRoutes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)?>;

function selectActivity(code, el) {
  STATE.line = code || null;
  const sel = document.getElementById('activitySelect');
  if (sel && code && sel.value !== code) sel.value = code;
  const btn = document.getElementById('btn-step1');
  if (btn) btn.disabled = !STATE.line;
}
window.selectActivity = selectActivity;

function selectChannel(code, el) {
  STATE.channel = code;
  document.querySelectorAll('[id^="ch-"]').forEach(card => card.classList.remove('selected'));
  if (el) el.classList.add('selected');
  const btn = document.getElementById('btn-step2');
  if (btn) btn.disabled = false;
  const allow = code === 'pos' ? POS_GWS : LINK_GWS;
  document.querySelectorAll('.gw-card[id^="gw-"]').forEach(card => {
    const id = (card.id || '').replace('gw-', '');
    card.style.display = allow.indexOf(id) >= 0 ? '' : 'none';
    card.classList.remove('selected');
  });
  STATE.gateway = null;
  const gwBtn = document.getElementById('btn-step3gw');
  if (gwBtn) gwBtn.disabled = true;
}
window.selectChannel = selectChannel;

function selectGateway(code, el) {
  STATE.gateway = code;
  document.querySelectorAll('.gw-card[id^="gw-"]').forEach(card => card.classList.remove('selected'));
  if (el) el.classList.add('selected');
  const btn = document.getElementById('btn-step3gw');
  if (btn) btn.disabled = false;
  STATE.destination = 'ledger_trx';
  STATE.walletAddr = LEDGER_ADDR;
}
window.selectGateway = selectGateway;

function lockLedgerOnlyDestination(code) {
  document.querySelectorAll('.dest-card').forEach(card => {
    const id = (card.id || '').replace('dest-', '');
    card.style.display = id === 'ledger_trx' ? '' : 'none';
    if (id !== 'ledger_trx') card.classList.remove('selected');
  });
  const ledger = document.getElementById('dest-ledger_trx');
  if (ledger) selectDestination('ledger_trx', ledger);
}

function goStep(n) {
  if (n === 2 && !STATE.line) {
    toast(AR ? 'اختر نشاط الشركة' : 'Select the business activity', 'error');
    return;
  }
  if (n === 3 && !STATE.channel) {
    toast(AR ? 'اختر POS أو رابط' : 'Select POS or Link', 'error');
    return;
  }
  if (n === 4 && !STATE.gateway) {
    toast(AR ? 'اختر مزود الخدمة' : 'Select a connected gateway', 'error');
    return;
  }

  for (let i = 1; i <= 4; i++) {
    const sec = document.getElementById('sec-step' + i);
    if (sec) sec.style.display = i === n ? '' : 'none';
    const item = document.getElementById('step' + i + '-item');
    if (item) item.className = 'step-item ' + (i < n ? 'done' : i === n ? 'active' : '');
  }
}

const STATE_TXN = { type: 'purchase_3d', secMode: '3D' };
const NEED_ORIG = ['auth_complete', 'refund', 'reversal', 'void', 'offline_purchase', 'online_purchase'];
const NO_AMOUNT = ['balance', 'settlement'];

function selectTxnTypeRouter(type, el) {
  STATE_TXN.type = type;
  document.querySelectorAll('#txnTypeGrid > div').forEach(d => {
    d.style.borderColor = 'var(--border)';
    d.style.background = 'rgba(255,255,255,.03)';
    const name = d.querySelector('.rtt-name');
    if (name) name.style.color = 'var(--muted2)';
  });

  el.style.borderColor = 'var(--gold)';
  el.style.background = 'rgba(255,215,0,.05)';
  const name = el.querySelector('.rtt-name');
  if (name) name.style.color = 'var(--gold)';

  const needsRrn = el.dataset.needsRrn === '1';
  const secWrap = document.getElementById('secModeWrap');
  const origWrap = document.getElementById('origRefWrap');
  if (secWrap) secWrap.style.display = (type === 'purchase_3d' || type === 'purchase_moto') ? '' : 'none';
  if (origWrap) origWrap.style.display = needsRrn ? '' : 'none';

  if (needsRrn) {
    const orig = document.getElementById('txnOrigRef');
    const approval = document.getElementById('txnApprovalCode');
    if (orig) orig.placeholder = 'Previous transaction reference';
    if (approval) approval.placeholder = 'Approval code';
  }
}
window.selectTxnTypeRouter = selectTxnTypeRouter;

function selectSecMode(mode, el) {
  STATE_TXN.secMode = mode;
  ['smode-3D', 'smode-2D'].forEach(id => {
    const node = document.getElementById(id);
    if (!node) return;
    const active = id === 'smode-' + mode;
    node.style.borderColor = active ? 'var(--gold)' : 'var(--border)';
    node.style.background = active ? 'rgba(255,215,0,.06)' : 'rgba(255,255,255,.03)';
    node.style.color = active ? 'var(--gold)' : 'var(--muted2)';
  });
}
window.selectSecMode = selectSecMode;

window.addEventListener('DOMContentLoaded', function() {
  // لا نفرض بوابة افتراضية — المستخدم يختار من البوابات الظاهرة فقط
  const firstGw = document.querySelector('.gw-card');
  if (firstGw && typeof firstGw.onclick === 'function') {
    // لا ننقر تلقائياً؛ يبقى الاختيار يدوياً
  }

  const defaultTxn = document.getElementById('rtt-purchase_3d');
  if (defaultTxn) window.selectTxnTypeRouter('purchase_3d', defaultTxn);
  if (document.getElementById('smode-3D')) window.selectSecMode('3D', document.getElementById('smode-3D'));
  document.getElementById('txnAmount').value = '10';
  updateSummary();
});

function selectDestination(code, el) {
  if (code !== 'ledger_trx') {
    toast(AR ? 'وجهة التسوية Ledger فقط. ليست IBAN بنك.' : 'Settlement destination is Ledger only. Not a bank IBAN.', 'error');
    return;
  }
  STATE.destination = code;
  document.querySelectorAll('.dest-card').forEach(c => c.classList.remove('selected'));
  if (el) el.classList.add('selected');
  const btn = document.getElementById('btn-step2');
  if (btn) btn.disabled = false;

  const customCodes = ['tron_w', 'erc20_w', 'btc_w'];
  const wrap = document.getElementById('customWalletWrap');
  if (wrap) wrap.className = customCodes.includes(code) ? 'wallet-input-wrap show' : 'wallet-input-wrap';

  const labels = {
    tron_w: AR ? 'عنوان TRC20' : 'TRC20 Address',
    erc20_w: AR ? 'عنوان ERC20' : 'ERC20 Address',
    btc_w: AR ? 'عنوان Bitcoin' : 'Bitcoin Address',
  };
  const labelEl = document.getElementById('customWalletLabel');
  if (labels[code] && labelEl) labelEl.innerHTML = '<i class="fas fa-wallet"></i> ' + labels[code];
}
window.selectDestination = selectDestination;

function updateSummary() {
  const amt = parseFloat(document.getElementById('txnAmount').value) || 0;
  const step3 = document.getElementById('btn-step3');
  if (step3) step3.disabled = amt <= 0;
}

function updateConfirmSummary() {
  const gwNames = <?=json_encode(array_map(fn($g) => $g['name'], $gateways))?>;
  const destNames = {
    gateway: AR ? 'نفس البوابة' : 'Same Gateway',
    stripe: 'Stripe',
    paypal: 'PayPal',
    nuvei: 'Nuvei (Mashreq)',
    wise: 'Wise',
    myfatoorah: 'MyFatoorah',
    binance_ex: 'Binance',
    gate_io: 'Gate.io',
    whop: 'Whop',
    mashreq: 'Mashreq Bank',
    hsbc: 'HSBC UAE',
    nbe: 'NBE Egypt',
    jpmorgan: 'JP Morgan',
    ledger_trx: 'Ledger TRX',
    tron_w: 'Custom TRC20',
    erc20_w: 'Custom ERC20',
    btc_w: 'Bitcoin'
  };
  const typeNames = {
    purchase_3d: 'Purchase 3D',
    purchase_moto: 'Purchase MOTO',
    auth: 'Authorization',
    auth_complete: 'Auth Completion',
    purchase_advice: 'Purchase Advice',
    offline_purchase: 'Offline Purchase',
    online_purchase: 'Online Purchase',
    refund: 'Refund',
    reversal: 'Reversal',
    balance: 'Balance Inquiry',
    cash_advance: 'Cash Advance',
    void: 'Void',
    settlement: 'Settlement',
    quasi_cash: 'Quasi Cash',
    transfer: 'Transfer',
    payment: 'Bill Payment',
  };

  const gwEl = document.getElementById('sum-gw');
  const destEl = document.getElementById('sum-dest');
  const amountEl = document.getElementById('sum-amount');
  const typeEl = document.getElementById('sum-type');

  if (gwEl) gwEl.textContent = gwNames[STATE.gateway] || STATE.gateway;
  if (destEl) destEl.textContent = destNames[STATE.destination] || STATE.destination;
  if (amountEl) amountEl.textContent = STATE.amount.toFixed(2) + ' ' + STATE.currency;
  if (typeEl) typeEl.textContent = typeNames[STATE.txnType] || STATE.txnType;

  const walletRow = document.getElementById('sum-wallet-row');
  const walletAddr = document.getElementById('customWalletAddr')?.value.trim() || (STATE.destination === 'ledger_trx' ? <?=json_encode(defined('LEDGER_TRC20_ADDRESS') ? LEDGER_TRC20_ADDRESS : '')?> : '');
  STATE.walletAddr = walletAddr;
  const sumWallet = document.getElementById('sum-wallet');
  if (walletRow) walletRow.style.display = walletAddr ? '' : 'none';
  if (sumWallet) sumWallet.textContent = walletAddr || '—';
}

window.proceedToCheckout = function() {
  if (!STATE.line || !STATE.channel || !STATE.gateway) {
    toast(AR ? 'أكمل النشاط والقناة والبوابة' : 'Complete activity, channel, and gateway', 'error');
    return;
  }
  if (!LEDGER_ADDR) {
    toast(AR ? 'أضف LEDGER_TRC20_ADDRESS' : 'Set LEDGER_TRC20_ADDRESS', 'error');
    return;
  }
  STATE.destination = 'ledger_trx';
  STATE.walletAddr = LEDGER_ADDR;
  STATE.txnType = STATE_TXN.type;
  STATE.amount = parseFloat(document.getElementById('txnAmount')?.value) || 0;
  STATE.currency = document.getElementById('txnCurrency')?.value || 'USD';

  if (STATE.channel === 'pos') {
    const q = new URLSearchParams({
      kiosk: '1',
      device: 'bitel_ic3600',
      gw: STATE.gateway,
      line: STATE.line,
      op: STATE.txnType
    });
    window.location.href = 'pos/index.php?' + q.toString();
    return;
  }

  const route = GW_ROUTES[STATE.gateway];
  if (!route) {
    toast(AR ? 'صفحة البوابة غير متاحة بعد' : 'Gateway page not available yet', 'error');
    return;
  }
  const params = new URLSearchParams({
    gateway: STATE.gateway,
    destination: 'ledger',
    amount: String(STATE.amount || 0),
    currency: STATE.currency,
    txn_type: STATE.txnType,
    op: STATE.txnType,
    line: STATE.line,
    sec_mode: STATE_TXN.secMode,
    orig_ref: document.getElementById('txnOrigRef')?.value.trim() || '',
    approval_code: document.getElementById('txnApprovalCode')?.value.trim() || '',
    notes: document.getElementById('txnNotes')?.value.trim() || '',
    wallet: LEDGER_ADDR,
    csrf_token: CSRF
  });
  window.location.href = route + '?' + params.toString();
};

function toast(msg, type = 'info') {
  const t = document.getElementById('toast');
  const c = { success: 'var(--green)', error: 'var(--red)', info: 'var(--gold)' };
  t.style.borderColor = c[type] || c.info;
  t.style.color = c[type] || c.info;
  t.textContent = msg;
  t.style.transform = 'translateX(-50%) translateY(0)';
  clearTimeout(t._t);
  t._t = setTimeout(() => {
    t.style.transform = 'translateX(-50%) translateY(100px)';
  }, 4000);
}
</script>
</body>
</html>
