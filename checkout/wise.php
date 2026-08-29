<?php
/**
 * ============================================================
 * DI PARMA | checkout/wise.php
 * Wise — بوابة دفع حقيقية (API Live)
 * ============================================================
 * • رصيد Wise الحالي
 * • آخر 10 عمليات
 * • إرسال تحويل مباشر (Quote → Recipient → Transfer → Fund)
 * • تحديد وجهة المبلغ (بنك / محفظة / نفس Wise)
 * • طريقة السحب: Manual أو Physical
 * ============================================================
 */
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../lib/WiseService.php';

$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang']==='en' ? 'en' : 'ar';
$ar   = ($lang === 'ar');
$dir  = $ar ? 'rtl' : 'ltr';
$csrf = generateCsrfToken();
$db   = db();

// ── بارامترات من router ─────────────────────────────────────
$amount      = floatval($_GET['amount']      ?? 0);
$currency    = strtoupper($_GET['currency']  ?? 'USD');
$destination = $_GET['destination']          ?? 'gateway';
$txnType     = $_GET['txn_type']             ?? 'purchase';
$walletAddr  = $_GET['wallet']               ?? '';
$ref         = $_GET['ref']                  ?? ('WISE-' . strtoupper(substr(uniqid(), 0, 8)));
$notes       = htmlspecialchars($_GET['notes'] ?? '');

// ── بيانات Wise ─────────────────────────────────────────────
$wiseBalances  = [];
$wiseHistory   = [];
$wiseError     = null;
$profileId     = null;

try {
    $wise         = WiseService::fromConfig();
    $profileId    = $wise->getProfileId();
    $wiseBalances = $wise->getBalances();

    // آخر 10 تحويلات من DB
    $wiseHistory = $db->query(
        "SELECT * FROM dp_transactions WHERE gateway='wise' ORDER BY created_at DESC LIMIT 10"
    );
} catch (Exception $e) {
    $wiseError = $e->getMessage();
}

// ── وجهات المبلغ ─────────────────────────────────────────────
$destinations = [
    'gateway'    => ['icon'=>'fas fa-exchange-alt', 'color'=>'#9fe870', 'ar'=>'رصيد Wise',              'en'=>'Wise Balance'],
    'mashreq'    => ['icon'=>'fas fa-university',   'color'=>'#FF6600', 'ar'=>'Mashreq Bank (TRANSCENDIO)','en'=>'Mashreq Bank'],
    'hsbc'       => ['icon'=>'fas fa-university',   'color'=>'#DB0011', 'ar'=>'HSBC UAE',                'en'=>'HSBC UAE'],
    'nbe'        => ['icon'=>'fas fa-landmark',     'color'=>'#006633', 'ar'=>'NBE Egypt',               'en'=>'NBE Egypt'],
    'jpmorgan'   => ['icon'=>'fas fa-landmark',     'color'=>'#003087', 'ar'=>'JP Morgan IOLTA',         'en'=>'JP Morgan IOLTA'],
    'ledger_trx' => ['icon'=>'fas fa-wallet',       'color'=>'#10B981', 'ar'=>'Ledger TRX (USDT)',       'en'=>'Ledger TRX (USDT)'],
    'custom'     => ['icon'=>'fas fa-pen',          'color'=>'#8B5CF6', 'ar'=>'حساب مخصص',              'en'=>'Custom Account'],
];

// بيانات البنوك المحددة
$bankDetails = [
    'mashreq'  => ['name'=>'Mashreq Bank PSC','beneficiary'=>'TRANSCENDIO FZ-LLC','iban'=>'AE300330000019101562722','swift'=>'BOMLAEADXXX','routing'=>'203320101','city'=>'Dubai, UAE'],
    'hsbc'     => ['name'=>'HSBC Bank Middle East Limited','beneficiary'=>'MR RAGEH SAEED ALI BAKRAIT','iban'=>'AE850200000013053368001','swift'=>'BBMEAEAD','city'=>'Abu Dhabi, UAE'],
    'nbe'      => ['name'=>'البنك الأهلي المصري','beneficiary'=>'TRANSCENDIO FZ-LLC','iban'=>'EG170003060131711241527030330','swift'=>'NBEGEGCX601'],
    'jpmorgan' => ['name'=>'JP Morgan Chase Bank N.A.','beneficiary'=>'ROBERT VALLES JR IOLTA','account'=>'663525063665','routing'=>'111000614','swift'=>'CHASUS33','type'=>'IOLTA'],
];

// أنواع العمليات — 10 أنواع
$txnTypes = [
    'purchase'        => ['ar'=>'تحويل مباشر',   'en'=>'Transfer',        'icon'=>'fa-paper-plane',  'color'=>'#9fe870'],
    'auth'            => ['ar'=>'تفويض',          'en'=>'Authorization',   'icon'=>'fa-shield-alt',   'color'=>'#3B82F6'],
    'auth_complete'   => ['ar'=>'إتمام تفويض',   'en'=>'Auth Completion', 'icon'=>'fa-check-double', 'color'=>'#6366F1'],
    'purchase_advice' => ['ar'=>'إشعار شراء',    'en'=>'Purchase Advice', 'icon'=>'fa-bell',         'color'=>'#F59E0B'],
    'refund'          => ['ar'=>'استرداد',        'en'=>'Refund',          'icon'=>'fa-undo',         'color'=>'#EF4444'],
    'reversal'        => ['ar'=>'إلغاء عملية',   'en'=>'Reversal',        'icon'=>'fa-reply',        'color'=>'#EC4899'],
    'balance'         => ['ar'=>'استعلام رصيد',  'en'=>'Balance Inquiry', 'icon'=>'fa-wallet',       'color'=>'#8B5CF6'],
    'cash_advance'    => ['ar'=>'سلفة نقدية',    'en'=>'Cash Advance',    'icon'=>'fa-money-bill',   'color'=>'#14B8A6'],
    'void'            => ['ar'=>'إلغاء',          'en'=>'Void',            'icon'=>'fa-ban',          'color'=>'#6B7280'],
    'settlement'      => ['ar'=>'تسوية',          'en'=>'Settlement',      'icon'=>'fa-university',   'color'=>'#FFD700'],
];
?><!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$dir?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | Wise Payment</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&family=Share+Tech+Mono&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--gold:#FFD700;--gold2:#FFB700;--bg:#030609;--bg2:#060c14;--card:#090f1e;--card2:#0b1224;--border:rgba(255,215,0,.12);--border2:rgba(255,215,0,.28);--text:#edf0f7;--muted:#4a5568;--muted2:#718096;--green:#10B981;--red:#EF4444;--wise:#9fe870;--wise2:#7acc50}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}

