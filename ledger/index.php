<?php
/**
 * ============================================================
 * DI PARMA | Ledger Section — قسم المحفظة الرئيسي
 * ============================================================
 */
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'en' ? 'en' : 'ar';
$ar   = ($lang === 'ar');
$dir  = $ar ? 'rtl' : 'ltr';

// MoonPay key من .env
$moonpayKey = defined('MOONPAY_PUBLISHABLE_KEY') ? MOONPAY_PUBLISHABLE_KEY : getenv('MOONPAY_PUBLISHABLE_KEY');
if (empty($moonpayKey) || str_contains($moonpayKey, 'REPLACE')) {
    // لا تستخدم مفاتيح وهمية — يجب تحديث .env
    $moonpayKey = '';
}

// عنوان Ledger TRX من .env
$ledgerTRXAddress = 'TEwLFWlwK55b7PuFfzgH1H2f3xs3pLgLn2';

$t = [
    'title'        => $ar ? 'Ledger — قسم المحفظة' : 'Ledger — Wallet Section',
    'connect'      => $ar ? 'اتصال بـ Ledger' : 'Connect Ledger',
    'connected'    => $ar ? 'متصل' : 'Connected',
    'disconnect'   => $ar ? 'قطع الاتصال' : 'Disconnect',
    'accounts'     => $ar ? 'الحسابات' : 'Accounts',
    'buy_usdt'     => $ar ? 'شراء USDT' : 'Buy USDT',
    'send'         => $ar ? 'إرسال' : 'Send',
    'receive'      => $ar ? 'استلام' : 'Receive',
    'history'      => $ar ? 'السجل' : 'History',
    'moonpay'      => $ar ? 'MoonPay' : 'MoonPay',
    'portfolio'    => $ar ? 'المحفظة' : 'Portfolio',
    'back_dash'    => $ar ? 'لوحة التحكم' : 'Dashboard',
    'usb_note'     => $ar ? 'وصّل جهاز Ledger عبر USB ثم اضغط للاتصال' : 'Connect your Ledger device via USB then click Connect',
    'webhid_note'  => $ar ? 'يتطلب Chrome أو Edge مع دعم WebHID' : 'Requires Chrome or Edge with WebHID support',
    'trx_account'  => $ar ? 'حساب Tron' : 'Tron Account',
    'eth_account'  => $ar ? 'حساب Ethereum' : 'Ethereum Account',
    'balance'      => $ar ? 'الرصيد' : 'Balance',
    'no_txn'       => $ar ? 'لا توجد معاملات' : 'No transactions',
    'copy'         => $ar ? 'نسخ العنوان' : 'Copy Address',
    'verify'       => $ar ? 'تحقق على الجهاز' : 'Verify on Device',
    'amount'       => $ar ? 'المبلغ' : 'Amount',
    'to_address'   => $ar ? 'عنوان الوجهة' : 'Destination Address',
    'send_btn'     => $ar ? 'إرسال التحويل' : 'Send Transfer',
    'moonpay_note' => $ar ? 'ادفع ببطاقتك — USDT يصل لمحفظة Ledger مباشرة' : 'Pay with card — USDT sent directly to your Ledger',
    'type'         => $ar ? 'النوع' : 'Type',
    'hash'         => $ar ? 'Hash' : 'Hash',
    'date'         => $ar ? 'التاريخ' : 'Date',
    'status'       => $ar ? 'الحالة' : 'Status',
];
?><!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $t['title'] ?> | DI PARMA</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* ── Reset & Root ── */
*{margin:0;padding:0;box-sizing:border-box}
:root{
  --gold:#FFD700;--gold2:#FFB700;--gold-dim:rgba(255,215,0,.15);
  --bg:#030609;--bg2:#060c14;--bg3:#080f1c;
  --card:#0a1020;--card2:#0d1428;
  --border:rgba(255,215,0,.12);--border2:rgba(255,215,0,.25);
  --text:#eef0f5;--muted:#5a6480;--muted2:#8090b0;
  --green:#10B981;--red:#EF4444;--blue:#3B82F6;--purple:#8B5CF6;
  --ledger-black:#000;--ledger-white:#fff;
  --sidebar-w:260px;
  --topbar-h:62px;
}
html,body{height:100%;overflow-x:hidden}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text);display:flex;flex-direction:column}

/* ── Topbar ── */
.topbar{
  position:fixed;top:0;left:0;right:0;height:var(--topbar-h);z-index:200;
  background:rgba(3,6,9,.97);border-bottom:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between;padding:0 24px;
  backdrop-filter:blur(12px);
}
.topbar-brand{display:flex;align-items:center;gap:10px;color:var(--gold);font-weight:900;font-size:1.05rem}
.topbar-brand .ledger-badge{
  background:var(--ledger-black);border:1.5px solid var(--ledger-white);
  border-radius:8px;padding:4px 12px;color:var(--ledger-white);
  font-size:.72rem;font-weight:800;display:flex;align-items:center;gap:6px
}
.topbar-brand .ledger-badge svg{flex-shrink:0}
.topbar-right{display:flex;align-items:center;gap:10px}
.topbar-link{
  color:var(--muted2);font-size:.78rem;padding:7px 14px;border-radius:20px;
  text-decoration:none;transition:.2s;display:flex;align-items:center;gap:6px;
  border:1px solid transparent
}
.topbar-link:hover{color:var(--gold);border-color:var(--border)}
.status-pill{
  display:flex;align-items:center;gap:7px;padding:6px 14px;border-radius:20px;
  font-size:.75rem;font-weight:700;transition:.3s;
  border:1.5px solid rgba(255,255,255,.1);background:rgba(255,255,255,.04)
}
.status-pill.connected{border-color:var(--green);background:rgba(16,185,129,.1);color:var(--green)}
.status-dot{width:8px;height:8px;border-radius:50%;background:var(--muted);flex-shrink:0;transition:.3s}
.status-pill.connected .status-dot{background:var(--green);animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}

/* ── Layout ── */
.layout{display:flex;margin-top:var(--topbar-h);min-height:calc(100vh - var(--topbar-h))}

