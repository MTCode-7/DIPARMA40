<?php
define('DI_PARMA_CHECKOUT', true);
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
$csrfToken  = generateCsrfToken();
$gwCode     = 'hsbc_uae';
$gwName     = 'HSBC Bank Middle East UAE';
$gwColor    = '#DB0011';
$gwIcon     = 'fas fa-university';
$currencies = ['AED','USD','EUR','GBP'];
$bankInfo   = [
    'Account Name'  => 'MR RAGEH SAEED ALI BAKRAIT',
    'IBAN'          => 'AE850200000013053368001',
    'Account No'    => '013-053368-001',
    'SWIFT / BIC'   => 'BBME AEAD',
    'Bank'          => 'HSBC Bank Middle East Limited',
    'City'          => 'Abu Dhabi, UAE',
    'Currency'      => 'AED',
];
require __DIR__ . '/checkout_template.php';
