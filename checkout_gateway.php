<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'ar' ? 'ar' : 'en';
$ar = $lang === 'ar'; $dir = $ar ? 'rtl' : 'ltr';

// جلب البوابات الفعّالة
$db = db();
$rows = $db->query("SELECT code, name, type, gateway_type, section FROM dp_payment_gateways WHERE status='active' AND connection_status='verified' ORDER BY sort_order ASC, id ASC");

// خريطة البوابة → الصفحة
$gwPages = [
    'stripe'     => 'checkout_stripe.php',
    'paypal'     => 'checkout_paypal.php',
    'wise'       => 'checkout_wise.php',
    'myfatoorah' => 'checkout_myfatoorah.php',
    'binance'    => 'checkout_binance.php',
    'gate_io'    => 'checkout_gate.php',
    'whop'       => 'checkout_whop.php',
    'nuvei'      => 'checkout_nuvei.php',
    'hsbc_uae'   => 'checkout_HSBCbankUUUAE.php',
    'nbe_egypt'  => 'checkout_NBEbank.php',
];

// أيقونة ولون لكل بوابة
$gwMeta = [
    'stripe'     => ['icon'=>'fab fa-stripe-s',         'color'=>'#6772e5', 'label'=>'Stripe'],
    'paypal'     => ['icon'=>'fab fa-paypal',            'color'=>'#0070ba', 'label'=>'PayPal'],
    'wise'       => ['icon'=>'fas fa-paper-plane',       'color'=>'#00B9FF', 'label'=>'Wise'],
    'myfatoorah' => ['icon'=>'fas fa-money-bill-wave',   'color'=>'#00b09b', 'label'=>'MyFatoorah'],
    'binance'    => ['icon'=>'fas fa-coins',             'color'=>'#F0B90B', 'label'=>'Binance Pay'],
    'gate_io'    => ['icon'=>'fas fa-door-open',         'color'=>'#e8112d', 'label'=>'Gate.io'],
    'whop'       => ['icon'=>'fas fa-bolt',              'color'=>'#4F46E5', 'label'=>'Whop'],
    'nuvei'      => ['icon'=>'fas fa-credit-card',       'color'=>'#0A5EB0', 'label'=>'Nuvei'],
    'hsbc_uae'   => ['icon'=>'fas fa-university',        'color'=>'#DB0011', 'label'=>'HSBC UAE'],
    'nbe_egypt'  => ['icon'=>'fas fa-landmark',          'color'=>'#006633', 'label'=>'NBE Egypt'],
];

