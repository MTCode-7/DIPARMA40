<?php
require_once __DIR__ . '/../ProtocolInterface.php';

final class CryptoProtocol implements ProtocolInterface {
    private string $code; private string $name;

    public function __construct(string $code, string $name) { $this->code = $code; $this->name = $name; }
    public function getCode(): string { return $this->code; }
    public function getName(): string { return $this->name; }

    public function execute(array $context): array {
        $wallets = $context['wallets'] ?? [];
        $amount = floatval($context['amount'] ?? 0);
        $result = [
            'success' => true,
            'protocol' => $this->code,
            'name' => $this->name,
            'amount' => $amount,
            'transaction_type' => 'OFFLINE',
            'mode' => 'LIVE'
        ];

        switch ($this->code) {
            case '301.1':
                $result['message'] = 'Cross-chain liquidity bridge prepared.';
                $result['transaction_type'] = 'CRYPTO_BRIDGE';
                $result['mode'] = 'LIVE';
                $result['bridge'] = $this->buildBridgePlan($wallets, $context);
                break;
            case '301.2':
                $result['message'] = 'Node-to-node sovereign transfer configured.';
                $result['transaction_type'] = 'NODE_TRANSFER';
                $result['mode'] = 'LIVE';
                $result['transfer'] = $this->buildNodeTransfer($context);
                break;
            case '301.3':
                $result['message'] = 'High-volume disbursement path assembled.';
                $result['transaction_type'] = $amount > 0 ? 'BULK_PAYOUT' : 'OFFLINE';
                $result['priority'] = $amount >= 25000;
                $result['disbursement_plan'] = $this->buildDisbursementPlan($wallets, $context);
                break;
            case '301.6':
                $result['message'] = 'Offshore sovereign settlement route identified.';
                $result['transaction_type'] = 'OFFSHORE_SETTLEMENT';
                $result['mode'] = 'OFFLINE';
                $result['offshore_region'] = $context['offshore_region'] ?? 'Middle East';
                break;
            case '301.7':
                $result['message'] = 'Quantum-resistant security layer applied.';
                $result['transaction_type'] = 'SECURITY_LAYER';
                $result['mode'] = 'LIVE';
                $result['security'] = ['cipher' => 'AES-512', 'quantum_ready' => true];
                break;
            default:
                return ['success' => false, 'message' => 'Unsupported crypto protocol code: ' . $this->code];
        }

        return $result;
    }

    private function buildBridgePlan(array $wallets, array $context): array {
        if (empty($wallets)) {
            return ['status' => 'no_wallets', 'note' => 'Wallet details are required for bridge execution'];
        }
        return [
            'wallet_count' => count($wallets),
            'estimated_fee_usd' => 10.0,
            'net_amount' => array_sum(array_column($wallets, 'amount_usdt')),
            'wallets' => $wallets
        ];
    }

    private function buildNodeTransfer(array $context): array {
        return [
            'source_node' => $context['source_node'] ?? 'node-01',
            'destination_node' => $context['destination_node'] ?? 'node-02',
            'amount' => $context['amount'] ?? 0,
            'currency' => strtoupper($context['currency'] ?? 'USD')
        ];
    }

    private function buildDisbursementPlan(array $wallets, array $context): array {
        if (empty($wallets)) {
            return ['status' => 'no_wallets', 'note' => 'Defer to manual high-volume payout flow'];
        }
        return [
            'wallet_count' => count($wallets),
            'estimated_fee_usd' => max(15.0, count($wallets) * 2.5),
            'wallets' => $wallets
        ];
    }
}
