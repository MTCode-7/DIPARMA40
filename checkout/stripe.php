<?php
/**
 * ============================================================
 * DI PARMA | checkout/stripe.php
 * Stripe — بوابة دفع مستقلة (بدون ربط ببنك محدد)
 * ============================================================
 * 
 * يدعم 13 نوعاً مختلفاً من عمليات الشراء عبر Stripe
 * 
 * الأنواع المدعومة:
 * 1.  purchase_3d      → شراء 3D Secure
 * 2.  purchase_2d      → شراء 2D / MOTO
 * 3.  purchase_advice  → شراء إرشادي (Advice)
 * 4.  purchase_offline → مبيعات خارج الخط (Offline MOTO)
 * 5.  purchase_online  → مبيعات عبر الإنترنت (Online MOTO)
 * 6.  auth_hold        → تجميد مبلغ (Authorization Hold)
 * 7.  auth_capture     → تأكيد التجميد (Auth Capture)
 * 8.  recurring        → شراء متكرر (اشتراك)
 * 9.  installment      → شراء بالتقسيط
 * 10. crypto_purchase  → شراء عملات رقمية
 * 11. gift_card        → شراء بطاقة هدايا
 * 12. wire_transfer    → تحويل بنكي مباشر
 * 13. quasi_cash       → سحب نقدي شبيه (Quasi Cash)
 * 
 * ============================================================
 * • رصيد + آخر 10 عمليات
 * • 3D / 2D / Hold / Capture / Refund / Void
 * • Stripe Elements (بطاقة آمنة)
 * • تحديد وجهة المبلغ
 * • طريقة السحب: Manual / Physical / NFC
 * ============================================================
 */

require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'en' ? 'en' : 'ar';
$ar   = ($lang === 'ar');
$dir  = $ar ? 'rtl' : 'ltr';
$csrf = generateCsrfToken();
$db   = db();

// ── بارامترات ──────────────────────────────────────────────
$amount      = floatval($_GET['amount'] ?? 0);
$currency    = strtoupper($_GET['currency'] ?? 'USD');
$destination = $_GET['destination'] ?? 'gateway';
$txnTypeInit = $_GET['txn_type'] ?? 'purchase_3d';
$walletAddr  = $_GET['wallet'] ?? '';
$ref         = $_GET['ref'] ?? ('STR-' . strtoupper(bin2hex(random_bytes(6))));
$notes       = htmlspecialchars($_GET['notes'] ?? '');

// ── Stripe Keys ─────────────────────────────────────────────
$stripePK = defined('STRIPE_PUBLIC_KEY')
    ? STRIPE_PUBLIC_KEY
    : (getenv('STRIPE_PUBLIC_KEY') ?: '');
$stripeEnv = getenv('STRIPE_ENVIRONMENT') ?: 'live';
$isTestMode = str_starts_with($stripePK, 'pk_test_');

// ── آخر 10 عمليات Stripe ────────────────────────────────────
$stripeHistory = [];
$stripeStats   = ['total_count' => 0, 'total_amount' => 0];
try {
    $stripeHistory = $db->query(
        "SELECT * FROM dp_transactions WHERE gateway IN ('stripe','stripe_2d','stripe_3d') ORDER BY created_at DESC LIMIT 10"
    );
    $st = $db->query(
        "SELECT COUNT(*) cnt, COALESCE(SUM(amount),0) total FROM dp_transactions WHERE gateway LIKE 'stripe%' AND status='completed'"
    );
    if (!empty($st[0])) {
        $stripeStats['total_count']  = $st[0]['cnt'];
        $stripeStats['total_amount'] = $st[0]['total'];
    }
} catch (Exception $e) {}

// ============================================================
// [3] تعريف 13 نوع عملية
// ============================================================

