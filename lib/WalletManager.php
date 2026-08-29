<?php
/**
 * DI PARMA | WalletManager
 * إدارة محافظ الفيات والكريبتو لكل مستخدم
 */
class WalletManager {
    private static ?self $instance = null;
    private $db;

    // عمولة الشركة الافتراضية
    const FEE_PCT       = 1.5;  // عمولة الإيداع والسحب
    const FEE_CONVERT   = 70.0; // عمولة التحويل فيات → كريبتو

    // العملات المدعومة
    const FIAT_CURRENCIES = ['USD','AED','EUR','SAR','GBP'];
    const CRYPTO_COINS = [
        'USDT' => ['TRC20','ERC20','BEP20'],
        'BTC'  => ['BTC'],
        'ETH'  => ['ERC20'],
        'BNB'  => ['BEP20'],
        'USDC' => ['ERC20','BEP20'],
    ];

    private function __construct() {
        $this->db = db();
    }

    public static function getInstance(): self {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    // ── إنشاء محافظ لمستخدم جديد ─────────────────────────
    public function createWalletsForUser(int $userId): void {
        // فيات — USD و AED افتراضي
        foreach (['USD','AED'] as $cur) {
            try {
                $this->db->execute(
                    "INSERT IGNORE INTO user_fiat_wallets (user_id,currency) VALUES (?,?)",
                    [$userId, $cur]
                );
            } catch(Exception $e) {}
        }
        // كريبتو
        foreach (self::CRYPTO_COINS as $coin => $networks) {
            foreach ($networks as $net) {
                try {
                    $this->db->execute(
                        "INSERT IGNORE INTO user_crypto_wallets (user_id,coin,network) VALUES (?,?,?)",
                        [$userId, $coin, $net]
                    );
                } catch(Exception $e) {}
            }
        }
    }

    // ── جلب محافظ المستخدم ────────────────────────────────
    public function getFiatWallets(int $userId): array {
        return $this->db->query(
            "SELECT * FROM user_fiat_wallets WHERE user_id=? ORDER BY currency",
            [$userId]
        ) ?: [];
    }

    public function getCryptoWallets(int $userId): array {
        return $this->db->query(
            "SELECT * FROM user_crypto_wallets WHERE user_id=? ORDER BY coin,network",
            [$userId]
        ) ?: [];
    }

    public function getFiatBalance(int $userId, string $currency='USD'): float {
        $rows = $this->db->query(
            "SELECT balance FROM user_fiat_wallets WHERE user_id=? AND currency=?",
            [$userId, $currency]
        );
        return (float)(($rows[0] ?? [])['balance'] ?? 0);
    }

    public function getCryptoBalance(int $userId, string $coin='USDT', string $network='TRC20'): float {
        $rows = $this->db->query(
            "SELECT balance FROM user_crypto_wallets WHERE user_id=? AND coin=? AND network=?",
            [$userId, $coin, $network]
        );
        return (float)(($rows[0] ?? [])['balance'] ?? 0);
    }

    // ── إيداع فيات (بعد نجاح الدفع) ─────────────────────
    public function depositFiat(int $userId, float $amount, string $currency, string $gateway, string $gatewayRef=''): array {
        if ($amount <= 0) return ['success'=>false,'message'=>'مبلغ غير صالح'];

        $fee    = round($amount * self::FEE_PCT / 100, 4);
        $net    = round($amount - $fee, 4);
        $ref    = $this->genRef('DEP');

        $this->db->beginTransaction();
        try {
            // إضافة للمحفظة أو تحديثها
            $existing = $this->db->query(
                "SELECT id FROM user_fiat_wallets WHERE user_id=? AND currency=?",
                [$userId, $currency]
            );
            if (!empty($existing)) {
                $this->db->execute(
                    "UPDATE user_fiat_wallets SET balance=balance+?, updated_at=NOW() WHERE user_id=? AND currency=?",
                    [$net, $userId, $currency]
                );
            } else {
                $this->db->execute(
                    "INSERT INTO user_fiat_wallets (user_id,currency,balance) VALUES (?,?,?)",
                    [$userId, $currency, $net]
                );
            }

            // عمولة الشركة
            $this->addCompanyFee($currency, $fee, 'fiat');

            // سجل الحركة
            $this->db->execute(
                "INSERT INTO wallet_transactions (reference,user_id,type,wallet_type,currency,amount,fee,net_amount,status,gateway,gateway_ref,note)
                 VALUES (?,?,'deposit','fiat',?,?,?,?,'completed',?,?,?)",
                [$ref,$userId,$currency,$amount,$fee,$net,$gateway,$gatewayRef,"إيداع عبر $gateway"]
            );

            $this->db->commit();
            return ['success'=>true,'reference'=>$ref,'net'=>$net,'fee'=>$fee];
        } catch(Exception $e) {
            $this->db->rollback();
            return ['success'=>false,'message'=>$e->getMessage()];
        }
    }

    // ── تحويل فيات → كريبتو (داخلي) ─────────────────────
    public function convertFiatToCrypto(int $userId, float $fiatAmount, string $fiatCurrency, string $coin, string $network): array {
        $balance = $this->getFiatBalance($userId, $fiatCurrency);
        if ($balance < $fiatAmount) return ['success'=>false,'message'=>"رصيد غير كافٍ — المتاح: $balance $fiatCurrency"];

        // سعر الصرف
        $rate = $this->getRate($coin, $fiatCurrency);
        if (!$rate) return ['success'=>false,'message'=>'تعذّر جلب سعر الصرف'];

        $fee         = round($fiatAmount * self::FEE_CONVERT / 100, 4);
        $netFiat     = $fiatAmount - $fee;
        $cryptoAmt   = round($netFiat / $rate, 8);
        $ref         = $this->genRef('CNV');

        $this->db->beginTransaction();
        try {
            // خصم من فيات
            $this->db->execute(
                "UPDATE user_fiat_wallets SET balance=balance-? WHERE user_id=? AND currency=? AND balance>=?",
                [$fiatAmount,$userId,$fiatCurrency,$fiatAmount]
            );
            $check = $this->db->query(
                "SELECT balance FROM user_fiat_wallets WHERE user_id=? AND currency=?",
                [$userId,$fiatCurrency]
            );
            // تحقق من نجاح الخصم
            if (empty($check)) throw new Exception('رصيد غير كافٍ');

            // إضافة للكريبتو
            $existing = $this->db->query(
                "SELECT id FROM user_crypto_wallets WHERE user_id=? AND coin=? AND network=?",
                [$userId,$coin,$network]
            );
            if (!empty($existing)) {
                $this->db->execute(
                    "UPDATE user_crypto_wallets SET balance=balance+? WHERE user_id=? AND coin=? AND network=?",
                    [$cryptoAmt,$userId,$coin,$network]
                );
            } else {
                $this->db->execute(
                    "INSERT INTO user_crypto_wallets (user_id,coin,network,balance) VALUES (?,?,?,?)",
                    [$userId,$coin,$network,$cryptoAmt]
                );
            }

            // عمولة الشركة
            $this->addCompanyFee($fiatCurrency, $fee, 'fiat');

            // سجل الحركة
            $this->db->execute(
                "INSERT INTO wallet_transactions (reference,user_id,type,wallet_type,coin,network,currency,amount,fee,net_amount,rate,from_wallet,to_wallet,status,note)
                 VALUES (?,?,'convert','crypto',?,?,?,?,?,?,?,'fiat','crypto','completed',?)",
                [$ref,$userId,$coin,$network,$fiatCurrency,$fiatAmount,$fee,$cryptoAmt,$rate,
                 "تحويل $fiatAmount $fiatCurrency → $cryptoAmt $coin/$network"]
            );

            $this->db->commit();
            return ['success'=>true,'reference'=>$ref,'crypto_amount'=>$cryptoAmt,'fee'=>$fee,'rate'=>$rate];
        } catch(Exception $e) {
            $this->db->rollback();
            return ['success'=>false,'message'=>$e->getMessage()];
        }
    }

    // ── سحب كريبتو خارجي ─────────────────────────────────
    public function withdrawCrypto(int $userId, float $amount, string $coin, string $network, string $toAddress, bool $skipLock=false): array {
        if (empty($toAddress)) return ['success'=>false,'message'=>'عنوان المحفظة مطلوب'];
        $balance = $this->getCryptoBalance($userId, $coin, $network);
        if ($balance < $amount) return ['success'=>false,'message'=>"رصيد غير كافٍ — المتاح: $balance $coin"];

        // ── تحقق من قفل 24 ساعة (يُتجاوز للإدارة) ──────────
        if (!$skipLock) {
            $walletRow = $this->db->query(
                "SELECT unlock_at FROM user_crypto_wallets WHERE user_id=? AND coin=? AND network=?",
                [$userId, $coin, $network]
            );
            $unlockAt = $walletRow[0]['unlock_at'] ?? null;
            if ($unlockAt && strtotime($unlockAt) > time()) {
                $remaining = ceil((strtotime($unlockAt) - time()) / 3600);
                return ['success'=>false,'message'=>"السحب مقفل — متاح بعد {$remaining} ساعة (في " . date('Y-m-d H:i', strtotime($unlockAt)) . ")"];
            }
        }

        $fee  = $coin === 'USDT' && $network === 'TRC20' ? 1.0 : ($coin === 'BTC' ? 0.0001 : 0.005);
        $net  = $amount - $fee;
        if ($net <= 0) return ['success'=>false,'message'=>'المبلغ أقل من رسوم الشبكة'];
        $ref  = $this->genRef('WDR');

        $this->db->beginTransaction();
        try {
            // تجميد المبلغ
            $this->db->execute(
                "UPDATE user_crypto_wallets SET balance=balance-?,locked=locked+? WHERE user_id=? AND coin=? AND network=? AND balance>=?",
                [$amount,$amount,$userId,$coin,$network,$amount]
            );
            // تحقق
            $chk = $this->db->query(
                "SELECT balance FROM user_crypto_wallets WHERE user_id=? AND coin=? AND network=?",
                [$userId,$coin,$network]
            );
            if (empty($chk)) throw new Exception('رصيد غير كافٍ');

            // سجل بحالة pending
            $this->db->execute(
                "INSERT INTO wallet_transactions (reference,user_id,type,wallet_type,coin,network,amount,fee,net_amount,to_address,status,note)
                 VALUES (?,?,'withdraw','crypto',?,?,?,?,?,?,'pending',?)",
                [$ref,$userId,$coin,$network,$amount,$fee,$net,$toAddress,"سحب $amount $coin/$network → $toAddress"]
            );

            $this->db->commit();

            // إرسال فعلي عبر HotWalletService
            require_once __DIR__ . '/HotWalletService.php';
            $hw = HotWalletService::getInstance();
            $txResult = $hw->sendUSDT($ref, $toAddress, $net, $userId);

            if ($txResult['success']) {
                $this->db->execute(
                    "UPDATE user_crypto_wallets SET locked=locked-? WHERE user_id=? AND coin=? AND network=?",
                    [$amount,$userId,$coin,$network]
                );
                $this->db->execute(
                    "UPDATE wallet_transactions SET status='completed',tx_hash=? WHERE reference=?",
                    [$txResult['tx_hash'],$ref]
                );
                $this->addCompanyFee($coin, $fee, 'crypto', $network);
            } else {
                // استرداد المبلغ عند الفشل
                $this->db->execute(
                    "UPDATE user_crypto_wallets SET balance=balance+?,locked=locked-? WHERE user_id=? AND coin=? AND network=?",
                    [$amount,$amount,$userId,$coin,$network]
                );
                $this->db->execute(
                    "UPDATE wallet_transactions SET status='failed',note=? WHERE reference=?",
                    ['فشل الإرسال: ' . $txResult['message'], $ref]
                );
                return ['success'=>false,'message'=>$txResult['message'],'reference'=>$ref];
            }

            return ['success'=>true,'reference'=>$ref,'tx_hash'=>$txResult['tx_hash'],'net'=>$net,'fee'=>$fee];
        } catch(Exception $e) {
            $this->db->rollback();
            return ['success'=>false,'message'=>$e->getMessage()];
        }
    }

    // ── عمولة الشركة ─────────────────────────────────────
    private function addCompanyFee(string $currency, float $amount, string $type, string $network=''): void {
        try {
            $existing = $this->db->query(
                "SELECT id FROM company_wallets WHERE wallet_type=? AND currency=? AND (network=? OR network IS NULL)",
                [$type,$currency,$network]
            );
            if (!empty($existing)) {
                $this->db->execute(
                    "UPDATE company_wallets SET balance=balance+?,total_received=total_received+? WHERE wallet_type=? AND currency=?",
                    [$amount,$amount,$type,$currency]
                );
            } else {
                $this->db->execute(
                    "INSERT INTO company_wallets (wallet_type,currency,network,balance,total_received) VALUES (?,?,?,?,?)",
                    [$type,$currency,$network,$amount,$amount]
                );
            }
        } catch(Exception $e) {}
    }

    // ── سعر الصرف ────────────────────────────────────────
    public function getRate(string $coin, string $fiat='USD'): float {
        try {
            require_once __DIR__ . '/ExchangeRateService.php';
            $calc = ExchangeRateService::getInstance()->calculate(1, $fiat, $coin);
            return (float)($calc['rate'] ?? 0);
        } catch(Exception $e) {
            // fallback أسعار تقريبية
            $rates = ['USDT'=>1.0,'BTC'=>60000,'ETH'=>3000,'BNB'=>300,'USDC'=>1.0];
            return $rates[$coin] ?? 1;
        }
    }

    // ── مساعدات ───────────────────────────────────────────
    private function genRef(string $prefix): string {
        return $prefix . date('Ymd') . strtoupper(substr(bin2hex(random_bytes(4)),0,8));
    }

    public function getSummary(int $userId): array {
        $fiats   = $this->getFiatWallets($userId);
        $cryptos = $this->getCryptoWallets($userId);
        $txns    = $this->db->query(
            "SELECT * FROM wallet_transactions WHERE user_id=? ORDER BY created_at DESC LIMIT 10",
            [$userId]
        ) ?: [];
        return ['fiat'=>$fiats,'crypto'=>$cryptos,'recent'=>$txns];
    }

    private function ensureTables(): void {
        // الجداول تُنشأ مسبقاً عبر db_wallets.sql على السيرفر
        // لا نقرأ الملف هنا تجنباً لأخطاء المسار
    }
}
