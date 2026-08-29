<?php
/**
 * ============================================================
 * DI PARMA | PayTabsAdapter v1
 * بوابة PayTabs — تدعم 2D/MOTO/3D/HOLD/CAPTURE/CANCEL
 * ============================================================
 * tran_class mapping:
 *   2D / MOTO → 'moti'  (Mail Order / Telephone Order)
 *   3D        → 'ecom'  (E-Commerce — يفعّل 3DS تلقائياً)
 *   HOLD      → tran_type='auth'  + tran_class حسب الوضع
 *   CAPTURE   → tran_type='capture'
 *   CANCEL    → tran_type='void'
 * ============================================================
 */

require_once __DIR__ . '/GatewayAdapterInterface.php';
require_once __DIR__ . '/GatewayErrorMapper.php';
require_once __DIR__ . '/GatewayLogger.php';

final class PayTabsAdapter implements GatewayAdapterInterface
{
    private string $serverKey;
    private string $profileId;
    private string $baseUrl;

    // نقاط نهاية PayTabs حسب المنطقة
    private const ENDPOINTS = [
        'SAU' => 'https://secure.paytabs.sa',
        'ARE' => 'https://secure.paytabs.com',
        'EGY' => 'https://secure-egypt.paytabs.com',
        'OMN' => 'https://secure-oman.paytabs.com',
        'JOR' => 'https://secure-jordan.paytabs.com',
        'IRQ' => 'https://secure-iraq.paytabs.com',
        'PAK' => 'https://secure-pakistan.paytabs.com',
        'QAT' => 'https://secure.paytabs.com',
        'GLOBAL' => 'https://secure.paytabs.com',
    ];

    public function __construct()
    {
        $this->serverKey = getenv('PAYTABS_SERVER_KEY') ?: '';
        $this->profileId = getenv('PAYTABS_PROFILE_ID') ?: '';
        $region          = strtoupper(getenv('PAYTABS_REGION') ?: 'ARE');
        $this->baseUrl   = self::ENDPOINTS[$region] ?? self::ENDPOINTS['ARE'];
    }

    public function getName(): string { return 'paytabs'; }

    public function supports(string $mode): bool
    {
        return in_array(strtoupper($mode), ['2D', '3D', 'HOLD', 'CAPTURE', 'CANCEL']);
    }

    public function normalizeError(array $rawResponse): string
    {
        return GatewayErrorMapper::fromPayTabs($rawResponse);
    }

    public function buildIdempotencyKey(string $reference, float $amount): string
    {
        return hash('sha256', 'pt_' . $reference . '|' . $amount . '|' . getenv('ENCRYPTION_KEY'));
    }

