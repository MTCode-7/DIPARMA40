<?php
/**
 * POS gateways — standalone module. Each gateway keeps the capture on that same gateway.
 */
if (!defined('POS_APP_ROOT')) {
    define('POS_APP_ROOT', dirname(__DIR__, 2));
}
if (defined('DI_PARMA_POS_GW')) {
    return;
}
define('DI_PARMA_POS_GW', true);

function pos_terminal_gateways(): array
{
    $allTypes = function_exists('pos_accepted_card_types') ? pos_accepted_card_types() : [];
    $acceptNoteAr = 'كل الشبكات والمُصدرين.';
    $acceptNoteEn = 'All networks and issuers.';
    $list = [
        'nuvei' => [
            'name' => 'Nuvei',
            'icon' => 'fas fa-credit-card',
            'color' => '#F97316',
            'adapter' => 'nuvei',
            'rail' => 'card',
            'desc_ar' => 'Nuvei. ' . $acceptNoteAr . ' بعد الموافقة يبقى المبلغ على Nuvei.',
            'desc_en' => 'Nuvei. ' . $acceptNoteEn . ' After approval the funds stay on Nuvei.',
        ],
        'paypal' => [
            'name' => 'PayPal',
            'icon' => 'fab fa-paypal',
            'color' => '#003087',
            'adapter' => 'paypal',
            'rail' => 'card',
            'desc_ar' => 'PayPal. ' . $acceptNoteAr . ' بعد الموافقة يبقى المبلغ على PayPal.',
            'desc_en' => 'PayPal. ' . $acceptNoteEn . ' After approval the funds stay on PayPal.',
        ],
        'stripe' => [
            'name' => 'Stripe',
            'icon' => 'fab fa-stripe-s',
            'color' => '#6772e5',
            'adapter' => 'stripe',
            'rail' => 'card',
            'desc_ar' => 'Stripe. ' . $acceptNoteAr . ' بعد الموافقة يبقى المبلغ على Stripe.',
            'desc_en' => 'Stripe. ' . $acceptNoteEn . ' After approval the funds stay on Stripe.',
        ],
        'square' => [
            'name' => 'Square',
            'icon' => 'fas fa-square',
            'color' => '#006AFF',
            'adapter' => 'square',
            'rail' => 'card',
            'desc_ar' => 'Square عبر Payments API أونلاين (Web Payments). بعد الموافقة يبقى المبلغ على Square. أوفلاين Square فقط على جهاز Square POS: يُخزَّن على الجهاز، رفع خلال 24 ساعة وينتهي بعد 72 من أول عملية. المعلّق يُعرض في تطبيق Square فقط (Transactions). حد العملية 50,000 دولار. إدخال الرقم يدوياً غير مدعوم أوفلاين.',
            'desc_en' => 'Square via live Payments API (Web Payments). After approval the funds stay on Square. Square Offline is Square POS hardware only: stored on the device, upload within 24 hours (expires 72 from first payment). Pending is visible only in the Square POS app (Transactions). Max 50,000 USD. Keyed PAN is not available offline.',
        ],
        'square_online' => [
            'name' => 'Square 2 · Online',
            'icon' => 'fas fa-store',
            'color' => '#006AFF',
            'adapter' => 'square',
            'rail' => 'fulfillment',
            'chargeable' => false,
            'company_no' => 10,
            'desc_ar' => 'شركة 10 — DI PARMA BUSINESSMAN SERVICES. المتجر: الإمارات. العمل: حول العالم. الخدمات: سياحة، حجوزات، إيجارات، عقارات، فنادق. الخصم على Square 1 ويبقى المبلغ على Square.',
            'desc_en' => 'Company 10 — DI PARMA BUSINESSMAN SERVICES. Store: UAE. Work: around the world. Services: tourism, bookings, rentals, real estate, hotels. Cards on Square 1; funds stay on Square.',
        ],
        'payram' => [
            'name' => 'PayRam',
            'icon' => 'fas fa-server',
            'color' => '#10B981',
            'adapter' => 'payram',
            'rail' => 'redirect',
            'desc_ar' => 'PayRam. ' . $acceptNoteAr . ' البطاقة على صفحة PayRam ويبقى المبلغ على PayRam.',
            'desc_en' => 'PayRam. ' . $acceptNoteEn . ' Card on the PayRam page; funds stay on PayRam.',
        ],
        'wise' => [
            'name' => 'Wise',
            'icon' => 'fas fa-exchange-alt',
            'color' => '#9fe870',
            'adapter' => 'wise',
            'rail' => 'wallet',
            'desc_ar' => 'Wise. ' . $acceptNoteAr . ' بعد القبول يبقى المبلغ على Wise.',
            'desc_en' => 'Wise. ' . $acceptNoteEn . ' After accept the funds stay on Wise.',
        ],
        'diparma' => [
            'name' => 'DI PARMA',
            'icon' => 'fas fa-coins',
            'color' => '#FFD700',
            'adapter' => 'diparma',
            'rail' => 'card',
            'desc_ar' => 'DI PARMA. ' . $acceptNoteAr . ' بعد الموافقة يبقى المبلغ على نفس البوابة.',
            'desc_en' => 'DI PARMA. ' . $acceptNoteEn . ' After approval the funds stay on the same gateway.',
        ],
        'diparma_gateway' => [
            'name' => 'DIPARMA GATEWAY',
            'icon' => 'fas fa-credit-card',
            'color' => '#E8C547',
            'adapter' => '',
            'rail' => 'card',
            'chargeable' => false,
            'desc_ar' => 'بعد موافقة البوابة المفعّلة يبقى المبلغ عليها.',
            'desc_en' => 'After the enabled gateway you pick approves, funds stay on that gateway.',
        ],
        'whop' => [
            'name' => 'Whop',
            'icon' => 'fas fa-bolt',
            'color' => '#7C3AED',
            'adapter' => 'whop',
            'rail' => 'redirect',
            'desc_ar' => 'Whop. ' . $acceptNoteAr . ' بعد إتمام الدفع يبقى المبلغ على Whop.',
            'desc_en' => 'Whop. ' . $acceptNoteEn . ' After payment the funds stay on Whop.',
        ],
        'gate_io' => [
            'name' => 'Gate.io',
            'icon' => 'fas fa-coins',
            'color' => '#E8112D',
            'adapter' => 'gate_io',
            'rail' => 'crypto',
            'desc_ar' => 'Gate.io. ' . $acceptNoteAr . ' بعد القبول يبقى المبلغ على Gate.io.',
            'desc_en' => 'Gate.io. ' . $acceptNoteEn . ' After accept the funds stay on Gate.io.',
        ],
        'binance' => [
            'name' => 'Binance',
            'icon' => 'fas fa-coins',
            'color' => '#F3BA2F',
            'adapter' => 'binance',
            'rail' => 'crypto',
            'desc_ar' => 'Binance. ' . $acceptNoteAr . ' بعد القبول يبقى المبلغ على Binance.',
            'desc_en' => 'Binance. ' . $acceptNoteEn . ' After accept the funds stay on Binance.',
        ],
        'checkout' => [
            'name' => 'Checkout.com',
            'icon' => 'fas fa-credit-card',
            'color' => '#1A1F36',
            'adapter' => 'checkout',
            'rail' => 'card',
            'desc_ar' => 'Checkout.com. ' . $acceptNoteAr . ' مسار مستقل. بعد الموافقة يبقى المبلغ على Checkout.',
            'desc_en' => 'Checkout.com. ' . $acceptNoteEn . ' Isolated path. After approval the funds stay on Checkout.',
        ],
        'paytabs' => [
            'name' => 'PayTabs',
            'icon' => 'fas fa-credit-card',
            'color' => '#00AEEF',
            'adapter' => 'paytabs',
            'rail' => 'card',
            'desc_ar' => 'PayTabs. ' . $acceptNoteAr . ' مسار مستقل. بعد الموافقة يبقى المبلغ على PayTabs.',
            'desc_en' => 'PayTabs. ' . $acceptNoteEn . ' Isolated path. After approval the funds stay on PayTabs.',
        ],
        'authorizenet' => [
            'name' => 'Authorize.Net',
            'icon' => 'fas fa-credit-card',
            'color' => '#1A4E8A',
            'adapter' => 'authorizenet',
            'rail' => 'card',
            'desc_ar' => 'Authorize.Net. ' . $acceptNoteAr . ' مسار مستقل. بعد الموافقة يبقى المبلغ على Authorize.Net.',
            'desc_en' => 'Authorize.Net. ' . $acceptNoteEn . ' Isolated path. After approval the funds stay on Authorize.Net.',
        ],
        'braintree' => [
            'name' => 'Braintree',
            'icon' => 'fas fa-credit-card',
            'color' => '#00A3E0',
            'adapter' => 'braintree',
            'rail' => 'card',
            'desc_ar' => 'Braintree. ' . $acceptNoteAr . ' مسار مستقل. بعد الموافقة يبقى المبلغ على Braintree.',
            'desc_en' => 'Braintree. ' . $acceptNoteEn . ' Isolated path. After approval the funds stay on Braintree.',
        ],
        'myfatoorah' => [
            'name' => 'MyFatoorah',
            'icon' => 'fas fa-credit-card',
            'color' => '#7C3AED',
            'adapter' => 'myfatoorah',
            'rail' => 'card',
            'desc_ar' => 'MyFatoorah. ' . $acceptNoteAr . ' مسار مستقل. بعد الموافقة يبقى المبلغ على MyFatoorah.',
            'desc_en' => 'MyFatoorah. ' . $acceptNoteEn . ' Isolated path. After approval the funds stay on MyFatoorah.',
        ],
    ];
    foreach ($list as &$meta) {
        $meta['card_types'] = $allTypes;
        $meta['accepts_all_card_networks'] = true;
    }
    unset($meta);
    return $list;
}

