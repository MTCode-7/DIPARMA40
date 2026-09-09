<?php
/**
 * DI PARMA | Stripe Checkout API
 * Creates a PaymentIntent and returns JSON for Stripe Elements confirmation.
 * For 2D/MOTO: charges card directly via StripeAdapter (no 3DS redirect).
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

$reference    = trim((string)($payload['reference']    ?? generateReference('STRIPE')));
$email        = trim((string)($payload['email']        ?? 'guest@diparmas.com'));
$currency     = strtoupper(trim((string)($payload['currency'] ?? 'USD')));
$securityMode = strtoupper(trim((string)($payload['security_mode'] ?? '3D')));
$txnType      = trim((string)($payload['txn_type']     ?? 'purchase'));
$cardNumber   = preg_replace('/\D/', '', $payload['card_number'] ?? '');

try {
    // ── مسار 2D/MOTO: بطاقة يدوية مباشرة عبر StripeAdapter ──
    if ($securityMode === '2D' && strlen($cardNumber) >= 13) {

        if (!class_exists('GatewayAdapterFactory')) {
            require_once __DIR__ . '/../lib/Adapters/GatewayAdapterInterface.php';
            require_once __DIR__ . '/../lib/Adapters/GatewayErrorMapper.php';
            require_once __DIR__ . '/../lib/Adapters/GatewayLogger.php';
            require_once __DIR__ . '/../lib/Adapters/StripeAdapter.php';
            require_once __DIR__ . '/../lib/Adapters/GatewayAdapterFactory.php';
        }

        // purchase_advice: تأكيد مسبق بدون تحصيل فعلي
        if (in_array($txnType, ['purchase_advice', 'auth_capture'], true)) {
            $rrn          = trim((string)($payload['orig_ref']      ?? ''));
            $approvalCode = trim((string)($payload['approval_code'] ?? ''));

            if (!function_exists('gateway_service')) {
                require_once __DIR__ . '/../includes/gateways.php';
            }
            $settlement = gateway_service()->settlePreAuthorization('stripe', [
                'order_ref'     => $reference,
                'amount'        => $amount,
                'currency'      => $currency,
                'rrn'           => $rrn,
                'approval_code' => $approvalCode,
                'card_number'   => $cardNumber,
                'card_expiry'   => trim((string)($payload['card_expiry'] ?? '')),
                'cvv2'          => trim((string)($payload['card_cvv'] ?? '')),
                'name'          => trim((string)($payload['card_name'] ?? 'Customer')),
                'email'         => $email,
            ]);

            if (ob_get_level() > 0) ob_clean();
            echo json_encode(array_merge($settlement, [
                'reference'      => $reference,
                'transaction_type' => $txnType,
                'status_message' => $settlement['success'] ? 'APPROVED' : 'DECLINED',
            ]), JSON_UNESCAPED_UNICODE);
            exit;
        }

        // purchase_2d / offline / online: charge مباشر
        $normalizedPayload = GatewayAdapterFactory::normalizePayload([
            'amount'          => $amount,
            'currency'        => $currency,
            'card_number'     => $cardNumber,
            'card_expiry'     => trim((string)($payload['card_expiry'] ?? '')),
            'cvv2'            => trim((string)($payload['card_cvv'] ?? '')),
            'processing_mode' => '2D',
            'reference'       => $reference,
            'name'            => trim((string)($payload['card_name'] ?? 'Customer')),
            'email'           => $email,
            'approval_code'   => trim((string)($payload['approval_code'] ?? '')),
        ]);

        $result = GatewayAdapterFactory::process($normalizedPayload, 'charge', 'stripe');

        if ($result['success']) {
            // حفظ في DB
            db()->insert('transactions', [
                'reference'        => $reference,
                'gateway'          => 'stripe',
                'amount'           => $amount,
                'currency'         => $currency,
                'customer_name'    => trim((string)($payload['card_name'] ?? 'Customer')),
                'customer_email'   => $email,
                'status'           => 'completed',
                'transaction_type' => $txnType ?: 'stripe_2d',
                'user_id'          => intval($_SESSION['user_id']),
                'fees'             => 0,
                'net_amount'       => $amount,
                'security_mode'    => '2D',
                'gateway_response' => json_encode($result),
                'created_at'       => date('Y-m-d H:i:s'),
            ]);
        }

        if (ob_get_level() > 0) ob_clean();
        echo json_encode(array_merge($result, [
            'reference'      => $reference,
            'status_message' => $result['success'] ? 'APPROVED' : ($result['message'] ?? 'DECLINED'),
        ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // ── مسار 3D: PaymentIntent عبر CardPaymentService (Stripe Elements) ──
    $result = CardPaymentService::getInstance()->createPayment([
        'reference'    => $reference,
        'amount'       => $amount,
        'currency'     => strtolower($currency),
        'email'        => $email,
        'user_id'      => (int)$_SESSION['user_id'],
        'card_provider' => 'stripe',
        'metadata'     => [
            'transaction_type'  => $txnType,
            'destination'       => trim((string)($payload['destination'] ?? 'gateway')),
            'payment_method_id' => trim((string)($payload['payment_method_id'] ?? '')),
            'name'              => trim((string)($payload['card_name'] ?? 'Customer')),
        ],
    ]);

    if (ob_get_level() > 0) ob_clean();
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    if (ob_get_level() > 0) ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => (defined('APP_IS_LOCAL') && APP_IS_LOCAL) ? $e->getMessage() : 'Stripe request failed',
    ], JSON_UNESCAPED_UNICODE);
}
