<?php
/**
 * ============================================================
 * DI PARMA | معالج Webhook - يدعم جميع البوابات
 * ============================================================
 */

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$gatewayHint = strtolower(trim((string)($_GET['gateway'] ?? $_POST['gateway'] ?? '')));
$nuveiDmn = isset($_GET['ppp_status'])
    || isset($_GET['Status'])
    || isset($_GET['merchant_unique_id'])
    || isset($_GET['TransactionId'])
    || isset($_GET['TransactionID'])
    || isset($_GET['ppp_TransactionID'])
    || isset($_GET['clientUniqueId'])
    || isset($_POST['ppp_status'])
    || isset($_POST['Status'])
    || isset($_POST['merchant_unique_id'])
    || isset($_POST['TransactionId'])
    || isset($_POST['clientUniqueId']);
if ($method === 'HEAD' || ($method === 'GET' && !$nuveiDmn && $gatewayHint !== 'nuvei')) {
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'OK';
    exit;
}
if ($nuveiDmn || $gatewayHint === 'nuvei') {
    require __DIR__ . '/nuvei_dmn.php';
    exit;
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
if (is_file(__DIR__ . '/../includes/peer_link.php')) {
    require_once __DIR__ . '/../includes/peer_link.php';
}

// ── إعداد السجلات ──────────────────────────────────────────
$logDir  = defined('LOGS_PATH') ? LOGS_PATH : __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/webhook.log';

$rawPayload = file_get_contents('php://input');
$headers    = function_exists('getallheaders') ? getallheaders() : [];
$gateway    = strtolower(trim($_GET['gateway'] ?? $_POST['gateway'] ?? 'nuvei'));
if ($rawPayload === '' && empty($_POST)) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo 'OK';
    exit;
}

// تسوية أسماء الهيدرات (case-insensitive)
$normalizedHeaders = [];
foreach ($headers as $k => $v) {
    $normalizedHeaders[strtolower($k)] = $v;
}

// تسجيل الطلب الوارد
$logEntry = [
    'time'    => date('Y-m-d H:i:s'),
    'ip'      => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'gateway' => $_GET['gateway'] ?? 'unknown',
    'size'    => strlen($rawPayload),
];
file_put_contents($logFile, json_encode($logEntry) . "\n", FILE_APPEND);

// ── التحقق من محتوى الطلب ──────────────────────────────────
if (empty($rawPayload) && !empty($_POST)) {
    $data = $_POST;
} else {
    if (empty($rawPayload)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Empty payload']);
        exit();
    }

    $data = json_decode($rawPayload, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        parse_str($rawPayload, $form);
        if (is_array($form) && $form !== []) {
            $data = $form;
        } else {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid JSON payload']);
            exit();
        }
    }
}

// ── التحقق من توقيع MoonPay (Moonpay-Signature-V2) ────────
if ($gateway === 'moonpay') {
    $moonpaySecret = defined('MOONPAY_WEBHOOK_SIGNING_SECRET')
        ? MOONPAY_WEBHOOK_SIGNING_SECRET
        : (getenv('MOONPAY_WEBHOOK_SIGNING_SECRET') ?: '');

    if (!empty($moonpaySecret)) {
        // MoonPay يرسل توقيعَين: Moonpay-Signature (v1) و Moonpay-Signature-V2
        $sigV2 = $normalizedHeaders['moonpay-signature-v2'] ?? '';
        $sigV1 = $normalizedHeaders['moonpay-signature']    ?? '';

        $verified = false;

        // التحقق من V2 أولاً (موصى به من MoonPay)
        if (!empty($sigV2)) {
            // V2: HMAC-SHA256(rawPayload, secret) مقارنةً بـ base64url
            $expected = base64_encode(hash_hmac('sha256', $rawPayload, $moonpaySecret, true));
            $verified = hash_equals($expected, $sigV2);
        }

        // fallback لـ V1 إذا V2 غير موجود
        if (!$verified && !empty($sigV1)) {
            $expected = hash_hmac('sha256', $rawPayload, $moonpaySecret);
            $verified = hash_equals($expected, $sigV1);
        }

        if (!$verified) {
            file_put_contents($logFile,
                "[" . date('Y-m-d H:i:s') . "] MOONPAY SECURITY: Invalid signature from "
                . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n",
                FILE_APPEND
            );
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Invalid MoonPay signature']);
            exit();
        }

        file_put_contents($logFile,
            "[" . date('Y-m-d H:i:s') . "] MOONPAY: Signature verified OK\n",
            FILE_APPEND
        );
    } else {
        http_response_code(503);
        echo json_encode(['status' => 'error', 'message' => 'MoonPay webhook is not configured']);
        exit();
    }
}

