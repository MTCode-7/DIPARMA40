<?php
/**
 * ============================================================
 * DI PARMA | POS Download — تحميل نظام POS لـ Bitel IC3600
 * ============================================================
 */
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/functions.php';

$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'en' ? 'en' : 'ar';
$ar   = ($lang === 'ar');
$dir  = $ar ? 'rtl' : 'ltr';

$siteUrl     = defined('SITE_URL') ? rtrim(SITE_URL, '/') : 'https://diparmas.com';
$posUrl      = $siteUrl . '/pos.php';
$installerUrl= $siteUrl . '/pos_installer.php';
$version     = '1.0.0';
$buildDate   = '2026-07-25';
?><!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA POS | تحميل النظام</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&family=Share+Tech+Mono&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{
  --gold:#FFD700;--gold2:#FFB700;
  --bg:#020508;--card:#090f1e;--card2:#0b1224;
  --border:rgba(255,215,0,.12);--border2:rgba(255,215,0,.28);
  --text:#edf0f7;--muted:#4a5568;--muted2:#718096;
  --green:#10B981;--red:#EF4444;--orange:#F97316;
}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}

/* Topbar */
.topbar{background:rgba(2,5,8,.97);border-bottom:1px solid var(--border);
  height:60px;display:flex;align-items:center;justify-content:space-between;
  padding:0 28px;position:sticky;top:0;z-index:100}
.tb-brand{color:var(--gold);font-weight:900;font-size:1.05rem;display:flex;align-items:center;gap:10px}
.tb-nav a{color:var(--muted2);font-size:.78rem;padding:6px 14px;border-radius:18px;text-decoration:none;transition:.2s}
.tb-nav a:hover{color:var(--gold)}

/* Hero */
.hero{background:linear-gradient(135deg,#030a14,#060e1c,#030a14);
  border-bottom:1px solid var(--border);padding:60px 28px;text-align:center}
.hero-badge{display:inline-flex;align-items:center;gap:8px;
  background:rgba(249,115,22,.1);border:1px solid rgba(249,115,22,.3);
  border-radius:20px;padding:6px 18px;font-size:.78rem;font-weight:700;
  color:var(--orange);margin-bottom:20px}
.hero h1{font-size:2.2rem;font-weight:900;margin-bottom:12px;
  background:linear-gradient(135deg,var(--gold),#fff8c0);
  -webkit-background-clip:text;-webkit-text-fill-color:transparent}
.hero p{font-size:.92rem;color:var(--muted2);max-width:560px;margin:0 auto 28px;line-height:1.8}
.version-tag{display:inline-flex;align-items:center;gap:6px;
  background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.2);
  border-radius:10px;padding:4px 14px;font-size:.72rem;color:var(--green);font-family:'Share Tech Mono',monospace}

/* Layout */
.wrap{max-width:1100px;margin:0 auto;padding:40px 24px}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:24px}
.grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-bottom:32px}

/* Cards */
.card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:24px}
.card-title{font-size:.92rem;font-weight:800;color:var(--gold);
  display:flex;align-items:center;gap:8px;margin-bottom:18px}

