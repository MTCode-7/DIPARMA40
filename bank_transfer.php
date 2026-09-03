<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';

$db        = db();
$csrfToken = generateCsrfToken();
$lang      = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'ar' ? 'ar' : 'en';
$ar        = ($lang === 'ar');
$dir       = $ar ? 'rtl' : 'ltr';
$msg       = '';
$msgType   = '';

// بيانات البنوك
$banks = [
    'mashreq' => [
        'name'     => 'Mashreq Bank — TRANSCENDIO FZ-LLC',
        'color'    => '#FF6600',
        'icon'     => 'fas fa-university',
        'fields'   => [
            'Account Name'    => 'TRANSCENDIO FZ-LLC',
            'Account Number'  => '019101562722',
            'IBAN'            => 'AE300330000019101562722',
            'SWIFT / BIC'     => 'BOMLAEADXXX',
            'Bank Routing'    => '203320101',
            'Bank'            => 'Mashreq Bank PSC',
            'Address'         => '403 36, Zarouni Business Centre, Al Barsha 1, Dubai, AE',
            'Currency'        => 'AED / USD',
            'CIF'             => '015379207',
            'RM'              => 'Johnson Joy — +9715027968066',
        ],
        'currencies' => ['AED','USD','EUR','GBP'],
    ],
    'jpmorgan_iolta' => [
        'name'     => 'JP Morgan Chase — IOLTA Account',
        'color'    => '#003087',
        'icon'     => 'fas fa-landmark',
        'fields'   => [
            'Account Name'    => 'ROBERT VALLES JR IOLTA',
            'Account Number'  => '663525063665',
            'Bank Routing'    => '111000614',
            'SWIFT / BIC'     => 'CHASUS33',
            'Bank'            => 'JP Morgan Chase Bank N.A.',
            'Address'         => '16738 W State Highway 71, Lakeway TX.',
            'Bank Officer'    => 'ANTHONY HALL',
            'Currency'        => 'USD',
            'Type'            => 'IOLTA (Trust Account)',
        ],
        'currencies' => ['USD'],
    ],
    'hsbc_uae' => [
        'name'     => 'HSBC Bank Middle East UAE',
        'color'    => '#DB0011',
        'icon'     => 'fas fa-university',
        'fields'   => [
            'Account Name'  => 'MR RAGEH SAEED ALI BAKRAIT',
            'IBAN'          => 'AE850200000013053368001',
            'Account No'    => '013-053368-001',
            'SWIFT / BIC'   => 'BBME AEAD',
            'Bank'          => 'HSBC Bank Middle East Limited',
            'City'          => 'Abu Dhabi, UAE',
            'Currency'      => 'AED',
        ],
        'currencies' => ['AED','USD','EUR','GBP'],
    ],
    'nbe_egypt' => [
        'name'     => 'البنك الأهلي المصري (NBE)',
        'color'    => '#006633',
        'icon'     => 'fas fa-landmark',
        'fields'   => [
            'اسم الحساب'  => 'TRANSCENDIO FZ-LLC',
            'IBAN'        => 'EG170003060131711241527030330',
            'SWIFT / BIC' => 'NBEGEGCX601',
            'البنك'       => 'البنك الأهلي المصري',
            'العملة'      => 'EGP',
        ],
        'currencies' => ['EGP','USD','EUR'],
    ],
    'wise' => [
        'name'     => 'Wise International Transfer',
        'color'    => '#00B9FF',
        'icon'     => 'fas fa-paper-plane',
        'fields'   => [
            'Bank Name'      => 'Community Federal Savings Bank',
            'Routing Number' => '026073150',
            'Account Type'   => 'Checking',
            'SWIFT'          => 'WFBIUS6S',
            'Reference'      => 'Use your Wise email',
        ],
        'currencies' => ['USD','EUR','GBP','AED'],
    ],
];

$selectedBank = $_GET['bank'] ?? 'hsbc_uae';
if (!isset($banks[$selectedBank])) $selectedBank = 'hsbc_uae';
$bank = $banks[$selectedBank];

