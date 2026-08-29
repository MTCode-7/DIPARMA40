<?php
/**
 * ============================================================
 * DI PARMA | GatewayAdapterInterface
 * واجهة موحدة لجميع محولات بوابات الدفع
 * ============================================================
 * يضمن أن كل بوابة تنفّذ نفس العقد:
 *  - charge()      → دفع مباشر (2D أو 3D حسب الـ payload)
 *  - hold()        → حجز (HOLD / AUTHORIZE)
 *  - capture()     → تحصيل المحجوز
 *  - cancel()      → إلغاء الحجز
 *  - supports()    → هل تدعم البوابة هذا النوع من المعالجة؟
 * ============================================================
 */

interface GatewayAdapterInterface
{
    /**
     * الـ Payload الموحد المتوقع:
     * [
     *   'amount'          => float,        // المبلغ
     *   'currency'        => string,       // USD / SAR / AED ...
     *   'card_number'     => string,       // رقم البطاقة (بدون مسافات)
     *   'card_expiry'     => string,       // MM/YY أو MM/YYYY
     *   'cvv2'            => string,       // 3-4 أرقام
     *   'processing_mode' => '2D'|'3D',   // وضع المعالجة
     *   'reference'       => string,       // رقم مرجعي فريد
     *   'name'            => string,       // اسم حامل البطاقة (اختياري)
     *   'email'           => string,       // البريد (اختياري)
     *   'approval_code'   => string,       // كود الموافقة (اختياري)
     * ]
     *
     * الـ Response الموحد:
     * [
     *   'success'           => bool,
     *   'status'            => 'completed'|'pending'|'requires_3ds'|'declined',
     *   'transaction_id'    => string,
     *   'reference'         => string,
     *   'amount'            => float,
     *   'currency'          => string,
     *   'message'           => string,
     *   'requires_3ds'      => bool,      // true إذا البوابة طلبت 3DS
     *   'client_secret'     => string,    // للـ 3DS redirect
     *   'redirect_url'      => string,    // للـ 3DS redirect
     *   'decline_code'      => string,    // كود الرفض إذا وجد
     * ]
     */
    public function charge(array $payload): array;

    /**
     * حجز المبلغ (لا يُخصم) — AUTHORIZE/HOLD
     */
    public function hold(array $payload): array;

    /**
     * تحصيل مبلغ محجوز مسبقاً
     * @param string $transactionId  معرّف العملية من hold()
     * @param float|null $amount     مبلغ جزئي أو null للكامل
     */
    public function capture(string $transactionId, ?float $amount = null): array;

    /**
     * إلغاء حجز
     * @param string $transactionId  معرّف العملية من hold()
     * @param string $reason         سبب الإلغاء
     */
    public function cancel(string $transactionId, string $reason = 'requested_by_customer'): array;

    /**
     * هل تدعم هذه البوابة نوع المعالجة؟
     * @param string $mode  '2D' | '3D' | 'hold' | 'capture'
     */
    public function supports(string $mode): bool;

    /**
     * اسم البوابة (للـ logging والـ DB)
     */
    public function getName(): string;

    /**
     * تحويل رد خطأ البوابة الخام إلى رمز موحد
     * يُستخدم داخلياً في كل محول للحصول على CARD_DECLINED, EXPIRED_CARD...
     * @param array $rawResponse الرد الخام من البوابة
     * @return string رمز موحد من GatewayErrorMapper
     */
    public function normalizeError(array $rawResponse): string;

    /**
     * توليد Idempotency Key فريد لعملية معينة
     * يمنع تكرار الخصم عند إعادة المحاولة
     * @param string $reference رقم مرجعي للعملية
     * @param float  $amount    المبلغ
     * @return string
     */
    public function buildIdempotencyKey(string $reference, float $amount): string;
}
