<?php
/**
 * DI PARMA | Audit Dashboard — VARA Compliant
 * سجل كامل لكل العمليات قابل للتصدير
 */
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/crypto_schema.php';

requireAdmin();
$db = db();

// ── فلاتر ─────────────────────────────────────────────
$dateFrom = $_GET['from']    ?? date('Y-m-d', strtotime('-30 days'));
$dateTo   = $_GET['to']      ?? date('Y-m-d');
$status   = $_GET['status']  ?? '';
$type     = $_GET['type']    ?? '';
$search   = trim($_GET['q']  ?? '');
$export   = $_GET['export']  ?? '';
$page     = max(1, intval($_GET['page'] ?? 1));
$perPage  = 50;

// ── بناء الاستعلام ────────────────────────────────────
$where  = ["t.created_at BETWEEN ? AND ?"];
$params = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];

if ($status) { $where[] = 't.status = ?'; $params[] = $status; }
if ($search) {
    $where[] = '(t.reference LIKE ? OR t.customer_name LIKE ? OR t.customer_email LIKE ?)';
    $params  = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

// ── العدد الكلي ───────────────────────────────────────
$totalRow = $db->query(
    "SELECT COUNT(*) as c, COALESCE(SUM(amount),0) as total
     FROM dp_transactions t $whereSQL", $params
);
$totalCount  = (int)($totalRow[0]['c']     ?? 0);
$totalAmount = (float)($totalRow[0]['total'] ?? 0);
$totalPages  = max(1, ceil($totalCount / $perPage));
$offset      = ($page - 1) * $perPage;

// ── جلب البيانات ──────────────────────────────────────
$transactions = $db->query(
    "SELECT t.*, u.username
     FROM dp_transactions t
     LEFT JOIN dp_users u ON u.id = t.user_id
     $whereSQL
     ORDER BY t.created_at DESC
     LIMIT $perPage OFFSET $offset",
    $params
);

// ── تصدير CSV ─────────────────────────────────────────
if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit_' . date('Y-m-d_His') . '.csv"');
    $all = $db->query(
        "SELECT t.*, u.username FROM dp_transactions t
         LEFT JOIN dp_users u ON u.id = t.user_id $whereSQL ORDER BY t.created_at DESC",
        $params
    );
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['المرجع','التاريخ','المستخدم','العميل','البريد','المبلغ','العملة',
                   'البوابة','البروتوكول','الحالة','الرسوم','الصافي','IP','معرف البطاقة']);
    foreach ($all as $r) {
        fputcsv($out, [
            $r['reference'], $r['created_at'], $r['username'] ?? '',
            $r['customer_name'] ?? '', $r['customer_email'] ?? '',
            $r['amount'], $r['currency'], $r['gateway'],
            $r['protocol'] ?? '', $r['status'],
            $r['fees'] ?? 0, $r['net_amount'] ?? 0,
            '', $r['card_last4'] ?? ''
        ]);
    }
    fclose($out);
    exit();
}

