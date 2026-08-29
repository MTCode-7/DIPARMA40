<?php
/**
 * ============================================================
 * DI PARMA | POS Terminal — نقطة البيع الكاملة
 * ============================================================
 */
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';

$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'en' ? 'en' : 'ar';
$ar   = ($lang === 'ar');
$dir  = $ar ? 'rtl' : 'ltr';
$csrf = generateCsrfToken();

// أنواع العمليات
$txnTypes = [
  'purchase'        => ['icon'=>'fa-credit-card',        'color'=>'#10B981', 'ar'=>'شراء',                'en'=>'Purchase'],
  'auth'            => ['icon'=>'fa-shield-alt',         'color'=>'#3B82F6', 'ar'=>'تفويض',               'en'=>'Authorization'],
  'auth_complete'   => ['icon'=>'fa-check-double',       'color'=>'#6366F1', 'ar'=>'إتمام تفويض',         'en'=>'Auth Completion'],
  'purchase_advice' => ['icon'=>'fa-bell',               'color'=>'#F59E0B', 'ar'=>'إشعار شراء',          'en'=>'Purchase Advice'],
  'refund'          => ['icon'=>'fa-undo',               'color'=>'#EF4444', 'ar'=>'استرداد',             'en'=>'Refund'],
  'reversal'        => ['icon'=>'fa-reply',              'color'=>'#EC4899', 'ar'=>'إلغاء عملية',        'en'=>'Reversal'],
  'balance'         => ['icon'=>'fa-wallet',             'color'=>'#8B5CF6', 'ar'=>'استعلام رصيد',       'en'=>'Balance Inquiry'],
  'cash_advance'    => ['icon'=>'fa-money-bill',         'color'=>'#14B8A6', 'ar'=>'سلفة نقدية',         'en'=>'Cash Advance'],
  'void'            => ['icon'=>'fa-ban',                'color'=>'#6B7280', 'ar'=>'إلغاء',              'en'=>'Void'],
  'settlement'      => ['icon'=>'fa-university',         'color'=>'#FFD700', 'ar'=>'تسوية',               'en'=>'Settlement'],
  'withdrawal_physical' => ['icon'=>'fa-sim-card',       'color'=>'#F97316', 'ar'=>'سحب فيزيائي (POS)',   'en'=>'Physical Withdrawal'],
  'withdrawal_manual'   => ['icon'=>'fa-hand-holding-usd','color'=>'#A78BFA','ar'=>'سحب يدوي (Manual)',   'en'=>'Manual Withdrawal'],
];
$currencies = ['USD','AED','SAR','EUR','GBP','KWD','BHD','QAR','OMR'];
?><!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$dir?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | POS Terminal</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&family=Share+Tech+Mono&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{
  --gold:#FFD700;--gold2:#FFB700;
  --bg:#020508;--bg2:#050a10;--bg3:#070e18;
  --card:#090f1e;--card2:#0b1224;
  --border:rgba(255,215,0,.12);--border2:rgba(255,215,0,.28);
  --text:#edf0f7;--muted:#4a5568;--muted2:#718096;
  --green:#10B981;--red:#EF4444;--blue:#3B82F6;
  --pos-screen:#001a08;--pos-digit:#00FF41;
}
html,body{min-height:100vh;font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text)}
/* ── Topbar ── */
.topbar{background:rgba(2,5,8,.97);border-bottom:1px solid var(--border);
  height:58px;display:flex;align-items:center;justify-content:space-between;
  padding:0 24px;position:sticky;top:0;z-index:100}
.tb-brand{display:flex;align-items:center;gap:10px;color:var(--gold);font-weight:900}
.tb-badge{background:#000;border:1.5px solid #333;border-radius:8px;
  padding:4px 12px;font-size:.72rem;font-weight:800;color:#fff;
  display:flex;align-items:center;gap:6px}
.tb-nav a{color:var(--muted2);font-size:.78rem;padding:6px 12px;
  border-radius:18px;text-decoration:none;transition:.2s}
.tb-nav a:hover{color:var(--gold)}
</style>
<style>
/* ── Layout ── */
.layout{display:grid;grid-template-columns:320px 1fr 300px;gap:0;min-height:calc(100vh - 58px)}
/* ── Left Panel — Transaction Types ── */
.left-panel{background:var(--bg2);border-right:1px solid var(--border);padding:16px;overflow-y:auto}
.panel-title{font-size:.65rem;font-weight:800;color:var(--muted);text-transform:uppercase;
  letter-spacing:1.5px;margin-bottom:12px;padding:0 4px}
.txn-btn{display:flex;align-items:center;gap:10px;width:100%;padding:11px 12px;
  border-radius:12px;border:1.5px solid transparent;background:rgba(255,255,255,.03);
  color:var(--muted2);font-family:'Cairo',sans-serif;font-size:.82rem;font-weight:600;
  cursor:pointer;transition:.2s;margin-bottom:6px;text-align:<?=$ar?'right':'left'?>}
.txn-btn:hover{background:rgba(255,255,255,.06);color:var(--text)}
.txn-btn.active{border-color:var(--border2);background:rgba(255,215,0,.06);color:var(--gold)}
.txn-btn .t-icon{width:34px;height:34px;border-radius:9px;display:flex;
  align-items:center;justify-content:center;font-size:.88rem;flex-shrink:0}
