<?php
/**
 * ============================================================
 * DI PARMA | ExchangeAPIService
 * شراء USDT تلقائياً من Binance/OKX بعد تأكيد الدفع
 * ============================================================
 * الطريقتان:
 *   Mode 1: Hot Wallet جاهز   → إرسال مباشر (أسرع)
 *   Mode 2: Market Buy         → شراء من Exchange ثم سحب
 * ============================================================
 */

class ExchangeAPIService
{
    // Binance API
    private const BINANCE_API = 'https://api.binance.com';
    // OKX API
    private const OKX_API     = 'https://www.okx.com';

    private static ?self $instance = null;
    private Database $db;
    private string $provider;      // binance | okx | hot_wallet
    private string $apiKey;
    private string $secretKey;
    private string $passphrase;    // OKX فقط
    private string $logFile;

    private function __construct()
    {
        $this->db         = db();
        $this->provider   = getenv('EXCHANGE_PROVIDER')   ?: 'hot_wallet';
        $this->apiKey     = getenv('EXCHANGE_API_KEY')    ?: '';
        $this->secretKey  = getenv('EXCHANGE_SECRET_KEY') ?: '';
        $this->passphrase = getenv('OKX_PASSPHRASE')      ?: '';
        $this->logFile    = defined('LOGS_PATH') ? LOGS_PATH . '/exchange.log' : __DIR__ . '/../logs/exchange.log';
        if (!is_dir(dirname($this->logFile))) @mkdir(dirname($this->logFile), 0755, true);
    }

