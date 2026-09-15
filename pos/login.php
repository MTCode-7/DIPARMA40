<?php
/**
 * POS kiosk login — stays on the terminal. Real account only.
 */
require_once __DIR__ . '/bootstrap.php';

$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'ar' ? 'ar' : 'en';
$ar = ($lang === 'ar');
$dir = $ar ? 'rtl' : 'ltr';

$resolvedLogin = pos_device_get((string)($_POST['device'] ?? $_GET['device'] ?? $_COOKIE['di_parma_pos_model'] ?? 'bitel_ic3600'));
$chosenModel = $resolvedLogin['model'] ?? 'bitel_ic3600';
$tid = pos_normalize_terminal_id((string)($_POST['tid'] ?? $_GET['tid'] ?? $_COOKIE['di_parma_pos_tid'] ?? pos_default_terminal_id()));
$kiosk = (string)($_POST['kiosk'] ?? $_GET['kiosk'] ?? '1') === '1';
$gw = pos_normalize_gateway((string)($_GET['gw'] ?? $_POST['gw'] ?? ''));

if (pos_restore_operator() && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $q = array_filter([
        'kiosk' => '1',
        'device' => $chosenModel,
        'tid' => $tid,
        'gw' => $gw,
        'line' => (string)($_GET['line'] ?? ''),
        'op' => (string)($_GET['op'] ?? ''),
        'mode' => (string)($_GET['mode'] ?? ''),
    ]);
    header('Location: ' . (function_exists('pos_url') ? pos_url('index.php', $q) : ('/pos/index.php?' . http_build_query($q))));
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = $ar ? 'رمز الأمان غير صالح.' : 'Invalid security token.';
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if ($username === '' || $password === '') {
            $error = $ar ? 'أدخل المستخدم وكلمة المرور.' : 'Enter username and password.';
        } else {
            $user = find('users', ['username' => $username]) ?: find('users', ['email' => $username]);
            $status = $user['status'] ?? 'active';
            if (!$user || $status === 'inactive') {
                $error = $ar ? 'الحساب غير متاح.' : 'Account is not available.';
            } elseif ($status === 'pending') {
                $error = $ar ? 'الحساب بانتظار الموافقة.' : 'Account is pending approval.';
            } elseif (!password_verify($password, $user['password_hash'] ?? '')) {
                $error = $ar ? 'المستخدم أو كلمة المرور غير صحيحة.' : 'Incorrect username or password.';
            } else {
                session_regenerate_id(true);
                pos_bind_session($user, $tid, $chosenModel);
                pos_issue_device_token((int)$user['id'], $tid, $chosenModel);
                setcookie('di_parma_pos_tid', $tid, time() + (365 * 24 * 3600), '/');
                setcookie('di_parma_pos_model', $chosenModel, time() + (365 * 24 * 3600), '/');
                try {
                    update('users', ['last_login' => date('Y-m-d H:i:s')], ['id' => $user['id']]);
                } catch (Throwable $e) {
                }
                $q = array_filter([
                    'kiosk' => '1',
                    'device' => $chosenModel,
                    'tid' => $tid,
                    'gw' => $gw,
                    'line' => (string)($_POST['line'] ?? $_GET['line'] ?? ''),
                    'op' => (string)($_POST['op'] ?? $_GET['op'] ?? ''),
                    'mode' => (string)($_POST['mode'] ?? $_GET['mode'] ?? ''),
                ]);
                header('Location: ' . (function_exists('pos_url') ? pos_url('index.php', $q) : ('/pos/index.php?' . http_build_query($q))));
                exit;
            }
        }
    }
}

$csrf = generateCsrfToken();
$dev = pos_device_get($chosenModel);
?><!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$dir?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<title>DI PARMA POS | Login</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{min-height:100vh;font-family:'Cairo',sans-serif;background:#020508;color:#edf0f7;display:flex;align-items:center;justify-content:center;padding:20px}
.box{width:100%;max-width:420px;background:#090f1e;border:1.5px solid rgba(255,215,0,.15);border-radius:18px;padding:24px}
h1{color:#FFD700;font-size:1.2rem;margin-bottom:6px}
p{color:#718096;font-size:.78rem;margin-bottom:18px;line-height:1.6}
label{display:block;font-size:.72rem;color:#FFD700;margin:10px 0 4px}
input,select{width:100%;background:#020508;border:1px solid rgba(255,215,0,.2);color:#edf0f7;border-radius:10px;padding:11px 12px;font-family:inherit}
.err{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.35);color:#fca5a5;border-radius:10px;padding:10px;font-size:.78rem;margin-bottom:12px}
button{width:100%;margin-top:16px;background:linear-gradient(135deg,#FFD700,#FFB700);border:0;border-radius:12px;padding:12px;font-weight:800;cursor:pointer}
</style>
</head>
<body>
<form class="box" method="post">
  <h1>DI PARMA POS</h1>
  <p><?=$ar?'دخول الجهاز. بعد الدخول يبقى هذا الـ ':'Device login. This '?>IC3600<?=$ar?' مرتبطاً بالحساب.':' stays bound to the account.'?></p>
  <?php if ($error): ?><div class="err"><?=htmlspecialchars($error)?></div><?php endif; ?>
  <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
  <input type="hidden" name="kiosk" value="<?=$kiosk?'1':'0'?>">
  <input type="hidden" name="gw" value="<?=htmlspecialchars($gw)?>">
  <label><?=$ar?'المستخدم أو الإيميل':'Username or email'?></label>
  <input type="text" name="username" autocomplete="username" required>
  <label><?=$ar?'كلمة المرور':'Password'?></label>
  <input type="password" name="password" autocomplete="current-password" required>
  <label><?=$ar?'الموديل':'Model'?></label>
  <select name="device">
    <?=pos_device_select_options((string) $chosenModel, $ar)?>
  </select>
  <label><?=$ar?'رقم الجهاز (TID)':'Terminal ID (TID)'?></label>
  <input type="text" name="tid" value="<?=htmlspecialchars($tid)?>" maxlength="16" required>
  <button type="submit"><?=$ar?'دخول وربط الجهاز':'Sign in and bind device'?></button>
</form>
</body>
</html>
