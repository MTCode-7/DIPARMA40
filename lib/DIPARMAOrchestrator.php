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

require_once __DIR__ . '/NuveiAdapter.php';
require_once __DIR__ . '/WiseService.php';
require_once __DIR__ . '/Adapters/GatewayAdapterFactory.php';

class DIPARMAOrchestrator
{
    private static ?self $instance = null;
    private ?object $db;

    /* ── بوابات الدفع المتاحة ─────────────────────────── */
    private array $GATEWAYS = [
        'nuvei'       => ['name'=>'Nuvei × Mashreq',    'type'=>'card',   'priority'=>1, 'currencies'=>['USD','AED','EUR','GBP','SAR'],'max_amount'=>500000],
        'stripe'      => ['name'=>'Stripe',              'type'=>'card',   'priority'=>2, 'currencies'=>['USD','EUR','GBP','AED'],     'max_amount'=>999999],
        'paypal'      => ['name'=>'PayPal',              'type'=>'card',   'priority'=>3, 'currencies'=>['USD','EUR','GBP'],           'max_amount'=>10000],
        'myfatoorah'  => ['name'=>'MyFatoorah',          'type'=>'card',   'priority'=>4, 'currencies'=>['AED','SAR','KWD','QAR','EGP'],'max_amount'=>100000],
        'wise'        => ['name'=>'Wise',                'type'=>'bank',   'priority'=>1, 'currencies'=>['USD','EUR','GBP','AED'],     'max_amount'=>1000000],
        'binance'     => ['name'=>'Binance Pay',         'type'=>'crypto', 'priority'=>1, 'currencies'=>['USD','USDT','BNB'],         'max_amount'=>999999],
        'gate_io'     => ['name'=>'Gate.io',             'type'=>'crypto', 'priority'=>2, 'currencies'=>['USD','USDT'],               'max_amount'=>999999],
    ];

    /* ── بنوك مباشرة ──────────────────────────────────── */
    private array $BANKS = [
        'mashreq'  => ['name'=>'Mashreq Bank PSC',           'currency'=>'AED','iban'=>'AE300330000019101562722','swift'=>'BOMLAEADXXX','beneficiary'=>'TRANSCENDIO FZ-LLC'],
        'hsbc'     => ['name'=>'HSBC Bank Middle East',      'currency'=>'AED','iban'=>'AE850200000013053368001','swift'=>'BBMEAEAD',   'beneficiary'=>'MR RAGEH SAEED ALI BAKRAIT'],
        'nbe'      => ['name'=>'National Bank of Egypt',     'currency'=>'EGP','iban'=>'EG170003060131711241527030330','swift'=>'NBEGEGCX601','beneficiary'=>'TRANSCENDIO FZ-LLC'],
        'jpmorgan' => ['name'=>'JP Morgan Chase Bank N.A.',  'currency'=>'USD','account'=>'663525063665','routing'=>'111000614','swift'=>'CHASUS33','beneficiary'=>'ROBERT VALLES JR IOLTA'],
    ];

