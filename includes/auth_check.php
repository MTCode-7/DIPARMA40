<?php
// ============================================================
// التحقق من المصادقة - XAMPP
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

// معالجة تغيير اللغة قبل أي شيء (إن لم تُعالَج في config)
if (isset($_GET['lang']) && in_array($_GET['lang'], ['ar', 'en'], true)) {
    // config.php عادةً يعيد التوجيه مسبقاً؛ هذا احتياطي فقط
    setcookie('di_parma_lang', $_GET['lang'], time() + (365 * 24 * 3600), '/');
    $_COOKIE['di_parma_lang'] = $_GET['lang'];
    $cleanUrl = strtok($_SERVER['REQUEST_URI'], '?');
    $q = $_GET;
    unset($q['lang']);
    $suffix = $q ? ('?' . http_build_query($q)) : '';
    header('Location: ' . $cleanUrl . $suffix);
    exit();
}

$currentLang = (isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'ar') ? 'ar' : 'en';
$pageDir = ($currentLang === 'ar') ? 'rtl' : 'ltr';
$GLOBALS['currentLang'] = $currentLang;

// تحميل نظام الترجمة
require_once __DIR__ . '/lang.php';

$authBaseUrl = preg_replace('#/checkout$#', '', rtrim(SITE_URL, '/'));

if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: ' . $authBaseUrl . '/login.php');
    exit();
}

$_SESSION['last_activity'] = time();

try {
    $db = db();
    $user = find('users', ['id' => intval($_SESSION['user_id'])]);

    if (!$user) {
        session_destroy();
        header('Location: ' . $authBaseUrl . '/login.php');
        exit();
    }

    // حساب معطّل
    if (($user['status'] ?? 'active') === 'inactive') {
        session_destroy();
        header('Location: ' . $authBaseUrl . '/login.php?account_disabled=1');
        exit();
    }

    // حساب في انتظار موافقة الأدمن
    if (($user['status'] ?? 'active') === 'pending') {
        session_destroy();
        header('Location: ' . $authBaseUrl . '/login.php?pending=1');
        exit();
    }

    $_SESSION['user_data'] = $user;
} catch (Exception $e) {
    session_destroy();
    header('Location: ' . $authBaseUrl . '/login.php?pending=1');
    exit();
}

if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time'] > SESSION_TIMEOUT)) {
    session_destroy();
    header('Location: ' . $authBaseUrl . '/login.php?session_expired=1');
    exit();
}

function getCurrentUser() {
    return $_SESSION['user_data'] ?? null;
}

function isAdmin() {
    $user = getCurrentUser();
    if (!empty($user['role']) && strtolower((string)$user['role']) === 'admin') {
        return true;
    }
    return strtolower((string)($_SESSION['role'] ?? '')) === 'admin';
}

function requireAdmin() {
    if (!isAdmin()) {
        header('Location: ' . $authBaseUrl . '/dashboard.php');
        exit();
    }
}
?>