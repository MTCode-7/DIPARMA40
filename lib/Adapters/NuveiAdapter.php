<?php
/**
 * DI PARMA | NuveiAdapter (SafeCharge)
 * API: https://secure.nuvei.com/ppp/api/v1/
 */
require_once __DIR__ . '/GatewayAdapterInterface.php';
require_once __DIR__ . '/GatewayErrorMapper.php';
require_once __DIR__ . '/GatewayLogger.php';

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
        $envUrl = trim((string)(getenv('NUVEI_API_URL') ?: ''));
        $env = strtolower(trim((string)(getenv('NUVEI_ENVIRONMENT') ?: 'live')));
        if ($envUrl !== '') {
            $this->baseUrl = rtrim($envUrl, '/');
        } elseif ($env === 'test' || $env === 'sandbox' || $env === 'int') {
            $this->baseUrl = 'https://ppp-test.nuvei.com/ppp/api/v1';
        } else {
            $this->baseUrl = 'https://secure.nuvei.com/ppp/api/v1';
        }
        $this->logFile    = defined('LOGS_PATH') ? LOGS_PATH.'/nuvei.log' : __DIR__.'/../../logs/nuvei.log';
        if(!is_dir(dirname($this->logFile))) @mkdir(dirname($this->logFile),0755,true);
    }

    /** Live Nuvei first; old SafeCharge host only as fallback. */
    private function apiBases(): array
    {
        $primary = rtrim($this->baseUrl, '/');
        $live = [
            'https://secure.nuvei.com/ppp/api/v1',
            'https://secure.safecharge.com/ppp/api/v1',
        ];
        $test = [
            'https://ppp-test.nuvei.com/ppp/api/v1',
            'https://ppp-test.safecharge.com/ppp/api/v1',
        ];
        $env = strtolower(trim((string)(getenv('NUVEI_ENVIRONMENT') ?: 'live')));
        $pool = ($env === 'test' || $env === 'sandbox' || $env === 'int') ? $test : $live;
        $bases = [$primary];
        foreach ($pool as $base) {
            if (!in_array($base, $bases, true)) {
                $bases[] = $base;
            }
        }
        return $bases;
    }
    

    public function getName(): string { return 'nuvei'; }

    /** Nuvei rejects localhost / http. Always send the live HTTPS site. */
    private function publicSiteUrl(): string
    {
        $env = trim((string)(getenv('NUVEI_PUBLIC_URL') ?: ''));
        $site = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '';
        $peer = defined('PEER_REMOTE_URL') ? rtrim((string) PEER_REMOTE_URL, '/') : '';
        foreach ([$env, $site, $peer, 'https://diparmas.com'] as $url) {
            $url = rtrim((string) $url, '/');
            if ($url !== '' && preg_match('#^https://#i', $url) && !preg_match('#localhost|127\.0\.0\.1#i', $url)) {
                return $url;
            }
        }
        return 'https://diparmas.com';
    }

    private function publicUrlDetails(string $reference = ''): array
    {
        $base = $this->publicSiteUrl();
        $valid = static function (string $url): string {
            $url = trim($url);
            if ($url === '' || !preg_match('#^https://#i', $url) || preg_match('#localhost|127\.0\.0\.1#i', $url)) {
                return '';
            }
            return rtrim($url, ' ');
        };
        // Must match Nuvei Control Panel → My Integration Settings → URLs
        $panelUrl = static function (string $envUrl, string $canonical) use ($valid): string {
            $envUrl = $valid($envUrl);
            if ($envUrl !== '' && (str_contains($envUrl, '/nuvei-') || str_contains($envUrl, '/nuvei_dmn.php'))) {
                return $envUrl;
            }
            return $canonical;
        };
        return [
            'successUrl' => $panelUrl((string) getenv('NUVEI_SUCCESS_URL'), $base . '/nuvei-success.php'),
            'failureUrl' => $panelUrl((string) (getenv('NUVEI_FAILURE_URL') ?: getenv('NUVEI_CANCEL_URL')), $base . '/nuvei-fail.php'),
            'pendingUrl' => $panelUrl((string) getenv('NUVEI_PENDING_URL'), $base . '/nuvei-pending.php'),
            'notificationUrl' => $panelUrl((string) getenv('NUVEI_WEBHOOK_URL'), $base . '/api/nuvei_dmn.php'),
        ];
    }

    /** REST urlDetails — backUrl belongs in Control Panel, not this class. */
    private function restUrlDetails(string $reference = ''): array
    {
        $urls = $this->publicUrlDetails($reference);
        return [
            'successUrl' => $urls['successUrl'],
            'failureUrl' => $urls['failureUrl'],
            'pendingUrl' => $urls['pendingUrl'],
            'notificationUrl' => $urls['notificationUrl'],
        ];
    }

    private function hostedPayUrl(string $sessionToken): string
    {
        $env = strtolower(trim((string) (getenv('NUVEI_ENVIRONMENT') ?: 'live')));
        $host = in_array($env, ['test', 'sandbox', 'int'], true)
            ? 'https://ppp-test.nuvei.com'
            : 'https://secure.safecharge.com';
        return $host . '/ppp/purchase.do?sessionToken=' . rawurlencode($sessionToken);
    }

    private function saleOrAuth(array $params): string
    {
        $type = (string) ($params['transactionType'] ?? 'Sale');
        return in_array($type, ['Sale', 'Auth', 'PreAuth'], true) ? $type : 'Sale';
    }

    public function supports(string $mode): bool {
        $mode = strtoupper(trim($mode));
        return in_array($mode, ['2D', '3D', 'HOLD', 'CAPTURE', 'CANCEL', 'REFUND', 'VOID'], true);
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

        if ($this->nuveiTxnApproved($res)) {
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

        $redir = $this->nuveiRedirectUrl($res);
        if ($redir !== '' || strtoupper((string)($res['transactionStatus'] ?? '')) === 'REDIRECT') {
            return [
                'success' => false,
                'requires_3ds' => true,
                'redirect_url' => $redir,
                'transaction_id' => $res['transactionId'] ?? '',
                'reference' => $ref,
                'message' => '3DS_REQUIRED',
                'raw' => $res,
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

    /**
     * REST /initPayment — 3DS fingerprint + InitAuth3D.
     * Live: https://secure.safecharge.com/ppp/api/v1/initPayment.do
     */
    public function initPayment(array $params): array
    {
        $sessionRes = $this->getSessionToken();
        if (($sessionRes['status'] ?? '') !== 'SUCCESS') {
            return ['success' => false, 'message' => 'Session token failed: ' . ($sessionRes['reason'] ?? 'Unknown')];
        }
        $clientReqId = (string) ($params['client_request_id'] ?? ('INIT-' . strtoupper(substr(uniqid(), 0, 8))));
        $reference = (string) ($params['reference'] ?? $params['client_unique_id'] ?? $clientReqId);
        $amount = number_format((float) ($params['amount'] ?? 0), 2, '.', '');
        $currency = strtoupper((string) ($params['currency'] ?? 'USD'));
        $notify3ds = $this->publicSiteUrl() . '/api/nuvei_dmn.php';
        $body = [
            'sessionToken' => $sessionRes['sessionToken'] ?? '',
            'merchantId' => $this->merchantId,
            'merchantSiteId' => $this->siteId,
            'clientUniqueId' => $reference,
            'clientRequestId' => $clientReqId,
            'amount' => $amount,
            'currency' => $currency,
            'userTokenId' => $params['user_token_id'] ?? $params['email'] ?? ('user_' . date('YmdHis')),
            'paymentOption' => $this->buildCardPaymentOption($params),
            'deviceDetails' => $this->buildDeviceDetails($params),
            'billingAddress' => $this->buildBillingAddress($params),
            'urlDetails' => $this->restUrlDetails($reference),
        ];
        $card = $body['paymentOption']['card'] ?? [];
        $card['threeD'] = ['methodNotificationUrl' => $notify3ds];
        $body['paymentOption']['card'] = $card;
        $res = $this->request('initPayment', $body);
        $ok = ($res['status'] ?? '') === 'SUCCESS';
        return [
            'success' => $ok,
            'status' => $ok ? 'initialized' : 'failed',
            'transaction_id' => $res['transactionId'] ?? '',
            'transaction_type' => $res['transactionType'] ?? 'InitAuth3D',
            'session_token' => $res['sessionToken'] ?? ($sessionRes['sessionToken'] ?? ''),
            'three_d' => $res['paymentOption']['card']['threeD'] ?? [],
            'message' => $ok ? 'Nuvei initPayment' : ($res['reason'] ?? $res['gwErrorReason'] ?? 'initPayment failed'),
            'raw' => $res,
        ];
    }

    /**
     * REST /accountCapture — bank details for APM payout UPO.
     * Live: https://secure.safecharge.com/ppp/api/v1/accountCapture.do
     */
    public function accountCapture(array $params): array
    {
        $sessionRes = $this->getSessionToken();
        if (($sessionRes['status'] ?? '') !== 'SUCCESS') {
            return ['success' => false, 'message' => 'Session token failed: ' . ($sessionRes['reason'] ?? 'Unknown')];
        }
        $body = [
            'sessionToken' => $sessionRes['sessionToken'] ?? '',
            'merchantId' => $this->merchantId,
            'merchantSiteId' => $this->siteId,
            'paymentMethod' => (string) ($params['payment_method'] ?? $params['paymentMethod'] ?? ''),
            'userTokenId' => (string) ($params['user_token_id'] ?? $params['userTokenId'] ?? ''),
            'currencyCode' => strtoupper((string) ($params['currency'] ?? $params['currencyCode'] ?? 'USD')),
            'countryCode' => strtoupper(substr((string) ($params['country'] ?? $params['countryCode'] ?? 'AE'), 0, 2)),
            'languageCode' => (string) ($params['language'] ?? 'en'),
            'urlDetails' => $this->restUrlDetails((string) ($params['reference'] ?? '')),
        ];
        if ($body['paymentMethod'] === '' || $body['userTokenId'] === '') {
            return ['success' => false, 'message' => 'accountCapture requires paymentMethod and userTokenId'];
        }
        if (isset($params['amount']) && (float) $params['amount'] > 0) {
            $body['amount'] = number_format((float) $params['amount'], 2, '.', '');
        }
        $res = $this->request('accountCapture', $body);
        $ok = ($res['status'] ?? '') === 'SUCCESS' || !empty($res['redirectUrl']);
        return [
            'success' => $ok,
            'redirect_url' => $res['redirectUrl'] ?? '',
            'session_token' => $res['sessionToken'] ?? '',
            'message' => $ok ? 'Nuvei accountCapture' : ($res['reason'] ?? 'accountCapture failed'),
            'raw' => $res,
        ];
    }

    /** REST /getPaymentStatus — verify createPayment / same session. */
    public function getPaymentStatus(string $sessionToken): array
    {
        if ($sessionToken === '') {
            return ['success' => false, 'message' => 'sessionToken required'];
        }
        $res = $this->request('getPaymentStatus', ['sessionToken' => $sessionToken]);
        $ok = ($res['status'] ?? '') === 'SUCCESS';
        return [
            'success' => $ok,
            'transaction_id' => $res['transactionId'] ?? '',
            'transaction_status' => $res['transactionStatus'] ?? '',
            'auth_code' => $res['authCode'] ?? '',
            'message' => $ok ? 'Nuvei getPaymentStatus' : ($res['reason'] ?? 'getPaymentStatus failed'),
            'raw' => $res,
        ];
    }

    /**
     * Capture — يدعم عقد الواجهة (string id) ومسار POS (array params).
     * @param string|array $transactionId
     */
    public function capture(string|array $transactionId, ?float $amount = null): array {
        if (is_array($transactionId)) {
            return $this->captureFromParams($transactionId);
        }

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
            'checksum'             => $this->relatedChecksum($ref, $ref, $amt, 'USD', $transactionId, $ts),
            'urlDetails'           => ['notificationUrl' => $this->restUrlDetails()['notificationUrl']],
        ];

        $res      = $this->post('/settleTransaction.do', $body);
        $success  = $this->nuveiTxnApproved($res);

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

    /** Capture من مسار POS (مصفوفة params مع related_transaction_id + auth_code) */
    private function captureFromParams(array $params): array
    {
        $ts          = date('YmdHis');
        $clientReqId = 'POS-CAP-' . strtoupper(substr(uniqid(), 0, 8));
        $amount      = number_format((float)($params['amount'] ?? 0), 2, '.', '');
        $currency    = $params['currency'] ?? 'USD';
        $clientUniqueId = (string)($params['client_unique_id'] ?? $clientReqId);
        $authCode    = trim((string)($params['auth_code'] ?? ''));
        $relatedId   = trim((string)($params['related_transaction_id'] ?? $params['payment_id'] ?? $params['nuvei_txn_id'] ?? ''));
        $authorizedAmount = isset($params['authorized_amount'])
            ? (float)$params['authorized_amount']
            : null;

        if ($relatedId === '') {
            return ['success' => false, 'message' => 'Nuvei Transaction ID (Payment ID) is required for capture'];
        }
        if ($authCode === '') {
            return ['success' => false, 'message' => 'Nuvei Auth Code is required for capture'];
        }

        $checksum = $this->relatedChecksum($clientReqId, $clientUniqueId, $amount, $currency, $relatedId, $ts);

        $result = $this->request('settleTransaction', [
            'merchantId'            => $this->merchantId,
            'merchantSiteId'        => $this->siteId,
            'clientRequestId'       => $clientReqId,
            'clientUniqueId'        => $clientUniqueId,
            'amount'                => $amount,
            'currency'              => $currency,
            'relatedTransactionId'  => $relatedId,
            'authCode'              => $authCode,
            'timeStamp'             => $ts,
            'checksum'              => $checksum,
            'urlDetails'            => ['notificationUrl' => $this->restUrlDetails($clientUniqueId)['notificationUrl']],
        ]);

        return $this->normalizeResponse('capture', $result, $clientReqId);
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
            'checksum'             => $this->relatedChecksum($ref, $ref, '0.00', 'USD', $transactionId, $ts),
        ];

        $res     = $this->post('/voidTransaction.do', $body);
        $success = $this->nuveiTxnApproved($res);

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

    /**
     * REST 1.0 checksum: UTF-8 SHA-256 of concatenated fields (secret last).
     * https://docs.nuvei.com/api/main/indexMain_v1_0.html?json
     */
    private function restChecksum(array $fields): string
    {
        return hash('sha256', implode('', array_map('strval', $fields)));
    }

    // getSessionToken: merchantId + merchantSiteId + clientRequestId + timeStamp + secret
    private function checksum(string $clientRequestId, string $timeStamp): string {
        return $this->restChecksum([
            $this->merchantId,
            $this->siteId,
            $clientRequestId,
            $timeStamp,
            $this->secretKey,
        ]);
    }

    // openOrder / payment: merchantId + merchantSiteId + clientRequestId + amount + currency + timeStamp + secret
    private function paymentChecksum(string $clientRequestId, string $amount, string $currency, string $timeStamp): string
    {
        return $this->restChecksum([
            $this->merchantId,
            $this->siteId,
            $clientRequestId,
            $amount,
            strtoupper($currency),
            $timeStamp,
            $this->secretKey,
        ]);
    }

    // settle/refund/void: merchantId + merchantSiteId + clientRequestId + clientUniqueId + amount + currency + relatedTransactionId + timeStamp + secret
    private function relatedChecksum(
        string $clientRequestId,
        string $clientUniqueId,
        string $amount,
        string $currency,
        string $relatedTransactionId,
        string $timeStamp
    ): string {
        return $this->restChecksum([
            $this->merchantId,
            $this->siteId,
            $clientRequestId,
            $clientUniqueId,
            $amount,
            strtoupper($currency),
            $relatedTransactionId,
            $timeStamp,
            $this->secretKey,
        ]);
    }

    // ── فتح session ───────────────────────────────────────
    public function openOrder(array $payload): array {
        if(empty($this->merchantId)) return ['success'=>false,'message'=>'NUVEI credentials missing'];

        $ts       = date('YmdHis');
        $amount   = number_format(floatval($payload['amount'] ?? 0), 2, '.', '');
        $currency = strtoupper($payload['currency'] ?? 'USD');
        $ref      = $payload['reference'] ?? 'ORD'.time();
        $email    = $payload['email']     ?? 'guest@diparmas.com';
        $checksum = $this->paymentChecksum($ref, $amount, $currency, $ts);

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
            'billingAddress'  => [
                'email' => $email,
                'country' => strtoupper(substr((string) ($payload['country'] ?? 'AE'), 0, 2)),
                'firstName' => 'Customer',
                'lastName' => 'Client',
            ],
            'urlDetails'      => $this->restUrlDetails($ref),
        ];
        $txnType = $this->saleOrAuth($payload);
        if ($txnType !== 'Sale') {
            $body['transactionType'] = $txnType;
        }

        $res = $this->post('/openOrder.do', $body);

        if(!empty($res['sessionToken']) && ($res['status'] ?? '') === 'SUCCESS') {
            $this->log("✓ OpenOrder: {$ref} | sessionToken: ".substr($res['sessionToken'],0,20));
            return [
                'success'       => true,
                'session_token' => $res['sessionToken'],
                'checkout_url'  => $this->hostedPayUrl((string) $res['sessionToken']),
                'reference'     => $ref,
                'provider'      => 'nuvei',
            ];
        }

        $this->log("✗ OpenOrder failed: ".json_encode($res));
        return ['success' => false, 'message' => trim(($res['errCode'] ?? '') . ' — ' . ($res['reason'] ?? 'Nuvei error'), ' —')];
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
        'transactionType'  => 'Sale',
        'urlDetails' => $this->restUrlDetails($ref),
    ];

    $res = $this->post('/payment.do', $body);

    // ─── التحقق من النتيجة ───
    if ($this->nuveiTxnApproved($res)) {
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

    $redir = $this->nuveiRedirectUrl($res);
    $txnSt = strtoupper((string)($res['transactionStatus'] ?? ''));
    if ($redir !== '' || $txnSt === 'REDIRECT') {
        return [
            'success' => false,
            'requires_3ds' => true,
            'redirect_url' => $redir,
            'transaction_id' => $res['transactionId'] ?? '',
            'reference' => $ref,
            'message' => '3DS_REQUIRED',
            'raw' => $res,
        ];
    }

    $this->log("✗ ChargeCard failed: ".json_encode($res));
    return [
        'success' => false,
        'message' => ($res['gwErrorReason'] ?? $res['reason'] ?? $res['errCode'] ?? 'Nuvei charge failed'),
        'raw' => $res,
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
            'checksum' => $this->relatedChecksum($ref, $ref, number_format($amount, 2, '.', ''), $currency, $transactionId, $ts),
            'urlDetails' => ['notificationUrl' => $this->restUrlDetails($ref)['notificationUrl']],
        ];

        $res = $this->post('/settleTransaction.do', $body);
        $success = $this->nuveiTxnApproved($res);

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
        return $this->request($endpoint, $body);
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
        $reference = (string) ($params['reference'] ?? $clientReqId);
        $txnType   = $this->saleOrAuth($params);

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
            'clientUniqueId'   => $reference,
            'amount'           => $amount,
            'currency'         => $currency,
            'userTokenId'      => $userToken,
            'transactionType'  => $txnType,
            'paymentOption'    => $this->buildCardPaymentOption($params),
            'billingAddress'   => $this->buildBillingAddress($params),
            'timeStamp'        => $ts,
            'checksum'         => $checksum,
            'deviceDetails'    => $this->buildDeviceDetails($params),
            'urlDetails'       => $this->restUrlDetails($reference),
            'isMoto'           => !empty($params['is_moto']) ? '1' : '0',
            'merchantDetails'  => [
                'customField1' => 'TRANSCENDIO_FZ_LLC',
                'customField2' => $params['ledger_addr'] ?? $params['ledger_address'] ?? (defined('LEDGER_TRC20_ADDRESS') ? LEDGER_TRC20_ADDRESS : ''),
                'customField3' => $params['pos_device'] ?? 'BITEL_IC3600',
                'customField4' => !empty($params['is_moto']) ? 'MOTO' : 'ECOM',
            ],
        ];
        $authCode = trim((string)($params['auth_code'] ?? $params['approval_code'] ?? ''));
        if ($authCode !== '') {
            $body['authCode'] = $authCode;
        }

        $result = $this->request('payment', $body);
        $this->log('payment ' . $clientReqId . ' ' . json_encode([
            'status' => $result['status'] ?? null,
            'transactionStatus' => $result['transactionStatus'] ?? null,
            'errCode' => $result['errCode'] ?? null,
            'reason' => $result['reason'] ?? null,
            'gwErrorReason' => $result['gwErrorReason'] ?? null,
            'transactionId' => $result['transactionId'] ?? null,
        ]));
        return $this->normalizeResponse('purchase', $result, $clientReqId);
    }

    /**
     * Purchase Advice / Direct Advice (bank MTI 0220, DIRECT_ADVICE_NO_PRE_AUTH).
     * Live Nuvei only — settle when relatedTransactionId exists, else Sale + bank authCode.
     */
    public function purchaseAdvice(array $params): array
    {
        if (empty($this->merchantId) || $this->secretKey === '') {
            return ['success' => false, 'message' => 'NUVEI credentials missing for Direct Advice'];
        }

        $params['card_cvv'] = '';
        $params['is_moto'] = false;
        $params['transactionType'] = 'Sale';
        $params['direct_advice'] = true;

        $relatedId = trim((string) ($params['related_transaction_id'] ?? $params['payment_id'] ?? $params['nuvei_txn_id'] ?? ''));
        $authCode = trim((string) ($params['auth_code'] ?? $params['approval_code'] ?? ''));
        $rrn = trim((string) ($params['rrn'] ?? $params['orig_ref'] ?? $params['reference'] ?? $params['client_unique_id'] ?? ''));

        // Prior host txn → real settleTransaction
        if ($relatedId !== '' && $authCode !== '') {
            $settled = $this->captureFromParams(array_merge($params, [
                'related_transaction_id' => $relatedId,
                'auth_code' => $authCode,
                'client_unique_id' => $rrn !== '' ? $rrn : ($params['client_unique_id'] ?? ''),
            ]));
            if (!empty($settled['success'])) {
                $settled['auth_type'] = 'DIRECT_ADVICE_NO_PRE_AUTH';
                $settled['mti'] = '0220';
                $settled['response_code'] = '00';
            }
            return $settled;
        }

        if ($authCode === '') {
            return ['success' => false, 'message' => 'Bank Approval Code is required for Direct Advice (MTI 0220)'];
        }
        if ($rrn === '') {
            return ['success' => false, 'message' => 'Bank reference (RRN) is required for Direct Advice (MTI 0220)'];
        }

        $params['reference'] = $rrn;
        $params['client_unique_id'] = $rrn;
        $params['auth_code'] = $authCode;

        $sessionRes = $this->getSessionToken();
        if (($sessionRes['status'] ?? '') !== 'SUCCESS') {
            return ['success' => false, 'message' => 'Session token failed: ' . ($sessionRes['reason'] ?? 'Unknown')];
        }
        $sessionToken = $sessionRes['sessionToken'];

        $ts = date('YmdHis');
        $clientReqId = 'POS-ADV-' . strtoupper(substr(uniqid(), 0, 8));
        $amount = number_format((float) ($params['amount'] ?? 0), 2, '.', '');
        $currency = $params['currency'] ?? 'USD';
        $userToken = $params['user_token_id'] ?? 'guest_' . date('YmdHis');

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
            'sessionToken' => $sessionToken,
            'merchantId' => $this->merchantId,
            'merchantSiteId' => $this->siteId,
            'clientRequestId' => $clientReqId,
            'clientUniqueId' => $rrn,
            'amount' => $amount,
            'currency' => $currency,
            'userTokenId' => $userToken,
            'transactionType' => 'Sale',
            'paymentOption' => $this->buildCardPaymentOption($params),
            'billingAddress' => $this->buildBillingAddress($params),
            'timeStamp' => $ts,
            'checksum' => $checksum,
            'deviceDetails' => $this->buildDeviceDetails($params),
            'urlDetails' => $this->restUrlDetails($rrn),
            'isMoto' => '0',
            'authCode' => $authCode,
            'merchantDetails' => [
                'customField1' => 'TRANSCENDIO_FZ_LLC',
                'customField2' => $params['ledger_addr'] ?? $params['ledger_address'] ?? (defined('LEDGER_TRC20_ADDRESS') ? LEDGER_TRC20_ADDRESS : ''),
                'customField3' => $params['terminal_id'] ?? $params['pos_device'] ?? 'POS',
                'customField4' => 'DIRECT_ADVICE_NO_PRE_AUTH',
                'customField5' => 'MTI0220',
                'customField6' => (string) ($params['processing_code'] ?? '000000'),
                'customField7' => (string) ($params['mid'] ?? $params['merchant_id'] ?? $this->merchantId),
                'customField8' => (string) ($params['tid'] ?? $params['terminal_id'] ?? ''),
            ],
        ];

        $result = $this->request('payment', $body);
        $this->log('purchaseAdvice ' . $clientReqId . ' ' . json_encode([
            'status' => $result['status'] ?? null,
            'transactionStatus' => $result['transactionStatus'] ?? null,
            'errCode' => $result['errCode'] ?? null,
            'reason' => $result['reason'] ?? null,
            'gwErrorReason' => $result['gwErrorReason'] ?? null,
            'transactionId' => $result['transactionId'] ?? null,
            'mti' => '0220',
            'auth_type' => 'DIRECT_ADVICE_NO_PRE_AUTH',
        ]));

        $normalized = $this->normalizeResponse('purchase_advice', $result, $clientReqId);
        $normalized['mti'] = '0220';
        $normalized['auth_type'] = 'DIRECT_ADVICE_NO_PRE_AUTH';
        if (!empty($normalized['success'])) {
            $normalized['response_code'] = '00';
        }
        return $normalized;
    }

    // ── Authorization — تفويض (حجز) ───────────────────────
    public function authorize(array $params): array
    {
        $params['transactionType'] = 'Auth';
        return $this->purchase($params);
    }

    /** شراء 2D — MOTO فقط إذا أُرسل is_moto من العملية */
    public function purchase2D(array $params): array
    {
        $params['transactionType'] = $params['transactionType'] ?? 'Sale';
        return $this->purchase($params);
    }

    /** سلفة نقدية / quasi-cash عبر نفس مسار البيع */
    public function cashAdvance(array $params): array
    {
        $params['is_moto'] = true;
        $params['moto_indicator'] = $params['moto_indicator'] ?? 'M';
        $params['transactionType'] = 'Sale';
        $params['pos_device'] = $params['pos_device'] ?? 'CASH_ADVANCE';
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
        $siteUrl     = $this->publicSiteUrl();

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
            'urlDetails'      => $this->restUrlDetails($reference),
            'deviceDetails'   => $this->buildDeviceDetails($params),
            'threeD'          => $this->buildThreeDSDetails($params, $siteUrl),
            'merchantDetails' => [
                'customField1' => $reference,
                'customField2' => $params['ledger_addr'] ?? (defined('LEDGER_TRC20_ADDRESS') ? LEDGER_TRC20_ADDRESS : ''),
            ],
        ];

        $result = $this->request('openOrder', $body);

        if (($result['status'] ?? '') === 'SUCCESS' && !empty($result['sessionToken'])) {
            $payUrl = $this->hostedPayUrl((string) $result['sessionToken']);

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

    /**
     * Refund — string transaction id or POS params array.
     * @param string|array $transactionId
     */
    public function refund(string|array $transactionId, float $amount = 0, string $currency = 'USD'): array {
        if (is_array($transactionId)) {
            return $this->refundFromParams($transactionId);
        }

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

    $success = $this->nuveiTxnApproved($res);

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

    /** Refund من مسار POS (مصفوفة params مع related_transaction_id) */
    private function refundFromParams(array $params): array
    {
        $sessionRes = $this->getSessionToken();
        if (($sessionRes['status'] ?? '') !== 'SUCCESS') {
            return ['success' => false, 'message' => 'Session token failed'];
        }

        $ts          = date('YmdHis');
        $clientReqId = 'POS-REF-' . strtoupper(substr(uniqid(), 0, 8));
        $amount      = number_format((float)($params['amount'] ?? 0), 2, '.', '');
        $currency    = $params['currency'] ?? 'USD';
        $relatedId   = (string)($params['related_transaction_id'] ?? '');

        if ($relatedId === '') {
            return ['success' => false, 'message' => 'related_transaction_id required for refund'];
        }

        $checksum = $this->buildChecksum([
            $this->merchantId,
            $this->siteId,
            $clientReqId,
            $relatedId,
            $amount,
            $currency,
            $this->secretKey,
        ]);

        $result = $this->request('refundTransaction', [
            'sessionToken'          => $sessionRes['sessionToken'] ?? '',
            'merchantId'            => $this->merchantId,
            'merchantSiteId'        => $this->siteId,
            'clientRequestId'       => $clientReqId,
            'clientUniqueId'        => $clientReqId,
            'amount'                => $amount,
            'currency'              => $currency,
            'relatedTransactionId'  => $relatedId,
            'timeStamp'             => $ts,
            'checksum'              => $checksum,
            'comment'               => $params['reason'] ?? 'Customer refund request',
        ]);

        return $this->normalizeResponse('refund', $result, $clientReqId);
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
        $token = trim((string)($p['cloud_token'] ?? $p['payment_token'] ?? $p['userPaymentOptionId'] ?? ''));
        if ($token !== '' && empty($p['card_number'])) {
            if (ctype_digit($token)) {
                return ['userPaymentOptionId' => $token];
            }
            return ['card' => ['ccTempToken' => $token]];
        }
        $card = [];
        if (!empty($p['card_number'])) {
            $expiry = explode('/', str_replace([' ','-'], '/', $p['card_expiry'] ?? '01/30'));
            // cardHolderName بدون أحرف خاصة — أحرف كبيرة فقط
            $cardName = strtoupper(preg_replace('/[^A-Za-z\s]/', '', $p['card_name'] ?? 'CARDHOLDER'));
            $cardName = trim(preg_replace('/\s+/', ' ', $cardName)) ?: 'CARDHOLDER';

            $cardBody = [
                    'cardNumber'        => preg_replace('/\D/', '', $p['card_number']),
                    'cardHolderName'    => $cardName,
                    'expirationMonth'   => str_pad($expiry[0] ?? '01', 2, '0', STR_PAD_LEFT),
                    'expirationYear'    => strlen($expiry[1] ?? '30') === 2
                        ? '20' . ($expiry[1] ?? '30')
                        : ($expiry[1] ?? '2030'),
            ];
            $cvv = trim((string)($p['card_cvv'] ?? ''));
            if ($cvv !== '') {
                $cardBody['CVV'] = $cvv;
            }
            $card = ['card' => $cardBody];
        }
        return $card ?: ['card' => []];
    }

    // ── بناء billing address ────────────────────────────────
    private function buildBillingAddress(array $p): array
    {
        // cardHolderName — أحرف إنجليزية فقط بدون رموز خاصة
        $nameParts = explode(' ', strtoupper(preg_replace('/[^A-Za-z\s]/', '', $p['card_name'] ?? 'CARDHOLDER')));
        $addr = [
            'firstName' => $nameParts[0] ?? 'CARDHOLDER',
            'lastName'  => $nameParts[1] ?? 'CLIENT',
            'email'     => filter_var($p['email'] ?? '', FILTER_VALIDATE_EMAIL)
                            ? $p['email']
                            : 'pos@diparmas.com',
            'country'   => strtoupper(substr($p['country'] ?? 'AE', 0, 2)),
            'city'      => trim((string)($p['city'] ?? '')) ?: 'Dubai',
            'address'   => trim((string)($p['address'] ?? '')) ?: 'Dubai',
            'zip'       => trim((string)($p['zip'] ?? '')) ?: '00000',
        ];
        $phone = preg_replace('/\D/', '', (string)($p['phone'] ?? ''));
        if ($phone !== '') {
            $addr['phone'] = $phone;
        }
        return $addr;
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
            'notificationUrl' => $this->restUrlDetails()['notificationUrl'] ?: (rtrim($siteUrl, '/') . '/api/nuvei_dmn.php'),
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

    /** Nuvei API status=SUCCESS is not an approval (DECLINED/REDIRECT also return SUCCESS). */
    private function nuveiTxnApproved(array $res): bool
    {
        $txn = strtoupper((string)($res['transactionStatus'] ?? ''));
        return in_array($txn, ['APPROVED', 'SUCCESS'], true);
    }

    private function nuveiRedirectUrl(array $res): string
    {
        $url = trim((string)($res['redirectUrl'] ?? $res['redirect_url'] ?? ''));
        if ($url === '') {
            $url = trim((string)($res['paymentOption']['card']['threeD']['acsUrl'] ?? ''));
        }
        return $url;
    }

        // ── تطبيع الاستجابة ────────────────────────────────────
    private function normalizeResponse(string $type, array $raw, string $clientReqId): array
    {
        $status      = strtoupper((string)($raw['status'] ?? ''));
        $txnStatus   = strtoupper((string)($raw['transactionStatus'] ?? ''));
        $success     = in_array($txnStatus, ['APPROVED', 'SUCCESS'], true);
        $reason      = $success ? 'APPROVED' : $this->formatDeclineReason($raw, $txnStatus, $status);
        $redirectUrl = $this->nuveiRedirectUrl($raw);
        $needs3ds    = !$success && ($txnStatus === 'REDIRECT' || $redirectUrl !== '');

        return [
            'success'          => $success,
            'txn_type'         => $type,
            'reference'        => $clientReqId,
            'nuvei_txn_id'     => $raw['transactionId'] ?? $raw['gwTransactionId'] ?? null,
            'payment_id'       => $raw['transactionId'] ?? $raw['gwTransactionId'] ?? null,
            'transaction_id'   => $raw['transactionId'] ?? $raw['gwTransactionId'] ?? null,
            'approval_code'    => $raw['authCode']           ?? $raw['approvalCode']      ?? null,
            'auth_code'        => $raw['authCode']           ?? $raw['approvalCode']      ?? null,
            'rrn'              => $raw['rrn']                 ?? $raw['retrievalReferenceNumber'] ?? null,
            'status'           => $status,
            'txn_status'       => $txnStatus,
            'amount'           => $raw['totalAmount']        ?? null,
            'currency'         => $raw['currency']           ?? null,
            'message'          => $needs3ds ? '3DS_REQUIRED' : $reason,
            'decline_reason'   => $success || $needs3ds ? null : $reason,
            'requires_3ds'     => $needs3ds,
            'redirect_url'     => $needs3ds ? $redirectUrl : '',
            'raw'              => $raw,
            'settlement_target'=> 'ledger',
            'acquirer'         => 'nuvei',
            'merchant'         => 'TRANSCENDIO FZ-LLC',
        ];
    }

    private function formatDeclineReason(array $raw, string $txnStatus, string $status): string
    {
        $card = is_array($raw['paymentOption']['card'] ?? null) ? $raw['paymentOption']['card'] : [];
        $blob = json_encode($raw, JSON_UNESCAPED_UNICODE) ?: '';
        $gwCode = trim((string)($raw['gwErrorCode'] ?? $card['gwErrorCode'] ?? ''));
        $errCode = trim((string)($raw['errCode'] ?? ''));
        $extCode = trim((string)($raw['gwExtendedErrorCode'] ?? $card['gwExtendedErrorCode'] ?? ''));
        $issuerCode = trim((string)($card['issuerDeclineCode'] ?? $raw['issuerDeclineCode'] ?? ''));
        $transReason = trim((string)($raw['transReason'] ?? $card['transReason'] ?? $raw['transactionReason'] ?? ''));
        $scan = $transReason . ' ' . $gwCode . ' ' . $errCode . ' ' . $extCode . ' ' . $blob;
        if (preg_match('/1507/', $scan)) {
            $gwCode = '1507';
        } elseif ($gwCode === '' && preg_match('/\b(1011|1007|1106|1019)\b/', $scan, $m)) {
            $gwCode = $m[1];
        }
        $reason = trim((string)(
            $raw['gwErrorReason']
            ?? $card['gwErrorReason']
            ?? $raw['errReason']
            ?? $raw['errorDescription']
            ?? $raw['reason']
            ?? $card['issuerDeclineReason']
            ?? $raw['issuerDeclineReason']
            ?? $raw['paymentMethodErrorReason']
            ?? ''
        ));
        if ($reason !== '' && preg_match('/TRANSID=|TRANSSCORE=|TRANSREASON/i', $reason)) {
            $reason = '';
        }
        $codeMap = [
            '1011' => 'Invalid card number',
            '1007' => 'Expired card',
            '1106' => 'Insufficient funds',
            '1019' => 'Invalid failure URL',
            '1507' => 'Issuer declined',
            '-1100' => 'Processor declined',
            '1116' => 'Issuer declined',
        ];
        $arMap = [
            '1011' => 'رقم البطاقة غير صحيح.',
            '1007' => 'البطاقة منتهية.',
            '1106' => 'الرصيد غير كافٍ.',
            '1019' => 'رابط الفشل غير مقبول من Nuvei.',
            '1507' => 'البنك المصدر رفض العملية. جرّب Purchase 3D بمبلغ صغير حقيقي.',
            '-1100' => 'Nuvei/المعالج رفض. غالباً قرار البنك المصدر.',
            '1116' => 'البنك المصدر رفض العملية.',
        ];
        if ($reason === '') {
            $reason = $codeMap[$gwCode] ?? $codeMap[$errCode] ?? $codeMap[$extCode] ?? ($txnStatus !== '' ? $txnStatus : $status);
        }
        if ($reason === '' || strcasecmp($reason, 'ERROR') === 0 || strcasecmp($reason, 'DECLINED') === 0) {
            if (isset($codeMap[$gwCode])) {
                $reason = $codeMap[$gwCode];
            } elseif (isset($codeMap[$extCode])) {
                $reason = $codeMap[$extCode];
            } else {
                $reason = 'Generic Decline';
            }
        }
        $explain = $arMap[$gwCode] ?? $arMap[$errCode] ?? $arMap[$extCode] ?? '';
        if ($explain === '' && preg_match('/generic\s*decline/i', $reason)) {
            $explain = 'البنك رفض العملية بدون كود تفصيلي. تواصل مع بنك البطاقة.';
        } elseif ($explain === '' && isset($codeMap[$gwCode]) && stripos($reason, $codeMap[$gwCode]) === false) {
            $explain = $codeMap[$gwCode];
        }
        $useful = static function (string $code): bool {
            return $code !== '' && !in_array($code, ['0', '00', '000', '-1', 'null'], true);
        };
        $bits = array_filter([
            $useful($gwCode) ? ('Nuvei ' . $gwCode) : '',
            $useful($extCode) && $extCode !== $gwCode ? ('ext ' . $extCode) : '',
            $reason,
            $explain,
        ]);
        return implode(' — ', array_unique($bits));
    }

        // ── HTTP Request ───────────────────────────────────────
    private function request(string $endpoint, array $body): array
    {
        $path = ltrim($endpoint, '/');
        if (!str_ends_with($path, '.do')) {
            $path .= '.do';
        }
        $payload = json_encode($body);
        $lastError = '';
        foreach ($this->apiBases() as $base) {
            $url = rtrim($base, '/') . '/' . $path;
            $ch  = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CONNECTTIMEOUT => 12,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            ]);
            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error    = curl_error($ch);
            curl_close($ch);

            if ($error) {
                $lastError = $error;
                $this->log('cURL ' . $error . ' ' . $url);
                continue;
            }
            if ($httpCode !== 200) {
                $this->log('HTTP ' . $httpCode . ' ' . $url);
                $lastError = 'HTTP ' . $httpCode;
                continue;
            }
            $decoded = json_decode((string)$response, true);
            if (!is_array($decoded)) {
                $lastError = 'Invalid JSON response';
                continue;
            }
            $this->baseUrl = $base;
            $decoded['_http_code'] = $httpCode;
            return $decoded;
        }

        return ['status' => 'ERROR', 'reason' => 'cURL: ' . ($lastError !== '' ? $lastError : 'Nuvei host unreachable')];
    }
}
