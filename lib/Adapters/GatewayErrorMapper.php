<?php
/**
 * ============================================================
 * DI PARMA | GatewayErrorMapper
 * توحيد رموز الأخطاء من جميع البوابات → رموز موحدة
 * ============================================================
 * الرموز الموحدة:
 *   CARD_DECLINED       — البطاقة مرفوضة (عام)
 *   INSUFFICIENT_FUNDS  — رصيد غير كافٍ
 *   EXPIRED_CARD        — البطاقة منتهية
 *   INVALID_CVV         — CVV خاطئ
 *   INVALID_CARD        — رقم بطاقة خاطئ
 *   STOLEN_CARD         — بطاقة مسروقة/مفقودة
 *   DO_NOT_HONOR        — رفض بنكي عام
 *   LIMIT_EXCEEDED      — تجاوز الحد
 *   DUPLICATE_TXN       — عملية مكررة
 *   GATEWAY_ERROR       — خطأ داخلي في البوابة
 *   NETWORK_ERROR       — خطأ في الشبكة
 *   UNKNOWN             — غير محدد
 * ============================================================
 */
class GatewayErrorMapper
{
    // ── خريطة Stripe ─────────────────────────────────────────
    private static array $stripeMap = [
        // decline_code
        'insufficient_funds'              => 'INSUFFICIENT_FUNDS',
        'card_declined'                   => 'CARD_DECLINED',
        'expired_card'                    => 'EXPIRED_CARD',
        'incorrect_cvc'                   => 'INVALID_CVV',
        'incorrect_number'                => 'INVALID_CARD',
        'invalid_cvc'                     => 'INVALID_CVV',
        'invalid_expiry_month'            => 'EXPIRED_CARD',
        'invalid_expiry_year'             => 'EXPIRED_CARD',
        'invalid_number'                  => 'INVALID_CARD',
        'stolen_card'                     => 'STOLEN_CARD',
        'lost_card'                       => 'STOLEN_CARD',
        'do_not_honor'                    => 'DO_NOT_HONOR',
        'do_not_try_again'                => 'DO_NOT_HONOR',
        'fraudulent'                      => 'STOLEN_CARD',
        'generic_decline'                 => 'CARD_DECLINED',
        'no_action_taken'                 => 'DO_NOT_HONOR',
        'not_permitted'                   => 'DO_NOT_HONOR',
        'pickup_card'                     => 'STOLEN_CARD',
        'restricted_card'                 => 'DO_NOT_HONOR',
        'revocation_of_all_authorizations'=> 'STOLEN_CARD',
        'security_violation'              => 'STOLEN_CARD',
        'service_not_allowed'             => 'DO_NOT_HONOR',
        'transaction_not_allowed'         => 'DO_NOT_HONOR',
        'try_again_later'                 => 'GATEWAY_ERROR',
        'withdrawal_count_limit_exceeded' => 'LIMIT_EXCEEDED',
        // error code
        'card_velocity_exceeded'          => 'LIMIT_EXCEEDED',
        'duplicate_transaction'           => 'DUPLICATE_TXN',
        'idempotency_key_in_use'          => 'DUPLICATE_TXN',
        'api_error'                       => 'GATEWAY_ERROR',
        'api_connection_error'            => 'NETWORK_ERROR',
        'authentication_error'            => 'GATEWAY_ERROR',
        'rate_limit_error'                => 'GATEWAY_ERROR',
    ];

    // ── خريطة Checkout.com ───────────────────────────────────
    private static array $checkoutMap = [
        '20014' => 'INVALID_CVV',
        '20051' => 'INSUFFICIENT_FUNDS',
        '20054' => 'EXPIRED_CARD',
        '20057' => 'DO_NOT_HONOR',
        '20061' => 'LIMIT_EXCEEDED',
        '20062' => 'STOLEN_CARD',
        '20065' => 'LIMIT_EXCEEDED',
        '20087' => 'INVALID_CVV',
        '20088' => 'INVALID_CVV',
        '20089' => 'INVALID_CARD',
        '20091' => 'GATEWAY_ERROR',
        '20093' => 'DO_NOT_HONOR',
        '20096' => 'GATEWAY_ERROR',
        '30004' => 'STOLEN_CARD',
        '30007' => 'STOLEN_CARD',
        '30012' => 'DO_NOT_HONOR',
        '30013' => 'DO_NOT_HONOR',
        '40104' => 'INVALID_CARD',
        '40105' => 'INVALID_CARD',
        '40106' => 'EXPIRED_CARD',
        '40107' => 'INVALID_CVV',
        '40108' => 'INVALID_CARD',
        '40109' => 'INVALID_CARD',
    ];

