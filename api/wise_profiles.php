<?php
/**
 * ============================================================
 * DI PARMA | Wise Profiles & Balances
 * ============================================================
 * صفحة عرض الملفات الشخصية والأرصدة والتحويلات الأخيرة
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/gateways.php';
require_once __DIR__ . '/../lib/WiseService.php';

// ── حماية: مدير فقط ────────────────────────────────────────
if (empty($_SESSION['user_id'])) {
    header('Location: ' . SITE_URL . '/login.php');
    exit();
}
$db      = db();
$me      = $db->find('users', ['id' => intval($_SESSION['user_id'])]);
$isAdmin = $me && strtolower($me['role'] ?? '') === 'admin';
if (!$isAdmin) {
    header('Location: ' . SITE_URL . '/dashboard.php?error=unauthorized');
    exit();
}

// ── طلب JSON مباشر (للـ AJAX) ──────────────────────────────
if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $wise = WiseService::fromConfig();
        $data = match ($_GET['json']) {
            'profiles' => $wise->getProfiles(),
            'balances' => $wise->getBalances(),
            'profile_id' => ['profile_id' => $wise->getProfileId()],
            default    => ['error' => 'نوع بيانات غير معروف']
        };
        echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    } catch (RuntimeException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// ── جلب البيانات للعرض ─────────────────────────────────────
$profiles   = [];
$balances   = [];
$profileId  = null;
$error      = null;
$apiLatency = null;

try {
    $wise      = WiseService::fromConfig();
    $t0        = microtime(true);
    $profiles  = $wise->getProfiles();
    $profileId = $wise->getProfileId();
    $balances  = $wise->getBalances();
    $apiLatency = round((microtime(true) - $t0) * 1000);
} catch (RuntimeException $e) {
    $error = $e->getMessage();
}

// حساب إجمالي الأرصدة بالدولار تقريبياً (عرض فقط)
$totalBalances = count($balances);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DI PARMA | Wise — الملفات الشخصية</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Cairo',sans-serif;background:#0a0f1e;color:#FFDFA0;min-height:100vh;padding:24px}
.wrap{max-width:1100px;margin:0 auto}
.header{background:rgba(10,16,39,.95);border:1px solid rgba(255,215,0,.25);border-radius:16px;padding:22px 26px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:22px}
.header h1{font-size:1.5rem;background:linear-gradient(135deg,#FFE066,#FFD700);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.header p{color:#888;font-size:.85rem;margin-top:3px}
.btn{display:inline-flex;align-items:center;gap:8px;padding:9px 20px;background:linear-gradient(135deg,#FFE066,#FFD700);color:#000;border-radius:10px;text-decoration:none;font-weight:700;font-family:'Cairo',sans-serif;font-size:.9rem;border:none;cursor:pointer;transition:opacity .2s}
.btn:hover{opacity:.88}
.btn-out{background:transparent;border:1.5px solid rgba(255,215,0,.35);color:#FFD700}
.btn-sm{padding:6px 14px;font-size:.82rem}
.card{background:rgba(10,16,39,.95);border:1px solid rgba(255,215,0,.2);border-radius:16px;padding:22px;margin-bottom:20px}
.card h2{font-size:1rem;color:#FFD700;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:22px}
.stat{background:rgba(10,16,39,.95);border:1px solid rgba(255,215,0,.2);border-radius:14px;padding:18px;text-align:center}
.stat .num{font-size:1.8rem;font-weight:800;color:#FFD700}
.stat .lbl{font-size:.78rem;color:#888;margin-top:4px}
.profile-card{background:rgba(255,255,255,.03);border:1px solid rgba(255,215,0,.15);border-radius:12px;padding:18px;margin-bottom:12px}
.profile-card.business{border-color:rgba(76,175,80,.4)}
.profile-card .top{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px}
.profile-card .name{font-size:1rem;font-weight:700;color:#E8F0FF}
.badge{display:inline-block;padding:3px 12px;border-radius:999px;font-size:.78rem;font-weight:600}
.badge-business{background:rgba(76,175,80,.15);color:#4CAF50;border:1px solid rgba(76,175,80,.3)}
.badge-personal{background:rgba(33,150,243,.15);color:#64B5F6;border:1px solid rgba(33,150,243,.3)}
.badge-live{background:rgba(76,175,80,.15);color:#4CAF50}
.info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}
.info-item .lbl{font-size:.72rem;color:#888;margin-bottom:3px}
.info-item .val{font-size:.88rem;color:#E8F0FF;font-weight:600}
.balance-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px}
.balance-card{background:rgba(255,255,255,.03);border:1px solid rgba(255,215,0,.12);border-radius:12px;padding:16px;text-align:center;transition:border-color .3s}
.balance-card:hover{border-color:rgba(255,215,0,.35)}
.balance-card .currency{font-size:1.1rem;font-weight:800;color:#FFD700;margin-bottom:6px}
.balance-card .amount{font-size:1.6rem;font-weight:800;color:#E8F0FF}
.balance-card .sub{font-size:.72rem;color:#888;margin-top:4px}
.balance-card.positive .amount{color:#4CAF50}
.err-box{background:rgba(217,83,79,.1);border:1px solid rgba(217,83,79,.35);border-radius:12px;padding:16px 20px;color:#EF9A9A;margin-bottom:20px}
.latency{font-size:.75rem;color:#888;text-align:center;margin-top:10px}
.copy-btn{background:rgba(255,215,0,.08);border:1px solid rgba(255,215,0,.2);border-radius:6px;padding:3px 10px;color:#FFD700;cursor:pointer;font-size:.72rem;font-family:'Cairo',sans-serif;transition:background .2s}
.copy-btn:hover{background:rgba(255,215,0,.15)}
@media(max-width:600px){.header{flex-direction:column}}
</style>
</head>
<body>
<div class="wrap">

  <!-- Header -->
  <div class="header">
    <div>
      <h1><i class="fas fa-exchange-alt"></i> Wise — الملفات الشخصية والأرصدة</h1>
      <p>اتصال مباشر بـ Wise API — بيئة Live</p>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <a href="<?= SITE_URL ?>/index.php" class="btn btn-out"><i class="fas fa-home"></i> الرئيسية</a>
      <button onclick="refreshAll()" class="btn"><i class="fas fa-sync"></i> تحديث</button>
      <a href="<?= SITE_URL ?>/api/wise_setup.php" class="btn btn-out"><i class="fas fa-cog"></i> إعادة الإعداد</a>
      <a href="<?= SITE_URL ?>/admin/gateway_manager.php" class="btn btn-out"><i class="fas fa-arrow-right"></i> البوابات</a>
    </div>
  </div>

  <?php if ($error): ?>
  <div class="err-box">
    <strong>⚠️ خطأ في الاتصال بـ Wise API:</strong><br>
    <?= htmlspecialchars($error) ?>
    <div style="margin-top:10px">
      <a href="<?= SITE_URL ?>/api/wise_setup.php" class="btn btn-sm">إعادة الإعداد</a>
    </div>
  </div>
  <?php endif; ?>

  <!-- إحصائيات سريعة -->
  <div class="stats">
    <div class="stat">
      <div class="num"><?= count($profiles) ?></div>
      <div class="lbl">الملفات الشخصية</div>
    </div>
    <div class="stat">
      <div class="num" style="color:<?= $profileId ? '#4CAF50' : '#d9534f' ?>"><?= $profileId ?: '—' ?></div>
      <div class="lbl">Profile ID النشط</div>
    </div>
    <div class="stat">
      <div class="num"><?= $totalBalances ?></div>
      <div class="lbl">عدد العملات</div>
    </div>
    <div class="stat">
      <div class="num" style="color:<?= $error ? '#d9534f' : '#4CAF50' ?>">
        <?= $error ? '❌' : '✅' ?>
      </div>
      <div class="lbl">حالة الاتصال</div>
    </div>
    <?php if ($apiLatency !== null): ?>
    <div class="stat">
      <div class="num" style="font-size:1.4rem"><?= $apiLatency ?><span style="font-size:.9rem"> ms</span></div>
      <div class="lbl">زمن الاستجابة</div>
    </div>
    <?php endif; ?>
  </div>

  <!-- الملفات الشخصية -->
  <?php if (!empty($profiles)): ?>
  <div class="card">
    <h2><i class="fas fa-user-circle"></i> الملفات الشخصية</h2>
    <?php foreach ($profiles as $p):
        $isBiz = ($p['type'] ?? '') === 'business';
        $isActive = (int)($p['id'] ?? 0) === (int)$profileId;
    ?>
    <div class="profile-card <?= $isBiz ? 'business' : '' ?>">
      <div class="top">
        <div>
          <div class="name">
            <?= htmlspecialchars($p['details']['name'] ?? $p['details']['firstName'] . ' ' . ($p['details']['lastName'] ?? '') ?? 'Profile') ?>
            <?php if ($isActive): ?><span style="font-size:.75rem;color:#4CAF50;margin-right:8px">← نشط</span><?php endif; ?>
          </div>
          <div style="font-size:.75rem;color:#888;margin-top:3px">ID: <?= htmlspecialchars((string)($p['id'] ?? '')) ?></div>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
          <span class="badge badge-<?= $isBiz ? 'business' : 'personal' ?>"><?= $isBiz ? 'Business' : 'Personal' ?></span>
          <span class="badge badge-live">Live</span>
          <?php if (!$isActive): ?>
            <button onclick="setProfile(<?= (int)$p['id'] ?>)" class="btn btn-sm btn-out">تعيين نشطاً</button>
          <?php endif; ?>
        </div>
      </div>
      <div class="info-grid">
        <?php if (!empty($p['details']['email'])): ?>
        <div class="info-item">
          <div class="lbl">البريد الإلكتروني</div>
          <div class="val"><?= htmlspecialchars($p['details']['email']) ?></div>
        </div>
        <?php endif; ?>
        <?php if (!empty($p['details']['registrationNumber'])): ?>
        <div class="info-item">
          <div class="lbl">رقم التسجيل</div>
          <div class="val"><?= htmlspecialchars($p['details']['registrationNumber']) ?></div>
        </div>
        <?php endif; ?>
        <?php if (!empty($p['details']['companyType'])): ?>
        <div class="info-item">
          <div class="lbl">نوع الشركة</div>
          <div class="val"><?= htmlspecialchars($p['details']['companyType']) ?></div>
        </div>
        <?php endif; ?>
        <div class="info-item">
          <div class="lbl">Webhook URL</div>
          <div class="val" style="direction:ltr;font-size:.78rem">
            <?= htmlspecialchars(SITE_URL . '/api/webhook.php?gateway=wise') ?>
            <button onclick="copyText('<?= SITE_URL ?>/api/webhook.php?gateway=wise')" class="copy-btn">نسخ</button>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- الأرصدة -->
  <?php if (!empty($balances)): ?>
  <div class="card">
    <h2><i class="fas fa-wallet"></i> الأرصدة المتاحة</h2>
    <div class="balance-grid">
      <?php foreach ($balances as $b):
          $avail    = (float)($b['amount']['value'] ?? $b['availableAmount']['value'] ?? 0);
          $reserved = (float)($b['reservedAmount']['value'] ?? 0);
          $currency = $b['amount']['currency'] ?? $b['currency'] ?? '?';
          $hasBalance = $avail > 0;
      ?>
      <div class="balance-card <?= $hasBalance ? 'positive' : '' ?>">
        <div class="currency"><?= htmlspecialchars($currency) ?></div>
        <div class="amount"><?= number_format($avail, 2) ?></div>
        <?php if ($reserved > 0): ?>
          <div class="sub">محجوز: <?= number_format($reserved, 2) ?></div>
        <?php endif; ?>
        <div class="sub" style="margin-top:6px">
          <?php if ($b['type'] ?? '' === 'SAVINGS'): ?>
            <span style="color:#f0ad4e">💰 ادخار</span>
          <?php else: ?>
            <span style="color:#888">📋 عادي</span>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if ($apiLatency !== null): ?>
    <div class="latency">زمن استجابة API: <?= $apiLatency ?> ms</div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div>

<script>
async function refreshAll() {
    location.reload();
}

async function setProfile(id) {
    const res = await fetch('?json=profile_id');
    // تحديث Profile ID في النظام
    const body = new FormData();
    body.append('profile_id', id);
    await fetch('wise_set_profile.php', { method: 'POST', body });
    alert('تم تعيين Profile ID: ' + id + '\nأعد تشغيل الصفحة لرؤية التغييرات.');
    location.reload();
}

function copyText(text) {
    navigator.clipboard?.writeText(text)
        .then(() => alert('✅ تم النسخ'))
        .catch(() => {
            const el = document.createElement('input');
            el.value = text;
            document.body.appendChild(el);
            el.select();
            document.execCommand('copy');
            document.body.removeChild(el);
            alert('✅ تم النسخ');
        });
}
</script>
</body>
</html>
