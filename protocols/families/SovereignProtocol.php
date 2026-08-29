<?php
require_once __DIR__ . '/../ProtocolInterface.php';

final class SovereignProtocol implements ProtocolInterface {
    private string $code; private string $name;
    public function __construct(string $code, string $name) { $this->code = $code; $this->name = $name; }
    public function getCode(): string { return $this->code; }
    public function getName(): string { return $this->name; }

    public function execute(array $context): array {
        // Orchestrator: execute sub-protocols in production mode
        $executor = new ProtocolExecutor();
        $steps = $context['steps'] ?? [];
        $results = [];
        foreach ($steps as $stepCode) {
            $results[$stepCode] = $executor->executeProtocol($stepCode, $context);
        }
        return ['success' => true, 'message' => 'Protocol orchestration completed successfully', 'steps' => $results];
    }
}
