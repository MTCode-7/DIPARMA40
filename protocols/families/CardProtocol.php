<?php
require_once __DIR__ . '/../ProtocolInterface.php';

final class CardProtocol implements ProtocolInterface {
    private string $code;
    private string $name;

    public function __construct(string $code, string $name) {
        $this->code = $code;
        $this->name = $name;
    }

    public function getCode(): string { return $this->code; }
    public function getName(): string { return $this->name; }

    public function execute(array $context): array {
        $amount = floatval($context['amount'] ?? 0);
        if ($amount <= 0) {
            return ['success' => false, 'message' => 'Invalid or missing amount'];
        }

        $currency = strtoupper(trim($context['currency'] ?? 'USD'));
        $gateway = strtolower(trim($context['gateway_type'] ?? $context['payment_gateway'] ?? ''));
        $paymentMethod = strtolower(trim($context['payment_method'] ?? 'card'));
        $result = [
            'success' => false,
            'protocol' => $this->code,
            'name' => $this->name,
            'amount' => $amount,
            'currency' => $currency,
            'gateway' => $gateway,
            'transaction_type' => 'ONLINE',
            'mode' => 'LIVE'
        ];

        switch ($this->code) {
            case '201.0':
            case '201.2':
            case '201.3':
            case '201.4':
            case '201.5':
            case '201.6':
            case '201.7':
                $result['message'] = 'Routing card payment using safe gateway flow.';
                $result['handler'] = 'card-route';
                $result['payment_method'] = $paymentMethod;
                $result['service_level'] = $this->determineServiceLevel($amount, $currency);
                break;
            case '201.9':
                $result['message'] = 'Multi-currency FX conversion calculated.';
                $result['transaction_type'] = 'FX_CONVERSION';
                $targetCurrency = strtoupper(trim($context['fx_target_currency'] ?? 'USD'));
                $result['conversion'] = $this->buildFxConversion($amount, $currency, $targetCurrency, $context);
                $result['handler'] = 'fx-conversion';
                break;
            default:
                return ['success' => false, 'message' => 'Unsupported card protocol code: ' . $this->code];
        }

        if (function_exists('gateway_service')) {
            try {
                $intentPayload = [
                    'amount' => $amount,
                    'currency' => $currency,
                    'customer_name' => $context['customer_name'] ?? '',
                    'customer_email' => $context['customer_email'] ?? '',
                    'customer_phone' => $context['customer_phone'] ?? '',
                    'payment_method' => $paymentMethod,
                    'description' => $this->name,
                    'source' => $context['source'] ?? 'web'
                ];
                $gatewayResponse = gateway_service()->createPaymentIntent($gateway, $intentPayload);
                $result['gateway_response'] = $gatewayResponse;
                if (!empty($gatewayResponse['success']) && !empty($gatewayResponse['data'])) {
                    $result['success'] = true;
                    $result['message'] .= ' Gateway payment intent created.';
                } elseif (!empty($gatewayResponse['message'])) {
                    $result['message'] .= ' Gateway reported: ' . $gatewayResponse['message'];
                }
            } catch (Throwable $e) {
                $result['message'] .= ' Gateway error: ' . $e->getMessage();
                $result['success'] = false;
            }
        }

        return $result;
    }

    private function determineServiceLevel(float $amount, string $currency): string {
        if ($amount >= 10000) {
            return 'high_priority';
        }
        return in_array($currency, ['USD', 'EUR', 'GBP'], true) ? 'standard' : 'multi_currency';
    }

    private function buildFxConversion(float $amount, string $currency, string $target, array $context): array {
        $rates = $context['exchange_rates'] ?? null;
        if (!is_array($rates) || empty($rates[$currency]) || empty($rates[$target])) {
            return [
                'from' => $currency,
                'to' => $target,
                'original_amount' => $amount,
                'converted_amount' => null,
                'exchange_rate' => null,
                'error' => 'Live exchange rates required; static FX removed',
            ];
        }
        $sourceRate = (float) $rates[$currency];
        $targetRate = (float) $rates[$target];
        if ($sourceRate <= 0 || $targetRate <= 0) {
            return [
                'from' => $currency,
                'to' => $target,
                'original_amount' => $amount,
                'converted_amount' => null,
                'exchange_rate' => null,
                'error' => 'Invalid live exchange rates',
            ];
        }
        $converted = $amount * ($targetRate / $sourceRate);
        return [
            'from' => $currency,
            'to' => $target,
            'original_amount' => $amount,
            'converted_amount' => round($converted, 6),
            'exchange_rate' => round($targetRate / $sourceRate, 8)
        ];
    }
}
