<?php
require_once __DIR__ . '/bootstrap.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
pos_clear_device_token();
$_SESSION = [];
session_destroy();
header('Location: ' . pos_login_url($_GET));
exit;
