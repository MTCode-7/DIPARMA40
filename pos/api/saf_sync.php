<?php
/**
 * مزامنة طابور Offline SAF → مضيف البنك/البوابة المختارة
 * POST /pos/api/saf_sync.php
 * body: { gateway_endpoint?, api_secret?, gateway? }
 */
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Use POST']);
    exit;
}

require_once dirname(__DIR__) . '/bootstrap.php';
pos_restore_operator();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$data = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = $_POST;
}

$endpoint = trim((string) ($data['gateway_endpoint'] ?? $data['endpoint'] ?? getenv('BANK_SAF_HOST') ?: getenv('OFFLINE_SAF_HOST') ?: ''));
$secret = trim((string) ($data['api_secret'] ?? $data['api_secret_key'] ?? getenv('BANK_SAF_KEY') ?: getenv('OFFLINE_SAF_KEY') ?: ''));

if ($endpoint === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'gateway_endpoint required (or set BANK_SAF_HOST). Gateway is your choice.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!class_exists('RealOfflineSalesManager', false)) {
    require_once dirname(__DIR__) . '/lib/RealOfflineSalesManager.php';
}

$saf = new RealOfflineSalesManager();
$result = $saf->syncOfflineQueue($endpoint, $secret);

echo json_encode([
    'success' => ($result['status'] ?? '') === 'SYNC_COMPLETE' || !empty($result['synced_count']),
    'result' => $result,
], JSON_UNESCAPED_UNICODE);
