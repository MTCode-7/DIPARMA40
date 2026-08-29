<?php
require_once __DIR__ . '/../ProtocolInterface.php';

final class LiquidityProtocol implements ProtocolInterface {
    private string $code; private string $name;

    public function __construct(string $code, string $name) { $this->code = $code; $this->name = $name; }
    public function getCode(): string { return $this->code; }
    public function getName(): string { return $this->name; }

    public function execute(array $context): array {
        $amount = floatval($context['amount'] ?? 0);
        $currency = strtoupper(trim($context['currency'] ?? 'USD'));
        $result = [
            'success' => true,
            'protocol' => $this->code,
            'name' => $this->name,
            'amount' => $amount,
            'currency' => $currency,
            'transaction_type' => 'ONLINE',
            'mode' => 'LIVE'
        ];

        switch ($this->code) {
            case '202.0':
                $result['message'] = 'BIN-based route selection completed.';
                $result['transaction_type'] = 'ONLINE';
                $result['route'] = $this->chooseBinRoute($context);
                break;
            case '202.1':
                $result['message'] = 'Inter-bank settlement bridge prepared.';
                $result['transaction_type'] = 'SETTLEMENT';
                $result['mode'] = 'OFFLINE';
                $result['settlement_date'] = $context['settlement_date'] ?? date('Y-m-d', strtotime('+1 day'));
                break;
            case '202.4':
                $result['message'] = 'Cross-border transaction sanitized.';
                $result['transaction_type'] = $this->isCrossBorder($context) ? 'ONLINE' : 'OFFLINE';
                $result['sanitized_fields'] = $this->sanitizeCrossBorder($context);
                break;
            case '202.5':
                $result['message'] = 'Liquidity mirror validated.';
                $result['transaction_type'] = 'OFFLINE';
                $result['reserved'] = $this->reserveLiquidity($context);
                break;
            case '202.6':
                $result['message'] = 'VIP routing strategy selected.';
                $result['transaction_type'] = $amount >= 10000 ? 'HIGH_PRIORITY' : 'ONLINE';
                $result['priority'] = $amount >= 10000;
                break;
            case '202.7':
                $result['message'] = 'Acquirer network tunnel chosen.';
                $result['transaction_type'] = 'ONLINE';
                $result['acquirer_network'] = $this->selectAcquirerNetwork($context);
                break;
            case '202.8':
                $result['message'] = 'Clearing house automated link configured.';
                $result['transaction_type'] = 'SETTLEMENT';
                $result['mode'] = 'OFFLINE';
                break;
            default:
                return ['success' => false, 'message' => 'Unsupported liquidity protocol code: ' . $this->code];
        }

        return $result;
    }

    private function chooseBinRoute(array $context): array {
        $bin = preg_replace('/[^0-9]/', '', $context['card_pan_masked'] ?? '');
        $bin = substr($bin, 0, 6);
        if (empty($bin)) {
            return ['gateway' => 'default', 'reason' => 'No BIN available'];
        }
        switch ($bin[0]) {
            case '4':
                return ['gateway' => 'visa', 'bin' => $bin];
            case '5':
            case '2':
                return ['gateway' => 'mastercard', 'bin' => $bin];
            case '6':
                return ['gateway' => 'unionpay', 'bin' => $bin];
            default:
                return ['gateway' => 'generic', 'bin' => $bin];
        }
    }

    private function isCrossBorder(array $context): bool {
        $origin = strtoupper(trim($context['origin_country'] ?? '')); 
        $destination = strtoupper(trim($context['destination_country'] ?? ''));
        return $origin !== '' && $destination !== '' && $origin !== $destination;
    }

    private function sanitizeCrossBorder(array $context): array {
        return [
            'customer_name' => trim($context['customer_name'] ?? ''),
            'customer_email' => strtolower(trim($context['customer_email'] ?? '')),
            'customer_phone' => preg_replace('/[^0-9+]/', '', $context['customer_phone'] ?? ''),
            'country_origin' => $context['origin_country'] ?? null,
            'country_destination' => $context['destination_country'] ?? null
        ];
    }

    private function reserveLiquidity(array $context): array {
        $required = floatval($context['net_usdt'] ?? ($context['amount'] ?? 0));
        $available = 100000.0;
        return [
            'required' => $required,
            'available' => $available,
            'reserved' => min($required, $available),
            'status' => $required <= $available ? 'ok' : 'insufficient'
        ];
    }

    private function selectAcquirerNetwork(array $context): string {
        if (!empty($context['acquirer_network'])) {
            return $context['acquirer_network'];
        }
        $currency = strtoupper(trim($context['currency'] ?? 'USD'));
        return $currency === 'EUR' ? 'sepa' : ($currency === 'USD' ? 'ach' : 'swft');
    }
}
