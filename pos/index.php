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

$lang = 'en';
if (isset($_GET['lang']) && in_array($_GET['lang'], ['ar', 'en'], true)) {
    $lang = $_GET['lang'];
    $_COOKIE['di_parma_lang'] = $lang;
    setcookie('di_parma_lang', $lang, time() + 86400 * 365, '/');
} elseif (isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'ar') {
    $lang = 'ar';
}
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
                'tid' => $result['tid'] ?? pos_request_terminal_id(),
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
    'terminal_id' => pos_request_terminal_id(),
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
$canonTid = (string) ($posDevice['terminal_id'] ?? '');
$getTid = pos_normalize_terminal_id((string) ($_GET['tid'] ?? ''));
if ((string)($_GET['device'] ?? '') !== $canonDevice || (string)($_GET['kiosk'] ?? '') !== '1' || (string)($_GET['line'] ?? '') === '' || $getTid !== $canonTid) {
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
$kioskDeviceReady = $kiosk
    && trim((string) ($posMerchant['line'] ?? '')) !== ''
    && trim((string) ($posDevice['model'] ?? '')) !== '';
$posHub = !$kioskDeviceReady && ((string)($_GET['op'] ?? '') === '' || (string)($_GET['mode'] ?? '') === '');
$isLedgerGw = false;

// أنواع العمليات المعيارية
$txnTypes = pos_operation_catalog();
$startOp = pos_normalize_operation((string)($_GET['op'] ?? 'purchase_3d'));
if (!isset($txnTypes[$startOp])) {
    $startOp = 'purchase_3d';
}
$startMode = strtolower(trim((string)($_GET['mode'] ?? '')));
$sunmiPhysical = in_array((string) ($posDevice['model'] ?? ''), ['sunmi_v3', 'sunmi_v3_mix'], true);
if ($startMode === '' && ($kiosk || $sunmiPhysical)) {
    $startMode = 'physical';
}
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
$startAmount = trim((string) ($_GET['amount'] ?? ''));
if ($startAmount !== '' && !preg_match('/^\d+(\.\d{0,2})?$/', $startAmount)) {
    $startAmount = '';
}
$startCurrency = strtoupper(trim((string) ($_GET['currency'] ?? '')));
if ($startCurrency === '') {
    $nuveiDefault = strtoupper(trim((string) (getenv('NUVEI_DEFAULT_CURRENCY') ?: 'AED')));
    $startCurrency = ($posGw === 'nuvei')
        ? (in_array($nuveiDefault, $currencies, true) ? $nuveiDefault : 'AED')
        : 'USD';
}
if (!in_array($startCurrency, $currencies, true)) {
    $startCurrency = ($posGw === 'nuvei') ? 'AED' : 'USD';
}
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
<script src="../assets/js/square_web_payments.js?v=<?= (int) @filemtime(POS_APP_ROOT . '/assets/js/square_web_payments.js') ?>"></script>
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
html,body{min-height:100vh;font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text);color-scheme:dark}
/* ── Topbar ── */
.topbar{background:rgba(2,5,8,.97);border-bottom:1px solid var(--border);
  min-height:58px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;
  padding:8px 16px;position:sticky;top:0;z-index:100}
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
.pos-amount-display{text-align:center;padding:10px 0;position:relative;z-index:6;cursor:text}
.pos-amount-label{color:rgba(0,255,65,.5);font-family:'Share Tech Mono',monospace;
  font-size:.68rem;letter-spacing:2px;margin-bottom:6px;pointer-events:none}
.pos-amount-value{font-family:'Share Tech Mono',monospace;font-size:2.4rem;
  color:var(--pos-digit);letter-spacing:4px;text-shadow:0 0 20px rgba(0,255,65,.4);
  background:transparent;border:0;border-bottom:2px solid transparent;width:100%;text-align:center;outline:none;
  pointer-events:auto!important;position:relative;z-index:7;cursor:text;
  caret-color:var(--pos-digit);user-select:text;-webkit-user-select:text;
  min-height:1.2em;padding:4px 0;-moz-user-select:text}
.pos-amount-value:focus{border-bottom-color:rgba(0,255,65,.55)}
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
.fld input,.fld select{width:100%;background:#141c2e;border:1.5px solid var(--border);
  border-radius:10px;padding:12px 14px;color:#f4f7fb;font-family:'Cairo',sans-serif;
  font-size:1rem;font-weight:700;line-height:1.45;transition:.2s;color-scheme:dark;
  pointer-events:auto!important;position:relative;z-index:2;cursor:text}
