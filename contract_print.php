<?php
require_once __DIR__ . '/includes/auth_check.php';

$reference = trim((string)($_GET['ref'] ?? $_GET['reference'] ?? ''));
if ($reference === '') {
    header('Location: transactions.php');
    exit();
}

header('Location: contract_receipt.php?ref=' . urlencode($reference));
exit();
