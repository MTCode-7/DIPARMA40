<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';

$db = db();
$reference = trim((string)($_GET['ref'] ?? $_GET['reference'] ?? ''));
if ($reference === '') {
    header('Location: transactions.php');
    exit();
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$transaction = $db->query(
    'SELECT * FROM ' . DB_PREFIX . 'transactions WHERE reference = ? AND user_id = ? LIMIT 1',
    [$reference, $userId]
)[0] ?? null;
$contract = $db->query(
    'SELECT * FROM ' . DB_PREFIX . 'contracts WHERE reference = ? AND user_id = ? LIMIT 1',
    [$reference, $userId]
)[0] ?? null;

if (!$transaction && !$contract) {
    header('Location: transactions.php');
    exit();
}

$status = strtolower((string)($transaction['status'] ?? 'pending'));
$statusLabel = [
    'completed' => 'Completed',
    'paid' => 'Completed',
    'failed' => 'Failed',
    'cancelled' => 'Cancelled',
    'pending' => 'Pending confirmation',
][$status] ?? ucfirst($status);
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | Contract and Receipt</title>
<style>
body{margin:0;background:#080b13;color:#edf0f7;font:15px Arial,sans-serif}.page{max-width:860px;margin:32px auto;padding:0 18px}.panel{background:#111827;border:1px solid #283449;border-radius:10px;margin:16px 0;padding:22px}h1,h2{margin:0 0 12px;color:#ffd700}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.label{color:#93a4bb;font-size:12px}.value{margin-top:4px;word-break:break-word}.terms{white-space:pre-wrap;line-height:1.7;background:#0b1220;padding:14px;border-radius:7px}.actions{display:flex;gap:10px;flex-wrap:wrap}a,button{border:1px solid #ffd700;background:#ffd700;color:#080b13;border-radius:6px;padding:10px 14px;text-decoration:none;font-weight:700;cursor:pointer}@media print{body{background:#fff;color:#111}.panel{border:1px solid #bbb;background:#fff}h1,h2{color:#111}.actions{display:none}}@media(max-width:600px){.grid{grid-template-columns:1fr}}
</style>
</head>
<body><main class="page">
<h1>DI PARMA</h1>
<div class="panel"><h2>Receipt</h2>
<?php if ($transaction): ?>
<div class="grid"><div><div class="label">Reference</div><div class="value"><?=htmlspecialchars($reference)?></div></div><div><div class="label">Status</div><div class="value"><?=htmlspecialchars($statusLabel)?></div></div><div><div class="label">Amount</div><div class="value"><?=htmlspecialchars(number_format((float)$transaction['amount'],2).' '.(string)$transaction['currency'])?></div></div><div><div class="label">Gateway</div><div class="value"><?=htmlspecialchars((string)($transaction['gateway'] ?? ''))?></div></div></div>
<?php else: ?><p>Receipt details are not available yet.</p><?php endif; ?></div>
<div class="panel"><h2>Electronic Contract</h2>
<?php if ($contract): ?><div class="grid"><div><div class="label">Service</div><div class="value"><?=htmlspecialchars((string)$contract['service_name'])?></div></div><div><div class="label">Delivery method</div><div class="value"><?=htmlspecialchars((string)$contract['delivery_method'])?></div></div></div><p><?=nl2br(htmlspecialchars((string)$contract['service_description']))?></p><p><?=nl2br(htmlspecialchars((string)$contract['delivery_notes']))?></p><div class="terms"><?=htmlspecialchars((string)$contract['terms_text'])?></div>
<?php else: ?><p>Contract details are not available for this transaction.</p><?php endif; ?></div>
<div class="actions"><button type="button" onclick="window.print()">Print</button><a href="transactions.php">Back to transactions</a></div>
</main></body></html>
