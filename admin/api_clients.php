<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();
$db = db();

// ensure table
try {
    $db->execute("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "api_clients` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(255) NOT NULL,
        `api_key` VARCHAR(100) NOT NULL UNIQUE,
        `api_secret` VARCHAR(255) NOT NULL,
        `webhook_url` VARCHAR(255) DEFAULT NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'active',
        `created_at` DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

$message = '';
$created = null;

// create client
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_client'])) {
    $name = trim($_POST['name'] ?? '');
    $webhook = trim($_POST['webhook_url'] ?? '');
    if ($name === '') {
        $message = '❌ يرجى إدخال اسم العميل.';
    } else {
        $apiKey = strtoupper(bin2hex(random_bytes(8)));
        $apiSecret = bin2hex(random_bytes(24));
        $db->insert(DB_PREFIX . 'api_clients', [
            'name'        => $name,
            'api_key'     => $apiKey,
            'api_secret'  => $apiSecret,
            'webhook_url' => $webhook,
            'status'      => 'active',
            'created_at'  => date('Y-m-d H:i:s')
        ]);
        // persist plain secret in server-side storage for webhook verification (secure file)
        try {
          $secretsFile = __DIR__ . '/../storage/api_secrets.json';
          $storageDir  = dirname($secretsFile);
          if (!is_dir($storageDir)) {
              mkdir($storageDir, 0700, true);
          }
          $secrets = [];
          if (file_exists($secretsFile)) $secrets = json_decode(file_get_contents($secretsFile), true) ?: [];
          $secrets[$apiKey] = $apiSecret;
          file_put_contents($secretsFile, json_encode($secrets, JSON_PRETTY_PRINT));
          @chmod($secretsFile, 0600);
        } catch (Exception $e) {
          error_log('[api_clients] storage write failed: ' . $e->getMessage());
        }
        $message = '✅ تم إنشاء العميل. احتفظ بمفتاح السر المقدم الآن لأنه لن يعرض مرة أخرى.';
        $created = ['api_key' => $apiKey, 'api_secret' => $apiSecret];
    }
}

$clients = $db->query('SELECT id,name,api_key,webhook_url,status,created_at FROM ' . DB_PREFIX . 'api_clients ORDER BY created_at DESC');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang ?? 'en') ?>" dir="<?= htmlspecialchars($pageDir ?? 'ltr') ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>API Clients</title>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap" rel="stylesheet">
  <style>body{font-family:'Cairo',sans-serif;background:#0b0f17;color:#f7d76b;padding:20px}.card{max-width:1100px;margin:0 auto;background:rgba(10,16,39,0.95);border:1px solid rgba(255,215,0,0.12);padding:18px;border-radius:12px} table{width:100%;border-collapse:collapse} th,td{padding:8px;border-bottom:1px solid rgba(255,255,255,0.04)} input,textarea{width:100%;padding:8px;border-radius:6px;background:#071021;color:#fff;border:1px solid rgba(255,255,255,0.04)} .btn{background:#ffd700;color:#0b0f17;padding:8px 16px;border:none;border-radius:6px;cursor:pointer;font-weight:700;text-decoration:none;display:inline-block}</style>
</head>
<body>
<div class="card">
  <h2>مدير مفاتيح API</h2>
  <?php if (!empty($message)): ?><div style="margin:8px 0;padding:8px;background:rgba(255,255,255,0.03);border-radius:8px"><?= htmlspecialchars($message) ?></div><?php endif; ?>

  <?php if (!empty($created)): ?>
    <div style="background:#072028;padding:12px;border-radius:8px;margin-bottom:12px;color:#cfeff0">
      <div><strong>API Key:</strong> <?= htmlspecialchars($created['api_key']) ?></div>
      <div><strong>API Secret:</strong> <?= htmlspecialchars($created['api_secret']) ?></div>
      <div style="margin-top:8px;color:#ffd700;font-weight:700;">احتفظ بهذه القيم الآن — لن تعرض مرة أخرى.</div>
    </div>
  <?php endif; ?>

  <form method="POST" style="margin-bottom:12px;">
    <label>اسم العميل</label>
    <input name="name" placeholder="مثال: myfatoorah_integration">
    <label style="margin-top:8px">Webhook URL (اختياري)</label>
    <input name="webhook_url" placeholder="https://example.com/webhook">
    <div style="margin-top:8px;text-align:left;"><button name="create_client" class="btn">إنشاء</button></div>
  </form>

  <h3>قائمة العملاء</h3>
  <table>
    <thead><tr><th>الاسم</th><th>API Key</th><th>Webhook</th><th>حالة</th><th>إنشئ في</th></tr></thead>
    <tbody>
      <?php foreach ($clients as $c): ?>
        <tr>
          <td><?= htmlspecialchars($c['name']) ?></td>
          <td><?= htmlspecialchars($c['api_key']) ?></td>
          <td><?= htmlspecialchars($c['webhook_url'] ?? '-') ?></td>
          <td><?= htmlspecialchars($c['status']) ?></td>
          <td><?= htmlspecialchars($c['created_at']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div style="margin-top:12px;text-align:left">
    <a href="../index.php" class="btn" style="background:#1a2340;margin-left:8px;">&#8962; الرئيسية</a>
    <a href="../dashboard.php" class="btn">عودة</a>
  </div>
</div>
</body>
</html>