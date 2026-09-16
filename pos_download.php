<?php
/**
 * Thin wrapper — POS install/download lives in /pos/install.php
 */
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: pos/install.php' . ($qs !== '' ? ('?' . $qs) : ''), true, 302);
exit;
