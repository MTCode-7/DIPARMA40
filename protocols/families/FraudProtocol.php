<?php
require_once __DIR__ . '/../ProtocolInterface.php';

final class FraudProtocol implements ProtocolInterface {
    private string $code; private string $name;
    public function __construct(string $code, string $name) { $this->code = $code; $this->name = $name; }
    public function getCode(): string { return $this->code; }
    public function getName(): string { return $this->name; }

    public function execute(array $context): array {
        // Fraud detection and risk assessment
        $amount = $context['amount'] ?? 0;
        if ($amount > 50000) {
            return ['success' => false, 'message' => 'Transaction flagged for manual review due to high amount'];
        }
        return ['success' => true, 'message' => 'Risk checks passed'];
    }
}