    /* ── 18 POS Terminals ─────────────────────────────── */
    private array $POS_TERMINALS = [
        'POS-001' => ['name'=>'Terminal Dubai Main',    'type'=>'BITEL_IC3600','location'=>'Dubai HQ',        'status'=>'active','gateway'=>'nuvei'],
        'POS-002' => ['name'=>'Terminal Dubai Branch',  'type'=>'BITEL_IC3600','location'=>'Dubai Branch',    'status'=>'active','gateway'=>'nuvei'],
        'POS-003' => ['name'=>'Terminal Abu Dhabi',     'type'=>'BITEL_IC3600','location'=>'Abu Dhabi',       'status'=>'active','gateway'=>'nuvei'],
        'POS-004' => ['name'=>'Terminal Sharjah',       'type'=>'BITEL_IC3600','location'=>'Sharjah',         'status'=>'active','gateway'=>'stripe'],
        'POS-005' => ['name'=>'Terminal Ajman',         'type'=>'BITEL_IC3600','location'=>'Ajman',           'status'=>'active','gateway'=>'nuvei'],
        'POS-006' => ['name'=>'Terminal RAK',           'type'=>'BITEL_IC3600','location'=>'Ras Al Khaimah',  'status'=>'active','gateway'=>'nuvei'],
        'POS-007' => ['name'=>'Terminal Fujairah',      'type'=>'BITEL_IC3600','location'=>'Fujairah',        'status'=>'active','gateway'=>'nuvei'],
        'POS-008' => ['name'=>'Terminal Cairo',         'type'=>'BITEL_IC3600','location'=>'Cairo, Egypt',    'status'=>'active','gateway'=>'myfatoorah'],
        'POS-009' => ['name'=>'Terminal Alexandria',    'type'=>'BITEL_IC3600','location'=>'Alexandria, Egypt','status'=>'active','gateway'=>'myfatoorah'],
        'POS-010' => ['name'=>'Terminal Riyadh',        'type'=>'BITEL_IC3600','location'=>'Riyadh, KSA',     'status'=>'active','gateway'=>'myfatoorah'],
        'POS-011' => ['name'=>'Terminal Jeddah',        'type'=>'BITEL_IC3600','location'=>'Jeddah, KSA',     'status'=>'active','gateway'=>'myfatoorah'],
        'POS-012' => ['name'=>'Terminal Kuwait',        'type'=>'BITEL_IC3600','location'=>'Kuwait City',     'status'=>'active','gateway'=>'myfatoorah'],
        'POS-013' => ['name'=>'Terminal Doha',          'type'=>'BITEL_IC3600','location'=>'Doha, Qatar',     'status'=>'active','gateway'=>'myfatoorah'],
        'POS-014' => ['name'=>'Terminal Bahrain',       'type'=>'BITEL_IC3600','location'=>'Manama, Bahrain', 'status'=>'active','gateway'=>'nuvei'],
        'POS-015' => ['name'=>'Terminal London',        'type'=>'BITEL_IC3600','location'=>'London, UK',      'status'=>'active','gateway'=>'stripe'],
        'POS-016' => ['name'=>'Terminal New York',      'type'=>'BITEL_IC3600','location'=>'New York, USA',   'status'=>'active','gateway'=>'stripe'],
        'POS-017' => ['name'=>'Terminal Paris',         'type'=>'BITEL_IC3600','location'=>'Paris, France',   'status'=>'active','gateway'=>'stripe'],
        'POS-018' => ['name'=>'Terminal Singapore',     'type'=>'BITEL_IC3600','location'=>'Singapore',       'status'=>'active','gateway'=>'stripe'],
    ];

    /* ── أنواع العمليات ───────────────────────────────── */
    private array $TXN_TYPES = [
        'purchase'        => ['method'=>'purchase',  'needs_orig'=>false],
        'auth'            => ['method'=>'authorize', 'needs_orig'=>false],
        'auth_complete'   => ['method'=>'capture',   'needs_orig'=>true ],
        'purchase_advice' => ['method'=>'purchase',  'needs_orig'=>false],
        'refund'          => ['method'=>'refund',    'needs_orig'=>true ],
        'reversal'        => ['method'=>'void',      'needs_orig'=>true ],
        'balance'         => ['method'=>'balance',   'needs_orig'=>false],
        'cash_advance'    => ['method'=>'purchase',  'needs_orig'=>false],
        'void'            => ['method'=>'void',      'needs_orig'=>true ],
        'settlement'      => ['method'=>'settle',    'needs_orig'=>false],
    ];

    private function __construct()
    {
        try {
            require_once __DIR__ . '/../includes/database.php';
            $this->db = db();
        } catch (Exception $e) {
            $this->db = null;
        }
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

        /* ── 1. اختيار الـ Processor ── */
        $processor = $this->selectProcessor($gateway, $currency, $amount, $posId, $txnType);

        /* ── 2. بناء الـ params ── */
        $params = [
            'reference'    => $reference,
            'amount'       => $amount,
            'currency'     => $currency,
            'card_number'  => $input['card_number']  ?? '',
            'card_name'    => $input['card_name']    ?? '',
            'card_expiry'  => $input['card_expiry']  ?? '',
            'card_cvv'     => $input['card_cvv']     ?? '',
            'email'        => $input['email']        ?? 'client@diparmas.com',
            'orig_ref'     => $input['orig_ref']     ?? '',
            'ledger_addr'  => $input['ledger_addr']  ?? 'TEwLFWlwK55b7PuFfzgH1H2f3xs3pLgLn2',
            'processing_mode' => $secMode,
            'pos_device'   => $posId ? ($this->POS_TERMINALS[$posId]['type'] ?? 'BITEL_IC3600') : 'WEB',
            'pos_id'       => $posId,
        ];

        /* ── 3. تنفيذ العملية ── */
        $result = $this->execute($processor, $txnType, $params);

        /* ── 4. Fallback إذا فشل ── */
        if (!$result['success'] && !in_array($txnType, ['refund','void','reversal','auth_complete'])) {
            $fallback = $this->selectFallback($processor, $currency, $amount);
            if ($fallback && $fallback !== $processor) {
                $this->log($reference, 'fallback', "$processor → $fallback");
                $result = $this->execute($fallback, $txnType, $params);
                $result['fallback_used'] = true;
                $result['fallback_from'] = $processor;
                $processor = $fallback;
            }
        }

        /* ── 5. تسجيل في DB ── */
        $this->save($reference, $input, $result, $processor, $posId, $ts);

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
        string $txnType
    ): string {
        /* إذا طُلب processor محدد وهو صالح */
        if ($requested && isset($this->GATEWAYS[$requested])) {
            $gw = $this->GATEWAYS[$requested];
            if (in_array($currency, $gw['currencies']) && $amount <= $gw['max_amount']) {
                return $requested;
            }
        }

        /* إذا طُلب bank محدد */
        if ($requested && isset($this->BANKS[$requested])) {
            return 'bank:' . $requested;
        }

        /* POS Terminal يحدد البوابة */
        if ($posId && isset($this->POS_TERMINALS[$posId])) {
            return $this->POS_TERMINALS[$posId]['gateway'];
        }

        /* اختيار تلقائي حسب العملة والمبلغ */
        return $this->autoSelect($currency, $amount, $txnType);
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
        $fallbacks = [
            'nuvei'      => 'stripe',
            'stripe'     => 'nuvei',
            'myfatoorah' => 'nuvei',
            'paypal'     => 'stripe',
            'wise'       => 'bank:mashreq',
        ];
        return $fallbacks[$failed] ?? null;
    }

