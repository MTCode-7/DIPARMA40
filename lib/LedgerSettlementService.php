<?php
/**
 * DI PARMA | LedgerSettlementService
 * قاعدة التسوية المطلوبة:
 *   - رسوم البوابة تُضاعف (مثال: 3 → 6) وتُحسب كتكلفة العملية
 *   - الصافي يُحوَّل فوراً كـ USDT (TRC20) إلى LEDGER
 *   - الوجهة: Ledger فقط — ليست البوابة وليست حساباً بنكياً
 * التنفيذ الفني: بعد نجاح الدفع، Hot Wallet يرسل الصافي USDT إلى Ledger.
 */
class LedgerSettlementService
{
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * نقطة موحّدة لكل البوابات المتصلة بعد نجاح الدفع:
     * رسوم × GATEWAY_FEE_MULTIPLIER ثم الصافي USDT → Ledger.
     */
    public static function settleSuccessfulPayment(array $params): array
    {
        return self::getInstance()->settleToLedger($params);
    }

    /** مضاعف رسوم البوابة (افتراضي 2 = دبل) */
    public function feeMultiplier(): float
    {
        $m = (float) (getenv('GATEWAY_FEE_MULTIPLIER') ?: ($_ENV['GATEWAY_FEE_MULTIPLIER'] ?? 2));
        if ($m < 1) {
            $m = 1;
        }
        return $m;
    }

    /** أصل التسوية للصافي: USDT (TRC20) */
    public function settlementAsset(): string
    {
        $asset = strtoupper(trim((string) (getenv('SETTLEMENT_ASSET') ?: ($_ENV['SETTLEMENT_ASSET'] ?? 'USDT'))));
        return in_array($asset, ['USDT', 'TRX'], true) ? $asset : 'USDT';
    }

    /** تطبيع كود البوابة لجلب الرسوم (nuvei_pos → nuvei، …) */
    public function normalizeGatewayCode(string $gatewayCode): string
    {
        $code = strtolower(trim($gatewayCode));
        if ($code === '' || $code === 'unknown') {
            return 'nuvei';
        }
        if (str_starts_with($code, 'bank:')) {
            $code = substr($code, 5);
        }
        $aliases = [
            'nuvei_pos' => 'nuvei', 'nuvei_api' => 'nuvei', 'mashreq' => 'nuvei',
            'stripe_pos' => 'stripe', 'paypal_api' => 'paypal',
            'diparma' => 'diparma', 'diparma_gateway' => 'diparma', 'moto' => 'nuvei',
        ];
        if (isset($aliases[$code])) {
            return $aliases[$code];
        }
        foreach (['nuvei', 'stripe', 'paypal', 'myfatoorah', 'wise', 'binance', 'gate_io', 'payram', 'redotpay', 'whop'] as $base) {
            if ($code === $base || str_starts_with($code, $base . '_')) {
                return $base;
            }
        }
        return $code;
    }

    /**
     * رسوم البوابة ثم مضاعفتها (دبل افتراضياً)، والصافي = المبلغ − الرسوم المضاعفة.
     * مثال: 100 − (3×2) = 94 → يُرسل 94 USDT إلى Ledger.
     */
    public function calculateGatewayFee(string $gatewayCode, float $amount): array
    {
        $pct = 2.5;
        $fixed = 0.0;
        $gatewayCode = $this->normalizeGatewayCode($gatewayCode);
        try {
            if (!function_exists('gateway_service')) {
                require_once __DIR__ . '/../includes/gateways.php';
            }
            $fees = gateway_service()->getGatewayFees($gatewayCode);
            $pct = (float) ($fees['percentage'] ?? 2.5);
            $fixed = (float) ($fees['fixed'] ?? 0);
        } catch (Throwable $e) {
            // defaults
        }

        $baseFee = round(($amount * $pct / 100) + $fixed, 4);
        $multiplier = $this->feeMultiplier();
        $feeAmount = round($baseFee * $multiplier, 4);
        if ($feeAmount < 0) {
            $feeAmount = 0;
        }
        if ($feeAmount > $amount) {
            $feeAmount = $amount;
        }
        $net = round(max(0, $amount - $feeAmount), 4);

        return [
            'gateway'            => $gatewayCode,
            'amount'             => $amount,
            'fee_percentage'     => $pct,
            'fee_fixed'          => $fixed,
            'fee_base'           => $baseFee,
            'fee_multiplier'     => $multiplier,
            'fee_amount'         => $feeAmount,
            'net_amount'         => $net,
            'settlement_asset'   => $this->settlementAsset(),
            'settlement_target'  => 'ledger',
        ];
    }