/** @deprecated use pos_terminal_gateways() */
function pos_card_gateways(): array
{
    return pos_terminal_gateways();
}

function pos_external_gateways(): array
{
    return [];
}

function pos_normalize_gateway(string $code): string
{
    $code = strtolower(trim($code));
    $aliases = [
        'di_parma' => 'diparma',
        'di-parma' => 'diparma',
        'diparma-gateway' => 'diparma_gateway',
        'diparmagateway' => 'diparma_gateway',
        'square_2' => 'square_online',
        'square2' => 'square_online',
        'square-online' => 'square_online',
        'authorize_net' => 'authorizenet',
        'authnet' => 'authorizenet',
        'checkout.com' => 'checkout',
        'pay_tabs' => 'paytabs',
    ];
    if (isset($aliases[$code])) {
        $code = $aliases[$code];
    }
    $list = pos_terminal_gateways();
    return isset($list[$code]) ? $code : '';
}

function pos_gateway_db_row(string $code): ?array
{
    static $rows = null;
    if ($rows === null) {
        $rows = [];
        try {
            $pfx = defined('DB_PREFIX') ? DB_PREFIX : 'dp_';
            foreach (db()->query("SELECT * FROM {$pfx}payment_gateways") ?: [] as $row) {
                $c = strtolower(trim((string) ($row['code'] ?? '')));
                if ($c !== '') {
                    $rows[$c] = $row;
                }
            }
        } catch (Throwable $e) {
            $rows = [];
        }
    }
    $code = pos_normalize_gateway($code);
    if ($code === '') {
        return null;
    }
    if (isset($rows[$code])) {
        return $rows[$code];
    }
    foreach (['di_parma' => 'diparma', 'gate.io' => 'gate_io'] as $from => $to) {
        if ($code === $to && isset($rows[$from])) {
            return $rows[$from];
        }
    }
    return null;
}