.fld input:focus,.fld select:focus{outline:none;border-color:var(--gold);background:#1a2438}
.fld select option,.fld select optgroup{
  background:#141c2e;color:#f4f7fb;font-size:1rem;font-weight:700;font-family:'Cairo',sans-serif}
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
.card-scheme-badge{position:absolute;top:14px;<?=$ar?'left':'right'?>:14px;font-size:.72rem;font-weight:900;letter-spacing:1px;color:#fff}
.card-auto-box{background:#141c2e;border:1.5px solid var(--border);border-radius:12px;padding:12px 14px;min-height:56px}
.card-auto-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px}
.card-auto-scheme{font-size:1rem;font-weight:900;color:var(--gold)}
.card-auto-pills{display:flex;flex-wrap:wrap;gap:6px}
.card-auto-pill{background:rgba(255,255,255,.06);border:1px solid var(--border);border-radius:999px;padding:4px 10px;font-size:.68rem;font-weight:700;color:var(--text)}
.card-auto-hint{font-size:.68rem;color:var(--muted2);line-height:1.5}
/* ── Right Panel — Receipt & Ledger ── */
.right-panel{background:var(--bg2);border-left:1px solid var(--border);padding:16px;overflow-y:auto}
/* Receipt */
.receipt{background:#fff;border-radius:4px;padding:14px 12px;color:#111;font-family:'Share Tech Mono',ui-monospace,monospace;
  font-size:.68rem;line-height:1.45;margin-bottom:14px;max-width:280px;letter-spacing:.02em;box-shadow:0 0 0 1px #e5e5e5}
.receipt-cut{text-align:center;color:#999;font-size:.55rem;letter-spacing:.3em;margin:4px 0}
.receipt-header{text-align:center;border-bottom:1px dashed #999;margin-bottom:8px;padding-bottom:8px}
.receipt-merchant{font-weight:900;font-size:.78rem;text-transform:uppercase}
.receipt-sub{font-size:.6rem;color:#333}
.receipt-row{display:flex;justify-content:space-between;gap:8px;margin-bottom:1px}
.receipt-row span:last-child{text-align:end;font-weight:700}
.receipt-banner{text-align:center;font-size:.95rem;font-weight:900;letter-spacing:.18em;margin:8px 0;padding:6px 0;border-top:1px dashed #999;border-bottom:1px dashed #999}
.receipt-banner.ok{color:#065f46}
.receipt-banner.no{color:#991b1b}
.receipt-reason{text-align:center;font-size:.62rem;font-weight:800;color:#7f1d1d;margin:4px 0 8px;white-space:normal;word-break:break-word;line-height:1.35}
.receipt-advice{text-align:center;font-size:.62rem;font-weight:800;margin:0 0 8px;padding:6px 4px;white-space:normal;word-break:break-word;line-height:1.4;border:1px dashed #999}
.receipt-advice.block{color:#7f1d1d;background:#fee2e2}
.receipt-advice.okuse{color:#065f46;background:#d1fae5}
.receipt-total{border-top:1px dashed #999;margin-top:8px;padding-top:8px;font-size:.78rem;font-weight:900}
.receipt-footer{text-align:center;margin-top:8px;font-size:.55rem;color:#555;line-height:1.4}
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
  transition:.35s;color:var(--text);box-shadow:0 8px 32px rgba(0,0,0,.5);
  max-width:min(92vw,520px);white-space:normal;text-align:center;line-height:1.45}
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
.tb-legend{flex:1 1 100%;order:5}
.tb-legend .ops-legend-box{margin:0;display:block!important}
.ops-legend-box{margin:0 0 12px;padding:12px 12px 10px;background:rgba(255,215,0,.07);border:1.5px solid var(--border2);border-radius:14px}
.ops-legend-title{font-size:.95rem;font-weight:900;color:var(--gold);margin-bottom:8px;letter-spacing:.02em}
.ops-legend-title span{font-size:.72rem;font-weight:700;color:var(--muted2)}
.ops-legend-chips{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px}
.ops-chip{display:inline-flex;align-items:center;border-radius:999px;padding:4px 10px;font-size:.68rem;font-weight:800}
.ops-chip.yes{background:rgba(16,185,129,.15);color:var(--green);border:1px solid rgba(16,185,129,.4)}
.ops-chip.no{background:rgba(239,68,68,.12);color:#fca5a5;border:1px solid rgba(239,68,68,.35)}
.ops-chip.mode{background:rgba(251,191,36,.12);color:#fbbf24;border:1px solid rgba(251,191,36,.35)}
.cap-cmp{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:6px 12px;font-size:.72rem;font-weight:800;border:1.5px solid var(--border);background:rgba(255,255,255,.03);color:var(--muted2);cursor:pointer;font-family:inherit}
.cap-cmp.is-on[data-cap-cmp="less"]{border-color:rgba(251,191,36,.55);color:#fbbf24;background:rgba(251,191,36,.12)}
.cap-cmp.is-on[data-cap-cmp="same"]{border-color:rgba(16,185,129,.55);color:var(--green);background:rgba(16,185,129,.12)}
.cap-cmp.is-on[data-cap-cmp="more"]{border-color:rgba(59,130,246,.55);color:#93c5fd;background:rgba(59,130,246,.12)}
.ops-legend-note{margin:0;font-size:.68rem;line-height:1.55;color:var(--muted2)}
.ops-sticker{margin:0 0 14px;background:var(--card);border:1.5px solid var(--border2);border-radius:14px;overflow:hidden}
.ops-sticker-head{padding:12px 14px 0;font-weight:900;font-size:.82rem;color:var(--gold)}
.ops-sticker .ops-legend-box{margin:10px 12px 8px;border-radius:12px}
.ops-sticker-wait{margin:0 14px 10px;font-size:.72rem;line-height:1.55;color:var(--muted2)}
.ops-sticker-wrap{overflow:auto;max-height:320px;padding:0 8px 12px}
.ops-sticker table{width:100%;border-collapse:collapse;font-size:.66rem}
.ops-sticker th{position:sticky;top:0;background:var(--card2);color:var(--muted2);text-align:<?=$ar?'right':'left'?>;padding:6px 5px;white-space:nowrap}
.ops-sticker td{padding:6px 5px;border-top:1px solid var(--border);color:var(--text);white-space:nowrap}
.ops-sticker tr.is-current{background:rgba(255,215,0,.08)}
.ops-sticker td.is-yes{color:var(--green);font-weight:800}
.ops-sticker td.is-no{color:#fca5a5;font-weight:700}
.ops-sticker td.is-mode{color:#fbbf24;font-weight:700}
</style>

<nav class="topbar">
  <div class="tb-brand">
    <a href="../dashboard.php" style="color:inherit;text-decoration:none;display:flex;align-items:center;gap:10px">
      <i class="fas fa-coins"></i> DI PARMA
    </a>
    <span style="color:var(--muted)">|</span>
    <div class="tb-badge"><i class="fas fa-cash-register"></i> <?= $posHub ? 'POS' : ('POS · ' . htmlspecialchars($posGwMeta['name'] ?? ($ar ? 'اختر البوابة' : 'Choose gateway')) . ' → Ledger') ?></div>
    <?php if (!$posHub): ?>
    <div class="tb-badge" style="margin-inline-start:8px"><?=htmlspecialchars(($posDevice['terminal_id'] ?? '') !== '' ? $posDevice['terminal_id'] : '—')?></div>
    <?php endif; ?>
    <a href="?<?=htmlspecialchars(http_build_query(array_merge($_GET, ['lang' => $ar ? 'en' : 'ar'])))?>" class="tb-badge" style="margin-inline-start:8px;text-decoration:none;color:var(--gold)"><?=$ar?'EN':'العربية'?></a>
  </div>
  <div class="tb-legend">
    <?php if (function_exists('pos_render_ops_legend')) pos_render_ops_legend($ar); ?>
  </div>
  <div class="tb-nav">
    <?php if (!$kiosk): ?>
    <a href="../ledger/"><i class="fas fa-wallet"></i> Ledger</a>
    <a class="tb-dash" href="../dashboard.php"><i class="fas fa-arrow-right" style="transform:<?=$ar?'':'scaleX(-1)'?>"></i> <?=$ar?'عودة للوحة التحكم':'Back to Dashboard'?></a>
    <?php endif; ?>
  </div>
</nav>

<?php if ($posHub): ?>
<?php $hubGwsPos = $liveGwsPos; ?>
<div style="max-width:1280px;margin:40px auto;padding:0 24px 60px">
  <p style="color:var(--muted2);font-size:.82rem;margin-bottom:14px;line-height:1.7">
    <?=$ar
      ? 'الخصم على البوابة المفعّلة التي تختارها. بعد الموافقة يُحسب الصافي ويُرسل USDT TRC20 إلى عنوان Ledger — ليست IBAN بنك.'
      : 'The card is charged on the enabled gateway you pick. After approval, net USDT TRC20 goes to the Ledger address — not a bank IBAN.'?>
  </p>

  <?php
    $fleetWithTid = function_exists('pos_company_terminals_with_tid')
        ? pos_company_terminals_with_tid()
        : [];
    $fleetTidCount = count($fleetWithTid);
    $linkedModels = [];
    foreach ($fleetWithTid as $unit) {
        $mk = (string) ($unit['model'] ?? '');
        if ($mk === '' || isset($linkedModels[$mk])) {
            continue;
        }
        $linkedModels[$mk] = trim(($unit['brand'] ?? '') . ' ' . ($unit['name'] ?? $mk));
    }
    $curModel = (string) $posDevice['model'];
  ?>
  <style>
    .hub-add{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px}
    .hub-add details{flex:1;min-width:200px;background:var(--card);border:1.5px dashed var(--border);border-radius:12px}
    .hub-add summary{cursor:pointer;padding:11px 14px;font-weight:800;font-size:.78rem;color:var(--gold);list-style:none;user-select:none}
    .hub-add summary::-webkit-details-marker{display:none}
    .hub-add summary::after{content:'+';float:<?=$ar?'left':'right'?>;color:var(--muted2);font-weight:700}
    .hub-add details[open] summary::after{content:'−'}
    .hub-add details[open] summary{border-bottom:1px solid var(--border)}
    .hub-add .hub-add-body{padding:12px 14px 14px}
    .hub-add details:not([open]) > *:not(summary){display:none!important}
    .hub-flow{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:18px;align-items:end;position:relative;z-index:2}
    .hub-flow .fld{margin:0;position:relative;z-index:2}
    .hub-flow select,.hub-flow input,.hub-add input,.hub-add select{
      pointer-events:auto!important;position:relative;z-index:3
    }
    @media(max-width:640px){.hub-flow{grid-template-columns:1fr}}
  </style>
  <?php if ($catalogMsg !== ''): ?>
  <div style="margin-bottom:10px;color:#f87171;font-size:.8rem"><?=htmlspecialchars($catalogMsg)?></div>
  <?php endif; ?>
  <?php if (!$kiosk): ?>
  <div class="hub-add">
    <details>
      <summary><?=$ar?'إضافة TID جديد':'Add new TID'?></summary>
      <form method="post" class="hub-add-body">
        <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
        <input type="hidden" name="pos_catalog" value="add_tid">
        <input type="hidden" name="device" value="<?=htmlspecialchars($posDevice['model'])?>">
        <input type="hidden" name="tid" value="<?=htmlspecialchars($posDevice['terminal_id'])?>">
        <input type="hidden" name="line" value="<?=htmlspecialchars($posMerchant['line'])?>">
        <div class="fld" style="margin:0 0 8px">
          <label>TID</label>
          <input type="text" name="new_tid" maxlength="16" placeholder="16526257" required>
        </div>
        <div class="fld" style="margin:0 0 8px">
          <label><?=$ar?'الجهاز المرتبط':'Linked device'?></label>
          <select name="new_tid_model">
            <option value=""><?=$ar?'— اختياري —':'— optional —'?></option>
            <?php foreach ($linkedModels as $mk => $mlab): ?>
            <option value="<?=htmlspecialchars($mk)?>" <?=$curModel===$mk?'selected':''?>><?=htmlspecialchars($mlab)?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn btn-gold btn-sm btn-full" style="width:100%;padding:8px;border:0;border-radius:10px;cursor:pointer;font-weight:800"><?=$ar?'حفظ TID':'Save TID'?></button>
      </form>
    </details>
    <details>
      <summary><?=$ar?'إضافة موديل / تايب جديد':'Add new model / type'?></summary>
      <form method="post" class="hub-add-body">
        <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
        <input type="hidden" name="pos_catalog" value="add_device">
        <input type="hidden" name="device" value="<?=htmlspecialchars($posDevice['model'])?>">
        <input type="hidden" name="tid" value="<?=htmlspecialchars($posDevice['terminal_id'])?>">
        <input type="hidden" name="line" value="<?=htmlspecialchars($posMerchant['line'])?>">
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
    </details>
    <details>
      <summary><?=$ar?'إضافة نشاط جديد':'Add new activity'?></summary>
      <form method="post" class="hub-add-body">
        <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
        <input type="hidden" name="pos_catalog" value="add_activity">
        <input type="hidden" name="device" value="<?=htmlspecialchars($posDevice['model'])?>">
        <input type="hidden" name="tid" value="<?=htmlspecialchars($posDevice['terminal_id'])?>">
        <input type="hidden" name="line" value="<?=htmlspecialchars($posMerchant['line'])?>">
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
    </details>
  </div>
  <?php endif; ?>

  <div style="background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.25);border-radius:14px;padding:12px 14px;margin-bottom:22px;font-size:.78rem;line-height:1.6;color:var(--muted2)">
    <span style="color:var(--green);font-weight:800">LEDGER</span>
    <span style="font-family:monospace;margin-inline-start:8px;color:var(--text);word-break:break-all"><?=htmlspecialchars($ledgerAddr !== '' ? $ledgerAddr : ($ar ? 'غير مضبوط — LEDGER_TRC20_ADDRESS' : 'Not set — LEDGER_TRC20_ADDRESS'))?></span>
  </div>

  <div class="panel-title"><?=$ar?'إعداد العملية':'Operation setup'?>
    <span style="font-weight:600;color:var(--muted2);font-size:.72rem;margin-inline-start:8px">
      <?=$fleetTidCount?> TID
    </span>
  </div>
  <div class="hub-flow">
    <form method="get" id="hubDeviceForm" class="fld">
      <input type="hidden" name="kiosk" value="<?=$kiosk?'1':'0'?>">
      <input type="hidden" name="line" value="<?=htmlspecialchars($posMerchant['line'])?>">
      <input type="hidden" name="device" id="hubDeviceModel" value="<?=htmlspecialchars((string) $posDevice['model'])?>">
      <label for="hubTidSelect"><?=$ar?'الجهاز / TID':'Device / TID'?></label>
      <select name="tid" id="hubTidSelect" onchange="hubTidChange(this)">
          <option value="" <?=($posDevice['terminal_id'] ?? '') === '' ? 'selected' : ''?>><?=$ar?'— بدون TID —':'— no TID —'?></option>
          <?php
            $tidNow = (string) $posDevice['terminal_id'];
            $tidFound = false;
            foreach ($fleetWithTid as $unit):
                $unitTid = (string) ($unit['tid'] ?? '');
                if ($unitTid === '') {
                    continue;
                }
                $sel = ($tidNow === $unitTid);
                if ($sel) {
                    $tidFound = true;
                }
                $lab = trim(($unit['fleet'] ?? '') . ' · ' . ($unit['brand'] ?? '') . ' ' . ($unit['name'] ?? '') . ' · ' . $unitTid);
          ?>
          <option value="<?=htmlspecialchars($unitTid)?>" data-model="<?=htmlspecialchars((string) ($unit['model'] ?? ''))?>" <?=$sel?'selected':''?>><?=htmlspecialchars($lab)?></option>
          <?php endforeach;
            if ($tidNow !== '' && !$tidFound):
          ?>
          <option value="<?=htmlspecialchars($tidNow)?>" data-model="<?=htmlspecialchars((string) $posDevice['model'])?>" selected><?=htmlspecialchars(($posDevice['label'] ?? '') . ' · ' . $tidNow)?></option>
          <?php endif; ?>
        </select>
    </form>

    <div class="fld" id="hubLinesWrap">
      <label for="hubLineSelect"><?=$ar?'نشاط الشركة (يمكن إضافة أنشطة لاحقاً)':'Business activity (add more later)'?></label>
      <select id="hubLineSelect" onchange="hubPickLine(this)">
        <option value=""><?=$ar?'— اختر النشاط —':'— Select activity —'?></option>
        <?php foreach ($activityLines as $lineKey => $lineRow): ?>
        <option
          value="<?=htmlspecialchars($lineKey)?>"
          data-suggest="<?=htmlspecialchars($lineRow['suggested_gateway'] ?? '')?>"
          data-mcc="<?=htmlspecialchars($lineRow['mcc'] ?? '')?>"
          <?=($posMerchant['line']===$lineKey)?'selected':''?>
        ><?=$ar ? htmlspecialchars($lineRow['ar']) : htmlspecialchars($lineRow['en'])?> · MCC <?=htmlspecialchars($lineRow['mcc'] ?? '')?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="fld" id="hubGwsWrap" style="<?=empty($hubGwsPos)?'display:none':''?>">
      <label for="hubGwSelect"><?=$ar?'البوابة المتصلة':'Connected gateway'?></label>
      <select id="hubGwSelect" onchange="hubPickGw(this)">
        <option value=""><?=$ar?'— اختر بوابة متصلة —':'— Select a connected gateway —'?></option>
        <?php foreach ($hubGwsPos as $gwCode => $gwRow): ?>
        <option value="<?=htmlspecialchars((string) $gwCode)?>"
          data-label="<?=htmlspecialchars((string) ($gwRow['name'] ?? $gwCode))?>"
          <?=$posGw===(string)$gwCode?'selected':''?>
        ><?=htmlspecialchars((string) ($gwRow['name'] ?? $gwCode))?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="fld">
      <label for="hubModeSelect"><?=$ar?'طريقة الدفع':'Payment method'?></label>
      <select id="hubModeSelect" onchange="hubPickMode(this)">
        <option value=""><?=$ar?'— اختر طريقة الدفع —':'— Select payment method —'?></option>
        <?php foreach ($cardPresentModes as $modeKey => $mode): ?>
        <option value="<?=htmlspecialchars($modeKey)?>"><?=$ar ? htmlspecialchars($mode['ar']) : htmlspecialchars($mode['en'])?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="fld">
      <label for="hubOpSelect"><?=$ar?'نوع الشراء':'Purchase type'?></label>
      <select id="hubOpSelect" onchange="hubPickOp(this)">
        <option value=""><?=$ar?'— اختر نوع الشراء —':'— Select purchase type —'?></option>
        <?php foreach ($activityOps as $opKey => $op): ?>
        <option value="<?=htmlspecialchars($op['pos_key'])?>"><?=$ar ? htmlspecialchars($op['ar']) : htmlspecialchars($op['en'])?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="fld">
      <label for="hubAmount"><?=$ar?'المبلغ':'Amount'?></label>
      <input type="text" id="hubAmount" inputmode="decimal" autocomplete="off" placeholder="0.00" oninput="hubPickAmount(this)">
    </div>

    <div class="fld">
      <label for="hubCurrency"><?=$ar?'العملة':'Currency'?></label>
      <select id="hubCurrency" onchange="hubPickCurrency(this)">
        <?php foreach ($currencies as $c): ?>
        <option value="<?=htmlspecialchars($c)?>" <?=$c==='USD'?'selected':''?>><?=htmlspecialchars($c)?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="fld">
      <label for="hubArrivalSelect"><?=$ar?'وصول المبلغ':'Where funds arrive'?></label>
      <select id="hubArrivalSelect" onchange="hubPickArrivalSel(this)">
        <?php foreach ($arrivalOptions as $arrKey => $arr): ?>
        <option value="<?=htmlspecialchars($arrKey)?>" <?=$arrKey==='wallet'?'selected':''?>>
          <?=$ar ? htmlspecialchars($arr['ar']) : htmlspecialchars($arr['en'])?><?=!empty($arr['preferred'])?' ★':''?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div id="hubPayoutWrap" class="fld" style="display:none">
      <label for="hubPayoutSelect"><?=$ar?'تحويل الصافي إلى Ledger':'Move net to Ledger'?></label>
      <select id="hubPayoutSelect" onchange="hubPickPayout(this)">
        <option value=""><?=$ar?'— اختر بوابة أو بنكاً —':'— Pick a gateway or bank —'?></option>
        <?php foreach ($payoutRails as $railKey => $rail): ?>
        <option value="<?=htmlspecialchars($railKey)?>"><?=$ar ? htmlspecialchars($rail['ar']) : htmlspecialchars($rail['en'])?> · <?=$rail['kind']==='bank'?($ar?'بنك':'Bank'):($ar?'بوابة':'Gateway')?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div id="hubGwEmpty" style="border:1px solid var(--border);border-radius:14px;padding:18px;background:var(--card);margin-bottom:18px;<?=empty($hubGwsPos)?'':'display:none'?>">
    <div style="font-weight:800;margin-bottom:8px;color:var(--gold)"><?=$ar?'لا توجد بوابة مفعّلة ومتصلة':'No enabled connected gateway'?></div>
    <div style="font-size:.78rem;color:var(--muted2);margin-bottom:8px;line-height:1.5">
      <?=$ar
        ? 'لا توجد بوابة مفعّلة ومتصلة. تواصل مع الإدارة لتفعيل بوابة.'
        : 'No enabled connected gateway. Ask an administrator to enable one.'?>
    </div>
    <?php if (function_exists('isAdmin') && isAdmin()): ?>
    <a href="../admin/gateway_manager.php" style="color:var(--gold);font-size:.8rem"><?=$ar?'فتح إدارة البوابات':'Open Gateway Manager'?></a>
    <?php endif; ?>
  </div>
  <?php if ($isVerix): ?>
  <?php
    $appOn = !empty($verixCommission['payment_app_installed']);
    $keysOn = !empty($verixCommission['keys_injected']);
  ?>
  <div style="background:rgba(255,215,0,.08);border:1px solid rgba(255,215,0,.28);border-radius:14px;padding:14px 16px;margin-bottom:22px;font-size:.78rem;line-height:1.7;color:var(--muted2)">
    <div style="color:var(--gold);font-weight:900;margin-bottom:6px">Verifone VX 675 · Verix V</div>
    <div><?=$ar
      ? 'الجهاز ليس أندرويد. نظام Verix V. على الويب تختار أي بوابة متصلة. إن استخدم تطبيق Nuvei على الجهاز نفسه فالتفويض يتم هناك ثم يُسجَّل الناتج.'
      : 'Not Android. Verix V OS. On this screen you pick any connected gateway. If the Nuvei app on the device authorizes, the result is recorded after that.'?></div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px;margin-top:12px">
      <div style="border:1px solid var(--border);border-radius:10px;padding:8px 10px">Verix V · <?=$ar?'نعم':'Yes'?></div>
      <div style="border:1px solid var(--border);border-radius:10px;padding:8px 10px;color:<?=$appOn?'var(--green)':'#f87171'?>"><?=$ar?'تطبيق الدفع':'Payment App'?> · <?=$appOn?($ar?'مثبّت':'installed'):($ar?'غير مثبّت':'missing')?></div>
      <div style="border:1px solid var(--border);border-radius:10px;padding:8px 10px;color:<?=$keysOn?'var(--green)':'#f87171'?>"><?=$ar?'مفاتيح Nuvei':'Nuvei keys'?> · <?=$keysOn?($ar?'محقونة':'injected'):($ar?'غير محقونة':'not injected')?></div>
      <div style="border:1px solid var(--border);border-radius:10px;padding:8px 10px"><?=$ar?'البوابات المتصلة':'Connected gateways'?> · <?=count($liveGwsPos)?></div>
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

  <button type="button" class="key-btn key-enter" id="hubGo" onclick="hubGo()" disabled style="width:100%;height:48px;border-radius:14px;font-size:1rem">
    <?=$ar?'فتح POS':'Open POS'?>
  </button>
</div>
<script>
const HUB = {
  line: <?=json_encode((string) ($posMerchant['line'] ?? ''))?>,
  gw: <?=json_encode((string) $posGw)?>,
  op: '',
  mode: '',
  arrival: 'wallet',
  payout: '',
  amount: '',
  currency: <?=json_encode($startCurrency)?>
};
const HUB_GWS = <?=json_encode($hubGwsPos, JSON_UNESCAPED_UNICODE)?>;
const HUB_SUGGEST = <?=json_encode($activitySuggest, JSON_UNESCAPED_UNICODE)?>;
const HUB_LEDGER = <?=json_encode($ledgerAddr)?>;
const HUB_AR = <?=$ar?'true':'false'?>;
const HUB_QS = <?=json_encode($posQuery)?>;
const HUB_VERIX = <?= $isVerix ? 'true' : 'false' ?>;
const HUB_HOST = <?=json_encode($verifoneHost)?>;
const HUB_TID = <?=json_encode($posDevice['terminal_id'])?>;
const HUB_READY = true;
const STICKER_ROWS = <?=json_encode(function_exists('pos_ops_sticker_rows') ? pos_ops_sticker_rows() : [], JSON_UNESCAPED_UNICODE)?>;
const AR = HUB_AR;
function stickerRule(type) {
  return (STICKER_ROWS || []).find(r => r.op === type) || null;
}
function legendChipHtml(flag, yesLbl, noLbl) {
  if (flag === 'mode') return `<span class="ops-chip mode">${AR ? 'حسب الوضع' : 'By mode'} · ${yesLbl}</span>`;
  if (flag === true) return `<span class="ops-chip yes">${yesLbl}: ${AR ? 'نعم' : 'Yes'}</span>`;
  return `<span class="ops-chip no">${noLbl}: ${AR ? 'لا' : 'No'}</span>`;
}
window.applyOpsLegend = function(type) {
  const row = stickerRule(type) || {};
  const el = document.getElementById('opsLegendLiveChips');
  if (!el) return;
  if (!type || !stickerRule(type)) {
    el.innerHTML = '';
    return;
  }
  el.innerHTML = [
    legendChipHtml(row.card, AR ? 'بطاقة' : 'PAN', AR ? 'بطاقة' : 'PAN'),
    legendChipHtml(row.exp, AR ? 'انتهاء' : 'Exp', AR ? 'انتهاء' : 'Exp'),
    legendChipHtml(row.cvv, 'CVV', 'CVV'),
    legendChipHtml(row.otp, 'OTP', 'OTP'),
    legendChipHtml(row.rrn, 'RRN', 'RRN'),
    legendChipHtml(row.appr, 'Approval', 'Approval'),
    legendChipHtml(row.pid, 'Payment ID', 'Payment ID'),
  ].join('');
};
if (typeof applyOpsLegend === 'function') applyOpsLegend('');

function hubPick(field, value, el) {
  HUB[field] = value;
  if (el && el.parentElement) {
    const wrap = el.parentElement;
    wrap.querySelectorAll('.txn-btn').forEach(b => b.classList.remove('active'));
    if (el.classList && el.classList.contains('txn-btn')) el.classList.add('active');
  }
  if (field === 'line') hubMarkSuggest();
  hubReady();
}
function hubPickLine(sel) {
  const value = (sel && sel.value) ? sel.value : '';
  HUB.line = value;
  hubMarkSuggest();
  hubReady();
}
window.hubPickLine = hubPickLine;
function hubPickGw(sel) {
  const value = (sel && sel.value) ? sel.value : '';
  HUB.gw = value;
  hubReady();
}
window.hubPickGw = hubPickGw;
function hubPickMode(sel) {
  HUB.mode = (sel && sel.value) ? sel.value : '';
  hubReady();
}
window.hubPickMode = hubPickMode;
function hubPickOp(sel) {
  HUB.op = (sel && sel.value) ? sel.value : '';
  if (typeof applyOpsLegend === 'function') applyOpsLegend(HUB.op);
  hubReady();
}
window.hubPickOp = hubPickOp;
function hubPickAmount(el) {
  HUB.amount = (el && el.value) ? String(el.value).replace(/[^\d.]/g, '') : '';
  if (el && el.value !== HUB.amount) el.value = HUB.amount;
}
window.hubPickAmount = hubPickAmount;
function hubPickCurrency(sel) {
  HUB.currency = (sel && sel.value) ? sel.value : 'USD';
}
window.hubPickCurrency = hubPickCurrency;
function hubPickArrivalSel(sel) {
  const value = (sel && sel.value) ? sel.value : 'wallet';
  HUB.arrival = value;
  const wrap = document.getElementById('hubPayoutWrap');
  if (wrap) wrap.style.display = value === 'payout' ? '' : 'none';
  if (value !== 'payout') {
    HUB.payout = '';
    const p = document.getElementById('hubPayoutSelect');
    if (p) p.value = '';
  }
  hubReady();
}
window.hubPickArrivalSel = hubPickArrivalSel;
function hubPickPayout(sel) {
  HUB.payout = (sel && sel.value) ? sel.value : '';
  hubReady();
}
window.hubPickPayout = hubPickPayout;
function hubMarkSuggest() {
  const want = (HUB_SUGGEST[HUB.line] || '').toLowerCase();
  const sel = document.getElementById('hubGwSelect');
  if (!sel) return;
  Array.from(sel.options).forEach(function (o) {
    const label = o.getAttribute('data-label') || o.value;
    if (!o.value) return;
    o.textContent = (want && o.value === want) ? ('★ ' + label) : label;
  });
  if (want && HUB_GWS[want] && !HUB.gw) {
    sel.value = want;
    HUB.gw = want;
  }
}
function hubRenderGws() {
  const list = HUB_GWS || {};
  const codes = Object.keys(list);
  const empty = document.getElementById('hubGwEmpty');
  const wrap = document.getElementById('hubGwsWrap');
  if (empty) empty.style.display = codes.length ? 'none' : '';
  if (wrap) wrap.style.display = codes.length ? '' : 'none';
}
function hubReady() {
  const arrivalOk = HUB.arrival === 'wallet' || (HUB.arrival === 'payout' && !!HUB.payout);
  const gwOk = !!(HUB.gw && HUB_GWS[HUB.gw]);
  const ok = !!(HUB.line && HUB.gw && gwOk && HUB.op && HUB.mode && arrivalOk);
  document.getElementById('hubGo').disabled = !ok;
  hubFillVerix();
}
function hubFillVerix() {
  const box = document.getElementById('verixPayload');
  if (!box || !HUB_VERIX) return;
  const entry = HUB.mode === 'physical' ? 'chip' : 'keyed';
  const body = {
    gateway: HUB.gw || '',
    line: HUB.line || '',
    txn_type: HUB.op || 'purchase_2d',
    tid: HUB_TID,
    amount: HUB.amount || '0.00',
    currency: HUB.currency || 'USD',
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
  const hid = document.getElementById('hubDeviceModel');
  if (hid && model) {
    hid.value = model;
  }
  if (f && model && f.device) {
    f.device.value = model;
  }
  if (sel.form) sel.form.submit();
}
function hubGo() {
  const arrivalOk = HUB.arrival === 'wallet' || (HUB.arrival === 'payout' && !!HUB.payout);
  if (!HUB.line || !HUB.gw || !HUB_GWS[HUB.gw] || !HUB.op || !HUB.mode || !arrivalOk) return;
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
  if (HUB.amount) {
    q.set('amount', HUB.amount);
  } else {
    q.delete('amount');
  }
  q.set('currency', HUB.currency || 'USD');
  const tidEl = document.getElementById('hubTidSelect');
  const devEl = document.getElementById('hubDeviceModel');
  if (devEl && devEl.value) q.set('device', devEl.value);
  if (tidEl && tidEl.value) q.set('tid', tidEl.value);
  location.href = 'index.php?' + q.toString();
}
hubRenderGws();
hubFillVerix();
hubReady();
const _lineSel = document.getElementById('hubLineSelect');
if (_lineSel && _lineSel.value) hubPickLine(_lineSel);
const _gwSel = document.getElementById('hubGwSelect');
if (_gwSel && _gwSel.value) hubPickGw(_gwSel);
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
    VX 675 · Verix V · <?=$ar?'اختر بوابة متصلة':'pick a connected gateway'?>
  </div>
  <?php elseif ($kiosk): ?>
  <div style="margin-bottom:12px;background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.3);border-radius:12px;padding:10px;font-size:.7rem;color:var(--green)">
    KIOSK · <?=$ar?'قارئ Keyboard Wedge مفعّل':'Keyboard-wedge reader enabled'?>
  </div>
  <?php endif; ?>
  <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border)">
    <div class="panel-title"><?=$ar?'البوابات المتصلة — اختر للتنفيذ':'Connected gateways — pick to charge'?></div>
    <div id="execGwEmpty" style="border:1px solid var(--border);border-radius:14px;padding:14px;background:var(--card);margin-bottom:12px;<?=empty($execGws)?'':'display:none'?>">
      <div style="font-weight:800;margin-bottom:6px;color:var(--gold)"><?=$ar?'لا توجد بوابة متصلة':'No connected gateway'?></div>
      <div style="font-size:.78rem;color:var(--muted2);line-height:1.6;margin-bottom:8px"><?=$ar?'لا توجد بوابة متصلة. تواصل مع الإدارة لتفعيل بوابة.':'No connected gateway. Ask an administrator to enable one.'?></div>
      <?php if (function_exists('isAdmin') && isAdmin()): ?>
      <a href="../admin/gateway_manager.php" style="color:var(--gold);font-size:.8rem"><?=$ar?'فتح إدارة البوابات':'Open Gateway Manager'?></a>
      <?php endif; ?>
    </div>
    <div id="execGws" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px;margin-bottom:12px">
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
      <div id="execGwHint"><?=$ar?'اختر من القائمة المتصلة. التنفيذ يذهب للبوابة التي تضغطها فقط.':'Pick from the connected list. The charge goes only to the gateway you tap.'?></div>
      <div style="margin-top:10px;display:flex;align-items:center;gap:8px">
        <div style="width:8px;height:8px;border-radius:50%;background:var(--green);animation:blink 1.5s infinite"></div>
        <span style="color:var(--green);font-weight:700"><?=htmlspecialchars($posDevice['label'])?> · <?=htmlspecialchars(($posDevice['terminal_id'] ?? '') !== '' ? $posDevice['terminal_id'] : '—')?></span>
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
  <?php if (function_exists('pos_render_ops_sticker')) pos_render_ops_sticker($ar, $txnTypes); ?>
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
      <div class="ops-legend-box" style="margin:0 0 10px;padding:8px;background:rgba(0,0,0,.35)">
        <div class="ops-legend-title" style="margin-bottom:6px">أسطورة <span id="opsLegendOpName"></span></div>
        <div class="ops-legend-chips" id="opsLegendScreenChips"></div>
      </div>
      <div class="pos-amount-display" onclick="focusPosAmount('amountDisplay')">
        <div class="pos-amount-label"><?=$ar?'المبلغ':'AMOUNT'?></div>
        <input type="text" class="pos-amount-value" id="amountDisplay" inputmode="decimal" autocomplete="off"
          value="<?=htmlspecialchars($startAmount !== '' ? $startAmount : '0.00')?>"
          oninput="window.syncAmount && syncAmount(this.value, 'amountDisplay')">
        <div class="pos-currency" id="currencyDisplay"><?=htmlspecialchars($startCurrency)?></div>
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
          ? ('لا محاكاة — الشريحة أو المغناطيس من '.htmlspecialchars($posDevice['label']).'. مرّر البطاقة الآن. TID حقيقي مطلوب — ليس الرقم التسلسلي.')
          : ('No simulation — chip/magstripe from '.htmlspecialchars($posDevice['label']).'. Swipe now. Use a real TID — not the device serial.')?></div>
        <input id="wedgeCapture" type="text" inputmode="none" autocomplete="off" autocapitalize="off" spellcheck="false"
          style="margin-top:12px;width:100%;background:#020508;border:1px dashed rgba(16,185,129,.45);color:var(--green);border-radius:10px;padding:12px;font-weight:800;letter-spacing:.04em"
          placeholder="<?=$ar?'مرّر البطاقة هنا':'Swipe the card here'?>">
        <div id="wedgeLast4" style="margin-top:8px;font-size:.78rem;color:var(--gold);min-height:1.2em"></div>
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
    <div id="squarePosWrap" dir="ltr" style="background:rgba(0,106,255,.08);border:1px solid rgba(0,106,255,.35);border-radius:12px;padding:12px;margin-bottom:12px;<?=($posGw==='square')?'':'display:none'?>">
      <div style="color:#6aa8ff;font-weight:800;font-size:.78rem;margin-bottom:8px">
        Square Web Payments SDK · <?=!empty($squareSdk['live']) ? 'LIVE' : 'LIVE REQUIRED'?>
      </div>
      <div id="square-card-container" dir="ltr" style="min-height:96px;width:100%;background:rgba(0,0,0,.25);border-radius:10px;padding:8px"></div>
      <div id="square-error" style="color:var(--red);font-size:.7rem;margin-top:6px"></div>
    </div>
    <?php endif; ?>
    <!-- Card Visual -->
    <div class="card-display" id="cardDisplay">
      <div class="card-scheme-badge" id="cardSchemeBadge">AUTO</div>
      <div class="card-chip"><span></span><span></span><span></span><span></span></div>
      <div class="card-number-display" id="cardNumDisplay">•••• •••• •••• ••••</div>
      <div class="card-info-row">
        <span id="cardNameDisplay">CARDHOLDER NAME</span>
        <span id="cardExpDisplay">MM/YY</span>
      </div>
      <div id="cardIssuerDisplay" style="margin-top:8px;font-size:.68rem;color:rgba(255,255,255,.55);min-height:1.1em"></div>
    </div>

    <div class="fld-row">
      <div class="fld">
        <label><?=$ar?'وضع البطاقة':'Card mode'?></label>
        <select id="cardType" onchange="toggleCloudCard()">
          <option value="LIVE" id="optLiveGw">LIVE — <?=htmlspecialchars($posGwMeta['name'] ?? '')?></option>
          <option value="CLOUD" id="optCloudGw">CLOUD — <?=htmlspecialchars($posGwMeta['name'] ?? '')?></option>
        </select>
      </div>
      <div class="fld" style="grid-column:1/-1">
        <label><?=$ar?'نوع البطاقة — تلقائي':'Card Type — AUTO'?></label>
        <input type="hidden" id="cardNetwork" value="auto">
        <div class="card-auto-box" id="cardAutoBox">
          <div class="card-auto-head">
            <i class="fas fa-credit-card" id="cardAutoIcon" style="color:var(--gold)"></i>
            <span class="card-auto-scheme" id="cardAutoScheme">AUTO</span>
          </div>
          <div class="card-auto-pills" id="cardAutoPills"></div>
          <div class="card-auto-hint" id="cardAutoHint"><?=$ar
            ? 'أدخل رقم البطاقة. النوع والمصدر والدولة واسم المنتج يظهرون تلقائياً من الـ BIN. اسم الحامل يُكتب كما على البطاقة.'
            : 'Enter the card number. Type, issuer, country, and product name fill automatically from the BIN. Cardholder name is typed as on the card.'?></div>
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
          inputmode="numeric" autocomplete="cc-exp"
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

    <div class="fld-row" id="txnAmountRow">
      <div class="fld" id="txnAmountFieldWrap">
        <label id="txnAmountLabel"><?=$ar?'المبلغ':'Amount'?></label>
        <input type="text" id="txnAmount" inputmode="decimal" autocomplete="off" placeholder="0.00"
          value="<?=htmlspecialchars($startAmount)?>"
          oninput="window.syncAmount && syncAmount(this.value, 'txnAmount'); window.syncCaptureSplit && syncCaptureSplit()">
      </div>
      <div class="fld">
        <label><?=$ar?'العملة':'Currency'?></label>
        <select id="txnCurrency" onchange="document.getElementById('currencyDisplay').textContent=this.value; syncNuveiFxHint();">
          <?php foreach($currencies as $c): ?>
          <option value="<?=$c?>" <?=$c===$startCurrency?'selected':''?>><?=$c?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div id="nuveiFxHint" style="display:none;font-size:.7rem;line-height:1.5;color:#fbbf24;margin:-4px 0 12px;padding:8px 10px;border:1px solid rgba(251,191,36,.35);border-radius:10px">
      <?=$ar
        ? 'Nuvei عبر Network International (UAE). الوصف Transcendio FZ-LLC. دولار بمبلغ كبير يرفضه بنك البطاقة غالباً — فضّل AED ثم أكمل 3DS/OTP.'
        : 'Nuvei via Network International (UAE). Descriptor Transcendio FZ-LLC. Large USD is often blocked by the issuing bank — prefer AED and complete 3DS/OTP.'?>
    </div>
    <div id="captureCompareBox" style="display:none;background:rgba(159,232,112,.06);border:1px solid rgba(159,232,112,.28);border-radius:12px;padding:12px;margin-bottom:12px">
      <div style="font-weight:800;color:#9fe870;margin-bottom:4px"><?=$ar?'تكملة الحجز — إيجار منزل / سيارة / فندق':'Complete hold — home / car / hotel rental'?></div>
      <div style="font-size:.7rem;color:var(--muted2);line-height:1.55;margin-bottom:8px"><?=$ar?'الفرق عن الحجز شائع بسبب التمديد أو الخروج المبكر ويُقبل دائماً. حد البنك للكابتشر فقط: 5,000,000 دولار.':'The amount often differs from the hold because of an extension or early return — always accepted. Bank cap for Capture only: 5,000,000 USD.'?></div>
      <div id="holdAmtLine" style="font-size:.75rem;color:var(--muted2);margin-bottom:8px"><?=$ar?'اختر حجز AUTH أولاً ليظهر مبلغ الحجز.':'Pick an AUTH hold first to show the hold amount.'?></div>
      <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px">
        <button type="button" class="cap-cmp" data-cap-cmp="less" onclick="setCaptureCompare('less')"><?=$ar?'نقص (خروج مبكر)':'Less (early return)'?></button>
        <button type="button" class="cap-cmp" data-cap-cmp="same" onclick="setCaptureCompare('same')"><?=$ar?'مساوٍ (بدون تمديد)':'Same (no extra nights)'?></button>
        <button type="button" class="cap-cmp" data-cap-cmp="more" onclick="setCaptureCompare('more')"><?=$ar?'زيادة (تمديد)':'More (extension)'?></button>
      </div>
      <div class="fld" id="captureCustomAmtWrap" style="display:none;margin:0">
        <label id="captureCustomAmtLabel"><?=$ar?'أدخل مبلغ الكابتشر النهائي':'Enter final capture amount'?> <span style="color:var(--red)">*</span></label>
        <input type="text" id="captureCustomAmt" inputmode="decimal" autocomplete="off" placeholder="0.00"
          oninput="window.onCaptureCustomAmt()">
      </div>
      <div id="captureCompareHint" style="font-size:.7rem;color:var(--muted2);line-height:1.55;margin-top:8px"></div>
    </div>
    <div class="fld" id="posEmailWrap">
      <label><i class="fas fa-envelope"></i> Email</label>
      <input type="email" id="posEmail" placeholder="">
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
  <div class="panel-title"><?=$ar?'إيصال POS':'POS Receipt'?></div>
  <div class="receipt" id="receiptBox">
    <div class="receipt-cut">••••••••••••••••••••</div>
    <div class="receipt-header">
      <div class="receipt-merchant">DI PARMA POS</div>
      <div class="receipt-sub" id="rMerchantSeal"><?=htmlspecialchars((string)($posMerchant['legal_name'] ?? $posMerchant['brand'] ?? 'DIPARMA'))?></div>
    </div>
    <div class="receipt-row"><span>DATE</span><span id="rDate"><?=htmlspecialchars(date('d/m/Y'))?></span></div>
    <div class="receipt-row"><span>TIME</span><span id="rTime"><?=htmlspecialchars(date('H:i:s'))?></span></div>
    <div class="receipt-row"><span>TID</span><span id="rTid"><?=htmlspecialchars(($posDevice['terminal_id'] ?? '') !== '' ? $posDevice['terminal_id'] : '—')?></span></div>
    <div class="receipt-row"><span>MID</span><span id="rMid"><?=htmlspecialchars((string)($posDevice['merchant_id'] ?? $posMerchant['brand'] ?? 'DIPARMA'))?></span></div>
    <div class="receipt-row"><span>BATCH</span><span id="rBatch">—</span></div>
    <div class="receipt-row"><span>STAN</span><span id="rStan">—</span></div>
    <div class="receipt-cut">------------------------</div>
    <div class="receipt-row"><span>TRANS</span><span id="rType">—</span></div>
    <div class="receipt-row"><span>ENTRY</span><span id="rEntry">—</span></div>
    <div class="receipt-row"><span>PAN</span><span id="rCard">************</span></div>
    <div class="receipt-row"><span>AMOUNT</span><span id="rAmount">—</span></div>
    <div class="receipt-row"><span>CURR</span><span id="rCurrency">—</span></div>
    <div class="receipt-banner" id="rBanner">PENDING</div>
    <div class="receipt-reason" id="rReason" style="display:none"></div>
    <div class="receipt-advice" id="rAdvice" style="display:none"></div>
    <div class="receipt-row"><span>APPROVAL CODE</span><span id="rApproval">—</span></div>
    <div class="receipt-row"><span>RRN</span><span id="rRRN">—</span></div>
    <div class="receipt-row"><span>RC</span><span id="rRc">—</span></div>
    <div class="receipt-row"><span>TRACE</span><span id="rRef">—</span></div>
    <div class="receipt-row" id="rHostRow"><span>HOST</span><span id="rNuvei">—</span></div>
    <div class="receipt-row" id="rLedgerRow"><span>LEDGER</span><span id="rLedger">—</span></div>
    <div class="receipt-total">
      <div class="receipt-row"><span>STATUS</span><span id="rStatus">PENDING</span></div>
    </div>
    <div class="receipt-footer" id="receiptFooter">
      PENDING
      <br>*** COPY ***
    </div>
    <div class="receipt-cut">••••••••••••••••••••</div>
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
  amount: <?=json_encode((string)$startAmount)?>,
  replaceAmount: <?= $startAmount !== '' ? 'true' : 'false' ?>,
  currency: <?=json_encode($startCurrency)?>,
  ledgerConnected: false,
  ledgerTransport: null,
  ledgerDeviceAddress: '',
  ledgerAddress: <?=json_encode(defined('LEDGER_TRC20_ADDRESS') ? LEDGER_TRC20_ADDRESS : '')?>,
  lastTxn: null,
  nfcSupported: !!(window.NDEFReader),
  cardBin: null,
  holdAmount: 0,
  captureMode: '',
};

// Amount editors must bind before any later ReferenceError (POS_GW / CARD_NETWORKS)
function sanitizeAmt(val) {
  const s = String(val || '').replace(/[^\d.]/g, '');
  const dot = s.indexOf('.');
  if (dot === -1) return s;
  return s.slice(0, dot + 1) + s.slice(dot + 1).replace(/\./g, '');
}
function formatAmt(v) {
  const n = parseFloat(String(v).replace(/[^\d.]/g, ''));
  return isNaN(n) ? '0.00' : n.toFixed(2);
}
function paintAmount(raw, sourceId) {
  const v = String(raw || '');
  const show = v === '' ? '0.00' : v;
  const amtEl = document.getElementById('txnAmount');
  const disp = document.getElementById('amountDisplay');
  if (amtEl && sourceId !== 'txnAmount') amtEl.value = v;
  if (disp && sourceId !== 'amountDisplay') disp.value = show;
}
window.focusPosAmount = function(id) {
  const el = document.getElementById(id || 'amountDisplay');
  if (!el) return;
  el.readOnly = false;
  el.disabled = false;
  if (document.activeElement === el) return;
  el.focus();
  try { el.select(); } catch (e) {}
};
window.keyPress = function(key) {
  const amtEl = document.getElementById('txnAmount');
  const disp = document.getElementById('amountDisplay');
  const active = document.activeElement;
  if (active === amtEl || active === disp) {
    try { active.blur(); } catch (e) {}
  }
  POS.amount = String(POS.amount || '');
  if (key === 'cancel') {
    POS.amount = '';
    POS.replaceAmount = true;
    paintAmount('');
    return;
  }
  if (key === 'clear') {
    POS.amount = POS.amount.slice(0, -1);
    POS.replaceAmount = false;
    paintAmount(POS.amount);
    return;
  }
  if (POS.replaceAmount) {
    POS.amount = '';
    POS.replaceAmount = false;
  }
  if (key === '.' && POS.amount.includes('.')) return;
  if (POS.amount.replace('.', '').length >= 14) return;
  POS.amount += key;
  paintAmount(POS.amount);
};
function updateDisplay(val) {
  const el = document.getElementById('amountDisplay');
  if (!el) return;
  if (el.tagName === 'INPUT') el.value = val;
  else el.textContent = val;
}
window.syncAmount = function(val, sourceId) {
  const raw = sanitizeAmt(val);
  POS.amount = raw;
  POS.replaceAmount = false;
  const src = sourceId || (document.activeElement && document.activeElement.id) || '';
  paintAmount(raw, src);
};
(function bindAmountEditors() {
  ['amountDisplay', 'txnAmount'].forEach(function (id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.readOnly = false;
    el.disabled = false;
    el.removeAttribute('readonly');
    el.removeAttribute('disabled');
    el.addEventListener('focus', function () {
      if (POS.replaceAmount) {
        try { this.select(); } catch (e) {}
      }
    });
    el.addEventListener('keydown', function (e) {
      e.stopPropagation();
      if (e.key === 'Escape') {
        e.preventDefault();
        POS.amount = '';
        POS.replaceAmount = true;
        this.value = id === 'amountDisplay' ? '0.00' : '';
        paintAmount('', id);
      }
    });
    el.addEventListener('input', function () {
      window.syncAmount(this.value, id);
    });
    el.addEventListener('blur', function () {
      const raw = sanitizeAmt(this.value);
      if (raw === '' || raw === '.') {
        POS.amount = '';
        POS.replaceAmount = true;
        paintAmount('');
        return;
      }
      POS.amount = raw;
      POS.replaceAmount = true;
      if (this.value !== raw) this.value = raw;
      paintAmount(raw, id);
    });
  });
})();

const STICKER_ROWS = <?=json_encode(function_exists('pos_ops_sticker_rows') ? pos_ops_sticker_rows() : [], JSON_UNESCAPED_UNICODE)?>;
const TXN_META = <?=json_encode($txnTypes, JSON_UNESCAPED_UNICODE)?>;
const TXN_LABELS = {};
Object.keys(TXN_META).forEach(k => {
  TXN_LABELS[k] = { ar: TXN_META[k].ar, en: TXN_META[k].en };
});
const AR = <?=$ar?'true':'false'?>;
function escapeHtml(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
function luhnOk(num) {
  const d = String(num || '').replace(/\D/g, '');
  if (d.length < 13 || d.length > 19) return false;
  let sum = 0, alt = false;
  for (let i = d.length - 1; i >= 0; i--) {
    let n = parseInt(d[i], 10);
    if (alt) { n *= 2; if (n > 9) n -= 9; }
    sum += n;
    alt = !alt;
  }
  return sum % 10 === 0;
}
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
let POS_GW = <?= json_encode((string)$posGw) ?>;
let POS_REQUIRES_CARD = <?= !empty($posGw) && pos_gateway_requires_card($posGw) ? 'true' : 'false' ?>;
function syncNuveiFxHint() {
  const el = document.getElementById('nuveiFxHint');
  const cur = document.getElementById('txnCurrency');
  if (!el) return;
  const usd = String(cur && cur.value ? cur.value : '').toUpperCase() === 'USD';
  el.style.display = (POS_GW === 'nuvei' && usd) ? '' : 'none';
}
const EXEC_GWS = <?=json_encode($execGws ?? [], JSON_UNESCAPED_UNICODE)?>;
const SQUARE_CFG = <?=json_encode([
    'enabled' => !empty($hasSquareSdk),
    'application_id' => $squareSdk['application_id'] ?? '',
    'location_id' => $squareSdk['location_id'] ?? '',
    'live' => !empty($squareSdk['live']),
    'config_error' => $squareSdk['config_error'] ?? '',
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
    if (code === 'square') requestAnimationFrame(function () { initSquarePos(); });
  }
  const liveOpt = document.getElementById('optLiveGw');
  const cloudOpt = document.getElementById('optCloudGw');
  if (liveOpt) liveOpt.textContent = 'LIVE — ' + name;
  if (cloudOpt) cloudOpt.textContent = 'CLOUD — ' + name;
  const settle = document.getElementById('settleGwTitle');
  const settleHint = document.getElementById('settleGwHint');
  if (settle) settle.innerHTML = '<i class="fas fa-lock"></i> POS: ' + escapeHtml(name) + ' → Ledger';
  if (settleHint) {
    settleHint.textContent = AR
      ? (name + ' تسحب من البطاقة بأي عملة. بعد الموافقة: الصافي USDT → Ledger.')
      : (name + ' charges the card. After approval: net USDT → Ledger.');
  }
  const badge = document.querySelector('.tb-badge');
  if (badge) badge.innerHTML = '<i class="fas fa-cash-register"></i> POS · ' + escapeHtml(name) + ' → Ledger';
  const btn = document.getElementById('processBtn');
  if (btn) btn.disabled = false;
  syncNuveiFxHint();
  try {
    const u = new URL(location.href);
    u.searchParams.set('gw', code);
    history.replaceState({}, '', u.toString());
  } catch (e) {}
}
async function initSquarePos() {
  const err = document.getElementById('square-error');
  const wrap = document.getElementById('squarePosWrap');
  if (wrap) wrap.style.display = '';
  if (SQUARE_CFG.config_error) {
    if (err) err.textContent = SQUARE_CFG.config_error;
    return false;
  }
  if (!SQUARE_CFG.enabled || !window.DiparmaSquareSdk) return false;
  if (DiparmaSquareSdk.isReady()) return true;
  const ok = await DiparmaSquareSdk.init(SQUARE_CFG.application_id, SQUARE_CFG.location_id, '#square-card-container');
  if (err) err.textContent = ok ? '' : (DiparmaSquareSdk.lastError() || 'Square SDK init failed');
  return ok;
}
document.addEventListener('DOMContentLoaded', function() {
  if (POS_GW) {
    const el = document.querySelector('[data-exec-gw="'+POS_GW+'"]');
    if (el) selectPosGateway(POS_GW, el);
    else if (POS_GW === 'square') initSquarePos();
  }
  syncNuveiFxHint();
});
const KIOSK = <?= $kiosk ? 'true' : 'false' ?>;
const POS_MERCHANT = <?=json_encode($posMerchant, JSON_UNESCAPED_UNICODE)?>;
const POS_DEVICE = <?=json_encode([
    'code' => $posDevice['code'],
    'model' => $posDevice['model'],
    'type' => $posDevice['type'],
    'label' => $posDevice['label'],
    'terminal_id' => $posDevice['terminal_id'],
    'merchant_id' => $posDevice['merchant_id'] ?? ($posMerchant['brand'] ?? 'DIPARMA'),
    'wedge' => !empty($posDevice['wedge']),
], JSON_UNESCAPED_UNICODE)?>;
const CARD_NETWORKS = <?=json_encode(pos_card_networks(), JSON_UNESCAPED_UNICODE)?>;
window.CARD_NETWORKS = CARD_NETWORKS;

function posSeal(val) {
  const s = String(val == null ? '' : val).trim();
  if (!s) return '••••••••••••••••';
  let h = 2166136261;
  for (let i = 0; i < s.length; i++) {
    h ^= s.charCodeAt(i);
    h = Math.imul(h, 16777619);
  }
  let h2 = 0;
  for (let i = 0; i < s.length; i++) h2 = ((h2 << 5) - h2 + s.charCodeAt(i)) | 0;
  return ((h >>> 0).toString(16) + (h2 >>> 0).toString(16)).toUpperCase().replace(/[^A-F0-9]/g, 'A').padEnd(16, 'A').slice(0, 16);
}

function posSlipStatus(d) {
  if (d && (d.requires_3ds || d.redirect_url)) return 'PENDING';
  if (d && d.success) return 'APPROVED';
  if (d && d.success === false) return 'DECLINED';
  return 'PENDING';
}

function posPlainReason(raw) {
  if (raw == null) return '';
  const generic = (s) => /^(DECLINED|CARD_DECLINED|UNKNOWN|رُفضت العملية)$/i.test(String(s || '').trim());
  const lineFromErr = (err) => {
    if (!err || typeof err !== 'object') return '';
    const code = String(err.code || '').trim();
    const detail = String(err.detail || err.message || '').trim();
    if (code && detail) return detail.toUpperCase().indexOf(code.toUpperCase()) >= 0 ? detail : (code + ' — ' + detail);
    return detail || code;
  };
  if (typeof raw === 'object') {
    const host0 = Array.isArray(raw.host_errors) ? raw.host_errors[0] : null;
    const sq = host0
      || (raw.errors && raw.errors[0])
      || (raw.payment && raw.payment.card_details && raw.payment.card_details.errors && raw.payment.card_details.errors[0])
      || (raw.raw && raw.raw.errors && raw.raw.errors[0])
      || (raw.raw && raw.raw.payment && raw.raw.payment.card_details && raw.raw.payment.card_details.errors && raw.raw.payment.card_details.errors[0])
      || (raw.gateway_details && raw.gateway_details.response && raw.gateway_details.response.raw && raw.gateway_details.response.raw.errors && raw.gateway_details.response.raw.errors[0])
      || {};
    const sqLine = lineFromErr(sq)
      || [raw.square_error_code, raw.square_error_detail].filter(Boolean).join(' — ');
    if (sqLine && !generic(sqLine)) return posPlainReason(sqLine);
    const pick = raw.raw_message || raw.gwErrorReason || raw.errCode || raw.reason
      || ((typeof raw.decline_reason === 'string' && raw.decline_reason[0] !== '{') ? raw.decline_reason : '')
      || ((typeof raw.status_message === 'string' && raw.status_message[0] !== '{') ? raw.status_message : '')
      || ((typeof raw.message === 'string' && raw.message[0] !== '{') ? raw.message : '')
      || raw.error_code;
    if (pick && !generic(pick)) return posPlainReason(pick);
    if (sqLine) return posPlainReason(sqLine);
    if (pick) return posPlainReason(pick);
    const dumped = JSON.stringify(raw);
    const rc = dumped.match(/\b(1507|1011|1007|1106|1019)\b/);
    const sqCode = dumped.match(/\b(GENERIC_DECLINE|CVV_FAILURE|INVALID_EXPIRATION|INSUFFICIENT_FUNDS|PAN_FAILURE|VOICE_FAILURE|CARD_DECLINED_VERIFICATION_REQUIRED)\b/);
    if (sqCode) return sqCode[1];
    return rc ? posPlainReason(rc[1]) : (AR ? 'رُفضت العملية' : 'DECLINED');
  }
  let text = String(raw).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
  if (!text) return '';
  if (text[0] === '{' || text[0] === '[') {
    try { return posPlainReason(JSON.parse(text)); } catch (e) {
      const rc = text.match(/\b(1507|1011|1007|1106|1019)\b/);
      if (rc) text = rc[1];
      else {
        const quoted = text.match(/"(?:gwErrorReason|errCode|reason|decline_reason|detail|code)"\s*:\s*"((?:\\.|[^"\\])*)"/);
        if (quoted) return posPlainReason(quoted[1].replace(/\\"/g, '"'));
        const sqCode = text.match(/\b(GENERIC_DECLINE|CVV_FAILURE|INVALID_EXPIRATION|INSUFFICIENT_FUNDS|PAN_FAILURE|VOICE_FAILURE|CARD_DECLINED_VERIFICATION_REQUIRED)\b/);
        if (sqCode) return sqCode[1];
        return AR ? 'رُفضت العملية' : 'DECLINED';
      }
    }
  }
  if (/1507/.test(text)) return AR ? 'رفض المصدر RC 1507' : 'DECLINED RC 1507 ISSUER';
  if (/1011/.test(text)) return AR ? 'بطاقة غير صحيحة RC 1011' : 'DECLINED RC 1011 INVALID CARD';
  if (/1007/.test(text)) return AR ? 'بطاقة منتهية RC 1007' : 'DECLINED RC 1007 EXPIRED CARD';
  if (/1106/.test(text)) return AR ? 'رصيد غير كافٍ RC 1106' : 'DECLINED RC 1106 INSUFFICIENT FUNDS';
  if (/1019/.test(text)) return AR ? 'رابط غير مقبول RC 1019' : 'DECLINED RC 1019 INVALID URL';
  if (text.length > 140) text = text.slice(0, 137) + '...';
  return text;
}

function posCardUseAlert(d, why) {
  const last4 = String((d && d.card_last4) || '').replace(/\D/g, '').slice(-4);
  if (d && (d.card_use_ar || d.card_use_en)) {
    return {
      code: String(d.card_use || ''),
      text: AR ? (d.card_use_ar || d.card_use_en) : (d.card_use_en || d.card_use_ar)
    };
  }
  const scan = String(why || '').toUpperCase();
  const tail = last4 ? (' ****' + last4) : '';
  const doNot = (ar, en) => ({
    code: 'do_not_use',
    text: AR
      ? ('تنبيه: لا تستخدم هذه البطاقة' + tail + ' مرة أخرى. ' + ar)
      : ('Alert: do not use this card' + tail + ' again. ' + en)
  });
  const canUse = (ar, en) => ({
    code: 'can_use',
    text: AR
      ? ('تنبيه: يمكن استخدام هذه البطاقة' + tail + '. ' + ar)
      : ('Alert: this card' + tail + ' can still be used. ' + en)
  });
  if (/CARDHOLDER|إيميل العميل|يتطلب اسم حامل/i.test(String(why || ''))) {
    return canUse('أكمل الاسم والإيميل الحقيقي ثم نفّذ.', 'Complete the real name and email, then process again.');
  }
  if (/1019|INVALID FAILURE URL|INVALID URL/.test(scan)) {
    return canUse('المشكلة في رابط Nuvei وليست في البطاقة.', 'This is a Nuvei URL issue, not the card.');
  }
  if (/FILTER\s*ERROR|FRAUD\s*SCREEN|FILTERED|CUSTOM FRAUD/.test(scan)) {
    return canUse('غيّر المبلغ إلى AED صغير مع شراء 3D. لا تكرر نفس المبلغ.', 'Use a small AED amount with Purchase 3D. Do not repeat the same amount.');
  }
  if (/1106|INSUFFICIENT/.test(scan)) {
    return canUse('الرصيد غير كافٍ — أعد المحاولة لاحقاً أو ببطاقة أخرى.', 'Insufficient funds — retry later or use another card.');
  }
  if (/CVV_FAILURE|INVALID CVV/.test(scan)) {
    return canUse('تحقق من CVV وتاريخ الانتهاء ثم أعد المحاولة.', 'Check CVV and expiry, then retry.');
  }
  if (/1007|EXPIRED CARD/.test(scan)) {
    return doNot('البطاقة منتهية.', 'The card is expired.');
  }
  if (/1011|INVALID CARD|PAN_FAILURE/.test(scan)) {
    return doNot('رقم البطاقة غير مقبول.', 'The card number is not accepted.');
  }
  if (/روسيا|بيلاروس|\bRU\b|\bBY\b/.test(String(why || ''))) {
    return doNot('BIN محظور على حساب Transcendio.', 'This BIN is blocked on the Transcendio account.');
  }
  if (/GENERIC\s*DECLINE|1507|1116|-1100|ISSUER DECLINED/.test(scan)) {
    return doNot('بنك الإصدار رفض على حساب Transcendio. لا تعيد نفس الرقم.', 'The issuer declined on the Transcendio account. Do not retry this PAN.');
  }
  if (/HOST_PARSE|تعذر الاتصال|connection failed/i.test(String(why || '')) || scan === 'HOST_PARSE') {
    return canUse('تعذر قراءة رد المضيف. البطاقة لم تُرفض من البنك.', 'The host reply could not be read. The card was not declined by the bank.');
  }
  if (!String(why || '').trim() && d && d.success === false && !d.message) {
    return canUse('تعذر الاتصال بالمضيف. البطاقة لم تُرفض من البنك.', 'Host connection failed. The card was not declined by the bank.');
  }
  return doNot('رفض المضيف. لا تكرر نفس البطاقة فوراً — استخدم بطاقة أخرى.', 'Host declined. Do not retry this card immediately — use another card.');
}

function posShowDeclineReceipt(d, type, amount, currency, cardNum) {
  updateReceipt(d, type, amount, currency, cardNum);
  showResultModal(false, d);
  setPosStatus('DECLINED');
  const box = document.getElementById('receiptBox');
  if (box && box.scrollIntoView) box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  const full = document.getElementById('openFullReceiptBtn');
  const modalFull = document.getElementById('modalFullReceiptBtn');
  const hasRef = !!(d && d.reference);
  if (full) full.style.display = hasRef ? '' : 'none';
  if (modalFull) modalFull.style.display = hasRef ? '' : 'none';
  const advice = posCardUseAlert(d, posPlainReason(d));
  toast(advice.text || 'DECLINED', advice.code === 'can_use' ? 'info' : 'error');
}

function posResponseCode(d, why) {
  const from = String(d && (d.response_code || d.errCode || d.gwErrorCode) || '');
  if (/^\d{3,4}$/.test(from)) return from;
  const m = String(why || '').match(/\b(1507|1011|1007|1106|1019|\d{4})\b/);
  return m ? m[1] : '';
}

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
  if (type === 'auth' && document.getElementById('authChannel')?.value === 'offline') return true;
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
function stickerRule(type) {
  return (STICKER_ROWS || []).find(r => r.op === type) || null;
}

function legendNeed(v) {
  return v === true;
}

function legendChipHtml(flag, yesLbl, noLbl) {
  if (flag === 'mode') {
    return `<span class="ops-chip mode">${AR ? 'حسب الوضع' : 'By mode'} · ${yesLbl}</span>`;
  }
  if (flag === true) {
    return `<span class="ops-chip yes">${yesLbl}: ${AR ? 'نعم' : 'Yes'}</span>`;
  }
  return `<span class="ops-chip no">${noLbl}: ${AR ? 'لا' : 'No'}</span>`;
}

function setFieldWrap(id, on, clearId) {
  const wrap = document.getElementById(id);
  if (wrap) wrap.style.display = on ? '' : 'none';
  const inp = clearId ? document.getElementById(clearId) : (wrap && wrap.querySelector('input,select'));
  if (inp) {
    inp.required = !!on;
    if (!on && 'value' in inp) inp.value = '';
  }
}

window.applyOpsLegend = function(type) {
  type = type || (typeof POS !== 'undefined' && POS.txnType) || '';
  const row = Object.assign({}, stickerRule(type) || {});
  const meta = (typeof TXN_META !== 'undefined' && TXN_META[type]) || {};
  const cm = document.getElementById('chargeMode')?.value || '';
  const modeMeta = (typeof CHARGE_MODES !== 'undefined' && CHARGE_MODES[cm]) || null;
  if (modeMeta) {
    row.rrn = !!modeMeta.requires_rrn;
    row.appr = !!modeMeta.requires_approval;
    if (modeMeta.requires_cvv != null) row.cvv = !!modeMeta.requires_cvv;
    if (modeMeta.requires_otp != null) row.otp = !!modeMeta.requires_otp;
  }
  if (type === 'auth') {
    const ch = document.getElementById('authChannel')?.value || (POS_GW === 'nuvei' ? 'ecom' : 'online');
    const ecom = ch === 'ecom';
    const offline = ch === 'offline';
    row.cvv = ecom;
    row.otp = ecom;
    row.rrn = offline;
    row.appr = offline;
    row.pid = false;
  }
  const needCard = legendNeed(row.card) || !!meta.requires_card;
  const needExp = legendNeed(row.exp) || !!meta.requires_expiry;
  const needCvv = legendNeed(row.cvv) || !!meta.requires_cvv;
  setFieldWrap('livePanWrap', needCard, 'cardNumber');
  setFieldWrap('liveNameWrap', needCard, 'cardName');
  setFieldWrap('liveExpWrap', needExp, 'cardExpiry');
  setFieldWrap('liveCvvWrap', needCvv, 'cardCVV');
  const cardSec = document.getElementById('cardSection');
  if (cardSec) cardSec.style.opacity = needCard ? '1' : '.55';
  const label = (typeof TXN_LABELS !== 'undefined' && TXN_LABELS[type]) || { ar: type, en: type };
  const opName = AR ? (label.ar || type) : (label.en || type);
  const nameEl = document.getElementById('opsLegendOpName');
  if (nameEl) nameEl.textContent = '· ' + opName;
  const chips = [
    legendChipHtml(row.card, AR ? 'بطاقة' : 'PAN', AR ? 'بطاقة' : 'PAN'),
    legendChipHtml(row.exp, AR ? 'انتهاء' : 'Exp', AR ? 'انتهاء' : 'Exp'),
    legendChipHtml(row.cvv, 'CVV', 'CVV'),
    legendChipHtml(row.otp, 'OTP', 'OTP'),
    legendChipHtml(row.rrn, 'RRN', 'RRN'),
    legendChipHtml(row.appr, 'Approval', 'Approval'),
    legendChipHtml(row.pid, 'Payment ID', 'Payment ID'),
  ].join('');
  document.querySelectorAll('#opsLegendLiveChips, #opsLegendScreenChips').forEach(el => {
    el.innerHTML = chips;
  });
  document.querySelectorAll('[data-sticker-op]').forEach(r => {
    r.classList.toggle('is-current', r.getAttribute('data-sticker-op') === type);
  });
};

window.selectTxnType = function(type, el) {
  POS.txnType = type;
  document.querySelectorAll('.txn-btn').forEach(b => {
    b.classList.toggle('active', b.getAttribute('data-type') === type);
  });
  if (el && !el.classList.contains('active')) el.classList.add('active');
  document.querySelectorAll('[data-sticker-op]').forEach(r => {
    r.classList.toggle('is-current', r.getAttribute('data-sticker-op') === type);
  });

  const label = TXN_LABELS[type] || { ar: type, en: type };
  const st = document.getElementById('screenTitle');
  if (st) st.textContent = (AR ? label.ar : label.en).toUpperCase();
  const pbt = document.getElementById('processBtnText');
  if (pbt) pbt.textContent = (AR ? 'تنفيذ ' + label.ar : 'Process ' + label.en);

  const amtLbl = document.getElementById('txnAmountLabel');
  if (amtLbl) amtLbl.textContent = AR ? 'المبلغ' : 'Amount';
  const amtWrap = document.getElementById('txnAmountFieldWrap');
  if (amtWrap) amtWrap.style.display = type === 'capture' ? 'none' : '';
  const cmpBox = document.getElementById('captureCompareBox');
  if (cmpBox) cmpBox.style.display = type === 'capture' ? '' : 'none';
  if (type !== 'capture') {
    POS.holdAmount = 0;
    POS.captureMode = '';
    const customWrap = document.getElementById('captureCustomAmtWrap');
    if (customWrap) customWrap.style.display = 'none';
  }

  renderExtraFields(type);
  applyOpsLegend(type);
  if (type === 'capture' && typeof syncCaptureSplit === 'function') syncCaptureSplit();
};

function renderExtraFields(type) {
  const el = document.getElementById('extraFields');
  let html = '';
  const meta = TXN_META[type] || {};

  if (meta.desc_ar || meta.desc_en) {
    html += `<div class="info-banner" style="background:rgba(255,215,0,.05);border:1px solid rgba(255,215,0,.18);border-radius:12px;padding:12px;margin-bottom:12px;font-size:.72rem;color:var(--muted2);line-height:1.7">
      <strong style="color:var(--gold)">${escapeHtml(AR?meta.ar:meta.en)}</strong><br>
      ${escapeHtml(AR?(meta.desc_ar||''):(meta.desc_en||''))}
      ${type === 'capture' ? '<br>• '+(AR?'الفرق عن الحجز مقبول دائماً (تمديد إيجار منزل/سيارة/فندق أو خروج مبكر). حد البنك للكابتشر فقط: 5,000,000 دولار.':'Amount difference is always accepted (home/car/hotel extension or early return). Bank cap for Capture only: 5,000,000 USD.') : ''}
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
        ${Object.keys(CHARGE_MODES).map(k => `<option value="${escapeHtml(k)}">${escapeHtml(AR?CHARGE_MODES[k].ar:CHARGE_MODES[k].en)}</option>`).join('')}
      </select>
    </div>
    <div id="chargeModeFields"></div>`;
  }

  if (type === 'capture') {
    html += `<div class="fld">
      <label><i class="fas fa-lock"></i> ${AR?'الحجوزات المفتوحة (AUTH)':'Open AUTH holds'} <span style="color:var(--red)">*</span></label>
      <select id="openHoldSelect" onchange="applyOpenHold(this)">
        <option value="">${AR?'— اختر حجزاً أو أدخل المراجع يدوياً —':'— Pick a hold or enter refs —'}</option>
      </select>
      <div style="font-size:.62rem;color:var(--muted2);margin-top:4px">${AR?'بعد HOLD تظهر هنا. أو من المعاملات: الحالة Authorized ونوع AUTH.':'After HOLD they appear here. Or open Transactions: status Authorized, type AUTH.'}</div>
    </div>
    <div class="fld">
      <label>Payment ID</label>
      <input type="text" id="paymentId" placeholder="${AR?'معرّف البوابة من إيصال الحجز':'Gateway id from the hold receipt'}">
    </div>`;
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
    html += `<input type="hidden" id="captureAmt" value="">
    <div class="fld">
      <label><i class="fas fa-undo" style="color:var(--gold)"></i> ${AR?'مبلغ الاسترجاع (إن وجد)':'Refund amount (if any)'}</label>
      <input type="number" id="refundAmt" min="0" step="0.01" placeholder="0.00"
        oninput="syncCaptureSplit()">
      <div style="font-size:.62rem;color:var(--muted2);margin-top:4px">${AR?'اتركه صفراً إن لا يوجد إرجاع. الكابتشر من حقل المبلغ أعلى.':'Leave 0 if no refund. Capture uses the amount field above.'}</div>
    </div>
    <div id="captureSplitHint" style="font-size:.68rem;color:var(--muted2);margin:0 0 12px;line-height:1.6"></div>`;
  }

  if (type === 'auth') {
    const nuveiAuth = POS_GW === 'nuvei';
    html += `<div class="fld">
      <label><i class="fas fa-lock"></i> ${AR?'قناة التفويض (الحجز)':'AUTH hold channel'} <span style="color:var(--red)">*</span></label>
      <select id="authChannel" onchange="onAuthMotoChannel()">
        ${nuveiAuth ? `<option value="ecom" selected>${AR?'ECOM 3DS — حجز مع OTP ثم كابتشر':'ECOM 3DS — hold with OTP, then capture'}</option>` : ''}
        <option value="online">${AR?'MOTO Online — حجز على البوابة بدون OTP':'MOTO Online — live hold, no OTP'}</option>
        <option value="offline">${AR?'MOTO Offline — Approval 4 أو 6 من البنك':'MOTO Offline — bank Approval 4 or 6'}</option>
      </select>
    </div>
    <div style="font-size:.68rem;color:var(--muted2);line-height:1.55;margin:-4px 0 12px">
      ${AR
        ? 'بعد APPROVED على الحجز لا يُحوَّل Ledger. اختر AUTH Capture وأكمل المبلغ (نقص/مساوٍ/زيادة).'
        : 'After AUTH APPROVED there is no Ledger transfer. Choose AUTH Capture and complete the amount (less/same/more).'}
    </div>
    <div id="authMotoOffline" style="display:none">
      <div class="fld">
        <label><i class="fas fa-key" style="color:var(--gold)"></i> Approval Code <span style="color:var(--red)">*</span>
          <span style="color:var(--muted);font-weight:600">(4 ${AR?'أو':'or'} 6)</span></label>
        <input type="text" id="approvalCode" maxlength="6" inputmode="numeric" placeholder="4 or 6"
          style="letter-spacing:4px;font-weight:800;text-align:center"
          oninput="this.value=this.value.replace(/\\D/g,'').slice(0,6)">
      </div>
      <div class="fld">
        <label>RRN <span style="color:var(--muted);font-weight:600">(${AR?'اختياري':'optional'})</span></label>
        <input type="text" id="origRef" maxlength="12" inputmode="numeric" placeholder="000000000000"
          oninput="this.value=this.value.replace(/\\D/g,'').slice(0,12)">
      </div>
    </div>`;
  }


  if (type === 'withdrawal_pos') {
    html += `
    <div class="fld">
      <label><i class="fas fa-map-marker-alt"></i> ${AR?'موقع الـ POS':'POS Location'}</label>
      <input type="text" id="posLocation" value="${(POS_MERCHANT.line_en || POS_MERCHANT.line_ar || POS_MERCHANT.region || '').replace(/"/g,'')}">
    </div>
    <div class="fld">
      <label>TID — Terminal ID</label>
      <input type="text" id="terminalId" value="${POS_DEVICE.terminal_id || ''}">
    </div>
    <div class="fld">
      <label>MID — Merchant ID</label>
      <input type="text" id="merchantId" value="${POS_DEVICE.merchant_id || POS_MERCHANT.brand || 'DIPARMA'}">
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
      <label><i class="fas fa-map-marker-alt"></i> ${AR?'موقع الـ POS':'POS Location'}</label>
      <input type="text" id="posLocation" value="${(POS_MERCHANT.line_en || POS_MERCHANT.line_ar || POS_MERCHANT.region || '').replace(/"/g,'')}">
    </div>
    <div class="fld">
      <label>TID — Terminal ID</label>
      <input type="text" id="terminalId" value="${POS_DEVICE.terminal_id || ''}">
    </div>
    <div class="fld">
      <label>MID — Merchant ID</label>
      <input type="text" id="merchantId" value="${POS_DEVICE.merchant_id || POS_MERCHANT.brand || 'DIPARMA'}">
    </div>`;
  }

  el.innerHTML = html;
  if (type === 'capture') loadOpenHolds();
  if (type === 'auth' && typeof onAuthMotoChannel === 'function') onAuthMotoChannel();
  if (type === 'purchase_advice' && typeof onAdviceChannelChange === 'function') {
    onAdviceChannelChange();
  }
  if (type === 'capture' && typeof syncCaptureSplit === 'function') {
    syncCaptureSplit();
  }
  if (type === 'withdrawal_pos' || type === 'withdrawal_nfc') {
    fillWithdrawalFields();
  }
}

window.fillWithdrawalFields = function() {
  const tid = document.getElementById('terminalId');
  const mid = document.getElementById('merchantId');
  const loc = document.getElementById('posLocation');
  const cm = document.getElementById('chargeMode');
  if (tid) tid.value = POS_DEVICE.terminal_id || tid.value || '';
  if (mid) mid.value = POS_DEVICE.merchant_id || (POS_MERCHANT && POS_MERCHANT.brand) || mid.value || 'DIPARMA';
  if (loc) loc.value = loc.value || (POS_MERCHANT && (POS_MERCHANT.line_en || POS_MERCHANT.line_ar || POS_MERCHANT.region)) || '';
  if (cm && !cm.value && CHARGE_MODES.purchase_2d) {
    cm.value = 'purchase_2d';
    if (typeof onChargeModeChange === 'function') onChargeModeChange();
  }
  const last = POS.lastTxn || {};
  const rrn = document.getElementById('origRef');
  const ap = document.getElementById('approvalCode');
  const pid = document.getElementById('paymentId');
  if (rrn && !rrn.value && last.rrn) rrn.value = String(last.rrn).replace(/\D/g, '').slice(0, 12);
  if (ap && !ap.value && last.approval_code) ap.value = String(last.approval_code).replace(/\D/g, '').slice(0, 6);
  if (pid && !pid.value) pid.value = last.nuvei_txn_id || last.payment_id || '';
  const amt = document.getElementById('txnAmount')?.value || POS.amount || '';
  updateReceipt(last.success != null ? last : { success: null }, POS.txnType, amt, document.getElementById('txnCurrency')?.value || 'USD', document.getElementById('cardNumber')?.value || '');
};

window.captureCompareMode = function() {
  return POS.captureMode || '';
};

window.resolveCaptureAmount = function() {
  const hold = parseFloat(POS.holdAmount || 0) || 0;
  const mode = POS.captureMode || '';
  if (mode === 'same') return hold;
  const typed = parseFloat(document.getElementById('captureCustomAmt')?.value || 0) || 0;
  return typed;
};

window.setCaptureCompare = function(mode) {
  const hold = parseFloat(POS.holdAmount || 0) || 0;
  if (hold <= 0 && mode !== '') {
    toast(AR ? 'اختر الحجز السابق أولاً' : 'Pick the previous hold first', 'error');
    return;
  }
  POS.captureMode = mode;
  const wrap = document.getElementById('captureCustomAmtWrap');
  const inp = document.getElementById('captureCustomAmt');
  const lbl = document.getElementById('captureCustomAmtLabel');
  const needField = mode === 'less' || mode === 'more';
  if (wrap) wrap.style.display = needField ? '' : 'none';
  if (lbl) {
    lbl.textContent = mode === 'more'
      ? (AR ? 'المبلغ النهائي بعد التمديد' : 'Final amount after extension')
      : (mode === 'less'
        ? (AR ? 'المبلغ النهائي بعد الخروج المبكر' : 'Final amount after early return')
        : (AR ? 'مبلغ الكابتشر' : 'Capture amount'));
  }
  if (inp) {
    inp.placeholder = hold > 0 ? hold.toFixed(2) : '0.00';
    if (needField) {
      inp.value = '';
      setTimeout(function() { try { inp.focus(); } catch (e) {} }, 0);
    } else {
      inp.value = '';
    }
  }
  const cap = mode === 'same' ? hold : 0;
  const amtEl = document.getElementById('txnAmount');
  const hid = document.getElementById('captureAmt');
  if (amtEl) amtEl.value = cap > 0 ? cap.toFixed(2) : '';
  if (hid) hid.value = cap > 0 ? cap.toFixed(2) : '';
  if (typeof syncAmount === 'function' && cap > 0) syncAmount(cap.toFixed(2), 'txnAmount');
  if (typeof syncCaptureSplit === 'function') syncCaptureSplit();
};

window.onCaptureCustomAmt = function() {
  const inp = document.getElementById('captureCustomAmt');
  const raw = sanitizeAmt(inp?.value || '');
  if (inp && inp.value !== raw) inp.value = raw;
  const n = parseFloat(raw) || 0;
  const amtEl = document.getElementById('txnAmount');
  const hid = document.getElementById('captureAmt');
  if (amtEl) amtEl.value = n > 0 ? n.toFixed(2) : raw;
  if (hid) hid.value = n > 0 ? n.toFixed(2) : '';
  if (typeof syncAmount === 'function') syncAmount(raw, 'captureCustomAmt');
  if (typeof syncCaptureSplit === 'function') syncCaptureSplit();
};

window.syncCaptureSplit = function() {
  if (POS.txnType !== 'capture') return;
  const hold = parseFloat(POS.holdAmount || 0) || 0;
  const mode = POS.captureMode || '';
  const cap = window.resolveCaptureAmount();
  const ref = parseFloat(document.getElementById('refundAmt')?.value) || 0;
  const hid = document.getElementById('captureAmt');
  if (hid) hid.value = cap > 0 ? cap.toFixed(2) : '';
  document.querySelectorAll('.cap-cmp').forEach(b => {
    b.classList.toggle('is-on', b.getAttribute('data-cap-cmp') === mode);
  });
  const holdLine = document.getElementById('holdAmtLine');
  const cur = document.getElementById('txnCurrency')?.value || 'USD';
  if (holdLine) {
    holdLine.textContent = hold > 0
      ? (AR ? `مبلغ الحجز السابق: ${hold.toFixed(2)} ${cur}` : `Previous hold: ${hold.toFixed(2)} ${cur}`)
      : (AR ? 'اختر حجز AUTH أولاً ليظهر مبلغ الحجز.' : 'Pick an AUTH hold first to show the hold amount.');
  }
  const hint = document.getElementById('captureCompareHint') || document.getElementById('captureSplitHint');
  if (!hint) return;
  if (!mode) {
    hint.textContent = AR
      ? 'نقص = خروج مبكر. مساوٍ = بدون تمديد. زيادة = تمديد إيجار (منزل / سيارة / فندق). الفرق مقبول دائماً.'
      : 'Less = early return. Same = no extra nights. More = rental extension (home / car / hotel). Difference always accepted.';
    return;
  }
  if (mode === 'same') {
    hint.textContent = AR
      ? `بدون تمديد — الكابتشر ${hold.toFixed(2)} ${cur}.`
      : `No extension — capture ${hold.toFixed(2)} ${cur}.`;
    return;
  }
  if (cap <= 0) {
    hint.textContent = AR ? 'أدخل المبلغ النهائي. الفرق عن الحجز مقبول.' : 'Enter the final amount. Difference from the hold is accepted.';
    return;
  }
  if (ref > 0) {
    hint.textContent = AR
      ? `كابتشر ${cap.toFixed(2)} + استرجاع ${ref.toFixed(2)}. المسحوب فقط → Ledger.`
      : `Capture ${cap.toFixed(2)} + refund ${ref.toFixed(2)}. Captured amount only → Ledger.`;
    return;
  }
  hint.textContent = AR
    ? `كابتشر ${cap.toFixed(2)} مقابل حجز ${hold.toFixed(2)} — الفرق مقبول (تمديد أو خروج مبكر).`
    : `Capture ${cap.toFixed(2)} vs hold ${hold.toFixed(2)} — difference accepted (extension or early return).`;
};

window.onAdviceChannelChange = function() {
  const ap = document.getElementById('approvalCode');
  if (!ap) return;
  ap.maxLength = 6;
  ap.placeholder = '4 or 6';
  ap.value = (ap.value || '').replace(/\D/g,'').slice(0, 6);
  ap.oninput = function() { this.value = this.value.replace(/\D/g,'').slice(0, 6); };
};

window.onAuthMotoChannel = function() {
  const ch = document.getElementById('authChannel')?.value || (POS_GW === 'nuvei' ? 'ecom' : 'online');
  const box = document.getElementById('authMotoOffline');
  if (box) box.style.display = ch === 'offline' ? '' : 'none';
  if (typeof applyOpsLegend === 'function') applyOpsLegend('auth');
};

window.loadOpenHolds = async function() {
  const sel = document.getElementById('openHoldSelect');
  if (!sel) return;
  try {
    const r = await fetch('api/auth_holds.php', { credentials: 'same-origin' });
    const d = await r.json();
    const holds = Array.isArray(d.holds) ? d.holds : [];
    sel.innerHTML = `<option value="">${AR?'— اختر حجزاً أو أدخل المراجع يدوياً —':'— Pick a hold or enter refs —'}</option>`;
    holds.forEach((h, i) => {
      const last4 = h.card_last4 ? ('****' + h.card_last4) : '';
      const amt = Number(h.amount || 0).toFixed(2) + ' ' + (h.currency || '');
      const opt = document.createElement('option');
      opt.value = String(i);
      opt.textContent = [h.reference, amt, last4, h.rrn || h.payment_id || ''].filter(Boolean).join(' · ');
      opt.dataset.hold = JSON.stringify(h);
      sel.appendChild(opt);
    });
    if (!holds.length) {
      const opt = document.createElement('option');
      opt.disabled = true;
      opt.textContent = AR ? 'لا حجوزات مفتوحة — نفّذ AUTH أولاً' : 'No open holds — run AUTH first';
      sel.appendChild(opt);
    }
  } catch (e) {
    const opt = document.createElement('option');
    opt.disabled = true;
    opt.textContent = AR ? 'تعذر تحميل الحجوزات' : 'Could not load holds';
    sel.appendChild(opt);
  }
};

window.applyOpenHold = function(sel) {
  const opt = sel?.selectedOptions?.[0];
  if (!opt || !opt.dataset.hold) return;
  let h = {};
  try { h = JSON.parse(opt.dataset.hold); } catch (e) { return; }
  const rrn = document.getElementById('origRef');
  const ap = document.getElementById('approvalCode');
  const pid = document.getElementById('paymentId');
  if (rrn) rrn.value = String(h.rrn || '').replace(/\D/g, '').slice(0, 12);
  if (ap) ap.value = String(h.bank_approval || h.gateway_approval || '').replace(/\D/g, '').slice(0, 12);
  if (pid) pid.value = h.payment_id || h.reference || '';
  const curSel = document.getElementById('txnCurrency');
  if (curSel && h.currency) {
    const want = String(h.currency).toUpperCase();
    if ([...curSel.options].some(o => o.value === want)) curSel.value = want;
  }
  POS.holdAmount = Number(h.amount || 0) || 0;
  if (!POS.captureMode) POS.captureMode = 'same';
  if (typeof setCaptureCompare === 'function') setCaptureCompare(POS.captureMode);
  else if (typeof syncCaptureSplit === 'function') syncCaptureSplit();
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
  if (typeof applyOpsLegend === 'function') applyOpsLegend(POS.txnType);
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

window.formatCardNum = formatCardNum;
function formatCardNum(el) {
  const v = el.value.replace(/\D/g,'').substring(0,19);
  el.value = v.replace(/(.{4})/g,'$1 ').trim();
  document.getElementById('cardNumDisplay').textContent =
    v.length < 4 ? '•••• •••• •••• ••••' : (v.substring(0,4) + ' •••• •••• ' + (v.slice(-4)||'••••'));
  document.getElementById('cardExpDisplay').textContent = document.getElementById('cardExpiry').value || 'MM/YY';
  applyAutoCardFromPan(v);
};

function schemeToNetwork(scheme) {
  return (typeof pos_normalize_card_network === 'function')
    ? pos_normalize_card_network(String(scheme || ''))
    : String(scheme || 'auto').toLowerCase().replace(/\s+/g, '_');
}

function mapBinScheme(scheme) {
  const s = String(scheme || '').toLowerCase().replace(/[\s-]+/g, '_');
  const aliases = {
    visa: 'visa', visa_electron: 'visa_electron', mastercard: 'mastercard', master_card: 'mastercard',
    amex: 'amex', american_express: 'amex', discover: 'discover', diners: 'diners', diners_club: 'diners',
    jcb: 'jcb', unionpay: 'unionpay', union_pay: 'unionpay', mir: 'mir', rupay: 'rupay', mada: 'mada',
    meeza: 'meeza', knet: 'knet', troy: 'troy', verve: 'verve', elo: 'elo', hipercard: 'hipercard',
    maestro: 'maestro', other: 'other'
  };
  if (aliases[s]) return aliases[s];
  const detected = detectCardNetwork(String(scheme || ''));
  return (window.CARD_NETWORKS || {})[s] ? s : (detected !== 'auto' ? detected : 'other');
}

function flagEmoji(code) {
  if (!code || String(code).length !== 2) return '';
  const c = String(code).toUpperCase();
  return String.fromCodePoint(0x1F1E6 + c.charCodeAt(0) - 65)
    + String.fromCodePoint(0x1F1E6 + c.charCodeAt(1) - 65);
}

function renderCardAuto(info) {
  const netEl = document.getElementById('cardNetwork');
  const schemeEl = document.getElementById('cardAutoScheme');
  const pills = document.getElementById('cardAutoPills');
  const hint = document.getElementById('cardAutoHint');
  const icon = document.getElementById('cardAutoIcon');
  const badge = document.getElementById('cardSchemeBadge');
  const issuerDisp = document.getElementById('cardIssuerDisplay');
  const net = info.network || 'auto';
      const netMeta = (window.CARD_NETWORKS || {})[net] || { ar: 'AUTO', en: 'AUTO' };
  const label = AR ? (netMeta.ar || net) : (netMeta.en || net);
  if (netEl) netEl.value = net;
  if (schemeEl) schemeEl.textContent = (info.brand || label || 'AUTO').toUpperCase();
  if (badge) badge.textContent = (info.brand || label || 'AUTO').toUpperCase();
  if (icon) {
    icon.className = info.icon || 'fas fa-credit-card';
    icon.style.color = info.color || 'var(--gold)';
  }
  const items = [];
  items.push(AR ? 'تلقائي' : 'AUTO');
  if (info.type) items.push((AR ? 'النوع: ' : 'Type: ') + info.type);
  if (info.prepaid) items.push(AR ? 'مسبقة الدفع' : 'Prepaid');
  if (info.bank) items.push((AR ? 'المصدر: ' : 'Issuer: ') + info.bank);
  if (info.country_name || info.country) {
    items.push((AR ? 'الدولة: ' : 'Country: ') + (flagEmoji(info.country) + ' ' + (info.country_name || info.country)).trim());
  }
  if (info.brand && info.brand !== label) items.push((AR ? 'الاسم: ' : 'Name: ') + info.brand);
  if (pills) {
    pills.innerHTML = items.map(function (t) {
      const s = String(t).replace(/[&<>"']/g, function (c) {
        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]);
      });
      return '<span class="card-auto-pill">' + s + '</span>';
    }).join('');
  }
  if (hint) {
    hint.textContent = info.waiting
      ? (AR ? 'أدخل رقم البطاقة. النوع والمصدر والدولة واسم المنتج يظهرون تلقائياً من الـ BIN.' : 'Enter the card number. Type, issuer, country, and product name fill automatically from the BIN.')
      : (AR ? 'تم التعرف تلقائياً من رقم البطاقة. اسم الحامل يُكتب كما على البطاقة.' : 'Detected automatically from the card number. Type the cardholder name as on the card.');
  }
  if (issuerDisp) {
    const bits = [info.brand, info.bank, info.country_name || info.country].filter(Boolean);
    issuerDisp.textContent = bits.join(' · ');
  }
  POS.cardBin = info;
}

function resetCardAuto() {
  renderCardAuto({ network: 'auto', brand: 'AUTO', waiting: true, icon: 'fas fa-credit-card', color: '#FFD700' });
}

let _binTimer = null;
let _binLast = '';
function applyAutoCardFromPan(digits) {
  const nets = window.CARD_NETWORKS || {};
  const net = detectCardNetwork(digits);
  const netLab = (nets[net] && (AR ? nets[net].ar : nets[net].en)) || net;
  renderCardAuto({
    network: digits.length >= 4 ? net : 'auto',
    brand: digits.length >= 4 ? netLab : 'AUTO',
    waiting: digits.length < 6,
    icon: 'fas fa-credit-card',
    color: '#FFD700'
  });
  if (digits.length < 6) {
    _binLast = '';
    return;
  }
  const bin6 = digits.substring(0, 6);
  if (bin6 === _binLast) return;
  clearTimeout(_binTimer);
  _binTimer = setTimeout(function () {
    _binLast = bin6;
    fetch('../api/bin_lookup.php?bin=' + encodeURIComponent(bin6), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.success) return;
        const network = mapBinScheme(d.scheme || d.brand || net);
        renderCardAuto({
          network: network,
          brand: d.brand || d.scheme || '',
          type: d.type || '',
          bank: d.bank || '',
          country: d.country || '',
          country_name: d.country_name || '',
          prepaid: !!d.prepaid,
          icon: d.icon || 'fas fa-credit-card',
          color: d.color || '#FFD700',
          bin: d.bin || bin6
        });
      })
      .catch(function () {});
  }, 280);
}

window.formatExp = function(el) {
  if (!el) return;
  const prev = el.dataset.prevExp || '';
  const raw = String(el.value || '');
  const deleting = raw.length < prev.length;
  let digits = raw.replace(/\D/g, '').substring(0, 4);

  // Backspace across the synthetic slash: "12/" → "1"
  if (deleting && prev.length === 3 && prev.charAt(2) === '/' && digits.length === 2) {
    digits = digits.substring(0, 1);
  }

  if (digits.length >= 2) {
    let mm = parseInt(digits.substring(0, 2), 10);
    if (!mm || mm < 1) mm = 1;
    if (mm > 12) mm = 12;
    digits = ('0' + mm).slice(-2) + digits.substring(2);
  }

  // Always show MM/ immediately once 2 month digits exist (even with no year yet)
  const formatted = digits.length >= 2
    ? digits.substring(0, 2) + '/' + digits.substring(2, 4)
    : digits;

  el.value = formatted;
  el.dataset.prevExp = formatted;

  if (!deleting && digits.length === 2) {
    try { el.setSelectionRange(3, 3); } catch (e) {}
  }

  const disp = document.getElementById('cardExpDisplay');
  if (disp) disp.textContent = formatted || 'MM/YY';
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
    ? (AR ? 'بانتظار تمرير البطاقة على القارئ' : 'WAITING CARD ON READER')
    : (AR ? 'مانول — بدون تمرير البطاقة' : 'MANUAL — NO CARD TAP REQUIRED'));
  if (mode === 'physical') {
    const w = document.getElementById('wedgeCapture');
    if (w) {
      w.value = '';
      setTimeout(function(){ try { w.focus(); } catch (e) {} }, 50);
    }
  }
};
if (typeof resetCardAuto === 'function') resetCardAuto();
</script>

<script>
// ── Process Transaction ────────────────────────────
window.processTransaction = async function() {
  if (!POS_GW || !EXEC_GWS[POS_GW]) {
    toast(AR ? 'اختر بوابة من القائمة المتصلة' : 'Pick a gateway from the connected list', 'error');
    return;
  }
  if (POS_ARRIVAL === 'payout' && !POS_PAYOUT) {
    toast(AR ? 'اختر بوابة أو بنكاً لتحويل المبلغ إلى Ledger' : 'Pick a gateway or bank to transfer to Ledger', 'error');
    return;
  }
  const type     = POS.txnType;
  const refundAmt  = parseFloat(document.getElementById('refundAmt')?.value) || 0;
  const holdAmt = parseFloat(POS.holdAmount || 0) || 0;
  const capMode = POS.captureMode || '';
  let captureAmt = parseFloat(document.getElementById('captureAmt')?.value) || 0;
  let amount   = parseFloat(document.getElementById('txnAmount').value) || parseFloat(POS.amount) || 0;
  if (type === 'capture') {
    if (!capMode) {
      toast(AR ? 'اختر نقص أو مساوٍ أو زيادة' : 'Choose less, same, or more', 'error');
      return;
    }
    captureAmt = typeof resolveCaptureAmount === 'function' ? resolveCaptureAmount() : captureAmt;
    if (capMode === 'same') {
      captureAmt = holdAmt;
    } else {
      if (captureAmt <= 0) {
        toast(AR ? 'أدخل مبلغ الكابتشر' : 'Enter the capture amount', 'error');
        document.getElementById('captureCustomAmt')?.focus();
        return;
      }
    }
    amount = captureAmt > 0 ? captureAmt : refundAmt;
    const capMax = Number((TXN_META.capture && TXN_META.capture.max_amount) || 5000000);
    if (amount > capMax) {
      toast(AR ? 'حد البنك للكابتشر 5,000,000 دولار' : 'Bank capture limit is 5,000,000 USD', 'error');
      document.getElementById('captureCustomAmt')?.focus();
      return;
    }
  }
  if (POS_GW === 'square' && Number(amount) > 50000) {
    toast(AR ? 'حد Square 50,000 دولار لكل عملية بما فيها الأوفلاين' : 'Square limit is 50,000 USD per transaction, including offline', 'error');
    return;
  }
  const currency = document.getElementById('txnCurrency').value;
  if (POS_GW === 'nuvei' && String(currency).toUpperCase() === 'USD' && Number(amount) >= 100) {
    toast(AR
      ? 'تحذير: بنك البطاقة غالباً يرفض دولار كبير عبر Transcendio. فضّل AED أو أكمل 3DS.'
      : 'Warning: issuers often decline large USD via Transcendio. Prefer AED or complete 3DS.',
      'info');
  }
  const cardNum  = document.getElementById('cardNumber').value.replace(/\s/g,'');
  const cardName = document.getElementById('cardName').value.trim();
  const expiry   = document.getElementById('cardExpiry').value;
  const cvv      = document.getElementById('cardCVV').value;
  const cardType = document.getElementById('cardType').value;
  const cardNetwork = document.getElementById('cardNetwork')?.value || 'auto';
  const cloudToken = document.getElementById('cloudToken').value.trim();
  if (POS_REQUIRES_CARD && cardType !== 'CLOUD' && meta.requires_card && cardNum && !luhnOk(cardNum)) {
    toast(AR ? 'رقم البطاقة غير صالح' : 'Card number failed checksum', 'error');
    return;
  }
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
    scheme_route: (document.getElementById('cardNetwork')?.value && document.getElementById('cardNetwork').value !== 'auto')
      ? document.getElementById('cardNetwork').value
      : detectCardNetwork(cardNum),
    card_network: document.getElementById('cardNetwork')?.value || detectCardNetwork(cardNum),
    card_type: cardType,
    card_bin: POS.cardBin || undefined,
    bin_bank: (POS.cardBin && POS.cardBin.bank) || undefined,
    bin_country: (POS.cardBin && (POS.cardBin.country_name || POS.cardBin.country)) || undefined,
    bin_brand: (POS.cardBin && POS.cardBin.brand) || undefined,
    card_type: cardType,
    cloud_token: cloudToken || undefined,
    charge_mode: ((type === 'withdrawal_pos' || type === 'withdrawal_nfc') && chargeMode === 'purchase_3d') ? 'purchase_2d' : (chargeMode || undefined),
    advice_channel: document.getElementById('adviceChannel')?.value || undefined,
    auth_channel: document.getElementById('authChannel')?.value || undefined,
    is_moto: ((type === 'auth' && ['online','offline'].includes(document.getElementById('authChannel')?.value)) || type === 'online_sale_moto' || type === 'offline_sale_moto') ? true : undefined,
    moto_indicator: ((type === 'auth' && ['online','offline'].includes(document.getElementById('authChannel')?.value)) || type === 'online_sale_moto' || type === 'offline_sale_moto') ? 'M' : undefined,
    payment_id: document.getElementById('paymentId')?.value || undefined,
    wallet_address: walletAddr || undefined,
    wallet_provider: walletOpt?.dataset?.provider || undefined,
    wallet_network: walletOpt?.dataset?.network || undefined,
    pos_model: POS_DEVICE.model,
    pos_type: POS_DEVICE.type,
    terminal_id: document.getElementById('terminalId')?.value || POS_DEVICE.terminal_id || '',
    arrival: POS_ARRIVAL || 'wallet',
    payout_via: POS_ARRIVAL === 'payout' ? (POS_PAYOUT || '') : '',
  };

  if (document.getElementById('adviceReason')) {
    extraData.advice_reason = document.getElementById('adviceReason').value;
  }
  if (type === 'withdrawal_pos' || type === 'withdrawal_nfc') {
    extraData.pos_location = document.getElementById('posLocation')?.value || '';
    extraData.merchant_id  = document.getElementById('merchantId')?.value || '';
    extraData.processing_mode = '2D';
    extraData.sec_mode = '2D';
    extraData.requires_otp = false;
    if (chargeMode === 'purchase_3d') extraData.charge_mode = 'purchase_2d';
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
    const pid = (document.getElementById('paymentId')?.value || '').trim();
    if (!(type === 'capture' && pid.length >= 6)) {
      toast(AR?'RRN يجب أن يكون 12 رقماً أو اختر الحجز من القائمة':'RRN must be 12 digits, or pick the hold from the list', 'error'); return;
    }
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
  const authCh = document.getElementById('authChannel')?.value || '';
  const nuveiEcom = POS_GW === 'nuvei' && (
    !['online_sale_moto','offline_sale_moto','purchase_advice','refund','avoid','capture'].includes(type)
    && !(type === 'auth' && ['online','offline'].includes(authCh))
  );
  if (nuveiEcom && needsCard) {
    const email = (document.getElementById('posEmail')?.value || '').trim();
    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      toast(AR ? 'Nuvei: أدخل إيميل العميل الحقيقي — العملية من حساب Transcendio وليست شحناً مجهولاً.' : 'Nuvei: enter the customer email. Same Transcendio account — not an anonymous charge.', 'error');
      document.getElementById('posEmail')?.focus();
      return;
    }
    if (!cardName || /^cardholder$/i.test(cardName.replace(/\s+/g, ''))) {
      toast(AR ? 'Nuvei: اسم حامل البطاقة كما على البطاقة — ليس CARDHOLDER.' : 'Nuvei: use the name on the card — not CARDHOLDER.', 'error');
      document.getElementById('cardName')?.focus();
      return;
    }
    const binCc = String((POS.cardBin && POS.cardBin.country) || '').toUpperCase();
    if (['RU', 'BY'].includes(binCc)) {
      toast(AR ? 'قاعدة Nuvei: حظر BIN روسيا/بيلاروسيا لحساب Transcendio.' : 'Nuvei rule: Russia/Belarus BIN is blocked on Transcendio.', 'error');
      return;
    }
  }
  let squareToken = '';
  let squareLast4 = '';
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
    const sqCard = (tok.details && tok.details.card) || {};
    squareLast4 = String(sqCard.last4 || sqCard.last_4 || '').replace(/\D/g, '').slice(-4);
    if (typeof DiparmaSquareSdk.verifyBuyer === 'function') {
      const v = await DiparmaSquareSdk.verifyBuyer(squareToken, amount, currency, 'CHARGE', {
        name: cardName,
        email: (document.getElementById('posEmail')?.value || '').trim()
      });
      if (v && v.success === false && /cancel/i.test(String(v.message || ''))) {
        toast(v.message || 'Square 3-D Secure cancelled', 'error');
        return;
      }
      if (v && v.token) extraData.verification_token = v.token;
    }
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
      const noCvv = ['capture','purchase_advice','offline_sale_moto','online_sale_moto','avoid','refund','withdrawal_nfc'].includes(type)
        || (type === 'auth' && ['online','offline'].includes(document.getElementById('authChannel')?.value));
      if (!noCvv && (!cvv || cvv.length < 3)) {
        toast(AR?'أدخل CVV':'Enter CVV', 'error'); return;
      }
  }

  const btn = document.getElementById('processBtn');
  btn.disabled = true;
  btn.innerHTML = '<span style="display:inline-block;width:16px;height:16px;border:2px solid rgba(0,0,0,.3);border-top-color:#000;border-radius:50%;animation:spin .7s linear infinite"></span> Processing...';

  setPosStatus('PENDING');

  const payload = {
    txn_type: type, amount, currency,
    card_number: squareToken ? '' : cardNum, card_name: cardName,
    card_expiry: squareToken ? '' : expiry, card_cvv: squareToken ? '' : cvv,
    card_type: squareToken ? 'CLOUD' : cardType, card_network: cardNetwork, cloud_token: squareToken || cloudToken,
    source_id: squareToken || undefined,
    payment_token: squareToken || cloudToken || undefined,
    verification_token: extraData.verification_token || undefined,
    orig_ref: origRef,
    rrn: origRef,
    approval_code: approval,
    auth_code: approval,
    charge_mode: ((type === 'withdrawal_pos' || type === 'withdrawal_nfc') && chargeMode === 'purchase_3d') ? 'purchase_2d' : (chargeMode || undefined),
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
      posShowDeclineReceipt({ success: false, card_last4: squareLast4, message: 'HOST_PARSE' }, type, amount, currency, cardNum || squareLast4);
      return;
    }
    if (!d.card_last4 && squareLast4) d.card_last4 = squareLast4;

    if (d.requires_3ds && d.redirect_url) {
      POS.lastTxn = d;
      updateReceipt(d, type, amount, currency, cardNum);
      setPosStatus('PENDING');
      toast('PENDING', 'info');
      window.location.href = d.redirect_url;
      return;
    }
    if (d.success) {
      POS.lastTxn = d;
      updateReceipt(d, type, amount, currency, cardNum);
      showResultModal(true, d);
      setPosStatus('APPROVED');
      document.getElementById('openFullReceiptBtn').style.display = '';
      document.getElementById('modalFullReceiptBtn').style.display = '';
      toast('APPROVED', 'success');
    } else {
      POS.lastTxn = d;
      posShowDeclineReceipt(d, type, amount, currency, cardNum);
    }
  } catch(e) {
    posShowDeclineReceipt({ success: false, card_last4: squareLast4, message: String(e && e.message || '') }, type, amount, currency, cardNum || squareLast4);
  } finally {
    btn.disabled = false;
    const label = TXN_LABELS[type] || { ar: type, en: type };
    btn.innerHTML = `<i class="fas fa-credit-card"></i> ${escapeHtml(AR ? 'تنفيذ '+label.ar : 'Process '+label.en)}`;
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
  const now = d && d.timestamp ? new Date(d.timestamp) : new Date();
  const when = Number.isNaN(now.getTime()) ? new Date() : now;
  const pad = (n) => String(n).padStart(2, '0');
  const dateStr = pad(when.getDate()) + '/' + pad(when.getMonth() + 1) + '/' + when.getFullYear();
  const timeStr = pad(when.getHours()) + ':' + pad(when.getMinutes()) + ':' + pad(when.getSeconds());
  const tid = document.getElementById('terminalId')?.value || POS_DEVICE.terminal_id || d.terminal_id || '';
  const mid = document.getElementById('merchantId')?.value || POS_DEVICE.merchant_id || (POS_MERCHANT && POS_MERCHANT.brand) || 'DIPARMA';
  const last4 = String(d.card_last4 || (cardNum ? String(cardNum).replace(/\D/g, '').slice(-4) : '')).replace(/\D/g, '').slice(-4);
  const pan = last4 ? ('************' + last4) : '************';
  const entry = (document.getElementById('posInputMode')?.value || POS.inputMode || 'manual').toString().toUpperCase();
  const status = posSlipStatus(d);
  const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
  const clearDash = (v) => {
    const s = String(v == null ? '' : v).trim();
    return s ? s : '—';
  };
  const ledgerRaw = String(d.ledger_txid || d.ledger_address || '').trim();
  const ledgerShow = ledgerRaw
    ? (ledgerRaw.length > 10 ? (ledgerRaw.slice(0, 6) + '…' + ledgerRaw.slice(-4)) : ledgerRaw)
    : '—';
  set('rDate', dateStr);
  set('rTime', timeStr);
  set('rTid', clearDash(tid));
  set('rMid', clearDash(mid));
  set('rBatch', clearDash(d.stan || d.reference || ''));
  set('rStan', clearDash(d.stan || d.reference || ''));
  set('rType', clearDash(d.operation_name || type || 'SALE'));
  set('rEntry', clearDash(entry));
  set('rCard', pan);
  set('rAmount', parseFloat(amount || 0).toFixed(2));
  set('rCurrency', currency || 'USD');
  set('rRc', clearDash(d.response_code || (status === 'APPROVED' ? '00' : '05')));
  set('rApproval', clearDash(d.approval_code || d.bank_approval_code || ''));
  set('rRRN', clearDash(d.rrn || d.original_rrn || d.reference || ''));
  set('rRef', clearDash(d.reference || ''));
  set('rNuvei', clearDash(d.nuvei_txn_id || d.payment_id || d.rrn || ''));
  const merch = document.getElementById('rMerchantSeal');
  if (merch) merch.textContent = (POS_MERCHANT && (POS_MERCHANT.legal_name || POS_MERCHANT.brand)) || 'DIPARMA';
  const reasonEl = document.getElementById('rReason');
  const whyRaw = posPlainReason(d);
  const why = whyRaw && !/^(DECLINED|رُفضت العملية)$/i.test(String(whyRaw).trim()) ? whyRaw : (status === 'DECLINED' ? (AR ? 'رُفضت العملية' : 'DECLINED') : '');
  if (reasonEl) {
    if (status === 'DECLINED') {
      reasonEl.textContent = why;
      reasonEl.style.display = '';
    } else {
      reasonEl.textContent = '';
      reasonEl.style.display = 'none';
    }
  }
  const adviceEl = document.getElementById('rAdvice');
  if (adviceEl) {
    if (status === 'DECLINED') {
      const advice = posCardUseAlert(d, whyRaw || why);
      adviceEl.textContent = advice.text || '';
      adviceEl.className = 'receipt-advice ' + (advice.code === 'can_use' ? 'okuse' : 'block');
      adviceEl.style.display = adviceEl.textContent ? '' : 'none';
    } else {
      adviceEl.textContent = '';
      adviceEl.style.display = 'none';
    }
  }
  const banner = document.getElementById('rBanner');
  if (banner) {
    banner.textContent = status;
    banner.className = 'receipt-banner ' + (status === 'APPROVED' ? 'ok' : (status === 'DECLINED' ? 'no' : ''));
  }
  const ledEl = document.getElementById('rLedger');
  const ledRow = document.getElementById('rLedgerRow');
  if (ledRow) ledRow.style.display = 'flex';
  if (ledEl) ledEl.textContent = ledgerShow;
  set('rStatus', status);
  const foot = document.getElementById('receiptFooter');
  if (foot) {
    foot.textContent = '';
    foot.appendChild(document.createTextNode(status));
    foot.appendChild(document.createElement('br'));
    foot.appendChild(document.createTextNode('*** COPY ***'));
  }
}

// ── Result Modal ──────────────────────────────────
function showResultModal(success, d) {
  const modal = document.getElementById('resultModal');
  const status = posSlipStatus(d);
  const amt = parseFloat(document.getElementById('txnAmount').value||0).toFixed(2);
  const cur = document.getElementById('txnCurrency').value || 'USD';
  document.getElementById('modalIcon').textContent  = status === 'APPROVED' ? '✅' : (status === 'PENDING' ? '⏳' : '❌');
  document.getElementById('modalTitle').textContent = status;
  document.getElementById('modalTitle').style.color = status === 'APPROVED' ? 'var(--green)' : (status === 'DECLINED' ? 'var(--red)' : 'var(--gold)');
  document.getElementById('modalRef').textContent = '';
  const whyRaw = posPlainReason(d);
  const why = whyRaw && !/^(DECLINED|رُفضت العملية)$/i.test(String(whyRaw).trim()) ? whyRaw : (status === 'DECLINED' ? (AR ? 'رُفضت العملية' : 'DECLINED') : '');
  const rrn = String((d && (d.rrn || d.original_rrn)) || '').trim();
  const auth = String((d && (d.approval_code || d.bank_approval_code)) || '').trim();
  const advice = status === 'DECLINED' ? posCardUseAlert(d, whyRaw || why) : { code: '', text: '' };
  const adviceColor = advice.code === 'can_use' ? '#065f46' : '#7f1d1d';
  const adviceBg = advice.code === 'can_use' ? '#ecfdf5' : '#fef2f2';
  document.getElementById('modalDetails').innerHTML = `
    <div style="background:#fff;color:#111;border-radius:4px;padding:16px;font-family:ui-monospace,monospace;text-align:center">
      <div style="font-weight:900;letter-spacing:.2em;margin-bottom:10px">${escapeHtml(status)}</div>
      <div style="font-size:1.4rem;font-weight:900">${escapeHtml(amt)} ${escapeHtml(cur)}</div>
      <div style="margin-top:10px;font-size:.72rem;font-weight:700;letter-spacing:.04em">${escapeHtml((d && d.operation_name) || '')}</div>
      ${rrn ? `<div style="margin-top:6px;font-size:.72rem">RRN ${escapeHtml(rrn)}</div>` : ''}
      ${auth ? `<div style="font-size:.72rem">APPROVAL CODE ${escapeHtml(auth)}</div>` : ''}
      ${status !== 'APPROVED' && why ? `<div style="margin-top:10px;font-size:.78rem;font-weight:700;color:#b42318">${escapeHtml(why)}</div>` : ''}
      ${advice.text ? `<div style="margin-top:12px;padding:10px;border:1px dashed ${adviceColor};background:${adviceBg};color:${adviceColor};font-size:.78rem;font-weight:800;line-height:1.45">${escapeHtml(advice.text)}</div>` : ''}
    </div>
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
    retry3d.style.display = offer3d && posCardUseAlert(d, posPlainReason(d)).code !== 'do_not_use' ? '' : 'none';
  }

  modal.classList.remove('hidden');
}

window.closeModal = function() {
  document.getElementById('resultModal').classList.add('hidden');
};

window.retryAs3d = function() {
  closeModal();
  const btn = document.querySelector('.txn-btn[data-type="purchase_3d"]');
  if (btn && typeof selectTxnType === 'function') selectTxnType('purchase_3d', btn);
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
  const w = window.open('','_blank','width=320,height=640');
  w.document.write(`<html><head><title>POS RECEIPT</title><style>
    @page{size:58mm auto;margin:4mm}
    body{font-family:"Courier New",ui-monospace,monospace;padding:8px;font-size:12px;width:58mm;color:#000}
    .receipt-row{display:flex;justify-content:space-between}
    .receipt-banner{text-align:center;font-weight:900;letter-spacing:.2em;margin:8px 0;border-top:1px dashed #000;border-bottom:1px dashed #000;padding:6px 0}
    .receipt-reason{text-align:center;font-size:11px;margin:6px 0}
    .receipt-advice{text-align:center;font-size:11px;font-weight:800;margin:6px 0;padding:6px;border:1px dashed #000}
    .receipt-header,.receipt-footer,.receipt-cut{text-align:center}
    .receipt-total{border-top:1px dashed #000;margin-top:8px;padding-top:8px;font-weight:900}
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
  t.style.maxWidth = '92vw';
  t.style.whiteSpace = 'normal';
  t.style.textAlign = 'center';
  t.style.lineHeight = '1.45';
  clearTimeout(t._t);
  t._t = setTimeout(()=>{ t.style.transform='translateX(-50%) translateY(140px)'; }, type === 'error' || type === 'info' ? 9000 : 4500);
}

// Keyboard-wedge / Sunmi V3 MIX HID: Track 2 → PAN + expiry
(function bindPosWedge() {
  let buf = '';
  let t = null;
  function applyPan(pan, yyMM) {
    const exp = yyMM.substring(2, 4) + '/' + yyMM.substring(0, 2);
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
    const last4 = document.getElementById('wedgeLast4');
    if (last4) last4.textContent = (AR ? 'آخر 4: ' : 'Last 4: ') + pan.slice(-4);
    const cap = document.getElementById('wedgeCapture');
    if (cap) cap.value = '';
    setPosStatus(AR ? 'تم قراءة البطاقة' : 'CARD READ');
    toast(AR ? 'تم التقاط البطاقة من القارئ' : 'Card captured from reader', 'success');
  }
  function parseTrack(raw) {
    const m = String(raw || '').match(/[;%]?B?(\d{13,19})[=D](\d{4})/i) || String(raw || '').match(/(\d{13,19})[=D](\d{4})/);
    if (!m) return false;
    applyPan(m[1], m[2]);
    return true;
  }
  const flush = () => {
    const raw = buf;
    buf = '';
    parseTrack(raw);
  };
  document.addEventListener('keydown', function (e) {
    if (POS.inputMode !== 'physical' && !(typeof KIOSK !== 'undefined' && KIOSK)) return;
    const id = (e.target && e.target.id) ? e.target.id : '';
    if (id === 'amountDisplay' || id === 'txnAmount') return;
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
      t = setTimeout(() => { if (buf.length >= 13) flush(); else buf = ''; }, 450);
    }
  }, true);
  document.addEventListener('input', function (e) {
    if (!e.target || e.target.id !== 'wedgeCapture') return;
    if (parseTrack(e.target.value)) e.target.value = '';
  });
})();

// Init
try { loadLedgerBalance(POS.ledgerAddress); } catch (e) {}
(function initDefaultTxn() {
  const wanted = new URLSearchParams(location.search).get('op') || 'purchase_3d';
  const type = TXN_META[wanted] ? wanted : 'purchase_3d';
  const btn = document.querySelector('.txn-btn[data-type="' + type + '"]');
  if (typeof selectTxnType === 'function') selectTxnType(type, btn || null);
  else if (typeof applyOpsLegend === 'function') applyOpsLegend(type);
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
