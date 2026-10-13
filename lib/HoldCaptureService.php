<?php

declare(strict_types=1);

/**
 * ============================================================
 * DI PARMA | HoldCaptureService
 * ============================================================
 * طبقة خدمة موحّدة لعمليات الحجز والتحصيل.
 *
 * العمليات:
 *   createHold     — حجز المبلغ (Authorization)
 *   confirmHold    — تأكيد بعد 3DS
 *   capture        — تحصيل (Partial / Full / Over-Capture)
 *   cancel         — إلغاء الحجز (Void)
 *   charge         — شراء مباشر (2D / 3D / MOTO / OFFLINE)
 *   directCharge2D — شراء 2D مباشر
 *
 * البوابات المدعومة للـ Over-Capture والـ Offline:
 *   Nuvei · Stripe · Square · PayPal
 *
 * حدود Over-Capture (قابلة للتعديل في .env):
 *   OVERCAPTURE_NUVEI_MAX  = 200%
 *   OVERCAPTURE_STRIPE_MAX = 150%
 *   OVERCAPTURE_SQUARE_MAX = 150%
 *   OVERCAPTURE_PAYPAL_MAX = 150%
 * ============================================================
 */

require_once __DIR__ . '/Adapters/GatewayAdapterFactory.php';
require_once __DIR__ . '/MySystem/ChargeHub.php';

class HoldCaptureService
{
    private static ?self $instance = null;
    private Database     $db;
    private string       $logFile;

    // ──────────────────────────────────────────────────────────
    // Singleton
    // ──────────────────────────────────────────────────────────
    private function __construct()
    {
        $this->db      = db();
        $this->logFile = defined('LOGS_PATH')
            ? LOGS_PATH . '/hold_capture.log'
            : __DIR__ . '/../logs/hold_capture.log';

        if (!is_dir(dirname($this->logFile))) {
            @mkdir(dirname($this->logFile), 0755, true);
        }

        $this->ensureTable();
    }

    public static function getInstance(): self
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ──────────────────────────────────────────────────────────
    // قدرات البوابات
    // ──────────────────────────────────────────────────────────

    /**
     * البوابات الموافقة على Over-Capture مسبقاً.
     */
    public static function allowsOvercapture(string $gateway): bool
    {
        return in_array(strtolower(trim($gateway)), [
            'nuvei',
            'stripe',
            'square',
            'square_online',
            'paypal',
            'diparma',
            'diparma_iso',
        ], true);
    }

    /**
     * البوابات الموافقة على Offline Sales مسبقاً.
     */
    public static function allowsOfflineSales(string $gateway): bool
    {
        return in_array(strtolower(trim($gateway)), [
            'nuvei',
            'stripe',
            'square',
            'square_online',
            'paypal',
            'diparma',
            'diparma_iso',
        ], true);
    }

    /**
     * الحد الأقصى للـ Over-Capture كنسبة مئوية من المبلغ المحجوز.
     * القيم قابلة للتعديل من .env
     */
    private function maxOvercapturePercent(string $gateway): float
    {
        $map = [
            'nuvei'  => (float) (getenv('OVERCAPTURE_NUVEI_MAX')  ?: 200),
            'stripe' => (float) (getenv('OVERCAPTURE_STRIPE_MAX') ?: 150),
            'square' => (float) (getenv('OVERCAPTURE_SQUARE_MAX') ?: 150),
            'paypal' => (float) (getenv('OVERCAPTURE_PAYPAL_MAX') ?: 150),
        ];
        return $map[strtolower($gateway)] ?? 150.0;
    }

    /**
     * فحص حدود Over-Capture لبوابة معينة.
     */
    private function checkOvercaptureLimit(string $gateway, float $requested, float $remaining): array
    {
        $maxPercent = $this->maxOvercapturePercent($gateway);
        $maxAmount  = $remaining * ($maxPercent / 100);

        if ($requested > $maxAmount + 0.01) {
            return [
                'allowed' => false,
                'reason'  => sprintf(
                    'تجاوز الحد الأقصى لـ %s: %.2f > %.2f (%.0f%% من %.2f)',
                    $gateway, $requested, $maxAmount, $maxPercent, $remaining
                ),
            ];
        }

        return ['allowed' => true, 'reason' => ''];
    }

