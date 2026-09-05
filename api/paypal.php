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
require_once __DIR__ . '/../lib/PayPalService.php';

header('Content-Type: application/json; charset=utf-8');

$action  = strtolower(trim($_GET['action'] ?? ''));
$payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$db      = db();

$paypalGateway = $db->find('payment_gateways', ['code' => 'paypal']);
$paypalCredentials = json_decode($paypalGateway['credentials'] ?? '{}', true) ?: [];
foreach (['client_id' => 'PAYPAL_CLIENT_ID', 'secret' => 'PAYPAL_SECRET', 'environment' => 'PAYPAL_ENVIRONMENT'] as $field => $envKey) {
    if (!empty($paypalCredentials[$field])) {
        putenv($envKey . '=' . $paypalCredentials[$field]);
        $_ENV[$envKey] = $paypalCredentials[$field];
    }
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
            $intent    = strtoupper($payload['intent'] ?? 'CAPTURE');
            $intent    = in_array($intent, ['CAPTURE', 'AUTHORIZE'], true) ? $intent : 'CAPTURE';
            $transactionType = trim($payload['transaction_type'] ?? '')
                ?: ($intent === 'AUTHORIZE' ? 'PayPal Authorization' : 'PayPal Payment');
            $destination = trim($payload['destination'] ?? 'gateway');

            if ($amount <= 0) {
                echo json_encode(['success' => false, 'message' => 'مبلغ غير صالح']); break;
            }

            $result = $svc->createOrder($amount, $currency, $reference, [
                'description' => $transactionType,
                'intent'      => $intent,
                'cancel_url'  => (defined('SITE_URL') ? SITE_URL : 'https://diparmas.com') . '/checkout_router.php?gateway=paypal&destination=gateway&error=paypal_cancelled',
            ]);

            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;

        case 'authorize_order':
            if (!verifyCsrfToken($payload['csrf_token'] ?? '')) {
                echo json_encode(['success' => false, 'message' => 'CSRF invalid']); break;
            }
            $orderId   = trim($payload['order_id'] ?? '');
            $reference = trim($payload['reference'] ?? '');
            if ($orderId === '') {
                echo json_encode(['success' => false, 'message' => 'order_id مطلوب']); break;
            }

            $paypalTxn = json_decode($payload['paypal_txn'] ?? '', true) ?: [];
            $authorization = $paypalTxn['purchase_units'][0]['payments']['authorizations'][0] ?? [];
            $authorizationId = trim($payload['authorization_id'] ?? ($authorization['id'] ?? ''));
            $order = $svc->getOrder($orderId);
            $orderAuthorization = $order['purchase_units'][0]['payments']['authorizations'][0] ?? [];
            $authorizationId = $authorizationId ?: trim($orderAuthorization['id'] ?? '');
            $resolvedReference = $reference ?: ($order['purchase_units'][0]['reference_id'] ?? '');
            $orderStatus = strtoupper((string)($order['status'] ?? ($paypalTxn['status'] ?? '')));

            $txn = $resolvedReference !== '' ? $db->find('transactions', ['reference' => $resolvedReference]) : null;
            if ($txn && intval($txn['user_id'] ?? 0) !== intval($_SESSION['user_id'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'لا تملك صلاحية هذه العملية']); break;
            }

            if (in_array($orderStatus, ['APPROVED', 'COMPLETED'], true) && $authorizationId !== '') {
                $gatewayData = json_decode($txn['gateway_response'] ?? '{}', true) ?: [];
                $gatewayData['type'] = 'paypal_authorization';
                $gatewayData['order_id'] = $orderId;
                $gatewayData['authorization_id'] = $authorizationId;
                if (!$txn) {
                    $db->insert('transactions', [
                        'reference' => $resolvedReference,
                        'gateway' => 'paypal',
                        'amount' => floatval($authorization['amount']['value'] ?? 0),
                        'currency' => $authorization['amount']['currency_code'] ?? 'USD',
                        'customer_name' => 'Customer',
                        'customer_email' => 'guest@diparmas.com',
                        'status' => 'authorized',
                        'transaction_type' => 'PayPal Authorization',
                        'user_id' => intval($_SESSION['user_id']),
                        'fees' => 0,
                        'net_amount' => floatval($authorization['amount']['value'] ?? 0),
                        'security_mode' => '3D',
                        'gateway_response' => json_encode($gatewayData),
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                } else {
                    $db->update('transactions', ['status' => 'authorized', 'gateway_response' => json_encode($gatewayData), 'updated_at' => date('Y-m-d H:i:s')], ['reference' => $resolvedReference]);
                }
                $result = [
                    'success' => true,
                    'order_id' => $orderId,
                    'authorization_id' => $authorizationId,
                    'status' => 'authorized',
                    'reference' => $resolvedReference,
                ];
            } else {
                $result = ['success' => false, 'message' => 'PayPal authorization could not be verified (order status: ' . $orderStatus . ')'];
            }
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;

        case 'capture_authorization':
            if (!verifyCsrfToken($payload['csrf_token'] ?? '')) {
                echo json_encode(['success' => false, 'message' => 'CSRF invalid']); break;
            }
            $authorizationId = trim($payload['authorization_id'] ?? '');
            $reference = trim($payload['reference'] ?? '');
            if ($authorizationId === '') {
                echo json_encode(['success' => false, 'message' => 'authorization_id مطلوب']); break;
            }

            $txn = $reference !== '' ? $db->find('transactions', ['reference' => $reference]) : null;
            if (!$txn || intval($txn['user_id'] ?? 0) !== intval($_SESSION['user_id'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'لا تملك صلاحية هذه العملية']); break;
            }

            $result = $svc->captureAuthorization(
                $authorizationId,
                !empty($payload['amount']) ? floatval($payload['amount']) : null,
                trim($payload['currency'] ?? 'USD')
            );
            if ($result['success'] && $reference !== '') {
                $db->update('transactions', [
                    'status' => 'completed',
                    'updated_at' => date('Y-m-d H:i:s'),
                ], ['reference' => $reference]);
            }
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
            $headers = function_exists('getallheaders') ? getallheaders() : [];
            $webhookId = getenv('PAYPAL_WEBHOOK_ID') ?: '';

            if (!$svc->verifyWebhook($headers, $rawBody, $webhookId)) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Invalid PayPal webhook signature']);
                break;
            }

            $eventType = $data['event_type'] ?? '';

            if (str_contains($eventType, 'PAYMENT.CAPTURE.COMPLETED')) {
                $orderId   = $data['resource']['supplementary_data']['related_ids']['order_id'] ?? '';
                $order = $svc->getOrder($orderId);
                $reference = $order['purchase_units'][0]['reference_id']
                    ?? $data['resource']['custom_id']
                    ?? '';
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