$txnTypes = [
    // ════════════════════════════════════════════════════════════
    // 1. PURCHASE 2D / MOTO
    // ════════════════════════════════════════════════════════════
    'purchase_2d' => [
        'ar' => 'شراء 2D / MOTO',
        'en' => 'Purchase 2D / MOTO',
        'icon' => 'fa-credit-card',
        'color' => '#3B82F6',
        'security' => '2D',
        'requires_original' => false,
        'category' => 'moto',
        'iso' => '0200',
        'description' => 'شراء عام بدون 3D Secure (MOTO)'
    ],
    
    // ════════════════════════════════════════════════════════════
    // 2. PURCHASE ADVICE
    // ════════════════════════════════════════════════════════════
    'purchase_advice' => [
        'ar' => 'شراء إرشادي (Advice)',
        'en' => 'Purchase Advice',
        'icon' => 'fa-bell',
        'color' => '#F59E0B',
        'security' => '2D',
        'requires_original' => true,
        'category' => 'advice',
        'iso' => '0220',
        'description' => 'معاملة إرشادية بعد موافقة مسبقة من البنك'
    ],
    
    // ════════════════════════════════════════════════════════════
    // 3. PURCHASE OFFLINE
    // ════════════════════════════════════════════════════════════
    'purchase_offline' => [
        'ar' => 'مبيعات خارج الخط (Offline)',
        'en' => 'Offline Sales',
        'icon' => 'fa-phone',
        'color' => '#8B5CF6',
        'security' => '2D',
        'requires_original' => false,
        'category' => 'offline',
        'iso' => '0200',
        'description' => 'مبيعات عبر الهاتف/البريد/فاكس - MOTO'
    ],
    
    // ════════════════════════════════════════════════════════════
    // 4. PURCHASE ONLINE
    // ════════════════════════════════════════════════════════════
    'purchase_online' => [
        'ar' => 'مبيعات عبر الإنترنت (Online)',
        'en' => 'Online Sales',
        'icon' => 'fa-globe',
        'color' => '#06B6D4',
        'security' => '2D',
        'requires_original' => false,
        'category' => 'online',
        'iso' => '0200',
        'description' => 'مبيعات عبر الإنترنت مع تصنيف MOTO'
    ],
    
    // ════════════════════════════════════════════════════════════
    // 5. PURCHASE 3D SECURE
    // ════════════════════════════════════════════════════════════
    'purchase_3d' => [
        'ar' => 'شراء 3D Secure',
        'en' => 'Purchase 3D Secure',
        'icon' => 'fa-shield-alt',
        'color' => '#10B981',
        'security' => '3D',
        'requires_original' => false,
        'category' => 'secure',
        'iso' => '0200',
        'description' => 'شراء مع تحقق 3D Secure من البنك المصدر'
    ],
    
    // ════════════════════════════════════════════════════════════
    // 6. AUTH HOLD
    // ════════════════════════════════════════════════════════════
    'auth_hold' => [
        'ar' => 'تجميد مبلغ (Hold)',
        'en' => 'Authorization Hold',
        'icon' => 'fa-lock',
        'color' => '#6366F1',
        'security' => '3D',
        'requires_original' => false,
        'category' => 'auth',
        'iso' => '0100',
        'description' => 'تجميد المبلغ مؤقتاً لحين تأكيد العملية'
    ],
    
    // ════════════════════════════════════════════════════════════
    // 7. AUTH CAPTURE
    // ════════════════════════════════════════════════════════════
    'auth_capture' => [
        'ar' => 'تأكيد التجميد (Capture)',
        'en' => 'Auth Capture',
        'icon' => 'fa-check-double',
        'color' => '#8B5CF6',
        'security' => '3D',
        'requires_original' => true,
        'category' => 'auth',
        'iso' => '0200',
        'description' => 'تأكيد التجميد وتحويله إلى شراء كامل'
    ],
    
    // ════════════════════════════════════════════════════════════
    // 8. RECURRING
    // ════════════════════════════════════════════════════════════
    'recurring' => [
        'ar' => 'شراء متكرر (اشتراك)',
        'en' => 'Recurring',
        'icon' => 'fa-repeat',
        'color' => '#14B8A6',
        'security' => '3D',
        'requires_original' => false,
        'category' => 'recurring',
        'iso' => '0200',
        'description' => 'دفع متكرر شهري/سنوي للاشتراكات'
    ],
    
    // ════════════════════════════════════════════════════════════
    // 9. INSTALLMENT
    // ════════════════════════════════════════════════════════════
    'installment' => [
        'ar' => 'شراء بالتقسيط',
        'en' => 'Installment',
        'icon' => 'fa-calculator',
        'color' => '#F97316',
        'security' => '3D',
        'requires_original' => false,
        'category' => 'installment',
        'iso' => '0200',
        'description' => 'شراء وتقسيم المبلغ على عدة دفعات'
    ],
    
    // ════════════════════════════════════════════════════════════
    // 10. CRYPTO PURCHASE
    // ════════════════════════════════════════════════════════════
    'crypto_purchase' => [
        'ar' => 'شراء عملات رقمية',
        'en' => 'Crypto Purchase',
        'icon' => 'fab fa-bitcoin',
        'color' => '#F7931A',
        'security' => '2D',
        'requires_original' => false,
        'category' => 'crypto',
        'iso' => '0200',
        'description' => 'شراء USDT/BTC/ETH باستخدام البطاقة'
    ],
    
    // ════════════════════════════════════════════════════════════
    // 11. GIFT CARD
    // ════════════════════════════════════════════════════════════
    'gift_card' => [
        'ar' => 'بطاقة هدايا',
        'en' => 'Gift Card',
        'icon' => 'fa-gift',
        'color' => '#EC4899',
        'security' => '2D',
        'requires_original' => false,
        'category' => 'gift',
        'iso' => '0200',
        'description' => 'شراء بطاقة هدايا رقمية'
    ],
    
    // ════════════════════════════════════════════════════════════
    // 12. WIRE TRANSFER
    // ════════════════════════════════════════════════════════════
    'wire_transfer' => [
        'ar' => 'تحويل بنكي مباشر',
        'en' => 'Wire Transfer',
        'icon' => 'fa-university',
        'color' => '#1E40AF',
        'security' => '2D',
        'requires_original' => false,
        'category' => 'bank',
        'iso' => '0200',
        'description' => 'تحويل مبلغ من البطاقة إلى حساب بنكي'
    ],
    
    // ════════════════════════════════════════════════════════════
    // 13. QUASI CASH
    // ════════════════════════════════════════════════════════════
    'quasi_cash' => [
        'ar' => 'سحب نقدي شبيه',
        'en' => 'Quasi Cash',
        'icon' => 'fa-coins',
        'color' => '#FFD700',
        'security' => '3D',
        'requires_original' => false,
        'category' => 'cash',
        'iso' => '0200',
        'description' => 'سحب نقدي عبر البطاقة (كازينوهات/مراهنات)'
    ],
];

// ── وجهات المبلغ ──────────────────────────────────────────
$destinations = [
    'gateway'    => ['icon'=>'fab fa-stripe-s',    'color'=>'#6772e5','ar'=>'رصيد Stripe',           'en'=>'Stripe Balance'],
    'mashreq'    => ['icon'=>'fas fa-university',  'color'=>'#FF6600','ar'=>'Mashreq Bank (TRANSCENDIO)','en'=>'Mashreq Bank'],
    'hsbc'       => ['icon'=>'fas fa-university',  'color'=>'#DB0011','ar'=>'HSBC UAE',               'en'=>'HSBC UAE'],
    'nbe'        => ['icon'=>'fas fa-landmark',    'color'=>'#006633','ar'=>'NBE Egypt',              'en'=>'NBE Egypt'],
    'jpmorgan'   => ['icon'=>'fas fa-landmark',    'color'=>'#003087','ar'=>'JP Morgan IOLTA',        'en'=>'JP Morgan IOLTA'],
    'ledger_trx' => ['icon'=>'fas fa-wallet',      'color'=>'#10B981','ar'=>'Ledger TRX (USDT)',      'en'=>'Ledger TRX (USDT)'],
    'tron_w'     => ['icon'=>'fas fa-wallet',      'color'=>'#EF4444','ar'=>'محفظة TRC20',            'en'=>'Custom TRC20'],
    'erc20_w'    => ['icon'=>'fas fa-wallet',      'color'=>'#3B82F6','ar'=>'محفظة ERC20',            'en'=>'Custom ERC20'],
];

