<?php
/**
 * POS installer — IC3600 and other models. Local device setup only.
 */
require_once __DIR__ . '/bootstrap.php';

$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'ar' ? 'ar' : 'en';
$ar = ($lang === 'ar');
$dir = $ar ? 'rtl' : 'ltr';
$siteUrl = rtrim((string)SITE_URL, '/');
$defaultModel = 'bitel_ic3600';
$tidDefault = pos_request_terminal_id();
?><!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$dir?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<meta name="theme-color" content="#FFD700">
<meta name="mobile-web-app-capable" content="yes">
<title>DI PARMA POS | Install</title>
<link rel="manifest" href="../pos_manifest.json">
<link rel="icon" href="../assets/icons/logo.svg">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{min-height:100vh;font-family:'Cairo',sans-serif;background:#020508;color:#edf0f7;padding:20px}
.wrap{max-width:460px;margin:0 auto}
h1{color:#FFD700;font-size:1.3rem;margin:8px 0}
p{color:#718096;font-size:.8rem;line-height:1.7;margin-bottom:16px}
.card{background:#090f1e;border:1.5px solid rgba(255,215,0,.12);border-radius:16px;padding:16px;margin-bottom:12px}
label{display:block;font-size:.72rem;color:#FFD700;margin:10px 0 4px}
input,select{width:100%;background:#020508;border:1px solid rgba(255,215,0,.2);color:#edf0f7;border-radius:10px;padding:11px;font-family:inherit}
.btn{width:100%;margin-top:12px;border:0;border-radius:12px;padding:12px;font-weight:800;cursor:pointer;font-family:inherit}
.gold{background:linear-gradient(135deg,#FFD700,#FFB700);color:#111}
.dark{background:#111827;color:#edf0f7;border:1px solid rgba(255,215,0,.15)}
.ok{color:#10B981}.warn{color:#F97316}.err{color:#EF4444}
#log{font-size:.75rem;line-height:1.8;color:#94a3b8}
</style>
</head>
<body>
<div class="wrap">
  <h1>DI PARMA POS</h1>
  <p><?=$ar?'تثبيت على IC3600 أو أي موديل آخر. بعد الشحن: صافي USDT → Ledger فقط.':'Install on IC3600 or another model. After a charge: net USDT → Ledger only.'?></p>

  <div class="card">
    <div style="font-weight:800;margin-bottom:8px"><?=$ar?'الجهاز':'Device'?></div>
    <label><?=$ar?'الموديل':'Model'?></label>
    <select id="device">
      <?=pos_device_select_options((string) $defaultModel, $ar)?>
    </select>
    <label>Terminal ID</label>
    <input id="tid" value="<?=htmlspecialchars($tidDefault)?>" maxlength="16">
    <div id="log" style="margin-top:10px"></div>
    <button class="btn dark" type="button" onclick="checkDevice()"><?=$ar?'فحص الجهاز والسيرفر':'Check device and server'?></button>
    <button class="btn gold" type="button" onclick="openPos()"><?=$ar?'فتح POS ودخول الجهاز':'Open POS and bind device'?></button>
    <button class="btn dark" type="button" id="pwaBtn" onclick="installPwa()"><?=$ar?'تثبيت على الشاشة الرئيسية':'Add to home screen'?></button>
  </div>
</div>
<script>
const AR = <?=$ar?'true':'false'?>;
const SITE = <?=json_encode($siteUrl)?>;
let deferred = null;
window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); deferred = e; });

function qs() {
  const device = document.getElementById('device').value;
  const tid = (document.getElementById('tid').value || '').toUpperCase().replace(/[^A-Z0-9\-]/g, '');
  let q = 'kiosk=1&device=' + encodeURIComponent(device);
  if (tid) {
    q += '&tid=' + encodeURIComponent(tid);
  }
  return q;
}
function line(cls, text) {
  const el = document.getElementById('log');
  el.innerHTML += '<div class="' + cls + '">' + text + '</div>';
}
async function checkDevice() {
  const log = document.getElementById('log');
  log.innerHTML = '';
  const ua = navigator.userAgent;
  const android = /android/i.test(ua);
  const ic3600 = /ic3600|bitel/i.test(ua);
  line(ic3600 ? 'ok' : (android ? 'ok' : 'warn'), ic3600 ? 'Bitel IC3600' : (android ? 'Android POS' : (AR ? 'متصفح — يصلح للإعداد' : 'Browser — OK for setup')));
  line(navigator.onLine ? 'ok' : 'err', navigator.onLine ? (AR ? 'الإنترنت متصل' : 'Online') : (AR ? 'لا إنترنت' : 'Offline'));
  line(location.protocol === 'https:' || location.hostname === 'localhost' ? 'ok' : 'warn', location.protocol + '//' + location.host);
  try {
    await fetch(SITE + '/pos/api/transaction.php', { method: 'HEAD', signal: AbortSignal.timeout(6000) });
    line('ok', AR ? 'سيرفر POS يصل' : 'POS server reachable');
  } catch (e) {
    line('err', AR ? 'السيرفر لا يرد' : 'POS server not reachable');
  }
}
function openPos() {
  location.href = 'login.php?' + qs();
}
async function installPwa() {
  if (deferred) {
    deferred.prompt();
    await deferred.userChoice;
    deferred = null;
    return;
  }
  alert(AR ? 'من Chrome: القائمة → إضافة إلى الشاشة الرئيسية' : 'Chrome menu → Add to Home screen');
}
</script>
</body>
</html>
