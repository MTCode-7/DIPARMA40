<?php
// ============================================================
// DI PARMA | تكوين النظام — يدعم البيئة المحلية والإنتاجية
// ============================================================

// ── [1] المسارات الأساسية ────────────────────────────────────
if (!defined('ROOT_PATH'))      define('ROOT_PATH',      dirname(__DIR__));
if (!defined('INCLUDES_PATH'))  define('INCLUDES_PATH',  ROOT_PATH . '/includes');
if (!defined('ADMIN_PATH'))     define('ADMIN_PATH',     ROOT_PATH . '/admin');
if (!defined('PROTOCOLS_PATH')) define('PROTOCOLS_PATH', ROOT_PATH . '/protocols');
if (!defined('LOGS_PATH'))      define('LOGS_PATH',      ROOT_PATH . '/logs');
if (!defined('CACHE_PATH'))     define('CACHE_PATH',     ROOT_PATH . '/cache');
if (!defined('BACKUP_PATH'))    define('BACKUP_PATH',    ROOT_PATH . '/backups');
if (!defined('TEMP_PATH'))      define('TEMP_PATH',      ROOT_PATH . '/tmp');
if (!defined('LIB_PATH'))       define('LIB_PATH',       ROOT_PATH . '/lib');

// ── [2] قارئ .env ────────────────────────────────────────────
if (!function_exists('_dp_load_env')) {
    function _dp_load_env(string $file): void {
        if (!is_file($file) || !is_readable($file)) return;
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            // تخطي التعليقات
            if ($line === '' || $line[0] === '#') continue;
            if (!str_contains($line, '=')) continue;
            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value, " \t\"'");
            // لا تُعيد التعيين إذا كان موجوداً في $_ENV أو $_SERVER
            if (!array_key_exists($key, $_ENV) && !array_key_exists($key, $_SERVER)) {
                $_ENV[$key]    = $value;
                $_SERVER[$key] = $value;
                putenv("$key=$value");
            }
        }
    }
}
_dp_load_env(ROOT_PATH . '/.env');

// دالة مساعدة لقراءة قيمة من .env أو getenv
if (!function_exists('env')) {
    function env(string $key, $default = null) {
        $val = $_ENV[$key] ?? getenv($key);
        if ($val === false || $val === null || $val === '') return $default;
        // تحويل القيم النصية المنطقية
        if (in_array(strtolower((string)$val), ['true','yes','1'], true))  return true;
        if (in_array(strtolower((string)$val), ['false','no','0'], true))  return false;
        return $val;
    }
}

// ── [3] اكتشاف البيئة تلقائياً ──────────────────────────────
if (!function_exists('_dp_detect_env')) {
    function _dp_detect_env(): string {
        // 1. من .env
        $appEnv = env('APP_ENV', '');
        if (!empty($appEnv)) return strtolower(trim($appEnv));

        // 2. من متغير بيئة النظام
        $sysEnv = getenv('APP_ENV');
        if (!empty($sysEnv)) return strtolower(trim($sysEnv));

        // 3. كشف تلقائي عبر HTTP_HOST
        $host = $_SERVER['HTTP_HOST'] ?? php_uname('n');
        $localPatterns = ['localhost', '127.0.0.1', '::1', '.local', '.test', '.dev', '192.168.'];
        foreach ($localPatterns as $p) {
            if (str_contains($host, $p)) return 'local';
        }

        return 'production';
    }
}

$_DP_ENV = _dp_detect_env();
define('APP_ENV',       $_DP_ENV);
define('APP_IS_LOCAL',  $_DP_ENV === 'local');
define('APP_IS_PROD',   $_DP_ENV === 'production');

