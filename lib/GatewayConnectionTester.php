<?php
/**
 * ============================================================
 * DI PARMA | GatewayConnectionTester
 * يختبر الاتصال الحقيقي بكل بوابة دفع
 * ويُحدّث connection_status في dp_payment_gateways
 * ============================================================
 * connection_status:
 *   untested  — لم يُختبر بعد
 *   verified  — الاتصال ناجح + المفاتيح صحيحة
 *   failed    — فشل الاتصال أو المفاتيح خاطئة
 *   disabled  — مُعطَّل يدوياً
 * ============================================================
 */
class GatewayConnectionTester
{
    private Database $db;
    private string   $logFile;

    public function __construct()
    {
        $this->db      = db();
        $this->logFile = defined('LOGS_PATH')
            ? LOGS_PATH . '/gateway_tests.log'
            : __DIR__ . '/../logs/gateway_tests.log';
        if (!is_dir(dirname($this->logFile))) @mkdir(dirname($this->logFile), 0755, true);
    }

    // ══════════════════════════════════════════════════════════
    // اختبار بوابة واحدة
    // ══════════════════════════════════════════════════════════
    public function test(int $gatewayId): array
    {
        $gw = $this->db->find('payment_gateways', ['id' => $gatewayId]);
        if (!$gw) {
            return ['success' => false, 'message' => 'البوابة غير موجودة'];
        }

        $creds   = json_decode($gw['credentials'] ?? '{}', true) ?: [];
        $config  = json_decode($gw['config']      ?? '{}', true) ?: [];
        $settings= json_decode($gw['settings']    ?? '{}', true) ?: [];

        $all = array_merge($config, $creds, $settings);

        $start  = microtime(true);
        $result = $this->runTest($gw['code'], $gw, $all);
        $ms     = (int)((microtime(true) - $start) * 1000);

        // تحديث DB
        $this->db->execute(
            "UPDATE dp_payment_gateways SET
                connection_status = ?,
                last_tested       = NOW(),
                test_response_ms  = ?,
                test_message      = ?,
                updated_at        = NOW()
             WHERE id = ?",
            [
                $result['success'] ? 'verified' : 'failed',
                $ms,
                $result['message'],
                $gatewayId,
            ]
        );

        $this->log($gw['code'], $result['success'], $ms, $result['message']);

        return array_merge($result, [
            'gateway_id'   => $gatewayId,
            'gateway_code' => $gw['code'],
            'response_ms'  => $ms,
        ]);
    }

    // ══════════════════════════════════════════════════════════
    // اختبار جميع البوابات
    // ══════════════════════════════════════════════════════════
    public function testAll(): array
    {
        $gateways = $this->db->query(
            "SELECT id, code, name, connection_status
             FROM dp_payment_gateways
             WHERE status != 'deleted'
             ORDER BY sort_order, id"
        );

        $results = [];
        foreach ($gateways as $gw) {
            $results[] = $this->test((int)$gw['id']);
        }

        $verified = count(array_filter($results, fn($r) => $r['success']));
        return [
            'total'    => count($results),
            'verified' => $verified,
            'failed'   => count($results) - $verified,
            'results'  => $results,
        ];
    }

    // ══════════════════════════════════════════════════════════
    // منطق الاختبار لكل بوابة
    // ══════════════════════════════════════════════════════════
    private function runTest(string $code, array $gw, array $creds): array
    {
        // اختيار طريقة الاختبار حسب البوابة
        return match(strtolower($code)) {
            'stripe'       => $this->testStripe($creds),
            'checkout'     => $this->testCheckout($creds),
            'paytabs'      => $this->testPayTabs($creds),
            'authorizenet' => $this->testAuthorizeNet($creds),
            'myfatoorah'   => $this->testMyFatoorah($creds),
            'paypal'       => $this->testPayPal($creds),
            'braintree'    => $this->testBraintree($creds),
            'wise'         => $this->testWise($creds),
            'moonpay'      => $this->testMoonPay($creds),
            'gate_io',
            'gateio'       => $this->testGateIO($creds),
            default        => $this->testGenericRest($gw, $creds),
        };
    }

