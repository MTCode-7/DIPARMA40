<?php
/**
 * ============================================================
 * DI PARMA | SavedPaymentService
 * حفظ البطاقات والدفع بدون OTP (Recurring / MIT)
 * ============================================================
 */

class SavedPaymentService
{
    private static ?self $instance = null;
    private Database $db;
    private string $logFile;

    private function __construct()
    {
        $this->db      = db();
        $this->logFile = defined('LOGS_PATH') ? LOGS_PATH . '/saved_payments.log' : __DIR__ . '/../logs/saved_payments.log';
        if (!is_dir(dirname($this->logFile))) @mkdir(dirname($this->logFile), 0755, true);
        $this->ensureTables();
    }

    public static function getInstance(): self
    {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    // ══════════════════════════════════════════════════════════
    // [1] STRIPE — SetupIntent + off_session
    // ══════════════════════════════════════════════════════════

    /**
     * الخطوة 1: إنشاء SetupIntent لحفظ البطاقة
     * يُستدعى عند تحميل checkout عندما يريد المستخدم حفظ البطاقة
     */
    public function stripeCreateSetupIntent(int $userId, string $email): array
    {
        $secretKey = getenv('STRIPE_SECRET_KEY');
        if (empty($secretKey)) return ['success' => false, 'message' => 'Stripe غير مضبوط'];

        // إنشاء أو جلب Stripe Customer
        $customerId = $this->getOrCreateStripeCustomer($userId, $email);
        if (!$customerId) return ['success' => false, 'message' => 'فشل إنشاء Stripe Customer'];

        $ch = curl_init('https://api.stripe.com/v1/setup_intents');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'customer'              => $customerId,
                'usage'                 => 'off_session',
                'payment_method_types[]'=> 'card',
            ]),
            CURLOPT_USERPWD  => $secretKey . ':',
            CURLOPT_TIMEOUT  => 15,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($res, true);
        if ($code !== 200 || empty($data['client_secret'])) {
            return ['success' => false, 'message' => $data['error']['message'] ?? 'SetupIntent error'];
        }

