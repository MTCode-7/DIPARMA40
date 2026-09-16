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

$ledgerAddr = $client['ledger_address'] ?? (defined('LEDGER_TRC20_ADDRESS') ? LEDGER_TRC20_ADDRESS : '');

// رصيد عبر TronGrid (Tronscan يعيد 401 بدون مفتاح)
$trxBal = 0; $usdtBal = 0;
$usdtContract = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
try {
    if ($ledgerAddr !== '' && preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $ledgerAddr)) {
        $ch = curl_init('https://api.trongrid.io/v1/accounts/' . rawurlencode($ledgerAddr));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $r = curl_exec($ch);
        curl_close($ch);
        if ($r) {
            $d = json_decode($r, true);
            $acc = $d['data'][0] ?? [];
            $trxBal = round(floatval($acc['balance'] ?? 0) / 1e6, 4);
            foreach (($acc['trc20'] ?? []) as $row) {
                if (is_array($row) && isset($row[$usdtContract])) {
                    $usdtBal = round(floatval($row[$usdtContract]) / 1e6, 2);
                    break;
                }
            }
        }
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
