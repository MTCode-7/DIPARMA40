<?php
/**
 * DI PARMA POS — every terminal model is accepted.
 * Unknown models are still accepted as a generic POS (not rejected, not forced to Bitel).
 */
if (defined('DI_PARMA_POS_DEVICES')) {
    return;
}
define('DI_PARMA_POS_DEVICES', true);

function pos_device_types(): array
{
    return [
        'android_smart_pos' => ['ar' => 'جهاز POS أندرويد', 'en' => 'Android Smart POS'],
        'linux_pos' => ['ar' => 'جهاز POS لينكس', 'en' => 'Linux POS'],
        'windows_pos' => ['ar' => 'جهاز POS ويندوز', 'en' => 'Windows POS'],
        'tablet_reader' => ['ar' => 'تابلت + قارئ', 'en' => 'Tablet + reader'],
        'keyboard_wedge' => ['ar' => 'قارئ Keyboard Wedge', 'en' => 'Keyboard-wedge reader'],
        'verix_v' => ['ar' => 'Verifone Verix V', 'en' => 'Verifone Verix V'],
        'ingenico' => ['ar' => 'Ingenico', 'en' => 'Ingenico'],
        'softpos' => ['ar' => 'SoftPOS / Tap to Phone', 'en' => 'SoftPOS / Tap to Phone'],
        'desktop_browser' => ['ar' => 'متصفح مكتبي', 'en' => 'Desktop browser'],
    ];
}

function pos_device_entry(string $model, string $brand, string $name, string $type, array $extra = []): array
{
    $base = [
        'code' => strtoupper(str_replace(['-', ' '], '_', $model)),
        'model' => $model,
        'brand' => $brand,
        'name' => $name,
        'label' => trim($brand . ' ' . $name),
        'type' => $type,
        'accepted' => true,
        'kiosk' => true,
        'wedge' => true,
        'nfc_hw' => true,
        'chip' => true,
        'pwa' => $type !== 'verix_v',
        'detect' => [],
    ];
    return array_merge($base, $extra);
}

require_once __DIR__ . '/device_models.php';

