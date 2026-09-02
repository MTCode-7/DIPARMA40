<?php
/**
 * ============================================================
 * DI PARMA | Ledger POS Terminal
 * صفحة خاصة: DI PARMA API K + API S + Webhook × Ledger TRX
 * ─ 10 أنواع عمليات
 * ─ Manual Entry / Physical Card / NFC Tap
 * ─ POS Mode (قابل للتحميل على POS Terminal)
 * ============================================================
 */
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';

$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'en' ? 'en' : 'ar';
$ar   = ($lang === 'ar');
$dir  = $ar ? 'rtl' : 'ltr';
$csrf = generateCsrfToken();
$db   = db();

// ── مفاتيح API من الـ DB ─────────────────────────────────────
$apiKeyK = $apiKeyS = $webhookUrl = $ledgerAddr = '';
try {
    $apiRow = $db->query(
        "SELECT api_key, api_secret, webhook_url, meta FROM dp_api_clients
         WHERE status='active' ORDER BY id DESC LIMIT 1", []
    );
    if (!empty($apiRow[0])) {
        $apiKeyK    = $apiRow[0]['api_key']    ?? '';
        $apiKeyS    = $apiRow[0]['api_secret'] ?? '';
        $webhookUrl = $apiRow[0]['webhook_url'] ?? '';
        $meta = json_decode($apiRow[0]['meta'] ?? '{}', true);
        $ledgerAddr = $meta['ledger_address'] ?? 'TFyAQPrTRdP7zp46RPmE1iiCac1Lh6Bu58';
    }
} catch (Exception $e) { /* silently fallback */ }

if (!$ledgerAddr) $ledgerAddr = 'TFyAQPrTRdP7zp46RPmE1iiCac1Lh6Bu58';

// ── 10 أنواع العمليات ────────────────────────────────────────
$txnTypes = [
  'purchase'        => ['ar'=>'شراء',           'en'=>'Purchase',          'icon'=>'fa-credit-card', 'color'=>'#10B981','sub'=>'2D/3D'],
  'auth'            => ['ar'=>'تفويض',           'en'=>'Authorization',     'icon'=>'fa-shield-alt',  'color'=>'#3B82F6','sub'=>'Hold'],
  'auth_complete'   => ['ar'=>'إتمام تفويض',    'en'=>'Completion',        'icon'=>'fa-check-double','color'=>'#6366F1','sub'=>'Capture'],
  'purchase_advice' => ['ar'=>'إشعار شراء',     'en'=>'Purch. Advice',     'icon'=>'fa-bell',        'color'=>'#F59E0B','sub'=>'Offline'],
  'refund'          => ['ar'=>'استرداد',         'en'=>'Refund',            'icon'=>'fa-undo',        'color'=>'#EF4444','sub'=>'Return'],
  'reversal'        => ['ar'=>'إلغاء عملية',    'en'=>'Reversal',          'icon'=>'fa-reply',       'color'=>'#EC4899','sub'=>'Cancel'],
  'balance'         => ['ar'=>'استعلام رصيد',   'en'=>'Balance Inquiry',   'icon'=>'fa-wallet',      'color'=>'#8B5CF6','sub'=>'Inquiry'],
  'cash_advance'    => ['ar'=>'سلفة نقدية',     'en'=>'Cash Advance',      'icon'=>'fa-money-bill',  'color'=>'#14B8A6','sub'=>'Advance'],
  'void'            => ['ar'=>'إلغاء (نفس اليوم)','en'=>'Void',            'icon'=>'fa-ban',         'color'=>'#6B7280','sub'=>'Same Day'],
  'settlement'      => ['ar'=>'تسوية',           'en'=>'Settlement',        'icon'=>'fa-university',  'color'=>'#FFD700','sub'=>'EOD'],
];

// ── طرق الإدخال ──────────────────────────────────────────────
$inputModes = [
  'manual'   => ['ar'=>'إدخال يدوي',    'en'=>'Manual Entry',  'icon'=>'fa-keyboard',       'color'=>'#F97316'],
  'physical' => ['ar'=>'بطاقة فيزيائية','en'=>'Physical Card', 'icon'=>'fa-credit-card',    'color'=>'#3B82F6'],
  'nfc'      => ['ar'=>'NFC لاتلامسي',  'en'=>'NFC / Tap',     'icon'=>'fa-wifi',           'color'=>'#10B981'],
];
?><!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#030609">
<title>DI PARMA | Ledger POS</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
:root{
  --gold:#FFD700;--gold2:#FFB700;--bg:#030609;--card:#090f1e;--card2:#0b1224;
  --border:rgba(255,215,0,.12);--border2:rgba(255,215,0,.28);
  --text:#edf0f7;--muted:#4a5568;--muted2:#718096;
  --green:#10B981;--red:#EF4444;--orange:#F97316;--blue:#3B82F6;--purple:#8B5CF6;
  --radius:14px;--shadow:0 4px 24px rgba(0,0,0,.5);
}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;overflow-x:hidden}

/* ═══ TOP BAR ═══ */
.topbar{
  background:rgba(3,6,9,.97);border-bottom:1px solid var(--border);
  height:56px;display:flex;align-items:center;justify-content:space-between;
  padding:0 16px;position:sticky;top:0;z-index:200;
}
.tb-brand{color:var(--gold);font-weight:900;font-size:.95rem;display:flex;align-items:center;gap:8px}
.tb-ledger{font-size:.7rem;background:rgba(16,185,129,.12);color:var(--green);
  border:1px solid rgba(16,185,129,.3);border-radius:20px;padding:3px 10px;font-weight:700}
.tb-right{display:flex;align-items:center;gap:8px}
.tb-pos-btn{
  background:linear-gradient(135deg,var(--gold),var(--gold2));color:#000;border:none;
  padding:6px 14px;border-radius:20px;font-size:.72rem;font-weight:900;cursor:pointer;
  font-family:'Cairo',sans-serif;display:flex;align-items:center;gap:6px;
}

