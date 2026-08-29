<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
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
