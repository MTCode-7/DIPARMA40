<?php
/**
 * DI PARMA | Crypto Admin API
 * POST /api/crypto_admin.php?action=refill
 * POST /api/crypto_admin.php?action=kyc_approve
 * POST /api/crypto_admin.php?action=kyc_reject
 * GET  /api/crypto_admin.php?action=treasury
 * GET  /api/crypto_admin.php?action=process_events
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/crypto_schema.php';
require_once __DIR__ . '/../lib/ColdWalletManager.php';
require_once __DIR__ . '/../lib/KYCService.php';
require_once __DIR__ . '/../lib/EventBus.php';
require_once __DIR__ . '/../lib/RiskEngine.php';

header('Content-Type: application/json; charset=utf-8');
requireAdmin();
RiskEngine::ensureTables();

$action  = strtolower(trim($_GET['action'] ?? $_POST['action'] ?? ''));
$payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;

try {
    switch ($action) {

        case 'treasury':
            $cwm = ColdWalletManager::getInstance();
            echo json_encode(['success' => true, 'data' => $cwm->getStatus()], JSON_UNESCAPED_UNICODE);
            break;

        case 'refill':
            if (!verifyCsrfToken($payload['csrf_token'] ?? '')) {
                echo json_encode(['success' => false, 'message' => 'CSRF invalid']); break;
            }
            $cwm = ColdWalletManager::getInstance();
            $r = $cwm->recordTransfer(
                $payload['direction'] ?? 'cold_to_hot',
                (float)($payload['amount']  ?? 0),
                $payload['coin']    ?? 'USDT',
                $payload['network'] ?? 'TRC20',
                $payload['tx_hash'] ?? ''
            );
            echo json_encode($r, JSON_UNESCAPED_UNICODE);
            break;

        case 'kyc_approve':
            if (!verifyCsrfToken($payload['csrf_token'] ?? '')) {
                echo json_encode(['success' => false, 'message' => 'CSRF invalid']); break;
            }
            $kyc = KYCService::getInstance();
            $r   = $kyc->approveManual((int)($payload['user_id'] ?? 0), (int)($payload['level'] ?? 1));
            echo json_encode($r, JSON_UNESCAPED_UNICODE);
            break;

        case 'kyc_reject':
            if (!verifyCsrfToken($payload['csrf_token'] ?? '')) {
                echo json_encode(['success' => false, 'message' => 'CSRF invalid']); break;
            }
            $db = db();
            $db->update('kyc_verifications', [
                'status'           => 'rejected',
                'rejection_reason' => $payload['reason'] ?? 'رُفض من الأدمن',
                'updated_at'       => date('Y-m-d H:i:s'),
            ], ['user_id' => (int)($payload['user_id'] ?? 0)]);
            echo json_encode(['success' => true, 'message' => 'تم الرفض']);
            break;

        case 'process_events':
            $bus = EventBus::getInstance();
            $r   = $bus->processQueue(100);
            echo json_encode(['success' => true, 'result' => $r], JSON_UNESCAPED_UNICODE);
            break;

        case 'treasury_check':
            $cwm = ColdWalletManager::getInstance();
            echo json_encode($cwm->runCheck(), JSON_UNESCAPED_UNICODE);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'action غير معروف']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => APP_IS_LOCAL ? $e->getMessage() : 'خطأ داخلي']);
}
