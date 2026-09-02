<?php
/**
 * ============================================================
 * DI PARMA | تنفيذ تحويل حي عبر Wise API
 * Profile ID: 68162936
 * ============================================================
 */

require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';

$db = db();

// بيانات الاعتماد والـ Profile الخاصة بك
$token = '3EebcECIYNt1U5ur2LichSENbQv_249LyDshnHLrz1Quq1BCA';
$profileId = 68162936;

// بيانات التحويل (يمكن ربطها بنموذج إدخال متحرك)
$sourceCurrency = 'USD';
$targetCurrency = 'EUR';
$sourceAmount = 100.00;
$targetAccountId = ''; // معرف حساب المستلم - يجب تحديثه من .env
$referenceId = 'DP_LIVE_' . strtoupper(bin2hex(random_bytes(6)));

// الخطوة الأولى: إنشاء تسعيرة التحويل (Quote)
$quoteData = [
    'profile' => $profileId,
    'sourceCurrency' => $sourceCurrency,
    'targetCurrency' => $targetCurrency,
    'sourceAmount' => $sourceAmount
];

$ch = curl_init("https://api.wise.com/v1/quotes"); // استخدم البيئة المناسبة من .env
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($quoteData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Content-Type: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$quoteResult = json_decode($response, true);

if ($httpCode !== 200 && $httpCode !== 201) {
    die("❌ فشل إنشاء التسعيرة من Wise: " . htmlspecialchars($response));
}

$quoteId = $quoteResult['id'];

// الخطوة الثانية: تنفيذ التحويل المباشر (Transfer)
$transferData = [
    'targetAccount' => $targetAccountId,
    'quoteUuid' => $quoteId,
    'customerTransactionId' => $referenceId,
    'details' => [
        'reference' => 'DI PARMA Corporate Payment',
        'sourceOfFunds' => 'VERIFIED_BUSINESS_ACCOUNT'
    ]
];

$ch = curl_init("https://api.wise.com/v1/transfers");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($transferData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Content-Type: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$transferResult = json_decode($response, true);

if ($httpCode === 200 || $httpCode === 201) {
    $transferId = $transferResult['id'];
    $status = $transferResult['status'];

    // تسجيل المعاملة في قاعدة البيانات المحلية لديك
    $db->insert('transactions', [
        'reference' => $referenceId,
        'gateway' => 'wise',
        'amount' => $sourceAmount,
        'currency' => $sourceCurrency,
        'status' => 'pending',
        'mode' => 'LIVE',
        'gateway_response' => json_encode($transferResult),
        'created_at' => date('Y-m-d H:i:s')
    ]);

    echo "✅ تم إرسال طلب التحويل بنجاح عبر Wise!<br>";
    echo "معرف التحويل (Transfer ID): <b>{$transferId}</b><br>";
    echo "مرجع المعاملة (Reference): <b>{$referenceId}</b><br>";
    echo "الحالة الحالية: <b>{$status}</b>";
} else {
    echo "❌ فشل تنفيذ التحويل المباشر: " . htmlspecialchars($response);
}
?>