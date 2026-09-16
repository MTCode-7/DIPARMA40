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
