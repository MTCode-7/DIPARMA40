<?php
/**
 * DI PARMA | صفحة بوابة مستقلة: jpmorgan
 * تحتوي على جميع عمليات الشراء المعيارية.
 */
$gwCode = 'jpmorgan';
$checkoutBase = '../';
$bankInfo = [
    'Beneficiary' => getenv('JPMORGAN_BENEFICIARY') ?: (getenv('JPMORGAN_ACCOUNT_NAME') ?: ''),
    'Account' => getenv('JPMORGAN_ACCOUNT') ?: (getenv('JPMORGAN_ACCOUNT_NUMBER') ?: ''),
    'Routing' => getenv('JPMORGAN_ROUTING') ?: '',
    'SWIFT' => getenv('JPMORGAN_SWIFT') ?: 'CHASUS33',
    'Bank' => getenv('JPMORGAN_BANK_NAME') ?: (getenv('JPMORGAN_BANK') ?: 'JP Morgan Chase Bank N.A.'),
];
require __DIR__ . '/../includes/start_gateway_checkout.php';
