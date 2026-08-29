<?php
// ============================================================
// التحقق من المصادقة - XAMPP
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

// معالجة تغيير اللغة قبل أي شيء
if (isset($_GET['lang']) && in_array($_GET['lang'], ['ar', 'en'], true)) {
    setcookie('di_parma_lang', $_GET['lang'], time() + (365 * 24 * 3600), '/');
    $_COOKIE['di_parma_lang'] = $_GET['lang'];
    // redirect لنفس الصفحة بدون lang parameter
    $cleanUrl = strtok($_SERVER['REQUEST_URI'], '?');
    header('Location: ' . $cleanUrl);
    exit();
}

$currentLang = (isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'en') ? 'en' : 'ar';
$pageDir = ($currentLang === 'en') ? 'ltr' : 'rtl';

// تحميل نظام الترجمة
require_once __DIR__ . '/lang.php';

if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: ' . SITE_URL . '/login.php');
    exit();
}

$_SESSION['last_activity'] = time();

try {
    $db = db();
    $user = find('users', ['id' => intval($_SESSION['user_id'])]);

    if (!$user) {
        session_destroy();
        header('Location: ' . SITE_URL . '/login.php');
        exit();
    }

    // حساب معطّل
    if (($user['status'] ?? 'active') === 'inactive') {
        session_destroy();
        header('Location: ' . SITE_URL . '/login.php?account_disabled=1');
        exit();
    }

    // حساب في انتظار موافقة الأدمن
    if (($user['status'] ?? 'active') === 'pending') {
        session_destroy();
        header('Location: ' . SITE_URL . '/login.php?pending=1');
        exit();
    }

    $_SESSION['user_data'] = $user;
} catch (Exception $e) {
    session_destroy();
    header('Location: ' . SITE_URL . '/login.php?pending=1');
    exit();
}

if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time'] > SESSION_TIMEOUT)) {
    session_destroy();
    header('Location: ' . SITE_URL . '/login.php?session_expired=1');
    exit();
}

function getCurrentUser() {
    return $_SESSION['user_data'] ?? null;
}

function isAdmin() {
    $user = getCurrentUser();
    return !empty($user['role']) && strtolower((string)$user['role']) === 'admin';
}

function requireAdmin() {
    if (!isAdmin()) {
        header('Location: ' . SITE_URL . '/dashboard.php');
        exit();
    }
}
?>