/* ═══ API STATUS BAR ═══ */
.api-bar{
  background:rgba(9,15,30,.9);border-bottom:1px solid var(--border);
  padding:6px 16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;font-size:.7rem;
}
.api-badge{display:flex;align-items:center;gap:5px;padding:3px 10px;border-radius:12px;font-weight:700}
.api-k{background:rgba(255,215,0,.1);color:var(--gold);border:1px solid rgba(255,215,0,.2)}
.api-s{background:rgba(59,130,246,.1);color:var(--blue);border:1px solid rgba(59,130,246,.2)}
.api-wh{background:rgba(139,92,246,.1);color:var(--purple);border:1px solid rgba(139,92,246,.2)}
.api-dot{width:6px;height:6px;border-radius:50%;background:currentColor;flex-shrink:0}
.ledger-addr{
  margin-left:auto;font-family:monospace;font-size:.65rem;color:var(--green);
  background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.2);
  padding:3px 10px;border-radius:10px;
}

/* ═══ MAIN LAYOUT ═══ */
.main{display:grid;grid-template-columns:1fr 340px;gap:0;min-height:calc(100vh - 100px)}
@media(max-width:900px){.main{grid-template-columns:1fr}.side-panel{display:none}}

/* ═══ LEFT: POS TERMINAL ═══ */
.pos-wrap{padding:20px 16px;border-right:1px solid var(--border)}

/* INPUT MODE TABS */
.mode-tabs{display:flex;gap:8px;margin-bottom:18px}
.mode-tab{
  flex:1;padding:10px 6px;border-radius:12px;border:1.5px solid var(--border);
  background:var(--card);cursor:pointer;text-align:center;transition:.2s;
  font-size:.72rem;font-weight:800;color:var(--muted2);
}
.mode-tab.active{border-color:var(--gold);background:rgba(255,215,0,.06);color:var(--gold)}
.mode-tab i{display:block;font-size:1.2rem;margin-bottom:4px}

/* NFC ANIMATION */
.nfc-scan{
  display:none;flex-direction:column;align-items:center;justify-content:center;
  padding:40px;border:2px dashed rgba(16,185,129,.3);border-radius:20px;
  background:rgba(16,185,129,.04);margin-bottom:18px;cursor:pointer;
}
.nfc-scan.show{display:flex}
.nfc-ring{
  width:80px;height:80px;border-radius:50%;border:3px solid var(--green);
  display:flex;align-items:center;justify-content:center;font-size:2rem;color:var(--green);
  animation:nfc-pulse 1.5s ease-in-out infinite;margin-bottom:12px;
}
@keyframes nfc-pulse{0%,100%{box-shadow:0 0 0 0 rgba(16,185,129,.4)}50%{box-shadow:0 0 0 20px rgba(16,185,129,0)}}
.nfc-ring.tapped{background:rgba(16,185,129,.15);animation:none;border-color:var(--green)}

/* TXN TYPE GRID */
.txn-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:7px;margin-bottom:18px}
.txn-btn{
  background:var(--card);border:1.5px solid var(--border);border-radius:11px;
  padding:9px 4px;cursor:pointer;text-align:center;transition:.2s;
}
.txn-btn:hover{border-color:rgba(255,215,0,.25)}
.txn-btn.active{border-color:var(--gold);background:rgba(255,215,0,.06)}
.txn-icon{font-size:.95rem;margin-bottom:3px}
.txn-name{font-size:.58rem;font-weight:800;color:var(--muted2);line-height:1.3}
.txn-btn.active .txn-name{color:var(--gold)}
.txn-sub{font-size:.52rem;color:var(--muted);margin-top:2px}

/* CARD INPUT AREA */
.card-area{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:16px;margin-bottom:16px}
.card-area-title{font-size:.7rem;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:1.2px;margin-bottom:12px;display:flex;align-items:center;gap:6px}
.inp-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px}
.inp-row.full{grid-template-columns:1fr}
.fld label{display:block;font-size:.68rem;color:var(--muted2);margin-bottom:4px;font-weight:700}
.fld input,.fld select{
  width:100%;background:rgba(255,255,255,.04);border:1.5px solid var(--border);
  border-radius:10px;padding:10px 12px;color:var(--text);font-family:'Cairo',sans-serif;
  font-size:.83rem;transition:.2s;-webkit-appearance:none;
}
.fld input:focus,.fld select:focus{outline:none;border-color:var(--gold);background:rgba(255,215,0,.03)}
.fld input::placeholder{color:var(--muted)}
.fld-chip{position:relative}
.fld-chip input{padding-right:42px}
.fld-chip .chip-icon{position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:.85rem}

/* 3D/2D toggle */
.sec-toggle{display:flex;gap:8px;margin-bottom:14px}
.sec-btn{
  flex:1;padding:8px;border-radius:10px;border:1.5px solid var(--border);
  background:rgba(255,255,255,.03);cursor:pointer;text-align:center;
  font-size:.73rem;font-weight:700;color:var(--muted2);transition:.2s;
}
.sec-btn.active{border-color:var(--gold);background:rgba(255,215,0,.06);color:var(--gold)}

/* AMOUNT DISPLAY */
.amount-display{
  background:var(--card2);border:1px solid var(--border);border-radius:var(--radius);
  padding:16px;margin-bottom:16px;display:flex;align-items:center;gap:14px;
}
.amount-big{font-size:2.2rem;font-weight:900;color:var(--gold);line-height:1;flex:1}
.amount-cur{
  background:rgba(255,215,0,.1);color:var(--gold);border:1px solid rgba(255,215,0,.2);
  border-radius:8px;padding:4px 12px;font-weight:800;font-size:.85rem;
}

/* ORIG REF */
.orig-ref-wrap{display:none;margin-bottom:14px}
.orig-ref-wrap.show{display:block}

/* PROCESS BTN */
.process-btn{
  width:100%;padding:16px;border-radius:14px;border:none;cursor:pointer;
  font-family:'Cairo',sans-serif;font-size:1rem;font-weight:900;
  background:linear-gradient(135deg,var(--gold),var(--gold2));color:#000;
  box-shadow:0 8px 24px rgba(255,215,0,.25);transition:.3s;
  display:flex;align-items:center;justify-content:center;gap:10px;
}
.process-btn:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 12px 32px rgba(255,215,0,.35)}
.process-btn:disabled{opacity:.4;cursor:not-allowed;transform:none}
.process-btn.loading{background:var(--card2);color:var(--muted2);border:1px solid var(--border);box-shadow:none}

