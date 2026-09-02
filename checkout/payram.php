<?php
/**
 * DI PARMA | Checkout — PayRam (Self-hosted Crypto Gateway)
 * DI PARMA → PayRam → Ledger TRX (USDT)
 */
// Keep legacy PayRam links inside DI PARMA instead of exposing the hosted-link flow.
$internalCheckout = rtrim(defined('SITE_URL') ? SITE_URL : '', '/') . '/checkout_diparma.php';
$queryString = $_SERVER['QUERY_STRING'] ?? '';
header('Location: ' . $internalCheckout . ($queryString !== '' ? '?' . $queryString : ''));
exit;

require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../lib/PayRamAdapter.php';

$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'ar' ? 'ar' : 'en';
$ar   = ($lang === 'ar');
$dir  = $ar ? 'rtl' : 'ltr';
$csrf = generateCsrfToken();
$db   = db();

$amount      = floatval($_GET['amount']      ?? 0);
$currency    = strtoupper($_GET['currency']  ?? 'USD');
$destination = 'ledger_trx';
$txnType     = $_GET['txn_type']             ?? 'purchase';
$ref         = $_GET['ref']                  ?? ('PR-' . strtoupper(substr(uniqid(), 0, 8)));
$notes       = htmlspecialchars($_GET['notes'] ?? '');
$ledgerAddr  = defined('LEDGER_TRC20_ADDRESS') ? LEDGER_TRC20_ADDRESS
             : (getenv('LEDGER_TRC20_ADDRESS') ?: '');

$payram = new PayRamAdapter();

/* جلب أسعار العملات */
$tickers   = [];
$trxPrice  = null;
$usdtPrice = null;
try {
    $tickers = $payram->getTickers();
    foreach ($tickers as $t) {
        if ($t['blockchainCode'] === 'TRX' && $t['currencyCode'] === 'TRX')  $trxPrice  = (float)$t['price'];
        if ($t['blockchainCode'] === 'TRX' && $t['currencyCode'] === 'USDT') $usdtPrice = (float)$t['price'];
    }
} catch (Exception $e) {}

/* تحويل المبلغ */
$fxRates = ['USD'=>1.0,'AED'=>0.2723,'SAR'=>0.2667,'EUR'=>1.082,'GBP'=>1.271,'KWD'=>3.257,'QAR'=>0.2747,'EGP'=>0.0204];
$amountUSD  = $amount * ($fxRates[$currency] ?? 1.0);
$amountUSDT = $usdtPrice > 0 ? round($amountUSD / $usdtPrice, 6) : null;
$amountTRX  = $trxPrice  > 0 ? round($amountUSD / $trxPrice, 2) : null;

$payramConfigured = $payram->isConfigured();
$payramConnected  = $payram->checkConnection();
?><!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$dir?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | PayRam Crypto Checkout</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--gold:#FFD700;--bg:#020508;--card:#070e1c;--card2:#0a1224;--border:rgba(255,215,0,.11);--text:#edf0f7;--muted:#3d4a5c;--muted2:#6b7a90;--green:#10B981;--red:#EF4444;--tron:#EF4444}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.topbar{height:54px;background:rgba(2,5,8,.97);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 24px;position:sticky;top:0;z-index:100}
.tb-brand{color:var(--gold);font-weight:900;font-size:.92rem;display:flex;align-items:center;gap:8px}
.tb-badge{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);border-radius:8px;padding:3px 10px;font-size:.68rem;font-weight:800;color:var(--tron)}
.wrap{max-width:680px;margin:32px auto;padding:0 20px}
.card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:22px;margin-bottom:16px}
.card-title{font-size:.85rem;font-weight:800;color:var(--gold);display:flex;align-items:center;gap:8px;margin-bottom:16px}

/* Amount Display */
.amount-hero{text-align:center;padding:20px;background:var(--card2);border:1px solid var(--border);border-radius:14px;margin-bottom:16px}
.amount-usd{font-size:2.4rem;font-weight:900;color:var(--gold)}
.amount-sub{font-size:.78rem;color:var(--muted2);margin-top:6px;display:flex;justify-content:center;gap:16px;flex-wrap:wrap}
.amount-crypto{background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.2);border-radius:8px;padding:4px 12px;font-size:.75rem;color:var(--green);font-weight:700}

/* Chains */
.chain-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px}
.chain-card{background:var(--card2);border:1.5px solid var(--border);border-radius:12px;padding:12px;cursor:pointer;text-align:center;transition:.2s}
.chain-card:hover{border-color:rgba(255,215,0,.25);transform:translateY(-1px)}
.chain-card.active{border-color:var(--gold);background:rgba(255,215,0,.05)}
.chain-icon{font-size:1.4rem;margin-bottom:5px}
.chain-name{font-size:.75rem;font-weight:800}
.chain-token{font-size:.65rem;color:var(--muted2);margin-top:2px}
.chain-price{font-size:.7rem;color:var(--green);margin-top:3px;font-weight:700}

