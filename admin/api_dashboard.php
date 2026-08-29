<?php
/**
 * ============================================================
 * DI PARMA | Admin — API Dashboard
 * إدارة API Keys + إنشاء + إلغاء + عرض الإحصائيات
 * ============================================================
 */
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../api/v1/ApiAuth.php';

$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang']==='en' ? 'en' : 'ar';
$ar   = ($lang === 'ar');
$dir  = $ar ? 'rtl' : 'ltr';
$csrf = generateCsrfToken();
$db   = db();

$msg = ''; $msgType = ''; $newKeys = null;

// ── معالجة POST ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCsrfToken($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    // ── إنشاء عميل جديد ─────────────────────────────────────
    if ($action === 'create') {
        $name        = trim($_POST['name']         ?? '');
        $ledgerAddr  = trim($_POST['ledger_address'] ?? 'TEwLFWlwK55b7PuFfzgH1H2f3xs3pLgLn2');
        $webhookUrl  = trim($_POST['webhook_url']  ?? '');
        $dailyLimit  = floatval($_POST['daily_limit']   ?? 50000);
        $monthlyLim  = floatval($_POST['monthly_limit'] ?? 500000);

        if (empty($name)) {
            $msg = 'اسم العميل مطلوب'; $msgType = 'error';
        } else {
            $creds = ApiAuth::generateCredentials($name);
            try {
                $db->insert('api_clients', [
                    'name'           => $name,
                    'api_key'        => $creds['api_key'],
                    'api_secret'     => $creds['api_secret_enc'],
                    'webhook_secret' => $creds['whs_enc'],
                    'mid'            => $creds['mid'],
                    'tid'            => $creds['tid'],
                    'ledger_address' => $ledgerAddr ?: 'TEwLFWlwK55b7PuFfzgH1H2f3xs3pLgLn2',
                    'webhook_url'    => $webhookUrl,
                    'daily_limit'    => $dailyLimit,
                    'monthly_limit'  => $monthlyLim,
                    'status'         => 'active',
                    'created_by'     => intval($_SESSION['user_id'] ?? 0),
                    'created_at'     => date('Y-m-d H:i:s'),
                ]);
                $newKeys = array_merge($creds, ['name'=>$name]);
                $msg = 'تم إنشاء العميل بنجاح — احفظ المفاتيح الآن لن تظهر مرة أخرى';
                $msgType = 'success';
            } catch (Exception $e) {
                $msg = 'خطأ: ' . $e->getMessage(); $msgType = 'error';
            }
        }
    }

    // ── تغيير حالة ───────────────────────────────────────────
    if ($action === 'toggle') {
        $id        = intval($_POST['client_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? 'suspended';
        if (in_array($newStatus, ['active','suspended','revoked'])) {
            $db->execute("UPDATE dp_api_clients SET status=? WHERE id=?", [$newStatus, $id]);
            $msg = 'تم تحديث الحالة'; $msgType = 'success';
        }
    }

    // ── حذف ─────────────────────────────────────────────────
    if ($action === 'delete') {
        $id = intval($_POST['client_id'] ?? 0);
        $db->execute("DELETE FROM dp_api_clients WHERE id=?", [$id]);
        $msg = 'تم الحذف'; $msgType = 'success';
    }

    // ── تجديد المفاتيح ───────────────────────────────────────
    if ($action === 'rotate') {
        $id = intval($_POST['client_id'] ?? 0);
        $row = $db->query("SELECT name FROM dp_api_clients WHERE id=?", [$id])[0] ?? null;
        if ($row) {
            $creds = ApiAuth::generateCredentials($row['name']);
            $db->execute(
                "UPDATE dp_api_clients SET api_key=?,api_secret=?,webhook_secret=?,mid=?,tid=? WHERE id=?",
                [$creds['api_key'],$creds['api_secret_enc'],$creds['whs_enc'],$creds['mid'],$creds['tid'],$id]
            );
            $newKeys = array_merge($creds, ['name'=>$row['name']]);
            $msg = 'تم تجديد المفاتيح — احفظها الآن'; $msgType = 'success';
        }
    }
}

// ── جلب البيانات ─────────────────────────────────────────────
$clients = $db->query(
    "SELECT c.*, 
     (SELECT COUNT(*) FROM dp_api_logs l WHERE l.client_id=c.id) log_count,
     (SELECT COUNT(*) FROM dp_api_logs l WHERE l.client_id=c.id AND l.response_code=200) success_count
     FROM dp_api_clients c ORDER BY c.created_at DESC"
) ?: [];

$totalStats = $db->query(
    "SELECT COUNT(*) clients, COALESCE(SUM(total_charged),0) vol, COALESCE(SUM(total_txns),0) txns FROM dp_api_clients"
)[0] ?? ['clients'=>0,'vol'=>0,'txns'=>0];

$recentLogs = $db->query(
    "SELECT l.*, c.name client_name FROM dp_api_logs l
     LEFT JOIN dp_api_clients c ON c.id=l.client_id
     ORDER BY l.created_at DESC LIMIT 20"
) ?: [];
?><!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$dir?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | API Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&family=Share+Tech+Mono&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--gold:#FFD700;--gold2:#FFB700;--bg:#030609;--bg2:#060c14;--card:#090f1e;--card2:#0b1224;--border:rgba(255,215,0,.12);--border2:rgba(255,215,0,.28);--text:#edf0f7;--muted:#4a5568;--muted2:#718096;--green:#10B981;--red:#EF4444;--blue:#3B82F6;--purple:#8B5CF6}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.topbar{background:rgba(3,6,9,.97);border-bottom:1px solid var(--border);height:62px;display:flex;align-items:center;justify-content:space-between;padding:0 28px;position:sticky;top:0;z-index:100}
.tb-brand{color:var(--gold);font-weight:900;font-size:1.05rem;display:flex;align-items:center;gap:10px}
.api-badge{background:rgba(139,92,246,.12);border:1.5px solid rgba(139,92,246,.3);border-radius:10px;padding:5px 14px;font-size:.78rem;font-weight:800;color:var(--purple)}
.wrap{max-width:1300px;margin:0 auto;padding:28px 24px}
/* Developer portal header */
.portal-hero{display:flex;align-items:flex-end;justify-content:space-between;gap:24px;padding:34px 0 26px;border-bottom:1px solid var(--border);margin-bottom:24px}
.portal-hero h1{font-size:clamp(1.7rem,3vw,2.7rem);line-height:1.15;color:var(--text);margin-bottom:10px}
.portal-hero p{color:var(--muted2);font-size:.92rem}
.portal-kicker{color:var(--blue);font-size:.72rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase;margin-bottom:9px}
.portal-actions{display:flex;gap:10px;flex-wrap:wrap}
.endpoint-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:30px}
.endpoint-card{display:flex;align-items:center;gap:13px;background:var(--card);border:1px solid var(--border);border-radius:12px;padding:15px 16px;text-decoration:none;color:var(--text);transition:.2s}
.endpoint-card:hover{border-color:var(--border2);transform:translateY(-2px);background:var(--card2)}
.endpoint-icon{width:38px;height:38px;border-radius:10px;display:grid;place-items:center;background:rgba(59,130,246,.12);color:var(--blue);flex:none}
.endpoint-card strong{display:block;font-size:.82rem;margin-bottom:3px}
.endpoint-card span{display:block;color:var(--muted2);font-size:.7rem}
.method{font-family:'Share Tech Mono',monospace;color:var(--green);font-size:.68rem;margin-left:auto}
/* Stats */
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:28px}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:20px;text-align:center}
.stat-val{font-size:1.5rem;font-weight:900;color:var(--gold);margin-bottom:4px}
.stat-lbl{font-size:.72rem;color:var(--muted2)}
/* Layout */
.grid-2{display:grid;grid-template-columns:380px 1fr;gap:22px}
/* Form */
.form-card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:24px;margin-bottom:20px}
.form-title{font-size:.9rem;font-weight:800;color:var(--gold);display:flex;align-items:center;gap:8px;margin-bottom:18px}
.fld{margin-bottom:14px}
.fld label{display:block;font-size:.72rem;color:var(--muted2);margin-bottom:5px;font-weight:700}
.fld input,.fld select{width:100%;background:rgba(255,255,255,.04);border:1.5px solid var(--border);border-radius:11px;padding:11px 14px;color:var(--text);font-family:'Cairo',sans-serif;font-size:.88rem;transition:.2s}
.fld input:focus,.fld select:focus{outline:none;border-color:var(--gold)}
/* Keys Display */
.keys-reveal{background:rgba(16,185,129,.05);border:1.5px solid rgba(16,185,129,.25);border-radius:14px;padding:20px;margin-bottom:20px}
.keys-title{font-size:.9rem;font-weight:800;color:var(--green);display:flex;align-items:center;gap:8px;margin-bottom:16px}
.key-row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;background:rgba(0,0,0,.3);border-radius:10px;margin-bottom:8px}
.key-label{font-size:.7rem;color:var(--muted2);min-width:100px;font-weight:700}
.key-value{font-family:'Share Tech Mono',monospace;font-size:.72rem;color:var(--green);word-break:break-all;flex:1}
.key-copy{background:rgba(16,185,129,.15);border:none;border-radius:7px;padding:5px 10px;color:var(--green);cursor:pointer;font-size:.68rem;flex-shrink:0;transition:.2s}
.key-copy:hover{background:rgba(16,185,129,.3)}
/* Table */
.table-card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:20px}
.table-title{font-size:.9rem;font-weight:800;color:var(--gold);display:flex;align-items:center;gap:8px;margin-bottom:16px;justify-content:space-between}
.tbl{width:100%;border-collapse:collapse;font-size:.78rem}
.tbl th{padding:10px 12px;color:var(--muted2);font-weight:700;text-align:<?=$ar?'right':'left'?>;border-bottom:1px solid var(--border);background:rgba(255,215,0,.02)}
.tbl td{padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.03);vertical-align:middle}
.tbl tr:hover td{background:rgba(255,215,0,.02)}
/* Badges */
.badge{padding:3px 10px;border-radius:8px;font-size:.65rem;font-weight:800;display:inline-flex;align-items:center;gap:4px}
.badge-active{background:rgba(16,185,129,.12);color:var(--green);border:1px solid rgba(16,185,129,.2)}
.badge-suspended{background:rgba(251,191,36,.1);color:#FBBF24;border:1px solid rgba(251,191,36,.2)}
.badge-revoked{background:rgba(239,68,68,.1);color:var(--red);border:1px solid rgba(239,68,68,.2)}
/* Buttons */
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:10px;border:none;font-family:'Cairo',sans-serif;font-size:.8rem;font-weight:700;cursor:pointer;transition:.2s;text-decoration:none}
.btn-gold{background:linear-gradient(135deg,var(--gold),var(--gold2));color:#000;box-shadow:0 5px 15px rgba(255,215,0,.2)}
.btn-gold:hover{transform:translateY(-1px)}
.btn-red{background:rgba(239,68,68,.12);color:var(--red);border:1px solid rgba(239,68,68,.2)}
.btn-dark{background:rgba(255,255,255,.06);color:var(--text);border:1.5px solid var(--border)}
.btn-green{background:rgba(16,185,129,.12);color:var(--green);border:1px solid rgba(16,185,129,.2)}
.btn-sm{padding:5px 10px;font-size:.7rem}
/* Alert */
.alert{padding:12px 16px;border-radius:12px;font-size:.82rem;margin-bottom:16px;display:flex;align-items:center;gap:10px}
.alert-success{background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.2);color:var(--green)}
.alert-error{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);color:var(--red)}
/* Code block */
.code-block{background:rgba(0,0,0,.4);border:1px solid var(--border);border-radius:12px;padding:16px;font-family:'Share Tech Mono',monospace;font-size:.72rem;line-height:1.8;overflow-x:auto;white-space:pre}
/* Tabs */
.tab-bar{display:flex;gap:8px;margin-bottom:20px;border-bottom:1px solid var(--border);padding-bottom:8px}
.tab-btn{padding:8px 18px;border-radius:10px;border:none;font-family:'Cairo',sans-serif;font-size:.8rem;font-weight:700;cursor:pointer;color:var(--muted2);background:transparent;transition:.2s}
.tab-btn.active{background:rgba(255,215,0,.08);color:var(--gold);border:1px solid rgba(255,215,0,.2)}
/* Toast */
#toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(100px);background:var(--card);border:1px solid var(--border2);border-radius:14px;padding:12px 28px;font-size:.84rem;font-weight:700;z-index:9999;transition:.35s;color:var(--text)}
@media(max-width:1100px){.grid-2{grid-template-columns:1fr}.stats-grid{grid-template-columns:1fr 1fr}}
</style>
</head>
<body>
<header class="topbar">
  <div class="tb-brand">
    <i class="fas fa-coins"></i> DI PARMA
    <span style="color:var(--muted)">|</span>
    <div class="api-badge"><i class="fas fa-key"></i> API Dashboard</div>
  </div>
  <div style="display:flex;align-items:center;gap:10px">
    <a href="https://diparmas.com/api/v1/docs" target="_blank" class="btn btn-dark" style="font-size:.74rem">
      <i class="fas fa-book"></i> API Docs
    </a>
    <a href="../dashboard.php" class="btn btn-dark" style="font-size:.74rem">
      <i class="fas fa-th-large"></i> <?=$ar?'لوحة التحكم':'Dashboard'?>
    </a>
  </div>
