<?php
/**
 * DI PARMA | تصدير بيانات البوابات إلى CSV
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';

$db = db();
$gateways = $db->query("SELECT * FROM dp_payment_gateways ORDER BY status DESC, name ASC");

// إنشاء CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="diparma_gateways_' . date('Y-m-d') . '.csv"');
header('Pragma: no-cache');

$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM للعربية

// العناوين
fputcsv($out, [
    'الكود', 'الاسم', 'النوع', 'الحالة', 'المنطقة',
    'الرسوم %', 'الحد الأدنى', 'الحد اليومي',
    'API Key', 'Secret Key', 'Webhook URL', 'البيئة',
    'الميزات', 'أنواع البطاقات'
]);

foreach ($gateways as $gw) {
    $config  = json_decode($gw['config']      ?? '{}', true) ?: [];
    $creds   = json_decode($gw['credentials'] ?? '{}', true) ?: [];
    $settings= json_decode($gw['settings']    ?? '{}', true) ?: [];

    $fees    = $config['fees']   ?? [];
    $limits  = $config['limits'] ?? [];

    // إخفاء المفاتيح الحساسة جزئياً
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
        $gw['status'] === 'active' ? 'نشط ✓' : 'غير نشط',
        $config['region'] ?? '-',
        ($fees['percentage'] ?? 0) . '%',
        ($limits['min'] ?? 0) . ' USD',
        ($limits['max_daily'] ?? 0) . ' USD',
        $apiKey ?: '(فارغ)',
        $secretKey ?: '(فارغ)',
        $settings['webhook_url'] ?? '-',
        $settings['environment'] ?? $config['environment'] ?? 'sandbox',
        implode(' | ', $config['features']   ?? []),
        implode(' | ', $config['card_types'] ?? []),
    ]);
}

fclose($out);
