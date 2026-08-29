<?php
/**
 * ============================================================
 * DI PARMA | Direct Payment API
 * ============================================================
 * 
 * يدعم بوابات الدفع:
 *   - Stripe (بطاقات ائتمان)
 *   - MyFatoorah (بوابة الشرق الأوسط)
 *   - Checkout.com (بطاقات ائتمان)
 *   - PayPal (محفظة إلكترونية)
 *   - Wise (تحويلات بنكية)
 * 
 * ============================================================
 * المصادقة:
 *   - Session-based (للمستخدمين المسجلين)
 *   - API Key-based (للتطبيقات الخارجية)
 * 
 * ============================================================
 * نقاط النهاية (Endpoints):
 *   POST ?action=init_stripe          - إنشاء Stripe Payment Intent
 *   POST ?action=init_myfatoorah      - إنشاء MyFatoorah Session
 *   POST ?action=execute_myfatoorah   - تنفيذ MyFatoorah Payment
 *   POST ?action=init_checkout        - إنشاء Checkout.com Payment
 *   POST ?action=init_paypal          - إنشاء PayPal Order
 *   POST ?action=init_wise            - إنشاء Wise Quote
 *   GET  ?action=confirm_stripe       - التحقق من Stripe Payment
 *   GET  ?action=public_keys          - جلب المفاتيح العامة
 *   GET  ?action=gateways             - جلب قائمة البوابات المتاحة
 * ============================================================
 */

// ============================================================
// 1. إعدادات الرأس والأمان
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Key, X-Timestamp, X-Signature');

// معالجة طلبات OPTIONS (CORS Preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ============================================================
// 2. استيراد الملفات المطلوبة
// ============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

// بدء الجلسة (إذا لم تكن مبدوءة)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// 3. استيراد مكتبات البوابات (إن وجدت)
// ============================================================

// محاولة تحميل Stripe SDK
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

// ============================================================
// 4. التحقق من المصادقة (Session أو API Key)
// ============================================================

$userId = null;
$authMethod = 'none';

// 4.1 التحقق من Session
if (!empty($_SESSION['user_id'])) {
    $userId = (int)$_SESSION['user_id'];
    $authMethod = 'session';
}

// 4.2 التحقق من API Key (إذا لم يكن هناك Session)
if (!$userId) {
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';
    
    if (!empty($apiKey)) {
        try {
            $db = db();
            $rows = $db->query(
                "SELECT id, user_id, status FROM dp_api_clients 
                 WHERE api_key = ? AND status = 'active' LIMIT 1",
                [$apiKey]
            );
            
            if (!empty($rows[0])) {
                $userId = (int)($rows[0]['user_id'] ?? 0);
                $authMethod = 'api_key';
                $clientId = $rows[0]['id'];
            }
        } catch (Exception $e) {
            // تجاهل أخطاء قاعدة البيانات
        }
    }
}

// 4.3 إذا لم يتم المصادقة
if (!$userId) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized. Please login or provide valid API Key.',
        'auth_methods' => ['session', 'api_key']
    ]);
    exit;
}

// ============================================================
// 5. التحقق من CSRF Token (لطلبات POST من المتصفح)
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    if (!empty($csrfToken) && !verifyCsrfToken($csrfToken)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid CSRF token'
        ]);
        exit;
    }
}

// ============================================================
// 6. تحديد الإجراء المطلوب
// ============================================================

$action = strtolower(trim($_GET['action'] ?? $_POST['action'] ?? ''));
$payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;

// حذف csrf_token من المصفوفة لتجنب تسجيله
unset($payload['csrf_token']);

// ============================================================
// 7. معالجة الإجراءات
// ============================================================

