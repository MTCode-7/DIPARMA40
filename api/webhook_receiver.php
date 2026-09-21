<?php
/**
 * DI PARMA | MyFatoorah Webhook Receiver
 * POST https://diparmas.com/api/webhook_receiver.php
 *
 * MyFatoorah يرسل إشعارات الدفع إلى هذا العنوان
 * التوثيق: https://docs.myfatoorah.com/docs/webhook
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

$rawBody = file_get_contents('php://input');
$logFile = defined('LOGS_PATH') ? LOGS_PATH . '/myfatoorah_webhook.log' : __DIR__ . '/../logs/myfatoorah_webhook.log';
if (!is_dir(dirname($logFile))) @mkdir(dirname($logFile), 0755, true);

// تسجيل الطلب
@file_put_contents($logFile,
    '[' . date('Y-m-d H:i:s') . '] IP=' . ($_SERVER['REMOTE_ADDR'] ?? '') .
    ' size=' . strlen($rawBody) . "\n",
    FILE_APPEND
);

if (empty($rawBody)) {
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'note' => 'empty_payload']);
    exit;
}

$data = json_decode($rawBody, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    exit;
}

// ── التحقق من المفتاح ──────────────────────────────────────
$apiKey = getenv('MYFAOORAH_API_KEY') ?: getenv('MYFAOORAH_SECRET_KEY') ?: '';
$headerKey = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';
$headerKey = ltrim(str_replace('Bearer', '', $headerKey));

if ($apiKey !== '' && $headerKey !== '' && !hash_equals($apiKey, $headerKey)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// ── استخراج البيانات ───────────────────────────────────────
$eventType  = $data['Event'] ?? $data['event'] ?? '';
$invoiceId  = $data['Data']['InvoiceId']          ?? $data['InvoiceId']    ?? null;
$paymentId  = $data['Data']['PaymentId']          ?? $data['PaymentId']    ?? null;
$reference  = $data['Data']['CustomerReference']  ?? $data['CustomerReference']
           ?? $data['Data']['InvoiceReference']   ?? $data['InvoiceReference']
           ?? $data['Data']['OrderId']             ?? $data['OrderId']
           ?? ($invoiceId ? 'MF-' . $invoiceId : null);
$status     = strtolower($data['Data']['InvoiceStatus'] ?? $data['InvoiceStatus'] ?? $data['status'] ?? '');

// تسجيل الحدث
@file_put_contents($logFile,
    '[' . date('Y-m-d H:i:s') . "] event={$eventType} ref={$reference} status={$status}\n",
    FILE_APPEND
);

if (!$reference) {
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'note' => 'no_reference']);
    exit;
}

// ── تعيين الحالة الموحّدة ──────────────────────────────────
$statusMap = [
    'paid'        => 'completed',
    'succeeded'   => 'completed',
    'success'     => 'completed',
    'completed'   => 'completed',
    'failed'      => 'failed',
    'cancelled'   => 'failed',
    'canceled'    => 'failed',
    'expired'     => 'failed',
    'pending'     => 'pending',
    'initiated'   => 'pending',
];
$normalized = $statusMap[$status] ?? 'pending';

// ── تحديث قاعدة البيانات ──────────────────────────────────
try {
    $db = db();

    // البحث بالمرجع أو InvoiceId
    $txn = $db->find('transactions', ['reference' => $reference]);
    if (!$txn && $invoiceId) {
        $rows = $db->query(
            "SELECT * FROM dp_transactions WHERE JSON_EXTRACT(gateway_response,'$.InvoiceId')=? OR JSON_EXTRACT(gateway_response,'$.invoice_id')=? LIMIT 1",
            [$invoiceId, $invoiceId]
        );
        $txn = $rows[0] ?? null;
    }

    if ($txn) {
        $db->execute(
            "UPDATE dp_transactions SET status=?, gateway_response=JSON_SET(COALESCE(gateway_response,'{}'),'$.myfatoorah_event',?,'$.myfatoorah_status',?,'$.invoice_id',?,'$.payment_id',?), updated_at=NOW() WHERE id=?",
            [$normalized, $eventType, $status, $invoiceId, $paymentId, $txn['id']]
        );
        @file_put_contents($logFile,
            '[' . date('Y-m-d H:i:s') . "] Updated txn id={$txn['id']} → {$normalized}\n",
            FILE_APPEND
        );

        // إذا completed → Ledger Settlement
        if ($normalized === 'completed' && ($txn['status'] ?? '') !== 'completed') {
            if (file_exists(__DIR__ . '/../lib/PaymentOrchestrator.php')) {
                require_once __DIR__ . '/../lib/PaymentOrchestrator.php';
                try {
                    PaymentOrchestrator::getInstance()->onPaymentConfirmed((string)$reference, $data);
                } catch (\Throwable $e) {
                    error_log('[MyFatoorah] Orchestrator: ' . $e->getMessage());
                }
            }
        }
    } else {
        // سجّل معاملة جديدة إذا غير موجودة
        $db->execute(
            "INSERT INTO dp_transactions (reference, gateway, amount, currency, status, transaction_type, gateway_response, created_at)
             VALUES (?, 'myfatoorah', ?, 'USD', ?, 'myfatoorah_payment', ?, NOW())",
            [
                $reference,
                (float)($data['Data']['InvoiceValue'] ?? $data['InvoiceValue'] ?? 0),
                $normalized,
                json_encode($data, JSON_UNESCAPED_UNICODE),
            ]
        );
    }
} catch (\Throwable $e) {
    error_log('[MyFatoorah Webhook] DB: ' . $e->getMessage());
}

http_response_code(200);
echo json_encode(['status' => 'ok', 'reference' => $reference, 'normalized' => $normalized]);
