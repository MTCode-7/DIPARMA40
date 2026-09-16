<?php
/**
 * Square Web Payments SDK helpers for DIPARMA checkout + POS.
 */
if (defined('DI_PARMA_SQUARE_SDK')) {
    return;
}
define('DI_PARMA_SQUARE_SDK', true);

function square_sdk_config(): array
{
    $appId = trim((string) (getenv('SQUARE_APPLICATION_ID') ?: getenv('SQUARE_API_KEY') ?: ''));
    $locationId = trim((string) (getenv('SQUARE_LOCATION_ID') ?: ''));
    $token = trim((string) (getenv('SQUARE_ACCESS_TOKEN') ?: getenv('SQUARE_SECRET_KEY') ?: ''));
    $env = strtolower(trim((string) (getenv('SQUARE_ENVIRONMENT') ?: 'live')));
    $live = in_array($env, ['production', 'live', 'prod'], true);

    try {
        $row = function_exists('db') ? db()->find('payment_gateways', ['code' => 'square']) : null;
        $creds = json_decode((string) ($row['credentials'] ?? '{}'), true) ?: [];
        if ($appId === '' && !empty($creds['application_id'])) {
            $appId = trim((string) $creds['application_id']);
        }
        if ($appId === '' && !empty($creds['api_key'])) {
            $appId = trim((string) $creds['api_key']);
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
            $live = in_array($env, ['production', 'live', 'prod'], true);
        }
    } catch (Throwable $e) {
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
