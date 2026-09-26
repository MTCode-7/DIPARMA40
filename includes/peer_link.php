<?php
/**
 * DI PARMA | Peer link — local XAMPP ↔ remote production
 * يسمح بتنفيذ السحب ومزامنة الـ webhook من أي من العقدتين.
 */

if (!defined('PEER_LOCAL_URL')) {
    define('PEER_LOCAL_URL', rtrim((string) env('PEER_LOCAL_URL', 'http://localhost:8080/DIPARMA40'), '/'));
}
if (!defined('PEER_REMOTE_URL')) {
    define('PEER_REMOTE_URL', rtrim((string) env('PEER_REMOTE_URL', 'https://diparmas.com'), '/'));
}
if (!defined('PEER_SYNC_SECRET')) {
    define('PEER_SYNC_SECRET', (string) env('PEER_SYNC_SECRET', env('WEBHOOK_HMAC_SECRET', '')));
}
if (!defined('PEER_SYNC_ENABLED')) {
    define('PEER_SYNC_ENABLED', filter_var(env('PEER_SYNC_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN));
}

function peer_this_role(): string
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if (str_contains($host, 'localhost') || str_contains($host, '127.0.0.1') || defined('APP_IS_LOCAL') && APP_IS_LOCAL) {
        return 'local';
    }
    return 'remote';
}

function peer_other_url(): string
{
    return peer_this_role() === 'local' ? PEER_REMOTE_URL : PEER_LOCAL_URL;
}

function peer_sign(string $body, string $ts): string
{
    return hash_hmac('sha256', $ts . '.' . $body, PEER_SYNC_SECRET);
}

function peer_verify_request(string $rawBody): bool
{
    if (PEER_SYNC_SECRET === '') {
        return false;
    }
    $ts  = (string) ($_SERVER['HTTP_X_PEER_TS'] ?? '');
    $sig = (string) ($_SERVER['HTTP_X_PEER_SIGNATURE'] ?? '');
    if ($ts === '' || $sig === '' || !ctype_digit($ts)) {
        return false;
    }
    if (abs(time() - (int) $ts) > 300) {
        return false;
    }
    return hash_equals(peer_sign($rawBody, $ts), $sig);
}

/**
 * استدعاء العقدة الأخرى.
 * @return array{success:bool,http?:int,data?:array,message?:string}
 */
function peer_request(string $action, array $payload = [], int $timeout = 20): array
{
    if (!PEER_SYNC_ENABLED) {
        return ['success' => false, 'message' => 'Peer sync disabled'];
    }
    if (PEER_SYNC_SECRET === '') {
        return ['success' => false, 'message' => 'PEER_SYNC_SECRET is empty'];
    }
    $url = peer_other_url() . '/api/peer.php';
    $payload['action'] = $action;
    $payload['origin'] = peer_this_role();
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $ts = (string) time();
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Peer-Ts: ' . $ts,
            'X-Peer-Signature: ' . peer_sign($body, $ts),
            'X-Peer-Origin: ' . peer_this_role(),
        ],
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        return ['success' => false, 'http' => $code, 'message' => $err ?: 'Peer unreachable'];
    }
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        return ['success' => false, 'http' => $code, 'message' => 'Invalid peer JSON', 'raw' => substr((string) $raw, 0, 200)];
    }
    $data['http'] = $code;
    return $data;
}

function peer_notify_async(string $action, array $payload): void
{
    try {
        $result = peer_request($action, $payload, 12);
        error_log('[Peer] ' . $action . ' → ' . ($result['success'] ? 'ok' : ($result['message'] ?? 'fail')));
    } catch (Throwable $e) {
        error_log('[Peer] ' . $action . ' error: ' . $e->getMessage());
    }
}

function peer_status_rank(string $status): int
{
    $status = strtolower(trim($status));
    if (in_array($status, ['completed', 'captured', 'settled', 'approved', 'refunded'], true)) {
        return 3;
    }
    if ($status === 'authorized') {
        return 2;
    }
    if (in_array($status, ['pending', 'processing', 'pending_ledger'], true)) {
        return 1;
    }
    return 0;
}

function peer_txn_export_row(array $row): array
{
    $hang = function_exists('diparma_transaction_hang_reason')
        ? diparma_transaction_hang_reason($row)
        : [];
    return [
        'reference' => (string) ($row['reference'] ?? ''),
        'gateway' => (string) ($row['gateway'] ?? ''),
        'amount' => (float) ($row['amount'] ?? 0),
        'currency' => strtoupper((string) ($row['currency'] ?? 'USD')),
        'status' => (string) ($row['status'] ?? ''),
        'txn_type' => (string) ($row['transaction_type'] ?? ''),
        'transaction_label' => (string) ($row['transaction_label'] ?? ''),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'hang_live_status' => (string) ($hang['live_status'] ?? ''),
        'hang_code' => (string) ($hang['code'] ?? ''),
    ];
}

