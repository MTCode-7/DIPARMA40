<?php
/**
 * POS gateways — standalone module. Each gateway runs alone, then net USDT → Ledger.
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
            'desc_ar' => 'Nuvei → Ledger. ' . $acceptNoteAr . ' بعد الموافقة: USDT → Ledger.',
            'desc_en' => 'Nuvei → Ledger. ' . $acceptNoteEn . ' After approval: USDT → Ledger.',
        ],
        'paypal' => [
            'name' => 'PayPal',
            'icon' => 'fab fa-paypal',
            'color' => '#003087',
            'adapter' => 'paypal',
            'rail' => 'card',
            'desc_ar' => 'PayPal → Ledger. ' . $acceptNoteAr . ' بعد الموافقة: USDT → Ledger.',
            'desc_en' => 'PayPal → Ledger. ' . $acceptNoteEn . ' After approval: USDT → Ledger.',
        ],
        'stripe' => [
            'name' => 'Stripe',
            'icon' => 'fab fa-stripe-s',
            'color' => '#6772e5',
            'adapter' => 'stripe',
            'rail' => 'card',
            'desc_ar' => 'Stripe → Ledger. ' . $acceptNoteAr . ' بعد الموافقة: USDT → Ledger.',
            'desc_en' => 'Stripe → Ledger. ' . $acceptNoteEn . ' After approval: USDT → Ledger.',
        ],
        'square' => [
            'name' => 'Square',
            'icon' => 'fas fa-square',
            'color' => '#006AFF',
            'adapter' => 'square',
            'rail' => 'card',
            'desc_ar' => 'Square → Ledger. ' . $acceptNoteAr . ' بعد الموافقة: USDT → Ledger.',
            'desc_en' => 'Square → Ledger. ' . $acceptNoteEn . ' After approval: USDT → Ledger.',
        ],
        'payram' => [
            'name' => 'PayRam',
            'icon' => 'fas fa-server',
            'color' => '#10B981',
            'adapter' => 'payram',
            'rail' => 'redirect',
            'desc_ar' => 'PayRam → Ledger. ' . $acceptNoteAr . ' البطاقة على صفحة PayRam ثم USDT → Ledger.',
            'desc_en' => 'PayRam → Ledger. ' . $acceptNoteEn . ' Card on PayRam page, then USDT → Ledger.',
        ],
        'wise' => [
            'name' => 'Wise',
            'icon' => 'fas fa-exchange-alt',
            'color' => '#9fe870',
            'adapter' => 'wise',
            'rail' => 'wallet',
            'desc_ar' => 'Wise → Ledger. ' . $acceptNoteAr . ' بعد القبول: الصافي USDT → Ledger.',
            'desc_en' => 'Wise → Ledger. ' . $acceptNoteEn . ' After accept: net USDT → Ledger.',
        ],
        'diparma' => [
            'name' => 'DI PARMA',
            'icon' => 'fas fa-coins',
            'color' => '#FFD700',
            'adapter' => 'nuvei',
            'rail' => 'card',
            'desc_ar' => 'DI PARMA → Ledger. ' . $acceptNoteAr . ' بعد الموافقة: USDT → Ledger.',
            'desc_en' => 'DI PARMA → Ledger. ' . $acceptNoteEn . ' After approval: USDT → Ledger.',
        ],
        'diparma_gateway' => [
            'name' => 'DIPARMA GATEWAY',
            'icon' => 'fas fa-credit-card',
            'color' => '#E8C547',
            'adapter' => 'nuvei',
            'rail' => 'card',
            'desc_ar' => 'DIPARMA GATEWAY: ' . $acceptNoteAr . ' بطاقة → Ledger USDT. لا بنك كوجهة.',
            'desc_en' => 'DIPARMA GATEWAY: ' . $acceptNoteEn . ' Card → Ledger USDT. No bank destination.',
        ],
        'whop' => [
            'name' => 'Whop',
            'icon' => 'fas fa-bolt',
            'color' => '#7C3AED',
            'adapter' => 'whop',
            'rail' => 'redirect',
            'desc_ar' => 'Whop → Ledger. ' . $acceptNoteAr . ' بعد إتمام الدفع: USDT → Ledger.',
            'desc_en' => 'Whop → Ledger. ' . $acceptNoteEn . ' After payment: USDT → Ledger.',
        ],
        'gate_io' => [
            'name' => 'Gate.io',
            'icon' => 'fas fa-coins',
            'color' => '#E8112D',
            'adapter' => 'gate_io',
            'rail' => 'crypto',
            'desc_ar' => 'Gate.io → Ledger. ' . $acceptNoteAr . ' بعد القبول: الصافي USDT → Ledger.',
            'desc_en' => 'Gate.io → Ledger. ' . $acceptNoteEn . ' After accept: net USDT → Ledger.',
        ],
        'binance' => [
            'name' => 'Binance',
            'icon' => 'fas fa-coins',
            'color' => '#F3BA2F',
            'adapter' => 'binance',
            'rail' => 'crypto',
            'desc_ar' => 'Binance → Ledger. ' . $acceptNoteAr . ' بعد القبول: الصافي USDT → Ledger.',
            'desc_en' => 'Binance → Ledger. ' . $acceptNoteEn . ' After accept: net USDT → Ledger.',
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
        'gate.io' => 'gate_io',
        'gateio' => 'gate_io',
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
        if (pos_gateway_is_live($code)) {
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
        'purchase_advice', 'capture', 'withdrawal_pos', 'withdrawal_nfc',
    ];
}

function pos_format_gateway_result(array $result, string $fallbackMessage = 'DECLINED'): array
{
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
    $result['rrn'] = $result['rrn'] ?? $result['transaction_id'] ?? $result['reference_id'] ?? $result['payment_id'] ?? '';
    $result['approval_code'] = $result['approval_code'] ?? $result['auth_code'] ?? '';
    return $result;
}

function pos_run_standalone_gateway(string $gateway, string $txnType, array $params): array
{
    $meta = pos_gateway_meta($gateway);
    if (!$meta) {
        return ['success' => false, 'message' => 'Unknown POS gateway'];
    }
    $adapter = $meta['adapter'];

    if ($adapter === 'ledger') {
        $addr = trim((string) ($params['ledger_address'] ?? $params['ledger_addr'] ?? ''));
        if ($addr === '' && defined('LEDGER_TRC20_ADDRESS')) {
            $addr = trim((string) LEDGER_TRC20_ADDRESS);
        }
        if (!preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $addr)) {
            return ['success' => false, 'message' => 'LEDGER_TRC20_ADDRESS is required. DIPARMA GATEWAY is Ledger-only — no bank.'];
        }
        if (in_array($txnType, ['auth', 'capture', 'refund', 'avoid'], true)) {
            return ['success' => false, 'message' => 'DIPARMA GATEWAY has no bank AUTH/capture/refund. Ledger USDT TRC20 only.'];
        }
        require_once POS_APP_ROOT . '/lib/LedgerSettlementService.php';
        $ref = (string) ($params['reference'] ?? ('DGW-' . date('YmdHis')));
        $settle = LedgerSettlementService::getInstance()->settleToLedger([
            'reference' => $ref,
            'amount' => (float) ($params['amount'] ?? 0),
            'currency' => strtoupper((string) ($params['currency'] ?? 'USD')),
            'gateway' => 'diparma_gateway',
            'ledger_address' => $addr,
            'destination' => 'ledger',
            'txn_type' => $txnType,
            'user_id' => (int) ($params['user_id'] ?? 0),
        ]);
        $ok = !empty($settle['success']) && empty($settle['skipped']);
        $txid = $settle['txid'] ?? $settle['tx_hash'] ?? null;
        return pos_format_gateway_result([
            'success' => $ok,
            'message' => $ok
                ? ('LEDGER ' . ($txid ?? $settle['message'] ?? 'OK'))
                : ($settle['message'] ?? 'Ledger transfer failed'),
            'rrn' => $ref,
            'transaction_id' => $txid ?: $ref,
            'approval_code' => '',
            'ledger_address' => $addr,
            'rail' => 'ledger',
            'no_bank' => true,
            'txid' => $txid,
            'queued' => !empty($settle['queued']),
            'raw' => $settle,
        ]);
    }

    if ($adapter === 'nuvei') {
        require_once POS_APP_ROOT . '/lib/Adapters/NuveiAdapter.php';
        $nuvei = new NuveiAdapter();
        switch ($txnType) {
            case 'purchase_3d':
                return $nuvei->purchase3D($params);
            case 'online_sale_moto':
                $params['is_moto'] = true;
                return $nuvei->purchase2D($params);
            case 'offline_sale_moto':
                $params['is_moto'] = true;
                $params['card_cvv'] = '';
                return $nuvei->purchase($params);
            case 'purchase_advice':
                $params['card_cvv'] = '';
                $params['is_moto'] = false;
                return $nuvei->purchase($params);
            case 'auth':
                return $nuvei->authorize($params);
            case 'capture':
                return $nuvei->capture($params);
            case 'refund':
                return $nuvei->refund($params);
            case 'avoid':
                return $nuvei->void($params);
            default:
                return $nuvei->purchase($params);
        }
    }

    if ($adapter === 'stripe') {
        require_once POS_APP_ROOT . '/lib/Adapters/StripeAdapter.php';
        $stripe = new StripeAdapter();
        $payload = array_merge($params, [
            'name' => $params['card_name'] ?? 'CARDHOLDER',
            'processing_mode' => ($txnType === 'purchase_3d') ? '3D' : '2D',
        ]);
        if ($txnType === 'auth') {
            return $stripe->hold($payload);
        }
        if ($txnType === 'capture') {
            $id = (string)($params['related_transaction_id'] ?? $params['orig_ref'] ?? '');
            if ($id === '') {
                return ['success' => false, 'message' => 'Original Stripe id required for capture'];
            }
            return $stripe->capture($id, (float)($params['amount'] ?? 0) ?: null);
        }
        if (in_array($txnType, ['refund', 'avoid'], true)) {
            return ['success' => false, 'message' => 'Refund/Avoid on Stripe POS uses the Stripe dashboard or checkout Stripe page.'];
        }
        return $stripe->charge($payload);
    }

    if ($adapter === 'square') {
        require_once POS_APP_ROOT . '/lib/Adapters/SquareAdapter.php';
        $square = new SquareAdapter();
        $payload = array_merge($params, [
            'name' => $params['card_name'] ?? 'CARDHOLDER',
            'processing_mode' => ($txnType === 'purchase_3d') ? '3D' : '2D',
            'txn_type' => $txnType,
        ]);
        if ($txnType === 'auth') {
            return pos_format_gateway_result($square->hold($payload));
        }
        if ($txnType === 'capture') {
            $id = (string) ($params['related_transaction_id'] ?? $params['orig_ref'] ?? '');
            if ($id === '') {
                return ['success' => false, 'message' => 'Original Square payment id required for capture'];
            }
            return pos_format_gateway_result($square->capture($id, (float) ($params['amount'] ?? 0) ?: null));
        }
        if (in_array($txnType, ['refund', 'avoid'], true)) {
            $id = (string) ($params['related_transaction_id'] ?? $params['orig_ref'] ?? '');
            if ($id === '') {
                return ['success' => false, 'message' => 'Original Square payment id required for cancel'];
            }
            return pos_format_gateway_result($square->cancel($id, $txnType));
        }
        if (in_array($txnType, ['purchase_advice', 'offline_sale_moto', 'online_sale_moto'], true)) {
            $payload['card_cvv'] = $payload['card_cvv'] ?? ($params['card_cvv'] ?? '');
        }
        return pos_format_gateway_result($square->charge($payload));
    }

    if ($adapter === 'paypal') {
        require_once POS_APP_ROOT . '/lib/Adapters/PayPalAdapter.php';
        $paypal = new PayPalAdapter();
        $payload = array_merge($params, [
            'name' => $params['card_name'] ?? 'CARDHOLDER',
            'processing_mode' => ($txnType === 'purchase_3d') ? '3D' : '2D',
            'txn_type' => $txnType,
        ]);
        if ($txnType === 'auth') {
            return pos_format_gateway_result($paypal->hold($payload));
        }
        if ($txnType === 'capture') {
            $id = (string)($params['related_transaction_id'] ?? $params['orig_ref'] ?? '');
            if ($id === '') {
                return ['success' => false, 'message' => 'Original PayPal id required for capture'];
            }
            return pos_format_gateway_result($paypal->capture($id, (float)($params['amount'] ?? 0) ?: null));
        }
        if (in_array($txnType, ['refund', 'avoid'], true)) {
            $id = (string)($params['related_transaction_id'] ?? $params['orig_ref'] ?? '');
            if ($id === '') {
                return ['success' => false, 'message' => 'Original PayPal id required for refund/avoid'];
            }
            return pos_format_gateway_result($paypal->cancel($id, $txnType));
        }
        return pos_format_gateway_result($paypal->charge($payload));
    }

    if ($adapter === 'payram') {
        if (in_array($txnType, ['refund', 'avoid'], true)) {
            return ['success' => false, 'message' => 'PayRam POS refund/avoid is handled on the PayRam invoice, not as a card void.'];
        }
        if ($txnType === 'auth') {
            return ['success' => false, 'message' => 'PayRam POS is sale → Ledger. AUTH hold is not used on this rail.'];
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
            'message' => 'Open PayRam, complete the purchase, then net USDT → Ledger.',
            'raw' => $created,
        ]);
    }

    if ($adapter === 'whop') {
        if (in_array($txnType, ['refund', 'avoid', 'auth'], true)) {
            return ['success' => false, 'message' => 'Whop POS supports sale types → Ledger. AUTH/refund/avoid stay on Whop.'];
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
        if ($txnType === 'auth') {
            return ['success' => false, 'message' => 'Wise POS is sale → Ledger. AUTH hold is not used on this rail.'];
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
        if (in_array($txnType, ['refund', 'avoid'], true)) {
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
        if (in_array($txnType, ['refund', 'avoid'], true)) {
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
    require_once POS_APP_ROOT . '/lib/MySystem/OrdersService.php';
    require_once POS_APP_ROOT . '/lib/MySystem/CustomersService.php';

    $orders = new MySystemOrdersService();
    $customers = new MySystemCustomersService();
    $customer = $customers->resolve($params);

    $input = array_merge($params, [
        'provider' => $gateway,
        'gateway' => $gateway,
        'txn_type' => $txnType,
        'channel' => (string) ($params['channel'] ?? 'pos'),
        'notes' => ((string) ($params['channel'] ?? 'pos')) . '_order',
    ]);

    $create = $orders->create($input, $customer);
    $reference = (string) ($create['reference'] ?? ($params['reference'] ?? ''));
    if (empty($create['success'])) {
        return [
            'success' => false,
            'message' => $create['message'] ?? 'Order create failed',
            'stage' => 'orders',
            'reference' => $reference,
            'orchestrator' => 'di_parma_mysystem',
            'channel' => 'pos_web',
            'order_persisted' => false,
        ];
    }

    $params['reference'] = $reference;
    $payment = pos_run_standalone_gateway($gateway, $txnType, $params);
    if (!is_array($payment)) {
        $payment = ['success' => false, 'message' => 'Invalid provider response'];
    }

    $payment['provider'] = $gateway;
    $payment['reference'] = $reference;
    $applied = $orders->applyPaymentResult($reference, $payment);

    $payment['order_id'] = (int) ($create['order_id'] ?? ($applied['order']['id'] ?? 0));
    $payment['order'] = $applied['order'] ?? null;
    $payment['order_update_ok'] = !empty($applied['success']);
    $payment['order_persisted'] = true;
    $payment['orchestrator'] = 'di_parma_mysystem';
    $payment['channel'] = 'pos_web';
    $payment['stage'] = 'payment_result';

    return $payment;
}
