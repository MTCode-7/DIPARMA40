<?php
/**
 * ============================================================
 * DI PARMA | PayRam × Ledger
 * ============================================================
 * شراء مباشر → PayRam → Ledger TRX (USDT)
 * Self-hosted · No middleman · Your keys
 * ============================================================
 */
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/lib/PayRamAdapter.php';

$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'en' ? 'en' : 'ar';
$ar   = ($lang === 'ar');
$dir  = $ar ? 'rtl' : 'ltr';
$csrf = generateCsrfToken();

$payram   = new PayRamAdapter();
$payramConnected = false;
$ledgerAddr = defined('LEDGER_TRC20_ADDRESS') ? LEDGER_TRC20_ADDRESS
            : (getenv('LEDGER_TRC20_ADDRESS') ?: '');

/* أسعار العملات */
$tickers  = [];
$trxPrice = null;
$usdtPrice= null;
try {
    $tickers = $payram->getTickers();
    foreach ($tickers as $t) {
        if ($t['blockchainCode']==='TRX' && $t['currencyCode']==='TRX')  $trxPrice  = (float)$t['price'];
        if ($t['blockchainCode']==='TRX' && $t['currencyCode']==='USDT') $usdtPrice = (float)$t['price'];
    }
} catch (Exception $e) {}
$payramConnected = $payram->checkConnection();

/* 16 نوع عملية مالية حقيقية */
$TXN = [
  'purchase_3d'      => ['ar'=>'شراء 3D Secure',     'en'=>'Purchase 3D',       'icon'=>'fa-shield-alt',   'c'=>'#10B981','sub'=>'3D Secure',      'needs_rrn'=>false],
  'purchase_moto'    => ['ar'=>'شراء MOTO / 2D',     'en'=>'Purchase 2D/MOTO',  'icon'=>'fa-credit-card',  'c'=>'#06B6D4','sub'=>'2D / MOTO',       'needs_rrn'=>false],
  'auth'             => ['ar'=>'تفويض',              'en'=>'Authorization',     'icon'=>'fa-lock',         'c'=>'#3B82F6','sub'=>'Hold',            'needs_rrn'=>false],
  'auth_complete'    => ['ar'=>'إتمام تفويض',        'en'=>'Auth Completion',   'icon'=>'fa-check-double', 'c'=>'#6366F1','sub'=>'Capture · RRN',   'needs_rrn'=>true],
  'purchase_advice'  => ['ar'=>'إشعار شراء',         'en'=>'Purchase Advice',   'icon'=>'fa-bell',         'c'=>'#F59E0B','sub'=>'Offline 2D · RRN','needs_rrn'=>true],
  'offline_purchase' => ['ar'=>'شراء أوفلاين',       'en'=>'Offline Purchase',  'icon'=>'fa-server',       'c'=>'#F97316','sub'=>'MOTO 2D · RRN',   'needs_rrn'=>true],
  'online_purchase'  => ['ar'=>'شراء أونلاين',       'en'=>'Online Purchase',   'icon'=>'fa-globe',        'c'=>'#8B5CF6','sub'=>'MOTO 2D · RRN',   'needs_rrn'=>true],
  'refund'           => ['ar'=>'استرداد',            'en'=>'Refund',            'icon'=>'fa-undo',         'c'=>'#EF4444','sub'=>'Return',          'needs_rrn'=>true],
  'reversal'         => ['ar'=>'إلغاء عملية',       'en'=>'Reversal',          'icon'=>'fa-reply',        'c'=>'#EC4899','sub'=>'Same Day · RRN',  'needs_rrn'=>true],
  'balance'          => ['ar'=>'استعلام رصيد',      'en'=>'Balance Inquiry',   'icon'=>'fa-wallet',       'c'=>'#8B5CF6','sub'=>'Inquiry',         'needs_rrn'=>false],
  'cash_advance'     => ['ar'=>'سلفة نقدية',        'en'=>'Cash Advance',      'icon'=>'fa-money-bill',   'c'=>'#14B8A6','sub'=>'Advance',         'needs_rrn'=>false],
  'void'             => ['ar'=>'إلغاء',              'en'=>'Void',              'icon'=>'fa-ban',          'c'=>'#6B7280','sub'=>'Pre-Settlement',  'needs_rrn'=>true],
  'settlement'       => ['ar'=>'تسوية EOD',          'en'=>'Settlement',        'icon'=>'fa-university',   'c'=>'#FFD700','sub'=>'End of Day',      'needs_rrn'=>false],
  'quasi_cash'       => ['ar'=>'شبه نقدي',          'en'=>'Quasi Cash',        'icon'=>'fa-coins',        'c'=>'#F97316','sub'=>'QC / حوالات',    'needs_rrn'=>false],
  'transfer'         => ['ar'=>'تحويل P2P',          'en'=>'Transfer',          'icon'=>'fa-exchange-alt', 'c'=>'#06B6D4','sub'=>'P2P',             'needs_rrn'=>false],
  'payment'          => ['ar'=>'دفع فاتورة',        'en'=>'Bill Payment',      'icon'=>'fa-file-invoice', 'c'=>'#A855F7','sub'=>'Bill',            'needs_rrn'=>false],
];

