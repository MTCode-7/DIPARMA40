<?php
require_once __DIR__ . '/../includes/base58.php';
require_once __DIR__ . '/../lib/TronSigner.php';
require_once __DIR__ . '/../lib/MySystem/ChargeHub.php';
require_once __DIR__ . '/../includes/gateways.php';
require_once __DIR__ . '/../includes/activity_flow.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/gateway_channel_bar.php';
require_once __DIR__ . '/../includes/square_sdk.php';
require_once __DIR__ . '/../pos/lib/ops_sticker.php';
require_once __DIR__ . '/../pos/lib/operations.php';
require_once __DIR__ . '/../pos/lib/gateways.php';

$fail = 0;
function check(bool $ok, string $label): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) {
        $fail++;
    }
}

$usdt = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
check(dp_tron_address_hex($usdt) === '41a614f803b6fd780986a42c78ec9c7f77e6ded13c', 'USDT address hex');
check(dp_tron_address_abi($usdt) === '000000000000000000000000a614f803b6fd780986a42c78ec9c7f77e6ded13c', 'USDT ABI word');
check(DiParmaChargeHub::operationFromTxnType('refund') === 'refund', 'refund operation');
check(DiParmaChargeHub::operationFromTxnType('void') === 'cancel', 'void stays cancel');
check(bin2hex(TronSigner::keccak256('')) === 'c5d2460186f7233c927e7db2dcc703c0e500b653ca82273b7bfad8045d85a470', 'keccak256 empty');
$body = hex2bin('417e5f4552091a69125d5dfcb7b8c2659029395bdf');
$check = substr(hash('sha256', hash('sha256', $body, true), true), 0, 4);
$expected = dp_base58_encode($body . $check);
check(TronSigner::addressFromPrivateKey(str_repeat('0', 63) . '1') === $expected, 'key 1 derives its TRON address');

$pub = TronSigner::publicKey(str_repeat('0', 63) . '1');
$gx = '55066263022277343669578718895168534326250603453777594175500187360389116729240';
$gy = '32670510020758816978083085130507043184471273380659243275938904335757337482424';
check($pub[0] === $gx && $pub[1] === $gy, 'private key 1 is the generator');

$hash = hash('sha256', 'diparma-tron-sign');
$sig = TronSigner::signTxId($hash, str_repeat('0', 63) . '1');
check(strlen($sig) === 130, 'signature length');
$recovered = TronSigner::recoverPublicKey($hash, $sig);
check($recovered[0] === $pub[0] && $recovered[1] === $pub[1], 'signature recovers the public key');

require_once __DIR__ . '/../lib/Adapters/GatewayAdapterFactory.php';
require_once __DIR__ . '/../lib/HoldCaptureService.php';
require_once __DIR__ . '/../lib/LedgerSettlementService.php';
require_once __DIR__ . '/../lib/PayPalService.php';
foreach (['binance', 'payram', 'whop', 'wise', 'stripe', 'nuvei', 'square', 'paypal'] as $gateway) {
    check(GatewayAdapterFactory::isSupported($gateway), $gateway . ' is registered');
}
foreach (['square', 'square_online', 'paypal', 'stripe', 'nuvei'] as $gateway) {
    check(pos_gateway_allows_overcapture($gateway), strtoupper($gateway) . ' allows approved over-capture');
    check(HoldCaptureService::allowsOvercapture($gateway), strtoupper($gateway) . ' hold manager allows over-capture');
}
check(!pos_gateway_allows_overcapture('braintree'), 'Other gateways do not allow over-capture');
check(!HoldCaptureService::allowsOvercapture('braintree'), 'Other hold gateways do not allow over-capture');
check(pos_receipt_status(['status' => 'authorized', 'transaction_type' => 'auth']) === 'AUTHORIZED', 'AUTH hold receipt is not shown as captured');
check(pos_receipt_status(['status' => 'completed', 'transaction_type' => 'auth']) === 'AUTHORIZED', 'Completed AUTH operation remains a hold on its receipt');
check(pos_receipt_status(['status' => 'completed', 'transaction_label' => 'AUTH (Hold)']) === 'AUTHORIZED', 'AUTH display label remains a hold on its receipt');
check(pos_receipt_status(['status' => 'completed', 'transaction_type' => 'capture']) === 'APPROVED', 'Captured AUTH receipt is approved');
check(pos_receipt_status(['status' => 'failed', 'transaction_type' => 'purchase_3d']) === 'DECLINED', 'Failed purchase receipt is declined');
check(pos_receipt_status(['status' => 'pending', 'transaction_type' => 'purchase_3d']) === 'PENDING', 'Pending purchase receipt is pending');
check(pos_receipt_status(['status' => 'completed', 'transaction_type' => 'withdrawal_pos']) === 'SUCCESS', 'Completed withdrawal receipt reports success');
$paypalSourceBuilder = new ReflectionMethod(PayPalService::class, 'directCardPaymentSource');
$paypalSourceBuilder->setAccessible(true);
$paypalTestService = PayPalService::getInstance();
$paypalAuthSource = $paypalSourceBuilder->invoke($paypalTestService, [
    'name' => 'Test Customer',
    'number' => '4111111111111111',
    'expiry' => '2030-12',
    'security_code' => '123',
], false, 'TEST-AUTH');
check(isset($paypalAuthSource['card'])
    && !isset($paypalAuthSource['card']['stored_credential']), 'PayPal direct card authorization omits incompatible inferred stored-credential fields');
