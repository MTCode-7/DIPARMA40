<?php
$BANK_CONFIG = [
    'name'             => 'البنك الأهلي المصري (NBE)',
    'gateway_code'     => 'nbe_egypt',
    'prefix'           => 'NBE',
    'icon'             => 'fas fa-landmark',
    'color'            => '#006633',
    'default_currency' => 'EGP',
    'currencies'       => ['EGP','USD','EUR'],
    'fields'           => [
        'المستفيد'  => 'TRANSCENDIO FZ-LLC',
        'IBAN'      => 'EG170003060131711241527030330',
        'SWIFT'     => 'NBEGEGCX601',
        'البنك'     => 'البنك الأهلي المصري',
        'الفرع'     => 'القاهرة الرئيسي',
    ],
];
require_once __DIR__ . '/_bank_template.php';
