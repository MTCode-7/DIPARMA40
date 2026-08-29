<?php
/**
 * ============================================================
 * DI PARMA | GatewayLogger
 * تسجيل تدقيقي كامل لجميع عمليات بوابات الدفع
 * ============================================================
 * - يخفي بيانات البطاقة والـ CVV تلقائياً
 * - يسجل Request + Response + Error Code الموحد
 * - يدعم التدوير اليومي للملفات
 * - يحفظ في DB للتدقيق المالي (اختياري)
 * ============================================================
 */
class GatewayLogger
{
    // حقول يجب إخفاء قيمتها كاملاً
    private static array $maskFull = [
        'card_number', 'cc_number', 'number',
        'cvv2', 'card_cvv', 'cc_cvv', 'cvv', 'cvc',
        'secret', 'password', 'token', 'api_key',
        'secret_key', 'access_token', 'authorization',
    ];

    // حقول يُظهر أول 6 + آخر 4 أرقام فقط (BIN masking)
    private static array $maskBin = [
        'card_number', 'cc_number', 'number',
    ];

    // ══════════════════════════════════════════════════════════
    // تسجيل عملية كاملة
    // ══════════════════════════════════════════════════════════

    /**
     * @param string $gateway    اسم البوابة
     * @param string $operation  charge | hold | capture | cancel
     * @param array  $request    الطلب المرسل
     * @param array  $response   الرد المستلم
     * @param string $errorCode  رمز الخطأ الموحد (فارغ إذا نجح)
     * @param float  $duration   مدة الطلب بالثواني
     */
    public static function log(
        string $gateway,
        string $operation,
        array  $request,
        array  $response,
        string $errorCode = '',
        float  $duration  = 0.0
    ): void {
        $logDir = defined('LOGS_PATH') ? LOGS_PATH : __DIR__ . '/../../logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

        $entry = [
            'ts'         => date('Y-m-d H:i:s'),
            'gateway'    => $gateway,
            'operation'  => $operation,
            'ref'        => $request['reference'] ?? $response['reference'] ?? '',
            'amount'     => $request['amount']    ?? $response['amount']    ?? 0,
            'currency'   => strtoupper($request['currency'] ?? $response['currency'] ?? ''),
            'mode'       => strtoupper($request['processing_mode'] ?? ''),
            'status'     => $response['status']   ?? ($response['success'] ?? false ? 'success' : 'failed'),
            'error_code' => $errorCode,
            'duration_s' => round($duration, 3),
            'request'    => self::maskSensitive($request),
            'response'   => self::maskSensitive($response),
        ];

        // ── ملف يومي ─────────────────────────────────────────
        $logFile = $logDir . '/gateway_audit_' . date('Y-m-d') . '.log';
        @file_put_contents($logFile, json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);

        // ── ملف عمليات الفشل فقط ─────────────────────────────
        if (!($response['success'] ?? false)) {
            $errFile = $logDir . '/gateway_errors_' . date('Y-m') . '.log';
            @file_put_contents($errFile, json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);
        }
    }

    /**
     * تسجيل مختصر (للـ debug السريع)
     */
    public static function quick(string $gateway, string $operation, string $ref, bool $success, string $msg = ''): void
    {
        $logDir  = defined('LOGS_PATH') ? LOGS_PATH : __DIR__ . '/../../logs';
        $logFile = $logDir . '/gateway_quick.log';
        $status  = $success ? '✓' : '✗';
        @file_put_contents(
            $logFile,
            '[' . date('Y-m-d H:i:s') . "] $status [$gateway][$operation] ref=$ref" . ($msg ? " | $msg" : '') . PHP_EOL,
            FILE_APPEND
        );
    }

    // ══════════════════════════════════════════════════════════
    // إخفاء البيانات الحساسة
    // ══════════════════════════════════════════════════════════

    /**
     * يمشي على مصفوفة بشكل متكرر ويخفي الحقول الحساسة
     */
    public static function maskSensitive(array $data, int $depth = 0): array
    {
        if ($depth > 5) return $data; // منع التكرار اللانهائي

        $masked = [];
        foreach ($data as $key => $value) {
            $keyLower = strtolower((string)$key);

            if (is_array($value)) {
                $masked[$key] = self::maskSensitive($value, $depth + 1);
                continue;
            }

            // BIN masking لرقم البطاقة — يُظهر أول 6 + آخر 4
            if (in_array($keyLower, self::$maskBin) && is_string($value)) {
                $clean = preg_replace('/\D/', '', $value);
                if (strlen($clean) >= 12) {
                    $masked[$key] = substr($clean, 0, 6) . str_repeat('*', strlen($clean) - 10) . substr($clean, -4);
                    continue;
                }
            }

            // إخفاء كامل للحقول الحساسة
            if (in_array($keyLower, self::$maskFull) && !empty($value)) {
                $masked[$key] = '***MASKED***';
                continue;
            }

            // إخفاء أي قيمة تبدو كـ PAN (13-19 رقم متتالي)
            if (is_string($value) && preg_match('/^\d{13,19}$/', preg_replace('/[\s-]/', '', $value))) {
                $clean = preg_replace('/[\s-]/', '', $value);
                $masked[$key] = substr($clean, 0, 6) . str_repeat('*', strlen($clean) - 10) . substr($clean, -4);
                continue;
            }

            $masked[$key] = $value;
        }

        return $masked;
    }

    /**
     * تنظيف ملفات السجلات القديمة (أكثر من X يوم)
     */
    public static function cleanup(int $keepDays = 90): int
    {
        $logDir = defined('LOGS_PATH') ? LOGS_PATH : __DIR__ . '/../../logs';
        $count  = 0;
        $cutoff = strtotime("-$keepDays days");

        foreach (glob($logDir . '/gateway_audit_*.log') as $file) {
            if (filemtime($file) < $cutoff) {
                @unlink($file);
                $count++;
            }
        }

        return $count;
    }

    /**
     * إحصاءات سريعة من سجلات اليوم
     */
    public static function todayStats(): array
    {
        $logDir  = defined('LOGS_PATH') ? LOGS_PATH : __DIR__ . '/../../logs';
        $logFile = $logDir . '/gateway_audit_' . date('Y-m-d') . '.log';

        if (!file_exists($logFile)) {
            return ['total' => 0, 'success' => 0, 'failed' => 0, 'by_gateway' => []];
        }

        $lines      = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $total      = count($lines);
        $success    = 0;
        $byGateway  = [];

        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (!$entry) continue;

            $gw = $entry['gateway'] ?? 'unknown';
            $byGateway[$gw] = ($byGateway[$gw] ?? 0) + 1;

            if (($entry['status'] ?? '') === 'completed' || ($entry['response']['success'] ?? false)) {
                $success++;
            }
        }

        return [
            'total'      => $total,
            'success'    => $success,
            'failed'     => $total - $success,
            'by_gateway' => $byGateway,
        ];
    }
}
