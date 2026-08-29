<?php
/**
 * DI PARMA | Gateway Control Center
 * لوحة تحكم الـ Orchestrator — بوابات + بنوك + 18 POS
 */
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/lib/DIPARMAOrchestrator.php';

$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang']==='en' ? 'en' : 'ar';
$ar   = ($lang==='ar'); $dir=$ar?'rtl':'ltr';
$csrf = generateCsrfToken();

$orch = DIPARMAOrchestrator::getInstance();
$posList = $orch->getPOSList();
?><!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$dir?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | Gateway Control Center</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--gold:#FFD700;--gold2:#FFB700;--bg:#020508;--card:#070e1c;--card2:#0a1224;--border:rgba(255,215,0,.11);--border2:rgba(255,215,0,.26);--text:#edf0f7;--muted:#3d4a5c;--muted2:#6b7a90;--green:#10B981;--red:#EF4444;--blue:#3B82F6;--purple:#8B5CF6;--orange:#F97316}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.topbar{height:54px;background:rgba(2,5,8,.97);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 24px;position:sticky;top:0;z-index:100}
.tb-brand{color:var(--gold);font-weight:900;font-size:.95rem;display:flex;align-items:center;gap:8px}
.tb-link{color:var(--muted2);font-size:.75rem;padding:5px 10px;border-radius:14px;text-decoration:none}
.tb-link:hover{color:var(--gold)}
.wrap{max-width:1400px;margin:0 auto;padding:24px}
.page-title{font-size:1.3rem;font-weight:900;background:linear-gradient(135deg,var(--gold),#fff8c0);-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:4px}
.page-sub{font-size:.75rem;color:var(--muted2);margin-bottom:24px}

/* Stats */
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:16px;text-align:center}
.stat-val{font-size:1.6rem;font-weight:900;color:var(--gold)}
.stat-lbl{font-size:.68rem;color:var(--muted2);margin-top:3px}

/* Processors Grid */
.section-title{font-size:.72rem;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:1.3px;margin-bottom:12px;display:flex;align-items:center;gap:6px}
.proc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;margin-bottom:24px}
.proc-card{background:var(--card);border:1.5px solid var(--border);border-radius:14px;padding:14px;cursor:pointer;transition:.2s}
.proc-card:hover{transform:translateY(-2px);border-color:rgba(255,215,0,.25)}
.proc-card.selected{border-color:var(--gold);background:rgba(255,215,0,.05)}
.proc-icon{font-size:1.5rem;margin-bottom:8px}
.proc-name{font-size:.82rem;font-weight:800;margin-bottom:3px}
.proc-type{font-size:.65rem;color:var(--muted2)}
.proc-badge{display:inline-block;font-size:.58rem;padding:2px 7px;border-radius:5px;font-weight:700;margin-top:5px}
.badge-card{background:rgba(59,130,246,.12);color:var(--blue)}
.badge-bank{background:rgba(255,215,0,.1);color:var(--gold)}
.badge-crypto{background:rgba(16,185,129,.1);color:var(--green)}
.badge-pos{background:rgba(249,115,22,.1);color:var(--orange)}

/* POS Grid */
.pos-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px;margin-bottom:24px}
.pos-card{background:var(--card2);border:1.5px solid var(--border);border-radius:12px;padding:12px;cursor:pointer;transition:.2s}
.pos-card:hover{border-color:rgba(249,115,22,.3)}
.pos-card.selected{border-color:var(--orange);background:rgba(249,115,22,.05)}
.pos-id{font-size:.62rem;font-family:monospace;color:var(--muted2)}
.pos-name{font-size:.75rem;font-weight:800;margin:3px 0}
.pos-loc{font-size:.62rem;color:var(--muted2)}
.pos-gw{font-size:.58rem;color:var(--orange);margin-top:4px}
.pos-dot{display:inline-block;width:6px;height:6px;border-radius:50%;background:var(--green);margin-right:4px}

