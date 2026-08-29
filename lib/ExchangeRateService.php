<?php
/**
 * ============================================================
 * DI PARMA | ExchangeRateService
 * جلب أسعار الصرف من CoinGecko + Binance مع Cache في DB
 * ============================================================
 */

class ExchangeRateService
{
    // مدة صلاحية الـ Cache بالثواني
    private const CACHE_TTL = 30;

    // هامش المنصة الافتراضي %
    private const DEFAULT_MARGIN = 1.5;

    // العملات المدعومة
    private const SUPPORTED_COINS = [
        'USDT' => ['coingecko_id' => 'tether',       'binance_symbol' => 'USDT'],
        'BTC'  => ['coingecko_id' => 'bitcoin',      'binance_symbol' => 'BTC'],
        'ETH'  => ['coingecko_id' => 'ethereum',     'binance_symbol' => 'ETH'],
        'BNB'  => ['coingecko_id' => 'binancecoin',  'binance_symbol' => 'BNB'],
        'TRX'  => ['coingecko_id' => 'tron',         'binance_symbol' => 'TRX'],
    ];

    // عملات الفيات والسعر الثابت مقابل USD
    private const FIAT_VS_USD = [
        'USD' => 1.0,
        'AED' => 3.6725,
        'SAR' => 3.7500,
        'KWD' => 0.3070,
        'BHD' => 0.3770,
        'OMR' => 0.3850,
        'QAR' => 3.6400,
        'EUR' => 0.9200,
        'GBP' => 0.7900,
    ];

    private static ?self $instance = null;
    private Database $db;

