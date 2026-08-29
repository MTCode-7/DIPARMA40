<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db_optimized.php';

requireAdmin();

$db = db();
dp_ensure_indexes();

try {
    $db->execute("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "approval_requests` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT UNSIGNED NOT NULL,
        `type` VARCHAR(50) NOT NULL DEFAULT 'payment',
        `reference` VARCHAR(100) NOT NULL,
        `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `currency` VARCHAR(10) NOT NULL DEFAULT 'AED',
        `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
        `reason` TEXT DEFAULT NULL,
        `created_at` DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_request'])) {
    $id = intval($_POST['id'] ?? 0);
    $request = $db->find('approval_requests', ['id' => $id]);
    if ($request && $request['status'] === 'pending') {
        $db->update('approval_requests', ['status' => 'approved'], ['id' => $id]);
        $db->update('invoices', ['status' => 'paid'], ['reference' => $request['reference']]);
        $db->update('transactions', ['status' => 'completed'], ['reference' => $request['reference']]);

        $existingWallet = $db->find('wallets', ['user_id' => $request['user_id'], 'currency' => $request['currency']]);
        if ($existingWallet) {
            $db->execute("UPDATE `" . DB_PREFIX . "wallets` SET `balance` = `balance` + ? WHERE `user_id` = ? AND `currency` = ?", [(float)$request['amount'], $request['user_id'], $request['currency']]);
        } else {
            $db->insert('wallets', [
                'user_id' => $request['user_id'],
                'currency' => $request['currency'],
                'balance' => (float)$request['amount'],
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }

        $db->insert('ledger', [
            'user_id' => $request['user_id'],
            'type' => 'credit',
            'amount' => (float)$request['amount'],
            'currency' => $request['currency'],
            'reference' => $request['reference'],
            'description' => 'Approved integrated payment',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        $message = '✅ تم قبول الطلب وتم إضافة الرصيد إلى المحفظة';
        $messageType = 'success';
    } else {
        $message = 'ℹ️ هذا الطلب لم يعد قابلًا للتعديل';
        $messageType = 'info';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_request'])) {
    $id = intval($_POST['id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $request = $db->find('approval_requests', ['id' => $id]);
    if ($request && $request['status'] === 'pending') {
        $db->update('approval_requests', ['status' => 'rejected', 'reason' => $reason], ['id' => $id]);
        $db->update('invoices', ['status' => 'cancelled'], ['reference' => $request['reference']]);
        $db->update('transactions', ['status' => 'rejected'], ['reference' => $request['reference']]);
        $message = '❌ تم رفض الطلب';
        $messageType = 'error';
    } else {
        $message = 'ℹ️ هذا الطلب لم يعد قابلًا للتعديل';
        $messageType = 'info';
    }
}

$requests = $db->query("SELECT * FROM " . DB_PREFIX . "approval_requests ORDER BY created_at DESC");
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang ?? 'ar') ?>" dir="<?= htmlspecialchars($pageDir ?? 'rtl') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DI PARMA | طلبات الموافقة</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap" rel="stylesheet">
<style>
body{font-family:'Cairo',sans-serif;background:#0b0f17;color:#f7d76b;margin:0;padding:20px;} .container{max-width:1200px;margin:0 auto;} .card{background:rgba(255,255,255,0.05);border:1px solid rgba(255,215,0,0.2);border-radius:16px;padding:20px;margin-bottom:20px;} .nav{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;} .nav a{color:#fff;text-decoration:none;padding:8px 12px;border:1px solid rgba(255,215,0,0.2);border-radius:999px;} table{width:100%;border-collapse:collapse;} th,td{padding:10px;border-bottom:1px solid rgba(255,255,255,0.1);text-align:right;} .badge{display:inline-block;padding:4px 8px;border-radius:999px;background:rgba(255,215,0,0.25);} .btn{padding:8px 12px;border:none;border-radius:8px;cursor:pointer;margin-left:6px;} .btn-success{background:#2bb673;color:#fff;} .btn-danger{background:#d9534f;color:#fff;} textarea{width:100%;min-height:70px;border-radius:8px;padding:8px;} </style>
</head>
<body>
<div class="container">
  <div class="nav">
    <a href="index.php">&#8962; الرئيسية</a>
    <a href="dashboard.php">لوحة التحكم</a>
    <a href="wallets.php">المحفظة</a>
    <a href="invoices.php">الفواتير</a>
    <a href="approvals.php">الموافقات</a>
  </div>
  <div class="card">
    <h2>طلبات الموافقة</h2>
    <?php if ($message): ?><div style="padding:10px;border-radius:8px;background:rgba(255,255,255,0.08);"><?= $message ?></div><?php endif; ?>
    <table>
      <thead><tr><th>المرجع</th><th>النوع</th><th>المبلغ</th><th>الحالة</th><th>السبب</th><th>الإجراء</th></tr></thead>
      <tbody>
        <?php foreach ($requests as $request): ?>
          <tr>
            <td><a href="approval_detail.php?id=<?= (int)$request['id'] ?>" style="color:#ffd54f;text-decoration:none;"><?= htmlspecialchars($request['reference']) ?></a></td>
            <td><?= htmlspecialchars($request['type']) ?></td>
            <td><?= number_format((float)$request['amount'], 2) ?> <?= htmlspecialchars($request['currency']) ?></td>
            <td><span class="badge"><?= htmlspecialchars($request['status']) ?></span></td>
            <td><?= htmlspecialchars($request['reason'] ?? '-') ?></td>
            <td>
              <?php if ($request['status'] === 'pending'): ?>
                <form method="POST" style="display:inline-block;">
                  <input type="hidden" name="id" value="<?= $request['id'] ?>">
                  <button class="btn btn-success" name="approve_request">قبول</button>
                </form>
                <form method="POST" style="display:inline-block;">
                  <input type="hidden" name="id" value="<?= $request['id'] ?>">
                  <textarea name="reason" placeholder="سبب الرفض..."></textarea>
                  <button class="btn btn-danger" name="reject_request">رفض</button>
                </form>
              <?php else: ?>
                <span class="badge">تمت المعالجة</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
