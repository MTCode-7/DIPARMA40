<?php
/**
 * DI PARMA | Unified Checkout Template
 * يُستدعى من كل checkout_*.php بعد تعريف المتغيرات:
 * $gwCode, $gwName, $gwColor, $gwIcon, $gwLabel, $currencies[], $csrfToken
 */
if (!defined('DI_PARMA_CHECKOUT')) {
    header('Location: checkout_router.php'); exit;
}
if (!function_exists('pos_operation_catalog')) {
    require_once __DIR__ . '/includes/pos_operations.php';
}
$checkoutOps = $checkoutOps ?? pos_operation_catalog();
$basePath = $basePath ?? '';
$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'ar' ? 'ar' : 'en';
$ar   = ($lang === 'ar');
$dir  = $ar ? 'rtl' : 'ltr';
$currencyOptions = '';
foreach (($currencies ?? ['USD','EUR','GBP','AED']) as $cur) {
    $sel = (!empty($prefillCurrency) && $prefillCurrency === $cur) ? ' selected' : '';
    $currencyOptions .= '<option value="' . htmlspecialchars((string)$cur, ENT_QUOTES, 'UTF-8') . '"' . $sel . '>' . htmlspecialchars((string)$cur, ENT_QUOTES, 'UTF-8') . '</option>';
}
$motoCurrencyOptions = $currencyOptions;
$defaultOp = array_key_first($checkoutOps) ?: 'purchase_2d';
if (!empty($prefillOp) && isset($checkoutOps[$prefillOp])) {
    $defaultOp = $prefillOp;
}
$isPayram = (($gwCode ?? '') === 'payram');
$isDiparmaGw = (($gwCode ?? '') === 'diparma_gateway');
$isLedgerGw = false;
$chargeEndpoint = ($basePath ?? '') . 'api/checkout_charge.php';
$chargeGwCode = $chargeGwCode ?? $gwCode;
$isNuveiFamily = in_array(($chargeGwCode ?? $gwCode ?? ''), ['nuvei', 'diparma'], true);
$squareSdk = $squareSdk ?? ['ready' => false, 'application_id' => '', 'location_id' => '', 'script_url' => '', 'live' => false];
$hasSquareSdk = (($gwCode ?? '') === 'square' && !empty($squareSdk['application_id']) && !empty($squareSdk['location_id']));
?><!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$dir?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | <?=htmlspecialchars($gwName)?></title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<?php if (!empty($stripeKey)): ?><script src="https://js.stripe.com/v3/"></script><?php endif; ?>
<?php if ($hasSquareSdk): ?>
<script type="text/javascript" src="<?=htmlspecialchars($squareSdk['script_url'])?>"></script>
<script src="<?=htmlspecialchars($basePath)?>assets/js/square_web_payments.js?v=<?= (int) @filemtime(__DIR__ . '/assets/js/square_web_payments.js') ?>"></script>
<?php endif; ?>
</head>
<body>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{
  --gold:#FFD700;--bg:#040810;--card:#080d1a;--border:rgba(255,215,0,.15);
  --text:#f0f0f0;--muted:#666;--green:#10B981;--red:#EF4444;
  --gw:<?=htmlspecialchars($gwColor ?? '#FFD700')?>;
}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
/* ── Top Bar ── */
.top-bar{background:rgba(4,8,16,.97);border-bottom:1px solid var(--border);padding:0 24px;height:62px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:99}
.top-brand{color:var(--gold);font-weight:900;font-size:1rem}
.gw-badge{background:color-mix(in srgb,var(--gw) 15%,transparent);border:2px solid var(--gw);border-radius:12px;padding:6px 18px;color:var(--gw);font-weight:800;font-size:.85rem;display:flex;align-items:center;gap:8px}
.top-nav a{color:var(--muted);font-size:.82rem;padding:6px 13px;border-radius:20px;text-decoration:none;transition:.2s}
.top-nav a:hover{color:var(--gold)}
.back-link{display:inline-flex;align-items:center;gap:6px;color:var(--muted);font-size:.8rem;text-decoration:none;margin-bottom:14px}
.back-link:hover{color:var(--gold)}
/* ── Layout ── */
.wrap{max-width:1060px;margin:0 auto;padding:18px 20px;display:grid;grid-template-columns:1fr 330px;gap:18px}
.co-card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:22px;margin-bottom:14px}
.co-title{font-size:.9rem;font-weight:800;margin-bottom:14px;display:flex;align-items:center;gap:8px}
/* ── Transaction Type ── */
.tx-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:6px;margin-bottom:10px}
.tx-btn{background:rgba(255,255,255,.04);border:1.5px solid rgba(255,215,0,.12);border-radius:10px;padding:9px 3px;text-align:center;cursor:pointer;transition:.2s;user-select:none}
.tx-btn:hover{border-color:rgba(255,215,0,.3)}
.tx-btn.active{border-color:var(--gw);background:color-mix(in srgb,var(--gw) 10%,transparent)}
.tx-btn i{display:block;font-size:.95rem;margin-bottom:3px}
.tx-btn span{font-size:.58rem;font-weight:700;display:block;line-height:1.3;color:var(--text)}
/* ── Security ── */
.sec-grid{display:grid;grid-template-columns:1fr 1fr;gap:9px}
.sec-btn{background:rgba(255,255,255,.04);border:1.5px solid rgba(255,215,0,.12);border-radius:11px;padding:11px;cursor:pointer;transition:.2s;display:flex;align-items:center;gap:9px}
.sec-btn.active{border-color:var(--gold);background:rgba(255,215,0,.06)}
.sec-label{font-size:.77rem;font-weight:800}
.sec-sub{font-size:.63rem;color:var(--muted)}
/* ── Fields ── */
.fld{margin-bottom:11px}
.fld label{display:block;font-size:.73rem;color:var(--muted);margin-bottom:4px;font-weight:700}
.fld label .req{color:var(--red);margin-<?=$ar?'right':'left'?>:3px}
.fld label .opt{color:var(--muted);font-size:.63rem;font-weight:400}
.fld input,.fld select{width:100%;background:rgba(255,255,255,.04);border:1.5px solid var(--border);border-radius:10px;padding:10px 13px;color:var(--text);font-family:'Cairo',sans-serif;font-size:.86rem;transition:.2s}
.fld input:focus,.fld select:focus{outline:none;border-color:var(--gw);background:rgba(255,255,255,.06)}
.fld-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.fld-row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
/* ── Capture Required Box ── */
.req-box{background:rgba(255,165,0,.05);border:1.5px solid rgba(255,165,0,.2);border-radius:12px;padding:14px;margin-bottom:12px}
.req-box-title{font-size:.76rem;font-weight:800;color:#f0ad4e;margin-bottom:10px;display:flex;align-items:center;gap:6px}
/* ── Auth Optional Box ── */
.opt-box{background:rgba(255,255,255,.02);border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:14px;margin-top:8px}
.opt-box-title{font-size:.74rem;font-weight:800;color:#888;margin-bottom:10px;display:flex;align-items:center;gap:6px}
/* ── Info Note ── */
.info-note{background:color-mix(in srgb,var(--gw) 6%,transparent);border:1px solid color-mix(in srgb,var(--gw) 20%,transparent);border-radius:10px;padding:10px 12px;font-size:.75rem;color:#aaa;margin-bottom:10px}
/* ── Summary ── */
.sum-row{display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:.82rem}
.sum-row:last-child{border:none;font-weight:800;font-size:.9rem;color:var(--gold);padding-top:9px}
.sum-key{color:var(--muted)}
/* ── Pay Button ── */
.pay-btn{width:100%;padding:13px;border-radius:12px;border:none;cursor:pointer;font-family:'Cairo',sans-serif;font-weight:800;font-size:.93rem;background:linear-gradient(135deg,color-mix(in srgb,var(--gw) 80%,#000),var(--gw));color:#fff;transition:.3s;margin-top:11px;display:flex;align-items:center;justify-content:center;gap:8px}
.pay-btn:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 6px 20px color-mix(in srgb,var(--gw) 30%,transparent)}
.pay-btn:disabled{opacity:.45;cursor:not-allowed;transform:none}
.hidden{display:none!important}
/* ── Toast ── */
#toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(90px);background:var(--card);border:1px solid var(--gold);border-radius:14px;padding:12px 26px;font-size:.85rem;font-weight:700;z-index:9999;transition:.35s;color:var(--text);white-space:nowrap}
/* ── Responsive ── */
@media(max-width:768px){
  .wrap{grid-template-columns:1fr}
  .tx-grid{grid-template-columns:repeat(4,1fr)}
  .fld-row,.fld-row3{grid-template-columns:1fr}
}
</style>

<!-- ══ Top Bar ══ -->
<nav class="top-bar">
  <div class="top-brand"><i class="fas fa-coins"></i> DI PARMA</div>
  <div style="display:flex;align-items:center;gap:10px">
    <div class="gw-badge">
      <i class="<?=htmlspecialchars($gwIcon ?? 'fas fa-credit-card')?>"></i>
      <?=htmlspecialchars($gwName)?>
    </div>
    <div class="top-nav">
      <a href="<?=htmlspecialchars($basePath)?>dashboard.php"><i class="fas fa-th-large"></i></a>
      <a href="<?=htmlspecialchars($basePath)?>checkout_router.php"><i class="fas fa-exchange-alt"></i></a>
      <a href="<?=htmlspecialchars($basePath)?>pos/index.php"><i class="fas fa-cash-register"></i></a>
      <?php if (($gwCode ?? '') === 'paypal'): ?>
      <a href="<?=htmlspecialchars($basePath)?>holds.php" title="<?= $ar ? 'حجوزات PayPal' : 'PayPal Holds' ?>" style="color:#4DA6FF">
        <i class="fas fa-hand-holding-usd"></i> <?= $ar ? 'Holds' : 'Holds' ?>
      </a>
      <?php endif; ?>
    </div>
  </div>
</nav>

<div style="max-width:1060px;margin:14px auto;padding:0 20px">
  <a href="<?=htmlspecialchars($basePath)?>checkout_router.php" class="back-link">
    <i class="fas fa-arrow-<?=$ar?'right':'left'?>"></i>
    <?=$ar?'رجوع لاختيار البوابة':'Back to Gateway Selection'?>
  </a>
  <div style="font-size:.78rem;color:var(--muted);margin-bottom:8px">
    <?=$ar?'صفحة مستقلة لبوابة':'Dedicated gateway page:'?>
    <strong style="color:var(--gw)"><?=htmlspecialchars($gwName)?></strong>
    — <?=$ar?'كل عمليات الشراء متاحة هنا':'all purchase operations available here'?>
    · <span style="color:var(--gold);font-weight:800">ENDPOINT</span>
    <code style="color:var(--text);font-size:.72rem">POST <?=htmlspecialchars($chargeEndpoint)?></code>
    <?php if (($gwCode ?? '') === 'paypal'): ?>
      · <a href="<?=htmlspecialchars($basePath)?>holds.php" style="color:#4DA6FF;font-weight:700;text-decoration:none">
          <i class="fas fa-hand-holding-usd"></i> <?= $ar ? 'إدارة حجوزات PayPal' : 'Manage PayPal Holds' ?>
        </a>
    <?php endif; ?>
  </div>
</div>

<div class="wrap">
<div>

<!-- ══ 1. Transaction Type ══ -->
<div class="co-card">
  <div class="co-title"><i class="fas fa-sliders-h" style="color:var(--gold)"></i>
    <?=$ar?'نوع المعاملة':'Transaction Type'?>
  </div>
  <div class="tx-grid">
    <?php $i=0; foreach ($checkoutOps as $opKey => $op): $i++; ?>
    <div class="tx-btn <?=$opKey===$defaultOp?'active':''?>" id="tx_<?=htmlspecialchars($opKey)?>"
         onclick="setTx('<?=htmlspecialchars($opKey)?>',this)"
         title="<?=htmlspecialchars($ar?($op['desc_ar']??''):($op['desc_en']??''))?>">
      <i class="fas <?=htmlspecialchars($op['icon']??'fa-credit-card')?>" style="color:<?=htmlspecialchars($op['color']??'#FFD700')?>"></i>
      <span><?=htmlspecialchars($ar?($op['ar']??$opKey):($op['en']??$opKey))?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php if ($isNuveiFamily): ?>
  <div class="info-note" style="margin-top:8px">
    <i class="fas fa-link" style="color:var(--gw)"></i>
    <?=$ar
      ? 'Nuvei / DI PARMA — التحصيل عبر Nuvei. الوجهة بعد الموافقة: Ledger USDT. الحقول الإلزامية تتغير حسب نوع العملية.'
      : 'Nuvei / DI PARMA — Nuvei charges the card. After approval: Ledger USDT. Required fields change by operation type.'?>
  </div>
  <?php endif; ?>
  <div id="txDesc" class="info-note">
    <i class="fas fa-info-circle" style="color:var(--gw)"></i>
    <?php
      $first = $checkoutOps[$defaultOp] ?? reset($checkoutOps);
      echo htmlspecialchars($ar ? ($first['desc_ar'] ?? '') : ($first['desc_en'] ?? ''));
    ?>
  </div>
</div>

<!-- ══ 2. Card Section (direct2d / direct3d / online_moto) ══ -->
<div class="co-card hidden" id="cardSection">
  <div class="co-title">
    <i class="fas fa-credit-card" style="color:var(--gw)"></i>
    <?=$isDiparmaGw
      ? ($ar ? 'بطاقة → Ledger USDT' : 'Card → Ledger USDT')
      : ($isPayram ? ($ar ? 'بطاقة → كريبتو (PayRam)' : 'Card → Crypto (PayRam)') : ($ar ? 'بيانات البطاقة' : 'Card Details'))?>
    <span id="modeLabel" style="font-size:.72rem;color:var(--muted);font-weight:600;margin-<?=$ar?'right':'left'?>:auto"></span>
  </div>
  <div class="info-note" style="margin-bottom:12px">
    <i class="fas fa-credit-card" style="color:var(--gw)"></i>
    <?=htmlspecialchars($gwName)?> —
    <?=$ar
      ? 'قبول كل الشبكات والمُصدرين (فيزا، ماستركارد، أمكس، UnionPay، مدى، وغيرها). لا رفض حسب الشركة.'
      : 'All networks and issuers accepted (Visa, Mastercard, Amex, UnionPay, Mada, and any other). No brand block.'?>
  </div>
  <?php if ($isDiparmaGw): ?>
  <div class="info-note" style="margin-bottom:12px">
    <i class="fas fa-credit-card" style="color:var(--gw)"></i>
    <?=$ar
      ? 'DIPARMA GATEWAY: اسحب من البطاقة بأي عملة. الأفضل للوصول: USDT TRC20 على Ledger. لا بنك ولا IBAN كوجهة.'
      : 'DIPARMA GATEWAY: charge the card in any currency. Best arrival: USDT TRC20 on Ledger. No bank or IBAN destination.'?>
  </div>
  <?php elseif ($isPayram): ?>
  <div class="info-note" style="margin-bottom:12px">
    <i class="fas fa-info-circle" style="color:var(--gw)"></i>
    <?=$ar
      ? 'الدفع بالبطاقة عبر PayRam Onramp (Base). ستُحوَّل إلى صفحة PayRam لاختيار Cards ثم محفظة العميل — كل الشبكات والمُصدرين.'
      : 'Card payment via PayRam onramp (Base). You will open PayRam, choose Cards, then pay from the customer wallet — all networks and issuers.'?>
    <a href="https://docs.payram.com/features/card-to-crypto-fiat-onramp" target="_blank" rel="noopener"
       style="color:var(--gw);margin-<?=$ar?'right':'left'?>:6px"><?=$ar?'التوثيق':'Docs'?></a>
  </div>
  <?php endif; ?>
  <div class="fld-row">
    <div class="fld">
      <label><?=$ar?'المبلغ':'Amount'?> <span class="req">*</span></label>
      <input type="number" id="cardAmt" min="0.01" step="0.01" placeholder="0.00" oninput="calcP()"
             value="<?=!empty($prefillAmount)?htmlspecialchars((string)$prefillAmount):''?>">
    </div>
    <div class="fld">
      <label><?=$ar?'العملة':'Currency'?></label>
      <select id="cardCur" onchange="calcP()"><?=$currencyOptions?></select>
    </div>
  </div>
  <div id="localCardFields" class="<?=$isPayram?'hidden':''?>">
  <div class="req-box" style="margin-top:4px">
    <div class="req-box-title">
      <i class="fas fa-university"></i>
      <?=$ar?'الأصل والأساس والبيانات الداخلية':'Original Bank and Internal References'?>
    </div>
    <div class="fld-row">
      <div class="fld">
        <label style="color:#f0ad4e">RRN <?=$ar?'للبنك':'Bank RRN'?> <span class="opt">(<?=$ar?'اختياري':'optional'?>)</span></label>
        <input type="text" id="cardBankRrn" placeholder="12 digits" maxlength="12"
               oninput="this.value=this.value.replace(/[^0-9]/g,'')">
      </div>
      <div class="fld">
        <label style="color:#f0ad4e">APPROVAL CODE <?=$ar?'للبنك':'Bank Approval Code'?> <span class="opt">(<?=$ar?'اختياري':'optional'?>)</span></label>
        <input type="text" id="cardBankApproval" placeholder="4-6 characters" maxlength="6"
               oninput="this.value=this.value.replace(/[^0-9A-Za-z]/g,'')">
      </div>
    </div>
    <div class="fld-row">
      <div class="fld">
        <label><?=$ar?'Payment ID أو Transaction ID':'Payment ID or Transaction ID'?> <span class="opt">(<?=$ar?'اختياري':'optional'?>)</span></label>
        <input type="text" id="cardPaymentId" placeholder="PAY... / TXN...">
      </div>
      <div class="fld">
        <label><?=$ar?'Approval Code الداخلي':'Internal Approval Code'?> <span class="opt">(<?=$ar?'اختياري':'optional'?>)</span></label>
        <input type="text" id="cardInternalApproval" placeholder="Internal approval code" maxlength="64">
      </div>
    </div>
  </div>
  <div class="fld">
    <label id="lblCcNumber"><?=$ar?'رقم البطاقة — كل الشبكات والمُصدرين':'Card Number — all networks and issuers'?> <span class="req" id="reqCcNumber">*</span></label>
    <input type="text" id="ccNumber" maxlength="23" placeholder="0000 0000 0000 0000" oninput="fmtCard(this)" style="font-family:monospace;letter-spacing:1px">
  </div>
  <div class="fld-row3">
    <div class="fld">
      <label id="lblCcExpiry"><?=$ar?'تاريخ الانتهاء':'Expiry'?> <span class="req" id="reqCcExpiry">*</span></label>
      <input type="text" id="ccExpiry" maxlength="5" placeholder="MM/YY" oninput="fmtExp(this)">
    </div>
    <div class="fld">
      <label id="lblCcCvv">CVV2 / CVC2 <span class="req" id="reqCcCvv">*</span></label>
      <input type="password" id="ccCvv" maxlength="4" placeholder="•••">
    </div>
    <div class="fld">
      <label><?=$ar?'اسم حامل البطاقة':'Cardholder Name'?> <span class="opt">(<?=$ar?'اختياري':'opt'?>)</span></label>
      <input type="text" id="cardName" placeholder="<?=$ar?'الاسم كما في البطاقة':'Name as on card'?>">
    </div>
  </div>
  <div class="fld hidden" id="onlineApprovalWrap">
    <label style="color:#f0ad4e">Online Approval Code <span class="req">*</span> <span class="opt">(4 or 6 digits)</span></label>
    <input type="text" id="cardOnlineApproval" placeholder="4 or 6 digits" maxlength="6"
           oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,6)" style="font-family:monospace;letter-spacing:3px">
  </div>
  </div>
  <div class="fld-row">
    <div class="fld">
      <label>Email <?=$isPayram ? '<span class="req">*</span>' : '<span class="opt">(' . ($ar?'اختياري':'opt') . ')</span>'?></label>
      <input type="email" id="cardEmail" placeholder="example@email.com">
    </div>
    <div class="fld">
      <label><?=$ar?'الهاتف':'Phone'?> <span class="opt">(<?=$ar?'اختياري':'opt'?>)</span></label>
      <input type="tel" id="cardPhone" placeholder="+971 XX XXX XXXX">
    </div>
  </div>
  <!-- Stripe 3D element placeholder -->
  <?php if (!empty($stripeKey)): ?>
  <div id="stripeWrap" class="hidden">
    <div class="fld">
      <label><i class="fab fa-stripe-s" style="color:#6772e5"></i> 3D Secure Card</label>
      <div id="stripe-card-element" style="padding:11px 13px;background:rgba(255,255,255,.04);border:1.5px solid var(--border);border-radius:10px;min-height:42px"></div>
      <div id="stripe-error" style="color:var(--red);font-size:.73rem;margin-top:4px"></div>
    </div>
  </div>
  <?php endif; ?>
  <?php if ($hasSquareSdk): ?>
  <div id="squareWrap" class="hidden" dir="ltr">
    <div class="fld">
      <label><i class="fas fa-square" style="color:#006AFF"></i> Square Web Payments SDK</label>
      <div id="square-card-container" dir="ltr" style="padding:8px 10px;background:rgba(255,255,255,.04);border:1.5px solid var(--border);border-radius:10px;min-height:96px;width:100%"></div>
      <div id="square-error" style="color:var(--red);font-size:.73rem;margin-top:4px"></div>
      <div style="font-size:.68rem;color:var(--muted2);margin-top:6px">
        <?=!empty($squareSdk['live']) ? 'LIVE' : 'LIVE REQUIRED'?> · Square token → Square only
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- ══ 3. Capture / Offline MOTO Section ══ -->
<div class="co-card hidden" id="captureSection">
  <div class="co-title">
    <i class="fas fa-check-double" style="color:#9fe870"></i>
    <?=$ar?'بيانات التسوية / MOTO':'Capture / MOTO Details'?>
  </div>

  <!-- إلزامي -->
  <div class="req-box">
    <div class="req-box-title">
      <i class="fas fa-exclamation-circle"></i>
      <?=$ar?'إلزامي — مطلوب للتنفيذ':'Required — mandatory for execution'?>
    </div>
    <div class="fld-row">
      <div class="fld">
        <label style="color:#f0ad4e">RRN <span class="req">*</span></label>
        <input type="text" id="rrnInput" placeholder="<?=$ar?'12 رقم':'12 digits'?>" maxlength="12"
               oninput="this.value=this.value.replace(/[^0-9]/g,'')" style="font-family:monospace;letter-spacing:1px">
      </div>
      <div class="fld">
        <label style="color:#f0ad4e">Approval Code <span class="req">*</span></label>
        <input type="text" id="approvalInput" placeholder="4–6 digits" maxlength="6"
               oninput="this.value=this.value.replace(/[^0-9A-Za-z]/g,'')" style="font-family:monospace">
      </div>
    </div>
    <div class="fld-row">
      <div class="fld">
        <label><?=$ar?'Payment ID أو Transaction ID':'Payment ID or Transaction ID'?> <span class="opt">(<?=$ar?'اختياري':'optional'?>)</span></label>
        <input type="text" id="paymentIdInput" placeholder="PAY... / TXN...">
      </div>
      <div class="fld">
        <label><?=$ar?'Approval Code الداخلي':'Internal Approval Code'?> <span class="opt">(<?=$ar?'اختياري':'optional'?>)</span></label>
        <input type="text" id="internalApprovalInput" placeholder="Internal approval code" maxlength="64">
      </div>
    </div>
    <div class="fld-row">
      <div class="fld">
        <label><?=$ar?'المبلغ':'Amount'?> <span class="req">*</span></label>
        <input type="number" id="captureAmt" min="0.01" step="0.01" placeholder="0.00" oninput="calcP()">
      </div>
      <div class="fld">
        <label><?=$ar?'العملة':'Currency'?></label>
        <select id="captureCur" onchange="calcP()"><?=$motoCurrencyOptions?></select>
      </div>
    </div>
  </div>

  <!-- اختياري للمصادقة -->
  <div class="opt-box">
    <div class="opt-box-title">
      <i class="fas fa-lock-open" style="color:#888"></i>
      <?=$ar?'اختياري — للمصادقة فقط (يُرسل مشفّراً)':'Optional — for authentication only (sent encrypted)'?>
    </div>
    <div class="fld">
      <label id="lblMotoCard"><?=$ar?'رقم البطاقة':'Card Number'?> <span class="req" id="reqMotoCard">*</span></label>
      <input type="text" id="motoCardNum" maxlength="23" placeholder="0000 0000 0000 0000"
             oninput="fmtCard(this)" style="font-family:monospace;letter-spacing:1px">
    </div>
    <div class="fld-row3">
      <div class="fld">
        <label><?=$ar?'اسم حامل البطاقة':'Cardholder Name'?> <span class="opt">(<?=$ar?'اختياري':'opt'?>)</span></label>
        <input type="text" id="motoName" placeholder="<?=$ar?'الاسم كما في البطاقة':'Name as on card'?>">
      </div>
      <div class="fld">
        <label id="lblMotoExpiry"><?=$ar?'تاريخ الانتهاء':'Expiry'?> <span class="req" id="reqMotoExpiry">*</span></label>
        <input type="text" id="motoExpiry" maxlength="5" placeholder="MM/YY" oninput="fmtExp(this)">
      </div>
      <div class="fld">
        <label id="lblMotoCvv">CVV2 / CVC / CVV <span class="opt" id="optMotoCvv">(<?=$ar?'اختياري':'opt'?>)</span></label>
        <input type="password" id="motoCvv" maxlength="4" placeholder="•••">
      </div>
    </div>
    <div class="fld-row">
      <div class="fld">
        <label>Email <span class="opt">(<?=$ar?'اختياري':'opt'?>)</span></label>
        <input type="email" id="motoEmail" placeholder="example@email.com">
      </div>
      <div class="fld">
        <label><?=$ar?'الهاتف':'Phone'?> <span class="opt">(<?=$ar?'اختياري':'opt'?>)</span></label>
        <input type="tel" id="motoPhone" placeholder="+971 XX XXX XXXX">
      </div>
    </div>
  </div>
</div>

<!-- ══ 4. Refund / Avoid Section ══ -->
<div class="co-card hidden" id="refSection">
  <div class="co-title">
    <i class="fas fa-undo" style="color:#f0ad4e"></i>
    <?=$ar?'مرجع المعاملة':'Transaction Reference'?>
  </div>
  <?php if ($isNuveiFamily): ?>
  <div class="info-note">
    <?=$ar?'Nuvei/DI PARMA تتطلب RRN بطول 12 رقم للعمليات Refund / Avoid':'Nuvei/DI PARMA require a 12-digit RRN for Refund / Avoid'?>
  </div>
  <div class="fld">
    <label>RRN <span class="req">*</span> <span class="opt">(12 digits)</span></label>
    <input type="text" id="refInput" maxlength="12" placeholder="000000000000"
           oninput="this.value=this.value.replace(/\D/g,'').slice(0,12)" style="font-family:monospace;letter-spacing:2px">
  </div>
  <?php else: ?>
  <div class="fld">
    <label><?=$ar?'معرّف المعاملة الأصلية':'Original Transaction ID'?> <span class="req">*</span></label>
    <input type="text" id="refInput" placeholder="ORD... / TXN... / pi_...">
  </div>
  <?php endif; ?>
  <div class="fld-row" id="refAmtRow">
    <div class="fld">
      <label><?=$ar?'مبلغ الاسترداد':'Refund Amount'?> <span class="opt">(<?=$ar?'اختياري — كامل إذا فارغ':'opt — full if empty'?>)</span></label>
      <input type="number" id="refAmt" min="0.01" step="0.01" placeholder="0.00" oninput="calcP()">
    </div>
    <div class="fld">
      <label><?=$ar?'العملة':'Currency'?></label>
      <select id="refCur" onchange="calcP()"><?=$currencyOptions?></select>
    </div>
  </div>
</div>

<!-- ══ Withdrawal charge mode ══ -->
<div class="co-card hidden" id="withdrawSection">
  <div class="co-title">
    <i class="fas fa-hand-holding-usd" style="color:#14B8A6"></i>
    <?=$ar?'سحب عبر واجهة النظام':'System UI Withdrawal'?>
  </div>
  <div class="info-note">
    <?=$ar?'لا Direct لبوابات خارجية — قبول كل أنواع الكروت والشركات على كل بوابة':'No direct external checkout — all card types and issuers on every gateway'?>
  </div>
  <div class="fld">
    <label><?=$ar?'وضع التنفيذ':'Charge mode'?></label>
    <select id="chargeMode">
      <?php foreach (pos_withdrawal_charge_modes() as $mk => $mm): ?>
      <option value="<?=htmlspecialchars($mk)?>"><?=htmlspecialchars($ar?$mm['ar']:$mm['en'])?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="fld-row">
    <div class="fld">
      <label>RRN (12)</label>
      <input type="text" id="wdRrn" maxlength="12" placeholder="000000000000" oninput="this.value=this.value.replace(/\D/g,'').slice(0,12)">
    </div>
    <div class="fld">
      <label>Approval</label>
      <input type="text" id="wdApproval" maxlength="6" placeholder="digits" oninput="this.value=this.value.replace(/\D/g,'')">
    </div>
  </div>
  <div class="fld-row">
    <div class="fld">
      <label><?=$ar?'المبلغ':'Amount'?></label>
      <input type="number" id="wdAmt" min="0.01" step="0.01" placeholder="0.00" oninput="calcP()"
             value="<?=!empty($prefillAmount)?htmlspecialchars((string)$prefillAmount):''?>">
    </div>
    <div class="fld">
      <label><?=$ar?'العملة':'Currency'?></label>
      <select id="wdCur" onchange="calcP()"><?=$currencyOptions?></select>
    </div>
  </div>
</div>

<!-- ══ Bank Info (إن وُجدت) ══ -->
<?php if (!empty($bankInfo)): ?>
<div class="co-card">
  <div class="co-title">
    <i class="fas fa-university" style="color:var(--gw)"></i>
    <?=$ar?'معلومات الحساب البنكي':'Bank Account Information'?>
  </div>
  <?php foreach ($bankInfo as $key => $val): ?>
  <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:.84rem">
    <span style="color:var(--muted);font-size:.76rem"><?=htmlspecialchars($key)?></span>
    <span style="font-weight:700;display:flex;align-items:center;gap:8px">
      <?=htmlspecialchars($val)?>
      <button onclick="copyText('<?=htmlspecialchars($val)?>','<?=$ar?'نُسخ':'Copied'?>')"
              style="background:rgba(255,215,0,.1);border:1px solid rgba(255,215,0,.2);border-radius:7px;padding:2px 9px;cursor:pointer;font-size:.68rem;color:var(--gold)">
        <i class="fas fa-copy"></i>
      </button>
    </span>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

</div><!-- /col-left -->

<!-- ══ Summary Sidebar ══ -->
<div>
  <div class="co-card" style="position:sticky;top:76px">
    <div class="co-title"><i class="fas fa-receipt" style="color:var(--gold)"></i> Summary</div>
    <div class="sum-row">
      <span class="sum-key">Gateway</span>
      <span style="color:var(--gw)"><?=htmlspecialchars($gwName)?></span>
    </div>
    <div class="sum-row">
      <span class="sum-key"><?=$ar?'النوع':'Type'?></span>
      <span id="sumType" style="color:var(--gold)"><?=$ar?'سحب مباشر 2D':'Direct 2D'?></span>
    </div>
    <div class="sum-row">
      <span class="sum-key"><?=$ar?'المبلغ':'Amount'?></span>
      <span id="sumAmt">—</span>
    </div>
    <div class="sum-row">
      <span class="sum-key">Fee (10%)</span>
      <span id="sumFee" style="color:var(--red)">—</span>
    </div>
    <div class="sum-row">
      <span class="sum-key">Net</span>
      <span id="sumNet" style="color:var(--green)">—</span>
    </div>
    <button class="pay-btn" id="payBtn" onclick="go()">
      <i class="fas fa-bolt"></i>
      <span id="payBtnLabel"><?=$ar?'سحب مباشر':'Direct Charge'?></span>
    </button>
    <div style="text-align:center;margin-top:10px;font-size:.68rem;color:var(--muted)">
      <i class="fas fa-shield-alt" style="color:var(--green)"></i>
      <?=$ar?'محمي بتشفير TLS 1.3':'Protected by TLS 1.3 encryption'?>
    </div>
  </div>
</div>
</div><!-- /wrap -->

<div id="toast"></div>

<script>
var CSRF = '<?=htmlspecialchars($csrfToken)?>';
var CHARGE_ENDPOINT = <?=json_encode($chargeEndpoint, JSON_UNESCAPED_UNICODE)?>;
async function readJson(res) {
  var t = await res.text();
  t = String(t || '').replace(/[\uFEFF\u200B\u200C\u200D]/g, '').replace(/^\s+/, '');
  if (!t) throw new Error('Empty response from ' + CHARGE_ENDPOINT);
  try { return JSON.parse(t); }
  catch (e) { throw new Error('Invalid JSON from ' + CHARGE_ENDPOINT); }
}
function failMessage(d) {
  if (d && d.errors && d.errors.length) return d.errors.join(' · ');
  return (d && d.message) || 'Failed';
}
function luhnCheck(num) {
  var d = String(num || '').replace(/\D/g, '');
  if (d.length < 13 || d.length > 19) return false;
  var sum = 0, alt = false;
  for (var i = d.length - 1; i >= 0; i--) {
    var n = parseInt(d.charAt(i), 10);
    if (alt) { n *= 2; if (n > 9) n -= 9; }
    sum += n;
    alt = !alt;
  }
  return sum % 10 === 0;
}
var GW   = '<?=htmlspecialchars($gwCode)?>';
var CHARGE_GW = '<?=htmlspecialchars((string)($chargeGwCode ?? $gwCode))?>';
var BASE = '<?=htmlspecialchars($basePath)?>';
var DEST = '<?=htmlspecialchars($prefillDest ?? 'ledger')?>';
var WALLET = '<?=htmlspecialchars(($prefillWallet ?? '') !== '' ? $prefillWallet : (defined('LEDGER_TRC20_ADDRESS') ? LEDGER_TRC20_ADDRESS : ''))?>';
var REF  = '<?=htmlspecialchars($prefillRef ?? '')?>';
var CHECKOUT_CHANNEL = '<?=htmlspecialchars($checkoutChannel ?? 'checkout')?>';
var PREFILL_LINK = '<?=htmlspecialchars($prefillLink ?? '')?>';
var IS_NUVEI = <?= $isNuveiFamily ? 'true' : 'false' ?>;
var IS_LEDGER = false;
var OPS  = <?=json_encode($checkoutOps, JSON_UNESCAPED_UNICODE)?>;
var CHARGE_MODES = <?=json_encode(pos_withdrawal_charge_modes(), JSON_UNESCAPED_UNICODE)?>;
var curTx = '<?=htmlspecialchars($defaultOp)?>';
var stripe = null, stripeEl = null;
<?php if (!empty($stripeKey)): ?>
stripe = Stripe('<?=addslashes($stripeKey)?>');
<?php endif; ?>
var SQUARE_CFG = <?=json_encode([
    'enabled' => !empty($hasSquareSdk),
    'application_id' => $squareSdk['application_id'] ?? '',
    'location_id' => $squareSdk['location_id'] ?? '',
    'live' => !empty($squareSdk['live']),
], JSON_UNESCAPED_UNICODE)?>;
var squareBooted = false;

var TX_LABELS = {};
var TX_DESC = {};
Object.keys(OPS).forEach(function(k){
  TX_LABELS[k] = <?= $ar ? 'OPS[k].ar' : 'OPS[k].en' ?>;
  TX_DESC[k] = <?= $ar ? 'OPS[k].desc_ar' : 'OPS[k].desc_en' ?> || k;
});

function applyFieldRequirements(meta) {
  meta = meta || {};
  var squareHosted = typeof usesSquareHostedCard === 'function' && usesSquareHostedCard();
  var mark = function(id, on) {
    var el = document.getElementById(id);
    if (el) el.style.display = on ? '' : 'none';
  };
  mark('reqCcNumber', !squareHosted && meta.requires_card !== false);
  mark('reqCcExpiry', !squareHosted && !!meta.requires_expiry);
  mark('reqCcCvv', !squareHosted && !!meta.requires_cvv);
  mark('reqMotoCard', !squareHosted && !!meta.requires_card);
  mark('reqMotoExpiry', !squareHosted && !!meta.requires_expiry);
}

function setTx(type, el) {
  curTx = type;
  document.querySelectorAll('.tx-btn').forEach(function(b){b.classList.remove('active');});
  if (el) el.classList.add('active');
  document.getElementById('txDesc').textContent = '';
  var txIcon = document.createElement('i');
  txIcon.className = 'fas fa-info-circle';
  txIcon.style.color = 'var(--gw)';
  document.getElementById('txDesc').appendChild(txIcon);
  document.getElementById('txDesc').appendChild(document.createTextNode(' ' + (TX_DESC[type]||type)));
  document.getElementById('sumType').textContent = TX_LABELS[type]||type;
  document.getElementById('payBtnLabel').textContent = TX_LABELS[type]||type;

  var cardSec    = document.getElementById('cardSection');
  var captureSec = document.getElementById('captureSection');
  var refSec     = document.getElementById('refSection');
  var wdSec      = document.getElementById('withdrawSection');
  var modeLabel  = document.getElementById('modeLabel');
  var meta       = OPS[type] || {};

  [cardSec, captureSec, refSec, wdSec].forEach(function(s){ if(s) s.classList.add('hidden'); });

  if (type === 'withdrawal_pos' || type === 'withdrawal_nfc') {
    wdSec.classList.remove('hidden');
    cardSec.classList.remove('hidden');
    modeLabel.textContent = type === 'withdrawal_nfc' ? 'NFC' : 'POS';
    applyFieldRequirements(meta);
  } else if (type === 'purchase' || type === 'purchase_2d' || type === 'purchase_3d' || type === 'online_sale_moto' || type === 'auth') {
    cardSec.classList.remove('hidden');
    modeLabel.textContent = type === 'purchase' ? 'Card → Crypto · Base'
      : (type === 'online_sale_moto' ? 'MOTO · Approval 4 or 6'
      : (type === 'purchase_3d' ? '3D OTP' : (type === 'auth' ? 'AUTH HOLD' : '2D')));
    var sw = document.getElementById('stripeWrap');
    if (sw) {
      if (type === 'purchase_3d' && stripe) { sw.classList.remove('hidden'); initStripe(); }
      else sw.classList.add('hidden');
    }
    var localFields = document.getElementById('localCardFields');
    if (localFields) {
      if ((CHARGE_GW || GW) === 'square' && SQUARE_CFG.enabled) localFields.classList.add('hidden');
      else if (typeof GW !== 'undefined' && GW !== 'payram') localFields.classList.remove('hidden');
    }
    var sq = document.getElementById('squareWrap');
    if (sq) {
      if ((CHARGE_GW || GW) === 'square' && SQUARE_CFG.enabled && ['purchase_2d','purchase_3d','online_sale_moto','offline_sale_moto','auth','purchase','purchase_advice'].indexOf(type) >= 0) {
        sq.classList.remove('hidden');
        initSquareSdk();
      } else {
        sq.classList.add('hidden');
      }
    }
    var onlineAp = document.getElementById('onlineApprovalWrap');
    if (onlineAp) {
      if (type === 'online_sale_moto') onlineAp.classList.remove('hidden');
      else onlineAp.classList.add('hidden');
    }
    applyFieldRequirements(meta);
  } else if (type === 'capture' || type === 'purchase_advice' || type === 'offline_sale_moto') {
    captureSec.classList.remove('hidden');
    var ap = document.getElementById('approvalInput');
    var rrn = document.getElementById('rrnInput');
    if (rrn) {
      rrn.maxLength = 12;
      rrn.placeholder = '12 digits';
      var rrnReq = rrn.closest('.fld');
      if (rrnReq) rrnReq.style.opacity = (meta.requires_rrn || type !== 'offline_sale_moto') ? '1' : '.5';
    }
    if (ap) {
      ap.maxLength = 6;
      ap.placeholder = '4 or 6 digits';
    }
    applyFieldRequirements(meta);
  } else if (type === 'refund' || type === 'avoid') {
    refSec.classList.remove('hidden');
    var rar = document.getElementById('refAmtRow');
    if (rar) rar.style.display = type === 'refund' ? '' : 'none';
  } else {
    cardSec.classList.remove('hidden');
    applyFieldRequirements(meta);
  }
  calcP();
}

function initStripe() {
  if (stripeEl || !stripe) return;
  var els = stripe.elements();
  stripeEl = els.create('card', {
    style: {base:{color:'#fff',fontFamily:'Cairo,sans-serif',fontSize:'15px','::placeholder':{color:'#888'}},invalid:{color:'#ef5350'}},
    hidePostalCode: true
  });
  stripeEl.mount('#stripe-card-element');
  stripeEl.on('change', function(e){
    document.getElementById('stripe-error').textContent = e.error ? e.error.message : '';
  });
}

async function initSquareSdk() {
  if (!SQUARE_CFG.enabled || !window.DiparmaSquareSdk) return false;
  if (window.DiparmaSquareSdk.isReady()) { squareBooted = true; return true; }
  var errEl = document.getElementById('square-error');
  var ok = await DiparmaSquareSdk.init(SQUARE_CFG.application_id, SQUARE_CFG.location_id, '#square-card-container');
  squareBooted = !!ok;
  if (errEl) errEl.textContent = ok ? '' : (DiparmaSquareSdk.lastError() || 'Square SDK init failed');
  return ok;
}

function squareChargeTypes() {
  return ['purchase_2d','purchase_3d','online_sale_moto','offline_sale_moto','auth','purchase','purchase_advice'];
}
function isSquareSandboxNonce(tok) {
  var t = String(tok || '').trim().toLowerCase();
  return t.indexOf('cnon:card-nonce') === 0;
}
function squareTokenFrom(obj) {
  if (!obj) return '';
  return String(obj.source_id || obj.cloud_token || obj.payment_token || '').trim();
}
function hasLiveSquareToken(obj) {
  var t = squareTokenFrom(obj);
  return t.length >= 8 && !isSquareSandboxNonce(t);
}
function usesSquareHostedCard() {
  return (CHARGE_GW || GW) === 'square' && SQUARE_CFG.enabled && squareChargeTypes().indexOf(curTx) >= 0;
}

async function attachSquareCheckoutToken(payload) {
  if (!usesSquareHostedCard()) return { ok: true };
  if (!window.DiparmaSquareSdk) {
    return { ok: false, message: 'Square Web Payments SDK is not loaded' };
  }
  if (!DiparmaSquareSdk.isReady()) {
    await initSquareSdk();
  }
  var tok = await DiparmaSquareSdk.tokenize();
  if (!tok.success) {
    var se = document.getElementById('square-error');
    if (se) se.textContent = tok.message || 'Square tokenize failed';
    return { ok: false, message: tok.message || 'Square tokenize failed' };
  }
  if (isSquareSandboxNonce(tok.token)) {
    return { ok: false, message: 'Square simulation nonce is rejected. Use a real card.' };
  }
  payload.cloud_token = tok.token;
  payload.payment_token = tok.token;
  payload.source_id = tok.token;
  payload.card_type = 'CLOUD';
  payload.card_number = '';
  payload.cc_number = '';
  payload.card_cvv = '';
  payload.cc_cvv = '';
  payload.card_expiry = '';
  payload.cc_expiry = '';
  return { ok: true };
}

function calcP() {
  var a = parseFloat(
    document.getElementById('cardAmt')?.value ||
    document.getElementById('captureAmt')?.value ||
    document.getElementById('refAmt')?.value ||
    document.getElementById('wdAmt')?.value
  ) || 0;
  var c = document.getElementById('cardCur')?.value ||
          document.getElementById('captureCur')?.value ||
          document.getElementById('refCur')?.value ||
          document.getElementById('wdCur')?.value || 'USD';
  if (!a) {
    ['sumAmt','sumFee','sumNet'].forEach(function(id){
      var e = document.getElementById(id); if(e) e.textContent='—';
    });
    return;
  }
  var fee = (a * 0.10).toFixed(2);
  var net = (a - parseFloat(fee)).toFixed(2);
  document.getElementById('sumAmt').textContent = a.toFixed(2) + ' ' + c;
  document.getElementById('sumFee').textContent = fee + ' ' + c;
  document.getElementById('sumNet').textContent = net + ' ' + c;
}

function detectCardNetwork(pan) {
  var n = String(pan || '').replace(/\D/g,'');
  if (!n) return 'other';
  var i2 = parseInt(n.slice(0,2), 10);
  var i3 = parseInt(n.slice(0,3), 10);
  var i4 = parseInt(n.slice(0,4), 10);
  var i6 = n.slice(0,6);
  if (n.startsWith('4')) {
    if (['4026','4175','4405','4508','4844','4913','4917'].indexOf(n.slice(0,4)) >= 0) return 'visa_electron';
    return 'visa';
  }
  if ((i2 >= 51 && i2 <= 55) || (i4 >= 2221 && i4 <= 2720)) return 'mastercard';
  if (n.startsWith('34') || n.startsWith('37')) return 'amex';
  if (n.startsWith('62') || n.startsWith('81')) return 'unionpay';
  if (i4 >= 3528 && i4 <= 3589) return 'jcb';
  if (n.startsWith('6011') || n.startsWith('65') || (i3 >= 644 && i3 <= 649)) return 'discover';
  if (n.startsWith('36') || n.startsWith('38') || (i3 >= 300 && i3 <= 305)) return 'diners';
  if (i4 >= 2200 && i4 <= 2204) return 'mir';
  if (n.startsWith('60') || n.startsWith('82')) return 'rupay';
  if (['636368','438935','504175'].indexOf(i6) >= 0) return 'elo';
  if (n.startsWith('606282') || n.startsWith('3841')) return 'hipercard';
  if (n.startsWith('9792')) return 'troy';
  if (n.startsWith('506') || n.startsWith('650002')) return 'verve';
  if (n.startsWith('588845') || n.startsWith('9682')) return 'mada';
  if (n.startsWith('5078') || n.startsWith('5079')) return 'meeza';
  if (n.startsWith('888822')) return 'knet';
  if (n.startsWith('6703')) return 'bancontact';
  if (n.startsWith('9704')) return 'napas';
  if (n.startsWith('2205') || n.startsWith('5868')) return 'paypak';
  if (n.startsWith('5081')) return 'jaywan';
  if (n.startsWith('4571')) return 'dankort';
  if (i2 === 1) return 'uatp';
  if (i2 >= 50 && i2 <= 69) return 'maestro';
  return 'other';
}

function fmtCard(el) {
  var v = el.value.replace(/\D/g,'').substring(0,19);
  el.value = v.replace(/(.{4})/g,'$1 ').trim();
}
function fmtExp(el) {
  var v = el.value.replace(/\D/g,'');
  if (v.length >= 2) v = v.substring(0,2) + '/' + v.substring(2,4);
  el.value = v;
}
function copyText(txt, msg) {
  if (navigator.clipboard) navigator.clipboard.writeText(txt).then(function(){ showToast(msg,'success'); });
  else { var t=document.createElement('textarea');t.value=txt;document.body.appendChild(t);t.select();document.execCommand('copy');document.body.removeChild(t);showToast(msg,'success'); }
}

async function go() {
  var btn = document.getElementById('payBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

  var payload = { card_provider: GW, payment_type: curTx, csrf_token: CSRF };

  // PayRam Card-to-Crypto: إنشاء رابط ثم فتح صفحة PayRam (Cards على Base)
  if (GW === 'payram') {
    var pAmt = parseFloat(document.getElementById('cardAmt').value) || 0;
    var pEmail = (document.getElementById('cardEmail').value || '').trim();
    if (pAmt <= 0) { showToast('<?=$ar?'أدخل مبلغاً صحيحاً':'Enter valid amount'?>','error'); btn.disabled=false; resetBtn(); return; }
    if (!pEmail) { showToast('<?=$ar?'البريد مطلوب لمحفظة PayRam':'Email required for PayRam Wallet'?>','error'); btn.disabled=false; resetBtn(); return; }
    payload = {
      action: 'create',
      amount: pAmt,
      email: pEmail,
      customer_id: 'dp_' + Date.now(),
      txn_type: 'purchase',
      blockchain_code: 'BASE',
      currency_code: 'USDC',
      reference: REF || '',
      csrf_token: CSRF
    };
    try {
      var pr = await fetch(BASE + 'api/payram_payment.php', {
        method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)
      });
      var pd = await readJson(pr);
      if (!pd.success) { showToast(pd.message||'Failed','error'); btn.disabled=false; resetBtn(); return; }
      if (!pd.url) { showToast('<?=$ar?'لم يُرجع PayRam رابط دفع':'PayRam did not return a payment URL'?>','error'); btn.disabled=false; resetBtn(); return; }
      showToast('<?=$ar?'فتح صفحة PayRam — اختر Cards':'Opening PayRam — choose Cards'?>','success');
      setTimeout(function(){ window.location.href = pd.url; }, 600);
    } catch(err) {
      showToast('Error: '+err.message,'error');
      btn.disabled=false; resetBtn();
    }
    return;
  }

  if (usesSquareHostedCard()) {
    var amtPreEl = document.getElementById('cardAmt') || document.getElementById('captureAmt');
    var amtPre = parseFloat(amtPreEl && amtPreEl.value) || 0;
    if (amtPre <= 0) {
      showToast('<?=$ar?'أدخل مبلغاً صحيحاً':'Enter valid amount'?>','error'); btn.disabled=false; resetBtn(); return;
    }
    var sqAttach = await attachSquareCheckoutToken(payload);
    if (!sqAttach.ok) {
      showToast(sqAttach.message, 'error');
      btn.disabled=false; resetBtn(); return;
    }
  }
  var squareTokPresent = hasLiveSquareToken(payload);

  if (curTx === 'purchase_2d' || curTx === 'purchase_3d' || curTx === 'auth') {
    var amt = parseFloat(document.getElementById('cardAmt').value) || 0;
    if (amt <= 0) { showToast('<?=$ar?'أدخل مبلغاً صحيحاً':'Enter valid amount'?>','error'); btn.disabled=false; resetBtn(); return; }
    var cc = (document.getElementById('ccNumber')?.value || '').replace(/\s/g,'');
    if (!squareTokPresent && !cc) { showToast('<?=$ar?'رقم البطاقة مطلوب':'Card number required'?>','error'); btn.disabled=false; resetBtn(); return; }
    if (!squareTokPresent && cc && !luhnCheck(cc)) { showToast('<?=$ar?'رقم البطاقة غير صالح':'Invalid card number'?>','error'); btn.disabled=false; resetBtn(); return; }
    var metaP = OPS[curTx] || {};
    var exp  = (document.getElementById('ccExpiry')?.value || '').trim();
    var cvv  = (document.getElementById('ccCvv')?.value || '').trim();
    if (!squareTokPresent && metaP.requires_expiry && !/^(0[1-9]|1[0-2])\/[0-9]{2}$/.test(exp)) {
      showToast('<?=$ar?'تاريخ الانتهاء مطلوب (MM/YY)':'Expiry required (MM/YY)'?>','error'); btn.disabled=false; resetBtn(); return;
    }
    if (!squareTokPresent && metaP.requires_cvv && !cvv) {
      showToast('CVV required','error'); btn.disabled=false; resetBtn(); return;
    }
    payload.amount   = amt;
    payload.currency = document.getElementById('cardCur').value;
    payload.email    = document.getElementById('cardEmail').value.trim() || 'guest@diparmas.com';
    if (!squareTokPresent) {
      payload.cc_number = cc;
      payload.card_number = cc;
    }
    var bankRrn = document.getElementById('cardBankRrn').value.trim();
    var bankApproval = document.getElementById('cardBankApproval').value.trim();
    var paymentId = document.getElementById('cardPaymentId').value.trim();
    var internalApproval = document.getElementById('cardInternalApproval').value.trim();
    if (bankRrn) payload.bank_rrn = bankRrn;
    if (bankApproval) payload.bank_approval_code = bankApproval;
    if (paymentId) {
      payload.payment_id = paymentId;
      payload.transaction_id = paymentId;
    }
    if (internalApproval) payload.internal_approval_code = internalApproval;
    var name = document.getElementById('cardName').value.trim();
    var ph   = document.getElementById('cardPhone').value.trim();
    if (!squareTokPresent && exp)  { payload.cc_expiry = exp; payload.card_expiry = exp; }
    if (!squareTokPresent && cvv)  { payload.cc_cvv = cvv; payload.card_cvv = cvv; }
    if (name) payload.name = name;
    if (ph)   payload.phone = ph;
    payload.security_mode = curTx === 'purchase_3d' ? '3D' : '2D';
    payload.processing_mode = payload.security_mode;
    payload.txn_type = curTx;
    payload.card_network = squareTokPresent ? (payload.card_network || 'other') : detectCardNetwork(cc);
    if (curTx === 'auth') {
      payload.payment_type = 'auth';
    }

  } else if (curTx === 'capture' || curTx === 'purchase_advice' || curTx === 'offline_sale_moto') {
    var rrn  = document.getElementById('rrnInput').value.trim().replace(/\D/g,'');
    var apco = document.getElementById('approvalInput').value.trim().replace(/\D/g,'');
    var ma   = parseFloat(document.getElementById('captureAmt').value) || 0;
    var mn   = document.getElementById('motoCardNum').value.replace(/\s/g,'');
    var mexp = document.getElementById('motoExpiry').value.trim();
    if (curTx === 'capture' || curTx === 'purchase_advice') {
      if (!/^\d{12}$/.test(rrn)) { showToast('RRN must be 12 digits','error'); btn.disabled=false; resetBtn(); return; }
    }
    if (curTx === 'offline_sale_moto') {
      if (!/^\d{4}$|^\d{6}$/.test(apco)) { showToast('Offline Approval must be 4 or 6 digits','error'); btn.disabled=false; resetBtn(); return; }
    } else if (!apco || (apco.length !== 4 && apco.length !== 6 && apco.length < 4)) {
      showToast('Approval Code must be 4 or 6 digits','error'); btn.disabled=false; resetBtn(); return;
    }
    if (ma <= 0){ showToast('<?=$ar?'أدخل مبلغاً صحيحاً':'Enter valid amount'?>','error'); btn.disabled=false; resetBtn(); return; }
    if (!squareTokPresent && (!mn || !mexp)) {
      showToast('<?=$ar?'رقم البطاقة وتاريخ الانتهاء مطلوبان':'Card number and expiry required'?>','error');
      btn.disabled=false; resetBtn(); return;
    }
    payload.rrn           = rrn;
    payload.orig_ref      = rrn;
    payload.approval_code = apco;
    payload.bank_rrn      = rrn;
    payload.bank_approval_code = apco;
    payload.payment_id = document.getElementById('paymentIdInput').value.trim();
    payload.transaction_id = payload.payment_id;
    payload.internal_approval_code = document.getElementById('internalApprovalInput').value.trim();
    payload.amount        = ma;
    payload.currency      = document.getElementById('captureCur').value;
    payload.protocol      = '201.3';
    payload.security_mode = '2D';
    payload.moto_type     = 'MOTO';
    payload.txn_type      = curTx;
    payload.linked_to_auth = (curTx === 'capture');
    if (!squareTokPresent) {
      payload.cc_number = mn;
      payload.card_number = mn;
      payload.cc_expiry = mexp;
      payload.card_expiry = mexp;
    }
    var mcvv = document.getElementById('motoCvv').value.trim();
    var mnam = document.getElementById('motoName').value.trim();
    var meml = document.getElementById('motoEmail').value.trim();
    var mph  = document.getElementById('motoPhone').value.trim();
    if (!squareTokPresent && mcvv) { payload.cc_cvv = mcvv; payload.card_cvv = mcvv; }
    if (mnam) payload.name = mnam;
    if (meml) payload.email = meml;
    if (mph)  payload.phone = mph;

  } else if (curTx === 'online_sale_moto') {
    var amt = parseFloat(document.getElementById('cardAmt').value) || 0;
    var cc = (document.getElementById('ccNumber')?.value || '').replace(/\s/g,'');
    var apOnline = (document.getElementById('cardOnlineApproval')?.value || document.getElementById('cardBankApproval')?.value || '').replace(/\D/g,'');
    var expO = (document.getElementById('ccExpiry')?.value || '').trim();
    var cvvO = (document.getElementById('ccCvv')?.value || '').trim();
    if (amt <= 0) { showToast('<?=$ar?'أدخل مبلغاً صحيحاً':'Enter valid amount'?>','error'); btn.disabled=false; resetBtn(); return; }
    if (!squareTokPresent && !cc) { showToast('<?=$ar?'رقم البطاقة مطلوب':'Card number required'?>','error'); btn.disabled=false; resetBtn(); return; }
    if (!squareTokPresent && !/^(0[1-9]|1[0-2])\/[0-9]{2}$/.test(expO)) { showToast('Expiry required (MM/YY)','error'); btn.disabled=false; resetBtn(); return; }
    if (!squareTokPresent && !cvvO) { showToast('CVV required','error'); btn.disabled=false; resetBtn(); return; }
    if (!/^\d{4}$|^\d{6}$/.test(apOnline)) { showToast('Online Approval must be 4 or 6 digits','error'); btn.disabled=false; resetBtn(); return; }
    payload.amount = amt;
    payload.currency = document.getElementById('cardCur').value;
    if (!squareTokPresent) {
      payload.cc_number = cc;
      payload.card_number = cc;
      payload.cc_expiry = expO;
      payload.card_expiry = expO;
      payload.cc_cvv = cvvO;
      payload.card_cvv = cvvO;
    }
    payload.approval_code = apOnline;
    payload.bank_approval_code = apOnline;
    payload.txn_type = 'online_sale_moto';
    payload.security_mode = '2D';
    payload.moto_type = 'MOTO';
    payload.moto_channel = 'online';
    payload.email = document.getElementById('cardEmail').value.trim() || 'guest@diparmas.com';

  } else if (curTx === 'refund' || curTx === 'avoid') {
    var ref = document.getElementById('refInput').value.trim();
    if (!ref) { showToast('<?=$ar?'أدخل معرّف المعاملة':'Enter transaction ID'?>','error'); btn.disabled=false; resetBtn(); return; }
    if (IS_NUVEI && !/^\d{12}$/.test(ref.replace(/\D/g,''))) {
      showToast('RRN must be 12 digits','error'); btn.disabled=false; resetBtn(); return;
    }
    var refClean = IS_NUVEI ? ref.replace(/\D/g,'') : ref;
    payload.refund_reference = refClean;
    payload.orig_ref = refClean;
    payload.rrn = refClean;
    payload.txn_type = curTx;
    payload.amount = parseFloat(document.getElementById('refAmt')?.value) || 0;
    payload.currency = document.getElementById('refCur')?.value || 'USD';

  } else if (curTx === 'withdrawal_pos' || curTx === 'withdrawal_nfc') {
    var wAmt = parseFloat(document.getElementById('wdAmt')?.value || document.getElementById('cardAmt')?.value) || 0;
    var wCc = document.getElementById('ccNumber').value.replace(/\s/g,'');
    var mode = document.getElementById('chargeMode')?.value || '';
    var wExp = document.getElementById('ccExpiry').value.trim();
    if (!mode) { showToast('<?=$ar?'اختر وضع التنفيذ':'Select charge mode'?>','error'); btn.disabled=false; resetBtn(); return; }
    if (wAmt <= 0) { showToast('<?=$ar?'أدخل مبلغاً صحيحاً':'Enter valid amount'?>','error'); btn.disabled=false; resetBtn(); return; }
    if (!wCc) { showToast('<?=$ar?'رقم البطاقة مطلوب':'Card number required'?>','error'); btn.disabled=false; resetBtn(); return; }
    if (!/^(0[1-9]|1[0-2])\/[0-9]{2}$/.test(wExp)) { showToast('Expiry required (MM/YY)','error'); btn.disabled=false; resetBtn(); return; }
    payload.txn_type = curTx;
    payload.charge_mode = mode;
    payload.amount = wAmt;
    payload.currency = document.getElementById('wdCur')?.value || document.getElementById('cardCur').value;
    payload.cc_number = wCc;
    payload.card_number = wCc;
    payload.cc_expiry = wExp;
    payload.card_expiry = wExp;
    payload.cc_cvv = document.getElementById('ccCvv').value.trim();
    payload.card_cvv = payload.cc_cvv;
    payload.orig_ref = document.getElementById('wdRrn')?.value || '';
    payload.rrn = payload.orig_ref;
    payload.approval_code = document.getElementById('wdApproval')?.value || '';
    payload.extra = { charge_mode: mode, channel: 'system_pos', scheme_route: detectCardNetwork((document.getElementById('ccNumber')||{}).value || '') };
  }

  payload.destination = DEST || 'ledger';
  payload.ledger_address = WALLET || <?=json_encode(defined('LEDGER_TRC20_ADDRESS') ? LEDGER_TRC20_ADDRESS : '')?>;
  payload.auto_transfer = true;
  if (REF) payload.reference = REF;
  payload.gateway = CHARGE_GW || GW;
  payload.card_provider = CHARGE_GW || GW;
  payload.protocol = '201.3';
  payload.allow_fallback = false;
  payload.channel = CHECKOUT_CHANNEL || 'checkout';
  payload.pos_device = payload.pos_device || 'web_pos';
  payload.pos_model = payload.pos_model || 'web_pos';
  payload.pos_type = 'web';
  if (PREFILL_LINK) {
    payload.link_id = PREFILL_LINK;
    payload.channel = 'link';
    payload.extra = Object.assign({}, payload.extra || {}, { link_id: PREFILL_LINK, channel: 'link' });
  } else {
    payload.extra = Object.assign({}, payload.extra || {}, {
      channel: payload.channel,
      processing_mode: payload.processing_mode || payload.security_mode || '',
      txn_type: payload.txn_type || curTx
    });
  }

  try {
    var bankGateways = {mashreq:1, hsbc_uae:1, nbe_egypt:1, jpmorgan:1};
    // MY POS pipe: CHECKOUT / LINK / WEB → same as POS devices
    var posPipeGateways = {
      nuvei:1, diparma:1, square:1, stripe:1, paypal:1,
      gate_io:1, binance:1, whop:1
    };
    var apiUrl = CHARGE_ENDPOINT || (BASE + 'api/checkout_charge.php');
    var pipeGw = CHARGE_GW || GW;

    if (GW === 'diparma_gateway' && !CHARGE_GW) {
      showToast(<?=json_encode($ar ? 'فعّل بوابة دفع للخصم. DIPARMA GATEWAY للتسوية إلى Ledger فقط.' : 'Enable a charge gateway. DIPARMA GATEWAY is Ledger settlement only.')?>, 'error');
      btn.disabled=false; resetBtn(); return;
    }

    // كل بوابة على مسارها فقط — بدون تحويل لبوابة أخرى
    if (pipeGw === 'wise' || GW === 'wise') {
      apiUrl = BASE + 'api/wise_payment.php';
    } else if (pipeGw === 'payram' || GW === 'payram') {
      apiUrl = BASE + 'api/payram_payment.php';
    } else if (posPipeGateways[pipeGw]) {
      apiUrl = CHARGE_ENDPOINT || (BASE + 'api/checkout_charge.php');
    } else if (bankGateways[GW] || bankGateways[pipeGw]) {
      showToast(<?=json_encode($ar ? 'التحويل البنكي يدوي — استخدم تعليمات الحساب من الصفحة' : 'Bank transfer is manual — use on-page account instructions')?>, 'error');
      btn.disabled=false; resetBtn(); return;
    } else {
      // بوابات غير مدعومة على أنبوب POS (مثل myfatoorah)
      apiUrl = BASE + 'api/orchestrator.php?action=initiate';
    }

    // السحب عبر POS فقط عندما تكون بوابة البطاقة Nuvei/DI PARMA
    if (curTx === 'withdrawal_pos' || curTx === 'withdrawal_nfc') {
      if (pipeGw !== 'nuvei' && pipeGw !== 'diparma') {
        showToast(<?=json_encode($ar ? 'السحب متاح عبر Nuvei/DI PARMA فقط' : 'Withdrawals are available via Nuvei/DI PARMA only')?>, 'error');
        btn.disabled=false; resetBtn(); return;
      }
      apiUrl = CHARGE_ENDPOINT || (BASE + 'api/checkout_charge.php');
    }

    // Square nonce already attached above when hosted card form is used

    // Stripe 3DS فقط على صفحة Stripe
    if (curTx === 'purchase_3d' && pipeGw === 'stripe' && stripe && stripeEl) {
      var r1 = await fetch(apiUrl, {
        method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)
      });
      var d1 = await readJson(r1);
      if (!d1.success) { showToast(failMessage(d1),'error'); btn.disabled=false; resetBtn(); return; }
      if (d1.payment?.client_secret) {
        var res3d = await stripe.confirmCardPayment(d1.payment.client_secret, {payment_method:{card:stripeEl}});
        if (res3d.error) { document.getElementById('stripe-error').textContent=res3d.error.message; btn.disabled=false; resetBtn(); return; }
      }
      showToast('Done ✓','success');
      setTimeout(function(){ window.location.href=BASE+'receipt.php?ref='+encodeURIComponent(d1.reference); }, 1200);
      return;
    }

    var r = await fetch(apiUrl, {
      method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)
    });
    var d = await readJson(r);
    var redir = d.redirect_url || d.checkout_url || (d.payment && (d.payment.redirect_url || d.payment.checkout_url));
    if ((d.requires_3ds || redir) && redir) {
      showToast(<?=json_encode($ar ? 'أكمل الدفع على البوابة — التسوية بعد الموافقة فقط' : 'Complete gateway payment — Ledger settles only after approval')?>, 'info');
      window.location.href = redir;
      return;
    }
    if (!d.success) { showToast(failMessage(d),'error'); btn.disabled=false; resetBtn(); return; }
    showToast('Done ✓','success');
    setTimeout(function(){ window.location.href=BASE+'receipt.php?ref='+encodeURIComponent(d.reference||d.order_id||REF||''); }, 1200);

  } catch(err) {
    showToast('Error: '+err.message,'error');
    btn.disabled=false; resetBtn();
  }
}

function resetBtn(){
  document.getElementById('payBtn').innerHTML = '<i class="fas fa-bolt"></i><span id="payBtnLabel">' + (TX_LABELS[curTx]||curTx) + '</span>';
}
function showToast(msg, type) {
  var t = document.getElementById('toast');
  t.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(0);background:var(--card);border:1px solid '+(type==='error'?'var(--red)':'var(--green)')+';border-radius:14px;padding:12px 26px;font-size:.85rem;font-weight:700;z-index:9999;transition:.35s;color:var(--text);white-space:normal;max-width:min(92vw,560px);text-align:center';
  t.textContent = msg;
  setTimeout(function(){ t.style.transform='translateX(-50%) translateY(120px)'; }, 7000);
}

document.addEventListener('DOMContentLoaded', function(){
  var el = document.getElementById('tx_' + curTx) || document.querySelector('.tx-btn');
  if (el) setTx(curTx, el);
  if (document.getElementById('cardAmt')?.value) calcP();
});
</script>
</body></html>
