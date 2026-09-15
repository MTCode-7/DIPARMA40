<?php
/**
 * ============================================================
 * DI PARMA | POS Terminal — نقطة البيع الكاملة
 * ============================================================
 */
require_once __DIR__ . '/bootstrap.php';
if (!function_exists('activity_operations')) {
    require_once POS_APP_ROOT . '/includes/activity_flow.php';
}
pos_require_operator();

$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'ar' ? 'ar' : 'en';
$ar   = ($lang === 'ar');
$dir  = $ar ? 'rtl' : 'ltr';
$csrf = generateCsrfToken();
$catalogMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pos_catalog'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $catalogMsg = $ar ? 'رمز الأمان غير صالح' : 'Invalid security token';
    } else {
        $kind = (string) $_POST['pos_catalog'];
        $result = ['success' => false];
        if ($kind === 'add_tid') {
            $result = pos_custom_add_tid((string) ($_POST['new_tid'] ?? ''), (string) ($_POST['new_tid_model'] ?? ''));
        } elseif ($kind === 'add_device') {
            $result = pos_custom_add_device(
                (string) ($_POST['new_brand'] ?? ''),
                (string) ($_POST['new_model_name'] ?? ''),
                (string) ($_POST['new_device_type'] ?? 'android_smart_pos'),
                (string) ($_POST['new_device_region'] ?? 'global')
            );
        } elseif ($kind === 'add_activity') {
            $result = pos_custom_add_activity(
                (string) ($_POST['new_activity_en'] ?? ''),
                (string) ($_POST['new_activity_ar'] ?? ''),
                (string) ($_POST['new_activity_mcc'] ?? ''),
                (string) ($_POST['new_activity_gw'] ?? '')
            );
        }
        if (!empty($result['success'])) {
            $next = [
                'kiosk' => '1',
                'device' => $result['model'] ?? (string) ($_POST['device'] ?? $_GET['device'] ?? 'bitel_ic3600'),
                'tid' => $result['tid'] ?? (string) ($_POST['tid'] ?? $_GET['tid'] ?? pos_default_terminal_id()),
                'line' => $result['line'] ?? (string) ($_POST['line'] ?? $_GET['line'] ?? 'hajj'),
            ];
            $dest = function_exists('pos_url')
                ? pos_url('index.php', $next)
                : ('/pos/index.php?' . http_build_query($next));
            header('Location: ' . $dest);
            exit;
        }
        $catalogMsg = (string) ($result['message'] ?? ($ar ? 'تعذر الإضافة' : 'Could not add'));
    }
}
$chosenModel = trim((string)($_GET['device'] ?? $_COOKIE['di_parma_pos_model'] ?? 'bitel_ic3600'));
$resolvedPick = pos_device_get($chosenModel);
$chosenModel = (string)($resolvedPick['model'] ?? 'generic_pos');
$posDevice = pos_device_resolve([
    'pos_model' => $chosenModel,
    'terminal_id' => (string)($_GET['tid'] ?? $_SESSION['pos_terminal_id'] ?? $_COOKIE['di_parma_pos_tid'] ?? pos_default_terminal_id()),
]);
$isVerix = pos_device_is_verix($posDevice);
$verixCommission = pos_device_commission($posDevice);
$posGw = pos_normalize_gateway((string)($_GET['gw'] ?? ''));
if ($posGw !== '' && !pos_gateway_is_live($posGw)) {
    $posGw = '';
}
$posMerchant = pos_merchant_profile((string)($_GET['line'] ?? $_COOKIE['di_parma_pos_line'] ?? 'hajj'));
$canonDevice = (string)($posDevice['model'] ?? 'generic_pos');
$canonQs = array_filter([
    'kiosk' => '1',
    'device' => $canonDevice,
    'tid' => $posDevice['terminal_id'],
    'gw' => $posGw,
    'line' => $posMerchant['line'],
    'op' => (string)($_GET['op'] ?? ''),
    'mode' => (string)($_GET['mode'] ?? ''),
    'arrival' => (string)($_GET['arrival'] ?? ''),
    'payout' => (string)($_GET['payout'] ?? ''),
]);
if ((string)($_GET['device'] ?? '') !== $canonDevice || (string)($_GET['kiosk'] ?? '') !== '1' || (string)($_GET['line'] ?? '') === '') {
    $dest = function_exists('pos_url')
        ? pos_url('index.php', $canonQs)
        : ('/pos/index.php?' . http_build_query($canonQs));
    header('Location: ' . $dest);
    exit;
}
setcookie('di_parma_pos_line', $posMerchant['line'], time() + (365 * 24 * 3600), '/');
$_COOKIE['di_parma_pos_line'] = $posMerchant['line'];
if (!empty($_GET['device']) && pos_device_get((string)$_GET['device'])) {
    setcookie('di_parma_pos_model', $posDevice['model'], time() + (365 * 24 * 3600), '/');
}
setcookie('di_parma_pos_tid', $posDevice['terminal_id'], time() + (365 * 24 * 3600), '/');
$_COOKIE['di_parma_pos_tid'] = $posDevice['terminal_id'];
if (empty($_COOKIE['di_parma_pos_model']) || !empty($_GET['device'])) {
    setcookie('di_parma_pos_model', $posDevice['model'], time() + (365 * 24 * 3600), '/');
    $_COOKIE['di_parma_pos_model'] = $posDevice['model'];
}
if (!empty($_SESSION['user_id'])) {
    pos_issue_device_token((int)$_SESSION['user_id'], $posDevice['terminal_id'], $posDevice['model']);
}
$kioskParam = $_GET['kiosk'] ?? null;
if ($kioskParam === '1') {
    $kiosk = true;
} elseif ($kioskParam === '0') {
    $kiosk = false;
} else {
    $kiosk = !empty($posDevice['kiosk']);
}
$posQs = [];
if ($kiosk) {
    $posQs['kiosk'] = '1';
}
$posQs['device'] = $posDevice['model'];
$posQs['tid'] = $posDevice['terminal_id'];
$posQs['line'] = $posMerchant['line'];
$posQuery = http_build_query($posQs);
$posGwMeta = $posGw !== '' ? pos_gateway_meta($posGw) : null;
$posHub = (string)($_GET['op'] ?? '') === '' || (string)($_GET['mode'] ?? '') === '';
if ($isVerix) {
    $posHub = $posHub || $posGw === '' || !$posGwMeta;
}
$isLedgerGw = false;

