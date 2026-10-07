<?php
/**
 * DI PARMA | Queue Processor — Cron Job
 * يعالج ledger_transfer_queue و forward_transfer_queue
 *
 * تشغيل كل 5 دقائق:
 * * /5 * * * * php /var/www/html/DIPARMA40/cron/process_queues.php >> /var/www/html/DIPARMA40/logs/cron.log 2>&1
 */

if (PHP_SAPI !== 'cli' && !isset($_SERVER['HTTP_X_CRON_SECRET'])) {
    http_response_code(403);
    die('Forbidden');
}
if (isset($_SERVER['HTTP_X_CRON_SECRET'])) {
    $expected = getenv('CRON_SECRET') ?: '';
    if ($expected === '' || !hash_equals($expected, $_SERVER['HTTP_X_CRON_SECRET'])) {
        http_response_code(403);
        die('Forbidden');
    }
}

define('DB_SILENT_FAIL', true);
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/database.php';

$db  = db();
$log = function(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
};

$log('=== Queue Processor Start ===');

// ══════════════════════════════════════════════════════
// 1. ledger_transfer_queue — إرسال USDT للـ Ledger
// ══════════════════════════════════════════════════════
$log('Processing ledger_transfer_queue...');
$ledgerItems = [];
try {
    $ledgerItems = $db->query(
        "SELECT * FROM dp_ledger_transfer_queue
         WHERE status='queued' AND attempts < 5
         ORDER BY created_at ASC LIMIT 10"
    );
} catch (\Throwable $e) {
    $log('ledger_transfer_queue not found or error: ' . $e->getMessage());
}

foreach ($ledgerItems as $item) {
    $id        = (int)$item['id'];
    $ref       = (string)$item['reference'];
    $addr      = (string)$item['ledger_address'];
    $usdt      = (float)$item['usdt_amount'];
    $currency  = (string)($item['currency_orig'] ?? 'USD');

    $log("Ledger item #{$id} ref={$ref} amount={$usdt} USDT addr={$addr}");

    // تحديث attempts و status
    $db->execute(
        "UPDATE dp_ledger_transfer_queue SET attempts=attempts+1, status='processing', updated_at=NOW() WHERE id=?",
        [$id]
    );

    try {
        require_once dirname(__DIR__) . '/lib/HotWalletService.php';
        require_once dirname(__DIR__) . '/lib/WalletService.php';

        $send = HotWalletService::getInstance()->sendUSDT($ref, $addr, $usdt, 0);

        if (!empty($send['success'])) {
            $txid = $send['tx_hash'] ?? '';
            $db->execute(
                "UPDATE dp_ledger_transfer_queue SET status='done', txid=?, processed_at=NOW(), updated_at=NOW() WHERE id=?",
                [$txid, $id]
            );
            $db->execute(
                "UPDATE dp_transactions SET ledger_txid=?, ledger_transferred=1, ledger_status='completed', updated_at=NOW() WHERE reference=?",
                [$txid, $ref]
            );
            $log("✅ Ledger sent #{$id}: txid={$txid}");
        } else {
            $err = $send['message'] ?? 'send failed';
            $db->execute(
                "UPDATE dp_ledger_transfer_queue SET status='queued', message=?, updated_at=NOW() WHERE id=?",
                [substr($err, 0, 500), $id]
            );
            $log("❌ Ledger failed #{$id}: {$err}");
        }
    } catch (\Throwable $e) {
        $db->execute(
            "UPDATE dp_ledger_transfer_queue SET status='queued', message=?, updated_at=NOW() WHERE id=?",
            [substr($e->getMessage(), 0, 500), $id]
        );
        $log("❌ Ledger exception #{$id}: " . $e->getMessage());
    }
}

if (empty($ledgerItems)) {
    $log('No pending ledger transfers.');
}

// ══════════════════════════════════════════════════════
// 2. forward_transfer_queue — تحويل لبنك أو بوابة أخرى
// ══════════════════════════════════════════════════════
$log('Processing forward_transfer_queue...');
$forwardItems = [];
try {
    $forwardItems = $db->query(
        "SELECT * FROM dp_forward_transfer_queue
         WHERE status='pending' AND attempts < 3
         ORDER BY created_at ASC LIMIT 10"
    );
} catch (\Throwable $e) {
    $log('forward_transfer_queue not found or empty: ' . $e->getMessage());
}

foreach ($forwardItems as $item) {
    $id          = (int)$item['id'];
    $ref         = (string)$item['reference'];
    $fromGateway = (string)$item['from_gateway'];
    $destination = (string)$item['destination'];
    $amount      = (float)$item['amount'];
    $net         = (float)$item['net_amount'];
    $currency    = (string)$item['currency'];

    $log("Forward item #{$id} ref={$ref} from={$fromGateway} to={$destination} net={$net} {$currency}");

    $db->execute(
        "UPDATE dp_forward_transfer_queue SET attempts=attempts+1, updated_at=NOW() WHERE id=?",
        [$id]
    );

    // البنوك: تسجيل فقط (تحويل يدوي)
    if (str_starts_with($destination, 'bank:')) {
        $db->execute(
            "UPDATE dp_forward_transfer_queue SET status='pending_manual', updated_at=NOW() WHERE id=?",
            [$id]
        );
        $log("⚠️ Manual bank transfer required: #{$id} to {$destination}");
        continue;
    }

    // Wise: استخدام WiseService
    if ($destination === 'gateway:wise') {
        try {
            require_once dirname(__DIR__) . '/lib/WiseService.php';
            $wise = WiseService::getInstance();
            $profileId = getenv('WISE_PROFILE_ID') ?: '';
            if ($profileId === '') {
                $log("❌ WISE_PROFILE_ID missing for forward #{$id}");
                continue;
            }
            // تسجيل كـ pending_manual حتى يكتمل تكامل Wise
            $db->execute(
                "UPDATE dp_forward_transfer_queue SET status='pending_manual', updated_at=NOW() WHERE id=?",
                [$id]
            );
            $log("⚠️ Wise forward registered: #{$id} net={$net} {$currency}");
        } catch (\Throwable $e) {
            $log("❌ Wise forward exception #{$id}: " . $e->getMessage());
        }
        continue;
    }

    // بوابات أخرى: تسجيل كـ pending_manual
    $db->execute(
        "UPDATE dp_forward_transfer_queue SET status='pending_manual', updated_at=NOW() WHERE id=?",
        [$id]
    );
    $log("⚠️ Manual gateway forward required: #{$id} to {$destination}");
}

if (empty($forwardItems)) {
    $log('No pending forward transfers.');
}

$log('=== Queue Processor Done ===');
