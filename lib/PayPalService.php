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
        $env             = strtolower(trim(getenv('PAYPAL_ENVIRONMENT') ?: 'live'));
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
                    'cancel_url'          => $options['cancel_url'] ?? ($siteUrl . '/checkout_router.php?error=paypal_cancelled'),
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
            $orderStatus = strtoupper((string)($response['status'] ?? ''));

            if (in_array($orderStatus, ['APPROVED', 'COMPLETED'], true) && !empty($authorization['id'])) {
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

            return ['success' => false, 'status' => $orderStatus ?: ($response['status'] ?? ''), 'message' => 'PayPal authorization failed'];
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

    /**
     * Direct card charge/authorize (Advanced Card Processing / MOTO).
     * $intent: CAPTURE | AUTHORIZE
     */
    public function processCard(array $payload, string $intent = 'CAPTURE'): array
    {
        if (empty($this->clientId) || empty($this->secretKey)) {
            return ['success' => false, 'message' => 'PayPal credentials غير مضبوطة', 'error_code' => 'GATEWAY_ERROR'];
        }

        $intent = strtoupper($intent) === 'AUTHORIZE' ? 'AUTHORIZE' : 'CAPTURE';
        $amount = round(floatval($payload['amount'] ?? 0), 2);
        $currency = strtoupper(trim((string)($payload['currency'] ?? 'USD')));
        $reference = trim((string)($payload['reference'] ?? ''));
        $cardNumber = preg_replace('/\D/', '', (string)($payload['card_number'] ?? $payload['cc_number'] ?? ''));
        $cvv = trim((string)($payload['cvv2'] ?? $payload['card_cvv'] ?? $payload['cc_cvv'] ?? ''));
        $expiry = $this->normalizeCardExpiry((string)($payload['card_expiry'] ?? $payload['cc_expiry'] ?? ''));
        $name = trim((string)($payload['name'] ?? $payload['card_name'] ?? 'Customer'));

        if ($amount <= 0) {
            return ['success' => false, 'message' => 'المبلغ غير صالح', 'error_code' => 'GATEWAY_ERROR'];
        }
        if (strlen($cardNumber) < 13 || $expiry === '' || !preg_match('/^\d{3,4}$/', $cvv)) {
            return ['success' => false, 'message' => 'بيانات البطاقة غير مكتملة', 'error_code' => 'INVALID_CARD'];
        }

        try {
            $token = $this->getAccessToken();
            $body = [
                'intent' => $intent,
                'payment_source' => [
                    'card' => [
                        'name' => $name !== '' ? $name : 'Customer',
                        'number' => $cardNumber,
                        'expiry' => $expiry,
                        'security_code' => $cvv,
                    ],
                ],
                'purchase_units' => [[
                    'reference_id' => $reference !== '' ? $reference : ('PP-' . strtoupper(bin2hex(random_bytes(6)))),
                    'custom_id' => $reference,
                    'description' => $payload['transaction_label'] ?? ($payload['description'] ?? 'DI PARMA Payment'),
                    'amount' => [
                        'currency_code' => $currency,
                        'value' => number_format($amount, 2, '.', ''),
                    ],
                ]],
            ];

            $response = $this->request('POST', '/v2/checkout/orders', $token, $body, [
                'PayPal-Request-Id: ' . ($reference !== '' ? $reference : uniqid('ppcard_', true)),
            ]);

            return $this->formatCardOrderResponse($response, $intent, $reference, $amount, $currency);
        } catch (Exception $e) {
            $this->log('✗ processCard failed: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'error_code' => 'NETWORK_ERROR'];
        }
    }

    public function voidAuthorization(string $authorizationId): array
    {
        try {
            $token = $this->getAccessToken();
            $response = $this->request(
                'POST',
                '/v2/payments/authorizations/' . rawurlencode($authorizationId) . '/void',
                $token,
                []
            );
            $status = strtoupper((string)($response['status'] ?? ''));
            if (in_array($status, ['VOIDED', 'COMPLETED'], true) || ($response['_http_code'] ?? 0) === 204) {
                return [
                    'success' => true,
                    'status' => 'cancelled',
                    'transaction_id' => $authorizationId,
                    'message' => 'تم إلغاء تفويض PayPal',
                ];
            }
            return ['success' => false, 'message' => $response['message'] ?? 'PayPal void failed', 'raw' => $response];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
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

    private function request(string $method, string $path, string $token, array $body = [], array $extraHeaders = []): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $hasRequestId = false;
        foreach ($extraHeaders as $header) {
            if (stripos((string)$header, 'PayPal-Request-Id:') === 0) {
                $hasRequestId = true;
                break;
            }
        }
        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ];
        if (!$hasRequestId) {
            $headers[] = 'PayPal-Request-Id: ' . uniqid('diparma_', true);
        }
        $headers = array_merge($headers, $extraHeaders);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
        ]);

        if (!empty($body) || in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($res ?: '{}', true);
        if (!is_array($decoded)) {
            $decoded = [];
        }
        $decoded['_http_code'] = (int)$code;
        if ($res !== false && $res !== '' && $decoded === ['_http_code' => (int)$code] && trim($res) !== '' && trim($res) !== '{}') {
            $decoded['raw_body'] = $res;
        }
        return $decoded;
    }

    private function normalizeCardExpiry(string $expiry): string
    {
        $expiry = trim(str_replace([' ', '-'], '/', $expiry));
        if (preg_match('/^(20\d{2})\/(0[1-9]|1[0-2])$/', $expiry, $m)) {
            return $m[1] . '-' . $m[2];
        }
        if (preg_match('/^(0[1-9]|1[0-2])\/(\d{2}|\d{4})$/', $expiry, $m)) {
            $year = strlen($m[2]) === 2 ? ('20' . $m[2]) : $m[2];
            return $year . '-' . $m[1];
        }
        return '';
    }

    private function formatCardOrderResponse(array $response, string $intent, string $reference, float $amount, string $currency): array
    {
        $status = strtoupper((string)($response['status'] ?? ''));
        $orderId = (string)($response['id'] ?? '');
        $payments = $response['purchase_units'][0]['payments'] ?? [];
        $capture = $payments['captures'][0] ?? [];
        $authorization = $payments['authorizations'][0] ?? [];
        $paymentId = (string)($capture['id'] ?? $authorization['id'] ?? $orderId);
        $processor = $capture['processor_response'] ?? $authorization['processor_response'] ?? [];
        $approvalCode = (string)($processor['avs_code'] ?? $capture['id'] ?? $authorization['id'] ?? '');

        if (in_array($status, ['COMPLETED', 'APPROVED'], true) && $paymentId !== '') {
            $this->log("✓ Card {$intent}: {$orderId} | {$amount} {$currency}");
            return [
                'success' => true,
                'status' => $intent === 'AUTHORIZE' ? 'authorized' : 'completed',
                'transaction_id' => $paymentId,
                'payment_id' => $paymentId,
                'order_id' => $orderId,
                'authorization_id' => $authorization['id'] ?? '',
                'approval_code' => $approvalCode,
                'gateway_approval_code' => $approvalCode,
                'reference' => $reference,
                'amount' => $amount,
                'currency' => $currency,
                'message' => $intent === 'AUTHORIZE' ? 'تم تفويض البطاقة عبر PayPal' : 'تم الدفع عبر PayPal بنجاح',
                'raw' => $response,
            ];
        }

        $actionLink = '';
        foreach ($response['links'] ?? [] as $link) {
            if (($link['rel'] ?? '') === 'payer-action') {
                $actionLink = $link['href'] ?? '';
                break;
            }
        }
        if ($status === 'PAYER_ACTION_REQUIRED' && $actionLink !== '') {
            return [
                'success' => false,
                'status' => 'requires_3ds',
                'requires_3ds' => true,
                'redirect_url' => $actionLink,
                'order_id' => $orderId,
                'reference' => $reference,
                'amount' => $amount,
                'currency' => $currency,
                'message' => 'PayPal requires additional card verification',
                'raw' => $response,
            ];
        }

        $issue = strtoupper((string)($response['details'][0]['issue'] ?? $response['name'] ?? ''));
        $description = (string)($response['details'][0]['description'] ?? $response['message'] ?? 'PayPal card payment failed');
        $this->log('✗ processCard: ' . json_encode($response));

        return [
            'success' => false,
            'status' => 'declined',
            'message' => $description,
            'error_code' => $issue !== '' ? $issue : 'CARD_DECLINED',
            'order_id' => $orderId,
            'reference' => $reference,
            'amount' => $amount,
            'currency' => $currency,
            'raw' => $response,
        ];
    }

    private function log(string $msg): void
    {
        @file_put_contents($this->logFile, '[' . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
    }
}