    // ── خريطة MyFatoorah ─────────────────────────────────────
    private static array $myfatoorahMap = [
        'INVALID_CARD_NUMBER'  => 'INVALID_CARD',
        'INVALID_CVV'          => 'INVALID_CVV',
        'EXPIRED_CARD'         => 'EXPIRED_CARD',
        'INSUFFICIENT_FUNDS'   => 'INSUFFICIENT_FUNDS',
        'CARD_DECLINED'        => 'CARD_DECLINED',
        'STOLEN_CARD'          => 'STOLEN_CARD',
        'RESTRICTED_CARD'      => 'DO_NOT_HONOR',
        'LIMIT_EXCEEDED'       => 'LIMIT_EXCEEDED',
        'DUPLICATE_TRANSACTION'=> 'DUPLICATE_TXN',
        'GATEWAY_ERROR'        => 'GATEWAY_ERROR',
    ];

    // ── الرسائل العربية الموحدة ───────────────────────────────
    private static array $arabicMessages = [
        'CARD_DECLINED'      => '❌ البطاقة مرفوضة — تواصل مع البنك',
        'INSUFFICIENT_FUNDS' => '❌ الرصيد غير كافٍ',
        'EXPIRED_CARD'       => '❌ البطاقة منتهية الصلاحية',
        'INVALID_CVV'        => '❌ رمز التحقق CVV غير صحيح',
        'INVALID_CARD'       => '❌ رقم البطاقة غير صحيح',
        'STOLEN_CARD'        => '❌ البطاقة محظورة — تواصل مع البنك',
        'DO_NOT_HONOR'       => '❌ تم رفض العملية من البنك',
        'LIMIT_EXCEEDED'     => '❌ تجاوزت حد الإنفاق المسموح',
        'DUPLICATE_TXN'      => '❌ عملية مكررة — تم تنفيذها مسبقاً',
        'GATEWAY_ERROR'      => '❌ خطأ في بوابة الدفع — حاول لاحقاً',
        'NETWORK_ERROR'      => '❌ خطأ في الاتصال — حاول مجدداً',
        'UNKNOWN'            => '❌ خطأ غير محدد — تواصل مع الدعم',
    ];

    // ══════════════════════════════════════════════════════════
    // API العامة
    // ══════════════════════════════════════════════════════════

    /**
     * تحويل رد بوابة Stripe إلى رمز موحد
     */
    public static function fromStripe(array $response): string
    {
        $declineCode = strtolower($response['last_payment_error']['decline_code'] ?? '');
        $errorCode   = strtolower($response['last_payment_error']['code']         ??
                                  $response['error']['code']                       ?? '');

        return self::$stripeMap[$declineCode]
            ?? self::$stripeMap[$errorCode]
            ?? 'CARD_DECLINED';
    }

    /**
     * تحويل رد Checkout.com إلى رمز موحد
     */
    public static function fromCheckout(array $response): string
    {
        $code = (string)($response['response_code'] ?? '');
        return self::$checkoutMap[$code] ?? 'CARD_DECLINED';
    }

    /**
     * تحويل رد MyFatoorah إلى رمز موحد
     */
    public static function fromMyFatoorah(array $response): string
    {
        $msg = strtoupper(trim($response['Message'] ?? $response['ErrorMessage'] ?? ''));
        // بحث مباشر
        if (isset(self::$myfatoorahMap[$msg])) {
            return self::$myfatoorahMap[$msg];
        }
        // بحث جزئي
        foreach (self::$myfatoorahMap as $keyword => $code) {
            if (str_contains($msg, $keyword)) return $code;
        }
        return 'CARD_DECLINED';
    }

    /**
     * تحويل رد Braintree إلى رمز موحد
     */
    public static function fromBraintree(array $response): string
    {
        $status = strtolower($response['status'] ?? '');
        $code   = (string)($response['processorResponseCode'] ?? '');
        $text   = strtoupper($response['processorResponseText'] ?? '');

        // processor response codes
        $codeMap = [
            '2000' => 'DO_NOT_HONOR',
            '2001' => 'INSUFFICIENT_FUNDS',
            '2002' => 'LIMIT_EXCEEDED',
            '2003' => 'LIMIT_EXCEEDED',
            '2004' => 'EXPIRED_CARD',
            '2005' => 'INVALID_CARD',
            '2006' => 'INVALID_CARD',
            '2007' => 'INVALID_CARD',
            '2008' => 'INVALID_CARD',
            '2009' => 'INVALID_CARD',
            '2010' => 'INVALID_CVV',
            '2015' => 'DO_NOT_HONOR',
            '2016' => 'DUPLICATE_TXN',
            '2038' => 'DO_NOT_HONOR',
            '2046' => 'DO_NOT_HONOR',
            '2053' => 'STOLEN_CARD',
            '2054' => 'STOLEN_CARD',
            '2055' => 'STOLEN_CARD',
            '2056' => 'STOLEN_CARD',
            '2057' => 'STOLEN_CARD',
            '2059' => 'DO_NOT_HONOR',
            '2060' => 'DO_NOT_HONOR',
            '3000' => 'NETWORK_ERROR',
        ];

        if (isset($codeMap[$code])) return $codeMap[$code];

        if (str_contains($text, 'INSUFFICIENT')) return 'INSUFFICIENT_FUNDS';
        if (str_contains($text, 'EXPIRED'))      return 'EXPIRED_CARD';
        if (str_contains($text, 'CVV'))          return 'INVALID_CVV';
        if (str_contains($text, 'STOLEN'))       return 'STOLEN_CARD';
        if (str_contains($text, 'DUPLICATE'))    return 'DUPLICATE_TXN';
        if (str_contains($text, 'DO NOT HONOR')) return 'DO_NOT_HONOR';

        if ($status === 'processor_declined') return 'CARD_DECLINED';
        if ($status === 'gateway_rejected')   return 'GATEWAY_ERROR';
        if ($status === 'failed')             return 'GATEWAY_ERROR';

        return 'CARD_DECLINED';
    }