/* ── Center Panel — POS Screen ── */
.center-panel{background:var(--bg3);padding:24px;overflow-y:auto}
/* POS Device Frame */
.pos-device{
  max-width:480px;margin:0 auto;
  background:linear-gradient(145deg,#1a1a2e,#0d0d1a);
  border-radius:24px;padding:20px;
  box-shadow:0 20px 60px rgba(0,0,0,.6),0 0 0 1px rgba(255,255,255,.05);
}
.pos-screen{
  background:var(--pos-screen);border-radius:14px;padding:16px 20px;
  margin-bottom:16px;min-height:140px;position:relative;
  border:2px solid #003010;box-shadow:inset 0 0 20px rgba(0,255,65,.05)
}
.pos-screen-header{display:flex;justify-content:space-between;align-items:center;
  margin-bottom:12px;padding-bottom:10px;border-bottom:1px solid rgba(0,255,65,.1)}
.pos-screen-title{color:var(--pos-digit);font-family:'Share Tech Mono',monospace;
  font-size:.8rem;letter-spacing:2px;text-transform:uppercase}
.pos-time{color:rgba(0,255,65,.5);font-family:'Share Tech Mono',monospace;font-size:.7rem}
.pos-amount-display{text-align:center;padding:10px 0}
.pos-amount-label{color:rgba(0,255,65,.5);font-family:'Share Tech Mono',monospace;
  font-size:.68rem;letter-spacing:2px;margin-bottom:6px}
.pos-amount-value{font-family:'Share Tech Mono',monospace;font-size:2.4rem;
  color:var(--pos-digit);letter-spacing:4px;text-shadow:0 0 20px rgba(0,255,65,.4)}
.pos-currency{font-family:'Share Tech Mono',monospace;font-size:.8rem;
  color:rgba(0,255,65,.6);margin-top:4px}
.pos-status{display:flex;align-items:center;justify-content:center;gap:8px;
  margin-top:10px;font-family:'Share Tech Mono',monospace;font-size:.72rem}
.pos-status-dot{width:6px;height:6px;border-radius:50%;background:var(--pos-digit);
  animation:blink 1s infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.2}}
</style>
<style>
/* ── Keypad ── */
.pos-keypad{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:14px}
.key-btn{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08);
  border-radius:10px;padding:14px 8px;color:var(--text);font-family:'Cairo',sans-serif;
  font-size:1rem;font-weight:700;cursor:pointer;transition:.15s;text-align:center}
