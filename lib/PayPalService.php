<?php
/**
 * ============================================================
 * DI PARMA | PayPalService
 * تكامل PayPal SDK v6 — Orders API + Capture
 * ============================================================
 */

if (class_exists('PayPalService', false)) {
    return;
}

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
        $this->baseUrl   = 'https://api-m.paypal.com';
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

    public function getAuthorization(string $authorizationId): array
    {
        return $this->paymentResource('GET', '/v2/payments/authorizations/' . rawurlencode($authorizationId), [], 'PayPal authorization lookup failed');
    }

    public function captureAuthorization(string $authorizationId, ?float $amount = null, string $currency = 'USD', array $options = []): array
    {
        $body = [];
        if ($amount !== null && $amount > 0) {
            $body['amount'] = [
                'currency_code' => strtoupper($currency),
                'value' => number_format($amount, 2, '.', ''),
            ];
        }
        if (array_key_exists('final_capture', $options)) {
            $body['final_capture'] = (bool) $options['final_capture'];
        }
        $invoice = trim((string) ($options['invoice_id'] ?? ''));
        if ($invoice !== '') {
            $body['invoice_id'] = substr($invoice, 0, 127);
        }
        $result = $this->paymentResource(
            'POST',
            '/v2/payments/authorizations/' . rawurlencode($authorizationId) . '/capture',
            $body,
            'PayPal capture failed'
        );
        if (!empty($result['success'])) {
            $result['capture_id'] = (string) ($result['id'] ?? '');
            $result['payment_id'] = $result['capture_id'];
            $result['transaction_id'] = $result['capture_id'];
            $result['message'] = 'تم تحصيل التفويض عبر PayPal بنجاح';
        }
        return $result;
    }

    public function reauthorizeAuthorization(string $authorizationId, ?float $amount = null, string $currency = 'USD'): array
    {
        $body = [];
        if ($amount !== null && $amount > 0) {
            $body['amount'] = [
                'currency_code' => strtoupper($currency),
                'value' => number_format($amount, 2, '.', ''),
            ];
        }
        $result = $this->paymentResource(
            'POST',
            '/v2/payments/authorizations/' . rawurlencode($authorizationId) . '/reauthorize',
            $body,
            'PayPal reauthorize failed'
        );
        if (!empty($result['success'])) {
            $result['authorization_id'] = (string) ($result['id'] ?? $authorizationId);
            $result['payment_id'] = $result['authorization_id'];
            $result['status'] = 'authorized';
            $result['message'] = 'تمت إعادة تفويض PayPal. الفترة الجديدة 3 أيام، وداخل 29 يوماً من التفويض الأصلي.';
        }
        return $result;
    }

    public function getCapture(string $captureId): array
    {
        return $this->paymentResource('GET', '/v2/payments/captures/' . rawurlencode($captureId), [], 'PayPal capture lookup failed');
    }

    public function refundCapture(string $captureId, ?float $amount = null, string $currency = 'USD', string $note = ''): array
    {
        $body = [];
        if ($amount !== null && $amount > 0) {
            $body['amount'] = [
                'currency_code' => strtoupper($currency),
                'value' => number_format($amount, 2, '.', ''),
            ];
        }
        $note = trim($note);
        if ($note !== '') {
            $body['note_to_payer'] = substr($note, 0, 255);
        }
        $result = $this->paymentResource(
            'POST',
            '/v2/payments/captures/' . rawurlencode($captureId) . '/refund',
            $body,
            'PayPal refund failed'
        );
        if (!empty($result['success'])) {
            $result['refund_id'] = (string) ($result['id'] ?? '');
            $result['status'] = strtolower((string) ($result['status'] ?? 'completed')) === 'pending' ? 'pending' : 'refunded';
            $result['message'] = 'تم استرجاع التحصيل عبر PayPal';
        }
        return $result;
    }

    public function getRefund(string $refundId): array
    {
        return $this->paymentResource('GET', '/v2/payments/refunds/' . rawurlencode($refundId), [], 'PayPal refund lookup failed');
    }

    public function listPaymentTokens(string $customerId, int $pageSize = 10, int $page = 1): array
    {
        $customerId = trim($customerId);
        if ($customerId === '') {
            return ['success' => false, 'message' => 'customer_id مطلوب'];
        }
        $query = http_build_query([
            'customer_id' => $customerId,
            'page_size' => max(1, min(20, $pageSize)),
            'page' => max(1, $page),
            'total_required' => 'true',
        ]);
        return $this->paymentResource('GET', '/v3/vault/payment-tokens?' . $query, [], 'PayPal payment tokens lookup failed');
    }

    public function getPaymentToken(string $tokenId): array
    {
        $tokenId = trim($tokenId);
        if ($tokenId === '') {
            return ['success' => false, 'message' => 'payment token id مطلوب'];
        }
        return $this->paymentResource('GET', '/v3/vault/payment-tokens/' . rawurlencode($tokenId), [], 'PayPal payment token lookup failed');
    }

    /**
     * Shipment Tracking v1. transaction_id is the PayPal capture id, not the order id.
     * Tracker id is {transaction_id}-{tracking_number}.
     */
    public function addTracker(string $transactionId, string $trackingNumber, string $status = 'SHIPPED', string $carrier = 'OTHER', array $options = []): array
    {
        $built = $this->trackerBody($transactionId, $trackingNumber, $status, $carrier, $options);
        if (empty($built['success'])) {
            return $built;
        }
        return $this->trackerResource('POST', '/v1/shipping/trackers', $built['body'], 'PayPal tracking add failed');
    }

    public function listTrackers(string $transactionId = '', string $trackingNumber = ''): array
    {
        $query = [];
        if (trim($transactionId) !== '') {
            $query['transaction_id'] = trim($transactionId);
        }
        if (trim($trackingNumber) !== '') {
            $query['tracking_number'] = trim($trackingNumber);
        }
        $path = '/v1/shipping/trackers' . ($query ? ('?' . http_build_query($query)) : '');
        return $this->trackerResource('GET', $path, [], 'PayPal tracking list failed');
    }

    public function getTracker(string $trackerId): array
    {
        $trackerId = trim($trackerId);
        if ($trackerId === '') {
            return ['success' => false, 'message' => 'tracker id مطلوب'];
        }
        return $this->trackerResource('GET', '/v1/shipping/trackers/' . rawurlencode($trackerId), [], 'PayPal tracking lookup failed');
    }

    public function updateTracker(string $trackerId, string $transactionId, string $trackingNumber, string $status, string $carrier = 'OTHER', array $options = []): array
    {
        $trackerId = trim($trackerId);
        if ($trackerId === '') {
            return ['success' => false, 'message' => 'tracker id مطلوب'];
        }
        $built = $this->trackerBody($transactionId, $trackingNumber, $status, $carrier, $options);
        if (empty($built['success'])) {
            return $built;
        }
        return $this->trackerResource('PUT', '/v1/shipping/trackers/' . rawurlencode($trackerId), $built['body'], 'PayPal tracking update failed');
    }

    private function trackerBody(string $transactionId, string $trackingNumber, string $status, string $carrier, array $options): array
    {
        $transactionId = trim($transactionId);
        $trackingNumber = trim($trackingNumber);
        $status = strtoupper(trim($status));
        $carrier = strtoupper(trim($carrier !== '' ? $carrier : 'OTHER'));
        $allowed = ['SHIPPED', 'ON_HOLD', 'DELIVERED', 'CANCELLED', 'SHIPMENT_CREATED', 'DROPPED_OFF', 'IN_TRANSIT', 'RETURNED', 'LABEL_PRINTED', 'ERROR', 'UNCONFIRMED', 'PICKUP_FAILED', 'DELIVERY_DELAYED', 'DELIVERY_SCHEDULED', 'DELIVERY_FAILED', 'INRETURN', 'IN_PROCESS', 'NEW', 'VOID', 'PROCESSED', 'NOT_SHIPPED', 'LOCAL_PICKUP'];
        if ($transactionId === '') {
            return ['success' => false, 'message' => 'transaction_id مطلوب، وهو معرف التحصيل في PayPal وليس رقم الطلب'];
        }
        if (!in_array($status, $allowed, true)) {
            return ['success' => false, 'message' => 'حالة التتبع غير معروفة'];
        }
        if ($trackingNumber === '' && $status !== 'CANCELLED') {
            return ['success' => false, 'message' => 'tracking_number مطلوب'];
        }
        if ($carrier === 'OTHER' && trim((string) ($options['carrier_name_other'] ?? '')) === '') {
            return ['success' => false, 'message' => 'carrier_name_other مطلوب عندما تكون شركة الشحن OTHER'];
        }
        $body = [
            'transaction_id' => $transactionId,
            'status' => $status,
            'carrier' => $carrier,
        ];
        if ($trackingNumber !== '') {
            $body['tracking_number'] = $trackingNumber;
        }
        if ($carrier === 'OTHER') {
            $body['carrier_name_other'] = substr(trim((string) $options['carrier_name_other']), 0, 64);
        }
        $shipmentDate = trim((string) ($options['shipment_date'] ?? ''));
        if ($shipmentDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $shipmentDate)) {
            $body['shipment_date'] = $shipmentDate;
        }
        return ['success' => true, 'body' => $body];
    }

    private function trackerResource(string $method, string $path, array $body, string $fallback): array
    {
        try {
            $response = $this->request($method, $path, $this->getAccessToken(), $body);
            $http = (int) ($response['_http_code'] ?? 0);
            if ($http >= 200 && $http < 300) {
                $id = (string) ($response['id'] ?? '');
                if ($id === '' && !empty($body['transaction_id']) && !empty($body['tracking_number'])) {
                    $id = $body['transaction_id'] . '-' . $body['tracking_number'];
                }
                return array_merge($response, [
                    'success' => true,
                    'id' => $id,
                    'tracker_id' => $id,
                    'status' => (string) ($response['status'] ?? ($body['status'] ?? '')),
                    'message' => 'تم تسجيل تتبع الشحنة في PayPal',
                ]);
            }
            return [
                'success' => false,
                'message' => $this->hostMessage($response, $fallback),
                'error_code' => strtoupper((string) ($response['details'][0]['issue'] ?? $response['name'] ?? '')),
                'raw' => $response,
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function paymentResource(string $method, string $path, array $body, string $fallback): array
    {
        try {
            $response = $this->request($method, $path, $this->getAccessToken(), $body, [
                'Prefer: return=representation',
            ]);
            $http = (int) ($response['_http_code'] ?? 0);
            $status = strtoupper((string) ($response['status'] ?? ''));
            $okStatus = in_array($status, ['COMPLETED', 'CREATED', 'PENDING', 'PARTIALLY_REFUNDED', 'REFUNDED', 'CAPTURED'], true);
            if (($http >= 200 && $http < 300) && ($status === '' || $okStatus || isset($response['payment_tokens']) || isset($response['id']))) {
                if ($status !== '' && !in_array($status, ['COMPLETED', 'CREATED', 'PENDING', 'PARTIALLY_REFUNDED', 'REFUNDED', 'CAPTURED', 'VOIDED', 'DENIED', 'DECLINED'], true) && $http !== 204) {
                    return [
                        'success' => false,
                        'status' => strtolower($status),
                        'message' => $this->hostMessage($response, $fallback),
                        'error_code' => strtoupper((string) ($response['details'][0]['issue'] ?? $response['name'] ?? '')),
                        'raw' => $response,
                    ];
                }
                return array_merge($response, [
                    'success' => true,
                    'status' => strtolower($status !== '' ? $status : 'completed'),
                    'id' => (string) ($response['id'] ?? ''),
                    'amount' => floatval($response['amount']['value'] ?? 0),
                    'currency' => (string) ($response['amount']['currency_code'] ?? ''),
                ]);
            }
            if ($http === 204) {
                return ['success' => true, 'status' => 'completed'];
            }
            return [
                'success' => false,
                'status' => strtolower($status),
                'message' => $this->hostMessage($response, $fallback),
                'error_code' => strtoupper((string) ($response['details'][0]['issue'] ?? $response['name'] ?? '')),
                'raw' => $response,
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function hostMessage(array $response, string $fallback): string
    {
        $description = trim((string) ($response['details'][0]['description'] ?? ''));
        $issue = trim((string) ($response['details'][0]['issue'] ?? ''));
        $message = trim((string) ($response['message'] ?? ''));
        $text = $description !== '' ? $description : ($message !== '' ? $message : $fallback);
        if ($issue !== '' && stripos($text, $issue) === false) {
            $text .= ' [' . $issue . ']';
        }
        $debug = trim((string) ($response['debug_id'] ?? ''));
        if ($debug !== '') {
            $text .= ' (debug_id: ' . $debug . ')';
        }
        return $text;
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
        $ppEnv = strtolower(trim((string)(getenv('PAYPAL_ENVIRONMENT') ?: 'live')));
        if (in_array($ppEnv, ['sandbox', 'test'], true)) {
            return ['success' => false, 'message' => 'PayPal sandbox مرفوض. استخدم بيئة live.', 'error_code' => 'GATEWAY_ERROR'];
        }

        $intent = strtoupper($intent) === 'AUTHORIZE' ? 'AUTHORIZE' : 'CAPTURE';
        $amount = round(floatval($payload['amount'] ?? 0), 2);
        $currency = strtoupper(trim((string)($payload['currency'] ?? 'USD')));
        $reference = trim((string)($payload['reference'] ?? ''));
        $cardNumber = preg_replace('/\D/', '', (string)($payload['card_number'] ?? $payload['cc_number'] ?? ''));
        $cvv = trim((string)($payload['cvv2'] ?? $payload['card_cvv'] ?? $payload['cc_cvv'] ?? ''));
        $expiry = $this->normalizeCardExpiry((string)($payload['card_expiry'] ?? $payload['cc_expiry'] ?? ''));
        $name = trim((string)($payload['name'] ?? $payload['card_name'] ?? 'Customer'));
        $cloudToken = trim((string)($payload['cloud_token'] ?? $payload['payment_token'] ?? $payload['vault_id'] ?? ''));

        if ($amount <= 0) {
            return ['success' => false, 'message' => 'المبلغ غير صالح', 'error_code' => 'GATEWAY_ERROR'];
        }
        $useVault = $cloudToken !== '' && strlen($cardNumber) < 13;
        $txnType = strtolower(trim((string)($payload['txn_type'] ?? '')));
        $authChannel = strtolower(trim((string)($payload['auth_channel'] ?? $payload['moto_channel'] ?? '')));
        $isMoto = in_array($txnType, ['online_sale_moto', 'offline_sale_moto'], true)
            || ($txnType === 'auth' && in_array($authChannel, ['online', 'offline'], true));
        $cvvOk = (bool) preg_match('/^\d{3,4}$/', $cvv);
        if (!$useVault && (strlen($cardNumber) < 13 || $expiry === '' || (!$isMoto && !$cvvOk))) {
            return ['success' => false, 'message' => 'بيانات البطاقة غير مكتملة', 'error_code' => 'INVALID_CARD'];
        }

        $mode = strtoupper(trim((string)($payload['processing_mode'] ?? $payload['security_mode'] ?? '')));
        $want3ds = ($mode === '3D' || $txnType === 'purchase_3d');
        require_once __DIR__ . '/CardScaService.php';
        if ($want3ds && !CardScaService::shouldChallenge($payload)) {
            $want3ds = false;
        }

        try {
            $token = $this->getAccessToken();
            if ($useVault) {
                $paymentSource = ['token' => ['id' => $cloudToken, 'type' => 'PAYMENT_METHOD_TOKEN']];
            } else {
                $card = [
                    'name' => $name !== '' ? $name : 'Customer',
                    'number' => $cardNumber,
                    'expiry' => $expiry,
                ];
                if ($cvvOk) {
                    $card['security_code'] = $cvv;
                }
                if ($isMoto && !$want3ds) {
                    $card['stored_credential'] = [
                        'payment_initiator' => 'MERCHANT',
                        'payment_type' => 'ONE_TIME',
                        'usage' => 'DERIVED',
                    ];
                }
                if ($want3ds) {
                    $returnBase = $this->publicBaseUrl() . '/checkout/paypal.php';
                    $qs = $reference !== '' ? ('&ref=' . rawurlencode($reference)) : '';
                    $card['attributes'] = [
                        'verification' => ['method' => 'SCA_ALWAYS'],
                    ];
                    $card['experience_context'] = [
                        'return_url' => $returnBase . '?paypal_3ds=ok' . $qs,
                        'cancel_url' => $returnBase . '?paypal_3ds=cancel' . $qs,
                    ];
                } elseif (!$isMoto && !CardScaService::shouldChallenge($payload)) {
                    $card['stored_credential'] = [
                        'payment_initiator' => 'CUSTOMER',
                        'payment_type' => 'UNSCHEDULED',
                        'usage' => 'SUBSEQUENT',
                    ];
                }
                $paymentSource = ['card' => $card];
            }
            $body = [
                'intent' => $intent,
                'payment_source' => $paymentSource,
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

            $formatted = $this->formatCardOrderResponse($response, $intent, $reference, $amount, $currency);
            if (!empty($formatted['success']) && empty($formatted['requires_3ds'])) {
                CardScaService::markCompleted($cardNumber, (string) ($payload['card_expiry'] ?? $payload['cc_expiry'] ?? ''), substr($cardNumber, -4), $reference);
            }
            return $formatted;
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

    public function listWebhooks(): array
    {
        return $this->managementCall('GET', '/v1/notifications/webhooks', [], 'PayPal webhook list failed');
    }

    public function createWebhook(string $url, array $eventNames = []): array
    {
        $url = trim($url);
        if ($url === '') {
            $site = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : 'https://diparmas.com';
            $url = $site . '/api/paypal.php?action=webhook';
        }
        if ($eventNames === []) {
            $eventNames = [
                'PAYMENT.CAPTURE.COMPLETED',
                'PAYMENT.CAPTURE.DENIED',
                'PAYMENT.CAPTURE.REFUNDED',
                'PAYMENT.AUTHORIZATION.CREATED',
                'PAYMENT.AUTHORIZATION.VOIDED',
            ];
        }
        $events = [];
        foreach ($eventNames as $name) {
            $name = strtoupper(trim((string) $name));
            if ($name !== '') {
                $events[] = ['name' => $name];
            }
        }
        if ($events === []) {
            return ['success' => false, 'message' => 'event_types مطلوبة'];
        }
        return $this->managementCall('POST', '/v1/notifications/webhooks', [
            'url' => $url,
            'event_types' => $events,
        ], 'PayPal webhook create failed');
    }

    public function getWebhook(string $webhookId): array
    {
        $webhookId = trim($webhookId);
        if ($webhookId === '') {
            return ['success' => false, 'message' => 'webhook_id مطلوب'];
        }
        return $this->managementCall('GET', '/v1/notifications/webhooks/' . rawurlencode($webhookId), [], 'PayPal webhook lookup failed');
    }

    public function updateWebhook(string $webhookId, array $patch): array
    {
        $webhookId = trim($webhookId);
        if ($webhookId === '' || $patch === []) {
            return ['success' => false, 'message' => 'webhook_id و patch مطلوبان'];
        }
        return $this->managementCall(
            'PATCH',
            '/v1/notifications/webhooks/' . rawurlencode($webhookId),
            $patch,
            'PayPal webhook update failed',
            ['Content-Type: application/json-patch+json']
        );
    }

    public function deleteWebhook(string $webhookId): array
    {
        $webhookId = trim($webhookId);
        if ($webhookId === '') {
            return ['success' => false, 'message' => 'webhook_id مطلوب'];
        }
        return $this->managementCall('DELETE', '/v1/notifications/webhooks/' . rawurlencode($webhookId), [], 'PayPal webhook delete failed');
    }

    public function listWebhookSubscriptions(string $webhookId): array
    {
        $webhookId = trim($webhookId);
        if ($webhookId === '') {
            return ['success' => false, 'message' => 'webhook_id مطلوب'];
        }
        return $this->managementCall('GET', '/v1/notifications/webhooks/' . rawurlencode($webhookId) . '/event-types', [], 'PayPal webhook events failed');
    }

    public function listAvailableWebhookEvents(): array
    {
        return $this->managementCall('GET', '/v1/notifications/webhooks-event-types', [], 'PayPal event catalog failed');
    }

    public function listWebProfiles(): array
    {
        return $this->managementCall('GET', '/v1/payment-experience/web-profiles', [], 'PayPal web profile list failed');
    }

    public function createWebProfile(array $profile): array
    {
        $name = trim((string) ($profile['name'] ?? ''));
        if ($name === '') {
            return ['success' => false, 'message' => 'اسم ملف تجربة الدفع مطلوب'];
        }
        $profile['name'] = $name;
        return $this->managementCall('POST', '/v1/payment-experience/web-profiles', $profile, 'PayPal web profile create failed');
    }

    public function getWebProfile(string $profileId): array
    {
        $profileId = trim($profileId);
        if ($profileId === '') {
            return ['success' => false, 'message' => 'web profile id مطلوب'];
        }
        return $this->managementCall('GET', '/v1/payment-experience/web-profiles/' . rawurlencode($profileId), [], 'PayPal web profile lookup failed');
    }

    public function replaceWebProfile(string $profileId, array $profile): array
    {
        $profileId = trim($profileId);
        if ($profileId === '' || trim((string) ($profile['name'] ?? '')) === '') {
            return ['success' => false, 'message' => 'web profile id والاسم مطلوبان'];
        }
        return $this->managementCall('PUT', '/v1/payment-experience/web-profiles/' . rawurlencode($profileId), $profile, 'PayPal web profile update failed');
    }

    public function deleteWebProfile(string $profileId): array
    {
        $profileId = trim($profileId);
        if ($profileId === '') {
            return ['success' => false, 'message' => 'web profile id مطلوب'];
        }
        return $this->managementCall('DELETE', '/v1/payment-experience/web-profiles/' . rawurlencode($profileId), [], 'PayPal web profile delete failed');
    }

    private function managementCall(string $method, string $path, array $body, string $fallback, array $headers = []): array
    {
        try {
            $response = $this->request($method, $path, $this->getAccessToken(), $body, $headers);
            $http = (int) ($response['_http_code'] ?? 0);
            if ($http >= 200 && $http < 300) {
                return array_merge($response, [
                    'success' => true,
                    'message' => 'ok',
                ]);
            }
            return [
                'success' => false,
                'message' => $this->hostMessage($response, $fallback),
                'error_code' => strtoupper((string) ($response['details'][0]['issue'] ?? $response['name'] ?? '')),
                'raw' => $response,
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function verifyWebhook(array $headers, string $rawBody, string $webhookId): bool
    {
        if (empty($webhookId) || empty($rawBody)) return false;

        $normalized = [];
        foreach ($headers as $key => $value) {
            $normalized[strtoupper(str_replace('_', '-', (string) $key))] = $value;
        }

        try {
            $token = $this->getAccessToken();
            $body  = [
                'auth_algo'         => $normalized['PAYPAL-AUTH-ALGO']         ?? '',
                'cert_url'          => $normalized['PAYPAL-CERT-URL']          ?? '',
                'transmission_id'   => $normalized['PAYPAL-TRANSMISSION-ID']   ?? '',
                'transmission_sig'  => $normalized['PAYPAL-TRANSMISSION-SIG']  ?? '',
                'transmission_time' => $normalized['PAYPAL-TRANSMISSION-TIME'] ?? '',
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
        $hasContentType = false;
        foreach ($extraHeaders as $header) {
            if (stripos((string) $header, 'Content-Type:') === 0) {
                $hasContentType = true;
                break;
            }
        }
        $headers = [
            'Authorization: Bearer ' . $token,
        ];
        if (!$hasContentType) {
            $headers[] = 'Content-Type: application/json';
        }
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

    private function publicBaseUrl(): string
    {
        $env = rtrim((string)(getenv('APP_URL') ?: getenv('SITE_URL') ?: getenv('PUBLIC_URL') ?: ''), '/');
        if ($env !== '') {
            return $env;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((string)($_SERVER['SERVER_PORT'] ?? '') === '443');
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        return ($https ? 'https://' : 'http://') . $host;
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
        $approvalCode = trim((string)($processor['auth_code'] ?? $processor['authorization_code'] ?? ''));
        $responseCode = trim((string)($processor['response_code'] ?? ''));

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
                'response_code' => $responseCode,
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
            'response_code' => $responseCode,
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
