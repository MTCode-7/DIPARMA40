<?php
/**
 * CLI check for the DIPARMA ISO 8583 path.
 * Prints MTI, bitmap, and fields with PAN masked. Does not approve a payment.
 */
require_once __DIR__ . '/DiparmaIsoProcessor.php';

$visa = '4111111111111111';
$mastercard = '5555555555554444';
$cvv = '737';
$failed = false;

$common = [
    'card_expiry' => '12/28',
    'card_cvv' => $cvv,
    'currency' => 'USD',
    'terminal_id' => 'TID10001',
    'merchant_id' => 'MID100000000001',
    'stan' => '100001',
    'transmission' => '1011120000',
];

$cases = [
    'visa_auth' => [
        'type' => 'auth',
        'pan' => $visa,
        'expect_mti' => '0100',
        'expect_scheme' => 'VISN',
        'fields' => ['amount' => 25.50],
    ],
    'mc_purchase' => [
        'type' => 'purchase',
        'pan' => $mastercard,
        'expect_mti' => '0200',
        'expect_scheme' => 'MCRD',
        'fields' => ['amount' => 18.00, 'stan' => '100010'],
    ],
    'visa_capture' => [
        'type' => 'capture',
        'pan' => $visa,
        'expect_mti' => '0220',
        'expect_scheme' => 'VISN',
        'fields' => [
            'amount' => 30.00,
            'hold_amount' => 25.50,
            'rrn' => '123456789012',
            'approval_code' => '4821',
            'stan' => '100011',
        ],
    ],
    'mc_advice' => [
        'type' => 'purchase_advice',
        'pan' => $mastercard,
        'expect_mti' => '0220',
        'expect_scheme' => 'MCRD',
        'fields' => ['amount' => -12.34, 'stan' => '100002'],
    ],
    'visa_reversal' => [
        'type' => 'reversal',
        'pan' => $visa,
        'expect_mti' => '0400',
        'expect_scheme' => 'VISN',
        'fields' => [
            'amount' => 25.50,
            'rrn' => '123456789012',
            'approval_code' => '482196',
            'stan' => '100003',
        ],
    ],
];

$schemes = [];
foreach ($cases as $name => $case) {
    $fields = array_merge($common, $case['fields'], [
        'pan' => $case['pan'],
        'card_number' => $case['pan'],
    ]);
    $built = DiparmaIsoProcessor::buildIso8583($case['type'], $fields);
    $public = DiparmaIsoProcessor::handle($case['type'], $fields);
    $encoded = json_encode($public, JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded)) {
        fwrite(STDERR, "encode failed for {$name}\n");
        $failed = true;
        continue;
    }
    if (str_contains($encoded, $visa) || str_contains($encoded, $mastercard) || str_contains($encoded, $cvv)) {
        fwrite(STDERR, "sensitive data leaked for {$name}\n");
        $failed = true;
    }
    if (!empty($public['approved']) && ($public['response_code'] ?? '') !== '00') {
        fwrite(STDERR, "local approval without DE39 00 for {$name}\n");
        $failed = true;
    }
    $packed = (string) ($built['packed'] ?? '');
    unset($built['packed']);
    if ($packed !== '' && !str_starts_with($packed, (string) ($built['mti'] ?? ''))) {
        fwrite(STDERR, "packed MTI mismatch for {$name}\n");
        $failed = true;
    }
    $mti = (string) ($built['mti'] ?? '');
    $scheme = (string) ($built['fields']['48'] ?? '');
    $schemes[$name] = $scheme;
    if ($mti !== $case['expect_mti'] || $scheme !== $case['expect_scheme']) {
        fwrite(STDERR, "{$name} expected MTI {$case['expect_mti']} {$case['expect_scheme']}, got {$mti} {$scheme}\n");
        $failed = true;
    }
    $fieldList = [];
    foreach ((array) ($built['fields'] ?? []) as $bit => $value) {
        $fieldList[] = $bit . '=' . $value;
    }
    echo strtoupper($name)
        . ' MTI=' . $mti
        . ' scheme=' . $scheme
        . ' brand=' . ($built['brand'] ?? '')
        . ' bitmap=' . ($built['bitmap'] ?? '')
        . ' fields: ' . implode(' ', $fieldList)
        . PHP_EOL;
    echo '  public card=' . ($public['card_mask'] ?? '')
        . ' last4=' . ($public['card_last4'] ?? '')
        . ' response_code=' . var_export($public['response_code'] ?? null, true)
        . ' approved=' . (!empty($public['approved']) ? 'yes' : 'no')
        . ' transport=' . ($public['transport'] ?? '')
        . PHP_EOL;
}

