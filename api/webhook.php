<?php
/**
 * ============================================================
 * DI PARMA | معالج Webhook - يدعم جميع البوابات
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

// ── إعداد السجلات ──────────────────────────────────────────
$logDir  = defined('LOGS_PATH') ? LOGS_PATH : __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/webhook.log';

$rawPayload = file_get_contents('php://input');
$headers    = function_exists('getallheaders') ? getallheaders() : [];

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
if (empty($rawPayload)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Empty payload']);
    exit();
}

$data = json_decode($rawPayload, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON payload']);
    exit();
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
    }
}

// ── التحقق من التوقيع (HMAC أو Stripe signature) إذا كان مفعّلاً ─────
if ($gateway !== 'moonpay' && defined('WEBHOOK_VERIFY_SIGNATURE') && WEBHOOK_VERIFY_SIGNATURE === true) {
    $secret = defined('WEBHOOK_HMAC_SECRET') ? WEBHOOK_HMAC_SECRET : '';
    if (!empty($secret)) {
        $signatureHeader = $normalizedHeaders['stripe-signature']
            ?? $normalizedHeaders['x-signature']
            ?? $normalizedHeaders['x-wise-signature']
            ?? $normalizedHeaders['x-hub-signature-256']
            ?? '';

        if (!empty($signatureHeader)) {
            $valid = false;
            if (!empty($normalizedHeaders['stripe-signature'])) {
                require_once __DIR__ . '/../lib/Adapters/GatewayWebhookVerifier.php';
                $valid = GatewayWebhookVerifier::verifyStripeSignature($rawPayload, $signatureHeader, $secret);
            } else {
                require_once __DIR__ . '/../lib/Adapters/GatewayWebhookVerifier.php';
                $valid = GatewayWebhookVerifier::verifyGenericSignature($rawPayload, $signatureHeader, $secret, 'sha256');
            }

            if (!$valid) {
                file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] SECURITY: Invalid webhook signature from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n", FILE_APPEND);
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Invalid signature']);
                exit();
            }
        }
    }
}

// ── استخراج المرجع والحالة من هياكل بيانات متعددة ──────────
$gateway = strtolower(trim($_GET['gateway'] ?? ''));

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

    case 'paypal':
        $reference = $data['resource']['invoice_id']
            ?? $data['resource']['id']
            ?? null;
        $eventType = $data['event_type'] ?? '';
        $rawStatus = str_contains($eventType, 'COMPLETED') ? 'completed'
            : (str_contains($eventType, 'DENIED') || str_contains($eventType, 'FAILED') ? 'failed' : 'pending');
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
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Transaction not found', 'reference' => $reference]);
        exit();
    }

    // تحديث الحالة فقط إذا تغيرت أو كانت معلقة
    if ($transaction['status'] !== $normalizedStatus) {
        $updateData = [
            'status'           => $normalizedStatus,
            'gateway_response' => $rawPayload,
        ];
        // إضافة updated_at إذا كان العمود موجوداً
        try {
            $cols = $db->query("SHOW COLUMNS FROM " . DB_PREFIX . "transactions LIKE 'updated_at'");
            if (!empty($cols)) {
                $updateData['updated_at'] = date('Y-m-d H:i:s');
            }
        } catch (Exception $e) {}

        $db->update('transactions', $updateData, ['reference' => $reference]);

        file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Updated {$reference}: {$transaction['status']} → {$normalizedStatus}\n", FILE_APPEND);
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
