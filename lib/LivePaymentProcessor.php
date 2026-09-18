<?php
/**
 * Live payment processor — host adapters only.
 * Stripe/Nuvei/PayPal/Square/PayRam/Wise/Gate.io/MyFatoorah run in lib/Adapters.
 * No parallel cURL, no PaymentIntent without a method, no empty live_connected.
 */
if (defined('DI_PARMA_LIVE_PAYMENT_PROCESSOR')) {
    return;
}
define('DI_PARMA_LIVE_PAYMENT_PROCESSOR', true);

if (!class_exists('PaymentGatewayRouter', false)) {
    require_once __DIR__ . '/PaymentGatewayRouter.php';
}

class LivePaymentProcessor
{
    public static function executeLiveTransaction(string $gateway, array $payload): array
    {
        $gateway = strtolower(trim($gateway));
        if (in_array($gateway, ['byzati', 'byz', 'coinbase', 'bitpay'], true)) {
            return ['success' => false, 'message' => 'Gateway is not integrated in this project: ' . $gateway];
        }
        if ($gateway === 'gate.io') {
            $gateway = 'gateio';
        }
        return PaymentGatewayRouter::processTransaction($gateway, $payload);
    }
}
