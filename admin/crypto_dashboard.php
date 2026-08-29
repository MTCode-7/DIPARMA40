<?php
/**
 * DI PARMA | Admin Crypto Dashboard
 * مراقبة Treasury + Hot Wallet + KYC + Transactions
 */
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/gateways.php';
require_once __DIR__ . '/../includes/crypto_schema.php';
require_once __DIR__ . '/../lib/ColdWalletManager.php';
require_once __DIR__ . '/../lib/RiskEngine.php';

requireAdmin();
dp_create_crypto_tables();
RiskEngine::ensureTables();

$db      = db();
$cwm     = ColdWalletManager::getInstance();
$status  = $cwm->getStatus();

// ── إحصائيات سريعة ──────────────────────────────────────
$totalTxns = $db->query("SELECT COUNT(*) as c FROM dp_transactions")[0]['c'] ?? 0;
$cryptoTxns= $db->query("SELECT COUNT(*) as c FROM dp_blockchain_txns")[0]['c'] ?? 0;
$pendingKyc= $db->query("SELECT COUNT(*) as c FROM dp_kyc_verifications WHERE status='pending'")[0]['c'] ?? 0;
$approvedKyc=$db->query("SELECT COUNT(*) as c FROM dp_kyc_verifications WHERE status='approved'")[0]['c'] ?? 0;
$riskAlerts= $db->query("SELECT COUNT(*) as c FROM dp_risk_logs WHERE decision != 'approve' AND created_at >= DATE_SUB(NOW(),INTERVAL 24 HOUR)"  )[0]['c'] ?? 0;

// ── آخر 10 عمليات Blockchain ────────────────────────────
$recentBc = $db->query(
    "SELECT * FROM dp_blockchain_txns ORDER BY id DESC LIMIT 10"
);

// ── آخر 10 أحداث EventBus ───────────────────────────────
$recentEvents = $db->query(
    "SELECT * FROM dp_event_log ORDER BY id DESC LIMIT 10"
);

