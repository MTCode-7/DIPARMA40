<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';

$csrfToken = generateCsrfToken();
$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'en' ? 'en' : 'ar';
$ar = ($lang === 'ar'); $dir = $ar ? 'rtl' : 'ltr';
$db = db();
$msg = ''; $msgType = '';

// معالجة الإرسال
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken($_POST['csrf_token'] ?? '');

    $gateway    = trim($_POST['gateway']     ?? '');
    $txType     = trim($_POST['tx_type']     ?? 'capture');
    $rrn        = trim($_POST['rrn']         ?? '');
    $apCode     = trim($_POST['approval_code'] ?? '');
    $gwTxnId    = trim($_POST['gw_txn_id']   ?? '');
    $amount     = floatval($_POST['amount']  ?? 0);
    $currency   = strtoupper(trim($_POST['currency'] ?? 'USD'));
    $cardNum    = preg_replace('/\D/', '', $_POST['card_number'] ?? '');
    $cardExp    = trim($_POST['card_expiry'] ?? '');
    $cardCvv    = trim($_POST['card_cvv']    ?? '');
    $cardName   = trim($_POST['card_name']   ?? '');
    $email      = trim($_POST['email']       ?? 'guest@diparmas.com');

    if (!$rrn || !$apCode || !$gateway || $amount < 1) {
        $msg = 'RRN + Approval Code + Gateway + Amount — مطلوبة'; $msgType = 'error';
    } else {
        $ref = 'CAP' . strtoupper(bin2hex(random_bytes(5))) . date('Ymd');
        try {
            $db->execute(
                "INSERT INTO dp_transactions
                 (reference, gateway, protocol, amount, currency,
                  customer_email, status, transaction_type, security_mode,
                  gateway_response, created_at)
                 VALUES (?,?,?,?,?,?,'pending',?,?,?,NOW())",
                [
                    $ref, $gateway, $txType === 'offline' ? '201.3' : '101.1',
                    $amount, $currency, $email,
                    strtoupper($txType) . " — RRN:{$rrn} / AP:{$apCode}",
                    '2D',
                    json_encode([
                        'rrn'           => $rrn,
                        'approval_code' => $apCode,
                        'gw_txn_id'     => $gwTxnId,
                        'card_last4'    => $cardNum ? substr($cardNum, -4) : '',
                        'card_expiry'   => $cardExp,
                        'card_name'     => $cardName,
                        'tx_type'       => $txType,
                        'gateway'       => $gateway,
                    ])
                ]
            );
            $msg = ($ar ? '✅ تم تسجيل العملية — المرجع: ' : '✅ Recorded — Ref: ') . $ref;
            $msgType = 'success';
        } catch (Exception $e) {
            $msg = 'Error: ' . $e->getMessage(); $msgType = 'error';
        }
    }
}

// آخر العمليات
$recent = $db->query(
    "SELECT reference, gateway, amount, currency, transaction_type, status, created_at,
            JSON_EXTRACT(gateway_response,'$.rrn') as rrn,
            JSON_EXTRACT(gateway_response,'$.approval_code') as ap_code,
            JSON_EXTRACT(gateway_response,'$.gw_txn_id') as gw_txn_id
     FROM dp_transactions
     WHERE transaction_type LIKE '%RRN%'
     ORDER BY created_at DESC LIMIT 20"
);

