<?php
/**
 * ============================================================
 * DI PARMA | DirectPaymentService
 * معالجة البطاقات مباشرة بدون إعادة توجيه
 * يدعم: Stripe + MyFatoorah JS + Checkout.com
 * ============================================================
 */

class DirectPaymentService
{
    private static ?self $instance = null;
    private Database $db;
    private string $logFile;

    private function __construct()
    {
        $this->db      = db();
        $this->logFile = defined('LOGS_PATH') ? LOGS_PATH . '/direct_payment.log' : __DIR__ . '/../logs/direct_payment.log';
        if (!is_dir(dirname($this->logFile))) @mkdir(dirname($this->logFile), 0755, true);
    }

    public static function getInstance(): self
    {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    // ══════════════════════════════════════════════════════════
    // [1] STRIPE — Direct Charge via Payment Intent
    // ══════════════════════════════════════════════════════════

    /**
     * الخطوة 1: إنشاء Payment Intent
     * يُستدعى عند تحميل صفحة الدفع
     */
    public function stripeCreateIntent(float $amount, string $currency, string $reference, array $meta = []): array
    {
        $secretKey = getenv('STRIPE_SECRET_KEY');
        if (empty($secretKey)) {
            return ['success' => false, 'message' => 'STRIPE_SECRET_KEY غير مضبوط'];
        }

        $amountCents = (int)($amount * 100);
        $currency    = strtolower($currency);

        $params = [
            'amount'                    => $amountCents,
            'currency'                  => $currency,
            'payment_method_types[]'    => 'card',
            'metadata[reference]'       => $reference,
            'metadata[platform]'        => 'diparma',
        ];
        foreach ($meta as $k => $v) {
            $params["metadata[$k]"] = $v;
        }

        $ch = curl_init('https://api.stripe.com/v1/payment_intents');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_USERPWD        => $secretKey . ':',
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($res, true);

        if ($code !== 200 || empty($data['client_secret'])) {
            $this->log("Stripe intent failed: " . ($data['error']['message'] ?? $res));
            return ['success' => false, 'message' => $data['error']['message'] ?? 'Stripe error'];
        }

        $this->log("✓ Stripe intent created: {$data['id']} | $amount $currency");

        return [
            'success'           => true,
            'provider'          => 'stripe',
            'payment_intent_id' => $data['id'],
            'client_secret'     => $data['client_secret'],
            'public_key'        => getenv('STRIPE_PUBLIC_KEY'),
            'amount'            => $amount,
            'currency'          => strtoupper($currency),
        ];
    }

    /**
     * الخطوة 2: تأكيد الدفع بعد 3DS
     * يُستدعى من Webhook أو Frontend
     */
    public function stripeConfirm(string $paymentIntentId): array
    {
        $secretKey = getenv('STRIPE_SECRET_KEY');
        $ch = curl_init("https://api.stripe.com/v1/payment_intents/$paymentIntentId");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $secretKey . ':',
            CURLOPT_TIMEOUT        => 10,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data   = json_decode($res, true);
        $status = $data['status'] ?? '';

        // ── استخراج Approval Code الحقيقي من شبكة Visa/Mastercard ──
        // يأتي في: charges.data[0].payment_method_details.card.authorization_code
        $authCode = null;
        $rrn      = null;
        $network  = null;

        $charges = $data['charges']['data'][0]
               ?? $data['latest_charge']
               ?? null;

        // إذا كان latest_charge string نجلبه من API
        if (is_string($charges) && !empty($charges)) {
            $chRes = $this->stripeGetCharge($charges);
            $charges = $chRes;
        }

        if (is_array($charges)) {
            $cardDetails = $charges['payment_method_details']['card'] ?? [];
            $authCode    = $cardDetails['authorization_code'] ?? null;
            $network     = $cardDetails['network']            ?? null;
            // RRN = balance_transaction أو charge id كمرجع خارجي
            $rrn         = $charges['balance_transaction']    ?? $charges['id'] ?? null;
        }

        return [
            'success'       => in_array($status, ['succeeded', 'processing']),
            'status'        => $status,
            'amount'        => ($data['amount'] ?? 0) / 100,
            'currency'      => strtoupper($data['currency'] ?? ''),
            'reference'     => $data['metadata']['reference'] ?? '',
            'approval_code' => $authCode,   // ← كود الموافقة الحقيقي من Visa/MC
            'rrn'           => $rrn,
            'network'       => $network,
            'provider'      => 'stripe',
        ];
    }

    /**
     * جلب تفاصيل Charge من Stripe
     */
    private function stripeGetCharge(string $chargeId): array
    {
        $secretKey = getenv('STRIPE_SECRET_KEY');
        $ch = curl_init("https://api.stripe.com/v1/charges/{$chargeId}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $secretKey . ':',
            CURLOPT_TIMEOUT        => 10,
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        return json_decode($res, true) ?: [];
    }

    // ══════════════════════════════════════════════════════════
    // [2] MYFATOORAH — Session-based Direct Payment
    // ══════════════════════════════════════════════════════════

    /**
     * إنشاء MyFatoorah Session للدفع المباشر
     * يُستخدم مع MyFatoorah.js في الواجهة
     */
    public function myfatoorahCreateSession(float $amount, string $currency, string $reference): array
    {
        $apiKey  = getenv('MYFAOORAH_API_KEY');
        $env     = getenv('MYFAOORAH_ENVIRONMENT') ?: 'sandbox';
        $baseUrl = $env === 'live'
            ? 'https://api.myfatoorah.com'
            : 'https://apitest.myfatoorah.com';

        if (empty($apiKey)) {
            return ['success' => false, 'message' => 'MYFAOORAH_API_KEY غير مضبوط'];
        }

        // [1] جلب طرق الدفع المتاحة
        $ch = curl_init($baseUrl . '/v2/InitiateSession');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['CustomerIdentifier' => $reference]),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_TIMEOUT => 15,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($res, true);

        if ($code !== 200 || empty($data['Data']['SessionId'])) {
            $this->log("MyFatoorah session failed: " . json_encode($data));
            return ['success' => false, 'message' => $data['Message'] ?? 'MyFatoorah session error'];
        }

        $this->log("✓ MyFatoorah session: {$data['Data']['SessionId']}");

        return [
            'success'      => true,
            'provider'     => 'myfatoorah',
            'session_id'   => $data['Data']['SessionId'],
            'country_code' => $data['Data']['CountryCode'] ?? 'ARE',
            'amount'       => $amount,
            'currency'     => $currency,
            'reference'    => $reference,
            'env'          => $env,
        ];
    }

    /**
     * تنفيذ الدفع بعد إدخال البطاقة عبر MyFatoorah.js
     */
    public function myfatoorahExecutePayment(string $sessionId, float $amount, string $currency, string $reference, array $customer = []): array
    {
        $apiKey  = getenv('MYFAOORAH_API_KEY');
        $env     = getenv('MYFAOORAH_ENVIRONMENT') ?: 'sandbox';
        $baseUrl = $env === 'live'
            ? 'https://api.myfatoorah.com'
            : 'https://apitest.myfatoorah.com';

        $siteUrl = defined('SITE_URL') ? SITE_URL : 'https://diparmas.com';

        $body = [
            'SessionId'          => $sessionId,
            'InvoiceValue'       => $amount,
            'CurrencyIso'        => $currency,
            'CustomerName'       => $customer['name']  ?? 'Customer',
            'CustomerEmail'      => $customer['email'] ?? '',
            'CustomerMobile'     => $customer['phone'] ?? '',
            'CustomerReference'  => $reference,
            'CallBackUrl'        => $siteUrl . '/api/orchestrator.php?action=confirm&reference=' . $reference,
            'ErrorUrl'           => $siteUrl . '/crypto.php?error=payment_failed',
            'DisplayCurrencyIso' => $currency,
        ];

        $ch = curl_init($baseUrl . '/v2/ExecutePayment');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_TIMEOUT => 20,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($res, true);

        if ($code !== 200 || !($data['IsSuccess'] ?? false)) {
            $this->log("MyFatoorah execute failed: " . json_encode($data));
            return ['success' => false, 'message' => $data['Message'] ?? 'MyFatoorah execute error'];
        }

        $invoiceData = $data['Data'] ?? [];
        $status      = strtolower($invoiceData['InvoiceStatus'] ?? '');

        // ── استخراج Approval Code الحقيقي من MyFatoorah ──
        // يأتي في: Data.InvoiceTransactions[0].AuthorizationId
        // أو: Data.InvoiceTransactions[0].ReferenceId (RRN)
        $approvalCode = null;
        $rrn          = null;
        $network      = null;

        $transactions = $invoiceData['InvoiceTransactions'] ?? [];
        if (!empty($transactions) && is_array($transactions)) {
            // خذ أول transaction ناجحة
            foreach ($transactions as $txn) {
                $txnStatus = strtolower($txn['TransactionStatus'] ?? '');
                if (in_array($txnStatus, ['succss', 'success', 'paid', 'captured'])) {
                    $approvalCode = $txn['AuthorizationId']   ?? $txn['AuthorizationID'] ?? null;
                    $rrn          = $txn['ReferenceId']        ?? $txn['TransactionId']    ?? null;
                    break;
                }
            }
            // إذا ما فيه ناجحة، خذ أول واحدة
            if (!$approvalCode) {
                $first        = $transactions[0];
                $approvalCode = $first['AuthorizationId']  ?? $first['AuthorizationID'] ?? null;
                $rrn          = $first['ReferenceId']       ?? $first['TransactionId']   ?? null;
            }
        }

        // يمكن أيضاً أن يأتي مباشرة في Data
        if (!$approvalCode) {
            $approvalCode = $invoiceData['AuthorizationId'] ?? $invoiceData['ApprovalCode'] ?? null;
        }

        $this->log("✓ MyFatoorah executed: InvoiceId={$invoiceData['InvoiceId']} status=$status approval=$approvalCode rrn=$rrn");

        return [
            'success'       => in_array($status, ['paid', 'pending']),
            'provider'      => 'myfatoorah',
            'invoice_id'    => $invoiceData['InvoiceId']  ?? '',
            'status'        => $status,
            'amount'        => $amount,
            'currency'      => $currency,
            'reference'     => $reference,
            'approval_code' => $approvalCode,  // ← كود الموافقة الحقيقي
            'rrn'           => $rrn,
            // إذا يحتاج 3DS
            'redirect_url'  => $invoiceData['PaymentURL'] ?? null,
            'requires_3ds'  => !empty($invoiceData['PaymentURL']),
        ];
    }

    // ══════════════════════════════════════════════════════════
    // [3] CHECKOUT.COM — Direct Card Charge
    // ══════════════════════════════════════════════════════════

    /**
     * معالجة البطاقة مباشرة عبر Checkout.com
     * يستخدم Frames.js في الواجهة
     */
    public function checkoutCreatePayment(string $cardToken, float $amount, string $currency, string $reference, array $customer = []): array
    {
        $secretKey = getenv('CHECKOUT_API_KEY');
        if (empty($secretKey)) {
            return ['success' => false, 'message' => 'CHECKOUT_API_KEY غير مضبوط'];
        }

        $amountCents = (int)($amount * 100);
        $siteUrl     = defined('SITE_URL') ? SITE_URL : 'https://diparmas.com';

        $body = [
            'source'    => [
                'type'  => 'token',
                'token' => $cardToken,
            ],
            'amount'    => $amountCents,
            'currency'  => strtoupper($currency),
            'reference' => $reference,
            '3ds'       => ['enabled' => true],
            'customer'  => [
                'name'  => $customer['name']  ?? 'Customer',
                'email' => $customer['email'] ?? '',
            ],
            'success_url' => $siteUrl . '/crypto_confirm.php?ref=' . $reference . '&type=buy',
            'failure_url' => $siteUrl . '/crypto.php?error=payment_failed',
            'metadata'    => ['reference' => $reference, 'platform' => 'diparma'],
        ];

        $ch = curl_init('https://api.checkout.com/payments');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $secretKey,
            ],
            CURLOPT_TIMEOUT => 20,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($res, true);

        // 201 = approved, 202 = pending (3DS required)
        if (!in_array($code, [200, 201, 202])) {
            $this->log("Checkout.com failed: " . json_encode($data));
            return ['success' => false, 'message' => $data['error_codes'][0] ?? 'Checkout.com error'];
        }

        $status       = $data['status'] ?? '';
        $redirectUrl  = $data['_links']['redirect']['href'] ?? null;
        $requires3ds  = !empty($redirectUrl);

        $this->log("✓ Checkout.com payment: {$data['id']} status=$status");

        return [
            'success'      => in_array($status, ['Authorized', 'Pending', 'Captured']),
            'provider'     => 'checkout',
            'payment_id'   => $data['id'] ?? '',
            'status'       => $status,
            'requires_3ds' => $requires3ds,
            'redirect_url' => $redirectUrl,
            'amount'       => $amount,
            'currency'     => $currency,
            'reference'    => $reference,
        ];
    }

