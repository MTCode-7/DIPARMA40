<?php
/**
 * DI PARMA | Manage transaction (admin)
 * actions: delete | update | clear_failed
 */
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$payload = $_POST;
if (empty($payload['action'])) {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json)) {
        $payload = $json;
    }
}

if (!verifyCsrfToken($payload['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$action = strtolower(trim((string) ($payload['action'] ?? '')));
$db = db();
$table = 'transactions'; // بدون prefix — update/delete يضيفان DB_PREFIX تلقائياً
$tableSql = DB_PREFIX . 'transactions';

$allowedStatuses = [
    'pending', 'authorized', 'captured', 'settled',
    'completed', 'failed', 'refunded', 'chargeback', 'cancelled',
];

try {
    if ($action === 'clear_failed') {
        $cntRow = $db->query("SELECT COUNT(*) AS c FROM {$tableSql} WHERE LOWER(status) = 'failed'");
        $count = (int) ($cntRow[0]['c'] ?? 0);
        $db->execute("DELETE FROM {$tableSql} WHERE LOWER(status) = 'failed'");
        echo json_encode([
            'success' => true,
            'message' => "Deleted {$count} failed transaction(s)",
            'deleted' => $count,
        ]);
        exit;
    }

    if ($action === 'delete') {
        $id = (int) ($payload['id'] ?? 0);
        $ref = trim((string) ($payload['reference'] ?? ''));
        if ($id <= 0 && $ref === '') {
            throw new InvalidArgumentException('id or reference required');
        }
        if ($id > 0) {
            $db->execute("DELETE FROM {$tableSql} WHERE id = ?", [$id]);
        } else {
            $db->execute("DELETE FROM {$tableSql} WHERE reference = ?", [$ref]);
        }
        echo json_encode(['success' => true, 'message' => 'Transaction deleted']);
        exit;
    }

    if ($action === 'update') {
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            throw new InvalidArgumentException('id required');
        }
        $row = $db->query("SELECT id FROM {$tableSql} WHERE id = ? LIMIT 1", [$id]);
        if (empty($row)) {
            throw new RuntimeException('Transaction not found');
        }

        $data = [];
        if (isset($payload['status'])) {
            $st = strtolower(trim((string) $payload['status']));
            if (!in_array($st, $allowedStatuses, true)) {
                throw new InvalidArgumentException('Invalid status');
            }
            $data['status'] = $st;
        }
        if (array_key_exists('amount', $payload) && $payload['amount'] !== '') {
            $amt = (float) $payload['amount'];
            if ($amt < 0) {
                throw new InvalidArgumentException('Invalid amount');
            }
            $data['amount'] = $amt;
        }
        if (array_key_exists('currency', $payload) && $payload['currency'] !== '') {
            $data['currency'] = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) $payload['currency']), 0, 8));
        }
        if (array_key_exists('customer_name', $payload)) {
            $data['customer_name'] = trim((string) $payload['customer_name']);
        }
        if (array_key_exists('customer_email', $payload)) {
            $data['customer_email'] = trim((string) $payload['customer_email']);
        }
        if (array_key_exists('transaction_label', $payload)) {
            $data['transaction_label'] = trim((string) $payload['transaction_label']);
        }
        if (array_key_exists('gateway', $payload) && $payload['gateway'] !== '') {
            $data['gateway'] = trim((string) $payload['gateway']);
        }
        if (array_key_exists('error_message', $payload)) {
            $data['error_message'] = trim((string) $payload['error_message']);
        }

        if (empty($data)) {
            throw new InvalidArgumentException('No fields to update');
        }
        $data['updated_at'] = date('Y-m-d H:i:s');
        $db->update($table, $data, ['id' => $id]);

        echo json_encode(['success' => true, 'message' => 'Transaction updated', 'id' => $id]);
        exit;
    }

    throw new InvalidArgumentException('Unknown action');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
