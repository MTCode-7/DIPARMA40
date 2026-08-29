<?php
/**
 * ============================================================
 * DI PARMA | Crypto API Endpoint
 * ============================================================
 * GET  /api/crypto.php?action=rate&coin=USDT&fiat=AED
 * GET  /api/crypto.php?action=wallet&network=TRC20
 * GET  /api/crypto.php?action=balance
 * POST /api/crypto.php?action=buy
 * POST /api/crypto.php?action=sell
 * POST /api/crypto.php?action=fiat_confirmed&reference=XXX
 * GET  /api/crypto.php?action=monitor
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../lib/CryptoGateway.php';

header('Content-Type: application/json; charset=utf-8');

// مصادقة
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'غير مصرّح']);
    exit();
}

$action  = strtolower(trim($_GET['action'] ?? $_POST['action'] ?? ''));
$userId  = intval($_SESSION['user_id']);
$gateway = CryptoGateway::getInstance();

try {
    switch ($action) {

        // ── سعر الصرف ────────────────────────────────────────
        case 'rate':
            $coin = strtoupper($_GET['coin'] ?? 'USDT');
            $fiat = strtoupper($_GET['fiat'] ?? 'AED');
            echo json_encode($gateway->getRate($coin, $fiat), JSON_UNESCAPED_UNICODE);
            break;

        // ── جميع الأسعار ─────────────────────────────────────
        case 'rates':
            $fiat  = strtoupper($_GET['fiat'] ?? 'AED');
            $rates = ExchangeRateService::getInstance()->getAllRates($fiat);
            echo json_encode(['success' => true, 'rates' => $rates], JSON_UNESCAPED_UNICODE);
            break;

        // ── محفظة المستخدم ───────────────────────────────────
        case 'wallet':
            $network = strtoupper($_GET['network'] ?? 'TRC20');
            $coin    = strtoupper($_GET['coin'] ?? 'USDT');
            echo json_encode($gateway->getUserWallet($userId, $network, $coin), JSON_UNESCAPED_UNICODE);
            break;

        // ── رصيد Hot Wallet (أدمن فقط) ───────────────────────
        case 'balance':
            requireAdmin();
            echo json_encode($gateway->getHotBalance(), JSON_UNESCAPED_UNICODE);
            break;

        // ── شراء Crypto ──────────────────────────────────────
        case 'buy':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'POST مطلوب']);
                break;
            }
            $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            echo json_encode($gateway->initBuyCrypto($payload), JSON_UNESCAPED_UNICODE);
            break;

        // ── بيع Crypto ───────────────────────────────────────
        case 'sell':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'POST مطلوب']);
                break;
            }
            $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            echo json_encode($gateway->initSellCrypto($payload), JSON_UNESCAPED_UNICODE);
            break;

        // ── تأكيد الدفع الفيات (يُستدعى من Webhook) ─────────
        case 'fiat_confirmed':
            $reference = trim($_GET['reference'] ?? $_POST['reference'] ?? '');
            if (empty($reference)) {
                echo json_encode(['success' => false, 'message' => 'reference مطلوب']);
                break;
            }
            echo json_encode($gateway->onFiatPaymentConfirmed($reference), JSON_UNESCAPED_UNICODE);
            break;

        // ── تشغيل Monitor (أدمن أو CLI) ──────────────────────
        case 'monitor':
            requireAdmin();
            echo json_encode($gateway->runMonitor(), JSON_UNESCAPED_UNICODE);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'action غير معروف: ' . $action]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => APP_IS_LOCAL ? $e->getMessage() : 'خطأ داخلي في الخادم',
    ], JSON_UNESCAPED_UNICODE);
}
