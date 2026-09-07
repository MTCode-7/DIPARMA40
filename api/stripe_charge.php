<?php
/**
 * DI PARMA | Stripe Checkout API
 * Creates a PaymentIntent and returns JSON for Stripe Elements confirmation.
 */
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
ob_start();

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal Stripe server error'], JSON_UNESCAPED_UNICODE);
});

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../lib/CardPaymentService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
if (!verifyCsrfToken($payload['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF invalid'], JSON_UNESCAPED_UNICODE);
    exit;
}

$amount = (float)($payload['amount'] ?? 0);
if ($amount <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Amount must be greater than zero'], JSON_UNESCAPED_UNICODE);
    exit;
}

$reference = trim((string)($payload['reference'] ?? generateReference('STRIPE')));
$email = trim((string)($payload['email'] ?? 'guest@diparmas.com'));
$currency = strtoupper(trim((string)($payload['currency'] ?? 'USD')));

try {
    $result = CardPaymentService::getInstance()->createPayment([
        'reference' => $reference,
        'amount' => $amount,
        'currency' => strtolower($currency),
        'email' => $email,
        'user_id' => (int)$_SESSION['user_id'],
        'card_provider' => 'stripe',
        'metadata' => [
            'transaction_type' => trim((string)($payload['txn_type'] ?? 'purchase')),
            'destination' => trim((string)($payload['destination'] ?? 'gateway')),
            'payment_method_id' => trim((string)($payload['payment_method_id'] ?? '')),
            'card_number' => trim((string)($payload['card_number'] ?? '')),
            'card_expiry' => trim((string)($payload['card_expiry'] ?? '')),
            'card_cvv' => trim((string)($payload['card_cvv'] ?? '')),
            'name' => trim((string)($payload['card_name'] ?? 'Customer')),
        ],
    ]);

    if (ob_get_level() > 0) {
        ob_clean();
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => APP_IS_LOCAL ? $e->getMessage() : 'Stripe request failed',
    ], JSON_UNESCAPED_UNICODE);
}