    /**
     * فحص إمكانية الـ Offline للعرض في الواجهة.
     */
    public function canUseOffline(string $gateway): array
    {
        if (!self::allowsOfflineSales($gateway)) {
            return ['allowed' => false, 'reason' => "Offline sales غير مدعوم لبوابة {$gateway}"];
        }
        if (getenv('OFFLINE_ENABLED') === '0' || getenv('OFFLINE_ENABLED') === 'false') {
            return ['allowed' => false, 'reason' => 'Offline sales معطّل'];
        }
        return ['allowed' => true, 'reason' => ''];
    }

    /**
     * فحص إمكانية الـ Over-Capture للعرض في الواجهة.
     */
    public function canOvercapture(string $gateway, float $authorizedAmount, float $requestedAmount): array
    {
        if (!self::allowsOvercapture($gateway)) {
            return ['allowed' => false, 'reason' => "Over-capture غير مدعوم لبوابة {$gateway}"];
        }
        return ['allowed' => true, 'reason' => ''];
    }

    // ──────────────────────────────────────────────────────────
    // [1] HOLD — حجز المبلغ بدون خصم
    // ──────────────────────────────────────────────────────────
    public function createHold(
        float  $amount,
        string $currency,
        string $reference,
        int    $userId,
        array  $meta    = [],
        string $gateway = ''
    ): array {
        $payload = GatewayAdapterFactory::normalizePayload(array_merge($meta, [
            'amount'          => $amount,
            'currency'        => $currency,
            'reference'       => $reference,
            'processing_mode' => $meta['security_mode'] ?? '3D',
        ]));

        // تخفيض إلى 2D إذا لم يتطلب تحدياً
        if (strtoupper((string) ($payload['processing_mode'] ?? '')) === '3D') {
            try {
                require_once __DIR__ . '/CardScaService.php';
                if (!CardScaService::shouldChallenge($payload)) {
                    $payload['processing_mode'] = '2D';
                }
            } catch (\Throwable $e) {
                $this->log("CardScaService failed: {$e->getMessage()} — keeping 3D");
            }
        }

        $gw = strtolower(trim($gateway !== '' ? $gateway : (string) (getenv('CARD_PROVIDER') ?: '')));
        if ($gw === '') {
            return ['success' => false, 'message' => 'Gateway is required.'];
        }

        $result = DiParmaChargeHub::charge($gw, 'auth_hold', array_merge($payload, [
            'channel' => 'hold_capture',
            'user_id' => $userId,
        ]));

        $status = (string) ($result['status'] ?? '');

        if (!empty($result['success']) || $status === 'requires_3ds') {
            $holdId = $this->db->insert('dp_holds', [
                'reference'         => $reference,
                'user_id'           => $userId,
                'payment_intent_id' => $result['transaction_id'] ?? '',
                'amount'            => $amount,
                'currency'          => strtoupper($currency),
                'status'            => $status === 'authorized' ? 'authorized' : 'pending',
                'gateway'           => $gw,
                'mode'              => strtoupper($payload['processing_mode'] ?? '2D'),
                'expires_at'        => date('Y-m-d H:i:s', strtotime('+7 days')),
                'meta'              => json_encode($meta),
                'created_at'        => date('Y-m-d H:i:s'),
            ]);

            $result['hold_id']    = $holdId;
            $result['public_key'] = getenv('STRIPE_PUBLIC_KEY') ?: '';
            $this->syncAuthorizedStore($reference, $userId, $amount, $currency, $gw, $result, (string) ($payload['processing_mode'] ?? '2D'));
        }

        $this->log("createHold: ref={$reference} gw={$gw} status={$status} amount={$amount} {$currency}");
        return $result;
    }

