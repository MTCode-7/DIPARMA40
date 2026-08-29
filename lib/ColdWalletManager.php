<?php
/**
 * ============================================================
 * DI PARMA | ColdWalletManager
 * إدارة Cold Wallet — تنبيهات + منطق التحويل Hot/Cold
 * ============================================================
 */

class ColdWalletManager
{
    // نسب التوزيع المثالية
    const HOT_TARGET_PCT  = 5;   // 5% من الإجمالي في Hot
    const COLD_TARGET_PCT = 95;  // 95% في Cold

    // حدود التنبيه
    const ALERT_HOT_MIN_USDT  = 500;   // تنبيه إذا < 500 USDT
    const ALERT_HOT_CRIT_USDT = 100;   // حرج إذا < 100 USDT

    private static ?self $instance = null;
    private Database $db;
    private string $logFile;

    private function __construct()
    {
        $this->db      = db();
        $this->logFile = defined('LOGS_PATH') ? LOGS_PATH . '/cold_wallet.log' : __DIR__ . '/../logs/cold_wallet.log';
        if (!is_dir(dirname($this->logFile))) @mkdir(dirname($this->logFile), 0755, true);
    }

    public static function getInstance(): self
    {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    // ── الحالة الكاملة ───────────────────────────────────────

    public function getStatus(): array
    {
        $rows = $this->db->query(
            "SELECT * FROM dp_treasury_balances ORDER BY coin, network"
        );

        $summary = [];
        foreach ($rows as $row) {
            $hot      = (float)$row['hot_balance'];
            $cold     = (float)$row['cold_balance'];
            $reserved = (float)$row['reserved'];
            $total    = $hot + $cold;
            $available= $hot - $reserved;
            $hotPct   = $total > 0 ? round(($hot / $total) * 100, 1) : 0;

            $alert = 'ok';
            if ($available < self::ALERT_HOT_CRIT_USDT) $alert = 'critical';
            elseif ($available < self::ALERT_HOT_MIN_USDT) $alert = 'warning';

            $summary[] = [
                'coin'           => $row['coin'],
                'network'        => $row['network'],
                'hot_balance'    => $hot,
                'cold_balance'   => $cold,
                'reserved'       => $reserved,
                'available'      => $available,
                'total'          => $total,
                'hot_pct'        => $hotPct,
                'alert'          => $alert,
                'min_hot'        => (float)$row['min_hot'],
                'updated_at'     => $row['updated_at'],
                'needs_refill'   => $available < (float)$row['min_hot'],
            ];
        }

        return $summary;
    }

    // ── تشغيل فحص دوري ──────────────────────────────────────

    public function runCheck(): array
    {
        $statuses = $this->getStatus();
        $alerts   = [];

        foreach ($statuses as $status) {
            if ($status['alert'] !== 'ok') {
                $alerts[] = $status;
                $this->handleAlert($status);
            }
        }

        $this->log("فحص Treasury: " . count($statuses) . " عملة | تنبيهات: " . count($alerts));
        return ['checked' => count($statuses), 'alerts' => count($alerts), 'details' => $alerts];
    }

    private function handleAlert(array $status): void
    {
        $coin    = $status['coin'];
        $network = $status['network'];
        $avail   = $status['available'];
        $level   = $status['alert'];

        $this->log("⚠ [$level] $coin/$network — متاح: $avail USDT");

        // تسجيل في event_log
        try {
            $this->db->insert('event_log', [
                'event_type' => 'treasury.low_balance',
                'reference'  => null,
                'user_id'    => null,
                'payload'    => json_encode([
                    'coin'      => $coin,
                    'network'   => $network,
                    'balance'   => $avail,
                    'alert'     => $level,
                    'minimum'   => $status['min_hot'],
                ], JSON_UNESCAPED_UNICODE),
                'processed'  => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Exception $e) {}
    }

    // ── تسجيل إيداع Cold Wallet يدوي ────────────────────────

    /**
     * يُستدعى عندما يقوم الأدمن بتعبئة Hot Wallet من Cold
     */
    public function recordTransfer(
        string $direction,  // hot_to_cold | cold_to_hot
        float  $amount,
        string $coin,
        string $network,
        string $txHash = ''
    ): array {
        try {
            $row = $this->db->find('treasury_balances', ['coin' => $coin, 'network' => $network]);
            if (!$row) return ['success' => false, 'message' => "لا توجد بيانات لـ $coin/$network"];

            $hot  = (float)$row['hot_balance'];
            $cold = (float)$row['cold_balance'];

            if ($direction === 'cold_to_hot') {
                if ($cold < $amount) return ['success' => false, 'message' => 'رصيد Cold غير كافٍ'];
                $newHot  = $hot  + $amount;
                $newCold = $cold - $amount;
            } else {
                if ($hot < $amount) return ['success' => false, 'message' => 'رصيد Hot غير كافٍ'];
                $newHot  = $hot  - $amount;
                $newCold = $cold + $amount;
            }

            $this->db->update('treasury_balances', [
                'hot_balance'  => $newHot,
                'cold_balance' => $newCold,
                'updated_at'   => date('Y-m-d H:i:s'),
            ], ['coin' => $coin, 'network' => $network]);

            // تسجيل في blockchain_txns
            if ($txHash) {
                $this->db->insert('blockchain_txns', [
                    'reference'     => 'TREASURY-' . date('YmdHis'),
                    'network'       => $network,
                    'coin'          => $coin,
                    'tx_hash'       => $txHash,
                    'from_address'  => $direction === 'cold_to_hot' ? 'cold_wallet' : 'hot_wallet',
                    'to_address'    => $direction === 'cold_to_hot' ? 'hot_wallet'  : 'cold_wallet',
                    'amount'        => $amount,
                    'fee'           => 0,
                    'confirmations' => 20,
                    'required_conf' => 20,
                    'direction'     => 'internal',
                    'status'        => 'confirmed',
                    'created_at'    => date('Y-m-d H:i:s'),
                    'confirmed_at'  => date('Y-m-d H:i:s'),
                ]);
            }

            $this->log("✓ تحويل $direction: $amount $coin/$network | Hot=$newHot Cold=$newCold");

            return [
                'success'     => true,
                'direction'   => $direction,
                'amount'      => $amount,
                'new_hot'     => $newHot,
                'new_cold'    => $newCold,
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ── تحديث الرصيد الكلي ──────────────────────────────────

    public function updateColdBalance(string $coin, string $network, float $amount): void
    {
        $this->db->execute(
            "INSERT INTO dp_treasury_balances (coin, network, hot_balance, cold_balance, reserved, updated_at)
             VALUES (?, ?, 0, ?, 0, ?)
             ON DUPLICATE KEY UPDATE cold_balance = ?, updated_at = ?",
            [$coin, $network, $amount, date('Y-m-d H:i:s'), $amount, date('Y-m-d H:i:s')]
        );
    }

    private function log(string $msg): void
    {
        @file_put_contents($this->logFile, '[' . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
    }
}