// ── التحقق من التوقيع دائماً (fail-closed) ─────
if ($gateway !== 'moonpay') {
    $signatureHeader = $normalizedHeaders['stripe-signature']
        ?? $normalizedHeaders['x-signature-sha256']
        ?? $normalizedHeaders['x-signature']
        ?? $normalizedHeaders['x-wise-signature']
        ?? $normalizedHeaders['x-hub-signature-256']
        ?? $normalizedHeaders['x-whop-signature']
        ?? '';

    $valid = false;
    require_once __DIR__ . '/../lib/Adapters/GatewayWebhookVerifier.php';

    if ($gateway === 'whop') {
        $whopSecret = (string) (getenv('WHOP_WEBHOOK_SECRET') ?: '');
        $whopSig = (string) ($normalizedHeaders['x-whop-signature'] ?? $signatureHeader);
        if ($whopSecret === '' || $whopSig === '') {
            http_response_code(503);
            echo json_encode(['status' => 'error', 'message' => 'Whop webhook is not configured']);
            exit();
        }
        $valid = hash_equals(hash_hmac('sha256', $rawPayload, $whopSecret), $whopSig);
    } elseif ($gateway === 'wise') {
        require_once __DIR__ . '/../lib/WiseService.php';
        $wiseKey = (string) (getenv('WISE_WEBHOOK_PUBLIC_KEY') ?: ($_ENV['WISE_WEBHOOK_PUBLIC_KEY'] ?? ''));
        if ($wiseKey === '' || $signatureHeader === '') {
            file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] SECURITY: Wise webhook key or signature missing\n", FILE_APPEND);
            http_response_code(503);
            echo json_encode(['status' => 'error', 'message' => 'Wise webhook is not configured']);
            exit();
        }
        $valid = WiseService::verifyWebhookSignature($rawPayload, $signatureHeader, $wiseKey);
    } elseif ($gateway === 'stripe' || !empty($normalizedHeaders['stripe-signature'])) {
        $stripeSecret = (string) (getenv('STRIPE_WEBHOOK_SECRET') ?: '');
        if ($stripeSecret === '' || $signatureHeader === '') {
            http_response_code(503);
            echo json_encode(['status' => 'error', 'message' => 'Stripe webhook is not configured']);
            exit();
        }
        $valid = GatewayWebhookVerifier::verifyStripe($rawPayload, $signatureHeader, $stripeSecret);
    } elseif ($gateway === 'myfatoorah') {
        $mfSecret = (string) (getenv('MYFAOORAH_WEBHOOK_SECRET') ?: getenv('MYFAOORAH_SECRET_KEY') ?: '');
        $mfSig = (string) ($normalizedHeaders['myfatoorah-signature'] ?? $normalizedHeaders['signature'] ?? $signatureHeader);
        if ($mfSecret === '' || $mfSig === '') {
            http_response_code(503);
            echo json_encode(['status' => 'error', 'message' => 'MyFatoorah webhook is not configured']);
            exit();
        }
        $valid = hash_equals(hash_hmac('sha256', $rawPayload, $mfSecret), $mfSig)
            || hash_equals(hash_hmac('sha256', $rawPayload, $mfSecret, true), base64_decode($mfSig, true) ?: '');
    } else {
        $secret = defined('WEBHOOK_HMAC_SECRET') ? WEBHOOK_HMAC_SECRET : '';
        if ($secret === '' || $signatureHeader === '') {
            file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] SECURITY: Missing webhook verification configuration or signature\n", FILE_APPEND);
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Webhook signature required']);
            exit();
        }
        $valid = GatewayWebhookVerifier::verifyGenericSignature($rawPayload, $signatureHeader, $secret, 'sha256');
    }

    if (!$valid) {
        file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] SECURITY: Invalid webhook signature from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n", FILE_APPEND);
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Invalid signature']);
        exit();
    }
}

