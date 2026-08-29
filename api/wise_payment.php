<?php
/**
 * ============================================================
 * DI PARMA | Wise Direct Payment — بدون redirect
 * ============================================================
 */

// JSON header أولاً قبل أي require قد يُحدث redirect
header('Content-Type: application/json; charset=utf-8');

// تحقق من session يدوياً بدل auth_check (لمنع redirect HTML)
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/gateways.php';
require_once __DIR__ . '/../lib/WiseService.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح — سجّل دخولك أولاً']);
    exit;
}

// CSRF
$input = json_decode(file_get_contents('php://input'), true) ?? [];
if (!verifyCsrfToken($input['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'CSRF غير صالح']);
    exit;
}

$action = $_GET['action'] ?? $input['action'] ?? '';

try {
    $wise = WiseService::fromConfig();

    // ══════════════════════════════════════════════════════
    // [1] الحصول على Quote (السعر والرسوم)
    // ══════════════════════════════════════════════════════
    if ($action === 'quote') {
        $amount         = floatval($input['amount']          ?? 0);
        $sourceCurrency = strtoupper($input['source_currency'] ?? 'USD');
        $targetCurrency = strtoupper($input['target_currency'] ?? 'USD');

        if ($amount <= 0) {
            echo json_encode(['success' => false, 'message' => 'المبلغ غير صالح']);
            exit;
        }

        $quote = $wise->createQuote($amount, $sourceCurrency, $targetCurrency);

        if (empty($quote['id'])) {
            echo json_encode(['success' => false, 'message' => $quote['errors'][0]['message'] ?? 'فشل إنشاء Quote']);
            exit;
        }

        echo json_encode([
            'success'         => true,
            'quote_id'        => $quote['id'],
            'source_amount'   => $quote['sourceAmount']  ?? $amount,
            'target_amount'   => $quote['targetAmount']  ?? 0,
            'source_currency' => $quote['sourceCurrency'] ?? $sourceCurrency,
            'target_currency' => $quote['targetCurrency'] ?? $targetCurrency,
            'fee'             => $quote['fee'] ?? 0,
            'rate'            => $quote['rate'] ?? 1,
        ]);
        exit;
    }

    // ══════════════════════════════════════════════════════
    // [2] تنفيذ التحويل المباشر
    // ══════════════════════════════════════════════════════
    if ($action === 'transfer') {
        $amount           = floatval($input['amount']          ?? 0);
        $sourceCurrency   = strtoupper($input['source_currency'] ?? 'USD');
        $targetCurrency   = strtoupper($input['target_currency'] ?? 'USD');
        $recipientName    = trim($input['recipient_name']    ?? '');
        $recipientEmail   = trim($input['recipient_email']   ?? '');
        $iban             = trim($input['iban']              ?? '');
        $swift            = trim($input['swift']             ?? '');
        $accountNumber    = trim($input['account_number']    ?? '');
        $routingNumber    = trim($input['routing_number']    ?? '');
        $reference        = trim($input['reference']         ?? ('WISE_' . time()));
        $country          = strtoupper($input['country']     ?? 'AE');

        if ($amount <= 0)       { echo json_encode(['success'=>false,'message'=>'المبلغ غير صالح']); exit; }
        if (!$recipientName)    { echo json_encode(['success'=>false,'message'=>'اسم المستلم مطلوب']); exit; }

        // [1] Quote
        $quote = $wise->createQuote($amount, $sourceCurrency, $targetCurrency);
        if (empty($quote['id'])) {
            echo json_encode(['success'=>false,'message'=>'فشل إنشاء Quote: '.($quote['errors'][0]['message']??json_encode($quote))]);
            exit;
        }

        // [2] إنشاء Recipient
        $details = [];
        $type    = 'swift_code';

        if ($iban) {
            $type              = 'iban';
            $details['iban']   = preg_replace('/\s+/', '', $iban);
        } elseif ($accountNumber && $routingNumber) {
            $type                       = 'aba';
            $details['abartn']          = $routingNumber;
            $details['accountNumber']   = $accountNumber;
        } elseif ($accountNumber && $swift) {
            $type                       = 'swift_code';
            $details['swiftCode']       = $swift;
            $details['accountNumber']   = $accountNumber;
        } else {
            echo json_encode(['success'=>false,'message'=>'أدخل IBAN أو رقم الحساب + SWIFT/Routing']);
            exit;
        }

        $recipient = $wise->createRecipient($targetCurrency, $type, $details, $recipientName, $country);
        if (empty($recipient['id'])) {
            echo json_encode(['success'=>false,'message'=>'فشل إنشاء المستلم: '.($recipient['errors'][0]['message']??json_encode($recipient))]);
            exit;
        }

        // [3] Transfer
        $transfer = $wise->createTransfer(
            $quote['id'],
            $recipient['id'],
            $reference,
            'DI PARMA Payment'
        );
        if (empty($transfer['id'])) {
            echo json_encode(['success'=>false,'message'=>'فشل إنشاء التحويل: '.($transfer['errors'][0]['message']??json_encode($transfer))]);
            exit;
        }

        // [4] Fund (تمويل التحويل من رصيد Wise)
        $fund = $wise->fundTransfer($transfer['id']);

        // حفظ في DB
        $db = db();
        try {
            $db->insert('transactions', [
                'user_id'          => intval($_SESSION['user_id']),
                'reference'        => $reference,
                'type'             => 'wise_transfer',
                'amount'           => $amount,
                'currency'         => $sourceCurrency,
                'status'           => isset($fund['status']) && $fund['status'] === 'COMPLETED' ? 'completed' : 'pending',
                'gateway'          => 'wise',
                'gateway_response' => json_encode([
                    'transfer_id'  => $transfer['id'],
                    'quote_id'     => $quote['id'],
                    'recipient_id' => $recipient['id'],
                    'fund'         => $fund,
                ]),
                'created_at'       => date('Y-m-d H:i:s'),
            ]);
        } catch (Exception $ignored) {}

        echo json_encode([
            'success'       => true,
            'message'       => '✅ تم إرسال التحويل عبر Wise',
            'transfer_id'   => $transfer['id'],
            'reference'     => $reference,
            'amount'        => $quote['sourceAmount']  ?? $amount,
            'target_amount' => $quote['targetAmount']  ?? 0,
            'source_currency' => $sourceCurrency,
            'target_currency' => $targetCurrency,
            'status'        => $fund['status'] ?? 'PROCESSING',
            'fee'           => $quote['fee']   ?? 0,
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'action غير معروف']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
