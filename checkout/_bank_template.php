<?php
/**
 * DI PARMA | Bank Checkout Template
 * يُستدعى من bank_mashreq.php, bank_hsbc.php, bank_nbe.php, bank_jpmorgan.php
 * $BANK_CONFIG يجب تعريفه قبل include هذا الملف
 */
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang']==='ar' ? 'ar' : 'en';
$ar   = ($lang==='ar'); $dir=$ar?'rtl':'ltr';
$csrf = generateCsrfToken();
$db   = db();

$amount      = floatval($_GET['amount']      ?? 0);
$currency    = strtoupper($_GET['currency']  ?? $BANK_CONFIG['default_currency'] ?? 'AED');
$destination = $_GET['destination']          ?? 'gateway';
$txnTypeInit = $_GET['txn_type']             ?? 'purchase';
$ref         = $_GET['ref']                  ?? ($BANK_CONFIG['prefix'].'-'.strtoupper(substr(uniqid(),0,8)));

// آخر 10 عمليات هذا البنك
$bankHistory = [];
$bankStats   = ['total_count'=>0,'total_amount'=>0];
try{
    $bankHistory = $db->query("SELECT * FROM dp_transactions WHERE gateway=? ORDER BY created_at DESC LIMIT 10", [$BANK_CONFIG['gateway_code']]);
    $st = $db->query("SELECT COUNT(*) cnt, COALESCE(SUM(amount),0) total FROM dp_transactions WHERE gateway=? AND status='completed'", [$BANK_CONFIG['gateway_code']]);
    if(!empty($st[0])){$bankStats['total_count']=$st[0]['cnt'];$bankStats['total_amount']=$st[0]['total'];}
}catch(Exception $e){}

