<?php
/**
 * Inject the merchant POS TID into every live gateway call.
 * No stub adapters, no Stripe confirm-without-card, no throw-pending.
 */
if (defined('DI_PARMA_UNIVERSAL_TID_ROUTER')) {
    return;
}
define('DI_PARMA_UNIVERSAL_TID_ROUTER', true);

if (!class_exists('LivePaymentProcessor', false)) {
    require_once __DIR__ . '/LivePaymentProcessor.php';
}

class UniversalTidPaymentRouter
{
    public static function processWithExternalTid(string $incomingTid, string $gateway, array $payload, ?PDO $db = null): array
    {
        $tid = function_exists('pos_normalize_terminal_id')
            ? pos_normalize_terminal_id($incomingTid)
            : strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', trim($incomingTid)) ?? '');

        if ($tid === '') {
            return [
                'success' => false,
                'error' => 'Terminal ID (TID) is required',
                'message' => 'Terminal ID (TID) is required',
            ];
        }

        $payload['terminal_id'] = $tid;
        $payload['tid'] = $tid;
        if (!isset($payload['metadata']) || !is_array($payload['metadata'])) {
            $payload['metadata'] = [];
        }
        $payload['metadata']['pos_tid'] = $tid;
        $payload['metadata']['terminal_id'] = $tid;
        if (!isset($payload['extra']) || !is_array($payload['extra'])) {
            $payload['extra'] = [];
        }
        $payload['extra']['terminal_id'] = $tid;

        $result = LivePaymentProcessor::executeLiveTransaction($gateway, $payload);
        $result['terminal_id'] = $tid;

        if ($db instanceof PDO && function_exists('pos_iso_session_log')) {
            pos_iso_session_log([
                'terminal_id' => $tid,
                'merchant_id' => (string) ($payload['merchant_id'] ?? $payload['mid'] ?? ''),
                'mti' => (string) ($payload['mti'] ?? ($result['mti'] ?? '')),
                'stan' => (string) ($payload['stan'] ?? ''),
                'rrn' => (string) ($result['reference'] ?? $payload['rrn'] ?? ''),
                'amount' => $payload['amount'] ?? null,
                'response_code' => $result['response_code'] ?? (!empty($result['success']) ? '00' : null),
                'raw_payload' => '',
            ]);
        }

        return $result;
    }
}
