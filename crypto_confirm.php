<?php
/**
 * DI PARMA | تأكيد عملية Crypto + تتبع الحالة لحظياً
 */
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';

$currentLang = (isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'en') ? 'en' : 'ar';
$pageDir     = $currentLang === 'en' ? 'ltr' : 'rtl';
$reference   = trim($_GET['ref'] ?? '');
$type        = trim($_GET['type'] ?? 'buy');
$userId      = intval($_SESSION['user_id'] ?? 0);
$db          = db();

if (empty($reference)) { header('Location: crypto.php'); exit(); }

$txn = $db->find('transactions', ['reference' => $reference]);
if (!$txn || intval($txn['user_id']) !== $userId) { header('Location: crypto.php'); exit(); }

$gwData  = json_decode($txn['gateway_response'] ?? '{}', true);
$isBuy   = ($type === 'buy');
$status  = $txn['status'];

$statusConfig = [
    'pending'    => ['label'=> $currentLang==='en'?'Pending':'قيد الانتظار',    'color'=>'#f0ad4e', 'icon'=>'fa-clock',          'pct'=>25],
    'processing' => ['label'=> $currentLang==='en'?'Processing':'جاري المعالجة','color'=>'#5bc0de', 'icon'=>'fa-spinner fa-spin', 'pct'=>65],
    'completed'  => ['label'=> $currentLang==='en'?'Completed':'مكتمل',         'color'=>'#4CAF50', 'icon'=>'fa-circle-check',    'pct'=>100],
    'failed'     => ['label'=> $currentLang==='en'?'Failed':'فشل',              'color'=>'#ef5350', 'icon'=>'fa-circle-xmark',    'pct'=>0],
];
$sc = $statusConfig[$status] ?? $statusConfig['pending'];
?>
<!DOCTYPE html>
<html lang="<?= $currentLang ?>" dir="<?= $pageDir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | <?= $currentLang==='en'?'Transaction Confirmation':'تأكيد العملية' ?></title>
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
.confirm-card { background:var(--bg-card); border:1px solid var(--border-gold);
    border-radius:var(--border-radius-xl); padding:36px; max-width:600px; margin:40px auto; }
.status-ring { width:90px; height:90px; border-radius:50%; display:flex;
    align-items:center; justify-content:center; margin:0 auto 20px; font-size:2.2rem; }
.progress-track { height:8px; background:rgba(255,255,255,.08); border-radius:99px; overflow:hidden; margin:20px 0; }
.progress-fill { height:100%; border-radius:99px; transition:width 1s ease; }
.info-row { display:flex; justify-content:space-between; padding:10px 0;
    border-bottom:1px solid var(--border-light); font-size:.9rem; }
.info-row:last-child { border-bottom:none; }
.info-label { color:var(--text-muted); }
.info-val { color:var(--text-light); font-weight:600; font-family:monospace; font-size:.88rem; }
.steps-list { display:flex; flex-direction:column; gap:14px; margin-top:20px; }
.step-item { display:flex; align-items:center; gap:14px; }
.step-dot { width:32px; height:32px; border-radius:50%; display:flex; align-items:center;
    justify-content:center; font-size:.8rem; flex-shrink:0; }
.step-dot.done { background:rgba(76,175,80,.2); border:2px solid #4CAF50; color:#4CAF50; }
.step-dot.active { background:rgba(255,215,0,.15); border:2px solid var(--gold); color:var(--gold);
    animation:pulse 1.5s infinite; }
.step-dot.wait { background:rgba(255,255,255,.04); border:2px solid rgba(255,255,255,.12);
    color:var(--text-muted); }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.5} }
