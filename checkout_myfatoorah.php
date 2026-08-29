<?php
define('DI_PARMA_CHECKOUT', true);
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
$csrfToken    = generateCsrfToken();
$gwCode       = 'myfatoorah';
$gwName       = 'MyFatoorah';
$gwColor      = '#00b09b';
$gwIcon       = 'fas fa-money-bill-wave';
$currencies   = ['KWD','SAR','AED','BHD','QAR','OMR','EGP','USD'];
$hiddenTxTypes = []; // كل الأنواع مفعّلة — MyFatoorah confirmed active
require __DIR__ . '/checkout_template.php';