/* Form */
.fld{margin-bottom:12px}
.fld label{display:block;font-size:.68rem;color:var(--muted2);margin-bottom:4px;font-weight:700}
.fld input{width:100%;background:rgba(255,255,255,.04);border:1.5px solid var(--border);border-radius:10px;padding:10px 12px;color:var(--text);font-family:'Cairo',sans-serif;font-size:.83rem}
.fld input:focus{outline:none;border-color:var(--gold)}

/* Buttons */
.pay-btn{width:100%;padding:14px;border-radius:13px;border:none;cursor:pointer;font-family:'Cairo',sans-serif;font-size:.95rem;font-weight:900;background:linear-gradient(135deg,var(--gold),#FFB700);color:#000;box-shadow:0 8px 24px rgba(255,215,0,.22);transition:.3s;display:flex;align-items:center;justify-content:center;gap:10px}
.pay-btn:hover:not(:disabled){transform:translateY(-2px)}
.pay-btn:disabled{opacity:.35;cursor:not-allowed;transform:none}

/* Status */
.status-box{border-radius:12px;padding:14px;margin-top:14px;display:none;text-align:center}
.status-box.show{display:block}
.status-pending{background:rgba(255,215,0,.06);border:1px solid rgba(255,215,0,.2);color:var(--gold)}
.status-success{background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.2);color:var(--green)}
.status-error{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);color:var(--red)}

.not-configured{background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.2);border-radius:12px;padding:16px;text-align:center;font-size:.82rem;color:var(--red)}

/* QR & Address */
.deposit-info{background:var(--card2);border:1px solid rgba(16,185,129,.2);border-radius:12px;padding:14px;margin-top:12px;display:none}
.deposit-info.show{display:block}
.deposit-addr{font-family:monospace;font-size:.72rem;word-break:break-all;color:var(--green);background:rgba(16,185,129,.06);padding:8px 10px;border-radius:8px;margin:8px 0}
.copy-btn{background:none;border:1px solid rgba(16,185,129,.3);border-radius:6px;padding:4px 10px;color:var(--green);font-size:.68rem;cursor:pointer;font-family:'Cairo',sans-serif}

/* Toast */
#toast{position:fixed;bottom:22px;left:50%;transform:translateX(-50%) translateY(100px);background:var(--card);border:1px solid rgba(255,215,0,.3);border-radius:13px;padding:10px 22px;font-size:.8rem;font-weight:700;z-index:9999;transition:.35s;color:var(--text)}
</style>
</head>
<body>
<header class="topbar">
  <div class="tb-brand">
    <i class="fas fa-coins"></i> DI PARMA
    <span class="tb-badge"><i class="fas fa-server"></i> PayRam <?=$payramConnected?'✓':'!'?></span>
  </div>
  <a href="../checkout_router.php" style="color:var(--muted2);font-size:.75rem;text-decoration:none">
    <i class="fas fa-arrow-<?=$ar?'right':'left'?>"></i> <?=$ar?'رجوع':'Back'?>
  </a>
</header>

