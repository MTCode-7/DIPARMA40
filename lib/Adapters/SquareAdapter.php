<?php
declare(strict_types=1);

/**
 * DI PARMA | SquareAdapter
 * Square Payments API: charge / hold / capture / cancel / refund
 * Docs: https://developer.squareup.com
 *
 * أوضاع الدفع:
 *   2D      — Web Payments SDK token (source_id / cloud_token)
 *   3D      — Web Payments SDK + verification_token (SCA)
 *   MOTO    — keyed card charge on the same CreatePayment call
 *   OFFLINE — same CreatePayment call, mode sent on the payment note
 *
 * Square 2 (square_online) uses SQUARE2_* credentials and the same card calls.
 * Sandbox is rejected. The per-payment cap stays 50,000 USD.
 */

require_once __DIR__ . '/GatewayAdapterInterface.php';
require_once __DIR__ . '/GatewayCapabilitiesInterface.php';
require_once __DIR__ . '/GatewayErrorMapper.php';
require_once __DIR__ . '/GatewayLogger.php';

final class SquareAdapter implements GatewayAdapterInterface, GatewayCapabilitiesInterface
{
    /* ============================================================
     *                       CONSTANTS
     * ============================================================ */

    private const SUPPORTED_OPS = ['CHARGE', 'HOLD', 'CAPTURE', 'CANCEL', 'REFUND'];

    /** الأوضاع المعتمدة عبر Payments API (نفس طلب البطاقة) */
    private const CODE_SUPPORTED_MODES = ['2D', '3D', 'MOTO', 'OFFLINE'];

    private const ALLOWED_CURRENCIES = ['USD', 'CAD', 'GBP', 'EUR', 'AUD', 'JPY', 'AED', 'SAR'];

    private const MIN_KEY_LENGTH = 32;
    private const FLOAT_EPSILON  = 0.01;
    private const MAX_TXN_USD    = 50_000.00;
    private const CURL_TIMEOUT   = 45;
    private const CURL_CONNECT_TIMEOUT = 10;
    private const MAX_RETRIES    = 3;

    private const SENSITIVE_FIELDS = [
        'source_id', 'cloud_token', 'payment_token', 'payment_method',
        'verification_token', 'square_verification',
        'card_number', 'number', 'pan', 'cvv', 'cvc',
    ];

    /* ============================================================
     *                       PROPERTIES
     * ============================================================ */

    private static string $pendingProfile = 'square';

    private string $profile = 'square';
    private string $accessToken;
    private string $applicationId;
    private string $locationId;
    private string $baseUrl;
    private bool   $sandbox;
    private array  $enabledModes;

    public static function forProfile(string $profile): void
    {
        self::$pendingProfile = $profile === 'square_online' ? 'square_online' : 'square';
    }

    /** Cache لعملة الـ location — يُجنّب طلب HTTP إضافي في كل عملية */
    private ?string $cachedLocationCurrency = null;

    /* ============================================================
     *                    CONSTRUCTOR
     * ============================================================ */

    public function __construct(?array $enabledModes = null)
    {
        $this->profile = self::$pendingProfile;
        self::$pendingProfile = 'square';

        $sdkFile = dirname(__DIR__, 2) . '/includes/square_sdk.php';
        if (is_file($sdkFile)) {
            require_once $sdkFile;
        }

        if ($this->profile === 'square_online') {
            $this->loadSquareOnlineCredentials();
        } else {
            $creds = function_exists('square_runtime_credentials')
                ? square_runtime_credentials()
                : [];

            $this->accessToken   = trim((string) ($creds['access_token']   ?? getenv('SQUARE_ACCESS_TOKEN')   ?: getenv('SQUARE_SECRET_KEY')   ?: ''));
            $this->applicationId = trim((string) ($creds['application_id'] ?? getenv('SQUARE_APPLICATION_ID') ?: getenv('SQUARE_API_KEY')       ?: ''));
            $this->locationId    = trim((string) ($creds['location_id']    ?? getenv('SQUARE_LOCATION_ID')    ?: ''));

            $envHint = strtolower(trim((string) ($creds['environment'] ?? getenv('SQUARE_ENVIRONMENT') ?: 'production')));

            $this->sandbox  = $this->detectSandbox($creds, $envHint);
            $this->baseUrl  = 'https://connect.squareup.com';
        }

        $this->enabledModes = $enabledModes !== null
            ? array_values(array_unique(array_map('strtoupper', $enabledModes)))
            : self::CODE_SUPPORTED_MODES;
    }

