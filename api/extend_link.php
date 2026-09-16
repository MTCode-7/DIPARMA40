<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST only']);
    exit;
}

$payload = $_POST;
if (empty($payload['csrf_token'])) {
    $json = json_decode((string) file_get_contents('php://input'), true);
    if (is_array($json)) {
        $payload = array_merge($payload, $json);
    }
}

if (!verifyCsrfToken((string) ($payload['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$id = (int) ($payload['id'] ?? 0);
$days = (int) ($payload['days'] ?? 0);
if ($id <= 0 || $days < 1 || $days > 365) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'id and days (1-365) required']);
    exit;
}

$db = db();
$link = $db->find('payment_links', ['id' => $id, 'user_id' => (int) $_SESSION['user_id']]);
if (!$link) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Link not found']);
    exit;
}

$base = strtotime((string) ($link['expiry_date'] ?? '')) ?: time();
if ($base < time()) {
    $base = time();
}
$expiry = date('Y-m-d H:i:s', $base + ($days * 86400));
$db->update('payment_links', ['expiry_date' => $expiry], ['id' => $id, 'user_id' => (int) $_SESSION['user_id']]);

echo json_encode([
    'success' => true,
    'message' => 'Link extended',
    'expiry_date' => $expiry,
], JSON_UNESCAPED_UNICODE);
