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

$title = trim((string) ($payload['title'] ?? ''));
$amount = (float) ($payload['amount'] ?? 0);
$gateway = trim((string) ($payload['gateway'] ?? ''));
$currency = strtoupper(trim((string) ($payload['currency'] ?? 'USD')));
if ($title === '' || $amount <= 0 || $gateway === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'title, amount, and gateway required']);
    exit;
}

$expiryDays = max(1, min(365, (int) ($payload['expiry_days'] ?? 7)));
$linkId = strtoupper(substr($gateway, 0, 3)) . date('Ymd') . bin2hex(random_bytes(4));
$token = bin2hex(random_bytes(32));
$slug = function_exists('generateSlug') ? generateSlug($title) : strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title));

$insertData = [
    'link_id' => $linkId,
    'token' => $token,
    'slug' => $slug,
    'title' => $title,
    'description' => trim((string) ($payload['description'] ?? '')),
    'amount' => $amount,
    'currency' => $currency !== '' ? $currency : 'USD',
    'gateway' => $gateway,
    'protocol' => trim((string) ($payload['protocol'] ?? '101.0')),
    'payment_type' => trim((string) ($payload['payment_type'] ?? 'one_time')),
    'customer_name' => trim((string) ($payload['customer_name'] ?? '')),
    'customer_email' => trim((string) ($payload['customer_email'] ?? '')),
    'customer_phone' => trim((string) ($payload['customer_phone'] ?? '')),
    'redirect_url' => trim((string) ($payload['redirect_url'] ?? '')),
    'expiry_date' => date('Y-m-d H:i:s', strtotime("+{$expiryDays} days")),
    'max_uses' => (int) ($payload['max_uses'] ?? 0),
    'uses_count' => 0,
    'status' => 'active',
    'user_id' => (int) $_SESSION['user_id'],
    'created_at' => date('Y-m-d H:i:s'),
];

$id = db()->insert('payment_links', $insertData);
if ($id <= 0) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to create link']);
    exit;
}

$linkUrl = (defined('SITE_URL') ? SITE_URL : '') . '/pay.php?link=' . $linkId . '&token=' . $token;
echo json_encode([
    'success' => true,
    'id' => $id,
    'link_id' => $linkId,
    'url' => $linkUrl,
    'token' => $token,
    'slug' => $slug,
], JSON_UNESCAPED_UNICODE);