    private function loadSquareOnlineCredentials(): void
    {
        $this->accessToken = trim((string) (getenv('SQUARE2_ACCESS_TOKEN') ?: ''));
        $this->applicationId = trim((string) (getenv('SQUARE2_APPLICATION_ID') ?: ''));
        $this->locationId = trim((string) (getenv('SQUARE2_LOCATION_ID') ?: ''));
        try {
            $row = function_exists('db') ? db()->find('payment_gateways', ['code' => 'square_online']) : null;
            $creds = json_decode((string) ($row['credentials'] ?? '{}'), true) ?: [];
            if ($this->accessToken === '' && !empty($creds['access_token'])) {
                $this->accessToken = trim((string) $creds['access_token']);
            }
            if ($this->applicationId === '' && !empty($creds['application_id'])) {
                $this->applicationId = trim((string) $creds['application_id']);
            }
            if ($this->locationId === '' && !empty($creds['location_id'])) {
                $this->locationId = trim((string) $creds['location_id']);
            }
        } catch (\Throwable $e) {
        }
        $envHint = strtolower(trim((string) (getenv('SQUARE2_ENVIRONMENT') ?: 'production')));
        $this->sandbox = $this->detectSandbox(['environment' => $envHint], $envHint);
        $this->baseUrl = 'https://connect.squareup.com';
    }

    /* ============================================================
     *                    ENVIRONMENT DETECTION
     * ============================================================ */

    private function detectSandbox(array $creds, string $envHint): bool
    {
        if (function_exists('square_credentials_are_live')) {
            return !square_credentials_are_live($this->applicationId, $this->accessToken, $envHint);
        }

        if (in_array($envHint, ['production', 'live'], true)) {
            return false;
        }

        if (str_starts_with($this->accessToken, 'sandbox-') || str_starts_with($this->accessToken, 'EAAA')) {
            // token الـ sandbox يبدأ بـ sandbox- أو بـ EAAA للـ sandbox
            // token الـ live يبدأ بـ EAAAl (l للـ live)
            return str_starts_with($this->accessToken, 'sandbox-');
        }

        // لا يمكن تحديد البيئة — نرفع استثناء بدلاً من افتراض sandbox الذي يُعطّل العمليات
        throw new \RuntimeException(
            '[SquareAdapter] Cannot determine environment. '
            . 'Set SQUARE_ENVIRONMENT=production explicitly in your .env file.'
        );
    }

    /* ============================================================
     *                    IDENTITY / CAPABILITIES
     * ============================================================ */

    public function getName(): string
    {
        return $this->profile;
    }

    /** العمليات والأوضاع — Factory::process يمرّر 2D/MOTO/OFFLINE على charge */
    public function supports(string $operation): bool
    {
        $operation = strtoupper($operation);
        if (in_array($operation, self::SUPPORTED_OPS, true)) {
            return true;
        }
        return in_array($operation, ['2D', '3D', 'MOTO', 'OFFLINE', 'ADVICE'], true);
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
        return [
            'CHARGE'  => self::CODE_SUPPORTED_MODES,
            'HOLD'    => self::CODE_SUPPORTED_MODES,
            'CAPTURE' => self::CODE_SUPPORTED_MODES,
            'CANCEL'  => self::CODE_SUPPORTED_MODES,
            'REFUND'  => self::CODE_SUPPORTED_MODES,
        ];
    }

    public function supportedCurrencies(): array
    {
        return self::ALLOWED_CURRENCIES;
    }

