<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$db  = db();
$msg = '';

// ── تحويل للمحفظة الخارجية ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'withdraw') {
    $coin    = strtoupper(trim($_POST['coin']    ?? 'USDT'));
    $network = strtoupper(trim($_POST['network'] ?? 'TRC20'));
    $amount  = floatval($_POST['amount'] ?? 0);
    $toAddr  = trim($_POST['to_address'] ?? '');

    if ($amount <= 0 || empty($toAddr)) {
        $msg = ['type'=>'error','text'=>'❌ أدخل المبلغ والعنوان'];
    } else {
        // تحقق من الرصيد
        $cw = $db->query(
            "SELECT balance FROM company_wallets WHERE wallet_type='crypto' AND currency=? AND (network=? OR network='')",
            [$coin, $network]
        );
        $balance = floatval($cw[0]['balance'] ?? 0);

        if ($balance < $amount) {
            $msg = ['type'=>'error','text'=>"❌ رصيد غير كافٍ — المتاح: $balance $coin"];
        } else {
            try {
                require_once __DIR__ . '/../lib/HotWalletService.php';
                $ref = 'CWDR' . date('Ymd') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
                $hw  = HotWalletService::getInstance();
                $tx  = $hw->sendUSDT($ref, $toAddr, $amount, intval($_SESSION['user_id']));

                if ($tx['success']) {
                    $db->execute(
                        "UPDATE company_wallets SET balance=balance-?, total_sent=total_sent+? WHERE wallet_type='crypto' AND currency=?",
                        [$amount, $amount, $coin]
                    );
                    $msg = ['type'=>'success','text'=>"✅ تم السحب — {$amount} {$coin} | TX: " . substr($tx['tx_hash'],0,20) . '...'];
                } else {
                    $msg = ['type'=>'error','text'=>'❌ فشل السحب: ' . $tx['message']];
                }
            } catch (Exception $e) {
                $msg = ['type'=>'error','text'=>'❌ خطأ: ' . $e->getMessage()];
            }
        }
    }
}

// ── جلب محافظ الشركة ─────────────────────────────────
$cryptoWallets = $db->query(
    "SELECT * FROM company_wallets WHERE wallet_type='crypto' ORDER BY currency, network"
) ?: [];
$fiatWallets = $db->query(
    "SELECT * FROM company_wallets WHERE wallet_type='fiat' ORDER BY currency"
) ?: [];

// ── إحصاءات ──────────────────────────────────────────
$totalFees = $db->query(
    "SELECT COALESCE(SUM(fee),0) s FROM wallet_transactions WHERE status='completed'"
) ?: [];
$todayFees = $db->query(
    "SELECT COALESCE(SUM(fee),0) s FROM wallet_transactions WHERE status='completed' AND DATE(created_at)=CURDATE()"
) ?: [];
$totalOffline = $db->query(
    "SELECT COUNT(*) c, COALESCE(SUM(amount),0) s FROM dp_transactions WHERE gateway='offline' AND status='completed'"
) ?: [];
$recentTxns = $db->query(
    "SELECT wt.*, u.username FROM wallet_transactions wt
     LEFT JOIN dp_users u ON u.id=wt.user_id
     WHERE wt.type IN ('deposit','fee')
     ORDER BY wt.created_at DESC LIMIT 20"
) ?: [];
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | محفظة الشركة</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--gold:#FFD700;--gold2:#FFB700;--bg:#040810;--card:#080d1a;--card2:#0a1020;
  --border:rgba(255,215,0,.15);--text:#f0f0f0;--muted:#666;--green:#10B981;--red:#EF4444;--blue:#3B82F6}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text)}
.top-bar{background:rgba(4,8,16,.95);border-bottom:1px solid var(--border);padding:0 24px;
  height:60px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:99}