.key-btn:hover{background:rgba(255,215,0,.1);border-color:var(--border)}
.key-btn:active{transform:scale(.95)}
.key-btn.key-clear{background:rgba(239,68,68,.1);color:var(--red);border-color:rgba(239,68,68,.2)}
.key-btn.key-enter{background:linear-gradient(135deg,var(--green),#059669);color:#fff;border:none;grid-column:span 1}
.key-btn.key-cancel{background:rgba(239,68,68,.15);color:var(--red);border-color:rgba(239,68,68,.3)}
/* ── Form Section ── */
.form-section{background:var(--card);border:1px solid var(--border);border-radius:16px;
  padding:20px;margin-top:16px}
.form-title{font-size:.82rem;font-weight:800;color:var(--gold);margin-bottom:16px;
  display:flex;align-items:center;gap:8px}
.fld{margin-bottom:13px}
.fld label{display:block;font-size:.72rem;color:var(--muted2);margin-bottom:5px;font-weight:700}
.fld input,.fld select{width:100%;background:rgba(255,255,255,.04);border:1.5px solid var(--border);
  border-radius:10px;padding:10px 14px;color:var(--text);font-family:'Cairo',sans-serif;
  font-size:.88rem;transition:.2s}
.fld input:focus,.fld select:focus{outline:none;border-color:var(--gold);background:rgba(255,215,0,.03)}
.fld-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
/* Card Display */
.card-display{background:linear-gradient(135deg,#1a1a3e,#0d0d2e);border-radius:14px;
  padding:18px;margin-bottom:16px;border:1px solid rgba(59,130,246,.2);position:relative;overflow:hidden}
.card-display::before{content:'';position:absolute;top:-20px;right:-20px;width:100px;height:100px;
  background:radial-gradient(circle,rgba(59,130,246,.1),transparent);border-radius:50%}
.card-chip{width:36px;height:28px;background:linear-gradient(135deg,#C8A84B,#A07830);
  border-radius:6px;margin-bottom:12px;display:grid;grid-template-columns:1fr 1fr;
  grid-template-rows:1fr 1fr;gap:2px;padding:4px}
.card-chip span{background:rgba(0,0,0,.3);border-radius:2px}
.card-number-display{font-family:'Share Tech Mono',monospace;font-size:1.05rem;
  letter-spacing:3px;color:rgba(255,255,255,.9);margin-bottom:8px}
.card-info-row{display:flex;justify-content:space-between;font-size:.7rem;color:rgba(255,255,255,.5)}
/* ── Right Panel — Receipt & Ledger ── */
.right-panel{background:var(--bg2);border-left:1px solid var(--border);padding:16px;overflow-y:auto}
/* Receipt */
.receipt{background:#fff;border-radius:12px;padding:16px;color:#000;font-family:'Share Tech Mono',monospace;
  font-size:.7rem;line-height:1.8;margin-bottom:14px}
.receipt-header{text-align:center;border-bottom:2px dashed #ccc;margin-bottom:10px;padding-bottom:10px}
.receipt-row{display:flex;justify-content:space-between;margin-bottom:2px}
.receipt-total{border-top:2px dashed #ccc;margin-top:8px;padding-top:8px;
  font-size:.82rem;font-weight:900}
.receipt-footer{text-align:center;margin-top:10px;font-size:.62rem;color:#666}
/* Ledger Card */
.ledger-card{background:rgba(255,215,0,.04);border:1px solid var(--border2);
  border-radius:14px;padding:14px;margin-bottom:12px}
.ledger-title{font-size:.75rem;font-weight:800;color:var(--gold);margin-bottom:10px;
  display:flex;align-items:center;gap:6px}
.ledger-addr{font-family:'Share Tech Mono',monospace;font-size:.64rem;color:var(--muted2);
  word-break:break-all;margin-bottom:8px;line-height:1.5}
.ledger-bal{font-size:1.1rem;font-weight:900;color:var(--green)}
/* Buttons */
.btn{display:inline-flex;align-items:center;gap:7px;padding:11px 20px;border-radius:11px;
  border:none;font-family:'Cairo',sans-serif;font-size:.84rem;font-weight:700;
  cursor:pointer;transition:.25s;text-decoration:none}
.btn-gold{background:linear-gradient(135deg,var(--gold),var(--gold2));color:#000;
  box-shadow:0 6px 20px rgba(255,215,0,.2)}
.btn-gold:hover{transform:translateY(-2px);box-shadow:0 10px 28px rgba(255,215,0,.3)}
.btn-green{background:rgba(16,185,129,.15);color:var(--green);border:1px solid rgba(16,185,129,.3)}
.btn-red{background:rgba(239,68,68,.12);color:var(--red);border:1px solid rgba(239,68,68,.3)}
.btn-dark{background:rgba(255,255,255,.06);color:var(--text);border:1.5px solid var(--border)}
.btn-full{width:100%;justify-content:center;margin-top:8px}
.btn:disabled{opacity:.45;cursor:not-allowed;transform:none!important}
/* Toast */
#toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(100px);
  background:var(--card);border:1px solid var(--border2);border-radius:14px;
  padding:12px 28px;font-size:.84rem;font-weight:700;z-index:9999;
  transition:.35s;color:var(--text);box-shadow:0 8px 32px rgba(0,0,0,.5)}
/* Result Modal */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.8);z-index:500;
  display:flex;align-items:center;justify-content:center;backdrop-filter:blur(6px)}
.modal-box{background:var(--card2);border:1px solid var(--border2);border-radius:20px;
  padding:32px;max-width:420px;width:90%;text-align:center}
.modal-icon{font-size:3.5rem;margin-bottom:14px}
.modal-title{font-size:1.2rem;font-weight:900;margin-bottom:8px}
.modal-ref{font-family:'Share Tech Mono',monospace;font-size:.72rem;color:var(--muted2);
  word-break:break-all;margin-bottom:16px}
.modal-details{background:rgba(255,255,255,.03);border-radius:12px;padding:14px;
  font-size:.78rem;margin-bottom:16px;text-align:<?=$ar?'right':'left'?>}
.modal-row{display:flex;justify-content:space-between;padding:5px 0;
  border-bottom:1px solid rgba(255,255,255,.04)}
.modal-row:last-child{border:none}
/* Responsive */
@media(max-width:1100px){.layout{grid-template-columns:260px 1fr 260px}}
@media(max-width:900px){.layout{grid-template-columns:1fr}.left-panel,.right-panel{display:none}}
</style>

<nav class="topbar">
  <div class="tb-brand">
    <i class="fas fa-coins"></i> DI PARMA
    <span style="color:var(--muted)">|</span>
    <div class="tb-badge"><i class="fas fa-cash-register"></i> POS Terminal</div>
  </div>
  <div class="tb-nav">
    <a href="ledger/"><i class="fas fa-wallet"></i> Ledger</a>
    <a href="dashboard.php"><i class="fas fa-th-large"></i> <?=$ar?'لوحة التحكم':'Dashboard'?></a>
  </div>
</nav>

<div class="layout">
<!-- ══ LEFT: Transaction Types ══ -->
<div class="left-panel">
  <div class="panel-title"><?=$ar?'نوع العملية':'Transaction Type'?></div>
  <?php foreach($txnTypes as $key => $tx): ?>
  <button class="txn-btn <?=$key==='purchase'?'active':''?>"
    onclick="selectTxnType('<?=$key?>',this)"
    data-type="<?=$key?>">
    <div class="t-icon" style="background:<?=$tx['color']?>22;color:<?=$tx['color']?>">
      <i class="fas <?=$tx['icon']?>"></i>
    </div>
    <span><?=$ar?$tx['ar']:$tx['en']?></span>
  </button>
  <?php endforeach; ?>

  <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border)">
    <div class="panel-title"><?=$ar?'الجهاز':'Device'?></div>
    <div style="background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:12px;padding:12px;font-size:.72rem">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
        <div style="width:8px;height:8px;border-radius:50%;background:var(--green);animation:blink 1.5s infinite"></div>
        <span style="color:var(--green);font-weight:700">Bitel IC3600</span>
      </div>
      <div style="color:var(--muted2)">USB <?=$ar?'متصل':'Connected'?></div>
      <div style="color:var(--muted2);margin-top:4px">COM<?=$ar?'':' Port'?> — <span style="color:var(--gold)" id="comPort">Auto</span></div>
    </div>
  </div>
</div>

<!-- ══ CENTER: POS Screen ══ -->
<div class="center-panel">
  <!-- POS Device -->
  <div class="pos-device">
    <div class="pos-screen">
      <div class="pos-screen-header">
        <div class="pos-screen-title" id="screenTitle">PURCHASE</div>
        <div class="pos-time" id="posTime">--:--:--</div>
      </div>
      <div class="pos-amount-display">
        <div class="pos-amount-label"><?=$ar?'المبلغ':'AMOUNT'?></div>
        <div class="pos-amount-value" id="amountDisplay">0.00</div>
        <div class="pos-currency" id="currencyDisplay">USD</div>
      </div>
      <div class="pos-status">
        <div class="pos-status-dot"></div>
        <span id="posStatusText" style="color:rgba(0,255,65,.6);font-family:'Share Tech Mono',monospace;font-size:.68rem">READY</span>
      </div>
    </div>

    <!-- Keypad -->
    <div class="pos-keypad">
      <?php for($i=1;$i<=9;$i++): ?>
      <button class="key-btn" onclick="keyPress('<?=$i?>')"><?=$i?></button>
      <?php endfor; ?>
      <button class="key-btn key-clear" onclick="keyPress('clear')">CLR</button>
      <button class="key-btn" onclick="keyPress('0')">0</button>
      <button class="key-btn" onclick="keyPress('.')">.</button>
      <button class="key-btn key-cancel" onclick="keyPress('cancel')">CANCEL</button>
      <button class="key-btn" onclick="keyPress('00')">00</button>
      <button class="key-btn key-enter" onclick="processTransaction()" style="background:linear-gradient(135deg,#10B981,#059669);color:#fff">ENTER</button>
    </div>
  </div>

  <!-- Card Details Form -->
  <div class="form-section" id="cardSection">
    <div class="form-title"><i class="fas fa-credit-card"></i> <?=$ar?'بيانات البطاقة':'Card Details'?></div>

    <!-- Card Visual -->
    <div class="card-display" id="cardDisplay">
      <div class="card-chip"><span></span><span></span><span></span><span></span></div>
      <div class="card-number-display" id="cardNumDisplay">•••• •••• •••• ••••</div>
      <div class="card-info-row">
        <span id="cardNameDisplay">CARDHOLDER NAME</span>
        <span id="cardExpDisplay">MM/YY</span>
      </div>
    </div>

    <div class="fld-row">
      <div class="fld" style="grid-column:span 2">
        <label><i class="fas fa-user"></i> <?=$ar?'اسم حامل البطاقة':'Cardholder Name'?></label>
        <input type="text" id="cardName" placeholder="<?=$ar?'الاسم كما على البطاقة':'Name as on card'?>"
          oninput="document.getElementById('cardNameDisplay').textContent=this.value||'CARDHOLDER NAME'">
      </div>
      <div class="fld" style="grid-column:span 2">
        <label><i class="fas fa-credit-card"></i> <?=$ar?'رقم البطاقة':'Card Number'?></label>
        <input type="text" id="cardNumber" maxlength="19" placeholder="0000 0000 0000 0000"
          oninput="formatCardNum(this)">
      </div>
      <div class="fld">
        <label><?=$ar?'تاريخ الانتهاء':'Expiry'?></label>
        <input type="text" id="cardExpiry" maxlength="5" placeholder="MM/YY"
          oninput="formatExp(this)">
      </div>
      <div class="fld">
        <label>CVV</label>
        <input type="password" id="cardCVV" maxlength="4" placeholder="•••">
      </div>
    </div>

    <div class="fld-row">
      <div class="fld">
        <label><?=$ar?'المبلغ':'Amount'?></label>
        <input type="number" id="txnAmount" min="0.01" step="0.01" placeholder="0.00"
          oninput="syncAmount(this.value)">
      </div>
      <div class="fld">
        <label><?=$ar?'العملة':'Currency'?></label>
        <select id="txnCurrency" onchange="document.getElementById('currencyDisplay').textContent=this.value">
          <?php foreach($currencies as $c): ?>
          <option value="<?=$c?>" <?=$c==='USD'?'selected':''?>><?=$c?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <!-- حقول خاصة ببعض العمليات -->
    <div id="extraFields"></div>

    <button class="btn btn-gold btn-full" id="processBtn" onclick="processTransaction()">
      <i class="fas fa-credit-card"></i> <span id="processBtnText"><?=$ar?'تنفيذ الشراء':'Process Purchase'?></span>
    </button>
  </div>
</div>

<!-- ══ RIGHT: Receipt & Ledger ══ -->
<div class="right-panel">
  <!-- Ledger Status -->
  <div class="ledger-card">
    <div class="ledger-title"><i class="fas fa-wallet"></i> Ledger TRX</div>
    <div class="ledger-addr" id="ledgerAddr">TEwLFWlwK55b7PuFfzgH1H2f3xs3pLgLn2</div>
    <div class="ledger-bal" id="ledgerBal">— USDT</div>
    <div style="font-size:.7rem;color:var(--muted2);margin-top:4px" id="ledgerTRX">— TRX</div>
    <button class="btn btn-dark btn-sm btn-full" style="margin-top:10px;font-size:.72rem"
      onclick="connectLedger()">
      <i class="fas fa-plug"></i> <?=$ar?'ربط Ledger':'Connect Ledger'?>
    </button>
  </div>

  <!-- Auto-transfer toggle -->
  <div style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:12px;margin-bottom:12px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
      <span style="font-size:.78rem;font-weight:700"><?=$ar?'تحويل تلقائي للـ Ledger':'Auto-transfer to Ledger'?></span>
      <label style="position:relative;display:inline-block;width:40px;height:22px">
        <input type="checkbox" id="autoTransfer" style="opacity:0;width:0;height:0" checked>
        <span onclick="this.previousElementSibling.click()" style="position:absolute;cursor:pointer;inset:0;
          background:#333;border-radius:11px;transition:.3s" id="toggleSlider"></span>
      </label>
    </div>
    <div style="font-size:.68rem;color:var(--muted2)"><?=$ar?'عند نجاح العملية يُحوّل USDT للـ Ledger':'On success, convert to USDT and send to Ledger'?></div>
  </div>

  <!-- Receipt -->
  <div class="panel-title"><?=$ar?'الإيصال':'Receipt'?></div>
  <div class="receipt" id="receiptBox">
    <div class="receipt-header">
      <div style="font-size:.9rem;font-weight:900">DI PARMA</div>
      <div>POS Terminal</div>
      <div id="receiptDate"><?=date('d/m/Y H:i')?></div>
    </div>
    <div class="receipt-row"><span><?=$ar?'النوع':'Type'?></span><span id="rType">—</span></div>
    <div class="receipt-row"><span><?=$ar?'المبلغ':'Amount'?></span><span id="rAmount">—</span></div>
    <div class="receipt-row"><span><?=$ar?'العملة':'Currency'?></span><span id="rCurrency">—</span></div>
    <div class="receipt-row"><span><?=$ar?'البطاقة':'Card'?></span><span id="rCard">—</span></div>
    <div class="receipt-row"><span>Ref</span><span id="rRef">—</span></div>
    <div class="receipt-row"><span>RRN</span><span id="rRRN">—</span></div>
    <div class="receipt-row"><span>Approval</span><span id="rApproval">—</span></div>
    <div class="receipt-total">
      <div class="receipt-row"><span><?=$ar?'الحالة':'Status'?></span><span id="rStatus">PENDING</span></div>
    </div>
    <div class="receipt-footer">
      <?=$ar?'شكراً لاستخدام DI PARMA':'Thank you for using DI PARMA'?>
      <br>diparmas.com
    </div>
  </div>

  <button class="btn btn-dark btn-full" onclick="printReceipt()" style="font-size:.76rem">
    <i class="fas fa-print"></i> <?=$ar?'طباعة الإيصال':'Print Receipt'?>
  </button>
</div>
</div><!-- /layout -->

<!-- Result Modal -->
<div class="modal-overlay hidden" id="resultModal">
  <div class="modal-box">
    <div class="modal-icon" id="modalIcon">✅</div>
    <div class="modal-title" id="modalTitle"></div>
    <div class="modal-ref" id="modalRef"></div>
    <div class="modal-details" id="modalDetails"></div>
    <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
      <button class="btn btn-dark" onclick="closeModal()"><i class="fas fa-times"></i> <?=$ar?'إغلاق':'Close'?></button>
      <button class="btn btn-gold" onclick="printReceipt()"><i class="fas fa-print"></i> <?=$ar?'طباعة':'Print'?></button>
      <button class="btn btn-green" id="ledgerTransferBtn" onclick="transferToLedger()" style="display:none">
        <i class="fas fa-wallet"></i> <?=$ar?'تحويل للـ Ledger':'Transfer to Ledger'?>
      </button>
    </div>
  </div>
</div>

<div id="toast"></div>

<script>
// ── State ──────────────────────────────────────────
const POS = {
  txnType: 'purchase',
  amount: '',
  currency: 'USD',
  ledgerConnected: false,
  ledgerAddress: 'TEwLFWlwK55b7PuFfzgH1H2f3xs3pLgLn2',
  lastTxn: null,
};

const TXN_LABELS = {
  purchase:             { ar:'شراء',              en:'Purchase' },
  auth:                 { ar:'تفويض',              en:'Authorization' },
  auth_complete:        { ar:'إتمام تفويض',        en:'Auth Completion' },
  purchase_advice:      { ar:'إشعار شراء',         en:'Purchase Advice' },
  refund:               { ar:'استرداد',            en:'Refund' },
  reversal:             { ar:'إلغاء عملية',       en:'Reversal' },
  balance:              { ar:'استعلام رصيد',      en:'Balance Inquiry' },
  cash_advance:         { ar:'سلفة نقدية',        en:'Cash Advance' },
  void:                 { ar:'إلغاء',             en:'Void' },
  settlement:           { ar:'تسوية',              en:'Settlement' },
  withdrawal_physical:  { ar:'سحب فيزيائي',       en:'Physical Withdrawal' },
  withdrawal_manual:    { ar:'سحب يدوي',          en:'Manual Withdrawal' },
};
const AR = <?=$ar?'true':'false'?>;
const CSRF = '<?=$csrf?>';

// ── Clock ──────────────────────────────────────────
function updateClock() {
  const now = new Date();
  document.getElementById('posTime').textContent =
    now.toLocaleTimeString('en-GB', {hour12:false});
}
setInterval(updateClock, 1000);
updateClock();

// ── Toggle Slider ──────────────────────────────────
document.getElementById('autoTransfer').addEventListener('change', function() {
  document.getElementById('toggleSlider').style.background = this.checked ? 'var(--green)' : '#333';
});
document.getElementById('toggleSlider').style.background = 'var(--green)';

// ── Transaction Type ───────────────────────────────
window.selectTxnType = function(type, el) {
  POS.txnType = type;
  document.querySelectorAll('.txn-btn').forEach(b => b.classList.remove('active'));
  el.classList.add('active');

  const label = TXN_LABELS[type];
  document.getElementById('screenTitle').textContent = (AR ? label.ar : label.en).toUpperCase();
  document.getElementById('processBtnText').textContent =
    (AR ? 'تنفيذ ' + label.ar : 'Process ' + label.en);

  // حقول إضافية بحسب النوع
  renderExtraFields(type);

  // إخفاء بيانات البطاقة لبعض العمليات
  const noCard = ['balance','settlement'];
  document.getElementById('cardSection').style.opacity = noCard.includes(type) ? '.5' : '1';
};

function renderExtraFields(type) {
  const el = document.getElementById('extraFields');
  let html = '';

  if (type === 'auth_complete' || type === 'reversal' || type === 'void' || type === 'refund') {
    html = `<div class="fld">
      <label><i class="fas fa-hashtag"></i> ${AR?'رقم المرجع الأصلي':'Original Reference / RRN'}</label>
      <input type="text" id="origRef" placeholder="${AR?'RRN أو Approval Code الأصلي':'Original RRN or Approval Code'}">
    </div>`;
  }

  if (type === 'purchase_advice') {
    html += `<div class="fld">
      <label><i class="fas fa-info-circle"></i> ${AR?'سبب الإشعار':'Advice Reason'}</label>
      <select id="adviceReason">
        <option value="timeout">${AR?'انتهاء الوقت':'Timeout'}</option>
        <option value="offline">${AR?'معاملة أوفلاين':'Offline Transaction'}</option>
        <option value="partial">${AR?'موافقة جزئية':'Partial Approval'}</option>
      </select>
    </div>`;
  }

  // ── سحب فيزيائي (POS) ─────────────────────────
  if (type === 'withdrawal_physical') {
    html = `
    <div style="background:rgba(249,115,22,.06);border:1px solid rgba(249,115,22,.2);border-radius:12px;padding:14px;margin-bottom:14px">
      <div style="color:#F97316;font-weight:800;font-size:.8rem;margin-bottom:10px;display:flex;align-items:center;gap:8px">
        <i class="fas fa-sim-card"></i> ${AR?'سحب عبر جهاز POS (Bitel IC3600)':'Physical Withdrawal via POS (Bitel IC3600)'}
      </div>
      <div style="font-size:.72rem;color:var(--muted2);line-height:1.7">
        ${AR?'• أدخل بيانات البطاقة<br>• الجهاز سيطلب الـ PIN تلقائياً<br>• المبلغ يُسحب من البطاقة للـ Ledger':'• Enter card details<br>• Device will prompt for PIN<br>• Amount withdrawn from card to Ledger'}
      </div>
    </div>
    <div class="fld">
      <label><i class="fas fa-map-marker-alt"></i> ${AR?'موقع جهاز الـ POS':'POS Terminal Location'}</label>
      <input type="text" id="posLocation" placeholder="${AR?'مثال: فرع دبي — الكاونتر 1':'e.g. Dubai Branch — Counter 1'}">
    </div>
    <div class="fld">
      <label><i class="fas fa-hashtag"></i> TID — Terminal ID</label>
      <input type="text" id="terminalId" placeholder="T0000001" value="T0000001">
    </div>
    <div class="fld">
      <label><i class="fas fa-store"></i> MID — Merchant ID</label>
      <input type="text" id="merchantId" placeholder="M000000001">
    </div>`;
  }

  // ── سحب يدوي (Manual) ─────────────────────────
  if (type === 'withdrawal_manual') {
    html = `
    <div style="background:rgba(167,139,250,.06);border:1px solid rgba(167,139,250,.2);border-radius:12px;padding:14px;margin-bottom:14px">
      <div style="color:#A78BFA;font-weight:800;font-size:.8rem;margin-bottom:10px;display:flex;align-items:center;gap:8px">
        <i class="fas fa-hand-holding-usd"></i> ${AR?'سحب يدوي — إدخال يدوي للبيانات':'Manual Withdrawal — Manual Data Entry'}
      </div>
      <div style="font-size:.72rem;color:var(--muted2);line-height:1.7">
        ${AR?'• أدخل بيانات الـ Approval Code من البنك<br>• لا يحتاج جهاز POS<br>• يُسجّل كمعاملة يدوية MOTO':'• Enter bank Approval Code manually<br>• No POS device needed<br>• Recorded as MOTO manual transaction'}
      </div>
    </div>
    <div class="fld">
      <label><i class="fas fa-key" style="color:var(--gold)"></i> Approval Code <span style="color:var(--red)">*</span></label>
      <input type="text" id="approvalCode" maxlength="6" placeholder="XXXXXX"
        style="letter-spacing:6px;font-size:1.2rem;font-weight:800;text-align:center"
        oninput="this.value=this.value.replace(/[^0-9A-Za-z]/g,'').toUpperCase()">
      <div style="font-size:.68rem;color:var(--muted);margin-top:4px">
        ${AR?'كود الموافقة من البنك (4-6 خانات)':'Bank approval code (4-6 characters)'}
      </div>
    </div>
    <div class="fld">
      <label><i class="fas fa-hashtag"></i> RRN — Retrieval Reference Number</label>
      <input type="text" id="manualRRN" maxlength="12" placeholder="000000000000"
        oninput="this.value=this.value.replace(/\D/g,'')">
    </div>
    <div class="fld">
      <label><i class="fas fa-university"></i> ${AR?'اسم البنك':'Bank Name'}</label>
      <select id="manualBank">
        <option value="">${AR?'— اختر البنك —':'— Select Bank —'}</option>
        <option value="BOMLAEADXXX">Mashreq — AE300330000019101562722 (TRANSCENDIO FZ-LLC)</option>
        <option value="CHASUS33">JP Morgan Chase — 663525063665 (ROBERT VALLES JR IOLTA)</option>
        <option value="BBMEAEAD">HSBC UAE — AE850200000013053368001</option>
        <option value="NBEGEGCX601">NBE Egypt — EG170003060131711241527030330</option>
        <option value="OTHER">${AR?'بنك آخر':'Other Bank'}</option>
      </select>
    </div>
    <div class="fld">
      <label><i class="fas fa-sticky-note"></i> ${AR?'ملاحظات':'Notes'}</label>
      <input type="text" id="manualNotes" placeholder="${AR?'سبب السحب أو ملاحظات إضافية':'Withdrawal reason or additional notes'}">
    </div>`;
  }

  el.innerHTML = html;
}

// ── Keypad ─────────────────────────────────────────
window.keyPress = function(key) {
  if (key === 'cancel') { POS.amount = ''; updateDisplay('0.00'); return; }
  if (key === 'clear') {
    POS.amount = POS.amount.slice(0,-1) || '';
    updateDisplay(POS.amount ? formatAmt(POS.amount) : '0.00');
    document.getElementById('txnAmount').value = POS.amount;
    return;
  }
  if (key === '.' && POS.amount.includes('.')) return;
  if (POS.amount.length >= 10) return;
  POS.amount += key;
  const val = formatAmt(POS.amount);
  updateDisplay(val);
  document.getElementById('txnAmount').value = POS.amount;
};

function formatAmt(v) {
  const n = parseFloat(v);
  return isNaN(n) ? '0.00' : n.toLocaleString('en-US', {minimumFractionDigits:2,maximumFractionDigits:2});
}

function updateDisplay(val) {
  document.getElementById('amountDisplay').textContent = val;
}

window.syncAmount = function(val) {
  POS.amount = val;
  updateDisplay(formatAmt(val));
};

// ── Card Formatting ────────────────────────────────
window.formatCardNum = function(el) {
  const v = el.value.replace(/\D/g,'').substring(0,16);
  el.value = v.replace(/(.{4})/g,'$1 ').trim();
  const masked = v.replace(/(\d{4})(\d*)(\d{4})/, (_, a, m, e) =>
    a + ' ' + '*'.repeat(m.length).replace(/(.{4})/g,'$1 ').trim() + ' ' + e);
  document.getElementById('cardNumDisplay').textContent =
    v.length < 4 ? '•••• •••• •••• ••••' : (v.substring(0,4) + ' •••• •••• ' + (v.slice(-4)||'••••'));
  document.getElementById('cardExpDisplay').textContent = document.getElementById('cardExpiry').value || 'MM/YY';
};

window.formatExp = function(el) {
  let v = el.value.replace(/\D/g,'');
  if (v.length >= 2) v = v.substring(0,2) + '/' + v.substring(2,4);
  el.value = v;
  document.getElementById('cardExpDisplay').textContent = v || 'MM/YY';
};
</script>

<script>
// ── Process Transaction ────────────────────────────
window.processTransaction = async function() {
  const amount   = parseFloat(document.getElementById('txnAmount').value) || parseFloat(POS.amount) || 0;
  const currency = document.getElementById('txnCurrency').value;
  const cardNum  = document.getElementById('cardNumber').value.replace(/\s/g,'');
  const cardName = document.getElementById('cardName').value.trim();
  const expiry   = document.getElementById('cardExpiry').value;
  const cvv      = document.getElementById('cardCVV').value;
  const origRef  = document.getElementById('origRef')?.value || '';
  const type     = POS.txnType;

  // بيانات إضافية للسحب
  const extraData = {};
  if (type === 'withdrawal_physical') {
    extraData.pos_location = document.getElementById('posLocation')?.value || '';
    extraData.terminal_id  = document.getElementById('terminalId')?.value || 'T0000001';
    extraData.merchant_id  = document.getElementById('merchantId')?.value || '';
  }
  if (type === 'withdrawal_manual') {
    const apCode = document.getElementById('approvalCode')?.value || '';
    if (!apCode || apCode.length < 4) {
      toast(AR?'أدخل Approval Code صحيح (4-6 خانات)':'Enter valid Approval Code (4-6 chars)', 'error');
      return;
    }
    extraData.approval_code = apCode;
    extraData.manual_rrn    = document.getElementById('manualRRN')?.value || '';
    extraData.bank          = document.getElementById('manualBank')?.value || '';
    extraData.notes         = document.getElementById('manualNotes')?.value || '';
  }

  // Validation
  if (amount <= 0) { toast(AR?'أدخل المبلغ':'Enter amount', 'error'); return; }
  if (!['balance','settlement'].includes(type)) {
    if (cardNum.length < 13) { toast(AR?'رقم البطاقة غير صحيح':'Invalid card number', 'error'); return; }
  }

  const btn = document.getElementById('processBtn');
  btn.disabled = true;
  btn.innerHTML = '<span style="display:inline-block;width:16px;height:16px;border:2px solid rgba(0,0,0,.3);border-top-color:#000;border-radius:50%;animation:spin .7s linear infinite"></span> Processing...';

  setPosStatus(AR ? 'معالجة...' : 'PROCESSING...');

  const payload = {
    txn_type: type, amount, currency,
    card_number: cardNum, card_name: cardName,
    card_expiry: expiry, card_cvv: cvv,
    orig_ref: origRef,
    ledger_address: POS.ledgerAddress,
    auto_transfer: document.getElementById('autoTransfer').checked,
    csrf_token: CSRF,
    pos_device: 'BITEL_IC3600',
    extra: extraData,
  };

  try {
    const r = await fetch('api/pos_transaction.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    });
    const d = await r.json();

    if (d.success) {
      POS.lastTxn = d;
      updateReceipt(d, type, amount, currency, cardNum);
      showResultModal(true, d);
      setPosStatus(AR ? 'مكتملة ✓' : 'APPROVED ✓');
      if (payload.auto_transfer && d.ledger_transfer) {
        toast('✅ ' + (AR?'تم التحويل للـ Ledger':'Transferred to Ledger'), 'success');
      }
    } else {
      showResultModal(false, d);
      setPosStatus(AR ? 'مرفوضة ✗' : 'DECLINED ✗');
    }
  } catch(e) {
    toast(AR?'خطأ في الاتصال بالسيرفر':'Server connection error', 'error');
    setPosStatus('ERROR');
  } finally {
    btn.disabled = false;
    const label = TXN_LABELS[type];
    btn.innerHTML = `<i class="fas fa-credit-card"></i> ${AR ? 'تنفيذ '+label.ar : 'Process '+label.en}`;
  }
};

function setPosStatus(msg) {
  document.getElementById('posStatusText').textContent = msg;
}

// ── Update Receipt ────────────────────────────────
function updateReceipt(d, type, amount, currency, cardNum) {
  const label = TXN_LABELS[type];
  document.getElementById('rType').textContent     = AR ? label.ar : label.en;
  document.getElementById('rAmount').textContent   = parseFloat(amount).toFixed(2);
  document.getElementById('rCurrency').textContent = currency;
  document.getElementById('rCard').textContent     = cardNum ? '**** ' + cardNum.slice(-4) : '—';
  document.getElementById('rRef').textContent      = d.reference || '—';
  document.getElementById('rRRN').textContent      = d.rrn || '—';
  document.getElementById('rApproval').textContent = d.approval_code || '—';
  document.getElementById('rStatus').textContent   = d.success ? 'APPROVED' : 'DECLINED';
  document.getElementById('receiptDate').textContent = new Date().toLocaleString('en-GB');
}

// ── Result Modal ──────────────────────────────────
function showResultModal(success, d) {
  const modal = document.getElementById('resultModal');
  document.getElementById('modalIcon').textContent  = success ? '✅' : '❌';
  document.getElementById('modalTitle').textContent =
    success ? (AR?'تمت العملية بنجاح':'Transaction Approved') : (AR?'رُفضت العملية':'Transaction Declined');
  document.getElementById('modalTitle').style.color = success ? 'var(--green)' : 'var(--red)';
  document.getElementById('modalRef').textContent   = 'REF: ' + (d.reference || '—');

  const label = TXN_LABELS[POS.txnType];
  document.getElementById('modalDetails').innerHTML = `
    <div class="modal-row"><span>${AR?'النوع':'Type'}</span><span>${AR?label.ar:label.en}</span></div>
    <div class="modal-row"><span>${AR?'المبلغ':'Amount'}</span><span>${parseFloat(document.getElementById('txnAmount').value||0).toFixed(2)} ${document.getElementById('txnCurrency').value}</span></div>
    <div class="modal-row"><span>RRN</span><span>${d.rrn||'—'}</span></div>
    <div class="modal-row"><span>Approval</span><span>${d.approval_code||'—'}</span></div>
    ${d.ledger_transfer ? `<div class="modal-row"><span>Ledger TX</span><span style="color:var(--green)">${d.ledger_txid?.substring(0,16)||'Sent'}…</span></div>` : ''}
  `;

  const ltBtn = document.getElementById('ledgerTransferBtn');
  ltBtn.style.display = (success && !d.ledger_transfer) ? '' : 'none';

  modal.classList.remove('hidden');
}

window.closeModal = function() {
  document.getElementById('resultModal').classList.add('hidden');
};

// ── Transfer to Ledger ─────────────────────────────
window.transferToLedger = async function() {
  if (!POS.lastTxn) return;
  toast(AR?'جاري التحويل...':'Transferring...', 'info');
  try {
    const r = await fetch('api/pos_ledger_transfer.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({
        reference: POS.lastTxn.reference,
        ledger_address: POS.ledgerAddress,
        csrf_token: CSRF
      })
    });
    const d = await r.json();
    if (d.success) {
      toast('✅ ' + (AR?'تم التحويل: ':'Transferred: ') + (d.txid?.substring(0,20)||'OK'), 'success');
      document.getElementById('ledgerTransferBtn').style.display = 'none';
    } else {
      toast(d.message || 'Transfer failed', 'error');
    }
  } catch(e) { toast('Connection error', 'error'); }
};