/* ── Topbar ── */
.topbar{background:rgba(3,6,9,.97);border-bottom:1px solid var(--border);height:62px;display:flex;align-items:center;justify-content:space-between;padding:0 28px;position:sticky;top:0;z-index:100}
.tb-brand{color:var(--gold);font-weight:900;font-size:1.05rem;display:flex;align-items:center;gap:10px}
.wise-badge{background:rgba(159,232,112,.1);border:1.5px solid rgba(159,232,112,.25);border-radius:10px;padding:5px 14px;font-size:.78rem;font-weight:800;color:var(--wise);display:flex;align-items:center;gap:7px}
.tb-right{display:flex;align-items:center;gap:10px}
.tb-link{color:var(--muted2);font-size:.78rem;padding:6px 14px;border-radius:18px;text-decoration:none;transition:.2s}
.tb-link:hover{color:var(--gold)}

/* ── Layout ── */
.layout{max-width:1200px;margin:0 auto;padding:28px 24px;display:grid;grid-template-columns:1fr 360px;gap:24px}

/* ── Cards ── */
.card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:22px;margin-bottom:18px}
.card:last-child{margin-bottom:0}
.card-title{font-size:.88rem;font-weight:800;color:var(--wise);display:flex;align-items:center;gap:8px;margin-bottom:18px}

/* ── Balance Row ── */
.balance-row{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:4px}
.bal-card{background:var(--card2);border:1px solid var(--border);border-radius:12px;padding:12px 16px;display:flex;align-items:center;gap:10px;flex:1;min-width:140px}
.bal-currency{font-size:.72rem;color:var(--muted2);font-weight:700;margin-bottom:2px}
.bal-amount{font-size:1.1rem;font-weight:900;color:var(--wise)}

/* ── Transaction Type Selector ── */
.txn-tabs{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:20px}
.txn-tab{padding:10px 8px;border-radius:12px;border:1.5px solid var(--border);background:rgba(255,255,255,.03);cursor:pointer;text-align:center;transition:.2s}
.txn-tab:hover{border-color:rgba(255,255,255,.2)}
.txn-tab.active{border-color:var(--wise);background:rgba(159,232,112,.06)}
.txn-tab-icon{font-size:1.1rem;margin-bottom:5px;display:block}
.txn-tab-name{font-size:.7rem;font-weight:800;color:var(--muted2)}
.txn-tab.active .txn-tab-name{color:var(--wise)}

/* ── Form ── */
.fld{margin-bottom:14px}
.fld label{display:block;font-size:.72rem;color:var(--muted2);margin-bottom:5px;font-weight:700}
.fld input,.fld select,.fld textarea{width:100%;background:rgba(255,255,255,.04);border:1.5px solid var(--border);border-radius:11px;padding:11px 14px;color:var(--text);font-family:'Cairo',sans-serif;font-size:.88rem;transition:.2s;resize:none}
.fld input:focus,.fld select:focus,.fld textarea:focus{outline:none;border-color:var(--wise);background:rgba(159,232,112,.03)}
.fld-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.fld-row-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}

/* ── Destination ── */
.dest-section{margin-bottom:20px}
.dest-title{font-size:.72rem;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:1.5px;margin-bottom:10px}
.dest-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:8px}
.dest-opt{background:var(--card2);border:1.5px solid var(--border);border-radius:12px;padding:10px;cursor:pointer;text-align:center;transition:.2s}
.dest-opt:hover{border-color:rgba(255,255,255,.2)}
.dest-opt.active{border-color:var(--wise);background:rgba(159,232,112,.05)}
.dest-opt-icon{font-size:1.1rem;margin-bottom:5px;display:block}
.dest-opt-name{font-size:.68rem;font-weight:700;color:var(--muted2);line-height:1.4}
.dest-opt.active .dest-opt-name{color:var(--wise)}

/* ── Bank Info Box ── */
.bank-info{background:rgba(159,232,112,.04);border:1px solid rgba(159,232,112,.12);border-radius:12px;padding:14px;margin-bottom:16px;display:none}
.bank-info.show{display:block}
.bank-info-row{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:.78rem}
.bank-info-row:last-child{border:none}
.bank-info-key{color:var(--muted2)}
.bank-info-val{font-family:'Share Tech Mono',monospace;font-size:.74rem;color:var(--text);cursor:pointer}
.bank-info-val:hover{color:var(--wise)}

/* ── Method Selector ── */
.method-section{margin-bottom:20px}
.method-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
.method-card{background:var(--card2);border:1.5px solid var(--border);border-radius:14px;padding:14px;cursor:pointer;text-align:center;transition:.2s}
.method-card:hover{border-color:rgba(255,255,255,.2);transform:translateY(-1px)}
.method-card.active{border-color:var(--wise);background:rgba(159,232,112,.05)}
.method-icon{font-size:1.6rem;margin-bottom:8px;display:block}
.method-name{font-size:.78rem;font-weight:800;color:var(--muted2)}
.method-card.active .method-name{color:var(--wise)}
.method-desc{font-size:.65rem;color:var(--muted);margin-top:4px;line-height:1.5}

