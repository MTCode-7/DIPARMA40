<?php
/**
 * DI PARMA | ChargeHub — single charge entry
 *
 * All channels/orchestrators should charge through here:
 *   ChargeHub → pos_run_payment_orchestrator → pos_run_standalone_gateway → Adapter
 *
 * Ledger settlement stays with the caller (POS API / PaymentOrchestrator / DIPARMAOrchestrator).
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

    public static function normalizeGateway(string $gateway): string
    {
        $g = strtolower(trim($gateway));
        if ($g === '') {
            return '';
        }
        if (function_exists('pos_normalize_gateway')) {
            return pos_normalize_gateway($g);
        }
        return $g === 'diparma' ? 'diparma' : $g;
    }

    public static function supports(string $gateway): bool
    {
        self::ensurePosLoaded();
        $gw = self::normalizeGateway($gateway);
        return $gw !== ''
            && function_exists('pos_is_charge_processor')
            && pos_is_charge_processor($gw)
            && function_exists('pos_gateway_is_live')
            && pos_gateway_is_live($gw);
    }

    /**
     * Run a POS-pipe charge (Orders wrap + standalone adapter).
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public static function charge(string $gateway, string $txnType, array $params): array
    {
        self::ensurePosLoaded();
        $gw = self::normalizeGateway($gateway);
        if ($gw === '' || !function_exists('pos_run_payment_orchestrator')) {
            return [
                'success' => false,
                'message' => 'Charge hub unavailable',
                'hub' => 'di_parma_charge_hub',
            ];
        }
        if ($gw === '' || !function_exists('pos_is_charge_processor') || !pos_is_charge_processor($gw) || !pos_gateway_is_live($gw)) {
            return [
                'success' => false,
                'message' => "Gateway not on POS pipe: {$gateway}",
                'hub' => 'di_parma_charge_hub',
                'unsupported' => true,
            ];
        }

        if (empty($params['channel'])) {
            $params['channel'] = 'orchestrator';
        }
        $params['gateway'] = $gw;
        $params['provider'] = $gw;

        $result = pos_run_payment_orchestrator($gw, $txnType, $params);
        if (!is_array($result)) {
            $result = ['success' => false, 'message' => 'Invalid hub response'];
        }
        $result['hub'] = 'di_parma_charge_hub';
        $result['provider'] = $result['provider'] ?? $gw;
        return $result;
    }
}
