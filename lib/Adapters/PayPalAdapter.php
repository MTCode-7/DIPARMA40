<?php
declare(strict_types=1);

/**
 * PayPal card adapter — Advanced Card Processing.
 *
 * العمليات:
 *   charge, hold, capture, cancel, refund
 *
 * أوضاع الدفع:
 *   2D      — بدون 3D Secure
 *   3D      — مع 3D Secure (SCA)
 *   MOTO    — Mail Order / Telephone Order
 *   OFFLINE — إدخال يدوي / بطاقة غير موجودة (CNP)
 *
 * ملاحظات PayPal:
 *   - MOTO/OFFLINE يتطلبان تفعيلاً منفصلاً من Developer Dashboard.
 *   - 3D Secure يُمرَّر عبر payment_source.card.attributes.verification.
 *   - MOTO/OFFLINE لـ SAR و AED و EGP يُرسلان إلى PayPal. رفض البوابة يُحفظ كرفض.
 */

require_once __DIR__ . '/GatewayAdapterInterface.php';
require_once __DIR__ . '/GatewayCapabilitiesInterface.php';
require_once __DIR__ . '/GatewayErrorMapper.php';
require_once __DIR__ . '/GatewayLogger.php';
require_once __DIR__ . '/../PayPalService.php';

final class PayPalAdapter implements GatewayAdapterInterface, GatewayCapabilitiesInterface
{
    /* ============================================================
     *                       CONSTANTS
     * ============================================================ */

    /** العمليات المدعومة */
    private const SUPPORTED_OPS = ['CHARGE', 'HOLD', 'CAPTURE', 'CANCEL', 'REFUND'];

    /** أوضاع الدفع — الكود يعرف كيف ينفّذها */
    private const MODE_2D      = '2D';
    private const MODE_3D      = '3D';
    private const MODE_MOTO    = 'MOTO';
    private const MODE_OFFLINE = 'OFFLINE';

    private const CODE_SUPPORTED_MODES = [
        self::MODE_2D,
        self::MODE_3D,
        self::MODE_MOTO,
        self::MODE_OFFLINE,
    ];

    /** أوضاع افتراضية مفعّلة دائماً بلا حاجة لتفعيل يدوي */
    private const ALWAYS_ENABLED_MODES = [self::MODE_2D, self::MODE_3D];

    /** مصفوفة التوافق: عملية → أوضاع مسموحة */
    private const OP_MODE_MATRIX = [
        'CHARGE'  => [self::MODE_2D, self::MODE_3D, self::MODE_MOTO, self::MODE_OFFLINE],
        'HOLD'    => [self::MODE_2D, self::MODE_3D],
        'CAPTURE' => self::CODE_SUPPORTED_MODES,
        'CANCEL'  => self::CODE_SUPPORTED_MODES,
        'REFUND'  => self::CODE_SUPPORTED_MODES,
    ];

    /** العملات المدعومة */
    private const ALLOWED_CURRENCIES = ['USD', 'EUR', 'GBP', 'SAR', 'AED', 'EGP'];

    private const FLOAT_EPSILON  = 0.01;
    private const MIN_KEY_LENGTH = 32;

    private const SENSITIVE_FIELDS = [
        'card_number', 'number', 'pan', 'cvv', 'cvc',
        'security_code', 'expiry', 'exp_month', 'exp_year',
        'card', 'payment_source',
    ];

    /* ============================================================
     *                       PROPERTIES
     * ============================================================ */

    private PayPalService $svc;

    /** الأوضاع المفعّلة للحساب — تُحمَّل عند البناء */
    private array $enabledModes;

    /* ============================================================
     *                    CONSTRUCTOR / DI
     * ============================================================ */

    public function __construct(?PayPalService $svc = null, ?array $enabledModes = null)
    {
        $this->svc = $svc ?? PayPalService::getInstance();
        $this->enabledModes = $enabledModes !== null
            ? array_values(array_unique(array_map('strtoupper', $enabledModes)))
            : $this->loadEnabledModesFromConfig();
    }

