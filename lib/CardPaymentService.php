<?php
/**
 * ============================================================
 * DI PARMA | CardPaymentService
 * قبول بطاقات Visa/Mastercard عبر Nuvei / Stripe / Checkout
 * ============================================================
 */

require_once __DIR__ . '/Adapters/GatewayAdapterFactory.php';

class CardPaymentService
{
    private static ?self $instance = null;
    private Database $db;
    private string $provider;  // stripe | checkout | myfatoorah
    private string $logFile;

    // Stripe
    private string $stripeSecret;
    private string $stripeWebhookSecret;
    private string $stripePublicKey;

    // Checkout.com
    private string $checkoutSecret;
    private string $checkoutPublicKey;

    private function __construct()
    {
        $this->db                  = db();
        $this->provider            = getenv('CARD_PROVIDER')          ?: 'nuvei';
        $this->stripeSecret        = getenv('STRIPE_SECRET_KEY')      ?: '';
        $this->stripeWebhookSecret = getenv('STRIPE_WEBHOOK_SECRET')  ?: '';
        $this->stripePublicKey     = getenv('STRIPE_PUBLIC_KEY')      ?: '';
        $this->checkoutSecret      = getenv('CHECKOUT_API_KEY')       ?: '';
        $this->checkoutPublicKey   = getenv('CHECKOUT_SECRET_KEY')    ?: '';
        $this->logFile = defined('LOGS_PATH') ? LOGS_PATH . '/card_payment.log' : __DIR__ . '/../logs/card_payment.log';
        if (!is_dir(dirname($this->logFile))) @mkdir(dirname($this->logFile), 0755, true);
    }