$paypalThreeDsSource = $paypalSourceBuilder->invoke($paypalTestService, [
    'name' => 'Test Customer',
    'number' => '4111111111111111',
    'expiry' => '2030-12',
], true, 'TEST-3DS');
check(($paypalThreeDsSource['card']['attributes']['verification']['method'] ?? '') === 'SCA_ALWAYS'
    && !isset($paypalThreeDsSource['card']['stored_credential']), 'PayPal 3DS card source keeps verification fields without stored-credential metadata');
check(pos_card_use_alert('PayPal rejected request [INCOMPATIBLE_PARAMETER_VALUE]', '0995')['card_use'] === 'can_use', 'PayPal incompatible request does not incorrectly mark the card as unusable');
$setupReason = 'حساب PayPal الحي غير مفعّل لمدفوعات البطاقة المباشرة (Advanced Credit and Debit Card Payments). التفعيل يتم من لوحة PayPal ولا يمكن للكود فتحه. [PAYEE_NOT_ENABLED_FOR_CARD_PROCESSING]';
$setupAlert = pos_card_use_alert(pos_plain_host_message($setupReason), '1461');
check($setupAlert['card_use'] === 'account_setup', 'Advanced Credit setup is not treated as a host decline');
check($setupAlert['card_use_ar'] === '' && $setupAlert['card_use_en'] === '', 'Advanced Credit setup does not add a do-not-use card alert');
check(pos_card_use_alert('GENERIC DECLINE', '1461')['card_use'] === 'do_not_use', 'Issuer generic decline still blocks immediate retry');
check(pos_card_use_alert('PERMISSION_DENIED', '1461')['card_use'] === 'can_use', 'PayPal account permission does not block the card');
$squareAccount = pos_card_use_alert('PAYMENT_SOURCE_NOT_ENABLED_FOR_TARGET — This account has not been enabled to take payments', '9388');
check($squareAccount['card_use'] === 'account_setup', 'Square account-not-enabled is not a card host decline');
check($squareAccount['card_use_en'] === '' && $squareAccount['card_use_ar'] === '', 'Square account-not-enabled does not tell the cashier to abandon the card');
check(pos_card_use_alert('invalid_client', '1461')['card_use'] === 'can_use', 'PayPal key error does not block the card');
$tidAlert = pos_card_use_alert('Terminal ID (TID) is required.', '4242');
check($tidAlert['card_use'] === 'local_validation', 'Missing TID is local validation, not a host decline');
check($tidAlert['card_use_en'] === '' && $tidAlert['card_use_ar'] === '', 'Missing TID does not add a do-not-use card alert');
check(pos_card_use_alert('Select a real POS model from the catalog. · Enter the full approval code or its 4/6 digit form.', '4242')['card_use'] === 'local_validation', 'POS model and approval checks stay local');
check(pos_card_use_alert('GENERIC DECLINE', '4242')['card_use'] === 'do_not_use', 'Generic decline still blocks retry after the local-validation split');
$paypalField = pos_card_use_alert('The value of a field is invalid. (field: /payment_source/card/stored_credential/payment_type) (PayPal debug_id: e3c9070a574f9) [INVALID_PARAMETER]', '1461');
check($paypalField['card_use'] === 'local_validation', 'PayPal invalid stored_credential field is not a host decline');
check($paypalField['card_use_en'] === '' && $paypalField['card_use_ar'] === '', 'PayPal invalid field does not tell the cashier to abandon the card');
$keyAlert = pos_card_use_alert('ENCRYPTION_KEY is missing or shorter than 32 chars', '9465');
check($keyAlert['card_use'] === 'local_validation' && $keyAlert['card_use_en'] === '', 'A short encryption key does not mark the card unusable');
if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', 'dp_');
}
if (!defined('LOGS_PATH')) {
    define('LOGS_PATH', sys_get_temp_dir());
}
$setupWriter = new class {
    public array $rows = [];
    public function execute($sql) {}
    public function insertAvailable($table, $row) { $this->rows[] = $row; }
};
diparma_record_declined_attempt($setupWriter, [
    'reference' => 'SETUP-CHECK',
    'gateway' => 'paypal',
    'transaction_type' => 'purchase_3d',
    'amount' => 1,
    'currency' => 'USD',
    'reason' => $setupReason,
    'card_last4' => '1461',
    'details' => ['card_last4' => '1461', 'gateway_response' => ['card_last4' => '1461', 'message' => $setupReason]],
]);
$setupRow = $setupWriter->rows[0] ?? [];
$setupDetails = json_decode((string) ($setupRow['details'] ?? ''), true);
check(($setupRow['status'] ?? '') === 'account_setup', 'Advanced Credit attempt is stored as account setup');
check(($setupRow['card_last4'] ?? null) === null, 'Advanced Credit attempt does not store a card tail');
check(is_array($setupDetails) && !isset($setupDetails['card_last4']) && !isset($setupDetails['gateway_response']['card_last4']), 'Advanced Credit details do not keep a card tail');
$hostWriter = new class {
    public array $rows = [];
    public function execute($sql) {}
    public function insertAvailable($table, $row) { $this->rows[] = $row; }
};
diparma_record_declined_attempt($hostWriter, [
    'reference' => 'HOST-CHECK',
    'gateway' => 'paypal',
    'transaction_type' => 'purchase_3d',
    'amount' => 1,
    'currency' => 'USD',
    'reason' => 'GENERIC DECLINE',
    'card_last4' => '1461',
]);
check(($hostWriter->rows[0]['status'] ?? '') === 'declined' && ($hostWriter->rows[0]['card_last4'] ?? '') === '1461', 'Real host decline still records the card tail');
$localWriter = new class {
    public array $rows = [];
    public function execute($sql) {}
    public function insertAvailable($table, $row) { $this->rows[] = $row; }
};
diparma_record_declined_attempt($localWriter, [
    'reference' => 'TID-CHECK',
    'gateway' => 'nuvei',
    'transaction_type' => 'purchase_2d',
    'amount' => 1,
    'currency' => 'USD',
    'reason' => 'Terminal ID (TID) is required.',
    'card_last4' => '4242',
    'details' => ['card_last4' => '4242'],
]);
$localRow = $localWriter->rows[0] ?? [];
check(($localRow['status'] ?? '') === 'validation', 'TID failure is stored as validation, not a host decline');
check(($localRow['card_last4'] ?? null) === null, 'TID failure does not store a card tail');
$paypalAdapter = new PayPalAdapter();
check($paypalAdapter->normalizeError(['details' => [['issue' => 'INCOMPATIBLE_PARAMETER_VALUE']]]) === 'GATEWAY_ERROR', 'PayPal incompatible order parameters are classified as a gateway error');
$gatewayReceiptNames = [
    'paypal' => 'PayPal',
    'nuvei' => 'Nuvei',
    'stripe' => 'Stripe',
    'square' => 'Square 1',
    'square_online' => 'Square 2 · Online',
    'authorizenet' => 'Authorize.Net',
    'braintree' => 'Braintree',
    'myfatoorah' => 'MyFatoorah',
    'gate_io' => 'Gate.io',
    'binance' => 'Binance',
    'payram' => 'PayRam',
    'whop' => 'Whop',
    'wise' => 'Wise',
    'checkout' => 'Checkout.com',
    'paytabs' => 'PayTabs',
    'diparma' => 'DI PARMA',
    'diparma_gateway' => 'DIPARMA GATEWAY',
];
$settlementService = new LedgerSettlementService();
foreach ($gatewayReceiptNames as $gatewayCode => $gatewayName) {
    check(pos_gateway_display_name($gatewayCode) === $gatewayName, strtoupper($gatewayCode) . ' receipt identifies only its own gateway');
    $sameGatewayReceipt = pos_receipt_settlement_display($gatewayCode, 'purchase_3d', 'APPROVED', 'gateway');
    check($sameGatewayReceipt['path'] === $gatewayName . ' → ' . $gatewayName
        && $sameGatewayReceipt['target'] === strtoupper($gatewayName) . ' GATEWAY', strtoupper($gatewayCode) . ' receipt keeps funds on its own gateway');
    check($settlementService->keepsFundsOnGateway($gatewayCode, 'gateway'), strtoupper($gatewayCode) . ' default settlement stays on the selected gateway');
    check(!$settlementService->keepsFundsOnGateway($gatewayCode, 'ledger'), strtoupper($gatewayCode) . ' moves to Ledger only when explicitly selected');
}
check(pos_receipt_settlement_display('paypal', 'auth', 'DECLINED', 'gateway') === [
    'show' => false,
    'path' => '',
    'target' => '',
    'gateway_name' => 'PayPal',
    'funds_on_gateway' => false,
    'show_ledger' => false,
], 'Declined PayPal AUTH receipt does not claim a settlement or ledger transfer');
check(pos_receipt_settlement_display('paypal', 'auth', 'AUTHORIZED', 'ledger')['path'] === 'PayPal → PayPal'
    && pos_receipt_settlement_display('paypal', 'auth', 'AUTHORIZED', 'ledger')['target'] === 'PAYPAL GATEWAY'
    && !pos_receipt_settlement_display('paypal', 'auth', 'AUTHORIZED', 'ledger')['show_ledger'], 'Successful PayPal AUTH remains on PayPal and never routes to Ledger');
