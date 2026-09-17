<?php
/**
 * Company fleet — ~86 physical POS / TID inventory.
 *
 * - Known TIDs from device stickers/photos are seeded below.
 * - Remaining slots stay empty until you paste TIDs.
 * - Bulk import: cache/pos_company_tids.txt  (one TID per line)
 *   optional format: TID|model|brand|name|line
 * - Activity (merchant line) ↔ terminal binding is optional for now:
 *   leave `line` empty; bind later via import or known_tids rows.
 * - Capacity rule: each activity owns 5–15 terminals.
 *
 * Brands in fleet photos:
 *   HALA · Bitel IC5100 · Geidea · SoftPOS · Newland · PAX · Castles · Alinma
 */
if (defined('DI_PARMA_POS_COMPANY_TERMINALS')) {
    return;
}
define('DI_PARMA_POS_COMPANY_TERMINALS', true);

/** Target fleet size (~86 TIDs). */
function pos_company_fleet_target(): int
{
    return 86;
}

/** Min terminals expected per merchant activity. */
function pos_activity_terminals_min(): int
{
    return 5;
}

/** Max terminals allowed per merchant activity. */
function pos_activity_terminals_max(): int
{
    return 15;
}

/**
 * Confirmed TIDs read from device labels / photos.
 * `line` = merchant activity code (petroleum|logistics|hajj|…) — empty until bound later.
 * No `gateway_id` on these rows: charge gateway is the POS `gw=` / hub pick (Square, PayRam, …).
 *
 * @return list<array{tid:string,model:string,brand:string,name:string,line?:string,note?:string,serial?:string}>
 */
function pos_company_known_tids(): array
{
    $hala = '800 303 0122';
    return [
        ['tid' => '16526257', 'model' => 'hala_smart', 'brand' => 'HALA', 'name' => 'Smart POS', 'line' => '', 'note' => $hala],
        ['tid' => '16526246', 'model' => 'hala_smart', 'brand' => 'HALA', 'name' => 'Smart POS', 'line' => '', 'note' => $hala],
        ['tid' => '16526258', 'model' => 'hala_smart', 'brand' => 'HALA', 'name' => 'Smart POS', 'line' => '', 'note' => $hala],
        ['tid' => '16526256', 'model' => 'hala_smart', 'brand' => 'HALA', 'name' => 'Smart POS', 'line' => '', 'note' => $hala],
        ['tid' => '18526257', 'model' => 'hala_smart', 'brand' => 'HALA', 'name' => 'Smart POS', 'line' => '', 'note' => $hala],
        ['tid' => '18526258', 'model' => 'hala_smart', 'brand' => 'HALA', 'name' => 'Smart POS', 'line' => '', 'note' => $hala],
        ['tid' => '10526267', 'model' => 'hala_smart', 'brand' => 'HALA', 'name' => 'Smart POS', 'line' => '', 'note' => $hala],
        ['tid' => '10441079', 'model' => 'hala_smart', 'brand' => 'HALA', 'name' => 'Smart POS', 'line' => '', 'note' => $hala],
        ['tid' => '52400010', 'model' => 'softpos_phone', 'brand' => 'SoftPOS', 'name' => 'Phone', 'line' => '', 'note' => 'POS-21 label 5240-0010'],
        ['tid' => '52400099', 'model' => 'softpos_phone', 'brand' => 'SoftPOS', 'name' => 'Phone', 'line' => '', 'note' => 'POS-25 label 5240-0099'],
        ['tid' => 'S37424906', 'model' => 'pax_a920pro', 'brand' => 'PAX', 'name' => 'A920Pro', 'line' => '', 'note' => '8003300065'],
        ['tid' => '43743901', 'model' => 'pax_a920pro', 'brand' => 'PAX', 'name' => 'A920Pro', 'line' => '', 'note' => ''],
    ];
}