    /**
     * تحويل رد Authorize.Net إلى رمز موحد
     */
    public static function fromAuthorizeNet(array $response): string
    {
        $code = strtoupper(trim($response['transactionResponse']['responseCode'] ?? ''));
        if ($code === '1') {
            return 'CARD_DECLINED';
        }

        $errorText = strtoupper(trim($response['transactionResponse']['errors'][0]['errorText'] ?? ''));
        if (str_contains($errorText, 'CVC') || str_contains($errorText, 'CVV')) {
            return 'INVALID_CVV';
        }
        if (str_contains($errorText, 'EXPIRED') || str_contains($errorText, 'EXPIR')) {
            return 'EXPIRED_CARD';
        }
        if (str_contains($errorText, 'INVALID CARD') || str_contains($errorText, 'INVALID NUMBER')) {
            return 'INVALID_CARD';
        }
        if (str_contains($errorText, 'DO NOT HONOR') || str_contains($errorText, 'DECLINED')) {
            return 'DO_NOT_HONOR';
        }
        if (str_contains($errorText, 'INSUFFICIENT FUNDS')) {
            return 'INSUFFICIENT_FUNDS';
        }
        if (str_contains($errorText, 'DUPLICATE') || str_contains($errorText, 'IDEMPOTENCY')) {
            return 'DUPLICATE_TXN';
        }
        if ($code === '2') {
            return 'CARD_DECLINED';
        }
        if ($code === '3') {
            return 'DO_NOT_HONOR';
        }
        return 'GATEWAY_ERROR';
    }

    /**
     * تحويل رد PayTabs إلى رمز موحد
     */
    public static function fromPayTabs(array $response): string
    {
        $status = strtolower(trim($response['response_status'] ?? ''));
        $message = strtoupper(trim($response['result'] ?? $response['message'] ?? ''));

        if ($status === 'success') {
            return 'CARD_DECLINED';
        }

        if (str_contains($message, 'CARD') && str_contains($message, 'NUMBER')) {
            return 'INVALID_CARD';
        }
        if (str_contains($message, 'CVV') || str_contains($message, 'CVC')) {
            return 'INVALID_CVV';
        }
        if (str_contains($message, 'EXPIRED') || str_contains($message, 'EXPIRY')) {
            return 'EXPIRED_CARD';
        }
        if (str_contains($message, 'INSUFFICIENT')) {
            return 'INSUFFICIENT_FUNDS';
        }
        if (str_contains($message, 'DECLINED') || str_contains($message, 'DO NOT HONOR')) {
            return 'DO_NOT_HONOR';
        }
        if (str_contains($message, 'DUPLICATE')) {
            return 'DUPLICATE_TXN';
        }

        return 'CARD_DECLINED';
    }

    /**
     * الرسالة العربية من الرمز الموحد
     */
    public static function toArabic(string $unifiedCode): string
    {
        return self::$arabicMessages[$unifiedCode]
            ?? self::$arabicMessages['UNKNOWN'];
    }

    /**
     * هل الخطأ قابل للمحاولة مجدداً؟
     */
    public static function isRetryable(string $unifiedCode): bool
    {
        return in_array($unifiedCode, ['GATEWAY_ERROR', 'NETWORK_ERROR', 'DUPLICATE_TXN']);
    }

    /**
     * هل يجب إيقاف المحاولات نهائياً؟
     */
    public static function isHardBlock(string $unifiedCode): bool
    {
        return in_array($unifiedCode, ['STOLEN_CARD', 'DO_NOT_HONOR']);
    }

    /**
     * بناء response موحد مع error code
     */
    public static function buildErrorResponse(
        string $unifiedCode,
        string $reference    = '',
        float  $amount       = 0,
        string $currency     = '',
        string $rawMessage   = ''
    ): array {
        return [
            'success'        => false,
            'status'         => 'declined',
            'transaction_id' => '',
            'reference'      => $reference,
            'amount'         => $amount,
            'currency'       => $currency,
            'message'        => self::toArabic($unifiedCode),
            'error_code'     => $unifiedCode,
            'raw_message'    => $rawMessage,
            'requires_3ds'   => false,
            'client_secret'  => '',
            'redirect_url'   => '',
            'decline_code'   => $unifiedCode,
            'retryable'      => self::isRetryable($unifiedCode),
            'hard_block'     => self::isHardBlock($unifiedCode),
        ];
    }
}