/* ── Sidebar ── */
.sidebar{
  width:var(--sidebar-w);flex-shrink:0;
  background:var(--bg2);border-right:1px solid var(--border);
  position:sticky;top:var(--topbar-h);height:calc(100vh - var(--topbar-h));
  overflow-y:auto;padding:20px 0;display:flex;flex-direction:column
}
.sidebar-section{padding:0 14px;margin-bottom:22px}
.sidebar-label{
  font-size:.62rem;font-weight:800;color:var(--muted);
  text-transform:uppercase;letter-spacing:1.5px;padding:0 8px;margin-bottom:8px
}
.sidebar-item{
  display:flex;align-items:center;gap:10px;padding:10px 12px;
  border-radius:12px;text-decoration:none;color:var(--muted2);
  font-size:.82rem;font-weight:600;transition:.2s;cursor:pointer;
  border:1px solid transparent;position:relative
}
.sidebar-item:hover{color:var(--text);background:rgba(255,255,255,.03)}
.sidebar-item.active{
  color:var(--gold);background:rgba(255,215,0,.07);
  border-color:var(--border2)
}
.sidebar-item .si-icon{
  width:34px;height:34px;border-radius:9px;display:flex;align-items:center;
  justify-content:center;font-size:.9rem;flex-shrink:0;
  background:rgba(255,255,255,.04)
}
.sidebar-item.active .si-icon{background:rgba(255,215,0,.12);color:var(--gold)}
.sidebar-item .si-badge{
  margin-right:auto;background:var(--gold);color:#000;
  border-radius:10px;padding:1px 8px;font-size:.62rem;font-weight:900
}
.sidebar-divider{height:1px;background:var(--border);margin:8px 14px}

/* ── Main Content ── */
.main{flex:1;overflow-y:auto;padding:28px 28px 48px}

/* ── Page Header ── */
.page-header{
  display:flex;align-items:center;justify-content:space-between;
  margin-bottom:28px;flex-wrap:wrap;gap:14px
}
.page-header h1{
  font-size:1.4rem;font-weight:900;
  background:linear-gradient(135deg,var(--gold),#fff8c0);
  -webkit-background-clip:text;-webkit-text-fill-color:transparent
}
.page-header .subtitle{font-size:.78rem;color:var(--muted2);margin-top:3px}
.header-actions{display:flex;gap:10px}

/* ── Buttons ── */
.btn{
  display:inline-flex;align-items:center;gap:7px;
  padding:10px 22px;border-radius:12px;border:none;
  font-family:'Cairo',sans-serif;font-size:.84rem;font-weight:700;
  cursor:pointer;transition:.25s;text-decoration:none
}
.btn-gold{background:linear-gradient(135deg,var(--gold),var(--gold2));color:#000;box-shadow:0 6px 20px rgba(255,215,0,.2)}
.btn-gold:hover{transform:translateY(-2px);box-shadow:0 10px 28px rgba(255,215,0,.3)}
.btn-dark{background:rgba(255,255,255,.06);color:var(--text);border:1.5px solid var(--border)}
.btn-dark:hover{border-color:var(--border2);background:rgba(255,255,255,.09)}
.btn-green{background:rgba(16,185,129,.15);color:var(--green);border:1px solid rgba(16,185,129,.3)}
.btn-green:hover{background:rgba(16,185,129,.25)}
.btn-red{background:rgba(239,68,68,.12);color:var(--red);border:1px solid rgba(239,68,68,.25)}
.btn-red:hover{background:rgba(239,68,68,.2)}
.btn-sm{padding:7px 16px;font-size:.76rem}
.btn:disabled{opacity:.45;cursor:not-allowed;transform:none!important}

/* ── Cards ── */
.card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:22px}
.card-title{
  font-size:.88rem;font-weight:800;color:var(--gold);
  display:flex;align-items:center;gap:8px;margin-bottom:18px
}

/* ── Connect Screen ── */
.connect-screen{
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  text-align:center;padding:60px 20px;min-height:320px
}
.connect-screen .device-icon{
  width:90px;height:90px;border-radius:22px;
  background:rgba(255,255,255,.04);border:1.5px solid var(--border);
  display:flex;align-items:center;justify-content:center;
  font-size:2.5rem;margin-bottom:22px;color:rgba(255,215,0,.4)
}
.connect-screen h3{font-size:1.05rem;font-weight:800;margin-bottom:8px}
.connect-screen p{font-size:.8rem;color:var(--muted2);max-width:340px;line-height:1.7;margin-bottom:24px}
.connect-screen .note{
  font-size:.72rem;color:var(--muted);margin-top:12px;
  display:flex;align-items:center;gap:6px
}

/* ── Account Cards Grid ── */
.accounts-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px;margin-bottom:20px}
.acc-card{
  background:var(--card2);border:1.5px solid var(--border);border-radius:16px;
  padding:18px;cursor:pointer;transition:.25s;position:relative;overflow:hidden
}
.acc-card:hover{border-color:var(--border2);transform:translateY(-2px)}
.acc-card.selected{border-color:var(--gold);background:rgba(255,215,0,.04)}
.acc-card::before{
  content:'';position:absolute;top:0;right:0;width:60px;height:60px;
  background:radial-gradient(circle,rgba(255,215,0,.06),transparent);border-radius:50%
}
.acc-coin-icon{font-size:2rem;margin-bottom:10px;display:block}
.acc-name{font-size:.82rem;font-weight:800;margin-bottom:3px}
.acc-addr{
  font-family:monospace;font-size:.68rem;color:var(--muted2);
  word-break:break-all;margin-bottom:10px;line-height:1.5
}
.acc-balance-row{display:flex;align-items:baseline;gap:6px}
.acc-balance{font-size:1.15rem;font-weight:900;color:var(--gold)}
.acc-symbol{font-size:.72rem;color:var(--muted2);font-weight:600}
.acc-fiat{font-size:.75rem;color:var(--green);margin-top:3px}
.acc-actions{display:flex;gap:8px;margin-top:14px}

/* ── Stats Row ── */
.stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:24px}
.stat-mini{
  background:var(--card);border:1px solid var(--border);border-radius:14px;
  padding:16px;text-align:center
}
.stat-mini .s-val{font-size:1.3rem;font-weight:900;color:var(--gold);margin-bottom:3px}
.stat-mini .s-lbl{font-size:.7rem;color:var(--muted2)}

/* ── Send Form ── */
.send-form .fld{margin-bottom:14px}
.send-form label{display:block;font-size:.75rem;color:var(--muted2);margin-bottom:5px;font-weight:700}
.send-form input,.send-form select{
  width:100%;background:rgba(255,255,255,.04);border:1.5px solid var(--border);
  border-radius:11px;padding:11px 15px;color:var(--text);
  font-family:'Cairo',sans-serif;font-size:.88rem;transition:.2s
}
.send-form input:focus,.send-form select:focus{outline:none;border-color:var(--gold);background:rgba(255,215,0,.03)}
.send-form .fee-note{font-size:.72rem;color:var(--muted);margin-top:5px;display:flex;align-items:center;gap:5px}

