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
