<?php
/**
 * ============================================================
 * DI PARMA | تأكيد رمز الـ OTP لإتمام سحب Wise
 * ============================================================
 */

require_once __DIR__ . '/includes/gateways.php';

header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$otpCode        = trim($input['otp_code'] ?? '');
$reference      = trim($input['reference'] ?? '');
$otpChallengeId = trim($input['otp_challenge_id'] ?? '');

if (empty($otpCode)) {
    echo json_encode([
        'success' => false,
        'message' => '❌ يرجى إدخال رمز OTP'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// تجهيز طلب التأكيد لـ Wise
$confirmPayload = [
    'action'           => 'CONFIRM_OTP',
    'otp_code'         => $otpCode,
    'reference'        => $reference,
    'otp_challenge_id' => $otpChallengeId
];

try {
    // إعادة إرسال الرمز للتحقق والتأكيد المباشر
    $finalResult = gateway_service()->createPaymentIntent('wise', $confirmPayload);

    echo json_encode([
        'success'     => true,
        'status'      => 'completed',
        'message'     => '✅ تم تأكيد رمز OTP وبدء تحويل/سحب الأموال بنجاح عبر Wise.',
        'reference'   => $reference,
        'otp_status'  => 'verified',
        'wise_result' => $finalResult
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '❌ فشل التحقق من رمز OTP: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}