<?php
/**
 * CLI check for the DIPARMA ISO 8583 path.
 * Prints MTI, bitmap, and fields with PAN masked. Does not approve a payment.
 */
require_once __DIR__ . '/DiparmaIsoProcessor.php';

$pan = '4111111111111111';
$common = [
    'pan' => $pan,
    'card_number' => $pan,
    'card_expiry' => '12/28',
    'card_cvv' => '123',
    'currency' => 'USD',
    'terminal_id' => 'TID10001',
    'merchant_id' => 'MID100000000001',
    'stan' => '100001',
    'transmission' => '1011120000',
];

$cases = [
    'auth' => array_merge($common, [
        'amount' => 25.50,
    ]),
    'capture' => array_merge($common, [
        'amount' => 30.00,
        'rrn' => '123456789012',
        'approval_code' => '4821',
    ]),
    'purchase_advice' => array_merge($common, [
        'amount' => -12.34,
        'stan' => '100002',
    ]),
    'reversal' => array_merge($common, [
        'amount' => 25.50,
        'rrn' => '123456789012',
        'stan' => '100003',
    ]),
];

$failed = false;
foreach ($cases as $txnType => $fields) {
    $built = DiparmaIsoProcessor::buildIso8583($txnType, $fields);
    $public = DiparmaIsoProcessor::handle($txnType, $fields);
    $encoded = json_encode($public, JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded)) {
        fwrite(STDERR, "encode failed for {$txnType}\n");
        $failed = true;
        continue;
    }
    if (str_contains($encoded, $pan) || str_contains($encoded, '"cvv"') || str_contains($encoded, '"card_cvv"')) {
        fwrite(STDERR, "sensitive data leaked for {$txnType}\n");
        $failed = true;
    }
    $packed = (string) ($built['packed'] ?? '');
    unset($built['packed']);
    if ($packed !== '' && !str_starts_with($packed, (string) $built['mti'])) {
        fwrite(STDERR, "packed MTI mismatch for {$txnType}\n");
        $failed = true;
    }
    $fieldList = [];
    foreach ((array) ($built['fields'] ?? []) as $bit => $value) {
        $fieldList[] = $bit . '=' . $value;
    }
    echo strtoupper($txnType)
        . ' MTI=' . ($built['mti'] ?? '')
        . ' bitmap=' . ($built['bitmap'] ?? '')
        . ' secondary=' . (!empty($built['secondary_bitmap']) ? 'yes' : 'no')
        . ' fields: ' . implode(' ', $fieldList)
        . PHP_EOL;
    echo '  public card=' . ($public['card_mask'] ?? '') . ' last4=' . ($public['card_last4'] ?? '')
        . ' response_code=' . var_export($public['response_code'] ?? null, true)
        . ' approved=' . (!empty($public['approved']) ? 'yes' : 'no')
        . PHP_EOL;
}

$sample = ISO8583AdviceProcessor::buildISOFrame('0210', [
    37 => '123456789012',
    38 => '4821',
    39 => '00',
]);
$parsed = DiparmaIsoProcessor::parseResponse($sample);
echo 'PARSE MTI=' . ($parsed['mti'] ?? '')
    . ' DE39=' . var_export($parsed['response_code'] ?? null, true)
    . ' DE37=' . ($parsed['rrn'] ?? '')
    . ' DE38=' . ($parsed['approval_code'] ?? '')
    . PHP_EOL;

if (($parsed['response_code'] ?? null) !== '00' || ($parsed['rrn'] ?? '') !== '123456789012') {
    fwrite(STDERR, "parser did not extract DE39/DE37\n");
    $failed = true;
}

exit($failed ? 1 : 0);