/* ── NFC Indicator ── */
.nfc-bar{background:rgba(59,130,246,.06);border:1px solid rgba(59,130,246,.15);border-radius:10px;padding:10px 14px;display:flex;align-items:center;gap:10px;font-size:.78rem;color:var(--muted2);margin-top:10px;display:none}
.nfc-bar.show{display:flex}
.nfc-pulse{width:10px;height:10px;border-radius:50%;background:#3B82F6;animation:pulse 1.5s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}

/* ── Quote Box ── */
.quote-box{background:rgba(159,232,112,.04);border:1px solid rgba(159,232,112,.15);border-radius:12px;padding:14px;margin-bottom:16px;display:none}
.quote-box.show{display:block}
.quote-row{display:flex;justify-content:space-between;padding:5px 0;font-size:.8rem;border-bottom:1px solid rgba(255,255,255,.04)}
.quote-row:last-child{border:none;font-weight:800;font-size:.9rem;color:var(--wise)}
.quote-key{color:var(--muted2)}

/* ── Pay Button ── */
.pay-btn{width:100%;padding:15px;border-radius:14px;border:none;cursor:pointer;font-family:'Cairo',sans-serif;font-size:1rem;font-weight:900;background:linear-gradient(135deg,var(--wise),var(--wise2));color:#000;box-shadow:0 8px 24px rgba(159,232,112,.2);transition:.3s;display:flex;align-items:center;justify-content:center;gap:10px}
.pay-btn:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 12px 32px rgba(159,232,112,.3)}
.pay-btn:disabled{opacity:.4;cursor:not-allowed;transform:none}

/* ── Summary Sidebar ── */
.sum-row{display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:.82rem}
.sum-row:last-child{border:none;font-weight:900;font-size:.95rem;color:var(--wise);margin-top:6px}
.sum-key{color:var(--muted2)}

/* ── History Table ── */
.hist-table{width:100%;border-collapse:collapse;font-size:.75rem}
.hist-table th{padding:8px 10px;color:var(--muted);font-weight:700;text-align:<?=$ar?'right':'left'?>;border-bottom:1px solid var(--border);background:rgba(159,232,112,.02)}
.hist-table td{padding:8px 10px;border-bottom:1px solid rgba(255,255,255,.03)}
.hist-table tr:hover td{background:rgba(159,232,112,.02)}
.status-pill{padding:2px 8px;border-radius:7px;font-size:.65rem;font-weight:700}
.status-completed{background:rgba(16,185,129,.1);color:var(--green)}
.status-pending{background:rgba(251,191,36,.1);color:#FBBF24}
.status-failed{background:rgba(239,68,68,.1);color:var(--red)}

/* ── Result ── */
.result-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:500;align-items:center;justify-content:center;backdrop-filter:blur(6px)}
.result-overlay.show{display:flex}
.result-box{background:var(--card2);border:1px solid var(--border2);border-radius:20px;padding:32px;max-width:460px;width:90%;text-align:center}
.result-icon{font-size:3.5rem;margin-bottom:14px}
.result-title{font-size:1.15rem;font-weight:900;margin-bottom:8px}
.result-ref{font-family:'Share Tech Mono',monospace;font-size:.72rem;color:var(--muted2);word-break:break-all;margin-bottom:16px}
.result-details{background:rgba(255,255,255,.03);border-radius:12px;padding:14px;font-size:.78rem;text-align:<?=$ar?'right':'left'?>;margin-bottom:16px}
.result-detail-row{display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid rgba(255,255,255,.04)}
.result-detail-row:last-child{border:none}
.result-btns{display:flex;gap:10px;justify-content:center}

/* ── Toast ── */
#toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(100px);background:var(--card);border:1px solid var(--border2);border-radius:14px;padding:12px 28px;font-size:.84rem;font-weight:700;z-index:9999;transition:.35s;color:var(--text);box-shadow:0 8px 32px rgba(0,0,0,.5)}

/* ── Misc ── */
.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border-radius:11px;border:none;font-family:'Cairo',sans-serif;font-size:.84rem;font-weight:700;cursor:pointer;transition:.25s;text-decoration:none}
.btn-wise{background:linear-gradient(135deg,var(--wise),var(--wise2));color:#000;box-shadow:0 6px 20px rgba(159,232,112,.2)}
.btn-dark{background:rgba(255,255,255,.06);color:var(--text);border:1.5px solid var(--border)}
.btn-dark:hover{border-color:var(--border2)}
.spin{display:inline-block;width:16px;height:16px;border:2px solid rgba(0,0,0,.2);border-top-color:#000;border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.error-alert{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);border-radius:12px;padding:14px;font-size:.8rem;color:var(--red);margin-bottom:16px;display:flex;align-items:flex-start;gap:8px}
@media(max-width:900px){.layout{grid-template-columns:1fr}}
@media(max-width:600px){.txn-tabs{grid-template-columns:1fr 1fr}.method-grid{grid-template-columns:1fr 1fr}.dest-grid{grid-template-columns:repeat(3,1fr)}.fld-row,.fld-row-3{grid-template-columns:1fr}}
</style>
</head>
<body>

<!-- ══ TOPBAR ══ -->
<header class="topbar">
  <div class="tb-brand">
    <i class="fas fa-coins"></i> DI PARMA
    <span style="color:var(--muted)">|</span>
    <div class="wise-badge">
      <i class="fas fa-exchange-alt"></i>
      Wise Payment
      <span style="background:rgba(16,185,129,.2);border-radius:6px;padding:1px 7px;font-size:.65rem;color:var(--green)">LIVE</span>
    </div>
  </div>
  <div class="tb-right">
    <span style="font-family:'Share Tech Mono',monospace;font-size:.7rem;color:var(--muted2)"><?=htmlspecialchars($ref)?></span>
    <a href="../checkout_router.php" class="tb-link"><i class="fas fa-arrow-<?=$ar?'right':'left'?>"></i> <?=$ar?'رجوع':'Back'?></a>
    <a href="../dashboard.php" class="tb-link"><i class="fas fa-th-large"></i></a>
  </div>
</header>

<div class="layout">

  <!-- ══ LEFT COLUMN ══ -->
  <div>

    <?php if($wiseError): ?>
    <div class="error-alert">
      <i class="fas fa-exclamation-circle"></i>
      <span>Wise API: <?=htmlspecialchars($wiseError)?></span>
    </div>
    <?php endif; ?>

    <!-- ── Wise Balance ── -->
    <div class="card">
      <div class="card-title"><i class="fas fa-wallet"></i> <?=$ar?'رصيد Wise':'Wise Balance'?></div>
      <?php if(!empty($wiseBalances)): ?>
      <div class="balance-row">
        <?php foreach($wiseBalances as $bal):
          $avail = floatval($bal['amount']['value'] ?? $bal['availableAmount']['value'] ?? 0);
          $cur   = $bal['amount']['currency'] ?? $bal['currency'] ?? '?';
          if($avail <= 0 && $cur !== 'USD') continue;
        ?>
        <div class="bal-card">
          <div>
            <div class="bal-currency"><?=htmlspecialchars($cur)?></div>
            <div class="bal-amount"><?=number_format($avail,2)?></div>
          </div>
          <i class="fas fa-coins" style="color:var(--wise);opacity:.4;font-size:1.3rem"></i>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div style="color:var(--muted2);font-size:.8rem;text-align:center;padding:12px">
        <?=$wiseError ? ($ar?'تعذّر جلب الأرصدة':'Failed to load balances') : ($ar?'لا توجد أرصدة':'No balances')?>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── Transaction Type ── -->
    <div class="card">
      <div class="card-title"><i class="fas fa-list"></i> <?=$ar?'نوع العملية':'Transaction Type'?></div>
      <div class="txn-tabs">
        <?php foreach($txnTypes as $key => $tx): ?>
        <div class="txn-tab <?=$key==='purchase'?'active':''?>" onclick="selectTxnType('<?=$key?>',this)" data-type="<?=$key?>">
          <span class="txn-tab-icon" style="color:<?=$tx['color']?>"><i class="fas <?=$tx['icon']?>"></i></span>
          <div class="txn-tab-name"><?=$ar?$tx['ar']:$tx['en']?></div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Amount & Currency -->
      <div class="fld-row">
        <div class="fld">
          <label><?=$ar?'المبلغ':'Amount'?></label>
          <input type="number" id="txnAmount" min="0.01" step="0.01"
            value="<?=$amount>0?$amount:''?>" placeholder="0.00"
            oninput="onAmountChange()">
        </div>
        <div class="fld">
          <label><?=$ar?'عملة المرسل':'Source Currency'?></label>
          <select id="srcCurrency" onchange="onAmountChange()">
            <?php foreach(['USD','AED','EUR','GBP','SAR','KWD','QAR'] as $c): ?>
            <option value="<?=$c?>" <?=$c===$currency?'selected':''?>><?=$c?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fld">
          <label><?=$ar?'عملة المستلم':'Target Currency'?></label>
          <select id="tgtCurrency" onchange="onAmountChange()">
            <?php foreach(['USD','AED','EUR','GBP','SAR','KWD','EGP','GBP'] as $c): ?>
            <option value="<?=$c?>" <?=$c===$currency?'selected':''?>><?=$c?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- Quote Box -->
      <div class="quote-box" id="quoteBox">
        <div class="quote-row"><span class="quote-key"><?=$ar?'سعر الصرف':'Rate'?></span><span id="qRate">—</span></div>
        <div class="quote-row"><span class="quote-key"><?=$ar?'الرسوم':'Fee'?></span><span id="qFee">—</span></div>
        <div class="quote-row"><span class="quote-key"><?=$ar?'المبلغ المُستلَم':'Amount Received'?></span><span id="qTarget">—</span></div>
      </div>

      <button class="btn btn-dark" style="font-size:.76rem;margin-bottom:14px" onclick="getQuote()">
        <i class="fas fa-calculator"></i> <?=$ar?'احسب السعر':'Get Quote'?>
      </button>
    </div>

    <!-- ── Destination ── -->
    <div class="card">
      <div class="card-title"><i class="fas fa-map-marker-alt"></i> <?=$ar?'وجهة المبلغ':'Destination'?></div>
      <div class="dest-grid">
        <?php foreach($destinations as $code => $dest): ?>
        <div class="dest-opt <?=$code===$destination?'active':''?>"
          onclick="selectDest('<?=$code?>',this)" id="dest-<?=$code?>">
          <span class="dest-opt-icon" style="color:<?=$dest['color']?>"><i class="<?=$dest['icon']?>"></i></span>
          <div class="dest-opt-name"><?=$ar?$dest['ar']:$dest['en']?></div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Bank Info (يظهر عند اختيار بنك) -->
      <div class="bank-info" id="bankInfoBox">
        <?php foreach($bankDetails as $bk => $bd): ?>
        <div class="bank-info-inner" id="bkInfo_<?=$bk?>" style="display:none">
          <div class="bank-info-row"><span class="bank-info-key"><?=$ar?'البنك:':'Bank:'?></span><span class="bank-info-val"><?=htmlspecialchars($bd['name'])?></span></div>
          <div class="bank-info-row"><span class="bank-info-key"><?=$ar?'المستفيد:':'Beneficiary:'?></span><span class="bank-info-val" onclick="copyText(this)"><?=htmlspecialchars($bd['beneficiary'])?></span></div>
          <?php if(isset($bd['iban'])): ?>
          <div class="bank-info-row"><span class="bank-info-key">IBAN:</span><span class="bank-info-val" onclick="copyText(this)"><?=htmlspecialchars($bd['iban'])?></span></div>
          <?php endif; ?>
          <?php if(isset($bd['account'])): ?>
          <div class="bank-info-row"><span class="bank-info-key">Account:</span><span class="bank-info-val" onclick="copyText(this)"><?=htmlspecialchars($bd['account'])?></span></div>
          <div class="bank-info-row"><span class="bank-info-key">Routing:</span><span class="bank-info-val" onclick="copyText(this)"><?=htmlspecialchars($bd['routing']??'')?></span></div>
          <?php endif; ?>
          <div class="bank-info-row"><span class="bank-info-key">SWIFT:</span><span class="bank-info-val" onclick="copyText(this)"><?=htmlspecialchars($bd['swift']??'')?></span></div>
        </div>
        <?php endforeach; ?>

        <!-- Custom Account Fields -->
        <div id="customFields" style="display:none">
          <div class="fld-row" style="margin-top:8px">
            <div class="fld"><label><?=$ar?'اسم المستفيد':'Beneficiary Name'?></label><input type="text" id="custName" placeholder="Full Name"></div>
            <div class="fld"><label><?=$ar?'IBAN أو رقم الحساب':'IBAN or Account'?></label><input type="text" id="custIban" placeholder="AE... or 1234..."></div>
          </div>
          <div class="fld-row">
            <div class="fld"><label>SWIFT / BIC</label><input type="text" id="custSwift" placeholder="XXXXAEAD"></div>
            <div class="fld"><label><?=$ar?'الدولة':'Country'?></label><input type="text" id="custCountry" placeholder="AE" maxlength="2"></div>
          </div>
        </div>

        <!-- Ledger address -->
        <div id="ledgerField" style="display:none;margin-top:8px">
          <div class="fld">
            <label><i class="fas fa-wallet" style="color:var(--green)"></i> <?=$ar?'عنوان Ledger TRX':'Ledger TRX Address'?></label>
            <input type="text" id="ledgerAddr" value="TEwLFWlwK55b7PuFfzgH1H2f3xs3pLgLn2" readonly style="font-family:'Share Tech Mono',monospace;font-size:.75rem">
          </div>
          <div style="font-size:.68rem;color:var(--muted2);margin-top:3px"><?=$ar?'USDT يُرسَل تلقائياً بعد وصول التحويل':'USDT auto-sent after transfer received'?></div>
        </div>
      </div>
    </div>

    <!-- ── Withdrawal Method ── -->
    <div class="card">
      <div class="card-title"><i class="fas fa-hand-holding-usd"></i> <?=$ar?'طريقة السحب':'Withdrawal Method'?></div>
      <div class="method-grid">
        <div class="method-card active" onclick="selectMethod('manual',this)" id="method-manual">
          <span class="method-icon">✍️</span>
          <div class="method-name">Manual</div>
          <div class="method-desc"><?=$ar?'إدخال بيانات البطاقة يدوياً':'Manual card entry'?></div>
        </div>
        <div class="method-card" onclick="selectMethod('physical',this)" id="method-physical">
          <span class="method-icon">📟</span>
          <div class="method-name">Physical</div>
          <div class="method-desc">Bitel IC3600 POS</div>
        </div>
        <div class="method-card" onclick="selectMethod('nfc',this)" id="method-nfc">
          <span class="method-icon">📡</span>
          <div class="method-name">NFC</div>
          <div class="method-desc"><?=$ar?'لمسة على القارئ':'Tap on reader'?></div>
        </div>
      </div>

      <!-- NFC Status -->
      <div class="nfc-bar" id="nfcBar">
        <div class="nfc-pulse"></div>
        <span><?=$ar?'NFC جاهز — قرّب البطاقة أو الموبايل':'NFC ready — tap card or phone'?></span>
      </div>
    </div>

    <!-- ── Transfer Form ── -->
    <div class="card" id="transferForm">
      <div class="card-title"><i class="fas fa-paper-plane"></i> <?=$ar?'بيانات التحويل':'Transfer Details'?></div>

      <!-- Manual Fields -->
      <div id="manualFields">
        <div class="fld">
          <label><i class="fas fa-user"></i> <?=$ar?'اسم المرسل':'Sender Name'?></label>
          <input type="text" id="senderName" placeholder="<?=$ar?'اسمك الكامل':'Your full name'?>">
        </div>
        <div class="fld">
          <label><i class="fas fa-envelope"></i> <?=$ar?'البريد الإلكتروني':'Email'?></label>
          <input type="email" id="senderEmail" placeholder="email@example.com">
        </div>
        <?php if($notes): ?>
        <div class="fld">
          <label><?=$ar?'ملاحظات':'Notes'?></label>
          <input type="text" id="txnNotes" value="<?=$notes?>" readonly>
        </div>
        <?php endif; ?>
      </div>

      <!-- Physical/NFC Fields -->
      <div id="physicalFields" style="display:none">
        <div style="background:rgba(249,115,22,.06);border:1px solid rgba(249,115,22,.15);border-radius:12px;padding:14px;font-size:.78rem;color:var(--muted2);line-height:1.7">
          <div style="color:#F97316;font-weight:800;margin-bottom:6px"><i class="fas fa-sim-card"></i> Bitel IC3600</div>
          <?=$ar?'• وصّل الجهاز بالكمبيوتر<br>• اضغط "إرسال" لبدء العملية على الجهاز<br>• سيطلب الجهاز PIN من العميل':'• Connect device to computer<br>• Press Send to initiate on device<br>• Device will prompt customer for PIN'?>
        </div>
        <div class="fld" style="margin-top:12px">
          <label>Terminal ID</label>
          <input type="text" id="terminalId" value="T0000001" placeholder="T0000001">
        </div>
      </div>

      <button class="pay-btn" id="payBtn" onclick="processTransfer()">
        <i class="fas fa-paper-plane"></i>
        <?=$ar?'إرسال عبر Wise':'Send via Wise'?>
        <span id="payBtnAmount"><?=$amount>0?'— '.number_format($amount,2).' '.$currency:''?></span>
      </button>
    </div>

    <!-- ── History ── -->
    <div class="card">
      <div class="card-title"><i class="fas fa-history"></i> <?=$ar?'آخر 10 عمليات':'Last 10 Transactions'?></div>
      <?php if(!empty($wiseHistory)): ?>
      <div style="overflow-x:auto">
        <table class="hist-table">
          <thead>
            <tr>
              <th><?=$ar?'المرجع':'Ref'?></th>
              <th><?=$ar?'المبلغ':'Amount'?></th>
              <th><?=$ar?'الحالة':'Status'?></th>
              <th><?=$ar?'التاريخ':'Date'?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($wiseHistory as $txn): ?>
            <tr>
              <td style="font-family:'Share Tech Mono',monospace;font-size:.68rem"><?=htmlspecialchars(substr($txn['reference']??'—',0,18))?></td>
              <td style="font-weight:700"><?=number_format(floatval($txn['amount']??0),2)?> <?=htmlspecialchars($txn['currency']??'')?></td>
              <td>
                <span class="status-pill status-<?=htmlspecialchars($txn['status']??'pending')?>">
                  <?=htmlspecialchars($txn['status']??'pending')?>
                </span>
              </td>
              <td style="color:var(--muted2);font-size:.7rem"><?=date('d/m/y H:i',strtotime($txn['created_at']??'now'))?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <div style="text-align:center;padding:24px;color:var(--muted2);font-size:.8rem">
        <i class="fas fa-inbox" style="font-size:1.8rem;display:block;margin-bottom:8px;opacity:.3"></i>
        <?=$ar?'لا توجد عمليات سابقة':'No previous transactions'?>
      </div>
      <?php endif; ?>
    </div>

  </div><!-- /left -->

  <!-- ══ RIGHT SIDEBAR ══ -->
  <div>
    <div class="card" style="position:sticky;top:80px">
      <div class="card-title"><i class="fas fa-receipt"></i> <?=$ar?'ملخص العملية':'Transaction Summary'?></div>

      <div class="sum-row">
        <span class="sum-key"><?=$ar?'البوابة:':'Gateway:'?></span>
        <span style="color:var(--wise);font-weight:800">Wise</span>
      </div>
      <div class="sum-row">
        <span class="sum-key"><?=$ar?'البيئة:':'Environment:'?></span>
        <span style="color:var(--green)">● Live</span>
      </div>
      <div class="sum-row" id="sumType">
        <span class="sum-key"><?=$ar?'النوع:':'Type:'?></span>
        <span><?=$ar?'شراء مباشر':'Purchase'?></span>
      </div>
      <div class="sum-row" id="sumMethod">
        <span class="sum-key"><?=$ar?'الطريقة:':'Method:'?></span>
        <span>Manual</span>
      </div>
      <div class="sum-row" id="sumDest">
        <span class="sum-key"><?=$ar?'الوجهة:':'Destination:'?></span>
        <span style="color:var(--wise);font-size:.78rem"><?=$ar?'رصيد Wise':'Wise Balance'?></span>
      </div>
      <div class="sum-row">
        <span class="sum-key"><?=$ar?'المبلغ:':'Amount:'?></span>
        <span id="sumAmount"><?=$amount>0?number_format($amount,2).' '.$currency:'—'?></span>
      </div>
      <div class="sum-row" id="sumFeeRow" style="display:none">
        <span class="sum-key"><?=$ar?'الرسوم:':'Fee:'?></span>
        <span id="sumFee" style="color:var(--red)">—</span>
      </div>
      <div class="sum-row">
        <span class="sum-key"><?=$ar?'الإجمالي:':'Total:'?></span>
        <span id="sumTotal"><?=$amount>0?number_format($amount,2).' '.$currency:'—'?></span>
      </div>

      <!-- Wise API Status -->
      <div style="margin-top:16px;background:rgba(159,232,112,.04);border:1px solid rgba(159,232,112,.1);border-radius:10px;padding:12px;font-size:.72rem">
        <div style="font-weight:800;color:var(--wise);margin-bottom:8px"><i class="fas fa-plug"></i> Wise API</div>
        <div style="display:flex;justify-content:space-between;margin-bottom:4px">
          <span style="color:var(--muted2)">Status:</span>
          <span style="color:<?=$wiseError?'var(--red)':'var(--green)'?>"><?=$wiseError?'✗ Error':'✓ Connected'?></span>
        </div>
        <div style="display:flex;justify-content:space-between;margin-bottom:4px">
          <span style="color:var(--muted2)">Profile ID:</span>
          <span style="font-family:'Share Tech Mono',monospace"><?=$profileId??'—'?></span>
        </div>
        <div style="display:flex;justify-content:space-between">
          <span style="color:var(--muted2)">Env:</span>
          <span style="color:var(--green)">Live ●</span>
        </div>
      </div>

      <!-- Quick Links -->
      <div style="margin-top:14px;display:flex;flex-direction:column;gap:8px">
        <a href="../api/wise_profiles.php" class="btn btn-dark btn-sm" style="font-size:.75rem">
          <i class="fas fa-user-circle"></i> <?=$ar?'الملفات الشخصية':'Wise Profiles'?>
        </a>
        <a href="../checkout_router.php" class="btn btn-dark btn-sm" style="font-size:.75rem">
          <i class="fas fa-exchange-alt"></i> <?=$ar?'تغيير البوابة':'Change Gateway'?>
        </a>
      </div>
    </div>
  </div>

</div><!-- /layout -->

<!-- ══ RESULT OVERLAY ══ -->
<div class="result-overlay" id="resultOverlay">
  <div class="result-box">
    <div class="result-icon" id="resIcon">✅</div>
    <div class="result-title" id="resTitle"></div>
    <div class="result-ref" id="resRef"></div>
    <div class="result-details" id="resDetails"></div>
    <div class="result-btns">
      <button class="btn btn-dark" onclick="document.getElementById('resultOverlay').classList.remove('show')">
        <i class="fas fa-times"></i> <?=$ar?'إغلاق':'Close'?>
      </button>
      <a href="../dashboard.php" class="btn btn-wise">
        <i class="fas fa-th-large"></i> <?=$ar?'لوحة التحكم':'Dashboard'?>
      </a>
    </div>
  </div>
</div>

<div id="toast"></div>

<!-- ══ JAVASCRIPT ══ -->
<script>
const AR    = <?=$ar?'true':'false'?>;
const CSRF  = '<?=$csrf?>';
const REF   = '<?=$ref?>';
const INIT_AMOUNT = <?=$amount?>;
const INIT_CURRENCY = '<?=$currency?>';

const STATE = {
  txnType: 'purchase',
  destCode: '<?=$destination?>',
  method: 'manual',
  quoteId: null,
  fee: 0,
};

// ── Transaction Type ────────────────────────────────────────
window.selectTxnType = function(type, el) {
  STATE.txnType = type;
  document.querySelectorAll('.txn-tab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  const labels = {purchase:AR?'شراء مباشر':'Purchase',auth:AR?'تفويض':'Auth',refund:AR?'استرداد':'Refund',transfer:AR?'تحويل مباشر':'Transfer'};
  document.getElementById('sumType').querySelector('span:last-child').textContent = labels[type] || type;
};

// ── Destination ─────────────────────────────────────────────
window.selectDest = function(code, el) {
  STATE.destCode = code;
  document.querySelectorAll('.dest-opt').forEach(d => d.classList.remove('active'));
  el.classList.add('active');

  // إظهار/إخفاء bank info
  const bankBox = document.getElementById('bankInfoBox');
  const bankCodes = ['mashreq','hsbc','nbe','jpmorgan'];
  const isBankDest = bankCodes.includes(code);
  const isCustom   = code === 'custom';
  const isLedger   = code === 'ledger_trx';

  bankBox.className = 'bank-info' + (isBankDest || isCustom || isLedger ? ' show' : '');

  // أظهر المعلومات الصحيحة
  document.querySelectorAll('.bank-info-inner').forEach(d => d.style.display='none');
  if(isBankDest) document.getElementById('bkInfo_'+code)?.style.setProperty('display','block');

  document.getElementById('customFields').style.display  = isCustom  ? '' : 'none';
  document.getElementById('ledgerField').style.display   = isLedger  ? '' : 'none';

  // تحديث الملخص
  const destNames = {
    gateway:AR?'رصيد Wise':'Wise Balance',
    mashreq:'Mashreq Bank',hsbc:'HSBC UAE',nbe:'NBE Egypt',jpmorgan:'JP Morgan',
    ledger_trx:'Ledger TRX',custom:AR?'حساب مخصص':'Custom'
  };
  document.getElementById('sumDest').querySelector('span:last-child').textContent = destNames[code]||code;
};

// ── Method ─────────────────────────────────────────────────
window.selectMethod = function(method, el) {
  STATE.method = method;
  document.querySelectorAll('.method-card').forEach(m => m.classList.remove('active'));
  el.classList.add('active');

  document.getElementById('manualFields').style.display   = method === 'manual'   ? '' : 'none';
  document.getElementById('physicalFields').style.display = method === 'physical' ? '' : 'none';
  document.getElementById('nfcBar').className             = method === 'nfc' ? 'nfc-bar show' : 'nfc-bar';

  document.getElementById('sumMethod').querySelector('span:last-child').textContent = method;

  if (method === 'nfc') initNFC();
};

// ── NFC ─────────────────────────────────────────────────────
async function initNFC() {
  if ('NDEFReader' in window) {
    try {
      const ndef = new NDEFReader();
      await ndef.scan();
      ndef.onreading = (event) => {
        toast(AR?'✅ تم قراءة البطاقة عبر NFC':'✅ Card read via NFC','success');
        // معالجة البيانات القادمة من NFC
        processTransfer({ nfc_data: JSON.stringify(event.serialNumber) });
      };
    } catch(e) {
      toast('NFC: ' + e.message, 'error');
    }
  } else {
    toast(AR?'NFC غير مدعوم في هذا الجهاز':'NFC not supported on this device','error');
  }
}

// ── Quote ───────────────────────────────────────────────────
window.getQuote = async function() {
  const amount = parseFloat(document.getElementById('txnAmount').value) || 0;
  const src    = document.getElementById('srcCurrency').value;
  const tgt    = document.getElementById('tgtCurrency').value;

  if (amount <= 0) { toast(AR?'أدخل المبلغ':'Enter amount','error'); return; }

  try {
    const r = await fetch('../api/wise_payment.php?action=quote', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ amount, source_currency: src, target_currency: tgt, csrf_token: CSRF })
    });
    const d = await r.json();
    if (d.success) {
      STATE.quoteId = d.quote_id;
      STATE.fee     = d.fee;
      document.getElementById('quoteBox').classList.add('show');
      document.getElementById('qRate').textContent   = d.rate?.toFixed(4) || '1';
      document.getElementById('qFee').textContent    = d.fee?.toFixed(2) + ' ' + src;
      document.getElementById('qTarget').textContent = d.target_amount?.toFixed(2) + ' ' + d.target_currency;
      document.getElementById('sumFeeRow').style.display = '';
      document.getElementById('sumFee').textContent  = d.fee?.toFixed(2) + ' ' + src;
      document.getElementById('sumTotal').textContent = (amount + (d.fee||0)).toFixed(2) + ' ' + src;
      toast(AR?'تم احتساب السعر':'Quote calculated','success');
    } else {
      toast(d.message || 'Quote failed','error');
    }
  } catch(e) {
    toast(AR?'خطأ في الاتصال':'Connection error','error');
  }
};