if (($schemes['visa_auth'] ?? '') === ($schemes['mc_purchase'] ?? '') || ($schemes['visa_auth'] ?? '') === '') {
    fwrite(STDERR, "Visa and Mastercard scheme markers do not differ\n");
    $failed = true;
}

$capture = DiparmaIsoProcessor::buildIso8583('capture', array_merge($common, [
    'pan' => $visa,
    'card_number' => $visa,
    'amount' => 30.00,
    'hold_amount' => 25.50,
    'rrn' => '123456789012',
    'approval_code' => '4821',
]));
if (($capture['fields']['4'] ?? '') !== '000000003000' || ($capture['fields']['37'] ?? '') !== '123456789012' || ($capture['fields']['38'] ?? '') !== '4821') {
    fwrite(STDERR, "capture did not keep the higher amount with RRN and approval\n");
    $failed = true;
}
unset($capture['packed']);

$advice = DiparmaIsoProcessor::buildIso8583('purchase_advice', array_merge($common, [
    'pan' => $mastercard,
    'card_number' => $mastercard,
    'amount' => -12.34,
]));
if (($advice['mti'] ?? '') !== '0220' || ($advice['fields']['3'] ?? '') !== '200000' || ($advice['fields']['4'] ?? '') !== '000000001234') {
    fwrite(STDERR, "negative purchase advice was not a 0220 credit\n");
    $failed = true;
}
unset($advice['packed']);

$refund = DiparmaIsoProcessor::buildIso8583('refund', array_merge($common, [
    'pan' => $visa,
    'card_number' => $visa,
    'amount' => 20,
    'rrn' => '123456789012',
]));
if (($refund['mti'] ?? '') !== '0200' || ($refund['fields']['3'] ?? '') !== '200000' || ($refund['fields']['4'] ?? '') !== '000000002000') {
    fwrite(STDERR, "refund amount was not kept positive on 0200\n");
    $failed = true;
}
unset($refund['packed']);

$catalog = [
    'purchase_3d' => ['0200', 25],
    'purchase_2d' => ['0200', 25],
    'auth' => ['0100', 25],
    'auth_hold' => ['0100', 25],
    'auth_moto' => ['0100', 25],
    'hold' => ['0100', 25],
    'capture' => ['0220', 30],
    'auth_capture' => ['0220', 30],
    'purchase_advice' => ['0220', 12],
    'purchase_advice_online' => ['0220', 12],
    'purchase_advice_offline' => ['0220', 12],
    'online_sale_moto' => ['0200', 25],
    'offline_sale_moto' => ['0220', 25],
    'purchase_online' => ['0200', 25],
    'purchase_offline' => ['0220', 25],
    'refund' => ['0200', 10],
    'avoid' => ['0400', 25],
    'void' => ['0400', 25],
    'reversal' => ['0400', 25],
    'cancel' => ['0400', 25],
    'recurring' => ['0200', 25],
    'installment' => ['0200', 25],
    'quasi_cash' => ['0200', 25],
    'withdrawal_pos' => ['0200', 25],
    'withdrawal_nfc' => ['0200', 25],
    'crypto_purchase' => ['0200', 25],
    'gift_card' => ['0200', 25],
    'wire_transfer' => ['0200', 25],
    'balance' => ['0100', 0],
    'settlement' => ['0500', 0],
    'cash_advance' => ['0200', 25],
    'purchase' => ['0200', 25],
    'moto' => ['0200', 25],
];
foreach ($catalog as $type => $expect) {
    $built = DiparmaIsoProcessor::buildIso8583($type, array_merge($common, [
        'pan' => $visa,
        'card_number' => $visa,
        'amount' => $expect[1],
        'rrn' => '123456789012',
        'approval_code' => '482196',
    ]));
    unset($built['packed']);
    if (($built['mti'] ?? '') !== $expect[0] || ($built['fields']['48'] ?? '') !== 'VISN') {
        fwrite(STDERR, "catalog {$type} expected {$expect[0]} VISN, got " . ($built['mti'] ?? '') . ' ' . ($built['message'] ?? '') . "\n");
        $failed = true;
    }
}