    public static function getInstance(): self
    {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    // ── إنشاء عملية دفع ─────────────────────────────────────

    /**
     * إنشاء Payment Intent وإرجاع بيانات الـ Checkout
     */
    public function createPayment(array $payload): array
    {
        $reference  = $payload['reference']       ?? generateReference('CARD');
        $amount     = floatval($payload['amount'] ?? 0);
        $currency   = strtolower($payload['currency'] ?? 'usd');
        $email      = $payload['email']            ?? '';
        $userId     = intval($payload['user_id']   ?? 0);
        $metadata   = $payload['metadata']         ?? [];

        if ($amount <= 0) return ['success' => false, 'message' => 'مبلغ غير صالح'];

        // اختيار المستخدم يتقدم على إعداد .env
        $provider = !empty($payload['card_provider']) ? strtolower($payload['card_provider']) : $this->provider;

        return match($provider) {
            'nuvei'       => $this->createNuveiPayment($reference, $amount, $currency, $email, $userId, $metadata),
            'stripe'      => $this->createStripePayment($reference, $amount, $currency, $email, $userId, $metadata),
            'checkout'    => $this->createCheckoutPayment($reference, $amount, $currency, $email, $userId, $metadata),
            'myfatoorah'  => $this->createMyFatoorahPayment($reference, $amount, $currency, $email, $userId, $metadata),
            'paypal'      => $this->createPayPalPayment($reference, $amount, $currency, $email, $userId, $metadata),
            'binance','binance_pay' => $this->createBinancePayment($reference, $amount, $currency, $email, $userId, $metadata),
            'gate_io','gateio'     => $this->createGateIOPayment($reference, $amount, $currency, $email, $userId, $metadata),
            'whop'                 => $this->createWhopPayment($reference, $amount, $currency, $email, $userId, $metadata),
            default       => ['success' => false, 'message' => 'Payment provider غير مضبوط: ' . $provider],
        };
    }

    private function createNuveiPayment(
        string $reference, float $amount, string $currency,
        string $email, int $userId, array $metadata
    ): array {
        $adapter = GatewayAdapterFactory::resolve('nuvei');
        $payload = GatewayAdapterFactory::normalizePayload([
            'amount'        => $amount,
            'currency'     => $currency,
            'reference'    => $reference,
            'email'        => $email,
            'name'         => $metadata['name'] ?? 'Customer',
            'card_number'  => $metadata['card_number'] ?? '',
            'card_expiry'  => $metadata['card_expiry'] ?? '',
            'card_cvv'     => $metadata['card_cvv'] ?? '',
            'processing_mode' => $metadata['processing_mode'] ?? '3D',
        ]);

        $result = $adapter->charge($payload);
        if ($result['success']) {
            $result['provider'] = 'nuvei';
        }
        return $result;
    }

    // ── Stripe ───────────────────────────────────────────────

    private function createStripePayment(
        string $reference, float $amount, string $currency,
        string $email, int $userId, array $metadata
    ): array {
        if (empty($this->stripeSecret)) {
            return ['success' => false, 'message' => 'STRIPE_SECRET_KEY غير مضبوط في .env'];
        }

        // تحويل المبلغ للـ cents
        $amountCents = (int)($amount * 100);

        $params = [
            'amount'   => $amountCents,
            'currency' => $currency,
            'metadata' => array_merge($metadata, [
                'reference' => $reference,
                'user_id'   => $userId,
                'platform'  => 'diparma',
            ]),
            'receipt_email'          => $email,
            'payment_method_types[]' => 'card',
        ];

        $response = $this->stripeRequest('POST', '/v1/payment_intents', $params);

        if (empty($response['id'])) {
            return ['success' => false, 'message' => $response['error']['message'] ?? 'Stripe error'];
        }

        // حفظ في DB
        $this->db->update('transactions', [
            'gateway'          => 'stripe',
            'gateway_response' => json_encode(['payment_intent_id' => $response['id']]),
        ], ['reference' => $reference]);

        $this->log("✓ Stripe PI created: {$response['id']} | $amount $currency");

        return [
            'success'           => true,
            'provider'          => 'stripe',
            'payment_intent_id' => $response['id'],
            'client_secret'     => $response['client_secret'],
            'public_key'        => $this->stripePublicKey,
            'amount'            => $amount,
            'currency'          => strtoupper($currency),
            'reference'         => $reference,
        ];
    }

    // ── Checkout.com ─────────────────────────────────────────

    private function createCheckoutPayment(
        string $reference, float $amount, string $currency,
        string $email, int $userId, array $metadata
    ): array {
        if (empty($this->checkoutSecret)) {
            return ['success' => false, 'message' => 'CHECKOUT_API_KEY غير مضبوط في .env'];
        }

        $amountCents = (int)($amount * 100);
        $siteUrl     = defined('SITE_URL') ? SITE_URL : 'https://diparmas.com';

        $body = [
            'amount'      => $amountCents,
            'currency'    => strtoupper($currency),
            'reference'   => $reference,
            'customer'    => ['email' => $email],
            'success_url' => $siteUrl . '/crypto_confirm.php?ref=' . $reference . '&type=buy',
            'failure_url' => $siteUrl . '/crypto.php?error=payment_failed',
            'metadata'    => array_merge($metadata, ['user_id' => $userId]),
        ];

        $response = $this->checkoutRequest('POST', '/payment-links', $body);

        if (empty($response['id'])) {
            return ['success' => false, 'message' => $response['error_type'] ?? 'Checkout.com error'];
        }

        return [
            'success'      => true,
            'provider'     => 'checkout',
            'payment_id'   => $response['id'],
            'checkout_url' => $response['_links']['redirect']['href'] ?? '',
            'amount'       => $amount,
            'currency'     => strtoupper($currency),
            'reference'    => $reference,
        ];
    }

    // ── MyFatoorah (دعم إقليمي) ──────────────────────────────

    private function createMyFatoorahPayment(
        string $reference, float $amount, string $currency,
        string $email, int $userId, array $metadata
    ): array {
        $apiKey  = getenv('MYFAOORAH_API_KEY') ?: '';
        $siteUrl = defined('SITE_URL') ? SITE_URL : 'https://diparmas.com';
        $env     = getenv('MYFAOORAH_ENVIRONMENT') ?: 'sandbox';
        $baseUrl = $env === 'live'
            ? 'https://api.myfatoorah.com'
            : 'https://apitest.myfatoorah.com';

        if (empty($apiKey)) {
            return ['success' => false, 'message' => 'MYFAOORAH_API_KEY غير مضبوط'];
        }

        $body = [
            'NotificationOption'   => 'LNK',
            'InvoiceValue'         => $amount,
            'CurrencyIso'          => strtoupper($currency),
            'CustomerEmail'        => $email,
            'CallBackUrl'          => $siteUrl . '/crypto_confirm.php?ref=' . $reference . '&type=buy',
            'ErrorUrl'             => $siteUrl . '/crypto.php?error=payment_failed',
            'CustomerReference'    => $reference,
            'UserDefinedField'     => json_encode(['user_id' => $userId]),
        ];

        $ch = curl_init($baseUrl . '/v2/SendPayment');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
        ]);
        $res = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($res ?: '{}', true);
        if (!empty($data['Data']['InvoiceURL'])) {
            return [
                'success'      => true,
                'provider'     => 'myfatoorah',
                'invoice_id'   => $data['Data']['InvoiceId'],
                'checkout_url' => $data['Data']['InvoiceURL'],
                'amount'       => $amount,
                'currency'     => strtoupper($currency),
                'reference'    => $reference,
            ];
        }