/** Model mix for empty fleet slots (cycles until 86). */
function pos_company_slot_models(): array
{
    return [
        ['model' => 'hala_smart', 'brand' => 'HALA', 'name' => 'Smart POS', 'note' => '800 303 0122 — أضف TID'],
        ['model' => 'hala_smart', 'brand' => 'HALA', 'name' => 'Smart POS', 'note' => '800 303 0122 — أضف TID'],
        ['model' => 'hala_handheld', 'brand' => 'HALA', 'name' => 'Handheld', 'note' => 'أضف TID'],
        ['model' => 'hala_a920', 'brand' => 'HALA', 'name' => 'A920', 'note' => 'أضف TID'],
        ['model' => 'hala_softpos', 'brand' => 'HALA', 'name' => 'SoftPOS', 'note' => 'Tap to Phone'],
        ['model' => 'bitel_ic5100', 'brand' => 'Bitel', 'name' => 'IC5100', 'note' => 'أضف TID'],
        ['model' => 'bitel_ic3600', 'brand' => 'Bitel', 'name' => 'IC3600', 'note' => 'أضف TID'],
        ['model' => 'geidea_m3', 'brand' => 'Geidea', 'name' => 'M3', 'note' => 'أضف TID'],
        ['model' => 'geidea_smart', 'brand' => 'Geidea', 'name' => 'Smart POS', 'note' => 'أضف TID'],
        ['model' => 'geidea_softpos', 'brand' => 'Geidea', 'name' => 'SoftPOS', 'note' => 'Tap to Phone'],
        ['model' => 'softpos_phone', 'brand' => 'SoftPOS', 'name' => 'Phone', 'note' => 'Tap to Phone'],
        ['model' => 'nearpay_softpos', 'brand' => 'NearPay', 'name' => 'SoftPOS', 'note' => 'Tap to Phone'],
        ['model' => 'newland_n910', 'brand' => 'Newland', 'name' => 'N910', 'note' => 'أضف TID'],
        ['model' => 'newland_n950', 'brand' => 'Newland', 'name' => 'N950', 'note' => 'أضف TID'],
        ['model' => 'pax_a920pro', 'brand' => 'PAX', 'name' => 'A920Pro', 'note' => 'أضف TID'],
        ['model' => 'pax_s920', 'brand' => 'PAX', 'name' => 'S920', 'note' => 'Keypad — أضف TID'],
        ['model' => 'castles_vega3000', 'brand' => 'Castles', 'name' => 'VEGA 3000', 'note' => 'أضف TID'],
        ['model' => 'alinma_pos', 'brand' => 'Alinma', 'name' => 'POS', 'note' => 'مصرف الإنماء — أضف TID'],
        ['model' => 'verifone_vx675', 'brand' => 'Verifone', 'name' => 'VX 675', 'note' => 'Nuvei — أضف TID'],
        ['model' => 'sunmi_v3', 'brand' => 'Sunmi', 'name' => 'V3', 'note' => 'أضف TID'],
        ['model' => 'sunmi_v3_mix', 'brand' => 'Sunmi', 'name' => 'V3 MIX', 'note' => 'قارئ شريحة/NFC — أضف TID الحقيقي وليس الرقم التسلسلي'],
    ];
}

function pos_company_tids_import_file(): string
{
    $dir = defined('CACHE_PATH') ? CACHE_PATH : (defined('POS_APP_ROOT') ? POS_APP_ROOT . '/cache' : dirname(__DIR__, 2) . '/cache');
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return rtrim($dir, '/\\') . '/pos_company_tids.txt';
}

/**
 * Load extra TIDs from cache/pos_company_tids.txt
 * Lines: TID   or   TID|model|brand|name   or   TID|model|brand|name|line
 *
 * @return list<array{tid:string,model:string,brand:string,name:string,line:string,note:string}>
 */
