<?php
/**
 * ============================================================
 * DI PARMA | Stage 10: Wallet & Blockchain Execution
 * ============================================================
 * • Build TRC20 USDT transfer transaction
 * • Sign via HSM/Private Key
 * • Broadcast to TRON network
 * • Return TXID for monitoring
 * ============================================================
 */
require_once __DIR__ . '/../lib/TronSigner.php';

class BlockchainExecutor
{
    const USDT_CONTRACT   = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
    const TRON_API        = 'https://api.trongrid.io';
    const FEE_LIMIT_SUN   = 20_000_000; // 20 TRX in SUN
    const USDT_DECIMALS   = 6;

    private string $hotWalletAddr;
    private string $hotWalletKey;
    private string $tronGridKey;
    private ?object $db;

    public function __construct()
    {
        $this->hotWalletAddr = getenv('HOT_WALLET_TRC20_ADDRESS') ?: '';
        $this->hotWalletKey  = getenv('HOT_WALLET_TRC20_KEY')     ?: '';
        $this->tronGridKey   = getenv('TRONGRID_API_KEY')         ?: '';

        try {
            require_once __DIR__ . '/../includes/database.php';
            $this->db = db();
        } catch (Exception $e) { $this->db = null; }
    }

    // ── MAIN: Execute USDT transfer to Ledger ───────────────
    public function execute(string $toAddress, float $usdtAmount, string $reference): array
    {
        $start = microtime(true);

        // Validation
        if (empty($toAddress) || !$this->isValidTronAddress($toAddress)) {
            return $this->fail('INVALID_ADDRESS', 'Invalid destination TRC20 address', $reference);
        }
        if ($usdtAmount <= 0) {
            return $this->fail('INVALID_AMOUNT', 'Amount must be > 0', $reference);
        }
        if (empty($this->hotWalletKey)) {
            // Queue for manual processing
            return $this->queueTransfer($toAddress, $usdtAmount, $reference, 'Private key not configured');
        }

        try {
            // Step 1: Build transaction
            $txBuild = $this->buildTRC20Transfer($toAddress, $usdtAmount);
            if (!$txBuild['success']) {
                return $this->fail('BUILD_FAILED', $txBuild['message'], $reference);
            }

            // Step 2: Sign transaction
            $signedTx = $this->signTransaction($txBuild['transaction']);
            if (!$signedTx['success']) {
                return $this->fail('SIGN_FAILED', $signedTx['message'], $reference);
            }

            // Step 3: Broadcast
            $broadcast = $this->broadcastTransaction($signedTx['signed_tx']);
            $duration  = round((microtime(true) - $start) * 1000);

            if ($broadcast['success']) {
                $txid = $broadcast['txid'];
                $this->saveTransfer($reference, $toAddress, $usdtAmount, $txid, 'broadcasting');

                return [
                    'success'      => true,
                    'txid'         => $txid,
                    'status'       => 'broadcasting',
                    'to_address'   => $toAddress,
                    'usdt_amount'  => $usdtAmount,
                    'reference'    => $reference,
                    'tronscan_url' => "https://tronscan.org/#/transaction/{$txid}",
                    'duration_ms'  => $duration,
                    'timestamp'    => date('c'),
                ];
            }

            return $this->fail('BROADCAST_FAILED', $broadcast['message'], $reference);

        } catch (Exception $e) {
            error_log('[BlockchainExecutor] ' . $e->getMessage());
            return $this->queueTransfer($toAddress, $usdtAmount, $reference, $e->getMessage());
        }
    }

    // ── Build TRC20 Transfer ─────────────────────────────────
    private function buildTRC20Transfer(string $toAddress, float $amount): array
    {
        $amountSun = (int)bcmul((string)$amount, '1000000', 0);

        // ABI encode: transfer(address,uint256)
        $addressHex = TronSigner::abiWord($toAddress);
        $amountHex  = str_pad(dechex($amountSun), 64, '0', STR_PAD_LEFT);
        $parameter  = $addressHex . $amountHex;

        $body = [
            'owner_address'    => TronSigner::addressHex($this->hotWalletAddr),
            'contract_address' => TronSigner::addressHex(self::USDT_CONTRACT),
            'function_selector'=> 'transfer(address,uint256)',
            'parameter'        => $parameter,
            'fee_limit'        => self::FEE_LIMIT_SUN,
            'call_value'       => 0,
            'visible'          => false,
        ];

        $response = $this->tronPost('/wallet/triggersmartcontract', $body);

        if (empty($response['transaction'])) {
            return ['success'=>false,'message'=>'TronGrid build failed: '.json_encode($response)];
        }

        return ['success'=>true,'transaction'=>$response['transaction'],'raw'=>$response];
    }