/* شبكات PayRam */
$CHAINS = [
  ['code'=>'BASE',    'token'=>'USDC',  'name'=>'Base',     'icon'=>'🔵','color'=>'#3B82F6','sub'=>'Card Onramp · ~$0.01','recommended'=>true],
  ['code'=>'TRX',     'token'=>'USDT',  'name'=>'Tron',     'icon'=>'♦','color'=>'#EF4444','sub'=>'TRC20 · ~$0.01'],
  ['code'=>'TRX',     'token'=>'TRX',   'name'=>'Tron',     'icon'=>'♦','color'=>'#EF4444','sub'=>'Native · ~$0.01'],
  ['code'=>'ETH',     'token'=>'USDT',  'name'=>'Ethereum', 'icon'=>'Ξ','color'=>'#627EEA','sub'=>'ERC20 · ~$2'],
  ['code'=>'POLYGON', 'token'=>'USDT',  'name'=>'Polygon',  'icon'=>'⬡','color'=>'#8247E5','sub'=>'~$0.01'],
  ['code'=>'ETH',     'token'=>'ETH',   'name'=>'Ethereum', 'icon'=>'Ξ','color'=>'#627EEA','sub'=>'Native'],
];
?><!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$dir?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<meta name="theme-color" content="#020508">
<title>DI PARMA | PayRam × Ledger</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
:root{
  --gold:#FFD700;--gold2:#FFB700;
  --bg:#020508;--card:#070e1c;--card2:#0a1224;
  --border:rgba(255,215,0,.11);--border2:rgba(255,215,0,.26);
  --text:#edf0f7;--muted:#3d4a5c;--muted2:#6b7a90;
  --green:#10B981;--red:#EF4444;--blue:#3B82F6;--tron:#EF4444;
  --payram:#00d4a8;
}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;overflow-x:hidden}

/* ═══ TOPBAR ═══ */
.topbar{
  height:54px;background:rgba(2,5,8,.97);border-bottom:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between;
  padding:0 18px;position:sticky;top:0;z-index:200;
}
.tb-brand{display:flex;align-items:center;gap:8px;color:var(--gold);font-weight:900;font-size:.9rem}
.tb-payram{background:rgba(0,212,168,.1);border:1px solid rgba(0,212,168,.3);border-radius:8px;padding:3px 10px;font-size:.65rem;font-weight:800;color:var(--payram)}
.tb-right{display:flex;align-items:center;gap:8px}
.tb-link{color:var(--muted2);font-size:.72rem;padding:5px 10px;border-radius:12px;text-decoration:none}
.tb-link:hover{color:var(--gold)}

/* ═══ LEDGER BAR ═══ */
.ledger-bar{
  background:rgba(0,0,0,.5);border-bottom:1px solid rgba(16,185,129,.15);
  padding:5px 18px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:.67rem;
}
.ldg-addr{font-family:monospace;color:var(--green);background:rgba(16,185,129,.07);border:1px solid rgba(16,185,129,.15);padding:3px 9px;border-radius:8px;font-size:.62rem}
.ldg-bal{color:var(--green);font-weight:800}
.ldg-refresh{background:none;border:none;color:var(--green);cursor:pointer;font-size:.72rem;padding:2px 6px}

/* ═══ LAYOUT ═══ */
.layout{display:grid;grid-template-columns:1fr 280px;min-height:calc(100vh - 88px)}
@media(max-width:860px){.layout{grid-template-columns:1fr}.side{display:none}}

/* ═══ MAIN ═══ */
.main{padding:18px 16px;border-right:1px solid var(--border)}
.sec-label{font-size:.65rem;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:1.3px;margin-bottom:8px;display:flex;align-items:center;gap:6px}

/* TXN GRID */
.txn-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-bottom:18px}
.txn-btn{background:var(--card);border:1.5px solid var(--border);border-radius:11px;padding:8px 4px;cursor:pointer;text-align:center;transition:.2s}
.txn-btn:hover{border-color:rgba(255,215,0,.2)}
.txn-btn.active{border-color:var(--gold);background:rgba(255,215,0,.05)}
.txn-ico{font-size:.85rem;margin-bottom:2px}
.txn-nm{font-size:.57rem;font-weight:800;color:var(--muted2);line-height:1.3}
.txn-btn.active .txn-nm{color:var(--gold)}
.txn-sb{font-size:.5rem;color:var(--muted);margin-top:2px}

