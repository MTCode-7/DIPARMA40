<?php
/**
 * ============================================================
 * DI PARMA | BulkPaymentService
 * نظام الدفعات الكبيرة — معالجة آلاف العمليات دفعةً واحدة
 * ============================================================
 * يدعم:
 *   - رفع CSV/Excel بقائمة مدفوعات
 *   - معالجة دفعية Async
 *   - إرسال USDT لآلاف المحافظ
 *   - تقارير تفصيلية لكل دفعة
 * ============================================================
 */

require_once __DIR__ . '/../includes/limits.php';

class BulkPaymentService
{
    private static ?self $instance = null;
    private Database $db;
    private string $logFile;

    private function __construct()
    {
        $this->db      = db();
        $this->logFile = defined('LOGS_PATH') ? LOGS_PATH . '/bulk.log' : __DIR__ . '/../logs/bulk.log';
        if (!is_dir(dirname($this->logFile))) @mkdir(dirname($this->logFile), 0755, true);
        $this->ensureTables();
    }

    public static function getInstance(): self
    {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    // ── إنشاء دفعة جديدة ────────────────────────────────

    /**
     * إنشاء دفعة دفعات كبيرة
     * @param array $payments [ ['address'=>'T...','amount'=>1000,'coin'=>'USDT','network'=>'TRC20'], ... ]
     * @param array $meta     [ 'name'=>'دفعة يناير', 'currency'=>'USD' ]
     */
    public function createBatch(array $payments, array $meta = []): array
    {
        $userId    = intval($_SESSION['user_id'] ?? 0);
        $batchRef  = 'BATCH-' . date('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $totalAmt  = array_sum(array_column($payments, 'amount'));
        $count     = count($payments);

        if ($count === 0) {
            return ['success' => false, 'message' => 'قائمة المدفوعات فارغة'];
        }
        if ($count > BULK_MAX_RECORDS) {
            return ['success' => false, 'message' => "الحد الأقصى {$count} سجل لكل دفعة"];
        }

        // حفظ الدفعة الرئيسية
        $batchId = $this->db->insert('bulk_batches', [
            'reference'    => $batchRef,
            'user_id'      => $userId,
            'name'         => $meta['name'] ?? 'دفعة ' . date('Y-m-d H:i'),
            'total_amount' => $totalAmt,
            'currency'     => $meta['currency'] ?? 'USD',
            'total_count'  => $count,
            'processed'    => 0,
            'succeeded'    => 0,
            'failed'       => 0,
            'status'       => 'pending',
            'created_at'   => date('Y-m-d H:i:s'),
        ]);

        // حفظ السجلات الفردية
        $inserted = 0;
        foreach ($payments as $pay) {
            $this->db->insert('bulk_payment_items', [
                'batch_id'     => $batchId,
                'batch_ref'    => $batchRef,
                'to_address'   => trim($pay['address']   ?? ''),
                'amount'       => floatval($pay['amount']  ?? 0),
                'coin'         => strtoupper($pay['coin']  ?? 'USDT'),
                'network'      => strtoupper($pay['network'] ?? 'TRC20'),
                'reference'    => generateReference('BP'),
                'status'       => 'pending',
                'note'         => $pay['note'] ?? '',
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
            $inserted++;
        }

        $this->log("✓ دفعة جديدة: $batchRef | $count عملية | إجمالي: $totalAmt");

        return [
            'success'      => true,
            'batch_id'     => $batchId,
            'batch_ref'    => $batchRef,
            'total_count'  => $count,
            'total_amount' => $totalAmt,
            'status'       => 'pending',
            'message'      => "تم إنشاء الدفعة — $count عملية بانتظار المعالجة",
        ];
    }

    // ── معالجة الدفعة ────────────────────────────────────

    /**
     * معالجة دفعة — يُشغَّل من Cron أو يدوياً
     */
    public function processBatch(string $batchRef, int $batchSize = BULK_BATCH_SIZE): array
    {
        $batch = $this->db->find('bulk_batches', ['reference' => $batchRef]);
        if (!$batch) return ['success' => false, 'message' => 'الدفعة غير موجودة'];
        if ($batch['status'] === 'completed') return ['success' => true, 'message' => 'مكتملة مسبقاً'];

        // تحديث حالة الدفعة
        $this->db->update('bulk_batches', ['status' => 'processing'], ['reference' => $batchRef]);

        // جلب السجلات المعلقة
        $items = $this->db->query(
            "SELECT * FROM dp_bulk_payment_items
             WHERE batch_ref = ? AND status = 'pending'
             ORDER BY id ASC LIMIT ?",
            [$batchRef, $batchSize]
        );

        $succeeded = 0;
        $failed    = 0;

        foreach ($items as $item) {
            $result = $this->processItem($item);
            if ($result['success']) {
                $succeeded++;
                $this->db->update('bulk_payment_items', [
                    'status'    => 'completed',
                    'tx_hash'   => $result['tx_hash'] ?? null,
                    'processed_at' => date('Y-m-d H:i:s'),
                ], ['id' => $item['id']]);
            } else {
                $failed++;
                $this->db->update('bulk_payment_items', [
                    'status'       => 'failed',
                    'error'        => $result['message'] ?? 'فشل',
                    'processed_at' => date('Y-m-d H:i:s'),
                ], ['id' => $item['id']]);
            }
        }

        // تحديث إحصائيات الدفعة
        $this->db->execute(
            "UPDATE dp_bulk_batches SET
                processed  = processed + ?,
                succeeded  = succeeded + ?,
                failed     = failed + ?,
                status     = CASE WHEN processed + ? >= total_count THEN 'completed' ELSE 'processing' END,
                updated_at = ?
             WHERE reference = ?",
            [count($items), $succeeded, $failed, count($items), date('Y-m-d H:i:s'), $batchRef]
        );

        $this->log("دفعة $batchRef: نجح=$succeeded فشل=$failed");

        return [
            'success'   => true,
            'batch_ref' => $batchRef,
            'processed' => count($items),
            'succeeded' => $succeeded,
            'failed'    => $failed,
        ];
    }

    private function processItem(array $item): array
    {
        require_once __DIR__ . '/HotWalletService.php';
        $hw = HotWalletService::getInstance();
        return $hw->sendUSDT(
            $item['reference'],
            $item['to_address'],
            (float)$item['amount'],
            0
        );
    }

    // ── رفع CSV ──────────────────────────────────────────

    /**
     * تحليل ملف CSV لقائمة مدفوعات
     * الأعمدة المطلوبة: address, amount, coin, network, note
     */
    public function parseCSV(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return ['success' => false, 'message' => 'الملف غير موجود'];
        }

        $payments = [];
        $errors   = [];
        $row      = 0;

        if (($handle = fopen($filePath, 'r')) !== false) {
            $headers = null;
            while (($data = fgetcsv($handle)) !== false) {
                $row++;
                if ($row === 1) {
                    // أول سطر = عناوين
                    $headers = array_map('strtolower', array_map('trim', $data));
                    continue;
                }

                if (!$headers) {
                    $errors[] = "سطر $row: لا يوجد عناوين";
                    continue;
                }

                $line = array_combine($headers, $data);
                $addr = trim($line['address'] ?? $line['wallet'] ?? '');
                $amt  = floatval($line['amount'] ?? 0);

                // تحقق
                if (empty($addr)) {
                    $errors[] = "سطر $row: عنوان المحفظة فارغ";
                    continue;
                }
                if ($amt <= 0) {
                    $errors[] = "سطر $row: مبلغ غير صالح ($amt)";
                    continue;
                }
                if (!preg_match('/^T[A-Za-z0-9]{33}$/', $addr) && !preg_match('/^0x[a-fA-F0-9]{40}$/', $addr)) {
                    $errors[] = "سطر $row: عنوان غير صالح ($addr)";
                    continue;
                }

                $payments[] = [
                    'address' => $addr,
                    'amount'  => $amt,
                    'coin'    => strtoupper(trim($line['coin']    ?? 'USDT')),
                    'network' => strtoupper(trim($line['network'] ?? 'TRC20')),
                    'note'    => trim($line['note'] ?? $line['description'] ?? ''),
                ];
            }
            fclose($handle);
        }

        return [
            'success'  => count($payments) > 0,
            'payments' => $payments,
            'count'    => count($payments),
            'errors'   => $errors,
            'total'    => array_sum(array_column($payments, 'amount')),
        ];
    }

    // ── تقرير الدفعة ─────────────────────────────────────

    public function getBatchReport(string $batchRef): array
    {
        $batch = $this->db->find('bulk_batches', ['reference' => $batchRef]);
        if (!$batch) return ['success' => false, 'message' => 'الدفعة غير موجودة'];

        $items = $this->db->query(
            "SELECT * FROM dp_bulk_payment_items WHERE batch_ref = ? ORDER BY id ASC",
            [$batchRef]
        );

        $byStatus = ['pending' => 0, 'completed' => 0, 'failed' => 0];
        foreach ($items as $item) {
            $byStatus[$item['status']] = ($byStatus[$item['status']] ?? 0) + 1;
        }

        return [
            'success'      => true,
            'batch'        => $batch,
            'items'        => $items,
            'summary'      => [
                'total'     => $batch['total_count'],
                'pending'   => $byStatus['pending'],
                'completed' => $byStatus['completed'],
                'failed'    => $byStatus['failed'],
                'progress'  => $batch['total_count'] > 0
                    ? round(($batch['processed'] / $batch['total_count']) * 100, 1)
                    : 0,
            ],
        ];
    }

    // ── إنشاء الجداول ────────────────────────────────────

    private function ensureTables(): void
    {
        $this->db->execute("CREATE TABLE IF NOT EXISTS `dp_bulk_batches` (
            `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `reference`    VARCHAR(100) NOT NULL UNIQUE,
            `user_id`      INT UNSIGNED NOT NULL,
            `name`         VARCHAR(255) NOT NULL,
            `total_amount` DECIMAL(20,4) NOT NULL DEFAULT 0,
            `currency`     VARCHAR(10)   NOT NULL DEFAULT 'USD',
            `total_count`  INT UNSIGNED NOT NULL DEFAULT 0,
            `processed`    INT UNSIGNED NOT NULL DEFAULT 0,
            `succeeded`    INT UNSIGNED NOT NULL DEFAULT 0,
            `failed`       INT UNSIGNED NOT NULL DEFAULT 0,
            `status`       VARCHAR(20)  NOT NULL DEFAULT 'pending',
            `created_at`   DATETIME     NOT NULL,
            `updated_at`   DATETIME     DEFAULT NULL,
            INDEX `idx_user`   (`user_id`),
            INDEX `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->execute("CREATE TABLE IF NOT EXISTS `dp_bulk_payment_items` (
            `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `batch_id`     INT UNSIGNED NOT NULL,
            `batch_ref`    VARCHAR(100) NOT NULL,
            `reference`    VARCHAR(100) NOT NULL UNIQUE,
            `to_address`   VARCHAR(100) NOT NULL,
            `amount`       DECIMAL(20,8) NOT NULL,
            `coin`         VARCHAR(20)  NOT NULL DEFAULT 'USDT',
            `network`      VARCHAR(20)  NOT NULL DEFAULT 'TRC20',
            `status`       VARCHAR(20)  NOT NULL DEFAULT 'pending',
            `tx_hash`      VARCHAR(100) DEFAULT NULL,
            `error`        TEXT         DEFAULT NULL,
            `note`         TEXT         DEFAULT NULL,
            `created_at`   DATETIME     NOT NULL,
            `processed_at` DATETIME     DEFAULT NULL,
            INDEX `idx_batch`   (`batch_ref`),
            INDEX `idx_status`  (`status`),
            INDEX `idx_address` (`to_address`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    private function log(string $msg): void
    {
        @file_put_contents($this->logFile, '[' . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
    }
}
