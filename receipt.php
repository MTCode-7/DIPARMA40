<?php
/**
 * ============================================================
 * DI PARMA | إيصال دفع حقيقي (Real Payment Receipt)
 * يشبه إيصالات نقاط البيع (POS) البنكية
 * يعرض جميع تفاصيل المعاملة الحقيقية من بوابة الدفع
 * ============================================================
 */

// ============================================================
// 1. استيراد الملفات الأساسية
// ============================================================

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';

// ============================================================
// 2. تحديد اللغة والاتجاه
// ============================================================

$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'ar' ? 'ar' : 'en';
$ar   = ($lang === 'ar');
$dir  = $ar ? 'rtl' : 'ltr';

// ============================================================
// 3. استقبال المرجع والتحقق منه
// ============================================================

$ref = trim($_GET['ref'] ?? '');
if (!$ref) {
    header('Location: dashboard.php');
    exit;
}

// ============================================================
// 4. الاتصال بقاعدة البيانات وجلب المعاملة
// ============================================================

$db = db();
$txn = null;

try {
    // محاولة جلب من جدول dp_transactions
    $rows = $db->query(
        "SELECT * FROM dp_transactions WHERE reference = ? LIMIT 1",
        [$ref]
    );
    $txn = $rows[0] ?? null;
} catch (Exception $e) {}

if (!$txn) {
    // محاولة من جدول transactions القديم
    try {
        $rows = $db->query(
            "SELECT * FROM transactions WHERE reference = ? LIMIT 1",
            [$ref]
        );
        $txn = $rows[0] ?? null;
    } catch (Exception $e) {}
}

// ============================================================
// 5. إذا لم يتم العثور على المعاملة
// ============================================================