/* AMOUNT */
.amount-box{
  background:var(--card2);border:1px solid var(--border);border-radius:14px;
  padding:14px;margin-bottom:16px;
}
.amount-hero{font-size:2rem;font-weight:900;color:var(--gold);text-align:center;line-height:1}
.amount-subs{display:flex;justify-content:center;gap:10px;margin-top:8px;flex-wrap:wrap}
.amount-sub{background:rgba(16,185,129,.07);border:1px solid rgba(16,185,129,.2);border-radius:7px;padding:3px 10px;font-size:.7rem;color:var(--green);font-weight:700}
.amount-sub.tron{background:rgba(239,68,68,.07);border-color:rgba(239,68,68,.2);color:var(--tron)}
.amount-inputs{display:grid;grid-template-columns:1fr auto;gap:8px;margin-top:10px}
.amt-inp{width:100%;background:rgba(255,255,255,.04);border:1.5px solid var(--border);border-radius:10px;padding:10px 12px;color:var(--gold);font-family:'Cairo',sans-serif;font-size:1.1rem;font-weight:800;text-align:center}
.amt-inp:focus{outline:none;border-color:var(--gold)}
.cur-sel{background:rgba(255,215,0,.1);color:var(--gold);border:1px solid rgba(255,215,0,.2);border-radius:10px;padding:10px 12px;font-family:'Cairo',sans-serif;font-size:.85rem;font-weight:800;cursor:pointer}

/* CHAINS */
.chain-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:16px}
.chain-card{background:var(--card2);border:1.5px solid var(--border);border-radius:12px;padding:10px 6px;cursor:pointer;text-align:center;transition:.2s;position:relative}
.chain-card:hover{border-color:rgba(255,215,0,.2)}
.chain-card.active{border-color:var(--gold);background:rgba(255,215,0,.04)}
.chain-recommended{position:absolute;top:4px;right:4px;font-size:.5rem;background:rgba(16,185,129,.2);color:var(--green);padding:1px 5px;border-radius:4px;font-weight:800}
.chain-ico{font-size:1.3rem;margin-bottom:3px}
.chain-nm{font-size:.72rem;font-weight:800}
.chain-token{font-size:.62rem;color:var(--muted2)}
.chain-fee{font-size:.6rem;color:var(--green);margin-top:2px}

/* CUSTOMER */
.fld{margin-bottom:12px}
.fld label{display:block;font-size:.67rem;color:var(--muted2);margin-bottom:4px;font-weight:700}
.fld input{width:100%;background:rgba(255,255,255,.04);border:1.5px solid var(--border);border-radius:10px;padding:10px 12px;color:var(--text);font-family:'Cairo',sans-serif;font-size:.83rem}
.fld input:focus{outline:none;border-color:var(--gold)}

/* ORIG REF */
.orig-wrap{display:none;margin-bottom:12px}
.orig-wrap.show{display:block}

/* PAY BTN */
.pay-btn{
  width:100%;padding:15px;border-radius:14px;border:none;cursor:pointer;
  font-family:'Cairo',sans-serif;font-size:.95rem;font-weight:900;
  background:linear-gradient(135deg,var(--payram),#00a87d);color:#000;
  box-shadow:0 8px 24px rgba(0,212,168,.25);transition:.3s;
  display:flex;align-items:center;justify-content:center;gap:10px;
}
.pay-btn:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 12px 32px rgba(0,212,168,.35)}
.pay-btn:disabled{opacity:.35;cursor:not-allowed;transform:none}
.pay-btn.loading{background:var(--card2);color:var(--muted2);border:1px solid var(--border);box-shadow:none}

/* RESULT */
.result-box{background:var(--card2);border:1px solid var(--border2);border-radius:14px;padding:16px;margin-top:14px;display:none}
.result-box.show{display:block}
.res-row{display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:.74rem}
.res-row:last-child{border:none}
.res-k{color:var(--muted2)}.res-v{font-weight:700}

/* DEPOSIT INFO */
.deposit-box{background:rgba(0,212,168,.05);border:1px solid rgba(0,212,168,.2);border-radius:12px;padding:12px;margin-top:12px;display:none}
.deposit-box.show{display:block}
.deposit-addr{font-family:monospace;font-size:.68rem;word-break:break-all;color:var(--payram);background:rgba(0,212,168,.06);padding:8px 10px;border-radius:8px;margin:8px 0;line-height:1.6}
.copy-btn{background:none;border:1px solid rgba(0,212,168,.3);border-radius:6px;padding:4px 10px;color:var(--payram);font-size:.65rem;cursor:pointer;font-family:'Cairo',sans-serif}
.poll-status{font-size:.7rem;color:var(--muted2);margin-top:6px;text-align:center}

/* STATUS BADGE */
.status-badge{display:inline-block;padding:3px 10px;border-radius:6px;font-size:.65rem;font-weight:800}
.st-open{background:rgba(255,215,0,.1);color:var(--gold)}
.st-partial{background:rgba(249,115,22,.1);color:#F97316}
.st-filled{background:rgba(16,185,129,.1);color:var(--green)}
.st-cancelled{background:rgba(239,68,68,.1);color:var(--red)}

/* ═══ SIDE PANEL ═══ */
.side{background:var(--card2);border-left:1px solid var(--border);padding:14px;display:flex;flex-direction:column;gap:12px;overflow-y:auto}
.panel-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:13px}
.panel-title{font-size:.67rem;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:1.2px;margin-bottom:10px;display:flex;align-items:center;gap:6px}