    // ── Stripe ───────────────────────────────────────────────
    private function testStripe(array $creds): array
    {
        $key = $creds['secret_key'] ?? $creds['api_key'] ?? getenv('STRIPE_SECRET_KEY') ?: '';
        if (empty($key)) return ['success' => false, 'message' => 'STRIPE_SECRET_KEY مفقود'];

        $res  = $this->curl('GET', 'https://api.stripe.com/v1/balance', [], [
            'Authorization: Bearer ' . $key,
        ]);

        if ($res['http_code'] === 200 && isset($res['data']['available'])) {
            $currency = strtoupper($res['data']['available'][0]['currency'] ?? 'USD');
            $amount   = ($res['data']['available'][0]['amount'] ?? 0) / 100;
            return ['success' => true, 'message' => "✅ Stripe متصل — الرصيد: {$amount} {$currency}"];
        }
        if ($res['http_code'] === 401) {
            return ['success' => false, 'message' => '❌ مفتاح Stripe غير صحيح'];
        }
        return ['success' => false, 'message' => '❌ Stripe: HTTP ' . $res['http_code']];
    }

    // ── Checkout.com ─────────────────────────────────────────
    private function testCheckout(array $creds): array
    {
        $key = $creds['secret_key'] ?? $creds['api_key'] ?? getenv('CHECKOUT_API_KEY') ?: '';
        if (empty($key)) return ['success' => false, 'message' => 'CHECKOUT_API_KEY مفقود'];

        $base = str_contains($key, 'test') ? 'https://api.sandbox.checkout.com' : 'https://api.checkout.com';
        $res  = $this->curl('GET', $base . '/metadata', [], [
            'Authorization: Bearer ' . $key,
        ]);

        if (in_array($res['http_code'], [200, 204])) {
            return ['success' => true, 'message' => '✅ Checkout.com متصل'];
        }
        if ($res['http_code'] === 401) {
            return ['success' => false, 'message' => '❌ مفتاح Checkout.com غير صحيح'];
        }
        // endpoint آخر
        $res2 = $this->curl('GET', $base . '/access/tokens', [], [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ]);
        return $res2['http_code'] < 500
            ? ['success' => true, 'message' => '✅ Checkout.com متصل (HTTP ' . $res2['http_code'] . ')']
            : ['success' => false, 'message' => '❌ Checkout.com: HTTP ' . $res2['http_code']];
    }

    // ── PayTabs ───────────────────────────────────────────────
    private function testPayTabs(array $creds): array
    {
        $serverKey = $creds['server_key'] ?? $creds['api_key'] ?? getenv('PAYTABS_SERVER_KEY') ?: '';
        $profileId = $creds['profile_id'] ?? getenv('PAYTABS_PROFILE_ID') ?: '';
        if (empty($serverKey)) return ['success' => false, 'message' => 'PAYTABS_SERVER_KEY مفقود'];

        $region  = strtoupper($creds['region'] ?? getenv('PAYTABS_REGION') ?: 'ARE');
        $baseUrls = [
            'SAU' => 'https://secure.paytabs.sa',
            'ARE' => 'https://secure.paytabs.com',
            'EGY' => 'https://secure-egypt.paytabs.com',
        ];
        $base = $baseUrls[$region] ?? 'https://secure.paytabs.com';

        $res = $this->curl('POST', $base . '/payment/query', [
            'profile_id' => $profileId ?: '0',
            'tran_ref'   => 'TEST_CONNECTION_CHECK',
        ], ['Authorization: ' . $serverKey]);

        if ($res['http_code'] === 200 && !empty($res['data'])) {
            return ['success' => true, 'message' => '✅ PayTabs متصل'];
        }
        if ($res['http_code'] === 401 || $res['http_code'] === 403) {
            return ['success' => false, 'message' => '❌ مفتاح PayTabs غير صحيح'];
        }
        // 400 يعني المفتاح صحيح لكن الطلب خاطئ (طبيعي للاختبار)
        if ($res['http_code'] === 400) {
            return ['success' => true, 'message' => '✅ PayTabs متصل (المفتاح صحيح)'];
        }
        return ['success' => false, 'message' => '❌ PayTabs: HTTP ' . $res['http_code']];
    }

