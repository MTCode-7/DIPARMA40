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
$db   = db();

// ── البوابات المتاحة في الواجهة ───────────────────────────────────────
$allGateways = [
    'payram'     => ['name'=>'PayRam',        'icon'=>'fas fa-server',         'color'=>'#10B981','type'=>'crypto', 'desc_ar'=>'بعد الموافقة يبقى المبلغ على PayRam','desc_en'=>'After approval the funds stay on PayRam'],
    'diparma'    => ['name'=>'DI PARMA',      'icon'=>'fas fa-coins',          'color'=>'#FFD700','type'=>'card',   'desc_ar'=>'خصم البطاقة — المبلغ يبقى على DI PARMA','desc_en'=>'Card charge — funds stay on DI PARMA'],
    'nuvei'      => ['name'=>'Nuvei',        'icon'=>'fas fa-credit-card',   'color'=>'#F97316','type'=>'card',   'desc_ar'=>'خصم البطاقة على Nuvei — المبلغ يبقى على Nuvei','desc_en'=>'Card charge on Nuvei — funds stay on Nuvei'],
    'stripe'     => ['name'=>'Stripe',       'icon'=>'fab fa-stripe-s',       'color'=>'#6772e5','type'=>'card',   'desc_ar'=>'كل الشبكات والمُصدرين — المبلغ يبقى على Stripe','desc_en'=>'All networks and issuers — funds stay on Stripe'],
    'square'     => ['name'=>'Square 1',     'icon'=>'fas fa-square',          'color'=>'#006AFF','type'=>'card',   'desc_ar'=>'خصم البطاقة Payments API — المبلغ يبقى على Square','desc_en'=>'Card charge via Payments API — funds stay on Square'],
    'square_online' => ['name'=>'Square 2 · Online', 'icon'=>'fas fa-store',   'color'=>'#006AFF','type'=>'fulfillment','desc_ar'=>'شركة 10 — سياحة، حجوزات، إيجارات، عقارات، فنادق. متجر الإمارات. الخصم على Square 1.','desc_en'=>'Company 10 — tourism, bookings, rentals, real estate, hotels. UAE store. Card charge stays on Square 1.'],
    'paypal'     => ['name'=>'PayPal',        'icon'=>'fab fa-paypal',         'color'=>'#003087','type'=>'card',   'desc_ar'=>'كل الشبكات والمُصدرين','desc_en'=>'All networks and issuers'],
    'wise'       => ['name'=>'Wise Transfer', 'icon'=>'fas fa-exchange-alt',   'color'=>'#9fe870','type'=>'digital','desc_ar'=>'تحويل من رصيد Wise إلى مستفيد','desc_en'=>'Transfer from Wise balance to a recipient'],
    'myfatoorah' => ['name'=>'MyFatoorah',    'icon'=>'fas fa-money-bill-wave','color'=>'#00b09b','type'=>'card',   'desc_ar'=>'كل الشبكات والمُصدرين — الشرق الأوسط','desc_en'=>'All networks and issuers — Middle East'],
    'binance'    => ['name'=>'Binance',       'icon'=>'fas fa-coins',          'color'=>'#F3BA2F','type'=>'crypto', 'desc_ar'=>'كريبتو + كل الشبكات والمُصدرين','desc_en'=>'Crypto + all networks and issuers'],
    'gate_io'    => ['name'=>'Gate.io',       'icon'=>'fas fa-coins',          'color'=>'#E8112D','type'=>'crypto', 'desc_ar'=>'كريبتو + كل الشبكات والمُصدرين','desc_en'=>'Crypto + all networks and issuers'],
    'mashreq'    => ['name'=>'Mashreq Bank',  'icon'=>'fas fa-university',     'color'=>'#FF6600','type'=>'bank',   'desc_ar'=>'تحويل بنكي + كل الشبكات والمُصدرين','desc_en'=>'Bank transfer + all networks and issuers'],
    'hsbc_uae'   => ['name'=>'HSBC UAE',      'icon'=>'fas fa-university',     'color'=>'#DB0011','type'=>'bank',   'desc_ar'=>'تحويل بنكي + كل الشبكات والمُصدرين','desc_en'=>'Bank transfer + all networks and issuers'],
    'nbe_egypt'  => ['name'=>'NBE Egypt',     'icon'=>'fas fa-landmark',       'color'=>'#006633','type'=>'bank',   'desc_ar'=>'تحويل بنكي + كل الشبكات والمُصدرين','desc_en'=>'Bank transfer + all networks and issuers'],
    'jpmorgan'   => ['name'=>'JP Morgan Chase','icon'=>'fas fa-landmark',      'color'=>'#003087','type'=>'bank',   'desc_ar'=>'تحويل بنكي + كل الشبكات والمُصدرين','desc_en'=>'Bank transfer + all networks and issuers'],
    'whop'       => ['name'=>'Whop',          'icon'=>'fas fa-bolt',           'color'=>'#7C3AED','type'=>'digital','desc_ar'=>'كل الشبكات والمُصدرين','desc_en'=>'All networks and issuers'],
    'checkout'   => ['name'=>'Checkout.com',  'icon'=>'fas fa-credit-card',    'color'=>'#1A1F36','type'=>'card',   'desc_ar'=>'خصم البطاقة — المبلغ يبقى على Checkout.com','desc_en'=>'Card charge — funds stay on Checkout.com'],
    'paytabs'    => ['name'=>'PayTabs',       'icon'=>'fas fa-credit-card',    'color'=>'#00AEEF','type'=>'card',   'desc_ar'=>'خصم البطاقة — المبلغ يبقى على PayTabs','desc_en'=>'Card charge — funds stay on PayTabs'],
    'authorizenet'=> ['name'=>'Authorize.Net','icon'=>'fas fa-credit-card',    'color'=>'#1A4E8A','type'=>'card',   'desc_ar'=>'خصم البطاقة — المبلغ يبقى على Authorize.Net','desc_en'=>'Card charge — funds stay on Authorize.Net'],
    'braintree'  => ['name'=>'Braintree',     'icon'=>'fas fa-credit-card',    'color'=>'#00A3E0','type'=>'card',   'desc_ar'=>'خصم البطاقة — المبلغ يبقى على Braintree','desc_en'=>'Card charge — funds stay on Braintree'],
];

