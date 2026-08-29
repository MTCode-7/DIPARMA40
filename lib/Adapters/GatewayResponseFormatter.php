<?php
/**
 * ============================================================
 * DI PARMA | GatewayResponseFormatter
 * توحيد شكل الاستجابة لجميع البروتوكولات (101.1 / 201.3 / Direct)
 * ============================================================
 * يضمن أن أي كود يستهلك نتيجة الدفع يقرأ نفس الحقول
 * بغض النظر عن البوابة أو نوع العملية.
 *
 * الحقول الموحدة:
 *   success       bool    — نجاح العملية
 *   gateway       string  — اسم البوابة
 *   action        string  — CHARGE | HOLD | CAPTURE | CANCEL
 *   protocol      string  — DIRECT | 101.1 | 201.3
 *   amount        float   — المبلغ
 *   currency      string  — العملة
 *   status        string  — completed | authorized | captured | cancelled | declined | requires_3ds | pending
 *   message       string  — رسالة للمستخدم
 *   error_code    string  — رمز موحد من GatewayErrorMapper
 *   transaction_id string — معرّف العملية في البوابة
 *   reference     string  — الرقم المرجعي الداخلي
 *   requires_3ds  bool    — هل يحتاج 3DS redirect
 *   redirect_url  string  — رابط 3DS إن وجد
 *   client_secret string  — للـ Stripe.js
 *   retryable     bool    — هل يُعاد المحاولة
 *   hard_block    bool    — هل يُوقف نهائياً
 *   timestamp     string  — توقيت تنفيذ العملية
 *   raw_response  array   — الرد الخام من البوابة (للـ logging)
 * ============================================================
 */

final class GatewayResponseFormatter
{
    // ══════════════════════════════════════════════════════════
    // format() — نقطة الدخول الرئيسية
    // ══════════════════════════════════════════════════════════

    /**
     * @param bool   $success
     * @param string $gateway      اسم البوابة
     * @param string $action       charge | hold | capture | cancel
     * @param float  $amount
     * @param string $currency
     * @param array  $rawResponse  الرد الخام من الـ Adapter
     * @param string $message      رسالة اختيارية
     * @param string $protocol     DIRECT | 101.1 | 201.3
     */
    public static function format(
        bool   $success,
        string $gateway,
        string $action,
        float  $amount,
        string $currency,
        array  $rawResponse,
        string $message  = '',
        string $protocol = 'DIRECT'
    ): array {
        $action   = strtoupper(trim($action));
        $gateway  = strtolower(trim($gateway));
        $currency = strtoupper(trim($currency));
        $protocol = strtoupper(trim($protocol));

        // استخراج الحقول من الـ rawResponse
        $status      = self::resolveStatus($success, $action, $rawResponse);
        $errorCode   = $rawResponse['error_code']     ?? ($success ? '' : 'UNKNOWN');
        $txId        = $rawResponse['transaction_id'] ?? $rawResponse['tran_ref']
                    ?? $rawResponse['transId']         ?? '';
        $reference   = $rawResponse['reference']      ?? $rawResponse['cart_id']
                    ?? $rawResponse['refId']           ?? '';
        $requires3ds = (bool)($rawResponse['requires_3ds'] ?? false);
        $redirectUrl = $rawResponse['redirect_url']   ?? '';
        $clientSecret= $rawResponse['client_secret']  ?? '';
        $retryable   = (bool)($rawResponse['retryable']  ?? false);
        $hardBlock   = (bool)($rawResponse['hard_block'] ?? false);
        $authCode    = $rawResponse['auth_code']      ?? '';

        if (empty($message)) {
            $message = $success
                ? self::successMessage($action, $gateway)
                : ($rawResponse['message'] ?? '❌ فشلت العملية');
        }

        return [
            // ── النتيجة الأساسية ──────────────────────────────
            'success'        => $success,
            'gateway'        => $gateway,
            'action'         => $action,
            'protocol'       => $protocol,
            'status'         => $status,
            'message'        => $message,
            // ── المبلغ والعملة ────────────────────────────────
            'amount'         => round($amount, 2),
            'currency'       => $currency,
            // ── معرّفات العملية ───────────────────────────────
            'transaction_id' => $txId,
            'reference'      => $reference,
            'auth_code'      => $authCode,
            // ── 3DS ───────────────────────────────────────────
            'requires_3ds'   => $requires3ds,
            'redirect_url'   => $redirectUrl,
            'client_secret'  => $clientSecret,
            // ── معلومات الخطأ ─────────────────────────────────
            'error_code'     => $errorCode,
            'retryable'      => $retryable,
            'hard_block'     => $hardBlock,
            // ── توقيت ────────────────────────────────────────
            'timestamp'      => date('Y-m-d H:i:s'),
            // ── الرد الخام (للـ logging فقط) ──────────────────
            'raw_response'   => $rawResponse,
        ];
    }

    // ══════════════════════════════════════════════════════════
    // fromAdapterResponse() — تحويل رد Adapter مباشرة
    // ══════════════════════════════════════════════════════════