    // ──────────────────────────────────────────────────────────
    // [2] تأكيد HOLD بعد 3DS
    // ──────────────────────────────────────────────────────────
    public function confirmHold(string $paymentIntentId): array
    {
        $hold = $this->getHoldByPI($paymentIntentId);
        if (!$hold) {
            return ['success' => false, 'message' => 'الحجز غير موجود'];
        }

        $gateway = (string) ($hold['gateway'] ?? 'stripe');

        // Stripe — تحقق مباشر
        if ($gateway === 'stripe') {
            $secretKey = getenv('STRIPE_SECRET_KEY') ?: '';
            if (empty($secretKey)) {
                return ['success' => false, 'message' => 'STRIPE_SECRET_KEY غير مضبوط'];
            }

            $ch = curl_init("https://api.stripe.com/v1/payment_intents/{$paymentIntentId}");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD        => $secretKey . ':',
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $res    = curl_exec($ch);
            curl_close($ch);
            $pi     = json_decode($res ?: '{}', true) ?? [];
            $status = $pi['status'] ?? '';

            if ($status === 'requires_capture') {
                $this->db->execute(
                    "UPDATE dp_holds SET status='authorized', authorized_at=? WHERE payment_intent_id=?",
                    [date('Y-m-d H:i:s'), $paymentIntentId]
                );
                $this->log("confirmHold: PI={$paymentIntentId} authorized via Stripe");
                return [
                    'success'        => true,
                    'status'         => 'authorized',
                    'transaction_id' => $paymentIntentId,
                    'amount'         => ($pi['amount'] ?? 0) / 100,
                    'currency'       => strtoupper($pi['currency'] ?? ''),
                    'reference'      => $pi['metadata']['reference'] ?? '',
                    'message'        => 'تم تأكيد الحجز',
                    'requires_3ds'   => false,
                    'client_secret'  => '',
                    'redirect_url'   => '',
                    'decline_code'   => '',
                ];
            }

            return ['success' => false, 'status' => $status, 'message' => "الحجز لم يكتمل — الحالة: {$status}"];
        }

        // بوابات أخرى — confirmAuth عبر ChargeHub
        $result = DiParmaChargeHub::charge($gateway, 'confirm_auth', [
            'transaction_id' => $paymentIntentId,
            'reference'      => $hold['reference'] ?? '',
            'channel'        => 'hold_capture',
        ]);

        if (!empty($result['success'])) {
            $this->db->execute(
                "UPDATE dp_holds SET status='authorized', authorized_at=? WHERE payment_intent_id=?",
                [date('Y-m-d H:i:s'), $paymentIntentId]
            );
            $this->log("confirmHold: PI={$paymentIntentId} authorized via {$gateway}");
        }

        return $result;
    }

    // ──────────────────────────────────────────────────────────
    // [3] CAPTURE — تحصيل المبلغ المحجوز (مع Over-Capture)
    // ──────────────────────────────────────────────────────────

    /**
     * إكمال العملية بعد الحجز.
     *
     * الحالات:
     *   1) partialAmount < remaining → partial capture
     *   2) partialAmount = remaining → full capture
     *   3) partialAmount > remaining → over-capture (بموافقة مسبقة)
     *
     * @param string     $paymentIntentId
     * @param float|null $partialAmount   null = الباقي كاملاً
     * @param int|null   $userId
     * @param array      $opts            ['idempotency_key' => ...]
     */
    public function capture(
        string  $paymentIntentId,
        ?float  $partialAmount = null,
        ?int    $userId        = null,
        array   $opts          = []
    ): array {
        $start = microtime(true);

        // ─── 1) جلب الحجز مع قفل FOR UPDATE ────────────────
        $this->db->beginTransaction();
        try {
            $hold = $this->db->query(
                "SELECT * FROM dp_holds WHERE payment_intent_id = ? FOR UPDATE",
                [$paymentIntentId]
            )[0] ?? null;
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => 'DB lock error: ' . $e->getMessage()];
        }

        if (!$hold) {
            return ['success' => false, 'message' => 'الحجز غير موجود'];
        }

        // ─── 2) فحص الملكية ─────────────────────────────────
        if ($userId !== null && (int) ($hold['user_id'] ?? 0) !== $userId) {
            return ['success' => false, 'message' => 'لا تملك صلاحية هذا الحجز'];
        }

        // ─── 3) فحص الحالة ──────────────────────────────────
        $status = (string) ($hold['status'] ?? '');
        if (!in_array($status, ['authorized', 'partially_captured'], true)) {
            return ['success' => false, 'message' => "لا يمكن التحصيل — الحالة: {$status}"];
        }

        // ─── 4) حساب المبالغ ────────────────────────────────
        $gateway          = (string) ($hold['gateway'] ?? 'stripe');
        $authorizedAmount = (float)  ($hold['amount'] ?? 0);
        $alreadyCaptured  = (float)  ($hold['captured_amount'] ?? 0);
        $remaining        = max(0.0, $authorizedAmount - $alreadyCaptured);
        $requestedAmount  = $partialAmount ?? $remaining;

