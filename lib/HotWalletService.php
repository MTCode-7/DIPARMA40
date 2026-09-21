<?php
/**
 * ============================================================
 * DI PARMA | HotWalletService
 * إرسال USDT من Hot Wallet للمستخدمين عبر TronGrid API
 * ============================================================
 * يُستدعى بعد تأكيد دفع الفيات
 * ============================================================
 */

require_once __DIR__ . '/../includes/base58.php';

if (class_exists('HotWalletService', false)) {
    return;
}

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
        if (!class_exists('WalletService', false)) {
            require_once __DIR__ . '/WalletService.php';
        }

        // [1] التحقق من الإعداد — لا بث بدون عنوان ومفتاح
        if (empty($this->hotWalletAddress)) {
            return $this->fail($reference, 'HOT_WALLET_TRC20_ADDRESS غير مضبوط في .env', ['error_code' => 'CONFIG']);
        }
        if ($this->getHotWalletPrivateKey() === '') {
            return $this->fail($reference, 'مفتاح Hot Wallet غير متاح', ['error_code' => 'CONFIG']);
        }

        // [2] منع الإرسال المزدوج فقط إذا وُجد بث سابق (hash أو حالة قيد التنفيذ)
        $existing = $this->db->find('blockchain_txns', ['reference' => $reference, 'direction' => 'out']);
        $txnDbId = null;
        if ($existing) {
            $prevStatus = (string) ($existing['status'] ?? '');
            $prevHash   = trim((string) ($existing['tx_hash'] ?? ''));
            if ($prevHash !== '' || in_array($prevStatus, ['pending', 'confirmed', 'broadcasting'], true)) {
                return [
                    'success'   => true,
                    'tx_hash'   => $prevHash !== '' ? $prevHash : ($existing['tx_hash'] ?? null),
                    'status'    => $prevStatus !== '' ? $prevStatus : 'pending',
                    'message'   => 'تم الإرسال مسبقاً',
                    'duplicate' => true,
                ];
            }
            $txnDbId = (int) ($existing['id'] ?? 0) ?: null;
        }

        // [3] الرصيد من السلسلة (TronGrid) ناقص المحجوز — ليس رقم DB القديم وحده
        $funds = $this->syncAndGetFunds();
        $balance = $funds['usdt'];
        if ($balance < $amount) {
            $this->alertLowBalance($balance);
            return $this->fail(
                $reference,
                "رصيد Hot Wallet غير كافٍ: {$balance} USDT < {$amount} USDT",
                ['error_code' => 'INSUFFICIENT_USDT', 'balance' => $balance, 'required' => $amount]
            );
        }
        if ($funds['trx'] < self::ESTIMATED_FEE_TRX) {
            return $this->fail(
                $reference,
                "رصيد TRX غير كافٍ للغاز: {$funds['trx']} < " . self::ESTIMATED_FEE_TRX,
                ['error_code' => 'INSUFFICIENT_TRX', 'trx' => $funds['trx']]
            );
        }

        // [4] حجز المبلغ في Treasury
        $this->reserveBalance($amount);

        // [5] حفظ أو إعادة محاولة السجل الفاشل
        if ($txnDbId) {
            $this->db->update('blockchain_txns', [
                'to_address'   => $toAddress,
                'amount'       => $amount,
                'status'       => 'broadcasting',
                'from_address' => $this->hotWalletAddress,
            ], ['id' => $txnDbId]);
        } else {
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
        }

        // [6] بناء وإرسال المعاملة
        try {
            $txResult = $this->broadcastTRC20($toAddress, $amount);

            if (!$txResult['success']) {
                $this->releaseReserve($amount);
                $this->db->update('blockchain_txns', ['status' => 'failed'], ['id' => $txnDbId]);
                return $this->fail($reference, $txResult['message'], ['error_code' => 'BROADCAST_FAILED']);
            }

            $txHash = $txResult['tx_hash'];

            $this->db->update('blockchain_txns', [
                'tx_hash'      => $txHash,
                'status'       => 'pending',
                'raw_response' => json_encode($txResult),
            ], ['id' => $txnDbId]);

            $this->deductHotBalance($amount);

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
            return $this->fail($reference, 'استثناء: ' . $e->getMessage(), ['error_code' => 'EXCEPTION']);
        }
    }

    /**
     * المتاح للإنفاق: رصيد السلسلة الحي − المحجوز في DB.
     */
    public function getHotBalance(): float
    {
        return $this->syncAndGetFunds()['usdt'];
    }

    /**
     * مزامنة USDT من TronGrid ثم إرجاع المتاح + TRX للغاز.
     *
     * @return array{usdt:float,trx:float,live_usdt:float,reserved:float}
     */
    public function syncAndGetFunds(): array
    {
        $empty = ['usdt' => 0.0, 'trx' => 0.0, 'live_usdt' => 0.0, 'reserved' => 0.0];
        if (empty($this->hotWalletAddress)) {
            return $empty;
        }

        $account  = $this->fetchAccount($this->hotWalletAddress);
        $liveUsdt = $this->parseUsdtBalance($account);
        $trx      = $this->parseTrxBalance($account);

        $this->db->execute(
            "INSERT INTO " . DB_PREFIX . "treasury_balances (coin, network, hot_balance, cold_balance, reserved, updated_at)
             VALUES (?, ?, ?, 0, 0, ?)
             ON DUPLICATE KEY UPDATE hot_balance = VALUES(hot_balance), updated_at = VALUES(updated_at)",
            ['USDT', 'TRC20', $liveUsdt, date('Y-m-d H:i:s')]
        );

        $row      = $this->db->find('treasury_balances', ['coin' => 'USDT', 'network' => 'TRC20']);
        $reserved = $row ? (float) $row['reserved'] : 0.0;

        return [
            'usdt'      => max(0.0, $liveUsdt - $reserved),
            'trx'       => $trx,
            'live_usdt' => $liveUsdt,
            'reserved'  => $reserved,
        ];
    }

    /**
     * تحديث رصيد Hot Wallet من TronGrid ومزامنته مع DB
     */
    public function syncBalance(): float
    {
        return $this->syncAndGetFunds()['live_usdt'];
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
        if (empty($signedTx['signature'])) {
            return ['success' => false, 'message' => 'فشل توقيع المعاملة'];
        }

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
        $txId = $rawTx['txID'] ?? '';
        if ($txId === '' || $privateKey === '') {
            throw new \RuntimeException('signTransaction: txID أو privateKey فارغ');
        }

        if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
            @require_once __DIR__ . '/../vendor/autoload.php';
        }
        if (class_exists('\\phpseclib3\\Crypt\\EC', false)) {
            try {
                $privBin = hex2bin(ltrim($privateKey, '0x'));
                $key     = \phpseclib3\Crypt\EC::loadPrivateKeyFormat('Raw', $privBin)->withCurve('secp256k1');
                $sig     = bin2hex($key->withSignatureFormat('IEEE')->sign(hex2bin($txId)));
                $this->log("✓ Transaction signed locally: txID={$txId}");
                return array_merge($rawTx, ['signature' => [$sig]]);
            } catch (\Throwable $e) {
                $this->log("local sign failed: " . $e->getMessage());
            }
        }

        // احتياطي فقط إن لم تتوفر مكتبة التوقيع المحلية
        $apiKey = getenv('TRONGRID_API_KEY') ?: '';
        $signResponse = $this->httpPost(
            self::TRON_FULLNODE . '/wallet/gettransactionsign',
            ['transaction' => $rawTx, 'privateKey' => $privateKey],
            $apiKey
        );
        if (!empty($signResponse['signature']) && is_array($signResponse['signature'])) {
            $this->log("✓ Transaction signed via TronGrid fallback: txID={$txId}");
            return $signResponse;
        }

        throw new \RuntimeException(
            'لا يمكن توقيع معاملة Tron محلياً. شغّل: composer require phpseclib/phpseclib:~3.0'
        );
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
        return $this->parseUsdtBalance($this->fetchAccount($address));
    }

    private function fetchAccount(string $address): array
    {
        $url      = self::TRON_API_BASE . '/v1/accounts/' . rawurlencode($address);
        $response = $this->httpGet($url);
        if (!$response) {
            return [];
        }
        $data = json_decode($response, true);
        return is_array($data['data'][0] ?? null) ? $data['data'][0] : [];
    }

    private function parseUsdtBalance(array $account): float
    {
        $trc20    = $account['trc20'] ?? [];
        $contract = self::USDT_CONTRACT;
        if (!is_array($trc20)) {
            return 0.0;
        }
        foreach ($trc20 as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $c => $bal) {
                    if (strcasecmp((string) $c, $contract) === 0) {
                        return (float) $bal / 1_000_000;
                    }
                }
            } elseif (is_string($key) && strcasecmp($key, $contract) === 0) {
                return (float) $value / 1_000_000;
            }
        }
        return 0.0;
    }

    private function parseTrxBalance(array $account): float
    {
        return (float) ($account['balance'] ?? 0) / 1_000_000;
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
        return dp_base58_decode($input);
    }

    private function fail(string $reference, string $message, array $extra = []): array
    {
        $this->log("✗ فشل {$reference}: {$message}");
        $this->logEvent('crypto.send.failed', $reference, null, array_merge(['message' => $message], $extra));
        return array_merge(['success' => false, 'message' => $message], $extra);
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