    public static function getInstance(): self
    {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    // ── الواجهة الرئيسية ─────────────────────────────────────

    /**
     * تنفيذ شراء وإرسال USDT بعد تأكيد دفع الفيات
     *
     * @param string $reference   مرجع المعاملة
     * @param float  $usdtAmount  كمية USDT
     * @param string $toAddress   عنوان المستخدم
     * @param string $network     TRC20 | ERC20 | BEP20
     * @param int    $userId
     */
    public function fulfillOrder(
        string $reference,
        float  $usdtAmount,
        string $toAddress,
        string $network,
        int    $userId
    ): array {
        $this->log("→ fulfillOrder: $reference | $usdtAmount USDT → $toAddress [$network]");

        // تحقق مزدوج من عدم التنفيذ مسبقاً
        $existing = $this->db->find('blockchain_txns', [
            'reference' => $reference,
            'direction' => 'out',
        ]);
        if ($existing && in_array($existing['status'], ['pending','confirmed','broadcasting'])) {
            return [
                'success'   => true,
                'duplicate' => true,
                'tx_hash'   => $existing['tx_hash'],
                'message'   => 'تم الإرسال مسبقاً',
            ];
        }

        return match($this->provider) {
            'binance'    => $this->fulfilViaBinance($reference, $usdtAmount, $toAddress, $network, $userId),
            'okx'        => $this->fulfilViaOKX($reference, $usdtAmount, $toAddress, $network, $userId),
            default      => $this->fulfilViaHotWallet($reference, $usdtAmount, $toAddress, $network, $userId),
        };
    }

    // ── Hot Wallet (الافتراضي) ───────────────────────────────

    private function fulfilViaHotWallet(
        string $reference, float $usdtAmount,
        string $toAddress, string $network, int $userId
    ): array {
        require_once __DIR__ . '/HotWalletService.php';
        $hw = HotWalletService::getInstance();

        // تحقق من الرصيد
        $balance = $hw->getHotBalance();
        if ($balance < $usdtAmount) {
            return [
                'success' => false,
                'message' => "رصيد Hot Wallet غير كافٍ: {$balance} USDT < {$usdtAmount} USDT",
                'action'  => 'refill_required',
            ];
        }

        return $hw->sendUSDT($reference, $toAddress, $usdtAmount, $userId);
    }

    // ── Binance ──────────────────────────────────────────────

    private function fulfilViaBinance(
        string $reference, float $usdtAmount,
        string $toAddress, string $network, int $userId
    ): array {
        // نتحقق من رصيد USDT في Binance أولاً
        $balance = $this->getBinanceBalance('USDT');
        if ($balance < $usdtAmount) {
            $this->log("⚠ Binance USDT منخفض: $balance < $usdtAmount");
            return ['success' => false, 'message' => "رصيد Binance غير كافٍ: $balance USDT"];
        }

        // سحب USDT
        $result = $this->binanceWithdraw($usdtAmount, $toAddress, $network);
        if (!$result['success']) return $result;

        $withdrawId = $result['withdraw_id'];

        // حفظ في blockchain_txns للمراقبة
        $this->db->insert('blockchain_txns', [
            'reference'     => $reference,
            'network'       => $network,
            'coin'          => 'USDT',
            'tx_hash'       => null,
            'from_address'  => 'binance_hot',
            'to_address'    => $toAddress,
            'amount'        => $usdtAmount,
            'fee'           => 1.0,
            'confirmations' => 0,
            'required_conf' => 20,
            'direction'     => 'out',
            'status'        => 'broadcasting',
            'raw_response'  => json_encode($result),
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        // تسجيل event
        $this->logEvent('exchange.withdraw.initiated', $reference, $userId, [
            'provider'    => 'binance',
            'withdraw_id' => $withdrawId,
            'amount'      => $usdtAmount,
            'to_address'  => $toAddress,
            'network'     => $network,
        ]);

        $this->log("✓ Binance withdraw: $withdrawId | $usdtAmount USDT → $toAddress");

        return [
            'success'     => true,
            'provider'    => 'binance',
            'withdraw_id' => $withdrawId,
            'amount'      => $usdtAmount,
            'to_address'  => $toAddress,
            'network'     => $network,
            'status'      => 'processing',
            'message'     => 'جاري السحب من Binance',
        ];
    }

    // ── OKX ──────────────────────────────────────────────────

    private function fulfilViaOKX(
        string $reference, float $usdtAmount,
        string $toAddress, string $network, int $userId
    ): array {
        $balance = $this->getOKXBalance('USDT');
        if ($balance < $usdtAmount) {
            return ['success' => false, 'message' => "رصيد OKX غير كافٍ: $balance USDT"];
        }

        $chain = match($network) {
            'TRC20' => 'USDT-TRC20',
            'ERC20' => 'USDT-ERC20',
            'BEP20' => 'USDT-BSC',
            default => 'USDT-TRC20',
        };

        $result = $this->okxWithdraw($usdtAmount, $toAddress, $chain);
        if (!$result['success']) return $result;

        $this->logEvent('exchange.withdraw.initiated', $reference, $userId, [
            'provider'   => 'okx',
            'wdId'       => $result['wdId'] ?? '',
            'amount'     => $usdtAmount,
            'to_address' => $toAddress,
            'network'    => $network,
        ]);

        return array_merge($result, ['provider' => 'okx', 'status' => 'processing']);
    }

    // ── Binance API ──────────────────────────────────────────

    private function getBinanceBalance(string $asset): float
    {
        $response = $this->binanceRequest('GET', '/api/v3/account');
        $balances = $response['balances'] ?? [];
        foreach ($balances as $b) {
            if ($b['asset'] === $asset) return (float)$b['free'];
        }
        return 0.0;
    }

    private function binanceWithdraw(float $amount, string $address, string $network): array
    {
        $chain = match(strtoupper($network)) {
            'TRC20' => 'TRX',
            'ERC20' => 'ETH',
            'BEP20' => 'BSC',
            default => 'TRX',
        };

        $params = [
            'coin'      => 'USDT',
            'address'   => $address,
            'amount'    => number_format($amount, 6, '.', ''),
            'network'   => $chain,
            'timestamp' => round(microtime(true) * 1000),
        ];

        $response = $this->binanceRequest('POST', '/sapi/v1/capital/withdraw/apply', $params);
        if (isset($response['id'])) {
            return ['success' => true, 'withdraw_id' => $response['id']];
        }
        return ['success' => false, 'message' => $response['msg'] ?? 'Binance withdraw failed'];
    }

    private function binanceRequest(string $method, string $path, array $params = []): array
    {
        if (empty($this->apiKey)) return ['error' => 'Binance API غير مضبوط'];

        $params['timestamp'] = $params['timestamp'] ?? round(microtime(true) * 1000);
        $query     = http_build_query($params);
        $signature = hash_hmac('sha256', $query, $this->secretKey);
        $query    .= '&signature=' . $signature;

        $url = self::BINANCE_API . $path . ($method === 'GET' ? '?' . $query : '');
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'X-MBX-APIKEY: ' . $this->apiKey,
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_CUSTOMREQUEST => $method,
        ]);
        if ($method === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, $query);

        $res = curl_exec($ch);
        curl_close($ch);
        return json_decode($res ?: '{}', true) ?: [];
    }

    // ── OKX API ──────────────────────────────────────────────

    private function getOKXBalance(string $ccy): float
    {
        $response = $this->okxRequest('GET', '/api/v5/asset/balances', ['ccy' => $ccy]);
        return (float)($response['data'][0]['availBal'] ?? 0);
    }

    private function okxWithdraw(float $amount, string $address, string $chain): array
    {
        $params = [
            'ccy'    => 'USDT',
            'amt'    => number_format($amount, 6, '.', ''),
            'dest'   => '4',  // 4 = on-chain
            'toAddr' => $address,
            'fee'    => '1',
            'chain'  => $chain,
        ];
        $response = $this->okxRequest('POST', '/api/v5/asset/withdrawal', $params);
        if (!empty($response['data'][0]['wdId'])) {
            return ['success' => true, 'wdId' => $response['data'][0]['wdId']];
        }
        return ['success' => false, 'message' => $response['msg'] ?? 'OKX withdraw failed'];
    }

    private function okxRequest(string $method, string $path, array $params = []): array
    {
        if (empty($this->apiKey)) return ['error' => 'OKX API غير مضبوط'];

        $ts      = gmdate('Y-m-d\TH:i:s.') . sprintf('%03d', floor(microtime(true) * 1000) % 1000) . 'Z';
        $body    = $method === 'GET' ? '' : json_encode($params);
        $query   = $method === 'GET' ? '?' . http_build_query($params) : '';
        $sign    = base64_encode(hash_hmac('sha256', $ts . $method . $path . $query . $body, $this->secretKey, true));

        $ch = curl_init(self::OKX_API . $path . $query);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "OK-ACCESS-KEY: {$this->apiKey}",
                "OK-ACCESS-SIGN: {$sign}",
                "OK-ACCESS-TIMESTAMP: {$ts}",
                "OK-ACCESS-PASSPHRASE: {$this->passphrase}",
            ],
            CURLOPT_CUSTOMREQUEST => $method,
        ]);
        if ($method !== 'GET') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        $res = curl_exec($ch);
        curl_close($ch);
        return json_decode($res ?: '{}', true) ?: [];
    }

    // ── مساعدات ─────────────────────────────────────────────

    private function log(string $msg): void
    {
        @file_put_contents($this->logFile, '[' . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
    }

    private function logEvent(string $type, ?string $ref, ?int $userId, array $payload): void
    {
        try {
            $this->db->insert('event_log', [
                'event_type' => $type,
                'reference'  => $ref,
                'user_id'    => $userId,
                'payload'    => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'processed'  => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Exception $e) {}
    }
}