/* TXN Form */
.form-card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:20px;margin-bottom:20px}
.form-title{font-size:.8rem;font-weight:800;color:var(--gold);margin-bottom:16px;display:flex;align-items:center;gap:6px}
.txn-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:7px;margin-bottom:16px}
.txn-btn{background:var(--card2);border:1.5px solid var(--border);border-radius:11px;padding:8px 4px;cursor:pointer;text-align:center;transition:.2s}
.txn-btn:hover{border-color:rgba(255,215,0,.2)}
.txn-btn.active{border-color:var(--gold);background:rgba(255,215,0,.06)}
.txn-ico{font-size:.85rem;margin-bottom:2px}
.txn-nm{font-size:.57rem;font-weight:800;color:var(--muted2);line-height:1.3}
.txn-btn.active .txn-nm{color:var(--gold)}
.fld{margin-bottom:12px}
.fld label{display:block;font-size:.67rem;color:var(--muted2);margin-bottom:4px;font-weight:700}
.fld input,.fld select{width:100%;background:rgba(255,255,255,.04);border:1.5px solid var(--border);border-radius:10px;padding:10px 12px;color:var(--text);font-family:'Cairo',sans-serif;font-size:.83rem}
.fld input:focus,.fld select:focus{outline:none;border-color:var(--gold)}
.fld-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.sec-row{display:flex;gap:7px;margin-bottom:14px}
.sec-btn{flex:1;padding:8px;border-radius:9px;border:1.5px solid var(--border);background:rgba(255,255,255,.03);cursor:pointer;text-align:center;font-size:.72rem;font-weight:700;color:var(--muted2)}
.sec-btn.active{border-color:var(--gold);background:rgba(255,215,0,.06);color:var(--gold)}
.orig-wrap{display:none;margin-bottom:12px}
.orig-wrap.show{display:block}
.proc-btn{width:100%;padding:14px;border-radius:13px;border:none;cursor:pointer;font-family:'Cairo',sans-serif;font-size:.95rem;font-weight:900;background:linear-gradient(135deg,var(--gold),var(--gold2));color:#000;box-shadow:0 8px 24px rgba(255,215,0,.22);transition:.3s;display:flex;align-items:center;justify-content:center;gap:10px}
.proc-btn:hover:not(:disabled){transform:translateY(-2px)}
.proc-btn:disabled{opacity:.35;cursor:not-allowed;transform:none}

/* Result */
.result-box{background:var(--card2);border:1px solid var(--border2);border-radius:14px;padding:16px;margin-top:16px;display:none}
.result-box.show{display:block}
.res-title{font-size:.88rem;font-weight:800;margin-bottom:10px}
.res-row{display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:.75rem}
.res-row:last-child{border:none}
.res-key{color:var(--muted2)}
.res-val{font-weight:700}

/* Toast */
#toast{position:fixed;bottom:22px;left:50%;transform:translateX(-50%) translateY(100px);background:var(--card);border:1px solid var(--border2);border-radius:13px;padding:10px 22px;font-size:.8rem;font-weight:700;z-index:9999;transition:.35s}

@media(max-width:600px){.stats-grid{grid-template-columns:1fr 1fr}.txn-grid{grid-template-columns:repeat(3,1fr)}.proc-grid{grid-template-columns:1fr 1fr}.pos-grid{grid-template-columns:1fr 1fr}}
</style>
</head>
<body>
<header class="topbar">
  <div class="tb-brand"><i class="fas fa-coins"></i> DI PARMA <span style="color:var(--muted);margin:0 4px">|</span> Gateway Control</div>
  <div style="display:flex;gap:8px">
    <a href="checkout_router.php" class="tb-link"><i class="fas fa-credit-card"></i></a>
    <a href="dashboard.php" class="tb-link"><i class="fas fa-th-large"></i></a>
  </div>
</header>

<div class="wrap">
  <div class="page-title"><i class="fas fa-network-wired"></i> <?=$ar?'مركز تحكم البوابات':'Gateway Control Center'?></div>
  <div class="page-sub"><?=$ar?'توزيع ذكي على بوابات + بنوك + 18 POS Terminal':'Smart routing across gateways + banks + 18 POS Terminals'?></div>

  <!-- Stats -->
  <div class="stats-grid">
    <div class="stat-card"><div class="stat-val" id="statGW">5</div><div class="stat-lbl"><?=$ar?'بوابات دفع':'Payment Gateways'?></div></div>
    <div class="stat-card"><div class="stat-val" id="statBanks">4</div><div class="stat-lbl"><?=$ar?'بنوك مباشرة':'Direct Banks'?></div></div>
    <div class="stat-card"><div class="stat-val" id="statPOS"><?=count($posList)?></div><div class="stat-lbl"><?=$ar?'POS Terminals':'POS Terminals'?></div></div>
    <div class="stat-card"><div class="stat-val" style="color:var(--green)" id="statTxn">—</div><div class="stat-lbl"><?=$ar?'عمليات اليوم':'Today\'s TXNs'?></div></div>
  </div>

  <!-- Processors -->
  <div class="section-title"><i class="fas fa-plug"></i> <?=$ar?'اختر الـ Processor':'Select Processor'?> <span style="opacity:.5">(<?=$ar?'أو اتركه فارغاً للتوزيع التلقائي':'or leave empty for auto-routing'?>)</span></div>
  <div class="proc-grid" id="procGrid">
    <!-- بوابات -->
    <div class="proc-card" onclick="selProc('',this)" id="proc-auto">
      <div class="proc-icon">🤖</div>
      <div class="proc-name"><?=$ar?'تلقائي':'Auto Route'?></div>
      <div class="proc-type"><?=$ar?'DI PARMA يختار':'DI PARMA decides'?></div>
      <span class="proc-badge badge-card">SMART</span>
    </div>
    <div class="proc-card" onclick="selProc('nuvei',this)" id="proc-nuvei">
      <div class="proc-icon" style="color:#F97316">⬡</div>
      <div class="proc-name">Nuvei × Mashreq</div>
      <div class="proc-type">USD/AED/EUR/GBP/SAR</div>
      <span class="proc-badge badge-card">CARD</span>
    </div>
    <div class="proc-card" onclick="selProc('stripe',this)" id="proc-stripe">
      <div class="proc-icon" style="color:#6772e5">S</div>
      <div class="proc-name">Stripe</div>
      <div class="proc-type">USD/EUR/GBP/AED</div>
      <span class="proc-badge badge-card">CARD</span>
    </div>
    <div class="proc-card" onclick="selProc('paypal',this)" id="proc-paypal">
      <div class="proc-icon" style="color:#003087"><i class="fab fa-paypal"></i></div>
      <div class="proc-name">PayPal</div>
      <div class="proc-type">USD/EUR/GBP</div>
      <span class="proc-badge badge-card">CARD</span>
    </div>
    <div class="proc-card" onclick="selProc('myfatoorah',this)" id="proc-myfatoorah">
      <div class="proc-icon" style="color:#00b09b">⬡</div>
      <div class="proc-name">MyFatoorah</div>
      <div class="proc-type">AED/SAR/KWD/QAR/EGP</div>
      <span class="proc-badge badge-card">CARD</span>
    </div>
    <div class="proc-card" onclick="selProc('wise',this)" id="proc-wise">
      <div class="proc-icon" style="color:#9fe870"><i class="fas fa-exchange-alt"></i></div>
      <div class="proc-name">Wise</div>
      <div class="proc-type">USD/EUR/GBP/AED</div>
      <span class="proc-badge badge-bank">BANK</span>
    </div>
    <!-- بنوك -->
    <div class="proc-card" onclick="selProc('bank:mashreq',this)" id="proc-bank-mashreq">
      <div class="proc-icon" style="color:#FF6600"><i class="fas fa-university"></i></div>
      <div class="proc-name">Mashreq Bank</div>
      <div class="proc-type">AED — TRANSCENDIO</div>
      <span class="proc-badge badge-bank">BANK</span>
    </div>
    <div class="proc-card" onclick="selProc('bank:hsbc',this)" id="proc-bank-hsbc">
      <div class="proc-icon" style="color:#DB0011"><i class="fas fa-university"></i></div>
      <div class="proc-name">HSBC UAE</div>
      <div class="proc-type">AED — Middle East</div>
      <span class="proc-badge badge-bank">BANK</span>
    </div>
    <div class="proc-card" onclick="selProc('bank:nbe',this)" id="proc-bank-nbe">
      <div class="proc-icon" style="color:#006633"><i class="fas fa-landmark"></i></div>
      <div class="proc-name">NBE Egypt</div>
      <div class="proc-type">EGP — National Bank</div>
      <span class="proc-badge badge-bank">BANK</span>
    </div>
    <div class="proc-card" onclick="selProc('bank:jpmorgan',this)" id="proc-bank-jpmorgan">
      <div class="proc-icon" style="color:#003087"><i class="fas fa-landmark"></i></div>
      <div class="proc-name">JP Morgan IOLTA</div>
      <div class="proc-type">USD — New York</div>
      <span class="proc-badge badge-bank">BANK</span>
    </div>
    <!-- Crypto -->
    <div class="proc-card" onclick="selProc('binance',this)" id="proc-binance">
      <div class="proc-icon" style="color:#F3BA2F"><i class="fas fa-coins"></i></div>
      <div class="proc-name">Binance Pay</div>
      <div class="proc-type">USDT/BNB/USD</div>
      <span class="proc-badge badge-crypto">CRYPTO</span>
    </div>
    <div class="proc-card" onclick="selProc('gate_io',this)" id="proc-gate_io">
      <div class="proc-icon" style="color:#E8112D"><i class="fas fa-coins"></i></div>
      <div class="proc-name">Gate.io</div>
      <div class="proc-type">USDT/USD</div>
      <span class="proc-badge badge-crypto">CRYPTO</span>
    </div>
  </div>

  <!-- POS Terminals -->
  <div class="section-title"><i class="fas fa-tablet-alt"></i> <?=$ar?'18 POS Terminal':'18 POS Terminals'?> <span style="opacity:.5">(<?=$ar?'اختياري':'optional'?>)</span></div>
  <div class="pos-grid" id="posGrid">
    <?php foreach($posList as $pid=>$pos): ?>
    <div class="pos-card" onclick="selPOS('<?=$pid?>',this)" id="pos-<?=$pid?>">
      <div class="pos-dot"></div><span class="pos-id"><?=$pid?></span>
      <div class="pos-name"><?=htmlspecialchars($pos['name'])?></div>
      <div class="pos-loc"><i class="fas fa-map-marker-alt" style="color:var(--muted2);font-size:.6rem"></i> <?=htmlspecialchars($pos['location'])?></div>
      <div class="pos-gw"><?=$pos['type']?> · <?=$pos['gateway']?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- TXN Form -->
  <div class="form-card">
    <div class="form-title"><i class="fas fa-list"></i> <?=$ar?'نوع العملية + بيانات البطاقة':'Transaction Type + Card Details'?></div>

    <!-- 10 أنواع -->
    <div class="txn-grid" id="txnGrid">
      <?php
      $TXN=[
        'purchase'        =>['ar'=>'شراء',          'en'=>'Purchase',       'icon'=>'fa-credit-card',  'c'=>'#10B981','sub'=>'2D/3D','orig'=>false],
        'auth'            =>['ar'=>'تفويض',          'en'=>'Auth Hold',      'icon'=>'fa-shield-alt',   'c'=>'#3B82F6','sub'=>'Hold', 'orig'=>false],
        'auth_complete'   =>['ar'=>'إتمام تفويض',   'en'=>'Capture',        'icon'=>'fa-check-double', 'c'=>'#6366F1','sub'=>'Cap',  'orig'=>true ],
        'purchase_advice' =>['ar'=>'إشعار شراء',    'en'=>'Advice',         'icon'=>'fa-bell',         'c'=>'#F59E0B','sub'=>'Off',  'orig'=>false],
        'refund'          =>['ar'=>'استرداد',        'en'=>'Refund',         'icon'=>'fa-undo',         'c'=>'#EF4444','sub'=>'Ret',  'orig'=>true ],
        'reversal'        =>['ar'=>'إلغاء عملية',   'en'=>'Reversal',       'icon'=>'fa-reply',        'c'=>'#EC4899','sub'=>'Rev',  'orig'=>true ],
        'balance'         =>['ar'=>'استعلام رصيد',  'en'=>'Balance',        'icon'=>'fa-wallet',       'c'=>'#8B5CF6','sub'=>'Inq',  'orig'=>false],
        'cash_advance'    =>['ar'=>'سلفة نقدية',    'en'=>'Cash Advance',   'icon'=>'fa-money-bill',   'c'=>'#14B8A6','sub'=>'Adv',  'orig'=>false],
        'void'            =>['ar'=>'إلغاء اليوم',   'en'=>'Void',           'icon'=>'fa-ban',          'c'=>'#6B7280','sub'=>'Void', 'orig'=>true ],
        'settlement'      =>['ar'=>'تسوية',          'en'=>'Settlement',     'icon'=>'fa-university',   'c'=>'#FFD700','sub'=>'EOD',  'orig'=>false],
      ];
      foreach($TXN as $code=>$t):
      ?>
      <div class="txn-btn <?=$code==='purchase'?'active':''?>"
           onclick="selTxn('<?=$code?>',this)"
           data-orig="<?=$t['orig']?'1':'0'?>">
        <div class="txn-ico"><i class="fas <?=$t['icon']?>" style="color:<?=$t['c']?>"></i></div>
        <div class="txn-nm"><?=$ar?$t['ar']:$t['en']?></div>
        <div style="font-size:.5rem;color:var(--muted)"><?=$t['sub']?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- 3D/2D -->
    <div class="sec-row">
      <div class="sec-btn active" id="sec3D" onclick="selSec('3D',this)"><i class="fas fa-shield-alt"></i> 3D Secure</div>
      <div class="sec-btn" id="sec2D" onclick="selSec('2D',this)"><i class="fas fa-credit-card"></i> 2D / MOTO</div>
    </div>

    <!-- Orig Ref -->
    <div class="orig-wrap" id="origWrap">
      <div class="fld">
        <label><i class="fas fa-hashtag"></i> <?=$ar?'رقم المرجع الأصلي':'Original Reference (RRN/Approval)'?></label>
        <input type="text" id="origRef" placeholder="<?=$ar?'RRN / Approval Code':'RRN / Approval Code'?>">
      </div>
    </div>

    <!-- Card -->
    <div class="fld-row">
      <div class="fld" style="grid-column:span 2">
        <label><?=$ar?'رقم البطاقة':'Card Number'?></label>
        <input type="tel" id="cardNum" maxlength="19" placeholder="•••• •••• •••• ••••"
               oninput="let v=this.value.replace(/\D/g,'').substring(0,16);this.value=v.replace(/(.{4})/g,'$1 ').trim()">
      </div>
    </div>
    <div class="fld-row">
      <div class="fld">
        <label><?=$ar?'تاريخ الانتهاء':'Expiry'?></label>
        <input type="tel" id="cardExp" maxlength="5" placeholder="MM/YY"
               oninput="let v=this.value.replace(/\D/g,'');if(v.length>=2)v=v.substring(0,2)+'/'+v.substring(2,4);this.value=v">
      </div>
      <div class="fld"><label>CVV</label><input type="tel" id="cardCvv" maxlength="4" placeholder="•••"></div>
    </div>
    <div class="fld-row">
      <div class="fld">
        <label><?=$ar?'اسم حامل البطاقة':'Cardholder Name'?></label>
        <input type="text" id="cardName" placeholder="FULL NAME">
      </div>
      <div class="fld">
        <label><?=$ar?'البريد الإلكتروني':'Email'?></label>
        <input type="email" id="email" placeholder="client@example.com">
      </div>
    </div>
    <div class="fld-row">
      <div class="fld">
        <label><?=$ar?'المبلغ':'Amount'?></label>
        <input type="number" id="amount" min="0.01" step="0.01" placeholder="0.00">
      </div>
      <div class="fld">
        <label><?=$ar?'العملة':'Currency'?></label>
        <select id="currency">
          <option>USD</option><option>AED</option><option>SAR</option><option>EUR</option>
          <option>GBP</option><option>KWD</option><option>EGP</option><option>QAR</option>
          <option>USDT</option><option>TRX</option>
        </select>
      </div>
    </div>
    <div class="fld">
      <label><?=$ar?'وجهة Ledger (TRX)':'Ledger Address (TRX)'?></label>
      <input type="text" id="ledgerAddr" value="TEwLFWlwK55b7PuFfzgH1H2f3xs3pLgLn2">
    </div>

    <button class="proc-btn" id="procBtn" onclick="runTransaction()">
      <i class="fas fa-network-wired" id="procIco"></i>
      <span id="procLbl"><?=$ar?'تنفيذ عبر DI PARMA Orchestrator':'Process via DI PARMA Orchestrator'?></span>
    </button>

    <!-- Result -->
    <div class="result-box" id="resultBox">
      <div class="res-title" id="resTitle"></div>
      <div id="resRows"></div>
    </div>
  </div>
</div>

<div id="toast"></div>
<input type="hidden" id="csrf" value="<?=htmlspecialchars($csrf)?>">

<script>
const AR = <?=$ar?'true':'false'?>;
const S  = { txn:'purchase', sec:'3D', proc:'', pos:'' };

function selProc(code, el) {
  S.proc = code;
  document.querySelectorAll('.proc-card').forEach(c=>c.classList.remove('selected'));
  el.classList.add('selected');
}

function selPOS(id, el) {
  S.pos = S.pos === id ? '' : id;
  document.querySelectorAll('.pos-card').forEach(c=>c.classList.remove('selected'));
  if (S.pos) el.classList.add('selected');
}

function selTxn(code, el) {
  S.txn = code;
  document.querySelectorAll('.txn-btn').forEach(b=>b.classList.remove('active'));
  el.classList.add('active');
  const needOrig = el.dataset.orig === '1';
  document.getElementById('origWrap').className = 'orig-wrap'+(needOrig?' show':'');
}

function selSec(mode, el) {
  S.sec = mode;
  document.getElementById('sec3D').className = 'sec-btn'+(mode==='3D'?' active':'');
  document.getElementById('sec2D').className = 'sec-btn'+(mode==='2D'?' active':'');
}

async function runTransaction() {
  const btn = document.getElementById('procBtn');
  const amount = parseFloat(document.getElementById('amount').value)||0;
  const noAmt  = ['balance','settlement'].includes(S.txn);

  if (!noAmt && amount <= 0) { toast(AR?'أدخل المبلغ':'Enter amount','error'); return; }

  btn.disabled = true;
  document.getElementById('procIco').className = 'fas fa-spinner fa-spin';
  document.getElementById('procLbl').textContent = AR?'جاري التنفيذ...':'Processing...';

  const payload = {
    txn_type    : S.txn,
    sec_mode    : S.sec,
    gateway     : S.proc.startsWith('bank:') ? '' : S.proc,
    amount      : amount,
    currency    : document.getElementById('currency').value,
    card_number : document.getElementById('cardNum').value.replace(/\s/g,''),
    card_name   : document.getElementById('cardName').value,
    card_expiry : document.getElementById('cardExp').value,
    card_cvv    : document.getElementById('cardCvv').value,
    email       : document.getElementById('email').value || 'client@diparmas.com',
    orig_ref    : document.getElementById('origRef').value,
    ledger_addr : document.getElementById('ledgerAddr').value,
    pos_id      : S.pos || null,
    csrf_token  : document.getElementById('csrf').value,
  };

  // إذا اختار bank مباشر
  if (S.proc.startsWith('bank:')) payload.gateway = S.proc;

  try {
    const r = await fetch('/api/diparma_process.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      credentials:'include',
      body:JSON.stringify(payload),
    });
    const d = await r.json();
    showResult(d);
  } catch(e) {
    toast(e.message||'Error','error');
  } finally {
    btn.disabled = false;
    document.getElementById('procIco').className = 'fas fa-network-wired';
    document.getElementById('procLbl').textContent = AR?'تنفيذ عبر DI PARMA Orchestrator':'Process via DI PARMA Orchestrator';
  }
}

