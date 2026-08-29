<?php
/**
 * ============================================================
 * DI PARMA | MyFatoorahAdapter v2
 * + GatewayErrorMapper + GatewayLogger + Idempotency Key
 * ============================================================
 */

require_once __DIR__ . '/GatewayAdapterInterface.php';
require_once __DIR__ . '/GatewayErrorMapper.php';
require_once __DIR__ . '/GatewayLogger.php';

class MyFatoorahAdapter implements GatewayAdapterInterface
{
    private string $apiKey;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey  = getenv('MYFAOORAH_API_KEY') ?: '';
        $env           = getenv('MYFAOORAH_ENVIRONMENT') ?: 'sandbox';
        $this->baseUrl = $env === 'live'
            ? 'https://api.myfatoorah.com'
            : 'https://apitest.myfatoorah.com';
    }

    public function getName(): string { return 'myfatoorah'; }

    public function supports(string $mode): bool
    {
        // MyFatoorah لا تدعم HOLD/CAPTURE المستقل
        return in_array(strtoupper($mode), ['2D', '3D']);
    }

    public function normalizeError(array $rawResponse): string
    {
        return GatewayErrorMapper::fromMyFatoorah($rawResponse);
    }

    public function buildIdempotencyKey(string $reference, float $amount): string
    {
        // MyFatoorah تستخدم CustomerIdentifier كـ idempotency key ضمنياً
        return hash('sha256', $reference . '|' . $amount . '|' . getenv('ENCRYPTION_KEY'));
    }

    // ══════════════════════════════════════════════════════════
    // CHARGE
    // ══════════════════════════════════════════════════════════
    public function charge(array $payload): array
    {
        if (empty($this->apiKey)) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $payload['reference'] ?? '');
        }

        $mode      = strtoupper($payload['processing_mode'] ?? '3D');
        $amount    = floatval($payload['amount']   ?? 0);
        $currency  = strtoupper($payload['currency'] ?? 'USD');
        $reference = $payload['reference'] ?? uniqid('mf_', true);
        $siteUrl   = defined('SITE_URL') ? SITE_URL : 'https://diparmas.com';

        if ($amount <= 0) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $reference, $amount, $currency, 'المبلغ غير صالح');
        }

        $validation = $this->validateCard($payload);
        if (!$validation['valid']) {
            return GatewayErrorMapper::buildErrorResponse('INVALID_CARD', $reference, $amount, $currency, $validation['message']);
        }

        // Idempotency عبر CustomerIdentifier الفريد
        $idempKey = $this->buildIdempotencyKey($reference, $amount);

        $start = microtime(true);
        try {
            // [1] إنشاء Session — CustomerIdentifier يضمن عدم التكرار
            $sessionRes = $this->request('POST', '/v2/InitiateSession', [
                'CustomerIdentifier' => 'dp_' . $idempKey,
            ]);

            if (empty($sessionRes['Data']['SessionId'])) {
                $errCode = $this->normalizeError($sessionRes);
                GatewayLogger::log('myfatoorah', "charge[$mode]", $payload, $sessionRes, $errCode, microtime(true) - $start);
                return GatewayErrorMapper::buildErrorResponse($errCode, $reference, $amount, $currency,
                    $sessionRes['Message'] ?? 'فشل إنشاء Session');
            }

            $sessionId = $sessionRes['Data']['SessionId'];

            // [2] تنفيذ الدفع
            $body = [
                'SessionId'          => $sessionId,
                'InvoiceValue'       => $amount,
                'CurrencyIso'        => $currency,
                'CustomerName'       => $payload['name']  ?? 'Customer',
                'CustomerEmail'      => $payload['email'] ?? '',
                'CustomerReference'  => $reference,
                'CallBackUrl'        => $siteUrl . '/api/orchestrator.php?action=confirm&ref=' . $reference,
                'ErrorUrl'           => $siteUrl . '/crypto.php?error=payment_failed',
                'DisplayCurrencyIso' => $currency,
                'UserDefinedField'   => 'mode:' . $mode . '|platform:diparma',
            ];

            $execRes  = $this->request('POST', '/v2/ExecutePayment', $body);
            $duration = microtime(true) - $start;

            if (!($execRes['IsSuccess'] ?? false)) {
                $errCode = $this->normalizeError($execRes);
                GatewayLogger::log('myfatoorah', "charge[$mode]", $payload, $execRes, $errCode, $duration);
                return GatewayErrorMapper::buildErrorResponse($errCode, $reference, $amount, $currency,
                    $execRes['Message'] ?? 'فشل تنفيذ الدفع');
            }

            $data      = $execRes['Data']            ?? [];
            $invoiceId = (string)($data['InvoiceId']    ?? '');
            $status    = strtolower($data['InvoiceStatus'] ?? '');
            $payUrl    = $data['PaymentURL']            ?? null;

            // نجاح مباشر
            if ($status === 'paid') {
                $result = [
                    'success'        => true,
                    'status'         => 'completed',
                    'transaction_id' => $invoiceId,
                    'reference'      => $reference,
                    'amount'         => $amount,
                    'currency'       => $currency,
                    'message'        => '✅ تم الدفع عبر MyFatoorah',
                    'error_code'     => '',
                    'requires_3ds'   => false,
                    'client_secret'  => '',
                    'redirect_url'   => '',
                    'decline_code'   => '',
                    'retryable'      => false,
                    'hard_block'     => false,
                ];
                GatewayLogger::log('myfatoorah', "charge[$mode]", $payload, $result, '', $duration);
                return $result;
            }

            // يحتاج redirect
            if (!empty($payUrl)) {
                $result = [
                    'success'        => false,
                    'status'         => 'requires_3ds',
                    'transaction_id' => $invoiceId,
                    'reference'      => $reference,
                    'amount'         => $amount,
                    'currency'       => $currency,
                    'message'        => 'يتطلب إعادة التوجيه للتحقق',
                    'error_code'     => '',
                    'requires_3ds'   => true,
                    'client_secret'  => '',
                    'redirect_url'   => $payUrl,
                    'decline_code'   => '',
                    'retryable'      => false,
                    'hard_block'     => false,
                ];
                GatewayLogger::log('myfatoorah', "charge[$mode]", $payload, $result, '', $duration);
                return $result;
            }

            // pending أو فشل
            $errCode = $status === 'pending' ? '' : $this->normalizeError($execRes);
            GatewayLogger::log('myfatoorah', "charge[$mode]", $payload, $execRes, $errCode, $duration);

            if ($status === 'pending') {
                return [
                    'success'        => true,
                    'status'         => 'pending',
                    'transaction_id' => $invoiceId,
                    'reference'      => $reference,
                    'amount'         => $amount,
                    'currency'       => $currency,
                    'message'        => '⏳ في انتظار تأكيد الدفع',
                    'error_code'     => '',
                    'requires_3ds'   => false,
                    'client_secret'  => '',
                    'redirect_url'   => '',
                    'decline_code'   => '',
                    'retryable'      => false,
                    'hard_block'     => false,
                ];
            }

            return GatewayErrorMapper::buildErrorResponse($errCode, $reference, $amount, $currency, "الحالة: $status");

        } catch (Exception $e) {
            GatewayLogger::log('myfatoorah', "charge[$mode]", $payload, ['exception' => $e->getMessage()], 'NETWORK_ERROR', microtime(true) - $start);
            return GatewayErrorMapper::buildErrorResponse('NETWORK_ERROR', $reference, $amount, $currency, $e->getMessage());
        }
    }

    // ══════════════════════════════════════════════════════════
    // HOLD / CAPTURE — غير مدعومان
    // ══════════════════════════════════════════════════════════
    public function hold(array $payload): array
    {
        return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $payload['reference'] ?? '', 0, '',
            'MyFatoorah لا تدعم HOLD — استخدم charge() مباشرة');
    }

    public function capture(string $transactionId, ?float $amount = null): array
    {
        return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', '', 0, '',
            'MyFatoorah لا تدعم CAPTURE المستقل');
    }

    // ══════════════════════════════════════════════════════════
    // CANCEL
    // ══════════════════════════════════════════════════════════
    public function cancel(string $transactionId, string $reason = 'requested_by_customer'): array
    {
        $start = microtime(true);
        try {
            $res      = $this->request('POST', '/v2/CancelPayment', [
                'KeyType' => 'InvoiceId',
                'Key'     => $transactionId,
            ]);
            $duration = microtime(true) - $start;

            if ($res['IsSuccess'] ?? false) {
                $result = [
                    'success'        => true,
                    'status'         => 'cancelled',
                    'transaction_id' => $transactionId,
                    'reference'      => '',
                    'amount'         => 0,
                    'currency'       => '',
                    'message'        => '✅ تم إلغاء الفاتورة عبر MyFatoorah',
                    'error_code'     => '',
                    'requires_3ds'   => false,
                    'client_secret'  => '',
                    'redirect_url'   => '',
                    'decline_code'   => '',
                    'retryable'      => false,
                    'hard_block'     => false,
                ];
                GatewayLogger::log('myfatoorah', 'cancel', ['transaction_id' => $transactionId], $result, '', $duration);
                return $result;
            }

            $errCode = $this->normalizeError($res);
            GatewayLogger::log('myfatoorah', 'cancel', ['transaction_id' => $transactionId], $res, $errCode, $duration);
            return GatewayErrorMapper::buildErrorResponse($errCode, '', 0, '', $res['Message'] ?? 'فشل الإلغاء');

        } catch (Exception $e) {
            GatewayLogger::log('myfatoorah', 'cancel', ['transaction_id' => $transactionId], ['exception' => $e->getMessage()], 'NETWORK_ERROR', microtime(true) - $start);
            return GatewayErrorMapper::buildErrorResponse('NETWORK_ERROR', '', 0, '', $e->getMessage());
        }
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

    private function request(string $method, string $path, array $body = []): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
        ]);
        if (!empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $res = curl_exec($ch);
        curl_close($ch);
        return json_decode($res ?: '{}', true) ?: [];
    }
}