    public function capabilitiesSnapshot(): array
    {
        return [
            'gateway'        => $this->getName(),
            'operations'     => $this->supportedOperations(),
            'modes'          => $this->supportedModes(),
            'enabled'        => $this->enabledModes(),
            'currencies'     => $this->supportedCurrencies(),
            'matrix'         => $this->operationModeMatrix(),
            'sandbox'        => $this->sandbox,
            'max_txn_usd'    => self::MAX_TXN_USD,
        ];
    }

    public function getApplicationId(): string { return $this->applicationId; }
    public function getLocationId(): string    { return $this->locationId; }
    public function isSandbox(): bool          { return $this->sandbox; }

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

        $raw = hash_hmac(
            'sha256',
            'sq|' . $reference . '|' . number_format($amount, 2, '.', ''),
            $key,
            true
        );

        // Square max 45 chars. base64url → ~43 chars، توزيع ممتاز
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /* ============================================================
     *                       ERROR MAPPING
     * ============================================================ */

    public function normalizeError(array $rawResponse): string
    {
        return GatewayErrorMapper::fromSquare($rawResponse);
    }

    /* ============================================================
     *                       OPERATIONS
     * ============================================================ */

    public function charge(array $payload): array
    {
        return $this->createPayment($payload, true);
    }

    public function hold(array $payload): array
    {
        $out = $this->createPayment($payload, false);
        if (!empty($out['success'])) {
            $out['status']  = 'authorized';
            $out['message'] = 'Square hold authorized — capture required';
        }
        return $out;
    }

    public function capture(string $transactionId, ?float $amount = null): array
    {
        if ($this->sandbox) {
            throw new \RuntimeException(
                'SquareAdapter is running in sandbox mode. Set SQUARE_ENVIRONMENT=production in .env.'
            );
        }
        if ($this->accessToken === '') {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $transactionId, 0, 'USD', 'SQUARE_ACCESS_TOKEN missing');
        }

        $body = [];
        if ($amount !== null && $amount < 0) {
            $amount = abs($amount);
        }
        if ($amount !== null && $amount > 0) {
            $body['amount_money'] = [
                'amount'   => $this->toMinorUnits($amount),
                'currency' => 'USD',
            ];
        }

        $start = microtime(true);
        $res     = $this->request('POST', '/v2/payments/' . rawurlencode($transactionId) . '/complete', $body ?: new \stdClass());
        $payment = is_array($res['payment'] ?? null) ? $res['payment'] : $res;
        $status  = strtoupper((string) ($payment['status'] ?? ''));

        if ($status === 'COMPLETED') {
            $result = [
                'success'        => true,
                'status'         => 'completed',
                'transaction_id' => (string) ($payment['id'] ?? $transactionId),
                'reference'      => (string) ($payment['reference_id'] ?? $transactionId),
                'amount'         => isset($payment['amount_money']['amount'])
                    ? ((float) $payment['amount_money']['amount'] / 100)
                    : ($amount ?? 0),
                'currency'       => strtoupper((string) ($payment['amount_money']['currency'] ?? 'USD')),
                'approval_code'  => (string) ($payment['card_details']['auth_result_code'] ?? ''),
                'rrn'            => (string) ($payment['id'] ?? $transactionId),
                'card_last4'     => $this->cardLast4($payment),
                'message'        => 'Square capture completed',
                'requires_3ds'   => false,
                'raw'            => $res,
            ];
            GatewayLogger::log('square', 'capture', ['transaction_id' => $transactionId], $result, '', microtime(true) - $start);
            return $result;
        }