// ── استخراج المرجع والحالة من هياكل بيانات متعددة ──────────

$reference = null;
$rawStatus = null;

switch ($gateway) {
    case 'moonpay':
        // MoonPay webhook — transaction_updated, transaction_created, etc.
        $eventType = $data['type'] ?? '';
        $txData    = $data['data'] ?? $data;

        // المرجع: id المعاملة أو externalTransactionId
        $reference = $txData['externalTransactionId']
            ?? $txData['id']
            ?? $data['id']
            ?? null;

        $mpStatus  = strtolower($txData['status'] ?? $data['status'] ?? '');

        // تسجيل الحدث كاملاً
        file_put_contents($logFile,
            "[" . date('Y-m-d H:i:s') . "] MOONPAY EVENT: {$eventType} | ref={$reference} | status={$mpStatus}\n",
            FILE_APPEND
        );

        // تعيين الحالة
        $rawStatus = match($mpStatus) {
            'completed'  => 'completed',
            'failed'     => 'failed',
            'waitingpayment', 'waitingauthorization', 'pending' => 'pending',
            default      => $mpStatus ?: 'pending',
        };

        // إذا لم يوجد مرجع خارجي، استخدم id المعاملة مباشرة
        if (empty($reference)) {
            // أرجع 200 حتى MoonPay لا يعيد المحاولة
            http_response_code(200);
            echo json_encode(['status' => 'ok', 'note' => 'no_reference']);
            exit();
        }
        break;

    case 'wise':
        $reference = $data['data']['customerTransactionId']
            ?? $data['resource']['id']
            ?? $data['data']['reference']
            ?? null;
        $rawStatus = $data['data']['status'] ?? $data['status'] ?? null;
        break;

    case 'stripe':
        $eventType = $data['type'] ?? '';
        $reference = $data['data']['object']['metadata']['reference']
            ?? $data['data']['object']['id']
            ?? null;
        if (str_contains($eventType, 'succeeded') || str_contains($eventType, 'complete')) {
            $rawStatus = 'completed';
        } elseif (str_contains($eventType, 'fail') || str_contains($eventType, 'cancel')) {
            $rawStatus = 'failed';
        } else {
            $rawStatus = 'pending';
        }
        break;

    case 'square':
        $eventType = $data['type'] ?? ($data['event_type'] ?? '');
        $obj = $data['data']['object'] ?? $data['data'] ?? [];
        $payment = $obj['payment'] ?? $obj;
        $reference = $payment['reference_id']
            ?? $payment['id']
            ?? ($data['merchant_id'] ?? null);
        $sqStatus = strtoupper((string) ($payment['status'] ?? ''));
        if ($sqStatus === 'COMPLETED' || str_contains(strtolower((string) $eventType), 'payment.updated')) {
            $rawStatus = ($sqStatus === 'COMPLETED') ? 'completed' : 'pending';
        } elseif (in_array($sqStatus, ['FAILED', 'CANCELED', 'CANCELLED'], true)) {
            $rawStatus = 'failed';
        } else {
            $rawStatus = 'pending';
        }
        break;

    case 'paypal':
        $reference = $data['resource']['invoice_id']
            ?? $data['resource']['id']
            ?? null;
        $eventType = $data['event_type'] ?? '';
        $rawStatus = str_contains($eventType, 'COMPLETED') ? 'completed'
            : (str_contains($eventType, 'DENIED') || str_contains($eventType, 'FAILED') ? 'failed' : 'pending');
        break;

    case 'nuvei':
        $reference = $data['merchant_unique_id']
            ?? $data['clientUniqueId']
            ?? $data['clientRequestId']
            ?? $data['merchantUniqueId']
            ?? $data['customData']
            ?? $data['orderId']
            ?? $data['transactionId']
            ?? $data['TransactionId']
            ?? null;
        $rawStatus = $data['Status']
            ?? $data['ppp_status']
            ?? $data['transactionStatus']
            ?? $data['status']
            ?? $data['transaction']['status']
            ?? null;
        break;

    case 'myfatoorah':
        $mfData = is_array($data['Data'] ?? null) ? $data['Data'] : $data;
        $reference = $mfData['CustomerReference']
            ?? $mfData['InvoiceId']
            ?? $data['InvoiceId']
            ?? null;
        $mfStatus = strtolower((string) ($mfData['TransactionStatus'] ?? $mfData['InvoiceStatus'] ?? $data['Event'] ?? ''));
        if (in_array($mfStatus, ['succss', 'success', 'paid', 'deposited'], true) || str_contains($mfStatus, 'success')) {
            $rawStatus = 'completed';
        } elseif (in_array($mfStatus, ['failed', 'canceled', 'cancelled', 'expired'], true)) {
            $rawStatus = 'failed';
        } else {
            $rawStatus = 'pending';
        }
        break;

    case 'whop':
        // Whop webhook — معالجة مباشرة عبر WhopAdapter
        require_once __DIR__ . '/../lib/Adapters/WhopAdapter.php';
        $whop = new WhopAdapter();
        $sig  = $normalizedHeaders['x-whop-signature'] ?? $normalizedHeaders['whop-signature'] ?? '';
        $result = $whop->handleWebhook($rawPayload, $sig);
        http_response_code(200);
        echo json_encode(['status' => $result['success'] ? 'ok' : 'error', 'message' => $result['message'] ?? '']);
        exit();

    default:
        // محاولة استخراج شاملة من أي بنية
        $reference = $data['reference']
            ?? $data['data']['reference']
            ?? $data['transaction_id']
            ?? $data['order_id']
            ?? $data['id']
            ?? null;
        $rawStatus = $data['status']
            ?? $data['data']['status']
            ?? $data['transaction']['status']
            ?? null;
        break;
}

