<?php
/**
 * ============================================================
 * DI PARMA | معالجة المدفوعات الفعلية (Live Payment Processing)
 * ============================================================
 * 
 * 
 * ============================================================
 */

// ============================================================
// 1. إعدادات الرأس والأمان
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Key, X-Timestamp, X-Signature');

// معالجة طلبات OPTIONS (CORS Preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// قبول طلبات POST فقط
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Use POST.'
    ]);
    exit;
}

// ============================================================
// 2. استيراد الملفات المطلوبة
// ============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

// تحميل مكتبات البوابات
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

// ============================================================
// 3. إعدادات السجلات
// ============================================================

$logFile = __DIR__ . '/../logs/gateway_processor.log';
if (!is_dir(dirname($logFile))) {
    mkdir(dirname($logFile), 0755, true);
}

function secureLog($message, $level = 'INFO', $data = null) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    
    $logEntry = "[$timestamp] [$level] [$ip] $message";
    
    if ($data !== null) {
        $safeData = $data;
        if (is_array($safeData)) {
            unset($safeData['card_number']);
            unset($safeData['card_cvv']);
            unset($safeData['cvv']);
            unset($safeData['card_expiry']);
            unset($safeData['password']);
            unset($safeData['api_key']);
            unset($safeData['api_secret']);
        }
        $logEntry .= " | Data: " . json_encode($safeData, JSON_UNESCAPED_UNICODE);
    }
    
    $logEntry .= "\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

secureLog('=== New payment request ===', 'INFO');

// ============================================================
// 4. قراءة بيانات الطلب
// ============================================================

$rawPayload = file_get_contents('php://input');
$data = json_decode($rawPayload, true);

if (!$data && !empty($_POST)) {
    $data = $_POST;
}

if (!$data) {
    secureLog('Empty or invalid payload', 'ERROR');
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid or missing payload'
    ]);
    exit;
}

// ============================================================
// 5. استخراج وتنظيف البيانات
// ============================================================

$reference = trim($data['reference'] ?? '');
$gateway = strtolower(trim($data['gateway'] ?? ''));
$amount = floatval($data['amount'] ?? 0);
$currency = strtoupper(trim($data['currency'] ?? 'USD'));
$cardNumber = preg_replace('/\D/', '', $data['card_number'] ?? '');
$cardExpiry = trim($data['card_expiry'] ?? '');
$cardCvv = trim($data['card_cvv'] ?? '');
$cardName = trim($data['card_name'] ?? '');
$email = trim($data['email'] ?? '');
$phone = trim($data['phone'] ?? '');
$description = trim($data['description'] ?? 'DI PARMA Payment');
$ledgerAddress = trim($data['ledger_address'] ?? '');
$cryptoCurrency = strtoupper(trim($data['crypto_currency'] ?? 'USDT'));
$payramNetwork = trim($data['payram_network'] ?? 'polygon-erc20'); // polygon-erc20, trc20, etc.

// توليد مرجع إذا لم يتم إرساله
if (empty($reference)) {
    $reference = 'DP_' . strtoupper(bin2hex(random_bytes(6)));
}

// تحديد نوع العملية (MOTO أو عادية)
$isMoto = isset($data['is_moto']) ? (bool)$data['is_moto'] : false;
$motoIndicator = $isMoto ? 'M' : 'E';

// MOTO = 2D (بدون 3D Secure)
$securityMode = '2D';
$use3DSecure = false;

secureLog('Processing reference: ' . $reference . ', Gateway: ' . $gateway, 'INFO');

// ============================================================
// 6. التحقق من صحة البطاقة (Luhn, Expiry, CVV)
// ============================================================

