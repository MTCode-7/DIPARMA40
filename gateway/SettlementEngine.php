<?php
/**
 * ============================================================
 * DI PARMA | Stage 12-13: Settlement + Reconciliation
 * ============================================================
 * • Match authorization to crypto payout
 * • Record fees & net amounts
 * • Write audit trail
 * • Generate settlement report
 * • Send notifications (POS receipt, merchant, webhook, report)
 * ============================================================
 */
class SettlementEngine
{
    private ?object $db;

    public function __construct()
    {
        try {
            require_once __DIR__ . '/../includes/database.php';
            $this->db = db();
        } catch (Exception $e) { $this->db = null; }
    }

    // ── MAIN: Settle a completed transaction ─────────────────
    public function settle(array $txnData): array
    {
        $reference   = $txnData['reference']       ?? '';
        $fiatAmount  = floatval($txnData['amount'] ?? 0);
        $currency    = $txnData['currency']         ?? 'USD';
        $cryptoAmount= floatval($txnData['crypto_amount'] ?? 0);
        $txid        = $txnData['txid']             ?? '';
        $approvalCode= $txnData['approval_code']    ?? '';
        $rrn         = $txnData['rrn']              ?? '';
        $ledgerAddr  = $txnData['ledger_address']   ?? '';

        // 1. Reconciliation
        $recon = $this->reconcile($txnData);

        // 2. Update transaction with settlement data
        $this->updateSettlement($reference, $txid, $cryptoAmount, $recon);

        // 3. Generate audit trail
        $auditId = $this->writeAuditTrail($txnData, $recon);

        // 4. Generate receipt
        $receipt = $this->generateReceipt($txnData, $recon, $auditId);

        // 5. Send notifications
        $this->sendNotifications($txnData, $receipt);

        return [
            'success'       => true,
            'reference'     => $reference,
            'reconciliation'=> $recon,
            'receipt'       => $receipt,
            'audit_id'      => $auditId,
            'settled_at'    => date('c'),
        ];
    }

    // ── Stage 13: Reconciliation ─────────────────────────────
    public function reconcile(array $txnData): array
    {
        $fiatAmount   = floatval($txnData['amount']        ?? 0);
        $cryptoAmount = floatval($txnData['crypto_amount'] ?? 0);
        $currency     = $txnData['currency']               ?? 'USD';
        $txid         = $txnData['txid']                   ?? '';

        // Verify on-chain if we have txid
        $onChainVerified = false;
        $onChainAmount   = 0;

        if (!empty($txid) && !str_starts_with($txid, 'QUEUED_')) {
            require_once __DIR__ . '/OnChainMonitor.php';
            $monitor = new OnChainMonitor();
            $status  = $monitor->checkStatus($txid);
            $onChainVerified = $status['confirmed'] ?? false;
            // Cross-reference amount from TronScan
            $onChainAmount = $this->getOnChainAmount($txid);
        }

        // Fee breakdown
        $platformFee = round($fiatAmount * 0.0025, 4);  // 0.25%
        $spreadFee   = round($fiatAmount * 0.005, 4);   // 0.5%
        $networkFee  = 2.0;                              // TRC20 flat
        $totalFees   = $platformFee + $spreadFee + $networkFee;
        $netFiat     = $fiatAmount - $totalFees;

        $discrepancy = abs($cryptoAmount - $onChainAmount);
        $matched     = $onChainAmount > 0 ? $discrepancy < 0.001 : true; // allow <0.001 USDT

        return [
            'matched'          => $matched,
            'fiat_amount'      => $fiatAmount,
            'fiat_currency'    => $currency,
            'crypto_amount'    => $cryptoAmount,
            'crypto_currency'  => 'USDT',
            'on_chain_amount'  => $onChainAmount,
            'on_chain_verified'=> $onChainVerified,
            'discrepancy'      => $discrepancy,
            'fees'             => [
                'platform'  => $platformFee,
                'spread'    => $spreadFee,
                'network'   => $networkFee,
                'total'     => $totalFees,
            ],
            'net_fiat'         => $netFiat,
            'reconciled_at'    => date('c'),
        ];
    }

