<?php
/**
 * ============================================================
 * DI PARMA | Gate.io Adapter
 * ============================================================
 * Gate APIv4 — https://api.gateio.ws/api/v4
 * Docs: https://www.gate.com/docs/developers/apiv4/en/
 *
 * يدعم:
 *  - جلب رصيد الحساب (Spot + Unified)
 *  - تحويل USDT داخلي
 *  - سحب للخارج
 *  - إنشاء أوامر شراء/بيع Spot
 *  - فحص حالة المعاملات
 * ============================================================
 */

require_once __DIR__ . '/GatewayAdapterInterface.php';

class GateIOAdapter implements GatewayAdapterInterface
{
    private string $apiKey;
    private string $apiSecret;
    private string $baseUrl = 'https://api.gateio.ws/api/v4';
    private int    $timeout = 20;

    public function __construct(string $apiKey = '', string $apiSecret = '')
    {
        $this->apiKey    = $apiKey    ?: (getenv('GATEIO_API_KEY')    ?: '');
        $this->apiSecret = $apiSecret ?: (getenv('GATEIO_API_SECRET') ?: '');
    }

    public function getName(): string { return 'gate_io'; }

    public function supports(string $mode): bool
    {
        return in_array(strtoupper($mode), ['CHARGE', 'HOLD', 'WITHDRAW', 'BALANCE']);
    }

    public function normalizeError(array $raw): string { return $raw['label'] ?? 'GATEWAY_ERROR'; }

    public function buildIdempotencyKey(string $ref, float $amount): string
    {
        return 'gate_' . hash('sha256', $ref . '|' . $amount);
    }

    // ══════════════════════════════════════════════════════════
    // [1] Charge — شراء مباشر (إنشاء أمر Spot)
    // ══════════════════════════════════════════════════════════
    public function charge(array $payload): array
    {
        $amount    = floatval($payload['amount']   ?? 0);
        $currency  = strtoupper($payload['currency'] ?? 'USDT');
        $reference = $payload['reference'] ?? uniqid('gate_', true);

        if (empty($this->apiKey) || empty($this->apiSecret)) {
            return $this->error('GATEWAY_ERROR', $reference, $amount, $currency, 'Gate.io API Key غير مضبوط');
        }

        // إنشاء أمر شراء Spot — USDT/currency pair
        $pair   = 'USDT_' . $currency;
        $body   = json_encode([
            'currency_pair' => $pair,
            'side'          => 'buy',
            'amount'        => (string)$amount,
            'price'         => '0',    // market order
            'type'          => 'market',
            'time_in_force' => 'ioc',
            'text'          => 't-' . $reference,
        ]);

        $result = $this->request('POST', '/spot/orders', $body);

        if (!empty($result['id'])) {
            return [
                'success'        => true,
                'status'         => 'completed',
                'transaction_id' => (string)$result['id'],
                'reference'      => $reference,
                'amount'         => $amount,
                'currency'       => $currency,
                'message'        => '✅ تم إنشاء الأمر عبر Gate.io',
                'approval_code'  => null,
                'rrn'            => (string)$result['id'],
                'error_code'     => '',
                'requires_3ds'   => false,
                'client_secret'  => '',
                'redirect_url'   => '',
                'decline_code'   => '',
                'retryable'      => false,
                'hard_block'     => false,
            ];
        }

        $label = $result['label'] ?? $result['message'] ?? json_encode($result);
        return $this->error('GATEWAY_ERROR', $reference, $amount, $currency, $label);
    }

    // ══════════════════════════════════════════════════════════
    // [2] Hold — حجز (غير مدعوم مباشرة في Gate.io)
    // ══════════════════════════════════════════════════════════
    public function hold(array $payload): array
    {
        // Gate.io لا يدعم hold/capture — نُعيد خطأ واضح
        return $this->error(
            'GATEWAY_ERROR',
            $payload['reference'] ?? '',
            floatval($payload['amount'] ?? 0),
            strtoupper($payload['currency'] ?? 'USDT'),
            'Gate.io لا يدعم HOLD — استخدم Charge مباشرة'
        );
    }

    // ══════════════════════════════════════════════════════════
    // [3] Capture
    // ══════════════════════════════════════════════════════════
    public function capture(string $transactionId, ?float $amount = null): array
    {
        return $this->error('GATEWAY_ERROR', '', 0, '', 'Gate.io لا يدعم Capture');
    }

