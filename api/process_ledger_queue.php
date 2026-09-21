<?php
/**
 * إعادة محاولة تحويلات Ledger المعلّقة عبر HotWalletService فقط.
 * CLI: php api/process_ledger_queue.php
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../gateway/OnChainMonitor.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'CLI only']);
    exit(1);
}

$result = (new OnChainMonitor())->processPendingQueue();
echo json_encode([
    'success'   => true,
    'processed' => (int) ($result['processed'] ?? 0),
], JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit(0);
