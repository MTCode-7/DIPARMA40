<?php
/**
 * Custom POS catalog — extra TIDs, device models, and activities.
 */
if (defined('DI_PARMA_POS_CATALOG')) {
    return;
}
define('DI_PARMA_POS_CATALOG', true);
require_once __DIR__ . '/company_terminals.php';

function pos_custom_file(): string
{
    $dir = defined('CACHE_PATH') ? CACHE_PATH : (defined('POS_APP_ROOT') ? POS_APP_ROOT . '/cache' : dirname(__DIR__, 2) . '/cache');
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return rtrim($dir, '/\\') . '/pos_custom_catalog.json';
}

function pos_custom_defaults(): array
{
    return ['tids' => [], 'devices' => [], 'activities' => []];
}

function pos_custom_slug(string $raw): string
{
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $raw), '_'));
    return $slug !== '' ? substr($slug, 0, 40) : '';
}

function pos_custom_ensure_table(): void
{
    static $done = false;
    if ($done || !function_exists('db')) {
        return;
    }
    $done = true;
    try {
        $table = (defined('DB_PREFIX') ? DB_PREFIX : 'dp_') . 'pos_custom_catalog';
        db()->execute(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `kind` VARCHAR(20) NOT NULL,
                `code` VARCHAR(64) NOT NULL,
                `payload` TEXT NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uniq_kind_code` (`kind`, `code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        // file fallback
    }
}

function pos_custom_load(): array
{
    $data = pos_custom_defaults();
    pos_custom_ensure_table();
    try {
        if (function_exists('db')) {
            $table = (defined('DB_PREFIX') ? DB_PREFIX : 'dp_') . 'pos_custom_catalog';
            $rows = db()->query("SELECT kind, code, payload FROM `{$table}`") ?: [];
            foreach ($rows as $row) {
                $kind = (string) ($row['kind'] ?? '');
                $payload = json_decode((string) ($row['payload'] ?? ''), true);
                if (!is_array($payload)) {
                    continue;
                }
                if ($kind === 'tid') {
                    $payload['tid'] = (string) ($payload['tid'] ?? $row['code']);
                    $data['tids'][] = $payload;
                } elseif ($kind === 'device') {
                    $data['devices'][$payload['model'] ?? $row['code']] = $payload;
                } elseif ($kind === 'activity') {
                    $data['activities'][$payload['code'] ?? $row['code']] = $payload;
                }
            }
        }
    } catch (Throwable $e) {
        // use file
    }
    $file = pos_custom_file();
    if (is_file($file)) {
        $json = json_decode((string) file_get_contents($file), true);
        if (is_array($json)) {
            $data['tids'] = array_merge($json['tids'] ?? [], $data['tids']);
            $data['devices'] = array_merge($json['devices'] ?? [], $data['devices']);
            $data['activities'] = array_merge($json['activities'] ?? [], $data['activities']);
        }
    }
    return $data;
}

function pos_custom_put(string $kind, string $code, array $payload): bool
{
    pos_custom_ensure_table();
    $ok = false;
    try {
        if (function_exists('db')) {
            $table = (defined('DB_PREFIX') ? DB_PREFIX : 'dp_') . 'pos_custom_catalog';
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
            db()->execute(
                "INSERT INTO `{$table}` (kind, code, payload) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE payload = VALUES(payload)",
                [$kind, $code, $json]
            );
            $ok = true;
        }
    } catch (Throwable $e) {
        $ok = false;
    }
    $data = pos_custom_load();
    if ($kind === 'tid') {
        $data['tids'][] = $payload;
    } elseif ($kind === 'device') {
        $data['devices'][$code] = $payload;
    } elseif ($kind === 'activity') {
        $data['activities'][$code] = $payload;
    }
    @file_put_contents(pos_custom_file(), json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    return $ok || is_file(pos_custom_file());
}

function pos_custom_devices(): array
{
    $out = [];
    foreach (pos_custom_load()['devices'] as $row) {
        if (!is_array($row) || empty($row['model'])) {
            continue;
        }
        $out[] = $row;
    }
    return $out;
}

function pos_custom_activities(): array
{
    $out = [];
    foreach (pos_custom_load()['activities'] as $code => $row) {
        if (!is_array($row)) {
            continue;
        }
        $out[(string) ($row['code'] ?? $code)] = $row;
    }
    return $out;
}

function pos_saved_tids(): array
{
    $list = [];
    if (function_exists('pos_tid_records')) {
        $list = array_merge($list, array_keys(pos_tid_records()));
    }
    foreach (pos_custom_load()['tids'] as $tid) {
        if (is_array($tid)) {
            $tid = (string) ($tid['tid'] ?? '');
        }
        $tid = function_exists('pos_normalize_terminal_id') ? pos_normalize_terminal_id((string) $tid) : strtoupper((string) $tid);
        if ($tid !== '') {
            $list[] = $tid;
        }
    }
    $cookie = function_exists('pos_normalize_terminal_id')
        ? pos_normalize_terminal_id((string) ($_COOKIE['di_parma_pos_tid'] ?? ''))
        : '';
    if ($cookie !== '') {
        $list[] = $cookie;
    }
    return array_values(array_unique($list));
}

function pos_custom_add_tid(string $tid, string $model = ''): array
{
    $tid = function_exists('pos_normalize_terminal_id') ? pos_normalize_terminal_id($tid) : strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', $tid));
    if ($tid === '') {
        return ['success' => false, 'message' => 'TID required'];
    }
    $model = strtolower(trim($model));
    $payload = ['tid' => $tid];
    if ($model !== '' && function_exists('pos_device_get') && pos_device_get($model)) {
        $dev = pos_device_get($model);
        $payload['model'] = $model;
        $payload['brand'] = (string) ($dev['brand'] ?? '');
        $payload['name'] = (string) ($dev['name'] ?? '');
    }
    pos_custom_put('tid', $tid, $payload);
    return ['success' => true, 'tid' => $tid, 'model' => $payload['model'] ?? $model];
}

function pos_custom_add_device(string $brand, string $name, string $type, string $region = 'global'): array
{
    $brand = trim($brand);
    $name = trim($name);
    if ($brand === '' && $name === '') {
        return ['success' => false, 'message' => 'Model required'];
    }
    if ($name === '') {
        $name = $brand;
    }
    if ($brand === '') {
        $brand = 'POS';
    }
    $types = function_exists('pos_device_types') ? array_keys(pos_device_types()) : [];
    if ($type === '' || ($types && !in_array($type, $types, true))) {
        $type = 'android_smart_pos';
    }
    $model = pos_custom_slug($brand . '_' . $name);
    if ($model === '') {
        $model = 'pos_' . substr(bin2hex(random_bytes(3)), 0, 6);
    }
    $regions = function_exists('pos_device_regions') ? array_keys(pos_device_regions()) : [];
    if ($region === '' || ($regions && !in_array($region, $regions, true))) {
        $region = 'global';
    }
    $row = function_exists('pos_device_entry')
        ? pos_device_entry($model, $brand, $name, $type, ['custom' => true, 'region' => $region])
        : ['model' => $model, 'brand' => $brand, 'name' => $name, 'label' => trim($brand . ' ' . $name), 'type' => $type, 'custom' => true, 'region' => $region];
    pos_custom_put('device', $model, $row);
    return ['success' => true, 'model' => $model, 'label' => $row['label'] ?? $name];
}

function pos_custom_add_activity(string $en, string $ar, string $mcc, string $suggest = ''): array
{
    $en = trim($en);
    $ar = trim($ar);
    if ($en === '' && $ar === '') {
        return ['success' => false, 'message' => 'Activity name required'];
    }
    if ($en === '') {
        $en = $ar;
    }
    if ($ar === '') {
        $ar = $en;
    }
    $mcc = preg_replace('/\D/', '', $mcc);
    if (strlen($mcc) < 4) {
        $mcc = '5999';
    }
    $mcc = substr($mcc, 0, 4);
    $code = pos_custom_slug($en);
    if ($code === '' || in_array($code, ['petroleum', 'hajj', 'hotels', 'expo', 'autos', 'rental'], true)) {
        $code = 'act_' . substr(bin2hex(random_bytes(3)), 0, 6);
    }
    $payload = [
        'code' => $code,
        'mcc' => $mcc,
        'ar' => $ar,
        'en' => $en,
        'item' => $en,
        'suggested_gateway' => strtolower(trim($suggest)),
        'custom' => true,
    ];
    pos_custom_put('activity', $code, $payload);
    return ['success' => true, 'line' => $code];
}
