<?php
/**
 * DI PARMA | إدارة الاتصال
 * 6 أقسام: Banks | Payment Gateways | Crypto | Wallets | Social | Games
 */
if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/includes/config.php';
require_once ROOT_PATH . '/includes/database.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/gateways.php';
require_once ROOT_PATH . '/includes/auth_check.php';
require_once ROOT_PATH . '/lib/GatewayConnectionTester.php';
requireAdmin();

$db       = db();
$csrfToken = generateCsrfToken();
$msg = ''; $msgType = '';

// ── معالجة AJAX: اختبار الاتصال ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_connection') {
    header('Content-Type: application/json; charset=utf-8');
    $id = intval($_POST['gateway_id'] ?? 0);
    if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'ID مطلوب']); exit(); }
    $tester = new GatewayConnectionTester();
    echo json_encode($tester->test($id), JSON_UNESCAPED_UNICODE);
    exit();
}

// ── معالجة AJAX: تفعيل/تعطيل ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_status') {
    header('Content-Type: application/json; charset=utf-8');
    $id  = intval($_POST['gateway_id'] ?? 0);
    $gw  = $db->find('payment_gateways', ['id' => $id]);
    if (!$gw) { echo json_encode(['success'=>false,'message'=>'غير موجود']); exit(); }
    $new = $gw['status'] === 'active' ? 'inactive' : 'active';
    $db->execute("UPDATE dp_payment_gateways SET status=?, updated_at=NOW() WHERE id=?", [$new, $id]);
    echo json_encode(['success'=>true,'new_status'=>$new]);
    exit();
}

// ── معالجة AJAX: اختبار الكل ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_all') {
    header('Content-Type: application/json; charset=utf-8');
    $section = $_POST['section'] ?? '';
    $where   = $section ? "WHERE section='$section' AND status!='deleted'" : "WHERE status!='deleted'";
    $gws     = $db->query("SELECT id FROM dp_payment_gateways $where LIMIT 100");
    $tester  = new GatewayConnectionTester();
    $results = []; $ok = 0; $fail = 0;
    foreach ($gws as $g) {
        $r = $tester->test((int)$g['id']);
        $results[] = ['id' => $g['id'], 'success' => $r['success'], 'message' => $r['message'], 'ms' => $r['response_ms'] ?? 0];
        $r['success'] ? $ok++ : $fail++;
    }
    echo json_encode(['success'=>true,'verified'=>$ok,'failed'=>$fail,'total'=>count($results),'results'=>$results], JSON_UNESCAPED_UNICODE);
    exit();
}

// ── معالجة: حفظ بوابة ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['add_gateway','edit_gateway'])) {
    $isEdit = ($_POST['action'] === 'edit_gateway');
    $id     = intval($_POST['id'] ?? 0);
    $data   = [
        'name'       => trim($_POST['name']        ?? ''),
        'code'       => strtolower(trim($_POST['code'] ?? '')),
        'section'    => trim($_POST['section']     ?? 'payment_gateway'),
        'type'       => trim($_POST['type']        ?? 'electronic'),
        'status'     => trim($_POST['status']      ?? 'inactive'),
        'api_endpoint' => trim($_POST['api_endpoint'] ?? ''),
        'api_version'  => trim($_POST['api_version']  ?? ''),
        'connection_type' => trim($_POST['connection_type'] ?? 'rest'),
        'gateway_type'    => trim($_POST['gateway_type']    ?? 'card'),
        'supports_2d'     => isset($_POST['supports_2d'])     ? 1 : 0,
        'supports_3d'     => isset($_POST['supports_3d'])     ? 1 : 0,
        'supports_hold'   => isset($_POST['supports_hold'])   ? 1 : 0,
        'supports_capture'=> isset($_POST['supports_capture'])? 1 : 0,
        'sort_order'   => intval($_POST['sort_order']  ?? 0),
        'country'      => trim($_POST['country']       ?? ''),
        'swift_code'   => trim($_POST['swift_code']    ?? ''),
        'description'  => trim($_POST['description']   ?? ''),
        'connection_status' => 'untested',
        'updated_at'   => date('Y-m-d H:i:s'),
    ];

    // Credentials
    $creds = [];
    foreach (['api_key','secret_key','client_id','merchant_id','access_token',
              'public_key','profile_id','server_key','api_login_id','transaction_key',
              'webhook_secret','private_key'] as $f) {
        if (!empty($_POST[$f])) $creds[$f] = trim($_POST[$f]);
    }
    $settings = [];
    foreach (['webhook_url','callback_url','success_url','cancel_url','environment'] as $f) {
        if (!empty($_POST[$f])) $settings[$f] = trim($_POST[$f]);
    }
    $data['credentials'] = json_encode($creds);
    $data['settings']    = json_encode($settings);

    try {
        if ($isEdit && $id > 0) {
            $db->update('payment_gateways', $data, ['id' => $id]);
            $msg = '✅ تم حفظ التغييرات'; $msgType = 'success';
        } else {
            if ($db->find('payment_gateways', ['code' => $data['code']])) {
                $msg = '❌ الكود موجود مسبقاً'; $msgType = 'error';
            } else {
                $data['created_at'] = date('Y-m-d H:i:s');
                $db->insert('payment_gateways', $data);
                $msg = '✅ تم إضافة البوابة'; $msgType = 'success';
            }
        }
    } catch (Exception $e) {
        $msg = '❌ ' . $e->getMessage(); $msgType = 'error';
    }
}

