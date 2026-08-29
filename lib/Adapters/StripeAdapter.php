<?php
/**
 * ============================================================
 * DI PARMA | StripeAdapter v2
 * + GatewayErrorMapper  — رموز أخطاء موحدة
 * + GatewayLogger       — تسجيل تدقيقي كامل
 * + Idempotency Key     — منع تكرار الخصم
 * ============================================================
 */

require_once __DIR__ . '/GatewayAdapterInterface.php';
require_once __DIR__ . '/GatewayErrorMapper.php';
require_once __DIR__ . '/GatewayLogger.php';

class StripeAdapter implements GatewayAdapterInterface
{
    private string $secretKey;
    private string $publicKey;

    private const API = 'https://api.stripe.com';

    public function __construct()
    {
        $this->secretKey = getenv('STRIPE_SECRET_KEY') ?: '';
        $this->publicKey = getenv('STRIPE_PUBLIC_KEY')  ?: '';
    }

    public function getName(): string { return 'stripe'; }

    public function supports(string $mode): bool
    {
        return in_array(strtoupper($mode), ['2D','3D','HOLD','CAPTURE','CANCEL']);
    }

    public function normalizeError(array $rawResponse): string
    {
        return GatewayErrorMapper::fromStripe($rawResponse);
    }

    public function buildIdempotencyKey(string $reference, float $amount): string
    {
        return 'idemp_str_' . hash('sha256', $reference . '|' . $amount . '|' . getenv('ENCRYPTION_KEY'));
    }

