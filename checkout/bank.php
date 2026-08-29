<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';

$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang']==='en' ? 'en' : 'ar';
$ar=$lang==='ar'; $dir=$ar?'rtl':'ltr';
$csrf=generateCsrfToken();
$bank        = $_GET['bank']        ?? 'mashreq';
$amount      = floatval($_GET['amount']      ?? 0);
$currency    = strtoupper($_GET['currency']  ?? 'AED');
$destination = $_GET['destination']          ?? 'gateway';
$ref         = $_GET['ref']                  ?? ('BNK-'.strtoupper(substr(uniqid(),0,8)));

$banks = [
    'mashreq'  => ['name'=>'Mashreq Bank','color'=>'#FF6600','fields'=>['Beneficiary'=>'TRANSCENDIO FZ-LLC','IBAN'=>'AE300330000019101562722','SWIFT'=>'BOMLAEADXXX','Routing'=>'203320101','Bank'=>'Mashreq Bank PSC','City'=>'Dubai, UAE']],
    'hsbc_uae' => ['name'=>'HSBC UAE','color'=>'#DB0011','fields'=>['Beneficiary'=>'MR RAGEH SAEED ALI BAKRAIT','IBAN'=>'AE850200000013053368001','SWIFT'=>'BBMEAEAD','Account'=>'013-053368-001','Bank'=>'HSBC Bank Middle East Limited','City'=>'Abu Dhabi, UAE']],
    'nbe_egypt'=> ['name'=>'NBE Egypt','color'=>'#006633','fields'=>['Beneficiary'=>'TRANSCENDIO FZ-LLC','IBAN'=>'EG170003060131711241527030330','SWIFT'=>'NBEGEGCX601','Bank'=>'البنك الأهلي المصري']],
];
$bankData = $banks[$bank] ?? $banks['mashreq'];
?><!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$dir?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | <?=htmlspecialchars($bankData['name'])?></title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}:root{--gold:#FFD700;--bg:#030609;--card:#090f1e;--border:rgba(255,215,0,.12);--border2:rgba(255,215,0,.28);--text:#edf0f7;--muted:#4a5568;--muted2:#718096;--green:#10B981;--red:#EF4444}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.topbar{background:rgba(3,6,9,.97);border-bottom:1px solid var(--border);height:60px;display:flex;align-items:center;justify-content:space-between;padding:0 28px;position:sticky;top:0;z-index:100}
.tb-brand{color:var(--gold);font-weight:900;font-size:1.05rem;display:flex;align-items:center;gap:10px}
.wrap{max-width:760px;margin:0 auto;padding:32px 24px}
.card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:24px;margin-bottom:20px}
.card-title{font-size:.9rem;font-weight:800;color:var(--gold);display:flex;align-items:center;gap:8px;margin-bottom:18px}
.bank-row{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:.82rem;gap:12px}
.bank-row:last-child{border:none}
.bank-key{color:var(--muted2);flex-shrink:0}
.bank-val{font-family:monospace;font-size:.8rem;font-weight:700;cursor:pointer;text-align:left;word-break:break-all}
.bank-val:hover{color:var(--gold)}
.pay-btn{width:100%;padding:14px;border-radius:13px;border:none;cursor:pointer;font-family:'Cairo',sans-serif;font-size:.95rem;font-weight:900;color:#fff;transition:.3s;display:flex;align-items:center;justify-content:center;gap:10px;margin-top:12px}
.fld{margin-bottom:12px}.fld label{display:block;font-size:.72rem;color:var(--muted2);margin-bottom:5px;font-weight:700}
.fld input{width:100%;background:rgba(255,255,255,.04);border:1.5px solid var(--border);border-radius:11px;padding:11px 14px;color:var(--text);font-family:'Cairo',sans-serif;font-size:.88rem;transition:.2s}
.fld input:focus{outline:none;border-color:var(--gold)}
.fld-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.spin{display:inline-block;width:16px;height:16px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}
#toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(100px);background:var(--card);border:1px solid var(--border2);border-radius:14px;padding:12px 28px;font-size:.84rem;font-weight:700;z-index:9999;transition:.35s;color:var(--text)}
</style>
</head>
<body>
<header class="topbar">
  <div class="tb-brand"><i class="fas fa-coins"></i> DI PARMA <span style="color:var(--muted)">|</span>
    <span style="background:<?=$bankData['color']?>22;border:1px solid <?=$bankData['color']?>44;border-radius:8px;padding:4px 12px;font-size:.72rem;font-weight:800;color:<?=$bankData['color']?>"><?=htmlspecialchars($bankData['name'])?></span>
  </div>
  <a href="../checkout_router.php" style="color:var(--muted2);font-size:.78rem;text-decoration:none;display:flex;align-items:center;gap:6px"><i class="fas fa-arrow-<?=$ar?'right':'left'?>"></i> <?=$ar?'رجوع':'Back'?></a>
</header>

<div class="wrap">
  <div class="card">
    <div class="card-title"><i class="fas fa-university"></i> <?=$ar?'بيانات التحويل البنكي':'Bank Transfer Details'?></div>
    <?php foreach($bankData['fields'] as $k=>$v): ?>
    <div class="bank-row">
      <span class="bank-key"><?=$k?>:</span>
      <span class="bank-val" onclick="copy('<?=htmlspecialchars($v,ENT_QUOTES)?>')"><?=htmlspecialchars($v)?></span>
    </div>
    <?php endforeach; ?>
    <div class="bank-row"><span class="bank-key"><?=$ar?'المبلغ:':'Amount:'?></span><span class="bank-val" style="color:var(--gold)"><?=number_format($amount,2)?> <?=$currency?></span></div>
    <div class="bank-row"><span class="bank-key">Reference:</span><span class="bank-val" onclick="copy('<?=htmlspecialchars($ref,ENT_QUOTES)?>')"><?=htmlspecialchars($ref)?></span></div>
  </div>

  <div class="card">
    <div class="card-title"><i class="fas fa-check-circle"></i> <?=$ar?'تأكيد التحويل':'Confirm Transfer'?></div>
    <div class="fld"><label><?=$ar?'اسمك الكامل':'Your Full Name'?></label><input type="text" id="sName" placeholder="<?=$ar?'الاسم كما على الحساب':'Name as on account'?>"></div>
    <div class="fld-row">
      <div class="fld"><label><?=$ar?'رقم مرجع التحويل':'Transfer Reference'?></label><input type="text" id="txRef" placeholder="SWIFT/SEPA ref"></div>
      <div class="fld"><label><?=$ar?'تاريخ التحويل':'Transfer Date'?></label><input type="date" id="txDate" value="<?=date('Y-m-d')?>"></div>
    </div>
    <button class="pay-btn" id="confirmBtn" style="background:<?=$bankData['color']?>;box-shadow:0 8px 24px <?=$bankData['color']?>44" onclick="confirmTransfer()">
      <i class="fas fa-check"></i> <?=$ar?'تأكيد التحويل':'Confirm Transfer'?>
    </button>
  </div>
</div>

<div id="toast"></div>
<script>
const AR=<?=$ar?'true':'false'?>;const CSRF='<?=$csrf?>';const AMOUNT=<?=$amount?>;const CURRENCY='<?=$currency?>';const REF='<?=$ref?>';const DESTINATION='<?=$destination?>';const BANK='<?=$bank?>';
function copy(txt){navigator.clipboard?.writeText(txt).then(()=>toast(AR?'تم النسخ':'Copied','success'));}
async function confirmTransfer(){
  const btn=document.getElementById('confirmBtn');
  const name=document.getElementById('sName').value.trim();
  const txRef=document.getElementById('txRef').value.trim();
  if(!name)return toast(AR?'أدخل اسمك':'Enter name','error');
  btn.disabled=true;btn.innerHTML='<span class="spin"></span>';
  try{
    const r=await fetch('../api/pos_transaction.php',{method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({txn_type:'purchase',amount:AMOUNT,currency:CURRENCY,destination:DESTINATION,
        reference:REF,pos_device:'BANK_TRANSFER_'+BANK.toUpperCase(),csrf_token:CSRF,
        card_name:name,extra:{bank:BANK,transfer_ref:txRef,transfer_date:document.getElementById('txDate').value}})});
    const d=await r.json();
    if(d.success){toast(AR?'✅ تم تسجيل التحويل':'✅ Transfer recorded','success');
      setTimeout(()=>window.location.href='../dashboard.php?ref='+REF,2000);}
    else toast(d.message||'Error','error');
  }catch(e){toast(AR?'خطأ':'Error','error');}
  btn.disabled=false;btn.innerHTML='<i class="fas fa-check"></i> '+(AR?'تأكيد التحويل':'Confirm Transfer');
}
function toast(msg,type='info'){const t=document.getElementById('toast');const c={success:'var(--green)',error:'var(--red)',info:'var(--gold)'};t.style.borderColor=c[type]||c.info;t.style.color=c[type]||c.info;t.textContent=msg;t.style.transform='translateX(-50%) translateY(0)';clearTimeout(t._t);t._t=setTimeout(()=>{t.style.transform='translateX(-50%) translateY(100px)';},4000);}
</script>
</body>
</html>