    /* ════════════════════════════════════════════════════
       تنفيذ العملية عبر الـ processor المختار
    ════════════════════════════════════════════════════ */
    private function execute(string $processor, string $txnType, array $params): array
    {
        /* Bank direct */
        if (str_starts_with($processor, 'bank:')) {
            $bankCode = substr($processor, 5);
            return $this->executeBank($bankCode, $txnType, $params);
        }

        /* Payment Gateway */
        return match($processor) {
            'nuvei'      => $this->executeNuvei($txnType, $params),
            'stripe'     => $this->executeStripe($txnType, $params),
            'paypal'     => $this->executePayPal($txnType, $params),
            'myfatoorah' => $this->executeMyFatoorah($txnType, $params),
            'wise'       => $this->executeWise($txnType, $params),
            'binance'    => $this->executeBinance($txnType, $params),
            'gate_io'    => $this->executeGateIO($txnType, $params),
            default      => ['success'=>false,'message'=>"Unknown processor: $processor"],
        };
    }

    /* ── Nuvei ──────────────────────────────────────────── */
    private function executeNuvei(string $txnType, array $p): array
    {
        try {
            $nuvei  = new NuveiAdapter();
            $method = $this->TXN_TYPES[$txnType]['method'] ?? 'purchase';
            return match($method) {
                'purchase'  => $nuvei->purchase($p),
                'authorize' => $nuvei->authorize($p),
                'capture'   => $nuvei->capture($p),
                'refund'    => $nuvei->refund($p),
                'void'      => $nuvei->void($p),
                'balance'   => $nuvei->balanceInquiry($p),
                default     => $nuvei->purchase($p),
            };
        } catch (Exception $e) {
            return ['success'=>false,'message'=>'Nuvei: '.$e->getMessage()];
        }
    }

    /* ── Stripe ─────────────────────────────────────────── */
    private function executeStripe(string $txnType, array $p): array
    {
        try {
            require_once __DIR__ . '/Adapters/StripeAdapter.php';
            $stripe = new StripeAdapter();
            $method = $this->TXN_TYPES[$txnType]['method'] ?? 'purchase';
            return $stripe->charge(array_merge($p, ['txn_type'=>$txnType,'method'=>$method]));
        } catch (Exception $e) {
            return ['success'=>false,'message'=>'Stripe: '.$e->getMessage()];
        }
    }

    /* ── PayPal ─────────────────────────────────────────── */
    private function executePayPal(string $txnType, array $p): array
    {
        try {
            require_once __DIR__ . '/PayPalService.php';
            $paypal = new PayPalService();
            return $paypal->createOrder($p);
        } catch (Exception $e) {
            return ['success'=>false,'message'=>'PayPal: '.$e->getMessage()];
        }
    }

    /* ── MyFatoorah ─────────────────────────────────────── */
    private function executeMyFatoorah(string $txnType, array $p): array
    {
        try {
            $apiKey  = defined('MYFAOORAH_API_KEY') ? MYFAOORAH_API_KEY : getenv('MYFAOORAH_API_KEY');
            $env     = defined('MYFAOORAH_ENVIRONMENT') ? MYFAOORAH_ENVIRONMENT : getenv('MYFAOORAH_ENVIRONMENT');
            $baseUrl = ($env === 'live') ? 'https://api.myfatoorah.com' : 'https://apitest.myfatoorah.com';
            $siteUrl = defined('SITE_URL') ? SITE_URL : 'https://diparmas.com';

            $body = [
                'NotificationOption' => 'LNK',
                'InvoiceValue'       => $p['amount'],
                'CurrencyIso'        => $p['currency'],
                'CustomerEmail'      => $p['email'] ?? 'client@diparmas.com',
                'CallBackUrl'        => $siteUrl . '/payment_success.php?ref=' . $p['reference'],
                'ErrorUrl'           => $siteUrl . '/checkout_router.php?error=1',
                'CustomerReference'  => $p['reference'],
            ];

            $ch = curl_init($baseUrl . '/v2/SendPayment');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($body),
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer '.$apiKey],
            ]);
            $res = json_decode(curl_exec($ch) ?: '{}', true);
            curl_close($ch);

