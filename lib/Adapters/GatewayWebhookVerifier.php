<?php
/**
 * ============================================================
 * DI PARMA | GatewayWebhookVerifier
 * التحقق من التوقيع الرقمي لـ Webhooks جميع البوابات
 * ============================================================
 * - Stripe     : HMAC-SHA256 على Stripe-Signature header
 * - PayTabs    : HMAC-SHA256 على Server Key
 * - Authorize.Net : MD5 Hash
 * - Checkout.com  : HMAC-SHA256 على Cko-Signature header
 * - PayPal     : يستخدم PayPalService::verifyWebhook()
 * ============================================================
 */

class GatewayWebhookVerifier
{
    // تحمل tolerance بالثواني للـ timestamp (منع replay attacks)
    private const STRIPE_TOLERANCE_SECONDS = 300; // 5 دقائق

    // ══════════════════════════════════════════════════════════
    // [1] STRIPE
    // ══════════════════════════════════════════════════════════

    /**
     * التحقق من Stripe-Signature header
     *
     * @param string $rawPayload     الـ body الخام (قبل json_decode)
     * @param string $sigHeader      قيمة header: Stripe-Signature
     * @param string $endpointSecret مفتاح الـ Webhook من Stripe Dashboard
     */
    public static function verifyStripe(
        string $rawPayload,
        string $sigHeader,
        string $endpointSecret
    ): bool {
        if (empty($endpointSecret) || empty($sigHeader)) return false;

        $timestamp  = 0;
        $signatures = [];

        foreach (explode(',', $sigHeader) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) continue;
            if ($kv[0] === 't')  $timestamp    = intval($kv[1]);
            if ($kv[0] === 'v1') $signatures[] = $kv[1];
        }

        if ($timestamp === 0 || empty($signatures)) return false;

        // منع Replay Attacks
        if (abs(time() - $timestamp) > self::STRIPE_TOLERANCE_SECONDS) {
            self::log('stripe', 'replay_attack', "timestamp diff=" . abs(time() - $timestamp) . "s");
            return false;
        }

