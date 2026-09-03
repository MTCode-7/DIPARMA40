<?php
/**
 * ============================================================
 * DI PARMA | لوحة التحكم الرئيسية - Dashboard
 * ============================================================
 * نظام مراقبة وتحليل شامل لبوابات الدفع والمعاملات
 * ============================================================
 */

// التحقق من المصادقة
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/performance.php';
require_once __DIR__ . '/includes/db_optimized.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';

// ============================================================
// [1] جلب الإحصائيات من قاعدة البيانات
// ============================================================

$db = db();
dp_ensure_indexes();

// إحصائيات المعاملات — Cache 2 دقيقة
$_rawStats        = dp_get_dashboard_stats(30);
$transactionStats = [$_rawStats];
$stats = $_rawStats ?: ["total_transactions"=>0,"completed"=>0,"pending"=>0,"failed"=>0,"refunded"=>0,"chargeback"=>0,"total_amount"=>0,"completed_amount"=>0,"avg_amount"=>0];

// إحصائيات البوابات — Cache 2 دقيقة
$gatewayStats = dp_get_gateway_stats(30);

// المعاملات الأخيرة — Cache 30 ثانية
$recentTransactions = dp_get_recent_transactions(10);

// إحصائيات يومية — Cache 2 دقيقة
$dailyStats = DPCache::remember("daily_stats_7", 120, function() {
    return db()->query("SELECT DATE(created_at) as date, COUNT(*) as total, COALESCE(SUM(amount),0) as total_amount, COALESCE(SUM(CASE WHEN status='completed' THEN amount ELSE 0 END),0) as completed_amount FROM " . DB_PREFIX . "transactions WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY DATE(created_at) ORDER BY date ASC");
});

// إحصائيات البروتوكولات — Cache 5 دقائق
$protocolStats = DPCache::remember("protocol_stats_30", 300, function() {
    return db()->query("SELECT protocol, COUNT(*) as total, SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as successful FROM " . DB_PREFIX . "transactions WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY protocol ORDER BY total DESC");
});

// البوابات النشطة والمستخدمين — Cache 5 دقائق
$activeGatewaysCount = dp_get_active_gateways_count();
$totalUsersCount = (int) DPCache::remember("active_users_count", 300, function() {
    $r = db()->query("SELECT COUNT(*) AS c FROM " . DB_PREFIX . "users WHERE status='active'");
    return $r[0]["c"] ?? 0;
});

// ============================================================
// [2] حساب نسبة النجاح
// ============================================================
$successRate = ($stats["total_transactions"] ?? 0) > 0
    ? round(($stats["completed"] / $stats["total_transactions"]) * 100, 2)
    : 0;


// ============================================================
// [3] إعداد بيانات الرسم البياني
// ============================================================
$chartData = [
    'labels' => [],
    'amounts' => [],
    'counts' => []
];

foreach ($dailyStats as $day) {
    $chartData['labels'][] = date('d/m', strtotime($day['date']));
    $chartData['amounts'][] = floatval($day['completed_amount']);
    $chartData['counts'][] = intval($day['total']);
}