    // ══════════════════════════════════════════════════════════
    // [4] Cancel — إلغاء أمر مفتوح
    // ══════════════════════════════════════════════════════════
    public function cancel(string $transactionId, string $reason = ''): array
    {
        if (empty($transactionId)) {
            return $this->error('GATEWAY_ERROR', '', 0, '', 'order_id مطلوب');
        }

        // نحتاج currency_pair — نُجرّب USDT_USDT كافتراضي
        $currencyPair = 'BTC_USDT'; // يمكن تمريره من payload
        $result = $this->request('DELETE', "/spot/orders/{$transactionId}?currency_pair={$currencyPair}", '');

        if (isset($result['id']) || isset($result['status'])) {
            return [
                'success'        => true,
                'status'         => 'cancelled',
                'transaction_id' => $transactionId,
                'reference'      => '',
                'amount'         => 0,
                'currency'       => '',
                'message'        => '✅ تم إلغاء الأمر',
                'error_code'     => '',
                'requires_3ds'   => false,
                'client_secret'  => '',
                'redirect_url'   => '',
                'decline_code'   => '',
                'retryable'      => false,
                'hard_block'     => false,
            ];
        }

        return $this->error('GATEWAY_ERROR', '', 0, '', $result['label'] ?? 'فشل الإلغاء');
    }

    // ══════════════════════════════════════════════════════════
    // [5] رصيد الحساب
    // ══════════════════════════════════════════════════════════
    public function getBalance(?string $currency = null): array
    {
        $result = $this->request('GET', '/spot/accounts', '');

        if (!is_array($result)) {
            return ['success' => false, 'message' => 'فشل جلب الرصيد'];
        }

        if ($currency) {
            foreach ($result as $acc) {
                if (strtoupper($acc['currency'] ?? '') === strtoupper($currency)) {
                    return [
                        'success'   => true,
                        'currency'  => $acc['currency'],
                        'available' => floatval($acc['available'] ?? 0),
                        'locked'    => floatval($acc['locked']    ?? 0),
                        'total'     => floatval($acc['available'] ?? 0) + floatval($acc['locked'] ?? 0),
                    ];
                }
            }
            return ['success' => false, 'message' => "عملة {$currency} غير موجودة في الحساب"];
        }

        return ['success' => true, 'accounts' => $result];
    }

    // ══════════════════════════════════════════════════════════
    // [6] سحب خارجي
    // ══════════════════════════════════════════════════════════
    public function withdraw(
        float  $amount,
        string $currency,
        string $address,
        string $chain,
        string $memo = ''
    ): array {
        if (empty($address)) {
            return ['success' => false, 'message' => 'عنوان المحفظة مطلوب'];
        }

        $body = json_encode([
            'amount'   => (string)$amount,
            'currency' => strtoupper($currency),
            'address'  => $address,
            'chain'    => $chain ?: 'TRC20',
            'memo'     => $memo ?: '',
        ]);

        $result = $this->request('POST', '/withdrawals', $body);

        if (!empty($result['id'])) {
            return [
                'success'   => true,
                'tx_id'     => (string)$result['id'],
                'status'    => $result['status'] ?? 'PEND',
                'amount'    => $amount,
                'currency'  => strtoupper($currency),
                'address'   => $address,
                'message'   => '✅ تم إرسال طلب السحب',
            ];
        }

        return [
            'success' => false,
            'message' => $result['label'] ?? $result['message'] ?? json_encode($result),
        ];
    }

    // ══════════════════════════════════════════════════════════
    // [7] تحويل داخلي بين الحسابات (Spot → Unified)
    // ══════════════════════════════════════════════════════════
    public function transferInternal(
        float  $amount,
        string $currency,
        string $from = 'spot',
        string $to   = 'unified'
    ): array {
        $body = json_encode([
            'currency' => strtoupper($currency),
            'from'     => $from,
            'to'       => $to,
            'amount'   => (string)$amount,
        ]);

        $result = $this->request('POST', '/wallet/transfers', $body);

        if (isset($result['tx_id']) || empty($result['label'])) {
            return [
                'success'  => true,
                'tx_id'    => $result['tx_id'] ?? '',
                'message'  => "✅ تحويل داخلي: {$amount} {$currency} من {$from} إلى {$to}",
            ];
        }

        return [
            'success' => false,
            'message' => $result['label'] ?? $result['message'] ?? json_encode($result),
        ];
    }

