<?php
define('DI_PARMA_CHECKOUT', true);
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
$csrfToken  = generateCsrfToken();
$gwCode     = 'binance';
$gwName     = 'Binance Pay';
$gwColor    = '#F0B90B';
$gwIcon     = 'fas fa-coins';
$currencies = ['USD','USDT','BTC','ETH','BNB'];
require __DIR__ . '/checkout_template.php';
