<?php
/**
 * Verifone VX 675 — Verix V, Nuvei only.
 *
 * OS: Verix V SDK (VVDTK + VeriShield). Not Android, not a PWA.
 * Acquirer: Nuvei. No other gateway.
 * Payment App: Nuvei Payment App installed on the terminal.
 * Keys: Nuvei RKI / KIF into the terminal HSM — never stored in DIPARMA.
 *
 * After Nuvei authorizes on-device, the app notifies DIPARMA; settlement is USDT → Ledger.
 */
if (defined('DI_PARMA_VERIFONE_VX675')) {
    return;
}
define('DI_PARMA_VERIFONE_VX675', true);

if (!function_exists('pos_normalize_operation')) {
    require_once __DIR__ . '/operations.php';
}
if (!function_exists('pos_device_commission')) {
    require_once __DIR__ . '/devices.php';
}

function verifone_vx675_entry_mode(string $raw): string
{
    $raw = strtolower(trim($raw));
    if (in_array($raw, ['05', 'chip', 'icc', 'emv', 'insert', 'pos_chip', 'pos'], true)) {
        return 'pos_chip';
    }
    if (in_array($raw, ['07', 'nfc', 'ctls', 'contactless', 'tap', 'cless'], true)) {
        return 'nfc_contactless';
    }
    if (in_array($raw, ['90', '02', 'mag', 'swipe', 'track2'], true)) {
        return 'pos_chip';
    }
    if (in_array($raw, ['01', 'keyed', 'manual', 'moto'], true)) {
        return 'keyed';
    }
    return 'pos_chip';
}

function verifone_vx675_expiry(string $exp): string
{
    $d = preg_replace('/\D/', '', $exp);
    if (strlen($d) === 4) {
        return substr($d, 2, 2) . '/' . substr($d, 0, 2);
    }
    if (preg_match('/^(0[1-9]|1[0-2])\/([0-9]{2})$/', trim($exp))) {
        return trim($exp);
    }
    return '';
}

function verifone_vx675_locked_gateway(): string
{
    return 'nuvei';
}

function verifone_vx675_commission(): array
{
    $dev = function_exists('pos_device_get') ? pos_device_get('verifone_vx675') : [
        'model' => 'verifone_vx675',
        'type' => 'verix_v',
        'locked_gateway' => 'nuvei',
    ];
    return pos_device_commission($dev ?: []);
}

function verifone_vx675_reject_other_gateway(string $requested): ?string
{
    return null;
}

/** Map VX 675 result onto POS transaction fields. Device is accepted; gateway follows the request. */
function verifone_vx675_to_pos(array $in): array
{
    $entry = verifone_vx675_entry_mode((string) ($in['entry_mode'] ?? $in['pos_entry'] ?? 'chip'));
    $txn = pos_normalize_operation((string) ($in['txn_type'] ?? $in['op'] ?? 'purchase_2d'));
    $exp = verifone_vx675_expiry((string) ($in['expiry'] ?? $in['card_expiry'] ?? $in['exp_date'] ?? ''));
    $commission = verifone_vx675_commission();
    $gw = function_exists('pos_normalize_gateway')
        ? pos_normalize_gateway((string) ($in['gateway'] ?? ''))
        : strtolower(trim((string) ($in['gateway'] ?? '')));
    if ($gw === '') {
        $gw = 'nuvei';
    }

    return [
        'gateway' => $gw,
        'txn_type' => $txn,
        'amount' => (float) ($in['amount'] ?? 0),
        'currency' => strtoupper((string) ($in['currency'] ?? 'USD')),
        'card_number' => preg_replace('/\D/', '', (string) ($in['pan'] ?? $in['card_number'] ?? $in['icc_pan'] ?? '')),
        'card_expiry' => $exp,
        'card_cvv' => (string) ($in['cvv'] ?? $in['card_cvv'] ?? ''),
        'card_name' => (string) ($in['card_name'] ?? $in['holder'] ?? 'CARDHOLDER'),
        'card_type' => 'LIVE',
        'card_network' => (string) ($in['scheme'] ?? $in['card_network'] ?? 'auto'),
        'input_mode' => $entry === 'keyed' ? 'manual' : 'physical',
        'entry_mode' => $entry,
        'channels' => $entry === 'nfc_contactless' ? ['nfc'] : ['pos'],
        'pos_model' => 'verifone_vx675',
        'terminal_id' => (string) ($in['tid'] ?? $in['terminal_id'] ?? $commission['nuvei_tid'] ?? ''),
        'merchant_line' => (string) ($in['line'] ?? $in['merchant_line'] ?? ''),
        'rrn' => (string) ($in['rrn'] ?? ''),
        'approval_code' => (string) ($in['approval_code'] ?? $in['auth_code'] ?? ''),
        'nuvei_txn_id' => (string) ($in['nuvei_txn_id'] ?? $in['transaction_id'] ?? ''),
        'extra' => [
            'device' => 'verifone_vx675',
            'os' => 'Verix V',
            'sdk' => 'Verix V SDK',
            'acquirer' => $gw,
            'gateway' => $gw,
            'payment_app' => 'Nuvei Payment App',
            'payment_app_installed' => !empty($commission['payment_app_installed']),
            'keys_injected' => !empty($commission['keys_injected']),
            'key_injection' => 'nuvei_rki',
            'pos_model' => 'verifone_vx675',
            'terminal_id' => (string) ($in['tid'] ?? $in['terminal_id'] ?? ''),
            'aid' => $in['aid'] ?? '',
            'tvr' => $in['tvr'] ?? '',
            'tsi' => $in['tsi'] ?? '',
            'entry_mode' => $entry,
            'nuvei_txn_id' => (string) ($in['nuvei_txn_id'] ?? $in['transaction_id'] ?? ''),
        ],
    ];
}

function verifone_vx675_host_url(): string
{
    $base = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '';
    return $base . '/pos/api/verifone.php';
}

function verifone_vx675_sdk_urls(): array
{
    $dev = function_exists('pos_device_get') ? pos_device_get('verifone_vx675') : null;
    return is_array($dev['sdk_urls'] ?? null) ? $dev['sdk_urls'] : [
        'developer' => 'https://developer.verifone.com',
        'verix' => 'https://developer.verifone.com/verix',
        'vx675' => 'https://developer.verifone.com/vx675',
        'docs' => 'https://docs.verifone.com/',
        'nuvei' => 'https://docs.nuvei.com/',
    ];
}