/* LEDGER PANEL */
.ldg-bal-big{font-size:1.5rem;font-weight:900;color:var(--green);text-align:center;padding:6px 0 2px}
.ldg-bal-sub{font-size:.67rem;color:var(--muted2);text-align:center;margin-bottom:8px}
.ldg-addr-box{font-family:monospace;font-size:.6rem;word-break:break-all;color:var(--muted2);background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:8px;padding:7px 9px}

/* RECENT */
.txn-item{display:flex;align-items:center;gap:6px;padding:5px 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:.68rem}
.txn-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0}
.txn-ref{font-family:monospace;color:var(--muted2);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.6rem}
.txn-amt{color:var(--gold);font-weight:700}

/* TOAST */
#toast{position:fixed;bottom:22px;left:50%;transform:translateX(-50%) translateY(100px);background:var(--card);border:1px solid var(--border2);border-radius:13px;padding:10px 22px;font-size:.8rem;font-weight:700;z-index:9999;transition:.35s}

/* PRINT */
@media print{body *{visibility:hidden}#receipt,#receipt *{visibility:visible}#receipt{position:absolute;top:0;left:0;width:80mm;font-family:monospace;font-size:11px;color:#000}}
::-webkit-scrollbar{width:4px}::-webkit-scrollbar-thumb{background:rgba(0,212,168,.15);border-radius:4px}
</style>
</head>
<body>

<!-- TOPBAR -->
<header class="topbar">
  <div class="tb-brand">
    <i class="fas fa-coins" style="color:var(--gold)"></i> DI PARMA
    <span class="tb-payram"><i class="fas fa-server"></i> PayRam</span>
  </div>
  <div class="tb-right">
    <a href="checkout_router.php" class="tb-link"><i class="fas fa-exchange-alt"></i></a>
    <a href="dashboard.php" class="tb-link"><i class="fas fa-th-large"></i></a>
  </div>
</header>

<!-- LEDGER BAR -->
<div class="ledger-bar">
  <i class="fas fa-wallet" style="color:var(--green)"></i>
  <span style="color:var(--muted2);font-size:.65rem">Ledger TRX:</span>
  <span class="ldg-addr"><?=substr($ledgerAddr,0,12)?>…<?=substr($ledgerAddr,-6)?></span>
  <span class="ldg-bal" id="topLedgerBal">— USDT</span>
  <button class="ldg-refresh" onclick="refreshBal()"><i class="fas fa-sync-alt" id="balIco"></i></button>
  <span style="margin-left:auto;font-size:.6rem;color:var(--muted2)">
    PayRam: <span style="color:var(--payram)"><?=$payramConnected?'✓ Connected':($payram->isConfigured()?'⚠ Unreachable':'⚠ Not configured')?></span>
  </span>
</div>

<!-- LAYOUT -->
<div class="layout">

<!-- ═══ MAIN ═══ -->
<div class="main">

  <!-- نوع العملية المدعوم -->
  <div class="sec-label"><i class="fas fa-list"></i> <?=$ar?'نوع العملية':'Transaction Type'?></div>
  <div class="txn-grid">
    <?php foreach($TXN as $code=>$t): ?>
    <div class="txn-btn <?=$code==='purchase_3d'?'active':''?>"
         onclick="selTxn('<?=$code?>',this)"
         id="tt-<?=$code?>"
         data-needs-orig="<?=$t['needs_rrn']?'1':'0'?>">
      <div class="txn-ico"><i class="fas <?=$t['icon']?>" style="color:<?=$t['c']?>"></i></div>
      <div class="txn-nm"><?=$ar?$t['ar']:$t['en']?></div>
      <div class="txn-sb"><?=$t['sub']?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- المبلغ -->
  <div class="amount-box">
    <div class="amount-inputs">
      <input class="amt-inp" type="number" id="txnAmount" min="1" step="0.01"
             placeholder="0.00" oninput="updAmounts()">
      <select class="cur-sel" id="txnCurrency" onchange="updAmounts()">
        <option>USD</option><option>AED</option><option>SAR</option>
        <option>EUR</option><option>GBP</option><option>KWD</option>
        <option>EGP</option><option>QAR</option>
      </select>
    </div>
    <div class="amount-hero" id="amtHero">0.00</div>
    <div class="amount-subs">
      <span class="amount-sub" id="usdtAmt">≈ 0.0000 USDT</span>
      <span class="amount-sub tron" id="trxAmt">≈ 0.00 TRX</span>
    </div>
  </div>

  <!-- الشبكة -->
  <div class="sec-label"><i class="fas fa-network-wired"></i> <?=$ar?'الشبكة والعملة':'Network & Currency'?></div>
  <div class="chain-grid">
    <?php foreach($CHAINS as $i=>$c): ?>
    <div class="chain-card <?=$i===0?'active':''?>"
         onclick="selChain('<?=$c['code']?>','<?=$c['token']?>',this)">
      <?php if(!empty($c['recommended'])): ?>
      <div class="chain-recommended">✓ Best</div>
      <?php endif; ?>
      <div class="chain-ico" style="color:<?=$c['color']?>"><?=$c['icon']?></div>
      <div class="chain-nm"><?=$c['name']?></div>
      <div class="chain-token"><?=$c['token']?></div>
      <div class="chain-fee"><?=$c['sub']?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- بيانات العميل -->
  <div class="sec-label"><i class="fas fa-user"></i> <?=$ar?'بيانات العميل':'Customer'?></div>

  <div class="orig-wrap" id="origWrap">
    <div class="fld">
      <label><i class="fas fa-hashtag"></i> <?=$ar?'رقم المرجع الأصلي (RRN)':'Original Reference (RRN)'?> <span style="color:var(--red)">*</span></label>
      <input type="text" id="origRef" placeholder="<?=$ar?'RRN / Approval Code / رقم العملية السابقة':'RRN / Approval Code / Previous TXN reference'?>">
    </div>
  </div>

  <div class="fld">
    <label>Email</label>
    <input type="email" id="custEmail" placeholder="customer@example.com" value="client@diparmas.com">
  </div>
  <div class="fld">
    <label><?=$ar?'ملاحظات':'Notes'?> <span style="opacity:.4">(<?=$ar?'اختياري':'optional'?>)</span></label>
    <input type="text" id="txnNotes" placeholder="<?=$ar?'رقم الفاتورة، اسم العميل...':'Invoice #, client...'?>">
  </div>

  <!-- زر الدفع -->
  <button class="pay-btn" id="payBtn" onclick="createPayment()">
    <i class="fas fa-server" id="payIco"></i>
    <span id="payLbl"><?=$ar?'دفع عبر PayRam → Ledger':'Pay via PayRam → Ledger'?></span>
  </button>

  <!-- معلومات الإيداع -->
  <div class="deposit-box" id="depositBox">
    <div style="font-size:.78rem;font-weight:800;color:var(--payram);margin-bottom:6px">
      <i class="fas fa-check-circle"></i> <?=$ar?'تم إنشاء الدفعة':'Payment Created'?>
    </div>
    <div style="font-size:.68rem;color:var(--muted2)"><?=$ar?'أرسل الكريبتو لهذا العنوان:':'Send crypto to this address:'?></div>
    <div class="deposit-addr" id="depositAddr">—</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px">
      <button class="copy-btn" onclick="copyAddr()"><i class="fas fa-copy"></i> <?=$ar?'نسخ':'Copy'?></button>
      <a id="payramPageLink" href="#" target="_blank"
         style="background:none;border:1px solid rgba(0,212,168,.3);border-radius:6px;padding:4px 10px;color:var(--payram);font-size:.65rem;text-decoration:none">
        <i class="fas fa-external-link-alt"></i> <?=$ar?'صفحة الدفع':'Payment Page'?>
      </a>
      <a id="receiptLink" href="#" target="_blank"
         style="background:none;border:1px solid rgba(255,215,0,.3);border-radius:6px;padding:4px 10px;color:var(--gold);font-size:.65rem;text-decoration:none;display:none">
        <i class="fas fa-receipt"></i> <?=$ar?'الإيصال':'Receipt'?>
      </a>
    </div>
    <div style="font-size:.68rem">
      <span style="color:var(--muted2)"><?=$ar?'المرجع:':'Ref:'?></span>
      <span id="payRef" style="font-family:monospace;color:var(--gold)">—</span>
    </div>
    <div id="pollStatus" class="poll-status"></div>
    <div id="statusBadgeWrap" style="text-align:center;margin-top:8px"></div>
  </div>

  <!-- Result -->
  <div class="result-box" id="resultBox"></div>

</div><!-- /main -->

<!-- ═══ SIDE PANEL ═══ -->
<div class="side">

  <!-- Ledger Balance -->
  <div class="panel-card">
    <div class="panel-title"><i class="fas fa-wallet" style="color:var(--green)"></i> Ledger TRX</div>
    <div class="ldg-bal-big" id="sideLedgerBal">—</div>
    <div class="ldg-bal-sub">USDT · TRC20</div>
    <div class="ldg-addr-box"><?=htmlspecialchars($ledgerAddr)?></div>
    <div style="display:flex;gap:7px;margin-top:8px">
      <button onclick="navigator.clipboard?.writeText('<?=htmlspecialchars($ledgerAddr)?>')"
        style="flex:1;padding:5px;border-radius:7px;border:1px solid var(--border);background:rgba(255,255,255,.04);color:var(--muted2);font-size:.65rem;cursor:pointer;font-family:'Cairo',sans-serif">
        <i class="fas fa-copy"></i> <?=$ar?'نسخ':'Copy'?>
      </button>
      <button onclick="refreshBal()"
        style="flex:1;padding:5px;border-radius:7px;border:1px solid rgba(16,185,129,.2);background:rgba(16,185,129,.06);color:var(--green);font-size:.65rem;cursor:pointer;font-family:'Cairo',sans-serif">
        <i class="fas fa-sync-alt"></i> <?=$ar?'تحديث':'Refresh'?>
      </button>
    </div>
  </div>

  <!-- PayRam Info -->
  <div class="panel-card">
    <div class="panel-title"><i class="fas fa-server" style="color:var(--payram)"></i> PayRam</div>
    <?php
    $rows = [
      ['Status', $payramConnected ? '✓ Connected' : ($payram->isConfigured() ? '⚠ Unreachable' : '⚠ Not set')],
      ['Endpoint', substr($payram->getBaseUrl(),0,28).'…'],
      ['Network', 'Mainnet'],
      ['Webhook', 'diparmas.com/api/payram_webhook.php'],
    ];
    foreach($rows as [$k,$v]):
    ?>
    <div style="display:flex;justify-content:space-between;padding:4px 0;font-size:.67rem;border-bottom:1px solid rgba(255,255,255,.04)">
      <span style="color:var(--muted2)"><?=$k?></span>
      <span style="font-weight:700;color:var(--payram)"><?=htmlspecialchars($v)?></span>
    </div>
    <?php endforeach; ?>
    <div style="margin-top:8px;text-align:center">
      <a href="http://65.2.184.57:8080" target="_blank"
         style="font-size:.65rem;color:var(--payram);text-decoration:none">
        <i class="fas fa-external-link-alt"></i> Dashboard
      </a>
    </div>
  </div>

  <!-- Recent Transactions -->
  <div class="panel-card">
    <div class="panel-title"><i class="fas fa-history" style="color:var(--blue)"></i> <?=$ar?'آخر العمليات':'Recent'?></div>
    <div id="recentTxns">
      <div style="font-size:.65rem;color:var(--muted2);text-align:center;padding:8px"><?=$ar?'جاري التحميل...':'Loading...'?></div>
    </div>
  </div>

</div><!-- /side -->
</div><!-- /layout -->

<div id="receipt" style="display:none"></div>
<div id="toast"></div>
<input type="hidden" id="csrf" value="<?=htmlspecialchars($csrf)?>">
<input type="hidden" id="ledgerAddress" value="<?=htmlspecialchars($ledgerAddr)?>">

<script>
const AR = <?=$ar?'true':'false'?>;
const LEDGER_ADDR = document.getElementById('ledgerAddress').value;
const TRX_PRICE   = <?=json_encode($trxPrice)?>;
const USDT_PRICE  = <?=json_encode($usdtPrice)?>;
const FX = {USD:1,AED:0.2723,SAR:0.2667,EUR:1.082,GBP:1.271,KWD:3.257,EGP:0.0204,QAR:0.2747};

const TXN_LABELS = {
  purchase_3d:      AR?'شراء 3D Secure':'Purchase 3D',
  purchase_moto:    AR?'شراء MOTO / 2D':'Purchase MOTO',
  auth:             AR?'تفويض':'Authorization',
  auth_complete:    AR?'إتمام تفويض':'Auth Completion',
  purchase_advice:  AR?'إشعار شراء':'Purchase Advice',
  offline_purchase: AR?'شراء أوفلاين MOTO':'Offline Purchase',
  online_purchase:  AR?'شراء أونلاين MOTO':'Online Purchase',
  refund:           AR?'استرداد':'Refund',
  reversal:         AR?'إلغاء عملية':'Reversal',
  balance:          AR?'استعلام رصيد':'Balance Inquiry',
  cash_advance:     AR?'سلفة نقدية':'Cash Advance',
  void:             AR?'إلغاء':'Void',
  settlement:       AR?'تسوية':'Settlement',
  quasi_cash:       AR?'شبه نقدي':'Quasi Cash',
  transfer:         AR?'تحويل P2P':'Transfer',
  payment:          AR?'دفع فاتورة':'Bill Payment',
};

let S = { txn:'purchase_3d', chain:'BASE', token:'USDC', refId:null, pollTimer:null };

/* ── TXN TYPE ── */
function selTxn(code, el) {
  S.txn = code;
  document.querySelectorAll('.txn-btn').forEach(b=>b.classList.remove('active'));
  el.classList.add('active');
  const needsOrig = el.dataset.needsOrig === '1';
  document.getElementById('origWrap').className = 'orig-wrap' + (needsOrig?' show':'');
}

/* ── CHAIN ── */
function selChain(code, token, el) {
  S.chain = code; S.token = token;
  document.querySelectorAll('.chain-card').forEach(c=>c.classList.remove('active'));
  el.classList.add('active');
}

/* ── AMOUNTS ── */
function updAmounts() {
  const amt = parseFloat(document.getElementById('txnAmount').value) || 0;
  const cur = document.getElementById('txnCurrency').value;
  const usdAmt = amt * (FX[cur] || 1);
  document.getElementById('amtHero').textContent = usdAmt.toFixed(2) + ' USD';
  document.getElementById('usdtAmt').textContent = USDT_PRICE>0
    ? '≈ ' + (usdAmt/USDT_PRICE).toFixed(4) + ' USDT'
    : (AR?'غير متاح':'Unavailable');
  document.getElementById('trxAmt').textContent  = TRX_PRICE>0
    ? '≈ ' + (usdAmt/TRX_PRICE).toFixed(2) + ' TRX'
    : (AR?'غير متاح':'Unavailable');
}

/* ── CREATE PAYMENT ── */
async function createPayment() {
  const amt   = parseFloat(document.getElementById('txnAmount').value) || 0;
  const cur   = document.getElementById('txnCurrency').value;
  const email = document.getElementById('custEmail').value.trim();
  const usdAmt= amt * (FX[cur] || 1);

  if (amt <= 0)  { toast(AR?'أدخل المبلغ':'Enter amount','error'); return; }
  if (!email)    { toast(AR?'أدخل البريد الإلكتروني':'Enter email','error'); return; }

  // التحقق من RRN للعمليات التي تحتاجه
  const activeBtn = document.querySelector('.txn-btn.active');
  const needsRrn  = activeBtn && activeBtn.dataset.needsOrig === '1';
  const rrn       = document.getElementById('origRef').value.trim();
  if (needsRrn && !rrn) {
    toast(AR?'أدخل رقم المرجع الأصلي (RRN)':'Enter original RRN / reference','error');
    document.getElementById('origRef').focus();
    return;
  }

  const btn = document.getElementById('payBtn');
  btn.disabled = true; btn.classList.add('loading');
  document.getElementById('payIco').className = 'fas fa-spinner fa-spin';
  document.getElementById('payLbl').textContent = AR?'جاري الإنشاء...':'Creating...';

  try {
    const resp = await fetch('api/payram_payment.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      credentials: 'include',
      body: JSON.stringify({
        action          : 'create',
        txn_type        : S.txn,
        amount          : usdAmt,
        currency        : 'USD',
        email           : email,
        customer_id     : 'dp_' + Date.now(),
        blockchain_code : S.chain,
        currency_code   : S.token,
        reference       : 'PR-' + Date.now().toString(36).toUpperCase(),
        notes           : document.getElementById('txnNotes').value,
        orig_ref        : document.getElementById('origRef').value.trim(),
        csrf_token      : document.getElementById('csrf').value,
      }),
    });
    const data = await resp.json();

    if (data.success) {
      S.refId = data.reference_id;

      /* إظهار معلومات الإيداع */
      const box = document.getElementById('depositBox');
      box.classList.add('show');
      document.getElementById('depositAddr').textContent = data.deposit_address || (AR?'جاري التعيين...':'Assigning...');
      document.getElementById('payRef').textContent = data.reference_id;
      document.getElementById('payramPageLink').href = data.url || '#';
      document.getElementById('receiptLink').href  = 'contract_receipt.php?ref=' + data.reference_id;
      document.getElementById('receiptLink').style.display = '';

      if (!data.deposit_address) assignAddress(data.reference_id);

      toast(AR?'تم إنشاء الدفعة ✓':'Payment created ✓','success');
      startPolling(data.reference_id);
      loadRecent();
    } else {
      toast(data.message || 'Failed','error');
    }
  } catch(e) {
    toast(e.message || 'Error','error');
  } finally {
    btn.disabled = false; btn.classList.remove('loading');
    document.getElementById('payIco').className = 'fas fa-server';
    document.getElementById('payLbl').textContent = AR?'دفع عبر PayRam → Ledger':'Pay via PayRam → Ledger';
  }
}

