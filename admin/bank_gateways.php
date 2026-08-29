<?php
/**
 * DI PARMA | إدارة بوابات البنوك العالمية
 */
if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/includes/config.php';
require_once ROOT_PATH . '/includes/database.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/auth_check.php';
require_once ROOT_PATH . '/lib/BankGatewaysData.php';
requireAdmin();

$db   = db();
$csrf = generateCsrfToken();
$msg  = '';
$msgType = '';

// ── إنشاء الجداول ──────────────────────────────────────────
try {
    $db->execute("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "bank_gateways` (
        `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `bank_code`  VARCHAR(80)  NOT NULL,
        `bank_name`  VARCHAR(150) NOT NULL,
        `country`    VARCHAR(5)   NOT NULL DEFAULT '',
        `region`     VARCHAR(50)  NOT NULL DEFAULT '',
        `status`     VARCHAR(20)  NOT NULL DEFAULT 'active',
        `notes`      TEXT         DEFAULT NULL,
        `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->execute("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "bank_accounts` (
        `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `gateway_id` INT UNSIGNED NOT NULL,
        `label`      VARCHAR(120) NOT NULL,
        `fields`     TEXT         NOT NULL,
        `status`     VARCHAR(20)  NOT NULL DEFAULT 'active',
        `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`gateway_id`) REFERENCES `" . DB_PREFIX . "bank_gateways`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

$view   = $_GET['view'] ?? 'list';
$bankId = intval($_GET['id'] ?? 0);

// ── معالجة إضافة بنك ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_bank') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $msg = '❌ خطأ أمني'; $msgType = 'error';
    } else {
        $code  = trim($_POST['bank_code'] ?? '');
        $banks = getBanksData();
        if (!isset($banks[$code])) {
            $msg = '❌ البنك غير موجود'; $msgType = 'error';
        } elseif ($db->find('bank_gateways', ['bank_code' => $code])) {
            $msg = '⚠️ هذا البنك مضاف مسبقاً'; $msgType = 'error';
        } else {
            $b = $banks[$code];
            $db->insert('bank_gateways', [
                'bank_code' => $code,
                'bank_name' => $b['name'],
                'country'   => $b['country'],
                'region'    => $b['region'],
                'status'    => 'active',
                'notes'     => trim($_POST['notes'] ?? ''),
            ]);
            $msg = '✅ تم إضافة البنك بنجاح'; $msgType = 'success';
        }
    }
}

// ── معالجة إضافة حساب ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_account') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $msg = '❌ خطأ أمني'; $msgType = 'error';
    } else {
        $gId   = intval($_POST['gateway_id'] ?? 0);
        $label = trim($_POST['label'] ?? '');
        $gw    = $db->find('bank_gateways', ['id' => $gId]);
        if (!$gw || empty($label)) {
            $msg = '❌ يرجى ملء جميع الحقول'; $msgType = 'error';
        } else {
            $cnt = $db->query("SELECT COUNT(*) AS c FROM " . DB_PREFIX . "bank_accounts WHERE gateway_id=?", [$gId]);
            if (intval($cnt[0]['c'] ?? 0) >= 10) {
                $msg = '⚠️ الحد الأقصى 10 حسابات لكل بنك'; $msgType = 'error';
            } else {
                $banks  = getBanksData();
                $bData  = $banks[$gw['bank_code']] ?? [];
                $fields = [];
                foreach (($bData['fields'] ?? []) as $f) {
                    $fields[$f] = trim($_POST['field_' . $f] ?? '');
                }
                $db->insert('bank_accounts', [
                    'gateway_id' => $gId,
                    'label'      => $label,
                    'fields'     => json_encode($fields, JSON_UNESCAPED_UNICODE),
                    'status'     => 'active',
                ]);
                $msg = '✅ تم إضافة الحساب'; $msgType = 'success';
                $view = 'accounts'; $bankId = $gId;
            }
        }
    }
}

// ── معالجة تعديل حساب ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_account') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $msg = '❌ خطأ أمني'; $msgType = 'error';
    } else {
        $accId = intval($_POST['acc_id'] ?? 0);
        $acc   = $db->find('bank_accounts', ['id' => $accId]);
        if ($acc) {
            $gw     = $db->find('bank_gateways', ['id' => $acc['gateway_id']]);
            $banks  = getBanksData();
            $bData  = $banks[$gw['bank_code'] ?? ''] ?? [];
            $fields = [];
            foreach (($bData['fields'] ?? []) as $f) {
                $fields[$f] = trim($_POST['field_' . $f] ?? '');
            }
            $db->update('bank_accounts', [
                'label'  => trim($_POST['label'] ?? $acc['label']),
                'fields' => json_encode($fields, JSON_UNESCAPED_UNICODE),
                'status' => $_POST['status'] ?? $acc['status'],
            ], ['id' => $accId]);
            $msg = '✅ تم تحديث الحساب'; $msgType = 'success';
            $view = 'accounts'; $bankId = $acc['gateway_id'];
        }
    }
}

