<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/gateways.php';

requireAdmin();

// ── جلب البوابات من التكوين + قاعدة البيانات ──────────────
$db       = db();
$configuredGateways = getGatewaysConfig();
$dbGws    = [];
foreach ($configuredGateways as $code => $cfg) {
    $row = $db->find('payment_gateways', ['code' => $code]);
    $dbGws[] = [
        'code' => strtolower(trim($code)),
        'name' => $row['name'] ?? ($cfg['name'] ?? ucfirst($code)),
        'status' => $row['status'] ?? (($cfg['setup_complete'] ?? false) ? 'active' : 'inactive'),
        'config' => $row['config'] ?? json_encode($cfg),
        'credentials' => $row['credentials'] ?? json_encode($cfg['credentials'] ?? []),
        'settings' => $row['settings'] ?? json_encode($cfg['urls'] ?? [])
    ];
}

usort($dbGws, function ($a, $b) {
    return strcmp($a['name'], $b['name']);
});

// ── دالة فحص اتصال بوابة ──────────────────────────────────
function testGatewayConnection(string $code, array $config): array {
    $creds  = $config['credentials'] ?? [];
    $env    = strtolower($config['environment'] ?? 'sandbox');

    // استخراج أي token/key موجود
    $token  = '';
    foreach (['access_token','api_key','client_id','merchant_id','secret_key'] as $k) {
        $v = trim($creds[$k] ?? '');
        if (!empty($v) && !in_array($v, ['your_api_key','your_api_secret','your_access_token','your_profile_id',''])) {
            $token = $v;
            break;
        }
    }

    if (empty($token)) {
        $hint = 'يجب إضافة API Key أو Access Token أو secret_key في بيانات البوابة لكي يكتمل الفحص.';
        if ($code === 'moonpay') {
            $hint = 'MoonPay يحتاج api_key و secret_key، كما أن الحقول moonpay_id و moonpay_token مفيدة إذا كانت مطلوبة من لوحة MoonPay.';
        }
        return [
            'status' => 'no_credentials',
            'label' => '⚠️ بيانات اعتماد غير مضبوطة',
            'ms' => null,
            'http' => null,
            'hint' => $hint
        ];
    }

    $start = microtime(true);

    // ── Endpoints معروفة لكل بوابة ──
    $endpoints = [
        'wise'        => ($env === 'live' ? 'https://api.transferwise.com' : 'https://api.sandbox.transferwise.com') . '/v1/profiles',
        'stripe'      => 'https://api.stripe.com/v1/balance',
        'paypal'      => ($env === 'live' ? 'https://api.paypal.com' : 'https://api.sandbox.paypal.com') . '/v1/oauth2/token',
        'myfatoorah'  => ($env === 'live' ? 'https://api.myfatoorah.com' : 'https://apitest.myfatoorah.com') . '/v1/GetPaymentStatus',
        'adyen'       => 'https://checkout-test.adyen.com/v71/paymentMethods',
        'checkout'    => 'https://api.sandbox.checkout.com/tokens',
        'paytabs'     => 'https://secure.paytabs.sa/payment/request',
        'payfort'     => 'https://sbpaymentservices.payfort.com/FortAPI/paymentApi',
        'hyperpay'    => 'https://eu-test.oppwa.com/v1/paymentmethods',
        'tap'         => 'https://api.tap.company/v2/charges/',
        'paymob'      => 'https://accept.paymob.com/api/auth/tokens',
        'flutterwave' => 'https://api.flutterwave.com/v3/transactions',
        'paystack'    => 'https://api.paystack.co/transaction',
        'mpesa'       => 'https://sandbox.safaricom.co.ke/oauth/v1/generate',
        'razorpay'    => 'https://api.razorpay.com/v1/payments',
        'klarna'      => 'https://api.klarna.com/payments/v1/sessions',
        'moonpay'     => ($env === 'live' ? 'https://buy.moonpay.com/' : 'https://buy-sandbox.moonpay.com/'),
    ];

    $url = $endpoints[$code] ?? null;

    if ($code === 'moonpay') {
        $missing = [];
        if (empty($credentials['api_key'])) {
            $missing[] = 'api_key';
        }
        if (empty($credentials['secret_key'])) {
            $missing[] = 'secret_key';
        }

        $urls = $config['urls'] ?? [];
        if (empty($urls['webhook'])) {
            $missing[] = 'webhook';
        }
        if (empty($urls['success'])) {
            $missing[] = 'success';
        }
        if (empty($urls['cancel'])) {
            $missing[] = 'cancel';
        }

        if (!empty($missing)) {
            $hint = 'MoonPay يحتاج: ' . implode(', ', $missing) . '. أضف الحقول المطلوبة من لوحة الإدارة.';
            $label = '⚠️ MoonPay ناقص الإعداد';
            $status = 'partial_ready';
            if (in_array('api_key', $missing, true) || in_array('secret_key', $missing, true)) {
                $status = 'no_credentials';
                $label = '⚠️ بيانات MoonPay ناقصة';
            }
            return [
                'status' => $status,
                'label' => $label,
                'ms' => null,
                'http' => null,
                'hint' => $hint
            ];
        }

        return [
            'status' => 'connected',
            'label' => '✅ MoonPay مفعل',
            'ms' => null,
            'http' => null,
            'hint' => 'MoonPay live mode جاهز. تأكد من أن webhook و success/cancel URLs مطابقة لإعدادات MoonPay.'
        ];
    }

    // إذا لا يوجد endpoint معروف — اختبر بـ ping DNS فقط
    if (!$url) {
        // استخراج domain من webhook أو success url
        $urlsConf = $config['urls'] ?? [];
        $anyUrl   = $urlsConf['webhook'] ?? $urlsConf['success'] ?? '';
        if (!empty($anyUrl)) {
            $parsed = parse_url('https' . str_replace('http', '', $anyUrl));
            $host   = $parsed['host'] ?? '';
            if ($host) {
                $ip = gethostbyname($host);
                $ms = round((microtime(true) - $start) * 1000, 1);
                if ($ip !== $host) {
                    return ['status' => 'dns_ok', 'label' => '🔵 DNS يستجيب', 'ms' => $ms, 'http' => null, 'note' => 'لا يوجد test endpoint — DNS فقط'];
                }
                return ['status' => 'dns_fail', 'label' => '❌ DNS فشل', 'ms' => $ms, 'http' => null];
            }
        }
        return [
            'status' => 'unknown',
            'label' => '🔘 لا يوجد endpoint للفحص',
            'ms' => null,
            'http' => null,
            'hint' => 'لم يتم تحديد webhook أو success URL في إعدادات البوابة. أضف عنوان URL صالحاً كي يمكن فحص الاتصال.'
        ];
    }

    // ── إرسال طلب HTTP ──
    $headers = ['Authorization: Bearer ' . $token, 'Content-Type: application/json'];

    // بعض البوابات تحتاج Basic Auth
    if ($code === 'paypal') {
        $clientId = trim($creds['client_id'] ?? '');
        $secret   = trim($creds['secret']    ?? $creds['secret_key'] ?? '');
        $headers  = ['Content-Type: application/x-www-form-urlencoded'];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
            CURLOPT_USERPWD        => $clientId . ':' . $secret,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
    } else {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
    }

    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    $ms = round((microtime(true) - $start) * 1000, 1);

    if ($curlErr) {
        return ['status' => 'error', 'label' => '❌ خطأ: ' . $curlErr, 'ms' => $ms, 'http' => null];
    }

    // تفسير الكود
    if ($httpCode >= 200 && $httpCode < 300) {
        return ['status' => 'connected', 'label' => '✅ متصل', 'ms' => $ms, 'http' => $httpCode];
    } elseif ($httpCode === 401 || $httpCode === 403) {
        return ['status' => 'auth_fail', 'label' => '🔑 خطأ في المصادقة (HTTP ' . $httpCode . ')', 'ms' => $ms, 'http' => $httpCode];
    } elseif ($httpCode === 404) {
        return ['status' => 'not_found', 'label' => '⚠️ Endpoint غير موجود (404)', 'ms' => $ms, 'http' => $httpCode];
    } elseif ($httpCode >= 500) {
        return ['status' => 'server_error', 'label' => '🔴 خطأ في خادم البوابة (' . $httpCode . ')', 'ms' => $ms, 'http' => $httpCode];
    } elseif ($httpCode === 0) {
        return ['status' => 'timeout', 'label' => '⏱️ انتهت المهلة (timeout)', 'ms' => $ms, 'http' => null];
    }

    return ['status' => 'unknown_code', 'label' => 'HTTP ' . $httpCode, 'ms' => $ms, 'http' => $httpCode];
}