/* ── ASSIGN ADDRESS ── */
async function assignAddress(refId) {
  try {
    const resp = await fetch('api/payram_payment.php', {
      method:'POST', headers:{'Content-Type':'application/json'}, credentials:'include',
      body: JSON.stringify({ action:'assign_address', reference_id:refId, blockchain_code:S.chain, csrf_token:document.getElementById('csrf').value }),
    });
    const d = await resp.json();
    if (d.address) document.getElementById('depositAddr').textContent = d.address;
  } catch(e) {}
}

/* ── POLLING ── */
function startPolling(refId) {
  clearInterval(S.pollTimer);
  let attempts = 0;
  S.pollTimer = setInterval(async () => {
    attempts++;
    if (attempts > 120) { clearInterval(S.pollTimer); return; }
    try {
      const r = await fetch(`api/payram_payment.php?action=status&ref=${refId}`, {credentials:'include'});
      const d = await r.json();
      const status = d.status || 'UNKNOWN';

      document.getElementById('pollStatus').textContent =
        `${AR?'الحالة':'Status'}: ${status} · ${new Date().toLocaleTimeString()}`;

      /* Badge */
      const bdg = document.getElementById('statusBadgeWrap');
      const cls = status==='FILLED'||status==='OVER_FILLED' ? 'st-filled'
                : status==='PARTIALLY_FILLED' ? 'st-partial'
                : status==='CANCELLED' ? 'st-cancelled' : 'st-open';
      bdg.innerHTML = `<span class="status-badge ${cls}">${status}</span>`;

      if (status === 'FILLED' || status === 'OVER_FILLED') {
        clearInterval(S.pollTimer);
        toast(AR?'✅ تم الدفع — المبلغ في طريقه للـ Ledger':'✅ Paid — funds routing to Ledger','success');
        showResult(d, refId);
        loadRecent();
      } else if (status === 'CANCELLED') {
        clearInterval(S.pollTimer);
        toast(AR?'❌ انتهت الدفعة':'❌ Cancelled','error');
      }
    } catch(e) {}
  }, 5000);
}