function peer_upsert_txn(array $body): array
{
    $ref = trim((string) ($body['reference'] ?? ''));
    if ($ref === '' || !preg_match('/^[A-Za-z0-9._:-]{6,80}$/', $ref)) {
        return ['success' => false, 'message' => 'reference required'];
    }
    $db = db();
    $existing = $db->find('transactions', ['reference' => $ref]);
    $incomingStatus = strtolower(trim((string) ($body['status'] ?? 'pending')));
    if ($existing && peer_status_rank($incomingStatus) < peer_status_rank((string) ($existing['status'] ?? ''))) {
        return ['success' => true, 'reference' => $ref, 'kept' => (string) $existing['status']];
    }
    $blob = [];
    if ($existing && !empty($existing['gateway_response'])) {
        $decoded = json_decode((string) $existing['gateway_response'], true);
        if (is_array($decoded)) {
            $blob = $decoded;
        }
    }
    if (!empty($body['gateway_response']) && is_array($body['gateway_response'])) {
        $blob = array_merge($blob, $body['gateway_response']);
    }
    $blob['peer_origin'] = (string) ($body['origin'] ?? 'peer');
    $blob['peer_synced_at'] = date('c');
    if (!empty($body['hang_live_status'])) {
        $blob['payram_live_status'] = (string) $body['hang_live_status'];
    }
    $row = [
        'reference' => $ref,
        'gateway' => (string) ($body['gateway'] ?? ($existing['gateway'] ?? 'peer')),
        'amount' => (float) ($body['amount'] ?? ($existing['amount'] ?? 0)),
        'currency' => strtoupper((string) ($body['currency'] ?? ($existing['currency'] ?? 'USD'))),
        'status' => $incomingStatus !== '' ? $incomingStatus : 'pending',
        'transaction_type' => (string) ($body['txn_type'] ?? $body['transaction_type'] ?? ($existing['transaction_type'] ?? 'purchase')),
        'transaction_label' => (string) ($body['transaction_label'] ?? ($existing['transaction_label'] ?? '')),
        'gateway_response' => json_encode($blob, JSON_UNESCAPED_UNICODE),
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    if ($existing) {
        $db->update('transactions', $row, ['reference' => $ref]);
    } else {
        $row['created_at'] = (string) ($body['created_at'] ?? date('Y-m-d H:i:s'));
        if (method_exists($db, 'insertAvailable')) {
            $db->insertAvailable('transactions', $row);
        } else {
            $db->insert('transactions', $row);
        }
    }
    return ['success' => true, 'reference' => $ref, 'status' => $row['status']];
}

function diparma_peer_pull_transactions(int $limit = 100): array
{
    if (!PEER_SYNC_ENABLED || PEER_SYNC_SECRET === '') {
        return ['success' => false, 'pulled' => 0, 'message' => 'Peer sync is not configured'];
    }
    $result = peer_request('list_txns', ['limit' => $limit], 15);
    $rows = $result['transactions'] ?? [];
    if (!is_array($rows)) {
        return ['success' => false, 'pulled' => 0, 'message' => $result['message'] ?? 'Peer list failed'];
    }
    $pulled = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $row['origin'] = $result['role'] ?? 'peer';
        $upsert = peer_upsert_txn($row);
        if (!empty($upsert['success'])) {
            $pulled++;
        }
    }
    if (class_exists('DPCache')) {
        DPCache::delete('recent_txn_10');
        DPCache::delete('dashboard_stats_30');
    }
    return [
        'success' => !empty($result['success']),
        'pulled' => $pulled,
        'role' => $result['role'] ?? peer_this_role(),
        'message' => $result['message'] ?? '',
    ];
}

function diparma_peer_push_txn(array $txn): void
{
    if (!PEER_SYNC_ENABLED || PEER_SYNC_SECRET === '' || empty($txn['reference'])) {
        return;
    }
    $payload = peer_txn_export_row($txn);
    $payload['origin'] = peer_this_role();
    if (!empty($txn['gateway_response']) && is_array($txn['gateway_response'])) {
        $payload['gateway_response'] = $txn['gateway_response'];
    }
    peer_notify_async('sync_txn', $payload);
}
