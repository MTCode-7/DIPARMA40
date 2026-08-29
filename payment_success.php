<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';

$reference = trim((string)($_GET['ref'] ?? $_GET['reference_id'] ?? $_GET['reference'] ?? ''));
$transaction = null;

if ($reference !== '') {
    try {
        $db = db();
        $rows = $db->query(
            "SELECT reference, amount, currency, status, gateway, created_at, updated_at
             FROM dp_transactions
             WHERE reference = ? OR JSON_EXTRACT(gateway_response, '$.payram_ref') = JSON_QUOTE(?)
             LIMIT 1",
            [$reference, $reference]
        );
        $transaction = $rows[0] ?? null;
    } catch (Throwable $e) {
        error_log('[Payment Success] Lookup failed: ' . $e->getMessage());
    }
}

$status = strtolower((string)($transaction['status'] ?? 'pending'));
$statusLabels = [
    'completed' => 'Payment completed',
    'paid'      => 'Payment completed',
    'failed'    => 'Payment failed',
    'cancelled' => 'Payment cancelled',
    'pending'   => 'Payment is being confirmed',
];
$statusLabel = $statusLabels[$status] ?? 'Payment status: ' . strtoupper($status);
$statusClass = in_array($status, ['completed', 'paid'], true)
    ? 'success'
    : (in_array($status, ['failed', 'cancelled'], true) ? 'error' : 'pending');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>DI PARMA | Payment Status</title>
    <style>
        :root { color-scheme: dark; font-family: Arial, sans-serif; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #07111f; color: #e8eef7; }
        main { width: min(92vw, 480px); padding: 32px; border: 1px solid #20344d; border-radius: 14px; background: #0d1b2d; box-shadow: 0 18px 60px #0006; text-align: center; }
        h1 { margin: 0 0 10px; font-size: 1.5rem; }
        p { color: #9eb0c5; line-height: 1.6; }
        .status { margin: 24px 0; padding: 16px; border-radius: 10px; font-weight: 700; }
        .success { background: #123d32; color: #67e8b5; }
        .pending { background: #3d3212; color: #f7d774; }
        .error { background: #421d28; color: #ff9eae; }
        dl { margin: 0; text-align: left; }
        dt { margin-top: 14px; color: #8195ad; font-size: .8rem; }
        dd { margin: 4px 0 0; word-break: break-word; }
        .muted { font-size: .85rem; }
    </style>
</head>
<body>
<main>
    <h1>DI PARMA</h1>
    <?php if ($transaction): ?>
        <div class="status <?=htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8')?>">
            <?=htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8')?>
        </div>
        <dl>
            <dt>Reference</dt>
            <dd><?=htmlspecialchars((string)$transaction['reference'], ENT_QUOTES, 'UTF-8')?></dd>
            <dt>Amount</dt>
            <dd><?=htmlspecialchars(number_format((float)$transaction['amount'], 2) . ' ' . (string)$transaction['currency'], ENT_QUOTES, 'UTF-8')?></dd>
        </dl>
    <?php else: ?>
        <div class="status pending">Payment reference not found</div>
        <p class="muted">Your payment may still be processing. Please keep your payment reference and contact support if the status does not update.</p>
    <?php endif; ?>
</main>
</body>
</html>