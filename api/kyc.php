<?php
/**
 * DI PARMA | KYC API
 * POST /api/kyc.php?action=initiate
 * POST /api/kyc.php?action=webhook   ← Sumsub Webhook
 * GET  /api/kyc.php?action=status
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/crypto_schema.php';
require_once __DIR__ . '/../lib/KYCService.php';

header('Content-Type: application/json; charset=utf-8');

$action  = strtolower(trim($_GET['action'] ?? ''));
$payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;

try {
    $kyc = KYCService::getInstance();

    switch ($action) {

        case 'initiate':
            if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'غير مصرّح']); break; }
            if (!verifyCsrfToken($payload['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'CSRF invalid']); break; }
            $level = intval($payload['level'] ?? 1);
            echo json_encode($kyc->initiateKYC((int)$_SESSION['user_id'], $level), JSON_UNESCAPED_UNICODE);
            break;

        case 'status':
            if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'غير مصرّح']); break; }
            echo json_encode(array_merge($kyc->getStatus((int)$_SESSION['user_id']), ['success'=>true]), JSON_UNESCAPED_UNICODE);
            break;

        case 'webhook':
            // Sumsub Webhook — لا يحتاج session
            $raw       = file_get_contents('php://input');
            $signature = $_SERVER['HTTP_X_PAYLOAD_DIGEST'] ?? '';
            $result    = $kyc->handleWebhook($payload, $signature, $raw);
            http_response_code($result['success'] ? 200 : 400);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success'=>false,'message'=>'action غير معروف']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=> APP_IS_LOCAL ? $e->getMessage() : 'خطأ داخلي']);
}
