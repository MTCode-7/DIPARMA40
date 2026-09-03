<?php
/**
 * ============================================================
 * DI PARMA | Direct Payment API
 * ============================================================
 * 
 * ظٹط¯ط¹ظ… ط¨ظˆط§ط¨ط§طھ ط§ظ„ط¯ظپط¹:
 *   - Stripe (ط¨ط·ط§ظ‚ط§طھ ط§ط¦طھظ…ط§ظ†)
 *   - MyFatoorah (ط¨ظˆط§ط¨ط© ط§ظ„ط´ط±ظ‚ ط§ظ„ط£ظˆط³ط·)
 *   - Checkout.com (ط¨ط·ط§ظ‚ط§طھ ط§ط¦طھظ…ط§ظ†)
 *   - PayPal (ظ…ط­ظپط¸ط© ط¥ظ„ظƒطھط±ظˆظ†ظٹط©)
 *   - Wise (طھط­ظˆظٹظ„ط§طھ ط¨ظ†ظƒظٹط©)
 * 
 * ============================================================
 * ط§ظ„ظ…طµط§ط¯ظ‚ط©:
 *   - Session-based (ظ„ظ„ظ…ط³طھط®ط¯ظ…ظٹظ† ط§ظ„ظ…ط³ط¬ظ„ظٹظ†)
 *   - API Key-based (ظ„ظ„طھط·ط¨ظٹظ‚ط§طھ ط§ظ„ط®ط§ط±ط¬ظٹط©)
 * 
 * ============================================================
 * ظ†ظ‚ط§ط· ط§ظ„ظ†ظ‡ط§ظٹط© (Endpoints):
 *   POST ?action=init_stripe          - ط¥ظ†ط´ط§ط، Stripe Payment Intent
 *   POST ?action=init_myfatoorah      - ط¥ظ†ط´ط§ط، MyFatoorah Session
 *   POST ?action=execute_myfatoorah   - طھظ†ظپظٹط° MyFatoorah Payment
 *   POST ?action=init_checkout        - ط¥ظ†ط´ط§ط، Checkout.com Payment
 *   POST ?action=init_paypal          - ط¥ظ†ط´ط§ط، PayPal Order
 *   POST ?action=init_wise            - ط¥ظ†ط´ط§ط، Wise Quote
 *   GET  ?action=confirm_stripe       - ط§ظ„طھط­ظ‚ظ‚ ظ…ظ† Stripe Payment
 *   GET  ?action=public_keys          - ط¬ظ„ط¨ ط§ظ„ظ…ظپط§طھظٹط­ ط§ظ„ط¹ط§ظ…ط©
 *   GET  ?action=gateways             - ط¬ظ„ط¨ ظ‚ط§ط¦ظ…ط© ط§ظ„ط¨ظˆط§ط¨ط§طھ ط§ظ„ظ…طھط§ط­ط©
 * ============================================================
 */

// ============================================================
// 1. ط¥ط¹ط¯ط§ط¯ط§طھ ط§ظ„ط±ط£ط³ ظˆط§ظ„ط£ظ…ط§ظ†
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Key, X-Timestamp, X-Signature');

// ظ…ط¹ط§ظ„ط¬ط© ط·ظ„ط¨ط§طھ OPTIONS (CORS Preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ============================================================
// 2. ط§ط³طھظٹط±ط§ط¯ ط§ظ„ظ…ظ„ظپط§طھ ط§ظ„ظ…ط·ظ„ظˆط¨ط©
// ============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

// ط¨ط¯ط، ط§ظ„ط¬ظ„ط³ط© (ط¥ط°ط§ ظ„ظ… طھظƒظ† ظ…ط¨ط¯ظˆط،ط©)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// 3. ط§ط³طھظٹط±ط§ط¯ ظ…ظƒطھط¨ط§طھ ط§ظ„ط¨ظˆط§ط¨ط§طھ (ط¥ظ† ظˆط¬ط¯طھ)
// ============================================================

// ظ…ط­ط§ظˆظ„ط© طھط­ظ…ظٹظ„ Stripe SDK
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

// ============================================================
// 4. ط§ظ„طھط­ظ‚ظ‚ ظ…ظ† ط§ظ„ظ…طµط§ط¯ظ‚ط© (Session ط£ظˆ API Key)
// ============================================================

$userId = null;
$authMethod = 'none';

// 4.1 ط§ظ„طھط­ظ‚ظ‚ ظ…ظ† Session
if (!empty($_SESSION['user_id'])) {
    $userId = (int)$_SESSION['user_id'];
    $authMethod = 'session';
}

// 4.2 ط§ظ„طھط­ظ‚ظ‚ ظ…ظ† API Key (ط¥ط°ط§ ظ„ظ… ظٹظƒظ† ظ‡ظ†ط§ظƒ Session)
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
            // طھط¬ط§ظ‡ظ„ ط£ط®ط·ط§ط، ظ‚ط§ط¹ط¯ط© ط§ظ„ط¨ظٹط§ظ†ط§طھ
        }
    }
}

// 4.3 ط¥ط°ط§ ظ„ظ… ظٹطھظ… ط§ظ„ظ…طµط§ط¯ظ‚ط©
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
// 5. ط§ظ„طھط­ظ‚ظ‚ ظ…ظ† CSRF Token (ظ„ط·ظ„ط¨ط§طھ POST ظ…ظ† ط§ظ„ظ…طھطµظپط­)
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
// 6. طھط­ط¯ظٹط¯ ط§ظ„ط¥ط¬ط±ط§ط، ط§ظ„ظ…ط·ظ„ظˆط¨
// ============================================================