<div class="wrap">

  <?php if (!$payramConfigured): ?>
  <div class="not-configured">
    <i class="fas fa-exclamation-circle" style="font-size:1.5rem;margin-bottom:8px;display:block"></i>
    <?=$ar?'PayRam غير مهيّأ — أضف PAYRAM_API_KEY في .env':'PayRam not configured — add PAYRAM_API_KEY to .env'?>
    <div style="margin-top:8px;font-size:.72rem;color:var(--muted2)">
      <?=$ar?'Dashboard:':'Dashboard:'?> <a href="http://65.2.184.57:8080" target="_blank" style="color:var(--gold)">http://65.2.184.57:8080</a>
    </div>
  </div>
  <?php endif; ?>

  <!-- Amount -->
  <div class="amount-hero">
    <div class="amount-usd"><?=number_format($amountUSD, 2)?> USD</div>
    <div class="amount-sub">
      <span class="amount-crypto">≈ <?=$amountUSDT !== null ? number_format($amountUSDT,4).' USDT' : ($ar?'غير متاح':'Unavailable')?></span>
      <span class="amount-crypto" style="color:var(--tron);border-color:rgba(239,68,68,.2);background:rgba(239,68,68,.06)">≈ <?=$amountTRX !== null ? number_format($amountTRX,2).' TRX' : ($ar?'غير متاح':'Unavailable')?></span>
    </div>
  </div>

  <!-- Chain Selection -->
  <div class="card">
    <div class="card-title"><i class="fas fa-network-wired"></i> <?=$ar?'اختر الشبكة والعملة':'Select Network & Currency'?></div>
    <div class="chain-grid" id="chainGrid">
      <?php
      $chains = [
        ['code'=>'TRX',     'token'=>'USDT',  'label'=>'Tron',     'icon'=>'♦', 'color'=>'#EF4444', 'sub'=>'TRC20 · ~$0.01'],
        ['code'=>'TRX',     'token'=>'TRX',   'label'=>'Tron',     'icon'=>'♦', 'color'=>'#EF4444', 'sub'=>'Native · ~$0.01'],
        ['code'=>'BASE',    'token'=>'USDC',  'label'=>'Base',     'icon'=>'🔵','color'=>'#3B82F6', 'sub'=>'ERC20 · ~$0.01'],
        ['code'=>'ETH',     'token'=>'USDT',  'label'=>'Ethereum', 'icon'=>'Ξ', 'color'=>'#627EEA', 'sub'=>'ERC20 · ~$2'],
        ['code'=>'POLYGON', 'token'=>'USDT',  'label'=>'Polygon',  'icon'=>'⬡', 'color'=>'#8247E5', 'sub'=>'~$0.01'],
        ['code'=>'ETH',     'token'=>'ETH',   'label'=>'Ethereum', 'icon'=>'Ξ', 'color'=>'#627EEA', 'sub'=>'Native'],
      ];
      foreach ($chains as $i => $c):
      ?>
      <div class="chain-card <?=$i===0?'active':''?>"
           onclick="selChain('<?=$c['code']?>','<?=$c['token']?>',this)">
        <div class="chain-icon" style="color:<?=$c['color']?>"><?=$c['icon']?></div>
        <div class="chain-name"><?=$c['label']?></div>
        <div class="chain-token"><?=$c['token']?></div>
        <div class="chain-price"><?=$c['sub']?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Customer -->
  <div class="card">
    <div class="card-title"><i class="fas fa-user"></i> <?=$ar?'بيانات العميل':'Customer Details'?></div>
    <div class="fld">
      <label>Email</label>
      <input type="email" id="custEmail" placeholder="customer@example.com">
    </div>
    <div class="fld">
      <label><?=$ar?'رقم العميل (اختياري)':'Customer ID (optional)'?></label>
      <input type="text" id="custId" placeholder="user_123" value="<?=htmlspecialchars('dp_'.time())?>">
    </div>
  </div>

  <!-- Pay Button -->
  <button class="pay-btn" id="payBtn" onclick="createPayment()" <?=!$payramConnected?'disabled':''?>>
    <i class="fas fa-coins" id="payIco"></i>
    <span id="payLbl"><?=$ar?'إنشاء رابط الدفع':'Create Payment Link'?></span>
  </button>

  <!-- Deposit Info -->
  <div class="deposit-info" id="depositInfo">
    <div style="font-size:.78rem;font-weight:800;color:var(--green);margin-bottom:8px">
      <i class="fas fa-check-circle"></i> <?=$ar?'تم إنشاء الدفعة':'Payment Created'?>
    </div>
    <div style="font-size:.72rem;color:var(--muted2);margin-bottom:4px"><?=$ar?'عنوان الإيداع:':'Deposit Address:'?></div>
    <div class="deposit-addr" id="depositAddr">—</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
      <button class="copy-btn" onclick="copyAddr()"><i class="fas fa-copy"></i> <?=$ar?'نسخ':'Copy'?></button>
      <a id="payramLink" href="#" target="_blank" style="background:none;border:1px solid rgba(255,215,0,.3);border-radius:6px;padding:4px 10px;color:var(--gold);font-size:.68rem;text-decoration:none">
        <i class="fas fa-external-link-alt"></i> <?=$ar?'فتح صفحة الدفع':'Open Payment Page'?>
      </a>
    </div>
    <div style="font-size:.72rem;color:var(--muted2)">
      <?=$ar?'Reference:':'Reference:'?> <span id="payRef" style="font-family:monospace;color:var(--gold)">—</span>
    </div>
    <div id="statusPoll" style="margin-top:10px;font-size:.72rem;color:var(--muted2)"></div>
  </div>

  <!-- Status -->
  <div class="status-box" id="statusBox"></div>

</div>

<div id="toast"></div>
<input type="hidden" id="csrf" value="<?=htmlspecialchars($csrf)?>">
<input type="hidden" id="initAmount" value="<?=htmlspecialchars($amountUSD)?>">
<input type="hidden" id="initRef" value="<?=htmlspecialchars($ref)?>">

<script>
const AR = <?=$ar?'true':'false'?>;
let STATE = { chain:'TRX', token:'USDT', referenceId: null, pollTimer: null };

function selChain(code, token, el) {
  STATE.chain = code;
  STATE.token = token;
  document.querySelectorAll('.chain-card').forEach(c=>c.classList.remove('active'));
  el.classList.add('active');
}

