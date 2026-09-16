<?php
/**
 * DI PARMA | SquareAdapter
 * Square Payments API: charge / hold / capture / cancel
 * Docs: https://developer.squareup.com
 *
 * Card data: require source_id / cloud_token from Web Payments SDK.
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
        $this->sandbox = empty($creds['live']);
        if ($creds === []) {
            $env = strtolower(trim((string) (getenv('SQUARE_ENVIRONMENT') ?: 'sandbox')));
            $this->sandbox = !in_array($env, ['production', 'live', 'prod'], true);
        }
        $this->baseUrl = $this->sandbox
            ? 'https://connect.squareupsandbox.com'
            : 'https://connect.squareup.com';
    }

    public function getName(): string
    {
        return 'square';
    }

    public function supports(string $mode): bool
    {
        return in_array(strtoupper($mode), ['2D', '3D', 'HOLD', 'CAPTURE', 'CANCEL'], true);
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
        return GatewayErrorMapper::buildErrorResponse(
            $this->normalizeError($res),
            $transactionId,
            $amount ?? 0,
            'USD',
            $this->errorMessage($res)
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
        return GatewayErrorMapper::buildErrorResponse(
            $this->normalizeError($res),
            $transactionId,
            0,
            'USD',
            $this->errorMessage($res) ?: $reason
        );
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
        if ($this->accessToken === '') {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $reference, $amount, $currency, 'SQUARE_ACCESS_TOKEN missing');
        }
        if ($amount <= 0) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $reference, $amount, $currency, 'Invalid amount');
        }

        $sourceId = trim((string) (
            $payload['source_id']
            ?? $payload['cloud_token']
            ?? $payload['payment_token']
            ?? $payload['payment_method']
            ?? ''
        ));
        if ($sourceId === '' || strcasecmp($sourceId, 'cnon:card-nonce-ok') === 0) {
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
        $body = [
            'source_id' => $sourceId,
            'idempotency_key' => $this->buildIdempotencyKey($reference . ($autocomplete ? 'c' : 'h'), $amount),
            'amount_money' => [
                'amount' => (int) round($amount * 100),
                'currency' => $currency,
            ],
            'autocomplete' => $autocomplete,
            'location_id' => $locationId,
            'reference_id' => substr($reference, 0, 40),
            'note' => 'DIPARMA ' . ($payload['txn_type'] ?? 'sale'),
        ];
        if (!empty($payload['email'])) {
            $body['buyer_email_address'] = (string) $payload['email'];
        }

        try {
            $res = $this->request('POST', '/v2/payments', $body);
            $payment = $res['payment'] ?? [];
            $status = strtoupper((string) ($payment['status'] ?? ''));
            $duration = microtime(true) - $start;

            if (in_array($status, ['COMPLETED', 'APPROVED'], true)) {
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
                    'raw' => $res,
                ];
                GatewayLogger::log('square', $autocomplete ? 'charge' : 'hold', $payload, $result, '', $duration);
                return $result;
            }

            $err = $this->normalizeError($res);
            GatewayLogger::log('square', $autocomplete ? 'charge' : 'hold', $payload, $res, $err, $duration);
            return GatewayErrorMapper::buildErrorResponse($err, $reference, $amount, $currency, $this->errorMessage($res));
        } catch (Throwable $e) {
            GatewayLogger::log('square', $autocomplete ? 'charge' : 'hold', $payload, ['exception' => $e->getMessage()], 'NETWORK_ERROR', microtime(true) - $start);
            return GatewayErrorMapper::buildErrorResponse('NETWORK_ERROR', $reference, $amount, $currency, $e->getMessage());
        }
    }

    private function errorMessage(array $res): string
    {
        if (!empty($res['errors'][0]['detail'])) {
            return (string) $res['errors'][0]['detail'];
        }
        if (!empty($res['errors'][0]['code'])) {
            return (string) $res['errors'][0]['code'];
        }
        return (string) ($res['message'] ?? 'Square error');
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
