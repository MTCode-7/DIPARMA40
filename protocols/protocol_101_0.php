<?php
require_once __DIR__ . '/ProtocolInterface.php';
require_once __DIR__ . '/../includes/gateways.php';

final class Protocol_101_0 implements ProtocolInterface {
    public function getCode(): string { return '101.0'; }
    public function getName(): string { return 'Direct Card Settlement (101.0)'; }

    public function execute(array $context): array {
        $amount = floatval($context['amount'] ?? 0);
        if ($amount <= 0) {
            return ['success' => false, 'message' => '❌ المبلغ غير صحيح', 'protocol' => $this->getCode()];
        }

        $gateway = strtolower(trim($context['gateway_type'] ?? $context['gateway_code'] ?? $context['payment_gateway'] ?? ''));
        if (empty($gateway)) {
            return ['success' => false, 'message' => '❌ لم يتم تحديد بوابة الدفع', 'protocol' => $this->getCode()];
        }

        $currency = strtoupper(trim($context['currency'] ?? 'USD'));
        $authorizationId = trim((string)($context['authorization_id'] ?? ''));
        $otpCode = trim((string)($context['otp_code'] ?? ''));
        $allowBypass = !empty($context['allow_otp_bypass']);
        $securityMode = strtoupper(trim($context['security_mode'] ?? $context['secure_mode'] ?? '2D'));
        $secureMode = ($securityMode === '3D') ? '3ds' : '2d';

        $ref = $context['transaction_ref'] ?? 'TXN_' . strtoupper(bin2hex(random_bytes(6)));
        $payload = [
            'order_ref'        => $ref,
            'amount'           => $amount,
            'currency'         => $currency,
            'customer_name'    => $context['customer_name'] ?? 'Customer',
            'customer_email'   => $context['customer_email'] ?? '',
            'customer_phone'   => $context['customer_phone'] ?? '',
            'card_pan'         => trim($context['card_pan'] ?? ''),
            'card_expiry'      => trim($context['card_expiry'] ?? ''),
            'card_cvv'         => trim($context['card_cvv'] ?? ''),
            'description'      => 'Direct 101.0 card settlement',
            'transaction_type' => 'SALE',
            'payment_method'   => 'card',
            'source'           => $context['source'] ?? 'web',
            'otp_code'         => $otpCode,
            'allow_otp_bypass' => $allowBypass,
            'security_mode'    => $securityMode,
            'secure_mode'      => $secureMode
        ];

        try {
            $gatewayResponse = gateway_service()->createPaymentIntent($gateway, $payload);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => '❌ فشل اتصال البوابة: ' . $e->getMessage(), 'protocol' => $this->getCode()];
        }

        $response = is_array($gatewayResponse) ? $gatewayResponse : [];
        $requiresOtp = false;
        $otpHint = null;
        $otpKeys = ['requires_otp', 'authentication_required', 'challenge_required', '3ds_required', 'otp_required'];

        foreach ($otpKeys as $key) {
            if (array_key_exists($key, $response) && filter_var($response[$key], FILTER_VALIDATE_BOOLEAN)) {
                $requiresOtp = true;
                $otpHint = $response[$key];
                break;
            }
        }

        if (!$requiresOtp && !empty($response['response']) && is_array($response['response'])) {
            foreach ($otpKeys as $key) {
                if (array_key_exists($key, $response['response']) && filter_var($response['response'][$key], FILTER_VALIDATE_BOOLEAN)) {
                    $requiresOtp = true;
                    $otpHint = $response['response'][$key];
                    break;
                }
            }
        }

        if ($requiresOtp && $otpCode === '' && !$allowBypass) {
            return [
                'success' => false,
                'requires_otp' => true,
                'message' => '🔐 تم طلب رمز تحقق OTP من البنك لإتمام عملية 101.0',
                'otp_hint' => $otpHint,
                'otp_challenge_id' => $response['otp_challenge_id'] ?? null,
                'gateway_response' => $response,
                'protocol' => $this->getCode(),
                'status' => 'pending_otp'
            ];
        }

        if (!empty($response['success'])) {
            return [
                'success' => true,
                'message' => '✅ تم تنفيذ الدفع المباشر 101.0 بنجاح',
                'gateway_response' => $response,
                'protocol' => $this->getCode(),
                'status' => $response['status'] ?? 'captured'
            ];
        }

        return [
            'success' => false,
            'message' => '❌ فشل تنفيذ 101.0: ' . ($response['message'] ?? 'Gateway error'),
            'gateway_response' => $response,
            'protocol' => $this->getCode(),
            'status' => $response['status'] ?? 'failed'
        ];
    }
}

function create_direct_withdrawal_protocol_instance(): ProtocolInterface {
    return new Protocol_101_0();
}