    /**
     * يقرأ الأوضاع المفعّلة من متغيرات البيئة.
     * أولويات:
     *   2D, 3D  → دائماً
     *   MOTO    → PAYPAL_MOTO_ENABLED=1
     *   OFFLINE → PAYPAL_OFFLINE_ENABLED=1
     */
    private function loadEnabledModesFromConfig(): array
    {
        $enabled = self::ALWAYS_ENABLED_MODES;

        // Officially approved. Explicit 0/false/off still turns a mode off.
        if ($this->envEnabledByDefault('PAYPAL_MOTO_ENABLED')) {
            $enabled[] = self::MODE_MOTO;
        }
        if ($this->envEnabledByDefault('PAYPAL_OFFLINE_ENABLED')) {
            $enabled[] = self::MODE_OFFLINE;
        }

        return $enabled;
    }

    private function envEnabledByDefault(string $key): bool
    {
        $raw = getenv($key);
        if ($raw === false || trim((string) $raw) === '') {
            return true;
        }
        return !in_array(strtolower(trim((string) $raw)), ['0', 'false', 'off', 'no'], true);
    }

    /* ============================================================
     *                    IDENTITY / CAPABILITIES
     * ============================================================ */

    public function getName(): string
    {
        return 'paypal';
    }

    public function supports(string $mode): bool
    {
        $mode = strtoupper($mode);
        if (in_array($mode, self::SUPPORTED_OPS, true)) {
            return true;
        }
        return in_array($mode, ['2D', '3D', 'MOTO', 'OFFLINE', 'ADVICE'], true);
    }

    public function supportsMode(string $mode): bool
    {
        return in_array(strtoupper($mode), self::CODE_SUPPORTED_MODES, true);
    }

    public function supportedOperations(): array
    {
        return self::SUPPORTED_OPS;
    }

    public function supportedModes(): array
    {
        return self::CODE_SUPPORTED_MODES;
    }

    public function enabledModes(): array
    {
        return $this->enabledModes;
    }

    public function isModeEnabled(string $mode): bool
    {
        return in_array(strtoupper($mode), $this->enabledModes, true);
    }

    public function operationModeMatrix(): array
    {
        return self::OP_MODE_MATRIX;
    }

    public function supportedCurrencies(): array
    {
        return self::ALLOWED_CURRENCIES;
    }

    /**
     * تُرجع لقطة كاملة لقدرات البوابة — للواجهة/الـ API.
     */
    public function capabilitiesSnapshot(): array
    {
        return [
            'gateway'    => $this->getName(),
            'operations' => $this->supportedOperations(),
            'modes'      => $this->supportedModes(),
            'enabled'    => $this->enabledModes(),
            'currencies' => $this->supportedCurrencies(),
            'matrix'     => $this->operationModeMatrix(),
        ];
    }

    /* ============================================================
     *                       ERROR MAPPING
     * ============================================================ */