</style>
</head>
<body>
<?php if (function_exists('renderNavbar')) renderNavbar($currentLang); ?>
<div style="padding:20px">
<div class="confirm-card">

  <!-- Icon + Status -->
  <div style="text-align:center;margin-bottom:8px">
    <div class="status-ring" id="statusRing"
         style="background:<?= $sc['color'] ?>22;border:3px solid <?= $sc['color'] ?>">
      <i class="fas <?= $sc['icon'] ?>" style="color:<?= $sc['color'] ?>"></i>
    </div>
    <h2 style="color:var(--text-light);margin:0 0 6px;font-size:1.3rem" id="statusTitle">
      <?= $isBuy ? __('buy_usdt_title') : __('sell_usdt_title') ?>
    </h2>
    <span style="background:<?= $sc['color'] ?>22;color:<?= $sc['color'] ?>;
                 border:1px solid <?= $sc['color'] ?>44;padding:5px 16px;
                 border-radius:20px;font-size:.85rem;font-weight:700" id="statusBadge">
      <?= $sc['label'] ?>
    </span>
  </div>

  <!-- Progress Bar -->
  <div class="progress-track">
    <div class="progress-fill" id="progressFill"
         style="width:<?= $sc['pct'] ?>%;background:<?= $sc['color'] ?>"></div>
  </div>

  <!-- تفاصيل العملية -->
  <div style="margin:24px 0">
    <div class="info-row">
      <span class="info-label"><?= __('tx_reference') ?></span>
      <span class="info-val" style="color:var(--gold)"><?= htmlspecialchars($reference) ?></span>
    </div>
    <div class="info-row">
      <span class="info-label"><?= __('tx_amount') ?></span>
      <span class="info-val"><?= number_format((float)$txn['amount'],2) ?> <?= htmlspecialchars($txn['currency']) ?></span>
    </div>
    <?php if (!empty($gwData['crypto_amount'])): ?>
    <div class="info-row">
      <span class="info-label">USDT <?= $isBuy ? __('you_receive') : ($currentLang==='en'?'Sent':'أرسلت') ?></span>
      <span class="info-val" style="color:#26a17b"><?= number_format((float)$gwData['crypto_amount'],6) ?> USDT</span>
    </div>
    <?php endif; ?>
    <?php if (!empty($gwData['to_address'])): ?>
    <div class="info-row">
      <span class="info-label"><?= $currentLang==='en'?'Receiving Address':'عنوان الاستقبال' ?></span>
      <span class="info-val" style="font-size:.78rem"><?= htmlspecialchars(substr($gwData['to_address'],0,20))?>...
        <button onclick="navigator.clipboard.writeText('<?= htmlspecialchars($gwData['to_address']) ?>')"
          style="background:none;border:none;color:var(--gold);cursor:pointer;font-size:.8rem">
          <i class="fas fa-copy"></i></button>
      </span>
    </div>
    <?php endif; ?>
    <div class="info-row">
      <span class="info-label"><?= __('tx_network') ?></span>
      <span class="info-val"><?= htmlspecialchars($gwData['network'] ?? 'TRC20') ?></span>
    </div>
    <div class="info-row">
      <span class="info-label"><?= __('tx_gateway') ?></span>
      <span class="info-val"><?= htmlspecialchars($txn['gateway'] ?? '') ?></span>
    </div>
    <div class="info-row">
      <span class="info-label"><?= __('tx_date') ?></span>
      <span class="info-val"><?= $txn['created_at'] ?></span>
    </div>
  </div>

  <!-- مراحل العملية -->
  <div class="steps-list" id="stepsList">
    <?php
    $steps = $isBuy
        ? [
            [$currentLang==='en'?'Order Confirmed':'تأكيد الطلب',        'completed', 'fa-check'],
            [$currentLang==='en'?'Fiat Payment':'دفع الفيات',            $status === 'pending' ? 'active' : 'done', 'fa-credit-card'],
            [$currentLang==='en'?'Send USDT':'إرسال USDT',               in_array($status,['processing','completed']) ? ($status==='completed'?'done':'active') : 'wait', 'fa-paper-plane'],
            [$currentLang==='en'?'Blockchain Confirmation':'تأكيد البلوكشين', $status === 'completed' ? 'done' : 'wait', 'fa-cube'],
          ]
        : [
            [$currentLang==='en'?'Order Confirmed':'تأكيد الطلب',        'done',  'fa-check'],
            [$currentLang==='en'?'Awaiting USDT Deposit':'انتظار إيداع USDT', $status === 'pending' ? 'active' : 'done', 'fa-arrow-down'],
            [$currentLang==='en'?'Blockchain Verification':'تحقق من البلوكشين', in_array($status,['processing','completed']) ? ($status==='completed'?'done':'active') : 'wait', 'fa-cube'],
            [$currentLang==='en'?'Transfer Fiat':'تحويل الفيات',          $status === 'completed' ? 'done' : 'wait', 'fa-money-bill'],
          ];
    foreach ($steps as $step):
        $dotClass = $step[1] === 'done' ? 'done' : ($step[1] === 'active' ? 'active' : 'wait');
        $icon = $step[1] === 'done' ? 'fa-check' : "fa-{$step[2]}";
    ?>
    <div class="step-item">
      <div class="step-dot <?= $dotClass ?>"><i class="fas <?= $icon ?>"></i></div>
      <span style="color:<?= $dotClass==='wait'?'var(--text-muted)':($dotClass==='done'?'var(--text-light)':'var(--gold)') ?>;
                   font-size:.9rem">
        <?= $step[0] ?>
      </span>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- أزرار -->
  <div style="display:flex;gap:12px;margin-top:28px">
    <?php if ($status === 'pending' && $isBuy): ?>
    <button onclick="completeFiatPayment()" style="flex:1;padding:13px;border-radius:12px;
      background:var(--gold-gradient);color:#000;border:none;font-weight:700;cursor:pointer;font-size:.95rem">
      <i class="fas fa-credit-card"></i> <?= $currentLang==='en'?'Complete Payment':'إتمام الدفع' ?>
    </button>
    <?php endif; ?>
    <a href="crypto.php" style="flex:1;padding:13px;border-radius:12px;text-align:center;
      background:rgba(255,255,255,.05);color:var(--text-light);border:1px solid var(--border-light);
      font-size:.95rem;text-decoration:none">
      <i class="fas fa-arrow-right"></i> <?= $currentLang==='en'?'New Transaction':'عملية جديدة' ?>
    </a>
  </div>

  <!-- Auto refresh indicator -->
  <p style="text-align:center;color:var(--text-muted);font-size:.78rem;margin:16px 0 0">
    <span style="width:7px;height:7px;border-radius:50%;background:#4CAF50;display:inline-block;animation:pulse 2s infinite;vertical-align:middle"></span>
    يتحدث تلقائياً <span id="countdown">15</span>ث
  </p>