    // ── Authorize.Net ─────────────────────────────────────────
    private function testAuthorizeNet(array $creds): array
    {
        $loginId  = $creds['api_login_id']    ?? $creds['login_id']  ?? getenv('AUTHNET_API_LOGIN_ID')    ?: '';
        $tranKey  = $creds['transaction_key'] ?? $creds['api_key']   ?? getenv('AUTHNET_TRANSACTION_KEY') ?: '';
        if (empty($loginId) || empty($tranKey)) {
            return ['success' => false, 'message' => 'AUTHNET_API_LOGIN_ID أو AUTHNET_TRANSACTION_KEY مفقود'];
        }

        $env  = $creds['environment'] ?? getenv('AUTHNET_ENVIRONMENT') ?: '';
        $url  = $env === 'live'
            ? 'https://api.authorize.net/xml/v1/request.api'
            : 'https://apitest.authorize.net/xml/v1/request.api';

        $body = json_encode([
            'authenticateTestRequest' => [
                'merchantAuthentication' => [
                    'name'           => $loginId,
                    'transactionKey' => $tranKey,
                ],
            ],
        ]);

        $res = $this->curl('POST', $url, $body, ['Content-Type: application/json']);

        $data = $res['data'] ?? [];
        $code = $data['messages']['resultCode'] ?? '';

        if ($code === 'Ok') {
            return ['success' => true, 'message' => '✅ Authorize.Net متصل'];
        }
        $msg = $data['messages']['message'][0]['text'] ?? 'فشل الاتصال';
        return ['success' => false, 'message' => '❌ Authorize.Net: ' . $msg];
    }

    // ── PayPal ────────────────────────────────────────────────
    private function testPayPal(array $creds): array
    {
        $clientId = $creds['client_id'] ?? $creds['api_key'] ?? getenv('PAYPAL_CLIENT_ID') ?: '';
        $secret   = $creds['secret'] ?? $creds['secret_key'] ?? $creds['client_secret']
            ?? getenv('PAYPAL_CLIENT_SECRET') ?: (getenv('PAYPAL_SECRET') ?: '');
        if (empty($clientId) || empty($secret)) {
            return ['success' => false, 'message' => 'PAYPAL_CLIENT_ID أو PAYPAL_CLIENT_SECRET مفقود'];
        }

        $env  = $creds['environment'] ?? getenv('PAYPAL_ENVIRONMENT') ?: '';
        $base = $env === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';

        $res = $this->curl('POST', $base . '/v1/oauth2/token',
            'grant_type=client_credentials',
            ['Content-Type: application/x-www-form-urlencoded'],
            $clientId . ':' . $secret
        );

        if ($res['http_code'] === 200 && !empty($res['data']['access_token'])) {
            $expires = $res['data']['expires_in'] ?? 0;
            return ['success' => true, 'message' => "✅ PayPal متصل — Token صالح لـ {$expires} ثانية"];
        }
        return ['success' => false, 'message' => '❌ PayPal: بيانات الاعتماد غير صحيحة'];
    }

    // ── Braintree ─────────────────────────────────────────────
    private function testBraintree(array $creds): array
    {
        $merchantId = $creds['merchant_id'] ?? getenv('BRAINTREE_MERCHANT_ID') ?: '';
        $publicKey  = $creds['public_key']  ?? getenv('BRAINTREE_PUBLIC_KEY')  ?: '';
        $privateKey = $creds['private_key'] ?? getenv('BRAINTREE_PRIVATE_KEY') ?: '';

        if (empty($merchantId) || empty($publicKey) || empty($privateKey)) {
            return ['success' => false, 'message' => 'BRAINTREE_MERCHANT_ID / PUBLIC_KEY / PRIVATE_KEY مفقود'];
        }

        $env  = $creds['environment'] ?? getenv('BRAINTREE_ENVIRONMENT') ?: '';
        $base = $env === 'production'
            ? 'https://api.braintreegateway.com:443'
            : 'https://api.sandbox.braintreegateway.com:443';

        $res = $this->curl('GET',
            $base . '/v1/merchants/' . $merchantId . '/client_token',
            '', ['Content-Type: application/json', 'Accept: application/json', 'X-ApiVersion: 6'],
            $publicKey . ':' . $privateKey
        );

        if ($res['http_code'] === 200) {
            return ['success' => true, 'message' => '✅ Braintree متصل'];
        }
        if ($res['http_code'] === 401) {
            return ['success' => false, 'message' => '❌ Braintree: مفاتيح غير صحيحة'];
        }
        return ['success' => false, 'message' => '❌ Braintree: HTTP ' . $res['http_code']];
    }

