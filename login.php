<?php
// ============================================================
// تسجيل الدخول - DI PARMA
// ============================================================

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// معالجة تغيير اللغة
if (isset($_GET['lang']) && in_array($_GET['lang'], ['ar', 'en'], true)) {
    setcookie('di_parma_lang', $_GET['lang'], time() + (365 * 24 * 3600), '/');
    $_COOKIE['di_parma_lang'] = $_GET['lang'];
    $cleanUrl = strtok($_SERVER['REQUEST_URI'], '?');
    header('Location: ' . $cleanUrl);
    exit();
}

$currentLang = (isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'ar') ? 'ar' : 'en';
$pageDir = ($currentLang === 'en') ? 'ltr' : 'rtl';
$pageTitle = ($currentLang === 'en') ? 'DI PARMA | Login' : 'DI PARMA | تسجيل الدخول';
$pageSubTitle = ($currentLang === 'en') ? 'Ultimate Financial Gateway' : 'بوابة الدفع المالية الشاملة';
$usernameLabel = ($currentLang === 'en') ? 'Username' : 'اسم المستخدم';
$passwordLabel = ($currentLang === 'en') ? 'Password' : 'كلمة المرور';
$usernamePlaceholder = ($currentLang === 'en') ? 'Enter username' : 'أدخل اسم المستخدم';
$passwordPlaceholder = ($currentLang === 'en') ? 'Enter password' : 'أدخل كلمة المرور';
$loginButton = ($currentLang === 'en') ? 'Login' : 'تسجيل الدخول';

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /login.php');
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'يرجى إدخال اسم المستخدم وكلمة المرور';
    } else {
        try {
            $db = db();

            $user = find('users', ['username' => $username]);
            
            if (!$user) {
                $user = find('users', ['email' => $username]);
            }
            
            if ($user && ($user['status'] ?? 'active') === 'inactive') {
                $error = 'هذا الحساب معطل ولا يمكن تسجيل الدخول إليه حالياً';
            } elseif ($user && ($user['status'] ?? 'active') === 'pending') {
                $error = $currentLang === 'en'
                    ? 'Your account is pending admin approval. Please wait.'
                    : 'حسابك قيد المراجعة — في انتظار موافقة الإدارة';
            } elseif ($user && password_verify($password, $user['password_hash'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role']    = $user['role'] ?? 'user';
                $_SESSION['username']= $user['username'];
                $_SESSION['user_data'] = [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'first_name' => $user['first_name'] ?? '',
                    'last_name' => $user['last_name'] ?? '',
                    'role' => $user['role'] ?? 'user'
                ];
                $_SESSION['login_time'] = time();
                $_SESSION['last_activity'] = time();
                
                update('users', ['last_login' => date('Y-m-d H:i:s')], ['id' => $user['id']]);
                
                header('Location: /dashboard.php');
                exit();
            } else {
                $error = 'اسم المستخدم أو كلمة المرور غير صحيحة';
            }
        } catch (Exception $e) {
            $error = 'حدث خطأ في النظام';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang) ?>" dir="<?= htmlspecialchars($pageDir) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        :root {
            --gold: #FFD700;
            --gold-dark: #B58E15;
            --bg-dark: #0A0F1E;
            --text-light: #E8F0FF;
            --border-gold: rgba(255,215,0,0.25);
            --danger: #d9534f;
        }
        body {
            font-family: 'Cairo', sans-serif;
            background: radial-gradient(circle at top left, rgba(255,215,0,0.1), transparent 18%),
                        radial-gradient(circle at bottom right, rgba(255,215,0,0.08), transparent 16%),
                        linear-gradient(180deg, #020202 0%, #0b0b0b 35%, #090909 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-container {
            width: 100%;
            max-width: 420px;
            background: rgba(10,16,39,0.94);
            border: 1px solid var(--border-gold);
            border-radius: 24px;
            padding: 40px 35px;
            backdrop-filter: blur(18px);
            box-shadow: 0 20px 60px rgba(0,0,0,0.5);
        }
        .login-logo { text-align:center; margin-bottom:30px; }
        .login-logo .icon {
            display:inline-block; width:60px; height:60px;
            background:linear-gradient(135deg,var(--gold),var(--gold-dark));
            border-radius:16px;
            font-size:2rem; font-weight:900; color:var(--bg-dark);
            line-height:60px;
            box-shadow:0 0 40px rgba(255,215,0,0.2);
            margin-bottom:10px;
        }
        .login-logo h1 {
            font-size:1.8rem; font-weight:800;
            background:linear-gradient(135deg,#FFE066,var(--gold));
            -webkit-background-clip:text; -webkit-text-fill-color:transparent;
        }
        .login-logo p { color:#888; font-size:0.85rem; margin-top:4px; }
        .form-group { margin-bottom:20px; }
        .form-group label {
            display:block; color:#FFDFA0; font-size:0.8rem;
            font-weight:600; margin-bottom:8px;
        }
        .form-group input {
            width:100%; padding:12px 16px;
            background:rgba(0,0,0,0.8);
            border:1.5px solid rgba(255,255,255,0.08);
            border-radius:12px;
            color:var(--text-light); font-size:1rem;
            font-family:'Cairo',sans-serif;
            transition:all 0.3s ease;
        }
        .form-group input:focus {
            outline:none; border-color:var(--gold);
            box-shadow:0 0 25px rgba(255,215,0,0.1);
        }
        .btn-login {
            width:100%; padding:14px;
            background:linear-gradient(135deg,#FFE066,var(--gold),var(--gold-dark));
            border:none; border-radius:12px;
            font-size:1.1rem; font-weight:700;
            color:var(--bg-dark); cursor:pointer;
            transition:all 0.3s ease;
            font-family:'Cairo',sans-serif;
            margin-top:10px;
        }
        .btn-login:hover { transform:translateY(-2px); box-shadow:0 8px 30px rgba(255,215,0,0.3); }
        .alert {
            padding:12px 16px; border-radius:12px;
            margin-bottom:20px; text-align:center;
            font-weight:600; font-size:0.9rem;
        }
        .alert-error {
            background:rgba(217,83,79,0.12);
            border:1px solid var(--danger);
            color:var(--danger);
        }
        .login-footer {
            text-align:center; margin-top:25px;
            color:#666; font-size:0.8rem;
        }
        .security-badge {
            display:flex; justify-content:center; gap:20px;
            margin-top:20px; font-size:0.7rem; color:#555;
        }
    </style>
</head>
<body style="direction: <?= htmlspecialchars($pageDir) ?>;">
<div class="login-container">
    <div class="login-logo">
        <div class="icon">DP</div>
        <h1>DI PARMA</h1>
        <p><?= htmlspecialchars($pageSubTitle) ?></p>
    </div>

    <?php if (isset($_GET['pending'])): ?>
        <div class="alert" style="background:rgba(255,193,7,.1);border:1px solid rgba(255,193,7,.3);color:#ffc107;border-radius:12px;padding:12px 16px;margin-bottom:16px;font-size:.9rem">
            <i class="fas fa-clock" style="margin-left:8px"></i>
            <?= $currentLang === 'en'
                ? 'Account created successfully. Awaiting admin approval before you can login.'
                : 'تم إنشاء حسابك بنجاح — في انتظار موافقة الإدارة قبل تسجيل الدخول' ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['account_disabled'])): ?>
        <div class="alert alert-error"><i class="fas fa-ban"></i>
            <?= $currentLang === 'en' ? 'This account has been disabled.' : 'تم تعطيل هذا الحساب' ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="form-group">
            <label><i class="fas fa-user"></i> <?= htmlspecialchars($usernameLabel) ?></label>
            <input type="text" name="username" placeholder="<?= htmlspecialchars($usernamePlaceholder) ?>" required>
        </div>
        <div class="form-group">
            <label><i class="fas fa-lock"></i> <?= htmlspecialchars($passwordLabel) ?></label>
            <input type="password" name="password" placeholder="<?= htmlspecialchars($passwordPlaceholder) ?>" required>
        </div>
        <button type="submit" name="login" class="btn-login">
            <i class="fas fa-sign-in-alt"></i> <?= htmlspecialchars($loginButton) ?>
        </button>
    </form>

    <div class="login-footer" style="display:flex;flex-direction:column;gap:10px;align-items:center;">
        <a href="mailto:infodiparma@proton.me" style="color:var(--gold);text-decoration:none;font-size:0.9rem;">
            <i class="fas fa-envelope"></i>
            <?= $currentLang === 'en' ? 'Contact us: infodiparma@proton.me' : 'تواصل معنا: infodiparma@proton.me' ?>
        </a>
    </div>

    <div class="security-badge">
        <span><i class="fas fa-shield-alt"></i> PCI DSS Level 1</span>
        <span><i class="fas fa-lock"></i> 256-bit Encryption</span>
    </div>
</div>
</body>
</html>