// ── KYC pending ──────────────────────────────────────────
$kycPending = $db->query(
    "SELECT k.*, u.username FROM dp_kyc_verifications k
     LEFT JOIN dp_users u ON u.id = k.user_id
     WHERE k.status = 'pending' ORDER BY k.created_at DESC LIMIT 10"
);

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | Crypto Admin</title>
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
.stat-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:16px; margin-bottom:28px; }
.stat-box { background:var(--bg-card); border:1px solid var(--border-gold); border-radius:14px; padding:20px; text-align:center; }
.stat-num  { font-size:2rem; font-weight:800; color:var(--gold); }
.stat-lbl  { font-size:.8rem; color:var(--text-muted); margin-top:4px; }
.section   { background:var(--bg-card); border:1px solid var(--border-gold); border-radius:16px; padding:24px; margin-bottom:24px; }
.section h3{ color:var(--gold); margin:0 0 18px; font-size:1rem; }
table { width:100%; border-collapse:collapse; font-size:.85rem; }
th { color:var(--text-muted); font-weight:600; padding:10px 12px; border-bottom:1px solid var(--border-light); text-align:right; }
td { padding:10px 12px; border-bottom:1px solid rgba(255,255,255,.04); color:var(--text-light); }
.badge { padding:3px 10px; border-radius:20px; font-size:.75rem; font-weight:700; }
.badge-ok       { background:rgba(76,175,80,.15);  color:#4CAF50; }
.badge-warning  { background:rgba(255,152,0,.15);  color:#ff9800; }
.badge-critical { background:rgba(239,83,80,.15);  color:#ef5350; }
.badge-pending  { background:rgba(240,173,78,.15); color:#f0ad4e; }
.badge-approved { background:rgba(76,175,80,.15);  color:#4CAF50; }
.badge-in { background:rgba(26,188,156,.15); color:#1abc9c; }
.badge-out{ background:rgba(255,107,107,.15);color:#ff6b6b; }
.treasury-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:16px; }
.treasury-card { background:rgba(255,255,255,.03); border:1px solid var(--border-light); border-radius:12px; padding:18px; }
.progress-bar  { height:6px; background:rgba(255,255,255,.08); border-radius:99px; overflow:hidden; margin-top:8px; }
.progress-fill { height:100%; border-radius:99px; background:var(--gold-gradient); transition:width .5s; }
.action-btn { padding:7px 16px; border-radius:8px; border:none; cursor:pointer; font-size:.82rem; font-weight:600; }
.btn-approve{ background:rgba(76,175,80,.2);  color:#4CAF50; border:1px solid rgba(76,175,80,.3); }
.btn-reject { background:rgba(239,83,80,.2);  color:#ef5350; border:1px solid rgba(239,83,80,.3); }
.btn-refill { background:rgba(255,215,0,.15); color:var(--gold); border:1px solid var(--border-gold); }
</style>
</head>
<body style="background:var(--bg-dark);color:var(--text-light);font-family:Cairo,sans-serif;padding:24px">

<div style="max-width:1200px;margin:0 auto">

<!-- Header -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:28px;flex-wrap:wrap;gap:12px">
  <div>
    <h1 style="color:var(--gold);margin:0;font-size:1.5rem">
      <i class="fas fa-coins" style="margin-left:10px"></i> Crypto Admin Dashboard
    </h1>
    <p style="color:var(--text-muted);margin:4px 0 0;font-size:.85rem">مراقبة شاملة للأصول الرقمية</p>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap">
    <a href="../dashboard.php" style="padding:8px 18px;border-radius:10px;border:1px solid var(--border-gold);color:var(--text-gold);text-decoration:none;font-size:.85rem">
      <i class="fas fa-chart-pie"></i> لوحة التحكم
    </a>
    <a href="../crypto.php" style="padding:8px 18px;border-radius:10px;background:var(--gold-gradient);color:#000;text-decoration:none;font-size:.85rem;font-weight:700">
      <i class="fas fa-exchange-alt"></i> Crypto Exchange
    </a>
  </div>
</div>

<!-- Stats -->
<div class="stat-grid">
  <div class="stat-box">
    <div class="stat-num"><?= number_format($totalTxns) ?></div>
    <div class="stat-lbl"><i class="fas fa-list"></i> إجمالي المعاملات</div>
  </div>
  <div class="stat-box">
    <div class="stat-num" style="color:#26a17b"><?= number_format($cryptoTxns) ?></div>
    <div class="stat-lbl"><i class="fas fa-cube"></i> معاملات Blockchain</div>
  </div>
  <div class="stat-box">
    <div class="stat-num" style="color:#f0ad4e"><?= number_format($pendingKyc) ?></div>
    <div class="stat-lbl"><i class="fas fa-id-card"></i> KYC معلق</div>
  </div>
  <div class="stat-box">
    <div class="stat-num" style="color:#4CAF50"><?= number_format($approvedKyc) ?></div>
    <div class="stat-lbl"><i class="fas fa-check-circle"></i> KYC مقبول</div>
  </div>
  <div class="stat-box">
    <div class="stat-num" style="color:#ef5350"><?= number_format($riskAlerts) ?></div>
    <div class="stat-lbl"><i class="fas fa-shield-alt"></i> تنبيهات مخاطر (24h)</div>
  </div>
</div>

<!-- Treasury Status -->
<div class="section">
  <h3><i class="fas fa-vault" style="margin-left:8px"></i> حالة Treasury — Hot/Cold Wallets</h3>
  <div class="treasury-grid">
    <?php foreach ($status as $s): ?>
    <?php
      $alertClass = match($s['alert']) {
          'critical' => 'badge-critical',
          'warning'  => 'badge-warning',
          default    => 'badge-ok'
      };
      $hotPct = min($s['hot_pct'], 100);
    ?>
    <div class="treasury-card">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <div>
          <span style="font-size:1.1rem;font-weight:800;color:var(--gold)"><?= $s['coin'] ?></span>
          <span style="color:var(--text-muted);font-size:.8rem;margin-right:8px"><?= $s['network'] ?></span>
        </div>
        <span class="badge <?= $alertClass ?>"><?= match($s['alert']){'critical'=>'حرج','warning'=>'تحذير',default=>'جيد'} ?></span>
      </div>
      <div style="display:flex;justify-content:space-between;margin-bottom:6px">
        <span style="color:var(--text-muted);font-size:.82rem">Hot (متاح)</span>
        <span style="color:#4CAF50;font-weight:700"><?= number_format($s['available'],2) ?></span>
      </div>
      <div style="display:flex;justify-content:space-between;margin-bottom:6px">
        <span style="color:var(--text-muted);font-size:.82rem">Cold (محفوظ)</span>
        <span style="color:#5bc0de;font-weight:700"><?= number_format($s['cold_balance'],2) ?></span>
      </div>
      <div style="display:flex;justify-content:space-between;margin-bottom:8px">
        <span style="color:var(--text-muted);font-size:.82rem">محجوز</span>
        <span style="color:#f0ad4e"><?= number_format($s['reserved'],2) ?></span>
      </div>
      <div class="progress-bar">
        <div class="progress-fill" style="width:<?= $hotPct ?>%"></div>
      </div>
      <div style="display:flex;justify-content:space-between;margin-top:6px">
        <span style="color:var(--text-muted);font-size:.75rem">Hot: <?= $hotPct ?>%</span>
        <span style="color:var(--text-muted);font-size:.75rem">إجمالي: <?= number_format($s['total'],2) ?></span>
      </div>
      <?php if ($s['needs_refill']): ?>
      <button class="action-btn btn-refill" style="width:100%;margin-top:10px"
              onclick="showRefillModal('<?= $s['coin'] ?>','<?= $s['network'] ?>')">
        <i class="fas fa-plus-circle"></i> تعبئة Hot Wallet
      </button>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php if (empty($status)): ?>
    <div style="color:var(--text-muted);font-size:.9rem;padding:16px">
      لا توجد بيانات Treasury بعد — ستظهر بعد أول عملية.
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- KYC Pending -->
<?php if (!empty($kycPending)): ?>
<div class="section">
  <h3><i class="fas fa-id-card" style="margin-left:8px"></i> طلبات KYC المعلقة</h3>
  <table>
    <thead>
      <tr><th>المستخدم</th><th>المستوى</th><th>المزود</th><th>تاريخ الطلب</th><th>إجراء</th></tr>
    </thead>
    <tbody>
      <?php foreach ($kycPending as $k): ?>
      <tr>
        <td><?= htmlspecialchars($k['username'] ?? 'User #' . $k['user_id']) ?></td>
        <td><span class="badge badge-pending">Level <?= $k['level'] ?></span></td>
        <td><?= htmlspecialchars($k['provider']) ?></td>
        <td><?= $k['created_at'] ?></td>
        <td style="display:flex;gap:8px">
          <button class="action-btn btn-approve"
                  onclick="kycAction(<?= $k['user_id'] ?>,'approve',<?= $k['level'] ?>)">
            <i class="fas fa-check"></i> قبول
          </button>
          <button class="action-btn btn-reject"
                  onclick="kycAction(<?= $k['user_id'] ?>,'reject',<?= $k['level'] ?>)">
            <i class="fas fa-times"></i> رفض
          </button>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- آخر معاملات Blockchain -->
<div class="section">
  <h3><i class="fas fa-cube" style="margin-left:8px"></i> آخر معاملات Blockchain</h3>
  <?php if (empty($recentBc)): ?>
  <p style="color:var(--text-muted)">لا توجد معاملات بعد.</p>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table>
    <thead>
      <tr><th>المرجع</th><th>الشبكة</th><th>المبلغ</th><th>الاتجاه</th><th>الحالة</th><th>TX Hash</th><th>التاريخ</th></tr>
    </thead>
    <tbody>
      <?php foreach ($recentBc as $tx): ?>
      <tr>
        <td style="font-family:monospace;font-size:.78rem"><?= htmlspecialchars(substr($tx['reference'] ?? '—', 0, 20)) ?></td>
        <td><span class="badge badge-pending"><?= $tx['network'] ?></span></td>
        <td style="color:var(--gold);font-weight:700"><?= number_format((float)$tx['amount'],4) ?> <?= $tx['coin'] ?></td>
        <td><span class="badge <?= $tx['direction']==='in'?'badge-in':'badge-out' ?>">
          <?= $tx['direction'] === 'in' ? '↓ وارد' : '↑ صادر' ?>
        </span></td>
        <td><span class="badge <?= $tx['status']==='confirmed'?'badge-approved':($tx['status']==='failed'?'badge-critical':'badge-warning') ?>">
          <?= htmlspecialchars($tx['status']) ?>
        </span></td>
        <td style="font-family:monospace;font-size:.75rem">
          <?php if ($tx['tx_hash']): ?>
          <a href="https://tronscan.org/#/transaction/<?= $tx['tx_hash'] ?>" target="_blank"
             style="color:var(--gold)"><?= substr($tx['tx_hash'],0,12) ?>...</a>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td style="font-size:.8rem;color:var(--text-muted)"><?= $tx['created_at'] ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<!-- Event Log -->
<div class="section">
  <h3><i class="fas fa-stream" style="margin-left:8px"></i> آخر أحداث النظام</h3>
  <?php if (empty($recentEvents)): ?>
  <p style="color:var(--text-muted)">لا توجد أحداث بعد.</p>
  <?php else: ?>
  <table>
    <thead><tr><th>الحدث</th><th>المرجع</th><th>المستخدم</th><th>معالج</th><th>التاريخ</th></tr></thead>
    <tbody>
      <?php foreach ($recentEvents as $ev): ?>
      <tr>
        <td><code style="color:var(--gold);font-size:.8rem"><?= htmlspecialchars($ev['event_type']) ?></code></td>
        <td style="font-family:monospace;font-size:.78rem"><?= htmlspecialchars(substr($ev['reference'] ?? '—',0,18)) ?></td>
        <td><?= $ev['user_id'] ? '#' . $ev['user_id'] : '—' ?></td>
        <td><span class="badge <?= $ev['processed'] ? 'badge-approved' : 'badge-pending' ?>">
          <?= $ev['processed'] ? 'نعم' : 'لا' ?>
        </span></td>
        <td style="font-size:.8rem;color:var(--text-muted)"><?= $ev['created_at'] ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

</div><!-- end max-width -->

<!-- Refill Modal -->
<div id="refillModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:9999;align-items:center;justify-content:center">
  <div style="background:var(--bg-card);border:1px solid var(--border-gold);border-radius:20px;padding:32px;width:400px;max-width:90vw">
    <h3 style="color:var(--gold);margin:0 0 20px">تعبئة Hot Wallet</h3>
    <input type="hidden" id="refillCoin"><input type="hidden" id="refillNetwork">
    <div style="margin-bottom:16px">
      <label style="color:var(--text-muted);font-size:.85rem">المبلغ (USDT)</label>
      <input type="number" id="refillAmount" placeholder="0.00" min="1"
             style="width:100%;padding:12px 16px;margin-top:6px;background:rgba(255,255,255,.05);
                    border:1px solid var(--border-gold);border-radius:10px;color:var(--text-light);font-size:1rem">
    </div>
    <div style="margin-bottom:20px">
      <label style="color:var(--text-muted);font-size:.85rem">TX Hash (اختياري)</label>
      <input type="text" id="refillTxHash" placeholder="hash..."
             style="width:100%;padding:10px 16px;margin-top:6px;background:rgba(255,255,255,.05);
                    border:1px solid var(--border-gold);border-radius:10px;color:var(--text-light);font-size:.85rem;font-family:monospace">
    </div>
    <div style="display:flex;gap:12px">
      <button onclick="submitRefill()" style="flex:1;padding:12px;border-radius:10px;background:var(--gold-gradient);color:#000;border:none;font-weight:700;cursor:pointer">
        تأكيد التعبئة
      </button>
      <button onclick="document.getElementById('refillModal').style.display='none'"
              style="flex:1;padding:12px;border-radius:10px;background:rgba(255,255,255,.05);color:var(--text-light);border:1px solid var(--border-light);cursor:pointer">
        إلغاء
      </button>
    </div>
  </div>
</div>

<script>
function showRefillModal(coin, network) {
    document.getElementById('refillCoin').value    = coin;
    document.getElementById('refillNetwork').value = network;
    document.getElementById('refillAmount').value  = '';
    document.getElementById('refillTxHash').value  = '';
    document.getElementById('refillModal').style.display = 'flex';
}

async function submitRefill() {
    const coin    = document.getElementById('refillCoin').value;
    const network = document.getElementById('refillNetwork').value;
    const amount  = parseFloat(document.getElementById('refillAmount').value);
    const txHash  = document.getElementById('refillTxHash').value.trim();

    if (!amount || amount <= 0) { alert('أدخل مبلغاً صحيحاً'); return; }

    const r = await fetch('../api/crypto_admin.php?action=refill', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ coin, network, amount, tx_hash: txHash,
                               direction: 'cold_to_hot',
                               csrf_token: '<?= $csrfToken ?>' })
    });
    const d = await r.json();
    if (d.success) { location.reload(); }
    else           { alert(d.message || 'فشل'); }
}

async function kycAction(userId, action, level) {
    const r = await fetch('../api/crypto_admin.php?action=kyc_' + action, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ user_id: userId, level, csrf_token: '<?= $csrfToken ?>' })
    });
    const d = await r.json();
    if (d.success) location.reload();
    else alert(d.message || 'فشل');
}

// Auto refresh كل 60 ثانية
setTimeout(() => location.reload(), 60000);
</script>
</body>
</html>
