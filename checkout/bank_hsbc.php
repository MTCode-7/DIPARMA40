<?php
$BANK_CONFIG = [
    'name'             => 'HSBC Bank Middle East — UAE',
    'gateway_code'     => 'hsbc_uae',
    'prefix'           => 'HSBC',
    'icon'             => 'fas fa-university',
    'color'            => '#DB0011',
    'default_currency' => 'AED',
    'currencies'       => ['AED','USD','EUR','GBP'],
    'fields'           => [
        'Beneficiary'  => 'MR RAGEH SAEED ALI BAKRAIT',
        'Account No'   => '013-053368-001',
        'IBAN'         => 'AE850200000013053368001',
        'SWIFT'        => 'BBMEAEAD',
        'Bank'         => 'HSBC Bank Middle East Limited',
        'City'         => 'Abu Dhabi, UAE',
    ],
];
require_once __DIR__ . '/_bank_template.php';
