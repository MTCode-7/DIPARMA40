<?php
/**
 * ============================================================
 * DI PARMA | RedotPay Connect — Open API v2
 * ============================================================
 * Stablecoin Payment Gateway — USDT/Crypto → Ledger TRX
 * Docs: https://redotpay.readme.io/docs/getting-started
 * ============================================================
 * Authentication:
 *   X-R-AK          : appKey (merchant ID)
 *   X-R-TS          : timestamp (milliseconds)
 *   X-R-KEY-VERSION : RSA key version (default: 1)
 *   X-R-Signature   : Base64(SHA256withRSA(privateKey, payload))
 * ============================================================
 */
class RedotPayAdapter
{
    private string $appKey;
    private string $privateKey;   // RSA Private Key PEM
    private string $publicKey;    // RSA Public Key PEM (للتحقق من webhook)
    private string $baseUrl;
    private bool   $isLive;
    private int    $keyVersion;

    const URL_SANDBOX    = 'https://acquirersandbox.rp-2023app.com';
    const URL_PRODUCTION = 'https://acquirer.redotpay.com';

    public function __construct()
    {
        $this->appKey     = defined('REDOTPAY_APP_KEY')     ? REDOTPAY_APP_KEY     : (getenv('REDOTPAY_APP_KEY')     ?: '');
        $this->privateKey = defined('REDOTPAY_PRIVATE_KEY') ? REDOTPAY_PRIVATE_KEY : (getenv('REDOTPAY_PRIVATE_KEY') ?: '');
        $this->publicKey  = defined('REDOTPAY_PUBLIC_KEY')  ? REDOTPAY_PUBLIC_KEY  : (getenv('REDOTPAY_PUBLIC_KEY')  ?: '');
        $this->keyVersion = (int)(defined('REDOTPAY_KEY_VERSION') ? REDOTPAY_KEY_VERSION : (getenv('REDOTPAY_KEY_VERSION') ?: 1));
        $env              = defined('REDOTPAY_ENVIRONMENT') ? REDOTPAY_ENVIRONMENT : (getenv('REDOTPAY_ENVIRONMENT') ?: 'sandbox');
        $this->isLive     = in_array($env, ['live','production']);
        $this->baseUrl    = $this->isLive ? self::URL_PRODUCTION : self::URL_SANDBOX;
    }

    /* ══════════════════════════════════════════
       إنشاء طلب دفع
    ══════════════════════════════════════════ */
    public function createOrder(array $params): array
    {
        $orderId    = $params['order_id']    ?? ('DP-'.strtoupper(bin2hex(random_bytes(6))));
        $amount     = number_format((float)($params['amount'] ?? 0), 2, '.', '');
        $currency   = strtoupper($params['currency'] ?? 'USD');
        $returnUrl  = $params['return_url']  ?? (defined('SITE_URL') ? SITE_URL.'/payment_success.php' : '');
        $webhookUrl = $params['webhook_url'] ?? (defined('SITE_URL') ? SITE_URL.'/api/webhook.php?gateway=redotpay' : '');
        $subject    = $params['subject']     ?? 'DI PARMA Payment';
        $ledgerAddr = $params['ledger_addr'] ?? 'TEwLFWlwK55b7PuFfzgH1H2f3xs3pLgLn2';

        $body = [
            'orderId'     => $orderId,
            'amount'      => $amount,
            'currency'    => $currency,
            'subject'     => $subject,
            'redirectUrl' => $returnUrl,
            'notifyUrl'   => $webhookUrl,
            'extra'       => ['ledger_addr' => $ledgerAddr],
        ];

        $result = $this->request('POST', '/openapi/v2/order/create', $body);

        if (($result['code'] ?? '') === 'SUCCESS') {
            return [
                'success'    => true,
                'order_sn'   => $result['data']['orderSn']     ?? '',
                'order_id'   => $orderId,
                'pay_url'    => $result['data']['h5Url']        ?? $result['data']['payUrl'] ?? '',
                'qr_code'    => $result['data']['qrCode']       ?? null,
                'amount'     => $amount,
                'currency'   => $currency,
                'raw'        => $result,
            ];
        }

        return [
            'success' => false,
            'message' => $result['msg'] ?? ($result['code'] ?? 'RedotPay error'),
            'raw'     => $result,
        ];
    }