/* ═══ RIGHT: SIDE PANEL ═══ */
.side-panel{background:var(--card2);border-left:1px solid var(--border);padding:16px;display:flex;flex-direction:column;gap:14px}
.panel-section{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:14px}
.panel-title{font-size:.7rem;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:1.2px;margin-bottom:10px;display:flex;align-items:center;gap:6px}

/* API KEYS PANEL */
.key-row{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px;padding:8px 10px;background:rgba(255,255,255,.03);border-radius:8px}
.key-label{font-size:.65rem;font-weight:800;color:var(--muted2)}
.key-val{font-family:monospace;font-size:.65rem;color:var(--gold);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin:0 8px}
.key-copy{background:none;border:none;color:var(--muted);cursor:pointer;font-size:.75rem;padding:2px 4px}
.key-copy:hover{color:var(--gold)}

/* LEDGER PANEL */
.ledger-balance{text-align:center;padding:10px 0}
.ledger-bal-num{font-size:1.6rem;font-weight:900;color:var(--green)}
.ledger-bal-label{font-size:.68rem;color:var(--muted2);margin-top:2px}
.ledger-address-box{
  font-family:monospace;font-size:.62rem;word-break:break-all;color:var(--muted2);
  background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:8px;
  padding:8px 10px;margin-top:8px;
}

/* WEBHOOK PANEL */
.wh-url{font-size:.65rem;color:var(--purple);word-break:break-all;font-family:monospace;background:rgba(139,92,246,.06);border:1px solid rgba(139,92,246,.15);padding:7px 10px;border-radius:8px;margin-bottom:8px}
.wh-log{font-size:.65rem;color:var(--muted2);background:rgba(255,255,255,.02);border-radius:8px;padding:8px 10px;max-height:100px;overflow-y:auto;font-family:monospace}
.wh-log-entry{padding:2px 0;border-bottom:1px solid rgba(255,255,255,.04);line-height:1.5}
.wh-log-entry.ok{color:var(--green)}
.wh-log-entry.err{color:var(--red)}

/* RECENT TXN */
.txn-list{max-height:180px;overflow-y:auto}
.txn-item{display:flex;align-items:center;gap:8px;padding:7px 0;border-bottom:1px solid rgba(255,255,255,.04)}
.txn-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.txn-ref{font-size:.65rem;font-family:monospace;color:var(--muted2);flex:1}
.txn-amt{font-size:.7rem;font-weight:800;color:var(--gold)}
.txn-status{font-size:.58rem;padding:2px 7px;border-radius:6px;font-weight:700}
.st-success{background:rgba(16,185,129,.12);color:var(--green)}
.st-pending{background:rgba(255,215,0,.1);color:var(--gold)}
.st-failed{background:rgba(239,68,68,.1);color:var(--red)}

/* ═══ RESULT OVERLAY ═══ */
.result-overlay{
  display:none;position:fixed;inset:0;z-index:300;
  background:rgba(3,6,9,.92);backdrop-filter:blur(8px);
  align-items:center;justify-content:center;padding:20px;
}
.result-overlay.show{display:flex}
.result-box{
  background:var(--card);border:2px solid var(--border);border-radius:20px;
  padding:28px;width:100%;max-width:440px;text-align:center;
}
.result-icon{font-size:3rem;margin-bottom:14px}
.result-title{font-size:1.3rem;font-weight:900;margin-bottom:8px}
.result-ref{font-family:monospace;font-size:.78rem;color:var(--muted2);background:rgba(255,255,255,.04);padding:8px 14px;border-radius:8px;margin:10px 0;word-break:break-all}
.result-details{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:14px 0;text-align:left}
.result-det-row{font-size:.74rem}
.result-det-label{color:var(--muted2)}
.result-det-val{font-weight:800;color:var(--text)}
.result-close{
  width:100%;padding:12px;border-radius:12px;border:none;cursor:pointer;
  font-family:'Cairo',sans-serif;font-size:.9rem;font-weight:800;
  background:rgba(255,255,255,.07);color:var(--text);margin-top:6px;
}

/* ═══ RECEIPT BTN ═══ */
.receipt-btn{
  width:100%;padding:10px;border-radius:10px;border:1px solid var(--border2);
  background:rgba(255,215,0,.06);color:var(--gold);font-family:'Cairo',sans-serif;
  font-size:.82rem;font-weight:800;cursor:pointer;margin-top:8px;
  display:flex;align-items:center;justify-content:center;gap:8px;
}

/* ═══ TOAST ═══ */
#toast{
  position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(100px);
  background:var(--card);border:1px solid var(--border2);border-radius:14px;
  padding:10px 24px;font-size:.82rem;font-weight:700;z-index:9999;transition:.35s;color:var(--text);
}

/* ═══ POS MODE (Fullscreen) ═══ */
body.pos-mode .topbar{height:44px;padding:0 10px}
body.pos-mode .api-bar{display:none}
body.pos-mode .side-panel{display:none}
body.pos-mode .main{grid-template-columns:1fr}
body.pos-mode .pos-wrap{padding:10px}
body.pos-mode .amount-big{font-size:1.8rem}

/* ═══ PRINT RECEIPT ═══ */
@media print{
  body *{visibility:hidden}
  #receipt-print,#receipt-print *{visibility:visible}
  #receipt-print{position:absolute;top:0;left:0;width:80mm;font-family:monospace;font-size:11px;color:#000}
}

/* SCROLLBAR */
::-webkit-scrollbar{width:4px;height:4px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(255,215,0,.15);border-radius:4px}
</style>
</head>
<body>

<!-- ═══ TOP BAR ═══ -->
<header class="topbar">
  <div class="tb-brand">
    <i class="fas fa-coins" style="color:var(--gold)"></i>
    DI PARMA
    <span class="tb-ledger"><i class="fas fa-wallet"></i> Ledger TRX</span>
  </div>
  <div class="tb-right">
    <button class="tb-pos-btn" onclick="togglePosMode()">
      <i class="fas fa-tablet-alt"></i>
      <span id="pos-btn-label"><?= $ar ? 'وضع POS' : 'POS Mode' ?></span>
    </button>
    <a href="dashboard.php" style="color:var(--muted2);font-size:.78rem;text-decoration:none;padding:4px 10px">
      <i class="fas fa-th-large"></i>
    </a>
  </div>