check(pos_receipt_settlement_display('paypal', 'purchase_3d', 'APPROVED', 'gateway')['path'] === 'PayPal → PayPal'
    && pos_receipt_settlement_display('paypal', 'purchase_3d', 'APPROVED', 'gateway')['target'] === 'PAYPAL GATEWAY', 'Same-gateway PayPal purchase receipt identifies PayPal as destination');
check(pos_receipt_settlement_display('paypal', 'purchase_3d', 'APPROVED', 'ledger')['path'] === 'PayPal → Ledger'
    && pos_receipt_settlement_display('paypal', 'purchase_3d', 'APPROVED', 'ledger')['show_ledger'], 'Ledger route is shown only when explicitly requested for an approved purchase');
check(pos_gateway_receipt_identifiers([
    'authorization_id' => 'PAYPAL-AUTH-123',
    'approval_code' => 'AB12CD',
]) === [
    'rrn' => '',
    'approval_code' => 'AB12CD',
    'payment_id' => 'PAYPAL-AUTH-123',
    'internal_approval_code' => '',
], 'PayPal AUTH receipt keeps provider ID and approval separate from RRN');
check(pos_gateway_receipt_identifiers([
    'gateway_approval_code' => '',
    'approval_code' => 'P4Y123',
    'rrn' => '',
    'retrieval_reference_number' => 'RRN123456789',
    'payment_id' => '',
    'authorization_id' => 'AUTH123',
])['approval_code'] === 'P4Y123'
    && pos_gateway_receipt_identifiers([
        'gateway_approval_code' => '',
        'approval_code' => 'P4Y123',
        'rrn' => '',
        'retrieval_reference_number' => 'RRN123456789',
    ])['rrn'] === 'RRN123456789'
    && pos_gateway_receipt_identifiers([
        'payment_id' => '',
        'authorization_id' => 'AUTH123',
    ])['payment_id'] === 'AUTH123', 'Receipt identifiers use the first non-empty provider value');
