<?php
/**
 * ============================================================
 * DI PARMA | PayPalService
 * تكامل PayPal SDK v6 — Orders API + Capture
 * ============================================================
 */

class PayPalService
{
    private static ?self $instance = null;
    private string $clientId;
    private string $secretKey;
    private string $baseUrl;
    private string $logFile;

    private function __construct()
    {
        $this->clientId  = getenv('PAYPAL_CLIENT_ID') ?: '';
        $this->secretKey = getenv('PAYPAL_CLIENT_SECRET') ?: (getenv('PAYPAL_SECRET') ?: '');
        $env             = strtolower(trim(getenv('PAYPAL_ENVIRONMENT') ?: 'sandbox'));
        $this->baseUrl   = in_array($env, ['live', 'production'], true)
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
        $this->logFile   = defined('LOGS_PATH') ? LOGS_PATH . '/paypal.log' : __DIR__ . '/../logs/paypal.log';
        if (!is_dir(dirname($this->logFile))) @mkdir(dirname($this->logFile), 0755, true);
    }

    public static function getInstance(): self
    {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    // ══════════════════════════════════════════════════════════
    // [1] Access Token
    // ══════════════════════════════════════════════════════════

    private function getAccessToken(): string
    {
        $ch = curl_init($this->baseUrl . '/v1/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => $this->clientId . ':' . $this->secretKey,
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            throw new RuntimeException('PayPal auth failed: ' . $res);
        }

        $data = json_decode($res, true);
        return $data['access_token'] ?? throw new RuntimeException('No access_token in PayPal response');
    }

    // ══════════════════════════════════════════════════════════
    // [2] Client Token (للـ SDK v6 Frontend)
    // ══════════════════════════════════════════════════════════

    public function getClientToken(string $returnUrl = ''): array
    {
        if (empty($this->clientId) || empty($this->secretKey)) {
            return ['success' => false, 'message' => 'PayPal credentials غير مضبوطة'];
        }

        try {
            $postData = 'grant_type=client_credentials&response_type=client_token';
            if (!empty($returnUrl)) {
                $postData .= '&domains[]=' . urlencode($returnUrl);
            }

            $ch = curl_init($this->baseUrl . '/v1/oauth2/token');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_USERPWD        => $this->clientId . ':' . $this->secretKey,
                CURLOPT_POSTFIELDS     => $postData,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
                CURLOPT_TIMEOUT        => 15,
            ]);
            $res  = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $data = json_decode($res, true);

            if ($code !== 200 || empty($data['access_token'])) {
                return ['success' => false, 'message' => 'فشل جلب client token'];
            }

            return [
                'success'      => true,
                'client_token' => $data['access_token'],
                'expires_in'   => $data['expires_in'] ?? 32400,
                'client_id'    => $this->clientId,
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ══════════════════════════════════════════════════════════
    // [3] إنشاء Order — يُستدعى من Frontend
    // ══════════════════════════════════════════════════════════

    public function createOrder(float $amount, string $currency, string $reference, array $options = []): array
    {
        if (empty($this->clientId) || empty($this->secretKey)) {
            return ['success' => false, 'message' => 'PayPal credentials غير مضبوطة'];
        }

        $siteUrl = defined('SITE_URL') ? SITE_URL : 'https://diparmas.com';

        try {
            $token = $this->getAccessToken();

            $intent = strtoupper($options['intent'] ?? 'CAPTURE');
            if (!in_array($intent, ['CAPTURE', 'AUTHORIZE'], true)) {
                $intent = 'CAPTURE';
            }

            $body = [
                'intent' => $intent,
                'purchase_units' => [[
                    'reference_id'  => $reference,
                    'amount'        => [
                        'currency_code' => strtoupper($currency),
                        'value'         => number_format($amount, 2, '.', ''),
                    ],
                    'description'   => $options['description'] ?? 'DI PARMA Payment',
                ]],
                'application_context' => [
                    'return_url'          => $siteUrl . '/crypto_confirm.php?ref=' . $reference . '&type=buy&gateway=paypal',
                    'cancel_url'          => $siteUrl . '/crypto.php?error=paypal_cancelled',
                    'brand_name'          => 'DI PARMA',
                    'locale'              => 'en-US',
                    'landing_page'        => 'NO_PREFERENCE',
                    'shipping_preference' => 'NO_SHIPPING',
                    'user_action'         => 'PAY_NOW',
                ],
            ];

            $response = $this->request('POST', '/v2/checkout/orders', $token, $body);

            if (empty($response['id'])) {
                $this->log("✗ createOrder failed: " . json_encode($response));
                return ['success' => false, 'message' => $response['message'] ?? 'فشل إنشاء PayPal Order'];
            }

            // رابط الموافقة
            $approveUrl = '';
            foreach ($response['links'] ?? [] as $link) {
                if ($link['rel'] === 'approve') {
                    $approveUrl = $link['href'];
                    break;
                }
            }

            $this->log("✓ Order created: {$response['id']} | $amount $currency");

            return [
                'success'     => true,
                'order_id'    => $response['id'],
                'approve_url' => $approveUrl,
                'status'      => $response['status'],
                'reference'   => $reference,
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ══════════════════════════════════════════════════════════
    // [4] Capture Order — بعد موافقة المستخدم
    // ══════════════════════════════════════════════════════════

    public function captureOrder(string $orderId): array
    {
        try {
            $token    = $this->getAccessToken();
            $response = $this->request('POST', "/v2/checkout/orders/$orderId/capture", $token, []);

            $status = $response['status'] ?? '';

            if ($status === 'COMPLETED') {
                $capture = $response['purchase_units'][0]['payments']['captures'][0] ?? [];
                $this->log("✓ Captured: $orderId | {$capture['amount']['value']} {$capture['amount']['currency_code']}");
                return [
                    'success'    => true,
                    'order_id'   => $orderId,
                    'capture_id' => $capture['id'] ?? '',
                    'status'     => 'completed',
                    'amount'     => floatval($capture['amount']['value'] ?? 0),
                    'currency'   => $capture['amount']['currency_code'] ?? '',
                    'reference'  => $response['purchase_units'][0]['reference_id'] ?? '',
                    'message'    => '✅ تم الدفع عبر PayPal بنجاح',
                ];
            }

            return ['success' => false, 'status' => $status, 'message' => "PayPal status: $status"];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function authorizeOrder(string $orderId): array
    {
        try {
            $token    = $this->getAccessToken();
            $response = $this->request('POST', "/v2/checkout/orders/$orderId/authorize", $token, []);
            $authorization = $response['purchase_units'][0]['payments']['authorizations'][0] ?? [];

            if (($response['status'] ?? '') === 'COMPLETED' && !empty($authorization['id'])) {
                $this->log("✓ Authorized: $orderId | {$authorization['id']}");
                return [
                    'success'          => true,
                    'order_id'         => $orderId,
                    'authorization_id' => $authorization['id'],
                    'status'           => 'authorized',
                    'amount'           => floatval($authorization['amount']['value'] ?? 0),
                    'currency'         => $authorization['amount']['currency_code'] ?? '',
                    'reference'        => $response['purchase_units'][0]['reference_id'] ?? '',
                    'message'          => 'تم تفويض الدفع عبر PayPal بنجاح',
                ];
            }

            return ['success' => false, 'status' => $response['status'] ?? '', 'message' => 'PayPal authorization failed'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function captureAuthorization(string $authorizationId, ?float $amount = null, string $currency = 'USD'): array
    {
        try {
            $token = $this->getAccessToken();
            $body = $amount !== null ? ['amount' => [
                'currency_code' => strtoupper($currency),
                'value' => number_format($amount, 2, '.', ''),
            ]] : [];
            $response = $this->request('POST', "/v2/payments/authorizations/" . rawurlencode($authorizationId) . '/capture', $token, $body);
            $capture = !empty($response['id']) ? $response : [];

            if (($response['status'] ?? '') === 'COMPLETED' && !empty($capture['id'])) {
                return [
                    'success'    => true,
                    'capture_id' => $capture['id'],
                    'status'     => 'completed',
                    'amount'     => floatval($capture['amount']['value'] ?? 0),
                    'currency'   => $capture['amount']['currency_code'] ?? '',
                    'message'    => 'تم تحصيل التفويض عبر PayPal بنجاح',
                ];
            }

            return ['success' => false, 'status' => $response['status'] ?? '', 'message' => 'PayPal capture failed'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getOrder(string $orderId): array
    {
        if ($orderId === '') return [];

        try {
            return $this->request('GET', '/v2/checkout/orders/' . rawurlencode($orderId), $this->getAccessToken());
        } catch (Exception $e) {
            $this->log('getOrder failed: ' . $e->getMessage());
            return [];
        }
    }

    // ══════════════════════════════════════════════════════════
    // [5] التحقق من Webhook
    // ══════════════════════════════════════════════════════════

    public function verifyWebhook(array $headers, string $rawBody, string $webhookId): bool
    {
        if (empty($webhookId) || empty($rawBody)) return false;

        try {
            $token = $this->getAccessToken();
            $body  = [
                'auth_algo'         => $headers['PAYPAL-AUTH-ALGO']         ?? '',
                'cert_url'          => $headers['PAYPAL-CERT-URL']          ?? '',
                'transmission_id'   => $headers['PAYPAL-TRANSMISSION-ID']   ?? '',
                'transmission_sig'  => $headers['PAYPAL-TRANSMISSION-SIG']  ?? '',
                'transmission_time' => $headers['PAYPAL-TRANSMISSION-TIME'] ?? '',
                'webhook_id'        => $webhookId,
                'webhook_event'     => json_decode($rawBody, true),
            ];

            $response = $this->request('POST', '/v1/notifications/verify-webhook-signature', $token, $body);
            return ($response['verification_status'] ?? '') === 'SUCCESS';
        } catch (Exception $e) {
            return false;
        }
    }

    // ── HTTP Helper ──────────────────────────────────────────

    private function request(string $method, string $path, string $token, array $body = []): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'PayPal-Request-Id: ' . uniqid('diparma_', true),
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
        ]);

        if (!empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return json_decode($res ?: '{}', true) ?: [];
    }

    private function log(string $msg): void
    {
        @file_put_contents($this->logFile, '[' . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
    }
}