        if ($requestedAmount <= 0) {
            return ['success' => false, 'message' => 'المبلغ غير صالح'];
        }

        // ─── 5) تحديد نوع العملية ───────────────────────────
        $overAmount = max(0.0, $requestedAmount - $remaining);
        $isOver     = $overAmount > 0.01;

        // ─── 6) المبلغ الأعلى من المتبقي يُرسل كما أُدخل. سقف البنك المطلق يُفحص قبل ذلك.

        // ─── 7) بناء مفتاح الـ Idempotency ──────────────────
        $idempotencyKey = $opts['idempotency_key']
            ?? $this->buildCaptureKey($paymentIntentId, $requestedAmount);

        // ─── 8) تنفيذ Capture مباشر ─────────────────────────
        $result = DiParmaChargeHub::charge($gateway, 'capture', [
            'transaction_id'         => $paymentIntentId,
            'orig_ref'               => $paymentIntentId,
            'related_transaction_id' => $paymentIntentId,
            'amount'                 => $requestedAmount,
            'partial_amount'         => $requestedAmount,
            'currency'               => $hold['currency'] ?? 'USD',
            'reference'              => $hold['reference'] ?? '',
            'channel'                => 'hold_capture',
            'idempotency_key'        => $idempotencyKey,
            'overcapture'            => $isOver,
            'overcapture_amount'     => $overAmount,
        ]);

        // ─── 9) تسجيل النتيجة ───────────────────────────────
        if (!empty($result['success'])) {
            $this->recordCapture(
                $paymentIntentId,
                $result,
                $alreadyCaptured,
                $authorizedAmount,
                $isOver,
                $gateway
            );

            $result['reference']      = $hold['reference'];
            $result['total_captured'] = $alreadyCaptured + (float) ($result['amount'] ?? $requestedAmount);
            $result['overcapture']    = $isOver;
            $result['over_amount']    = $overAmount;
            $result['duration_ms']    = (int) ((microtime(true) - $start) * 1000);

            $this->log(sprintf(
                "capture OK: PI=%s req=%.2f auth=%.2f already=%.2f over=%s gw=%s",
                $paymentIntentId, $requestedAmount, $authorizedAmount,
                $alreadyCaptured, $isOver ? 'YES' : 'no', $gateway
            ));
        } else {
            $this->log(sprintf(
                "capture FAIL: PI=%s amt=%.2f gw=%s msg=%s",
                $paymentIntentId, $requestedAmount, $gateway,
                $result['message'] ?? 'unknown'
            ), 'ALERT');
        }