</header>

<div class="wrap">

  <section class="portal-hero">
    <div>
      <div class="portal-kicker">DI PARMA DEVELOPER PORTAL</div>
      <h1><?=$ar?'ابنِ باستخدام DI PARMA API':'Build with the DI PARMA API'?></h1>
      <p><?=$ar?'الوصول إلى مفاتيحك، نقاط النهاية، ومراقبة استخدامك':'Access your credentials, explore endpoints, and monitor usage'?></p>
    </div>
    <div class="portal-actions">
      <a href="../api/v1/docs" target="_blank" class="btn btn-gold"><i class="fas fa-rocket"></i> <?=$ar?'دليل البدء':'Welcome Guide'?></a>
      <a href="#api-endpoints" class="btn btn-dark"><i class="fas fa-code"></i> API v1</a>
    </div>
  </section>

  <section id="api-endpoints" class="endpoint-grid" aria-label="API endpoints">
    <a class="endpoint-card" href="../api/v1/docs" target="_blank"><span class="endpoint-icon"><i class="fas fa-code"></i></span><span><strong>Endpoint Reference</strong><span>All DI PARMA API endpoints</span></span><span class="method">DOCS</span></a>
    <a class="endpoint-card" href="../api/v1/docs" target="_blank"><span class="endpoint-icon"><i class="fas fa-credit-card"></i></span><span><strong>Charge</strong><span>Create a card payment</span></span><span class="method">POST</span></a>
    <a class="endpoint-card" href="../api/v1/docs" target="_blank"><span class="endpoint-icon"><i class="fas fa-wallet"></i></span><span><strong>Balance</strong><span>Ledger and account balance</span></span><span class="method">GET</span></a>
    <a class="endpoint-card" href="../api/v1/docs" target="_blank"><span class="endpoint-icon"><i class="fas fa-list"></i></span><span><strong>Transactions</strong><span>Search and monitor payments</span></span><span class="method">GET</span></a>
    <a class="endpoint-card" href="../api/v1/docs" target="_blank"><span class="endpoint-icon"><i class="fas fa-arrows-rotate"></i></span><span><strong>Refund / Void</strong><span>Reverse an existing payment</span></span><span class="method">POST</span></a>
    <a class="endpoint-card" href="../api/v1/docs" target="_blank"><span class="endpoint-icon"><i class="fas fa-bolt"></i></span><span><strong>Webhooks</strong><span>Receive payment status events</span></span><span class="method">HMAC</span></a>
  </section>

  <!-- Stats -->
  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-val"><?=number_format($totalStats['clients'])?></div>
      <div class="stat-lbl"><?=$ar?'إجمالي العملاء':'Total Clients'?></div>
    </div>
    <div class="stat-card">
      <div class="stat-val" style="color:var(--green)">$<?=number_format($totalStats['vol'],0)?></div>
      <div class="stat-lbl"><?=$ar?'إجمالي المعالج':'Total Processed'?></div>
    </div>
    <div class="stat-card">
      <div class="stat-val" style="color:var(--blue)"><?=number_format($totalStats['txns'])?></div>
      <div class="stat-lbl"><?=$ar?'إجمالي المعاملات':'Total TXNs'?></div>
    </div>
    <div class="stat-card">
      <div class="stat-val" style="color:var(--purple)"><?=count(array_filter($clients,fn($c)=>$c['status']==='active'))?></div>
      <div class="stat-lbl"><?=$ar?'عملاء نشطون':'Active Clients'?></div>
    </div>
  </div>

  <?php if($msg): ?>
  <div class="alert alert-<?=$msgType?>"><i class="fas fa-<?=$msgType==='success'?'check-circle':'exclamation-circle'?>"></i> <?=htmlspecialchars($msg)?></div>
  <?php endif; ?>

  <!-- New Keys Reveal -->
  <?php if($newKeys): ?>
  <div class="keys-reveal">
    <div class="keys-title">
      <i class="fas fa-key"></i>
      <?=$ar?'مفاتيح جديدة — احفظها الآن، لن تظهر مرة أخرى':'New Keys — Save Now, will not be shown again'?>
      <span style="margin-right:auto"></span>
      <button class="btn btn-green btn-sm" onclick="copyAllKeys()"><i class="fas fa-copy"></i> <?=$ar?'نسخ الكل':'Copy All'?></button>
    </div>
    <?php
    $keyItems = [
      'API_KEY (K)'      => $newKeys['api_key'],
      'API_SECRET (S)'   => $newKeys['api_secret'],
      'WEBHOOK_SECRET'   => $newKeys['webhook_secret'],
      'MID'              => $newKeys['mid'],
      'TID'              => $newKeys['tid'],
    ];
    foreach($keyItems as $label => $val): ?>
    <div class="key-row" id="krow_<?=md5($label)?>">
      <span class="key-label"><?=$label?></span>
      <span class="key-value" id="kval_<?=md5($label)?>"><?=htmlspecialchars($val)?></span>
      <button class="key-copy" onclick="copyKey('<?=htmlspecialchars($val,ENT_QUOTES)?>')">
        <i class="fas fa-copy"></i> نسخ
      </button>
    </div>
    <?php endforeach; ?>

    <!-- Example Code -->
    <div style="margin-top:16px">
      <div style="font-size:.72rem;color:var(--muted2);margin-bottom:8px;font-weight:700"><i class="fas fa-code"></i> مثال على الاستخدام</div>
      <div class="code-block" id="exampleCode"><?php