function showResult(d) {
  const box   = document.getElementById('resultBox');
  const title = document.getElementById('resTitle');
  const rows  = document.getElementById('resRows');

  box.classList.add('show');
  title.textContent = d.success
    ? (AR?'✅ تمت العملية بنجاح':'✅ Transaction Successful')
    : (AR?'❌ فشلت العملية':'❌ Transaction Failed');
  title.style.color = d.success ? 'var(--green)' : 'var(--red)';

  const items = [
    [AR?'المرجع':'Reference',       d.reference],
    [AR?'الـ Processor':'Processor', d.processor_name || d.processor],
    [AR?'POS':'POS',                 d.pos_name || '—'],
    [AR?'المبلغ':'Amount',           d.amount ? d.amount+' '+d.currency : '—'],
    [AR?'Auth Code':'Auth Code',     d.approval_code || '—'],
    [AR?'RRN':'RRN',                 d.rrn || '—'],
    [AR?'رسالة':'Message',           d.message || '—'],
    [AR?'Fallback':'Fallback',        d.fallback_used ? (AR?'نعم':'Yes')+' ← '+d.fallback_from : '—'],
  ];

  rows.innerHTML = items.map(([k,v])=>
    `<div class="res-row"><span class="res-key">${k}</span><span class="res-val">${v||'—'}</span></div>`
  ).join('');

  toast(d.success
    ? (AR?'تمت العملية':'Transaction completed')
    : (d.message||'Failed'),
    d.success?'success':'error'
  );
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

// تحديد auto بشكل افتراضي
document.getElementById('proc-auto').classList.add('selected');

// إحصائيات اليوم
fetch('/api/wallet.php?action=recent_ledger&limit=1')
  .then(r=>r.json())
  .catch(()=>({transactions:[]}))
  .then(d=>{
    document.getElementById('statTxn').textContent = d.transactions?.length >= 0 ? d.transactions.length : '—';
  });
</script>
</body>
</html>
