<?php
/**
 * ============================================================
 * DI PARMA | HoldCaptureService
 * طبقة خدمة موحدة — تستخدم GatewayAdapterFactory
 * تدعم: HOLD / CAPTURE / CANCEL / 2D DirectCharge
 * عبر أي بوابة: Stripe / Checkout.com / MyFatoorah
 * ============================================================
 */

require_once __DIR__ . '/Adapters/GatewayAdapterFactory.php';

class HoldCaptureService
{
    private static ?self $instance = null;
    private Database $db;
    private string $logFile;

    private function __construct()
    {
        $this->db      = db();
        $this->logFile = defined('LOGS_PATH')
            ? LOGS_PATH . '/hold_capture.log'
            : __DIR__ . '/../logs/hold_capture.log';
        if (!is_dir(dirname($this->logFile))) @mkdir(dirname($this->logFile), 0755, true);
        $this->ensureTable();
    }

    public static function getInstance(): self
    {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    // ══════════════════════════════════════════════════════════
    // [1] HOLD — حجز المبلغ بدون خصم
    // ══════════════════════════════════════════════════════════
    public function createHold(
        float  $amount,
        string $currency,
        string $reference,
        int    $userId,
        array  $meta = [],
        string $gateway = ''
    ): array {
        // بناء الـ Payload الموحد
        $payload = GatewayAdapterFactory::normalizePayload(array_merge($meta, [
            'amount'          => $amount,
            'currency'        => $currency,
            'reference'       => $reference,
            'processing_mode' => $meta['security_mode'] ?? '3D',
        ]));

        // تنفيذ الحجز عبر Factory
        $result = GatewayAdapterFactory::process($payload, 'hold', $gateway ?: null);

        if ($result['success'] || $result['status'] === 'requires_3ds') {
            // حفظ الحجز في DB
            $holdId = $this->db->insert('holds', [
                'reference'          => $reference,
                'user_id'            => $userId,
                'payment_intent_id'  => $result['transaction_id'] ?? '',
                'amount'             => $amount,
                'currency'           => strtoupper($currency),
                'status'             => $result['status'] === 'authorized' ? 'authorized' : 'pending',
                'gateway'            => $gateway ?: (getenv('CARD_PROVIDER') ?: 'nuvei'),
                'expires_at'         => date('Y-m-d H:i:s', strtotime('+7 days')),
                'meta'               => json_encode($meta),
                'created_at'         => date('Y-m-d H:i:s'),
            ]);

            $result['hold_id']   = $holdId;
            $result['public_key'] = getenv('STRIPE_PUBLIC_KEY') ?: '';
        }

        $this->log("createHold: ref=$reference status={$result['status']} amount=$amount $currency");
        return $result;
    }

    // ══════════════════════════════════════════════════════════
    // [2] تأكيد HOLD بعد 3DS (إذا كان مطلوباً)
    // ══════════════════════════════════════════════════════════
    public function confirmHold(string $paymentIntentId): array
    {
        // نتحقق من Stripe مباشرة للحصول على الحالة
        $secretKey = getenv('STRIPE_SECRET_KEY') ?: '';
        if (empty($secretKey)) {
            return ['success' => false, 'message' => 'STRIPE_SECRET_KEY غير مضبوط'];
        }

        $ch = curl_init("https://api.stripe.com/v1/payment_intents/$paymentIntentId");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $secretKey . ':',
            CURLOPT_TIMEOUT        => 10,
        ]);
        $res  = curl_exec($ch);
        curl_close($ch);
        $pi   = json_decode($res ?: '{}', true);
        $status = $pi['status'] ?? '';