check(pos_gateway_receipt_identifiers([
    'raw' => [
        'purchase_units' => [[
            'payments' => ['authorizations' => [[
                'id' => 'AUTH-123',
                'processor_response' => [
                    'auth_code' => 'P4Y123',
                    'retrieval_reference_number' => 'RRN123456789',
                ],
            ]]],
        ]],
    ],
]) === [
    'rrn' => 'RRN123456789',
    'approval_code' => 'P4Y123',
    'payment_id' => '',
    'internal_approval_code' => '',
], 'Nested gateway AUTH response exposes only its returned RRN and approval code');
check(pos_redact_pci(['client_secret' => 'pi_secret_value'])['client_secret'] === '[redacted]', 'Stripe client secret is removed from stored gateway data');
check(diparma_map_stripe_payment_intent_status('succeeded') === 'completed', 'Stripe 3DS success reconciles to completed');
check(diparma_map_stripe_payment_intent_status('requires_payment_method') === 'failed', 'Stripe failed authentication reconciles to failed');
check(diparma_map_stripe_payment_intent_status('requires_capture') === 'authorized', 'Stripe manual capture status remains an authorization');
$nuveiReflection = new ReflectionClass(NuveiAdapter::class);
$nuveiApprovalMethod = $nuveiReflection->getMethod('nuveiTxnApproved');
$nuveiApprovalMethod->setAccessible(true);
$nuveiApprovalProbe = new NuveiAdapter();
check($nuveiApprovalMethod->invoke($nuveiApprovalProbe, ['transactionStatus' => 'APPROVED']) === true, 'Nuvei APPROVED is accepted as financial approval');
check($nuveiApprovalMethod->invoke($nuveiApprovalProbe, ['status' => 'SUCCESS', 'transactionStatus' => 'SUCCESS']) === false, 'Nuvei SUCCESS without APPROVED is not financial approval');
check($nuveiApprovalMethod->invoke($nuveiApprovalProbe, ['status' => 'SUCCESS', 'transactionStatus' => 'REDIRECT', 'redirectUrl' => 'https://3ds.example.test']) === false, 'Nuvei REDIRECT is not financial approval');
check(activity_gateway_currencies('wise') === ['USD', 'EUR', 'GBP', 'AED'], 'Wise router currencies match its transfer form');
check(activity_gateway_currencies('myfatoorah') === ['KWD', 'SAR', 'AED', 'BHD', 'QAR', 'USD'], 'MyFatoorah router currencies match checkout');
check(activity_gateway_checkout_operations('payram') === ['purchase_3d'], 'PayRam exposes purchase only');
check(activity_gateway_checkout_operations('wise') === [], 'Wise uses transfer flow, not card operations');
check(activity_gateway_checkout_operations('binance') === ['crypto_purchase'], 'Binance exposes crypto purchase only');
check(activity_gateway_checkout_operations('mashreq') === ['purchase_3d'], 'Bank checkout exposes the supported purchase flow');
check(activity_gateway_checkout_operations('square_online') === [], 'Square Online is fulfillment, not a card charge');
check(gateway_offline_daily_limit_usd('nuvei') === 100000000.00, 'Nuvei offline daily limit is 100 million USD');
check(gateway_offline_daily_limit_usd('paypal') === 1000000.00, 'PayPal offline daily limit is 1 million USD');
check(gateway_offline_daily_limit_usd('stripe') === 5000000.00, 'Stripe offline daily limit is 5 million USD');
check(gateway_offline_daily_limit_usd('square') === 10000000.00, 'Square 1 offline daily limit is 10 million USD');
check(gateway_offline_daily_limit_usd('square_online') === 20000000.00, 'Square 2 offline daily limit is 20 million USD');
check(dp_gateway_charge_path_is_complete('square_online'), 'Square 2 has a card charge path');
check(dp_gateway_charge_path_is_complete('square_2') && dp_gateway_charge_path_is_complete('square2'), 'Square 2 aliases share the charge path');
check(dp_gateway_is_customer_visible(['code' => 'square_online', 'status' => 'active', 'connection_status' => 'verified', 'credentials' => '{"access_token":"token","location_id":"loc","site_id":"site"}']), 'Connected active Square 2 is visible on charge surfaces');
$connected = ['status' => 'active', 'connection_status' => 'verified', 'credentials' => '{"api_key":"live-key"}'];
foreach (['nuvei', 'paypal', 'stripe', 'square', 'diparma', 'checkout', 'paytabs', 'authorizenet', 'braintree', 'myfatoorah', 'payram', 'whop', 'gate_io', 'binance'] as $completeCode) {
    check(dp_gateway_charge_path_is_complete($completeCode), $completeCode . ' charge path is complete');
    check(dp_gateway_is_customer_visible($connected + ['code' => $completeCode]), $completeCode . ' is visible when connected and active');
}
foreach (['wise', 'adyen', 'klarna', 'sofort', 'alipay', 'razorpay', 'bank_transfer', 'diparma_gateway', 'mashreq', 'hsbc_uae', 'nbe_egypt', 'jpmorgan', 'ledger'] as $adminOnlyCode) {
    check(!dp_gateway_charge_path_is_complete($adminOnlyCode), $adminOnlyCode . ' stays admin-only');
    check(!dp_gateway_is_customer_visible($connected + ['code' => $adminOnlyCode]), $adminOnlyCode . ' stays hidden when connected');
}
check(!dp_gateway_is_live_for_fulfillment(['code' => 'square', 'status' => 'active', 'connection_status' => 'verified', 'credentials' => '{"access_token":"token","location_id":"loc","site_id":"site"}']), 'Square 1 credentials cannot enable Square 2 fulfillment');
check(dp_gateway_is_live_for_fulfillment(['code' => 'square_online', 'status' => 'active', 'connection_status' => 'verified', 'credentials' => '{"access_token":"token","location_id":"loc","site_id":"site"}']), 'Square 2 fulfillment requires its own verified connection');
check(activity_gateway_checkout_operations('stripe') === ['purchase_3d', 'purchase_2d', 'online_sale_moto', 'offline_sale_moto', 'purchase_advice', 'auth', 'capture'], 'Card gateway operations match the POS charge contract');
$nuveiRoute = static function (string $type, array $entry): array {
    $prepared = pos_prepare_operation_payload($type, array_merge([
        'gateway' => 'nuvei',
        'provider' => 'nuvei',
        'amount' => 1,
        'currency' => 'USD',
    ], $entry));
    return [
        'type' => strtolower((string) ($prepared['txn_type'] ?? $type)),
        'mode' => strtoupper((string) ($prepared['processing_mode'] ?? '')),
        'moto' => !empty($prepared['is_moto']),
        'offline' => !empty($prepared['is_offline']),
    ];
};
$chip = ['input_mode' => 'physical', 'entry_mode' => 'pos_chip', 'card_present' => true];
$keyed = ['input_mode' => 'manual', 'entry_mode' => 'keyed'];
check($nuveiRoute('purchase_2d', $chip) === ['type' => 'purchase_2d', 'mode' => '2D', 'moto' => false, 'offline' => false], 'Chip purchase 2D is a card-present sale');
check($nuveiRoute('purchase_3d', $chip)['mode'] === '3D' && $nuveiRoute('purchase_3d', $chip)['moto'] === false, 'Chip purchase 3D stays on the server 3DS path');
check($nuveiRoute('auth', $chip) === ['type' => 'auth', 'mode' => '2D', 'moto' => false, 'offline' => false], 'Chip auth is a card-present hold');
check($nuveiRoute('purchase_2d', $keyed)['type'] === 'purchase_2d' && $nuveiRoute('purchase_2d', $keyed)['offline'] === false, 'Keyed purchase 2D is sent as chosen and is not forced offline');
$largeUsd = static function (string $type) use ($nuveiRoute, $keyed): array {
    return $nuveiRoute($type, $keyed + ['amount' => 1000, 'currency' => 'USD']);
};
check($largeUsd('purchase_2d')['type'] === 'purchase_2d' && $largeUsd('purchase_2d')['mode'] !== '3D' && $largeUsd('purchase_2d')['offline'] === false, 'Nuvei large USD 2D sale is not forced to 3DS');
check($largeUsd('offline_sale_moto')['type'] === 'offline_sale_moto' && $largeUsd('offline_sale_moto')['offline'] === true, 'Nuvei large USD offline sale stays a direct offline sale');
check($nuveiRoute('purchase_2d', $keyed + ['amount' => 1000, 'currency' => 'AED'])['type'] === 'purchase_2d' && $nuveiRoute('purchase_2d', $keyed + ['amount' => 1000, 'currency' => 'AED'])['offline'] === false, 'Keyed sale stays the operation the operator chose');
check($nuveiRoute('purchase_3d', $keyed)['type'] === 'purchase_3d' && $nuveiRoute('purchase_3d', $keyed)['offline'] === false, 'Keyed purchase 3D stays 3D when the operator chose it');
check($nuveiRoute('purchase_advice', $chip)['mode'] === 'MOTO' && $nuveiRoute('purchase_advice', $chip)['offline'] === false, 'Purchase advice stays a direct MOTO sale');
check($nuveiRoute('offline_sale_moto', $chip)['offline'] === true, 'Offline sale stays offline on the chip path');
check($nuveiRoute('online_sale_moto', $chip)['mode'] === 'MOTO' && $nuveiRoute('online_sale_moto', $chip)['offline'] === false, 'Chip online sale stays MOTO');
$stickerRows = pos_ops_sticker_rows();
check(count(array_filter($stickerRows, static fn($row) => !empty($row['card']) && $row['cvv'] !== 'optional')) === 0, 'CVV is optional on every card operation');
check(count(array_filter($stickerRows, static fn($row) => empty($row['card']) && $row['cvv'] !== false)) === 0, 'Non-card operations do not request CVV');
check(!dp_gateway_is_live_for_charge(['code' => 'stripe', 'status' => 'active', 'connection_status' => 'pending', 'credentials' => '{}']), 'Active but unverified gateways stay hidden');
check(!dp_gateway_is_customer_visible(['code' => 'stripe', 'status' => 'active', 'connection_status' => 'pending', 'credentials' => '{"api_key":"live-key"}']), 'Complete but unverified gateways stay hidden');
check(!dp_gateway_is_live_for_charge(['code' => 'stripe', 'status' => 'inactive', 'connection_status' => 'verified', 'credentials' => '{}']), 'Inactive gateways stay hidden');
check(!dp_gateway_is_customer_visible(['code' => 'stripe', 'status' => 'inactive', 'connection_status' => 'verified', 'credentials' => '{"api_key":"live-key"}']), 'Complete but inactive gateways stay hidden');
$settlementChoices = [
    'gateway' => ['type' => 'same'],
    'ledger' => ['type' => 'ledger'],
    'gateway:paypal' => ['type' => 'forward'],
];
check(activity_normalize_settlement_target('gateway', 'stripe', $settlementChoices) === 'gateway', 'Settlement can stay on the charging gateway');
check(activity_normalize_settlement_target('ledger', 'stripe', $settlementChoices) === 'ledger', 'Settlement can target Ledger');
check(activity_normalize_settlement_target('ledger', 'paypal', activity_settlement_target_choices('paypal', 'checkout')) === 'ledger', 'Ledger target is preserved for gateway checkout');
check(activity_normalize_settlement_target('ledger', 'paypal', activity_settlement_target_choices('paypal', 'pos')) === 'ledger', 'Ledger target is preserved for POS');
check(activity_normalize_settlement_target('ledger', 'paypal', activity_settlement_target_choices('paypal', 'link')) === 'ledger', 'Ledger target is preserved for links');
check(diparma_channel_query(['settlement_target' => 'ledger']) === '?settlement_target=ledger', 'Gateway channel query preserves the selected Ledger destination');
check(activity_normalize_settlement_target('gateway:paypal', 'stripe', $settlementChoices) === 'gateway:paypal', 'Settlement can request another connected gateway');
check(activity_normalize_settlement_target('gateway:square_online', 'paypal', $settlementChoices) === '', 'Square 2 is not a funds-transfer destination');
check(activity_normalize_settlement_target('gateway:stripe', 'stripe', $settlementChoices) === '', 'Settlement cannot forward back to the source gateway');
check(activity_normalize_settlement_target('gateway:offline', 'stripe', $settlementChoices) === '', 'Settlement rejects unavailable gateway targets');
check(pos_is_blocked_test_card('4111111111111111'), 'Common test card number is blocked');
check(pos_is_blocked_test_card('4242424242424242'), 'Stripe test card number is blocked');
check(pos_is_simulation_card_nonce('cnon:card-nonce-ok'), 'Square sandbox success nonce is blocked');
check(pos_is_simulation_card_nonce('cnon:card-nonce-cvv-rejected'), 'Square sandbox decline nonce is blocked');
check(!pos_is_simulation_card_nonce('cnon:card-nonce-live-7a91e31f'), 'Opaque non-fixture Square nonce is not rejected by prefix alone');
check(square_is_simulation_nonce('cnon:card-nonce-ok'), 'Square SDK blocks its known sandbox success nonce');
check(!square_is_simulation_nonce('cnon:card-nonce-live-7a91e31f'), 'Square SDK permits non-fixture live nonce format');
check(!square_credentials_are_live('sq0idb-sandbox-app', 'sq0atp-live-token', 'production'), 'Square sandbox app id overrides a live-looking token');
check(!square_credentials_are_live('sq0idp-live-app', 'sq0atp-live-token', 'sandbox'), 'Square sandbox environment overrides live-looking credentials');
check(!square_credentials_are_live('sq0idp-live-app', 'sq0atb-sandbox-token', 'production'), 'Square sandbox token overrides a live app id');
check(square_credentials_are_live('sq0idp-live-app', 'sq0atp-live-token', 'production'), 'Square production app and token are accepted');

