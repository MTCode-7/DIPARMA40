<?php
/**
 * ============================================================
 * DI PARMA | Smart Payment Orchestrator
 * ============================================================
 * يوزّع العمليات تلقائياً على:
 * ─ بوابات الدفع: Nuvei, Stripe, PayPal, MyFatoorah, Wise
 * ─ البنوك: Mashreq, HSBC UAE, NBE Egypt, JP Morgan
 * ─ 18 POS Terminal (Bitel IC3600)
 * ─ Crypto: Binance, Gate.io, Ledger TRX
 *
 * منطق التوزيع:
 * 1. Currency → يحدد البنك/البوابة الأنسب
 * 2. TXN Type → يحدد الـ processor المناسب
 * 3. Amount → يوزّع بين بوابات حسب الحدود
 * 4. POS ID → يوجّه لـ terminal محدد
 * 5. Fallback → إذا فشل processor → ينتقل للتالي
 * ============================================================
 */

require_once __DIR__ . '/Adapters/GatewayAdapterFactory.php';
require_once __DIR__ . '/MySystem/ChargeHub.php';

class DIPARMAOrchestrator
{
    private static ?self $instance = null;
    private ?object $db;

    /* ── بوابات الدفع المتاحة ─────────────────────────── */
    private array $GATEWAYS = [
        'nuvei'       => ['name'=>'Nuvei → Ledger',     'type'=>'card',   'priority'=>1, 'currencies'=>['USD','AED','EUR','GBP','SAR'],'max_amount'=>PHP_FLOAT_MAX],
        'square'      => ['name'=>'Square → Ledger',    'type'=>'card',   'priority'=>1, 'currencies'=>['USD','EUR','GBP','AED','CAD','AUD','JPY'],'max_amount'=>PHP_FLOAT_MAX],
        'stripe'      => ['name'=>'Stripe',              'type'=>'card',   'priority'=>2, 'currencies'=>['USD','EUR','GBP','AED'],     'max_amount'=>PHP_FLOAT_MAX],
        'paypal'      => ['name'=>'PayPal → Ledger',     'type'=>'card',   'priority'=>3, 'currencies'=>['USD','EUR','GBP','AED'],    'max_amount'=>PHP_FLOAT_MAX],
        'payram'      => ['name'=>'PayRam → Ledger',     'type'=>'crypto', 'priority'=>4, 'currencies'=>['USD','USDT','EUR','GBP','AED'],'max_amount'=>PHP_FLOAT_MAX],
        'whop'        => ['name'=>'Whop → Ledger',       'type'=>'card',   'priority'=>5, 'currencies'=>['USD','EUR'],                'max_amount'=>PHP_FLOAT_MAX],
        'diparma'     => ['name'=>'DI PARMA → Ledger',   'type'=>'card',   'priority'=>1, 'currencies'=>['USD','AED','EUR','GBP','SAR'],'max_amount'=>PHP_FLOAT_MAX],
        'diparma_gateway' => ['name'=>'DIPARMA GATEWAY → Ledger','type'=>'card','priority'=>1, 'currencies'=>['USD','USDT','AED','EUR','GBP','SAR','KWD','QAR','EGP'],'max_amount'=>PHP_FLOAT_MAX],
        'myfatoorah'  => ['name'=>'MyFatoorah',          'type'=>'card',   'priority'=>4, 'currencies'=>['AED','SAR','KWD','QAR','EGP'],'max_amount'=>PHP_FLOAT_MAX],
        'wise'        => ['name'=>'Wise',                'type'=>'bank',   'priority'=>1, 'currencies'=>['USD','EUR','GBP','AED'],     'max_amount'=>PHP_FLOAT_MAX],
        'binance'     => ['name'=>'Binance Pay',         'type'=>'crypto', 'priority'=>1, 'currencies'=>['USD','USDT','BNB'],         'max_amount'=>PHP_FLOAT_MAX],
        'gate_io'     => ['name'=>'Gate.io',             'type'=>'crypto', 'priority'=>2, 'currencies'=>['USD','USDT'],               'max_amount'=>PHP_FLOAT_MAX],
    ];

