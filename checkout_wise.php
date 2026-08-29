<?php
define('DI_PARMA_CHECKOUT', true);
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
$csrfToken  = generateCsrfToken();
$gwCode     = 'wise';
$gwName     = 'Wise';
$gwColor    = '#00B9FF';
$gwIcon     = 'fas fa-paper-plane';
$currencies = ['USD','EUR','GBP','AED','SAR','EGP','INR'];
require __DIR__ . '/checkout_template.php';