// خريطة البوابات
$gwMap = [
    'stripe'     => ['label'=>'Stripe',     'color'=>'#6772e5','icon'=>'fab fa-stripe-s',       'id_label'=>'Payment Intent ID', 'placeholder'=>'pi_...'],
    'paypal'     => ['label'=>'PayPal',     'color'=>'#0070ba','icon'=>'fab fa-paypal',           'id_label'=>'PayPal Payment ID', 'placeholder'=>'PAY-... / PAYID-...'],
    'myfatoorah' => ['label'=>'MyFatoorah', 'color'=>'#00b09b','icon'=>'fas fa-money-bill-wave', 'id_label'=>'Invoice / Payment ID','placeholder'=>'Invoice ID'],
    'nuvei'      => ['label'=>'Nuvei',      'color'=>'#0A5EB0','icon'=>'fas fa-credit-card',     'id_label'=>'Transaction ID',    'placeholder'=>'transactionId'],
    'hsbc_uae'   => ['label'=>'HSBC UAE',   'color'=>'#DB0011','icon'=>'fas fa-university',      'id_label'=>'Bank Reference',    'placeholder'=>'Bank Ref'],
    'nbe_egypt'  => ['label'=>'NBE Egypt',  'color'=>'#006633','icon'=>'fas fa-landmark',        'id_label'=>'Bank Reference',    'placeholder'=>'Bank Ref'],
    'mashreq'    => ['label'=>'Mashreq',    'color'=>'#CC0000','icon'=>'fas fa-university',      'id_label'=>'Bank Reference',    'placeholder'=>'Bank Ref'],
    'wise'       => ['label'=>'Wise',       'color'=>'#00B9FF','icon'=>'fas fa-paper-plane',     'id_label'=>'Transfer ID',       'placeholder'=>'transferId'],
];
?><!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$dir?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | Capture / MOTO</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--gold:#FFD700;--bg:#040810;--card:#080d1a;--border:rgba(255,215,0,.15);--text:#f0f0f0;--muted:#666;--green:#10B981;--red:#EF4444;--orange:#f0ad4e}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.top-bar{background:rgba(4,8,16,.97);border-bottom:1px solid var(--border);padding:0 24px;height:62px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:99}
.brand{color:var(--gold);font-weight:900}
.top-nav a{color:var(--muted);font-size:.82rem;padding:6px 13px;border-radius:20px;text-decoration:none}
.top-nav a:hover{color:var(--gold)}
.wrap{max-width:1100px;margin:24px auto;padding:0 20px;display:grid;grid-template-columns:420px 1fr;gap:20px}
.co-card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:22px;margin-bottom:14px}
.co-title{font-size:.92rem;font-weight:800;margin-bottom:16px;display:flex;align-items:center;gap:8px}
/* Gateway Selector */
.gw-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:16px}
.gw-btn{background:rgba(255,255,255,.04);border:1.5px solid rgba(255,215,0,.12);border-radius:10px;padding:10px 6px;text-align:center;cursor:pointer;transition:.2s}
.gw-btn:hover{border-color:rgba(255,215,0,.3)}
.gw-btn.active{border-color:var(--gw-color);background:color-mix(in srgb,var(--gw-color) 10%,transparent)}
.gw-btn i{display:block;font-size:1.1rem;margin-bottom:4px}
.gw-btn span{font-size:.65rem;font-weight:700}
/* TX Type */
.tx-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:16px}
.tx-btn{background:rgba(255,255,255,.04);border:1.5px solid rgba(255,215,0,.12);border-radius:10px;padding:10px 6px;text-align:center;cursor:pointer;transition:.2s}
.tx-btn:hover{border-color:rgba(255,215,0,.3)}
.tx-btn.active{border-color:var(--gold);background:rgba(255,215,0,.08)}
.tx-btn i{display:block;font-size:1rem;margin-bottom:3px}
.tx-btn span{font-size:.65rem;font-weight:700;display:block;line-height:1.3}
/* Fields */
.fld{margin-bottom:12px}
.fld label{display:block;font-size:.74rem;color:var(--muted);margin-bottom:4px;font-weight:700}
.fld label .req{color:var(--red);margin:0 3px}
.fld label .opt{color:var(--muted);font-size:.63rem;font-weight:400}
.fld input,.fld select{width:100%;background:rgba(255,255,255,.04);border:1.5px solid var(--border);border-radius:10px;padding:10px 13px;color:var(--text);font-family:'Cairo',sans-serif;font-size:.86rem}
.fld input:focus,.fld select:focus{outline:none;border-color:var(--gold)}
.fld-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.fld-row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
.req-box{background:rgba(255,165,0,.05);border:1.5px solid rgba(255,165,0,.2);border-radius:12px;padding:14px;margin-bottom:12px}
.req-box-title{font-size:.76rem;font-weight:800;color:var(--orange);margin-bottom:10px;display:flex;align-items:center;gap:6px}
.opt-box{background:rgba(255,255,255,.02);border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:14px}
.opt-box-title{font-size:.74rem;font-weight:800;color:#888;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.submit-btn{width:100%;padding:13px;border-radius:12px;border:none;cursor:pointer;font-family:'Cairo',sans-serif;font-weight:800;font-size:.93rem;background:linear-gradient(135deg,#b8960a,var(--gold));color:#000;transition:.3s;margin-top:8px}
.submit-btn:hover{transform:translateY(-2px)}
.msg{padding:12px 16px;border-radius:10px;font-size:.84rem;font-weight:700;margin-bottom:14px}
.msg.success{background:rgba(16,185,129,.1);border:1px solid var(--green);color:var(--green)}
.msg.error{background:rgba(239,68,68,.1);border:1px solid var(--red);color:var(--red)}
/* Table */
.tbl-wrap{background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden}
table{width:100%;border-collapse:collapse;font-size:.78rem}
th{background:rgba(255,215,0,.06);padding:10px 12px;color:var(--muted);font-weight:700;text-align:right;border-bottom:1px solid var(--border)}
td{padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle}
tr:last-child td{border:none}
tr:hover td{background:rgba(255,255,255,.02)}
.status{padding:2px 9px;border-radius:20px;font-size:.67rem;font-weight:800}
.status.pending{background:rgba(240,173,78,.15);color:var(--orange);border:1px solid rgba(240,173,78,.3)}
.status.completed{background:rgba(16,185,129,.15);color:var(--green);border:1px solid rgba(16,185,129,.3)}
.status.failed{background:rgba(239,68,68,.15);color:var(--red);border:1px solid rgba(239,68,68,.3)}
.back-link{display:inline-flex;align-items:center;gap:6px;color:var(--muted);font-size:.8rem;text-decoration:none;margin-bottom:14px}
.back-link:hover{color:var(--gold)}
@media(max-width:900px){.wrap{grid-template-columns:1fr}}
</style>

<nav class="top-bar">
  <div class="brand"><i class="fas fa-coins"></i> DI PARMA</div>
  <div class="top-nav">
    <a href="dashboard.php"><i class="fas fa-th-large"></i></a>
    <a href="index.php"><i class="fas fa-home"></i></a>
  </div>
</nav>

<div style="max-width:1100px;margin:14px auto;padding:0 20px">
  <a href="index.php" class="back-link"><i class="fas fa-arrow-<?=$ar?'right':'left'?>"></i> <?=$ar?'رجوع':'Back'?></a>
</div>

<div class="wrap">
<!-- ══ Form ══ -->
<div>
<?php if ($msg): ?>
<div class="msg <?=$msgType?>"><?=htmlspecialchars($msg)?></div>
<?php endif; ?>

<form method="POST" id="captureForm">
<input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrfToken)?>">
<input type="hidden" name="gateway" id="selectedGateway" value="nuvei">
<input type="hidden" name="tx_type" id="selectedTxType" value="capture">

