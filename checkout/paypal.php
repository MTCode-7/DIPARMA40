<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

/**
 * ============================================================
 * DI PARMA | PayPal Checkout - 13 نوع شراء
 * ============================================================
 * 
 * يدعم 13 نوعاً مختلفاً من عمليات الشراء عبر PayPal
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
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

$paypalGateway = db()->find('payment_gateways', ['code' => 'paypal']);
$paypalCredentials = json_decode($paypalGateway['credentials'] ?? '{}', true) ?: [];
foreach (['client_id' => 'PAYPAL_CLIENT_ID', 'secret' => 'PAYPAL_SECRET', 'environment' => 'PAYPAL_ENVIRONMENT'] as $field => $envKey) {
  if (!empty($paypalCredentials[$field])) {
    putenv($envKey . '=' . $paypalCredentials[$field]);
    $_ENV[$envKey] = $paypalCredentials[$field];
  }
}

// ============================================================
// [1] إعدادات اللغة
// ============================================================

$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'ar' ? 'ar' : 'en';
$ar   = ($lang === 'ar');
$dir  = $ar ? 'rtl' : 'ltr';
$csrf = generateCsrfToken();

// ============================================================
// [2] استقبال بارامترات URL
// ============================================================

$amount      = floatval($_GET['amount'] ?? 0);
$currency    = strtoupper($_GET['currency'] ?? 'USD');
$destination = $_GET['destination'] ?? 'gateway';
$txnTypeInit = $_GET['txn_type'] ?? 'purchase_3d';
$txnTypeInit = [
  'auth'          => 'auth_moto',
  'auth_complete' => 'auth_capture',
][$txnTypeInit] ?? $txnTypeInit;
$walletAddr  = $_GET['wallet'] ?? '';
$ref         = $_GET['ref'] ?? ('PP-' . strtoupper(bin2hex(random_bytes(6))));

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
        'security' => '2D',
        'requires_original' => false,
        'category' => 'auth',
        'iso' => '0100',
        'moto_indicator' => 'M',
        'description' => 'تجميد المبلغ مؤقتاً لحين تأكيد العملية'
    ],

      'auth_moto' => [
        'ar' => 'حجز MOTO',
        'en' => 'MOTO Authorization Hold',
        'icon' => 'fa-phone',
        'color' => '#F59E0B',
        'security' => '2D',
        'requires_original' => false,
        'category' => 'auth',
        'iso' => '0100',
        'moto_indicator' => 'M',
        'description' => 'حجز مبلغ عبر الهاتف أو البريد دون 3D Secure'
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
        'description' => 'شراء عملات رقمية باستخدام البطاقة'
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

// ============================================================
// [4] وجهات المبلغ
// ============================================================

$destinations = [
    'gateway' => 'PayPal Balance',
    'mashreq' => 'Mashreq Bank — AE300330000019101562722',
    'hsbc' => 'HSBC UAE',
    'nbe' => 'NBE Egypt',
    'jpmorgan' => 'JP Morgan IOLTA',
    'ledger_trx' => 'Ledger TRX — ' . (defined('LEDGER_TRC20_ADDRESS') ? LEDGER_TRC20_ADDRESS : 'TEwLFWlwK55b7PuFfzgH1H2f3xs3pLgLn2'),
    'tron_w' => $walletAddr ?: 'TRC20 Wallet',
    'erc20_w' => $walletAddr ?: 'ERC20 Wallet',
];

$destLabel = $destinations[$destination] ?? $destination;

// ============================================================
// [5] PayPal Client ID
// ============================================================

$paypalClientId = getenv('PAYPAL_CLIENT_ID') ?: '';

// ============================================================
// [6] الحصول على بيانات النوع المحدد
// ============================================================

$txnDef = $txnTypes[$txnTypeInit] ?? $txnTypes['purchase_3d'];
?>
<!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$dir?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | <?=$ar?'بوابة PayPal':'PayPal Checkout'?></title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* ============================================================
   STYLES
   ============================================================ */