    public function normalizeError(array $rawResponse): string
    {
        $issue = strtoupper((string) (
            $rawResponse['details'][0]['issue']
            ?? $rawResponse['error_code']
            ?? $rawResponse['name']
            ?? ''
        ));

        $map = [
            // بطاقة
            'CARD_DECLINED'                          => 'CARD_DECLINED',
            'CARD_EXPIRED'                           => 'EXPIRED_CARD',
            'EXPIRED_CARD'                           => 'EXPIRED_CARD',
            'INVALID_SECURITY_CODE'                  => 'INVALID_CVV',
            'INVALID_CVV'                            => 'INVALID_CVV',
            'INVALID_ACCOUNT_NUMBER'                 => 'INVALID_CARD',
            'INVALID_CARD'                           => 'INVALID_CARD',
            'INSUFFICIENT_FUNDS'                     => 'INSUFFICIENT_FUNDS',
            'TRANSACTION_BLOCKED_BY_PAYEE'           => 'DO_NOT_HONOR',

            // بوابة
            'PAYEE_NOT_ENABLED_FOR_CARD_PROCESSING'  => 'GATEWAY_ERROR',
            'NOT_ENABLED'                            => 'GATEWAY_ERROR',
            'INCOMPATIBLE_PARAMETER_VALUE'           => 'GATEWAY_ERROR',
            'NETWORK_ERROR'                          => 'NETWORK_ERROR',
            'GATEWAY_ERROR'                          => 'GATEWAY_ERROR',

            // 3D Secure
            'PAYER_ACTION_REQUIRED'                  => 'THREE_DS_REQUIRED',
            'AUTHENTICATION_FAILURE'                 => 'THREE_DS_FAILED',
            'SCA_REQUIRED'                           => 'THREE_DS_REQUIRED',
            'TRANSACTION_REFUSED'                    => 'THREE_DS_FAILED',

            // MOTO / OFFLINE
            'MOTO_NOT_ENABLED'                       => 'MOTO_NOT_ENABLED',
            'PAYEE_NOT_ENABLED_FOR_MOTO'             => 'MOTO_NOT_ENABLED',
            'CARD_NOT_SUPPORTED_FOR_MOTO'            => 'CARD_NOT_SUPPORTED',
        ];

        return $map[$issue] ?? 'GATEWAY_ERROR';
    }

    /* ============================================================
     *                    IDEMPOTENCY
     * ============================================================ */

    public function buildIdempotencyKey(string $reference, float $amount): string
    {
        $key = getenv('ENCRYPTION_KEY');
        if ($key === false || strlen($key) < self::MIN_KEY_LENGTH) {
            throw new \RuntimeException(
                'ENCRYPTION_KEY is missing or shorter than ' . self::MIN_KEY_LENGTH . ' chars'
            );
        }

        return hash_hmac(
            'sha256',
            'pp_' . $reference . '|' . number_format($amount, 2, '.', ''),
            $key
        );
    }

    /* ============================================================
     *                       OPERATIONS
     * ============================================================ */

    public function charge(array $payload): array
    {
        $payload = $this->normalizeAdviceAmount($payload);
        $mode = $this->resolveMode($payload);
        return $this->runCard($payload, 'CAPTURE', 'charge', $mode);
    }

    public function hold(array $payload): array
    {
        $payload = $this->payloadForAuthorize($payload);
        $mode = $this->resolveMode($payload);
        if (!in_array($mode, [self::MODE_2D, self::MODE_3D], true)) {
            $mode = self::MODE_2D;
            $payload['processing_mode'] = self::MODE_2D;
            $payload['mode'] = self::MODE_2D;
            $payload['is_moto'] = false;
        }
        return $this->runCard($payload, 'AUTHORIZE', 'hold', $mode);
    }

    /**
     * PayPal rejects intent AUTHORIZE when the card is sent as MOTO.
     * HOLD stays 2D or 3D. Sale MOTO types keep their own charge() path.
     */
    private function payloadForAuthorize(array $payload): array
    {
        $explicit = strtoupper(trim((string) (
            $payload['security_mode']
            ?? $payload['processing_mode']
            ?? $payload['mode']
            ?? $payload['charge_mode']
            ?? ''
        )));
        $mode = $explicit === self::MODE_3D ? self::MODE_3D : self::MODE_2D;
        $payload['processing_mode'] = $mode;
        $payload['security_mode'] = $mode;
        $payload['mode'] = $mode;
        $payload['charge_mode'] = $mode;
        $payload['is_moto'] = false;
        $payload['is_offline'] = false;
        $payload['auth_channel'] = 'ecom';
        unset($payload['moto_channel'], $payload['moto_type'], $payload['moto_indicator'], $payload['_moto'], $payload['_offline']);
        return $payload;
    }

