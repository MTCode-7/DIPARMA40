<?php
/**
 * ============================================================
 * DI PARMA | CryptoGateway
 * ربط خدمات Crypto مع gateway_service الموجود
 * ============================================================
 * يُعالج عمليتين:
 *   1. buy_crypto  — المستخدم يدفع فيات ويستقبل USDT
 *   2. sell_crypto — المستخدم يرسل USDT ويستقبل فيات
 * ============================================================
 */

require_once __DIR__ . '/ExchangeRateService.php';
require_once __DIR__ . '/WalletService.php';
require_once __DIR__ . '/HotWalletService.php';
require_once __DIR__ . '/BlockchainMonitor.php';
require_once __DIR__ . '/../includes/crypto_schema.php';

class CryptoGateway
{
    private static ?self $instance = null;
    private Database $db;
    private ExchangeRateService $fxService;
    private WalletService $walletService;
    private HotWalletService $hotWallet;

    private function __construct()
    {
        $this->db            = db();
        $this->fxService     = ExchangeRateService::getInstance();
        $this->walletService = WalletService::getInstance();
        $this->hotWallet     = HotWalletService::getInstance();

        // إنشاء الجداول إن لم تكن موجودة
        dp_create_crypto_tables();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ══════════════════════════════════════════════════════════
    // [1] شراء Crypto بالفيات (buy_crypto)
    // ══════════════════════════════════════════════════════════

    /**
     * الخطوة 1: المستخدم يطلب شراء USDT
     * يُعيد: بيانات الدفع + عنوان استقبال USDT
     */
    public function initBuyCrypto(array $payload): array
    {
        $userId     = intval($_SESSION['user_id'] ?? 0);
        $fiatAmount = round(floatval($payload['amount'] ?? 0), 2);
        $fiat       = strtoupper(trim($payload['currency'] ?? 'AED'));
        $coin       = strtoupper(trim($payload['crypto'] ?? 'USDT'));
        $network    = strtoupper(trim($payload['network'] ?? 'TRC20'));
        $toAddress  = trim($payload['wallet_address'] ?? '');
        $gateway    = strtolower(trim($payload['payment_gateway'] ?? 'myfatoorah'));
        $reference  = generateReference('CRYPTO');

        if ($fiatAmount <= 0) {
            return ['success' => false, 'message' => 'المبلغ غير صالح'];
        }
        if (empty($toAddress)) {
            return ['success' => false, 'message' => 'عنوان المحفظة مطلوب'];
        }

        // [1] حساب المبلغ
        try {
            $calc = $this->fxService->calculate($fiatAmount, $fiat, $coin);
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => 'فشل جلب سعر الصرف: ' . $e->getMessage()];
        }

        // [2] تسجيل المعاملة كـ pending
        $txnId = $this->db->insert('transactions', [
            'reference'        => $reference,
            'gateway'          => $gateway,
            'amount'           => $fiatAmount,
            'currency'         => $fiat,
            'customer_name'    => $payload['customer_name'] ?? '',
            'customer_email'   => $payload['customer_email'] ?? '',
            'customer_phone'   => $payload['customer_phone'] ?? '',
            'status'           => 'pending',
            'transaction_type' => "شراء {$calc['crypto_amount']} {$coin}/{$network}",
            'user_id'          => $userId,
            'fees'             => $calc['fee_fiat'],
            'net_amount'       => $calc['net_fiat'],
            'security_mode'    => '2D',
            'gateway_response' => json_encode([
                'type'          => 'buy_crypto',
                'coin'          => $coin,
                'network'       => $network,
                'crypto_amount' => $calc['crypto_amount'],
                'to_address'    => $toAddress,
                'rate'          => $calc['final_rate'],
                'margin_pct'    => $calc['margin_pct'],
            ]),
            'created_at'       => date('Y-m-d H:i:s'),
        ]);

        return [
            'success'       => true,
            'reference'     => $reference,
            'transaction_id'=> $txnId,
            'fiat_amount'   => $fiatAmount,
            'fiat_currency' => $fiat,
            'crypto_amount' => $calc['crypto_amount'],
            'coin'          => $coin,
            'network'       => $network,
            'to_address'    => $toAddress,
            'rate'          => $calc['final_rate'],
            'fee_fiat'      => $calc['fee_fiat'],
            'payment_gateway' => $gateway,
            'message'       => 'أكمل الدفع عبر ' . $gateway,
            'next_step'     => 'complete_fiat_payment',
        ];
    }

