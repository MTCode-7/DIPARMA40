<?php
/**
 * ============================================================
 * DI PARMA | EventBus
 * نظام أحداث Async داخلي يربط جميع الخدمات
 * ============================================================
 * يعمل عبر جدول dp_event_log + Worker يعالج الأحداث
 * ============================================================
 * الأحداث المدعومة:
 *   payment.approved       → يطلق: Crypto fulfillment
 *   payment.failed         → يطلق: Notification
 *   kyc.approved           → يطلق: Notification + limit update
 *   crypto.deposit.confirmed → يطلق: Notification
 *   crypto.send.confirmed  → يطلق: Notification
 *   treasury.low_balance   → يطلق: Admin alert
 * ============================================================
 */

class EventBus
{
    private static ?self $instance = null;
    private Database $db;
    private array $handlers = [];
    private string $logFile;

    private function __construct()
    {
        $this->db      = db();
        $this->logFile = defined('LOGS_PATH') ? LOGS_PATH . '/eventbus.log' : __DIR__ . '/../logs/eventbus.log';
        if (!is_dir(dirname($this->logFile))) @mkdir(dirname($this->logFile), 0755, true);
        $this->registerDefaultHandlers();
    }

    public static function getInstance(): self
    {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    // ── نشر حدث ─────────────────────────────────────────────

    /**
     * نشر حدث — يُخزَّن في DB للمعالجة الفورية أو المؤجّلة
     */
    public function publish(string $event, array $payload, ?string $reference = null, ?int $userId = null): int
    {
        $id = (int)$this->db->insert('event_log', [
            'event_type' => $event,
            'reference'  => $reference,
            'user_id'    => $userId,
            'payload'    => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'processed'  => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->log("→ publish: $event | ref=$reference | id=$id");

        // تنفيذ فوري إذا كان Handler مسجّلاً
        $this->dispatch($event, $payload, $id);

        return $id;
    }

    // ── تسجيل Handler ────────────────────────────────────────

    /**
     * تسجيل Handler لحدث معين
     */
    public function subscribe(string $event, callable $handler): void
    {
        $this->handlers[$event][] = $handler;
    }

    // ── معالجة الأحداث ───────────────────────────────────────

    /**
     * معالجة الأحداث غير المعالجة (يُشغَّل من Cron)
     */
    public function processQueue(int $limit = 50): array
    {
        $results = ['processed' => 0, 'failed' => 0];

        $events = $this->db->query(
            "SELECT * FROM dp_event_log
             WHERE processed = 0
               AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             ORDER BY id ASC
             LIMIT ?",
            [$limit]
        );

        foreach ($events as $event) {
            try {
                $payload = json_decode($event['payload'] ?? '{}', true) ?: [];
                $handled = $this->dispatch($event['event_type'], $payload, (int)$event['id']);

                $this->db->update('event_log', [
                    'processed' => 1,
                ], ['id' => $event['id']]);

                $results['processed']++;
                $this->log("✓ processed: {$event['event_type']} #{$event['id']}");
            } catch (Exception $e) {
                $results['failed']++;
                $this->log("✗ failed: {$event['event_type']} #{$event['id']}: " . $e->getMessage());
            }
        }

        return $results;
    }

    private function dispatch(string $event, array $payload, int $eventId): bool
    {
        $handlers = $this->handlers[$event] ?? [];
        if (empty($handlers)) return false;

        foreach ($handlers as $handler) {
            try {
                $handler($payload, $event, $eventId);
            } catch (Exception $e) {
                $this->log("✗ handler error [$event]: " . $e->getMessage());
            }
        }
        return true;
    }

    // ── Handlers الافتراضية ──────────────────────────────────

    private function registerDefaultHandlers(): void
    {
        // [1] عند نجاح الدفع → ابدأ إرسال USDT
        $this->subscribe('payment.approved', function(array $payload) {
            $reference = $payload['reference'] ?? '';
            if (empty($reference)) return;

            $this->log("→ payment.approved → initiating crypto fulfillment: $reference");

            require_once __DIR__ . '/ExchangeAPIService.php';
            require_once __DIR__ . '/CryptoGateway.php';

            $gateway = CryptoGateway::getInstance();
            $result  = $gateway->onFiatPaymentConfirmed($reference);
            $this->log($result['success'] ? "✓ crypto initiated: $reference" : "✗ crypto failed: " . ($result['message'] ?? ''));
        });

        // [2] عند تأكيد إرسال Crypto → أرسل إشعار
        $this->subscribe('crypto.send.confirmed', function(array $payload) {
            $userId = $payload['user_id'] ?? null;
            if (!$userId) return;
            $this->log("→ crypto.send.confirmed → notify user $userId");
            // TODO: إرسال إشعار SMS/Email
        });

        // [3] انخفاض رصيد Treasury → تنبيه الأدمن
        $this->subscribe('treasury.low_balance', function(array $payload) {
            $balance = $payload['balance'] ?? 0;
            $this->log("⚠ treasury.low_balance: $balance USDT — يجب تعبئة Hot Wallet");
            // TODO: إرسال إيميل تحذير للأدمن
        });

        // [4] KYC مقبول → تحديث حدود التداول
        $this->subscribe('kyc.approved', function(array $payload) {
            $userId = $payload['user_id'] ?? null;
            if (!$userId) return;
            $this->log("→ kyc.approved → update limits for user $userId");
        });

        // [5] إيداع Crypto مؤكد → معالجة بيع USDT
        $this->subscribe('crypto.deposit.confirmed', function(array $payload) {
            $reference = $payload['reference'] ?? '';
            $this->log("→ crypto.deposit.confirmed: $reference");
            // يمكن إضافة منطق صرف الفيات هنا
        });
    }

    // ── مساعدات ─────────────────────────────────────────────

    private function log(string $msg): void
    {
        @file_put_contents($this->logFile, '[' . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
    }
}