$gateways = $allGateways;

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
  $connectedPos = activity_connected_gateways('pos');
  $connectedCheckout = activity_connected_gateways('checkout');
  $connectedLink = activity_connected_gateways('link');
  unset($connectedPos['wise'], $connectedLink['wise'], $connectedLink['square_online']);
  $posRoutes = [];
  $linkRoutes = [];
  foreach (array_keys($gateways) as $code) {
      $posRoutes[$code] = activity_pos_route($code);
      $linkRoutes[$code] = activity_link_route($code);
  }
    $gatewayCurrencies = [];
      $gatewayOperations = [];
      $settlementTargets = ['pos' => [], 'checkout' => [], 'link' => []];
    foreach (array_keys($gateways) as $code) {
      $gatewayCurrencies[$code] = activity_gateway_currencies($code);
        $gatewayOperations[$code] = activity_gateway_checkout_operations($code);
        foreach (['pos', 'checkout', 'link'] as $targetChannel) {
          $settlementTargets[$targetChannel][$code] = activity_settlement_target_choices($code, $targetChannel);
        }
    }
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
/* Toast */
#toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(100px);background:var(--card);border:1px solid var(--border2);border-radius:14px;padding:12px 28px;font-size:.84rem;font-weight:700;z-index:9999;transition:.35s;color:var(--text)}
@media(max-width:600px){.gw-grid{grid-template-columns:1fr 1fr}.fld-row{grid-template-columns:1fr}}
</style>
</head>
<body>
<header class="topbar">
  <div class="tb-brand"><i class="fas fa-coins"></i> DI PARMA <span style="color:var(--muted);margin:0 4px">|</span> <span style="color:var(--gold);font-size:.85rem"><?=$ar?'الدفع':'Checkout'?></span></div>
  <div class="tb-nav">
    <a href="checkout_ledger.php"><i class="fas fa-wallet"></i> Ledger CHECKOUT</a>
    <a href="dashboard.php"><i class="fas fa-th-large"></i> <?=$ar?'لوحة التحكم':'Dashboard'?></a>
  </div>
</header>