function pos_device_catalog(): array
{
    $list = [
        pos_device_entry('bitel_ic3600', 'Bitel', 'IC3600', 'android_smart_pos', ['region' => 'gulf', 'detect' => ['bitel', 'ic3600']]),
        pos_device_entry('bitel_ic3800', 'Bitel', 'IC3800', 'android_smart_pos', ['region' => 'gulf', 'detect' => ['ic3800']]),
        pos_device_entry('verifone_vx675', 'Verifone', 'VX 675', 'verix_v', [
            'region' => 'americas',
            'os' => 'Verix V',
            'sdk' => 'Verix V SDK',
            'acquirer' => 'nuvei',
            'payment_app' => 'Nuvei Payment App',
            'key_injection' => 'nuvei_rki',
            'kiosk' => false,
            'wedge' => false,
            'pwa' => false,
            'magstripe' => true,
            'native_api' => '/pos/api/verifone.php',
            'detect' => ['vx675', 'verifone', 'verix'],
            'sdk_urls' => [
                'developer' => 'https://developer.verifone.com',
                'verix' => 'https://developer.verifone.com/verix',
                'vx675' => 'https://developer.verifone.com/vx675',
                'docs' => 'https://docs.verifone.com/',
                'nuvei' => 'https://docs.nuvei.com/',
            ],
        ]),
        pos_device_entry('verifone_vx520', 'Verifone', 'VX 520', 'verix_v', ['region' => 'americas', 'os' => 'Verix V', 'pwa' => false, 'detect' => ['vx520']]),
        pos_device_entry('verifone_vx680', 'Verifone', 'VX 680', 'verix_v', ['region' => 'americas', 'os' => 'Verix V', 'pwa' => false, 'detect' => ['vx680']]),
        pos_device_entry('verifone_vx820', 'Verifone', 'VX 820', 'verix_v', ['region' => 'americas', 'os' => 'Verix V', 'pwa' => false, 'detect' => ['vx820']]),
        pos_device_entry('verifone_t650p', 'Verifone', 'T650p', 'android_smart_pos', ['region' => 'americas', 'detect' => ['t650']]),
        pos_device_entry('verifone_p400', 'Verifone', 'P400', 'android_smart_pos', ['region' => 'americas', 'detect' => ['p400']]),
        pos_device_entry('ingenico_move5000', 'Ingenico', 'Move 5000', 'ingenico', ['region' => 'europe', 'detect' => ['move5000', 'ingenico']]),
        pos_device_entry('ingenico_desk5000', 'Ingenico', 'Desk 5000', 'ingenico', ['region' => 'europe', 'detect' => ['desk5000']]),
        pos_device_entry('ingenico_lane3000', 'Ingenico', 'Lane 3000', 'ingenico', ['region' => 'europe', 'detect' => ['lane3000']]),
        pos_device_entry('ingenico_axiium', 'Ingenico', 'AXIUM DX8000', 'android_smart_pos', ['region' => 'europe', 'detect' => ['axiium', 'dx8000']]),
        pos_device_entry('pax_a920', 'PAX', 'A920', 'android_smart_pos', ['region' => 'east_asia', 'detect' => ['pax', 'a920']]),
        pos_device_entry('pax_a77', 'PAX', 'A77', 'android_smart_pos', ['region' => 'east_asia', 'detect' => ['a77']]),
        pos_device_entry('pax_a35', 'PAX', 'A35', 'android_smart_pos', ['region' => 'east_asia', 'detect' => ['a35']]),
        pos_device_entry('pax_a80', 'PAX', 'A80', 'android_smart_pos', ['region' => 'east_asia', 'detect' => ['a80']]),
        pos_device_entry('pax_a910', 'PAX', 'A910', 'android_smart_pos', ['region' => 'east_asia', 'detect' => ['a910']]),
        pos_device_entry('pax_im30', 'PAX', 'IM30', 'android_smart_pos', ['region' => 'east_asia', 'detect' => ['im30']]),
        pos_device_entry('sunmi_p2', 'Sunmi', 'P2', 'android_smart_pos', ['region' => 'east_asia', 'detect' => ['sunmi', 'p2']]),
        pos_device_entry('sunmi_p2_pro', 'Sunmi', 'P2 Pro', 'android_smart_pos', ['region' => 'east_asia', 'detect' => ['p2 pro']]),
        pos_device_entry('sunmi_v2s', 'Sunmi', 'V2s', 'android_smart_pos', ['region' => 'east_asia', 'detect' => ['v2s']]),
        pos_device_entry('sunmi_v3', 'Sunmi', 'V3', 'android_smart_pos', [
            'region' => 'east_asia',
            'os' => 'Android',
            'pwa' => true,
            'nfc_hw' => true,
            'chip' => true,
            'magstripe' => true,
            'channel' => 'sunmi_v3',
            'detect' => ['sunmi v3', 'sunmi_v3', 'v3'],
            'sdk_urls' => [
                'sunmi' => 'https://developer.sunmi.com/',
            ],
        ]),
        pos_device_entry('web_pos', 'Web', 'Browser POS', 'desktop_browser', [
            'region' => 'global',
            'channel' => 'web',
            'kiosk' => false,
            'nfc_hw' => false,
            'chip' => false,
            'pwa' => true,
            'detect' => ['web', 'browser'],
        ]),
        pos_device_entry('app_pos', 'App', 'Mobile App POS', 'android_smart_pos', [
            'region' => 'global',
            'channel' => 'app',
            'detect' => ['app', 'mobile_app'],
        ]),
        pos_device_entry('newland_n910', 'Newland', 'N910', 'android_smart_pos', ['region' => 'east_asia', 'detect' => ['newland', 'n910']]),
        pos_device_entry('newland_n950', 'Newland', 'N950', 'android_smart_pos', ['region' => 'east_asia', 'detect' => ['n950']]),
        pos_device_entry('castles_s1f2', 'Castles', 'S1F2', 'android_smart_pos', ['region' => 'east_asia', 'detect' => ['castles', 's1f2']]),
        pos_device_entry('urovo_i9000s', 'Urovo', 'i9000s', 'android_smart_pos', ['region' => 'east_asia', 'detect' => ['urovo']]),
        pos_device_entry('telpo_m8', 'Telpo', 'M8', 'android_smart_pos', ['region' => 'east_asia', 'detect' => ['telpo']]),
        pos_device_entry('morefun_mx8', 'MoreFun', 'MX8', 'android_smart_pos', ['region' => 'east_asia', 'detect' => ['morefun']]),
        pos_device_entry('nexgo_n86', 'Nexgo', 'N86', 'android_smart_pos', ['region' => 'east_asia', 'detect' => ['nexgo']]),
        pos_device_entry('datecs_bluepad', 'Datecs', 'BluePad', 'android_smart_pos', ['region' => 'europe', 'detect' => ['datecs']]),
        pos_device_entry('android_generic', 'Android', 'Smart POS', 'android_smart_pos', ['region' => 'global', 'detect' => ['android']]),
        pos_device_entry('linux_generic', 'Linux', 'POS', 'linux_pos', ['region' => 'global', 'nfc_hw' => false, 'pwa' => false, 'detect' => ['x11', 'linux']]),
        pos_device_entry('windows_generic', 'Windows', 'POS', 'windows_pos', ['region' => 'global', 'kiosk' => false, 'nfc_hw' => false, 'pwa' => false, 'detect' => ['windows', 'win64', 'wow64']]),
        pos_device_entry('tablet_generic', 'Tablet', 'Reader', 'tablet_reader', ['region' => 'global', 'chip' => false, 'detect' => ['ipad', 'tablet']]),
        pos_device_entry('keyboard_wedge', 'HID', 'Wedge', 'keyboard_wedge', ['region' => 'global', 'nfc_hw' => false, 'chip' => false, 'pwa' => false]),
        pos_device_entry('chrome_desktop', 'Chrome', 'Desktop', 'desktop_browser', ['region' => 'global', 'kiosk' => false, 'nfc_hw' => false, 'chip' => false, 'pwa' => false]),
        pos_device_entry('generic_pos', 'POS', 'Any terminal', 'android_smart_pos', ['region' => 'global', 'detect' => []]),
    ];
    if (function_exists('pos_device_regional_models')) {
        $list = array_merge($list, pos_device_regional_models());
    }
    $out = [];
    foreach ($list as $row) {
        $out[$row['model']] = $row;
    }
    if (function_exists('pos_custom_devices')) {
        foreach (pos_custom_devices() as $row) {
            if (!empty($row['model'])) {
                $out[$row['model']] = $row;
            }
        }
    }
    return $out;
}

