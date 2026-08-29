<?php
/**
 * DI PARMA | PayPal API
 * GET  /api/paypal.php?action=client_token
 * POST /api/paypal.php?action=create_order
 * POST /api/paypal.php?action=capture_order
 * POST /api/paypal.php?action=webhook
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../lib/PayPalService.php';

header('Content-Type: application/json; charset=utf-8');

$action  = strtolower(trim($_GET['action'] ?? ''));
$payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$db      = db();

// Webhook لا يحتاج session
if ($action !== 'webhook' && empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'غير مصرّح']);
    exit();
}

$svc = PayPalService::getInstance();

try {
    switch ($action) {

        // ── Client Token للـ SDK v6 ──────────────────────────
        case 'client_token':
            $siteUrl = defined('SITE_URL') ? SITE_URL : 'https://diparmas.com';
            echo json_encode($svc->getClientToken($siteUrl), JSON_UNESCAPED_UNICODE);
            break;

        // ── إنشاء Order ──────────────────────────────────────
        case 'create_order':
            if (!verifyCsrfToken($payload['csrf_token'] ?? '')) {
                echo json_encode(['success' => false, 'message' => 'CSRF invalid']); break;
            }
            $amount    = floatval($payload['amount']   ?? 0);
            $currency  = trim($payload['currency']     ?? 'USD');
            $reference = trim($payload['reference']    ?? generateReference('PP'));

            if ($amount <= 0) {
                echo json_encode(['success' => false, 'message' => 'مبلغ غير صالح']); break;
            }

            // حفظ في transactions
            $db->insert('transactions', [
                'reference'        => $reference,
                'gateway'          => 'paypal',
                'amount'           => $amount,
                'currency'         => $currency,
                'customer_name'    => $payload['name']  ?? 'Customer',
                'customer_email'   => $payload['email'] ?? 'guest@diparmas.com',
                'status'           => 'pending',
                'transaction_type' => 'PayPal — شراء USDT',
                'user_id'          => intval($_SESSION['user_id'] ?? 0),
                'fees'             => round($amount * 0.034 + 0.30, 2),
                'net_amount'       => round($amount - ($amount * 0.034 + 0.30), 2),
                'security_mode'    => '3D',
                'gateway_response' => json_encode([
                    'type'          => 'paypal_order',
                    'crypto'        => $payload['crypto']         ?? 'USDT',
                    'network'       => $payload['network']        ?? 'TRC20',
                    'wallet'        => $payload['wallet_address'] ?? '',
                    'crypto_amount' => $payload['crypto_amount']  ?? 0,
                ]),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $result = $svc->createOrder($amount, $currency, $reference, [
                'description' => "شراء {$payload['crypto_amount']} {$payload['crypto']}",
            ]);

            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;

        // ── Capture بعد موافقة المستخدم ──────────────────────
        case 'capture_order':
            if (!verifyCsrfToken($payload['csrf_token'] ?? '')) {
                echo json_encode(['success' => false, 'message' => 'CSRF invalid']); break;
            }
            $orderId   = trim($payload['order_id']  ?? '');
            $reference = trim($payload['reference'] ?? '');

            if (empty($orderId)) {
                echo json_encode(['success' => false, 'message' => 'order_id مطلوب']); break;
            }

            $result = $svc->captureOrder($orderId);

            if ($result['success']) {
                $db->update('transactions', [
                    'status'     => 'completed',
                    'updated_at' => date('Y-m-d H:i:s'),
                ], ['reference' => $reference ?: $result['reference']]);

                // إرسال USDT بعد الدفع
                $txn = $db->find('transactions', ['reference' => $reference ?: $result['reference']]);
                if ($txn) {
                    $gwData  = json_decode($txn['gateway_response'] ?? '{}', true);
                    $toAddr  = $gwData['wallet']        ?? '';
                    $cryptoAmt = floatval($gwData['crypto_amount'] ?? 0);
                    $network = $gwData['network']       ?? 'TRC20';

                    if (!empty($toAddr) && $cryptoAmt > 0 && file_exists(__DIR__.'/../lib/ExchangeAPIService.php')) {
                        require_once __DIR__.'/../lib/ExchangeAPIService.php';
                        require_once __DIR__.'/../lib/WalletService.php';
                        require_once __DIR__.'/../lib/HotWalletService.php';
                        $ex = ExchangeAPIService::getInstance();
                        $ex->fulfillOrder($txn['reference'], $cryptoAmt, $toAddr, $network, intval($txn['user_id']));
                    }
                }
            }

            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;

        // ── Webhook ──────────────────────────────────────────
        case 'webhook':
            $rawBody = file_get_contents('php://input');
            $data    = json_decode($rawBody, true);
            $eventType = $data['event_type'] ?? '';

            if (str_contains($eventType, 'PAYMENT.CAPTURE.COMPLETED')) {
                $orderId   = $data['resource']['supplementary_data']['related_ids']['order_id'] ?? '';
                $reference = $data['resource']['purchase_units'][0]['reference_id'] ?? '';
                if ($reference) {
                    $db->update('transactions', ['status' => 'completed'], ['reference' => $reference]);
                }
            }

            http_response_code(200);
            echo json_encode(['status' => 'ok']);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'action غير معروف']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => defined('APP_IS_LOCAL') && APP_IS_LOCAL ? $e->getMessage() : 'خطأ داخلي']);
}
