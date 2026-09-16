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
            'icon' => 'fa-gas-pump',
            'color' => '#F59E0B',
        ],
        'logistics' => [
            'mcc' => '4214',
            'ar' => 'لوجستيك ونقل وتوصيل',
            'en' => 'Logistics, freight & delivery',
            'item' => 'Logistics freight courier delivery',
            'suggested_gateway' => 'nuvei',
            'icon' => 'fa-truck',
            'color' => '#3B82F6',
            'desc_ar' => 'شحن · نقل · توصيل · مخازن — عبر أجهزة POS في المحلات والمستودعات',
            'desc_en' => 'Freight · transport · delivery · warehouses — via POS at stores and depots',
        ],
        'hajj' => [
            'mcc' => '4722',
            'ar' => 'حج وعمرة وسياحة',
            'en' => 'Hajj, Umrah & tourism',
            'item' => 'Hajj Umrah tourism',
            'suggested_gateway' => 'stripe',
            'icon' => 'fa-kaaba',
            'color' => '#10B981',
        ],
        'hotels' => [
            'mcc' => '7011',
            'ar' => 'فنادق وإقامة',
            'en' => 'Hotels & lodging',
            'item' => 'Hotel lodging',
            'suggested_gateway' => 'stripe',
            'icon' => 'fa-hotel',
            'color' => '#8B5CF6',
        ],
        'expo' => [
            'mcc' => '7991',
            'ar' => 'معارض وفعاليات',
            'en' => 'Exhibitions & events',
            'item' => 'Exhibition event services',
            'suggested_gateway' => 'paypal',
            'icon' => 'fa-calendar-check',
            'color' => '#EC4899',
        ],
        'autos' => [
            'mcc' => '5511',
            'ar' => 'سيارات',
            'en' => 'Vehicles',
            'item' => 'Vehicle sales',
            'suggested_gateway' => 'nuvei',
            'icon' => 'fa-car',
            'color' => '#EF4444',
        ],
        'rental' => [
            'mcc' => '7512',
            'ar' => 'تأجير سيارات — السعودية والخارج',
            'en' => 'Car rental — KSA and abroad',
            'item' => 'Car rental KSA international',
            'suggested_gateway' => 'stripe',
            'icon' => 'fa-key',
            'color' => '#14B8A6',
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
                'icon' => (string) ($row['icon'] ?? 'fa-briefcase'),
                'color' => (string) ($row['color'] ?? '#FFD700'),
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
