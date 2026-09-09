<?php
/**
 * DI PARMA | NuveiAdapter (SafeCharge)
 * API: https://secure.safecharge.com/ppp/api/v1/
 */
// ─── تعريف GatewayErrorMapper ────────────────────────────
if (!class_exists('GatewayErrorMapper')) {
    class GatewayErrorMapper {
        public static function buildErrorResponse(
            string $errorCode, 
            string $reference, 
            float $amount = 0, 
            string $currency = 'USD', 
            string $message = ''
        ): array {
            $errorMessages = [
                'INVALID_CARD' => 'رقم البطاقة غير صحيح',
                'EXPIRED_CARD' => 'البطاقة منتهية الصلاحية',
                'INVALID_CVV' => 'رمز CVV غير صحيح',
                'INSUFFICIENT_FUNDS' => 'الرصيد غير كافٍ',
                'DUPLICATE_TXN' => 'عملية مكررة',
                'LIMIT_EXCEEDED' => 'تم تجاوز الحد المسموح',
                'CARD_DECLINED' => 'تم رفض البطاقة',
                'GATEWAY_ERROR' => 'خطأ في بوابة الدفع',
                'INVALID_AMOUNT' => 'المبلغ غير صالح',
                'AUTH_FAILED' => 'فشل التحقق من البطاقة',
                'CAPTURE_FAILED' => 'فشل تحصيل المبلغ',
                'CANCEL_FAILED' => 'فشل إلغاء العملية',
                'REFUND_FAILED' => 'فشل استرداد المبلغ',
            ];

            return [
                'success' => false,
                'status' => 'failed',
                'reference' => $reference,
                'amount' => $amount,
                'currency' => $currency,
                'error_code' => $errorCode,
                'message' => $message ?: ($errorMessages[$errorCode] ?? 'حدث خطأ في الدفع'),
                'retryable' => in_array($errorCode, ['INSUFFICIENT_FUNDS', 'LIMIT_EXCEEDED']),
                'hard_block' => in_array($errorCode, ['INVALID_CARD', 'EXPIRED_CARD', 'CARD_DECLINED']),
                'requires_3ds' => false,
                'client_secret' => '',
                'redirect_url' => '',
                'decline_code' => $errorCode,
            ];
        }
    }
}
// ─── نهاية GatewayErrorMapper ────────────────────────────

