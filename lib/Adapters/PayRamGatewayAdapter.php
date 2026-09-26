<?php
/**
 * PayRam on the adapter factory. Charge opens the PayRam page; success comes from the webhook.
 */
require_once __DIR__ . '/GatewayAdapterInterface.php';
require_once __DIR__ . '/GatewayErrorMapper.php';
require_once __DIR__ . '/../PayRamAdapter.php';

class PayRamGatewayAdapter implements GatewayAdapterInterface
{
    public function getName(): string
    {
        return 'payram';
    }

    public function supports(string $mode): bool
    {
        return in_array(strtoupper(trim($mode)), ['2D', '3D', 'CHARGE', 'HOLD', 'CAPTURE', 'CANCEL', 'MOTO', 'OFFLINE', 'ADVICE'], true);
    }

    public function normalizeError(array $rawResponse): string
    {
        return 'GATEWAY_ERROR';
    }

    public function buildIdempotencyKey(string $reference, float $amount): string
    {
        return 'idemp_payram_' . hash('sha256', $reference . '|' . $amount);
    }

    public function charge(array $payload): array
    {
        $created = (new PayRamAdapter())->createPayment([
            'amount' => (float) ($payload['amount'] ?? 0),
            'email' => (string) ($payload['email'] ?? ''),
            'customer_id' => (string) ($payload['customer_id'] ?? $payload['user_token_id'] ?? ('user_' . time())),
        ]);
        if (empty($created['success'])) {
            return GatewayErrorMapper::buildErrorResponse(
                'GATEWAY_ERROR',
                (string) ($payload['reference'] ?? ''),
                (float) ($payload['amount'] ?? 0),
                strtoupper((string) ($payload['currency'] ?? 'USD')),
                (string) ($created['message'] ?? 'PayRam payment creation failed')
            );
        }
        $ref = (string) ($created['reference_id'] ?? '');
        return [
            'success' => false,
            'requires_3ds' => true,
            'redirect_url' => (string) ($created['url'] ?? ''),
            'transaction_id' => $ref,
            'reference' => (string) ($payload['reference'] ?? $ref),
            'amount' => (float) ($payload['amount'] ?? 0),
            'currency' => strtoupper((string) ($payload['currency'] ?? 'USD')),
            'message' => 'Open PayRam and complete the purchase. Funds stay on PayRam.',
            'raw' => $created,
        ];
    }

    public function hold(array $payload): array
    {
        return $this->charge($payload);
    }

    public function capture(string $transactionId, ?float $amount = null): array
    {
        return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $transactionId, $amount ?? 0, '', 'PayRam does not support capture');
    }

    public function cancel(string $transactionId, string $reason = 'requested_by_customer'): array
    {
        return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $transactionId, 0, '', 'PayRam refund/void stays on the PayRam invoice');
    }

    private function unsupported(array $payload, string $op): array
    {
        return GatewayErrorMapper::buildErrorResponse(
            'GATEWAY_ERROR',
            (string) ($payload['reference'] ?? ''),
            (float) ($payload['amount'] ?? 0),
            strtoupper((string) ($payload['currency'] ?? 'USD')),
            'PayRam does not support ' . $op
        );
    }
}
