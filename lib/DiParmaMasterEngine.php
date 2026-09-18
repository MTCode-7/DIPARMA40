<?php
/**
 * DI PARMA master engine — ISO 8583 Advice, PIN/MAC, live TID routing.
 *
 * Public API:
 *   hexToBinaryBitmap / binaryToHexBitmap / buildISOFrame / parseISOFrame
 *   generatePinBlock / calculateMAC
 *   buildAdvice / parseAdviceResponse
 *   executeLiveGatewayTransaction($gateway, $payload)
 *   processWithExternalTid($tid, $gateway, $payload)
 *
 * Adapters are instance classes (NuveiAdapter::purchase, StripeAdapter::charge, …)
 * reached via UniversalTidPaymentRouter → PaymentGatewayRouter → pos_run_standalone_gateway.
 * There is no \Lib\Adapters\… namespace and no static ::process().
 */
if (defined('DI_PARMA_MASTER_ENGINE')) {
    return;
}
define('DI_PARMA_MASTER_ENGINE', true);

$dpRoot = dirname(__DIR__);
if (!class_exists('ISO8583AdviceProcessor', false)) {
    $f = $dpRoot . '/pos/lib/ISO8583AdviceProcessor.php';
    if (is_file($f)) {
        require_once $f;
    }
}
if (!class_exists('ISO8583SecurityHandler', false)) {
    $f = $dpRoot . '/pos/lib/ISO8583SecurityHandler.php';
    if (is_file($f)) {
        require_once $f;
    }
}
if (!class_exists('POSAdviceHandler', false)) {
    $f = $dpRoot . '/pos/lib/POSAdviceHandler.php';
    if (is_file($f)) {
        require_once $f;
    }
}
if (!class_exists('LivePaymentProcessor', false)) {
    require_once __DIR__ . '/LivePaymentProcessor.php';
}
if (!class_exists('UniversalTidPaymentRouter', false)) {
    require_once __DIR__ . '/UniversalTidPaymentRouter.php';
}

class DiParmaMasterEngine
{
    public static function hexToBinaryBitmap(string $hexBitmap): string
    {
        return ISO8583AdviceProcessor::hexToBinaryBitmap($hexBitmap);
    }

    public static function binaryToHexBitmap(string $binaryBitmap): string
    {
        return ISO8583AdviceProcessor::binaryToHexBitmap($binaryBitmap);
    }

    public static function buildISOFrame(string $mti, array $fields): string
    {
        return ISO8583AdviceProcessor::buildISOFrame($mti, $fields);
    }

    public static function parseISOFrame(string $rawFrame): array
    {
        return ISO8583AdviceProcessor::parseISOFrame($rawFrame);
    }

    public static function generatePinBlock(string $pin, string $pan): string
    {
        return ISO8583SecurityHandler::generatePinBlock($pin, $pan);
    }

    public static function calculateMAC(string $rawMessage, string $secretKey = ''): string
    {
        return ISO8583SecurityHandler::calculateMAC($rawMessage, $secretKey);
    }

    public static function buildAdvice(array $transactionData, string $mti = '0220'): array
    {
        return POSAdviceHandler::buildAdviceRequest($transactionData, $mti);
    }

    public static function parseAdviceResponse(string $raw): array
    {
        return POSAdviceHandler::handleAdviceResponse($raw);
    }

    public static function executeLiveGatewayTransaction(string $gateway, array $payload): array
    {
        return LivePaymentProcessor::executeLiveTransaction($gateway, $payload);
    }

    public static function processWithExternalTid(string $tid, string $gateway, array $payload, ?PDO $db = null): array
    {
        $tid = trim($tid);
        if ($tid === '') {
            return [
                'success' => false,
                'error' => 'Terminal ID (TID) cannot be empty.',
                'message' => 'Terminal ID (TID) cannot be empty.',
            ];
        }
        $gateway = strtolower(trim($gateway));
        if (in_array($gateway, ['byzati', 'byz', 'coinbase', 'bitpay'], true)) {
            $msg = 'Gateway is not connected in this project: ' . $gateway;
            return ['success' => false, 'error' => $msg, 'message' => $msg];
        }
        // pos_run_standalone_gateway($gateway, $txnType, $payload) — three arguments.
        // Do not call it with ($gateway, $payload) only.
        return UniversalTidPaymentRouter::processWithExternalTid($tid, $gateway, $payload, $db);
    }
}