.top-brand{color:var(--gold);font-weight:900;font-size:1.1rem}
.top-nav a{color:var(--muted);font-size:.82rem;padding:6px 14px;border-radius:20px;text-decoration:none;transition:.2s}
.top-nav a:hover{color:var(--gold)}
.wrap{max-width:1400px;margin:0 auto;padding:28px 20px}
.pg-title{font-size:1.3rem;font-weight:900;margin-bottom:24px;display:flex;align-items:center;gap:10px}
.msg{padding:14px 18px;border-radius:12px;margin-bottom:20px;font-size:.88rem;font-weight:700}
.msg.success{background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.3);color:#10B981}
.msg.error{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#EF4444}
/* Stats */
.stats{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;margin-bottom:28px}
.stat{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:20px;position:relative;overflow:hidden}
.stat-line{position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,transparent,var(--cl),transparent)}
.stat-lbl{font-size:.72rem;color:var(--muted);margin-bottom:8px}
.stat-val{font-size:1.5rem;font-weight:900;color:var(--cl)}
.stat-sub{font-size:.7rem;color:var(--muted);margin-top:4px}
/* Wallet Cards */
.wallet-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;margin-bottom:28px}
.wcard{background:var(--card2);border:1px solid var(--border);border-radius:18px;padding:22px;transition:.3s}
.wcard:hover{border-color:rgba(255,215,0,.3)}
.wcard-head{display:flex;align-items:center;gap:12px;margin-bottom:14px}
.wcard-ico{width:44px;height:44px;border-radius:12px;background:rgba(255,215,0,.1);display:flex;align-items:center;justify-content:center;font-size:1.4rem}
.wcard-name{font-size:.95rem;font-weight:800}
.wcard-net{font-size:.68rem;color:var(--muted)}
.wcard-bal{font-size:1.6rem;font-weight:900;color:var(--gold);margin-bottom:4px}
.wcard-recv{font-size:.72rem;color:var(--muted)}
.wcard-btn{width:100%;margin-top:14px;padding:9px;border-radius:10px;border:none;cursor:pointer;
  background:linear-gradient(135deg,var(--gold),var(--gold2));color:#000;
  font-family:'Cairo',sans-serif;font-size:.82rem;font-weight:800}