function onAmountChange() {
  const amt = parseFloat(document.getElementById('txnAmount').value)||0;
  const cur = document.getElementById('srcCurrency').value;
  document.getElementById('sumAmount').textContent  = amt>0 ? amt.toFixed(2)+' '+cur : '—';
  document.getElementById('sumTotal').textContent   = amt>0 ? amt.toFixed(2)+' '+cur : '—';
  document.getElementById('payBtnAmount').textContent = amt>0 ? '— '+amt.toFixed(2)+' '+cur : '';
  // إعادة تعيين القيمة المحسوبة
  document.getElementById('quoteBox').classList.remove('show');
  STATE.quoteId = null;
}

// ── Process Transfer ─────────────────────────────────────────
window.processTransfer = async function(extra = {}) {
  const amount  = parseFloat(document.getElementById('txnAmount').value)||0;
  const src     = document.getElementById('srcCurrency').value;
  const tgt     = document.getElementById('tgtCurrency').value;
  const name    = document.getElementById('senderName')?.value.trim() || '';
  const email   = document.getElementById('senderEmail')?.value.trim() || '';
  const btn     = document.getElementById('payBtn');

  if (amount <= 0) { toast(AR?'أدخل المبلغ':'Enter amount','error'); return; }
  if (!name && STATE.method==='manual') { toast(AR?'أدخل اسمك':'Enter your name','error'); return; }

  // بيانات الوجهة
  let destData = {};
  const dest = STATE.destCode;

  if (dest === 'custom') {
    destData.recipient_name  = document.getElementById('custName')?.value || name;
    destData.iban            = document.getElementById('custIban')?.value || '';
    destData.swift           = document.getElementById('custSwift')?.value || '';
    destData.country         = document.getElementById('custCountry')?.value || 'AE';
  } else if (['mashreq','hsbc','nbe','jpmorgan'].includes(dest)) {
    const bankMap = {
      mashreq:  {name:'TRANSCENDIO FZ-LLC',iban:'AE300330000019101562722',swift:'BOMLAEADXXX',country:'AE'},
      hsbc:     {name:'MR RAGEH SAEED ALI BAKRAIT',iban:'AE850200000013053368001',swift:'BBMEAEAD',country:'AE'},
      nbe:      {name:'TRANSCENDIO FZ-LLC',iban:'EG170003060131711241527030330',swift:'NBEGEGCX601',country:'EG'},
      jpmorgan: {name:'ROBERT VALLES JR IOLTA',account:'663525063665',routing:'111000614',swift:'CHASUS33',country:'US'},
    };
    destData = {
      recipient_name: bankMap[dest].name,
      iban:           bankMap[dest].iban || '',
      account_number: bankMap[dest].account || '',
      routing_number: bankMap[dest].routing || '',
      swift:          bankMap[dest].swift || '',
      country:        bankMap[dest].country,
    };
  } else if (dest === 'ledger_trx') {
    // بعد وصول التحويل، يُحوَّل تلقائياً للـ Ledger
    destData.recipient_name = 'DI PARMA LEDGER';
    destData.iban = 'AE300330000019101562722'; // يصل Mashreq أولاً ثم للـ Ledger
    destData.swift = 'BOMLAEADXXX';
    destData.country = 'AE';
    destData.auto_to_ledger = true;
    destData.ledger_address = document.getElementById('ledgerAddr')?.value || 'TEwLFWlwK55b7PuFfzgH1H2f3xs3pLgLn2';
  }

  btn.disabled = true;
  btn.innerHTML = '<span class="spin"></span> ' + (AR?'جاري الإرسال...':'Sending...');

  const payload = {
    action:          'transfer',
    amount,
    source_currency: src,
    target_currency: tgt,
    recipient_name:  destData.recipient_name || name,
    recipient_email: email || 'client@diparmas.com',
    reference:       REF,
    destination:     dest,
    method:          STATE.method,
    csrf_token:      CSRF,
    ...destData,
    ...extra,
  };

  try {
    const r = await fetch('../api/wise_payment.php?action=transfer', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    });
    const d = await r.json();
    showResult(d);
  } catch(e) {
    toast(AR?'خطأ في الاتصال':'Connection error','error');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-paper-plane"></i> ' + (AR?'إرسال عبر Wise':'Send via Wise');
  }
};