// ── الحصول على بيانات النوع المحدد ──────────────────────────
$txnDef = $txnTypes[$txnTypeInit] ?? $txnTypes['purchase_3d'];
?>
<!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$dir?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | Stripe Checkout</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&family=Share+Tech+Mono&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<?php if (!empty($stripePK)): ?>
<script src="https://js.stripe.com/v3/"></script>
<?php endif; ?>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--gold:#FFD700;--bg:#030609;--card:#090f1e;--card2:#0b1224;--border:rgba(255,215,0,.12);--border2:rgba(255,215,0,.28);--text:#edf0f7;--muted:#4a5568;--muted2:#718096;--green:#10B981;--red:#EF4444;--stripe:#6772e5;--stripe2:#5469d4}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.topbar{background:rgba(3,6,9,.97);border-bottom:1px solid var(--border);height:62px;display:flex;align-items:center;justify-content:space-between;padding:0 28px;position:sticky;top:0;z-index:100}
.tb-brand{color:var(--gold);font-weight:900;font-size:1.05rem;display:flex;align-items:center;gap:10px}
.stripe-badge{background:rgba(103,114,229,.12);border:1.5px solid rgba(103,114,229,.3);border-radius:10px;padding:5px 14px;font-size:.78rem;font-weight:800;color:var(--stripe);display:flex;align-items:center;gap:7px}
.test-banner{background:rgba(251,191,36,.08);border-bottom:1px solid rgba(251,191,36,.2);padding:8px 28px;font-size:.75rem;color:#FBBF24;text-align:center;display:flex;align-items:center;justify-content:center;gap:8px}
.layout{max-width:1200px;margin:0 auto;padding:28px 24px;display:grid;grid-template-columns:1fr 360px;gap:24px}
.card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:22px;margin-bottom:18px}
.card-title{font-size:.88rem;font-weight:800;color:var(--stripe);display:flex;align-items:center;gap:8px;margin-bottom:18px}

/* 13 Type Grid - 5 أعمدة */
.txn-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin-bottom:20px}
.txn-btn{padding:10px 6px;border-radius:12px;border:1.5px solid var(--border);background:rgba(255,255,255,.03);cursor:pointer;text-align:center;transition:.2s}
.txn-btn:hover{border-color:rgba(103,114,229,.3)}
.txn-btn.active{border-color:var(--stripe);background:rgba(103,114,229,.07)}
.txn-btn-icon{font-size:1rem;margin-bottom:4px;display:block}
.txn-btn-name{font-size:.58rem;font-weight:800;color:var(--muted2);line-height:1.2}
.txn-btn.active .txn-btn-name{color:var(--stripe)}

/* Extra fields */
.extra-fields{display:none;margin-top:8px}
.extra-fields.show{display:block}

/* Stats */
.stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:4px}
.stat-c{background:var(--card2);border:1px solid var(--border);border-radius:12px;padding:12px;text-align:center}
.stat-v{font-size:1.1rem;font-weight:900;color:var(--stripe)}
.stat-l{font-size:.65rem;color:var(--muted2);margin-top:2px}

/* Stripe Element */
.stripe-element-wrap{background:rgba(103,114,229,.04);border:1.5px solid rgba(103,114,229,.2);border-radius:13px;padding:16px;margin-bottom:16px}
#stripe-card-element{padding:4px 0;min-height:24px}
#stripe-error{color:var(--red);font-size:.78rem;margin-top:8px;min-height:20px}

/* Manual card */
.fld{margin-bottom:14px}
.fld label{display:block;font-size:.72rem;color:var(--muted2);margin-bottom:5px;font-weight:700}
.fld input,.fld select{width:100%;background:rgba(255,255,255,.04);border:1.5px solid var(--border);border-radius:11px;padding:11px 14px;color:var(--text);font-family:'Cairo',sans-serif;font-size:.88rem;transition:.2s}
.fld input:focus,.fld select:focus{outline:none;border-color:var(--stripe)}
.fld-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}

/* Mode tabs */
.mode-tabs{display:flex;gap:10px;margin-bottom:16px}
.mode-tab{flex:1;padding:9px;border-radius:11px;border:1.5px solid var(--border);background:rgba(255,255,255,.03);cursor:pointer;text-align:center;font-size:.78rem;font-weight:700;color:var(--muted2);transition:.2s}
.mode-tab.active{border-color:var(--stripe);background:rgba(103,114,229,.06);color:var(--stripe)}

/* Destination */
.dest-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:16px}
.dest-opt{background:var(--card2);border:1.5px solid var(--border);border-radius:12px;padding:10px;cursor:pointer;text-align:center;transition:.2s}
.dest-opt:hover{border-color:rgba(255,255,255,.15)}
.dest-opt.active{border-color:var(--stripe);background:rgba(103,114,229,.05)}
.dest-opt-icon{font-size:1.1rem;margin-bottom:4px;display:block}
.dest-opt-name{font-size:.62rem;font-weight:700;color:var(--muted2);line-height:1.3}
.dest-opt.active .dest-opt-name{color:var(--stripe)}

/* Wallet input */
.wallet-input{display:none;margin-top:10px}
.wallet-input.show{display:block}

/* Method */
.method-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px}
.method-card{background:var(--card2);border:1.5px solid var(--border);border-radius:14px;padding:14px;cursor:pointer;text-align:center;transition:.2s}
.method-card:hover{border-color:rgba(255,255,255,.15);transform:translateY(-1px)}
.method-card.active{border-color:var(--stripe);background:rgba(103,114,229,.05)}
.method-icon{font-size:1.5rem;margin-bottom:6px;display:block}
.method-name{font-size:.75rem;font-weight:800;color:var(--muted2)}
.method-card.active .method-name{color:var(--stripe)}
.method-desc{font-size:.62rem;color:var(--muted);margin-top:3px}