// ── [4] اكتشاف SITE_URL تلقائياً ────────────────────────────
if (!function_exists('_dp_detect_url')) {
    function _dp_detect_url(): string {
        // من .env أولاً
        $envUrl = env('SITE_URL', env('APP_URL', ''));
        if (!empty($envUrl)) {
            $envHost = strtolower((string) parse_url($envUrl, PHP_URL_HOST));
            $requestHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
            $isLocalEnvUrl = in_array($envHost, ['localhost', '127.0.0.1', '::1'], true);
            $requestHostWithoutPort = preg_replace('/:\d+$/', '', $requestHost);
            $isLocalRequest = in_array($requestHostWithoutPort, ['localhost', '127.0.0.1', '::1'], true);
            $isExternalRequest = $requestHost !== '' && !in_array(
                $requestHostWithoutPort,
                ['localhost', '127.0.0.1', '::1'],
                true
            );

            if ($isLocalRequest) {
                $httpsProto = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
                $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $httpsProto === 'https';
                $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
                $rootPath = realpath(ROOT_PATH) ?: '';
                $basePath = '';
                if ($documentRoot !== '' && $rootPath !== '' && str_starts_with($rootPath, $documentRoot)) {
                    $relativeRoot = trim(str_replace('\\', '/', substr($rootPath, strlen($documentRoot))), '/');
                    $basePath = $relativeRoot !== '' ? '/' . $relativeRoot : '';
                }
                return ($isHttps ? 'https://' : 'http://') . $requestHost . $basePath;
            }

            if ($isExternalRequest && $requestHostWithoutPort === $envHost) {
                $httpsProto = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
                $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                    || $httpsProto === 'https'
                    || strtolower($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on'
                    || str_contains($_SERVER['HTTP_CF_VISITOR'] ?? '', '"scheme":"https"');
                return ($isHttps ? 'https://' : 'http://') . $requestHost;
            }

            // Keep the session on the host that started the request.
            if ($isExternalRequest && $requestHostWithoutPort !== $envHost) {
                $httpsProto = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
                $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                    || $httpsProto === 'https'
                    || strtolower($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on'
                    || str_contains($_SERVER['HTTP_CF_VISITOR'] ?? '', '"scheme":"https"');
                return ($isHttps ? 'https://' : 'http://') . $requestHost;
            }

            // Do not redirect production requests to a local development URL.
            if (!($isLocalEnvUrl && APP_IS_PROD && $isExternalRequest)) {
                return rtrim($envUrl, '/');
            }
        }

        // اكتشاف البروتوكول الحقيقي خاصةً عند استخدام Cloudflare أو وكيل عكسي
        $httpsProto = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
        $httpsSsl   = strtolower($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '');
        $cfVisitor  = $_SERVER['HTTP_CF_VISITOR'] ?? '';
        $isHttps = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || $httpsProto === 'https'
            || $httpsSsl === 'on'
            || str_contains($cfVisitor, '"scheme":"https"')
        );

        $scheme   = $isHttps ? 'https' : 'http';
        $host     = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $script   = $_SERVER['SCRIPT_NAME'] ?? '';
        // استخراج مسار التطبيق (حذف اسم الملف)
        $basePath = rtrim(dirname($script), '/\\');
        return $scheme . '://' . $host . $basePath;
    }
}

// ── [5] إعدادات قاعدة البيانات ──────────────────────────────
define('DB_DRIVER', 'mysql');
define('DB_HOST',   env('DB_HOST',   'localhost'));
define('DB_PORT',   env('DB_PORT',   '3306'));
define('DB_NAME',   env('DB_NAME',   'diparma_gateway'));
define('DB_USER',   env('DB_USER',   'diparma_user'));
define('DB_PASS',   array_key_exists('DB_PASS', $_ENV) ? (string)$_ENV['DB_PASS'] : (getenv('DB_PASS') !== false ? (string)getenv('DB_PASS') : 'diparma_secure_2024'));
define('DB_PREFIX', env('DB_PREFIX', 'dp_'));

// ── [6] إعدادات النظام ───────────────────────────────────────
define('SITE_URL',          _dp_detect_url());
define('SITE_NAME',         env('APP_NAME',         'DI PARMA Gateway'));
define('TIMEZONE',          env('APP_TIMEZONE',      'Asia/Dubai'));
define('ENCRYPTION_KEY',    env('ENCRYPTION_KEY',    'DI_PARMA_SECURE_KEY_2026'));
define('JWT_SECRET',        env('JWT_SECRET',        ''));
define('GATEWAYS_PATH',     env('GATEWAYS_PATH',     '/gateway/'));
define('GATEWAYS_CONFIG',   env('GATEWAYS_CONFIG',   '/config/gateways_all.json'));
define('ALLOWED_GATEWAYS',  env('ALLOWED_GATEWAYS',  '*'));
define('PAYMENT_TIMEOUT',   (int) env('PAYMENT_TIMEOUT', 300));
define('MAX_AMOUNT',        (float) env('MAX_AMOUNT', 1000000));
define('MIN_AMOUNT',        (float) env('MIN_AMOUNT', 1));
define('SESSION_TIMEOUT',   (int) env('SESSION_TIMEOUT',   3600));
define('MAX_LOGIN_ATTEMPTS',(int) env('MAX_LOGIN_ATTEMPTS', 5));
if (!defined('ALLOW_SIMULATION')) {
    define('ALLOW_SIMULATION', false);
}
if (!defined('PAYRAM_API_KEY')) {
    define('PAYRAM_API_KEY', env('PAYRAM_API_KEY', ''));
}
if (!defined('PAYRAM_BASE_URL')) {
    define('PAYRAM_BASE_URL', env('PAYRAM_BASE_URL', 'http://65.2.184.57:8080'));
}
if (!defined('PAYRAM_WEBHOOK_SECRET')) {
    define('PAYRAM_WEBHOOK_SECRET', env('PAYRAM_WEBHOOK_SECRET', env('PAYRAM_API_KEY', '')));
}

// عنوان Ledger للاستلام فقط؛ لا تستخدمه كمحفظة Hot Wallet للإرسال.
if (!defined('LEDGER_TRC20_ADDRESS')) {
    define('LEDGER_TRC20_ADDRESS', env('LEDGER_TRC20_ADDRESS', 'TFyAQPrTRdP7zp46RPmE1iiCac1Lh6Bu58'));
}

// المحفظة الباردة: Ledger فقط، ولا تُستخدم للإرسال الآلي.
if (!defined('COLD_WALLET_TRC20_ADDRESS')) {
    define('COLD_WALLET_TRC20_ADDRESS', env('COLD_WALLET_TRC20_ADDRESS', LEDGER_TRC20_ADDRESS));
}

// عنوان Hot Wallet للإرسال
if (!defined('HOT_WALLET_TRC20_ADDRESS')) {
    define('HOT_WALLET_TRC20_ADDRESS', env('HOT_WALLET_TRC20_ADDRESS', 'TKST5Ug2UtAq6iQ8wVzy7tTah1FRgWaWYn'));
}

// المفتاح الخاص للمحفظة الساخنة
if (!defined('HOT_WALLET_TRC20_KEY')) {
    define('HOT_WALLET_TRC20_KEY', env('HOT_WALLET_TRC20_KEY', ''));
}

// ── [7] إعدادات Webhook ──────────────────────────────────────
if (!defined('WEBHOOK_DEFAULT_RESPONSE_CODE')) {
    define('WEBHOOK_DEFAULT_RESPONSE_CODE', 200);
}
if (!defined('WEBHOOK_HMAC_SECRET')) {
    define('WEBHOOK_HMAC_SECRET', env('WEBHOOK_HMAC_SECRET', 'y8K4r7Qz9vT2pX1sB6nF0mL3aR5cH2yU'));
}
if (!defined('WEBHOOK_VERIFY_SIGNATURE')) {
    define('WEBHOOK_VERIFY_SIGNATURE', (bool) env('WEBHOOK_VERIFY_SIGNATURE', true));
}
if (!defined('WEBHOOK_USE_ASYNC_PROCESSING')) {
    define('WEBHOOK_USE_ASYNC_PROCESSING', (bool) env('WEBHOOK_USE_ASYNC_PROCESSING', true));
}
if (!defined('WEBHOOK_ACCEPT_ONLY_HOSTS')) {
    define('WEBHOOK_ACCEPT_ONLY_HOSTS', []);
}
if (!defined('MOONPAY_WEBHOOK_SIGNING_SECRET')) {
    define('MOONPAY_WEBHOOK_SIGNING_SECRET', env('MOONPAY_WEBHOOK_SIGNING_SECRET', ''));
}
if (!defined('MOONPAY_WEBHOOK_KEY')) {
    define('MOONPAY_WEBHOOK_KEY', env('MOONPAY_WEBHOOK_KEY', ''));
}
if (!defined('MOONPAY_PUBLISHABLE_KEY')) {
    define('MOONPAY_PUBLISHABLE_KEY', env('MOONPAY_PUBLISHABLE_KEY', ''));
}

// ── [8] ضبط PHP بحسب البيئة ─────────────────────────────────
date_default_timezone_set(TIMEZONE);

if (APP_IS_LOCAL) {
    // محلي: إظهار الأخطاء
    ini_set('display_errors',         '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    // إنتاج: إخفاء الأخطاء وتسجيلها فقط
    ini_set('display_errors',         '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors',             '1');
    ini_set('error_log',              LOGS_PATH . '/php_errors.log');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}

// ── [9] بدء الجلسة ──────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        $requestIsHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
            || strtolower($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on'
            || str_contains($_SERVER['HTTP_CF_VISITOR'] ?? '', '"scheme":"https"');
        session_name('DIPARMASESSID');
        session_set_cookie_params([
            'lifetime' => SESSION_TIMEOUT,
            'path'     => '/',
            'secure'   => $requestIsHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    session_start();
}

// ── [10] إنشاء المجلدات الضرورية إن لم تكن موجودة ───────────
foreach ([LOGS_PATH, CACHE_PATH, BACKUP_PATH, TEMP_PATH] as $_dir) {
    if (!is_dir($_dir)) @mkdir($_dir, 0755, true);
}
unset($_dir, $_DP_ENV);
