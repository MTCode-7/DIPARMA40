<?php
/**
 * DI PARMA adapter compatible with GatewayAdapterInterface.
 * MOTO is represented by processing_mode=2D and is intentionally preserved.
 */
require_once __DIR__ . '/../Adapters/GatewayAdapterInterface.php';
require_once __DIR__ . '/../Adapters/GatewayErrorMapper.php';

class DIPARMAGateway implements GatewayAdapterInterface
{
    private string $apiKey;
    private string $apiSecret;
    private string $merchantId;
    private string $ledgerAddress;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey = trim((string)(getenv('DIPARMA_API_KEY') ?: ''));
        $this->apiSecret = trim((string)(getenv('DIPARMA_API_SECRET') ?: ''));
        $this->merchantId = trim((string)(getenv('DIPARMA_MERCHANT_ID') ?: ''));
        $this->ledgerAddress = trim((string)(getenv('LEDGER_TRC20_ADDRESS') ?: ''));
        $this->baseUrl = rtrim((string)(getenv('DIPARMA_API_URL') ?: 'https://diparmas.com/api/v1'), '/');
    }

    public function getName(): string { return 'diparma'; }

    public function supports(string $mode): bool
    {
        return in_array(strtoupper(trim($mode)), ['2D', '3D', 'HOLD', 'CAPTURE', 'CANCEL'], true);
    }

    public function charge(array $payload): array
    {
        $mode = strtoupper((string)($payload['processing_mode'] ?? '3D'));
        if (!$this->supports($mode)) {
            return $this->error($payload, 'Unsupported processing mode');
        }
        $payload['merchant_id'] = $this->merchantId;
        $payload['ledger_address'] = $this->ledgerAddress;
        $payload['moto'] = $mode === '2D';
        return $this->request('charge', $payload);
    }

    public function hold(array $payload): array
    {
        $payload['merchant_id'] = $this->merchantId;
        return $this->request('auth', $payload);
    }

    public function capture(string $transactionId, ?float $amount = null): array
    {
        return $this->request('capture', [
            'transaction_id' => $transactionId,
            'amount' => $amount,
            'merchant_id' => $this->merchantId,
        ]);
    }

    public function cancel(string $transactionId, string $reason = 'requested_by_customer'): array
    {
        return $this->request('void', [
            'transaction_id' => $transactionId,
            'reason' => $reason,
            'merchant_id' => $this->merchantId,
        ]);
    }

    public function normalizeError(array $rawResponse): string
    {
        if (!empty($rawResponse['error_code'])) {
            return (string)$rawResponse['error_code'];
        }
        return !empty($rawResponse['success']) ? '' : 'GATEWAY_ERROR';
    }

    public function buildIdempotencyKey(string $reference, float $amount): string
    {
        return 'idemp_dp_' . hash('sha256', $reference . '|' . $amount . '|' . getenv('ENCRYPTION_KEY'));
    }

    private function request(string $action, array $payload): array
    {
        if ($this->apiKey === '' || $this->apiSecret === '') {
            return $this->error($payload, 'DI PARMA API credentials are not configured');
        }
        $timestamp = time();
        $reference = (string)($payload['reference'] ?? $payload['transaction_id'] ?? '');
        $amount = (string)($payload['amount'] ?? '0');
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $timestamp . '.' . $reference . '.' . $amount, $this->apiSecret);
        $ch = curl_init($this->baseUrl . '/' . rawurlencode($action));
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Api-Key: ' . $this->apiKey, 'X-Timestamp: ' . $timestamp, 'X-Signature: ' . $signature],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => (int)(getenv('PAYMENT_TIMEOUT') ?: 300),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false || $curlError !== '') {
            return $this->error($payload, $curlError ?: 'DI PARMA API request failed');
        }
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return $this->error($payload, 'Invalid DI PARMA API response');
        }
        if ($status < 200 || $status >= 300) {
            $decoded['success'] = false;
            $decoded['status'] = $decoded['status'] ?? 'declined';
        }
        return $decoded;
    }

    private function error(array $payload, string $message): array
    {
        return GatewayErrorMapper::buildErrorResponse('GATEWAY_ERROR', (string)($payload['reference'] ?? ''), (float)($payload['amount'] ?? 0), strtoupper((string)($payload['currency'] ?? 'USD')), $message);
    }
}
