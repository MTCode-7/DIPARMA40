<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/gateways.php';

requireAdmin();

$db = db();

// â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
// [1] ط¥ط­طµط§ط¦ظٹط§طھ ط¹ط§ظ…ط©
// â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
$totalTransactions = $db->query("SELECT COUNT(*) as count FROM " . DB_PREFIX . "transactions")[0]['count'] ?? 0;
$completedTransactions = $db->query("SELECT COUNT(*) as count FROM " . DB_PREFIX . "transactions WHERE status = 'completed'")[0]['count'] ?? 0;
$totalAmount = $db->query("SELECT SUM(amount) as total FROM " . DB_PREFIX . "transactions WHERE status = 'completed'")[0]['total'] ?? 0;
$totalFees = $db->query("SELECT SUM(fees) as total FROM " . DB_PREFIX . "transactions WHERE status = 'completed'")[0]['total'] ?? 0;

// â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
// [2] ط­ط§ظ„ط© ط§ظ„ط¨ظˆط§ط¨ط§طھ
// â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
$gateways = $db->query("SELECT * FROM " . DB_PREFIX . "payment_gateways ORDER BY code ASC");
$gatewayStatus = [];
foreach ($gateways as $gw) {
    $creds = json_decode($gw['credentials'], true) ?? [];
    $config = json_decode($gw['config'], true) ?? [];
    $hasToken = false;
    foreach (['access_token','api_key','api_secret','client_id'] as $key) {
        if (!empty($creds[$key]) && !in_array($creds[$key], ['your_api_key','your_api_secret',''])) {
            $hasToken = true;
            break;
        }
    }
    $gatewayStatus[$gw['code']] = [
        'name' => $gw['name'],
        'status' => $gw['status'],
        'has_credentials' => $hasToken,
        'environment' => $config['environment'] ?? 'sandbox',
    ];
}

// â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
// [3] ط§ط®طھط¨ط§ط± Wise API
// â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
$wiseStatus = ['status' => 'not_configured'];
if (isset($gatewayStatus['wise']) && $gatewayStatus['wise']['has_credentials']) {
    $wiseConfig = getGatewayConfig('wise');
    $wiseCreds = $wiseConfig['credentials'] ?? [];
    $token = trim($wiseCreds['access_token'] ?? $wiseCreds['api_key'] ?? '');
    $profId = trim($wiseCreds['profile_id'] ?? '');
    $env = $wiseConfig['environment'] ?? 'sandbox';
    $baseUrl = ($env === 'live') ? 'https://api.transferwise.com' : 'https://api.sandbox.transferwise.com';
    
    $ch = curl_init($baseUrl . '/v1/profiles');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $wiseStatus = [
        'status' => $code === 200 ? 'connected' : 'failed',
        'http_code' => $code,
        'environment' => $env,
        'profile_id' => $profId,
    ];
}

// â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
// [4] ط­ط§ظ„ط© Tunnel
// â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
$tunnelUrl = 'https://lovely-spiders-deny.loca.lt';
$ch = curl_init($tunnelUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 4,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_NOBODY => true,
]);
curl_exec($ch);
$tunnelCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$tunnelActive = ($tunnelCode > 0 && $tunnelCode < 500);

