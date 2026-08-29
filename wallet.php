<?php
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/functions.php';
require_once 'includes/auth_check.php';

header('Location: wallets.php');
exit();

$userId  = intval($_SESSION['user_id']);
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$wm = WalletManager::getInstance();

// إنشاء المحافظ إن لم تكن موجودة
$wm->createWalletsForUser($userId);

$summary = $wm->getSummary($userId);
$fiats   = $summary['fiat'];
$cryptos = $summary['crypto'];
$recent  = $summary['recent'];

// مجموع الفيات بالـ USD
$totalFiatUSD = 0;
foreach ($fiats as $w) {
    $r = $w['currency'] === 'USD' ? 1 : ($w['currency'] === 'AED' ? 0.272 : ($w['currency'] === 'EUR' ? 1.08 : 0.27));
    $totalFiatUSD += $w['balance'] * $r;
}
$totalCryptoUSD = 0;
foreach ($cryptos as $w) {
    $rate = $wm->getRate($w['coin'], 'USD');
    $totalCryptoUSD += $w['balance'] / ($rate ?: 1);
}
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>محفظتي — DI PARMA</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--gold:#FFD700;--gold2:#FFB700;--bg:#040810;--card:#080d1a;--card2:#0a1020;
  --border:rgba(255,215,0,.12);--text:#f0f0f0;--muted:#666;--green:#10B981;--red:#EF4444;--blue:#3B82F6}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.top-bar{background:rgba(4,8,16,.95);border-bottom:1px solid var(--border);padding:0 24px;height:60px;
  display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:99}
.top-brand{color:var(--gold);font-weight:900;font-size:1rem}
.top-nav a{color:var(--muted);font-size:.82rem;padding:6px 14px;border-radius:20px;text-decoration:none;transition:.2s}
.top-nav a:hover{color:var(--gold);background:rgba(255,215,0,.07)}
.wrap{max-width:1200px;margin:0 auto;padding:32px 20px}
.pg-title{font-size:1.4rem;font-weight:900;margin-bottom:6px}
.pg-sub{color:var(--muted);font-size:.85rem;margin-bottom:28px}
/* بطاقات الإجمالي */
.total-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;margin-bottom:32px}
.total-card{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:24px;position:relative;overflow:hidden}
.total-card .tc-line{position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,transparent,var(--cl),transparent)}
.tc-lbl{font-size:.75rem;color:var(--muted);margin-bottom:8px}
.tc-val{font-size:1.8rem;font-weight:900;color:var(--cl)}
.tc-sub{font-size:.72rem;color:var(--muted);margin-top:4px}
/* تبويبات */
.tabs{display:flex;gap:4px;background:rgba(255,255,255,.04);border:1px solid var(--border);
  border-radius:14px;padding:4px;margin-bottom:24px;width:fit-content}
