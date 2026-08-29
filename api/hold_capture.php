<?php
/**
 * DI PARMA | Hold/Capture API
 */
// JSON header أولاً لمنع redirect HTML من auth_check
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit;
}

require_once __DIR__ . '/../includes/crypto_schema.php';
require_once __DIR__ . '/../lib/HoldCaptureService.php';

if (file_exists(__DIR__ . '/../lib/ExchangeAPIService.php')) {
    require_once __DIR__ . '/../lib/ExchangeAPIService.php';
}
if (file_exists(__DIR__ . '/../lib/WalletService.php')) {
    require_once __DIR__ . '/../lib/WalletService.php';
}
if (file_exists(__DIR__ . '/../lib/HotWalletService.php')) {
    require_once __DIR__ . '/../lib/HotWalletService.php';
}

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'غير مصرّح']);
    exit();
}

dp_create_crypto_tables();

$userId  = intval($_SESSION['user_id']);
$action  = strtolower(trim($_GET['action'] ?? ''));
$payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$svc     = HoldCaptureService::getInstance();
$db      = db();

try {
    switch ($action) {

        // ── إنشاء HOLD ────────────────────────────────────────
        case 'create_hold':
            if (!verifyCsrfToken($payload['csrf_token'] ?? '')) {
                echo json_encode(['success' => false, 'message' => 'CSRF invalid']); break;
            }
            $amount    = floatval($payload['amount']   ?? 0);
            $currency  = trim($payload['currency']     ?? 'USD');
            $reference = trim($payload['reference']    ?? generateReference('HOLD'));
            $meta      = [
                'crypto'        => $payload['crypto']         ?? 'USDT',
                'network'       => $payload['network']        ?? 'TRC20',
                'wallet'        => $payload['wallet_address'] ?? '',
                'crypto_amount' => $payload['crypto_amount']  ?? 0,
            ];

            if ($amount <= 0) {
                echo json_encode(['success' => false, 'message' => 'مبلغ غير صالح']); break;
            }

            // INSERT أو UPDATE — لمنع Duplicate entry
            $existingTxn = $db->find('transactions', ['reference' => $reference]);
            if (!$existingTxn) {
                $db->insert('transactions', [
                    'reference'        => $reference,
                    'gateway'          => 'stripe_hold',
                    'amount'           => $amount,
                    'currency'         => $currency,
                    'customer_name'    => $payload['name']  ?? 'Customer',
                    'customer_email'   => $payload['email'] ?? 'guest@diparmas.com',
                    'status'           => 'pending',
                    'transaction_type' => "حجز — {$meta['crypto']}/{$meta['network']}",
                    'user_id'          => $userId,
                    'fees'             => round($amount * 0.015, 2),
                    'net_amount'       => round($amount * 0.985, 2),
                    'security_mode'    => $payload['security_mode'] ?? '3D',
                    'gateway_response' => json_encode(array_merge($meta, ['type' => 'hold'])),
                    'created_at'       => date('Y-m-d H:i:s'),
                ]);
            }

            $result = $svc->createHold($amount, $currency, $reference, $userId, $meta);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;

        // ── تأكيد HOLD ────────────────────────────────────────
        case 'confirm_hold':
            $piId = trim($payload['payment_intent_id'] ?? $_GET['pi'] ?? '');
            if (empty($piId)) {
                echo json_encode(['success' => false, 'message' => 'payment_intent_id مطلوب']); break;
            }
            $result = $svc->confirmHold($piId);
            if ($result['success']) {
                $hold = $svc->getHoldByPI($piId);
                if ($hold) {
                    $db->update('transactions', [
                        'status'     => 'authorized',
                        'updated_at' => date('Y-m-d H:i:s'),
                    ], ['reference' => $hold['reference']]);
                }
            }
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;

        // ── CAPTURE — تحصيل المبلغ المحجوز ───────────────────
        case 'capture':
            if (!verifyCsrfToken($payload['csrf_token'] ?? '')) {
                echo json_encode(['success' => false, 'message' => 'CSRF invalid']); break;
            }
            $piId          = trim($payload['payment_intent_id'] ?? '');
            $refCapture    = trim($payload['reference'] ?? '');
            $partialAmount = !empty($payload['partial_amount']) ? floatval($payload['partial_amount']) : null;

            // إذا أُرسل reference بدل payment_intent_id — ابحث عبر الـ reference
            if (empty($piId) && !empty($refCapture)) {
                $holdByRef = $svc->getHoldByReference($refCapture);
                if ($holdByRef) {
                    $piId = $holdByRef['payment_intent_id'] ?? '';
                }
                if (empty($piId)) {
                    // البحث في transactions
                    $txnByRef = $db->find('transactions', ['reference' => $refCapture]);
                    if ($txnByRef) {
                        $gwData = json_decode($txnByRef['gateway_response'] ?? '{}', true);
                        $piId   = $gwData['payment_intent_id'] ?? $gwData['transaction_id'] ?? '';
                    }
                }
            }

            if (empty($piId)) {
                echo json_encode(['success' => false, 'message' => 'أدخل Authorization ID أو Reference الحجز']); break;
            }

            $captureResult = $svc->capture($piId, $partialAmount);

            if ($captureResult['success']) {
                $hold = $svc->getHoldByPI($piId);
                if ($hold) {
                    $gwData = json_decode(
                        $db->find('transactions', ['reference' => $hold['reference']])['gateway_response'] ?? '{}',
                        true
                    );
                    $toAddress    = $gwData['wallet']        ?? '';
                    $cryptoAmount = floatval($gwData['crypto_amount'] ?? 0);
                    $network      = $gwData['network']       ?? 'TRC20';

                    if (!empty($toAddress) && $cryptoAmount > 0 && class_exists('ExchangeAPIService')) {
                        $exchangeResult = ExchangeAPIService::getInstance()->fulfillOrder(
                            $hold['reference'], $cryptoAmount, $toAddress, $network, $userId
                        );
                        $captureResult['crypto_sent'] = $exchangeResult['success'] ?? false;
                        $captureResult['tx_hash']     = $exchangeResult['tx_hash'] ?? null;
                    }

                    $db->update('transactions', [
                        'status'     => 'completed',
                        'updated_at' => date('Y-m-d H:i:s'),
                    ], ['reference' => $hold['reference']]);
                }
            }

            echo json_encode($captureResult, JSON_UNESCAPED_UNICODE);
            break;

        // ── 2D Direct Charge — شراء مباشر بدون OTP ──────────
        case 'charge_2d':
            if (!verifyCsrfToken($payload['csrf_token'] ?? '')) {
                echo json_encode(['success' => false, 'message' => 'CSRF invalid']); break;
            }

            $amount    = floatval($payload['amount']   ?? 0);
            $currency  = trim($payload['currency']     ?? 'USD');
            $reference = trim($payload['reference']    ?? generateReference('2D'));
            $ccNumber  = preg_replace('/\D/', '', $payload['cc_number'] ?? '');
            $ccExpiry  = trim($payload['card_expiry']  ?? '');
            $ccCvv     = trim($payload['card_cvv']     ?? $payload['cvv2'] ?? '');

            if ($amount <= 0) {
                echo json_encode(['success' => false, 'message' => 'مبلغ غير صالح']); break;
            }
            if (strlen($ccNumber) < 13) {
                echo json_encode(['success' => false, 'message' => 'رقم البطاقة غير صالح']); break;
            }
            if (!preg_match('/^\d{3,4}$/', $ccCvv)) {
                echo json_encode(['success' => false, 'message' => 'CVV غير صالح']); break;
            }

            // تحديد البوابة من الـ payload
            $gwOverride = trim($payload['card_provider'] ?? $payload['gateway'] ?? '');

            $result = $svc->directCharge2D([
                'amount'        => $amount,
                'currency'      => $currency,
                'reference'     => $reference,
                'cc_number'     => $ccNumber,
                'cc_expiry'     => $ccExpiry,
                'cvv2'          => $ccCvv,
                'security_mode' => '2D',
                'approval_code' => $payload['approval_code'] ?? '',
                'name'          => $payload['name']          ?? 'Customer',
                'email'         => $payload['email']         ?? 'guest@diparmas.com',
            ], $gwOverride ?: null);

            if ($result['success']) {
                // تحديث أو إنشاء transaction
                $existing = $db->find('transactions', ['reference' => $reference]);
                if ($existing) {
                    $db->update('transactions', [
                        'status'     => 'completed',
                        'updated_at' => date('Y-m-d H:i:s'),
                    ], ['reference' => $reference]);
                } else {
                    $db->insert('transactions', [
                        'reference'        => $reference,
                        'gateway'          => 'nuvei',
                        'amount'           => $amount,
                        'currency'         => $currency,
                        'customer_name'    => $payload['name']  ?? 'Customer',
                        'customer_email'   => $payload['email'] ?? 'guest@diparmas.com',
                        'status'           => 'completed',
                        'transaction_type' => 'MOTO — شراء مباشر بدون OTP',
                        'user_id'          => $userId,
                        'fees'             => round($amount * 0.029 + 0.30, 2),
                        'net_amount'       => round($amount - ($amount * 0.029 + 0.30), 2),
                        'security_mode'    => '2D',
                        'gateway_response' => json_encode($result),
                        'created_at'       => date('Y-m-d H:i:s'),
                    ]);
                }
            }

            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;

        // ── CANCEL — إلغاء الحجز ─────────────────────────────
        case 'cancel':
            if (!verifyCsrfToken($payload['csrf_token'] ?? '')) {
                echo json_encode(['success' => false, 'message' => 'CSRF invalid']); break;
            }
            $piId   = trim($payload['payment_intent_id'] ?? '');
            $reason = trim($payload['reason'] ?? 'requested_by_customer');

            if (empty($piId)) {
                echo json_encode(['success' => false, 'message' => 'payment_intent_id مطلوب']); break;
            }

            $result = $svc->cancel($piId, $reason);
            if ($result['success']) {
                $hold = $svc->getHoldByPI($piId);
                if ($hold) {
                    $db->update('transactions', [
                        'status'     => 'failed',
                        'updated_at' => date('Y-m-d H:i:s'),
                    ], ['reference' => $hold['reference']]);
                }
            }
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;

        // ── قائمة الحجوزات ────────────────────────────────────
        case 'list':
            $holds = $svc->getUserHolds($userId);
            echo json_encode(['success' => true, 'holds' => $holds], JSON_UNESCAPED_UNICODE);
            break;

        // ── حالة حجز ─────────────────────────────────────────
        case 'status':
            $piId = trim($_GET['pi'] ?? '');
            $ref  = trim($_GET['ref'] ?? '');
            $hold = $piId
                ? $svc->getHoldByPI($piId)
                : ($ref ? $svc->getHoldByReference($ref) : null);
            if (!$hold || intval($hold['user_id']) !== $userId) {
                echo json_encode(['success' => false, 'message' => 'غير موجود']); break;
            }
            echo json_encode(['success' => true, 'hold' => $hold], JSON_UNESCAPED_UNICODE);
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
