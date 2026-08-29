<?php
/**
 * ============================================================
 * DI PARMA | PayRam Adapter
 * Self-hosted crypto payment gateway
 * BASE_URL: http://65.2.184.57:8080
 * ============================================================
 * APIs:
 *   POST /api/v1/payment              → Create payment
 *   GET  /api/v1/payment/reference/{id} → Payment status
 *   GET  /api/v1/blockchain-currency/reference/{id} → Get chains
 *   POST /api/v1/deposit-address/reference/{id}     → Assign address
 *   GET  /api/v1/ticker               → Live prices
 *   POST /api/v1/withdrawal/merchant  → Create payout
 *   GET  /api/v1/withdrawal/{id}/merchant → Payout status
 *   GET  /api/v1/withdrawal/merchant  → List payouts
 * ============================================================
 */
class PayRamAdapter
{
    private string $apiKey;
    private string $webhookSecret;
    private string $baseUrl;
    private int    $timeout;

    public function __construct()
    {
        $this->apiKey  = defined('PAYRAM_API_KEY')  ? PAYRAM_API_KEY  : (getenv('PAYRAM_API_KEY')  ?: '');
        $this->webhookSecret = defined('PAYRAM_WEBHOOK_SECRET')
            ? PAYRAM_WEBHOOK_SECRET
            : (getenv('PAYRAM_WEBHOOK_SECRET') ?: $this->apiKey);
        $this->baseUrl = rtrim(
            defined('PAYRAM_BASE_URL') ? PAYRAM_BASE_URL : (getenv('PAYRAM_BASE_URL') ?: 'http://65.2.184.57:8080'),
            '/'
        );
        $this->timeout = 30;
    }

    /* ══════════════════════════════════════════
       PAYMENTS
    ══════════════════════════════════════════ */

    /**
     * إنشاء دفعة جديدة — يرجع reference_id + url
     */
    public function createPayment(array $params): array
    {
        $body = [
            'customerEmail' => $params['email']       ?? 'client@diparmas.com',
            'customerId'    => $params['customer_id'] ?? 'user_' . time(),
            'amountInUSD'   => (float)($params['amount'] ?? 0),
        ];

        $result = $this->request('POST', '/api/v1/payment', $body);

        // Payram قد يرجع reference_id أو referenceID (camelCase)
        $refId = $result['reference_id'] ?? $result['referenceID'] ?? $result['referenceId'] ?? null;

        if (!empty($refId)) {
            return [
                'success'      => true,
                'reference_id' => $refId,
                'invoice_id'   => $result['invoiceID']   ?? $result['invoice_id'] ?? null,
                'url'          => $result['url']          ?? ($this->baseUrl . '/pay/' . $refId),
                'amount'       => $params['amount'],
                'raw'          => $result,
            ];
        }

        return ['success' => false, 'message' => $result['message'] ?? 'PayRam payment creation failed', 'raw' => $result];
    }

    /**
     * حالة الدفعة
     */
    public function getPaymentStatus(string $referenceId): array
    {
        $result = $this->request('GET', '/api/v1/payment/reference/' . $referenceId);

        if (($result['success'] ?? true) === false || isset($result['error'])) {
            return [
                'success' => false,
                'reference_id' => $referenceId,
                'message' => $result['message'] ?? $result['error'] ?? 'PayRam status request failed',
                'raw' => $result,
            ];
        }

        return [
            'success'       => true,
            'reference_id'  => $result['referenceID'] ?? $result['reference_id'] ?? $referenceId,
            'invoice_id'    => $result['invoiceID']   ?? $result['invoice_id']   ?? null,
            'status'        => $result['paymentState'] ?? $result['status']      ?? 'UNKNOWN',
            'amount'        => $result['amountInUSD']  ?? $result['amount']      ?? null,
            'filled_amount' => $result['filledAmountInUSD'] ?? $result['filled_amount'] ?? null,
            'raw'           => $result,
        ];
    }

    /**
     * الحصول على العملات المدعومة لدفعة
     */
    public function getBlockchainCurrencies(string $referenceId): array
    {
        return $this->request('GET', '/api/v1/blockchain-currency/reference/' . $referenceId);
    }

    /**
     * تعيين عنوان إيداع للعميل
     */
    public function assignDepositAddress(string $referenceId, string $blockchainCode): array
    {
        return $this->request('POST', '/api/v1/deposit-address/reference/' . $referenceId, [
            'blockchain_code' => strtoupper($blockchainCode),
        ]);
    }

    /* ══════════════════════════════════════════
       TICKERS — أسعار العملات
    ══════════════════════════════════════════ */

    /**
     * أسعار العملات الحية — public endpoint
     */
    public function getTickers(): array
    {
        return $this->request('GET', '/api/v1/ticker', [], false);
    }

    /**
     * فحص اتصال PayRam مع المصادقة، وليس مجرد وجود الإعدادات.
     */
    public function checkConnection(): bool
    {
        if (!$this->isConfigured()) return false;
        $result = $this->request('GET', '/api/v1/ticker', [], true);
        return is_array($result) && array_is_list($result) && !empty($result);
    }