function validateCard($cardNumber, $cardExpiry, $cardCvv) {
    $errors = [];
    
    // 6.1 التحقق من رقم البطاقة (Luhn)
    if (empty($cardNumber) || strlen($cardNumber) < 13 || strlen($cardNumber) > 19) {
        $errors[] = 'Invalid card number length (must be 13-19 digits)';
    } else {
        if (!isValidLuhn($cardNumber)) {
            $errors[] = 'Invalid card number (checksum failed)';
        }
    }
    
    // 6.2 التحقق من تاريخ الانتهاء
    if (empty($cardExpiry) || !preg_match('/^(0[1-9]|1[0-2])\/([0-9]{2})$/', $cardExpiry)) {
        $errors[] = 'Invalid expiry date (format: MM/YY)';
    } else {
        list($month, $year) = explode('/', $cardExpiry);
        $expiryTimestamp = mktime(0, 0, 0, intval($month), 1, intval($year) + 2000);
        if ($expiryTimestamp < time()) {
            $errors[] = 'Card has expired';
        }
    }
    
    // 6.3 التحقق من CVV
    $cardType = detectCardType($cardNumber);
    $cvvLength = $cardType === 'Amex' ? 4 : 3;
    if (empty($cardCvv) || strlen($cardCvv) !== $cvvLength || !ctype_digit($cardCvv)) {
        $errors[] = 'Invalid CVV (must be ' . $cvvLength . ' digits)';
    }
    
    return [
        'success' => empty($errors),
        'errors' => $errors,
        'card_type' => $cardType,
        'last4' => substr($cardNumber, -4)
    ];
}

// 6.4 دوال التحقق المساعدة
function isValidLuhn($number) {
    $number = preg_replace('/\D/', '', $number);
    $sum = 0;
    $alt = false;
    for ($i = strlen($number) - 1; $i >= 0; $i--) {
        $n = (int)$number[$i];
        if ($alt) {
            $n *= 2;
            if ($n > 9) $n -= 9;
        }
        $sum += $n;
        $alt = !$alt;
    }
    return $sum % 10 === 0;
}

function detectCardType($number) {
    $number = preg_replace('/\D/', '', $number);
    if (preg_match('/^4/', $number)) return 'Visa';
    if (preg_match('/^5[1-5]/', $number)) return 'Mastercard';
    if (preg_match('/^3[47]/', $number)) return 'Amex';
    if (preg_match('/^6(?:011|5)/', $number)) return 'Discover';
    if (preg_match('/^3(?:0[0-5]|[68])/', $number)) return 'Diners';
    if (preg_match('/^(?:2131|1800|35)/', $number)) return 'JCB';
    if (preg_match('/^62/', $number)) return 'UnionPay';
    return 'Unknown';
}

// ============================================================
// 7. التحقق من صحة البيانات العامة
// ============================================================

$errors = [];

// التحقق من المبلغ
if ($amount <= 0) {
    $errors[] = 'Amount must be greater than 0';
}

// التحقق من العملة
$allowedCurrencies = ['USD', 'EUR', 'GBP', 'AED', 'SAR', 'EGP', 'KWD', 'QAR'];
if (!in_array($currency, $allowedCurrencies)) {
    $errors[] = 'Unsupported currency: ' . $currency;
}

// التحقق من البوابة
$allowedGateways = ['payram', 'wise', 'stripe', 'paypal', 'myfatoorah'];
if (!in_array($gateway, $allowedGateways)) {
    $errors[] = 'Unsupported gateway: ' . $gateway;
}

// التحقق من البريد الإلكتروني
if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Invalid email address';
}

// التحقق من البطاقة
$cardValidation = validateCard($cardNumber, $cardExpiry, $cardCvv);
if (!$cardValidation['success']) {
    $errors = array_merge($errors, $cardValidation['errors']);
}

// إذا كان هناك أخطاء، أعدها للمستخدم
if (!empty($errors)) {
    secureLog('Validation errors: ' . implode(', ', $errors), 'ERROR');
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Validation failed',
        'errors' => $errors
    ]);
    exit;
}

// ============================================================
// 8. الاتصال بقاعدة البيانات
// ============================================================

$db = db();

// ============================================================
// 9. تسجيل المعاملة في قاعدة البيانات
// ============================================================

try {
    $db->insert('dp_transactions', [
        'reference' => $reference,
        'user_id' => $_SESSION['user_id'] ?? null,
        'gateway' => $gateway,
        'gateway_type' => $gateway === 'payram' ? 'crypto' : ($gateway === 'wise' ? 'bank' : 'card'),
        'transaction_type' => 'purchase_2d',
        'transaction_label' => $isMoto ? 'MOTO Purchase' : 'Purchase',
        'amount' => $amount,
        'currency' => $currency,
        'card_last4' => $cardValidation['last4'],
        'card_brand' => $cardValidation['card_type'],
        'cardholder_name' => $cardName,
        'security_mode' => $securityMode,
        'moto_indicator' => $motoIndicator,
        'is_moto' => $isMoto ? 1 : 0,
        'status' => 'pending',
        'customer_email' => $email,
        'customer_phone' => $phone,
        'description' => $description,
        'ledger_address' => $ledgerAddress,
        'crypto_currency' => $cryptoCurrency,
        'created_at' => date('Y-m-d H:i:s')
    ]);
    
    $transactionId = $db->getPDO()->lastInsertId();
    secureLog('Transaction created: ' . $reference . ' (ID: ' . $transactionId . ')', 'INFO');
    
} catch (Exception $e) {
    secureLog('Database insert error: ' . $e->getMessage(), 'ERROR');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
    exit;
}