function pos_company_import_tids_from_file(): array
{
    $file = pos_company_tids_import_file();
    if (!is_file($file)) {
        return [];
    }
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }
    $out = [];
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $parts = array_map('trim', explode('|', $line));
        $tid = (string) ($parts[0] ?? '');
        if ($tid === '') {
            continue;
        }
        $out[] = [
            'tid' => $tid,
            'model' => (string) ($parts[1] ?? 'hala_smart'),
            'brand' => (string) ($parts[2] ?? 'HALA'),
            'name' => (string) ($parts[3] ?? 'Smart POS'),
            'line' => strtolower((string) ($parts[4] ?? '')),
            'note' => 'imported',
        ];
    }
    return $out;
}

/**
 * Full company fleet (target 86). Known + imported TIDs first, then empty slots.
 *
 * @return list<array<string,mixed>>
 */
function pos_company_terminals(): array
{
    $target = pos_company_fleet_target();
    $slots = pos_company_slot_models();
    $seed = array_merge(pos_company_known_tids(), pos_company_import_tids_from_file());

    // Deduplicate by normalized TID
    $seen = [];
    $units = [];
    foreach ($seed as $row) {
        $raw = trim((string) ($row['tid'] ?? ''));
        $norm = $raw !== '' && function_exists('pos_normalize_terminal_id')
            ? pos_normalize_terminal_id($raw)
            : strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '');
        if ($norm !== '' && isset($seen[$norm])) {
            continue;
        }
        if ($norm !== '') {
            $seen[$norm] = true;
            $row['tid'] = $norm;
        }
        $row['line'] = strtolower(trim((string) ($row['line'] ?? '')));
        $units[] = $row;
    }

    $n = count($units);
    for ($i = $n; $i < $target; $i++) {
        $tpl = $slots[$i % count($slots)];
        $units[] = [
            'tid' => '',
            'model' => $tpl['model'],
            'brand' => $tpl['brand'],
            'name' => $tpl['name'],
            'line' => '',
            'note' => $tpl['note'],
        ];
    }

    // Assign fleet labels POS-01 … POS-86
    foreach ($units as $idx => &$row) {
        $row['fleet'] = 'POS-' . str_pad((string) ($idx + 1), 2, '0', STR_PAD_LEFT);
        if (!isset($row['line'])) {
            $row['line'] = '';
        }
    }
    unset($row);

    return array_slice($units, 0, $target);
}

/**
 * Company terminals that already have a real TID (empty fleet slots are hidden).
 *
 * @return list<array<string,mixed>>
 */
function pos_company_terminals_with_tid(): array
{
    $out = [];
    foreach (pos_tid_records() as $tid => $rec) {
        $tid = (string) $tid;
        if ($tid === '') {
            continue;
        }
        $out[] = [
            'tid' => $tid,
            'model' => (string) ($rec['model'] ?? ''),
            'brand' => (string) ($rec['brand'] ?? ''),
            'name' => (string) ($rec['name'] ?? ''),
            'line' => (string) ($rec['line'] ?? ''),
            'note' => '',
            'fleet' => (string) ($rec['fleet'] ?? ''),
        ];
    }
    return $out;
}

