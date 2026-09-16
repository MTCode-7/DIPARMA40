<?php
/**
 * Nuvei Back + Failure URL. Must return HTTP 200 for Control Panel checks.
 */
require_once __DIR__ . '/includes/nuvei_http_ok.php';

$status = strtoupper((string)($_GET['ppp_status'] ?? $_POST['ppp_status'] ?? $_GET['Status'] ?? $_POST['Status'] ?? ''));
$reason = trim((string)($_GET['reason'] ?? $_POST['reason'] ?? $_GET['gwErrorReason'] ?? $_POST['gwErrorReason'] ?? ''));
$error = (string)($_GET['error'] ?? '');
$txnId = trim((string)($_GET['TransactionID'] ?? $_POST['TransactionID'] ?? $_GET['PPP_TransactionID'] ?? ''));
$failed = $error === 'payment_failed' || in_array($status, ['FAILED', 'DECLINED', 'ERROR', 'FAIL'], true);

if ($failed) {
    nuvei_ok_page(
        'Payment declined',
        $reason !== '' ? $reason : 'رُفض الدفع من Nuvei',
        $reason !== '' ? $reason : 'Nuvei declined the payment.',
        $txnId
    );
    exit;
}

nuvei_ok_page(
    'Checkout',
    'صفحة الرجوع من Nuvei جاهزة',
    'Nuvei back URL is reachable.',
    $txnId
);
