<?php
require_once __DIR__ . '/../lib/CardScaService.php';
require_once __DIR__ . '/../lib/Adapters/NuveiAdapter.php';
require_once __DIR__ . '/../lib/Adapters/GatewayAdapterFactory.php';
require_once __DIR__ . '/../lib/MySystem/ChargeHub.php';
require_once __DIR__ . '/../pos/lib/POSAdviceHandler.php';

$fail = 0;
function check(bool $ok, string $label): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) {
        $fail++;
    }
}

check(!CardScaService::shouldRecordCompletedChallenge(false, true), 'Approved 2D sale does not mark 3DS completed');
check(!CardScaService::shouldRecordCompletedChallenge(true, false), 'Failed 3DS sale does not mark 3DS completed');
check(CardScaService::shouldRecordCompletedChallenge(true, true), 'Approved challenged 3DS sale marks 3DS completed');

foreach ([
    'APPROVED' => 'completed',
    'SUCCESS' => 'completed',
    'SETTLED' => 'completed',
    'CAPTURED' => 'completed',
    'PENDING' => 'pending',
    'DECLINED' => 'failed',
    'CANCELED' => 'cancelled',
    'REFUNDED' => 'refunded',
    'UNKNOWN_STATUS' => 'pending',
] as $nuveiStatus => $expectedStatus) {
    check(NuveiAdapter::mapTransactionStatus($nuveiStatus) === $expectedStatus,
        'Nuvei ' . $nuveiStatus . ' status maps to ' . $expectedStatus);
}
foreach ([
    ['pending', 'completed', true],
    ['completed', 'pending', false],
    ['completed', 'failed', false],
    ['completed', 'refunded', true],
    ['refunded', 'completed', false],
    ['refunded', 'pending', false],
    ['cancelled', 'pending', false],
    ['cancelled', 'completed', true],
] as [$current, $incoming, $expected]) {
    check(NuveiAdapter::shouldApplyTransactionStatus($current, $incoming) === $expected,
        'Nuvei ' . $current . ' transaction ' . ($expected ? 'accepts' : 'ignores') . ' ' . $incoming . ' update');
}

$negativeAdvice = POSAdviceHandler::buildAdviceRequest([
    'card_number' => '4111111111111111',
    'amount' => -12.34,
    'rrn' => '123456789012',
]);
check(($negativeAdvice['mti'] ?? '') === '0220'
    && ($negativeAdvice['fields']['3'] ?? '') === '200000'
    && ($negativeAdvice['fields']['4'] ?? '') === '000000001234',
    'Negative Purchase Advice is encoded as a 0220 credit');
$manualAdvice = POSAdviceHandler::buildAdviceRequest([
    'card_number' => '4111111111111111',
    'amount' => 12.34,
    'rrn' => '123456789012',
    'entry_mode' => 'keyed',
]);
check(($manualAdvice['fields']['22'] ?? '') === '012', 'Manual Purchase Advice uses keyed entry mode');
$swipeAdvice = POSAdviceHandler::buildAdviceRequest([
    'card_number' => '4111111111111111',
    'amount' => 12.34,
    'rrn' => '123456789012',
    'entry_mode' => 'pos_magstripe',
]);
check(($swipeAdvice['fields']['22'] ?? '') === '901', 'Swiped Purchase Advice uses magstripe entry mode');
check(empty(POSAdviceHandler::handleAdviceResponse('{"response_code":"00"}')['success']),
    'Purchase Advice does not accept a success code without a response MTI');
check(empty(POSAdviceHandler::handleAdviceResponse('{"mti":"0200","response_code":"00"}')['success']),
    'Purchase Advice rejects a non-0230 response MTI');
check(!empty(POSAdviceHandler::handleAdviceResponse('{"mti":"0230","response_code":"00"}')['success']),
    'Purchase Advice accepts an approved 0230 response');

$adapter = new NuveiAdapter();
$savedNuveiApiUrl = getenv('NUVEI_API_URL');
$savedNuveiMerchantId = getenv('NUVEI_MERCHANT_ID');
$savedNuveiSiteId = getenv('NUVEI_SITE_ID');
$savedNuveiSecretKey = getenv('NUVEI_SECRET_KEY');
$baseUrlProperty = new ReflectionProperty(NuveiAdapter::class, 'baseUrl');
$configurationErrorProperty = new ReflectionProperty(NuveiAdapter::class, 'configurationError');
$requestMethod = new ReflectionMethod(NuveiAdapter::class, 'request');

putenv('NUVEI_MERCHANT_ID=TEST-MERCHANT');
putenv('NUVEI_SITE_ID=TEST-SITE');
putenv('NUVEI_SECRET_KEY=TEST-SECRET');
putenv('NUVEI_API_URL=https://ppp-test.nuvei.com/ppp/api/v1');
$sandboxAdapter = new NuveiAdapter();
check($baseUrlProperty->getValue($sandboxAdapter) === 'https://ppp-test.nuvei.com/ppp/api/v1',
    'Nuvei honors an explicitly configured HTTPS Sandbox endpoint');
