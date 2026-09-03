<?php
/**
 * ============================================================
 * DI PARMA | إدارة المحفظة وسجل الحركات - Wallets & Ledger
 * ============================================================
 */

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/performance.php';
require_once __DIR__ . '/includes/db_optimized.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/lib/WalletService.php';

$db = db();
dp_ensure_indexes();
$userId = $_SESSION['user_id'] ?? 0;
$walletService = WalletService::getInstance();

// جلب المحافظ وسجل الحركات مع معالجة الأخطاء بأمان
try {
    $wallets = $db->query("SELECT * FROM " . DB_PREFIX . "wallets WHERE user_id = ? ORDER BY created_at DESC", [$userId]) ?: [];
} catch (Exception $e) {
    $wallets = [];
}

try {
    $userWallets = $db->query("SELECT network, coin, address, status, created_at FROM " . DB_PREFIX . "user_wallets WHERE user_id = ? ORDER BY network ASC", [$userId]) ?: [];
} catch (Exception $e) {
    $userWallets = [];
}

try {
    $cryptoWallets = $db->query("SELECT coin, network, balance, updated_at FROM " . DB_PREFIX . "user_crypto_wallets WHERE user_id = ? ORDER BY network ASC, coin ASC", [$userId]) ?: [];
} catch (Exception $e) {
    $cryptoWallets = [];
}

$totalFiat = array_sum(array_map(static fn(array $wallet): float => (float)($wallet['balance'] ?? 0), $wallets));
$totalCrypto = array_sum(array_map(static fn(array $wallet): float => (float)($wallet['balance'] ?? 0), $cryptoWallets));
$currencyCount = count(array_unique(array_filter(array_merge(
    array_column($wallets, 'currency'),
    array_column($cryptoWallets, 'coin')
))));
$networkCount = count(array_unique(array_filter(array_merge(
    array_column($userWallets, 'network'),
    array_column($cryptoWallets, 'network')
))));
$walletCount = count($wallets) + count($cryptoWallets);

