<?php
/**
 * ============================================================
 * DI PARMA | BraintreeAdapter
 * بديل PayPal المباشر — يقبل بطاقات Visa/Mastercard/Amex
 * بدون redirect أو popup
 * ============================================================
 * Braintree مملوك لـ PayPal — يمكن تحويل المبالغ لـ PayPal
 * يدعم: 2D (MOTO) / 3D Secure / HOLD / CAPTURE / CANCEL
 *
 * المتغيرات في .env:
 *   BRAINTREE_MERCHANT_ID
 *   BRAINTREE_PUBLIC_KEY
 *   BRAINTREE_PRIVATE_KEY
 *   BRAINTREE_ENVIRONMENT  (sandbox | production)
 * ============================================================
 */

require_once __DIR__ . '/GatewayAdapterInterface.php';
require_once __DIR__ . '/GatewayErrorMapper.php';
require_once __DIR__ . '/GatewayLogger.php';

final class BraintreeAdapter implements GatewayAdapterInterface
{
    private string $merchantId;
    private string $publicKey;
    private string $privateKey;
    private string $baseUrl;
    private string $environment;

    public function __construct()
    {
        $this->merchantId  = getenv('BRAINTREE_MERCHANT_ID')  ?: '';
        $this->publicKey   = getenv('BRAINTREE_PUBLIC_KEY')   ?: '';
        $this->privateKey  = getenv('BRAINTREE_PRIVATE_KEY')  ?: '';
        $this->environment = getenv('BRAINTREE_ENVIRONMENT')  ?: '';
        $this->baseUrl     = $this->environment === 'production'
            ? 'https://api.braintreegateway.com:443'
            : 'https://api.sandbox.braintreegateway.com:443';
    }

    public function getName(): string { return 'braintree'; }

    public function supports(string $mode): bool
    {
        return in_array(strtoupper($mode), ['2D','3D','HOLD','CAPTURE','CANCEL']);
    }

    public function normalizeError(array $rawResponse): string
    {
        return GatewayErrorMapper::fromBraintree($rawResponse);
    }

    public function buildIdempotencyKey(string $reference, float $amount): string
    {
        return hash('sha256', 'bt_' . $reference . '|' . $amount . '|' . getenv('ENCRYPTION_KEY'));
    }