    // ══════════════════════════════════════════════════════════
    // CHARGE — 2D أو 3D
    // ══════════════════════════════════════════════════════════
    public function charge(array $payload): array
    {
        if (empty($this->secretKey)) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $payload['reference'] ?? '');
        }

        $mode      = strtoupper($payload['processing_mode'] ?? '3D');
        $amount    = floatval($payload['amount']   ?? 0);
        $currency  = strtolower($payload['currency'] ?? 'usd');
        $reference = $payload['reference'] ?? uniqid('str_', true);

        if ($amount <= 0) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $reference, $amount, strtoupper($currency), 'المبلغ غير صالح');
        }

        $validation = $this->validateCard($payload);
        if (!$validation['valid']) {
            return GatewayErrorMapper::buildErrorResponse('INVALID_CARD', $reference, $amount, strtoupper($currency), $validation['message']);
        }
        [$ccNumber, $expMonth, $expYear, $cvv2] = $validation['data'];

        $start = microtime(true);
        try {
            // [1] PaymentMethod
            $pm = $this->request('POST', '/v1/payment_methods', [
                'type'                  => 'card',
                'card[number]'          => $ccNumber,
                'card[exp_month]'       => $expMonth,
                'card[exp_year]'        => $expYear,
                'card[cvc]'             => $cvv2,
                'billing_details[name]' => $payload['name']  ?? 'Customer',
                'billing_details[email]'=> $payload['email'] ?? '',
            ]);

            if (empty($pm['id'])) {
                $errCode = $this->normalizeError($pm);
                GatewayLogger::log('stripe', "charge[$mode]", $payload, $pm, $errCode, microtime(true) - $start);
                return GatewayErrorMapper::buildErrorResponse($errCode, $reference, $amount, strtoupper($currency), $pm['error']['message'] ?? '');
            }

            // [2] PaymentIntent
            $piParams = [
                'amount'              => (int)($amount * 100),
                'currency'            => $currency,
                'payment_method'      => $pm['id'],
                'confirm'             => 'true',
                'metadata[reference]' => $reference,
                'metadata[platform]'  => 'diparma',
                'metadata[mode]'      => $mode,
            ];

            // payment_method_types=card يعمل مع كل العملات بدون قيود
            $piParams['payment_method_types[]'] = 'card';

            if ($mode === '2D') {
                $piParams['off_session'] = 'true';
                $piParams['payment_method_options[card][request_three_d_secure]'] = 'any';
            }

            if (!empty($payload['approval_code'])) {
                $piParams['metadata[approval_code]'] = $payload['approval_code'];
            }

            $pi      = $this->request('POST', '/v1/payment_intents', $piParams,
                         $this->buildIdempotencyKey($reference, $amount));
            $status  = $pi['status'] ?? '';
            $piId    = $pi['id']     ?? '';
            $duration = microtime(true) - $start;

            // ── نجاح ──────────────────────────────────────────
            if ($status === 'succeeded') {
                // ── استخراج Approval Code الحقيقي من Visa/Mastercard ──
                // يأتي من: charges.data[0].payment_method_details.card.authorization_code
                $authCode   = null;
                $rrn        = null;
                $cardNetwork= null;
                $last4      = null;

                // جلب تفاصيل الـ charge إذا كانت كـ string
                $chargeObj = $pi['charges']['data'][0] ?? null;
                if (!$chargeObj && !empty($pi['latest_charge'])) {
                    $lcId = $pi['latest_charge'];
                    if (is_string($lcId)) {
                        $chargeObj = $this->request('GET', "/v1/charges/{$lcId}");
                    } elseif (is_array($lcId)) {
                        $chargeObj = $lcId;
                    }
                }
                if (is_array($chargeObj)) {
                    $cardDet    = $chargeObj['payment_method_details']['card'] ?? [];
                    $authCode   = $cardDet['authorization_code'] ?? null;  // ← كود الموافقة الحقيقي
                    $cardNetwork= $cardDet['network']             ?? null;  // visa | mastercard
                    $last4      = $cardDet['last4']               ?? null;
                    $rrn        = $chargeObj['balance_transaction'] ?? $chargeObj['id'] ?? null;
                }

                $result = [
                    'success'        => true,
                    'status'         => 'completed',
                    'transaction_id' => $piId,
                    'reference'      => $reference,
                    'amount'         => $amount,
                    'currency'       => strtoupper($currency),
                    'message'        => "✅ تم الدفع {$mode} عبر Stripe",
                    'approval_code'  => $authCode,    // ← Approval Code من Visa/MC
                    'rrn'            => $rrn,
                    'card_network'   => $cardNetwork,
                    'card_last4'     => $last4,
                    'error_code'     => '',
                    'requires_3ds'   => false,
                    'client_secret'  => '',
                    'redirect_url'   => '',
                    'decline_code'   => '',
                    'retryable'      => false,
                    'hard_block'     => false,
                ];
                GatewayLogger::log('stripe', "charge[$mode]", $payload, $result, '', $duration);
                return $result;
            }

            // ── يحتاج 3DS ─────────────────────────────────────
            if ($status === 'requires_action' && !empty($pi['client_secret'])) {
                $result = [
                    'success'        => false,
                    'status'         => 'requires_3ds',
                    'transaction_id' => $piId,
                    'reference'      => $reference,
                    'amount'         => $amount,
                    'currency'       => strtoupper($currency),
                    'message'        => 'يتطلب التحقق 3D Secure',
                    'error_code'     => '',
                    'requires_3ds'   => true,
                    'client_secret'  => $pi['client_secret'],
                    'redirect_url'   => $pi['next_action']['redirect_to_url']['url'] ?? '',
                    'decline_code'   => '',
                    'retryable'      => false,
                    'hard_block'     => false,
                ];
                GatewayLogger::log('stripe', "charge[$mode]", $payload, $result, '', $duration);
                return $result;
            }

            // ── فشل ───────────────────────────────────────────
            $errCode = $this->normalizeError($pi);
            GatewayLogger::log('stripe', "charge[$mode]", $payload, $pi, $errCode, $duration);
            return GatewayErrorMapper::buildErrorResponse(
                $errCode, $reference, $amount, strtoupper($currency),
                $pi['last_payment_error']['message'] ?? $pi['error']['message'] ?? $status
            );

        } catch (Exception $e) {
            GatewayLogger::log('stripe', "charge[$mode]", $payload, ['exception' => $e->getMessage()], 'NETWORK_ERROR', microtime(true) - $start);
            return GatewayErrorMapper::buildErrorResponse('NETWORK_ERROR', $reference, $amount, strtoupper($currency), $e->getMessage());
        }
    }

    // ══════════════════════════════════════════════════════════
    // HOLD
    // ══════════════════════════════════════════════════════════
    public function hold(array $payload): array
    {
        if (empty($this->secretKey)) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $payload['reference'] ?? '');
        }

        $amount    = floatval($payload['amount']   ?? 0);
        $currency  = strtolower($payload['currency'] ?? 'usd');
        $reference = $payload['reference'] ?? uniqid('hold_', true);

        if ($amount <= 0) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $reference, $amount, strtoupper($currency), 'المبلغ غير صالح');
        }

        $validation = $this->validateCard($payload);
        if (!$validation['valid']) {
            return GatewayErrorMapper::buildErrorResponse('INVALID_CARD', $reference, $amount, strtoupper($currency), $validation['message']);
        }
        [$ccNumber, $expMonth, $expYear, $cvv2] = $validation['data'];

        $start = microtime(true);
        try {
            $pm = $this->request('POST', '/v1/payment_methods', [
                'type'                  => 'card',
                'card[number]'          => $ccNumber,
                'card[exp_month]'       => $expMonth,
                'card[exp_year]'        => $expYear,
                'card[cvc]'             => $cvv2,
                'billing_details[name]' => $payload['name'] ?? 'Customer',
            ]);

            if (empty($pm['id'])) {
                $errCode = $this->normalizeError($pm);
                GatewayLogger::log('stripe', 'hold', $payload, $pm, $errCode, microtime(true) - $start);
                return GatewayErrorMapper::buildErrorResponse($errCode, $reference, $amount, strtoupper($currency));
            }

            $pi = $this->request('POST', '/v1/payment_intents', [
                'amount'                                     => (int)($amount * 100),
                'currency'                                   => $currency,
                'capture_method'                             => 'manual',
                'payment_method'                             => $pm['id'],
                'confirm'                                    => 'true',
                'payment_method_types[]'                     => 'card',
                'metadata[reference]'                        => $reference,
                'metadata[platform]'                         => 'diparma',
                'metadata[type]'                             => 'hold',
            ], $this->buildIdempotencyKey('hold_' . $reference, $amount));

            $status   = $pi['status'] ?? '';
            $piId     = $pi['id']     ?? '';
            $duration = microtime(true) - $start;

            if (in_array($status, ['requires_capture', 'succeeded'])) {
                $result = [
                    'success'        => true,
                    'status'         => 'authorized',
                    'transaction_id' => $piId,
                    'client_secret'  => $pi['client_secret'] ?? '',
                    'public_key'     => $this->publicKey,
                    'reference'      => $reference,
                    'amount'         => $amount,
                    'currency'       => strtoupper($currency),
                    'message'        => '✅ تم حجز المبلغ — لم يُخصم بعد',
                    'error_code'     => '',
                    'requires_3ds'   => false,
                    'redirect_url'   => '',
                    'decline_code'   => '',
                    'retryable'      => false,
                    'hard_block'     => false,
                ];
                GatewayLogger::log('stripe', 'hold', $payload, $result, '', $duration);
                return $result;
            }

            if ($status === 'requires_action' && !empty($pi['client_secret'])) {
                $result = [
                    'success'        => false,
                    'status'         => 'requires_3ds',
                    'transaction_id' => $piId,
                    'client_secret'  => $pi['client_secret'],
                    'public_key'     => $this->publicKey,
                    'reference'      => $reference,
                    'amount'         => $amount,
                    'currency'       => strtoupper($currency),
                    'message'        => 'يتطلب 3DS لتأكيد الحجز',
                    'error_code'     => '',
                    'requires_3ds'   => true,
                    'redirect_url'   => '',
                    'decline_code'   => '',
                    'retryable'      => false,
                    'hard_block'     => false,
                ];
                GatewayLogger::log('stripe', 'hold', $payload, $result, '', $duration);
                return $result;
            }

            $errCode = $this->normalizeError($pi);
            GatewayLogger::log('stripe', 'hold', $payload, $pi, $errCode, microtime(true) - $start);
            return GatewayErrorMapper::buildErrorResponse($errCode, $reference, $amount, strtoupper($currency),
                $pi['last_payment_error']['message'] ?? $pi['error']['message'] ?? $status);

        } catch (Exception $e) {
            GatewayLogger::log('stripe', 'hold', $payload, ['exception' => $e->getMessage()], 'NETWORK_ERROR', microtime(true) - $start);
            return GatewayErrorMapper::buildErrorResponse('NETWORK_ERROR', $reference, $amount, strtoupper($currency), $e->getMessage());
        }
    }

    // ══════════════════════════════════════════════════════════
    // CAPTURE
    // ══════════════════════════════════════════════════════════
    public function capture(string $transactionId, ?float $amount = null): array
    {
        $start  = microtime(true);
        $params = [];
        if ($amount !== null) {
            $params['amount_to_capture'] = (int)($amount * 100);
        }

        $res      = $this->request('POST', "/v1/payment_intents/$transactionId/capture", $params,
                      $this->buildIdempotencyKey('cap_' . $transactionId, $amount ?? 0));
        $status   = $res['status'] ?? '';
        $duration = microtime(true) - $start;

        if ($status === 'succeeded') {
            $captured = ($res['amount_received'] ?? $res['amount'] ?? 0) / 100;

            // استخراج Approval Code من CAPTURE response
            $authCode    = null;
            $rrn         = null;
            $cardNetwork = null;
            $chargeObj   = $res['charges']['data'][0] ?? null;
            if (!$chargeObj && !empty($res['latest_charge'])) {
                $lcId = $res['latest_charge'];
                if (is_string($lcId)) {
                    $chargeObj = $this->request('GET', "/v1/charges/{$lcId}");
                } elseif (is_array($lcId)) {
                    $chargeObj = $lcId;
                }
            }
            if (is_array($chargeObj)) {
                $cardDet    = $chargeObj['payment_method_details']['card'] ?? [];
                $authCode   = $cardDet['authorization_code'] ?? null;
                $cardNetwork= $cardDet['network']             ?? null;
                $rrn        = $chargeObj['balance_transaction'] ?? $chargeObj['id'] ?? null;
            }

            $result = [
                'success'        => true,
                'status'         => 'captured',
                'transaction_id' => $transactionId,
                'reference'      => $res['metadata']['reference'] ?? '',
                'amount'         => $captured,
                'currency'       => strtoupper($res['currency'] ?? ''),
                'message'        => "✅ تم التحصيل: $captured",
                'approval_code'  => $authCode,   // ← Approval Code الحقيقي
                'rrn'            => $rrn,
                'card_network'   => $cardNetwork,
                'error_code'     => '',
                'requires_3ds'   => false,
                'client_secret'  => '',
                'redirect_url'   => '',
                'decline_code'   => '',
                'retryable'      => false,
                'hard_block'     => false,
            ];
            GatewayLogger::log('stripe', 'capture', ['transaction_id' => $transactionId], $result, '', $duration);
            return $result;
        }

        $errCode = $this->normalizeError($res);
        GatewayLogger::log('stripe', 'capture', ['transaction_id' => $transactionId], $res, $errCode, $duration);
        return GatewayErrorMapper::buildErrorResponse($errCode, '', 0, '', $res['error']['message'] ?? "فشل التحصيل: $status");
    }

    // ══════════════════════════════════════════════════════════
    // CANCEL
    // ══════════════════════════════════════════════════════════
    public function cancel(string $transactionId, string $reason = 'requested_by_customer'): array
    {
        $start = microtime(true);
        $res   = $this->request('POST', "/v1/payment_intents/$transactionId/cancel", [
            'cancellation_reason' => $reason,
        ]);
        $status   = $res['status'] ?? '';
        $duration = microtime(true) - $start;

        if (in_array($status, ['canceled', 'cancelled'])) {
            $result = [
                'success'        => true,
                'status'         => 'cancelled',
                'transaction_id' => $transactionId,
                'reference'      => $res['metadata']['reference'] ?? '',
                'amount'         => ($res['amount'] ?? 0) / 100,
                'currency'       => strtoupper($res['currency'] ?? ''),
                'message'        => '✅ تم إلغاء الحجز',
                'error_code'     => '',
                'requires_3ds'   => false,
                'client_secret'  => '',
                'redirect_url'   => '',
                'decline_code'   => '',
                'retryable'      => false,
                'hard_block'     => false,
            ];
            GatewayLogger::log('stripe', 'cancel', ['transaction_id' => $transactionId], $result, '', $duration);
            return $result;
        }

        $errCode = $this->normalizeError($res);
        GatewayLogger::log('stripe', 'cancel', ['transaction_id' => $transactionId], $res, $errCode, $duration);
        return GatewayErrorMapper::buildErrorResponse($errCode, '', 0, '', $res['error']['message'] ?? 'فشل الإلغاء');
    }

    // ── مساعدات ──────────────────────────────────────────────

    private function validateCard(array $payload): array
    {
        $ccNumber = preg_replace('/\D/', '', $payload['card_number'] ?? $payload['cc_number'] ?? '');
        $ccExpiry = trim($payload['card_expiry'] ?? $payload['cc_expiry'] ?? '');
        $cvv2     = trim((string)($payload['cvv2'] ?? $payload['card_cvv'] ?? $payload['cc_cvv'] ?? ''));

        if (strlen($ccNumber) < 13 || strlen($ccNumber) > 19) {
            return ['valid' => false, 'message' => 'رقم البطاقة غير صالح'];
        }
        if (!preg_match('/^\d{3,4}$/', $cvv2)) {
            return ['valid' => false, 'message' => 'CVV غير صالح'];
        }

        $parts      = explode('/', $ccExpiry);
        $expMonth   = intval($parts[0] ?? 0);
        $expYearRaw = trim($parts[1] ?? '');
        $expYear    = strlen($expYearRaw) === 2 ? intval('20' . $expYearRaw) : intval($expYearRaw);

        if ($expMonth < 1 || $expMonth > 12) {
            return ['valid' => false, 'message' => 'شهر انتهاء البطاقة غير صالح'];
        }
        $exp = new DateTime("$expYear-$expMonth-01");
        $exp->modify('last day of this month');
        if ($exp < new DateTime()) {
            return ['valid' => false, 'message' => 'البطاقة منتهية الصلاحية'];
        }

        return ['valid' => true, 'data' => [$ccNumber, $expMonth, $expYear, $cvv2]];
    }

    private function request(string $method, string $path, array $params = [], string $idempotencyKey = ''): array
    {
        $ch = curl_init(self::API . $path);
        $headers = ['Content-Type: application/x-www-form-urlencoded'];
        if (!empty($idempotencyKey)) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERPWD        => $this->secretKey . ':',
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
        ]);
        if ($method !== 'GET' && !empty($params)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }
        $res = curl_exec($ch);
        curl_close($ch);
        return json_decode($res ?: '{}', true) ?: [];
    }
}
