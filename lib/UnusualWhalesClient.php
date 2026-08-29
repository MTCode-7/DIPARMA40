<?php

final class UnusualWhalesClient
{
    private string $apiKey;
    private string $baseUrl = 'https://api.unusualwhales.com';

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = trim($apiKey ?? (getenv('UNUSUAL_WHALES_API_KEY') ?: ''));
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function darkpoolRecent(array $query = []): array
    {
        return $this->request('/api/darkpool/recent', $query);
    }

    public function gexLevels(string $ticker, array $query = []): array
    {
        $ticker = strtoupper(trim($ticker));
        if (!preg_match('/^[A-Z][A-Z0-9.\-]{0,9}$/', $ticker)) {
            throw new InvalidArgumentException('Invalid ticker');
        }
        return $this->request('/api/stock/' . rawurlencode($ticker) . '/gex-levels', $query);
    }

    private function request(string $path, array $query = []): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'Unusual Whales API key is not configured'];
        }

        $query = array_filter($query, static fn($value) => $value !== '' && $value !== null);
        $url = $this->baseUrl . $path . ($query ? '?' . http_build_query($query) : '');
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($error) return ['success' => false, 'message' => 'API connection failed'];
        $data = json_decode((string)$body, true);
        if (!is_array($data)) return ['success' => false, 'message' => 'Invalid API response'];
        if ($status < 200 || $status >= 300) {
            return ['success' => false, 'message' => $data['message'] ?? ('API HTTP ' . $status)];
        }
        return ['success' => true, 'data' => $data['data'] ?? $data];
    }
}