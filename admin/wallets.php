<?php
require_once '../includes/config.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth_check.php';
if(($_SESSION['role']??'')!=='admin'){header('Location:../index.php');exit;}
require_once '../lib/WalletManager.php';

$wm = WalletManager::getInstance();
$db = db();

// إجمالي محافظ الشركة
$companyWallets = $db->fetchAll("SELECT * FROM company_wallets ORDER BY wallet_type,currency") ?: [];

// إحصاءات
$totalUsers    = $db->fetchOne("SELECT COUNT(*) c FROM users WHERE status='active'")['c'] ?? 0;
$totalDeposits = $db->fetchOne("SELECT COALESCE(SUM(amount),0) s FROM wallet_transactions WHERE type='deposit' AND status='completed'")['s'] ?? 0;
$totalFees     = $db->fetchOne("SELECT COALESCE(SUM(fee),0) s FROM wallet_transactions WHERE status='completed'")['s'] ?? 0;
$pendingWd     = $db->fetchOne("SELECT COUNT(*) c FROM wallet_transactions WHERE type='withdraw' AND status='pending'")['c'] ?? 0;

// قائمة المستخدمين مع أرصدتهم
$users = $db->fetchAll("
    SELECT u.id,u.username,u.email,
        COALESCE((SELECT SUM(balance) FROM user_fiat_wallets WHERE user_id=u.id),0) fiat_total,
        COALESCE((SELECT SUM(balance) FROM user_crypto_wallets WHERE user_id=u.id),0) crypto_total,
        COALESCE((SELECT COUNT(*) FROM wallet_transactions WHERE user_id=u.id),0) txn_count
    FROM users u WHERE u.status='active'
    ORDER BY fiat_total DESC LIMIT 50
") ?: [];

// آخر الحركات
$recentTxns = $db->fetchAll("
    SELECT wt.*,u.username FROM wallet_transactions wt
    LEFT JOIN users u ON u.id=wt.user_id
    ORDER BY wt.created_at DESC LIMIT 20
") ?: [];
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>إدارة المحافظ — DI PARMA</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--gold:#FFD700;--gold2:#FFB700;--bg:#040810;--card:#080d1a;--card2:#0a1020;
  --border:rgba(255,215,0,.12);--text:#f0f0f0;--muted:#666;--green:#10B981;--red:#EF4444;--blue:#3B82F6}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text)}
.top-bar{background:rgba(4,8,16,.95);border-bottom:1px solid var(--border);padding:0 24px;height:60px;
  display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:99}
.top-brand{color:var(--gold);font-weight:900}
.top-nav a{color:var(--muted);font-size:.82rem;padding:6px 14px;border-radius:20px;text-decoration:none;transition:.2s}
.top-nav a:hover{color:var(--gold)}
.wrap{max-width:1400px;margin:0 auto;padding:32px 20px}
.pg-title{font-size:1.4rem;font-weight:900;margin-bottom:24px;display:flex;align-items:center;gap:10px}
/* إحصاءات */
.stats{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px;margin-bottom:32px}
.stat{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:20px;position:relative;overflow:hidden}
.stat-line{position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,var(--cl),transparent)}
.stat-lbl{font-size:.72rem;color:var(--muted);margin-bottom:8px}
.stat-val{font-size:1.6rem;font-weight:900;color:var(--cl)}
/* محافظ الشركة */
.company-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:32px}
.cw{background:var(--card2);border:1px solid var(--border);border-radius:14px;padding:18px}
.cw-type{font-size:.68rem;color:var(--muted);margin-bottom:4px}
.cw-cur{font-size:.9rem;font-weight:800;margin-bottom:8px}
.cw-bal{font-size:1.3rem;font-weight:900;color:var(--gold)}
.cw-tot{font-size:.68rem;color:var(--muted);margin-top:4px}
/* جدول */
.tbl-wrap{background:var(--card);border:1px solid var(--border);border-radius:18px;overflow:hidden;margin-bottom:32px}
.tbl-head{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.tbl-head h3{font-size:.95rem;font-weight:800}
table{width:100%;border-collapse:collapse}
th{font-size:.72rem;color:var(--muted);font-weight:600;padding:10px 14px;text-align:right;border-bottom:1px solid var(--border)}
td{padding:11px 14px;font-size:.82rem;border-bottom:1px solid rgba(255,215,0,.04)}
tr:hover td{background:rgba(255,255,255,.02)}
.badge{padding:3px 10px;border-radius:8px;font-size:.68rem;font-weight:700}
.ok{background:rgba(16,185,129,.15);color:#10B981}
.pen{background:rgba(251,191,36,.15);color:#FBB724}
.fail{background:rgba(239,68,68,.15);color:#EF4444}
/* أزرار */
.btn-sm{padding:5px 12px;border-radius:8px;font-size:.72rem;font-weight:700;cursor:pointer;border:none;font-family:'Cairo',sans-serif}
.btn-gold{background:linear-gradient(135deg,var(--gold),var(--gold2));color:#000}
.btn-red{background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.3);color:#EF4444}
.btn-blue{background:rgba(59,130,246,.15);border:1px solid rgba(59,130,246,.3);color:#93C5FD}
/* Modal */
.modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:200;display:none;align-items:center;justify-content:center}
.modal-bg.open{display:flex}
.modal{background:var(--card);border:1px solid var(--border);border-radius:24px;padding:32px;width:100%;max-width:460px;position:relative}
.modal h3{font-size:1.05rem;font-weight:900;margin-bottom:18px}
.fld{margin-bottom:14px}
.fld label{display:block;font-size:.76rem;color:var(--muted);margin-bottom:5px}
.fld input,.fld select{width:100%;background:rgba(255,255,255,.05);border:1px solid var(--border);
  border-radius:10px;padding:10px 14px;color:var(--text);font-family:'Cairo',sans-serif;font-size:.87rem}
.btn-full{width:100%;background:linear-gradient(135deg,var(--gold),var(--gold2));color:#000;
  padding:13px;border-radius:12px;font-weight:800;font-size:.92rem;border:none;cursor:pointer;font-family:'Cairo',sans-serif}
.close-x{position:absolute;top:14px;left:14px;background:rgba(255,255,255,.06);border:none;
  color:#ccc;width:30px;height:30px;border-radius:8px;cursor:pointer}
.tabs{display:flex;gap:4px;background:rgba(255,255,255,.04);border:1px solid var(--border);
  border-radius:12px;padding:3px;margin-bottom:24px;width:fit-content}
.tab{padding:7px 20px;border-radius:8px;font-size:.82rem;font-weight:700;cursor:pointer;color:var(--muted)}
.tab.active{background:linear-gradient(135deg,var(--gold),var(--gold2));color:#000}
</style>
</head>
<body>
<nav class="top-bar">
  <div class="top-brand"><i class="fas fa-coins"></i> DI PARMA — Admin</div>
  <div class="top-nav">
    <a href="users.php"><i class="fas fa-users"></i> المستخدمون</a>
    <a href="wallets.php" style="color:var(--gold)"><i class="fas fa-wallet"></i> المحافظ</a>
    <a href="bank_gateways.php"><i class="fas fa-university"></i> البوابات</a>
    <a href="../index.php?logout=1"><i class="fas fa-sign-out-alt"></i> خروج</a>
  </div>
</nav>
<div class="wrap">
  <div class="pg-title"><i class="fas fa-wallet" style="color:var(--gold)"></i> إدارة المحافظ</div>

  <!-- إحصاءات -->
  <div class="stats">
    <div class="stat" style="--cl:#FFD700"><div class="stat-line"></div>
      <div class="stat-lbl">إجمالي الإيداعات</div>
      <div class="stat-val">$<?=number_format($totalDeposits,0)?></div>
    </div>
    <div class="stat" style="--cl:#10B981"><div class="stat-line"></div>
      <div class="stat-lbl">إجمالي العمولات</div>
      <div class="stat-val">$<?=number_format($totalFees,0)?></div>
    </div>
    <div class="stat" style="--cl:#3B82F6"><div class="stat-line"></div>
      <div class="stat-lbl">مستخدمون نشطون</div>
      <div class="stat-val"><?=$totalUsers?></div>
    </div>
    <div class="stat" style="--cl:#F59E0B"><div class="stat-line"></div>
      <div class="stat-lbl">سحوبات معلقة</div>
      <div class="stat-val"><?=$pendingWd?></div>
    </div>
  </div>

  <!-- محافظ الشركة -->
  <div style="margin-bottom:10px;font-size:.95rem;font-weight:800"><i class="fas fa-building" style="color:var(--gold)"></i> محافظ الشركة</div>
  <div class="company-grid">
    <?php foreach($companyWallets as $cw): ?>
    <div class="cw">
      <div class="cw-type"><?=$cw['wallet_type']==='fiat'?'فيات':'كريبتو'?></div>
      <div class="cw-cur"><?=$cw['currency']?><?=$cw['network']?' / '.$cw['network']:''?></div>
      <div class="cw-bal"><?=number_format($cw['balance'],4)?></div>
      <div class="cw-tot">إجمالي استلام: <?=number_format($cw['total_received'],4)?></div>
    </div>
    <?php endforeach; ?>
    <div class="cw" style="border:2px dashed rgba(255,215,0,.2);cursor:pointer" onclick="openAdminCredit()">
      <div style="text-align:center;padding:10px;color:var(--muted)">
        <i class="fas fa-plus" style="font-size:1.5rem;display:block;margin-bottom:6px"></i>
        إضافة/خصم يدوي
      </div>
    </div>
  </div>

  <!-- تبويبات -->
  <div class="tabs">
    <div class="tab active" onclick="switchTab('users',this)"><i class="fas fa-users"></i> محافظ العملاء</div>
    <div class="tab" onclick="switchTab('txns',this)"><i class="fas fa-list"></i> آخر الحركات</div>
  </div>

  <!-- محافظ العملاء -->
  <div id="tab-users">
    <div class="tbl-wrap">
      <div class="tbl-head">
        <h3><i class="fas fa-users" style="color:var(--blue)"></i> أرصدة العملاء</h3>
        <input type="text" placeholder="بحث..." id="searchUser" oninput="filterUsers()"
          style="background:rgba(255,255,255,.05);border:1px solid var(--border);border-radius:8px;padding:6px 12px;color:var(--text);font-size:.8rem;font-family:'Cairo',sans-serif;width:200px">
      </div>
      <table>
        <thead>
          <tr><th>المستخدم</th><th>البريد</th><th>فيات (USD)</th><th>كريبتو (USDT)</th><th>المعاملات</th><th>إجراء</th></tr>
        </thead>
        <tbody id="usersBody">
          <?php foreach($users as $u): ?>
          <tr>
            <td><strong><?=htmlspecialchars($u['username'])?></strong></td>
            <td style="color:var(--muted);font-size:.75rem"><?=htmlspecialchars($u['email'])?></td>
            <td style="color:var(--gold);font-weight:700"><?=number_format($u['fiat_total'],2)?></td>
            <td style="color:#10B981;font-weight:700"><?=number_format($u['crypto_total'],6)?></td>
            <td style="color:var(--muted)"><?=$u['txn_count']?></td>
            <td>
              <button class="btn-sm btn-blue" onclick="viewUser(<?=$u['id']?>,<?=htmlspecialchars(json_encode($u['username']))?>')">
                <i class="fas fa-eye"></i> عرض
              </button>
              <button class="btn-sm btn-gold" onclick="openCredit(<?=$u['id']?>,<?=htmlspecialchars(json_encode($u['username']))?>')">
                <i class="fas fa-plus"></i> إضافة
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- آخر الحركات -->
  <div id="tab-txns" style="display:none">
    <div class="tbl-wrap">
      <div class="tbl-head">
        <h3><i class="fas fa-list" style="color:var(--green)"></i> آخر الحركات</h3>
      </div>
      <table>
        <thead>
          <tr><th>المرجع</th><th>المستخدم</th><th>النوع</th><th>المبلغ</th><th>العمولة</th><th>الصافي</th><th>الحالة</th><th>التاريخ</th><th>إجراء</th></tr>
        </thead>
        <tbody>
          <?php
          $typeMap=['deposit'=>'إيداع','convert'=>'تحويل','withdraw'=>'سحب','fee'=>'عمولة','admin_credit'=>'إضافة إدارة','admin_debit'=>'خصم إدارة'];
          $stMap=['completed'=>['ok','مكتمل'],'pending'=>['pen','معلق'],'failed'=>['fail','فاشل'],'processing'=>['pen','جاري']];
          foreach($recentTxns as $t):
            $coin=$t['coin']?:$t['currency'];
            $st=$stMap[$t['status']]??['pen',$t['status']];
          ?>
          <tr>
            <td style="font-size:.7rem;color:var(--muted)"><?=substr($t['reference'],0,14)?></td>
            <td style="font-weight:700"><?=htmlspecialchars($t['username']??'—')?></td>
            <td><?=$typeMap[$t['type']]??$t['type']?></td>
            <td><?=number_format($t['amount'],4)?> <?=$coin?></td>
            <td style="color:var(--red)"><?=number_format($t['fee'],4)?></td>
            <td style="color:var(--green)"><?=number_format($t['net_amount'],4)?> <?=$coin?></td>
            <td><span class="badge <?=$st[0]?>"><?=$st[1]?></span></td>
            <td style="font-size:.7rem;color:var(--muted)"><?=date('d/m H:i',strtotime($t['created_at']))?></td>
            <td>
              <?php if($t['status']==='pending'&&$t['type']==='withdraw'): ?>
              <button class="btn-sm btn-gold" onclick="approveWd('<?=$t['reference']?>')">موافقة</button>
              <button class="btn-sm btn-red" onclick="rejectWd('<?=$t['reference']?>')">رفض</button>
              <?php else: ?>
              <span style="color:var(--muted);font-size:.7rem">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal إضافة/خصم يدوي -->
<div class="modal-bg" id="creditModal">
  <div class="modal">
    <button class="close-x" onclick="closeModal('creditModal')"><i class="fas fa-times"></i></button>
    <h3><i class="fas fa-plus" style="color:var(--gold)"></i> إضافة/خصم يدوي</h3>
    <input type="hidden" id="credit_uid">
    <div class="fld">
      <label>المستخدم</label>
      <input type="text" id="credit_uname" readonly>
    </div>
    <div class="fld">
      <label>النوع</label>
      <select id="credit_type">
        <option value="admin_credit">إضافة رصيد</option>
        <option value="admin_debit">خصم رصيد</option>
      </select>
    </div>
    <div class="fld">
      <label>نوع المحفظة</label>
      <select id="credit_wallet">
        <option value="fiat">فيات</option>
        <option value="crypto">كريبتو</option>
      </select>
    </div>
    <div class="fld">
      <label>العملة</label>
      <select id="credit_currency">
        <option value="USD">USD</option>
        <option value="AED">AED</option>
        <option value="USDT">USDT</option>
        <option value="BTC">BTC</option>
        <option value="ETH">ETH</option>
      </select>
    </div>
    <div class="fld">
      <label>المبلغ</label>
      <input type="number" id="credit_amount" placeholder="0.00" min="0" step="0.0001">
    </div>
    <div class="fld">
      <label>ملاحظة</label>
      <input type="text" id="credit_note" placeholder="سبب الإضافة/الخصم">
    </div>
    <button class="btn-full" onclick="submitCredit()"><i class="fas fa-check"></i> تأكيد</button>
  </div>
</div>

<script>
const CSRF = '<?=generateCsrfToken()?>';

function switchTab(name,el){
  ['users','txns'].forEach(t=>{ document.getElementById('tab-'+t).style.display=t===name?'':'none'; });
  document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active'));
  el.classList.add('active');
}

function openCredit(uid,uname){
  document.getElementById('credit_uid').value=uid;
  document.getElementById('credit_uname').value=uname;
  document.getElementById('creditModal').classList.add('open');
}
function openAdminCredit(){
  document.getElementById('credit_uid').value='0';
  document.getElementById('credit_uname').value='الشركة';
  document.getElementById('creditModal').classList.add('open');
}
function closeModal(id){ document.getElementById(id).classList.remove('open'); }

async function submitCredit(){
  const uid=document.getElementById('credit_uid').value;
  const type=document.getElementById('credit_type').value;
  const wallet=document.getElementById('credit_wallet').value;
  const currency=document.getElementById('credit_currency').value;
  const amount=parseFloat(document.getElementById('credit_amount').value);
  const note=document.getElementById('credit_note').value;
  if(!amount){alert('أدخل المبلغ');return;}
  const r=await fetch('api/admin_wallet.php',{method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'admin_adjust',user_id:uid,type,wallet_type:wallet,currency,amount,note,csrf_token:CSRF})});
  const d=await r.json();
  if(d.success){closeModal('creditModal');alert('تم بنجاح');location.reload();}
  else alert(d.message||'فشل');
}

async function approveWd(ref){
  if(!confirm('موافقة على السحب '+ref+'؟'))return;
  const r=await fetch('api/admin_wallet.php',{method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'approve_withdraw',reference:ref,csrf_token:CSRF})});
  const d=await r.json();
  if(d.success){alert('تم الموافقة');location.reload();}
  else alert(d.message);
}

async function rejectWd(ref){
  if(!confirm('رفض السحب '+ref+'؟'))return;
  const r=await fetch('api/admin_wallet.php',{method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'reject_withdraw',reference:ref,csrf_token:CSRF})});
  const d=await r.json();
  if(d.success){alert('تم الرفض');location.reload();}
  else alert(d.message);
}

function viewUser(uid,uname){
  window.location.href='wallets.php?user='+uid;
}

function filterUsers(){
  const q=document.getElementById('searchUser').value.toLowerCase();
  document.querySelectorAll('#usersBody tr').forEach(tr=>{
    tr.style.display=tr.textContent.toLowerCase().includes(q)?'':'none';
  });
}
</script>
</body>
</html>
