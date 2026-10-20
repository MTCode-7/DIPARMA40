<?php
require_once __DIR__ . '/../lib/CardScaService.php';
require_once __DIR__ . '/../lib/Adapters/NuveiAdapter.php';
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
