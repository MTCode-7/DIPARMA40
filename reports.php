<?php
/**
 * DI PARMA | التقارير المالية الشاملة
 */
require_once __DIR__ . "/includes/auth_check.php";
require_once __DIR__ . "/includes/database.php";
require_once __DIR__ . "/includes/functions.php";
$db = db();
$userId = intval($_SESSION["user_id"] ?? 0);
$currentUser = $db->find("users", ["id" => $userId]);
$isAdmin = $currentUser && strtolower($currentUser["role"] ?? "") === "admin";
if (!$isAdmin) { header("Location: dashboard.php"); exit(); }
$dateFrom = $_GET["date_from"] ?? date("Y-m-01");
$dateTo   = $_GET["date_to"]   ?? date("Y-m-d");
$gwFilter  = $_GET["gateway"]  ?? "";
$curFilter = $_GET["currency"] ?? "";
$stFilter  = $_GET["status"]   ?? "";
$where  = "WHERE created_at BETWEEN ? AND ?";
$params = [$dateFrom . " 00:00:00", $dateTo . " 23:59:59"];
if ($gwFilter)  { $where .= " AND gateway=?";  $params[] = $gwFilter; }
if ($curFilter) { $where .= " AND currency=?"; $params[] = $curFilter; }
if ($stFilter)  { $where .= " AND status=?";   $params[] = $stFilter; }
if (isset($_GET["export"]) && $_GET["export"] === "csv") {
    $rows = $db->query("SELECT reference,gateway,amount,currency,status,security_mode,customer_name,customer_email,fees,net_amount,created_at FROM dp_transactions $where ORDER BY created_at DESC", $params);
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=report_" . date("Ymd") . ".csv");
    echo "\xEF\xBB\xBF";
    echo "Reference,Gateway,Amount,Currency,Status,Mode,Name,Email,Fees,Net,Date\n";
    foreach ($rows as $r) {
        echo implode(",", array_map(fn($v) => "\"" . str_replace("\"", "\"\"", $v ?? "") . "\"", [
            $r["reference"],$r["gateway"],$r["amount"],$r["currency"],$r["status"],
            $r["security_mode"],$r["customer_name"],$r["customer_email"],$r["fees"],$r["net_amount"],$r["created_at"]
        ])) . "\n";
    }
    exit();
}
$s = $db->query("SELECT COUNT(*) as total,
    SUM(status=\"completed\") as completed, SUM(status=\"pending\") as pending,
    SUM(status=\"failed\") as failed, SUM(status=\"processing\") as processing,
    SUM(amount) as total_amount, SUM(CASE WHEN status=\"completed\" THEN amount ELSE 0 END) as revenue,
    SUM(fees) as total_fees, SUM(net_amount) as total_net,
    AVG(amount) as avg_amount, MAX(amount) as max_amount, SUM(amount_usdt) as total_usdt
    FROM dp_transactions $where", $params)[0] ?? [];
$gwReport  = $db->query("SELECT gateway, COUNT(*) as cnt, SUM(amount) as total,
    SUM(CASE WHEN status=\"completed\" THEN amount ELSE 0 END) as revenue,
    SUM(fees) as fees, AVG(amount) as avg,
    SUM(status=\"completed\") as ok, SUM(status=\"failed\") as fail
    FROM dp_transactions $where GROUP BY gateway ORDER BY total DESC LIMIT 20", $params) ?: [];
$curReport = $db->query("SELECT currency, COUNT(*) as cnt, SUM(amount) as total,
    SUM(CASE WHEN status=\"completed\" THEN amount ELSE 0 END) as revenue
    FROM dp_transactions $where GROUP BY currency ORDER BY total DESC", $params) ?: [];
$daily = $db->query("SELECT DATE(created_at) as day, COUNT(*) as cnt, SUM(amount) as total,
    SUM(CASE WHEN status=\"completed\" THEN amount ELSE 0 END) as revenue
    FROM dp_transactions $where GROUP BY DATE(created_at) ORDER BY day DESC LIMIT 30", $params) ?: [];
$modeRpt = $db->query("SELECT security_mode, COUNT(*) as cnt,
    SUM(status=\"completed\") as ok, SUM(amount) as total
    FROM dp_transactions $where GROUP BY security_mode", $params) ?: [];
$recent  = $db->query("SELECT reference,gateway,amount,currency,status,security_mode,
    customer_name,created_at,fees FROM dp_transactions $where ORDER BY created_at DESC LIMIT 50", $params) ?: [];
$gateways  = array_column($db->query("SELECT DISTINCT gateway FROM dp_transactions ORDER BY gateway") ?: [], "gateway");
$currencies= array_column($db->query("SELECT DISTINCT currency FROM dp_transactions ORDER BY currency") ?: [], "currency");
$chartLabels = json_encode(array_reverse(array_column($daily,"day")));
$chartRevenue= json_encode(array_map("floatval", array_reverse(array_column($daily,"revenue"))));
$chartVol    = json_encode(array_map("floatval", array_reverse(array_column($daily,"total"))));
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | التقارير المالية</title>
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
:root{--gold:#ffd700;--bg:#0a0a0a;--card:rgba(255,255,255,.04);--border:rgba(255,215,0,.2);}
body{background:var(--bg);color:#e0e0e0;font-family:Cairo,sans-serif;margin:0;padding:0;}
.hdr{background:rgba(0,0,0,.9);border-bottom:1px solid var(--border);padding:16px 28px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;}
.hdr h1{color:var(--gold);font-size:1.3rem;margin:0;display:flex;align-items:center;gap:10px;}
.wrap{max-width:1500px;margin:0 auto;padding:24px;}
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:24px;}
.kpi{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:18px;text-align:center;}
.kpi-val{font-size:1.7rem;font-weight:800;color:var(--gold);margin:6px 0;}
.kpi-lbl{font-size:.75rem;color:#777;}
.kpi-sub{font-size:.78rem;color:#aaa;margin-top:4px;}
.section{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:22px;margin-bottom:20px;}
.sec-hdr{color:var(--gold);font-size:1rem;font-weight:700;margin:0 0 16px;display:flex;align-items:center;gap:10px;}
.tbl{width:100%;border-collapse:collapse;font-size:.85rem;}
.tbl th{background:rgba(255,215,0,.08);color:var(--gold);padding:10px 14px;text-align:right;border-bottom:1px solid var(--border);}
.tbl td{padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.05);color:#ddd;vertical-align:middle;}
.tbl tr:hover td{background:rgba(255,255,255,.03);}
.badge{padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:600;}
.b-ok{background:rgba(76,175,80,.15);color:#4CAF50;border:1px solid #4CAF5040;}
.b-pen{background:rgba(240,173,78,.15);color:#f0ad4e;border:1px solid #f0ad4e40;}
.b-fail{background:rgba(239,83,80,.15);color:#ef5350;border:1px solid #ef535040;}
.b-proc{background:rgba(91,192,222,.15);color:#5bc0de;border:1px solid #5bc0de40;}
.filters{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:16px;margin-bottom:20px;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;}
.filters label{font-size:.78rem;color:#888;display:block;margin-bottom:4px;}
.filters input,.filters select{padding:8px 12px;background:rgba(255,255,255,.06);border:1.5px solid var(--border);border-radius:9px;color:#fff;font-family:Cairo,sans-serif;font-size:.85rem;outline:none;}
.filters input:focus,.filters select:focus{border-color:var(--gold);}
.btn{padding:9px 18px;border-radius:10px;border:none;cursor:pointer;font-family:Cairo,sans-serif;font-weight:600;font-size:.85rem;display:inline-flex;align-items:center;gap:7px;text-decoration:none;transition:all .2s;}
.btn-gold{background:linear-gradient(135deg,#ffd700,#ffb700);color:#000;}
.btn-csv{background:rgba(76,175,80,.15);border:1px solid #4CAF50;color:#4CAF50;}
.btn-out{background:transparent;border:1.5px solid var(--border);color:#aaa;}
.chart-wrap{position:relative;height:280px;}
.progress-bar{height:8px;border-radius:4px;background:rgba(255,215,0,.1);overflow:hidden;margin-top:4px;}
.progress-fill{height:100%;border-radius:4px;background:var(--gold);}
.empty{text-align:center;color:#555;padding:30px;}
@media(max-width:768px){.kpi-grid{grid-template-columns:1fr 1fr;}.filters{flex-direction:column;}.hdr{flex-direction:column;align-items:flex-start;}}
.print-hide{}
@media print{.filters,.btn,.hdr a,.print-hide{display:none!important;}.wrap{padding:0;}.section{page-break-inside:avoid;}}
</style>
</head>
<body>
<div class="hdr">
  <h1><i class="fas fa-chart-bar"></i> التقارير المالية الشاملة</h1>
  <div style="display:flex;gap:8px;align-items:center">
    <a href="<?= htmlspecialchars("reports.php?" . http_build_query(array_merge($_GET, ["export"=>"csv"]))) ?>" class="btn btn-csv print-hide">
      <i class="fas fa-file-csv"></i> تصدير CSV
    </a>
    <button onclick="window.print()" class="btn btn-out print-hide"><i class="fas fa-print"></i> طباعة</button>
    <a href="dashboard.php" class="btn btn-out print-hide"><i class="fas fa-home"></i> الرئيسية</a>
  </div>
</div>
<div class="wrap">
<!-- Filters -->
<form method="GET" class="filters print-hide">
<div><label>من تاريخ</label><input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>"></div>
<div><label>إلى تاريخ</label><input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>"></div>
<div><label>البوابة</label>
<select name="gateway"><option value="">كل البوابات</option>
<?php foreach($gateways as $g): ?><option value="<?= htmlspecialchars($g) ?>" <?= $gwFilter===$g?"selected":"" ?>><?= htmlspecialchars(strtoupper($g)) ?></option><?php endforeach; ?>
</select></div>
<div><label>العملة</label>
<select name="currency"><option value="">كل العملات</option>
<?php foreach($currencies as $c): ?><option value="<?= htmlspecialchars($c) ?>" <?= $curFilter===$c?"selected":"" ?>><?= htmlspecialchars($c) ?></option><?php endforeach; ?>
</select></div>
<div><label>الحالة</label>
<select name="status"><option value="">الكل</option>
<option value="completed" <?= $stFilter==="completed"?"selected":"" ?>>مكتملة</option>
<option value="pending"   <?= $stFilter==="pending"  ?"selected":"" ?>>معلقة</option>
<option value="failed"    <?= $stFilter==="failed"   ?"selected":"" ?>>فاشلة</option>
<option value="processing"<?= $stFilter==="processing"?"selected":"" ?>>جاري</option>
</select></div>
<div style="display:flex;gap:8px;align-items:flex-end">
<button type="submit" class="btn btn-gold"><i class="fas fa-filter"></i> تطبيق</button>
<a href="reports.php" class="btn btn-out"><i class="fas fa-undo"></i> إعادة تعيين</a>
</div>
</form><!-- KPIs -->
<div class="kpi-grid">
<div class="kpi"><div class="kpi-lbl">إجمالي المعاملات</div><div class="kpi-val"><?= number_format($s["total"]??0) ?></div>
<div class="kpi-sub">✅ <?= number_format($s["completed"]??0) ?> مكتملة</div></div>
<div class="kpi"><div class="kpi-lbl">إجمالي المبالغ</div><div class="kpi-val"><?= number_format($s["total_amount"]??0,2) ?></div>
<div class="kpi-sub">متوسط <?= number_format($s["avg_amount"]??0,2) ?></div></div>
<div class="kpi"><div class="kpi-lbl">الإيرادات المكتملة</div><div class="kpi-val" style="color:#4CAF50"><?= number_format($s["revenue"]??0,2) ?></div>
<div class="kpi-sub">صافي <?= number_format($s["total_net"]??0,2) ?></div></div>
<div class="kpi"><div class="kpi-lbl">إجمالي الرسوم</div><div class="kpi-val" style="color:#f0ad4e"><?= number_format($s["total_fees"]??0,2) ?></div></div>
<div class="kpi"><div class="kpi-lbl">USDT المرسل</div><div class="kpi-val" style="color:#9fe870"><?= number_format($s["total_usdt"]??0,4) ?></div></div>
<div class="kpi"><div class="kpi-lbl">معلقة / فاشلة</div>
<div class="kpi-val" style="color:#ef5350"><?= number_format(($s["pending"]??0)+($s["failed"]??0)) ?></div>
<div class="kpi-sub">⏳ <?= $s["pending"]??0 ?> | ❌ <?= $s["failed"]??0 ?></div></div>
</div><!-- Chart يومي + Security Mode -->
<div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:20px">
<div class="section"><h3 class="sec-hdr"><i class="fas fa-chart-line"></i> الإيرادات اليومية (آخر 30 يوم)</h3>
<div class="chart-wrap"><canvas id="dailyChart"></canvas></div></div>
<div class="section"><h3 class="sec-hdr"><i class="fas fa-shield-alt"></i> Security Mode</h3>
<div style="margin-top:10px">
<?php foreach($modeRpt as $mr):
  $tot = array_sum(array_column($modeRpt,"cnt"));
  $pct = $tot > 0 ? round(($mr["cnt"]/$tot)*100) : 0;
  $col = strtoupper($mr["security_mode"]??"")!=="3D" ? "#5bc0de" : "var(--gold)";
?>
<div style="margin-bottom:16px">
<div style="display:flex;justify-content:space-between;margin-bottom:4px">
<span style="color:<?= $col ?>;font-weight:700"><?= htmlspecialchars($mr["security_mode"]??"N/A") ?></span>
<span style="color:#aaa;font-size:.8rem"><?= $mr["cnt"] ?> (<?= $pct ?>%)</span>
</div>
<div class="progress-bar"><div class="progress-fill" style="width:<?= $pct ?>%;background:<?= $col ?>"></div></div>
<div style="font-size:.75rem;color:#666;margin-top:3px">✅ <?= $mr["ok"]??0 ?> ناجح — إجمالي <?= number_format($mr["total"]??0,2) ?></div>
</div>
<?php endforeach; ?>
<?php if(empty($modeRpt)): ?><div class="empty">لا بيانات</div><?php endif; ?>
</div></div>
</div><!-- جدول البوابات -->
<div class="section"><h3 class="sec-hdr"><i class="fas fa-server"></i> تقرير بوابات الدفع</h3>
<?php if(empty($gwReport)): ?><div class="empty">لا بيانات</div>
<?php else: ?>
<div style="overflow-x:auto"><table class="tbl">
<thead><tr><th>البوابة</th><th>المعاملات</th><th>إجمالي</th><th>إيرادات</th><th>رسوم</th><th>متوسط</th><th>ناجح</th><th>فاشل</th><th>معدل النجاح</th></tr></thead>
<tbody>
<?php foreach($gwReport as $g):
  $rate = ($g["cnt"]??0)>0 ? round((($g["ok"]??0)/($g["cnt"]??1))*100) : 0;
  $rateColor = $rate>=80?"#4CAF50":($rate>=50?"#f0ad4e":"#ef5350");
?>
<tr>
<td><span style="color:var(--gold);font-weight:700"><?= htmlspecialchars(strtoupper($g["gateway"]??"")) ?></span></td>
<td><?= number_format($g["cnt"]??0) ?></td>
<td style="color:var(--gold)"><?= number_format($g["total"]??0,2) ?></td>
<td style="color:#4CAF50"><?= number_format($g["revenue"]??0,2) ?></td>
<td style="color:#f0ad4e"><?= number_format($g["fees"]??0,2) ?></td>
<td><?= number_format($g["avg"]??0,2) ?></td>
<td style="color:#4CAF50"><?= $g["ok"]??0 ?></td>
<td style="color:#ef5350"><?= $g["fail"]??0 ?></td>
<td><span style="color:<?= $rateColor ?>;font-weight:700"><?= $rate ?>%</span></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
</div>

<!-- جدول العملات -->
<div class="section"><h3 class="sec-hdr"><i class="fas fa-coins"></i> تقرير العملات</h3>
<?php if(empty($curReport)): ?><div class="empty">لا بيانات</div>
<?php else: ?>
<div style="overflow-x:auto"><table class="tbl">
<thead><tr><th>العملة</th><th>المعاملات</th><th>إجمالي</th><th>إيرادات</th></tr></thead>
<tbody>
<?php foreach($curReport as $c): ?>
<tr>
<td><span style="color:var(--gold);font-weight:700"><?= htmlspecialchars($c["currency"]??"") ?></span></td>
<td><?= number_format($c["cnt"]??0) ?></td>
<td style="color:var(--gold)"><?= number_format($c["total"]??0,2) ?></td>
<td style="color:#4CAF50"><?= number_format($c["revenue"]??0,2) ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
</div>

<!-- جدول يومي -->
<div class="section"><h3 class="sec-hdr"><i class="fas fa-calendar-alt"></i> التقرير اليومي (آخر 30 يوم)</h3>
<?php if(empty($daily)): ?><div class="empty">لا بيانات</div>
<?php else: ?>
<div style="overflow-x:auto"><table class="tbl">
<thead><tr><th>التاريخ</th><th>المعاملات</th><th>إجمالي</th><th>إيرادات</th></tr></thead>
<tbody>
<?php foreach($daily as $d): ?>
<tr>
<td style="color:#aaa"><?= htmlspecialchars($d["day"]??"") ?></td>
<td><?= number_format($d["cnt"]??0) ?></td>
<td style="color:var(--gold)"><?= number_format($d["total"]??0,2) ?></td>
<td style="color:#4CAF50"><?= number_format($d["revenue"]??0,2) ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
</div><!-- آخر المعاملات -->
<div class="section"><h3 class="sec-hdr"><i class="fas fa-list"></i> آخر 50 معاملة</h3>
<?php if(empty($recent)): ?><div class="empty">لا بيانات</div>
<?php else: ?>
<div style="overflow-x:auto"><table class="tbl">
<thead><tr><th>#</th><th>المرجع</th><th>البوابة</th><th>المبلغ</th><th>العملة</th><th>الحالة</th><th>Mode</th><th>العميل</th><th>الرسوم</th><th>التاريخ</th></tr></thead>
<tbody>
<?php foreach($recent as $i=>$r):
  $badge = match($r["status"]??""){ "completed"=>"b-ok","pending"=>"b-pen","failed"=>"b-fail",default=>"b-proc" };
  $modeC = strtoupper($r["security_mode"]??"")!=="3D" ? "#5bc0de" : "var(--gold)";
?>
<tr>
<td style="color:#555"><?= $i+1 ?></td>
<td style="font-family:monospace;font-size:.75rem;color:#aaa"><?= htmlspecialchars(substr($r["reference"]??"",0,18)) ?></td>
<td style="color:var(--gold);font-weight:600"><?= htmlspecialchars(strtoupper($r["gateway"]??"")) ?></td>
<td style="font-weight:700"><?= number_format($r["amount"]??0,2) ?></td>
<td style="color:#aaa"><?= htmlspecialchars($r["currency"]??"") ?></td>
<td><span class="badge <?= $badge ?>"><?= htmlspecialchars($r["status"]??"") ?></span></td>
<td><span style="color:<?= $modeC ?>;font-weight:600;font-size:.75rem"><?= htmlspecialchars($r["security_mode"]??"N/A") ?></span></td>
<td style="font-size:.8rem;color:#aaa"><?= htmlspecialchars(substr($r["customer_name"]??"Guest",0,20)) ?></td>
<td style="color:#f0ad4e;font-size:.8rem"><?= number_format($r["fees"]??0,2) ?></td>
<td style="color:#666;font-size:.75rem"><?= substr($r["created_at"]??"",0,16) ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
</div>
</div><!-- end wrap --><script>
var labels  = <?= $chartLabels ?>;
var revenue = <?= $chartRevenue ?>;
var vol     = <?= $chartVol ?>;
var ctx = document.getElementById("dailyChart");
if(ctx){
  new Chart(ctx, {
    type:"line",
    data:{
      labels:labels,
      datasets:[
        {label:"الإيرادات",data:revenue,borderColor:"#ffd700",backgroundColor:"rgba(255,215,0,.08)",tension:.3,fill:true,pointRadius:3},
        {label:"الحجم الكلي",data:vol,borderColor:"#5bc0de",backgroundColor:"rgba(91,192,222,.06)",tension:.3,fill:false,pointRadius:2,borderDash:[4,4]}
      ]
    },
    options:{
      responsive:true,maintainAspectRatio:false,
      plugins:{legend:{labels:{color:"#aaa",font:{family:"Cairo"}}}},
      scales:{
        x:{ticks:{color:"#666",maxTicksLimit:10},grid:{color:"rgba(255,255,255,.04)"}},
        y:{ticks:{color:"#666"},grid:{color:"rgba(255,255,255,.04)"}}
      }
    }
  });
}
</script>
</body>
</html>