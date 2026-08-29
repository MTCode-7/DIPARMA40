<?php
require_once __DIR__ . '/ProtocolInterface.php';
require_once __DIR__ . '/../includes/gateways.php';

/**
 * 1. بروتوكول حجز المبلغ - تفويض (HOLD / AUTHORIZE) [البروتوكول: 101.1]
 */
final class Protocol_101_1_Hold implements ProtocolInterface {
    public function getCode(): string { return '101.1'; }
    public function getName(): string { return 'Standard Visa/Mastercard Authorization Hold'; }

    public function execute(array $context): array {
        $amount = floatval($context['amount'] ?? 0);
        if ($amount <= 0) {
            return ['success' => false, 'message' => '❌ المبلغ غير صحيح'];
        }

        $gateway  = strtolower(trim($context['gateway_type'] ?? $context['payment_gateway'] ?? ''));
        $currency = strtoupper(trim($context['currency'] ?? 'USD'));

        // التحقق من CVV2
        $cvv2 = trim((string)($context['cvv2'] ?? $context['cvv'] ?? ''));
        if (!empty($cvv2) && !preg_match('/^\d{3,4}$/', $cvv2)) {
            return ['success' => false, 'message' => '❌ خطأ: رمز التحقق CVV2 يجب أن يتكون من 3 أو 4 أرقام فقط'];
        }

        $authorizationId = 'AUTH_' . strtoupper(bin2hex(random_bytes(8)));

        $result = [
            'success'          => true,
            'protocol'         => $this->getCode(),
            'name'             => $this->getName(),
            'action'           => 'HOLD',
            'status'           => 'authorized',
            'amount'           => $amount,
            'currency'         => $currency,
            'gateway'          => $gateway,
            'transaction_type' => 'AUTHORIZE_ONLY',
            'authorization_id' => $authorizationId,
            'mode'             => 'LIVE',
            'message'          => '✅ تم حجز المبلغ بنجاح - ينتظر الإنجاز أو التسديد',
        ];

        if (!empty($gateway)) {
            $securityMode = strtoupper(trim($context['security_mode'] ?? $context['secure_mode'] ?? '3D'));
            $payload = [
                'order_ref'        => $authorizationId,
                'amount'           => $amount,
                'currency'         => $currency,
                'customer_name'    => $context['customer_name'] ?? 'Customer',
                'description'      => 'حجز مبلغ - ' . $this->getName(),
                'transaction_type' => 'AUTHORIZE_ONLY',
                'source'           => $context['source'] ?? 'web',
                'security_mode'    => $securityMode,
                'secure_mode'      => ($securityMode === '3D') ? '3ds' : '2d',
                'cvv2'             => $cvv2, // إرسال رمز التحقق لحظياً
            ];
            try {
                $result['gateway_response'] = gateway_service()->createPaymentIntent($gateway, $payload);
            } catch (Throwable $e) {
                $result['success'] = false;
                $result['message'] = '❌ فشل الحجز: ' . $e->getMessage();
            }
        }

        return $result;
    }
}

/**
 * 2. بروتوكول تسوية وتحصيل المبلغ (COMPLETION / CAPTURE) [البروتوكول: 101.1]
 */
final class Protocol_101_1_Completion implements ProtocolInterface {
    public function getCode(): string { return '101.1'; }
    public function getName(): string { return 'Standard Visa/Mastercard Settlement & Completion'; }