    public function capture(string $transactionId, ?float $amount = null): array
    {
        $start = microtime(true);

        $auth = $this->svc->getAuthorization($transactionId);
        if (!is_array($auth) || empty($auth['id'] ?? $auth['success'] ?? null)) {
            return GatewayErrorMapper::buildErrorResponse(
                'AUTHORIZATION_NOT_FOUND',
                $transactionId,
                $amount ?? 0.0,
                'USD',
                "Authorization {$transactionId} not found or not retrievable"
            );
        }

        [$currency, $authAmount] = $this->extractAuthAmount($auth);

        if ($authAmount <= 0) {
            return GatewayErrorMapper::buildErrorResponse(
                'INVALID_AUTHORIZATION_AMOUNT',
                $transactionId,
                $amount ?? 0.0,
                $currency,
                'Authorization has no valid amount'
            );
        }

        $captureAmount = $amount ?? $authAmount;

        if ($captureAmount <= 0) {
            return GatewayErrorMapper::buildErrorResponse(
                'INVALID_CAPTURE_AMOUNT',
                $transactionId,
                $captureAmount,
                $currency,
                'Capture amount must be greater than zero'
            );
        }

        $isFullAmount = abs($captureAmount - $authAmount) < self::FLOAT_EPSILON;
        $isOver = $captureAmount > $authAmount + self::FLOAT_EPSILON;
        $finalCapture = $isFullAmount || $isOver || $this->shouldClosePartialCapture();

        $result = $this->svc->captureAuthorization(
            $transactionId,
            $captureAmount,
            $currency,
            ['final_capture' => $finalCapture]
        );

        GatewayLogger::log(
            'paypal',
            'capture',
            [
                'transaction_id' => $transactionId,
                'amount'         => $captureAmount,
                'auth_amount'    => $authAmount,
                'currency'       => $currency,
                'final_capture'  => $finalCapture,
                'partial'        => !$isFullAmount,
            ],
            $result,
            empty($result['success']) ? 'GATEWAY_ERROR' : '',
            microtime(true) - $start
        );

        if (!empty($result['success'])) {
            $result['_meta'] = [
                'auth_amount'      => $authAmount,
                'captured_amount'  => $captureAmount,
                'remaining_amount' => max(0.0, $authAmount - $captureAmount),
                'final_capture'    => $finalCapture,
            ];
            return $result;
        }

        return GatewayErrorMapper::buildErrorResponse(
            $this->normalizeError($result['raw'] ?? $result),
            $transactionId,
            $captureAmount,
            $currency,
            $this->describeFailure($result)
        );
    }

    public function cancel(string $transactionId, string $reason = 'requested_by_customer'): array
    {
        $start = microtime(true);

        $result = $this->svc->voidAuthorization($transactionId);

        GatewayLogger::log(
            'paypal',
            'cancel',
            ['transaction_id' => $transactionId, 'reason' => $reason],
            $result,
            empty($result['success']) ? 'GATEWAY_ERROR' : '',
            microtime(true) - $start
        );

        if (!empty($result['success'])) {
            return $result;
        }

        return GatewayErrorMapper::buildErrorResponse(
            $this->normalizeError($result['raw'] ?? $result),
            $transactionId,
            0.0,
            '',
            $this->describeFailure($result)
        );
    }

    public function refund(string $captureId, ?float $amount = null, string $currency = 'USD'): array
    {
        $start = microtime(true);

        if ($amount !== null && $amount <= 0) {
            return GatewayErrorMapper::buildErrorResponse(
                'INVALID_REFUND_AMOUNT',
                $captureId,
                $amount,
                $currency,
                'Refund amount must be greater than zero'
            );
        }

        $result = $this->svc->refundCapture($captureId, $amount, $currency);

        GatewayLogger::log(
            'paypal',
            'refund',
            ['capture_id' => $captureId, 'amount' => $amount, 'currency' => $currency],
            $result,
            empty($result['success']) ? 'GATEWAY_ERROR' : '',
            microtime(true) - $start
        );

        if (!empty($result['success'])) {
            return $result;
        }

        return GatewayErrorMapper::buildErrorResponse(
            $this->normalizeError($result['raw'] ?? $result),
            $captureId,
            $amount ?? 0.0,
            $currency,
            $this->describeFailure($result)
        );
    }

