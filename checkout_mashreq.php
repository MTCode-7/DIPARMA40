<?php
define('DI_PARMA_CHECKOUT', true);
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
$csrfToken  = generateCsrfToken();
$gwCode     = 'mashreq';
$gwName     = 'Mashreq Bank Dubai';
$gwColor    = '#CC0000';
$gwIcon     = 'fas fa-university';
$currencies = ['AED','USD','EUR','GBP'];
$bankInfo   = [
    'Account Name'  => 'TRANSCENDIO FZ-LLC',
    'IBAN'          => 'AE300330000019101562722',
    'SWIFT / BIC'   => 'BOMLAEADXXX',
    'Bank'          => 'Mashreq Bank',
    'CIF'           => '015379207',
    'City'          => 'Dubai, UAE',
    'Currency'      => 'AED',
];
require __DIR__ . '/checkout_template.php';
