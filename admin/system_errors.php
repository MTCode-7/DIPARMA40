<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();
$db = db();
$csrfToken = generateCsrfToken();
$repairMessage = '';
$repairType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $repairMessage = 'رمز الحماية غير صالح.';
        $repairType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'repair_dirs') {
                $created = [];
                foreach ([LOGS_PATH, CACHE_PATH, BACKUP_PATH, TEMP_PATH, ROOT_PATH . '/private_uploads', ROOT_PATH . '/uploads'] as $dir) {
                    if (!is_dir($dir)) {
                        if (!mkdir($dir, 0750, true)) throw new RuntimeException('تعذر إنشاء: ' . $dir);
                        $created[] = $dir;
                    }
                }
                $repairMessage = $created ? 'تم إنشاء المجلدات الناقصة.' : 'المجلدات الأساسية موجودة ولا تحتاج إصلاحًا.';
            } elseif ($action === 'repair_notifications') {
                $db->execute("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "notifications` (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id INT UNSIGNED NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    message TEXT DEFAULT NULL,
                    `read` TINYINT(1) NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $repairMessage = 'تم تجهيز جدول الإشعارات بنجاح.';
            } elseif ($action === 'test_db') {
                $db->query('SELECT 1');
                $repairMessage = 'اتصال قاعدة البيانات يعمل بنجاح.';
            } else {
                throw new RuntimeException('إجراء غير معروف.');
            }
        } catch (Throwable $e) {
            $repairMessage = 'تعذر الإصلاح: ' . $e->getMessage();
            $repairType = 'error';
        }
    }
}

function readErrorLines(string $file, int $limit = 40): array {
    if (!is_file($file) || !is_readable($file)) return [];
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return [];
    return array_slice(array_reverse($lines), 0, $limit);
}

function diagnoseError(string $line): array {
    $lower = strtolower($line);
    if (str_contains($lower, 'sqlstate') || str_contains($lower, 'query error') || str_contains($lower, 'database')) {
        return ['قاعدة البيانات أو استعلام SQL', 'افحص اتصال MySQL والجداول والحقول المطلوبة.', 'test_db'];
    }
    if (str_contains($lower, 'permission') || str_contains($lower, 'unable to move') || str_contains($lower, 'mkdir')) {
        return ['صلاحيات أو مجلد مفقود', 'أنشئ المجلدات الأساسية وتحقق من صلاحيات مجلدات الرفع والسجلات.', 'repair_dirs'];
    }
    if (str_contains($lower, 'gateway') || str_contains($lower, 'curl') || str_contains($lower, 'timeout')) {
        return ['بوابة دفع أو اتصال خارجي', 'افحص اتصال البوابة ومفاتيحها وبيئة Live من إدارة البوابات.', 'none'];
    }
    return ['خطأ تطبيق عام', 'راجع تفاصيل السجل ثم افحص آخر تعديل أو الملف المذكور.', 'none'];
}

$logFiles = [
    'PHP' => LOGS_PATH . '/php_errors.log',
    'System' => LOGS_PATH . '/system.log',
    'Gateway' => LOGS_PATH . '/gateway_processor.log',
];
$errors = [];
foreach ($logFiles as $source => $file) {
    foreach (readErrorLines($file) as $line) {
        [$cause, $fix, $action] = diagnoseError($line);
        $errors[] = ['source' => $source, 'line' => $line, 'cause' => $cause, 'fix' => $fix, 'action' => $action];
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | أخطاء النظام</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}body{margin:0;background:#080d17;color:#f0f0f0;font-family:Cairo,Arial,sans-serif;padding:20px}.wrap{max-width:1400px;margin:auto}.top{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:20px}.brand{color:#ffd700;font-weight:900;font-size:1.15rem}.links{display:flex;gap:8px;flex-wrap:wrap}.links a,.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 13px;border-radius:9px;border:1px solid rgba(255,215,0,.25);background:rgba(255,215,0,.06);color:#ffd700;text-decoration:none;font:inherit;font-size:.78rem;cursor:pointer}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-bottom:20px}.stat,.card{background:#101827;border:1px solid rgba(255,215,0,.16);border-radius:14px;padding:18px}.stat strong{display:block;color:#ffd700;font-size:1.5rem}.stat span{color:#999;font-size:.75rem}.alert{padding:12px 15px;border-radius:10px;margin-bottom:18px;background:rgba(16,185,129,.1);border:1px solid #10b981;color:#80f0c0;font-size:.82rem}.alert.error{background:rgba(239,68,68,.1);border-color:#ef4444;color:#ff9a9a}.actions{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px}.btn{background:linear-gradient(135deg,#ffd700,#ffb700);color:#000;font-weight:800}.card h2{margin:0 0 15px;color:#ffd700;font-size:1rem}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:900px;font-size:.78rem}th,td{padding:10px;border-bottom:1px solid rgba(255,255,255,.07);text-align:right;vertical-align:top}th{color:#ffd700;background:rgba(255,215,0,.05)}.source{color:#7dd3fc}.cause{color:#fbbf24}.fix{color:#a7f3d0}.log{color:#aaa;direction:ltr;text-align:left;max-width:420px;white-space:pre-wrap;word-break:break-word}
</style>
</head>
<body><main class="wrap">
<header class="top"><div class="brand">DIPARMA ULTIMATE GATEWAY | أخطاء النظام</div><nav class="links"><a href="../index.php">الرئيسية</a><a href="user_approvals.php">الموافقات</a><a href="../logout.php">خروج</a></nav></header>
<?php if ($repairMessage): ?><div class="alert <?= $repairType === 'error' ? 'error' : '' ?>"><?= htmlspecialchars($repairMessage) ?></div><?php endif; ?>
<section class="grid"><div class="stat"><strong><?= count($errors) ?></strong><span>الأخطاء المسجلة في آخر السجلات</span></div><div class="stat"><strong><?= is_writable(LOGS_PATH) ? 'OK' : '!' ?></strong><span>صلاحية مجلد السجلات</span></div><div class="stat"><strong><?= is_writable(CACHE_PATH) ? 'OK' : '!' ?></strong><span>صلاحية مجلد الكاش</span></div><div class="stat"><strong><?= extension_loaded('pdo_mysql') ? 'OK' : '!' ?></strong><span>امتداد MySQL</span></div></section>
<section class="actions"><form method="POST"><input type="hidden" name="csrf_token" value="<?= $csrfToken ?>"><input type="hidden" name="action" value="repair_dirs"><button class="btn">إصلاح المجلدات</button></form><form method="POST"><input type="hidden" name="csrf_token" value="<?= $csrfToken ?>"><input type="hidden" name="action" value="repair_notifications"><button class="btn">إصلاح جدول الإشعارات</button></form><form method="POST"><input type="hidden" name="csrf_token" value="<?= $csrfToken ?>"><input type="hidden" name="action" value="test_db"><button class="btn">فحص قاعدة البيانات</button></form></section>
<section class="card"><h2>الأخطاء والأسباب والحلول</h2><?php if (!$errors): ?><p style="color:#80f0c0">لا توجد أخطاء مسجلة في ملفات السجل المتاحة.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>المصدر</th><th>الخطأ</th><th>السبب المحتمل</th><th>الإصلاح المقترح</th></tr></thead><tbody><?php foreach ($errors as $error): ?><tr><td class="source"><?= htmlspecialchars($error['source']) ?></td><td class="log"><?= htmlspecialchars($error['line']) ?></td><td class="cause"><?= htmlspecialchars($error['cause']) ?></td><td class="fix"><?= htmlspecialchars($error['fix']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
</main></body></html>