    /* ============================================================
     *                       MODE HANDLING
     * ============================================================ */

    private function resolveMode(array $payload): string
    {
        $mode = strtoupper(trim((string) (
            $payload['mode']
            ?? $payload['processing_mode']
            ?? $payload['security_mode']
            ?? $payload['charge_mode']
            ?? ''
        )));
        $txn = strtolower(trim((string) ($payload['txn_type'] ?? '')));
        $channel = strtolower(trim((string) ($payload['moto_channel'] ?? $payload['auth_channel'] ?? '')));
        if (
            $mode === self::MODE_OFFLINE
            || $txn === 'offline_sale_moto'
            || $channel === 'offline'
            || !empty($payload['is_offline'])
        ) {
            return self::MODE_OFFLINE;
        }
        if (
            $mode === self::MODE_MOTO
            || !empty($payload['is_moto'])
            || $txn === 'online_sale_moto'
            || $channel === 'online'
        ) {
            return self::MODE_MOTO;
        }
        if (in_array($mode, [self::MODE_2D, self::MODE_3D], true)) {
            return $mode;
        }
        return $mode !== '' ? $mode : self::MODE_2D;
    }

    /**
     * Negative purchase advice. PayPalService has no unlinked credit
     * (refundCapture requires a prior capture), so the positive amount is sent
     * with advice_sign=minus. A gateway error stays a decline.
     */
    private function normalizeAdviceAmount(array $payload): array
    {
        $txn = strtolower(trim((string) ($payload['txn_type'] ?? '')));
        $amount = (float) ($payload['amount'] ?? 0);
        if ($txn === 'purchase_advice' && $amount < 0) {
            $payload['advice_sign'] = 'minus';
            $payload['amount'] = abs($amount);
        }
        return $payload;
    }

    public function reauthorize(string $authorizationId, ?float $amount = null, string $currency = 'USD'): array
    {
        $start = microtime(true);
        $authorizationId = trim($authorizationId);
        $currency = strtoupper(trim($currency)) ?: 'USD';
        if ($authorizationId === '' || $amount === null || $amount <= 0) {
            return GatewayErrorMapper::buildErrorResponse(
                'GATEWAY_ERROR',
                $authorizationId,
                (float) ($amount ?? 0),
                $currency,
                'Authorization ID and amount are required'
            );
        }

        $result = $this->svc->reauthorizeAuthorization($authorizationId, $amount, $currency);
        GatewayLogger::log(
            'paypal',
            'reauthorize',
            ['authorization_id' => $authorizationId, 'amount' => $amount, 'currency' => $currency],
            $result,
            empty($result['success']) ? 'GATEWAY_ERROR' : '',
            microtime(true) - $start
        );
        if (!empty($result['success'])) {
            return $result;
        }

        return GatewayErrorMapper::buildErrorResponse(
            $this->normalizeError(is_array($result['raw'] ?? null) ? $result['raw'] : $result),
            $authorizationId,
            $amount,
            $currency,
            $this->describeFailure($result)
        );
    }

    /**
     * تحقق مزدوج:
     *   1) الكود يدعم الوضع؟
     *   2) الحساب مفعّل الوضع؟
     *   3) العملية متوافقة مع الوضع؟
     */
    private function assertModeCompatible(string $operation, string $mode): void
    {
        if (!$this->supportsMode($mode)) {
            throw new \InvalidArgumentException(
                "Payment mode '{$mode}' is not supported by this adapter. "
                . 'Code supports: ' . implode(', ', self::CODE_SUPPORTED_MODES)
            );
        }

        if (!$this->isModeEnabled($mode)) {
            throw new \InvalidArgumentException(
                "Payment mode '{$mode}' is not enabled for this account. "
                . 'Currently enabled: ' . implode(', ', $this->enabledModes)
            );
        }

        $allowed = self::OP_MODE_MATRIX[$operation] ?? [];
        if (!in_array($mode, $allowed, true)) {
            throw new \InvalidArgumentException(
                "Mode {$mode} is not compatible with operation {$operation}"
            );
        }
    }