/* NFC */
.nfc-bar{background:rgba(59,130,246,.06);border:1px solid rgba(59,130,246,.15);border-radius:10px;padding:10px 14px;display:none;align-items:center;gap:10px;font-size:.78rem;color:var(--muted2);margin-top:10px}
.nfc-bar.show{display:flex}
.nfc-pulse{width:10px;height:10px;border-radius:50%;background:#3B82F6;animation:pulse 1.5s infinite;flex-shrink:0}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}

/* Pay btn */
.pay-btn{width:100%;padding:15px;border-radius:14px;border:none;cursor:pointer;font-family:'Cairo',sans-serif;font-size:1rem;font-weight:900;background:linear-gradient(135deg,var(--stripe),var(--stripe2));color:#fff;box-shadow:0 8px 24px rgba(103,114,229,.25);transition:.3s;display:flex;align-items:center;justify-content:center;gap:10px}
.pay-btn:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 12px 32px rgba(103,114,229,.35)}
.pay-btn:disabled{opacity:.4;cursor:not-allowed;transform:none}

/* Summary */
.sum-row{display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:.82rem}
.sum-row:last-child{border:none;font-weight:900;font-size:.95rem;color:var(--stripe);margin-top:6px}
.sum-key{color:var(--muted2)}

/* History */
.hist-table{width:100%;border-collapse:collapse;font-size:.75rem}
.hist-table th{padding:8px 10px;color:var(--muted);font-weight:700;text-align:<?=$ar?'right':'left'?>;border-bottom:1px solid var(--border);background:rgba(103,114,229,.02)}
.hist-table td{padding:8px 10px;border-bottom:1px solid rgba(255,255,255,.03)}
.hist-table tr:hover td{background:rgba(103,114,229,.02)}
.sp{padding:2px 8px;border-radius:7px;font-size:.62rem;font-weight:700}
.sp-completed{background:rgba(16,185,129,.1);color:var(--green)}
.sp-pending{background:rgba(251,191,36,.1);color:#FBBF24}
.sp-failed{background:rgba(239,68,68,.1);color:var(--red)}

/* Result */
.result-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:500;align-items:center;justify-content:center;backdrop-filter:blur(6px)}
.result-overlay.show{display:flex}
.result-box{background:var(--card2);border:1px solid var(--border2);border-radius:20px;padding:32px;max-width:460px;width:90%;text-align:center}
.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border-radius:11px;border:none;font-family:'Cairo',sans-serif;font-size:.84rem;font-weight:700;cursor:pointer;transition:.25s;text-decoration:none}
.btn-stripe{background:linear-gradient(135deg,var(--stripe),var(--stripe2));color:#fff}
.btn-dark{background:rgba(255,255,255,.06);color:var(--text);border:1.5px solid var(--border)}
.spin{display:inline-block;width:16px;height:16px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
#toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(100px);background:var(--card);border:1px solid var(--border2);border-radius:14px;padding:12px 28px;font-size:.84rem;font-weight:700;z-index:9999;transition:.35s;color:var(--text)}
@media(max-width:900px){.layout{grid-template-columns:1fr}}
@media(max-width:600px){.txn-grid{grid-template-columns:repeat(3,1fr)}.dest-grid{grid-template-columns:repeat(4,1fr)}.fld-row{grid-template-columns:1fr}}
</style>
</head>
<body>

<header class="topbar">
  <div class="tb-brand">
    <i class="fas fa-coins"></i> DI PARMA
    <span style="color:var(--muted)">|</span>
    <div class="stripe-badge">
      <i class="fab fa-stripe-s"></i> Stripe
      <span style="background:rgba(16,185,129,.2);border-radius:6px;padding:1px 7px;font-size:.65rem;color:var(--green)"><?=$isTestMode?'TEST':'LIVE'?></span>
    </div>
  </div>
  <div style="display:flex;align-items:center;gap:10px">
    <span style="font-family:'Share Tech Mono',monospace;font-size:.7rem;color:var(--muted2)"><?=htmlspecialchars($ref)?></span>
    <a href="../checkout_router.php" style="color:var(--muted2);font-size:.78rem;text-decoration:none;padding:6px 12px;border-radius:10px">
      <i class="fas fa-arrow-<?=$ar?'right':'left'?>"></i> <?=$ar?'رجوع':'Back'?>
    </a>
  </div>
</header>

<?php if($isTestMode): ?>
<div class="test-banner">
  <i class="fas fa-flask"></i>
  <?=$ar?'وضع الاختبار — استخدم بطاقة 4242 4242 4242 4242':'Test Mode — Use card 4242 4242 4242 4242'?>
</div>
<?php endif; ?>

<div class="layout">
<div>

  <!-- ── Stats ── -->
  <div class="card">
    <div class="card-title"><i class="fab fa-stripe-s"></i> Stripe</div>
    <div class="stats-row">
      <div class="stat-c">
        <div class="stat-v"><?=number_format($stripeStats['total_count'])?></div>
        <div class="stat-l"><?=$ar?'العمليات':'TXNs'?></div>
      </div>
      <div class="stat-c">
        <div class="stat-v" style="font-size:.9rem">$<?=number_format($stripeStats['total_amount'],0)?></div>
        <div class="stat-l"><?=$ar?'إجمالي':'Total'?></div>
      </div>
      <div class="stat-c">
        <div class="stat-v" style="font-size:.75rem;color:<?=$isTestMode?'#FBBF24':'var(--green)'?>"><?=$isTestMode?'● Test':'● Live'?></div>
        <div class="stat-l">Mode</div>
      </div>
    </div>
    <div style="margin-top:10px;font-size:.7rem;color:var(--muted2)">
      <?=$ar?'بوابة مستقلة — غير مرتبطة ببنك محدد':'Independent gateway — not linked to a specific bank'?>
    </div>
  </div>

  <!-- ── 13 Transaction Types ── -->
  <div class="card">
    <div class="card-title"><i class="fas fa-list"></i> <?=$ar?'نوع العملية (13)':'Transaction Types (13)'?></div>
    <div class="txn-grid" id="txnGrid">
      <?php foreach($txnTypes as $code => $txn): ?>
      <div class="txn-btn <?=$code === $txnTypeInit ? 'active' : ''?>" 
           onclick="selectTxnType('<?=$code?>', this)"
           data-type="<?=$code?>"
           data-orig="<?=$txn['requires_original'] ? '1' : '0'?>">
        <span class="txn-btn-icon" style="color:<?=$txn['color']?>">
          <i class="fas <?=$txn['icon']?>"></i>
        </span>
        <div class="txn-btn-name"><?=$ar ? $txn['ar'] : $txn['en']?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Extra: PI for capture/refund/void -->
    <div class="extra-fields <?=$txnDef['requires_original'] ? 'show' : ''?>" id="extraPI">
      <div class="fld">
        <label><i class="fas fa-hashtag"></i> <?=$ar?'المرجع الأصلي (RRN/Approval)':'Original Reference (RRN/Approval)'?></label>
        <input type="text" id="origRef" placeholder="<?=$ar?'رقم العملية السابقة':'Previous transaction reference'?>">
      </div>
    </div>
  </div>

  <!-- ── Card Input Mode ── -->
  <div class="card">
    <div class="card-title"><i class="fas fa-credit-card"></i> <?=$ar?'بيانات البطاقة':'Card Details'?></div>

    <!-- Mode: 3D vs 2D -->
    <div class="mode-tabs">
      <div class="mode-tab active" id="mode3D" onclick="setMode('3D',this)">
        <i class="fas fa-shield-alt"></i> 3D Secure
      </div>
      <div class="mode-tab" id="mode2D" onclick="setMode('2D',this)">
        <i class="fas fa-credit-card"></i> 2D / MOTO
      </div>
    </div>

    <!-- 3D: Stripe Elements -->
    <div id="stripeElementSection">
      <?php if(!empty($stripePK)): ?>
      <div class="stripe-element-wrap">
        <div id="stripe-card-element"></div>
        <div id="stripe-error"></div>
      </div>
      <div class="fld">
        <label><?=$ar?'الاسم على البطاقة':'Name on card'?></label>
        <input type="text" id="stripeCardName" placeholder="<?=$ar?'الاسم الكامل':'Full name'?>">
      </div>
      <?php else: ?>
      <div style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);border-radius:10px;padding:14px;font-size:.78rem;color:var(--red)">
        <i class="fas fa-exclamation-circle"></i>
        <?=$ar?'Stripe Public Key غير موجود — تحقق من .env':'Stripe Public Key missing — check .env'?>
      </div>
      <?php endif; ?>
    </div>

    <!-- 2D / MOTO: Manual Fields -->
    <div id="manualCardSection" style="display:none">
      <div class="fld">
        <label><?=$ar?'اسم حامل البطاقة':'Cardholder Name'?></label>
        <input type="text" id="manualName" placeholder="<?=$ar?'الاسم كما على البطاقة':'Name as on card'?>">
      </div>
      <div class="fld">
        <label><?=$ar?'رقم البطاقة':'Card Number'?></label>
        <input type="text" id="manualNum" maxlength="19" placeholder="0000 0000 0000 0000"
          oninput="let v=this.value.replace(/\D/g,'').substring(0,16);this.value=v.replace(/(.{4})/g,'$1 ').trim()">
      </div>
      <div class="fld-row">
        <div class="fld">
          <label><?=$ar?'تاريخ الانتهاء':'Expiry'?></label>
          <input type="text" id="manualExp" maxlength="5" placeholder="MM/YY"
            oninput="let v=this.value.replace(/\D/g,'');if(v.length>=2)v=v.substring(0,2)+'/'+v.substring(2,4);this.value=v">
        </div>
        <div class="fld">
          <label>CVV</label>
          <input type="password" id="manualCVV" maxlength="4" placeholder="•••">
        </div>
      </div>
      <div style="background:rgba(251,191,36,.06);border:1px solid rgba(251,191,36,.15);border-radius:10px;padding:10px;font-size:.72rem;color:#FBBF24;display:flex;align-items:center;gap:8px">
        <i class="fas fa-info-circle"></i>
        <?=$ar?'MOTO — بدون OTP/3DS — يُرسَل كـ Mail Order':'MOTO — No OTP/3DS — sent as Mail Order Transaction'?>
      </div>
    </div>

    <!-- Amount & Currency -->
    <div class="fld-row" style="margin-top:16px">
      <div class="fld">
        <label><?=$ar?'المبلغ':'Amount'?></label>
        <input type="number" id="txnAmount" min="0.01" step="0.01"
          value="<?=$amount>0?$amount:''?>" placeholder="0.00" oninput="updateSummary()">
      </div>
      <div class="fld">
        <label><?=$ar?'العملة':'Currency'?></label>
        <select id="txnCurrency" onchange="updateSummary()">
          <?php foreach(['USD','AED','EUR','GBP','SAR'] as $c): ?>
          <option value="<?=$c?>" <?=$c===$currency?'selected':''?>><?=$c?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="fld">
      <label><?=$ar?'البريد الإلكتروني':'Email'?></label>
      <input type="email" id="txnEmail" placeholder="email@example.com">
    </div>
  </div>

  <!-- ── Destination ── -->
  <div class="card">
    <div class="card-title"><i class="fas fa-map-marker-alt"></i> <?=$ar?'وجهة المبلغ':'Destination'?></div>
    <div class="dest-grid">
      <?php foreach($destinations as $code => $dest): ?>
      <div class="dest-opt <?=$code===$destination?'active':''?>"
        onclick="selectDest('<?=$code?>',this)" id="sdest-<?=$code?>">
        <span class="dest-opt-icon" style="color:<?=$dest['color']?>"><i class="<?=$dest['icon']?>"></i></span>
        <div class="dest-opt-name"><?=$ar?$dest['ar']:$dest['en']?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="wallet-input" id="walletInputWrap">
      <div class="fld">
        <label id="walletInputLabel"><i class="fas fa-wallet"></i> <?=$ar?'عنوان المحفظة':'Wallet Address'?></label>
        <input type="text" id="walletInputAddr" placeholder="T... or 0x..."
          value="<?=htmlspecialchars($walletAddr)?>">
      </div>
    </div>
  </div>

  <!-- ── Withdrawal Method ── -->
  <div class="card">
    <div class="card-title"><i class="fas fa-hand-holding-usd"></i> <?=$ar?'طريقة السحب':'Withdrawal Method'?></div>
    <div class="method-grid">
      <div class="method-card active" onclick="selectMethod('manual',this)" id="mth-manual">
        <span class="method-icon">✍️</span>
        <div class="method-name">Manual</div>
        <div class="method-desc"><?=$ar?'إدخال يدوي':'Manual entry'?></div>
      </div>
      <div class="method-card" onclick="selectMethod('physical',this)" id="mth-physical">
        <span class="method-icon">📟</span>
        <div class="method-name">Physical</div>
        <div class="method-desc">Bitel IC3600</div>
      </div>
      <div class="method-card" onclick="selectMethod('nfc',this)" id="mth-nfc">
        <span class="method-icon">📡</span>
        <div class="method-name">NFC</div>
        <div class="method-desc"><?=$ar?'لمسة بدون تلامس':'Contactless'?></div>
      </div>
    </div>
    <div class="nfc-bar" id="nfcBar">
      <div class="nfc-pulse"></div>
      <span><?=$ar?'NFC نشط — قرّب البطاقة':'NFC active — tap card'?></span>
    </div>
  </div>

  <!-- ── Pay Button ── -->
  <button class="pay-btn" id="payBtn" onclick="processStripe()" style="margin-bottom:24px">
    <i class="fab fa-stripe-s"></i>
    <span><?=$ar?'ادفع عبر Stripe':'Pay with Stripe'?></span>
    <span id="payBtnAmt" style="opacity:.8"><?=$amount>0?'('.number_format($amount,2).' '.$currency.')':''?></span>
  </button>

  <!-- ── History ── -->
  <div class="card">
    <div class="card-title"><i class="fas fa-history"></i> <?=$ar?'آخر 10 عمليات Stripe':'Last 10 Stripe Transactions'?></div>
    <?php if(!empty($stripeHistory)): ?>
    <div style="overflow-x:auto">
      <table class="hist-table">
        <thead><tr>
          <th><?=$ar?'المرجع':'Ref'?></th>
          <th><?=$ar?'النوع':'Type'?></th>
          <th><?=$ar?'المبلغ':'Amount'?></th>
          <th><?=$ar?'الحالة':'Status'?></th>
          <th><?=$ar?'التاريخ':'Date'?></th>
        </tr></thead>
        <tbody>
          <?php foreach($stripeHistory as $t): ?>
          <tr>
            <td style="font-family:'Share Tech Mono',monospace;font-size:.66rem"><?=htmlspecialchars(substr($t['reference']??'—',0,16))?></td>
            <td style="font-size:.7rem"><?=htmlspecialchars($t['protocol']??$t['transaction_type']??'—')?></td>
            <td style="font-weight:700"><?=number_format(floatval($t['amount']??0),2)?> <?=htmlspecialchars($t['currency']??'')?></td>
            <td><span class="sp sp-<?=htmlspecialchars($t['status']??'pending')?>"><?=htmlspecialchars($t['status']??'—')?></span></td>
            <td style="color:var(--muted2);font-size:.68rem"><?=date('d/m/y H:i',strtotime($t['created_at']??'now'))?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div style="text-align:center;padding:24px;color:var(--muted2);font-size:.8rem">
      <i class="fas fa-inbox" style="font-size:1.8rem;display:block;margin-bottom:8px;opacity:.3"></i>
      <?=$ar?'لا توجد عمليات سابقة':'No previous transactions'?>
    </div>
    <?php endif; ?>
  </div>

</div><!-- /left -->

<!-- ══ SIDEBAR ══ -->
<div>
  <div class="card" style="position:sticky;top:80px">
    <div class="card-title"><i class="fas fa-receipt"></i> <?=$ar?'ملخص':'Summary'?></div>
    <div class="sum-row"><span class="sum-key"><?=$ar?'البوابة:':'Gateway:'?></span><span style="color:var(--stripe)">Stripe</span></div>
    <div class="sum-row"><span class="sum-key"><?=$ar?'النوع:':'Mode:'?></span><span><?=$isTestMode?'Test':'Live'?></span></div>
    <div class="sum-row" id="sumTxn"><span class="sum-key"><?=$ar?'العملية:':'Type:'?></span><span><?=$ar ? $txnDef['ar'] : $txnDef['en']?></span></div>
    <div class="sum-row" id="sumSecurity"><span class="sum-key"><?=$ar?'الأمان:':'Security:'?></span><span><?=$txnDef['security']?></span></div>
    <div class="sum-row" id="sumMethod"><span class="sum-key"><?=$ar?'الطريقة:':'Method:'?></span><span>Manual</span></div>
    <div class="sum-row" id="sumDest"><span class="sum-key"><?=$ar?'الوجهة:':'Destination:'?></span><span style="color:var(--stripe);font-size:.78rem"><?=$ar?'رصيد Stripe':'Stripe Balance'?></span></div>
    <div class="sum-row"><span class="sum-key"><?=$ar?'المبلغ:':'Amount:'?></span><span id="sumAmt"><?=$amount>0?number_format($amount,2).' '.$currency:'—'?></span></div>
    <div class="sum-row"><span class="sum-key"><?=$ar?'الإجمالي:':'Total:'?></span><span id="sumTotal"><?=$amount>0?number_format($amount,2).' '.$currency:'—'?></span></div>

    <!-- Stripe info -->
    <div style="margin-top:16px;background:rgba(103,114,229,.04);border:1px solid rgba(103,114,229,.1);border-radius:10px;padding:12px;font-size:.7rem">
      <div style="font-weight:800;color:var(--stripe);margin-bottom:8px"><i class="fab fa-stripe-s"></i> Stripe API</div>
      <div style="display:flex;justify-content:space-between;margin-bottom:4px"><span style="color:var(--muted2)">Key:</span><span style="font-family:'Share Tech Mono',monospace"><?=substr($stripePK,0,18)?>...</span></div>
      <div style="display:flex;justify-content:space-between;margin-bottom:4px"><span style="color:var(--muted2)">Bank:</span><span style="color:var(--muted2);font-size:.68rem"><?=$ar?'غير مرتبط ببنك':'Not bank-linked'?></span></div>
      <div style="display:flex;justify-content:space-between"><span style="color:var(--muted2)">Mode:</span><span style="color:<?=$isTestMode?'#FBBF24':'var(--green)'?>"><?=$isTestMode?'Test':'Live'?> ●</span></div>
    </div>

    <a href="../checkout_router.php" class="btn btn-dark" style="margin-top:14px;font-size:.74rem;width:100%;justify-content:center">
      <i class="fas fa-exchange-alt"></i> <?=$ar?'تغيير البوابة':'Change Gateway'?>
    </a>
  </div>
</div>
</div><!-- /layout -->

<!-- ══ RESULT ══ -->
<div class="result-overlay" id="resultOverlay">
  <div class="result-box">
    <div style="font-size:3.5rem;margin-bottom:14px" id="resIcon">✅</div>
    <div style="font-size:1.15rem;font-weight:900;margin-bottom:8px" id="resTitle"></div>
    <div style="font-family:'Share Tech Mono',monospace;font-size:.72rem;color:var(--muted2);word-break:break-all;margin-bottom:16px" id="resRef"></div>
    <div style="background:rgba(255,255,255,.03);border-radius:12px;padding:14px;font-size:.78rem;text-align:<?=$ar?'right':'left'?>;margin-bottom:16px" id="resDetails"></div>
    <div style="display:flex;gap:10px;justify-content:center">
      <button class="btn btn-dark" onclick="document.getElementById('resultOverlay').classList.remove('show')">
        <i class="fas fa-times"></i> <?=$ar?'إغلاق':'Close'?>
      </button>
      <a href="../dashboard.php" class="btn btn-stripe">
        <i class="fas fa-th-large"></i> <?=$ar?'لوحة التحكم':'Dashboard'?>
      </a>
    </div>
  </div>
</div>

<div id="toast"></div>

<script>
// ============================================================
// CONFIGURATION
// ============================================================
const AR    = <?=$ar?'true':'false'?>;
const CSRF  = '<?=$csrf?>';
const REF   = '<?=$ref?>';
const STRIPE_PK = '<?=htmlspecialchars($stripePK)?>';
const INIT_AMT  = <?=$amount?>;
const INIT_CUR  = '<?=$currency?>';
const TXN_TYPE  = '<?=$txnTypeInit?>';
const SECURITY  = '<?=$txnDef['security']?>';
const REQUIRES_ORIGINAL = <?=$txnDef['requires_original'] ? 'true' : 'false'?>;

const STATE = {
  txnType: TXN_TYPE,
  secMode: '3D',
  dest: '<?=$destination?>',
  method: 'manual',
  requiresOriginal: REQUIRES_ORIGINAL,
};

// ── Init Stripe Elements ─────────────────────────────────────
let stripe = null, stripeElements = null, stripeCard = null;
function initStripe() {
  if (!STRIPE_PK || stripe) return;
  stripe = Stripe(STRIPE_PK);
  stripeElements = stripe.elements();
  stripeCard = stripeElements.create('card', {
    style: {
      base: {
        color: '#edf0f7',
        fontFamily: 'Cairo, sans-serif',
        fontSize: '15px',
        '::placeholder': { color: '#718096' },
        iconColor: '#6772e5',
      },
      invalid: { color: '#EF4444', iconColor: '#EF4444' },
    },
    hidePostalCode: true,
  });
  stripeCard.mount('#stripe-card-element');
  stripeCard.on('change', e => {
    document.getElementById('stripe-error').textContent = e.error ? e.error.message : '';
  });
}

document.addEventListener('DOMContentLoaded', () => {
  initStripe();
  if (INIT_AMT > 0) updateSummary();
  selectDest('<?=$destination?>', document.getElementById('sdest-<?=$destination?>'));
  
  // Show original reference if needed
  if (REQUIRES_ORIGINAL) {
    document.getElementById('extraPI').className = 'extra-fields show';
  }
});

// ── Select TXN Type ──────────────────────────────────────────
function selectTxnType(type, el) {
  document.querySelectorAll('.txn-btn').forEach(b => b.classList.remove('active'));
  el.classList.add('active');
  STATE.txnType = type;
  STATE.requiresOriginal = el.dataset.orig === '1';
  
  const name = el.querySelector('.txn-btn-name').textContent;
  document.getElementById('sumTxn').querySelector('span:last-child').textContent = name;
  
  // Show/hide original reference
  document.getElementById('extraPI').className = 'extra-fields' + (STATE.requiresOriginal ? ' show' : '');
}

// ── Mode (3D / 2D) ───────────────────────────────────────────
window.setMode = function(mode, el) {
  STATE.secMode = mode;
  document.querySelectorAll('.mode-tab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  document.getElementById('stripeElementSection').style.display = mode === '3D' ? '' : 'none';
  document.getElementById('manualCardSection').style.display    = mode === '2D' ? '' : 'none';
};

// ── Destination ─────────────────────────────────────────────
window.selectDest = function(code, el) {
  STATE.dest = code;
  document.querySelectorAll('.dest-opt').forEach(d => d.classList.remove('active'));
  if (el) el.classList.add('active');
  const customCodes = ['tron_w','erc20_w'];
  const isLedger = code === 'ledger_trx';
  const wrap = document.getElementById('walletInputWrap');
  wrap.className = 'wallet-input' + (customCodes.includes(code) || isLedger ? ' show' : '');
  if (isLedger) {
    document.getElementById('walletInputAddr').value = 'TEwLFWlwK55b7PuFfzgH1H2f3xs3pLgLn2';
    document.getElementById('walletInputAddr').readOnly = true;
  } else {
    document.getElementById('walletInputAddr').readOnly = false;
  }
  const n = {gateway:AR?'رصيد Stripe':'Stripe Balance',mashreq:'Mashreq Bank',hsbc:'HSBC UAE',nbe:'NBE Egypt',jpmorgan:'JP Morgan',ledger_trx:'Ledger TRX',tron_w:'Custom TRC20',erc20_w:'Custom ERC20'};
  document.getElementById('sumDest').querySelector('span:last-child').textContent = n[code] || code;
};

// ── Method ──────────────────────────────────────────────────
window.selectMethod = function(method, el) {
  STATE.method = method;
  document.querySelectorAll('.method-card').forEach(m => m.classList.remove('active'));
  el.classList.add('active');
  document.getElementById('nfcBar').className = 'nfc-bar' + (method === 'nfc' ? ' show' : '');
  document.getElementById('sumMethod').querySelector('span:last-child').textContent = method;
  if (method === 'nfc') initNFC();
};

// ── NFC ─────────────────────────────────────────────────────
async function initNFC() {
  if ('NDEFReader' in window) {
    try {
      const nf = new NDEFReader();
      await nf.scan();
      nf.onreading = e => {
        toast(AR ? '✅ تم قراءة البطاقة عبر NFC' : '✅ Card read via NFC', 'success');
        processStripe({ nfc_serial: e.serialNumber });
      };
    } catch(e) { toast('NFC: ' + e.message, 'error'); }
  } else {
    toast(AR ? 'NFC غير مدعوم' : 'NFC not supported', 'error');
  }
}

// ── Summary ─────────────────────────────────────────────────
function updateSummary() {
  const amt = parseFloat(document.getElementById('txnAmount').value) || 0;
  const cur = document.getElementById('txnCurrency').value;
  document.getElementById('sumAmt').textContent   = amt > 0 ? amt.toFixed(2) + ' ' + cur : '—';
  document.getElementById('sumTotal').textContent = amt > 0 ? amt.toFixed(2) + ' ' + cur : '—';
  document.getElementById('payBtnAmt').textContent = amt > 0 ? '(' + amt.toFixed(2) + ' ' + cur + ')' : '';
}

// ── Process ─────────────────────────────────────────────────
window.processStripe = async function(extra = {}) {
  const btn    = document.getElementById('payBtn');
  const amount = parseFloat(document.getElementById('txnAmount').value) || 0;
  const currency = document.getElementById('txnCurrency').value;
  const email  = document.getElementById('txnEmail').value.trim();
  const origRef = document.getElementById('origRef')?.value.trim() || '';

  const noCard = ['auth_capture', 'refund', 'reversal', 'void'];
  if (!noCard.includes(STATE.txnType) && amount <= 0) {
    return toast(AR ? 'أدخل المبلغ' : 'Enter amount', 'error');
  }

  btn.disabled = true;
  btn.innerHTML = '<span class="spin"></span> ' + (AR ? 'جاري المعالجة...' : 'Processing...');

  const walletAddr = document.getElementById('walletInputAddr')?.value.trim() || 'TEwLFWlwK55b7PuFfzgH1H2f3xs3pLgLn2';

  try {
    let paymentMethodId = null;

    // 3D: Stripe Elements → get payment method
    if (STATE.secMode === '3D' && !noCard.includes(STATE.txnType) && stripe && stripeCard && !extra.nfc_serial) {
      const name = document.getElementById('stripeCardName').value.trim();
      const { paymentMethod, error } = await stripe.createPaymentMethod({
        type: 'card',
        card: stripeCard,
        billing_details: { name: name || 'Customer', email: email || undefined },
      });
      if (error) {
        document.getElementById('stripe-error').textContent = error.message;
        btn.disabled = false;
        btn.innerHTML = '<i class="fab fa-stripe-s"></i> ' + (AR ? 'ادفع عبر Stripe' : 'Pay with Stripe');
        return;
      }
      paymentMethodId = paymentMethod.id;
    }

    // 2D / MOTO: manual fields
    let manualCard = {};
    if (STATE.secMode === '2D' && !noCard.includes(STATE.txnType)) {
      manualCard = {
        card_number: document.getElementById('manualNum').value.replace(/\s/g,''),
        card_name:   document.getElementById('manualName').value.trim(),
        card_expiry: document.getElementById('manualExp').value,
        card_cvv:    document.getElementById('manualCVV').value,
      };
      if (!manualCard.card_number || manualCard.card_number.length < 13) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fab fa-stripe-s"></i> ' + (AR ? 'ادفع عبر Stripe' : 'Pay with Stripe');
        return toast(AR ? 'أدخل رقم البطاقة' : 'Enter card number', 'error');
      }
    }

    const payload = {
      txn_type:          STATE.txnType,
      gateway:           'stripe',
      amount,
      currency,
      email:             email || 'client@diparmas.com',
      security_mode:     STATE.secMode,
      payment_method_id: paymentMethodId,
      orig_ref:          origRef,
      destination:       STATE.dest,
      ledger_address:    walletAddr,
      auto_transfer:     ['ledger_trx','tron_w','erc20_w'].includes(STATE.dest),
      reference:         REF,
      pos_device:        STATE.method === 'physical' ? 'BITEL_IC3600' : 'WEB_STRIPE',
      csrf_token:        CSRF,
      ...manualCard,
      ...extra,
    };

    const r = await fetch('../api/stripe_charge.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const d = await r.json();

    // 3DS challenge if required
    if (d.requires_3ds && d.client_secret && stripe) {
      toast(AR ? 'البنك يطلب تأكيد 3D...' : '3D verification required...', 'info');
      const { error, paymentIntent } = await stripe.confirmCardPayment(d.client_secret);
      if (error) {
        showResult({ success: false, message: error.message }, amount, currency);
        return;
      }
      showResult({ success: true, reference: REF, rrn: paymentIntent.id, status_message: 'APPROVED' }, amount, currency);
      return;
    }

    showResult(d, amount, currency);

  } catch(e) {
    toast(AR ? 'خطأ في الاتصال' : 'Connection error', 'error');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="fab fa-stripe-s"></i> <span>' + (AR ? 'ادفع عبر Stripe' : 'Pay with Stripe') + '</span><span id="payBtnAmt" style="opacity:.8"></span>';
    updateSummary();
  }
};

function showResult(d, amount, currency) {
  document.getElementById('resultOverlay').classList.add('show');
  document.getElementById('resIcon').textContent  = d.success ? '✅' : '❌';
  document.getElementById('resTitle').textContent = d.success
    ? (AR ? 'تمت العملية بنجاح' : 'Payment Approved')
    : (AR ? 'رُفضت العملية' : 'Payment Declined');
  document.getElementById('resTitle').style.color = d.success ? 'var(--green)' : 'var(--red)';
  document.getElementById('resRef').textContent   = 'REF: ' + (d.reference || REF);
  document.getElementById('resDetails').innerHTML = `
    <div style="display:flex;justify-content:space-between;padding:4px 0"><span>Gateway</span><span style="color:var(--stripe)">Stripe</span></div>
    ${d.rrn?`<div style="display:flex;justify-content:space-between;padding:4px 0"><span>ID</span><span style="font-family:monospace;font-size:.68rem">${d.rrn}</span></div>`:''}
    <div style="display:flex;justify-content:space-between;padding:4px 0"><span>${AR?'المبلغ':'Amount'}</span><span>${(amount||0).toFixed(2)} ${currency||''}</span></div>
    <div style="display:flex;justify-content:space-between;padding:4px 0"><span>${AR?'الوجهة':'Destination'}</span><span style="color:var(--stripe)">${STATE.dest}</span></div>
    <div style="display:flex;justify-content:space-between;padding:4px 0"><span>Status</span><span style="color:${d.success?'var(--green)':'var(--red)'}">${d.status_message||'—'}</span></div>
  `;
}

function toast(msg, type='info') {
  const t = document.getElementById('toast');
  const c = {success:'var(--green)',error:'var(--red)',info:'var(--stripe)'};
  t.style.borderColor = c[type] || c.info;
  t.style.color = c[type] || c.info;
  t.textContent = msg;
  t.style.transform = 'translateX(-50%) translateY(0)';
  clearTimeout(t._t);
  t._t = setTimeout(() => { t.style.transform = 'translateX(-50%) translateY(100px)'; }, 4500);
}
</script>
</body>
</html>