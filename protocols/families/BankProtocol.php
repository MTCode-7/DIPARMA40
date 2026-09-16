<?php
require_once __DIR__ . '/../ProtocolInterface.php';

final class BankProtocol implements ProtocolInterface {
    private string $code; private string $name;
    public function __construct(string $code, string $name) { $this->code = $code; $this->name = $name; }
    public function getCode(): string { return $this->code; }
    public function getName(): string { return $this->name; }

    public function execute(array $context): array {
        $beneficiary = $context['beneficiary'] ?? ($context['wallets'][0]['address'] ?? null);
        if (empty($beneficiary)) {
            return ['success' => false, 'message' => 'No beneficiary provided'];
        }
        return [
            'success' => false,
            'message' => 'Bank transfer is not simulated. Use a live bank gateway checkout.',
            'beneficiary' => $beneficiary,
        ];
    }
}
