<?php
/**
 * ============================================================
 * DI PARMA | المعاملات المالية - Transactions
 * ============================================================
 * عرض وإدارة جميع المعاملات المالية مع خيارات البحث والتصفية
 * ============================================================
 */

// التحقق من المصادقة
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/performance.php';
require_once __DIR__ . '/includes/db_optimized.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/gateways.php';
requireAdmin();

$ar = function_exists('is_ar') ? is_ar() : (($currentLang ?? 'en') === 'ar');
$db = db();
$csrfToken = generateCsrfToken();
dp_ensure_indexes();
$reconcileReport = [];
$peerPull = [];
try {
    if (function_exists('diparma_peer_pull_transactions')) {
        $peerPull = diparma_peer_pull_transactions(100);
    }
} catch (Throwable $e) {
    $peerPull = [];
}
try {
    $reconcileReport = diparma_reconcile_pending_transactions($db, 25);
} catch (Throwable $e) {
    $reconcileReport = [];
}

$refundMessage = '';
$refundStatus = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['refund_reference'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $refundMessage = 'Security check failed';
        $refundStatus = 'error';
    } else {
    $refundResult = processRefundTransaction(
        trim((string)($_POST['refund_reference'] ?? '')),
        floatval($_POST['refund_amount'] ?? 0),
        trim((string)($_POST['refund_reason'] ?? 'Refund requested via transactions page'))
    );
    $refundMessage = $refundResult['message'] ?? '';
    $refundStatus = $refundResult['success'] ? 'success' : 'error';
    }
}

// ============================================================
// [1] معالجة الطلبات
// ============================================================