if (!$txn) {
    ?>
    <!DOCTYPE html>
    <html lang="<?=$lang?>" dir="<?=$dir?>">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>DI PARMA | الإيصال غير موجود</title>
        <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
        <style>
            *{margin:0;padding:0;box-sizing:border-box}
            body{font-family:'Cairo',sans-serif;background:#f0f0f0;display:flex;justify-content:center;align-items:center;min-height:100vh}
            .not-found{background:#fff;padding:50px;border-radius:16px;text-align:center;max-width:500px;box-shadow:0 4px 20px rgba(0,0,0,0.1)}
            .not-found i{font-size:60px;color:#ccc;margin-bottom:20px}
            .not-found h2{color:#333;margin-bottom:10px}
            .not-found p{color:#999;margin-bottom:20px}
            .btn{display:inline-block;padding:12px 30px;background:#FFD700;color:#000;border-radius:8px;text-decoration:none;font-weight:700}
        </style>
    </head>
    <body>
        <div class="not-found">
            <i class="fas fa-receipt"></i>
            <h2>⚠️ الإيصال غير موجود</h2>
            <p>لم يتم العثور على معاملة بهذا الرقم المرجعي</p>
            <p style="font-size:14px;color:#666;font-weight:600">المرجع: <?=htmlspecialchars($ref)?></p>
            <a href="dashboard.php" class="btn">← العودة إلى لوحة التحكم</a>
        </div>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
    </body>
    </html>
    <?php
    exit;
}

// ============================================================
// 6. استخراج جميع تفاصيل المعاملة الحقيقية
// ============================================================

// 6.1 تفاصيل عامة
$amount = number_format((float)($txn['amount'] ?? 0), 2);
$currency = $txn['currency'] ?? 'USD';
$status = strtoupper($txn['status'] ?? 'UNKNOWN');
$gateway = strtoupper($txn['gateway'] ?? '—');
$createdAt = $txn['created_at'] ?? date('Y-m-d H:i:s');
$dateObj = new DateTime($createdAt);
$dateStr = $dateObj->format('d/m/Y');
$timeStr = $dateObj->format('H:i:s');

// 6.2 استخراج رد البوابة (JSON)
$gwResp = json_decode($txn['gateway_response'] ?? '{}', true) ?? [];

// 6.3 تفاصيل البطاقة (من البوابة)
$cardLast4 = $txn['card_last4'] ?? ($gwResp['card_last4'] ?? ($gwResp['payment_method_details']['card']['last4'] ?? '****'));
$cardBrand = $txn['card_brand'] ?? ($gwResp['card_brand'] ?? ($gwResp['payment_method_details']['card']['brand'] ?? 'Visa'));
$cardType = $txn['card_type'] ?? ($gwResp['card_type'] ?? ($gwResp['payment_method_details']['card']['funding'] ?? 'Credit'));
$cardholderName = $txn['cardholder_name'] ?? ($gwResp['cardholder_name'] ?? ($gwResp['payment_method_details']['card']['holder_name'] ?? '—'));

// 6.4 رموز الموافقة والتحقق
$authCode = $gwResp['approval_code'] ?? $gwResp['auth_code'] ?? $gwResp['authorization_code'] ?? $gwResp['stage_1_card']['auth_code'] ?? '—';
$rrn = $gwResp['rrn'] ?? $gwResp['retrieval_reference_number'] ?? $gwResp['stage_1_card']['rrn'] ?? '—';
$stan = $gwResp['stan'] ?? $gwResp['system_trace_audit_number'] ?? $gwResp['stage_1_card']['stan'] ?? '—';
$transactionId = $gwResp['transaction_id'] ?? $gwResp['nuvei_txn_id'] ?? $gwResp['stage_1_card']['nuvei_txn'] ?? $gwResp['id'] ?? '—';

// 6.5 تفاصيل الأمان
$secMode = $txn['security_mode'] ?? $gwResp['security_mode'] ?? $gwResp['stage_1_card']['sec_mode'] ?? '3D SECURE';
$authType = $txn['authorization_type'] ?? $gwResp['authorization_type'] ?? $gwResp['stage_1_card']['auth_type'] ?? 'STANDARD';

// 6.6 تفاصيل البنك المستحوذ (Acquirer)
$acquirer = $gwResp['acquirer'] ?? ($gwResp['stage_1_card']['acquirer'] ?? 'Mashreq Bank PSC');
$acquirerCountry = $gwResp['acquirer_country'] ?? ($gwResp['stage_1_card']['acquirer_country'] ?? 'AE');
$acquirerId = $gwResp['acquirer_id'] ?? ($gwResp['stage_1_card']['acquirer_id'] ?? '330');

// 6.7 تفاصيل التاجر
$merchantName = 'TRANSCENDIO FZ-LLC';
$merchantId = $gwResp['merchant_id'] ?? $txn['merchant_id'] ?? 'DI0001';
$merchantCity = 'Dubai';
$merchantCountry = 'AE';
$merchantCategory = $gwResp['mcc'] ?? $txn['mcc'] ?? '5999';

// 6.8 تفاصيل الحساب البنكي للإيداع
$iban = $txn['iban'] ?? $gwResp['iban'] ?? 'AE300330000019101562722';
$swift = $txn['swift'] ?? $gwResp['swift'] ?? 'BOMLAEADXXX';
$accountName = $txn['account_name'] ?? $gwResp['account_name'] ?? 'TRANSCENDIO FZ-LLC';

// 6.9 تفاصيل العملات الرقمية (إذا وجدت)
$ledgerAddr = $gwResp['ledger_addr'] ?? $gwResp['stage_2_ledger']['address'] ?? $gwResp['ledger_target'] ?? '—';
$ledgerTxid = $gwResp['ledger_txid'] ?? $gwResp['stage_2_ledger']['txid'] ?? null;
$usdtAmt = number_format((float)($txn['ledger_amount'] ?? $gwResp['stage_2_ledger']['usdt_amount'] ?? 0), 6);

// 6.10 تحديد الحالة النهائية
$isApproved = in_array(strtolower($txn['status'] ?? ''), ['completed', 'captured', 'authorized', 'settled', 'approved']);
$statusColor = $isApproved ? '#16a34a' : '#dc2626';
$statusBg = $isApproved ? '#ecfdf5' : '#fef2f2';
$statusIcon = $isApproved ? '✅' : '❌';
$statusText = $isApproved ? 'APPROVED' : strtoupper($txn['status'] ?? 'DECLINED');

// 6.11 نوع المعاملة
$txnType = strtoupper(str_replace('_', ' ', $txn['transaction_type'] ?? $txn['protocol'] ?? 'PURCHASE'));

// ============================================================
// 7. عرض الإيصال الحقيقي
// ============================================================

?>
<!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$dir?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DI PARMA | إيصال الدفع - <?=htmlspecialchars($ref)?></title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ============================================================
           STYLES - إيصال احترافي يشبه POS
           ============================================================ */
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'JetBrains Mono', 'Cairo', monospace, sans-serif;
            background: #e8e8e8;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 20px;
        }
        
        /* حاوية الإيصال */
        .receipt-wrapper {
            width: 100%;
            max-width: 400px;
        }
        
        /* أزرار الإجراءات */
        .actions {
            display: flex;
            gap: 10px;
            margin-bottom: 16px;
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .actions button,
        .actions a {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-family: 'Cairo', sans-serif;
            font-size: 0.85rem;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .btn-print {
            background: #1a1a2e;
            color: #FFD700;
        }
        .btn-print:hover {
            background: #000;
            transform: scale(1.02);
        }
        
        .btn-share {
            background: #0077b6;
            color: #fff;
        }
        .btn-share:hover {
            background: #005f8a;
            transform: scale(1.02);
        }
        
        .btn-back {
            background: #fff;
            color: #333;
            border: 1px solid #ddd;
        }
        .btn-back:hover {
            background: #f5f5f5;
            transform: scale(1.02);
        }
        
        .btn-download {
            background: #16a34a;
            color: #fff;
        }
        .btn-download:hover {
            background: #15803d;
            transform: scale(1.02);
        }
        
        /* ============================================================
           الإيصال نفسه - تصميم POS حقيقي
           ============================================================ */
        
        .receipt {
            background: #ffffff;
            width: 100%;
            border-radius: 6px;
            box-shadow: 0 4px 30px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            font-family: 'JetBrains Mono', 'Courier New', monospace;
            color: #000;
            font-size: 12px;
            line-height: 1.6;
            position: relative;
        }
        
        /* رأس الإيصال */
        .receipt-header {
            background: #000;
            color: #FFD700;
            text-align: center;
            padding: 20px 20px 14px;
            border-bottom: 3px solid #FFD700;
        }
        
        .receipt-header .logo {
            font-size: 26px;
            font-weight: 900;
            letter-spacing: 4px;
            font-family: 'Cairo', sans-serif;
        }
        
        .receipt-header .subtitle {
            font-size: 10px;
            letter-spacing: 3px;
            color: rgba(255, 215, 0, 0.7);
            margin-top: 2px;
        }
        
        .receipt-header .company {
            font-size: 10px;
            color: rgba(255, 255, 255, 0.7);
            margin-top: 4px;
            letter-spacing: 1px;
        }
        
        .receipt-header .address {
            font-size: 9px;
            color: rgba(255, 255, 255, 0.4);
            margin-top: 2px;
        }
        
        /* خط فاصل */
        .divider {
            text-align: center;
            padding: 4px 0;
            color: #ccc;
            font-size: 11px;
            letter-spacing: 3px;
            border-bottom: 1px dashed #ddd;
        }
        
        /* حالة المعاملة */
        .status-section {
            text-align: center;
            padding: 14px 20px;
            border-bottom: 2px dashed #ddd;
            background: <?=$statusBg?>;
        }
        
        .status-badge {
            display: inline-block;
            padding: 8px 30px;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 900;
            letter-spacing: 2px;
            border: 2px solid <?=$statusColor?>;
            color: <?=$statusColor?>;
            background: #fff;
        }
        
        .status-badge .icon {
            margin-right: 8px;
        }
        
        .status-type {
            font-size: 10px;
            color: #888;
            margin-top: 6px;
            letter-spacing: 1px;
            font-family: 'Cairo', sans-serif;
        }
        
        /* الأقسام */
        .section {
            padding: 12px 18px;
            border-bottom: 1px dashed #e5e5e5;
        }
        
        .section:last-child {
            border-bottom: none;
        }
        
        .section-title {
            font-size: 9px;
            font-weight: 700;
            color: #999;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-bottom: 6px;
            text-align: center;
        }
        
        /* صفوف البيانات */
        .row {
            display: flex;
            justify-content: space-between;
            padding: 2px 0;
            font-size: 11px;
        }
        
        .row .label {
            color: #777;
            font-weight: 400;
        }
        
        .row .value {
            font-weight: 700;
            text-align: right;
            max-width: 65%;
            word-break: break-all;
            font-family: 'JetBrains Mono', monospace;
        }
        
        .row .value.highlight {
            color: <?=$statusColor?>;
        }
        
        .row .value.small {
            font-size: 9.5px;
        }
        
        /* مربع المبلغ */
        .amount-box {
            background: #fafafa;
            border: 2px solid #000;
            border-radius: 6px;
            padding: 14px;
            text-align: center;
            margin: 6px 0;
        }
        
        .amount-box .label {
            font-size: 9px;
            color: #999;
            letter-spacing: 2px;
            text-transform: uppercase;
            font-weight: 600;
        }
        
        .amount-box .value {
            font-size: 32px;
            font-weight: 900;
            color: #000;
            letter-spacing: 1px;
            line-height: 1.2;
        }
        
        .amount-box .currency {
            font-size: 16px;
            font-weight: 700;
            color: #666;
        }
        
        /* تفاصيل البطاقة */
        .card-details {
            background: #f8f9fa;
            border-radius: 4px;
            padding: 10px 14px;
            margin: 4px 0;
            border-left: 4px solid <?=$statusColor?>;
        }
        
        /* تفاصيل البنك */
        .bank-details {
            background: #f0f4ff;
            border-radius: 4px;
            padding: 10px 14px;
            margin: 4px 0;
            border-left: 4px solid #0066cc;
        }
        
        .bank-details .bank-row {
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            padding: 1px 0;
        }
        
        .bank-details .bank-label {
            color: #666;
        }
        
        .bank-details .bank-value {
            font-weight: 600;
            text-align: right;
            word-break: break-all;
        }
        
        /* تفاصيل العملات الرقمية */
        .crypto-details {
            background: #f0fdf4;
            border-radius: 4px;
            padding: 10px 14px;
            margin: 4px 0;
            border-left: 4px solid #16a34a;
        }
        
        .crypto-details .crypto-label {
            font-size: 9px;
            font-weight: 700;
            color: #16a34a;
            letter-spacing: 1px;
            margin-bottom: 4px;
        }
        
        .crypto-details .crypto-addr {
            font-size: 9px;
            word-break: break-all;
            color: #333;
        }
        
        .crypto-details .crypto-txid {
            font-size: 8.5px;
            color: #0066cc;
            word-break: break-all;
            margin-top: 3px;
        }
        
        .crypto-details .crypto-txid a {
            color: #0066cc;
            text-decoration: none;
        }
        .crypto-details .crypto-txid a:hover {
            text-decoration: underline;
        }
        
        /* تذييل الإيصال */
        .receipt-footer {
            background: #1a1a2e;
            color: rgba(255, 255, 255, 0.6);
            text-align: center;
            padding: 16px 20px;
            font-size: 9px;
            line-height: 2;
        }
        
        .receipt-footer strong {
            color: #FFD700;
        }
        
        .receipt-footer .footer-small {
            font-size: 8px;
            opacity: 0.5;
        }
        
        /* شريط القطع (مثل إيصالات POS) */
        .cut-line {
            text-align: center;
            padding: 6px 0;
            color: #ccc;
            font-size: 12px;
            letter-spacing: 5px;
            border-top: 2px dashed #ddd;
            border-bottom: 2px dashed #ddd;
            margin: 0 18px;
        }
        
        /* ============================================================
           طباعة
           ============================================================ */
        
        @media print {
            body {
                background: #fff !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            
            .receipt-wrapper {
                max-width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            
            .receipt {
                box-shadow: none !important;
                border-radius: 0 !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            
            .actions {
                display: none !important;
            }
            
            .no-print {
                display: none !important;
            }
            
            @page {
                margin: 0;
                size: 80mm auto;
            }
            
            .receipt-footer {
                page-break-inside: avoid;
            }
        }
        
        /* ============================================================
           استجابة
           ============================================================ */
        
        @media (max-width: 480px) {
            .receipt {
                border-radius: 0;
            }
            
            .actions {
                gap: 6px;
            }
            
            .actions button,
            .actions a {
                font-size: 0.75rem;
                padding: 8px 14px;
            }
        }
    </style>
</head>
<body>

<div class="receipt-wrapper">

    <!-- ============================================================
         أزرار الإجراءات (لا تظهر في الطباعة)
         ============================================================ -->
    <div class="actions no-print">
        <button class="btn-print" onclick="window.print()">
            <i class="fas fa-print"></i> طباعة
        </button>
        <button class="btn-download" onclick="downloadReceipt()">
            <i class="fas fa-download"></i> تحميل PDF
        </button>
        <button class="btn-share" onclick="shareReceipt()">
            <i class="fas fa-share-alt"></i> مشاركة
        </button>
        <a href="dashboard.php" class="btn-back">
            <i class="fas fa-arrow-<?=$ar?'right':'left'?>"></i> رجوع
        </a>
    </div>

    <!-- ============================================================
         الإيصال - تصميم POS حقيقي
         ============================================================ -->
    <div class="receipt" id="receipt">

        <!-- رأس الإيصال -->
        <div class="receipt-header">
            <div class="logo">DI PARMA</div>
            <div class="subtitle">✦ ULTIMATE GATEWAY ✦</div>
            <div class="company"><?=$merchantName?></div>
            <div class="address">Al Barsha 1, Dubai, UAE | diparmas.com</div>
        </div>

        <!-- حالة المعاملة -->
        <div class="status-section">
            <div class="status-badge">
                <span class="icon"><?=$statusIcon?></span>
                <?=$statusText?>
            </div>
            <div class="status-type">
                <?=$txnType?> &bull; <?=$gateway?> &bull; <?=$secMode?>
            </div>
        </div>

        <!-- التاريخ والمرجع -->
        <div class="section">
            <div class="row">
                <span class="label">DATE / TIME</span>
                <span class="value"><?=$dateStr?> <?=$timeStr?></span>
            </div>
            <div class="row">
                <span class="label">REFERENCE</span>
                <span class="value small"><?=htmlspecialchars($ref)?></span>
            </div>
            <div class="row">
                <span class="label">STAN</span>
                <span class="value small"><?=htmlspecialchars($stan)?></span>
            </div>
            <div class="row">
                <span class="label">RRN</span>
                <span class="value small"><?=htmlspecialchars($rrn)?></span>
            </div>
        </div>

        <!-- المبلغ -->
        <div class="section">
            <div class="amount-box">
                <div class="label">TOTAL AMOUNT</div>
                <div class="value"><?=$amount?> <span class="currency"><?=$currency?></span></div>
            </div>
        </div>

        <!-- تفاصيل البطاقة -->
        <div class="section">
            <div class="divider">— CARD DETAILS —</div>
            <div class="card-details">
                <div class="row">
                    <span class="label">CARD</span>
                    <span class="value"><?=htmlspecialchars($cardBrand)?> <?=htmlspecialchars($cardType)?></span>
                </div>
                <div class="row">
                    <span class="label">CARD NUMBER</span>
                    <span class="value">•••• •••• •••• <?=htmlspecialchars($cardLast4)?></span>
                </div>
                <?php if ($cardholderName && $cardholderName !== '—'): ?>
                <div class="row">
                    <span class="label">CARDHOLDER</span>
                    <span class="value"><?=htmlspecialchars(strtoupper($cardholderName))?></span>
                </div>
                <?php endif; ?>
                <div class="row">
                    <span class="label">AUTH CODE</span>
                    <span class="value highlight"><?=htmlspecialchars($authCode)?></span>
                </div>
                <div class="row">
                    <span class="label">AUTH TYPE</span>
                    <span class="value"><?=htmlspecialchars($authType)?></span>
                </div>
                <?php if ($transactionId && $transactionId !== '—'): ?>
                <div class="row">
                    <span class="label">TXN ID</span>
                    <span class="value small"><?=htmlspecialchars(substr($transactionId, 0, 16))?>…</span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- تفاصيل المستحوذ والتاجر -->
        <div class="section">
            <div class="divider">— ACQUIRER & MERCHANT —</div>
            <div class="row">
                <span class="label">ACQUIRER</span>
                <span class="value"><?=htmlspecialchars($acquirer)?></span>
            </div>
            <div class="row">
                <span class="label">ACQUIRER ID</span>
                <span class="value"><?=htmlspecialchars($acquirerId)?></span>
            </div>
            <div class="row">
                <span class="label">MERCHANT</span>
                <span class="value"><?=htmlspecialchars($merchantName)?></span>
            </div>
            <div class="row">
                <span class="label">MERCHANT ID</span>
                <span class="value"><?=htmlspecialchars($merchantId)?></span>
            </div>
            <div class="row">
                <span class="label">MCC</span>
                <span class="value"><?=htmlspecialchars($merchantCategory)?></span>
            </div>
            <div class="row">
                <span class="label">CITY / COUNTRY</span>
                <span class="value"><?=$merchantCity?>, <?=$merchantCountry?></span>
            </div>
        </div>

        <!-- تفاصيل الحساب البنكي -->
        <div class="section">
            <div class="divider">— SETTLEMENT ACCOUNT —</div>
            <div class="bank-details">
                <div class="bank-row">
                    <span class="bank-label">BANK</span>
                    <span class="bank-value">Mashreq Bank PSC</span>
                </div>
                <div class="bank-row">
                    <span class="bank-label">ACCOUNT NAME</span>
                    <span class="bank-value"><?=$accountName?></span>
                </div>
                <div class="bank-row">
                    <span class="bank-label">IBAN</span>
                    <span class="bank-value" style="font-size:10px;font-family:monospace"><?=$iban?></span>
                </div>
                <div class="bank-row">
                    <span class="bank-label">SWIFT</span>
                    <span class="bank-value"><?=$swift?></span>
                </div>
            </div>
        </div>

        <!-- تفاصيل العملات الرقمية (إذا وجدت) -->
        <?php if ($ledgerAddr && $ledgerAddr !== '—'): ?>
        <div class="section">
            <div class="divider">— CRYPTO LEDGER —</div>
            <div class="crypto-details">
                <div class="crypto-label">⬡ USDT TRC20 DESTINATION</div>
                <div class="crypto-addr"><?=htmlspecialchars($ledgerAddr)?></div>
                <?php if ((float)$usdtAmt > 0): ?>
                <div class="row" style="margin-top:4px">
                    <span class="label">AMOUNT</span>
                    <span class="value"><?=$usdtAmt?> USDT</span>
                </div>
                <?php endif; ?>
                <?php if ($ledgerTxid): ?>
                <div class="crypto-txid">
                    TX: <?=htmlspecialchars($ledgerTxid)?>
                    <br>
                    <a href="https://tronscan.org/#/transaction/<?=urlencode($ledgerTxid)?>" target="_blank">
                        View on TronScan ↗
                    </a>
                </div>
                <?php else: ?>
                <div style="font-size:9px;color:#999;margin-top:4px">⏳ Transfer: Queued</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- شريط القطع (مثل إيصالات POS) -->
        <div class="cut-line">✂ — — — — — — — — — — — — — — — — ✂</div>

        <!-- تذييل الإيصال -->
        <div class="receipt-footer">
            <strong>✓ THANK YOU FOR YOUR BUSINESS ✓</strong><br>
            Powered by DI PARMA Gateway © <?=date('Y')?><br>
            This is an official payment receipt<br>
            <span class="footer-small">Keep for your records — <?=htmlspecialchars($ref)?></span><br>
            <span class="footer-small">Transactions are subject to verification</span>
        </div>

    </div><!-- /receipt -->

    <!-- حقوق النشر -->
    <div style="text-align:center;margin-top:12px;font-size:0.7rem;color:#999;font-family:'Cairo',sans-serif" class="no-print">
        <a href="https://diparmas.com" style="color:#999;text-decoration:none">diparmas.com</a> &bull; 
        <span id="datetime"></span>
    </div>

</div><!-- /receipt-wrapper -->

<!-- ============================================================
     JavaScript
     ============================================================ -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
<script>
    // عرض الوقت الحالي
    document.getElementById('datetime').textContent = new Date().toLocaleString('ar-AE', {
        timeZone: 'Asia/Dubai',
        hour12: false
    });

    // وظيفة المشاركة
    function shareReceipt() {
        const url = window.location.href;
        if (navigator.share) {
            navigator.share({
                title: 'DI PARMA Receipt - <?=htmlspecialchars($ref)?>',
                url: url
            }).catch(() => {});
        } else {
            navigator.clipboard?.writeText(url).then(() => {
                alert('✅ تم نسخ رابط الإيصال');
            }).catch(() => {
                // نسخ يدوي
                const input = document.createElement('input');
                input.value = url;
                document.body.appendChild(input);
                input.select();
                document.execCommand('copy');
                document.body.removeChild(input);
                alert('✅ تم نسخ رابط الإيصال');
            });
        }
    }

    // وظيفة تحميل PDF (طباعة مع حفظ)
    function downloadReceipt() {
        // يمكن استخدام window.print() لحفظ كـ PDF
        window.print();
    }

    // اختصار لوحة المفاتيح: Ctrl+P للطباعة
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
            // السماح بالطباعة العادية
        }
    });
</script>

</body>
</html>
<?php
exit;
?>