/* Device Card */
.device-card{background:linear-gradient(145deg,#0d1428,#090f1e);
  border:1.5px solid var(--border2);border-radius:20px;padding:28px;
  display:flex;flex-direction:column;align-items:center;text-align:center}
.device-icon{width:100px;height:130px;background:linear-gradient(145deg,#1a1a2e,#0d0d1a);
  border-radius:16px;margin-bottom:20px;display:flex;align-items:center;justify-content:center;
  font-size:3.5rem;box-shadow:0 20px 40px rgba(0,0,0,.5),0 0 0 1px rgba(255,255,255,.05);
  position:relative}
.device-screen{position:absolute;top:12px;left:12px;right:12px;height:50px;
  background:var(--bg);border-radius:6px;display:flex;align-items:center;
  justify-content:center;font-family:'Share Tech Mono',monospace;
  font-size:.55rem;color:rgba(0,255,65,.7);padding:4px}
.device-name{font-size:1.1rem;font-weight:900;margin-bottom:6px}
.device-spec{font-size:.72rem;color:var(--muted2);line-height:1.9}

/* Steps */
.steps{counter-reset:step}
.step{display:flex;gap:16px;margin-bottom:20px;padding-bottom:20px;
  border-bottom:1px solid rgba(255,255,255,.04)}
.step:last-child{border:none;margin-bottom:0;padding-bottom:0}
.step-num{width:36px;height:36px;border-radius:50%;
  background:linear-gradient(135deg,var(--gold),var(--gold2));
  color:#000;font-weight:900;font-size:.85rem;
  display:flex;align-items:center;justify-content:center;flex-shrink:0}
.step-content{flex:1}
.step-title{font-size:.88rem;font-weight:800;margin-bottom:6px}
.step-desc{font-size:.78rem;color:var(--muted2);line-height:1.7}
.step-code{background:rgba(0,0,0,.4);border:1px solid var(--border);border-radius:10px;
  padding:10px 14px;font-family:'Share Tech Mono',monospace;font-size:.75rem;
  color:var(--green);margin-top:8px;word-break:break-all}

/* Spec Grid */
.spec-item{background:var(--card2);border:1px solid var(--border);border-radius:12px;
  padding:14px;text-align:center}
.spec-icon{font-size:1.4rem;margin-bottom:8px;display:block}
.spec-val{font-size:.88rem;font-weight:800;color:var(--gold);margin-bottom:3px}
.spec-lbl{font-size:.68rem;color:var(--muted2)}

/* Download Button */
.dl-btn{display:flex;align-items:center;justify-content:center;gap:10px;
  width:100%;padding:16px;border-radius:14px;border:none;cursor:pointer;
  font-family:'Cairo',sans-serif;font-size:1rem;font-weight:800;
  background:linear-gradient(135deg,var(--orange),#e55c00);
  color:#fff;box-shadow:0 8px 28px rgba(249,115,22,.3);
  transition:.3s;text-decoration:none;margin-top:16px}
.dl-btn:hover{transform:translateY(-2px);box-shadow:0 12px 36px rgba(249,115,22,.4)}
.dl-btn-secondary{background:rgba(255,255,255,.06);color:var(--text);
  border:1.5px solid var(--border);box-shadow:none;margin-top:10px}
.dl-btn-secondary:hover{border-color:var(--border2);box-shadow:none}

/* QR */
.qr-box{background:var(--card2);border:1px solid var(--border);border-radius:16px;
  padding:24px;text-align:center}
.qr-img{width:180px;height:180px;margin:0 auto 12px;
  background:#fff;border-radius:12px;padding:8px;display:flex;align-items:center;justify-content:center}
.qr-img img{width:100%;height:100%;border-radius:6px}

/* Features */
.feat-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}
.feat-item{background:var(--card2);border:1px solid var(--border);
  border-radius:12px;padding:14px;display:flex;align-items:flex-start;gap:10px}
.feat-icon{width:34px;height:34px;border-radius:9px;display:flex;
  align-items:center;justify-content:center;font-size:.88rem;flex-shrink:0}
.feat-title{font-size:.78rem;font-weight:800;margin-bottom:3px}
.feat-desc{font-size:.68rem;color:var(--muted2);line-height:1.5}

/* Info box */
.info-box{background:rgba(16,185,129,.05);border:1px solid rgba(16,185,129,.15);
  border-radius:12px;padding:14px;font-size:.78rem;color:var(--muted2);
  line-height:1.7;margin-top:14px;display:flex;gap:10px}
.info-box i{color:var(--green);flex-shrink:0;margin-top:2px}
.warn-box{background:rgba(249,115,22,.05);border:1px solid rgba(249,115,22,.15);
  border-radius:12px;padding:14px;font-size:.78rem;color:var(--muted2);
  line-height:1.7;margin-top:14px;display:flex;gap:10px}
.warn-box i{color:var(--orange);flex-shrink:0;margin-top:2px}

/* Badges */
.badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;
  border-radius:8px;font-size:.68rem;font-weight:700}
.badge-live{background:rgba(16,185,129,.12);color:var(--green);border:1px solid rgba(16,185,129,.2)}
.badge-nuvei{background:rgba(249,115,22,.1);color:var(--orange);border:1px solid rgba(249,115,22,.2)}
.badge-mashreq{background:rgba(255,215,0,.08);color:var(--gold);border:1px solid rgba(255,215,0,.15)}

/* Toast */
#toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(100px);
  background:var(--card);border:1px solid var(--border2);border-radius:14px;
  padding:12px 28px;font-size:.84rem;font-weight:700;z-index:9999;
  transition:.35s;color:var(--text);box-shadow:0 8px 32px rgba(0,0,0,.5)}

@media(max-width:768px){.grid-2,.grid-3{grid-template-columns:1fr}.feat-grid{grid-template-columns:1fr}}
</style>
</head>
<body>

<header class="topbar">
  <div class="tb-brand">
    <i class="fas fa-coins"></i> DI PARMA
    <span style="color:var(--muted);margin:0 4px">|</span>
    <span style="color:var(--orange);font-size:.85rem"><i class="fas fa-cash-register"></i> POS System</span>
  </div>
  <div class="tb-nav">
    <a href="pos.php"><i class="fas fa-cash-register"></i> <?=$ar?'تشغيل POS':'Launch POS'?></a>
    <a href="dashboard.php"><i class="fas fa-th-large"></i> <?=$ar?'لوحة التحكم':'Dashboard'?></a>
  </div>
</header>

<!-- Hero -->
<div class="hero">
  <div class="hero-badge"><i class="fas fa-download"></i> <?=$ar?'تحميل النظام':'System Download'?></div>
  <h1><?=$ar?'نظام DI PARMA POS':'DI PARMA POS System'?></h1>
  <p><?=$ar?
    'نظام نقطة البيع المتكامل — Nuvei + Mashreq Bank (TRANSCENDIO FZ-LLC)<br>مصمم خصيصاً لجهاز Bitel IC3600':
    'Integrated POS System — Nuvei + Mashreq Bank (TRANSCENDIO FZ-LLC)<br>Designed specifically for Bitel IC3600'
  ?></p>
  <div class="version-tag">
    <i class="fas fa-tag"></i> v<?=$version?> &nbsp;|&nbsp; Build <?=$buildDate?>
    &nbsp;|&nbsp; <span style="color:var(--green)">● LIVE</span>
  </div>
</div>

<div class="wrap">

  <!-- Spec Row -->
  <div class="grid-3" style="margin-bottom:32px">
    <div class="spec-item">
      <span class="spec-icon">💳</span>
      <div class="spec-val">Nuvei API</div>
      <div class="spec-lbl"><?=$ar?'بوابة الدفع':'Payment Gateway'?></div>
    </div>
    <div class="spec-item">
      <span class="spec-icon">🏦</span>
      <div class="spec-val">Mashreq Bank</div>
      <div class="spec-lbl">TRANSCENDIO FZ-LLC</div>
    </div>
    <div class="spec-item">
      <span class="spec-icon">📱</span>
      <div class="spec-val">Bitel IC3600</div>
      <div class="spec-lbl">Android POS</div>
    </div>
    <div class="spec-item">
      <span class="spec-icon">♦</span>
      <div class="spec-val">Ledger TRX</div>
      <div class="spec-lbl"><?=$ar?'تحويل تلقائي':'Auto Transfer'?></div>
    </div>
    <div class="spec-item">
      <span class="spec-icon">🔒</span>
      <div class="spec-val">SSL / HTTPS</div>
      <div class="spec-lbl"><?=$ar?'مشفّر بالكامل':'Fully Encrypted'?></div>
    </div>
    <div class="spec-item">
      <span class="spec-icon">⚡</span>
      <div class="spec-val"><?=$ar?'10 عمليات':'10 TXN Types'?></div>
      <div class="spec-lbl"><?=$ar?'شراء، تفويض، استرداد...':'Purchase, Auth, Refund...'?></div>
    </div>
  </div>

  <div class="grid-2">

    <!-- LEFT: خطوات التثبيت -->
    <div>
      <div class="card" style="margin-bottom:20px">
        <div class="card-title"><i class="fas fa-list-ol"></i> <?=$ar?'خطوات التثبيت':'Installation Steps'?></div>

        <div class="steps">
          <div class="step">
            <div class="step-num">1</div>
            <div class="step-content">
              <div class="step-title"><?=$ar?'على جهاز Bitel IC3600':'On Bitel IC3600 Device'?></div>
              <div class="step-desc"><?=$ar?
                'افتح المتصفح المدمج (Chrome) على الجهاز':
                'Open the built-in browser (Chrome) on the device'
              ?></div>
            </div>
          </div>

          <div class="step">
            <div class="step-num">2</div>
            <div class="step-content">
              <div class="step-title"><?=$ar?'انتقل لصفحة المثبّت':'Go to Installer Page'?></div>
              <div class="step-desc"><?=$ar?'اكتب هذا الرابط في المتصفح':'Type this URL in browser'?></div>
              <div class="step-code" onclick="copyText('<?=$installerUrl?>')" style="cursor:pointer" title="Click to copy">
                <?=$installerUrl?>
                <i class="fas fa-copy" style="margin-right:8px;color:var(--muted2)"></i>
              </div>
            </div>
          </div>

          <div class="step">
            <div class="step-num">3</div>
            <div class="step-content">
              <div class="step-title"><?=$ar?'تسجيل الدخول':'Login'?></div>
              <div class="step-desc"><?=$ar?
                'أدخل بيانات حساب DI PARMA — ثم اضغط "تثبيت"':
                'Enter your DI PARMA credentials — then press "Install"'
              ?></div>
            </div>
          </div>

          <div class="step">
            <div class="step-num">4</div>
            <div class="step-content">
              <div class="step-title"><?=$ar?'إضافة للشاشة الرئيسية':'Add to Home Screen'?></div>
              <div class="step-desc"><?=$ar?
                'في Chrome: القائمة ← "إضافة إلى الشاشة الرئيسية" — يعمل كتطبيق مستقل (PWA)':
                'In Chrome: Menu ← "Add to Home Screen" — Works as standalone app (PWA)'
              ?></div>
            </div>
          </div>

          <div class="step">
            <div class="step-num">5</div>
            <div class="step-content">
              <div class="step-title"><?=$ar?'ربط Ledger (اختياري)':'Connect Ledger (optional)'?></div>
              <div class="step-desc"><?=$ar?
                'وصّل جهاز Ledger عبر USB OTG — يُحوّل المبالغ تلقائياً بعد كل عملية':
                'Connect Ledger via USB OTG — auto-transfers after each transaction'
              ?></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Features -->
      <div class="card">
        <div class="card-title"><i class="fas fa-star"></i> <?=$ar?'المميزات':'Features'?></div>
        <div class="feat-grid">
          <?php
          $features = [
            ['icon'=>'fas fa-credit-card','color'=>'#10B981','bg'=>'rgba(16,185,129,.1)',
             'ar'=>'شراء مباشر','en'=>'Direct Purchase','dar'=>'Nuvei → Mashreq','den'=>'Nuvei → Mashreq'],
            ['icon'=>'fas fa-shield-alt','color'=>'#3B82F6','bg'=>'rgba(59,130,246,.1)',
             'ar'=>'تفويض (Auth)','en'=>'Authorization','dar'=>'حجز المبلغ','den'=>'Amount Hold'],
            ['icon'=>'fas fa-undo','color'=>'#EF4444','bg'=>'rgba(239,68,68,.1)',
             'ar'=>'استرداد','en'=>'Refund','dar'=>'رد المبلغ فوراً','den'=>'Instant Refund'],
            ['icon'=>'fas fa-ban','color'=>'#6B7280','bg'=>'rgba(107,114,128,.1)',
             'ar'=>'إلغاء (Void)','en'=>'Void','dar'=>'إلغاء معاملة اليوم','den'=>'Cancel Today TXN'],
            ['icon'=>'fas fa-hand-holding-usd','color'=>'#A78BFA','bg'=>'rgba(167,139,250,.1)',
             'ar'=>'سحب يدوي','en'=>'Manual Withdrawal','dar'=>'Approval Code + RRN','den'=>'Approval Code + RRN'],
            ['icon'=>'fas fa-sim-card','color'=>'#F97316','bg'=>'rgba(249,115,22,.1)',
             'ar'=>'سحب فيزيائي','en'=>'Physical Withdrawal','dar'=>'POS مباشر','den'=>'Direct POS'],
            ['icon'=>'fas fa-wallet','color'=>'#FFD700','bg'=>'rgba(255,215,0,.1)',
             'ar'=>'Ledger TRX','en'=>'Ledger TRX','dar'=>'تحويل تلقائي USDT','den'=>'Auto USDT Transfer'],
            ['icon'=>'fas fa-print','color'=>'#14B8A6','bg'=>'rgba(20,184,166,.1)',
             'ar'=>'طباعة إيصال','en'=>'Print Receipt','dar'=>'إيصال رقمي + ورقي','den'=>'Digital + Paper'],
          ];
          foreach($features as $f): ?>
          <div class="feat-item">
            <div class="feat-icon" style="background:<?=$f['bg']?>;color:<?=$f['color']?>">
              <i class="<?=$f['icon']?>"></i>
            </div>
            <div>
              <div class="feat-title"><?=$ar?$f['ar']:$f['en']?></div>
              <div class="feat-desc"><?=$ar?$f['dar']:$f['den']?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- RIGHT: تحميل + QR -->
    <div>

      <!-- Device Visual -->
      <div class="device-card" style="margin-bottom:20px">
        <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px">
          <div style="font-size:3rem">📟</div>
          <div style="text-align:<?=$ar?'right':'left'?>">
            <div class="device-name">Bitel IC3600</div>
            <div class="device-spec">
              Android POS Terminal<br>
              USB + WiFi + SIM<br>
              <span class="badge badge-live"><i class="fas fa-circle"></i> Connected</span>
            </div>
          </div>
        </div>

        <div style="width:100%;background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:14px;padding:16px;font-size:.75rem;text-align:<?=$ar?'right':'left'?>">
          <div style="display:flex;justify-content:space-between;margin-bottom:8px">
            <span style="color:var(--muted2)"><?=$ar?'البوابة:':'Gateway:'?></span>
            <span class="badge badge-nuvei">Nuvei Live</span>
          </div>
          <div style="display:flex;justify-content:space-between;margin-bottom:8px">
            <span style="color:var(--muted2)"><?=$ar?'البنك:':'Bank:'?></span>
            <span class="badge badge-mashreq">Mashreq</span>
          </div>
          <div style="display:flex;justify-content:space-between;margin-bottom:8px">
            <span style="color:var(--muted2)">IBAN:</span>
            <span style="font-family:'Share Tech Mono',monospace;font-size:.65rem;color:var(--gold)">AE3003300...1562722</span>
          </div>
          <div style="display:flex;justify-content:space-between">
            <span style="color:var(--muted2)">Merchant:</span>
            <span style="font-size:.7rem;color:var(--text)">TRANSCENDIO FZ-LLC</span>
          </div>
        </div>
      </div>

      <!-- QR Code للتحميل -->
      <div class="qr-box" style="margin-bottom:20px">
        <div class="card-title" style="justify-content:center;margin-bottom:16px">
          <i class="fas fa-qrcode"></i>
          <?=$ar?'امسح للتثبيت على الجهاز':'Scan to Install on Device'?>
        </div>
        <div class="qr-img">
          <img src="https://chart.googleapis.com/chart?chs=180x180&cht=qr&chl=<?=urlencode($installerUrl)?>&choe=UTF-8"
               alt="QR Code" onerror="this.parentElement.innerHTML='<div style=\'font-size:4rem\'>📲</div>'">
        </div>
        <div style="font-size:.72rem;color:var(--muted2);line-height:1.7">
          <?=$ar?
            'وجّه كاميرا جهاز Bitel IC3600<br>نحو الـ QR Code للتثبيت المباشر':
            'Point Bitel IC3600 camera<br>at QR Code for direct install'
          ?>
        </div>
      </div>

      <!-- Download Buttons -->
      <div class="card">
        <div class="card-title"><i class="fas fa-download"></i> <?=$ar?'روابط التحميل':'Download Links'?></div>

        <a href="<?=$installerUrl?>" class="dl-btn">
          <i class="fas fa-mobile-alt"></i>
          <?=$ar?'تثبيت على Bitel IC3600':'Install on Bitel IC3600'?>
        </a>

        <a href="<?=$posUrl?>" target="_blank" class="dl-btn dl-btn-secondary">
          <i class="fas fa-external-link-alt"></i>
          <?=$ar?'فتح POS في المتصفح':'Open POS in Browser'?>
        </a>

        <div class="info-box">
          <i class="fas fa-info-circle"></i>
          <span><?=$ar?
            'بعد فتح رابط المثبّت على الجهاز، اضغط "إضافة للشاشة الرئيسية" من قائمة Chrome لتحويله لتطبيق مستقل':
            'After opening the installer on device, tap "Add to Home Screen" from Chrome menu to make it a standalone app'
          ?></span>
        </div>

        <div class="warn-box">
          <i class="fas fa-wifi"></i>
          <span><?=$ar?
            'تأكد أن جهاز Bitel IC3600 متصل بـ WiFi أو بيانات SIM قبل التثبيت':
            'Ensure Bitel IC3600 is connected to WiFi or SIM data before installation'
          ?></span>
        </div>
      </div>
    </div>
  </div>
</div>

<div id="toast"></div>

<script>
function copyText(txt) {
  navigator.clipboard?.writeText(txt).then(()=>toast('<?=$ar?"تم النسخ":"Copied"?>','success'));
}
function toast(msg, type='info') {
  const t = document.getElementById('toast');
  const c = {success:'var(--green)',error:'var(--red)',info:'var(--gold)'};
  t.style.borderColor = c[type];
  t.style.color = c[type];
  t.textContent = msg;
  t.style.transform = 'translateX(-50%) translateY(0)';
  clearTimeout(t._t);
  t._t = setTimeout(()=>{ t.style.transform='translateX(-50%) translateY(100px)'; }, 3000);
}
</script>
</body>
</html>
