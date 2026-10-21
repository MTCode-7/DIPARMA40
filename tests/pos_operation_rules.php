<?php
/**
 * Live POS rules: capture sign/over-capture, advice RRN+approval, offline approval, auth receipt pair.
 */
require_once __DIR__ . '/../pos/lib/operations.php';
require_once __DIR__ . '/../pos/lib/gateways.php';
require_once __DIR__ . '/../pos/lib/DirectAdvicePOSProcessor.php';
require_once __DIR__ . '/../pos/lib/RealOfflineSalesManager.php';

$fail = 0;
function check(bool $ok, string $label): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) {
        $fail++;
    }
}

$card = [
    'cloud_token' => 'pm_live_token_12345678',
    'card_expiry' => '12/30',
];

foreach ([-10.0, 50.0, 65.0] as $amount) {
    $errors = pos_amount_policy_errors('capture', $amount);
    check($errors === [], 'capture ' . $amount . ' is not rejected for sign or over-capture');
}
$overCap = pos_amount_policy_errors('capture', -5000000.01);
check($overCap !== [] && !preg_match('/hold/i', implode(' ', $overCap)), 'capture absolute ceiling still applies');
check(pos_amount_policy_errors('purchase_2d', -10) !== [], 'other sales still reject a negative amount');

$adviceNeg = pos_amount_policy_errors('purchase_advice', -10);
check($adviceNeg === [], 'advice accepts a negative amount');
$adviceMissingRrn = pos_validate_operation_fields('purchase_advice', $card + ['approval_code' => '123456']);
check(in_array('RRN must be exactly 12 digits.', $adviceMissingRrn, true), 'advice requires RRN');
$adviceMissingAp = pos_validate_operation_fields('purchase_advice', $card + ['rrn' => '123456789012']);
check($adviceMissingAp !== [] && !in_array('RRN must be exactly 12 digits.', $adviceMissingAp, true), 'advice requires approval');
$adviceOk = pos_validate_operation_fields('purchase_advice', $card + ['rrn' => '123456789012', 'approval_code' => '123456']);
check($adviceOk === [], 'advice with RRN and approval passes');
check(pos_host_advice_amount('nuvei', -40.0) === -40.0, 'Nuvei Advice preserves a negative credit amount for ISO processing');
check(pos_host_advice_amount('stripe', -40.0) === 40.0, 'other Advice gateways retain positive host debit amounts');
check(pos_nuvei_physical_advice_data_error('physical', 'LIVE', '', '') !== null,
    'physical Nuvei Advice cannot submit without card data from the reader');
check(pos_nuvei_physical_advice_data_error('physical', 'LIVE', '4111111111111111', '12/30') === null,
    'physical Nuvei Advice accepts card data from a magstripe reader');
check(pos_nuvei_physical_advice_data_error('manual', 'LIVE', '', '') === null,
    'physical reader data guard does not affect Manual entry');
$adviceMeta = pos_operation_meta('purchase_advice');
check(!empty($adviceMeta['requires_rrn']) && !empty($adviceMeta['requires_approval']) && !empty($adviceMeta['linked_to_auth']) && !empty($adviceMeta['direct_advice']), 'advice links to a prior authorization using external references, without requiring a local hold');
check(($adviceMeta['ar'] ?? '') === 'PURCHASE ADVICE' && ($adviceMeta['en'] ?? '') === 'PURCHASE ADVICE', 'advice label does not misidentify card-present transactions as MOTO');
check(($adviceMeta['auth_type'] ?? '') === 'PURCHASE_ADVICE_AFTER_AUTH', 'advice is not described as an independent sale');
$swipedAdvice = pos_prepare_operation_payload('purchase_advice', [
    'gateway' => 'nuvei',
    'input_mode' => 'physical',
    'entry_mode' => 'pos_magstripe',
]);
check(empty($swipedAdvice['is_moto']) && !empty($swipedAdvice['card_present']), 'swiped advice is treated as card present, not keyed MOTO');
$keyedAdvice = pos_prepare_operation_payload('purchase_advice', [
    'gateway' => 'nuvei',
    'input_mode' => 'manual',
    'entry_mode' => 'keyed',
]);
check(!empty($keyedAdvice['is_moto']) && empty($keyedAdvice['card_present']), 'manual advice stays keyed MOTO');
$nuveiPhysicalAdvice = pos_prepare_operation_payload('purchase_advice', [
    'gateway' => 'nuvei',
    'input_mode' => 'physical',
    'entry_mode' => 'pos_magstripe',
    'card_cvv' => '123',
]);
check(empty($nuveiPhysicalAdvice['is_moto'])
    && !empty($nuveiPhysicalAdvice['card_present'])
    && !empty($nuveiPhysicalAdvice['direct_advice'])
    && empty($nuveiPhysicalAdvice['card_cvv'])
    && ($nuveiPhysicalAdvice['entry_mode'] ?? '') === 'pos_magstripe',
    'Nuvei physical Advice uses card-present ISO Advice');