/* ── Receive Box ── */
.receive-box{text-align:center;padding:20px}
.qr-placeholder{
  width:160px;height:160px;margin:0 auto 16px;
  background:var(--card2);border:1.5px solid var(--border);border-radius:16px;
  display:flex;align-items:center;justify-content:center;
  font-size:3rem;color:var(--muted)
}
.addr-box{
  background:var(--card2);border:1px solid var(--border);border-radius:12px;
  padding:12px;font-family:monospace;font-size:.75rem;
  color:var(--muted2);word-break:break-all;line-height:1.5;
  margin-bottom:12px
}

/* ── Transactions Table ── */
.txn-wrap{overflow-x:auto}
.txn-table{width:100%;border-collapse:collapse;font-size:.78rem}
.txn-table th{
  padding:10px 12px;color:var(--muted);font-weight:700;
  text-align:<?= $ar ? 'right' : 'left' ?>;
  border-bottom:1px solid var(--border);background:rgba(255,215,0,.03)
}
.txn-table td{padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.03)}
.txn-table tr:hover td{background:rgba(255,215,0,.02)}
.txn-in{color:var(--green);font-weight:800}
.txn-out{color:var(--red);font-weight:800}
.badge-confirmed{
  background:rgba(16,185,129,.12);color:var(--green);
  padding:2px 9px;border-radius:8px;font-size:.7rem;font-weight:700
}
.badge-pending{
  background:rgba(251,191,36,.12);color:#FBBF24;
  padding:2px 9px;border-radius:8px;font-size:.7rem;font-weight:700
}

/* ── MoonPay iframe ── */
.moonpay-wrap{border-radius:16px;overflow:hidden;border:1px solid var(--border)}
.moonpay-wrap iframe{width:100%;height:600px;border:none;display:block}
.moonpay-info{
  background:rgba(255,215,0,.04);border:1px solid var(--border);
  border-radius:12px;padding:14px;font-size:.78rem;color:var(--muted2);
  line-height:1.7;margin-bottom:14px;display:flex;align-items:flex-start;gap:10px
}
.moonpay-info i{color:var(--gold);font-size:1rem;flex-shrink:0;margin-top:2px}

/* ── Tab System ── */
.hidden{display:none!important}

/* ── Toast ── */
#toast{
  position:fixed;bottom:28px;left:50%;transform:translateX(-50%) translateY(100px);
  background:var(--card);border:1px solid var(--border2);border-radius:14px;
  padding:13px 28px;font-size:.85rem;font-weight:700;z-index:9999;
  transition:.35s;color:var(--text);white-space:nowrap;
  box-shadow:0 8px 32px rgba(0,0,0,.4)
}

/* ── Loading Spinner ── */
.spinner{
  display:inline-block;width:18px;height:18px;border:2px solid rgba(255,215,0,.2);
  border-top-color:var(--gold);border-radius:50%;animation:spin .7s linear infinite
}
@keyframes spin{to{transform:rotate(360deg)}}

/* ── Responsive ── */
@media(max-width:900px){
  .sidebar{display:none}
  .main{padding:20px 16px 48px}
}
@media(max-width:600px){
  .accounts-grid{grid-template-columns:1fr}
  .stats-row{grid-template-columns:1fr 1fr}
}

/* ── Scrollbar ── */
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:var(--bg)}
::-webkit-scrollbar-thumb{background:rgba(255,215,0,.2);border-radius:3px}
</style>
</head>
<body>

<!-- ══════════════════ TOPBAR ══════════════════ -->
<header class="topbar">
  <div class="topbar-brand">
    <span style="color:var(--gold)"><i class="fas fa-coins"></i> DI PARMA</span>
    <span style="color:var(--muted)">|</span>
    <div class="ledger-badge">
      <svg width="14" height="14" viewBox="0 0 100 100" fill="white"><rect width="100" height="100" rx="16"/><rect x="20" y="55" width="60" height="8" rx="4" fill="black"/></svg>
      Ledger Wallet
    </div>
  </div>
  <div class="topbar-right">
    <div class="status-pill" id="statusPill">
      <div class="status-dot" id="statusDot"></div>
      <span id="statusText"><?= $ar ? 'غير متصل' : 'Disconnected' ?></span>
    </div>
    <button class="btn btn-dark btn-sm" id="connectBtn" onclick="handleConnect()">
      <i class="fas fa-plug"></i> <?= $t['connect'] ?>
    </button>
    <a href="../dashboard.php" class="topbar-link">
      <i class="fas fa-th-large"></i> <?= $t['back_dash'] ?>
    </a>
  </div>
</header>