<div class="wrap">
  <div class="page-title"><i class="fas fa-briefcase"></i> <?=$ar?'الدفع حسب النشاط':'Pay by activity'?></div>
  <div class="page-sub"><?=$ar?'نشاط الشركة → POS أو رابط → البوابة المتصلة → نوع العملية. المبلغ يبقى على نفس البوابة.':'Business activity → POS or Link → connected gateway → operation. Funds stay on the same gateway.'?></div>

  <!-- Steps Bar -->
  <div class="steps-bar">
    <div class="step-item active" id="step1-item">
      <div class="step-num">1</div>
      <div class="step-label"><?=$ar?'النشاط':'Activity'?></div>
    </div>
    <div class="step-sep"></div>
    <div class="step-item" id="step2-item">
      <div class="step-num">2</div>
      <div class="step-label"><?=$ar?'POS أو Checkout أو رابط':'POS, Checkout, or Link'?></div>
    </div>
    <div class="step-sep"></div>
    <div class="step-item" id="step3-item">
      <div class="step-num">3</div>
      <div class="step-label"><?=$ar?'البوابة':'Gateway'?></div>
    </div>
    <div class="step-sep"></div>
    <div class="step-item" id="step4-item">
      <div class="step-num">4</div>
      <div class="step-label"><?=$ar?'المبلغ والتفاصيل':'Amount & Details'?></div>
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
      <?=$ar?'التالي — القناة':'Next — Channel'?> <i class="fas fa-arrow-left"></i>
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
      <div style="font-size:.75rem;font-weight:800;color:var(--orange);margin-bottom:6px"><?=$ar?'نفس البوابة':'Same gateway'?></div>
      <div style="font-size:.7rem;color:var(--muted2);line-height:1.6"><?=$ar?'المبلغ يبقى على البوابة المستخدمة. للوصول إلى Ledger استخدم صفحة Ledger CHECKOUT — بوابة مستقلة.':'Funds stay on the gateway you use. To send to Ledger open Ledger CHECKOUT — a standalone gateway.'?></div>
      <div style="margin-top:10px"><a href="checkout_ledger.php" style="color:var(--gold);font-weight:800;text-decoration:none"><i class="fas fa-wallet"></i> Ledger CHECKOUT</a></div>
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
          ? 'لا توجد بوابة متصلة حالياً. تواصل مع الإدارة لتفعيل بوابة.'
          : 'No connected gateway yet. Ask an administrator to enable one.'?>
      </div>
      <?php if (function_exists('isAdmin') && isAdmin()): ?>
      <a href="admin/gateway_manager.php" style="display:inline-block;margin-top:14px;color:#000;background:var(--gold);padding:10px 16px;border-radius:10px;text-decoration:none;font-weight:800;font-size:.82rem">
        <?=$ar?'فتح إدارة البوابات':'Open Gateway Manager'?>
      </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php
    $types = [
      'card' => ($ar ? 'بطاقات' : 'Cards'),
      'bank' => ($ar ? 'تحويل بنكي' : 'Bank Transfer'),
      'crypto' => ($ar ? 'كريبتو' : 'Crypto'),
      'digital' => ($ar ? 'رقمي' : 'Digital'),
      'fulfillment' => ($ar ? 'خدمات Square Online' : 'Square Online Services'),
    ];
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

    <div class="section-title" style="font-size:.65rem;margin-top:18px;color:var(--muted)">— <?=$ar?'بوابة مستقلة':'Standalone'?> —</div>
    <div class="gw-grid">
      <a class="gw-card" href="checkout_ledger.php" style="text-decoration:none;color:inherit">
        <div class="gw-type-badge" style="background:rgba(16,185,129,.12);color:var(--green)">ledger</div>
        <div class="gw-icon" style="background:#10B98122;color:#10B981"><i class="fas fa-wallet"></i></div>
        <div class="gw-name">Ledger CHECKOUT</div>
        <div class="gw-desc"><?=$ar?'بوابة خاصة لوحدها — أضف بوابات الخصم ليصل الصافي إلى Ledger':'Its own gateway — attach charge gateways so the net arrives at Ledger'?></div>
      </a>
    </div>

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
      <div id="txnOpsWrap">
      <div class="section-title" style="margin-bottom:12px"><i class="fas fa-list"></i> <?=$ar?'نوع العملية':'Operation type'?></div>
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
      </div>

      <div class="fld" id="settlementTargetWrap" style="margin:0 0 16px">
        <label for="settlementTarget"><?=$ar?'وجهة المبلغ بعد نجاح الخصم':'Where funds go after the charge'?></label>
        <select id="settlementTarget" onchange="updateSettlementTargetHint()"></select>
        <div id="settlementTargetHint" style="font-size:.68rem;color:var(--muted2);margin-top:6px;line-height:1.55"></div>
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

      <div class="fld-row" id="txnAmountWrap">
        <div class="fld">
          <label><?=$ar?'المبلغ':'Amount'?></label>
          <input type="number" id="txnAmount" min="0.01" step="0.01" placeholder="0.00" oninput="updateSummary()">
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
            <option value="BHD">BHD</option>
            <option value="EGP">EGP</option>
            <option value="QAR">QAR</option>
            <option value="CAD">CAD</option>
            <option value="AUD">AUD</option>
            <option value="USDT">USDT</option>
          </select>
        </div>
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

