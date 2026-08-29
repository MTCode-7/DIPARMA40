<?php
/**
 * ============================================================
 * DI PARMA | Performance Engine — محرك الأداء الفائق
 * ============================================================
 * يُضمَّن في أعلى كل صفحة لتحقيق أعلى سرعة ممكنة
 * ============================================================
 */

// ── [1] Output Buffering مع gzip ────────────────────────────
if (!ob_get_level()) {
    $acceptsGzip = str_contains($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip');
    if ($acceptsGzip && extension_loaded('zlib') && !ini_get('zlib.output_compression')) {
        ob_start('ob_gzhandler');
    } else {
        ob_start();
    }
}

// ── [2] HTTP Cache Headers للملفات الثابتة ──────────────────
if (!function_exists('dp_set_cache_headers')) {
    function dp_set_cache_headers(int $ttl = 0): void {
        if ($ttl > 0) {
            header("Cache-Control: public, max-age={$ttl}, stale-while-revalidate=60");
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $ttl) . ' GMT');
        } else {
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
        }
    }
}

// ── [3] كاش الذاكرة (APCu أو fallback Array) ────────────────
if (!class_exists('DPCache')) {
    class DPCache {
        private static array $store = [];
        private static bool  $apcuAvailable;

        private static function hasApcu(): bool {
            if (!isset(self::$apcuAvailable)) {
                self::$apcuAvailable = function_exists('apcu_fetch') && ini_get('apc.enabled');
            }
            return self::$apcuAvailable;
        }

        public static function get(string $key, mixed $default = null): mixed {
            if (self::hasApcu()) {
                $val = apcu_fetch($key, $success);
                return $success ? $val : $default;
            }
            if (isset(self::$store[$key]) && self::$store[$key][1] > time()) {
                return self::$store[$key][0];
            }
            return $default;
        }

        public static function set(string $key, mixed $value, int $ttl = 300): void {
            if (self::hasApcu()) {
                apcu_store($key, $value, $ttl);
                return;
            }
            self::$store[$key] = [$value, time() + $ttl];
        }

        public static function delete(string $key): void {
            if (self::hasApcu()) { apcu_delete($key); return; }
            unset(self::$store[$key]);
        }

        public static function remember(string $key, int $ttl, callable $fn): mixed {
            $cached = self::get($key);
            if ($cached !== null) return $cached;
            $value = $fn();
            self::set($key, $value, $ttl);
            return $value;
        }
    }
}

// ── [4] قاعدة بيانات مُحسَّنة مع Query Cache ────────────────
if (!function_exists('dp_query_cached')) {
    function dp_query_cached(string $sql, array $params = [], int $ttl = 60): array {
        $key = 'qc_' . md5($sql . serialize($params));
        return DPCache::remember($key, $ttl, function () use ($sql, $params) {
            return db()->query($sql, $params);
        });
    }
}

// ── [5] Lazy Loading للإضافات الثقيلة ──────────────────────
if (!function_exists('dp_lazy_require')) {
    function dp_lazy_require(string $file): void {
        static $loaded = [];
        if (!isset($loaded[$file])) {
            $loaded[$file] = true;
            require_once $file;
        }
    }
}

// ── [6] Minify HTML عند الإرسال ────────────────────────────
if (!function_exists('dp_minify_html')) {
    function dp_minify_html(string $html): string {
        // حذف تعليقات HTML (ما عدا IE conditionals)
        $html = preg_replace('/<!--(?!\[if).*?-->/s', '', $html);
        // ضغط المسافات المتعددة
        $html = preg_replace('/\s{2,}/', ' ', $html);
        // حذف المسافات حول وسوم HTML
        $html = preg_replace('/>\s+</', '><', $html);
        return trim($html);
    }
}

// ── [7] Critical CSS Inlining Helper ────────────────────────
if (!function_exists('dp_inline_css')) {
    function dp_inline_css(string $file): string {
        $path = defined('ASSETS_PATH') ? ASSETS_PATH . '/css/' . $file : '';
        if (!$path || !file_exists($path)) return '';
        return '<style>' . file_get_contents($path) . '</style>';
    }
}

// ── [8] Preload Hints (HTTP/2 Link headers) ─────────────────
if (!function_exists('dp_preload')) {
    function dp_preload(array $resources): void {
        foreach ($resources as $res) {
            $as   = $res['as']   ?? 'fetch';
            $type = isset($res['type']) ? "; type=\"{$res['type']}\"" : '';
            header("Link: <{$res['href']}>; rel=preload; as={$as}{$type}", false);
        }
    }
}

// ── [9] تسجيل وقت البداية للقياس ────────────────────────────
if (!defined('DP_START_TIME')) {
    define('DP_START_TIME', microtime(true));
}

// ── [10] Security + Performance Headers ─────────────────────
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // تفعيل Keep-Alive للاتصالات
    header('Connection: keep-alive');
}