function pos_device_aliases(): array
{
    return [
        'ic3600' => 'bitel_ic3600',
        'bitel' => 'bitel_ic3600',
        'bitel-ic3600' => 'bitel_ic3600',
        'bitel_countertop' => 'bitel_ic3600',
        'vx675' => 'verifone_vx675',
        'vx-675' => 'verifone_vx675',
        'verifone' => 'verifone_vx675',
        'verix' => 'verifone_vx675',
        'verix_v' => 'verifone_vx675',
        'vx520' => 'verifone_vx520',
        'vx680' => 'verifone_vx680',
        'vx820' => 'verifone_vx820',
        't650p' => 'verifone_t650p',
        'p400' => 'verifone_p400',
        'clover' => 'clover_flex',
        'square' => 'square_terminal',
        'amex' => 'amex_harmony',
        'visa' => 'visa_tap_to_phone',
        'mastercard' => 'mastercard_tap_on_phone',
        'softpos' => 'visa_softpos',
        'geidea' => 'geidea_smart',
        'pinelabs' => 'pinelabs_plutus',
        'paytm' => 'paytm_edc',
        'moniepoint' => 'moniepoint_pos',
        'move5000' => 'ingenico_move5000',
        'desk5000' => 'ingenico_desk5000',
        'lane3000' => 'ingenico_lane3000',
        'a920' => 'pax_a920',
        'a920pro' => 'pax_a920pro',
        'a920_pro' => 'pax_a920pro',
        'hala' => 'hala_smart',
        'castles' => 'castles_vega3000',
        'vega' => 'castles_vega3000',
        'a77' => 'pax_a77',
        'a35' => 'pax_a35',
        'a80' => 'pax_a80',
        'a910' => 'pax_a910',
        'im30' => 'pax_im30',
        'p2' => 'sunmi_p2',
        'n910' => 'newland_n910',
        'wedge' => 'keyboard_wedge',
        'chrome' => 'chrome_desktop',
        'desktop' => 'chrome_desktop',
        'any' => 'generic_pos',
        'other' => 'generic_pos',
        'unknown' => 'generic_pos',
    ];
}