</div>

<div id="toast"></div>

<script>
const AR   = <?=$ar?'true':'false'?>;
const STATE = {
  line: null,
  channel: null,
  gateway: null,
  amount: 0,
  currency: 'USD',
  txnType: 'purchase_3d',
};
const POS_GWS = <?=json_encode(array_keys($connectedPos), JSON_UNESCAPED_UNICODE)?>;
const CHECKOUT_GWS = <?=json_encode(array_keys($connectedCheckout), JSON_UNESCAPED_UNICODE)?>;
const LINK_GWS = <?=json_encode(array_keys($connectedLink), JSON_UNESCAPED_UNICODE)?>;

const GW_ROUTES = <?=json_encode($gatewayRoutes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)?>;
const POS_ROUTES = <?=json_encode($posRoutes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)?>;
const LINK_ROUTES = <?=json_encode($linkRoutes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)?>;
const GW_CURRENCIES = <?=json_encode($gatewayCurrencies, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)?>;
const GW_OPERATIONS = <?=json_encode($gatewayOperations, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)?>;
const SETTLEMENT_TARGETS = <?=json_encode($settlementTargets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)?>;

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
  const allow = code === 'pos' ? POS_GWS : (code === 'link' ? LINK_GWS : CHECKOUT_GWS);
  document.querySelectorAll('.gw-card[id^="gw-"]').forEach(card => {
    const id = (card.id || '').replace('gw-', '');
    card.style.display = allow.indexOf(id) >= 0 ? '' : 'none';
    card.classList.remove('selected');
  });
  STATE.gateway = null;
  const gwBtn = document.getElementById('btn-step3gw');
  if (gwBtn) gwBtn.disabled = true;
  configureStep4();
}
window.selectChannel = selectChannel;

function selectGateway(code, el) {
  STATE.gateway = code;
  document.querySelectorAll('.gw-card[id^="gw-"]').forEach(card => card.classList.remove('selected'));
  if (el) el.classList.add('selected');
  const btn = document.getElementById('btn-step3gw');
  if (btn) {
    btn.disabled = false;
    btn.innerHTML = STATE.channel === 'link'
      ? (AR ? 'التالي — تفاصيل الرابط <i class="fas fa-arrow-left"></i>' : 'Next — Link details <i class="fas fa-arrow-left"></i>')
      : (code === 'wise'
        ? (AR ? 'التالي — بيانات المستفيد <i class="fas fa-arrow-left"></i>' : 'Next — Recipient details <i class="fas fa-arrow-left"></i>')
        : (code === 'square_online'
          ? (AR ? 'التالي — الخدمات <i class="fas fa-arrow-left"></i>' : 'Next — Services <i class="fas fa-arrow-left"></i>')
          : (AR ? 'التالي — المبلغ والتفاصيل <i class="fas fa-arrow-left"></i>' : 'Next — Amount & details <i class="fas fa-arrow-left"></i>')));
  }
  const allowedOps = GW_OPERATIONS[code] || [];
  document.querySelectorAll('#txnTypeGrid > div').forEach(card => {
    const visible = allowedOps.includes((card.id || '').replace('rtt-', ''));
    card.style.display = visible ? '' : 'none';
  });
  if (allowedOps.length && !allowedOps.includes(STATE_TXN.type)) {
    const defaultOp = document.getElementById('rtt-' + allowedOps[0]);
    if (defaultOp) window.selectTxnTypeRouter(allowedOps[0], defaultOp);
  }
  applyCurrencyChoices(code);
  configureStep4();
}
window.selectGateway = selectGateway;

function applyCurrencyChoices(code) {
  const select = document.getElementById('txnCurrency');
  const allowed = GW_CURRENCIES[code] || ['USD'];
  if (!select) return;
  Array.from(select.options).forEach(option => {
    const enabled = allowed.includes(option.value);
    option.hidden = !enabled;
    option.disabled = !enabled;
  });
  if (!allowed.includes(select.value)) select.value = allowed[0] || 'USD';
}