    // ══════════════════════════════════════════════════════════
    // CHARGE — 2D أو 3D
    // ══════════════════════════════════════════════════════════
    public function charge(array $payload): array
    {
        if (empty($this->merchantId) || empty($this->publicKey) || empty($this->privateKey)) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR',
                $payload['reference'] ?? '', 0, '',
                'BRAINTREE_MERCHANT_ID أو BRAINTREE_PUBLIC_KEY أو BRAINTREE_PRIVATE_KEY غير مضبوط');
        }

        $mode      = strtoupper($payload['processing_mode'] ?? '3D');
        $amount    = floatval($payload['amount']   ?? 0);
        $currency  = strtoupper($payload['currency'] ?? 'USD');
        $reference = $payload['reference'] ?? uniqid('bt_', true);

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
            // بناء XML للـ Braintree REST API
            $body = $this->buildSaleXml([
                'amount'          => number_format($amount, 2, '.', ''),
                'card_number'     => $ccNumber,
                'exp_month'       => sprintf('%02d', $expMonth),
                'exp_year'        => (string)$expYear,
                'cvv'             => $cvv2,
                'order_id'        => $reference,
                'channel'         => 'diparma',
                'submit_for_settlement' => true,
                // 2D: تعطيل 3DS
                'three_d_secure_pass_thru' => $mode === '3D',
                'customer_name'   => $payload['name']  ?? 'Customer',
                'customer_email'  => $payload['email'] ?? '',
            ]);

            $res      = $this->request('POST', '/merchants/' . $this->merchantId . '/transactions', $body);
            $duration = microtime(true) - $start;

            $status = $res['status'] ?? '';
            $id     = $res['id']     ?? '';

            if (in_array($status, ['authorized', 'submitted_for_settlement', 'settling', 'settled'])) {
                $result = [
                    'success'        => true,
                    'status'         => 'completed',
                    'transaction_id' => $id,
                    'reference'      => $reference,
                    'amount'         => $amount,
                    'currency'       => $currency,
                    'message'        => "✅ تم الدفع {$mode} عبر Braintree/PayPal",
                    'error_code'     => '',
                    'requires_3ds'   => false,
                    'client_secret'  => '',
                    'redirect_url'   => '',
                    'decline_code'   => '',
                    'retryable'      => false,
                    'hard_block'     => false,
                ];
                GatewayLogger::log('braintree', "charge[$mode]", $payload, $result, '', $duration);
                return $result;
            }

            $errCode = $this->normalizeError($res);
            GatewayLogger::log('braintree', "charge[$mode]", $payload, $res, $errCode, $duration);
            return GatewayErrorMapper::buildErrorResponse($errCode, $reference, $amount, $currency,
                $res['processorResponseText'] ?? $res['status'] ?? 'فشل الدفع');

        } catch (Exception $e) {
            GatewayLogger::log('braintree', "charge[$mode]", $payload,
                ['exception' => $e->getMessage()], 'NETWORK_ERROR', microtime(true) - $start);
            return GatewayErrorMapper::buildErrorResponse('NETWORK_ERROR', $reference, $amount, $currency, $e->getMessage());
        }
    }

    // ══════════════════════════════════════════════════════════
    // HOLD — submitForSettlement: false
    // ══════════════════════════════════════════════════════════
    public function hold(array $payload): array
    {
        if (empty($this->merchantId)) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $payload['reference'] ?? '');
        }

        $amount    = floatval($payload['amount']   ?? 0);
        $currency  = strtoupper($payload['currency'] ?? 'USD');
        $reference = $payload['reference'] ?? uniqid('bth_', true);

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
            $body = $this->buildSaleXml([
                'amount'                 => number_format($amount, 2, '.', ''),
                'card_number'            => $ccNumber,
                'exp_month'              => sprintf('%02d', $expMonth),
                'exp_year'               => (string)$expYear,
                'cvv'                    => $cvv2,
                'order_id'               => $reference,
                'channel'                => 'diparma',
                'submit_for_settlement'  => false,  // ← HOLD
                'customer_name'          => $payload['name']  ?? 'Customer',
                'customer_email'         => $payload['email'] ?? '',
            ]);

            $res      = $this->request('POST', '/merchants/' . $this->merchantId . '/transactions', $body);
            $duration = microtime(true) - $start;
            $status   = $res['status'] ?? '';
            $id       = $res['id']     ?? '';

            if ($status === 'authorized') {
                $result = [
                    'success'        => true,
                    'status'         => 'authorized',
                    'transaction_id' => $id,
                    'reference'      => $reference,
                    'amount'         => $amount,
                    'currency'       => $currency,
                    'message'        => '✅ تم حجز المبلغ عبر Braintree',
                    'error_code'     => '',
                    'requires_3ds'   => false,
                    'client_secret'  => '',
                    'redirect_url'   => '',
                    'decline_code'   => '',
                    'retryable'      => false,
                    'hard_block'     => false,
                ];
                GatewayLogger::log('braintree', 'hold', $payload, $result, '', $duration);
                return $result;
            }

            $errCode = $this->normalizeError($res);
            GatewayLogger::log('braintree', 'hold', $payload, $res, $errCode, $duration);
            return GatewayErrorMapper::buildErrorResponse($errCode, $reference, $amount, $currency,
                $res['processorResponseText'] ?? "فشل الحجز: $status");

        } catch (Exception $e) {
            return GatewayErrorMapper::buildErrorResponse('NETWORK_ERROR', $reference, $amount, $currency, $e->getMessage());
        }
    }

    // ══════════════════════════════════════════════════════════
    // CAPTURE — submitForSettlement
    // ══════════════════════════════════════════════════════════
    public function capture(string $transactionId, ?float $amount = null): array
    {
        $start = microtime(true);
        try {
            $body = $amount !== null
                ? '<submit-for-settlement><amount>' . number_format($amount, 2, '.', '') . '</amount></submit-for-settlement>'
                : '';

            $res      = $this->request('PUT',
                '/merchants/' . $this->merchantId . '/transactions/' . $transactionId . '/submit_for_settlement',
                $body);
            $duration = microtime(true) - $start;
            $status   = $res['status'] ?? '';

            if (in_array($status, ['submitted_for_settlement', 'settling', 'settled'])) {
                $result = [
                    'success'        => true,
                    'status'         => 'captured',
                    'transaction_id' => $transactionId,
                    'reference'      => $res['orderId'] ?? '',
                    'amount'         => floatval($res['amount'] ?? $amount ?? 0),
                    'currency'       => '',
                    'message'        => '✅ تم تحصيل المبلغ عبر Braintree',
                    'error_code'     => '',
                    'requires_3ds'   => false,
                    'client_secret'  => '',
                    'redirect_url'   => '',
                    'decline_code'   => '',
                    'retryable'      => false,
                    'hard_block'     => false,
                ];
                GatewayLogger::log('braintree', 'capture', ['transaction_id' => $transactionId], $result, '', $duration);
                return $result;
            }

            $errCode = $this->normalizeError($res);
            GatewayLogger::log('braintree', 'capture', ['transaction_id' => $transactionId], $res, $errCode, $duration);
            return GatewayErrorMapper::buildErrorResponse($errCode, '', 0, '', "فشل التحصيل: $status");

        } catch (Exception $e) {
            return GatewayErrorMapper::buildErrorResponse('NETWORK_ERROR', '', 0, '', $e->getMessage());
        }
    }

    // ══════════════════════════════════════════════════════════
    // CANCEL — void
    // ══════════════════════════════════════════════════════════
    public function cancel(string $transactionId, string $reason = 'requested_by_customer'): array
    {
        $start = microtime(true);
        try {
            $res      = $this->request('PUT',
                '/merchants/' . $this->merchantId . '/transactions/' . $transactionId . '/void', '');
            $duration = microtime(true) - $start;
            $status   = $res['status'] ?? '';

            if ($status === 'voided') {
                $result = [
                    'success'        => true,
                    'status'         => 'cancelled',
                    'transaction_id' => $transactionId,
                    'reference'      => '',
                    'amount'         => 0,
                    'currency'       => '',
                    'message'        => '✅ تم إلغاء العملية عبر Braintree',
                    'error_code'     => '',
                    'requires_3ds'   => false,
                    'client_secret'  => '',
                    'redirect_url'   => '',
                    'decline_code'   => '',
                    'retryable'      => false,
                    'hard_block'     => false,
                ];
                GatewayLogger::log('braintree', 'cancel', ['transaction_id' => $transactionId], $result, '', $duration);
                return $result;
            }

            $errCode = $this->normalizeError($res);
            GatewayLogger::log('braintree', 'cancel', ['transaction_id' => $transactionId], $res, $errCode, $duration);
            return GatewayErrorMapper::buildErrorResponse($errCode, '', 0, '', "فشل الإلغاء: $status");

        } catch (Exception $e) {
            return GatewayErrorMapper::buildErrorResponse('NETWORK_ERROR', '', 0, '', $e->getMessage());
        }
    }

    // ── مساعدات ──────────────────────────────────────────────

    private function buildSaleXml(array $p): string
    {
        $settle = ($p['submit_for_settlement'] ?? true) ? 'true' : 'false';
        return '<?xml version="1.0" encoding="UTF-8"?>
<transaction>
  <type>sale</type>
  <amount>' . htmlspecialchars($p['amount']) . '</amount>
  <order-id>' . htmlspecialchars($p['order_id']) . '</order-id>
  <channel>' . htmlspecialchars($p['channel']) . '</channel>
  <options>
    <submit-for-settlement>' . $settle . '</submit-for-settlement>
  </options>
  <credit-card>
    <number>' . htmlspecialchars($p['card_number']) . '</number>
    <expiration-month>' . htmlspecialchars($p['exp_month']) . '</expiration-month>
    <expiration-year>' . htmlspecialchars($p['exp_year']) . '</expiration-year>
    <cvv>' . htmlspecialchars($p['cvv']) . '</cvv>
    <cardholder-name>' . htmlspecialchars($p['customer_name'] ?? 'Customer') . '</cardholder-name>
  </credit-card>
  <customer>
    <email>' . htmlspecialchars($p['customer_email'] ?? '') . '</email>
  </customer>
</transaction>';
    }

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

    private function request(string $method, string $path, string $body = ''): array
    {
        $ch = curl_init($this->baseUrl . '/v1' . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_USERPWD        => $this->publicKey . ':' . $this->privateKey,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/xml',
                'Accept: application/xml',
                'X-ApiVersion: 6',
            ],
        ]);
        if (!empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $res  = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['status' => 'error', 'message' => $err];
        }

        // تحويل XML إلى array
        return $this->xmlToArray($res ?: '<transaction/>');
    }

    private function xmlToArray(string $xml): array
    {
        try {
            $obj = @simplexml_load_string($xml);
            if (!$obj) return ['status' => 'error', 'message' => 'invalid XML'];
            return json_decode(json_encode($obj), true) ?: [];
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
}
