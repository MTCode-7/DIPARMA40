<?php
/**
 * ============================================================
 * DI PARMA | GatewayTokenCache
 * تخزين مؤقت آمن لـ Bearer Tokens / Access Tokens
 * ============================================================
 * - يخزّن Token + وقت انتهاء الصلاحية في ملف JSON مشفّر
 * - يمنع استنزاف طلبات المصادقة المتكررة
 * - يدعم قراءة من ملف / ذاكرة / APCu إن توفر
 * - مفتاح التشفير من ENCRYPTION_KEY في .env
 * ============================================================
 */

final class GatewayTokenCache
{
    private static string $cacheDir  = '';
    private static int    $bufferSec = 60; // خصم 60 ثانية للأمان قبل انتهاء الصلاحية

    // ذاكرة داخلية للطلب الحالي (in-request cache)
    private static array $memCache = [];

    // ══════════════════════════════════════════════════════════
    // القراءة
    // ══════════════════════════════════════════════════════════

    /**
     * @param string $gatewayKey  مفتاح فريد للبوابة + البيئة (مثل: stripe_live)
     * @return string|null  الـ token أو null إذا انتهت صلاحيته / غير موجود
     */
    public static function get(string $gatewayKey): ?string
    {
        // [1] تحقق من الذاكرة أولاً
        if (isset(self::$memCache[$gatewayKey])) {
            $cached = self::$memCache[$gatewayKey];
            if ($cached['expires_at'] > time()) {
                return $cached['token'];
            }
            unset(self::$memCache[$gatewayKey]);
        }

        // [2] APCu إن كان متاحاً (أسرع)
        if (function_exists('apcu_fetch')) {
            $token = apcu_fetch('gw_token_' . $gatewayKey, $success);
            if ($success && $token) {
                self::$memCache[$gatewayKey] = ['token' => $token, 'expires_at' => PHP_INT_MAX];
                return $token;
            }
        }

        // [3] قراءة من ملف
        $file = self::cacheFile($gatewayKey);
        if (!file_exists($file)) return null;

        $raw  = @file_get_contents($file);
        if (!$raw) return null;

        $data = self::decrypt($raw);
        if (!$data) {
            @unlink($file);
            return null;
        }

        // انتهت الصلاحية
        if (($data['expires_at'] ?? 0) <= time()) {
            @unlink($file);
            return null;
        }

        // خزّن في الذاكرة
        self::$memCache[$gatewayKey] = $data;
        return $data['token'];
    }

    // ══════════════════════════════════════════════════════════
    // الكتابة
    // ══════════════════════════════════════════════════════════

    /**
     * @param string $gatewayKey  مفتاح البوابة
     * @param string $token       الـ token
     * @param int    $expiresIn   مدة الصلاحية بالثواني
     */
    public static function set(string $gatewayKey, string $token, int $expiresIn): void
    {
        $expiresAt = time() + max(0, $expiresIn - self::$bufferSec);

        $data = [
            'token'      => $token,
            'expires_at' => $expiresAt,
            'gateway'    => $gatewayKey,
            'created_at' => time(),
        ];

        // [1] خزّن في الذاكرة
        self::$memCache[$gatewayKey] = $data;

        // [2] APCu
        if (function_exists('apcu_store')) {
            $ttl = max(1, $expiresAt - time());
            apcu_store('gw_token_' . $gatewayKey, $token, $ttl);
        }

        // [3] كتابة ملف مشفّر
        $dir = self::ensureCacheDir();
        if ($dir) {
            $encrypted = self::encrypt($data);
            if ($encrypted) {
                @file_put_contents(self::cacheFile($gatewayKey), $encrypted, LOCK_EX);
                @chmod(self::cacheFile($gatewayKey), 0600); // قراءة للـ owner فقط
            }
        }
    }

    // ══════════════════════════════════════════════════════════
    // الحذف
    // ══════════════════════════════════════════════════════════

    public static function delete(string $gatewayKey): void
    {
        unset(self::$memCache[$gatewayKey]);

        if (function_exists('apcu_delete')) {
            apcu_delete('gw_token_' . $gatewayKey);
        }

        $file = self::cacheFile($gatewayKey);
        if (file_exists($file)) @unlink($file);
    }

