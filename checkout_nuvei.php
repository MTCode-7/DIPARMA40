<?php
define('DI_PARMA_CHECKOUT', true);
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
$csrfToken  = generateCsrfToken();
$gwCode     = 'nuvei';
$gwName     = 'Nuvei';
$gwColor    = '#0A5EB0';
$gwIcon     = 'fas fa-credit-card';
$currencies = ['USD','EUR','GBP','AED','SAR'];
require __DIR__ . '/checkout_template.php';
