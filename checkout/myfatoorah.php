<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';
$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'ar' ? 'ar' : 'en';
$ar = $lang === 'ar';
$dir = $ar ? 'rtl' : 'ltr';
$csrf = generateCsrfToken();
$amount=floatval($_GET['amount']??0); $currency=strtoupper($_GET['currency']??'KWD');
$destination=$_GET['destination']??'gateway'; $ref=$_GET['ref']??('MF-'.strtoupper(substr(uniqid(),0,8)));
$walletAddr=$_GET['wallet']??'';
?><!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$dir?>">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | MyFatoorah</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}:root{--gold:#FFD700;--bg:#030609;--card:#090f1e;--border:rgba(255,215,0,.12);--border2:rgba(255,215,0,.28);--text:#edf0f7;--muted2:#718096;--green:#10B981;--red:#EF4444;--mf:#00b09b}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.topbar{background:rgba(3,6,9,.97);border-bottom:1px solid var(--border);height:60px;display:flex;align-items:center;justify-content:space-between;padding:0 28px;position:sticky;top:0;z-index:100}
.tb-brand{color:var(--gold);font-weight:900;font-size:1.05rem;display:flex;align-items:center;gap:10px}
.wrap{max-width:760px;margin:0 auto;padding:32px 24px}
.card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:24px;margin-bottom:20px}
.card-title{font-size:.9rem;font-weight:800;color:var(--gold);display:flex;align-items:center;gap:8px;margin-bottom:18px}
.fld{margin-bottom:12px}.fld label{display:block;font-size:.72rem;color:var(--muted2);margin-bottom:5px;font-weight:700}
.fld input,.fld select{width:100%;background:rgba(255,255,255,.04);border:1.5px solid var(--border);border-radius:11px;padding:11px 14px;color:var(--text);font-family:'Cairo',sans-serif;font-size:.88rem;transition:.2s}
.fld input:focus,.fld select:focus{outline:none;border-color:var(--gold)}
.fld-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.pay-btn{width:100%;padding:14px;border-radius:13px;border:none;cursor:pointer;font-family:'Cairo',sans-serif;font-size:.95rem;font-weight:900;background:linear-gradient(135deg,var(--mf),#007a6a);color:#fff;box-shadow:0 8px 24px rgba(0,176,155,.2);transition:.3s;display:flex;align-items:center;justify-content:center;gap:10px;margin-top:12px}
.pay-btn:hover:not(:disabled){transform:translateY(-2px)}.pay-btn:disabled{opacity:.4;cursor:not-allowed}
.spin{display:inline-block;width:16px;height:16px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}
#toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(100px);background:var(--card);border:1px solid var(--border2);border-radius:14px;padding:12px 28px;font-size:.84rem;font-weight:700;z-index:9999;transition:.35s;color:var(--text)}
</style>
</head>
<body>
<header class="topbar">
  <div class="tb-brand"><i class="fas fa-coins"></i> DI PARMA <span style="color:var(--muted2)">|</span><span style="background:rgba(0,176,155,.1);border:1px solid rgba(0,176,155,.2);border-radius:8px;padding:4px 12px;font-size:.72rem;font-weight:800;color:var(--mf)"><i class="fas fa-money-bill-wave"></i> MyFatoorah</span></div>
  <a href="../checkout_router.php" style="color:var(--muted2);font-size:.78rem;text-decoration:none;display:flex;align-items:center;gap:6px"><i class="fas fa-arrow-<?=$ar?'right':'left'?>"></i> <?=$ar?'رجوع':'Back'?></a>
</header>
<div class="wrap">
  <div class="card">
    <div class="card-title"><i class="fas fa-credit-card"></i> <?=$ar?'بيانات البطاقة':'Card Details'?></div>
    <div class="fld"><label><?=$ar?'اسم حامل البطاقة':'Cardholder Name'?></label><input type="text" id="mfName" placeholder="<?=$ar?'الاسم كما على البطاقة':'Name as on card'?>"></div>
    <div class="fld"><label><?=$ar?'رقم البطاقة':'Card Number'?></label><input type="text" id="mfNum" maxlength="19" placeholder="0000 0000 0000 0000" oninput="let v=this.value.replace(/\D/g,'').substring(0,16);this.value=v.replace(/(.{4})/g,'$1 ').trim()"></div>
    <div class="fld-row">
      <div class="fld"><label><?=$ar?'تاريخ الانتهاء':'Expiry'?></label><input type="text" id="mfExp" maxlength="5" placeholder="MM/YY"></div>
      <div class="fld"><label>CVV</label><input type="password" id="mfCvv" maxlength="4" placeholder="•••"></div>
    </div>
    <div class="fld"><label><?=$ar?'البريد الإلكتروني':'Email'?></label><input type="email" id="mfEmail" placeholder="email@example.com"></div>
    <button class="pay-btn" id="mfBtn" onclick="processMF()">
      <i class="fas fa-lock"></i> <?=$ar?'ادفع عبر MyFatoorah':'Pay via MyFatoorah'?> — <?=number_format($amount,2)?> <?=$currency?>
    </button>
  </div>
</div>
<div id="toast"></div>
<script>
const AR=<?=$ar?'true':'false'?>;const CSRF='<?=$csrf?>';const AMOUNT=<?=$amount?>;const CURRENCY='<?=$currency?>';const REF='<?=$ref?>';const DESTINATION='<?=$destination?>';const WALLET='<?=htmlspecialchars($walletAddr)?>';
async function processMF(){
  const btn=document.getElementById('mfBtn');
  const num=document.getElementById('mfNum').value.replace(/\s/g,'');
  const name=document.getElementById('mfName').value.trim();
  const exp=document.getElementById('mfExp').value;
  const cvv=document.getElementById('mfCvv').value;
  const email=document.getElementById('mfEmail').value.trim();
  if(!name||num.length<13||!exp||cvv.length<3)return toast(AR?'أكمل البيانات':'Fill all fields','error');
  btn.disabled=true;btn.innerHTML='<span class="spin"></span>';
  try{
    const r=await fetch('../api/pos_transaction.php',{method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({txn_type:'purchase',amount:AMOUNT,currency:CURRENCY,destination:DESTINATION,
        card_number:num,card_name:name,card_expiry:exp,card_cvv:cvv,email:email||'client@diparmas.com',
        ledger_address:WALLET||'TEwLFWlwK55b7PuFfzgH1H2f3xs3pLgLn2',auto_transfer:DESTINATION==='ledger_trx',
        reference:REF,pos_device:'WEB_MYFATOORAH',csrf_token:CSRF})});
    const d=await r.json();
    if(d.success){toast(AR?'✅ تمت العملية بنجاح':'✅ Approved','success');setTimeout(()=>window.location.href='../dashboard.php?ref='+REF,2000);}
    else toast(d.message||'Failed','error');
  }catch(e){toast('Error','error');}
  btn.disabled=false;btn.innerHTML='<i class="fas fa-lock"></i> '+(AR?'إعادة المحاولة':'Retry');
}
function toast(msg,type='info'){const t=document.getElementById('toast');const c={success:'var(--green)',error:'var(--red)',info:'var(--gold)'};t.style.borderColor=c[type]||c.info;t.style.color=c[type]||c.info;t.textContent=msg;t.style.transform='translateX(-50%) translateY(0)';clearTimeout(t._t);t._t=setTimeout(()=>{t.style.transform='translateX(-50%) translateY(100px)';},4000);}
</script>
</body></html>
