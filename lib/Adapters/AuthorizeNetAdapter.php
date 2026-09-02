<?php
/**
 * ============================================================
 * DI PARMA | AuthorizeNetAdapter v1
 * بوابة Authorize.Net — تدعم 2D/3D/HOLD/CAPTURE/CANCEL
 * ============================================================
 * 2D: authCaptureTransaction بدون 3DS (deviceType=EC)
 * 3D: authCaptureTransaction مع fullPageZoom
 * HOLD: authOnlyTransaction (لا يخصم)
 * CAPTURE: priorAuthCaptureTransaction
 * CANCEL: voidTransaction
 * ============================================================
 */

require_once __DIR__ . '/GatewayAdapterInterface.php';
require_once __DIR__ . '/GatewayErrorMapper.php';
require_once __DIR__ . '/GatewayLogger.php';

final class AuthorizeNetAdapter implements GatewayAdapterInterface
{
    private string $apiLoginId;
    private string $transactionKey;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiLoginId     = getenv('AUTHNET_API_LOGIN_ID')    ?: '';
        $this->transactionKey = getenv('AUTHNET_TRANSACTION_KEY') ?: '';
        $env                  = getenv('AUTHNET_ENVIRONMENT')      ?: '';
        $this->baseUrl        = $env === 'live'
            ? 'https://api.authorize.net/xml/v1/request.api'
            : 'https://apitest.authorize.net/xml/v1/request.api';
    }

    public function getName(): string { return 'authorizenet'; }

    public function supports(string $mode): bool
    {
        return in_array(strtoupper($mode), ['2D', '3D', 'HOLD', 'CAPTURE', 'CANCEL']);
    }

    public function normalizeError(array $rawResponse): string
    {
        return GatewayErrorMapper::fromAuthorizeNet($rawResponse);
    }

    public function buildIdempotencyKey(string $reference, float $amount): string
    {
        return hash('sha256', 'an_' . $reference . '|' . $amount . '|' . getenv('ENCRYPTION_KEY'));
    }

    // ══════════════════════════════════════════════════════════
    // CHARGE — 2D أو 3D
    // ══════════════════════════════════════════════════════════
    public function charge(array $payload): array
    {
        if (empty($this->apiLoginId) || empty($this->transactionKey)) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $payload['reference'] ?? '',
                0, '', 'AUTHNET_API_LOGIN_ID أو AUTHNET_TRANSACTION_KEY غير مضبوط');
        }

        $mode      = strtoupper($payload['processing_mode'] ?? '3D');
        $amount    = floatval($payload['amount']   ?? 0);
        $currency  = strtoupper($payload['currency'] ?? 'USD');
        $reference = $payload['reference'] ?? uniqid('an_', true);

        if ($amount <= 0) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $reference, $amount, $currency, 'المبلغ غير صالح');
        }

        $validation = $this->validateCard($payload);
        if (!$validation['valid']) {
            return GatewayErrorMapper::buildErrorResponse('INVALID_CARD', $reference, $amount, $currency, $validation['message']);
        }
        [$ccNumber, $expMonth, $expYear, $cvv2] = $validation['data'];

        // Authorize.Net: expiry = YYYY-MM
        $expiry = sprintf('%04d-%02d', $expYear, $expMonth);

        $body = [
            'createTransactionRequest' => [
                'merchantAuthentication' => [
                    'name'           => $this->apiLoginId,
                    'transactionKey' => $this->transactionKey,
                ],
                'refId' => $reference,
                'transactionRequest' => [
                    'transactionType' => 'authCaptureTransaction',
                    'amount'          => number_format($amount, 2, '.', ''),
                    'payment'         => [
                        'creditCard' => [
                            'cardNumber'     => $ccNumber,
                            'expirationDate' => $expiry,
                            'cardCode'       => $cvv2,
                        ],
                    ],
                    // 2D: EC device type تجاوز 3DS
                    // 3D: WEB يفعّل 3DS تلقائياً
                    'retail' => $mode === '2D'
                        ? ['marketType' => '0', 'deviceType' => '8']  // 8 = EC (Ecommerce no 3DS)
                        : null,
                    'order' => [
                        'invoiceNumber' => $reference,
                        'description'   => 'DI PARMA Payment',
                    ],
                    'customer' => [
                        'email' => $payload['email'] ?? '',
                    ],
                ],
            ],
        ];

        // تنظيف القيم الـ null
        if ($body['createTransactionRequest']['transactionRequest']['retail'] === null) {
            unset($body['createTransactionRequest']['transactionRequest']['retail']);
        }

        if (!empty($payload['approval_code'])) {
            $body['createTransactionRequest']['transactionRequest']['userFields'] = [
                ['name' => 'approval_code', 'value' => $payload['approval_code']],
            ];
        }

        $start    = microtime(true);
        $res      = $this->request($body, $this->buildIdempotencyKey($reference, $amount));
        $duration = microtime(true) - $start;

        return $this->parseChargeResponse($res, $mode, $reference, $amount, $currency, $payload, $duration, 'charge');
    }

    // ══════════════════════════════════════════════════════════
    // HOLD — authOnlyTransaction
    // ══════════════════════════════════════════════════════════
    public function hold(array $payload): array
    {
        if (empty($this->apiLoginId) || empty($this->transactionKey)) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $payload['reference'] ?? '');
        }

        $amount    = floatval($payload['amount']   ?? 0);
        $currency  = strtoupper($payload['currency'] ?? 'USD');
        $reference = $payload['reference'] ?? uniqid('anh_', true);

        if ($amount <= 0) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $reference, $amount, $currency, 'المبلغ غير صالح');
        }

        $validation = $this->validateCard($payload);
        if (!$validation['valid']) {
            return GatewayErrorMapper::buildErrorResponse('INVALID_CARD', $reference, $amount, $currency, $validation['message']);
        }
        [$ccNumber, $expMonth, $expYear, $cvv2] = $validation['data'];

        $body = [
            'createTransactionRequest' => [
                'merchantAuthentication' => [
                    'name'           => $this->apiLoginId,
                    'transactionKey' => $this->transactionKey,
                ],
                'refId' => $reference,
                'transactionRequest' => [
                    'transactionType' => 'authOnlyTransaction',  // ← HOLD
                    'amount'          => number_format($amount, 2, '.', ''),
                    'payment'         => [
                        'creditCard' => [
                            'cardNumber'     => $ccNumber,
                            'expirationDate' => sprintf('%04d-%02d', $expYear, $expMonth),
                            'cardCode'       => $cvv2,
                        ],
                    ],
                    'order' => ['invoiceNumber' => $reference, 'description' => 'DI PARMA Hold'],
                ],
            ],
        ];

        $start    = microtime(true);
        $res      = $this->request($body, $this->buildIdempotencyKey('hold_' . $reference, $amount));
        $duration = microtime(true) - $start;

        return $this->parseChargeResponse($res, 'HOLD', $reference, $amount, $currency, $payload, $duration, 'hold');
    }

    // ══════════════════════════════════════════════════════════
    // CAPTURE — priorAuthCaptureTransaction
    // ══════════════════════════════════════════════════════════
    public function capture(string $transactionId, ?float $amount = null): array
    {
        $body = [
            'createTransactionRequest' => [
                'merchantAuthentication' => [
                    'name'           => $this->apiLoginId,
                    'transactionKey' => $this->transactionKey,
                ],
                'transactionRequest' => [
                    'transactionType' => 'priorAuthCaptureTransaction',
                    'refTransId'      => $transactionId,
                ],
            ],
        ];

        if ($amount !== null) {
            $body['createTransactionRequest']['transactionRequest']['amount'] = number_format($amount, 2, '.', '');
        }

        $start    = microtime(true);
        $res      = $this->request($body, $this->buildIdempotencyKey('cap_' . $transactionId, $amount ?? 0));
        $duration = microtime(true) - $start;

        $txRes    = $res['transactionResponse'] ?? [];
        $respCode = $txRes['responseCode']      ?? '';

        if ($respCode === '1') {
            $result = [
                'success'        => true,
                'status'         => 'captured',
                'transaction_id' => $txRes['transId'] ?? $transactionId,
                'reference'      => $txRes['refTransID'] ?? '',
                'amount'         => $amount ?? 0,
                'currency'       => '',
                'message'        => '✅ تم تحصيل المبلغ عبر Authorize.Net',
                'error_code'     => '',
                'requires_3ds'   => false,
                'client_secret'  => '',
                'redirect_url'   => '',
                'decline_code'   => '',
                'retryable'      => false,
                'hard_block'     => false,
            ];
            GatewayLogger::log('authorizenet', 'capture', ['transaction_id' => $transactionId], $result, '', $duration);
            return $result;
        }

        $errCode = $this->normalizeError($res);
        GatewayLogger::log('authorizenet', 'capture', ['transaction_id' => $transactionId], $res, $errCode, $duration);
        return GatewayErrorMapper::buildErrorResponse($errCode, '', 0, '',
            $txRes['errors'][0]['errorText'] ?? 'فشل التحصيل');
    }

    // ══════════════════════════════════════════════════════════
    // CANCEL — voidTransaction
    // ══════════════════════════════════════════════════════════
    public function cancel(string $transactionId, string $reason = 'requested_by_customer'): array
    {
        $body = [
            'createTransactionRequest' => [
                'merchantAuthentication' => [
                    'name'           => $this->apiLoginId,
                    'transactionKey' => $this->transactionKey,
                ],
                'transactionRequest' => [
                    'transactionType' => 'voidTransaction',
                    'refTransId'      => $transactionId,
                ],
            ],
        ];

        $start    = microtime(true);
        $res      = $this->request($body);
        $duration = microtime(true) - $start;

        $txRes    = $res['transactionResponse'] ?? [];
        $respCode = $txRes['responseCode']      ?? '';

        if ($respCode === '1') {
            $result = [
                'success'        => true,
                'status'         => 'cancelled',
                'transaction_id' => $txRes['transId'] ?? $transactionId,
                'reference'      => '',
                'amount'         => 0,
                'currency'       => '',
                'message'        => '✅ تم إلغاء العملية عبر Authorize.Net',
                'error_code'     => '',
                'requires_3ds'   => false,
                'client_secret'  => '',
                'redirect_url'   => '',
                'decline_code'   => '',
                'retryable'      => false,
                'hard_block'     => false,
            ];
            GatewayLogger::log('authorizenet', 'cancel', ['transaction_id' => $transactionId], $result, '', $duration);
            return $result;
        }

        $errCode = $this->normalizeError($res);
        GatewayLogger::log('authorizenet', 'cancel', ['transaction_id' => $transactionId], $res, $errCode, $duration);
        return GatewayErrorMapper::buildErrorResponse($errCode, '', 0, '',
            $txRes['errors'][0]['errorText'] ?? 'فشل الإلغاء');
    }

    // ══════════════════════════════════════════════════════════
    // مساعد تحليل Response
    // ══════════════════════════════════════════════════════════
    private function parseChargeResponse(
        array  $res,
        string $mode,
        string $reference,
        float  $amount,
        string $currency,
        array  $payload,
        float  $duration,
        string $operation
    ): array {
        $txRes    = $res['transactionResponse'] ?? [];
        $respCode = $txRes['responseCode']      ?? '';
        $transId  = $txRes['transId']           ?? '';
        $authCode = $txRes['authCode']          ?? '';

        // 1 = Approved
        if ($respCode === '1') {
            $statusMap = ['charge' => 'completed', 'hold' => 'authorized'];
            $msgMap    = ['charge' => "✅ تم الدفع {$mode} عبر Authorize.Net", 'hold' => '✅ تم حجز المبلغ عبر Authorize.Net'];
            $result = [
                'success'        => true,
                'status'         => $statusMap[$operation] ?? 'completed',
                'transaction_id' => $transId,
                'auth_code'      => $authCode,
                'reference'      => $reference,
                'amount'         => $amount,
                'currency'       => $currency,
                'message'        => $msgMap[$operation] ?? '✅ تمت العملية بنجاح',
                'error_code'     => '',
                'requires_3ds'   => false,
                'client_secret'  => '',
                'redirect_url'   => '',
                'decline_code'   => '',
                'retryable'      => false,
                'hard_block'     => false,
            ];
            GatewayLogger::log('authorizenet', "$operation[$mode]", $payload, $result, '', $duration);
            return $result;
        }

        // 2 = Declined, 3 = Error, 4 = Held for review
        $errCode = $this->normalizeError($res);
        GatewayLogger::log('authorizenet', "$operation[$mode]", $payload, $res, $errCode, $duration);
        $errMsg = $txRes['errors'][0]['errorText']
            ?? $txRes['messages'][0]['description']
            ?? "responseCode: $respCode";
        return GatewayErrorMapper::buildErrorResponse($errCode, $reference, $amount, $currency, $errMsg);
    }

    // ── HTTP + Validate ───────────────────────────────────────

    private function validateCard(array $payload): array
    {
        $ccNumber = preg_replace('/\D/', '', $payload['card_number'] ?? $payload['cc_number'] ?? '');
        $cvv2     = trim((string)($payload['cvv2'] ?? $payload['card_cvv'] ?? ''));

        if (strlen($ccNumber) < 13 || strlen($ccNumber) > 19) {
            return ['valid' => false, 'message' => 'رقم البطاقة غير صالح'];
        }
        if (!preg_match('/^\d{3,4}$/', $cvv2)) {
            return ['valid' => false, 'message' => 'CVV غير صالح'];
        }

        $parts   = explode('/', trim($payload['card_expiry'] ?? $payload['cc_expiry'] ?? ''));
        $month   = intval($parts[0] ?? 0);
        $yearRaw = trim($parts[1] ?? '');
        $year    = strlen($yearRaw) === 2 ? intval('20' . $yearRaw) : intval($yearRaw);

        if ($month < 1 || $month > 12) {
            return ['valid' => false, 'message' => 'شهر انتهاء البطاقة غير صالح'];
        }
        $exp = new DateTime("$year-$month-01");
        $exp->modify('last day of this month');
        if ($exp < new DateTime()) {
            return ['valid' => false, 'message' => 'البطاقة منتهية الصلاحية'];
        }
        return ['valid' => true, 'data' => [$ccNumber, $month, $year, $cvv2]];
    }

    private function request(array $body, string $idempotencyKey = ''): array
    {
        $ch = curl_init($this->baseUrl);
        $headers = ['Content-Type: application/json'];
        if (!empty($idempotencyKey)) {
            // Authorize.Net يدعم X-Anet-Request-ID للـ idempotency
            $headers[] = 'X-Anet-Request-ID: ' . $idempotencyKey;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['transactionResponse' => ['responseCode' => '3',
                'errors' => [['errorText' => $err]]]];
        }

        // Authorize.Net يُرجع BOM أحياناً — نزيله
        $res = ltrim($res, "\xEF\xBB\xBF");
        return json_decode($res ?: '{}', true) ?: [];
    }
}