.tab{padding:8px 22px;border-radius:10px;font-size:.85rem;font-weight:700;cursor:pointer;transition:.2s;color:var(--muted)}
.tab.active{background:linear-gradient(135deg,var(--gold),var(--gold2));color:#000}
/* جدول المحافظ */
.wallet-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;margin-bottom:32px}
.wcard{background:var(--card2);border:1px solid var(--border);border-radius:18px;padding:22px;transition:.3s}
.wcard:hover{border-color:rgba(255,215,0,.25);transform:translateY(-2px)}
.wcard-head{display:flex;align-items:center;gap:12px;margin-bottom:16px}
.wcard-ico{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;background:rgba(255,215,0,.1)}
.wcard-name{font-weight:800;font-size:.95rem}
.wcard-net{font-size:.68rem;color:var(--muted)}
.wcard-bal{font-size:1.5rem;font-weight:900;color:var(--gold);margin-bottom:4px}
.wcard-usd{font-size:.75rem;color:var(--muted)}
.wcard-btns{display:flex;gap:8px;margin-top:16px}
.wbtn{flex:1;padding:8px;border-radius:10px;font-size:.78rem;font-weight:700;cursor:pointer;border:none;transition:.2s;font-family:'Cairo',sans-serif}
.wbtn-gold{background:linear-gradient(135deg,var(--gold),var(--gold2));color:#000}
.wbtn-out{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);color:#ccc}
.wbtn-out:hover{border-color:var(--gold);color:var(--gold)}
/* جدول الحركات */
.txn-table{width:100%;border-collapse:collapse}
.txn-table th{font-size:.72rem;color:var(--muted);font-weight:600;padding:10px 14px;text-align:right;border-bottom:1px solid var(--border)}
.txn-table td{padding:12px 14px;font-size:.82rem;border-bottom:1px solid rgba(255,215,0,.05)}
.txn-table tr:hover td{background:rgba(255,255,255,.02)}
.badge{padding:3px 10px;border-radius:8px;font-size:.68rem;font-weight:700}
.badge-ok{background:rgba(16,185,129,.15);color:#10B981}
.badge-pen{background:rgba(251,191,36,.15);color:#FBB724}
.badge-fail{background:rgba(239,68,68,.15);color:#EF4444}
.sec-title{font-size:1rem;font-weight:800;margin-bottom:16px;display:flex;align-items:center;gap:8px}
/* Modal */
.modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:200;display:none;align-items:center;justify-content:center}
.modal-bg.open{display:flex}
.modal{background:var(--card);border:1px solid var(--border);border-radius:24px;padding:32px;width:100%;max-width:480px;position:relative}
.modal h3{font-size:1.1rem;font-weight:900;margin-bottom:20px}
.fld{margin-bottom:16px}
.fld label{display:block;font-size:.78rem;color:var(--muted);margin-bottom:6px}
.fld input,.fld select{width:100%;background:rgba(255,255,255,.05);border:1px solid var(--border);
  border-radius:10px;padding:11px 14px;color:var(--text);font-family:'Cairo',sans-serif;font-size:.88rem}
.fld input:focus,.fld select:focus{outline:none;border-color:var(--gold)}
.btn-submit{width:100%;background:linear-gradient(135deg,var(--gold),var(--gold2));color:#000;
  padding:14px;border-radius:12px;font-weight:800;font-size:.95rem;border:none;cursor:pointer;font-family:'Cairo',sans-serif}
.close-modal{position:absolute;top:16px;left:16px;background:rgba(255,255,255,.06);border:none;
  color:#ccc;width:32px;height:32px;border-radius:8px;cursor:pointer;font-size:1rem}
.qr-area{border:2px dashed rgba(255,215,0,.3);border-radius:12px;padding:20px;text-align:center;
  margin-bottom:12px;cursor:pointer;color:var(--muted);font-size:.82rem}
</style>
</head>
<body>
<nav class="top-bar">
  <div class="top-brand"><i class="fas fa-coins"></i> DI PARMA</div>
  <div class="top-nav">
    <a href="dashboard.php"><i class="fas fa-th-large"></i> لوحة التحكم</a>
    <a href="wallet.php" style="color:var(--gold)"><i class="fas fa-wallet"></i> محفظتي</a>
    <a href="history.php"><i class="fas fa-history"></i> السجل</a>
    <a href="index.php?logout=1"><i class="fas fa-sign-out-alt"></i> خروج</a>
  </div>
</nav>

<div class="wrap">
  <div class="pg-title"><i class="fas fa-wallet" style="color:var(--gold)"></i> محفظتي</div>
  <div class="pg-sub">إدارة محافظ الفيات والكريبتو — إيداع، تحويل، سحب</div>

  <!-- إجمالي -->
  <div class="total-grid">
    <div class="total-card" style="--cl:#FFD700">
      <div class="tc-line"></div>
      <div class="tc-lbl">إجمالي الفيات</div>
      <div class="tc-val">$<?=number_format($totalFiatUSD,2)?></div>
      <div class="tc-sub">USD مكافئ</div>
    </div>
    <div class="total-card" style="--cl:#10B981">
      <div class="tc-line"></div>
      <div class="tc-lbl">إجمالي الكريبتو</div>
      <div class="tc-val">$<?=number_format($totalCryptoUSD,2)?></div>
      <div class="tc-sub">USD مكافئ</div>
    </div>
    <div class="total-card" style="--cl:#3B82F6">
      <div class="tc-line"></div>
      <div class="tc-lbl">إجمالي المحفظة</div>
      <div class="tc-val">$<?=number_format($totalFiatUSD+$totalCryptoUSD,2)?></div>
      <div class="tc-sub">فيات + كريبتو</div>
    </div>
    <div class="total-card" style="--cl:#8B5CF6">
      <div class="tc-line"></div>
      <div class="tc-lbl">آخر نشاط</div>
      <div class="tc-val" style="font-size:1rem"><?=!empty($recent)?date('d/m/Y',strtotime($recent[0]['created_at'])):'—'?></div>
      <div class="tc-sub"><?=count($recent)?> معاملة أخيرة</div>
    </div>
  </div>

  <!-- تبويبات -->
  <div class="tabs">
    <div class="tab active" onclick="switchTab('fiat',this)"><i class="fas fa-dollar-sign"></i> محفظة الفيات</div>
    <div class="tab" onclick="switchTab('crypto',this)"><i class="fab fa-bitcoin"></i> محفظة الكريبتو</div>
    <div class="tab" onclick="switchTab('history',this)"><i class="fas fa-history"></i> الحركات</div>
  </div>

  <!-- محفظة الفيات -->
  <div id="tab-fiat">
    <div class="sec-title"><i class="fas fa-dollar-sign" style="color:var(--gold)"></i> محافظ الفيات</div>
    <div class="wallet-grid">
      <?php foreach($fiats as $w): ?>
      <?php $icons=['USD'=>'💵','AED'=>'🇦🇪','EUR'=>'💶','SAR'=>'🇸🇦','GBP'=>'💷']; ?>
      <div class="wcard">
        <div class="wcard-head">
          <div class="wcard-ico"><?=$icons[$w['currency']]??'💰'?></div>
          <div>
            <div class="wcard-name"><?=$w['currency']?> — <?=$w['currency']==='USD'?'دولار أمريكي':($w['currency']==='AED'?'درهم إماراتي':($w['currency']==='EUR'?'يورو':'عملة'))?></div>
            <div class="wcard-net">فيات داخلي</div>
          </div>
        </div>
        <div class="wcard-bal"><?=number_format($w['balance'],2)?> <?=$w['currency']?></div>
        <div class="wcard-usd">محجوز: <?=number_format($w['locked'],2)?> <?=$w['currency']?></div>
        <div class="wcard-btns">
          <button class="wbtn wbtn-gold" onclick="openDeposit('<?=$w['currency']?>')"><i class="fas fa-plus"></i> إيداع</button>
          <button class="wbtn wbtn-out" onclick="openConvert('<?=$w['currency']?>')"><i class="fas fa-exchange-alt"></i> تحويل لكريبتو</button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <!-- محفظة الكريبتو -->
  <div id="tab-crypto" style="display:none">
    <div class="sec-title"><i class="fab fa-bitcoin" style="color:#F7931A"></i> محافظ الكريبتو</div>
    <div class="wallet-grid">
      <?php
      $coinIcons=['USDT'=>'💚','BTC'=>'🟠','ETH'=>'🔷','BNB'=>'🟡','USDC'=>'🔵'];
      $shown=[];
      foreach($cryptos as $w):
        $key=$w['coin'].'_'.$w['network'];
        if(in_array($key,$shown))continue;
        $shown[]=$key;
        $usdVal = $w['balance'] > 0 ? $w['balance'] / ($wm->getRate($w['coin'],'USD') ?: 1) : 0;
      ?>
      <div class="wcard">
        <div class="wcard-head">
          <div class="wcard-ico"><?=$coinIcons[$w['coin']]??'🪙'?></div>
          <div>
            <div class="wcard-name"><?=$w['coin']?></div>
            <div class="wcard-net">شبكة: <?=$w['network']?></div>
          </div>
        </div>
        <div class="wcard-bal"><?=number_format($w['balance'],6)?> <?=$w['coin']?></div>
        <div class="wcard-usd">≈ $<?=number_format($usdVal,2)?> USD</div>
        <div class="wcard-btns">
          <button class="wbtn wbtn-gold" onclick="openWithdraw('<?=$w['coin']?>','<?=$w['network']?>',<?=$w['balance']?>)">
            <i class="fas fa-paper-plane"></i> سحب خارجي
          </button>
          <?php if($isAdmin): ?>
          <button class="wbtn wbtn-out" onclick="openAdminTransfer('<?=$w['coin']?>','<?=$w['network']?>',<?=$w['balance']?>)">
            <i class="fas fa-exchange-alt"></i> تحويل لعميل
          </button>
          <?php else: ?>
          <button class="wbtn wbtn-out" onclick="openConvert(null,'<?=$w['coin']?>','<?=$w['network']?>')">
            <i class="fas fa-info-circle"></i> التفاصيل
          </button>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- الحركات -->
  <div id="tab-history" style="display:none">
    <div class="sec-title"><i class="fas fa-history" style="color:var(--blue)"></i> آخر الحركات</div>
    <div style="background:var(--card);border:1px solid var(--border);border-radius:18px;overflow:hidden">
      <table class="txn-table">
        <thead>
          <tr>
            <th>المرجع</th><th>النوع</th><th>المبلغ</th><th>العمولة</th><th>الصافي</th><th>الحالة</th><th>التاريخ</th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($recent)): ?>
          <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--muted)">لا توجد حركات بعد</td></tr>
          <?php else: foreach($recent as $t): ?>
          <?php
            $typeLabels=['deposit'=>'إيداع','convert'=>'تحويل','withdraw'=>'سحب','fee'=>'عمولة','admin_credit'=>'إضافة إدارة','admin_debit'=>'خصم إدارة'];
            $stClass=['completed'=>'badge-ok','pending'=>'badge-pen','failed'=>'badge-fail','processing'=>'badge-pen'];
            $stLabel=['completed'=>'مكتمل','pending'=>'قيد التنفيذ','failed'=>'فاشل','processing'=>'جاري'];
            $coin = $t['coin'] ?: $t['currency'];
          ?>
          <tr>
            <td style="font-size:.72rem;color:var(--muted)"><?=htmlspecialchars(substr($t['reference'],0,16))?></td>
            <td><?=$typeLabels[$t['type']]??$t['type']?></td>
            <td><?=number_format($t['amount'],4)?> <?=$coin?></td>
            <td style="color:var(--red)"><?=number_format($t['fee'],4)?></td>
            <td style="color:var(--green)"><?=number_format($t['net_amount'],4)?> <?=$coin?></td>
            <td><span class="badge <?=$stClass[$t['status']]??'badge-pen'?>"><?=$stLabel[$t['status']]??$t['status']?></span></td>
            <td style="font-size:.72rem;color:var(--muted)"><?=date('d/m/Y H:i',strtotime($t['created_at']))?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<!-- Modal إيداع -->
<div class="modal-bg" id="depositModal">
  <div class="modal">
    <button class="close-modal" onclick="closeModal('depositModal')"><i class="fas fa-times"></i></button>
    <h3><i class="fas fa-plus" style="color:var(--gold)"></i> إيداع فيات</h3>
    <div class="fld">
      <label>العملة</label>
      <select id="dep_currency">
        <option value="USD">USD — دولار أمريكي</option>
        <option value="AED">AED — درهم إماراتي</option>
        <option value="EUR">EUR — يورو</option>
      </select>
    </div>
    <div class="fld">
      <label>المبلغ</label>
      <input type="number" id="dep_amount" placeholder="0.00" min="10" step="0.01">
    </div>
    <div class="fld">
      <label>بوابة الدفع</label>
      <select id="dep_gateway">
        <option value="paypal">PayPal</option>
        <option value="binance">Binance Pay</option>
        <option value="gate_io">Gate.io</option>
        <option value="myfatoorah">MyFatoorah</option>
      </select>
    </div>
    <div style="background:rgba(255,215,0,.06);border:1px solid rgba(255,215,0,.15);border-radius:10px;padding:12px;margin-bottom:16px;font-size:.8rem">
      <div style="color:var(--muted)">عمولة المنصة: <span style="color:var(--gold)">1.5%</span></div>
      <div id="dep_preview" style="color:#ccc;margin-top:4px">أدخل المبلغ لعرض التفاصيل</div>
    </div>
    <button class="btn-submit" onclick="submitDeposit()"><i class="fas fa-credit-card"></i> إيداع الآن</button>
  </div>
</div>

<!-- Modal تحويل فيات → كريبتو -->
<div class="modal-bg" id="convertModal">
  <div class="modal">
    <button class="close-modal" onclick="closeModal('convertModal')"><i class="fas fa-times"></i></button>
    <h3><i class="fas fa-exchange-alt" style="color:#10B981"></i> تحويل فيات → كريبتو</h3>
    <div class="fld">
      <label>من (فيات)</label>
      <select id="conv_fiat">
        <option value="USD">USD — دولار أمريكي</option>
        <option value="AED">AED — درهم إماراتي</option>
        <option value="EUR">EUR — يورو</option>
      </select>
    </div>
    <div class="fld">
      <label>المبلغ</label>
      <input type="number" id="conv_amount" placeholder="0.00" min="1" step="0.01" oninput="calcConvert()">
    </div>
    <div class="fld">
      <label>إلى (كريبتو)</label>
      <select id="conv_coin" onchange="calcConvert()">
        <option value="USDT">USDT</option>
        <option value="BTC">BTC</option>
        <option value="ETH">ETH</option>
        <option value="BNB">BNB</option>
      </select>
    </div>
    <div class="fld">
      <label>الشبكة</label>
      <select id="conv_network">
        <option value="TRC20">TRC20 (Tron)</option>
        <option value="ERC20">ERC20 (Ethereum)</option>
        <option value="BEP20">BEP20 (BSC)</option>
        <option value="BTC">BTC (Bitcoin)</option>
      </select>
    </div>
    <div style="background:rgba(16,185,129,.06);border:1px solid rgba(16,185,129,.2);border-radius:10px;padding:12px;margin-bottom:16px;font-size:.8rem">
      <div id="conv_preview" style="color:var(--muted)">أدخل المبلغ لعرض التفاصيل</div>
    </div>
    <button class="btn-submit" style="background:linear-gradient(135deg,#10B981,#059669)" onclick="submitConvert()">
      <i class="fas fa-exchange-alt"></i> تحويل الآن
    </button>
  </div>
</div>

<!-- Modal سحب كريبتو -->
<div class="modal-bg" id="withdrawModal">
  <div class="modal">
    <button class="close-modal" onclick="closeModal('withdrawModal')"><i class="fas fa-times"></i></button>
    <h3><i class="fas fa-paper-plane" style="color:#3B82F6"></i> سحب كريبتو خارجي</h3>
    <div class="fld">
      <label>العملة والشبكة</label>
      <select id="wd_coin_net">
        <option value="USDT|TRC20">USDT — TRC20</option>
        <option value="USDT|ERC20">USDT — ERC20</option>
        <option value="USDT|BEP20">USDT — BEP20</option>
        <option value="BTC|BTC">BTC — Bitcoin</option>
        <option value="ETH|ERC20">ETH — ERC20</option>
        <option value="BNB|BEP20">BNB — BEP20</option>
      </select>
    </div>
    <div class="fld">
      <label>المبلغ</label>
      <input type="number" id="wd_amount" placeholder="0.00" min="0.0001" step="0.0001">
      <div id="wd_balance" style="font-size:.72rem;color:var(--muted);margin-top:4px"></div>
    </div>
    <div class="fld">
      <label>عنوان المحفظة الخارجية</label>
      <input type="text" id="wd_address" placeholder="أدخل العنوان أو امسح QR Code">
    </div>
    <div class="qr-area" onclick="scanQR()">
      <i class="fas fa-qrcode" style="font-size:1.5rem;margin-bottom:6px;display:block"></i>
      اضغط لمسح QR Code
    </div>
    <div style="background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.2);border-radius:10px;padding:10px;margin-bottom:16px;font-size:.78rem;color:#aaa">
      ⚠ رسوم الشبكة: USDT/TRC20 = 1 USDT | BTC = 0.0001 | ETH = 0.005
    </div>
    <button class="btn-submit" style="background:linear-gradient(135deg,#3B82F6,#2563EB)" onclick="submitWithdraw()">
      <i class="fas fa-paper-plane"></i> سحب الآن
    </button>
  </div>
</div>
<script>
function switchTab(name,el){
  ['fiat','crypto','history'].forEach(t=>{
    document.getElementById('tab-'+t).style.display = t===name?'':'none';
  });
  document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active'));
  el.classList.add('active');
}
function openDeposit(cur){
  if(cur) document.getElementById('dep_currency').value=cur;
  document.getElementById('depositModal').classList.add('open');
}
function openConvert(fiat,coin,net){
  if(fiat) document.getElementById('conv_fiat').value=fiat;
  if(coin) document.getElementById('conv_coin').value=coin;
  if(net)  document.getElementById('conv_network').value=net;
  document.getElementById('convertModal').classList.add('open');
}
function openWithdraw(coin,net,bal){
  if(coin&&net) document.getElementById('wd_coin_net').value=coin+'|'+net;
  document.getElementById('wd_balance').textContent = 'الرصيد المتاح: '+(bal||0)+' '+(coin||'');
  document.getElementById('withdrawModal').classList.add('open');
}
function closeModal(id){ document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-bg').forEach(m=>{
  m.addEventListener('click',e=>{ if(e.target===m) m.classList.remove('open'); });
});

// حساب معاينة الإيداع
document.getElementById('dep_amount').addEventListener('input',function(){
  const amt=parseFloat(this.value)||0;
  const fee=(amt*1.5/100).toFixed(2);
  const net=(amt-fee).toFixed(2);
  document.getElementById('dep_preview').innerHTML =
    `عمولة: <span style="color:#ef4444">${fee}</span> | سيُضاف: <span style="color:#10B981">${net}</span>`;
});

// حساب معاينة التحويل
function calcConvert(){
  const amt=parseFloat(document.getElementById('conv_amount').value)||0;
  const coin=document.getElementById('conv_coin').value;
  if(!amt){ document.getElementById('conv_preview').textContent='أدخل المبلغ'; return; }
  fetch(`api/crypto.php?action=rate&coin=${coin}&fiat=${document.getElementById('conv_fiat').value}`)
    .then(r=>r.json()).then(d=>{
      if(d.final_rate){
        const fee=(amt*70/100).toFixed(2);
        const net=(amt-fee).toFixed(6);
        const crypto=(net/d.final_rate).toFixed(6);
        document.getElementById('conv_preview').innerHTML =
          `عمولة المنصة: <span style="color:#ef4444">${fee} (70%)</span> | ستحصل على: <span style="color:#10B981">${crypto} ${coin}</span> بسعر ${d.final_rate}`;
      }
    }).catch(()=>{});
}

// سحب QR
function scanQR(){
  const addr = prompt('أدخل عنوان المحفظة (أو امسح QR من تطبيق المحفظة):');
  if(addr) document.getElementById('wd_address').value=addr.trim();
}

function openAdminTransfer(coin, net, bal){
  document.getElementById('at_coin').value = coin+'|'+net;
  document.getElementById('at_balance').textContent = 'الرصيد: '+bal+' '+coin;
  document.getElementById('adminTransferModal').classList.add('open');
}

async function submitAdminTransfer(){
  const coinNet = document.getElementById('at_coin').value.split('|');
  const amount  = parseFloat(document.getElementById('at_amount').value);
  const address = document.getElementById('at_address').value.trim();
  const note    = document.getElementById('at_note').value.trim();
  if(!amount){ alert('أدخل المبلغ'); return; }
  if(!address){ alert('أدخل العنوان'); return; }
  if(!confirm('تأكيد التحويل: '+amount+' '+coinNet[0]+' إلى '+address.substring(0,20)+'...؟')) return;
  const r = await fetch('api/wallet.php?action=withdraw',{method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({amount,coin:coinNet[0],network:coinNet[1],to_address:address,
      note,csrf_token:CSRF_TOKEN,skip_lock:true})});
  const d = await r.json();
  if(d.success){ closeModal('adminTransferModal'); showToast('✅ تم التحويل — '+d.tx_hash?.substring(0,16)+'...','success'); setTimeout(()=>location.reload(),2000); }
  else alert(d.message||'فشل التحويل');
}

// إرسال إيداع
async function submitDeposit(){
  const amount=parseFloat(document.getElementById('dep_amount').value);
  const currency=document.getElementById('dep_currency').value;
  const gateway=document.getElementById('dep_gateway').value;
  if(!amount||amount<10){alert('الحد الأدنى للإيداع 10');return;}
  const r=await fetch('api/wallet.php?action=deposit',{method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({amount,currency,gateway,csrf_token:CSRF_TOKEN})});
  const d=await r.json();
  if(d.success){
    if(d.redirect) window.location.href=d.redirect;
    else { closeModal('depositModal'); showToast('تم الإيداع بنجاح','success'); setTimeout(()=>location.reload(),1500); }
  } else alert(d.message||'فشل الإيداع');
}

// إرسال تحويل
async function submitConvert(){
  const amount=parseFloat(document.getElementById('conv_amount').value);
  const fiat=document.getElementById('conv_fiat').value;
  const coin=document.getElementById('conv_coin').value;
  const network=document.getElementById('conv_network').value;
  if(!amount||amount<1){alert('أدخل مبلغاً صحيحاً');return;}
  const r=await fetch('api/wallet.php?action=convert',{method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({amount,fiat_currency:fiat,coin,network,csrf_token:CSRF_TOKEN})});
  const d=await r.json();
  if(d.success){ closeModal('convertModal'); showToast(`تم التحويل — ${d.crypto_amount} ${coin}`,'success'); setTimeout(()=>location.reload(),1500); }
  else alert(d.message||'فشل التحويل');
}

// إرسال سحب
async function submitWithdraw(){
  const coinNet=document.getElementById('wd_coin_net').value.split('|');
  const amount=parseFloat(document.getElementById('wd_amount').value);
  const address=document.getElementById('wd_address').value.trim();
  if(!amount){alert('أدخل المبلغ');return;}
  if(!address){alert('أدخل عنوان المحفظة');return;}
  if(!confirm(`تأكيد السحب: ${amount} ${coinNet[0]} إلى ${address.substring(0,20)}...؟`))return;
  const r=await fetch('api/wallet.php?action=withdraw',{method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({amount,coin:coinNet[0],network:coinNet[1],to_address:address,csrf_token:CSRF_TOKEN})});
  const d=await r.json();
  if(d.success){ closeModal('withdrawModal'); showToast('تم السحب — '+d.tx_hash?.substring(0,16)+'...','success'); setTimeout(()=>location.reload(),2000); }
  else alert(d.message||'فشل السحب');
}

function showToast(msg,type){
  const t=document.createElement('div');
  t.style.cssText=`position:fixed;bottom:24px;right:24px;background:${type==='success'?'#10B981':'#EF4444'};
    color:#fff;padding:14px 24px;border-radius:12px;font-family:Cairo,sans-serif;font-size:.85rem;
    font-weight:700;z-index:999;box-shadow:0 8px 24px rgba(0,0,0,.4)`;
  t.textContent=msg; document.body.appendChild(t);
  setTimeout(()=>t.remove(),3000);
}

const CSRF_TOKEN = '<?=generateCsrfToken()?>';
const IS_ADMIN   = <?=$isAdmin?'true':'false'?>;
</script>
<!-- Modal تحويل الإدارة لمحفظة خارجية -->
<?php if($isAdmin): ?>
<div class="modal-bg" id="adminTransferModal">
  <div class="modal">
    <button class="close-modal" onclick="closeModal('adminTransferModal')"><i class="fas fa-times"></i></button>
    <h3><i class="fas fa-paper-plane" style="color:#3B82F6"></i> تحويل لمحفظة خارجية</h3>
    <div class="fld">
      <label>العملة والشبكة</label>
      <select id="at_coin">
        <?php foreach($cryptos as $w): if($w['balance']>0): ?>
        <option value="<?=$w['coin']?>|<?=$w['network']?>"><?=$w['coin']?> / <?=$w['network']?> (<?=number_format($w['balance'],6)?>)</option>
        <?php endif; endforeach; ?>
      </select>
    </div>
    <div class="fld">
      <label>المبلغ</label>
      <input type="number" id="at_amount" placeholder="0.000000" min="0.000001" step="0.000001">
      <div id="at_balance" style="font-size:.72rem;color:var(--muted);margin-top:4px"></div>
    </div>
    <div class="fld">
      <label>عنوان المحفظة الخارجية</label>
      <input type="text" id="at_address" placeholder="أدخل العنوان أو امسح QR">
    </div>
    <div class="fld">
      <label>ملاحظة (اختياري)</label>
      <input type="text" id="at_note" placeholder="سبب التحويل">
    </div>
    <div style="background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.2);border-radius:10px;padding:10px;margin-bottom:16px;font-size:.78rem;color:#aaa">
      ⚠ بدون قيد 24 ساعة — تحويل فوري
    </div>
    <button class="btn-submit" style="background:linear-gradient(135deg,#3B82F6,#2563EB)" onclick="submitAdminTransfer()">
      <i class="fas fa-paper-plane"></i> تحويل فوري
    </button>
  </div>
</div>
<?php endif; ?>

</body>
</html>
