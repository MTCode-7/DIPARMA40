<?php
/**
 * DI PARMA | Orchestrator API (compat)
 *
 * Card pipe gateways: PaymentOrchestrator → DiParmaChargeHub → POS standalone → Adapter → Ledger
 * Webhook confirm / approve / crypto flows stay here.
 */
header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors', '0');
ob_start();
register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => APP_IS_LOCAL ? $error['message'] : 'خطأ داخلي في الخادم',
    ], JSON_UNESCAPED_UNICODE);
});

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

        // ── Legacy client confirmation is disabled; verified webhooks own this transition. ──
        case 'confirm':
        // ── Client-supplied approval is not proof of a provider payment. ──
        case 'approve':
            http_response_code(410);
            echo json_encode([
                'success' => false,
                'message' => 'إيقاف التأكيد اليدوي. تأكيد الدفع يتم فقط عبر webhook موثّق من البوابة.',
                'code' => 'VERIFIED_PROVIDER_EVENT_REQUIRED',
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

} catch (Throwable $e) {
    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => APP_IS_LOCAL ? $e->getMessage() : 'خطأ داخلي في الخادم',
    ], JSON_UNESCAPED_UNICODE);
}