            if (!empty($res['Data']['InvoiceURL'])) {
                return ['success'=>true,'checkout_url'=>$res['Data']['InvoiceURL'],'invoice_id'=>$res['Data']['InvoiceId'],'provider'=>'myfatoorah'];
            }
            return ['success'=>false,'message'=>$res['Message'] ?? 'MyFatoorah error'];
        } catch (Exception $e) {
            return ['success'=>false,'message'=>'MyFatoorah: '.$e->getMessage()];
        }
    }

    /* ── Wise ───────────────────────────────────────────── */
    private function executeWise(string $txnType, array $p): array
    {
        try {
            $wise = WiseService::fromConfig();
            return $wise->createTransfer([
                'amount'           => $p['amount'],
                'source_currency'  => $p['currency'],
                'target_currency'  => 'USD',
                'reference'        => $p['reference'],
                'recipient_name'   => $p['card_name'] ?? 'DI PARMA',
                'recipient_email'  => $p['email']     ?? 'client@diparmas.com',
            ]);
        } catch (Exception $e) {
            return ['success'=>false,'message'=>'Wise: '.$e->getMessage()];
        }
    }

    /* ── Binance ────────────────────────────────────────── */
    private function executeBinance(string $txnType, array $p): array
    {
        try {
            require_once __DIR__ . '/Adapters/BinanceOTCAdapter.php';
            $binance = new BinanceOTCAdapter();
            return $binance->charge($p);
        } catch (Exception $e) {
            return ['success'=>false,'message'=>'Binance: '.$e->getMessage()];
        }
    }

    /* ── Gate.io ────────────────────────────────────────── */
    private function executeGateIO(string $txnType, array $p): array
    {
        try {
            require_once __DIR__ . '/Adapters/GateIOAdapter.php';
            $gate = new GateIOAdapter();
            return $gate->charge($p);
        } catch (Exception $e) {
            return ['success'=>false,'message'=>'Gate.io: '.$e->getMessage()];
        }
    }

    /* ── Bank Direct ────────────────────────────────────── */
    private function executeBank(string $bankCode, string $txnType, array $p): array
    {
        $bank = $this->BANKS[$bankCode] ?? null;
        if (!$bank) return ['success'=>false,'message'=>"Bank not found: $bankCode"];

        /* تسجيل التحويل البنكي في DB — يتطلب تأكيد يدوي */
        return [
            'success'     => true,
            'type'        => 'bank_transfer',
            'bank'        => $bank['name'],
            'beneficiary' => $bank['beneficiary'],
            'iban'        => $bank['iban']    ?? null,
            'account'     => $bank['account'] ?? null,
            'routing'     => $bank['routing'] ?? null,
            'swift'       => $bank['swift'],
            'currency'    => $bank['currency'],
            'reference'   => $p['reference'],
            'message'     => 'Bank transfer recorded — awaiting confirmation',
            'provider'    => 'bank:'.$bankCode,
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
        try {
            $this->db->execute(
                "INSERT IGNORE INTO dp_transactions
                 (reference, gateway, amount, currency, card_last4, cardholder_name,
                  transaction_type, security_mode, status, gateway_response,
                  orig_ref, notes, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())",
                [
                    $reference,
                    $processor,
                    $input['amount'] ?? 0,
                    $input['currency'] ?? 'USD',
                    $input['card_number'] ? substr(preg_replace('/\D/','',$input['card_number']),-4) : null,
                    $input['card_name'] ?? null,
                    $input['txn_type'] ?? 'purchase',
                    $input['sec_mode'] ?? '3D',
                    $result['success'] ? 'completed' : 'failed',
                    json_encode([
                        'processor'    => $processor,
                        'pos_id'       => $posId,
                        'auth_code'    => $result['approval_code'] ?? null,
                        'rrn'          => $result['rrn'] ?? null,
                        'txn_id'       => $result['nuvei_txn_id'] ?? $result['payment_intent_id'] ?? null,
                        'fallback'     => $result['fallback_used'] ?? false,
                        'message'      => $result['message'] ?? null,
                    ]),
                    $input['orig_ref'] ?? null,
                    $input['notes']    ?? null,
                ]
            );
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
