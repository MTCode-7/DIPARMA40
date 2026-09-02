<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
$csrfToken = generateCsrfToken();
$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'ar' ? 'ar' : 'en';
$ar = ($lang === 'ar'); $dir = $ar ? 'rtl' : 'ltr';

$gateways = [
    'nuvei'      => ['label'=>'Nuvei',      'color'=>'#0A5EB0','icon'=>'fas fa-credit-card',     'id_label'=>'Transaction ID (sessionToken)', 'currencies'=>['USD','EUR','GBP','AED','SAR']],
    'stripe'     => ['label'=>'Stripe',     'color'=>'#6772e5','icon'=>'fab fa-stripe-s',         'id_label'=>'Payment Intent ID (pi_...)',   'currencies'=>['USD','EUR','GBP','AED','SAR']],
    'paypal'     => ['label'=>'PayPal',     'color'=>'#0070ba','icon'=>'fab fa-paypal',            'id_label'=>'Order ID (PayPal)',            'currencies'=>['USD','EUR','GBP','AUD','CAD']],
    'myfatoorah' => ['label'=>'MyFatoorah', 'color'=>'#00b09b','icon'=>'fas fa-money-bill-wave',  'id_label'=>'Invoice ID',                  'currencies'=>['KWD','SAR','AED','BHD','QAR','OMR','USD']],
    'wise'       => ['label'=>'Wise',       'color'=>'#00B9FF','icon'=>'fas fa-paper-plane',       'id_label'=>'Quote ID (Transfer Reference)','currencies'=>['USD','EUR','GBP','AED','SAR','EGP']],
    'binance'    => ['label'=>'Binance Pay','color'=>'#F0B90B','icon'=>'fas fa-coins',             'id_label'=>'Prepay ID',                   'currencies'=>['USD','USDT','BTC','ETH','BNB']],
    'gate_io'    => ['label'=>'Gate.io',    'color'=>'#e8112d','icon'=>'fas fa-door-open',         'id_label'=>'Order ID',                    'currencies'=>['USD','USDT','BTC','ETH']],
    'whop'       => ['label'=>'Whop',       'color'=>'#4F46E5','icon'=>'fas fa-bolt',              'id_label'=>'Checkout URL / Payment ID',   'currencies'=>['USD','EUR','GBP']],
];
?><!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$dir?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | Create Transaction ID</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--gold:#FFD700;--bg:#040810;--card:#080d1a;--border:rgba(255,215,0,.15);--text:#f0f0f0;--muted:#666;--green:#10B981;--red:#EF4444}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.top-bar{background:rgba(4,8,16,.97);border-bottom:1px solid var(--border);padding:0 24px;height:62px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:99}
.brand{color:var(--gold);font-weight:900}
.top-nav a{color:var(--muted);font-size:.82rem;padding:6px 12px;border-radius:20px;text-decoration:none}
.top-nav a:hover{color:var(--gold)}
.wrap{max-width:1000px;margin:28px auto;padding:0 20px;display:grid;grid-template-columns:320px 1fr;gap:20px}
.co-card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:22px;margin-bottom:14px}
.co-title{font-size:.92rem;font-weight:800;margin-bottom:16px;display:flex;align-items:center;gap:8px}
/* Gateway Grid */
.gw-grid{display:grid;grid-template-columns:1fr 1fr;gap:9px}
.gw-btn{background:rgba(255,255,255,.04);border:2px solid rgba(255,215,0,.12);border-radius:12px;padding:12px 8px;text-align:center;cursor:pointer;transition:.25s}
.gw-btn:hover{border-color:rgba(255,215,0,.3);transform:translateY(-1px)}
.gw-btn.active{border-color:var(--gw-color,var(--gold));background:color-mix(in srgb,var(--gw-color,var(--gold)) 10%,transparent)}
.gw-btn i{display:block;font-size:1.3rem;margin-bottom:5px}
.gw-btn span{font-size:.7rem;font-weight:700}
/* Form */
.fld{margin-bottom:12px}
.fld label{display:block;font-size:.74rem;color:var(--muted);margin-bottom:4px;font-weight:700}
.fld input,.fld select{width:100%;background:rgba(255,255,255,.04);border:1.5px solid var(--border);border-radius:10px;padding:10px 13px;color:var(--text);font-family:'Cairo',sans-serif;font-size:.86rem}
.fld input:focus,.fld select:focus{outline:none;border-color:var(--active-color,var(--gold))}
.fld-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.btn{width:100%;padding:13px;border-radius:12px;border:none;cursor:pointer;font-family:'Cairo',sans-serif;font-weight:800;font-size:.93rem;color:#fff;transition:.3s;margin-top:8px;display:flex;align-items:center;justify-content:center;gap:8px}
.btn:hover:not(:disabled){transform:translateY(-2px)}
.btn:disabled{opacity:.45;cursor:not-allowed;transform:none}
/* Result */
.result-area{min-height:200px}
.result-card{background:rgba(16,185,129,.06);border:1.5px solid var(--green);border-radius:16px;padding:20px}
.result-title{font-size:.9rem;font-weight:800;color:var(--green);margin-bottom:14px;display:flex;align-items:center;gap:8px}
.result-row{display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-bottom:1px solid rgba(255,255,255,.06);font-size:.82rem}
.result-row:last-child{border:none}
.result-key{color:var(--muted);font-size:.75rem;flex-shrink:0;margin-<?=$ar?'left':'right'?>:12px}
.result-val{font-weight:700;font-family:monospace;display:flex;align-items:center;gap:8px;min-width:0;word-break:break-all;color:var(--text);text-align:<?=$ar?'right':'left'?>}
.copy-btn{background:rgba(255,215,0,.1);border:1px solid rgba(255,215,0,.2);border-radius:7px;padding:3px 10px;cursor:pointer;font-size:.68rem;color:var(--gold);white-space:nowrap;flex-shrink:0}
.id-highlight{color:var(--gold);font-size:.9rem;font-weight:900}
.action-btns{display:flex;gap:8px;margin-top:14px}
.action-btn{flex:1;padding:10px;border-radius:10px;border:none;cursor:pointer;font-family:'Cairo',sans-serif;font-weight:700;font-size:.82rem;transition:.2s;text-decoration:none;text-align:center;display:flex;align-items:center;justify-content:center;gap:6px}
.action-btn.capture{background:rgba(159,232,112,.15);color:#9fe870;border:1px solid rgba(159,232,112,.3)}
.action-btn.capture:hover{background:rgba(159,232,112,.25)}
.info-note{background:rgba(255,215,0,.04);border:1px solid rgba(255,215,0,.1);border-radius:10px;padding:12px;font-size:.77rem;color:#aaa;margin-bottom:14px;line-height:1.6}
.empty-state{text-align:center;padding:50px 20px;color:var(--muted)}
.empty-state i{font-size:3rem;display:block;margin-bottom:14px;color:rgba(255,215,0,.15)}
.back-link{display:inline-flex;align-items:center;gap:6px;color:var(--muted);font-size:.8rem;text-decoration:none;margin-bottom:14px}
.back-link:hover{color:var(--gold)}
@media(max-width:768px){.wrap{grid-template-columns:1fr}}
</style>

<nav class="top-bar">
  <div class="brand"><i class="fas fa-coins"></i> DI PARMA</div>
  <div class="top-nav">
    <a href="capture.php"><i class="fas fa-check-double"></i> Capture</a>
    <a href="dashboard.php"><i class="fas fa-th-large"></i></a>
  </div>
</nav>

<div style="max-width:1000px;margin:14px auto;padding:0 20px">
  <a href="index.php" class="back-link"><i class="fas fa-arrow-<?=$ar?'right':'left'?>"></i> <?=$ar?'رجوع':'Back'?></a>
</div>

<div class="wrap">
<!-- ══ Left: Form ══ -->
<div>
  <div class="info-note">
    <i class="fas fa-info-circle" style="color:var(--gold)"></i>
    <?=$ar?
      '<strong>إنشاء Transaction ID بدون سحب.</strong> استخدم الـ ID في صفحة Capture مع RRN + Approval Code لإتمام العملية.' :
      '<strong>Create Transaction ID without charge.</strong> Use the ID in Capture page with RRN + Approval Code to complete the transaction.'
    ?>
  </div>

  <!-- اختيار البوابة -->
  <div class="co-card">
    <div class="co-title"><i class="fas fa-plug" style="color:var(--gold)"></i> <?=$ar?'اختر البوابة':'Select Gateway'?></div>
    <div class="gw-grid">
      <?php foreach ($gateways as $code => $gw): ?>
      <div class="gw-btn <?=$code==='nuvei'?'active':''?>"
           id="gwbtn_<?=$code?>"
           style="--gw-color:<?=$gw['color']?>"
           onclick="selectGW('<?=$code?>',<?=json_encode($gw)?>)">
        <i class="<?=$gw['icon']?>" style="color:<?=$gw['color']?>"></i>
        <span><?=$gw['label']?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- الحقول -->
  <div class="co-card">
    <div class="co-title">
      <i id="formIcon" class="fas fa-credit-card" style="color:#0A5EB0"></i>
      <span id="formTitle">Nuvei — <?=$ar?'إنشاء Transaction ID':'Create Transaction ID'?></span>
    </div>

    <div class="fld-row">
      <div class="fld">
        <label><?=$ar?'المبلغ المرجعي':'Reference Amount'?></label>
        <input type="number" id="amount" min="1" step="0.01" placeholder="1000.00" value="1000">
      </div>
      <div class="fld">
        <label><?=$ar?'العملة':'Currency'?></label>
        <select id="currency">
          <option value="USD">USD</option>
        </select>
      </div>
    </div>

    <div class="fld">
      <label>Email <span style="font-size:.63rem;color:var(--muted)">(<?=$ar?'اختياري':'optional'?>)</span></label>
      <input type="email" id="email" placeholder="example@email.com" value="guest@diparmas.com">
    </div>

    <button class="btn" id="createBtn" style="background:linear-gradient(135deg,#083d7a,#0A5EB0)" onclick="createTxn()">
      <i class="fas fa-plus-circle"></i>
      <?=$ar?'إنشاء Transaction ID':'Create Transaction ID'?>
    </button>
  </div>
</div>

<!-- ══ Right: Result ══ -->
<div class="result-area">
  <!-- Empty state -->
  <div id="emptyState" class="co-card" style="height:100%;display:flex;align-items:center;justify-content:center">
    <div class="empty-state">
      <i class="fas fa-fingerprint"></i>
      <p style="font-size:.88rem;margin-bottom:6px;font-weight:700"><?=$ar?'لا يوجد ID بعد':'No ID yet'?></p>
      <p style="font-size:.78rem"><?=$ar?'اختر بوابة واضغط إنشاء':'Select gateway and click Create'?></p>
    </div>
  </div>

  <!-- Result -->
  <div id="resultArea" style="display:none">
    <div class="co-card result-card">
      <div class="result-title">
        <i class="fas fa-check-circle"></i>
        <?=$ar?'✅ تم إنشاء Transaction ID':'✅ Transaction ID Created'?>
      </div>

      <div class="result-row">
        <span class="result-key"><?=$ar?'البوابة':'Gateway'?></span>
        <span class="result-val" id="resGateway" style="color:var(--gold)"></span>
      </div>

      <div class="result-row">
        <span class="result-key" id="resTxnLabel">Transaction ID</span>
        <span class="result-val">
          <span id="resTxnId" class="id-highlight"></span>
          <button class="copy-btn" onclick="copyVal('resTxnId')"><i class="fas fa-copy"></i> Copy</button>
        </span>
      </div>

      <div id="resExtraRow" class="result-row" style="display:none">
        <span class="result-key" id="resExtraLabel">Extra ID</span>
        <span class="result-val">
          <span id="resExtra"></span>
          <button class="copy-btn" onclick="copyVal('resExtra')"><i class="fas fa-copy"></i></button>
        </span>
      </div>

      <div class="result-row">
        <span class="result-key"><?=$ar?'المرجع':'Reference'?></span>
        <span class="result-val">
          <span id="resRef" style="color:#aaa;font-size:.78rem"></span>
          <button class="copy-btn" onclick="copyVal('resRef')"><i class="fas fa-copy"></i></button>
        </span>
      </div>

      <div class="result-row">
        <span class="result-key"><?=$ar?'الرسالة':'Message'?></span>
        <span class="result-val" id="resMessage" style="color:#aaa;font-size:.78rem"></span>
      </div>

      <!-- Action Buttons -->
      <div class="action-btns">
        <a href="capture.php" class="action-btn capture">
          <i class="fas fa-check-double"></i>
          <?=$ar?'انتقل لـ Capture':'Go to Capture'?>
        </a>
        <button class="action-btn" style="background:rgba(255,215,0,.08);color:var(--gold);border:1px solid rgba(255,215,0,.2)" onclick="createTxn()">
          <i class="fas fa-redo"></i>
          <?=$ar?'إنشاء جديد':'Create New'?>
        </button>
      </div>
    </div>

    <!-- URL خاص لـ Whop/PayPal -->
    <div id="urlBox" class="co-card" style="display:none;margin-top:0;border-color:rgba(255,165,0,.3)">
      <div class="co-title" style="font-size:.82rem"><i class="fas fa-external-link-alt" style="color:var(--gold)"></i> <?=$ar?'رابط الدفع':'Payment URL'?></div>
      <div style="word-break:break-all;font-family:monospace;font-size:.78rem;color:#aaa" id="urlText"></div>
      <div style="margin-top:10px;display:flex;gap:8px">
        <button class="copy-btn" style="padding:6px 14px" onclick="copyText(document.getElementById('urlText').textContent)">
          <i class="fas fa-copy"></i> Copy URL
        </button>
        <a id="urlLink" href="#" target="_blank" class="copy-btn" style="padding:6px 14px;text-decoration:none">
          <i class="fas fa-external-link-alt"></i> Open
        </a>
      </div>
    </div>
  </div>
</div>
</div>

<div id="toast" style="position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(90px);background:var(--card);border:1px solid var(--gold);border-radius:14px;padding:12px 26px;font-size:.85rem;font-weight:700;z-index:9999;transition:.35s;color:var(--text)"></div>

<script>
var CSRF = '<?=htmlspecialchars($csrfToken)?>';
var currentGW = 'nuvei';
var gwData = <?=json_encode($gateways)?>;

function selectGW(code, data) {
  currentGW = code;
  document.querySelectorAll('.gw-btn').forEach(function(b){ b.classList.remove('active'); });
  var btn = document.getElementById('gwbtn_' + code);
  if (btn) btn.classList.add('active');

  // تحديث العملات
  var sel = document.getElementById('currency');
  sel.innerHTML = '';
  (data.currencies || ['USD']).forEach(function(c){
    var o = document.createElement('option');
    o.value = c; o.textContent = c;
    sel.appendChild(o);
  });

  // تحديث الزر والعنوان
  var btn2 = document.getElementById('createBtn');
  btn2.style.background = 'linear-gradient(135deg,' + shadeColor(data.color, -30) + ',' + data.color + ')';

  var icon = document.getElementById('formIcon');
  icon.className = data.icon + ' ';
  icon.style.color = data.color;

  document.getElementById('formTitle').textContent = data.label + ' — <?=$ar?'إنشاء Transaction ID':'Create Transaction ID'?>';
  document.getElementById('resTxnLabel').textContent = data.id_label;
}

function shadeColor(color, percent) {
  var num = parseInt(color.replace('#',''), 16);
  var amt = Math.round(2.55 * percent);
  var R = (num >> 16) + amt;
  var G = (num >> 8 & 0x00FF) + amt;
  var B = (num & 0x0000FF) + amt;
  return '#' + (0x1000000 + (R<255?R<1?0:R:255)*0x10000 + (G<255?G<1?0:G:255)*0x100 + (B<255?B<1?0:B:255)).toString(16).slice(1);
}

async function createTxn() {
  var btn = document.getElementById('createBtn');
  btn.disabled = true;
  var origHTML = btn.innerHTML;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';

  try {
    var r = await fetch('api/create_txn.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        gateway:    currentGW,
        amount:     parseFloat(document.getElementById('amount').value) || 1000,
        currency:   document.getElementById('currency').value,
        email:      document.getElementById('email').value || 'guest@diparmas.com',
        csrf_token: CSRF
      })
    });
    var d = await r.json();

    if (d.success) {
      // عرض النتيجة
      document.getElementById('emptyState').style.display = 'none';
      document.getElementById('resultArea').style.display = 'block';

      var gw = gwData[currentGW] || {};
      document.getElementById('resGateway').textContent  = gw.label || currentGW;
      document.getElementById('resGateway').style.color  = gw.color || 'var(--gold)';
      document.getElementById('resTxnLabel').textContent = gw.id_label || 'Transaction ID';
      document.getElementById('resTxnId').textContent    = d.transaction_id || '';
      document.getElementById('resRef').textContent      = d.reference || '';
      document.getElementById('resMessage').textContent  = d.message || '';

      // Extra ID
      var extra = d.order_id || d.quote_id || '';
      var extraRow = document.getElementById('resExtraRow');
      if (extra) {
        document.getElementById('resExtraLabel').textContent = currentGW === 'paypal' ? 'PayPal Order ID' : 'Order ID';
        document.getElementById('resExtra').textContent = extra;
        extraRow.style.display = '';
      } else {
        extraRow.style.display = 'none';
      }

      // URL box
      var url = d.checkout_url || d.approve_url || d.payment_url || '';
      var urlBox = document.getElementById('urlBox');
      if (url) {
        document.getElementById('urlText').textContent = url;
        document.getElementById('urlLink').href = url;
        urlBox.style.display = 'block';
      } else {
        urlBox.style.display = 'none';
      }

      showToast('<?=$ar?'تم إنشاء Transaction ID ✓':'Transaction ID created ✓'?>', 'success');
    } else {
      showToast(d.message || 'Failed', 'error');
    }
  } catch(err) {
    showToast('Error: ' + err.message, 'error');
  }

  btn.disabled = false;
  btn.innerHTML = origHTML;
}

function copyVal(id) {
  var el = document.getElementById(id);
  if (!el) return;
  copyText(el.textContent);
}
function copyText(txt) {
  if (navigator.clipboard) {
    navigator.clipboard.writeText(txt).then(function(){ showToast('Copied!', 'success'); });
  } else {
    var t = document.createElement('textarea');
    t.value = txt; document.body.appendChild(t);
    t.select(); document.execCommand('copy');
    document.body.removeChild(t);
    showToast('Copied!', 'success');
  }
}
function showToast(msg, type) {
  var t = document.getElementById('toast');
  t.style.borderColor = type === 'error' ? 'var(--red)' : 'var(--green)';
  t.textContent = msg;
  t.style.transform = 'translateX(-50%) translateY(0)';
  setTimeout(function(){ t.style.transform = 'translateX(-50%) translateY(90px)'; }, 3000);
}

// Initialize with Nuvei
selectGW('nuvei', gwData['nuvei']);
</script>
</body></html>
