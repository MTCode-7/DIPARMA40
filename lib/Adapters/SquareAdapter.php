<?php
/**
 * DI PARMA | SquareAdapter
 * Square Payments API: charge / hold / capture / cancel
 * Docs: https://developer.squareup.com
 *
 * Card data: require source_id / cloud_token from Web Payments SDK (live API).
 * Square Offline: Square POS/hardware store-and-forward only (24h take,
 * 72h upload from first payment, 50k USD). Pending is viewable only in
 * Square POS apps. Keyed PAN is unsupported offline. This Payments API
 * adapter is live connect.squareup.com only.
 * https://squareup.com/help/us/en/article/7777-process-card-payments-with-offline-mode
 * https://squareup.com/help/us/en/article/8551-view-offline-payments
 */
require_once __DIR__ . '/GatewayAdapterInterface.php';
require_once __DIR__ . '/GatewayErrorMapper.php';
require_once __DIR__ . '/GatewayLogger.php';

class SquareAdapter implements GatewayAdapterInterface
{
    private string $accessToken;
    private string $applicationId;
    private string $locationId;
    private string $baseUrl;
    private bool $sandbox;

    public function __construct()
    {
        $sdkFile = dirname(__DIR__, 2) . '/includes/square_sdk.php';
        if (is_file($sdkFile)) {
            require_once $sdkFile;
        }
        $creds = function_exists('square_runtime_credentials') ? square_runtime_credentials() : [];
        $this->accessToken = trim((string) ($creds['access_token'] ?? getenv('SQUARE_ACCESS_TOKEN') ?: getenv('SQUARE_SECRET_KEY') ?: ''));
        $this->applicationId = trim((string) ($creds['application_id'] ?? getenv('SQUARE_APPLICATION_ID') ?: getenv('SQUARE_API_KEY') ?: ''));
        $this->locationId = trim((string) ($creds['location_id'] ?? getenv('SQUARE_LOCATION_ID') ?: ''));
        $envHint = strtolower(trim((string) ($creds['environment'] ?? getenv('SQUARE_ENVIRONMENT') ?: 'production')));
        if (function_exists('square_credentials_are_live')) {
            $this->sandbox = !square_credentials_are_live($this->applicationId, $this->accessToken, $envHint);
        } else {
            $this->sandbox = empty($creds['live']);
        }
        $this->baseUrl = 'https://connect.squareup.com';
    }

    public function getName(): string
    {
        return 'square';
    }

    public function supports(string $mode): bool
    {
        return in_array(strtoupper($mode), ['2D', '3D', 'HOLD', 'CAPTURE', 'CANCEL', 'REFUND', 'OFFLINE', 'MOTO', 'ADVICE'], true);
    }

    public function normalizeError(array $rawResponse): string
    {
        return GatewayErrorMapper::fromSquare($rawResponse);
    }

    public function buildIdempotencyKey(string $reference, float $amount): string
    {
        return substr(hash('sha256', 'sq|' . $reference . '|' . $amount . '|' . (getenv('ENCRYPTION_KEY') ?: 'diparma')), 0, 45);
    }

    public function getApplicationId(): string
    {
        return $this->applicationId;
    }

    public function getLocationId(): string
    {
        return $this->locationId;
    }

    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    public function charge(array $payload): array
    {
        return $this->createPayment($payload, true);
    }

    public function hold(array $payload): array
    {
        $out = $this->createPayment($payload, false);
        if (!empty($out['success'])) {
            $out['status'] = 'authorized';
            $out['message'] = 'Square hold authorized — not captured yet';
        }
        return $out;
    }

