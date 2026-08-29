<?php
/**
 * ============================================================
 * DI PARMA | مراقبة الأمان - Security Monitoring
 * ============================================================
 * نظام مراقبة أمان البوابة والمعاملات
 * ============================================================
 */

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';

$db = db();
$securityAlerts = [];
$systemStatus = [];

// فحص 1: المعاملات المريبة
$suspiciousTransactions = $db->query("
    SELECT COUNT(*) as count FROM " . DB_PREFIX . "transactions
    WHERE status='failed' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
")[0]['count'] ?? 0;

if ($suspiciousTransactions > 10) {
    $securityAlerts[] = [
        'level' => 'warning',
        'title' => 'معاملات فاشلة متعددة',
        'message' => "تم رصد $suspiciousTransactions معاملة فاشلة في آخر 24 ساعة",
        'action' => 'مراجعة سجلات المعاملات'
    ];
}

// فحص 2: البوابات غير المفعلة
$inactiveGateways = $db->query("
    SELECT COUNT(*) as count FROM " . DB_PREFIX . "payment_gateways
    WHERE status='inactive'
")[0]['count'] ?? 0;

$systemStatus[] = [
    'name' => 'البوابات النشطة',
    'value' => $db->query("SELECT COUNT(*) as count FROM " . DB_PREFIX . "payment_gateways WHERE status='active'")[0]['count'] ?? 0,
    'icon' => 'fas fa-check-circle',
    'color' => 'success'
];

// فحص 3: معدل نجاح المعاملات
$totalTxn = $db->query("SELECT COUNT(*) as count FROM " . DB_PREFIX . "transactions")[0]['count'] ?? 1;
$completedTxn = $db->query("SELECT COUNT(*) as count FROM " . DB_PREFIX . "transactions WHERE status='completed'")[0]['count'] ?? 0;
$successRate = ($completedTxn / max($totalTxn, 1)) * 100;

$systemStatus[] = [
    'name' => 'معدل النجاح',
    'value' => round($successRate, 2) . '%',
    'icon' => 'fas fa-chart-line',
    'color' => $successRate >= 90 ? 'success' : 'warning'
];

if ($successRate < 80) {
    $securityAlerts[] = [
        'level' => 'danger',
        'title' => 'معدل نجاح منخفض',
        'message' => "معدل نجاح المعاملات هو " . round($successRate, 2) . "%",
        'action' => 'فحص تكوين البوابات'
    ];
}

// فحص 4: الروابط المنتهية الصلاحية
$expiredLinks = $db->query("
    SELECT COUNT(*) as count FROM " . DB_PREFIX . "payment_links
    WHERE status='expired' OR (expiry_date < NOW() AND status='active')
")[0]['count'] ?? 0;

$systemStatus[] = [
    'name' => 'روابط الدفع النشطة',
    'value' => $db->query("SELECT COUNT(*) as count FROM " . DB_PREFIX . "payment_links WHERE status='active'")[0]['count'] ?? 0,
    'icon' => 'fas fa-link',
    'color' => 'info'
];

// فحص 5: استخدام الخادم
$uptime = shell_exec('uptime 2>/dev/null') ?? 'Unknown';
$freeSpace = round(disk_free_space(__DIR__) / (1024 * 1024 * 1024), 2);

$systemStatus[] = [
    'name' => 'مساحة الخادم',
    'value' => $freeSpace . ' GB',
    'icon' => 'fas fa-server',
    'color' => $freeSpace > 10 ? 'success' : 'danger'
];

if ($freeSpace < 5) {
    $securityAlerts[] = [
        'level' => 'danger',
        'title' => 'مساحة تخزين منخفضة',
        'message' => "المساحة المتبقية: $freeSpace GB",
        'action' => 'تنظيف الملفات المؤقتة أو ترقية الخادم'
    ];
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang ?? 'ar') ?>" dir="<?= htmlspecialchars($pageDir ?? 'rtl') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DI PARMA | مراقبة الأمان</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        :root {
            --gold: #FFD700;
            --bg-card: rgba(10,16,39,0.94);
            --text-gold: #FFDFA0;
            --border-gold: rgba(255,215,0,0.25);
            --danger: #d9534f;
            --warning: #f0ad4e;
            --success: #4CAF50;
        }
        body {
            font-family: 'Cairo', sans-serif;
            background: linear-gradient(180deg, #020202 0%, #0b0b0b 35%, #090909 100%);
            color: var(--text-gold);
            min-height: 100vh;
            padding: 20px;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { text-align: center; margin-bottom: 30px; color: var(--gold); }
        
        .back-btn {
            display: inline-block;
            margin-bottom: 20px;
            padding: 10px 20px;
            background: rgba(255,215,0,0.1);
            border: 1px solid var(--border-gold);
            color: var(--gold);
            border-radius: 8px;
            text-decoration: none;
        }
        
        .alerts-section {
            margin-bottom: 30px;
        }
        
        .alert {
            background: rgba(255,255,255,0.05);
            border-right: 4px solid;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        
        .alert.danger { border-color: var(--danger); }
        .alert.warning { border-color: var(--warning); }
        .alert.success { border-color: var(--success); }
        
        .alert-title {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .alert-message {
            font-size: 0.9rem;
            color: #bbb;
            margin-bottom: 8px;
        }
        
        .alert-action {
            font-size: 0.85rem;
            color: #999;
            font-style: italic;
        }
        
        .status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .status-card {
            background: var(--bg-card);
            border: 1px solid var(--border-gold);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
        }
        
        .status-card i {
            font-size: 2rem;
            margin-bottom: 10px;
        }
        
        .status-card.success i { color: var(--success); }
        .status-card.warning i { color: var(--warning); }
        .status-card.danger i { color: var(--danger); }
        
        .status-value {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--gold);
            margin: 10px 0;
        }
        
        .status-name {
            color: #888;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back-btn"><i class="fas fa-arrow-right"></i> العودة</a>
        <h1><i class="fas fa-shield-alt"></i> مراقبة الأمان</h1>
        
        <?php if (!empty($securityAlerts)): ?>
        <div class="alerts-section">
            <h2 style="color: var(--gold); margin-bottom: 20px;">⚠️ التنبيهات</h2>
            <?php foreach ($securityAlerts as $alert): ?>
                <div class="alert <?= htmlspecialchars($alert['level']) ?>">
                    <div class="alert-title"><?= htmlspecialchars($alert['title']) ?></div>
                    <div class="alert-message"><?= htmlspecialchars($alert['message']) ?></div>
                    <div class="alert-action">📌 <?= htmlspecialchars($alert['action']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div style="background: rgba(76,175,80,0.1); border: 1px solid rgba(76,175,80,0.3); border-radius: 8px; padding: 20px; margin-bottom: 30px; text-align: center;">
            <i class="fas fa-check-circle" style="color: var(--success); font-size: 2rem; margin-bottom: 10px;"></i>
            <p style="color: var(--success); font-weight: 600;">جميع الأنظمة تعمل بشكل طبيعي</p>
        </div>
        <?php endif; ?>
        
        <h2 style="color: var(--gold); margin-bottom: 20px;">📊 حالة النظام</h2>
        <div class="status-grid">
            <?php foreach ($systemStatus as $status): ?>
                <div class="status-card <?= htmlspecialchars($status['color']) ?>">
                    <i class="<?= htmlspecialchars($status['icon']) ?>"></i>
                    <div class="status-value"><?= htmlspecialchars($status['value']) ?></div>
                    <div class="status-name"><?= htmlspecialchars($status['name']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>