*{margin:0;padding:0;box-sizing:border-box}
:root{--gold:#FFD700;--gold2:#FFB700;--bg:#030609;--card:#090f1e;--card2:#0b1224;--border:rgba(255,215,0,.12);--border2:rgba(255,215,0,.28);--text:#edf0f7;--muted:#4a5568;--muted2:#718096;--green:#10B981;--red:#EF4444;--paypal:#003087}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}

/* Topbar */
.topbar{background:rgba(3,6,9,.97);border-bottom:1px solid var(--border);height:60px;display:flex;align-items:center;justify-content:space-between;padding:0 28px;position:sticky;top:0;z-index:100}
.tb-brand{color:var(--gold);font-weight:900;font-size:1.05rem;display:flex;align-items:center;gap:10px}
.tb-badge{background:rgba(0,48,135,.15);border:1px solid rgba(0,48,135,.3);border-radius:8px;padding:4px 12px;font-size:.72rem;font-weight:800;color:#4da6ff}
.tb-back{color:var(--muted2);font-size:.78rem;text-decoration:none;display:flex;align-items:center;gap:6px}
.tb-back:hover{color:var(--gold)}

/* Layout */
.wrap{max-width:860px;margin:0 auto;padding:32px 24px}

/* Cards */
.card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:24px;margin-bottom:20px}
.card-title{font-size:.9rem;font-weight:800;color:var(--gold);display:flex;align-items:center;gap:8px;margin-bottom:18px}

/* Destination */
.dest-box{background:rgba(16,185,129,.05);border:1px solid rgba(16,185,129,.15);border-radius:12px;padding:14px;margin-bottom:16px}
.dest-title{font-size:.72rem;font-weight:800;color:var(--green);margin-bottom:5px}
.dest-val{font-size:.75rem;color:var(--muted2);word-break:break-all}

/* TXN Types Grid - 13 نوع */
.txn-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin-bottom:16px}
.txn-btn{padding:8px 4px;border-radius:10px;border:1.5px solid var(--border);background:rgba(255,255,255,.03);cursor:pointer;text-align:center;transition:.2s}
.txn-btn:hover{border-color:rgba(255,255,255,.15)}
.txn-btn.active{border-color:var(--gold);background:rgba(255,215,0,.05)}
.txn-btn-icon{font-size:.9rem;margin-bottom:3px;display:block}
.txn-btn-name{font-size:.58rem;font-weight:800;color:var(--muted2);line-height:1.2}
.txn-btn.active .txn-btn-name{color:var(--gold)}

/* Summary */
.sum-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:.82rem}
.sum-row:last-child{border:none;font-weight:900;font-size:.98rem;color:var(--gold)}
.sum-key{color:var(--muted2)}
.sum-value{text-align:right;word-break:break-all}

/* Method Tabs */
.method-tabs{display:flex;gap:10px;margin:16px 0}
.method-tab{flex:1;padding:10px;border-radius:12px;border:1.5px solid var(--border);background:rgba(255,255,255,.03);cursor:pointer;text-align:center;font-size:.8rem;font-weight:700;color:var(--muted2);transition:.2s}
.method-tab.active{border-color:var(--gold);background:rgba(255,215,0,.05);color:var(--gold)}

/* PayPal Button */
#paypal-button-container{min-height:50px;margin-top:8px}

/* Direct Card */
#card-section{display:none}
.fld{margin-bottom:12px}
.fld label{display:block;font-size:.72rem;color:var(--muted2);margin-bottom:5px;font-weight:700}
.fld input{width:100%;background:rgba(255,255,255,.04);border:1.5px solid var(--border);border-radius:11px;padding:11px 14px;color:var(--text);font-family:'Cairo',sans-serif;font-size:.88rem;transition:.2s}
.fld input:focus{outline:none;border-color:var(--gold)}
.fld-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}

