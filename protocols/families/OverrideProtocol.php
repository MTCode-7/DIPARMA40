<?php
require_once __DIR__ . '/../ProtocolInterface.php';

final class OverrideProtocol implements ProtocolInterface {
    private string $code; private string $name;
    public function __construct(string $code, string $name) { $this->code = $code; $this->name = $name; }
    public function getCode(): string { return $this->code; }
    public function getName(): string { return $this->name; }

    public function execute(array $context): array {
        // Overrides require explicit operator approval in config
        $allow = getenv('ENABLE_PROTOCOL_OVERRIDE') ?: false;
        if (!$allow) {
            return ['success' => false, 'message' => 'Override disabled: manual approval required'];
        }
        return ['success' => true, 'message' => 'Override executed (operator-approved)'];
    }
}
