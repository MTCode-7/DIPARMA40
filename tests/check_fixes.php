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
foreach (['binance', 'payram', 'whop', 'wise', 'stripe', 'nuvei', 'square', 'paypal'] as $gateway) {
    check(GatewayAdapterFactory::isSupported($gateway), $gateway . ' is registered');
}
foreach (['square', 'paypal', 'stripe', 'nuvei'] as $gateway) {
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
check(!dp_gateway_is_live_for_charge(['code' => 'square_online', 'status' => 'active', 'connection_status' => 'verified', 'credentials' => '{"access_token":"token","location_id":"loc","site_id":"site"}']), 'Square 2 cannot process card charges');
check(!dp_gateway_is_live_for_fulfillment(['code' => 'square', 'status' => 'active', 'connection_status' => 'verified', 'credentials' => '{"access_token":"token","location_id":"loc","site_id":"site"}']), 'Square 1 credentials cannot enable Square 2 fulfillment');
check(dp_gateway_is_live_for_fulfillment(['code' => 'square_online', 'status' => 'active', 'connection_status' => 'verified', 'credentials' => '{"access_token":"token","location_id":"loc","site_id":"site"}']), 'Square 2 fulfillment requires its own verified connection');
check(activity_gateway_checkout_operations('stripe') === ['purchase_3d', 'purchase_2d', 'online_sale_moto', 'offline_sale_moto', 'purchase_advice', 'auth', 'capture'], 'Card gateway operations match the POS charge contract');
$stickerRows = pos_ops_sticker_rows();
check(count(array_filter($stickerRows, static fn($row) => !empty($row['card']) && $row['cvv'] !== 'optional')) === 0, 'CVV is optional on every card operation');
check(count(array_filter($stickerRows, static fn($row) => empty($row['card']) && $row['cvv'] !== false)) === 0, 'Non-card operations do not request CVV');
check(!dp_gateway_is_live_for_charge(['code' => 'stripe', 'status' => 'active', 'connection_status' => 'pending', 'credentials' => '{}']), 'Active but unverified gateways stay hidden');
check(!dp_gateway_is_live_for_charge(['code' => 'stripe', 'status' => 'inactive', 'connection_status' => 'verified', 'credentials' => '{}']), 'Inactive gateways stay hidden');
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
