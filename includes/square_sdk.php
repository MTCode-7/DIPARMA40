<?php
/**
 * Square Web Payments SDK helpers for DIPARMA checkout + POS.
 */
if (defined('DI_PARMA_SQUARE_SDK')) {
    return;
}
define('DI_PARMA_SQUARE_SDK', true);

function square_is_application_id(string $id): bool
{
    return (bool) preg_match('/^(sandbox-)?sq0id[bp]-/i', $id);
}

function square_env_is_live(string $appId, string $envHint): bool
{
    $id = strtolower(trim($appId));
    if (str_starts_with($id, 'sandbox-')) {
        return false;
    }
    if (preg_match('/^sq0id[bp]-/', $id)) {
        return true;
    }
    return in_array(strtolower(trim($envHint)), ['production', 'live', 'prod'], true);
}

function square_fetch_locations(string $token, bool $live): array
{
    if ($token === '') {
        return [];
    }
    $base = $live ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com';
    $ch = curl_init($base . '/v2/locations');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Square-Version: 2024-01-18',
            'Accept: application/json',
        ],
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $data = json_decode((string) $raw, true);
    return is_array($data['locations'] ?? null) ? $data['locations'] : [];
}

function square_pick_location_id(string $preferred, array $locations): string
{
    $all = [];
    $active = [];
    foreach ($locations as $loc) {
        $id = trim((string) ($loc['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $all[] = $id;
        if (strtoupper((string) ($loc['status'] ?? '')) === 'ACTIVE') {
            $active[] = $id;
        }
    }
    if ($preferred !== '' && in_array($preferred, $all, true)) {
        return $preferred;
    }
    return $active[0] ?? ($all[0] ?? $preferred);
}

function square_sdk_config(): array
{
    $envAppId = trim((string) (getenv('SQUARE_APPLICATION_ID') ?: ''));
    $envApiKey = trim((string) (getenv('SQUARE_API_KEY') ?: ''));
    $appId = square_is_application_id($envAppId) ? $envAppId : '';
    if ($appId === '' && square_is_application_id($envApiKey)) {
        $appId = $envApiKey;
    }
    $locationId = trim((string) (getenv('SQUARE_LOCATION_ID') ?: ''));
    $token = trim((string) (getenv('SQUARE_ACCESS_TOKEN') ?: getenv('SQUARE_SECRET_KEY') ?: ''));
    $env = strtolower(trim((string) (getenv('SQUARE_ENVIRONMENT') ?: 'production')));

    try {
        $row = function_exists('db') ? db()->find('payment_gateways', ['code' => 'square']) : null;
        $creds = json_decode((string) ($row['credentials'] ?? '{}'), true) ?: [];
        foreach (['application_id', 'api_key'] as $credKey) {
            $candidate = trim((string) ($creds[$credKey] ?? ''));
            if ($appId === '' && square_is_application_id($candidate)) {
                $appId = $candidate;
            }
        }
        if ($locationId === '' && !empty($creds['location_id'])) {
            $locationId = trim((string) $creds['location_id']);
        }
        if ($token === '' && !empty($creds['access_token'])) {
            $token = trim((string) $creds['access_token']);
        }
        if ($token === '' && !empty($creds['secret_key'])) {
            $token = trim((string) $creds['secret_key']);
        }
        if (!empty($creds['environment'])) {
            $env = strtolower(trim((string) $creds['environment']));
        }
    } catch (Throwable $e) {
    }

    $live = square_env_is_live($appId, $env);
    if ($token !== '') {
        static $locationCache = null;
        if ($locationCache === null) {
            $locationCache = square_fetch_locations($token, $live);
        }
        if ($locationCache) {
            $locationId = square_pick_location_id($locationId, $locationCache);
        }
    }

    return [
        'application_id' => $appId,
        'location_id' => $locationId,
        'access_token_set' => $token !== '',
        'environment' => $live ? 'production' : 'sandbox',
        'live' => $live,
        'script_url' => $live
            ? 'https://web.squarecdn.com/v1/square.js'
            : 'https://sandbox.web.squarecdn.com/v1/square.js',
        'ready' => $appId !== '' && $locationId !== '' && $token !== '',
    ];
}

function square_runtime_credentials(): array
{
    $cfg = square_sdk_config();
    $token = trim((string) (getenv('SQUARE_ACCESS_TOKEN') ?: getenv('SQUARE_SECRET_KEY') ?: ''));
    try {
        $row = function_exists('db') ? db()->find('payment_gateways', ['code' => 'square']) : null;
        $creds = json_decode((string) ($row['credentials'] ?? '{}'), true) ?: [];
        if ($token === '' && !empty($creds['access_token'])) {
            $token = trim((string) $creds['access_token']);
        }
        if ($token === '' && !empty($creds['secret_key'])) {
            $token = trim((string) $creds['secret_key']);
        }
    } catch (Throwable $e) {
    }
    return [
        'application_id' => (string) ($cfg['application_id'] ?? ''),
        'location_id' => (string) ($cfg['location_id'] ?? ''),
        'access_token' => $token,
        'live' => !empty($cfg['live']),
        'environment' => (string) ($cfg['environment'] ?? 'production'),
    ];
}

function square_sdk_script_tag(): string
{
    $cfg = square_sdk_config();
    if ($cfg['application_id'] === '') {
        return '';
    }
    return '<script type="text/javascript" src="' . htmlspecialchars($cfg['script_url'], ENT_QUOTES, 'UTF-8') . '"></script>';
}