// ============================================================
// [4] عرض لوحة التحكم
// ============================================================
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang ?? 'en') ?>" dir="<?= htmlspecialchars($pageDir ?? 'ltr') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DI PARMA | <?= $currentLang==='en'?'Dashboard':'لوحة التحكم' ?></title>
    <meta name="theme-color" content="#0A0F1E">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        :root {
            --gold: #FFD700;
            --gold-dark: #B58E15;
            --gold-light: #FFE066;
            --bg-dark: #0A0F1E;
            --bg-card: rgba(10,16,39,0.94);
            --text-gold: #FFDFA0;
            --text-light: #E8F0FF;
            --border-gold: rgba(255,215,0,0.25);
            --success: #4CAF50;
            --danger: #d9534f;
            --warning: #f0ad4e;
            --info: #5bc0de;
            --purple: #9b59b6;
            --cyan: #1abc9c;
        }
        body {
            font-family: 'Cairo', sans-serif;
            background: radial-gradient(circle at top left, rgba(255,215,0,0.1), transparent 18%),
                        radial-gradient(circle at bottom right, rgba(255,215,0,0.08), transparent 16%),
                        linear-gradient(180deg, #020202 0%, #0b0b0b 35%, #090909 100%);
            background-attachment: fixed;
            color: var(--text-gold);
            min-height: 100vh;
        }
        .container { max-width: 1440px; margin: 0 auto; padding: 20px; }
        
        /* ===== شريط التنقل ===== */
        .navbar {
            background: rgba(0,0,0,0.85);
            border: 1px solid var(--border-gold);
            backdrop-filter: blur(20px);
            padding: 0.8rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            border-radius: 16px;
            margin-bottom: 25px;
            box-shadow: 0 10px 40px rgba(255,215,0,0.08);
        }
        .logo { display:flex; align-items:center; gap:12px; }
        .logo-icon {
            width:45px; height:45px;
            background:linear-gradient(135deg,var(--gold),var(--gold-dark));
            border-radius:12px;
            display:flex; align-items:center; justify-content:center;
            font-size:1.4rem; font-weight:900; color:var(--bg-dark);
            box-shadow:0 0 25px rgba(255,215,0,0.2);
        }
        .logo-text h2 {
            font-size:1.2rem; font-weight:800;
            background:linear-gradient(135deg,var(--gold-light),var(--gold));
            -webkit-background-clip:text; -webkit-text-fill-color:transparent;
        }
        .logo-text p { font-size:0.65rem; color:#999; margin-top:-2px; }
        .nav-links {
            display:flex; gap:8px; flex-wrap:wrap; align-items:center;
        }
        .nav-link {
            padding:8px 16px;
            border-radius:10px;
            color:var(--text-gold);
            text-decoration:none;
            font-size:0.8rem;
            font-weight:600;
            transition:all 0.3s ease;
            display:flex;
            align-items:center;
            gap:6px;
            background:rgba(255,255,255,0.03);
            border:1px solid transparent;
        }
        .nav-link:hover {
            background:rgba(255,215,0,0.08);
            border-color:var(--border-gold);
        }
        .nav-link.active {
            background:rgba(255,215,0,0.12);
            border-color:var(--gold);
            color:var(--gold);
        }
        .nav-link.logout {
            color:#ff6b6b;
            border-color:rgba(255,107,107,0.2);
        }
        .nav-link.logout:hover {
            background:rgba(255,107,107,0.1);
            border-color:rgba(255,107,107,0.4);
        }
        .user-badge {
            display:flex;
            align-items:center;
            gap:10px;
            background:rgba(255,215,0,0.05);
            padding:6px 14px 6px 18px;
            border-radius:30px;
            border:1px solid var(--border-gold);
            color:var(--text-gold);
            font-size:0.8rem;
        }
        .user-badge i { color:var(--gold); font-size:1rem; }
        
        /* ===== بطاقات الإحصائيات ===== */
        .stats-grid {
            display:grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap:15px;
            margin-bottom:25px;
        }
        .stat-card {
            background:var(--bg-card);
            border:1px solid var(--border-gold);
            border-radius:16px;
            padding:18px 20px;
            transition:all 0.3s ease;
            position:relative;
            overflow:hidden;
        }
        .stat-card:hover {
            transform:translateY(-4px);
            border-color:var(--gold);
            box-shadow:0 8px 30px rgba(255,215,0,0.05);
        }
        .stat-card .icon {
            font-size:1.8rem;
            margin-bottom:8px;
            display:block;
        }
        .stat-card .value {
            font-size:1.8rem;
            font-weight:800;
            color:var(--text-light);
            line-height:1.2;
        }
        .stat-card .label {
            font-size:0.7rem;
            color:#888;
            margin-top:4px;
        }
        .stat-card .change {
            font-size:0.7rem;
            margin-top:6px;
            display:inline-block;
            padding:2px 10px;
            border-radius:12px;
            background:rgba(76,175,80,0.15);
            color:var(--success);
        }
        .stat-card .change.negative {
            background:rgba(217,83,79,0.15);
            color:var(--danger);
        }
        .stat-card::before {
            content:'';
            position:absolute;
            top:0;
            right:0;
            width:80px;
            height:80px;
            background:radial-gradient(circle, rgba(255,215,0,0.03), transparent);
            border-radius:50%;
            transform:translate(30%, -30%);
        }
        .stat-card.gold .icon { color:var(--gold); }
        .stat-card.green .icon { color:var(--success); }
        .stat-card.red .icon { color:var(--danger); }
        .stat-card.blue .icon { color:var(--info); }
        .stat-card.purple .icon { color:var(--purple); }
        .stat-card.cyan .icon { color:var(--cyan); }
        
        /* ===== المخططات ===== */
        .charts-grid {
            display:grid;
            grid-template-columns: 2fr 1fr;
            gap:20px;
            margin-bottom:25px;
        }
        .chart-box {
            background:var(--bg-card);
            border:1px solid var(--border-gold);
            border-radius:16px;
            padding:20px;
        }
        .chart-box h3 {
            color:var(--text-light);
            font-size:1rem;
            margin-bottom:15px;
            display:flex;
            align-items:center;
            gap:10px;
        }
        .chart-box h3 i { color:var(--gold); }
        .chart-box canvas { width:100% !important; max-height:300px; }
        
        /* ===== المعاملات الأخيرة ===== */
        .transactions-section {
            background:var(--bg-card);
            border:1px solid var(--border-gold);
            border-radius:16px;
            padding:20px;
            margin-bottom:25px;
        }
        .transactions-section .header {
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:15px;
            flex-wrap:wrap;
            gap:10px;
        }
        .transactions-section .header h3 {
            color:var(--text-light);
            font-size:1rem;
            display:flex;
            align-items:center;
            gap:10px;
        }
        .transactions-section .header h3 i { color:var(--gold); }
        .transactions-table {
            width:100%;
            border-collapse:collapse;
            font-size:0.85rem;
        }
        .transactions-table th {
            text-align:right;
            padding:12px 10px;
            color:#888;
            font-weight:600;
            border-bottom:1px solid rgba(255,255,255,0.05);
        }
        .transactions-table td {
            padding:10px;
            border-bottom:1px solid rgba(255,255,255,0.03);
            color:var(--text-gold);
        }
        .transactions-table tr:hover td {
            background:rgba(255,215,0,0.03);
        }
        .status-badge {
            padding:4px 12px;
            border-radius:20px;
            font-size:0.7rem;
            font-weight:600;
            display:inline-block;
        }
        .status-completed { background:rgba(76,175,80,0.15); color:var(--success); }
        .status-pending { background:rgba(240,173,78,0.15); color:var(--warning); }
        .status-failed { background:rgba(217,83,79,0.15); color:var(--danger); }
        .status-refunded { background:rgba(91,192,222,0.15); color:var(--info); }
        .status-chargeback { background:rgba(155,89,182,0.15); color:var(--purple); }
        
        .reference-link {
            color:var(--gold);
            text-decoration:none;
            font-weight:600;
        }
        .reference-link:hover { text-decoration:underline; }
        
        /* ===== بوابة سريعة ===== */
        .quick-actions {
            display:grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap:12px;
            margin-top:10px;
        }
        .quick-btn {
            background:rgba(255,255,255,0.03);
            border:1px solid rgba(255,255,255,0.06);
            border-radius:12px;
            padding:15px;
            text-align:center;
            color:var(--text-gold);
            text-decoration:none;
            transition:all 0.3s ease;
            font-size:0.8rem;
            font-weight:600;
        }
        .quick-btn:hover {
            background:rgba(255,215,0,0.05);
            border-color:var(--border-gold);
            transform:translateY(-2px);
        }
        .quick-btn i {
            font-size:1.5rem;
            display:block;
            margin-bottom:8px;
            color:var(--gold);
        }
        
        /* ===== استجابة ===== */
        @media (max-width: 992px) {
            .charts-grid { grid-template-columns:1fr; }
        }
        @media (max-width: 768px) {
            .navbar { flex-direction:column; text-align:center; padding:1rem; }
            .nav-links { justify-content:center; }
            .stats-grid { grid-template-columns:1fr 1fr; }
            .transactions-table { font-size:0.7rem; }
            .transactions-table th, .transactions-table td { padding:6px; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns:1fr; }
        }
        
        /* ===== سكرول ===== */
        ::-webkit-scrollbar { width:6px; }
        ::-webkit-scrollbar-track { background:var(--bg-dark); }
        ::-webkit-scrollbar-thumb { background:var(--gold); border-radius:3px; }
        
        /* ===== تحميل ===== */
        .fade-in { animation:fadeIn 0.5s ease-in-out; }
        @keyframes fadeIn { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }
    </style>
</head>
<body>

<div class="container">
    <!-- ===== شريط التنقل ===== -->
    <nav class="navbar">
        <div class="logo">
            <div class="logo-icon">DP</div>
            <div class="logo-text">
                <h2>DI PARMA</h2>
                <p>Ultimate Financial Gateway</p>
            </div>
        </div>
        <div class="nav-links">
            <a href="index.php?account=1" class="nav-link"><i class="fas fa-home"></i> <?= $currentLang==='en'?'Home':'الرئيسية' ?></a>
            <a href="dashboard.php" class="nav-link active"><i class="fas fa-chart-pie"></i> <?= $currentLang==='en'?'Dashboard':'لوحة التحكم' ?></a>
            <a href="admin/connection_manager.php" class="nav-link"><i class="fas fa-network-wired"></i> <?= $currentLang==='en'?'Connection':'إدارة الاتصال' ?></a>
            <a href="wallets.php" class="nav-link"><i class="fas fa-wallet"></i> <?= $currentLang==='en'?'Wallet':'المحفظة' ?></a>
            <a href="invoices.php" class="nav-link"><i class="fas fa-file-invoice"></i> <?= $currentLang==='en'?'Invoices':'الفواتير' ?></a>
            <a href="approvals.php" class="nav-link"><i class="fas fa-check-double"></i> <?= $currentLang==='en'?'Approvals':'الموافقات' ?></a>
            <a href="admin/gateway_manager.php?profile=true" class="nav-link"><i class="fas fa-user-cog"></i> <?= $currentLang==='en'?'Settings':'تغيير الحساب' ?></a>
            <a href="transactions.php" class="nav-link"><i class="fas fa-list"></i> <?= $currentLang==='en'?'Transactions':'المعاملات' ?></a>
            <a href="crypto.php" class="nav-link"><i class="fas fa-coins"></i> Crypto</a>
            <a href="wallet.php" class="nav-link"><i class="fas fa-wallet"></i> <?= $currentLang==='en'?'My Wallet':'محفظتي' ?></a>
            <a href="ledger/" class="nav-link" style="border-color:rgba(255,215,0,.2);background:rgba(255,215,0,.04)">
              <svg width="13" height="13" viewBox="0 0 100 100" fill="currentColor" style="flex-shrink:0"><rect width="100" height="100" rx="16"/><rect x="15" y="60" width="70" height="10" rx="5" fill="black"/></svg>
              Ledger
            </a>
            <a href="pos.php" class="nav-link" style="border-color:rgba(16,185,129,.2);background:rgba(16,185,129,.04);color:#10B981">
              <i class="fas fa-cash-register"></i> POS
            </a>
            <a href="checkout_router.php" class="nav-link" style="border-color:rgba(255,215,0,.3);background:rgba(255,215,0,.06);color:var(--gold);font-weight:800">
              <i class="fas fa-credit-card"></i> <?= $currentLang==='en'?'Checkout':'الدفع' ?>
            </a>
            <a href="kyc.php" class="nav-link"><i class="fas fa-id-card"></i> KYC</a>
            <a href="reports.php" class="nav-link"><i class="fas fa-file-alt"></i> <?= $currentLang==='en'?'Reports':'التقارير' ?></a>
            <div style="display:flex;align-items:center;gap:8px">
                <?= langSwitcher(false) ?>
            </div>
            <div class="user-badge">
                <i class="fas fa-user-circle"></i>
                <?= htmlspecialchars($_SESSION['user_data']['first_name'] ?? ($currentLang==='en'?'User':'مستخدم')) ?>
            </div>
            <a href="logout.php" class="nav-link logout"><i class="fas fa-sign-out-alt"></i></a>
        </div>
    </nav>

    <!-- ===== الإحصائيات ===== -->
    <div class="stats-grid fade-in">
        <div class="stat-card gold">
            <span class="icon"><i class="fas fa-dollar-sign"></i></span>
            <div class="value"><?= number_format($stats['total_amount'] ?? 0, 2) ?></div>
            <div class="label"><?= $currentLang==='en'?'Total Transactions (Last 30D)':'إجمالي المعاملات (آخر 30 يوم)' ?></div>
            <div class="change"><i class="fas fa-arrow-up"></i> <?= number_format($stats['completed_amount'] ?? 0, 2) ?> <?= $currentLang==='en'?'Completed':'مكتمل' ?></div>
        </div>
        <div class="stat-card green">
            <span class="icon"><i class="fas fa-check-circle"></i></span>
            <div class="value"><?= number_format($stats['completed'] ?? 0) ?></div>
            <div class="label"><?= $currentLang==='en'?'Successful Transactions':'معاملات ناجحة' ?></div>
            <div class="change"><?= $currentLang==='en'?'Success Rate':'نسبة النجاح' ?> <?= $successRate ?>%</div>
        </div>
        <div class="stat-card blue">
            <span class="icon"><i class="fas fa-clock"></i></span>
            <div class="value"><?= number_format($stats['pending'] ?? 0) ?></div>
            <div class="label"><?= $currentLang==='en'?'Pending':'قيد الانتظار' ?></div>
            <div class="change"><i class="fas fa-hourglass-half"></i> <?= $currentLang==='en'?'Processing':'جاري المعالجة' ?></div>
        </div>
        <div class="stat-card red">
            <span class="icon"><i class="fas fa-times-circle"></i></span>
            <div class="value"><?= number_format($stats['failed'] ?? 0) ?></div>
            <div class="label"><?= $currentLang==='en'?'Failed':'فاشلة' ?></div>
            <div class="change negative"><i class="fas fa-arrow-down"></i> <?= number_format($stats['chargeback'] ?? 0) ?> <?= $currentLang==='en'?'Chargebacks':'إلغاء' ?></div>
        </div>
        <div class="stat-card purple">
            <span class="icon"><i class="fas fa-credit-card"></i></span>
            <div class="value"><?= number_format($stats['refunded'] ?? 0) ?></div>
            <div class="label"><?= $currentLang==='en'?'Refunded':'مستردة' ?></div>
            <div class="change"><?= $currentLang==='en'?'Refunded':'تم الاسترداد' ?></div>
        </div>
        <div class="stat-card cyan">
            <span class="icon"><i class="fas fa-university"></i></span>
            <div class="value"><?= number_format($activeGatewaysCount) ?></div>
            <div class="label"><?= $currentLang==='en'?'Active Gateways':'بوابات نشطة' ?></div>
            <div class="change"><i class="fas fa-check"></i> <?= $currentLang==='en'?'Connected':'متصلة' ?></div>
        </div>
    </div>

    <!-- ===== المخططات ===== -->
    <div class="charts-grid fade-in">
        <div class="chart-box">
            <h3><i class="fas fa-chart-bar"></i> <?= $currentLang==='en'?'Daily Transactions (Last 7D)':'المعاملات اليومية (آخر 7 أيام)' ?></h3>
            <canvas id="dailyChart"></canvas>
        </div>
        <div class="chart-box">
            <h3><i class="fas fa-chart-pie"></i> <?= $currentLang==='en'?'Gateway Distribution':'توزيع البوابات' ?></h3>
            <canvas id="gatewayChart"></canvas>
        </div>
    </div>

    <!-- ===== المعاملات الأخيرة ===== -->
    <div class="transactions-section fade-in">
        <div class="header">
            <h3><i class="fas fa-history"></i> <?= $currentLang==='en'?'Recent Transactions':'آخر المعاملات' ?></h3>
            <a href="transactions.php" class="nav-link" style="font-size:0.8rem;">
                <?= $currentLang==='en'?'View All':'عرض الكل' ?> <i class="fas fa-arrow-left"></i>
            </a>
        </div>
        <div style="overflow-x:auto;">
            <table class="transactions-table">
                <thead>
                    <tr>
                        <th><?= $currentLang==='en'?'Reference':'المرجع' ?></th>
                        <th><?= $currentLang==='en'?'Customer':'العميل' ?></th>
                        <th><?= $currentLang==='en'?'Amount':'المبلغ' ?></th>
                        <th><?= $currentLang==='en'?'Gateway':'البوابة' ?></th>
                        <th><?= $currentLang==='en'?'Status':'الحالة' ?></th>
                        <th><?= $currentLang==='en'?'Date':'التاريخ' ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentTransactions)): ?>
                        <tr>
                            <td colspan="6" style="text-align:center;color:#666;padding:30px;">
                                <i class="fas fa-inbox" style="font-size:2rem;display:block;margin-bottom:10px;"></i>
                                <?= $currentLang==='en'?'No Recent Transactions':'لا توجد معاملات حديثة' ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recentTransactions as $tx): ?>
                            <tr>
                                <td>
                                    <a href="transaction.php?id=<?= $tx['id'] ?>" class="reference-link">
                                        <?= htmlspecialchars($tx['reference']) ?>
                                    </a>
                                </td>
                                <td><?= htmlspecialchars($tx['customer_name'] ?: ($currentLang==='en'?'Unknown':'غير معروف')) ?></td>
                                <td>
                                    <strong>
                                        <?= number_format($tx['amount'], 2) ?>
                                        <small style="color:#888;"><?= htmlspecialchars($tx['currency']) ?></small>
                                    </strong>
                                </td>
                                <td><?= htmlspecialchars($tx['gateway']) ?></td>
                                <td>
                                    <span class="status-badge status-<?= $tx['status'] ?>">
                                        <?php
                                        $statusLabels = (
                                            $currentLang === 'en'
                                            ? [
                                                'completed' => 'Completed',
                                                'pending' => 'Pending',
                                                'failed' => 'Failed',
                                                'refunded' => 'Refunded',
                                                'chargeback' => 'Chargeback'
                                            ]
                                            : [
                                                'completed' => 'مكتمل',
                                                'pending' => 'قيد الانتظار',
                                                'failed' => 'فشل',
                                                'refunded' => 'مسترد',
                                                'chargeback' => 'إلغاء'
                                            ]
                                        );
                                        echo $statusLabels[$tx['status']] ?? $tx['status'];
                                        ?>
                                    </span>
                                </td>
                                <td style="font-size:0.75rem;color:#888;">
                                    <?= date('d/m/Y H:i', strtotime($tx['created_at'])) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ===== إجراءات سريعة ===== -->
    <div class="quick-actions fade-in">
        <a href="admin/connection_manager.php" class="quick-btn">
            <i class="fas fa-plus-circle"></i>
            <?= $currentLang==='en'?'Add Gateway':'إضافة بوابة' ?>
        </a>
        <a href="payment.php" class="quick-btn">
            <i class="fas fa-hand-holding-usd"></i>
            <?= $currentLang==='en'?'New Payment':'عملية دفع جديدة' ?>
        </a>
        <a href="reports.php" class="quick-btn">
            <i class="fas fa-file-pdf"></i>
            <?= $currentLang==='en'?'Financial Report':'تقرير مالي' ?>
        </a>
        <a href="backup.php" class="quick-btn">
            <i class="fas fa-database"></i>
            <?= $currentLang==='en'?'Backup':'نسخ احتياطي' ?>
        </a>
        <a href="settings.php" class="quick-btn">
            <i class="fas fa-cogs"></i>
            <?= $currentLang==='en'?'System Settings':'إعدادات النظام' ?>
        </a>
        <a href="security.php" class="quick-btn">
            <i class="fas fa-shield-alt"></i>
            <?= $currentLang==='en'?'Security Monitor':'مراقبة الأمان' ?>
        </a>
        <a href="ledger/" class="quick-btn" style="border-color:rgba(255,215,0,.2);background:rgba(255,215,0,.03)">
            <i class="fas fa-wallet" style="color:var(--gold)"></i>
            Ledger Wallet
        </a>
        <a href="pos.php" class="quick-btn" style="border-color:rgba(16,185,129,.2);background:rgba(16,185,129,.03)">
            <i class="fas fa-cash-register" style="color:#10B981"></i>
            POS Terminal
        </a>
        <a href="checkout_router.php" class="quick-btn" style="border-color:rgba(255,215,0,.3);background:rgba(255,215,0,.05)">
            <i class="fas fa-credit-card" style="color:var(--gold)"></i>
            <?= $currentLang==='en'?'Checkout':'الدفع والتحويل' ?>
        </a>
        <a href="gateway/dashboard.php" class="quick-btn" style="border-color:rgba(59,130,246,.2);background:rgba(59,130,246,.03)">
            <i class="fas fa-network-wired" style="color:#3B82F6"></i>
            Gateway Monitor
        </a>
    </div>
