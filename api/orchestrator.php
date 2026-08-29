<?php
/**
 * DI PARMA | Orchestrator API
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success'=>false,'message'=>'غير مصرّح']);
    exit();
}

require_once __DIR__ . '/../includes/crypto_schema.php';
require_once __DIR__ . '/../lib/RiskEngine.php';
require_once __DIR__ . '/../lib/KYCService.php';
require_once __DIR__ . '/../lib/CardPaymentService.php';
require_once __DIR__ . '/../lib/ExchangeAPIService.php';
require_once __DIR__ . '/../lib/ExchangeRateService.php';
require_once __DIR__ . '/../lib/EventBus.php';
require_once __DIR__ . '/../lib/WalletService.php';
require_once __DIR__ . '/../lib/PaymentOrchestrator.php';

RiskEngine::ensureTables();
dp_create_crypto_tables();

$action  = strtolower(trim($_GET['action'] ?? ''));
$payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;

try {
    $orch = PaymentOrchestrator::getInstance();

    switch ($action) {

        // ── إنشاء طلب شراء كامل ──────────────────────────────
        case 'initiate':
            if (!verifyCsrfToken($payload['csrf_token'] ?? '')) {
                echo json_encode(['success'=>false,'message'=>'CSRF غير صالح']);
                break;
            }
            $payload['user_id'] = intval($_SESSION['user_id']);
            echo json_encode($orch->initiatePurchase($payload), JSON_UNESCAPED_UNICODE);
            break;

        // ── تأكيد دفع (يُستدعى بعد Webhook أو يدوياً) ────────
        case 'confirm':
            $reference = trim($payload['reference'] ?? $_GET['ref'] ?? '');
            if (empty($reference)) {
                echo json_encode(['success'=>false,'message'=>'reference مطلوب']);
                break;
            }
            echo json_encode($orch->onPaymentConfirmed($reference, $payload), JSON_UNESCAPED_UNICODE);
            break;

        // ── استقبال Approval Code الحقيقي من Visa/Mastercard ──
        // يُستدعى من checkout JS بعد أن تُرجع البوابة requires_approval
        case 'approve':
            if (!verifyCsrfToken($payload['csrf_token'] ?? '')) {
                echo json_encode(['success'=>false,'message'=>'CSRF غير صالح']);
                break;
            }
            $reference    = trim($payload['reference']    ?? '');
            $approvalCode = trim($payload['approval_code'] ?? '');

            if (empty($reference)) {
                echo json_encode(['success'=>false,'message'=>'reference مطلوب']);
                break;
            }
            if (empty($approvalCode)) {
                echo json_encode(['success'=>false,'message'=>'approval_code مطلوب']);
                break;
            }

            $db  = db();

            // تأكد أن حقل approval_code موجود في الجدول
            try {
                $colCheck = $db->query(
                    "SELECT COUNT(*) as cnt FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME   = '" . DB_PREFIX . "transactions'
                       AND COLUMN_NAME  = 'approval_code'"
                );
                if (empty($colCheck[0]['cnt'])) {
                    $db->execute(
                        "ALTER TABLE `" . DB_PREFIX . "transactions`
                         ADD COLUMN `approval_code` VARCHAR(6) DEFAULT NULL AFTER `security_mode`"
                    );
                }
            } catch (Exception $ignored) {}

            $txn = $db->find('transactions', ['reference' => $reference]);

            if (!$txn || intval($txn['user_id']) !== intval($_SESSION['user_id'])) {
                echo json_encode(['success'=>false,'message'=>'العملية غير موجودة أو غير مصرح']);
                break;
            }

            // حفظ الـ Approval Code الحقيقي في DB
            $db->update('transactions', [
                'approval_code' => $approvalCode,
                'status'        => 'approved',
                'updated_at'    => date('Y-m-d H:i:s'),
            ], ['reference' => $reference]);

            // تسجيل في approval_requests إذا كانت الجدول موجودة
            try {
                $db->execute(
                    "INSERT INTO dp_approval_requests
                        (user_id, type, reference, amount, currency, status, reason, created_at)
                     VALUES (?, 'approval_code', ?, ?, ?, 'approved', ?, ?)
                     ON DUPLICATE KEY UPDATE status='approved', reason=VALUES(reason)",
                    [
                        intval($_SESSION['user_id']),
                        $reference,
                        floatval($txn['amount']),
                        $txn['currency'],
                        'approval_code:' . $approvalCode,
                        date('Y-m-d H:i:s'),
                    ]
                );
            } catch (Exception $ignored) {}

            echo json_encode([
                'success'       => true,
                'message'       => 'تم تأكيد Approval Code بنجاح',
                'reference'     => $reference,
                'approval_code' => $approvalCode,
                'status'        => 'approved',
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ── حالة الطلب ───────────────────────────────────────
        case 'status':
            $reference = trim($_GET['ref'] ?? $payload['reference'] ?? '');
            if (empty($reference)) {
                echo json_encode(['success'=>false,'message'=>'reference مطلوب']);
                break;
            }
            $db  = db();
            $txn = $db->find('transactions', ['reference' => $reference]);
            if (!$txn || intval($txn['user_id']) !== intval($_SESSION['user_id'])) {
                echo json_encode(['success'=>false,'message'=>'غير موجود أو غير مصرّح']);
                break;
            }
            $bc = $db->find('blockchain_txns', ['reference' => $reference]);
            echo json_encode([
                'success'   => true,
                'reference' => $reference,
                'status'    => $txn['status'],
                'tx_hash'   => $bc['tx_hash']  ?? null,
                'network'   => $bc['network']  ?? null,
                'explorer'  => $bc['tx_hash']
                    ? 'https://tronscan.org/#/transaction/' . $bc['tx_hash']
                    : null,
                'amount'    => $txn['amount'],
                'currency'  => $txn['currency'],
                'updated_at'=> $txn['updated_at'] ?? $txn['created_at'],
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ── معالجة Event Queue (Cron) ─────────────────────────
        case 'process_queue':
            requireAdmin();
            $bus = EventBus::getInstance();
            echo json_encode($bus->processQueue(100), JSON_UNESCAPED_UNICODE);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success'=>false,'message'=>'action غير معروف: '.$action]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => APP_IS_LOCAL ? $e->getMessage() : 'خطأ داخلي في الخادم',
    ], JSON_UNESCAPED_UNICODE);
}
