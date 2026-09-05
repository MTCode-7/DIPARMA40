<?php
/**
 * DI PARMA | Create Transaction ID — All Gateways
 * POST: { gateway, amount, currency, email, csrf_token, ...extra }
 * Returns: { success, transaction_id, gateway, reference, message }
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success'=>false,'message'=>'Unauthorized']);
    exit;
}

$currentUser = db()->find('users', ['id' => intval($_SESSION['user_id'])]);
if (!$currentUser || ($currentUser['status'] ?? 'inactive') !== 'active') {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Account approval is required before payment access']);
    exit;
}

$payload  = json_decode(file_get_contents('php://input'), true) ?: $_POST;

if (!verifyCsrfToken($payload['csrf_token'] ?? '')) {
    echo json_encode(['success'=>false,'message'=>'CSRF invalid']);
    exit;
}

$gateway  = strtolower(trim($payload['gateway']  ?? ''));
$amount   = floatval($payload['amount']   ?? 1);
$currency = strtoupper(trim($payload['currency'] ?? 'USD'));
$email    = trim($payload['email'] ?? 'guest@diparmas.com');
$ref      = 'TXN' . strtoupper(bin2hex(random_bytes(5))) . date('Ymd');

function httpPost(string $url, array $headers, string $body, int $timeout = 20): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => $resp, 'error' => $err, 'http_code' => $code];
}

function saveToDb(string $ref, string $gateway, float $amount, string $currency, string $email, array $data): void {
    try {
        $db = db();
        $db->execute(
            "INSERT INTO dp_transactions
             (reference, gateway, amount, currency, customer_email, status,
              transaction_type, security_mode, gateway_response, created_at)
             VALUES (?,?,?,?,?,'pending','Create TxID','2D',?,NOW())",
            [$ref, $gateway, $amount, $currency, $email, json_encode($data)]
        );
    } catch (Exception $e) { /* silent */ }
}

$result = ['success'=>false,'message'=>'Gateway not supported','gateway'=>$gateway,'reference'=>$ref];

