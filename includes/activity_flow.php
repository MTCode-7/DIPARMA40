<?php
/**
 * Activity-first payment flow:
 * نشاط الشركة → POS أو رابط → بوابة متصلة → 13 عملية → Ledger
 */
if (defined('DI_PARMA_ACTIVITY_FLOW')) {
    return;
}
define('DI_PARMA_ACTIVITY_FLOW', true);

if (!function_exists('pos_merchant_lines')) {
    require_once dirname(__DIR__) . '/pos/lib/merchant.php';
}
if (!function_exists('pos_operation_catalog')) {
    require_once dirname(__DIR__) . '/pos/lib/operations.php';
}
if (!function_exists('pos_terminal_gateways')) {
    require_once dirname(__DIR__) . '/pos/lib/gateways.php';
}

function activity_card_present_modes(): array
{
    return [
        'physical' => [
            'ar' => 'تمرير البطاقة',
            'en' => 'Present the card',
            'icon' => 'fa-sim-card',
            'color' => '#10B981',
            'desc_ar' => 'شريحة في جهاز POS أو لمس NFC — البطاقة حاضرة',
            'desc_en' => 'Chip in the POS or NFC tap — card present',
        ],
        'manual' => [
            'ar' => 'بدون تمرير',
            'en' => 'No card tap',
            'icon' => 'fa-keyboard',
            'color' => '#F59E0B',
            'desc_ar' => 'إدخال الرقم يدوياً — لا شريحة ولا NFC',
            'desc_en' => 'Type the number — no chip and no NFC',
        ],
    ];
}

function activity_suggested_gateway(string $line): string
{
    $row = pos_merchant_line($line);
    return strtolower(trim((string) ($row['suggested_gateway'] ?? '')));
}

function activity_channels(): array
{
    return [
        'pos' => [
            'ar' => 'سحب POS',
            'en' => 'POS',
            'icon' => 'fa-cash-register',
            'color' => '#F97316',
            'desc_ar' => 'نقطة البيع — شريحة أو NFC أو إدخال يدوي',
            'desc_en' => 'Terminal — chip, NFC, or keyed entry',
        ],
        'link' => [
            'ar' => 'رابط دفع',
            'en' => 'Payment link',
            'icon' => 'fa-link',
            'color' => '#3B82F6',
            'desc_ar' => 'رابط للكاشير أو للعميل — نفس البوابة والعملية',
            'desc_en' => 'Link for cashier or customer — same gateway and operation',
        ],
    ];
}

function activity_ledger_address(): string
{
    if (defined('LEDGER_TRC20_ADDRESS')) {
        return trim((string) LEDGER_TRC20_ADDRESS);
    }
    return trim((string) (getenv('LEDGER_TRC20_ADDRESS') ?: ''));
}

/** أين يصل الصافي بعد موافقة البطاقة. الوجهة النهائية دائماً المحفظة. */
function activity_arrival_options(): array
{
    $addr = activity_ledger_address();
    return [
        'wallet' => [
            'ar' => 'عنوان المحفظة — Ledger',
            'en' => 'Wallet address — Ledger',
            'icon' => 'fa-wallet',
            'color' => '#10B981',
            'preferred' => true,
            'desc_ar' => 'الأفضل. البطاقة تُخصم عند البوابة ثم الصافي USDT يُرسل فوراً إلى عنوان Ledger.',
            'desc_en' => 'Best. The card is charged at the gateway, then net USDT is sent to the Ledger address.',
            'address' => $addr,
        ],
        'payout' => [
            'ar' => 'وقف عند البوابة — ثم تحويل',
            'en' => 'Stopped at gateway — then transfer',
            'icon' => 'fa-university',
            'color' => '#F59E0B',
            'preferred' => false,
            'desc_ar' => 'إذا لم يصل تلقائياً للمحفظة: اختر بوابة دفع أو بنكاً لتحويل الصافي إلى Ledger.',
            'desc_en' => 'If it does not auto-arrive at the wallet: pick a gateway or bank to move the net to Ledger.',
        ],
    ];
}