/* Pay Button */
.pay-btn{width:100%;padding:14px;border-radius:13px;border:none;cursor:pointer;font-family:'Cairo',sans-serif;font-size:.95rem;font-weight:900;background:linear-gradient(135deg,#003087,#001a4d);color:#fff;box-shadow:0 8px 24px rgba(0,48,135,.3);transition:.3s;display:flex;align-items:center;justify-content:center;gap:10px;margin-top:12px}
.pay-btn:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 12px 32px rgba(0,48,135,.4)}
.pay-btn:disabled{opacity:.4;cursor:not-allowed}
.pay-btn .spin{display:inline-block;width:16px;height:16px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite}

/* Extra Fields */
.extra-fields{display:none;margin-top:10px;padding:12px;background:rgba(255,255,255,.02);border-radius:10px;border:1px dashed var(--border)}
.extra-fields.show{display:block}

@keyframes spin{to{transform:rotate(360deg)}}

/* Toast */
#toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(100px);background:var(--card);border:1px solid var(--border2);border-radius:14px;padding:12px 28px;font-size:.84rem;font-weight:700;z-index:9999;transition:.35s;color:var(--text)}

/* Responsive */
@media(max-width:700px){.txn-grid{grid-template-columns:repeat(4,1fr)}}
@media(max-width:500px){.txn-grid{grid-template-columns:repeat(3,1fr)}}
</style>
</head>
<body>

<!-- ═══ TOPBAR ═══ -->
<header class="topbar">
  <div class="tb-brand">
    <i class="fas fa-coins"></i> DI PARMA
    <span style="color:var(--muted)">|</span>
    <div class="tb-badge"><i class="fab fa-paypal"></i> PayPal</div>
  </div>
  <a href="../checkout_router.php" class="tb-back">
    <i class="fas fa-arrow-<?=$ar?'right':'left'?>"></i> <?=$ar?'رجوع':'Back'?>
  </a>
</header>

<div class="wrap">

  <!-- ═══ DESTINATION ═══ -->
  <div class="dest-box">
    <div class="dest-title"><i class="fas fa-map-marker-alt"></i> <?=$ar?'وجهة المبلغ':'Destination'?></div>
    <div class="dest-val"><?=htmlspecialchars($destLabel)?></div>
  </div>

  <!-- ═══ 13 TYPE SELECTION ═══ -->
  <div class="card">
    <div class="card-title"><i class="fas fa-list"></i> <?=$ar?'نوع العملية (14)':'Transaction Type (14)'?></div>
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

    <!-- Extra Fields (Original Reference) -->
    <div class="extra-fields <?=$txnDef['requires_original'] ? 'show' : ''?>" id="extraOrigRef">
      <div class="fld">
        <label><i class="fas fa-hashtag"></i> <?=$ar?'رقم المرجع الأصلي (RRN)':'Original Reference (RRN)'?></label>
        <input type="text" id="origRef" placeholder="<?=$ar?'رقم العملية السابقة':'Previous transaction reference'?>">
      </div>
      <div class="fld" id="approvalCodeWrap" style="display:none">
        <label><i class="fas fa-check-circle"></i> <?=$ar?'رمز الموافقة':'Approval Code'?></label>
        <input type="text" id="approvalCode" placeholder="<?=$ar?'أدخل رمز الموافقة':'Enter approval code'?>">
      </div>
    </div>
  </div>

  <!-- ═══ SUMMARY ═══ -->
  <div class="card">
    <div class="card-title"><i class="fas fa-receipt"></i> <?=$ar?'ملخص':'Summary'?></div>
    <div class="sum-row">
      <span class="sum-key"><?=$ar?'المبلغ:':'Amount:'?></span>
      <span class="sum-value"><?=number_format($amount, 2)?> <?=$currency?></span>
    </div>
    <div class="sum-row">
      <span class="sum-key"><?=$ar?'النوع:':'Type:'?></span>
      <span class="sum-value" id="sumType"><?=$ar ? $txnDef['ar'] : $txnDef['en']?></span>
    </div>
    <div class="sum-row">
      <span class="sum-key"><?=$ar?'الأمان:':'Security:'?></span>
      <span class="sum-value" id="sumSecurity"><?=$txnDef['security']?></span>
    </div>
    <div class="sum-row">
      <span class="sum-key"><?=$ar?'المرجع:':'Ref:'?></span>
      <span class="sum-value" style="font-family:monospace;font-size:.72rem"><?=htmlspecialchars($ref)?></span>
    </div>
    <div class="sum-row">
      <span class="sum-key"><?=$ar?'الوجهة:':'Destination:'?></span>
      <span class="sum-value" style="color:var(--green);font-size:.78rem"><?=htmlspecialchars($destLabel)?></span>
    </div>
    <div class="sum-row">
      <span class="sum-key"><?=$ar?'الإجمالي:':'Total:'?></span>
      <span class="sum-value"><?=number_format($amount, 2)?> <?=$currency?></span>
    </div>

    <!-- Method Tabs -->
    <div class="method-tabs">
      <button type="button" class="method-tab active" data-method="paypal">
        <i class="fab fa-paypal"></i> PayPal
      </button>
      <button type="button" class="method-tab" data-method="card">
        <i class="fas fa-credit-card"></i> <?=$ar?'بطاقة مباشرة':'Direct Card'?>
      </button>
    </div>

    <!-- PayPal Button -->
    <div id="paypal-section">
      <div id="paypal-button-container"></div>
    </div>

    <!-- Direct Card -->
    <div id="card-section">
      <div class="fld">
        <label><?=$ar?'رقم البطاقة':'Card Number'?></label>
        <input type="text" id="ppCardNum" maxlength="19" 
               placeholder="0000 0000 0000 0000" 
               oninput="formatCard(this)">
      </div>
      <div class="fld-row">
        <div class="fld">
          <label><?=$ar?'تاريخ الانتهاء':'Expiry'?></label>
          <input type="text" id="ppExp" maxlength="5" placeholder="MM/YY" oninput="formatExpiry(this)">
        </div>
        <div class="fld">
          <label>CVV</label>
          <input type="password" id="ppCvv" maxlength="4" placeholder="•••">
        </div>
      </div>
      <div class="fld">
        <label><?=$ar?'الاسم':'Name'?></label>
        <input type="text" id="ppName" placeholder="<?=$ar?'الاسم كما على البطاقة':'Name as on card'?>">
      </div>
      <div class="fld">
        <label><?=$ar?'البريد الإلكتروني':'Email'?></label>
        <input type="email" id="ppEmail" placeholder="email@example.com">
      </div>
      <button class="pay-btn" id="ppCardBtn" onclick="payByCard()">
        <i class="fas fa-lock"></i> <?=in_array($txnTypeInit, ['auth_hold', 'auth_moto'], true) ? ($ar?'حجز عبر البطاقة':'Authorize via Card') : ($ar?'ادفع بالبطاقة':'Pay via Card')?>
      </button>
    </div>
  </div>

</div>

<!-- ═══ TOAST ═══ -->
<div id="toast"></div>

<!-- ═══ PAYPAL SDK ═══ -->
<script src="https://www.paypal.com/sdk/js?client-id=<?=htmlspecialchars($paypalClientId)?>&currency=<?=$currency?>&intent=<?=in_array($txnTypeInit, ['auth_hold', 'auth_moto'], true) ? 'authorize' : 'capture'?>"></script>

<script>
// ============================================================
// CONFIGURATION
// ============================================================
const AR = <?=$ar ? 'true' : 'false'?>;
const CSRF = '<?=$csrf?>';
const AMOUNT = <?=$amount?>;
const CURRENCY = '<?=$currency?>';
const DESTINATION = '<?=$destination?>';
const WALLET = '<?=htmlspecialchars($walletAddr)?>';
const TXN_TYPE = '<?=$txnTypeInit?>';
const REF = '<?=$ref?>';
const SECURITY = '<?=$txnDef['security']?>';

// ============================================================
// STATE
// ============================================================
const STATE = {
    txnType: TXN_TYPE,
    method: 'paypal',
    security: SECURITY,
    requiresOriginal: <?=$txnDef['requires_original'] ? 'true' : 'false'?>
};

// ============================================================
// SELECT TXN TYPE
// ============================================================
function selectTxnType(type, el) {
    // Update active state
    document.querySelectorAll('.txn-btn').forEach(b => b.classList.remove('active'));
    el.classList.add('active');
    STATE.txnType = type;
    STATE.requiresOriginal = el.dataset.orig === '1';
    
    // Show/hide original reference field
    document.getElementById('extraOrigRef').className = 'extra-fields' + (STATE.requiresOriginal ? ' show' : '');
    document.getElementById('approvalCodeWrap').style.display = type === 'auth_capture' ? '' : 'none';
    
    // Update summary
    const name = el.querySelector('.txn-btn-name').textContent;
    const security = el.dataset.security || '2D';
    document.getElementById('sumType').textContent = name;
    document.getElementById('sumSecurity').textContent = security;
}

// ============================================================
// SWITCH METHOD
// ============================================================
function switchMethod(method, el) {
    document.querySelectorAll('.method-tab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    STATE.method = method;
    
    document.getElementById('paypal-section').style.display = method === 'paypal' ? '' : 'none';
    document.getElementById('card-section').style.display = method === 'card' ? '' : 'none';
}

  document.querySelectorAll('.method-tab').forEach(tab => {
    tab.addEventListener('click', () => switchMethod(tab.dataset.method, tab));
  });

// ============================================================
// CARD FORMATTING
// ============================================================
function formatCard(el) {
    let v = el.value.replace(/\D/g, '').substring(0, 16);
    el.value = v.replace(/(.{4})/g, '$1 ').trim();
}

function formatExpiry(el) {
    let v = el.value.replace(/\D/g, '').substring(0, 4);
    if (v.length >= 2) {
        v = v.substring(0, 2) + '/' + v.substring(2, 4);
    }
    el.value = v;
}

// ============================================================
// PAYPAL SMART BUTTONS
// ============================================================
if (typeof paypal !== 'undefined') {
    paypal.Buttons({
    createOrder: async function() {
      const response = await fetch('../api/paypal.php?action=create_order', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          amount: AMOUNT,
          currency: CURRENCY,
          reference: REF,
          destination: DESTINATION,
          intent: ['auth_hold', 'auth_moto'].includes(TXN_TYPE) ? 'AUTHORIZE' : 'CAPTURE',
          wallet_address: WALLET,
          transaction_type: ['auth_hold', 'auth_moto'].includes(TXN_TYPE) ? 'PayPal Authorization' : 'PayPal Payment',
          csrf_token: CSRF
        })
      });
      const result = await response.json();
      if (!result.success || !result.order_id) {
        throw new Error(result.message || 'PayPal order creation failed');
      }
      return result.order_id;
        },
        
        onApprove: async function(data, actions) {
            // Authorize or Capture based on transaction type
            let order;
            order = ['auth_hold', 'auth_moto'].includes(TXN_TYPE)
              ? await actions.order.authorize()
              : await actions.order.capture();
            
            // Get original reference if needed
            const origRef = document.getElementById('origRef')?.value || '';
            
            const action = ['auth_hold', 'auth_moto'].includes(TXN_TYPE) ? 'authorize_order' : 'capture_order';
            const response = await fetch('../api/paypal.php?action=' + action, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                order_id: data.orderID,
                reference: REF,
                csrf_token: CSRF,
                paypal_txn: JSON.stringify(order),
                orig_ref: origRef
              })
            });
            const result = await response.json();
            if (!result.success) throw new Error(result.message || 'PayPal transaction failed');
            toast(AR ? '✅ تمت العملية بنجاح' : '✅ Transaction approved', 'success');
            setTimeout(() => { window.location.href = '../receipt.php?ref=' + encodeURIComponent(REF); }, 2000);
        },
        
        onError: function(err) {
            toast(AR ? 'خطأ في PayPal: ' + err : 'PayPal error: ' + err, 'error');
        },
        
        onCancel: function() {
            toast(AR ? 'تم إلغاء الدفع' : 'Payment cancelled', 'info');
        }
    }).render('#paypal-button-container');
}

