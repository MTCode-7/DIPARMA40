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
        $reference    = generateReference('ORD');

        // ══ مسار خاص: بروتوكول 201.3 — MOTO ══════════════════
        if ($protocol === '201.3' || $paymentType === 'MOTO') {
            return $this->initiateMOTO($input, $userId, $reference);
        }

        // ── [1] التحقق الأساسي ───────────────────────────────
        if ($fiatAmount < 10)    return $this->fail('الحد الأدنى 10 ' . $fiat, $reference);
        if (empty($walletAddr))  return $this->fail('عنوان المحفظة مطلوب', $reference);
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
        $cardProvider = strtolower($input['card_provider']  ?? getenv('CARD_PROVIDER') ?: 'nuvei');

        // ── تحقق أساسي ───────────────────────────────────────
        if ($fiatAmount < 1)   return $this->fail('المبلغ غير صالح', $reference);
        if (empty($walletAddr)) return $this->fail('عنوان المحفظة مطلوب', $reference);

        $ccNumber = preg_replace('/\D/', '', $input['cc_number'] ?? '');
        $ccExpiry = trim($input['cc_expiry'] ?? '');
        $ccCvv    = trim((string)($input['cc_cvv'] ?? $input['cvv2'] ?? ''));

        if (strlen($ccNumber) < 13) return $this->fail('رقم البطاقة غير صالح', $reference);
        if (empty($ccExpiry))       return $this->fail('تاريخ انتهاء البطاقة مطلوب', $reference);
        if (!preg_match('/^\d{3,4}$/', $ccCvv)) return $this->fail('CVV غير صالح', $reference);

        // ── حساب السعر ───────────────────────────────────────
        try {
            $calc = ExchangeRateService::getInstance()->calculate($fiatAmount, $fiat, $coin);
        } catch (RuntimeException $e) {
            return $this->fail('فشل جلب سعر الصرف: ' . $e->getMessage(), $reference);
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
            'processing_mode' => '2D',  // 201.3 = 2D دائماً
            'reference'       => $reference,
            'name'            => $input['name']  ?? 'Customer',
            'email'           => $email ?: 'guest@diparmas.com',
            'approval_code'   => $input['approval_code'] ?? '',
        ]);

        return $this->fail(
            'Offline payments are disabled. A real gateway authorization is required.',
            $reference,
            ['error_code' => 'REAL_GATEWAY_REQUIRED']
        );

        // ── حفظ في DB كـ pending ──────────────────────────────
        $this->db->insert('transactions', [
            'reference'        => $reference,
            'gateway'          => 'offline',
            'protocol'         => '201.3',
            'amount'           => $fiatAmount,
            'currency'         => $fiat,
            'customer_name'    => $input['name']  ?? '',
            'customer_email'   => $email ?: 'guest@diparmas.com',
            'status'           => 'pending',
            'transaction_type' => "Offline Sale — {$calc['crypto_amount']} {$coin}/{$network}",
            'user_id'          => $userId,
            'fees'             => $calc['fee_fiat'],
            'net_amount'       => $calc['net_fiat'],
            'security_mode'    => '2D',
            'gateway_response' => json_encode([
                'protocol'      => '201.3',
                'payment_type'  => 'MOTO_OFFLINE',
                'coin'          => $coin,
                'network'       => $network,
                'crypto_amount' => $calc['crypto_amount'],
                'to_address'    => $walletAddr,
                'card_last4'    => substr($ccNumber, -4),
                'card_expiry'   => $ccExpiry,
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if (!$gwResult['success']) {
            return $this->fail(
                $gwResult['message'] ?? 'فشل الدفع عبر ' . $cardProvider,
                $reference,
                ['error_code' => $gwResult['error_code'] ?? '', 'decline_code' => $gwResult['decline_code'] ?? '']
            );
        }

        // ── 100% للإدارة — الإدارة تحول للعميل يدوياً ─────────
        $totalCrypto = $calc['crypto_amount'];
        $adminUserId = 1; // ID الإدارة
        $unlockAt    = date('Y-m-d H:i:s', strtotime('+24 hours'));

        // إضافة 100% لمحفظة الإدارة الداخلية
        $existingAdmin = $this->db->query(
            "SELECT id FROM user_crypto_wallets WHERE user_id=? AND coin=? AND network=?",
            [$adminUserId, $coin, $network]
        );
        if (!empty($existingAdmin)) {
            $this->db->execute(
                "UPDATE user_crypto_wallets SET balance=balance+? WHERE user_id=? AND coin=? AND network=?",
                [$totalCrypto, $adminUserId, $coin, $network]
            );
        } else {
            $this->db->execute(
                "INSERT INTO user_crypto_wallets (user_id,coin,network,balance,locked,status) VALUES (?,?,?,?,0,'active')",
                [$adminUserId, $coin, $network, $totalCrypto]
            );
        }

        // سجل الحركة
        try {
            $this->db->execute(
                "INSERT INTO wallet_transactions (reference,user_id,type,wallet_type,coin,network,amount,fee,net_amount,status,note,created_at)
                 VALUES (?,?,'deposit','crypto',?,?,?,0,?,'completed',?,NOW())",
                [$reference.'_ADMIN', $adminUserId, $coin, $network,
                 $totalCrypto, $totalCrypto,
                 "100% Offline Sale — من العميل #{$userId} | المرجع: {$reference}"]
            );
        } catch (\Exception $e) {}

        // تحديث حالة المعاملة
        $this->db->update('transactions', [
            'status'     => 'pending',
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['reference' => $reference]);

        return [
            'success'      => true,
            'reference'    => $reference,
            'protocol'     => '201.3',
            'payment_type' => 'MOTO_OFFLINE',
            'status'       => 'pending',
            'message'      => '✅ تم استلام الطلب — سيتم التحويل من قِبل الإدارة',
            'order'        => [
                'fiat_amount'   => $fiatAmount,
                'fiat_currency' => $fiat,
                'total_crypto'  => $totalCrypto,
                'coin'          => $coin,
                'network'       => $network,
            ],
        ];
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

    private function fail(string $message, string $reference, array $extra = []): array
    {
        return array_merge([
            'success'   => false,
            'message'   => $message,
            'reference' => $reference,
        ], $extra);
    }
}