$nuveiManualAdvice = pos_prepare_operation_payload('purchase_advice', [
    'gateway' => 'nuvei',
    'input_mode' => 'manual',
    'entry_mode' => 'keyed',
    'card_cvv' => '123',
]);
check(!empty($nuveiManualAdvice['is_moto'])
    && empty($nuveiManualAdvice['card_present'])
    && !empty($nuveiManualAdvice['direct_advice'])
    && ($nuveiManualAdvice['card_cvv'] ?? '') === '123',
    'Nuvei manual Advice keeps CVV while using keyed ISO Advice');
$physicalAdviceFields = pos_validate_operation_fields('purchase_advice', [
    'rrn' => '123456789012',
    'approval_code' => '123456',
    'input_mode' => 'physical',
], ['input_mode' => 'physical']);
check($physicalAdviceFields === [], 'physical Advice requires references but no manual card fields');
$manualAdviceFields = pos_validate_operation_fields('purchase_advice', [
    'rrn' => '123456789012',
    'approval_code' => '123456',
    'card_number' => '4111111111111111',
    'card_expiry' => '12/30',
    'card_cvv' => '',
], ['requires_cvv' => true]);
check(in_array('Invalid CVV. Must be 3-4 digits.', $manualAdviceFields, true),
    'manual Nuvei Advice requires a CVV');

$offlineMissing = pos_validate_operation_fields('offline_sale_moto', $card);
check($offlineMissing !== [] && !in_array('RRN must be exactly 12 digits.', $offlineMissing, true), 'offline requires approval and not RRN');
$offlineOk = pos_validate_operation_fields('offline_sales_moto', $card + ['approval_code' => '654321']);
check($offlineOk === [], 'offline alias accepts approval without RRN');
check(pos_amount_policy_errors('offline_sale_moto', -10) === [], 'offline accepts a negative amount');
check(pos_amount_policy_errors('offline_sale_moto', -2000000.01) !== [], 'offline absolute ceiling still applies');

$kept = pos_auth_receipt_pair(['rrn' => '111122223333', 'approval_code' => '654321'], '000001', 'POS-KEEP');
check($kept['rrn'] === '111122223333' && $kept['approval_code'] === '654321', 'auth receipt keeps host RRN and approval');
$made = pos_auth_receipt_pair([], '123456', 'POS-MAKE');
check(pos_is_valid_rrn($made['rrn']) && pos_is_valid_approval($made['approval_code'], null) && $made['rrn'] !== '—' && $made['approval_code'] !== '—', 'auth receipt generates both fields when the host omits them');
$nuveiMissing = pos_auth_receipt_pair([], '123456', 'POS-NUVEI', false);
check($nuveiMissing['rrn'] === '' && $nuveiMissing['approval_code'] === '', 'Nuvei AUTH leaves provider-missing receipt identifiers empty');
$nuveiKept = pos_auth_receipt_pair(['rrn' => '111122223333', 'approval_code' => '654321'], '000001', 'POS-NUVEI', false);
check($nuveiKept['rrn'] === '111122223333' && $nuveiKept['approval_code'] === '654321', 'Nuvei AUTH retains identifiers supplied by the provider');

$mismatch = pos_bind_capture_pair(['rrn' => '111122223333', 'approval_code' => '654321'], '999988887777', '654321');
check($mismatch['ok'] === false, 'capture rejects a different RRN');
$locked = pos_bind_capture_pair(['rrn' => '111122223333', 'approval_code' => '654321'], '', '');
check($locked['ok'] === true && $locked['rrn'] === '111122223333' && $locked['approval'] === '654321', 'capture refills the stored AUTH pair');
check(pos_host_debit_amount('capture', -10) === 10.0 && pos_host_debit_amount('offline_sale_moto', -10) === 10.0, 'negative capture and offline still debit the absolute amount');

