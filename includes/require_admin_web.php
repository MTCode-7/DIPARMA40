<?php
if (PHP_SAPI === 'cli') {
    return;
}
require_once __DIR__ . '/auth_check.php';
requireAdmin();
