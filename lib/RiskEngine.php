<?php
/**
 * ============================================================
 * DI PARMA | RiskEngine
 * فحص المخاطر لكل عملية: IP / Geo / Velocity / Blacklist / AML
 * ============================================================
 */

class RiskEngine
{
    // درجة المخاطر
    const SCORE_LOW    = 0;
    const SCORE_MEDIUM = 40;
    const SCORE_HIGH   = 70;
    const SCORE_BLOCK  = 90;

    // حدود Velocity (عدد العمليات)
    const VELOCITY_PER_HOUR   = PHP_INT_MAX;
    const VELOCITY_PER_DAY    = PHP_INT_MAX;
    const VELOCITY_AMOUNT_DAY = PHP_INT_MAX; // بلا حدود

    // دول مقيّدة (OFAC + FATF High Risk)
    const BLOCKED_COUNTRIES = [
        'IR','CU','KP','SY','RU','BY','MM','VE','SD','SO','LY','IQ','AF','YE','ZW'
    ];

    private static ?self $instance = null;
    private Database $db;
    private string $logFile;

    private function __construct()
    {
        $this->db      = db();
        $this->logFile = defined('LOGS_PATH') ? LOGS_PATH . '/risk.log' : __DIR__ . '/../logs/risk.log';
        if (!is_dir(dirname($this->logFile))) @mkdir(dirname($this->logFile), 0755, true);
    }