// â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
// [5] ط¢ط®ط± 10 ظ…ط¹ط§ظ…ظ„ط§طھ
// â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ
$recentTransactions = $db->query("SELECT * FROM " . DB_PREFIX . "transactions ORDER BY created_at DESC LIMIT 10");

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang ?? 'ar') ?>" dir="<?= htmlspecialchars($pageDir ?? 'rtl') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DI PARMA | ط­ط§ظ„ط© ط§ظ„ظ†ط¸ط§ظ…</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        :root {
            --gold: #FFD700;
            --bg-dark: #0A0F1E;
            --bg-card: rgba(10,16,39,0.94);
            --text-gold: #FFDFA0;
            --success: #4CAF50;
            --danger: #d9534f;
            --warning: #f0ad4e;
        }
        body {
            font-family: 'Cairo', sans-serif;
            background: linear-gradient(180deg, #020202 0%, #0b0b0b 50%, #090909 100%);
            color: var(--text-gold);
            min-height: 100vh;
            padding: 20px;
        }
        .container { max-width: 1400px; margin: 0 auto; }
        .header {
            background: var(--bg-card);
            border: 1px solid rgba(255,215,0,0.25);
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 {
            font-size: 1.8rem;
            background: linear-gradient(135deg, #FFE066, #FFD700);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: var(--bg-card);
            border: 1px solid rgba(255,215,0,0.25);
            border-radius: 16px;
            padding: 20px;
            text-align: center;
        }
        .stat-card .icon {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }
        .stat-card .value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--gold);
            margin-bottom: 5px;
        }
        .stat-card .label {
            font-size: 0.95rem;
            color: #AAA;
        }
        .section {
            background: var(--bg-card);
            border: 1px solid rgba(255,215,0,0.25);
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 25px;
        }
        .section-title {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--gold);
        }
        .gateway-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 15px;
        }
        .gateway-card {
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,215,0,0.15);
            border-radius: 12px;
            padding: 18px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .gateway-card .icon {
            font-size: 2rem;
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255,215,0,0.1);
            border-radius: 12px;
        }
        .gateway-card .info {
            flex: 1;
        }
        .gateway-card .name {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 5px;
        }
        .gateway-card .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .badge-success { background: rgba(76,175,80,0.2); color: var(--success); }
        .badge-danger { background: rgba(217,83,79,0.2); color: var(--danger); }
        .badge-warning { background: rgba(240,173,78,0.2); color: var(--warning); }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px;
            text-align: right;
            border-bottom: 1px solid rgba(255,215,0,0.1);
        }
        th {
            background: rgba(255,215,0,0.05);
            font-weight: 600;
            color: var(--gold);
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: linear-gradient(135deg, var(--gold), #B58E15);
            color: #000;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
        }
        .btn:hover { opacity: 0.9; }
        .tunnel-status {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 15px;
            background: rgba(255,255,255,0.03);
            border-radius: 12px;
            border: 1px solid rgba(255,215,0,0.15);
        }
        .pulse {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }
        .pulse.active { background: var(--success); }
        .pulse.inactive { background: var(--danger); }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
    </style>
</head>
<body>
<div class="container">
    <!-- Header -->
    <div class="header">
        <div>
            <h1>ًں“ٹ ط­ط§ظ„ط© ط§ظ„ظ†ط¸ط§ظ… ظˆ ط§ظ„ط¨ظˆط§ط¨ط§طھ</h1>
            <p style="color:#AAA;margin-top:5px;">ظ…ط±ط§ظ‚ط¨ط© ط´ط§ظ…ظ„ط© ظ„ط¬ظ…ظٹط¹ ط§ظ„ط¨ظˆط§ط¨ط§طھ ظˆط§ظ„ط¹ظ…ظ„ظٹط§طھ</p>
        </div>
        <a href="index.php" class="btn"><i class="fas fa-home"></i> ط§ظ„ط±ط¦ظٹط³ظٹط©</a>
    </div>

    <!-- Stats Grid -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="icon">ًں’°</div>
            <div class="value"><?= number_format($totalAmount, 2) ?></div>
            <div class="label">ط¥ط¬ظ…ط§ظ„ظٹ ط§ظ„ظ…ط¨ظ„ط؛ ط§ظ„ظ…ط­طµظ‘ظ„ (AED)</div>
        </div>
        <div class="stat-card">
            <div class="icon">ًں“ˆ</div>
            <div class="value"><?= $completedTransactions ?></div>
            <div class="label">ط§ظ„ط¹ظ…ظ„ظٹط§طھ ط§ظ„ظ…ظƒطھظ…ظ„ط©</div>
        </div>
        <div class="stat-card">
            <div class="icon">ًں“ٹ</div>
            <div class="value"><?= $totalTransactions ?></div>
            <div class="label">ط¥ط¬ظ…ط§ظ„ظٹ ط§ظ„ط¹ظ…ظ„ظٹط§طھ</div>
        </div>
        <div class="stat-card">
            <div class="icon">ًں’¸</div>
            <div class="value"><?= number_format($totalFees, 2) ?></div>
            <div class="label">ط¥ط¬ظ…ط§ظ„ظٹ ط§ظ„ط±ط³ظˆظ…</div>
        </div>
    </div>

    <!-- Tunnel Status -->
    <div class="section">
        <div class="section-title">
            <i class="fas fa-network-wired"></i> ط­ط§ظ„ط© Localtunnel (Webhook)
        </div>
        <div class="tunnel-status">
            <div class="pulse <?= $tunnelActive ? 'active' : 'inactive' ?>"></div>
            <div style="flex:1;">
                <div style="font-weight:600;margin-bottom:5px;"><?= $tunnelUrl ?></div>
                <div style="font-size:0.9rem;color:#AAA;">
                    <?= $tunnelActive 
                        ? '<span style="color:var(--success);">âœ… ط§ظ„ظ†ظپظ‚ ظ†ط´ط· â€” Webhooks ط³طھط¹ظ…ظ„ ط¨ط´ظƒظ„ طµط­ظٹط­</span>' 
                        : '<span style="color:var(--danger);">â‌Œ ط§ظ„ظ†ظپظ‚ ط؛ظٹط± ظ…طھط§ط­ â€” ظ‚ظ… ط¨طھط´ط؛ظٹظ„: lt --port 80 --subdomain lovely-spiders-deny</span>' 
                    ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Gateways Status -->
    <div class="section">
        <div class="section-title">
            <i class="fas fa-credit-card"></i> ط­ط§ظ„ط© ط§ظ„ط¨ظˆط§ط¨ط§طھ (<?= count($gatewayStatus) ?> ط¨ظˆط§ط¨ط©)
        </div>
        <div class="gateway-grid">
            <?php foreach ($gatewayStatus as $code => $gw): ?>
                <div class="gateway-card">
                    <div class="icon">
                        <?php if ($code === 'wise'): ?>
                            <i class="fas fa-exchange-alt"></i>
                        <?php elseif ($code === 'stripe'): ?>
                            <i class="fab fa-stripe-s"></i>
                        <?php elseif ($code === 'paypal'): ?>
                            <i class="fab fa-paypal"></i>
                        <?php else: ?>
                            <i class="fas fa-credit-card"></i>
                        <?php endif; ?>
                    </div>
                    <div class="info">
                        <div class="name"><?= htmlspecialchars($gw['name']) ?></div>
                        <div>
                            <?php
                            if ($gw['status'] === 'active' && $gw['has_credentials']) {
                                if ($code === 'wise' && $wiseStatus['status'] === 'connected') {
                                    echo '<span class="status-badge badge-success">âœ… ظ…طھطµظ„ط© (API OK)</span>';
                                } elseif ($code === 'wise' && $wiseStatus['status'] === 'failed') {
                                    echo '<span class="status-badge badge-danger">â‌Œ ظپط´ظ„ ط§ظ„ط§طھطµط§ظ„</span>';
                                } else {
                                    echo '<span class="status-badge badge-success">âœ… ظ…ظڈظ‡ظٹظ‘ط£ط©</span>';
                                }
                            } elseif ($gw['status'] === 'active') {
                                echo '<span class="status-badge badge-warning">âڑ ï¸ڈ ط¨ظٹط§ظ†ط§طھ ظ†ط§ظ‚طµط©</span>';
                            } else {
                                echo '<span class="status-badge badge-danger">â‌Œ ط؛ظٹط± ظ†ط´ط·ط©</span>';
                            }
                            ?>
                            <span style="margin-left:8px;font-size:0.8rem;color:#888;"><?= $gw['environment'] ?></span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <?php if ($wiseStatus['status'] !== 'not_configured'): ?>
        <div style="margin-top:20px;padding:15px;background:rgba(52,168,224,0.05);border:1px solid rgba(52,168,224,0.2);border-radius:12px;">
            <strong>طھظپط§طµظٹظ„ Wise:</strong><br>
            Profile ID: <code><?= htmlspecialchars($wiseStatus['profile_id'] ?? 'N/A') ?></code><br>
            HTTP Code: <span style="color:<?= $wiseStatus['http_code'] === 200 ? 'var(--success)' : 'var(--danger)' ?>"><?= $wiseStatus['http_code'] ?? 'N/A' ?></span><br>
            Environment: <strong><?= htmlspecialchars($wiseStatus['environment'] ?? 'N/A') ?></strong>
        </div>
        <?php endif; ?>
    </div>

    <!-- Recent Transactions -->
    <div class="section">
        <div class="section-title">
            <i class="fas fa-history"></i> ط¢ط®ط± 10 ط¹ظ…ظ„ظٹط§طھ
        </div>
        <table>
            <thead>
                <tr>
                    <th>ط§ظ„ظ…ط±ط¬ط¹</th>
                    <th>ط§ظ„ط¨ظˆط§ط¨ط©</th>
                    <th>ط§ظ„ظ…ط¨ظ„ط؛</th>
                    <th>ط§ظ„ط­ط§ظ„ط©</th>
                    <th>ط§ظ„طھط§ط±ظٹط®</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentTransactions as $tx): ?>
                <tr>
                    <td><?= htmlspecialchars($tx['reference']) ?></td>
                    <td><?= htmlspecialchars($tx['gateway']) ?></td>
                    <td><?= number_format($tx['amount'], 2) ?> <?= htmlspecialchars($tx['currency']) ?></td>
                    <td>
                        <?php
                        $statusClass = $tx['status'] === 'completed' ? 'badge-success' : ($tx['status'] === 'pending' ? 'badge-warning' : 'badge-danger');
                        echo '<span class="status-badge ' . $statusClass . '">' . getStatusLabel($tx['status']) . '</span>';
                        ?>
                    </td>
                    <td><?= date('Y-m-d H:i', strtotime($tx['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>

