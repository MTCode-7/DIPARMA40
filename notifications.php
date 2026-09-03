<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';

$db = db();
// Ensure notifications table exists (already created elsewhere on demand)
try {
    $db->execute("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "notifications` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT UNSIGNED NOT NULL,
        `title` VARCHAR(255) NOT NULL,
        `message` TEXT DEFAULT NULL,
        `read` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

$userId = intval($_SESSION['user_id'] ?? 0);
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['mark_read_id'])) {
    $nid = intval($_POST['mark_read_id']);
    if ($nid > 0) {
        $db->update('notifications', ['read' => 1], ['id' => $nid]);
        $message = '✅ تم وضع الإشعار كمقروء';
    }
}

$notifications = $db->query('SELECT * FROM ' . DB_PREFIX . "notifications WHERE user_id = ? ORDER BY created_at DESC", [$userId]);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang ?? 'en') ?>" dir="<?= htmlspecialchars($pageDir ?? 'ltr') ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>الإشعارات - DI PARMA</title>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap" rel="stylesheet">
  <style>body{font-family:'Cairo',sans-serif;background:#0b0f17;color:#f7d76b;padding:18px} .card{background:rgba(255,255,255,0.04);padding:18px;border-radius:12px;border:1px solid rgba(255,215,0,0.08)} table{width:100%;border-collapse:collapse} th,td{padding:10px;border-bottom:1px solid rgba(255,255,255,0.04);text-align:right} .btn{background:#2bb673;color:#fff;padding:6px 10px;border-radius:6px;text-decoration:none;border:none;cursor:pointer} .muted{color:#cfcfcf;font-size:0.9rem}</style>
</head>
<body>
<div class="card">
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;">
    <a href="index.php" style="color:#FFD700;text-decoration:none;padding:6px 14px;border:1px solid rgba(255,215,0,0.3);border-radius:20px;font-size:0.85rem;">&#8962; الرئيسية</a>
    <a href="dashboard.php" style="color:#fff;text-decoration:none;padding:6px 14px;border:1px solid rgba(255,255,255,0.1);border-radius:20px;font-size:0.85rem;">لوحة التحكم</a>
  </div>
  <h2>الإشعارات</h2>
  <?php if ($message): ?><div style="padding:10px;background:rgba(255,255,255,0.03);border-radius:8px;margin-bottom:10px;"><strong><?= htmlspecialchars($message) ?></strong></div><?php endif; ?>
  <?php if (empty($notifications)): ?>
    <p class="muted">لا توجد إشعارات.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>العنوان</th><th>الرسالة</th><th>التاريخ</th><th>الحالة</th><th>إجراء</th></tr></thead>
      <tbody>
        <?php foreach ($notifications as $n): ?>
          <tr>
            <td><?= htmlspecialchars($n['title']) ?></td>
            <td style="max-width:55%;"><?= nl2br(htmlspecialchars($n['message'])) ?></td>
            <td><?= htmlspecialchars($n['created_at']) ?></td>
            <td><?= $n['read'] ? '<span style="color:#9bd6a5">مقروء</span>' : '<span style="color:#ffd54f">جديد</span>' ?></td>
            <td>
              <?php if (!$n['read']): ?>
                <form method="POST" style="display:inline-block;margin:0;">
                  <input type="hidden" name="mark_read_id" value="<?= (int)$n['id'] ?>">
                  <button class="btn">وضع كمقروء</button>
                </form>
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
</body>
</html>
