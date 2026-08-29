<?php
// ============================================================
// تسجيل الخروج
// ============================================================

require_once __DIR__ . '/includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// مسح جميع بيانات الجلسة
$_SESSION = [];

// حذف كوكي الجلسة
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// تدمير الجلسة
session_destroy();

// التوجيه إلى صفحة تسجيل الدخول
header('Location: /login.php');
exit();
?>