// ── حذف حساب ────────────────────────────────────────────────
if (isset($_GET['del_acc'], $_GET['token']) && hash_equals($csrf, $_GET['token'])) {
    $acc = $db->find('bank_accounts', ['id' => intval($_GET['del_acc'])]);
    if ($acc) {
        $bankId = intval($acc['gateway_id']);
        $db->delete('bank_accounts', ['id' => intval($_GET['del_acc'])]);
        $msg = '✅ تم حذف الحساب'; $msgType = 'success';
        $view = 'accounts';
    }
}

// ── حذف بنك ─────────────────────────────────────────────────
if (isset($_GET['del_bank'], $_GET['token']) && hash_equals($csrf, $_GET['token'])) {
    $db->delete('bank_gateways', ['id' => intval($_GET['del_bank'])]);
    $msg = '✅ تم حذف البنك'; $msgType = 'success';
    $view = 'list';
}

// ── تبديل حالة بنك ──────────────────────────────────────────
if (isset($_GET['toggle'], $_GET['token']) && hash_equals($csrf, $_GET['token'])) {
    $gw = $db->find('bank_gateways', ['id' => intval($_GET['toggle'])]);
    if ($gw) {
        $ns = $gw['status'] === 'active' ? 'inactive' : 'active';
        $db->update('bank_gateways', ['status' => $ns], ['id' => $gw['id']]);
        $msg = '✅ تم تغيير الحالة'; $msgType = 'success';
    }
}