function configureStep4() {
  const linkMode = STATE.channel === 'link';
  const wiseTransfer = STATE.channel === 'checkout' && STATE.gateway === 'wise';
  const fulfillment = STATE.channel === 'checkout' && STATE.gateway === 'square_online';
  const bankTransfer = STATE.channel === 'checkout' && ['mashreq', 'hsbc_uae', 'nbe_egypt', 'jpmorgan'].includes(STATE.gateway);
  const ops = document.getElementById('txnOpsWrap');
  const sec = document.getElementById('secModeWrap');
  const amount = document.getElementById('txnAmountWrap');
  const targetWrap = document.getElementById('settlementTargetWrap');
  const targetSelect = document.getElementById('settlementTarget');
  const next = document.getElementById('btn-step3');
  if (ops) ops.style.display = linkMode || wiseTransfer || fulfillment ? 'none' : '';
  if (sec) sec.style.display = linkMode || wiseTransfer || fulfillment || bankTransfer ? 'none' : '';
  if (amount) amount.style.display = fulfillment ? 'none' : '';
  if (targetWrap) targetWrap.style.display = wiseTransfer || fulfillment ? 'none' : '';
  if (targetSelect && targetWrap && targetWrap.style.display !== 'none') {
    const options = SETTLEMENT_TARGETS[STATE.channel]?.[STATE.gateway] || [];
    const sameSelection = targetSelect.dataset.source === (STATE.gateway || '')
      && targetSelect.dataset.channel === (STATE.channel || '');
    const current = sameSelection ? targetSelect.value : 'gateway';
    targetSelect.replaceChildren();
    options.forEach(target => {
      const option = document.createElement('option');
      option.value = target.code;
      option.textContent = AR ? target.ar : target.en;
      targetSelect.appendChild(option);
    });
    targetSelect.value = options.some(target => target.code === current) ? current : 'gateway';
    targetSelect.dataset.source = STATE.gateway || '';
    targetSelect.dataset.channel = STATE.channel || '';
  }
  updateSettlementTargetHint();
  if (next) {
    next.innerHTML = fulfillment
      ? (AR ? 'فتح خدمات Square Online <i class="fas fa-arrow-left"></i>' : 'Open Square Online services <i class="fas fa-arrow-left"></i>')
      : (AR ? 'متابعة <i class="fas fa-arrow-left"></i>' : 'Continue <i class="fas fa-arrow-left"></i>');
  }
  updateSummary();
}

function updateSettlementTargetHint() {
  const target = document.getElementById('settlementTarget')?.value || 'gateway';
  const hint = document.getElementById('settlementTargetHint');
  if (!hint) return;
  hint.textContent = target.startsWith('gateway:')
    ? (AR ? 'سيُسجل طلب تحويل من بوابة الخصم إلى البوابة المختارة؛ الإرسال الفعلي يحتاج تنفيذ التحويل لدى المزود.' : 'A transfer request will be queued from the charging gateway to the selected gateway; the provider payout must be completed separately.')
    : (target === 'ledger'
      ? (AR ? 'يُحوّل الصافي إلى Ledger عبر تسوية USDT بعد نجاح الخصم.' : 'Net proceeds are settled to Ledger in USDT after the charge succeeds.')
      : (AR ? 'يبقى المبلغ في حساب بوابة الخصم نفسها.' : 'Funds remain in the charging gateway account.'));
}

function goStep(n) {
  if (n === 2 && !STATE.line) {
    toast(AR ? 'اختر نشاط الشركة' : 'Select the business activity', 'error');
    return;
  }
  if (n === 3 && !STATE.channel) {
    toast(AR ? 'اختر القناة' : 'Select a channel', 'error');
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

  const secWrap = document.getElementById('secModeWrap');
  if (secWrap) secWrap.style.display = (type === 'purchase_3d' || type === 'purchase_2d' || type === 'purchase_moto') ? '' : 'none';
  if (type === 'purchase_2d' && typeof window.selectSecMode === 'function') {
    const sm2 = document.getElementById('smode-2D');
    if (sm2) window.selectSecMode('2D', sm2);
  }
  if (type === 'purchase_3d' && typeof window.selectSecMode === 'function') {
    const sm3 = document.getElementById('smode-3D');
    if (sm3) window.selectSecMode('3D', sm3);
  }
}
window.selectTxnTypeRouter = selectTxnTypeRouter;

function selectSecMode(mode, el) {
  STATE_TXN.secMode = mode;
  if (mode === '2D' && (STATE_TXN.type === 'purchase_3d' || STATE_TXN.type === 'purchase')) {
    STATE_TXN.type = 'purchase_2d';
  } else if (mode === '3D' && (STATE_TXN.type === 'purchase_2d' || STATE_TXN.type === 'purchase')) {
    STATE_TXN.type = 'purchase_3d';
  }
  const opEl = document.getElementById('rtt-' + STATE_TXN.type);
  if (opEl && (mode === '2D' || mode === '3D')) {
    document.querySelectorAll('#txnTypeGrid > div').forEach(d => {
      d.style.borderColor = 'var(--border)';
      d.style.background = 'rgba(255,255,255,.03)';
      const n = d.querySelector('.rtt-name');
      if (n) n.style.color = 'var(--muted2)';
    });
    opEl.style.borderColor = 'var(--gold)';
    opEl.style.background = 'rgba(255,215,0,.05)';
    const n = opEl.querySelector('.rtt-name');
    if (n) n.style.color = 'var(--gold)';
  }
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
  updateSummary();
});

