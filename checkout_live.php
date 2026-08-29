<?php
/**
 * DI PARMA | checkout_live.php
 * يحوّل لـ checkout_router.php أو checkout_ledger.php
 */
require_once __DIR__ . '/includes/auth_check.php';

// إذا طُلب Ledger POS صراحةً
if (isset($_GET['gateway']) && $_GET['gateway'] === 'diparma_ledger') {
    header('Location: checkout_ledger.php', true, 302);
    exit;
}

// حفظ أي بارامترات موجودة وتمريرها
$params = http_build_query($_GET);
$url = 'checkout_router.php' . ($params ? '?' . $params : '');
header('Location: ' . $url, true, 301);
exit;