    /**
     * الخطوة 2: بعد تأكيد الدفع الفيات → إرسال USDT
     * يُستدعى من Webhook أو يدوياً
     */
    public function onFiatPaymentConfirmed(string $reference): array
    {
        $txn = $this->db->find('transactions', ['reference' => $reference]);
        if (!$txn) {
            return ['success' => false, 'message' => 'المعاملة غير موجودة'];
        }

        if ($txn['status'] === 'completed') {
            return ['success' => true, 'message' => 'مكتملة مسبقاً'];
        }

        // استخراج بيانات Crypto من gateway_response
        $gwData = json_decode($txn['gateway_response'] ?? '{}', true);
        if (empty($gwData['to_address']) || empty($gwData['crypto_amount'])) {
            return ['success' => false, 'message' => 'بيانات Crypto مفقودة في المعاملة'];
        }

        $toAddress    = $gwData['to_address'];
        $cryptoAmount = (float)$gwData['crypto_amount'];
        $userId       = (int)$txn['user_id'];

        // إرسال USDT
        $result = $this->hotWallet->sendUSDT($reference, $toAddress, $cryptoAmount, $userId);

        if ($result['success']) {
            $this->db->update('transactions', [
                'status'     => 'processing',
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['reference' => $reference]);
        } else {
            $this->db->update('transactions', [
                'status'        => 'failed',
                'error_message' => $result['message'],
                'updated_at'    => date('Y-m-d H:i:s'),
            ], ['reference' => $reference]);
        }

        return $result;
    }

    // ══════════════════════════════════════════════════════════
    // [2] بيع Crypto واستقبال فيات (sell_crypto)
    // ══════════════════════════════════════════════════════════

    /**
     * المستخدم يريد بيع USDT وأخذ AED
     * يُعيد: عنوان إيداع على Hot Wallet
     */
    public function initSellCrypto(array $payload): array
    {
        $userId       = intval($_SESSION['user_id'] ?? 0);
        $cryptoAmount = round(floatval($payload['crypto_amount'] ?? 0), 6);
        $coin         = strtoupper(trim($payload['crypto'] ?? 'USDT'));
        $network      = strtoupper(trim($payload['network'] ?? 'TRC20'));
        $fiat         = strtoupper(trim($payload['currency'] ?? 'AED'));
        $reference    = generateReference('SELL');

        if ($cryptoAmount <= 0) {
            return ['success' => false, 'message' => 'مبلغ Crypto غير صالح'];
        }

        // حساب المبلغ الفيات
        try {
            $rateData   = $this->fxService->getRate($coin, $fiat);
            $fiatAmount = round($cryptoAmount * $rateData['rate'], 2);
            $fee        = round($fiatAmount * ($rateData['margin_pct'] / 100), 2);
            $netFiat    = $fiatAmount - $fee;
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => 'فشل جلب سعر الصرف'];
        }

        // جلب عنوان Hot Wallet للاستقبال
        $depositAddress = getenv('HOT_WALLET_TRC20_ADDRESS') ?: '';
        if (empty($depositAddress)) {
            return ['success' => false, 'message' => 'Hot Wallet غير مضبوط'];
        }

        // تسجيل المعاملة
        $txnId = $this->db->insert('transactions', [
            'reference'        => $reference,
            'gateway'          => 'crypto_deposit',
            'amount'           => $netFiat,
            'currency'         => $fiat,
            'customer_name'    => $payload['customer_name'] ?? '',
            'customer_email'   => $payload['customer_email'] ?? '',
            'status'           => 'pending',
            'transaction_type' => "بيع {$cryptoAmount} {$coin}/{$network}",
            'user_id'          => $userId,
            'fees'             => $fee,
            'net_amount'       => $netFiat,
            'gateway_response' => json_encode([
                'type'           => 'sell_crypto',
                'coin'           => $coin,
                'network'        => $network,
                'crypto_amount'  => $cryptoAmount,
                'deposit_address'=> $depositAddress,
                'fiat_amount'    => $netFiat,
                'rate'           => $rateData['rate'],
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return [
            'success'         => true,
            'reference'       => $reference,
            'transaction_id'  => $txnId,
            'deposit_address' => $depositAddress,
            'network'         => $network,
            'coin'            => $coin,
            'expected_amount' => $cryptoAmount,
            'fiat_you_receive'=> $netFiat,
            'fiat_currency'   => $fiat,
            'rate'            => $rateData['rate'],
            'fee_fiat'        => $fee,
            'message'         => "أرسل {$cryptoAmount} {$coin} إلى العنوان أدناه",
            'explorer_prefix' => 'https://tronscan.org/#/address/' . $depositAddress,
        ];
    }

    // ══════════════════════════════════════════════════════════
    // [3] API Endpoints المساعدة
    // ══════════════════════════════════════════════════════════

    /** جلب سعر الصرف */
    public function getRate(string $coin, string $fiat = 'AED'): array
    {
        try {
            return array_merge(
                $this->fxService->getRate($coin, $fiat),
                ['success' => true]
            );
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /** جلب أو إنشاء محفظة المستخدم */
    public function getUserWallet(int $userId, string $network = 'TRC20', string $coin = 'USDT'): array
    {
        try {
            return array_merge(
                $this->walletService->getOrCreate($userId, $network, $coin),
                ['success' => true]
            );
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /** رصيد Hot Wallet */
    public function getHotBalance(): array
    {
        $balance = $this->hotWallet->getHotBalance();
        return [
            'success' => true,
            'balance' => $balance,
            'coin'    => 'USDT',
            'network' => 'TRC20',
        ];
    }

    /** تشغيل دورة المراقبة يدوياً */
    public function runMonitor(): array
    {
        $monitor = BlockchainMonitor::getInstance();
        return $monitor->run();
    }
}
