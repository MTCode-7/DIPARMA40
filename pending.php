<?php
require_once __DIR__ . '/includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . SITE_URL . '/dashboard.php');
    exit;
}

$lang = isset($_GET['lang']) && in_array($_GET['lang'], ['ar', 'en'], true)
  ? $_GET['lang']
  : (isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'en' ? 'en' : 'ar');
$ar = $lang === 'ar';
$dir = $ar ? 'rtl' : 'ltr';
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DI PARMA | <?= $ar ? 'بانتظار القبول' : 'Pending Approval' ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--gold:#FFD700;--gold2:#FFB700;--bg:#040810;--card:#080d1a;--border:rgba(255,215,0,.18);--muted:#888;--green:#10B981}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:#f0f0f0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{width:100%;max-width:520px;background:var(--card);border:1px solid var(--border);border-radius:20px;padding:38px 30px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.35)}
.icon{width:76px;height:76px;margin:0 auto 18px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:rgba(255,193,7,.12);border:1px solid rgba(255,193,7,.35);color:#ffc107;font-size:2rem}
h1{font-size:1.45rem;color:var(--gold);margin-bottom:10px} .lead{color:#ddd;font-size:.92rem;line-height:1.8;margin-bottom:24px}
.details{display:grid;gap:10px;text-align:<?= $ar ? 'right' : 'left' ?>;margin-bottom:26px}
.detail{display:flex;align-items:center;gap:10px;padding:13px 14px;background:rgba(255,255,255,.035);border-radius:10px;color:#ccc;font-size:.82rem}
.detail i{width:20px;color:var(--gold);text-align:center}.detail strong{color:#fff;margin-<?= $ar ? 'right' : 'left' ?>:auto}
.button{display:inline-block;background:linear-gradient(135deg,var(--gold),var(--gold2));color:#000;text-decoration:none;padding:12px 28px;border-radius:12px;font-weight:800;font-size:.88rem}
.note{color:var(--muted);font-size:.72rem;margin-top:18px;line-height:1.7}
</style>
</head>
<body>
<main class="card">
  <div class="icon"><i class="fas fa-clock"></i></div>
  <h1><?= $ar ? 'تم إنشاء الحساب بنجاح' : 'Account Created Successfully' ?></h1>
  <p class="lead">
    <?= $ar ? 'تم تسجيل معلوماتك كاملة، وحسابك الآن قيد مراجعة الإدارة.' : 'Your information was saved successfully, and your account is now under admin review.' ?>
  </p>
  <div class="details">
    <div class="detail"><i class="fas fa-hourglass-half"></i><span><?= $ar ? 'حالة الحساب' : 'Account Status' ?></span><strong><?= $ar ? 'بانتظار القبول' : 'Pending Approval' ?></strong></div>
    <div class="detail"><i class="fas fa-user-check"></i><span><?= $ar ? 'نوع القبول' : 'Approval Type' ?></span><strong><?= $ar ? 'موافقة الإدارة اليدوية' : 'Manual Admin Approval' ?></strong></div>
    <div class="detail"><i class="fas fa-shield-alt"></i><span><?= $ar ? 'التفعيل' : 'Activation' ?></span><strong><?= $ar ? 'بعد الموافقة فقط' : 'After approval only' ?></strong></div>
  </div>
  <a class="button" href="login.php"><i class="fas fa-sign-in-alt"></i> <?= $ar ? 'العودة لتسجيل الدخول' : 'Back to Login' ?></a>
  <a class="button" style="margin-top:10px;background:transparent;color:var(--gold);border:1px solid var(--gold)" href="support.php"><i class="fas fa-headset"></i> <?= $ar ? 'التواصل مع خدمة العملاء' : 'Contact Customer Service' ?></a>
  <p class="note"><?= $ar ? 'لا يمكن تسجيل الدخول حتى تتم الموافقة على الحساب من الإدارة.' : 'Login is unavailable until an administrator approves your account.' ?></p>
</main>
</body>
</html>
