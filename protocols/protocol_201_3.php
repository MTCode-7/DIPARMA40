<?php
require_once __DIR__ . '/ProtocolInterface.php';
require_once __DIR__ . '/../includes/gateways.php';

/**
 * بروتوكول 201.3 - يدعم الحجز المسبق (Pre-Authorization) والتسوية المباشرة
 */
final class Protocol_201_3 implements ProtocolInterface {
    public function getCode(): string { return '201.3'; }
    public function getName(): string { return 'Corporate Direct Settlement Tunnel'; }

    public function execute(array $context): array {
        $amount = floatval($context['amount'] ?? 0);
        if ($amount <= 0) {
            return ['success' => false, 'message' => 'Invalid amount'];
        }

        // 1. استخراج RRN و Approval Code (إذا وجدا)
        $rrn          = trim((string)($context['rrn'] ?? ''));
        $approvalCode = trim((string)($context['approval_code'] ?? $context['manager_approval'] ?? ''));
        $gateway      = strtolower(trim($context['gateway_type'] ?? $context['payment_gateway'] ?? ''));

        // 2. الحالة الأهم: إذا كان لدينا RRN و Approval Code، فهذا حجز مسبق!
        if (!empty($rrn) && !empty($approvalCode)) {
            return $this->completePreAuthorizedTransaction($gateway, $amount, $context, $rrn, $approvalCode);
        }

        // 3. إذا لم يكن هناك RRN أو Code، نتحقق من وجود بيانات البطاقة لإجراء حجز جديد
        if (empty($context['cc_number']) || empty($context['cc_expiry'])) {
            return ['success' => false, 'message' => 'Processing Failed: Missing card details, or RRN/Approval Code is required.'];
        }

        // 4. التحقق من CVV2 (اختياري)
        $cvv2 = trim((string)($context['cvv2'] ?? $context['cc_cvv'] ?? ''));
        if (!empty($cvv2) && !preg_match('/^\d{3,4}$/', $cvv2)) {
            return ['success' => false, 'message' => '❌ خطأ: رمز التحقق CVV2 يجب أن يتكون من 3 أو 4 أرقام فقط'];
        }

        // 5. إجراء عملية حجز/دفع جديدة
        return $this->requestNewAuthorization($gateway, $amount, $context, $cvv2);
    }

    // ============================================================
    // الحالة 1: إتمام عملية حجز مسبق (باستخدام RRN و Approval Code)
    // ============================================================
    private function completePreAuthorizedTransaction($gateway, $amount, $context, $rrn, $approvalCode): array {
        try {
            // إرسال طلب التسوية (Capture) إلى البوابة باستخدام الـ RRN والكود
            $payload = [
                'order_ref'     => $context['transaction_ref'] ?? uniqid('dir_', true),
                'amount'        => $amount,
                'currency'      => strtoupper(trim($context['currency'] ?? 'USD')),
                'source'        => 'backend_dashboard',
                'payment_type'  => 'MOTO_SETTLEMENT',
                'rrn'           => $rrn,
                'approval_code' => $approvalCode,
                'customer_name' => $context['customer_name'] ?? 'VIP Client',
                'description'   => 'Settlement of Pre-Authorization - ' . $this->getName(),
            ];

            $gwResp = gateway_service()->settlePreAuthorization($gateway, $payload);
            $isSuccess = is_array($gwResp) && (!isset($gwResp['success']) || $gwResp['success'] === true);

            return [
                'success'          => $isSuccess,
                'protocol'         => $this->getCode(),
                'name'             => $this->getName(),
                'amount'           => $amount,
                'currency'         => strtoupper(trim($context['currency'] ?? 'USD')),
                'gateway'          => $gateway,
                'transaction_type' => 'PRE_AUTH_SETTLEMENT',
                'mode'             => 'LIVE',
                'message'          => $isSuccess
                    ? '✅ Pre-authorization settled successfully using RRN & Approval Code.'
                    : '❌ Settlement failed: ' . ($gwResp['message'] ?? ''),
                'gateway_response' => $gwResp,
            ];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Settlement call failed: ' . $e->getMessage()];
        }
    }

    // ============================================================
    // الحالة 2: إجراء حجز جديد (باستخدام بيانات البطاقة)
    // ============================================================
    private function requestNewAuthorization($gateway, $amount, $context, $cvv2): array {
        $payload = [
            'order_ref'     => $context['transaction_ref'] ?? uniqid('dir_', true),
            'amount'        => $amount,
            'currency'      => strtoupper(trim($context['currency'] ?? 'USD')),
            'source'        => 'backend_dashboard',
            'payment_type'  => 'MOTO',
            'card_number'   => $context['cc_number'],
            'card_expiry'   => $context['cc_expiry'],
            'cvv2'          => $cvv2,
            'customer_name' => $context['customer_name'] ?? 'VIP Client',
            'description'   => 'High Value Corporate Charge - ' . $this->getName(),
        ];

        try {
            $gwResp = gateway_service()->createPaymentIntent($gateway, $payload);
            $isSuccess = is_array($gwResp) && (!isset($gwResp['success']) || $gwResp['success'] === true);

            return [
                'success'          => $isSuccess,
                'protocol'         => $this->getCode(),
                'name'             => $this->getName(),
                'amount'           => $amount,
                'currency'         => strtoupper(trim($context['currency'] ?? 'USD')),
                'gateway'          => $gateway,
                'transaction_type' => 'PENDING_REVIEW',
                'mode'             => 'LIVE',
                'message'          => $isSuccess
                    ? '✅ Authorization requested successfully. Please obtain RRN & Approval Code from the bank.'
                    : '❌ Gateway authorization failed: ' . ($gwResp['message'] ?? ''),
                'gateway_response' => $gwResp,
                'requires_approval_code' => true,
                'requires_rrn'           => true
            ];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Gateway call failed: ' . $e->getMessage()];
        }
    }
}

function create_settlement_protocol_instance(): ProtocolInterface {
    return new Protocol_201_3();
}

function create_moto_protocol_instance(): ProtocolInterface {
    return new Protocol_201_3();
}