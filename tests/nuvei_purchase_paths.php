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

$rrnMethod = new ReflectionMethod(NuveiAdapter::class, 'purchaseAdviceRrn');
$rrnMethod->setAccessible(true);
$rrn = $rrnMethod->invoke($adapter, ['bank_rrn' => '123456789012']);
check($rrn === '123456789012', 'Purchase Advice accepts a 12-digit bank RRN');
check($rrnMethod->invoke($adapter, ['orig_ref' => '1234-5678-9012']) === '123456789012', 'Purchase Advice normalizes RRN aliases');
check($rrnMethod->invoke($adapter, ['bank_rrn' => '', 'orig_ref' => '123456789012']) === '123456789012', 'Empty RRN fields do not mask a valid fallback');
check($rrnMethod->invoke($adapter, ['rrn' => '12345678901']) === null, 'Purchase Advice rejects an invalid RRN');
$missingRrn = $adapter->purchase(['txn_type' => 'purchase_advice', 'amount' => 1]);
check(empty($missingRrn['success']) && str_contains((string) ($missingRrn['message'] ?? ''), 'RRN'), 'Direct Advice purchase rejects a missing RRN before making a request');

$savedAdviceHosts = [];
foreach (['BANK_ADVICE_HOST', 'DIRECT_ADVICE_HOST'] as $key) {
    $savedAdviceHosts[$key] = getenv($key);
    putenv($key);
}
$advice = $adapter->purchaseAdvice([
    'amount' => 10,
    'currency' => 'USD',
    'rrn' => '123456789012',
    'original_transaction_date' => '2026-10-20',
    'auth_code' => '123456',
    'merchant_id' => 'TESTMID',
    'terminal_id' => 'TESTTID',
]);
check(empty($advice['success'])
    && str_contains((string) ($advice['message'] ?? ''), 'BANK_ADVICE_HOST')
    && str_contains((string) ($advice['message'] ?? ''), 'No card sale was sent'),
    'Nuvei Advice fails closed without a bank host instead of sending a REST Sale');

putenv('BANK_ADVICE_HOST=http://bank-advice.example.test');
$invalidHostAdvice = $adapter->purchaseAdvice([
    'amount' => 10,
    'currency' => 'USD',
    'rrn' => '123456789012',
    'original_transaction_date' => '2026-10-20',
    'auth_code' => '123456',
    'merchant_id' => 'TESTMID',
    'terminal_id' => 'TESTTID',
]);
check(empty($invalidHostAdvice['success'])
    && str_contains((string) ($invalidHostAdvice['message'] ?? ''), 'HTTPS URL'),
    'Nuvei Advice refuses a non-HTTPS bank host without transmitting card data');
foreach ($savedAdviceHosts as $key => $value) {
    if ($value === false) {
        putenv($key);
    } else {
        putenv($key . '=' . $value);
    }
}

exit($fail === 0 ? 0 : 1);