function pos_gateway_supports_withdrawal(string $code): bool
{
    return pos_is_charge_processor($code) && pos_gateway_is_live($code);
}

function pos_is_charge_processor(string $code): bool
{
    $code = pos_normalize_gateway($code);
    if ($code === '' || $code === 'diparma_gateway') {
        return false;
    }
    $meta = pos_terminal_gateways()[$code] ?? null;
    if (!$meta) {
        return false;
    }
    if (array_key_exists('chargeable', $meta) && empty($meta['chargeable'])) {
        return false;
    }
    return ($meta['adapter'] ?? '') !== 'ledger';
}

function pos_gateway_is_live(string $code): bool
{
    $code = pos_normalize_gateway($code);
    if ($code === '') {
        return false;
    }
    if (!function_exists('isGatewayVisibleInPos')) {
        require_once POS_APP_ROOT . '/includes/gateways.php';
    }
    $row = pos_gateway_db_row($code);
    if (!$row) {
        return false;
    }
    $row['code'] = $code;
    return isGatewayVisibleInPos($row);
}

function pos_live_gateways(): array
{
    $out = [];
    foreach (pos_terminal_gateways() as $code => $meta) {
        if (pos_is_charge_processor($code) && pos_gateway_is_live($code)) {
            $out[$code] = $meta;
        }
    }
    return $out;
}

function pos_gateway_meta(string $code): ?array
{
    $code = pos_normalize_gateway($code);
    if ($code === '') {
        return null;
    }
    return pos_terminal_gateways()[$code];
}