// أنواع العمليات المعيارية
$txnTypes = pos_operation_catalog();
$startOp = pos_normalize_operation((string)($_GET['op'] ?? 'purchase_3d'));
if (!isset($txnTypes[$startOp])) {
    $startOp = 'purchase_3d';
}
$startMode = strtolower(trim((string)($_GET['mode'] ?? 'manual')));
if (!in_array($startMode, ['manual', 'physical'], true)) {
    $startMode = 'manual';
}
$activityLines = pos_merchant_lines();
$activityOps = activity_operations();
$cardPresentModes = activity_card_present_modes();
$arrivalOptions = activity_arrival_options();
$payoutRails = activity_payout_rails();
$startArrival = activity_normalize_arrival((string) ($_GET['arrival'] ?? 'wallet'));
$startPayout = activity_normalize_payout_rail((string) ($_GET['payout'] ?? ''));
$activitySuggest = [];
foreach ($activityLines as $lk => $lr) {
    $activitySuggest[$lk] = strtolower(trim((string)($lr['suggested_gateway'] ?? '')));
}
$ledgerAddr = activity_ledger_address();
$liveGwsPos = activity_connected_gateways('pos');
$liveGwsLink = activity_connected_gateways('link');
$execGws = $liveGwsPos;
if ($isVerix) {
    $execGws = [];
    if (isset($liveGwsPos['nuvei'])) {
        $execGws['nuvei'] = $liveGwsPos['nuvei'];
    }
}
foreach ($execGws as $gwCode => &$gwMetaRow) {
    $gwMetaRow['requires_card'] = pos_gateway_requires_card((string) $gwCode);
}
unset($gwMetaRow);
$verifoneHost = rtrim((string) SITE_URL, '/') . '/pos/api/verifone.php';
$verifoneSdk = is_array($posDevice['sdk_urls'] ?? null) ? $posDevice['sdk_urls'] : [];
$verixReady = true;
$squareSdk = ['ready' => false, 'application_id' => '', 'location_id' => '', 'script_url' => '', 'live' => false];
require_once POS_APP_ROOT . '/includes/square_sdk.php';
$squareSdk = square_sdk_config();
$hasSquareSdk = !empty($squareSdk['application_id']) && !empty($squareSdk['location_id']);
$currencies = ['USD','AED','SAR','EUR','GBP','KWD','BHD','QAR','OMR','EGP','USDT'];
$linkedWallets = [];
try {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid > 0) {
        $linkedWallets = db()->query(
            "SELECT provider, network, address, status FROM " . DB_PREFIX . "linked_wallets
             WHERE user_id=? AND status='active'",
            [$uid]
        ) ?: [];
    }
} catch (Throwable $e) {
    $linkedWallets = [];
}
?><!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$dir?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | POS <?=htmlspecialchars($posDevice['label'])?></title>
<meta name="theme-color" content="#FFD700">
<link rel="manifest" href="../pos_manifest.json">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&family=Share+Tech+Mono&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<?php if (!empty($hasSquareSdk)): ?>
<script type="text/javascript" src="<?=htmlspecialchars($squareSdk['script_url'])?>"></script>
<script src="../assets/js/square_web_payments.js"></script>
<?php endif; ?>
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
.tb-nav{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.tb-nav a{color:var(--muted2);font-size:.78rem;padding:6px 12px;
  border-radius:18px;text-decoration:none;transition:.2s}
.tb-nav a:hover{color:var(--gold)}
.tb-dash{display:inline-flex;align-items:center;gap:7px;padding:8px 16px;
  border-radius:20px;border:1.5px solid rgba(255,215,0,.4);color:var(--gold)!important;
  font-weight:800;font-size:.78rem;background:rgba(255,215,0,.08)}
.tb-dash:hover{background:rgba(255,215,0,.16);color:#ffe066!important}
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
.hidden{display:none!important}
.mode-row{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px}
.mode-btn{background:rgba(255,255,255,.04);border:1.5px solid var(--border);border-radius:12px;
  padding:12px 10px;cursor:pointer;text-align:center;color:var(--muted2);font-family:'Cairo',sans-serif}
.mode-btn strong{display:block;color:var(--text);font-size:.84rem;margin-bottom:3px}
.mode-btn span{font-size:.66rem;line-height:1.4}
.mode-btn.active{border-color:var(--gold);background:rgba(255,215,0,.08);color:var(--gold)}
.phys-wait{background:rgba(16,185,129,.06);border:1px dashed rgba(16,185,129,.4);border-radius:12px;
  padding:16px;text-align:center;margin-bottom:12px}
/* Responsive */
.txn-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}
@media(max-width:1100px){.layout{grid-template-columns:260px 1fr 260px}}
@media(max-width:900px){
  .layout{grid-template-columns:1fr}
  .left-panel{display:none}
  .right-panel{display:block}
}
</style>

<nav class="topbar">
  <div class="tb-brand">
    <a href="../dashboard.php" style="color:inherit;text-decoration:none;display:flex;align-items:center;gap:10px">
      <i class="fas fa-coins"></i> DI PARMA
    </a>
    <span style="color:var(--muted)">|</span>
    <div class="tb-badge"><i class="fas fa-cash-register"></i> <?= $posHub ? htmlspecialchars($posDevice['label']) : ('POS · ' . htmlspecialchars($posGwMeta['name'] ?? ($ar ? 'اختر البوابة' : 'Choose gateway')) . ' → Ledger') ?></div>
    <div class="tb-badge" style="margin-inline-start:8px"><?=htmlspecialchars($posDevice['terminal_id'])?></div>
  </div>
  <div class="tb-nav">
    <?php if (!$kiosk): ?>
    <a href="../ledger/"><i class="fas fa-wallet"></i> Ledger</a>
    <?php endif; ?>
    <a class="tb-dash" href="../dashboard.php"><i class="fas fa-arrow-right" style="transform:<?=$ar?'':'scaleX(-1)'?>"></i> <?=$ar?'عودة للوحة التحكم':'Back to Dashboard'?></a>
  </div>
</nav>

<?php if ($posHub): ?>
<?php $hubGwsPos = $liveGwsPos; ?>
<div style="max-width:1100px;margin:40px auto;padding:0 24px 60px">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:8px">
    <h1 style="font-size:1.4rem;font-weight:900;color:var(--gold);margin:0">
      POS
    </h1>
    <a class="tb-dash" href="../dashboard.php"><i class="fas fa-th-large"></i> <?=$ar?'عودة للوحة التحكم':'Back to Dashboard'?></a>
  </div>
  <p style="color:var(--muted2);font-size:.82rem;margin-bottom:18px;line-height:1.7">
    <?=$ar
      ? 'كل الأجهزة مقبولة. 1 النشاط — 2 البوابة — 3 نوع الشراء — 4 تمرير البطاقة أو بدون تمرير. المبلغ يصل لـ Ledger.'
      : 'All terminals are accepted. 1 Activity — 2 Gateway — 3 Purchase type — 4 Card present or keyed. Amount arrives at Ledger.'?>
  </p>
  <div style="background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.25);border-radius:14px;padding:12px 14px;margin-bottom:22px;font-size:.78rem;line-height:1.6;color:var(--muted2)">
    <span style="color:var(--green);font-weight:800">LEDGER</span>
    <span style="font-family:monospace;margin-inline-start:8px;color:var(--text);word-break:break-all"><?=htmlspecialchars($ledgerAddr !== '' ? $ledgerAddr : ($ar ? 'غير مضبوط — LEDGER_TRC20_ADDRESS' : 'Not set — LEDGER_TRC20_ADDRESS'))?></span>
  </div>

  <div class="panel-title"><?=$ar?'أجهزة شركاتي':'My company terminals'?></div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:8px;margin-bottom:18px">
    <?php foreach (pos_company_terminals() as $unit):
        $unitTid = trim((string) ($unit['tid'] ?? ''));
        if ($unitTid !== '' && function_exists('pos_normalize_terminal_id')) {
            $unitTid = pos_normalize_terminal_id($unitTid);
        } else {
            $unitTid = strtoupper($unitTid);
        }
        $q = $posQs;
        $q['device'] = $unit['model'];
        if ($unitTid !== '') {
            $q['tid'] = $unitTid;
        }
        $href = '?' . http_build_query($q);
        $active = ($posDevice['model'] === $unit['model'] && ($unitTid === '' || (string) $posDevice['terminal_id'] === $unitTid));
    ?>
    <a href="<?=htmlspecialchars($href)?>" style="text-decoration:none;background:var(--card);border:1.5px solid <?=!empty($active)?'var(--gold)':'var(--border)'?>;border-radius:14px;padding:12px;display:block">
      <div style="font-weight:800;color:var(--text);font-size:.82rem"><?=htmlspecialchars($unit['brand'].' '.$unit['name'])?></div>
      <div style="font-family:monospace;font-size:.72rem;color:var(--gold);margin-top:6px"><?=htmlspecialchars($unitTid !== '' ? $unitTid : ($ar ? 'بدون TID — أضفه' : 'No TID — add it'))?></div>
      <?php if (!empty($unit['note'])): ?>
      <div style="font-size:.62rem;color:var(--muted2);margin-top:4px"><?=htmlspecialchars($unit['note'])?></div>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>
  <form method="get" id="hubDeviceForm" style="background:var(--card);border:1.5px solid var(--border);border-radius:16px;padding:16px;margin-bottom:14px;display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;align-items:end">
    <input type="hidden" name="kiosk" value="<?=$kiosk?'1':'0'?>">
    <div class="fld" style="margin:0">
      <label><?=$ar?'النوع / الموديل':'Type / model'?></label>
      <select name="device" onchange="this.form.submit()" size="1" style="max-width:100%">
        <?=pos_device_select_options((string) $posDevice['model'], $ar)?>
      </select>
    </div>
    <div class="fld" style="margin:0">
      <label>TID</label>
      <select name="tid" onchange="hubTidChange(this)">
        <?php
          $tidNow = (string) $posDevice['terminal_id'];
          $tidRecords = function_exists('pos_tid_records') ? pos_tid_records() : [];
          $tidList = function_exists('pos_saved_tids') ? pos_saved_tids() : [$tidNow];
          if ($tidNow !== '' && !in_array($tidNow, $tidList, true)) {
              array_unshift($tidList, $tidNow);
          }
          foreach ($tidList as $tidOpt):
              $rec = $tidRecords[$tidOpt] ?? null;
              $lab = $rec['label'] ?? $tidOpt;
        ?>
        <option value="<?=htmlspecialchars($tidOpt)?>" data-model="<?=htmlspecialchars($rec['model'] ?? '')?>" <?=$tidNow===$tidOpt?'selected':''?>><?=htmlspecialchars($lab)?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>

  <?php if ($catalogMsg !== ''): ?>
  <div style="margin-bottom:14px;color:#f87171;font-size:.8rem"><?=htmlspecialchars($catalogMsg)?></div>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px;margin-bottom:22px">
    <form method="post" style="background:var(--card);border:1.5px dashed var(--border);border-radius:16px;padding:14px">
      <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
      <input type="hidden" name="pos_catalog" value="add_tid">
      <input type="hidden" name="device" value="<?=htmlspecialchars($posDevice['model'])?>">
      <input type="hidden" name="tid" value="<?=htmlspecialchars($posDevice['terminal_id'])?>">
      <input type="hidden" name="line" value="<?=htmlspecialchars($posMerchant['line'])?>">
      <div class="panel-title" style="margin-bottom:8px"><?=$ar?'إضافة TID جديد':'Add new TID'?></div>
      <div class="fld" style="margin:0 0 8px">
        <label>TID</label>
        <input type="text" name="new_tid" maxlength="16" placeholder="16526257" required>
      </div>
      <div class="fld" style="margin:0 0 8px">
        <label><?=$ar?'الجهاز المرتبط':'Linked device'?></label>
        <select name="new_tid_model">
          <option value=""><?=$ar?'— اختياري —':'— optional —'?></option>
          <?=function_exists('pos_device_select_options') ? pos_device_select_options((string) $posDevice['model'], $ar) : ''?>
        </select>
      </div>
      <button type="submit" class="btn btn-gold btn-sm btn-full" style="width:100%;padding:8px;border:0;border-radius:10px;cursor:pointer;font-weight:800"><?=$ar?'حفظ TID':'Save TID'?></button>
    </form>

    <form method="post" style="background:var(--card);border:1.5px dashed var(--border);border-radius:16px;padding:14px">
      <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
      <input type="hidden" name="pos_catalog" value="add_device">
      <input type="hidden" name="device" value="<?=htmlspecialchars($posDevice['model'])?>">
      <input type="hidden" name="tid" value="<?=htmlspecialchars($posDevice['terminal_id'])?>">
      <input type="hidden" name="line" value="<?=htmlspecialchars($posMerchant['line'])?>">
      <div class="panel-title" style="margin-bottom:8px"><?=$ar?'إضافة موديل / تايب جديد':'Add new model / type'?></div>
      <div class="fld" style="margin:0 0 8px">
        <label><?=$ar?'الشركة / البراند':'Brand'?></label>
        <input type="text" name="new_brand" maxlength="40" placeholder="Bitel" required>
      </div>
      <div class="fld" style="margin:0 0 8px">
        <label><?=$ar?'الموديل':'Model'?></label>
        <input type="text" name="new_model_name" maxlength="40" placeholder="IC4000" required>
      </div>
      <div class="fld" style="margin:0 0 8px">
        <label><?=$ar?'النوع':'Type'?></label>
        <select name="new_device_type">
          <?php foreach (pos_device_types() as $typeKey => $typeLab): ?>
          <option value="<?=htmlspecialchars($typeKey)?>"><?=htmlspecialchars($ar ? $typeLab['ar'] : $typeLab['en'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fld" style="margin:0 0 8px">
        <label><?=$ar?'المنطقة':'Region'?></label>
        <select name="new_device_region">
          <?php foreach (pos_device_regions() as $regKey => $regLab): ?>
          <option value="<?=htmlspecialchars($regKey)?>"><?=htmlspecialchars($ar ? $regLab['ar'] : $regLab['en'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-gold btn-sm btn-full" style="width:100%;padding:8px;border:0;border-radius:10px;cursor:pointer;font-weight:800"><?=$ar?'حفظ الجهاز':'Save device'?></button>
    </form>

    <form method="post" style="background:var(--card);border:1.5px dashed var(--border);border-radius:16px;padding:14px">
      <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
      <input type="hidden" name="pos_catalog" value="add_activity">
      <input type="hidden" name="device" value="<?=htmlspecialchars($posDevice['model'])?>">
      <input type="hidden" name="tid" value="<?=htmlspecialchars($posDevice['terminal_id'])?>">
      <input type="hidden" name="line" value="<?=htmlspecialchars($posMerchant['line'])?>">
      <div class="panel-title" style="margin-bottom:8px"><?=$ar?'إضافة نشاط جديد':'Add new activity'?></div>
      <div class="fld" style="margin:0 0 8px">
        <label><?=$ar?'الاسم بالإنجليزي':'English name'?></label>
        <input type="text" name="new_activity_en" maxlength="80" placeholder="Retail" required>
      </div>
      <div class="fld" style="margin:0 0 8px">
        <label><?=$ar?'الاسم بالعربي':'Arabic name'?></label>
        <input type="text" name="new_activity_ar" maxlength="80" placeholder="تجزئة">
      </div>
      <div class="fld" style="margin:0 0 8px">
        <label>MCC</label>
        <input type="text" name="new_activity_mcc" maxlength="4" placeholder="5999">
      </div>
      <button type="submit" class="btn btn-gold btn-sm btn-full" style="width:100%;padding:8px;border:0;border-radius:10px;cursor:pointer;font-weight:800"><?=$ar?'حفظ النشاط':'Save activity'?></button>
    </form>
  </div>
  <?php if ($isVerix): ?>
  <?php
    $appOn = !empty($verixCommission['payment_app_installed']);
    $keysOn = !empty($verixCommission['keys_injected']);
  ?>
  <div style="background:rgba(255,215,0,.08);border:1px solid rgba(255,215,0,.28);border-radius:14px;padding:14px 16px;margin-bottom:22px;font-size:.78rem;line-height:1.7;color:var(--muted2)">
    <div style="color:var(--gold);font-weight:900;margin-bottom:6px">Verifone VX 675 · Verix V · Nuvei only</div>
    <div><?=$ar
      ? 'الجهاز ليس أندرويد. نظام Verix V. تطبيق الدفع: Nuvei Payment App. المفاتيح تُحقن من Nuvei (RKI/KIF) داخل HSM الجهاز — ليست في DIPARMA. بعد التفويض على الجهاز يُسجَّل الناتج ثم USDT → Ledger.'
      : 'Not Android. Verix V OS. Payment App: Nuvei. Keys are injected by Nuvei (RKI/KIF) into the terminal HSM — never stored in DIPARMA. After on-device authorization, the result is recorded and USDT → Ledger.'?></div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px;margin-top:12px">
      <div style="border:1px solid var(--border);border-radius:10px;padding:8px 10px">Verix V · <?=$ar?'نعم':'Yes'?></div>
      <div style="border:1px solid var(--border);border-radius:10px;padding:8px 10px;color:<?=$appOn?'var(--green)':'#f87171'?>"><?=$ar?'تطبيق الدفع':'Payment App'?> · <?=$appOn?($ar?'مثبّت':'installed'):($ar?'غير مثبّت':'missing')?></div>
      <div style="border:1px solid var(--border);border-radius:10px;padding:8px 10px;color:<?=$keysOn?'var(--green)':'#f87171'?>"><?=$ar?'مفاتيح Nuvei':'Nuvei keys'?> · <?=$keysOn?($ar?'محقونة':'injected'):($ar?'غير محقونة':'not injected')?></div>
      <div style="border:1px solid var(--border);border-radius:10px;padding:8px 10px">Nuvei · <?=$ar?'فقط':'only'?></div>
    </div>
    <?php if (!$verixReady): ?>
    <div style="margin-top:10px;color:#f87171">
      <?=$ar
        ? 'بعد تثبيت Nuvei Payment App وحقن المفاتيح من Nuvei ضع NUVEI_POS_PAYMENT_APP=1 و NUVEI_POS_KEYS_INJECTED=1'
        : 'After Nuvei installs the Payment App and injects keys, set NUVEI_POS_PAYMENT_APP=1 and NUVEI_POS_KEYS_INJECTED=1'?>
    </div>
    <?php endif; ?>
    <div style="margin-top:10px;font-family:monospace;color:var(--text);word-break:break-all"><?=htmlspecialchars($verifoneHost)?></div>
    <div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:10px">
      <?php foreach ($verifoneSdk as $sdkKey => $sdkUrl): ?>
      <a href="<?=htmlspecialchars($sdkUrl)?>" target="_blank" rel="noopener" style="color:var(--gold)"><?=htmlspecialchars($sdkKey)?></a>
      <?php endforeach; ?>
    </div>
    <pre id="verixPayload" style="margin-top:12px;background:rgba(0,0,0,.25);border-radius:10px;padding:10px;overflow:auto;color:var(--text);font-size:.68rem;white-space:pre-wrap"></pre>
  </div>
  <?php endif; ?>

  <div class="panel-title">1 · <?=$ar?'اختر النشاط':'Choose activity'?></div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;margin-bottom:22px" id="hubLines">
    <?php foreach ($activityLines as $lineKey => $lineRow): ?>
    <button type="button" class="txn-btn" data-line="<?=htmlspecialchars($lineKey)?>" data-suggest="<?=htmlspecialchars($lineRow['suggested_gateway'] ?? '')?>" onclick="hubPick('line','<?=htmlspecialchars($lineKey)?>',this)" style="margin:0;flex-direction:column;padding:14px 10px;gap:6px;text-align:center">
      <span style="font-weight:800;color:var(--text)"><?=$ar?$lineRow['ar']:$lineRow['en']?></span>
      <span style="font-size:.62rem;color:var(--muted2)">MCC <?=$lineRow['mcc']?></span>
    </button>
    <?php endforeach; ?>
  </div>

  <div class="panel-title">2 · <?=$ar?'بوابة مقترحة (الاختيار النهائي عند التنفيذ)':'Suggested gateway (final choice at charge)'?></div>
  <div id="hubGwEmpty" style="border:1px solid var(--border);border-radius:14px;padding:18px;background:var(--card);margin-bottom:22px;<?=empty($hubGwsPos)?'':'display:none'?>">
    <div style="font-weight:800;margin-bottom:8px;color:var(--gold)"><?=$ar?'لا توجد بوابة مفعّلة':'No enabled gateway'?></div>
    <a href="../admin/gateway_manager.php" style="color:var(--gold);font-size:.8rem"><?=$ar?'فتح إدارة البوابات':'Open Gateway Manager'?></a>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:22px" id="hubGws"></div>

  <div class="panel-title">3 · <?=$ar?'اختر نوع الشراء':'Choose purchase type'?></div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px;margin-bottom:22px" id="hubOps">
    <?php foreach ($activityOps as $opKey => $op): ?>
    <button type="button" class="txn-btn" data-op="<?=htmlspecialchars($op['pos_key'])?>" onclick="hubPick('op','<?=htmlspecialchars($op['pos_key'])?>',this)" style="margin:0;flex-direction:column;text-align:center;padding:10px 6px;gap:6px">
      <div class="t-icon" style="background:<?=$op['color']?>22;color:<?=$op['color']?>;margin:0 auto"><i class="fas <?=$op['icon']?>"></i></div>
      <span style="font-size:.68rem;line-height:1.25"><?=$ar?$op['ar']:$op['en']?></span>
    </button>
    <?php endforeach; ?>
  </div>

  <div class="panel-title">4 · <?=$ar?'طريقة الدفع':'Payment method'?></div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:22px" id="hubModes">
    <?php foreach ($cardPresentModes as $modeKey => $mode): ?>
    <button type="button" class="txn-btn" data-mode="<?=htmlspecialchars($modeKey)?>" onclick="hubPick('mode','<?=htmlspecialchars($modeKey)?>',this)" style="margin:0;padding:16px;gap:12px">
      <div class="t-icon" style="background:<?=$mode['color']?>22;color:<?=$mode['color']?>"><i class="fas <?=$mode['icon']?>"></i></div>
      <div>
        <div style="font-weight:800;color:var(--text)"><?=$ar?$mode['ar']:$mode['en']?></div>
        <div style="font-size:.68rem;color:var(--muted2);margin-top:4px;line-height:1.5"><?=$ar?$mode['desc_ar']:$mode['desc_en']?></div>
      </div>
    </button>
    <?php endforeach; ?>
  </div>

  <div class="panel-title">5 · <?=$ar?'وصول المبلغ':'Where funds arrive'?></div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px" id="hubArrival">
    <?php foreach ($arrivalOptions as $arrKey => $arr): ?>
    <button type="button" class="txn-btn" data-arrival="<?=htmlspecialchars($arrKey)?>" onclick="hubPickArrival('<?=htmlspecialchars($arrKey)?>',this)" style="margin:0;padding:16px;gap:12px">
      <div class="t-icon" style="background:<?=$arr['color']?>22;color:<?=$arr['color']?>"><i class="fas <?=$arr['icon']?>"></i></div>
      <div>
        <div style="font-weight:800;color:var(--text)"><?=$ar?$arr['ar']:$arr['en']?><?=!empty($arr['preferred'])?' ★':''?></div>
        <div style="font-size:.68rem;color:var(--muted2);margin-top:4px;line-height:1.5"><?=$ar?$arr['desc_ar']:$arr['desc_en']?></div>
      </div>
    </button>
    <?php endforeach; ?>
  </div>
  <div id="hubPayoutWrap" style="display:none;margin-bottom:22px">
    <div style="font-size:.72rem;color:var(--gold);font-weight:800;margin-bottom:8px"><?=$ar?'اختر بوابة أو بنكاً لتحويل الصافي إلى Ledger':'Pick a gateway or bank to move the net to Ledger'?></div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px" id="hubPayouts">
      <?php foreach ($payoutRails as $railKey => $rail): ?>
      <button type="button" class="txn-btn" data-payout="<?=htmlspecialchars($railKey)?>" onclick="hubPick('payout','<?=htmlspecialchars($railKey)?>',this)" style="margin:0;flex-direction:column;padding:12px;text-align:center;gap:6px">
        <div class="t-icon" style="background:<?=$rail['color']?>22;color:<?=$rail['color']?>;margin:0 auto"><i class="fas <?=$rail['icon']?>"></i></div>
        <span style="font-size:.72rem;font-weight:800"><?=$ar?$rail['ar']:$rail['en']?></span>
        <span style="font-size:.58rem;color:var(--muted2)"><?=$rail['kind']==='bank'?($ar?'بنك':'Bank'):($ar?'بوابة':'Gateway')?></span>
      </button>
      <?php endforeach; ?>
    </div>
  </div>

  <button type="button" class="key-btn key-enter" id="hubGo" onclick="hubGo()" disabled style="width:100%;height:48px;border-radius:14px;font-size:1rem">
    <?=$ar?'فتح POS':'Open POS'?>
  </button>
</div>
<script>
const HUB = { line:'', gw:'', op:'', mode:'', arrival:'wallet', payout:'' };
const HUB_GWS = <?=json_encode($hubGwsPos, JSON_UNESCAPED_UNICODE)?>;
const HUB_SUGGEST = <?=json_encode($activitySuggest, JSON_UNESCAPED_UNICODE)?>;
const HUB_LEDGER = <?=json_encode($ledgerAddr)?>;
const HUB_AR = <?=$ar?'true':'false'?>;
const HUB_QS = <?=json_encode($posQuery)?>;
const HUB_VERIX = <?= $isVerix ? 'true' : 'false' ?>;
const HUB_HOST = <?=json_encode($verifoneHost)?>;
const HUB_TID = <?=json_encode($posDevice['terminal_id'])?>;
const HUB_READY = true;

function hubPick(field, value, el) {
  HUB[field] = value;
  const wrap = el.parentElement;
  wrap.querySelectorAll('.txn-btn').forEach(b => b.classList.remove('active'));
  el.classList.add('active');
  if (field === 'line') hubMarkSuggest();
  hubReady();
}
function hubPickArrival(value, el) {
  hubPick('arrival', value, el);
  const wrap = document.getElementById('hubPayoutWrap');
  if (wrap) wrap.style.display = value === 'payout' ? '' : 'none';
  if (value !== 'payout') HUB.payout = '';
}
function hubMarkSuggest() {
  const want = (HUB_SUGGEST[HUB.line] || '').toLowerCase();
  document.querySelectorAll('#hubGws .txn-btn').forEach(b => {
    const code = (b.dataset.gw || '').toLowerCase();
    b.style.borderColor = (want && code === want) ? 'var(--gold)' : '';
  });
}
function hubRenderGws() {
  const box = document.getElementById('hubGws');
  const list = HUB_GWS || {};
  const codes = Object.keys(list);
  document.getElementById('hubGwEmpty').style.display = codes.length ? 'none' : '';
  box.innerHTML = '';
  codes.forEach(code => {
    const g = list[code];
    const a = document.createElement('button');
    a.type = 'button';
    a.className = 'txn-btn';
    a.dataset.gw = code;
    a.style.cssText = 'margin:0;flex-direction:column;padding:16px;text-align:start;gap:8px';
    a.innerHTML = '<div class="t-icon" style="background:'+g.color+'22;color:'+g.color+'"><i class="'+g.icon+'"></i></div><div style="font-weight:800;color:var(--text)">'+g.name+'</div><div style="font-size:.68rem;color:var(--muted2);line-height:1.5">'+(HUB_AR?(g.desc_ar||''):(g.desc_en||''))+'</div>';
    a.onclick = function(){ hubPick('gw', code, a); };
    box.appendChild(a);
  });
}
function hubReady() {
  const arrivalOk = HUB.arrival === 'wallet' || (HUB.arrival === 'payout' && !!HUB.payout);
  const ok = !!(HUB.line && HUB.op && HUB.mode && arrivalOk);
  document.getElementById('hubGo').disabled = !ok;
  hubFillVerix();
}
function hubFillVerix() {
  const box = document.getElementById('verixPayload');
  if (!box || !HUB_VERIX) return;
  const entry = HUB.mode === 'physical' ? 'chip' : 'keyed';
  const body = {
    gateway: HUB.gw || 'nuvei',
    line: HUB.line || '',
    txn_type: HUB.op || 'purchase_2d',
    tid: HUB_TID,
    amount: '0.00',
    currency: 'USD',
    entry_mode: entry,
    pos_model: 'verifone_vx675',
    approval_code: '',
    rrn: '',
    nuvei_txn_id: ''
  };
  box.textContent = 'POST ' + HUB_HOST + '\n' + JSON.stringify(body, null, 2);
}
function hubTidChange(sel) {
  const opt = sel.options[sel.selectedIndex];
  const model = opt ? (opt.getAttribute('data-model') || '') : '';
  const f = document.getElementById('hubDeviceForm');
  if (f && model && f.device) {
    f.device.value = model;
  }
  sel.form.submit();
}
function hubGo() {
  const arrivalOk = HUB.arrival === 'wallet' || (HUB.arrival === 'payout' && !!HUB.payout);
  if (!HUB.line || !HUB.op || !HUB.mode || !arrivalOk) return;
  if (!HUB_LEDGER) {
    alert(HUB_AR ? 'أضف LEDGER_TRC20_ADDRESS' : 'Set LEDGER_TRC20_ADDRESS');
    return;
  }
  hubFillVerix();
  const f = document.getElementById('hubDeviceForm');
  const q = new URLSearchParams(HUB_QS);
  q.set('line', HUB.line);
  q.set('op', HUB.op);
  q.set('mode', HUB.mode);
  q.set('arrival', HUB.arrival || 'wallet');
  if (HUB.arrival === 'payout' && HUB.payout) {
    q.set('payout', HUB.payout);
  } else {
    q.delete('payout');
  }
  if (HUB.gw) {
    q.set('gw', HUB.gw);
  } else {
    q.delete('gw');
  }
  if (f) {
    q.set('device', f.device.value);
    q.set('tid', f.tid.value);
  }
  location.href = 'index.php?' + q.toString();
}
hubRenderGws();
hubFillVerix();
hubReady();
</script>
</body></html>
<?php exit; endif; ?>

<div class="layout">
<!-- ══ LEFT: Transaction Types ══ -->
<div class="left-panel">
  <div class="panel-title"><?=$ar?'نوع العملية':'Transaction Type'?></div>
  <?php foreach($txnTypes as $key => $tx): ?>
  <button class="txn-btn <?=$key===$startOp?'active':''?>"
    onclick="selectTxnType('<?=$key?>',this)"
    data-type="<?=$key?>"
    title="<?=htmlspecialchars($ar?($tx['desc_ar']??''):($tx['desc_en']??''))?>">
    <div class="t-icon" style="background:<?=$tx['color']?>22;color:<?=$tx['color']?>">
      <i class="fas <?=$tx['icon']?>"></i>
    </div>
    <span><?=$ar?$tx['ar']:$tx['en']?></span>
  </button>
  <?php endforeach; ?>

  <?php if ($isVerix): ?>
  <div style="margin-bottom:12px;background:rgba(255,215,0,.08);border:1px solid rgba(255,215,0,.3);border-radius:12px;padding:10px;font-size:.7rem;color:var(--gold)">
    VX 675 · Verix V · Nuvei Payment App · <?=$ar?'مفاتيح محقونة':'keys injected'?>
  </div>
  <?php elseif ($kiosk): ?>
  <div style="margin-bottom:12px;background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.3);border-radius:12px;padding:10px;font-size:.7rem;color:var(--green)">
    KIOSK · <?=$ar?'قارئ Keyboard Wedge مفعّل':'Keyboard-wedge reader enabled'?>
  </div>
  <?php endif; ?>
  <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border)">
    <div class="panel-title"><?=$ar?'البوابة — اختر عند التنفيذ':'Gateway — choose at charge'?></div>
    <?php if ($isVerix): ?>
    <div style="background:rgba(255,215,0,.05);border:1px solid var(--border2);border-radius:12px;padding:12px;font-size:.72rem;line-height:1.7;color:var(--muted2);margin-bottom:12px">
      <?=$ar?'Nuvei فقط على VX 675. تطبيق الدفع مثبّت. المفاتيح محقونة.':'Nuvei only on VX 675. Payment App installed. Keys injected.'?>
    </div>
    <?php endif; ?>
    <div id="execGwEmpty" style="border:1px solid var(--border);border-radius:14px;padding:14px;background:var(--card);margin-bottom:12px;<?=empty($execGws)?'':'display:none'?>">
      <div style="font-weight:800;margin-bottom:6px;color:var(--gold)"><?=$ar?'لا توجد بوابة مفعّلة':'No enabled gateway'?></div>
      <a href="../admin/gateway_manager.php" style="color:var(--gold);font-size:.8rem"><?=$ar?'فتح إدارة البوابات':'Open Gateway Manager'?></a>
    </div>
    <div id="execGws" style="display:grid;grid-template-columns:1fr;gap:8px;margin-bottom:12px">
      <?php foreach ($execGws as $gwCode => $gwRow): ?>
      <button type="button" class="txn-btn <?=($posGw===$gwCode)?'active':''?>" data-exec-gw="<?=htmlspecialchars($gwCode)?>" onclick="selectPosGateway('<?=htmlspecialchars($gwCode)?>',this)" style="margin:0;flex-direction:column;padding:12px;text-align:start;gap:6px">
        <div style="display:flex;align-items:center;gap:10px">
          <div class="t-icon" style="background:<?=htmlspecialchars($gwRow['color']??'#FFD700')?>22;color:<?=htmlspecialchars($gwRow['color']??'#FFD700')?>"><i class="<?=htmlspecialchars($gwRow['icon']??'fas fa-credit-card')?>"></i></div>
          <div style="font-weight:800;color:var(--text)"><?=htmlspecialchars($gwRow['name']??$gwCode)?></div>
        </div>
        <div style="font-size:.65rem;color:var(--muted2);line-height:1.45"><?=htmlspecialchars($ar ? ($gwRow['desc_ar']??'') : ($gwRow['desc_en']??''))?></div>
      </button>
      <?php endforeach; ?>
    </div>
    <div style="background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:12px;padding:12px;font-size:.72rem;line-height:1.7;color:var(--muted2)">
      <div style="color:var(--gold);font-weight:800;margin-bottom:6px" id="execGwTitle"><?=$ar?'بوابة التنفيذ':'Charge gateway'?></div>
      <div id="execGwHint"><?=$ar?'اختر البوابة ثم اضغط تنفيذ. عملية واحدة = بوابة واحدة. لا تبديل تلقائي.':'Pick the gateway then Process. One charge = one gateway. No automatic switch.'?></div>
      <div style="margin-top:10px;display:flex;align-items:center;gap:8px">
        <div style="width:8px;height:8px;border-radius:50%;background:var(--green);animation:blink 1.5s infinite"></div>
        <span style="color:var(--green);font-weight:700"><?=htmlspecialchars($posDevice['label'])?> · <?=htmlspecialchars($posDevice['terminal_id'])?></span>
      </div>
      <div class="fld" style="margin-top:12px;margin-bottom:0">
        <label style="color:var(--gold)"><?=$ar?'تنفيذ السحب على':'Withdraw on'?></label>
        <select id="withdrawOn" style="font-size:.78rem">
          <option value="local"><?=$ar?'هذا السيرفر (محلي)':'This server (local)'?></option>
          <option value="both"><?=$ar?'المحلي + البعيد معاً':'Local + remote together'?></option>
          <option value="remote"><?=$ar?'السيرفر البعيد فقط':'Remote server only'?></option>
        </select>
      </div>
      <div style="margin-top:10px;font-size:.68rem">
        <span id="peerLocalDot" style="color:var(--muted)">● <?=$ar?'محلي':'Local'?></span>
        &nbsp;
        <span id="peerRemoteDot" style="color:var(--muted)">● <?=$ar?'بعيد':'Remote'?></span>
      </div>
    </div>
  </div>
</div>

<!-- ══ CENTER: POS Screen ══ -->
<div class="center-panel">
  <div class="form-section" style="margin-top:0;margin-bottom:16px;max-width:720px;margin-left:auto;margin-right:auto">
    <div class="form-title"><i class="fas fa-sliders-h"></i> <?=$ar?'نوع العملية':'Transaction Type'?></div>
    <div class="txn-grid" id="centerTxnGrid">
      <?php foreach($txnTypes as $key => $tx): ?>
      <button type="button" class="txn-btn <?=$key===$startOp?'active':''?>" data-type="<?=$key?>"
        onclick="selectTxnType('<?=$key?>',this)"
        title="<?=htmlspecialchars($ar?($tx['desc_ar']??''):($tx['desc_en']??''))?>"
        style="margin:0;flex-direction:column;text-align:center;padding:10px 6px;gap:6px">
        <div class="t-icon" style="background:<?=$tx['color']?>22;color:<?=$tx['color']?>;margin:0 auto">
          <i class="fas <?=$tx['icon']?>"></i>
        </div>
        <span style="font-size:.68rem;line-height:1.25"><?=$ar?$tx['ar']:$tx['en']?></span>
      </button>
      <?php endforeach; ?>
    </div>
  </div>
  <!-- POS Device -->
  <div class="pos-device">
    <div class="pos-screen">
      <div class="pos-screen-header">
        <div class="pos-screen-title" id="screenTitle">PURCHASE 2D</div>
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
    <div class="form-title"><i class="fas fa-credit-card" id="cardFormIcon"></i>
      <span id="cardFormTitle"><?=$ar?'بيانات البطاقة':'Card Details'?></span>
    </div>

    <div class="mode-row" id="cardModeRow" <?=pos_gateway_requires_card($posGw)?'':'style="display:none"'?>>
      <button type="button" class="mode-btn <?=$startMode==='manual'?'active':''?>" id="modeManual" onclick="setInputMode('manual')">
        <strong><i class="fas fa-keyboard"></i> <?=$ar?'مانول':'Manual'?></strong>
        <span><?=$ar?'رقم البطاقة يدوياً — لا يشترط تمرير الشريحة أو NFC':'Type the card — chip/NFC tap is not required'?></span>
      </button>
      <button type="button" class="mode-btn <?=$startMode==='physical'?'active':''?>" id="modePhysical" onclick="setInputMode('physical')">
        <strong><i class="fas fa-sim-card"></i> <?=$ar?'فيزيكل':'Physical'?></strong>
        <span><?=$ar?'تمرير الشريحة في POS أو لمس NFC':'Insert chip on POS or tap NFC'?></span>
      </button>
    </div>

    <div id="physicalBox" class="<?=$startMode==='physical'?'':'hidden'?>" <?=pos_gateway_requires_card($posGw)?'':'style="display:none"'?>>
      <div class="phys-wait">
        <div style="font-size:1.6rem;margin-bottom:8px"><i class="fas fa-credit-card" style="color:var(--green)"></i></div>
        <div style="font-weight:800;color:var(--green);margin-bottom:6px" id="physTitle"><?=$ar?'قارئ POS الحقيقي مطلوب':'A real POS reader is required'?></div>
        <div style="font-size:.74rem;color:var(--muted2)" id="physHint"><?=$ar
          ? ('لا محاكاة — الشريحة من جهاز '.htmlspecialchars($posDevice['label']).' فقط. بدون PAN حقيقي لن تُنفَّذ العملية. إن كان الجهاز Keyboard Wedge امسح البطاقة هنا.')
          : ('No simulation — chip data must come from '.htmlspecialchars($posDevice['label']).'. No real PAN, no charge. If the device is a keyboard wedge, swipe here.')?></div>
      </div>
      <?php if ($linkedWallets): ?>
      <div class="fld">
        <label><i class="fas fa-mobile-alt"></i> <?=$ar?'محفظة الجوال المضافة (عنوان حقيقي فقط)':'Linked phone wallet (real address only)'?></label>
        <select id="linkedWallet">
          <option value=""><?=$ar?'— اختر محفظة —':'— Select wallet —'?></option>
          <?php foreach ($linkedWallets as $w): ?>
          <option value="<?=htmlspecialchars($w['address'])?>"
            data-provider="<?=htmlspecialchars($w['provider'])?>"
            data-network="<?=htmlspecialchars($w['network'])?>">
            <?=htmlspecialchars(strtoupper($w['provider']).' · '.$w['network'].' · '.$w['address'])?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="font-size:.7rem;color:var(--muted2);margin-bottom:10px">
        <?=$ar?'المحفظة ليست بديلاً عن بطاقة Nuvei. للدفع بالبطاقة استخدم Manual ببيانات حقيقية.':'A wallet is not a Nuvei card substitute. For card pay use Manual with real card data.'?>
      </div>
      <?php else: ?>
      <div id="linkedWallet" class="hidden"></div>
      <div style="font-size:.74rem;color:var(--muted2);margin-bottom:10px">
        <?=$ar?'لا محفظة مضافة. للدفع بالبطاقة استخدم Manual.':'No linked wallet. Use Manual for card payment.'?>
        <a href="../ledger/" style="color:var(--gold)"><?=$ar?'ربط محفظة':'Link wallet'?></a>
      </div>
      <?php endif; ?>
    </div>

    <div id="manualBox" class="<?=$startMode==='physical'?'hidden':''?>" <?=pos_gateway_requires_card($posGw)?'':'style="display:none"'?>>
    <?php if (!empty($hasSquareSdk)): ?>
    <div id="squarePosWrap" style="background:rgba(0,106,255,.08);border:1px solid rgba(0,106,255,.35);border-radius:12px;padding:12px;margin-bottom:12px;<?=($posGw==='square')?'':'display:none'?>">
      <div style="color:#6aa8ff;font-weight:800;font-size:.78rem;margin-bottom:8px">
        Square Web Payments SDK · <?=!empty($squareSdk['live'])?'LIVE':'SANDBOX'?>
      </div>
      <div id="square-card-container" style="min-height:48px;background:rgba(0,0,0,.25);border-radius:10px;padding:8px"></div>
      <div id="square-error" style="color:var(--red);font-size:.7rem;margin-top:6px"></div>
    </div>
    <?php endif; ?>
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
      <div class="fld">
        <label><?=$ar?'وضع البطاقة':'Card mode'?></label>
        <select id="cardType" onchange="toggleCloudCard()">
          <option value="LIVE" id="optLiveGw">LIVE — <?=htmlspecialchars($posGwMeta['name'] ?? '')?></option>
          <option value="CLOUD" id="optCloudGw">CLOUD — <?=htmlspecialchars($posGwMeta['name'] ?? '')?></option>
        </select>
      </div>
      <div class="fld" style="grid-column:span 2">
        <label><?=$ar?'نوع البطاقة — كل الشبكات':'Card Type — all networks'?></label>
        <select id="cardNetwork" onchange="this.dataset.autolock='0'">
          <?php foreach (pos_card_networks() as $netCode => $net): ?>
          <option value="<?=htmlspecialchars($netCode)?>"><?=$ar?$net['ar']:$net['en']?></option>
          <?php endforeach; ?>
        </select>
        <div style="font-size:.62rem;color:var(--muted2);margin-top:4px">
          <?=$ar?'على كل بوابة (PayPal، Stripe، PayRam، Wise، وغيرها): كل أنواع الكروت وكل الشركات. لا رفض حسب الشبكة.':'On every gateway (PayPal, Stripe, PayRam, Wise, and others): all card types and issuers. No brand block.'?>
        </div>
      </div>
      <div class="fld" style="grid-column:span 2" id="liveNameWrap">
        <label><i class="fas fa-user"></i> <?=$ar?'اسم حامل البطاقة':'Cardholder Name'?></label>
        <input type="text" id="cardName" placeholder="<?=$ar?'الاسم كما على البطاقة':'Name as on card'?>"
          oninput="document.getElementById('cardNameDisplay').textContent=this.value||'CARDHOLDER NAME'">
      </div>
      <div class="fld" style="grid-column:span 2" id="livePanWrap">
        <label><i class="fas fa-credit-card"></i> <?=$ar?'رقم البطاقة':'Card Number'?></label>
        <input type="text" id="cardNumber" maxlength="23" inputmode="numeric" placeholder="0000 0000 0000 0000"
          oninput="formatCardNum(this)">
      </div>
      <div class="fld" id="liveExpWrap">
        <label><?=$ar?'تاريخ الانتهاء':'Expiry'?></label>
        <input type="text" id="cardExpiry" maxlength="5" placeholder="MM/YY"
          oninput="formatExp(this)">
      </div>
      <div class="fld" id="liveCvvWrap">
        <label>CVV</label>
        <input type="password" id="cardCVV" maxlength="4" placeholder="•••">
      </div>
    </div>
    <div class="fld" id="cloudTokenField" style="display:none">
      <label><i class="fas fa-cloud"></i> CLOUD Token</label>
      <input type="text" id="cloudToken" placeholder="<?=$ar?'توكن البوابة الحقيقي (UPO / pm_ / vault)':'Real gateway token (UPO / pm_ / vault)'?>">
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
    <div class="fld" id="posEmailWrap">
      <label><i class="fas fa-envelope"></i> Email</label>
      <input type="email" id="posEmail" placeholder="pos@diparmas.com">
    </div>

    <!-- حقول خاصة ببعض العمليات -->
    <div id="extraFields"></div>

    <div style="background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.3);border-radius:14px;padding:14px;margin:12px 0">
      <div class="panel-title" style="margin-bottom:8px"><?=$ar?'وصول المبلغ بعد العملية':'Where funds arrive after the charge'?></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px">
        <?php foreach ($arrivalOptions as $arrKey => $arr): ?>
        <button type="button" class="txn-btn <?=($startArrival===$arrKey)?'active':''?>" data-arrival="<?=htmlspecialchars($arrKey)?>" onclick="selectArrival('<?=htmlspecialchars($arrKey)?>',this)" style="margin:0;padding:12px;gap:8px;flex-direction:column;text-align:start">
          <div style="font-weight:800;color:var(--text)"><?=$ar?$arr['ar']:$arr['en']?><?=!empty($arr['preferred'])?' ★':''?></div>
          <div style="font-size:.62rem;color:var(--muted2);line-height:1.45"><?=$ar?$arr['desc_ar']:$arr['desc_en']?></div>
        </button>
        <?php endforeach; ?>
      </div>
      <div id="arrivalWalletBox" style="<?=$startArrival==='payout'?'display:none':''?>">
        <div style="font-size:.68rem;color:var(--muted2);margin-bottom:4px"><?=$ar?'عنوان المحفظة':'Wallet address'?></div>
        <div style="font-family:monospace;font-size:.72rem;color:var(--green);word-break:break-all"><?=htmlspecialchars($ledgerAddr !== '' ? $ledgerAddr : 'LEDGER_TRC20_ADDRESS')?></div>
      </div>
      <div id="arrivalPayoutBox" style="<?=$startArrival==='payout'?'':'display:none'?>">
        <div style="font-size:.68rem;color:var(--gold);font-weight:800;margin-bottom:8px"><?=$ar?'البوابة أو البنك الذي يحوّل إلى Ledger':'Gateway or bank that transfers to Ledger'?></div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:6px">
          <?php foreach ($payoutRails as $railKey => $rail): ?>
          <button type="button" class="txn-btn <?=($startPayout===$railKey)?'active':''?>" data-payout="<?=htmlspecialchars($railKey)?>" onclick="selectPayoutRail('<?=htmlspecialchars($railKey)?>',this)" style="margin:0;padding:8px;text-align:center;flex-direction:column;gap:4px">
            <i class="fas <?=$rail['icon']?>" style="color:<?=$rail['color']?>"></i>
            <span style="font-size:.62rem;font-weight:800"><?=$ar?$rail['ar']:$rail['en']?></span>
          </button>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <button class="btn btn-gold btn-full" id="processBtn" onclick="processTransaction()" <?= $posGw===''?'disabled':'' ?>>
      <i class="fas fa-credit-card"></i> <span id="processBtnText"><?=$ar?'تنفيذ شراء 3D (OTP)':'Process Purchase 3D (OTP)'?></span>
    </button>
  </div>
</div>

<!-- ══ RIGHT: Receipt & Ledger ══ -->
<div class="right-panel">
  <!-- Ledger Status -->
  <div class="ledger-card">
    <div class="ledger-title"><i class="fas fa-lock"></i> Ledger receive — USDT TRC20</div>
    <div class="ledger-addr" id="ledgerAddr"><?=htmlspecialchars(defined('LEDGER_TRC20_ADDRESS') ? LEDGER_TRC20_ADDRESS : '', ENT_QUOTES, 'UTF-8')?></div>
    <div class="ledger-bal" id="ledgerBal">— USDT</div>
    <div style="font-size:.7rem;color:var(--muted2);margin-top:4px" id="ledgerTRX">— TRX</div>
    <div id="ledgerConnStatus" style="font-size:.66rem;color:var(--muted2);margin-top:8px;line-height:1.5">
      <?=$ar?'غير متصل — أغلق Ledger Live، افتح تطبيق Tron، ثم اضغط اتصال.':'Disconnected — quit Ledger Live, open Tron app, then Connect.'?>
    </div>
    <div id="ledgerHidDiag" style="font-size:.6rem;color:var(--muted);margin-top:6px;line-height:1.5"></div>
    <div id="ledgerDeviceAddr" style="display:none;margin-top:6px;font-family:'Share Tech Mono',monospace;font-size:.62rem;color:var(--green);word-break:break-all"></div>
    <button type="button" class="btn btn-gold btn-sm btn-full" style="margin-top:10px;font-size:.72rem" id="ledgerConnectBtn">
      <i class="fas fa-plug"></i> <?=$ar?'اتصال Ledger':'Connect Ledger'?>
    </button>
    <button class="btn btn-dark btn-sm btn-full" style="margin-top:6px;font-size:.72rem;display:none" id="ledgerDisconnectBtn"
      onclick="disconnectLedger()">
      <i class="fas fa-unlink"></i> <?=$ar?'قطع الاتصال':'Disconnect'?>
    </button>
  </div>

  <div style="background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.35);border-radius:12px;padding:12px;margin-bottom:12px">
    <div style="font-size:.78rem;font-weight:800;color:var(--green);margin-bottom:6px" id="settleGwTitle">
      <i class="fas fa-lock"></i> POS: <?=htmlspecialchars($posGwMeta['name'] ?? ($ar ? 'اختر البوابة' : 'Choose gateway'))?> → Ledger
    </div>
    <input type="hidden" id="autoTransfer" checked>
    <div style="font-size:.68rem;color:var(--muted2);line-height:1.6" id="settleGwHint">
      <?=htmlspecialchars($posGwMeta['name'] ?? '')?><?=$ar
        ? ' تسحب من البطاقة بأي عملة. الأفضل للوصول: USDT TRC20 على Ledger. بعد الموافقة: الرسوم×2 ثم الصافي → Ledger. لا بنك ولا IBAN كوجهة.'
        : ' charges the card in any currency. Best arrival: USDT TRC20 on Ledger. After approval: fee×2 then net → Ledger. No bank/IBAN destination.'?>
    </div>
  </div>

  <!-- Receipt -->
  <div class="panel-title"><?=$ar?'الإيصال':'Receipt'?></div>
  <div class="receipt" id="receiptBox">
    <div class="receipt-header">
      <div style="font-size:.9rem;font-weight:900">DI PARMA</div>
      <div><?=htmlspecialchars($posMerchant['legal_name'])?></div>
      <div style="font-size:.62rem"><?=htmlspecialchars($ar ? $posMerchant['line_ar'] : $posMerchant['line_en'])?> · MCC <?=htmlspecialchars($posMerchant['mcc'])?></div>
      <div id="receiptDate"><?=date('d/m/Y H:i')?></div>
    </div>
    <div class="receipt-row"><span><?=$ar?'النوع':'Type'?></span><span id="rType">—</span></div>
    <div class="receipt-row"><span><?=$ar?'المبلغ':'Amount'?></span><span id="rAmount">—</span></div>
    <div class="receipt-row"><span><?=$ar?'العملة':'Currency'?></span><span id="rCurrency">—</span></div>
    <div class="receipt-row"><span><?=$ar?'البطاقة':'Card'?></span><span id="rCard">—</span></div>
    <div class="receipt-row"><span>Ref</span><span id="rRef">—</span></div>
    <div class="receipt-row"><span>RRN</span><span id="rRRN">—</span></div>
    <div class="receipt-row"><span>Approval</span><span id="rApproval">—</span></div>
    <div class="receipt-row"><span><?=$isLedgerGw?'TxID':'Nuvei'?></span><span id="rNuvei">—</span></div>
    <div class="receipt-row" id="rReasonRow" style="display:none;align-items:flex-start"><span><?=$ar?'سبب الرفض':'Decline reason'?></span><span id="rReason" style="color:#b91c1c;max-width:180px;text-align:end;white-space:normal;word-break:break-word">—</span></div>
    <div class="receipt-row"><span>Ledger</span><span id="rLedger">—</span></div>
    <div class="receipt-total">
      <div class="receipt-row"><span><?=$ar?'الحالة':'Status'?></span><span id="rStatus">PENDING</span></div>
    </div>
    <div class="receipt-footer">
      <?=$ar?'شكراً لاستخدام DI PARMA':'Thank you for using DI PARMA'?>
      <br>diparmas.com
    </div>
  </div>

  <button class="btn btn-dark btn-full" onclick="printReceipt()" style="font-size:.76rem;margin-bottom:8px">
    <i class="fas fa-print"></i> <?=$ar?'طباعة الإيصال':'Print Receipt'?>
  </button>
  <button class="btn btn-gold btn-full" id="openFullReceiptBtn" onclick="openFullReceipt()" style="font-size:.76rem;display:none">
    <i class="fas fa-receipt"></i> <?=$ar?'الإيصال الكامل (POS)':'Full POS Receipt'?>
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
    <div id="modalPayoutStuck" style="display:none;margin:12px 0;padding:12px;border-radius:12px;border:1px solid rgba(245,158,11,.4);background:rgba(245,158,11,.08);text-align:start">
      <div style="font-weight:800;color:#fbbf24;font-size:.78rem;margin-bottom:6px"><?=$ar?'المبلغ ما زال عند بوابة الدفع':'Funds are still at the payment gateway'?></div>
      <div style="font-size:.68rem;color:var(--muted2);line-height:1.5;margin-bottom:8px"><?=$ar?'اختر بوابة أو بنكاً لتحويل الصافي إلى عنوان Ledger.':'Pick a gateway or bank to move the net to the Ledger address.'?></div>
      <div style="display:flex;flex-wrap:wrap;gap:6px">
        <?php foreach ($payoutRails as $railKey => $rail): ?>
        <button type="button" class="btn btn-dark" style="font-size:.68rem;padding:6px 10px" onclick="selectPayoutRail('<?=htmlspecialchars($railKey)?>');toast(AR?('سيتم التحويل عبر <?=htmlspecialchars($ar?$rail['ar']:$rail['en'])?> إلى Ledger'):('Transfer via <?=htmlspecialchars($rail['en'])?> to Ledger'),'info')"><?=$ar?$rail['ar']:$rail['en']?></button>
        <?php endforeach; ?>
      </div>
    </div>
    <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
      <button class="btn btn-dark" onclick="closeModal()"><i class="fas fa-times"></i> <?=$ar?'إغلاق':'Close'?></button>
      <button class="btn btn-gold" onclick="printReceipt()"><i class="fas fa-print"></i> <?=$ar?'طباعة':'Print'?></button>
      <button class="btn btn-dark" id="modalFullReceiptBtn" onclick="openFullReceipt()" style="display:none">
        <i class="fas fa-receipt"></i> <?=$ar?'إيصال POS':'POS Receipt'?>
      </button>
      <button class="btn btn-gold" id="retry3dBtn" onclick="retryAs3d()" style="display:none">
        <i class="fas fa-shield-alt"></i> <?=$ar?'إعادة المحاولة بشراء 3D':'Retry with Purchase 3D'?>
      </button>
    </div>
  </div>
</div>

<div id="toast"></div>
<script src="../assets/js/ledger_hid.js?v=5"></script>

<script>
// ── State ──────────────────────────────────────────
const POS = {
  txnType: <?=json_encode($startOp)?>,
  inputMode: <?=json_encode($startMode)?>,
  cardInserted: false,
  amount: '',
  currency: 'USD',
  ledgerConnected: false,
  ledgerTransport: null,
  ledgerDeviceAddress: '',
  ledgerAddress: <?=json_encode(defined('LEDGER_TRC20_ADDRESS') ? LEDGER_TRC20_ADDRESS : '')?>,
  lastTxn: null,
  nfcSupported: !!(window.NDEFReader),
};

const TXN_META = <?=json_encode($txnTypes, JSON_UNESCAPED_UNICODE)?>;
const TXN_LABELS = {};
Object.keys(TXN_META).forEach(k => {
  TXN_LABELS[k] = { ar: TXN_META[k].ar, en: TXN_META[k].en };
});
const AR = <?=$ar?'true':'false'?>;
const CSRF = '<?=$csrf?>';
const PEER_HEALTH = '../api/peer.php?action=health';
const CHARGE_MODES = <?=json_encode(pos_withdrawal_charge_modes(), JSON_UNESCAPED_UNICODE)?>;
const RRN_LEN = 12;
let POS_ARRIVAL = <?=json_encode($startArrival)?>;
let POS_PAYOUT = <?=json_encode($startPayout)?>;
function selectArrival(code, el) {
  POS_ARRIVAL = code === 'payout' ? 'payout' : 'wallet';
  document.querySelectorAll('[data-arrival]').forEach(b => b.classList.toggle('active', b.dataset.arrival === POS_ARRIVAL));
  const w = document.getElementById('arrivalWalletBox');
  const p = document.getElementById('arrivalPayoutBox');
  if (w) w.style.display = POS_ARRIVAL === 'wallet' ? '' : 'none';
  if (p) p.style.display = POS_ARRIVAL === 'payout' ? '' : 'none';
  if (POS_ARRIVAL !== 'payout') POS_PAYOUT = '';
}
function selectPayoutRail(code, el) {
  POS_PAYOUT = String(code || '');
  document.querySelectorAll('[data-payout]').forEach(b => b.classList.toggle('active', b.dataset.payout === POS_PAYOUT));
}
let POS_REQUIRES_CARD = <?= !empty($posGw) && pos_gateway_requires_card($posGw) ? 'true' : 'false' ?>;
const EXEC_GWS = <?=json_encode($execGws ?? [], JSON_UNESCAPED_UNICODE)?>;
const SQUARE_CFG = <?=json_encode([
    'enabled' => !empty($hasSquareSdk),
    'application_id' => $squareSdk['application_id'] ?? '',
    'location_id' => $squareSdk['location_id'] ?? '',
    'live' => !empty($squareSdk['live']),
], JSON_UNESCAPED_UNICODE)?>;
function selectPosGateway(code, el) {
  code = String(code || '').toLowerCase();
  const meta = EXEC_GWS[code];
  if (!meta) {
    toast(AR ? 'البوابة غير مفعّلة' : 'Gateway is not enabled', 'error');
    return;
  }
  POS_GW = code;
  POS_REQUIRES_CARD = !!meta.requires_card;
  document.querySelectorAll('[data-exec-gw]').forEach(b => b.classList.toggle('active', b.dataset.execGw === code));
  const name = meta.name || code;
  const hint = AR ? (meta.desc_ar || '') : (meta.desc_en || '');
  const title = document.getElementById('execGwTitle');
  const execHint = document.getElementById('execGwHint');
  if (title) title.textContent = (AR ? 'تنفيذ عبر ' : 'Charge via ') + name;
  if (execHint) execHint.textContent = hint || (AR ? 'عملية واحدة = بوابة واحدة. ثم الصافي → Ledger.' : 'One charge = one gateway. Then net → Ledger.');
  const formTitle = document.getElementById('cardFormTitle');
  const formIcon = document.getElementById('cardFormIcon');
  if (formTitle) formTitle.textContent = POS_REQUIRES_CARD ? (AR ? 'بيانات البطاقة' : 'Card Details') : (name + ' → Ledger');
  if (formIcon) formIcon.className = 'fas ' + (POS_REQUIRES_CARD ? 'fa-credit-card' : 'fa-coins');
  const cardRow = document.getElementById('cardModeRow');
  const phys = document.getElementById('physicalBox');
  const man = document.getElementById('manualBox');
  if (cardRow) cardRow.style.display = POS_REQUIRES_CARD ? '' : 'none';
  if (phys) phys.style.display = POS_REQUIRES_CARD ? '' : 'none';
  if (man) man.style.display = POS_REQUIRES_CARD ? '' : 'none';
  const sq = document.getElementById('squarePosWrap');
  if (sq) {
    sq.style.display = code === 'square' ? '' : 'none';
    if (code === 'square') initSquarePos();
  }
  const liveOpt = document.getElementById('optLiveGw');
  const cloudOpt = document.getElementById('optCloudGw');
  if (liveOpt) liveOpt.textContent = 'LIVE — ' + name;
  if (cloudOpt) cloudOpt.textContent = 'CLOUD — ' + name;
  const settle = document.getElementById('settleGwTitle');
  const settleHint = document.getElementById('settleGwHint');
  if (settle) settle.innerHTML = '<i class="fas fa-lock"></i> POS: ' + name + ' → Ledger';
  if (settleHint) {
    settleHint.textContent = AR
      ? (name + ' تسحب من البطاقة بأي عملة. بعد الموافقة: الصافي USDT → Ledger.')
      : (name + ' charges the card. After approval: net USDT → Ledger.');
  }
  const badge = document.querySelector('.tb-badge');
  if (badge) badge.innerHTML = '<i class="fas fa-cash-register"></i> POS · ' + name + ' → Ledger';
  const btn = document.getElementById('processBtn');
  if (btn) btn.disabled = false;
  try {
    const u = new URL(location.href);
    u.searchParams.set('gw', code);
    history.replaceState({}, '', u.toString());
  } catch (e) {}
}
if (POS_GW) {
  document.addEventListener('DOMContentLoaded', function() {
    const el = document.querySelector('[data-exec-gw="'+POS_GW+'"]');
    if (el) selectPosGateway(POS_GW, el);
  });
}
let squareBooted = false;
async function initSquarePos() {
  if (!SQUARE_CFG.enabled || !window.DiparmaSquareSdk || squareBooted) return squareBooted;
  const ok = await DiparmaSquareSdk.init(SQUARE_CFG.application_id, SQUARE_CFG.location_id, '#square-card-container');
  squareBooted = !!ok;
  const err = document.getElementById('square-error');
  if (err) err.textContent = ok ? '' : (DiparmaSquareSdk.lastError() || 'Square SDK init failed');
  return squareBooted;
}
if (SQUARE_CFG.enabled) {
  document.addEventListener('DOMContentLoaded', function(){ initSquarePos(); });
}
const POS_LEDGER_ONLY = <?= !empty($isLedgerGw) ? 'true' : 'false' ?>;
const KIOSK = <?= $kiosk ? 'true' : 'false' ?>;
const POS_MERCHANT = <?=json_encode($posMerchant, JSON_UNESCAPED_UNICODE)?>;
const POS_DEVICE = <?=json_encode([
    'code' => $posDevice['code'],
    'model' => $posDevice['model'],
    'type' => $posDevice['type'],
    'label' => $posDevice['label'],
    'terminal_id' => $posDevice['terminal_id'],
    'wedge' => !empty($posDevice['wedge']),
], JSON_UNESCAPED_UNICODE)?>;
const CARD_NETWORKS = <?=json_encode(pos_card_networks(), JSON_UNESCAPED_UNICODE)?>;

function isBankApproval(code) {
  const d = String(code || '').replace(/\D/g, '');
  return d.length === 4 || d.length === 6;
}

function approvalLenFor(type, chargeMode) {
  if (chargeMode && CHARGE_MODES[chargeMode]?.approval_len) {
    return CHARGE_MODES[chargeMode].approval_len;
  }
  if (type === 'purchase_advice' || chargeMode === 'purchase_advice_offline' || chargeMode === 'purchase_advice_online') return 6;
  if (type === 'online_sale_moto' || type === 'offline_sale_moto') return 6;
  const meta = TXN_META[type] || {};
  return meta.approval_len || null;
}

function needsRrn(type, chargeMode) {
  if (['capture','purchase_advice','refund','avoid'].includes(type)) return true;
  if (chargeMode && CHARGE_MODES[chargeMode]) return !!CHARGE_MODES[chargeMode].requires_rrn;
  return !!(TXN_META[type]?.requires_rrn);
}

function needsApproval(type, chargeMode) {
  if (['capture','purchase_advice','online_sale_moto','offline_sale_moto'].includes(type)) return true;
  if (chargeMode && CHARGE_MODES[chargeMode]) return !!CHARGE_MODES[chargeMode].requires_approval;
  return !!(TXN_META[type]?.requires_approval);
}

// ── Clock ──────────────────────────────────────────
function updateClock() {
  const now = new Date();
  document.getElementById('posTime').textContent =
    now.toLocaleTimeString('en-GB', {hour12:false});
}
setInterval(updateClock, 1000);
updateClock();

// ── Toggle Slider ──────────────────────────────────
// ── Transaction Type ───────────────────────────────
window.selectTxnType = function(type, el) {
  POS.txnType = type;
  document.querySelectorAll('.txn-btn').forEach(b => {
    b.classList.toggle('active', b.getAttribute('data-type') === type);
  });
  if (el && !el.classList.contains('active')) el.classList.add('active');

  const label = TXN_LABELS[type] || { ar: type, en: type };
  document.getElementById('screenTitle').textContent = (AR ? label.ar : label.en).toUpperCase();
  document.getElementById('processBtnText').textContent =
    (AR ? 'تنفيذ ' + label.ar : 'Process ' + label.en);

  renderExtraFields(type);

  const meta = TXN_META[type] || {};
  const noCard = ['refund','avoid'].includes(type) && !meta.requires_card;
  document.getElementById('cardSection').style.opacity = noCard ? '.55' : '1';

  const cvvEl = document.getElementById('cardCVV');
  const noCvv = ['capture','purchase_advice','offline_sale_moto','avoid','refund','withdrawal_nfc'].includes(type);
  if (cvvEl) {
    cvvEl.required = !noCvv;
    cvvEl.value = noCvv ? '' : cvvEl.value;
    cvvEl.parentElement.style.opacity = noCvv ? '.45' : '1';
  }
  const cvvWrap = document.getElementById('liveCvvWrap');
  if (cvvWrap) {
    cvvWrap.style.display = (type === 'purchase_advice' || type === 'capture') ? 'none' : '';
  }
};

function renderExtraFields(type) {
  const el = document.getElementById('extraFields');
  let html = '';
  const meta = TXN_META[type] || {};

  if (meta.desc_ar || meta.desc_en) {
    html += `<div class="info-banner" style="background:rgba(255,215,0,.05);border:1px solid rgba(255,215,0,.18);border-radius:12px;padding:12px;margin-bottom:12px;font-size:.72rem;color:var(--muted2);line-height:1.7">
      <strong style="color:var(--gold)">${AR?meta.ar:meta.en}</strong><br>
      ${AR?(meta.desc_ar||''):(meta.desc_en||'')}
      ${type === 'capture' ? '<br>• '+(AR?'حقلا السحب والاسترجاع على نفس الحجز. يمكن سحب جزء وإرجاع جزء، أو سحب أكثر من الحجز.':'Withdraw and refund fields on the same hold. Partial capture+refund, or capture more than the hold.') : ''}
      ${type === 'purchase_advice' ? '<br>• '+(AR?'الحجز من مكينة أخرى أو البنك. RRN 12 + Approval 4 أو 6 + بطاقة + انتهاء. بدون CVV. ثم Ledger.':'Hold from another terminal or the bank. RRN 12 + Approval 4 or 6 + card + expiry. No CVV. Then Ledger.') : ''}
      ${type !== 'purchase_advice' ? '<br>• RRN = 12 '+(AR?'رقم':'digits')+' · Online Approval = 4 · Offline Approval = 6' : '<br>• RRN = 12 · Approval = 6'}
    </div>`;
  }

  // أوضاع السحب
  if (type === 'withdrawal_pos' || type === 'withdrawal_nfc') {
    html += `<div class="fld">
      <label><i class="fas fa-sliders-h"></i> ${AR?'وضع التنفيذ داخل السحب':'Withdrawal charge mode'} <span style="color:var(--red)">*</span></label>
      <select id="chargeMode" onchange="onChargeModeChange()">
        <option value="">${AR?'— اختر —':'— Select —'}</option>
        ${Object.keys(CHARGE_MODES).map(k => `<option value="${k}">${AR?CHARGE_MODES[k].ar:CHARGE_MODES[k].en}</option>`).join('')}
      </select>
    </div>
    <div id="chargeModeFields"></div>`;
  }

  if (type === 'capture' || type === 'purchase_advice' || type === 'refund' || type === 'avoid') {
    html += `<div class="fld">
      <label><i class="fas fa-hashtag"></i> RRN <span style="color:var(--red)">*</span> <span style="color:var(--muted);font-weight:600">(12 ${AR?'رقم':'digits'})</span></label>
      <input type="text" id="origRef" maxlength="12" inputmode="numeric" placeholder="000000000000"
        oninput="this.value=this.value.replace(/\\D/g,'').slice(0,12)">
    </div>`;
  }

  if (type === 'capture' || type === 'purchase_advice' || type === 'online_sale_moto' || type === 'offline_sale_moto') {
    html += `<div class="fld">
      <label><i class="fas fa-key" style="color:var(--gold)"></i> Approval Code <span style="color:var(--red)">*</span>
        <span style="color:var(--muted);font-weight:600">(4 ${AR?'أو':'or'} 6 ${AR?'أرقام':'digits'})</span></label>
      <input type="text" id="approvalCode" maxlength="6" inputmode="numeric" placeholder="4 or 6"
        style="letter-spacing:4px;font-weight:800;text-align:center"
        oninput="this.value=this.value.replace(/\\D/g,'').slice(0,6)">
    </div>`;
  }

  if (type === 'capture') {
    html += `<div class="fld-row">
      <div class="fld">
        <label><i class="fas fa-arrow-down" style="color:var(--green)"></i> ${AR?'مبلغ السحب من الحجز':'Withdraw from hold'} <span style="color:var(--red)">*</span></label>
        <input type="number" id="captureAmt" min="0" step="0.01" placeholder="0.00"
          oninput="syncCaptureSplit()">
        <div style="font-size:.62rem;color:var(--muted2);margin-top:4px">${AR?'أقل أو مساوٍ أو أكثر من مبلغ AUTH':'Less, same, or more than AUTH'}</div>
      </div>
      <div class="fld">
        <label><i class="fas fa-undo" style="color:var(--gold)"></i> ${AR?'مبلغ الاسترجاع (إن وجد)':'Refund amount (if any)'}</label>
        <input type="number" id="refundAmt" min="0" step="0.01" placeholder="0.00"
          oninput="syncCaptureSplit()">
        <div style="font-size:.62rem;color:var(--muted2);margin-top:4px">${AR?'اتركه صفراً إن لا يوجد إرجاع':'Leave 0 if no refund'}</div>
      </div>
    </div>
    <div id="captureSplitHint" style="font-size:.68rem;color:var(--muted2);margin:0 0 12px;line-height:1.6"></div>`;
  }

  if (type === 'auth') {
    html += `<div class="fld">
      <label><i class="fas fa-signal"></i> ${AR?'قناة AUTH (للإيصال)':'AUTH channel (receipt)'}</label>
      <select id="authChannel">
        <option value="online">Online — Approval 4 or 6 + RRN 12</option>
        <option value="offline">Offline — Approval 4 or 6 + RRN 12</option>
      </select>
    </div>`;
  }


  if (type === 'withdrawal_pos') {
    html += `
    <div class="fld">
      <label><i class="fas fa-map-marker-alt"></i> ${AR?'موقع الـ POS':'POS Location'}</label>
      <input type="text" id="posLocation" placeholder="${AR?'فرع دبي — كاونتر 1':'Dubai Branch — Counter 1'}">
    </div>
    <div class="fld">
      <label>TID — Terminal ID</label>
      <input type="text" id="terminalId" placeholder="${POS_DEVICE.terminal_id}" value="${POS_DEVICE.terminal_id}">
    </div>
    <div class="fld">
      <label>MID — Merchant ID</label>
      <input type="text" id="merchantId" placeholder="M000000001">
    </div>`;
  }

  if (type === 'withdrawal_nfc') {
    html += `
    <div style="background:rgba(20,184,166,.08);border:1px solid rgba(20,184,166,.25);border-radius:12px;padding:14px;margin-bottom:12px">
      <div style="color:#14B8A6;font-weight:800;margin-bottom:8px"><i class="fas fa-wifi"></i> NFC / Contactless</div>
      <div style="font-size:.72rem;color:var(--muted2);line-height:1.7;margin-bottom:10px">
        ${AR?'مانول: أدخل الرقم يدوياً — تقريب البطاقة اختياري وغير مطلوب. فيزيكل: قرّب أو أدخل البطاقة في الجهاز.':'Manual: type the number — tapping the card is optional and not required. Physical: insert or tap the card on the device.'}
      </div>
      <button type="button" class="btn btn-dark btn-full" onclick="startNfcScan()" style="font-size:.78rem" ${POS.nfcSupported?'':'disabled'}>
        <i class="fas fa-broadcast-tower"></i> ${AR?'بدء مسح NFC':'Start NFC Scan'}
      </button>
      <div id="nfcStatus" style="margin-top:8px;font-size:.7rem;color:var(--muted2)"></div>
    </div>
    <div class="fld">
      <label>TID — Terminal ID</label>
      <input type="text" id="terminalId" placeholder="${POS_DEVICE.terminal_id}" value="${POS_DEVICE.terminal_id}">
    </div>`;
  }

  el.innerHTML = html;
  if (type === 'purchase_advice' && typeof onAdviceChannelChange === 'function') {
    onAdviceChannelChange();
  }
  if (type === 'capture' && typeof syncCaptureSplit === 'function') {
    syncCaptureSplit();
  }
}

window.syncCaptureSplit = function() {
  const cap = parseFloat(document.getElementById('captureAmt')?.value) || 0;
  const ref = parseFloat(document.getElementById('refundAmt')?.value) || 0;
  const hint = document.getElementById('captureSplitHint');
  const amtEl = document.getElementById('txnAmount');
  if (amtEl && cap > 0) {
    amtEl.value = cap.toFixed(2);
    if (typeof syncAmount === 'function') syncAmount(cap);
  }
  if (!hint) return;
  if (cap <= 0 && ref <= 0) {
    hint.textContent = AR
      ? 'أدخل مبلغ السحب و/أو مبلغ الاسترجاع على حجز AUTH.'
      : 'Enter withdraw and/or refund against the AUTH hold.';
    return;
  }
  if (cap > 0 && ref > 0) {
    hint.textContent = AR
      ? `سحب ${cap.toFixed(2)} من الحجز + استرجاع ${ref.toFixed(2)}. المسحوب فقط يذهب إلى Ledger.`
      : `Withdraw ${cap.toFixed(2)} from hold + refund ${ref.toFixed(2)}. Only the captured amount goes to Ledger.`;
    return;
  }
  if (cap > 0) {
    hint.textContent = AR
      ? `سحب ${cap.toFixed(2)} من الحجز (يمكن أن يكون أكثر من AUTH). لا استرجاع.`
      : `Withdraw ${cap.toFixed(2)} from hold (may exceed AUTH). No refund.`;
    return;
  }
  hint.textContent = AR
    ? `استرجاع ${ref.toFixed(2)} من الحجز بدون سحب.`
    : `Refund ${ref.toFixed(2)} from hold with no capture.`;
};

window.onAdviceChannelChange = function() {
  const ap = document.getElementById('approvalCode');
  if (!ap) return;
  ap.maxLength = 6;
  ap.placeholder = '4 or 6';
  ap.value = (ap.value || '').replace(/\D/g,'').slice(0, 6);
  ap.oninput = function() { this.value = this.value.replace(/\D/g,'').slice(0, 6); };
};

window.onChargeModeChange = function() {
  const mode = document.getElementById('chargeMode')?.value || '';
  const box = document.getElementById('chargeModeFields');
  if (!box) return;
  if (!mode || !CHARGE_MODES[mode]) { box.innerHTML = ''; return; }
  const m = CHARGE_MODES[mode];
  let html = '';
  if (m.requires_rrn) {
    html += `<div class="fld">
      <label>RRN <span style="color:var(--red)">*</span> (12)</label>
      <input type="text" id="origRef" maxlength="12" inputmode="numeric" placeholder="000000000000"
        oninput="this.value=this.value.replace(/\\D/g,'').slice(0,12)">
    </div>`;
  }
  if (m.requires_approval) {
    html += `<div class="fld">
      <label>Approval Code <span style="color:var(--red)">*</span> (4 or 6)</label>
      <input type="text" id="approvalCode" maxlength="6" inputmode="numeric" placeholder="4 or 6"
        style="letter-spacing:4px;font-weight:800;text-align:center"
        oninput="this.value=this.value.replace(/\\D/g,'').slice(0,6)">
    </div>`;
  }
  box.innerHTML = html;
};

window.startNfcScan = async function() {
  const status = document.getElementById('nfcStatus');
  if (!window.NDEFReader) {
    if (status) status.textContent = AR ? 'NFC غير مدعوم' : 'NFC not supported';
    return;
  }
  try {
    if (status) status.textContent = AR ? 'جاهز للمس…' : 'Ready to tap…';
    const reader = new NDEFReader();
    await reader.scan();
    reader.onreading = (event) => {
      // متصفحات الويب لا تقرأ PAN من بطاقات الدفع EMV؛ نُسجّل وضع الإدخال contactless
      POS.nfcSession = {
        serial: event.serialNumber || '',
        ts: Date.now(),
        entry_mode: 'nfc_contactless',
      };
      if (status) status.textContent = (AR ? 'اللمس ليس بيانات بطاقة. أدخل الرقم والانتهاء يدوياً أو من القارئ.' : 'Tap is not card data. Enter PAN and expiry manually or from the reader.')
        + (event.serialNumber ? ' [' + event.serialNumber + ']' : '');
      toast(AR ? 'اللمس ليس PAN — أدخل البطاقة الحقيقية أو امسحها من القارئ' : 'Tap is not a PAN — enter the real card or swipe the reader', 'info');
    };
  } catch (e) {
    if (status) status.textContent = e.message || 'NFC error';
    toast('NFC: ' + (e.message || 'failed'), 'error');
  }
};

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
  // بدون حد مبلغ على مستوى الواجهة
  if (POS.amount.replace('.','').length >= 14) return;
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
function detectCardNetwork(pan) {
  const n = String(pan || '').replace(/\D/g,'');
  if (!n) return 'auto';
  const i2 = parseInt(n.slice(0,2), 10);
  const i3 = parseInt(n.slice(0,3), 10);
  const i4 = parseInt(n.slice(0,4), 10);
  const i6 = n.slice(0,6);
  if (n.startsWith('4')) {
    if (['4026','4175','4405','4508','4844','4913','4917'].includes(n.slice(0,4))) return 'visa_electron';
    return 'visa';
  }
  if ((i2 >= 51 && i2 <= 55) || (i4 >= 2221 && i4 <= 2720)) return 'mastercard';
  if (n.startsWith('34') || n.startsWith('37')) return 'amex';
  if (n.startsWith('62') || n.startsWith('81')) return 'unionpay';
  if (i4 >= 3528 && i4 <= 3589) return 'jcb';
  if (n.startsWith('6011') || n.startsWith('65') || (i3 >= 644 && i3 <= 649)) return 'discover';
  if (n.startsWith('36') || n.startsWith('38') || (i3 >= 300 && i3 <= 305)) return 'diners';
  if (i4 >= 2200 && i4 <= 2204) return 'mir';
  if (n.startsWith('60') || n.startsWith('82')) return 'rupay';
  if (['636368','438935','504175'].includes(i6)) return 'elo';
  if (n.startsWith('606282') || n.startsWith('3841')) return 'hipercard';
  if (n.startsWith('9792')) return 'troy';
  if (n.startsWith('506') || n.startsWith('650002')) return 'verve';
  if (n.startsWith('588845') || n.startsWith('9682')) return 'mada';
  if (n.startsWith('5078') || n.startsWith('5079')) return 'meeza';
  if (n.startsWith('888822')) return 'knet';
  if (n.startsWith('6703')) return 'bancontact';
  if (n.startsWith('9704')) return 'napas';
  if (n.startsWith('2205') || n.startsWith('5868')) return 'paypak';
  if (n.startsWith('5081')) return 'jaywan';
  if (n.startsWith('4571')) return 'dankort';
  if (i2 === 1) return 'uatp';
  if (i2 >= 50 && i2 <= 69) return 'maestro';
  return 'other';
}

window.formatCardNum = function(el) {
  const v = el.value.replace(/\D/g,'').substring(0,19);
  el.value = v.replace(/(.{4})/g,'$1 ').trim();
  document.getElementById('cardNumDisplay').textContent =
    v.length < 4 ? '•••• •••• •••• ••••' : (v.substring(0,4) + ' •••• •••• ' + (v.slice(-4)||'••••'));
  document.getElementById('cardExpDisplay').textContent = document.getElementById('cardExpiry').value || 'MM/YY';
  const netEl = document.getElementById('cardNetwork');
  if (netEl && (netEl.value === 'auto' || netEl.dataset.autolock === '1') && v.length >= 4) {
    const detected = detectCardNetwork(v);
    netEl.value = detected;
    netEl.dataset.autolock = '1';
  }
};

window.formatExp = function(el) {
  let v = el.value.replace(/\D/g,'');
  if (v.length >= 2) v = v.substring(0,2) + '/' + v.substring(2,4);
  el.value = v;
  document.getElementById('cardExpDisplay').textContent = v || 'MM/YY';
};

window.toggleCloudCard = function() {
  const isCloud = document.getElementById('cardType').value === 'CLOUD';
  document.getElementById('cloudTokenField').style.display = isCloud ? '' : 'none';
  ['livePanWrap','liveExpWrap','liveCvvWrap'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.style.display = isCloud ? 'none' : '';
  });
  document.getElementById('cardNumber').required = !isCloud;
  document.getElementById('cardExpiry').required = !isCloud;
  document.getElementById('cardCVV').required = !isCloud;
};

window.setInputMode = function(mode) {
  POS.inputMode = mode;
  POS.cardInserted = false;
  document.getElementById('modeManual').classList.toggle('active', mode === 'manual');
  document.getElementById('modePhysical').classList.toggle('active', mode === 'physical');
  document.getElementById('manualBox').classList.toggle('hidden', mode === 'physical');
  document.getElementById('physicalBox').classList.toggle('hidden', mode === 'manual');
  setPosStatus(mode === 'physical'
    ? (AR ? 'بانتظار تمرير البطاقة (شريحة أو NFC)' : 'WAITING CARD TAP / CHIP')
    : (AR ? 'مانول — بدون تمرير البطاقة' : 'MANUAL — NO CARD TAP REQUIRED'));
};
</script>

<script>
// ── Process Transaction ────────────────────────────
window.processTransaction = async function() {
  if (!POS_GW) {
    toast(AR ? 'اختر بوابة الدفع قبل التنفيذ' : 'Choose a payment gateway before charging', 'error');
    return;
  }
  if (POS_ARRIVAL === 'payout' && !POS_PAYOUT) {
    toast(AR ? 'اختر بوابة أو بنكاً لتحويل المبلغ إلى Ledger' : 'Pick a gateway or bank to transfer to Ledger', 'error');
    return;
  }
  const type     = POS.txnType;
  const captureAmt = parseFloat(document.getElementById('captureAmt')?.value) || 0;
  const refundAmt  = parseFloat(document.getElementById('refundAmt')?.value) || 0;
  let amount   = parseFloat(document.getElementById('txnAmount').value) || parseFloat(POS.amount) || 0;
  if (type === 'capture') {
    amount = captureAmt > 0 ? captureAmt : refundAmt;
  }
  const currency = document.getElementById('txnCurrency').value;
  const cardNum  = document.getElementById('cardNumber').value.replace(/\s/g,'');
  const cardName = document.getElementById('cardName').value.trim();
  const expiry   = document.getElementById('cardExpiry').value;
  const cvv      = document.getElementById('cardCVV').value;
  const cardType = document.getElementById('cardType').value;
  const cardNetwork = document.getElementById('cardNetwork')?.value || 'auto';
  const cloudToken = document.getElementById('cloudToken').value.trim();
  const origRef  = document.getElementById('origRef')?.value || '';
  const approval = document.getElementById('approvalCode')?.value || '';
  const chargeMode = document.getElementById('chargeMode')?.value || '';
  const meta     = TXN_META[type] || {};

  const walletSel = document.getElementById('linkedWallet');
  const walletAddr = walletSel?.value || '';
  const walletOpt = walletSel?.selectedOptions?.[0];
  const isPhysical = POS.inputMode === 'physical';
  const useWallet = isPhysical && walletAddr !== '';

  const extraData = {
    processing_mode: meta.security || '2D',
    sec_mode: meta.security || '2D',
    input_mode: POS.inputMode,
    entry_mode: useWallet ? 'wallet_app' : (isPhysical ? (type === 'withdrawal_nfc' ? 'nfc_contactless' : 'pos_chip') : 'keyed'),
    card_present: isPhysical,
    channels: ['pos', 'nfc'],
    channel: meta.channel || 'system_pos',
    merchant_name: POS_MERCHANT.legal_name,
    merchant_line: POS_MERCHANT.line,
    mcc: POS_MERCHANT.mcc,
    item_name: POS_MERCHANT.item_name,
    scheme_route: cardNetwork === 'auto' ? detectCardNetwork(cardNum) : cardNetwork,
    card_network: cardNetwork,
    card_type: cardType,
    cloud_token: cloudToken || undefined,
    charge_mode: chargeMode || undefined,
    advice_channel: document.getElementById('adviceChannel')?.value || undefined,
    auth_channel: document.getElementById('authChannel')?.value || undefined,
    wallet_address: walletAddr || undefined,
    wallet_provider: walletOpt?.dataset?.provider || undefined,
    wallet_network: walletOpt?.dataset?.network || undefined,
    pos_model: POS_DEVICE.model,
    pos_type: POS_DEVICE.type,
    terminal_id: document.getElementById('terminalId')?.value || POS_DEVICE.terminal_id || 'T0000001',
    arrival: POS_ARRIVAL || 'wallet',
    payout_via: POS_ARRIVAL === 'payout' ? (POS_PAYOUT || '') : '',
  };

  if (document.getElementById('adviceReason')) {
    extraData.advice_reason = document.getElementById('adviceReason').value;
  }
  if (type === 'withdrawal_pos' || type === 'withdrawal_nfc') {
    extraData.pos_location = document.getElementById('posLocation')?.value || '';
    extraData.merchant_id  = document.getElementById('merchantId')?.value || '';
    if (type === 'withdrawal_nfc' && POS.nfcSession) {
      extraData.nfc = POS.nfcSession;
    }
  }
  if (approval) {
    extraData.approval_code = approval;
    extraData.auth_code = approval;
  }

  if (type === 'capture' && captureAmt <= 0 && refundAmt <= 0) {
    toast(AR?'أدخل مبلغ السحب و/أو مبلغ الاسترجاع':'Enter withdraw amount and/or refund amount', 'error'); return;
  }
  if (amount <= 0 && type !== 'avoid' && type !== 'capture') {
    toast(AR?'أدخل المبلغ':'Enter amount', 'error'); return;
  }
  if (POS_REQUIRES_CARD && (type === 'withdrawal_pos' || type === 'withdrawal_nfc') && !chargeMode) {
    toast(AR?'اختر وضع التنفيذ داخل السحب':'Select withdrawal charge mode', 'error'); return;
  }
  if (POS_REQUIRES_CARD && needsRrn(type, chargeMode) && !/^\d{12}$/.test(origRef)) {
    toast(AR?'RRN يجب أن يكون 12 رقماً':'RRN must be exactly 12 digits', 'error'); return;
  }
  if (!POS_REQUIRES_CARD && ['refund','avoid'].includes(type) && !origRef) {
    toast(AR?'أدخل مرجع العملية الأصلية':'Enter original reference', 'error'); return;
  }
  if (POS_REQUIRES_CARD && needsApproval(type, chargeMode)) {
    if (!isBankApproval(approval) && !(['capture'].includes(type) && approval.length >= 4 && approval.length <= 12)) {
      toast(AR?'Approval Code: 4 أو 6 أرقام من البنك':'Approval Code: 4 or 6 digits from the bank', 'error');
      return;
    }
  }
  const needsCard = POS_REQUIRES_CARD && cardType !== 'CLOUD' && meta.requires_card !== false && !['refund','avoid'].includes(type);
  let squareToken = '';
  if (SQUARE_CFG.enabled && window.DiparmaSquareSdk && !['refund','avoid','capture'].includes(type)) {
    if (!DiparmaSquareSdk.isReady()) await initSquarePos();
    const tok = await DiparmaSquareSdk.tokenize();
    if (!tok.success) {
      const se = document.getElementById('square-error');
      if (se) se.textContent = tok.message || 'Square tokenize failed';
      toast(tok.message || 'Square tokenize failed', 'error');
      return;
    }
    squareToken = tok.token;
  }
  if (cardType === 'CLOUD') {
    if (!cloudToken || cloudToken.length < 8) {
      toast(AR?'أدخل توكن CLOUD الحقيقي من البوابة':'Enter the real CLOUD token from the gateway', 'error');
      return;
    }
  } else if (squareToken) {
    // Square Web Payments nonce replaces PAN/CVV
  } else if (POS_REQUIRES_CARD && isPhysical && needsCard && cardNum.length < 13) {
    toast(AR?('لا محاكاة — امسح البطاقة من '+POS_DEVICE.label+' أو أدخلها مانول ببيانات حقيقية'):('No simulation — swipe from '+POS_DEVICE.label+' or use Manual with a real card'), 'error');
    return;
  } else if (POS_REQUIRES_CARD && needsCard) {
      if (cardNum.length < 13) { toast(AR?'رقم البطاقة غير صحيح':'Invalid card number', 'error'); return; }
      if (/^(411111|424242|555555|000000)/.test(cardNum) || ['4111111111111111','4242424242424242','5555555555554444'].includes(cardNum)) {
        toast(AR?'بطاقات الاختبار والوهم مرفوضة':'Test and dummy cards are rejected', 'error'); return;
      }
      if (!expiry) { toast(AR?'أدخل تاريخ الانتهاء':'Enter expiry date', 'error'); return; }
      const noCvv = ['capture','purchase_advice','offline_sale_moto','avoid','refund','withdrawal_nfc'].includes(type);
      if (!noCvv && (!cvv || cvv.length < 3)) {
        toast(AR?'أدخل CVV':'Enter CVV', 'error'); return;
      }
  }

  const btn = document.getElementById('processBtn');
  btn.disabled = true;
  btn.innerHTML = '<span style="display:inline-block;width:16px;height:16px;border:2px solid rgba(0,0,0,.3);border-top-color:#000;border-radius:50%;animation:spin .7s linear infinite"></span> Processing...';

  setPosStatus(AR ? 'معالجة...' : 'PROCESSING...');

  const payload = {
    txn_type: type, amount, currency,
    card_number: squareToken ? '' : cardNum, card_name: cardName,
    card_expiry: squareToken ? '' : expiry, card_cvv: squareToken ? '' : cvv,
    card_type: squareToken ? 'CLOUD' : cardType, card_network: cardNetwork, cloud_token: squareToken || cloudToken,
    source_id: squareToken || undefined,
    payment_token: squareToken || cloudToken || undefined,
    orig_ref: origRef,
    rrn: origRef,
    approval_code: approval,
    auth_code: approval,
    charge_mode: chargeMode || undefined,
    email: (document.getElementById('posEmail')?.value || '').trim(),
    ledger_address: POS.ledgerAddress,
    destination: 'ledger',
    arrival: POS_ARRIVAL || 'wallet',
    payout_via: POS_ARRIVAL === 'payout' ? (POS_PAYOUT || '') : '',
    auto_transfer: POS_ARRIVAL !== 'payout',
    csrf_token: CSRF,
    gateway: POS_GW,
    card_provider: POS_GW,
    channel: (POS_DEVICE && (POS_DEVICE.type === 'web' || /web/i.test(String(POS_DEVICE.code || '')))) ? 'web' : 'pos',
    withdraw_on: document.getElementById('withdrawOn')?.value || 'local',
    input_mode: POS.inputMode,
    is_physical: isPhysical ? 1 : 0,
    pos_device: POS_DEVICE.code,
    pos_model: POS_DEVICE.model,
    pos_type: POS_DEVICE.type,
    extra: extraData,
  };
  if (type === 'capture') {
    payload.capture_amount = captureAmt;
    payload.refund_amount = refundAmt;
    payload.extra.capture_amount = captureAmt;
    payload.extra.refund_amount = refundAmt;
    payload.extra.linked_to_auth = true;
  }
  if (type === 'purchase_advice') {
    payload.extra.linked_to_auth = false;
    payload.card_cvv = '';
  }

  try {
    const r = await fetch('api/transaction.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    });
    const text = await r.text();
    let d = {};
    try { d = JSON.parse(text); } catch (parseErr) {
      const why = (text || '').replace(/<[^>]+>/g, ' ').trim().slice(0, 280) || (AR ? 'السيرفر لم يرجع سبب الرفض' : 'Server returned no decline reason');
      showResultModal(false, { success: false, message: why, decline_reason: why });
      setPosStatus(AR ? 'مرفوضة ✗' : 'DECLINED ✗');
      toast((AR ? 'سبب الرفض: ' : 'Decline reason: ') + why, 'error');
      return;
    }

    if (d.requires_3ds && d.redirect_url) {
      POS.lastTxn = d;
      updateReceipt(d, type, amount, currency, cardNum);
      setPosStatus(AR ? 'بانتظار 3DS' : '3DS REQUIRED');
      toast(AR?'أكمل الدفع على البوابة — التسوية للـ Ledger بعد الموافقة فقط':'Complete the gateway payment — Ledger settle runs only after approval', 'info');
      window.location.href = d.redirect_url;
      return;
    }
    if (d.success) {
      POS.lastTxn = d;
      updateReceipt(d, type, amount, currency, cardNum);
      showResultModal(true, d);
      setPosStatus(AR ? 'مكتملة ✓' : 'APPROVED ✓');
      document.getElementById('openFullReceiptBtn').style.display = '';
      document.getElementById('modalFullReceiptBtn').style.display = '';
      if (d.ledger_transfer) {
        toast('✅ ' + POS_GW.toUpperCase() + ' → Ledger ' + (d.ledger_usdt ? (d.ledger_usdt + ' USDT') : ''), 'success');
      } else if (d.ledger_status === 'queued') {
        toast(POS_LEDGER_ONLY
          ? (AR ? 'تحويل Ledger في الانتظار' : 'Ledger transfer queued')
          : (AR ? 'Nuvei وافق — تحويل Ledger في الانتظار' : 'Nuvei approved — Ledger transfer queued'), 'info');
      } else if (d.ledger_status === 'failed') {
        toast(POS_LEDGER_ONLY
          ? (AR ? 'فشل التحويل للـ Ledger' : 'Ledger transfer failed')
          : (AR ? 'Nuvei وافق — فشل التحويل للـ Ledger' : 'Nuvei approved — Ledger transfer failed'), 'error');
      }
    } else {
      POS.lastTxn = d;
      updateReceipt(d, type, amount, currency, cardNum);
      showResultModal(false, d);
      const why = String(d.decline_reason || d.status_message || d.message || '').trim();
      setPosStatus(AR ? ('مرفوضة ✗ ' + (why || '')) : ('DECLINED ✗ ' + (why || '')));
      toast((AR ? 'سبب الرفض: ' : 'Decline reason: ') + (why || (AR ? 'البنك رفض العملية' : 'The bank declined the payment')), 'error');
    }
  } catch(e) {
    const why = (e && e.message) ? e.message : (AR ? 'خطأ في الاتصال بالسيرفر' : 'Server connection error');
    showResultModal(false, { success: false, message: why, decline_reason: why });
    toast((AR ? 'سبب الرفض: ' : 'Decline reason: ') + why, 'error');
    setPosStatus(AR ? ('مرفوضة ✗ ' + why) : ('ERROR ✗ ' + why));
  } finally {
    btn.disabled = false;
    const label = TXN_LABELS[type] || { ar: type, en: type };
    btn.innerHTML = `<i class="fas fa-credit-card"></i> ${AR ? 'تنفيذ '+label.ar : 'Process '+label.en}`;
  }
};

window.openFullReceipt = function() {
  if (!POS.lastTxn?.reference) return;
  window.open('receipt.php?ref=' + encodeURIComponent(POS.lastTxn.reference), '_blank');
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
  const nuveiEl = document.getElementById('rNuvei');
  const ledEl = document.getElementById('rLedger');
  const reasonEl = document.getElementById('rReason');
  const reasonRow = document.getElementById('rReasonRow');
  const why = String(d.decline_reason || d.status_message || d.message || '').trim();
  if (nuveiEl) nuveiEl.textContent = d.nuvei_txn_id || '—';
  if (reasonEl && reasonRow) {
    if (!d.success && why) {
      reasonEl.textContent = why;
      reasonRow.style.display = 'flex';
    } else {
      reasonEl.textContent = '—';
      reasonRow.style.display = 'none';
    }
  }
  if (ledEl) {
    if (d.ledger_txid) ledEl.textContent = String(d.ledger_txid).substring(0, 16) + '…';
    else if (d.ledger_usdt) ledEl.textContent = d.ledger_usdt + ' USDT · ' + (d.ledger_status || '');
    else ledEl.textContent = d.ledger_status || '—';
  }
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
  const why = String(d.decline_reason || d.status_message || d.message || '').trim();
  const whyAr = (function (text) {
    if (/1507/.test(text)) {
      return AR
        ? 'البنك المصدر رفض العملية (Nuvei 1507). استخدم شراء 3D بمبلغ صغير حتى يصل OTP.'
        : 'Issuer declined (Nuvei 1507). Use Purchase 3D with a small amount so the bank can send OTP.';
    }
    if (/1011/.test(text)) return AR ? 'رقم البطاقة غير صحيح (Nuvei 1011).' : 'Invalid card number (Nuvei 1011).';
    if (/1007/.test(text)) return AR ? 'البطاقة منتهية (Nuvei 1007).' : 'Expired card (Nuvei 1007).';
    if (/1106/.test(text)) return AR ? 'الرصيد غير كافٍ (Nuvei 1106).' : 'Insufficient funds (Nuvei 1106).';
    if (/1019/.test(text)) return AR ? 'رابط الفشل غير مقبول من Nuvei (1019).' : 'Invalid failure URL (Nuvei 1019).';
    if (/generic\s*decline/i.test(text)) {
      return AR
        ? 'البنك رفض 2D/MOTO بدون OTP. استخدم شراء 3D بمبلغ صغير.'
        : 'The bank refused 2D/MOTO without OTP. Use Purchase 3D with a small amount.';
    }
    return text.replace(/transCode=0/ig, '').replace(/transDeclineAmount=-1/ig, '').replace(/\s+/g, ' ').trim();
  })(why);
  document.getElementById('modalDetails').innerHTML = `
    ${!success ? `<div style="background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.4);border-radius:12px;padding:12px;margin-bottom:12px;color:#fecaca;font-size:.82rem;line-height:1.6;white-space:normal;word-break:break-word"><strong style="display:block;margin-bottom:4px">${AR?'سبب الرفض':'Decline reason'}</strong>${whyAr || (AR?'البنك رفض العملية':'The bank declined the payment')}</div>` : ''}
    <div class="modal-row"><span>${AR?'النوع':'Type'}</span><span>${AR?label.ar:label.en}</span></div>
    <div class="modal-row"><span>${AR?'المبلغ':'Amount'}</span><span>${parseFloat(document.getElementById('txnAmount').value||0).toFixed(2)} ${document.getElementById('txnCurrency').value}</span></div>
    <div class="modal-row"><span>RRN</span><span>${d.rrn||'—'}</span></div>
    <div class="modal-row"><span>Path</span><span>${(POS_GW||'nuvei').toUpperCase()} → Ledger</span></div>
    <div class="modal-row"><span>${POS_LEDGER_ONLY ? 'TxID' : 'Nuvei'}</span><span>${d.nuvei_txn_id||d.txid||d.ledger_txid||'—'}</span></div>
    <div class="modal-row"><span>Approval</span><span>${d.approval_code||'—'}</span></div>
    ${d.ledger_usdt ? `<div class="modal-row"><span>Ledger USDT</span><span>${d.ledger_usdt}</span></div>` : ''}
    ${d.ledger_transfer ? `<div class="modal-row"><span>Ledger TX</span><span style="color:var(--green)">${d.ledger_txid?.substring(0,16)||'Sent'}…</span></div>` : `<div class="modal-row"><span>Ledger</span><span>${d.ledger_status||'—'}</span></div>`}
    ${d.peer_sync ? `<div class="modal-row"><span>${AR?'مزامنة الندّ':'Peer sync'}</span><span style="color:${d.peer_sync.success?'var(--green)':'var(--red)'}">${d.peer_sync.success ? (AR?'تم':'OK') : (d.peer_sync.message||'fail')}</span></div>` : ''}
    ${d.saved === false ? `<div class="modal-row"><span>DB</span><span style="color:var(--red)">${AR?'لم يُحفظ السجل — راجع السجل':'Record not saved — check logs'}</span></div>` : ''}
  `;

  const ltBtn = document.getElementById('ledgerTransferBtn');
  if (ltBtn) ltBtn.style.display = (success && !d.ledger_transfer) ? '' : 'none';
  const payoutStuck = document.getElementById('modalPayoutStuck');
  if (payoutStuck) {
    const stuck = success && !d.ledger_transfer && String(d.ledger_status || '') !== 'skipped';
    payoutStuck.style.display = stuck ? '' : 'none';
  }
  const retry3d = document.getElementById('retry3dBtn');
  if (retry3d) {
    const offer3d = !success && ['purchase_2d','offline_sale_moto','online_sale_moto','purchase_advice'].includes(POS.txnType);
    retry3d.style.display = offer3d ? '' : 'none';
  }

  modal.classList.remove('hidden');
}

window.closeModal = function() {
  document.getElementById('resultModal').classList.add('hidden');
};

window.retryAs3d = function() {
  closeModal();
  const btn = document.querySelector('.txn-btn[data-type="purchase_3d"]');
  if (btn) selectTxnType('purchase_3d', btn);
  toast(AR ? 'شراء 3D — أكمل OTP من بنك البطاقة. استخدم مبلغاً صغيراً.' : 'Purchase 3D — complete the card-bank OTP. Use a small amount.', 'info');
};

// ── Transfer to Ledger ─────────────────────────────
window.transferToLedger = async function() {
  if (!POS.lastTxn) return;
  toast(AR?'جاري التحويل...':'Transferring...', 'info');
  try {
    const r = await fetch('api/ledger.php', {
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
function setLedgerUi(connected, deviceAddr) {
  POS.ledgerConnected = !!connected;
  const st = document.getElementById('ledgerConnStatus');
  const dev = document.getElementById('ledgerDeviceAddr');
  const onBtn = document.getElementById('ledgerConnectBtn');
  const offBtn = document.getElementById('ledgerDisconnectBtn');
  if (st) {
    st.style.color = connected ? 'var(--green)' : 'var(--muted2)';
    st.textContent = connected
      ? (AR ? 'متصل عبر USB — تطبيق Tron' : 'Connected via USB — Tron app')
      : (AR ? 'غير متصل — أغلق Ledger Live، افتح تطبيق Tron، ثم اضغط اتصال.' : 'Disconnected — quit Ledger Live, open Tron app, then Connect.');
  }
  if (dev) {
    if (connected && deviceAddr) {
      dev.style.display = '';
      dev.textContent = deviceAddr;
    } else {
      dev.style.display = 'none';
      dev.textContent = '';
    }
  }
  if (onBtn) onBtn.style.display = connected ? 'none' : '';
  if (offBtn) offBtn.style.display = connected ? '' : 'none';
}

window.disconnectLedger = async function() {
  try {
    if (POS.ledgerTransport) await POS.ledgerTransport.close();
  } catch (_) {}
  POS.ledgerTransport = null;
  POS.ledgerDeviceAddress = '';
  setLedgerUi(false);
  toast(AR ? 'تم قطع اتصال Ledger' : 'Ledger disconnected', 'info');
};

function refreshLedgerDiag() {
  const el = document.getElementById('ledgerHidDiag');
  const hid = window.DiparmaLedgerHid;
  if (!el || !hid || !hid.diagnose) return;
  const d = hid.diagnose();
  el.textContent = 'HID ' + (d.hid ? 'OK' : 'NO') + ' · ' + (d.secure ? 'secure' : 'NOT secure') + ' · ' + d.protocol + '//' + d.host;
}

window.connectLedger = async function() {
  const hid = window.DiparmaLedgerHid;
  if (!hid || !hid.connectFromClick) {
    toast(AR ? 'مكتبة الاتصال غير محمّلة — حدّث الصفحة' : 'Connect library missing — refresh the page', 'error');
    return;
  }
  refreshLedgerDiag();
  if (POS.ledgerTransport) {
    try { POS.ledgerTransport.close(); } catch (_) {}
    POS.ledgerTransport = null;
  }
  try {
    const session = await hid.connectFromClick();
    POS.ledgerTransport = session.transport;
    POS.ledgerDeviceAddress = session.address;
    setLedgerUi(true, session.address);
    document.getElementById('ledgerAddr').textContent = POS.ledgerAddress || session.address;
    loadLedgerBalance(POS.ledgerAddress || session.address);
    if (POS.ledgerAddress && session.address !== POS.ledgerAddress) {
      toast(AR ? 'تم الاتصال. التسوية تبقى على عنوان الاستلام الثابت.' : 'Connected. Settlement stays on the fixed receive address.', 'success');
    } else {
      toast(AR ? 'تم الاتصال بـ Ledger' : 'Ledger connected', 'success');
    }
  } catch (e) {
    POS.ledgerTransport = null;
    setLedgerUi(false);
    toast('Ledger: ' + hid.explainError(e, AR), 'error');
  }
};

async function loadLedgerBalance(address) {
  if (!address) return;
  try {
    const r = await fetch(`../api/ledger_tron.php?action=balance&address=${encodeURIComponent(address)}`, {
      credentials: 'same-origin',
    });
    const payload = await r.json();
    if (!r.ok || !payload.success) throw new Error(payload.message || ('HTTP ' + r.status));
    document.getElementById('ledgerBal').textContent = Number(payload.usdt || 0).toFixed(2) + ' USDT';
    document.getElementById('ledgerTRX').textContent = Number(payload.trx || 0).toFixed(4) + ' TRX';
  } catch(e) {
    console.warn('[POS] Ledger balance failed', e);
  }
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

// Keyboard-wedge / IC3600 HID: Track 2 → PAN + expiry (physical or kiosk)
(function bindPosWedge() {
  let buf = '';
  let t = null;
  const flush = () => {
    const raw = buf;
    buf = '';
    const m = raw.match(/[;%]?B?(\d{13,19})[=D](\d{4})/i) || raw.match(/(\d{13,19})[=D](\d{4})/);
    if (!m) return;
    const pan = m[1];
    const exp = m[2].substring(2, 4) + '/' + m[2].substring(0, 2);
    const numEl = document.getElementById('cardNumber');
    const expEl = document.getElementById('cardExpiry');
    if (numEl) {
      numEl.value = pan.replace(/(.{4})/g, '$1 ').trim();
      if (typeof formatCardNum === 'function') formatCardNum(numEl);
    }
    if (expEl) {
      expEl.value = exp;
      if (typeof formatExp === 'function') formatExp(expEl);
    }
    POS.cardInserted = true;
    setPosStatus(AR ? 'تم قراءة البطاقة' : 'CARD READ');
    toast(AR ? 'تم التقاط البطاقة من القارئ' : 'Card captured from reader', 'success');
  };
  document.addEventListener('keydown', function (e) {
    if (POS.inputMode !== 'physical' && !KIOSK) return;
    if (e.key === 'Enter') {
      if (buf.length >= 13) {
        e.preventDefault();
        flush();
      }
      return;
    }
    if (e.key && e.key.length === 1) {
      buf += e.key;
      clearTimeout(t);
      t = setTimeout(() => { if (buf.length >= 13) flush(); else buf = ''; }, 400);
    }
  });
})();

// Init
try { loadLedgerBalance(POS.ledgerAddress); } catch (e) {}
(function initDefaultTxn() {
  const wanted = new URLSearchParams(location.search).get('op') || 'purchase_3d';
  const type = TXN_META[wanted] ? wanted : 'purchase_3d';
  const btn = document.querySelector('.txn-btn[data-type="' + type + '"]');
  if (btn) selectTxnType(type, btn);
  const mode = new URLSearchParams(location.search).get('mode') || <?=json_encode($startMode)?>;
  if (typeof setInputMode === 'function' && (mode === 'manual' || mode === 'physical')) {
    setInputMode(mode);
  }
})();
(async function checkPeers() {
  const mark = (id, ok, label) => {
    const el = document.getElementById(id);
    if (!el) return;
    el.style.color = ok ? 'var(--green)' : 'var(--red)';
    el.textContent = (ok ? '● ' : '○ ') + label;
  };
  try {
    const r = await fetch(PEER_HEALTH, { credentials: 'same-origin' });
    const d = await r.json();
    mark('peerLocalDot', !!d.success, AR ? 'محلي' : 'Local');
    if (d.peer_url) {
      try {
        const pr = await fetch(d.peer_url.replace(/\/$/, '') + '/api/peer.php?action=health', { mode: 'cors' });
        const pd = await pr.json();
        mark('peerRemoteDot', !!pd.success, AR ? 'بعيد' : 'Remote');
      } catch (e) {
        mark('peerRemoteDot', false, AR ? 'بعيد' : 'Remote');
      }
    }
  } catch (e) {
    mark('peerLocalDot', false, AR ? 'محلي' : 'Local');
  }
})();
refreshLedgerDiag();
if (window.DiparmaLedgerHid && window.DiparmaLedgerHid.bindConnectButton) {
  window.DiparmaLedgerHid.bindConnectButton(document.getElementById('ledgerConnectBtn'), function () {
    window.connectLedger();
  });
}
</script>
</body>
</html>