$named = [
    'recurring' => ['0200', '500000', '012', '08'],
    'installment' => ['0200', '510000', '012', '08'],
    'quasi_cash' => ['0200', '110000', '012', '08'],
    'gift_card' => ['0200', '520000', '012', '08'],
    'wire_transfer' => ['0200', '400000', '010', '00'],
    'crypto_purchase' => ['0200', '530000', '012', '08'],
    'purchase_3d' => ['0200', '000000', '812', '59'],
    'purchase_2d' => ['0200', '000000', '012', '08'],
    'offline_sale_moto' => ['0220', '000000', '012', '01'],
    'auth_moto' => ['0100', '000000', '012', '08'],
    'withdrawal_pos' => ['0200', '010000', '051', '00'],
    'withdrawal_nfc' => ['0200', '010000', '071', '00'],
];
foreach ($named as $type => $expect) {
    $pan = $type === 'quasi_cash' ? $mastercard : $visa;
    $built = DiparmaIsoProcessor::buildIso8583($type, array_merge($common, [
        'pan' => $pan,
        'card_number' => $pan,
        'amount' => 15,
    ]));
    unset($built['packed']);
    $ok = ($built['mti'] ?? '') === $expect[0]
        && ($built['fields']['3'] ?? '') === $expect[1]
        && ($built['fields']['22'] ?? '') === $expect[2]
        && ($built['fields']['25'] ?? '') === $expect[3]
        && ($built['fields']['48'] ?? '') === ($pan === $mastercard ? 'MCRD' : 'VISN');
    echo strtoupper($type)
        . ' MTI=' . ($built['mti'] ?? '')
        . ' DE3=' . ($built['fields']['3'] ?? '')
        . ' DE22=' . ($built['fields']['22'] ?? '')
        . ' DE25=' . ($built['fields']['25'] ?? '')
        . ' scheme=' . ($built['fields']['48'] ?? '')
        . ' card=' . ($built['fields']['2'] ?? '')
        . PHP_EOL;
    if (!$ok) {
        fwrite(STDERR, "{$type} entry or processing code mismatch\n");
        $failed = true;
    }
}

$bins = [
    '2221000000000005' => 'MCRD',
    '2720000000000008' => 'MCRD',
    '5105105105105100' => 'MCRD',
    '2721000000000007' => '',
    '6011000000000004' => '',
    '378282246310005' => '',
];
foreach ($bins as $pan => $scheme) {
    $built = DiparmaIsoProcessor::buildIso8583('purchase_2d', array_merge($common, [
        'pan' => $pan,
        'card_number' => $pan,
        'amount' => 1,
    ]));
    unset($built['packed']);
    $got = (string) ($built['fields']['48'] ?? '');
    $public = DiparmaIsoProcessor::handle('purchase_2d', array_merge($common, [
        'pan' => $pan,
        'card_number' => $pan,
        'amount' => 1,
    ]));
    $encoded = json_encode($public);
    if (is_string($encoded) && str_contains($encoded, $pan)) {
        fwrite(STDERR, "PAN leaked for a BIN check\n");
        $failed = true;
    }
    if ($scheme === '') {
        if (($public['approved'] ?? false) || !str_contains((string) ($public['message'] ?? ''), 'Unsupported')) {
            fwrite(STDERR, "non Visa/Mastercard BIN was not rejected\n");
            $failed = true;
        }
    } elseif ($got !== $scheme || !empty($public['approved'])) {
        fwrite(STDERR, "BIN scheme mismatch\n");
        $failed = true;
    }
}

if (DiparmaIsoProcessor::isRoute('paypal') || DiparmaIsoProcessor::isRoute('stripe') || DiparmaIsoProcessor::isRoute('square') || DiparmaIsoProcessor::isRoute('nuvei')) {
    fwrite(STDERR, "foreign processors were accepted on diparma_iso\n");
    $failed = true;
}

$sample = ISO8583AdviceProcessor::buildISOFrame('0210', [
    37 => '123456789012',
    38 => '4821',
    39 => '00',
    48 => 'VISN',
]);
$parsed = DiparmaIsoProcessor::parseResponse($sample);
echo 'PARSE MTI=' . ($parsed['mti'] ?? '')
    . ' DE39=' . var_export($parsed['response_code'] ?? null, true)
    . ' DE37=' . ($parsed['rrn'] ?? '')
    . ' DE38=' . ($parsed['approval_code'] ?? '')
    . ' scheme=' . ($parsed['scheme'] ?? '')
    . PHP_EOL;

if (($parsed['response_code'] ?? null) !== '00' || ($parsed['rrn'] ?? '') !== '123456789012' || ($parsed['scheme'] ?? '') !== 'VISN') {
    fwrite(STDERR, "parser did not extract DE39/DE37/DE48\n");
    $failed = true;
}

exit($failed ? 1 : 0);
