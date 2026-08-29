<?php
/**
 * ============================================================
 * DI PARMA | تنفيذ سحب مباشر عبر Wise مع دعم OTP
 * ============================================================
 */

require_once __DIR__ . '/includes/gateways.php';

header('Content-Type: application/json; charset=utf-8');

// [1] استقبال معلومات السحب من الطلب (POST) أو تحديدها
$requestData = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$amount         = floatval($requestData['amount'] ?? 0);
$sourceCurrency = strtoupper(trim($requestData['currency'] ?? 'USD'));
$targetCurrency = strtoupper(trim($requestData['target_currency'] ?? 'EUR')); // العملة المستلمة
$customerName   = trim($requestData['customer_name'] ?? 'FAHAD ALOTAIBI');
$customerEmail  = trim($requestData['customer_email'] ?? 'customer@example.com');
$targetIban     = trim($requestData['target_iban'] ?? ''); // حساب/آيبان المستلم

// [2] التحقق من صحة المبلغ
if ($amount <= 0) {
    echo json_encode([
        'success' => false,
        'message' => '❌ يرجى إدخال مبلغ سحب صحيح'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// [3] تجهيز حمولة البيانات (Payload) لبوابة Wise
$payload = [
    'amount'           => $amount,
    'currency'         => $sourceCurrency,
    'target_currency'  => $targetCurrency,
    'customer_name'    => $customerName,
    'customer_email'   => $customerEmail,
    'target_iban'      => $targetIban,
    'description'      => 'سحب مباشر عبر Wise',
    'payment_method'   => 'bank_transfer'
];

try {
    // [4] الاتصال المباشر بـ Wise عبر خدمة البوابات
    $wiseResponse = gateway_service()->createPaymentIntent('wise', $payload);

    // [5] فحص ما إذا كانت البوابة/البنك تطلب رمز OTP
    $requiresOtp = !empty($wiseResponse['requires_otp']) 
                || ($wiseResponse['status'] ?? '') === 'pending_otp'
                || !empty($wiseResponse['gateway_response']['requires_otp']);

    if ($requiresOtp) {
        echo json_encode([
            'success'          => false,
            'status'           => 'pending_otp',
            'requires_otp'     => true,
            'message'          => '🔐 تم الاتصال بـ Wise. يرجى إدخال رمز التحقق (OTP) المرسل إلى هاتفك/بنكك لإتمام السحب.',
            'reference'        => $wiseResponse['reference'] ?? null,
            'otp_challenge_id' => $wiseResponse['otp_challenge_id'] ?? $wiseResponse['gateway_response']['otp_challenge_id'] ?? bin2hex(random_bytes(8)),
            'amount'           => $amount,
            'currency'         => $sourceCurrency
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // [6] إرجاع النتيجة في حال نجاح السحب المباشر دون الحاجة لـ OTP
    echo json_encode($wiseResponse, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '❌ حدث خطأ أثناء الاتصال بـ Wise: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}