$savedEnvironment = [];
$setTestEnvironment = static function (string $key, string $value) use (&$savedEnvironment): void {
    if (!array_key_exists($key, $savedEnvironment)) {
        $savedEnvironment[$key] = getenv($key);
    }
    putenv($key . '=' . $value);
};
$restoreEnvironment = static function () use (&$savedEnvironment): void {
    foreach ($savedEnvironment as $key => $value) {
        if ($value === false) {
            putenv($key);
        } else {
            putenv($key . '=' . $value);
        }
    }
};

$originalPaypalEnv = [];
foreach (['PAYPAL_CLIENT_ID', 'PAYPAL_CLIENT_SECRET', 'PAYPAL_SECRET'] as $key) {
    $originalPaypalEnv[$key] = [
        'process' => getenv($key),
        'env' => $_ENV[$key] ?? null,
        'env_exists' => array_key_exists($key, $_ENV),
    ];
}
$setTestEnvironment('PAYPAL_CLIENT_ID', 'stale-client-id');
$setTestEnvironment('PAYPAL_CLIENT_SECRET', 'stale-client-secret');
$setTestEnvironment('PAYPAL_SECRET', 'stale-client-secret');
$_ENV['PAYPAL_CLIENT_ID'] = 'stale-client-id';
$_ENV['PAYPAL_CLIENT_SECRET'] = 'stale-client-secret';
$_ENV['PAYPAL_SECRET'] = 'stale-client-secret';
pos_apply_paypal_credentials([
    'client_id' => 'configured-client-id',
    'secret' => '',
    'client_secret' => 'configured-client-secret',
]);
check(getenv('PAYPAL_CLIENT_ID') === 'configured-client-id'
    && getenv('PAYPAL_CLIENT_SECRET') === 'configured-client-secret'
    && getenv('PAYPAL_SECRET') === 'configured-client-secret', 'PayPal saved credentials override stale process environment and accept aliases');
