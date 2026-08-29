<?php
// change_password.php - أداة إعادة تعيين كلمة المرور (للمدير فقط)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// التحقق من تسجيل الدخول وصلاحية المدير
if (empty($_SESSION['user_id'])) {
    header('Location: ' . SITE_URL . '/login.php');
    exit();
}

$db = db();
$user = $db->find('users', ['id' => intval($_SESSION['user_id'])]);
if (!$user || strtolower($user['role'] ?? '') !== 'admin') {
    header('Location: ' . SITE_URL . '/dashboard.php?error=unauthorized');
    exit();
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_password'])) {
    $targetUsername = trim($_POST['username'] ?? 'admin');
    $newPassword    = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (strlen($newPassword) < 8) {
        $message = '❌ كلمة المرور يجب أن تكون 8 أحرف على الأقل';
        $messageType = 'error';
    } elseif ($newPassword !== $confirmPassword) {
        $message = '❌ كلمتا المرور غير متطابقتين';
        $messageType = 'error';
    } else {
        $hashed = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $rows   = $db->update('users', ['password_hash' => $hashed], ['username' => $targetUsername]);

        if ($rows > 0) {
            $message = '✅ تم تغيير كلمة المرور بنجاح للمستخدم: ' . htmlspecialchars($targetUsername);
            $messageType = 'success';
        } else {
            $message = '❌ لم يتم العثور على المستخدم أو لم تتغير أي بيانات';
            $messageType = 'error';
        }
    }
}

// جلب قائمة المستخدمين للعرض في القائمة
$users = $db->query('SELECT username FROM ' . DB_PREFIX . 'users ORDER BY username ASC');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DI PARMA | تغيير كلمة المرور</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Cairo', sans-serif;
            background: linear-gradient(180deg, #020202 0%, #0b0b0b 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: #FFDFA0;
        }
        .card {
            width: 100%;
            max-width: 460px;
            background: rgba(10,16,39,0.95);
            border: 1px solid rgba(255,215,0,0.25);
            border-radius: 20px;
            padding: 35px 30px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.5);
        }
        h2 {
            font-size: 1.4rem;
            font-weight: 800;
            background: linear-gradient(135deg, #FFE066, #FFD700);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 25px;
            text-align: center;
        }
        .form-group { margin-bottom: 18px; }
        .form-group label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            color: #FFDFA0;
            margin-bottom: 7px;
        }
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 11px 14px;
            background: rgba(0,0,0,0.7);
            border: 1.5px solid rgba(255,255,255,0.08);
            border-radius: 10px;
            color: #E8F0FF;
            font-size: 0.95rem;
            font-family: 'Cairo', sans-serif;
            transition: border-color 0.3s;
        }
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #FFD700;
        }
        .btn {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #FFE066, #FFD700);
            color: #000;
            border: none;
            border-radius: 10px;
            font-family: 'Cairo', sans-serif;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            margin-top: 8px;
            transition: opacity 0.2s;
        }
        .btn:hover { opacity: 0.88; }
        .alert {
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 0.9rem;
        }
        .alert-success { background: rgba(76,175,80,0.15); border: 1px solid rgba(76,175,80,0.4); color: #81C784; }
        .alert-error   { background: rgba(217,83,79,0.15);  border: 1px solid rgba(217,83,79,0.4);  color: #EF9A9A; }
        .back-link {
            display: block;
            text-align: center;
            margin-top: 18px;
            color: #888;
            font-size: 0.85rem;
            text-decoration: none;
        }
        .back-link:hover { color: #FFD700; }
    </style>
</head>
<body>
<div class="card">
    <h2>🔑 تغيير كلمة المرور</h2>

    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?>">
            <?= $message ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label>المستخدم</label>
            <select name="username">
                <?php foreach ($users as $u): ?>
                    <option value="<?= htmlspecialchars($u['username']) ?>">
                        <?= htmlspecialchars($u['username']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>كلمة المرور الجديدة</label>
            <input type="password" name="new_password" placeholder="8 أحرف على الأقل" required minlength="8">
        </div>
        <div class="form-group">
            <label>تأكيد كلمة المرور</label>
            <input type="password" name="confirm_password" placeholder="أعد كتابة كلمة المرور" required>
        </div>
        <button type="submit" class="btn">تغيير كلمة المرور</button>
    </form>
    <a href="index.php" class="back-link" style="margin-bottom:6px;display:block;">← الرئيسية</a>
    <a href="dashboard.php" class="back-link">← العودة إلى لوحة التحكم</a>
</div>
</body>
</html>