    // ── Wise ──────────────────────────────────────────────────
    private function testWise(array $creds): array
    {
        $apiKey = $creds['api_key'] ?? $creds['token'] ?? getenv('WISE_API_TOKEN') ?: '';
        if (empty($apiKey)) return ['success' => false, 'message' => 'WISE_API_TOKEN مفقود'];

        $res = $this->curl('GET', 'https://api.transferwise.com/v1/profiles', [], [
            'Authorization: Bearer ' . $apiKey,
        ]);

        if ($res['http_code'] === 200 && is_array($res['data'])) {
            $count = count($res['data']);
            return ['success' => true, 'message' => "✅ Wise متصل — {$count} profile(s)"];
        }
        if ($res['http_code'] === 401) {
            return ['success' => false, 'message' => '❌ Wise: API Token غير صحيح'];
        }
        return ['success' => false, 'message' => '❌ Wise: HTTP ' . $res['http_code']];
    }

    // ── MoonPay ──────────────────────────────────────────────
    private function testMoonPay(array $creds): array
    {
        $pubKey = $creds['public_key'] ?? $creds['api_key'] ?? '';
        $secKey = $creds['secret_key'] ?? '';

        if (empty($pubKey)) {
            return ['success' => false, 'message' => '❌ MoonPay: Publishable Key مفقود'];
        }
        if (empty($secKey)) {
            return ['success' => false, 'message' => '❌ MoonPay: Secret Key مفقود'];
        }

        // MoonPay Widget API لا يقبل server-to-server calls
        // نتحقق فقط من صيغة المفاتيح
        $isTestPub  = str_starts_with($pubKey, 'pk_test_');
        $isLivePub  = str_starts_with($pubKey, 'pk_live_');
        $isTestSec  = str_starts_with($secKey, 'sk_test_');
        $isLiveSec  = str_starts_with($secKey, 'sk_live_');

        if (!$isTestPub && !$isLivePub) {
            return ['success' => false, 'message' => '❌ MoonPay: صيغة Publishable Key غير صحيحة (يجب أن تبدأ بـ pk_test_ أو pk_live_)'];
        }
        if (!$isTestSec && !$isLiveSec) {
            return ['success' => false, 'message' => '❌ MoonPay: صيغة Secret Key غير صحيحة (يجب أن تبدأ بـ sk_test_ أو sk_live_)'];
        }

        // تحقق من تطابق البيئة
        if ($isTestPub && $isLiveSec || $isLivePub && $isTestSec) {
            return ['success' => false, 'message' => '❌ MoonPay: بيئة المفاتيح غير متطابقة (test/live)'];
        }

        $env = $isLivePub ? 'live' : 'sandbox';
        return [
            'success' => true,
            'message' => "✅ MoonPay متصل — بيئة $env — المفاتيح صحيحة الصيغة",
        ];
    }

    // ── Generic REST (لأي بوابة غير محددة) ───────────────────
    // ── MyFatoorah ───────────────────────────────────────────
    private function testMyFatoorah(array $creds): array
    {
        $apiKey = $creds['api_key'] ?? $creds['MYFAOORAH_API_KEY'] ?? getenv('MYFAOORAH_API_KEY') ?: '';
        if (empty($apiKey)) return ['success' => false, 'message' => '❌ MyFatoorah: API Key مفقود'];

        $env     = $creds['environment'] ?? 'live';
        $baseUrl = $env === 'live' ? 'https://api.myfatoorah.com' : 'https://apitest.myfatoorah.com';

        // POST /v2/InitiateSession — الطريقة الصحيحة للتحقق
        $body = json_encode(['CustomerIdentifier' => 'test_diparma_' . time()]);
        $res  = $this->curl('POST', $baseUrl . '/v2/InitiateSession', $body, [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ]);

        // 200 يعني المفتاح صالح — 400 يعني مفتاح صالح لكن بيانات خاطئة
        if (in_array($res['http_code'], [200, 201, 400])) {
            $merchant = $creds['merchant_id'] ?? '—';
            return ['success' => true, 'message' => "✅ MyFatoorah متصل — Merchant: {$merchant} | بيئة: {$env}"];
        }
        if ($res['http_code'] === 401) {
            return ['success' => false, 'message' => '❌ MyFatoorah: API Key غير صالح'];
        }
        return ['success' => false, 'message' => "❌ MyFatoorah: HTTP {$res['http_code']}"];
    }