// ─── تحميل ملف .env يدوياً ──────────────────────────────
function loadEnvFile($path) {
    if (!file_exists($path)) {
        return;
    }
    
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // تخطي التعليقات
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        
        // البحث عن علامة =
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // إزالة علامات التنصيص إن وجدت
            if (strpos($value, '"') === 0 || strpos($value, "'") === 0) {
                $value = substr($value, 1, -1);
            }
            
            // تعيين المتغير عبر putenv و $_ENV و $_SERVER
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

// تحميل .env من المسار الصحيح
$envPaths = [
    __DIR__ . '/../.env',          // إذا كان NuveiAdapter في مجلد فرعي
    __DIR__ . '/.env',              // إذا كان في نفس المجلد
    dirname(__DIR__) . '/.env',     // مجلد الأب
];

foreach ($envPaths as $path) {
    if (file_exists($path)) {
        loadEnvFile($path);
        break;
    }
}
// ─── نهاية تحميل .env ────────────────────────────────────

class NuveiAdapter implements GatewayAdapterInterface {

    private string $merchantId;
    private string $siteId;
    private string $secretKey;
    private string $baseUrl = 'https://secure.nuvei.com/ppp/api/v1';
    private string $logFile;

    public function __construct() {
        $this->merchantId = getenv('NUVEI_MERCHANT_ID') ?: '';
        $this->siteId     = getenv('NUVEI_SITE_ID')     ?: '';
        $this->secretKey  = getenv('NUVEI_SECRET_KEY')  ?: '';
        $this->logFile    = defined('LOGS_PATH') ? LOGS_PATH.'/nuvei.log' : __DIR__.'/../../logs/nuvei.log';
        if(!is_dir(dirname($this->logFile))) @mkdir(dirname($this->logFile),0755,true);
    }
    

    public function getName(): string { return 'nuvei'; }

    public function supports(string $mode): bool {
        $mode = strtoupper(trim($mode));
        return in_array($mode, ['2D', '3D', 'HOLD', 'CAPTURE', 'CANCEL'], true);
    }

    public function normalizeError(array $rawResponse): string {
        $raw = $rawResponse['gwErrorReason'] ?? $rawResponse['reason'] ?? $rawResponse['errCode'] ?? '';
        if (is_string($raw) && $raw !== '') {
            $upper = strtoupper($raw);
            if (str_contains($upper, 'INVALID')) return 'INVALID_CARD';
            if (str_contains($upper, 'EXPIRED')) return 'EXPIRED_CARD';
            if (str_contains($upper, 'CVV')) return 'INVALID_CVV';
            if (str_contains($upper, 'INSUFFICIENT')) return 'INSUFFICIENT_FUNDS';
            if (str_contains($upper, 'DUPLICATE')) return 'DUPLICATE_TXN';
            if (str_contains($upper, 'LIMIT')) return 'LIMIT_EXCEEDED';
        }
        return 'CARD_DECLINED';
    }

    public function buildIdempotencyKey(string $reference, float $amount): string {
        return 'idemp_nuvei_' . hash('sha256', $reference . '|' . $amount . '|' . (getenv('ENCRYPTION_KEY') ?: 'diparma'));
    }

    public function charge(array $payload): array {
        $reference = $payload['reference'] ?? 'N'.time();
        $amount    = floatval($payload['amount'] ?? 0);
        $currency  = strtoupper($payload['currency'] ?? 'USD');

        if ($amount <= 0) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $reference, $amount, $currency, 'المبلغ غير صالح');
        }

        $ccNumber = $payload['card_number'] ?? $payload['cc_number'] ?? '';
        $ccExp    = $payload['card_expiry'] ?? $payload['cc_expiry'] ?? '';
        $ccCvv    = $payload['cvv2'] ?? $payload['card_cvv'] ?? $payload['cc_cvv'] ?? '';

        if ($ccNumber === '' || $ccExp === '' || $ccCvv === '') {
            return GatewayErrorMapper::buildErrorResponse('INVALID_CARD', $reference, $amount, $currency, 'بيانات البطاقة غير مكتملة');
        }

        $payload['cc_number'] = $ccNumber;
        $payload['cc_expiry'] = $ccExp;
        $payload['cc_cvv']    = $ccCvv;

        return $this->chargeCard($payload);
    }

    public function hold(array $payload): array {
        // Auth (Pre-Authorization) — يحجز المبلغ بدون تحصيل فعلي
        if (empty($this->merchantId)) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $payload['reference'] ?? '');
        }

        $ts       = date('YmdHis');
        $amount   = number_format(floatval($payload['amount'] ?? 0), 2, '.', '');
        $currency = strtoupper($payload['currency'] ?? 'USD');
        $ref      = $payload['reference'] ?? 'HOLD' . time();
        $email    = $payload['email'] ?? 'guest@diparmas.com';
        $ccNum    = $payload['card_number'] ?? $payload['cc_number'] ?? '';
        $ccExp    = $payload['card_expiry'] ?? $payload['cc_expiry'] ?? '';
        $ccCvv    = $payload['cvv2'] ?? $payload['card_cvv'] ?? $payload['cc_cvv'] ?? '';
        $name     = $payload['name'] ?? 'Customer';
        $nameParts = preg_split('/\s+/', trim($name), 2) ?: ['Customer'];

        if ($ccNum === '' || $ccExp === '' || $ccCvv === '') {
            return GatewayErrorMapper::buildErrorResponse('INVALID_CARD', $ref, (float)$amount, $currency, 'بيانات البطاقة غير مكتملة');
        }

        $expParts = explode('/', str_replace('-', '/', $ccExp));
        $expMonth = str_pad($expParts[0] ?? '01', 2, '0', STR_PAD_LEFT);
        $expYear  = strlen($expParts[1] ?? '25') == 2 ? '20' . ($expParts[1]) : ($expParts[1] ?? '2025');

        // Nuvei /payment.do checksum: merchantId+siteId+clientRequestId+amount+currency+timeStamp+secretKey
        $checksum = hash('sha256', $this->merchantId.$this->siteId.$ref.$amount.$currency.$ts.$this->secretKey);

        $body = [
            'merchantId'      => $this->merchantId,
            'merchantSiteId'  => $this->siteId,
            'clientRequestId' => $ref,
            'clientUniqueId'  => $ref,
            'amount'          => $amount,
            'currency'        => $currency,
            'timeStamp'       => $ts,
            'checksum'        => $checksum,
            'userTokenId'     => $email,
            'transactionType' => 'Auth',   // ← حجز فقط بدون تحصيل
            'paymentOption'   => [
                'card' => [
                    'cardNumber'      => $ccNum,
                    'cardHolderName'  => $name,
                    'expirationMonth' => $expMonth,
                    'expirationYear'  => $expYear,
                    'CVV'             => $ccCvv,
                ]
            ],
            'billingAddress'  => [
                'email'     => $email,
                'firstName' => $nameParts[0] ?: 'Customer',
                'lastName'  => $nameParts[1] ?? 'Client',
                'country'   => strtoupper(substr($payload['country'] ?? 'AE', 0, 2)),
                'city'      => trim($payload['city'] ?? 'Dubai') ?: 'Dubai',
                'address'   => trim($payload['address'] ?? 'Al Barsha 1, Dubai, UAE') ?: 'Al Barsha 1, Dubai, UAE',
                'zip'       => trim($payload['zip'] ?? '00000') ?: '00000',
            ],
            'deviceDetails'   => [
                'deviceType'       => 'DESKTOP',
                'ipAddress'        => filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ? $_SERVER['REMOTE_ADDR'] : null,
                'browserUserAgent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0',
            ],
        ];

        $res = $this->post('/payment.do', $body);

        if (in_array($res['transactionStatus'] ?? '', ['APPROVED', 'SUCCESS']) ||
            ($res['status'] ?? '') === 'SUCCESS') {
            $this->log("✓ Hold/Auth: {$ref} | txId: " . ($res['transactionId'] ?? ''));
            return [
                'success'        => true,
                'status'         => 'authorized',
                'transaction_id' => $res['transactionId'] ?? $res['gwTransactionId'] ?? '',
                'approval_code'  => $res['authCode'] ?? '',
                'rrn'            => $res['rrn'] ?? '',
                'reference'      => $ref,
                'amount'         => floatval($amount),
                'currency'       => $currency,
                'message'        => '✅ تم حجز المبلغ عبر Nuvei (Auth)',
                'error_code'     => '',
                'requires_3ds'   => false,
                'client_secret'  => '',
                'redirect_url'   => '',
                'decline_code'   => '',
                'retryable'      => false,
                'hard_block'     => false,
            ];
        }

        $errCode = $this->normalizeError($res);
        $this->log("✗ Hold failed: " . json_encode($res));
        return GatewayErrorMapper::buildErrorResponse($errCode, $ref, (float)$amount, $currency,
            $res['gwErrorReason'] ?? $res['reason'] ?? $res['errCode'] ?? 'Nuvei auth failed');
    }

        // ── توليد checksum ──────────────────────────────────────
    private function buildChecksum(array $fields): string
    {
        return hash('sha256', implode('', $fields));
    }

        // ── طلب Session Token ──────────────────────────────────
    public function getSessionToken(): array
    {
        $ts  = date('YmdHis');
        $cri = uniqid('diparma_', true);
        // Nuvei checksum: merchantId + merchantSiteId + clientRequestId + timeStamp + secretKey
        $checksum = $this->buildChecksum([
            $this->merchantId,
            $this->siteId,
            $cri,
            $ts,
            $this->secretKey,
        ]);

        return $this->request('getSessionToken', [
            'merchantId'      => $this->merchantId,
            'merchantSiteId'  => $this->siteId,
            'clientRequestId' => $cri,
            'timeStamp'       => $ts,
            'checksum'        => $checksum,
        ]);
    }

    public function capture(string $transactionId, ?float $amount = null): array {
        // Settle — تحصيل حجز سابق مباشرة عبر settleTransaction
        if (empty($transactionId)) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', '', 0, '', 'transactionId مطلوب للـ Capture');
        }
        if (empty($this->merchantId)) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', '', 0, '', 'NUVEI credentials missing');
        }

        $ts  = date('YmdHis');
        $ref = 'CAP' . time();
        $amt = number_format(floatval($amount ?? 0), 2, '.', '');

        $body = [
            'merchantId'           => $this->merchantId,
            'merchantSiteId'       => $this->siteId,
            'clientRequestId'      => $ref,
            'clientUniqueId'       => $ref,
            'amount'               => $amt,
            'currency'             => 'USD',
            'relatedTransactionId' => $transactionId,
            'timeStamp'            => $ts,
            'checksum'             => $this->checksum($ref, $ts),
        ];

        $res      = $this->post('/settleTransaction.do', $body);
        $success  = in_array(strtoupper((string)($res['transactionStatus'] ?? '')), ['APPROVED', 'SUCCESS'], true)
                    || ($res['status'] ?? '') === 'SUCCESS';

        if ($success) {
            $this->log("✓ Capture: {$transactionId} | ref={$ref}");
            return [
                'success'        => true,
                'status'         => 'captured',
                'transaction_id' => $res['transactionId'] ?? $transactionId,
                'reference'      => $res['clientUniqueId'] ?? $ref,
                'amount'         => floatval($amount ?? 0),
                'currency'       => 'USD',
                'message'        => '✅ تم تحصيل المبلغ عبر Nuvei',
                'approval_code'  => $res['authCode'] ?? '',
                'error_code'     => '',
                'requires_3ds'   => false,
                'client_secret'  => '',
                'redirect_url'   => '',
                'decline_code'   => '',
                'retryable'      => false,
                'hard_block'     => false,
            ];
        }

        $errCode = $this->normalizeError($res);
        $this->log("✗ Capture failed: " . json_encode($res));
        return GatewayErrorMapper::buildErrorResponse($errCode, $transactionId, floatval($amount ?? 0), 'USD',
            $res['gwErrorReason'] ?? $res['reason'] ?? $res['errCode'] ?? 'Nuvei capture failed');
    }

    public function cancel(string $transactionId, string $reason = 'requested_by_customer'): array {
        // Void — إلغاء عملية مباشرة
        if (empty($transactionId)) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', '', 0, '', 'transactionId مطلوب للـ Void');
        }
        if (empty($this->merchantId)) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', '', 0, '', 'NUVEI credentials missing');
        }

        $ts  = date('YmdHis');
        $ref = 'VOD' . time();

        $body = [
            'merchantId'           => $this->merchantId,
            'merchantSiteId'       => $this->siteId,
            'clientRequestId'      => $ref,
            'clientUniqueId'       => $ref,
            'amount'               => '0.00',
            'currency'             => 'USD',
            'relatedTransactionId' => $transactionId,
            'timeStamp'            => $ts,
            'checksum'             => $this->checksum($ref, $ts),
        ];

        $res     = $this->post('/voidTransaction.do', $body);
        $success = in_array(strtoupper((string)($res['transactionStatus'] ?? '')), ['APPROVED', 'SUCCESS'], true)
                   || ($res['status'] ?? '') === 'SUCCESS';

        if ($success) {
            $this->log("✓ Void: {$transactionId}");
            return [
                'success'        => true,
                'status'         => 'cancelled',
                'transaction_id' => $transactionId,
                'reference'      => $ref,
                'amount'         => 0,
                'currency'       => 'USD',
                'message'        => '✅ تم إلغاء العملية عبر Nuvei',
                'error_code'     => '',
                'requires_3ds'   => false,
                'client_secret'  => '',
                'redirect_url'   => '',
                'decline_code'   => '',
                'retryable'      => false,
                'hard_block'     => false,
            ];
        }

        $errCode = $this->normalizeError($res);
        $this->log("✗ Void failed: " . json_encode($res));
        return GatewayErrorMapper::buildErrorResponse($errCode, $transactionId, 0, '',
            $res['gwErrorReason'] ?? $res['reason'] ?? $res['errCode'] ?? 'Nuvei void failed');
    }

    // ── توليد checksum (الترتيب الصحيح الموثّق من Nuvei) ──
    // getSessionToken: merchantId + siteId + clientRequestId + timeStamp + secretKey
    // payment/order:   merchantId + siteId + clientRequestId + timeStamp + secretKey
    private function checksum(string $clientRequestId, string $timeStamp): string {
        return hash('sha256', $this->merchantId.$this->siteId.$clientRequestId.$timeStamp.$this->secretKey);
    }

    // ── فتح session ───────────────────────────────────────
    public function openOrder(array $payload): array {
        if(empty($this->merchantId)) return ['success'=>false,'message'=>'NUVEI credentials missing'];

        $ts       = date('YmdHis');
        $amount   = number_format(floatval($payload['amount'] ?? 0), 2, '.', '');
        $currency = strtoupper($payload['currency'] ?? 'USD');
        $ref      = $payload['reference'] ?? 'ORD'.time();
        $email    = $payload['email']     ?? 'guest@diparmas.com';
        $siteUrl  = defined('SITE_URL') ? SITE_URL : 'https://diparmas.com';

        $checksum = $this->checksum($ref, $ts);

        $body = [
            'merchantId'     => $this->merchantId,
            'merchantSiteId' => $this->siteId,
            'clientRequestId'=> $ref,
            'amount'         => $amount,
            'currency'       => $currency,
            'timeStamp'      => $ts,
            'checksum'       => $checksum,
            'userTokenId'    => $email,
            'billingAddress' => ['email' => $email],
            'successUrl'     => $siteUrl.'/crypto_confirm.php?ref='.$ref.'&gateway=nuvei',
            'failureUrl'     => $siteUrl.'/checkout_router.php?error=payment_failed',
            'pendingUrl'     => $siteUrl.'/api/webhook.php?gateway=nuvei',
            'backUrl'        => $siteUrl.'/checkout_router.php',
            'notificationUrl'=> $siteUrl.'/api/webhook.php?gateway=nuvei',
        ];

        $res = $this->post('/openOrder.do', $body);

        if(!empty($res['sessionToken']) && ($res['status'] ?? '') === 'SUCCESS') {
            $this->log("✓ OpenOrder: {$ref} | sessionToken: ".substr($res['sessionToken'],0,20));
            return [
                'success'       => true,
                'session_token' => $res['sessionToken'],
                'checkout_url'  => 'https://secure.nuvei.com/ppp/purchase.do?sessionToken='.$res['sessionToken'],
                'reference'     => $ref,
                'provider'      => 'nuvei',
            ];
        }

        $this->log("✗ OpenOrder failed: ".json_encode($res));
        return ['success'=>false,'message'=>$res['errCode'].' — '.($res['reason'] ?? 'Nuvei error')];
    }

    // ── دفع مباشر بالبطاقة (API v1) ─────────────────────
    public function chargeCard(array $payload): array {
    if(empty($this->merchantId)) {
        return ['success'=>false,'message'=>'NUVEI credentials missing'];
    }

    // ─── الخطوة 1: الحصول على session token ───
    $ts  = date('YmdHis');
    $cri = uniqid('diparma_', true);
    $checksum = hash('sha256', $this->merchantId . $this->siteId . $cri . $ts . $this->secretKey);
    
    $sessionBody = [
        'merchantId'      => $this->merchantId,
        'merchantSiteId'  => $this->siteId,
        'clientRequestId' => $cri,
        'timeStamp'       => $ts,
        'checksum'        => $checksum,
    ];
    
    $sessionRes = $this->post('/getSessionToken.do', $sessionBody);
    
    if (($sessionRes['status'] ?? '') !== 'SUCCESS' || empty($sessionRes['sessionToken'])) {
        $this->log("✗ ChargeCard: session token failed: ".json_encode($sessionRes));
        return [
            'success' => false,
            'message' => 'Nuvei session token failed: ' . ($sessionRes['reason'] ?? $sessionRes['errCode'] ?? 'Unknown'),
            'raw' => $sessionRes
        ];
    }
    $sessionToken = $sessionRes['sessionToken'];

    // ─── الخطوة 2: تنفيذ الدفع ───
    $ts       = date('YmdHis');
    $amount   = number_format(floatval($payload['amount'] ?? 0), 2, '.', '');
    $currency = strtoupper($payload['currency'] ?? 'USD');
    $ref      = $payload['reference'] ?? 'ORD'.time();
    $email    = $payload['email'] ?? 'guest@diparmas.com';
    $ccNum    = $payload['cc_number'] ?? '';
    $ccExp    = $payload['cc_expiry'] ?? '';
    $ccCvv    = $payload['cc_cvv'] ?? '';
    $name     = $payload['name'] ?? 'Customer';
    $nameParts = preg_split('/\s+/', trim($name), 2) ?: ['Customer'];
    $ipAddress = filter_var($payload['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''), FILTER_VALIDATE_IP)
        ? ($payload['ip_address'] ?? $_SERVER['REMOTE_ADDR'])
        : null;

    // تحليل تاريخ الانتهاء
    $expParts = explode('/', str_replace('-', '/', $ccExp));
    $expMonth = str_pad($expParts[0] ?? '01', 2, '0', STR_PAD_LEFT);
    $expYear  = strlen($expParts[1] ?? '25') == 2 ? '20'.($expParts[1]) : ($expParts[1] ?? '2025');

    // Nuvei /payment.do checksum: merchantId+siteId+clientRequestId+amount+currency+timeStamp+secretKey
    $paymentChecksum = hash('sha256', $this->merchantId . $this->siteId . $ref . $amount . $currency . $ts . $this->secretKey);

    $body = [
        'sessionToken'     => $sessionToken,  // ← مهم: إضافة sessionToken
        'merchantId'       => $this->merchantId,
        'merchantSiteId'   => $this->siteId,
        'clientRequestId'  => $ref,
        'clientUniqueId'   => $ref,
        'amount'           => $amount,
        'currency'         => $currency,
        'timeStamp'        => $ts,
        'checksum'         => $paymentChecksum,
        'userTokenId'      => $email,
        'paymentOption'    => [
            'card' => [
                'cardNumber'        => $ccNum,
                'cardHolderName'    => $name,
                'expirationMonth'   => $expMonth,
                'expirationYear'    => $expYear,
                'CVV'               => $ccCvv,
            ]
        ],
        'billingAddress'   => [
            'email'     => $email,
            'firstName' => $nameParts[0] ?: 'Customer',
            'lastName'  => $nameParts[1] ?? 'Client',
            'country'   => strtoupper(substr($payload['country'] ?? 'AE', 0, 2)),
            'city'      => trim($payload['city'] ?? 'Dubai') ?: 'Dubai',
            'address'   => trim($payload['address'] ?? 'Al Barsha 1, Dubai, UAE') ?: 'Al Barsha 1, Dubai, UAE',
            'zip'       => trim($payload['zip'] ?? '00000') ?: '00000',
        ],
        'deviceDetails'    => [
            'deviceType' => 'DESKTOP',
            'ipAddress' => $ipAddress,
            'browserUserAgent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0',
        ],
        'transactionType'  => strtoupper($payload['processing_mode'] ?? '') === '2D'
                                ? 'MOTO'   // Mail/Telephone Order — بدون 3DS
                                : 'Sale',  // شراء عادي مع 3DS
        'urlDetails' => [
            'successUrl' => 'https://diparmas.com/crypto_confirm.php?ref=' . $ref . '&gateway=nuvei',
            'failureUrl' => 'https://diparmas.com/checkout_router.php?error=payment_failed',
            'notificationUrl' => 'https://diparmas.com/api/webhook.php?gateway=nuvei',
        ]
    ];

    $res = $this->post('/payment.do', $body);

    // ─── التحقق من النتيجة ───
    if(in_array($res['transactionStatus'] ?? '', ['APPROVED','SUCCESS']) ||
       ($res['status'] ?? '') === 'SUCCESS') {
        $this->log("✓ ChargeCard: {$ref} | txId: ".($res['transactionId'] ?? ''));
        return [
            'success'        => true,
            'transaction_id' => $res['transactionId'] ?? $res['gwTransactionId'] ?? '',
            'approval_code'  => $res['authCode'] ?? '',
            'rrn'            => $res['rrn'] ?? '',
            'reference'      => $ref,
            'status'         => 'completed',
            'provider'       => 'nuvei',
            'message'        => 'Payment approved',
            'raw'            => $res,
        ];
    }

    $this->log("✗ ChargeCard failed: ".json_encode($res));
    return [
        'success' => false,
        'message' => ($res['gwErrorReason'] ?? $res['reason'] ?? $res['errCode'] ?? 'Nuvei charge failed'),
        'raw' => $res,
    ];
}


    // ── استرداد (Refund) ──────────────────────────────────
    // public function refund(string $transactionId, float $amount, string $currency='USD'): array {
    //     $ts    = date('YmdHis');
    //     $amt   = number_format($amount, 2, '.', '');
    //     $refId = 'REF'.time();
    //     $checksum = $this->checksum($refId, $ts);

    //     $body = [
    //         'merchantId'      => $this->merchantId,
    //         'merchantSiteId'  => $this->siteId,
    //         'clientRequestId' => $refId,
    //         'clientUniqueId'  => $refId,
    //         'amount'          => $amt,
    //         'currency'        => strtoupper($currency),
    //         'relatedTransactionId' => $transactionId,
    //         'timeStamp'       => $ts,
    //         'checksum'        => $checksum,
    //     ];

    //     $res = $this->post('/refundTransaction.do', $body);

    //     return [
    //         'success'   => ($res['transactionStatus'] ?? '') === 'APPROVED',
    //         'message'   => $res['gwErrorReason'] ?? $res['reason'] ?? 'Refund processed',
    //         'refund_id' => $res['transactionId'] ?? '',
    //     ];
    // }

    public function settleTransaction(string $transactionId, float $amount, string $currency = 'USD', string $reference = ''): array {
        if ($this->merchantId === '' || $transactionId === '') {
            return ['success' => false, 'message' => 'Nuvei settlement requires merchant credentials and transaction ID'];
        }

        $ref = $reference !== '' ? $reference : 'SETTLE' . time();
        $ts = date('YmdHis');
        $body = [
            'merchantId' => $this->merchantId,
            'merchantSiteId' => $this->siteId,
            'clientRequestId' => $ref,
            'clientUniqueId' => $ref,
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => strtoupper($currency),
            'relatedTransactionId' => $transactionId,
            'timeStamp' => $ts,
            'checksum' => $this->checksum($ref, $ts),
        ];

        $res = $this->post('/settleTransaction.do', $body);
        $success = in_array(strtoupper((string)($res['transactionStatus'] ?? '')), ['APPROVED', 'SUCCESS'], true)
            || ($res['status'] ?? '') === 'SUCCESS';

        return [
            'success' => $success,
            'status' => $success ? 'completed' : 'failed',
            'transaction_id' => $res['transactionId'] ?? $transactionId,
            'message' => $success ? 'Nuvei Purchase Advice settled' : ($res['gwErrorReason'] ?? $res['reason'] ?? 'Nuvei settlement failed'),
            'raw' => $res,
        ];
    }

    // ── اختبار الاتصال ────────────────────────────────────
    public function testConnection(): array {
        if(empty($this->merchantId)) return ['success'=>false,'message'=>'NUVEI_MERCHANT_ID missing'];

        $ts  = date('YmdHis');
        $reqId = 'test_'.$ts;
        $checksum = $this->checksum($reqId, $ts);

        $body = [
            'merchantId'     => $this->merchantId,
            'merchantSiteId' => $this->siteId,
            'clientRequestId'=> $reqId,
            'timeStamp'      => $ts,
            'checksum'       => $checksum,
        ];

        $start = microtime(true);
        $res   = $this->post('/getSessionToken.do', $body);
        $ms    = round((microtime(true) - $start) * 1000);

        if(!empty($res['sessionToken']) && ($res['status'] ?? '') === 'SUCCESS') {
            return ['success'=>true,'message'=>"✅ Nuvei connected ({$ms}ms)",'ms'=>$ms];
        }

        return ['success'=>false,'message'=>($res['reason'] ?? $res['errCode'] ?? 'Connection failed').' ('.$ms.'ms)','ms'=>$ms];
    }

    // ── Webhook Handler ───────────────────────────────────
    public function handleWebhook(array $data): array {
        $status = $data['transactionStatus'] ?? $data['Status'] ?? '';
        $ref    = $data['clientRequestId']   ?? $data['clientUniqueId'] ?? '';
        $txId   = $data['TransactionID']     ?? $data['transactionId']  ?? '';

        $this->log("Webhook: status=$status ref=$ref txId=$txId");

        return [
            'success'        => in_array(strtoupper($status), ['APPROVED','SUCCESS']),
            'reference'      => $ref,
            'transaction_id' => $txId,
            'status'         => $status,
        ];
    }

    // ── HTTP POST ─────────────────────────────────────────
    private function post(string $endpoint, array $body): array {
        $ch = curl_init($this->baseUrl.$endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json','Accept: application/json'],
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode($res ?: '{}', true) ?: [];
        $data['_http_code'] = $code;
        return $data;
    }

    private function log(string $msg): void {
        @file_put_contents($this->logFile, '['.date('Y-m-d H:i:s')."] $msg\n", FILE_APPEND);
    }



    
    // ── Purchase — شراء مباشر ──────────────────────────────
    public function purchase(array $params): array
    {
        $sessionRes = $this->getSessionToken();
        if (($sessionRes['status'] ?? '') !== 'SUCCESS') {
            return ['success' => false, 'message' => 'Session token failed: ' . ($sessionRes['reason'] ?? 'Unknown')];
        }
        $sessionToken = $sessionRes['sessionToken'];

        $ts        = date('YmdHis');
        $clientReqId = 'POS-PUR-' . strtoupper(substr(uniqid(), 0, 8));
        $amount    = number_format((float)$params['amount'], 2, '.', '');
        $currency  = $params['currency'] ?? 'USD';
        $userToken = $params['user_token_id'] ?? 'guest_' . date('YmdHis');

        // Nuvei checksum: merchantId + merchantSiteId + clientRequestId + amount + currency + timeStamp + secretKey
        $checksum = $this->buildChecksum([
            $this->merchantId,
            $this->siteId,
            $clientReqId,
            $amount,
            $currency,
            $ts,
            $this->secretKey,
        ]);

        $body = [
            'sessionToken'     => $sessionToken,
            'merchantId'       => $this->merchantId,
            'merchantSiteId'   => $this->siteId,
            'clientRequestId'  => $clientReqId,
            'clientUniqueId'   => $clientReqId,
            'amount'           => $amount,
            'currency'         => $currency,
            'userTokenId'      => $userToken,
            'transactionType'   => $params['transactionType'] ?? 'Sale',
            'paymentOption'    => $this->buildCardPaymentOption($params),
            'billingAddress'   => $this->buildBillingAddress($params),
            'timeStamp'        => $ts,
            'checksum'         => $checksum,
            'deviceDetails'    => $this->buildDeviceDetails($params),
            'urlDetails'       => [
                'successUrl'  => defined('NUVEI_SUCCESS_URL') ? NUVEI_SUCCESS_URL : 'https://diparmas.com/crypto_confirm.php',
                'failureUrl'  => defined('NUVEI_CANCEL_URL')  ? NUVEI_CANCEL_URL  : 'https://diparmas.com/checkout.php',
                'notificationUrl' => 'https://diparmas.com/api/webhook.php?gateway=nuvei',
            ],
            'merchantDetails'  => [
                'customField1' => 'TRANSCENDIO_FZ_LLC',
                'customField2' => 'MASHREQ_AE300330000019101562722',
                'customField3' => $params['pos_device'] ?? 'BITEL_IC3600',
                'customField4' => !empty($params['is_moto']) ? 'MOTO' : 'ECOM',
            ],
        ];

        $result = $this->request('payment', $body);
        return $this->normalizeResponse('purchase', $result, $clientReqId);
    }

    // ── Authorization — تفويض (حجز) ───────────────────────
    public function authorize(array $params): array
    {
        $params['transactionType'] = 'Auth';
        return $this->purchase($params);
    }

    // ── Purchase 3D Secure — يرجع redirectUrl لـ OTP ──────
    public function purchase3D(array $params): array
    {
        $sessionRes = $this->getSessionToken();
        if (($sessionRes['status'] ?? '') !== 'SUCCESS') {
            return ['success' => false, 'message' => 'Session token failed: ' . ($sessionRes['reason'] ?? 'Unknown')];
        }
        $sessionToken = $sessionRes['sessionToken'];

        $ts          = date('YmdHis');
        $clientReqId = 'DP3D-' . strtoupper(substr(uniqid(), 0, 8));
        $amount      = number_format((float)$params['amount'], 2, '.', '');
        $currency    = $params['currency'] ?? 'USD';
        $userToken   = $params['user_token_id'] ?? 'guest_' . date('YmdHis');
        $reference   = $params['reference'] ?? $clientReqId;
        $siteUrl     = defined('SITE_URL') ? rtrim(SITE_URL, '/') : 'https://diparmas.com';

        $checksum = $this->buildChecksum([
            $this->merchantId,
            $this->siteId,
            $clientReqId,
            $amount,
            $currency,
            $ts,
            $this->secretKey,
        ]);

        $body = [
            'sessionToken'    => $sessionToken,
            'merchantId'      => $this->merchantId,
            'merchantSiteId'  => $this->siteId,
            'clientRequestId' => $clientReqId,
            'clientUniqueId'  => $reference,
            'amount'          => $amount,
            'currency'        => $currency,
            'userTokenId'     => $userToken,
            'transactionType' => 'Sale',
            'billingAddress'  => $this->buildBillingAddress($params),
            'timeStamp'       => $ts,
            'checksum'        => $checksum,
            'urlDetails'      => [
                'successUrl'      => $siteUrl . '/payment_success.php?ref=' . $reference . '&gateway=nuvei',
                'failureUrl'      => $siteUrl . '/checkout_diparma.php?error=1',
                'notificationUrl' => $siteUrl . '/api/webhook.php?gateway=nuvei',
                'backUrl'         => $siteUrl . '/checkout_diparma.php',
            ],
            'deviceDetails'   => $this->buildDeviceDetails($params),
            'threeD'          => $this->buildThreeDSDetails($params, $siteUrl),
            'merchantDetails' => [
                'customField1' => $reference,
                'customField2' => $params['ledger_addr'] ?? (defined('LEDGER_TRC20_ADDRESS') ? LEDGER_TRC20_ADDRESS : ''),
            ],
        ];

        $result = $this->request('openOrder', $body);

        if (($result['status'] ?? '') === 'SUCCESS' && !empty($result['sessionToken'])) {
            $payUrl = defined('NUVEI_ENVIRONMENT') && NUVEI_ENVIRONMENT === 'live'
                ? 'https://secure.nuvei.com/ppp/purchase.do?sessionToken=' . $result['sessionToken']
                : 'https://ppp-test.nuvei.com/ppp/purchase.do?sessionToken=' . $result['sessionToken'];

            return [
                'success'       => true,
                'requires_3ds'  => true,
                'redirect_url'  => $payUrl,
                'session_token' => $result['sessionToken'],
                'reference'     => $reference,
                'client_req_id' => $clientReqId,
                'amount'        => $amount,
                'currency'      => $currency,
            ];
        }

        return [
            'success' => false,
            'message' => '3D order failed: ' . ($result['reason'] ?? ($result['status'] ?? 'Unknown')),
            'raw'     => $result,
        ];
    }

    // ── Capture — تحصيل بعد تفويض ─────────────────────────
    // public function capture(array $params): array
    // {
    //     $sessionRes = $this->getSessionToken();
    //     if (($sessionRes['status'] ?? '') !== 'SUCCESS') {
    //         return ['success' => false, 'message' => 'Session token failed'];
    //     }

    //     $ts          = date('YmdHis');
    //     $clientReqId = 'POS-CAP-' . strtoupper(substr(uniqid(), 0, 8));
    //     $amount      = number_format((float)$params['amount'], 2, '.', '');
    //     $currency    = $params['currency'] ?? 'USD';
    //     $clientUniqueId = (string)($params['client_unique_id'] ?? $clientReqId);
    //     $authCode    = trim((string)($params['auth_code'] ?? ''));
    //     $authorizedAmount = isset($params['authorized_amount'])
    //         ? (float)$params['authorized_amount']
    //         : null;

    //     if ($authCode === '') {
    //         return ['success' => false, 'message' => 'Nuvei authCode is required for settlement'];
    //     }

    //     if ($authorizedAmount !== null && (float)$amount > $authorizedAmount) {
    //         return [
    //             'success' => false,
    //             'message' => 'Settlement amount cannot exceed the original authorized amount',
    //         ];
    //     }

    //     $checksum = $this->buildChecksum([
    //         $this->merchantId,
    //         $this->siteId,
    //         $clientReqId,
    //         $clientUniqueId,
    //         $amount,
    //         $currency,
    //         $params['related_transaction_id'],
    //         $authCode,
    //         $this->secretKey,
    //     ]);

    //     $result = $this->request('settleTransaction', [
    //         'sessionToken'          => $sessionRes['sessionToken'],
    //         'merchantId'            => $this->merchantId,
    //         'merchantSiteId'        => $this->siteId,
    //         'clientRequestId'       => $clientReqId,
    //         'clientUniqueId'        => $clientUniqueId,
    //         'amount'                => $amount,
    //         'currency'              => $currency,
    //         'relatedTransactionId'  => $params['related_transaction_id'],
    //         'authCode'              => $authCode,
    //         'timeStamp'             => $ts,
    //         'checksum'              => $checksum,
    //     ]);

    //     return $this->normalizeResponse('capture', $result, $clientReqId);
    // }

    // ── Refund — استرداد ───────────────────────────────────
    // public function refund(array $params): array
    // {
    //     $sessionRes = $this->getSessionToken();
    //     if (($sessionRes['status'] ?? '') !== 'SUCCESS') {
    //         return ['success' => false, 'message' => 'Session token failed'];
    //     }

    //     $ts          = date('YmdHis');
    //     $clientReqId = 'POS-REF-' . strtoupper(substr(uniqid(), 0, 8));
    //     $amount      = number_format((float)$params['amount'], 2, '.', '');
    //     $currency    = $params['currency'] ?? 'USD';

    //     $checksum = $this->buildChecksum([
    //         $this->merchantId,
    //         $this->siteId,
    //         $clientReqId,
    //         $params['related_transaction_id'],
    //         $amount,
    //         $currency,
    //         $this->secretKey,
    //     ]);

    //     $result = $this->request('refundTransaction', [
    //         'sessionToken'          => $sessionRes['sessionToken'],
    //         'merchantId'            => $this->merchantId,
    //         'merchantSiteId'        => $this->siteId,
    //         'clientRequestId'       => $clientReqId,
    //         'clientUniqueId'        => $clientReqId,
    //         'amount'                => $amount,
    //         'currency'              => $currency,
    //         'relatedTransactionId'  => $params['related_transaction_id'],
    //         'timeStamp'             => $ts,
    //         'checksum'              => $checksum,
    //         'comment'               => $params['reason'] ?? 'Customer refund request',
    //     ]);

    //     return $this->normalizeResponse('refund', $result, $clientReqId);
    // }

    /**
 * Refund (استرداد) - استرداد مبلغ لعملية سابقة
 * 
 * @param string $transactionId معرف العملية من Nuvei
 * @param float $amount المبلغ المراد استرداده
 * @param string $currency العملة
 * @return array
 */
public function refund(string $transactionId, float $amount, string $currency = 'USD'): array {
    if (empty($transactionId)) {
        return GatewayErrorMapper::buildErrorResponse(
            'GATEWAY_ERROR', 
            '', 
            0, 
            $currency, 
            'transactionId مطلوب للـ Refund'
        );
    }
    
    if (empty($this->merchantId)) {
        return GatewayErrorMapper::buildErrorResponse(
            'GATEWAY_ERROR', 
            '', 
            0, 
            $currency, 
            'NUVEI credentials missing'
        );
    }

    // الحصول على Session Token
    $sessionRes = $this->getSessionToken();
    if (($sessionRes['status'] ?? '') !== 'SUCCESS' || empty($sessionRes['sessionToken'])) {
        return GatewayErrorMapper::buildErrorResponse(
            'GATEWAY_ERROR',
            $transactionId,
            $amount,
            $currency,
            'Session token failed: ' . ($sessionRes['reason'] ?? 'Unknown')
        );
    }
    $sessionToken = $sessionRes['sessionToken'];

    $ts    = date('YmdHis');
    $amt   = number_format($amount, 2, '.', '');
    $refId = 'REF' . time();
    
    $checksum = hash('sha256', 
        $this->merchantId . 
        $this->siteId . 
        $refId . 
        $amt . 
        strtoupper($currency) . 
        $ts . 
        $this->secretKey
    );

    $body = [
        'sessionToken'          => $sessionToken,
        'merchantId'            => $this->merchantId,
        'merchantSiteId'        => $this->siteId,
        'clientRequestId'       => $refId,
        'clientUniqueId'        => $refId,
        'amount'                => $amt,
        'currency'              => strtoupper($currency),
        'relatedTransactionId'  => $transactionId,
        'timeStamp'             => $ts,
        'checksum'             => $checksum,
        'comment'               => 'Refund requested by customer',
    ];

    $res = $this->post('/refundTransaction.do', $body);

    $success = in_array(strtoupper((string)($res['transactionStatus'] ?? '')), ['APPROVED', 'SUCCESS'], true)
                || ($res['status'] ?? '') === 'SUCCESS';

    if ($success) {
        $this->log("✓ Refund: {$transactionId} | ref={$refId}");
        return [
            'success'        => true,
            'status'         => 'refunded',
            'transaction_id' => $res['transactionId'] ?? $transactionId,
            'reference'      => $refId,
            'amount'         => $amount,
            'currency'       => $currency,
            'message'        => '✅ تم استرداد المبلغ عبر Nuvei',
            'error_code'     => '',
            'requires_3ds'   => false,
            'client_secret'  => '',
            'redirect_url'   => '',
            'decline_code'   => '',
            'retryable'      => false,
            'hard_block'     => false,
        ];
    }

    $errCode = $this->normalizeError($res);
    $this->log("✗ Refund failed: " . json_encode($res));
    return GatewayErrorMapper::buildErrorResponse(
        $errCode, 
        $transactionId, 
        $amount, 
        $currency,
        $res['gwErrorReason'] ?? $res['reason'] ?? $res['errCode'] ?? 'Nuvei refund failed'
    );
}

    // ── Void — إلغاء ───────────────────────────────────────
    public function void(array $params): array
    {
        $sessionRes = $this->getSessionToken();
        if (($sessionRes['status'] ?? '') !== 'SUCCESS') {
            return ['success' => false, 'message' => 'Session token failed'];
        }

        $ts          = date('YmdHis');
        $clientReqId = 'POS-VOD-' . strtoupper(substr(uniqid(), 0, 8));
        $amount      = number_format((float)$params['amount'], 2, '.', '');
        $currency    = $params['currency'] ?? 'USD';

        $checksum = $this->buildChecksum([
            $this->merchantId,
            $this->siteId,
            $clientReqId,
            $params['related_transaction_id'],
            $amount,
            $currency,
            $this->secretKey,
        ]);

        $result = $this->request('voidTransaction', [
            'sessionToken'          => $sessionRes['sessionToken'],
            'merchantId'            => $this->merchantId,
            'merchantSiteId'        => $this->siteId,
            'clientRequestId'       => $clientReqId,
            'clientUniqueId'        => $clientReqId,
            'amount'                => $amount,
            'currency'              => $currency,
            'relatedTransactionId'  => $params['related_transaction_id'],
            'timeStamp'             => $ts,
            'checksum'              => $checksum,
        ]);

        return $this->normalizeResponse('void', $result, $clientReqId);
    }

    // ── Balance Inquiry ────────────────────────────────────
    public function balanceInquiry(array $params): array
    {
        $sessionRes = $this->getSessionToken();
        if (($sessionRes['status'] ?? '') !== 'SUCCESS') {
            return ['success' => false, 'message' => 'Session token failed'];
        }

        $ts          = date('YmdHis');
        $clientReqId = 'POS-BAL-' . strtoupper(substr(uniqid(), 0, 8));

        $checksum = $this->buildChecksum([
            $this->merchantId,
            $this->siteId,
            $clientReqId,
            $ts,
            $this->secretKey,
        ]);

        $result = $this->request('getAccountDetails', [
            'sessionToken'     => $sessionRes['sessionToken'],
            'merchantId'       => $this->merchantId,
            'merchantSiteId'   => $this->siteId,
            'clientRequestId'  => $clientReqId,
            'timeStamp'        => $ts,
            'checksum'         => $checksum,
        ]);

        return $this->normalizeResponse('balance', $result, $clientReqId);
    }

    // ── بناء payment option للبطاقة ────────────────────────
    private function buildCardPaymentOption(array $p): array
    {
        $card = [];
        if (!empty($p['card_number'])) {
            $expiry = explode('/', str_replace([' ','-'], '/', $p['card_expiry'] ?? '01/30'));
            // cardHolderName بدون أحرف خاصة — أحرف كبيرة فقط
            $cardName = strtoupper(preg_replace('/[^A-Za-z\s]/', '', $p['card_name'] ?? 'CARDHOLDER'));
            $cardName = trim(preg_replace('/\s+/', ' ', $cardName)) ?: 'CARDHOLDER';

            $card = [
                'card' => [
                    'cardNumber'        => preg_replace('/\D/', '', $p['card_number']),
                    'cardHolderName'    => $cardName,
                    'expirationMonth'   => str_pad($expiry[0] ?? '01', 2, '0', STR_PAD_LEFT),
                    'expirationYear'    => strlen($expiry[1] ?? '30') === 2
                        ? '20' . ($expiry[1] ?? '30')
                        : ($expiry[1] ?? '2030'),
                    'CVV'               => $p['card_cvv'] ?? '',
                ],
            ];
        }
        return $card ?: ['card' => []];
    }

    // ── بناء billing address ────────────────────────────────
    private function buildBillingAddress(array $p): array
    {
        // cardHolderName — أحرف إنجليزية فقط بدون رموز خاصة
        $nameParts = explode(' ', strtoupper(preg_replace('/[^A-Za-z\s]/', '', $p['card_name'] ?? 'CARDHOLDER')));
        return [
            'firstName' => $nameParts[0] ?? 'CARDHOLDER',
            'lastName'  => $nameParts[1] ?? 'CLIENT',
            'email'     => filter_var($p['email'] ?? '', FILTER_VALIDATE_EMAIL)
                            ? $p['email']
                            : '',
            'phone'     => preg_replace('/\D/', '', $p['phone'] ?? '971501234567') ?: '971501234567',
            'country'   => strtoupper(substr($p['country'] ?? 'AE', 0, 2)),
            'city'      => trim($p['city']    ?? 'Dubai') ?: 'Dubai',
            'address'   => trim($p['address'] ?? 'Al Barsha 1, Dubai, UAE') ?: 'Al Barsha 1, Dubai, UAE',
            'zip'       => trim($p['zip']     ?? '00000') ?: '00000',
        ];
    }

    private function buildDeviceDetails(array $p): array
    {
        $ip = trim((string)($p['ip_address'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''));
        return [
            'deviceType' => 'DESKTOP',
            'ipAddress' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null,
            'browser' => $_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0',
            'browserUserAgent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0',
        ];
    }

    private function buildThreeDSDetails(array $p, string $siteUrl): array
    {
        return [
            'notificationUrl' => rtrim($siteUrl, '/') . '/api/webhook.php?gateway=nuvei',
            'challengePreference' => '04',
            'browserDetails' => [
                'browserAcceptHeader' => $_SERVER['HTTP_ACCEPT'] ?? '*/*',
                'browserJavaEnabled' => false,
                'browserLanguage' => substr($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'en-US', 0, 8),
                'browserColorDepth' => 24,
                'browserScreenHeight' => 1080,
                'browserScreenWidth' => 1920,
                'browserTimeZone' => 0,
                'browserUserAgent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0',
                'browserJavaScriptEnabled' => true,
                'ipAddress' => $this->buildDeviceDetails($p)['ipAddress'],
            ],
        ];
    }

        // ── تطبيع الاستجابة ────────────────────────────────────
    private function normalizeResponse(string $type, array $raw, string $clientReqId): array
    {
        $status      = strtoupper($raw['status']            ?? '');
        $txnStatus   = strtoupper($raw['transactionStatus'] ?? $raw['internalRequestId'] ?? '');
        $success     = in_array($status, ['SUCCESS', 'APPROVED'])
                    || in_array($txnStatus, ['APPROVED', 'SUCCESS']);

        return [
            'success'          => $success,
            'txn_type'         => $type,
            'reference'        => $clientReqId,
            'nuvei_txn_id'     => $raw['transactionId']      ?? $raw['internalRequestId'] ?? null,
            'approval_code'    => $raw['authCode']           ?? $raw['approvalCode']      ?? null,
            'rrn'              => $raw['rrn']                 ?? $raw['retrievalReferenceNumber'] ?? $raw['externalTransactionId'] ?? null,
            'status'           => $status,
            'txn_status'       => $txnStatus,
            'amount'           => $raw['totalAmount']        ?? null,
            'currency'         => $raw['currency']           ?? null,
            'message'          => $raw['reason']             ?? ($success ? 'APPROVED' : 'DECLINED'),
            'raw'              => $raw,
            'bank'             => 'MASHREQ_AE300330000019101562722',
            'acquirer'         => 'Mashreq Bank PSC',
            'merchant'         => 'TRANSCENDIO FZ-LLC',
        ];
    }

        // ── HTTP Request ───────────────────────────────────────
    private function request(string $endpoint, array $body): array
    {
        $url = $this->baseUrl . $endpoint . '.do';
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['status' => 'ERROR', 'reason' => 'cURL: ' . $error];
        }
        if ($httpCode !== 200) {
            return ['status' => 'ERROR', 'reason' => 'HTTP ' . $httpCode];
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : ['status' => 'ERROR', 'reason' => 'Invalid JSON response'];
    }
}