$gatewayConfiguredAdapter = new NuveiAdapter([
    'merchant_id' => 'GATEWAY-MERCHANT',
    'site_id' => 'GATEWAY-SITE',
    'secret_key' => 'GATEWAY-SECRET',
    'api_endpoint' => 'https://gateway-sandbox.nuvei.test/ppp/api/v1',
]);
check($baseUrlProperty->getValue($gatewayConfiguredAdapter) === 'https://gateway-sandbox.nuvei.test/ppp/api/v1',
    'Nuvei adapter honors gateway-level credentials and endpoint');
$resolvedConfiguredAdapter = GatewayAdapterFactory::resolve('nuvei', [
    'merchant_id' => 'RESOLVED-MERCHANT',
    'site_id' => 'RESOLVED-SITE',
    'secret_key' => 'RESOLVED-SECRET',
    'api_endpoint' => 'https://resolved-sandbox.nuvei.test/ppp/api/v1',
]);
check($baseUrlProperty->getValue($resolvedConfiguredAdapter) === 'https://resolved-sandbox.nuvei.test/ppp/api/v1',
    'Nuvei factory resolve passes explicit credentials and endpoint to the adapter');

putenv('NUVEI_API_URL=http://nuvei.example.test/ppp/api/v1');
$invalidEndpointAdapter = new NuveiAdapter();
$invalidEndpointResult = $requestMethod->invoke($invalidEndpointAdapter, 'getSessionToken', []);
check($configurationErrorProperty->getValue($invalidEndpointAdapter) !== ''
    && ($invalidEndpointResult['status'] ?? '') === 'ERROR'
    && str_contains((string) ($invalidEndpointResult['reason'] ?? ''), 'HTTPS'),
    'Nuvei refuses an insecure configured API URL without sending credentials');
$invalidStatusQuery = $invalidEndpointAdapter->getTransactionDetails('TEST-REFERENCE');
check(($invalidStatusQuery['status'] ?? '') === 'ERROR'
    && str_contains((string) ($invalidStatusQuery['reason'] ?? ''), 'HTTPS'),
    'Nuvei status query refuses an insecure configured API URL');
$anonymousOrder = $invalidEndpointAdapter->openOrder([
    'amount' => 10,
    'currency' => 'USD',
    'email' => 'guest@diparmas.com',
    'reference' => 'TEST-OPEN-ORDER',
    'transactionType' => 'Auth',
]);
check(empty($anonymousOrder['success'])
    && str_contains((string) ($anonymousOrder['message'] ?? ''), 'يتطلب'),
    'Nuvei openOrder rejects anonymous identity before creating an order');

$paymentOptionMethod = new ReflectionMethod(NuveiAdapter::class, 'buildCardPaymentOption');
check($paymentOptionMethod->invoke($invalidEndpointAdapter, ['cloud_token' => 'nuvei-upo-token-123456'])
    === ['card' => ['ccTempToken' => 'nuvei-upo-token-123456']],
    'Nuvei maps a stored token to ccTempToken without requiring PAN data');
check($paymentOptionMethod->invoke($invalidEndpointAdapter, ['payment_token' => '123456789'])
    === ['userPaymentOptionId' => '123456789'],
    'Nuvei maps a numeric stored token to userPaymentOptionId');

$tokenCharge = $invalidEndpointAdapter->charge([
    'txn_type' => 'purchase_2d',
    'amount' => 10,
    'currency' => 'USD',
    'cloud_token' => 'nuvei-upo-token-123456',
]);
check(empty($tokenCharge['success'])
    && str_contains((string) ($tokenCharge['message'] ?? ''), 'Session token failed')
    && ($tokenCharge['error_code'] ?? '') !== 'INVALID_CARD',
    'Nuvei accepts a stored-token charge without PAN or expiry');
$tokenHold = $invalidEndpointAdapter->hold([
    'amount' => 10,
    'currency' => 'USD',
    'payment_token' => 'nuvei-upo-token-123456',
]);
check(empty($tokenHold['success'])
    && str_contains((string) ($tokenHold['message'] ?? ''), 'Session token failed')
    && ($tokenHold['error_code'] ?? '') !== 'INVALID_CARD',
    'Nuvei accepts a stored-token authorization without PAN or expiry');
$offlineSale = $invalidEndpointAdapter->charge([
    'txn_type' => 'offline_sale_moto',
    'processing_mode' => 'OFFLINE',
    'is_offline' => true,
    'amount' => 10,
    'currency' => 'USD',
    'card_number' => '4111111111111111',
    'card_expiry' => '12/30',
]);
check(empty($offlineSale['success'])
    && ($offlineSale['error_code'] ?? '') === 'OFFLINE_NOT_SUPPORTED',
    'Nuvei offline sale fails closed without attempting REST authorization');
$offlineSaleAlias = $invalidEndpointAdapter->charge([
    'txn_type' => 'offline_sales_moto',
    'amount' => 10,
    'currency' => 'USD',
    'card_number' => '4111111111111111',
    'card_expiry' => '12/30',
]);
check(empty($offlineSaleAlias['success'])
    && ($offlineSaleAlias['error_code'] ?? '') === 'OFFLINE_NOT_SUPPORTED',
    'Nuvei offline sale alias also fails closed');
