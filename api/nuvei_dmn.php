<?php
/**
 * Nuvei DMN listener — GET or POST, always HTTP 200.
 * https://docs.nuvei.com/documentation/integration/webhooks/
 */
http_response_code(200);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Allow: GET, POST, HEAD, OPTIONS');
header('Content-Security-Policy: frame-ancestors *');
header_remove('X-Frame-Options');

function nuvei_dmn_ok(): void
{
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>OK</title></head><body style="margin:0;background:#fff;color:#111;font:16px/1.4 sans-serif">OK</body></html>';
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'HEAD' || $method === 'OPTIONS') {
    nuvei_dmn_ok();
    exit;
}

$raw = (string) file_get_contents('php://input');
$data = [];
$getHasDmn = isset($_GET['ppp_status'])
    || isset($_GET['Status'])
    || isset($_GET['merchant_unique_id'])
    || isset($_GET['TransactionId'])
    || isset($_GET['TransactionID'])
    || isset($_GET['ppp_TransactionID'])
    || isset($_GET['clientUniqueId']);

if ($getHasDmn) {
    $data = $_GET;
} elseif (!empty($_POST)) {
    $data = $_POST;
} elseif ($raw !== '') {
    $json = json_decode($raw, true);
    if (is_array($json)) {
        $data = $json;
    } else {
        parse_str($raw, $form);
        $data = is_array($form) ? $form : [];
    }
}

if ($data === []) {
    nuvei_dmn_ok();
    exit;
}

$logDir = dirname(__DIR__) . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/webhook.log';
@file_put_contents($logFile, json_encode([
    'time' => date('Y-m-d H:i:s'),
    'src' => 'nuvei_dmn',
    'method' => $method,
    'keys' => array_keys($data),
], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

$reference = trim((string)(
    $data['merchant_unique_id']
    ?? $data['clientUniqueId']
    ?? $data['clientRequestId']
    ?? $data['customData']
    ?? $data['merchantUniqueId']
    ?? ''
));
$rawStatus = strtoupper(trim((string)(
    $data['Status']
    ?? $data['ppp_status']
    ?? $data['transactionStatus']
    ?? $data['status']
    ?? ''
)));
$statusMap = [
    'OK' => 'completed',
    'APPROVED' => 'completed',
    'SUCCESS' => 'completed',
    'SETTLED' => 'completed',
    'CAPTURED' => 'completed',
    'DECLINED' => 'failed',
    'FAILED' => 'failed',
    'ERROR' => 'failed',
    'FAIL' => 'failed',
    'CANCELLED' => 'failed',
    'CANCELED' => 'failed',
    'PENDING' => 'pending',
    'UPDATE' => 'pending',
];
$normalized = $statusMap[$rawStatus] ?? 'pending';

if ($reference === '') {
    nuvei_dmn_ok();
    exit;
}

try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/database.php';
    require_once __DIR__ . '/../includes/functions.php';
    require_once __DIR__ . '/../lib/Adapters/GatewayWebhookVerifier.php';

    $secret = trim((string) (getenv('NUVEI_SECRET_KEY') ?: ''));
    if ($secret === '') {
        try {
            $row = db()->find('payment_gateways', ['code' => 'nuvei']);
            $creds = json_decode((string) ($row['credentials'] ?? '{}'), true) ?: [];
            $secret = trim((string) ($creds['secret_key'] ?? $creds['merchant_secret'] ?? ''));
        } catch (Throwable $e) {
            $secret = '';
        }
    }
    if (!GatewayWebhookVerifier::verifyNuveiDmn($data, $secret)) {
        @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . "] NUVEI DMN rejected checksum\n", FILE_APPEND);
        nuvei_dmn_ok();
        exit;
    }

    $db = db();
    $transaction = $db->find('transactions', ['reference' => $reference]);
    if (!$transaction && isset($data['ppp_TransactionID'])) {
        $transaction = $db->find('transactions', ['rrn' => (string)$data['ppp_TransactionID']]) ?: null;
    }
    if ($transaction) {
        $update = [
            'gateway_response' => json_encode($data, JSON_UNESCAPED_UNICODE),
        ];
        if ($normalized !== 'completed') {
            $update['status'] = $normalized;
        }
        $db->update('transactions', $update, ['id' => $transaction['id']]);
        if ($normalized === 'completed' && ($transaction['status'] ?? '') !== 'completed') {
            require_once __DIR__ . '/../lib/PaymentOrchestrator.php';
            PaymentOrchestrator::getInstance()->onPaymentConfirmed($reference, $data);
        }
        @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . "] NUVEI DMN {$reference} → {$normalized}\n", FILE_APPEND);
    }
} catch (Throwable $e) {
    @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] NUVEI DMN error: ' . $e->getMessage() . "\n", FILE_APPEND);
}

echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>OK</title></head><body>OK</body></html>';
