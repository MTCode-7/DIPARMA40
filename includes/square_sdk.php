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

/** Square Web Payments sandbox simulation nonce — never charge. */
function square_is_simulation_nonce(string $token): bool
{
    $t = strtolower(trim($token));
    return $t !== '' && str_starts_with($t, 'cnon:card-nonce');
}

/** Real Square card nonce / source_id from Web Payments SDK (not PAN, not simulation). */
function square_is_real_card_nonce(string $token): bool
{
    $token = trim($token);
    if ($token === '' || square_is_simulation_nonce($token)) {
        return false;
    }
    return strlen($token) >= 8;
}

function square_token_live_flag(string $token): ?bool
{
    $t = strtolower(trim($token));
    if ($t === '') {
        return null;
    }
    if (str_starts_with($t, 'sandbox-') || str_starts_with($t, 'sq0atb-')) {
        return false;
    }
    if (str_starts_with($t, 'sq0atp-')) {
        return true;
    }
    return null;
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
    // Default LIVE. $envHint is kept for callers; sandbox host is used only when
    // Application ID is sandbox-sq0idb- (see square_credentials_are_live).
    return true;
}

function square_credentials_are_live(string $appId, string $token, string $envHint = 'production'): bool
{
    $appFlag = square_app_id_live_flag($appId);
    if ($appFlag !== null) {
        return $appFlag;
    }
    $tokenFlag = square_token_live_flag($token);
    if ($tokenFlag !== null) {
        return $tokenFlag;
    }
    return square_env_is_live($appId, $envHint);
}

function square_fetch_locations(string $token, bool $live): array
{
    if ($token === '') {
        return [];
    }
    $base = 'https://connect.squareup.com';
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
    if ($preferred !== '' && in_array($preferred, $active, true)) {
        return $preferred;
    }
    return $active[0] ?? ($all[0] ?? $preferred);
}

function square_app_id_live_flag(string $id): ?bool
{
    $id = strtolower(trim($id));
    if ($id === '' || !square_is_application_id($id)) {
        return null;
    }
    if (str_starts_with($id, 'sandbox-')) {
        return false;
    }
    return true;
}

function square_sdk_config(): array
{
    $candidates = [];
    $pushId = static function (string $id) use (&$candidates): void {
        $id = trim($id);
        if ($id !== '' && square_is_application_id($id) && !in_array($id, $candidates, true)) {
            $candidates[] = $id;
        }
    };
    $pushId((string) (getenv('SQUARE_APPLICATION_ID') ?: ''));
    $pushId((string) (getenv('SQUARE_API_KEY') ?: ''));
    $locationId = trim((string) (getenv('SQUARE_LOCATION_ID') ?: ''));
    $token = trim((string) (getenv('SQUARE_ACCESS_TOKEN') ?: getenv('SQUARE_SECRET_KEY') ?: ''));
    $env = strtolower(trim((string) (getenv('SQUARE_ENVIRONMENT') ?: 'production')));
    $configError = '';

    try {
        $row = function_exists('db') ? db()->find('payment_gateways', ['code' => 'square']) : null;
        $creds = json_decode((string) ($row['credentials'] ?? '{}'), true) ?: [];
        $pushId((string) ($creds['application_id'] ?? ''));
        $pushId((string) ($creds['api_key'] ?? ''));
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

    $appId = $candidates[0] ?? '';
    foreach ($candidates as $candidate) {
        if (square_app_id_live_flag($candidate) === true) {
            $appId = $candidate;
            break;
        }
    }
    $live = square_credentials_are_live($appId, $token, $env);
    foreach ($candidates as $candidate) {
        if (square_app_id_live_flag($candidate) === $live) {
            $appId = $candidate;
            break;
        }
    }
    $locations = [];
    if ($token !== '') {
        static $locCache = [];
        $cacheKey = $live ? 'live' : 'sandbox';
        if (!array_key_exists($cacheKey, $locCache)) {
            $locCache[$cacheKey] = square_fetch_locations($token, $live);
        }
        $locations = $locCache[$cacheKey];
        if ($locations) {
            $locationId = square_pick_location_id($locationId, $locations);
        } elseif ($locationId === '') {
            $configError = 'Square Locations API did not return a location for this access token.';
        }
        $appFlag = square_app_id_live_flag($appId);
        if ($appFlag !== null && $appFlag !== $live) {
            $configError = $live
                ? 'Square access token is LIVE but Application ID is sandbox. Set SQUARE_APPLICATION_ID from the Production tab.'
                : 'Square Application ID is sandbox. DIPARMA uses production (sq0idp- + connect.squareup.com).';
        }
    }

    return [
        'application_id' => $appId,
        'location_id' => $locationId,
        'access_token_set' => $token !== '',
        'environment' => $live ? 'production' : 'sandbox',
        'live' => $live,
        'script_url' => 'https://web.squarecdn.com/v1/square.js',
        'ready' => $live && $appId !== '' && $locationId !== '' && $token !== '' && $configError === '',
        'config_error' => $configError,
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
