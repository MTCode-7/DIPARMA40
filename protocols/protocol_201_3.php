<?php
require_once __DIR__ . '/ProtocolInterface.php';
require_once __DIR__ . '/../lib/MySystem/ChargeHub.php';

/**
 * بروتوكول 201.3 — حجز مسبق أو شراء MOTO عبر ChargeHub فقط.
 */
final class Protocol_201_3 implements ProtocolInterface {
    public function getCode(): string { return '201.3'; }
    public function getName(): string { return 'Corporate Direct Settlement Tunnel'; }

    public function execute(array $context): array {
        $amount = floatval($context['amount'] ?? 0);
        if ($amount <= 0) {
            return ['success' => false, 'message' => 'Invalid amount'];
        }

        $rrn          = trim((string)($context['rrn'] ?? ''));
        $approvalCode = trim((string)($context['approval_code'] ?? $context['manager_approval'] ?? ''));
        $gateway      = strtolower(trim((string)($context['gateway_type'] ?? $context['payment_gateway'] ?? $context['gateway'] ?? '')));

        if ($rrn !== '' && $approvalCode !== '') {
            return $this->completePreAuthorizedTransaction($gateway, $amount, $context, $rrn, $approvalCode);
        }

        if (empty($context['cc_number']) || empty($context['cc_expiry'])) {
            return ['success' => false, 'message' => 'Processing Failed: Missing card details, or RRN/Approval Code is required.'];
        }

        $cvv2 = trim((string)($context['cvv2'] ?? $context['cc_cvv'] ?? ''));
        if ($cvv2 !== '' && !preg_match('/^\d{3,4}$/', $cvv2)) {
            return ['success' => false, 'message' => 'CVV2 must be 3 or 4 digits'];
        }

        return $this->requestNewAuthorization($gateway, $amount, $context, $cvv2);
    }

    private function completePreAuthorizedTransaction($gateway, $amount, $context, $rrn, $approvalCode): array {
        try {
            $gwResp = DiParmaChargeHub::charge((string) $gateway, 'capture', [
                'amount' => $amount,
                'currency' => strtoupper(trim((string)($context['currency'] ?? 'USD'))),
                'reference' => $context['transaction_ref'] ?? uniqid('dir_', true),
                'rrn' => $rrn,
                'orig_ref' => $rrn,
                'related_transaction_id' => $rrn,
                'approval_code' => $approvalCode,
                'card_name' => $context['customer_name'] ?? 'VIP Client',
                'channel' => 'protocol_201_3',
            ]);
            $isSuccess = is_array($gwResp) && !empty($gwResp['success']);

            return [
                'success'          => $isSuccess,
                'protocol'         => $this->getCode(),
                'name'             => $this->getName(),
                'amount'           => $amount,
                'currency'         => strtoupper(trim((string)($context['currency'] ?? 'USD'))),
                'gateway'          => $gateway,
                'transaction_type' => 'PRE_AUTH_SETTLEMENT',
                'mode'             => 'LIVE',
                'message'          => $isSuccess
                    ? 'Pre-authorization settled via ChargeHub.'
                    : 'Settlement failed: ' . ($gwResp['message'] ?? ''),
                'gateway_response' => $gwResp,
            ];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Settlement call failed: ' . $e->getMessage()];
        }
    }

    private function requestNewAuthorization($gateway, $amount, $context, $cvv2): array {
        try {
            $gwResp = DiParmaChargeHub::charge((string) $gateway, 'auth_moto', [
                'amount' => $amount,
                'currency' => strtoupper(trim((string)($context['currency'] ?? 'USD'))),
                'reference' => $context['transaction_ref'] ?? uniqid('dir_', true),
                'card_number' => $context['cc_number'] ?? '',
                'card_expiry' => $context['cc_expiry'] ?? '',
                'card_cvv' => $cvv2,
                'cvv2' => $cvv2,
                'card_name' => $context['customer_name'] ?? 'VIP Client',
                'processing_mode' => '2D',
                'is_moto' => true,
                'channel' => 'protocol_201_3',
            ]);
            $isSuccess = is_array($gwResp) && !empty($gwResp['success']);

            return [
                'success'          => $isSuccess,
                'protocol'         => $this->getCode(),
                'name'             => $this->getName(),
                'amount'           => $amount,
                'currency'         => strtoupper(trim((string)($context['currency'] ?? 'USD'))),
                'gateway'          => $gateway,
                'transaction_type' => 'PENDING_REVIEW',
                'mode'             => 'LIVE',
                'message'          => $isSuccess
                    ? 'Authorization requested via ChargeHub.'
                    : 'Gateway authorization failed: ' . ($gwResp['message'] ?? ''),
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
