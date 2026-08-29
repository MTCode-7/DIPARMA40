<?php
/**
 * DI PARMA | Whop Webhook Receiver
 * URL: https://diparmas.com/api/whop_webhook.php
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../lib/Adapters/WhopAdapter.php';

$rawBody  = file_get_contents('php://input');
$sig      = $_SERVER['HTTP_X_WHOP_SIGNATURE'] ?? $_SERVER['HTTP_SIGNATURE'] ?? '';

$adapter  = new WhopAdapter();
$result   = $adapter->handleWebhook($rawBody, $sig);

http_response_code($result['success'] ? 200 : 400);
echo json_encode($result);
