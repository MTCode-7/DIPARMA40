<?php
declare(strict_types=1);

/**
 * Keeps AUTH holds aligned in dp_holds and dp_transactions.
 * Idempotent: never deletes payment rows and never invents RRN or approval codes.
 */
final class HoldStoreSync
{
    private const REPAIR_STAMP = 'hold-store-repair-v1';

    public static function repairOnce(bool $force = false): array
    {
        $flag = self::flagPath();
        if (!$force && is_file($flag)) {
            return ['skipped' => true, 'reason' => 'already_ran'];
        }
        try {
            if (!function_exists('db')) {
                return ['skipped' => true, 'reason' => 'no_db'];
            }
            $stats = self::repair(db());
            if ($flag !== '') {
                @mkdir(dirname($flag), 0755, true);
                @file_put_contents($flag, json_encode([
                    'stamp' => self::REPAIR_STAMP,
                    'at' => date('c'),
                    'stats' => $stats,
                ], JSON_UNESCAPED_UNICODE));
            }
            return $stats;
        } catch (Throwable $e) {
            error_log('[HoldStoreSync] repair failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public static function repair($db): array
    {
        self::ensureColumns($db);
        $backfill = self::backfillIdentifiers($db);
        $synced = self::syncMissingSides($db);
        return [
            'ok' => true,
            'backfill' => $backfill,
            'synced' => $synced,
        ];
    }

    public static function ensureColumns($db): void
    {
        $holdAlters = [
            "ALTER TABLE `dp_holds` ADD COLUMN `mode` VARCHAR(20) NOT NULL DEFAULT '2D'",
            "ALTER TABLE `dp_holds` ADD COLUMN `capture_count` INT UNSIGNED NOT NULL DEFAULT 0",
            "ALTER TABLE `dp_holds` ADD COLUMN `last_captured_at` DATETIME NULL",
            "ALTER TABLE `dp_holds` ADD COLUMN `overcaptured` TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE `dp_holds` ADD COLUMN `offline_sale` TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE `dp_holds` ADD COLUMN `rrn` VARCHAR(64) NULL",
            "ALTER TABLE `dp_holds` ADD COLUMN `approval_code` VARCHAR(64) NULL",
            "ALTER TABLE `dp_holds` ADD COLUMN `auth_code` VARCHAR(64) NULL",
        ];
        foreach ($holdAlters as $sql) {
            try {
                $db->execute($sql);
            } catch (Throwable $e) {
            }
        }
        $txn = self::txnTable();
        foreach ([
            "ALTER TABLE {$txn} ADD COLUMN `approval_code` VARCHAR(64) NULL",
            "ALTER TABLE {$txn} ADD COLUMN `auth_code` VARCHAR(64) NULL",
            "ALTER TABLE {$txn} ADD COLUMN `bank_approval_code` VARCHAR(64) NULL",
            "ALTER TABLE {$txn} ADD COLUMN `rrn` VARCHAR(64) NULL",
        ] as $sql) {
            try {
                $db->execute($sql);
            } catch (Throwable $e) {
            }
        }
        try {
            $db->execute("
                CREATE TABLE IF NOT EXISTS `capture_overcaptures` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `payment_intent_id` VARCHAR(100) NOT NULL,
                    `gateway` VARCHAR(30) NOT NULL,
                    `authorized_amount` DECIMAL(12,2) NOT NULL,
                    `total_captured` DECIMAL(12,2) NOT NULL,
                    `over_amount` DECIMAL(12,2) NOT NULL,
                    `mode` VARCHAR(20) DEFAULT '2D',
                    `created_at` DATETIME NOT NULL,
                    INDEX `idx_pi` (`payment_intent_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
        }
        try {
            $db->execute("
                CREATE TABLE IF NOT EXISTS `offline_sales` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `payment_intent_id` VARCHAR(100) NOT NULL,
                    `gateway` VARCHAR(30) NOT NULL,
                    `reference` VARCHAR(100) NOT NULL,
                    `amount` DECIMAL(12,2) NOT NULL,
                    `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
                    `mode` VARCHAR(20) NOT NULL DEFAULT 'OFFLINE',
                    `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
                    `captured_at` DATETIME NULL,
                    `settled_at` DATETIME NULL,
                    `created_at` DATETIME NOT NULL,
                    INDEX `idx_pi` (`payment_intent_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
        }
    }

    /**
     * @param array{transaction_id?:string,payment_id?:string,orig_ref?:string,reference?:string,rrn?:string,approval?:string} $keys
     * @return array{txn:?array,hold:?array}
     */
    public static function find($db, array $keys): array
    {
        $txn = self::findTransaction($db, $keys);
        $hold = self::findHold($db, $keys, $txn);
        return ['txn' => $txn, 'hold' => $hold];
    }

    public static function isClosed(?array $txn, ?array $hold): bool
    {
        $txnStatus = strtolower(trim((string) ($txn['status'] ?? '')));
        $holdStatus = strtolower(trim((string) ($hold['status'] ?? '')));
        if (in_array($holdStatus, ['captured', 'cancelled', 'canceled', 'voided', 'expired'], true)) {
            return true;
        }
        if (in_array($txnStatus, ['captured', 'cancelled', 'canceled', 'voided', 'expired'], true)) {
            return true;
        }
        return false;
    }

    public static function syncAuthorized($db, array $auth): void
    {
        self::ensureColumns($db);
        $pi = trim((string) ($auth['payment_intent_id'] ?? $auth['payment_id'] ?? $auth['transaction_id'] ?? ''));
        $reference = trim((string) ($auth['reference'] ?? ''));
        if ($pi === '' && $reference === '') {
            return;
        }
        $existing = self::find($db, [
            'payment_id' => $pi,
            'transaction_id' => $pi,
            'reference' => $reference,
            'orig_ref' => $reference,
            'rrn' => (string) ($auth['rrn'] ?? ''),
            'approval' => (string) ($auth['approval_code'] ?? $auth['auth_code'] ?? ''),
        ]);
        if (self::isClosed($existing['txn'], $existing['hold'])) {
            self::alignTerminalStatus($db, $existing['txn'], $existing['hold']);
            return;
        }
        self::upsertHold($db, $auth, $existing['hold']);
        self::upsertAuthTransaction($db, $auth, $existing['txn']);
    }

    public static function markCaptured($db, array $keys, float $amount): void
    {
        $found = self::find($db, $keys);
        $now = date('Y-m-d H:i:s');
        $authorized = (float) ($found['hold']['amount'] ?? $found['txn']['amount'] ?? 0);
        $already = (float) ($found['hold']['captured_amount'] ?? 0);
        $total = $already + $amount;
        $status = ($authorized > 0 && $total + 0.009 < $authorized) ? 'partially_captured' : 'captured';
        if (is_array($found['hold']) && !empty($found['hold']['payment_intent_id'])) {
            $holdCols = self::columns($db, 'dp_holds');
            $sets = ['status' => $status];
            if (isset($holdCols['captured_amount'])) {
                $sets['captured_amount'] = $total;
            }
            if (isset($holdCols['captured_at'])) {
                $sets['captured_at'] = $found['hold']['captured_at'] ?? $now;
            }
            if (isset($holdCols['last_captured_at'])) {
                $sets['last_captured_at'] = $now;
            }
            self::updateById($db, 'dp_holds', (int) $found['hold']['id'], $sets);
        }
        if (is_array($found['txn']) && !empty($found['txn']['id'])) {
            $txnStatus = $status === 'partially_captured' ? 'authorized' : 'captured';
            self::updateById($db, self::txnBare(), (int) $found['txn']['id'], [
                'status' => $txnStatus,
                'updated_at' => $now,
            ]);
        }
    }

    public static function markCancelled($db, array $keys): void
    {
        $found = self::find($db, $keys);
        $now = date('Y-m-d H:i:s');
        if (is_array($found['hold']) && !empty($found['hold']['id'])) {
            $sets = ['status' => 'cancelled'];
            $cols = self::columns($db, 'dp_holds');
            if (isset($cols['cancelled_at'])) {
                $sets['cancelled_at'] = $now;
            }
            self::updateById($db, 'dp_holds', (int) $found['hold']['id'], $sets);
        }
        if (is_array($found['txn']) && !empty($found['txn']['id'])) {
            self::updateById($db, self::txnBare(), (int) $found['txn']['id'], [
                'status' => 'cancelled',
                'updated_at' => $now,
            ]);
        }
    }

    private static function backfillIdentifiers($db): int
    {
        $updated = 0;
        $txn = self::txnTable();
        $rows = [];
        try {
            $rows = $db->query(
                "SELECT * FROM {$txn} WHERE transaction_type IN ('auth','auth_hold','auth_moto','hold') ORDER BY id ASC"
            );
        } catch (Throwable $e) {
            $rows = [];
        }
        foreach ($rows as $row) {
            $pulled = self::pullIdentifiers((string) ($row['gateway_response'] ?? ''));
            $patch = [];
            if (trim((string) ($row['rrn'] ?? '')) === '' && $pulled['rrn'] !== '') {
                $patch['rrn'] = $pulled['rrn'];
            }
            if (trim((string) ($row['approval_code'] ?? '')) === '' && $pulled['approval'] !== '') {
                $patch['approval_code'] = $pulled['approval'];
            }
            if (trim((string) ($row['auth_code'] ?? '')) === '' && $pulled['approval'] !== '') {
                $patch['auth_code'] = $pulled['approval'];
            }
            if ($patch !== []) {
                self::updateById($db, self::txnBare(), (int) $row['id'], $patch);
                $updated++;
            }
        }
        try {
            $holds = $db->query('SELECT * FROM `dp_holds` ORDER BY id ASC');
        } catch (Throwable $e) {
            $holds = [];
        }
        foreach ($holds as $hold) {
            $meta = (string) ($hold['meta'] ?? '');
            $pulled = self::pullIdentifiers($meta);
            $patch = [];
            $cols = self::columns($db, 'dp_holds');
            if (isset($cols['rrn']) && trim((string) ($hold['rrn'] ?? '')) === '' && $pulled['rrn'] !== '') {
                $patch['rrn'] = $pulled['rrn'];
            }
            if (isset($cols['approval_code']) && trim((string) ($hold['approval_code'] ?? '')) === '' && $pulled['approval'] !== '') {
                $patch['approval_code'] = $pulled['approval'];
            }
            if (isset($cols['auth_code']) && trim((string) ($hold['auth_code'] ?? '')) === '' && $pulled['approval'] !== '') {
                $patch['auth_code'] = $pulled['approval'];
            }
            if ($patch !== []) {
                self::updateById($db, 'dp_holds', (int) $hold['id'], $patch);
                $updated++;
            }
        }
        return $updated;
    }

    private static function syncMissingSides($db): int
    {
        $count = 0;
        $txn = self::txnTable();
        $rows = [];
        try {
            $rows = $db->query(
                "SELECT * FROM {$txn} WHERE transaction_type IN ('auth','auth_hold','auth_moto','hold')
                 AND status IN ('authorized','completed','approved','captured','partially_captured','cancelled','canceled','voided')"
            );
        } catch (Throwable $e) {
            $rows = [];
        }
        foreach ($rows as $row) {
            $pi = self::paymentIdFromRow($row);
            $found = self::findHold($db, [
                'payment_id' => $pi,
                'reference' => (string) ($row['reference'] ?? ''),
                'rrn' => (string) ($row['rrn'] ?? ''),
                'approval' => (string) ($row['approval_code'] ?? $row['auth_code'] ?? ''),
            ], $row);
            if ($found === null) {
                self::upsertHold($db, self::authFromTransaction($row), null);
                $count++;
            } else {
                self::alignTerminalStatus($db, $row, $found);
            }
        }
        try {
            $holds = $db->query(
                "SELECT * FROM `dp_holds` WHERE status IN ('authorized','captured','partially_captured','cancelled','canceled')"
            );
        } catch (Throwable $e) {
            $holds = [];
        }
        foreach ($holds as $hold) {
            $txnRow = self::findTransaction($db, [
                'payment_id' => (string) ($hold['payment_intent_id'] ?? ''),
                'reference' => (string) ($hold['reference'] ?? ''),
                'rrn' => (string) ($hold['rrn'] ?? ''),
                'approval' => (string) ($hold['approval_code'] ?? $hold['auth_code'] ?? ''),
            ]);
            if ($txnRow === null) {
                self::upsertAuthTransaction($db, self::authFromHold($hold), null);
                $count++;
            } else {
                self::alignTerminalStatus($db, $txnRow, $hold);
            }
        }
        return $count;
    }

    private static function alignTerminalStatus($db, ?array $txn, ?array $hold): void
    {
        $rank = [
            'pending' => 1,
            'authorized' => 2,
            'approved' => 2,
            'completed' => 2,
            'partially_captured' => 3,
            'captured' => 4,
            'cancelled' => 5,
            'canceled' => 5,
            'voided' => 5,
            'expired' => 5,
        ];
        $txnStatus = strtolower(trim((string) ($txn['status'] ?? '')));
        $holdStatus = strtolower(trim((string) ($hold['status'] ?? '')));
        if ($txnStatus === 'completed' || $txnStatus === 'approved') {
            $later = self::completedCaptureExists($db, $txn);
            $txnStatus = $later ? 'captured' : 'authorized';
        }
        $best = ($rank[$holdStatus] ?? 0) >= ($rank[$txnStatus] ?? 0) ? $holdStatus : $txnStatus;
        if ($best === '' || ($rank[$best] ?? 0) < 2) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        if (is_array($hold) && !empty($hold['id']) && ($rank[$holdStatus] ?? 0) < ($rank[$best] ?? 0)) {
            self::updateById($db, 'dp_holds', (int) $hold['id'], ['status' => $best === 'authorized' ? 'authorized' : $best]);
        }
        if (is_array($txn) && !empty($txn['id']) && ($rank[$txnStatus] ?? 0) < ($rank[$best] ?? 0)) {
            $write = $best === 'partially_captured' ? 'authorized' : $best;
            self::updateById($db, self::txnBare(), (int) $txn['id'], [
                'status' => $write,
                'updated_at' => $now,
            ]);
        }
    }

    private static function completedCaptureExists($db, ?array $auth): bool
    {
        if (!is_array($auth)) {
            return false;
        }
        $rrn = trim((string) ($auth['rrn'] ?? ''));
        $pi = self::paymentIdFromRow($auth);
        if ($rrn === '' && $pi === '') {
            return false;
        }
        $where = [];
        $bind = [];
        if ($rrn !== '') {
            $where[] = 'rrn = ?';
            $bind[] = $rrn;
        }
        if ($pi !== '') {
            $where[] = 'gateway_response LIKE ?';
            $bind[] = '%' . self::like($pi) . '%';
        }
        try {
            $hit = $db->query(
                'SELECT id FROM ' . self::txnTable() . " WHERE transaction_type = 'capture' AND status IN ('completed','captured','approved') AND (" . implode(' OR ', $where) . ') LIMIT 1',
                $bind
            );
            return !empty($hit);
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function findTransaction($db, array $keys): ?array
    {
        $id = trim((string) ($keys['local_id'] ?? ''));
        $payment = trim((string) ($keys['payment_id'] ?? $keys['transaction_id'] ?? ''));
        $reference = trim((string) ($keys['reference'] ?? $keys['orig_ref'] ?? ''));
        $rrn = preg_replace('/\D/', '', (string) ($keys['rrn'] ?? $keys['orig_ref'] ?? '')) ?? '';
        $approval = trim((string) ($keys['approval'] ?? ''));
        $where = [];
        $bind = [];
        if ($id !== '' && ctype_digit($id)) {
            $where[] = 'id = ?';
            $bind[] = (int) $id;
        }
        if ($reference !== '') {
            $where[] = 'reference = ?';
            $bind[] = $reference;
        }
        if ($rrn !== '') {
            $where[] = 'rrn = ?';
            $bind[] = $rrn;
        }
        if ($payment !== '') {
            $where[] = 'gateway_response LIKE ?';
            $bind[] = '%' . self::like($payment) . '%';
        }
        if ($approval !== '') {
            $where[] = 'approval_code = ?';
            $bind[] = $approval;
            $where[] = 'auth_code = ?';
            $bind[] = $approval;
            $where[] = 'bank_approval_code = ?';
            $bind[] = $approval;
        }
        if ($where === []) {
            return null;
        }
        try {
            $rows = $db->query(
                'SELECT * FROM ' . self::txnTable() . " WHERE transaction_type IN ('auth','auth_hold','auth_moto','hold') AND (" . implode(' OR ', $where) . ') ORDER BY id DESC LIMIT 20',
                $bind
            );
        } catch (Throwable $e) {
            return null;
        }
        $match = self::bestRow($rows, $keys);
        if ($match !== null || $approval === '') {
            return $match;
        }
        try {
            $rows = $db->query(
                'SELECT * FROM ' . self::txnTable() . " WHERE transaction_type IN ('auth','auth_hold','auth_moto','hold') ORDER BY id DESC LIMIT 200"
            );
        } catch (Throwable $e) {
            return null;
        }
        foreach ($rows as $row) {
            $stored = (string) ($row['approval_code'] ?? $row['auth_code'] ?? $row['bank_approval_code'] ?? '');
            if ($stored !== '' && self::codesMatch($stored, $approval)) {
                return $row;
            }
        }
        return null;
    }

    private static function findHold($db, array $keys, ?array $txn): ?array
    {
        $payment = trim((string) ($keys['payment_id'] ?? $keys['transaction_id'] ?? ''));
        if ($payment === '' && is_array($txn)) {
            $payment = self::paymentIdFromRow($txn);
        }
        $reference = trim((string) ($keys['reference'] ?? $keys['orig_ref'] ?? ($txn['reference'] ?? '')));
        $rrn = trim((string) ($keys['rrn'] ?? ($txn['rrn'] ?? '')));
        $approval = trim((string) ($keys['approval'] ?? ($txn['approval_code'] ?? $txn['auth_code'] ?? '')));
        $where = [];
        $bind = [];
        if ($payment !== '') {
            $where[] = 'payment_intent_id = ?';
            $bind[] = $payment;
        }
        if ($reference !== '') {
            $where[] = 'reference = ?';
            $bind[] = $reference;
        }
        $cols = self::columns($db, 'dp_holds');
        if ($rrn !== '' && isset($cols['rrn'])) {
            $where[] = 'rrn = ?';
            $bind[] = preg_replace('/\D/', '', $rrn) ?: $rrn;
        }
        if ($approval !== '' && isset($cols['approval_code'])) {
            $where[] = 'approval_code = ?';
            $bind[] = $approval;
        }
        if ($approval !== '' && isset($cols['auth_code'])) {
            $where[] = 'auth_code = ?';
            $bind[] = $approval;
        }
        if ($where === []) {
            return null;
        }
        try {
            $rows = $db->query(
                'SELECT * FROM `dp_holds` WHERE ' . implode(' OR ', $where) . ' ORDER BY id DESC LIMIT 20',
                $bind
            );
        } catch (Throwable $e) {
            return null;
        }
        $match = self::bestRow($rows, $keys);
        if ($match !== null || $approval === '') {
            return $match;
        }
        try {
            $rows = $db->query('SELECT * FROM `dp_holds` ORDER BY id DESC LIMIT 200');
        } catch (Throwable $e) {
            return null;
        }
        foreach ($rows as $row) {
            $stored = (string) ($row['approval_code'] ?? $row['auth_code'] ?? '');
            if ($stored !== '' && self::codesMatch($stored, $approval)) {
                return $row;
            }
        }
        return null;
    }

    private static function bestRow(array $rows, array $keys): ?array
    {
        if ($rows === []) {
            return null;
        }
        $approval = trim((string) ($keys['approval'] ?? ''));
        $payment = trim((string) ($keys['payment_id'] ?? $keys['transaction_id'] ?? ''));
        $rrn = preg_replace('/\D/', '', (string) ($keys['rrn'] ?? '')) ?? '';
        $best = null;
        $bestScore = -1;
        foreach ($rows as $row) {
            $score = 0;
            $storedApproval = (string) ($row['approval_code'] ?? $row['auth_code'] ?? $row['bank_approval_code'] ?? '');
            if ($approval !== '') {
                if (!self::codesMatch($storedApproval, $approval) && strcasecmp($storedApproval, $approval) !== 0) {
                    $pi = (string) ($row['payment_intent_id'] ?? '');
                    $ref = (string) ($row['reference'] ?? '');
                    $rowRrn = preg_replace('/\D/', '', (string) ($row['rrn'] ?? '')) ?? '';
                    $paymentHit = $payment !== '' && ($pi === $payment || str_contains((string) ($row['gateway_response'] ?? ''), $payment));
                    $rrnHit = $rrn !== '' && $rowRrn === $rrn;
                    $refHit = $ref !== '' && ($ref === ($keys['reference'] ?? '') || $ref === ($keys['orig_ref'] ?? ''));
                    if (!$paymentHit && !$rrnHit && !$refHit) {
                        continue;
                    }
                } else {
                    $score += 5;
                }
            }
            if ($payment !== '' && ((string) ($row['payment_intent_id'] ?? '') === $payment || str_contains((string) ($row['gateway_response'] ?? ''), $payment))) {
                $score += 4;
            }
            if ($rrn !== '' && (preg_replace('/\D/', '', (string) ($row['rrn'] ?? '')) ?? '') === $rrn) {
                $score += 3;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
            }
        }
        return $best ?? ($approval === '' ? $rows[0] : null);
    }

    /**
     * Full stored code, or the 4/6-digit receipt form.
     * A longer purely numeric code matches its last 6 digits.
     */
    public static function codesMatch(string $stored, string $entered): bool
    {
        $stored = trim($stored);
        $entered = trim($entered);
        if ($stored === '' || $entered === '') {
            return false;
        }
        if (strcasecmp($stored, $entered) === 0) {
            return true;
        }
        if (function_exists('pos_approval_receipt_label')) {
            $label = pos_approval_receipt_label($stored);
        } else {
            $label = self::receiptLabel($stored);
        }
        return $label !== '' && strcasecmp($label, $entered) === 0;
    }

    /**
     * Receipt label: 4 or 6 digits stay; a longer purely numeric code shows the last 6.
     * The stored value is never rewritten here.
     */
    public static function receiptLabel(string $stored): string
    {
        $stored = trim($stored);
        if ($stored === '' || !ctype_digit($stored)) {
            return $stored;
        }
        $len = strlen($stored);
        if ($len === 4 || $len === 6) {
            return $stored;
        }
        if ($len > 6) {
            return substr($stored, -6);
        }
        return $stored;
    }

    private static function upsertHold($db, array $auth, ?array $existing): void
    {
        $pi = trim((string) ($auth['payment_intent_id'] ?? $auth['payment_id'] ?? ''));
        $reference = trim((string) ($auth['reference'] ?? ''));
        if ($pi === '') {
            $pi = $reference;
        }
        if ($pi === '' || $reference === '') {
            return;
        }
        $status = self::holdStatusFromAuth($auth);
        $cols = self::columns($db, 'dp_holds');
        $data = [
            'reference' => $reference,
            'user_id' => (int) ($auth['user_id'] ?? 0),
            'payment_intent_id' => $pi,
            'amount' => (float) ($auth['amount'] ?? 0),
            'currency' => strtoupper((string) ($auth['currency'] ?? 'USD')),
            'status' => $status,
            'gateway' => strtolower((string) ($auth['gateway'] ?? '')),
        ];
        if (isset($cols['mode'])) {
            $data['mode'] = (string) ($auth['mode'] ?? '2D');
        }
        if (isset($cols['rrn']) && trim((string) ($auth['rrn'] ?? '')) !== '') {
            $data['rrn'] = (string) $auth['rrn'];
        }
        $approval = trim((string) ($auth['approval_code'] ?? $auth['auth_code'] ?? ''));
        if ($approval !== '') {
            if (isset($cols['approval_code'])) {
                $data['approval_code'] = $approval;
            }
            if (isset($cols['auth_code'])) {
                $data['auth_code'] = $approval;
            }
        }
        if (isset($cols['meta'])) {
            $data['meta'] = (string) ($auth['gateway_response'] ?? $auth['meta'] ?? '');
        }
        if (is_array($existing) && !empty($existing['id'])) {
            unset($data['reference'], $data['payment_intent_id'], $data['user_id'], $data['amount']);
            if (trim((string) ($existing['rrn'] ?? '')) !== '') {
                unset($data['rrn']);
            }
            if (trim((string) ($existing['approval_code'] ?? '')) !== '') {
                unset($data['approval_code'], $data['auth_code']);
            }
            if (self::isClosed(null, $existing) && ($data['status'] ?? '') === 'authorized') {
                unset($data['status']);
            }
            if ($data === []) {
                return;
            }
            self::updateById($db, 'dp_holds', (int) $existing['id'], $data);
            return;
        }
        $data['created_at'] = date('Y-m-d H:i:s');
        if ($status === 'authorized' && isset($cols['authorized_at'])) {
            $data['authorized_at'] = date('Y-m-d H:i:s');
        }
        try {
            $db->insert('dp_holds', self::onlyColumns($data, $cols));
        } catch (Throwable $e) {
            error_log('[HoldStoreSync] hold insert skipped: ' . $e->getMessage());
        }
    }

    private static function upsertAuthTransaction($db, array $auth, ?array $existing): void
    {
        $reference = trim((string) ($auth['reference'] ?? ''));
        if ($reference === '') {
            return;
        }
        $approval = trim((string) ($auth['approval_code'] ?? $auth['auth_code'] ?? ''));
        $status = self::txnStatusFromAuth($auth);
        $payload = [
            'reference' => $reference,
            'user_id' => (int) ($auth['user_id'] ?? 0) ?: null,
            'gateway' => strtolower((string) ($auth['gateway'] ?? '')),
            'transaction_type' => 'auth',
            'amount' => (float) ($auth['amount'] ?? 0),
            'currency' => strtoupper((string) ($auth['currency'] ?? 'USD')),
            'status' => $status,
            'rrn' => trim((string) ($auth['rrn'] ?? '')) !== '' ? (string) $auth['rrn'] : null,
            'approval_code' => $approval !== '' ? $approval : null,
            'auth_code' => $approval !== '' ? $approval : null,
            'bank_approval_code' => $approval !== '' ? $approval : null,
            'gateway_response' => (string) ($auth['gateway_response'] ?? ''),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if (is_array($existing) && !empty($existing['id'])) {
            $patch = [];
            if (trim((string) ($existing['rrn'] ?? '')) === '' && !empty($payload['rrn'])) {
                $patch['rrn'] = $payload['rrn'];
            }
            if (trim((string) ($existing['approval_code'] ?? '')) === '' && $approval !== '') {
                $patch['approval_code'] = $approval;
                $patch['auth_code'] = $approval;
            }
            if (trim((string) ($existing['auth_code'] ?? '')) === '' && $approval !== '') {
                $patch['auth_code'] = $approval;
            }
            $patch['updated_at'] = $payload['updated_at'];
            self::updateById($db, self::txnBare(), (int) $existing['id'], $patch);
            return;
        }
        $payload['created_at'] = date('Y-m-d H:i:s');
        try {
            $db->insertAvailable('transactions', $payload);
        } catch (Throwable $e) {
            error_log('[HoldStoreSync] auth insert skipped: ' . $e->getMessage());
        }
    }

    private static function authFromTransaction(array $row): array
    {
        $pulled = self::pullIdentifiers((string) ($row['gateway_response'] ?? ''));
        return [
            'reference' => (string) ($row['reference'] ?? ''),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'payment_intent_id' => self::paymentIdFromRow($row),
            'amount' => (float) ($row['amount'] ?? 0),
            'currency' => (string) ($row['currency'] ?? 'USD'),
            'gateway' => (string) ($row['gateway'] ?? ''),
            'status' => (string) ($row['status'] ?? 'authorized'),
            'rrn' => trim((string) ($row['rrn'] ?? '')) !== '' ? (string) $row['rrn'] : $pulled['rrn'],
            'approval_code' => trim((string) ($row['approval_code'] ?? $row['auth_code'] ?? '')) !== ''
                ? (string) ($row['approval_code'] ?? $row['auth_code'])
                : $pulled['approval'],
            'gateway_response' => (string) ($row['gateway_response'] ?? ''),
            'mode' => '2D',
        ];
    }

    private static function authFromHold(array $hold): array
    {
        $pulled = self::pullIdentifiers((string) ($hold['meta'] ?? ''));
        $status = strtolower((string) ($hold['status'] ?? 'authorized'));
        return [
            'reference' => (string) ($hold['reference'] ?? ''),
            'user_id' => (int) ($hold['user_id'] ?? 0),
            'payment_intent_id' => (string) ($hold['payment_intent_id'] ?? ''),
            'amount' => (float) ($hold['amount'] ?? 0),
            'currency' => (string) ($hold['currency'] ?? 'USD'),
            'gateway' => (string) ($hold['gateway'] ?? ''),
            'status' => $status,
            'rrn' => trim((string) ($hold['rrn'] ?? '')) !== '' ? (string) $hold['rrn'] : $pulled['rrn'],
            'approval_code' => trim((string) ($hold['approval_code'] ?? $hold['auth_code'] ?? '')) !== ''
                ? (string) ($hold['approval_code'] ?? $hold['auth_code'])
                : $pulled['approval'],
            'gateway_response' => (string) ($hold['meta'] ?? ''),
            'mode' => (string) ($hold['mode'] ?? '2D'),
        ];
    }

    private static function holdStatusFromAuth(array $auth): string
    {
        $status = strtolower(trim((string) ($auth['status'] ?? 'authorized')));
        if (in_array($status, ['captured', 'cancelled', 'canceled', 'voided', 'partially_captured', 'authorized', 'pending'], true)) {
            return $status === 'canceled' || $status === 'voided' ? 'cancelled' : $status;
        }
        if (in_array($status, ['completed', 'approved'], true)) {
            return 'authorized';
        }
        return 'authorized';
    }

    private static function txnStatusFromAuth(array $auth): string
    {
        $status = self::holdStatusFromAuth($auth);
        if ($status === 'partially_captured') {
            return 'authorized';
        }
        return $status;
    }

    /** @return array{rrn:string,approval:string} */
    private static function pullIdentifiers(string $json): array
    {
        $data = json_decode($json, true);
        $rrn = '';
        $approval = '';
        if (!is_array($data)) {
            return ['rrn' => '', 'approval' => ''];
        }
        $walk = static function ($node) use (&$walk, &$rrn, &$approval): void {
            if (!is_array($node) || ($rrn !== '' && $approval !== '')) {
                return;
            }
            foreach (['rrn', 'retrieval_reference_number', 'retrievalReferenceNumber', 'acquirer_reference_number'] as $key) {
                if ($rrn === '' && isset($node[$key]) && is_scalar($node[$key]) && trim((string) $node[$key]) !== '') {
                    $rrn = trim((string) $node[$key]);
                }
            }
            foreach (['gateway_approval_code', 'approval_code', 'auth_code', 'authCode', 'approvalCode', 'authorization_code', 'auth_result_code'] as $key) {
                if ($approval === '' && isset($node[$key]) && is_scalar($node[$key]) && trim((string) $node[$key]) !== '') {
                    $approval = trim((string) $node[$key]);
                }
            }
            foreach ($node as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($data);
        return ['rrn' => $rrn, 'approval' => $approval];
    }

    private static function paymentIdFromRow(array $row): string
    {
        $blob = json_decode((string) ($row['gateway_response'] ?? ''), true);
        if (is_array($blob)) {
            foreach (['payment_id', 'authorization_id', 'transaction_id', 'nuvei_txn_id'] as $key) {
                $value = trim((string) ($blob[$key] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return '';
    }

    private static function updateById($db, string $table, int $id, array $data): void
    {
        if ($id <= 0 || $data === []) {
            return;
        }
        $cols = self::columns($db, $table);
        $data = self::onlyColumns($data, $cols);
        if ($data === []) {
            return;
        }
        try {
            $db->update($table, $data, ['id' => $id]);
        } catch (Throwable $e) {
            error_log('[HoldStoreSync] update skipped: ' . $e->getMessage());
        }
    }

    private static function onlyColumns(array $data, array $cols): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (isset($cols[strtolower($key)])) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    private static function columns($db, string $table): array
    {
        static $cache = [];
        $table = trim($table, '`');
        if (isset($cache[$table])) {
            return $cache[$table];
        }
        $physical = str_starts_with($table, 'dp_') ? $table : ((defined('DB_PREFIX') ? DB_PREFIX : 'dp_') . $table);
        try {
            $rows = $db->query('SHOW COLUMNS FROM `' . $physical . '`');
        } catch (Throwable $e) {
            $cache[$table] = [];
            return [];
        }
        $cols = [];
        foreach ($rows as $row) {
            $field = strtolower((string) ($row['Field'] ?? ''));
            if ($field !== '') {
                $cols[$field] = true;
            }
        }
        $cache[$table] = $cols;
        return $cols;
    }

    private static function txnTable(): string
    {
        $prefix = defined('DB_PREFIX') ? (string) DB_PREFIX : 'dp_';
        return '`' . $prefix . 'transactions`';
    }

    private static function txnBare(): string
    {
        return 'transactions';
    }

    private static function like(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private static function flagPath(): string
    {
        $root = dirname(__DIR__);
        $dir = defined('CACHE_PATH') ? (string) CACHE_PATH : ($root . '/cache');
        return rtrim($dir, '/\\') . '/' . self::REPAIR_STAMP . '.json';
    }
}
