<?php
/**
 * Alias for MyFatoorah (and other) provider dashboards that still post to webhook_receiver.php.
 * Live handling is api/webhook.php.
 */
if (!isset($_GET['gateway']) || trim((string) $_GET['gateway']) === '') {
    $_GET['gateway'] = 'myfatoorah';
}
require __DIR__ . '/webhook.php';