    /* ══════════════════════════════════════════
       استعلام حالة الطلب
    ══════════════════════════════════════════ */
    public function queryOrder(string $orderSn): array
    {
        $result = $this->request('POST', '/openapi/v2/order/query', ['orderSn' => $orderSn]);

        if (($result['code'] ?? '') === 'SUCCESS') {
            $data   = $result['data'] ?? [];
            $status = strtoupper($data['status'] ?? '');
            return [
                'success'  => true,
                'order_sn' => $orderSn,
                'status'   => $status,
                'paid'     => in_array($status, ['PAID','SUCCESS','COMPLETED']),
                'amount'   => $data['amount']   ?? null,
                'currency' => $data['currency'] ?? null,
                'raw'      => $result,
            ];
        }

        return [
            'success' => false,
            'message' => $result['msg'] ?? 'Query failed',
            'raw'     => $result,
        ];
    }

    /* ══════════════════════════════════════════
       استرداد
    ══════════════════════════════════════════ */
    public function refund(string $orderSn, float $amount, string $reason = ''): array
    {
        $refundId = 'DPREF-'.strtoupper(bin2hex(random_bytes(5)));
        $body = [
            'orderSn'  => $orderSn,
            'refundId' => $refundId,
            'amount'   => number_format($amount, 2, '.', ''),
            'reason'   => $reason ?: 'Customer refund request',
        ];

        $result = $this->request('POST', '/openapi/v2/order/refund', $body);

        return [
            'success'   => ($result['code'] ?? '') === 'SUCCESS',
            'refund_id' => $refundId,
            'message'   => $result['msg'] ?? ($result['code'] ?? ''),
            'raw'       => $result,
        ];
    }

    /* ══════════════════════════════════════════
       التحقق من Webhook
    ══════════════════════════════════════════ */
    public function verifyWebhook(string $payload, string $signature): bool
    {
        if (empty($this->publicKey)) return false;
        $pubKey = openssl_pkey_get_public($this->publicKey);
        if (!$pubKey) return false;
        $decoded = base64_decode($signature);
        return openssl_verify($payload, $decoded, $pubKey, OPENSSL_ALGO_SHA256) === 1;
    }

    /* ══════════════════════════════════════════
       Signature — SHA256withRSA
    ══════════════════════════════════════════ */
    private function sign(string $payload): string
    {
        if (empty($this->privateKey)) return '';
        $privKey = openssl_pkey_get_private($this->privateKey);
        if (!$privKey) return '';
        $sig = '';
        openssl_sign($payload, $sig, $privKey, OPENSSL_ALGO_SHA256);
        return base64_encode($sig);
    }

    /* ══════════════════════════════════════════
       HTTP Request
    ══════════════════════════════════════════ */
    private function request(string $method, string $path, array $body = []): array
    {
        $ts      = (string)(int)(microtime(true) * 1000);
        $bodyStr = !empty($body) ? json_encode($body) : '';
        $sig     = $this->sign($bodyStr);
        $url     = $this->baseUrl . $path;

        $headers = [
            'Content-Type: application/json',
            'X-R-AK: '          . $this->appKey,
            'X-R-TS: '          . $ts,
            'X-R-KEY-VERSION: ' . $this->keyVersion,
        ];
        if ($sig) $headers[] = 'X-R-Signature: ' . $sig;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $bodyStr,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) return ['code'=>'CURL_ERROR','msg'=>$error];
        if ($httpCode !== 200) return ['code'=>'HTTP_'.$httpCode,'msg'=>'HTTP error'];

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : ['code'=>'PARSE_ERROR','msg'=>'Invalid JSON'];
    }

    public function isConfigured(): bool
    {
        return !empty($this->appKey) && !empty($this->privateKey);
    }

    public function getEnvironment(): string
    {
        return $this->isLive ? 'production' : 'sandbox';
    }
}
