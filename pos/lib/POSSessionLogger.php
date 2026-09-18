<?php
/**
 * POS terminal sessions and ISO 8583 Advice logs.
 * Uses DIPARMA PDO (DB_*). Does not store PIN, PIN Block, or full PAN.
 */
if (defined('DI_PARMA_POS_SESSION_LOGGER')) {
    return;
}
define('DI_PARMA_POS_SESSION_LOGGER', true);

class POSSessionLogger
{
    private PDO $db;
    private string $sessions;
    private string $logs;

    public function __construct(PDO $dbConnection)
    {
        $this->db = $dbConnection;
        $this->sessions = function_exists('dp_table') ? dp_table('pos_terminal_sessions') : '`dp_pos_terminal_sessions`';
        $this->logs = function_exists('dp_table') ? dp_table('iso_transaction_logs') : '`dp_iso_transaction_logs`';
        $this->initializeTables();
    }

    public static function fromApp(): ?self
    {
        if (!function_exists('db')) {
            return null;
        }
        $pdo = db()->getPDO();
        if (!$pdo instanceof PDO) {
            return null;
        }
        return new self($pdo);
    }

    private function initializeTables(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS {$this->sessions} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                terminal_id VARCHAR(32) NOT NULL,
                merchant_id VARCHAR(32) NOT NULL,
                session_status VARCHAR(20) DEFAULT 'ACTIVE',
                last_stan VARCHAR(10),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_pos_tid (terminal_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS {$this->logs} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                terminal_id VARCHAR(32) NOT NULL,
                merchant_id VARCHAR(32) DEFAULT NULL,
                mti VARCHAR(4) NOT NULL,
                stan VARCHAR(10),
                rrn VARCHAR(32),
                amount DECIMAL(12,2),
                response_code VARCHAR(5),
                has_pin_block TINYINT(1) NOT NULL DEFAULT 0,
                has_mac TINYINT(1) NOT NULL DEFAULT 0,
                mac_signature VARCHAR(32),
                raw_payload TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                KEY idx_iso_tid (terminal_id),
                KEY idx_iso_rrn (rrn),
                KEY idx_iso_stan (stan)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function logTerminalSession(string $terminalId, string $merchantId, string $stan): void
    {
        $terminalId = strtoupper(trim($terminalId));
        $merchantId = strtoupper(trim($merchantId));
        $stan = substr(preg_replace('/\D/', '', $stan) ?? '', -6);
        if ($terminalId === '' || $merchantId === '') {
            return;
        }
        $stmt = $this->db->prepare("
            INSERT INTO {$this->sessions} (terminal_id, merchant_id, last_stan, session_status)
            VALUES (:terminal_id, :merchant_id, :stan, 'ACTIVE')
            ON DUPLICATE KEY UPDATE
                last_stan = VALUES(last_stan),
                merchant_id = VALUES(merchant_id),
                session_status = 'ACTIVE',
                updated_at = NOW()
        ");
        $stmt->execute([
            ':terminal_id' => substr($terminalId, 0, 32),
            ':merchant_id' => substr($merchantId, 0, 32),
            ':stan' => $stan !== '' ? str_pad($stan, 6, '0', STR_PAD_LEFT) : null,
        ]);
    }

    public function logISOTransaction(array $data): int
    {
        $tid = strtoupper(trim((string) ($data['terminal_id'] ?? '')));
        if ($tid === '') {
            return 0;
        }
        $mti = preg_replace('/\D/', '', (string) ($data['mti'] ?? '')) ?? '';
        $mti = str_pad(substr($mti, 0, 4), 4, '0', STR_PAD_LEFT);
        if ($mti === '0000') {
            return 0;
        }

        $hasPin = !empty($data['has_pin_block']) || (!empty($data['pin_block']) && $data['pin_block'] !== '');
        $mac = strtoupper(preg_replace('/[^0-9A-F]/', '', (string) ($data['mac_signature'] ?? '')) ?? '');
        $payload = self::sanitizePayload((string) ($data['raw_payload'] ?? ''));

        $stmt = $this->db->prepare("
            INSERT INTO {$this->logs}
            (terminal_id, merchant_id, mti, stan, rrn, amount, response_code, has_pin_block, has_mac, mac_signature, raw_payload)
            VALUES
            (:terminal_id, :merchant_id, :mti, :stan, :rrn, :amount, :response_code, :has_pin_block, :has_mac, :mac_signature, :raw_payload)
        ");
        $stmt->execute([
            ':terminal_id' => substr($tid, 0, 32),
            ':merchant_id' => substr(strtoupper(trim((string) ($data['merchant_id'] ?? ''))), 0, 32) ?: null,
            ':mti' => $mti,
            ':stan' => $data['stan'] ?? null,
            ':rrn' => $data['rrn'] ?? null,
            ':amount' => isset($data['amount']) ? (float) $data['amount'] : null,
            ':response_code' => $data['response_code'] ?? null,
            ':has_pin_block' => $hasPin ? 1 : 0,
            ':has_mac' => ($mac !== '' || !empty($data['has_mac'])) ? 1 : 0,
            ':mac_signature' => $mac !== '' ? substr($mac, 0, 32) : null,
            ':raw_payload' => $payload,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public static function sanitizePayload(string $raw): string
    {
        $raw = preg_replace('/\d{13,19}/', '****', $raw) ?? $raw;
        if (strlen($raw) > 4000) {
            $raw = substr($raw, 0, 4000);
        }
        return $raw;
    }
}

function pos_iso_session_log(array $data): void
{
    try {
        $logger = POSSessionLogger::fromApp();
        if (!$logger) {
            return;
        }
        $tid = trim((string) ($data['terminal_id'] ?? ''));
        $mid = trim((string) ($data['merchant_id'] ?? ''));
        $stan = (string) ($data['stan'] ?? '');
        if ($tid !== '' && $mid !== '') {
            $logger->logTerminalSession($tid, $mid, $stan);
        }
        $logger->logISOTransaction($data);
    } catch (Throwable $e) {
        error_log('[POSSessionLogger] ' . $e->getMessage());
    }
}