$gateways = [];
foreach ($rows as $row) {
    $code = $row['code'];
    if (isset($gwPages[$code])) {
        $gateways[] = array_merge($row, $gwMeta[$code] ?? ['icon'=>'fas fa-credit-card','color'=>'var(--gold)','label'=>$row['name']]);
    }
}
?><!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$dir?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | اختر بوابة الدفع</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--gold:#FFD700;--bg:#040810;--card:#080d1a;--border:rgba(255,215,0,.15);--text:#f0f0f0;--muted:#666;--green:#10B981}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.top-bar{background:rgba(4,8,16,.97);border-bottom:1px solid var(--border);padding:0 28px;height:62px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:99}
.brand{color:var(--gold);font-weight:900;font-size:1.05rem;display:flex;align-items:center;gap:8px}
.top-nav a{color:var(--muted);font-size:.82rem;padding:6px 14px;border-radius:20px;text-decoration:none;transition:.2s}
.top-nav a:hover{color:var(--gold)}
.wrap{max-width:900px;margin:40px auto;padding:0 20px}
.page-title{text-align:center;margin-bottom:32px}
.page-title h1{font-size:1.6rem;font-weight:900;color:var(--gold);margin-bottom:6px}
.page-title p{color:var(--muted);font-size:.88rem}
/* ── تصنيفات ── */
.section-label{font-size:.78rem;color:var(--muted);font-weight:700;margin:24px 0 10px;display:flex;align-items:center;gap:8px;text-transform:uppercase;letter-spacing:.5px}
.section-label::after{content:'';flex:1;height:1px;background:var(--border)}
/* ── بطاقات البوابات ── */
.gw-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;margin-bottom:8px}
.gw-card{background:var(--card);border:2px solid var(--border);border-radius:16px;padding:22px 16px;text-align:center;cursor:pointer;transition:.25s;position:relative;overflow:hidden}
.gw-card:hover{transform:translateY(-4px);border-color:rgba(255,215,0,.4);box-shadow:0 8px 32px rgba(0,0,0,.4)}
.gw-card.active{border-color:var(--gw-color,var(--gold));background:rgba(255,215,0,.04)}
.gw-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--gw-color,var(--gold));opacity:0;transition:.25s}
.gw-card:hover::before,.gw-card.active::before{opacity:1}
.gw-icon{font-size:2rem;margin-bottom:10px;display:block}
.gw-name{font-size:.82rem;font-weight:800;color:var(--text);margin-bottom:4px}
.gw-type{font-size:.67rem;color:var(--muted);background:rgba(255,255,255,.05);border-radius:20px;padding:2px 10px;display:inline-block}
.gw-badge{position:absolute;top:8px;right:8px;background:rgba(16,185,129,.15);border:1px solid var(--green);border-radius:20px;padding:2px 7px;font-size:.6rem;color:var(--green);font-weight:700}
/* ── زر التالي ── */
.next-btn{display:block;width:100%;max-width:400px;margin:32px auto 0;padding:15px;border-radius:14px;border:none;cursor:pointer;font-family:'Cairo',sans-serif;font-weight:900;font-size:1rem;background:linear-gradient(135deg,#b8960a,var(--gold));color:#000;transition:.3s;text-align:center}
.next-btn:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(255,215,0,.2)}
.next-btn:disabled{opacity:.4;cursor:not-allowed;transform:none}
.hint{text-align:center;margin-top:10px;font-size:.74rem;color:var(--muted)}
/* ── empty state ── */
.empty{text-align:center;padding:60px 20px;color:var(--muted)}
.empty i{font-size:3rem;margin-bottom:16px;display:block;color:rgba(255,215,0,.2)}
@media(max-width:600px){.gw-grid{grid-template-columns:repeat(2,1fr)}}
</style>

<nav class="top-bar">
  <div class="brand"><i class="fas fa-coins"></i> DI PARMA</div>
  <div class="top-nav">
    <a href="dashboard.php"><i class="fas fa-th-large"></i> <?=$ar?'لوحة التحكم':'Dashboard'?></a>
    <a href="history.php"><i class="fas fa-history"></i> <?=$ar?'السجل':'History'?></a>
  </div>
</nav>

<div class="wrap">
  <div class="page-title">
    <h1><i class="fas fa-shield-alt"></i> <?=$ar?'اختر بوابة الدفع':'Select Payment Gateway'?></h1>
    <p><?=$ar?'كل بوابة لها صفحة دفع مخصصة بجميع أنواع المعاملات':'Each gateway has a dedicated checkout with full transaction types'?></p>
  </div>

<?php if (empty($gateways)): ?>
  <div class="empty">
    <i class="fas fa-plug"></i>
    <p><?=$ar?'لا توجد بوابات مفعّلة حالياً':'No active gateways at the moment'?></p>
  </div>
<?php else: ?>

  <?php
  // تصنيف البوابات
  $bankGateways  = array_filter($gateways, fn($g) => in_array($g['code'], ['hsbc_uae','nbe_egypt','wise']));
  $cardGateways  = array_filter($gateways, fn($g) => in_array($g['code'], ['stripe','paypal','myfatoorah','nuvei']));
  $cryptoGateways= array_filter($gateways, fn($g) => in_array($g['code'], ['binance','gate_io','whop']));
  ?>

  <?php if (!empty($cardGateways)): ?>
  <div class="section-label"><i class="fas fa-credit-card" style="color:#6772e5"></i> <?=$ar?'بوابات البطاقات':'Card Gateways'?></div>
  <div class="gw-grid">
    <?php foreach ($cardGateways as $gw): ?>
    <div class="gw-card" id="card_<?=$gw['code']?>" style="--gw-color:<?=$gw['color']?>"
         onclick="selectGW('<?=$gw['code']?>','<?=$gwPages[$gw['code']]?>','<?=$gw['color']?>')">
      <span class="gw-badge">✓ Live</span>
      <i class="gw-icon <?=$gw['icon']?>" style="color:<?=$gw['color']?>"></i>
      <div class="gw-name"><?=htmlspecialchars($gw['label'] ?? $gw['name'])?></div>
      <span class="gw-type">Card</span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($bankGateways)): ?>
  <div class="section-label"><i class="fas fa-university" style="color:#DB0011"></i> <?=$ar?'التحويلات البنكية':'Bank Transfers'?></div>
  <div class="gw-grid">
    <?php foreach ($bankGateways as $gw): ?>
    <div class="gw-card" id="card_<?=$gw['code']?>" style="--gw-color:<?=$gw['color']?>"
         onclick="selectGW('<?=$gw['code']?>','<?=$gwPages[$gw['code']]?>','<?=$gw['color']?>')">
      <span class="gw-badge">✓ Live</span>
      <i class="gw-icon <?=$gw['icon']?>" style="color:<?=$gw['color']?>"></i>
      <div class="gw-name"><?=htmlspecialchars($gw['label'] ?? $gw['name'])?></div>
      <span class="gw-type">Bank</span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($cryptoGateways)): ?>
  <div class="section-label"><i class="fab fa-bitcoin" style="color:#F0B90B"></i> <?=$ar?'بوابات العملات الرقمية':'Crypto Gateways'?></div>
  <div class="gw-grid">
    <?php foreach ($cryptoGateways as $gw): ?>
    <div class="gw-card" id="card_<?=$gw['code']?>" style="--gw-color:<?=$gw['color']?>"
         onclick="selectGW('<?=$gw['code']?>','<?=$gwPages[$gw['code']]?>','<?=$gw['color']?>')">
      <span class="gw-badge">✓ Live</span>
      <i class="gw-icon <?=$gw['icon']?>" style="color:<?=$gw['color']?>"></i>
      <div class="gw-name"><?=htmlspecialchars($gw['label'] ?? $gw['name'])?></div>
      <span class="gw-type">Crypto</span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <button class="next-btn" id="nextBtn" disabled onclick="goToGateway()">
    <i class="fas fa-arrow-<?=$ar?'left':'right'?>"></i>
    <?=$ar?'انتقل لصفحة الدفع':'Go to Checkout'?>
  </button>
  <p class="hint" id="hintText"><?=$ar?'اختر بوابة للمتابعة':'Select a gateway to continue'?></p>

