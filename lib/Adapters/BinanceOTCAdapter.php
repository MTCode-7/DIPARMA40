<?php
/**
 * ============================================================
 * DI PARMA | BinanceOTCAdapter
 * Binance OTC (Over The Counter) — تداول مباشر بكميات كبيرة
 * ============================================================
 * Binance OTC يختلف عن بطاقات الائتمان:
 *  - يُستخدم لشراء/بيع العملات الرقمية مباشرة
 *  - لا يحتاج 2D/3D — يعمل بـ API key + HMAC signature
 *  - يدعم: Quote → Accept → Settle (3 خطوات)
 *  - يدعم أيضاً: Spot Trading للمبالغ الصغيرة
 *
 * المتغيرات في .env:
 *   BINANCE_OTC_API_KEY
 *   BINANCE_OTC_SECRET_KEY
 *   BINANCE_OTC_ENVIRONMENT  (live | testnet)
 *   BINANCE_OTC_BASE_URL
 * ============================================================
 */

require_once __DIR__ . '/GatewayAdapterInterface.php';
require_once __DIR__ . '/GatewayErrorMapper.php';
require_once __DIR__ . '/GatewayLogger.php';

final class BinanceOTCAdapter implements GatewayAdapterInterface
{
    private string $apiKey;
    private string $secretKey;
    private string $baseUrl;
    private string $recvWindow = '5000';

    public function __construct()
    {
        $this->apiKey    = getenv('BINANCE_OTC_API_KEY')    ?: getenv('EXCHANGE_API_KEY')    ?: '';
        $this->secretKey = getenv('BINANCE_OTC_SECRET_KEY') ?: getenv('EXCHANGE_SECRET_KEY') ?: '';
        $env             = getenv('BINANCE_OTC_ENVIRONMENT') ?: 'live';
        $this->baseUrl   = getenv('BINANCE_OTC_BASE_URL')   ?:
            ($env === 'testnet'
                ? 'https://testnet.binance.vision'
                : 'https://api.binance.com');
    }

    public function getName(): string { return 'binance_otc'; }

    public function supports(string $mode): bool
    {
        // OTC لا يدعم 2D/3D — له نظام خاص
        return in_array(strtoupper($mode), ['OTC', 'SPOT', 'CHARGE', '2D', '3D', 'HOLD', 'CANCEL']);
    }

    public function normalizeError(array $rawResponse): string
    {
        return GatewayErrorMapper::fromBinance($rawResponse);
    }

    public function buildIdempotencyKey(string $reference, float $amount): string
    {
        return hash('sha256', 'bnc_' . $reference . '|' . $amount . '|' . getenv('ENCRYPTION_KEY'));
    }

