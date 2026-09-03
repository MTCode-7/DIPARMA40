<?php
/**
 * ============================================================
 * DI PARMA | روابط الدفع - Payment Links
 * ============================================================
 * نظام إنشاء وإدارة روابط الدفع المباشرة
 * ============================================================
 */

// التحقق من المصادقة
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/performance.php';
require_once __DIR__ . '/includes/db_optimized.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';

// ============================================================
// [1] معالجة الطلبات
// ============================================================

$db = db();
dp_ensure_indexes();
$message = '';
$messageType = '';

// إنشاء رابط دفع جديد
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_link'])) {
    $linkData = [
        'title' => trim($_POST['title'] ?? ''),
        'amount' => floatval($_POST['amount'] ?? 0),
        'currency' => trim($_POST['currency'] ?? 'USD'),
        'gateway' => trim($_POST['gateway'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'customer_name' => trim($_POST['customer_name'] ?? ''),
        'customer_email' => trim($_POST['customer_email'] ?? ''),
        'customer_phone' => trim($_POST['customer_phone'] ?? ''),
        'expiry_days' => intval($_POST['expiry_days'] ?? 7),
        'max_uses' => intval($_POST['max_uses'] ?? 0),
        'redirect_url' => trim($_POST['redirect_url'] ?? ''),
        'protocol' => trim($_POST['protocol'] ?? '101.0'),
        'payment_type' => trim($_POST['payment_type'] ?? 'one_time')
    ];
    
    // التحقق من البيانات
    if (empty($linkData['title']) || $linkData['amount'] <= 0 || empty($linkData['gateway'])) {
        $message = '❌ يرجى ملء جميع الحقول المطلوبة';
        $messageType = 'error';
    } else {
        // توليد معرف فريد للرابط
        $linkId = strtoupper(substr($linkData['gateway'], 0, 3)) . date('Ymd') . bin2hex(random_bytes(4));
        $token = bin2hex(random_bytes(32));
        $slug = generateSlug($linkData['title']);
        
        // حساب تاريخ الانتهاء
        $expiryDate = date('Y-m-d H:i:s', strtotime("+{$linkData['expiry_days']} days"));
        
        // إدخال الرابط في قاعدة البيانات
        $insertData = [
            'link_id' => $linkId,
            'token' => $token,
            'slug' => $slug,
            'title' => $linkData['title'],
            'description' => $linkData['description'],
            'amount' => $linkData['amount'],
            'currency' => $linkData['currency'],
            'gateway' => $linkData['gateway'],
            'protocol' => $linkData['protocol'],
            'payment_type' => $linkData['payment_type'],
            'customer_name' => $linkData['customer_name'],
            'customer_email' => $linkData['customer_email'],
            'customer_phone' => $linkData['customer_phone'],
            'redirect_url' => $linkData['redirect_url'],
            'expiry_date' => $expiryDate,
            'max_uses' => $linkData['max_uses'],
            'uses_count' => 0,
            'status' => 'active',
            'user_id' => $_SESSION['user_id'],
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        try {
            $id = $db->insert('payment_links', $insertData);
            
            if ($id > 0) {
                $linkUrl = SITE_URL . '/pay.php?link=' . $linkId . '&token=' . $token;
                $message = '✅ تم إنشاء رابط الدفع بنجاح!';
                $messageType = 'success';
                
                // حفظ الرابط في الجلسة لعرضه
                $_SESSION['new_link'] = [
                    'id' => $linkId,
                    'url' => $linkUrl,
                    'token' => $token,
                    'slug' => $slug
                ];
            } else {
                $message = '❌ فشل في إنشاء الرابط';
                $messageType = 'error';
            }
        } catch (Exception $e) {
            $message = '❌ خطأ: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// حذف رابط
if (isset($_GET['delete']) && isset($_GET['token'])) {
    $id = intval($_GET['delete']);
    $token = $_GET['token'];
    
    // التحقق من صحة الطلب
    if (hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        try {
            $db->update('payment_links', ['status' => 'deleted'], ['id' => $id, 'user_id' => $_SESSION['user_id']]);
            $message = '✅ تم حذف الرابط بنجاح';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = '❌ فشل في حذف الرابط';
            $messageType = 'error';
        }
    }
}

// تعطيل/تفعيل رابط
if (isset($_GET['toggle']) && isset($_GET['token'])) {
    $id = intval($_GET['toggle']);
    $token = $_GET['token'];
    
    if (hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        try {
            $link = $db->find('payment_links', ['id' => $id, 'user_id' => $_SESSION['user_id']]);
            if ($link) {
                $newStatus = $link['status'] === 'active' ? 'inactive' : 'active';
                $db->update('payment_links', ['status' => $newStatus], ['id' => $id]);
                $message = '✅ تم ' . ($newStatus === 'active' ? 'تفعيل' : 'تعطيل') . ' الرابط بنجاح';
                $messageType = 'success';
            }
        } catch (Exception $e) {
            $message = '❌ فشل في تغيير الحالة';
            $messageType = 'error';
        }
    }
}

// ============================================================
// [2] إنشاء جداول النظام المتكامل إن لم تكن موجودة
// ============================================================
try {
    $db->execute("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "payment_links` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `link_id` VARCHAR(100) NOT NULL UNIQUE,
        `token` VARCHAR(255) NOT NULL,
        `slug` VARCHAR(255) NOT NULL,
        `title` VARCHAR(255) NOT NULL,
        `description` TEXT DEFAULT NULL,
        `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `currency` VARCHAR(10) NOT NULL DEFAULT 'AED',
        `gateway` VARCHAR(50) NOT NULL DEFAULT 'nuvei',
        `protocol` VARCHAR(50) DEFAULT '101.0',
        `payment_type` VARCHAR(50) DEFAULT 'one_time',
        `customer_name` VARCHAR(255) DEFAULT NULL,
        `customer_email` VARCHAR(255) DEFAULT NULL,
        `customer_phone` VARCHAR(255) DEFAULT NULL,
        `redirect_url` TEXT DEFAULT NULL,
        `expiry_date` DATETIME DEFAULT NULL,
        `max_uses` INT DEFAULT 0,
        `uses_count` INT DEFAULT 0,
        `status` VARCHAR(30) NOT NULL DEFAULT 'active',
        `user_id` INT UNSIGNED DEFAULT 0,
        `created_at` DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->execute("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "wallets` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT UNSIGNED NOT NULL,
        `currency` VARCHAR(10) NOT NULL DEFAULT 'AED',
        `balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `status` VARCHAR(30) NOT NULL DEFAULT 'active',
        `created_at` DATETIME NOT NULL,
        UNIQUE KEY `uniq_wallet` (`user_id`, `currency`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->execute("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "invoices` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `invoice_number` VARCHAR(100) NOT NULL UNIQUE,
        `reference` VARCHAR(100) NOT NULL,
        `user_id` INT UNSIGNED DEFAULT 0,
        `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `currency` VARCHAR(10) NOT NULL DEFAULT 'AED',
        `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
        `gateway` VARCHAR(50) NOT NULL DEFAULT 'nuvei',
        `description` TEXT DEFAULT NULL,
        `created_at` DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->execute("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "ledger` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT UNSIGNED NOT NULL,
        `type` VARCHAR(20) NOT NULL,
        `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `currency` VARCHAR(10) NOT NULL DEFAULT 'AED',
        `reference` VARCHAR(100) NOT NULL,
        `description` TEXT DEFAULT NULL,
        `created_at` DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    // ignore setup failures in this request
}

// ============================================================
// [3] جلب الروابط من قاعدة البيانات
// ============================================================

// الروابط النشطة
$activeLinks = $db->query("
    SELECT * FROM " . DB_PREFIX . "payment_links 
    WHERE user_id = ? AND status = 'active'
    ORDER BY created_at DESC
", [$_SESSION['user_id']]);

// الروابط غير النشطة
$inactiveLinks = $db->query("
    SELECT * FROM " . DB_PREFIX . "payment_links 
    WHERE user_id = ? AND status IN ('inactive', 'expired', 'deleted')
    ORDER BY created_at DESC
", [$_SESSION['user_id']]);

// ============================================================
// [4] توليد CSRF Token
// ============================================================
$csrfToken = generateCsrfToken();

// ============================================================
// [5] عرض الصفحة
// ============================================================
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang ?? 'en') ?>" dir="<?= htmlspecialchars($pageDir ?? 'ltr') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DI PARMA | روابط الدفع</title>
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
        
        /* ===== الإحصائيات ===== */
        .stats-grid {
            display:grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap:12px;
            margin-bottom:25px;
        }
        .stat-card {
            background:var(--bg-card);
            border:1px solid var(--border-gold);
            border-radius:12px;
            padding:15px 18px;
            text-align:center;
        }
        .stat-card .number {
            font-size:1.8rem;
            font-weight:800;
            color:var(--gold);
        }
        .stat-card .label {
            font-size:0.7rem;
            color:#888;
            margin-top:4px;
        }
        
        /* ===== نموذج إنشاء رابط ===== */
        .form-section {
            background:var(--bg-card);
            border:1px solid var(--border-gold);
            border-radius:16px;
            padding:25px;
            margin-bottom:25px;
        }
        .form-section h2 {
            color:var(--text-light);
            font-size:1.2rem;
            margin-bottom:20px;
            display:flex;
            align-items:center;
            gap:10px;
        }
        .form-section h2 i { color:var(--gold); }
        
        .form-grid {
            display:grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap:15px;
        }
        .form-group {
            display:flex;
            flex-direction:column;
            gap:5px;
        }
        .form-group label {
            color:var(--text-gold);
            font-size:0.8rem;
            font-weight:600;
        }
        .form-group label i { color:var(--gold); margin-left:6px; }
        .form-group input, .form-group select, .form-group textarea {
            padding:10px 14px;
            background:rgba(0,0,0,0.8);
            border:1px solid rgba(255,255,255,0.08);
            border-radius:10px;
            color:var(--text-light);
            font-family:'Cairo',sans-serif;
            font-size:0.9rem;
            transition:all 0.3s ease;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline:none;
            border-color:var(--gold);
            box-shadow:0 0 20px rgba(255,215,0,0.05);
        }
        .form-group textarea { min-height:60px; resize:vertical; }
        .form-group .hint {
            font-size:0.65rem;
            color:#666;
            margin-top:2px;
        }
        
        .form-actions {
            display:flex;
            gap:10px;
            margin-top:20px;
            flex-wrap:wrap;
        }
        .btn {
            padding:10px 25px;
            border:none;
            border-radius:10px;
            font-family:'Cairo',sans-serif;
            font-weight:600;
            cursor:pointer;
            transition:all 0.3s ease;
            display:inline-flex;
            align-items:center;
            gap:8px;
            text-decoration:none;
            font-size:0.9rem;
        }
        .btn-primary {
            background:linear-gradient(135deg,var(--gold-light),var(--gold));
            color:var(--bg-dark);
        }
        .btn-primary:hover {
            transform:translateY(-2px);
            box-shadow:0 8px 25px rgba(255,215,0,0.3);
        }
        .btn-success { background:var(--success); color:white; }
        .btn-danger { background:var(--danger); color:white; }
        .btn-warning { background:var(--warning); color:white; }
        .btn-info { background:var(--info); color:white; }
        .btn-outline {
            background:transparent;
            border:1px solid var(--border-gold);
            color:var(--text-gold);
        }
        .btn-outline:hover {
            background:rgba(255,215,0,0.05);
        }
        .btn-sm { padding:5px 12px; font-size:0.75rem; }
        
        /* ===== الروابط ===== */
        .links-section {
            background:var(--bg-card);
            border:1px solid var(--border-gold);
            border-radius:16px;
            padding:25px;
            margin-bottom:25px;
        }
        .links-section .header {
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:20px;
            flex-wrap:wrap;
            gap:10px;
        }
        .links-section .header h3 {
            color:var(--text-light);
            font-size:1rem;
            display:flex;
            align-items:center;
            gap:10px;
        }
        .links-section .header h3 i { color:var(--gold); }
        
        .link-card {
            background:rgba(0,0,0,0.3);
            border:1px solid rgba(255,255,255,0.05);
            border-radius:12px;
            padding:18px;
            margin-bottom:12px;
            transition:all 0.3s ease;
        }
        .link-card:hover {
            border-color:var(--border-gold);
        }
        .link-card .top {
            display:flex;
            justify-content:space-between;
            align-items:start;
            flex-wrap:wrap;
            gap:10px;
            margin-bottom:10px;
        }
        .link-card .title {
            color:var(--text-light);
            font-weight:600;
            font-size:1rem;
        }
        .link-card .title i { color:var(--gold); margin-left:8px; }
        .link-card .link-url {
            color:var(--gold);
            font-size:0.8rem;
            direction:ltr;
            word-break:break-all;
            background:rgba(0,0,0,0.4);
            padding:8px 12px;
            border-radius:8px;
            margin:8px 0;
            display:flex;
            align-items:center;
            gap:10px;
            flex-wrap:wrap;
        }
        .link-card .link-url .copy-btn {
            background:rgba(255,215,0,0.1);
            border:1px solid var(--border-gold);
            border-radius:6px;
            padding:4px 12px;
            color:var(--gold);
            cursor:pointer;
            font-size:0.7rem;
            font-family:'Cairo',sans-serif;
            transition:all 0.3s ease;
        }
        .link-card .link-url .copy-btn:hover {
            background:rgba(255,215,0,0.2);
        }
        .link-card .details {
            display:grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap:8px;
            margin:10px 0;
            font-size:0.75rem;
            color:#888;
        }
        .link-card .details span {
            display:flex;
            align-items:center;
            gap:4px;
        }
        .link-card .details i { color:var(--gold); }
        .link-card .actions {
            display:flex;
            gap:8px;
            flex-wrap:wrap;
            margin-top:10px;
            padding-top:10px;
            border-top:1px solid rgba(255,255,255,0.05);
        }
        .link-card .status-badge {
            padding:3px 12px;
            border-radius:20px;
            font-size:0.65rem;
            font-weight:600;
        }
        .status-active { background:rgba(76,175,80,0.15); color:var(--success); }
        .status-inactive { background:rgba(217,83,79,0.15); color:var(--danger); }
        .status-expired { background:rgba(240,173,78,0.15); color:var(--warning); }
        .status-deleted { background:rgba(155,89,182,0.15); color:var(--purple); }
        
        /* ===== Toast ===== */
        .toast {
            position:fixed;
            top:20px;
            left:20px;
            padding:15px 25px;
            border-radius:12px;
            background:var(--bg-card);
            border:1px solid var(--border-gold);
            color:var(--text-gold);
            z-index:9999;
            display:none;
            animation:slideIn 0.3s ease;
            max-width:450px;
        }
        .toast.success { border-color:var(--success); color:var(--success); }
        .toast.error { border-color:var(--danger); color:var(--danger); }
        .toast.info { border-color:var(--info); color:var(--info); }
        @keyframes slideIn {
            from { transform:translateX(-100px); opacity:0; }
            to { transform:translateX(0); opacity:1; }
        }
        
        /* ===== استجابة ===== */
        @media (max-width: 768px) {
            .navbar { flex-direction:column; text-align:center; padding:1rem; }
            .nav-links { justify-content:center; flex-wrap:wrap; }
            .form-grid { grid-template-columns:1fr; }
            .link-card .top { flex-direction:column; }
            .link-card .link-url { flex-direction:column; align-items:stretch; }
        }
        
        .empty-state {
            text-align:center;
            padding:40px 20px;
            color:#666;
        }
        .empty-state i {
            font-size:4rem;
            display:block;
            margin-bottom:15px;
            color:var(--border-gold);
        }
        
        ::-webkit-scrollbar { width:6px; }
        ::-webkit-scrollbar-track { background:var(--bg-dark); }
        ::-webkit-scrollbar-thumb { background:var(--gold); border-radius:3px; }
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
            <a href="index.php" class="nav-link"><i class="fas fa-home"></i> الرئيسية</a>
            <a href="dashboard.php" class="nav-link"><i class="fas fa-chart-pie"></i> لوحة التحكم</a>
            <a href="links.php" class="nav-link active"><i class="fas fa-link"></i> روابط الدفع</a>
            <a href="transactions.php" class="nav-link"><i class="fas fa-list"></i> المعاملات</a>
            <a href="crypto.php" class="nav-link"><i class="fas fa-coins"></i> Crypto</a>
            <a href="wallets.php" class="nav-link"><i class="fas fa-wallet"></i> المحفظة</a>
            <a href="invoices.php" class="nav-link"><i class="fas fa-file-invoice"></i> الفواتير</a>
            <a href="approvals.php" class="nav-link"><i class="fas fa-check-double"></i> الموافقات</a>
            <a href="admin/gateway_manager.php" class="nav-link"><i class="fas fa-route"></i> البوابات</a>
            <a href="admin/gateway_manager.php?profile=true" class="nav-link"><i class="fas fa-user-cog"></i> تغيير الحساب</a>
            <a href="logout.php" class="nav-link logout"><i class="fas fa-sign-out-alt"></i></a>
        </div>
    </nav>

    <!-- ===== الإحصائيات ===== -->
    <?php
    $totalLinks = count($activeLinks) + count($inactiveLinks);
    $totalAmount = array_sum(array_column($activeLinks, 'amount'));
    $totalUses = array_sum(array_column($activeLinks, 'uses_count'));
    ?>
    <div class="stats-grid fade-in">
        <div class="stat-card">
            <div class="number"><?= $totalLinks ?></div>
            <div class="label">إجمالي الروابط</div>
        </div>
        <div class="stat-card">
            <div class="number" style="color:var(--success);"><?= count($activeLinks) ?></div>
            <div class="label">روابط نشطة</div>
        </div>
        <div class="stat-card">
            <div class="number" style="color:var(--gold);"><?= number_format($totalAmount, 2) ?></div>
            <div class="label">إجمالي المبلغ</div>
        </div>
        <div class="stat-card">
            <div class="number" style="color:var(--info);"><?= $totalUses ?></div>
            <div class="label">إجمالي الاستخدامات</div>
        </div>
    </div>

    <!-- ===== رسائل ===== -->
    <?php if ($message): ?>
        <div class="toast <?= $messageType ?>" style="display:block;position:static;margin-bottom:20px;">
            <?= $message ?>
        </div>
    <?php endif; ?>

    <!-- ===== نموذج إنشاء رابط ===== -->
    <div class="form-section fade-in">
        <h2><i class="fas fa-plus-circle"></i> إنشاء رابط دفع جديد</h2>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            
            <div class="form-grid">
                <div class="form-group">
                    <label><i class="fas fa-tag"></i> عنوان الرابط</label>
                    <input type="text" name="title" placeholder="مثال: فاتورة رقم 123" required>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-dollar-sign"></i> المبلغ</label>
                    <input type="number" name="amount" step="0.01" min="0.01" placeholder="0.00" required>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-globe"></i> العملة</label>
                    <select name="currency">
                        <option value="USD">USD - دولار</option>
                        <option value="EUR">EUR - يورو</option>
                        <option value="GBP">GBP - جنيه</option>
                        <option value="AED" selected>AED - درهم</option>
                        <option value="SAR">SAR - ريال</option>
                        <option value="KWD">KWD - دينار</option>
                        <option value="BHD">BHD - دينار</option>
                        <option value="QAR">QAR - ريال</option>
                    </select>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-credit-card"></i> بوابة الدفع</label>
                    <select name="gateway" required>
                        <?php
                        $activeGateways = $db->query("SELECT * FROM " . DB_PREFIX . "payment_gateways WHERE status = 'active'");
                        foreach ($activeGateways as $gw):
                        ?>
                            <option value="<?= $gw['code'] ?>"><?= htmlspecialchars($gw['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-microchip"></i> البروتوكول</label>
                    <select name="protocol">
                        <option value="101.0">💳 101.0 - سحب مباشر</option>
                        <option value="101.1">🔒 101.1 - تفويض وتسوية</option>
                        <option value="201.3">🏢 201.3 - تسوية شركات</option>
                        <option value="801.9">801.9 - الأمان الأساسي</option>
                    </select>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-clock"></i> صلاحية الرابط (أيام)</label>
                    <input type="number" name="expiry_days" value="7" min="1" max="365">
                    <div class="hint">بعد انتهاء الصلاحية لن يعمل الرابط</div>
                </div>
                <div class="form-group" style="grid-column: span 2;">
                    <label><i class="fas fa-align-left"></i> الوصف</label>
                    <textarea name="description" placeholder="وصف مختصر للمعاملة"></textarea>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-user"></i> اسم العميل <span style="color:#888;font-size:0.8rem;font-weight:400;">(اختياري)</span></label>
                    <input type="text" name="customer_name" placeholder="اسم العميل">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-envelope"></i> بريد العميل <span style="color:#888;font-size:0.8rem;font-weight:400;">(اختياري)</span></label>
                    <input type="email" name="customer_email" placeholder="customer@example.com">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-phone"></i> جوال العميل <span style="color:#888;font-size:0.8rem;font-weight:400;">(اختياري)</span></label>
                    <input type="tel" name="customer_phone" placeholder="+971 50 123 4567">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-link"></i> رابط إعادة التوجيه</label>
                    <input type="url" name="redirect_url" placeholder="https://example.com/thankyou">
                    <div class="hint">رابط يعيد توجيه العميل بعد الدفع</div>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-refresh"></i> الحد الأقصى للاستخدام</label>
                    <input type="number" name="max_uses" value="0" min="0" placeholder="0 = غير محدود">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-tag"></i> نوع الدفع</label>
                    <select name="payment_type">
                        <option value="one_time">دفعة واحدة</option>
                        <option value="recurring">دفع متكرر</option>
                        <option value="installment">دفع بالتقسيط</option>
                    </select>
                </div>
            </div>
            
            <div class="form-actions">
                <button type="submit" name="create_link" class="btn btn-primary">
                    <i class="fas fa-plus"></i> إنشاء الرابط
                </button>
                <button type="reset" class="btn btn-outline">
                    <i class="fas fa-undo"></i> إعادة تعيين
                </button>
            </div>
        </form>
    </div>

    <!-- ===== عرض الرابط الجديد ===== -->
    <?php if (isset($_SESSION['new_link'])): ?>
        <div class="form-section" style="border-color:var(--success);">
            <h2 style="color:var(--success);"><i class="fas fa-check-circle"></i> تم إنشاء الرابط بنجاح!</h2>
            <div style="background:rgba(76,175,80,0.05);border-radius:12px;padding:15px;margin-bottom:15px;">
                <div style="font-size:0.8rem;color:#888;margin-bottom:5px;">رابط الدفع:</div>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <code style="background:rgba(0,0,0,0.5);padding:10px 15px;border-radius:8px;color:var(--gold);word-break:break-all;flex:1;direction:ltr;">
                        <?= htmlspecialchars($_SESSION['new_link']['url']) ?>
                    </code>
                    <button onclick="copyLink('<?= htmlspecialchars($_SESSION['new_link']['url']) ?>')" class="btn btn-success btn-sm">
                        <i class="fas fa-copy"></i> نسخ
                    </button>
                    <a href="<?= htmlspecialchars($_SESSION['new_link']['url']) ?>" target="_blank" class="btn btn-primary btn-sm">
                        <i class="fas fa-external-link-alt"></i> فتح
                    </a>
                </div>
                <div style="font-size:0.7rem;color:#888;margin-top:8px;">
                    معرف الرابط: <strong style="color:var(--gold);"><?= $_SESSION['new_link']['id'] ?></strong>
                    • الرابط المختصر: <strong style="color:var(--gold);"><?= SITE_URL ?>/p/<?= $_SESSION['new_link']['slug'] ?></strong>
                </div>
            </div>
            <?php unset($_SESSION['new_link']); ?>
        </div>
    <?php endif; ?>

    <!-- ===== الروابط النشطة ===== -->
    <div class="links-section fade-in">
        <div class="header">
            <h3><i class="fas fa-link"></i> الروابط النشطة (<?= count($activeLinks) ?>)</h3>
            <span style="font-size:0.7rem;color:#888;">
                <i class="fas fa-info-circle"></i> الروابط النشطة قابلة للاستخدام
            </span>
        </div>
        
        <?php if (empty($activeLinks)): ?>
            <div class="empty-state">
                <i class="fas fa-link"></i>
                <p>لا توجد روابط دفع نشطة</p>
                <button onclick="document.querySelector('.form-section').scrollIntoView({behavior:'smooth'})" class="btn btn-primary" style="margin-top:15px;">
                    <i class="fas fa-plus"></i> إنشاء رابط جديد
                </button>
            </div>
        <?php else: ?>
            <?php foreach ($activeLinks as $link): ?>
                <div class="link-card">
                    <div class="top">
                        <div>
                            <div class="title">
                                <i class="fas fa-link"></i> <?= htmlspecialchars($link['title']) ?>
                            </div>
                            <div style="font-size:0.7rem;color:#888;margin-top:4px;">
                                معرف: <strong style="color:var(--gold);"><?= $link['link_id'] ?></strong>
                            </div>
                        </div>
                        <div>
                            <span class="status-badge status-active">✓ نشط</span>
                            <span style="font-size:0.7rem;color:#888;margin-right:8px;">
                                يستخدم: <?= $link['uses_count'] ?>/<?= $link['max_uses'] > 0 ? $link['max_uses'] : '∞' ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="link-url">
                        <code style="flex:1;word-break:break-all;direction:ltr;">
                            <?= SITE_URL ?>/pay.php?link=<?= $link['link_id'] ?>&token=<?= $link['token'] ?>
                        </code>
                        <button onclick="copyLink('<?= SITE_URL ?>/pay.php?link=<?= $link['link_id'] ?>&token=<?= $link['token'] ?>')" class="copy-btn">
                            <i class="fas fa-copy"></i> نسخ
                        </button>
                        <button onclick="copyLink('<?= SITE_URL ?>/p/<?= $link['slug'] ?>')" class="copy-btn">
                            <i class="fas fa-shortcode"></i> مختصر
                        </button>
                    </div>
                    
                    <div class="details">
                        <span><i class="fas fa-dollar-sign"></i> <?= number_format($link['amount'], 2) ?> <?= $link['currency'] ?></span>
                        <span><i class="fas fa-credit-card"></i> <?= $link['gateway'] ?></span>
                        <span><i class="fas fa-microchip"></i> <?= $link['protocol'] ?></span>
                        <span><i class="fas fa-calendar"></i> ينتهي: <?= date('d/m/Y', strtotime($link['expiry_date'])) ?></span>
                        <span><i class="fas fa-clock"></i> أنشئ: <?= date('d/m/Y', strtotime($link['created_at'])) ?></span>
                    </div>
                    
                    <div class="actions">
                        <a href="<?= SITE_URL ?>/pay.php?link=<?= $link['link_id'] ?>&token=<?= $link['token'] ?>" target="_blank" class="btn btn-success btn-sm">
                            <i class="fas fa-external-link-alt"></i> فتح
                        </a>
                        <a href="?toggle=<?= $link['id'] ?>&token=<?= $csrfToken ?>" class="btn btn-warning btn-sm" onclick="return confirm('هل أنت متأكد من تعطيل هذا الرابط؟')">
                            <i class="fas fa-pause"></i> تعطيل
                        </a>
                        <a href="?delete=<?= $link['id'] ?>&token=<?= $csrfToken ?>" class="btn btn-danger btn-sm" onclick="return confirm('⚠️ هل أنت متأكد من حذف هذا الرابط نهائياً؟')">
                            <i class="fas fa-trash"></i> حذف
                        </a>
                        <button onclick="generateQR('<?= $link['link_id'] ?>', '<?= $link['token'] ?>')" class="btn btn-info btn-sm">
                            <i class="fas fa-qrcode"></i> QR
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ===== الروابط غير النشطة ===== -->
    <?php if (!empty($inactiveLinks)): ?>
        <div class="links-section fade-in" style="border-color:rgba(255,255,255,0.05);">
            <div class="header">
                <h3 style="color:#888;"><i class="fas fa-archive"></i> الروابط غير النشطة (<?= count($inactiveLinks) ?>)</h3>
            </div>
            
            <?php foreach ($inactiveLinks as $link): ?>
                <div class="link-card" style="opacity:0.6;">
                    <div class="top">
                        <div>
                            <div class="title" style="color:#888;">
                                <i class="fas fa-link"></i> <?= htmlspecialchars($link['title']) ?>
                            </div>
                            <div style="font-size:0.7rem;color:#666;margin-top:4px;">
                                معرف: <?= $link['link_id'] ?>
                            </div>
                        </div>
                        <div>
                            <span class="status-badge status-<?= $link['status'] ?>">
                                <?php
                                $statusLabels = [
                                    'inactive' => '⏸ معطل',
                                    'expired' => '⏰ منتهي',
                                    'deleted' => '🗑 محذوف'
                                ];
                                echo $statusLabels[$link['status']] ?? $link['status'];
                                ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="details">
                        <span><i class="fas fa-dollar-sign"></i> <?= number_format($link['amount'], 2) ?> <?= $link['currency'] ?></span>
                        <span><i class="fas fa-credit-card"></i> <?= $link['gateway'] ?></span>
                        <span><i class="fas fa-calendar"></i> أنشئ: <?= date('d/m/Y', strtotime($link['created_at'])) ?></span>
                    </div>
                    
                    <div class="actions">
                        <?php if ($link['status'] !== 'deleted'): ?>
                            <a href="?toggle=<?= $link['id'] ?>&token=<?= $csrfToken ?>" class="btn btn-success btn-sm" onclick="return confirm('هل تريد إعادة تفعيل هذا الرابط؟')">
                                <i class="fas fa-play"></i> إعادة تفعيل
                            </a>
                        <?php endif; ?>
                        <?php if ($link['status'] === 'expired'): ?>
                            <button onclick="extendLink(<?= $link['id'] ?>)" class="btn btn-warning btn-sm">
                                <i class="fas fa-clock"></i> تمديد
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ============================================================
     JavaScript
============================================================ -->
<script>
// ============================================================
// نسخ الرابط
// ============================================================
function copyLink(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => {
            showToast('✅ تم نسخ الرابط بنجاح!', 'success');
        }).catch(() => {
            fallbackCopy(text);
        });
    } else {
        fallbackCopy(text);
    }
}

function fallbackCopy(text) {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    try {
        document.execCommand('copy');
        showToast('✅ تم نسخ الرابط بنجاح!', 'success');
    } catch (err) {
        showToast('❌ فشل في نسخ الرابط', 'error');
    }
    document.body.removeChild(textarea);
}

// ============================================================
// Toast Notifications
// ============================================================
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = 'toast ' + type;
    toast.textContent = message;
    toast.style.display = 'block';
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.5s ease';
        setTimeout(() => toast.remove(), 500);
    }, 3000);
}

// ============================================================
// توليد QR Code
// ============================================================
function generateQR(linkId, token) {
    const url = '<?= SITE_URL ?>/pay.php?link=' + linkId + '&token=' + token;
    window.open('https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' + encodeURIComponent(url), '_blank');
}

// ============================================================
// تمديد صلاحية الرابط
// ============================================================
function extendLink(id) {
    const days = prompt('أدخل عدد الأيام للتمديد:', '7');
    if (days && !isNaN(days) && days > 0) {
        const formData = new FormData();
        formData.append('action', 'extend');
        formData.append('id', id);
        formData.append('days', days);
        formData.append('csrf_token', '<?= $csrfToken ?>');
        
        fetch('api/extend_link.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('✅ تم تمديد الرابط بنجاح!', 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast('❌ ' + data.message, 'error');
            }
        })
        .catch(() => {
            showToast('❌ حدث خطأ في التمديد', 'error');
        });
    }
}

// ============================================================
// تفعيل التمرير السلس
// ============================================================
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function(e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
            target.scrollIntoView({ behavior: 'smooth' });
        }
    });
});

// ============================================================
// تحميل الصفحة
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    // إزالة رسائل toast بعد 5 ثواني
    document.querySelectorAll('.toast').forEach(toast => {
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 0.5s ease';
            setTimeout(() => toast.style.display = 'none', 500);
        }, 5000);
    });
});
</script>

</body>
</html>

<?php
// ============================================================
// [5] إنشاء جدول روابط الدفع
// ============================================================
function createPaymentLinksTable() {
    $db = db();
    
    $sql = "
    CREATE TABLE IF NOT EXISTS " . DB_PREFIX . "payment_links (
        id INT AUTO_INCREMENT PRIMARY KEY,
        link_id VARCHAR(50) NOT NULL UNIQUE,
        token VARCHAR(64) NOT NULL,
        slug VARCHAR(100) NOT NULL UNIQUE,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        amount DECIMAL(15,6) NOT NULL,
        currency VARCHAR(10) NOT NULL,
        gateway VARCHAR(50) NOT NULL,
        protocol VARCHAR(20) NOT NULL,
        payment_type VARCHAR(50) DEFAULT 'one_time',
        customer_name VARCHAR(255),
        customer_email VARCHAR(255),
        customer_phone VARCHAR(50),
        redirect_url VARCHAR(500),
        expiry_date DATETIME NOT NULL,
        max_uses INT DEFAULT 0,
        uses_count INT DEFAULT 0,
        status ENUM('active', 'inactive', 'expired', 'deleted') DEFAULT 'active',
        user_id INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_link_id (link_id),
        INDEX idx_token (token),
        INDEX idx_slug (slug),
        INDEX idx_user (user_id),
        INDEX idx_status (status),
        INDEX idx_expiry (expiry_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    
    try {
        $db->execute($sql);
        return ['success' => true, 'message' => 'Payment links table created'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

// إنشاء الجدول إذا لم يكن موجوداً
if (!defined('SKIP_DB_INIT')) {
    $tableResult = createPaymentLinksTable();
    if (!$tableResult['success']) {
        error_log('Payment links table creation error: ' . $tableResult['message']);
    }
}
?>
