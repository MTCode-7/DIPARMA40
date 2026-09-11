<?php
require_once __DIR__ . '/../includes/auth_check.php';
requireAdmin();
header('Location: ../api/offline_approve.php', true, 302);
exit();
