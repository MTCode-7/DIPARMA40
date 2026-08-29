<?php
/**
 * ============================================================
 * DI PARMA | Stage 11: On-Chain Monitor
 * ============================================================
 * • Poll TronScan for transaction status
 * • Detect: pending → confirmed → failed
 * • Retry/Recovery on failure
 * • Update DB with final status
 * ============================================================
 */
class OnChainMonitor
{
    const TRON_API       = 'https://api.trongrid.io';
    const TRONSCAN_API   = 'https://apilist.tronscanapi.com/api';
    const CONFIRM_BLOCKS = 19; // TRON ≈ 19 confirmations = finality
    const POLL_INTERVAL  = 5;  // seconds between polls
    const MAX_WAIT       = 120; // seconds max wait

    private string $tronGridKey;
    private ?object $db;

    public function __construct()
    {
        $this->tronGridKey = getenv('TRONGRID_API_KEY') ?: '';
        try {
            require_once __DIR__ . '/../includes/database.php';
            $this->db = db();
        } catch (Exception $e) { $this->db = null; }
    }

    // ── Check transaction status ─────────────────────────────
    public function checkStatus(string $txid): array
    {
        if (str_starts_with($txid, 'QUEUED_') || str_starts_with($txid, 'LEDGER-Q-')) {
            return ['status' => 'queued', 'confirmations' => 0, 'confirmed' => false];
        }

        try {
            // TronGrid API
            $headers = ['Content-Type: application/json'];
            if ($this->tronGridKey) $headers[] = 'TRON-PRO-API-KEY: ' . $this->tronGridKey;

            $ch = curl_init(self::TRON_API . '/wallet/gettransactioninfobyid');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode(['value' => $txid]),
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
            ]);
            $r = json_decode(curl_exec($ch), true);
            curl_close($ch);

            if (empty($r) || empty($r['id'])) {
                return ['status' => 'pending', 'confirmations' => 0, 'confirmed' => false, 'txid' => $txid];
            }

            $receipt      = $r['receipt']         ?? [];
            $result       = $receipt['result']    ?? $r['contractRet'] ?? '';
            $blockNumber  = $r['blockNumber']      ?? 0;
            $blockTimeMs  = $r['blockTimeStamp']   ?? 0;

            // Get current block for confirmation count
            $currentBlock = $this->getCurrentBlock();
            $confirmations = $blockNumber > 0 ? max(0, $currentBlock - $blockNumber) : 0;

            $status = match(true) {
                $result === 'SUCCESS' && $confirmations >= self::CONFIRM_BLOCKS => 'confirmed',
                $result === 'SUCCESS' && $confirmations > 0                    => 'confirming',
                $result === 'FAILED' || $result === 'REVERT'                   => 'failed',
                $blockNumber > 0                                                => 'confirming',
                default                                                         => 'pending',
            };

            return [
                'status'        => $status,
                'confirmed'     => $status === 'confirmed',
                'txid'          => $txid,
                'block_number'  => $blockNumber,
                'confirmations' => $confirmations,
                'block_time'    => $blockTimeMs ? date('c', intval($blockTimeMs / 1000)) : null,
                'contract_result'=> $result,
                'energy_used'   => $receipt['energy_usage_total'] ?? 0,
                'tronscan_url'  => "https://tronscan.org/#/transaction/{$txid}",
            ];

        } catch (Exception $e) {
            return ['status'=>'error','message'=>$e->getMessage(),'txid'=>$txid];
        }
    }

    // ── Monitor with retry ───────────────────────────────────
    public function monitor(string $txid, string $reference, callable $onConfirmed = null): array
    {
        $startTime = time();
        $attempts  = 0;

        while (time() - $startTime < self::MAX_WAIT) {
            $attempts++;
            $status = $this->checkStatus($txid);

            $this->updateDBStatus($reference, $txid, $status['status'], $status);

            if ($status['confirmed']) {
                if ($onConfirmed) $onConfirmed($txid, $status);
                return array_merge($status, ['attempts' => $attempts]);
            }

            if ($status['status'] === 'failed') {
                return array_merge($status, ['attempts' => $attempts,
                    'message' => 'Transaction failed on-chain']);
            }

            sleep(self::POLL_INTERVAL);
        }

        return ['status' => 'timeout', 'txid' => $txid, 'attempts' => $attempts,
                'message' => 'Monitoring timeout after ' . self::MAX_WAIT . 's'];
    }

    // ── Process pending queue ────────────────────────────────
    public function processPendingQueue(): array
    {
        if (!$this->db) return ['processed' => 0];

        $processed = 0;
        try {
            $pending = $this->db->query(
                "SELECT * FROM dp_ledger_transfer_queue WHERE status IN ('queued','processing') AND attempts < 5 ORDER BY created_at ASC LIMIT 10"
            );

            foreach ($pending as $item) {
                $this->db->execute(
                    "UPDATE dp_ledger_transfer_queue SET status='processing', attempts=attempts+1, updated_at=NOW() WHERE id=?",
                    [$item['id']]
                );

                // Re-attempt execution
                require_once __DIR__ . '/BlockchainExecutor.php';
                $executor = new BlockchainExecutor();
                $result   = $executor->execute($item['ledger_address'], floatval($item['usdt_amount']), $item['reference']);

                $newStatus = $result['success'] && !($result['queued'] ?? false) ? 'completed' : 'queued';
                $newTxid   = $result['txid'] ?? $item['txid'];

                $this->db->execute(
                    "UPDATE dp_ledger_transfer_queue SET status=?, txid=?, updated_at=NOW() WHERE id=?",
                    [$newStatus, $newTxid, $item['id']]
                );

                if ($newStatus === 'completed') $processed++;
            }
        } catch (Exception $e) {
            error_log('[OnChainMonitor] Queue: ' . $e->getMessage());
        }

        return ['processed' => $processed];
    }

    private function getCurrentBlock(): int
    {
        try {
            $headers = $this->tronGridKey ? ['TRON-PRO-API-KEY: '.$this->tronGridKey] : [];
            $ch = curl_init(self::TRON_API . '/wallet/getnowblock');
            curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>'{}',CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>3,CURLOPT_HTTPHEADER=>$headers]);
            $r = json_decode(curl_exec($ch), true);
            curl_close($ch);
            return intval($r['block_header']['raw_data']['number'] ?? 0);
        } catch (Exception $e) { return 0; }
    }

    private function updateDBStatus(string $ref, string $txid, string $status, array $data): void
    {
        if (!$this->db) return;
        try {
            $this->db->execute(
                "UPDATE dp_transactions SET ledger_txid=?, ledger_status=?, updated_at=NOW() WHERE reference=?",
                [$txid, $status, $ref]
            );
            // Update queue too
            $this->db->execute(
                "UPDATE dp_ledger_transfer_queue SET status=?, updated_at=NOW() WHERE reference=?",
                [in_array($status,['confirmed','completed'])?'completed':$status, $ref]
            );
        } catch (Exception $e) {}
    }
}
