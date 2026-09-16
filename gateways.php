<?php
require_once __DIR__ . '/includes/auth_check.php';
requireAdmin();
header('Location: admin/gateway_manager.php', true, 302);
exit;
