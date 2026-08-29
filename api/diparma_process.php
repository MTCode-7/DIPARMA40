<?php
/**
 * ============================================================
 * DI PARMA | Process API — Smart Orchestrator
 * POST /api/diparma_process.php
 * ============================================================
 * يوزّع العمليات تلقائياً على:
 * ─ بوابات: Nuvei, Stripe, PayPal, MyFatoorah, Wise
 * ─ بنوك: Mashreq, HSBC, NBE, JP Morgan
 * ─ 18 POS Terminal
 * ─ Crypto: Binance, Gate.io, Ledger TRX
 * ============================================================
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type,X-Api-Key,X-Timestamp,X-Signature');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'message'=>'POST only']);
    exit;
}

set_exception_handler(function($e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    exit;
});

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../lib/DIPARMAOrchestrator.php';

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Invalid JSON']);
    exit;
}

/* ── action خاصة ── */
$action = $body['action'] ?? 'process';

/* ── قائمة الـ POS ── */
if ($action === 'pos_list') {
    $orch = DIPARMAOrchestrator::getInstance();
    echo json_encode(['success'=>true,'terminals'=>$orch->getPOSList()]);
    exit;
}

if ($action === 'pos_status') {
    $orch = DIPARMAOrchestrator::getInstance();
    echo json_encode($orch->getPOSStatus($body['pos_id'] ?? ''));
    exit;
}

/* ── Validation ── */
$amount   = (float)($body['amount']   ?? 0);
$currency = strtoupper($body['currency'] ?? 'USD');
$txnType  = $body['txn_type'] ?? 'purchase';

$noAmountTypes = ['balance','settlement'];
if (!in_array($txnType, $noAmountTypes) && $amount <= 0) {
    echo json_encode(['success'=>false,'message'=>'amount required']);
    exit;
}

/* ── تنفيذ عبر Orchestrator ── */
$orch   = DIPARMAOrchestrator::getInstance();
$result = $orch->process($body);

echo json_encode($result, JSON_UNESCAPED_UNICODE);
