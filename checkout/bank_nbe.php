<?php
/**
 * DI PARMA | صفحة بوابة مستقلة: nbe_egypt
 * تحتوي على جميع عمليات الشراء المعيارية.
 */
$gwCode = 'nbe_egypt';
$checkoutBase = '../';
$bankInfo = [
    'Beneficiary' => 'TRANSCENDIO FZ-LLC',
    'IBAN' => 'EG170003060131711241527030330',
    'SWIFT' => 'NBEGEGCX601',
    'Bank' => 'National Bank of Egypt',
];
require __DIR__ . '/../includes/start_gateway_checkout.php';