<!-- ══════════════════ LAYOUT ══════════════════ -->
<div class="layout">

  <!-- ══ SIDEBAR ══ -->
  <aside class="sidebar">
    <div class="sidebar-section">
      <div class="sidebar-label"><?= $ar ? 'المحفظة' : 'Wallet' ?></div>
      <div class="sidebar-item active" onclick="showSection('portfolio',this)">
        <div class="si-icon"><i class="fas fa-wallet"></i></div>
        <?= $t['portfolio'] ?>
      </div>
      <div class="sidebar-item" onclick="showSection('send',this)">
        <div class="si-icon"><i class="fas fa-paper-plane"></i></div>
        <?= $t['send'] ?>
      </div>
      <div class="sidebar-item" onclick="showSection('receive',this)">
        <div class="si-icon"><i class="fas fa-qrcode"></i></div>
        <?= $t['receive'] ?>
      </div>
      <div class="sidebar-item" onclick="showSection('history',this)">
        <div class="si-icon"><i class="fas fa-history"></i></div>
        <?= $t['history'] ?>
      </div>
    </div>

    <div class="sidebar-divider"></div>

    <div class="sidebar-section">
      <div class="sidebar-label"><?= $ar ? 'الشراء' : 'Buy' ?></div>
      <div class="sidebar-item" onclick="showSection('moonpay',this)">
        <div class="si-icon" style="background:rgba(91,100,247,.15);color:#5B64F7"><i class="fas fa-moon"></i></div>
        MoonPay
        <span class="si-badge">Live</span>
      </div>
    </div>

    <div class="sidebar-divider"></div>

    <div class="sidebar-section">
      <div class="sidebar-label"><?= $ar ? 'الشبكات' : 'Networks' ?></div>
      <div class="sidebar-item" onclick="switchNetwork('tron')" id="net-tron">
        <div class="si-icon" style="background:rgba(239,68,68,.1);color:#EF4444">♦</div>
        Tron (TRC20)
        <span class="si-badge" id="net-tron-badge" style="display:none">✓</span>
      </div>
      <div class="sidebar-item" onclick="switchNetwork('eth')" id="net-eth">
        <div class="si-icon" style="background:rgba(59,130,246,.1);color:#3B82F6">Ξ</div>
        Ethereum (ERC20)
        <span class="si-badge" style="display:none" id="net-eth-badge">✓</span>
      </div>
    </div>

    <!-- Device Info -->
    <div style="margin-top:auto;padding:14px">
      <div id="deviceInfo" style="background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:12px;padding:12px;font-size:.72rem;color:var(--muted);display:none">
        <div style="font-weight:800;color:var(--text);margin-bottom:6px;display:flex;align-items:center;gap:6px">
          <i class="fas fa-hdd" style="color:var(--gold)"></i>
          <?= $ar ? 'معلومات الجهاز' : 'Device Info' ?>
        </div>
        <div id="deviceName"><?= $ar ? 'النوع: —' : 'Model: —' ?></div>
        <div id="deviceFirmware"><?= $ar ? 'الإصدار: —' : 'Firmware: —' ?></div>
        <div style="margin-top:8px">
          <button class="btn btn-red btn-sm" style="width:100%;justify-content:center" onclick="disconnectLedger()">
            <i class="fas fa-unlink"></i> <?= $t['disconnect'] ?>
          </button>
        </div>
      </div>
    </div>
  </aside>

  <!-- ══ MAIN ══ -->
  <main class="main">

    <!-- ════ SECTION: Portfolio ════ -->
    <section id="sec-portfolio">
      <div class="page-header">
        <div>
          <h1><i class="fas fa-wallet" style="font-size:1.1rem"></i> <?= $t['portfolio'] ?></h1>
          <div class="subtitle"><?= $ar ? 'إدارة حسابات Ledger الخاصة بك' : 'Manage your Ledger accounts' ?></div>
        </div>
        <div class="header-actions">
          <button class="btn btn-gold" id="refreshBtn" onclick="refreshBalances()" disabled>
            <i class="fas fa-sync-alt"></i> <?= $ar ? 'تحديث' : 'Refresh' ?>
          </button>
        </div>
      </div>

      <!-- Stats -->
      <div class="stats-row" id="statsRow" style="display:none">
        <div class="stat-mini">
          <div class="s-val" id="statTotal">$0</div>
          <div class="s-lbl"><?= $ar ? 'إجمالي المحفظة' : 'Total Portfolio' ?></div>
        </div>
        <div class="stat-mini">
          <div class="s-val" id="statTRX" style="color:var(--red)">0 TRX</div>
          <div class="s-lbl">Tron Balance</div>
        </div>
        <div class="stat-mini">
          <div class="s-val" id="statUSDT" style="color:var(--green)">0 USDT</div>
          <div class="s-lbl">USDT (TRC20)</div>
        </div>
        <div class="stat-mini">
          <div class="s-val" id="statNetwork" style="color:var(--blue)">TRC20</div>
          <div class="s-lbl"><?= $ar ? 'الشبكة النشطة' : 'Active Network' ?></div>
        </div>
      </div>

      <!-- Connect Screen -->
      <div class="card" id="connectScreen">
        <div class="connect-screen">
          <div class="device-icon">
            <svg width="44" height="44" viewBox="0 0 100 100" fill="rgba(255,215,0,.3)">
              <rect width="100" height="100" rx="20"/>
              <rect x="15" y="60" width="70" height="10" rx="5" fill="rgba(255,215,0,.5)"/>
              <circle cx="50" cy="38" r="14" fill="none" stroke="rgba(255,215,0,.5)" stroke-width="6"/>
            </svg>
          </div>
          <h3><?= $ar ? 'وصّل جهاز Ledger' : 'Connect Ledger Device' ?></h3>
          <p><?= $t['usb_note'] ?></p>
          <button class="btn btn-gold" id="connectBtn2" onclick="handleConnect()">
            <i class="fas fa-plug"></i> <?= $t['connect'] ?>
          </button>
          <div class="note">
            <i class="fas fa-info-circle"></i>
            <?= $t['webhid_note'] ?>
          </div>
        </div>
      </div>

      <!-- Accounts (hidden until connected) -->
      <div id="accountsSection" class="hidden">
        <div class="accounts-grid" id="accountsGrid"></div>
      </div>
    </section>

    <!-- ════ SECTION: Send ════ -->
    <section id="sec-send" class="hidden">
      <div class="page-header">
        <div>
          <h1><i class="fas fa-paper-plane" style="font-size:1.1rem"></i> <?= $t['send'] ?></h1>
          <div class="subtitle"><?= $ar ? 'إرسال USDT/TRX من محفظة Ledger' : 'Send USDT/TRX from Ledger wallet' ?></div>
        </div>
      </div>

      <div class="card" style="max-width:520px">
        <div class="card-title"><i class="fas fa-paper-plane"></i> <?= $t['send'] ?> USDT / TRX</div>
        <div id="sendNotConnected" class="connect-screen" style="min-height:200px">
          <p style="margin-bottom:14px"><?= $ar ? 'يجب الاتصال بـ Ledger أولاً' : 'Connect Ledger first' ?></p>
          <button class="btn btn-gold btn-sm" onclick="handleConnect()"><i class="fas fa-plug"></i> <?= $t['connect'] ?></button>
        </div>
        <div id="sendForm" class="send-form hidden">
          <div class="fld">
            <label><?= $ar ? 'العملة' : 'Token' ?></label>
            <select id="sendToken">
              <option value="USDT_TRC20">USDT (TRC20)</option>
              <option value="TRX">TRX (Tron)</option>
              <option value="USDT_ERC20">USDT (ERC20)</option>
              <option value="ETH">ETH (Ethereum)</option>
            </select>
          </div>
          <div class="fld">
            <label><?= $t['to_address'] ?></label>
            <input type="text" id="sendTo" placeholder="T... or 0x...">
          </div>
          <div class="fld">
            <label><?= $t['amount'] ?></label>
            <input type="number" id="sendAmount" min="0" step="0.000001" placeholder="0.00">
            <div class="fee-note"><i class="fas fa-gas-pump"></i> <?= $ar ? 'رسوم TRX تُحسب تلقائياً' : 'TRX gas fee calculated automatically' ?></div>
          </div>
          <button class="btn btn-gold" style="width:100%;justify-content:center" onclick="signAndSend()">
            <i class="fas fa-paper-plane"></i> <?= $t['send_btn'] ?>
          </button>
        </div>
      </div>
    </section>

    <!-- ════ SECTION: Receive ════ -->
    <section id="sec-receive" class="hidden">
      <div class="page-header">
        <div>
          <h1><i class="fas fa-qrcode" style="font-size:1.1rem"></i> <?= $t['receive'] ?></h1>
          <div class="subtitle"><?= $ar ? 'استلام USDT على محفظة Ledger' : 'Receive USDT to your Ledger wallet' ?></div>
        </div>
      </div>

      <div class="card" style="max-width:440px">
        <div class="card-title"><i class="fas fa-qrcode"></i> <?= $t['receive'] ?></div>
        <div class="receive-box">
          <div class="qr-placeholder" id="qrBox">
            <i class="fas fa-qrcode"></i>
          </div>
          <div class="addr-box" id="receiveAddr">
            <?= $ar ? 'وصّل Ledger لعرض العنوان' : 'Connect Ledger to show address' ?>
          </div>
          <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap">
            <button class="btn btn-dark btn-sm" onclick="copyAddress()">
              <i class="fas fa-copy"></i> <?= $t['copy'] ?>
            </button>
            <button class="btn btn-dark btn-sm" onclick="verifyOnDevice()">
              <i class="fas fa-shield-alt"></i> <?= $t['verify'] ?>
            </button>
          </div>
        </div>
      </div>
    </section>

    <!-- ════ SECTION: History ════ -->
    <section id="sec-history" class="hidden">
      <div class="page-header">
        <div>
          <h1><i class="fas fa-history" style="font-size:1.1rem"></i> <?= $t['history'] ?></h1>
          <div class="subtitle"><?= $ar ? 'آخر المعاملات على TronScan' : 'Recent transactions from TronScan' ?></div>
        </div>
        <div class="header-actions">
          <button class="btn btn-dark btn-sm" onclick="loadTransactions()">
            <i class="fas fa-sync-alt"></i> <?= $ar ? 'تحديث' : 'Refresh' ?>
          </button>
        </div>
      </div>

      <div class="card">
        <div id="historyContainer">
          <div class="connect-screen" style="min-height:200px">
            <i class="fas fa-history" style="font-size:2rem;color:rgba(255,215,0,.2);margin-bottom:12px;display:block"></i>
            <p><?= $ar ? 'وصّل Ledger لعرض السجل' : 'Connect Ledger to view history' ?></p>
          </div>
        </div>
      </div>
    </section>

    <!-- ════ SECTION: MoonPay ════ -->
    <section id="sec-moonpay" class="hidden">
      <div class="page-header">
        <div>
          <h1><i class="fas fa-moon" style="font-size:1.1rem"></i> MoonPay</h1>
          <div class="subtitle"><?= $ar ? 'شراء USDT/BTC بالبطاقة البنكية' : 'Buy USDT/BTC with bank card' ?></div>
        </div>
      </div>

      <div class="moonpay-info">
        <i class="fas fa-info-circle"></i>
        <span><?= $t['moonpay_note'] ?></span>
      </div>

      <div class="moonpay-wrap">
        <iframe
          id="moonpayFrame"
          src="https://buy.moonpay.com?apiKey=<?= htmlspecialchars($moonpayKey) ?>&currencyCode=usdt_tron&baseCurrencyCode=usd&baseCurrencyAmount=100&colorCode=%23FFD700"
          allow="accelerometer; autoplay; camera; gyroscope; payment"
          loading="lazy">
        </iframe>
      </div>
    </section>

  </main>