    public function execute(array $context): array {
        $amount = floatval($context['amount'] ?? 0);
        if ($amount <= 0) {
            return ['success' => false, 'message' => '❌ المبلغ غير صحيح'];
        }

        $authorizationId = trim((string)($context['authorization_id'] ?? ''));
        if (empty($authorizationId)) {
            return ['success' => false, 'message' => '❌ معرف التفويض (Authorization ID) مفقود لإتمام عملية التحصيل.'];
        }

        $gateway      = strtolower(trim($context['gateway_type'] ?? $context['payment_gateway'] ?? ''));
        $currency     = strtoupper(trim($context['currency'] ?? 'USD'));

        // التحقق من CVV2
        $cvv2 = trim((string)($context['cvv2'] ?? $context['cvv'] ?? ''));
        if (!empty($cvv2) && !preg_match('/^\d{3,4}$/', $cvv2)) {
            return ['success' => false, 'message' => '❌ خطأ: رمز التحقق CVV2 يجب أن يتكون من 3 أو 4 أرقام فقط'];
        }

        $completionId = 'COMP_' . strtoupper(bin2hex(random_bytes(8)));
        $approvalCode = trim((string)($context['approval_code'] ?? $context['manager_approval'] ?? ''));
        $allowBypass  = !empty($context['allow_approval_bypass']);

        if (empty($gateway)) {
            return ['success' => false, 'message' => '❌ لم يتم تحديد بوابة دفع حية.'];
        }

        $securityMode = strtoupper(trim($context['security_mode'] ?? $context['secure_mode'] ?? '3D'));
        $payload = [
            'order_ref'        => $completionId,
            'amount'           => $amount,
            'currency'         => $currency,
            'customer_name'    => $context['customer_name'] ?? 'Customer',
            'description'      => 'إنجاز مبلغ محجوز - ' . $this->getName(),
            'transaction_type' => 'CAPTURE_ONLY',
            'authorization_id' => $authorizationId,
            'security_mode'    => $securityMode,
            'secure_mode'      => ($securityMode === '3D') ? '3ds' : '2d',
            'cvv2'             => $cvv2, // إرسال رمز التحقق لحظياً
        ];

        if (!empty($approvalCode)) {
            $payload['approval_code'] = $approvalCode;
        }

        try {
            $gwResp = gateway_service()->createPaymentIntent($gateway, $payload);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => '❌ فشل الاتصال بالبوابة: ' . $e->getMessage()];
        }

        // فحص إذا كانت العملية تتطلب موافقة
        $requiresApproval = false;
        if (is_array($gwResp)) {
            foreach (['requires_approval','approval_required','manager_approval_required','pending_approval'] as $k) {
                if (array_key_exists($k, $gwResp) && filter_var($gwResp[$k], FILTER_VALIDATE_BOOLEAN)) {
                    $requiresApproval = true;
                    break;
                }
            }
        }

        if ($requiresApproval && $approvalCode === '' && !$allowBypass) {
            // في وضع 2D — نتجاوز طلب الموافقة تلقائياً
            $secMode = strtoupper(trim($context['security_mode'] ?? '3D'));
            if ($secMode === '2D') {
                $allowBypass = true;
            }
        }

        if ($requiresApproval && $approvalCode === '' && !$allowBypass) {
            return [
                'success'          => false,
                'requires_approval'=> true,
                'message'          => '🔐 العملية تتطلب رمز موافقة (Approval Code) لإتمامها',
                'gateway_response' => $gwResp,
                'status'           => 'pending_approval',
                'protocol'         => $this->getCode(),
            ];
        }

        $isSuccess = is_array($gwResp) && (!isset($gwResp['success']) || $gwResp['success'] === true);

        return [
            'success'          => $isSuccess,
            'protocol'         => $this->getCode(),
            'name'             => $this->getName(),
            'action'           => 'COMPLETION',
            'status'           => $isSuccess ? 'completed' : 'failed',
            'amount'           => $amount,
            'currency'         => $currency,
            'gateway'          => $gateway,
            'authorization_id' => $authorizationId,
            'reference_id'     => $completionId,
            'message'          => $isSuccess
                ? '✅ تم إنجاز وتحصيل المبلغ بنجاح.'
                : '❌ فشل التنفيذ: ' . ($gwResp['message'] ?? 'Gateway error'),
            'gateway_response' => $gwResp,
        ];
    }
}

// Factory functions
function create_auth_protocol_instance(): ProtocolInterface {
    return new Protocol_101_1_Hold();
}

function create_settlement_protocol_instance_101(): ProtocolInterface {
    return new Protocol_101_1_Completion();
}
