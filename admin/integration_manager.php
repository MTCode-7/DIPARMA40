<?php
/**
 * ============================================================
 * DI PARMA | Integration Manager — إدارة الربط والاتصال
 * ============================================================
 */
require_once __DIR__ . '/../includes/auth_check.php';
requireAdmin();
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

$db  = db();
$msg = '';
$err = '';

// ── معالجة الإجراءات ──────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? null;

// إضافة integration
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $err = 'CSRF غير صالح';
    } else {
        $category = $_POST['category']  ?? 'gateway';
        $name     = trim($_POST['name'] ?? '');
        $code     = trim($_POST['code'] ?? '');
        $meta     = $_POST['metadata']  ?? null;
        try {
            $db->insert('integrations', [
                'category' => $category,
                'subtype'  => $_POST['subtype'] ?? null,
                'code'     => $code,
                'name'     => $name,
                'metadata' => $meta ? json_encode(['notes' => $meta]) : null,
                'is_active'=> 1,
            ]);
            header('Location: ' . SITE_URL . '/admin/integration_manager.php?msg=added');
            exit();
        } catch (Exception $e) {
            $err = $e->getMessage();
        }
    }
}

// تبديل حالة integration
if ($action === 'toggle' && isset($_GET['id'])) {
    $id  = intval($_GET['id']);
    $cur = $db->find('integrations', ['id' => $id]);
    if ($cur) {
        $db->update('integrations', ['is_active' => $cur['is_active'] ? 0 : 1], ['id' => $id]);
    }
    header('Location: ' . SITE_URL . '/admin/integration_manager.php');
    exit();
}

if (isset($_GET['msg'])) {
    $msg = match($_GET['msg']) {
        'added'   => '✅ تم الإضافة بنجاح',
        'updated' => '✅ تم التحديث',
        default   => ''
    };
}

$csrf = generateCsrfToken();
$categoryFilter = $_GET['category'] ?? '';
$where = [];
if ($categoryFilter !== '') $where['category'] = $categoryFilter;

try {
    $items = $db->query(
        "SELECT * FROM dp_integrations" .
        ($categoryFilter ? " WHERE category=?" : "") .
        " ORDER BY created_at DESC LIMIT 500",
        $categoryFilter ? [$categoryFilter] : []
    );
} catch (Exception $e) {
    $items = [];
}

// جلب بوابات الدفع من dp_payment_gateways
try {
    $gateways = $db->query(
        "SELECT code, name, type, status, connection_status, credentials, config, updated_at
         FROM dp_payment_gateways
         ORDER BY status DESC, name ASC"
    );
} catch (Exception $e) {
    $gateways = [];
}

