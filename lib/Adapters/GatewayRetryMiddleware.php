<?php
/**
 * ============================================================
 * DI PARMA | GatewayRetryMiddleware
 * إعادة محاولة ذكية عند فشل الاتصال — Exponential Backoff
 * ============================================================
 * - يُعيد المحاولة فقط على أخطاء NETWORK_ERROR / GATEWAY_ERROR
 * - يستخدم Idempotency Key لمنع تكرار الخصم
 * - يُسجّل كل محاولة في GatewayLogger
 * - يدعم تكوين MaxRetries و BaseDelay مستقلاً لكل عملية
 * ============================================================
 */

require_once __DIR__ . '/GatewayAdapterInterface.php';
require_once __DIR__ . '/GatewayErrorMapper.php';
require_once __DIR__ . '/GatewayLogger.php';

final class GatewayRetryMiddleware implements GatewayAdapterInterface
{
    private GatewayAdapterInterface $adapter;
    private int   $maxRetries;
    private int   $baseDelayMs;   // تأخير أساسي بالمللي ثانية

    // أخطاء قابلة للإعادة فقط
    private const RETRYABLE_CODES = ['NETWORK_ERROR', 'GATEWAY_ERROR'];

    public function __construct(
        GatewayAdapterInterface $adapter,
        int $maxRetries  = 3,
        int $baseDelayMs = 500
    ) {
        $this->adapter     = $adapter;
        $this->maxRetries  = max(1, $maxRetries);
        $this->baseDelayMs = max(100, $baseDelayMs);
    }

    public function getName(): string
    {
        return $this->adapter->getName();
    }

    public function supports(string $mode): bool
    {
        return $this->adapter->supports($mode);
    }

    public function normalizeError(array $rawResponse): string
    {
        return $this->adapter->normalizeError($rawResponse);
    }

    public function buildIdempotencyKey(string $reference, float $amount): string
    {
        return $this->adapter->buildIdempotencyKey($reference, $amount);
    }

    // ══════════════════════════════════════════════════════════
    // CHARGE — مع retry
    // ══════════════════════════════════════════════════════════
    public function charge(array $payload): array
    {
        return $this->withRetry('charge', $payload);
    }

    // ══════════════════════════════════════════════════════════
    // HOLD — مع retry
    // ══════════════════════════════════════════════════════════
    public function hold(array $payload): array
    {
        return $this->withRetry('hold', $payload);
    }

    // ══════════════════════════════════════════════════════════
    // CAPTURE — مع retry
    // ══════════════════════════════════════════════════════════
    public function capture(string $transactionId, ?float $amount = null): array
    {
        return $this->withRetryArgs('capture', [$transactionId, $amount]);
    }

    // ══════════════════════════════════════════════════════════
    // CANCEL — مع retry (مرة واحدة فقط — void لا يُكرر)
    // ══════════════════════════════════════════════════════════
    public function cancel(string $transactionId, string $reason = 'requested_by_customer'): array
    {
        // CANCEL لا يُعاد إلا مرة واحدة — خطر التكرار
        return $this->withRetryArgs('cancel', [$transactionId, $reason], 1);
    }

    // ══════════════════════════════════════════════════════════
    // المنطق الأساسي للـ Retry
    // ══════════════════════════════════════════════════════════

    /**
     * تنفيذ عملية charge/hold مع payload
     */
    private function withRetry(string $operation, array $payload, ?int $maxRetries = null): array
    {
        $max     = $maxRetries ?? $this->maxRetries;
        $attempt = 0;

        while ($attempt < $max) {
            $attempt++;
            $start = microtime(true);

            try {
                $response = ($operation === 'charge')
                    ? $this->adapter->charge($payload)
                    : $this->adapter->hold($payload);

                $duration = microtime(true) - $start;

                // نجاح أو رفض بنكي (غير قابل للإعادة)
                if ($response['success'] ?? false) {
                    if ($attempt > 1) {
                        GatewayLogger::quick($this->getName(), "$operation:retry:success",
                            $payload['reference'] ?? '', true, "attempt=$attempt");
                    }
                    return $response;
                }

                $errCode = $response['error_code'] ?? $response['decline_code'] ?? 'UNKNOWN';

                // إذا الخطأ غير قابل للإعادة — أوقف فوراً
                if (!in_array($errCode, self::RETRYABLE_CODES)) {
                    return $response;
                }

                // خطأ قابل للإعادة — سجّل وانتظر
                GatewayLogger::quick($this->getName(), "$operation:retry",
                    $payload['reference'] ?? '', false,
                    "attempt=$attempt/$max errCode=$errCode duration=" . round($duration, 3) . "s");

            } catch (\Throwable $e) {
                GatewayLogger::quick($this->getName(), "$operation:exception",
                    $payload['reference'] ?? '', false,
                    "attempt=$attempt/$max " . $e->getMessage());
            }

            // لا تنتظر بعد آخر محاولة
            if ($attempt < $max) {
                // Exponential Backoff: 500ms, 1000ms, 2000ms...
                $delayUs = $this->baseDelayMs * (2 ** ($attempt - 1)) * 1000;
                usleep(min($delayUs, 8_000_000)); // حد أقصى 8 ثواني
            }
        }

        // استنفذنا كل المحاولات
        GatewayLogger::quick($this->getName(), "$operation:exhausted",
            $payload['reference'] ?? '', false, "max=$max attempts exhausted");

        return GatewayErrorMapper::buildErrorResponse(
            'NETWORK_ERROR',
            $payload['reference'] ?? '',
            floatval($payload['amount']   ?? 0),
            strtoupper($payload['currency'] ?? ''),
            "❌ فشل الاتصال بالبوابة بعد $max محاولات"
        );
    }

    /**
     * تنفيذ عملية capture/cancel مع args بدلاً من payload
     */
    private function withRetryArgs(string $operation, array $args, ?int $maxRetries = null): array
    {
        $max     = $maxRetries ?? $this->maxRetries;
        $attempt = 0;
        $txId    = $args[0] ?? '';

        while ($attempt < $max) {
            $attempt++;

            try {
                $response = match($operation) {
                    'capture' => $this->adapter->capture($args[0], $args[1] ?? null),
                    'cancel'  => $this->adapter->cancel($args[0], $args[1] ?? 'requested_by_customer'),
                    default   => GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR'),
                };

                if ($response['success'] ?? false) {
                    return $response;
                }

                $errCode = $response['error_code'] ?? 'UNKNOWN';
                if (!in_array($errCode, self::RETRYABLE_CODES)) {
                    return $response;
                }

                GatewayLogger::quick($this->getName(), "$operation:retry",
                    $txId, false, "attempt=$attempt/$max errCode=$errCode");

            } catch (\Throwable $e) {
                GatewayLogger::quick($this->getName(), "$operation:exception",
                    $txId, false, "attempt=$attempt " . $e->getMessage());
            }

            if ($attempt < $max) {
                usleep($this->baseDelayMs * (2 ** ($attempt - 1)) * 1000);
            }
        }

        return GatewayErrorMapper::buildErrorResponse('NETWORK_ERROR', $txId, 0, '',
            "❌ فشل $operation بعد $max محاولات");
    }
}
