<?php
/**
 * DI PARMA | SFOX API adapter.
 * Supports authenticated user/account checks and balance reads.
 */
class SFOXAdapter
{
    private string $token;
    private string $baseUrl;
    private int $timeout = 15;

    public function __construct(string $token = '', string $baseUrl = '')
    {
        $this->token = trim($token ?: (getenv('SFOX_API_TOKEN') ?: (getenv('SFOX_API_KEY') ?: '')));
        $this->baseUrl = rtrim($baseUrl ?: (getenv('SFOX_API_URL') ?: 'https://api.sfox.com/v1'), '/');
    }

    public function testConnection(): array
    {
        if ($this->token === '') {
            return ['success' => false, 'message' => 'SFOX API token is missing'];
        }

        $result = $this->request('GET', '/user');
        if (($result['_http_code'] ?? 0) >= 200 && ($result['_http_code'] ?? 0) < 300 && !isset($result['error'])) {
            return ['success' => true, 'message' => 'SFOX connected'];
        }

        return ['success' => false, 'message' => 'SFOX: ' . ($result['message'] ?? ($result['error'] ?? ('HTTP ' . ($result['_http_code'] ?? 0))))];
    }

    public function getBalance(): array
    {
        $result = $this->request('GET', '/balances');
        if (($result['_http_code'] ?? 0) >= 200 && ($result['_http_code'] ?? 0) < 300 && !isset($result['error'])) {
            return ['success' => true, 'balances' => $result];
        }

        return ['success' => false, 'message' => $result['message'] ?? ($result['error'] ?? 'SFOX balance request failed')];
    }

    private function request(string $method, string $path): array
    {
        $ch = curl_init($this->baseUrl . '/' . ltrim($path, '/'));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $this->token,
            ],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '') {
            return ['error' => $error, '_http_code' => $httpCode];
        }

        $data = json_decode($raw ?: '{}', true);
        return is_array($data) ? ($data + ['_http_code' => $httpCode]) : ['error' => 'Invalid SFOX response', '_http_code' => $httpCode];
    }
}