<?php endif; ?>
</div>

<script>
var selectedCode = null;
var selectedPage = null;

function selectGW(code, page, color) {
  // إزالة التحديد السابق
  document.querySelectorAll('.gw-card').forEach(function(c) {
    c.classList.remove('active');
    c.style.boxShadow = '';
  });

  selectedCode = code;
  selectedPage = page;

  var card = document.getElementById('card_' + code);
  if (card) {
    card.classList.add('active');
    card.style.boxShadow = '0 0 0 3px ' + color + '44, 0 8px 32px rgba(0,0,0,.5)';
  }

  var btn = document.getElementById('nextBtn');
  btn.disabled = false;
  btn.style.background = 'linear-gradient(135deg,' + color + 'cc,' + color + ')';

  var hint = document.getElementById('hintText');
  hint.innerHTML = '<i class="fas fa-check-circle" style="color:#10B981"></i> <?=$ar?"تم اختيار":"Selected:"?> <strong style="color:' + color + '">' + code.replace('_',' ').toUpperCase() + '</strong>';
}

function goToGateway() {
  if (!selectedPage) return;
  window.location.href = selectedPage;
}

// دعم الكيبورد Enter
document.addEventListener('keydown', function(e) {
  if (e.key === 'Enter' && selectedPage) goToGateway();
});
</script>
</body>
</html>
