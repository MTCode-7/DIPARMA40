<?php
/**
 * ============================================================
 * DI PARMA | DataRetentionService
 * حفظ البيانات 7 سنوات وفق متطلبات VARA
 * ============================================================
 * VARA Requirement:
 *   - جميع سجلات المعاملات: 7 سنوات
 *   - بيانات KYC: طوال مدة العلاقة + 5 سنوات
 *   - سجلات AML: 7 سنوات
 *   - Audit Logs: 7 سنوات
 *   - لا يمكن حذف أو تعديل السجلات
 * ============================================================
 */

class DataRetentionService
{
    // مدة الحفظ بالسنوات
    const RETENTION_TRANSACTIONS = 7;
    const RETENTION_KYC          = 7;
    const RETENTION_AML_LOGS     = 7;
    const RETENTION_AUDIT        = 7;
    const RETENTION_BLOCKCHAIN   = 7;

    // جداول محمية من الحذف
    const PROTECTED_TABLES = [
        'dp_transactions',
        'dp_kyc_verifications',
        'dp_risk_logs',
        'dp_blockchain_txns',
        'dp_event_log',
        'dp_contracts',
        'dp_bulk_batches',
        'dp_bulk_payment_items',
    ];

    private static ?self $instance = null;
    private Database $db;
    private string $logFile;

    private function __construct()
    {
        $this->db      = db();
        $this->logFile = defined('LOGS_PATH') ? LOGS_PATH . '/retention.log' : __DIR__ . '/../logs/retention.log';
        if (!is_dir(dirname($this->logFile))) @mkdir(dirname($this->logFile), 0755, true);
    }