/* Section */
.sec-title{font-size:1rem;font-weight:800;margin-bottom:16px;display:flex;align-items:center;gap:8px;color:var(--text)}
/* Table */
.tbl-wrap{background:var(--card);border:1px solid var(--border);border-radius:18px;overflow:hidden;margin-bottom:28px}
.tbl-head{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.tbl-head h3{font-size:.95rem;font-weight:800}
table{width:100%;border-collapse:collapse}
th{font-size:.72rem;color:var(--muted);font-weight:600;padding:10px 14px;text-align:right;border-bottom:1px solid var(--border)}
td{padding:11px 14px;font-size:.82rem;border-bottom:1px solid rgba(255,215,0,.04)}
tr:hover td{background:rgba(255,255,255,.02)}
.badge-ok{background:rgba(16,185,129,.15);color:#10B981;padding:3px 10px;border-radius:8px;font-size:.68rem;font-weight:700}
/* Modal */
.modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:200;display:none;align-items:center;justify-content:center}
.modal-bg.open{display:flex}
.modal{background:var(--card);border:1px solid var(--border);border-radius:24px;padding:32px;width:100%;max-width:460px;position:relative}
.modal h3{font-size:1.05rem;font-weight:900;margin-bottom:18px;display:flex;align-items:center;gap:8px}
.fld{margin-bottom:14px}
.fld label{display:block;font-size:.76rem;color:var(--muted);margin-bottom:5px;font-weight:600}
.fld input,.fld select{width:100%;background:rgba(255,255,255,.05);border:1.5px solid var(--border);
  border-radius:10px;padding:10px 14px;color:var(--text);font-family:'Cairo',sans-serif;font-size:.87rem}
.fld input:focus,.fld select:focus{outline:none;border-color:var(--gold)}
.btn-full{width:100%;background:linear-gradient(135deg,var(--gold),var(--gold2));color:#000;
  padding:13px;border-radius:12px;font-weight:800;font-size:.92rem;border:none;cursor:pointer;font-family:'Cairo',sans-serif;margin-top:8px}
.close-x{position:absolute;top:14px;left:14px;background:rgba(255,255,255,.06);border:none;
  color:#ccc;width:30px;height:30px;border-radius:8px;cursor:pointer;font-size:1rem}
</style>
</head>
<body>
<nav class="top-bar">
  <div class="top-brand"><i class="fas fa-coins"></i> DI PARMA — Admin</div>
  <div class="top-nav">
    <a href="../dashboard.php"><i class="fas fa-th-large"></i> لوحة التحكم</a>
    <a href="company_wallet.php" style="color:var(--gold)"><i class="fas fa-building"></i> محفظة الشركة</a>
    <a href="offline_approvals.php"><i class="fas fa-file-invoice"></i> Offline</a>
    <a href="wallets.php"><i class="fas fa-users"></i> محافظ العملاء</a>
  </div>
</nav>

<div class="wrap">
  <div class="pg-title">
    <i class="fas fa-building" style="color:var(--gold)"></i>
    محفظة الشركة — DI PARMA
  </div>

  <?php if ($msg): ?>
  <div class="msg <?=$msg['type']?>"><?=$msg['text']?></div>
  <?php endif; ?>

  <!-- إحصاءات -->
  <div class="stats">
    <div class="stat" style="--cl:#FFD700"><div class="stat-line"></div>
      <div class="stat-lbl">إجمالي العمولات</div>
      <div class="stat-val">$<?=number_format($totalFees[0]['s']??0,2)?></div>
      <div class="stat-sub">جميع الوقت</div>
    </div>
    <div class="stat" style="--cl:#10B981"><div class="stat-line"></div>
      <div class="stat-lbl">عمولات اليوم</div>
      <div class="stat-val">$<?=number_format($todayFees[0]['s']??0,2)?></div>
      <div class="stat-sub"><?=date('Y-m-d')?></div>
    </div>
    <div class="stat" style="--cl:#3B82F6"><div class="stat-line"></div>
      <div class="stat-lbl">Offline Sales</div>
      <div class="stat-val"><?=$totalOffline[0]['c']??0?></div>
      <div class="stat-sub">$<?=number_format($totalOffline[0]['s']??0,0)?> إجمالي</div>
    </div>
    <div class="stat" style="--cl:#8B5CF6"><div class="stat-line"></div>
      <div class="stat-lbl">Hot Wallet (TRC20)</div>
      <div class="stat-val" style="font-size:1rem"><?=substr(getenv('HOT_WALLET_TRC20_ADDRESS')?:'—',0,12)?>...</div>
      <div class="stat-sub">محفظة الإرسال</div>
    </div>
  </div>

  <!-- محافظ الكريبتو -->
  <div class="sec-title">
    <i class="fab fa-bitcoin" style="color:#F7931A"></i>
    محافظ الكريبتو
  </div>
  <div class="wallet-grid">
    <?php
    $coinIcos = ['USDT'=>'💚','BTC'=>'🟠','ETH'=>'🔷','BNB'=>'🟡'];
    foreach($cryptoWallets as $w):
    ?>
    <div class="wcard">
      <div class="wcard-head">
        <div class="wcard-ico"><?=$coinIcos[$w['currency']]??'🪙'?></div>
        <div>
          <div class="wcard-name"><?=$w['currency']?></div>
          <div class="wcard-net"><?=$w['network']?:''?></div>
        </div>
      </div>
      <div class="wcard-bal"><?=number_format($w['balance'],6)?></div>
      <div class="wcard-recv">إجمالي استلام: <?=number_format($w['total_received'],6)?></div>
      <div class="wcard-recv">إجمالي إرسال: <?=number_format($w['total_sent'],6)?></div>
      <?php if($w['balance'] > 0): ?>
      <button class="wcard-btn" onclick="openWithdraw('<?=$w['currency']?>','<?=$w['network']?>',<?=$w['balance']?>)">
        <i class="fas fa-paper-plane"></i> سحب خارجي
      </button>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php if(empty($cryptoWallets)): ?>
    <div style="color:var(--muted);padding:20px">لا توجد أرصدة كريبتو بعد</div>
    <?php endif; ?>
  </div>

  <!-- محافظ الفيات -->
  <div class="sec-title">
    <i class="fas fa-dollar-sign" style="color:var(--gold)"></i>
    محافظ الفيات
  </div>
  <div class="wallet-grid">
    <?php
    $fiatIcos = ['USD'=>'💵','AED'=>'🇦🇪','EUR'=>'💶','SAR'=>'🇸🇦'];
    foreach($fiatWallets as $w):
    ?>
    <div class="wcard">
      <div class="wcard-head">
        <div class="wcard-ico"><?=$fiatIcos[$w['currency']]??'💰'?></div>
        <div>
          <div class="wcard-name"><?=$w['currency']?></div>
          <div class="wcard-net">فيات داخلي</div>
        </div>
      </div>
      <div class="wcard-bal"><?=number_format($w['balance'],2)?> <?=$w['currency']?></div>
      <div class="wcard-recv">إجمالي استلام: <?=number_format($w['total_received'],2)?></div>
    </div>
    <?php endforeach; ?>
    <?php if(empty($fiatWallets)): ?>
    <div style="color:var(--muted);padding:20px">لا توجد أرصدة فيات بعد</div>
    <?php endif; ?>
  </div>

  <!-- آخر الحركات -->
  <div class="tbl-wrap">
    <div class="tbl-head">
      <h3><i class="fas fa-history" style="color:var(--green)"></i> آخر الحركات</h3>
    </div>
    <?php if(empty($recentTxns)): ?>
    <div style="padding:32px;text-align:center;color:var(--muted)">لا توجد حركات بعد</div>
    <?php else: ?>
    <table>
      <thead>
        <tr><th>المرجع</th><th>العميل</th><th>النوع</th><th>العملة</th><th>المبلغ</th><th>العمولة</th><th>الحالة</th><th>التاريخ</th></tr>
      </thead>
      <tbody>
        <?php foreach($recentTxns as $t):
          $coin = $t['coin'] ?: $t['currency'];
        ?>
        <tr>
          <td style="font-size:.7rem;color:var(--muted)"><?=substr($t['reference'],0,14)?></td>
          <td style="font-weight:700"><?=htmlspecialchars($t['username']??'—')?></td>
          <td><?=$t['type']==='deposit'?'إيداع':'عمولة'?></td>
          <td><?=$coin?></td>
          <td style="color:var(--gold);font-weight:700"><?=number_format($t['amount'],4)?></td>
          <td style="color:var(--red)"><?=number_format($t['fee'],4)?></td>
          <td><span class="badge-ok">مكتمل</span></td>
          <td style="font-size:.7rem;color:var(--muted)"><?=date('d/m H:i',strtotime($t['created_at']))?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<!-- Modal سحب -->
<div class="modal-bg" id="wdModal">
  <div class="modal">
    <button class="close-x" onclick="document.getElementById('wdModal').classList.remove('open')"><i class="fas fa-times"></i></button>
    <h3><i class="fas fa-paper-plane" style="color:var(--blue)"></i> سحب من محفظة الشركة</h3>
    <form method="POST">
      <input type="hidden" name="action" value="withdraw">
      <div class="fld">
        <label>العملة والشبكة</label>
        <input type="text" name="coin" id="wd_coin" readonly style="background:rgba(255,215,0,.06)">
        <input type="hidden" name="network" id="wd_network">
      </div>
      <div class="fld">
        <label>المبلغ المتاح</label>
        <input type="number" name="amount" id="wd_amount" step="0.000001" min="0.000001" placeholder="0.000000" required>
        <div id="wd_max" style="font-size:.72rem;color:var(--muted);margin-top:4px"></div>
      </div>
      <div class="fld">
        <label>عنوان المحفظة الخارجية</label>
        <input type="text" name="to_address" placeholder="أدخل العنوان" required>
      </div>
      <div style="background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.2);border-radius:10px;padding:10px;margin-bottom:14px;font-size:.78rem;color:#aaa">
        ⚠ تأكد من صحة العنوان — العمليات لا يمكن التراجع عنها
      </div>
      <button type="submit" class="btn-full"><i class="fas fa-paper-plane"></i> تأكيد السحب</button>
    </form>
  </div>
</div>

<script>
function openWithdraw(coin, network, balance) {
    document.getElementById('wd_coin').value = coin + ' / ' + network;
    document.getElementById('wd_network').value = network;
    document.getElementById('wd_max').textContent = 'الرصيد المتاح: ' + balance + ' ' + coin;
    document.getElementById('wd_amount').max = balance;
    document.getElementById('wdModal').classList.add('open');
}
document.getElementById('wdModal').addEventListener('click', function(e) {
    if(e.target === this) this.classList.remove('open');
});
</script>
</body>
</html>
