<?php
/**
 * POS device login — real user account, then this terminal stays signed in.
 */
if (defined('DI_PARMA_POS_AUTH')) {
    return;
}
define('DI_PARMA_POS_AUTH', true);

const POS_DEVICE_COOKIE = 'di_parma_pos_dev';
const POS_DEVICE_TTL = 2592000; // 30 days

function pos_auth_secret(): string
{
    foreach ([
        defined('JWT_SECRET') ? JWT_SECRET : '',
        defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : '',
        defined('WEBHOOK_HMAC_SECRET') ? WEBHOOK_HMAC_SECRET : '',
    ] as $key) {
        if (is_string($key) && strlen($key) >= 16) {
            return $key;
        }
    }
    return hash('sha256', (defined('DB_NAME') ? DB_NAME : 'diparma') . '|pos-device');
}

function pos_auth_b64(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function pos_auth_unb64(string $raw): string
{
    $pad = strlen($raw) % 4;
    if ($pad) {
        $raw .= str_repeat('=', 4 - $pad);
    }
    return (string) base64_decode(strtr($raw, '-_', '+/'), true);
}

function pos_issue_device_token(int $userId, string $terminalId, string $model): void
{
    $payload = [
        'uid' => $userId,
        'tid' => pos_normalize_terminal_id($terminalId),
        'model' => $model,
        'iat' => time(),
        'exp' => time() + POS_DEVICE_TTL,
    ];
    $body = pos_auth_b64(json_encode($payload, JSON_UNESCAPED_UNICODE));
    $sig = hash_hmac('sha256', $body, pos_auth_secret());
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    setcookie(POS_DEVICE_COOKIE, $body . '.' . $sig, [
        'expires' => $payload['exp'],
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[POS_DEVICE_COOKIE] = $body . '.' . $sig;
}

function pos_clear_device_token(): void
{
    setcookie(POS_DEVICE_COOKIE, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE[POS_DEVICE_COOKIE]);
}

function pos_read_device_token(): ?array
{
    $raw = (string)($_COOKIE[POS_DEVICE_COOKIE] ?? '');
    if ($raw === '' || strpos($raw, '.') === false) {
        return null;
    }
    [$body, $sig] = explode('.', $raw, 2);
    $expect = hash_hmac('sha256', $body, pos_auth_secret());
    if (!hash_equals($expect, $sig)) {
        return null;
    }
    $data = json_decode(pos_auth_unb64($body), true);
    if (!is_array($data) || (int)($data['exp'] ?? 0) < time() || (int)($data['uid'] ?? 0) <= 0) {
        return null;
    }
    return $data;
}

function pos_bind_session(array $user, string $terminalId, string $model): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['role'] = $user['role'] ?? 'user';
    $_SESSION['username'] = $user['username'] ?? '';
    $_SESSION['user_data'] = $user;
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['pos_device'] = true;
    $_SESSION['pos_terminal_id'] = pos_normalize_terminal_id($terminalId);
    $_SESSION['pos_model'] = $model;
    if (!function_exists('getCurrentUser')) {
        function getCurrentUser() {
            return $_SESSION['user_data'] ?? null;
        }
    }
}

function pos_restore_operator(): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid > 0 && !empty($_SESSION['pos_device'])) {
        $_SESSION['last_activity'] = time();
        $_SESSION['login_time'] = time();
        return true;
    }
    if ($uid > 0) {
        $_SESSION['pos_device'] = true;
        $_SESSION['last_activity'] = time();
        return true;
    }
    $token = pos_read_device_token();
    if (!$token) {
        return false;
    }
    try {
        $user = find('users', ['id' => (int)$token['uid']]);
    } catch (Throwable $e) {
        return false;
    }
    if (!$user || ($user['status'] ?? 'active') !== 'active') {
        pos_clear_device_token();
        return false;
    }
    pos_bind_session($user, (string)($token['tid'] ?? 'T0000001'), (string)($token['model'] ?? 'bitel_ic3600'));
    return true;
}

function pos_public_dir(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/pos/index.php'));
    if (preg_match('#^(.*?)/pos(?:\\.php|/[^/]*)?$#', $script, $m)) {
        $prefix = $m[1];
        return ($prefix === '' ? '' : $prefix) . '/pos';
    }
    $dir = rtrim(dirname($script), '/');
    if (basename($dir) === 'pos') {
        return $dir;
    }
    return ($dir === '' || $dir === '/' ? '' : $dir) . '/pos';
}

function pos_url(string $file = 'index.php', array $query = []): string
{
    $path = pos_public_dir() . '/' . ltrim($file, '/');
    $query = array_filter($query, static function ($v) {
        return $v !== null && $v !== '';
    });
    return $path . ($query ? ('?' . http_build_query($query)) : '');
}

function pos_login_url(array $query = []): string
{
    $keep = [];
    foreach (['kiosk', 'device', 'tid', 'gw', 'line'] as $key) {
        if (isset($query[$key]) && (string)$query[$key] !== '') {
            $keep[$key] = $query[$key];
        } elseif (isset($_GET[$key]) && (string)$_GET[$key] !== '') {
            $keep[$key] = $_GET[$key];
        }
    }
    if (empty($keep['kiosk'])) {
        $keep['kiosk'] = '1';
    }
    if (empty($keep['device'])) {
        $keep['device'] = 'bitel_ic3600';
    }
    return pos_url('login.php', $keep);
}

function pos_require_operator(): void
{
    if (pos_restore_operator()) {
        return;
    }
    header('Location: ' . pos_login_url($_GET));
    exit;
}