        return [
            'success'         => true,
            'setup_intent_id' => $data['id'],
            'client_secret'   => $data['client_secret'],
            'customer_id'     => $customerId,
            'public_key'      => getenv('STRIPE_PUBLIC_KEY'),
        ];
    }

    /**
     * الخطوة 2: حفظ البطاقة بعد تأكيد SetupIntent
     */
    public function stripeSaveCard(int $userId, string $paymentMethodId, string $customerId): array
    {
        $secretKey = getenv('STRIPE_SECRET_KEY');

        // جلب تفاصيل البطاقة
        $ch = curl_init("https://api.stripe.com/v1/payment_methods/$paymentMethodId");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $secretKey . ':',
            CURLOPT_TIMEOUT        => 10,
        ]);
        $res  = curl_exec($ch);
        curl_close($ch);
        $pm = json_decode($res, true);

        $card = $pm['card'] ?? [];
        $last4  = $card['last4']    ?? '****';
        $brand  = $card['brand']    ?? 'card';
        $expM   = $card['exp_month'] ?? 0;
        $expY   = $card['exp_year']  ?? 0;
        $expiry = sprintf('%02d/%02d', $expM, $expY % 100);

        // ربط البطاقة بالعميل إذا لم تكن مربوطة
        $this->stripeAttachToCustomer($paymentMethodId, $customerId);

        // حفظ في DB
        $id = $this->saveCard($userId, 'stripe', $paymentMethodId, $last4, $brand, $expiry, [
            'customer_id' => $customerId,
        ]);

        $this->log("✓ Stripe card saved: user=$userId pm=$paymentMethodId last4=$last4");

        return [
            'success'    => true,
            'card_id'    => $id,
            'last4'      => $last4,
            'brand'      => $brand,
            'expiry'     => $expiry,
        ];
    }

    /**
     * الخطوة 3: دفع بدون OTP باستخدام البطاقة المحفوظة
     */
    public function stripeChargeOffSession(int $userId, int $cardId, float $amount, string $currency, string $reference): array
    {
        $card = $this->getCard($userId, $cardId);
        if (!$card) return ['success' => false, 'message' => 'البطاقة غير موجودة'];

        $secretKey   = getenv('STRIPE_SECRET_KEY');
        $amountCents = (int)($amount * 100);

        $ch = curl_init('https://api.stripe.com/v1/payment_intents');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'amount'                     => $amountCents,
                'currency'                   => strtolower($currency),
                'customer'                   => $card['meta']['customer_id'] ?? '',
                'payment_method'             => $card['token'],
                'off_session'                => 'true',
                'confirm'                    => 'true',
                'metadata[reference]'        => $reference,
                'metadata[user_id]'          => $userId,
            ]),
            CURLOPT_USERPWD  => $secretKey . ':',
            CURLOPT_TIMEOUT  => 20,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data   = json_decode($res, true);
        $status = $data['status'] ?? '';

        if (in_array($status, ['succeeded', 'processing'])) {
            $this->log("✓ Stripe off-session: $reference | $amount $currency | card=$cardId");
            return [
                'success'          => true,
                'payment_intent_id'=> $data['id'],
                'status'           => $status,
                'no_otp'           => true,
            ];
        }

        // يحتاج 3DS — نادر مع off_session لكن ممكن
        if ($status === 'requires_action') {
            return [
                'success'       => false,
                'requires_3ds'  => true,
                'client_secret' => $data['client_secret'],
                'message'       => 'البنك يطلب تحقق إضافي',
            ];
        }

        return ['success' => false, 'message' => $data['error']['message'] ?? "Status: $status"];
    }

    // ══════════════════════════════════════════════════════════
    // [2] MYFATOORAH — RecurringId
    // ══════════════════════════════════════════════════════════

    /**
     * حفظ RecurringId بعد أول معاملة ناجحة
     */
    public function myfatoorahSaveRecurring(int $userId, string $invoiceId): array
    {
        $apiKey  = getenv('MYFAOORAH_API_KEY');
        $env     = getenv('MYFAOORAH_ENVIRONMENT') ?: 'sandbox';
        $baseUrl = $env === 'live' ? 'https://api.myfatoorah.com' : 'https://apitest.myfatoorah.com';

        // جلب تفاصيل الفاتورة للحصول على RecurringId
        $ch = curl_init($baseUrl . '/v2/GetPaymentStatus');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['Key' => $invoiceId, 'KeyType' => 'InvoiceId']),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $res  = curl_exec($ch);
        curl_close($ch);

        $data       = json_decode($res, true);
        $invoiceData= $data['Data'] ?? [];
        $recurringId= $invoiceData['RecurringId'] ?? null;

        if (empty($recurringId)) {
            return ['success' => false, 'message' => 'لا يوجد RecurringId — تأكد من تفعيل Recurring في MyFatoorah'];
        }

        // جلب بيانات البطاقة
        $transactions = $invoiceData['InvoiceTransactions'] ?? [];
        $last4  = '****';
        $brand  = 'card';
        $expiry = '';
        if (!empty($transactions)) {
            $tx    = $transactions[0];
            $last4 = substr($tx['CardNumber'] ?? '****', -4);
            $brand = strtolower($tx['PaymentGateway'] ?? 'card');
        }

        $id = $this->saveCard($userId, 'myfatoorah', $recurringId, $last4, $brand, $expiry, [
            'invoice_id' => $invoiceId,
        ]);

        $this->log("✓ MyFatoorah recurring saved: user=$userId recurringId=$recurringId");

        return ['success' => true, 'card_id' => $id, 'recurring_id' => $recurringId, 'last4' => $last4];
    }

    /**
     * دفع بدون OTP باستخدام RecurringId
     */
    public function myfatoorahChargeRecurring(int $userId, int $cardId, float $amount, string $currency, string $reference): array
    {
        $card = $this->getCard($userId, $cardId);
        if (!$card) return ['success' => false, 'message' => 'البطاقة غير موجودة'];

        $apiKey  = getenv('MYFAOORAH_API_KEY');
        $env     = getenv('MYFAOORAH_ENVIRONMENT') ?: 'sandbox';
        $baseUrl = $env === 'live' ? 'https://api.myfatoorah.com' : 'https://apitest.myfatoorah.com';
        $siteUrl = defined('SITE_URL') ? SITE_URL : 'https://diparmas.com';

        $body = [
            'RecurringId'     => $card['token'],
            'InvoiceValue'    => $amount,
            'CurrencyIso'     => strtoupper($currency),
            'CustomerReference'=> $reference,
            'CallBackUrl'     => $siteUrl . '/api/orchestrator.php?action=confirm&reference=' . $reference,
        ];

        $ch = curl_init($baseUrl . '/v2/ExecuteRecurringPayment');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            CURLOPT_TIMEOUT        => 20,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($res, true);

        if ($code === 200 && ($data['IsSuccess'] ?? false)) {
            $this->log("✓ MyFatoorah recurring charge: $reference | $amount $currency");
            return [
                'success'    => true,
                'invoice_id' => $data['Data']['InvoiceId'] ?? '',
                'no_otp'     => true,
                'status'     => 'paid',
            ];
        }

        return ['success' => false, 'message' => $data['Message'] ?? 'Recurring charge failed'];
    }

    // ══════════════════════════════════════════════════════════
    // [3] إدارة البطاقات المحفوظة
    // ══════════════════════════════════════════════════════════

    public function getUserCards(int $userId): array
    {
        return $this->db->query(
            "SELECT * FROM dp_payment_methods
             WHERE user_id = ? AND status = 'active'
             ORDER BY is_default DESC, created_at DESC",
            [$userId]
        );
    }

    public function getCard(int $userId, int $cardId): ?array
    {
        $row = $this->db->find('payment_methods', ['id' => $cardId, 'user_id' => $userId, 'status' => 'active']);
        if (!$row) return null;
        $row['meta'] = json_decode($row['meta'] ?? '{}', true) ?: [];
        return $row;
    }

    public function deleteCard(int $userId, int $cardId): bool
    {
        $card = $this->getCard($userId, $cardId);
        if (!$card) return false;

        // حذف من Stripe إذا كان Stripe
        if ($card['gateway'] === 'stripe') {
            $this->stripeDetachCard($card['token']);
        }

        $this->db->update('payment_methods', ['status' => 'deleted'], ['id' => $cardId, 'user_id' => $userId]);
        return true;
    }

    public function setDefault(int $userId, int $cardId): bool
    {
        $this->db->execute(
            "UPDATE dp_payment_methods SET is_default=0 WHERE user_id=?",
            [$userId]
        );
        $this->db->update('payment_methods', ['is_default' => 1], ['id' => $cardId, 'user_id' => $userId]);
        return true;
    }

    // ── مساعدات Stripe ───────────────────────────────────────

    private function getOrCreateStripeCustomer(int $userId, string $email): ?string
    {
        // تحقق من وجود customer_id مسبق
        $existing = $this->db->query(
            "SELECT meta FROM dp_payment_methods WHERE user_id=? AND gateway='stripe' AND status='active' LIMIT 1",
            [$userId]
        );
        if (!empty($existing)) {
            $meta = json_decode($existing[0]['meta'] ?? '{}', true);
            if (!empty($meta['customer_id'])) return $meta['customer_id'];
        }

        // إنشاء جديد
        $secretKey = getenv('STRIPE_SECRET_KEY');
        $ch = curl_init('https://api.stripe.com/v1/customers');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'email'             => $email,
                'metadata[user_id]' => $userId,
            ]),
            CURLOPT_USERPWD => $secretKey . ':',
            CURLOPT_TIMEOUT => 10,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($res, true);
        return ($code === 200 && !empty($data['id'])) ? $data['id'] : null;
    }

    private function stripeAttachToCustomer(string $pmId, string $customerId): void
    {
        $secretKey = getenv('STRIPE_SECRET_KEY');
        $ch = curl_init("https://api.stripe.com/v1/payment_methods/$pmId/attach");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['customer' => $customerId]),
            CURLOPT_USERPWD        => $secretKey . ':',
            CURLOPT_TIMEOUT        => 10,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    private function stripeDetachCard(string $pmId): void
    {
        $secretKey = getenv('STRIPE_SECRET_KEY');
        $ch = curl_init("https://api.stripe.com/v1/payment_methods/$pmId/detach");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '',
            CURLOPT_USERPWD        => $secretKey . ':',
            CURLOPT_TIMEOUT        => 10,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    // ── DB ───────────────────────────────────────────────────

    private function saveCard(int $userId, string $gateway, string $token, string $last4, string $brand, string $expiry, array $meta = []): int
    {
        // هل هي الأولى؟
        $count = $this->db->query(
            "SELECT COUNT(*) as c FROM dp_payment_methods WHERE user_id=? AND status='active'",
            [$userId]
        );
        $isFirst = (int)($count[0]['c'] ?? 0) === 0;

        return (int)$this->db->insert('payment_methods', [
            'user_id'    => $userId,
            'gateway'    => $gateway,
            'token'      => $token,
            'card_last4' => $last4,
            'card_brand' => $brand,
            'card_expiry'=> $expiry,
            'is_default' => $isFirst ? 1 : 0,
            'status'     => 'active',
            'meta'       => json_encode($meta),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function ensureTables(): void
    {
        $this->db->execute("CREATE TABLE IF NOT EXISTS `dp_payment_methods` (
            `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id`     INT UNSIGNED NOT NULL,
            `gateway`     VARCHAR(30)  NOT NULL COMMENT 'stripe|myfatoorah',
            `token`       VARCHAR(255) NOT NULL COMMENT 'pm_xxx|RecurringId',
            `card_last4`  VARCHAR(4)   NOT NULL DEFAULT '****',
            `card_brand`  VARCHAR(20)  NOT NULL DEFAULT 'card',
            `card_expiry` VARCHAR(10)  DEFAULT NULL,
            `is_default`  TINYINT(1)   NOT NULL DEFAULT 0,
            `status`      VARCHAR(20)  NOT NULL DEFAULT 'active',
            `meta`        TEXT         DEFAULT NULL COMMENT 'JSON: customer_id etc',
            `created_at`  DATETIME     NOT NULL,
            INDEX `idx_user`    (`user_id`),
            INDEX `idx_gateway` (`gateway`),
            INDEX `idx_status`  (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    private function log(string $msg): void
    {
        @file_put_contents($this->logFile, '[' . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
    }
}
