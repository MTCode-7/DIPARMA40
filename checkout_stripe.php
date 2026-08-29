<?php
define('DI_PARMA_CHECKOUT', true);
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
$csrfToken     = generateCsrfToken();
$gwCode        = 'stripe';
$gwName        = 'Stripe';
$gwColor       = '#6772e5';
$gwIcon        = 'fab fa-stripe-s';
$currencies    = ['USD','EUR','GBP','AED','SAR'];
$stripeKey     = getenv('STRIPE_PUBLIC_KEY') ?: '';
$hiddenTxTypes = []; // كل الأنواع متاحة
require __DIR__ . '/checkout_template.php';
