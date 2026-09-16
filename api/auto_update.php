<?php
/**
 * DI PARMA | Auto Update endpoint
 *
 * GET  ?action=health
 * POST GitHub webhook (X-Hub-Signature-256) or X-Auto-Update-Token
 * CLI  php api/auto_update.php cron
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../lib/AutoUpdateService.php';

$isCli = PHP_SAPI === 'cli';

if ($isCli) {
    $arg = strtolower(trim((string) ($argv[1] ?? 'cron')));
    if ($arg === 'status') {
        echo json_encode(AutoUpdateService::status(true), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
        exit(0);
    }
    $result = AutoUpdateService::pull('cron');
    echo json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(!empty($result['success']) ? 0 : 1);
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = strtolower(trim((string) ($_GET['action'] ?? '')));

if ($method === 'GET' || $method === 'HEAD' || $action === 'health') {
    $st = AutoUpdateService::status(false);
    unset($st['sha']);
    echo json_encode($st, JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$okSig = AutoUpdateService::verifyGithubSignature($raw) || AutoUpdateService::verifyToken();
if (!$okSig && is_file(__DIR__ . '/../includes/peer_link.php')) {
    require_once __DIR__ . '/../includes/peer_link.php';
    $okSig = function_exists('peer_verify_request') && peer_verify_request($raw);
}
if (!$okSig) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid auto-update signature']);
    exit;
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = [];
}
$ref = (string) ($payload['ref'] ?? $_GET['ref'] ?? '');
if (!AutoUpdateService::allowedRef($ref !== '' ? $ref : null)) {
    echo json_encode(['success' => true, 'message' => 'Ignored ref ' . $ref, 'skipped' => true]);
    exit;
}

$reason = (string) ($payload['reason'] ?? '');
if ($reason === '') {
    $reason = isset($_SERVER['HTTP_X_HUB_SIGNATURE_256']) ? 'github-webhook' : 'token';
}

echo json_encode(AutoUpdateService::pull($reason, false), JSON_UNESCAPED_UNICODE);
