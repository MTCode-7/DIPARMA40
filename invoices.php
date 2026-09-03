<?php
/**
 * ============================================================
 * DI PARMA | قائمة الفواتير - Invoices
 * ============================================================
 */

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';

$db = db();
$userId = $_SESSION['user_id'] ?? 0;

// جلب الفواتير الخاصة بالمستخدم مع معالجة الأخطاء المحتملة
try {
    $invoices = $db->query("SELECT * FROM " . DB_PREFIX . "invoices WHERE user_id = ? ORDER BY created_at DESC", [$userId]);
} catch (Exception $e) {
    $invoices = [];
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang ?? 'en') ?>" dir="<?= htmlspecialchars($pageDir ?? 'ltr') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DI PARMA | الفواتير</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', sans-serif; background: #0b0f17; color: #ffdfa0; margin: 0; padding: 20px; } 
        .container { max-width: 1100px; margin: 0 auto; } 
        .card { background: rgba(10,16,39,0.95); border: 1px solid rgba(255,215,0,0.2); border-radius: 16px; padding: 25px; margin-bottom: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); } 
        .nav { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px; } 
        .nav a { color: #fff; text-decoration: none; padding: 8px 16px; border: 1px solid rgba(255,215,0,0.2); border-radius: 999px; transition: 0.3s; font-size: 0.9rem; } 
        .nav a:hover, .nav a.active { background: rgba(255,215,0,0.1); border-color: #FFD700; color: #FFD700; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; } 
        th, td { padding: 12px; border-bottom: 1px solid rgba(255,255,255,0.05); text-align: right; font-size: 0.9rem; } 
        th { color: #888; font-weight: 600; }
        .badge { display: inline-block; padding: 5px 12px; border-radius: 999px; font-size: 0.8rem; font-weight: 600; }
        .badge-completed { background: rgba(76,175,80,0.15); color: #4CAF50; border: 1px solid rgba(76,175,80,0.3); }
        .badge-pending { background: rgba(240,173,78,0.15); color: #f0ad4e; border: 1px solid rgba(240,173,78,0.3); }
        .badge-failed { background: rgba(217,83,79,0.15); color: #d9534f; border: 1px solid rgba(217,83,79,0.3); }
        .empty-state { text-align: center; padding: 40px; color: #777; }
    </style>
</head>
<body>
<div class="container">
    <div class="nav">
        <a href="index.php">&#8962; الرئيسية</a>
        <a href="dashboard.php">لوحة التحكم</a>
        <a href="links.php">روابط الدفع</a>
        <a href="transactions.php">المعاملات</a>
        <a href="wallets.php">المحفظة</a>
        <a href="invoices.php" class="active">الفواتير</a>
    </div>
    
    <div class="card">
        <h2 style="color: #FFD700; margin-bottom: 20px; font-size: 1.3rem;">الفواتير الداخلية</h2>
        
        <?php if (empty($invoices)): ?>
            <div class="empty-state">لا توجد فواتير مسجلة حتى الآن.</div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>رقم الفاتورة</th>
                            <th>المرجع</th>
                            <th>المبلغ</th>
                            <th>الحالة</th>
                            <th>التاريخ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invoices as $invoice): ?>
                            <?php 
                                $status = strtolower($invoice['status'] ?? 'pending');
                                $badgeClass = 'badge-pending';
                                if (in_array($status, ['completed', 'paid', 'success'])) $badgeClass = 'badge-completed';
                                elseif (in_array($status, ['failed', 'cancelled'])) $badgeClass = 'badge-failed';
                            ?>
                            <tr>
                                <td style="font-weight: 600; color: #fff;"><?= htmlspecialchars($invoice['invoice_number'] ?? '-') ?></td>
                                <td style="direction: ltr; text-align: right; color: #aaa;"><?= htmlspecialchars($invoice['reference'] ?? '-') ?></td>
                                <td style="color: #FFD700; font-weight: 700;"><?= number_format((float)($invoice['amount'] ?? 0), 2) ?> <?= htmlspecialchars($invoice['currency'] ?? 'USD') ?></td>
                                <td><span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($invoice['status']) ?></span></td>
                                <td style="color: #aaa;"><?= htmlspecialchars($invoice['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