// ── Ledger Connect ────────────────────────────────
window.connectLedger = async function() {
  toast(AR?'جاري الاتصال بـ Ledger...':'Connecting to Ledger...', 'info');
  try {
    const { DeviceManagementKitBuilder } = await import('https://cdn.jsdelivr.net/npm/@ledgerhq/device-management-kit@latest/dist/index.mjs');
    const { webHidTransportFactory }     = await import('https://cdn.jsdelivr.net/npm/@ledgerhq/device-transport-kit-web-hid@latest/dist/index.mjs');
    const { SignerTrxBuilder }           = await import('https://cdn.jsdelivr.net/npm/@ledgerhq/device-signer-kit-tron@latest/dist/index.mjs');

    const dmk = new DeviceManagementKitBuilder().addTransport(webHidTransportFactory).build();
    const device = await new Promise((res, rej) => {
      const sub = dmk.startDiscovering({}).subscribe({
        next(d) { sub.unsubscribe(); res(d); },
        error: rej,
      });
      setTimeout(() => { sub.unsubscribe(); rej(new Error('Timeout')); }, 20000);
    });
    const sessionId = await dmk.connect({ device });
    const signer    = new SignerTrxBuilder({ dmk, sessionId }).build();
    const result    = await new Promise((res, rej) => {
      const sub = signer.getAddress("44'/195'/0'/0/0").observable.subscribe({
        next(s) { if(s.status==='Completed'){sub.unsubscribe();res(s.output);}
                  else if(s.status==='Error'){sub.unsubscribe();rej(s.error);} },
        error: rej,
      });
    });
    POS.ledgerAddress = result.address || result;
    POS.ledgerConnected = true;
    document.getElementById('ledgerAddr').textContent = POS.ledgerAddress;
    loadLedgerBalance(POS.ledgerAddress);
    toast('✅ Ledger connected: ' + POS.ledgerAddress.substring(0,14)+'...', 'success');
  } catch(e) {
    toast('Ledger: ' + (e.message||'Failed'), 'error');
  }
};

