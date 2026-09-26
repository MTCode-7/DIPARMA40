<?php
/**
 * Wise on the adapter factory. A quote is pricing only and is not an approval.
 */
require_once __DIR__ . '/GatewayAdapterInterface.php';
require_once __DIR__ . '/GatewayErrorMapper.php';
require_once __DIR__ . '/../WiseService.php';

class WiseGatewayAdapter implements GatewayAdapterInterface
{
    public function getName(): string
    {
        return 'wise';
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
        return 'idemp_wise_' . hash('sha256', $reference . '|' . $amount);
    }

    public function charge(array $payload): array
    {
        $amount = (float) ($payload['amount'] ?? 0);
        $currency = strtoupper((string) ($payload['currency'] ?? 'USD'));
        $source = $currency === 'USDT' ? 'USD' : $currency;
        $target = $source;
        $quote = WiseService::fromConfig()->createQuote($amount, $source, $target);
        if (empty($quote['id'])) {
            $message = $quote['errors'][0]['message'] ?? ($quote['message'] ?? 'Wise quote failed');
            return GatewayErrorMapper::buildErrorResponse(
                'GATEWAY_ERROR',
                (string) ($payload['reference'] ?? ''),
                $amount,
                $currency,
                (string) $message
            );
        }
        return [
            'success' => false,
            'transaction_id' => (string) $quote['id'],
            'reference' => (string) ($payload['reference'] ?? ''),
            'amount' => $amount,
            'currency' => $currency,
            'message' => 'Wise quote is pricing only. No simulated approval. Complete a real Wise payment, then Ledger.',
            'raw' => $quote,
        ];
    }

    public function hold(array $payload): array
    {
        return $this->charge($payload);
    }

    public function capture(string $transactionId, ?float $amount = null): array
    {
        return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $transactionId, $amount ?? 0, '', 'Wise does not support capture');
    }

    public function cancel(string $transactionId, string $reason = 'requested_by_customer'): array
    {
        return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $transactionId, 0, '', 'Wise refund/void is not a card void');
    }
}