// ============================================================
// PAY BY CARD
// ============================================================
async function payByCard() {
    const btn = document.getElementById('ppCardBtn');
    const num = document.getElementById('ppCardNum').value.replace(/\s/g, '');
    const exp = document.getElementById('ppExp').value;
    const cvv = document.getElementById('ppCvv').value;
    const name = document.getElementById('ppName').value.trim();
    const email = document.getElementById('ppEmail').value.trim();
    const origRef = document.getElementById('origRef')?.value || '';
    const authCode = document.getElementById('approvalCode')?.value.trim() || '';
    
    // Validation
    if (num.length < 13) {
        return toast(AR ? 'رقم البطاقة غير صحيح' : 'Invalid card number', 'error');
    }
    if (!exp.match(/^\d{2}\/\d{2}$/)) {
        return toast(AR ? 'أدخل تاريخ الانتهاء' : 'Enter expiry date', 'error');
    }
    if (cvv.length < 3) {
        return toast(AR ? 'أدخل CVV' : 'Enter CVV', 'error');
    }
    if (!name) {
        return toast(AR ? 'أدخل اسم حامل البطاقة' : 'Enter cardholder name', 'error');
    }
    if (TXN_TYPE === 'auth_capture' && !authCode) {
      return toast(AR ? 'أدخل رمز التفويض الأصلي' : 'Enter the original authorization code', 'error');
    }
    
    btn.disabled = true;
    btn.innerHTML = '<span class="spin"></span> ' + (AR ? 'جاري المعالجة...' : 'Processing...');
    
    await sendToServer({
        card_number: num,
        card_expiry: exp,
        card_cvv: cvv,
        card_name: name,
        email: email || 'client@diparmas.com',
        method: 'direct_card',
        orig_ref: origRef,
        auth_code: authCode,
      txn_type: ['auth_hold', 'auth_moto'].includes(TXN_TYPE) ? 'auth' : (TXN_TYPE === 'auth_capture' ? 'auth_complete' : 'purchase'),
      extra: ['auth_hold', 'auth_moto'].includes(TXN_TYPE) ? { moto_indicator: 'M', is_moto: 1, transaction_label: 'MOTO Authorization Hold' } : {}
    });
    
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-lock"></i> ' + (['auth_hold', 'auth_moto'].includes(TXN_TYPE)
      ? (AR ? 'حجز عبر البطاقة' : 'Authorize via Card')
      : (AR ? 'ادفع بالبطاقة' : 'Pay via Card'));
}