<!-- اختيار البوابة -->
<div class="co-card">
  <div class="co-title"><i class="fas fa-plug" style="color:var(--gold)"></i> <?=$ar?'البوابة':'Gateway'?></div>
  <div class="gw-grid">
    <?php foreach ($gwMap as $code => $gw): ?>
    <div class="gw-btn <?=$code==='nuvei'?'active':''?>"
         id="gwbtn_<?=$code?>"
         style="--gw-color:<?=$gw['color']?>"
         onclick="selectGW('<?=$code?>','<?=addslashes($gw['color'])?>','<?=addslashes($gw['id_label'])?>','<?=addslashes($gw['placeholder'])?>')">
      <i class="<?=$gw['icon']?>" style="color:<?=$gw['color']?>"></i>
      <span><?=$gw['label']?></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- نوع العملية -->
<div class="co-card">
  <div class="co-title"><i class="fas fa-sliders-h" style="color:var(--gold)"></i> <?=$ar?'نوع العملية':'Transaction Type'?></div>
  <div class="tx-grid">
    <div class="tx-btn active" id="txbtn_capture" onclick="selectTx('capture',this)">
      <i class="fas fa-check-double" style="color:#9fe870"></i>
      <span>Capture</span>
    </div>
    <div class="tx-btn" id="txbtn_online" onclick="selectTx('online',this)">
      <i class="fas fa-globe" style="color:#00B9FF"></i>
      <span>Online<br>MOTO</span>
    </div>
    <div class="tx-btn" id="txbtn_offline" onclick="selectTx('offline',this)">
      <i class="fas fa-server" style="color:#f0ad4e"></i>
      <span>Offline<br>MOTO</span>
    </div>
  </div>
