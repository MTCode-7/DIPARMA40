<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();

$db = db();
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_action'])) {
    $userId = intval($_POST['user_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $role = trim($_POST['role'] ?? '');
    $status = trim($_POST['status'] ?? '');

    if ($userId > 0) {
        $user = $db->find('users', ['id' => $userId]);
        if ($user) {
            if ($action === 'update_role' && in_array($role, ['admin', 'user'], true)) {
                $db->update('users', ['role' => $role], ['id' => $userId]);
                $message = '✅ تم تحديث الدور بنجاح';
                $messageType = 'success';
            } elseif ($action === 'update_status' && in_array($status, ['active', 'inactive'], true)) {
                $db->update('users', ['status' => $status], ['id' => $userId]);
                // إنشاء محافظ تلقائياً عند تفعيل الحساب
                if ($status === 'active') {
                    require_once __DIR__ . '/../lib/WalletManager.php';
                    WalletManager::getInstance()->createWalletsForUser($userId);
                }
                $message = $status === 'active' ? '✅ تم تفعيل الحساب وإنشاء المحافظ' : '✅ تم تعطيل الحساب';
                $messageType = 'success';
            }
        }
    }
}

$users = $db->query('SELECT id, username, email, first_name, last_name, role, status, created_at FROM ' . DB_PREFIX . 'users ORDER BY created_at DESC');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang ?? 'en') ?>" dir="<?= htmlspecialchars($pageDir ?? 'ltr') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DI PARMA | إدارة المستخدمين</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap" rel="stylesheet">
<style>
body{font-family:'Cairo',sans-serif;background:#0b0f17;color:#f7d76b;margin:0;padding:20px;} .container{max-width:1100px;margin:0 auto;} .card{background:rgba(255,255,255,0.05);border:1px solid rgba(255,215,0,0.2);border-radius:16px;padding:20px;margin-bottom:20px;} .nav{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;} .nav a{color:#fff;text-decoration:none;padding:8px 12px;border:1px solid rgba(255,215,0,0.2);border-radius:999px;} table{width:100%;border-collapse:collapse;} th,td{padding:10px;border-bottom:1px solid rgba(255,255,255,0.1);text-align:right;} .badge{display:inline-block;padding:4px 8px;border-radius:999px;background:rgba(255,215,0,0.25);} .btn{padding:8px 12px;border:none;border-radius:8px;cursor:pointer;background:#2bb673;color:#fff;text-decoration:none;display:inline-block;} </style>
</head>
<body>
<div class="container">
  <div class="nav">
    <a href="../index.php">&#8962; الرئيسية</a>
    <a href="../dashboard.php">لوحة التحكم</a>
    <a href="gateway_manager.php">إدارة البوابات</a>
    <a href="users.php">المستخدمين</a>
  </div>
  <div class="card">
    <h2>إدارة المستخدمين</h2>
    <?php if ($message): ?><div style="padding:10px;border-radius:8px;background:rgba(255,255,255,0.08);margin-bottom:12px;"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <table>
      <thead>
        <tr>
          <th>المستخدم</th>
          <th>البريد</th>
          <th>الدور</th>
          <th>الحالة</th>
          <th>التاريخ</th>
          <th>إجراء</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $user): ?>
          <tr>
            <td><?= htmlspecialchars(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: ($user['username'] ?? '-')) ?></td>
            <td><?= htmlspecialchars($user['email'] ?? '-') ?></td>
            <td><span class="badge"><?= htmlspecialchars($user['role'] ?? 'user') ?></span></td>
            <td><span class="badge"><?= htmlspecialchars($user['status'] ?? 'active') ?></span></td>
            <td><?= htmlspecialchars($user['created_at']) ?></td>
            <td>
              <form method="POST" style="display:inline-block;margin-left:6px;">
                <input type="hidden" name="user_action" value="1">
                <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                <input type="hidden" name="action" value="update_role">
                <select name="role" style="padding:8px;border-radius:8px;">
                  <option value="user" <?= (($user['role'] ?? 'user') === 'user') ? 'selected' : '' ?>>user</option>
                  <option value="admin" <?= (($user['role'] ?? 'user') === 'admin') ? 'selected' : '' ?>>admin</option>
                </select>
                <button class="btn" type="submit">تحديث الدور</button>
              </form>
              <form method="POST" style="display:inline-block;margin-left:6px;">
                <input type="hidden" name="user_action" value="1">
                <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                <input type="hidden" name="action" value="update_status">
                <select name="status" style="padding:8px;border-radius:8px;">
                  <option value="active" <?= (($user['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>active</option>
                  <option value="inactive" <?= (($user['status'] ?? 'active') === 'inactive') ? 'selected' : '' ?>>inactive</option>
                </select>
                <button class="btn" type="submit">تحديث الحالة</button>
              </form>
              <a href="../user_profile.php?id=<?= (int)$user['id'] ?>" class="btn">فتح الملف</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