$txnTypes=['purchase'=>['ar'=>'شراء مباشر','en'=>'Purchase'],'auth'=>['ar'=>'تفويض','en'=>'Authorization'],'auth_complete'=>['ar'=>'إتمام تفويض','en'=>'Auth Completion'],'purchase_advice'=>['ar'=>'إشعار شراء','en'=>'Purchase Advice'],'refund'=>['ar'=>'استرداد','en'=>'Refund'],'reversal'=>['ar'=>'إلغاء عملية','en'=>'Reversal'],'balance'=>['ar'=>'استعلام رصيد','en'=>'Balance Inquiry'],'cash_advance'=>['ar'=>'سلفة نقدية','en'=>'Cash Advance'],'void'=>['ar'=>'إلغاء','en'=>'Void'],'settlement'=>['ar'=>'تسوية','en'=>'Settlement']];
$destinations=['gateway'=>['ar'=>'نفس البنك','en'=>'Same Bank'],'ledger_trx'=>['ar'=>'Ledger TRX','en'=>'Ledger TRX'],'mashreq'=>['ar'=>'Mashreq','en'=>'Mashreq'],'custom'=>['ar'=>'حساب مخصص','en'=>'Custom Account']];
?><!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$dir?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | <?=htmlspecialchars($BANK_CONFIG['name'])?></title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&family=Share+Tech+Mono&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--gold:#FFD700;--bg:#030609;--card:#090f1e;--card2:#0b1224;--border:rgba(255,215,0,.12);--border2:rgba(255,215,0,.28);--text:#edf0f7;--muted:#4a5568;--muted2:#718096;--green:#10B981;--red:#EF4444;--bank:<?=$BANK_CONFIG['color']?>}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.topbar{background:rgba(3,6,9,.97);border-bottom:1px solid var(--border);height:62px;display:flex;align-items:center;justify-content:space-between;padding:0 28px;position:sticky;top:0;z-index:100}
.tb-brand{color:var(--gold);font-weight:900;font-size:1.05rem;display:flex;align-items:center;gap:10px}
.bank-badge{background:rgba(255,255,255,.04);border:1.5px solid var(--bank)44;border-radius:10px;padding:5px 14px;font-size:.78rem;font-weight:800;color:var(--bank);display:flex;align-items:center;gap:7px}
.layout{max-width:1100px;margin:0 auto;padding:28px 24px;display:grid;grid-template-columns:1fr 320px;gap:22px}
.card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:22px;margin-bottom:18px}
.card-title{font-size:.88rem;font-weight:800;color:var(--bank);display:flex;align-items:center;gap:8px;margin-bottom:16px}
.stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:4px}
.stat-c{background:var(--card2);border:1px solid var(--border);border-radius:10px;padding:10px;text-align:center}
.stat-v{font-size:1.05rem;font-weight:900;color:var(--bank)}
.stat-l{font-size:.62rem;color:var(--muted2);margin-top:2px}
/* Bank details */
.bank-detail-row{display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:.8rem;gap:12px}
.bank-detail-row:last-child{border:none}
.bdk{color:var(--muted2);flex-shrink:0;min-width:100px}
.bdv{font-family:'Share Tech Mono',monospace;font-size:.76rem;word-break:break-all;cursor:pointer}
.bdv:hover{color:var(--bank)}
/* TXN tabs */
.txn-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px}
.txn-tab{padding:8px 16px;border-radius:10px;border:1.5px solid var(--border);background:rgba(255,255,255,.03);cursor:pointer;font-size:.76rem;font-weight:700;color:var(--muted2);transition:.2s}
.txn-tab.active{border-color:var(--bank);color:var(--bank);background:rgba(255,255,255,.04)}
/* Destination */
.dest-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:14px}
.dest-opt{background:var(--card2);border:1.5px solid var(--border);border-radius:10px;padding:10px 6px;cursor:pointer;text-align:center;transition:.2s}
.dest-opt:hover{border-color:rgba(255,255,255,.15)}
.dest-opt.active{border-color:var(--bank);background:rgba(255,255,255,.03)}
.dest-opt-icon{font-size:1rem;margin-bottom:4px;display:block}
.dest-opt-name{font-size:.62rem;font-weight:700;color:var(--muted2)}
.dest-opt.active .dest-opt-name{color:var(--bank)}
/* Method */
.method-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:14px}
.mth-card{background:var(--card2);border:1.5px solid var(--border);border-radius:12px;padding:12px;cursor:pointer;text-align:center;transition:.2s}
.mth-card:hover{border-color:rgba(255,255,255,.15)}
.mth-card.active{border-color:var(--bank);background:rgba(255,255,255,.03)}
.mth-icon{font-size:1.4rem;margin-bottom:5px;display:block}
.mth-name{font-size:.72rem;font-weight:800;color:var(--muted2)}
.mth-card.active .mth-name{color:var(--bank)}
/* NFC */
.nfc-bar{background:rgba(59,130,246,.06);border:1px solid rgba(59,130,246,.15);border-radius:10px;padding:10px 14px;display:none;align-items:center;gap:10px;font-size:.76rem;color:var(--muted2);margin-top:10px}
.nfc-bar.show{display:flex}
.nfc-pulse{width:9px;height:9px;border-radius:50%;background:#3B82F6;animation:pulse 1.5s infinite;flex-shrink:0}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
/* Form */
.fld{margin-bottom:12px}
.fld label{display:block;font-size:.7rem;color:var(--muted2);margin-bottom:4px;font-weight:700}
.fld input,.fld select{width:100%;background:rgba(255,255,255,.04);border:1.5px solid var(--border);border-radius:10px;padding:10px 13px;color:var(--text);font-family:'Cairo',sans-serif;font-size:.86rem;transition:.2s}
.fld input:focus,.fld select:focus{outline:none;border-color:var(--bank)}
.fld-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
/* Pay btn */
.pay-btn{width:100%;padding:14px;border-radius:13px;border:none;cursor:pointer;font-family:'Cairo',sans-serif;font-size:.95rem;font-weight:900;background:var(--bank);color:#fff;box-shadow:0 8px 24px var(--bank)44;transition:.3s;display:flex;align-items:center;justify-content:center;gap:10px}
.pay-btn:hover:not(:disabled){transform:translateY(-2px);filter:brightness(1.1)}
.pay-btn:disabled{opacity:.4;cursor:not-allowed;transform:none}
/* Summary */
.sum-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:.8rem}
.sum-row:last-child{border:none;font-weight:900;font-size:.92rem;color:var(--bank)}
.sum-key{color:var(--muted2)}
/* History */
.hist-tbl{width:100%;border-collapse:collapse;font-size:.73rem}
.hist-tbl th{padding:7px 9px;color:var(--muted);font-weight:700;text-align:<?=$ar?'right':'left'?>;border-bottom:1px solid var(--border)}
.hist-tbl td{padding:7px 9px;border-bottom:1px solid rgba(255,255,255,.03)}
.sp{padding:2px 7px;border-radius:6px;font-size:.62rem;font-weight:700}
.sp-completed{background:rgba(16,185,129,.1);color:var(--green)}
.sp-pending{background:rgba(251,191,36,.1);color:#FBBF24}
.sp-failed{background:rgba(239,68,68,.1);color:var(--red)}
/* Result */
.res-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:500;align-items:center;justify-content:center;backdrop-filter:blur(6px)}
.res-overlay.show{display:flex}
.res-box{background:var(--card2);border:1px solid var(--border2);border-radius:20px;padding:30px;max-width:440px;width:90%;text-align:center}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:10px;border:none;font-family:'Cairo',sans-serif;font-size:.82rem;font-weight:700;cursor:pointer;text-decoration:none;transition:.2s}
.btn-bank{background:var(--bank);color:#fff}
.btn-dark{background:rgba(255,255,255,.06);color:var(--text);border:1.5px solid var(--border)}
.spin{display:inline-block;width:15px;height:15px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
#toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(100px);background:var(--card);border:1px solid var(--border2);border-radius:13px;padding:11px 26px;font-size:.82rem;font-weight:700;z-index:9999;transition:.35s;color:var(--text)}
@media(max-width:900px){.layout{grid-template-columns:1fr}}
@media(max-width:600px){.fld-row{grid-template-columns:1fr}.dest-grid{grid-template-columns:repeat(4,1fr)}}
</style>
</head>
<body>
<header class="topbar">
  <div class="tb-brand">
    <i class="fas fa-coins"></i> DI PARMA <span style="color:var(--muted)">|</span>
    <div class="bank-badge"><i class="<?=htmlspecialchars($BANK_CONFIG['icon'])?>"></i> <?=htmlspecialchars($BANK_CONFIG['name'])?></div>
  </div>
  <div style="display:flex;align-items:center;gap:10px">
    <span style="font-family:'Share Tech Mono',monospace;font-size:.68rem;color:var(--muted2)"><?=htmlspecialchars($ref)?></span>
    <a href="../checkout_router.php" style="color:var(--muted2);font-size:.76rem;text-decoration:none;padding:5px 12px;border-radius:9px">
      <i class="fas fa-arrow-<?=$ar?'right':'left'?>"></i> <?=$ar?'رجوع':'Back'?>
    </a>
  </div>
</header>

<div class="layout">
<div>

  <!-- Stats -->
  <div class="card">
    <div class="card-title"><i class="<?=htmlspecialchars($BANK_CONFIG['icon'])?>"></i> <?=htmlspecialchars($BANK_CONFIG['name'])?></div>
    <div class="stats-row">
      <div class="stat-c"><div class="stat-v"><?=number_format($bankStats['total_count'])?></div><div class="stat-l"><?=$ar?'العمليات':'TXNs'?></div></div>
      <div class="stat-c"><div class="stat-v" style="font-size:.85rem">$<?=number_format($bankStats['total_amount'],0)?></div><div class="stat-l"><?=$ar?'إجمالي':'Total'?></div></div>
      <div class="stat-c"><div class="stat-v" style="font-size:.7rem;color:var(--green)">● Live</div><div class="stat-l"><?=$ar?'الحالة':'Status'?></div></div>
    </div>
  </div>

  <!-- Bank Details -->
  <div class="card">
    <div class="card-title"><i class="fas fa-info-circle"></i> <?=$ar?'بيانات الحساب البنكي':'Bank Account Details'?></div>
    <?php foreach($BANK_CONFIG['fields'] as $k=>$v): ?>
    <div class="bank-detail-row">
      <span class="bdk"><?=htmlspecialchars($k)?>:</span>
      <span class="bdv" onclick="copyText('<?=htmlspecialchars($v,ENT_QUOTES)?>')"><?=htmlspecialchars($v)?></span>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Transaction Type -->
  <div class="card">
    <div class="card-title"><i class="fas fa-list"></i> <?=$ar?'نوع العملية':'Transaction Type'?></div>
    <div class="txn-tabs">
      <?php foreach($txnTypes as $k=>$t): ?>
      <div class="txn-tab <?=$k===$txnTypeInit?'active':''?>" onclick="selTxn('<?=$k?>',this)"><?=$ar?$t['ar']:$t['en']?></div>
      <?php endforeach; ?>
    </div>

    <!-- 3D / 2D Toggle -->
    <div id="secModeWrap" style="display:flex;gap:8px;margin-bottom:14px">
      <div onclick="selSecMode('3D',this)" id="bsm-3D"
        style="flex:1;padding:9px;border-radius:10px;border:1.5px solid var(--bank);background:rgba(255,255,255,.04);cursor:pointer;text-align:center;font-size:.75rem;font-weight:700;color:var(--bank)">
        <i class="fas fa-shield-alt"></i> 3D Secure
      </div>
      <div onclick="selSecMode('2D',this)" id="bsm-2D"
        style="flex:1;padding:9px;border-radius:10px;border:1.5px solid var(--border);background:rgba(255,255,255,.03);cursor:pointer;text-align:center;font-size:.75rem;font-weight:700;color:var(--muted2)">
        <i class="fas fa-credit-card"></i> 2D / MOTO
      </div>
    </div>

    <!-- Orig Ref -->
    <div id="bankOrigRefWrap" style="display:none;margin-bottom:12px">
      <div class="fld">
        <label><i class="fas fa-hashtag"></i> <?=$ar?'رقم المرجع الأصلي (RRN)':'Original Reference (RRN)'?></label>
        <input type="text" id="bankOrigRef" placeholder="<?=$ar?'RRN / Approval Code':'RRN / Approval Code'?>">
      </div>
    </div>

    <div class="fld-row">
      <div class="fld">
        <label><?=$ar?'المبلغ':'Amount'?></label>
        <input type="number" id="txnAmount" value="<?=$amount>0?$amount:''?>" min="0.01" step="0.01" placeholder="0.00" oninput="updateSum()">
      </div>
      <div class="fld">
        <label><?=$ar?'العملة':'Currency'?></label>
        <select id="txnCurrency" onchange="updateSum()">
          <?php foreach($BANK_CONFIG['currencies'] as $c): ?>
          <option value="<?=$c?>" <?=$c===$currency?'selected':''?>><?=$c?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="fld">
      <label><?=$ar?'اسم المرسل':'Sender Name'?></label>
      <input type="text" id="senderName" placeholder="<?=$ar?'الاسم الكامل':'Full name'?>">
    </div>
    <div class="fld">
      <label><?=$ar?'البريد الإلكتروني':'Email'?></label>
      <input type="email" id="senderEmail" placeholder="email@example.com">
    </div>
    <div class="fld">
      <label><?=$ar?'رقم مرجع التحويل (اختياري)':'Transfer Reference (optional)'?></label>
      <input type="text" id="transferRef" placeholder="SWIFT ref / رقم التحويل">
    </div>
  </div>

  <!-- Destination -->
  <div class="card">
    <div class="card-title"><i class="fas fa-map-marker-alt"></i> <?=$ar?'وجهة المبلغ':'Destination'?></div>
    <div class="dest-grid">
      <?php foreach($destinations as $code=>$d): ?>
      <div class="dest-opt <?=$code===$destination?'active':''?>" onclick="selDest('<?=$code?>',this)">
        <span class="dest-opt-icon"><i class="fas <?=$code==='ledger_trx'?'fa-wallet':($code==='gateway'?'fa-university':'fa-map-marker-alt')?>"></i></span>
        <div class="dest-opt-name"><?=$ar?$d['ar']:$d['en']?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <div id="ledgerWalletField" style="display:none">
      <div class="fld">
        <label><i class="fas fa-wallet" style="color:var(--green)"></i> Ledger TRX Address</label>
        <input type="text" id="ledgerAddr" value="TEwLFWlwK55b7PuFfzgH1H2f3xs3pLgLn2" readonly style="font-family:'Share Tech Mono',monospace;font-size:.72rem">
      </div>
    </div>
    <div id="customDestField" style="display:none">
      <div class="fld-row">
        <div class="fld"><label><?=$ar?'اسم المستفيد':'Beneficiary'?></label><input type="text" id="custDestName"></div>
        <div class="fld"><label>IBAN / Account</label><input type="text" id="custDestIban"></div>
      </div>
    </div>
  </div>

  <!-- Withdrawal Method -->
  <div class="card">
    <div class="card-title"><i class="fas fa-hand-holding-usd"></i> <?=$ar?'طريقة السحب':'Withdrawal Method'?></div>
    <div class="method-grid">
      <div class="mth-card active" onclick="selMth('manual',this)" id="mth-manual">
        <span class="mth-icon">✍️</span><div class="mth-name">Manual</div>
      </div>
      <div class="mth-card" onclick="selMth('physical',this)" id="mth-physical">
        <span class="mth-icon">📟</span><div class="mth-name">Physical</div>
      </div>
      <div class="mth-card" onclick="selMth('nfc',this)" id="mth-nfc">
        <span class="mth-icon">📡</span><div class="mth-name">NFC</div>
      </div>
    </div>
    <div class="nfc-bar" id="nfcBar">
      <div class="nfc-pulse"></div>
      <span><?=$ar?'NFC جاهز':'NFC ready — tap card'?></span>
    </div>
  </div>

  <button class="pay-btn" id="payBtn" onclick="processBank()" style="margin-bottom:20px">
    <i class="fas fa-paper-plane"></i>
    <?=$ar?'تأكيد التحويل عبر':'Confirm via'?> <?=htmlspecialchars($BANK_CONFIG['name'])?>
  </button>

  <!-- History -->
  <div class="card">
    <div class="card-title"><i class="fas fa-history"></i> <?=$ar?'آخر 10 عمليات':'Last 10 Transactions'?></div>
    <?php if(!empty($bankHistory)): ?>
    <div style="overflow-x:auto">
      <table class="hist-tbl">
        <thead><tr>
          <th><?=$ar?'المرجع':'Ref'?></th>
          <th><?=$ar?'المبلغ':'Amount'?></th>
          <th><?=$ar?'الحالة':'Status'?></th>
          <th><?=$ar?'التاريخ':'Date'?></th>
        </tr></thead>
        <tbody>
          <?php foreach($bankHistory as $t): ?>
          <tr>
            <td style="font-family:'Share Tech Mono',monospace;font-size:.65rem"><?=htmlspecialchars(substr($t['reference']??'—',0,16))?></td>
            <td style="font-weight:700"><?=number_format(floatval($t['amount']??0),2)?> <?=htmlspecialchars($t['currency']??'')?></td>
            <td><span class="sp sp-<?=htmlspecialchars($t['status']??'pending')?>"><?=htmlspecialchars($t['status']??'—')?></span></td>
            <td style="color:var(--muted2);font-size:.66rem"><?=date('d/m/y H:i',strtotime($t['created_at']??'now'))?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div style="text-align:center;padding:20px;color:var(--muted2);font-size:.78rem">
      <i class="fas fa-inbox" style="font-size:1.6rem;display:block;margin-bottom:8px;opacity:.25"></i>
      <?=$ar?'لا توجد عمليات سابقة':'No transactions'?>
    </div>
    <?php endif; ?>
  </div>

</div><!-- /left -->

<!-- SIDEBAR -->
<div>
  <div class="card" style="position:sticky;top:80px">
    <div class="card-title"><i class="fas fa-receipt"></i> <?=$ar?'ملخص':'Summary'?></div>
    <div class="sum-row"><span class="sum-key"><?=$ar?'البنك:':'Bank:'?></span><span style="color:var(--bank)"><?=htmlspecialchars($BANK_CONFIG['name'])?></span></div>
    <?php if(isset($BANK_CONFIG['fields']['IBAN'])): ?>
    <div class="sum-row"><span class="sum-key">IBAN:</span><span style="font-family:'Share Tech Mono',monospace;font-size:.66rem"><?=htmlspecialchars(substr($BANK_CONFIG['fields']['IBAN'],0,22))?></span></div>
    <?php endif; ?>
    <?php if(isset($BANK_CONFIG['fields']['SWIFT'])): ?>
    <div class="sum-row"><span class="sum-key">SWIFT:</span><span style="font-family:'Share Tech Mono',monospace"><?=htmlspecialchars($BANK_CONFIG['fields']['SWIFT'])?></span></div>
    <?php endif; ?>
    <div class="sum-row" id="sumTxnType"><span class="sum-key"><?=$ar?'النوع:':'Type:'?></span><span><?=$ar?'شراء':'Purchase'?></span></div>
    <div class="sum-row" id="sumMethod"><span class="sum-key"><?=$ar?'الطريقة:':'Method:'?></span><span>Manual</span></div>
    <div class="sum-row" id="sumDest"><span class="sum-key"><?=$ar?'الوجهة:':'Dest:'?></span><span style="color:var(--bank)"><?=$ar?'نفس البنك':'Same Bank'?></span></div>
    <div class="sum-row"><span class="sum-key"><?=$ar?'الإجمالي:':'Total:'?></span><span id="sumTotal"><?=$amount>0?number_format($amount,2).' '.$currency:'—'?></span></div>

    <a href="../checkout_router.php" class="btn btn-dark" style="margin-top:14px;font-size:.74rem;width:100%;justify-content:center">
      <i class="fas fa-exchange-alt"></i> <?=$ar?'تغيير البوابة':'Change Gateway'?>
    </a>
  </div>
</div>
</div><!-- /layout -->

<!-- Result -->
<div class="res-overlay" id="resOverlay">
  <div class="res-box">
    <div style="font-size:3.2rem;margin-bottom:12px" id="resIcon2">✅</div>
    <div style="font-size:1.1rem;font-weight:900;margin-bottom:8px" id="resTitle2"></div>
    <div style="font-family:'Share Tech Mono',monospace;font-size:.7rem;color:var(--muted2);margin-bottom:14px" id="resRef2"></div>
    <div style="background:rgba(255,255,255,.03);border-radius:10px;padding:12px;font-size:.76rem;text-align:<?=$ar?'right':'left'?>;margin-bottom:14px" id="resDetails2"></div>
    <div style="display:flex;gap:8px;justify-content:center">
      <button class="btn btn-dark" onclick="document.getElementById('resOverlay').classList.remove('show')"><i class="fas fa-times"></i> <?=$ar?'إغلاق':'Close'?></button>
      <a href="../dashboard.php" class="btn btn-bank"><i class="fas fa-th-large"></i> <?=$ar?'لوحة التحكم':'Dashboard'?></a>
    </div>
  </div>
</div>

<div id="toast"></div>

<script>
const AR=<?=$ar?'true':'false'?>;const CSRF='<?=$csrf?>';const REF='<?=$ref?>';
const BNK_CODE='<?=$BANK_CONFIG['gateway_code']?>';
const STATE2={txnType:'<?=$txnTypeInit?>',dest:'<?=$destination?>',method:'manual',secMode:'3D'};

// ── Sec Mode ─────────────────────────────────────────────────
function selSecMode(mode,el){
  STATE2.secMode=mode;
  ['3D','2D'].forEach(m=>{
    const b=document.getElementById('bsm-'+m);
    if(!b)return;
    if(m===mode){b.style.borderColor='var(--bank)';b.style.background='rgba(255,255,255,.04)';b.style.color='var(--bank)';}
    else{b.style.borderColor='var(--border)';b.style.background='rgba(255,255,255,.03)';b.style.color='var(--muted2)';}
  });
}

function selTxn(t,el){STATE2.txnType=t;document.querySelectorAll('.txn-tab').forEach(x=>x.classList.remove('active'));el.classList.add('active');const lab={purchase:AR?'شراء':'Purchase',auth:AR?'تفويض':'Auth',refund:AR?'استرداد':'Refund',transfer:AR?'تحويل':'Transfer'};document.getElementById('sumTxnType').querySelector('span:last-child').textContent=lab[t]||t;}
function selDest(c,el){STATE2.dest=c;document.querySelectorAll('.dest-opt').forEach(x=>x.classList.remove('active'));el.classList.add('active');document.getElementById('ledgerWalletField').style.display=c==='ledger_trx'?'':'none';document.getElementById('customDestField').style.display=c==='custom'?'':'none';const n={gateway:AR?'نفس البنك':'Same Bank',ledger_trx:'Ledger TRX',mashreq:'Mashreq',custom:AR?'مخصص':'Custom'};document.getElementById('sumDest').querySelector('span:last-child').textContent=n[c]||c;}
function selMth(m,el){STATE2.method=m;document.querySelectorAll('.mth-card').forEach(x=>x.classList.remove('active'));el.classList.add('active');document.getElementById('nfcBar').className='nfc-bar'+(m==='nfc'?' show':'');document.getElementById('sumMethod').querySelector('span:last-child').textContent=m;if(m==='nfc')initNFC2();}
async function initNFC2(){if('NDEFReader' in window){try{const nf=new NDEFReader();await nf.scan();nf.onreading=e=>{toast(AR?'✅ NFC قُرئت':'✅ NFC read','success');processBank({nfc_serial:e.serialNumber});};}catch(e){toast('NFC: '+e.message,'error');}}else toast(AR?'NFC غير مدعوم':'NFC not supported','error');}
function updateSum(){const a=parseFloat(document.getElementById('txnAmount').value)||0;const c=document.getElementById('txnCurrency').value;document.getElementById('sumTotal').textContent=a>0?a.toFixed(2)+' '+c:'—';}
function copyText(txt){navigator.clipboard?.writeText(txt).then(()=>toast(AR?'تم النسخ':'Copied','success'));}

async function processBank(extra={}){
  const btn=document.getElementById('payBtn');
  const amount=parseFloat(document.getElementById('txnAmount').value)||0;
  const currency=document.getElementById('txnCurrency').value;
  const name=document.getElementById('senderName').value.trim();
  const email=document.getElementById('senderEmail').value.trim();
  const tref=document.getElementById('transferRef').value.trim();
  if(amount<=0)return toast(AR?'أدخل المبلغ':'Enter amount','error');
  if(!name)return toast(AR?'أدخل اسمك':'Enter name','error');

  btn.disabled=true;btn.innerHTML='<span class="spin"></span>';

  const wallet=document.getElementById('ledgerAddr')?.value||'TEwLFWlwK55b7PuFfzgH1H2f3xs3pLgLn2';
  const custDest={name:document.getElementById('custDestName')?.value||'',iban:document.getElementById('custDestIban')?.value||''};

  try{
    const r=await fetch('../api/pos_transaction.php',{method:'POST',headers:{'Content-Type':'application/json'},
      credentials:'include',
      body:JSON.stringify({txn_type:STATE2.txnType,amount,currency,destination:STATE2.dest,reference:REF,
        card_name:name,email:email||'client@diparmas.com',csrf_token:CSRF,
        ledger_address:wallet,auto_transfer:STATE2.dest==='ledger_trx',
        orig_ref:document.getElementById('bankOrigRef')?.value||'',
        pos_device:'BANK_'+BNK_CODE.toUpperCase(),
        extra:{bank:BNK_CODE,transfer_ref:tref,method:STATE2.method,sec_mode:STATE2.secMode,custom_dest:custDest,...extra}})});
    const d=await r.json();
    showBankResult(d,amount,currency);
  }catch(e){toast(AR?'خطأ':'Error','error');}
  btn.disabled=false;btn.innerHTML='<i class="fas fa-paper-plane"></i> '+(AR?'تأكيد التحويل عبر':'Confirm via ')+' <?=htmlspecialchars($BANK_CONFIG['name'])?>';
}

function showBankResult(d,amt,cur){
  document.getElementById('resOverlay').classList.add('show');
  document.getElementById('resIcon2').textContent=d.success?'✅':'❌';
  document.getElementById('resTitle2').textContent=d.success?(AR?'تم التسجيل بنجاح':'Transfer Recorded'):(AR?'فشل':'Failed');
  document.getElementById('resTitle2').style.color=d.success?'var(--green)':'var(--red)';
  document.getElementById('resRef2').textContent='REF: '+(d.reference||REF);
  document.getElementById('resDetails2').innerHTML=`<div style="display:flex;justify-content:space-between;padding:4px 0"><span>${AR?'البنك':'Bank'}</span><span>${'<?=htmlspecialchars($BANK_CONFIG["name"])?>'}</span></div><div style="display:flex;justify-content:space-between;padding:4px 0"><span>${AR?'المبلغ':'Amount'}</span><span>${amt.toFixed(2)} ${cur}</span></div><div style="display:flex;justify-content:space-between;padding:4px 0"><span>Status</span><span style="color:${d.success?'var(--green)':'var(--red)'}">${d.status_message||'—'}</span></div>`;
}

function toast(msg,type='info'){const t=document.getElementById('toast');const c={success:'var(--green)',error:'var(--red)',info:'var(--gold)'};t.style.borderColor=c[type]||c.info;t.style.color=c[type]||c.info;t.textContent=msg;t.style.transform='translateX(-50%) translateY(0)';clearTimeout(t._t);t._t=setTimeout(()=>{t.style.transform='translateX(-50%) translateY(100px)';},4500);}

document.addEventListener('DOMContentLoaded',()=>{if(<?=$amount?>>0)updateSum();selDest('<?=$destination?>',document.getElementById('dest-<?=$destination?>'));});
</script>
</body>
</html>