foreach (['purchase_2d', 'online_sale_moto', 'purchase_advice', 'offline_sale_moto', 'purchase_3d', 'auth'] as $nuveiType) {
    check(pos_nuvei_large_usd_sale('nuvei', $nuveiType, 150.0, 'USD') === false, 'nuvei does not force 3DS on ' . $nuveiType);
}
check(pos_keyed_entry_forces_offline('purchase_2d') === false, 'keyed 2D is not forced offline');
check(pos_keyed_entry_forces_offline('online_sale_moto') === false, 'keyed online sale is not forced offline');
check(pos_keyed_entry_forces_offline('purchase_advice') === false, 'advice is not forced offline');
check(pos_keyed_entry_forces_offline('offline_sale_moto') === true, 'explicit offline stays offline');
check(pos_nuvei_offline_operation_requested('nuvei', 'offline_sale_moto') === true, 'Nuvei SAF sale is identified for fail-closed handling');
check(pos_nuvei_offline_operation_requested('nuvei', 'auth', ['auth_channel' => 'offline']) === true, 'Nuvei offline authorization is identified for fail-closed handling');
check(pos_nuvei_offline_operation_requested('nuvei', 'auth', ['auth_channel' => 'online', 'extra' => ['auth_channel' => 'offline']]) === true, 'Nuvei offline authorization is detected in nested extra data');
check(pos_nuvei_offline_operation_requested('nuvei', 'purchase_2d') === false, 'Nuvei online 2D operation remains available');
check(pos_nuvei_offline_operation_requested('stripe', 'offline_sale_moto') === false, 'Other gateway offline routing is unchanged');
check(pos_paypal_charge_mode('purchase_2d', 'OFFLINE', false) === '2D', 'PayPal sends keyed 2D when offline mode is off');
check(pos_paypal_charge_mode('purchase_advice', 'OFFLINE', false) === 'MOTO', 'PayPal sends advice when offline mode is off');
check(pos_paypal_charge_mode('offline_sale_moto', 'OFFLINE', false) === 'OFFLINE', 'explicit PayPal offline stays on the offline path');
check(pos_amount_policy_errors('capture', 0.0) !== [], 'zero capture stays rejected');
check(pos_amount_policy_errors('purchase_advice', 0.0) !== [], 'zero advice stays rejected');
check(pos_amount_policy_errors('offline_sale_moto', 0.0) !== [], 'zero offline stays rejected');
check(pos_is_blocked_test_card('4111111111111111'), 'test card fixture stays blocked');
$blocked = pos_validate_operation_fields('purchase_2d', [
    'card_number' => '4111111111111111',
    'card_expiry' => '12/30',
]);
check(in_array('Test and dummy cards are blocked. Use a real card.', $blocked, true), 'validator still blocks the test card');

$released2d = pos_release_forced_offline('purchase_2d', [
    'is_offline' => true,
    'processing_mode' => 'OFFLINE',
    'moto_channel' => 'offline',
    'approval_code' => '123456',
]);
check(empty($released2d['is_offline']) && strtoupper((string) $released2d['processing_mode']) !== 'OFFLINE', 'forced offline flag is dropped from a 2D sale');
$releasedAdvice = pos_release_forced_offline('purchase_advice', [
    'is_offline' => true,
    'processing_mode' => 'OFFLINE',
    'rrn' => '123456789012',
    'approval_code' => '123456',
]);
check(empty($releasedAdvice['is_offline']) && ($releasedAdvice['approval_code'] ?? '') === '123456' && ($releasedAdvice['rrn'] ?? '') === '123456789012', 'advice keeps RRN and approval and is not held offline');
$releasedOffline = pos_release_forced_offline('offline_sale_moto', [
    'approval_code' => '654321',
    'rrn' => '123456789012',
]);
check(!empty($releasedOffline['is_offline']) && ($releasedOffline['approval_code'] ?? '') === '654321', 'offline stays offline and keeps the approval code');

$traces = [
    'auth' => $card + ['amount' => 25.0],
    'capture' => $card + ['rrn' => '123456789012', 'approval_code' => '654321', 'amount' => 80.0],
    'purchase_advice' => $card + ['rrn' => '123456789012', 'approval_code' => '123456', 'amount' => -40.0],
    'offline_sale_moto' => $card + ['approval_code' => '654321', 'amount' => -15.0],
];
foreach ($traces as $type => $payload) {
    $fieldErrors = pos_validate_operation_fields($type, $payload);
    $amountErrors = pos_amount_policy_errors($type, (float) $payload['amount']);
    $local = array_merge($fieldErrors, $amountErrors);
    check($local === [], 'trace ' . $type . ' is not rejected locally');
    echo 'TRACE ' . $type . ' amount=' . $payload['amount'] . ' local=' . ($local === [] ? 'PASS' : implode(' | ', $local)) . PHP_EOL;
}

exit($fail === 0 ? 0 : 1);
