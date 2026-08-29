<?php
/**
 * ============================================================
 * DI PARMA | التحقق من حالة المعاملة (Live Sync)
 * ============================================================
 * 
 * يقوم هذا الملف بالتحقق المباشر من بوابات الدفع
 * لتحديث حالة المعاملة في قاعدة البيانات
 * 
 * ============================================================
 * البوابات المدعومة:
 * - Wise, Stripe, PayPal, MyFatoorah, Checkout.com
 * - Nuvei, Binance, Coinbase, BitPay, DIPARMA ✅
 * - وجميع البوابات الأخرى عبر Generic Handler
 * ============================================================
 * 
 * طريقة الاستخدام:
 *   GET /api/check_transaction.php?id=123
 *   GET /api/check_transaction.php?ref=DP_ABC123
 *   GET /api/check_transaction.php?gateway=diparma&id=123
 * ============================================================
 */

// ============================================================
// [1] استيراد الملفات المطلوبة
// ============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

// ============================================================
// [2] إعدادات الرأس
// ============================================================

header('Content-Type: application/json; charset=utf-8');

// ============================================================
// [3] التحقق من الجلسة (Session)
// ============================================================

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'غير مسموح. يرجى تسجيل الدخول أولاً.'
    ]);
    exit();
}

// ============================================================
// [4] استقبال معرّف المعاملة
// ============================================================

$id = intval($_GET['id'] ?? 0);
$ref = trim($_GET['ref'] ?? $_GET['reference'] ?? '');
$gatewayFilter = strtolower(trim($_GET['gateway'] ?? ''));

if ($id <= 0 && empty($ref)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Transaction ID or Reference is required'
    ]);
    exit();
}

// ============================================================
// [5] جلب المعاملة من قاعدة البيانات
// ============================================================

$db = db();
$transaction = null;

if ($id > 0) {
    $transaction = $db->find('dp_transactions', ['id' => $id]);
}

if (!$transaction && !empty($ref)) {
    $transaction = $db->find('dp_transactions', ['reference' => $ref]);
}

if (!$transaction) {
    try {
        $rows = $db->query(
            "SELECT * FROM transactions WHERE id = ? OR reference = ? LIMIT 1",
            [$id, $ref]
        );
        if (!empty($rows[0])) {
            $transaction = $rows[0];
        }
    } catch (Exception $e) {}
}

if (!$transaction) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'Transaction not found'
    ]);
    exit();
}

// ============================================================
// [6] التحقق من الصلاحيات
// ============================================================

$currentUser = $db->find('users', ['id' => intval($_SESSION['user_id'])]);
$isAdmin = $currentUser && strtolower($currentUser['role'] ?? '') === 'admin';
$transactionUserId = intval($transaction['user_id'] ?? 0);

if ($transactionUserId !== intval($_SESSION['user_id']) && !$isAdmin) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'You do not have permission to view this transaction'
    ]);
    exit();
}

// ============================================================
// [7] استخراج بيانات المعاملة
// ============================================================

$currentStatus = $transaction['status'] ?? 'pending';
$gateway = strtolower(trim($transaction['gateway'] ?? ''));

if (!empty($gatewayFilter)) {
    $gateway = $gatewayFilter;
}

$reference = trim($transaction['gateway_transaction_id'] ?? $transaction['reference'] ?? '');
$gatewayTransferId = trim($transaction['gateway_transfer_id'] ?? '');
$gatewayResponse = $transaction['gateway_response'] ?? '';

$updated = false;
$newStatus = $currentStatus;
$liveData = null;
$source = 'local';
$gatewayType = 'unknown';

// ============================================================
// [8] جلب تكوين البوابة من قاعدة البيانات
// ============================================================

$gatewayConfig = null;
try {
    $gatewayConfig = $db->find('payment_gateways', ['code' => $gateway]);
    if ($gatewayConfig) {
        $gatewayType = $gatewayConfig['gateway_type'] ?? 'card';
        $gatewayConfig['credentials'] = json_decode($gatewayConfig['credentials'] ?? '{}', true);
        $gatewayConfig['settings'] = json_decode($gatewayConfig['settings'] ?? '{}', true);
        $gatewayConfig['config'] = json_decode($gatewayConfig['config'] ?? '{}', true);
    }
} catch (Exception $e) {}

// ============================================================
// [9] التحقق من الحالة حسب البوابة (Live Sync)
// ============================================================