    /**
     * حقن خصائص الوضع في الـ payload قبل الإرسال.
     */
    private function applyModeToPayload(array $payload, string $mode): array
    {
        if (!isset($payload['payment_source']) || !is_array($payload['payment_source'])) {
            $payload['payment_source'] = [];
        }
        if (!isset($payload['payment_source']['card']) || !is_array($payload['payment_source']['card'])) {
            $payload['payment_source']['card'] = [];
        }

        switch ($mode) {
            case self::MODE_3D:
                $payload['payment_source']['card']['attributes'] = array_merge(
                    $payload['payment_source']['card']['attributes'] ?? [],
                    ['verification' => ['method' => 'SCA_WHEN_REQUIRED']]
                );
                break;

            case self::MODE_2D:
                $payload['payment_source']['card']['attributes'] = array_merge(
                    $payload['payment_source']['card']['attributes'] ?? [],
                    ['verification' => ['method' => 'SCA_NEVER']]
                );
                break;

            case self::MODE_MOTO:
            case self::MODE_OFFLINE:
                $payload['payment_source']['card']['attributes'] = array_merge(
                    $payload['payment_source']['card']['attributes'] ?? [],
                    ['verification' => ['method' => 'SCA_NEVER']]
                );
                $payload['_moto']    = true;
                $payload['_offline'] = ($mode === self::MODE_OFFLINE);
                break;
        }

        $payload['_mode'] = $mode;
        return $payload;
    }

    /* ============================================================
     *                       INTERNALS
     * ============================================================ */

    private function runCard(array $payload, string $intent, string $operation, string $mode): array
    {
        $start = microtime(true);

        $this->validatePayload($payload, $mode);
        $this->assertModeCompatible(strtoupper($operation), $mode);

        $payload = $this->applyModeToPayload($payload, $mode);

        $reference = (string) $payload['reference'];
        $amount    = (float)  $payload['amount'];
        $currency  = strtoupper((string) ($payload['currency'] ?? 'USD'));

        $result = $this->svc->processCard($payload, $intent);

        if (!empty($result['success'])) {
            GatewayLogger::log(
                'paypal',
                $operation,
                $this->sanitizeForLog($payload),
                $result,
                '',
                microtime(true) - $start
            );
            return $result;
        }

        $errCode = $this->normalizeError($result['raw'] ?? $result);

        GatewayLogger::log(
            'paypal',
            $operation,
            $this->sanitizeForLog($payload),
            $result,
            $errCode,
            microtime(true) - $start
        );

        return GatewayErrorMapper::buildErrorResponse(
            $errCode,
            $reference,
            $amount,
            $currency,
            $this->describeFailure($result)
        );
    }

    private function validatePayload(array $payload, string $mode): void
    {
        if (empty($payload['reference']) || !is_string($payload['reference'])) {
            throw new \InvalidArgumentException('Missing or invalid "reference"');
        }

        if (!isset($payload['amount']) || !is_numeric($payload['amount'])) {
            throw new \InvalidArgumentException('Missing or non-numeric "amount"');
        }

        if ((float) $payload['amount'] <= 0) {
            throw new \InvalidArgumentException('"amount" must be greater than zero');
        }

        $currency = strtoupper((string) ($payload['currency'] ?? 'USD'));
        if (!in_array($currency, self::ALLOWED_CURRENCIES, true)) {
            throw new \InvalidArgumentException("Unsupported currency: {$currency}");
        }

        if (in_array($mode, [self::MODE_MOTO, self::MODE_OFFLINE], true)) {
            $card = $payload['payment_source']['card'] ?? [];
            $number = (string) ($card['number'] ?? $payload['card_number'] ?? $payload['cc_number'] ?? '');
            $expiry = (string) ($card['expiry'] ?? $payload['card_expiry'] ?? $payload['cc_expiry'] ?? '');
            $token = trim((string) ($payload['cloud_token'] ?? $payload['payment_token'] ?? $payload['vault_id'] ?? ''));
            if (($number === '' || $expiry === '') && $token === '') {
                throw new \InvalidArgumentException(
                    'MOTO/OFFLINE requires card number and expiry'
                );
            }
        }
    }