/** مسارات التحويل إلى Ledger عندما يقف المبلغ عند البوابة */
function activity_payout_rails(): array
{
    return [
        'wise' => ['ar' => 'Wise', 'en' => 'Wise', 'icon' => 'fa-exchange-alt', 'color' => '#9fe870', 'kind' => 'gateway'],
        'payram' => ['ar' => 'PayRam', 'en' => 'PayRam', 'icon' => 'fa-server', 'color' => '#10B981', 'kind' => 'gateway'],
        'mashreq' => ['ar' => 'Mashreq Bank', 'en' => 'Mashreq Bank', 'icon' => 'fa-university', 'color' => '#FF6600', 'kind' => 'bank'],
        'hsbc' => ['ar' => 'HSBC UAE', 'en' => 'HSBC UAE', 'icon' => 'fa-university', 'color' => '#DB0011', 'kind' => 'bank'],
        'nbe' => ['ar' => 'البنك الأهلي المصري', 'en' => 'NBE Egypt', 'icon' => 'fa-university', 'color' => '#C8102E', 'kind' => 'bank'],
        'jpmorgan' => ['ar' => 'JP Morgan', 'en' => 'JP Morgan', 'icon' => 'fa-university', 'color' => '#0A2F6C', 'kind' => 'bank'],
    ];
}

function activity_normalize_arrival(string $code): string
{
    $code = strtolower(trim($code));
    return $code === 'payout' ? 'payout' : 'wallet';
}

function activity_normalize_payout_rail(string $code): string
{
    $code = strtolower(trim($code));
    $rails = activity_payout_rails();
    return isset($rails[$code]) ? $code : '';
}

/** الأنواع الـ 13 المعتمدة لكل نشاط وبوابة */
function activity_operations(): array
{
    $pos = pos_operation_catalog();
    $pick = static function (string $posKey, array $over = []) use ($pos): array {
        $base = $pos[$posKey] ?? [
            'ar' => $posKey,
            'en' => $posKey,
            'icon' => 'fa-credit-card',
            'color' => '#FFD700',
            'desc_ar' => '',
            'desc_en' => '',
        ];
        $row = array_merge($base, $over);
        $row['pos_key'] = $posKey;
        return $row;
    };

    return [
        'purchase_3d' => $pick('purchase_3d'),
        'purchase_2d' => $pick('purchase_2d'),
        'purchase_advice' => $pick('purchase_advice'),
        'purchase_offline' => $pick('offline_sale_moto', [
            'ar' => 'شراء أوفلاين',
            'en' => 'Offline purchase',
        ]),
        'purchase_online' => $pick('online_sale_moto', [
            'ar' => 'شراء أونلاين',
            'en' => 'Online purchase',
        ]),
        'auth_hold' => $pick('auth'),
        'auth_capture' => $pick('capture'),
        'recurring' => $pick('recurring'),
        'installment' => $pick('installment'),
        'crypto_purchase' => $pick('crypto_purchase'),
        'gift_card' => $pick('gift_card'),
        'wire_transfer' => $pick('wire_transfer'),
        'quasi_cash' => $pick('quasi_cash'),
    ];
}

function activity_normalize_operation(string $code): string
{
    $code = trim($code);
    $ops = activity_operations();
    if (isset($ops[$code])) {
        return $code;
    }
    $aliases = [
        'auth' => 'auth_hold',
        'capture' => 'auth_capture',
        'offline_sale_moto' => 'purchase_offline',
        'online_sale_moto' => 'purchase_online',
        'purchase_moto' => 'purchase_2d',
    ];
    return $aliases[$code] ?? (isset($ops['purchase_3d']) ? 'purchase_3d' : $code);
}

function activity_pos_operation(string $code): string
{
    $ops = activity_operations();
    $code = activity_normalize_operation($code);
    return $ops[$code]['pos_key'] ?? pos_normalize_operation($code);
}