// ── حذف ──────────────────────────────────────────────────
if (isset($_GET['delete'], $_GET['token']) && hash_equals($_SESSION['csrf_token'] ?? '', $_GET['token'])) {
    $db->delete('payment_gateways', ['id' => intval($_GET['delete'])]);
    $msg = '✅ تم الحذف'; $msgType = 'success';
}

// ── جلب القسم النشط ──────────────────────────────────────
$activeSection = $_GET['section'] ?? 'bank';
$editId        = intval($_GET['edit'] ?? 0);
$editGw        = $editId ? $db->find('payment_gateways', ['id' => $editId]) : null;
$showAdd       = isset($_GET['add']) || $editGw;

// ── الأقسام ───────────────────────────────────────────────
$sections = [
    'bank'            => ['label'=>'BANK LIST',         'icon'=>'fas fa-university',   'color'=>'#5bc0de', 'limit'=>100],
    'payment_gateway' => ['label'=>'PAYMENT GATEWAY',   'icon'=>'fas fa-credit-card',  'color'=>'var(--gold)', 'limit'=>100],
    'crypto'          => ['label'=>'CRYPTO GATE',        'icon'=>'fab fa-bitcoin',      'color'=>'#f3ba2f', 'limit'=>100],
    'wallet'          => ['label'=>'WALLET',             'icon'=>'fas fa-wallet',       'color'=>'#9fe870', 'limit'=>100],
    'social'          => ['label'=>'SOCIAL PAYMENT',     'icon'=>'fas fa-share-nodes',  'color'=>'#ab9ff2', 'limit'=>100],
    'games'           => ['label'=>'GAMES PAYMENT',      'icon'=>'fas fa-gamepad',      'color'=>'#f0ad4e', 'limit'=>100],
];

// ── جلب البوابات للقسم النشط ─────────────────────────────
$gateways = $db->query(
    "SELECT * FROM dp_payment_gateways WHERE section=? ORDER BY status DESC, sort_order, name LIMIT 200",
    [$activeSection]
);