// ── تنفيذ الفحص عند الطلب ──────────────────────────────────
$testResults    = [];
$runTest        = isset($_GET['run']) && $_GET['run'] === '1';
$gatewayToTest  = isset($_GET['gateway']) ? strtolower(trim($_GET['gateway'])) : null;

function resolveGatewayTestList(array $dbGws, ?string $gatewayToTest): array {
    if (!$gatewayToTest) {
        return $dbGws;
    }
    return array_values(array_filter($dbGws, fn($gw) => strtolower($gw['code']) === $gatewayToTest));
}

if ($runTest) {
    foreach (resolveGatewayTestList($dbGws, $gatewayToTest) as $gw) {
        $config = getGatewayConfig($gw['code']) ?: [];
        $dbConfig = json_decode($gw['config'] ?? '{}', true) ?: [];
        $dbCredentials = json_decode($gw['credentials'] ?? '{}', true) ?: [];
        $dbSettings = json_decode($gw['settings'] ?? '{}', true) ?: [];

        $config['credentials'] = array_merge($config['credentials'] ?? [], $dbCredentials);
        $config['urls'] = array_merge($config['urls'] ?? [], $dbSettings);
        $config['environment'] = $dbConfig['environment'] ?? $config['environment'] ?? 'sandbox';

        $testResults[$gw['code']] = testGatewayConnection($gw['code'], $config);
    }
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang ?? 'ar') ?>" dir="<?= htmlspecialchars($pageDir ?? 'rtl') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DI PARMA | فحص البوابات</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Cairo',sans-serif; background:#0a0f1e; color:#FFDFA0; min-height:100vh; padding:20px; }
.container { max-width:1200px; margin:0 auto; }
.header { display:flex; justify-content:space-between; align-items:center; margin-bottom:25px; padding:20px 25px; background:rgba(10,16,39,0.94); border:1px solid rgba(255,215,0,0.25); border-radius:16px; flex-wrap:wrap; gap:12px; }
.header h1 { font-size:1.6rem; background:linear-gradient(135deg,#FFE066,#FFD700); -webkit-background-clip:text; -webkit-text-fill-color:transparent; }
.btn { display:inline-flex; align-items:center; gap:8px; padding:10px 22px; background:linear-gradient(135deg,#FFD700,#B58E15); color:#000; border:none; border-radius:10px; text-decoration:none; font-weight:700; cursor:pointer; font-family:'Cairo',sans-serif; font-size:0.95rem; transition:opacity 0.2s; }
.btn:hover { opacity:0.88; }
.btn-outline { background:transparent; border:1.5px solid rgba(255,215,0,0.4); color:#FFD700; }
.card { background:rgba(10,16,39,0.94); border:1px solid rgba(255,215,0,0.2); border-radius:16px; padding:22px; margin-bottom:20px; }
.stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:15px; margin-bottom:25px; }
.stat-box { background:rgba(10,16,39,0.94); border:1px solid rgba(255,215,0,0.2); border-radius:14px; padding:18px; text-align:center; }
.stat-box .num { font-size:2rem; font-weight:800; color:#FFD700; }
.stat-box .lbl { font-size:0.85rem; color:#aaa; margin-top:4px; }
table { width:100%; border-collapse:collapse; }
th,td { padding:12px 14px; text-align:right; border-bottom:1px solid rgba(255,215,0,0.08); font-size:0.9rem; }
th { background:rgba(255,215,0,0.06); color:#FFD700; font-weight:700; font-size:0.85rem; }
tr:hover td { background:rgba(255,215,0,0.03); }
.badge { display:inline-block; padding:4px 12px; border-radius:999px; font-size:0.8rem; font-weight:600; }
.badge-connected   { background:rgba(76,175,80,0.2);   color:#4CAF50; }
.badge-auth_fail   { background:rgba(240,173,78,0.2);  color:#f0ad4e; }
.badge-error       { background:rgba(217,83,79,0.2);   color:#d9534f; }
.badge-partial_ready { background:rgba(255,193,7,0.18); color:#FFC107; }
.badge-timeout     { background:rgba(217,83,79,0.15);  color:#e57373; }
.badge-no_credentials { background:rgba(255,255,255,0.07); color:#aaa; }
.badge-dns_ok      { background:rgba(33,150,243,0.2);  color:#64B5F6; }
.badge-unknown, .badge-unknown_code { background:rgba(255,255,255,0.05); color:#888; }
.badge-server_error{ background:rgba(217,83,79,0.2);   color:#d9534f; }
.ms-bar { display:inline-block; height:6px; border-radius:3px; background:#FFD700; opacity:0.7; vertical-align:middle; margin-right:6px; }
.spinner { animation:spin 0.9s linear infinite; display:inline-block; }
@keyframes spin { to { transform:rotate(360deg); } }
.icon-col { width:40px; text-align:center; font-size:1.2rem; }
</style>
</head>
<body>
<div class="container">

    <!-- Header -->
    <div class="header">
        <div>
            <h1><i class="fas fa-plug"></i> فحص اتصال البوابات</h1>
            <p style="color:#aaa;margin-top:4px;font-size:0.88rem;">يختبر الاتصال الفعلي مع كل بوابة دفع مكتملة</p>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <a href="index.php" class="btn btn-outline"><i class="fas fa-home"></i> الرئيسية</a>
            <a href="dashboard.php" class="btn btn-outline"><i class="fas fa-chart-pie"></i> لوحة التحكم</a>
            <a href="gateway_check.php?run=1" class="btn"><i class="fas fa-play"></i> تشغيل الفحص</a>
            <a href="admin/gateway_manager.php" class="btn btn-outline"><i class="fas fa-cog"></i> إدارة البوابات</a>
        </div>
    </div>

    <?php if ($runTest && !empty($testResults)): ?>

    <!-- إحصائيات -->
    <?php
    $connected   = count(array_filter($testResults, fn($r) => $r['status'] === 'connected'));
    $authFail    = count(array_filter($testResults, fn($r) => $r['status'] === 'auth_fail'));
    $noCreds     = count(array_filter($testResults, fn($r) => $r['status'] === 'no_credentials'));
    $failed      = count($testResults) - $connected - $authFail - $noCreds;
    $avgMs       = array_filter(array_column($testResults, 'ms'));
    $avgMs       = $avgMs ? round(array_sum($avgMs) / count($avgMs)) : 0;
    ?>
    <div class="stats">
        <div class="stat-box">
            <div class="num" style="color:#4CAF50;"><?= $connected ?></div>
            <div class="lbl">✅ متصلة</div>
        </div>
        <div class="stat-box">
            <div class="num" style="color:#f0ad4e;"><?= $authFail ?></div>
            <div class="lbl">🔑 خطأ مصادقة</div>
        </div>
        <div class="stat-box">
            <div class="num" style="color:#888;"><?= $noCreds ?></div>
            <div class="lbl">⚠️ بيانات ناقصة</div>
        </div>
        <div class="stat-box">
            <div class="num" style="color:#d9534f;"><?= $failed ?></div>
            <div class="lbl">❌ فشل/timeout</div>
        </div>
        <div class="stat-box">
            <div class="num"><?= $avgMs ?> ms</div>
            <div class="lbl">⚡ متوسط الاستجابة</div>
        </div>
    </div>

    <!-- جدول النتائج -->
    <div class="card">
        <table>
            <thead>
                <tr>
                    <th class="icon-col"></th>
                    <th>البوابة</th>
                    <th>الكود</th>
                    <th>الحالة</th>
                    <th>وقت الاستجابة</th>
                    <th>HTTP</th>
                    <th>الإجراء</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($testResults as $code => $result):
                $gwRow = null;
                foreach ($dbGws as $g) { if ($g['code'] === $code) { $gwRow = $g; break; } }
                $gwConfig = getGatewayConfig($code);
                $icon     = $gwConfig['icon'] ?? 'fas fa-credit-card';
                $name     = $gwConfig['name'] ?? $gwRow['name'] ?? $code;
                $env      = json_decode($gwRow['config'] ?? '{}', true)['environment'] ?? 'sandbox';
                $badgeClass = 'badge-' . $result['status'];
            ?>
                <tr>
                    <td class="icon-col"><i class="<?= htmlspecialchars($icon) ?>" style="color:#FFD700;"></i></td>
                    <td>
                        <strong><?= htmlspecialchars($name) ?></strong><br>
                        <span style="font-size:0.78rem;color:#888;"><?= htmlspecialchars($env) ?></span>
                    </td>
                    <td><code style="color:#FFD700;background:rgba(255,215,0,0.08);padding:2px 8px;border-radius:5px;"><?= htmlspecialchars($code) ?></code></td>
                    <td>
                        <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($result['label']) ?></span>
                        <?php if (!empty($result['hint'])): ?>
                            <div style="margin-top:6px;color:#ccc;font-size:0.8rem;line-height:1.4;"><?= htmlspecialchars($result['hint']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($result['ms'] !== null): ?>
                            <span class="ms-bar" style="width:<?= min($result['ms'] / 5, 80) ?>px;"></span>
                            <?= $result['ms'] ?> ms
                        <?php else: ?>
                            <span style="color:#666;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($result['http']): ?>
                            <span style="color:<?= $result['http'] < 300 ? '#4CAF50' : '#f0ad4e' ?>;"><?= $result['http'] ?></span>
                        <?php else: ?>
                            <span style="color:#666;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($result['status'] === 'no_credentials' || $result['status'] === 'auth_fail'): ?>
                            <a href="admin/gateway_manager.php?edit=<?= $gwRow['id'] ?>" style="color:#FFD700;font-size:0.82rem;"><i class="fas fa-edit"></i> تعديل البيانات</a>
                        <?php elseif ($result['status'] === 'connected'): ?>
                            <span style="color:#4CAF50;font-size:0.82rem;"><i class="fas fa-check"></i> جاهز</span>
                        <?php else: ?>
                            <a href="gateway_check.php?run=1<?= $gatewayToTest ? '&gateway=' . urlencode($gatewayToTest) : '' ?>" style="color:#aaa;font-size:0.82rem;"><i class="fas fa-redo"></i> إعادة</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p style="text-align:center;color:#666;font-size:0.82rem;margin-top:10px;">
        آخر فحص: <?= date('Y-m-d H:i:s') ?> &nbsp;|&nbsp;
        <a href="gateway_check.php?run=1" style="color:#FFD700;">إعادة الفحص</a>
    </p>

    <?php else: ?>

    <!-- حالة الانتظار -->
    <div class="card" style="text-align:center;padding:50px 20px;">
        <i class="fas fa-plug" style="font-size:4rem;color:rgba(255,215,0,0.3);margin-bottom:20px;display:block;"></i>
        <h2 style="color:#FFD700;margin-bottom:10px;">فحص اتصال البوابات</h2>
        <p style="color:#aaa;margin-bottom:25px;line-height:1.8;">
            اضغط على <strong style="color:#FFD700;">تشغيل الفحص</strong> لاختبار الاتصال الفعلي مع جميع البوابات المكتملة.<br>
            يُرسَل طلب HTTP حقيقي لكل بوابة ويُقاس وقت الاستجابة.
        </p>

        <!-- قائمة البوابات المكتملة -->
        <?php if (!empty($dbGws)): ?>
        <div style="display:flex;flex-wrap:wrap;gap:10px;justify-content:center;margin-bottom:25px;">
            <?php foreach ($dbGws as $gw):
                $cfg  = getGatewayConfig($gw['code']);
                $icon = $cfg['icon'] ?? 'fas fa-credit-card';
            ?>
            <span style="padding:6px 14px;border-radius:20px;background:rgba(255,215,0,0.08);border:1px solid rgba(255,215,0,0.2);font-size:0.85rem;">
                <i class="<?= htmlspecialchars($icon) ?>" style="color:#FFD700;margin-left:5px;"></i>
                <?= htmlspecialchars($cfg['name'] ?? $gw['name']) ?>
            </span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <a href="gateway_check.php?run=1" class="btn" style="font-size:1rem;padding:12px 30px;">
            <i class="fas fa-play"></i> تشغيل الفحص الآن
        </a>
    </div>

    <?php endif; ?>
</div>
</body>
</html>