        return $result;
    }

    // ──────────────────────────────────────────────────────────
    // [4] CANCEL — إلغاء الحجز (Void)
    // ──────────────────────────────────────────────────────────
    public function cancel(string $paymentIntentId, string $reason = 'requested_by_customer'): array
    {
        $hold = $this->getHoldByPI($paymentIntentId);
        if (!$hold) {
            return ['success' => false, 'message' => 'الحجز غير موجود'];
        }

        $gateway = $hold['gateway'] ?? 'stripe';

        $result = DiParmaChargeHub::charge($gateway, 'void', [
            'transaction_id'         => $paymentIntentId,
            'orig_ref'               => $paymentIntentId,
            'related_transaction_id' => $paymentIntentId,
            'reason'                 => $reason,
            'reference'              => $hold['reference'] ?? '',
            'channel'                => 'hold_capture',
        ]);

        if (!empty($result['success'])) {
            $this->db->execute(
                "UPDATE dp_holds SET status='cancelled', cancelled_at=? WHERE payment_intent_id=?",
                [date('Y-m-d H:i:s'), $paymentIntentId]
            );
            $this->syncCancelledStore($paymentIntentId, (string) ($hold['reference'] ?? ''));
            $result['reference'] = $hold['reference'];
            $this->log("cancel: PI={$paymentIntentId} gateway={$gateway}");
        }

        return $result;
    }

    // ──────────────────────────────────────────────────────────
    // [5] CHARGE — شراء مباشر (2D / 3D / MOTO / OFFLINE)
    // ──────────────────────────────────────────────────────────

    /**
     * نقطة دخول موحدة لجميع أوضاع الشراء.
     * يقرأ processing_mode ويوجّه تلقائياً.
     */
    public function charge(array $context, string $gateway = ''): array
    {
        $payload = GatewayAdapterFactory::normalizePayload($context);

        $mode = strtoupper((string) ($payload['mode'] ?? $payload['processing_mode'] ?? '2D'));

        $gw = strtolower(trim($gateway ?: (getenv('CARD_PROVIDER') ?: '')));
        if ($gw === '') {
            return ['success' => false, 'message' => 'CARD_PROVIDER غير مضبوط'];
        }

        // ─── فحص دعم Offline ────────────────────────────────
        if ($mode === 'OFFLINE') {
            $offCheck = $this->canUseOffline($gw);
            if (!$offCheck['allowed']) {
                return ['success' => false, 'error_code' => 'OFFLINE_NOT_ALLOWED', 'message' => $offCheck['reason']];
            }
        }

        // ─── تخفيض 3D إذا لم يتطلب تحدياً ──────────────────
        if ($mode === '3D') {
            try {
                require_once __DIR__ . '/CardScaService.php';
                if (!CardScaService::shouldChallenge($payload)) {
                    $mode = '2D';
                }
            } catch (\Throwable $e) {
                $this->log("CardScaService failed: {$e->getMessage()} — keeping 3D");
            }
        }

        $payload['processing_mode'] = $mode;

        $result = DiParmaChargeHub::charge($gw, 'purchase', array_merge($payload, [
            'mode'    => $mode,
            'channel' => 'hold_capture',
        ]));

        $this->log(sprintf(
            "charge[%s]: ref=%s gw=%s status=%s",
            $mode, $payload['reference'] ?? '', $gw, $result['status'] ?? ''
        ));

        return $result;
    }

    // ──────────────────────────────────────────────────────────
    // [6] 2D Direct Charge
    // ──────────────────────────────────────────────────────────
    public function directCharge2D(array $context, string $gateway = ''): array
    {
        $payload = GatewayAdapterFactory::normalizePayload(array_merge($context, [
            'processing_mode' => '2D',
        ]));

        if (($payload['amount'] ?? 0) <= 0) {
            return ['success' => false, 'message' => 'المبلغ غير صالح'];
        }
        if (strlen((string) ($payload['card_number'] ?? '')) < 13) {
            return ['success' => false, 'message' => 'رقم البطاقة غير صالح'];
        }

        $gw = strtolower(trim($gateway ?: (getenv('CARD_PROVIDER') ?: 'nuvei')));

        $result = DiParmaChargeHub::charge($gw, 'purchase_2d', array_merge($payload, [
            'processing_mode' => '2D',
            'channel'         => 'hold_capture',
        ]));

        $this->log("directCharge2D: ref={$payload['reference']} gw={$gw} status=" . ($result['status'] ?? ''));
        return $result;
    }

    // ──────────────────────────────────────────────────────────
    // دوال مساعدة خاصة
    // ──────────────────────────────────────────────────────────

    /**
     * تسجيل نتيجة Capture في DB مع قفل FOR UPDATE.
     * يُحدّث dp_holds ويُسجّل في capture_overcaptures عند الحاجة.
     */
    private function recordCapture(
        string $paymentIntentId,
        array  $result,
        float  $alreadyCaptured,
        float  $authorizedAmount,
        bool   $isOver,
        string $gateway
    ): void {
        $this->db->beginTransaction();
        try {
            // إعادة جلب الصف بقفل ثانٍ لضمان التزامن
            $hold = $this->db->query(
                "SELECT * FROM dp_holds WHERE payment_intent_id = ? FOR UPDATE",
                [$paymentIntentId]
            )[0] ?? null;

            if (!$hold) {
                $this->db->rollback();
                return;
            }

            $capturedAmount = (float) ($result['amount'] ?? 0);
            $newTotal       = $alreadyCaptured + $capturedAmount;
            $captureCount   = (int) ($hold['capture_count'] ?? 0) + 1;

            // الحالة: captured إذا وصل للمبلغ المصرَّح به أو تجاوزه
            $newStatus = ($newTotal >= $authorizedAmount - 0.01)
                ? 'captured'
                : 'partially_captured';

            $now = date('Y-m-d H:i:s');

            $this->db->execute(
                "UPDATE dp_holds
                 SET status           = ?,
                     captured_amount  = ?,
                     captured_at      = COALESCE(captured_at, ?),
                     last_captured_at = ?,
                     capture_count    = ?,
                     overcaptured     = ?
                 WHERE payment_intent_id = ?",
                [
                    $newStatus,
                    $newTotal,
                    $now,
                    $now,
                    $captureCount,
                    $isOver ? 1 : 0,
                    $paymentIntentId,
                ]
            );

            $this->db->commit();

            // ─── سجّل Over-Capture في جدول التدقيق ──────────
            if ($isOver) {
                $this->db->insert('capture_overcaptures', [
                    'payment_intent_id' => $paymentIntentId,
                    'gateway'           => $gateway,
                    'authorized_amount' => $authorizedAmount,
                    'total_captured'    => $newTotal,
                    'over_amount'       => $newTotal - $authorizedAmount,
                    'mode'              => $hold['mode'] ?? '2D',
                    'created_at'        => $now,
                ]);
            }
            $this->syncCapturedStore($paymentIntentId, (string) ($hold['reference'] ?? ''), $capturedAmount);
        } catch (\Throwable $e) {
            $this->db->rollback();
            $this->log("recordCapture FAILED: PI={$paymentIntentId} error={$e->getMessage()}", 'ALERT');
            throw $e;
        }
    }

    /**
     * بناء مفتاح Idempotency للـ Capture.
     * يستخدم HMAC-SHA256 مع ENCRYPTION_KEY.
     */
    private function holdSync(): void
    {
        if (!class_exists('HoldStoreSync', false)) {
            require_once __DIR__ . '/HoldStoreSync.php';
        }
    }

    private function syncAuthorizedStore(string $reference, int $userId, float $amount, string $currency, string $gateway, array $result, string $mode): void
    {
        try {
            $this->holdSync();
            HoldStoreSync::syncAuthorized($this->db, [
                'reference' => $reference,
                'user_id' => $userId,
                'payment_intent_id' => (string) ($result['transaction_id'] ?? $result['payment_id'] ?? ''),
                'amount' => $amount,
                'currency' => $currency,
                'gateway' => $gateway,
                'status' => (string) ($result['status'] ?? 'authorized'),
                'rrn' => (string) ($result['rrn'] ?? ''),
                'approval_code' => (string) ($result['approval_code'] ?? $result['auth_code'] ?? ''),
                'gateway_response' => json_encode($result, JSON_UNESCAPED_UNICODE),
                'mode' => $mode,
            ]);
        } catch (Throwable $e) {
            $this->log('hold sync auth failed: ' . $e->getMessage(), 'ALERT');
        }
    }

    private function syncCapturedStore(string $paymentIntentId, string $reference, float $amount): void
    {
        try {
            $this->holdSync();
            HoldStoreSync::markCaptured($this->db, [
                'payment_id' => $paymentIntentId,
                'transaction_id' => $paymentIntentId,
                'reference' => $reference,
                'orig_ref' => $reference,
            ], $amount);
        } catch (Throwable $e) {
            $this->log('hold sync capture failed: ' . $e->getMessage(), 'ALERT');
        }
    }

    private function syncCancelledStore(string $paymentIntentId, string $reference): void
    {
        try {
            $this->holdSync();
            HoldStoreSync::markCancelled($this->db, [
                'payment_id' => $paymentIntentId,
                'transaction_id' => $paymentIntentId,
                'reference' => $reference,
                'orig_ref' => $reference,
            ]);
        } catch (Throwable $e) {
            $this->log('hold sync cancel failed: ' . $e->getMessage(), 'ALERT');
        }
    }

    private function buildCaptureKey(string $pi, float $amount): string
    {
        $key = getenv('ENCRYPTION_KEY');
        if ($key === false || strlen($key) < 32) {
            // fallback ثابت: بدون time() لضمان idempotency عند إعادة الإرسال
            return hash('sha256', "capture|{$pi}|" . number_format($amount, 2, '.', ''));
        }
        return hash_hmac(
            'sha256',
            "capture|{$pi}|" . number_format($amount, 2, '.', ''),
            $key
        );
    }

    // ──────────────────────────────────────────────────────────
    // إدارة الحجوزات
    // ──────────────────────────────────────────────────────────
    public function getUserHolds(int $userId): array
    {
        return $this->db->query(
            "SELECT * FROM dp_holds WHERE user_id = ? ORDER BY created_at DESC",
            [$userId]
        );
    }

    public function getHoldByReference(string $reference): ?array
    {
        return $this->db->find('dp_holds', ['reference' => $reference]);
    }

    public function getHoldByPI(string $paymentIntentId): ?array
    {
        return $this->db->find('dp_holds', ['payment_intent_id' => $paymentIntentId]);
    }

    public function getAllHolds(int $limit = 50): array
    {
        return $this->db->query(
            "SELECT h.*, u.username
             FROM dp_holds h
             LEFT JOIN dp_users u ON u.id = h.user_id
             ORDER BY h.created_at DESC
             LIMIT ?",
            [$limit]
        );
    }

    // ──────────────────────────────────────────────────────────
    // DB Schema
    // ──────────────────────────────────────────────────────────
    private function ensureTable(): void
    {
        // جدول الحجوزات الرئيسي
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `dp_holds` (
                `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `reference`         VARCHAR(100) NOT NULL,
                `user_id`           INT UNSIGNED NOT NULL,
                `payment_intent_id` VARCHAR(100) NOT NULL,
                `amount`            DECIMAL(12,2) NOT NULL,
                `currency`          VARCHAR(10) NOT NULL DEFAULT 'USD',
                `status`            VARCHAR(30) NOT NULL DEFAULT 'pending'
                                    COMMENT 'pending|authorized|partially_captured|captured|cancelled|expired',
                `gateway`           VARCHAR(30) NOT NULL DEFAULT 'stripe',
                `mode`              VARCHAR(20) DEFAULT '2D',
                `captured_amount`   DECIMAL(12,2) DEFAULT NULL,
                `capture_count`     INT UNSIGNED NOT NULL DEFAULT 0,
                `overcaptured`      TINYINT(1) NOT NULL DEFAULT 0,
                `offline_sale`      TINYINT(1) NOT NULL DEFAULT 0,
                `meta`              TEXT DEFAULT NULL,
                `expires_at`        DATETIME DEFAULT NULL,
                `authorized_at`     DATETIME DEFAULT NULL,
                `captured_at`       DATETIME DEFAULT NULL,
                `last_captured_at`  DATETIME DEFAULT NULL,
                `cancelled_at`      DATETIME DEFAULT NULL,
                `created_at`        DATETIME NOT NULL,
                UNIQUE KEY `uniq_ref` (`reference`),
                UNIQUE KEY `uniq_pi`  (`payment_intent_id`),
                INDEX `idx_user`      (`user_id`),
                INDEX `idx_status`    (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // جدول تدقيق Over-Capture
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `capture_overcaptures` (
                `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `payment_intent_id` VARCHAR(100) NOT NULL,
                `gateway`           VARCHAR(30) NOT NULL,
                `authorized_amount` DECIMAL(12,2) NOT NULL,
                `total_captured`    DECIMAL(12,2) NOT NULL,
                `over_amount`       DECIMAL(12,2) NOT NULL,
                `mode`              VARCHAR(20) DEFAULT '2D',
                `created_at`        DATETIME NOT NULL,
                INDEX `idx_pi`      (`payment_intent_id`),
                INDEX `idx_gateway` (`gateway`),
                INDEX `idx_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // جدول Offline Sales
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `offline_sales` (
                `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `payment_intent_id` VARCHAR(100) NOT NULL,
                `gateway`           VARCHAR(30) NOT NULL,
                `reference`         VARCHAR(100) NOT NULL,
                `amount`            DECIMAL(12,2) NOT NULL,
                `currency`          VARCHAR(10) NOT NULL DEFAULT 'USD',
                `mode`              VARCHAR(20) NOT NULL DEFAULT 'OFFLINE',
                `status`            VARCHAR(30) NOT NULL DEFAULT 'pending',
                `captured_at`       DATETIME DEFAULT NULL,
                `settled_at`        DATETIME DEFAULT NULL,
                `created_at`        DATETIME NOT NULL,
                INDEX `idx_pi`      (`payment_intent_id`),
                INDEX `idx_gateway` (`gateway`),
                INDEX `idx_status`  (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    // ──────────────────────────────────────────────────────────
    // Logging
    // ──────────────────────────────────────────────────────────
    private function log(string $msg, string $level = 'INFO'): void
    {
        @file_put_contents(
            $this->logFile,
            '[' . date('Y-m-d H:i:s') . "] [{$level}] {$msg}\n",
            FILE_APPEND
        );
    }
}
