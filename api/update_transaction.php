<?php
/**
 * Compat: POST action=check_status&reference=...
 * Canonical live check: api/check_transaction.php
 */
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
if (empty($payload['reference']) && empty($payload['csrf_token'])) {
    $json = json_decode((string) file_get_contents('php://input'), true);
    if (is_array($json)) {
        $payload = $json;
    }
}

if (!verifyCsrfToken((string) ($payload['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$ref = trim((string) ($payload['reference'] ?? ''));
if ($ref === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'reference required']);
    exit;
}

$_GET['ref'] = $ref;
$_GET['reference'] = $ref;
require __DIR__ . '/check_transaction.php';
