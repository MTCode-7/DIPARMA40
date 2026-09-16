<?php
/**
 * Thin wrapper — POS lives in /pos.
 * Old bookmarks keep working. The rest of the project is unchanged.
 */
$qs = $_SERVER['QUERY_STRING'] ?? '';
$target = 'pos/index.php';
if ($qs !== '') {
    $target = 'pos/index.php?' . $qs;
}
header('Location: ' . $target, true, 302);
exit;