    // ══════════════════════════════════════════════════════════
    // CHARGE — OTC Quote → Accept (الخطوة الكاملة)
    // ══════════════════════════════════════════════════════════
    /**
     * تنفيذ صفقة OTC كاملة:
     *  1. طلب Quote (سعر) لزوج التداول
     *  2. قبول الـ Quote
     *  3. تأكيد التسوية
     *
     * payload يجب أن يحتوي:
     *   from_coin    — العملة المدفوعة (مثل USDT)
     *   to_coin      — العملة المستقبلة (مثل BTC)
     *   from_amount  — المبلغ المدفوع
     *   reference    — رقم مرجعي
     */
    public function charge(array $payload): array
    {
        if (empty($this->apiKey) || empty($this->secretKey)) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR',
                $payload['reference'] ?? '', 0, '', 'BINANCE_OTC_API_KEY غير مضبوط');
        }

        $fromCoin  = strtoupper($payload['from_coin']   ?? 'USDT');
        $toCoin    = strtoupper($payload['to_coin']     ?? 'BTC');
        $fromAmt   = floatval($payload['from_amount']   ?? $payload['amount'] ?? 0);
        $reference = $payload['reference'] ?? uniqid('bnc_', true);

        if ($fromAmt <= 0) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $reference, $fromAmt, $fromCoin, 'المبلغ غير صالح');
        }

        $start = microtime(true);

        try {
            // [1] طلب Quote
            $quoteParams = [
                'fromCoin'    => $fromCoin,
                'toCoin'      => $toCoin,
                'fromAmount'  => $fromAmt,
                'requestValidSecs' => 30,
            ];

            $quoteRes = $this->request('POST', '/sapi/v1/otc/quotes', $quoteParams);

            if (!isset($quoteRes['quoteId'])) {
                $errCode = $this->normalizeError($quoteRes);
                GatewayLogger::log('binance_otc', 'quote', $payload, $quoteRes, $errCode, microtime(true) - $start);
                return GatewayErrorMapper::buildErrorResponse($errCode, $reference, $fromAmt, $fromCoin,
                    $quoteRes['msg'] ?? 'فشل الحصول على سعر OTC');
            }

            $quoteId  = $quoteRes['quoteId'];
            $toAmount = floatval($quoteRes['toAmount'] ?? 0);
            $ratio    = floatval($quoteRes['ratio']    ?? 0);

            // [2] قبول الـ Quote
            $acceptRes = $this->request('POST', '/sapi/v1/otc/orders', [
                'quoteId'    => $quoteId,
                'clientOrderId' => $reference,
            ]);

            $duration = microtime(true) - $start;

            $orderId = $acceptRes['orderId'] ?? '';
            $status  = strtoupper($acceptRes['orderStatus'] ?? '');

            if (in_array($status, ['SUCCESS', 'PROCESS', 'FILLED'])) {
                $result = [
                    'success'        => true,
                    'status'         => 'completed',
                    'transaction_id' => $orderId,
                    'quote_id'       => $quoteId,
                    'reference'      => $reference,
                    'from_coin'      => $fromCoin,
                    'to_coin'        => $toCoin,
                    'from_amount'    => $fromAmt,
                    'to_amount'      => $toAmount,
                    'ratio'          => $ratio,
                    'amount'         => $fromAmt,
                    'currency'       => $fromCoin,
                    'message'        => "✅ تم تنفيذ صفقة OTC: {$fromAmt} {$fromCoin} → {$toAmount} {$toCoin}",
                    'error_code'     => '',
                    'requires_3ds'   => false,
                    'client_secret'  => '',
                    'redirect_url'   => '',
                    'decline_code'   => '',
                    'retryable'      => false,
                    'hard_block'     => false,
                ];
                GatewayLogger::log('binance_otc', 'charge', $payload, $result, '', $duration);
                return $result;
            }

            $errCode = $this->normalizeError($acceptRes);
            GatewayLogger::log('binance_otc', 'charge', $payload, $acceptRes, $errCode, $duration);
            return GatewayErrorMapper::buildErrorResponse($errCode, $reference, $fromAmt, $fromCoin,
                $acceptRes['msg'] ?? "فشل تنفيذ الصفقة: $status");

        } catch (Exception $e) {
            GatewayLogger::log('binance_otc', 'charge', $payload,
                ['exception' => $e->getMessage()], 'NETWORK_ERROR', microtime(true) - $start);
            return GatewayErrorMapper::buildErrorResponse('NETWORK_ERROR', $reference, $fromAmt, $fromCoin, $e->getMessage());
        }
    }

    // ══════════════════════════════════════════════════════════
    // HOLD — OTC لا يدعم hold تقليدي — يُعيد القيمة الحالية
    // ══════════════════════════════════════════════════════════
    public function hold(array $payload): array
    {
        // OTC = الصفقة فورية، لا يوجد hold/capture
        // لكن يمكن طلب Quote بدون تنفيذ = "نظرة" على السعر
        return $this->getQuote($payload);
    }

    /**
     * الحصول على سعر OTC بدون تنفيذ
     */
    public function getQuote(array $payload): array
    {
        if (empty($this->apiKey)) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $payload['reference'] ?? '');
        }

        $fromCoin  = strtoupper($payload['from_coin']  ?? 'USDT');
        $toCoin    = strtoupper($payload['to_coin']    ?? 'BTC');
        $fromAmt   = floatval($payload['from_amount']  ?? $payload['amount'] ?? 0);
        $reference = $payload['reference'] ?? uniqid('qot_', true);

        $start = microtime(true);
        $res = $this->request('POST', '/sapi/v1/otc/quotes', [
            'fromCoin'   => $fromCoin,
            'toCoin'     => $toCoin,
            'fromAmount' => $fromAmt,
            'requestValidSecs' => 30,
        ]);
        $duration = microtime(true) - $start;

        if (isset($res['quoteId'])) {
            $result = [
                'success'        => true,
                'status'         => 'quoted',
                'transaction_id' => $res['quoteId'],
                'quote_id'       => $res['quoteId'],
                'reference'      => $reference,
                'from_coin'      => $fromCoin,
                'to_coin'        => $toCoin,
                'from_amount'    => $fromAmt,
                'to_amount'      => floatval($res['toAmount'] ?? 0),
                'ratio'          => floatval($res['ratio'] ?? 0),
                'expires_at'     => date('Y-m-d H:i:s', time() + 30),
                'amount'         => $fromAmt,
                'currency'       => $fromCoin,
                'message'        => "السعر: 1 {$toCoin} = {$res['ratio']} {$fromCoin} — صالح 30 ثانية",
                'error_code'     => '',
                'requires_3ds'   => false,
                'client_secret'  => '',
                'redirect_url'   => '',
                'decline_code'   => '',
                'retryable'      => false,
                'hard_block'     => false,
            ];
            GatewayLogger::log('binance_otc', 'quote', $payload, $result, '', $duration);
            return $result;
        }

        $errCode = $this->normalizeError($res);
        GatewayLogger::log('binance_otc', 'quote', $payload, $res, $errCode, $duration);
        return GatewayErrorMapper::buildErrorResponse($errCode, $reference, $fromAmt, $fromCoin,
            $res['msg'] ?? 'فشل الحصول على سعر');
    }

    // ══════════════════════════════════════════════════════════
    // CAPTURE — قبول Quote موجود
    // ══════════════════════════════════════════════════════════
    public function capture(string $transactionId, ?float $amount = null): array
    {
        // transactionId = quoteId هنا
        $start = microtime(true);
        $res = $this->request('POST', '/sapi/v1/otc/orders', [
            'quoteId' => $transactionId,
        ]);
        $duration = microtime(true) - $start;

        $status = strtoupper($res['orderStatus'] ?? '');
        if (in_array($status, ['SUCCESS', 'PROCESS', 'FILLED'])) {
            $result = [
                'success'        => true,
                'status'         => 'captured',
                'transaction_id' => $res['orderId'] ?? $transactionId,
                'reference'      => $res['clientOrderId'] ?? '',
                'amount'         => floatval($res['fromAmount'] ?? $amount ?? 0),
                'currency'       => $res['fromCoin'] ?? '',
                'message'        => '✅ تم تنفيذ صفقة OTC',
                'error_code'     => '',
                'requires_3ds'   => false,
                'client_secret'  => '',
                'redirect_url'   => '',
                'decline_code'   => '',
                'retryable'      => false,
                'hard_block'     => false,
            ];
            GatewayLogger::log('binance_otc', 'capture', ['quote_id' => $transactionId], $result, '', $duration);
            return $result;
        }

        $errCode = $this->normalizeError($res);
        GatewayLogger::log('binance_otc', 'capture', ['quote_id' => $transactionId], $res, $errCode, $duration);
        return GatewayErrorMapper::buildErrorResponse($errCode, '', 0, '', $res['msg'] ?? 'فشل تنفيذ الصفقة');
    }

    // ══════════════════════════════════════════════════════════
    // CANCEL — OTC لا يدعم إلغاء بعد التنفيذ
    // ══════════════════════════════════════════════════════════
    public function cancel(string $transactionId, string $reason = 'requested_by_customer'): array
    {
        return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $transactionId, 0, '',
            'صفقات OTC لا يمكن إلغاؤها بعد التنفيذ');
    }

    // ══════════════════════════════════════════════════════════
    // Spot Trade — للمبالغ الصغيرة
    // ══════════════════════════════════════════════════════════
    public function spotMarketOrder(string $symbol, string $side, float $quoteOrderQty): array
    {
        $start = microtime(true);
        $res = $this->request('POST', '/api/v3/order', [
            'symbol'           => strtoupper($symbol),
            'side'             => strtoupper($side),    // BUY | SELL
            'type'             => 'MARKET',
            'quoteOrderQty'    => $quoteOrderQty,
            'newClientOrderId' => 'dp_' . uniqid(),
        ]);
        $duration = microtime(true) - $start;

        if (isset($res['orderId'])) {
            $result = [
                'success'        => true,
                'status'         => 'completed',
                'transaction_id' => (string)$res['orderId'],
                'reference'      => $res['clientOrderId'] ?? '',
                'amount'         => floatval($res['cummulativeQuoteQty'] ?? $quoteOrderQty),
                'currency'       => '',
                'executed_qty'   => floatval($res['executedQty'] ?? 0),
                'symbol'         => $res['symbol'] ?? $symbol,
                'side'           => $res['side'] ?? $side,
                'message'        => "✅ تم تنفيذ أمر Spot: {$res['executedQty']} @ Market",
                'error_code'     => '',
                'requires_3ds'   => false,
                'client_secret'  => '',
                'redirect_url'   => '',
                'decline_code'   => '',
                'retryable'      => false,
                'hard_block'     => false,
            ];
            GatewayLogger::log('binance_otc', 'spot', ['symbol' => $symbol, 'qty' => $quoteOrderQty], $result, '', $duration);
            return $result;
        }

        $errCode = $this->normalizeError($res);
        GatewayLogger::log('binance_otc', 'spot', ['symbol' => $symbol], $res, $errCode, $duration);
        return GatewayErrorMapper::buildErrorResponse($errCode, '', $quoteOrderQty, '',
            $res['msg'] ?? 'فشل أمر Spot');
    }

    // ══════════════════════════════════════════════════════════
    // رصيد المحفظة
    // ══════════════════════════════════════════════════════════
    public function getBalance(string $asset = ''): array
    {
        $res = $this->request('GET', '/api/v3/account', []);
        if (!isset($res['balances'])) {
            return ['success' => false, 'message' => $res['msg'] ?? 'فشل جلب الرصيد'];
        }

        $balances = [];
        foreach ($res['balances'] as $b) {
            $free = floatval($b['free'] ?? 0);
            $locked = floatval($b['locked'] ?? 0);
            if ($free + $locked > 0 || (!empty($asset) && strtoupper($b['asset']) === strtoupper($asset))) {
                $balances[$b['asset']] = [
                    'free'   => $free,
                    'locked' => $locked,
                    'total'  => $free + $locked,
                ];
            }
        }

        if (!empty($asset)) {
            $key = strtoupper($asset);
            return [
                'success' => true,
                'asset'   => $key,
                'free'    => $balances[$key]['free']   ?? 0,
                'locked'  => $balances[$key]['locked'] ?? 0,
                'total'   => $balances[$key]['total']  ?? 0,
            ];
        }

        return ['success' => true, 'balances' => $balances];
    }

    // ══════════════════════════════════════════════════════════
    // سحب عملة للمحفظة الخارجية
    // ══════════════════════════════════════════════════════════
    public function withdraw(string $coin, float $amount, string $address, string $network = 'TRX'): array
    {
        $start = microtime(true);
        $res = $this->request('POST', '/sapi/v1/capital/withdraw/apply', [
            'coin'    => strtoupper($coin),
            'amount'  => $amount,
            'address' => $address,
            'network' => strtoupper($network),
        ]);
        $duration = microtime(true) - $start;

        if (isset($res['id'])) {
            $result = [
                'success'        => true,
                'status'         => 'processing',
                'transaction_id' => $res['id'],
                'reference'      => $res['id'],
                'amount'         => $amount,
                'currency'       => $coin,
                'network'        => $network,
                'address'        => $address,
                'message'        => "✅ طلب سحب {$amount} {$coin} إلى {$address} ({$network}) قيد المعالجة",
                'error_code'     => '',
                'requires_3ds'   => false,
                'client_secret'  => '',
                'redirect_url'   => '',
                'decline_code'   => '',
                'retryable'      => false,
                'hard_block'     => false,
            ];
            GatewayLogger::log('binance_otc', 'withdraw', [
                'coin' => $coin, 'amount' => $amount, 'network' => $network
            ], $result, '', $duration);
            return $result;
        }

        $errCode = $this->normalizeError($res);
        GatewayLogger::log('binance_otc', 'withdraw', [
            'coin' => $coin, 'amount' => $amount
        ], $res, $errCode, $duration);
        return GatewayErrorMapper::buildErrorResponse($errCode, '', $amount, $coin,
            $res['msg'] ?? 'فشل طلب السحب');
    }

    // ══════════════════════════════════════════════════════════
    // HTTP Helper — HMAC-SHA256 Signature
    // ══════════════════════════════════════════════════════════
    private function request(string $method, string $path, array $params = []): array
    {
        // إضافة timestamp
        $params['timestamp']  = round(microtime(true) * 1000);
        $params['recvWindow'] = $this->recvWindow;

        // بناء query string للتوقيع
        $queryString = http_build_query($params, '', '&');

        // HMAC-SHA256 signature
        $signature = hash_hmac('sha256', $queryString, $this->secretKey);
        $queryString .= '&signature=' . $signature;

        $url = $this->baseUrl . $path;
        $ch  = curl_init();

        if ($method === 'GET') {
            $url .= '?' . $queryString;
            curl_setopt($ch, CURLOPT_URL, $url);
        } else {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $queryString);
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'X-MBX-APIKEY: ' . $this->apiKey,
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);

        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['code' => -1, 'msg' => $err];
        }

        return json_decode($res ?: '{}', true) ?: ['code' => -1, 'msg' => 'Invalid response'];
    }
}
