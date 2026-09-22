<?php
require_once __DIR__ . '/../includes/base58.php';
require_once __DIR__ . '/../lib/TronSigner.php';
require_once __DIR__ . '/../lib/MySystem/ChargeHub.php';

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
foreach (['binance', 'payram', 'whop', 'wise', 'stripe', 'nuvei', 'square', 'paypal'] as $gateway) {
    check(GatewayAdapterFactory::isSupported($gateway), $gateway . ' is registered');
}

exit($fail === 0 ? 0 : 1);
