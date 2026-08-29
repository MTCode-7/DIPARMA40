<?php
/**
 * DI PARMA | GET /api/v1/balance
 * استعلام رصيد الـ Ledger + إحصائيات العميل
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/ApiAuth.php';

header('Content-Type: application/json');
$client = ApiAuth::verify();

$ledgerAddr = $client['ledger_address'] ?? 'TEwLFWlwK55b7PuFfzgH1H2f3xs3pLgLn2';

// جلب رصيد TronScan
$trxBal = 0; $usdtBal = 0;
try {
    $r = @file_get_contents("https://apilist.tronscanapi.com/api/accountv2?address={$ledgerAddr}");
    if ($r) {
        $d = json_decode($r, true);
        $trxBal  = round(floatval($d['balance'] ?? 0) / 1e6, 4);
        $usdt    = array_filter($d['trc20token_balances'] ?? [], fn($t) => $t['tokenAbbr'] === 'USDT');
        $usdtBal = $usdt ? round(floatval(reset($usdt)['balance'] ?? 0) / 1e6, 2) : 0;
    }
} catch (Exception $e) {}

// إحصائيات العميل
$db = db();
$stats = $db->query(
    "SELECT COUNT(*) cnt, COALESCE(SUM(amount),0) total FROM dp_transactions WHERE gateway='nuvei_api' AND status='completed'",
    []
)[0] ?? ['cnt'=>0,'total'=>0];

echo json_encode([
    'success'        => true,
    'mid'            => $client['mid'],
    'tid'            => $client['tid'],
    'ledger_address' => $ledgerAddr,
    'ledger_trx'     => $trxBal,
    'ledger_usdt'    => $usdtBal,
    'tronscan'       => "https://tronscan.org/#/address/{$ledgerAddr}",
    'account_stats'  => [
        'total_charged' => floatval($client['total_charged'] ?? 0),
        'total_txns'    => intval($client['total_txns'] ?? 0),
        'daily_limit'   => floatval($client['daily_limit'] ?? 50000),
        'monthly_limit' => floatval($client['monthly_limit'] ?? 500000),
    ],
    'timestamp'      => date('c'),
]);
