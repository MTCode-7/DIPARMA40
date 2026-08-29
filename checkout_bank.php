<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';

$userId    = intval($_SESSION['user_id'] ?? 0);
$csrfToken = generateCsrfToken();
$lang      = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'en' ? 'en' : 'ar';
$ar        = $lang === 'ar';
$dir       = $ar ? 'rtl' : 'ltr';

// بيانات الحساب البنكي
$bank = [
    'name'        => 'Mashreq Bank PSC',
    'account_name'=> 'TRANSCENDIO FZ-LLC',
    'account_no'  => '019101562722',
    'iban'        => 'AE300330000019101562722',
    'swift'       => 'BOMLAEADXXX',
    'routing'     => '203320101',
    'currency'    => 'AED / USD',
    'country'     => 'United Arab Emirates',
    'city'        => 'Dubai — Al Barsha 1',
    'address'     => '403 36, Zarouni Business Centre Building, Al Barsha 1, Dubai, AE',
    'cif'         => '015379207',
];
?><!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$dir?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | Bank Transfer</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--gold:#FFD700;--gold2:#FFB700;--bg:#040810;--card:#080d1a;--card2:#0a1020;--border:rgba(255,215,0,.15);--text:#f0f0f0;--muted:#666;--green:#10B981;--red:#EF4444;--blue:#3B82F6;--hsbc:#DB0011}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.top-bar{background:rgba(4,8,16,.95);border-bottom:1px solid var(--border);padding:0 24px;height:60px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:99}
.top-brand{color:var(--gold);font-weight:900;font-size:1.1rem}
.gw-badge{background:rgba(219,0,17,.15);border:2px solid var(--hsbc);border-radius:12px;padding:6px 16px;color:var(--hsbc);font-weight:800;font-size:.85rem;display:flex;align-items:center;gap:8px}
.top-nav a{color:var(--muted);font-size:.82rem;padding:6px 14px;border-radius:20px;text-decoration:none}
.top-nav a:hover{color:var(--gold)}
.wrap{max-width:960px;margin:0 auto;padding:28px 20px;display:grid;grid-template-columns:1fr 360px;gap:24px}
.co-card{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:26px;margin-bottom:16px}
.co-title{font-size:1rem;font-weight:800;margin-bottom:18px;display:flex;align-items:center;gap:8px}
.bank-info{background:rgba(219,0,17,.05);border:1px solid rgba(219,0,17,.2);border-radius:16px;padding:20px;margin-bottom:16px}
.bank-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.06);font-size:.88rem}
.bank-row:last-child{border:none}
.bank-key{color:var(--muted);font-size:.78rem}
.bank-val{font-weight:700;font-size:.9rem;display:flex;align-items:center;gap:8px}
.copy-btn{background:rgba(255,215,0,.1);border:1px solid rgba(255,215,0,.2);border-radius:8px;padding:3px 10px;cursor:pointer;font-size:.7rem;color:var(--gold);transition:.2s}
.copy-btn:hover{background:rgba(255,215,0,.2)}
.iban-box{background:rgba(255,215,0,.06);border:1px solid rgba(255,215,0,.2);border-radius:12px;padding:16px;text-align:center;margin:12px 0}
.iban-num{font-family:monospace;font-size:1.1rem;font-weight:900;color:var(--gold);letter-spacing:2px}
.fld{margin-bottom:14px}
.fld label{display:block;font-size:.76rem;color:var(--muted);margin-bottom:5px;font-weight:600}
.fld input,.fld select{width:100%;background:rgba(255,255,255,.04);border:1.5px solid var(--border);border-radius:11px;padding:11px 14px;color:var(--text);font-family:'Cairo',sans-serif;font-size:.88rem}
.fld input:focus,.fld select:focus{outline:none;border-color:var(--gold)}
.fld-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.fld-req{color:var(--red);font-size:.7rem}
.sum-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:.84rem}
.sum-row:last-child{border:none;font-weight:800;font-size:.95rem;color:var(--gold);margin-top:6px}
.sum-key{color:var(--muted)}
.submit-btn{width:100%;padding:14px;border-radius:13px;border:none;cursor:pointer;font-family:'Cairo',sans-serif;font-weight:800;font-size:.95rem;background:linear-gradient(135deg,var(--hsbc),#ff1a2d);color:#fff;transition:.3s;margin-top:14px}
.submit-btn:hover:not(:disabled){transform:translateY(-2px)}
.submit-btn:disabled{opacity:.5;cursor:not-allowed}
.steps{display:flex;flex-direction:column;gap:10px;margin-bottom:16px}
.step{display:flex;align-items:center;gap:12px;padding:12px;background:rgba(255,255,255,.03);border-radius:10px;font-size:.82rem;color:#ccc}
.step-num{width:28px;height:28px;border-radius:50%;background:var(--gold);color:#000;font-weight:900;display:flex;align-items:center;justify-content:center;font-size:.78rem;flex-shrink:0}
.back-link{display:inline-flex;align-items:center;gap:6px;color:var(--muted);font-size:.82rem;text-decoration:none;margin-bottom:16px}
.back-link:hover{color:var(--gold)}
.alert-note{background:rgba(59,130,246,.06);border:1px solid rgba(59,130,246,.2);border-radius:12px;padding:14px;font-size:.8rem;color:#aaa;line-height:1.7;margin-bottom:16px}
@media(max-width:768px){.wrap{grid-template-columns:1fr}.fld-row{grid-template-columns:1fr}}
</style>
</head>
<body>
<nav class="top-bar">
  <div class="top-brand"><i class="fas fa-coins"></i> DI PARMA</div>
  <div style="display:flex;align-items:center;gap:10px">
    <div class="gw-badge"><i class="fas fa-university"></i> HSBC Bank Transfer</div>
    <div class="top-nav">
      <a href="dashboard.php"><i class="fas fa-th-large"></i></a>
      <a href="checkout_router.php"><i class="fas fa-exchange-alt"></i></a>
    </div>
  </div>
</nav>

<div style="max-width:960px;margin:16px auto;padding:0 20px">
  <a href="checkout_router.php" class="back-link">
    <i class="fas fa-arrow-<?=$ar?'right':'left'?>"></i>
    <?=$ar?'رجوع':'Back'?>
  </a>
</div>

<div class="wrap">
<div>
  <!-- بيانات الحساب البنكي -->
  <div class="co-card">
    <div class="co-title"><i class="fas fa-university" style="color:var(--hsbc)"></i> <?=$ar?'بيانات الحساب البنكي':'Bank Account Details'?></div>

    <div class="iban-box">
      <div style="font-size:.72rem;color:var(--muted);margin-bottom:6px">IBAN</div>
      <div class="iban-num"><?=chunk_split($bank['iban'],4,' ')?></div>
      <button class="copy-btn" style="margin-top:8px" onclick="copyText('<?=$bank['iban']?>','<?=$ar?'تم نسخ IBAN':'IBAN copied'?>')">
        <i class="fas fa-copy"></i> <?=$ar?'نسخ IBAN':'Copy IBAN'?>
      </button>
    </div>

    <div class="bank-info">
      <?php $rows=[
        [$ar?'اسم صاحب الحساب':'Account Name', $bank['account_name']],
        [$ar?'رقم الحساب':'Account Number', $bank['account_no']],
        [$ar?'IBAN':'IBAN', $bank['iban']],
        [$ar?'SWIFT / BIC':'SWIFT / BIC', $bank['swift']],
        [$ar?'البنك':'Bank', $bank['name']],
        [$ar?'العملة':'Currency', $bank['currency']],
        [$ar?'الدولة':'Country', $bank['country']],
        [$ar?'المدينة':'City', $bank['city']],
      ];
      foreach($rows as $r): ?>
      <div class="bank-row">
        <span class="bank-key"><?=$r[0]?></span>
        <span class="bank-val">
          <?=htmlspecialchars($r[1])?>
          <button class="copy-btn" onclick="copyText('<?=htmlspecialchars($r[1])?>','<?=$ar?'تم النسخ':'Copied'?>')">
            <i class="fas fa-copy"></i>
          </button>
        </span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- خطوات التحويل -->
  <div class="co-card">
    <div class="co-title"><i class="fas fa-list-ol" style="color:var(--gold)"></i> <?=$ar?'خطوات التحويل':'Transfer Steps'?></div>
    <div class="steps">
      <div class="step"><div class="step-num">1</div><?=$ar?'أدخل المبلغ المراد تحويله أدناه':'Enter the amount to transfer below'?></div>
      <div class="step"><div class="step-num">2</div><?=$ar?'قم بتحويل المبلغ لحسابنا عبر بنكك':'Transfer the amount to our account via your bank'?></div>
      <div class="step"><div class="step-num">3</div><?=$ar?'أدخل رقم المرجع (RRN) بعد التحويل':'Enter the reference number (RRN) after transfer'?></div>
      <div class="step"><div class="step-num">4</div><?=$ar?'سيتم التأكيد خلال 24 ساعة':'Confirmation within 24 hours'?></div>
    </div>
  </div>

  <!-- نموذج التأكيد -->
  <div class="co-card">
    <div class="co-title"><i class="fas fa-paper-plane" style="color:var(--blue)"></i> <?=$ar?'تأكيد التحويل':'Confirm Transfer'?></div>

    <div class="alert-note">
      <i class="fas fa-info-circle" style="color:var(--blue)"></i>
      <?=$ar?'بعد إتمام التحويل البنكي، أدخل البيانات أدناه لتأكيد العملية وتحديث حسابك.':'After completing the bank transfer, enter the details below to confirm and update your account.'?>
    </div>

    <div class="fld-row">
      <div class="fld">
        <label><?=$ar?'المبلغ المُحوَّل':'Amount Transferred'?> <span class="fld-req">*</span></label>
        <input type="number" id="fiatAmt" min="1" step="0.01" placeholder="0.00" oninput="calcP()">
      </div>
      <div class="fld">
        <label><?=$ar?'العملة':'Currency'?></label>
        <select id="fiatCur" onchange="calcP()">
          <option value="AED">AED — درهم</option>
          <option value="USD">USD — دولار</option>
          <option value="EUR">EUR — يورو</option>
        </select>
      </div>
    </div>

    <div class="fld">
      <label><?=$ar?'رقم المرجع (RRN)':'Reference Number (RRN)'?> <span class="fld-req">*</span></label>
      <input type="text" id="rrnInput" placeholder="<?=$ar?'رقم المرجع من بنكك':'Your bank reference number'?>">
    </div>

    <div class="fld">
      <label><?=$ar?'تاريخ التحويل':'Transfer Date'?></label>
      <input type="date" id="txDate" value="<?=date('Y-m-d')?>">
    </div>

    <div class="fld">
      <label><?=$ar?'البريد الإلكتروني':'Email'?></label>
      <input type="email" id="emailAddr" placeholder="example@email.com">
    </div>

    <div class="fld">
      <label><?=$ar?'ملاحظة (اختياري)':'Note (optional)'?></label>
      <input type="text" id="noteField" placeholder="<?=$ar?'أي ملاحظة إضافية':'Any additional note'?>">
    </div>
  </div>
</div>

<!-- ملخص -->
<div>
  <div class="co-card" style="position:sticky;top:80px">
    <div class="co-title"><i class="fas fa-receipt" style="color:var(--gold)"></i> <?=$ar?'ملخص':'Summary'?></div>
    <div class="sum-row"><span class="sum-key"><?=$ar?'البنك':'Bank'?></span><span style="color:var(--hsbc)">HSBC UAE</span></div>
    <div class="sum-row"><span class="sum-key">SWIFT</span><span style="color:var(--gold)">BBME AEAD</span></div>
    <div class="sum-row"><span class="sum-key"><?=$ar?'المبلغ':'Amount'?></span><span id="sumAmt">—</span></div>
    <div class="sum-row"><span class="sum-key"><?=$ar?'الرسوم':'Fee'?></span><span id="sumFee" style="color:var(--red)">—</span></div>
    <div class="sum-row"><span class="sum-key"><?=$ar?'الصافي':'Net'?></span><span id="sumNet" style="color:var(--green)">—</span></div>

    <button class="submit-btn" id="submitBtn" onclick="submitTransfer()">
      <i class="fas fa-university"></i> <?=$ar?'تأكيد التحويل البنكي':'Confirm Bank Transfer'?>
    </button>

    <div style="display:flex;align-items:center;gap:6px;margin-top:12px;font-size:.72rem;color:var(--muted);justify-content:center">
      <i class="fas fa-shield-alt" style="color:var(--green)"></i>
      <?=$ar?'تحويل آمن — HSBC Bank Middle East':'Secure Transfer — HSBC Bank Middle East'?>
    </div>
  </div>
</div>
</div>

<div id="toast" style="position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(80px);background:var(--card);border:1px solid var(--gold);border-radius:14px;padding:13px 26px;font-size:.86rem;font-weight:700;z-index:999;transition:.4s;color:var(--text)"></div>

<script>
var CSRF='<?=$csrfToken?>';

function calcP(){
  var a=parseFloat(document.getElementById('fiatAmt').value)||0;
  var c=document.getElementById('fiatCur').value;
  if(!a){['sumAmt','sumFee','sumNet'].forEach(function(i){document.getElementById(i).textContent='—';});return;}
  var f=(a*0.10).toFixed(2);
  var n=(a-parseFloat(f)).toFixed(2);
  document.getElementById('sumAmt').textContent=a.toFixed(2)+' '+c;
  document.getElementById('sumFee').textContent=f+' '+c+' (10%)';
  document.getElementById('sumNet').textContent=n+' '+c;
}

function copyText(txt, msg){
  navigator.clipboard.writeText(txt).then(function(){showToast(msg,'success');});
}

async function submitTransfer(){
  var btn=document.getElementById('submitBtn');
  var amt=parseFloat(document.getElementById('fiatAmt').value)||0;
  var cur=document.getElementById('fiatCur').value;
  var rrn=document.getElementById('rrnInput').value.trim();
  var date=document.getElementById('txDate').value;
  var email=document.getElementById('emailAddr').value.trim()||'guest@diparmas.com';
  var note=document.getElementById('noteField').value.trim();

  if(amt<1){showToast('<?=$ar?'أدخل المبلغ':'Enter amount'?>','error');return;}
  if(!rrn){showToast('<?=$ar?'أدخل رقم المرجع (RRN)':'Enter RRN'?>','error');return;}

  btn.disabled=true;
  btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> <?=$ar?'جاري...':'Processing...'?>';

  var ref='BANK'+Date.now();
  var payload={
    amount:amt,currency:cur,
    gateway:'bank_transfer',
    gateway_type:'bank',
    reference:ref,
    rrn:rrn,
    transfer_date:date,
    email:email,
    note:note,
    bank_name:'HSBC UAE',
    account_no:'013-053368-001',
    iban:'AE850200000013053368001',
    swift:'BBME AEAD',
    payment_type:'bank_transfer',
    protocol:'201.3',
    csrf_token:CSRF
  };

  try{
    var r=await fetch('api/pos_settlement.php',{method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify(payload)});
    var d=await r.json();
    if(d.success){
      showToast('<?=$ar?'✅ تم تسجيل التحويل — سيتم التأكيد خلال 24 ساعة':'✅ Transfer registered — confirmation within 24 hours'?>','success');
      setTimeout(function(){
        window.location.href='transactions.php';
      },2500);
    } else {
      showToast(d.message||'Error','error');
      btn.disabled=false;
      btn.innerHTML='<i class="fas fa-university"></i> <?=$ar?'تأكيد التحويل البنكي':'Confirm Bank Transfer'?>';
    }
  }catch(err){
    showToast('Error: '+err.message,'error');
    btn.disabled=false;
    btn.innerHTML='<i class="fas fa-university"></i> <?=$ar?'تأكيد التحويل البنكي':'Confirm Bank Transfer'?>';
  }
}

function showToast(msg,type){
  var t=document.getElementById('toast');
  t.style.borderColor=type==='error'?'var(--red)':'var(--green)';
  t.textContent=msg;t.style.transform='translateX(-50%) translateY(0)';
  setTimeout(function(){t.style.transform='translateX(-50%) translateY(80px)';},3500);
}
</script>
</body>
</html>