    /* ── بنوك مباشرة ──────────────────────────────────── */
    private array $BANKS = [
        'mashreq'  => ['name'=>'Mashreq Bank PSC',           'currency'=>'AED','iban'=>'AE300330000019101562722','swift'=>'BOMLAEADXXX','beneficiary'=>'TRANSCENDIO FZ-LLC'],
        'hsbc'     => ['name'=>'HSBC Bank Middle East',      'currency'=>'AED','iban'=>'AE850200000013053368001','swift'=>'BBMEAEAD',   'beneficiary'=>'MR RAGEH SAEED ALI BAKRAIT'],
        'nbe'      => ['name'=>'National Bank of Egypt',     'currency'=>'EGP','iban'=>'EG170003060131711241527030330','swift'=>'NBEGEGCX601','beneficiary'=>'TRANSCENDIO FZ-LLC'],
        'jpmorgan' => ['name'=>'JP Morgan Chase Bank N.A.',  'currency'=>'USD','account'=>'663525063665','routing'=>'111000614','swift'=>'CHASUS33','beneficiary'=>'ROBERT VALLES JR IOLTA'],
    ];

    /* ── أجهزة POS الحقيقية (TID فعلي فقط) ─────────────── */
    private array $POS_TERMINALS = [];

    /* ── أنواع العمليات المعيارية (POS) ─────────────── */
    private array $TXN_TYPES = [
        'purchase_2d'         => ['method'=>'purchase',  'needs_orig'=>false],
        'purchase_3d'         => ['method'=>'purchase',  'needs_orig'=>false],
        'online_sale_moto'    => ['method'=>'purchase',  'needs_orig'=>false],
        'offline_sale_moto'   => ['method'=>'purchase',  'needs_orig'=>false],
        'auth'                => ['method'=>'authorize', 'needs_orig'=>false],
        'capture'             => ['method'=>'capture',   'needs_orig'=>true ],
        'purchase_advice'     => ['method'=>'purchase',  'needs_orig'=>true ],
        'refund'              => ['method'=>'refund',    'needs_orig'=>true ],
        'avoid'               => ['method'=>'void',      'needs_orig'=>true ],
        'withdrawal_pos'      => ['method'=>'purchase',  'needs_orig'=>false],
        'withdrawal_nfc'      => ['method'=>'purchase',  'needs_orig'=>false],
        // aliases
        'purchase'            => ['method'=>'purchase',  'needs_orig'=>false],
        'purchase_offline'    => ['method'=>'purchase',  'needs_orig'=>false],
        'purchase_online'     => ['method'=>'purchase',  'needs_orig'=>false],
        'auth_hold'           => ['method'=>'authorize', 'needs_orig'=>false],
        'auth_moto'           => ['method'=>'authorize', 'needs_orig'=>false],
        'auth_complete'       => ['method'=>'capture',   'needs_orig'=>true ],
        'auth_capture'        => ['method'=>'capture',   'needs_orig'=>true ],
        'cash_advance'        => ['method'=>'purchase',  'needs_orig'=>false],
        'void'                => ['method'=>'void',      'needs_orig'=>true ],
        'reversal'            => ['method'=>'void',      'needs_orig'=>true ],
        'balance'             => ['method'=>'balance',   'needs_orig'=>false],
        'settlement'          => ['method'=>'settle',    'needs_orig'=>false],
    ];

    private function __construct()
    {
        try {
            require_once __DIR__ . '/../includes/database.php';
            $this->db = db();
            $this->loadRealPosTerminals();
        } catch (Exception $e) {
            $this->db = null;
        }
    }

    private function loadRealPosTerminals(): void
    {
        $file = dirname(__DIR__) . '/pos/lib/company_terminals.php';
        if (!is_file($file)) {
            return;
        }
        require_once $file;
        if (!function_exists('pos_company_terminals_with_tid')) {
            return;
        }
        $out = [];
        foreach (pos_company_terminals_with_tid() as $row) {
            $tid = trim((string) ($row['tid'] ?? ''));
            if ($tid === '') {
                continue;
            }
            $out[$tid] = [
                'name' => trim((string) (($row['brand'] ?? '') . ' ' . ($row['name'] ?? $tid))),
                'type' => (string) ($row['model'] ?? ''),
                'location' => (string) ($row['line'] ?? ''),
                'status' => 'active',
                'gateway' => '',
            ];
        }
        $this->POS_TERMINALS = $out;
    }