function pos_gateway_requires_card(string $code): bool
{
    $meta = pos_gateway_meta($code);
    return ($meta['rail'] ?? 'card') === 'card';
}

/** Best card-charge currency. Crypto tickers charge as USD; settlement is always USDT → Ledger. */
function pos_best_card_currency(string $currency): string
{
    $c = strtoupper(trim($currency));
    $asUsd = ['USDT', 'USDC', 'TRX', 'BTC', 'ETH'];
    return in_array($c, $asUsd, true) ? 'USD' : ($c !== '' ? $c : 'USD');
}

function pos_sale_operations(): array
{
    return [
        'purchase_2d', 'purchase_3d', 'online_sale_moto', 'offline_sale_moto',
        'purchase_advice', 'auth', 'capture', 'withdrawal_pos', 'withdrawal_nfc',
    ];
}

function pos_prime_paypal_env(): void
{
    if (!function_exists('pos_gateway_db_row')) {
        return;
    }
    $row = pos_gateway_db_row('paypal');
    if (!$row) {
        return;
    }
    $creds = json_decode((string) ($row['credentials'] ?? ''), true) ?: [];
    $client = trim((string) ($creds['client_id'] ?? ''));
    $secret = trim((string) ($creds['secret'] ?? $creds['client_secret'] ?? ''));
    if ($client !== '' && !getenv('PAYPAL_CLIENT_ID')) {
        putenv('PAYPAL_CLIENT_ID=' . $client);
        $_ENV['PAYPAL_CLIENT_ID'] = $client;
    }
    if ($secret !== '' && !getenv('PAYPAL_CLIENT_SECRET')) {
        putenv('PAYPAL_CLIENT_SECRET=' . $secret);
        putenv('PAYPAL_SECRET=' . $secret);
        $_ENV['PAYPAL_CLIENT_SECRET'] = $secret;
        $_ENV['PAYPAL_SECRET'] = $secret;
    }
}

function pos_paypal_host_id(array $params, string $kind): string
{
    $candidates = [];
    if (function_exists('pos_host_payment_id')) {
        $candidates[] = pos_host_payment_id($params);
    }
    foreach (['payment_id', 'related_transaction_id', 'transaction_id', 'orig_ref', 'rrn'] as $key) {
        $candidates[] = trim((string) ($params[$key] ?? ''));
    }
    foreach ($candidates as $value) {
        if ($value !== '' && !preg_match('/^\d{12}$/', $value)) {
            return $value;
        }
    }
    $needle = '';
    foreach ($candidates as $value) {
        if ($value !== '') {
            $needle = $value;
            break;
        }
    }
    if ($needle === '' || !function_exists('db')) {
        return '';
    }
    try {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $needle) . '%';
        $rows = db()->query(
            'SELECT gateway_response FROM ' . (defined('DB_PREFIX') ? DB_PREFIX : 'dp_') . "transactions
             WHERE gateway = 'paypal' AND (reference = ? OR rrn = ? OR gateway_response LIKE ?)
             ORDER BY id DESC LIMIT 3",
            [$needle, preg_replace('/\D/', '', $needle), $like]
        );
    } catch (Throwable $e) {
        return '';
    }
    $keys = $kind === 'capture'
        ? ['capture_id', 'payment_id', 'transaction_id']
        : ['authorization_id', 'payment_id', 'transaction_id'];
    foreach ($rows ?: [] as $row) {
        $blob = json_decode((string) ($row['gateway_response'] ?? ''), true);
        $id = pos_paypal_id_from_node(is_array($blob) ? $blob : [], $keys);
        if ($id !== '') {
            return $id;
        }
    }
    return '';
}

function pos_paypal_id_from_node(array $node, array $keys): string
{
    foreach ($keys as $key) {
        $value = trim((string) ($node[$key] ?? ''));
        if ($value !== '' && !preg_match('/^\d{12}$/', $value) && preg_match('/^[A-Za-z0-9_-]{10,40}$/', $value)) {
            return $value;
        }
    }
    if (isset($node['raw']) && is_array($node['raw'])) {
        return pos_paypal_id_from_node($node['raw'], $keys);
    }
    return '';
}

