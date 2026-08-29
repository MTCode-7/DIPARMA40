<?php
/**
 * DI PARMA | Checkout — RedotPay Connect
 * Stablecoin Payment → Ledger TRX
 */
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../lib/RedotPayAdapter.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['success'=>false,'message'=>'POST only']); exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?: $_POST;

if (!verifyCsrfToken($body['csrf_token'] ?? '')) {
    http_response_code(403); echo json_encode(['success'=>false,'message'=>'Invalid CSRF']); exit;
}

$amount     = (float)($body['amount']     ?? 0);
$currency   = strtoupper($body['currency'] ?? 'USD');
$ledgerAddr = trim($body['ledger_addr']   ?? 'TEwLFWlwK55b7PuFfzgH1H2f3xs3pLgLn2');
$notes      = trim($body['notes']         ?? '');

if ($amount <= 0) {
    echo json_encode(['success'=>false,'message'=>'Amount required']); exit;
}

$rp     = new RedotPayAdapter();
$siteUrl= defined('SITE_URL') ? rtrim(SITE_URL,'/') : 'https://diparmas.com';
$result = $rp->createOrder([
    'amount'      => $amount,
    'currency'    => $currency,
    'subject'     => 'DI PARMA — Ledger TRX Payment',
    'return_url'  => $siteUrl . '/payment_success.php',
    'webhook_url' => $siteUrl . '/api/webhook.php?gateway=redotpay',
    'ledger_addr' => $ledgerAddr,
]);

if ($result['success']) {
    // تسجيل في DB
    try {
        db()->execute(
            "INSERT INTO dp_transactions
             (reference, gateway, amount, currency, status, transaction_type,
              gateway_response, notes, created_at)
             VALUES (?,?,?,?,'pending','redotpay_order',?,?,NOW())",
            [
                $result['order_id'],
                'redotpay',
                $amount,
                $currency,
                json_encode($result['raw']),
                $notes,
            ]
        );
    } catch (Exception $e) { error_log('[RedotPay] DB: '.$e->getMessage()); }
}

echo json_encode($result);
