<?php
/**
 * Whop on the adapter factory. Charge returns the hosted checkout URL.
 */
require_once __DIR__ . '/GatewayAdapterInterface.php';
require_once __DIR__ . '/GatewayErrorMapper.php';
require_once __DIR__ . '/WhopAdapter.php';

class WhopGatewayAdapter implements GatewayAdapterInterface
{
    public function getName(): string
    {
        return 'whop';
    }

    public function supports(string $mode): bool
    {
        return in_array(strtoupper(trim($mode)), ['2D', '3D', 'CHARGE'], true);
    }

    public function normalizeError(array $rawResponse): string
    {
        return 'GATEWAY_ERROR';
    }

    public function buildIdempotencyKey(string $reference, float $amount): string
    {
        return 'idemp_whop_' . hash('sha256', $reference . '|' . $amount);
    }

    public function charge(array $payload): array
    {
        $whop = new WhopAdapter();
        $link = $whop->createPaymentLink($payload);
        if (empty($link['success'])) {
            return GatewayErrorMapper::buildErrorResponse(
                'GATEWAY_ERROR',
                (string) ($payload['reference'] ?? ''),
                (float) ($payload['amount'] ?? 0),
                strtoupper((string) ($payload['currency'] ?? 'USD')),
                (string) ($link['message'] ?? 'Whop payment link failed')
            );
        }
        $id = (string) ($link['payment_id'] ?? '');
        return [
            'success' => false,
            'requires_3ds' => true,
            'redirect_url' => (string) ($link['checkout_url'] ?? ''),
            'checkout_url' => (string) ($link['checkout_url'] ?? ''),
            'transaction_id' => $id,
            'reference' => (string) ($payload['reference'] ?? $id),
            'amount' => (float) ($payload['amount'] ?? 0),
            'currency' => strtoupper((string) ($payload['currency'] ?? 'USD')),
            'message' => 'Open Whop, complete the purchase, then net USDT → Ledger.',
            'raw' => $link,
        ];
    }

    public function hold(array $payload): array
    {
        return $this->charge($payload);
    }

    public function capture(string $transactionId, ?float $amount = null): array
    {
        return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $transactionId, $amount ?? 0, '', 'Whop does not support capture');
    }

    public function cancel(string $transactionId, string $reason = 'requested_by_customer'): array
    {
        return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $transactionId, 0, '', 'Whop refund/void stays on Whop');
    }
}