try {
    $ledger = $db->query("SELECT * FROM " . DB_PREFIX . "ledger WHERE user_id = ? ORDER BY created_at DESC LIMIT 50", [$userId]) ?: [];
} catch (Exception $e) {
    $ledger = [];
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang ?? 'en') ?>" dir="<?= htmlspecialchars($pageDir ?? 'ltr') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DI PARMA | المحفظة</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Cairo', sans-serif; background: #0b0f17; color: #ffdfa0; padding: 20px; } 
        .container { max-width: 1100px; margin: 0 auto; } 
        .card { background: rgba(10,16,39,0.95); border: 1px solid rgba(255,215,0,0.2); border-radius: 16px; padding: 25px; margin-bottom: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); } 
        .nav { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px; } 
        .nav a { color: #fff; text-decoration: none; padding: 8px 16px; border: 1px solid rgba(255,215,0,0.2); border-radius: 999px; transition: 0.3s; font-size: 0.9rem; } 
        .nav a:hover, .nav a.active { background: rgba(255,215,0,0.1); border-color: #FFD700; color: #FFD700; }
        .balance { font-size: 2.2rem; color: #FFD700; font-weight: 800; margin-top: 10px; } 
        table { width: 100%; border-collapse: collapse; margin-top: 15px; } 
        th, td { padding: 12px; border-bottom: 1px solid rgba(255,255,255,0.05); text-align: right; font-size: 0.9rem; } 
        th { color: #888; font-weight: 600; }
        .badge { display: inline-block; padding: 5px 12px; border-radius: 999px; font-size: 0.8rem; font-weight: 600; background: rgba(255,215,0,0.15); color: #FFD700; border: 1px solid rgba(255,215,0,0.3); } 
        .empty-state { text-align: center; padding: 30px; color: #777; font-size: 0.9rem; }
        .wallet-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin-top: 15px; }
        .wallet-box { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,215,0,0.1); border-radius: 12px; padding: 20px; }
        .stats-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:15px; margin-bottom:20px; }
        .stat-box { background:rgba(255,215,0,.06); border:1px solid rgba(255,215,0,.16); border-radius:12px; padding:18px; }
        .stat-label { color:#aaa; font-size:.82rem; }
        .stat-value { color:#FFD700; font-size:1.55rem; font-weight:800; margin-top:6px; }
    </style>
</head>
<body>
<div class="container">
    <div class="nav">
        <a href="index.php">&#8962; الرئيسية</a>
        <a href="dashboard.php">لوحة التحكم</a>
        <a href="links.php">روابط الدفع</a>
        <a href="transactions.php">المعاملات</a>
        <a href="wallets.php" class="active">المحفظة</a>
        <a href="invoices.php">الفواتير</a>
    </div>

    <div class="stats-grid">
        <div class="stat-box"><div class="stat-label">إجمالي المحافظ</div><div class="stat-value"><?= $walletCount ?></div></div>
        <div class="stat-box"><div class="stat-label">إجمالي الرصيد الداخلي</div><div class="stat-value"><?= number_format($totalFiat, 2) ?></div></div>
        <div class="stat-box"><div class="stat-label">إجمالي رصيد الكريبتو</div><div class="stat-value"><?= number_format($totalCrypto, 8) ?></div></div>
        <div class="stat-box"><div class="stat-label">العملات</div><div class="stat-value"><?= $currencyCount ?></div></div>
        <div class="stat-box"><div class="stat-label">الشبكات</div><div class="stat-value"><?= $networkCount ?></div></div>
    </div>

    <div class="card">
        <h2 style="color: #FFD700; margin-bottom: 10px; font-size: 1.3rem;">المحفظة الداخلية</h2>
        <p style="color: #aaa; font-size: 0.9rem; margin-bottom: 15px;">هذا هو الرصيد الداخلي الذي يزداد عند إتمام دفعة داخلية أو تسوية المعاملات.</p>
        
        <?php if (empty($wallets)): ?>
            <div class="wallet-box">
                <div style="color: #888; font-size: 0.9rem;">الرصيد الحالي المتوفر</div>
                <div class="balance">0.00 <span style="font-size: 1.2rem; color: #aaa;">USD</span></div>
            </div>
        <?php else: ?>
            <div class="wallet-grid">
                <?php foreach ($wallets as $wallet): ?>
                    <div class="wallet-box">
                        <div style="color: #888; font-size: 0.9rem;"><?= htmlspecialchars($wallet['currency'] ?? 'USD') ?> محفظة</div>
                        <div class="balance"><?= number_format((float)($wallet['balance'] ?? 0), 2) ?> <span style="font-size: 1.2rem; color: #aaa;"><?= htmlspecialchars($wallet['currency'] ?? 'USD') ?></span></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2 style="color: #FFD700; margin-bottom: 10px; font-size: 1.3rem;">أرصدة العملات والشبكات</h2>
        <?php if (empty($cryptoWallets)): ?>
            <div class="empty-state">لا توجد أرصدة كريبتو مسجلة.</div>
        <?php else: ?>
            <div class="wallet-grid">
                <?php foreach ($cryptoWallets as $wallet): ?>
                    <div class="wallet-box">
                        <div style="color:#FFD700;font-size:1.1rem;font-weight:700;"><?= htmlspecialchars((string)$wallet['coin']) ?></div>
                        <div style="color:#aaa;margin-top:5px;">الشبكة: <?= htmlspecialchars((string)$wallet['network']) ?></div>
                        <div class="balance"><?= number_format((float)$wallet['balance'], 8) ?> <span style="font-size:1.1rem;color:#aaa;"><?= htmlspecialchars((string)$wallet['coin']) ?></span></div>
                        <div style="color:#777;font-size:.78rem;margin-top:8px;">آخر تحديث: <?= htmlspecialchars((string)($wallet['updated_at'] ?? '')) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2 style="color: #FFD700; margin-bottom: 10px; font-size: 1.3rem;">المحفظة الرقمية</h2>
        <p style="color: #aaa; font-size: 0.9rem; margin-bottom: 15px;">هذا هو عنوان المحفظة الرقمية الخاص بك لاستقبال العملات المدعومة.</p>
        <?php if (empty($userWallets)): ?>
            <div class="empty-state">لا توجد محفظة رقمية بعد. يمكنك إنشاؤها من صفحة التشفير.</div>
        <?php else: ?>
            <div class="wallet-grid">
                <?php foreach ($userWallets as $wallet): ?>
                    <div class="wallet-box">
                        <div class="label"><?= htmlspecialchars($wallet['network'] . ' ' . $wallet['coin']) ?></div>
                        <div class="value" style="font-family: monospace; word-break: break-all;"><?= htmlspecialchars($wallet['address']) ?></div>
                        <div style="margin-top: 10px; display: flex; justify-content: space-between; align-items: center; gap: 10px;">
                            <span class="badge"><?= htmlspecialchars($wallet['status']) ?></span>
                            <span style="color: #aaa; font-size: 0.82rem;"><?= htmlspecialchars($wallet['created_at']) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3 style="color: #FFD700; margin-bottom: 15px; font-size: 1.2rem;">سجل الحركة (Ledger)</h3>
        
        <?php if (empty($ledger)): ?>
            <div class="empty-state">لا توجد حركات مالية مسجلة في السجل حتى الآن.</div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>النوع</th>
                            <th>المبلغ</th>
                            <th>المرجع</th>
                            <th>الوصف</th>
                            <th>التاريخ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ledger as $entry): ?>
                            <tr>
                                <td><span class="badge"><?= htmlspecialchars($entry['type'] ?? '-') ?></span></td>
                                <td style="color: #FFD700; font-weight: 700;"><?= number_format((float)($entry['amount'] ?? 0), 2) ?> <?= htmlspecialchars($entry['currency'] ?? 'USD') ?></td>
                                <td style="direction: ltr; text-align: right; color: #aaa;"><?= htmlspecialchars($entry['reference'] ?? '-') ?></td>
                                <td style="color: #fff;"><?= htmlspecialchars($entry['description'] ?? '-') ?></td>
                                <td style="color: #888;"><?= htmlspecialchars($entry['created_at'] ?? '-') ?></td>
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