</header>

<!-- ═══ API STATUS BAR ═══ -->
<div class="api-bar">
  <div class="api-badge api-k">
    <span class="api-dot"></span>
    API K: <span style="margin-left:4px;font-family:monospace" id="disp-api-k">
      <?= $apiKeyK ? substr($apiKeyK, 0, 8) . '••••••••' : '—' ?>
    </span>
  </div>
  <div class="api-badge api-s">
    <span class="api-dot"></span>
    API S: <span style="margin-left:4px;font-family:monospace" id="disp-api-s">
      <?= $apiKeyS ? substr($apiKeyS, 0, 8) . '••••••••' : '—' ?>
    </span>
  </div>
  <div class="api-badge api-wh">
    <span class="api-dot"></span>
    Webhook: <span style="margin-left:4px"><?= $webhookUrl ? '✓ Active' : '— Not set' ?></span>
  </div>
  <div class="ledger-addr">
    <i class="fas fa-wallet"></i>
    <?= htmlspecialchars(substr($ledgerAddr, 0, 12)) ?>...<?= substr($ledgerAddr, -6) ?>
  </div>
</div>

<!-- ═══ MAIN ═══ -->
<div class="main">

  <!-- ═══ LEFT: POS TERMINAL ═══ -->
  <div class="pos-wrap">

    <!-- INPUT MODE TABS -->
    <div class="mode-tabs">
      <?php foreach ($inputModes as $mcode => $mode): ?>
      <div class="mode-tab <?= $mcode === 'manual' ? 'active' : '' ?>"
           onclick="selectMode('<?= $mcode ?>',this)"
           id="mode-<?= $mcode ?>">
        <i class="fas <?= $mode['icon'] ?>" style="color:<?= $mode['color'] ?>"></i>
        <?= $ar ? $mode['ar'] : $mode['en'] ?>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- NFC TAP AREA -->
    <div class="nfc-scan" id="nfc-area">
      <div class="nfc-ring" id="nfc-ring"><i class="fas fa-wifi"></i></div>
      <div style="font-size:.85rem;font-weight:800;color:var(--green)"><?= $ar ? 'اضغط هنا أو قرّب البطاقة' : 'Tap here or place card' ?></div>
      <div style="font-size:.7rem;color:var(--muted2);margin-top:4px"><?= $ar ? 'NFC / Contactless' : 'NFC / Contactless' ?></div>
    </div>

    <!-- TXN TYPE GRID -->
    <div style="font-size:.68rem;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:1.2px;margin-bottom:8px">
      <i class="fas fa-list"></i> <?= $ar ? 'نوع العملية' : 'Transaction Type' ?>
    </div>
    <div class="txn-grid" id="txn-type-grid">
      <?php foreach ($txnTypes as $tcode => $t): ?>
      <div class="txn-btn <?= $tcode === 'purchase' ? 'active' : '' ?>"
           onclick="selectTxnType('<?= $tcode ?>',this)"
           id="ttype-<?= $tcode ?>">
        <div class="txn-icon"><i class="fas <?= $t['icon'] ?>" style="color:<?= $t['color'] ?>"></i></div>
        <div class="txn-name"><?= $ar ? $t['ar'] : $t['en'] ?></div>
        <div class="txn-sub"><?= $t['sub'] ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- ORIG REF (يظهر لبعض الأنواع) -->
    <div class="orig-ref-wrap" id="orig-ref-wrap">
      <div class="fld" style="margin-bottom:12px">
        <label><i class="fas fa-hashtag"></i> <?= $ar ? 'رقم المرجع الأصلي (RRN)' : 'Original Reference (RRN)' ?></label>
        <input type="text" id="orig-ref" placeholder="<?= $ar ? 'رقم العملية السابقة' : 'Previous transaction reference' ?>">
      </div>
      <div class="fld" id="approval-code-wrap" style="display:none;margin-bottom:12px">
        <label><i class="fas fa-check-circle"></i> <?= $ar ? 'رمز الموافقة' : 'Approval Code' ?></label>
        <input type="text" id="approval-code" placeholder="<?= $ar ? 'أدخل رمز الموافقة' : 'Enter approval code' ?>">
      </div>
    </div>

    <!-- 3D/2D TOGGLE -->
    <div class="sec-toggle" id="sec-toggle-wrap">
      <div class="sec-btn active" id="sbtn-3D" onclick="selectSecMode('3D',this)">
        <i class="fas fa-shield-alt"></i> 3D Secure
      </div>
      <div class="sec-btn" id="sbtn-2D" onclick="selectSecMode('2D',this)">
        <i class="fas fa-credit-card"></i> 2D / MOTO
      </div>
    </div>

    <!-- CARD INPUT -->
    <div class="card-area" id="card-area">
      <div class="card-area-title">
        <i class="fas fa-credit-card" style="color:var(--gold)"></i>
        <?= $ar ? 'بيانات البطاقة' : 'Card Details' ?>
        <span id="card-mode-badge" style="margin-left:auto;font-size:.6rem;background:rgba(249,115,22,.1);color:var(--orange);border:1px solid rgba(249,115,22,.2);padding:2px 8px;border-radius:6px">Manual</span>
      </div>
      <div class="inp-row full">
        <div class="fld fld-chip">
          <label><?= $ar ? 'رقم البطاقة' : 'Card Number' ?></label>
          <input type="tel" id="card-num" maxlength="19" placeholder="•••• •••• •••• ••••"
                 oninput="fmtCard(this)" autocomplete="cc-number">
          <span class="chip-icon" id="card-brand-icon"><i class="fas fa-credit-card"></i></span>
        </div>
      </div>
      <div class="inp-row">
        <div class="fld">
          <label><?= $ar ? 'تاريخ الانتهاء' : 'Expiry' ?></label>
          <input type="tel" id="card-exp" maxlength="5" placeholder="MM/YY"
                 oninput="fmtExpiry(this)" autocomplete="cc-exp">
        </div>
        <div class="fld">
          <label>CVV</label>
          <input type="tel" id="card-cvv" maxlength="4" placeholder="•••"
                 autocomplete="cc-csc">
        </div>
      </div>
      <div class="inp-row full">
        <div class="fld">
          <label><?= $ar ? 'اسم حامل البطاقة' : 'Cardholder Name' ?></label>
          <input type="text" id="card-name" placeholder="<?= $ar ? 'الاسم الكامل' : 'Full Name' ?>"
                 autocomplete="cc-name">
        </div>
      </div>
    </div>

    <!-- AMOUNT DISPLAY -->
    <div class="amount-display">
      <div>
        <div style="font-size:.65rem;color:var(--muted2);margin-bottom:4px"><?= $ar ? 'المبلغ' : 'Amount' ?></div>
        <div class="amount-big" id="amount-display">0.00</div>
      </div>
      <div style="display:flex;flex-direction:column;gap:8px">
        <select class="amount-cur" id="txn-currency" onchange="updateAmountDisplay()">
          <option value="USD">USD</option>
          <option value="AED">AED</option>
          <option value="SAR">SAR</option>
          <option value="EUR">EUR</option>
          <option value="GBP">GBP</option>
          <option value="KWD">KWD</option>
          <option value="EGP">EGP</option>
          <option value="QAR">QAR</option>
          <option value="USDT">USDT</option>
          <option value="TRX">TRX</option>
        </select>
        <input type="number" id="txn-amount" min="0.01" step="0.01" placeholder="0.00"
               style="width:100%;background:rgba(255,255,255,.04);border:1.5px solid var(--border);border-radius:8px;padding:6px 10px;color:var(--gold);font-family:'Cairo',sans-serif;font-size:.9rem;font-weight:800;text-align:center"
               oninput="updateAmountDisplay()">
      </div>
    </div>

    <!-- NOTES -->
    <div class="fld" style="margin-bottom:14px">
      <label><i class="fas fa-sticky-note"></i> <?= $ar ? 'ملاحظات (اختياري)' : 'Notes (optional)' ?></label>
      <input type="text" id="txn-notes" placeholder="<?= $ar ? 'رقم الفاتورة، العميل...' : 'Invoice #, client...' ?>">
    </div>

    <!-- PROCESS BUTTON -->
    <button class="process-btn" id="process-btn" onclick="processTransaction()">
      <i class="fas fa-lock" id="proc-icon"></i>
      <span id="proc-label"><?= $ar ? 'تنفيذ العملية' : 'Process Transaction' ?></span>
    </button>

  </div><!-- /pos-wrap -->

  <!-- ═══ RIGHT: SIDE PANEL ═══ -->
  <div class="side-panel">

    <!-- API KEYS -->
    <div class="panel-section">
      <div class="panel-title"><i class="fas fa-key" style="color:var(--gold)"></i> <?= $ar ? 'مفاتيح API' : 'API Keys' ?></div>
      <div class="key-row">
        <span class="key-label">API K</span>
        <span class="key-val" id="key-k-val"><?= htmlspecialchars($apiKeyK ?: '—') ?></span>
        <button class="key-copy" onclick="copyText('<?= htmlspecialchars($apiKeyK) ?>')" title="Copy"><i class="fas fa-copy"></i></button>
      </div>
      <div class="key-row">
        <span class="key-label">API S</span>
        <span class="key-val" id="key-s-val"><?= htmlspecialchars($apiKeyS ?: '—') ?></span>
        <button class="key-copy" onclick="copyText('<?= htmlspecialchars($apiKeyS) ?>')" title="Copy"><i class="fas fa-copy"></i></button>
      </div>
      <div class="key-row" style="margin-bottom:0">
        <span class="key-label">Webhook</span>
        <span class="key-val" id="key-wh-val"><?= htmlspecialchars($webhookUrl ?: '—') ?></span>
        <button class="key-copy" onclick="copyText('<?= htmlspecialchars($webhookUrl) ?>')" title="Copy"><i class="fas fa-copy"></i></button>
      </div>
    </div>

    <!-- LEDGER -->
    <div class="panel-section">
      <div class="panel-title"><i class="fas fa-wallet" style="color:var(--green)"></i> Ledger TRX</div>
      <div class="ledger-balance">
        <div class="ledger-bal-num" id="ledger-bal">—</div>
        <div class="ledger-bal-label">USDT (TRC20)</div>
      </div>
      <div class="ledger-address-box" id="ledger-addr-box">
        <?= htmlspecialchars($ledgerAddr) ?>
      </div>
      <div style="display:flex;gap:8px;margin-top:8px">
        <button onclick="copyText('<?= htmlspecialchars($ledgerAddr) ?>')"
          style="flex:1;padding:6px;border-radius:8px;border:1px solid var(--border);background:rgba(255,255,255,.04);color:var(--muted2);font-size:.68rem;cursor:pointer;font-family:'Cairo',sans-serif">
          <i class="fas fa-copy"></i> <?= $ar ? 'نسخ' : 'Copy' ?>
        </button>
        <button onclick="refreshLedgerBalance()"
          style="flex:1;padding:6px;border-radius:8px;border:1px solid rgba(16,185,129,.2);background:rgba(16,185,129,.06);color:var(--green);font-size:.68rem;cursor:pointer;font-family:'Cairo',sans-serif">
          <i class="fas fa-sync-alt" id="bal-refresh-icon"></i> <?= $ar ? 'تحديث' : 'Refresh' ?>
        </button>
      </div>
    </div>

    <!-- WEBHOOK LOG -->
    <div class="panel-section">
      <div class="panel-title"><i class="fas fa-bolt" style="color:var(--purple)"></i> Webhook</div>
      <?php if ($webhookUrl): ?>
      <div class="wh-url"><?= htmlspecialchars($webhookUrl) ?></div>
      <?php else: ?>
      <div style="font-size:.7rem;color:var(--muted2);margin-bottom:8px"><?= $ar ? 'لم يُضبط بعد' : 'Not configured' ?></div>
      <?php endif; ?>
      <div class="wh-log" id="wh-log">
        <div style="color:var(--muted);font-size:.62rem"><?= $ar ? 'سيظهر هنا سجل الـ Webhook...' : 'Webhook events will appear here...' ?></div>
      </div>
    </div>

    <!-- RECENT TRANSACTIONS -->
    <div class="panel-section">
      <div class="panel-title"><i class="fas fa-history" style="color:var(--blue)"></i> <?= $ar ? 'آخر العمليات' : 'Recent Transactions' ?></div>
      <div class="txn-list" id="recent-txns">
        <div style="font-size:.68rem;color:var(--muted2);text-align:center;padding:10px">
          <?= $ar ? 'جاري التحميل...' : 'Loading...' ?>
        </div>
      </div>
    </div>

  </div><!-- /side-panel -->

