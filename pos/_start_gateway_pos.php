<?php
/**
 * DI PARMA | POS خاص ببوابة
 * يفتح نقطة البيع على نفس البوابة فقط.
 */
if (empty($gwCode)) {
    header('Location: index.php?kiosk=1');
    exit;
}

$gwCode = strtolower(trim((string) $gwCode));
if (!function_exists('dp_gateway_is_visible_on_channels')) {
    $root = dirname(__DIR__);
    if (is_file($root . '/includes/config.php')) {
        require_once $root . '/includes/config.php';
    }
    if (is_file($root . '/includes/database.php')) {
        require_once $root . '/includes/database.php';
    }
    if (is_file($root . '/includes/gateways.php')) {
        require_once $root . '/includes/gateways.php';
    }
}
if ($gwCode !== 'ledger' && function_exists('dp_gateway_is_visible_on_channels') && !dp_gateway_is_visible_on_channels($gwCode)) {
    header('Location: index.php?kiosk=1', true, 302);
    exit;
}
$qs = [
    'kiosk' => '1',
    'gw' => $gwCode,
];
foreach (['line', 'op', 'device', 'tid', 'mode', 'amount', 'currency'] as $key) {
    $val = trim((string) ($_GET[$key] ?? ''));
    if ($val !== '') {
        $qs[$key] = $val;
    }
}
if ($gwCode === 'ledger' || (isset($_GET['ledger_checkout']) && (string) $_GET['ledger_checkout'] === '1')) {
    $qs['ledger_checkout'] = '1';
    if ($gwCode === 'ledger') {
        unset($qs['gw']);
    }
}

header('Location: index.php?' . http_build_query($qs), true, 302);
exit;
