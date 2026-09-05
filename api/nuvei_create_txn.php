<?php
/**
 * DI PARMA | Nuvei — Create Transaction ID Only (no charge)
 * POST: { amount, currency, email, csrf_token }
 * Returns: { success, transaction_id, session_token, reference }
 */
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success'=>false,'message'=>'Unauthorized']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;

if (!verifyCsrfToken($payload['csrf_token'] ?? '')) {
    echo json_encode(['success'=>false,'message'=>'CSRF invalid']);
    exit;
}

$amount   = floatval($payload['amount']   ?? 1);
$currency = strtoupper(trim($payload['currency'] ?? 'USD'));
$email    = trim($payload['email'] ?? 'guest@diparmas.com');

// Nuvei credentials
$merchantId = trim((string)getenv('NUVEI_MERCHANT_ID'));
$siteId     = trim((string)getenv('NUVEI_SITE_ID'));
$secretKey  = trim((string)getenv('NUVEI_SECRET_KEY'));
if ($merchantId === '' || $siteId === '' || $secretKey === '') {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Nuvei credentials are not configured']);
    exit;
}
$baseUrl = 'https://secure.nuvei.com/ppp/api/v1';

$ref  = 'TXN' . strtoupper(bin2hex(random_bytes(5))) . date('Ymd');
$ts   = date('YmdHis');
$amtF = number_format($amount, 2, '.', '');

// Checksum: merchantId + siteId + clientRequestId + timeStamp + secretKey
$checksum = hash('sha256', $merchantId . $siteId . $ref . $ts . $secretKey);

$body = [
    'merchantId'      => $merchantId,
    'merchantSiteId'  => $siteId,
    'clientRequestId' => $ref,
    'clientUniqueId'  => $ref,
    'amount'          => $amtF,
    'currency'        => $currency,
    'timeStamp'       => $ts,
    'checksum'        => $checksum,
    'userTokenId'     => $email,
    'billingAddress'  => ['email' => $email],
    'transactionType' => 'Auth', // حجز فقط بدون سحب
];

$ch = curl_init($baseUrl . '/openOrder.do');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($body),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$resp = curl_exec($ch);
$err  = curl_error($ch);
curl_close($ch);

if ($err) {
    echo json_encode(['success'=>false,'message'=>'cURL Error: '.$err]);
    exit;
}

$data = json_decode($resp, true) ?: [];

if (($data['status'] ?? '') === 'SUCCESS' && !empty($data['sessionToken'])) {
    // نحفظ في DB
    try {
        $db = db();
        $db->execute(
            "INSERT INTO dp_transactions
             (reference, gateway, amount, currency, customer_email, status,
              transaction_type, security_mode, gateway_response, created_at)
             VALUES (?,?,?,?,?,'pending','Nuvei Create TxID','2D',?,NOW())",
            [
                $ref, 'nuvei', $amount, $currency, $email,
                json_encode([
                    'session_token' => $data['sessionToken'],
                    'order_id'      => $data['orderId'] ?? '',
                    'merchant_id'   => $merchantId,
                    'site_id'       => $siteId,
                    'amount'        => $amtF,
                    'currency'      => $currency,
                ])
            ]
        );
    } catch (Throwable $e) {
        error_log('[Nuvei Create Txn] DB error: ' . $e->getMessage());
    }
    echo json_encode([
        'success'       => true,
        'reference'     => $ref,
        'session_token' => $data['sessionToken'],
        'order_id'      => $data['orderId'] ?? '',
        'message'       => 'Transaction created — use sessionToken as Transaction ID',
        'note'          => 'Use this in capture.php as Transaction ID',
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => ($data['reason'] ?? $data['errCode'] ?? 'Nuvei error'),
        'raw'     => $data,
    ]);
}