$exAmt = '100.00';
$exKey = $newKeys['api_key'];
$exSec = $newKeys['api_secret'];
echo htmlspecialchars(<<<CODE
// PHP Example
\$apiKey    = "{$exKey}";
\$apiSecret = "{$exSec}";
\$timestamp = time();
\$body      = json_encode([
    "amount"      => {$exAmt},
    "currency"    => "USD",
    "card_number" => "4111111111111111",
    "card_name"   => "JOHN DOE",
    "card_expiry" => "12/26",
    "card_cvv"    => "123",
    "txn_type"    => "purchase",
    "sec_mode"    => "3D"
]);
\$sig = hash_hmac('sha256',
    \$apiKey . ':' . \$timestamp . ':' . hash('sha256', \$body),
    \$apiSecret
);
// POST https://diparmas.com/api/v1/charge
// Headers: X-Api-Key, X-Timestamp, X-Signature
CODE);
      ?></div>
    </div>
  </div>
  <?php endif; ?>

  <div class="tab-bar">
    <button class="tab-btn active" onclick="showTab('clients',this)"><?=$ar?'العملاء':'Clients'?></button>
    <button class="tab-btn" onclick="showTab('create',this)"><?=$ar?'إنشاء عميل':'Create Client'?></button>
    <button class="tab-btn" onclick="showTab('logs',this)"><?=$ar?'سجل الطلبات':'Request Logs'?></button>
    <button class="tab-btn" onclick="showTab('docs',this)">API Docs</button>
  </div>

  <!-- ══ TAB: Clients ══ -->
  <div id="tab-clients">
    <div class="table-card">
      <div class="table-title">
        <span><i class="fas fa-users"></i> <?=$ar?'العملاء النشطون':'API Clients'?></span>
        <span style="font-size:.7rem;color:var(--muted2)"><?=count($clients)?> <?=$ar?'عميل':'clients'?></span>
      </div>
      <div style="overflow-x:auto">
        <table class="tbl">
          <thead><tr>
            <th><?=$ar?'الاسم':'Name'?></th>
            <th>API Key</th>
            <th>MID</th>
            <th>TID</th>
            <th>Ledger</th>
            <th><?=$ar?'الحجم':'Volume'?></th>
            <th><?=$ar?'الحالة':'Status'?></th>
            <th><?=$ar?'آخر استخدام':'Last Used'?></th>
            <th><?=$ar?'إجراءات':'Actions'?></th>
          </tr></thead>
          <tbody>
          <?php foreach($clients as $c): ?>
          <tr>
            <td style="font-weight:800"><?=htmlspecialchars($c['name'])?></td>
            <td>
              <span style="font-family:'Share Tech Mono',monospace;font-size:.68rem;color:var(--purple)">
                <?=substr($c['api_key'],0,12)?>...
              </span>
              <button class="btn btn-dark btn-sm" style="font-size:.6rem;margin-right:4px"
                onclick="copyKey('<?=htmlspecialchars($c['api_key'],ENT_QUOTES)?>')">
                <i class="fas fa-copy"></i>
              </button>
            </td>
            <td style="font-family:'Share Tech Mono',monospace;font-size:.72rem"><?=htmlspecialchars($c['mid'])?></td>
            <td style="font-family:'Share Tech Mono',monospace;font-size:.72rem"><?=htmlspecialchars($c['tid'])?></td>
            <td style="font-family:'Share Tech Mono',monospace;font-size:.65rem;color:var(--green)">
              <?=htmlspecialchars(substr($c['ledger_address']??'',0,14))?>...
            </td>
            <td style="font-weight:700;color:var(--green)">$<?=number_format(floatval($c['total_charged']),0)?></td>
            <td>
              <span class="badge badge-<?=htmlspecialchars($c['status'])?>">
                <?=$c['status']==='active'?'●':($c['status']==='suspended'?'⏸':'✗')?> <?=htmlspecialchars($c['status'])?>
              </span>
            </td>
            <td style="font-size:.7rem;color:var(--muted2)"><?=$c['last_used_at'] ? date('d/m/y H:i',strtotime($c['last_used_at'])) : '—'?></td>
            <td>
              <div style="display:flex;gap:5px;flex-wrap:wrap">
                <?php if($c['status']==='active'): ?>
                <form method="post" style="display:inline">
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="client_id" value="<?=$c['id']?>">
                  <input type="hidden" name="new_status" value="suspended">
                  <input type="hidden" name="csrf_token" value="<?=$csrf?>">
                  <button class="btn btn-sm" style="background:rgba(251,191,36,.1);color:#FBBF24;border:1px solid rgba(251,191,36,.2)" title="Suspend">
                    <i class="fas fa-pause"></i>
                  </button>
                </form>
                <?php else: ?>
                <form method="post" style="display:inline">
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="client_id" value="<?=$c['id']?>">
                  <input type="hidden" name="new_status" value="active">
                  <input type="hidden" name="csrf_token" value="<?=$csrf?>">
                  <button class="btn btn-green btn-sm" title="Activate"><i class="fas fa-play"></i></button>
                </form>
                <?php endif; ?>
                <form method="post" style="display:inline">
                  <input type="hidden" name="action" value="rotate">
                  <input type="hidden" name="client_id" value="<?=$c['id']?>">
                  <input type="hidden" name="csrf_token" value="<?=$csrf?>">
                  <button class="btn btn-dark btn-sm" title="<?=$ar?'تجديد المفاتيح':'Rotate Keys'?>" onclick="return confirm('<?=$ar?'تجديد المفاتيح سيبطل القديمة':'Rotating keys will invalidate old ones'?>?')">
                    <i class="fas fa-sync-alt"></i>
                  </button>
                </form>
                <form method="post" style="display:inline">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="client_id" value="<?=$c['id']?>">
                  <input type="hidden" name="csrf_token" value="<?=$csrf?>">
                  <button class="btn btn-red btn-sm" onclick="return confirm('<?=$ar?'حذف نهائي؟':'Delete permanently?'?>')" title="Delete">
                    <i class="fas fa-trash"></i>
                  </button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($clients)): ?>
          <tr><td colspan="9" style="text-align:center;padding:30px;color:var(--muted2)"><?=$ar?'لا يوجد عملاء بعد':'No clients yet'?></td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ══ TAB: Create ══ -->
  <div id="tab-create" style="display:none">
    <div class="form-card">
      <div class="form-title"><i class="fas fa-plus-circle"></i> <?=$ar?'إنشاء عميل API جديد':'Create New API Client'?></div>
      <form method="post">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="csrf_token" value="<?=$csrf?>">

        <div class="fld">
          <label><i class="fas fa-user"></i> <?=$ar?'اسم العميل / التطبيق':'Client / App Name'?> *</label>
          <input type="text" name="name" required placeholder="<?=$ar?'مثال: تطبيق الكاشير':'e.g. POS App'?>">
        </div>
        <div class="fld">
          <label><i class="fas fa-wallet"></i> Ledger TRX Address</label>
          <input type="text" name="ledger_address" value="TEwLFWlwK55b7PuFfzgH1H2f3xs3pLgLn2"
            style="font-family:'Share Tech Mono',monospace;font-size:.82rem">
        </div>
        <div class="fld">
          <label><i class="fas fa-link"></i> Webhook URL <?=$ar?'(اختياري)':'(optional)'?></label>
          <input type="url" name="webhook_url" placeholder="https://yourapp.com/webhook">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
          <div class="fld">
            <label><?=$ar?'الحد اليومي ($)':'Daily Limit ($)'?></label>
            <input type="number" name="daily_limit" value="50000" min="100" step="100">
          </div>
          <div class="fld">
            <label><?=$ar?'الحد الشهري ($)':'Monthly Limit ($)'?></label>
            <input type="number" name="monthly_limit" value="500000" min="1000" step="1000">
          </div>
        </div>

        <button type="submit" class="btn btn-gold" style="width:100%;justify-content:center;padding:13px;font-size:.9rem">
          <i class="fas fa-key"></i> <?=$ar?'إنشاء وتوليد المفاتيح':'Generate API Keys'?>
        </button>
      </form>
    </div>
  </div>

  <!-- ══ TAB: Logs ══ -->
  <div id="tab-logs" style="display:none">
    <div class="table-card">
      <div class="table-title"><i class="fas fa-list"></i> <?=$ar?'آخر 20 طلب':'Last 20 Requests'?></div>
      <div style="overflow-x:auto">
        <table class="tbl">
          <thead><tr>
            <th><?=$ar?'العميل':'Client'?></th>
            <th>Endpoint</th>
            <th>Method</th>
            <th>Status</th>
            <th>Reference</th>
            <th>IP</th>
            <th>ms</th>
            <th><?=$ar?'الوقت':'Time'?></th>
          </tr></thead>
          <tbody>
          <?php foreach($recentLogs as $log): ?>
          <tr>
            <td style="font-size:.72rem"><?=htmlspecialchars($log['client_name']??'—')?></td>
            <td style="font-family:'Share Tech Mono',monospace;font-size:.68rem;color:var(--purple)"><?=htmlspecialchars($log['endpoint'])?></td>
            <td><span style="background:rgba(59,130,246,.1);color:var(--blue);padding:2px 7px;border-radius:6px;font-size:.65rem;font-weight:700"><?=htmlspecialchars($log['method'])?></span></td>
            <td>
              <span class="badge <?=($log['response_code']>=200&&$log['response_code']<300)?'badge-active':($log['response_code']>=400?'badge-revoked':'badge-suspended')?>">
                <?=$log['response_code']?>
              </span>
            </td>
            <td style="font-family:'Share Tech Mono',monospace;font-size:.68rem"><?=htmlspecialchars(substr($log['reference']??'—',0,20))?></td>
            <td style="font-size:.68rem;color:var(--muted2)"><?=htmlspecialchars($log['ip']??'—')?></td>
            <td style="font-size:.68rem;color:var(--muted2)"><?=$log['duration_ms']?>'ms':''?><?=$log['duration_ms']??'—'?></td>
            <td style="font-size:.7rem;color:var(--muted2)"><?=date('d/m H:i',strtotime($log['created_at']))?></td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($recentLogs)): ?>
          <tr><td colspan="8" style="text-align:center;padding:30px;color:var(--muted2)"><?=$ar?'لا توجد سجلات':'No logs'?></td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ══ TAB: Docs ══ -->
  <div id="tab-docs" style="display:none">
    <div class="form-card">
      <div class="form-title"><i class="fas fa-book"></i> API Documentation</div>

      <div style="margin-bottom:20px">
        <div style="font-size:.82rem;font-weight:800;color:var(--gold);margin-bottom:10px">Base URL</div>
        <div class="code-block">https://diparmas.com/api/v1</div>
      </div>

      <div style="margin-bottom:20px">
        <div style="font-size:.82rem;font-weight:800;color:var(--gold);margin-bottom:10px">Authentication Headers</div>
        <div class="code-block">X-Api-Key: dpk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
