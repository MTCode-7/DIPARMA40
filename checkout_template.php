<?php
/**
 * DI PARMA | Unified Checkout Template
 * يُستدعى من كل checkout_*.php بعد تعريف المتغيرات:
 * $gwCode, $gwName, $gwColor, $gwIcon, $gwLabel, $currencies[], $csrfToken
 */
if (!defined('DI_PARMA_CHECKOUT')) {
    header('Location: checkout_router.php'); exit;
}
$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'ar' ? 'ar' : 'en';
$ar   = ($lang === 'ar');
$dir  = $ar ? 'rtl' : 'ltr';
$currencyOptions = '';
foreach (($currencies ?? ['USD','EUR','GBP','AED']) as $cur) {
    $currencyOptions .= "<option value=\"{$cur}\">{$cur}</option>";
}
$motoCurrencyOptions = '';
foreach (($currencies ?? ['USD','EUR','GBP','AED']) as $cur) {
    $motoCurrencyOptions .= "<option value=\"{$cur}\">{$cur}</option>";
}
?><!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$dir?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | <?=htmlspecialchars($gwName)?></title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<?php if (!empty($stripeKey)): ?><script src="https://js.stripe.com/v3/"></script><?php endif; ?>
</head>
<body>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{
  --gold:#FFD700;--bg:#040810;--card:#080d1a;--border:rgba(255,215,0,.15);
  --text:#f0f0f0;--muted:#666;--green:#10B981;--red:#EF4444;
  --gw:<?=htmlspecialchars($gwColor ?? '#FFD700')?>;
}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
/* ── Top Bar ── */
.top-bar{background:rgba(4,8,16,.97);border-bottom:1px solid var(--border);padding:0 24px;height:62px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:99}
.top-brand{color:var(--gold);font-weight:900;font-size:1rem}
.gw-badge{background:color-mix(in srgb,var(--gw) 15%,transparent);border:2px solid var(--gw);border-radius:12px;padding:6px 18px;color:var(--gw);font-weight:800;font-size:.85rem;display:flex;align-items:center;gap:8px}
.top-nav a{color:var(--muted);font-size:.82rem;padding:6px 13px;border-radius:20px;text-decoration:none;transition:.2s}
.top-nav a:hover{color:var(--gold)}
.back-link{display:inline-flex;align-items:center;gap:6px;color:var(--muted);font-size:.8rem;text-decoration:none;margin-bottom:14px}
.back-link:hover{color:var(--gold)}
/* ── Layout ── */
.wrap{max-width:1060px;margin:0 auto;padding:18px 20px;display:grid;grid-template-columns:1fr 330px;gap:18px}
.co-card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:22px;margin-bottom:14px}
.co-title{font-size:.9rem;font-weight:800;margin-bottom:14px;display:flex;align-items:center;gap:8px}
/* ── Transaction Type ── */
.tx-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:6px;margin-bottom:10px}
.tx-btn{background:rgba(255,255,255,.04);border:1.5px solid rgba(255,215,0,.12);border-radius:10px;padding:9px 3px;text-align:center;cursor:pointer;transition:.2s;user-select:none}
.tx-btn:hover{border-color:rgba(255,215,0,.3)}
.tx-btn.active{border-color:var(--gw);background:color-mix(in srgb,var(--gw) 10%,transparent)}
.tx-btn i{display:block;font-size:.95rem;margin-bottom:3px}
.tx-btn span{font-size:.58rem;font-weight:700;display:block;line-height:1.3;color:var(--text)}
/* ── Security ── */
.sec-grid{display:grid;grid-template-columns:1fr 1fr;gap:9px}
.sec-btn{background:rgba(255,255,255,.04);border:1.5px solid rgba(255,215,0,.12);border-radius:11px;padding:11px;cursor:pointer;transition:.2s;display:flex;align-items:center;gap:9px}
.sec-btn.active{border-color:var(--gold);background:rgba(255,215,0,.06)}
.sec-label{font-size:.77rem;font-weight:800}
.sec-sub{font-size:.63rem;color:var(--muted)}
/* ── Fields ── */
.fld{margin-bottom:11px}
.fld label{display:block;font-size:.73rem;color:var(--muted);margin-bottom:4px;font-weight:700}
.fld label .req{color:var(--red);margin-<?=$ar?'right':'left'?>:3px}
.fld label .opt{color:var(--muted);font-size:.63rem;font-weight:400}
.fld input,.fld select{width:100%;background:rgba(255,255,255,.04);border:1.5px solid var(--border);border-radius:10px;padding:10px 13px;color:var(--text);font-family:'Cairo',sans-serif;font-size:.86rem;transition:.2s}
.fld input:focus,.fld select:focus{outline:none;border-color:var(--gw);background:rgba(255,255,255,.06)}
.fld-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.fld-row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
/* ── Capture Required Box ── */
.req-box{background:rgba(255,165,0,.05);border:1.5px solid rgba(255,165,0,.2);border-radius:12px;padding:14px;margin-bottom:12px}
.req-box-title{font-size:.76rem;font-weight:800;color:#f0ad4e;margin-bottom:10px;display:flex;align-items:center;gap:6px}
/* ── Auth Optional Box ── */
.opt-box{background:rgba(255,255,255,.02);border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:14px;margin-top:8px}
.opt-box-title{font-size:.74rem;font-weight:800;color:#888;margin-bottom:10px;display:flex;align-items:center;gap:6px}
/* ── Info Note ── */
.info-note{background:color-mix(in srgb,var(--gw) 6%,transparent);border:1px solid color-mix(in srgb,var(--gw) 20%,transparent);border-radius:10px;padding:10px 12px;font-size:.75rem;color:#aaa;margin-bottom:10px}
/* ── Summary ── */
.sum-row{display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:.82rem}
.sum-row:last-child{border:none;font-weight:800;font-size:.9rem;color:var(--gold);padding-top:9px}
.sum-key{color:var(--muted)}
/* ── Pay Button ── */
.pay-btn{width:100%;padding:13px;border-radius:12px;border:none;cursor:pointer;font-family:'Cairo',sans-serif;font-weight:800;font-size:.93rem;background:linear-gradient(135deg,color-mix(in srgb,var(--gw) 80%,#000),var(--gw));color:#fff;transition:.3s;margin-top:11px;display:flex;align-items:center;justify-content:center;gap:8px}
.pay-btn:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 6px 20px color-mix(in srgb,var(--gw) 30%,transparent)}
.pay-btn:disabled{opacity:.45;cursor:not-allowed;transform:none}
.hidden{display:none!important}
/* ── Toast ── */
#toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(90px);background:var(--card);border:1px solid var(--gold);border-radius:14px;padding:12px 26px;font-size:.85rem;font-weight:700;z-index:9999;transition:.35s;color:var(--text);white-space:nowrap}
/* ── Responsive ── */
@media(max-width:768px){
  .wrap{grid-template-columns:1fr}
  .tx-grid{grid-template-columns:repeat(4,1fr)}
  .fld-row,.fld-row3{grid-template-columns:1fr}
}
</style>

<!-- ══ Top Bar ══ -->
<nav class="top-bar">
  <div class="top-brand"><i class="fas fa-coins"></i> DI PARMA</div>
  <div style="display:flex;align-items:center;gap:10px">
    <div class="gw-badge">
      <i class="<?=htmlspecialchars($gwIcon ?? 'fas fa-credit-card')?>"></i>
      <?=htmlspecialchars($gwName)?>
    </div>
    <div class="top-nav">
      <a href="dashboard.php"><i class="fas fa-th-large"></i></a>
      <a href="checkout_router.php"><i class="fas fa-exchange-alt"></i></a>
    </div>
  </div>
</nav>

<div style="max-width:1060px;margin:14px auto;padding:0 20px">
  <a href="checkout_router.php" class="back-link">
    <i class="fas fa-arrow-<?=$ar?'right':'left'?>"></i>
    <?=$ar?'رجوع لاختيار البوابة':'Back to Gateway Selection'?>
  </a>
</div>

<div class="wrap">
<div>

<!-- ══ 1. Transaction Type ══ -->
<div class="co-card">
  <div class="co-title"><i class="fas fa-sliders-h" style="color:var(--gold)"></i>
    <?=$ar?'نوع المعاملة':'Transaction Type'?>
  </div>
  <div class="tx-grid">
    <div class="tx-btn active" id="tx_direct2d" onclick="setTx('direct2d',this)">
      <i class="fas fa-bolt" style="color:#FFD700"></i>
      <span><?=$ar?'سحب<br>2D':'Direct<br>2D'?></span>
    </div>
    <div class="tx-btn" id="tx_direct3d" onclick="setTx('direct3d',this)">
      <i class="fas fa-shield-alt" style="color:#5bc0de"></i>
      <span><?=$ar?'سحب<br>3D':'Direct<br>3D'?></span>
    </div>
    <div class="tx-btn" id="tx_capture" onclick="setTx('capture',this)">
      <i class="fas fa-check-double" style="color:#9fe870"></i>
      <span><?=$ar?'تسوية':'Capture'?></span>
    </div>
    <div class="tx-btn" id="tx_online_moto" onclick="setTx('online_moto',this)">
      <i class="fas fa-globe" style="color:#00B9FF"></i>
      <span>Online<br>MOTO</span>
    </div>
    <div class="tx-btn" id="tx_refund" onclick="setTx('refund',this)">
      <i class="fas fa-undo" style="color:#f0ad4e"></i>
      <span>Refund</span>
    </div>
    <div class="tx-btn" id="tx_avoid" onclick="setTx('avoid',this)">
      <i class="fas fa-ban" style="color:#888"></i>
      <span>Avoid</span>
    </div>
  </div>
  <div id="txDesc" class="info-note">
    <i class="fas fa-bolt" style="color:#FFD700"></i>
    <?=$ar?'سحب مباشر 2D — تحصيل فوري بدون OTP':'Direct Charge 2D — instant debit, no OTP'?>
  </div>
</div>

<!-- ══ 2. Card Section (direct2d / direct3d / online_moto) ══ -->
<div class="co-card hidden" id="cardSection">
  <div class="co-title">
    <i class="fas fa-credit-card" style="color:var(--gw)"></i>
    <?=$ar?'بيانات البطاقة':'Card Details'?>
    <span id="modeLabel" style="font-size:.72rem;color:var(--muted);font-weight:600;margin-<?=$ar?'right':'left'?>:auto"></span>
  </div>
  <div class="fld-row">
    <div class="fld">
      <label><?=$ar?'المبلغ':'Amount'?> <span class="req">*</span></label>
      <input type="number" id="cardAmt" min="1" step="0.01" placeholder="0.00" oninput="calcP()">
    </div>
    <div class="fld">
      <label><?=$ar?'العملة':'Currency'?></label>
      <select id="cardCur" onchange="calcP()"><?=$currencyOptions?></select>
    </div>
  </div>
  <div class="fld">
    <label><?=$ar?'رقم البطاقة':'Card Number'?> <span class="req">*</span></label>
    <input type="text" id="ccNumber" maxlength="19" placeholder="0000 0000 0000 0000" oninput="fmtCard(this)" style="font-family:monospace;letter-spacing:1px">
  </div>
  <div class="fld-row3">
    <div class="fld">
      <label><?=$ar?'تاريخ الانتهاء':'Expiry'?> <span class="opt">(<?=$ar?'اختياري':'opt'?>)</span></label>
      <input type="text" id="ccExpiry" maxlength="5" placeholder="MM/YY" oninput="fmtExp(this)">
    </div>
    <div class="fld">
      <label>CVV2 / CVC2 <span class="opt">(<?=$ar?'اختياري':'opt'?>)</span></label>
      <input type="password" id="ccCvv" maxlength="4" placeholder="•••">
    </div>
    <div class="fld">
      <label><?=$ar?'اسم حامل البطاقة':'Cardholder Name'?> <span class="opt">(<?=$ar?'اختياري':'opt'?>)</span></label>
      <input type="text" id="cardName" placeholder="<?=$ar?'الاسم كما في البطاقة':'Name as on card'?>">
    </div>
  </div>
  <div class="fld-row">
    <div class="fld">
      <label>Email <span class="opt">(<?=$ar?'اختياري':'opt'?>)</span></label>
      <input type="email" id="cardEmail" placeholder="example@email.com">
    </div>
    <div class="fld">
      <label><?=$ar?'الهاتف':'Phone'?> <span class="opt">(<?=$ar?'اختياري':'opt'?>)</span></label>
      <input type="tel" id="cardPhone" placeholder="+971 XX XXX XXXX">
    </div>
  </div>
  <!-- Stripe 3D element placeholder -->
  <?php if (!empty($stripeKey)): ?>
  <div id="stripeWrap" class="hidden">
    <div class="fld">
      <label><i class="fab fa-stripe-s" style="color:#6772e5"></i> 3D Secure Card</label>
      <div id="stripe-card-element" style="padding:11px 13px;background:rgba(255,255,255,.04);border:1.5px solid var(--border);border-radius:10px;min-height:42px"></div>
      <div id="stripe-error" style="color:var(--red);font-size:.73rem;margin-top:4px"></div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- ══ 3. Capture / Offline MOTO Section ══ -->
<div class="co-card hidden" id="captureSection">
  <div class="co-title">
    <i class="fas fa-check-double" style="color:#9fe870"></i>
    <?=$ar?'بيانات التسوية / MOTO':'Capture / MOTO Details'?>
  </div>

  <!-- إلزامي -->
  <div class="req-box">
    <div class="req-box-title">
      <i class="fas fa-exclamation-circle"></i>
      <?=$ar?'إلزامي — مطلوب للتنفيذ':'Required — mandatory for execution'?>
    </div>
    <div class="fld-row">
      <div class="fld">
        <label style="color:#f0ad4e">RRN <span class="req">*</span></label>
        <input type="text" id="rrnInput" placeholder="<?=$ar?'12 رقم':'12 digits'?>" maxlength="12"
               oninput="this.value=this.value.replace(/[^0-9]/g,'')" style="font-family:monospace;letter-spacing:1px">
      </div>
      <div class="fld">
        <label style="color:#f0ad4e">Approval Code <span class="req">*</span></label>
        <input type="text" id="approvalInput" placeholder="4–6 digits" maxlength="6"
               oninput="this.value=this.value.replace(/[^0-9A-Za-z]/g,'')" style="font-family:monospace">
      </div>
    </div>
    <div class="fld-row">
      <div class="fld">
        <label><?=$ar?'المبلغ':'Amount'?> <span class="req">*</span></label>
        <input type="number" id="captureAmt" min="1" step="0.01" placeholder="0.00" oninput="calcP()">
      </div>
      <div class="fld">
        <label><?=$ar?'العملة':'Currency'?></label>
        <select id="captureCur" onchange="calcP()"><?=$motoCurrencyOptions?></select>
      </div>
    </div>
  </div>

  <!-- اختياري للمصادقة -->
  <div class="opt-box">
    <div class="opt-box-title">
      <i class="fas fa-lock-open" style="color:#888"></i>
      <?=$ar?'اختياري — للمصادقة فقط (يُرسل مشفّراً)':'Optional — for authentication only (sent encrypted)'?>
    </div>
    <div class="fld">
      <label><?=$ar?'رقم البطاقة':'Card Number'?> <span class="opt">(<?=$ar?'اختياري':'opt'?>)</span></label>
      <input type="text" id="motoCardNum" maxlength="19" placeholder="0000 0000 0000 0000"
             oninput="fmtCard(this)" style="font-family:monospace;letter-spacing:1px">
    </div>
    <div class="fld-row3">
      <div class="fld">
        <label><?=$ar?'اسم حامل البطاقة':'Cardholder Name'?> <span class="opt">(<?=$ar?'اختياري':'opt'?>)</span></label>
        <input type="text" id="motoName" placeholder="<?=$ar?'الاسم كما في البطاقة':'Name as on card'?>">
      </div>
      <div class="fld">
        <label><?=$ar?'تاريخ الانتهاء':'Expiry'?> <span class="opt">(<?=$ar?'اختياري':'opt'?>)</span></label>
        <input type="text" id="motoExpiry" maxlength="5" placeholder="MM/YY" oninput="fmtExp(this)">
      </div>
      <div class="fld">
        <label>CVV2 / CVC / CVV <span class="opt">(<?=$ar?'اختياري':'opt'?>)</span></label>
        <input type="password" id="motoCvv" maxlength="4" placeholder="•••">
      </div>
    </div>
    <div class="fld-row">
      <div class="fld">
        <label>Email <span class="opt">(<?=$ar?'اختياري':'opt'?>)</span></label>
        <input type="email" id="motoEmail" placeholder="example@email.com">
      </div>
      <div class="fld">
        <label><?=$ar?'الهاتف':'Phone'?> <span class="opt">(<?=$ar?'اختياري':'opt'?>)</span></label>
        <input type="tel" id="motoPhone" placeholder="+971 XX XXX XXXX">
      </div>
    </div>
  </div>
</div>

<!-- ══ 4. Refund / Avoid Section ══ -->
<div class="co-card hidden" id="refSection">
  <div class="co-title">
    <i class="fas fa-undo" style="color:#f0ad4e"></i>
    <?=$ar?'مرجع المعاملة':'Transaction Reference'?>
  </div>
  <div class="fld">
    <label><?=$ar?'معرّف المعاملة الأصلية':'Original Transaction ID'?> <span class="req">*</span></label>
    <input type="text" id="refInput" placeholder="ORD... / TXN... / pi_...">
  </div>
  <div class="fld-row" id="refAmtRow">
    <div class="fld">
      <label><?=$ar?'مبلغ الاسترداد':'Refund Amount'?> <span class="opt">(<?=$ar?'اختياري — كامل إذا فارغ':'opt — full if empty'?>)</span></label>
      <input type="number" id="refAmt" min="0.01" step="0.01" placeholder="0.00" oninput="calcP()">
    </div>
    <div class="fld">
      <label><?=$ar?'العملة':'Currency'?></label>
      <select id="refCur" onchange="calcP()"><?=$currencyOptions?></select>
    </div>
  </div>
</div>

<!-- ══ Bank Info (إن وُجدت) ══ -->
<?php if (!empty($bankInfo)): ?>
<div class="co-card">
  <div class="co-title">
    <i class="fas fa-university" style="color:var(--gw)"></i>
    <?=$ar?'معلومات الحساب البنكي':'Bank Account Information'?>
  </div>
  <?php foreach ($bankInfo as $key => $val): ?>
  <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:.84rem">
    <span style="color:var(--muted);font-size:.76rem"><?=htmlspecialchars($key)?></span>
    <span style="font-weight:700;display:flex;align-items:center;gap:8px">
      <?=htmlspecialchars($val)?>
      <button onclick="copyText('<?=htmlspecialchars($val)?>','<?=$ar?'نُسخ':'Copied'?>')"
              style="background:rgba(255,215,0,.1);border:1px solid rgba(255,215,0,.2);border-radius:7px;padding:2px 9px;cursor:pointer;font-size:.68rem;color:var(--gold)">
        <i class="fas fa-copy"></i>
      </button>
    </span>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

</div><!-- /col-left -->

<!-- ══ Summary Sidebar ══ -->
<div>
  <div class="co-card" style="position:sticky;top:76px">
    <div class="co-title"><i class="fas fa-receipt" style="color:var(--gold)"></i> Summary</div>
    <div class="sum-row">
      <span class="sum-key">Gateway</span>
      <span style="color:var(--gw)"><?=htmlspecialchars($gwName)?></span>
    </div>
    <div class="sum-row">
      <span class="sum-key"><?=$ar?'النوع':'Type'?></span>
      <span id="sumType" style="color:var(--gold)"><?=$ar?'سحب مباشر 2D':'Direct 2D'?></span>
    </div>
    <div class="sum-row">
      <span class="sum-key"><?=$ar?'المبلغ':'Amount'?></span>
      <span id="sumAmt">—</span>
    </div>
    <div class="sum-row">
      <span class="sum-key">Fee (10%)</span>
      <span id="sumFee" style="color:var(--red)">—</span>
    </div>
    <div class="sum-row">
      <span class="sum-key">Net</span>
      <span id="sumNet" style="color:var(--green)">—</span>
    </div>
    <button class="pay-btn" id="payBtn" onclick="go()">
      <i class="fas fa-bolt"></i>
      <span id="payBtnLabel"><?=$ar?'سحب مباشر':'Direct Charge'?></span>
    </button>
    <div style="text-align:center;margin-top:10px;font-size:.68rem;color:var(--muted)">
      <i class="fas fa-shield-alt" style="color:var(--green)"></i>
      <?=$ar?'محمي بتشفير TLS 1.3':'Protected by TLS 1.3 encryption'?>
    </div>
  </div>
</div>
</div><!-- /wrap -->

<div id="toast"></div>

<script>
var CSRF = '<?=htmlspecialchars($csrfToken)?>';
var GW   = '<?=htmlspecialchars($gwCode)?>';
var curTx = 'direct2d';
var stripe = null, stripeEl = null;
<?php if (!empty($stripeKey)): ?>
stripe = Stripe('<?=addslashes($stripeKey)?>');
<?php endif; ?>

var TX_LABELS = {
  direct2d:    '<?=$ar?'سحب مباشر 2D':'Direct Charge 2D'?>',
  direct3d:    '<?=$ar?'سحب مباشر 3D':'Direct Charge 3D'?>',
  capture:     '<?=$ar?'تسوية':'Capture'?>',
  online_moto: 'Online MOTO',
  offline_moto:'Offline MOTO',
  refund:      'Refund',
  avoid:       'Avoid'
};
var TX_DESC = {
  direct2d:    '<?=$ar?'سحب مباشر 2D — تحصيل فوري بدون OTP':'Direct 2D — instant charge, no OTP'?>',
  direct3d:    '<?=$ar?'سحب مباشر 3D — تحصيل فوري مع OTP':'Direct 3D — instant charge with OTP verification'?>',
  capture:     '<?=$ar?'تسوية — تحصيل بعد التفويض (RRN + Approval Code)':'Capture — settle after authorization (RRN + Approval Code)'?>',
  online_moto: '<?=$ar?'Online MOTO — بطاقة عبر الإنترنت/الهاتف بدون 3D':'Online MOTO — card via phone/internet, no 3D'?>',
  offline_moto:'<?=$ar?'Offline MOTO — يدوي عبر RRN + Approval Code':'Offline MOTO — manual via RRN + Approval Code'?>',
  refund:      '<?=$ar?'Refund — إرجاع المبلغ للعميل':'Refund — return funds to customer'?>',
  avoid:       '<?=$ar?'Avoid — تجميد المعاملة بدون تنفيذ':'Avoid — freeze transaction without execution'?>'
};

function setTx(type, el) {
  curTx = type;
  document.querySelectorAll('.tx-btn').forEach(function(b){b.classList.remove('active');});
  el.classList.add('active');
  document.getElementById('txDesc').innerHTML = '<i class="fas fa-info-circle" style="color:var(--gw)"></i> ' + TX_DESC[type];
  document.getElementById('sumType').textContent = TX_LABELS[type];
  document.getElementById('payBtnLabel').textContent = TX_LABELS[type];

  var cardSec    = document.getElementById('cardSection');
  var captureSec = document.getElementById('captureSection');
  var refSec     = document.getElementById('refSection');
  var modeLabel  = document.getElementById('modeLabel');

  [cardSec, captureSec, refSec].forEach(function(s){s.classList.add('hidden');});

  if (type === 'direct2d' || type === 'direct3d' || type === 'online_moto') {
    cardSec.classList.remove('hidden');
    modeLabel.textContent = type === 'direct3d' ? '3D Secure' : (type === 'online_moto' ? 'Online MOTO' : '2D');
    // Stripe 3D
    var sw = document.getElementById('stripeWrap');
    if (sw) {
      if (type === 'direct3d' && stripe) {
        sw.classList.remove('hidden');
        initStripe();
      } else {
        sw.classList.add('hidden');
      }
    }
  } else if (type === 'capture' || type === 'offline_moto') {
    captureSec.classList.remove('hidden');
  } else if (type === 'refund' || type === 'avoid') {
    refSec.classList.remove('hidden');
    var rar = document.getElementById('refAmtRow');
    if (rar) rar.style.display = type === 'refund' ? '' : 'none';
  }
}

function initStripe() {
  if (stripeEl || !stripe) return;
  var els = stripe.elements();
  stripeEl = els.create('card', {
    style: {base:{color:'#fff',fontFamily:'Cairo,sans-serif',fontSize:'15px','::placeholder':{color:'#888'}},invalid:{color:'#ef5350'}},
    hidePostalCode: true
  });
  stripeEl.mount('#stripe-card-element');
  stripeEl.on('change', function(e){
    document.getElementById('stripe-error').textContent = e.error ? e.error.message : '';
  });
}

function calcP() {
  var a = parseFloat(
    document.getElementById('cardAmt')?.value ||
    document.getElementById('captureAmt')?.value ||
    document.getElementById('refAmt')?.value
  ) || 0;
  var c = document.getElementById('cardCur')?.value ||
          document.getElementById('captureCur')?.value ||
          document.getElementById('refCur')?.value || 'USD';
  if (!a) {
    ['sumAmt','sumFee','sumNet'].forEach(function(id){
      var e = document.getElementById(id); if(e) e.textContent='—';
    });
    return;
  }
  var fee = (a * 0.10).toFixed(2);
  var net = (a - parseFloat(fee)).toFixed(2);
  document.getElementById('sumAmt').textContent = a.toFixed(2) + ' ' + c;
  document.getElementById('sumFee').textContent = fee + ' ' + c;
  document.getElementById('sumNet').textContent = net + ' ' + c;
}

function fmtCard(el) {
  var v = el.value.replace(/\D/g,'').substring(0,16);
  el.value = v.replace(/(.{4})/g,'$1 ').trim();
}
function fmtExp(el) {
  var v = el.value.replace(/\D/g,'');
  if (v.length >= 2) v = v.substring(0,2) + '/' + v.substring(2,4);
  el.value = v;
}
function copyText(txt, msg) {
  if (navigator.clipboard) navigator.clipboard.writeText(txt).then(function(){ showToast(msg,'success'); });
  else { var t=document.createElement('textarea');t.value=txt;document.body.appendChild(t);t.select();document.execCommand('copy');document.body.removeChild(t);showToast(msg,'success'); }
}

async function go() {
  var btn = document.getElementById('payBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

  var payload = { card_provider: GW, payment_type: curTx, csrf_token: CSRF };

  if (curTx === 'direct2d' || curTx === 'direct3d' || curTx === 'online_moto') {
    var amt = parseFloat(document.getElementById('cardAmt').value) || 0;
    if (amt < 1) { showToast('<?=$ar?'أدخل مبلغاً صحيحاً':'Enter valid amount'?>','error'); btn.disabled=false; resetBtn(); return; }
    var cc = document.getElementById('ccNumber').value.replace(/\s/g,'');
    if (!cc) { showToast('<?=$ar?'رقم البطاقة مطلوب':'Card number required'?>','error'); btn.disabled=false; resetBtn(); return; }
    payload.amount   = amt;
    payload.currency = document.getElementById('cardCur').value;
    payload.email    = document.getElementById('cardEmail').value.trim() || 'guest@diparmas.com';
    payload.cc_number = cc;
    var exp  = document.getElementById('ccExpiry').value.trim();
    var cvv  = document.getElementById('ccCvv').value.trim();
    var name = document.getElementById('cardName').value.trim();
    var ph   = document.getElementById('cardPhone').value.trim();
    if (exp)  payload.cc_expiry = exp;
    if (cvv)  payload.cc_cvv    = cvv;
    if (name) payload.name      = name;
    if (ph)   payload.phone     = ph;
    payload.security_mode = curTx === 'direct3d' ? '3D' : '2D';
    payload.moto_type     = curTx === 'online_moto' ? 'online' : null;

  } else if (curTx === 'capture' || curTx === 'offline_moto') {
    var rrn  = document.getElementById('rrnInput').value.trim();
    var apco = document.getElementById('approvalInput').value.trim();
    var ma   = parseFloat(document.getElementById('captureAmt').value) || 0;
    if (!rrn)  { showToast('RRN required','error'); btn.disabled=false; resetBtn(); return; }
    if (!apco) { showToast('Approval Code required','error'); btn.disabled=false; resetBtn(); return; }
    if (ma < 1){ showToast('<?=$ar?'أدخل مبلغاً صحيحاً':'Enter valid amount'?>','error'); btn.disabled=false; resetBtn(); return; }
    payload.rrn           = rrn;
    payload.approval_code = apco;
    payload.amount        = ma;
    payload.currency      = document.getElementById('captureCur').value;
    payload.protocol      = curTx === 'offline_moto' ? '201.3' : '101.1';
    // اختياري
    var mn   = document.getElementById('motoCardNum').value.replace(/\s/g,'');
    var mexp = document.getElementById('motoExpiry').value.trim();
    var mcvv = document.getElementById('motoCvv').value.trim();
    var mnam = document.getElementById('motoName').value.trim();
    var meml = document.getElementById('motoEmail').value.trim();
    var mph  = document.getElementById('motoPhone').value.trim();
    if (mn)   payload.cc_number = mn;
    if (mexp) payload.cc_expiry = mexp;
    if (mcvv) payload.cc_cvv    = mcvv;
    if (mnam) payload.name      = mnam;
    if (meml) payload.email     = meml;
    if (mph)  payload.phone     = mph;

  } else if (curTx === 'refund' || curTx === 'avoid') {
    var ref = document.getElementById('refInput').value.trim();
    if (!ref) { showToast('<?=$ar?'أدخل معرّف المعاملة':'Enter transaction ID'?>','error'); btn.disabled=false; resetBtn(); return; }
    payload.refund_reference = ref;
    payload.amount = parseFloat(document.getElementById('refAmt')?.value) || 0;
    payload.currency = document.getElementById('refCur')?.value || 'USD';
  }

  try {
    // Stripe 3D special flow
    if (curTx === 'direct3d' && stripe && stripeEl) {
      var r1 = await fetch('api/orchestrator.php?action=initiate', {
        method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)
      });
      var d1 = await r1.json();
      if (!d1.success) { showToast(d1.message||'Failed','error'); btn.disabled=false; resetBtn(); return; }
      if (d1.payment?.client_secret) {
        var res3d = await stripe.confirmCardPayment(d1.payment.client_secret, {payment_method:{card:stripeEl}});
        if (res3d.error) { document.getElementById('stripe-error').textContent=res3d.error.message; btn.disabled=false; resetBtn(); return; }
      }
      showToast('Done ✓','success');
      setTimeout(function(){ window.location.href='crypto_confirm.php?ref='+encodeURIComponent(d1.reference)+'&type=buy'; }, 1200);
      return;
    }

    var r = await fetch('api/orchestrator.php?action=initiate', {
      method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)
    });
    var d = await r.json();
    if (!d.success) { showToast(d.message||'Failed','error'); btn.disabled=false; resetBtn(); return; }
    showToast('Done ✓','success');
    setTimeout(function(){ window.location.href='crypto_confirm.php?ref='+encodeURIComponent(d.reference)+'&type=buy'; }, 1200);

  } catch(err) {
    showToast('Error: '+err.message,'error');
    btn.disabled=false; resetBtn();
  }
}

function resetBtn(){
  document.getElementById('payBtn').innerHTML = '<i class="fas fa-bolt"></i><span id="payBtnLabel">' + TX_LABELS[curTx] + '</span>';
}
function showToast(msg, type) {
  var t = document.getElementById('toast');
  t.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(0);background:var(--card);border:1px solid '+(type==='error'?'var(--red)':'var(--green)')+';border-radius:14px;padding:12px 26px;font-size:.85rem;font-weight:700;z-index:9999;transition:.35s;color:var(--text);white-space:nowrap';
  t.textContent = msg;
  setTimeout(function(){ t.style.transform='translateX(-50%) translateY(90px)'; }, 3500);
}

document.addEventListener('DOMContentLoaded', function(){
  setTx('direct2d', document.getElementById('tx_direct2d'));
});
</script>
</body></html>