    public function capture(string $transactionId, ?float $amount = null): array
    {
        if ($this->accessToken === '') {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $transactionId, 0, 'USD', 'SQUARE_ACCESS_TOKEN missing');
        }
        $body = new stdClass();
        if ($amount !== null && $amount > 0) {
            $body = [
                'amount_money' => [
                    'amount' => (int) round($amount * 100),
                    'currency' => 'USD',
                ],
            ];
        }
        $res = $this->request('POST', '/v2/payments/' . rawurlencode($transactionId) . '/complete', $body === null || $body instanceof stdClass ? new stdClass() : $body);
        $payment = $res['payment'] ?? $res;
        $status = strtoupper((string) ($payment['status'] ?? ''));
        if ($status === 'COMPLETED') {
            return [
                'success' => true,
                'status' => 'completed',
                'transaction_id' => $payment['id'] ?? $transactionId,
                'reference' => $payment['reference_id'] ?? $transactionId,
                'amount' => isset($payment['amount_money']['amount']) ? ((float) $payment['amount_money']['amount'] / 100) : ($amount ?? 0),
                'currency' => strtoupper((string) ($payment['amount_money']['currency'] ?? 'USD')),
                'approval_code' => $payment['card_details']['auth_result_code'] ?? '',
                'rrn' => $payment['id'] ?? $transactionId,
                'message' => 'Square capture completed',
                'requires_3ds' => false,
                'raw' => $res,
            ];
        }
        return $this->declineFromSquare(
            $res,
            $transactionId,
            $amount ?? 0,
            'USD'
        );
    }

    public function cancel(string $transactionId, string $reason = 'requested_by_customer'): array
    {
        if ($this->accessToken === '') {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $transactionId, 0, 'USD', 'SQUARE_ACCESS_TOKEN missing');
        }
        $res = $this->request('POST', '/v2/payments/' . rawurlencode($transactionId) . '/cancel', new stdClass());
        $payment = $res['payment'] ?? $res;
        $status = strtoupper((string) ($payment['status'] ?? ''));
        if (in_array($status, ['CANCELED', 'CANCELLED'], true)) {
            return [
                'success' => true,
                'status' => 'cancelled',
                'transaction_id' => $payment['id'] ?? $transactionId,
                'reference' => $transactionId,
                'message' => 'Square payment cancelled',
                'raw' => $res,
            ];
        }
        $out = $this->declineFromSquare($res, $transactionId, 0, 'USD');
        if (trim((string) ($out['raw_message'] ?? '')) === '' && $reason !== '') {
            $out['raw_message'] = $reason;
            $out['message'] = $reason;
        }
        return $out;
    }

    public function refund(string $transactionId, ?float $amount = null, string $currency = 'USD'): array
    {
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
        $body = [
            'idempotency_key' => $this->buildIdempotencyKey('refund|' . $transactionId, $amount),
            'payment_id' => $transactionId,
            'amount_money' => [
                'amount' => (int) round($amount * 100),
                'currency' => $currency,
            ],
            'reason' => 'requested_by_customer',
        ];
        $res = $this->request('POST', '/v2/refunds', $body);
        $refund = is_array($res['refund'] ?? null) ? $res['refund'] : [];
        $status = strtoupper((string) ($refund['status'] ?? ''));
        if (in_array($status, ['PENDING', 'COMPLETED'], true)) {
            return [
                'success' => true,
                'status' => $status === 'PENDING' ? 'pending' : 'refunded',
                'transaction_id' => (string) ($refund['id'] ?? $transactionId),
                'reference' => $transactionId,
                'amount' => isset($refund['amount_money']['amount']) ? ((float) $refund['amount_money']['amount'] / 100) : $amount,
                'currency' => strtoupper((string) ($refund['amount_money']['currency'] ?? $currency)),
                'message' => 'Square refund submitted',
                'requires_3ds' => false,
                'raw' => $res,
            ];
        }
        return $this->declineFromSquare($res, $transactionId, $amount, $currency);
    }

    /** Resolve Square location if env empty. */
    public function resolveLocationId(): string
    {
        if ($this->locationId !== '') {
            return $this->locationId;
        }
        $res = $this->request('GET', '/v2/locations');
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

    private function createPayment(array $payload, bool $autocomplete): array
    {
        $reference = (string) ($payload['reference'] ?? uniqid('sq_', true));
        $amount = (float) ($payload['amount'] ?? 0);
        $currency = strtoupper((string) ($payload['currency'] ?? 'USD'));
        if ($this->sandbox) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $reference, $amount, $currency, 'Square sandbox is rejected. Use production keys.');
        }
        if ($this->accessToken === '') {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $reference, $amount, $currency, 'SQUARE_ACCESS_TOKEN missing');
        }
        if ($amount <= 0) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $reference, $amount, $currency, 'Invalid amount');
        }
        if (!function_exists('square_request_is_diparma_offline')) {
            $gwFile = dirname(__DIR__, 2) . '/includes/gateways.php';
            if (is_file($gwFile)) {
                require_once $gwFile;
            }
        }
        if (function_exists('square_request_is_diparma_offline') && square_request_is_diparma_offline($payload)) {
            $msg = function_exists('square_offline_device_only_message')
                ? square_offline_device_only_message()
                : 'Square Offline is only on Square POS hardware. DIPARMA cannot store keyed cards offline.';
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $reference, $amount, $currency, $msg);
        }
        $squareCap = function_exists('gateway_max_per_txn_usd') ? gateway_max_per_txn_usd('square') : 50000.00;
        if ($squareCap === null) {
            $squareCap = 50000.00;
        }
        if (in_array($currency, ['USD', 'USDT', 'USDC'], true) && $amount > $squareCap) {
            return GatewayErrorMapper::buildErrorResponse(
                'GATEWAY_ERROR',
                $reference,
                $amount,
                $currency,
                'Square limit is ' . number_format($squareCap, 2, '.', ',') . ' USD per transaction, including offline.'
            );
        }

        $sourceId = trim((string) (
            $payload['source_id']
            ?? $payload['cloud_token']
            ?? $payload['payment_token']
            ?? $payload['payment_method']
            ?? ''
        ));
        if ($sourceId === '' || strncasecmp($sourceId, 'cnon:card-nonce', 15) === 0) {
            return GatewayErrorMapper::buildErrorResponse(
                'INVALID_CARD',
                $reference,
                $amount,
                $currency,
                'Square requires a real card nonce from Web Payments SDK. Pass cloud_token / source_id.'
            );
        }

        $locationId = $this->resolveLocationId();
        if ($locationId === '') {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $reference, $amount, $currency, 'SQUARE_LOCATION_ID missing');
        }

        $start = microtime(true);
        $chargeCurrency = $currency;
        $chargeAmount = $amount;
        $locCurrency = $this->locationCurrency($locationId);
        if ($locCurrency === '') {
            $locCurrency = 'USD';
        }
        if ($chargeCurrency !== $locCurrency) {
            if (in_array($chargeCurrency, ['USDT', 'USDC', 'USD'], true) && $locCurrency === 'USD') {
                $chargeCurrency = 'USD';
            } else {
                return GatewayErrorMapper::buildErrorResponse(
                    'GATEWAY_ERROR',
                    $reference,
                    $amount,
                    $currency,
                    'Square location currency is ' . $locCurrency . '; POS sent ' . $currency
                );
            }
        }

        $body = [
            'source_id' => $sourceId,
            'idempotency_key' => $this->buildIdempotencyKey($reference . ($autocomplete ? 'c' : 'h'), $chargeAmount),
            'amount_money' => [
                'amount' => (int) round($chargeAmount * 100),
                'currency' => $chargeCurrency,
            ],
            'autocomplete' => $autocomplete,
            'location_id' => $locationId,
            'reference_id' => substr(preg_replace('/[^A-Za-z0-9:_-]/', '', $reference) ?: ('sq' . date('YmdHis')), 0, 40),
            'note' => 'DIPARMA ' . preg_replace('/[^A-Za-z0-9 _-]/', '', (string) ($payload['txn_type'] ?? 'sale')),
        ];
        $verification = trim((string) ($payload['verification_token'] ?? $payload['square_verification'] ?? ''));
        if ($verification !== '') {
            $body['verification_token'] = $verification;
        }
        $email = trim((string) ($payload['email'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $body['buyer_email_address'] = $email;
        }

        try {
            $res = $this->request('POST', '/v2/payments', $body);
            $payment = $res['payment'] ?? [];
            $status = strtoupper((string) ($payment['status'] ?? ''));
            $duration = microtime(true) - $start;

            if (in_array($status, ['COMPLETED', 'APPROVED', 'AUTHORIZED'], true)) {
                $auth = (string) ($payment['card_details']['auth_result_code'] ?? '');
                $result = [
                    'success' => true,
                    'status' => $autocomplete ? 'completed' : 'authorized',
                    'transaction_id' => $payment['id'] ?? '',
                    'reference' => $reference,
                    'amount' => $amount,
                    'currency' => $currency,
                    'approval_code' => $auth,
                    'rrn' => $payment['id'] ?? $reference,
                    'message' => $autocomplete ? 'Square payment approved' : 'Square payment authorized',
                    'requires_3ds' => false,
                    'redirect_url' => '',
                    'error_code' => '',
                    'card_last4' => $this->cardLast4($payment),
                    'raw' => $res,
                ];
                GatewayLogger::log('square', $autocomplete ? 'charge' : 'hold', $payload, $result, '', $duration);
                return $result;
            }

            GatewayLogger::log('square', $autocomplete ? 'charge' : 'hold', $payload, $res, $this->normalizeError($res), $duration);
            $out = $this->declineFromSquare($res, $reference, $amount, $currency);
            $payId = trim((string) ($payment['id'] ?? ''));
            $auth = trim((string) ($payment['card_details']['auth_result_code'] ?? ''));
            $out['transaction_id'] = $payId;
            if ($payId !== '') {
                $out['rrn'] = $payId;
            }
            $out['approval_code'] = $auth;
            $out['card_last4'] = $this->cardLast4($payment);
            return $out;
        } catch (Throwable $e) {
            GatewayLogger::log('square', $autocomplete ? 'charge' : 'hold', $payload, ['exception' => $e->getMessage()], 'NETWORK_ERROR', microtime(true) - $start);
            return GatewayErrorMapper::buildErrorResponse('NETWORK_ERROR', $reference, $amount, $currency, $e->getMessage());
        }
    }

    /**
     * AUTH Hold / charge / capture fail: keep Square's own code+detail.
     * Do not replace GENERIC_DECLINE / CVV_FAILURE with a generic CARD_DECLINED label.
     *
     * @return array<string,mixed>
     */
    private function declineFromSquare(array $res, string $reference, float $amount, string $currency): array
    {
        $unified = $this->normalizeError($res);
        $parts = $this->errorParts($res);
        $line = $this->formatErrorLine($parts['code'], $parts['detail']);
        if ($line === '') {
            $line = $this->errorMessage($res);
        }
        $out = GatewayErrorMapper::buildErrorResponse($unified, $reference, $amount, $currency, $line);
        if ($parts['code'] !== '') {
            $out['error_code'] = $parts['code'];
        }
        $out['raw_message'] = $line;
        $out['message'] = $line;
        $out['square_error_code'] = $parts['code'];
        $out['square_error_detail'] = $parts['detail'];
        $out['host_errors'] = array_values(array_filter([
            $parts['code'] !== '' || $parts['detail'] !== ''
                ? [
                    'code' => $parts['code'],
                    'detail' => $parts['detail'],
                    'category' => $parts['category'],
                ]
                : null,
        ]));
        $out['raw'] = $res;
        return $out;
    }

    /**
     * @return array{code:string,detail:string,category:string}
     */
    private function errorParts(array $res): array
    {
        $payment = is_array($res['payment'] ?? null) ? $res['payment'] : [];
        $cardDetails = is_array($payment['card_details'] ?? null) ? $payment['card_details'] : [];
        $cardErr = is_array($cardDetails['errors'][0] ?? null) ? $cardDetails['errors'][0] : [];
        $apiErr = is_array($res['errors'][0] ?? null) ? $res['errors'][0] : [];
        return [
            'code' => trim((string) ($apiErr['code'] ?? $cardErr['code'] ?? '')),
            'detail' => trim((string) ($apiErr['detail'] ?? $cardErr['detail'] ?? '')),
            'category' => trim((string) ($apiErr['category'] ?? $cardErr['category'] ?? '')),
        ];
    }

    private function formatErrorLine(string $code, string $detail): string
    {
        $code = trim($code);
        $detail = trim($detail);
        if ($code !== '' && $detail !== '') {
            if (stripos($detail, $code) !== false) {
                return $detail;
            }
            return $code . ' — ' . $detail;
        }
        return $detail !== '' ? $detail : $code;
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

    private function errorMessage(array $res): string
    {
        $parts = $this->errorParts($res);
        $line = $this->formatErrorLine($parts['code'], $parts['detail']);
        if ($line !== '') {
            return $line;
        }
        $payment = is_array($res['payment'] ?? null) ? $res['payment'] : [];
        $status = strtoupper((string) ($payment['status'] ?? ''));
        if ($status === 'FAILED') {
            return 'SQUARE_FAILED';
        }
        return trim((string) ($res['message'] ?? '')) ?: 'Square error';
    }

    private function locationCurrency(string $locationId): string
    {
        if ($locationId === '') {
            return '';
        }
        $res = $this->request('GET', '/v2/locations/' . rawurlencode($locationId));
        return strtoupper((string) ($res['location']['currency'] ?? ''));
    }

    private function request(string $method, string $path, $body = null): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json',
            'Accept: application/json',
            'Square-Version: 2024-01-18',
        ];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
        ];
        if ($body !== null && strtoupper($method) !== 'GET') {
            $opts[CURLOPT_POSTFIELDS] = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            return ['errors' => [['code' => 'NETWORK', 'detail' => $err ?: 'curl failed']], 'http_code' => $code];
        }
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            return ['errors' => [['code' => 'PARSE', 'detail' => 'Invalid JSON']], 'http_code' => $code, 'raw' => $raw];
        }
        $data['http_code'] = $code;
        return $data;
    }
}