// ============================================================
// 10. معالجة الدفع حسب البوابة (فعلي)
// ============================================================

$response = [];
$status = 'failed';
$message = '';
$gatewayTransactionId = '';
$authCode = '';
$rrn = '';
$gatewayResponse = [];

try {
    
    // ════════════════════════════════════════════════════════
    // 10.1 PAYRAM - العملات الرقمية الذاتية
    // ════════════════════════════════════════════════════════
    if ($gateway === 'payram') {
        secureLog('Processing PayRam payment: ' . $reference, 'INFO');
        
        // إعدادات PayRam
        $payramConfig = [
            'api_key' => getenv('PAYRAM_API_KEY') ?: '',
            'api_secret' => getenv('PAYRAM_API_SECRET') ?: '',
            'environment' => getenv('PAYRAM_ENVIRONMENT') ?: 'production',
            'network' => $payramNetwork,
            'callback_url' => getenv('SITE_URL') . '/api/payram_callback.php',
        ];
        
        if (empty($payramConfig['api_key']) || empty($payramConfig['api_secret'])) {
            throw new Exception('PayRam API credentials not configured');
        }
        
        // بناء الطلب لـ PayRam
        $payramPayload = [
            'amount' => $amount,
            'currency' => $currency,
            'crypto_currency' => $cryptoCurrency,
            'network' => $payramNetwork,
            'reference' => $reference,
            'callback_url' => $payramConfig['callback_url'],
            'metadata' => [
                'customer_email' => $email,
                'customer_name' => $cardName,
                'transaction_type' => $isMoto ? 'moto' : 'standard',
                'moto_indicator' => $motoIndicator,
            ],
            'card_details' => [
                'number' => $cardNumber,
                'expiry' => $cardExpiry,
                'cvv' => $cardCvv,
                'holder' => $cardName,
                'type' => $cardValidation['card_type'],
                'last4' => $cardValidation['last4'],
            ],
            'security' => [
                'mode' => '2D',
                'is_moto' => $isMoto,
            ],
            'ledger' => [
                'address' => $ledgerAddress,
                'auto_transfer' => true,
            ],
        ];
        
        // إرسال الطلب إلى PayRam API
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $reference . '.' . $amount, $payramConfig['api_secret']);
        
        $ch = curl_init($payramConfig['environment'] === 'production' 
            ? 'https://api.payram.io/v1/payment/create' 
            : 'https://sandbox.payram.io/v1/payment/create'
        );
        
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payramPayload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Api-Key: ' . $payramConfig['api_key'],
                'X-Timestamp: ' . $timestamp,
                'X-Signature: ' . $signature,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => $payramConfig['environment'] === 'production',
        ]);
        
        $payramResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $payramData = json_decode($payramResponse, true);
            
            if (isset($payramData['success']) && $payramData['success'] === true) {
                $status = 'completed';
                $message = 'PayRam payment processed successfully';
                $gatewayTransactionId = $payramData['transaction_id'] ?? '';
                $authCode = $payramData['auth_code'] ?? '';
                $rrn = $payramData['rrn'] ?? '';
                $gatewayResponse = $payramData;
                
                secureLog('PayRam payment successful: ' . $reference, 'INFO');
            } else {
                throw new Exception('PayRam error: ' . ($payramData['message'] ?? 'Unknown error'));
            }
        } else {
            throw new Exception('PayRam API error: HTTP ' . $httpCode . ' - ' . $payramResponse);
        }
    }
    
    // ════════════════════════════════════════════════════════
    // 10.2 WISE - التحويلات البنكية الدولية
    // ════════════════════════════════════════════════════════
    elseif ($gateway === 'wise') {
        secureLog('Processing Wise payment: ' . $reference, 'INFO');
        
        $wiseToken = getenv('WISE_API_TOKEN') ?: '';
        $profileId = getenv('WISE_PROFILE_ID') ?: '';
        
        if (empty($wiseToken) || empty($profileId)) {
            throw new Exception('Wise API credentials not configured');
        }
        
        // إنشاء Quote في Wise
        $wisePayload = [
            'targetCurrency' => 'USD',
            'sourceCurrency' => $currency,
            'sourceAmount' => $amount,
            'customerTransactionId' => $reference,
            'profileId' => (int)$profileId,
        ];
        
        $ch = curl_init('https://api.wise.com/v1/quotes');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($wisePayload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $wiseToken,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        
        $wiseResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $wiseData = json_decode($wiseResponse, true);
            
            if (isset($wiseData['id'])) {
                // إنشاء Transfer في Wise
                $transferPayload = [
                    'quoteId' => $wiseData['id'],
                    'sourceCurrency' => $currency,
                    'targetCurrency' => 'USD',
                    'sourceAmount' => $amount,
                    'customerTransactionId' => $reference,
                    'reference' => 'DI PARMA #' . $reference,
                    'paymentMethod' => 'BALANCE',
                ];
                
                $ch = curl_init('https://api.wise.com/v1/transfers');
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($transferPayload),
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $wiseToken,
                        'Content-Type: application/json',
                        'Accept: application/json',
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);
                
                $transferResponse = curl_exec($ch);
                $transferHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($transferHttpCode === 200) {
                    $transferData = json_decode($transferResponse, true);
                    
                    $status = 'completed';
                    $message = 'Wise transfer completed successfully';
                    $gatewayTransactionId = $transferData['id'] ?? '';
                    $authCode = $wiseData['id'] ?? '';
                    $rrn = $transferData['id'] ?? '';
                    $gatewayResponse = [
                        'quote' => $wiseData,
                        'transfer' => $transferData,
                    ];
                    
                    secureLog('Wise transfer successful: ' . $reference, 'INFO');
                } else {
                    throw new Exception('Wise transfer failed: ' . $transferResponse);
                }
            } else {
                throw new Exception('Wise quote creation failed');
            }
        } else {
            throw new Exception('Wise API error: HTTP ' . $httpCode . ' - ' . $wiseResponse);
        }
    }
    
    // ════════════════════════════════════════════════════════
    // 10.3 STRIPE - البطاقات الائتمانية (مع MOTO = 2D)
    // ════════════════════════════════════════════════════════
    elseif ($gateway === 'stripe') {
        secureLog('Processing Stripe payment: ' . $reference, 'INFO');
        
        if (!class_exists('Stripe\Stripe')) {
            throw new Exception('Stripe library not installed');
        }
        
        $stripeSecret = getenv('STRIPE_SECRET_KEY') ?: '';
        if (empty($stripeSecret)) {
            throw new Exception('STRIPE_SECRET_KEY not configured');
        }
        
        \Stripe\Stripe::setApiKey($stripeSecret);
        
        // إنشاء PaymentMethod
        $paymentMethod = \Stripe\PaymentMethod::create([
            'type' => 'card',
            'card' => [
                'number' => $cardNumber,
                'exp_month' => substr($cardExpiry, 0, 2),
                'exp_year' => '20' . substr($cardExpiry, 3, 2),
                'cvc' => $cardCvv,
            ],
            'billing_details' => [
                'name' => $cardName,
                'email' => $email,
                'phone' => $phone,
            ],
        ]);
        
        // MOTO = 2D: لا نطلب 3D Secure
        $intentData = [
            'amount' => (int)($amount * 100),
            'currency' => strtolower($currency),
            'payment_method' => $paymentMethod->id,
            'payment_method_types' => ['card'],
            'confirmation_method' => 'automatic',
            'confirm' => true,
            'metadata' => [
                'reference' => $reference,
                'gateway' => 'stripe',
                'moto_indicator' => $motoIndicator,
                'is_moto' => $isMoto ? 'true' : 'false',
                'security_mode' => '2D',
            ],
            'statement_descriptor' => 'DI PARMA PAYMENT',
            'receipt_email' => $email,
            // MOTO = 2D: نمنع 3D Secure
            'payment_method_options' => [
                'card' => [
                    'request_three_d_secure' => 'automatic', // Stripe يعرف أنها MOTO
                ],
            ],
        ];
        
        // إضافة return_url
        $intentData['return_url'] = getenv('SITE_URL') . '/receipt.php?ref=' . $reference;
        
        $paymentIntent = \Stripe\PaymentIntent::create($intentData);
        
        // التحقق من الحالة
        if ($paymentIntent->status === 'succeeded' || $paymentIntent->status === 'requires_capture') {
            $status = 'completed';
            $message = 'Stripe payment processed successfully';
            $gatewayTransactionId = $paymentIntent->id;
            $authCode = $paymentIntent->id;
            $gatewayResponse = $paymentIntent->toArray();
            
            secureLog('Stripe payment successful: ' . $reference, 'INFO');
        } else {
            throw new Exception('Stripe payment failed: ' . $paymentIntent->status);
        }
    }
    
    // ════════════════════════════════════════════════════════
    // 10.4 PAYPAL - المحافظ الإلكترونية
    // ════════════════════════════════════════════════════════
    elseif ($gateway === 'paypal') {
        secureLog('Processing PayPal payment: ' . $reference, 'INFO');
        
        $clientId = getenv('PAYPAL_CLIENT_ID') ?: '';
        $clientSecret = getenv('PAYPAL_CLIENT_SECRET') ?: '';
        $env = getenv('PAYPAL_ENVIRONMENT') ?: 'sandbox';
        
        if (empty($clientId) || empty($clientSecret)) {
            throw new Exception('PayPal credentials not configured');
        }
        
        // الحصول على Access Token
        $auth = base64_encode($clientId . ':' . $clientSecret);
        $url = $env === 'production' 
            ? 'https://api-m.paypal.com/v1/oauth2/token'
            : 'https://api-m.sandbox.paypal.com/v1/oauth2/token';
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . $auth,
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        
        $tokenResponse = curl_exec($ch);
        curl_close($ch);
        
        $tokenData = json_decode($tokenResponse, true);
        $accessToken = $tokenData['access_token'] ?? '';
        
        if (empty($accessToken)) {
            throw new Exception('Failed to get PayPal access token');
        }
        
        // إنشاء Order
        $paypalUrl = $env === 'production'
            ? 'https://api-m.paypal.com/v2/checkout/orders'
            : 'https://api-m.sandbox.paypal.com/v2/checkout/orders';
        
        $orderData = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $reference,
                'amount' => [
                    'currency_code' => $currency,
                    'value' => number_format($amount, 2, '.', ''),
                ],
                'description' => $description . ' - ' . $reference,
            ]],
            'application_context' => [
                'brand_name' => 'DI PARMA',
                'return_url' => getenv('SITE_URL') . '/receipt.php?ref=' . $reference,
                'cancel_url' => getenv('SITE_URL') . '/checkout.php?error=cancelled',
                'user_action' => 'PAY_NOW',
            ],
        ];
        
        $ch = curl_init($paypalUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($orderData),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        
        $orderResponse = curl_exec($ch);
        curl_close($ch);
        
        $orderData = json_decode($orderResponse, true);
        
        if (isset($orderData['id'])) {
            $status = 'processing';
            $message = 'PayPal order created successfully';
            $gatewayTransactionId = $orderData['id'];
            $authCode = $orderData['id'];
            $gatewayResponse = $orderData;
            
            secureLog('PayPal order created: ' . $reference, 'INFO');
        } else {
            throw new Exception('PayPal order creation failed');
        }
    }
    
    // ════════════════════════════════════════════════════════
    // 10.5 MYFATOORAH - بوابة الشرق الأوسط
    // ════════════════════════════════════════════════════════
    elseif ($gateway === 'myfatoorah') {
        secureLog('Processing MyFatoorah payment: ' . $reference, 'INFO');
        
        $apiKey = getenv('MYFATOORAH_API_KEY') ?: '';
        $env = getenv('MYFATOORAH_ENVIRONMENT') ?: 'sandbox';
        
        if (empty($apiKey)) {
            throw new Exception('MyFatoorah API key not configured');
        }
        
        $url = $env === 'production'
            ? 'https://api.myfatoorah.com/v2/InitiatePayment'
            : 'https://apitest.myfatoorah.com/v2/InitiatePayment';
        
        $myfatoorahData = [
            'InvoiceAmount' => $amount,
            'CurrencyIso' => $currency,
            'CustomerName' => $cardName,
            'CustomerEmail' => $email,
            'CustomerPhone' => $phone,
            'InvoiceReference' => $reference,
            'CallBackUrl' => getenv('SITE_URL') . '/receipt.php?ref=' . $reference,
            'ErrorUrl' => getenv('SITE_URL') . '/checkout.php?error=payment_failed',
            'PaymentMethod' => 'Card',
            // MyFatoorah يدعم MOTO
            'MotoIndicator' => $isMoto ? 'M' : null,
        ];
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($myfatoorahData),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        
        $myfatoorahResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $myfatoorahData = json_decode($myfatoorahResponse, true);
            
            if (isset($myfatoorahData['Data']['PaymentURL'])) {
                $status = 'pending';
                $message = 'MyFatoorah payment created successfully';
                $gatewayTransactionId = $myfatoorahData['Data']['InvoiceId'] ?? '';
                $gatewayResponse = $myfatoorahData;
                
                secureLog('MyFatoorah payment created: ' . $reference, 'INFO');
            } else {
                throw new Exception('MyFatoorah payment creation failed');
            }
        } else {
            throw new Exception('MyFatoorah API error: HTTP ' . $httpCode);
        }
    }
    
} catch (Exception $e) {
    // ============================================================
    // 11. معالجة الأخطاء
    // ============================================================
    
    $status = 'failed';
    $message = $e->getMessage();
    secureLog('Payment error: ' . $e->getMessage(), 'ERROR');
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'reference' => $reference,
        'gateway' => $gateway,
        'status' => 'failed',
        'message' => $e->getMessage(),
        'timestamp' => date('c'),
    ]);
    exit;
}