    // ══════════════════════════════════════════════════════════
    // CHARGE — 2D (MOTO) أو 3D (ecom)
    // ══════════════════════════════════════════════════════════
    public function charge(array $payload): array
    {
        if (empty($this->serverKey) || empty($this->profileId)) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $payload['reference'] ?? '',
                0, '', 'PAYTABS_SERVER_KEY أو PAYTABS_PROFILE_ID غير مضبوط');
        }

        $mode      = strtoupper($payload['processing_mode'] ?? '3D');
        $amount    = floatval($payload['amount']   ?? 0);
        $currency  = strtoupper($payload['currency'] ?? 'USD');
        $reference = $payload['reference'] ?? uniqid('pt_', true);
        $siteUrl   = defined('SITE_URL') ? SITE_URL : 'https://diparmas.com';

        if ($amount <= 0) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $reference, $amount, $currency, 'المبلغ غير صالح');
        }

        $validation = $this->validateCard($payload);
        if (!$validation['valid']) {
            return GatewayErrorMapper::buildErrorResponse('INVALID_CARD', $reference, $amount, $currency, $validation['message']);
        }
        [$ccNumber, $expMonth, $expYear, $cvv2] = $validation['data'];

        // PayTabs: expiry format = MM/YYYY
        $expiry = sprintf('%02d/%04d', $expMonth, $expYear);

        $body = [
            'profile_id'       => $this->profileId,
            'tran_type'        => 'sale',
            'tran_class'       => $mode === '2D' ? 'moti' : 'ecom',
            'cart_id'          => $reference,
            'cart_description' => 'DI PARMA Payment',
            'cart_amount'      => $amount,
            'cart_currency'    => $currency,
            'payment_methods'  => ['creditcard'],
            'card_details'     => [
                'pan'          => $ccNumber,
                'expiry_month' => sprintf('%02d', $expMonth),
                'expiry_year'  => substr((string)$expYear, -2),
                'cvv'          => $cvv2,
            ],
            'customer_details' => [
                'name'  => $payload['name']  ?? 'Customer',
                'email' => $payload['email'] ?? 'customer@diparmas.com',
                'ip'    => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ],
            'callback'         => $siteUrl . '/api/orchestrator.php?action=confirm&ref=' . $reference,
            'return'           => $siteUrl . '/crypto_confirm.php?ref=' . $reference,
        ];

        // إضافة approval_code إن وجد (للـ MOTO)
        if (!empty($payload['approval_code'])) {
            $body['payment_info'] = ['approval_code' => $payload['approval_code']];
        }

        $start    = microtime(true);
        $res      = $this->request('/payment/request', $body);
        $duration = microtime(true) - $start;

        $tranRef = $res['tran_ref']    ?? '';
        $respSt  = strtolower($res['payment_result']['response_status'] ?? '');
        $respMsg = $res['payment_result']['response_message'] ?? '';
        $respCode= $res['payment_result']['response_code']    ?? '';

        // A = Authorized/Success, H = Hold
        if ($respSt === 'a') {
            $result = [
                'success'        => true,
                'status'         => 'completed',
                'transaction_id' => $tranRef,
                'reference'      => $reference,
                'amount'         => $amount,
                'currency'       => $currency,
                'message'        => "✅ تم الدفع {$mode} عبر PayTabs",
                'error_code'     => '',
                'requires_3ds'   => false,
                'client_secret'  => '',
                'redirect_url'   => '',
                'decline_code'   => '',
                'retryable'      => false,
                'hard_block'     => false,
            ];
            GatewayLogger::log('paytabs', "charge[$mode]", $payload, $result, '', $duration);
            return $result;
        }

        // P = Pending / يحتاج 3DS redirect
        if ($respSt === 'p' && !empty($res['redirect_url'])) {
            $result = [
                'success'        => false,
                'status'         => 'requires_3ds',
                'transaction_id' => $tranRef,
                'reference'      => $reference,
                'amount'         => $amount,
                'currency'       => $currency,
                'message'        => 'يتطلب 3D Secure',
                'error_code'     => '',
                'requires_3ds'   => true,
                'client_secret'  => '',
                'redirect_url'   => $res['redirect_url'],
                'decline_code'   => '',
                'retryable'      => false,
                'hard_block'     => false,
            ];
            GatewayLogger::log('paytabs', "charge[$mode]", $payload, $result, '', $duration);
            return $result;
        }

        // فشل
        $errCode = $this->normalizeError($res);
        GatewayLogger::log('paytabs', "charge[$mode]", $payload, $res, $errCode, $duration);
        return GatewayErrorMapper::buildErrorResponse($errCode, $reference, $amount, $currency,
            $respMsg ?: "response_code: $respCode");
    }

    // ══════════════════════════════════════════════════════════
    // HOLD — tran_type: auth
    // ══════════════════════════════════════════════════════════
    public function hold(array $payload): array
    {
        if (empty($this->serverKey) || empty($this->profileId)) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $payload['reference'] ?? '');
        }

        $mode      = strtoupper($payload['processing_mode'] ?? '3D');
        $amount    = floatval($payload['amount']   ?? 0);
        $currency  = strtoupper($payload['currency'] ?? 'USD');
        $reference = $payload['reference'] ?? uniqid('pth_', true);
        $siteUrl   = defined('SITE_URL') ? SITE_URL : 'https://diparmas.com';

        if ($amount <= 0) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $reference, $amount, $currency, 'المبلغ غير صالح');
        }

        $validation = $this->validateCard($payload);
        if (!$validation['valid']) {
            return GatewayErrorMapper::buildErrorResponse('INVALID_CARD', $reference, $amount, $currency, $validation['message']);
        }
        [$ccNumber, $expMonth, $expYear, $cvv2] = $validation['data'];

        $body = [
            'profile_id'       => $this->profileId,
            'tran_type'        => 'auth',       // ← HOLD
            'tran_class'       => $mode === '2D' ? 'moti' : 'ecom',
            'cart_id'          => $reference,
            'cart_description' => 'DI PARMA Hold',
            'cart_amount'      => $amount,
            'cart_currency'    => $currency,
            'payment_methods'  => ['creditcard'],
            'card_details'     => [
                'pan'          => $ccNumber,
                'expiry_month' => sprintf('%02d', $expMonth),
                'expiry_year'  => substr((string)$expYear, -2),
                'cvv'          => $cvv2,
            ],
            'customer_details' => [
                'name'  => $payload['name']  ?? 'Customer',
                'email' => $payload['email'] ?? 'customer@diparmas.com',
                'ip'    => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ],
            'callback' => $siteUrl . '/api/orchestrator.php?action=confirm_hold&ref=' . $reference,
            'return'   => $siteUrl . '/crypto_confirm.php?ref=' . $reference,
        ];

        $start    = microtime(true);
        $res      = $this->request('/payment/request', $body);
        $duration = microtime(true) - $start;

        $tranRef = $res['tran_ref'] ?? '';
        $respSt  = strtolower($res['payment_result']['response_status'] ?? '');

        if ($respSt === 'a') {
            $result = [
                'success'        => true,
                'status'         => 'authorized',
                'transaction_id' => $tranRef,
                'reference'      => $reference,
                'amount'         => $amount,
                'currency'       => $currency,
                'message'        => '✅ تم حجز المبلغ عبر PayTabs',
                'error_code'     => '',
                'requires_3ds'   => false,
                'client_secret'  => '',
                'redirect_url'   => '',
                'decline_code'   => '',
                'retryable'      => false,
                'hard_block'     => false,
            ];
            GatewayLogger::log('paytabs', 'hold', $payload, $result, '', $duration);
            return $result;
        }

        if ($respSt === 'p' && !empty($res['redirect_url'])) {
            $result = [
                'success'        => false,
                'status'         => 'requires_3ds',
                'transaction_id' => $tranRef,
                'reference'      => $reference,
                'amount'         => $amount,
                'currency'       => $currency,
                'message'        => 'يتطلب 3DS لتأكيد الحجز',
                'error_code'     => '',
                'requires_3ds'   => true,
                'client_secret'  => '',
                'redirect_url'   => $res['redirect_url'],
                'decline_code'   => '',
                'retryable'      => false,
                'hard_block'     => false,
            ];
            GatewayLogger::log('paytabs', 'hold', $payload, $result, '', $duration);
            return $result;
        }

        $errCode = $this->normalizeError($res);
        GatewayLogger::log('paytabs', 'hold', $payload, $res, $errCode, $duration);
        return GatewayErrorMapper::buildErrorResponse($errCode, $reference, $amount, $currency,
            $res['payment_result']['response_message'] ?? 'فشل الحجز');
    }

    // ══════════════════════════════════════════════════════════
    // CAPTURE — tran_type: capture
    // ══════════════════════════════════════════════════════════
    public function capture(string $transactionId, ?float $amount = null): array
    {
        $body = [
            'profile_id'       => $this->profileId,
            'tran_type'        => 'capture',
            'tran_class'       => 'ecom',
            'cart_id'          => 'cap_' . $transactionId,
            'cart_description' => 'DI PARMA Capture',
            'cart_amount'      => $amount ?? 0,
            'cart_currency'    => 'USD',
            'tran_ref'         => $transactionId,
        ];

        $start    = microtime(true);
        $res      = $this->request('/payment/request', $body);
        $duration = microtime(true) - $start;

        $respSt = strtolower($res['payment_result']['response_status'] ?? '');

        if ($respSt === 'a') {
            $result = [
                'success'        => true,
                'status'         => 'captured',
                'transaction_id' => $res['tran_ref'] ?? $transactionId,
                'reference'      => $res['cart_id'] ?? '',
                'amount'         => $amount ?? 0,
                'currency'       => '',
                'message'        => '✅ تم تحصيل المبلغ عبر PayTabs',
                'error_code'     => '',
                'requires_3ds'   => false,
                'client_secret'  => '',
                'redirect_url'   => '',
                'decline_code'   => '',
                'retryable'      => false,
                'hard_block'     => false,
            ];
            GatewayLogger::log('paytabs', 'capture', ['transaction_id' => $transactionId], $result, '', $duration);
            return $result;
        }

        $errCode = $this->normalizeError($res);
        GatewayLogger::log('paytabs', 'capture', ['transaction_id' => $transactionId], $res, $errCode, $duration);
        return GatewayErrorMapper::buildErrorResponse($errCode, '', 0, '',
            $res['payment_result']['response_message'] ?? 'فشل التحصيل');
    }

    // ══════════════════════════════════════════════════════════
    // CANCEL — tran_type: void
    // ══════════════════════════════════════════════════════════
    public function cancel(string $transactionId, string $reason = 'requested_by_customer'): array
    {
        $body = [
            'profile_id'       => $this->profileId,
            'tran_type'        => 'void',
            'tran_class'       => 'ecom',
            'cart_id'          => 'void_' . $transactionId,
            'cart_description' => $reason,
            'cart_amount'      => 0,
            'cart_currency'    => 'USD',
            'tran_ref'         => $transactionId,
        ];

        $start    = microtime(true);
        $res      = $this->request('/payment/request', $body);
        $duration = microtime(true) - $start;

        $respSt = strtolower($res['payment_result']['response_status'] ?? '');

        if ($respSt === 'a') {
            $result = [
                'success'        => true,
                'status'         => 'cancelled',
                'transaction_id' => $transactionId,
                'reference'      => '',
                'amount'         => 0,
                'currency'       => '',
                'message'        => '✅ تم إلغاء الحجز عبر PayTabs',
                'error_code'     => '',
                'requires_3ds'   => false,
                'client_secret'  => '',
                'redirect_url'   => '',
                'decline_code'   => '',
                'retryable'      => false,
                'hard_block'     => false,
            ];
            GatewayLogger::log('paytabs', 'cancel', ['transaction_id' => $transactionId], $result, '', $duration);
            return $result;
        }

        $errCode = $this->normalizeError($res);
        GatewayLogger::log('paytabs', 'cancel', ['transaction_id' => $transactionId], $res, $errCode, $duration);
        return GatewayErrorMapper::buildErrorResponse($errCode, '', 0, '',
            $res['payment_result']['response_message'] ?? 'فشل الإلغاء');
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

    private function request(string $path, array $body): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . $this->serverKey,
                'Content-Type: application/json',
            ],
        ]);
        $res  = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['payment_result' => ['response_status' => 'E', 'response_message' => $err]];
        }

        return json_decode($res ?: '{}', true) ?: ['payment_result' => ['response_status' => 'E']];
    }
}