    /**
     * تحويل مبلغ فيات إلى USDT تقريبي.
     */
    public function toUsdt(float $amount, string $currency): float
    {
        $currency = strtoupper(trim($currency));
        if ($currency === 'USD' || $currency === 'USDT') {
            return round($amount, 6);
        }

        try {
            if (!class_exists('ExchangeRateService')) {
                require_once __DIR__ . '/ExchangeRateService.php';
            }
            $calc = ExchangeRateService::getInstance()->calculate($amount, $currency, 'USDT');
            if (!empty($calc['crypto_amount'])) {
                return round((float) $calc['crypto_amount'], 6);
            }
        } catch (Throwable $e) {
            // fallback map
        }

        $rates = [
            'AED' => 0.2723, 'SAR' => 0.2667, 'EUR' => 1.08,
            'GBP' => 1.27, 'KWD' => 3.25, 'QAR' => 0.2747,
            'BHD' => 2.65, 'OMR' => 2.60, 'EGP' => 0.0204,
        ];
        return round($amount * ($rates[$currency] ?? 1.0), 6);
    }

    /**
     * تسوية فورية بعد نجاح الدفع.
     *
     * @param array{
     *   reference:string,
     *   amount:float,
     *   currency:string,
     *   gateway:string,
     *   ledger_address?:string,
     *   user_id?:int,
     *   transaction_id?:int|null
     * } $params
     */
    public function settleToLedger(array $params): array
    {
        $reference = trim((string) ($params['reference'] ?? ''));
        $amount    = (float) ($params['amount'] ?? 0);
        $currency  = strtoupper(trim((string) ($params['currency'] ?? 'USD')));
        $gateway   = strtolower(trim((string) ($params['gateway'] ?? 'unknown')));
        $userId    = (int) ($params['user_id'] ?? ($_SESSION['user_id'] ?? 0));
        $txnId     = isset($params['transaction_id']) ? (int) $params['transaction_id'] : null;

        $target = strtolower(trim((string) ($params['destination'] ?? $params['settlement_target'] ?? 'ledger')));
        $ledgerAliases = ['', 'ledger', 'ledger_trx', 'crypto', 'wallet'];
        $blocked = ['bank', 'mashreq', 'iban', 'gateway'];
        if (in_array($target, $blocked, true) || ($target !== '' && !in_array($target, $ledgerAliases, true))) {
            return ['success' => false, 'message' => 'Settlement destination is Ledger only. Bank/gateway payout is disabled.', 'queued' => false];
        }
        $configured = defined('LEDGER_TRC20_ADDRESS') ? trim((string) LEDGER_TRC20_ADDRESS) : '';
        $ledgerAddr = $configured !== '' ? $configured : trim((string) ($params['ledger_address'] ?? ''));

        if ($reference === '' || $amount <= 0) {
            return ['success' => false, 'message' => 'Invalid settlement params', 'queued' => false];
        }
        if (!preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $ledgerAddr)) {
            return ['success' => false, 'message' => 'LEDGER_TRC20_ADDRESS not configured', 'queued' => false];
        }

        // لا تُحوّل عمليات AUTH بدون Capture / Refund / Avoid
        $txnType = strtolower(trim((string) ($params['txn_type'] ?? '')));
        $skipTypes = ['auth', 'auth_hold', 'auth_moto', 'hold', 'refund', 'avoid', 'void', 'reversal'];
        if ($txnType !== '' && in_array($txnType, $skipTypes, true)) {
            return [
                'success' => true,
                'skipped' => true,
                'message' => 'Settlement skipped for txn type ' . $txnType,
                'fee'     => $this->calculateGatewayFee($gateway, $amount),
            ];
        }

        $fee = $this->calculateGatewayFee($gateway, $amount);
        $netFiat = $fee['net_amount'];
        $asset = $this->settlementAsset();
        // حالياً التنفيذ الفعلي: USDT TRC20 إلى Ledger (TRX لاحقاً إن لزم)
        $cryptoAmount = $this->toUsdt($netFiat, $currency);

        $result = [
            'success'        => false,
            'reference'      => $reference,
            'gateway'        => $gateway,
            'fee'            => $fee,
            'net_fiat'       => $netFiat,
            'usdt_amount'    => $cryptoAmount,
            'settlement_asset' => $asset,
            'settlement_target' => 'ledger',
            'ledger_address' => $ledgerAddr,
            'txid'           => null,
            'queued'         => false,
            'message'        => '',
        ];

