<?php
/**
 * DI PARMA | Whop OAuth Callback
 * يستقبل redirect بعد تسجيل الدخول عبر Whop
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
if(session_status()===PHP_SESSION_NONE) session_start();

$code  = trim($_GET['code']  ?? '');
$state = trim($_GET['state'] ?? '');
$error = trim($_GET['error'] ?? '');

if (!empty($error)) {
    header('Location: /index.php?error=whop_auth_failed');
    exit;
}

if (empty($code)) {
    header('Location: /index.php?error=whop_no_code');
    exit;
}

// تبادل الـ code بـ access token
$clientId     = getenv('WHOP_CLIENT_ID')     ?: '';
$clientSecret = getenv('WHOP_CLIENT_SECRET') ?: '';
$callbackUrl  = getenv('WHOP_CALLBACK_URL')  ?: 'https://diparmas.com/auth/whop_callback.php';

$ch = curl_init('https://api.whop.com/oauth/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'code'          => $code,
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri'  => $callbackUrl,
        'grant_type'    => 'authorization_code',
    ]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_TIMEOUT    => 15,
]);
$res   = curl_exec($ch);
curl_close($ch);
$token = json_decode($res ?: '{}', true);

if (empty($token['access_token'])) {
    header('Location: /index.php?error=whop_token_failed');
    exit;
}

// جلب بيانات المستخدم من Whop
$ch2 = curl_init('https://api.whop.com/v5/me');
curl_setopt_array($ch2, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token['access_token']],
    CURLOPT_TIMEOUT        => 15,
]);
$meRes  = curl_exec($ch2);
curl_close($ch2);
$whopUser = json_decode($meRes ?: '{}', true);

if (empty($whopUser['id'])) {
    header('Location: /index.php?error=whop_user_failed');
    exit;
}

$db       = db();
$whopId   = $whopUser['id'];
$email    = $whopUser['email']    ?? '';
$username = $whopUser['username'] ?? $whopUser['name'] ?? 'whop_' . $whopId;

// تحقق إذا كان المستخدم موجوداً
$existing = $db->find('users', ['email' => $email]);

if ($existing) {
    // تسجيل دخول مباشر
    if ($existing['status'] !== 'active') {
        header('Location: /index.php?error=account_pending');
        exit;
    }
    $_SESSION['user_id']  = $existing['id'];
    $_SESSION['username'] = $existing['username'];
    $_SESSION['role']     = $existing['role'];
    $_SESSION['email']    = $existing['email'];
    header('Location: /dashboard.php');
    exit;
} else {
    // إنشاء حساب جديد بحالة pending
    $userId = $db->insert('users', [
        'username'   => $username,
        'email'      => $email,
        'password'   => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
        'role'       => 'user',
        'status'     => 'pending',
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    // حفظ Whop ID
    try {
        $db->execute(
            "INSERT IGNORE INTO user_meta (user_id, meta_key, meta_value) VALUES (?,?,?)",
            [$userId, 'whop_id', $whopId]
        );
    } catch(Exception $e) {}

    header('Location: /pending.php?from=whop');
    exit;
}
