<?php
/**
 * DI PARMA | AML Transaction Monitoring Report — VARA Compliant
 * تقارير مكافحة غسيل الأموال التلقائية
 */
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/crypto_schema.php';

requireAdmin();
$db = db();

$dateFrom = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo   = $_GET['to']   ?? date('Y-m-d');
$export   = $_GET['export'] ?? '';

// ── 1. إحصائيات AML العامة ───────────────────────────
$totalTxns = $db->query(
    "SELECT COUNT(*) as c, COALESCE(SUM(amount),0) as vol
     FROM dp_transactions
     WHERE created_at BETWEEN ? AND ?",
    [$dateFrom.' 00:00:00', $dateTo.' 23:59:59']
)[0] ?? [];

// ── 2. العمليات عالية المخاطر (> $10,000) ────────────
$highRisk = $db->query(
    "SELECT t.*, u.username FROM dp_transactions t
     LEFT JOIN dp_users u ON u.id = t.user_id
     WHERE t.amount > 10000
       AND t.created_at BETWEEN ? AND ?
     ORDER BY t.amount DESC LIMIT 100",
    [$dateFrom.' 00:00:00', $dateTo.' 23:59:59']
);

// ── 3. SAR Candidates (مشبوهة) ───────────────────────
$sarCandidates = $db->query(
    "SELECT t.*, u.username FROM dp_transactions t
     LEFT JOIN dp_users u ON u.id = t.user_id
     WHERE t.status = 'failed'
       AND t.amount > 5000
       AND t.created_at BETWEEN ? AND ?
     ORDER BY t.created_at DESC LIMIT 50",
    [$dateFrom.' 00:00:00', $dateTo.' 23:59:59']
);

// ── 4. عمليات متكررة (Velocity) ──────────────────────
$velocity = $db->query(
    "SELECT user_id, COUNT(*) as cnt, SUM(amount) as total,
            MIN(created_at) as first_txn, MAX(created_at) as last_txn
     FROM dp_transactions
     WHERE created_at BETWEEN ? AND ?
       AND status IN ('completed','processing')
     GROUP BY user_id
     HAVING cnt >= 5
     ORDER BY cnt DESC LIMIT 20",
    [$dateFrom.' 00:00:00', $dateTo.' 23:59:59']
);

// ── 5. العملاء بدون KYC ───────────────────────────────
$noKyc = $db->query(
    "SELECT DISTINCT t.user_id, t.customer_name, t.customer_email,
            COUNT(*) as txn_count, SUM(t.amount) as total
     FROM dp_transactions t
     LEFT JOIN dp_kyc_verifications k ON k.user_id = t.user_id
     WHERE k.id IS NULL
       AND t.created_at BETWEEN ? AND ?
     GROUP BY t.user_id
     ORDER BY total DESC LIMIT 20",
    [$dateFrom.' 00:00:00', $dateTo.' 23:59:59']
);

// ── 6. توزيع البوابات ─────────────────────────────────
$byGateway = $db->query(
    "SELECT gateway, COUNT(*) as cnt, SUM(amount) as total
     FROM dp_transactions
     WHERE created_at BETWEEN ? AND ?
     GROUP BY gateway ORDER BY total DESC",
    [$dateFrom.' 00:00:00', $dateTo.' 23:59:59']
);

