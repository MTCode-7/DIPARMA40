<?php
/**
 * DI_PARMA_MYSYSTEM API
 *
 * Orders + Customers → Payment Orchestrator → Providers → Payment Result → Orders
 *
 * POST /api/mysystem.php?action=pay
 * GET  /api/mysystem.php?action=diagram
 */
header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../lib/MySystem/PaymentOrchestrator.php';

$action = strtolower(trim((string) ($_GET['action'] ?? $_POST['action'] ?? 'pay')));
$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

try {
    switch ($action) {
        case 'diagram':
            echo json_encode([
                'system' => 'DI_PARMA_MYSYSTEM',
                'flow' => [
                    'DASHBOARD DI PARMA _ POS_WEB',
                    'PAYMENT ORCHESTRATOR',
                    ['Provider A (square)', 'Provider B (nuvei)', 'Provider C (stripe/…)'],
                    'Payment Result',
                    'Orders',
                    'Ledger USDT',
                ],
                'endpoints' => [
                    'pos_web' => '/pos/api/transaction.php',
                    'pos_wrap' => '/api/pos_transaction.php',
                    'pay' => '/api/mysystem.php?action=pay',
                    'order' => '/api/mysystem.php?action=order&reference=ORD-...',
                    'diagram' => '/api/mysystem.php?action=diagram',
                ],
                'files' => [
                    'lib/MySystem/ChargeHub.php',
                    'pos/index.php',
                    'pos/api/transaction.php',
                    'pos/lib/gateways.php (pos_run_payment_orchestrator)',
                    'lib/MySystem/OrdersService.php',
                    'lib/MySystem/CustomersService.php',
                    'lib/MySystem/PaymentOrchestrator.php',
                    'lib/PaymentOrchestrator.php (compat → ChargeHub)',
                    'lib/DIPARMAOrchestrator.php (routing → ChargeHub)',
                    'api/mysystem.php',
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        case 'order':
            $ref = trim((string) ($payload['reference'] ?? $_GET['reference'] ?? ''));
            $orders = new MySystemOrdersService();
            $order = $orders->getByReference($ref);
            echo json_encode([
                'success' => (bool) $order,
                'order' => $order,
                'message' => $order ? 'ok' : 'Order not found',
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'pay':
        default:
            if (empty($_SESSION['user_id']) && empty($payload['customer_id']) && empty($payload['email'])) {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Unauthorized — login or pass customer_id/email']);
                break;
            }
            if (!empty($payload['csrf_token']) && function_exists('verifyCsrfToken') && !verifyCsrfToken((string) $payload['csrf_token'])) {
                echo json_encode(['success' => false, 'message' => 'Invalid CSRF']);
                break;
            }
            if (!empty($_SESSION['user_id'])) {
                $payload['user_id'] = (int) $_SESSION['user_id'];
            }
            $orch = MySystemPaymentOrchestrator::getInstance();
            echo json_encode($orch->pay($payload), JSON_UNESCAPED_UNICODE);
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => (defined('APP_IS_LOCAL') && APP_IS_LOCAL) ? $e->getMessage() : 'Internal error',
    ], JSON_UNESCAPED_UNICODE);
}