function activity_link_gateway_meta(): array
{
    return [
        'myfatoorah' => [
            'name' => 'MyFatoorah',
            'icon' => 'fas fa-money-bill-wave',
            'color' => '#00b09b',
            'desc_ar' => 'كل الشبكات والمُصدرين — الشرق الأوسط',
            'desc_en' => 'All networks and issuers — Middle East',
        ],
        'mashreq' => [
            'name' => 'Mashreq Bank',
            'icon' => 'fas fa-university',
            'color' => '#FF6600',
            'desc_ar' => 'تحويل بنكي + كروت',
            'desc_en' => 'Bank transfer + cards',
        ],
        'hsbc_uae' => [
            'name' => 'HSBC UAE',
            'icon' => 'fas fa-university',
            'color' => '#DB0011',
            'desc_ar' => 'تحويل بنكي + كروت',
            'desc_en' => 'Bank transfer + cards',
        ],
        'nbe_egypt' => [
            'name' => 'NBE Egypt',
            'icon' => 'fas fa-landmark',
            'color' => '#006633',
            'desc_ar' => 'تحويل بنكي + كروت',
            'desc_en' => 'Bank transfer + cards',
        ],
        'jpmorgan' => [
            'name' => 'JP Morgan Chase',
            'icon' => 'fas fa-landmark',
            'color' => '#003087',
            'desc_ar' => 'تحويل بنكي + كروت',
            'desc_en' => 'Bank transfer + cards',
        ],
    ];
}

function activity_connected_gateways(string $channel = 'pos'): array
{
    $out = [];
    foreach (pos_live_gateways() as $code => $gw) {
        $out[$code] = $gw;
    }
    if ($channel !== 'link') {
        return $out;
    }
    if (!function_exists('isGatewayVisibleInCheckout')) {
        require_once dirname(__DIR__) . '/includes/gateways.php';
    }
    foreach (activity_link_gateway_meta() as $code => $gw) {
        if (isset($out[$code])) {
            continue;
        }
        try {
            $row = db()->find('payment_gateways', ['code' => $code]);
        } catch (Throwable $e) {
            $row = null;
        }
        if ($row && isGatewayVisibleInCheckout(array_merge($row, ['code' => $code]))) {
            $out[$code] = $gw + ['adapter' => $code, 'rail' => 'card'];
        }
    }
    // Square Online is a fulfillment/service channel; card charging stays on Square 1.
    try {
        $squareRow = db()->find('payment_gateways', ['code' => 'square']);
    } catch (Throwable $e) {
        $squareRow = null;
    }
    if ($squareRow && isGatewayVisibleInCheckout(array_merge($squareRow, ['code' => 'square_online']))) {
        $out['square_online'] = [
            'name' => 'Square 2 · Online',
            'icon' => 'fas fa-store',
            'color' => '#006AFF',
            'desc_ar' => 'خدمات Square Online، والدفع بالبطاقة عبر Square 1',
            'desc_en' => 'Square Online services; card payment through Square 1',
            'adapter' => 'square',
            'rail' => 'fulfillment',
            'chargeable' => false,
        ];
    }
    return $out;
}

function activity_checkout_route(string $code): string
{
    $routes = [
        'nuvei' => 'checkout/nuvei.php',
        'stripe' => 'checkout/stripe.php',
        'square' => 'checkout/square.php',
        'square_online' => 'checkout/square_online.php',
        'diparma_gateway' => 'checkout_router.php',
        'paypal' => 'checkout/paypal.php',
        'wise' => 'checkout/wise.php',
        'myfatoorah' => 'checkout/myfatoorah.php',
        'binance' => 'checkout/binance.php',
        'gate_io' => 'checkout/gate_io.php',
        'mashreq' => 'checkout/bank_mashreq.php',
        'hsbc_uae' => 'checkout/bank_hsbc.php',
        'nbe_egypt' => 'checkout/bank_nbe.php',
        'jpmorgan' => 'checkout/bank_jpmorgan.php',
        'whop' => 'checkout/whop.php',
        'payram' => 'checkout/payram.php',
        'diparma' => 'checkout/diparma.php',
    ];
    return $routes[$code] ?? '';
}