function pos_device_accept_unknown(string $model): array
{
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $model), '_'));
    if ($slug === '') {
        $slug = 'generic_pos';
    }
    $label = trim(str_replace('_', ' ', $model));
    if ($label === '') {
        $label = 'POS';
    }
    return pos_device_entry($slug, 'POS', $label, 'android_smart_pos', [
        'label' => $label,
        'accepted' => true,
        'unknown' => true,
    ]);
}

/** Always returns a device. Unknown models are accepted, never rejected. */
function pos_device_get(string $model): ?array
{
    $model = strtolower(trim($model));
    if ($model === '') {
        return pos_device_catalog()['generic_pos'] ?? pos_device_accept_unknown('generic_pos');
    }
    $alias = pos_device_aliases();
    $key = $alias[$model] ?? $model;
    $all = pos_device_catalog();
    if (isset($all[$key])) {
        $all[$key]['accepted'] = true;
        return $all[$key];
    }
    return pos_device_accept_unknown($model);
}

function pos_device_detect(?string $ua = null): array
{
    $ua = strtolower((string) ($ua ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')));
    foreach (pos_device_catalog() as $device) {
        foreach ($device['detect'] as $needle) {
            if ($needle !== '' && strpos($ua, $needle) !== false) {
                $device['detected'] = true;
                $device['accepted'] = true;
                return $device;
            }
        }
    }
    $any = pos_device_get('generic_pos');
    return ($any ?: pos_device_accept_unknown('generic_pos')) + ['detected' => false, 'accepted' => true];
}

function pos_default_terminal_id(): string
{
    return 'T705953';
}

function pos_normalize_terminal_id(string $tid): string
{
    $tid = strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', $tid));
    if ($tid === '' || $tid === 'T0000001') {
        return pos_default_terminal_id();
    }
    return substr($tid, 0, 16);
}

function pos_device_resolve(array $input = [], ?string $ua = null): array
{
    $raw = strtolower(trim((string) ($input['pos_model'] ?? $input['device'] ?? $input['pos_device'] ?? '')));
    $device = pos_device_get($raw);
    if (!$device) {
        $device = pos_device_get('generic_pos');
    }
    $device['accepted'] = true;
    $device['detected'] = !empty($device['detected']);
    $device['terminal_id'] = pos_normalize_terminal_id((string) ($input['terminal_id'] ?? $input['tid'] ?? pos_default_terminal_id()));
    $nfcOn = !empty($input['withdrawal_nfc']);
    $posOn = !empty($input['withdrawal_pos']) || !$nfcOn;
    $device['nfc'] = $nfcOn;
    $device['pos_chip'] = $posOn;
    if ($nfcOn && !$posOn) {
        $device['code'] = 'NFC_CONTACTLESS';
    }
    $device['commission'] = pos_device_commission($device);
    return $device;
}

function pos_env_flag(string $key, bool $default = false): bool
{
    $raw = '';
    if (function_exists('env')) {
        $raw = (string) env($key, '');
    }
    if ($raw === '') {
        $raw = (string) (getenv($key) ?: '');
    }
    $raw = strtolower(trim($raw));
    if ($raw === '') {
        return $default;
    }
    return in_array($raw, ['1', 'true', 'yes', 'on'], true);
}

function pos_device_is_verix(array $device): bool
{
    return ($device['type'] ?? '') === 'verix_v' || strpos((string) ($device['model'] ?? ''), 'verifone_vx') === 0;
}

function pos_device_locked_gateway(array $device): string
{
    return strtolower(trim((string) ($device['locked_gateway'] ?? '')));
}

function pos_device_commission(array $device): array
{
    $isVerix = pos_device_is_verix($device);
    return [
        'os' => (string) ($device['os'] ?? ($isVerix ? 'Verix V' : '')),
        'acquirer' => (string) ($device['acquirer'] ?? ''),
        'locked_gateway' => '',
        'payment_app' => (string) ($device['payment_app'] ?? ''),
        'payment_app_installed' => true,
        'keys_injected' => true,
        'key_injection' => (string) ($device['key_injection'] ?? ''),
        'nuvei_tid' => '',
        'ready' => true,
        'accepted' => true,
    ];
}

function pos_device_is_commissioned(array $device): bool
{
    return true;
}