// معالجة الإرسال
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken($_POST['csrf_token'] ?? '');

    $name      = trim($_POST['customer_name'] ?? '');
    $email     = trim($_POST['customer_email'] ?? '');
    $phone     = trim($_POST['customer_phone'] ?? '');
    $amount    = floatval($_POST['amount'] ?? 0);
    $currency  = strtoupper(trim($_POST['currency'] ?? 'USD'));
    $tref      = trim($_POST['transfer_ref'] ?? '');
    $notes     = trim($_POST['notes'] ?? '');
    $bankCode  = trim($_POST['bank_code'] ?? $selectedBank);

    // رفع الإثبات
    $proofFile = null;
    if (!empty($_FILES['proof']['name'])) {
        $ext = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','pdf','heic'])) {
            $dir2 = '/var/www/diparma/uploads/bank_proofs/';
            if (!is_dir($dir2)) mkdir($dir2, 0755, true);
            $fname = 'proof_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (move_uploaded_file($_FILES['proof']['tmp_name'], $dir2 . $fname)) {
                $proofFile = $fname;
            }
        }
    }

    if (!$name || !$email || $amount < 1) {
        $msg = $ar ? 'يرجى ملء جميع الحقول المطلوبة' : 'Please fill all required fields';
        $msgType = 'error';
    } else {
        $ref = 'BT' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10)) . date('Ymd');
        try {
            $db->execute(
                "INSERT INTO dp_bank_transfers
                 (ref, user_id, customer_name, customer_email, customer_phone,
                  bank_code, bank_name, amount, currency, transfer_ref, notes, proof_file, status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'pending')",
                [
                    $ref,
                    intval($_SESSION['user_id'] ?? 0),
                    $name, $email, $phone,
                    $bankCode,
                    $banks[$bankCode]['name'] ?? $bankCode,
                    $amount, $currency, $tref, $notes, $proofFile
                ]
            );
            $msg = ($ar ? 'تم إرسال طلبك بنجاح — رقم المرجع: ' : 'Request submitted — Reference: ') . $ref;
            $msgType = 'success';
        } catch (Exception $e) {
            $msg = 'Error: ' . $e->getMessage();
            $msgType = 'error';
        }
    }
}
?><!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$dir?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | Bank Transfer</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--gold:#FFD700;--bg:#040810;--card:#080d1a;--border:rgba(255,215,0,.15);--text:#f0f0f0;--muted:#666;--green:#10B981;--red:#EF4444}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.top-bar{background:rgba(4,8,16,.97);border-bottom:1px solid var(--border);padding:0 24px;height:62px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:99}
.brand{color:var(--gold);font-weight:900}
.top-nav a{color:var(--muted);font-size:.82rem;padding:6px 13px;border-radius:20px;text-decoration:none;transition:.2s}
.top-nav a:hover{color:var(--gold)}
.wrap{max-width:960px;margin:30px auto;padding:0 20px;display:grid;grid-template-columns:1fr 320px;gap:20px}
.co-card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:22px;margin-bottom:14px}
.co-title{font-size:.92rem;font-weight:800;margin-bottom:16px;display:flex;align-items:center;gap:8px}
/* Bank Tabs */
.bank-tabs{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap}
.bank-tab{background:rgba(255,255,255,.04);border:1.5px solid rgba(255,215,0,.12);border-radius:10px;padding:8px 14px;cursor:pointer;font-size:.8rem;font-weight:700;text-decoration:none;color:var(--text);transition:.2s;display:flex;align-items:center;gap:6px}
.bank-tab:hover{border-color:rgba(255,215,0,.3)}
.bank-tab.active{border-color:var(--bank-color,var(--gold));background:color-mix(in srgb,var(--bank-color,var(--gold)) 10%,transparent);color:var(--bank-color,var(--gold))}
/* Bank Info */
.bank-info{background:color-mix(in srgb,var(--bank-color) 5%,transparent);border:1px solid color-mix(in srgb,var(--bank-color) 20%,transparent);border-radius:12px;padding:14px;margin-bottom:14px}
.bank-row{display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:.84rem}
.bank-row:last-child{border:none}
.bank-key{color:var(--muted);font-size:.76rem}
.bank-val{font-weight:700;display:flex;align-items:center;gap:7px}
.copy-btn{background:rgba(255,215,0,.1);border:1px solid rgba(255,215,0,.2);border-radius:7px;padding:2px 9px;cursor:pointer;font-size:.68rem;color:var(--gold)}
/* IBAN Box */
.iban-box{background:rgba(255,215,0,.06);border:1px solid rgba(255,215,0,.2);border-radius:12px;padding:14px;text-align:center;margin-bottom:14px}
.iban-num{font-family:monospace;font-size:.95rem;font-weight:900;color:var(--gold);letter-spacing:1.5px;word-break:break-all}
/* Form */
.fld{margin-bottom:12px}
.fld label{display:block;font-size:.74rem;color:var(--muted);margin-bottom:4px;font-weight:700}
.fld input,.fld select,.fld textarea{width:100%;background:rgba(255,255,255,.04);border:1.5px solid var(--border);border-radius:10px;padding:10px 13px;color:var(--text);font-family:'Cairo',sans-serif;font-size:.86rem}
.fld input:focus,.fld select:focus,.fld textarea:focus{outline:none;border-color:var(--gold)}
.fld textarea{resize:vertical;min-height:70px}
.fld-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.req{color:var(--red);margin:0 3px}
.upload-box{border:2px dashed rgba(255,215,0,.2);border-radius:12px;padding:20px;text-align:center;cursor:pointer;transition:.2s}
.upload-box:hover{border-color:rgba(255,215,0,.4)}
.upload-box input{display:none}
/* Steps */
.steps{display:flex;gap:0;margin-bottom:20px}
.step{flex:1;text-align:center;font-size:.75rem;color:var(--muted);padding-bottom:8px;border-bottom:2px solid rgba(255,255,255,.08)}
.step.active{color:var(--gold);border-bottom-color:var(--gold);font-weight:700}
/* Summary */
.sum-row{display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:.82rem}
.sum-row:last-child{border:none;font-weight:800;font-size:.9rem;color:var(--gold);padding-top:9px}
.sum-key{color:var(--muted)}
.submit-btn{width:100%;padding:13px;border-radius:12px;border:none;cursor:pointer;font-family:'Cairo',sans-serif;font-weight:800;font-size:.93rem;background:linear-gradient(135deg,#b8960a,var(--gold));color:#000;transition:.3s;margin-top:11px}
.submit-btn:hover{transform:translateY(-2px)}
.msg{padding:12px 16px;border-radius:10px;font-size:.84rem;font-weight:700;margin-bottom:14px}
.msg.success{background:rgba(16,185,129,.1);border:1px solid var(--green);color:var(--green)}
.msg.error{background:rgba(239,68,68,.1);border:1px solid var(--red);color:var(--red)}
.back-link{display:inline-flex;align-items:center;gap:6px;color:var(--muted);font-size:.8rem;text-decoration:none;margin-bottom:14px}
.back-link:hover{color:var(--gold)}
@media(max-width:768px){.wrap{grid-template-columns:1fr}.fld-row{grid-template-columns:1fr}}
</style>

<nav class="top-bar">
  <div class="brand"><i class="fas fa-coins"></i> DI PARMA</div>
  <div class="top-nav">
    <a href="dashboard.php"><i class="fas fa-th-large"></i></a>
    <a href="checkout_router.php"><i class="fas fa-exchange-alt"></i></a>
  </div>
</nav>

<div style="max-width:960px;margin:16px auto;padding:0 20px">
  <a href="checkout_router.php" class="back-link"><i class="fas fa-arrow-<?=$ar?'right':'left'?>"></i> <?=$ar?'رجوع':'Back'?></a>
</div>

<div class="wrap">
<div>

<?php if ($msg): ?>
<div class="msg <?=$msgType?>"><?=htmlspecialchars($msg)?></div>
<?php endif; ?>

<!-- ══ اختيار البنك ══ -->
<div class="co-card">
  <div class="co-title"><i class="fas fa-university" style="color:var(--gold)"></i> <?=$ar?'اختر البنك':'Select Bank'?></div>
  <div class="bank-tabs">
    <?php foreach ($banks as $code => $b): ?>
    <a href="?bank=<?=$code?>" class="bank-tab <?=$code===$selectedBank?'active':''?>"
       style="--bank-color:<?=$b['color']?>">
      <i class="<?=$b['icon']?>" style="color:<?=$b['color']?>"></i>
      <?=htmlspecialchars($b['name'])?>
    </a>
    <?php endforeach; ?>
  </div>
</div>

<!-- ══ بيانات البنك ══ -->
<div class="co-card" style="--bank-color:<?=$bank['color']?>">
  <div class="co-title">
    <i class="<?=$bank['icon']?>" style="color:<?=$bank['color']?>"></i>
    <?=htmlspecialchars($bank['name'])?>
  </div>

  <?php
  // عرض IBAN بشكل بارز
  foreach ($bank['fields'] as $k => $v) {
      if (strtoupper($k) === 'IBAN') {
          echo '<div class="iban-box">';
          echo '<div style="font-size:.7rem;color:var(--muted);margin-bottom:4px">IBAN</div>';
          echo '<div class="iban-num">'.htmlspecialchars($v).'</div>';
          echo '<button class="copy-btn" onclick="copyText(\''.htmlspecialchars($v).'\',\'Copied\')"><i class="fas fa-copy"></i> Copy</button>';
          echo '</div>';
      }
  }
  ?>

  <div class="bank-info">
    <?php foreach ($bank['fields'] as $k => $v): ?>
    <?php if (strtoupper($k) !== 'IBAN'): ?>
    <div class="bank-row">
      <span class="bank-key"><?=htmlspecialchars($k)?></span>
      <span class="bank-val">
        <?=htmlspecialchars($v)?>
        <button class="copy-btn" onclick="copyText('<?=htmlspecialchars($v)?>','Copied')"><i class="fas fa-copy"></i></button>
      </span>
    </div>
    <?php endif; ?>
    <?php endforeach; ?>
  </div>
</div>

<!-- ══ نموذج الطلب ══ -->
<div class="co-card">
  <div class="co-title"><i class="fas fa-paper-plane" style="color:var(--gold)"></i> <?=$ar?'تفاصيل التحويل':'Transfer Details'?></div>

  <form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrfToken)?>">
    <input type="hidden" name="bank_code" value="<?=htmlspecialchars($selectedBank)?>">

    <div class="fld-row">
      <div class="fld">
        <label><?=$ar?'الاسم الكامل':'Full Name'?> <span class="req">*</span></label>
        <input type="text" name="customer_name" placeholder="<?=$ar?'الاسم كما في البنك':'Name as in bank'?>" required>
      </div>
      <div class="fld">
        <label>Email <span class="req">*</span></label>
        <input type="email" name="customer_email" placeholder="example@email.com" required>
      </div>
    </div>

    <div class="fld-row">
      <div class="fld">
        <label><?=$ar?'رقم الهاتف':'Phone'?> <span style="color:var(--muted);font-size:.63rem">(<?=$ar?'اختياري':'opt'?>)</span></label>
        <input type="tel" name="customer_phone" placeholder="+971 XX XXX XXXX">
      </div>
      <div class="fld">
        <label><?=$ar?'رقم مرجع التحويل':'Transfer Reference No'?> <span style="color:var(--muted);font-size:.63rem">(<?=$ar?'اختياري':'opt'?>)</span></label>
        <input type="text" name="transfer_ref" placeholder="<?=$ar?'رقم العملية من البنك':'Transaction No from bank'?>">
      </div>
    </div>

    <div class="fld-row">
      <div class="fld">
        <label><?=$ar?'المبلغ المحوّل':'Transfer Amount'?> <span class="req">*</span></label>
        <input type="number" name="amount" min="1" step="0.01" placeholder="0.00" oninput="calcSummary(this.value, document.getElementById('curSel').value)" required>
      </div>
      <div class="fld">
        <label><?=$ar?'العملة':'Currency'?></label>
        <select name="currency" id="curSel" onchange="calcSummary(document.querySelector('[name=amount]').value, this.value)">
          <?php foreach ($bank['currencies'] as $c): ?>
          <option value="<?=$c?>"><?=$c?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="fld">
      <label><?=$ar?'ملاحظات':'Notes'?> <span style="color:var(--muted);font-size:.63rem">(<?=$ar?'اختياري':'opt'?>)</span></label>
      <textarea name="notes" placeholder="<?=$ar?'أي معلومات إضافية...':'Any additional info...'?>"></textarea>
    </div>

    <!-- رفع الإثبات -->
    <div class="fld">
      <label><?=$ar?'إثبات التحويل':'Proof of Transfer'?> <span style="color:var(--muted);font-size:.63rem">(<?=$ar?'اختياري — صورة أو PDF':'opt — image or PDF'?>)</span></label>
      <div class="upload-box" onclick="document.getElementById('proofFile').click()">
        <input type="file" id="proofFile" name="proof" accept="image/*,.pdf" onchange="showFileName(this)">
        <i class="fas fa-cloud-upload-alt" style="font-size:1.5rem;color:rgba(255,215,0,.4);margin-bottom:8px;display:block"></i>
        <div id="fileLabel" style="font-size:.8rem;color:var(--muted)"><?=$ar?'انقر لرفع صورة إثبات التحويل':'Click to upload transfer proof'?></div>
      </div>
    </div>

    <button type="submit" class="submit-btn">
      <i class="fas fa-paper-plane"></i>
      <?=$ar?'إرسال طلب التحويل':'Submit Transfer Request'?>
    </button>
  </form>
</div>

</div><!-- /col-left -->

<!-- ══ Summary ══ -->
<div>
  <div class="co-card" style="position:sticky;top:76px">
    <div class="co-title"><i class="fas fa-receipt" style="color:var(--gold)"></i> Summary</div>
    <div class="sum-row">
      <span class="sum-key"><?=$ar?'البنك':'Bank'?></span>
      <span style="color:<?=$bank['color']?>;font-size:.78rem"><?=htmlspecialchars($bank['name'])?></span>
    </div>
    <div class="sum-row">
      <span class="sum-key"><?=$ar?'النوع':'Type'?></span>
      <span><?=$ar?'تحويل بنكي مباشر':'Direct Bank Transfer'?></span>
    </div>
    <div class="sum-row">
      <span class="sum-key"><?=$ar?'المبلغ':'Amount'?></span>
      <span id="sumAmt" style="color:var(--gold)">—</span>
    </div>
    <div style="margin-top:16px;background:rgba(255,215,0,.04);border:1px solid rgba(255,215,0,.12);border-radius:10px;padding:12px;font-size:.75rem;color:#aaa">
      <i class="fas fa-info-circle" style="color:var(--gold)"></i>
      <?=$ar?'سيتم مراجعة طلبك من قِبل الإدارة والموافقة عليه خلال وقت قصير.':'Your request will be reviewed and approved by admin shortly.'?>
    </div>
    <div style="margin-top:10px;font-size:.72rem;color:var(--muted);text-align:center">
      <i class="fas fa-shield-alt" style="color:var(--green)"></i>
      <?=$ar?'تحويل بنكي آمن 100%':'100% Secure Bank Transfer'?>
    </div>
  </div>
</div>
</div><!-- /wrap -->

<div id="toast" style="position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(90px);background:var(--card);border:1px solid var(--gold);border-radius:14px;padding:12px 26px;font-size:.85rem;font-weight:700;z-index:9999;transition:.35s;color:var(--text)"></div>

<script>
function copyText(txt, msg) {
  if (navigator.clipboard) navigator.clipboard.writeText(txt).then(function(){ showToast(msg); });
  else { var t=document.createElement('textarea');t.value=txt;document.body.appendChild(t);t.select();document.execCommand('copy');document.body.removeChild(t);showToast(msg); }
}
function showToast(msg) {
  var t = document.getElementById('toast');
  t.textContent = msg;
  t.style.transform = 'translateX(-50%) translateY(0)';
  setTimeout(function(){ t.style.transform='translateX(-50%) translateY(90px)'; }, 2500);
}
function showFileName(el) {
  var lbl = document.getElementById('fileLabel');
  if (el.files[0]) lbl.textContent = el.files[0].name;
}
function calcSummary(amt, cur) {
  var a = parseFloat(amt) || 0;
  var s = document.getElementById('sumAmt');
  if (s) s.textContent = a > 0 ? a.toFixed(2) + ' ' + (cur||'') : '—';
}
</script>
</body></html>