    public static function getInstance(): self
    {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    // ── الفحص الرئيسي ────────────────────────────────────────

    /**
     * فحص عملية كاملة وإرجاع قرار
     * @return array ['decision'=>'approve|review|reject', 'score'=>int, 'reasons'=>[]]
     */
    public function evaluate(array $context): array
    {
        $score   = 0;
        $reasons = [];

        $userId  = intval($context['user_id']   ?? 0);
        $amount  = floatval($context['amount']  ?? 0);
        $ip      = $context['ip']               ?? getClientIP();
        $email   = $context['email']            ?? '';
        $country = $context['country']          ?? '';
        $wallet  = $context['wallet_address']   ?? '';

        // [1] فحص Blacklist
        $bl = $this->checkBlacklist($ip, $email, $wallet);
        if ($bl) { $score += 100; $reasons[] = 'blacklist: ' . $bl; }

        // [2] فحص الدولة
        $geoScore = $this->checkGeo($ip, $country);
        $score += $geoScore['score'];
        if ($geoScore['reason']) $reasons[] = $geoScore['reason'];

        // [3] فحص Velocity
        if ($userId > 0) {
            $vel = $this->checkVelocity($userId, $amount);
            $score += $vel['score'];
            if ($vel['reason']) $reasons[] = $vel['reason'];
        }

        // [4] فحص المبلغ
        $amtScore = $this->checkAmount($amount, $userId);
        $score += $amtScore['score'];
        if ($amtScore['reason']) $reasons[] = $amtScore['reason'];

        // [5] فحص عنوان المحفظة
        if (!empty($wallet)) {
            $walScore = $this->checkWalletAddress($wallet);
            $score += $walScore['score'];
            if ($walScore['reason']) $reasons[] = $walScore['reason'];
        }

        // [6] فحص KYC Level
        $kycScore = $this->checkKYCLevel($userId, $amount);
        $score += $kycScore['score'];
        if ($kycScore['reason']) $reasons[] = $kycScore['reason'];

        // القرار
        $decision = 'approve';
        if ($score >= self::SCORE_BLOCK)  $decision = 'reject';
        elseif ($score >= self::SCORE_HIGH) $decision = 'review';
        elseif ($score >= self::SCORE_MEDIUM) $decision = 'review';

        // تسجيل
        $this->logRisk($userId, $score, $decision, $reasons, $context);

        return [
            'decision' => $decision,
            'score'    => min($score, 100),
            'reasons'  => $reasons,
            'approved' => $decision === 'approve',
        ];
    }

    // ── فحوصات فردية ────────────────────────────────────────

    private function checkBlacklist(string $ip, string $email, string $wallet): ?string
    {
        try {
            // فحص IP
            $r = $this->db->query(
                "SELECT reason FROM dp_risk_blacklist WHERE type='ip' AND value=? AND active=1 LIMIT 1",
                [$ip]
            );
            if (!empty($r)) return 'IP محظور: ' . $r[0]['reason'];

            // فحص Email
            if ($email) {
                $r = $this->db->query(
                    "SELECT reason FROM dp_risk_blacklist WHERE type='email' AND value=? AND active=1 LIMIT 1",
                    [strtolower($email)]
                );
                if (!empty($r)) return 'Email محظور';
            }

            // فحص Wallet
            if ($wallet) {
                $r = $this->db->query(
                    "SELECT reason FROM dp_risk_blacklist WHERE type='wallet' AND value=? AND active=1 LIMIT 1",
                    [$wallet]
                );
                if (!empty($r)) return 'محفظة محظورة';
            }
        } catch (Exception $e) {}
        return null;
    }

    private function checkGeo(string $ip, string $country): array
    {
        // فحص IP محلي أولاً
        if (in_array($ip, ['127.0.0.1', '::1', 'localhost'])) {
            return ['score' => 0, 'reason' => null];
        }

        // جلب الدولة من IP إذا لم تُعطَ
        if (empty($country)) {
            $country = $this->getCountryFromIP($ip);
        }

        if (in_array(strtoupper($country), self::BLOCKED_COUNTRIES)) {
            return ['score' => 100, 'reason' => "دولة مقيّدة: $country"];
        }

        return ['score' => 0, 'reason' => null];
    }

    private function checkVelocity(int $userId, float $amount): array
    {
        $score  = 0;
        $reason = null;

        try {
            // عدد العمليات آخر ساعة
            $hourly = $this->db->query(
                "SELECT COUNT(*) as c FROM dp_transactions
                 WHERE user_id=? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
                [$userId]
            );
            $hourlyCount = (int)($hourly[0]['c'] ?? 0);
            // بلا حدود — تم تعطيل فحص Velocity
            // if ($hourlyCount >= self::VELOCITY_PER_HOUR) { ... }

            $daily = $this->db->query(
                "SELECT COUNT(*) as c, COALESCE(SUM(amount),0) as total FROM dp_transactions
                 WHERE user_id=? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                   AND status IN ('completed','processing')",
                [$userId]
            );
            // بلا حدود يومية — تم تعطيل فحص المبلغ اليومي
        } catch (Exception $e) {}

        return ['score' => $score, 'reason' => $reason];
    }

    private function checkAmount(float $amount, int $userId): array
    {
        // بلا حدود — تم تعطيل فحص المبلغ
        if ($amount <= 0) return ['score' => 50, 'reason' => 'مبلغ غير صالح'];
        return ['score' => 0, 'reason' => null];
    }

    private function checkWalletAddress(string $wallet): array
    {
        // تحقق أساسي من صحة التنسيق
        $isTRC20 = preg_match('/^T[A-Za-z0-9]{33}$/', $wallet);
        $isERC20 = preg_match('/^0x[a-fA-F0-9]{40}$/', $wallet);

        if (!$isTRC20 && !$isERC20) {
            return ['score' => 30, 'reason' => 'عنوان محفظة غير صالح'];
        }
        return ['score' => 0, 'reason' => null];
    }

    private function checkKYCLevel(int $userId, float $amount): array
    {
        // بلا حدود — تم تعطيل فحص KYC للمبلغ
        return ['score' => 0, 'reason' => null];
    }

    private function getCountryFromIP(string $ip): string
    {
        // استخدام ip-api.com (مجاني، 45 طلب/دقيقة)
        try {
            $url = "http://ip-api.com/json/{$ip}?fields=countryCode";
            $ch  = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 3,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $res  = curl_exec($ch);
            curl_close($ch);
            $data = json_decode($res, true);
            return $data['countryCode'] ?? '';
        } catch (Exception $e) {
            return '';
        }
    }

    // ── إنشاء جداول Risk ────────────────────────────────────

    public static function ensureTables(): void
    {
        $db = db();
        $db->execute("CREATE TABLE IF NOT EXISTS `dp_risk_blacklist` (
            `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `type`       VARCHAR(20) NOT NULL COMMENT 'ip|email|wallet|card_bin',
            `value`      VARCHAR(255) NOT NULL,
            `reason`     VARCHAR(255) DEFAULT NULL,
            `active`     TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL,
            INDEX `idx_type_value` (`type`, `value`(100))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->execute("CREATE TABLE IF NOT EXISTS `dp_risk_logs` (
            `id`         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id`    INT UNSIGNED DEFAULT NULL,
            `ip`         VARCHAR(45) DEFAULT NULL,
            `score`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `decision`   VARCHAR(20) NOT NULL,
            `reasons`    TEXT DEFAULT NULL,
            `context`    MEDIUMTEXT DEFAULT NULL,
            `created_at` DATETIME NOT NULL,
            INDEX `idx_user`     (`user_id`),
            INDEX `idx_decision` (`decision`),
            INDEX `idx_created`  (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    private function logRisk(int $userId, int $score, string $decision, array $reasons, array $context): void
    {
        try {
            $this->db->insert('risk_logs', [
                'user_id'    => $userId ?: null,
                'ip'         => $context['ip'] ?? getClientIP(),
                'score'      => $score,
                'decision'   => $decision,
                'reasons'    => implode(' | ', $reasons),
                'context'    => json_encode($context, JSON_UNESCAPED_UNICODE),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Exception $e) {}
    }
}
