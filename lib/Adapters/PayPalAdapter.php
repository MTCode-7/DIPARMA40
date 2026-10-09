<?php
/**
 * PayPal card adapter — Advanced Card Processing (MOTO/2D).
 */

require_once __DIR__ . '/GatewayAdapterInterface.php';
require_once __DIR__ . '/GatewayErrorMapper.php';
require_once __DIR__ . '/GatewayLogger.php';
require_once __DIR__ . '/../PayPalService.php';

final class PayPalAdapter implements GatewayAdapterInterface
{
    private PayPalService $svc;

    public function __construct()
    {
        $this->svc = PayPalService::getInstance();
    }

    public function getName(): string
    {
        return 'paypal';
    }

    public function supports(string $mode): bool
    {
        return in_array(strtoupper($mode), ['2D', '3D', 'HOLD', 'CAPTURE', 'CANCEL', 'REFUND', 'MOTO', 'OFFLINE', 'ADVICE'], true);
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
            'PAYEE_NOT_ENABLED_FOR_CARD_PROCESSING' => 'GATEWAY_ERROR',
            'NOT_ENABLED' => 'GATEWAY_ERROR',
            'INCOMPATIBLE_PARAMETER_VALUE' => 'GATEWAY_ERROR',
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
        $currency = 'USD';
        $authAmount = 0.0;
        $auth = $this->svc->getAuthorization($transactionId);
        if (is_array($auth) && (isset($auth['id']) || isset($auth['amount']) || !empty($auth['success']))) {
            $rawCurrency = '';
            if (!empty($auth['currency'])) {
                $rawCurrency = (string) $auth['currency'];
            } elseif (is_array($auth['amount'] ?? null) && !empty($auth['amount']['currency_code'])) {
                $rawCurrency = (string) $auth['amount']['currency_code'];
            }
            $currency = strtoupper(trim($rawCurrency !== '' ? $rawCurrency : 'USD')) ?: 'USD';
            $authAmount = floatval(is_array($auth['amount'] ?? null)
                ? ($auth['amount']['value'] ?? 0)
                : ($auth['amount'] ?? 0));
        }
        $finalCapture = $amount !== null && $authAmount > 0 && $amount >= $authAmount;
        $result = $this->svc->captureAuthorization(
            $transactionId,
            $amount,
            $currency,
            ['final_capture' => $finalCapture]
        );
        GatewayLogger::log('paypal', 'capture', ['transaction_id' => $transactionId, 'currency' => $currency], $result, empty($result['success']) ? 'GATEWAY_ERROR' : '', microtime(true) - $start);
        if (!empty($result['success'])) {
            return $result;
        }
        return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $transactionId, $amount ?? 0, $currency, $this->describeFailure($result));
    }

    public function cancel(string $transactionId, string $reason = 'requested_by_customer'): array
    {
        $start = microtime(true);
        $result = $this->svc->voidAuthorization($transactionId);
        GatewayLogger::log('paypal', 'cancel', ['transaction_id' => $transactionId, 'reason' => $reason], $result, empty($result['success']) ? 'GATEWAY_ERROR' : '', microtime(true) - $start);
        if (!empty($result['success'])) {
            return $result;
        }
        return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $transactionId, 0, '', $this->describeFailure($result));
    }

    public function refund(string $captureId, ?float $amount = null, string $currency = 'USD'): array
    {
        $start = microtime(true);
        $result = $this->svc->refundCapture($captureId, $amount, $currency);
        GatewayLogger::log('paypal', 'refund', ['capture_id' => $captureId], $result, empty($result['success']) ? 'GATEWAY_ERROR' : '', microtime(true) - $start);
        if (!empty($result['success'])) {
            return $result;
        }
        return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $captureId, $amount ?? 0, $currency, $this->describeFailure($result));
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
        $issue = strtoupper(trim((string)($result['error_code'] ?? $result['raw']['details'][0]['issue'] ?? '')));
        $debugId = trim((string)($result['raw']['debug_id'] ?? ''));

        if ($issue === 'PAYEE_NOT_ENABLED_FOR_CARD_PROCESSING'
            || str_contains(strtolower($message), 'not setup to be able to process card')
            || str_contains(strtolower($message), 'not enabled for card')) {
            $message = 'حساب PayPal الحي غير مفعّل لمدفوعات البطاقة المباشرة. '
                . 'فعّل Advanced Credit and Debit Card Payments من Developer Dashboard '
                . '(Live → App → Features → Accept payments)، أو استخدم زر محفظة PayPal.';
        } elseif ($message === '') {
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
}
