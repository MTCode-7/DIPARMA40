<?php
/**
 * ============================================================
 * DI PARMA | BlockchainMonitor
 * مراقبة التحويلات الواردة على TRC20/ERC20 وتحديث المعاملات
 * ============================================================
 * يُشغَّل كـ Worker دوري (Cron Job كل دقيقة):
 *   php lib/BlockchainMonitor.php
 * أو عبر Endpoint:
 *   GET /api/blockchain_sync.php
 * ============================================================
 */

class BlockchainMonitor
{
    // TronGrid API
    private const TRON_API_BASE   = 'https://api.trongrid.io';

    // BSCScan / Etherscan (يحتاج API Key)
    private const ETH_API_BASE    = 'https://api.etherscan.io/api';
    private const BSC_API_BASE    = 'https://api.bscscan.com/api';

    // USDT Contract Addresses
    private const USDT_CONTRACTS  = [
        'TRC20' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
        'ERC20' => '0xdac17f958d2ee523a2206206994597c13d831ec7',
        'BEP20' => '0x55d398326f99059ff775485246999027b3197955',
    ];

    private static ?self $instance = null;
    private Database $db;
    private string $logFile;

    private function __construct()
    {
        $this->db      = db();
        $this->logFile = defined('LOGS_PATH') ? LOGS_PATH . '/blockchain.log' : __DIR__ . '/../logs/blockchain.log';

        if (!is_dir(dirname($this->logFile))) {
            @mkdir(dirname($this->logFile), 0755, true);
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ── الدورة الرئيسية ──────────────────────────────────────

    /**
     * تشغيل دورة المراقبة الكاملة
     */
    public function run(): array
    {
        $results = ['checked' => 0, 'confirmed' => 0, 'errors' => 0];

        // [1] مراقبة التحويلات الواردة (للمحافظ المنتظِرة)
        $inbound = $this->scanInboundTRC20();
        $results['checked']   += $inbound['checked'];
        $results['confirmed'] += $inbound['confirmed'];
        $results['errors']    += $inbound['errors'];

        // [2] تحديث تأكيدات التحويلات الصادرة المعلقة
        $outbound = $this->updateOutboundConfirmations();
        $results['checked']   += $outbound['checked'];
        $results['confirmed'] += $outbound['confirmed'];

        $this->log("دورة اكتملت: " . json_encode($results));
        return $results;
    }

    // ── مسح التحويلات الواردة ────────────────────────────────

    /**
     * يبحث عن محافظ تنتظر استقبال USDT TRC20
     */
    private function scanInboundTRC20(): array
    {
        $results = ['checked' => 0, 'confirmed' => 0, 'errors' => 0];

        // جلب المعاملات المعلقة التي تنتظر استقبال crypto
        $pendingTxns = $this->db->query(
            "SELECT t.*, w.address as deposit_address
             FROM " . DB_PREFIX . "transactions t
             LEFT JOIN " . DB_PREFIX . "user_wallets w
               ON w.user_id = t.user_id AND w.network = 'TRC20' AND w.coin = 'USDT'
             WHERE t.gateway = 'crypto_deposit'
               AND t.status = 'pending'
               AND w.address IS NOT NULL
               AND t.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             LIMIT 50"
        );

        foreach ($pendingTxns as $txn) {
            $results['checked']++;
            try {
                $found = $this->checkTRC20Deposit(
                    $txn['deposit_address'],
                    (float)$txn['amount'],
                    $txn['reference']
                );

                if ($found) {
                    $results['confirmed']++;
                    $this->onDepositConfirmed($txn, $found);
                }
            } catch (Exception $e) {
                $results['errors']++;
                $this->log("خطأ في فحص {$txn['reference']}: " . $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * التحقق من وصول USDT لعنوان معين عبر TronGrid
     */
    public function checkTRC20Deposit(string $address, float $expectedAmount, string $reference): ?array
    {
        $url = self::TRON_API_BASE . "/v1/accounts/{$address}/transactions/trc20"
             . "?limit=20&contract_address=" . self::USDT_CONTRACTS['TRC20']
             . "&only_confirmed=false";

        $response = $this->httpGet($url);
        if ($response === null) return null;

        $data = json_decode($response, true);
        if (empty($data['data'])) return null;

        foreach ($data['data'] as $tx) {
            // نبحث عن تحويل وارد بالمبلغ المتوقع (±1%)
            $toAddress = $tx['to'] ?? '';
            $value     = isset($tx['value']) ? (float)$tx['value'] / 1_000_000 : 0; // USDT = 6 decimals
            $txHash    = $tx['transaction_id'] ?? '';

            if (strtolower($toAddress) !== strtolower($address)) continue;

            $tolerance = $expectedAmount * 0.01; // 1% tolerance
            if (abs($value - $expectedAmount) > $tolerance) continue;

            // تحقق من عدم معالجته مسبقاً
            $existing = $this->db->find('blockchain_txns', ['tx_hash' => $txHash]);
            if ($existing) continue;

            return [
                'tx_hash'       => $txHash,
                'from_address'  => $tx['from'] ?? '',
                'to_address'    => $toAddress,
                'amount'        => $value,
                'network'       => 'TRC20',
                'confirmations' => $tx['confirmed'] ? 20 : 0,
                'confirmed'     => $tx['confirmed'] ?? false,
            ];
        }

        return null;
    }

    /**
     * يُنفَّذ عند تأكيد الإيداع
     */
    private function onDepositConfirmed(array $txn, array $txData): void
    {
        $now = date('Y-m-d H:i:s');

        // [1] حفظ معاملة البلوكشين
        $this->db->insert('blockchain_txns', [
            'reference'     => $txn['reference'],
            'network'       => 'TRC20',
            'coin'          => 'USDT',
            'tx_hash'       => $txData['tx_hash'],
            'from_address'  => $txData['from_address'],
            'to_address'    => $txData['to_address'],
            'amount'        => $txData['amount'],
            'fee'           => 1.0, // TRX fee تقريبي
            'confirmations' => $txData['confirmations'],
            'required_conf' => WalletService::REQUIRED_CONFIRMATIONS['TRC20'],
            'direction'     => 'in',
            'status'        => $txData['confirmed'] ? 'confirmed' : 'pending',
            'raw_response'  => json_encode($txData),
            'created_at'    => $now,
            'confirmed_at'  => $txData['confirmed'] ? $now : null,
        ]);

        // [2] تحديث حالة المعاملة الرئيسية
        if ($txData['confirmed']) {
            $this->db->update('transactions', [
                'status'           => 'completed',
                'gateway_response' => json_encode($txData),
                'updated_at'       => $now,
            ], ['reference' => $txn['reference']]);

            // [3] تسجيل الحدث
            $this->logEvent('crypto.deposit.confirmed', $txn['reference'], (int)$txn['user_id'], $txData);

            $this->log("✓ إيداع مؤكد: {$txn['reference']} — {$txData['amount']} USDT [{$txData['tx_hash']}]");
        }
    }

    // ── تحديث تأكيدات الصادر ─────────────────────────────────

    private function updateOutboundConfirmations(): array
    {
        $results = ['checked' => 0, 'confirmed' => 0];

        $pending = $this->db->query(
            "SELECT * FROM " . DB_PREFIX . "blockchain_txns
             WHERE direction = 'out'
               AND status IN ('broadcasting', 'pending')
               AND tx_hash IS NOT NULL
               AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             LIMIT 30"
        );

        foreach ($pending as $btxn) {
            $results['checked']++;
            try {
                $info = $this->getTRC20TxInfo($btxn['tx_hash']);
                if (!$info) continue;

                $confirmed = $info['confirmed'] ?? false;
                $conf      = $confirmed ? (WalletService::REQUIRED_CONFIRMATIONS['TRC20']) : 0;

                if ($confirmed) {
                    $results['confirmed']++;
                    $now = date('Y-m-d H:i:s');

                    $this->db->update('blockchain_txns', [
                        'status'        => 'confirmed',
                        'confirmations' => $conf,
                        'confirmed_at'  => $now,
                        'raw_response'  => json_encode($info),
                    ], ['id' => $btxn['id']]);

                    // تحديث المعاملة الرئيسية
                    if (!empty($btxn['reference'])) {
                        $this->db->update('transactions', [
                            'status'     => 'completed',
                            'updated_at' => $now,
                        ], ['reference' => $btxn['reference']]);

                        $this->logEvent('crypto.send.confirmed', $btxn['reference'], null, [
                            'tx_hash' => $btxn['tx_hash'],
                            'amount'  => $btxn['amount'],
                        ]);
                    }

                    $this->log("✓ إرسال مؤكد: {$btxn['tx_hash']}");
                }
            } catch (Exception $e) {
                $this->log("خطأ تأكيد {$btxn['tx_hash']}: " . $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * جلب معلومات معاملة TRC20 بالـ hash
     */
    public function getTRC20TxInfo(string $txHash): ?array
    {
        $url      = self::TRON_API_BASE . "/v1/transactions/{$txHash}";
        $response = $this->httpGet($url);
        if ($response === null) return null;

        $data = json_decode($response, true);
        return $data['data'][0] ?? null;
    }

    // ── مساعدات ─────────────────────────────────────────────

    private function httpGet(string $url, int $timeout = 10): ?string
    {
        if (!function_exists('curl_init')) return null;

        $apiKey = getenv('TRONGRID_API_KEY') ?: '';
        $headers = ['Accept: application/json'];
        if ($apiKey) $headers[] = 'TRON-PRO-API-KEY: ' . $apiKey;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'DI-PARMA-Gateway/1.0',
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $response = curl_exec($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($response !== false && $code === 200) ? $response : null;
    }

    private function log(string $message): void
    {
        $line = "[" . date('Y-m-d H:i:s') . "] " . $message . PHP_EOL;
        @file_put_contents($this->logFile, $line, FILE_APPEND);
    }

    private function logEvent(string $type, ?string $reference, ?int $userId, array $payload): void
    {
        try {
            $this->db->insert('event_log', [
                'event_type' => $type,
                'reference'  => $reference,
                'user_id'    => $userId,
                'payload'    => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'processed'  => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Exception $e) {
            // صامت
        }
    }
}

// ── تشغيل مباشر كـ CLI Worker ───────────────────────────────
if (PHP_SAPI === 'cli' && basename(__FILE__) === basename($argv[0] ?? '')) {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/database.php';
    $monitor = BlockchainMonitor::getInstance();
    $result  = $monitor->run();
    echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
}
