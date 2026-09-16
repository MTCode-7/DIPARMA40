<?php
require_once __DIR__ . '/../ProtocolInterface.php';

final class FraudProtocol implements ProtocolInterface {
    private string $code; private string $name;
    public function __construct(string $code, string $name) { $this->code = $code; $this->name = $name; }
    public function getCode(): string { return $this->code; }
    public function getName(): string { return $this->name; }

    public function execute(array $context): array {
        return ['success' => false, 'message' => 'Risk engine not connected; auto-pass fraud check removed'];
    }
}
