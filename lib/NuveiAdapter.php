<?php
/**
 * ============================================================
 * DI PARMA | NuveiAdapter — Nuvei / SafeCharge API
 * ============================================================
 * يدعم: Purchase, Auth, Capture, Refund, Void, Balance
 * مرتبط بـ: Mashreq Bank (TRANSCENDIO FZ-LLC)
 * ============================================================
 */
class NuveiAdapter
{
    private string $merchantId;
    private string $siteId;
    private string $secretKey;
    private string $baseUrl;
    private bool   $isLive;

    // Nuvei API Endpoints
    const URL_LIVE    = 'https://secure.nuvei.com/ppp/api/v1/';
    const URL_SANDBOX = 'https://ppp-test.nuvei.com/ppp/api/v1/';

    public function __construct()
    {
        $this->merchantId = defined('NUVEI_MERCHANT_ID') ? NUVEI_MERCHANT_ID : getenv('NUVEI_MERCHANT_ID');
        $this->siteId     = defined('NUVEI_SITE_ID')     ? NUVEI_SITE_ID     : getenv('NUVEI_SITE_ID');
        $this->secretKey  = defined('NUVEI_SECRET_KEY')  ? NUVEI_SECRET_KEY  : getenv('NUVEI_SECRET_KEY');
        $env              = defined('NUVEI_ENVIRONMENT')  ? NUVEI_ENVIRONMENT : getenv('NUVEI_ENVIRONMENT');
        $this->isLive     = ($env === 'live' || $env === 'production');
        $this->baseUrl    = $this->isLive ? self::URL_LIVE : self::URL_SANDBOX;
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
                'customField2' => $params['ledger_addr'] ?? 'TEwLFWlwK55b7PuFfzgH1H2f3xs3pLgLn2',
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
    public function capture(array $params): array
    {
        $sessionRes = $this->getSessionToken();
        if (($sessionRes['status'] ?? '') !== 'SUCCESS') {
            return ['success' => false, 'message' => 'Session token failed'];
        }

        $ts          = date('YmdHis');
        $clientReqId = 'POS-CAP-' . strtoupper(substr(uniqid(), 0, 8));
        $amount      = number_format((float)$params['amount'], 2, '.', '');
        $currency    = $params['currency'] ?? 'USD';
        $clientUniqueId = (string)($params['client_unique_id'] ?? $clientReqId);
        $authCode    = trim((string)($params['auth_code'] ?? ''));
        $authorizedAmount = isset($params['authorized_amount'])
            ? (float)$params['authorized_amount']
            : null;

        if ($authCode === '') {
            return ['success' => false, 'message' => 'Nuvei authCode is required for settlement'];
        }

        if ($authorizedAmount !== null && (float)$amount > $authorizedAmount) {
            return [
                'success' => false,
                'message' => 'Settlement amount cannot exceed the original authorized amount',
            ];
        }

        $checksum = $this->buildChecksum([
            $this->merchantId,
            $this->siteId,
            $clientReqId,
            $clientUniqueId,
            $amount,
            $currency,
            $params['related_transaction_id'],
            $authCode,
            $this->secretKey,
        ]);

        $result = $this->request('settleTransaction', [
            'sessionToken'          => $sessionRes['sessionToken'],
            'merchantId'            => $this->merchantId,
            'merchantSiteId'        => $this->siteId,
            'clientRequestId'       => $clientReqId,
            'clientUniqueId'        => $clientUniqueId,
            'amount'                => $amount,
            'currency'              => $currency,
            'relatedTransactionId'  => $params['related_transaction_id'],
            'authCode'              => $authCode,
            'timeStamp'             => $ts,
            'checksum'              => $checksum,
        ]);

        return $this->normalizeResponse('capture', $result, $clientReqId);
    }

    // ── Refund — استرداد ───────────────────────────────────
    public function refund(array $params): array
    {
        $sessionRes = $this->getSessionToken();
        if (($sessionRes['status'] ?? '') !== 'SUCCESS') {
            return ['success' => false, 'message' => 'Session token failed'];
        }

        $ts          = date('YmdHis');
        $clientReqId = 'POS-REF-' . strtoupper(substr(uniqid(), 0, 8));
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

        $result = $this->request('refundTransaction', [
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
                            : 'client@diparmas.com',
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
            'ipAddress' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '1.1.1.1',
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
            'rrn'              => $raw['rrn']                 ?? $raw['externalTransactionId'] ?? substr(md5(uniqid()), 0, 12),
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