// ============================================================
// 12. تحديث المعاملة في قاعدة البيانات
// ============================================================

try {
    $db->update('dp_transactions', [
        'status' => $status,
        'gateway_transaction_id' => $gatewayTransactionId,
        'auth_code' => $authCode,
        'rrn' => $rrn,
        'gateway_response' => json_encode($gatewayResponse, JSON_UNESCAPED_UNICODE),
        'processed_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ], ['reference' => $reference]);
    
    secureLog('Transaction updated: ' . $reference . ' → ' . $status, 'INFO');
    
} catch (Exception $e) {
    secureLog('Database update error: ' . $e->getMessage(), 'ERROR');
}

// ============================================================
// 13. إرسال Webhook (إذا تم تعيينه)
// ============================================================

$webhookUrl = getenv('DEFAULT_WEBHOOK_URL') ?: '';
if (!empty($webhookUrl) && $status !== 'failed') {
    try {
        $webhookData = [
            'event' => 'charge.' . $status,
            'gateway' => $gateway,
            'reference' => $reference,
            'amount' => $amount,
            'currency' => $currency,
            'auth_code' => $authCode,
            'rrn' => $rrn,
            'status' => $status,
            'is_moto' => $isMoto,
            'moto_indicator' => $motoIndicator,
            'timestamp' => date('c'),
        ];
        
        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($webhookData),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-DI-PARMA-Event: charge.' . $status,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        curl_exec($ch);
        curl_close($ch);
        
        secureLog('Webhook sent: ' . $webhookUrl, 'INFO');
    } catch (Exception $e) {
        secureLog('Webhook error: ' . $e->getMessage(), 'WARNING');
    }
}

// ============================================================
// 14. الرد النهائي
// ============================================================

http_response_code(200);
echo json_encode([
    'success' => true,
    'reference' => $reference,
    'gateway' => $gateway,
    'status' => $status,
    'amount' => $amount,
    'currency' => $currency,
    'auth_code' => $authCode,
    'rrn' => $rrn,
    'transaction_id' => $gatewayTransactionId,
    'is_moto' => $isMoto,
    'moto_indicator' => $motoIndicator,
    'security_mode' => $securityMode,
    'card_last4' => $cardValidation['last4'],
    'card_brand' => $cardValidation['card_type'],
    'message' => $message,
    'timestamp' => date('c'),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

secureLog('Response sent: ' . $reference . ' → ' . $status, 'INFO');

// ============================================================
// نهاية الملف
// ============================================================
?>