<?php
$BANK_CONFIG = [
    'name'             => 'Mashreq Bank — TRANSCENDIO FZ-LLC',
    'gateway_code'     => 'mashreq',
    'prefix'           => 'MSHQ',
    'icon'             => 'fas fa-university',
    'color'            => '#FF6600',
    'default_currency' => 'AED',
    'currencies'       => ['AED','USD','EUR','GBP'],
    'fields'           => [
        'Beneficiary'  => 'TRANSCENDIO FZ-LLC',
        'Account No'   => '019101562722',
        'IBAN'         => 'AE300330000019101562722',
        'SWIFT'        => 'BOMLAEADXXX',
        'Routing'      => '203320101',
        'Bank'         => 'Mashreq Bank PSC',
        'CIF'          => '015379207',
        'RM'           => 'Johnson Joy — +9715027968066',
        'Address'      => '403 36, Zarouni Business Centre, Al Barsha 1, Dubai, AE',
    ],
];
require_once __DIR__ . '/_bank_template.php';
