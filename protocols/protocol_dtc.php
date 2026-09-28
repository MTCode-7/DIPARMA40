<?php
require_once __DIR__ . '/ProtocolInterface.php';
require_once __DIR__ . '/../lib/MySystem/ChargeHub.php';

/**
 * PROTOCOL DTC — Direct Transaction Capture
 * Sale / hold / capture on the selected fully-connected gateway only.
 * Funds stay on that gateway unless Ledger CHECKOUT set the destination.
 */
final class Protocol_DTC implements ProtocolInterface
{
    public function getCode(): string
    {
        return 'DTC';
    }

    public function getName(): string
    {
        return 'Direct Transaction Capture';
    }

    public function execute(array $context): array
    {
        $amount = floatval($context['amount'] ?? 0);
        if ($amount <= 0) {
            return $this->fail('Invalid amount');
        }

        $gateway = strtolower(trim((string) (
            $context['gateway']
            ?? $context['card_provider']
            ?? $context['payment_gateway']
            ?? $context['gateway_type']
            ?? ''
        )));
        if (!function_exists('dp_gateway_is_visible_on_channels')) {
            require_once dirname(__DIR__) . '/includes/gateways.php';
        }
        if ($gateway === '' || !dp_gateway_is_visible_on_channels($gateway)) {
            return $this->fail('Gateway is not fully connected');
        }

        $txnType = strtolower(trim((string) ($context['txn_type'] ?? $context['transaction_type'] ?? 'purchase')));
        $rrn = trim((string) ($context['rrn'] ?? $context['orig_ref'] ?? $context['related_transaction_id'] ?? ''));
        $approval = trim((string) ($context['approval_code'] ?? ''));
        if ($rrn !== '' && $approval !== '' && in_array($txnType, ['capture', 'auth_capture', 'settle', ''], true)) {
            $txnType = 'capture';
        } elseif (in_array($txnType, ['auth', 'auth_hold', 'hold', 'authorize', 'auth_moto'], true)) {
            $txnType = $txnType === 'auth_moto' ? 'auth_moto' : 'auth_hold';
        } else {
            if (!class_exists('CardScaService', false)) {
                require_once dirname(__DIR__) . '/lib/CardScaService.php';
            }
            $txnType = CardScaService::shouldChallenge($context) ? 'purchase_3d' : 'purchase_2d';
        }

        try {
            $gwResp = DiParmaChargeHub::charge($gateway, $txnType, [
                'amount' => $amount,
                'currency' => strtoupper(trim((string) ($context['currency'] ?? 'USD'))),
                'reference' => $context['transaction_ref'] ?? $context['reference'] ?? uniqid('dtc_', true),
                'card_number' => $context['cc_number'] ?? $context['card_number'] ?? '',
                'card_expiry' => $context['cc_expiry'] ?? $context['card_expiry'] ?? '',
                'card_cvv' => $context['cvv2'] ?? $context['cc_cvv'] ?? $context['card_cvv'] ?? '',
                'cvv2' => $context['cvv2'] ?? $context['cc_cvv'] ?? $context['card_cvv'] ?? '',
                'card_name' => $context['card_name'] ?? $context['customer_name'] ?? $context['name'] ?? 'Customer',
                'name' => $context['name'] ?? $context['customer_name'] ?? 'Customer',
                'email' => $context['email'] ?? '',
                'rrn' => $rrn,
                'orig_ref' => $rrn,
                'related_transaction_id' => $rrn,
                'approval_code' => $approval,
                'cloud_token' => $context['cloud_token'] ?? $context['source_id'] ?? $context['payment_token'] ?? null,
                'source_id' => $context['source_id'] ?? $context['cloud_token'] ?? null,
                'processing_mode' => $txnType === 'purchase_3d' ? '3D' : '2D',
                'destination' => (($context['ledger_checkout'] ?? '') === '1' || ($context['destination'] ?? '') === 'ledger')
                    ? 'ledger'
                    : 'gateway',
                'channel' => 'protocol_dtc',
                'protocol' => $this->getCode(),
            ]);
        } catch (Throwable $e) {
            return $this->fail('DTC charge failed: ' . $e->getMessage());
        }

        $pending = !empty($gwResp['requires_3ds']) || !empty($gwResp['redirect_url']) || !empty($gwResp['checkout_url']);
        $ok = is_array($gwResp) && (!empty($gwResp['success']) || $pending);

        return [
            'success' => !empty($gwResp['success']),
            'requires_3ds' => $pending,
            'redirect_url' => $gwResp['redirect_url'] ?? $gwResp['checkout_url'] ?? '',
            'protocol' => $this->getCode(),
            'name' => $this->getName(),
            'amount' => $amount,
            'currency' => strtoupper(trim((string) ($context['currency'] ?? 'USD'))),
            'gateway' => $gateway,
            'transaction_type' => strtoupper($txnType),
            'mode' => 'LIVE',
            'message' => $pending
                ? ($gwResp['message'] ?? '3DS_REQUIRED')
                : ($ok
                    ? 'DTC captured on ' . $gateway
                    : (string) ($gwResp['message'] ?? 'DTC declined')),
            'gateway_response' => $gwResp,
        ];
    }

    private function fail(string $message): array
    {
        return [
            'success' => false,
            'protocol' => $this->getCode(),
            'name' => $this->getName(),
            'message' => $message,
        ];
    }
}

function create_dtc_protocol_instance(): ProtocolInterface
{
    return new Protocol_DTC();
}