foreach ($originalPaypalEnv as $key => $values) {
    if ($values['process'] === false) {
        putenv($key);
    } else {
        putenv($key . '=' . $values['process']);
    }
    if ($values['env_exists']) {
        $_ENV[$key] = $values['env'];
    } else {
        unset($_ENV[$key]);
    }
}

$setTestEnvironment('AUTHNET_ENVIRONMENT', 'test');
$setTestEnvironment('AUTHNET_API_LOGIN_ID', 'test-login');
$setTestEnvironment('AUTHNET_TRANSACTION_KEY', 'test-transaction-key');
$authnetTestAdapter = new AuthorizeNetAdapter();
$authnetTestPayload = ['reference' => 'TEST-AUTHNET', 'amount' => 1, 'currency' => 'USD'];
$authnetTestOperations = [
    'charge' => static fn() => $authnetTestAdapter->charge($authnetTestPayload),
    'hold' => static fn() => $authnetTestAdapter->hold($authnetTestPayload),
    'capture' => static fn() => $authnetTestAdapter->capture('TEST-TXN', 1),
    'cancel' => static fn() => $authnetTestAdapter->cancel('TEST-TXN'),
];
foreach ($authnetTestOperations as $operation => $runTestOperation) {
    $result = $runTestOperation();
    check(empty($result['success']) && str_contains(strtolower((string) ($result['message'] ?? '')), 'live environment'), 'Authorize.Net test environment blocks ' . $operation);
}
$setTestEnvironment('AUTHNET_ENVIRONMENT', '');
$authnetMissingEnvironment = $authnetTestAdapter->charge($authnetTestPayload);
check(empty($authnetMissingEnvironment['success']) && str_contains(strtolower((string) ($authnetMissingEnvironment['message'] ?? '')), 'live environment'), 'Authorize.Net missing environment fails closed');