/* ── SHOW RESULT ── */
function showResult(d, refId) {
  const box = document.getElementById('resultBox');
  box.className = 'result-box show';
  const rows = [
    [AR?'المرجع':'Reference', refId],
    [AR?'الحالة':'Status', d.status || '—'],
    [AR?'المبلغ المستلم':'Filled', d.filled_amount ? d.filled_amount + ' USD' : '—'],
    [AR?'الشبكة':'Network', S.chain + ' · ' + S.token],
    [AR?'Ledger':'Ledger', LEDGER_ADDR.substring(0,16)+'…'],
  ];
  box.innerHTML = '<div style="font-size:.78rem;font-weight:800;color:var(--green);margin-bottom:8px">✅ ' +
    (AR?'تمت العملية بنجاح':'Transaction Successful') + '</div>' +
    rows.map(([k,v])=>`<div class="res-row"><span class="res-k">${k}</span><span class="res-v">${v}</span></div>`).join('');
}

/* ── LEDGER BALANCE ── */
async function refreshBal() {
  document.getElementById('balIco').className = 'fas fa-spinner fa-spin';
  try {
    const r = await fetch(`https://apilist.tronscanapi.com/api/accountv2?address=${encodeURIComponent(LEDGER_ADDR)}`);
    const d = await r.json();
    let usdt = '0.00';
    (d.trc20token_balances||[]).forEach(t=>{
      if(t.tokenAbbr==='USDT'||t.tokenId==='TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t')
        usdt = (parseFloat(t.balance||0)/1e6).toFixed(2);
    });
    document.getElementById('topLedgerBal').textContent = usdt + ' USDT';
    document.getElementById('sideLedgerBal').textContent = usdt + ' USDT';
  } catch(e) {
    document.getElementById('topLedgerBal').textContent = '—';
    document.getElementById('sideLedgerBal').textContent = '—';
  }
  document.getElementById('balIco').className = 'fas fa-sync-alt';
}

