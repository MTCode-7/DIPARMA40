<?php
/**
 * ============================================================
 * DI PARMA | WiseService — خدمة Wise API الكاملة
 * ============================================================
 * تغطي: الملفات الشخصية، الأرصدة، التحويلات، الحسابات
 * المستهدفة، الـ Quotes، تتبع الحالة، والـ Webhooks.
 * ============================================================
 */

class WiseService
{
    private string $apiKey;
    private string $baseUrl;
    private ?int   $profileId = null;
    private int    $timeout   = 20;

    // مفتاح الكاش في قاعدة البيانات لـ Profile ID
    private const CACHE_KEY_PROFILE = 'wise_profile_id';

    // ──────────────────────────────────────────────────────────
    public function __construct(string $apiKey, string $baseUrl = 'https://api.wise.com')
    {
        $this->apiKey  = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    // ══════════════════════════════════════════════════════════
    // [1] مصنع (Factory) — يبني النسخة من إعدادات النظام
    // ══════════════════════════════════════════════════════════
    public static function fromConfig(): self
    {
        $config = [];
        if (function_exists('getGatewayConfig')) {
            $config = getGatewayConfig('wise') ?? [];
        }

        $apiKey  = $config['credentials']['api_key'] ?? '';
        $baseUrl = $config['api_base'] ?? 'https://api.wise.com';

        $instance = new self($apiKey, $baseUrl);

        // تحميل Profile ID المخزّن إن وجد
        $cached = self::loadCachedProfileId();
        if ($cached) {
            $instance->profileId = (int) $cached;
        }

        return $instance;
    }

    // ══════════════════════════════════════════════════════════
    // [2] Profile — الملف الشخصي
    // ══════════════════════════════════════════════════════════

    /**
     * جلب جميع الملفات الشخصية (شخصي + تجاري)
     */
    public function getProfiles(): array
    {
        return $this->request('GET', '/v1/profiles');
    }

    /**
     * جلب Profile ID الأول المتاح وتخزينه
     * يعطي الأولوية للنوع التجاري (business)
     */
    public function fetchAndCacheProfileId(): int
    {
        $profiles = $this->getProfiles();

        if (empty($profiles) || !is_array($profiles)) {
            throw new RuntimeException('Wise: لا توجد ملفات شخصية مرتبطة بهذا المفتاح.');
        }

        // تفضيل business على personal
        $chosen = null;
        foreach ($profiles as $p) {
            if (($p['type'] ?? '') === 'business') {
                $chosen = $p;
                break;
            }
        }
        $chosen = $chosen ?? $profiles[0];
        $id     = (int) $chosen['id'];

        $this->profileId = $id;
        self::saveCachedProfileId($id);

        return $id;
    }

    /**
     * الحصول على Profile ID (يجلبه تلقائياً إن لم يكن محفوظاً)
     */
    public function getProfileId(): int
    {
        if ($this->profileId) {
            return $this->profileId;
        }
        return $this->fetchAndCacheProfileId();
    }

    // ══════════════════════════════════════════════════════════
    // [3] Balances — الأرصدة
    // ══════════════════════════════════════════════════════════

    /**
     * جلب جميع أرصدة الحساب
     */
    public function getBalances(): array
    {
        $profileId = $this->getProfileId();
        return $this->request('GET', "/v4/profiles/{$profileId}/balances?types=STANDARD");
    }

    /**
     * جلب رصيد عملة محددة
     */
    public function getBalance(string $currency): ?array
    {
        $balances = $this->getBalances();
        foreach ($balances as $b) {
            if (strtoupper($b['currency'] ?? '') === strtoupper($currency)) {
                return $b;
            }
        }
        return null;
    }

    // ══════════════════════════════════════════════════════════
    // [4] Quote — عرض السعر
    // ══════════════════════════════════════════════════════════

    /**
     * إنشاء Quote لمعرفة الرسوم وسعر الصرف
     *
     * @param float  $amount     المبلغ المُرسَل
     * @param string $sourceCurrency العملة المصدر
     * @param string $targetCurrency العملة الهدف
     * @param string $type       FIXED_SOURCE | FIXED_TARGET
     */
    public function createQuote(
        float  $amount,
        string $sourceCurrency,
        string $targetCurrency,
        string $type = 'FIXED_SOURCE'
    ): array {
        $profileId = $this->getProfileId();

        $body = [
            'sourceCurrency' => strtoupper($sourceCurrency),
            'targetCurrency' => strtoupper($targetCurrency),
            'sourceAmount'   => $amount,
            'payOut'         => 'BANK_TRANSFER',
            'preferredPayIn' => 'BALANCE',
        ];

        if ($type === 'FIXED_TARGET') {
            unset($body['sourceAmount']);
            $body['targetAmount'] = $amount;
        }

        return $this->request('POST', "/v3/profiles/{$profileId}/quotes", $body);
    }

    // ══════════════════════════════════════════════════════════
    // [5] Recipient (Target Account) — الحساب المستهدف
    // ══════════════════════════════════════════════════════════

    /**
     * إنشاء حساب مستهدف (مستلم)
     *
     * @param string $currency    عملة الحساب
     * @param string $type        عنوان السويفت أو IBAN أو ABA إلخ
     * @param array  $details     تفاصيل الحساب (IBAN, SWIFT, abartn...)
     * @param string $name        اسم المستلم
     * @param string $country     كود الدولة (SA, AE, GB...)
     */
    public function createRecipient(
        string $currency,
        string $type,
        array  $details,
        string $name,
        string $country = 'AE'
    ): array {
        $profileId = $this->getProfileId();

        $body = [
            'currency'  => strtoupper($currency),
            'type'      => $type,
            'profile'   => $profileId,
            'accountHolderName' => $name,
            'details'   => $details,
            'ownedByCustomer' => false,
        ];

        return $this->request('POST', '/v1/accounts', $body);
    }

    /**
     * جلب قائمة الحسابات المستهدفة
     */
    public function getRecipients(?string $currency = null): array
    {
        $profileId = $this->getProfileId();
        $qs        = $currency ? "?currency={$currency}" : '';
        return $this->request('GET', "/v1/accounts?profile={$profileId}{$qs}");
    }

    /**
     * جلب متطلبات الحساب المستهدف (الحقول المطلوبة)
     */
    public function getAccountRequirements(string $sourceCurrency, string $targetCurrency, float $sourceAmount): array
    {
        $qs = "?source={$sourceCurrency}&target={$targetCurrency}&sourceAmount={$sourceAmount}";
        return $this->request('GET', '/v1/account-requirements' . $qs);
    }

    // ══════════════════════════════════════════════════════════
    // [6] Transfer — التحويل
    // ══════════════════════════════════════════════════════════

    /**
     * إنشاء تحويل جديد
     *
     * @param int    $quoteId         معرّف الـ Quote
     * @param int    $targetAccountId معرّف الحساب المستهدف
     * @param string $reference       المرجع الداخلي للنظام
     * @param string $details         ملاحظة للمستلم (اختياري)
     */
    public function createTransfer(
        int    $quoteId,
        int    $targetAccountId,
        string $reference,
        string $details = ''
    ): array {
        $body = [
            'targetAccount'           => $targetAccountId,
            'quoteUuid'               => $quoteId,
            'customerTransactionId'   => $reference,
            'details'                 => [
                'reference' => $details ?: $reference,
            ],
        ];

        return $this->request('POST', '/v1/transfers', $body);
    }

    /**
     * تمويل تحويل من رصيد الحساب (Fund Transfer)
     *
     * @param int $transferId معرّف التحويل
     */
    public function fundTransfer(int $transferId): array
    {
        $profileId = $this->getProfileId();

        $body = ['type' => 'BALANCE'];

        return $this->request(
            'POST',
            "/v3/profiles/{$profileId}/transfers/{$transferId}/payments",
            $body
        );
    }

    /**
     * تنفيذ تحويل كامل من البداية للنهاية:
     * Quote → Recipient (اختياري) → Transfer → Fund
     *
     * @param float  $amount
     * @param string $sourceCurrency
     * @param string $targetCurrency
     * @param int    $targetAccountId  معرّف حساب موجود مسبقاً
     * @param string $reference        المرجع الداخلي
     */
    public function executeTransfer(
        float  $amount,
        string $sourceCurrency,
        string $targetCurrency,
        int    $targetAccountId,
        string $reference
    ): array {
        // 1. إنشاء Quote
        $quote = $this->createQuote($amount, $sourceCurrency, $targetCurrency);
        if (empty($quote['id'])) {
            throw new RuntimeException('Wise: فشل إنشاء Quote — ' . json_encode($quote));
        }

        // 2. إنشاء التحويل
        $transfer = $this->createTransfer(
            (int) $quote['id'],
            $targetAccountId,
            $reference
        );
        if (empty($transfer['id'])) {
            throw new RuntimeException('Wise: فشل إنشاء التحويل — ' . json_encode($transfer));
        }

        // 3. تمويل التحويل
        $fund = $this->fundTransfer((int) $transfer['id']);

        return [
            'quote'    => $quote,
            'transfer' => $transfer,
            'fund'     => $fund,
            'transfer_id' => $transfer['id'],
            'status'      => $transfer['status'] ?? 'pending',
        ];
    }

    // ══════════════════════════════════════════════════════════
    // [7] Transfer Status — تتبع الحالة
    // ══════════════════════════════════════════════════════════

    /**
     * جلب حالة تحويل بمعرّفه
     */
    public function getTransferById(int $transferId): array
    {
        return $this->request('GET', "/v1/transfers/{$transferId}");
    }

    /**
     * البحث عن تحويل باستخدام customerTransactionId (المرجع الداخلي)
     */
    public function getTransferByReference(string $reference): ?array
    {
        $profileId = $this->getProfileId();
        $result = $this->request(
            'GET',
            "/v1/transfers?profile={$profileId}&customerTransactionId=" . urlencode($reference)
        );

        if (!empty($result) && is_array($result)) {
            // الـ API يُرجع مصفوفة أو كائن واحد
            return is_array($result[0] ?? null) ? $result[0] : $result;
        }
        return null;
    }

    /**
     * تحويل حالة Wise إلى حالة النظام الموحّدة
     */
    public static function mapStatus(string $wiseStatus): string
    {
        $map = [
            'incoming_payment_waiting' => 'pending',
            'incoming_payment_initiated' => 'pending',
            'processing'    => 'pending',
            'funds_converted' => 'pending',
            'outgoing_payment_sent' => 'completed',
            'funds_refunded' => 'refunded',
            'bounced_back'  => 'failed',
            'charged_back'  => 'chargeback',
            'cancelled'     => 'failed',
            'payout_sent'   => 'completed',
            'completed'     => 'completed',
            'failed'        => 'failed',
            'rejected'      => 'failed',
            'expired'       => 'failed',
        ];

        return $map[strtolower(trim($wiseStatus))] ?? 'pending';
    }

    // ══════════════════════════════════════════════════════════
    // [8] Webhook — التحقق من التوقيع
    // ══════════════════════════════════════════════════════════

    /**
     * التحقق من توقيع Wise Webhook
     *
     * @param string $payload   محتوى الطلب الخام
     * @param string $signature قيمة هيدر X-Signature-SHA256
     * @param string $publicKey مفتاح RSA العام من Wise
     */
    public static function verifyWebhookSignature(
        string $payload,
        string $signature,
        string $publicKey
    ): bool {
        if (empty($publicKey) || empty($signature)) {
            return true; // تخطي التحقق إذا لم يكن مفعّلاً
        }

        $decodedSig = base64_decode($signature);
        $result = openssl_verify($payload, $decodedSig, $publicKey, OPENSSL_ALGO_SHA256);
        return $result === 1;
    }

    // ══════════════════════════════════════════════════════════
    // [9] HTTP Client الداخلي
    // ══════════════════════════════════════════════════════════

    /**
     * تنفيذ طلب HTTP لـ Wise API
     *
     * @param string     $method  GET | POST | PUT | DELETE
     * @param string     $path    مسار الـ endpoint
     * @param array|null $body    بيانات الطلب (لـ POST/PUT)
     */
    public function request(string $method, string $path, ?array $body = null): array
    {
        $url = $this->baseUrl . $path;

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $method = strtoupper($method);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new RuntimeException("Wise cURL error: {$curlError}");
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 400) {
            $errorMsg = $decoded['message']
                ?? $decoded['error']
                ?? $decoded['errors'][0]['message']
                ?? "HTTP {$httpCode}";
            throw new RuntimeException("Wise API error [{$httpCode}]: {$errorMsg}");
        }

        return $decoded ?? [];
    }

    // ══════════════════════════════════════════════════════════
    // [10] كاش Profile ID في قاعدة البيانات
    // ══════════════════════════════════════════════════════════

    private static function loadCachedProfileId(): ?string
    {
        try {
            $db  = db();
            $row = $db->query(
                "SELECT `value` FROM " . DB_PREFIX . "settings WHERE `key` = ? LIMIT 1",
                [self::CACHE_KEY_PROFILE]
            );
            return $row[0]['value'] ?? null;
        } catch (Exception $e) {
            return null;
        }
    }

    private static function saveCachedProfileId(int $id): void
    {
        try {
            $db = db();

            // إنشاء جدول settings إن لم يكن موجوداً
            $db->execute("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "settings` (
                `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `key`        VARCHAR(100) NOT NULL UNIQUE,
                `value`      TEXT         DEFAULT NULL,
                `updated_at` DATETIME     NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // INSERT OR UPDATE
            $db->execute(
                "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `updated_at`)
                 VALUES (?, ?, NOW())
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_at` = NOW()",
                [self::CACHE_KEY_PROFILE, (string) $id]
            );
        } catch (Exception $e) {
            // غير حرج — سيُجلب مجدداً في الطلب التالي
        }
    }
}