</div><!-- /main -->

<!-- ═══ RESULT OVERLAY ═══ -->
<div class="result-overlay" id="result-overlay">
  <div class="result-box">
    <div class="result-icon" id="res-icon">✅</div>
    <div class="result-title" id="res-title">—</div>
    <div class="result-ref" id="res-ref">—</div>
    <div class="result-details" id="res-details"></div>
    <button class="receipt-btn" onclick="printReceipt()">
      <i class="fas fa-print"></i> <?= $ar ? 'طباعة الإيصال' : 'Print Receipt' ?>
    </button>
    <button class="result-close" onclick="closeResult()">
      <?= $ar ? 'إغلاق وعملية جديدة' : 'Close & New Transaction' ?>
    </button>
  </div>
</div>

<!-- RECEIPT PRINT AREA -->
<div id="receipt-print" style="display:none"></div>

<div id="toast"></div>

<!-- CSRF hidden -->
<input type="hidden" id="csrf-token" value="<?= htmlspecialchars($csrf) ?>">
<input type="hidden" id="ledger-address" value="<?= htmlspecialchars($ledgerAddr) ?>">
<input type="hidden" id="api-key-k" value="<?= htmlspecialchars($apiKeyK) ?>">
<input type="hidden" id="api-key-s" value="<?= htmlspecialchars($apiKeyS) ?>">
<input type="hidden" id="webhook-url" value="<?= htmlspecialchars($webhookUrl) ?>">