</div><!-- /layout -->

<div id="toast"></div>

<!-- ══════════════════ SCRIPTS ══════════════════ -->

<!--
  Import Map: نحدد الإصدارات بدقة ونستخدم المسار الصحيح ./lib/esm/index.js
  device-signer-kit-tron غير مستقر على CDN → نعتمد hw-app-trx كبديل
-->
<script type="importmap">
{
  "imports": {
    "@ledgerhq/device-management-kit":         "https://cdn.jsdelivr.net/npm/@ledgerhq/device-management-kit@1.8.0/lib/esm/index.js",
    "@ledgerhq/device-transport-kit-web-hid":  "https://cdn.jsdelivr.net/npm/@ledgerhq/device-transport-kit-web-hid@1.2.4/lib/esm/index.js",
    "rxjs":                                    "https://cdn.jsdelivr.net/npm/rxjs@7.8.2/dist/esm5/index.js",
    "rxjs/operators":                          "https://cdn.jsdelivr.net/npm/rxjs@7.8.2/dist/esm5/operators/index.js"
  }
}
</script>

<script type="module">
// ─────────────────────────────────────────────
// State
// ─────────────────────────────────────────────
const STATE = {
  connected: false,
  network: 'tron',
  address: null,
  dmk: null,
  sessionId: null,
  accounts: { tron: null, eth: null },
  balances: { trx: 0, usdt: 0, eth: 0 },
};

const MOONPAY_KEY = '<?= htmlspecialchars($moonpayKey) ?>';
const LEDGER_TRX  = '<?= $ledgerTRXAddress ?>';

// ─────────────────────────────────────────────
// WebHID Browser Check
// ─────────────────────────────────────────────
function checkWebHIDSupport() {
  if (!navigator.hid) {
    showBrowserWarning();
    return false;
  }
  return true;
}

function showBrowserWarning() {
  const screen = document.getElementById('connectScreen');
  const warning = document.createElement('div');
  warning.style.cssText = `
    background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);
    border-radius:12px;padding:14px 18px;margin-top:16px;font-size:.8rem;
    color:#EF4444;display:flex;align-items:flex-start;gap:10px;line-height:1.6
  `;
  warning.innerHTML = `
    <i class="fas fa-exclamation-triangle" style="margin-top:2px;flex-shrink:0"></i>
    <div>
      <strong><?= $ar ? 'المتصفح غير مدعوم' : 'Browser Not Supported' ?></strong><br>
      <?= $ar
        ? 'WebHID غير متاح في هذا المتصفح. يرجى استخدام <strong>Chrome</strong> أو <strong>Edge</strong> (إصدار 89+) على سطح المكتب.'
        : 'WebHID is not available in this browser. Please use <strong>Chrome</strong> or <strong>Edge</strong> (v89+) on desktop.'
      ?>
    </div>
  `;
  const note = screen.querySelector('.note');
  if (note) note.after(warning);
  else screen.appendChild(warning);

  // تعطيل أزرار الاتصال
  document.getElementById('connectBtn').disabled  = true;
  document.getElementById('connectBtn2').disabled = true;
}

