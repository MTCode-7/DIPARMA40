<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';
$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'ar' ? 'ar' : 'en';
$ar = $lang === 'ar';
$dir = $ar ? 'rtl' : 'ltr';
$csrf = generateCsrfToken();
$amount=floatval($_GET['amount']??0); $currency=strtoupper($_GET['currency']??'USDT');
$destination=$_GET['destination']??'gateway'; $ref=$_GET['ref']??('BNB-'.strtoupper(substr(uniqid(),0,8)));
$walletAddr=$_GET['wallet']??'TEwLFWlwK55b7PuFfzgH1H2f3xs3pLgLn2';
?><!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$dir?>">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | Binance Pay</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}:root{--gold:#FFD700;--bg:#030609;--card:#090f1e;--border:rgba(255,215,0,.12);--border2:rgba(255,215,0,.28);--text:#edf0f7;--muted2:#718096;--green:#10B981;--red:#EF4444;--bnb:#F3BA2F}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.topbar{background:rgba(3,6,9,.97);border-bottom:1px solid var(--border);height:60px;display:flex;align-items:center;justify-content:space-between;padding:0 28px;position:sticky;top:0;z-index:100}
.tb-brand{color:var(--gold);font-weight:900;font-size:1.05rem;display:flex;align-items:center;gap:10px}
.wrap{max-width:760px;margin:0 auto;padding:32px 24px}
.card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:24px;margin-bottom:20px}
.card-title{font-size:.9rem;font-weight:800;color:var(--gold);display:flex;align-items:center;gap:8px;margin-bottom:18px}
.addr-box{background:rgba(243,186,47,.06);border:1px solid rgba(243,186,47,.2);border-radius:12px;padding:16px;margin-bottom:16px;text-align:center}
.addr-qr{width:160px;height:160px;margin:0 auto 12px;background:#fff;border-radius:12px;padding:8px;display:flex;align-items:center;justify-content:center}
.addr-qr img{width:100%;height:100%;border-radius:6px}
.addr-val{font-family:monospace;font-size:.72rem;color:var(--muted2);word-break:break-all;margin-bottom:10px;cursor:pointer}
.addr-val:hover{color:var(--gold)}
.network-tabs{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-bottom:12px}
.net-tab{padding:6px 16px;border-radius:20px;border:1.5px solid var(--border);font-size:.75rem;font-weight:700;color:var(--muted2);cursor:pointer;transition:.2s}
.net-tab.active{border-color:var(--bnb);color:var(--bnb);background:rgba(243,186,47,.08)}
.fld{margin-bottom:12px}.fld label{display:block;font-size:.72rem;color:var(--muted2);margin-bottom:5px;font-weight:700}
.fld input{width:100%;background:rgba(255,255,255,.04);border:1.5px solid var(--border);border-radius:11px;padding:11px 14px;color:var(--text);font-family:'Cairo',sans-serif;font-size:.88rem;transition:.2s}
.fld input:focus{outline:none;border-color:var(--gold)}
.pay-btn{width:100%;padding:14px;border-radius:13px;border:none;cursor:pointer;font-family:'Cairo',sans-serif;font-size:.95rem;font-weight:900;background:linear-gradient(135deg,var(--bnb),#d4a017);color:#000;box-shadow:0 8px 24px rgba(243,186,47,.2);transition:.3s;display:flex;align-items:center;justify-content:center;gap:10px;margin-top:12px}
.pay-btn:hover:not(:disabled){transform:translateY(-2px)}.pay-btn:disabled{opacity:.4;cursor:not-allowed}
.spin{display:inline-block;width:16px;height:16px;border:2px solid rgba(0,0,0,.2);border-top-color:#000;border-radius:50%;animation:spin .7s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}
#toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(100px);background:var(--card);border:1px solid var(--border2);border-radius:14px;padding:12px 28px;font-size:.84rem;font-weight:700;z-index:9999;transition:.35s;color:var(--text)}
</style>
</head>
<body>
<header class="topbar">
  <div class="tb-brand"><i class="fas fa-coins"></i> DI PARMA <span style="color:var(--muted2)">|</span><span style="background:rgba(243,186,47,.1);border:1px solid rgba(243,186,47,.2);border-radius:8px;padding:4px 12px;font-size:.72rem;font-weight:800;color:var(--bnb)"><i class="fas fa-coins"></i> Binance</span></div>
  <a href="../checkout_router.php" style="color:var(--muted2);font-size:.78rem;text-decoration:none;display:flex;align-items:center;gap:6px"><i class="fas fa-arrow-<?=$ar?'right':'left'?>"></i> <?=$ar?'رجوع':'Back'?></a>
</header>
<div class="wrap">
  <!-- Wallet Address + QR -->
  <div class="card">
    <div class="card-title"><i class="fas fa-wallet"></i> <?=$ar?'ارسل USDT لهذا العنوان':'Send USDT to this address'?></div>
    <div class="network-tabs" id="netTabs">
      <div class="net-tab active" onclick="selectNet('TRC20',this)">TRC20</div>
      <div class="net-tab" onclick="selectNet('ERC20',this)">ERC20</div>
      <div class="net-tab" onclick="selectNet('BEP20',this)">BEP20</div>
    </div>
    <div class="addr-box">
      <div class="addr-qr">
        <img id="qrImg" src="https://chart.googleapis.com/chart?chs=160x160&cht=qr&chl=<?=urlencode($walletAddr)?>&choe=UTF-8" alt="QR">
      </div>
      <div class="addr-val" id="walletDisplay" onclick="copyAddr()"><?=htmlspecialchars($walletAddr)?></div>
      <button onclick="copyAddr()" style="background:rgba(243,186,47,.1);border:1px solid rgba(243,186,47,.2);border-radius:8px;padding:6px 18px;color:var(--bnb);font-size:.75rem;font-weight:700;cursor:pointer">
        <i class="fas fa-copy"></i> <?=$ar?'نسخ العنوان':'Copy Address'?>
      </button>
    </div>
    <div style="text-align:center;font-size:.8rem;color:var(--muted2)">
      <?=$ar?'أرسل بالضبط:':'Send exactly:'?> <span style="color:var(--bnb);font-weight:900"><?=number_format($amount,2)?> USDT</span>
      <br><span style="font-size:.7rem"><?=$ar?'المرجع في الملاحظة:':'Reference in memo:'?> <b><?=htmlspecialchars($ref)?></b></span>
    </div>
  </div>

  <!-- Confirm -->
  <div class="card">
    <div class="card-title"><i class="fas fa-check-circle"></i> <?=$ar?'تأكيد الإرسال':'Confirm Send'?></div>
    <div class="fld"><label>TX Hash / Transaction ID</label><input type="text" id="txHash" placeholder="0x... or T..."></div>
    <button class="pay-btn" id="confirmBtn" onclick="confirmCrypto()">
      <i class="fas fa-check"></i> <?=$ar?'أرسلت — تأكيد':'Sent — Confirm'?>
    </button>
  </div>
</div>
<div id="toast"></div>
<script>
const AR=<?=$ar?'true':'false'?>;const CSRF='<?=$csrf?>';const AMOUNT=<?=$amount?>;const CURRENCY='<?=$currency?>';const REF='<?=$ref?>';const DESTINATION='<?=$destination?>';
const WALLETS={TRC20:'<?=htmlspecialchars($walletAddr)?>',ERC20:'0x...',BEP20:'0x...'};
function selectNet(net,el){document.querySelectorAll('.net-tab').forEach(t=>t.classList.remove('active'));el.classList.add('active');const w=WALLETS[net]||WALLETS.TRC20;document.getElementById('walletDisplay').textContent=w;document.getElementById('qrImg').src='https://chart.googleapis.com/chart?chs=160x160&cht=qr&chl='+encodeURIComponent(w)+'&choe=UTF-8';}
function copyAddr(){const a=document.getElementById('walletDisplay').textContent;navigator.clipboard?.writeText(a).then(()=>toast(AR?'تم نسخ العنوان':'Address copied','success'));}
async function confirmCrypto(){
  const txHash=document.getElementById('txHash').value.trim();
  if(!txHash)return toast(AR?'أدخل TX Hash':'Enter TX Hash','error');
  const btn=document.getElementById('confirmBtn');btn.disabled=true;btn.innerHTML='<span class="spin"></span>';
  try{
    const r=await fetch('../api/pos_transaction.php',{method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({txn_type:'purchase',amount:AMOUNT,currency:'USDT',destination:DESTINATION,
        reference:REF,pos_device:'BINANCE_CRYPTO',csrf_token:CSRF,
        extra:{tx_hash:txHash,network:'TRC20',crypto_amount:AMOUNT}})});
    const d=await r.json();
    if(d.success){toast(AR?'✅ تم التأكيد':'✅ Confirmed','success');setTimeout(()=>window.location.href='../dashboard.php?ref='+REF,2000);}
    else toast(d.message||'Failed','error');
  }catch(e){toast('Error','error');}
  btn.disabled=false;btn.innerHTML='<i class="fas fa-check"></i> '+(AR?'أرسلت — تأكيد':'Sent — Confirm');
}
function toast(msg,type='info'){const t=document.getElementById('toast');const c={success:'var(--green)',error:'var(--red)',info:'var(--gold)'};t.style.borderColor=c[type]||c.info;t.style.color=c[type]||c.info;t.textContent=msg;t.style.transform='translateX(-50%) translateY(0)';clearTimeout(t._t);t._t=setTimeout(()=>{t.style.transform='translateX(-50%) translateY(100px)';},4000);}
</script>
</body></html>