    /**
     * إنشاء Public Key لـ Checkout.com Frames
     */
    public function checkoutGetPublicKey(): string
    {
        return getenv('CHECKOUT_PUBLIC_KEY') ?: '';
    }

    // ── مساعد موحّد ─────────────────────────────────────────

    /**
     * نقطة دخول موحّدة — يختار البوابة تلقائياً
     */
    public function processDirectPayment(string $provider, array $payload): array
    {
        return match(strtolower($provider)) {
            'stripe'     => $this->stripeCreateIntent(
                (float)($payload['amount']   ?? 0),
                $payload['currency']         ?? 'USD',
                $payload['reference']        ?? '',
                $payload['meta']             ?? []
            ),
            'myfatoorah' => $this->myfatoorahCreateSession(
                (float)($payload['amount']   ?? 0),
                $payload['currency']         ?? 'USD',
                $payload['reference']        ?? ''
            ),
            'checkout'   => $this->checkoutCreatePayment(
                $payload['card_token']       ?? '',
                (float)($payload['amount']   ?? 0),
                $payload['currency']         ?? 'USD',
                $payload['reference']        ?? '',
                $payload['customer']         ?? []
            ),
            default => ['success' => false, 'message' => "Provider غير مدعوم: $provider"],
        };
    }

    private function log(string $msg): void
    {
        @file_put_contents($this->logFile, '[' . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
    }
}
