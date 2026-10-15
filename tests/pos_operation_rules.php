<?php
/**
 * Live POS rules: capture sign/over-capture, advice RRN+approval, offline approval, auth receipt pair.
 */
require_once __DIR__ . '/../pos/lib/operations.php';
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
$adviceMeta = pos_operation_meta('purchase_advice');
check(!empty($adviceMeta['requires_rrn']) && !empty($adviceMeta['requires_approval']) && empty($adviceMeta['linked_to_auth']) && !empty($adviceMeta['direct_advice']), 'advice stays a direct settlement');

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

$mismatch = pos_bind_capture_pair(['rrn' => '111122223333', 'approval_code' => '654321'], '999988887777', '654321');
check($mismatch['ok'] === false, 'capture rejects a different RRN');
$locked = pos_bind_capture_pair(['rrn' => '111122223333', 'approval_code' => '654321'], '', '');
check($locked['ok'] === true && $locked['rrn'] === '111122223333' && $locked['approval'] === '654321', 'capture refills the stored AUTH pair');
check(pos_host_debit_amount('capture', -10) === 10.0 && pos_host_debit_amount('offline_sale_moto', -10) === 10.0, 'negative capture and offline still debit the absolute amount');

exit($fail === 0 ? 0 : 1);