</div>

<!-- ============================================================
     JavaScript - المخططات
============================================================ -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== رسم بياني يومي =====
    const dailyCtx = document.getElementById('dailyChart').getContext('2d');
    const currentLang = '<?= htmlspecialchars($currentLang) ?>';
    const dailyData = <?= json_encode([
        'labels' => $chartData['labels'],
        'amounts' => $chartData['amounts'],
        'counts' => $chartData['counts']
    ]) ?>;
    
    new Chart(dailyCtx, {
        type: 'bar',
        data: {
            labels: dailyData.labels,
            datasets: [
                {
                    label: currentLang==='en'?'Amount (USD)':'المبلغ (USD)',
                    data: dailyData.amounts,
                    backgroundColor: 'rgba(255, 215, 0, 0.3)',
                    borderColor: '#FFD700',
                    borderWidth: 2,
                    borderRadius: 4,
                    yAxisID: 'y',
                },
                {
                    label: currentLang==='en'?'Transaction Count':'عدد المعاملات',
                    data: dailyData.counts,
                    type: 'line',
                    borderColor: '#4CAF50',
                    backgroundColor: 'rgba(76, 175, 80, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#4CAF50',
                    yAxisID: 'y1',
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    labels: {
                        color: '#E8F0FF',
                        font: { family: currentLang==='en'?'Arial':'Cairo' }
                    }
                }
            },
            scales: {
                x: {
                    grid: { color: 'rgba(255,255,255,0.03)' },
                    ticks: { color: '#888' }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(255,255,255,0.03)' },
                    ticks: { color: '#888' },
                    position: 'left'
                },
                y1: {
                    beginAtZero: true,
                    grid: { display: false },
                    ticks: { color: '#4CAF50' },
                    position: 'right'
                }
            }
        }
    });

    // ===== رسم بياني للبوابات =====
    const gatewayCtx = document.getElementById('gatewayChart').getContext('2d');
    const gatewayData = <?= json_encode($gatewayStats) ?>;
    
    const labels = gatewayData.map(g => g.gateway);
    const data = gatewayData.map(g => g.total);
    const colors = ['#FFD700', '#4CAF50', '#5bc0de', '#9b59b6', '#f0ad4e', '#e74c3c', '#1abc9c', '#3498db'];
    
    new Chart(gatewayCtx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: data,
                backgroundColor: colors.slice(0, data.length),
                borderColor: 'rgba(10,15,30,0.8)',
                borderWidth: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: '#E8F0FF',
                        font: { family: currentLang==='en'?'Arial':'Cairo', size: 11 },
                        padding: 15
                    }
                }
            }
        }
    });
});

// ============================================================
// تحديث البيانات كل 60 ثانية
// ============================================================
setInterval(function() {
    location.reload();
}, 60000);
</script>

</body>
</html>