try {
    $result = [];
    
    switch ($action) {
        
        // ════════════════════════════════════════════════════════
        // 1. STRIPE - إنشاء Payment Intent
        // ════════════════════════════════════════════════════════
        case 'init_stripe':
            if (!class_exists('Stripe\Stripe')) {
                throw new Exception('Stripe library not installed. Run: composer require stripe/stripe-php');
            }
            
            $amount = (float)($payload['amount'] ?? 0);
            $currency = strtoupper($payload['currency'] ?? 'USD');
            $reference = $payload['reference'] ?? generateReference('STR');
            
            if ($amount <= 0) {
                throw new Exception('Invalid amount');
            }
            
            // إعداد Stripe
            $stripeSecret = getenv('STRIPE_SECRET_KEY') ?: '';
            if (empty($stripeSecret)) {
                throw new Exception('STRIPE_SECRET_KEY not configured');
            }
            
            \Stripe\Stripe::setApiKey($stripeSecret);
            
            // إنشاء Payment Intent
            $intent = \Stripe\PaymentIntent::create([
                'amount' => (int)($amount * 100),
                'currency' => strtolower($currency),
                'metadata' => [
                    'user_id' => $userId,
                    'reference' => $reference,
                ],
                'statement_descriptor' => 'DI PARMA PAYMENT',
                'receipt_email' => $payload['email'] ?? '',
            ]);
            
            // تسجيل المعاملة
            $db = db();
            $db->insert('dp_transactions', [
                'reference' => $reference,
                'user_id' => $userId,
                'gateway' => 'stripe',
                'gateway_type' => 'card',
                'transaction_type' => 'purchase_3d',
                'amount' => $amount,
                'currency' => $currency,
                'status' => 'pending',
                'gateway_transaction_id' => $intent->id,
                'gateway_response' => json_encode($intent->toArray()),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            
            $result = [
                'success' => true,
                'payment_intent_id' => $intent->id,
                'client_secret' => $intent->client_secret,
                'reference' => $reference,
                'amount' => $amount,
                'currency' => $currency,
                'status' => $intent->status,
            ];
            break;
        
        // ════════════════════════════════════════════════════════
        // 2. MYFATOORAH - إنشاء Session
        // ════════════════════════════════════════════════════════
        case 'init_myfatoorah':
            $apiKey = getenv('MYFATOORAH_API_KEY') ?: '';
            $env = getenv('MYFATOORAH_ENVIRONMENT') ?: 'sandbox';
            
            if (empty($apiKey)) {
                throw new Exception('MYFATOORAH_API_KEY not configured');
            }
            
            $amount = (float)($payload['amount'] ?? 0);
            $currency = strtoupper($payload['currency'] ?? 'USD');
            $reference = $payload['reference'] ?? generateReference('MF');
            
            if ($amount <= 0) {
                throw new Exception('Invalid amount');
            }
            
            $url = $env === 'production' 
                ? 'https://api.myfatoorah.com/v2/InitiatePayment' 
                : 'https://apitest.myfatoorah.com/v2/InitiatePayment';
            
            $data = [
                'InvoiceAmount' => $amount,
                'CurrencyIso' => $currency,
                'CustomerName' => $payload['name'] ?? 'Customer',
                'CustomerEmail' => $payload['email'] ?? 'customer@example.com',
                'CustomerPhone' => $payload['phone'] ?? '971501234567',
                'InvoiceReference' => $reference,
                'CallBackUrl' => getenv('SITE_URL') . '/receipt.php?ref=' . $reference,
                'ErrorUrl' => getenv('SITE_URL') . '/checkout.php?error=payment_failed',
            ];
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $responseData = json_decode($response, true);
            
            if ($httpCode !== 200 || !isset($responseData['Data'])) {
                throw new Exception('MyFatoorah error: ' . ($responseData['Message'] ?? 'Unknown error'));
            }
            
            // تسجيل المعاملة
            $db = db();
            $db->insert('dp_transactions', [
                'reference' => $reference,
                'user_id' => $userId,
                'gateway' => 'myfatoorah',
                'gateway_type' => 'card',
                'amount' => $amount,
                'currency' => $currency,
                'status' => 'pending',
                'gateway_transaction_id' => $responseData['Data']['InvoiceId'] ?? null,
                'gateway_response' => json_encode($responseData),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            
            $result = [
                'success' => true,
                'payment_url' => $responseData['Data']['PaymentURL'] ?? '',
                'invoice_id' => $responseData['Data']['InvoiceId'] ?? null,
                'reference' => $reference,
            ];
            break;
        
        // ════════════════════════════════════════════════════════
        // 3. MYFATOORAH - تنفيذ الدفع
        // ════════════════════════════════════════════════════════
        case 'execute_myfatoorah':
            $apiKey = getenv('MYFATOORAH_API_KEY') ?: '';
            if (empty($apiKey)) {
                throw new Exception('MYFATOORAH_API_KEY not configured');
            }
            
            $sessionId = $payload['session_id'] ?? '';
            $amount = (float)($payload['amount'] ?? 0);
            $currency = strtoupper($payload['currency'] ?? 'USD');
            
            if (empty($sessionId)) {
                throw new Exception('Session ID required');
            }
            
            $url = 'https://api.myfatoorah.com/v2/ExecutePayment';
            
            $data = [
                'SessionId' => $sessionId,
                'PaymentMethodId' => $payload['payment_method_id'] ?? 1,
            ];
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $responseData = json_decode($response, true);
            
            if ($httpCode !== 200 || !isset($responseData['Data'])) {
                throw new Exception('MyFatoorah error: ' . ($responseData['Message'] ?? 'Unknown error'));
            }
            
            $result = [
                'success' => true,
                'data' => $responseData['Data'],
                'invoice_id' => $responseData['Data']['InvoiceId'] ?? null,
                'status' => $responseData['Data']['InvoiceStatus'] ?? 'unknown',
            ];
            break;
        
        // ════════════════════════════════════════════════════════
        // 4. CHECKOUT.COM - إنشاء Payment
        // ════════════════════════════════════════════════════════
        case 'init_checkout':
            $secretKey = getenv('CHECKOUT_SECRET_KEY') ?: '';
            if (empty($secretKey)) {
                throw new Exception('CHECKOUT_SECRET_KEY not configured');
            }
            
            $amount = (float)($payload['amount'] ?? 0);
            $currency = strtoupper($payload['currency'] ?? 'USD');
            $reference = $payload['reference'] ?? generateReference('CKO');
            
            if ($amount <= 0) {
                throw new Exception('Invalid amount');
            }
            
            $url = 'https://api.checkout.com/payments';
            
            $data = [
                'source' => [
                    'type' => 'card',
                    'token' => $payload['card_token'] ?? '',
                ],
                'amount' => (int)($amount * 100),
                'currency' => strtolower($currency),
                'reference' => $reference,
                'metadata' => [
                    'user_id' => $userId,
                ],
                'customer' => [
                    'name' => $payload['name'] ?? 'Customer',
                    'email' => $payload['email'] ?? 'customer@example.com',
                ],
                'processing' => [
                    'merchant_initiated' => false,
                ],
                'success_url' => getenv('SITE_URL') . '/receipt.php?ref=' . $reference,
                'failure_url' => getenv('SITE_URL') . '/checkout.php?error=payment_failed',
            ];
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: ' . $secretKey,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $responseData = json_decode($response, true);
            
            if ($httpCode !== 201 && $httpCode !== 200) {
                throw new Exception('Checkout.com error: ' . ($responseData['message'] ?? 'Unknown error'));
            }
            
            // تسجيل المعاملة
            $db = db();
            $db->insert('dp_transactions', [
                'reference' => $reference,
                'user_id' => $userId,
                'gateway' => 'checkout',
                'gateway_type' => 'card',
                'amount' => $amount,
                'currency' => $currency,
                'status' => $responseData['status'] ?? 'pending',
                'gateway_transaction_id' => $responseData['id'] ?? null,
                'gateway_response' => json_encode($responseData),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            
            $result = [
                'success' => true,
                'payment_id' => $responseData['id'] ?? null,
                'status' => $responseData['status'] ?? 'pending',
                'reference' => $reference,
            ];
            break;
        
        // ════════════════════════════════════════════════════════
        // 5. PAYPAL - إنشاء Order
        // ════════════════════════════════════════════════════════
        case 'init_paypal':
            $clientId = getenv('PAYPAL_CLIENT_ID') ?: '';
            $clientSecret = getenv('PAYPAL_CLIENT_SECRET') ?: '';
            $env = getenv('PAYPAL_ENVIRONMENT') ?: 'sandbox';
            
            if (empty($clientId) || empty($clientSecret)) {
                throw new Exception('PayPal credentials not configured');
            }
            
            $amount = (float)($payload['amount'] ?? 0);
            $currency = strtoupper($payload['currency'] ?? 'USD');
            $reference = $payload['reference'] ?? generateReference('PPL');
            
            if ($amount <= 0) {
                throw new Exception('Invalid amount');
            }
            
            $url = $env === 'production' 
                ? 'https://api-m.paypal.com/v2/checkout/orders' 
                : 'https://api-m.sandbox.paypal.com/v2/checkout/orders';
            
            // الحصول على Access Token
            $auth = base64_encode($clientId . ':' . $clientSecret);
            $ch = curl_init($env === 'production' 
                ? 'https://api-m.paypal.com/v1/oauth2/token' 
                : 'https://api-m.sandbox.paypal.com/v1/oauth2/token');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
                CURLOPT_HTTPHEADER => [
                    'Authorization: Basic ' . $auth,
                    'Content-Type: application/x-www-form-urlencoded',
                ],
                CURLOPT_RETURNTRANSFER => true,
            ]);
            $tokenResponse = curl_exec($ch);
            curl_close($ch);
            
            $tokenData = json_decode($tokenResponse, true);
            $accessToken = $tokenData['access_token'] ?? '';
            
            if (empty($accessToken)) {
                throw new Exception('Failed to get PayPal access token');
            }
            
            // إنشاء Order
            $data = [
                'intent' => 'CAPTURE',
                'purchase_units' => [
                    [
                        'reference_id' => $reference,
                        'amount' => [
                            'currency_code' => $currency,
                            'value' => number_format($amount, 2, '.', ''),
                        ],
                        'description' => 'DI PARMA Payment - ' . $reference,
                    ],
                ],
                'application_context' => [
                    'brand_name' => 'DI PARMA',
                    'return_url' => getenv('SITE_URL') . '/receipt.php?ref=' . $reference,
                    'cancel_url' => getenv('SITE_URL') . '/checkout.php?error=cancelled',
                    'user_action' => 'PAY_NOW',
                ],
            ];
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $accessToken,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $responseData = json_decode($response, true);
            
            if ($httpCode !== 201) {
                throw new Exception('PayPal error: ' . ($responseData['message'] ?? 'Unknown error'));
            }
            
            // استخراج رابط الموافقة
            $approvalUrl = null;
            foreach ($responseData['links'] as $link) {
                if ($link['rel'] === 'approve') {
                    $approvalUrl = $link['href'];
                    break;
                }
            }
            
            // تسجيل المعاملة
            $db = db();
            $db->insert('dp_transactions', [
                'reference' => $reference,
                'user_id' => $userId,
                'gateway' => 'paypal',
                'gateway_type' => 'wallet',
                'amount' => $amount,
                'currency' => $currency,
                'status' => 'pending',
                'gateway_transaction_id' => $responseData['id'] ?? null,
                'gateway_response' => json_encode($responseData),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            
            $result = [
                'success' => true,
                'order_id' => $responseData['id'] ?? null,
                'approval_url' => $approvalUrl,
                'reference' => $reference,
            ];
            break;
        
        // ════════════════════════════════════════════════════════
        // 6. WISE - إنشاء Quote (تحويل بنكي)
        // ════════════════════════════════════════════════════════
        case 'init_wise':
            $apiToken = getenv('WISE_API_TOKEN') ?: '';
            $profileId = getenv('WISE_PROFILE_ID') ?: '';
            
            if (empty($apiToken) || empty($profileId)) {
                throw new Exception('Wise credentials not configured');
            }
            
            $amount = (float)($payload['amount'] ?? 0);
            $currency = strtoupper($payload['currency'] ?? 'USD');
            $reference = $payload['reference'] ?? generateReference('WISE');
            
            if ($amount <= 0) {
                throw new Exception('Invalid amount');
            }
            
            // إنشاء Quote
            $url = 'https://api.wise.com/v1/quotes';
            $data = [
                'targetCurrency' => 'USD',
                'sourceCurrency' => $currency,
                'sourceAmount' => $amount,
                'customerTransactionId' => $reference,
                'profileId' => (int)$profileId,
            ];
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiToken,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $responseData = json_decode($response, true);
            
            if ($httpCode !== 200 || !isset($responseData['id'])) {
                throw new Exception('Wise error: ' . ($responseData['message'] ?? 'Unknown error'));
            }
            
            $result = [
                'success' => true,
                'quote_id' => $responseData['id'],
                'reference' => $reference,
                'amount' => $amount,
                'currency' => $currency,
                'target_amount' => $responseData['targetAmount'] ?? null,
            ];
            break;
        
        // ════════════════════════════════════════════════════════
        // 7. STRIPE - تأكيد Payment Intent
        // ════════════════════════════════════════════════════════
        case 'confirm_stripe':
            $intentId = $payload['payment_intent_id'] ?? $_GET['pi'] ?? '';
            
            if (empty($intentId)) {
                throw new Exception('payment_intent_id required');
            }
            
            $stripeSecret = getenv('STRIPE_SECRET_KEY') ?: '';
            if (empty($stripeSecret)) {
                throw new Exception('STRIPE_SECRET_KEY not configured');
            }
            
            \Stripe\Stripe::setApiKey($stripeSecret);
            $intent = \Stripe\PaymentIntent::retrieve($intentId);
            
            // تحديث حالة المعاملة
            $db = db();
            $db->update('dp_transactions', [
                'status' => $intent->status,
                'gateway_response' => json_encode($intent->toArray()),
            ], ['gateway_transaction_id' => $intentId]);
            
            $result = [
                'success' => true,
                'payment_intent_id' => $intent->id,
                'status' => $intent->status,
                'amount' => $intent->amount / 100,
                'currency' => strtoupper($intent->currency),
            ];
            break;
        
        // ════════════════════════════════════════════════════════
        // 8. PUBLIC KEYS - جلب المفاتيح العامة للـ JS SDKs
        // ════════════════════════════════════════════════════════
        case 'public_keys':
            $result = [
                'success' => true,
                'stripe_public_key' => getenv('STRIPE_PUBLIC_KEY') ?: '',
                'checkout_public_key' => getenv('CHECKOUT_PUBLIC_KEY') ?: '',
                'myfatoorah_env' => getenv('MYFATOORAH_ENVIRONMENT') ?: 'sandbox',
                'paypal_client_id' => getenv('PAYPAL_CLIENT_ID') ?: '',
                'timestamp' => date('c'),
            ];
            break;
        
        // ════════════════════════════════════════════════════════
        // 9. GATEWAYS - جلب قائمة البوابات المتاحة
        // ════════════════════════════════════════════════════════
        case 'gateways':
            $gateways = [];
            
            // التحقق من البوابات المتاحة
            if (!empty(getenv('STRIPE_SECRET_KEY'))) {
                $gateways[] = ['code' => 'stripe', 'name' => 'Stripe', 'type' => 'card', 'enabled' => true];
            }
            if (!empty(getenv('STRIPE_PUBLIC_KEY'))) {
                $gateways[] = ['code' => 'stripe_public', 'name' => 'Stripe (Public)', 'type' => 'card', 'enabled' => true];
            }
            if (!empty(getenv('MYFATOORAH_API_KEY'))) {
                $gateways[] = ['code' => 'myfatoorah', 'name' => 'MyFatoorah', 'type' => 'card', 'enabled' => true];
            }
            if (!empty(getenv('CHECKOUT_SECRET_KEY'))) {
                $gateways[] = ['code' => 'checkout', 'name' => 'Checkout.com', 'type' => 'card', 'enabled' => true];
            }
            if (!empty(getenv('PAYPAL_CLIENT_ID'))) {
                $gateways[] = ['code' => 'paypal', 'name' => 'PayPal', 'type' => 'wallet', 'enabled' => true];
            }
            if (!empty(getenv('WISE_API_TOKEN'))) {
                $gateways[] = ['code' => 'wise', 'name' => 'Wise', 'type' => 'bank', 'enabled' => true];
            }
            
            $result = [
                'success' => true,
                'count' => count($gateways),
                'gateways' => $gateways,
                'timestamp' => date('c'),
            ];
            break;
        
        // ════════════════════════════════════════════════════════
        // 10. ACTION غير معروف
        // ════════════════════════════════════════════════════════
        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Unknown action: ' . $action,
                'available_actions' => [
                    'init_stripe',
                    'init_myfatoorah',
                    'execute_myfatoorah',
                    'init_checkout',
                    'init_paypal',
                    'init_wise',
                    'confirm_stripe',
                    'public_keys',
                    'gateways',
                ]
            ]);
            exit;
    }
    
    // ============================================================
    // 8. إرجاع النتيجة
    // ============================================================
    
    http_response_code(200);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    // ============================================================
    // 9. معالجة الأخطاء
    // ============================================================
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => defined('APP_IS_LOCAL') && APP_IS_LOCAL ? $e->getMessage() : 'Internal server error',
        'code' => $e->getCode(),
        'timestamp' => date('c'),
    ], JSON_UNESCAPED_UNICODE);
    
    // تسجيل الخطأ
    error_log('[DirectPaymentAPI] Error: ' . $e->getMessage());
}

// ============================================================
// نهاية الملف
// ============================================================
?>