<script>
const AR = <?= $ar ? 'true' : 'false' ?>;

/* ═══ STATE ═══ */
const STATE = {
  inputMode : 'manual',
  txnType   : 'purchase',
  secMode   : '3D',
  nfcTapped : false,
  processing: false,
  lastResult: null,
};

const NEED_ORIG = ['auth_complete','refund','reversal','void'];
const HIDE_CARD = ['balance','settlement'];

/* ═══ INPUT MODE ═══ */
function selectMode(code, el) {
  STATE.inputMode = code;
  document.querySelectorAll('.mode-tab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');

  const nfcArea  = document.getElementById('nfc-area');
  const cardArea = document.getElementById('card-area');
  const badge    = document.getElementById('card-mode-badge');

  nfcArea.classList.toggle('show', code === 'nfc');

  if (code === 'nfc') {
    cardArea.style.display = 'none';
    STATE.nfcTapped = false;
    document.getElementById('nfc-ring').classList.remove('tapped');
  } else {
    cardArea.style.display = '';
  }

  const labels = {manual:'Manual',physical:'Physical',nfc:'NFC'};
  const colors = {
    manual : {bg:'rgba(249,115,22,.1)',color:'var(--orange)',border:'rgba(249,115,22,.2)'},
    physical:{bg:'rgba(59,130,246,.1)',color:'var(--blue)',border:'rgba(59,130,246,.2)'},
    nfc    : {bg:'rgba(16,185,129,.1)',color:'var(--green)',border:'rgba(16,185,129,.2)'},
  };
  if (badge) {
    badge.textContent = labels[code] || code;
    badge.style.background   = colors[code]?.bg;
    badge.style.color        = colors[code]?.color;
    badge.style.borderColor  = colors[code]?.border;
  }
}

/* ═══ TXN TYPE ═══ */
function selectTxnType(code, el) {
  STATE.txnType = code;
  document.querySelectorAll('.txn-btn').forEach(b => b.classList.remove('active'));
  el.classList.add('active');

  const origWrap = document.getElementById('orig-ref-wrap');
  const secWrap  = document.getElementById('sec-toggle-wrap');
  const cardArea = document.getElementById('card-area');

  origWrap.classList.toggle('show', NEED_ORIG.includes(code));
  secWrap.style.display  = code === 'purchase' ? '' : 'none';
  cardArea.style.display = HIDE_CARD.includes(code) || STATE.inputMode === 'nfc' ? 'none' : '';
}

/* ═══ SEC MODE ═══ */
function selectSecMode(mode, el) {
  STATE.secMode = mode;
  ['3D','2D'].forEach(m => {
    const b = document.getElementById('sbtn-'+m);
    if (!b) return;
    b.classList.toggle('active', m === mode);
  });
}

/* ═══ AMOUNT ═══ */
function updateAmountDisplay() {
  const v = parseFloat(document.getElementById('txn-amount').value) || 0;
  document.getElementById('amount-display').textContent = v.toFixed(2);
}

/* ═══ CARD FORMAT ═══ */
function fmtCard(inp) {
  let v = inp.value.replace(/\D/g,'').substring(0,16);
  inp.value = v.replace(/(.{4})/g,'$1 ').trim();
  // Brand icon
  const icon = document.getElementById('card-brand-icon');
  if (!icon) return;
  if (/^4/.test(v))       icon.innerHTML = '<i class="fab fa-cc-visa" style="color:#1a1f71;font-size:1.1rem"></i>';
  else if (/^5[1-5]/.test(v)) icon.innerHTML = '<i class="fab fa-cc-mastercard" style="color:#eb001b;font-size:1.1rem"></i>';
  else if (/^3[47]/.test(v))  icon.innerHTML = '<i class="fab fa-cc-amex" style="color:#007bc1;font-size:1.1rem"></i>';
  else icon.innerHTML = '<i class="fas fa-credit-card"></i>';
}

function fmtExpiry(inp) {
  let v = inp.value.replace(/\D/g,'').substring(0,4);
  if (v.length >= 3) v = v.substring(0,2) + '/' + v.substring(2);
  inp.value = v;
}