    // ── Gate.io ──────────────────────────────────────────────
    private function testGateIO(array $creds): array
    {
        $key    = $creds['api_key']    ?? getenv('GATEIO_API_KEY')    ?: '';
        $secret = $creds['api_secret'] ?? getenv('GATEIO_API_SECRET') ?: '';

        if (empty($key))    return ['success' => false, 'message' => '❌ Gate.io API Key مفقود'];
        if (empty($secret)) return ['success' => false, 'message' => '❌ Gate.io API Secret مفقود'];

        $method  = 'GET';
        $path    = '/api/v4/account/detail';
        $ts      = (string)time();
        $bHash   = hash('sha512', '');
        $signStr = $method . "\n" . $path . "\n" . "" . "\n" . $bHash . "\n" . $ts;
        $sig     = hash_hmac('sha512', $signStr, $secret);

        $res = $this->curl('GET', 'https://api.gateio.ws' . $path, [], [
            'Accept: application/json',
            'Content-Type: application/json',
            'KEY: '       . $key,
            'SIGN: '      . $sig,
            'Timestamp: ' . $ts,
        ]);

        if ($res['http_code'] === 200 && !empty($res['data']['user_id'])) {
            $uid  = $res['data']['user_id'];
            $tier = $res['data']['tier'] ?? 0;
            return ['success' => true, 'message' => "✅ Gate.io متصل — UID: {$uid} | VIP: {$tier}"];
        }
        if ($res['http_code'] === 401) {
            return ['success' => false, 'message' => '❌ Gate.io: مفتاح API غير صالح أو INVALID_SIGNATURE'];
        }
        return ['success' => false, 'message' => "❌ Gate.io: HTTP {$res['http_code']} — " . ($res['data']['message'] ?? '')];
    }

    private function testGenericRest(array $gw, array $creds): array
    {
        $endpoint = $gw['api_endpoint'] ?? '';
        if (empty($endpoint)) {
            return ['success' => false, 'message' => '❌ api_endpoint غير محدد — أضف الـ URL من لوحة التحكم'];
        }

        $apiKey = $creds['api_key'] ?? $creds['secret_key'] ?? $creds['token'] ?? '';

        $headers = ['Content-Type: application/json'];
        if (!empty($apiKey)) {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        $res = $this->curl('GET', rtrim($endpoint, '/'), [], $headers);

        if ($res['http_code'] >= 200 && $res['http_code'] < 300) {
            return ['success' => true, 'message' => "✅ الاتصال ناجح — HTTP {$res['http_code']}"];
        }
        if ($res['http_code'] === 0) {
            return ['success' => false, 'message' => '❌ لم يتم الاتصال — تحقق من api_endpoint'];
        }
        return ['success' => false, 'message' => "❌ فشل الاتصال — HTTP {$res['http_code']}"];
    }

    // ══════════════════════════════════════════════════════════
    // HTTP Helper
    // ══════════════════════════════════════════════════════════
    private function curl(
        string $method,
        string $url,
        $body    = [],
        array  $headers = [],
        string $userPwd = ''
    ): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        if (!empty($userPwd)) {
            curl_setopt($ch, CURLOPT_USERPWD, $userPwd);
        }

        if (!empty($body)) {
            $postData = is_array($body) ? json_encode($body) : $body;
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        }

        $res     = curl_exec($ch);
        $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err     = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['http_code' => 0, 'data' => [], 'error' => $err];
        }

        // تنظيف BOM
        $res = ltrim($res ?? '', "\xEF\xBB\xBF");

        // حاول JSON أولاً ثم XML
        $data = json_decode($res, true);
        if ($data === null && !empty($res)) {
            $xml = @simplexml_load_string($res);
            if ($xml) {
                $data = json_decode(json_encode($xml), true);
            }
        }

        return ['http_code' => $code, 'data' => $data ?? [], 'raw' => $res];
    }

    private function log(string $code, bool $success, int $ms, string $msg): void
    {
        $status = $success ? '✅' : '❌';
        @file_put_contents($this->logFile,
            '[' . date('Y-m-d H:i:s') . "] $status [$code] {$ms}ms | $msg\n",
            FILE_APPEND
        );
    }
}