// إحصاء كل قسم
$counts = [];
foreach ($sections as $sec => $info) {
    $row = $db->query("SELECT COUNT(*) as c, SUM(status='active') as a, SUM(connection_status='verified') as v FROM dp_payment_gateways WHERE section=?", [$sec]);
    $counts[$sec] = $row[0] ?? ['c'=>0,'a'=>0,'v'=>0];
}
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | إدارة الاتصال</title>
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{--gold:#ffd700;--bg:#0a0a0a;--card:rgba(255,255,255,.04);--border:rgba(255,215,0,.2);}
*{box-sizing:border-box;}
body{background:var(--bg);color:#e0e0e0;font-family:Cairo,sans-serif;margin:0;min-height:100vh;}
.header{background:rgba(0,0,0,.9);border-bottom:1px solid var(--border);padding:16px 28px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.header h1{color:var(--gold);font-size:1.3rem;margin:0;display:flex;align-items:center;gap:10px;}
.sections-nav{display:flex;gap:8px;overflow-x:auto;padding:18px 28px;border-bottom:1px solid rgba(255,255,255,.06);}
.sec-btn{display:flex;align-items:center;gap:8px;padding:10px 18px;border-radius:12px;border:1.5px solid rgba(255,255,255,.1);background:transparent;color:#aaa;cursor:pointer;font-family:Cairo,sans-serif;font-size:.85rem;font-weight:600;white-space:nowrap;transition:all .2s;}
.sec-btn:hover{border-color:var(--gold);color:var(--gold);}
.sec-btn.active{background:rgba(255,215,0,.1);border-color:var(--gold);color:var(--gold);}
.sec-btn .badge{background:rgba(255,255,255,.1);padding:2px 8px;border-radius:10px;font-size:.72rem;}
.sec-btn.active .badge{background:rgba(255,215,0,.2);color:var(--gold);}
.container{max-width:1500px;margin:0 auto;padding:24px 28px;}
.toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:20px;flex-wrap:wrap;}
.toolbar-left{display:flex;gap:10px;align-items:center;}
.search-box{padding:10px 16px;background:rgba(255,255,255,.05);border:1.5px solid var(--border);border-radius:10px;color:#fff;font-family:Cairo,sans-serif;font-size:.88rem;outline:none;width:240px;}
.search-box:focus{border-color:var(--gold);}
.btn{padding:10px 20px;border-radius:10px;border:none;cursor:pointer;font-family:Cairo,sans-serif;font-weight:600;font-size:.88rem;display:inline-flex;align-items:center;gap:8px;text-decoration:none;transition:all .2s;}
.btn-gold{background:linear-gradient(135deg,#ffd700,#ffb700);color:#000;}
.btn-gold:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(255,215,0,.3);}
.btn-blue{background:rgba(91,192,222,.15);border:1px solid #5bc0de;color:#5bc0de;}
.btn-red{background:rgba(239,83,80,.15);border:1px solid #ef5350;color:#ef5350;}
.btn-sm{padding:6px 14px;font-size:.78rem;}
.btn-outline{background:transparent;border:1.5px solid var(--border);color:#aaa;}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;}
.gw-card{background:var(--card);border:1.5px solid var(--border);border-radius:16px;padding:18px;transition:border-color .2s;}
.gw-card:hover{border-color:rgba(255,215,0,.4);}
.gw-card.active-gw{border-color:rgba(76,175,80,.4);}
.gw-head{display:flex;align-items:center;gap:12px;margin-bottom:12px;}
.gw-icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;}
.gw-name{font-weight:700;font-size:.95rem;color:#fff;}
.gw-code{font-size:.72rem;color:#666;font-family:monospace;}
.badges{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;}
.badge-pill{padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:600;}
.st-active{background:rgba(76,175,80,.15);color:#4CAF50;border:1px solid #4CAF5040;}
.st-inactive{background:rgba(239,83,80,.12);color:#ef5350;border:1px solid #ef535040;}
.conn-verified{background:rgba(76,175,80,.15);color:#4CAF50;border:1px solid #4CAF5040;}
.conn-failed{background:rgba(239,83,80,.12);color:#ef5350;border:1px solid #ef535040;}
.conn-untested{background:rgba(240,173,78,.12);color:#f0ad4e;border:1px solid #f0ad4e40;}
.conn-disabled{background:rgba(136,136,136,.12);color:#888;}
.gw-details{font-size:.78rem;color:#777;margin-bottom:14px;line-height:1.7;}
.gw-details span{color:#aaa;}
.gw-actions{display:flex;gap:8px;flex-wrap:wrap;}
.stats-bar{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:22px;}
.stat-box{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:14px 18px;text-align:center;}
.stat-num{font-size:1.6rem;font-weight:800;color:var(--gold);}
.stat-lbl{font-size:.75rem;color:#666;margin-top:2px;}
.empty-state{text-align:center;padding:60px 20px;color:#444;}
.empty-state i{font-size:3rem;margin-bottom:14px;display:block;}
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.8);z-index:1000;overflow-y:auto;}
.modal.show{display:flex;align-items:flex-start;justify-content:center;padding:40px 20px;}
.modal-box{background:#0e0e0e;border:1.5px solid var(--border);border-radius:20px;padding:28px;width:100%;max-width:680px;}
.modal-box h3{color:var(--gold);margin:0 0 20px;font-size:1.1rem;}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.form-group{display:flex;flex-direction:column;gap:6px;}
.form-group label{font-size:.8rem;color:#888;}
.form-group input,.form-group select,.form-group textarea{padding:11px 14px;background:rgba(255,255,255,.05);border:1.5px solid var(--border);border-radius:10px;color:#fff;font-family:Cairo,sans-serif;font-size:.88rem;outline:none;}
.form-group input:focus,.form-group select:focus{border-color:var(--gold);}
.form-group.full{grid-column:1/-1;}
.sep{border:none;border-top:1px solid rgba(255,255,255,.06);margin:16px 0;}
.toast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%) translateY(80px);background:rgba(10,14,30,.97);border:1.5px solid var(--gold);border-radius:12px;padding:12px 24px;color:#fff;z-index:9999;transition:transform .3s;font-size:.92rem;}
.test-spinner{animation:spin 1s linear infinite;}
@keyframes spin{to{transform:rotate(360deg);}}
@media(max-width:768px){.form-grid{grid-template-columns:1fr;}.stats-bar{grid-template-columns:1fr 1fr;}.grid{grid-template-columns:1fr;}.search-box{width:180px;}}
</style>
</head>
<body>

<div id="toast" class="toast"></div>

<!-- Header -->
<div class="header">
    <h1><i class="fas fa-network-wired"></i> إدارة الاتصال</h1>
    <div style="display:flex;gap:10px;align-items:center">
        <a href="gateway_manager.php" class="btn btn-outline btn-sm">
            <i class="fas fa-cog"></i> الإعدادات المتقدمة
        </a>
        <a href="../dashboard.php" class="btn btn-outline btn-sm">
            <i class="fas fa-home"></i> الرئيسية
        </a>
    </div>
</div>

<!-- رسائل النظام -->
<?php if ($msg): ?>
<div style="background:<?= $msgType==='success'?'rgba(76,175,80,.1)':'rgba(239,83,80,.1)' ?>;border:1px solid <?= $msgType==='success'?'#4CAF50':'#ef5350' ?>;padding:12px 24px;text-align:center;color:<?= $msgType==='success'?'#4CAF50':'#ef5350' ?>;">
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<!-- Sections Nav -->
<div class="sections-nav">
<?php foreach ($sections as $sec => $info): ?>
    <?php $cnt = $counts[$sec]; ?>
    <a href="?section=<?= $sec ?>" class="sec-btn <?= $activeSection===$sec?'active':'' ?>"
       style="<?= $activeSection===$sec?'--c:'.$info['color'].';border-color:'.$info['color'].';color:'.$info['color'] :'' ?>">
        <i class="<?= $info['icon'] ?>" style="color:<?= $info['color'] ?>"></i>
        <?= $info['label'] ?>
        <span class="badge"><?= intval($cnt['c'] ?? 0) ?>/100</span>
        <?php if (($cnt['v'] ?? 0) > 0): ?>
        <span class="badge" style="background:rgba(76,175,80,.2);color:#4CAF50">✓<?= intval($cnt['v']) ?></span>
        <?php endif; ?>
    </a>
<?php endforeach; ?>
</div>

<div class="container">
<!-- Stats Bar -->
<?php $cnt = $counts[$activeSection]; $info = $sections[$activeSection]; ?>
<div class="stats-bar">
    <div class="stat-box">
        <div class="stat-num"><?= intval($cnt['c'] ?? 0) ?></div>
        <div class="stat-lbl">إجمالي <?= $info['label'] ?></div>
    </div>
    <div class="stat-box">
        <div class="stat-num" style="color:#4CAF50"><?= intval($cnt['a'] ?? 0) ?></div>
        <div class="stat-lbl">مفعّل (Active)</div>
    </div>
    <div class="stat-box">
        <div class="stat-num" style="color:#5bc0de"><?= intval($cnt['v'] ?? 0) ?></div>
        <div class="stat-lbl">متصل (Verified)</div>
    </div>
</div>

<!-- Toolbar -->
<div class="toolbar">
    <div class="toolbar-left">
        <input type="text" class="search-box" id="searchBox"
               placeholder="🔍 بحث باسم أو كود..."
               onkeyup="filterCards(this.value)">
        <button class="btn btn-blue btn-sm" onclick="testAllInSection()">
            <i class="fas fa-plug"></i> اختبار الكل
        </button>
    </div>
    <div style="display:flex;gap:8px">
        <a href="?section=<?= $activeSection ?>&add=1" class="btn btn-gold btn-sm">
            <i class="fas fa-plus"></i> إضافة <?= $info['label'] ?>
        </a>
    </div>
</div>

<!-- Cards Grid -->
<div class="grid" id="gwGrid">
<?php if (empty($gateways)): ?>
<div class="empty-state" style="grid-column:1/-1">
    <i class="<?= $info['icon'] ?>" style="color:<?= $info['color'] ?>"></i>
    <p>لا توجد بيانات في هذا القسم بعد.</p>
    <a href="?section=<?= $activeSection ?>&add=1" class="btn btn-gold">
        <i class="fas fa-plus"></i> إضافة أول <?= $info['label'] ?>
    </a>
</div>
<?php else: ?>
<?php foreach ($gateways as $gw):
    $creds  = json_decode($gw['credentials'] ?? '{}', true) ?: [];
    $connSt = $gw['connection_status'] ?? 'untested';
    $connBadge = match($connSt) {
        'verified'  => ['cl'=>'conn-verified', 'icon'=>'check-circle',    'lbl'=>'متصل ✅'],
        'failed'    => ['cl'=>'conn-failed',   'icon'=>'times-circle',    'lbl'=>'فشل ❌'],
        'disabled'  => ['cl'=>'conn-disabled', 'icon'=>'ban',             'lbl'=>'معطل'],
        default     => ['cl'=>'conn-untested', 'icon'=>'question-circle', 'lbl'=>'لم يُختبر'],
    };
    $hasKey = !empty($creds['api_key']) || !empty($creds['secret_key']) || !empty($creds['client_id'])
           || !empty($creds['server_key']) || !empty($creds['api_login_id']) || !empty($creds['access_token']);
    $ms = $gw['test_response_ms'] ?? null;
    $meta = $GLOBALS['gatewayMeta'][$gw['code']] ?? ['icon'=>'fas fa-plug','color'=>$info['color']];
?>
<div class="gw-card <?= $gw['status']==='active'?'active-gw':'' ?>" id="card-<?= $gw['id'] ?>"
     data-name="<?= strtolower(htmlspecialchars($gw['name'])) ?>"
     data-code="<?= strtolower(htmlspecialchars($gw['code'])) ?>">

    <div class="gw-head">
        <div class="gw-icon" style="background:<?= $info['color'] ?>20;color:<?= $info['color'] ?>">
            <?php
            $icn = $meta['icon'] ?? $info['icon'];
            ?>
            <i class="<?= $icn ?>"></i>
        </div>
        <div style="flex:1;min-width:0">
            <div class="gw-name"><?= htmlspecialchars($gw['name']) ?></div>
            <div class="gw-code"><?= htmlspecialchars($gw['code']) ?></div>
        </div>
        <div style="font-size:.72rem;color:#555">
            <?= htmlspecialchars($gw['country'] ?? '') ?>
        </div>
    </div>

    <div class="badges">
        <span class="badge-pill <?= $gw['status']==='active'?'st-active':'st-inactive' ?>">
            <?= $gw['status']==='active'?'✅ نشط':'⚪ غير نشط' ?>
        </span>
        <span class="badge-pill <?= $connBadge['cl'] ?>" id="badge-<?= $gw['id'] ?>">
            <i class="fas fa-<?= $connBadge['icon'] ?>"></i>
            <?= $connBadge['lbl'] ?>
            <?php if ($ms): ?><small>(<?= $ms ?>ms)</small><?php endif; ?>
        </span>
        <?php if ($hasKey): ?>
        <span class="badge-pill" style="background:rgba(91,192,222,.1);color:#5bc0de;border:1px solid #5bc0de40">
            <i class="fas fa-key"></i> API مُضاف
        </span>
        <?php endif; ?>
        <?php if ($gw['supports_2d'] ?? 0): ?><span class="badge-pill" style="background:rgba(91,192,222,.1);color:#5bc0de;border:1px solid #5bc0de40">2D</span><?php endif; ?>
        <?php if ($gw['supports_3d'] ?? 0): ?><span class="badge-pill" style="background:rgba(255,215,0,.1);color:var(--gold);border:1px solid #ffd70040">3D</span><?php endif; ?>
        <?php if ($gw['supports_hold'] ?? 0): ?><span class="badge-pill" style="background:rgba(159,232,112,.1);color:#9fe870;border:1px solid #9fe87040">HOLD</span><?php endif; ?>
    </div>

    <div class="gw-details">
        <?php if ($gw['api_endpoint']): ?>
        <div>🔗 <span><?= htmlspecialchars(substr($gw['api_endpoint'], 0, 45)) ?><?= strlen($gw['api_endpoint'])>45?'...':'' ?></span></div>
        <?php endif; ?>
        <?php if ($gw['swift_code']): ?>
        <div>🏦 SWIFT: <span><?= htmlspecialchars($gw['swift_code']) ?></span></div>
        <?php endif; ?>
        <?php if ($gw['last_tested']): ?>
        <div>🕐 آخر اختبار: <span><?= date('d/m H:i', strtotime($gw['last_tested'])) ?></span></div>
        <?php endif; ?>
        <?php if ($gw['test_message']): ?>
        <div style="color:<?= $connSt==='verified'?'#4CAF50':'#ef5350' ?>;margin-top:4px">
            <?= htmlspecialchars(mb_substr($gw['test_message'], 0, 60)) ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="gw-actions">
        <button class="btn btn-blue btn-sm" id="test-btn-<?= $gw['id'] ?>"
                onclick="testGateway(<?= $gw['id'] ?>, '<?= addslashes($gw['name']) ?>')">
            <i class="fas fa-plug"></i> اختبار
        </button>
        <a href="?section=<?= $activeSection ?>&edit=<?= $gw['id'] ?>" class="btn btn-sm btn-outline">
            <i class="fas fa-pen"></i> تعديل
        </a>
        <button class="btn btn-sm <?= $gw['status']==='active'?'btn-red':'btn-blue' ?>"
                onclick="toggleStatus(<?= $gw['id'] ?>, this)">
            <i class="fas fa-<?= $gw['status']==='active'?'pause':'play' ?>"></i>
            <?= $gw['status']==='active'?'تعطيل':'تفعيل' ?>
        </button>
        <a href="?section=<?= $activeSection ?>&delete=<?= $gw['id'] ?>&token=<?= $csrfToken ?>"
           class="btn btn-sm btn-red"
           onclick="return confirm('حذف نهائي؟')">
            <i class="fas fa-trash"></i>
        </a>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div><!-- end grid -->
<!-- ── نموذج إضافة / تعديل ── -->
<?php if ($showAdd): ?>
<?php
$editCode  = $editGw['code']    ?? '';
$editCreds = json_decode($editGw['credentials'] ?? '{}', true) ?: [];
$editSetts = json_decode($editGw['settings']    ?? '{}', true) ?: [];
$isEditGw  = (bool)$editGw;
?>
<div style="background:#0e0e0e;border:1.5px solid var(--border);border-radius:20px;padding:28px;margin-top:24px">
    <h3 style="color:var(--gold);margin:0 0 22px;font-size:1.1rem">
        <i class="fas fa-<?= $isEditGw?'pen':'plus-circle' ?>" style="margin-left:8px"></i>
        <?= $isEditGw?'تعديل بيانات: '.htmlspecialchars($editGw['name']):'إضافة '.$info['label'].' جديد' ?>
    </h3>
    <form method="POST">
        <input type="hidden" name="action" value="<?= $isEditGw?'edit_gateway':'add_gateway' ?>">
        <?php if ($isEditGw): ?><input type="hidden" name="id" value="<?= $editGw['id'] ?>"><?php endif; ?>
        <input type="hidden" name="section" value="<?= $activeSection ?>">

        <div class="form-grid">
            <div class="form-group">
                <label>الاسم *</label>
                <input name="name" value="<?= htmlspecialchars($editGw['name'] ?? '') ?>" placeholder="مثال: Stripe" required>
            </div>
            <div class="form-group">
                <label>الكود (code) *</label>
                <input name="code" value="<?= htmlspecialchars($editGw['code'] ?? '') ?>"
                       placeholder="stripe" <?= $isEditGw?'readonly':'' ?> required>
            </div>
            <div class="form-group">
                <label>النوع (type)</label>
                <select name="type">
                    <?php foreach (['electronic'=>'إلكترونية','bank'=>'بنكية','crypto'=>'عملات رقمية','wallet'=>'محفظة','social'=>'اجتماعي','game'=>'ألعاب'] as $v=>$l): ?>
                    <option value="<?= $v ?>" <?= ($editGw['type']??'')===$v?'selected':'' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>الحالة</label>
                <select name="status">
                    <option value="inactive" <?= ($editGw['status']??'')==='inactive'?'selected':'' ?>>غير نشط</option>
                    <option value="active"   <?= ($editGw['status']??'')==='active'  ?'selected':'' ?>>نشط</option>
                </select>
            </div>
            <div class="form-group full">
                <label>API Endpoint (Base URL)</label>
                <input name="api_endpoint" type="url" value="<?= htmlspecialchars($editGw['api_endpoint'] ?? '') ?>" placeholder="https://api.example.com">
            </div>
            <div class="form-group">
                <label>API Key</label>
                <input name="api_key" type="password" value="<?= htmlspecialchars($editCreds['api_key'] ?? '') ?>" placeholder="••••••">
            </div>
            <div class="form-group">
                <label>Secret Key</label>
                <input name="secret_key" type="password" value="<?= htmlspecialchars($editCreds['secret_key'] ?? '') ?>" placeholder="••••••">
            </div>
            <div class="form-group">
                <label>Client ID</label>
                <input name="client_id" value="<?= htmlspecialchars($editCreds['client_id'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Merchant ID / Profile ID</label>
                <input name="merchant_id" value="<?= htmlspecialchars($editCreds['merchant_id'] ?? $editCreds['profile_id'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Webhook URL</label>
                <input name="webhook_url" type="url" value="<?= htmlspecialchars($editSetts['webhook_url'] ?? '') ?>" placeholder="https://diparmas.com/api/webhook.php">
            </div>
            <div class="form-group">
                <label>Environment</label>
                <select name="environment">
                    <option value="sandbox" <?= ($editSetts['environment']??'')==='sandbox'?'selected':'' ?>>Sandbox</option>
                    <option value="live"    <?= ($editSetts['environment']??'')==='live'   ?'selected':'' ?>>Live</option>
                </select>
            </div>
            <div class="form-group">
                <label>البلد / المنطقة</label>
                <input name="country" value="<?= htmlspecialchars($editGw['country'] ?? '') ?>" placeholder="السعودية | الإمارات | Global">
            </div>
            <div class="form-group">
                <label>SWIFT / BIC (للبنوك)</label>
                <input name="swift_code" value="<?= htmlspecialchars($editGw['swift_code'] ?? '') ?>" placeholder="RJHISARI">
            </div>
            <div class="form-group">
                <label>نوع الاتصال</label>
                <select name="connection_type">
                    <option value="rest"    <?= ($editGw['connection_type']??'rest')==='rest'   ?'selected':'' ?>>REST API</option>
                    <option value="soap"    <?= ($editGw['connection_type']??'')==='soap'   ?'selected':'' ?>>SOAP/XML</option>
                    <option value="web3"    <?= ($editGw['connection_type']??'')==='web3'   ?'selected':'' ?>>Web3/Blockchain</option>
                    <option value="manual"  <?= ($editGw['connection_type']??'')==='manual' ?'selected':'' ?>>يدوي</option>
                </select>
            </div>
            <div class="form-group">
                <label>الترتيب في Checkout</label>
                <input name="sort_order" type="number" value="<?= intval($editGw['sort_order'] ?? 0) ?>" min="0">
            </div>
            <div class="form-group full">
                <label>وصف</label>
                <textarea name="description" rows="2"><?= htmlspecialchars($editGw['description'] ?? '') ?></textarea>
            </div>
            <div class="form-group full">
                <label>الميزات المدعومة</label>
                <div style="display:flex;gap:18px;flex-wrap:wrap;margin-top:6px">
                    <?php foreach (['supports_2d'=>'2D','supports_3d'=>'3D Secure','supports_hold'=>'HOLD (101.1)','supports_capture'=>'CAPTURE'] as $f=>$l): ?>
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;color:#ccc;font-size:.88rem">
                        <input type="checkbox" name="<?= $f ?>" value="1" <?= ($editGw[$f]??0)?'checked':'' ?> style="width:16px;height:16px;accent-color:var(--gold)">
                        <?= $l ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <hr class="sep">
        <div style="display:flex;gap:10px;flex-wrap:wrap">
            <button type="submit" class="btn btn-gold"><i class="fas fa-save"></i> حفظ</button>
            <?php if ($isEditGw): ?>
            <button type="button" onclick="testGateway(<?= $editGw['id'] ?>, '<?= addslashes($editGw['name'] ?? '') ?>')"
                    id="test-btn-<?= $editGw['id'] ?>"
                    class="btn btn-blue">
                <i class="fas fa-plug"></i> اختبار الاتصال الآن
            </button>
            <?php endif; ?>
            <a href="?section=<?= $activeSection ?>" class="btn btn-outline"><i class="fas fa-times"></i> إلغاء</a>
        </div>
    </form>
</div>
<?php endif; ?>

</div><!-- end container --><script>
var CSRF = '<?= $csrfToken ?>';
var ACTIVE_SECTION = '<?= $activeSection ?>';

// ── اختبار بوابة واحدة ──────────────────────────────────
async function testGateway(id, name) {
    var btn   = document.getElementById('test-btn-' + id);
    var badge = document.getElementById('badge-' + id);
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner test-spinner"></i> جاري...'; }
    if (badge) { badge.innerHTML = '<i class="fas fa-spinner test-spinner"></i> جاري الاختبار...'; badge.className = 'badge-pill conn-untested'; }
    try {
        var fd = new FormData();
        fd.append('action', 'test_connection');
        fd.append('gateway_id', id);
        fd.append('csrf_token', CSRF);
        var r = await fetch('connection_manager.php', {method:'POST', body:fd});
        var d = await r.json();
        var ok = d.success;
        var ms = d.response_ms ?? '';
        if (badge) {
            badge.className = 'badge-pill ' + (ok ? 'conn-verified' : 'conn-failed');
            badge.innerHTML = '<i class="fas fa-' + (ok ? 'check-circle' : 'times-circle') + '"></i> ' + d.message + (ms ? ' <small>(' + ms + 'ms)</small>' : '');
        }
        showToast(d.message, ok ? 'success' : 'error');
    } catch(e) {
        showToast('خطأ في الاتصال: ' + e.message, 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-plug"></i> اختبار'; }
    }
}

// ── اختبار كل البوابات في القسم ─────────────────────────
async function testAllInSection() {
    showToast('جاري اختبار كل الاتصالات...', 'info');
    try {
        var fd = new FormData();
        fd.append('action', 'test_all');
        fd.append('section', ACTIVE_SECTION);
        fd.append('csrf_token', CSRF);
        var r = await fetch('connection_manager.php', {method:'POST', body:fd});
        var d = await r.json();
        showToast('✅ ' + d.verified + ' متصلة | ❌ ' + d.failed + ' فشلت | إجمالي ' + d.total, d.failed === 0 ? 'success' : 'warning');
        setTimeout(() => location.reload(), 2500);
    } catch(e) {
        showToast('خطأ: ' + e.message, 'error');
    }
}

// ── تفعيل / تعطيل ────────────────────────────────────────
async function toggleStatus(id, btn) {
    try {
        var fd = new FormData();
        fd.append('action', 'toggle_status');
        fd.append('gateway_id', id);
        fd.append('csrf_token', CSRF);
        var r = await fetch('connection_manager.php', {method:'POST', body:fd});
        var d = await r.json();
        if (d.success) {
            var isActive = d.new_status === 'active';
            btn.className = 'btn btn-sm ' + (isActive ? 'btn-red' : 'btn-blue');
            btn.innerHTML = '<i class="fas fa-' + (isActive ? 'pause' : 'play') + '"></i> ' + (isActive ? 'تعطيل' : 'تفعيل');
            var card = document.getElementById('card-' + id);
            if (card) card.className = 'gw-card' + (isActive ? ' active-gw' : '');
            showToast(isActive ? '✅ تم التفعيل' : '⚪ تم التعطيل', 'success');
        }
    } catch(e) { showToast('خطأ', 'error'); }
}

// ── بحث ─────────────────────────────────────────────────
function filterCards(q) {
    q = q.trim().toLowerCase();
    document.querySelectorAll('#gwGrid .gw-card').forEach(function(c) {
        var n = (c.dataset.name || '') + ' ' + (c.dataset.code || '');
        c.style.display = (!q || n.includes(q)) ? '' : 'none';
    });
}

// ── Toast ─────────────────────────────────────────────────
function showToast(msg, type) {
    var t = document.getElementById('toast');
    if (!t) return;
    var c = {success:'#4CAF50', error:'#ef5350', warning:'#f0ad4e', info:'var(--gold)'};
    t.style.borderColor = c[type] || c.info;
    t.textContent = msg;
    t.style.transform = 'translateX(-50%) translateY(0)';
    clearTimeout(t._t);
    t._t = setTimeout(function() { t.style.transform = 'translateX(-50%) translateY(80px)'; }, 4000);
}
</script>
</body>
</html>