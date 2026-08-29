<?php
/**
 * ============================================================
 * DI PARMA | API Auth Middleware (كامل ومُحسّن)
 * ============================================================
 * 
 * الميزات:
 * ✅ API Key + HMAC Signature
 * ✅ Timestamp (منع Replay Attacks)
 * ✅ Rate Limiting (منع الهجمات العنيفة)
 * ✅ IP Whitelist
 * ✅ CORS Support
 * ✅ AES-256-GCM Encryption
 * ✅ Webhook Signature Verification
 * ✅ API Logging مع تنظيف تلقائي
 * ✅ توليد Credentials آمن
 * ============================================================
 */

class ApiAuth
{
    private static ?array $client = null;
    private static ?string $rawBody = null;
    
    // إعدادات الأمان
    private const MAX_TIMESTAMP_AGE = 300;        // 5 دقائق
    private const RATE_LIMIT_WINDOW = 60;         // 60 ثانية
    private const RATE_LIMIT_MAX = 100;           // 100 طلب في الدقيقة
    private const MAX_LOG_SIZE = 10000;           // عدد السجلات قبل التنظيف

    /**
     * ============================================================
     * التحقق الرئيسي من الطلب
     * ============================================================
     */
    public static function verify(): array
    {
        // 1. بدء CORS (إذا كنت تستخدم API من متصفح)
        self::handleCORS();
        
        // 2. استخراج بيانات المصادقة
        $key       = self::extractKey();
        $timestamp = (int)($_SERVER['HTTP_X_TIMESTAMP'] ?? 0);
        $signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
        $rawBody   = self::getRawBody();
        $ip        = self::getClientIP();
        $endpoint  = $_SERVER['REQUEST_URI'] ?? '';
        $method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // 3. التحقق من API Key
        if (empty($key)) {
            self::abort(401, 'missing_api_key', 'API Key required in X-Api-Key or Authorization: Bearer header');
        }

        // 4. جلب العميل من قاعدة البيانات
        $client = self::loadClient($key);
        if (!$client) {
            self::abort(401, 'invalid_api_key', 'Invalid or inactive API Key');
        }

        // 5. التحقق من حالة الحساب
        if ($client['status'] !== 'active') {
            self::abort(403, 'account_suspended', 'Account status: ' . $client['status']);
        }

        // 6. التحقق من IP Whitelist (إذا تم تفعيله)
        if (!empty($client['ip_whitelist'])) {
            if (!self::checkIPWhitelist($ip, $client['ip_whitelist'])) {
                self::abort(403, 'ip_blocked', 'IP address not whitelisted for this account');
            }
        }

        // 7. التحقق من Rate Limiting
        if (!self::checkRateLimit($client['id'], $ip, $endpoint)) {
            self::abort(429, 'rate_limit_exceeded', 'Too many requests. Please wait ' . self::RATE_LIMIT_WINDOW . ' seconds.');
        }

        // 8. التحقق من Timestamp (منع Replay Attacks)
        if (empty($timestamp) || abs(time() - $timestamp) > self::MAX_TIMESTAMP_AGE) {
            self::abort(401, 'timestamp_expired', 'Request timestamp too old or missing (max ' . self::MAX_TIMESTAMP_AGE . 's)');
        }

        // 9. التحقق من HMAC Signature
        if (empty($signature)) {
            self::abort(401, 'missing_signature', 'X-Signature header required');
        }

        // 10. حساب التوقيع المتوقع
        $expectedSig = self::computeSignature(
            $client['api_secret_raw'],
            $key,
            $timestamp,
            $rawBody
        );

        // 11. مقارنة التوقيعات (مقاومة لهجمات التوقيت)
        if (!hash_equals($expectedSig, $signature)) {
            self::abort(401, 'invalid_signature', 'Signature verification failed');
        }

        // 12. تخزين بيانات العميل
        self::$client = $client;

        // 13. تحديث آخر استخدام
        self::updateLastUsed($client['id'], $ip);

        // 14. تسجيل الطلب (اختياري - يمكن تعطيله في الإنتاج)
        if (getenv('API_LOG_ENABLED') !== 'false') {
            self::logRequest($client['id'], $key, $endpoint, $method, $rawBody);
        }

        return $client;
    }

    /**
     * ============================================================
     * حساب توقيع HMAC-SHA256
     * الصيغة: HMAC-SHA256(api_secret, "api_key:timestamp:sha256(body)")
     * ============================================================
     */
    public static function computeSignature(string $secret, string $apiKey, int $timestamp, string $body): string
    {
        $bodyHash = hash('sha256', $body);
        $message  = implode(':', [$apiKey, $timestamp, $bodyHash]);
        return hash_hmac('sha256', $message, $secret);
    }

