<?php
require_once __DIR__ . '/includes/auth_check.php';
header('Location: pos/index.php', true, 302);
exit;
