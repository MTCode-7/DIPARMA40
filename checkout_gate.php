<?php
define('DI_PARMA_CHECKOUT', true);
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
$csrfToken  = generateCsrfToken();
$gwCode     = 'gate_io';
$gwName     = 'Gate.io';
$gwColor    = '#e8112d';
$gwIcon     = 'fas fa-door-open';
$currencies = ['USD','USDT','BTC','ETH','GT'];
require __DIR__ . '/checkout_template.php';