    /**
     * @return array{0:string,1:float}
     */
    private function extractAuthAmount(array $auth): array
    {
        $currency = 'USD';
        if (!empty($auth['currency'])) {
            $currency = (string) $auth['currency'];
        } elseif (is_array($auth['amount'] ?? null) && !empty($auth['amount']['currency_code'])) {
            $currency = (string) $auth['amount']['currency_code'];
        }
        $currency = strtoupper(trim($currency)) ?: 'USD';

        $amount = 0.0;
        if (is_array($auth['amount'] ?? null)) {
            $amount = (float) ($auth['amount']['value'] ?? 0);
        } elseif (isset($auth['amount'])) {
            $amount = (float) $auth['amount'];
        }

        return [$currency, $amount];
    }

    private function shouldClosePartialCapture(): bool
    {
        // false = يسمح بـ partial captures متعددة على نفس الـ authorization
        // true  = يغلق الـ authorization بعد أول capture (حتى لو جزئي)
        // القيمة مقروءة من .env — افتراضي false لدعم Over-Capture المتعدد
        return filter_var(getenv('PAYPAL_FINAL_CAPTURE_ON_PARTIAL') ?: 'false', FILTER_VALIDATE_BOOLEAN);
    }

    private function describeFailure(array $result): string
    {
        $message = trim((string) ($result['message'] ?? ''));
        $issue   = strtoupper(trim((string) (
            $result['error_code']
            ?? $result['raw']['details'][0]['issue']
            ?? ''
        )));
        $debugId = trim((string) ($result['raw']['debug_id'] ?? ''));

        if ($debugId !== '') {
            GatewayLogger::log(
                'paypal',
                'failure_debug',
                ['issue' => $issue],
                ['debug_id' => $debugId],
                $issue,
                0.0
            );
        }

        if (
            $issue === 'PAYEE_NOT_ENABLED_FOR_CARD_PROCESSING'
            || str_contains(strtolower($message), 'not setup to be able to process card')
            || str_contains(strtolower($message), 'not enabled for card')
        ) {
            $message = 'حساب PayPal الحي غير مفعّل لمدفوعات البطاقة المباشرة (Advanced Credit and Debit Card Payments). '
                . 'التفعيل يتم من لوحة PayPal ولا يمكن للكود فتحه.';
        } elseif (in_array($issue, ['MOTO_NOT_ENABLED', 'PAYEE_NOT_ENABLED_FOR_MOTO'], true)) {
            $message = 'حساب PayPal لا يدعم MOTO/OFFLINE. '
                . 'فعّل Mail Order / Telephone Order من Developer Dashboard.';
        } elseif (in_array($issue, ['THREE_DS_REQUIRED', 'PAYER_ACTION_REQUIRED'], true)) {
            $message = 'البطاقة تتطلب 3D Secure. أعد المحاولة بوضع 3D.';
        } elseif ($issue === 'THREE_DS_FAILED') {
            $message = 'فشل التحقق من 3D Secure. اطلب من العميل المحاولة مرة أخرى.';
        } elseif ($message === '') {
            $message = 'PayPal رفض عملية البطاقة';
        }

        if ($issue !== '' && stripos($message, $issue) === false) {
            $message .= " [$issue]";
        }

        return $message;
    }

    private function sanitizeForLog(array $data): array
    {
        foreach (self::SENSITIVE_FIELDS as $field) {
            if (isset($data[$field])) {
                $data[$field] = '***REDACTED***';
            }
        }
        return $data;
    }
}