async function loadLedgerBalance(address) {
  try {
    const r = await fetch(`https://apilist.tronscanapi.com/api/accountv2?address=${address}`);
    const d = await r.json();
    const trx  = (d.balance / 1e6).toFixed(2);
    const usdt = d.trc20token_balances?.find(t=>t.tokenAbbr==='USDT');
    const uBal = usdt ? (parseFloat(usdt.balance)/1e6).toFixed(2) : '0';
    document.getElementById('ledgerBal').textContent = uBal + ' USDT';
    document.getElementById('ledgerTRX').textContent = trx + ' TRX';
  } catch(e) {}
}

// ── Print Receipt ─────────────────────────────────
window.printReceipt = function() {
  const content = document.getElementById('receiptBox').innerHTML;
  const w = window.open('','_blank','width=400,height=600');
  w.document.write(`<html><head><style>
    body{font-family:monospace;padding:20px;font-size:12px}
    .receipt-row{display:flex;justify-content:space-between}
    .receipt-total{border-top:2px dashed #000;margin-top:8px;padding-top:8px}
    .receipt-footer{text-align:center;margin-top:10px;font-size:10px;color:#666}
  </style></head><body>${content}</body></html>`);
  w.document.close();
  w.print();
};

// ── Toast ──────────────────────────────────────────
function toast(msg, type='info') {
  const t = document.getElementById('toast');
  const c = {success:'var(--green)',error:'var(--red)',info:'var(--gold)'};
  t.style.borderColor = c[type]||c.info;
  t.style.color = c[type]||c.info;
  t.textContent = msg;
  t.style.transform = 'translateX(-50%) translateY(0)';
  clearTimeout(t._t);
  t._t = setTimeout(()=>{ t.style.transform='translateX(-50%) translateY(100px)'; }, 4500);
}

// Init
loadLedgerBalance('TEwLFWlwK55b7PuFfzgH1H2f3xs3pLgLn2');
</script>
</body>
</html>