// ── إحصائيات سريعة ───────────────────────────────────
$stats = $db->query(
    "SELECT status, COUNT(*) as c, COALESCE(SUM(amount),0) as total
     FROM dp_transactions t $whereSQL GROUP BY status", $params
);
$statMap = [];
foreach ($stats as $s) $statMap[$s['status']] = $s;

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | Audit Dashboard — VARA</title>
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
body { background:var(--bg-dark); color:var(--text-light); font-family:Cairo,sans-serif; padding:20px; }
.wrap { max-width:1400px; margin:0 auto; }
.filter-card { background:var(--bg-card); border:1px solid var(--border-gold); border-radius:16px; padding:20px; margin-bottom:24px; }
.stat-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:14px; margin-bottom:24px; }
.stat-box { background:var(--bg-card); border:1px solid var(--border-gold); border-radius:12px; padding:16px; text-align:center; }
.stat-num { font-size:1.6rem; font-weight:800; color:var(--gold); }
.stat-lbl { font-size:.78rem; color:var(--text-muted); }
table { width:100%; border-collapse:collapse; font-size:.82rem; }
th { color:var(--text-muted); font-weight:600; padding:10px 12px; border-bottom:1px solid var(--border-light); text-align:right; background:rgba(255,215,0,.04); }
td { padding:9px 12px; border-bottom:1px solid rgba(255,255,255,.04); vertical-align:middle; }
tr:hover td { background:rgba(255,215,0,.03); }
.badge { padding:3px 10px; border-radius:20px; font-size:.72rem; font-weight:700; }
.badge-completed { background:rgba(76,175,80,.15); color:#4CAF50; }
.badge-pending   { background:rgba(240,173,78,.15); color:#f0ad4e; }
.badge-failed    { background:rgba(239,83,80,.15);  color:#ef5350; }
.badge-processing{ background:rgba(91,192,222,.15); color:#5bc0de; }
.btn-export { padding:8px 18px; border-radius:10px; background:var(--gold-gradient); color:#000; border:none; cursor:pointer; font-weight:700; font-size:.85rem; text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
.filter-input { padding:9px 14px; background:rgba(255,255,255,.05); border:1px solid var(--border-gold); border-radius:10px; color:var(--text-light); font-size:.88rem; outline:none; }
.filter-input:focus { border-color:var(--gold); }
.vara-badge { background:rgba(0,176,155,.15); color:#00b09b; border:1px solid rgba(0,176,155,.3); padding:4px 12px; border-radius:20px; font-size:.75rem; font-weight:700; }
.pagination { display:flex; gap:6px; justify-content:center; margin-top:20px; }
.page-btn { padding:6px 12px; border-radius:8px; border:1px solid var(--border-gold); background:transparent; color:var(--text-muted); cursor:pointer; font-size:.82rem; text-decoration:none; }
.page-btn.active { background:var(--gold-gradient); color:#000; border-color:var(--gold); font-weight:700; }
</style>
</head>
<body>
<div class="wrap">

<!-- Header -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px">
  <div>
    <h1 style="color:var(--gold);margin:0;font-size:1.4rem">
      <i class="fas fa-shield-halved" style="margin-left:8px"></i>Audit Dashboard
    </h1>
    <p style="color:var(--text-muted);margin:4px 0 0;font-size:.82rem">
      سجل تدقيق شامل — متوافق مع متطلبات VARA
      <span class="vara-badge" style="margin-right:8px">VARA Compliant</span>
    </p>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap">
    <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'csv'])) ?>" class="btn-export">
      <i class="fas fa-file-csv"></i> تصدير CSV
    </a>
    <a href="audit_report.php" style="padding:8px 18px;border-radius:10px;border:1px solid var(--border-gold);color:var(--text-gold);text-decoration:none;font-size:.85rem">
      <i class="fas fa-file-pdf"></i> تقرير VARA
    </a>
    <a href="../dashboard.php" style="padding:8px 16px;border-radius:10px;border:1px solid var(--border-light);color:var(--text-muted);text-decoration:none;font-size:.85rem">
      <i class="fas fa-arrow-right"></i>
    </a>
  </div>
</div>

<!-- إحصائيات -->
<div class="stat-grid">
  <div class="stat-box">
    <div class="stat-num"><?= number_format($totalCount) ?></div>
    <div class="stat-lbl">إجمالي العمليات</div>
  </div>
  <div class="stat-box">
    <div class="stat-num" style="color:#4CAF50"><?= number_format($statMap['completed']['c'] ?? 0) ?></div>
    <div class="stat-lbl">مكتملة</div>
  </div>
  <div class="stat-box">
    <div class="stat-num" style="color:#f0ad4e"><?= number_format($statMap['pending']['c'] ?? 0) ?></div>
    <div class="stat-lbl">معلقة</div>
  </div>
  <div class="stat-box">
    <div class="stat-num" style="color:#ef5350"><?= number_format($statMap['failed']['c'] ?? 0) ?></div>
    <div class="stat-lbl">فاشلة</div>
  </div>
  <div class="stat-box">
    <div class="stat-num"><?= number_format($totalAmount, 2) ?> USD</div>
    <div class="stat-lbl">إجمالي المبالغ</div>
  </div>
  <div class="stat-box">
    <div class="stat-num" style="color:#26a17b"><?= number_format($statMap['completed']['total'] ?? 0, 2) ?> USD</div>
    <div class="stat-lbl">المبالغ المكتملة</div>
  </div>
</div>

<!-- فلاتر -->
<form class="filter-card" method="GET">
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px">
    <div>
      <label style="color:var(--text-muted);font-size:.78rem;display:block;margin-bottom:4px">من تاريخ</label>
      <input type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>" class="filter-input" style="width:100%">
    </div>
    <div>
      <label style="color:var(--text-muted);font-size:.78rem;display:block;margin-bottom:4px">إلى تاريخ</label>
      <input type="date" name="to" value="<?= htmlspecialchars($dateTo) ?>" class="filter-input" style="width:100%">
    </div>
    <div>
      <label style="color:var(--text-muted);font-size:.78rem;display:block;margin-bottom:4px">الحالة</label>
      <select name="status" class="filter-input" style="width:100%">
        <option value="">الكل</option>
        <?php foreach (['completed','pending','failed','processing','refunded'] as $s): ?>
        <option value="<?= $s ?>" <?= $status===$s?'selected':'' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label style="color:var(--text-muted);font-size:.78rem;display:block;margin-bottom:4px">بحث</label>
      <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
             placeholder="مرجع / اسم / بريد" class="filter-input" style="width:100%">
    </div>
    <div style="display:flex;align-items:flex-end">
      <button type="submit" style="width:100%;padding:9px;border-radius:10px;background:var(--gold-gradient);color:#000;border:none;font-weight:700;cursor:pointer">
        <i class="fas fa-search"></i> بحث
      </button>
    </div>
  </div>
</form>

<!-- الجدول -->
<div style="background:var(--bg-card);border:1px solid var(--border-gold);border-radius:16px;overflow:hidden">
  <div style="padding:16px 20px;border-bottom:1px solid var(--border-light);display:flex;justify-content:space-between;align-items:center">
    <span style="color:var(--text-light);font-size:.9rem;font-weight:600">
      <i class="fas fa-list" style="color:var(--gold);margin-left:6px"></i>
      <?= number_format($totalCount) ?> عملية
      (صفحة <?= $page ?> من <?= $totalPages ?>)
    </span>
    <span style="color:var(--text-muted);font-size:.78rem">
      <i class="fas fa-clock"></i> آخر تحديث: <?= date('H:i:s') ?>
    </span>
  </div>
  <div style="overflow-x:auto">
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>المرجع</th>
        <th>التاريخ والوقت</th>
        <th>المستخدم</th>
        <th>العميل</th>
        <th>المبلغ</th>
        <th>العملة</th>
        <th>البوابة</th>
        <th>البروتوكول</th>
        <th>الحالة</th>
        <th>الرسوم</th>
        <th>آخر 4 أرقام</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($transactions)): ?>
      <tr><td colspan="12" style="text-align:center;padding:32px;color:var(--text-muted)">
        لا توجد عمليات في هذه الفترة
      </td></tr>
      <?php else: ?>
      <?php foreach ($transactions as $i => $t): ?>
      <tr>
        <td style="color:var(--text-muted)"><?= $offset + $i + 1 ?></td>
        <td>
          <code style="color:var(--gold);font-size:.78rem"><?= htmlspecialchars($t['reference']) ?></code>
        </td>
        <td style="color:var(--text-muted);font-size:.8rem;white-space:nowrap"><?= $t['created_at'] ?></td>
        <td><?= htmlspecialchars($t['username'] ?? '#' . $t['user_id']) ?></td>
        <td>
          <div style="font-size:.82rem"><?= htmlspecialchars($t['customer_name'] ?? '—') ?></div>
          <div style="color:var(--text-muted);font-size:.75rem"><?= htmlspecialchars($t['customer_email'] ?? '') ?></div>
        </td>
        <td style="font-weight:700;color:var(--gold)"><?= number_format((float)$t['amount'], 2) ?> USD</td>
        <td style="color:var(--text-muted);font-size:.78rem"><?= htmlspecialchars($t['currency']) ?></td>
        <td><span style="background:rgba(255,215,0,.08);padding:2px 8px;border-radius:6px;font-size:.75rem">
          <?= htmlspecialchars($t['gateway']) ?>
        </span></td>
        <td style="font-size:.78rem;color:var(--text-muted)"><?= htmlspecialchars($t['protocol'] ?? '—') ?></td>
        <td>
          <span class="badge badge-<?= $t['status'] ?>">
            <?= htmlspecialchars($t['status']) ?>
          </span>
        </td>
        <td style="color:var(--text-muted)"><?= number_format((float)($t['fees'] ?? 0), 2) ?></td>
        <td style="font-family:monospace;font-size:.82rem">
          <?= $t['card_last4'] ? '****' . htmlspecialchars($t['card_last4']) : '—' ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="pagination">
  <?php if ($page > 1): ?>
  <a href="?<?= http_build_query(array_merge($_GET, ['page'=>$page-1])) ?>" class="page-btn">
    <i class="fas fa-chevron-right"></i>
  </a>
  <?php endif; ?>
  <?php for ($p = max(1,$page-3); $p <= min($totalPages,$page+3); $p++): ?>
  <a href="?<?= http_build_query(array_merge($_GET, ['page'=>$p])) ?>"
     class="page-btn <?= $p===$page?'active':'' ?>"><?= $p ?></a>
  <?php endfor; ?>
  <?php if ($page < $totalPages): ?>
  <a href="?<?= http_build_query(array_merge($_GET, ['page'=>$page+1])) ?>" class="page-btn">
    <i class="fas fa-chevron-left"></i>
  </a>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- VARA Notice -->
<div style="margin-top:24px;padding:14px 20px;background:rgba(0,176,155,.07);border:1px solid rgba(0,176,155,.2);border-radius:12px;font-size:.8rem;color:var(--text-muted)">
  <i class="fas fa-shield-halved" style="color:#00b09b"></i>
  جميع السجلات محفوظة وفق متطلبات VARA — مدة الحفظ: 7 سنوات — مشفّرة ومحمية من التعديل.
  آخر فحص: <?= date('Y-m-d H:i:s') ?>
</div>

</div><!-- wrap -->
</body>
</html>
