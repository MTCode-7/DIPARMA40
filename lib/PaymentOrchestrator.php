<?php
/**
 * DI PARMA | PaymentOrchestrator
 * Compat layer: risk/KYC/hosted checkout + webhook confirm.
 * Card charges go through DiParmaChargeHub (no parallel adapter path).
 */

require_once __DIR__ . '/RiskEngine.php';
require_once __DIR__ . '/KYCService.php';
require_once __DIR__ . '/CardPaymentService.php';
require_once __DIR__ . '/ExchangeAPIService.php';
require_once __DIR__ . '/ExchangeRateService.php';
require_once __DIR__ . '/EventBus.php';
require_once __DIR__ . '/WalletService.php';

class PaymentOrchestrator
{
    private static ?self $instance = null;
    private Database $db;

    private function __construct()
    {
        $this->db = db();
    }

    public static function getInstance(): self
    {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    // ══════════════════════════════════════════════════════════
    // STEP 1 — إنشاء طلب الشراء
    // ══════════════════════════════════════════════════════════

    /**
     * نقطة الدخول الرئيسية
     * العميل يرسل: amount, currency, crypto, network, wallet_address, email
     */
    public function initiatePurchase(array $input): array
    {
        $userId       = intval($_SESSION['user_id'] ?? 0);
        $fiatAmount   = round(floatval($input['amount']         ?? 0), 2);
        $fiat         = strtoupper(trim($input['currency']      ?? 'AED'));
        $coin         = strtoupper(trim($input['crypto']        ?? 'USDT'));
        $network      = strtoupper(trim($input['network']       ?? 'TRC20'));
        $walletAddr   = trim($input['wallet_address']           ?? '');
        $email        = trim($input['email']                    ?? '');
        $cardProvider = strtolower(trim((string)($input['card_provider'] ?? $input['gateway'] ?? '')));
        if ($cardProvider === '') {
            $cardProvider = strtolower(trim((string)(getenv('CARD_PROVIDER') ?: '')));
        }
        if ($cardProvider === '') {
            return $this->fail('بوابة الدفع مطلوبة (card_provider)', $this->resolveReference($input));
        }
        $protocol     = trim($input['protocol']                 ?? '');
        $paymentType  = strtoupper(trim($input['payment_type']  ?? ''));
        $txnHint      = strtolower(trim((string)($input['transaction_type'] ?? $input['txn_type'] ?? '')));
        $reference    = $this->resolveReference($input);

        // عمليات البطاقة من صفحات checkout → مسار البوابة المختارة فقط (MOTO/2D)
        $hasTokenRaw = trim((string)($input['cloud_token'] ?? $input['source_id'] ?? $input['payment_token'] ?? ''));
        if (strcasecmp($hasTokenRaw, 'cnon:card-nonce-ok') === 0) {
            return $this->fail('Square requires a real card nonce from Web Payments SDK', $reference);
        }
        $hasToken = strlen($hasTokenRaw) >= 8;
        $hasCard = preg_replace('/\D/', '', (string)($input['cc_number'] ?? $input['card_number'] ?? '')) !== '';
        $cardTxnTypes = ['purchase_2d','purchase_3d','purchase','auth','auth_hold','auth_moto','capture','purchase_advice','purchase_offline','purchase_online','moto_purchase'];
        if ($protocol === '201.3'
            || in_array($paymentType, ['MOTO', 'ONLINE_MOTO'], true)
            || (($hasCard || $hasToken) && in_array($txnHint, $cardTxnTypes, true))
        ) {
            $input['card_provider'] = $cardProvider;
            $input['gateway'] = $cardProvider;
            if ($protocol === '') {
                $input['protocol'] = '201.3';
            }
            return $this->initiateMOTO($input, $userId, $reference);
        }

        // ── [1] التحقق الأساسي ───────────────────────────────
        if ($fiatAmount < 10)    return $this->fail('الحد الأدنى 10 ' . $fiat, $reference);
        $transactionType = strtolower(trim($input['transaction_type'] ?? $input['txn_type'] ?? ''));
        $destination = strtolower(trim($input['destination'] ?? 'ledger'));
        if ($walletAddr === '' && defined('LEDGER_TRC20_ADDRESS')) {
            $walletAddr = (string) LEDGER_TRC20_ADDRESS;
        }
        $input['ledger_address'] = $input['ledger_address'] ?? $walletAddr;
        $requiresWallet = $transactionType === 'crypto_purchase'
            || in_array($destination, ['crypto', 'wallet', 'ledger', 'ledger_trx'], true);
        if ($requiresWallet && empty($walletAddr)) {
            return $this->fail('عنوان المحفظة مطلوب', $reference);
        }
        if (empty($email))       return $this->fail('البريد الإلكتروني مطلوب', $reference);

        // ── [2] فحص المخاطر ──────────────────────────────────
        $risk = RiskEngine::getInstance()->evaluate([
            'user_id'        => $userId,
            'amount'         => $fiatAmount,
            'ip'             => getClientIP(),
            'email'          => $email,
            'wallet_address' => $walletAddr,
        ]);

        if ($risk['decision'] === 'reject') {
            return $this->fail('تم رفض العملية لأسباب أمنية', $reference, ['risk' => $risk]);
        }

        // ── [3] فحص KYC ──────────────────────────────────────
        $kyc = KYCService::getInstance()->getStatus($userId);
        if ($fiatAmount > $kyc['daily_limit']) {
            return $this->fail(
                "المبلغ ({$fiatAmount} {$fiat}) يتجاوز حد KYC اليومي ({$kyc['daily_limit']} {$fiat}). يرجى إكمال التحقق.",
                $reference,
                ['kyc_required' => true, 'kyc_level' => $kyc['level']]
            );
        }

        // ── [4] حساب السعر ───────────────────────────────────
        try {
            $calc = ExchangeRateService::getInstance()->calculate($fiatAmount, $fiat, $coin);
        } catch (RuntimeException $e) {
            return $this->fail('فشل جلب سعر الصرف: ' . $e->getMessage(), $reference);
        }

        // ── [5] حفظ الطلب في DB ──────────────────────────────
        $txnId = $this->db->insert('transactions', [
            'reference'        => $reference,
            'gateway'          => $cardProvider,
            'amount'           => $fiatAmount,
            'currency'         => $fiat,
            'customer_name'    => $input['name']  ?? '',
            'customer_email'   => $email,
            'customer_phone'   => $input['phone'] ?? '',
            'status'           => 'pending',
            'transaction_type' => "شراء {$calc['crypto_amount']} {$coin}/{$network}",
            'user_id'          => $userId,
            'fees'             => $calc['fee_fiat'],
            'net_amount'       => $calc['net_fiat'],
            'security_mode'    => '3D',
            'gateway_response' => json_encode([
                'type'          => 'card_to_crypto',
                'coin'          => $coin,
                'network'       => $network,
                'crypto_amount' => $calc['crypto_amount'],
                'to_address'    => $walletAddr,
                'ledger_address'=> $input['ledger_address'] ?? $walletAddr,
                'destination'   => $destination,
                'rate'          => $calc['final_rate'],
                'risk_score'    => $risk['score'],
                'risk_decision' => $risk['decision'],
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $hasTokenRaw = trim((string)($input['cloud_token'] ?? $input['source_id'] ?? $input['payment_token'] ?? ''));
        if (strcasecmp($hasTokenRaw, 'cnon:card-nonce-ok') === 0) {
            return $this->fail('Square requires a real card nonce from Web Payments SDK', $reference);
        }
        $hasToken = strlen($hasTokenRaw) >= 8;
        $hasCardPan = strlen(preg_replace('/\D/', '', (string)($input['cc_number'] ?? $input['card_number'] ?? ''))) >= 13;
        if ($hasCardPan || $hasToken) {
            require_once __DIR__ . '/MySystem/ChargeHub.php';
            $paymentResult = DiParmaChargeHub::charge($cardProvider, 'purchase_3d', [
                'amount' => $fiatAmount,
                'currency' => $fiat,
                'card_number' => preg_replace('/\D/', '', (string)($input['cc_number'] ?? $input['card_number'] ?? '')),
                'card_expiry' => (string)($input['cc_expiry'] ?? $input['card_expiry'] ?? ''),
                'card_cvv' => (string)($input['cc_cvv'] ?? $input['card_cvv'] ?? $input['cvv2'] ?? ''),
                'reference' => $reference,
                'card_name' => $input['name'] ?? 'Customer',
                'email' => $email,
                'user_id' => $userId,
                'channel' => 'payment_orchestrator_hosted',
                'processing_mode' => '3D',
                'cloud_token' => $input['cloud_token'] ?? $input['payment_token'] ?? null,
                'source_id' => $input['source_id'] ?? null,
                'destination' => 'ledger',
                'ledger_address' => $walletAddr,
            ]);
        } else {
            $paymentResult = CardPaymentService::getInstance()->createPayment([
                'reference'     => $reference,
                'amount'        => $fiatAmount,
                'currency'      => strtolower($fiat),
                'email'         => $email,
                'user_id'       => $userId,
                'card_provider' => $cardProvider,
                'metadata'      => [
                    'crypto'        => $coin,
                    'network'       => $network,
                    'crypto_amount' => $calc['crypto_amount'],
                    'to_address'    => $walletAddr,
                ],
            ]);
        }

        $pending3ds = !empty($paymentResult['requires_3ds'])
            || !empty($paymentResult['redirect_url'])
            || !empty($paymentResult['checkout_url']);
        if (empty($paymentResult['success']) && !$pending3ds) {
            $this->db->update('transactions', ['status' => 'failed'], ['reference' => $reference]);
            return $this->fail($paymentResult['message'] ?? 'Charge failed', $reference);
        }

        // نشر حدث: payment.created
        EventBus::getInstance()->publish('payment.created', [
            'reference'     => $reference,
            'amount'        => $fiatAmount,
            'currency'      => $fiat,
            'crypto_amount' => $calc['crypto_amount'],
            'coin'          => $coin,
            'network'       => $network,
            'to_address'    => $walletAddr,
            'user_id'       => $userId,
        ], $reference, $userId);

        return [
            'success'        => true,
            'reference'      => $reference,
            'transaction_id' => $txnId,
            'payment'        => $paymentResult,
            'order'          => [
                'fiat_amount'   => $fiatAmount,
                'fiat_currency' => $fiat,
                'crypto_amount' => $calc['crypto_amount'],
                'coin'          => $coin,
                'network'       => $network,
                'rate'          => $calc['final_rate'],
                'fee'           => $calc['fee_fiat'],
                'to_address'    => $walletAddr,
            ],
            'risk' => ['score' => $risk['score'], 'decision' => $risk['decision']],
            'next' => $paymentResult['checkout_url'] ?? null,
        ];
    }

    // ══════════════════════════════════════════════════════════
    // STEP 1b — بروتوكول 201.3 MOTO (دفع مباشر بدون 3DS)
    // ══════════════════════════════════════════════════════════

    private function initiateMOTO(array $input, int $userId, string $reference): array
    {
        $fiatAmount   = round(floatval($input['amount']   ?? 0), 2);
        $fiat         = strtoupper(trim($input['currency']  ?? 'USD'));
        $coin         = strtoupper(trim($input['crypto']    ?? 'USDT'));
        $network      = strtoupper(trim($input['network']   ?? 'TRC20'));
        $walletAddr   = trim($input['wallet_address']       ?? '');
        $email        = trim($input['email']                ?? '');

        $requestedProvider = strtolower(trim($input['card_provider'] ?? $input['gateway'] ?? ''));
        $envProvider       = strtolower(trim(getenv('CARD_PROVIDER') ?: 'nuvei'));
        $processingGateways = ['nuvei', 'stripe', 'square', 'checkout', 'checkout.com', 'paytabs',
                                'authorizenet', 'authnet', 'authorize_net', 'myfatoorah', 'diparma',
                                'diparma_gateway', 'paypal', 'braintree', 'payram', 'whop', 'gate_io', 'binance'];
        $cardProvider = in_array($requestedProvider, $processingGateways, true)
            ? $requestedProvider
            : (in_array($envProvider, $processingGateways, true) ? $envProvider : '');
        if ($cardProvider === '') {
            return $this->fail('بوابة الدفع غير محددة', $reference);
        }

        $transactionType = strtolower(trim($input['transaction_type'] ?? $input['txn_type'] ?? $input['payment_type'] ?? ''));
        $rrn = trim((string)($input['rrn'] ?? $input['orig_ref'] ?? ''));
        $approvalCode = trim((string)($input['approval_code'] ?? ''));

        // ── تحقق أساسي ───────────────────────────────────────
        if ($fiatAmount < 1)   return $this->fail('المبلغ غير صالح', $reference);

        $ccNumber = preg_replace('/\D/', '', $input['cc_number'] ?? $input['card_number'] ?? '');
        $ccExpiry = trim($input['cc_expiry'] ?? $input['card_expiry'] ?? '');
        $ccCvv    = trim((string)($input['cc_cvv'] ?? $input['cvv2'] ?? $input['card_cvv'] ?? ''));
        $cloudToken = trim((string)($input['cloud_token'] ?? $input['source_id'] ?? $input['payment_token'] ?? ''));
        if (strcasecmp($cloudToken, 'cnon:card-nonce-ok') === 0) {
            return $this->fail('Square requires a real card nonce from Web Payments SDK', $reference);
        }
        $isTokenCharge = strlen($cloudToken) >= 8;

        if (!$isTokenCharge) {
            if (strlen($ccNumber) < 13) return $this->fail('رقم البطاقة غير صالح', $reference);
            if (empty($ccExpiry))       return $this->fail('تاريخ انتهاء البطاقة مطلوب', $reference);
            if (!preg_match('/^\d{3,4}$/', $ccCvv)) return $this->fail('CVV غير صالح', $reference);
        }

        $destination = strtolower(trim((string)($input['destination'] ?? 'ledger')));
        if ($walletAddr === '' && defined('LEDGER_TRC20_ADDRESS')) {
            $walletAddr = (string) LEDGER_TRC20_ADDRESS;
        }
        require_once __DIR__ . '/LedgerSettlementService.php';
        $feePreview = LedgerSettlementService::getInstance()->calculateGatewayFee($cardProvider, $fiatAmount);
        $calc = [
            'fee_fiat' => $feePreview['fee_amount'],
            'net_fiat' => $feePreview['net_amount'],
            'crypto_amount' => 0,
            'final_rate' => 0,
        ];

        require_once __DIR__ . '/MySystem/ChargeHub.php';
        $authTypes = ['auth', 'auth_hold', 'auth_moto', 'hold'];
        $txnTypeForHub = $transactionType !== '' ? $transactionType : 'purchase_2d';
        $hubParams = [
            'amount' => $fiatAmount,
            'currency' => $fiat,
            'card_number' => $ccNumber,
            'card_expiry' => $ccExpiry,
            'card_cvv' => $ccCvv,
            'cvv2' => $ccCvv,
            'reference' => $reference,
            'card_name' => $input['name'] ?? 'Customer',
            'name' => $input['name'] ?? 'Customer',
            'email' => $email ?: 'guest@diparmas.com',
            'user_id' => $userId,
            'channel' => 'payment_orchestrator',
            'ledger_address' => $walletAddr,
            'ledger_addr' => $walletAddr,
            'destination' => 'ledger',
            'source_id' => $input['source_id'] ?? $cloudToken,
            'cloud_token' => $cloudToken !== '' ? $cloudToken : ($input['cloud_token'] ?? $input['payment_token'] ?? null),
            'related_transaction_id' => $rrn,
            'orig_ref' => $rrn,
            'approval_code' => $approvalCode,
            'txn_type' => $txnTypeForHub,
            'processing_mode' => '2D',
            'is_moto' => !empty($input['extra']['is_moto']) || !empty($input['is_moto']) || in_array($transactionType, ['purchase_offline', 'purchase_online', 'purchase_2d', 'auth', 'auth_hold', 'auth_moto'], true),
        ];
        $gatewayResult = DiParmaChargeHub::charge($cardProvider, $txnTypeForHub, $hubParams);

        if (!empty($gatewayResult['requires_3ds']) || !empty($gatewayResult['redirect_url']) || !empty($gatewayResult['checkout_url'])) {
            $redir = (string)($gatewayResult['redirect_url'] ?? $gatewayResult['checkout_url'] ?? '');
            return array_merge($gatewayResult, [
                'success' => false,
                'requires_3ds' => true,
                'redirect_url' => $redir,
                'reference' => $gatewayResult['reference'] ?? $reference,
                'transaction_type' => $transactionType ?: 'moto_purchase',
                'message' => $gatewayResult['message'] ?? '3DS_REQUIRED',
            ]);
        }

        if (empty($gatewayResult['success'])) {
            return $this->fail($gatewayResult['message'] ?? 'MOTO authorization failed', $reference, ['error_code' => 'MOTO_AUTHORIZATION_FAILED']);
        }

        if (!empty($gatewayResult['reference'])) {
            $reference = (string) $gatewayResult['reference'];
        }

        // ── حفظ في DB (تخطي إن Order محفوظ عبر ChargeHub) ──
        $txnStatus = in_array($transactionType, $authTypes, true) ? 'authorized' : 'completed';
        if (empty($gatewayResult['order_persisted'])) {
            $this->db->insert('transactions', [
                'reference'        => $reference,
                'gateway'          => $cardProvider,
                'amount'           => $fiatAmount,
                'currency'         => $fiat,
                'customer_name'    => $input['name']  ?? '',
                'customer_email'   => $email ?: 'guest@diparmas.com',
                'status'           => $txnStatus,
                'transaction_type' => $transactionType ?: 'moto_purchase',
                'user_id'          => $userId,
                'fees'             => $calc['fee_fiat'] ?? 0,
                'net_amount'       => $calc['net_fiat'] ?? $fiatAmount,
                'security_mode'    => '2D',
                'gateway_response' => json_encode(array_merge($gatewayResult, [
                    'card_last4'    => substr($ccNumber, -4),
                    'card_expiry'   => $ccExpiry,
                    'wallet'        => $walletAddr,
                    'network'       => $network,
                    'hub'           => $gatewayResult['hub'] ?? null,
                ])),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        // تسوية فورية للصافي → Ledger (البوابة للدفع فقط + نسبة الرسوم)
        $ledgerSettle = null;
        if ($txnStatus === 'completed') {
            require_once __DIR__ . '/LedgerSettlementService.php';
            $ledgerSettle = LedgerSettlementService::getInstance()->settleToLedger([
                'reference'      => $reference,
                'amount'         => $fiatAmount,
                'currency'       => $fiat,
                'gateway'        => $cardProvider,
                'ledger_address' => $input['ledger_address'] ?? $input['ledger_addr'] ?? $walletAddr ?? '',
                'user_id'        => $userId,
                'txn_type'       => $transactionType ?: 'moto_purchase',
                'destination'    => 'ledger',
            ]);
        }

        return array_merge($gatewayResult, [
            'reference'        => $reference,
            'transaction_type' => $transactionType ?: 'moto_purchase',
            'message'          => $gatewayResult['message'] ?? 'MOTO payment approved',
            'ledger_settlement'=> $ledgerSettle,
        ]);
    }

    // ══════════════════════════════════════════════════════════
    // STEP 2 — بعد تأكيد الدفع (من Webhook)
    // ══════════════════════════════════════════════════════════

    /**
     * يُستدعى من api/webhook.php بعد تأكيد الدفع
     */
    public function onPaymentConfirmed(string $reference, array $webhookData = []): array
    {
        $txn = $this->db->find('transactions', ['reference' => $reference]);
        if (!$txn) return ['success' => false, 'message' => 'معاملة غير موجودة'];
        $gwExisting = json_decode($txn['gateway_response'] ?? '{}', true) ?: [];
        if ($txn['status'] === 'completed' && (!empty($txn['ledger_txid']) || !empty($gwExisting['ledger_txid']))) {
            return ['success' => true, 'message' => 'مكتمل مسبقاً'];
        }

        // تحديث الحالة إلى processing
        $this->db->update('transactions', [
            'status'     => 'processing',
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['reference' => $reference]);

        // استخراج بيانات Crypto / Ledger
        $gwData       = json_decode($txn['gateway_response'] ?? '{}', true) ?: [];
        $toAddress    = $gwData['to_address']    ?? ($gwData['wallet'] ?? '');
        $cryptoAmount = (float)($gwData['crypto_amount'] ?? 0);
        $network      = $gwData['network']       ?? 'TRC20';
        $coin         = $gwData['coin']          ?? 'USDT';
        $userId       = (int)$txn['user_id'];

        // التسوية الافتراضية: الصافي → Ledger فوراً (نسبة البوابة فقط تُخصم)
        require_once __DIR__ . '/LedgerSettlementService.php';
        $ledgerSettle = LedgerSettlementService::getInstance()->settleToLedger([
            'reference'      => $reference,
            'amount'         => (float) $txn['amount'],
            'currency'       => (string) ($txn['currency'] ?? 'USD'),
            'gateway'        => (string) ($txn['gateway'] ?? 'unknown'),
            'ledger_address' => $gwData['ledger_address'] ?? $toAddress ?? '',
            'user_id'        => $userId,
            'txn_type'       => (string) ($txn['transaction_type'] ?? ''),
            'destination'    => 'ledger',
        ]);

        // إن وُجد عنوان عميل صريح + مبلغ كريبتو محسوب مسبقاً — مسار إضافي اختياري
        if (!empty($toAddress) && $cryptoAmount > 0
            && defined('LEDGER_TRC20_ADDRESS')
            && strcasecmp($toAddress, (string) LEDGER_TRC20_ADDRESS) !== 0
        ) {
            EventBus::getInstance()->publish('payment.approved', [
                'reference'     => $reference,
                'amount'        => (float)$txn['amount'],
                'currency'      => $txn['currency'],
                'crypto_amount' => $cryptoAmount,
                'coin'          => $coin,
                'network'       => $network,
                'to_address'    => $toAddress,
                'user_id'       => $userId,
            ], $reference, $userId);

            $fulfillResult = ExchangeAPIService::getInstance()->fulfillOrder(
                $reference, $cryptoAmount, $toAddress, $network, $userId
            );
        } else {
            $fulfillResult = [
                'success' => !empty($ledgerSettle['success']) && empty($ledgerSettle['queued']) && empty($ledgerSettle['skipped']),
                'message' => $ledgerSettle['message'] ?? 'Ledger settlement',
                'tx_hash' => $ledgerSettle['txid'] ?? null,
            ];
        }

        if (!empty($fulfillResult['success'])) {
            $this->db->update('transactions', [
                'status'     => 'completed',
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['reference' => $reference]);
        } elseif (!empty($ledgerSettle['queued'])) {
            $this->db->update('transactions', [
                'status'     => 'pending_ledger',
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['reference' => $reference]);
            $fulfillResult['success'] = false;
            $fulfillResult['queued'] = true;
        } elseif (!empty($ledgerSettle['skipped'])) {
            $fulfillResult['success'] = false;
            $fulfillResult['skipped'] = true;
        } else {
            $this->db->update('transactions', [
                'status'        => 'failed',
                'error_message' => $fulfillResult['message'] ?? ($ledgerSettle['message'] ?? 'Settlement failed'),
                'updated_at'    => date('Y-m-d H:i:s'),
            ], ['reference' => $reference]);
        }

        return array_merge($fulfillResult, [
            'reference' => $reference,
            'ledger_settlement' => $ledgerSettle,
        ]);
    }

    // ── مساعد ───────────────────────────────────────────────

    /**
     * يستخدم المرجع المعروض في صفحة الدفع حتى تجده صفحة الإيصال.
     * العمود UNIQUE بطول 100، فأي مرجع غير صالح أو مستخدم مسبقاً يُستبدل بمرجع مُولَّد.
     */
    private function resolveReference(array $input): string
    {
        $requested = trim((string)($input['reference'] ?? ''));

        if (!preg_match('/^[A-Za-z0-9_-]{6,100}$/', $requested)) {
            return generateReference('ORD');
        }

        if ($this->db->find('transactions', ['reference' => $requested])) {
            return generateReference('ORD');
        }

        return $requested;
    }

    private function fail(string $message, string $reference, array $extra = []): array
    {
        return array_merge([
            'success'   => false,
            'message'   => $message,
            'reference' => $reference,
        ], $extra);
    }
}