        // تحديث الرسوم على المعاملة أولاً
        $this->persistFeeFields($reference, $txnId, $fee, $cryptoAmount, $ledgerAddr, 'pending');

        if ($cryptoAmount < 0.000001) {
            $result['success'] = true;
            $result['message'] = 'Net amount is zero after doubled gateway fee';
            $this->persistFeeFields($reference, $txnId, $fee, 0, $ledgerAddr, 'completed');
            return $result;
        }

        // الصافي → Ledger كـ USDT (ليس رصيد بوابة)
        try {
            if (!class_exists('HotWalletService')) {
                require_once __DIR__ . '/HotWalletService.php';
            }
            if (!class_exists('WalletService')) {
                require_once __DIR__ . '/WalletService.php';
            }

            $send = HotWalletService::getInstance()->sendUSDT($reference, $ledgerAddr, $cryptoAmount, $userId);
            if (!empty($send['success'])) {
                $result['success'] = true;
                $result['txid'] = $send['tx_hash'] ?? null;
                $result['message'] = $send['message'] ?? ("{$cryptoAmount} {$asset} sent to Ledger (net after x{$fee['fee_multiplier']} gateway fee)");
                $result['duplicate'] = !empty($send['duplicate']);
                $this->persistFeeFields($reference, $txnId, $fee, $cryptoAmount, $ledgerAddr, 'completed', $result['txid']);
                return $result;
            }

            $this->queueTransfer($reference, $ledgerAddr, $cryptoAmount, $currency, $send['message'] ?? 'send failed');
            $result['queued'] = true;
            $result['message'] = $send['message'] ?? 'Queued for Ledger USDT transfer';
            $this->persistFeeFields($reference, $txnId, $fee, $cryptoAmount, $ledgerAddr, 'queued');
            return $result;
        } catch (Throwable $e) {
            $this->queueTransfer($reference, $ledgerAddr, $cryptoAmount, $currency, $e->getMessage());
            $result['queued'] = true;
            $result['message'] = $e->getMessage();
            $this->persistFeeFields($reference, $txnId, $fee, $cryptoAmount, $ledgerAddr, 'queued');
            return $result;
        }
    }

    private function persistFeeFields(
        string $reference,
        ?int $txnId,
        array $fee,
        float $usdt,
        string $ledgerAddr,
        string $ledgerStatus,
        ?string $txid = null
    ): void {
        try {
            $db = db();
            $core = [
                'fees' => $fee['fee_amount'],
                'net_amount' => $fee['net_amount'],
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            $extra = [
                'ledger_address' => $ledgerAddr,
                'ledger_amount' => $usdt,
                'ledger_status' => $ledgerStatus,
            ];
            if ($txid) {
                $extra['ledger_txid'] = $txid;
                $extra['ledger_transferred'] = 1;
            }

            $tryUpdate = static function (array $data) use ($db, $txnId, $reference): bool {
                try {
                    if ($txnId) {
                        $db->update('transactions', $data, ['id' => $txnId]);
                        return true;
                    }
                    $db->update('transactions', $data, ['reference' => $reference]);
                    return true;
                } catch (Throwable $e) {
                    return false;
                }
            };

            if (!$tryUpdate(array_merge($core, $extra))) {
                $tryUpdate($core);
            }
        } catch (Throwable $e) {
            error_log('[LedgerSettlement] persist: ' . $e->getMessage());
        }
    }

    private function queueTransfer(string $reference, string $addr, float $usdt, string $currency, string $message): void
    {
        try {
            $db = db();
            $db->execute(
                "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "ledger_transfer_queue` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `reference` VARCHAR(100) NOT NULL,
                    `ledger_address` VARCHAR(128) NOT NULL,
                    `usdt_amount` DECIMAL(18,6) NOT NULL DEFAULT 0,
                    `currency_orig` VARCHAR(16) DEFAULT NULL,
                    `status` VARCHAR(32) NOT NULL DEFAULT 'queued',
                    `message` TEXT NULL,
                    `txid` VARCHAR(128) NULL,
                    `attempts` INT NOT NULL DEFAULT 0,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NULL,
                    KEY `idx_ref` (`reference`),
                    KEY `idx_status` (`status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            $db->insert('ledger_transfer_queue', [
                'reference' => $reference,
                'ledger_address' => $addr,
                'usdt_amount' => round($usdt, 6),
                'currency_orig' => $currency,
                'status' => 'queued',
                'message' => substr($message, 0, 500),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            error_log('[LedgerSettlement] queue: ' . $e->getMessage());
        }
    }
}