    // ── Write Audit Trail ────────────────────────────────────
    private function writeAuditTrail(array $txnData, array $recon): string
    {
        $auditId = 'AUD-' . strtoupper(substr(md5($txnData['reference'].time()), 0, 12));

        if (!$this->db) return $auditId;

        try {
            $this->db->execute(
                "CREATE TABLE IF NOT EXISTS dp_audit_trail (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    audit_id VARCHAR(32) UNIQUE,
                    reference VARCHAR(100),
                    event_type VARCHAR(50),
                    fiat_amount DECIMAL(12,2),
                    fiat_currency VARCHAR(10),
                    crypto_amount DECIMAL(18,8),
                    txid VARCHAR(100),
                    approval_code VARCHAR(20),
                    rrn VARCHAR(20),
                    ledger_address VARCHAR(100),
                    reconciled TINYINT(1) DEFAULT 0,
                    discrepancy DECIMAL(18,8) DEFAULT 0,
                    fees_json TEXT,
                    metadata JSON,
                    created_at DATETIME
                ) ENGINE=InnoDB"
            );

            $this->db->insert('audit_trail', [
                'audit_id'       => $auditId,
                'reference'      => $txnData['reference'] ?? '',
                'event_type'     => 'settlement',
                'fiat_amount'    => $txnData['amount'] ?? 0,
                'fiat_currency'  => $txnData['currency'] ?? 'USD',
                'crypto_amount'  => $txnData['crypto_amount'] ?? 0,
                'txid'           => $txnData['txid'] ?? '',
                'approval_code'  => $txnData['approval_code'] ?? '',
                'rrn'            => $txnData['rrn'] ?? '',
                'ledger_address' => $txnData['ledger_address'] ?? '',
                'reconciled'     => $recon['matched'] ? 1 : 0,
                'discrepancy'    => $recon['discrepancy'] ?? 0,
                'fees_json'      => json_encode($recon['fees'] ?? []),
                'metadata'       => json_encode($recon),
                'created_at'     => date('Y-m-d H:i:s'),
            ]);
        } catch (Exception $e) {
            error_log('[Settlement] Audit: ' . $e->getMessage());
        }

