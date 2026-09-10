<?php
/**
 * ============================================================
 * DI PARMA | PaymentOrchestrator
 * التدفق الكامل: البطاقة → فيات → USDT → محفظة العميل
 * ============================================================
 *
 * المسار الكامل:
 *  1. العميل يُدخل بيانات البطاقة + عنوان المحفظة
 *  2. RiskEngine يفحص العملية
 *  3. KYCService يتحقق من الحدود
 *  4. CardPaymentService ينشئ Payment Intent
 *  5. العميل يدفع عبر Stripe/Checkout
 *  6. Webhook يصل → payment.approved
 *  7. EventBus ينشر الحدث
 *  8. ExchangeAPIService يرسل USDT
 *  9. BlockchainMonitor يتابع التأكيد
 * 10. إشعار للعميل
 * ============================================================
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
        $cardProvider = strtolower($input['card_provider']      ?? getenv('CARD_PROVIDER') ?: 'nuvei');
        $protocol     = trim($input['protocol']                 ?? '');
        $paymentType  = strtoupper(trim($input['payment_type']  ?? ''));
        $reference    = $this->resolveReference($input);

        // ══ مسار خاص: بروتوكول 201.3 — MOTO ══════════════════
        if ($protocol === '201.3' || in_array($paymentType, ['MOTO', 'ONLINE_MOTO'], true)) {
            return $this->initiateMOTO($input, $userId, $reference);
        }

        // ── [1] التحقق الأساسي ───────────────────────────────
        if ($fiatAmount < 10)    return $this->fail('الحد الأدنى 10 ' . $fiat, $reference);
        $transactionType = strtolower(trim($input['transaction_type'] ?? $input['txn_type'] ?? ''));
        $destination = strtolower(trim($input['destination'] ?? ''));
        $requiresWallet = $transactionType === 'crypto_purchase'
            || in_array($destination, ['crypto', 'wallet', 'ledger'], true);
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
                'rate'          => $calc['final_rate'],
                'risk_score'    => $risk['score'],
                'risk_decision' => $risk['decision'],
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // ── [6] إنشاء Payment Intent ─────────────────────────
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

        if (!$paymentResult['success']) {
            $this->db->update('transactions', ['status' => 'failed'], ['reference' => $reference]);
            return $this->fail($paymentResult['message'], $reference);
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
        $processingGateways = ['nuvei', 'stripe', 'checkout', 'checkout.com', 'paytabs',
                                'authorizenet', 'authnet', 'authorize_net', 'myfatoorah', 'diparma',
                                'paypal', 'braintree'];
        $cardProvider = in_array($requestedProvider, $processingGateways, true)
            ? $requestedProvider
            : $envProvider;

        $transactionType = strtolower(trim($input['transaction_type'] ?? $input['txn_type'] ?? $input['payment_type'] ?? ''));
        $rrn = trim((string)($input['rrn'] ?? $input['orig_ref'] ?? ''));
        $approvalCode = trim((string)($input['approval_code'] ?? ''));

        // ── تحقق أساسي ───────────────────────────────────────
        if ($fiatAmount < 1)   return $this->fail('المبلغ غير صالح', $reference);

        if (in_array($transactionType, ['purchase_advice', 'capture', 'auth_capture'], true)
            && $rrn !== '' && $approvalCode !== '') {
            // تأكد من تحميل gateway_service إذا لم تكن محمّلة بعد
            if (!function_exists('gateway_service')) {
                require_once __DIR__ . '/../includes/gateways.php';
            }
            $settlement = gateway_service()->settlePreAuthorization($cardProvider, [
                'order_ref' => $reference,
                'amount' => $fiatAmount,
                'currency' => $fiat,
                'rrn' => $rrn,
                'approval_code' => $approvalCode,
                'customer_name' => $input['name'] ?? 'Customer',
            ]);

            return !empty($settlement['success'])
                ? array_merge($settlement, ['reference' => $reference, 'transaction_type' => 'purchase_advice'])
                : $this->fail($settlement['message'] ?? 'Authorization settlement failed', $reference, ['error_code' => 'ADVICE_SETTLEMENT_FAILED']);
        }

        $ccNumber = preg_replace('/\D/', '', $input['cc_number'] ?? '');
        $ccExpiry = trim($input['cc_expiry'] ?? '');
        $ccCvv    = trim((string)($input['cc_cvv'] ?? $input['cvv2'] ?? ''));

        if (strlen($ccNumber) < 13) return $this->fail('رقم البطاقة غير صالح', $reference);
        if (empty($ccExpiry))       return $this->fail('تاريخ انتهاء البطاقة مطلوب', $reference);
        if (!preg_match('/^\d{3,4}$/', $ccCvv)) return $this->fail('CVV غير صالح', $reference);

        $destination = strtolower(trim((string)($input['destination'] ?? 'gateway')));
        $needsCrypto = in_array($destination, ['crypto', 'wallet', 'ledger', 'ledger_trx', 'tron_w', 'erc20_w'], true)
            || $transactionType === 'crypto_purchase';
        $calc = ['fee_fiat' => 0, 'net_fiat' => $fiatAmount, 'crypto_amount' => 0, 'final_rate' => 0];
        if ($needsCrypto) {
            try {
                $calc = ExchangeRateService::getInstance()->calculate($fiatAmount, $fiat, $coin);
            } catch (RuntimeException $e) {
                return $this->fail('فشل جلب سعر الصرف: ' . $e->getMessage(), $reference);
            }
        }

        // ── تحميل Adapters ────────────────────────────────────
        if (!class_exists('GatewayAdapterFactory')) {
            require_once __DIR__ . '/Adapters/GatewayAdapterInterface.php';
            require_once __DIR__ . '/Adapters/GatewayErrorMapper.php';
            require_once __DIR__ . '/Adapters/GatewayLogger.php';
            require_once __DIR__ . '/Adapters/StripeAdapter.php';
            require_once __DIR__ . '/Adapters/CheckoutAdapter.php';
            require_once __DIR__ . '/Adapters/MyFatoorahAdapter.php';
            require_once __DIR__ . '/Adapters/PayTabsAdapter.php';
            require_once __DIR__ . '/Adapters/AuthorizeNetAdapter.php';
            require_once __DIR__ . '/Adapters/GatewayAdapterFactory.php';
        }

        // ── تنفيذ الدفع 2D عبر Factory ───────────────────────
        $payload = GatewayAdapterFactory::normalizePayload([
            'amount'          => $fiatAmount,
            'currency'        => $fiat,
            'card_number'     => $ccNumber,
            'card_expiry'     => $ccExpiry,
            'cvv2'            => $ccCvv,
            'processing_mode' => '2D',
            'reference'       => $reference,
            'name'            => $input['name']  ?? 'Customer',
            'email'           => $email ?: 'guest@diparmas.com',
            'approval_code'   => $input['approval_code'] ?? '',
        ]);
        $payload['transaction_label'] = $input['extra']['transaction_label'] ?? $input['transaction_label'] ?? $transactionType;
        $payload['is_moto'] = !empty($input['extra']['is_moto']) || !empty($input['is_moto']) || in_array($transactionType, ['purchase_offline', 'purchase_online', 'purchase_2d', 'auth_moto'], true);
        $payload['is_offline'] = $transactionType === 'purchase_offline' || !empty($input['extra']['is_offline']);

        $authTypes = ['auth', 'auth_hold', 'auth_moto', 'hold'];
        $captureTypes = ['auth_complete', 'auth_capture', 'capture'];
        if (in_array($transactionType, $captureTypes, true) && $rrn !== '') {
            $gatewayResult = GatewayAdapterFactory::process(array_merge($payload, [
                'transaction_id' => $rrn,
                'partial_amount' => $fiatAmount,
            ]), 'capture', $cardProvider);
        } elseif (in_array($transactionType, $authTypes, true)) {
            $gatewayResult = GatewayAdapterFactory::process($payload, 'hold', $cardProvider);
        } else {
            $gatewayResult = GatewayAdapterFactory::process($payload, 'charge', $cardProvider);
        }

        if (empty($gatewayResult['success'])) {
            return $this->fail($gatewayResult['message'] ?? 'MOTO authorization failed', $reference, ['error_code' => 'MOTO_AUTHORIZATION_FAILED']);
        }

        // ── حفظ في DB ─────────────────────────────────────────
        $txnStatus = in_array($transactionType, $authTypes, true) ? 'authorized' : 'completed';
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
            ])),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return array_merge($gatewayResult, [
            'reference'        => $reference,
            'transaction_type' => $transactionType ?: 'moto_purchase',
            'message'          => $gatewayResult['message'] ?? 'MOTO payment approved',
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
        if ($txn['status'] === 'completed') return ['success' => true, 'message' => 'مكتمل مسبقاً'];

        // تحديث الحالة إلى processing
        $this->db->update('transactions', [
            'status'     => 'processing',
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['reference' => $reference]);

        // استخراج بيانات Crypto
        $gwData       = json_decode($txn['gateway_response'] ?? '{}', true);
        $toAddress    = $gwData['to_address']    ?? '';
        $cryptoAmount = (float)($gwData['crypto_amount'] ?? 0);
        $network      = $gwData['network']       ?? 'TRC20';
        $coin         = $gwData['coin']          ?? 'USDT';
        $userId       = (int)$txn['user_id'];

        if (empty($toAddress) || $cryptoAmount <= 0) {
            return ['success' => false, 'message' => 'بيانات Crypto مفقودة في المعاملة'];
        }

        // نشر حدث payment.approved → EventBus يطلق الإرسال تلقائياً
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

        // تنفيذ فوري أيضاً (بالتوازي مع EventBus)
        $fulfillResult = ExchangeAPIService::getInstance()->fulfillOrder(
            $reference, $cryptoAmount, $toAddress, $network, $userId
        );

        if ($fulfillResult['success']) {
            $this->db->update('transactions', [
                'status'     => 'processing',
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['reference' => $reference]);
        } else {
            $this->db->update('transactions', [
                'status'        => 'failed',
                'error_message' => $fulfillResult['message'],
                'updated_at'    => date('Y-m-d H:i:s'),
            ], ['reference' => $reference]);
        }

        return array_merge($fulfillResult, ['reference' => $reference]);
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
