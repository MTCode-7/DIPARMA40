<?php
/**
 * DI PARMA | Crypto.com Exchange API v1 adapter.
 * Supports authenticated account summary and spot balance reads.
 */
class CryptoComExchangeAdapter
{
    private string $apiKey;
    private string $apiSecret;
    private string $baseUrl;
    private int $timeout = 15;

    public function __construct(string $apiKey = '', string $apiSecret = '', string $baseUrl = '')
    {
        $this->apiKey = trim($apiKey ?: (getenv('CRYPTOCOM_API_KEY') ?: ''));
        $this->apiSecret = trim($apiSecret ?: (getenv('CRYPTOCOM_API_SECRET') ?: ''));
        $this->baseUrl = rtrim($baseUrl ?: (getenv('CRYPTOCOM_API_URL') ?: 'https://api.crypto.com/exchange/v1'), '/');
    }

    public function testConnection(): array
    {
        if ($this->apiKey === '' || $this->apiSecret === '') {
            return ['success' => false, 'message' => 'Crypto.com API key or secret is missing'];
        }

        $result = $this->request('private/get-account-summary', []);
        if (($result['code'] ?? -1) === 0) {
            $accounts = $result['result']['accounts'] ?? [];
            return ['success' => true, 'message' => 'Crypto.com connected - ' . count($accounts) . ' account(s)', 'accounts' => $accounts];
        }

        return ['success' => false, 'message' => 'Crypto.com: ' . ($result['message'] ?? ('HTTP ' . ($result['_http_code'] ?? 0)))];
    }

    public function getBalance(?string $currency = null): array
    {
        $result = $this->request('private/get-account-summary', []);
        if (($result['code'] ?? -1) !== 0) {
            return ['success' => false, 'message' => $result['message'] ?? 'Crypto.com balance request failed'];
        }

        $accounts = $result['result']['accounts'] ?? [];
        if ($currency !== null) {
            foreach ($accounts as $account) {
                if (strcasecmp((string)($account['currency'] ?? ''), $currency) === 0) {
                    return ['success' => true, 'account' => $account];
                }
            }
            return ['success' => false, 'message' => 'Currency not found in Crypto.com account'];
        }

        return ['success' => true, 'accounts' => $accounts];
    }

    private function request(string $method, array $params): array
    {
        $id = random_int(1, 2147483647);
        $nonce = (int)floor(microtime(true) * 1000);
        $paramString = '';
        $sorted = $params;
        ksort($sorted);
        foreach ($sorted as $key => $value) {
            $paramString .= $key . (is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES) : (string)$value);
        }

        $signature = hash_hmac('sha256', $method . $id . $this->apiKey . $paramString . $nonce, $this->apiSecret);
        $body = json_encode([
            'id' => $id,
            'method' => $method,
            'api_key' => $this->apiKey,
            'params' => $params,
            'nonce' => $nonce,
            'sig' => $signature,
        ], JSON_UNESCAPED_SLASHES);

        $ch = curl_init($this->baseUrl . '/' . $method);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '') {
            return ['code' => -1, 'message' => $error, '_http_code' => $httpCode];
        }

        $data = json_decode($raw ?: '{}', true);
        return is_array($data) ? ($data + ['_http_code' => $httpCode]) : ['code' => -1, 'message' => 'Invalid Crypto.com response', '_http_code' => $httpCode];
    }
}
