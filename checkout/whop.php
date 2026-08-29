<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';
$ref = $_GET['ref'] ?? ('WHP-'.strtoupper(substr(uniqid(),0,8)));
$amount = floatval($_GET['amount'] ?? 0);
$whopUrl = 'https://whop.com/checkout/plan_A4P3nPnySfV8n?ref='.urlencode($ref).'&amount='.$amount;
header('Location: ' . $whopUrl);
exit;
