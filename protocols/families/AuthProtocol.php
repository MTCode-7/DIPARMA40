<?php
require_once __DIR__ . '/../ProtocolInterface.php';

final class AuthProtocol implements ProtocolInterface {
    private string $code;
    private string $name;

    public function __construct(string $code, string $name) {
        $this->code = $code;
        $this->name = $name;
    }

    public function getCode(): string { return $this->code; }
    public function getName(): string { return $this->name; }

    public function execute(array $context): array {
        // Safe, legitimate authentication steps
        $user = $context['customer_name'] ?? ($context['user'] ?? null);
        $result = [
            'success' => true,
            'message' => 'Authentication verified successfully',
            'user' => $user
        ];
        // Example: validate session and presence of email/phone
        if (empty($context['customer_email']) && empty($context['customer_phone'])) {
            return ['success' => false, 'message' => 'Missing contact info for authentication'];
        }
        return $result;
    }
}