// ── تصدير CSV ─────────────────────────────────────────
if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="aml_report_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['=== AML Report — DI PARMA === تاريخ: ' . date('Y-m-d')]);
    fputcsv($out, []);
    fputcsv($out, ['قسم 1: العمليات عالية المخاطر (> $10,000)']);
    fputcsv($out, ['المرجع','التاريخ','المستخدم','العميل','المبلغ','العملة','البوابة','الحالة']);
    foreach ($highRisk as $r) {
        fputcsv($out, [$r['reference'],$r['created_at'],$r['username']??'',$r['customer_name']??'',$r['amount'],$r['currency'],$r['gateway'],$r['status']]);
    }
    fputcsv($out, []);
    fputcsv($out, ['قسم 2: SAR Candidates']);
    fputcsv($out, ['المرجع','التاريخ','المستخدم','المبلغ','البوابة','الحالة']);
    foreach ($sarCandidates as $r) {
        fputcsv($out, [$r['reference'],$r['created_at'],$r['username']??'',$r['amount'],$r['gateway'],$r['status']]);
    }
    fclose($out);
    exit();
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | AML Report — VARA</title>
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
body { background:var(--bg-dark); color:var(--text-light); font-family:Cairo,sans-serif; padding:20px; }
.wrap { max-width:1300px; margin:0 auto; }
.section { background:var(--bg-card); border:1px solid var(--border-gold); border-radius:16px; padding:24px; margin-bottom:24px; }
.section-title { color:var(--gold); font-size:1rem; font-weight:700; margin:0 0 16px; border-bottom:1px solid var(--border-light); padding-bottom:10px; }
table { width:100%; border-collapse:collapse; font-size:.82rem; }
th { color:var(--text-muted); padding:9px 12px; border-bottom:1px solid var(--border-light); text-align:right; background:rgba(255,215,0,.04); }
td { padding:8px 12px; border-bottom:1px solid rgba(255,255,255,.04); }
.risk-high { color:#ef5350; font-weight:700; }
.risk-med  { color:#f0ad4e; font-weight:700; }
.risk-low  { color:#4CAF50; }
.stat-row { display:flex; gap:20px; flex-wrap:wrap; margin-bottom:20px; }
.stat-item { flex:1; min-width:150px; background:rgba(255,255,255,.03); border-radius:12px; padding:16px; text-align:center; border:1px solid var(--border-light); }
</style>
</head>
<body>
<div class="wrap">

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px">
  <div>
    <h1 style="color:var(--gold);margin:0;font-size:1.4rem">
      <i class="fas fa-file-shield" style="margin-left:8px"></i>AML Transaction Monitoring Report
    </h1>
    <p style="color:var(--text-muted);margin:4px 0 0;font-size:.82rem">
      <?= $dateFrom ?> — <?= $dateTo ?>
      <span style="background:rgba(0,176,155,.15);color:#00b09b;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700;margin-right:8px">VARA AML</span>
    </p>
  </div>
  <div style="display:flex;gap:10px">
    <a href="?from=<?= $dateFrom ?>&to=<?= $dateTo ?>&export=csv" style="padding:8px 18px;border-radius:10px;background:var(--gold-gradient);color:#000;font-weight:700;font-size:.85rem;text-decoration:none">
      <i class="fas fa-download"></i> تصدير CSV
    </a>
    <a href="audit_dashboard.php" style="padding:8px 16px;border-radius:10px;border:1px solid var(--border-light);color:var(--text-muted);text-decoration:none;font-size:.85rem">
      Audit Dashboard
    </a>
  </div>
</div>

<!-- فلتر التاريخ -->
<form style="background:var(--bg-card);border:1px solid var(--border-gold);border-radius:12px;padding:16px 20px;margin-bottom:24px;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
  <div><label style="color:var(--text-muted);font-size:.78rem;display:block;margin-bottom:4px">من</label>
    <input type="date" name="from" value="<?= $dateFrom ?>" style="padding:8px 12px;background:rgba(255,255,255,.05);border:1px solid var(--border-gold);border-radius:8px;color:var(--text-light)"></div>
  <div><label style="color:var(--text-muted);font-size:.78rem;display:block;margin-bottom:4px">إلى</label>
    <input type="date" name="to" value="<?= $dateTo ?>" style="padding:8px 12px;background:rgba(255,255,255,.05);border:1px solid var(--border-gold);border-radius:8px;color:var(--text-light)"></div>
  <button type="submit" style="padding:8px 18px;border-radius:8px;background:var(--gold-gradient);color:#000;border:none;font-weight:700;cursor:pointer">تطبيق</button>
</form>

<!-- ملخص -->
<div class="stat-row">
  <div class="stat-item">
    <div style="font-size:1.6rem;font-weight:800;color:var(--gold)"><?= number_format($totalTxns['c'] ?? 0) ?></div>
    <div style="color:var(--text-muted);font-size:.78rem">إجمالي العمليات</div>
  </div>
  <div class="stat-item">
    <div style="font-size:1.6rem;font-weight:800;color:var(--gold)"><?= '$' . number_format($totalTxns['vol'] ?? 0, 0) ?> USD</div>
    <div style="color:var(--text-muted);font-size:.78rem">حجم التداول</div>
  </div>
  <div class="stat-item">
    <div style="font-size:1.6rem;font-weight:800;color:#ef5350"><?= count($highRisk) ?></div>
    <div style="color:var(--text-muted);font-size:.78rem">عمليات عالية المخاطر</div>
  </div>
  <div class="stat-item">
    <div style="font-size:1.6rem;font-weight:800;color:#f0ad4e"><?= count($sarCandidates) ?></div>
    <div style="color:var(--text-muted);font-size:.78rem">SAR Candidates</div>
  </div>
  <div class="stat-item">
    <div style="font-size:1.6rem;font-weight:800;color:#5bc0de"><?= count($noKyc) ?></div>
    <div style="color:var(--text-muted);font-size:.78rem">بدون KYC</div>
  </div>
</div>

<!-- 1. عمليات عالية المخاطر -->
<div class="section">
  <h3 class="section-title">
    <i class="fas fa-triangle-exclamation" style="color:#ef5350;margin-left:6px"></i>
    1. العمليات عالية المخاطر (> $10,000) — <?= count($highRisk) ?> عملية
  </h3>
  <?php if (empty($highRisk)): ?>
  <p style="color:var(--text-muted)">لا توجد عمليات عالية المخاطر في هذه الفترة ✓</p>
  <?php else: ?>
  <table>
    <thead><tr><th>المرجع</th><th>التاريخ</th><th>العميل</th><th>المبلغ</th><th>البوابة</th><th>الحالة</th><th>مستوى الخطر</th></tr></thead>
    <tbody>
    <?php foreach ($highRisk as $r): ?>
    <tr>
      <td><code style="color:var(--gold);font-size:.78rem"><?= htmlspecialchars($r['reference']) ?></code></td>
      <td style="color:var(--text-muted);font-size:.8rem"><?= $r['created_at'] ?></td>
      <td><?= htmlspecialchars($r['customer_name'] ?? $r['username'] ?? '—') ?></td>
      <td class="risk-high">$<?= number_format((float)$r['amount'], 2) ?> USD</td>
      <td><?= htmlspecialchars($r['gateway']) ?></td>
      <td><span style="background:<?= $r['status']==='completed'?'rgba(76,175,80,.15)':'rgba(239,83,80,.15)' ?>;color:<?= $r['status']==='completed'?'#4CAF50':'#ef5350' ?>;padding:2px 8px;border-radius:10px;font-size:.72rem"><?= $r['status'] ?></span></td>
      <td class="<?= $r['amount'] > 50000 ? 'risk-high' : 'risk-med' ?>">
        <?= $r['amount'] > 50000 ? 'HIGH' : 'MEDIUM' ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- 2. SAR Candidates -->
<div class="section">
  <h3 class="section-title">
    <i class="fas fa-flag" style="color:#f0ad4e;margin-left:6px"></i>
    2. SAR Candidates — عمليات مشبوهة (<?= count($sarCandidates) ?>)
  </h3>
  <?php if (empty($sarCandidates)): ?>
  <p style="color:#4CAF50"><i class="fas fa-check-circle"></i> لا توجد عمليات مشبوهة — النظام نظيف ✓</p>
  <?php else: ?>
  <p style="color:#f0ad4e;font-size:.85rem;margin-bottom:12px">
    <i class="fas fa-info-circle"></i>
    هذه العمليات تستوجب مراجعة يدوية وقد تحتاج لإرسال SAR لـ VARA
  </p>
  <table>
    <thead><tr><th>المرجع</th><th>التاريخ</th><th>المستخدم</th><th>المبلغ</th><th>البوابة</th><th>سبب الاشتباه</th></tr></thead>
    <tbody>
    <?php foreach ($sarCandidates as $r): ?>
    <tr>
      <td><code style="color:var(--gold);font-size:.78rem"><?= htmlspecialchars($r['reference']) ?></code></td>
      <td style="color:var(--text-muted);font-size:.8rem"><?= $r['created_at'] ?></td>
      <td><?= htmlspecialchars($r['username'] ?? '#' . $r['user_id']) ?></td>
      <td class="risk-med">$<?= number_format((float)$r['amount'], 2) ?> USD</td>
      <td><?= htmlspecialchars($r['gateway']) ?></td>
      <td style="color:#f0ad4e;font-size:.78rem">عملية فاشلة بمبلغ > $5,000</td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- 3. Velocity Check -->
<div class="section">
  <h3 class="section-title">
    <i class="fas fa-gauge-high" style="color:#5bc0de;margin-left:6px"></i>
    3. فحص Velocity — مستخدمون بعمليات متكررة
  </h3>
  <?php if (empty($velocity)): ?>
  <p style="color:#4CAF50"><i class="fas fa-check-circle"></i> لا توجد أنماط غير طبيعية ✓</p>
  <?php else: ?>
  <table>
    <thead><tr><th>المستخدم</th><th>عدد العمليات</th><th>الإجمالي</th><th>أول عملية</th><th>آخر عملية</th><th>التقييم</th></tr></thead>
    <tbody>
    <?php foreach ($velocity as $v): ?>
    <tr>
      <td>#<?= $v['user_id'] ?></td>
      <td style="color:var(--gold);font-weight:700"><?= number_format($v['cnt']) ?></td>
      <td><?= number_format($v['total'], 2) ?></td>
      <td style="font-size:.78rem;color:var(--text-muted)"><?= $v['first_txn'] ?></td>
      <td style="font-size:.78rem;color:var(--text-muted)"><?= $v['last_txn'] ?></td>
      <td class="<?= $v['cnt'] > 20 ? 'risk-high' : 'risk-med' ?>">
        <?= $v['cnt'] > 20 ? '⚠ راجع' : 'طبيعي' ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- 4. توزيع البوابات -->
<div class="section">
  <h3 class="section-title">
    <i class="fas fa-chart-pie" style="color:var(--gold);margin-left:6px"></i>
    4. توزيع حجم التداول بالبوابات
  </h3>
  <table>
    <thead><tr><th>البوابة</th><th>عدد العمليات</th><th>الحجم الكلي</th><th>النسبة</th></tr></thead>
    <tbody>
    <?php
    $grandTotal = array_sum(array_column($byGateway, 'total')) ?: 1;
    foreach ($byGateway as $gw):
        $pct = round(($gw['total'] / $grandTotal) * 100, 1);
    ?>
    <tr>
      <td style="font-weight:600"><?= htmlspecialchars($gw['gateway']) ?></td>
      <td><?= number_format($gw['cnt']) ?></td>
      <td style="color:var(--gold)"><?= number_format($gw['total'], 2) ?></td>
      <td>
        <div style="display:flex;align-items:center;gap:8px">
          <div style="flex:1;height:6px;background:rgba(255,255,255,.08);border-radius:99px;overflow:hidden">
            <div style="width:<?= $pct ?>%;height:100%;background:var(--gold-gradient);border-radius:99px"></div>
          </div>
          <span style="font-size:.8rem;color:var(--text-muted)"><?= $pct ?>%</span>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- VARA Footer -->
<div style="padding:14px 20px;background:rgba(0,176,155,.07);border:1px solid rgba(0,176,155,.2);border-radius:12px;font-size:.8rem;color:var(--text-muted)">
  <strong style="color:#00b09b">VARA AML Compliance Note:</strong>
  هذا التقرير مولَّد تلقائياً وفق متطلبات VARA للمراقبة المستمرة للمعاملات.
  العمليات عالية المخاطر (> $10,000) تستوجب مراجعة يدوية والإبلاغ عنها خلال 30 يوم عند الاشتباه.
  تاريخ التقرير: <?= date('Y-m-d H:i:s') ?> — مُعدّ بواسطة DI PARMA Compliance System
</div>

</div>
</body>
</html>