if (in_array($currentStatus, ['pending', 'processing'], true)) {

    // ════════════════════════════════════════════════════════
    // 9.1 WISE
    // ════════════════════════════════════════════════════════
    if ($gateway === 'wise' && !empty($reference)) {
        try {
            $wiseServiceFile = __DIR__ . '/../lib/WiseService.php';
            if (file_exists($wiseServiceFile)) {
                require_once $wiseServiceFile;
                if (class_exists('WiseService')) {
                    $wise = WiseService::fromConfig();
                    $transfer = null;
                    
                    if (!empty($gatewayTransferId) && is_numeric($gatewayTransferId)) {
                        $transfer = $wise->getTransferById((int)$gatewayTransferId);
                    }
                    
                    if (empty($transfer['id'])) {
                        $transfer = $wise->getTransferByReference($transaction['reference'] ?? $reference);
                    }
                    
                    if (!empty($transfer['id'])) {
                        $wiseStatus = $transfer['status'] ?? '';
                        $mapped = WiseService::mapStatus($wiseStatus);
                        $liveData = $transfer;
                        $source = 'wise_api';
                        
                        if ($mapped !== $currentStatus) {
                            $newStatus = $mapped;
                            $updated = true;
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log('[DI PARMA] Wise error: ' . $e->getMessage());
        }
    }

    // ════════════════════════════════════════════════════════
    // 9.2 STRIPE
    // ════════════════════════════════════════════════════════
    elseif ($gateway === 'stripe' && !empty($reference)) {
        try {
            if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
                require_once __DIR__ . '/../vendor/autoload.php';
                
                if (class_exists('Stripe\Stripe')) {
                    $stripeSecret = getenv('STRIPE_SECRET_KEY') ?: 
                                   ($gatewayConfig['credentials']['secret_key'] ?? '');
                    
                    if (!empty($stripeSecret)) {
                        \Stripe\Stripe::setApiKey($stripeSecret);
                        $paymentIntent = \Stripe\PaymentIntent::retrieve($reference);
                        
                        if ($paymentIntent) {
                            $stripeStatus = $paymentIntent->status;
                            $mapped = mapStripeStatus($stripeStatus);
                            $liveData = $paymentIntent->toArray();
                            $source = 'stripe_api';
                            
                            if ($mapped !== $currentStatus) {
                                $newStatus = $mapped;
                                $updated = true;
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log('[DI PARMA] Stripe error: ' . $e->getMessage());
        }
    }

    // ════════════════════════════════════════════════════════
    // 9.3 PAYPAL
    // ════════════════════════════════════════════════════════
    elseif ($gateway === 'paypal' && !empty($reference)) {
        try {
            $clientId = getenv('PAYPAL_CLIENT_ID') ?: 
                       ($gatewayConfig['credentials']['client_id'] ?? '');
            $clientSecret = getenv('PAYPAL_CLIENT_SECRET') ?: 
                          ($gatewayConfig['credentials']['client_secret'] ?? '');
            $env = getenv('PAYPAL_ENVIRONMENT') ?: 
                  ($gatewayConfig['settings']['environment'] ?? 'sandbox');
            
            if (!empty($clientId) && !empty($clientSecret)) {
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
                    CURLOPT_TIMEOUT => 10,
                ]);
                
                $tokenResponse = curl_exec($ch);
                curl_close($ch);
                
                $tokenData = json_decode($tokenResponse, true);
                $accessToken = $tokenData['access_token'] ?? '';
                
                if (!empty($accessToken)) {
                    $paypalUrl = $env === 'production'
                        ? 'https://api-m.paypal.com/v2/checkout/orders/' . $reference
                        : 'https://api-m.sandbox.paypal.com/v2/checkout/orders/' . $reference;
                    
                    $ch = curl_init($paypalUrl);
                    curl_setopt_array($ch, [
                        CURLOPT_HTTPHEADER => [
                            'Authorization: Bearer ' . $accessToken,
                            'Content-Type: application/json',
                        ],
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 10,
                    ]);
                    
                    $orderResponse = curl_exec($ch);
                    curl_close($ch);
                    
                    $orderData = json_decode($orderResponse, true);
                    
                    if (!empty($orderData['status'])) {
                        $paypalStatus = $orderData['status'];
                        $mapped = mapPayPalStatus($paypalStatus);
                        $liveData = $orderData;
                        $source = 'paypal_api';
                        
                        if ($mapped !== $currentStatus) {
                            $newStatus = $mapped;
                            $updated = true;
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log('[DI PARMA] PayPal error: ' . $e->getMessage());
        }
    }

    // ════════════════════════════════════════════════════════
    // 9.4 MYFATOORAH
    // ════════════════════════════════════════════════════════
    elseif ($gateway === 'myfatoorah' && !empty($reference)) {
        try {
            $apiKey = getenv('MYFATOORAH_API_KEY') ?: 
                     ($gatewayConfig['credentials']['api_key'] ?? '');
            $env = getenv('MYFATOORAH_ENVIRONMENT') ?: 
                  ($gatewayConfig['settings']['environment'] ?? 'sandbox');
            
            if (!empty($apiKey)) {
                $url = $env === 'production'
                    ? 'https://api.myfatoorah.com/v2/GetPaymentStatus'
                    : 'https://apitest.myfatoorah.com/v2/GetPaymentStatus';
                
                $data = ['InvoiceId' => $reference];
                
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($data),
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $apiKey,
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 10,
                ]);
                
                $response = curl_exec($ch);
                curl_close($ch);
                
                $responseData = json_decode($response, true);
                
                if (!empty($responseData['Data']['InvoiceStatus'])) {
                    $myfatoorahStatus = $responseData['Data']['InvoiceStatus'];
                    $mapped = mapMyFatoorahStatus($myfatoorahStatus);
                    $liveData = $responseData;
                    $source = 'myfatoorah_api';
                    
                    if ($mapped !== $currentStatus) {
                        $newStatus = $mapped;
                        $updated = true;
                    }
                }
            }
        } catch (Exception $e) {
            error_log('[DI PARMA] MyFatoorah error: ' . $e->getMessage());
        }
    }

    // ════════════════════════════════════════════════════════
    // 9.5 CHECKOUT.COM
    // ════════════════════════════════════════════════════════
    elseif ($gateway === 'checkout' && !empty($reference)) {
        try {
            $secretKey = getenv('CHECKOUT_SECRET_KEY') ?: 
                        ($gatewayConfig['credentials']['secret_key'] ?? '');
            
            if (!empty($secretKey)) {
                $ch = curl_init('https://api.checkout.com/payments/' . $reference);
                curl_setopt_array($ch, [
                    CURLOPT_HTTPHEADER => [
                        'Authorization: ' . $secretKey,
                        'Content-Type: application/json',
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 10,
                ]);
                
                $response = curl_exec($ch);
                curl_close($ch);
                
                $responseData = json_decode($response, true);
                
                if (!empty($responseData['status'])) {
                    $checkoutStatus = $responseData['status'];
                    $mapped = mapCheckoutStatus($checkoutStatus);
                    $liveData = $responseData;
                    $source = 'checkout_api';
                    
                    if ($mapped !== $currentStatus) {
                        $newStatus = $mapped;
                        $updated = true;
                    }
                }
            }
        } catch (Exception $e) {
            error_log('[DI PARMA] Checkout.com error: ' . $e->getMessage());
        }
    }

    // ════════════════════════════════════════════════════════
    // 9.6 NUVEI
    // ════════════════════════════════════════════════════════
    elseif ($gateway === 'nuvei' && !empty($reference)) {
        try {
            $merchantId = getenv('NUVEI_MERCHANT_ID') ?: 
                        ($gatewayConfig['credentials']['merchant_id'] ?? '');
            $siteId = getenv('NUVEI_SITE_ID') ?: 
                    ($gatewayConfig['credentials']['site_id'] ?? '');
            $secretKey = getenv('NUVEI_SECRET_KEY') ?: 
                        ($gatewayConfig['credentials']['secret_key'] ?? '');
            
            if (!empty($merchantId) && !empty($secretKey)) {
                $timestamp = time();
                $checksum = md5($merchantId . $timestamp . $secretKey);
                
                $data = [
                    'merchantId' => $merchantId,
                    'merchantSiteId' => $siteId,
                    'timeStamp' => $timestamp,
                    'checksum' => $checksum,
                    'orderId' => $reference,
                ];
                
                $url = getenv('NUVEI_ENVIRONMENT') === 'live'
                    ? 'https://api.nuvei.com/v1/getTransactionStatus'
                    : 'https://sandbox.api.nuvei.com/v1/getTransactionStatus';
                
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($data),
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 10,
                ]);
                
                $response = curl_exec($ch);
                curl_close($ch);
                
                $responseData = json_decode($response, true);
                
                if (!empty($responseData['transactionStatus'])) {
                    $nuveiStatus = $responseData['transactionStatus'];
                    $mapped = mapNuveiStatus($nuveiStatus);
                    $liveData = $responseData;
                    $source = 'nuvei_api';
                    
                    if ($mapped !== $currentStatus) {
                        $newStatus = $mapped;
                        $updated = true;
                    }
                }
            }
        } catch (Exception $e) {
            error_log('[DI PARMA] Nuvei error: ' . $e->getMessage());
        }
    }

    // ════════════════════════════════════════════════════════
    // 9.7 BINANCE
    // ════════════════════════════════════════════════════════
    elseif ($gateway === 'binance' && !empty($reference)) {
        try {
            $apiKey = getenv('BINANCE_API_KEY') ?: 
                     ($gatewayConfig['credentials']['api_key'] ?? '');
            $apiSecret = getenv('BINANCE_API_SECRET') ?: 
                        ($gatewayConfig['credentials']['api_secret'] ?? '');
            
            if (!empty($apiKey) && !empty($apiSecret)) {
                $timestamp = round(microtime(true) * 1000);
                $signature = hash_hmac('sha256', 'timestamp=' . $timestamp, $apiSecret);
                
                $ch = curl_init('https://api.binance.com/sapi/v1/pay/transactions?prepayId=' . $reference);
                curl_setopt_array($ch, [
                    CURLOPT_HTTPHEADER => [
                        'X-MBX-APIKEY: ' . $apiKey,
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 10,
                ]);
                
                $response = curl_exec($ch);
                curl_close($ch);
                
                $responseData = json_decode($response, true);
                
                if (!empty($responseData['status'])) {
                    $binanceStatus = $responseData['status'];
                    $mapped = mapBinanceStatus($binanceStatus);
                    $liveData = $responseData;
                    $source = 'binance_api';
                    
                    if ($mapped !== $currentStatus) {
                        $newStatus = $mapped;
                        $updated = true;
                    }
                }
            }
        } catch (Exception $e) {
            error_log('[DI PARMA] Binance error: ' . $e->getMessage());
        }
    }

    // ════════════════════════════════════════════════════════
    // 9.8 COINBASE
    // ════════════════════════════════════════════════════════
    elseif ($gateway === 'coinbase' && !empty($reference)) {
        try {
            $apiKey = getenv('COINBASE_API_KEY') ?: 
                     ($gatewayConfig['credentials']['api_key'] ?? '');
            $apiSecret = getenv('COINBASE_API_SECRET') ?: 
                        ($gatewayConfig['credentials']['api_secret'] ?? '');
            
            if (!empty($apiKey) && !empty($apiSecret)) {
                $timestamp = time();
                $signature = hash_hmac('sha256', $timestamp . 'GET' . '/v2/charges/' . $reference, $apiSecret);
                
                $ch = curl_init('https://api.commerce.coinbase.com/v2/charges/' . $reference);
                curl_setopt_array($ch, [
                    CURLOPT_HTTPHEADER => [
                        'X-CC-Api-Key: ' . $apiKey,
                        'X-CC-Version: 2018-03-22',
                        'X-CC-Signature: ' . $signature,
                        'X-CC-Timestamp: ' . $timestamp,
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 10,
                ]);
                
                $response = curl_exec($ch);
                curl_close($ch);
                
                $responseData = json_decode($response, true);
                
                if (!empty($responseData['data']['status'])) {
                    $coinbaseStatus = $responseData['data']['status'];
                    $mapped = mapCoinbaseStatus($coinbaseStatus);
                    $liveData = $responseData;
                    $source = 'coinbase_api';
                    
                    if ($mapped !== $currentStatus) {
                        $newStatus = $mapped;
                        $updated = true;
                    }
                }
            }
        } catch (Exception $e) {
            error_log('[DI PARMA] Coinbase error: ' . $e->getMessage());
        }
    }

    // ════════════════════════════════════════════════════════
    // 9.9 BITPAY
    // ════════════════════════════════════════════════════════
    elseif ($gateway === 'bitpay' && !empty($reference)) {
        try {
            $apiKey = getenv('BITPAY_API_KEY') ?: 
                     ($gatewayConfig['credentials']['api_key'] ?? '');
            
            if (!empty($apiKey)) {
                $ch = curl_init('https://api.bitpay.com/v1/invoices/' . $reference);
                curl_setopt_array($ch, [
                    CURLOPT_HTTPHEADER => [
                        'X-Identity: ' . $apiKey,
                        'Content-Type: application/json',
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 10,
                ]);
                
                $response = curl_exec($ch);
                curl_close($ch);
                
                $responseData = json_decode($response, true);
                
                if (!empty($responseData['data']['status'])) {
                    $bitpayStatus = $responseData['data']['status'];
                    $mapped = mapBitpayStatus($bitpayStatus);
                    $liveData = $responseData;
                    $source = 'bitpay_api';
                    
                    if ($mapped !== $currentStatus) {
                        $newStatus = $mapped;
                        $updated = true;
                    }
                }
            }
        } catch (Exception $e) {
            error_log('[DI PARMA] BitPay error: ' . $e->getMessage());
        }
    }

    // ════════════════════════════════════════════════════════
    // 9.10 DI PARMA GATEWAY ✅
    // ════════════════════════════════════════════════════════
    elseif ($gateway === 'diparma' || $gateway === 'diparma_ledger' || $gateway === 'diparma_usd') {
        
        try {
            /**
             * التحقق من حالة المعاملة عبر DI PARMA Gateway
             * 
             * DI PARMA Gateway يوفر API للتحقق من حالة المعاملة
             * endpoint: /api/v1/transaction_status.php
             */
            $siteUrl = getenv('SITE_URL') ?: 'https://diparmas.com';
            $apiEndpoint = $siteUrl . '/api/v1/transaction_status.php';
            
            // استخدام المفاتيح من تكوين DI PARMA
            $diparmaApiKey = getenv('DIPARMA_API_KEY') ?: 
                            ($gatewayConfig['credentials']['api_key'] ?? '');
            $diparmaApiSecret = getenv('DIPARMA_API_SECRET') ?: 
                               ($gatewayConfig['credentials']['api_secret'] ?? '');
            
            if (!empty($diparmaApiKey)) {
                
                // بناء الطلب للتحقق من الحالة
                $timestamp = time();
                $payload = json_encode([
                    'reference' => $reference,
                    'action' => 'status',
                ]);
                
                // حساب التوقيع HMAC
                $signature = hash_hmac('sha256', $timestamp . '.' . $reference, $diparmaApiSecret);
                
                $ch = curl_init($apiEndpoint);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $payload,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'X-Api-Key: ' . $diparmaApiKey,
                        'X-Timestamp: ' . $timestamp,
                        'X-Signature: ' . $signature,
                        'X-Gateway: diparma',
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($httpCode === 200) {
                    $responseData = json_decode($response, true);
                    
                    if (isset($responseData['success']) && $responseData['success'] === true) {
                        $diparmaStatus = $responseData['status'] ?? 'pending';
                        $mapped = mapDIPARMAStatus($diparmaStatus);
                        $liveData = $responseData;
                        $source = 'diparma_api';
                        
                        // استخراج بيانات إضافية
                        if (isset($responseData['auth_code'])) {
                            $transaction['auth_code'] = $responseData['auth_code'];
                        }
                        if (isset($responseData['rrn'])) {
                            $transaction['rrn'] = $responseData['rrn'];
                        }
                        if (isset($responseData['ledger']['txid'])) {
                            $transaction['ledger_txid'] = $responseData['ledger']['txid'];
                        }
                        
                        if ($mapped !== $currentStatus) {
                            $newStatus = $mapped;
                            $updated = true;
                        }
                    }
                } else {
                    // محاولة التحقق من الاستجابة المخزنة
                    error_log('[DI PARMA] DI PARMA API status check failed: HTTP ' . $httpCode);
                }
            }
            
            // إذا فشل API، محاولة استخدام الاستجابة المخزنة
            if (!$updated) {
                $stored = $gatewayResponse;
                if (is_string($stored) && $stored !== '') {
                    $decoded = json_decode($stored, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $rawStatus = $decoded['status'] ?? $decoded['data']['status'] ?? null;
                        if (!empty($rawStatus)) {
                            $mapped = mapDIPARMAStatus($rawStatus);
                            if ($mapped !== $currentStatus) {
                                $newStatus = $mapped;
                                $updated = true;
                                $source = 'stored_response';
                            }
                        }
                    }
                }
            }
            
        } catch (Exception $e) {
            error_log('[DI PARMA] DI PARMA Gateway error: ' . $e->getMessage());
        }
    }
}

// ============================================================
// [10] Fallback - قراءة الاستجابة المخزّنة محلياً
// ============================================================

if (!$updated && in_array($currentStatus, ['pending', 'processing'], true)) {
    $stored = $gatewayResponse;
    if (is_string($stored) && $stored !== '') {
        $decoded = json_decode($stored, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $rawStatus = $decoded['status']
                ?? $decoded['data']['status']
                ?? $decoded['transfer']['status']
                ?? $decoded['payment_intent']['status']
                ?? $decoded['transactionStatus']
                ?? $decoded['InvoiceStatus']
                ?? $decoded['Status']
                ?? null;
            
            if (!empty($rawStatus)) {
                $mapped = mapGenericStatus($rawStatus);
                if ($mapped !== $currentStatus) {
                    $newStatus = $mapped;
                    $updated = true;
                    $source = 'stored_response';
                }
            }
        }
    }
}

// ============================================================
// [11] حفظ التحديث في قاعدة البيانات
// ============================================================

if ($updated) {
    try {
        $updateData = [
            'status' => $newStatus,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        
        if ($liveData !== null) {
            $updateData['gateway_response'] = json_encode($liveData, JSON_UNESCAPED_UNICODE);
        }
        
        $db->update('dp_transactions', $updateData, ['id' => $transaction['id']]);
        
        try {
            $db->update('transactions', ['status' => $newStatus], ['id' => $transaction['id']]);
        } catch (Exception $e) {}
        
    } catch (Exception $e) {
        error_log('[DI PARMA] Database update error: ' . $e->getMessage());
    }
}

// ============================================================
// [12] بناء الاستجابة النهائية
// ============================================================

$responseData = [
    'success' => true,
    'id' => (int)$transaction['id'],
    'reference' => $transaction['reference'] ?? '',
    'gateway' => $gateway,
    'gateway_type' => $gatewayType,
    'previous_status' => $currentStatus,
    'status' => $newStatus,
    'status_label' => getStatusLabel($newStatus),
    'status_color' => getStatusColor($newStatus),
    'updated' => $updated,
    'source' => $source,
    'timestamp' => date('c'),
];

if ($liveData !== null) {
    $responseData['gateway_data'] = $liveData;
}

http_response_code(200);
echo json_encode($responseData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

// ============================================================
// [13] دوال مساعدة - خريطة الحالات
// ============================================================

/**
 * تحويل حالة DI PARMA إلى حالة النظام
 */
function mapDIPARMAStatus($diparmaStatus) {
    $diparmaStatus = strtoupper(trim($diparmaStatus));
    $map = [
        'COMPLETED' => 'completed',
        'SUCCESS' => 'completed',
        'APPROVED' => 'completed',
        'CAPTURED' => 'completed',
        'SETTLED' => 'completed',
        'PROCESSING' => 'processing',
        'PENDING' => 'pending',
        'AWAITING' => 'pending',
        'FAILED' => 'failed',
        'DECLINED' => 'failed',
        'REJECTED' => 'failed',
        'CANCELLED' => 'cancelled',
        'VOIDED' => 'cancelled',
        'REFUNDED' => 'refunded',
        'PENDING_LEDGER' => 'pending_ledger',
        'LEDGER_SENT' => 'completed',
        'LEDGER_QUEUED' => 'pending_ledger',
    ];
    return $map[$diparmaStatus] ?? 'pending';
}

/**
 * تحويل حالة Stripe
 */
function mapStripeStatus($stripeStatus) {
    $map = [
        'succeeded' => 'completed',
        'processing' => 'processing',
        'requires_action' => 'processing',
        'requires_confirmation' => 'pending',
        'requires_payment_method' => 'pending',
        'canceled' => 'cancelled',
    ];
    return $map[$stripeStatus] ?? 'pending';
}

/**
 * تحويل حالة PayPal
 */
function mapPayPalStatus($paypalStatus) {
    $map = [
        'COMPLETED' => 'completed',
        'APPROVED' => 'completed',
        'SAVED' => 'processing',
        'CREATED' => 'pending',
        'VOIDED' => 'cancelled',
        'PAYER_ACTION_REQUIRED' => 'processing',
    ];
    return $map[$paypalStatus] ?? 'pending';
}

/**
 * تحويل حالة MyFatoorah
 */
function mapMyFatoorahStatus($myfatoorahStatus) {
    $map = [
        'Paid' => 'completed',
        'Pending' => 'pending',
        'Failed' => 'failed',
        'Expired' => 'cancelled',
        'Canceled' => 'cancelled',
        'Refunded' => 'refunded',
        'PartiallyRefunded' => 'refunded',
    ];
    return $map[$myfatoorahStatus] ?? 'pending';
}

/**
 * تحويل حالة Checkout.com
 */
function mapCheckoutStatus($checkoutStatus) {
    $map = [
        'captured' => 'completed',
        'authorized' => 'completed',
        'pending' => 'pending',
        'declined' => 'failed',
        'cancelled' => 'cancelled',
        'refunded' => 'refunded',
    ];
    return $map[$checkoutStatus] ?? 'pending';
}

/**
 * تحويل حالة Nuvei
 */
function mapNuveiStatus($nuveiStatus) {
    $map = [
        'SUCCESS' => 'completed',
        'PENDING' => 'pending',
        'DECLINED' => 'failed',
        'CANCELED' => 'cancelled',
        'REFUNDED' => 'refunded',
    ];
    return $map[$nuveiStatus] ?? 'pending';
}

/**
 * تحويل حالة Binance
 */
function mapBinanceStatus($binanceStatus) {
    $map = [
        'PAID' => 'completed',
        'PENDING' => 'pending',
        'FAILED' => 'failed',
        'EXPIRED' => 'cancelled',
        'REFUNDED' => 'refunded',
    ];
    return $map[$binanceStatus] ?? 'pending';
}

/**
 * تحويل حالة Coinbase
 */
function mapCoinbaseStatus($coinbaseStatus) {
    $map = [
        'COMPLETED' => 'completed',
        'PENDING' => 'pending',
        'FAILED' => 'failed',
        'EXPIRED' => 'cancelled',
        'CANCELED' => 'cancelled',
        'REFUNDED' => 'refunded',
    ];
    return $map[$coinbaseStatus] ?? 'pending';
}

/**
 * تحويل حالة BitPay
 */
function mapBitpayStatus($bitpayStatus) {
    $map = [
        'PAID' => 'completed',
        'CONFIRMED' => 'completed',
        'COMPLETE' => 'completed',
        'PENDING' => 'pending',
        'FAILED' => 'failed',
        'EXPIRED' => 'cancelled',
        'CANCELED' => 'cancelled',
        'REFUNDED' => 'refunded',
    ];
    return $map[$bitpayStatus] ?? 'pending';
}

/**
 * تحويل حالة عامة
 */
function mapGenericStatus($status) {
    $status = strtoupper(trim($status));
    $map = [
        'SUCCESS' => 'completed',
        'COMPLETED' => 'completed',
        'APPROVED' => 'completed',
        'CAPTURED' => 'completed',
        'SETTLED' => 'completed',
        'PAID' => 'completed',
        'PROCESSING' => 'processing',
        'PENDING' => 'pending',
        'AWAITING' => 'pending',
        'FAILED' => 'failed',
        'DECLINED' => 'failed',
        'REJECTED' => 'failed',
        'CANCELLED' => 'cancelled',
        'VOIDED' => 'cancelled',
        'REFUNDED' => 'refunded',
    ];
    return $map[$status] ?? 'pending';
}

// NOTE: getStatusLabel() is now defined in includes/functions.php
// Removed duplicate declaration to prevent fatal errors

/**
 * لون الحالة
 */
function getStatusColor($status) {
    $colors = [
        'pending' => '#f0ad4e',
        'processing' => '#5bc0de',
        'completed' => '#4CAF50',
        'failed' => '#d9534f',
        'cancelled' => '#888',
        'refunded' => '#f0ad4e',
        'pending_ledger' => '#f0ad4e',
        'pending_bank_transfer' => '#f0ad4e',
        'pending_crypto' => '#f0ad4e',
        'authorized' => '#5bc0de',
        'captured' => '#4CAF50',
        'settled' => '#4CAF50',
    ];
    return $colors[$status] ?? '#888';
}

// ============================================================
// نهاية الملف
// ============================================================
?>