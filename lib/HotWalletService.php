<?php
/**
 * ============================================================
 * DI PARMA | HotWalletService
 * إرسال USDT من Hot Wallet للمستخدمين عبر TronGrid API
 * ============================================================
 * يُستدعى بعد تأكيد دفع الفيات
 * ============================================================
 */

class HotWalletService
{
    private const TRON_API_BASE   = 'https://api.trongrid.io';
    private const TRON_FULLNODE   = 'https://api.trongrid.io';

    // USDT TRC20 Contract
    private const USDT_CONTRACT   = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

    // رسوم TRX المقدّرة لكل معاملة USDT
    private const ESTIMATED_FEE_TRX = 15.0;

    // الحد الأدنى للتنبيه بانخفاض الرصيد
    private const MIN_HOT_BALANCE  = 500.0;

    private static ?self $instance = null;
    private Database $db;
    private string $hotWalletAddress;
    private string $hotWalletEncryptedKey;
    private string $logFile;

    private function __construct()
    {
        $this->db      = db();
        $this->logFile = defined('LOGS_PATH') ? LOGS_PATH . '/hot_wallet.log' : __DIR__ . '/../logs/hot_wallet.log';

        if (!is_dir(dirname($this->logFile))) {
            @mkdir(dirname($this->logFile), 0755, true);
        }

        // قراءة بيانات Hot Wallet من .env
        $this->hotWalletAddress      = getenv('HOT_WALLET_TRC20_ADDRESS') ?: '';
        $this->hotWalletEncryptedKey = getenv('HOT_WALLET_TRC20_KEY')     ?: '';
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
     * إرسال USDT TRC20 للمستخدم بعد تأكيد الدفع الفيات
     *
     * @param string $reference   مرجع المعاملة الداخلي
     * @param string $toAddress   عنوان محفظة المستخدم TRC20
     * @param float  $amount      مبلغ USDT
     * @param int    $userId
     */
    public function sendUSDT(string $reference, string $toAddress, float $amount, int $userId): array
    {
        // [1] التحقق من الإعداد
        if (empty($this->hotWalletAddress)) {
            return $this->fail($reference, 'HOT_WALLET_TRC20_ADDRESS غير مضبوط في .env');
        }

        // [2] التحقق من عدم إرسالها مسبقاً
        $existing = $this->db->find('blockchain_txns', ['reference' => $reference, 'direction' => 'out']);
        if ($existing) {
            return [
                'success'  => true,
                'tx_hash'  => $existing['tx_hash'],
                'status'   => $existing['status'],
                'message'  => 'تم الإرسال مسبقاً',
                'duplicate' => true,
            ];
        }

        // [3] التحقق من الرصيد
        $balance = $this->getHotBalance();
        if ($balance < $amount) {
            $this->alertLowBalance($balance);
            return $this->fail($reference, "رصيد Hot Wallet غير كافٍ: {$balance} USDT < {$amount} USDT");
        }

        // [4] حجز المبلغ في Treasury
        $this->reserveBalance($amount);

        // [5] حفظ سجل المحاولة
        $txnDbId = $this->db->insert('blockchain_txns', [
            'reference'     => $reference,
            'network'       => 'TRC20',
            'coin'          => 'USDT',
            'tx_hash'       => null,
            'from_address'  => $this->hotWalletAddress,
            'to_address'    => $toAddress,
            'amount'        => $amount,
            'fee'           => self::ESTIMATED_FEE_TRX,
            'confirmations' => 0,
            'required_conf' => WalletService::REQUIRED_CONFIRMATIONS['TRC20'],
            'direction'     => 'out',
            'status'        => 'broadcasting',
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        // [6] بناء وإرسال المعاملة
        try {
            $txResult = $this->broadcastTRC20($toAddress, $amount);

            if (!$txResult['success']) {
                // تحرير الحجز عند الفشل
                $this->releaseReserve($amount);
                $this->db->update('blockchain_txns', ['status' => 'failed'], ['id' => $txnDbId]);
                return $this->fail($reference, $txResult['message']);
            }

            $txHash = $txResult['tx_hash'];

            // [7] تحديث السجل بالـ hash
            $this->db->update('blockchain_txns', [
                'tx_hash'      => $txHash,
                'status'       => 'pending',
                'raw_response' => json_encode($txResult),
            ], ['id' => $txnDbId]);

            // [8] تحديث رصيد Hot Wallet
            $this->deductHotBalance($amount);

            // [9] تسجيل حدث
            $this->logEvent('crypto.send.initiated', $reference, $userId, [
                'tx_hash'    => $txHash,
                'amount'     => $amount,
                'to_address' => $toAddress,
                'network'    => 'TRC20',
            ]);

            $this->log("✓ إرسال: {$amount} USDT → {$toAddress} | Hash: {$txHash}");

            return [
                'success'    => true,
                'tx_hash'    => $txHash,
                'amount'     => $amount,
                'to_address' => $toAddress,
                'network'    => 'TRC20',
                'status'     => 'pending',
                'message'    => 'تم الإرسال — في انتظار التأكيد على البلوكشين',
                'explorer'   => "https://tronscan.org/#/transaction/{$txHash}",
            ];

        } catch (Exception $e) {
            $this->releaseReserve($amount);
            $this->db->update('blockchain_txns', ['status' => 'failed'], ['id' => $txnDbId]);
            return $this->fail($reference, 'استثناء: ' . $e->getMessage());
        }
    }

    /**
     * جلب رصيد USDT في Hot Wallet من TronGrid
     */
    public function getHotBalance(): float
    {
        if (empty($this->hotWalletAddress)) return 0.0;

        // أولاً من DB (أسرع)
        $row = $this->db->find('treasury_balances', [
            'coin'    => 'USDT',
            'network' => 'TRC20',
        ]);

        if ($row) {
            return (float)$row['hot_balance'] - (float)$row['reserved'];
        }

        // ثانياً من TronGrid مباشرة
        return $this->fetchLiveBalance($this->hotWalletAddress);
    }

    /**
     * تحديث رصيد Hot Wallet من TronGrid ومزامنته مع DB
     */
    public function syncBalance(): float
    {
        if (empty($this->hotWalletAddress)) return 0.0;

        $liveBalance = $this->fetchLiveBalance($this->hotWalletAddress);

        $this->db->execute(
            "INSERT INTO " . DB_PREFIX . "treasury_balances (coin, network, hot_balance, cold_balance, reserved, updated_at)
             VALUES (?, ?, ?, 0, 0, ?)
             ON DUPLICATE KEY UPDATE hot_balance = VALUES(hot_balance), updated_at = VALUES(updated_at)",
            ['USDT', 'TRC20', $liveBalance, date('Y-m-d H:i:s')]
        );

        return $liveBalance;
    }

    // ── Broadcast TRC20 ──────────────────────────────────────

    /**
     * بناء وبثّ معاملة USDT TRC20 عبر TronGrid
     * في الإنتاج: يحتاج مكتبة IEXBase/tron-api أو php-tron
     */
    private function broadcastTRC20(string $toAddress, float $amount): array
    {
        $apiKey = getenv('TRONGRID_API_KEY') ?: '';

        // تحويل المبلغ لـ Sun (1 USDT = 1,000,000 Sun)
        $amountSun = (int)($amount * 1_000_000);

        // [A] بناء المعاملة عبر TronGrid
        $buildUrl  = self::TRON_FULLNODE . '/wallet/triggersmartcontract';
        $buildBody = [
            'owner_address'     => $this->tronAddressToHex($this->hotWalletAddress),
            'contract_address'  => $this->tronAddressToHex(self::USDT_CONTRACT),
            'function_selector' => 'transfer(address,uint256)',
            'parameter'         => $this->encodeTransferParams($toAddress, $amountSun),
            'fee_limit'         => 100_000_000, // 100 TRX
            'call_value'        => 0,
        ];

        $buildResponse = $this->httpPost($buildUrl, $buildBody, $apiKey);
        if (!$buildResponse || empty($buildResponse['transaction'])) {
            return ['success' => false, 'message' => 'فشل بناء المعاملة: ' . json_encode($buildResponse)];
        }

        $rawTx = $buildResponse['transaction'];

        // [B] توقيع المعاملة
        $privateKey = $this->getHotWalletPrivateKey();
        if (empty($privateKey)) {
            return ['success' => false, 'message' => 'مفتاح Hot Wallet غير متاح'];
        }

        $signedTx = $this->signTransaction($rawTx, $privateKey);

        // [C] بثّ المعاملة
        $broadcastUrl      = self::TRON_FULLNODE . '/wallet/broadcasttransaction';
        $broadcastResponse = $this->httpPost($broadcastUrl, $signedTx, $apiKey);

        if (!$broadcastResponse || empty($broadcastResponse['result'])) {
            return [
                'success' => false,
                'message' => 'فشل بثّ المعاملة: ' . ($broadcastResponse['message'] ?? json_encode($broadcastResponse)),
            ];
        }

        return [
            'success' => true,
            'tx_hash' => $rawTx['txID'] ?? '',
        ];
    }

    // ── مساعدات Tron ────────────────────────────────────────

    private function tronAddressToHex(string $base58Address): string
    {
        // تحويل Base58Check → Hex
        $decoded = $this->base58Decode($base58Address);
        return '0x' . bin2hex(substr($decoded, 0, -4));
    }

    private function encodeTransferParams(string $toAddress, int $amountSun): string
    {
        // ABI encoding: (address, uint256)
        $toHex     = str_pad(ltrim($this->tronAddressToHex($toAddress), '0x'), 64, '0', STR_PAD_LEFT);
        $amountHex = str_pad(dechex($amountSun), 64, '0', STR_PAD_LEFT);
        return $toHex . $amountHex;
    }

    private function signTransaction(array $rawTx, string $privateKey): array
    {
        // في الإنتاج: استخدم مكتبة ECDSA حقيقية مثل phpseclib أو kornrunner/ethereum-offline-raw-tx
        // هنا placeholder لهيكل البيانات
        $txId      = $rawTx['txID'] ?? '';
        $signature = hash_hmac('sha256', $txId, $privateKey); // يُستبدل بـ ECDSA secp256k1

        return array_merge($rawTx, ['signature' => [$signature]]);
    }

    private function getHotWalletPrivateKey(): string
    {
        if (empty($this->hotWalletEncryptedKey)) return '';

        try {
            $walletService = WalletService::getInstance();
            return $walletService->decryptKey($this->hotWalletEncryptedKey);
        } catch (Exception $e) {
            $this->log("فشل فك تشفير مفتاح Hot Wallet: " . $e->getMessage());
            return '';
        }
    }

    private function fetchLiveBalance(string $address): float
    {
        $url      = self::TRON_API_BASE . "/v1/accounts/{$address}";
        $response = $this->httpGet($url);
        if (!$response) return 0.0;

        $data = json_decode($response, true);
        $trc20Balances = $data['data'][0]['trc20'] ?? [];

        foreach ($trc20Balances as $contract => $balance) {
            if (strtolower($contract) === strtolower(self::USDT_CONTRACT)) {
                return (float)$balance / 1_000_000;
            }
        }
        return 0.0;
    }

    // ── إدارة الرصيد في Treasury ─────────────────────────────

    private function reserveBalance(float $amount): void
    {
        $this->db->execute(
            "UPDATE " . DB_PREFIX . "treasury_balances
             SET reserved = reserved + ?, updated_at = ?
             WHERE coin = 'USDT' AND network = 'TRC20'",
            [$amount, date('Y-m-d H:i:s')]
        );
    }

    private function releaseReserve(float $amount): void
    {
        $this->db->execute(
            "UPDATE " . DB_PREFIX . "treasury_balances
             SET reserved = GREATEST(0, reserved - ?), updated_at = ?
             WHERE coin = 'USDT' AND network = 'TRC20'",
            [$amount, date('Y-m-d H:i:s')]
        );
    }

    private function deductHotBalance(float $amount): void
    {
        $this->db->execute(
            "UPDATE " . DB_PREFIX . "treasury_balances
             SET hot_balance = GREATEST(0, hot_balance - ?),
                 reserved    = GREATEST(0, reserved - ?),
                 updated_at  = ?
             WHERE coin = 'USDT' AND network = 'TRC20'",
            [$amount, $amount, date('Y-m-d H:i:s')]
        );
    }

    private function alertLowBalance(float $balance): void
    {
        $this->log("⚠ تنبيه: رصيد Hot Wallet منخفض! المتاح: {$balance} USDT");
        $this->logEvent('treasury.low_balance', null, null, [
            'balance'   => $balance,
            'minimum'   => self::MIN_HOT_BALANCE,
            'network'   => 'TRC20',
            'coin'      => 'USDT',
        ]);
    }

    // ── HTTP ─────────────────────────────────────────────────

    private function httpGet(string $url, int $timeout = 10): ?string
    {
        if (!function_exists('curl_init')) return null;
        $apiKey  = getenv('TRONGRID_API_KEY') ?: '';
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
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($res !== false && $code === 200) ? $res : null;
    }

    private function httpPost(string $url, array $body, string $apiKey = ''): ?array
    {
        if (!function_exists('curl_init')) return null;
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($apiKey) $headers[] = 'TRON-PRO-API-KEY: ' . $apiKey;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'DI-PARMA-Gateway/1.0',
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res === false || $code < 200 || $code >= 300) return null;
        return json_decode($res, true);
    }

    private function base58Decode(string $input): string
    {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $num      = gmp_init(0);
        $base     = gmp_init(58);

        foreach (str_split($input) as $char) {
            $pos = strpos($alphabet, $char);
            $num = gmp_add(gmp_mul($num, $base), gmp_init($pos));
        }

        return gmp_export($num);
    }

    private function fail(string $reference, string $message): array
    {
        $this->log("✗ فشل {$reference}: {$message}");
        $this->logEvent('crypto.send.failed', $reference, null, ['message' => $message]);
        return ['success' => false, 'message' => $message];
    }

    private function log(string $message): void
    {
        @file_put_contents($this->logFile, "[" . date('Y-m-d H:i:s') . "] $message\n", FILE_APPEND);
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
        } catch (Exception $e) { /* صامت */ }
    }
}
