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
require_once POS_APP_ROOT . '/api/v1/ApiAuth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$data = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = $_POST;
}

$userId = intval($_SESSION['user_id'] ?? 0);
if ($userId > 0) {
    if (!function_exists('verifyCsrfToken') || !verifyCsrfToken((string) ($data['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
} else {
    try {
        $apiClient = ApiAuth::verify();
        $userId = (int) ($apiClient['user_id'] ?? 0);
        if ($userId <= 0) {
            throw new RuntimeException('no user');
        }
    } catch (Throwable $e) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication required']);
        exit;
    }
}

$envHost = trim((string) (getenv('BANK_SAF_HOST') ?: getenv('OFFLINE_SAF_HOST') ?: ''));
$endpoint = trim((string) ($data['gateway_endpoint'] ?? $data['endpoint'] ?? $envHost));
$secret = trim((string) ($data['api_secret'] ?? $data['api_secret_key'] ?? getenv('BANK_SAF_KEY') ?: getenv('OFFLINE_SAF_KEY') ?: ''));

$safHostOk = static function (string $url): bool {
    $parts = parse_url($url);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    if (!in_array($scheme, ['https', 'http'], true) || $host === '') {
        return false;
    }
    if ($host === 'localhost' || $host === '127.0.0.1' || str_ends_with($host, '.local')) {
        return false;
    }
    $ip = gethostbyname($host);
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
};

if ($endpoint === '' || !$safHostOk($endpoint)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'gateway_endpoint required and must be a public http(s) host (or set BANK_SAF_HOST).',
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
