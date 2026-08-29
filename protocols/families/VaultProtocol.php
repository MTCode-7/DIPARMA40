<?php
require_once __DIR__ . '/../ProtocolInterface.php';

final class VaultProtocol implements ProtocolInterface {
    private string $code; private string $name;
    public function __construct(string $code, string $name) { $this->code = $code; $this->name = $name; }
    public function getCode(): string { return $this->code; }
    public function getName(): string { return $this->name; }

    public function execute(array $context): array {
        // Controlled vault access operations
        $action = $context['action'] ?? 'reserve';
        if ($action === 'reserve') {
            return ['success' => true, 'message' => 'Vault reserve executed successfully'];
        }
        if ($action === 'release') {
            return ['success' => true, 'message' => 'Vault release executed successfully'];
        }
        return ['success' => false, 'message' => 'Unknown vault action'];
    }
}
