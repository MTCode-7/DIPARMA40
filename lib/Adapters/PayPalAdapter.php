<?php
/**
 * PayPal card adapter — Advanced Card Processing (MOTO/2D) with Braintree fallback.
 */

require_once __DIR__ . '/GatewayAdapterInterface.php';
require_once __DIR__ . '/GatewayErrorMapper.php';
require_once __DIR__ . '/GatewayLogger.php';
require_once __DIR__ . '/BraintreeAdapter.php';
require_once __DIR__ . '/../PayPalService.php';

final class PayPalAdapter implements GatewayAdapterInterface
{
    private PayPalService $svc;
    private BraintreeAdapter $braintree;

    public function __construct()
    {
        $this->svc = PayPalService::getInstance();
        $this->braintree = new BraintreeAdapter();
    }

    public function getName(): string
    {
        return 'paypal';
    }

    public function supports(string $mode): bool
    {
        return in_array(strtoupper($mode), ['2D', '3D', 'HOLD', 'CAPTURE', 'CANCEL']);
    }

    public function normalizeError(array $rawResponse): string
    {
        $issue = strtoupper((string)($rawResponse['details'][0]['issue'] ?? $rawResponse['error_code'] ?? $rawResponse['name'] ?? ''));
        $map = [
            'CARD_DECLINED' => 'CARD_DECLINED',
            'CARD_EXPIRED' => 'EXPIRED_CARD',
            'EXPIRED_CARD' => 'EXPIRED_CARD',
            'INVALID_SECURITY_CODE' => 'INVALID_CVV',
            'INVALID_CVV' => 'INVALID_CVV',
            'INVALID_ACCOUNT_NUMBER' => 'INVALID_CARD',
            'INVALID_CARD' => 'INVALID_CARD',
            'INSUFFICIENT_FUNDS' => 'INSUFFICIENT_FUNDS',
            'TRANSACTION_BLOCKED_BY_PAYEE' => 'DO_NOT_HONOR',
            'NETWORK_ERROR' => 'NETWORK_ERROR',
            'GATEWAY_ERROR' => 'GATEWAY_ERROR',
        ];
        return $map[$issue] ?? 'CARD_DECLINED';
    }

    public function buildIdempotencyKey(string $reference, float $amount): string
    {
        return hash('sha256', 'pp_' . $reference . '|' . $amount . '|' . getenv('ENCRYPTION_KEY'));
    }

    public function charge(array $payload): array
    {
        return $this->runCard($payload, 'CAPTURE', 'charge');
    }

    public function hold(array $payload): array
    {
        return $this->runCard($payload, 'AUTHORIZE', 'hold');
    }

    public function capture(string $transactionId, ?float $amount = null): array
    {
        $start = microtime(true);
        $result = $this->svc->captureAuthorization($transactionId, $amount);
        GatewayLogger::log('paypal', 'capture', ['transaction_id' => $transactionId], $result, empty($result['success']) ? 'GATEWAY_ERROR' : '', microtime(true) - $start);
        if (!empty($result['success'])) {
            return $result;
        }
        if ($this->braintreeConfigured()) {
            return $this->braintree->capture($transactionId, $amount);
        }
        return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $transactionId, $amount ?? 0, '', $this->describeFailure($result));
    }

    public function cancel(string $transactionId, string $reason = 'requested_by_customer'): array
    {
        $start = microtime(true);
        $result = $this->svc->voidAuthorization($transactionId);
        GatewayLogger::log('paypal', 'cancel', ['transaction_id' => $transactionId, 'reason' => $reason], $result, empty($result['success']) ? 'GATEWAY_ERROR' : '', microtime(true) - $start);
        if (!empty($result['success'])) {
            return $result;
        }
        if ($this->braintreeConfigured()) {
            return $this->braintree->cancel($transactionId, $reason);
        }
        return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $transactionId, 0, '', $this->describeFailure($result));
    }

    private function runCard(array $payload, string $intent, string $operation): array
    {
        $start = microtime(true);
        $reference = (string)($payload['reference'] ?? '');
        $amount = floatval($payload['amount'] ?? 0);
        $currency = strtoupper((string)($payload['currency'] ?? 'USD'));

        $result = $this->svc->processCard($payload, $intent);
        if (!empty($result['success'])) {
            GatewayLogger::log('paypal', $operation, $payload, $result, '', microtime(true) - $start);
            return $result;
        }

        if ($this->shouldFallbackToBraintree($result) && $this->braintreeConfigured()) {
            GatewayLogger::quick('paypal', $operation, $reference, false, 'PayPal card API unavailable, falling back to Braintree');
            return $intent === 'AUTHORIZE'
                ? $this->braintree->hold($payload)
                : $this->braintree->charge($payload);
        }

        $errCode = $this->normalizeError($result['raw'] ?? $result);
        GatewayLogger::log('paypal', $operation, $payload, $result, $errCode, microtime(true) - $start);
        return GatewayErrorMapper::buildErrorResponse(
            $errCode,
            $reference,
            $amount,
            $currency,
            $this->describeFailure($result)
        );
    }

    /**
     * Surfaces PayPal's own reason; an empty message would collapse into a
     * generic "gateway error" that hides why the card was refused.
     */
    private function describeFailure(array $result): string
    {
        $message = trim((string)($result['message'] ?? ''));
        $issue = trim((string)($result['error_code'] ?? $result['raw']['details'][0]['issue'] ?? ''));
        $debugId = trim((string)($result['raw']['debug_id'] ?? ''));

        if ($message === '') {
            $message = 'PayPal رفض عملية البطاقة';
        }
        if ($issue !== '' && stripos($message, $issue) === false) {
            $message .= " [$issue]";
        }
        if ($debugId !== '') {
            $message .= " (debug_id: $debugId)";
        }
        return $message;
    }

    private function braintreeConfigured(): bool
    {
        return (bool)getenv('BRAINTREE_MERCHANT_ID')
            && (bool)getenv('BRAINTREE_PUBLIC_KEY')
            && (bool)getenv('BRAINTREE_PRIVATE_KEY');
    }

    private function shouldFallbackToBraintree(array $result): bool
    {
        $issue = strtoupper((string)($result['error_code'] ?? $result['raw']['details'][0]['issue'] ?? $result['raw']['name'] ?? ''));
        $message = strtolower((string)($result['message'] ?? ''));
        $fallbackIssues = [
            'GATEWAY_ERROR',
            'NOT_ENABLED',
            'PERMISSION_DENIED',
            'UNPROCESSABLE_ENTITY',
            'PAYMENT_SOURCE_CANNOT_BE_USED',
            'CARD_BRAND_NOT_SUPPORTED',
            'INVALID_RESOURCE_ID',
        ];
        if (in_array($issue, $fallbackIssues, true)) {
            return true;
        }
        return str_contains($message, 'credentials')
            || str_contains($message, 'not enabled')
            || str_contains($message, 'advanced card');
    }
}