        if ($status === 'requires_capture') {
            $this->db->execute(
                "UPDATE dp_holds SET status='authorized', authorized_at=? WHERE payment_intent_id=?",
                [date('Y-m-d H:i:s'), $paymentIntentId]
            );
            $this->log("confirmHold: PI=$paymentIntentId authorized");
            return [
                'success'        => true,
                'status'         => 'authorized',
                'transaction_id' => $paymentIntentId,
                'amount'         => ($pi['amount'] ?? 0) / 100,
                'currency'       => strtoupper($pi['currency'] ?? ''),
                'reference'      => $pi['metadata']['reference'] ?? '',
                'message'        => '✅ تم تأكيد الحجز',
                'requires_3ds'   => false,
                'client_secret'  => '',
                'redirect_url'   => '',
                'decline_code'   => '',
            ];
        }

        return [
            'success' => false,
            'status'  => $status,
            'message' => "الحجز لم يكتمل — الحالة: $status",
        ];
    }

    // ══════════════════════════════════════════════════════════
    // [3] CAPTURE — تحصيل المبلغ المحجوز
    // ══════════════════════════════════════════════════════════
    public function capture(string $paymentIntentId, ?float $partialAmount = null): array
    {
        $hold = $this->getHoldByPI($paymentIntentId);
        if (!$hold) {
            return ['success' => false, 'message' => 'الحجز غير موجود في النظام'];
        }
        if ($hold['status'] !== 'authorized') {
            return ['success' => false, 'message' => "لا يمكن التحصيل — حالة الحجز: {$hold['status']}"];
        }

        $gateway = $hold['gateway'] ?? 'stripe';
        $payload = GatewayAdapterFactory::normalizePayload([
            'transaction_id' => $paymentIntentId,
            'partial_amount' => $partialAmount,
        ]);

        $result = GatewayAdapterFactory::process($payload, 'capture', $gateway);

        if ($result['success']) {
            $captured = $result['amount'] ?? ($hold['amount']);
            $this->db->execute(
                "UPDATE dp_holds SET status='captured', captured_amount=?, captured_at=? WHERE payment_intent_id=?",
                [$captured, date('Y-m-d H:i:s'), $paymentIntentId]
            );
            $result['reference'] = $hold['reference'];
            $this->log("capture: PI=$paymentIntentId amount=$captured gateway=$gateway");
        }

        return $result;
    }

    // ══════════════════════════════════════════════════════════
    // [4] CANCEL — إلغاء الحجز
    // ══════════════════════════════════════════════════════════
    public function cancel(string $paymentIntentId, string $reason = 'requested_by_customer'): array
    {
        $hold = $this->getHoldByPI($paymentIntentId);
        if (!$hold) {
            return ['success' => false, 'message' => 'الحجز غير موجود'];
        }

        $gateway = $hold['gateway'] ?? 'stripe';
        $payload = GatewayAdapterFactory::normalizePayload([
            'transaction_id' => $paymentIntentId,
            'reason'         => $reason,
        ]);

        $result = GatewayAdapterFactory::process($payload, 'cancel', $gateway);

        if ($result['success']) {
            $this->db->execute(
                "UPDATE dp_holds SET status='cancelled', cancelled_at=? WHERE payment_intent_id=?",
                [date('Y-m-d H:i:s'), $paymentIntentId]
            );
            $result['reference'] = $hold['reference'];
            $this->log("cancel: PI=$paymentIntentId gateway=$gateway");
        }

        return $result;
    }

    // ══════════════════════════════════════════════════════════
    // [5] 2D Direct Charge — شراء مباشر بدون OTP
    // ══════════════════════════════════════════════════════════
    /**
     * يُستخدم عند اختيار وضع 2D في checkout
     * يعمل مع أي بوابة مدعومة عبر Factory
     */
    public function directCharge2D(array $context, string $gateway = ''): array
    {
        // بناء الـ Payload الموحد مع إجبار الوضع على 2D
        $payload = GatewayAdapterFactory::normalizePayload(array_merge($context, [
            'processing_mode' => '2D',
        ]));

        if ($payload['amount'] <= 0) {
            return ['success' => false, 'message' => 'المبلغ غير صالح'];
        }
        if (strlen($payload['card_number']) < 13) {
            return ['success' => false, 'message' => 'رقم البطاقة غير صالح'];
        }
        if (!preg_match('/^\d{3,4}$/', $payload['cvv2'])) {
            return ['success' => false, 'message' => 'CVV غير صالح'];
        }

        // اختيار البوابة — Stripe أولاً لأنها الأكثر دعماً لـ 2D
        $gw     = $gateway ?: (getenv('CARD_PROVIDER') ?: 'nuvei');
        $result = GatewayAdapterFactory::process($payload, 'charge', $gw);

        $this->log("directCharge2D: ref={$payload['reference']} status={$result['status']} gateway=$gw");
        return $result;
    }

    // ══════════════════════════════════════════════════════════
    // [6] Unified Charge — 2D أو 3D حسب الـ payload
    // ══════════════════════════════════════════════════════════
    /**
     * نقطة دخول موحدة — تقرأ processing_mode وتوجّه تلقائياً
     */
    public function charge(array $context, string $gateway = ''): array
    {
        $payload = GatewayAdapterFactory::normalizePayload($context);
        $gw      = $gateway ?: (getenv('CARD_PROVIDER') ?: 'nuvei');
        $result  = GatewayAdapterFactory::process($payload, 'charge', $gw);
        $this->log("charge[{$payload['processing_mode']}]: ref={$payload['reference']} status={$result['status']}");
        return $result;
    }

    // ══════════════════════════════════════════════════════════
    // إدارة الحجوزات
    // ══════════════════════════════════════════════════════════
    public function getUserHolds(int $userId): array
    {
        return $this->db->query(
            "SELECT * FROM dp_holds WHERE user_id=? ORDER BY created_at DESC",
            [$userId]
        );
    }

    public function getHoldByReference(string $reference): ?array
    {
        return $this->db->find('holds', ['reference' => $reference]);
    }

    public function getHoldByPI(string $paymentIntentId): ?array
    {
        return $this->db->find('holds', ['payment_intent_id' => $paymentIntentId]);
    }

    public function getAllHolds(int $limit = 50): array
    {
        return $this->db->query(
            "SELECT h.*, u.username FROM dp_holds h
             LEFT JOIN dp_users u ON u.id = h.user_id
             ORDER BY h.created_at DESC LIMIT ?",
            [$limit]
        );
    }

    // ── DB Schema ─────────────────────────────────────────────
    private function ensureTable(): void
    {
        $this->db->execute("CREATE TABLE IF NOT EXISTS `dp_holds` (
            `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `reference`         VARCHAR(100) NOT NULL UNIQUE,
            `user_id`           INT UNSIGNED NOT NULL,
            `payment_intent_id` VARCHAR(100) NOT NULL UNIQUE,
            `amount`            DECIMAL(12,2) NOT NULL,
            `currency`          VARCHAR(10)  NOT NULL DEFAULT 'USD',
            `status`            VARCHAR(30)  NOT NULL DEFAULT 'pending'
                                COMMENT 'pending|authorized|captured|cancelled|expired',
            `gateway`           VARCHAR(30)  NOT NULL DEFAULT 'stripe',
            `captured_amount`   DECIMAL(12,2) DEFAULT NULL,
            `meta`              TEXT         DEFAULT NULL,
            `expires_at`        DATETIME     DEFAULT NULL,
            `authorized_at`     DATETIME     DEFAULT NULL,
            `captured_at`       DATETIME     DEFAULT NULL,
            `cancelled_at`      DATETIME     DEFAULT NULL,
            `created_at`        DATETIME     NOT NULL,
            INDEX `idx_user`    (`user_id`),
            INDEX `idx_status`  (`status`),
            INDEX `idx_pi`      (`payment_intent_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    private function log(string $msg): void
    {
        @file_put_contents($this->logFile, '[' . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
    }
}