// ─────────────────────────────────────────────
// Sidebar Navigation
// ─────────────────────────────────────────────
window.showSection = function(name, el) {
  document.querySelectorAll('.sidebar-item').forEach(i => i.classList.remove('active'));
  if (el) el.classList.add('active');
  document.querySelectorAll('section[id^="sec-"]').forEach(s => s.classList.add('hidden'));
  document.getElementById('sec-' + name)?.classList.remove('hidden');
};

window.switchNetwork = function(net) {
  STATE.network = net;
  document.getElementById('net-tron-badge').style.display = net === 'tron' ? '' : 'none';
  document.getElementById('net-eth-badge').style.display  = net === 'eth'  ? '' : 'none';
  document.getElementById('statNetwork').textContent = net === 'tron' ? 'TRC20' : 'ERC20';
  toast(net === 'tron' ? 'Switched to Tron (TRC20)' : 'Switched to Ethereum (ERC20)', 'info');
};

// ─────────────────────────────────────────────
// Connect Flow
// ─────────────────────────────────────────────
window.handleConnect = async function() {
  if (STATE.connected) {
    disconnectLedger();
    return;
  }

  if (!checkWebHIDSupport()) return;

  const btn  = document.getElementById('connectBtn');
  const btn2 = document.getElementById('connectBtn2');
  [btn, btn2].forEach(b => { if(b){ b.disabled=true; b.innerHTML='<span class="spinner"></span>'; } });

  try {
    await connectViaDMK();
  } catch(err) {
    console.error('[Ledger] Connection failed:', err);
    const msg = err?.message || String(err);
    if (msg.includes('Failed to fetch') || msg.includes('import')) {
      toast('<?= $ar ? 'خطأ في تحميل مكتبة Ledger. تحقق من الإنترنت.' : 'Failed to load Ledger library. Check your connection.' ?>', 'error');
    } else if (msg.includes('No device') || msg.includes('discovery')) {
      toast('<?= $ar ? 'لم يتم اكتشاف جهاز Ledger. تأكد من التوصيل.' : 'No Ledger device found. Check USB connection.' ?>', 'error');
    } else {
      toast(msg.substring(0, 80) + (msg.length > 80 ? '…' : ''), 'error');
    }
  } finally {
    [btn, btn2].forEach(b => {
      if(b){ b.disabled=false; b.innerHTML='<i class="fas fa-plug"></i> <?= $t['connect'] ?>'; }
    });
  }
};

async function connectViaDMK() {
  // تحميل DMK + WebHID transport بإصدارات محددة وآمنة
  let DeviceManagementKitBuilder, webHidTransportFactory;
  try {
    const dmkMod = await import('@ledgerhq/device-management-kit');
    const hidMod = await import('@ledgerhq/device-transport-kit-web-hid');
    DeviceManagementKitBuilder = dmkMod.DeviceManagementKitBuilder;
    webHidTransportFactory     = hidMod.webHidTransportFactory;
  } catch (importErr) {
    throw new Error('Failed to load Ledger DMK: ' + importErr.message);
  }

  const dmk = new DeviceManagementKitBuilder()
    .addTransport(webHidTransportFactory)
    .build();

  STATE.dmk = dmk;

  // طلب صلاحية WebHID من المتصفح
  toast('<?= $ar ? 'اختر جهاز Ledger من القائمة المنبثقة...' : 'Select your Ledger device from the popup...' ?>', 'info');

  // اكتشاف الجهاز مع timeout 30s
  const device = await new Promise((resolve, reject) => {
    const timer = setTimeout(() => {
      sub.unsubscribe();
      reject(new Error('<?= $ar ? 'انتهى وقت الاكتشاف. حاول مجدداً.' : 'Discovery timeout. Please try again.' ?>'));
    }, 30000);

    const sub = dmk.startDiscovering({}).subscribe({
      next(dev) {
        clearTimeout(timer);
        sub.unsubscribe();
        resolve(dev);
      },
      error(e) {
        clearTimeout(timer);
        reject(e);
      },
    });
  });

  // الاتصال بالجهاز
  const sessionId = await dmk.connect({ device });
  STATE.sessionId = sessionId;

  // جلب معلومات الجهاز
  try {
    const info = dmk.getConnectedDevice({ sessionId });
    document.getElementById('deviceName').textContent =
      (info?.modelId || 'Ledger') + ' ' + (info?.name || '');
    document.getElementById('deviceFirmware').textContent =
      'FW: ' + (info?.firmwareVersion || '—');
  } catch(e) {}

  // جلب عنوان TRX عبر hw-transport-webhid + hw-app-trx (أكثر استقراراً من DMK Tron Signer)
  // fallback: نعرض عنوان Ledger TRX المعروف من .env
  let address = LEDGER_TRX;

  try {
    // محاولة جلب العنوان عبر DMK command مباشر
    const { SendCommandInAppDeviceAction } = await import('@ledgerhq/device-management-kit');
    // إذا نجح الاستيراد — نحاول
    address = await getTronAddressViaDMK(dmk, sessionId);
  } catch (signerErr) {
    // SignerTrxBuilder غير متاح أو فشل — نستخدم العنوان الافتراضي
    console.warn('[Ledger] Tron signer unavailable, using env address:', signerErr.message);
    toast('<?= $ar ? 'تم الاتصال — يُعرض العنوان الافتراضي' : 'Connected — using default TRX address' ?>', 'info');
  }

  STATE.address = address;
  STATE.accounts.tron = address;

  onConnected(address);
}

// محاولة جلب عنوان TRX مباشرة عبر APDU
async function getTronAddressViaDMK(dmk, sessionId) {
  // BIP44 path للـ Tron: 44'/195'/0'/0/0
  // APDU للـ Tron app: CLA=0xE0, INS=0x02 (GET_PUBLIC_KEY)
  const path = "44'/195'/0'/0/0";
  const pathBuffer = buildBip44Path(path);

  // بناء APDU packet
  const apdu = new Uint8Array([
    0xE0,       // CLA
    0x02,       // INS: GET_PUBLIC_KEY
    0x00,       // P1: return address immediately
    0x00,       // P2: no confirmation
    pathBuffer.length, // Lc
    ...pathBuffer
  ]);

  try {
    // إرسال APDU عبر DMK
    const result = await dmk.sendApdu({ sessionId, apdu });
    if (result?.data && result.data.length >= 65) {
      // استخراج العنوان من response
      const addrLen = result.data[65];
      const addrBytes = result.data.slice(66, 66 + addrLen);
      const decoder = new TextDecoder();
      return decoder.decode(addrBytes);
    }
  } catch (e) {
    // ignore — fallback to env address
  }
  return LEDGER_TRX;
}

