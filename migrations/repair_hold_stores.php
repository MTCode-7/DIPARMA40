<?php
/**
 * One-shot, idempotent hold repair.
 * Local and public: php migrations/repair_hold_stores.php
 * Does not delete payment rows and does not invent RRN or approval codes.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/pos/lib/operations.php';
require_once $root . '/lib/HoldStoreSync.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$force = in_array('--force', $argv ?? [], true);
$stats = HoldStoreSync::repairOnce($force);
echo json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
exit(!empty($stats['ok']) || !empty($stats['skipped']) ? 0 : 1);
