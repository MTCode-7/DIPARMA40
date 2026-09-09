<?php
/**
 * DI PARMA | NuveiAdapter (SafeCharge)
 * API: https://secure.safecharge.com/ppp/api/v1/
 */
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

        $checksum = $this->checksum($ref, $ts);

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

        $res = $this->post('/paymentcc.do', $body);

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
        if(empty($this->merchantId)) return ['success'=>false,'message'=>'NUVEI credentials missing'];

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

        $checksum = $this->checksum($ref, $ts);

        $body = [
            'merchantId'       => $this->merchantId,
            'merchantSiteId'   => $this->siteId,
            'clientRequestId'  => $ref,
            'clientUniqueId'   => $ref,
            'amount'           => $amount,
            'currency'         => $currency,
            'timeStamp'        => $ts,
            'checksum'         => $checksum,
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
        ];

        $res = $this->post('/paymentcc.do', $body);

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
            ];
        }

        $this->log("✗ ChargeCard failed: ".json_encode($res));
        return [
            'success' => false,
            'message' => ($res['gwErrorReason'] ?? $res['reason'] ?? $res['errCode'] ?? 'Nuvei charge failed'),
        ];
    }

    // ── استرداد (Refund) ──────────────────────────────────
    public function refund(string $transactionId, float $amount, string $currency='USD'): array {
        $ts    = date('YmdHis');
        $amt   = number_format($amount, 2, '.', '');
        $refId = 'REF'.time();
        $checksum = $this->checksum($refId, $ts);

        $body = [
            'merchantId'      => $this->merchantId,
            'merchantSiteId'  => $this->siteId,
            'clientRequestId' => $refId,
            'clientUniqueId'  => $refId,
            'amount'          => $amt,
            'currency'        => strtoupper($currency),
            'relatedTransactionId' => $transactionId,
            'timeStamp'       => $ts,
            'checksum'        => $checksum,
        ];

        $res = $this->post('/refundTransaction.do', $body);

        return [
            'success'   => ($res['transactionStatus'] ?? '') === 'APPROVED',
            'message'   => $res['gwErrorReason'] ?? $res['reason'] ?? 'Refund processed',
            'refund_id' => $res['transactionId'] ?? '',
        ];
    }

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
}
