<?php
/**
 * DI PARMA | صفحة بوابة مستقلة: Ledger
 * مسار checkout/ledger.php يفتح نفس بوابة Ledger CHECKOUT.
 */
require_once __DIR__ . '/../includes/auth_check.php';
header('Location: ../checkout_ledger.php' . (!empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : ''), true, 302);
exit;