    // ── Sign Transaction ─────────────────────────────────────
    private function signTransaction(array $tx): array
    {
        // فك تشفير Private Key
        $encKey = $this->hotWalletKey;
        $privKey = $this->decryptKey($encKey);

        if (empty($privKey)) {
            return ['success'=>false,'message'=>'Cannot decrypt private key'];
        }

        // TRON transaction signing using secp256k1
        // TXHash = tx['txID']
        $txHash = $tx['txID'] ?? '';
        if (empty($txHash)) {
            return ['success'=>false,'message'=>'Missing txID'];
        }

        // Sign hash with private key (requires openssl/secp256k1)
        try {
            $signature = TronSigner::signTxId($txHash, $privKey);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $tx['signature'] = [$signature];
        return ['success'=>true,'signed_tx'=>$tx];
    }

    // ── Broadcast Transaction ────────────────────────────────
    private function broadcastTransaction(array $signedTx): array
    {
        $response = $this->tronPost('/wallet/broadcasttransaction', $signedTx);

        if (!empty($response['result']) && $response['result'] === true) {
            return [
                'success' => true,
                'txid'    => $signedTx['txID'] ?? ($response['txid'] ?? ''),
            ];
        }

        return [
            'success' => false,
            'message' => $response['message'] ?? 'Broadcast failed: '.json_encode($response),
        ];
    }

    // ── Queue Transfer (when key unavailable) ───────────────
    private function queueTransfer(string $to, float $amount, string $ref, string $reason): array
    {
        $queueId = 'LEDGER-Q-' . strtoupper(substr(md5($ref.time()), 0, 10));
        if ($this->db) {
            try {
                $this->db->execute(
                    "CREATE TABLE IF NOT EXISTS dp_ledger_transfer_queue (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        reference VARCHAR(100), ledger_address VARCHAR(100),
                        usdt_amount DECIMAL(18,8), currency_orig VARCHAR(10) DEFAULT 'USD',
                        status ENUM('queued','processing','completed','failed') DEFAULT 'queued',
                        txid VARCHAR(100) DEFAULT NULL, error_msg TEXT,
                        attempts TINYINT DEFAULT 0, created_at DATETIME, updated_at DATETIME
                    ) ENGINE=InnoDB"
                );
                $this->db->insert('ledger_transfer_queue', [
                    'reference'      => $ref,
                    'ledger_address' => $to,
                    'usdt_amount'    => $amount,
                    'status'         => 'queued',
                    'txid'           => $queueId,
                    'error_msg'      => $reason,
                    'created_at'     => date('Y-m-d H:i:s'),
                    'updated_at'     => date('Y-m-d H:i:s'),
                ]);
            } catch (Exception $e) {}
        }

        return [
            'success'      => false,
            'queued'       => true,
            'status'       => 'queued',
            'to_address'   => $to,
            'usdt_amount'  => $amount,
            'reference'    => $ref,
            'message'      => 'Transfer not broadcast: ' . $reason,
            'timestamp'    => date('c'),
        ];
    }

    private function saveTransfer(string $ref, string $to, float $amount, string $txid, string $status): void
    {
        if (!$this->db) return;
        try {
            $this->db->execute(
                "UPDATE dp_transactions SET ledger_txid=?, ledger_status=? WHERE reference=?",
                [$txid, $status, $ref]
            );
        } catch (Exception $e) {}
    }

    // ── TRON Utilities ───────────────────────────────────────
    private function tronPost(string $endpoint, array $body): array
    {
        $headers = ['Content-Type: application/json'];
        if ($this->tronGridKey) $headers[] = 'TRON-PRO-API-KEY: ' . $this->tronGridKey;

        $ch = curl_init(self::TRON_API . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $r = json_decode(curl_exec($ch), true);
        curl_close($ch);
        return is_array($r) ? $r : [];
    }

    private function isValidTronAddress(string $address): bool
    {
        return str_starts_with($address, 'T') && strlen($address) === 34;
    }

    private function decryptKey(string $encrypted): string
    {
        $encKey = defined('ENCRYPTION_KEY') && ENCRYPTION_KEY !== '' ? ENCRYPTION_KEY : (getenv('ENCRYPTION_KEY') ?: '');
        $decoded = base64_decode($encrypted);
        if (strlen($decoded) < 28) return $encrypted;
        $iv = substr($decoded, 0, 12);
        $tag = substr($decoded, 12, 16);
        $cipher = substr($decoded, 28);
        $aesKey = hash('sha256', $encKey, true);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $aesKey, OPENSSL_RAW_DATA, $iv, $tag);
        return $plain ?: '';
    }

    private function fail(string $code, string $msg, string $ref): array
    {
        return ['success'=>false,'error'=>$code,'message'=>$msg,'reference'=>$ref,'timestamp'=>date('c')];
    }
}
