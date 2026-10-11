<?php
/**
 * Idempotent cleanup: delete failed transaction rows from dp_transactions.
 * Does not print card data. Safe to run more than once.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

$count = diparma_purge_failed_transactions(db());
echo 'purged_failed=' . (int) $count . PHP_EOL;