switch ($gateway) {

    // ── Nuvei ─────────────────────────────────────────────
    case 'nuvei':
        $mId = trim((string)getenv('NUVEI_MERCHANT_ID'));
        $sId = trim((string)getenv('NUVEI_SITE_ID'));
        $key = trim((string)getenv('NUVEI_SECRET_KEY'));
        if ($mId === '' || $sId === '' || $key === '') {
            $result['message'] = 'Nuvei credentials are not configured';
            break;
        }
        $ts  = date('YmdHis');
        $amt = number_format($amount, 2, '.', '');

        // Step 1: Get Session Token (checksum = merchantId+siteId+clientRequestId+timeStamp+secretKey)
        $cs1 = hash('sha256', $mId.$sId.$ref.$ts.$key);
        $body1 = json_encode([
            'merchantId'      => $mId,
            'merchantSiteId'  => $sId,
            'clientRequestId' => $ref,
            'timeStamp'       => $ts,
            'checksum'        => $cs1,
        ]);
        $r1 = httpPost('https://secure.nuvei.com/ppp/api/v1/getSessionToken.do',
            ['Content-Type: application/json'], $body1);
        $d1 = json_decode($r1['body'], true) ?: [];
        if (($d1['status']??'') !== 'SUCCESS' || empty($d1['sessionToken'])) {
            $result['message'] = 'Nuvei getSessionToken failed: ' . ($d1['reason'] ?? $d1['errCode'] ?? 'unknown');
            break;
        }
        $sessionToken = $d1['sessionToken'];

        // Step 2: Open Order using session token
        $ref2 = $ref . '_O';
        $ts2  = date('YmdHis');
        $cs2  = hash('sha256', $mId.$sId.$ref2.$ts2.$key);
        $body2 = json_encode([
            'merchantId'      => $mId,
            'merchantSiteId'  => $sId,
            'sessionToken'    => $sessionToken,
            'clientRequestId' => $ref2,
            'clientUniqueId'  => $ref2,
            'amount'          => $amt,
            'currency'        => $currency,
            'timeStamp'       => $ts2,
            'checksum'        => $cs2,
            'userTokenId'     => $email,
            'billingAddress'  => ['email' => $email],
            'transactionType' => 'Auth',
        ]);
        $r2 = httpPost('https://secure.nuvei.com/ppp/api/v1/openOrder.do',
            ['Content-Type: application/json'], $body2);
        $d = json_decode($r2['body'], true) ?: [];
        if (($d['status']??'') === 'SUCCESS' && !empty($d['sessionToken'])) {
            saveToDb($ref,'nuvei',$amount,$currency,$email,[
                'session_token' => $d['sessionToken'],
                'order_id'      => $d['orderId'] ?? '',
                'first_token'   => $sessionToken,
            ]);
            $result = ['success'=>true,'transaction_id'=>$d['sessionToken'],
                'order_id'=>$d['orderId']??'','reference'=>$ref,
                'gateway'=>'nuvei','message'=>'Nuvei Transaction ID created'];
        } else {
            // fallback: استخدم الـ session token الأول مباشرة
            saveToDb($ref,'nuvei',$amount,$currency,$email,['session_token'=>$sessionToken]);
            $result = ['success'=>true,'transaction_id'=>$sessionToken,
                'order_id'=>'','reference'=>$ref,
                'gateway'=>'nuvei','message'=>'Nuvei Session Token created'];
        }
        break;

    // ── Stripe ────────────────────────────────────────────
    case 'stripe':
        $sk = getenv('STRIPE_SECRET_KEY') ?: '';
        if (!$sk) { $result['message'] = 'Stripe key missing'; break; }
        $amtCents = intval($amount * 100);
        $body = http_build_query([
            'amount'         => $amtCents,
            'currency'       => strtolower($currency),
            'capture_method' => 'manual',
            'description'    => 'DI PARMA — '.$ref,
            'metadata[reference]' => $ref,
        ]);
        $r = httpPost('https://api.stripe.com/v1/payment_intents',
            ['Authorization: Bearer '.$sk, 'Content-Type: application/x-www-form-urlencoded'], $body);
        $d = json_decode($r['body'], true) ?: [];
        if (!empty($d['id']) && str_starts_with($d['id'], 'pi_')) {
            saveToDb($ref,'stripe',$amount,$currency,$email,['payment_intent_id'=>$d['id'],'status'=>$d['status']]);
            $result = ['success'=>true,'transaction_id'=>$d['id'],
                'status'=>$d['status'],'reference'=>$ref,
                'gateway'=>'stripe','message'=>'Stripe Payment Intent created (capture_method=manual)'];
        } else {
            $result['message'] = $d['error']['message'] ?? 'Stripe error';
        }
        break;

    // ── PayPal ────────────────────────────────────────────
    case 'paypal':
        $clientId = getenv('PAYPAL_CLIENT_ID') ?: '';
        $secret   = getenv('PAYPAL_CLIENT_SECRET') ?: (getenv('PAYPAL_SECRET') ?: '');
        if (!$clientId || !$secret) { $result['message'] = 'PayPal credentials missing'; break; }
        // Get access token
        $tokenR = httpPost('https://api-m.paypal.com/v1/oauth2/token',
            ['Accept: application/json','Content-Type: application/x-www-form-urlencoded',
             'Authorization: Basic '.base64_encode($clientId.':'.$secret)],
            'grant_type=client_credentials');
        $tokenD = json_decode($tokenR['body'], true) ?: [];
        $token  = $tokenD['access_token'] ?? '';
        if (!$token) { $result['message'] = 'PayPal auth failed'; break; }
        // Create authorization
        $body = json_encode([
            'intent' => 'AUTHORIZE',
            'purchase_units' => [[
                'reference_id' => $ref,
                'amount' => ['currency_code'=>$currency,'value'=>number_format($amount,2,'.','')],
            ]],
        ]);
        $r = httpPost('https://api-m.paypal.com/v2/checkout/orders',
            ['Authorization: Bearer '.$token,'Content-Type: application/json'], $body);
        $d = json_decode($r['body'], true) ?: [];
        if (!empty($d['id'])) {
            $approveUrl = '';
            foreach (($d['links'] ?? []) as $link) {
                if ($link['rel'] === 'approve') { $approveUrl = $link['href']; break; }
            }
            saveToDb($ref,'paypal',$amount,$currency,$email,['order_id'=>$d['id'],'approve_url'=>$approveUrl]);
            $result = ['success'=>true,'transaction_id'=>$d['id'],
                'approve_url'=>$approveUrl,'reference'=>$ref,
                'gateway'=>'paypal','message'=>'PayPal Order created (intent=AUTHORIZE)'];
        } else {
            $result['message'] = $d['message'] ?? 'PayPal error';
        }
        break;

    // ── MyFatoorah ────────────────────────────────────────
    case 'myfatoorah':
        $apiKey = getenv('MYFAOORAH_API_KEY') ?: '';
        if (!$apiKey) { $result['message'] = 'MyFatoorah key missing'; break; }
        $body = json_encode([
            'InvoiceValue'         => $amount,
            'CurrencyIso'          => $currency,
            'CustomerEmail'        => $email,
            'CallBackUrl'          => getenv('APP_URL').'/crypto_confirm.php',
            'ErrorUrl'             => getenv('APP_URL').'/checkout.php',
            'Language'             => 'en',
            'CustomerReference'    => $ref,
        ]);
        $r = httpPost('https://api.myfatoorah.com/v2/SendPayment',
            ['Authorization: Bearer '.$apiKey,'Content-Type: application/json'], $body);
        $d = json_decode($r['body'], true) ?: [];
        $invoiceId = $d['Data']['InvoiceId'] ?? $d['Data']['PaymentId'] ?? '';
        if ($invoiceId) {
            saveToDb($ref,'myfatoorah',$amount,$currency,$email,['invoice_id'=>$invoiceId,'payment_url'=>$d['Data']['PaymentURL']??'']);
            $result = ['success'=>true,'transaction_id'=>(string)$invoiceId,
                'payment_url'=>$d['Data']['PaymentURL']??'','reference'=>$ref,
                'gateway'=>'myfatoorah','message'=>'MyFatoorah Invoice created'];
        } else {
            $result['message'] = $d['Message'] ?? $d['ValidationErrors'][0]['Error'] ?? 'MyFatoorah error';
        }
        break;

    // ── Wise ──────────────────────────────────────────────
    case 'wise':
        $apiKey = getenv('WISE_API_KEY') ?: '';
        if (!$apiKey) { $result['message'] = 'Wise key missing'; break; }
        // Get profile ID
        $profR = []; $ch = curl_init('https://api.transferwise.com/v1/profiles');
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$apiKey]]);
        $profD = json_decode(curl_exec($ch), true) ?: []; curl_close($ch);
        $profileId = $profD[0]['id'] ?? '';
        if (!$profileId) { $result['message'] = 'Wise profile not found'; break; }
        // Create quote
        $body = json_encode(['sourceCurrency'=>$currency,'targetCurrency'=>$currency,
            'sourceAmount'=>$amount,'profile'=>$profileId,'payOut'=>'BANK_TRANSFER']);
        $r = httpPost('https://api.transferwise.com/v2/quotes',
            ['Authorization: Bearer '.$apiKey,'Content-Type: application/json'], $body);
        $d = json_decode($r['body'], true) ?: [];
        $quoteId = $d['id'] ?? '';
        if ($quoteId) {
            saveToDb($ref,'wise',$amount,$currency,$email,['quote_id'=>$quoteId,'profile_id'=>$profileId]);
            $result = ['success'=>true,'transaction_id'=>$quoteId,
                'reference'=>$ref,'gateway'=>'wise',
                'message'=>'Wise Quote created — use as Transfer ID reference'];
        } else {
            $result['message'] = $d['errors'][0]['message'] ?? 'Wise error';
        }
        break;

    // ── Binance ───────────────────────────────────────────
    case 'binance':
        $apiKey = getenv('GATE_IO_API_KEY') ?: '';  // Binance Pay API Key
        $secret = getenv('GATE_IO_SECRET_KEY') ?: '';
        $ts     = (string)(time() * 1000);
        $nonce  = bin2hex(random_bytes(8));
        $body   = json_encode([
            'env'           => ['terminalType'=>'WEB'],
            'merchantTradeNo' => $ref,
            'orderAmount'   => number_format($amount, 2, '.', ''),
            'currency'      => $currency,
            'goods'         => ['goodsType'=>'01','goodsCategory'=>'Z000','referenceGoodsId'=>$ref,
                                'goodsName'=>'DI PARMA Payment','goodsDetail'=>''],
        ]);
        $payload2sign = $ts . "\n" . $nonce . "\n" . $body . "\n";
        $sig = strtoupper(hash_hmac('sha512', $payload2sign, $secret));
        $r = httpPost('https://bpay.binanceapi.com/binancepay/openapi/v2/order',
            ['Content-Type: application/json','BinancePay-Timestamp:'.$ts,
             'BinancePay-Nonce:'.$nonce,'BinancePay-Certificate-SN:'.$apiKey,
             'BinancePay-Signature:'.$sig], $body);
        $d = json_decode($r['body'], true) ?: [];
        $prepayId = $d['data']['prepayId'] ?? '';
        if ($prepayId) {
            saveToDb($ref,'binance',$amount,$currency,$email,['prepay_id'=>$prepayId,'checkout_url'=>$d['data']['checkoutUrl']??'']);
            $result = ['success'=>true,'transaction_id'=>$prepayId,
                'checkout_url'=>$d['data']['checkoutUrl']??'','reference'=>$ref,
                'gateway'=>'binance','message'=>'Binance Pay Order created'];
        } else {
            $result['message'] = $d['errorMessage'] ?? $d['code'] ?? 'Binance error';
        }
        break;

    // ── Whop ──────────────────────────────────────────────
    case 'whop':
        $apiKey       = getenv('WHOP_API_KEY') ?: '';
        $checkoutUrl  = getenv('WHOP_CHECKOUT_URL') ?: 'https://whop.com/checkout/plan_A4P3nPnySfV8n';
        // Whop لا يدعم إنشاء transaction بدون checkout — نرجع الـ checkout URL
        saveToDb($ref,'whop',$amount,$currency,$email,['checkout_url'=>$checkoutUrl]);
        $result = ['success'=>true,'transaction_id'=>'whop_'.time(),
            'checkout_url'=>$checkoutUrl,'reference'=>$ref,
            'gateway'=>'whop','message'=>'Use Whop Checkout URL to initiate payment'];
        break;

    // ── Gate.io ───────────────────────────────────────────
    case 'gate_io':
        $apiKey = getenv('GATE_IO_API_KEY') ?: '';
        $secret = getenv('GATE_IO_SECRET_KEY') ?: '';
        if (!$apiKey || !$secret) { $result['message'] = 'Gate.io credentials missing'; break; }
        $ts   = (string)time();
        $body = json_encode([
            'out_order_id' => $ref,
            'amount'       => number_format($amount, 2, '.', ''),
            'currency'     => $currency,
            'subject'      => 'DI PARMA — '.$ref,
        ]);
        $sign = hash_hmac('sha512', $apiKey.$ts.$body, $secret);
        $r = httpPost('https://api.gateio.ws/api/pay/orders',
            ['Content-Type: application/json','KEY:'.$apiKey,
             'TIMESTAMP:'.$ts,'SIGN:'.$sign], $body);
        $d = json_decode($r['body'], true) ?: [];
        $orderId = $d['order_id'] ?? $d['data']['order_id'] ?? '';
        if ($orderId) {
            saveToDb($ref,'gate_io',$amount,$currency,$email,['order_id'=>$orderId]);
            $result = ['success'=>true,'transaction_id'=>$orderId,
                'reference'=>$ref,'gateway'=>'gate_io','message'=>'Gate.io Order created'];
        } else {
            $result['message'] = $d['message'] ?? $d['label'] ?? 'Gate.io error';
        }
        break;
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