// ── Show Result ──────────────────────────────────────────────
function showResult(d) {
  const overlay = document.getElementById('resultOverlay');
  overlay.classList.add('show');

  document.getElementById('resIcon').textContent  = d.success ? '✅' : '❌';
  document.getElementById('resTitle').textContent = d.success
    ? (AR?'تم الإرسال بنجاح':'Transfer Sent Successfully')
    : (AR?'فشل التحويل':'Transfer Failed');
  document.getElementById('resTitle').style.color = d.success ? 'var(--green)' : 'var(--red)';
  document.getElementById('resRef').textContent   = 'REF: ' + (d.reference || REF);

  document.getElementById('resDetails').innerHTML = `
    <div class="result-detail-row"><span>Gateway</span><span style="color:var(--wise)">Wise Live</span></div>
    ${d.transfer_id ? `<div class="result-detail-row"><span>Transfer ID</span><span style="font-family:monospace">${d.transfer_id}</span></div>` : ''}
    <div class="result-detail-row"><span>${AR?'المبلغ':'Amount'}</span><span>${(d.amount||0).toFixed?.(2)||d.amount} ${d.source_currency||''}</span></div>
    ${d.target_amount ? `<div class="result-detail-row"><span>${AR?'المُستلَم':'Received'}</span><span style="color:var(--wise)">${parseFloat(d.target_amount).toFixed(2)} ${d.target_currency||''}</span></div>` : ''}
    ${d.fee ? `<div class="result-detail-row"><span>${AR?'الرسوم':'Fee'}</span><span style="color:var(--red)">${parseFloat(d.fee).toFixed(2)}</span></div>` : ''}
    <div class="result-detail-row"><span>Status</span><span style="color:${d.status==='COMPLETED'?'var(--green)':'#FBBF24'}">${d.status||'PROCESSING'}</span></div>
    ${d.message ? `<div class="result-detail-row"><span>${AR?'الرسالة':'Message'}</span><span>${d.message}</span></div>` : ''}
  `;
}

// ── Utilities ────────────────────────────────────────────────
function copyText(el) {
  const txt = typeof el === 'string' ? el : el.textContent;
  navigator.clipboard?.writeText(txt).then(() => toast(AR?'تم النسخ':'Copied','success'));
}

function toast(msg, type='info') {
  const t = document.getElementById('toast');
  const c = {success:'var(--green)',error:'var(--red)',info:'var(--wise)'};
  t.style.borderColor = c[type]||c.info;
  t.style.color = c[type]||c.info;
  t.textContent = msg;
  t.style.transform = 'translateX(-50%) translateY(0)';
  clearTimeout(t._t);
  t._t = setTimeout(() => { t.style.transform = 'translateX(-50%) translateY(100px)'; }, 4500);
}

// Init
document.addEventListener('DOMContentLoaded', () => {
  if (INIT_AMOUNT > 0) onAmountChange();
  selectDest('<?=$destination?>', document.getElementById('dest-<?=$destination?>'));
});
</script>
</body>
</html>
