<?php
/**
 * حذف جميع العمليات غير الحقيقية من dp_transactions
 * تشغيل: php clean_txns.php
 */
define('DB_SILENT_FAIL', true);
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/database.php';
$db = db();

// عرض كل ما في الجدول
$all = $db->query("SELECT id, reference, gateway, amount, currency, status, transaction_type, created_at FROM dp_transactions ORDER BY id ASC");
echo "=== إجمالي العمليات: " . count($all) . " ===\n\n";

if (empty($all)) {
    echo "✅ الجدول فارغ — لا يوجد شيء للحذف\n";
    exit;
}

// طباعة كل عملية
foreach ($all as $t) {
    echo "[{$t['id']}] {$t['reference']} | {$t['gateway']} | {$t['amount']} {$t['currency']} | {$t['status']} | {$t['transaction_type']} | {$t['created_at']}\n";
}

// حذف كل شيء إذا طُلب
if (isset($argv[1]) && $argv[1] === '--delete') {
    $db->execute("DELETE FROM dp_transactions WHERE 1=1");
    // إعادة ضبط auto_increment
    $db->execute("ALTER TABLE dp_transactions AUTO_INCREMENT = 1");
    echo "\n✅ تم حذف كل العمليات وإعادة ضبط العداد\n";
} else {
    echo "\nلحذف كل شيء: php clean_txns.php --delete\n";
}
