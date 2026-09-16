<?php
/**
 * DI PARMA | Ledger Tron proxy
 * رصيد + سجل عبر TronGrid من الخادم (بدون Tronscan 401 / CORS)
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action  = strtolower(trim($_GET['action'] ?? 'balance'));
$address = trim($_GET['address'] ?? '');

if ($address === '' && defined('LEDGER_TRC20_ADDRESS')) {
    $address = (string) LEDGER_TRC20_ADDRESS;
}

if (!preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $address)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid Tron address']);
    exit;
}

const USDT_CONTRACT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

function trongrid_get(string $url): array
{
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    $apiKey = env('TRONGRID_API_KEY', getenv('TRONGRID_API_KEY') ?: '');
    if ($apiKey !== '') {
        $headers[] = 'TRON-PRO-API-KEY: ' . $apiKey;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false || $code >= 400) {
        throw new RuntimeException($err !== '' ? $err : ('TronGrid HTTP ' . $code));
    }
    $json = json_decode($body, true);
    if (!is_array($json)) {
        throw new RuntimeException('Invalid TronGrid JSON');
    }
    return $json;
}

function fetch_trx_price_usd(): float
{
    // سعر تقريبي للعرض فقط
    try {
        $ch = curl_init('https://api.coingecko.com/api/v3/simple/price?ids=tron&vs_currencies=usd');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        if ($body) {
            $d = json_decode($body, true);
            $p = floatval($d['tron']['usd'] ?? 0);
            if ($p > 0) {
                return $p;
            }
        }
    } catch (Throwable $e) {
    }
    return 0.12;
}

try {
    if ($action === 'transactions' || $action === 'history') {
        $limit = max(1, min(50, (int) ($_GET['limit'] ?? 25)));
        $payload = trongrid_get(
            'https://api.trongrid.io/v1/accounts/' . rawurlencode($address)
            . '/transactions?limit=' . $limit . '&only_confirmed=true'
        );
        $rows = $payload['data'] ?? [];
        $out  = [];
        foreach ($rows as $tx) {
            $raw = $tx['raw_data']['contract'][0] ?? [];
            $val = $raw['parameter']['value'] ?? [];
            $amountSun = (int) ($val['amount'] ?? 0);
            $toHex   = $val['to_address'] ?? '';
            $fromHex = $val['owner_address'] ?? '';
            // TronGrid يعيد عناوين hex أحياناً — نعرض الاتجاه تقريبياً عبر amount فقط
            $ts = isset($tx['block_timestamp']) ? (int) $tx['block_timestamp'] : 0;
            $hash = $tx['txID'] ?? ($tx['transaction_id'] ?? '');
            $out[] = [
                'hash'      => $hash,
                'amount'    => $amountSun / 1e6,
                'timestamp' => $ts,
                'to'        => $toHex,
                'from'      => $fromHex,
                'confirmed' => !empty($tx['ret'][0]['contractRet']) && $tx['ret'][0]['contractRet'] === 'SUCCESS',
            ];
        }
        echo json_encode([
            'success'   => true,
            'address'   => $address,
            'source'    => 'trongrid',
            'transactions' => $out,
        ]);
        exit;
    }

    // default: balance
    $payload = trongrid_get('https://api.trongrid.io/v1/accounts/' . rawurlencode($address));
    $acc = $payload['data'][0] ?? [];
    $trxBal = round(((float) ($acc['balance'] ?? 0)) / 1e6, 6);
    $usdtBal = 0.0;
    foreach (($acc['trc20'] ?? []) as $row) {
        if (is_array($row) && array_key_exists(USDT_CONTRACT, $row)) {
            $usdtBal = round(((float) $row[USDT_CONTRACT]) / 1e6, 6);
            break;
        }
    }
    $trxPrice = fetch_trx_price_usd();
    $totalUsd = round(($trxBal * $trxPrice) + $usdtBal, 2);

    echo json_encode([
        'success'        => true,
        'address'        => $address,
        'source'         => 'trongrid',
        'trx'            => $trxBal,
        'usdt'           => $usdtBal,
        'trx_price_usd'  => $trxPrice,
        'total_usd'      => $totalUsd,
        'explorer'       => 'https://tronscan.org/#/address/' . $address,
        'note'           => 'Tron account only — not full Ledger Live portfolio',
    ]);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'address' => $address,
    ]);
}