function pos_company_terminal_models(): array
{
    if (!function_exists('pos_m')) {
        return [];
    }
    $a = 'android_smart_pos';
    $s = 'softpos';
    $legacy = ['pwa' => false, 'wedge' => false];
    return [
        pos_m('hala_smart', 'HALA', 'Smart POS', $a, 'gulf', ['detect' => ['hala', '165262', '185262', '105262', '104410']]),
        pos_m('hala_a920', 'HALA', 'A920', $a, 'gulf', ['detect' => ['hala a920']]),
        pos_m('hala_handheld', 'HALA', 'Handheld', $a, 'gulf', ['detect' => ['hala handheld']]),
        pos_m('hala_softpos', 'HALA', 'SoftPOS', $s, 'gulf', ['chip' => false]),
        pos_m('bitel_ic5100', 'Bitel', 'IC5100', $a, 'gulf', [
            'detect' => ['ic5100', 'bitel ic5100', 'brs3617'],
            'serial_example' => 'BRS36170705953',
        ]),
        pos_m('softpos_phone', 'SoftPOS', 'Phone POS', $s, 'gulf', [
            'chip' => false,
            'nfc_hw' => true,
            'detect' => ['softpos phone', '5240'],
        ]),
        pos_m('geidea_m3', 'Geidea', 'M3', $a, 'gulf', ['detect' => ['geidea']]),
        pos_m('geidea_smart', 'Geidea', 'Smart POS', $a, 'gulf'),
        pos_m('geidea_softpos', 'Geidea', 'SoftPOS', $s, 'gulf', ['chip' => false]),
        pos_m('nearpay_softpos', 'NearPay', 'SoftPOS', $s, 'gulf', ['chip' => false]),
        pos_m('castles_vega', 'Castles', 'VEGA', $a, 'gulf', ['detect' => ['castles vega', 'vega']]),
        pos_m('pax_a920pro_gulf', 'PAX', 'A920Pro', $a, 'gulf', ['detect' => ['a920pro']]),
        pos_m('pax_s920', 'PAX', 'S920', $a, 'gulf', ['detect' => ['s920']]),
        pos_m('pax_d180', 'PAX', 'D180', $a, 'gulf', $legacy + ['detect' => ['d180']]),
        pos_m('newland_n910_gulf', 'Newland', 'N910', $a, 'gulf', ['detect' => ['newland']]),
        pos_m('alinma_pos', 'Alinma', 'POS', $a, 'gulf', ['detect' => ['alinma']]),
    ];
}

function pos_company_fleet_count(): int
{
    return count(pos_company_terminals());
}

function pos_company_fleet_stats(): array
{
    $byModel = [];
    $byLine = [];
    $withTid = 0;
    $withLine = 0;
    foreach (pos_company_terminals() as $row) {
        $m = (string) ($row['model'] ?? 'unknown');
        $byModel[$m] = ($byModel[$m] ?? 0) + 1;
        if (trim((string) ($row['tid'] ?? '')) !== '') {
            $withTid++;
        }
        $line = strtolower(trim((string) ($row['line'] ?? '')));
        if ($line !== '') {
            $withLine++;
            $byLine[$line] = ($byLine[$line] ?? 0) + 1;
        }
    }
    return [
        'total' => pos_company_fleet_count(),
        'target' => pos_company_fleet_target(),
        'with_tid' => $withTid,
        'missing_tid' => pos_company_fleet_count() - $withTid,
        'with_line' => $withLine,
        'unbound_line' => pos_company_fleet_count() - $withLine,
        'activity_terminals_min' => pos_activity_terminals_min(),
        'activity_terminals_max' => pos_activity_terminals_max(),
        'import_file' => pos_company_tids_import_file(),
        'by_model' => $byModel,
        'by_line' => $byLine,
    ];
}

/**
 * Activity code bound to a TID (empty until linked later).
 */
function pos_terminal_activity_line(string $tid): string
{
    $tid = function_exists('pos_normalize_terminal_id')
        ? pos_normalize_terminal_id($tid)
        : strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $tid) ?? '');
    if ($tid === '') {
        return '';
    }
    $rec = pos_tid_records()[$tid] ?? null;
    return is_array($rec) ? strtolower(trim((string) ($rec['line'] ?? ''))) : '';
}

/**
 * Terminals assigned to a merchant activity (empty list until you bind later).
 * Business rule: each activity typically has 5–15 terminals.
 *
 * @return list<array<string,mixed>>
 */
function pos_terminals_for_activity(string $line): array
{
    $line = strtolower(trim($line));
    if ($line === '') {
        return [];
    }
    $out = [];
    foreach (pos_company_terminals() as $row) {
        if (strtolower(trim((string) ($row['line'] ?? ''))) === $line) {
            $out[] = $row;
        }
    }
    return $out;
}