// ── جلب البيانات ────────────────────────────────────────────
$allGateways = $db->query("SELECT * FROM " . DB_PREFIX . "bank_gateways ORDER BY region, bank_name ASC");
$currentBank = $bankId > 0 ? $db->find('bank_gateways', ['id' => $bankId]) : null;
$accounts    = $bankId > 0 ? $db->query("SELECT * FROM " . DB_PREFIX . "bank_accounts WHERE gateway_id=? ORDER BY id ASC", [$bankId]) : [];
$banksData   = getBanksData();
$byRegion    = getBanksByRegion();
$editAccId   = intval($_GET['edit_acc'] ?? 0);
$editAcc     = $editAccId > 0 ? $db->find('bank_accounts', ['id' => $editAccId]) : null;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | إدارة بوابات البنوك</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Cairo',sans-serif;background:#0a0f1e;color:#FFDFA0;min-height:100vh;padding:20px}
.wrap{max-width:1200px;margin:0 auto}
.topbar{background:rgba(10,16,39,.95);border:1px solid rgba(255,215,0,.25);border-radius:14px;padding:18px 24px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:20px}
.topbar h1{font-size:1.35rem;background:linear-gradient(135deg,#FFE066,#FFD700);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;background:linear-gradient(135deg,#FFE066,#FFD700);color:#000;border-radius:10px;text-decoration:none;font-weight:700;font-family:'Cairo',sans-serif;font-size:.88rem;border:none;cursor:pointer;transition:opacity .2s}
.btn:hover{opacity:.85}
.btn-out{background:transparent;border:1.5px solid rgba(255,215,0,.35);color:#FFD700}
.btn-red{background:rgba(217,83,79,.15);border:1px solid rgba(217,83,79,.4);color:#EF9A9A}
.btn-sm{padding:5px 12px;font-size:.78rem}
.card{background:rgba(10,16,39,.95);border:1px solid rgba(255,215,0,.18);border-radius:14px;padding:20px;margin-bottom:18px}
.card h2{color:#FFD700;font-size:1rem;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.alert-success{background:rgba(76,175,80,.12);border:1px solid rgba(76,175,80,.35);border-radius:10px;padding:12px 16px;color:#81C784;margin-bottom:16px}
.alert-error{background:rgba(217,83,79,.12);border:1px solid rgba(217,83,79,.35);border-radius:10px;padding:12px 16px;color:#EF9A9A;margin-bottom:16px}
table{width:100%;border-collapse:collapse}
th,td{padding:11px 13px;text-align:right;border-bottom:1px solid rgba(255,215,0,.07);font-size:.88rem}
th{color:#888;font-weight:600}
.badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:.75rem;font-weight:600}
.badge-active{background:rgba(76,175,80,.15);color:#4CAF50;border:1px solid rgba(76,175,80,.3)}
.badge-inactive{background:rgba(217,83,79,.15);color:#d9534f;border:1px solid rgba(217,83,79,.3)}
.fg{margin-bottom:14px}
.fg label{display:block;font-size:.78rem;color:#FFDFA0;font-weight:600;margin-bottom:6px}
.fg input,.fg select,.fg textarea{width:100%;padding:10px 12px;background:rgba(0,0,0,.7);border:1.5px solid rgba(255,255,255,.08);border-radius:9px;color:#E8F0FF;font-family:'Cairo',sans-serif;font-size:.9rem}
.fg input:focus,.fg select:focus{outline:none;border-color:#FFD700}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.region-header{background:rgba(255,215,0,.06);border:1px solid rgba(255,215,0,.12);border-radius:8px;padding:10px 14px;margin:14px 0 8px;font-weight:700;color:#FFD700;font-size:.9rem}
select option{background:#0a0f1e;color:#E8F0FF}
.acc-card{background:rgba(255,255,255,.03);border:1px solid rgba(255,215,0,.12);border-radius:10px;padding:14px;margin-bottom:10px}
.acc-card .acc-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
.acc-card .acc-label{font-weight:700;color:#FFD700}
.field-row{display:flex;gap:8px;align-items:flex-start;padding:5px 0;border-bottom:1px solid rgba(255,255,255,.04)}
.field-row:last-child{border:none}
.field-row .k{min-width:160px;font-size:.78rem;color:#888}
.field-row .v{font-size:.85rem;color:#E8F0FF;word-break:break-all}
.empty{text-align:center;padding:40px;color:#555}
.tabs{display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap}
.tab{padding:8px 18px;border-radius:999px;font-size:.85rem;font-weight:600;cursor:pointer;border:1.5px solid rgba(255,215,0,.2);color:#888;text-decoration:none}
.tab.active,.tab:hover{background:rgba(255,215,0,.1);border-color:#FFD700;color:#FFD700}
.count-badge{background:rgba(255,215,0,.15);color:#FFD700;border-radius:999px;padding:2px 8px;font-size:.72rem;margin-right:4px}
@media(max-width:640px){.grid2{grid-template-columns:1fr}.topbar{flex-direction:column}}
</style>
</head>
<body>
<div class="wrap">

<!-- Header -->
<div class="topbar">
  <div>
    <h1><i class="fas fa-university"></i> إدارة بوابات البنوك العالمية</h1>
    <div style="font-size:.8rem;color:#888;margin-top:4px"><?= count($allGateways) ?> بنك مضاف — <?= array_sum(array_map(fn($g) => intval($g['acc_count'] ?? 0), $db->query("SELECT gateway_id, COUNT(*) AS acc_count FROM " . DB_PREFIX . "bank_accounts GROUP BY gateway_id") ?: [])) ?> حساب</div>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap">
    <a href="?view=add" class="btn"><i class="fas fa-plus"></i> إضافة بنك</a>
    <a href="gateway_manager.php" class="btn btn-out"><i class="fas fa-route"></i> بوابات الدفع</a>
    <a href="../index.php" class="btn btn-out"><i class="fas fa-home"></i> الرئيسية</a>
  </div>
</div>

<?php if ($msg): ?>
<div class="alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<?php if ($view === 'add'): ?>
<!-- ════════════════════════════════════════════════════════ -->
<!-- إضافة بنك جديد -->
<!-- ════════════════════════════════════════════════════════ -->
<div class="card">
  <h2><i class="fas fa-plus-circle"></i> إضافة بنك من القائمة</h2>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="add_bank">
    <div class="fg">
      <label>اختر البنك</label>
      <select name="bank_code" required onchange="updateBankInfo(this.value)">
        <option value="">— اختر البنك —</option>
        <?php foreach ($byRegion as $region => $banks): ?>
          <optgroup label="<?= htmlspecialchars($region) ?>">
            <?php foreach ($banks as $code => $b): ?>
              <option value="<?= htmlspecialchars($code) ?>">
                <?= htmlspecialchars($b['flag'] . ' ' . $b['name'] . ' — ' . $b['local']) ?>
              </option>
            <?php endforeach; ?>
          </optgroup>
        <?php endforeach; ?>
      </select>
    </div>
    <div id="bankInfoBox" style="display:none;margin-bottom:14px;padding:12px;background:rgba(255,215,0,.06);border:1px solid rgba(255,215,0,.15);border-radius:10px;font-size:.85rem">
      <div id="bankInfoContent"></div>
    </div>
    <div class="fg">
      <label>ملاحظات (اختياري)</label>
      <textarea name="notes" rows="2" placeholder="أي ملاحظات إضافية..."></textarea>
    </div>
    <div style="display:flex;gap:10px">
      <button type="submit" class="btn"><i class="fas fa-save"></i> إضافة البنك</button>
      <a href="bank_gateways.php" class="btn btn-out">إلغاء</a>
    </div>
  </form>
</div>

<script>
const banksData = <?= json_encode($banksData, JSON_UNESCAPED_UNICODE) ?>;
function updateBankInfo(code) {
    const box = document.getElementById('bankInfoBox');
    const content = document.getElementById('bankInfoContent');
    if (!code || !banksData[code]) { box.style.display='none'; return; }
    const b = banksData[code];
    content.innerHTML = `
      <strong style="color:#FFD700">${b.flag} ${b.name}</strong><br>
      <span style="color:#aaa">${b.local || ''}</span><br>
      <span>SWIFT: <strong style="direction:ltr;display:inline-block">${b.swift || '-'}</strong></span>
      &nbsp;&nbsp; <span>العملات: ${(b.currencies||[]).join(', ')}</span><br>
      <span style="color:#888;font-size:.8rem">الحقول المطلوبة: ${(b.fields||[]).join(', ')}</span>
    `;
    box.style.display = 'block';
}
</script>

<?php elseif ($view === 'accounts' && $currentBank): ?>
<!-- ════════════════════════════════════════════════════════ -->
<!-- حسابات البنك -->
<!-- ════════════════════════════════════════════════════════ -->
<?php
$bInfo   = $banksData[$currentBank['bank_code']] ?? [];
$accCount = count($accounts);
?>
<div class="card">
  <h2>
    <?= htmlspecialchars($bInfo['flag'] ?? '🏦') ?>
    <?= htmlspecialchars($currentBank['bank_name']) ?>
    <span class="count-badge"><?= $accCount ?>/10 حساب</span>
    <span class="badge badge-<?= $currentBank['status'] ?>" style="margin-right:auto"><?= $currentBank['status'] === 'active' ? 'نشط' : 'معطل' ?></span>
  </h2>

  <?php if (!empty($bInfo)): ?>
  <div style="background:rgba(255,215,0,.05);border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:.82rem;display:flex;gap:20px;flex-wrap:wrap">
    <span><strong>SWIFT:</strong> <code style="color:#FFD700;direction:ltr;display:inline-block"><?= htmlspecialchars($bInfo['swift'] ?? '-') ?></code></span>
    <span><strong>الدولة:</strong> <?= htmlspecialchars($bInfo['region'] ?? '') ?></span>
    <span><strong>العملات:</strong> <?= implode(', ', $bInfo['currencies'] ?? []) ?></span>
  </div>
  <?php endif; ?>

  <!-- قائمة الحسابات -->
  <?php if (empty($accounts)): ?>
    <div class="empty"><i class="fas fa-wallet" style="font-size:2.5rem;color:#333;display:block;margin-bottom:12px"></i>لا توجد حسابات بعد</div>
  <?php else: ?>
    <?php foreach ($accounts as $acc):
      $fields = json_decode($acc['fields'] ?? '{}', true) ?: [];
      $isEdit = ($editAccId === intval($acc['id']));
    ?>
    <div class="acc-card">
      <div class="acc-top">
        <div>
          <span class="acc-label"><i class="fas fa-credit-card"></i> <?= htmlspecialchars($acc['label']) ?></span>
          <span class="badge badge-<?= $acc['status'] ?>" style="margin-right:8px"><?= $acc['status'] === 'active' ? 'نشط' : 'معطل' ?></span>
        </div>
        <div style="display:flex;gap:8px">
          <a href="?view=accounts&id=<?= $currentBank['id'] ?>&edit_acc=<?= $acc['id'] ?>" class="btn btn-sm btn-out"><i class="fas fa-edit"></i></a>
          <a href="?view=accounts&id=<?= $currentBank['id'] ?>&del_acc=<?= $acc['id'] ?>&token=<?= $csrf ?>"
             onclick="return confirm('حذف هذا الحساب؟')" class="btn btn-sm btn-red"><i class="fas fa-trash"></i></a>
        </div>
      </div>
      <?php if ($isEdit): ?>
      <!-- نموذج تعديل الحساب -->
      <form method="POST" style="margin-top:12px">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="edit_account">
        <input type="hidden" name="acc_id" value="<?= $acc['id'] ?>">
        <div class="grid2">
          <div class="fg">
            <label>تسمية الحساب</label>
            <input type="text" name="label" value="<?= htmlspecialchars($acc['label']) ?>" required>
          </div>
          <div class="fg">
            <label>الحالة</label>
            <select name="status">
              <option value="active" <?= $acc['status']==='active'?'selected':'' ?>>نشط</option>
              <option value="inactive" <?= $acc['status']==='inactive'?'selected':'' ?>>معطل</option>
            </select>
          </div>
        </div>
        <div class="grid2">
        <?php foreach (($bInfo['fields'] ?? []) as $f): ?>
          <div class="fg">
            <label><?= htmlspecialchars(getBankFieldLabel($f)) ?></label>
            <input type="text" name="field_<?= htmlspecialchars($f) ?>" value="<?= htmlspecialchars($fields[$f] ?? '') ?>">
          </div>
        <?php endforeach; ?>
        </div>
        <div style="display:flex;gap:8px">
          <button type="submit" class="btn btn-sm"><i class="fas fa-save"></i> حفظ</button>
          <a href="?view=accounts&id=<?= $currentBank['id'] ?>" class="btn btn-sm btn-out">إلغاء</a>
        </div>
      </form>
      <?php else: ?>
      <?php foreach ($fields as $k => $v): ?>
        <?php if (empty($v)) continue; ?>
        <div class="field-row">
          <span class="k"><?= htmlspecialchars(getBankFieldLabel($k)) ?></span>
          <span class="v" style="direction:ltr"><?= htmlspecialchars($v) ?></span>
        </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <!-- إضافة حساب جديد -->
  <?php if ($accCount < 10): ?>
  <div style="margin-top:20px;border-top:1px solid rgba(255,215,0,.1);padding-top:18px">
    <h2 style="font-size:.95rem;margin-bottom:14px"><i class="fas fa-plus"></i> إضافة حساب جديد (<?= $accCount ?>/10)</h2>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="add_account">
      <input type="hidden" name="gateway_id" value="<?= $currentBank['id'] ?>">
      <div class="fg">
        <label>تسمية الحساب (مثال: حساب رئيسي، فرع دبي...)</label>
        <input type="text" name="label" placeholder="تسمية مميزة للحساب" required>
      </div>
      <div class="grid2">
      <?php foreach (($bInfo['fields'] ?? []) as $f): ?>
        <div class="fg">
          <label><?= htmlspecialchars(getBankFieldLabel($f)) ?></label>
          <input type="text" name="field_<?= htmlspecialchars($f) ?>"
                 placeholder="<?= htmlspecialchars(getBankFieldLabel($f)) ?>">
        </div>
      <?php endforeach; ?>
      </div>
      <button type="submit" class="btn"><i class="fas fa-plus-circle"></i> إضافة الحساب</button>
    </form>
  </div>
  <?php else: ?>
    <div style="margin-top:14px;padding:12px;background:rgba(255,165,0,.08);border-radius:8px;color:#f0ad4e;font-size:.85rem">
      <i class="fas fa-info-circle"></i> وصلت للحد الأقصى (10 حسابات). احذف حساباً لإضافة جديد.
    </div>
  <?php endif; ?>

  <div style="margin-top:16px">
    <a href="bank_gateways.php" class="btn btn-out btn-sm"><i class="fas fa-arrow-right"></i> العودة للقائمة</a>
  </div>
</div>

<?php else: ?>
<!-- ════════════════════════════════════════════════════════ -->
<!-- قائمة البنوك المضافة -->
<!-- ════════════════════════════════════════════════════════ -->
<div class="card">
  <h2><i class="fas fa-list"></i> البنوك المضافة</h2>

  <?php if (empty($allGateways)): ?>
    <div class="empty">
      <i class="fas fa-university" style="font-size:2.5rem;color:#333;display:block;margin-bottom:12px"></i>
      لا توجد بنوك مضافة بعد<br>
      <a href="?view=add" class="btn" style="margin-top:14px"><i class="fas fa-plus"></i> إضافة أول بنك</a>
    </div>
  <?php else: ?>
    <?php
    // تجميع الحسابات لكل بنك
    $accCounts = [];
    $allAccCounts = $db->query("SELECT gateway_id, COUNT(*) AS c FROM " . DB_PREFIX . "bank_accounts GROUP BY gateway_id");
    foreach ($allAccCounts as $row) $accCounts[$row['gateway_id']] = intval($row['c']);
    // تجميع حسب المنطقة
    $grouped = [];
    foreach ($allGateways as $gw) $grouped[$gw['region']][] = $gw;
    ?>
    <?php foreach ($grouped as $region => $banks): ?>
    <div class="region-header"><i class="fas fa-globe"></i> <?= htmlspecialchars($region) ?></div>
    <table>
      <thead><tr>
        <th>البنك</th><th>SWIFT</th><th>الحسابات</th><th>الحالة</th><th>إجراءات</th>
      </tr></thead>
      <tbody>
      <?php foreach ($banks as $gw):
        $bInfo = $banksData[$gw['bank_code']] ?? [];
        $cnt   = $accCounts[$gw['id']] ?? 0;
      ?>
      <tr>
        <td>
          <strong><?= htmlspecialchars($bInfo['flag'] ?? '🏦') ?> <?= htmlspecialchars($gw['bank_name']) ?></strong><br>
          <small style="color:#888"><?= htmlspecialchars($bInfo['local'] ?? '') ?></small>
        </td>
        <td><code style="direction:ltr;display:inline-block;font-size:.8rem;color:#FFD700"><?= htmlspecialchars($bInfo['swift'] ?? '-') ?></code></td>
        <td>
          <a href="?view=accounts&id=<?= $gw['id'] ?>" class="btn btn-sm btn-out">
            <i class="fas fa-wallet"></i> <?= $cnt ?>/10 حساب
          </a>
        </td>
        <td>
          <a href="?toggle=<?= $gw['id'] ?>&token=<?= $csrf ?>" class="badge badge-<?= $gw['status'] ?>" style="cursor:pointer;text-decoration:none">
            <?= $gw['status'] === 'active' ? 'نشط' : 'معطل' ?>
          </a>
        </td>
        <td>
          <div style="display:flex;gap:6px">
            <a href="?view=accounts&id=<?= $gw['id'] ?>" class="btn btn-sm"><i class="fas fa-eye"></i> الحسابات</a>
            <a href="?del_bank=<?= $gw['id'] ?>&token=<?= $csrf ?>"
               onclick="return confirm('حذف هذا البنك وجميع حساباته؟')"
               class="btn btn-sm btn-red"><i class="fas fa-trash"></i></a>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- البنوك المتاحة للإضافة -->
<div class="card">
  <h2><i class="fas fa-globe"></i> البنوك المتاحة للإضافة (<?= count($banksData) ?> بنك)</h2>
  <?php foreach ($byRegion as $region => $banks): ?>
    <div class="region-header"><?= htmlspecialchars($region) ?></div>
    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px">
    <?php foreach ($banks as $code => $b):
      $alreadyAdded = (bool)$db->find('bank_gateways', ['bank_code' => $code]);
    ?>
      <div style="background:rgba(<?= $alreadyAdded?'76,175,80':'255,255,255' ?>,.04);border:1px solid rgba(<?= $alreadyAdded?'76,175,80':'255,215,0' ?>,.15);border-radius:8px;padding:8px 12px;font-size:.8rem">
        <?= htmlspecialchars($b['flag']) ?> <?= htmlspecialchars($b['name']) ?>
        <?php if ($alreadyAdded): ?>
          <span style="color:#4CAF50;font-size:.72rem"> ✓</span>
        <?php else: ?>
          <a href="javascript:void(0)" onclick="quickAdd('<?= htmlspecialchars($code) ?>')" style="color:#FFD700;font-size:.72rem;text-decoration:none;margin-right:4px">+ إضافة</a>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>

<form id="quickAddForm" method="POST" style="display:none">
  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
  <input type="hidden" name="action" value="add_bank">
  <input type="hidden" name="bank_code" id="quickBankCode">
  <input type="hidden" name="notes" value="">
</form>
<script>
function quickAdd(code) {
    if (!confirm('إضافة هذا البنك الآن؟')) return;
    document.getElementById('quickBankCode').value = code;
    document.getElementById('quickAddForm').submit();
}
</script>

<?php endif; ?>

</div><!-- /wrap -->
</body>
</html>