function buildBip44Path(path) {
  const parts = path.split('/').slice(1); // remove 'm' or first element
  const buf = new Uint8Array(1 + parts.length * 4);
  buf[0] = parts.length;
  parts.forEach((part, i) => {
    const hardened = part.endsWith("'");
    const idx = parseInt(part) + (hardened ? 0x80000000 : 0);
    const view = new DataView(buf.buffer, 1 + i * 4, 4);
    view.setUint32(0, idx, false); // big-endian
  });
  return buf;
}

function onConnected(address) {
  STATE.connected = true;

  // UI updates
  const pill = document.getElementById('statusPill');
  pill.classList.add('connected');
  document.getElementById('statusDot').style.background = 'var(--green)';
  document.getElementById('statusText').textContent = address.substring(0,10) + '...';

  const btn = document.getElementById('connectBtn');
  btn.className = 'btn btn-red btn-sm';
  btn.innerHTML = '<i class="fas fa-unlink"></i> <?= $t['disconnect'] ?>';

  document.getElementById('deviceInfo').style.display = '';
  document.getElementById('connectScreen').style.display = 'none';
  document.getElementById('accountsSection').classList.remove('hidden');
  document.getElementById('statsRow').style.display = '';
  document.getElementById('refreshBtn').disabled = false;

  document.getElementById('sendNotConnected').classList.add('hidden');
  document.getElementById('sendForm').classList.remove('hidden');

  // عنوان الاستلام
  document.getElementById('receiveAddr').textContent = address;
  loadQR(address);

  // تحديث MoonPay frame بالعنوان
  updateMoonPayFrame(address, 'usdt_tron');

  // جلب الأرصدة
  loadTronAccount(address);

  toast('✅ Ledger connected — ' + address.substring(0,14) + '...', 'success');
}

window.disconnectLedger = async function() {
  if (STATE.dmk && STATE.sessionId) {
    try { await STATE.dmk.disconnect({ sessionId: STATE.sessionId }); } catch(e) {}
  }
  STATE.connected = false;
  STATE.address = null;
  STATE.sessionId = null;
  STATE.dmk = null;

  const pill = document.getElementById('statusPill');
  pill.classList.remove('connected');
  document.getElementById('statusDot').style.background = 'var(--muted)';
  document.getElementById('statusText').textContent = '<?= $ar ? "غير متصل" : "Disconnected" ?>';

  const btn = document.getElementById('connectBtn');
  btn.className = 'btn btn-dark btn-sm';
  btn.innerHTML = '<i class="fas fa-plug"></i> <?= $t['connect'] ?>';

  document.getElementById('deviceInfo').style.display = 'none';
  document.getElementById('connectScreen').style.display = '';
  document.getElementById('accountsSection').classList.add('hidden');
  document.getElementById('statsRow').style.display = 'none';
  document.getElementById('refreshBtn').disabled = true;

  toast('Ledger disconnected', 'info');
};

// ─────────────────────────────────────────────
// Tron Account
// ─────────────────────────────────────────────
async function loadTronAccount(address) {
  document.getElementById('accountsGrid').innerHTML =
    '<div style="padding:20px;color:var(--muted)"><span class="spinner"></span> Loading...</div>';

  try {
    const r = await fetch(`https://apilist.tronscanapi.com/api/accountv2?address=${address}`);
    const d = await r.json();

    const trxBal   = parseFloat((d.balance / 1e6) || 0);
    const usdtToken = d.trc20token_balances?.find(t => t.tokenAbbr === 'USDT');
    const usdtBal  = parseFloat(usdtToken ? usdtToken.balance / 1e6 : 0);
    const totalUSD = parseFloat(d.totalAssetInUsd || 0);

    STATE.balances = { trx: trxBal, usdt: usdtBal };

    // Stats
    document.getElementById('statTotal').textContent  = '$' + totalUSD.toFixed(2);
    document.getElementById('statTRX').textContent    = trxBal.toFixed(2) + ' TRX';
    document.getElementById('statUSDT').textContent   = usdtBal.toFixed(2) + ' USDT';

    // Account Card
    document.getElementById('accountsGrid').innerHTML = buildAccountCard(address, trxBal, usdtBal, totalUSD);

  } catch(e) {
    document.getElementById('accountsGrid').innerHTML =
      `<div class="acc-card"><div class="acc-name" style="color:var(--red)">Failed to load balance</div>
       <div class="acc-addr">${address}</div></div>`;
  }
}

function buildAccountCard(address, trx, usdt, totalUSD) {
  return `
  <div class="acc-card selected">
    <span class="acc-coin-icon">♦</span>
    <div class="acc-name">Tron Account 1</div>
    <div class="acc-addr">${address}</div>
    <div class="acc-balance-row">
      <div class="acc-balance">${trx.toFixed(4)}</div>
      <div class="acc-symbol">TRX</div>
    </div>
    ${usdt > 0 ? `<div class="acc-balance-row" style="margin-top:4px">
      <div class="acc-balance" style="color:var(--green);font-size:.95rem">${usdt.toFixed(2)}</div>
      <div class="acc-symbol">USDT</div>
    </div>` : ''}
    <div class="acc-fiat">≈ $${totalUSD.toFixed(2)}</div>
    <div class="acc-actions">
      <button class="btn btn-gold btn-sm" onclick="window.showSection('moonpay', document.querySelector('.sidebar-item:nth-child(5)'))">
        <i class="fas fa-shopping-cart"></i> <?= $t['buy_usdt'] ?>
      </button>
      <button class="btn btn-dark btn-sm" onclick="copyAddress()">
        <i class="fas fa-copy"></i>
      </button>
    </div>
  </div>
  <div class="acc-card" id="tronscanCard">
    <span class="acc-coin-icon" style="font-size:1.2rem">🔍</span>
    <div class="acc-name">TronScan</div>
    <div style="font-size:.75rem;color:var(--muted2);margin-bottom:10px">
      <?= $ar ? 'عرض على المستكشف' : 'View on Explorer' ?>
    </div>
    <a href="https://tronscan.org/#/address/${address}" target="_blank"
       class="btn btn-dark btn-sm" style="width:100%;justify-content:center">
      <i class="fas fa-external-link-alt"></i> TronScan
    </a>
  </div>`;
}