    /**
     * تحويل الـ response الخام من أي Adapter إلى الصيغة الموحدة
     */
    public static function fromAdapterResponse(
        array  $adapterResponse,
        string $gateway,
        string $action,
        string $protocol = 'DIRECT'
    ): array {
        return self::format(
            $adapterResponse['success']  ?? false,
            $gateway,
            $action,
            floatval($adapterResponse['amount']   ?? 0),
            $adapterResponse['currency']          ?? 'USD',
            $adapterResponse,
            $adapterResponse['message']           ?? '',
            $protocol
        );
    }

    // ══════════════════════════════════════════════════════════
    // forProtocol() — تنسيق خاص بالبروتوكول
    // ══════════════════════════════════════════════════════════

    /**
     * تنسيق موحد يُضيف حقولاً خاصة بالبروتوكولات 101.1 / 201.3
     */
    public static function forProtocol(
        array  $adapterResponse,
        string $gateway,
        string $protocol,       // '101.1' | '201.3' | 'DIRECT'
        array  $orderMeta = []  // بيانات الطلب: crypto, network, wallet...
    ): array {
        $action = match (strtoupper($protocol)) {
            '101.1' => ($adapterResponse['status'] ?? '') === 'authorized' ? 'HOLD' : 'CAPTURE',
            '201.3' => 'CHARGE',
            default => 'CHARGE',
        };

        $base = self::fromAdapterResponse($adapterResponse, $gateway, $action, $protocol);

        // إضافة بيانات الطلب الخاصة بالـ crypto
        if (!empty($orderMeta)) {
            $base['order'] = [
                'crypto'        => $orderMeta['crypto']         ?? 'USDT',
                'network'       => $orderMeta['network']        ?? 'TRC20',
                'wallet'        => $orderMeta['wallet_address'] ?? '',
                'crypto_amount' => $orderMeta['crypto_amount']  ?? 0,
            ];
        }

        // حقول خاصة بـ 101.1
        if ($protocol === '101.1') {
            $base['hold_expires'] = $orderMeta['hold_expires'] ?? date('Y-m-d H:i:s', strtotime('+7 days'));
            $base['capture_by']   = $orderMeta['capture_by']   ?? 'admin';
        }

        return $base;
    }

    // ══════════════════════════════════════════════════════════
    // toApiJson() — للإرسال مباشرة للـ Frontend
    // ══════════════════════════════════════════════════════════

    /**
     * ينقّح الـ response من الحقول الحساسة قبل إرسالها للـ Frontend
     */
    public static function toApiJson(array $formatted, bool $includeRaw = false): array
    {
        $safe = $formatted;

        // حذف raw_response من الإرسال للـ Frontend افتراضياً
        if (!$includeRaw) {
            unset($safe['raw_response']);
        }

        return $safe;
    }

    // ══════════════════════════════════════════════════════════
    // خطأ سريع موحد
    // ══════════════════════════════════════════════════════════

    public static function error(
        string $message,
        string $errorCode = 'GATEWAY_ERROR',
        string $gateway   = '',
        string $action    = 'CHARGE'
    ): array {
        return [
            'success'        => false,
            'gateway'        => $gateway,
            'action'         => strtoupper($action),
            'protocol'       => 'DIRECT',
            'status'         => 'declined',
            'message'        => $message,
            'amount'         => 0.0,
            'currency'       => '',
            'transaction_id' => '',
            'reference'      => '',
            'auth_code'      => '',
            'requires_3ds'   => false,
            'redirect_url'   => '',
            'client_secret'  => '',
            'error_code'     => $errorCode,
            'retryable'      => in_array($errorCode, ['GATEWAY_ERROR', 'NETWORK_ERROR']),
            'hard_block'     => in_array($errorCode, ['STOLEN_CARD', 'DO_NOT_HONOR']),
            'timestamp'      => date('Y-m-d H:i:s'),
            'raw_response'   => [],
        ];
    }

    // ── مساعدات خاصة ─────────────────────────────────────────

    private static function resolveStatus(bool $success, string $action, array $raw): string
    {
        if (!$success) {
            return $raw['status'] ?? 'declined';
        }

        // استخدام الـ status من الـ raw إذا موجود
        $rawStatus = $raw['status'] ?? '';
        if (!empty($rawStatus) && $rawStatus !== 'declined') {
            return $rawStatus;
        }

        return match ($action) {
            'HOLD'    => 'authorized',
            'CAPTURE' => 'captured',
            'CANCEL'  => 'cancelled',
            default   => 'completed',
        };
    }

    private static function successMessage(string $action, string $gateway): string
    {
        $gwLabel = match ($gateway) {
            'stripe'       => 'Stripe',
            'checkout'     => 'Checkout.com',
            'myfatoorah'   => 'MyFatoorah',
            'paytabs'      => 'PayTabs',
            'authorizenet' => 'Authorize.Net',
            'paypal'       => 'PayPal',
            default        => ucfirst($gateway),
        };

        return match ($action) {
            'CHARGE'  => "✅ تم الدفع عبر $gwLabel بنجاح",
            'HOLD'    => "✅ تم حجز المبلغ عبر $gwLabel",
            'CAPTURE' => "✅ تم تحصيل المبلغ عبر $gwLabel",
            'CANCEL'  => "✅ تم إلغاء الحجز عبر $gwLabel",
            default   => "✅ تمت العملية عبر $gwLabel",
        };
    }
}