// تصفية البحث
$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';
$gateway = $_GET['gateway'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$sort = $_GET['sort'] ?? 'desc';
$limit = intval($_GET['limit'] ?? 50);
$page = intval($_GET['page'] ?? 1);
$offset = ($page - 1) * $limit;

// بناء استعلام البحث
$where = [];
$params = [];

if (!empty($search)) {
    $where[] = "(reference COLLATE utf8mb4_general_ci LIKE ? OR customer_name COLLATE utf8mb4_general_ci LIKE ? OR customer_email COLLATE utf8mb4_general_ci LIKE ? OR gateway COLLATE utf8mb4_general_ci LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$where[] = diparma_visible_transaction_sql();
if (!empty($status)) {
    $where[] = "status COLLATE utf8mb4_general_ci = ?";
    $params[] = $status;
}

if (!empty($gateway)) {
    $where[] = "gateway COLLATE utf8mb4_general_ci = ?";
    $params[] = $gateway;
}

if (!empty($date_from)) {
    $where[] = "created_at >= ?";
    $params[] = $date_from . ' 00:00:00';
}

if (!empty($date_to)) {
    $where[] = "created_at <= ?";
    $params[] = $date_to . ' 23:59:59';
}

$whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// ============================================================
// [2] جلب المعاملات
// ============================================================

// إجمالي المعاملات
$countSql = "SELECT COUNT(*) as total FROM " . DB_PREFIX . "transactions $whereClause";
$countResult = $db->query($countSql, $params);
$totalTransactions = $countResult[0]['total'] ?? 0;

// تحقق من وجود العمود amount_usdt قبل إضافته إلى الاستعلام
$amountUsdtField = '0.00 AS amount_usdt,';
try {
    $columnExists = $db->query(
        "SELECT 1 AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1",
        [DB_NAME, DB_PREFIX . 'transactions', 'amount_usdt']
    );
    if (!empty($columnExists[0]['cnt'])) {
        $amountUsdtField = 'COALESCE(t.amount_usdt, 0.00) AS amount_usdt,';
    } else {
        // إذا لم يكن العمود موجوداً، نضيفه تلقائياً لتجنب الأخطاء مستقبلاً
        try {
            $db->execute(
                "ALTER TABLE " . DB_PREFIX . "transactions ADD COLUMN amount_usdt DECIMAL(12,2) NOT NULL DEFAULT 0.00"
            );
            $amountUsdtField = 'COALESCE(t.amount_usdt, 0.00) AS amount_usdt,';
        } catch (Exception $alterError) {
            // تجاهل في حالة فشل تغيير الجدول، نستخدم القيمة الافتراضية فقط
            $amountUsdtField = '0.00 AS amount_usdt,';
        }
    }
} catch (Exception $e) {
    // إذا لم تنجح استعلامات مخطط البيانات، نستخدم القيمة الافتراضية الآمنة
    $amountUsdtField = '0.00 AS amount_usdt,';
}

// تأكد من أن الأعمدة الضرورية موجودة قبل استخدام الاستعلام
foreach (['card_type' => 'VARCHAR(50) DEFAULT NULL', 'card_last4' => 'VARCHAR(16) DEFAULT NULL', 'updated_at' => "DATETIME NULL DEFAULT NULL"] as $column => $definition) {
    try {
        $columnExists = $db->query(
            "SELECT 1 AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1",
            [DB_NAME, DB_PREFIX . 'transactions', $column]
        );
        if (empty($columnExists[0]['cnt'])) {
            $db->execute("ALTER TABLE " . DB_PREFIX . "transactions ADD COLUMN {$column} {$definition}");
        }
    } catch (Exception $e) {
        // إذا فشل التحقق أو الإضافة، نتابع دون إيقاف الصفحة.
    }
}

// المعاملات مع التصفية
$sql = "
    SELECT 
        t.id,
        t.reference,
        t.gateway,
        t.protocol,
        t.amount,
        t.currency,
        $amountUsdtField
        t.fees,
        t.net_amount,
        t.status,
        t.transaction_type,
        t.transaction_label,
        t.card_type,
        t.card_last4,
        t.customer_name,
        t.customer_email,
        t.customer_phone,
        t.gateway_response,
        t.error_message,
        t.created_at,
        t.updated_at,
        (SELECT COUNT(*) FROM " . DB_PREFIX . "contracts c WHERE c.reference COLLATE utf8mb4_general_ci = t.reference COLLATE utf8mb4_general_ci) AS has_contract
    FROM " . DB_PREFIX . "transactions t
    $whereClause
    ORDER BY created_at " . ($sort === 'asc' ? 'ASC' : 'DESC') . "
    LIMIT ? OFFSET ?
";

$queryParams = array_merge($params, [$limit, $offset]);
$transactions = $db->query($sql, $queryParams);

// ============================================================
// [3] إحصائيات المعاملات
// ============================================================

$stats = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
        SUM(CASE WHEN status = 'refunded' THEN 1 ELSE 0 END) as refunded,
        SUM(CASE WHEN status = 'chargeback' THEN 1 ELSE 0 END) as chargeback,
        SUM(amount) as total_amount,
        SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as completed_amount,
        AVG(amount) as avg_amount
    FROM " . DB_PREFIX . "transactions
    WHERE " . diparma_visible_transaction_sql() . "
");

$transactionStats = $stats[0] ?? [
    'total' => 0,
    'completed' => 0,
    'pending' => 0,
    'failed' => 0,
    'refunded' => 0,
    'chargeback' => 0,
    'total_amount' => 0,
    'completed_amount' => 0,
    'avg_amount' => 0
];

// ============================================================
// [4] الحصول على قائمة البوابات للتصفية
// ============================================================

$gatewaysList = $db->query("
    SELECT DISTINCT gateway 
    FROM " . DB_PREFIX . "transactions 
    ORDER BY gateway COLLATE utf8mb4_general_ci
");

// ============================================================
// [5] حساب الصفحات
// ============================================================

$totalPages = ceil($totalTransactions / $limit);

// ============================================================
// [6] عرض الصفحة
// ============================================================
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang ?? 'en') ?>" dir="<?= htmlspecialchars($pageDir ?? 'ltr') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DI PARMA | Financial Transactions</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
        }
        body {
            font-family: 'Cairo', sans-serif;
            background: radial-gradient(circle at top left, rgba(255,215,0,0.1), transparent 18%),
                        radial-gradient(circle at bottom right, rgba(255,215,0,0.08), transparent 16%),
                        linear-gradient(180deg, #020202 0%, #0b0b0b 35%, #090909 100%);
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
        
        /* ===== إحصائيات ===== */
        .stats-grid {
            display:grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap:12px;
            margin-bottom:20px;
        }
        .stat-card {
            background:var(--bg-card);
            border:1px solid var(--border-gold);
            border-radius:12px;
            padding:12px 15px;
            text-align:center;
        }
        .stat-card .number {
            font-size:1.3rem;
            font-weight:800;
            color:var(--gold);
        }
        .stat-card .label {
            font-size:0.65rem;
            color:#888;
            margin-top:2px;
        }
        .stat-card .number.green { color:var(--success); }
        .stat-card .number.red { color:var(--danger); }
        .stat-card .number.yellow { color:var(--warning); }
        .stat-card .number.blue { color:var(--info); }
        .stat-card .number.purple { color:var(--purple); }
        
        /* ===== فلتر البحث ===== */
        .filter-section {
            background:var(--bg-card);
            border:1px solid var(--border-gold);
            border-radius:16px;
            padding:20px;
            margin-bottom:20px;
        }
        .filter-grid {
            display:grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap:12px;
        }
        .filter-group label {
            display:block;
            font-size:0.7rem;
            color:#888;
            margin-bottom:4px;
        }
        .filter-group input, .filter-group select {
            width:100%;
            padding:8px 12px;
            background:rgba(0,0,0,0.8);
            border:1px solid rgba(255,255,255,0.08);
            border-radius:8px;
            color:var(--text-light);
            font-family:'Cairo',sans-serif;
            font-size:0.85rem;
        }
        .filter-group input:focus, .filter-group select:focus {
            outline:none;
            border-color:var(--gold);
        }
        .filter-actions {
            display:flex;
            gap:8px;
            align-items:end;
            margin-top:8px;
        }
        .btn {
            padding:8px 16px;
            border:none;
            border-radius:8px;
            font-family:'Cairo',sans-serif;
            font-weight:600;
            cursor:pointer;
            transition:all 0.3s ease;
            display:inline-flex;
            align-items:center;
            gap:6px;
            font-size:0.85rem;
            text-decoration:none;
        }
        .btn-primary {
            background:linear-gradient(135deg,var(--gold-light),var(--gold));
            color:var(--bg-dark);
        }
        .btn-primary:hover { transform:translateY(-2px); box-shadow:0 8px 25px rgba(255,215,0,0.3); }
        .btn-outline {
            background:transparent;
            border:1px solid var(--border-gold);
            color:var(--text-gold);
        }
        .btn-outline:hover { background:rgba(255,215,0,0.05); }
        .btn-success { background:var(--success); color:white; }
        .btn-danger { background:var(--danger); color:white; }
        .btn-info { background:var(--info); color:white; }
        .btn-sm { padding:4px 10px; font-size:0.7rem; }
        
        /* ===== جدول المعاملات ===== */
        .transactions-section {
            background:var(--bg-card);
            border:1px solid var(--border-gold);
            border-radius:16px;
            padding:20px;
            overflow-x:auto;
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
            font-size:0.8rem;
            min-width:800px;
        }
        .transactions-table th {
            text-align:right;
            padding:10px;
            color:#888;
            font-weight:600;
            border-bottom:1px solid rgba(255,255,255,0.05);
            white-space:nowrap;
        }
        .transactions-table td {
            padding:10px;
            border-bottom:1px solid rgba(255,255,255,0.03);
            color:var(--text-gold);
            vertical-align:middle;
        }
        .transactions-table tr:hover td {
            background:rgba(255,215,0,0.02);
        }
        .transactions-table .ref-link {
            color:var(--gold);
            text-decoration:none;
            font-weight:600;
        }
        .transactions-table .ref-link:hover { text-decoration:underline; }
        
        .status-badge {
            padding:3px 10px;
            border-radius:20px;
            font-size:0.65rem;
            font-weight:600;
            display:inline-block;
        }
        .status-completed { background:rgba(76,175,80,0.15); color:var(--success); }
        .status-pending { background:rgba(240,173,78,0.15); color:var(--warning); }
        .status-failed { background:rgba(217,83,79,0.15); color:var(--danger); }
        .status-refunded { background:rgba(91,192,222,0.15); color:var(--info); }
        .status-chargeback { background:rgba(155,89,182,0.15); color:var(--purple); }
        .status-authorized { background:rgba(91,192,222,0.15); color:var(--info); }
        .status-captured { background:rgba(76,175,80,0.15); color:var(--success); }
        .status-settled { background:rgba(76,175,80,0.15); color:var(--success); }
        
        /* ===== ترقيم الصفحات ===== */
        .pagination {
            display:flex;
            justify-content:center;
            gap:6px;
            margin-top:20px;
            flex-wrap:wrap;
        }
        .pagination a, .pagination span {
            padding:6px 12px;
            border-radius:8px;
            color:var(--text-gold);
            text-decoration:none;
            border:1px solid rgba(255,255,255,0.05);
            transition:all 0.3s ease;
            font-size:0.85rem;
        }
        .pagination a:hover {
            border-color:var(--border-gold);
            background:rgba(255,215,0,0.05);
        }
        .pagination .active {
            background:rgba(255,215,0,0.15);
            border-color:var(--gold);
            color:var(--gold);
        }
        .pagination .disabled {
            opacity:0.3;
            cursor:default;
        }
        
        .empty-state {
            text-align:center;
            padding:40px 20px;
            color:#666;
        }
        .empty-state i {
            font-size:3rem;
            display:block;
            margin-bottom:10px;
            color:var(--border-gold);
        }
        
        @media (max-width: 768px) {
            .navbar { flex-direction:column; text-align:center; padding:1rem; }
            .nav-links { justify-content:center; flex-wrap:wrap; }
            .filter-grid { grid-template-columns:1fr 1fr; }
            .stats-grid { grid-template-columns:repeat(3, 1fr); }
            .transactions-table { font-size:0.7rem; min-width:600px; }
            .transactions-table th, .transactions-table td { padding:6px; }
        }
        @media (max-width: 480px) {
            .filter-grid { grid-template-columns:1fr; }
            .stats-grid { grid-template-columns:1fr 1fr; }
        }
        
        ::-webkit-scrollbar { width:6px; }
        ::-webkit-scrollbar-track { background:var(--bg-dark); }
        ::-webkit-scrollbar-thumb { background:var(--gold); border-radius:3px; }
        .fade-in { animation:fadeIn 0.5s ease-in-out; }
        @keyframes fadeIn { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }
        
        .amount-usdt {
            font-size:0.7rem;
            color:#888;
            display:block;
        }
        .customer-info {
            font-size:0.7rem;
            color:#888;
        }
        .customer-info .name { color:var(--text-gold); }
        .alert {
            padding:10px 14px;
            border-radius:10px;
            margin-bottom:16px;
            font-size:0.85rem;
            border:1px solid transparent;
        }
        .alert-success { background:rgba(76,175,80,0.14); color:#b8f2b8; border-color:rgba(76,175,80,0.35); }
        .alert-error { background:rgba(217,83,79,0.14); color:#ffb7b7; border-color:rgba(217,83,79,0.35); }
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
            <a href="index.php" class="nav-link"><i class="fas fa-home"></i> Home</a>
            <a href="dashboard.php" class="nav-link"><i class="fas fa-chart-pie"></i> Dashboard</a>
            <a href="transactions.php" class="nav-link active"><i class="fas fa-list"></i> Transactions</a>
            <a href="crypto.php" class="nav-link"><i class="fas fa-coins"></i> Crypto</a>
            <a href="links.php" class="nav-link"><i class="fas fa-link"></i> Payment Links</a>
            <?php if (isAdmin()): ?>
            <a href="admin/gateway_manager.php" class="nav-link"><i class="fas fa-route"></i> Gateways</a>
            <a href="admin/gateway_manager.php?profile=true" class="nav-link"><i class="fas fa-user-cog"></i> Account Settings</a>
            <?php endif; ?>
            <a href="logout.php" class="nav-link logout"><i class="fas fa-sign-out-alt"></i></a>
        </div>
    </nav>

    <?php if (!empty($refundMessage)): ?>
        <div class="alert alert-<?= $refundStatus === 'success' ? 'success' : 'error' ?>">
            <?= htmlspecialchars($refundMessage) ?>
        </div>
    <?php endif; ?>
    <?php
    $stillPending = array_values(array_filter($reconcileReport, static fn($row) => !empty($row['still_pending'])));
    $nowCompleted = array_values(array_filter($reconcileReport, static fn($row) => empty($row['still_pending'])));
    ?>
    <?php if (!empty($peerPull['pulled'])): ?>
        <div class="alert alert-success">
            <?= $ar
                ? 'استعادة من البعيد: ' . (int) $peerPull['pulled'] . ' معاملة.'
                : 'Restored from remote: ' . (int) $peerPull['pulled'] . ' transaction(s).' ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($reconcileReport)): ?>
        <div class="alert <?= empty($stillPending) ? 'alert-success' : 'alert-error' ?>">
            <?= $ar
                ? 'مراجعة الحي: ' . count($reconcileReport) . ' معلّقة سابقاً — ' . count($nowCompleted) . ' اكتملت و' . count($stillPending) . ' ما زالت معلّقة لأن الفاتورة لم تُدفع على البوابة.'
                : 'Live review: ' . count($reconcileReport) . ' previously pending — ' . count($nowCompleted) . ' completed and ' . count($stillPending) . ' still pending because the gateway invoice was never paid.' ?>
        </div>
    <?php endif; ?>

    <!-- ===== الإحصائيات ===== -->
    <div class="stats-grid fade-in">
        <div class="stat-card">
            <div class="number"><?= number_format($transactionStats['total'] ?? 0) ?></div>
            <div class="label">Total Transactions</div>
        </div>
        <div class="stat-card">
            <div class="number green"><?= number_format($transactionStats['completed'] ?? 0) ?></div>
            <div class="label">Completed</div>
        </div>
        <div class="stat-card">
            <div class="number yellow"><?= number_format($transactionStats['pending'] ?? 0) ?></div>
            <div class="label">Pending</div>
        </div>
        <div class="stat-card">
            <div class="number red"><?= number_format($transactionStats['failed'] ?? 0) ?></div>
            <div class="label">Failed</div>
        </div>
        <div class="stat-card">
            <div class="number blue"><?= number_format($transactionStats['refunded'] ?? 0) ?></div>
            <div class="label">Refunded</div>
        </div>
        <div class="stat-card">
            <div class="number purple"><?= number_format($transactionStats['chargeback'] ?? 0) ?></div>
            <div class="label">Chargebacks</div>
        </div>
        <div class="stat-card">
            <div class="number"><?= number_format($transactionStats['total_amount'] ?? 0, 2) ?></div>
            <div class="label">Total Amount</div>
        </div>
        <div class="stat-card">
            <div class="number" style="color:var(--success);"><?= number_format($transactionStats['completed_amount'] ?? 0, 2) ?></div>
            <div class="label">Completed Amount</div>
        </div>
    </div>

    <!-- ===== فلتر البحث ===== -->
    <div class="filter-section fade-in">
        <form method="GET" action="">
            <div class="filter-grid">
                <div class="filter-group">
                    <label><i class="fas fa-search"></i> Search</label>
                    <input type="text" name="search" placeholder="Reference, customer, gateway..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-circle"></i> Status</label>
                    <select name="status">
                        <option value="">All</option>
                        <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="authorized" <?= $status === 'authorized' ? 'selected' : '' ?>>Authorized</option>
                        <option value="captured" <?= $status === 'captured' ? 'selected' : '' ?>>Captured</option>
                        <option value="settled" <?= $status === 'settled' ? 'selected' : '' ?>>Settled</option>
                        <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="failed" <?= $status === 'failed' ? 'selected' : '' ?>>Failed</option>
                        <option value="refunded" <?= $status === 'refunded' ? 'selected' : '' ?>>Refunded</option>
                        <option value="chargeback" <?= $status === 'chargeback' ? 'selected' : '' ?>>Chargeback</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-credit-card"></i> Gateway</label>
                    <select name="gateway">
                        <option value="">All</option>
                        <?php foreach ($gatewaysList as $g): ?>
                            <option value="<?= htmlspecialchars($g['gateway']) ?>" <?= $gateway === $g['gateway'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($g['gateway']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-calendar"></i> From Date</label>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-calendar"></i> To Date</label>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-sort"></i> Sort Order</label>
                    <select name="sort">
                        <option value="desc" <?= $sort === 'desc' ? 'selected' : '' ?>>Newest First</option>
                        <option value="asc" <?= $sort === 'asc' ? 'selected' : '' ?>>Oldest First</option>
                    </select>
                </div>
                <div class="filter-group" style="display:flex;gap:8px;align-items:end;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                    <a href="transactions.php" class="btn btn-outline"><i class="fas fa-undo"></i> Reset</a>
                </div>
            </div>
        </form>
    </div>

    <!-- ===== جدول المعاملات ===== -->
    <div class="transactions-section fade-in">
        <div class="header">
            <h3><i class="fas fa-list"></i> Transactions (<?= number_format($totalTransactions) ?>)</h3>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <span style="font-size:0.7rem;color:#888;">
                    Showing <?= count($transactions) ?> of <?= number_format($totalTransactions) ?>
                </span>
                <?php if (($transactionStats['failed'] ?? 0) > 0): ?>
                <button type="button" class="btn btn-danger btn-sm" onclick="clearFailedTxns()">
                    <i class="fas fa-trash"></i> Clear Failed (<?= (int)$transactionStats['failed'] ?>)
                </button>
                <?php endif; ?>
                <a href="?export=csv&<?= http_build_query($_GET) ?>" class="btn btn-success btn-sm">
                    <i class="fas fa-file-csv"></i> Export
                </a>
            </div>
        </div>

        <?php if (empty($transactions)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <p>No transactions match your search.</p>
            </div>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="transactions-table">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Customer</th>
                            <th>Amount</th>
                            <th>Gateway</th>
                            <th>Protocol</th>
                            <th>Status</th>
                            <th><?= $ar ? 'سبب التعليق' : 'Hang reason' ?></th>
                            <th>Operation</th>
                            <th>Date</th>
                            <th>Contract</th>
                            <th>Edit / Delete</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $tx): ?>
                            <tr data-id="<?= (int)$tx['id'] ?>">
                                <td>
                                    <a href="receipt.php?ref=<?= urlencode($tx['reference']) ?>" class="ref-link">
                                        <?= htmlspecialchars($tx['reference']) ?>
                                    </a>
                                </td>
                                <td>
                                    <div class="customer-info">
                                        <div class="name"><?= htmlspecialchars($tx['customer_name'] ?? 'Unknown') ?></div>
                                        <div style="font-size:0.6rem;"><?= htmlspecialchars($tx['customer_email'] ?? '') ?></div>
                                        <?php if ($tx['card_last4']): ?>
                                            <div style="font-size:0.6rem;color:#666;">•••• <?= htmlspecialchars($tx['card_last4']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <strong><?= number_format($tx['amount'], 2) ?></strong>
                                    <small style="color:#888;"><?= htmlspecialchars($tx['currency']) ?></small>
                                    <?php if ((float)$tx['amount_usdt'] > 0): ?>
                                        <span class="amount-usdt">≈ <?= number_format($tx['amount_usdt'], 2) ?> USDT</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-size:0.7rem;color:#888;"><?= htmlspecialchars($tx['gateway']) ?></span>
                                    <?php if ($tx['card_type']): ?>
                                        <div style="font-size:0.6rem;color:#666;"><?= htmlspecialchars($tx['card_type']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="protocol-badge" style="font-size:0.6rem;color:var(--gold);">
                                        <?= htmlspecialchars(function_exists('protocol_display_name') ? protocol_display_name($tx['protocol'] ?? '') : ($tx['protocol'] ?? '')) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge status-<?= htmlspecialchars($tx['status']) ?>">
                                        <?= getStatusLabel($tx['status']) ?>
                                    </span>
                                </td>
                                <td style="max-width:280px;font-size:0.68rem;line-height:1.35;color:#c9b37a;">
                                    <?php
                                    $hang = diparma_transaction_hang_reason($tx);
                                    echo htmlspecialchars($ar ? ($hang['ar'] ?? '') : ($hang['en'] ?? ''));
                                    if (!empty($hang['live_status'])) {
                                        echo '<div style="color:#888;margin-top:4px;">Live: ' . htmlspecialchars((string) $hang['live_status']) . '</div>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <strong style="color:var(--gold);">
                                        <?= htmlspecialchars($tx['transaction_label'] ?: (in_array($tx['transaction_type'] ?? '', ['auth_capture', 'purchase', 'purchase_2d', 'purchase_advice', 'purchase_offline', 'purchase_online'], true) ? 'SALE' : 'PURCHASE')) ?>
                                    </strong>
                                </td>
                                <td style="font-size:0.7rem;color:#888;">
                                    <?= date('d/m/Y', strtotime($tx['created_at'])) ?>
                                    <div style="font-size:0.6rem;"><?= date('H:i', strtotime($tx['created_at'])) ?></div>
                                </td>
                                <td>
                                    <?php if (!empty($tx['has_contract'])): ?>
                                        <a href="contract_print.php?ref=<?= urlencode($tx['reference']) ?>" class="btn btn-outline btn-sm" title="<?= $ar ? 'عرض العقد' : 'View contract' ?>">
                                            <i class="fas fa-file-contract"></i>
                                        </a>
                                        <a href="contract_pdf.php?ref=<?= urlencode($tx['reference']) ?>" class="btn btn-outline btn-sm" title="<?= $ar ? 'تنزيل PDF' : 'Download PDF' ?>">
                                            <i class="fas fa-file-pdf"></i>
                                        </a>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                    <a href="receipt.php?ref=<?= urlencode($tx['reference']) ?>" class="btn btn-info btn-sm" title="<?= $ar ? 'عرض الإيصال' : 'View receipt' ?>">
                                        <i class="fas fa-receipt"></i>
                                    </a>
                                    <?php if ($tx['status'] === 'pending'): ?>
                                        <button type="button" onclick="updateStatus('<?= htmlspecialchars($tx['reference'], ENT_QUOTES) ?>')" class="btn btn-primary btn-sm" title="<?= $ar ? 'تحديث الحالة' : 'Refresh status' ?>">
                                            <i class="fas fa-sync"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if (!in_array($tx['status'], ['refunded','chargeback','failed'], true)): ?>
                                        <button type="button" class="btn btn-danger btn-sm" onclick="processRefund('<?= htmlspecialchars($tx['reference'], ENT_QUOTES) ?>', '<?= number_format((float)($tx['amount'] ?? 0), 2, '.', '') ?>')" title="<?= $ar ? 'استرداد المبلغ' : 'Refund' ?>">
                                            <i class="fas fa-undo-alt"></i>
                                        </button>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="txn-manage" style="display:flex;flex-direction:column;gap:6px;min-width:160px;">
                                        <select class="txn-edit-status" style="font-size:.7rem;padding:4px;border-radius:6px;background:#0a1020;color:var(--text-gold);border:1px solid rgba(255,215,0,.2);">
                                            <?php foreach (['pending','authorized','captured','settled','completed','failed','refunded','chargeback','cancelled'] as $stOpt): ?>
                                                <option value="<?= $stOpt ?>" <?= ($tx['status'] === $stOpt) ? 'selected' : '' ?>><?= htmlspecialchars($stOpt) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="number" step="0.01" class="txn-edit-amount" value="<?= htmlspecialchars((string)$tx['amount']) ?>"
                                               style="font-size:.7rem;padding:4px;border-radius:6px;background:#0a1020;color:var(--text-gold);border:1px solid rgba(255,215,0,.2);"
                                               title="Amount">
                                        <input type="text" class="txn-edit-name" value="<?= htmlspecialchars($tx['customer_name'] ?? '') ?>"
                                               placeholder="Customer"
                                               style="font-size:.7rem;padding:4px;border-radius:6px;background:#0a1020;color:var(--text-gold);border:1px solid rgba(255,215,0,.2);">
                                        <div style="display:flex;gap:4px;">
                                            <button type="button" class="btn btn-success btn-sm" title="Save"
                                                    onclick="saveTxnRow(this, <?= (int)$tx['id'] ?>)">
                                                <i class="fas fa-save"></i>
                                            </button>
                                            <button type="button" class="btn btn-danger btn-sm" title="Delete"
                                                    onclick="deleteTxnRow(<?= (int)$tx['id'] ?>, '<?= htmlspecialchars($tx['reference'], ENT_QUOTES) ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- ===== ترقيم الصفحات ===== -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="disabled"><i class="fas fa-chevron-right"></i></span>
                    <?php endif; ?>
                    
                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    for ($i = $startPage; $i <= $endPage; $i++):
                    ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" class="<?= $i === $page ? 'active' : '' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    <?php else: ?>
                        <span class="disabled"><i class="fas fa-chevron-left"></i></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ============================================================
     JavaScript
============================================================ -->
<script>
// ============================================================
// تحديث حالة المعاملة
// ============================================================
const UI_AR = <?= $ar ? 'true' : 'false' ?>;
function updateStatus(reference) {
    if (!confirm(UI_AR ? 'هل تريد تحديث حالة هذه المعاملة؟' : 'Update this transaction status?')) return;
    
    const btn = event.target.closest('button');
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    btn.disabled = true;
    
    fetch('api/update_transaction.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=check_status&csrf_token=' + encodeURIComponent('<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>') + '&reference=' + encodeURIComponent(reference)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('❌ ' + data.message);
            btn.innerHTML = originalHtml;
            btn.disabled = false;
        }
    })
    .catch(error => {
        alert('❌ ' + (UI_AR ? 'حدث خطأ: ' : 'Error: ') + error.message);
        btn.innerHTML = originalHtml;
        btn.disabled = false;
    });
}

// ============================================================
// استرداد المعاملة
// ============================================================
function processRefund(reference, amount) {
    if (!confirm(UI_AR ? 'هل تريد استرداد مبلغ هذه المعاملة؟' : 'Refund this transaction?')) return;

    const btn = event.target.closest('button');
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    btn.disabled = true;

    fetch('api/refund_transaction.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'csrf_token=' + encodeURIComponent('<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>') + '&reference=' + encodeURIComponent(reference) + '&amount=' + encodeURIComponent(amount) + '&reason=' + encodeURIComponent('Refund requested from transactions page')
    })
    .then(response => response.json())
    .then(data => {
        alert(data.message || (UI_AR ? 'تمت العملية' : 'Done'));
        if (data.success) location.reload();
        else {
            btn.innerHTML = originalHtml;
            btn.disabled = false;
        }
    })
    .catch(error => {
        alert('❌ ' + (UI_AR ? 'حدث خطأ: ' : 'Error: ') + error.message);
        btn.innerHTML = originalHtml;
        btn.disabled = false;
    });
}

const TXN_CSRF = <?= json_encode($csrfToken) ?>;

function manageTxn(body) {
    return fetch('api/manage_transaction.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ csrf_token: TXN_CSRF, ...body }).toString(),
        credentials: 'same-origin',
    }).then(r => r.json());
}

function saveTxnRow(btn, id) {
    const box = btn.closest('.txn-manage');
    const status = box.querySelector('.txn-edit-status').value;
    const amount = box.querySelector('.txn-edit-amount').value;
    const customer_name = box.querySelector('.txn-edit-name').value;
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    manageTxn({ action: 'update', id: String(id), status, amount, customer_name })
        .then(data => {
            if (data.success) location.reload();
            else {
                alert('❌ ' + (data.message || 'Update failed'));
                btn.innerHTML = original;
                btn.disabled = false;
            }
        })
        .catch(err => {
            alert('❌ ' + err.message);
            btn.innerHTML = original;
            btn.disabled = false;
        });
}

function deleteTxnRow(id, reference) {
    if (!confirm((UI_AR ? 'حذف العملية نهائياً؟\n' : 'Permanently delete this transaction?\n') + reference)) return;
    manageTxn({ action: 'delete', id: String(id), reference })
        .then(data => {
            if (data.success) location.reload();
            else alert('❌ ' + (data.message || 'Delete failed'));
        })
        .catch(err => alert('❌ ' + err.message));
}

function clearFailedTxns() {
    if (!confirm(UI_AR ? 'مسح كل العمليات Failed؟' : 'Clear all Failed transactions?')) return;
    manageTxn({ action: 'clear_failed' })
        .then(data => {
            alert(data.message || 'Done');
            if (data.success) location.reload();
        })
        .catch(err => alert('❌ ' + err.message));
}

// ============================================================
// تصدير CSV
// ============================================================
document.querySelector('a[href*="export=csv"]')?.addEventListener('click', function(e) {
    // يمكن إضافة منطق للتصدير
});

// ============================================================
// تحديث تلقائي كل 30 ثانية
// ============================================================
setInterval(function() {
    // تحديث الإحصائيات فقط
    fetch(window.location.href + '&ajax=stats')
    .then(response => response.json())
    .then(data => {
        // تحديث الإحصائيات
    })
    .catch(() => {});
}, 30000);
</script>

</body>
</html>

<?php
// ============================================================
// [7] تصدير CSV
// ============================================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="transactions_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Reference', 'Customer', 'Email', 'Amount', 'Currency', 'Gateway', 'Operation', 'Protocol', 'Status', 'Date']);
    
    foreach ($transactions as $tx) {
        fputcsv($output, [
            $tx['reference'],
            $tx['customer_name'] ?? '',
            $tx['customer_email'] ?? '',
            $tx['amount'],
            $tx['currency'],
            $tx['gateway'],
            $tx['transaction_label'] ?? '',
            function_exists('protocol_display_name') ? protocol_display_name($tx['protocol'] ?? '') : ($tx['protocol'] ?? ''),
            getStatusLabel($tx['status']),
            $tx['created_at']
        ]);
    }
    
    fclose($output);
    exit();
}
?>
