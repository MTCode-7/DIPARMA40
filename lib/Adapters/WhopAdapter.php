<?php
/**
 * DI PARMA | WhopAdapter
 * ربط منصة Whop كبوابة دفع
 */
class WhopAdapter {

    private string $apiKey;
    private string $webhookSecret;
    private string $baseUrl = 'https://api.whop.com/v5';
    private string $logFile;

    public function __construct() {
        $this->apiKey        = getenv('WHOP_API_KEY')        ?: '';
        $this->webhookSecret = getenv('WHOP_WEBHOOK_SECRET') ?: '';
        $this->logFile       = defined('LOGS_PATH') ? LOGS_PATH . '/whop.log' : __DIR__ . '/../../logs/whop.log';
        if (!is_dir(dirname($this->logFile))) @mkdir(dirname($this->logFile), 0755, true);
    }

    // ── إنشاء رابط دفع ────────────────────────────────────
    public function createPaymentLink(array $payload): array {
        if (empty($this->apiKey)) {
            return ['success' => false, 'message' => 'WHOP_API_KEY غير مضبوط'];
        }

        $amount    = floatval($payload['amount']    ?? 0);
        $currency  = strtolower($payload['currency'] ?? 'usd');
        $reference = $payload['reference']           ?? '';
        $email     = $payload['email']               ?? '';
        $siteUrl   = defined('SITE_URL') ? SITE_URL : 'https://diparmas.com';

        if ($amount <= 0) return ['success' => false, 'message' => 'مبلغ غير صالح'];

        $body = [
            'amount'       => intval($amount * 100), // cents
            'currency'     => $currency,
            'metadata'     => ['reference' => $reference, 'platform' => 'diparma'],
            'redirect_url' => $siteUrl . '/crypto_confirm.php?ref=' . $reference . '&gateway=whop',
        ];

        $res = $this->request('POST', '/payments', $body);

        if (!empty($res['id'])) {
            $this->log("✓ Whop payment created: {$res['id']} | $amount $currency");
            return [
                'success'      => true,
                'provider'     => 'whop',
                'payment_id'   => $res['id'],
                'checkout_url' => $res['checkout_url'] ?? $res['payment_url'] ?? '',
                'amount'       => $amount,
                'currency'     => strtoupper($currency),
                'reference'    => $reference,
            ];
        }

        return ['success' => false, 'message' => $res['message'] ?? $res['error'] ?? 'Whop error'];
    }

    // ── التحقق من حالة دفع ────────────────────────────────
    public function getPayment(string $paymentId): array {
        return $this->request('GET', "/payments/{$paymentId}");
    }

    // ── معالجة Webhook ────────────────────────────────────
    public function handleWebhook(string $rawBody, string $signature): array {
        // التحقق من التوقيع
        if (!empty($this->webhookSecret)) {
            $expected = hash_hmac('sha256', $rawBody, $this->webhookSecret);
            if (!hash_equals($expected, $signature)) {
                return ['success' => false, 'message' => 'توقيع غير صالح'];
            }
        }

        $event = json_decode($rawBody, true);
        if (empty($event)) return ['success' => false, 'message' => 'payload غير صالح'];

        $type = $event['event'] ?? $event['type'] ?? '';
        $data = $event['data'] ?? $event;

        $this->log("Webhook: $type | " . json_encode($data));

        switch ($type) {
            case 'invoice_paid':
            case 'payment.completed':
                return $this->onPaymentPaid($data);

            case 'membership_activated':
                return $this->onMembershipActivated($data);

            case 'invoice_created':
                return ['success' => true, 'message' => 'invoice_created received'];

            default:
                return ['success' => true, 'message' => "event $type ignored"];
        }
    }

    // ── دفع مكتمل ─────────────────────────────────────────
    private function onPaymentPaid(array $data): array {
        $ref = $data['metadata']['reference']
            ?? $data['reference']
            ?? $data['id']
            ?? '';

        if (empty($ref)) return ['success' => false, 'message' => 'reference مفقود'];

        // تحديث حالة المعاملة
        try {
            $db = db();
            $db->update('transactions', [
                'status'           => 'completed',
                'gateway_response' => json_encode($data),
                'updated_at'       => date('Y-m-d H:i:s'),
            ], ['reference' => $ref]);

            $this->log("✓ Payment confirmed: $ref");

            // إرسال USDT إذا كانت معاملة شراء كريبتو
            $txn = $db->find('transactions', ['reference' => $ref]);
            if ($txn) {
                require_once __DIR__ . '/../PaymentOrchestrator.php';
                PaymentOrchestrator::getInstance()->onPaymentConfirmed($ref, $data);
            }

            return ['success' => true, 'reference' => $ref];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ── تفعيل اشتراك ─────────────────────────────────────
    private function onMembershipActivated(array $data): array {
        $userId = $data['metadata']['user_id'] ?? 0;
        $this->log("Membership activated for user: $userId");
        return ['success' => true, 'message' => 'membership activated'];
    }

    // ── اختبار الاتصال ────────────────────────────────────
    public function testConnection(): array {
        if (empty($this->apiKey)) {
            return ['success' => false, 'message' => 'WHOP_API_KEY غير مضبوط'];
        }

        $start = microtime(true);
        $res   = $this->request('GET', '/me');
        $ms    = round((microtime(true) - $start) * 1000);

        if (!empty($res['id']) || !empty($res['business'])) {
            return ['success' => true, 'message' => "✅ Whop متصل ({$ms}ms)", 'ms' => $ms];
        }

        return ['success' => false, 'message' => $res['message'] ?? $res['error'] ?? 'فشل الاتصال', 'ms' => $ms];
    }

    // ── HTTP Helper ────────────────────────────────────────
    private function request(string $method, string $path, array $body = []): array {
        $ch = curl_init($this->baseUrl . $path);
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
        ]);

        if ($method !== 'GET' && !empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($res ?: '{}', true) ?: [];
        $data['_http_code'] = $code;
        return $data;
    }

    private function log(string $msg): void {
        @file_put_contents($this->logFile, '[' . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
    }
}