X-Timestamp: 1753200000
X-Signature: HMAC-SHA256(api_secret, "api_key:timestamp:sha256(body)")
Content-Type: application/json</div>
      </div>

      <div style="margin-bottom:20px">
        <div style="font-size:.82rem;font-weight:800;color:var(--gold);margin-bottom:10px">POST /api/v1/charge — <?=$ar?'سحب من البطاقة → Ledger':'Card Charge → Ledger'?></div>
        <div class="code-block">{
  "amount":         100.00,
  "currency":       "USD",
  "card_number":    "4111111111111111",
  "card_name":      "JOHN DOE",
  "card_expiry":    "12/26",
  "card_cvv":       "123",
  "txn_type":       "purchase",
  "sec_mode":       "3D",
  "ledger_address": "TEwLFWlwK55b7PuFfzgH1H2f3xs3pLgLn2",
  "reference":      "ORDER-001"
}

// Response
{
  "success":        true,
  "reference":      "ORDER-001",
  "status":         "completed",
  "approval_code":  "ABC123",
  "rrn":            "123456789012",
  "ledger_status":  "queued",
  "duration_ms":    1240
}</div>
      </div>

      <div style="margin-bottom:20px">
        <div style="font-size:.82rem;font-weight:800;color:var(--gold);margin-bottom:10px">GET /api/v1/balance</div>
        <div class="code-block">{
  "success":       true,
  "ledger_trx":    12.5400,
  "ledger_usdt":   4850.00,
  "account_stats": {
    "total_charged": 125000.00,
    "total_txns": 47
  }
}</div>
      </div>

      <div>
        <div style="font-size:.82rem;font-weight:800;color:var(--gold);margin-bottom:10px">Webhook Events</div>
        <div class="code-block">// X-DiParma-Signature: HMAC-SHA256(webhook_secret, payload)
{
  "event":     "charge.completed",
  "reference": "ORDER-001",
  "amount":    100.00,
  "status":    "completed",
  "ledger_status": "queued",
  "timestamp": 1753200000
}</div>
      </div>
    </div>
  </div>