/* ═══ PROCESS TRANSACTION ═══ */
async function processTransaction() {
  if (STATE.processing) return;

  const amount = parseFloat(document.getElementById('txn-amount').value) || 0;
  if (amount <= 0) { toast(AR?'أدخل المبلغ':'Enter amount','error'); return; }

  // NFC mode: must tap first
  if (STATE.inputMode === 'nfc' && !STATE.nfcTapped && !HIDE_CARD.includes(STATE.txnType)) {
    toast(AR?'قرّب البطاقة أولاً':'Please tap the card first','error'); return;
  }

  // Card validation (unless balance/settlement/nfc-not-tapped)
  if (!HIDE_CARD.includes(STATE.txnType) && STATE.inputMode !== 'nfc') {
    const cn = document.getElementById('card-num').value.replace(/\s/g,'');
    const ce = document.getElementById('card-exp').value;
    const cv = document.getElementById('card-cvv').value;
    if (cn.length < 13) { toast(AR?'رقم البطاقة غير صحيح':'Invalid card number','error'); return; }
    if (!/^\d{2}\/\d{2}$/.test(ce)) { toast(AR?'تاريخ الانتهاء غير صحيح':'Invalid expiry','error'); return; }
    if (cv.length < 3)  { toast(AR?'CVV غير صحيح':'Invalid CVV','error'); return; }
  }

  STATE.processing = true;
  const btn  = document.getElementById('process-btn');
  const icon = document.getElementById('proc-icon');
  const lbl  = document.getElementById('proc-label');
  btn.classList.add('loading');
  btn.disabled = true;
  icon.className = 'fas fa-spinner fa-spin';
  lbl.textContent = AR ? 'جاري المعالجة...' : 'Processing...';

  const reference = 'LDG' + Date.now().toString(36).toUpperCase();
  const csrf = document.getElementById('csrf-token').value;
  const apiK = document.getElementById('api-key-k').value;
  const apiS = document.getElementById('api-key-s').value;
  const ledger = document.getElementById('ledger-address').value;

  const payload = {
    csrf_token  : csrf,
    api_key     : apiK,
    api_secret  : apiS,
    reference   : reference,
    ledger_addr : ledger,
    txn_type    : STATE.txnType,
    input_mode  : STATE.inputMode,
    sec_mode    : STATE.secMode,
    amount      : amount,
    currency    : document.getElementById('txn-currency').value,
    notes       : document.getElementById('txn-notes').value,
    orig_ref    : document.getElementById('orig-ref')?.value || '',
    card_num    : document.getElementById('card-num')?.value.replace(/\s/g,'') || '',
    card_exp    : document.getElementById('card-exp')?.value || '',
    card_name   : document.getElementById('card-name')?.value || '',
    destination : 'ledger_trx',
    gateway     : 'diparma_ledger',
    nfc_tapped  : STATE.nfcTapped,
  };

  try {
    const resp = await fetch('api/pos_ledger_transfer.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify(payload),
    });
    const data = await resp.json();
    STATE.processing = false;
    STATE.lastResult = { ...data, reference, payload };

    resetProcessBtn();
    showResult(data, reference, payload);
    loadRecentTxns();
    if (data.success) {
      logWebhookEvent('POST', reference, 'success');
      toast(AR?'تمت العملية بنجاح ✓':'Transaction successful ✓','success');
    } else {
      logWebhookEvent('POST', reference, 'error');
    }
  } catch (err) {
    STATE.processing = false;
    resetProcessBtn();
    toast(AR?'خطأ في الاتصال':'Connection error','error');
    logWebhookEvent('ERR', reference, 'error');
  }
}

function resetProcessBtn() {
  const btn  = document.getElementById('process-btn');
  const icon = document.getElementById('proc-icon');
  const lbl  = document.getElementById('proc-label');
  btn.classList.remove('loading');
  btn.disabled = false;
  icon.className = 'fas fa-lock';
  lbl.textContent = AR ? 'تنفيذ العملية' : 'Process Transaction';
}

/* ═══ SHOW RESULT ═══ */
function showResult(data, reference, payload) {
  const overlay = document.getElementById('result-overlay');
  const ok      = data.success;

  document.getElementById('res-icon').textContent = ok ? '✅' : '❌';
  document.getElementById('res-title').textContent =
    ok ? (AR?'تمت العملية بنجاح':'Transaction Successful')
       : (AR?'فشلت العملية':'Transaction Failed');
  document.getElementById('res-title').style.color = ok ? 'var(--green)' : 'var(--red)';
  document.getElementById('res-ref').textContent = reference;

  const txnLabels = {
    purchase:'Purchase',auth:'Authorization',auth_complete:'Completion',
    purchase_advice:'Purchase Advice',refund:'Refund',reversal:'Reversal',
    balance:'Balance Inquiry',cash_advance:'Cash Advance',void:'Void',settlement:'Settlement'
  };

  const details = [
    [AR?'المبلغ':'Amount',           payload.amount + ' ' + payload.currency],
    [AR?'نوع العملية':'Type',         txnLabels[payload.txn_type] || payload.txn_type],
    [AR?'طريقة الإدخال':'Input Mode', payload.input_mode?.toUpperCase()],
    [AR?'وضع الأمان':'Security',      payload.sec_mode || '—'],
    [AR?'وجهة':'Destination',        'Ledger TRX (USDT)'],
    [AR?'الرسالة':'Message',          data.message || '—'],
  ];

  let html = '';
  details.forEach(([label, val]) => {
    html += `<div class="result-det-row">
      <div class="result-det-label">${label}</div>
      <div class="result-det-val">${val}</div>
    </div>`;
  });
  document.getElementById('res-details').innerHTML = html;
  overlay.classList.add('show');
}