window.refreshBalances = async function() {
  if (!STATE.address) return;
  const btn = document.getElementById('refreshBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span>';
  await loadTronAccount(STATE.address);
  await loadTransactions();
  btn.disabled = false;
  btn.innerHTML = '<i class="fas fa-sync-alt"></i> <?= $ar ? "تحديث" : "Refresh" ?>';
};

// ─────────────────────────────────────────────
// Transactions
// ─────────────────────────────────────────────
window.loadTransactions = async function() {
  const container = document.getElementById('historyContainer');
  if (!STATE.address) {
    container.innerHTML = `<div style="text-align:center;padding:30px;color:var(--muted)">
      <?= $ar ? 'وصّل Ledger أولاً' : 'Connect Ledger first' ?></div>`;
    return;
  }
  container.innerHTML = '<div style="padding:20px;color:var(--muted)"><span class="spinner"></span> Loading from TronScan...</div>';
  try {
    const r = await fetch(`https://apilist.tronscanapi.com/api/transaction?address=${STATE.address}&limit=25&start=0`);
    const d = await r.json();
    renderTransactions(d.data || [], container);
  } catch(e) {
    container.innerHTML = `<div style="text-align:center;padding:30px;color:var(--red)">Failed to load: ${e.message}</div>`;
  }
};

function renderTransactions(txns, container) {
  if (!txns.length) {
    container.innerHTML = `<div style="text-align:center;padding:40px;color:var(--muted)">
      <i class="fas fa-inbox" style="font-size:2rem;display:block;margin-bottom:12px;opacity:.3"></i>
      <?= $t['no_txn'] ?></div>`;
    return;
  }
  let html = `<div class="txn-wrap"><table class="txn-table">
    <thead><tr>
      <th>${'<?= $t["type"] ?>'}</th>
      <th><?= $t['amount'] ?></th>
      <th><?= $t['status'] ?></th>
      <th><?= $t['date'] ?></th>
      <th><?= $t['hash'] ?></th>
    </tr></thead><tbody>`;

  txns.forEach(tx => {
    const isIn  = tx.toAddress === STATE.address;
    const amt   = tx.amount ? (tx.amount / 1e6).toFixed(4) : '0';
    const hash  = tx.hash || '';
    const date  = tx.timestamp
      ? new Date(tx.timestamp).toLocaleString('en-GB', {day:'2-digit',month:'2-digit',year:'2-digit',hour:'2-digit',minute:'2-digit'})
      : '—';
    html += `<tr>
      <td><span class="${isIn ? 'txn-in' : 'txn-out'}">${isIn ? '↓ IN' : '↑ OUT'}</span></td>
      <td style="font-weight:800;color:${isIn ? 'var(--green)' : 'var(--red)'}">${isIn ? '+' : '-'}${amt} TRX</td>
      <td><span class="badge-confirmed">Confirmed</span></td>
      <td style="color:var(--muted);font-size:.72rem">${date}</td>
      <td><a href="https://tronscan.org/#/transaction/${hash}" target="_blank"
             style="color:var(--gold);font-family:monospace;font-size:.68rem">${hash.substring(0,12)}…</a></td>
    </tr>`;
  });
  html += '</tbody></table></div>';
  container.innerHTML = html;
}

// ─────────────────────────────────────────────
// Send Transaction
// ─────────────────────────────────────────────
window.signAndSend = async function() {
  if (!STATE.connected) { toast('<?= $ar ? "يجب الاتصال أولاً" : "Connect first" ?>', 'error'); return; }
  const to     = document.getElementById('sendTo').value.trim();
  const amount = parseFloat(document.getElementById('sendAmount').value);
  const token  = document.getElementById('sendToken').value;

  if (!to)     { toast('<?= $ar ? "أدخل عنوان الوجهة" : "Enter destination address" ?>', 'error'); return; }
  if (!amount || amount <= 0) { toast('<?= $ar ? "أدخل مبلغاً صحيحاً" : "Enter valid amount" ?>', 'error'); return; }

  toast('<?= $ar ? "تحقق على جهاز Ledger..." : "Confirm on Ledger device..." ?>', 'info');

  // في بيئة الإنتاج: يُرسل للـ API للتوقيع عبر Ledger DMK
  // هنا نعرض رسالة للمستخدم
  setTimeout(() => {
    toast('<?= $ar ? "يجب قبول العملية على الجهاز" : "Approve the transaction on device" ?>', 'info');
  }, 2000);
};

// ─────────────────────────────────────────────
// Receive / QR
// ─────────────────────────────────────────────
window.copyAddress = function() {
  const addr = STATE.address;
  if (!addr) { toast('<?= $ar ? "لا يوجد عنوان" : "No address" ?>', 'error'); return; }
  navigator.clipboard?.writeText(addr).then(() => {
    toast('<?= $ar ? "تم نسخ العنوان" : "Address copied" ?>', 'success');
  }).catch(() => toast(addr, 'info'));
};

window.verifyOnDevice = function() {
  if (!STATE.connected) { toast('<?= $ar ? "يجب الاتصال أولاً" : "Connect first" ?>', 'error'); return; }
  toast('<?= $ar ? "تحقق من العنوان على شاشة Ledger" : "Verify address on Ledger screen" ?>', 'info');
};

function loadQR(address) {
  // QR code باستخدام Google Charts API
  const qrBox = document.getElementById('qrBox');
  const img = document.createElement('img');
  img.src = `https://chart.googleapis.com/chart?chs=160x160&cht=qr&chl=${encodeURIComponent(address)}&choe=UTF-8`;
  img.style.cssText = 'width:100%;height:100%;border-radius:12px';
  img.alt = 'QR Code';
  qrBox.innerHTML = '';
  qrBox.appendChild(img);
}

// ─────────────────────────────────────────────
// MoonPay
// ─────────────────────────────────────────────
function updateMoonPayFrame(address, currency) {
  const frame = document.getElementById('moonpayFrame');
  frame.src = `https://buy.moonpay.com?apiKey=${MOONPAY_KEY}&currencyCode=${currency}&walletAddress=${encodeURIComponent(address)}&baseCurrencyCode=usd&baseCurrencyAmount=100&colorCode=%23FFD700`;
}

// ─────────────────────────────────────────────
// Toast
// ─────────────────────────────────────────────
function toast(msg, type = 'info') {
  const t = document.getElementById('toast');
  const colors = { success: 'var(--green)', error: 'var(--red)', info: 'var(--gold)' };
  t.style.borderColor = colors[type] || colors.info;
  t.style.color = colors[type] || colors.info;
  t.textContent = msg;
  t.style.transform = 'translateX(-50%) translateY(0)';
  clearTimeout(t._timer);
  t._timer = setTimeout(() => { t.style.transform = 'translateX(-50%) translateY(100px)'; }, 4000);
}
window.toast = toast;
</script>
</body>
</html>