function pos_format_gateway_result(array $result, string $fallbackMessage = 'DECLINED', string $gateway = ''): array
{
    if ($gateway !== '') {
        $result['gateway'] = $gateway;
        $result['provider'] = $gateway;
        $result['isolated'] = true;
    }
    if (empty($result['success']) && empty($result['requires_3ds']) && empty($result['redirect_url']) && empty($result['checkout_url'])) {
        $result['success'] = false;
        $result['message'] = $result['message'] ?? $fallbackMessage;
        return $result;
    }
    if (!empty($result['checkout_url']) && empty($result['redirect_url'])) {
        $result['redirect_url'] = $result['checkout_url'];
    }
    if (!empty($result['redirect_url']) && empty($result['requires_3ds'])) {
        $result['requires_3ds'] = true;
        $result['success'] = false;
        $result['message'] = $result['message'] ?? 'REDIRECT_REQUIRED';
    }
    $result['payment_id'] = $result['payment_id'] ?? $result['transaction_id'] ?? $result['nuvei_txn_id'] ?? '';
    $result['transaction_id'] = $result['transaction_id'] ?? $result['payment_id'] ?? '';
    $result['rrn'] = $result['rrn'] ?? '';
    $result['approval_code'] = $result['approval_code'] ?? $result['auth_code'] ?? '';
    return $result;
}

/** Isolated card adapters — each gateway runs only its own class. */
function pos_isolated_card_adapters(): array
{
    return ['nuvei', 'stripe', 'square', 'paypal', 'checkout', 'paytabs', 'authorizenet', 'braintree', 'myfatoorah', 'diparma'];
}

function pos_adapter_class_file(string $adapter): array
{
    $map = [
        'nuvei' => ['NuveiAdapter.php', 'NuveiAdapter'],
        'stripe' => ['StripeAdapter.php', 'StripeAdapter'],
        'square' => ['SquareAdapter.php', 'SquareAdapter'],
        'paypal' => ['PayPalAdapter.php', 'PayPalAdapter'],
        'checkout' => ['CheckoutAdapter.php', 'CheckoutAdapter'],
        'paytabs' => ['PayTabsAdapter.php', 'PayTabsAdapter'],
        'authorizenet' => ['AuthorizeNetAdapter.php', 'AuthorizeNetAdapter'],
        'braintree' => ['BraintreeAdapter.php', 'BraintreeAdapter'],
        'myfatoorah' => ['MyFatoorahAdapter.php', 'MyFatoorahAdapter'],
        'diparma' => ['../gateways/DIPARMAGateway.php', 'DIPARMAGateway'],
    ];
    return $map[$adapter] ?? ['', ''];
}

function pos_prepare_operation_payload(string $txnType, array $params): array
{
    $txnType = strtolower(trim($txnType));
    $params['txn_type'] = $txnType;
    $name = trim((string) ($params['card_name'] ?? $params['name'] ?? ''));
    if (function_exists('pos_real_card_name')) {
        $name = pos_real_card_name($name);
    }
    $params['name'] = $name;
    $params['card_name'] = $name;

    if ($txnType === 'purchase_3d') {
        if (!class_exists('CardScaService', false)) {
            require_once POS_APP_ROOT . '/lib/CardScaService.php';
        }
        if (!CardScaService::shouldChallenge($params)) {
            $params['processing_mode'] = '2D';
            $params['txn_type'] = 'purchase_2d';
            $params['is_moto'] = true;
        } else {
            $params['processing_mode'] = '3D';
            $params['is_moto'] = false;
        }
        return $params;
    }

    $params['processing_mode'] = '2D';
    $params['is_moto'] = true;
    $params['moto_indicator'] = $params['moto_indicator'] ?? 'M';

    if ($txnType === 'offline_sale_moto') {
        $params['is_offline'] = true;
        $params['moto_channel'] = 'offline';
        $params['auth_channel'] = 'offline';
    }
    if ($txnType === 'online_sale_moto') {
        $params['moto_channel'] = 'online';
        $params['auth_channel'] = 'online';
    }
    if ($txnType === 'purchase_advice') {
        $params['direct_advice'] = true;
        $params['card_cvv'] = '';
    }
    if (in_array($txnType, ['auth', 'auth_hold', 'auth_moto', 'hold', 'capture'], true)) {
        $params['card_cvv'] = $params['card_cvv'] ?? '';
    }
    return $params;
}

