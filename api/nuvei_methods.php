<?php
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once dirname(__DIR__) . '/lib/NuveiSmart2PayMethodsService.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$service = new NuveiSmart2PayMethodsService();
$country = trim((string) ($_GET['country'] ?? ''));
$scope = strtolower(trim((string) ($_GET['scope'] ?? 'assigned')));
if ($scope === 'method') {
    $result = $service->method((int) ($_GET['id'] ?? 0));
} elseif ($scope === 'all') {
    $result = $service->listMethods($country !== '' ? $country : null);
} else {
    $result = $service->listAssignedMethods($country !== '' ? $country : null);
}

if (empty($result['success'])) {
    http_response_code(!empty($result['configured']) ? 503 : 502);
}
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
