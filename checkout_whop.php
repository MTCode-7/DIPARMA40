<?php
define('DI_PARMA_CHECKOUT', true);
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
$csrfToken       = generateCsrfToken();
$gwCode          = 'whop';
$gwName          = 'Whop';
$gwColor         = '#4F46E5';
$gwIcon          = 'fas fa-bolt';
$currencies      = ['USD','EUR','GBP'];
$whopCheckoutUrl = getenv('WHOP_CHECKOUT_URL') ?: 'https://whop.com/checkout/plan_A4P3nPnySfV8n';
$hiddenTxTypes   = []; // كل الأنواع متاحة

// Override template go() function للـ Direct → Whop Checkout Link
$extraHeadScript = <<<JS
<script>
// Override: Direct 2D/3D → Whop Checkout Link
window.__whopCheckoutUrl = '{$whopCheckoutUrl}';
window.__whopOverrideGo = function(curTx) {
    if (curTx === 'direct2d' || curTx === 'direct3d') {
        window.open(window.__whopCheckoutUrl, '_blank');
        return true; // intercepted
    }
    return false; // let template handle it
};
</script>
JS;

require __DIR__ . '/checkout_template.php';
