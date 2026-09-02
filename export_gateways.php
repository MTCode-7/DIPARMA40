<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';

$db = db();
$gateways = $db->query("SELECT * FROM dp_payment_gateways ORDER BY status DESC, name ASC");

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="diparma_gateways_' . date('Y-m-d') . '.xls"');
header('Pragma: no-cache');

echo chr(0xEF).chr(0xBB).chr(0xBF);
echo "<table border='1'>";
echo "<tr style='background:#1a1a2e;color:#FFD700;font-weight:bold'>
<td>ط§ظ„ظƒظˆط¯</td>
<td>ط§ظ„ط§ط³ظ…</td>
<td>ط§ظ„ظ†ظˆط¹</td>
<td>ط§ظ„ط­ط§ظ„ط©</td>
<td>ط§ظ„ظ…ظ†ط·ظ‚ط©</td>
<td>ط§ظ„ط±ط³ظˆظ… %</td>
<td>ط§ظ„ط­ط¯ ط§ظ„ط£ط¯ظ†ظ‰ USD</td>
<td>ط§ظ„ط­ط¯ ط§ظ„ظٹظˆظ…ظٹ USD</td>
<td>API Key</td>
<td>Secret Key</td>
<td>Merchant ID</td>
<td>Webhook URL</td>
<td>Success URL</td>
<td>ط§ظ„ط¨ظٹط¦ط©</td>
<td>ط§ظ„ظ…ظٹط²ط§طھ</td>
<td>ط£ظ†ظˆط§ط¹ ط§ظ„ط¨ط·ط§ظ‚ط§طھ</td>
<td>ط§ظ„ط¹ظ…ظ„ط§طھ</td>
<td>ط§ظ„ظˆطµظپ</td>
<td>ط±ط§ط¨ط· ط§ظ„طھط³ط¬ظٹظ„</td>
</tr>";

$registrationLinks = [
    'stripe'      => 'https://dashboard.stripe.com/register',
    'paypal'      => 'https://developer.paypal.com',
    'wise'        => 'https://wise.com/gb/business',
    'myfatoorah'  => 'https://portal.myfatoorah.com',
    'moonpay'     => 'https://www.moonpay.com/business',
    'transak'     => 'https://transak.com',
    'banxa'       => 'https://banxa.com',
    'mercuryo'    => 'https://mercuryo.io/business',
    'simplex'     => 'https://simplex.com',
    'ramp'        => 'https://ramp.network',
    'checkout'    => 'https://dashboard.checkout.com',
    'adyen'       => 'https://www.adyen.com',
    'binance'     => 'https://pay.binance.com',
    'coinbase'    => 'https://commerce.coinbase.com',
    'payfort'     => 'https://www.payfort.com',
    'hyperpay'    => 'https://www.hyperpay.com',
    'tap'         => 'https://www.tap.company',
    'paytabs'     => 'https://www.paytabs.com',
    'telr'        => 'https://telr.com',
    'stcpay'      => 'https://b2b.stcpay.com.sa',
    'knet'        => 'https://www.knet.com.kw',
    'apple_pay'   => 'https://developer.apple.com/apple-pay',
    'google_pay'  => 'https://pay.google.com/business',
    'amazon_pay'  => 'https://pay.amazon.com',
    'razorpay'    => 'https://razorpay.com',
    'paymob'      => 'https://paymob.com',
    'fawry'       => 'https://developer.fawrystaging.com',
    'flutterwave' => 'https://flutterwave.com/us/business',
    'paystack'    => 'https://paystack.com',
    'mpesa'       => 'https://developer.safaricom.co.ke',
    'klarna'      => 'https://developers.klarna.com',
    'revolut'     => 'https://developer.revolut.com',
    'square'      => 'https://developer.squareup.com',
    'braintree'   => 'https://developer.paypal.com/braintree',
    'alipay'      => 'https://global.alipay.com/platform',
    'wechat_pay'  => 'https://pay.weixin.qq.com',
];

foreach ($gateways as $gw) {
    $config   = json_decode($gw['config']      ?? '{}', true) ?: [];
    $creds    = json_decode($gw['credentials'] ?? '{}', true) ?: [];
    $settings = json_decode($gw['settings']    ?? '{}', true) ?: [];
    $fees     = $config['fees']   ?? [];
    $limits   = $config['limits'] ?? [];

    $apiKey    = $creds['api_key']    ?? $creds['client_id']    ?? '';
    $secretKey = $creds['secret_key'] ?? $creds['secret']       ?? $creds['client_secret'] ?? '';
    $merchantId= $creds['merchant_id']?? $creds['merchant_account'] ?? $creds['store_id'] ?? '';

    $status = $gw['status'] === 'active' ? 'ظ†ط´ط· âœ“' : 'ط؛ظٹط± ظ†ط´ط·';
    $bgColor = $gw['status'] === 'active' ? '#e8f5e9' : '#fff';

    echo "<tr style='background:{$bgColor}'>
    <td><b>{$gw['code']}</b></td>
    <td>{$gw['name']}</td>
    <td>{$gw['type']}</td>
    <td>{$status}</td>
    <td>" . ($config['region'] ?? '-') . "</td>
    <td>" . ($fees['percentage'] ?? 0) . "%</td>
    <td>" . ($limits['min'] ?? 0) . "</td>
    <td>" . number_format($limits['max_daily'] ?? 0) . "</td>
    <td>" . (empty($apiKey) ? '(ظپط§ط±ط؛)' : $apiKey) . "</td>
    <td>" . (empty($secretKey) ? '(ظپط§ط±ط؛)' : $secretKey) . "</td>
    <td>" . (empty($merchantId) ? '-' : $merchantId) . "</td>
    <td>" . ($settings['webhook_url'] ?? $settings['webhook'] ?? '-') . "</td>
    <td>" . ($settings['success_url'] ?? $config['urls']['success'] ?? '-') . "</td>
    <td>" . ($settings['environment'] ?? $config['environment'] ?? 'sandbox') . "</td>
    <td>" . implode(', ', $config['features'] ?? []) . "</td>
    <td>" . implode(', ', $config['card_types'] ?? []) . "</td>
    <td>" . implode(', ', array_slice($config['currencies'] ?? [], 0, 5)) . "</td>
    <td>" . ($config['description'] ?? '-') . "</td>
    <td>" . ($registrationLinks[$gw['code']] ?? '-') . "</td>
    </tr>";
}
echo "</table>";

