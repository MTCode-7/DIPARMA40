<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
$csrfToken = generateCsrfToken();
$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'ar' ? 'ar' : 'en';
$ar = ($lang === 'ar'); $dir = $ar ? 'rtl' : 'ltr';
?><!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$dir?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | Nuvei — Create Transaction ID</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--gold:#FFD700;--bg:#040810;--card:#080d1a;--border:rgba(255,215,0,.15);--text:#f0f0f0;--muted:#666;--green:#10B981;--red:#EF4444;--nuvei:#0A5EB0}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.top-bar{background:rgba(4,8,16,.97);border-bottom:1px solid var(--border);padding:0 24px;height:62px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:99}
.brand{color:var(--gold);font-weight:900}
.badge{background:rgba(10,94,176,.15);border:2px solid var(--nuvei);border-radius:10px;padding:5px 14px;color:var(--nuvei);font-weight:800;font-size:.82rem}
.top-nav a{color:var(--muted);font-size:.82rem;padding:6px 12px;border-radius:20px;text-decoration:none}
.top-nav a:hover{color:var(--gold)}
.wrap{max-width:560px;margin:40px auto;padding:0 20px}
.co-card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:24px;margin-bottom:16px}
.co-title{font-size:.95rem;font-weight:800;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.fld{margin-bottom:12px}
.fld label{display:block;font-size:.74rem;color:var(--muted);margin-bottom:4px;font-weight:700}
.fld input,.fld select{width:100%;background:rgba(255,255,255,.04);border:1.5px solid var(--border);border-radius:10px;padding:10px 13px;color:var(--text);font-family:'Cairo',sans-serif;font-size:.86rem}
.fld input:focus,.fld select:focus{outline:none;border-color:var(--nuvei)}
.fld-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.btn{width:100%;padding:13px;border-radius:12px;border:none;cursor:pointer;font-family:'Cairo',sans-serif;font-weight:800;font-size:.93rem;background:linear-gradient(135deg,#083d7a,var(--nuvei));color:#fff;transition:.3s;margin-top:8px;display:flex;align-items:center;justify-content:center;gap:8px}
.btn:hover{transform:translateY(-2px)}
.btn:disabled{opacity:.5;cursor:not-allowed;transform:none}
/* Result Box */
.result-box{display:none;background:rgba(10,94,176,.06);border:1.5px solid var(--nuvei);border-radius:14px;padding:20px;margin-top:16px}
.result-box.show{display:block}
.result-title{font-size:.88rem;font-weight:800;color:var(--green);margin-bottom:12px}
.result-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.06);font-size:.82rem}
.result-row:last-child{border:none}
.result-key{color:var(--muted);font-size:.76rem}
.result-val{font-weight:700;font-family:monospace;display:flex;align-items:center;gap:8px;max-width:280px;word-break:break-all}
.copy-btn{background:rgba(255,215,0,.1);border:1px solid rgba(255,215,0,.2);border-radius:7px;padding:2px 9px;cursor:pointer;font-size:.68rem;color:var(--gold);white-space:nowrap}
.info-note{background:rgba(255,215,0,.04);border:1px solid rgba(255,215,0,.1);border-radius:10px;padding:12px;font-size:.78rem;color:#aaa;margin-bottom:14px}
.back-link{display:inline-flex;align-items:center;gap:6px;color:var(--muted);font-size:.8rem;text-decoration:none;margin-bottom:16px}
.back-link:hover{color:var(--gold)}
</style>

<nav class="top-bar">
  <div class="brand"><i class="fas fa-coins"></i> DI PARMA</div>
  <div style="display:flex;align-items:center;gap:10px">
    <div class="badge"><i class="fas fa-credit-card"></i> Nuvei — Create Transaction ID</div>
    <div class="top-nav"><a href="dashboard.php"><i class="fas fa-th-large"></i></a></div>
  </div>
</nav>

<div class="wrap">
  <a href="capture.php" class="back-link"><i class="fas fa-arrow-<?=$ar?'right':'left'?>"></i> <?=$ar?'رجوع لـ Capture':'Back to Capture'?></a>

  <div class="info-note">
    <i class="fas fa-info-circle" style="color:var(--gold)"></i>
    <?=$ar?
      'هذه الصفحة تُنشئ Transaction ID في Nuvei <strong>بدون سحب أو حجز</strong>. استخدم الـ ID في صفحة Capture لاحقاً.':
      'This page creates a Transaction ID in Nuvei <strong>without any charge or hold</strong>. Use the ID in the Capture page later.'
    ?>
  </div>

  <div class="co-card">
    <div class="co-title">
      <i class="fas fa-plus-circle" style="color:var(--nuvei)"></i>
      <?=$ar?'إنشاء Transaction ID':'Create Transaction ID'?>
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
          <option value="EUR">EUR</option>
          <option value="GBP">GBP</option>
          <option value="AED">AED</option>
          <option value="SAR">SAR</option>
        </select>
      </div>
    </div>

    <div class="fld">
      <label>Email <span style="font-size:.63rem;color:var(--muted)">(optional)</span></label>
      <input type="email" id="email" placeholder="example@email.com" value="guest@diparmas.com">
    </div>

    <button class="btn" id="createBtn" onclick="createTxn()">
      <i class="fas fa-plus-circle"></i>
      <?=$ar?'إنشاء Transaction ID':'Create Transaction ID'?>
    </button>
  </div>

  <!-- Result -->
  <div class="result-box" id="resultBox">
    <div class="result-title">
      <i class="fas fa-check-circle"></i>
      <?=$ar?'✅ تم إنشاء Transaction ID بنجاح':'✅ Transaction ID created successfully'?>
    </div>
    <div class="result-row">
      <span class="result-key">Transaction ID (sessionToken)</span>
      <span class="result-val">
        <span id="resTxnId" style="color:var(--nuvei)"></span>
        <button class="copy-btn" onclick="copyVal('resTxnId','Copied!')"><i class="fas fa-copy"></i></button>
      </span>
    </div>
    <div class="result-row">
      <span class="result-key">Order ID</span>
      <span class="result-val">
        <span id="resOrderId" style="color:var(--gold)"></span>
        <button class="copy-btn" onclick="copyVal('resOrderId','Copied!')"><i class="fas fa-copy"></i></button>
      </span>
    </div>
    <div class="result-row">
      <span class="result-key">Reference</span>
      <span class="result-val">
        <span id="resRef" style="color:#aaa"></span>
        <button class="copy-btn" onclick="copyVal('resRef','Copied!')"><i class="fas fa-copy"></i></button>
      </span>
    </div>
    <div style="margin-top:14px;text-align:center">
      <a id="goCapture" href="capture.php" class="btn" style="display:inline-flex;width:auto;padding:10px 20px;text-decoration:none">
        <i class="fas fa-check-double"></i>
        <?=$ar?'انتقل لصفحة Capture':'Go to Capture'?>
      </a>
    </div>
  </div>
</div>

<div id="toast" style="position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(90px);background:var(--card);border:1px solid var(--gold);border-radius:14px;padding:12px 26px;font-size:.85rem;font-weight:700;z-index:9999;transition:.35s;color:var(--text)"></div>

<script>
var CSRF = '<?=htmlspecialchars($csrfToken)?>';

async function createTxn() {
    var btn = document.getElementById('createBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';

    var amount   = parseFloat(document.getElementById('amount').value) || 1000;
    var currency = document.getElementById('currency').value;
    var email    = document.getElementById('email').value || 'guest@diparmas.com';

    try {
        var r = await fetch('api/nuvei_create_txn.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ amount, currency, email, csrf_token: CSRF })
        });
        var d = await r.json();

        if (d.success) {
            document.getElementById('resTxnId').textContent  = d.session_token || '';
            document.getElementById('resOrderId').textContent = d.order_id || '';
            document.getElementById('resRef').textContent    = d.reference || '';
            document.getElementById('resultBox').classList.add('show');
            showToast('Transaction ID created ✓', 'success');
        } else {
            showToast(d.message || 'Failed', 'error');
        }
    } catch(err) {
        showToast('Error: ' + err.message, 'error');
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-plus-circle"></i> <?=$ar?'إنشاء Transaction ID':'Create Transaction ID'?>';
}

function copyVal(id, msg) {
    var el = document.getElementById(id);
    if (!el) return;
    if (navigator.clipboard) {
        navigator.clipboard.writeText(el.textContent).then(function(){ showToast(msg, 'success'); });
    } else {
        var t = document.createElement('textarea');
        t.value = el.textContent;
        document.body.appendChild(t);
        t.select();
        document.execCommand('copy');
        document.body.removeChild(t);
        showToast(msg, 'success');
    }
}

function showToast(msg, type) {
    var t = document.getElementById('toast');
    t.style.borderColor = type === 'error' ? 'var(--red)' : 'var(--green)';
    t.textContent = msg;
    t.style.transform = 'translateX(-50%) translateY(0)';
    setTimeout(function(){ t.style.transform = 'translateX(-50%) translateY(90px)'; }, 3000);
}
</script>
</body></html>