    public static function getInstance(): self
    {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    /* ════════════════════════════════════════════════════
       MAIN ENTRY — يوزّع العملية تلقائياً
    ════════════════════════════════════════════════════ */
    public function process(array $input): array
    {
        $ts        = time();
        $reference = $input['reference'] ?? ('DP-' . strtoupper(bin2hex(random_bytes(5))));
        $txnType   = strtolower($input['txn_type']  ?? 'purchase');
        $amount    = (float)($input['amount']        ?? 0);
        $currency  = strtoupper($input['currency']  ?? 'USD');
        $posId     = $input['pos_id']               ?? null;
        $gateway   = strtolower($input['gateway']   ?? '');
        $secMode   = strtoupper($input['sec_mode']  ?? '3D');
        require_once __DIR__ . '/CardScaService.php';
        if ($secMode === '3D' && !CardScaService::shouldChallenge($input)) {
            $secMode = '2D';
        }

        /* ── 1. اختيار الـ Processor — بدون تبديل صامت لبوابة أخرى ── */
        $fromPos = $posId || !empty($input['pos_device']) || strtolower((string)($input['source'] ?? '')) === 'pos';
        if ($fromPos) {
            $gateway = strtolower(trim((string) ($input['gateway'] ?? $input['card_provider'] ?? $gateway)));
            $input['gateway'] = $gateway;
            $input['allow_fallback'] = false;
            $input['destination'] = 'gateway';
        }
        $allowFallback = !$fromPos && !empty($input['allow_fallback']);
        $processor = $fromPos
            ? $gateway
            : $this->selectProcessor($gateway, $currency, $amount, $posId, $txnType, $allowFallback);
        if ($processor === '') {
            return [
                'success' => false,
                'message' => $gateway !== ''
                    ? "Gateway '{$gateway}' is not available for this amount/currency"
                    : 'Gateway is required',
                'reference' => $reference,
                'timestamp' => date('c', $ts),
            ];
        }

        /* ── 2. بناء الـ params ── */
        $params = [
            'reference'    => $reference,
            'amount'       => $amount,
            'currency'     => $currency,
            'card_number'  => $input['card_number']  ?? '',
            'card_name'    => $input['card_name']    ?? '',
            'card_expiry'  => $input['card_expiry']  ?? '',
            'card_cvv'     => $input['card_cvv']     ?? '',
            'email'        => $input['email']        ?? '',
            'orig_ref'     => $input['orig_ref']     ?? '',
            'ledger_addr'  => $input['ledger_addr']  ?? (defined('LEDGER_TRC20_ADDRESS') ? LEDGER_TRC20_ADDRESS : ''),
            'processing_mode' => $secMode,
            'pos_device'   => $posId ? ($this->POS_TERMINALS[$posId]['type'] ?? '') : 'WEB',
            'pos_id'       => $posId,
        ];

        /* ── 3. تنفيذ العملية على البوابة المختارة فقط ── */
        $result = $this->execute($processor, $txnType, $params);

        /* ── 4. Fallback اختياري فقط (allow_fallback=true) ── */
        if ($allowFallback && !$result['success'] && !in_array($txnType, ['refund','void','reversal','auth_complete'])) {
            $fallback = $this->selectFallback($processor, $currency, $amount);
            if ($fallback && $fallback !== $processor) {
                $this->log($reference, 'fallback', "$processor → $fallback");
                $result = $this->execute($fallback, $txnType, $params);
                $result['fallback_used'] = true;
                $result['fallback_from'] = $processor;
                $processor = $fallback;
            }
        }

        /* ── 5. تسجيل في DB (تخطي إن ChargeHub حفظ Order مسبقاً) ── */
        if (empty($result['order_persisted'])) {
            $this->save($reference, $input, $result, $processor, $posId, $ts);
        } elseif (!empty($result['reference'])) {
            $reference = (string) $result['reference'];
        }

        if (!empty($result['success']) && empty($result['requires_3ds']) && empty($result['redirect_url'])) {
            require_once __DIR__ . '/CardScaService.php';
            CardScaService::markCompleted(
                (string) ($input['card_number'] ?? $input['cc_number'] ?? ''),
                (string) ($input['card_expiry'] ?? $input['cc_expiry'] ?? ''),
                (string) ($input['card_last4'] ?? ''),
                $reference
            );
        }

        /* ── 5b. تسوية فورية للصافي → Ledger (نسبة البوابة فقط) ── */
        $ledgerSettle = null;
        $alreadyLedger = !empty($result['no_bank']) && $processor !== 'diparma_gateway';
        if (!empty($result['success']) && empty($result['requires_3ds']) && empty($result['redirect_url']) && !$alreadyLedger) {
            try {
                require_once __DIR__ . '/LedgerSettlementService.php';
                $ledgerSettle = LedgerSettlementService::getInstance()->settleToLedger([
                    'reference'      => $reference,
                    'amount'         => $amount,
                    'currency'       => $currency,
                    'gateway'        => $processor,
                    'ledger_address' => $params['ledger_addr'] ?? '',
                    'user_id'        => (int)($input['user_id'] ?? ($_SESSION['user_id'] ?? 0)),
                    'txn_type'       => $txnType,
                    'destination'    => 'gateway',
                ]);
            } catch (Throwable $e) {
                error_log('[DIPARMA-ORCH] Ledger settle: ' . $e->getMessage());
                $ledgerSettle = ['success' => false, 'message' => $e->getMessage(), 'queued' => true];
            }
        }

        /* ── 6. الرد ── */
        return array_merge($result, [
            'reference'    => $reference,
            'processor'    => $processor,
            'processor_name' => $this->getProcessorName($processor),
            'pos_id'       => $posId,
            'pos_name'     => $posId ? ($this->POS_TERMINALS[$posId]['name'] ?? $posId) : null,
            'txn_type'     => $txnType,
            'amount'       => $amount,
            'currency'     => $currency,
            'ledger_settlement' => $ledgerSettle,
            'timestamp'    => date('c', $ts),
        ]);
    }

    /* ════════════════════════════════════════════════════
       اختيار الـ Processor تلقائياً
    ════════════════════════════════════════════════════ */
    private function selectProcessor(
        string $requested,
        string $currency,
        float  $amount,
        ?string $posId,
        string $txnType,
        bool $allowAutoSelect = false
    ): string {
        /* بوابة صريحة مطلوبة — لا نبدّلها ببوابة أخرى */
        if ($requested !== '') {
            if (isset($this->GATEWAYS[$requested])) {
                $gw = $this->GATEWAYS[$requested];
                if (in_array($currency, $gw['currencies'], true) && $amount <= $gw['max_amount']) {
                    return $requested;
                }
                return '';
            }
            if (isset($this->BANKS[$requested])) {
                return 'bank:' . $requested;
            }
            if (str_starts_with($requested, 'bank:') && isset($this->BANKS[substr($requested, 5)])) {
                return $requested;
            }
            return '';
        }

        if ($posId && isset($this->POS_TERMINALS[$posId]) && $requested === '') {
            return $this->POS_TERMINALS[$posId]['gateway'] ?? '';
        }

        /* اختيار تلقائي فقط عند السماح صراحة */
        if ($allowAutoSelect) {
            return $this->autoSelect($currency, $amount, $txnType);
        }

        return '';
    }

    private function autoSelect(string $currency, float $amount, string $txnType): string
    {
        /* EGP → MyFatoorah أو NBE */
        if ($currency === 'EGP') return 'myfatoorah';

        /* KWD/QAR/BHD/OMR → MyFatoorah */
        if (in_array($currency, ['KWD','QAR','BHD','OMR','SAR'])) return 'myfatoorah';

        /* USDT/TRX/BNB → Crypto */
        if (in_array($currency, ['USDT','TRX','BNB'])) return 'binance';

        /* USD/EUR/GBP مبالغ كبيرة → Wise */
        if (in_array($currency, ['USD','EUR','GBP']) && $amount > 50000) return 'wise';

        /* AED → Nuvei (Mashreq) */
        if ($currency === 'AED') return 'nuvei';

        /* Default → Nuvei */
        return 'nuvei';
    }

    private function selectFallback(string $failed, string $currency, float $amount): ?string
    {
        if (str_starts_with($failed, 'pos') || $failed === 'nuvei') {
            return null;
        }
        $fallbacks = [
            'stripe'     => 'nuvei',
            'myfatoorah' => 'nuvei',
            'paypal'     => 'nuvei',
            'wise'       => 'bank:mashreq',
        ];
        return $fallbacks[$failed] ?? null;
    }

    /* ════════════════════════════════════════════════════
       تنفيذ العملية عبر الـ processor المختار
       (ChargeHub = POS pipe للبوابات المدعومة)
    ════════════════════════════════════════════════════ */
    private function execute(string $processor, string $txnType, array $params): array
    {
        if (str_starts_with($processor, 'bank:')) {
            $bankCode = substr($processor, 5);
            return $this->executeBank($bankCode, $txnType, $params);
        }

        require_once __DIR__ . '/MySystem/ChargeHub.php';
        $params['channel'] = $params['channel'] ?? 'diparma_orchestrator';
        return DiParmaChargeHub::charge($processor, $txnType, $params);
    }

    /* ── Bank Direct ────────────────────────────────────── */
    private function executeBank(string $bankCode, string $txnType, array $p): array
    {
        $bank = $this->BANKS[$bankCode] ?? null;
        if (!$bank) {
            return ['success' => false, 'message' => "Bank not found: $bankCode"];
        }

        return [
            'success'     => false,
            'type'        => 'bank_transfer',
            'bank'        => $bank['name'],
            'beneficiary' => $bank['beneficiary'],
            'iban'        => $bank['iban']    ?? null,
            'account'     => $bank['account'] ?? null,
            'routing'     => $bank['routing'] ?? null,
            'swift'       => $bank['swift'],
            'currency'    => $bank['currency'],
            'reference'   => $p['reference'],
            'message'     => 'Bank transfer is not an online charge. Pay the IBAN; confirmation comes from the bank, not a local success.',
            'provider'    => 'bank:' . $bankCode,
        ];
    }

    /* ════════════════════════════════════════════════════
       قائمة الـ POS الـ 18
    ════════════════════════════════════════════════════ */
    public function getPOSList(): array
    {
        return $this->POS_TERMINALS;
    }

    public function getPOSStatus(string $posId): array
    {
        if (!isset($this->POS_TERMINALS[$posId])) {
            return ['success'=>false,'message'=>"POS not found: $posId"];
        }
        $pos = $this->POS_TERMINALS[$posId];
        return [
            'success'  => true,
            'pos_id'   => $posId,
            'name'     => $pos['name'],
            'type'     => $pos['type'],
            'location' => $pos['location'],
            'status'   => $pos['status'],
            'gateway'  => $pos['gateway'],
        ];
    }

    /* ════════════════════════════════════════════════════
       حفظ في DB
    ════════════════════════════════════════════════════ */
    private function save(
        string $reference, array $input, array $result,
        string $processor, ?string $posId, int $ts
    ): void {
        if (!$this->db) return;
        $pending3ds = !empty($result['requires_3ds']) || !empty($result['redirect_url']);
        if (!diparma_should_persist_charge(!empty($result['success']), $pending3ds)) {
            return;
        }
        try {
            $cardLast4 = null;
            if (!empty($input['card_number'])) {
                $cardLast4 = substr(preg_replace('/\D/', '', (string) $input['card_number']), -4);
            }
            $this->db->insertAvailable('transactions', [
                'reference' => $reference,
                'gateway' => $processor,
                'amount' => $input['amount'] ?? 0,
                'currency' => $input['currency'] ?? 'USD',
                'card_last4' => $cardLast4,
                'cardholder_name' => $input['card_name'] ?? null,
                'transaction_type' => $input['txn_type'] ?? 'purchase',
                'security_mode' => $input['sec_mode'] ?? '3D',
                'status' => $pending3ds ? 'pending' : 'completed',
                'gateway_response' => json_encode([
                    'processor'    => $processor,
                    'pos_id'       => $posId,
                    'auth_code'    => $result['approval_code'] ?? null,
                    'rrn'          => $result['rrn'] ?? null,
                    'txn_id'       => $result['nuvei_txn_id'] ?? $result['payment_intent_id'] ?? null,
                    'fallback'     => $result['fallback_used'] ?? false,
                    'message'      => $result['message'] ?? null,
                ]),
                'orig_ref' => $input['orig_ref'] ?? null,
                'notes' => $input['notes'] ?? null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Exception $e) {
            error_log('[Orchestrator] Save: '.$e->getMessage());
        }
    }

    private function getProcessorName(string $processor): string
    {
        if (str_starts_with($processor, 'bank:')) {
            $code = substr($processor, 5);
            return $this->BANKS[$code]['name'] ?? $processor;
        }
        return $this->GATEWAYS[$processor]['name'] ?? $processor;
    }

    private function log(string $ref, string $event, string $detail): void
    {
        error_log("[DIPARMA-ORCH][{$ref}] {$event}: {$detail}");
    }
}