        return ['success' => false, 'message' => $data['Message'] ?? 'MyFatoorah error'];
    }

    // ── Webhook Verification ─────────────────────────────────

    /**
     * التحقق من Webhook Stripe وإرجاع بيانات الحدث
     */
    public function verifyStripeWebhook(string $rawBody, string $signature): ?array
    {
        if (empty($this->stripeWebhookSecret)) return json_decode($rawBody, true);

        // Stripe Webhook signature verification
        $parts = [];
        foreach (explode(',', $signature) as $part) {
            [$k, $v] = explode('=', $part, 2);
            $parts[$k] = $v;
        }

        $timestamp = $parts['t'] ?? 0;
        $sig       = $parts['v1'] ?? '';
        $expected  = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $this->stripeWebhookSecret);

        if (!hash_equals($expected, $sig)) return null;
        return json_decode($rawBody, true);
    }

    // ── HTTP Helpers ─────────────────────────────────────────

    private function stripeRequest(string $method, string $path, array $params): array
    {
        $ch = curl_init('https://api.stripe.com' . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERPWD        => $this->stripeSecret . ':',
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_CUSTOMREQUEST  => $method,
        ]);
        if ($method !== 'GET') {
            // Flatten nested arrays for Stripe
            curl_setopt($ch, CURLOPT_POSTFIELDS, $this->flattenParams($params));
        }
        $res = curl_exec($ch);
        curl_close($ch);
        return json_decode($res ?: '{}', true) ?: [];
    }

    private function checkoutRequest(string $method, string $path, array $body): array
    {
        $ch = curl_init('https://api.checkout.com' . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->checkoutSecret,
            ],
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS    => json_encode($body),
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        return json_decode($res ?: '{}', true) ?: [];
    }

    private function flattenParams(array $params, string $prefix = ''): string
    {
        $result = [];
        foreach ($params as $key => $value) {
            $fullKey = $prefix ? "{$prefix}[{$key}]" : $key;
            if (is_array($value)) {
                $result[] = $this->flattenParams($value, $fullKey);
            } else {
                $result[] = urlencode($fullKey) . '=' . urlencode($value);
            }
        }
        return implode('&', $result);
    }

    // ── PayPal ───────────────────────────────────────────────

    private function createPayPalPayment(
        string $reference, float $amount, string $currency,
        string $email, int $userId, array $metadata
    ): array {
        $clientId  = getenv('PAYPAL_CLIENT_ID')     ?: '';
        $secret    = getenv('PAYPAL_CLIENT_SECRET') ?: getenv('PAYPAL_SECRET') ?: '';
        $siteUrl   = defined('SITE_URL') ? SITE_URL : 'https://diparmas.com';
        $sandbox   = (getenv('PAYPAL_ENVIRONMENT') ?: 'live') === 'sandbox';
        $base      = $sandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';

        if (empty($clientId) || empty($secret)) {
            return ['success' => false, 'message' => 'PayPal credentials غير مضبوطة في .env'];
        }

        // الحصول على access token
        $ch = curl_init($base . '/v1/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
            CURLOPT_USERPWD        => "$clientId:$secret",
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $tokenRes = json_decode(curl_exec($ch) ?: '{}', true);
        curl_close($ch);

        $token = $tokenRes['access_token'] ?? '';
        if (empty($token)) {
            return ['success' => false, 'message' => 'PayPal: فشل الحصول على access token'];
        }

        // إنشاء Order
        $orderBody = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $reference,
                'amount' => [
                    'currency_code' => strtoupper($currency),
                    'value'         => number_format($amount, 2, '.', ''),
                ],
                'custom_id' => $reference,
            ]],
            'application_context' => [
                'return_url' => $siteUrl . '/crypto_confirm.php?ref=' . $reference . '&type=buy&gateway=paypal',
                'cancel_url' => $siteUrl . '/crypto.php?error=payment_cancelled',
            ],
        ];

        $ch2 = curl_init($base . '/v2/checkout/orders');
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($orderBody),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_TIMEOUT => 15,
        ]);
        $orderRes = json_decode(curl_exec($ch2) ?: '{}', true);
        curl_close($ch2);

        $approveUrl = '';
        foreach ($orderRes['links'] ?? [] as $link) {
            if ($link['rel'] === 'approve') { $approveUrl = $link['href']; break; }
        }

        if (empty($orderRes['id'])) {
            return ['success' => false, 'message' => $orderRes['message'] ?? 'PayPal order error'];
        }

        $this->db->update('transactions', [
            'gateway'          => 'paypal',
            'gateway_response' => json_encode(['order_id' => $orderRes['id']]),
        ], ['reference' => $reference]);

        $this->log("✓ PayPal Order: {$orderRes['id']} | $amount $currency");

        return [
            'success'      => true,
            'provider'     => 'paypal',
            'order_id'     => $orderRes['id'],
            'checkout_url' => $approveUrl,
            'amount'       => $amount,
            'currency'     => strtoupper($currency),
            'reference'    => $reference,
        ];
    }

    // ── Binance Pay ──────────────────────────────────────────

    private function createBinancePayment(
        string $reference, float $amount, string $currency,
        string $email, int $userId, array $metadata
    ): array {
        $apiKey    = getenv('BINANCE_API_KEY') ?: getenv('EXCHANGE_API_KEY') ?: '';
        $secretKey = getenv('BINANCE_SECRET_KEY') ?: getenv('EXCHANGE_SECRET_KEY') ?: '';
        $siteUrl   = defined('SITE_URL') ? SITE_URL : 'https://diparmas.com';

        if (empty($apiKey) || empty($secretKey)) {
            return ['success' => false, 'message' => 'Binance API credentials غير مضبوطة في .env'];
        }

        $nonce     = substr(str_replace(['+','/','='], '', base64_encode(random_bytes(16))), 0, 32);
        $timestamp = round(microtime(true) * 1000);

        $body = [
            'env'            => ['terminalType' => 'WEB'],
            'merchantTradeNo'=> $reference,
            'orderAmount'    => round($amount, 2),
            'currency'       => strtoupper($currency),
            'description'    => 'DI PARMA Payment',
            'goodsDetails'   => [[
                'goodsType'  => '02',
                'goodsCategory' => 'Z000',
                'referenceGoodsId' => $reference,
                'goodsName'  => 'DI PARMA',
                'goodsUnitAmount' => ['currency' => strtoupper($currency), 'value' => number_format($amount,2,'.','')],
                'goodsQuantity' => '1',
            ]],
            'returnUrl'  => $siteUrl . '/crypto_confirm.php?ref=' . $reference . '&type=buy&gateway=binance',
            'cancelUrl'  => $siteUrl . '/crypto.php?error=payment_cancelled',
            'webhookUrl' => $siteUrl . '/api/webhook.php?gateway=binance',
        ];

        $payload = $timestamp . "\n" . $nonce . "\n" . json_encode($body) . "\n";
        $sig     = strtoupper(hash_hmac('sha512', $payload, $secretKey));

        $ch = curl_init('https://bpay.binanceapi.com/binancepay/openapi/v3/order');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'BinancePay-Timestamp: ' . $timestamp,
                'BinancePay-Nonce: ' . $nonce,
                'BinancePay-Certificate-SN: ' . $apiKey,
                'BinancePay-Signature: ' . $sig,
            ],
        ]);
        $res = json_decode(curl_exec($ch) ?: '{}', true);
        curl_close($ch);

        if (($res['status'] ?? '') !== 'SUCCESS') {
            return ['success' => false, 'message' => $res['errorMessage'] ?? 'Binance Pay error'];
        }

        $checkoutUrl = $res['data']['checkoutUrl'] ?? '';

        $this->db->update('transactions', [
            'gateway'          => 'binance',
            'gateway_response' => json_encode(['prepay_id' => $res['data']['prepayId'] ?? '']),
        ], ['reference' => $reference]);

        $this->log("✓ Binance Pay order: {$reference} | $amount $currency");

        return [
            'success'      => true,
            'provider'     => 'binance',
            'prepay_id'    => $res['data']['prepayId'] ?? '',
            'checkout_url' => $checkoutUrl,
            'qr_code'      => $res['data']['qrcodeLink'] ?? '',
            'amount'       => $amount,
            'currency'     => strtoupper($currency),
            'reference'    => $reference,
        ];
    }

    // ── Gate.io Pay ──────────────────────────────────────────

    private function createGateIOPayment(
        string $reference, float $amount, string $currency,
        string $email, int $userId, array $metadata
    ): array {
        $apiKey    = getenv('GATE_IO_API_KEY')    ?: '';
        $secretKey = getenv('GATE_IO_SECRET_KEY') ?: '';
        $siteUrl   = defined('SITE_URL') ? SITE_URL : 'https://diparmas.com';

        if (empty($apiKey) || empty($secretKey)) {
            return ['success' => false, 'message' => 'Gate.io API credentials غير مضبوطة في .env'];
        }

        $timestamp = time();
        $body = [
            'merchant_order_id' => $reference,
            'amount'            => number_format($amount, 2, '.', ''),
            'currency'          => strtoupper($currency),
            'subject'           => 'DI PARMA Payment',
            'return_url'        => $siteUrl . '/crypto_confirm.php?ref=' . $reference . '&type=buy&gateway=gate_io',
            'cancel_url'        => $siteUrl . '/crypto.php?error=payment_cancelled',
            'notify_url'        => $siteUrl . '/api/webhook.php?gateway=gate_io',
        ];

        $bodyStr = json_encode($body);
        $sig     = hash_hmac('sha512', "POST\n/api/v1/pay/orders\n\n" . hash('sha512', $bodyStr) . "\n" . $timestamp, $secretKey);

        $ch = curl_init('https://api.gateio.ws/api/v1/pay/orders');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $bodyStr,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'KEY: ' . $apiKey,
                'SIGN: ' . $sig,
                'Timestamp: ' . $timestamp,
            ],
        ]);
        $res = json_decode(curl_exec($ch) ?: '{}', true);
        curl_close($ch);

        if (empty($res['order_id'])) {
            return ['success' => false, 'message' => $res['message'] ?? $res['label'] ?? 'Gate.io Pay error'];
        }

        $this->db->update('transactions', [
            'gateway'          => 'gate_io',
            'gateway_response' => json_encode(['order_id' => $res['order_id']]),
        ], ['reference' => $reference]);

        $this->log("✓ Gate.io Pay order: {$res['order_id']} | $amount $currency");

        return [
            'success'      => true,
            'provider'     => 'gate_io',
            'order_id'     => $res['order_id'],
            'checkout_url' => $res['payment_url'] ?? '',
            'amount'       => $amount,
            'currency'     => strtoupper($currency),
            'reference'    => $reference,
        ];
    }

    // ── Whop ─────────────────────────────────────────────────

    private function createWhopPayment(
        string $reference, float $amount, string $currency,
        string $email, int $userId, array $metadata
    ): array {
        require_once __DIR__ . '/Adapters/WhopAdapter.php';
        $whop = new WhopAdapter();
        return $whop->createPaymentLink([
            'reference' => $reference,
            'amount'    => $amount,
            'currency'  => $currency,
            'email'     => $email,
            'user_id'   => $userId,
            'metadata'  => $metadata,
        ]);
    }

    private function log(string $msg): void
    {
        @file_put_contents($this->logFile, '[' . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
    }
}
