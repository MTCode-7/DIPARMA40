<?php
/**
 * DI PARMA | Peer API
 * يربط السيرفر المحلي (XAMPP) بالسيرفر البعيد للسحب والـ webhook من كليهما.
 *
 * GET  ?action=health
 * POST { action: withdraw|sync_txn|forward_webhook, ... }  + HMAC headers
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/peer_link.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = strtolower(trim((string) ($_GET['action'] ?? '')));

if ($method === 'GET' && ($action === 'health' || $action === '')) {
    echo json_encode([
        'success'     => true,
        'role'        => peer_this_role(),
        'site'        => defined('SITE_URL') ? SITE_URL : '',
        'local_url'   => PEER_LOCAL_URL,
        'remote_url'  => PEER_REMOTE_URL,
        'peer_url'    => peer_other_url(),
        'enabled'     => PEER_SYNC_ENABLED,
        'secret_set'  => PEER_SYNC_SECRET !== '',
        'webhooks'    => [
            'generic' => rtrim((string) SITE_URL, '/') . '/api/webhook.php',
            'payram'  => rtrim((string) SITE_URL, '/') . '/api/payram_webhook.php',
            'paypal'  => rtrim((string) SITE_URL, '/') . '/api/paypal.php?action=webhook',
            'whop'    => rtrim((string) SITE_URL, '/') . '/api/whop_webhook.php',
            'diparma' => rtrim((string) SITE_URL, '/') . '/api/webhooks/diparma.php',
        ],
        'withdraw'    => rtrim((string) SITE_URL, '/') . '/api/pos_transaction.php',
    ]);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
if (!peer_verify_request($raw)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid peer signature']);
    exit;
}

$body = json_decode($raw, true) ?: [];
$action = strtolower(trim((string) ($body['action'] ?? $action)));

switch ($action) {
    case 'health':
        echo json_encode(['success' => true, 'role' => peer_this_role()]);
        break;

    case 'withdraw':
        // تنفيذ سحب على هذه العقدة عبر POS API الداخلي
        $payload = $body['payload'] ?? [];
        if (!is_array($payload)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'payload required']);
            exit;
        }
        $txnType = pos_normalize_if_available((string) ($payload['txn_type'] ?? 'withdrawal_pos'));
        if (!in_array($txnType, ['withdrawal_pos', 'withdrawal_nfc', 'cash_advance'], true)) {
            $payload['txn_type'] = 'withdrawal_pos';
        }
        $result = peer_internal_pos($payload);
        echo json_encode($result);
        break;

    case 'sync_txn':
        $ref = trim((string) ($body['reference'] ?? ''));
        if ($ref === '') {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'reference required']);
            exit;
        }
        try {
            $db = db();
            $existing = $db->find('transactions', ['reference' => $ref]);
            $row = [
                'reference'        => $ref,
                'gateway'          => $body['gateway'] ?? 'peer',
                'amount'           => (float) ($body['amount'] ?? 0),
                'currency'         => strtoupper((string) ($body['currency'] ?? 'USD')),
                'status'           => $body['status'] ?? 'completed',
                'transaction_type' => $body['txn_type'] ?? 'withdrawal_pos',
                'notes'            => 'peer:' . ($body['origin'] ?? 'unknown'),
                'gateway_response' => json_encode($body['gateway_response'] ?? $body, JSON_UNESCAPED_UNICODE),
            ];
            if ($existing) {
                $db->update('transactions', $row, ['reference' => $ref]);
            } else {
                $row['created_at'] = date('Y-m-d H:i:s');
                $db->insert('transactions', $row);
            }
            echo json_encode(['success' => true, 'reference' => $ref, 'role' => peer_this_role()]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    case 'forward_webhook':
        echo json_encode(['success' => true, 'message' => 'accepted', 'role' => peer_this_role()]);
        break;

    case 'auto_update':
        require_once __DIR__ . '/../lib/AutoUpdateService.php';
        echo json_encode(AutoUpdateService::pull(
            (string) ($body['reason'] ?? 'peer'),
            !empty($body['force'])
        ), JSON_UNESCAPED_UNICODE);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown peer action']);
}

function pos_normalize_if_available(string $type): string
{
    if (!function_exists('pos_normalize_operation')) {
        $ops = __DIR__ . '/../includes/pos_operations.php';
        if (is_file($ops)) {
            require_once $ops;
        }
    }
    return function_exists('pos_normalize_operation') ? pos_normalize_operation($type) : $type;
}

function peer_internal_pos(array $payload): array
{
    $url = rtrim((string) SITE_URL, '/') . '/api/pos_transaction.php';
    if (empty($payload['csrf_token']) && session_status() === PHP_SESSION_ACTIVE && function_exists('generateCsrfToken')) {
        $payload['csrf_token'] = generateCsrfToken();
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Peer-Internal: 1'],
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        return ['success' => false, 'http' => $code, 'message' => 'POS peer call failed'];
    }
    $data['peer_role'] = peer_this_role();
    return $data;
}
