<?php
/**
 * DI PARMA | PayPal API
 * GET  /api/paypal.php?action=client_token
 * POST /api/paypal.php?action=create_order
 * POST /api/paypal.php?action=capture_order
 * POST /api/paypal.php?action=add_tracker
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

            if (!empty($result['success']) && $reference !== '' && !$db->find('transactions', ['reference' => $reference])) {
                try {
                    $db->insert('transactions', [
                        'reference' => $reference,
                        'gateway' => 'paypal',
                        'amount' => $amount,
                        'currency' => strtoupper($currency),
                        'customer_name' => trim((string) ($payload['name'] ?? 'Customer')) ?: 'Customer',
                        'customer_email' => trim((string) ($payload['email'] ?? 'guest@diparmas.com')) ?: 'guest@diparmas.com',
                        'status' => 'pending',
                        'transaction_type' => $transactionType,
                        'user_id' => intval($_SESSION['user_id'] ?? 0),
                        'fees' => 0,
                        'net_amount' => $amount,
                        'security_mode' => '3D',
                        'gateway_response' => json_encode([
                            'type' => 'paypal_order',
                            'order_id' => $result['order_id'] ?? '',
                            'intent' => $intent,
                            'destination' => $destination,
                        ]),
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                    $result['persisted'] = true;
                } catch (Throwable $e) {
                    $result['persisted'] = false;
                    $result['persist_error'] = $e->getMessage();
                }
            }

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
            if ($authorizationId === '' && $orderStatus === 'APPROVED') {
                $authorized = $svc->authorizeOrder($orderId);
                if (empty($authorized['success'])) {
                    echo json_encode($authorized, JSON_UNESCAPED_UNICODE);
                    break;
                }
                $authorizationId = trim((string) ($authorized['authorization_id'] ?? ''));
                $orderStatus = 'COMPLETED';
                $authorization = [
                    'id' => $authorizationId,
                    'amount' => [
                        'value' => $authorized['amount'] ?? ($order['purchase_units'][0]['amount']['value'] ?? 0),
                        'currency_code' => $authorized['currency'] ?? ($order['purchase_units'][0]['amount']['currency_code'] ?? 'USD'),
                    ],
                ];
            }

            $txn = $resolvedReference !== '' ? $db->find('transactions', ['reference' => $resolvedReference]) : null;
            if ($txn && intval($txn['user_id'] ?? 0) !== intval($_SESSION['user_id'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'لا تملك صلاحية هذه العملية']); break;
            }

            if (in_array($orderStatus, ['APPROVED', 'COMPLETED'], true) && $authorizationId !== '') {
                $gatewayData = is_array($txn) ? (json_decode($txn['gateway_response'] ?? '{}', true) ?: []) : [];
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

            $authorizedAmount = (float)($txn['amount'] ?? 0);
            $captureAmount = !empty($payload['amount']) ? (float)$payload['amount'] : $authorizedAmount;
            if ($authorizedAmount <= 0 || $captureAmount <= 0 || $captureAmount > $authorizedAmount) {
                echo json_encode([
                    'success' => false,
                    'message' => 'مبلغ التحصيل يجب أن يكون أكبر من صفر ولا يتجاوز مبلغ التفويض (' . number_format($authorizedAmount, 2, '.', '') . ')',
                ], JSON_UNESCAPED_UNICODE); break;
            }

            $result = $svc->captureAuthorization(
                $authorizationId,
                $captureAmount,
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

                $ref = $reference ?: ($result['reference'] ?? '');
                if ($ref !== '') {
                    require_once __DIR__.'/../lib/LedgerSettlementService.php';
                    $txn = $db->find('transactions', ['reference' => $ref]);
                    $settle = LedgerSettlementService::getInstance()->settleToLedger([
                        'reference'      => $ref,
                        'amount'         => (float)($txn['amount'] ?? 0),
                        'currency'       => (string)($txn['currency'] ?? 'USD'),
                        'gateway'        => 'paypal',
                        'ledger_address' => '',
                        'user_id'        => (int)($txn['user_id'] ?? 0),
                        'txn_type'       => 'purchase',
                        'destination'    => 'ledger',
                    ]);
                    $result['ledger_settlement'] = $settle;
                }
            }

            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;

        case 'list_webhooks':
        case 'create_webhook':
        case 'get_webhook':
        case 'update_webhook':
        case 'delete_webhook':
        case 'list_web_profiles':
        case 'create_web_profile':
        case 'get_web_profile':
        case 'update_web_profile':
        case 'delete_web_profile':
            if (!verifyCsrfToken($payload['csrf_token'] ?? '')) {
                echo json_encode(['success' => false, 'message' => 'CSRF invalid']); break;
            }
            if ($action === 'list_webhooks') {
                $result = $svc->listWebhooks();
            } elseif ($action === 'create_webhook') {
                $events = $payload['event_types'] ?? [];
                if (isset($events[0]['name'])) {
                    $events = array_column($events, 'name');
                }
                $result = $svc->createWebhook(trim((string) ($payload['url'] ?? '')), is_array($events) ? $events : []);
            } elseif ($action === 'get_webhook') {
                $result = $svc->getWebhook(trim((string) ($payload['webhook_id'] ?? '')));
            } elseif ($action === 'update_webhook') {
                $patch = $payload['patch'] ?? [];
                $result = $svc->updateWebhook(trim((string) ($payload['webhook_id'] ?? '')), is_array($patch) ? $patch : []);
            } elseif ($action === 'delete_webhook') {
                $result = $svc->deleteWebhook(trim((string) ($payload['webhook_id'] ?? '')));
            } elseif ($action === 'list_web_profiles') {
                $result = $svc->listWebProfiles();
            } elseif ($action === 'create_web_profile') {
                $profile = $payload['profile'] ?? $payload;
                unset($profile['csrf_token'], $profile['action']);
                $result = $svc->createWebProfile(is_array($profile) ? $profile : []);
            } elseif ($action === 'get_web_profile') {
                $result = $svc->getWebProfile(trim((string) ($payload['profile_id'] ?? $payload['id'] ?? '')));
            } elseif ($action === 'update_web_profile') {
                $profile = $payload['profile'] ?? [];
                $result = $svc->replaceWebProfile(trim((string) ($payload['profile_id'] ?? $payload['id'] ?? '')), is_array($profile) ? $profile : []);
            } else {
                $result = $svc->deleteWebProfile(trim((string) ($payload['profile_id'] ?? $payload['id'] ?? '')));
            }
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;

        case 'add_tracker':
        case 'update_tracker':
        case 'get_tracker':
        case 'list_trackers':
            if (!verifyCsrfToken($payload['csrf_token'] ?? '')) {
                echo json_encode(['success' => false, 'message' => 'CSRF invalid']); break;
            }
            if ($action === 'list_trackers') {
                $result = $svc->listTrackers(
                    trim((string) ($payload['transaction_id'] ?? '')),
                    trim((string) ($payload['tracking_number'] ?? ''))
                );
            } elseif ($action === 'get_tracker') {
                $result = $svc->getTracker(trim((string) ($payload['tracker_id'] ?? $payload['id'] ?? '')));
            } elseif ($action === 'update_tracker') {
                $result = $svc->updateTracker(
                    trim((string) ($payload['tracker_id'] ?? $payload['id'] ?? '')),
                    trim((string) ($payload['transaction_id'] ?? '')),
                    trim((string) ($payload['tracking_number'] ?? '')),
                    trim((string) ($payload['status'] ?? 'SHIPPED')),
                    trim((string) ($payload['carrier'] ?? 'OTHER')),
                    [
                        'carrier_name_other' => $payload['carrier_name_other'] ?? '',
                        'shipment_date' => $payload['shipment_date'] ?? '',
                    ]
                );
            } else {
                $result = $svc->addTracker(
                    trim((string) ($payload['transaction_id'] ?? '')),
                    trim((string) ($payload['tracking_number'] ?? '')),
                    trim((string) ($payload['status'] ?? 'SHIPPED')),
                    trim((string) ($payload['carrier'] ?? 'OTHER')),
                    [
                        'carrier_name_other' => $payload['carrier_name_other'] ?? '',
                        'shipment_date' => $payload['shipment_date'] ?? '',
                    ]
                );
            }
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;

        // ── Webhook ──────────────────────────────────────────
        case 'webhook':
            $rawBody = file_get_contents('php://input');
            $data    = json_decode($rawBody, true);
            $headers = function_exists('getallheaders') ? getallheaders() : [];
            $paypalGateway = $db->find('payment_gateways', ['code' => 'paypal']);
            $paypalSettings = json_decode($paypalGateway['settings'] ?? '{}', true) ?: [];
            $webhookId = $paypalSettings['webhook_id'] ?? (getenv('PAYPAL_WEBHOOK_ID') ?: '');

            if (!$svc->verifyWebhook($headers, $rawBody, $webhookId)) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Invalid PayPal webhook signature']);
                break;
            }

            $eventType = strtoupper((string) ($data['event_type'] ?? ''));
            $statusMap = [
                'PAYMENT.CAPTURE.COMPLETED' => 'completed',
                'PAYMENT.CAPTURE.DENIED' => 'declined',
                'PAYMENT.CAPTURE.REFUNDED' => 'refunded',
                'PAYMENT.AUTHORIZATION.CREATED' => 'authorized',
                'PAYMENT.AUTHORIZATION.VOIDED' => 'cancelled',
            ];
            if (isset($statusMap[$eventType])) {
                $resource = is_array($data['resource'] ?? null) ? $data['resource'] : [];
                $orderId = (string) ($resource['supplementary_data']['related_ids']['order_id'] ?? '');
                $reference = '';
                if ($orderId !== '') {
                    $order = $svc->getOrder($orderId);
                    $reference = (string) ($order['purchase_units'][0]['reference_id'] ?? '');
                }
                if ($reference === '') {
                    $reference = (string) ($resource['custom_id'] ?? $resource['invoice_id'] ?? '');
                }
                if ($reference !== '') {
                    $db->update('transactions', [
                        'status' => $statusMap[$eventType],
                        'updated_at' => date('Y-m-d H:i:s'),
                    ], ['reference' => $reference]);
                    if ($statusMap[$eventType] === 'completed') {
                        try {
                            require_once __DIR__.'/../lib/LedgerSettlementService.php';
                            $txn = $db->find('transactions', ['reference' => $reference]);
                            if ($txn) {
                                LedgerSettlementService::getInstance()->settleToLedger([
                                    'reference' => $reference,
                                    'amount'    => (float)$txn['amount'],
                                    'currency'  => (string)($txn['currency'] ?? 'USD'),
                                    'gateway'   => 'paypal',
                                    'user_id'   => (int)($txn['user_id'] ?? 0),
                                    'txn_type'  => 'purchase',
                                    'destination' => 'ledger',
                                ]);
                            }
                        } catch (Throwable $e) {
                            error_log('[PayPal][Ledger] ' . $e->getMessage());
                        }
                    }
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