function updateSummary() {
  const amt = parseFloat(document.getElementById('txnAmount').value) || 0;
  const step3 = document.getElementById('btn-step3');
  const needsAmount = !(STATE.channel === 'checkout' && STATE.gateway === 'square_online');
  if (step3) step3.disabled = needsAmount && amt <= 0;
}

window.proceedToCheckout = function() {
  if (!STATE.line || !STATE.channel || !STATE.gateway) {
    toast(AR ? 'أكمل النشاط والقناة والبوابة' : 'Complete activity, channel, and gateway', 'error');
    return;
  }
  STATE.txnType = STATE_TXN.type;
  STATE.amount = parseFloat(document.getElementById('txnAmount')?.value) || 0;
  STATE.currency = document.getElementById('txnCurrency')?.value || 'USD';
  const settlementTarget = document.getElementById('settlementTarget')?.value || 'gateway';

  if (STATE.channel === 'checkout' && STATE.gateway === 'square_online') {
    const serviceRoute = GW_ROUTES[STATE.gateway];
    if (serviceRoute) window.location.href = serviceRoute;
    else toast(AR ? 'صفحة الخدمات غير متاحة' : 'Services page is unavailable', 'error');
    return;
  }
  if (STATE.amount <= 0) {
    toast(AR ? 'أدخل مبلغاً أكبر من صفر' : 'Enter an amount greater than zero', 'error');
    return;
  }

  if (STATE.channel === 'pos') {
    const q = new URLSearchParams({
      kiosk: '1',
      gw: STATE.gateway,
      line: STATE.line,
      op: STATE.txnType,
      amount: String(STATE.amount),
      currency: STATE.currency,
      settlement_target: settlementTarget
    });
    const routerDevice = <?= json_encode(strtolower(preg_replace('/[^a-z0-9_]/', '', (string) ($_GET['device'] ?? $_COOKIE['di_parma_pos_model'] ?? ''))), JSON_UNESCAPED_UNICODE) ?>;
    if (routerDevice) q.set('device', routerDevice);
    const routerTidRaw = <?= json_encode(strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', (string) ($_GET['tid'] ?? ''))), JSON_UNESCAPED_UNICODE) ?>;
    const routerTid = routerTidRaw;
    if (routerTid) q.set('tid', routerTid);
    window.location.href = (POS_ROUTES[STATE.gateway] || 'pos/index.php') + '?' + q.toString();
    return;
  }

  if (STATE.channel === 'link') {
    const q = new URLSearchParams({
      gateway: STATE.gateway,
      amount: String(STATE.amount || 0),
      currency: STATE.currency,
      line: STATE.line,
      settlement_target: settlementTarget
    });
    window.location.href = (LINK_ROUTES[STATE.gateway] || 'links.php') + '?' + q.toString();
    return;
  }

  const route = GW_ROUTES[STATE.gateway];
  if (!route) {
    toast(AR ? 'صفحة البوابة غير متاحة بعد' : 'Gateway page not available yet', 'error');
    return;
  }
  const params = new URLSearchParams({
    gateway: STATE.gateway,
    destination: settlementTarget,
    settlement_target: settlementTarget,
    channel: 'checkout',
    amount: String(STATE.amount || 0),
    currency: STATE.currency,
    txn_type: STATE.txnType,
    op: STATE.txnType,
    line: STATE.line,
    sec_mode: STATE_TXN.secMode
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