    /**
     * تحويل USD → Crypto
     */
    public function convertUsdToCrypto(float $usdAmount, string $blockchainCode, string $currencyCode): ?float
    {
        $tickers = $this->getTickers();
        foreach ($tickers as $ticker) {
            if (
                strtoupper($ticker['blockchainCode'] ?? '') === strtoupper($blockchainCode) &&
                strtoupper($ticker['currencyCode']   ?? '') === strtoupper($currencyCode)
            ) {
                $price     = (float)($ticker['price'] ?? 1);
                $precision = (int)($ticker['walletPrecision'] ?? 6);
                if ($price <= 0) return null;
                return round($usdAmount / $price, $precision);
            }
        }
        return null;
    }

    /* ══════════════════════════════════════════
       PAYOUTS
    ══════════════════════════════════════════ */

    /**
     * إنشاء payout — إرسال مبلغ لمحفظة
     */
    public function createPayout(array $params): array
    {
        $idempotencyKey = $params['idempotency_key'] ?? ('dp-' . strtoupper(bin2hex(random_bytes(8))));

        $body = [
            'email'          => $params['email']            ?? 'client@diparmas.com',
            'blockchainCode' => strtoupper($params['blockchain_code'] ?? 'TRX'),
            'currencyCode'   => strtoupper($params['currency_code']   ?? 'USDT'),
            'amount'         => (string)($params['amount']),
            'toAddress'      => $params['to_address'],
            'customerID'     => (string)($params['customer_id'] ?? 'dp_' . time()),
        ];
        if (!empty($params['mobile'])) $body['mobileNumber'] = $params['mobile'];

        $result = $this->request('POST', '/api/v1/withdrawal/merchant', $body, true, [
            'Idempotency-Key: ' . $idempotencyKey,
        ]);

        return [
            'success'         => isset($result['id']),
            'payout_id'       => $result['id']        ?? null,
            'status'          => $result['status']     ?? null,
            'tx_hash'         => $result['txHash']     ?? null,
            'idempotency_key' => $idempotencyKey,
            'raw'             => $result,
        ];
    }

    /**
     * حالة payout
     */
    public function getPayoutStatus(int $payoutId): array
    {
        return $this->request('GET', '/api/v1/withdrawal/' . $payoutId . '/merchant');
    }

    /**
     * قائمة payouts
     */
    public function listPayouts(array $filters = []): array
    {
        $query = http_build_query(array_merge(['limit' => 50, 'order' => 'DESC'], $filters));
        return $this->request('GET', '/api/v1/withdrawal/merchant?' . $query);
    }

    /* ══════════════════════════════════════════
       WEBHOOK VERIFICATION
    ══════════════════════════════════════════ */

    /**
     * التحقق من توقيع PayRam Webhook
     * X-Payram-Signature: sha256=<hex>
     */
    public function verifyWebhook(string $rawBody, string $signatureHeader): bool
    {
        $signatureHeader = trim((string)$signatureHeader);
        if ($signatureHeader === '') return false;

        $secrets = array_values(array_filter([
            $this->webhookSecret ?: '',
            $this->apiKey ?: '',
        ], static fn($secret) => $secret !== ''));

        if (empty($secrets)) return false;

        $normalized = strtolower($signatureHeader);
        foreach ($secrets as $secret) {
            $plain = hash_hmac('sha256', $rawBody, $secret);
            $withPrefix = 'sha256=' . $plain;
            if (
                hash_equals($withPrefix, $signatureHeader) ||
                hash_equals($plain, $signatureHeader) ||
                hash_equals(strtolower($withPrefix), $normalized) ||
                hash_equals(strtolower($plain), $normalized)
            ) {
                return true;
            }
        }

        return false;
    }

    /* ══════════════════════════════════════════
       HTTP REQUEST
    ══════════════════════════════════════════ */

    private function request(
        string $method,
        string $path,
        array  $body = [],
        bool   $auth = true,
        array  $extraHeaders = []
    ): array {
        $url     = $this->baseUrl . $path;
        $headers = ['Content-Type: application/json'];
        if ($auth && $this->apiKey) {
            $headers[] = 'API-Key: ' . $this->apiKey;
        }
        foreach ($extraHeaders as $h) $headers[] = $h;

        $ch = curl_init($url);
        $bodyJson = !empty($body) ? json_encode($body) : '';

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => false, // HTTP على port 8080
        ]);

        if (in_array($method, ['POST', 'PUT', 'PATCH']) && $bodyJson) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyJson);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) return ['error' => $error, 'success' => false];
        if (!$response) return ['error' => 'Empty response', 'success' => false];

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return ['success' => false, 'message' => 'PayRam returned invalid JSON', 'raw_response' => $response, 'http_code' => $httpCode];
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            $decoded['success'] = false;
            $decoded['http_code'] = $httpCode;
            $decoded['message'] = $decoded['message'] ?? ('PayRam HTTP error ' . $httpCode);
        }
        return $decoded;
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && !empty($this->baseUrl);
    }

    public function getBaseUrl(): string { return $this->baseUrl; }
}