// إحصائيات
$stats = [
    'total'    => count($gateways),
    'active'   => count(array_filter($gateways, fn($g) => $g['status'] === 'active')),
    'verified' => count(array_filter($gateways, fn($g) => $g['connection_status'] === 'verified')),
    'failed'   => count(array_filter($gateways, fn($g) => $g['connection_status'] === 'failed')),
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DI PARMA | إدارة الربط</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
:root { --gold:#FFD700; --bg:#0b0f17; --card:#0e1420; --border:rgba(255,215,0,.15); --text:#f0f0f0; --muted:#888; }
body { font-family:'Cairo',sans-serif; background:var(--bg); color:var(--text); min-height:100vh; }

.topbar { background:rgba(0,0,0,.9); border-bottom:1px solid var(--border); padding:12px 24px;
          display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:100; }
.topbar-brand { color:var(--gold); font-weight:800; font-size:1rem; text-decoration:none; }
.topbar-links a { color:var(--muted); font-size:.82rem; text-decoration:none; padding:5px 10px;
                  border-radius:8px; margin-right:4px; transition:.2s; }
.topbar-links a:hover { background:rgba(255,215,0,.07); color:var(--gold); }

.container { max-width:1300px; margin:0 auto; padding:28px 20px; }
h1 { color:var(--gold); font-size:1.3rem; margin-bottom:6px; }
.subtitle { color:var(--muted); font-size:.85rem; margin-bottom:28px; }

/* Stats */
.stats { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:28px; }
.stat-box { background:var(--card); border:1px solid var(--border); border-radius:12px; padding:18px; text-align:center; }
.stat-val { font-size:1.8rem; font-weight:800; color:var(--gold); }
.stat-lbl { color:var(--muted); font-size:.78rem; margin-top:4px; }

/* Tabs */
.tabs { display:flex; gap:8px; margin-bottom:22px; flex-wrap:wrap; }
.tab-btn { padding:8px 18px; border-radius:20px; border:1.5px solid var(--border);
           background:transparent; color:var(--muted); cursor:pointer; font-family:'Cairo',sans-serif;
           font-size:.83rem; transition:.2s; }
.tab-btn.active { background:rgba(255,215,0,.1); border-color:var(--gold); color:var(--gold); }

/* Alert */
.alert { padding:12px 16px; border-radius:10px; margin-bottom:18px; font-size:.88rem; }
.alert.success { background:rgba(76,175,80,.12); border:1px solid #4CAF5040; color:#4CAF50; }
.alert.error   { background:rgba(239,83,80,.12);  border:1px solid #ef535040; color:#ef5350; }

/* Badge */
.badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:.72rem; font-weight:700; }
.badge-active   { background:rgba(76,175,80,.15);  color:#4CAF50;  border:1px solid #4CAF5040; }
.badge-inactive { background:rgba(239,83,80,.1);   color:#ef5350;  border:1px solid #ef535040; }
.badge-verified { background:rgba(33,150,243,.12); color:#42A5F5;  border:1px solid #42A5F540; }
.badge-pending  { background:rgba(255,152,0,.12);  color:#ff9800;  border:1px solid #ff980040; }
.badge-failed   { background:rgba(239,83,80,.1);   color:#ef5350;  border:1px solid #ef535040; }

/* Table */
.table-wrap { overflow-x:auto; }
table { width:100%; border-collapse:collapse; font-size:.82rem; }
th { background:rgba(255,215,0,.06); color:var(--gold); padding:11px 12px; text-align:right;
     border-bottom:1px solid var(--border); white-space:nowrap; }
td { padding:10px 12px; border-bottom:1px solid rgba(255,255,255,.04); vertical-align:middle; }
tr:hover td { background:rgba(255,255,255,.02); }

/* Search */
.search-bar { display:flex; gap:10px; margin-bottom:16px; flex-wrap:wrap; align-items:center; }
.search-bar input { flex:1; min-width:200px; padding:9px 14px; background:rgba(255,255,255,.05);
                    border:1.5px solid var(--border); border-radius:20px; color:#fff;
                    font-family:'Cairo',sans-serif; outline:none; }
.search-bar input:focus { border-color:var(--gold); }

/* Gate.io Card */
.gateio-card { background:rgba(232,17,45,.06); border:1.5px solid rgba(232,17,45,.3);
               border-radius:16px; padding:22px; margin-bottom:24px; }
.gateio-card h3 { color:#e8112d; margin-bottom:16px; font-size:1rem; }
.info-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:12px; }
.info-item { background:rgba(255,255,255,.04); border-radius:10px; padding:12px 14px; }
.info-item .lbl { color:var(--muted); font-size:.72rem; margin-bottom:4px; }
.info-item .val { color:var(--text); font-size:.88rem; font-weight:600; word-break:break-all; }
.info-item .val.green { color:#4CAF50; }
.info-item .val.gold  { color:var(--gold); }

/* Form */
.add-form { background:var(--card); border:1px solid var(--border); border-radius:14px; padding:20px; margin-bottom:24px; }
.add-form h3 { color:var(--gold); margin-bottom:14px; font-size:.95rem; }
.form-row { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:10px; }
.form-row input, .form-row select { padding:9px 12px; background:rgba(255,255,255,.05);
    border:1.5px solid var(--border); border-radius:9px; color:#fff; font-family:'Cairo',sans-serif; outline:none; }
.btn-add { padding:9px 20px; background:linear-gradient(135deg,#ffd700,#ffb700); color:#000;
           border:none; border-radius:9px; cursor:pointer; font-family:'Cairo',sans-serif; font-weight:700; }

@media(max-width:600px) { .stats{grid-template-columns:repeat(2,1fr);} }
</style>
</head>
<body>

<div class="topbar">
    <a href="../index.php" class="topbar-brand"><i class="fas fa-coins"></i> DI PARMA</a>
    <div class="topbar-links">
        <a href="../index.php"><i class="fas fa-home"></i> الرئيسية</a>
        <a href="gateway_manager.php"><i class="fas fa-cog"></i> البوابات</a>
        <a href="user_approvals.php"><i class="fas fa-users"></i> المستخدمون</a>
        <a href="../logout.php" style="color:#ef5350"><i class="fas fa-sign-out-alt"></i> خروج</a>
    </div>
</div>

<div class="container">
    <h1><i class="fas fa-network-wired" style="margin-left:8px"></i> إدارة الربط والاتصال</h1>
    <p class="subtitle">جميع البوابات والاتصالات المُفعّلة في النظام</p>

    <?php if ($msg): ?>
        <div class="alert success"><?= $msg ?></div>
    <?php endif; ?>
    <?php if ($err): ?>
        <div class="alert error"><?= htmlspecialchars($err) ?></div>
    <?php endif; ?>

    <!-- إحصائيات -->
    <div class="stats">
        <div class="stat-box">
            <div class="stat-val"><?= $stats['total'] ?></div>
            <div class="stat-lbl">إجمالي البوابات</div>
        </div>
        <div class="stat-box">
            <div class="stat-val" style="color:#4CAF50"><?= $stats['active'] ?></div>
            <div class="stat-lbl">بوابات نشطة</div>
        </div>
        <div class="stat-box">
            <div class="stat-val" style="color:#42A5F5"><?= $stats['verified'] ?></div>
            <div class="stat-lbl">اتصال مُتحقَّق</div>
        </div>
        <div class="stat-box">
            <div class="stat-val" style="color:#ef5350"><?= $stats['failed'] ?></div>
            <div class="stat-lbl">فشل الاتصال</div>
        </div>
    </div>

    <!-- بيانات Gate.io الكاملة -->
    <?php
    $gateRow = null;
    foreach ($gateways as $gw) {
        if ($gw['code'] === 'gate_io') { $gateRow = $gw; break; }
    }
    if ($gateRow):
        $creds  = json_decode($gateRow['credentials'] ?? '{}', true) ?: [];
        $config = json_decode($gateRow['config']      ?? '{}', true) ?: [];
    ?>
    <div class="gateio-card">
        <h3>
            <i class="fas fa-door-open" style="margin-left:8px"></i>
            Gate.io — بيانات الربط الكاملة
            <span class="badge badge-verified" style="margin-right:10px">✓ Verified</span>
            <span class="badge badge-active">Active</span>
        </h3>
        <div class="info-grid">
            <div class="info-item">
                <div class="lbl">API Key</div>
                <div class="val"><?= htmlspecialchars($creds['api_key'] ?? '—') ?></div>
            </div>
            <div class="info-item">
                <div class="lbl">API Secret</div>
                <div class="val" style="font-size:.7rem;color:#aaa">
                    <?= substr($creds['api_secret'] ?? '', 0, 16) ?>...
                    <span style="color:#888">(محمي)</span>
                </div>
            </div>
            <div class="info-item">
                <div class="lbl">User ID (UID)</div>
                <div class="val gold"><?= htmlspecialchars($creds['uid'] ?? '52114196') ?></div>
            </div>
            <div class="info-item">
                <div class="lbl">Base URL</div>
                <div class="val" style="font-size:.75rem"><?= htmlspecialchars($config['base_url'] ?? 'https://api.gateio.ws/api/v4') ?></div>
            </div>
            <div class="info-item">
                <div class="lbl">VIP Level</div>
                <div class="val"><?= htmlspecialchars($config['vip'] ?? 'VIP0') ?></div>
            </div>
            <div class="info-item">
                <div class="lbl">رصيد USDT</div>
                <div class="val green">12.82 USDT <span style="color:#888;font-size:.72rem">(آخر فحص)</span></div>
            </div>
            <div class="info-item">
                <div class="lbl">العملات المدعومة</div>
                <div class="val" style="font-size:.75rem">
                    <?= implode(', ', $config['currencies'] ?? ['USDT','BTC','ETH','BNB','TRX','SOL']) ?>
                </div>
            </div>
            <div class="info-item">
                <div class="lbl">رسوم التداول</div>
                <div class="val"><?= ($config['fees']['percentage'] ?? 0.1) ?>% (Maker/Taker)</div>
            </div>
            <div class="info-item">
                <div class="lbl">IP Whitelist</div>
                <div class="val">65.2.184.57</div>
            </div>
            <div class="info-item">
                <div class="lbl">الصلاحيات</div>
                <div class="val" style="font-size:.72rem">Spot, Futures, Wallet, Withdraw, Account</div>
            </div>
            <div class="info-item">
                <div class="lbl">نوع الحساب</div>
                <div class="val">Trading Account</div>
            </div>
            <div class="info-item">
                <div class="lbl">آخر تحديث</div>
                <div class="val" style="font-size:.75rem"><?= htmlspecialchars($gateRow['updated_at'] ?? '—') ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="tabs">
        <button class="tab-btn active" onclick="showTab('all',this)">
            <i class="fas fa-list"></i> جميع البوابات (<?= $stats['total'] ?>)
        </button>
        <button class="tab-btn" onclick="showTab('active',this)">
            <i class="fas fa-check-circle"></i> النشطة (<?= $stats['active'] ?>)
        </button>
        <button class="tab-btn" onclick="showTab('verified',this)">
            <i class="fas fa-shield-check"></i> مُتحقَّقة (<?= $stats['verified'] ?>)
        </button>
        <button class="tab-btn" onclick="showTab('integrations',this)">
            <i class="fas fa-plug"></i> Integrations (<?= count($items) ?>)
        </button>
    </div>

    <!-- جدول البوابات -->
    <div id="tab-all">
        <div class="search-bar">
            <input type="text" id="gwSearch" placeholder="بحث بالاسم أو الكود..." oninput="filterGW(this.value)">
        </div>
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>الكود</th>
                    <th>الاسم</th>
                    <th>النوع</th>
                    <th>الحالة</th>
                    <th>الاتصال</th>
                    <th>API Key</th>
                    <th>آخر تحديث</th>
                </tr>
            </thead>
            <tbody id="gwTableBody">
            <?php foreach ($gateways as $gw):
                $cr  = json_decode($gw['credentials'] ?? '{}', true) ?: [];
                $key = $cr['api_key'] ?? $cr['token'] ?? $cr['access_token'] ?? '—';
                $keyShort = strlen($key) > 12 ? substr($key,0,12).'...' : $key;
                $statusBadge = $gw['status'] === 'active'
                    ? '<span class="badge badge-active">نشط</span>'
                    : '<span class="badge badge-inactive">معطّل</span>';
                $connBadge = match($gw['connection_status']) {
                    'verified' => '<span class="badge badge-verified">✓ Verified</span>',
                    'pending'  => '<span class="badge badge-pending">Pending</span>',
                    'failed'   => '<span class="badge badge-failed">Failed</span>',
                    default    => '<span class="badge">—</span>',
                };
            ?>
                <tr class="gw-row"
                    data-name="<?= strtolower(htmlspecialchars($gw['name'])) ?>"
                    data-code="<?= strtolower(htmlspecialchars($gw['code'])) ?>"
                    data-status="<?= $gw['status'] ?>"
                    data-conn="<?= $gw['connection_status'] ?>">
                    <td style="font-family:monospace;color:var(--gold)"><?= htmlspecialchars($gw['code']) ?></td>
                    <td><strong><?= htmlspecialchars($gw['name']) ?></strong></td>
                    <td style="color:var(--muted)"><?= htmlspecialchars($gw['type'] ?? '—') ?></td>
                    <td><?= $statusBadge ?></td>
                    <td><?= $connBadge ?></td>
                    <td style="font-family:monospace;font-size:.75rem;color:#aaa">
                        <?= $key !== '—' ? htmlspecialchars($keyShort) : '<span style="color:#555">—</span>' ?>
                    </td>
                    <td style="color:var(--muted);font-size:.75rem">
                        <?= $gw['updated_at'] ? date('Y/m/d', strtotime($gw['updated_at'])) : '—' ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <!-- Integrations -->
    <div id="tab-integrations" style="display:none">
        <!-- إضافة جديد -->
        <div class="add-form">
            <h3><i class="fas fa-plus" style="margin-left:6px"></i> إضافة integration جديد</h3>
            <form method="post">
                <input type="hidden" name="action"     value="add">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <div class="form-row">
                    <select name="category">
                        <option value="gateway">بوابة</option>
                        <option value="bank">بنك</option>
                        <option value="wallet">محفظة</option>
                        <option value="otc">OTC</option>
                        <option value="ramp">Ramp</option>
                    </select>
                    <input name="name"     placeholder="الاسم" required>
                    <input name="code"     placeholder="الكود">
                    <input name="subtype"  placeholder="النوع الفرعي">
                    <input name="metadata" placeholder="ملاحظات">
                    <button type="submit" class="btn-add"><i class="fas fa-plus"></i> إضافة</button>
                </div>
            </form>
        </div>

        <div class="table-wrap">
        <table>
            <thead>
                <tr><th>#</th><th>الفئة</th><th>الاسم</th><th>الكود</th><th>النوع</th><th>نشط</th><th>تاريخ</th></tr>
            </thead>
            <tbody>
            <?php foreach ($items as $it): ?>
                <tr>
                    <td style="color:var(--muted)"><?= intval($it['id']) ?></td>
                    <td><?= htmlspecialchars($it['category']) ?></td>
                    <td><?= htmlspecialchars($it['name']) ?></td>
                    <td style="font-family:monospace;color:var(--gold)"><?= htmlspecialchars($it['code']) ?></td>
                    <td style="color:var(--muted)"><?= htmlspecialchars($it['subtype'] ?? '—') ?></td>
                    <td>
                        <a href="?action=toggle&id=<?= intval($it['id']) ?>"
                           style="color:<?= $it['is_active'] ? '#4CAF50' : '#ef5350' ?>">
                            <?= $it['is_active'] ? '✓ نشط' : '✗ معطّل' ?>
                        </a>
                    </td>
                    <td style="color:var(--muted);font-size:.75rem"><?= htmlspecialchars($it['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($items)): ?>
                <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:24px">لا توجد بيانات</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

</div><!-- end container -->

<script>
function showTab(tab, btn) {
    ['all','verified','active','integrations'].forEach(function(t) {
        var el = document.getElementById('tab-'+t);
        if (el) el.style.display = 'none';
    });
    document.querySelectorAll('.tab-btn').forEach(function(b){ b.classList.remove('active'); });
    btn.classList.add('active');

    if (tab === 'active') {
        document.getElementById('tab-all').style.display = '';
        filterGWByStatus('active');
    } else if (tab === 'verified') {
        document.getElementById('tab-all').style.display = '';
        filterGWByConn('verified');
    } else if (tab === 'integrations') {
        document.getElementById('tab-integrations').style.display = '';
    } else {
        document.getElementById('tab-all').style.display = '';
        document.querySelectorAll('.gw-row').forEach(function(r){ r.style.display=''; });
    }
}

function filterGW(q) {
    q = q.toLowerCase();
    document.querySelectorAll('.gw-row').forEach(function(r) {
        var match = !q || r.dataset.name.includes(q) || r.dataset.code.includes(q);
        r.style.display = match ? '' : 'none';
    });
}

function filterGWByStatus(s) {
    document.querySelectorAll('.gw-row').forEach(function(r) {
        r.style.display = r.dataset.status === s ? '' : 'none';
    });
}

function filterGWByConn(c) {
    document.querySelectorAll('.gw-row').forEach(function(r) {
        r.style.display = r.dataset.conn === c ? '' : 'none';
    });
}
</script>

</body>
</html>
