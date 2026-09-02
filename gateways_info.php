<?php
/**
 * DI PARMA | طھطµط¯ظٹط± ط¨ظٹط§ظ†ط§طھ ط§ظ„ط¨ظˆط§ط¨ط§طھ ط¥ظ„ظ‰ CSV
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';

$db = db();
$gateways = $db->query("SELECT * FROM dp_payment_gateways ORDER BY status DESC, name ASC");

// ط¥ظ†ط´ط§ط، CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="diparma_gateways_' . date('Y-m-d') . '.csv"');
header('Pragma: no-cache');

$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM ظ„ظ„ط¹ط±ط¨ظٹط©

// ط§ظ„ط¹ظ†ط§ظˆظٹظ†
fputcsv($out, [
    'ط§ظ„ظƒظˆط¯', 'ط§ظ„ط§ط³ظ…', 'ط§ظ„ظ†ظˆط¹', 'ط§ظ„ط­ط§ظ„ط©', 'ط§ظ„ظ…ظ†ط·ظ‚ط©',
    'ط§ظ„ط±ط³ظˆظ… %', 'ط§ظ„ط­ط¯ ط§ظ„ط£ط¯ظ†ظ‰', 'ط§ظ„ط­ط¯ ط§ظ„ظٹظˆظ…ظٹ',
    'API Key', 'Secret Key', 'Webhook URL', 'ط§ظ„ط¨ظٹط¦ط©',
    'ط§ظ„ظ…ظٹط²ط§طھ', 'ط£ظ†ظˆط§ط¹ ط§ظ„ط¨ط·ط§ظ‚ط§طھ'
]);

foreach ($gateways as $gw) {
    $config  = json_decode($gw['config']      ?? '{}', true) ?: [];
    $creds   = json_decode($gw['credentials'] ?? '{}', true) ?: [];
    $settings= json_decode($gw['settings']    ?? '{}', true) ?: [];

    $fees    = $config['fees']   ?? [];
    $limits  = $config['limits'] ?? [];

    // ط¥ط®ظپط§ط، ط§ظ„ظ…ظپط§طھظٹط­ ط§ظ„ط­ط³ط§ط³ط© ط¬ط²ط¦ظٹط§ظ‹
    $apiKey = $creds['api_key'] ?? '';
    if (strlen($apiKey) > 8) {
        $apiKey = substr($apiKey, 0, 6) . str_repeat('*', strlen($apiKey) - 10) . substr($apiKey, -4);
    }
    $secretKey = $creds['secret_key'] ?? '';
    if (strlen($secretKey) > 8) {
        $secretKey = substr($secretKey, 0, 6) . str_repeat('*', strlen($secretKey) - 10) . substr($secretKey, -4);
    }

    fputcsv($out, [
        $gw['code'],
        $gw['name'],
        $gw['type'],
        $gw['status'] === 'active' ? 'ظ†ط´ط· âœ“' : 'ط؛ظٹط± ظ†ط´ط·',
        $config['region'] ?? '-',
        ($fees['percentage'] ?? 0) . '%',
        ($limits['min'] ?? 0) . ' USD',
        ($limits['max_daily'] ?? 0) . ' USD',
        $apiKey ?: '(ظپط§ط±ط؛)',
        $secretKey ?: '(ظپط§ط±ط؛)',
        $settings['webhook_url'] ?? '-',
        $settings['environment'] ?? $config['environment'] ?? 'sandbox',
        implode(' | ', $config['features']   ?? []),
        implode(' | ', $config['card_types'] ?? []),
    ]);
}

fclose($out);