if (empty($reference)) {
    if ($gateway === 'nuvei') {
        http_response_code(200);
        echo 'OK';
        exit();
    }
    http_response_code(400);
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] ERROR: Reference not found in payload\n", FILE_APPEND);
    echo json_encode(['status' => 'error', 'message' => 'Transaction reference not found in payload']);
    exit();
}

// ── تعيين الحالة الموحدة ────────────────────────────────────
$statusMap = [
    'completed'  => 'completed', 'complete'    => 'completed',
    'success'    => 'completed', 'succeeded'   => 'completed',
    'processed'  => 'completed', 'payout_sent' => 'completed',
    'paid'       => 'completed', 'settled'     => 'completed',
    'captured'   => 'completed',

    'failed'     => 'failed',    'fail'        => 'failed',
    'cancelled'  => 'failed',    'canceled'    => 'failed',
    'rejected'   => 'failed',    'expired'     => 'failed',
    'declined'   => 'failed',    'banned'      => 'failed',
    'refunded'   => 'refunded',

    'pending'    => 'pending',   'processing'  => 'pending',
    'received'   => 'pending',   'authorized'  => 'pending',
];

$normalizedStatus = $statusMap[strtolower(trim($rawStatus ?? ''))] ?? 'pending';

// ── البحث عن المعاملة وتحديثها ─────────────────────────────
try {
    $db = db();
    $transaction = $db->find('transactions', ['reference' => $reference]);

    if (!$transaction) {
        if ($gateway === 'nuvei') {
            http_response_code(200);
            echo 'OK';
            exit();
        }
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Transaction not found', 'reference' => $reference]);
        exit();
    }

    $payloadAmount = $data['amount'] ?? ($data['data']['amount'] ?? null);
    $payloadCurrency = $data['currency'] ?? ($data['data']['currency'] ?? null);
    if ($payloadAmount !== null && is_numeric($payloadAmount)
        && abs((float)$payloadAmount - (float)($transaction['amount'] ?? 0)) > 0.01) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Webhook amount mismatch']);
        exit();
    }
    if ($payloadCurrency !== null && strtoupper((string)$payloadCurrency) !== strtoupper((string)($transaction['currency'] ?? ''))) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Webhook currency mismatch']);
        exit();
    }

    // تحديث الحالة فقط إذا تغيرت أو كانت معلقة
    if ($transaction['status'] !== $normalizedStatus) {
        $updateData = [
            'gateway_response' => $rawPayload,
        ];
        // لا نضع completed قبل الاستدعاء — onPaymentConfirmed هو من يغلق التسوية
        if ($normalizedStatus !== 'completed') {
            $updateData['status'] = $normalizedStatus;
        }
        try {
            $cols = $db->query("SHOW COLUMNS FROM " . DB_PREFIX . "transactions LIKE 'updated_at'");
            if (!empty($cols)) {
                $updateData['updated_at'] = date('Y-m-d H:i:s');
            }
        } catch (Exception $e) {}

        $db->update('transactions', $updateData, ['reference' => $reference]);

        file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Updated {$reference}: {$transaction['status']} → {$normalizedStatus}\n", FILE_APPEND);

        if ($normalizedStatus === 'completed' && $transaction['status'] !== 'completed') {
            try {
                require_once __DIR__ . '/../lib/PaymentOrchestrator.php';
                $confirm = PaymentOrchestrator::getInstance()->onPaymentConfirmed($reference, $data);
                file_put_contents(
                    $logFile,
                    "[" . date('Y-m-d H:i:s') . "] onPaymentConfirmed {$reference}: " . json_encode([
                        'success' => $confirm['success'] ?? false,
                        'message' => $confirm['message'] ?? '',
                    ], JSON_UNESCAPED_UNICODE) . "\n",
                    FILE_APPEND
                );
            } catch (Throwable $e) {
                file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] onPaymentConfirmed error {$reference}: " . $e->getMessage() . "\n", FILE_APPEND);
                try {
                    require_once __DIR__ . '/../lib/LedgerSettlementService.php';
                    LedgerSettlementService::settleSuccessfulPayment([
                        'reference' => $reference,
                        'amount'    => (float)($transaction['amount'] ?? 0),
                        'currency'  => (string)($transaction['currency'] ?? 'USD'),
                        'gateway'   => (string)($transaction['gateway'] ?? $gateway ?? 'unknown'),
                        'user_id'   => (int)($transaction['user_id'] ?? 0),
                        'txn_type'  => (string)($transaction['transaction_type'] ?? 'purchase'),
                        'destination' => 'ledger',
                    ]);
                } catch (Throwable $e2) {
                    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Ledger settle error {$reference}: " . $e2->getMessage() . "\n", FILE_APPEND);
                }
                try {
                    $db->update('transactions', ['status' => 'completed'], ['reference' => $reference]);
                } catch (Throwable $e3) {
                }
            }
        }

        if (empty($_SERVER['HTTP_X_PEER_ORIGIN']) && function_exists('peer_request')) {
            try {
                peer_request('sync_txn', [
                    'reference'        => $reference,
                    'gateway'          => (string)($transaction['gateway'] ?? $gateway ?? 'unknown'),
                    'amount'           => (float)($transaction['amount'] ?? 0),
                    'currency'         => (string)($transaction['currency'] ?? 'USD'),
                    'status'           => $normalizedStatus,
                    'txn_type'         => (string)($transaction['transaction_type'] ?? 'purchase'),
                    'gateway_response' => ['webhook' => $gateway, 'peer_forward' => true],
                ], 6);
            } catch (Throwable $e) {
                file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Peer sync error {$reference}: " . $e->getMessage() . "\n", FILE_APPEND);
            }
        }
    }

    $responseCode = defined('WEBHOOK_DEFAULT_RESPONSE_CODE') ? WEBHOOK_DEFAULT_RESPONSE_CODE : 200;
    http_response_code($responseCode);
    echo json_encode([
        'status'    => 'success',
        'reference' => $reference,
        'new_status'=> $normalizedStatus,
    ]);

} catch (Exception $e) {
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] DB ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal server error']);
}