    /**
     * ============================================================
     * التحقق من توقيع Webhook
     * ============================================================
     */
    public static function verifyWebhook(string $secret, string $payload, string $signature): bool
    {
        if (empty($signature)) return false;
        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signature);
    }

    /**
     * ============================================================
     * توليد Credentials جديدة (API Key + Secret + Webhook Secret)
     * ============================================================
     */
    public static function generateCredentials(string $name, array $options = []): array
    {
        $apiKey         = 'dpk_' . bin2hex(random_bytes(16));          // 36 حرف
        $apiSecretPlain = 'dps_' . bin2hex(random_bytes(32));          // 68 حرف
        $webhookSecret  = 'whs_' . bin2hex(random_bytes(20));          // 44 حرف
        $mid            = 'MID' . strtoupper(substr(md5($name . time()), 0, 9));
        $tid            = 'TID' . strtoupper(substr(md5(uniqid()), 0, 7));

        return [
            'api_key'         => $apiKey,
            'api_secret'      => $apiSecretPlain,          // يُعرض مرة واحدة فقط
            'api_secret_enc'  => self::encryptSecret($apiSecretPlain),
            'webhook_secret'  => $webhookSecret,
            'whs_enc'         => self::encryptSecret($webhookSecret),
            'mid'             => $mid,
            'tid'             => $tid,
            'ip_whitelist'    => $options['ip_whitelist'] ?? null,
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // Private Helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * الحصول على نص الطلب الخام (مع التخزين المؤقت)
     */
    private static function getRawBody(): string
    {
        if (self::$rawBody === null) {
            self::$rawBody = file_get_contents('php://input') ?: '';
        }
        return self::$rawBody;
    }

    /**
     * استخراج API Key من الرؤوس
     */
    private static function extractKey(): string
    {
        // 1. من X-Api-Key
        $key = $_SERVER['HTTP_X_API_KEY'] ?? '';
        if (!empty($key)) {
            return trim($key);
        }

        // 2. من Authorization: Bearer
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    /**
     * جلب العميل من قاعدة البيانات
     */
    private static function loadClient(string $key): ?array
    {
        try {
            require_once __DIR__ . '/../../includes/database.php';
            $db = db();
            
            $rows = $db->query(
                "SELECT * FROM dp_api_clients WHERE api_key = ? LIMIT 1",
                [$key]
            );
            
            if (empty($rows[0])) return null;

            $client = $rows[0];
            
            // فك تشفير المفتاح السري
            $client['api_secret_raw'] = self::decryptSecret($client['api_secret']);
            
            return $client;
            
        } catch (Exception $e) {
            error_log('[ApiAuth] DB error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * تحديث آخر استخدام
     */
    private static function updateLastUsed(int $clientId, string $ip): void
    {
        try {
            $db = db();
            $db->execute(
                "UPDATE dp_api_clients 
                 SET last_used_at = NOW(), 
                     last_ip = ?,
                     total_txns = total_txns + 1
                 WHERE id = ?",
                [$ip, $clientId]
            );
        } catch (Exception $e) {
            error_log('[ApiAuth] Update error: ' . $e->getMessage());
        }
    }

    /**
     * التحقق من Rate Limiting
     */
    private static function checkRateLimit(int $clientId, string $ip, string $endpoint): bool
    {
        try {
            $db = db();
            $window = time() - self::RATE_LIMIT_WINDOW;
            
            $count = $db->query(
                "SELECT COUNT(*) as cnt FROM dp_api_logs 
                 WHERE client_id = ? 
                 AND ip = ? 
                 AND created_at > FROM_UNIXTIME(?)",
                [$clientId, $ip, $window]
            );
            
            $requests = (int)($count[0]['cnt'] ?? 0);
            return $requests < self::RATE_LIMIT_MAX;
            
        } catch (Exception $e) {
            error_log('[ApiAuth] Rate limit error: ' . $e->getMessage());
            return true; // في حالة خطأ قاعدة البيانات، نسمح بالطلب
        }
    }

    /**
     * التحقق من IP Whitelist
     */
    private static function checkIPWhitelist(string $ip, string $whitelist): bool
    {
        $allowed = array_map('trim', explode(',', $whitelist));
        return in_array($ip, $allowed) || in_array('*', $allowed);
    }

    /**
     * الحصول على IP العميل (مع دعم الـ Proxies)
     */
    private static function getClientIP(): string
    {
        $headers = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'REMOTE_ADDR'
        ];
        
        foreach ($headers as $header) {
            if (isset($_SERVER[$header]) && !empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return '0.0.0.0';
    }

    /**
     * معالجة CORS
     */
    private static function handleCORS(): void
    {
        $allowedOrigins = [
            'https://diparmas.com',
            'https://www.diparmas.com',
            'http://localhost:3000',
            'http://localhost:5173',
        ];
        
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        
        if (in_array($origin, $allowedOrigins) || getenv('APP_ENV') === 'development') {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, X-Api-Key, X-Timestamp, X-Signature, X-Gateway');
            header('Access-Control-Max-Age: 86400');
        }
        
        // معالجة طلبات OPTIONS (Preflight)
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
    }

    /**
     * تشفير المفتاح السري (AES-256-GCM)
     */
    private static function encryptSecret(string $plain): string
    {
        $key = self::getEncryptionKey();
        $aesKey = hash('sha256', $key, true);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $aesKey, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        return base64_encode($iv . $tag . $cipher);
    }

    /**
     * فك تشفير المفتاح السري (AES-256-GCM)
     */
    private static function decryptSecret(string $stored): string
    {
        $key = self::getEncryptionKey();
        $decoded = base64_decode($stored);
        
        // إذا كان النص غير مشفر (للتوافق مع الإصدارات القديمة)
        if (strlen($decoded) < 28) {
            return $stored;
        }

        $iv = substr($decoded, 0, 12);
        $tag = substr($decoded, 12, 16);
        $ciphertext = substr($decoded, 28);
        $aesKey = hash('sha256', $key, true);

        $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $aesKey, OPENSSL_RAW_DATA, $iv, $tag);
        return $plain !== false ? $plain : $stored;
    }

    /**
     * الحصول على مفتاح التشفير
     */
    private static function getEncryptionKey(): string
    {
        $key = getenv('ENCRYPTION_KEY');
        if (empty($key)) {
            $key = defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : 'DI_PARMA_SECURE_KEY_2026';
        }
        return $key;
    }

    /**
     * تسجيل طلب API
     */
    private static function logRequest(int $clientId, string $apiKey, string $endpoint, string $method, string $body): void
    {
        try {
            $db = db();
            
            // تنظيف السجلات القديمة (إذا تجاوزت الحد)
            self::cleanOldLogs();
            
            $db->insert('api_logs', [
                'client_id' => $clientId,
                'api_key' => $apiKey,
                'endpoint' => $endpoint,
                'method' => $method,
                'request_body' => mb_substr($body, 0, 4000),
                'ip' => self::getClientIP(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            
        } catch (Exception $e) {
            error_log('[ApiAuth] Log error: ' . $e->getMessage());
        }
    }

    /**
     * تنظيف السجلات القديمة
     */
    private static function cleanOldLogs(): void
    {
        try {
            $db = db();
            $count = $db->query("SELECT COUNT(*) as cnt FROM dp_api_logs");
            if (($count[0]['cnt'] ?? 0) > self::MAX_LOG_SIZE) {
                $db->execute(
                    "DELETE FROM dp_api_logs 
                     WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
                     ORDER BY id ASC LIMIT 1000"
                );
            }
        } catch (Exception $e) {
            // تجاهل أخطاء التنظيف
        }
    }

    /**
     * إيقاف التنفيذ وعرض خطأ JSON
     */
    private static function abort(int $code, string $error, string $message): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        
        echo json_encode([
            'success' => false,
            'error' => $error,
            'message' => $message,
            'timestamp' => date('c'),
        ], JSON_UNESCAPED_UNICODE);
        
        exit;
    }

    // ──────────────────────────────────────────────────────────────
    // Getters (للاستخدام في ملفات API)
    // ──────────────────────────────────────────────────────────────

    public static function getClient(): ?array
    {
        return self::$client;
    }

    public static function getClientId(): ?int
    {
        return self::$client['id'] ?? null;
    }

    public static function getApiKey(): ?string
    {
        return self::$client['api_key'] ?? null;
    }

    /**
     * التحقق من وجود عميل مصادق
     */
    public static function isAuthenticated(): bool
    {
        return self::$client !== null;
    }
}

// ──────────────────────────────────────────────────────────────
// دالة مساعدة للاستخدام السريع في ملفات API
// ──────────────────────────────────────────────────────────────

if (!function_exists('api_auth')) {
    /**
     * دالة مساعدة للتحقق من المصادقة في ملفات API
     */
    function api_auth(): array
    {
        return ApiAuth::verify();
    }
}

/**
 * ============================================================
 * نهاية الملف
 * ============================================================
 * 
 * طريقة الاستخدام في ملفات API:
 * 
 * <?php
 * require_once __DIR__ . '/../lib/ApiAuth.php';
 * 
 * // التحقق من المصادقة
 * $client = ApiAuth::verify();
 * 
 * // أو باستخدام الدالة المساعدة
 * // $client = api_auth();
 * 
 * // الآن يمكنك استخدام بيانات العميل
 * $clientId = ApiAuth::getClientId();
 * $apiKey = ApiAuth::getApiKey();
 * 
 * // ... معالجة الطلب
 * ?>
 * ============================================================
 */
?>