/* ── COPY ADDRESS ── */
function copyAddr() {
  const addr = document.getElementById('depositAddr').textContent;
  navigator.clipboard?.writeText(addr).then(() => toast(AR?'تم النسخ ✓':'Copied ✓','success'));
}

/* ── RECENT TRANSACTIONS ── */
async function loadRecent() {
  try {
    const r = await fetch('api/wallet.php?action=recent_ledger&limit=6', {credentials:'include'});
    const d = await r.json();
    const c = document.getElementById('recentTxns');
    if (!d.transactions?.length) {
      c.innerHTML = `<div style="font-size:.65rem;color:var(--muted2);text-align:center;padding:8px">${AR?'لا توجد بعد':'None yet'}</div>`;
      return;
    }
    c.innerHTML = d.transactions.map(t => {
      const ok = ['completed','captured'].includes(t.status);
      const dc = ok?'var(--green)':t.status==='failed'?'var(--red)':'var(--gold)';
      return `<div class="txn-item">
        <div class="txn-dot" style="background:${dc}"></div>
        <div class="txn-ref">${(t.reference||'').substring(0,14)}</div>
        <div class="txn-amt">${parseFloat(t.amount||0).toFixed(2)}</div>
      </div>`;
    }).join('');
  } catch(_) {}
}

/* ── TOAST ── */
function toast(msg, type='info') {
  const t = document.getElementById('toast');
  const c = {success:'var(--green)',error:'var(--red)',info:'var(--payram)'};
  t.style.color = c[type]||c.info;
  t.style.borderColor = c[type]||c.info;
  t.textContent = msg;
  t.style.transform = 'translateX(-50%) translateY(0)';
  clearTimeout(t._t);
  t._t = setTimeout(()=>{t.style.transform='translateX(-50%) translateY(100px)';},4000);
}

/* ── INIT ── */
document.addEventListener('DOMContentLoaded', () => {
  refreshBal();
  loadRecent();
  setInterval(refreshBal, 30000);
});
</script>
</body>
</html>
