<?php
/**
 * ============================================================
 * DI PARMA | Gateway Orchestrator
 * Card-to-Crypto Complete Transaction Pipeline
 * ============================================================
 * Stages 1-13 — كل المراحل في pipeline واحد
 * ============================================================
 */
require_once __DIR__ . '/PreChecksEngine.php';
require_once __DIR__ . '/FXConversionEngine.php';
require_once __DIR__ . '/BlockchainExecutor.php';
require_once __DIR__ . '/OnChainMonitor.php';
require_once __DIR__ . '/SettlementEngine.php';
require_once __DIR__ . '/../lib/NuveiAdapter.php';

class GatewayOrchestrator
{
    private array $pipeline = [];
    private ?object $db;

    public function __construct()
    {
        try {
            require_once __DIR__ . '/../includes/database.php';
            $this->db = db();
        } catch (Exception $e) { $this->db = null; }
    }

    /**
     * FULL PIPELINE: Card → Auth → FX → Blockchain → Settlement
     */
    public function process(array $input): array
    {
        $start     = microtime(true);
        $reference = $input['reference'] ?? ('DPM-' . strtoupper(substr(uniqid(), 0, 8)));
        $input['reference'] = $reference;
        $stages    = [];

        $this->logPipeline($reference, 'started', $input);

        // ════════════════════════════════════════════════════
        // STAGE 4: Pre-checks
        // ════════════════════════════════════════════════════
        $preCheck = (new PreChecksEngine())->run($input);
        $stages['pre_checks'] = $preCheck;

        if (!$preCheck['passed']) {
            return $this->abort($reference, 'PRE_CHECK_FAILED', $preCheck, $stages, $start);
        }

        $routing = $preCheck['routing'] ?? 'nuvei';

        // ════════════════════════════════════════════════════
        // STAGE 5-6: Card Authorization (Nuvei → Mashreq)
        // ════════════════════════════════════════════════════
        $authResult = $this->authorize($input, $routing);
        $stages['card_authorization'] = $authResult;

        if (!$authResult['success']) {
            $this->logPipeline($reference, 'authorization_declined', $authResult);
            return $this->abort($reference, 'AUTHORIZATION_DECLINED', $authResult, $stages, $start);
        }

        // ════════════════════════════════════════════════════
        // STAGE 7: Funds Capture
        // ════════════════════════════════════════════════════
        $stages['funds_capture'] = [
            'success'       => true,
            'approval_code' => $authResult['approval_code'] ?? '',
            'rrn'           => $authResult['rrn']           ?? '',
            'amount'        => $input['amount'],
            'currency'      => $input['currency'],
        ];

        // ════════════════════════════════════════════════════
        // STAGE 8: FX Conversion
        // ════════════════════════════════════════════════════
        $fx = new FXConversionEngine();
        $fxResult = $fx->convert(
            floatval($input['amount']),
            $input['currency'] ?? 'USD'
        );
        $stages['fx_conversion'] = $fxResult;

        if (!$fxResult['success']) {
            return $this->abort($reference, 'FX_CONVERSION_FAILED', $fxResult, $stages, $start);
        }

        $usdtAmount = $fxResult['to_amount'];

        // ════════════════════════════════════════════════════
        // STAGE 9: Liquidity Checks
        // ════════════════════════════════════════════════════
        $liquidityResult = $fx->checkLiquidity($usdtAmount);
        $stages['liquidity_checks'] = $liquidityResult;

        if (!$liquidityResult['success']) {
            // Don't abort — queue for processing when liquidity available
            $stages['liquidity_warning'] = $liquidityResult['message'];
        }

        // ════════════════════════════════════════════════════
        // STAGE 10: Blockchain Execution → Ledger TRX
        // ════════════════════════════════════════════════════
        $ledgerAddr = $input['ledger_address'] ?? 'TEwLFWlwK55b7PuFfzgH1H2f3xs3pLgLn2';
        $executor   = new BlockchainExecutor();
        $execResult = $executor->execute($ledgerAddr, $usdtAmount, $reference);
        $stages['blockchain_execution'] = $execResult;

        $txid = $execResult['txid'] ?? '';

        // ════════════════════════════════════════════════════
        // STAGE 11: On-Chain Monitoring (async/queued)
        // ════════════════════════════════════════════════════
        $monitorResult = ['status' => $execResult['queued'] ?? false ? 'queued' : 'monitoring',
                          'txid'   => $txid];
        if (!($execResult['queued'] ?? false) && !empty($txid)) {
            $monitor = new OnChainMonitor();
            $monitorResult = $monitor->checkStatus($txid);
        }
        $stages['on_chain_monitoring'] = $monitorResult;

        // ════════════════════════════════════════════════════
        // STAGE 12-13: Settlement + Reconciliation
        // ════════════════════════════════════════════════════
        $settlement = new SettlementEngine();
        $settlementData = array_merge($input, [
            'txid'          => $txid,
            'crypto_amount' => $usdtAmount,
            'approval_code' => $authResult['approval_code'] ?? '',
            'rrn'           => $authResult['rrn']           ?? '',
            'ledger_address'=> $ledgerAddr,
            'nuvei_txn_id'  => $authResult['nuvei_txn_id']  ?? '',
        ]);
        $settlementResult = $settlement->settle($settlementData);
        $stages['settlement'] = $settlementResult;

        // ════════════════════════════════════════════════════
        // SAVE TO DB
        // ════════════════════════════════════════════════════
        $this->saveTransaction($reference, $input, $stages, $usdtAmount, $txid);

        $duration = round((microtime(true) - $start) * 1000);
        $this->logPipeline($reference, 'completed', ['duration_ms'=>$duration]);

        return [
            'success'         => true,
            'reference'       => $reference,
            'status'          => 'completed',
            'approval_code'   => $authResult['approval_code'] ?? null,
            'rrn'             => $authResult['rrn']           ?? null,
            'nuvei_txn_id'    => $authResult['nuvei_txn_id']  ?? null,
            'fiat_amount'     => $input['amount'],
            'fiat_currency'   => $input['currency'] ?? 'USD',
            'usdt_amount'     => $usdtAmount,
            'fx_rate'         => $fxResult['rate'],
            'txid'            => $txid,
            'ledger_address'  => $ledgerAddr,
            'ledger_status'   => $execResult['queued'] ?? false ? 'queued' : 'broadcasting',
            'tronscan_url'    => !empty($txid) && !str_starts_with($txid,'QUEUED_')
                                 ? "https://tronscan.org/#/transaction/{$txid}" : null,
            'receipt'         => $settlementResult['receipt']  ?? null,
            'audit_id'        => $settlementResult['audit_id'] ?? null,
            'routing'         => $routing,
            'duration_ms'     => $duration,
            'stages'          => $stages,
            'timestamp'       => date('c'),
        ];
    }

