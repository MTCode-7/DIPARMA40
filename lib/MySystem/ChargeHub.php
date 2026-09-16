<?php
/**
 * DI PARMA | ChargeHub — canonical charge entry
 *
 * Dashboard / POS / API / orchestrators charge here only:
 *   ChargeHub → POS live pipe (pos_run_payment_orchestrator)
 *            → else GatewayAdapterFactory (checkout adapters)
 *
 * Ledger settlement stays with the caller after a successful capture.
 */
class DiParmaChargeHub
{
    public static function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function ensurePosLoaded(): void
    {
        if (!defined('POS_APP_ROOT')) {
            define('POS_APP_ROOT', self::projectRoot());
        }
        require_once POS_APP_ROOT . '/pos/lib/gateways.php';
    }

    public static function ensureFactoryLoaded(): void
    {
        if (class_exists('GatewayAdapterFactory', false)) {
            return;
        }
        require_once self::projectRoot() . '/lib/Adapters/GatewayAdapterFactory.php';
    }

    public static function normalizeGateway(string $gateway): string
    {
        $g = strtolower(trim($gateway));
        if ($g === '' || $g === 'diparma_gateway') {
            return $g === 'diparma_gateway' ? 'diparma_gateway' : '';
        }
        self::ensurePosLoaded();
        if (function_exists('pos_normalize_gateway')) {
            $pos = pos_normalize_gateway($g);
            if ($pos !== '') {
                return $pos;
            }
        }
        $aliases = [
            'di_parma' => 'diparma',
            'di-parma' => 'diparma',
            'gate.io' => 'gate_io',
            'gateio' => 'gate_io',
            'checkout.com' => 'checkout',
            'authorize_net' => 'authorizenet',
            'authnet' => 'authorizenet',
        ];
        return $aliases[$g] ?? $g;
    }

    /** Live POS processor (visible + chargeable). */
    public static function supports(string $gateway): bool
    {
        self::ensurePosLoaded();
        $gw = self::normalizeGateway($gateway);
        if ($gw === '' || $gw === 'diparma_gateway') {
            return false;
        }
        return function_exists('pos_is_charge_processor')
            && pos_is_charge_processor($gw)
            && function_exists('pos_gateway_is_live')
            && pos_gateway_is_live($gw);
    }

    /** Chargeable via POS pipe or a known adapter. */
    public static function canCharge(string $gateway): bool
    {
        if (self::supports($gateway)) {
            return true;
        }
        $gw = self::normalizeGateway($gateway);
        if ($gw === '' || $gw === 'diparma_gateway') {
            return false;
        }
        self::ensureFactoryLoaded();
        return GatewayAdapterFactory::isSupported($gw);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public static function charge(string $gateway, string $txnType, array $params): array
    {
        $gw = self::normalizeGateway($gateway);
        if ($gw === '' || $gw === 'diparma_gateway') {
            return self::fail(
                $gw === 'diparma_gateway'
                    ? 'Pick an enabled payment gateway. Ledger is the settlement destination.'
                    : 'Gateway is required',
                $gateway
            );
        }

        if (empty($params['channel'])) {
            $params['channel'] = 'orchestrator';
        }
        $params['gateway'] = $gw;
        $params['provider'] = $gw;
        $txnType = strtolower(trim($txnType !== '' ? $txnType : (string) ($params['txn_type'] ?? 'purchase')));
        $params['txn_type'] = $txnType;

        if (self::supports($gw) && function_exists('pos_run_payment_orchestrator')) {
            $result = pos_run_payment_orchestrator($gw, $txnType, $params);
            return self::finalize($gw, $result, 'pos');
        }

        return self::chargeViaAdapter($gw, $txnType, $params);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private static function chargeViaAdapter(string $gw, string $txnType, array $params): array
    {
        self::ensureFactoryLoaded();
        if (!GatewayAdapterFactory::isSupported($gw)) {
            return self::fail("Gateway not available for charge: {$gw}", $gw, true);
        }

        $op = self::operationFromTxnType($txnType);
        $txId = trim((string) (
            $params['transaction_id']
            ?? $params['related_transaction_id']
            ?? $params['orig_ref']
            ?? $params['rrn']
            ?? $params['payment_intent_id']
            ?? ''
        ));
        $payload = GatewayAdapterFactory::normalizePayload(array_merge($params, [
            'processing_mode' => $params['processing_mode'] ?? $params['sec_mode'] ?? $params['security_mode'] ?? '2D',
            'transaction_id' => $txId,
            'partial_amount' => $params['partial_amount'] ?? ($op === 'capture' ? ($params['amount'] ?? null) : null),
            'card_cvv' => $params['card_cvv'] ?? $params['cvv2'] ?? $params['cc_cvv'] ?? '',
            'name' => $params['name'] ?? $params['card_name'] ?? $params['customer_name'] ?? 'Customer',
        ]));
        $payload['cloud_token'] = $params['cloud_token'] ?? $params['source_id'] ?? $params['payment_token'] ?? null;
        $payload['source_id'] = $params['source_id'] ?? $payload['cloud_token'];
        $payload['txn_type'] = $txnType;
        $payload['is_moto'] = !empty($params['is_moto']);

        try {
            $result = GatewayAdapterFactory::process($payload, $op, $gw);
        } catch (Throwable $e) {
            return self::fail($e->getMessage(), $gw);
        }

        return self::finalize($gw, is_array($result) ? $result : ['success' => false, 'message' => 'Invalid adapter response'], 'adapter');
    }

    public static function operationFromTxnType(string $txnType): string
    {
        $t = strtolower(trim($txnType));
        if (in_array($t, ['auth', 'auth_hold', 'auth_moto', 'hold', 'authorize'], true)) {
            return 'hold';
        }
        if (in_array($t, ['capture', 'auth_complete', 'auth_capture', 'purchase_advice'], true)) {
            return 'capture';
        }
        if (in_array($t, ['void', 'avoid', 'reversal', 'cancel', 'refund'], true)) {
            return 'cancel';
        }
        return 'charge';
    }

    /**
     * @param array<string,mixed>|mixed $result
     * @return array<string,mixed>
     */
    private static function finalize(string $gw, $result, string $path): array
    {
        if (!is_array($result)) {
            $result = ['success' => false, 'message' => 'Invalid hub response'];
        }
        $result['hub'] = 'di_parma_charge_hub';
        $result['hub_path'] = $path;
        $result['provider'] = $result['provider'] ?? $gw;
        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    private static function fail(string $message, string $gateway, bool $unsupported = false): array
    {
        $out = [
            'success' => false,
            'message' => $message,
            'hub' => 'di_parma_charge_hub',
            'provider' => $gateway,
        ];
        if ($unsupported) {
            $out['unsupported'] = true;
        }
        return $out;
    }
}
