<?php
/**
 * ============================================================
 * DI PARMA | CheckoutAdapter v2
 * + GatewayErrorMapper + GatewayLogger + Idempotency Key
 * ============================================================
 */

require_once __DIR__ . '/GatewayAdapterInterface.php';
require_once __DIR__ . '/GatewayErrorMapper.php';
require_once __DIR__ . '/GatewayLogger.php';

class CheckoutAdapter implements GatewayAdapterInterface
{
    private string $secretKey;
    private string $publicKey;
    private string $baseUrl;

    public function __construct()
    {
        $this->secretKey = getenv('CHECKOUT_API_KEY')    ?: getenv('CHECKOUT_SECRET_KEY') ?: '';
        $this->publicKey = getenv('CHECKOUT_PUBLIC_KEY') ?: '';
        $env             = getenv('CHECKOUT_ENVIRONMENT') ?: 'sandbox';
        $this->baseUrl   = $env === 'live'
            ? 'https://api.checkout.com'
            : 'https://api.sandbox.checkout.com';
    }

    public function getName(): string { return 'checkout'; }

    public function supports(string $mode): bool
    {
        return in_array(strtoupper($mode), ['2D','3D','HOLD','CAPTURE','CANCEL']);
    }

    public function normalizeError(array $rawResponse): string
    {
        return GatewayErrorMapper::fromCheckout($rawResponse);
    }

    public function buildIdempotencyKey(string $reference, float $amount): string
    {
        return 'idemp_cko_' . hash('sha256', $reference . '|' . $amount . '|' . getenv('ENCRYPTION_KEY'));
    }

