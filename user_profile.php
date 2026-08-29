<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';

requireAdmin();

$db = db();
$userId = intval($_GET['id'] ?? 0);
if ($userId <= 0) {
    header('Location: dashboard.php');
    exit();
}

$user = $db->find('users', ['id' => $userId]);
if (!$user) {
    header('Location: dashboard.php');
    exit();
}

$transactions = $db->query(
    'SELECT * FROM ' . DB_PREFIX . 'transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 10',
    [$userId]
);
$wallets = $db->query(
    'SELECT * FROM ' . DB_PREFIX . 'wallets WHERE user_id = ? ORDER BY created_at DESC',
    [$userId]
);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang ?? 'ar') ?>" dir="<?= htmlspecialchars($pageDir ?? 'rtl') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DI PARMA | ملف المستخدم</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap" rel="stylesheet">
<style>
body{font-family:'Cairo',sans-serif;background:#0b0f17;color:#f7d76b;margin:0;padding:20px;} .container{max-width:1000px;margin:0 auto;} .card{background:rgba(255,255,255,0.05);border:1px solid rgba(255,215,0,0.2);border-radius:16px;padding:20px;margin-bottom:20px;} .nav{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;} .nav a{color:#fff;text-decoration:none;padding:8px 12px;border:1px solid rgba(255,215,0,0.2);border-radius:999px;} .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;} .item{background:rgba(255,255,255,0.06);padding:12px;border-radius:12px;} .label{font-size:0.85rem;color:#cdbb6b;margin-bottom:6px;} .value{font-size:1rem;font-weight:700;} table{width:100%;border-collapse:collapse;} th,td{padding:10px;border-bottom:1px solid rgba(255,255,255,0.1);text-align:right;} .badge{display:inline-block;padding:4px 8px;border-radius:999px;background:rgba(255,215,0,0.25);} </style>
</head>
<body>
<div class="container">
  <div class="nav">
    <a href="index.php">&#8962; الرئيسية</a>
    <a href="dashboard.php">لوحة التحكم</a>
    <a href="approvals.php">الموافقات</a>
    <a href="wallets.php">المحفظة</a>
    <a href="invoices.php">الفواتير</a>
  </div>
  <div class="card">
    <h2>ملف المستخدم</h2>
    <div class="grid">
      <div class="item"><div class="label">اسم المستخدم</div><div class="value"><?= htmlspecialchars($user['username'] ?? '-') ?></div></div>
      <div class="item"><div class="label">البريد</div><div class="value"><?= htmlspecialchars($user['email'] ?? '-') ?></div></div>
      <div class="item"><div class="label">الاسم الكامل</div><div class="value"><?= htmlspecialchars(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))) ?></div></div>
      <div class="item"><div class="label">الدور</div><div class="value"><?= htmlspecialchars($user['role'] ?? 'user') ?></div></div>
    </div>
  </div>
  <div class="card">
    <h3>آخر المعاملات</h3>
    <table>
      <thead><tr><th>المرجع</th><th>المبلغ</th><th>الحالة</th><th>التاريخ</th></tr></thead>
      <tbody>
        <?php foreach ($transactions as $tx): ?>
          <tr>
            <td><?= htmlspecialchars($tx['reference']) ?></td>
            <td><?= number_format((float)$tx['amount'], 2) ?> <?= htmlspecialchars($tx['currency']) ?></td>
            <td><span class="badge"><?= htmlspecialchars($tx['status']) ?></span></td>
            <td><?= htmlspecialchars($tx['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card">
    <h3>المحافظ</h3>
    <table>
      <thead><tr><th>العملة</th><th>الرصيد</th><th>الحالة</th></tr></thead>
      <tbody>
        <?php foreach ($wallets as $wallet): ?>
          <tr>
            <td><?= htmlspecialchars($wallet['currency']) ?></td>
            <td><?= number_format((float)$wallet['balance'], 2) ?></td>
            <td><span class="badge"><?= htmlspecialchars($wallet['status']) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