        $expected = hash_hmac('sha256', "$timestamp.$rawPayload", $endpointSecret);

        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                self::log('stripe', 'verified', 'OK');
                return true;
            }
        }

        self::log('stripe', 'invalid_sig', 'signature mismatch');
        return false;
    }

    // ══════════════════════════════════════════════════════════
    // [2] CHECKOUT.COM
    // ══════════════════════════════════════════════════════════

    /**
     * التحقق من Cko-Signature header
     *
     * @param string $rawPayload  الـ body الخام
     * @param string $sigHeader   قيمة header: Cko-Signature
     * @param string $secretKey   Webhook Secret من Checkout Dashboard
     */
    public static function verifyCheckout(
        string $rawPayload,
        string $sigHeader,
        string $secretKey
    ): bool {
        if (empty($secretKey) || empty($sigHeader)) return false;

        $expected = hash_hmac('sha256', $rawPayload, $secretKey);
        $result   = hash_equals($expected, strtolower($sigHeader));

        self::log('checkout', $result ? 'verified' : 'invalid_sig', '');
        return $result;
    }

    // ══════════════════════════════════════════════════════════
    // [3] PAYTABS
    // ══════════════════════════════════════════════════════════

    /**
     * التحقق من PayTabs Webhook
     * PayTabs يرسل signature في body كحقل: signature
     *
     * @param array  $payload    الـ body بعد json_decode
     * @param string $serverKey  PAYTABS_SERVER_KEY
     */
    public static function verifyPayTabs(array $payload, string $serverKey): bool
    {
        if (empty($serverKey)) return false;

        $receivedSig = $payload['signature'] ?? '';
        if (empty($receivedSig)) {
            self::log('paytabs', 'missing_sig', 'no signature in payload');
            return false;
        }

        // PayTabs: HMAC-SHA256 على cart_id + tran_ref + cart_amount + cart_currency
        $data = ($payload['cart_id']       ?? '') .
                ($payload['tran_ref']      ?? '') .
                ($payload['cart_amount']   ?? '') .
                ($payload['cart_currency'] ?? '');

        $expected = hash_hmac('sha256', $data, $serverKey);
        $result   = hash_equals($expected, $receivedSig);

        self::log('paytabs', $result ? 'verified' : 'invalid_sig', '');
        return $result;
    }

    // ══════════════════════════════════════════════════════════
    // [4] AUTHORIZE.NET
    // ══════════════════════════════════════════════════════════

    /**
     * التحقق من Authorize.Net Webhook
     * يستخدم x-anet-signature header مع SHA-512
     *
     * @param string $rawPayload   الـ body الخام
     * @param string $sigHeader    قيمة header: x-anet-signature
     * @param string $signatureKey Webhook Signature Key من Authorize.Net
     */
    public static function verifyAuthorizeNet(
        string $rawPayload,
        string $sigHeader,
        string $signatureKey
    ): bool {
        if (empty($signatureKey) || empty($sigHeader)) return false;

        // Authorize.Net يرسل الـ hash بصيغة: sha512=HASH
        $sigHeader = preg_replace('/^sha512=/i', '', $sigHeader);

        $expected = strtoupper(hash_hmac('sha512', $rawPayload, $signatureKey));
        $result   = hash_equals($expected, strtoupper($sigHeader));

        self::log('authorizenet', $result ? 'verified' : 'invalid_sig', '');
        return $result;
    }

    // ══════════════════════════════════════════════════════════
    // [5] موحّد — يختار البوابة تلقائياً
    // ══════════════════════════════════════════════════════════

    /**
     * نقطة دخول موحدة — يقرأ من .env تلقائياً
     *
     * @param string $gateway    stripe | checkout | paytabs | authorizenet
     * @param string $rawPayload الـ body الخام
     * @param array  $headers    كل الـ headers (مفاتيح lowercase)
     */
    public static function verify(
        string $gateway,
        string $rawPayload,
        array  $headers
    ): bool {
        $gateway = strtolower(trim($gateway));

        switch ($gateway) {
            case 'stripe':
                $sig    = $headers['stripe-signature'] ?? $headers['HTTP_STRIPE_SIGNATURE'] ?? '';
                $secret = getenv('STRIPE_WEBHOOK_SECRET') ?: '';
                return self::verifyStripe($rawPayload, $sig, $secret);

            case 'checkout':
                $sig    = $headers['cko-signature'] ?? $headers['HTTP_CKO_SIGNATURE'] ?? '';
                $secret = getenv('CHECKOUT_WEBHOOK_SECRET') ?: '';
                return self::verifyCheckout($rawPayload, $sig, $secret);

            case 'paytabs':
                $payload   = json_decode($rawPayload, true) ?: [];
                $serverKey = getenv('PAYTABS_SERVER_KEY') ?: '';
                return self::verifyPayTabs($payload, $serverKey);

            case 'authorizenet':
                $sig    = $headers['x-anet-signature'] ?? $headers['HTTP_X_ANET_SIGNATURE'] ?? '';
                $secret = getenv('AUTHNET_SIGNATURE_KEY') ?: '';
                return self::verifyAuthorizeNet($rawPayload, $sig, $secret);

            case 'paypal':
                // يعتمد على PayPalService::verifyWebhook()
                if (!class_exists('PayPalService')) {
                    @include_once __DIR__ . '/../PayPalService.php';
                }
                if (class_exists('PayPalService')) {
                    $webhookId = getenv('PAYPAL_WEBHOOK_ID') ?: '';
                    return PayPalService::getInstance()->verifyWebhook(
                        array_change_key_case($headers, CASE_UPPER),
                        $rawPayload,
                        $webhookId
                    );
                }
                return false;

            default:
                self::log($gateway, 'unsupported', 'gateway not supported');
                return false;
        }
    }

    /**
     * قراءة الـ headers الحالية من PHP
     * مساعد للاستخدام في ملفات webhook.php
     */
    public static function getRequestHeaders(): array
    {
        $headers = [];

        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                $headers[strtolower($k)] = $v;
            }
            return $headers;
        }

        // fallback لـ nginx / FastCGI
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $key = strtolower(str_replace('_', '-', substr($k, 5)));
                $headers[$key] = $v;
            }
        }
        return $headers;
    }

    // ── مساعد ────────────────────────────────────────────────
    private static function log(string $gateway, string $event, string $detail): void
    {
        $logDir  = defined('LOGS_PATH') ? LOGS_PATH : __DIR__ . '/../../logs';
        $logFile = $logDir . '/webhook_verify.log';
        if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
        $line = '[' . date('Y-m-d H:i:s') . "] [$gateway][$event]" . ($detail ? " $detail" : '') . PHP_EOL;
        @file_put_contents($logFile, $line, FILE_APPEND);
    }
}
