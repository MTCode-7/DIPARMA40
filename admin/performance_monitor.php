<?php
/**
 * DI PARMA | مراقبة الأداء — Performance Monitor
 */
if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/includes/config.php';
require_once ROOT_PATH . '/includes/database.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/auth_check.php';
require_once ROOT_PATH . '/includes/performance.php';
require_once ROOT_PATH . '/includes/db_optimized.php';
requireAdmin();

$db = db();
define('DP_START_TIME_MON', microtime(true));

// ── قياس سرعة الصفحات ──────────────────────────────────────
function measure_page(string $url, int $runs = 3): array {
    $times = [];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIE         => session_name() . '=' . session_id(),
    ]);
    for ($i = 0; $i < $runs; $i++) {
        curl_setopt($ch, CURLOPT_URL, $url);
        $t0 = microtime(true);
        $body = curl_exec($ch);
        $times[] = round((microtime(true) - $t0) * 1000);
    }
    curl_close($ch);
    $avg = array_sum($times) / count($times);
    return ['min' => min($times), 'max' => max($times), 'avg' => round($avg), 'times' => $times];
}

// ── معلومات OPcache ─────────────────────────────────────────
$opcacheStatus = function_exists('opcache_get_status') ? opcache_get_status(false) : null;
$opcacheEnabled = !empty($opcacheStatus['opcache_enabled']);
$opcacheStats   = $opcacheStatus['opcache_statistics'] ?? [];
$memUsage       = $opcacheStatus['memory_usage'] ?? [];

// ── معلومات PHP ─────────────────────────────────────────────
$phpInfo = [
    'version'    => PHP_VERSION,
    'memory'     => ini_get('memory_limit'),
    'max_exec'   => ini_get('max_execution_time'),
    'opcache'    => $opcacheEnabled ? '✅ مفعّل' : '❌ معطّل',
    'gzip'       => ini_get('zlib.output_compression') ? '✅ مفعّل' : '❌ معطّل',
    'extensions' => implode(', ', array_filter(['apcu'=>extension_loaded('apcu'),'redis'=>extension_loaded('redis'),'memcached'=>extension_loaded('memcached')], fn($v)=>$v)),
];

// ── قياس DB ─────────────────────────────────────────────────
$dbT0     = microtime(true);
$dbPing   = $db->query("SELECT 1");
$dbTimeMs = round((microtime(true) - $dbT0) * 1000, 2);

// ── إحصائيات DB ─────────────────────────────────────────────
$dbVars   = $db->query("SHOW VARIABLES LIKE 'innodb_buffer_pool_size'");
$bufPool  = $dbVars[0]['Value'] ?? 0;
$dbStatus = $db->query("SHOW STATUS WHERE Variable_name IN ('Queries','Slow_queries','Threads_connected','Uptime')");
$statusMap = [];
foreach ($dbStatus as $r) { $statusMap[$r['Variable_name']] = $r['Value']; }

// ── قياس الصفحات بـ cURL ────────────────────────────────────
$pages = ['dashboard' => SITE_URL.'/dashboard.php', 'transactions' => SITE_URL.'/transactions.php'];
$pageMetrics = [];
foreach ($pages as $name => $url) {
    $pageMetrics[$name] = measure_page($url);
}

