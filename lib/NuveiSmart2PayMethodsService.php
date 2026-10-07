<?php
/**
 * Smart2Pay / GlobalPay Payment Methods API.
 * Kept separate from the SafeCharge/Nuvei transaction adapter.
 */
final class NuveiSmart2PayMethodsService
{
    private string $baseUrl;
    private string $username;
    private string $password;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) (getenv('NUVEI_S2P_API_URL') ?: 'https://api.smart2pay.com'), '/');
        $this->username = trim((string) (getenv('NUVEI_S2P_USERNAME') ?: ''));
        $this->password = trim((string) (getenv('NUVEI_S2P_PASSWORD') ?: ''));
    }

    public function isConfigured(): bool
    {
        return $this->username !== '' && $this->password !== '';
    }

    public function listAssignedMethods(?string $country = null): array
    {
        return $this->get('/v1/methods/assigned', $country);
    }

    public function listMethods(?string $country = null): array
    {
        return $this->get('/v1/methods', $country);
    }

    public function method(int $methodId): array
    {
        if ($methodId <= 0) {
            return ['success' => false, 'message' => 'Payment method ID is required'];
        }
        return $this->request('/v1/methods/' . $methodId);
    }

    private function get(string $path, ?string $country): array
    {
        $query = $country !== null && trim($country) !== ''
            ? ['country' => strtoupper(trim($country))]
            : [];
        return $this->request($path, $query);
    }

    private function request(string $path, array $query = []): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'configured' => false, 'message' => 'Smart2Pay credentials are not configured'];
        }
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password),
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $error !== '') {
            return ['success' => false, 'status' => $status, 'message' => 'Smart2Pay request failed'];
        }
        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            return ['success' => false, 'status' => $status, 'message' => 'Smart2Pay returned invalid JSON'];
        }
        if ($status < 200 || $status >= 300) {
            return ['success' => false, 'status' => $status, 'response' => $decoded, 'message' => 'Smart2Pay returned HTTP ' . $status];
        }
        return ['success' => true, 'status' => $status, 'response' => $decoded];
    }
}
