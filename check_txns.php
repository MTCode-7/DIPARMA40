<?php
define('DB_SILENT_FAIL', true);
require 'includes/config.php';
require 'includes/database.php';
$db = db();

echo "=== كل العمليات في dp_transactions ===\n";
$txns = $db->query("SELECT id, reference, gateway, amount, currency, status, transaction_type, created_at FROM dp_transactions ORDER BY created_at DESC");
echo "العدد الكلي: " . count($txns) . "\n\n";

foreach ($txns as $t) {
    $fake = false;
    $reasons = [];

    // علامات الوهم
    if (str_contains($t['reference'] ?? '', 'TEST') ||
        str_contains($t['reference'] ?? '', 'test') ||
        str_contains($t['reference'] ?? '', 'FAKE') ||
        str_contains($t['reference'] ?? '', 'demo') ||
        str_contains($t['reference'] ?? '', 'DEMO')) {
        $fake = true; $reasons[] = 'reference وهمي';
    }
    if (in_array($t['gateway'] ?? '', ['integrated','test','fake','demo','simulation','simulated'])) {
        $fake = true; $reasons[] = 'gateway وهمي';
    }
    if (in_array($t['transaction_type'] ?? '', ['test','fake','demo','simulation'])) {
        $fake = true; $reasons[] = 'transaction_type وهمي';
    }
    if (str_contains($t['transaction_type'] ?? '', 'test') ||
        str_contains($t['transaction_type'] ?? '', 'demo')) {
        $fake = true; $reasons[] = 'type يحتوي test/demo';
    }

    $icon = $fake ? '❌ وهمي' : '✅ حقيقي';
    $reason = $fake ? ' ← ' . implode(', ', $reasons) : '';
    echo "[{$t['id']}] {$t['reference']} | {$t['gateway']} | {$t['amount']} {$t['currency']} | {$t['status']} | {$t['transaction_type']} — {$icon}{$reason}\n";
}

echo "\n=== عمليات وهمية للحذف ===\n";
$fake_ids = [];
foreach ($txns as $t) {
    if (
        str_contains($t['reference'] ?? '', 'TEST') ||
        str_contains($t['reference'] ?? '', 'test') ||
        str_contains($t['reference'] ?? '', 'FAKE') ||
        str_contains($t['reference'] ?? '', 'demo') ||
        str_contains($t['reference'] ?? '', 'DEMO') ||
        in_array($t['gateway'] ?? '', ['integrated','test','fake','demo','simulation','simulated']) ||
        in_array($t['transaction_type'] ?? '', ['test','fake','demo','simulation']) ||
        str_contains($t['transaction_type'] ?? '', 'test') ||
        str_contains($t['transaction_type'] ?? '', 'demo')
    ) {
        $fake_ids[] = $t['id'];
        echo "  → حذف ID={$t['id']} ref={$t['reference']} gateway={$t['gateway']}\n";
    }
}

if (isset($_GET['delete']) && $_GET['delete'] === 'yes' && !empty($fake_ids)) {
    $placeholders = implode(',', array_fill(0, count($fake_ids), '?'));
    $deleted = $db->execute("DELETE FROM dp_transactions WHERE id IN ({$placeholders})", $fake_ids);
    echo "\n✅ تم حذف {$deleted} عملية وهمية\n";
} elseif (!empty($fake_ids)) {
    echo "\nلحذفها افتح: check_txns.php?delete=yes\n";
} else {
    echo "\n✅ لا توجد عمليات وهمية\n";
}