$setTestEnvironment('MYFAOORAH_ENVIRONMENT', 'test');
$setTestEnvironment('MYFAOORAH_API_KEY', 'test-key');
$myFatoorahTestCharge = (new MyFatoorahAdapter())->charge(['reference' => 'TEST-MYFATOOORAH', 'amount' => 1, 'currency' => 'USD']);
check(empty($myFatoorahTestCharge['success']) && str_contains(strtolower((string) ($myFatoorahTestCharge['message'] ?? '')), 'live environment'), 'MyFatoorah test environment cannot approve charges');
$myFatoorahTestCancel = (new MyFatoorahAdapter())->cancel('TEST-TXN');
check(empty($myFatoorahTestCancel['success']) && str_contains(strtolower((string) ($myFatoorahTestCancel['message'] ?? '')), 'live environment'), 'MyFatoorah test environment blocks cancellation');
$setTestEnvironment('MYFAOORAH_ENVIRONMENT', '');
$myFatoorahMissingEnvironment = (new MyFatoorahAdapter())->charge(['reference' => 'TEST-MYFATOOORAH', 'amount' => 1, 'currency' => 'USD']);
check(empty($myFatoorahMissingEnvironment['success']) && str_contains(strtolower((string) ($myFatoorahMissingEnvironment['message'] ?? '')), 'live environment'), 'MyFatoorah missing environment fails closed');

