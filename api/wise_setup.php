<?php
/**
 * ============================================================
 * DI PARMA | إعداد Wise في قاعدة البيانات
 * ============================================================
 * شغّل هذا الملف مرة واحدة من المتصفح (كمدير) لتفعيل Wise
 * وجلب Profile ID تلقائياً.
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/gateways.php';
require_once __DIR__ . '/../lib/WiseService.php';

// ── حماية: مدير فقط ───────────────────────────────────────
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

$steps  = [];
$errors = [];

// ══════════════════════════════════════════════════════════
// [1] إنشاء جدول settings إن لم يكن موجوداً
// ══════════════════════════════════════════════════════════
try {
    $db->execute("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "settings` (
        `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `key`        VARCHAR(100) NOT NULL UNIQUE,
        `value`      TEXT         DEFAULT NULL,
        `updated_at` DATETIME     NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $steps[] = ['ok', 'جدول dp_settings جاهز'];
} catch (Exception $e) {
    $errors[] = 'جدول settings: ' . $e->getMessage();
}

// ══════════════════════════════════════════════════════════
// [2] جلب Profile ID من Wise API
// ══════════════════════════════════════════════════════════
$profileId   = null;
$profileData = [];

try {
    $wise      = WiseService::fromConfig();
    $profiles  = $wise->getProfiles();

    if (empty($profiles)) {
        $errors[] = 'Wise: لم يتم إرجاع أي ملف شخصي — تحقق من صحة المفتاح.';
    } else {
        // تفضيل business
        $chosen = null;
        foreach ($profiles as $p) {
            if (($p['type'] ?? '') === 'business') { $chosen = $p; break; }
        }
        $chosen      = $chosen ?? $profiles[0];
        $profileId   = (int) $chosen['id'];
        $profileData = $chosen;

        $steps[] = ['ok', "Profile ID: {$profileId} (نوع: " . ($chosen['type'] ?? '?') . ")"];

        // حفظ في جدول settings
        $db->execute(
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `updated_at`)
             VALUES ('wise_profile_id', ?, NOW())
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_at` = NOW()",
            [(string) $profileId]
        );
        $steps[] = ['ok', 'تم حفظ Profile ID في dp_settings'];
    }
} catch (RuntimeException $e) {
    $errors[] = 'Wise API: ' . $e->getMessage();
}

// ══════════════════════════════════════════════════════════
// [3] تفعيل / تحديث سجل Wise في dp_payment_gateways
// ══════════════════════════════════════════════════════════
try {
    // إنشاء جدول payment_gateways إن لم يكن موجوداً
    $db->execute("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "payment_gateways` (
        `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `code`        VARCHAR(50)  NOT NULL UNIQUE,
        `name`        VARCHAR(100) NOT NULL,
        `status`      VARCHAR(20)  NOT NULL DEFAULT 'inactive',
        `config`      TEXT         DEFAULT NULL,
        `credentials` TEXT         DEFAULT NULL,
        `settings`    TEXT         DEFAULT NULL,
        `created_at`  DATETIME     NOT NULL,
        `updated_at`  DATETIME     NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $credentials = json_encode([
        'api_key'    => '5497cf6e-ae91-42d2-99b8-e77d3328bf53',
        'profile_id' => $profileId ?? '',
    ], JSON_UNESCAPED_UNICODE);

    $config = json_encode([
        'environment' => 'live',
        'api_base'    => 'https://api.wise.com',
        'currencies'  => ['USD','EUR','GBP','AED','SAR','KWD','BHD','OMR','QAR','EGP'],
        'fees'        => ['percentage' => 0.6, 'fixed' => 0.00],
    ], JSON_UNESCAPED_UNICODE);

    $settings = json_encode([
        'webhook' => SITE_URL . '/api/webhook.php?gateway=wise',
        'success' => SITE_URL . '/payment_success.php?gateway=wise',
        'cancel'  => SITE_URL . '/payment_cancelled.php?gateway=wise',
    ], JSON_UNESCAPED_UNICODE);

    $existing = $db->find('payment_gateways', ['code' => 'wise']);

    if ($existing) {
        $db->update('payment_gateways', [
            'name'        => 'Wise',
            'status'      => 'active',
            'config'      => $config,
            'credentials' => $credentials,
            'settings'    => $settings,
            'updated_at'  => date('Y-m-d H:i:s'),
        ], ['code' => 'wise']);
        $steps[] = ['ok', 'تم تحديث سجل Wise في dp_payment_gateways وتفعيله'];
    } else {
        $db->insert('payment_gateways', [
            'code'        => 'wise',
            'name'        => 'Wise',
            'status'      => 'active',
            'config'      => $config,
            'credentials' => $credentials,
            'settings'    => $settings,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        $steps[] = ['ok', 'تم إنشاء سجل Wise في dp_payment_gateways وتفعيله'];
    }
} catch (Exception $e) {
    $errors[] = 'payment_gateways: ' . $e->getMessage();
}

// ══════════════════════════════════════════════════════════
// [4] اختبار الاتصال — جلب الأرصدة
// ══════════════════════════════════════════════════════════
$balances = [];
if ($profileId && empty($errors)) {
    try {
        $wise     = WiseService::fromConfig();
        $wise->fetchAndCacheProfileId(); // تحديث الكاش بالـ ID الجديد
        $balances = $wise->getBalances();
        $steps[]  = ['ok', 'اتصال Wise ناجح — عدد الأرصدة: ' . count($balances)];
    } catch (RuntimeException $e) {
        $errors[] = 'جلب الأرصدة: ' . $e->getMessage();
    }
}

$allOk = empty($errors);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DI PARMA | إعداد Wise</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Cairo',sans-serif;background:#0a0f1e;color:#FFDFA0;min-height:100vh;padding:30px}
.wrap{max-width:760px;margin:0 auto}
.card{background:rgba(10,16,39,.95);border:1px solid rgba(255,215,0,.25);border-radius:16px;padding:28px;margin-bottom:20px}
h1{font-size:1.6rem;background:linear-gradient(135deg,#FFE066,#FFD700);-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:20px}
h2{font-size:1.1rem;color:#FFD700;margin-bottom:14px}
.step{display:flex;align-items:flex-start;gap:10px;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.05)}
.step:last-child{border-bottom:none}
.step .icon{font-size:1.1rem;margin-top:1px}
.step .text{font-size:.9rem;color:#E8F0FF}
.err{color:#EF9A9A;background:rgba(217,83,79,.1);border:1px solid rgba(217,83,79,.3);border-radius:8px;padding:10px 14px;margin-bottom:8px;font-size:.9rem}
.ok-banner{background:rgba(76,175,80,.12);border:1px solid rgba(76,175,80,.35);border-radius:12px;padding:16px 20px;display:flex;align-items:center;gap:12px;margin-bottom:20px}
.ok-banner .icon{font-size:2rem}
.ok-banner .text{font-size:1rem;color:#A5D6A7}
.fail-banner{background:rgba(217,83,79,.1);border:1px solid rgba(217,83,79,.35);border-radius:12px;padding:16px 20px;margin-bottom:20px;color:#EF9A9A}
table{width:100%;border-collapse:collapse;font-size:.88rem}
th,td{padding:9px 12px;text-align:right;border-bottom:1px solid rgba(255,255,255,.06)}
th{color:#FFD700;font-weight:700}
.btn{display:inline-flex;align-items:center;gap:8px;padding:10px 22px;background:linear-gradient(135deg,#FFE066,#FFD700);color:#000;border-radius:10px;text-decoration:none;font-weight:700;font-family:'Cairo',sans-serif}
.btn-out{background:transparent;border:1.5px solid rgba(255,215,0,.4);color:#FFD700}
</style>
</head>
<body>
<div class="wrap">

  <div class="card">
    <h1>⚙️ إعداد بوابة Wise</h1>

    <?php if ($allOk): ?>
    <div class="ok-banner">
      <span class="icon">✅</span>
      <div class="text">تم إعداد Wise بنجاح وهو جاهز للاستخدام الآن.</div>
    </div>
    <?php else: ?>
    <div class="fail-banner">
      <strong>⚠️ اكتمل الإعداد مع بعض الأخطاء:</strong>
      <ul style="margin-top:8px;padding-right:20px">
        <?php foreach ($errors as $e): ?>
          <li style="margin-top:4px"><?= htmlspecialchars($e) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <h2>خطوات الإعداد</h2>
    <?php foreach ($steps as [$type, $msg]): ?>
      <div class="step">
        <span class="icon"><?= $type === 'ok' ? '✅' : '⚠️' ?></span>
        <span class="text"><?= htmlspecialchars($msg) ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($profileId): ?>
  <div class="card">
    <h2>بيانات الحساب</h2>
    <table>
      <tr><th>Profile ID</th><td><?= htmlspecialchars((string)$profileId) ?></td></tr>
      <tr><th>نوع الحساب</th><td><?= htmlspecialchars($profileData['type'] ?? '-') ?></td></tr>
      <tr><th>البيئة</th><td><span style="color:#4CAF50;font-weight:700">Live ✅</span></td></tr>
      <tr><th>Webhook URL</th><td style="direction:ltr;text-align:left"><?= htmlspecialchars(SITE_URL . '/api/webhook.php?gateway=wise') ?></td></tr>
    </table>
  </div>
  <?php endif; ?>

  <?php if (!empty($balances)): ?>
  <div class="card">
    <h2>الأرصدة المتاحة</h2>
    <table>
      <thead><tr><th>العملة</th><th>الرصيد المتاح</th><th>الرصيد الكلي</th></tr></thead>
      <tbody>
        <?php foreach ($balances as $b):
            $avail = $b['amount']['value'] ?? $b['availableAmount']['value'] ?? 0;
            $total = $b['totalAmount']['value'] ?? $avail;
            $curr  = $b['amount']['currency'] ?? $b['currency'] ?? '?';
        ?>
        <tr>
          <td style="font-weight:700;color:#FFD700"><?= htmlspecialchars($curr) ?></td>
          <td><?= number_format((float)$avail, 2) ?></td>
          <td><?= number_format((float)$total, 2) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <div style="display:flex;gap:12px;flex-wrap:wrap">
    <a href="<?= SITE_URL ?>/index.php" class="btn btn-out"><i class="fas fa-home"></i> الرئيسية</a>
    <a href="<?= SITE_URL ?>/admin/gateway_manager.php" class="btn">إدارة البوابات</a>
    <a href="<?= SITE_URL ?>/dashboard.php" class="btn btn-out">لوحة التحكم</a>
    <a href="<?= SITE_URL ?>/api/wise_profiles.php" class="btn btn-out">عرض الملفات الشخصية</a>
  </div>

</div>
</body>
</html>
