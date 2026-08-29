<?php
define('DI_PARMA_CHECKOUT', true);
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
$csrfToken  = generateCsrfToken();
$gwCode     = 'paypal';
$gwName     = 'PayPal';
$gwColor    = '#0070ba';
$gwIcon     = 'fab fa-paypal';
$currencies = ['USD','EUR','GBP','AUD','CAD'];
require __DIR__ . '/checkout_template.php';
