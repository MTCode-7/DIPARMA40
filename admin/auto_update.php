<?php
/**
 * DI PARMA | Auto Update
 */
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}
require_once ROOT_PATH . '/includes/config.php';
require_once ROOT_PATH . '/includes/database.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/auth_check.php';
require_once ROOT_PATH . '/lib/AutoUpdateService.php';
if (is_file(ROOT_PATH . '/includes/peer_link.php')) {
    require_once ROOT_PATH . '/includes/peer_link.php';
}
requireAdmin();

$ar = function_exists('is_ar') ? is_ar() : (($GLOBALS['dp_lang'] ?? 'en') === 'ar');
$csrf = generateCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => $ar ? 'رمز الأمان غير صالح' : 'Invalid CSRF token']);
        exit;
    }
    $action = strtolower(trim((string) ($_POST['action'] ?? '')));
    if ($action === 'status') {
        echo json_encode(AutoUpdateService::status(!empty($_POST['fetch'])), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'toggle') {
        $on = !empty($_POST['enabled']);
        AutoUpdateService::setEnabled($on);
        echo json_encode([
            'success' => true,
            'enabled' => AutoUpdateService::enabled(),
            'message' => $on
                ? ($ar ? 'تم تفعيل Auto Update' : 'Auto Update enabled')
                : ($ar ? 'تم إيقاف Auto Update' : 'Auto Update disabled'),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'pull') {
        echo json_encode(AutoUpdateService::pull('admin', !empty($_POST['force'])), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'pull_remote') {
        if (!function_exists('peer_request')) {
            echo json_encode(['success' => false, 'message' => $ar ? 'نظام الربط غير متوفر' : 'Peer link unavailable']);
            exit;
        }
        echo json_encode(peer_request('auto_update', [
            'force'  => !empty($_POST['force']),
            'reason' => 'peer-admin',
        ], 90), JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

$st = AutoUpdateService::status(false);
$dir = $ar ? 'rtl' : 'ltr';
$lang = $ar ? 'ar' : 'en';
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | Auto Update</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Cairo',sans-serif;background:#0a0f1e;color:#FFDFA0;padding:20px;min-height:100vh}
.wrap{max-width:980px;margin:0 auto}
.topbar{background:rgba(10,16,39,.95);border:1px solid rgba(255,215,0,.25);border-radius:14px;padding:18px 24px;display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px}
.topbar h1{font-size:1.3rem;background:linear-gradient(135deg,#FFE066,#FFD700);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:20px}
.stat{background:rgba(10,16,39,.95);border:1px solid rgba(255,215,0,.2);border-radius:14px;padding:18px;text-align:center}
.stat .num{font-size:1.15rem;font-weight:800;color:#FFD700;word-break:break-all}
.stat .unit{font-size:.75rem;color:#888;margin-top:4px}
.stat.good .num{color:#4CAF50}
.stat.bad .num{color:#d9534f}
.card{background:rgba(10,16,39,.95);border:1px solid rgba(255,215,0,.18);border-radius:14px;padding:20px;margin-bottom:16px}
.card h2{color:#FFD700;font-size:1rem;margin-bottom:12px}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;background:linear-gradient(135deg,#FFE066,#FFD700);color:#000;border-radius:10px;text-decoration:none;font-weight:700;font-size:.85rem;border:none;cursor:pointer}
.btn:disabled{opacity:.5;cursor:not-allowed}
.btn-out{background:transparent;border:1.5px solid rgba(255,215,0,.35);color:#FFD700}
.btn-danger{background:transparent;border:1.5px solid rgba(217,83,79,.5);color:#d9534f}
.row{display:flex;gap:10px;flex-wrap:wrap}
.tip{font-size:.82rem;color:#888;line-height:1.7;margin-top:8px}
code{background:rgba(255,215,0,.08);padding:2px 7px;border-radius:5px;font-size:.8rem;direction:ltr;display:inline-block}
.log{background:#05070e;border:1px solid rgba(255,215,0,.12);border-radius:10px;padding:14px;font-size:.8rem;direction:ltr;text-align:left;white-space:pre-wrap;min-height:72px;color:#ccc}
.switch{display:inline-flex;align-items:center;gap:10px;cursor:pointer}
.switch input{accent-color:#FFD700;width:18px;height:18px}
</style>
</head>
<body>
<div class="wrap">
  <div class="topbar">
    <div>
      <h1><i class="fas fa-sync-alt"></i> Auto Update</h1>
      <div style="font-size:.8rem;color:#888;margin-top:3px">
        <?= $ar ? 'مزامنة الكود من GitHub إلى هذه العقدة والسيرفر البعيد' : 'Sync code from GitHub onto this node and production' ?>
      </div>
    </div>
    <div class="row">
      <a href="../dashboard.php" class="btn btn-out"><i class="fas fa-home"></i> <?= $ar ? 'لوحة التحكم' : 'Dashboard' ?></a>
    </div>
  </div>

  <div class="grid">
    <div class="stat <?= !empty($st['enabled']) ? 'good' : 'bad' ?>" id="stEnabled">
      <div class="num"><?= !empty($st['enabled']) ? ($ar ? 'مفعّل' : 'ON') : ($ar ? 'متوقف' : 'OFF') ?></div>
      <div class="unit">Auto Update</div>
    </div>
    <div class="stat">
      <div class="num" id="stRole"><?= htmlspecialchars((string) ($st['role'] ?? '')) ?></div>
      <div class="unit"><?= $ar ? 'هذه العقدة' : 'This node' ?></div>
    </div>
    <div class="stat">
      <div class="num" id="stSha"><?= htmlspecialchars((string) ($st['short_sha'] ?: '—')) ?></div>
      <div class="unit">HEAD</div>
    </div>
    <div class="stat <?= !empty($st['dirty']) ? 'bad' : 'good' ?>" id="stDirty">
      <div class="num"><?= !empty($st['dirty']) ? ($st['dirty_count'] ?? '?') : '0' ?></div>
      <div class="unit"><?= $ar ? 'تعديلات محلية' : 'Local changes' ?></div>
    </div>
  </div>

  <div class="card">
    <h2><?= $ar ? 'التحكم' : 'Controls' ?></h2>
    <label class="switch">
      <input type="checkbox" id="enabledBox" <?= !empty($st['enabled']) ? 'checked' : '' ?>>
      <span><?= $ar ? 'تفعيل النظام' : 'Enable Auto Update' ?></span>
    </label>
    <div class="row" style="margin-top:16px">
      <button class="btn" id="btnCheck"><i class="fas fa-search"></i> <?= $ar ? 'فحص GitHub' : 'Check GitHub' ?></button>
      <button class="btn" id="btnPull"><i class="fas fa-download"></i> <?= $ar ? 'تحديث هذه العقدة' : 'Update this node' ?></button>
      <button class="btn btn-out" id="btnPullRemote"><i class="fas fa-cloud-upload-alt"></i> <?= $ar ? 'تحديث diparmas.com' : 'Update diparmas.com' ?></button>
      <button class="btn btn-danger" id="btnForce"><?= $ar ? 'تحديث إجباري' : 'Force update' ?></button>
    </div>
    <p class="tip">
      <?= $ar
        ? 'التحديث يسحب origin/main بسحب fast-forward فقط، ولا يلمس ملف .env أو السجلات. ارفع الكود إلى GitHub أولاً ثم حدّث السيرفر البعيد.'
        : 'Updates fast-forward origin/main only and never touch .env or logs. Push to GitHub first, then update production.' ?>
    </p>
    <p class="tip">Webhook: <code><?= htmlspecialchars((string) ($st['webhook'] ?? '')) ?></code></p>
  </div>

  <div class="card">
    <h2><?= $ar ? 'النتيجة' : 'Result' ?></h2>
    <div class="log" id="log"><?= htmlspecialchars((string) ($st['subject'] ?? '')) ?></div>
  </div>
</div>
<script>
const CSRF = <?= json_encode($csrf) ?>;
const AR = <?= $ar ? 'true' : 'false' ?>;
const logEl = document.getElementById('log');

async function call(action, extra = {}) {
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', action);
  Object.entries(extra).forEach(([k, v]) => fd.append(k, v));
  const r = await fetch('auto_update.php', { method: 'POST', body: fd });
  return r.json();
}

function paint(d) {
  if (!d) return;
  document.getElementById('stEnabled').className = 'stat ' + (d.enabled ? 'good' : 'bad');
  document.getElementById('stEnabled').querySelector('.num').textContent = d.enabled ? (AR ? 'مفعّل' : 'ON') : (AR ? 'متوقف' : 'OFF');
  document.getElementById('enabledBox').checked = !!d.enabled;
  if (d.role) document.getElementById('stRole').textContent = d.role;
  if (d.short_sha) document.getElementById('stSha').textContent = d.short_sha;
  const dirty = document.getElementById('stDirty');
  dirty.className = 'stat ' + (d.dirty ? 'bad' : 'good');
  dirty.querySelector('.num').textContent = d.dirty ? (d.dirty_count || 1) : '0';
}

function show(d) {
  logEl.textContent = JSON.stringify(d, null, 2);
  paint(d);
}

document.getElementById('enabledBox').addEventListener('change', async (e) => {
  show(await call('toggle', { enabled: e.target.checked ? '1' : '' }));
});
document.getElementById('btnCheck').addEventListener('click', async () => {
  show(await call('status', { fetch: '1' }));
});
document.getElementById('btnPull').addEventListener('click', async () => {
  show(await call('pull'));
});
document.getElementById('btnForce').addEventListener('click', async () => {
  if (!confirm(AR ? 'تحديث إجباري قد يتجاهل بعض التحذيرات. متابعة؟' : 'Force update may ignore dirty-tree protection. Continue?')) return;
  show(await call('pull', { force: '1' }));
});
document.getElementById('btnPullRemote').addEventListener('click', async () => {
  show(await call('pull_remote'));
});
</script>
</body>
</html>