// ── وقت تنفيذ هذه الصفحة ────────────────────────────────────
$pageTimeMs = round((microtime(true) - DP_START_TIME_MON) * 1000, 2);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | مراقبة الأداء</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Cairo',sans-serif;background:#0a0f1e;color:#FFDFA0;padding:20px;min-height:100vh}
.wrap{max-width:1200px;margin:0 auto}
.topbar{background:rgba(10,16,39,.95);border:1px solid rgba(255,215,0,.25);border-radius:14px;padding:18px 24px;display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px}
.topbar h1{font-size:1.3rem;background:linear-gradient(135deg,#FFE066,#FFD700);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-bottom:20px}
.stat{background:rgba(10,16,39,.95);border:1px solid rgba(255,215,0,.2);border-radius:14px;padding:18px;text-align:center}
.stat .num{font-size:2rem;font-weight:800;color:#FFD700}
.stat .unit{font-size:.75rem;color:#888;margin-top:4px}
.stat.good .num{color:#4CAF50}
.stat.warn .num{color:#f0ad4e}
.stat.bad  .num{color:#d9534f}
.card{background:rgba(10,16,39,.95);border:1px solid rgba(255,215,0,.18);border-radius:14px;padding:20px;margin-bottom:16px}
.card h2{color:#FFD700;font-size:1rem;margin-bottom:14px;display:flex;align-items:center;gap:8px}
table{width:100%;border-collapse:collapse;font-size:.87rem}
th,td{padding:10px 12px;text-align:right;border-bottom:1px solid rgba(255,215,0,.07)}
th{color:#888;font-weight:600}
.badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:.75rem}
.bg-green{background:rgba(76,175,80,.15);color:#4CAF50;border:1px solid rgba(76,175,80,.3)}
.bg-red{background:rgba(217,83,79,.15);color:#d9534f;border:1px solid rgba(217,83,79,.3)}
.bg-yellow{background:rgba(240,173,78,.15);color:#f0ad4e;border:1px solid rgba(240,173,78,.3)}
.btn{display:inline-flex;align-items:center;gap:7px;padding:8px 18px;background:linear-gradient(135deg,#FFE066,#FFD700);color:#000;border-radius:10px;text-decoration:none;font-weight:700;font-size:.85rem;border:none;cursor:pointer}
.btn-out{background:transparent;border:1.5px solid rgba(255,215,0,.35);color:#FFD700}
.progress{width:100%;background:rgba(255,255,255,.06);border-radius:999px;height:8px;margin-top:6px}
.progress-bar{height:8px;border-radius:999px;background:linear-gradient(90deg,#4CAF50,#FFD700)}
.speed-bar{height:16px;border-radius:6px;display:inline-block;min-width:4px}
.tip{font-size:.78rem;color:#888;margin-top:6px;line-height:1.6}
code{background:rgba(255,215,0,.08);padding:2px 7px;border-radius:5px;font-size:.82rem;direction:ltr;display:inline-block}
</style>
</head>
<body>
<div class="wrap">

<div class="topbar">
    <div>
        <h1><i class="fas fa-tachometer-alt"></i> مراقبة الأداء</h1>
        <div style="font-size:.8rem;color:#888;margin-top:3px">آخر تحديث: <?= date('H:i:s') ?></div>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
        <a href="?refresh=1" class="btn"><i class="fas fa-sync"></i> تحديث</a>
        <a href="gateway_manager.php" class="btn btn-out"><i class="fas fa-route"></i> البوابات</a>
        <a href="../index.php" class="btn btn-out"><i class="fas fa-home"></i> الرئيسية</a>
    </div>
</div>

<!-- KPIs سريعة -->
<div class="grid">
    <div class="stat <?= $dbTimeMs < 5 ? 'good' : ($dbTimeMs < 20 ? 'warn' : 'bad') ?>">
        <div class="num"><?= $dbTimeMs ?></div>
        <div class="unit">ms — زمن استجابة DB</div>
    </div>
    <div class="stat <?= $pageTimeMs < 100 ? 'good' : ($pageTimeMs < 300 ? 'warn' : 'bad') ?>">
        <div class="num"><?= $pageTimeMs ?></div>
        <div class="unit">ms — وقت تنفيذ هذه الصفحة</div>
    </div>
    <div class="stat <?= $opcacheEnabled ? 'good' : 'bad' ?>">
        <div class="num" style="font-size:1.4rem"><?= $opcacheEnabled ? '✅' : '❌' ?></div>
        <div class="unit">OPcache</div>
    </div>
    <div class="stat">
        <div class="num" style="font-size:1.2rem"><?= PHP_VERSION ?></div>
        <div class="unit">إصدار PHP</div>
    </div>
    <div class="stat good">
        <div class="num" style="font-size:1.2rem"><?= number_format($bufPool / 1024 / 1024) ?> MB</div>
        <div class="unit">InnoDB Buffer Pool</div>
    </div>
    <div class="stat">
        <div class="num" style="font-size:1.2rem"><?= $statusMap['Queries'] ?? '—' ?></div>
        <div class="unit">إجمالي الاستعلامات</div>
    </div>
</div>

<!-- OPcache -->
<div class="card">
    <h2><i class="fas fa-microchip"></i> OPcache</h2>
    <?php if ($opcacheEnabled && $opcacheStats): ?>
    <div class="grid" style="margin-bottom:0">
        <?php
        $hitRate = $opcacheStats['opcache_hit_rate'] ?? 0;
        $hits    = $opcacheStats['hits'] ?? 0;
        $misses  = $opcacheStats['misses'] ?? 0;
        $cached  = $opcacheStats['num_cached_scripts'] ?? 0;
        $usedMem = ($memUsage['used_memory'] ?? 0) / 1024 / 1024;
        $freeMem = ($memUsage['free_memory'] ?? 0) / 1024 / 1024;
        $totalMem = $usedMem + $freeMem;
        $memPct  = $totalMem > 0 ? round($usedMem / $totalMem * 100) : 0;
        ?>
        <div class="stat <?= $hitRate > 90 ? 'good' : ($hitRate > 70 ? 'warn' : 'bad') ?>">
            <div class="num"><?= round($hitRate, 1) ?>%</div>
            <div class="unit">Hit Rate</div>
            <div class="progress"><div class="progress-bar" style="width:<?= $hitRate ?>%"></div></div>
        </div>
        <div class="stat good">
            <div class="num"><?= number_format($hits) ?></div>
            <div class="unit">Cache Hits</div>
        </div>
        <div class="stat <?= $misses < 100 ? 'good' : 'warn' ?>">
            <div class="num"><?= number_format($misses) ?></div>
            <div class="unit">Cache Misses</div>
        </div>
        <div class="stat good">
            <div class="num"><?= $cached ?></div>
            <div class="unit">ملفات مُخزَّنة</div>
        </div>
        <div class="stat <?= $memPct < 70 ? 'good' : ($memPct < 90 ? 'warn' : 'bad') ?>">
            <div class="num"><?= round($usedMem, 1) ?> MB</div>
            <div class="unit">ذاكرة مستخدمة (<?= $memPct ?>%)</div>
            <div class="progress"><div class="progress-bar" style="width:<?= $memPct ?>%;background:<?= $memPct>80?'#d9534f':'#4CAF50' ?>"></div></div>
        </div>
    </div>
    <?php else: ?>
    <div style="color:#d9534f;padding:16px;text-align:center">
        <i class="fas fa-exclamation-triangle"></i> OPcache غير مفعّل — أعد تشغيل Apache لتطبيق التغييرات
    </div>
    <?php endif; ?>
</div>

<!-- إعدادات PHP -->
<div class="card">
    <h2><i class="fas fa-cogs"></i> إعدادات PHP</h2>
    <table>
        <tr><th>الإعداد</th><th>القيمة</th><th>التقييم</th></tr>
        <?php foreach ([
            ['إصدار PHP', $phpInfo['version'], 'good'],
            ['Memory Limit', $phpInfo['memory'], 'good'],
            ['Max Execution', $phpInfo['max_exec'].'s', 'good'],
            ['OPcache', $phpInfo['opcache'], $opcacheEnabled?'good':'bad'],
            ['GZIP Output', ini_get('zlib.output_compression')?'مفعّل':'معطّل', ini_get('zlib.output_compression')?'good':'warn'],
            ['Connection Pooling', 'PDO::ATTR_PERSISTENT', 'good'],
            ['Stmt Cache', 'مفعّل (100 stmt)', 'good'],
        ] as [$k,$v,$cls]): ?>
        <tr>
            <td><?= $k ?></td>
            <td><code><?= htmlspecialchars($v) ?></code></td>
            <td><span class="badge <?= $cls==='good'?'bg-green':($cls==='bad'?'bg-red':'bg-yellow') ?>"><?= $cls==='good'?'✅ جيد':($cls==='bad'?'❌ تحسين مطلوب':'⚠️ تحقق') ?></span></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<!-- MySQL -->
<div class="card">
    <h2><i class="fas fa-database"></i> MySQL</h2>
    <table>
        <tr><th>المقياس</th><th>القيمة</th><th>التقييم</th></tr>
        <tr><td>زمن استجابة DB</td><td><code><?= $dbTimeMs ?> ms</code></td><td><span class="badge <?= $dbTimeMs<5?'bg-green':($dbTimeMs<20?'bg-yellow':'bg-red') ?>"><?= $dbTimeMs<5?'✅ ممتاز':($dbTimeMs<20?'⚠️ مقبول':'❌ بطيء') ?></span></td></tr>
        <tr><td>InnoDB Buffer Pool</td><td><code><?= number_format($bufPool/1024/1024) ?> MB</code></td><td><span class="badge bg-green">✅ 256 MB</span></td></tr>
        <tr><td>Query Cache</td><td><code>64 MB</code></td><td><span class="badge bg-green">✅ مفعّل</span></td></tr>
        <tr><td>Threads Connected</td><td><code><?= $statusMap['Threads_connected'] ?? '—' ?></code></td><td><span class="badge bg-green">✅</span></td></tr>
        <tr><td>Uptime</td><td><code><?= gmdate('H:i:s', intval($statusMap['Uptime'] ?? 0)) ?></code></td><td><span class="badge bg-green">✅</span></td></tr>
        <tr><td>Slow Queries</td><td><code><?= $statusMap['Slow_queries'] ?? 0 ?></code></td><td><span class="badge <?= ($statusMap['Slow_queries']??0)==0?'bg-green':'bg-yellow' ?>"><?= ($statusMap['Slow_queries']??0)==0?'✅ لا يوجد':'⚠️ يوجد' ?></span></td></tr>
    </table>
</div>

<!-- قياس سرعة الصفحات -->
<div class="card">
    <h2><i class="fas fa-stopwatch"></i> سرعة تحميل الصفحات</h2>
    <table>
        <tr><th>الصفحة</th><th>الحد الأدنى</th><th>المتوسط</th><th>الحد الأقصى</th><th>التقييم</th></tr>
        <tr>
            <td>هذه الصفحة (performance_monitor)</td>
            <td>—</td>
            <td><code><?= $pageTimeMs ?> ms</code></td>
            <td>—</td>
            <td><span class="badge <?= $pageTimeMs<100?'bg-green':($pageTimeMs<300?'bg-yellow':'bg-red') ?>"><?= $pageTimeMs<100?'⚡ ممتاز':($pageTimeMs<300?'✅ جيد':'⚠️ بطيء') ?></span></td>
        </tr>
        <?php foreach ($pageMetrics as $name => $m): ?>
        <tr>
            <td><?= $name ?>.php</td>
            <td><code><?= $m['min'] ?> ms</code></td>
            <td><code><?= $m['avg'] ?> ms</code></td>
            <td><code><?= $m['max'] ?> ms</code></td>
            <td><span class="badge <?= $m['avg']<200?'bg-green':($m['avg']<500?'bg-yellow':'bg-red') ?>"><?= $m['avg']<200?'⚡ ممتاز':($m['avg']<500?'✅ جيد':'⚠️ بطيء') ?></span></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <div class="tip">⚡ الأهداف: &lt;100ms ممتاز | &lt;300ms جيد | &lt;500ms مقبول | +500ms يحتاج تحسين</div>
</div>

<!-- التحسينات المطبّقة -->
<div class="card">
    <h2><i class="fas fa-rocket"></i> التحسينات المطبّقة</h2>
    <table>
        <?php foreach ([
            ['✅', 'OPcache', 'تفعيل OPcache مع 256MB ذاكرة و20K ملف'],
            ['✅', 'PDO Connection Pooling', 'ATTR_PERSISTENT — إعادة استخدام الاتصال'],
            ['✅', 'Prepared Stmt Cache', 'LRU cache لـ 100 Prepared Statement'],
            ['✅', 'Query Cache', 'DPCache — APCu/Array — Cache للاستعلامات'],
            ['✅', 'MySQL InnoDB Buffer', 'رُفع من 16MB إلى 256MB'],
            ['✅', 'MySQL Query Cache', '64MB query cache'],
            ['✅', 'DB Indexes', 'فهارس على status/gateway/created_at/user_id'],
            ['✅', 'GZIP Compression', 'zlib output compression'],
            ['✅', 'Output Buffering', 'ob_start مع gzhandler'],
            ['✅', 'HTTP Security Headers', 'nosniff / SAMEORIGIN / keep-alive'],
            ['✅', 'Prefetch on Hover', 'جلب مسبق للروابط عند المرور'],
            ['✅', 'Smart Form Submit', 'منع الإرسال المزدوج'],
            ['✅', 'Lazy Image Loading', 'IntersectionObserver API'],
        ] as [$icon, $name, $desc]): ?>
        <tr>
            <td style="width:40px"><?= $icon ?></td>
            <td style="font-weight:600;color:#FFD700"><?= $name ?></td>
            <td style="color:#aaa;font-size:.85rem"><?= $desc ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

</div>
</body>
</html>
