<?php
define('DI_PARMA_CHECKOUT', true);
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
$csrfToken  = generateCsrfToken();
$gwCode     = 'nbe_egypt';
$gwName     = 'البنك الأهلي المصري (NBE)';
$gwColor    = '#006633';
$gwIcon     = 'fas fa-landmark';
$currencies = ['EGP','USD','EUR'];
$bankInfo   = [
    'اسم الحساب'    => 'TRANSCENDIO FZ-LLC',
    'IBAN'          => 'EG170003060131711241527030330',
    'SWIFT / BIC'   => 'NBEGEGCX601',
    'البنك'         => 'البنك الأهلي المصري',
    'CIF'           => '015379207',
    'العملة'        => 'EGP',
];
require __DIR__ . '/checkout_template.php';