    private function __construct()
    {
        $this->db = db();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ── واجهة عامة ──────────────────────────────────────────

    /**
     * جلب السعر النهائي للمستخدم (مع هامش المنصة)
     * getRate('USDT', 'AED') → ['rate'=>3.67, 'final_rate'=>3.72, 'margin'=>1.5]
     */
    public function getRate(string $coin, string $fiat = 'AED', float $margin = self::DEFAULT_MARGIN): array
    {
        $coin = strtoupper(trim($coin));
        $fiat = strtoupper(trim($fiat));

        // [1] محاولة الـ Cache
        $cached = $this->getCached($coin, $fiat);
        if ($cached !== null) {
            return $cached;
        }

        // [2] جلب من المصادر الخارجية
        $rate = $this->fetchFromSources($coin, $fiat);

        if ($rate === null) {
            // fallback لآخر سعر في DB
            $rate = $this->getLastKnownRate($coin, $fiat);
        }

        if ($rate === null) {
            throw new RuntimeException("لا يوجد سعر متاح لـ $coin/$fiat");
        }

        $finalRate = round($rate * (1 + $margin / 100), 8);

        // [3] حفظ في DB
        $this->saveRate($coin, $fiat, $rate, $finalRate, $margin);

        return [
            'coin'       => $coin,
            'fiat'       => $fiat,
            'rate'       => $rate,
            'final_rate' => $finalRate,
            'margin_pct' => $margin,
            'source'     => 'live',
            'fetched_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * حساب مبلغ Crypto من مبلغ Fiat
     * calculate(500, 'AED', 'USDT') → ['crypto_amount'=>134.13, 'rate'=>3.72, ...]
     */
    public function calculate(float $fiatAmount, string $fiat, string $coin, float $margin = self::DEFAULT_MARGIN): array
    {
        $rateData    = $this->getRate($coin, $fiat, $margin);
        $finalRate   = $rateData['final_rate'];
        $cryptoAmount = round($fiatAmount / $finalRate, 6);
        $fee          = round($fiatAmount * ($margin / 100), 2);

        return [
            'fiat_amount'   => $fiatAmount,
            'fiat_currency' => $fiat,
            'coin'          => $coin,
            'crypto_amount' => $cryptoAmount,
            'rate'          => $rateData['rate'],
            'final_rate'    => $finalRate,
            'margin_pct'    => $margin,
            'fee_fiat'      => $fee,
            'net_fiat'      => $fiatAmount - $fee,
        ];
    }

    /**
     * جلب أسعار جميع العملات دفعةً واحدة
     */
    public function getAllRates(string $fiat = 'AED'): array
    {
        $rates = [];
        foreach (array_keys(self::SUPPORTED_COINS) as $coin) {
            try {
                $rates[$coin] = $this->getRate($coin, $fiat);
            } catch (RuntimeException $e) {
                $rates[$coin] = ['error' => $e->getMessage()];
            }
        }
        return $rates;
    }

    // ── جلب من المصادر ──────────────────────────────────────

    private function fetchFromSources(string $coin, string $fiat): ?float
    {
        $rates = [];

        // المصدر 1: CoinGecko
        $cg = $this->fetchCoinGecko($coin, $fiat);
        if ($cg !== null) $rates[] = $cg;

        // المصدر 2: Binance (USDT/USD ثم تحويل)
        $bn = $this->fetchBinance($coin, $fiat);
        if ($bn !== null) $rates[] = $bn;

        if (empty($rates)) return null;

        // المتوسط بين المصادر
        return array_sum($rates) / count($rates);
    }

    private function fetchCoinGecko(string $coin, string $fiat): ?float
    {
        $coinId = self::SUPPORTED_COINS[$coin]['coingecko_id'] ?? null;
        if (!$coinId) return null;

        // CoinGecko يدعم USD مباشرة، نحوّل للعملة المطلوبة
        $url = "https://api.coingecko.com/api/v3/simple/price"
             . "?ids={$coinId}&vs_currencies=usd";

        $response = $this->httpGet($url, 5);
        if ($response === null) return null;

        $data = json_decode($response, true);
        $usdRate = $data[$coinId]['usd'] ?? null;
        if (!$usdRate) return null;

        // تحويل USD → فيات مطلوب
        return $this->usdToFiat((float)$usdRate, $fiat);
    }

    private function fetchBinance(string $coin, string $fiat): ?float
    {
        // Binance يدعم USDT كعملة أساس
        if ($coin === 'USDT') {
            // USDT دائماً = 1 USD تقريباً
            return $this->usdToFiat(1.0, $fiat);
        }

        $symbol = $coin . 'USDT';
        $url    = "https://api.binance.com/api/v3/ticker/price?symbol={$symbol}";

        $response = $this->httpGet($url, 5);
        if ($response === null) return null;

        $data     = json_decode($response, true);
        $usdtRate = isset($data['price']) ? (float)$data['price'] : null;
        if (!$usdtRate) return null;

        return $this->usdToFiat($usdtRate, $fiat);
    }

    // ── Cache ────────────────────────────────────────────────

    private function getCached(string $coin, string $fiat): ?array
    {
        try {
            $row = $this->db->query(
                "SELECT * FROM " . DB_PREFIX . "fx_rates
                 WHERE base_currency = ? AND quote_currency = ?
                   AND fetched_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
                 ORDER BY fetched_at DESC LIMIT 1",
                [$coin, $fiat, self::CACHE_TTL]
            );

            if (empty($row)) return null;

            $r = $row[0];
            return [
                'coin'       => $coin,
                'fiat'       => $fiat,
                'rate'       => (float)$r['rate'],
                'final_rate' => (float)$r['final_rate'],
                'margin_pct' => (float)$r['margin_pct'],
                'source'     => 'cache',
                'fetched_at' => $r['fetched_at'],
            ];
        } catch (Exception $e) {
            return null;
        }
    }

    private function saveRate(string $coin, string $fiat, float $rate, float $finalRate, float $margin): void
    {
        try {
            $this->db->insert('fx_rates', [
                'base_currency'  => $coin,
                'quote_currency' => $fiat,
                'rate'           => $rate,
                'source'         => 'aggregate',
                'margin_pct'     => $margin,
                'final_rate'     => $finalRate,
                'fetched_at'     => date('Y-m-d H:i:s'),
            ]);
        } catch (Exception $e) {
            // لا نوقف التنفيذ إذا فشل الحفظ
            error_log('ExchangeRateService::saveRate error: ' . $e->getMessage());
        }
    }

    private function getLastKnownRate(string $coin, string $fiat): ?float
    {
        try {
            $row = $this->db->query(
                "SELECT rate FROM " . DB_PREFIX . "fx_rates
                 WHERE base_currency = ? AND quote_currency = ?
                 ORDER BY fetched_at DESC LIMIT 1",
                [$coin, $fiat]
            );
            return !empty($row) ? (float)$row[0]['rate'] : null;
        } catch (Exception $e) {
            return null;
        }
    }

    // ── مساعدات ─────────────────────────────────────────────

    private function usdToFiat(float $usdAmount, string $fiat): float
    {
        $fiatPerUsd = self::FIAT_VS_USD[$fiat] ?? 1.0;
        return round($usdAmount * $fiatPerUsd, 8);
    }

    private function httpGet(string $url, int $timeout = 10): ?string
    {
        if (!function_exists('curl_init')) return null;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'DI-PARMA-Gateway/1.0',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($response !== false && $httpCode === 200) ? $response : null;
    }
}