    // ── Card Authorization ───────────────────────────────────
    private function authorize(array $input, string $processor = 'nuvei'): array
    {
        $nuvei = new NuveiAdapter();
        $params = [
            'amount'       => $input['amount'],
            'currency'     => $input['currency'] ?? 'USD',
            'card_number'  => $input['card_number']  ?? '',
            'card_name'    => $input['card_name']    ?? 'Customer',
            'card_expiry'  => $input['card_expiry']  ?? '',
            'card_cvv'     => $input['card_cvv']     ?? '',
            'email'        => $input['email']        ?? 'client@diparmas.com',
            'reference'    => $input['reference'],
            'processing_mode' => $input['sec_mode']  ?? '3D',
            'pos_device'   => $input['pos_device']   ?? 'BITEL_IC3600',
        ];

        $txnType = strtolower($input['txn_type'] ?? 'purchase');
        return match($txnType) {
            'auth'    => $nuvei->authorize($params),
            'refund'  => $nuvei->refund($params),
            'void'    => $nuvei->void($params),
            'capture' => $nuvei->capture($params),
            default   => $nuvei->purchase($params),
        };
    }

    private function abort(string $ref, string $reason, array $data, array $stages, float $start): array
    {
        $duration = round((microtime(true) - $start) * 1000);
        $this->logPipeline($ref, 'aborted', ['reason'=>$reason]);
        return [
            'success'     => false,
            'reference'   => $ref,
            'error'       => $reason,
            'message'     => $data['message'] ?? $reason,
            'stages'      => $stages,
            'duration_ms' => $duration,
            'timestamp'   => date('c'),
        ];
    }

    private function saveTransaction(string $ref, array $input, array $stages, float $crypto, string $txid): void
    {
        if (!$this->db) return;
        try {
            $this->db->insert('transactions', [
                'reference'       => $ref,
                'gateway'         => 'diparma_gateway',
                'amount'          => $input['amount'],
                'currency'        => $input['currency'] ?? 'USD',
                'status'          => 'completed',
                'protocol'        => $input['txn_type'] ?? 'purchase',
                'customer_name'   => $input['card_name'] ?? 'Customer',
                'gateway_response'=> json_encode([
                    'approval_code' => $stages['card_authorization']['approval_code'] ?? null,
                    'rrn'           => $stages['card_authorization']['rrn'] ?? null,
                    'nuvei_txn_id'  => $stages['card_authorization']['nuvei_txn_id'] ?? null,
                    'ledger_txid'   => $txid,
                    'usdt_amount'   => $crypto,
                    'pipeline'      => array_keys($stages),
                ]),
                'created_at'      => date('Y-m-d H:i:s'),
            ]);
        } catch (Exception $e) {
            error_log('[Orchestrator] Save: ' . $e->getMessage());
        }
    }

    private function logPipeline(string $ref, string $event, array $data): void
    {
        error_log("[GATEWAY][{$ref}] {$event}: " . json_encode($data));
    }
}
