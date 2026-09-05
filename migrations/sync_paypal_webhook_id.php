<?php
$envFile = '/var/www/html/DIPARMA40/.env';
$webhookId = '3MP94571V5580482K';
$contents = file_get_contents($envFile);
$updated = preg_replace('/^PAYPAL_WEBHOOK_ID=.*$/m', 'PAYPAL_WEBHOOK_ID=' . $webhookId, $contents, -1, $count);
if ($count === 0) {
    $updated .= PHP_EOL . 'PAYPAL_WEBHOOK_ID=' . $webhookId . PHP_EOL;
}
file_put_contents($envFile, $updated);
echo "PAYPAL_WEBHOOK_ID synchronized\n";
