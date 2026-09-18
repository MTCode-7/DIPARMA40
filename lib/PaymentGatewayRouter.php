<?php
/**
 * Multi-gateway router — live adapters only.
 * No local tx_id, no simulated APPROVED, no demo runner.
 */
if (defined('DI_PARMA_PAYMENT_GATEWAY_ROUTER')) {
    return;
}
define('DI_PARMA_PAYMENT_GATEWAY_ROUTER', true);

class PaymentGatewayRouter
{
    public static function processTransaction(string $gateway, array $payload): array
    {
        if (!function_exists('pos_run_standalone_gateway')) {
            $posGw = dirname(__DIR__) . '/pos/lib/gateways.php';
            if (is_file($posGw)) {
                if (!defined('POS_APP_ROOT')) {
                    define('POS_APP_ROOT', dirname(__DIR__));
                }
                require_once $posGw;
            }
        }
        if (!function_exists('pos_run_standalone_gateway')) {
            return ['success' => false, 'message' => 'POS gateway layer is not loaded'];
        }

        $gateway = pos_normalize_gateway($gateway);
        if ($gateway === '') {
            return ['success' => false, 'message' => 'Unknown payment gateway'];
        }
        if ($gateway === 'diparma_gateway') {
            return ['success' => false, 'message' => 'DIPARMA GATEWAY is settlement to Ledger, not a card charge processor'];
        }
        if (!pos_is_charge_processor($gateway) || !pos_gateway_is_live($gateway)) {
            return ['success' => false, 'message' => 'Pick an enabled payment gateway'];
        }

        $txnType = strtolower(trim((string) ($payload['txn_type'] ?? $payload['type'] ?? 'purchase_2d')));
        if ($txnType === '') {
            $txnType = 'purchase_2d';
        }

        $result = pos_run_standalone_gateway($gateway, $txnType, $payload);
        if (function_exists('pos_format_gateway_result')) {
            $result = pos_format_gateway_result($result);
        }
        $result['gateway'] = $gateway;
        $hostId = trim((string) ($result['transaction_id'] ?? $result['payment_id'] ?? ''));
        if (!empty($result['success']) && $hostId === '' && empty($result['requires_3ds']) && empty($result['redirect_url'])) {
            $result['success'] = false;
            $result['message'] = $result['message'] ?? 'Host did not return a transaction id';
        }
        return $result;
    }
}