    // ══════════════════════════════════════════════════════════
    // [8] فحص حالة أمر
    // ══════════════════════════════════════════════════════════
    public function getOrderStatus(string $orderId, string $currencyPair): array
    {
        $result = $this->request('GET', "/spot/orders/{$orderId}?currency_pair={$currencyPair}", '');

        if (!empty($result['id'])) {
            $statusMap = [
                'open'      => 'pending',
                'closed'    => 'completed',
                'cancelled' => 'cancelled',
            ];
            return [
                'success'   => true,
                'order_id'  => (string)$result['id'],
                'status'    => $statusMap[$result['status'] ?? ''] ?? $result['status'],
                'filled'    => floatval($result['fill_price'] ?? 0),
                'amount'    => floatval($result['amount']     ?? 0),
                'currency'  => $result['currency_pair']       ?? $currencyPair,
                'raw'       => $result,
            ];
        }

        return ['success' => false, 'message' => $result['label'] ?? 'الأمر غير موجود'];
    }

    // ══════════════════════════════════════════════════════════
    // [9] فحص اتصال API
    // ══════════════════════════════════════════════════════════
    public function testConnection(): array
    {
        if (empty($this->apiKey)) {
            return ['success' => false, 'message' => 'API Key غير مضبوط'];
        }

        $result = $this->request('GET', '/account/detail', '');

        if (!empty($result['user_id'])) {
            return [
                'success'  => true,
                'user_id'  => $result['user_id'],
                'tier'     => $result['tier']        ?? 'unknown',
                'mfa'      => $result['mfa_enabled'] ?? false,
                'message'  => "✅ Gate.io متصل — User ID: {$result['user_id']}",
            ];
        }

        return [
            'success' => false,
            'message' => $result['label'] ?? $result['message'] ?? 'فشل الاتصال بـ Gate.io',
        ];
    }

    // ══════════════════════════════════════════════════════════
    // HTTP Request — Gate APIv4 Signed
    // ══════════════════════════════════════════════════════════
    private function request(string $method, string $path, string $body = ''): array
    {
        $timestamp   = (string)time();
        $queryString = '';
        $url         = $this->baseUrl . $path;

        // فصل query string من الـ path للتوقيع
        if (strpos($path, '?') !== false) {
            [$pathPart, $queryString] = explode('?', $path, 2);
        } else {
            $pathPart = $path;
        }

        // Gate APIv4 signature format (من التوثيق الرسمي):
        // Method + \n + RequestURL + \n + QueryString + \n + HexEncode(SHA512(body)) + \n + Timestamp
        // RequestURL = /api/v4/path (بدون host)
        $bodyHash    = hash('sha512', $body ?: '');
        $signPayload = implode("\n", [
            $method,
            '/api/v4' . $pathPart,   // ← URL كامل بدون host
            $queryString,
            $bodyHash,
            $timestamp
        ]);
        $signature   = hash_hmac('sha512', $signPayload, $this->apiSecret);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'KEY: '       . $this->apiKey,
            'SIGN: '      . $signature,
            'Timestamp: ' . $timestamp,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
        ]);

        if (!in_array($method, ['GET', 'DELETE']) && !empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['label' => 'NETWORK_ERROR', 'message' => $err];
        }

        $data = json_decode($res ?: '{}', true);
        return is_array($data) ? $data : ['label' => 'PARSE_ERROR', 'raw' => $res];
    }

    // ── مساعد: بناء خطأ موحد ─────────────────────────────────
    private function error(
        string $code,
        string $reference,
        float  $amount,
        string $currency,
        string $message = ''
    ): array {
        return [
            'success'        => false,
            'status'         => 'failed',
            'transaction_id' => '',
            'reference'      => $reference,
            'amount'         => $amount,
            'currency'       => $currency,
            'message'        => $message ?: $code,
            'error_code'     => $code,
            'approval_code'  => null,
            'rrn'            => null,
            'requires_3ds'   => false,
            'client_secret'  => '',
            'redirect_url'   => '',
            'decline_code'   => '',
            'retryable'      => false,
            'hard_block'     => false,
        ];
    }
}
