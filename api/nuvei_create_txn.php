<?php
/**
 * DI PARMA | Nuvei — Create Transaction ID Only (no charge)
 * POST: { amount, currency, email, csrf_token }
 * Returns: { success, transaction_id, session_token, reference }
 */
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success'=>false,'message'=>'Unauthorized']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;

if (!verifyCsrfToken($payload['csrf_token'] ?? '')) {
    echo json_encode(['success'=>false,'message'=>'CSRF invalid']);
    exit;
}

$amount   = floatval($payload['amount']   ?? 1);
$currency = strtoupper(trim($payload['currency'] ?? 'USD'));
$email    = trim($payload['email'] ?? 'guest@diparmas.com');
$cardholderName = trim((string) ($payload['name'] ?? $payload['card_name'] ?? ''));

if ($cardholderName === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Nuvei requires the cardholder name and a valid customer email',
    ]);
    exit;
}

$ref  = 'TXN' . strtoupper(bin2hex(random_bytes(5))) . date('Ymd');
$amtF = number_format($amount, 2, '.', '');
require_once __DIR__ . '/../lib/Adapters/GatewayAdapterFactory.php';
$order = GatewayAdapterFactory::resolve('nuvei')->openOrder([
    'amount' => $amount,
    'currency' => $currency,
    'email' => $email,
    'name' => $cardholderName,
    'reference' => $ref,
    'transactionType' => 'Auth',
]);

if (!empty($order['success']) && !empty($order['session_token'])) {
    // نحفظ في DB
    try {
        $db = db();
        $db->execute(
            "INSERT INTO dp_transactions
             (reference, gateway, amount, currency, customer_email, status,
              transaction_type, security_mode, gateway_response, created_at)
             VALUES (?,?,?,?,?,'pending','Nuvei Create TxID','2D',?,NOW())",
            [
                $ref, 'nuvei', $amount, $currency, $email,
                json_encode([
                    'session_token' => $order['session_token'],
                    'checkout_url'  => $order['checkout_url'] ?? '',
                    'amount'        => $amtF,
                    'currency'      => $currency,
                ])
            ]
        );
    } catch (Throwable $e) {
        error_log('[Nuvei Create Txn] DB error: ' . $e->getMessage());
    }
    echo json_encode([
        'success'       => true,
        'reference'     => $ref,
        'transaction_id' => $order['session_token'],
        'session_token' => $order['session_token'],
        'checkout_url'  => $order['checkout_url'] ?? '',
        'message'       => 'Transaction created — use sessionToken as Transaction ID',
        'note'          => 'Use this in capture.php as Transaction ID',
    ]);
} else {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => (string) ($order['message'] ?? 'Nuvei openOrder failed'),
    ]);
}