    // ══════════════════════════════════════════════════════════
    // getOrFetch — استرجاع أو جلب جديد
    // ══════════════════════════════════════════════════════════

    /**
     * يسترجع الـ token من الكاش، وإذا لم يجده يستدعي callback للجلب
     *
     * @param string   $gatewayKey
     * @param callable $fetchCallback  دالة ترجع ['token' => '...', 'expires_in' => 3600]
     */
    public static function getOrFetch(string $gatewayKey, callable $fetchCallback): ?string
    {
        $cached = self::get($gatewayKey);
        if ($cached !== null) return $cached;

        try {
            $result = $fetchCallback();
            if (!empty($result['token']) && !empty($result['expires_in'])) {
                self::set($gatewayKey, $result['token'], intval($result['expires_in']));
                return $result['token'];
            }
        } catch (\Throwable $e) {
            // تسجيل الخطأ دون كسر التدفق
            $logDir  = defined('LOGS_PATH') ? LOGS_PATH : __DIR__ . '/../../logs';
            $logFile = $logDir . '/token_cache.log';
            if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
            @file_put_contents($logFile,
                '[' . date('Y-m-d H:i:s') . "] [$gatewayKey] fetchCallback failed: " . $e->getMessage() . PHP_EOL,
                FILE_APPEND
            );
        }

        return null;
    }

    // ══════════════════════════════════════════════════════════
    // تنظيف الملفات المنتهية
    // ══════════════════════════════════════════════════════════

    public static function cleanup(): int
    {
        $dir   = self::ensureCacheDir();
        $count = 0;
        if (!$dir) return 0;

        foreach (glob($dir . 'gw_token_*.json') as $file) {
            $raw  = @file_get_contents($file);
            $data = $raw ? self::decrypt($raw) : null;
            if (!$data || ($data['expires_at'] ?? 0) <= time()) {
                @unlink($file);
                $count++;
            }
        }
        return $count;
    }

    // ── مساعدات خاصة ─────────────────────────────────────────

    private static function cacheFile(string $gatewayKey): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', $gatewayKey);
        return self::ensureCacheDir() . 'gw_token_' . $safe . '.json';
    }

    private static function ensureCacheDir(): string
    {
        if (!empty(self::$cacheDir)) return self::$cacheDir;

        $base = defined('CACHE_PATH')
            ? CACHE_PATH
            : (defined('BASE_PATH') ? BASE_PATH . '/cache' : __DIR__ . '/../../cache');

        $dir = rtrim($base, '/\\') . '/tokens/';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        self::$cacheDir = $dir;
        return $dir;
    }

    /**
     * تشفير البيانات بـ AES-256-CBC
     */
    private static function encrypt(array $data): ?string
    {
        $key = self::getEncKey();
        if (!$key) return json_encode($data); // fallback بدون تشفير

        $iv       = random_bytes(16);
        $plaintext = json_encode($data);
        $cipher   = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        if ($cipher === false) return null;
        return base64_encode($iv . $cipher);
    }

    /**
     * فك تشفير البيانات
     */
    private static function decrypt(string $raw): ?array
    {
        $key = self::getEncKey();

        // محاولة قراءة كـ JSON خام أولاً (fallback بدون تشفير)
        $jsonTry = json_decode($raw, true);
        if (is_array($jsonTry) && isset($jsonTry['token'])) return $jsonTry;

        if (!$key) return null;

        $decoded = base64_decode($raw, true);
        if ($decoded === false || strlen($decoded) < 17) return null;

        $iv         = substr($decoded, 0, 16);
        $ciphertext = substr($decoded, 16);
        $plaintext  = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        if ($plaintext === false) return null;
        return json_decode($plaintext, true);
    }

    private static function getEncKey(): ?string
    {
        $raw = getenv('ENCRYPTION_KEY') ?: '';
        if (empty($raw)) return null;
        // توحيد الطول لـ 32 byte (AES-256)
        return hash('sha256', $raw, true);
    }
}
