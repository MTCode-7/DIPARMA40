<?php
/**
 * DI PARMA | صفحة بوابة مستقلة: hsbc_uae
 * تحتوي على جميع عمليات الشراء المعيارية.
 */
$gwCode = 'hsbc_uae';
$checkoutBase = '../';
$bankInfo = [
    'Beneficiary' => 'MR RAGEH SAEED ALI BAKRAIT',
    'IBAN' => 'AE850200000013053368001',
    'SWIFT' => 'BBMEAEAD',
    'Bank' => 'HSBC Bank Middle East Limited',
];
require __DIR__ . '/../includes/start_gateway_checkout.php';
