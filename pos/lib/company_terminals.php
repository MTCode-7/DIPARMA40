<?php
/**
 * Company fleet — HALA / Castles / PAX A920Pro and their TIDs.
 * More units can be added from POS (Add TID + model).
 */
if (defined('DI_PARMA_POS_COMPANY_TERMINALS')) {
    return;
}
define('DI_PARMA_POS_COMPANY_TERMINALS', true);

function pos_company_terminals(): array
{
    return [
        ['tid' => '16526257', 'model' => 'hala_smart', 'brand' => 'HALA', 'name' => 'Smart POS', 'note' => '800 303 0122'],
        ['tid' => '16526246', 'model' => 'hala_smart', 'brand' => 'HALA', 'name' => 'Smart POS', 'note' => '800 303 0122'],
        ['tid' => '16526258', 'model' => 'hala_smart', 'brand' => 'HALA', 'name' => 'Smart POS', 'note' => '800 303 0122'],
        ['tid' => 'S37424906', 'model' => 'pax_a920pro', 'brand' => 'PAX', 'name' => 'A920Pro', 'note' => '8003300065'],
        ['tid' => '43743901', 'model' => 'pax_a920pro', 'brand' => 'PAX', 'name' => 'A920Pro', 'note' => ''],
        ['tid' => '', 'model' => 'castles_vega3000', 'brand' => 'Castles', 'name' => 'VEGA 3000', 'note' => 'أضف TID'],
        ['tid' => '', 'model' => 'hala_handheld', 'brand' => 'HALA', 'name' => 'Handheld', 'note' => 'أضف TID'],
    ];
}

function pos_company_terminal_models(): array
{
    if (!function_exists('pos_m')) {
        return [];
    }
    $a = 'android_smart_pos';
    $s = 'softpos';
    return [
        pos_m('hala_smart', 'HALA', 'Smart POS', $a, 'gulf', ['detect' => ['hala', '165262']]),
        pos_m('hala_a920', 'HALA', 'A920', $a, 'gulf', ['detect' => ['hala a920']]),
        pos_m('hala_handheld', 'HALA', 'Handheld', $a, 'gulf', ['detect' => ['hala handheld']]),
        pos_m('hala_softpos', 'HALA', 'SoftPOS', $s, 'gulf', ['chip' => false]),
        pos_m('castles_vega', 'Castles', 'VEGA', $a, 'gulf', ['detect' => ['castles vega', 'vega']]),
        pos_m('pax_a920pro_gulf', 'PAX', 'A920Pro', $a, 'gulf', ['detect' => ['a920pro', 'a920 pro']]),
        pos_m('pax_s920', 'PAX', 'S920', $a, 'gulf', ['detect' => ['s920']]),
        pos_m('pax_d180', 'PAX', 'D180', $a, 'gulf', ['pwa' => false, 'detect' => ['d180']]),
    ];
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
            'label' => trim(($row['brand'] ?? '') . ' ' . ($row['name'] ?? '') . ' · ' . $tid),
            'company' => true,
        ];
    }
    $custom = function_exists('pos_custom_load') ? pos_custom_load() : ['tids' => []];
    foreach ($custom['tids'] as $item) {
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
                'label' => trim(((string) ($item['brand'] ?? '') . ' ' . (string) ($item['name'] ?? '')) . ' · ' . $tid),
                'company' => false,
            ];
        } else {
            $tid = function_exists('pos_normalize_terminal_id')
                ? pos_normalize_terminal_id((string) $item)
                : strtoupper((string) $item);
            if ($tid === '') {
                continue;
            }
            if (!isset($out[$tid])) {
                $out[$tid] = ['tid' => $tid, 'model' => '', 'brand' => '', 'name' => '', 'label' => $tid, 'company' => false];
            }
        }
    }
    return $out;
}
