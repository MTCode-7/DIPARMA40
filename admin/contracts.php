<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();
$db = db();
// pagination
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 25; $offset = ($page-1)*$limit;
$totalRow = $db->query('SELECT COUNT(*) as total FROM ' . DB_PREFIX . 'contracts');
$total = $totalRow[0]['total'] ?? 0;
$contracts = $db->query('SELECT * FROM ' . DB_PREFIX . 'contracts ORDER BY created_at DESC LIMIT ? OFFSET ?', [$limit, $offset]);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang ?? 'en') ?>" dir="<?= htmlspecialchars($pageDir ?? 'ltr') ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>قائمة العقود</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap" rel="stylesheet">
<style>body{font-family:'Cairo',sans-serif;background:#0b0f17;color:#f7d76b;padding:20px}.card{max-width:1100px;margin:0 auto;background:rgba(10,16,39,0.95);border:1px solid rgba(255,215,0,0.12);padding:18px;border-radius:12px} table{width:100%;border-collapse:collapse} th,td{padding:8px;border-bottom:1px solid rgba(255,255,255,0.04)} .btn{padding:6px 10px;border-radius:8px;background:#ffd700;color:#000;text-decoration:none}</style>
</head>
<body>
<div class="card">
  <h2>قائمة العقود (<?= $total ?>)</h2>
  <table>
    <thead><tr><th>المرجع</th><th>الخدمة</th><th>المستخدم</th><th>تاريخ الإنشاء</th><th>إجراء</th></tr></thead>
    <tbody>
      <?php foreach ($contracts as $c): ?>
        <tr>
          <td><?= htmlspecialchars($c['reference']) ?></td>
          <td><?= htmlspecialchars($c['service_name'] ?? '-') ?></td>
          <td><?= htmlspecialchars($c['user_id'] ?? '-') ?></td>
          <td><?= htmlspecialchars($c['created_at'] ?? '-') ?></td>
          <td>
            <a class="btn" href="../contract_print.php?ref=<?= urlencode($c['reference']) ?>">عرض</a>
            <a class="btn" href="../contract_pdf.php?ref=<?= urlencode($c['reference']) ?>">PDF</a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div style="margin-top:12px;display:flex;gap:8px;justify-content:flex-end;">
    <a class="btn" href="../index.php" style="background:#1a2340;">&#8962; الرئيسية</a>
    <a class="btn" href="../dashboard.php">العودة</a>
  </div>
</div>
</body>
</html>