        return $auditId;
    }

    // ── Generate Receipt ─────────────────────────────────────
    private function generateReceipt(array $txnData, array $recon, string $auditId): array
    {
        return [
            'receipt_id'    => 'RCP-' . strtoupper(substr($auditId, 4)),
            'merchant'      => 'TRANSCENDIO FZ-LLC',
            'merchant_id'   => $txnData['merchant_id'] ?? 'DIPARMA',
            'terminal_id'   => $txnData['terminal_id'] ?? 'POS',
            'reference'     => $txnData['reference']   ?? '',
            'date'          => date('d/m/Y H:i:s'),
            'card_last4'    => substr(preg_replace('/\D/','',($txnData['card_number']??'')), -4) ?: '****',
            'txn_type'      => strtoupper($txnData['txn_type'] ?? 'PURCHASE'),
            'fiat_amount'   => number_format(floatval($txnData['amount']??0), 2),
            'fiat_currency' => $txnData['currency'] ?? 'USD',
            'crypto_amount' => number_format(floatval($txnData['crypto_amount']??0), 6),
            'approval_code' => $txnData['approval_code'] ?? '—',
            'rrn'           => $txnData['rrn']           ?? '—',
            'txid'          => $txnData['txid']          ?? '—',
            'ledger_address'=> $txnData['ledger_address']?? '—',
            'fees_total'    => number_format($recon['fees']['total'] ?? 0, 4),
            'net_fiat'      => number_format($recon['net_fiat'] ?? 0, 2),
            'status'        => 'APPROVED',
            'gateway'       => 'DI PARMA / Nuvei / Mashreq',
            'tronscan'      => !empty($txnData['txid']) ? "https://tronscan.org/#/transaction/{$txnData['txid']}" : null,
        ];
    }

    // ── Send Notifications ───────────────────────────────────
    private function sendNotifications(array $txnData, array $receipt): void
    {
        // 1. POS Receipt (log)
        error_log('[RECEIPT] ' . json_encode($receipt));

        // 2. Webhook callback to API client
        if (!empty($txnData['webhook_url'])) {
            $payload = json_encode([
                'event'     => 'settlement.completed',
                'reference' => $txnData['reference'] ?? '',
                'receipt'   => $receipt,
                'timestamp' => time(),
            ]);
            $sig = hash_hmac('sha256', $payload, $txnData['webhook_secret'] ?? '');
            @(function() use ($txnData, $payload, $sig) {
                $ch = curl_init($txnData['webhook_url']);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $payload,
                    CURLOPT_HTTPHEADER     => ['Content-Type: application/json','X-DiParma-Signature: '.$sig,'X-DiParma-Event: settlement.completed'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 5,
                ]);
                curl_exec($ch);
                curl_close($ch);
            })();
        }
    }

    private function updateSettlement(string $ref, string $txid, float $crypto, array $recon): void
    {
        if (!$this->db) return;
        try {
            // Add columns if missing
            @$this->db->execute("ALTER TABLE dp_transactions ADD COLUMN IF NOT EXISTS ledger_txid VARCHAR(100) DEFAULT NULL");
            @$this->db->execute("ALTER TABLE dp_transactions ADD COLUMN IF NOT EXISTS ledger_status VARCHAR(30) DEFAULT NULL");
            @$this->db->execute("ALTER TABLE dp_transactions ADD COLUMN IF NOT EXISTS crypto_amount DECIMAL(18,8) DEFAULT NULL");
            @$this->db->execute("ALTER TABLE dp_transactions ADD COLUMN IF NOT EXISTS reconciled TINYINT(1) DEFAULT 0");
            @$this->db->execute("ALTER TABLE dp_transactions ADD COLUMN IF NOT EXISTS settled_at DATETIME DEFAULT NULL");

            $this->db->execute(
                "UPDATE dp_transactions SET ledger_txid=?, ledger_status='settled', crypto_amount=?, reconciled=?, settled_at=NOW() WHERE reference=?",
                [$txid, $crypto, $recon['matched'] ? 1 : 0, $ref]
            );
        } catch (Exception $e) {
            error_log('[Settlement] Update: ' . $e->getMessage());
        }
    }

    private function getOnChainAmount(string $txid): float
    {
        try {
            $ch = curl_init("https://apilist.tronscanapi.com/api/transaction-info?hash={$txid}");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5]);
            $r = json_decode(curl_exec($ch), true);
            curl_close($ch);
            $val = $r['trc20TransferInfo'][0]['amount_str'] ?? $r['contractData']['amount'] ?? 0;
            return floatval($val) / 1e6;
        } catch (Exception $e) { return 0.0; }
    }

    // ── Daily Settlement Report ──────────────────────────────
    public function generateDailyReport(string $date = null): array
    {
        $date = $date ?? date('Y-m-d');
        if (!$this->db) return ['date'=>$date,'error'=>'No DB'];

        try {
            $txns = $this->db->query(
                "SELECT COUNT(*) cnt, COALESCE(SUM(amount),0) vol, COALESCE(SUM(crypto_amount),0) crypto_vol,
                 currency, gateway, status
                 FROM dp_transactions WHERE DATE(created_at)=?
                 GROUP BY currency, gateway, status",
                [$date]
            );

            $pending = $this->db->query(
                "SELECT COUNT(*) cnt, COALESCE(SUM(usdt_amount),0) vol FROM dp_ledger_transfer_queue WHERE DATE(created_at)=? AND status='queued'",
                [$date]
            );

            return [
                'date'              => $date,
                'transactions'      => $txns,
                'pending_transfers' => $pending[0] ?? ['cnt'=>0,'vol'=>0],
                'generated_at'      => date('c'),
            ];
        } catch (Exception $e) {
            return ['date'=>$date,'error'=>$e->getMessage()];
        }
    }
}