</div>

<!-- الحقول الإلزامية -->
<div class="co-card">
  <div class="req-box">
    <div class="req-box-title"><i class="fas fa-exclamation-circle"></i> <?=$ar?'إلزامي — مطلوب للتنفيذ':'Required — mandatory'?></div>
    <div class="fld-row">
      <div class="fld">
        <label style="color:var(--orange)">RRN <span class="req">*</span></label>
        <input type="text" name="rrn" maxlength="12" placeholder="12 digits"
               oninput="this.value=this.value.replace(/[^0-9]/g,'')" style="font-family:monospace" required>
      </div>
      <div class="fld">
        <label style="color:var(--orange)">Approval Code <span class="req">*</span></label>
        <input type="text" name="approval_code" maxlength="6" placeholder="4-6 digits"
               oninput="this.value=this.value.replace(/[^0-9A-Za-z]/g,'')" style="font-family:monospace" required>
      </div>
    </div>
    <div class="fld">
      <label id="gwTxnLabel" style="color:#6772e5">
        <i id="gwTxnIcon" class="fas fa-credit-card" style="color:#6772e5"></i>
        Transaction ID <span class="opt">(optional)</span>
      </label>
      <input type="text" name="gw_txn_id" id="gwTxnInput" placeholder="transactionId / pi_... / PAY-..."
             style="font-family:monospace">
      <div id="gwTxnHint" style="font-size:.67rem;color:var(--muted);margin-top:3px">
        <i class="fas fa-info-circle"></i> RRN → Bank &nbsp;|&nbsp; ID → Gateway API
      </div>
    </div>
    <div class="fld-row">
      <div class="fld">
        <label><?=$ar?'المبلغ':'Amount'?> <span class="req">*</span></label>
        <input type="number" name="amount" min="1" step="0.01" placeholder="0.00" required>
      </div>
      <div class="fld">
        <label><?=$ar?'العملة':'Currency'?></label>
        <select name="currency">
          <option>USD</option><option>AED</option><option>EUR</option>
          <option>GBP</option><option>SAR</option><option>EGP</option><option>KWD</option>
        </select>
      </div>
    </div>
    <!-- البطاقة إلزامية -->
    <div class="fld">
      <label style="color:var(--orange)"><?=$ar?'رقم البطاقة':'Card Number'?> <span class="req">*</span></label>
      <input type="text" name="card_number" maxlength="19" placeholder="0000 0000 0000 0000"
             oninput="fmtCard(this)" style="font-family:monospace;letter-spacing:1px" required>
    </div>
    <div class="fld-row">
      <div class="fld">
        <label style="color:var(--orange)">Expiry <span class="req">*</span></label>
        <input type="text" name="card_expiry" maxlength="5" placeholder="MM/YY"
               oninput="fmtExp(this)" required>
      </div>
      <div class="fld">
        <label style="color:var(--orange)">CVV2 / CVC <span class="req">*</span></label>
        <input type="password" name="card_cvv" maxlength="4" placeholder="•••" required>
      </div>
    </div>
  </div>

  <!-- اختياري -->
  <div class="opt-box">
    <div class="opt-box-title"><i class="fas fa-lock-open"></i> <?=$ar?'اختياري — للمصادقة':'Optional — authentication'?></div>
    <div class="fld">
      <label><?=$ar?'اسم حامل البطاقة':'Cardholder Name'?> <span class="opt">(opt)</span></label>
      <input type="text" name="card_name" placeholder="<?=$ar?'الاسم كما في البطاقة':'Name as on card'?>">
    </div>
    <div class="fld-row">
      <div class="fld">
        <label>Email <span class="opt">(opt)</span></label>
        <input type="email" name="email" placeholder="example@email.com" value="guest@diparmas.com">
      </div>
    </div>
  </div>

  <button type="submit" class="submit-btn">
    <i class="fas fa-bolt"></i> <?=$ar?'تنفيذ العملية':'Execute Transaction'?>
  </button>