async function createPayment() {
  const btn   = document.getElementById('payBtn');
  const email = document.getElementById('custEmail').value.trim();
  const custId= document.getElementById('custId').value.trim();
  const amount= parseFloat(document.getElementById('initAmount').value) || 0;

  if (!email) { toast(AR?'أدخل البريد الإلكتروني':'Enter email','error'); return; }
  if (amount <= 0) { toast(AR?'المبلغ غير صحيح':'Invalid amount','error'); return; }

  btn.disabled = true;
  document.getElementById('payIco').className = 'fas fa-spinner fa-spin';
  document.getElementById('payLbl').textContent = AR?'جاري الإنشاء...':'Creating...';

  try {
    const resp = await fetch('../api/payram_payment.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      credentials: 'include',
      body: JSON.stringify({
        action         : 'create',
        amount         : amount,
        currency       : 'USD',
        email          : email,
        customer_id    : custId,
        blockchain_code: STATE.chain,
        currency_code  : STATE.token,
        reference      : document.getElementById('initRef').value,
        csrf_token     : document.getElementById('csrf').value,
      }),
    });
    const data = await resp.json();

    if (data.success) {
      STATE.referenceId = data.reference_id;

      document.getElementById('depositInfo').classList.add('show');
      document.getElementById('depositAddr').textContent = data.deposit_address || (AR?'جاري التعيين...':'Assigning...');
      document.getElementById('payRef').textContent = data.reference_id;
      document.getElementById('payramLink').href = data.url || '#';

      /* إذا لم يكن عنوان الإيداع محدداً → عيّنه */
      if (!data.deposit_address) assignAddress(data.reference_id);

      toast(AR?'تم إنشاء الدفعة ✓':'Payment created ✓','success');
      startPolling(data.reference_id);
    } else {
      showStatus(data.message || 'Failed', 'error');
    }
  } catch(e) {
    showStatus(e.message || 'Error', 'error');
  } finally {
    btn.disabled = false;
    document.getElementById('payIco').className = 'fas fa-coins';
    document.getElementById('payLbl').textContent = AR?'إنشاء رابط الدفع':'Create Payment Link';
  }
}

async function assignAddress(refId) {
  try {
    const resp = await fetch('../api/payram_payment.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      credentials:'include',
      body: JSON.stringify({
        action          : 'assign_address',
        reference_id    : refId,
        blockchain_code : STATE.chain,
        csrf_token      : document.getElementById('csrf').value,
      }),
    });
    const d = await resp.json();
    if (d.address) document.getElementById('depositAddr').textContent = d.address;
  } catch(e) {}
}

function startPolling(refId) {
  clearInterval(STATE.pollTimer);
  let attempts = 0;
  STATE.pollTimer = setInterval(async () => {
    attempts++;
    if (attempts > 60) { clearInterval(STATE.pollTimer); return; }
    try {
      const resp = await fetch(`../api/payram_payment.php?action=status&ref=${refId}&csrf_token=${document.getElementById('csrf').value}`, {credentials:'include'});
      const d = await resp.json();
      const s = d.status || 'UNKNOWN';
      document.getElementById('statusPoll').textContent = `Status: ${s} · ${new Date().toLocaleTimeString()}`;

      if (s === 'FILLED' || s === 'OVER_FILLED') {
        clearInterval(STATE.pollTimer);
        showStatus(AR?'✅ تم الدفع بنجاح!':'✅ Payment received!','success');
        toast(AR?'تم الدفع ✓':'Paid ✓','success');
      } else if (s === 'CANCELLED') {
        clearInterval(STATE.pollTimer);
        showStatus(AR?'❌ انتهت صلاحية الدفعة':'❌ Payment cancelled','error');
      } else if (s === 'PARTIALLY_FILLED') {
        document.getElementById('statusPoll').textContent = `⚠️ Partial — ${s}`;
      }
    } catch(e) {}
  }, 5000); // كل 5 ثوانٍ
}

function copyAddr() {
  const addr = document.getElementById('depositAddr').textContent;
  navigator.clipboard?.writeText(addr).then(() => toast(AR?'تم النسخ':'Copied','success'));
}

function showStatus(msg, type) {
  const box = document.getElementById('statusBox');
  box.className = 'status-box show status-' + type;
  box.textContent = msg;
}

function toast(msg, type='info') {
  const t = document.getElementById('toast');
  const c = {success:'var(--green)',error:'var(--red)',info:'var(--gold)'};
  t.style.color = c[type]||c.info;
  t.style.borderColor = c[type]||c.info;
  t.textContent = msg;
  t.style.transform = 'translateX(-50%) translateY(0)';
  clearTimeout(t._t);
  t._t = setTimeout(()=>{t.style.transform='translateX(-50%) translateY(100px)';},4000);
}
</script>
</body>
</html>