</div><!-- /wrap -->

<div id="toast"></div>

<script>
function showTab(name, btn) {
  document.querySelectorAll('[id^="tab-"]').forEach(t => t.style.display='none');
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-'+name).style.display = '';
  btn.classList.add('active');
}

function copyKey(txt) {
  navigator.clipboard?.writeText(txt).then(() => toast('✅ تم النسخ','success'));
}

function copyAllKeys() {
  const rows = document.querySelectorAll('.key-value');
  const text = Array.from(rows).map(r => r.textContent.trim()).join('\n');
  navigator.clipboard?.writeText(text).then(() => toast('✅ تم نسخ جميع المفاتيح','success'));
}

function toast(msg, type='info') {
  const t = document.getElementById('toast');
  const c = {success:'var(--green)',error:'var(--red)',info:'var(--gold)'};
  t.style.borderColor = c[type]||c.info;
  t.style.color = c[type]||c.info;
  t.textContent = msg;
  t.style.transform = 'translateX(-50%) translateY(0)';
  clearTimeout(t._t);
  t._t = setTimeout(()=>{ t.style.transform='translateX(-50%) translateY(100px)'; }, 3500);
}

// إذا عرضت مفاتيح جديدة — افتح tab create
<?php if($newKeys): ?>
document.querySelector('.tab-btn').click();
showTab('clients', document.querySelector('.tab-btn'));
<?php endif; ?>
</script>
</body>
</html>
