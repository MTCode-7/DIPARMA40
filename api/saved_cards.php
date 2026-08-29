<?php
/**
 * DI PARMA | Saved Cards API
 * POST /api/saved_cards.php?action=setup_stripe
 * POST /api/saved_cards.php?action=save_stripe
 * POST /api/saved_cards.php?action=save_myfatoorah
 * POST /api/saved_cards.php?action=charge
 * GET  /api/saved_cards.php?action=list
 * POST /api/saved_cards.php?action=delete
 * POST /api/saved_cards.php?action=set_default
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../lib/SavedPaymentService.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'غير مصرّح']);
    exit();
}

$userId  = intval($_SESSION['user_id']);
$action  = strtolower(trim($_GET['action'] ?? ''));
$payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$svc     = SavedPaymentService::getInstance();

try {
    switch ($action) {

        // ── قائمة البطاقات ────────────────────────────────────
        case 'list':
            $cards = $svc->getUserCards($userId);
            // إخفاء بيانات حساسة
            foreach ($cards as &$c) {
                unset($c['token'], $c['meta']);
            }
            echo json_encode(['success' => true, 'cards' => $cards], JSON_UNESCAPED_UNICODE);
            break;

        // ── Stripe: إنشاء SetupIntent ─────────────────────────
        case 'setup_stripe':
            $email = $payload['email'] ?? ($_SESSION['user_data']['email'] ?? '');
            $result = $svc->stripeCreateSetupIntent($userId, $email);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;

        // ── Stripe: حفظ البطاقة بعد SetupIntent ──────────────
        case 'save_stripe':
            if (!verifyCsrfToken($payload['csrf_token'] ?? '')) {
                echo json_encode(['success' => false, 'message' => 'CSRF invalid']); break;
            }
            $pmId       = trim($payload['payment_method_id'] ?? '');
            $customerId = trim($payload['customer_id']       ?? '');
            if (empty($pmId) || empty($customerId)) {
                echo json_encode(['success' => false, 'message' => 'payment_method_id و customer_id مطلوبان']); break;
            }
            echo json_encode($svc->stripeSaveCard($userId, $pmId, $customerId), JSON_UNESCAPED_UNICODE);
            break;

        // ── MyFatoorah: حفظ RecurringId ──────────────────────
        case 'save_myfatoorah':
            if (!verifyCsrfToken($payload['csrf_token'] ?? '')) {
                echo json_encode(['success' => false, 'message' => 'CSRF invalid']); break;
            }
            $invoiceId = trim($payload['invoice_id'] ?? '');
            if (empty($invoiceId)) {
                echo json_encode(['success' => false, 'message' => 'invoice_id مطلوب']); break;
            }
            echo json_encode($svc->myfatoorahSaveRecurring($userId, $invoiceId), JSON_UNESCAPED_UNICODE);
            break;

        // ── الدفع ببطاقة محفوظة (بدون OTP) ─────────────────
        case 'charge':
            if (!verifyCsrfToken($payload['csrf_token'] ?? '')) {
                echo json_encode(['success' => false, 'message' => 'CSRF invalid']); break;
            }
            $cardId    = intval($payload['card_id']   ?? 0);
            $amount    = floatval($payload['amount']  ?? 0);
            $currency  = trim($payload['currency']    ?? 'USD');
            $reference = trim($payload['reference']   ?? generateReference('MIT'));
            $gateway   = trim($payload['gateway']     ?? '');

            if ($cardId <= 0 || $amount <= 0) {
                echo json_encode(['success' => false, 'message' => 'بيانات غير صالحة']); break;
            }

            if ($gateway === 'stripe') {
                $result = $svc->stripeChargeOffSession($userId, $cardId, $amount, $currency, $reference);
            } elseif ($gateway === 'myfatoorah') {
                $result = $svc->myfatoorahChargeRecurring($userId, $cardId, $amount, $currency, $reference);
            } else {
                echo json_encode(['success' => false, 'message' => 'gateway غير مدعوم للدفع المتكرر']); break;
            }

            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;

        // ── حذف بطاقة ────────────────────────────────────────
        case 'delete':
            if (!verifyCsrfToken($payload['csrf_token'] ?? '')) {
                echo json_encode(['success' => false, 'message' => 'CSRF invalid']); break;
            }
            $cardId = intval($payload['card_id'] ?? 0);
            $result = $svc->deleteCard($userId, $cardId);
            echo json_encode(['success' => $result, 'message' => $result ? 'تم الحذف' : 'فشل الحذف'], JSON_UNESCAPED_UNICODE);
            break;

        // ── تعيين افتراضي ─────────────────────────────────────
        case 'set_default':
            if (!verifyCsrfToken($payload['csrf_token'] ?? '')) {
                echo json_encode(['success' => false, 'message' => 'CSRF invalid']); break;
            }
            $cardId = intval($payload['card_id'] ?? 0);
            $result = $svc->setDefault($userId, $cardId);
            echo json_encode(['success' => $result], JSON_UNESCAPED_UNICODE);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'action غير معروف: ' . $action]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => defined('APP_IS_LOCAL') && APP_IS_LOCAL ? $e->getMessage() : 'خطأ داخلي',
    ]);
}