// ============================================================
// SEND TO SERVER
// ============================================================
async function sendToServer(extra) {
    try {
        const payload = {
            txn_type: extra.txn_type || TXN_TYPE,
            amount: AMOUNT,
            currency: CURRENCY,
            destination: DESTINATION,
            ledger_address: WALLET || 'TEwLFWlwK55b7PuFfzgH1H2f3xs3pLgLn2',
            auto_transfer: DESTINATION === 'ledger_trx',
            reference: REF,
            pos_device: 'WEB_PAYPAL',
            csrf_token: CSRF,
            security_mode: SECURITY,
            ...extra
        };
        
        // Remove undefined values
        Object.keys(payload).forEach(key => {
            if (payload[key] === undefined) delete payload[key];
        });
        
        const r = await fetch('../api/pos_transaction.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        
        const d = await r.json();
        
        if (d.success) {
            toast(AR ? '✅ تمت العملية بنجاح' : '✅ Transaction approved', 'success');
            setTimeout(() => {
                window.location.href = '../receipt.php?ref=' + encodeURIComponent(REF);
            }, 2000);
        } else {
            toast(d.message || (AR ? 'فشلت العملية' : 'Transaction failed'), 'error');
        }
    } catch (e) {
        toast(AR ? 'خطأ في الاتصال' : 'Connection error', 'error');
    }
}

// ============================================================
// TOAST NOTIFICATION
// ============================================================
function toast(msg, type = 'info') {
    const t = document.getElementById('toast');
    const colors = {
        success: 'var(--green)',
        error: 'var(--red)',
        info: 'var(--gold)'
    };
    t.style.borderColor = colors[type] || colors.info;
    t.style.color = colors[type] || colors.info;
    t.textContent = msg;
    t.style.transform = 'translateX(-50%) translateY(0)';
    clearTimeout(t._t);
    t._t = setTimeout(() => {
        t.style.transform = 'translateX(-50%) translateY(100px)';
    }, 4000);
}

// ============================================================
// INIT
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    // Show/hide original reference if needed
    if (STATE.requiresOriginal) {
        document.getElementById('extraOrigRef').className = 'extra-fields show';
    }
});
</script>
</body>
</html>