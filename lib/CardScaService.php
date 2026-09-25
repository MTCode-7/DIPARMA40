<?php
/**
 * Card 3DS / OTP: challenge on first successful use only.
 * Subsequent charges for the same card skip OTP.
 */
class CardScaService
{
    public static function fingerprint(string $cardNumber, string $expiry = '', string $last4 = ''): string
    {
        $pan = preg_replace('/\D/', '', $cardNumber) ?? '';
        $last4 = preg_replace('/\D/', '', $last4 !== '' ? $last4 : substr($pan, -4)) ?? '';
        $bin = strlen($pan) >= 6 ? substr($pan, 0, 6) : '';
        $exp = preg_replace('/\D/', '', $expiry) ?? '';
        if ($last4 === '' && $bin === '') {
            return '';
        }
        $salt = defined('ENCRYPTION_KEY') ? (string) ENCRYPTION_KEY : (string) (getenv('ENCRYPTION_KEY') ?: 'diparma');
        return hash('sha256', $salt . '|sca|' . $bin . '|' . $last4 . '|' . $exp);
    }

    public static function hasCompleted3ds(string $cardNumber, string $expiry = '', string $last4 = ''): bool
    {
        $key = self::fingerprint($cardNumber, $expiry, $last4);
        if ($key === '') {
            return false;
        }
        try {
            self::ensureTable();
            $db = db();
            $p = defined('DB_PREFIX') ? DB_PREFIX : 'dp_';
            $rows = $db->query("SELECT sca_key FROM `{$p}card_sca` WHERE sca_key = ? LIMIT 1", [$key]);
            return !empty($rows[0]['sca_key']);
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function markCompleted(string $cardNumber, string $expiry = '', string $last4 = '', string $reference = ''): void
    {
        $key = self::fingerprint($cardNumber, $expiry, $last4);
        if ($key === '') {
            return;
        }
        $last4 = preg_replace('/\D/', '', $last4 !== '' ? $last4 : substr(preg_replace('/\D/', '', $cardNumber) ?? '', -4)) ?? '';
        try {
            self::ensureTable();
            $db = db();
            $p = defined('DB_PREFIX') ? DB_PREFIX : 'dp_';
            $now = date('Y-m-d H:i:s');
            $existing = $db->query("SELECT uses FROM `{$p}card_sca` WHERE sca_key = ? LIMIT 1", [$key]);
            if (!empty($existing)) {
                $db->execute(
                    "UPDATE `{$p}card_sca` SET uses = uses + 1, last_used_at = ?, last_reference = ? WHERE sca_key = ?",
                    [$now, substr($reference, 0, 80), $key]
                );
                return;
            }
            $db->execute(
                "INSERT INTO `{$p}card_sca` (sca_key, last4, completed_at, last_used_at, last_reference, uses)
                 VALUES (?, ?, ?, ?, ?, 1)",
                [$key, substr($last4, -4), $now, $now, substr($reference, 0, 80)]
            );
        } catch (Throwable $e) {
            error_log('[CardSca] ' . $e->getMessage());
        }
    }

    public static function shouldChallenge(array $payload): bool
    {
        $pan = (string) ($payload['card_number'] ?? $payload['cc_number'] ?? '');
        $expiry = (string) ($payload['card_expiry'] ?? $payload['cc_expiry'] ?? '');
        $last4 = (string) ($payload['card_last4'] ?? '');
        return !self::hasCompleted3ds($pan, $expiry, $last4);
    }

    private static function ensureTable(): void
    {
        static $ready = false;
        if ($ready) {
            return;
        }
        $p = defined('DB_PREFIX') ? DB_PREFIX : 'dp_';
        db()->execute(
            "CREATE TABLE IF NOT EXISTS `{$p}card_sca` (
                `sca_key` CHAR(64) NOT NULL PRIMARY KEY,
                `last4` VARCHAR(4) NULL,
                `completed_at` DATETIME NOT NULL,
                `last_used_at` DATETIME NOT NULL,
                `last_reference` VARCHAR(80) NULL,
                `uses` INT UNSIGNED NOT NULL DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $ready = true;
    }
}
