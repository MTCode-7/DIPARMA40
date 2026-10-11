<?php
/**
 * PCI-DSS controls are enabled.
 * This is not a PCI certification, SAQ, or attestation of compliance.
 */
function dp_pci_boot(): void
{
    if (defined('DIPARMA_PCI_DSS')) {
        return;
    }
    $dpPciOn = true;
    if (function_exists('env')) {
        $dpPciFlag = env('DIPARMA_PCI_DSS', true);
        $dpPciOn = !($dpPciFlag === false || $dpPciFlag === 0 || $dpPciFlag === '0');
    }
    define('DIPARMA_PCI_DSS', $dpPciOn);
}

function dp_request_is_https(): bool
{
    if (PHP_SAPI === 'cli') {
        return false;
    }
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on'
        || str_contains((string) ($_SERVER['HTTP_CF_VISITOR'] ?? ''), '"scheme":"https"');
}

function dp_pci_production_https(): bool
{
    if (!dp_request_is_https()) {
        return false;
    }
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = (string) preg_replace('/:\d+$/', '', $host);
    if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        return false;
    }
    return $host === 'diparmas.com' || $host === 'www.diparmas.com' || str_ends_with($host, '.diparmas.com');
}

function dp_pci_send_headers(): void
{
    if (!DIPARMA_PCI_DSS || headers_sent() || PHP_SAPI === 'cli' || !dp_pci_production_https()) {
        return;
    }
    header('Strict-Transport-Security: max-age=15552000; includeSubDomains');
}

function dp_pci_is_sad_key(string $key, bool $storage): bool
{
    $lk = strtolower($key);
    if (str_contains($lk, 'check') || str_contains($lk, 'last4') || str_contains($lk, 'last_4') || str_contains($lk, 'brand')) {
        return false;
    }
    $compact = preg_replace('/[^a-z0-9]/', '', $lk) ?? '';
    $exact = [
        'cvv', 'cvc', 'csc', 'cid', 'cvn', 'cvv2', 'cvc2', 'cardcvv', 'cccvv', 'cardcvc',
        'securitycode', 'cardcode', 'cardsecuritycode', 'cavv',
        'pan', 'cardnumber', 'ccnumber', 'cardpan', 'primaryaccountnumber',
        'track', 'track1', 'track2', 'trackdata', 'magstripe',
        'pin', 'pinblock', 'cardpin',
    ];
    if (in_array($compact, $exact, true)) {
        return true;
    }
    if ($storage && in_array($compact, ['clientsecret', 'password', 'privatekey', 'secretkey'], true)) {
        return true;
    }
    return (bool) preg_match('/(^|_)(cvv2|cvc2|cvv|cvc|csc|cid|pin_block|pin|pan|track_data|track1|track2|track|magstripe|card_number|cc_number|card_cvv|cc_cvv|card_cvc|security_code|card_code)(_|$)/', $lk);
}

function dp_pci_luhn(string $digits): bool
{
    $len = strlen($digits);
    if ($len < 13 || $len > 19) {
        return false;
    }
    $sum = 0;
    $alt = false;
    for ($i = $len - 1; $i >= 0; $i--) {
        $n = (int) $digits[$i];
        if ($alt) {
            $n *= 2;
            if ($n > 9) {
                $n -= 9;
            }
        }
        $sum += $n;
        $alt = !$alt;
    }
    return $sum % 10 === 0;
}

function dp_pci_mask_text(string $value): string
{
    if (!DIPARMA_PCI_DSS || $value === '') {
        return $value;
    }
    $value = preg_replace('/%B[^?%]{0,160}\??/', '****TRACK****', $value) ?? $value;
    $value = preg_replace('/;\d{0,19}=[^?]{0,80}\??/', '****TRACK****', $value) ?? $value;
    $masked = preg_replace_callback('/(?<!\d)(?:\d[ -]*){13,19}(?!\d)/', static function (array $m): string {
        $digits = preg_replace('/\D/', '', $m[0]) ?? '';
        if (!dp_pci_luhn($digits)) {
            return $m[0];
        }
        return '****' . substr($digits, -4);
    }, $value);
    return $masked ?? $value;
}

