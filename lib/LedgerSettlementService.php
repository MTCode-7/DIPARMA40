<?php
/**
 * DI PARMA | LedgerSettlementService
 * قاعدة التسوية المطلوبة:
 *   - كل بوابة تحتفظ بالمبلغ عليها (PayPal→PayPal، Stripe→Stripe، …)
 *   - لا تحويل USDT تلقائي إلى Ledger بعد الخصم
 *   - رسوم البوابة تُحسب وتُسجَّل فقط
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
     * المبلغ يبقى على نفس البوابة.
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
            return '';
        }
        if (str_starts_with($code, 'bank:')) {
            $code = substr($code, 5);
        }
        $aliases = [
            'nuvei_pos' => 'nuvei', 'nuvei_api' => 'nuvei', 'mashreq' => 'nuvei',
            'stripe_pos' => 'stripe', 'paypal_api' => 'paypal',
            'diparma' => 'diparma', 'diparma_gateway' => 'diparma',
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

    /** Default: keep capture on the charging gateway. Ledger move only from Ledger CHECKOUT. */
    public function keepsFundsOnGateway(string $gatewayCode, string $destination = '', bool $ledgerCheckout = false): bool
    {
        unset($gatewayCode);
        $destination = strtolower(trim($destination));

        // المسار 2: Ledger — من checkout_ledger أو destination صريح
        if (in_array($destination, ['ledger', 'ledger_trx'], true)) {
            return false; // يذهب للـ Ledger
        }
        if ($ledgerCheckout && in_array($destination, ['ledger', 'ledger_trx', 'wallet'], true)) {
            return false; // يذهب للـ Ledger
        }

        // المسار 3: Forward — بنك أو بوابة أخرى
        if (str_starts_with($destination, 'bank:') || str_starts_with($destination, 'gateway:')) {
            return false; // يذهب للوجهة المحددة
        }

        // المسار 1: Gateway — يبقى على نفس البوابة (الافتراضي)
        return true;
    }

    /**
     * Mark a successful capture as settled on the charging gateway — no USDT send.
     */
    public function retainOnGateway(array $params): array
    {
        $reference = trim((string) ($params['reference'] ?? ''));
        $amount = (float) ($params['amount'] ?? 0);
        $currency = strtoupper(trim((string) ($params['currency'] ?? 'USD'))) ?: 'USD';
        $gateway = $this->normalizeGatewayCode((string) ($params['gateway'] ?? 'paypal'));
        $txnId = isset($params['transaction_id']) ? (int) $params['transaction_id'] : null;
        $txnType = strtolower(trim((string) ($params['txn_type'] ?? '')));
        $skipTypes = ['auth', 'auth_hold', 'auth_moto', 'hold', 'refund', 'avoid', 'void', 'reversal'];
        if ($txnType !== '' && in_array($txnType, $skipTypes, true)) {
            return [
                'success' => false,
                'skipped' => true,
                'retained' => false,
                'message' => 'Settlement skipped for txn type ' . $txnType,
            ];
        }
        if ($reference === '' || $amount <= 0) {
            return ['success' => false, 'message' => 'Invalid settlement params', 'queued' => false];
        }

        $fee = $this->calculateGatewayFee($gateway, $amount);
        $result = [
            'success' => true,
            'retained' => true,
            'skipped' => false,
            'queued' => false,
            'reference' => $reference,
            'gateway' => $gateway,
            'fee' => $fee,
            'net_fiat' => $fee['net_amount'],
            'usdt_amount' => 0,
            'settlement_asset' => strtoupper($currency),
            'settlement_target' => 'gateway',
            'ledger_address' => '',
            'txid' => null,
            'message' => 'The money stopped at ' . $gateway . ' — no Ledger transfer',
        ];
        $this->persistGatewayRetention($reference, $txnId, $result);
        return $result;
    }

    private function persistGatewayRetention(string $reference, ?int $txnId, array $settle): void
    {
        try {
            $db = db();
            $txn = $txnId ? $db->find('transactions', ['id' => $txnId]) : $db->find('transactions', ['reference' => $reference]);
            $blob = [];
            if (is_array($txn)) {
                $blob = json_decode((string) ($txn['gateway_response'] ?? ''), true) ?: [];
            }
            $blob['settlement_target'] = 'gateway';
            $blob['settlement_path'] = ($settle['gateway'] ?? 'paypal') . '_to_gateway';
            $blob['ledger_settlement'] = $settle;
            $blob['ledger_status'] = 'retained';
            $data = [
                'fees' => $settle['fee']['fee_amount'] ?? 0,
                'net_amount' => $settle['net_fiat'] ?? 0,
                'gateway_response' => json_encode($blob, JSON_UNESCAPED_UNICODE),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($txnId) {
                $db->update('transactions', $data, ['id' => $txnId]);
            } else {
                $db->update('transactions', $data, ['reference' => $reference]);
            }
        } catch (Throwable $e) {
            error_log('[Ledger][gateway-retain] ' . $e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════
    // المسار 3: Forward — إلى بنك أو بوابة أخرى
    // destination: 'bank:mashreq' | 'bank:jpmorgan' | 'bank:hsbc' |
    //              'bank:nbe' | 'gateway:wise' | 'gateway:paypal' |
    //              'gateway:stripe' | 'gateway:binance' | ...
    // ═══════════════════════════════════════════════════════

    /**
     * الوجهات المدعومة للمسار الثالث
     */
    public static function forwardDestinations(): array
    {
        return [
            // بنوك
            'bank:mashreq'  => ['name'=>'Mashreq Bank',        'type'=>'bank',    'currency'=>'AED', 'account'=>getenv('MASHREQ_ACCOUNT_NUMBER'),  'iban'=>getenv('MASHREQ_IBAN'),       'swift'=>getenv('MASHREQ_SWIFT')],
            'bank:jpmorgan' => ['name'=>'JP Morgan Chase',      'type'=>'bank',    'currency'=>'USD', 'account'=>getenv('JPMORGAN_ACCOUNT_NUMBER'), 'routing'=>getenv('JPMORGAN_ROUTING'), 'swift'=>getenv('JPMORGAN_SWIFT')],
            'bank:hsbc'     => ['name'=>'HSBC UAE',             'type'=>'bank',    'currency'=>'AED', 'account'=>'',                                'swift'=>'BBMEAEAD'],
            'bank:nbe'      => ['name'=>'NBE Egypt',            'type'=>'bank',    'currency'=>'EGP', 'account'=>'',                                'swift'=>'NBEGEGCX601'],
            // بوابات
            'gateway:wise'     => ['name'=>'Wise',    'type'=>'gateway', 'currency'=>'*', 'note'=>'International bank transfer via Wise API'],
            'gateway:paypal'   => ['name'=>'PayPal',  'type'=>'gateway', 'currency'=>'*', 'note'=>'PayPal payout to linked account'],
            'gateway:stripe'   => ['name'=>'Stripe',  'type'=>'gateway', 'currency'=>'*', 'note'=>'Stripe payout to bank account'],
            'gateway:binance'  => ['name'=>'Binance', 'type'=>'gateway', 'currency'=>'*', 'note'=>'Binance transfer/withdrawal'],
            // Ledger (اختصار)
            'ledger'           => ['name'=>'Ledger TRC20', 'type'=>'ledger', 'currency'=>'USDT', 'address'=>defined('LEDGER_TRC20_ADDRESS') ? LEDGER_TRC20_ADDRESS : getenv('LEDGER_TRC20_ADDRESS')],
            'ledger_trx'       => ['name'=>'Ledger TRC20', 'type'=>'ledger', 'currency'=>'USDT', 'address'=>defined('LEDGER_TRC20_ADDRESS') ? LEDGER_TRC20_ADDRESS : getenv('LEDGER_TRC20_ADDRESS')],
        ];
    }

    /**
     * المسار 3: تسجيل طلب التحويل للوجهة المحددة
     * (التنفيذ الفعلي يدوي أو عبر cron/API)
     */
    public function forwardToDestination(array $params): array
    {
        $reference   = trim((string)($params['reference']   ?? ''));
        $amount      = (float)($params['amount']            ?? 0);
        $currency    = strtoupper(trim((string)($params['currency'] ?? 'USD'))) ?: 'USD';
        $gateway     = $this->normalizeGatewayCode((string)($params['gateway'] ?? ''));
        $destination = strtolower(trim((string)($params['destination'] ?? '')));
        $txnId       = isset($params['transaction_id']) ? (int)$params['transaction_id'] : null;
        $txnType     = strtolower(trim((string)($params['txn_type'] ?? '')));

        // تخطي AUTH / Refund / Void
        $skipTypes = ['auth', 'auth_hold', 'auth_moto', 'hold', 'refund', 'avoid', 'void', 'reversal'];
        if ($txnType !== '' && in_array($txnType, $skipTypes, true)) {
            return ['success' => false, 'skipped' => true, 'message' => 'Forward skipped for txn type ' . $txnType];
        }

        if ($reference === '' || $amount <= 0 || $destination === '') {
            return ['success' => false, 'message' => 'Invalid forward params'];
        }

        $destinations = self::forwardDestinations();
        $destInfo     = $destinations[$destination] ?? null;

        if ($destInfo === null) {
            return ['success' => false, 'message' => 'Unknown destination: ' . $destination];
        }

        $fee    = $this->calculateGatewayFee($gateway, $amount);
        $net    = $fee['net_amount'];

        $result = [
            'success'            => true,
            'route'              => 'forward',
            'reference'          => $reference,
            'gateway'            => $gateway,
            'destination'        => $destination,
            'destination_info'   => $destInfo,
            'fee'                => $fee,
            'net_fiat'           => $net,
            'settlement_target'  => $destination,
            'settlement_path'    => $gateway . '_to_' . $destination,
            'message'            => "مطلوب تحويل {$net} {$currency} من {$gateway} إلى {$destInfo['name']}",
            'status'             => 'pending_transfer',
        ];

        // تسجيل في DB
        try {
            $db = db();
            $txn = $txnId ? $db->find('transactions', ['id' => $txnId]) : $db->find('transactions', ['reference' => $reference]);
            if ($txn) {
                $blob = json_decode((string)($txn['gateway_response'] ?? ''), true) ?: [];
                $blob['settlement_target']  = $destination;
                $blob['settlement_path']    = $result['settlement_path'];
                $blob['forward_request']    = $result;
                $blob['ledger_status']      = 'pending_transfer';
                $data = [
                    'fees'             => $fee['fee_amount'],
                    'net_amount'       => $net,
                    'gateway_response' => json_encode($blob, JSON_UNESCAPED_UNICODE),
                    'updated_at'       => date('Y-m-d H:i:s'),
                ];
                $txnId ? $db->update('transactions', $data, ['id' => $txnId])
                       : $db->update('transactions', $data, ['reference' => $reference]);
            }

            // إضافة لطابور التحويل
            $db->execute(
                "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "forward_transfer_queue` (
                    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `reference`   VARCHAR(100) NOT NULL,
                    `from_gateway` VARCHAR(50) NOT NULL,
                    `destination` VARCHAR(100) NOT NULL,
                    `amount`      DECIMAL(18,4) NOT NULL,
                    `currency`    VARCHAR(10) NOT NULL DEFAULT 'USD',
                    `net_amount`  DECIMAL(18,4) NOT NULL,
                    `status`      VARCHAR(30) NOT NULL DEFAULT 'pending',
                    `notes`       TEXT NULL,
                    `processed_at` DATETIME NULL,
                    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY `idx_ref` (`reference`), KEY `idx_status` (`status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            $db->execute(
                "INSERT INTO " . DB_PREFIX . "forward_transfer_queue
                 (reference, from_gateway, destination, amount, currency, net_amount, status, notes, created_at)
                 VALUES (?,?,?,?,?,?,'pending',?,NOW())",
                [$reference, $gateway, $destination, $amount, $currency, $net, json_encode($destInfo)]
            );
        } catch (Throwable $e) {
            error_log('[Ledger][forward] ' . $e->getMessage());
        }

        return $result;
    }
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
            'settlement_target'  => 'gateway',
        ];
    }

    /**
     * تحويل مبلغ فيات إلى USDT تقريبي.
     */
    public function toUsdt(float $amount, string $currency): float
    {
        $currency = strtoupper(trim($currency));
        if (in_array($currency, ['USD', 'USDT', 'USDC'], true)) {
            return round($amount, 6);
        }

        if (!class_exists('ExchangeRateService')) {
            require_once __DIR__ . '/ExchangeRateService.php';
        }
        $calc = ExchangeRateService::getInstance()->calculate($amount, $currency, 'USDT');
        if (empty($calc['crypto_amount']) || (float) ($calc['rate'] ?? 0) <= 0) {
            throw new RuntimeException('Live FX rate unavailable for ' . $currency);
        }
        return round((float) $calc['crypto_amount'], 6);
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

        $target = strtolower(trim((string) ($params['destination'] ?? $params['settlement_target'] ?? 'gateway')));
        $ledgerCheckout = !empty($params['ledger_checkout'])
            || strtolower((string) ($params['source'] ?? '')) === 'ledger_checkout';
        if ($this->keepsFundsOnGateway($gateway, $target, $ledgerCheckout)) {
            return $this->retainOnGateway($params);
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
                'success' => false,
                'skipped' => true,
                'message' => 'Settlement skipped for txn type ' . $txnType,
                'fee'     => $this->calculateGatewayFee($gateway, $amount),
            ];
        }

        $fee = $this->calculateGatewayFee($gateway, $amount);
        $netFiat = $fee['net_amount'];
        $asset = $this->settlementAsset();
        // حالياً التنفيذ الفعلي: USDT TRC20 إلى Ledger (TRX لاحقاً إن لزم)
        try {
            $cryptoAmount = $this->toUsdt($netFiat, $currency);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'queued' => false];
        }

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
            $result['success'] = false;
            $result['skipped'] = true;
            $result['message'] = 'Net amount is zero after doubled gateway fee; no on-chain transfer';
            $this->persistFeeFields($reference, $txnId, $fee, 0, $ledgerAddr, 'pending');
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

            $result['error_code'] = $send['error_code'] ?? 'SEND_FAILED';
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