/**
 * Validate activity ↔ terminal capacity (5–15).
 * Empty binding is OK until you assign later.
 *
 * @return array{ok:bool,count:int,min:int,max:int,status:string,message:string}
 */
function pos_activity_terminal_capacity(string $line): array
{
    $min = pos_activity_terminals_min();
    $max = pos_activity_terminals_max();
    $count = count(pos_terminals_for_activity($line));
    $status = 'empty';
    $ok = true;
    $message = 'No terminals linked yet — bind 5 to 15 later.';

    if ($count > 0 && $count < $min) {
        $status = 'below_min';
        $ok = false;
        $message = "Activity has {$count} terminal(s); minimum is {$min}.";
    } elseif ($count >= $min && $count <= $max) {
        $status = 'ok';
        $ok = true;
        $message = "Activity has {$count} terminals (within {$min}–{$max}).";
    } elseif ($count > $max) {
        $status = 'above_max';
        $ok = false;
        $message = "Activity has {$count} terminals; maximum is {$max}.";
    }

    return [
        'ok' => $ok,
        'count' => $count,
        'min' => $min,
        'max' => $max,
        'status' => $status,
        'message' => $message,
    ];
}

/**
 * Capacity snapshot for every known merchant activity.
 *
 * @return array<string,array{ok:bool,count:int,min:int,max:int,status:string,message:string}>
 */
function pos_activity_terminal_capacity_map(): array
{
    $map = [];
    $codes = function_exists('pos_merchant_lines')
        ? array_keys(pos_merchant_lines())
        : [];
    foreach ($codes as $code) {
        $map[(string) $code] = pos_activity_terminal_capacity((string) $code);
    }
    // Include any bound lines not yet in merchant catalog
    foreach (pos_company_terminals() as $row) {
        $line = strtolower(trim((string) ($row['line'] ?? '')));
        if ($line !== '' && !isset($map[$line])) {
            $map[$line] = pos_activity_terminal_capacity($line);
        }
    }
    return $map;
}

function pos_tid_records(): array
{
    $out = [];
    foreach (pos_company_terminals() as $row) {
        $rawTid = trim((string) ($row['tid'] ?? ''));
        if ($rawTid === '') {
            continue;
        }
        $tid = function_exists('pos_normalize_terminal_id')
            ? pos_normalize_terminal_id($rawTid)
            : strtoupper($rawTid);
        if ($tid === '') {
            continue;
        }
        $out[$tid] = [
            'tid' => $tid,
            'model' => (string) ($row['model'] ?? ''),
            'brand' => (string) ($row['brand'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'line' => strtolower(trim((string) ($row['line'] ?? ''))),
            'fleet' => (string) ($row['fleet'] ?? ''),
            'label' => trim(($row['brand'] ?? '') . ' ' . ($row['name'] ?? '') . ' · ' . $tid),
            'company' => true,
        ];
    }
    $custom = function_exists('pos_custom_load') ? pos_custom_load() : ['tids' => []];
    foreach ($custom['tids'] ?? [] as $item) {
        if (is_array($item)) {
            $tid = function_exists('pos_normalize_terminal_id')
                ? pos_normalize_terminal_id((string) ($item['tid'] ?? ''))
                : strtoupper((string) ($item['tid'] ?? ''));
            if ($tid === '') {
                continue;
            }
            $out[$tid] = [
                'tid' => $tid,
                'model' => (string) ($item['model'] ?? ''),
                'brand' => (string) ($item['brand'] ?? ''),
                'name' => (string) ($item['name'] ?? ''),
                'line' => strtolower(trim((string) ($item['line'] ?? ''))),
                'fleet' => (string) ($item['fleet'] ?? ''),
                'label' => trim(((string) ($item['brand'] ?? '') . ' ' . (string) ($item['name'] ?? '')) . ' · ' . $tid),
                'company' => false,
            ];
        }
    }
    return $out;
}