function closeResult() {
  document.getElementById('result-overlay').classList.remove('show');
  // Reset card fields
  ['card-num','card-exp','card-cvv','card-name','txn-amount','txn-notes','orig-ref'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  document.getElementById('amount-display').textContent = '0.00';
  STATE.nfcTapped = false;
  document.getElementById('nfc-ring')?.classList.remove('tapped');
}

/* ═══ PRINT RECEIPT ═══ */
function printReceipt() {
  if (!STATE.lastResult) return;
  const r = STATE.lastResult;
  const now = new Date().toLocaleString(AR?'ar-AE':'en-US');
  document.getElementById('receipt-print').innerHTML = `
<div style="font-family:monospace;font-size:11px;width:80mm;color:#000">
  <div style="text-align:center;font-size:14px;font-weight:bold;margin-bottom:4px">DI PARMA</div>
  <div style="text-align:center;font-size:10px;margin-bottom:8px">Ledger POS Terminal</div>
  <div>--------------------------------</div>
  <div>Ref : ${r.reference}</div>
  <div>Date: ${now}</div>
  <div>Type: ${r.payload?.txn_type?.toUpperCase()}</div>
  <div>Mode: ${r.payload?.input_mode?.toUpperCase()}</div>
  <div>Amt : ${r.payload?.amount} ${r.payload?.currency}</div>
  <div>Dest: Ledger TRX (USDT)</div>
  <div>Status: ${r.success ? 'APPROVED' : 'DECLINED'}</div>
  <div>Msg : ${r.message || '—'}</div>
  <div>--------------------------------</div>
  <div style="text-align:center;margin-top:4px">DI PARMA Gateway © 2026</div>
</div>`;
  document.getElementById('receipt-print').style.display = '';
  window.print();
  document.getElementById('receipt-print').style.display = 'none';
}

/* ═══ LEDGER BALANCE ═══ */
async function refreshLedgerBalance() {
  const icon = document.getElementById('bal-refresh-icon');
  const addr = document.getElementById('ledger-address').value;
  icon.className = 'fas fa-spinner fa-spin';
  try {
    const resp = await fetch(
      `https://apilist.tronscanapi.com/api/accountv2?address=${encodeURIComponent(addr)}`
    );
    const data = await resp.json();
    let usdt = 0;
    const tokens = data.trc20token_balances || data.tokens || [];
    tokens.forEach(t => {
      if (t.tokenAbbr === 'USDT' || t.tokenId === 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t') {
        usdt = (parseFloat(t.balance || t.amount || 0) / 1e6).toFixed(2);
      }
    });
    document.getElementById('ledger-bal').textContent = usdt + ' USDT';
  } catch (e) {
    document.getElementById('ledger-bal').textContent = '— (error)';
  }
  icon.className = 'fas fa-sync-alt';
}

/* ═══ RECENT TRANSACTIONS ═══ */
async function loadRecentTxns() {
  try {
    const resp = await fetch('api/wallet.php?action=recent_ledger&limit=8');
    const data = await resp.json();
    const container = document.getElementById('recent-txns');
    if (!data.transactions?.length) {
      container.innerHTML = `<div style="font-size:.68rem;color:var(--muted2);text-align:center;padding:10px">${AR?'لا توجد عمليات بعد':'No transactions yet'}</div>`;
      return;
    }
    container.innerHTML = data.transactions.map(t => {
      const stClass = t.status === 'completed' || t.status === 'captured' ? 'st-success'
                    : t.status === 'failed' ? 'st-failed' : 'st-pending';
      const dotClr  = t.status === 'completed' || t.status === 'captured' ? 'var(--green)'
                    : t.status === 'failed' ? 'var(--red)' : 'var(--gold)';
      return `<div class="txn-item">
        <div class="txn-dot" style="background:${dotClr}"></div>
        <div class="txn-ref">${(t.reference||'').substring(0,14)}</div>
        <div class="txn-amt">${parseFloat(t.amount||0).toFixed(2)} ${t.currency||''}</div>
        <div class="txn-status ${stClass}">${t.status||'—'}</div>
      </div>`;
    }).join('');
  } catch (e) {
    document.getElementById('recent-txns').innerHTML =
      `<div style="font-size:.68rem;color:var(--muted2);text-align:center;padding:10px">${AR?'خطأ في التحميل':'Load error'}</div>`;
  }
}

/* ═══ WEBHOOK LOG ═══ */
function logWebhookEvent(method, ref, status) {
  const log   = document.getElementById('wh-log');
  const now   = new Date().toLocaleTimeString();
  const cls   = status === 'success' ? 'ok' : 'err';
  const entry = document.createElement('div');
  entry.className = 'wh-log-entry ' + cls;
  entry.textContent = `[${now}] ${method} ${ref.substring(0,12)}… → ${status}`;
  if (log.querySelector('[style]')) log.innerHTML = '';
  log.prepend(entry);
}

/* ═══ POS MODE ═══ */
let posModeActive = false;
function togglePosMode() {
  posModeActive = !posModeActive;
  document.body.classList.toggle('pos-mode', posModeActive);
  document.getElementById('pos-btn-label').textContent =
    posModeActive ? (AR?'خروج POS':'Exit POS') : (AR?'وضع POS':'POS Mode');
  if (posModeActive && document.documentElement.requestFullscreen) {
    document.documentElement.requestFullscreen().catch(()=>{});
  } else if (!posModeActive && document.fullscreenElement) {
    document.exitFullscreen().catch(()=>{});
  }
}

/* ═══ COPY ═══ */
function copyText(text) {
  if (!text || text === '—') return;
  navigator.clipboard.writeText(text).then(() => toast(AR?'تم النسخ ✓':'Copied ✓','success'));
}

/* ═══ TOAST ═══ */
function toast(msg, type='info') {
  const t = document.getElementById('toast');
  const c = {success:'var(--green)',error:'var(--red)',info:'var(--gold)'};
  t.style.borderColor = c[type]||c.info;
  t.style.color = c[type]||c.info;
  t.textContent = msg;
  t.style.transform = 'translateX(-50%) translateY(0)';
  clearTimeout(t._t);
  t._t = setTimeout(() => { t.style.transform = 'translateX(-50%) translateY(100px)'; }, 4000);
}

/* ═══ INIT ═══ */
document.addEventListener('DOMContentLoaded', () => {
  loadRecentTxns();
  refreshLedgerBalance();
  // كل 30 ثانية نحدث الرصيد
  setInterval(refreshLedgerBalance, 30000);
});
</script>
</body>
</html>
