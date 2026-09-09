<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit();
}

$reference = trim((string)($_POST['reference'] ?? $_REQUEST['reference'] ?? ''));
$amount = floatval($_POST['amount'] ?? $_REQUEST['amount'] ?? 0);
$reason = trim((string)($_POST['reason'] ?? $_REQUEST['reason'] ?? 'Refund requested by admin'));

if ($reference === '') {
    echo json_encode(['success' => false, 'message' => 'المرجع مفقود.']);
    exit();
}

$result = processRefundTransaction($reference, $amount, $reason);
echo json_encode($result);