$offlineHold = $invalidEndpointAdapter->hold([
    'txn_type' => 'auth',
    'auth_channel' => 'offline',
    'processing_mode' => 'OFFLINE',
    'amount' => 10,
    'currency' => 'USD',
    'payment_token' => 'nuvei-upo-token-123456',
]);
check(empty($offlineHold['success'])
    && ($offlineHold['error_code'] ?? '') === 'OFFLINE_NOT_SUPPORTED',
    'Nuvei offline authorization fails closed without attempting REST authorization');
$nestedOfflineHold = $invalidEndpointAdapter->hold([
    'txn_type' => 'auth',
    'auth_channel' => 'online',
    'extra' => ['auth_channel' => 'offline'],
    'amount' => 10,
    'currency' => 'USD',
    'payment_token' => 'nuvei-upo-token-123456',
]);
check(empty($nestedOfflineHold['success'])
    && ($nestedOfflineHold['error_code'] ?? '') === 'OFFLINE_NOT_SUPPORTED',
    'Nuvei offline authorization is rejected when flagged in nested extra data');
$invalidCard = $invalidEndpointAdapter->charge([
    'txn_type' => 'purchase_2d',
    'amount' => 10,
    'currency' => 'USD',
    'card_number' => '4111111111111111',
]);
check(($invalidCard['error_code'] ?? '') === 'INVALID_CARD',
    'Nuvei still requires expiry when charging with a PAN');

if ($savedNuveiApiUrl === false) {
    putenv('NUVEI_API_URL');
} else {
    putenv('NUVEI_API_URL=' . $savedNuveiApiUrl);
}
foreach ([
    'NUVEI_MERCHANT_ID' => $savedNuveiMerchantId,
    'NUVEI_SITE_ID' => $savedNuveiSiteId,
    'NUVEI_SECRET_KEY' => $savedNuveiSecretKey,
] as $key => $value) {
    if ($value === false) {
        putenv($key);
    } else {
        putenv($key . '=' . $value);
    }
}

$nuveiIsLive = DiParmaChargeHub::supports('nuvei');
if (!$nuveiIsLive) {
    check(!DiParmaChargeHub::canCharge('nuvei'),
        'Disabled Nuvei is not reported as chargeable through adapter fallback');
    $disabledCharge = DiParmaChargeHub::charge('nuvei', 'purchase_2d', ['amount' => 10]);
    check(empty($disabledCharge['success'])
        && str_contains((string) ($disabledCharge['message'] ?? ''), 'not active for POS charges'),
        'Disabled Nuvei cannot bypass POS activation through adapter fallback');
}

$adviceAdapter = new NuveiAdapter();
$merchantIdProperty = new ReflectionProperty(NuveiAdapter::class, 'merchantId');
$merchantIdProperty->setValue($adviceAdapter, '');
$missingAdviceTxn = $adviceAdapter->purchaseAdvice([
    'amount' => 10,
    'auth_code' => '123456',
    'original_transaction_date' => '2026-10-21',
]);
check(empty($missingAdviceTxn['success'])
    && str_contains((string) ($missingAdviceTxn['message'] ?? ''), '12-digit bank RRN'),
    'Nuvei Purchase Advice requires the original bank RRN');
$missingAdviceDate = $adviceAdapter->purchaseAdvice([
    'amount' => 10,
    'rrn' => '123456789012',
    'auth_code' => '123456',
]);
check(empty($missingAdviceDate['success'])
    && str_contains((string) ($missingAdviceDate['message'] ?? ''), 'valid original transaction date'),
    'Nuvei Purchase Advice requires the original transaction date');
$missingManualCvv = $adviceAdapter->purchaseAdvice([
    'amount' => 10,
    'rrn' => '123456789012',
    'auth_code' => '123456',
    'original_transaction_date' => '2026-10-21',
    'input_mode' => 'manual',
    'card_number' => '4000000000000002',
    'card_expiry' => '12/30',
]);
check(empty($missingManualCvv['success'])
    && str_contains((string) ($missingManualCvv['message'] ?? ''), 'requires PAN, expiry, and a 3-4 digit CVV'),
    'Nuvei manual Advice requires PAN, expiry, and CVV');
$physicalAdvice = $adviceAdapter->purchaseAdvice([
    'amount' => 10,
    'rrn' => '123456789012',
    'auth_code' => '123456',
    'original_transaction_date' => '2026-10-21',
    'input_mode' => 'physical',
]);
check(empty($physicalAdvice['success'])
    && str_contains((string) ($physicalAdvice['message'] ?? ''), 'MID و TID مطلوبان'),
    'Nuvei physical Advice needs no manually entered PAN, expiry, or CVV');
$purchaseAdviceWithoutRrn = $adviceAdapter->purchase([
    'txn_type' => 'purchase_advice',
    'amount' => 10,
]);
check(empty($purchaseAdviceWithoutRrn['success'])
    && str_contains((string) ($purchaseAdviceWithoutRrn['message'] ?? ''), '12-digit bank RRN'),
    'Nuvei purchase() routes Advice through RRN validation');

exit($fail === 0 ? 0 : 1);
