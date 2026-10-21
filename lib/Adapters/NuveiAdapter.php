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
    private string $configurationError = '';
    private string $logFile;

    public function __construct(array $config = []) {
        $this->merchantId = trim((string) ($config['merchant_id'] ?? ''));
        $this->siteId = trim((string) ($config['site_id'] ?? ''));
        $this->secretKey = trim((string) ($config['secret_key'] ?? ''));
        $envUrl = trim((string) ($config['api_endpoint'] ?? ''));
        if ($this->merchantId === '') {
            $this->merchantId = trim((string) (getenv('NUVEI_MERCHANT_ID') ?: ''));
        }
        if ($this->siteId === '') {
            $this->siteId = trim((string) (getenv('NUVEI_SITE_ID') ?: ''));
        }
        if ($this->secretKey === '') {
            $this->secretKey = trim((string) (getenv('NUVEI_SECRET_KEY') ?: ''));
        }
        if ($envUrl === '') {
            $envUrl = trim((string) (getenv('NUVEI_API_URL') ?: ''));
        }
        if ($envUrl !== '') {
            $urlParts = parse_url($envUrl);
            if (!is_array($urlParts)
                || strtolower((string) ($urlParts['scheme'] ?? '')) !== 'https'
                || trim((string) ($urlParts['host'] ?? '')) === ''
                || isset($urlParts['user'])
                || isset($urlParts['pass'])
                || isset($urlParts['query'])
                || isset($urlParts['fragment'])) {
                $this->configurationError = 'NUVEI_API_URL must be a valid HTTPS base URL without credentials, query, or fragment';
            } else {
                $this->baseUrl = rtrim($envUrl, '/');
            }
        }
        $this->logFile    = defined('LOGS_PATH') ? LOGS_PATH.'/nuvei.log' : __DIR__.'/../../logs/nuvei.log';
        if(!is_dir(dirname($this->logFile))) @mkdir(dirname($this->logFile),0755,true);
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
            'successUrl' => $panelUrl((string) getenv('NUVEI_SUCCESS_URL'), $base . '/nuvei-success.html'),
            'failureUrl' => $panelUrl((string) (getenv('NUVEI_FAILURE_URL') ?: getenv('NUVEI_CANCEL_URL')), $base . '/nuvei-fail.html'),
            'pendingUrl' => $panelUrl((string) getenv('NUVEI_PENDING_URL'), $base . '/nuvei-pending.html'),
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
        return 'https://secure.nuvei.com/ppp/purchase.do?sessionToken=' . rawurlencode($sessionToken);
    }

    private function saleOrAuth(array $params): string
    {
        $type = (string) ($params['transactionType'] ?? 'Sale');
        return in_array($type, ['Sale', 'Auth', 'PreAuth', 'Credit'], true) ? $type : 'Sale';
    }

    public function supports(string $mode): bool {
        $mode = strtoupper(trim($mode));
        return in_array($mode, ['2D', '3D', 'HOLD', 'CAPTURE', 'CANCEL', 'REFUND', 'VOID', 'MOTO', 'OFFLINE', 'ADVICE'], true);
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
        $offlineError = $this->offlineModeError($payload);
        if ($offlineError !== null) {
            return $offlineError;
        }
        if (strtolower(trim((string) ($payload['txn_type'] ?? ''))) === 'purchase_advice') {
            return $this->purchaseAdvice($payload);
        }
        $reference = $payload['reference'] ?? 'N'.time();
        $amount    = floatval($payload['amount'] ?? 0);
        $currency  = strtoupper($payload['currency'] ?? 'USD');

        $txnKind = strtolower(trim((string) ($payload['txn_type'] ?? '')));
        if ($amount < 0 && in_array($txnKind, ['offline_sale_moto', 'purchase_advice', 'capture'], true)) {
            $payload['amount_sign'] = 'minus';
            $amount = abs($amount);
            $payload['amount'] = $amount;
        }
        if ($amount <= 0) {
            return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', $reference, $amount, $currency, 'المبلغ غير صالح');
        }

        $ccNumber = $payload['card_number'] ?? $payload['cc_number'] ?? '';
        $ccExp    = $payload['card_expiry'] ?? $payload['cc_expiry'] ?? '';
        $ccCvv    = $payload['cvv2'] ?? $payload['card_cvv'] ?? $payload['cc_cvv'] ?? '';

        if (!$this->hasCardOrPaymentToken($payload)) {
            return GatewayErrorMapper::buildErrorResponse('INVALID_CARD', $reference, $amount, $currency, 'بيانات البطاقة أو توكن الدفع غير مكتملة');
        }

        $payload['cc_number'] = $ccNumber;
        $payload['cc_expiry'] = $ccExp;
        $payload['cc_cvv']    = $ccCvv;
        $payload['card_number'] = $ccNumber;
        $payload['card_expiry'] = $ccExp;
        $payload['card_cvv'] = $ccCvv;
        $payload['card_name'] = $payload['name'] ?? $payload['card_name'] ?? '';
        $payload['transactionType'] = $this->saleOrAuth($payload);

        return $this->purchase($payload);
    }

    public function hold(array $payload): array {
        $offlineError = $this->offlineModeError($payload);
        if ($offlineError !== null) {
            return $offlineError;
        }
        $payload['transactionType'] = 'Auth';
        $mode = strtoupper(trim((string) ($payload['processing_mode'] ?? $payload['mode'] ?? '')));
        $channel = strtolower(trim((string) ($payload['auth_channel'] ?? $payload['moto_channel'] ?? '')));
        $present = function_exists('pos_entry_is_present') && pos_entry_is_present($payload);
        if ($channel === 'offline' || !empty($payload['is_offline']) || $mode === 'OFFLINE') {
            $payload['processing_mode'] = 'OFFLINE';
            $payload['is_offline'] = true;
            $payload['is_moto'] = true;
        } elseif ($mode === '3D') {
            $payload['processing_mode'] = '3D';
            $payload['is_moto'] = false;
        } elseif ($channel === 'ecom' || $present) {
            $payload['processing_mode'] = '2D';
            $payload['is_moto'] = false;
            if ($present) {
                $payload['card_present'] = true;
            }
        } else {
            $payload['processing_mode'] = 'MOTO';
            $payload['is_moto'] = true;
        }
        $payload['card_number'] = $payload['card_number'] ?? $payload['cc_number'] ?? '';
        $payload['card_expiry'] = $payload['card_expiry'] ?? $payload['cc_expiry'] ?? '';
        $payload['card_cvv'] = $payload['cvv2'] ?? $payload['card_cvv'] ?? $payload['cc_cvv'] ?? '';
        $payload['card_name'] = $payload['name'] ?? $payload['card_name'] ?? '';
        if (!$this->hasCardOrPaymentToken($payload)) {
            return GatewayErrorMapper::buildErrorResponse(
                'INVALID_CARD',
                (string) ($payload['reference'] ?? ''),
                (float) ($payload['amount'] ?? 0),
                strtoupper((string) ($payload['currency'] ?? 'USD')),
                'بيانات البطاقة أو توكن الدفع غير مكتملة'
            );
        }
        return $this->purchase($payload);
    }

    private function hasCardOrPaymentToken(array $payload): bool
    {
        $cardNumber = trim((string) ($payload['card_number'] ?? $payload['cc_number'] ?? ''));
        $cardExpiry = trim((string) ($payload['card_expiry'] ?? $payload['cc_expiry'] ?? ''));

        return $cardNumber !== ''
            ? $cardExpiry !== ''
            : $this->paymentToken($payload) !== '';
    }

    private function paymentToken(array $params): string
    {
        foreach (['cloud_token', 'payment_token', 'userPaymentOptionId'] as $key) {
            $token = trim((string) ($params[$key] ?? ''));
            if ($token !== '') {
                return $token;
            }
        }
        return '';
    }

    private function offlineModeError(array $params): ?array
    {
        $type = strtolower(trim((string) ($params['txn_type'] ?? '')));
        $offlineRequested = in_array($type, ['offline_sale_moto', 'offline_sales_moto'], true);
        $sources = [$params];
        if (is_array($params['extra'] ?? null)) {
            $sources[] = $params['extra'];
        }
        foreach ($sources as $source) {
            $mode = strtoupper(trim((string) ($source['processing_mode'] ?? $source['mode'] ?? '')));
            $channel = strtolower(trim((string) ($source['auth_channel'] ?? $source['moto_channel'] ?? '')));
            $offlineRequested = $offlineRequested
                || !empty($source['is_offline'])
                || $mode === 'OFFLINE'
                || $channel === 'offline';
        }
        if (!$offlineRequested) {
            return null;
        }

        return [
            'success' => false,
            'status' => 'failed',
            'error_code' => 'OFFLINE_NOT_SUPPORTED',
            'message' => 'Nuvei REST does not support offline SAF sales or holds. No transaction was sent; use a supported online operation or an approved SAF terminal.',
        ];
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
        $sessionToken = trim((string) ($params['sessionToken'] ?? $params['session_token'] ?? ''));
        if ($sessionToken === '') {
            $sessionRes = $this->getSessionToken();
            if (($sessionRes['status'] ?? '') !== 'SUCCESS') {
                return ['success' => false, 'message' => 'Session token failed: ' . ($sessionRes['reason'] ?? 'Unknown')];
            }
            $sessionToken = (string) ($sessionRes['sessionToken'] ?? '');
        }
        $clientReqId = (string) ($params['client_request_id'] ?? ('INIT-' . strtoupper(substr(uniqid(), 0, 8))));
        $reference = (string) ($params['reference'] ?? $params['client_unique_id'] ?? $clientReqId);
        $amount = number_format((float) ($params['amount'] ?? 0), 2, '.', '');
        $currency = strtoupper((string) ($params['currency'] ?? 'USD'));
        $notify3ds = $this->restUrlDetails($reference)['notificationUrl'] ?: ($this->publicSiteUrl() . '/api/nuvei_dmn.php');
        $body = [
            'sessionToken' => $sessionToken,
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
            'session_token' => $res['sessionToken'] ?? $sessionToken,
            'three_d' => $res['paymentOption']['card']['threeD'] ?? [],
            'redirect_url' => $this->nuveiRedirectUrl($res),
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

        $ts       = date('YmdHis');
        $ref      = 'CAP-' . strtoupper(substr(uniqid(), 0, 8));
        $amtFloat = floatval($amount ?? 0);
        $amt      = number_format($amtFloat, 2, '.', '');
        // العملة: تُمرَّر من الـ caller — إذا لم تُمرَّر نجلبها من الـ transaction عبر Nuvei أو نستخدم USD كـ fallback آمن
        $currency = 'USD';
        if (is_array($amount) && isset($amount['currency'])) {
            // دعم استدعاء: capture($id, ['amount'=>x,'currency'=>'AED'])
            $currency = strtoupper(trim((string) $amount['currency']));
            $amtFloat = floatval($amount['amount'] ?? 0);
            $amt      = number_format($amtFloat, 2, '.', '');
        }

        $body = [
            'merchantId'           => $this->merchantId,
            'merchantSiteId'       => $this->siteId,
            'clientRequestId'      => $ref,
            'clientUniqueId'       => $ref,
            'amount'               => $amt,
            'currency'             => $currency,
            'relatedTransactionId' => $transactionId,
            'timeStamp'            => $ts,
            'checksum'             => $this->relatedChecksum($ref, $ref, $amt, $currency, $transactionId, $ts),
            'urlDetails'           => ['notificationUrl' => $this->restUrlDetails()['notificationUrl']],
        ];

        $res      = $this->post('/settleTransaction.do', $body);
        $success  = $this->nuveiTxnApproved($res);

        if ($success) {
            $this->log("✓ Capture: {$transactionId} | ref={$ref} | currency={$currency}");
            return [
                'success'        => true,
                'status'         => 'captured',
                'transaction_id' => $res['transactionId'] ?? $transactionId,
                'reference'      => $res['clientUniqueId'] ?? $ref,
                'amount'         => $amtFloat,
                'currency'       => $res['currency'] ?? $currency,
                'message'        => '✅ تم تحصيل المبلغ عبر Nuvei',
                'approval_code'  => $res['authCode'] ?? '',
                'rrn'            => $res['rrn'] ?? $res['retrievalReferenceNumber'] ?? '',
                'nuvei_txn_id'   => $res['transactionId'] ?? $transactionId,
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
        return GatewayErrorMapper::buildErrorResponse($errCode, $transactionId, $amtFloat, $currency,
            $res['gwErrorReason'] ?? $res['reason'] ?? $res['errCode'] ?? 'Nuvei capture failed');
    }

    /** Capture من مسار POS (مصفوفة params مع related_transaction_id + auth_code) */
    private function captureFromParams(array $params): array
    {
        $ts          = date('YmdHis');
        $clientReqId = 'POS-CAP-' . strtoupper(substr(uniqid(), 0, 8));
        $amountRaw   = (float) ($params['capture_amount'] ?? $params['amount'] ?? 0);
        $amount      = number_format($amountRaw, 2, '.', '');
        $currency    = $params['currency'] ?? 'USD';
        $clientUniqueId = (string)($params['client_unique_id'] ?? $clientReqId);
        $authCode    = trim((string)($params['auth_code'] ?? ''));
        $relatedId   = trim((string)($params['related_transaction_id'] ?? $params['payment_id'] ?? $params['nuvei_txn_id'] ?? ''));

        if ($relatedId === '') {
            return ['success' => false, 'message' => 'Nuvei Transaction ID (Payment ID) is required for capture'];
        }
        if (abs($amountRaw) < 0.01) {
            return ['success' => false, 'message' => 'Capture amount is required'];
        }
        if ($amountRaw < 0) {
            $params['amount_sign'] = 'minus';
            $amountRaw = abs($amountRaw);
            $amount = number_format($amountRaw, 2, '.', '');
        }
        // authCode اختياري: Nuvei تقبل settleTransaction بدونه إذا كان relatedTransactionId صحيحاً.
        // إذا أرسله المشغّل نُضمّنه، إذا لم يرسله نُرسل الطلب بدونه.

        $checksum = $this->relatedChecksum($clientReqId, $clientUniqueId, $amount, $currency, $relatedId, $ts);

        $body = [
            'merchantId'            => $this->merchantId,
            'merchantSiteId'        => $this->siteId,
            'clientRequestId'       => $clientReqId,
            'clientUniqueId'        => $clientUniqueId,
            'amount'                => $amount,
            'currency'              => $currency,
            'relatedTransactionId'  => $relatedId,
            'timeStamp'             => $ts,
            'checksum'              => $checksum,
            'urlDetails'            => ['notificationUrl' => $this->restUrlDetails($clientUniqueId)['notificationUrl']],
        ];
        // أضف authCode فقط إذا تم توفيره — لا تُرسل حقلاً فارغاً
        if ($authCode !== '') {
            $body['authCode'] = $authCode;
        }

        $result = $this->request('settleTransaction', $body);

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
        $ref = 'VOD-' . strtoupper(substr(uniqid(), 0, 8));

        // Nuvei void: amount=0.00 مطلوب لكن currency يجب أن تُطابق الـ transaction الأصلية.
        // نجلبها من الـ reason string إذا مرّرها الـ caller (convention: reason='USD' or 'AED')
        // أو من DB عبر helper إذا متاح، أو USD كـ safe fallback.
        $currency = 'USD';
        if (strlen($reason) === 3 && ctype_alpha($reason)) {
            // caller مرّر currency code بدل reason string
            $currency = strtoupper($reason);
            $reason   = 'requested_by_customer';
        } elseif (function_exists('nuvei_fetch_txn_currency')) {
            $fetched = nuvei_fetch_txn_currency($transactionId);
            if ($fetched !== '') {
                $currency = strtoupper($fetched);
            }
        }

        $body = [
            'merchantId'           => $this->merchantId,
            'merchantSiteId'       => $this->siteId,
            'clientRequestId'      => $ref,
            'clientUniqueId'       => $ref,
            'amount'               => '0.00',
            'currency'             => $currency,
            'relatedTransactionId' => $transactionId,
            'timeStamp'            => $ts,
            'checksum'             => $this->relatedChecksum($ref, $ref, '0.00', $currency, $transactionId, $ts),
        ];

        $res     = $this->post('/voidTransaction.do', $body);
        $success = $this->nuveiTxnApproved($res);

        if ($success) {
            $this->log("✓ Void: {$transactionId} | currency={$currency}");
            return [
                'success'        => true,
                'status'         => 'cancelled',
                'transaction_id' => $res['transactionId'] ?? $transactionId,
                'reference'      => $ref,
                'amount'         => 0,
                'currency'       => $res['currency'] ?? $currency,
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
        return GatewayErrorMapper::buildErrorResponse($errCode, $transactionId, 0, $currency,
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
        $ident = $this->missingPayerIdentity($payload);
        if ($ident !== null) {
            return $ident;
        }
        $email = $this->identifiedEmail($payload);
        $nameParts = explode(' ', $this->identifiedCardName($payload), 2);
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
                'firstName' => $nameParts[0],
                'lastName' => $nameParts[1] ?? $nameParts[0],
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
        $payload['card_number'] = $payload['card_number'] ?? $payload['cc_number'] ?? '';
        $payload['card_expiry'] = $payload['card_expiry'] ?? $payload['cc_expiry'] ?? '';
        $payload['card_cvv'] = $payload['card_cvv'] ?? $payload['cc_cvv'] ?? '';
        $payload['card_name'] = $payload['card_name'] ?? $payload['name'] ?? '';
        $payload['transactionType'] = $payload['transactionType'] ?? 'Sale';
        return $this->purchase($payload);
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
        if ($this->merchantId === '' || $this->siteId === '' || $this->secretKey === '') {
            return ['success' => false, 'message' => 'Nuvei merchant_id, site_id, and secret_key are required'];
        }

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

    public function getTransactionDetails(string $orderId): array
    {
        if ($this->merchantId === '' || $this->siteId === '' || $this->secretKey === '') {
            return ['status' => 'ERROR', 'reason' => 'Nuvei merchant_id, site_id, and secret_key are required'];
        }
        $timeStamp = time();
        $checksum = md5($this->merchantId . $timeStamp . $this->secretKey);
        return $this->request('getTransactionDetails.do', [
            'merchantId' => $this->merchantId,
            'merchantSiteId' => $this->siteId,
            'timeStamp' => $timeStamp,
            'checksum' => $checksum,
            'orderId' => $orderId,
        ]);
    }

    // ── Webhook Handler ───────────────────────────────────
    public function handleWebhook(array $data): array {
        $status = $data['transactionStatus'] ?? $data['Status'] ?? '';
        $ref    = $data['clientRequestId']   ?? $data['clientUniqueId'] ?? '';
        $txId   = $data['TransactionID']     ?? $data['transactionId']  ?? '';

        $this->log("Webhook: status=$status ref=$ref txId=$txId");

        return [
            'success'        => strtoupper($status) === 'APPROVED',
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
        $offlineError = $this->offlineModeError($params);
        if ($offlineError !== null) {
            return $offlineError;
        }
        $ident = $this->missingPayerIdentity($params);
        if ($ident !== null) {
            return $ident;
        }
        $adviceRrn = null;
        if (strtolower(trim((string) ($params['txn_type'] ?? ''))) === 'purchase_advice') {
            $adviceRrn = $this->purchaseAdviceRrn($params);
            if ($adviceRrn === null) {
                return ['success' => false, 'message' => 'Purchase advice requires a 12-digit bank RRN'];
            }
        }
        $sessionRes = $this->getSessionToken();
        if (($sessionRes['status'] ?? '') !== 'SUCCESS') {
            return ['success' => false, 'message' => 'Session token failed: ' . ($sessionRes['reason'] ?? 'Unknown')];
        }
        $sessionToken = $sessionRes['sessionToken'];
        $mode = strtoupper(trim((string) ($params['processing_mode'] ?? '')));
        $offline = $mode === 'OFFLINE' || !empty($params['is_offline']);
        $isMoto = $offline || $mode === 'MOTO' || !empty($params['is_moto']) || !empty($params['direct_advice']);

        $ts        = date('YmdHis');
        $clientReqId = 'POS-PUR-' . strtoupper(substr(uniqid(), 0, 8));
        $amount    = number_format((float)$params['amount'], 2, '.', '');
        $currency  = strtoupper((string) ($params['currency'] ?? 'USD'));
        $userToken = $this->identifiedEmail($params) ?: (string) ($params['user_token_id'] ?? '');
        $reference = (string) ($params['reference'] ?? $clientReqId);
        $txnType   = $this->saleOrAuth($params);

        $init3d = [];
        $initTxnId = '';
        require_once __DIR__ . '/../CardScaService.php';
        $threeDSale = $mode === '3D' || strtolower((string) ($params['txn_type'] ?? '')) === 'purchase_3d';
        $need3ds = $threeDSale && !$isMoto && CardScaService::shouldChallenge($params);
        if ($need3ds) {
            $init = $this->initPayment(array_merge($params, [
                'sessionToken' => $sessionToken,
                'client_request_id' => $clientReqId,
                'reference' => $reference,
            ]));
            if (($init['success'] ?? false) !== true) {
                return [
                    'success' => false,
                    'message' => (string) ($init['message'] ?? 'initPayment failed'),
                    'reference' => $reference,
                    'raw' => $init['raw'] ?? $init,
                ];
            }
            $init3d = is_array($init['three_d'] ?? null) ? $init['three_d'] : [];
            $initTxnId = trim((string) ($init['transaction_id'] ?? ''));
        }

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
            'isMoto'           => $isMoto ? '1' : '0',
            'merchantDetails'  => [
                'customField1' => 'TRANSCENDIO_FZ_LLC',
                'customField2' => $params['ledger_addr'] ?? $params['ledger_address'] ?? (defined('LEDGER_TRC20_ADDRESS') ? LEDGER_TRC20_ADDRESS : ''),
                'customField3' => $params['pos_device'] ?? 'BITEL_IC3600',
                'customField4' => $offline ? 'OFFLINE' : ($isMoto ? 'MOTO' : (!empty($params['card_present']) ? 'CARD' : 'ECOM')),
            ],
        ];
        if ($adviceRrn !== null) {
            // /payment has no caller-supplied RRN field; customData preserves it for Nuvei reporting.
            $body['customData'] = $this->buildPurchaseAdviceCustomData($adviceRrn);
        }
        if ($initTxnId !== '') {
            $body['relatedTransactionId'] = $initTxnId;
        }
        $authCode = trim((string)($params['auth_code'] ?? $params['approval_code'] ?? ''));
        if ($authCode !== '') {
            $body['authCode'] = $authCode;
        }
        if ($need3ds && isset($body['paymentOption']['card']) && is_array($body['paymentOption']['card'])) {
            $ours = $this->buildThreeDSDetails($params, $this->publicSiteUrl());
            $body['paymentOption']['card']['threeD'] = array_merge($init3d, $ours);
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
        $normalized = $this->normalizeResponse('purchase', $result, $clientReqId);
        if (CardScaService::shouldRecordCompletedChallenge($need3ds, !empty($normalized['success']))) {
            CardScaService::markCompleted(
                (string) ($params['card_number'] ?? $params['cc_number'] ?? ''),
                (string) ($params['card_expiry'] ?? $params['cc_expiry'] ?? ''),
                (string) ($params['card_last4'] ?? ''),
                $reference
            );
        }
        return $normalized;
    }

    private function purchaseAdviceRrn(array $params): ?string
    {
        foreach (['bank_rrn', 'rrn', 'orig_ref'] as $key) {
            $rrn = preg_replace('/\D+/', '', (string) ($params[$key] ?? '')) ?? '';
            if (preg_match('/^\d{12}$/', $rrn)) {
                return $rrn;
            }
        }
        return null;
    }

    /**
     * Submit an actual ISO 0220 Advice to the configured bank advice host.
     * A negative amount is sent as a debit of the absolute value.
     */
    public function purchaseAdvice(array $params): array
    {
        $rrn = $this->purchaseAdviceRrn($params);
        if ($rrn === null) {
            return ['success' => false, 'message' => 'Purchase advice requires a 12-digit bank RRN'];
        }

        $amountRaw = (float) ($params['amount'] ?? 0);
        if (abs($amountRaw) < 0.00001) {
            return ['success' => false, 'message' => 'Purchase advice amount is required'];
        }

        require_once dirname(__DIR__, 2) . '/pos/lib/DirectAdvicePOSProcessor.php';
        $processor = new DirectAdvicePOSProcessor(
            (string) ($params['merchant_id'] ?? $params['mid'] ?? ''),
            (string) ($params['terminal_id'] ?? $params['tid'] ?? ''),
            'nuvei'
        );
        $result = $processor->executeDirectAdviceSale($rrn, $amountRaw, $params);
        $success = !empty($result['success']);
        $transactionId = trim((string) ($result['transaction_id'] ?? ''));

        return [
            'success' => $success,
            'status' => $success ? 'completed' : 'failed',
            'txn_type' => 'purchase_advice',
            'reference' => (string) ($params['reference'] ?? ''),
            'transaction_id' => $transactionId,
            'payment_id' => $transactionId,
            'nuvei_txn_id' => $transactionId,
            'rrn' => (string) ($result['reference_number'] ?? $rrn),
            'approval_code' => (string) ($result['approval_code'] ?? ''),
            'response_code' => (string) ($result['response_code'] ?? ''),
            'amount' => abs($amountRaw),
            'currency' => strtoupper((string) ($params['currency'] ?? 'USD')),
            'mti' => (string) ($result['mti'] ?? ($success ? '0230' : '0220')),
            'auth_type' => 'PURCHASE_ADVICE_AFTER_AUTH',
            'message' => (string) ($result['message'] ?? 'Bank Advice host rejected the request'),
            'raw' => $result['gateway_response'] ?? $result,
            'acquirer' => 'nuvei',
            'settlement_target' => 'gateway',
        ];
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
        require_once __DIR__ . '/../CardScaService.php';
        $params['processing_mode'] = '3D';
        $params['txn_type'] = 'purchase_3d';
        $params['is_moto'] = false;
        $params['is_offline'] = false;
        $params['direct_advice'] = false;
        $params['transactionType'] = 'Sale';
        if (!CardScaService::shouldChallenge($params)) {
            $params['processing_mode'] = '2D';
            $params['txn_type'] = 'purchase_2d';
            return $this->purchase2D($params);
        }
        return $this->purchase($params);
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

        $checksum = $this->relatedChecksum(
            $clientReqId,
            $clientReqId,
            $amount,
            $currency,
            $relatedId,
            $ts
        );

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
        $relatedId   = (string) ($params['related_transaction_id'] ?? '');
        // Nuvei void: amount=0.00 صحيح — البنك يُلغي المبلغ كاملاً
        $amount      = '0.00';
        $currency    = strtoupper((string) ($params['currency'] ?? 'USD'));

        // checksum صحيح: merchantId + siteId + clientRequestId + clientUniqueId
        //                 + amount + currency + relatedTransactionId + timeStamp + secretKey
        $checksum = $this->relatedChecksum($clientReqId, $clientReqId, $amount, $currency, $relatedId, $ts);

        $result = $this->request('voidTransaction', [
            'sessionToken'          => $sessionRes['sessionToken'],
            'merchantId'            => $this->merchantId,
            'merchantSiteId'        => $this->siteId,
            'clientRequestId'       => $clientReqId,
            'clientUniqueId'        => $clientReqId,
            'amount'                => $amount,
            'currency'              => $currency,
            'relatedTransactionId'  => $relatedId,
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

    /** اسم حامل البطاقة الحقيقي — لا CARDHOLDER ولا اسم وهمي. */
    private function identifiedCardName(array $p): string
    {
        $raw = trim((string) ($p['card_name'] ?? $p['name'] ?? ''));
        $clean = strtoupper((string) preg_replace('/[^A-Za-z\s]/', '', $raw));
        $clean = trim((string) preg_replace('/\s+/', ' ', $clean));
        $compact = str_replace(' ', '', $clean);
        if ($clean === '' || preg_match('/^(CARDHOLDER|CUSTOMER|CLIENT|GUEST|TEST|UNKNOWN)$/', $compact)) {
            return '';
        }
        return $clean;
    }

    /** إيميل العميل على نفس العملية — ليس guest/pos@diparmas. */
    private function identifiedEmail(array $p): string
    {
        $email = trim((string) ($p['email'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return '';
        }
        if (preg_match('/^(guest|pos|noreply|no-reply|test)@/i', $email)) {
            return '';
        }
        return $email;
    }

    private function missingPayerIdentity(array $p): ?array
    {
        $txn = strtolower(trim((string) ($p['txn_type'] ?? '')));
        $mode = strtoupper(trim((string) ($p['processing_mode'] ?? '')));
        $schemeKeyed = !empty($p['direct_advice'])
            || !empty($p['is_offline'])
            || !empty($p['is_moto'])
            || in_array($mode, ['MOTO', 'OFFLINE'], true)
            || in_array($txn, ['purchase_advice', 'offline_sale_moto', 'online_sale_moto', 'purchase_2d', 'auth', 'capture'], true);
        if ($schemeKeyed) {
            return null;
        }
        if ($this->identifiedCardName($p) === '' || $this->identifiedEmail($p) === '') {
            return [
                'success' => false,
                'message' => 'Nuvei على حساب Transcendio يتطلب اسم حامل البطاقة كما مطبوع وإيميل العميل الحقيقي. لا يُرسل CARDHOLDER ولا إيميل وهمي — العملية ليست شحناً مجهولاً.',
            ];
        }
        return null;
    }

    // ── بناء payment option للبطاقة ────────────────────────
    private function buildCardPaymentOption(array $p): array
    {
        $token = $this->paymentToken($p);
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
            $cardName = $this->identifiedCardName($p);

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
            $card = ['card' => $this->withThreeDS($cardBody, $p, !empty($p['is_moto']))];
        }
        return $card ?: ['card' => []];
    }

    /** Ecom REST /payment.do — 3DS on the card. MOTO / advice skip challenge. */
    private function withThreeDS(array $cardBody, array $params, bool $isMoto): array
    {
        if ($isMoto || !empty($params['direct_advice'])) {
            return $cardBody;
        }
        require_once __DIR__ . '/../CardScaService.php';
        if (!CardScaService::shouldChallenge($params)) {
            return $cardBody;
        }
        $cardBody['threeD'] = $this->buildThreeDSDetails($params, $this->publicSiteUrl());
        return $cardBody;
    }

    // ── بناء billing address ────────────────────────────────
    private function buildBillingAddress(array $p): array
    {
        // cardHolderName — أحرف إنجليزية فقط بدون رموز خاصة
        $nameParts = explode(' ', $this->identifiedCardName($p), 2);
        $addr = [
            'firstName' => $nameParts[0] ?: '',
            'lastName'  => $nameParts[1] ?? ($nameParts[0] ?: ''),
            'email'     => $this->identifiedEmail($p),
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
            'methodCompletionInd' => 'U',
            'platformType' => '02',
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
        return $txn === 'APPROVED';
    }

    public static function mapTransactionStatus(string $status): string
    {
        return match (strtoupper(trim($status))) {
            'OK', 'APPROVED', 'SUCCESS', 'SETTLED', 'CAPTURED' => 'completed',
            'DECLINED', 'FAILED', 'ERROR', 'FAIL' => 'failed',
            'CANCELLED', 'CANCELED' => 'cancelled',
            'REFUNDED' => 'refunded',
            'PENDING', 'UPDATE' => 'pending',
            default => 'pending',
        };
    }

    public static function shouldApplyTransactionStatus(string $currentStatus, string $incomingStatus): bool
    {
        $current = strtolower(trim($currentStatus));
        $incoming = strtolower(trim($incomingStatus));
        if ($current === $incoming) {
            return true;
        }
        if ($current === 'refunded') {
            return false;
        }
        if ($current === 'completed') {
            return $incoming === 'refunded';
        }
        if (in_array($current, ['failed', 'cancelled'], true)) {
            return in_array($incoming, ['completed', 'refunded'], true);
        }
        return true;
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
        $success     = $txnStatus === 'APPROVED';
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
            'amount'           => $raw['totalAmount'] ?? $raw['amount'] ?? null,
            'currency'         => $raw['currency']           ?? null,
            'message'          => $needs3ds ? '3DS_REQUIRED' : $reason,
            'decline_reason'   => $success || $needs3ds ? null : $reason,
            'requires_3ds'     => $needs3ds,
            'redirect_url'     => $needs3ds ? $redirectUrl : '',
            'raw'              => $raw,
            'settlement_target'=> 'gateway',
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
        $scan = $transReason . ' ' . $gwCode . ' ' . $errCode . ' ' . $extCode . ' ' . $reason . ' ' . $blob;
        $filterHit = (bool) preg_match('/custom\s*fraud\s*screen|filter\s*error|fraud\s*filter|FILTERED/i', $scan . ' ' . $txnStatus . ' ' . $status);
        if ($filterHit) {
            return 'Nuvei Filter Error — Custom Fraud Screen Filter — قاعدة على نفس حساب Transcendio (Client 251014941) أوقفت العملية حسب المبلغ أو BIN أو الدولة. ليست شحناً مجهولاً وليست عطل الربط.';
        }
        if (preg_match('/1507/', $scan)) {
            $gwCode = '1507';
        } elseif ($gwCode === '' && preg_match('/\b(1011|1007|1106|1019)\b/', $scan, $m)) {
            $gwCode = $m[1];
        }
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
            $explain = 'Generic Decline من بنك الإصدار على نفس حساب Transcendio / Network International UAE (الوصف Transcendio FZ-LLC). ليست شحناً مجهولاً. لا تعيد نفس PAN بعد تكرار الرفض.';
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
        if ($this->configurationError !== '') {
            return ['status' => 'ERROR', 'reason' => $this->configurationError];
        }

        $path = ltrim($endpoint, '/');
        if (!str_ends_with($path, '.do')) {
            $path .= '.do';
        }
        $payload = json_encode($body);
        if ($payload === false) {
            return ['status' => 'ERROR', 'reason' => 'Nuvei request payload could not be encoded'];
        }

        $url = rtrim($this->baseUrl, '/') . '/' . $path;
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
            $this->log('cURL ' . $error . ' ' . $url);
            return [
                'status' => 'ERROR',
                'reason' => 'cURL: ' . $error . '. Request was not retried; verify the Nuvei transaction status before retrying.',
            ];
        }
        if ($httpCode !== 200) {
            $this->log('HTTP ' . $httpCode . ' ' . $url);
            return [
                'status' => 'ERROR',
                'reason' => 'HTTP ' . $httpCode . '. Request was not retried; verify the Nuvei transaction status before retrying.',
            ];
        }
        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            return [
                'status' => 'ERROR',
                'reason' => 'Invalid JSON response. Request was not retried; verify the Nuvei transaction status before retrying.',
            ];
        }
        $decoded['_http_code'] = $httpCode;
        return $decoded;
    }
}
