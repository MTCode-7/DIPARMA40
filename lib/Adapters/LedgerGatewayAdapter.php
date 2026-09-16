<?php
/**
 * DIPARMA GATEWAY — Ledger USDT TRC20 only.
 * No bank, no IBAN, no Nuvei, no AUTH/capture/refund.
 */
require_once __DIR__ . '/GatewayAdapterInterface.php';
require_once __DIR__ . '/GatewayErrorMapper.php';

class LedgerGatewayAdapter implements GatewayAdapterInterface
{
    public function getName(): string
    {
        return 'diparma_gateway';
    }

    public function supports(string $mode): bool
    {
        return in_array(strtoupper(trim($mode)), ['CHARGE', '2D', '3D'], true);
    }

    public function normalizeError(array $rawResponse): string
    {
        return !empty($rawResponse['success']) ? '' : 'GATEWAY_ERROR';
    }

    public function buildIdempotencyKey(string $reference, float $amount): string
    {
        return 'idemp_ledger_' . hash('sha256', $reference . '|' . $amount . '|diparma_gateway');
    }

    public function charge(array $payload): array
    {
        return $this->rejectBankOp($payload, 'charge');
    }

    public function hold(array $payload): array
    {
        return $this->rejectBankOp($payload, 'hold');
    }

    public function capture(string $transactionId, ?float $amount = null): array
    {
        return $this->rejectBankOp(['reference' => $transactionId, 'amount' => $amount ?? 0], 'capture');
    }

    public function cancel(string $transactionId, string $reason = 'requested_by_customer'): array
    {
        return $this->rejectBankOp(['reference' => $transactionId], 'cancel');
    }

    private function rejectBankOp(array $payload, string $op): array
    {
        return GatewayErrorMapper::buildErrorResponse(
            'GATEWAY_ERROR',
            (string) ($payload['reference'] ?? ''),
            (float) ($payload['amount'] ?? 0),
            strtoupper((string) ($payload['currency'] ?? 'USD')),
            'DIPARMA GATEWAY is Ledger-only. No bank ' . $op . '.'
        );
    }
}
