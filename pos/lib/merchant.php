<?php
/**
 * POS merchant profile — TRANSCENDIO FZ-LLC operating lines.
 */
if (defined('DI_PARMA_POS_MERCHANT')) {
    return;
}
define('DI_PARMA_POS_MERCHANT', true);

function pos_merchant_legal_name(): string
{
    return 'TRANSCENDIO FZ-LLC';
}

function pos_merchant_lines(): array
{
    $lines = [
        'petroleum' => [
            'mcc' => '5541',
            'ar' => 'بترول وطاقة',
            'en' => 'Petroleum & energy',
            'item' => 'Petroleum energy services',
            'suggested_gateway' => 'nuvei',
        ],
        'hajj' => [
            'mcc' => '4722',
            'ar' => 'حج وعمرة وسياحة',
            'en' => 'Hajj, Umrah & tourism',
            'item' => 'Hajj Umrah tourism',
            'suggested_gateway' => 'stripe',
        ],
        'hotels' => [
            'mcc' => '7011',
            'ar' => 'فنادق وإقامة',
            'en' => 'Hotels & lodging',
            'item' => 'Hotel lodging',
            'suggested_gateway' => 'stripe',
        ],
        'expo' => [
            'mcc' => '7991',
            'ar' => 'معارض وفعاليات',
            'en' => 'Exhibitions & events',
            'item' => 'Exhibition event services',
            'suggested_gateway' => 'paypal',
        ],
        'autos' => [
            'mcc' => '5511',
            'ar' => 'سيارات',
            'en' => 'Vehicles',
            'item' => 'Vehicle sales',
            'suggested_gateway' => 'nuvei',
        ],
        'rental' => [
            'mcc' => '7512',
            'ar' => 'تأجير سيارات — السعودية والخارج',
            'en' => 'Car rental — KSA and abroad',
            'item' => 'Car rental KSA international',
            'suggested_gateway' => 'stripe',
        ],
    ];
    if (function_exists('pos_custom_activities')) {
        foreach (pos_custom_activities() as $code => $row) {
            $lines[$code] = [
                'mcc' => (string) ($row['mcc'] ?? '5999'),
                'ar' => (string) ($row['ar'] ?? $code),
                'en' => (string) ($row['en'] ?? $code),
                'item' => (string) ($row['item'] ?? ($row['en'] ?? $code)),
                'suggested_gateway' => (string) ($row['suggested_gateway'] ?? ''),
            ];
        }
    }
    return $lines;
}

function pos_merchant_line(string $code = ''): array
{
    $lines = pos_merchant_lines();
    $code = strtolower(trim($code));
    if ($code === '' || !isset($lines[$code])) {
        $code = 'hajj';
    }
    return $lines[$code] + ['code' => $code];
}

function pos_merchant_profile(string $line = ''): array
{
    $row = pos_merchant_line($line);
    return [
        'legal_name' => pos_merchant_legal_name(),
        'brand' => 'DI PARMA',
        'line' => $row['code'],
        'mcc' => $row['mcc'],
        'line_ar' => $row['ar'],
        'line_en' => $row['en'],
        'item_name' => $row['item'],
        'region' => 'Saudi Arabia and international',
    ];
}
