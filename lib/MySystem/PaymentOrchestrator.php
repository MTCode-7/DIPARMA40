<?php
/**
 * DI_PARMA_MYSYSTEM | Payment Orchestrator
 *
 * Orders ──┐
 *          ├──► PAYMENT ORCHESTRATOR ──► Provider A/B/C ──► Payment Result ──► Orders
 * Customers┘
 */
require_once __DIR__ . '/OrdersService.php';
require_once __DIR__ . '/CustomersService.php';
require_once __DIR__ . '/../Adapters/GatewayAdapterFactory.php';

class MySystemPaymentOrchestrator
{
    private static ?self $instance = null;
    private MySystemOrdersService $orders;
    private MySystemCustomersService $customers;

    /** Default provider priority when gateway not specified */
    private array $providerPriority = ['square', 'nuvei', 'stripe', 'paypal'];

    private function __construct()
    {
        $this->orders = new MySystemOrdersService();
        $this->customers = new MySystemCustomersService();
    }

    public static function getInstance(): self
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Main entry matching DI_PARMA_MYSYSTEM diagram.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function pay(array $input): array
    {
        $customer = $this->customers->resolve($input);
        $provider = $this->resolveProvider($input);
        if ($provider === '') {
            return [
                'success' => false,
                'message' => 'No payment provider available',
                'stage' => 'orchestrator',
            ];
        }

        $input['provider'] = $provider;
        $input['gateway'] = $provider;

        $orderCreate = $this->orders->create($input, $customer);
        if (empty($orderCreate['success'])) {
            return [
                'success' => false,
                'message' => $orderCreate['message'] ?? 'Order create failed',
                'stage' => 'orders',
                'reference' => $orderCreate['reference'] ?? null,
            ];
        }

        $reference = (string) $orderCreate['reference'];
        $paymentResult = $this->chargeProvider($provider, $input, $customer, $reference);
        $paymentResult['provider'] = $provider;
        $paymentResult['reference'] = $reference;

        $applied = $this->orders->applyPaymentResult($reference, $paymentResult);

        return [
            'success' => !empty($paymentResult['success']),
            'stage' => 'payment_result',
            'reference' => $reference,
            'order_id' => $orderCreate['order_id'] ?? null,
            'customer' => [
                'id' => $customer['id'],
                'email' => $customer['email'],
                'name' => $customer['name'],
            ],
            'provider' => $provider,
            'payment' => $paymentResult,
            'order' => $applied['order'] ?? null,
            'order_update_ok' => !empty($applied['success']),
            'message' => $paymentResult['message'] ?? ($applied['message'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $input
     */
    private function resolveProvider(array $input): string
    {
        $requested = strtolower(trim((string) ($input['provider'] ?? $input['gateway'] ?? '')));
        if ($requested === 'diparma_gateway') {
            $requested = strtolower(trim((string) ($input['card_provider'] ?? '')));
        }
        if ($requested !== '' && $requested !== 'diparma_gateway' && GatewayAdapterFactory::isSupported($requested)) {
            return $requested;
        }
        foreach ($this->providerPriority as $code) {
            if (GatewayAdapterFactory::isSupported($code)) {
                // Prefer providers that look configured
                try {
                    $gw = db()->find('payment_gateways', ['code' => $code]);
                    if ($gw && strtolower((string) ($gw['status'] ?? '')) === 'active') {
                        return $code;
                    }
                } catch (Throwable $e) {
                }
            }
        }
        return $requested !== '' ? $requested : (string) ($this->providerPriority[0] ?? '');
    }

    /**
     * @param array<string,mixed> $input
     * @param array{id:int,email:string,name:string} $customer
     * @return array<string,mixed>
     */
    private function chargeProvider(string $provider, array $input, array $customer, string $reference): array
    {
        $txnType = strtolower(trim((string) ($input['txn_type'] ?? 'purchase_2d')));
        require_once __DIR__ . '/ChargeHub.php';

        if (DiParmaChargeHub::supports($provider)) {
            // Avoid nested Orders create — ChargeHub/orchestrator already create+apply.
            // Here we only need standalone when order already exists from pay().
            DiParmaChargeHub::ensurePosLoaded();
            $params = array_merge($input, [
                'amount' => (float) ($input['amount'] ?? 0),
                'currency' => strtoupper((string) ($input['currency'] ?? 'USD')),
                'card_number' => preg_replace('/\D/', '', (string) ($input['card_number'] ?? $input['cc_number'] ?? '')),
                'card_expiry' => (string) ($input['card_expiry'] ?? $input['cc_expiry'] ?? ''),
                'card_cvv' => (string) ($input['card_cvv'] ?? $input['cc_cvv'] ?? $input['cvv2'] ?? ''),
                'name' => (string) ($input['card_name'] ?? $customer['name'] ?? 'CARDHOLDER'),
                'email' => (string) ($input['email'] ?? $customer['email'] ?? ''),
                'reference' => $reference,
                'user_id' => (int) ($customer['id'] ?? 0),
                'source_id' => $input['source_id'] ?? null,
                'cloud_token' => $input['cloud_token'] ?? $input['payment_token'] ?? null,
                'destination' => 'ledger',
                'ledger_address' => $input['ledger_address'] ?? (defined('LEDGER_TRC20_ADDRESS') ? LEDGER_TRC20_ADDRESS : ''),
                'channel' => (string) ($input['channel'] ?? 'mysystem'),
            ]);
            // Order already created in pay() — charge via standalone only
            $payment = pos_run_standalone_gateway($provider, $txnType, $params);
            if (!is_array($payment)) {
                $payment = ['success' => false, 'message' => 'Invalid provider response'];
            }
            $payment['provider'] = $provider;
            $payment['hub'] = 'di_parma_charge_hub';
            return $payment;
        }

        $mode = (str_contains($txnType, '3d') || strtoupper((string) ($input['sec_mode'] ?? '')) === '3D')
            ? '3D'
            : '2D';

        try {
            $adapter = GatewayAdapterFactory::make($provider, $mode);
        } catch (Throwable $e) {
            return [
                'success' => false,
                'status' => 'failed',
                'message' => 'Provider unavailable: ' . $e->getMessage(),
                'provider' => $provider,
            ];
        }

        $payload = [
            'amount' => (float) ($input['amount'] ?? 0),
            'currency' => strtoupper((string) ($input['currency'] ?? 'USD')),
            'card_number' => preg_replace('/\D/', '', (string) ($input['card_number'] ?? $input['cc_number'] ?? '')),
            'card_expiry' => (string) ($input['card_expiry'] ?? $input['cc_expiry'] ?? ''),
            'card_cvv' => (string) ($input['card_cvv'] ?? $input['cc_cvv'] ?? $input['cvv2'] ?? ''),
            'cvv2' => (string) ($input['card_cvv'] ?? $input['cc_cvv'] ?? $input['cvv2'] ?? ''),
            'name' => (string) ($input['card_name'] ?? $customer['name'] ?? 'CARDHOLDER'),
            'email' => (string) ($input['email'] ?? $customer['email'] ?? ''),
            'reference' => $reference,
            'processing_mode' => $mode,
            'txn_type' => $txnType,
            'source_id' => $input['source_id'] ?? null,
            'cloud_token' => $input['cloud_token'] ?? $input['payment_token'] ?? null,
            'payment_token' => $input['payment_token'] ?? $input['cloud_token'] ?? null,
            'destination' => 'ledger',
            'ledger_address' => $input['ledger_address'] ?? (defined('LEDGER_TRC20_ADDRESS') ? LEDGER_TRC20_ADDRESS : ''),
        ];

        try {
            if (in_array($txnType, ['auth', 'auth_hold', 'auth_moto'], true)) {
                $result = $adapter->hold($payload);
            } else {
                $result = $adapter->charge($payload);
            }
        } catch (Throwable $e) {
            return [
                'success' => false,
                'status' => 'failed',
                'message' => $e->getMessage(),
                'provider' => $provider,
            ];
        }

        if (!is_array($result)) {
            return ['success' => false, 'status' => 'failed', 'message' => 'Invalid provider response', 'provider' => $provider];
        }
        $result['provider'] = $provider;
        return $result;
    }
}