/**
 * @param mixed $value
 * @return mixed
 */
function dp_pci_scrub_storage($value)
{
    if (!DIPARMA_PCI_DSS) {
        return $value;
    }
    if (is_array($value)) {
        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && dp_pci_is_sad_key($key, true)) {
                $out[$key] = '[redacted]';
                continue;
            }
            $out[$key] = dp_pci_scrub_storage($item);
        }
        return $out;
    }
    if (!is_string($value) || $value === '') {
        return $value;
    }
    $trim = ltrim($value);
    if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            $encoded = json_encode(dp_pci_scrub_storage($decoded), JSON_UNESCAPED_UNICODE);
            if (is_string($encoded)) {
                return $encoded;
            }
        }
    }
    return dp_pci_mask_text($value);
}

/**
 * Response-safe copy: drop PAN/CVV/track. Keep client_secret for an in-flight 3-D Secure step.
 * @param mixed $value
 * @return mixed
 */
function dp_pci_public($value)
{
    if (!DIPARMA_PCI_DSS) {
        return $value;
    }
    if (is_array($value)) {
        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && dp_pci_is_sad_key($key, false)) {
                continue;
            }
            $out[$key] = dp_pci_public($item);
        }
        return $out;
    }
    if (is_string($value)) {
        return dp_pci_mask_text($value);
    }
    return $value;
}

function dp_pci_scrub_row(string $table, array $data): array
{
    if (!DIPARMA_PCI_DSS || $data === []) {
        return $data;
    }
    $panColumns = ['card_number', 'cc_number', 'pan', 'card_pan', 'primary_account_number'];
    $sadColumns = ['card_cvv', 'cc_cvv', 'cvv', 'cvv2', 'cvc', 'cvc2', 'cid', 'security_code', 'pin', 'pin_block', 'track1', 'track2', 'track_data', 'magstripe'];
    $blobColumns = ['gateway_response', 'transaction_data', 'details', 'request_body', 'response_body', 'raw_payload'];
    foreach ($data as $col => $val) {
        $name = strtolower((string) $col);
        if (in_array($name, $sadColumns, true)) {
            $data[$col] = null;
            continue;
        }
        if (in_array($name, $panColumns, true) && is_string($val)) {
            $digits = preg_replace('/\D/', '', $val) ?? '';
            $plainPan = $digits !== '' && strlen($digits) >= 13 && strlen($digits) <= 19 && preg_match('/^\d[\d\s-]*$/', $val);
            if ($plainPan) {
                if (array_key_exists('card_last4', $data) && trim((string) $data['card_last4']) === '') {
                    $data['card_last4'] = substr($digits, -4);
                }
                $data[$col] = null;
                continue;
            }
        }
        if (in_array($name, $blobColumns, true)) {
            $data[$col] = dp_pci_scrub_storage($val);
        }
    }
    unset($table);
    return $data;
}

function dp_pci_scrub_debug_params($params)
{
    if (!DIPARMA_PCI_DSS) {
        return $params;
    }
    return dp_pci_scrub_storage($params);
}

function dp_pci_ensure_setting($db): void
{
    static $done = false;
    if ($done || !DIPARMA_PCI_DSS || !is_object($db) || !method_exists($db, 'execute')) {
        return;
    }
    $done = true;
    $prefix = defined('DB_PREFIX') ? (string) DB_PREFIX : 'dp_';
    try {
        $db->execute(
            "INSERT INTO `{$prefix}settings` (`key`, `value`) VALUES ('pci_dss', '1')
             ON DUPLICATE KEY UPDATE `value` = '1'"
        );
    } catch (Throwable $e) {
        // Older installs may not have dp_settings yet. The code default stays on.
    }
}