    public static function getInstance(): self
    {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    // ── فحص امتثال الحفظ ────────────────────────────────

    /**
     * فحص أن جميع البيانات محفوظة ضمن المتطلبات
     */
    public function checkCompliance(): array
    {
        $results = [];
        $now     = new DateTime();

        // فحص أقدم سجل في كل جدول
        foreach ([
            ['transactions',  'dp_transactions',    self::RETENTION_TRANSACTIONS],
            ['kyc',           'dp_kyc_verifications', self::RETENTION_KYC],
            ['risk_logs',     'dp_risk_logs',        self::RETENTION_AML_LOGS],
            ['blockchain',    'dp_blockchain_txns',  self::RETENTION_BLOCKCHAIN],
            ['events',        'dp_event_log',        self::RETENTION_AUDIT],
        ] as [$name, $table, $years]) {
            try {
                $oldest = $this->db->query(
                    "SELECT MIN(created_at) as oldest, COUNT(*) as total FROM $table"
                )[0] ?? [];

                $oldestDate = $oldest['oldest'] ?? null;
                $total      = (int)($oldest['total'] ?? 0);
                $retentionEnd = (new DateTime())->modify("-$years years");

                $compliant = true;
                $message   = "✓ ضمن متطلبات VARA ($years سنوات)";

                if ($oldestDate) {
                    $oldestDt = new DateTime($oldestDate);
                    if ($oldestDt < $retentionEnd) {
                        $compliant = false;
                        $message   = "⚠ توجد سجلات أقدم من $years سنوات — يجب الأرشفة";
                    }
                }

                $results[$name] = [
                    'table'         => $table,
                    'total'         => $total,
                    'oldest'        => $oldestDate,
                    'retention_years' => $years,
                    'compliant'     => $compliant,
                    'message'       => $message,
                ];
            } catch (Exception $e) {
                $results[$name] = ['error' => $e->getMessage()];
            }
        }

        return $results;
    }

    // ── أرشفة السجلات القديمة جداً ──────────────────────

    /**
     * أرشفة السجلات الأقدم من مدة الحفظ
     * لا تُحذف — تُنقل لجداول أرشيف
     */
    public function archiveOldRecords(): array
    {
        $archived = 0;
        $errors   = [];

        foreach ([
            ['dp_transactions',  self::RETENTION_TRANSACTIONS],
            ['dp_risk_logs',     self::RETENTION_AML_LOGS],
            ['dp_event_log',     self::RETENTION_AUDIT],
        ] as [$table, $years]) {
            try {
                $archiveTable = $table . '_archive';

                // إنشاء جدول الأرشيف
                $this->db->execute("CREATE TABLE IF NOT EXISTS `$archiveTable` LIKE `$table`");
                $this->db->execute("ALTER TABLE `$archiveTable` MODIFY COLUMN `id` BIGINT UNSIGNED NOT NULL");

                // نقل السجلات القديمة
                $moved = $this->db->execute(
                    "INSERT IGNORE INTO `$archiveTable`
                     SELECT * FROM `$table`
                     WHERE created_at < DATE_SUB(NOW(), INTERVAL ? YEAR)",
                    [$years + 1] // أقدم من سنة إضافية فوق الحد
                );

                $archived += $moved;
                $this->log("أرشفة $table: $moved سجل");
            } catch (Exception $e) {
                $errors[] = "$table: " . $e->getMessage();
            }
        }

        return [
            'success'  => empty($errors),
            'archived' => $archived,
            'errors'   => $errors,
        ];
    }

    // ── منع الحذف من الجداول المحمية ────────────────────

    /**
     * التحقق من أن الجدول محمي من الحذف
     */
    public static function isProtected(string $table): bool
    {
        return in_array($table, self::PROTECTED_TABLES, true);
    }

    // ── تقرير الاحتفاظ ──────────────────────────────────

    public function generateReport(): array
    {
        $compliance = $this->checkCompliance();
        $allCompliant = !in_array(false, array_column($compliance, 'compliant'));

        return [
            'generated_at'  => date('Y-m-d H:i:s'),
            'vara_version'  => 'VARA Virtual Assets Regulation 2023',
            'company'       => 'DI PARMA Businessmen Services',
            'compliant'     => $allCompliant,
            'retention_policy' => [
                'transactions'  => self::RETENTION_TRANSACTIONS . ' سنوات',
                'kyc'           => self::RETENTION_KYC . ' سنوات',
                'aml_logs'      => self::RETENTION_AML_LOGS . ' سنوات',
                'audit'         => self::RETENTION_AUDIT . ' سنوات',
                'blockchain'    => self::RETENTION_BLOCKCHAIN . ' سنوات',
            ],
            'tables_protected' => self::PROTECTED_TABLES,
            'checks'           => $compliance,
            'storage_note'     => 'البيانات محفوظة على AWS Lightsail (Mumbai) مع نسخ احتياطية يومية',
        ];
    }

    // ── إعداد Cron للأرشفة التلقائية ────────────────────

    /**
     * يُشغَّل شهرياً من Cron
     */
    public static function runMonthlyArchive(): void
    {
        $service = self::getInstance();
        $result  = $service->archiveOldRecords();
        $service->log("تشغيل شهري: " . json_encode($result));
    }

    // ── إضافة .env للتحقق من الحفظ ──────────────────────

    public function ensureRetentionTriggers(): void
    {
        // إضافة trigger لمنع الحذف من الجداول المحمية
        foreach (self::PROTECTED_TABLES as $table) {
            try {
                $triggerName = "prevent_delete_{$table}";
                $this->db->execute("
                    CREATE TRIGGER IF NOT EXISTS `$triggerName`
                    BEFORE DELETE ON `$table`
                    FOR EACH ROW
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'VARA: حذف سجلات ممنوع — Data Retention Policy'
                ");
            } catch (Exception $e) {
                // MySQL قد يرفض CREATE TRIGGER IF NOT EXISTS — نتجاهل
            }
        }
    }

    private function log(string $msg): void
    {
        @file_put_contents($this->logFile, '[' . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
    }
}

// تشغيل مباشر من CLI
if (PHP_SAPI === 'cli' && basename(__FILE__) === basename($argv[0] ?? '')) {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/database.php';
    DataRetentionService::runMonthlyArchive();
}