</div>
</form>
</div>

<!-- ══ Recent Transactions ══ -->
<div>
  <div class="co-card">
    <div class="co-title"><i class="fas fa-history" style="color:var(--gold)"></i> <?=$ar?'آخر العمليات':'Recent Transactions'?></div>
    <div class="tbl-wrap">
      <?php if (empty($recent)): ?>
      <div style="text-align:center;padding:40px;color:var(--muted)">
        <i class="fas fa-inbox" style="font-size:2rem;display:block;margin-bottom:10px;color:rgba(255,215,0,.2)"></i>
        <?=$ar?'لا توجد عمليات':'No transactions yet'?>
      </div>
      <?php else: ?>
      <table>
        <thead>
          <tr>
            <th><?=$ar?'المرجع':'Ref'?></th>
            <th><?=$ar?'البوابة':'GW'?></th>
            <th><?=$ar?'المبلغ':'Amount'?></th>
            <th>RRN</th>
            <th>AP Code</th>
            <th>GW ID</th>
            <th><?=$ar?'الحالة':'Status'?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($recent as $row): ?>
        <tr>
          <td><code style="color:var(--gold);font-size:.72rem"><?=htmlspecialchars(substr($row['reference'],0,18))?></code></td>
          <td style="font-weight:700"><?=htmlspecialchars($row['gateway'])?></td>
          <td style="font-weight:800;color:var(--gold)"><?=number_format($row['amount'],2)?> <?=htmlspecialchars($row['currency'])?></td>
          <td><code style="color:#5bc0de;font-size:.72rem"><?=htmlspecialchars(trim($row['rrn'],'"'))?></code></td>
          <td><code style="color:#9fe870;font-size:.72rem"><?=htmlspecialchars(trim($row['ap_code'],'"'))?></code></td>
          <td><code style="color:#aaa;font-size:.7rem"><?=htmlspecialchars(trim($row['gw_txn_id'],'"'))?></code></td>
          <td><span class="status <?=htmlspecialchars($row['status'])?>"><?=htmlspecialchars($row['status'])?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>
</div>

<script>
var gwData = <?=json_encode($gwMap)?>;

function selectGW(code, color, idLabel, placeholder) {
  document.querySelectorAll('.gw-btn').forEach(function(b){ b.classList.remove('active'); });
  var btn = document.getElementById('gwbtn_' + code);
  if (btn) { btn.classList.add('active'); btn.style.setProperty('--gw-color', color); }
  document.getElementById('selectedGateway').value = code;

  // تحديث حقل GW ID
  var lbl = document.getElementById('gwTxnLabel');
  var inp = document.getElementById('gwTxnInput');
  var hint = document.getElementById('gwTxnHint');
  var icon = document.getElementById('gwTxnIcon');

  if (lbl) lbl.innerHTML = '<i id="gwTxnIcon" class="fas fa-credit-card" style="color:'+color+'"></i> ' + idLabel + ' <span class="opt">(optional)</span>';
  if (inp) { inp.placeholder = placeholder; inp.style.borderColor = ''; }
  if (hint) hint.innerHTML = '<i class="fas fa-info-circle" style="color:'+color+'"></i> RRN &rarr; Bank &nbsp;|&nbsp; ' + idLabel + ' &rarr; ' + code.charAt(0).toUpperCase()+code.slice(1);
}

function selectTx(type, el) {
  document.querySelectorAll('.tx-btn').forEach(function(b){ b.classList.remove('active'); });
  el.classList.add('active');
  document.getElementById('selectedTxType').value = type;
}

function fmtCard(el) {
  var v = el.value.replace(/\D/g,'').substring(0,16);
  el.setAttribute('data-raw', v);
  el.value = v.replace(/(.{4})/g,'$1 ').trim();
}
function fmtExp(el) {
  var v = el.value.replace(/\D/g,'');
  if (v.length >= 2) v = v.substring(0,2) + '/' + v.substring(2,4);
  el.value = v;
}

// Initialize
selectGW('nuvei', '#0A5EB0', 'Transaction ID', 'transactionId');
</script>
</body></html>
