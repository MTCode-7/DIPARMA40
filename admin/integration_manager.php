<?php
require_once dirname(__DIR__) . '/includes/auth_check.php';
requireAdmin();
header('Location: gateway_manager.php', true, 302);
exit;
