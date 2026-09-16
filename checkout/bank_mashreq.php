<?php
/**
 * DI PARMA | صفحة بوابة مستقلة: mashreq
 * تحتوي على جميع عمليات الشراء المعيارية.
 */
$gwCode = 'mashreq';
$checkoutBase = '../';
$bankInfo = [
    'Beneficiary' => 'TRANSCENDIO FZ-LLC',
    'IBAN' => 'AE300330000019101562722',
    'SWIFT' => 'BOMLAEADXXX',
    'Bank' => 'Mashreq Bank PSC',
];
require __DIR__ . '/../includes/start_gateway_checkout.php';
