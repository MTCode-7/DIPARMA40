<?php
/**
 * DI PARMA | checkout_offline.php
 * يحوّل لـ checkout_router.php — لا محاكاة
 */
require_once __DIR__ . '/includes/auth_check.php';
$params = http_build_query(array_merge($_GET, ['txn_type' => 'purchase', 'sec_mode' => '2D']));
header('Location: checkout_router.php?' . $params, true, 301);
exit;
