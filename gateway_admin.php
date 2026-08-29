<?php
/**
 * ============================================================
 * DI PARMA | توجيه إداري - Gateway Admin Redirect
 * ============================================================
 */

// التحقق من صلاحيات المدير قبل إعادة التوجيه لضمان الأمان
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';

$db = db();
$userId = $_SESSION['user_id'] ?? 0;
$user = $db->find('users', ['id' => $userId]);
$isAdmin = $user && isset($user['role']) && strtolower($user['role']) === 'admin';

if (!$isAdmin) {
    header('Location: dashboard.php?error=unauthorized');
    exit();
}

// إعادة توجيه المدير بسلام إلى لوحة إدارة البوابات الجديدة
header('Location: admin/gateway_manager.php');
exit();