$setTestEnvironment('STRIPE_SECRET_KEY', 'sk_test_fake');
$stripeStandardTestCharge = (new StripeAdapter())->charge(['reference' => 'TEST-STRIPE-SK', 'amount' => 1, 'currency' => 'USD']);
check(empty($stripeStandardTestCharge['success']) && str_contains(strtolower((string) ($stripeStandardTestCharge['message'] ?? '')), 'live secret'), 'Stripe standard test keys are rejected');
$setTestEnvironment('STRIPE_SECRET_KEY', 'rk_test_fake');
$setTestEnvironment('STRIPE_PUBLIC_KEY', 'pk_test_fake');
$stripeTestCharge = (new StripeAdapter())->charge(['reference' => 'TEST-STRIPE', 'amount' => 1, 'currency' => 'USD']);
check(empty($stripeTestCharge['success']) && str_contains(strtolower((string) ($stripeTestCharge['message'] ?? '')), 'live secret'), 'Stripe restricted test keys cannot approve charges');

$setTestEnvironment('BRAINTREE_ENVIRONMENT', 'sandbox');
$setTestEnvironment('BRAINTREE_MERCHANT_ID', 'test-merchant');
$setTestEnvironment('BRAINTREE_PUBLIC_KEY', 'test-public');
$setTestEnvironment('BRAINTREE_PRIVATE_KEY', 'test-private');
$braintreeTestAdapter = new BraintreeAdapter();
foreach ([
    'capture' => static fn() => $braintreeTestAdapter->capture('TEST-TXN', 1),
    'cancel' => static fn() => $braintreeTestAdapter->cancel('TEST-TXN'),
] as $operation => $runTestOperation) {
    $result = $runTestOperation();
    check(empty($result['success']) && str_contains(strtolower((string) ($result['message'] ?? '')), 'production environment'), 'Braintree sandbox blocks ' . $operation);
}

$setTestEnvironment('SQUARE_ENVIRONMENT', 'sandbox');
$setTestEnvironment('SQUARE_APPLICATION_ID', 'sq0idb-fake');
$setTestEnvironment('SQUARE_ACCESS_TOKEN', 'sandbox-token');
$setTestEnvironment('SQUARE_LOCATION_ID', 'test-location');
$squareTestAdapter = new SquareAdapter();
foreach ([
    'capture' => static fn() => $squareTestAdapter->capture('TEST-TXN', 1),
    'cancel' => static fn() => $squareTestAdapter->cancel('TEST-TXN'),
    'refund' => static fn() => $squareTestAdapter->refund('TEST-TXN', 1),
] as $operation => $runTestOperation) {
    $result = $runTestOperation();
    check(empty($result['success']) && str_contains(strtolower((string) ($result['message'] ?? '')), 'sandbox'), 'Square sandbox blocks ' . $operation);
}

$setTestEnvironment('PAYPAL_ENVIRONMENT', 'sandbox');
$paypalTestService = PayPalService::getInstance();
foreach ([
    'create order' => static fn() => $paypalTestService->createOrder(1, 'USD', 'TEST-ORDER'),
    'capture authorization' => static fn() => $paypalTestService->captureAuthorization('TEST-AUTH', 1),
    'void authorization' => static fn() => $paypalTestService->voidAuthorization('TEST-AUTH'),
    'refund capture' => static fn() => $paypalTestService->refundCapture('TEST-CAPTURE', 1),
] as $operation => $runTestOperation) {
    $result = $runTestOperation();
    check(empty($result['success']) && str_contains(strtolower((string) ($result['message'] ?? '')), 'live environment'), 'PayPal sandbox blocks ' . $operation);
}
$restoreEnvironment();

exit($fail === 0 ? 0 : 1);