$action = strtolower(trim($_GET['action'] ?? $_POST['action'] ?? ''));
$payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;

// ط­ط°ظپ csrf_token ظ…ظ† ط§ظ„ظ…طµظپظˆظپط© ظ„طھط¬ظ†ط¨ طھط³ط¬ظٹظ„ظ‡
unset($payload['csrf_token']);

// ============================================================
// 7. ظ…ط¹ط§ظ„ط¬ط© ط§ظ„ط¥ط¬ط±ط§ط،ط§طھ
// ============================================================

try {
    $result = [];
    
    switch ($action) {
        
        // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
        // 1. STRIPE - ط¥ظ†ط´ط§ط، Payment Intent
        // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
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
            
            // ط¥ط¹ط¯ط§ط¯ Stripe
            $stripeSecret = getenv('STRIPE_SECRET_KEY') ?: '';
            if (empty($stripeSecret)) {
                throw new Exception('STRIPE_SECRET_KEY not configured');
            }
            
            \Stripe\Stripe::setApiKey($stripeSecret);
            
            // ط¥ظ†ط´ط§ط، Payment Intent
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
            
            // طھط³ط¬ظٹظ„ ط§ظ„ظ…ط¹ط§ظ…ظ„ط©
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
        
        // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
        // 2. MYFATOORAH - ط¥ظ†ط´ط§ط، Session
        // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
        case 'init_myfatoorah':
            $apiKey = getenv('MYFATOORAH_API_KEY') ?: '';
            $env = getenv('MYFATOORAH_ENVIRONMENT') ?: '';
            
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
                'CustomerEmail' => $payload['email'] ?? '',
                'CustomerPhone' => $payload['phone'] ?? '',
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
            
            // طھط³ط¬ظٹظ„ ط§ظ„ظ…ط¹ط§ظ…ظ„ط©
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
        
        // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
        // 3. MYFATOORAH - طھظ†ظپظٹط° ط§ظ„ط¯ظپط¹
        // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
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
        
        // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
        // 4. CHECKOUT.COM - ط¥ظ†ط´ط§ط، Payment
        // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
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
                    'email' => $payload['email'] ?? '',
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
            
            // طھط³ط¬ظٹظ„ ط§ظ„ظ…ط¹ط§ظ…ظ„ط©
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
        
        // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
        // 5. PAYPAL - ط¥ظ†ط´ط§ط، Order
        // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
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
            
            $url = in_array(strtolower($env), ['live', 'production'], true)
                ? 'https://api-m.paypal.com/v2/checkout/orders' 
                : 'https://api-m.sandbox.paypal.com/v2/checkout/orders';
            
            // ط§ظ„ط­طµظˆظ„ ط¹ظ„ظ‰ Access Token
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
            
            // ط¥ظ†ط´ط§ط، Order
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
            
            // ط§ط³طھط®ط±ط§ط¬ ط±ط§ط¨ط· ط§ظ„ظ…ظˆط§ظپظ‚ط©
            $approvalUrl = null;
            foreach ($responseData['links'] as $link) {
                if ($link['rel'] === 'approve') {
                    $approvalUrl = $link['href'];
                    break;
                }
            }
            
            // طھط³ط¬ظٹظ„ ط§ظ„ظ…ط¹ط§ظ…ظ„ط©
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
        
        // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
        // 6. WISE - ط¥ظ†ط´ط§ط، Quote (طھط­ظˆظٹظ„ ط¨ظ†ظƒظٹ)
        // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
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
            
            // ط¥ظ†ط´ط§ط، Quote
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
        
        // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
        // 7. STRIPE - طھط£ظƒظٹط¯ Payment Intent
        // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
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
            
            // طھط­ط¯ظٹط« ط­ط§ظ„ط© ط§ظ„ظ…ط¹ط§ظ…ظ„ط©
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
        
        // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
        // 8. PUBLIC KEYS - ط¬ظ„ط¨ ط§ظ„ظ…ظپط§طھظٹط­ ط§ظ„ط¹ط§ظ…ط© ظ„ظ„ظ€ JS SDKs
        // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
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
        
        // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
        // 9. GATEWAYS - ط¬ظ„ط¨ ظ‚ط§ط¦ظ…ط© ط§ظ„ط¨ظˆط§ط¨ط§طھ ط§ظ„ظ…طھط§ط­ط©
        // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
        case 'gateways':
            $gateways = [];
            
            // ط§ظ„طھط­ظ‚ظ‚ ظ…ظ† ط§ظ„ط¨ظˆط§ط¨ط§طھ ط§ظ„ظ…طھط§ط­ط©
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
        
        // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
        // 10. ACTION ط؛ظٹط± ظ…ط¹ط±ظˆظپ
        // â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
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
    // 8. ط¥ط±ط¬ط§ط¹ ط§ظ„ظ†طھظٹط¬ط©
    // ============================================================
    
    http_response_code(200);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    // ============================================================
    // 9. ظ…ط¹ط§ظ„ط¬ط© ط§ظ„ط£ط®ط·ط§ط،
    // ============================================================
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => defined('APP_IS_LOCAL') && APP_IS_LOCAL ? $e->getMessage() : 'Internal server error',
        'code' => $e->getCode(),
        'timestamp' => date('c'),
    ], JSON_UNESCAPED_UNICODE);
    
    // طھط³ط¬ظٹظ„ ط§ظ„ط®ط·ط£
    error_log('[DirectPaymentAPI] Error: ' . $e->getMessage());
}

// ============================================================
// ظ†ظ‡ط§ظٹط© ط§ظ„ظ…ظ„ظپ
// ============================================================
?>