</div>
</div>

<script>
const REF    = '<?= addslashes($reference) ?>';
const TYPE   = '<?= addslashes($type) ?>';
let   count  = 15;
const DONE   = ['completed','failed'];
let   isDone = <?= json_encode(in_array($status, ['completed','failed'])) ?>;

const statusCfg = {
    pending:    { label:'قيد الانتظار',  color:'#f0ad4e', icon:'fa-clock',           pct:25  },
    processing: { label:'جاري المعالجة', color:'#5bc0de', icon:'fa-spinner fa-spin',  pct:65  },
    completed:  { label:'مكتمل',          color:'#4CAF50', icon:'fa-circle-check',     pct:100 },
    failed:     { label:'فشل',            color:'#ef5350', icon:'fa-circle-xmark',     pct:0   },
};

async function checkStatus() {
    if (isDone) return;
    try {
        const r = await fetch('api/check_transaction.php?id=<?= intval($txn['id']) ?>');
        const d = await r.json();
        if (!d.success) return;
        const st = d.status;
        const cfg = statusCfg[st] || statusCfg.pending;

        // تحديث الـ UI
        document.getElementById('statusBadge').textContent = cfg.label;
        document.getElementById('statusBadge').style.cssText =
            `background:${cfg.color}22;color:${cfg.color};border:1px solid ${cfg.color}44;
             padding:5px 16px;border-radius:20px;font-size:.85rem;font-weight:700`;
        document.getElementById('statusRing').style.cssText =
            `background:${cfg.color}22;border:3px solid ${cfg.color};
             width:90px;height:90px;border-radius:50%;display:flex;
             align-items:center;justify-content:center;margin:0 auto 20px;font-size:2.2rem`;
        document.getElementById('statusRing').querySelector('i').className = 'fas ' + cfg.icon;
        document.getElementById('statusRing').querySelector('i').style.color = cfg.color;
        document.getElementById('progressFill').style.width  = cfg.pct + '%';
        document.getElementById('progressFill').style.background = cfg.color;

        if (DONE.includes(st)) {
            isDone = true;
            document.getElementById('countdown').closest('p').innerHTML =
                st === 'completed'
                    ? '<span style="color:#4CAF50"><i class="fas fa-circle-check"></i> اكتملت العملية بنجاح</span>'
                    : '<span style="color:#ef5350"><i class="fas fa-circle-xmark"></i> فشلت العملية</span>';
        }
    } catch(e) {}
}

// Countdown
function tick() {
    if (isDone) return;
    count--;
    const el = document.getElementById('countdown');
    if (el) el.textContent = count;
    if (count <= 0) { count = 15; checkStatus(); }
}
setInterval(tick, 1000);

async function completeFiatPayment() {
    const r = await fetch('api/crypto.php?action=fiat_confirmed&reference=' + encodeURIComponent(REF));
    const d = await r.json();
    if (d.success) { checkStatus(); }
}
</script>
</body>
</html>