function pos_related_host_id(string $gateway, array $params): string
{
    if ($gateway === 'paypal' && function_exists('pos_paypal_host_id')) {
        $id = pos_paypal_host_id($params, 'authorization');
        if ($id !== '') {
            return $id;
        }
    }
    if (function_exists('pos_host_payment_id')) {
        $id = pos_host_payment_id($params);
        if ($id !== '') {
            return $id;
        }
    }
    foreach (['payment_id', 'related_transaction_id', 'nuvei_txn_id', 'transaction_id', 'orig_ref'] as $key) {
        $value = trim((string) ($params[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function pos_run_isolated_card_gateway(string $gateway, string $adapter, string $txnType, array $params): array
{
    $gateway = pos_normalize_gateway($gateway);
    $adapter = strtolower(trim($adapter));
    if ($gateway === 'nuvei' && $adapter !== 'nuvei') {
        return ['success' => false, 'message' => 'Nuvei runs only on the Nuvei adapter', 'gateway' => 'nuvei'];
    }
    if ($adapter === 'nuvei' && $gateway !== 'nuvei') {
        return ['success' => false, 'message' => 'Nuvei adapter cannot charge for ' . $gateway, 'gateway' => $gateway];
    }
    $params = pos_prepare_operation_payload($txnType, $params);
    $params['gateway'] = $gateway;
    $params['provider'] = $gateway;
    $effective = strtolower((string) ($params['txn_type'] ?? $txnType));
    [$file, $class] = pos_adapter_class_file($adapter);
    if ($file === '' || $class === '') {
        return ['success' => false, 'message' => 'Isolated adapter missing for ' . $gateway, 'gateway' => $gateway];
    }
    if ($adapter === 'paypal' && function_exists('pos_prime_paypal_env')) {
        pos_prime_paypal_env();
    }
    require_once POS_APP_ROOT . '/lib/Adapters/' . $file;
    if (!class_exists($class, false)) {
        return ['success' => false, 'message' => 'Adapter class not found: ' . $class, 'gateway' => $gateway];
    }
    $obj = new $class();
    $amount = (float) ($params['amount'] ?? 0);

    if (in_array($effective, ['auth', 'auth_hold', 'auth_moto', 'hold', 'authorize'], true)) {
        if ($adapter === 'nuvei' && method_exists($obj, 'authorize')) {
            return pos_format_gateway_result($obj->authorize($params), 'DECLINED', $gateway);
        }
        return pos_format_gateway_result($obj->hold($params), 'DECLINED', $gateway);
    }

    if (in_array($effective, ['capture', 'auth_complete', 'auth_capture'], true)) {
        if ($adapter === 'nuvei') {
            return pos_format_gateway_result($obj->capture($params, $amount > 0 ? $amount : null), 'DECLINED', $gateway);
        }
        $id = pos_related_host_id($gateway, $params);
        if ($id === '') {
            return ['success' => false, 'message' => 'Original payment id required for capture on ' . $gateway, 'gateway' => $gateway];
        }
        return pos_format_gateway_result($obj->capture($id, $amount > 0 ? $amount : null), 'DECLINED', $gateway);
    }

    if ($effective === 'refund') {
        $id = $gateway === 'paypal' && function_exists('pos_paypal_host_id')
            ? pos_paypal_host_id($params, 'capture')
            : pos_related_host_id($gateway, $params);
        if ($id === '') {
            return ['success' => false, 'message' => 'Original payment id required for refund on ' . $gateway, 'gateway' => $gateway];
        }
        if (!method_exists($obj, 'refund')) {
            return ['success' => false, 'message' => 'Refund is not supported on ' . $gateway, 'gateway' => $gateway];
        }
        return pos_format_gateway_result($obj->refund($id, $amount > 0 ? $amount : 0.0, (string) ($params['currency'] ?? 'USD')), 'DECLINED', $gateway);
    }

    if (in_array($effective, ['avoid', 'void', 'cancel', 'reversal'], true)) {
        $id = pos_related_host_id($gateway, $params);
        if ($id === '') {
            return ['success' => false, 'message' => 'Original payment id required to cancel on ' . $gateway, 'gateway' => $gateway];
        }
        if ($adapter === 'nuvei' && method_exists($obj, 'void')) {
            return pos_format_gateway_result($obj->void($params), 'DECLINED', $gateway);
        }
        return pos_format_gateway_result($obj->cancel($id, $effective), 'DECLINED', $gateway);
    }

    if ($effective === 'purchase_3d' && method_exists($obj, 'purchase3D')) {
        return pos_format_gateway_result($obj->purchase3D($params), 'DECLINED', $gateway);
    }
    if (in_array($effective, ['purchase_2d', 'online_sale_moto', 'offline_sale_moto', 'purchase_advice'], true)
        && method_exists($obj, 'purchase2D')) {
        return pos_format_gateway_result($obj->purchase2D($params), 'DECLINED', $gateway);
    }
    return pos_format_gateway_result($obj->charge($params), 'DECLINED', $gateway);
}

/**
 * Direct Advice → البوابة المختارة فقط (بدون افتراض Nuvei).
 * لا يستدعي purchase_advice من pos_run_standalone_gateway لتفادي الحلقة.
 */
function pos_dispatch_direct_advice_to_gateway(string $gateway, array $params): array
{
    $gateway = pos_normalize_gateway($gateway);
    if (!pos_is_charge_processor($gateway) || !pos_gateway_is_live($gateway)) {
        return ['success' => false, 'message' => 'Pick an enabled payment gateway'];
    }
    $meta = pos_gateway_meta($gateway);
    if (!$meta) {
        return ['success' => false, 'message' => 'Unknown POS gateway'];
    }
    $adapter = $meta['adapter'];
    $params['card_cvv'] = '';
    $params['direct_advice'] = true;
    $params['txn_type'] = 'purchase_advice';

    if (in_array($adapter, pos_isolated_card_adapters(), true)) {
        return pos_run_isolated_card_gateway($gateway, $adapter, 'purchase_advice', $params);
    }

    return ['success' => false, 'message' => 'Purchase Advice is a card MOTO sale on the selected card gateway only.', 'gateway' => $gateway];
}

function pos_run_standalone_gateway(string $gateway, string $txnType, array $params): array
{
    if (!pos_is_charge_processor($gateway) || !pos_gateway_is_live($gateway)) {
        return ['success' => false, 'message' => 'Pick an enabled payment gateway'];
    }
    $meta = pos_gateway_meta($gateway);
    if (!$meta) {
        return ['success' => false, 'message' => 'Unknown POS gateway'];
    }
    $adapter = $meta['adapter'];

    if ($adapter === 'ledger') {
        return ['success' => false, 'message' => 'Ledger is the settlement destination, not a card gateway. Pick a connected card gateway.'];
    }

    if (in_array($adapter, pos_isolated_card_adapters(), true)) {
        return pos_run_isolated_card_gateway($gateway, $adapter, $txnType, $params);
    }

    $params = pos_prepare_operation_payload($txnType, $params);
    $txnType = strtolower((string) ($params['txn_type'] ?? $txnType));

    if ($adapter === 'payram') {
        if (in_array($txnType, ['refund', 'avoid'], true)) {
            return ['success' => false, 'message' => 'PayRam POS refund/avoid is handled on the PayRam invoice, not as a card void.'];
        }
        require_once POS_APP_ROOT . '/lib/PayRamAdapter.php';
        $payram = new PayRamAdapter();
        $created = $payram->createPayment([
            'amount' => (float)($params['amount'] ?? 0),
            'email' => $params['email'] ?? '',
            'customer_id' => $params['user_token_id'] ?? ('pos_' . time()),
        ]);
        if (empty($created['success'])) {
            return $created;
        }
        return pos_format_gateway_result([
            'success' => false,
            'requires_3ds' => true,
            'redirect_url' => $created['url'] ?? '',
            'reference_id' => $created['reference_id'] ?? '',
            'transaction_id' => $created['reference_id'] ?? '',
            'rrn' => $created['reference_id'] ?? '',
            'message' => 'Open PayRam and complete the purchase. Funds stay on PayRam.',
            'raw' => $created,
        ]);
    }

    if ($adapter === 'whop') {
        if (in_array($txnType, ['refund', 'avoid'], true)) {
            return ['success' => false, 'message' => 'Whop refund/avoid stays on Whop.'];
        }
        require_once POS_APP_ROOT . '/lib/Adapters/WhopAdapter.php';
        $whop = new WhopAdapter();
        $link = $whop->createPaymentLink($params);
        if (empty($link['success'])) {
            return $link;
        }
        return pos_format_gateway_result([
            'success' => false,
            'requires_3ds' => true,
            'redirect_url' => $link['checkout_url'] ?? '',
            'payment_id' => $link['payment_id'] ?? '',
            'transaction_id' => $link['payment_id'] ?? '',
            'rrn' => $link['payment_id'] ?? '',
            'message' => 'Open Whop, complete the purchase, then net USDT → Ledger.',
            'raw' => $link,
        ]);
    }

    if ($adapter === 'wise') {
        if (in_array($txnType, ['refund', 'avoid'], true)) {
            return ['success' => false, 'message' => 'Wise POS refund/avoid is not a card void. Use the Wise dashboard.'];
        }
        require_once POS_APP_ROOT . '/lib/WiseService.php';
        $wise = WiseService::fromConfig();
        $amount = (float)($params['amount'] ?? 0);
        $currency = strtoupper((string)($params['currency'] ?? 'USD'));
        $target = $currency === 'USDT' ? 'USD' : $currency;
        $quote = $wise->createQuote($amount, $currency === 'USDT' ? 'USD' : $currency, $target);
        if (empty($quote['id'])) {
            return [
                'success' => false,
                'message' => $quote['errors'][0]['message'] ?? ($quote['message'] ?? 'Wise quote failed'),
                'raw' => $quote,
            ];
        }
        return [
            'success' => false,
            'message' => 'Wise quote is pricing only. No simulated approval. Complete a real Wise payment, then Ledger.',
            'transaction_id' => (string)$quote['id'],
            'raw' => $quote,
        ];
    }

    if ($adapter === 'gate_io') {
        require_once POS_APP_ROOT . '/lib/Adapters/GateIOAdapter.php';
        $gate = new GateIOAdapter();
        if ($txnType === 'auth') {
            return $gate->hold($params);
        }
        if ($txnType === 'refund') {
            return ['success' => false, 'message' => 'Gate.io does not support a card refund. Cancel the order with avoid when it is still open.'];
        }
        if ($txnType === 'avoid') {
            $id = (string)($params['related_transaction_id'] ?? $params['orig_ref'] ?? '');
            if ($id === '') {
                return ['success' => false, 'message' => 'Original Gate.io order id required'];
            }
            return $gate->cancel($id, $txnType);
        }
        if ($txnType === 'capture') {
            $id = (string)($params['related_transaction_id'] ?? $params['orig_ref'] ?? '');
            if ($id !== '') {
                return $gate->capture($id, (float)($params['amount'] ?? 0) ?: null);
            }
        }
        return pos_format_gateway_result($gate->charge($params));
    }

    if ($adapter === 'binance') {
        require_once POS_APP_ROOT . '/lib/Adapters/BinanceOTCAdapter.php';
        $binance = new BinanceOTCAdapter();
        $payload = array_merge($params, [
            'from_coin' => strtoupper((string)($params['currency'] ?? 'USDT')) === 'USDT' ? 'USDT' : 'USDT',
            'to_coin' => 'USDT',
            'from_amount' => (float)($params['amount'] ?? 0),
        ]);
        if ($txnType === 'auth') {
            return $binance->hold($payload);
        }
        if ($txnType === 'refund') {
            return ['success' => false, 'message' => 'Binance does not support a card refund. Cancel the order with avoid when it is still open.'];
        }
        if ($txnType === 'avoid') {
            $id = (string)($params['related_transaction_id'] ?? $params['orig_ref'] ?? '');
            if ($id === '') {
                return ['success' => false, 'message' => 'Original Binance order id required'];
            }
            return $binance->cancel($id, $txnType);
        }
        if ($txnType === 'capture') {
            $id = (string)($params['related_transaction_id'] ?? $params['orig_ref'] ?? '');
            if ($id !== '') {
                return $binance->capture($id, (float)($params['amount'] ?? 0) ?: null);
            }
        }
        return pos_format_gateway_result($binance->charge($payload));
    }

    return ['success' => false, 'message' => 'POS adapter not available'];
}

/**
 * POS_WEB → Payment Orchestrator → Provider → Payment Result → Orders
 *
 * Creates a pending order, runs the selected provider, then writes the
 * payment result back onto the same order (DI_PARMA_MYSYSTEM diagram).
 *
 * @param array<string,mixed> $params
 * @return array<string,mixed>
 */
function pos_run_payment_orchestrator(string $gateway, string $txnType, array $params): array
{
    $payment = pos_run_standalone_gateway($gateway, $txnType, $params);
    if (!is_array($payment)) {
        $payment = ['success' => false, 'message' => 'Invalid gateway response'];
    }
    $payment['provider'] = $gateway;
    $payment['reference'] = (string) ($payment['reference'] ?? ($params['reference'] ?? ''));
    $payment['order_persisted'] = false;
    $payment['orchestrator'] = 'gateway';
    $payment['channel'] = (string) ($params['channel'] ?? 'pos');
    $payment['stage'] = 'payment_result';
    return $payment;
}