        GatewayLogger::log('square', 'capture', ['transaction_id' => $transactionId], $res, $this->normalizeError($res), microtime(true) - $start);
        return $this->declineFromSquare($res, $transactionId, $amount ?? 0, 'USD');
    }

    public function cancel(string $transactionId, string $reason = 'requested_by_customer'): array
    {
        if ($this->sandbox) {
            throw new \RuntimeException(
                'SquareAdapter is running in sandbox mode. Set SQUARE_ENVIRONMENT=production in .env.'
            );
        }
        if ($this->accessToken === '') {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $transactionId, 0, 'USD', 'SQUARE_ACCESS_TOKEN missing');
        }

        $start = microtime(true);
        $res    = $this->request('POST', '/v2/payments/' . rawurlencode($transactionId) . '/cancel', new \stdClass());
        $payment = is_array($res['payment'] ?? null) ? $res['payment'] : $res;
        $status  = strtoupper((string) ($payment['status'] ?? ''));

        if (in_array($status, ['CANCELED', 'CANCELLED'], true)) {
            $result = [
                'success'        => true,
                'status'         => 'cancelled',
                'transaction_id' => (string) ($payment['id'] ?? $transactionId),
                'reference'      => $transactionId,
                'message'        => 'Square payment cancelled',
                'raw'            => $res,
            ];
            GatewayLogger::log('square', 'cancel', ['transaction_id' => $transactionId, 'reason' => $reason], $result, '', microtime(true) - $start);
            return $result;
        }

        GatewayLogger::log('square', 'cancel', ['transaction_id' => $transactionId], $res, $this->normalizeError($res), microtime(true) - $start);
        return $this->declineFromSquare($res, $transactionId, 0, 'USD');
    }

    public function refund(string $transactionId, ?float $amount = null, string $currency = 'USD'): array
    {
        if ($this->sandbox) {
            throw new \RuntimeException(
                'SquareAdapter is running in sandbox mode. Set SQUARE_ENVIRONMENT=production in .env.'
            );
        }
        if ($this->accessToken === '') {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $transactionId, $amount ?? 0, $currency, 'SQUARE_ACCESS_TOKEN missing');
        }
        if ($transactionId === '') {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', '', $amount ?? 0, $currency, 'Square payment id is required for refund');
        }
        if ($amount === null || $amount <= 0) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $transactionId, 0, $currency, 'Square refund amount must be greater than 0');
        }

        $currency = strtoupper($currency !== '' ? $currency : 'USD');
        if (!in_array($currency, self::ALLOWED_CURRENCIES, true)) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $transactionId, $amount, $currency, "Unsupported currency: {$currency}");
        }

        $body = [
            'idempotency_key' => $this->buildIdempotencyKey('refund|' . $transactionId, $amount),
            'payment_id'      => $transactionId,
            'amount_money'    => [
                'amount'   => $this->toMinorUnits($amount),
                'currency' => $currency,
            ],
            'reason' => 'requested_by_customer',
        ];

        $start  = microtime(true);
        $res    = $this->request('POST', '/v2/refunds', $body);
        $refund = is_array($res['refund'] ?? null) ? $res['refund'] : [];
        $status = strtoupper((string) ($refund['status'] ?? ''));

        if (in_array($status, ['PENDING', 'COMPLETED'], true)) {
            $result = [
                'success'        => true,
                'status'         => $status === 'PENDING' ? 'pending' : 'refunded',
                'transaction_id' => (string) ($refund['id'] ?? $transactionId),
                'reference'      => $transactionId,
                'amount'         => isset($refund['amount_money']['amount'])
                    ? ((float) $refund['amount_money']['amount'] / 100)
                    : $amount,
                'currency'       => strtoupper((string) ($refund['amount_money']['currency'] ?? $currency)),
                'message'        => 'Square refund submitted',
                'requires_3ds'   => false,
                'raw'            => $res,
            ];
            GatewayLogger::log('square', 'refund', ['transaction_id' => $transactionId, 'amount' => $amount], $result, '', microtime(true) - $start);
            return $result;
        }

        GatewayLogger::log('square', 'refund', ['transaction_id' => $transactionId], $res, $this->normalizeError($res), microtime(true) - $start);
        return $this->declineFromSquare($res, $transactionId, $amount, $currency);
    }

    /* ============================================================
     *                    MODE HANDLING
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
        if ($mode === 'OFFLINE' || $txn === 'offline_sale_moto' || $channel === 'offline' || !empty($payload['is_offline'])) {
            return 'OFFLINE';
        }
        if ($mode === 'MOTO' || !empty($payload['is_moto']) || $txn === 'online_sale_moto' || $channel === 'online') {
            return 'MOTO';
        }
        return $mode !== '' ? $mode : '2D';
    }

    private function assertModeCompatible(string $operation, string $mode): void
    {
        if (!$this->supportsMode($mode)) {
            throw new \InvalidArgumentException(
                "Unsupported mode '{$mode}'. Square supports: " . implode(', ', self::CODE_SUPPORTED_MODES)
            );
        }

        if (!$this->isModeEnabled($mode)) {
            throw new \InvalidArgumentException(
                "Mode '{$mode}' is not enabled for this Square account"
            );
        }

        $allowed = $this->operationModeMatrix()[$operation] ?? [];
        if (!in_array($mode, $allowed, true)) {
            throw new \InvalidArgumentException(
                "Mode {$mode} is not compatible with operation {$operation}"
            );
        }
    }

    private function applyModeToPayload(array $payload, string $mode): array
    {
        if ($mode === '3D') {
            $verification = trim((string) (
                $payload['verification_token']
                ?? $payload['square_verification']
                ?? ''
            ));
            if ($verification === '') {
                throw new \InvalidArgumentException(
                    '3D mode requires verification_token from Square Web Payments SDK'
                );
            }
            $payload['verification_token'] = $verification;
        }

        if ($mode === 'MOTO' || $mode === 'OFFLINE') {
            $payload['_moto'] = true;
            $payload['_offline'] = ($mode === 'OFFLINE');
        }

        $payload['_mode'] = $mode;
        return $payload;
    }

    /* ============================================================
     *                    CORE PAYMENT
     * ============================================================ */

    private function createPayment(array $payload, bool $autocomplete): array
    {
        $reference = (string) ($payload['reference'] ?? uniqid('sq_', true));
        $amount    = (float)  ($payload['amount']    ?? 0);
        $currency  = strtoupper((string) ($payload['currency'] ?? 'USD'));
        $operation = $autocomplete ? 'CHARGE' : 'HOLD';
        $signedKind = strtolower((string) ($payload['txn_type'] ?? ''));
        if (in_array($signedKind, ['purchase_advice', 'offline_sale_moto', 'capture'], true) && $amount < 0) {
            // /v2/refunds requires a payment_id, so there is no unlinked credit here.
            // A positive CreatePayment is sent with note MINUS. A Square error is a decline.
            $payload['advice_sign'] = 'minus';
            $amount = abs($amount);
            $payload['amount'] = $amount;
        }

        // 1. فحص الوضع
        $mode = $this->resolveMode($payload);
        try {
            $this->assertModeCompatible($operation, $mode);
            $payload = $this->applyModeToPayload($payload, $mode);
        } catch (\InvalidArgumentException $e) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $reference, $amount, $currency, $e->getMessage());
        }

        // 2. sandbox — رفض صريح بدل استجابة وهمية
        if ($this->sandbox) {
            throw new \RuntimeException(
                'SquareAdapter is running in sandbox mode. Set SQUARE_ENVIRONMENT=production in .env.'
            );
        }

        // 3. فحوصات أساسية
        if ($this->accessToken === '') {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $reference, $amount, $currency, 'SQUARE_ACCESS_TOKEN missing');
        }
        if ($amount <= 0) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $reference, $amount, $currency, 'Invalid amount');
        }
        if (!in_array($currency, self::ALLOWED_CURRENCIES, true)) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $reference, $amount, $currency, "Unsupported currency: {$currency}");
        }

        // 4. فحص حد المبلغ
        $capCode = $this->profile === 'square_online' ? 'square_online' : 'square';
        $cap = (float) (function_exists('gateway_max_per_txn_usd') ? (gateway_max_per_txn_usd($capCode) ?? self::MAX_TXN_USD) : self::MAX_TXN_USD);
        if (in_array($currency, ['USD', 'USDT', 'USDC'], true) && $amount > $cap) {
            return GatewayErrorMapper::buildErrorResponse(
                'GATEWAY_ERROR', $reference, $amount, $currency,
                'Square limit is ' . number_format($cap, 2, '.', ',') . ' USD per transaction.'
            );
        }

        // 5. source_id
        $sourceId = trim((string) (
            $payload['source_id']
            ?? $payload['cloud_token']
            ?? $payload['payment_token']
            ?? $payload['payment_method']
            ?? ''
        ));
        if ($sourceId === '' || (function_exists('square_is_simulation_nonce') && square_is_simulation_nonce($sourceId))) {
            return GatewayErrorMapper::buildErrorResponse(
                'INVALID_CARD', $reference, $amount, $currency,
                'Square requires a real card nonce from Web Payments SDK. Pass cloud_token / source_id.'
            );
        }

        // 6. location
        $locationId = $this->resolveLocationId();
        if ($locationId === '') {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $reference, $amount, $currency, 'SQUARE_LOCATION_ID missing');
        }

        // 7. تطابق العملة مع الـ location
        $chargeCurrency = $currency;
        $locCurrency    = $this->locationCurrency($locationId);
        if ($locCurrency === '') {
            $locCurrency = 'USD';
        }
        if ($chargeCurrency !== $locCurrency) {
            if (in_array($chargeCurrency, ['USDT', 'USDC', 'USD'], true) && $locCurrency === 'USD') {
                $chargeCurrency = 'USD';
            } else {
                return GatewayErrorMapper::buildErrorResponse(
                    'GATEWAY_ERROR', $reference, $amount, $currency,
                    "Square location currency is {$locCurrency}; request sent {$currency}"
                );
            }
        }

        // 8. بناء الطلب
        $body = [
            'source_id'       => $sourceId,
            'idempotency_key' => $this->buildIdempotencyKey($reference . ($autocomplete ? 'c' : 'h'), (float) $amount),
            'amount_money'    => [
                'amount'   => $this->toMinorUnits((float) $amount),
                'currency' => $chargeCurrency,
            ],
            'autocomplete'  => $autocomplete,
            'location_id'   => $locationId,
            'reference_id'  => substr(preg_replace('/[^A-Za-z0-9:_-]/', '', $reference) ?: ('sq' . date('YmdHis')), 0, 40),
            'note'          => trim('DIPARMA ' . preg_replace('/[^A-Za-z0-9 _-]/', '', (string) ($payload['txn_type'] ?? 'sale'))
                . ' ' . strtoupper((string) ($payload['_mode'] ?? ''))
                . (($payload['advice_sign'] ?? '') === 'minus' ? ' MINUS' : '')),
        ];

        if (!empty($payload['verification_token'])) {
            $body['verification_token'] = $payload['verification_token'];
        }
        $email = trim((string) ($payload['email'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $body['buyer_email_address'] = $email;
        }

        // 9. تنفيذ الطلب
        $start = microtime(true);
        try {
            $res     = $this->request('POST', '/v2/payments', $body);
            $payment = is_array($res['payment'] ?? null) ? $res['payment'] : [];
            $status  = strtoupper((string) ($payment['status'] ?? ''));
            $duration = microtime(true) - $start;

            if (in_array($status, ['COMPLETED', 'APPROVED', 'AUTHORIZED'], true)) {
                $result = [
                    'success'        => true,
                    'status'         => $autocomplete ? 'completed' : 'authorized',
                    'transaction_id' => (string) ($payment['id'] ?? ''),
                    'reference'      => $reference,
                    'amount'         => (float) $amount,
                    'currency'       => $currency,
                    'approval_code'  => (string) ($payment['card_details']['auth_result_code'] ?? ''),
                    'rrn'            => (string) ($payment['id'] ?? $reference),
                    'card_last4'     => $this->cardLast4($payment),
                    'message'        => $autocomplete ? 'Square payment approved' : 'Square payment authorized',
                    'requires_3ds'   => false,
                    'redirect_url'   => '',
                    'error_code'     => '',
                    'raw'            => $res,
                ];
                GatewayLogger::log('square', $autocomplete ? 'charge' : 'hold', $this->sanitizeForLog($payload), $result, '', $duration);
                return $result;
            }

            GatewayLogger::log('square', $autocomplete ? 'charge' : 'hold', $this->sanitizeForLog($payload), $res, $this->normalizeError($res), $duration);
            $out = $this->declineFromSquare($res, $reference, (float) $amount, $currency);
            $out['transaction_id'] = (string) ($payment['id'] ?? '');
            $out['approval_code']  = (string) ($payment['card_details']['auth_result_code'] ?? '');
            $out['card_last4']     = $this->cardLast4($payment);
            return $out;

        } catch (\Throwable $e) {
            GatewayLogger::log('square', $autocomplete ? 'charge' : 'hold', $this->sanitizeForLog($payload), ['exception' => $e->getMessage()], 'NETWORK_ERROR', microtime(true) - $start);
            return GatewayErrorMapper::buildErrorResponse('NETWORK_ERROR', $reference, (float) $amount, $currency, $e->getMessage());
        }
    }

    /* ============================================================
     *                    HELPERS
     * ============================================================ */

    /** تحويل المبلغ لأصغر وحدة (سنت) بدقة صحيحة */
    private function toMinorUnits(float $amount): int
    {
        return (int) round($amount * 100, 0, PHP_ROUND_HALF_UP);
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

    private function sandboxError(string $reference, float $amount, string $currency): array
    {
        return GatewayErrorMapper::buildErrorResponse(
            'GATEWAY_ERROR', $reference, $amount, $currency,
            'Square sandbox is rejected. Use production credentials with SQUARE_ENVIRONMENT=production.'
        );
    }

    public function resolveLocationId(): string
    {
        if ($this->locationId !== '') {
            return $this->locationId;
        }
        $res  = $this->request('GET', '/v2/locations');
        $locs = $res['locations'] ?? [];
        foreach ($locs as $loc) {
            if (($loc['status'] ?? '') === 'ACTIVE' && !empty($loc['id'])) {
                $this->locationId = (string) $loc['id'];
                return $this->locationId;
            }
        }
        if (!empty($locs[0]['id'])) {
            $this->locationId = (string) $locs[0]['id'];
        }
        return $this->locationId;
    }

    /** مع cache لتجنّب طلب HTTP إضافي في كل عملية */
    private function locationCurrency(string $locationId): string
    {
        if ($this->cachedLocationCurrency !== null) {
            return $this->cachedLocationCurrency;
        }

        $cacheKey = 'square_loc_currency_' . md5($locationId);
        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch($cacheKey);
            if ($cached !== false) {
                return $this->cachedLocationCurrency = (string) $cached;
            }
        }

        $res      = $this->request('GET', '/v2/locations/' . rawurlencode($locationId));
        $currency = strtoupper((string) ($res['location']['currency'] ?? ''));

        if ($currency !== '' && function_exists('apcu_store')) {
            apcu_store($cacheKey, $currency, 3600);
        }

        return $this->cachedLocationCurrency = $currency;
    }

    private function cardLast4(array $payment): string
    {
        $last = (string) (
            $payment['card_details']['card']['last_4']
            ?? $payment['card_details']['card']['last4']
            ?? ''
        );
        $last = preg_replace('/\D+/', '', $last) ?? '';
        return strlen($last) >= 4 ? substr($last, -4) : $last;
    }

    /* ============================================================
     *                    ERROR HELPERS
     * ============================================================ */

    private function declineFromSquare(array $res, string $reference, float $amount, string $currency): array
    {
        $unified = $this->normalizeError($res);
        $parts   = $this->errorParts($res);
        $line    = $this->formatErrorLine($parts['code'], $parts['detail']);
        if ($line === '') {
            $line = $this->errorMessage($res);
        }

        $out = GatewayErrorMapper::buildErrorResponse($unified, $reference, $amount, $currency, $line);
        $out['error_code']          = $parts['code'] !== '' ? $parts['code'] : $unified;
        $out['raw_message']         = $line;
        $out['message']             = $line;
        $out['square_error_code']   = $parts['code'];
        $out['square_error_detail'] = $parts['detail'];
        $out['host_errors']         = array_values(array_filter([
            $parts['code'] !== '' || $parts['detail'] !== ''
                ? ['code' => $parts['code'], 'detail' => $parts['detail'], 'category' => $parts['category']]
                : null,
        ]));
        $out['raw'] = $res;
        return $out;
    }

    /** @return array{code:string,detail:string,category:string} */
    private function errorParts(array $res): array
    {
        $payment    = is_array($res['payment']   ?? null) ? $res['payment']   : [];
        $cardDetails = is_array($payment['card_details'] ?? null) ? $payment['card_details'] : [];
        $cardErr    = is_array($cardDetails['errors'][0] ?? null) ? $cardDetails['errors'][0] : [];
        $apiErr     = is_array($res['errors'][0] ?? null) ? $res['errors'][0] : [];
        return [
            'code'     => trim((string) ($apiErr['code']     ?? $cardErr['code']     ?? '')),
            'detail'   => trim((string) ($apiErr['detail']   ?? $cardErr['detail']   ?? '')),
            'category' => trim((string) ($apiErr['category'] ?? $cardErr['category'] ?? '')),
        ];
    }

    private function formatErrorLine(string $code, string $detail): string
    {
        $code   = trim($code);
        $detail = trim($detail);
        if ($code !== '' && $detail !== '') {
            return stripos($detail, $code) !== false ? $detail : "{$code} — {$detail}";
        }
        return $detail !== '' ? $detail : $code;
    }

    private function errorMessage(array $res): string
    {
        $parts = $this->errorParts($res);
        $line  = $this->formatErrorLine($parts['code'], $parts['detail']);
        if ($line !== '') {
            return $line;
        }
        $payment = is_array($res['payment'] ?? null) ? $res['payment'] : [];
        $status  = strtoupper((string) ($payment['status'] ?? ''));
        if ($status === 'FAILED') {
            return 'SQUARE_FAILED';
        }
        return trim((string) ($res['message'] ?? '')) ?: 'Square error';
    }

    /* ============================================================
     *                    HTTP — مع retry + SSL
     * ============================================================ */

    private function request(string $method, string $path, mixed $body = null, int $attempt = 1): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        $ch  = curl_init($url);

        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json',
            'Accept: application/json',
            'Square-Version: 2024-01-18',
        ];

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => self::CURL_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CURL_CONNECT_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        ];

        if ($body !== null && strtoupper($method) !== 'GET') {
            $opts[CURLOPT_POSTFIELDS] = is_string($body)
                ? $body
                : json_encode($body, JSON_UNESCAPED_UNICODE);
        }

        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        // Retry على أخطاء الشبكة
        if ($raw === false) {
            if ($attempt < self::MAX_RETRIES) {
                usleep(500_000 * $attempt);
                return $this->request($method, $path, $body, $attempt + 1);
            }
            return ['errors' => [['code' => 'NETWORK', 'detail' => $err ?: 'curl failed']], 'http_code' => $code];
        }

        // Retry على 429 / 5xx — آمن لأن كل طلب له idempotency key
        if (in_array($code, [429, 500, 502, 503, 504], true) && $attempt < self::MAX_RETRIES) {
            usleep(1_000_000 * $attempt);
            return $this->request($method, $path, $body, $attempt + 1);
        }

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            return ['errors' => [['code' => 'PARSE', 'detail' => 'Invalid JSON']], 'http_code' => $code, 'raw' => $raw];
        }

        $data['http_code'] = $code;
        return $data;
    }
}