    // ══════════════════════════════════════════════════════════
    // CHARGE
    // ══════════════════════════════════════════════════════════
    public function charge(array $payload): array
    {
        if (empty($this->secretKey)) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $payload['reference'] ?? '');
        }

        $mode      = strtoupper($payload['processing_mode'] ?? '3D');
        $amount    = floatval($payload['amount']   ?? 0);
        $currency  = strtoupper($payload['currency'] ?? 'USD');
        $reference = $payload['reference'] ?? uniqid('cko_', true);
        $siteUrl   = defined('SITE_URL') ? SITE_URL : 'https://diparmas.com';

        if ($amount <= 0) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $reference, $amount, $currency, 'المبلغ غير صالح');
        }

        $validation = $this->validateCard($payload);
        if (!$validation['valid']) {
            return GatewayErrorMapper::buildErrorResponse('INVALID_CARD', $reference, $amount, $currency, $validation['message']);
        }
        [$ccNumber, $expMonth, $expYear, $cvv2] = $validation['data'];

        $start = microtime(true);
        try {
            $body = [
                'source'      => [
                    'type'         => 'card',
                    'number'       => $ccNumber,
                    'expiry_month' => $expMonth,
                    'expiry_year'  => $expYear,
                    'cvv'          => $cvv2,
                    'name'         => $payload['name'] ?? 'Customer',
                ],
                'amount'      => (int)($amount * 100),
                'currency'    => $currency,
                'reference'   => $reference,
                'capture'     => true,
                '3ds'         => ['enabled' => $mode !== '2D'],
                'customer'    => ['name' => $payload['name'] ?? 'Customer', 'email' => $payload['email'] ?? ''],
                'metadata'    => ['reference' => $reference, 'platform' => 'diparma', 'mode' => $mode],
                'success_url' => $siteUrl . '/crypto_confirm.php?ref=' . $reference,
                'failure_url' => $siteUrl . '/crypto.php?error=payment_failed',
            ];

            if (!empty($payload['approval_code'])) {
                $body['metadata']['approval_code'] = $payload['approval_code'];
            }

            $res      = $this->request('POST', '/payments', $body,
                          $this->buildIdempotencyKey($reference, $amount));
            $st       = $res['status'] ?? '';
            $id       = $res['id']     ?? '';
            $duration = microtime(true) - $start;

            if (in_array($st, ['Authorized', 'Captured'])) {
                $result = [
                    'success'        => true,
                    'status'         => 'completed',
                    'transaction_id' => $id,
                    'reference'      => $reference,
                    'amount'         => $amount,
                    'currency'       => $currency,
                    'message'        => "✅ تم الدفع {$mode} عبر Checkout.com",
                    'error_code'     => '',
                    'requires_3ds'   => false,
                    'client_secret'  => '',
                    'redirect_url'   => '',
                    'decline_code'   => '',
                    'retryable'      => false,
                    'hard_block'     => false,
                ];
                GatewayLogger::log('checkout', "charge[$mode]", $payload, $result, '', $duration);
                return $result;
            }

            $redirectUrl = $res['_links']['redirect']['href'] ?? '';
            if ($st === 'Pending' && !empty($redirectUrl)) {
                $result = [
                    'success'        => false,
                    'status'         => 'requires_3ds',
                    'transaction_id' => $id,
                    'reference'      => $reference,
                    'amount'         => $amount,
                    'currency'       => $currency,
                    'message'        => 'يتطلب 3DS redirect',
                    'error_code'     => '',
                    'requires_3ds'   => true,
                    'client_secret'  => '',
                    'redirect_url'   => $redirectUrl,
                    'decline_code'   => '',
                    'retryable'      => false,
                    'hard_block'     => false,
                ];
                GatewayLogger::log('checkout', "charge[$mode]", $payload, $result, '', $duration);
                return $result;
            }

            $errCode = $this->normalizeError($res);
            GatewayLogger::log('checkout', "charge[$mode]", $payload, $res, $errCode, $duration);
            return GatewayErrorMapper::buildErrorResponse($errCode, $reference, $amount, $currency,
                $res['response_summary'] ?? $res['error_codes'][0] ?? $st);

        } catch (Exception $e) {
            GatewayLogger::log('checkout', "charge[$mode]", $payload, ['exception' => $e->getMessage()], 'NETWORK_ERROR', microtime(true) - $start);
            return GatewayErrorMapper::buildErrorResponse('NETWORK_ERROR', $reference, $amount, $currency, $e->getMessage());
        }
    }

    // ══════════════════════════════════════════════════════════
    // HOLD — capture: false
    // ══════════════════════════════════════════════════════════
    public function hold(array $payload): array
    {
        if (empty($this->secretKey)) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $payload['reference'] ?? '');
        }

        $amount    = floatval($payload['amount']   ?? 0);
        $currency  = strtoupper($payload['currency'] ?? 'USD');
        $reference = $payload['reference'] ?? uniqid('ckoh_', true);
        $siteUrl   = defined('SITE_URL') ? SITE_URL : 'https://diparmas.com';

        if ($amount <= 0) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $reference, $amount, $currency, 'المبلغ غير صالح');
        }

        $validation = $this->validateCard($payload);
        if (!$validation['valid']) {
            return GatewayErrorMapper::buildErrorResponse('INVALID_CARD', $reference, $amount, $currency, $validation['message']);
        }
        [$ccNumber, $expMonth, $expYear, $cvv2] = $validation['data'];

        $start = microtime(true);
        try {
            $body = [
                'source'      => [
                    'type'         => 'card',
                    'number'       => $ccNumber,
                    'expiry_month' => $expMonth,
                    'expiry_year'  => $expYear,
                    'cvv'          => $cvv2,
                    'name'         => $payload['name'] ?? 'Customer',
                ],
                'amount'      => (int)($amount * 100),
                'currency'    => $currency,
                'reference'   => $reference,
                'capture'     => false,   // HOLD
                '3ds'         => ['enabled' => true],
                'customer'    => ['name' => $payload['name'] ?? 'Customer', 'email' => $payload['email'] ?? ''],
                'metadata'    => ['reference' => $reference, 'platform' => 'diparma', 'type' => 'hold'],
                'success_url' => $siteUrl . '/crypto_confirm.php?ref=' . $reference,
                'failure_url' => $siteUrl . '/crypto.php?error=hold_failed',
            ];

            $res      = $this->request('POST', '/payments', $body,
                          $this->buildIdempotencyKey('hold_' . $reference, $amount));
            $st       = $res['status'] ?? '';
            $id       = $res['id']     ?? '';
            $duration = microtime(true) - $start;

            if ($st === 'Authorized') {
                $result = [
                    'success'        => true,
                    'status'         => 'authorized',
                    'transaction_id' => $id,
                    'reference'      => $reference,
                    'amount'         => $amount,
                    'currency'       => $currency,
                    'message'        => '✅ تم حجز المبلغ عبر Checkout.com',
                    'error_code'     => '',
                    'requires_3ds'   => false,
                    'client_secret'  => '',
                    'redirect_url'   => '',
                    'decline_code'   => '',
                    'retryable'      => false,
                    'hard_block'     => false,
                ];
                GatewayLogger::log('checkout', 'hold', $payload, $result, '', $duration);
                return $result;
            }

            $redirectUrl = $res['_links']['redirect']['href'] ?? '';
            if ($st === 'Pending' && !empty($redirectUrl)) {
                $result = [
                    'success'        => false,
                    'status'         => 'requires_3ds',
                    'transaction_id' => $id,
                    'reference'      => $reference,
                    'amount'         => $amount,
                    'currency'       => $currency,
                    'message'        => 'يتطلب 3DS لتأكيد الحجز',
                    'error_code'     => '',
                    'requires_3ds'   => true,
                    'client_secret'  => '',
                    'redirect_url'   => $redirectUrl,
                    'decline_code'   => '',
                    'retryable'      => false,
                    'hard_block'     => false,
                ];
                GatewayLogger::log('checkout', 'hold', $payload, $result, '', $duration);
                return $result;
            }

            $errCode = $this->normalizeError($res);
            GatewayLogger::log('checkout', 'hold', $payload, $res, $errCode, $duration);
            return GatewayErrorMapper::buildErrorResponse($errCode, $reference, $amount, $currency,
                $res['response_summary'] ?? "فشل الحجز: $st");

        } catch (Exception $e) {
            GatewayLogger::log('checkout', 'hold', $payload, ['exception' => $e->getMessage()], 'NETWORK_ERROR', microtime(true) - $start);
            return GatewayErrorMapper::buildErrorResponse('NETWORK_ERROR', $reference, $amount, $currency, $e->getMessage());
        }
    }

    // ══════════════════════════════════════════════════════════
    // CAPTURE
    // ══════════════════════════════════════════════════════════
    public function capture(string $transactionId, ?float $amount = null): array
    {
        $start = microtime(true);
        $body  = $amount !== null ? ['amount' => (int)($amount * 100)] : [];

        $res      = $this->request('POST', "/payments/$transactionId/captures", $body,
                      $this->buildIdempotencyKey('cap_' . $transactionId, $amount ?? 0));
        $duration = microtime(true) - $start;

        if (!empty($res['action_id'])) {
            $result = [
                'success'        => true,
                'status'         => 'captured',
                'transaction_id' => $transactionId,
                'reference'      => $res['reference'] ?? '',
                'amount'         => $amount ?? 0,
                'currency'       => '',
                'message'        => '✅ تم تحصيل المبلغ عبر Checkout.com',
                'error_code'     => '',
                'requires_3ds'   => false,
                'client_secret'  => '',
                'redirect_url'   => '',
                'decline_code'   => '',
                'retryable'      => false,
                'hard_block'     => false,
            ];
            GatewayLogger::log('checkout', 'capture', ['transaction_id' => $transactionId], $result, '', $duration);
            return $result;
        }

        $errCode = $this->normalizeError($res);
        GatewayLogger::log('checkout', 'capture', ['transaction_id' => $transactionId], $res, $errCode, $duration);
        return GatewayErrorMapper::buildErrorResponse($errCode, '', 0, '', $res['error_codes'][0] ?? 'فشل التحصيل');
    }

    // ══════════════════════════════════════════════════════════
    // CANCEL (VOID)
    // ══════════════════════════════════════════════════════════
    public function cancel(string $transactionId, string $reason = 'requested_by_customer'): array
    {
        $start = microtime(true);
        $res   = $this->request('POST', "/payments/$transactionId/voids", ['reference' => $reason]);
        $duration = microtime(true) - $start;

        if (!empty($res['action_id'])) {
            $result = [
                'success'        => true,
                'status'         => 'cancelled',
                'transaction_id' => $transactionId,
                'reference'      => '',
                'amount'         => 0,
                'currency'       => '',
                'message'        => '✅ تم الإلغاء عبر Checkout.com',
                'error_code'     => '',
                'requires_3ds'   => false,
                'client_secret'  => '',
                'redirect_url'   => '',
                'decline_code'   => '',
                'retryable'      => false,
                'hard_block'     => false,
            ];
            GatewayLogger::log('checkout', 'cancel', ['transaction_id' => $transactionId], $result, '', $duration);
            return $result;
        }

        $errCode = $this->normalizeError($res);
        GatewayLogger::log('checkout', 'cancel', ['transaction_id' => $transactionId], $res, $errCode, $duration);
        return GatewayErrorMapper::buildErrorResponse($errCode, '', 0, '', $res['error_codes'][0] ?? 'فشل الإلغاء');
    }

    // ── مساعدات ──────────────────────────────────────────────

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

    private function request(string $method, string $path, array $body = [], string $idempotencyKey = ''): array
    {
        $ch      = curl_init($this->baseUrl . $path);
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->secretKey,
        ];
        if (!empty($idempotencyKey)) {
            $headers[] = 'Cko-Idempotency-Key: ' . $idempotencyKey;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if (!empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode($res ?: '{}', true) ?: [];
        $data['_http_code'] = $code;
        return $data;
    }
}
