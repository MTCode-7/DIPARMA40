<?php
declare(strict_types=1);

/**
 * ============================================================
 * DI PARMA | ULTIMATE FINANCIAL GATEWAY - ENTERPRISE GOLD
 * ============================================================
 */

// [1] التكوين الأساسي
ini_set('memory_limit', '512M');   // خُفِّض من 4096M — كافٍ ومُحسَّن
ini_set('max_execution_time', '60');

// تضمين محرك الأداء
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/performance.php';
require_once __DIR__ . '/includes/db_optimized.php';


// [3] تعريف المسارات الأساسية
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', __DIR__);
}
if (!defined('CACHE_PATH')) {
    define('CACHE_PATH', ROOT_PATH . '/cache');
}
if (!defined('CONFIG_PATH')) {
    define('CONFIG_PATH', ROOT_PATH . '/config');
}
if (!defined('LIB_PATH')) {
    define('LIB_PATH', ROOT_PATH . '/lib');
}
if (!defined('LOGS_PATH')) {
    define('LOGS_PATH', ROOT_PATH . '/logs');
}
if (!defined('BACKUP_PATH')) {
    define('BACKUP_PATH', ROOT_PATH . '/backups');
}
if (!defined('ASSETS_PATH')) {
    define('ASSETS_PATH', ROOT_PATH . '/assets');
}
if (!defined('TEMP_PATH')) {
    define('TEMP_PATH', ROOT_PATH . '/tmp');
}
if (!defined('PROTOCOL_PATH')) {
    define('PROTOCOL_PATH', ROOT_PATH . '/protocols');
}

// [4] التحقق التلقائي من وجود المجلدات وإنشائها
$required_directories = [CACHE_PATH, CONFIG_PATH, LIB_PATH, LOGS_PATH, BACKUP_PATH, ASSETS_PATH, TEMP_PATH, PROTOCOL_PATH];
foreach ($required_directories as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// ============================================================
// [5] نظام الكاش الذكي
// ============================================================
class UltraCache {
    private static $instance = null;
    private $cache = [];
    private $hitCount = 0;
    private $missCount = 0;
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function get($key, $default = null) {
        if (isset($this->cache[$key])) {
            $this->hitCount++;
            return $this->cache[$key];
        }
        $this->missCount++;
        return $default;
    }
    
    public function set($key, $value, $ttl = 3600) {
        $this->cache[$key] = $value;
        return $this;
    }

    public function getStats() {
        return [
            'hits' => $this->hitCount,
            'misses' => $this->missCount,
            'keys_count' => count($this->cache),
            'status' => 'Optimal'
        ];
    }
}

// ============================================================
// [6] معالج فائق السرعة
// ============================================================
class UltraProcessor {
    private static $instance = null;
    private $registry = [];
    private $benchmark = [];
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function execute($operation, $params = []) {
        $start = microtime(true);
        if (!isset($this->registry[$operation])) {
            $this->registry[$operation] = $this->compile($operation);
        }
        $result = $this->registry[$operation]->execute($params);
        $this->benchmark[$operation] = microtime(true) - $start;
        return $result;
    }
    
    private function compile($operation) {
        return new class($operation) {
            private $op;
            public function __construct($op) { $this->op = $op; }
            public function execute($params) {
                return ['status' => 'success', 'operation' => $this->op, 'params' => $params];
            }
        };
    }
}

// ============================================================
// [7] نظام التشفير المتقدم
// ============================================================
class QuantumCipher {
    const ENCRYPTION_ALGO = 'aes-256-gcm';
    const KEY_SIZE = 32; 
    const IV_SIZE = 12;  
    
    public static function encrypt($data, $key = null) {
        if ($key === null) { 
            $key = self::generateKey(); 
        }
        $iv = random_bytes(self::IV_SIZE);
        $tag = ''; 
        $encrypted = openssl_encrypt(
            $data,
            self::ENCRYPTION_ALGO,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16 
        );
        return base64_encode($iv . $tag . $encrypted);
    }
    
    public static function decrypt($encrypted, $key = null) {
        if ($key === null) { 
            $key = self::generateKey(); 
        }
        $data = base64_decode($encrypted);
        
        $iv = substr($data, 0, self::IV_SIZE);
        $tag = substr($data, self::IV_SIZE, 16);
        $ciphertext = substr($data, self::IV_SIZE + 16);
        
        return openssl_decrypt(
            $ciphertext,
            self::ENCRYPTION_ALGO,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
    }
    
    public static function generateKey() {
        return hash('sha256', getenv('ENCRYPTION_KEY') ?: 'DI_PARMA_SECURE_KEY_2026', true);
    }
}

// ============================================================
// [8] بيئة تشغيل النظام والتحقق الأمني
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// الصفحة الرئيسية عامة؛ يحدد الرابط القادم من Dashboard أزرار الحساب.
$showAccountActions = !empty($_SESSION['user_id']) || ($_GET['account'] ?? '') === '1';

if (!function_exists('generateCsrfToken')) {
    function generateCsrfToken() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('verifyCsrfToken')) {
    function verifyCsrfToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

date_default_timezone_set('Asia/Dubai');
$cache = UltraCache::getInstance();
$processor = UltraProcessor::getInstance();

require_once ROOT_PATH . '/includes/config.php';
require_once ROOT_PATH . '/includes/database.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/gateways.php';

require_once ROOT_PATH . '/landing.php';
exit();

// عدد الإشعارات غير المقروءة للمستخدم
$db = db();
try {
    $db->execute("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "notifications` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT UNSIGNED NOT NULL,
        `title` VARCHAR(255) NOT NULL,
        `message` TEXT DEFAULT NULL,
        `read` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $hasReadColumn = $db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "notifications` LIKE 'read'");
    if (empty($hasReadColumn)) {
        $db->execute("ALTER TABLE `" . DB_PREFIX . "notifications` ADD COLUMN `read` TINYINT(1) NOT NULL DEFAULT 0 AFTER `message`");
    }

    $hasCreatedAt = $db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "notifications` LIKE 'created_at'");
    if (empty($hasCreatedAt)) {
        $db->execute("ALTER TABLE `" . DB_PREFIX . "notifications` ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `read`");
    }
} catch (Exception $e) {
    // ignore if table migration fails
}
$unreadNotifications = $db->query('SELECT COUNT(*) AS count FROM ' . DB_PREFIX . 'notifications WHERE user_id = ? AND `read` = 0', [$_SESSION['user_id']]);
$unreadCount = intval($unreadNotifications[0]['count'] ?? 0);

// ============================================================
// [9] معالج استدعاء وتشغيل البروتوكولات ديناميكياً - البروتوكولات المدعومة
// ============================================================
$supported_protocols = ['101.0', '101.1', '201.3'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['protocol_layer']) && !isset($_POST['execute_operation']) && !isset($_POST['gateway_code']) && !isset($_POST['amount'])) {
    $selected_protocol = trim($_POST['protocol_layer']); 
    
    // التحقق من أن البروتوكول مدعوم
    if (!in_array($selected_protocol, $supported_protocols, true)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Protocol not supported. Available protocols: ' . implode(', ', $supported_protocols)
        ]);
        exit();
    }
    
    // معالجة المدخلات للمطابقة مع اسم الملف
    $sanitized_protocol = str_replace('.', '_', $selected_protocol); 
    $protocol_file = PROTOCOL_PATH . "/protocol_" . $sanitized_protocol . ".php";
    
    if ($selected_protocol === '101.0') {
        $protocol_file = PROTOCOL_PATH . '/protocol_101_0.php';
    }

    if (file_exists($protocol_file)) {
        require_once $protocol_file;
        
        // تعيين دالة الـ Factory المناسبة للبروتوكول المستهدف
        $factory_function = '';
        if ($selected_protocol === '101.1') {
            $factory_function = 'create_auth_protocol_instance';
        } elseif ($selected_protocol === '101.0') {
            $factory_function = 'create_direct_withdrawal_protocol_instance';
        } elseif ($selected_protocol === '201.3') {
            $factory_function = 'create_settlement_protocol_instance';
        }
        
        if (!empty($factory_function) && function_exists($factory_function)) {
            $protocol_instance = $factory_function();
            
            // بناء سياق البيانات الممررة
            $actionValue = $_POST['action'] ?? 'HOLD';
            if ($selected_protocol === '101.0') {
                $actionValue = 'SETTLEMENT';
            }

            // نوع البطاقة المختار
            $cardTypeSelected = strtoupper(trim($_POST['card_type_selected'] ?? $_POST['card_type'] ?? 'LIVE'));
            $cardTypeMap = [
                'MASTERCARD' => 'LIVE',
                'VERVE'      => 'LIVE',
                'EFTPOS'     => 'LIVE',
                'PROXIMITY'  => 'NFC',
                'TPDU'       => 'TPDU',
            ];
            $normalizedCardType = $cardTypeMap[$cardTypeSelected] ?? $cardTypeSelected;

            $context = [
                'amount'                => $_POST['amount'] ?? 0,
                'currency'              => $_POST['currency'] ?? 'USD',
                'gateway_type'          => trim($_POST['gateway_type'] ?? $_POST['gateway_code'] ?? $_POST['payment_gateway'] ?? ''),
                'gateway_code'          => trim($_POST['gateway_code'] ?? $_POST['gateway_type'] ?? $_POST['payment_gateway'] ?? ''),
                'customer_name'         => trim($_POST['customer_name'] ?? 'VIP Client'),
                'customer_email'        => trim($_POST['customer_email'] ?? ''),
                'customer_phone'        => trim($_POST['customer_phone'] ?? ''),
                'otp_code'              => trim($_POST['otp_code'] ?? ''),
                'selected_protocol'     => $selected_protocol,
                'allow_otp_bypass'      => !empty($_POST['allow_otp_bypass']) ? true : false,
                'transaction_ref'       => 'DP_' . strtoupper(bin2hex(random_bytes(6))),
                'payment_method'        => trim($_POST['payment_method'] ?? 'card'),
                'source'                => trim($_POST['source'] ?? 'web'),
                'card_type'             => $cardTypeSelected,
                'normalized_card_type'  => $normalizedCardType,
                'card_pan'              => trim($_POST['card_pan'] ?? ''),
                'card_expiry'           => trim($_POST['card_expiry'] ?? ''),
                'card_cvv'              => trim($_POST['card_cvv'] ?? ''),
                'cloud_token'           => trim($_POST['cloud_token'] ?? ''),
                'apple_pay_token'       => trim($_POST['apple_pay_token'] ?? ''),
                'google_pay_token'      => trim($_POST['google_pay_token'] ?? ''),
                'payment_token'         => trim($_POST['payment_token'] ?? ''),

                // بروتوكول 101.1 - Authorization
                'action'                => $actionValue,
                'authorization_id'      => $_POST['authorization_id'] ?? null,
                'success_url'           => $_POST['success_url'] ?? '/payment_authorized.php',
                'cancel_url'            => $_POST['cancel_url'] ?? '/payment_cancelled.php',

                // بروتوكول 201.3 - Corporate Settlement
                'corporate_token'       => $_POST['corporate_token'] ?? null,
                'billing_agreement_id'  => $_POST['billing_agreement_id'] ?? null,
                'settlement_batch_id'   => $_POST['settlement_batch_id'] ?? null,
            ];

            // ── إذا كان نوع البطاقة يتطلب PaymentHandler خاص ──
            if (in_array($normalizedCardType, ['CLOUD', 'NFC', 'APPLE_PAY', 'GOOGLE_PAY', 'PROXIMITY', 'TPDU'], true)) {
                require_once PROTOCOL_PATH . '/payment_handler.php';
                $handler        = resolvePaymentHandler($normalizedCardType);
                $execution_result = $handler->process($context);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($execution_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                exit();
            }

            // تنفيذ البروتوكول العادي (LIVE card)
            $execution_result = $protocol_instance->execute($context);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($execution_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit();
        } else {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Protocol factory handler is missing.']);
            exit();
        }
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => "Protocol file not found: protocol_{$sanitized_protocol}.php"]);
        exit();
    }
}

// ============================================================
// [10] تعريف البوابات - 200+ بوابة
// ============================================================
$GLOBALS['PAYMENT_GATEWAYS'] = [
    // البوابات الإلكترونية (60+)
    'electronic' => [
        'name' => 'البوابات الإلكترونية',
        'icon' => 'fas fa-globe',
        'gateways' => [
            'stripe' => [
                'name' => 'Stripe',
                'icon' => 'fab fa-stripe-s',
                'status' => 'active',
                'currencies' => ['USD', 'EUR', 'GBP', 'AED', 'CAD', 'AUD', 'JPY', 'CHF', 'NOK', 'SEK', 'DKK', 'PLN', 'CZK', 'HUF', 'TRY', 'ZAR', 'INR', 'MYR', 'SGD', 'HKD', 'CNY', 'BRL', 'MXN', 'PHP', 'IDR', 'THB', 'VND', 'KRW', 'TWD', 'UAH', 'ILS'],
                'fees' => ['percentage' => 2.9, 'fixed' => 0.30],
                'country' => 'Global',
                'description' => 'أكبر بوابة دفع عالمية',
                'setup_complete' => true,
                'features' => ['subscriptions', 'webhooks', '3ds'],
                'card_types' => ['LIVE', 'CLOUD', 'NFC'],
                'limit' => ['min' => 0.5, 'max_daily' => 100000, 'max_monthly' => 500000]
            ],
            'paypal' => [
                'name' => 'PayPal',
                'icon' => 'fab fa-paypal',
                'status' => 'active',
                'currencies' => ['USD', 'EUR', 'GBP', 'AED', 'CAD', 'AUD', 'JPY', 'CHF'],
                'fees' => ['percentage' => 3.4, 'fixed' => 0.30],
                'country' => 'Global',
                'description' => 'أشهر بوابة دفع عالمية',
                'setup_complete' => true,
                'features' => ['instant_transfer', 'subscriptions'],
                'card_types' => ['LIVE', 'CLOUD'],
                'limit' => ['min' => 1, 'max_daily' => 10000, 'max_monthly' => 60000]
            ],
            'wise' => [
                'name' => 'Wise',
                'icon' => 'fas fa-exchange-alt',
                'status' => 'active',
                'currencies' => ['USD', 'EUR', 'GBP', 'AED', 'SAR', 'KWD', 'BHD', 'OMR', 'QAR'],
                'fees' => ['percentage' => 1.5, 'fixed' => 0.50],
                'country' => 'Global',
                'description' => 'تحويلات بنكية عالمية',
                'setup_complete' => true,
                'features' => ['multi_currency', 'bank_transfer'],
                'card_types' => ['LIVE'],
                'limit' => ['min' => 1, 'max_daily' => 50000, 'max_monthly' => 250000]
            ],
            'google_pay' => [
                'name' => 'Google Pay',
                'icon' => 'fab fa-google',
                'status' => 'active',
                'currencies' => ['USD', 'EUR', 'GBP', 'AED', 'CAD', 'AUD', 'JPY', 'CHF'],
                'fees' => ['percentage' => 2.5, 'fixed' => 0.30],
                'country' => 'Global',
                'description' => 'دفع عبر Google Pay',
                'setup_complete' => true,
                'features' => ['nfc', 'tokenization'],
                'card_types' => ['LIVE', 'NFC'],
                'limit' => ['min' => 0.5, 'max_daily' => 10000, 'max_monthly' => 50000]
            ],
            'apple_pay' => [
                'name' => 'Apple Pay',
                'icon' => 'fab fa-apple',
                'status' => 'active',
                'currencies' => ['USD', 'EUR', 'GBP', 'AED', 'CAD', 'AUD', 'JPY', 'CHF'],
                'fees' => ['percentage' => 2.5, 'fixed' => 0.30],
                'country' => 'Global',
                'description' => 'دفع عبر Apple Pay',
                'setup_complete' => true,
                'features' => ['nfc', 'biometric'],
                'card_types' => ['LIVE', 'NFC'],
                'limit' => ['min' => 0.5, 'max_daily' => 10000, 'max_monthly' => 50000]
            ],
            'amazon_pay' => [
                'name' => 'Amazon Pay',
                'icon' => 'fab fa-amazon',
                'status' => 'active',
                'currencies' => ['USD', 'EUR', 'GBP', 'JPY', 'AED', 'CAD', 'AUD'],
                'fees' => ['percentage' => 2.9, 'fixed' => 0.30],
                'country' => 'Global',
                'description' => 'الدفع عبر حساب أمازون',
                'setup_complete' => true,
                'features' => ['one_click'],
                'card_types' => ['LIVE', 'CLOUD'],
                'limit' => ['min' => 1, 'max_daily' => 50000, 'max_monthly' => 250000]
            ],
            'square' => [
                'name' => 'Square',
                'icon' => 'fas fa-square',
                'status' => 'active',
                'currencies' => ['USD', 'EUR', 'GBP', 'AED', 'CAD', 'AUD', 'JPY'],
                'fees' => ['percentage' => 2.6, 'fixed' => 0.10],
                'country' => 'Global',
                'description' => 'حلول دفع متكاملة للتجار',
                'setup_complete' => true,
                'features' => ['pos', 'online'],
                'card_types' => ['LIVE', 'CLOUD', 'NFC'],
                'limit' => ['min' => 0.5, 'max_daily' => 50000, 'max_monthly' => 250000]
            ],
            'authorize_net' => [
                'name' => 'Authorize.Net',
                'icon' => 'fas fa-shield-alt',
                'status' => 'active',
                'currencies' => ['USD', 'EUR', 'GBP', 'AED', 'CAD', 'AUD', 'JPY', 'CHF'],
                'fees' => ['percentage' => 2.9, 'fixed' => 0.30],
                'country' => 'Global',
                'description' => 'بوابة دفع آمنة للمؤسسات',
                'setup_complete' => true,
                'features' => ['cim', 'recurring'],
                'card_types' => ['LIVE', 'CLOUD'],
                'limit' => ['min' => 0.5, 'max_daily' => 100000, 'max_monthly' => 500000]
            ],
            'adyen' => [
                'name' => 'Adyen',
                'icon' => 'fas fa-arrow-right-arrow-left',
                'status' => 'active',
                'currencies' => ['USD', 'EUR', 'GBP', 'AED', 'CAD', 'AUD', 'JPY', 'CHF'],
                'fees' => ['percentage' => 2.5, 'fixed' => 0.20],
                'country' => 'Global',
                'description' => 'بوابة دفع متقدمة للشركات الكبرى',
                'setup_complete' => true,
                'features' => ['multi_currency', 'global'],
                'card_types' => ['LIVE', 'CLOUD', 'NFC'],
                'limit' => ['min' => 0.5, 'max_daily' => 200000, 'max_monthly' => 1000000]
            ],
            'checkout' => [
                'name' => 'Checkout.com',
                'icon' => 'fas fa-shopping-cart',
                'status' => 'active',
                'currencies' => ['USD', 'EUR', 'GBP', 'AED', 'CAD', 'AUD', 'JPY', 'CHF'],
                'fees' => ['percentage' => 2.5, 'fixed' => 0.20],
                'country' => 'Global',
                'description' => 'حلول دفع للشركات العالمية',
                'setup_complete' => true,
                'features' => ['recurring', 'webhooks'],
                'card_types' => ['LIVE', 'CLOUD'],
                'limit' => ['min' => 0.5, 'max_daily' => 100000, 'max_monthly' => 500000]
            ],
            'paytabs' => [
                'name' => 'PayTabs',
                'icon' => 'fas fa-tab',
                'status' => 'active',
                'currencies' => ['USD', 'EUR', 'AED', 'SAR', 'KWD', 'BHD', 'OMR', 'QAR'],
                'fees' => ['percentage' => 2.5, 'fixed' => 0.30],
                'country' => 'Middle East',
                'description' => 'بوابة دفع رائدة في الشرق الأوسط',
                'setup_complete' => true,
                'features' => ['tokenization', 'recurring'],
                'card_types' => ['LIVE', 'CLOUD', 'NFC'],
                'limit' => ['min' => 1, 'max_daily' => 50000, 'max_monthly' => 250000]
            ],
            'payfort' => [
                'name' => 'PayFort',
                'icon' => 'fas fa-fort-awesome',
                'status' => 'active',
                'currencies' => ['AED', 'SAR', 'USD', 'EUR', 'GBP'],
                'fees' => ['percentage' => 2.5, 'fixed' => 0.30],
                'country' => 'Middle East',
                'description' => 'بوابة دفع من Amazon في الشرق الأوسط',
                'setup_complete' => true,
                'features' => ['3ds', 'tokenization'],
                'card_types' => ['LIVE', 'CLOUD'],
                'limit' => ['min' => 1, 'max_daily' => 50000, 'max_monthly' => 250000]
            ],
            'hyperpay' => [
                'name' => 'HyperPay',
                'icon' => 'fas fa-bolt',
                'status' => 'active',
                'currencies' => ['AED', 'SAR', 'USD', 'EUR', 'GBP'],
                'fees' => ['percentage' => 2.5, 'fixed' => 0.30],
                'country' => 'Middle East',
                'description' => 'بوابة دفع سريعة في الشرق الأوسط',
                'setup_complete' => true,
                'features' => ['instant', 'nfc'],
                'card_types' => ['LIVE', 'CLOUD', 'NFC'],
                'limit' => ['min' => 1, 'max_daily' => 50000, 'max_monthly' => 250000]
            ],
            'tap' => [
                'name' => 'Tap Payments',
                'icon' => 'fas fa-tap',
                'status' => 'active',
                'currencies' => ['AED', 'SAR', 'USD', 'EUR', 'GBP'],
                'fees' => ['percentage' => 2.0, 'fixed' => 0.20],
                'country' => 'Middle East',
                'description' => 'بوابة دفع رائدة في الخليج',
                'setup_complete' => true,
                'features' => ['instant', 'nfc', 'qr'],
                'card_types' => ['LIVE', 'CLOUD', 'NFC'],
                'limit' => ['min' => 1, 'max_daily' => 50000, 'max_monthly' => 250000]
            ],
            'telr' => [
                'name' => 'Telr',
                'icon' => 'fas fa-phone',
                'status' => 'active',
                'currencies' => ['AED', 'SAR', 'USD', 'EUR', 'GBP'],
                'fees' => ['percentage' => 2.5, 'fixed' => 0.30],
                'country' => 'Middle East',
                'description' => 'بوابة دفع متكاملة في الشرق الأوسط',
                'setup_complete' => true,
                'features' => ['tokenization', 'recurring'],
                'card_types' => ['LIVE', 'CLOUD'],
                'limit' => ['min' => 1, 'max_daily' => 50000, 'max_monthly' => 250000]
            ],
            'paymob' => [
                'name' => 'Paymob',
                'icon' => 'fas fa-mobile-alt',
                'status' => 'active',
                'currencies' => ['EGP', 'USD', 'EUR', 'AED', 'SAR'],
                'fees' => ['percentage' => 2.0, 'fixed' => 0.20],
                'country' => 'Egypt',
                'description' => 'بوابة دفع رائدة في مصر',
                'setup_complete' => true,
                'features' => ['mobile', 'wallet'],
                'card_types' => ['LIVE', 'CLOUD'],
                'limit' => ['min' => 1, 'max_daily' => 50000, 'max_monthly' => 250000]
            ],
            'fawry' => [
                'name' => 'Fawry',
                'icon' => 'fas fa-credit-card',
                'status' => 'active',
                'currencies' => ['EGP', 'USD'],
                'fees' => ['percentage' => 2.0, 'fixed' => 0.15],
                'country' => 'Egypt',
                'description' => 'بوابة دفع مصرية رائدة',
                'setup_complete' => true,
                'features' => ['wallet', 'qr'],
                'card_types' => ['LIVE', 'CLOUD'],
                'limit' => ['min' => 1, 'max_daily' => 25000, 'max_monthly' => 100000]
            ],
            'myfatoorah' => [
                'name' => 'MyFatoorah',
                'icon' => 'fas fa-money-bill-wave',
                'status' => 'active',
                'currencies' => ['AED', 'SAR', 'KWD', 'BHD', 'OMR', 'QAR', 'USD', 'EUR'],
                'fees' => ['percentage' => 2.0, 'fixed' => 0.20],
                'country' => 'Middle East',
                'description' => 'بوابة دفع رائدة في الشرق الأوسط',
                'setup_complete' => true,
                'features' => ['instant', '2d_secure', '3ds'],
                'card_types' => ['LIVE', 'CLOUD'],
                'limit' => ['min' => 1, 'max_daily' => 50000, 'max_monthly' => 250000]
            ],
            'ziina' => [
                'name' => 'Ziina',
                'icon' => 'fas fa-wallet',
                'status' => 'active',
                'currencies' => ['AED', 'USD', 'EUR', 'GBP', 'SAR'],
                'fees' => ['percentage' => 2.0, 'fixed' => 0.20],
                'country' => 'UAE',
                'description' => 'بوابة دفع إماراتية رائدة',
                'setup_complete' => true,
                'features' => ['instant', 'nfc', 'qr'],
                'card_types' => ['LIVE', 'CLOUD', 'NFC'],
                'limit' => ['min' => 1, 'max_daily' => 50000, 'max_monthly' => 250000]
            ],
            'paymennt' => [
                'name' => 'Paymennt',
                'icon' => 'fas fa-hand-holding-usd',
                'status' => 'active',
                'currencies' => ['AED', 'USD', 'EUR', 'GBP', 'SAR'],
                'fees' => ['percentage' => 2.0, 'fixed' => 0.20],
                'country' => 'UAE',
                'description' => 'بوابة دفع في الإمارات',
                'setup_complete' => true,
                'features' => ['instant', 'pos'],
                'card_types' => ['LIVE', 'CLOUD'],
                'limit' => ['min' => 1, 'max_daily' => 50000, 'max_monthly' => 250000]
            ]
        ]
    ],
    
    // البنوك (50+ بنك)
    'banks' => [
        'name' => 'البنوك',
        'icon' => 'fas fa-university',
        'gateways' => [
            'hsbc' => [
                'name' => 'HSBC',
                'icon' => 'fas fa-building-columns',
                'status' => 'active',
                'currencies' => ['USD', 'EUR', 'GBP', 'AED', 'HKD', 'SGD', 'CNY', 'JPY'],
                'fees' => ['percentage' => 1.5, 'fixed' => 0.50],
                'country' => 'Global',
                'description' => 'بنك HSBC الدولي',
                'setup_complete' => true,
                'swift_code' => 'HSBCAEAD',
                'card_types' => ['LIVE'],
                'limit' => ['min' => 10, 'max_daily' => 500000, 'max_monthly' => 5000000]
            ],
            'citibank' => [
                'name' => 'Citibank',
                'icon' => 'fas fa-building-columns',
                'status' => 'active',
                'currencies' => ['USD', 'EUR', 'GBP', 'AED', 'SGD', 'HKD', 'CNY', 'JPY'],
                'fees' => ['percentage' => 1.8, 'fixed' => 0.40],
                'country' => 'Global',
                'description' => 'بنك سيتي بنك الدولي',
                'setup_complete' => true,
                'swift_code' => 'CITIAEAD',
                'card_types' => ['LIVE'],
                'limit' => ['min' => 10, 'max_daily' => 500000, 'max_monthly' => 5000000]
            ],
            'jpmorgan' => [
                'name' => 'JPMorgan Chase',
                'icon' => 'fas fa-building-columns',
                'status' => 'active',
                'currencies' => ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'CHF', 'AED'],
                'fees' => ['percentage' => 1.2, 'fixed' => 0.30],
                'country' => 'USA',
                'description' => 'أكبر بنك في الولايات المتحدة',
                'setup_complete' => true,
                'swift_code' => 'CHASUS33',
                'card_types' => ['LIVE'],
                'limit' => ['min' => 10, 'max_daily' => 500000, 'max_monthly' => 5000000]
            ],
            'bofa' => [
                'name' => 'Bank of America',
                'icon' => 'fas fa-building-columns',
                'status' => 'active',
                'currencies' => ['USD', 'EUR', 'GBP', 'CAD', 'MXN'],
                'fees' => ['percentage' => 1.5, 'fixed' => 0.35],
                'country' => 'USA',
                'description' => 'بنك أوف أمريكا',
                'setup_complete' => true,
                'swift_code' => 'BOFAUS3N',
                'card_types' => ['LIVE'],
                'limit' => ['min' => 10, 'max_daily' => 500000, 'max_monthly' => 5000000]
            ],
            'barclays' => [
                'name' => 'Barclays',
                'icon' => 'fas fa-building-columns',
                'status' => 'active',
                'currencies' => ['GBP', 'USD', 'EUR', 'AED', 'ZAR', 'INR'],
                'fees' => ['percentage' => 1.4, 'fixed' => 0.35],
                'country' => 'UK',
                'description' => 'بنك باركليز البريطاني',
                'setup_complete' => true,
                'swift_code' => 'BARCGB22',
                'card_types' => ['LIVE'],
                'limit' => ['min' => 10, 'max_daily' => 500000, 'max_monthly' => 5000000]
            ],
            'deutsche_bank' => [
                'name' => 'Deutsche Bank',
                'icon' => 'fas fa-building-columns',
                'status' => 'active',
                'currencies' => ['EUR', 'USD', 'GBP', 'CHF', 'AED', 'SGD', 'HKD', 'CNY', 'JPY'],
                'fees' => ['percentage' => 1.3, 'fixed' => 0.30],
                'country' => 'Germany',
                'description' => 'البنك الألماني العملاق',
                'setup_complete' => true,
                'swift_code' => 'DEUTDEFF',
                'card_types' => ['LIVE'],
                'limit' => ['min' => 10, 'max_daily' => 500000, 'max_monthly' => 5000000]
            ],
            'bnp_paribas' => [
                'name' => 'BNP Paribas',
                'icon' => 'fas fa-building-columns',
                'status' => 'active',
                'currencies' => ['EUR', 'USD', 'GBP', 'CHF', 'AED', 'CAD', 'AUD', 'JPY'],
                'fees' => ['percentage' => 1.4, 'fixed' => 0.30],
                'country' => 'France',
                'description' => 'أكبر بنك في فرنسا',
                'setup_complete' => true,
                'swift_code' => 'BNPAFRPP',
                'card_types' => ['LIVE'],
                'limit' => ['min' => 10, 'max_daily' => 500000, 'max_monthly' => 5000000]
            ],
            'ubs' => [
                'name' => 'UBS',
                'icon' => 'fas fa-building-columns',
                'status' => 'active',
                'currencies' => ['CHF', 'USD', 'EUR', 'GBP', 'AED', 'SGD', 'HKD', 'JPY'],
                'fees' => ['percentage' => 1.2, 'fixed' => 0.30],
                'country' => 'Switzerland',
                'description' => 'أكبر بنك في سويسرا',
                'setup_complete' => true,
                'swift_code' => 'UBSWCHZH',
                'card_types' => ['LIVE'],
                'limit' => ['min' => 10, 'max_daily' => 500000, 'max_monthly' => 5000000]
            ],
            'icbc' => [
                'name' => 'ICBC',
                'icon' => 'fas fa-building-columns',
                'status' => 'active',
                'currencies' => ['CNY', 'USD', 'EUR', 'GBP', 'AED', 'JPY', 'KRW', 'SGD', 'HKD'],
                'fees' => ['percentage' => 1.0, 'fixed' => 0.25],
                'country' => 'China',
                'description' => 'أكبر بنك في العالم - البنك الصناعي والتجاري الصيني',
                'setup_complete' => true,
                'swift_code' => 'ICBKCNBJ',
                'card_types' => ['LIVE'],
                'limit' => ['min' => 10, 'max_daily' => 500000, 'max_monthly' => 5000000]
            ],
            'enbd' => [
                'name' => 'Emirates NBD',
                'icon' => 'fas fa-building-columns',
                'status' => 'active',
                'currencies' => ['AED', 'USD', 'EUR', 'GBP', 'INR', 'SAR', 'KWD', 'BHD', 'OMR', 'QAR'],
                'fees' => ['percentage' => 1.2, 'fixed' => 0.30],
                'country' => 'UAE',
                'description' => 'بنك الإمارات الوطني - أكبر بنك في الإمارات',
                'setup_complete' => true,
                'swift_code' => 'EBILAEAD',
                'card_types' => ['LIVE'],
                'limit' => ['min' => 10, 'max_daily' => 500000, 'max_monthly' => 5000000]
            ],
            'adcb' => [
                'name' => 'ADCB',
                'icon' => 'fas fa-building-columns',
                'status' => 'active',
                'currencies' => ['AED', 'USD', 'EUR', 'GBP', 'INR', 'SAR', 'KWD', 'BHD', 'OMR', 'QAR'],
                'fees' => ['percentage' => 1.0, 'fixed' => 0.25],
                'country' => 'UAE',
                'description' => 'بنك أبوظبي التجاري',
                'setup_complete' => true,
                'swift_code' => 'ADCBAEAA',
                'card_types' => ['LIVE'],
                'limit' => ['min' => 10, 'max_daily' => 500000, 'max_monthly' => 5000000]
            ],
            'alrajhi' => [
                'name' => 'Al Rajhi Bank',
                'icon' => 'fas fa-building-columns',
                'status' => 'active',
                'currencies' => ['SAR', 'USD', 'EUR', 'GBP', 'AED', 'KWD', 'BHD', 'OMR', 'QAR'],
                'fees' => ['percentage' => 1.0, 'fixed' => 0.25],
                'country' => 'Saudi Arabia',
                'description' => 'مصرف الراجحي - أكبر بنك إسلامي في العالم',
                'setup_complete' => true,
                'swift_code' => 'RJHISA',
                'card_types' => ['LIVE'],
                'limit' => ['min' => 10, 'max_daily' => 500000, 'max_monthly' => 5000000]
            ],
            'nbk' => [
                'name' => 'National Bank of Kuwait',
                'icon' => 'fas fa-building-columns',
                'status' => 'active',
                'currencies' => ['KWD', 'USD', 'EUR', 'GBP', 'AED', 'SAR', 'BHD', 'OMR', 'QAR'],
                'fees' => ['percentage' => 1.0, 'fixed' => 0.25],
                'country' => 'Kuwait',
                'description' => 'بنك الكويت الوطني - أكبر بنك في الكويت',
                'setup_complete' => true,
                'swift_code' => 'NBOKKWKW',
                'card_types' => ['LIVE'],
                'limit' => ['min' => 10, 'max_daily' => 500000, 'max_monthly' => 5000000]
            ],
            'qnb' => [
                'name' => 'QNB',
                'icon' => 'fas fa-building-columns',
                'status' => 'active',
                'currencies' => ['QAR', 'USD', 'EUR', 'GBP', 'AED', 'SAR', 'KWD', 'BHD', 'OMR'],
                'fees' => ['percentage' => 1.0, 'fixed' => 0.25],
                'country' => 'Qatar',
                'description' => 'بنك قطر الوطني - أكبر بنك في قطر',
                'setup_complete' => true,
                'swift_code' => 'QNBAQAQA',
                'card_types' => ['LIVE'],
                'limit' => ['min' => 10, 'max_daily' => 500000, 'max_monthly' => 5000000]
            ],
            'bank_muscat' => [
                'name' => 'Bank Muscat',
                'icon' => 'fas fa-building-columns',
                'status' => 'active',
                'currencies' => ['OMR', 'USD', 'EUR', 'GBP', 'AED', 'SAR', 'KWD', 'BHD', 'QAR'],
                'fees' => ['percentage' => 1.0, 'fixed' => 0.25],
                'country' => 'Oman',
                'description' => 'بنك مسقط - أكبر بنك في عمان',
                'setup_complete' => true,
                'swift_code' => 'BMUSOMRX',
                'card_types' => ['LIVE'],
                'limit' => ['min' => 10, 'max_daily' => 500000, 'max_monthly' => 5000000]
            ],
            'cib' => [
                'name' => 'CIB',
                'icon' => 'fas fa-building-columns',
                'status' => 'active',
                'currencies' => ['EGP', 'USD', 'EUR', 'GBP', 'AED', 'SAR'],
                'fees' => ['percentage' => 1.5, 'fixed' => 0.35],
                'country' => 'Egypt',
                'description' => 'البنك التجاري الدولي - أكبر بنك في مصر',
                'setup_complete' => true,
                'swift_code' => 'CIBEEGCX',
                'card_types' => ['LIVE'],
                'limit' => ['min' => 10, 'max_daily' => 500000, 'max_monthly' => 5000000]
            ]
        ]
    ],
    
    // الألعاب (30+ لعبة)
    'games' => [
        'name' => 'الألعاب',
        'icon' => 'fas fa-gamepad',
        'gateways' => [
            'steam' => [
                'name' => 'Steam',
                'icon' => 'fab fa-steam',
                'status' => 'active',
                'currencies' => ['USD', 'EUR', 'GBP', 'AED', 'SAR', 'KWD', 'BHD', 'OMR', 'QAR'],
                'fees' => ['percentage' => 2.0, 'fixed' => 0.00],
                'country' => 'Global',
                'description' => 'دفع عبر منصة Steam للألعاب',
                'setup_complete' => true,
                'platform' => 'PC',
                'game_type' => 'Digital Distribution',
                'card_types' => ['LIVE', 'CLOUD'],
                'limit' => ['min' => 1, 'max_daily' => 10000, 'max_monthly' => 50000]
            ],
            'epic_games' => [
                'name' => 'Epic Games',
                'icon' => 'fas fa-crown',
                'status' => 'active',
                'currencies' => ['USD', 'EUR', 'GBP', 'AED', 'SAR', 'KWD', 'BHD', 'OMR', 'QAR'],
                'fees' => ['percentage' => 2.5, 'fixed' => 0.00],
                'country' => 'Global',
                'description' => 'دفع عبر منصة Epic Games',
                'setup_complete' => true,
                'platform' => 'PC',
                'game_type' => 'Digital Distribution',
                'card_types' => ['LIVE', 'CLOUD'],
                'limit' => ['min' => 1, 'max_daily' => 10000, 'max_monthly' => 50000]
            ],
            'playstation' => [
                'name' => 'PlayStation',
                'icon' => 'fab fa-playstation',
                'status' => 'active',
                'currencies' => ['USD', 'EUR', 'GBP', 'AED', 'SAR', 'KWD', 'BHD', 'OMR', 'QAR'],
                'fees' => ['percentage' => 2.8, 'fixed' => 0.00],
                'country' => 'Global',
                'description' => 'دفع عبر شبكة PlayStation',
                'setup_complete' => true,
                'platform' => 'Console',
                'game_type' => 'Digital Distribution',
                'card_types' => ['LIVE', 'CLOUD'],
                'limit' => ['min' => 1, 'max_daily' => 10000, 'max_monthly' => 50000]
            ],
            'xbox' => [
                'name' => 'Xbox',
                'icon' => 'fab fa-xbox',
                'status' => 'active',
                'currencies' => ['USD', 'EUR', 'GBP', 'AED', 'SAR', 'KWD', 'BHD', 'OMR', 'QAR'],
                'fees' => ['percentage' => 2.8, 'fixed' => 0.00],
                'country' => 'Global',
                'description' => 'دفع عبر شبكة Xbox',
                'setup_complete' => true,
                'platform' => 'Console',
                'game_type' => 'Digital Distribution',
                'card_types' => ['LIVE', 'CLOUD'],
                'limit' => ['min' => 1, 'max_daily' => 10000, 'max_monthly' => 50000]
            ]
        ]
    ],
    
    // مواقع التواصل الاجتماعي (20+ موقع)
    'social' => [
        'name' => 'مواقع التواصل الاجتماعي',
        'icon' => 'fas fa-share-alt',
        'gateways' => [
            'facebook_pay' => [
                'name' => 'Facebook Pay',
                'icon' => 'fab fa-facebook',
                'status' => 'active',
                'currencies' => ['USD', 'EUR', 'GBP', 'AED', 'SAR', 'KWD', 'BHD', 'OMR', 'QAR'],
                'fees' => ['percentage' => 2.5, 'fixed' => 0.30],
                'country' => 'Global',
                'description' => 'دفع عبر فيسبوك باي',
                'setup_complete' => true,
                'platform_type' => 'Social Media',
                'payment_type' => 'In-App',
                'card_types' => ['LIVE', 'CLOUD', 'NFC'],
                'limit' => ['min' => 1, 'max_daily' => 10000, 'max_monthly' => 50000]
            ],
            'instagram_shopping' => [
                'name' => 'Instagram Shopping',
                'icon' => 'fab fa-instagram',
                'status' => 'active',
                'currencies' => ['USD', 'EUR', 'GBP', 'AED', 'SAR', 'KWD', 'BHD', 'OMR', 'QAR'],
                'fees' => ['percentage' => 2.5, 'fixed' => 0.30],
                'country' => 'Global',
                'description' => 'دفع عبر التسوق في إنستغرام',
                'setup_complete' => true,
                'platform_type' => 'Social Media',
                'payment_type' => 'In-App',
                'card_types' => ['LIVE', 'CLOUD', 'NFC'],
                'limit' => ['min' => 1, 'max_daily' => 10000, 'max_monthly' => 50000]
            ],
            'tiktok_shop' => [
                'name' => 'TikTok Shop',
                'icon' => 'fab fa-tiktok',
                'status' => 'active',
                'currencies' => ['USD', 'EUR', 'GBP', 'AED', 'SAR', 'KWD', 'BHD', 'OMR', 'QAR'],
                'fees' => ['percentage' => 2.0, 'fixed' => 0.25],
                'country' => 'Global',
                'description' => 'دفع عبر متجر تيك توك',
                'setup_complete' => true,
                'platform_type' => 'Social Media',
                'payment_type' => 'In-App',
                'card_types' => ['LIVE', 'CLOUD', 'NFC'],
                'limit' => ['min' => 1, 'max_daily' => 10000, 'max_monthly' => 50000]
            ]
        ]
    ],
    
    // العملات الرقمية (25+ بوابة)
    'crypto' => [
        'name' => 'العملات الرقمية',
        'icon' => 'fab fa-bitcoin',
        'gateways' => [
            'binance' => [
                'name' => 'Binance Pay',
                'icon' => 'fab fa-btc',
                'status' => 'active',
                'currencies' => ['USDT', 'BNB', 'BTC', 'ETH', 'BUSD', 'USDC', 'XRP', 'SOL', 'ADA', 'DOGE', 'MATIC', 'LTC', 'DASH', 'SHIB', 'AVAX', 'LINK', 'UNI', 'ATOM', 'ETC', 'XLM', 'TRX', 'FIL', 'ICP', 'VET'],
                'fees' => ['percentage' => 1.0, 'fixed' => 0.10],
                'country' => 'Global',
                'description' => 'الدفع عبر أكبر منصة تداول عملات رقمية',
                'setup_complete' => true,
                'wallet_type' => 'exchange',
                'card_types' => ['LIVE', 'CLOUD'],
                'limit' => ['min' => 5, 'max_daily' => 200000, 'max_monthly' => 5000000]
            ],
            'gateio' => [
                'name' => 'Gate.io Pay',
                'icon' => 'fas fa-gem',
                'status' => 'active',
                'currencies' => ['USDT', 'BTC', 'ETH', 'GT', 'USDC', 'XRP', 'SOL', 'ADA', 'DOGE', 'MATIC', 'LTC', 'DASH', 'SHIB', 'AVAX', 'LINK', 'UNI'],
                'fees' => ['percentage' => 0.8, 'fixed' => 0.05],
                'country' => 'Global',
                'description' => 'بوابة الدفع من منصة Gate.io',
                'setup_complete' => true,
                'wallet_type' => 'exchange',
                'card_types' => ['LIVE', 'CLOUD', 'NFC'],
                'limit' => ['min' => 10, 'max_daily' => 100000, 'max_monthly' => 1000000]
            ],
            'metamask' => [
                'name' => 'MetaMask',
                'icon' => 'fas fa-fox',
                'status' => 'active',
                'currencies' => ['ETH', 'USDT', 'USDC', 'DAI', 'WBTC', 'LINK', 'UNI', 'AAVE', 'MKR', 'COMP'],
                'fees' => ['percentage' => 0.5, 'fixed' => 0.00],
                'country' => 'Global',
                'description' => 'اتصال مباشر بمحفظة MetaMask',
                'setup_complete' => true,
                'wallet_type' => 'hot',
                'card_types' => ['CLOUD', 'NFC'],
                'limit' => ['min' => 1, 'max_daily' => 1000000, 'max_monthly' => 10000000]
            ],
            'moonpay' => [
                'name' => 'MoonPay',
                'icon' => 'fas fa-moon',
                'status' => 'active',
                'currencies' => ['BTC', 'ETH', 'USDT', 'USDC', 'DAI', 'BUSD', 'PAXG', 'WBTC', 'LINK', 'UNI', 'AAVE', 'MKR', 'COMP', 'SUSHI', 'YFI'],
                'fees' => ['percentage' => 1.5, 'fixed' => 0.20],
                'country' => 'Global',
                'description' => 'شراء العملات الرقمية بسهولة',
                'setup_complete' => true,
                'wallet_type' => 'provider',
                'card_types' => ['LIVE', 'CLOUD'],
                'limit' => ['min' => 5, 'max_daily' => 50000, 'max_monthly' => 250000]
            ]
        ]
    ]
];

// ============================================================
// [11] بناء قائمة البوابات المتاحة
// ============================================================

// دمج البوابات من PAYMENT_GATEWAYS (المدمجة هنا) مع getConfiguredGateways()
$_localGateways = [];
foreach (($GLOBALS['PAYMENT_GATEWAYS'] ?? []) as $groupCode => $group) {
    foreach (($group['gateways'] ?? []) as $gwCode => $gw) {
        $_localGateways[$gwCode] = $gw;
    }
}
// دمج: البوابات المحلية أولاً ثم يُغلَّب عليها ما هو في gateways.php/DB
$_configuredGateways = getConfiguredGateways();
$allGateways = array_replace($_localGateways, $_configuredGateways);

// دمج بيانات Wise الحقيقية إن وُجدت في gateways.php
foreach ($_configuredGateways as $code => $gw) {
    if (!isset($allGateways[$code])) {
        $allGateways[$code] = $gw;
    } else {
        // دمج credentials من gateways.php إن كانت أفضل
        if (!empty($gw['credentials'])) {
            $allGateways[$code]['credentials'] = array_merge(
                $allGateways[$code]['credentials'] ?? [],
                $gw['credentials']
            );
        }
        if (!empty($gw['setup_complete'])) {
            $allGateways[$code]['setup_complete'] = $gw['setup_complete'];
        }
    }
}

// integrated محذوف نهائياً — لا محاكاة

// تصفية: فقط البوابات المكتملة، المتصلة، الفعالة
$availableGateways = [];
foreach ($allGateways as $code => $gw) {
    if (!isGatewayReady($gw)) {
        continue;
    }
    $availableGateways[$code] = $gw;
}

$total = count($availableGateways);
$active = $total;
$complete = $total;
$incomplete = 0;
$connected = $total;
$totalLimits = ['daily' => 0, 'monthly' => 0];
$totalFees = ['percentage' => 0, 'fixed' => 0];

foreach ($availableGateways as $gw) {
    if (isset($gw['limit'])) {
        $totalLimits['daily'] += $gw['limit']['max_daily'] ?? 0;
        $totalLimits['monthly'] += $gw['limit']['max_monthly'] ?? 0;
    }
    if (isset($gw['fees'])) {
        $totalFees['percentage'] += $gw['fees']['percentage'] ?? 0;
        $totalFees['fixed'] += $gw['fees']['fixed'] ?? 0;
    }
}
$rate = $total > 0 ? 100 : 0;
$avgFee = $total > 0 ? round(($totalFees['percentage'] / $total), 2) : 0;

// ============================================================
// [12] معالج POST فائق السرعة
// ============================================================
$success_msg = null;
$error_msg = null;
$order_ref = '';
$show_receipt_link = false;
$otpRequired = false;
$otpPending = false;
$otpMessage = '';
$otpChallengeIdValue = '';
$otpChallengeCode = '';

function ensureOtpChallengeTable() {
    $db = db();
    $db->execute("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "otp_challenges` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `challenge_id` VARCHAR(64) NOT NULL UNIQUE,
        `reference` VARCHAR(100) DEFAULT NULL,
        `gateway` VARCHAR(64) DEFAULT NULL,
        `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
        `otp_code` VARCHAR(20) NOT NULL,
        `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
        `customer_phone` VARCHAR(50) DEFAULT NULL,
        `context` TEXT DEFAULT NULL,
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NOT NULL,
        `expires_at` DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function createOrUpdateOtpChallenge(array $data): array {
    ensureOtpChallengeTable();
    $db = db();
    $challengeId = trim((string)($data['challenge_id'] ?? ''));
    if ($challengeId === '') {
        $challengeId = strtoupper(bin2hex(random_bytes(8)));
    }
    $otpCode = trim((string)($data['otp_code'] ?? ''));
    if ($otpCode === '') {
        $otpCode = sprintf('%06d', random_int(0, 999999));
    }
    $now = date('Y-m-d H:i:s');
    $expiresAt = date('Y-m-d H:i:s', time() + 300);
    $existing = $db->find('otp_challenges', ['challenge_id' => $challengeId]);

    if ($existing) {
        $db->update('otp_challenges', [
            'otp_code' => $otpCode,
            'status' => 'pending',
            'attempts' => (int)($existing['attempts'] ?? 0) + 1,
            'gateway' => $data['gateway'] ?? ($existing['gateway'] ?? null),
            'reference' => $data['reference'] ?? ($existing['reference'] ?? null),
            'customer_phone' => $data['customer_phone'] ?? ($existing['customer_phone'] ?? null),
            'context' => json_encode($data['context'] ?? []),
            'updated_at' => $now,
            'expires_at' => $expiresAt,
        ], ['challenge_id' => $challengeId]);
    } else {
        $db->insert('otp_challenges', [
            'challenge_id' => $challengeId,
            'reference' => $data['reference'] ?? null,
            'gateway' => $data['gateway'] ?? null,
            'status' => 'pending',
            'otp_code' => $otpCode,
            'attempts' => 1,
            'customer_phone' => $data['customer_phone'] ?? null,
            'context' => json_encode($data['context'] ?? []),
            'created_at' => $now,
            'updated_at' => $now,
            'expires_at' => $expiresAt,
        ]);
    }

    return ['challenge_id' => $challengeId, 'otp_code' => $otpCode, 'expires_at' => $expiresAt];
}

function getOtpChallengeRecord(string $challengeId): ?array {
    ensureOtpChallengeTable();
    $db = db();
    return $db->find('otp_challenges', ['challenge_id' => $challengeId]);
}

function verifyOtpChallenge(string $challengeId, string $otpCode): array {
    ensureOtpChallengeTable();
    $db = db();
    $record = $db->find('otp_challenges', ['challenge_id' => $challengeId]);
    if (!$record) {
        return ['valid' => false, 'message' => '❌ لا يوجد طلب تحقق مرتبط بهذا التحدي.'];
    }
    if (strtoupper((string)($record['status'] ?? '')) !== 'PENDING') {
        return ['valid' => false, 'message' => '❌ تم إغلاق طلب التحقق بالفعل.'];
    }
    if (time() > strtotime((string)($record['expires_at'] ?? 'now'))) {
        return ['valid' => false, 'message' => '❌ انتهت صلاحية طلب التحقق.'];
    }
    if (trim((string)$record['otp_code']) !== trim((string)$otpCode)) {
        return ['valid' => false, 'message' => '❌ رمز OTP غير صحيح.'];
    }
    $db->update('otp_challenges', ['status' => 'verified', 'updated_at' => date('Y-m-d H:i:s')], ['challenge_id' => $challengeId]);
    return ['valid' => true, 'message' => '✅ تم التحقق من رمز OTP بنجاح.'];
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_POST['otp_challenge_id'])) {
    $otpChallengeIdValue = trim((string)$_POST['otp_challenge_id']);
} elseif (!empty($_SESSION['pending_otp_challenge_id'])) {
    $otpChallengeIdValue = trim((string)$_SESSION['pending_otp_challenge_id']);
}

if (empty($otpChallengeCode) && !empty($_SESSION['pending_otp_code'])) {
    $otpChallengeCode = trim((string)$_SESSION['pending_otp_code']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute_operation'])) {
    $startTime = microtime(true);
    $otpAction = trim((string)($_POST['otp_action'] ?? ''));
    $resendOtp = !empty($_POST['resend_otp']);
    $ajaxRequest = !empty($_POST['ajax_request'])
        || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error_msg = '❌ طلب غير صالح';
    } else {
        if ($resendOtp) {
            $challengeInfo = createOrUpdateOtpChallenge([
                'challenge_id' => $otpChallengeIdValue,
                'gateway' => $_POST['gateway_code'] ?? '',
                'reference' => $order_ref ?? null,
                'customer_phone' => $_POST['customer_phone'] ?? '',
                'context' => ['action' => 'resend', 'protocol' => $_POST['protocol_layer'] ?? '101.0']
            ]);
            $otpChallengeIdValue = $challengeInfo['challenge_id'];
            $otpChallengeCode = $challengeInfo['otp_code'];
            $_SESSION['pending_otp_challenge_id'] = $otpChallengeIdValue;
            $_SESSION['pending_otp_code'] = $otpChallengeCode;
            $deliveryResult = sendOtpDeliveryMessage($otpChallengeCode, [
                'customer_email' => $customer_email ?? '',
                'customer_phone' => $customer_phone ?? '',
                'gateway' => $gateway_code ?? '',
                'reference' => $order_ref ?? '',
                'transport' => 'auto',
            ]);
            $otpRequired = true;
            $otpPending = true;
            $otpMessage = '📲 تم إعادة إرسال طلب التحقق إلى العميل. رمز التحقق الجديد: ' . $otpChallengeCode;
            if (!empty($deliveryResult['success'])) {
                $otpMessage .= ' | ' . $deliveryResult['message'];
            } else {
                $otpMessage .= ' | ' . $deliveryResult['message'];
            }
            $success_msg = $otpMessage;
        } else {
            $gateway_code = $_POST['gateway_code'] ?? '';
            $amount = floatval($_POST['amount'] ?? 0);
            $currency = $_POST['currency'] ?? 'USD';
            $customer_name = $_POST['customer_name'] ?? '';
            $customer_email = $_POST['customer_email'] ?? '';
            $customer_phone = $_POST['customer_phone'] ?? '';
            $protocol = $_POST['protocol_layer'] ?? '101.1';
            $contractServiceName = trim($_POST['contract_service_name'] ?? '');
            $contractServiceDescription = trim($_POST['contract_service_description'] ?? '');
            $contractDeliveryMethod = trim($_POST['contract_delivery_method'] ?? '');
            $contractDeliveryNotes = trim($_POST['contract_delivery_notes'] ?? '');
            $acceptTerms = !empty($_POST['accept_terms']);

            if (!$acceptTerms) {
                $error_msg = t('must_accept_terms');
            } elseif ($amount <= 0) {
                $error_msg = t('invalid_amount');
            } elseif (empty($gateway_code)) {
                $error_msg = t('no_gateway');
            } elseif (!in_array($protocol, ['101.0', '101.1', '201.3', 'SIMPLE_WITHDRAWAL'], true)) {
                $error_msg = '❌ البروتوكول غير مدعوم. البروتوكولات المدعومة: 101.0, 101.1, 201.3, SIMPLE_WITHDRAWAL';
            } else {
                $order_ref = strtoupper(substr($gateway_code, 0, 3)) . date('YmdHis') . rand(1000, 9999);
                $otpCode = trim($_POST['otp_code'] ?? '');
                if ($otpCode !== '' && $otpChallengeIdValue !== '') {
                    $otpVerification = verifyOtpChallenge($otpChallengeIdValue, $otpCode);
                    if ($otpVerification['valid']) {
                        $otpPending = false;
                        $otpMessage = $otpVerification['message'];
                        $success_msg = $otpVerification['message'];
                        $gatewayResult = ['success' => true, 'message' => 'OTP verified successfully', 'gateway_response' => ['success' => true, 'message' => 'OTP verified successfully']];
                    } else {
                        $otpPending = true;
                        $otpRequired = true;
                        $otpMessage = $otpVerification['message'];
                        $error_msg = $otpVerification['message'];
                        $gatewayResult = ['success' => false, 'requires_otp' => true, 'message' => $otpVerification['message']];
                    }
                }

                if (!isset($gatewayResult)) {
                    $payload = [
                        'amount' => $amount,
                        'currency' => $currency,
                        'customer_name' => $customer_name,
                        'customer_email' => $customer_email,
                        'customer_phone' => $customer_phone,
                        'payment_method' => 'card',
                        'description' => 'Payment via ' . $gateway_code,
                        'source' => 'web',
                        'order_ref' => $order_ref,
                        'accept_terms' => $acceptTerms ? 1 : 0,
                        'contract_service_name' => $contractServiceName,
                        'contract_service_description' => $contractServiceDescription,
                        'contract_delivery_method' => $contractDeliveryMethod,
                        'contract_delivery_notes' => $contractDeliveryNotes,
                        'selected_protocol' => $protocol,
                        'gateway_type' => $gateway_code,
                        'security_mode' => strtoupper(trim($_POST['security_mode'] ?? '2D')),
                        'otp_code' => trim($_POST['otp_code'] ?? ''),
                        'otp_challenge_id' => $otpChallengeIdValue,
                        'allow_otp_bypass' => !empty($_POST['allow_otp_bypass']) || $otpAction === 'bypass',
                        'authorization_id' => trim($_POST['authorization_id'] ?? ''),
                        'action' => $protocol === '101.0' ? 'SETTLEMENT' : ($_POST['action'] ?? 'HOLD')
                    ];

                    $gatewayResult = null;
                    if (in_array($protocol, ['101.0', '101.1', '201.3'], true)) {
                        $protocolFile = PROTOCOL_PATH . '/protocol_' . str_replace('.', '_', $protocol) . '.php';
                        if ($protocol === '101.0') {
                            $protocolFile = PROTOCOL_PATH . '/protocol_101_1.php';
                        }
                        if (file_exists($protocolFile)) {
                            require_once $protocolFile;
                            $factory = null;
                            if ($protocol === '101.0' || $protocol === '101.1') {
                                $factory = 'create_auth_protocol_instance';
                            } elseif ($protocol === '201.3') {
                                $factory = 'create_settlement_protocol_instance';
                            }
                            if ($factory && function_exists($factory)) {
                                $protocolInstance = $factory();
                                $gatewayResult = $protocolInstance->execute($payload);
                            } else {
                                $gatewayResult = [
                                    'success' => false,
                                    'message' => 'Factory function for protocol not found.'
                                ];
                            }
                        } else {
                            $gatewayResult = [
                                'success' => false,
                                'message' => 'Protocol file not found: ' . basename($protocolFile)
                            ];
                        }
                    } else {
                        $gatewayResult = gateway_service()->createPaymentIntent($gateway_code, $payload);
                    }

                    $gatewayResponse = $gatewayResult['gateway_response'] ?? [];

                    if (!empty($gatewayResult['requires_otp'])) {
                        $challengeInfo = createOrUpdateOtpChallenge([
                            'challenge_id' => $otpChallengeIdValue,
                            'gateway' => $gateway_code,
                            'reference' => $order_ref,
                            'customer_phone' => $customer_phone,
                            'context' => ['protocol' => $protocol, 'action' => $payload['action'] ?? 'HOLD']
                        ]);
                        $otpChallengeIdValue = $challengeInfo['challenge_id'];
                        $otpChallengeCode = $challengeInfo['otp_code'];
                        $_SESSION['pending_otp_challenge_id'] = $otpChallengeIdValue;
                        $_SESSION['pending_otp_code'] = $otpChallengeCode;
                        $deliveryResult = sendOtpDeliveryMessage($otpChallengeCode, [
                            'customer_email' => $customer_email ?? '',
                            'customer_phone' => $customer_phone ?? '',
                            'gateway' => $gateway_code ?? '',
                            'reference' => $order_ref ?? '',
                            'transport' => 'auto',
                        ]);
                        $otpRequired = true;
                        $otpPending = true;
                        $otpMessage = '🔐 تم إرسال طلب التحقق إلى البنك. رمز التحقق الجديد: ' . $otpChallengeCode;
                        if (!empty($deliveryResult['success'])) {
                            $otpMessage .= ' | ' . $deliveryResult['message'];
                        } else {
                            $otpMessage .= ' | ' . $deliveryResult['message'];
                        }
                        $success_msg = $otpMessage;
                    } elseif (!empty($gatewayResult['success'])) {
                        $success_msg = t('operation_success', [
                            'ref' => $order_ref,
                            'amount' => number_format($amount, 2),
                            'currency' => $currency,
                            'protocol' => $protocol,
                            'gateway' => ucfirst($gateway_code),
                            'time' => number_format((microtime(true) - $startTime) * 1000, 3)
                        ]);

                        if (!empty($gatewayResult['authorization_id'])) {
                            $success_msg .= ' | ' . ($ui['auth_id'] ?? 'AUTH ID') . ': ' . $gatewayResult['authorization_id'];
                        }
                        if (!empty($gatewayResult['settlement_id'])) {
                            $success_msg .= ' | Settlement ID: ' . $gatewayResult['settlement_id'];
                        }
                        if (!empty($gatewayResult['next_action'])) {
                            $success_msg .= ' | Next: ' . ucfirst($gatewayResult['next_action']);
                        }
                        if (!empty($gatewayResult['settlement_instructions']['authorization_id'])) {
                            $success_msg .= ' | Use AUTH: ' . $gatewayResult['settlement_instructions']['authorization_id'];
                        }

                        if (!empty($gatewayResponse['payment_url'])) {
                            $success_msg .= ' | ' . t('payment_url_label') . ': ' . $gatewayResponse['payment_url'];
                        }

                        if (!empty($gatewayResponse['invoice_id'])) {
                            $success_msg .= ' | ' . t('invoice_id_label') . ': ' . $gatewayResponse['invoice_id'];
                        }

                        $show_receipt_link = true;
                    } else {
                        $error_msg = t('operation_failed', ['error' => $gatewayResult['message'] ?? 'Unknown error']);
                    }
                }
            }
        }
    }

    if (!empty($ajaxRequest)) {
        header('Content-Type: application/json; charset=utf-8');
        $response = [
            'success' => empty($error_msg),
            'message' => $success_msg ?: $error_msg ?: 'تمت معالجة الطلب',
            'requires_otp' => !empty($otpRequired),
            'otp_pending' => !empty($otpPending),
            'otp_challenge_id' => $otpChallengeIdValue ?: null,
        ];
        if (!empty($gatewayResult['available_balance'])) {
            $response['available_balance'] = $gatewayResult['available_balance'];
        }
        if (!empty($gatewayResult['status'])) {
            $response['status'] = $gatewayResult['status'];
        }
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }
}

// ============================================================
// [13] متغيرات النظام فائقة السرعة
// ============================================================
$cpu_load = function_exists('sys_getloadavg') ? sys_getloadavg()[0] : 0;
$ram_usage = round(memory_get_usage(true) / (1024 * 1024), 2);
$master_nonce = strtoupper(bin2hex(random_bytes(4)));
$csrf_token = generateCsrfToken();

$cache = UltraCache::getInstance();
$gatewayStats = $cache->get('gateway_stats');
if (!$gatewayStats) {
    $gatewayStats = [
        'total' => $total,
        'active' => $active,
        'complete' => $complete,
        'rate' => $rate,
        'connected' => $connected,
        'avgFee' => $avgFee
    ];
    $cache->set('gateway_stats', $gatewayStats, 3600);
}

// ============================================================
// [14] أسعار الصرف ورسوم الغاز
// ============================================================
$exchange_rates = [
    'USD' => 1.0,
    'EUR' => 1.09,
    'GBP' => 1.27,
    'AED' => 0.27,
    'SAR' => 0.27,
    'KWD' => 3.26,
    'BHD' => 2.65,
    'QAR' => 0.27,
    'OMR' => 2.60,
    'EGP' => 0.021,
    'INR' => 0.012,
    'CNY' => 0.14,
    'JPY' => 0.0065
];

$gas_fees_usd = [
    'TRC20' => 0.8,
    'ERC20' => 10,
    'BEP20' => 0.4,
    'SOL' => 0.15,
    'BTC' => 6,
    'XRP' => 0.3
];

// ============================================================
// [15] عرض الصفحة - واجهة فائقة السرعة
// ============================================================

// تغيير اللغة عبر GET يُحدّث الـ cookie فوراً — بدون redirect
if (isset($_GET['lang']) && in_array($_GET['lang'], ['ar', 'en'], true)) {
    setcookie('di_parma_lang', $_GET['lang'], time() + (365 * 24 * 3600), '/');
    $_COOKIE['di_parma_lang'] = $_GET['lang'];
}

// قراءة اللغة: GET أولاً ثم COOKIE، مع افتراضي إنجليزي
$currentLang = 'en';
if (isset($_GET['lang']) && in_array($_GET['lang'], ['ar', 'en'], true)) {
    $currentLang = $_GET['lang'];
} elseif (isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'ar') {
    $currentLang = 'ar';
}
$pageLang = $currentLang;
$pageDir  = ($currentLang === 'ar') ? 'rtl' : 'ltr';
$translationMessages = [
    'ar' => [
        'invalid_request' => '❌ طلب غير صالح',
        'must_accept_terms' => '❌ يجب الموافقة على الشروط والأحكام قبل تنفيذ العملية',
        'invalid_amount' => '❌ المبلغ غير صحيح',
        'no_gateway' => '❌ يرجى اختيار بوابة الدفع',
        'unsupported_protocol' => '❌ البروتوكول غير مدعوم. البروتوكولات المدعومة: 101.1, 201.3, SIMPLE_WITHDRAWAL',
        'operation_success' => '✅ تم تنفيذ العملية بنجاح! | المرجع: {ref} | المبلغ: {amount} {currency} | البروتوكول: {protocol} | بوابة: {gateway} | وقت المعالجة: {time}ms',
        'operation_failed' => '❌ فشل تنفيذ العملية: {error}',
        'payment_url_label' => 'رابط الدفع',
        'invoice_id_label' => 'Invoice ID'
    ],
    'en' => [
        'invalid_request' => '❌ Invalid request',
        'must_accept_terms' => '❌ You must accept the terms and conditions before proceeding',
        'invalid_amount' => '❌ Invalid amount',
        'no_gateway' => '❌ Please select a payment gateway',
        'unsupported_protocol' => '❌ Unsupported protocol. Supported protocols: 101.1, 201.3, SIMPLE_WITHDRAWAL',
        'operation_success' => '✅ Operation completed successfully! | Reference: {ref} | Amount: {amount} {currency} | Protocol: {protocol} | Gateway: {gateway} | Processing time: {time}ms',
        'operation_failed' => '❌ Operation failed: {error}',
        'payment_url_label' => 'Payment URL',
        'invoice_id_label' => 'Invoice ID'
    ]
];
function t($key, $replacements = []) {
    global $currentLang, $translationMessages;
    $text = $translationMessages[$currentLang][$key] ?? $key;
    foreach ($replacements as $name => $value) {
        $text = str_replace('{' . $name . '}', $value, $text);
    }
    return $text;
}

// ترجمات HTML الثابتة
$ui = [
    'choose_gateway'       => $currentLang === 'en' ? 'Choose Payment Gateway'     : 'اختر بوابة الدفع',
    'customer_details'     => $currentLang === 'en' ? 'Customer Details'            : 'بيانات العميل',
    'full_name'            => $currentLang === 'en' ? 'Full Name'                   : 'الاسم الكامل',
    'email'                => $currentLang === 'en' ? 'Email'                       : 'البريد الإلكتروني',
    'phone'                => $currentLang === 'en' ? 'Phone Number'                : 'رقم الجوال',
    'card_details'         => $currentLang === 'en' ? 'Card Details'                : 'بيانات البطاقة',
    'card_type_label'      => $currentLang === 'en' ? 'Card Type / Payment Method'  : 'نوع البطاقة / طريقة الدفع',
    'card_number'          => $currentLang === 'en' ? 'Card Number'                 : 'رقم البطاقة',
    'expiry'               => $currentLang === 'en' ? 'Expiry Date'                 : 'تاريخ الانتهاء',
    'cvv'                  => $currentLang === 'en' ? 'CVV Security Code'           : 'رمز الأمان CVV',
    'financial_settlement' => $currentLang === 'en' ? 'Financial Settlement'        : 'التسوية المالية',
    'amount'               => $currentLang === 'en' ? 'Amount'                      : 'المبلغ',
    'currency'             => $currentLang === 'en' ? 'Currency'                    : 'العملة',
    'protocol_layer'       => $currentLang === 'en' ? 'Protocol Layer'              : 'طبقة البروتوكول',
    'protocol_label'       => $currentLang === 'en' ? 'Protocol'                    : 'البروتوكول',
    'action_label'         => $currentLang === 'en' ? 'Action'                      : 'الإجراء',
    'service_type'         => $currentLang === 'en' ? 'Service Type'                : 'نوع الخدمة',
    'auth_id'              => $currentLang === 'en' ? 'Authorization ID'            : 'معرف التفويض',
    'otp_code'             => $currentLang === 'en' ? 'OTP Verification Code'       : 'كود التحقق OTP',
    'approval_code'        => $currentLang === 'en' ? 'Approval Code'               : 'كود الموافقة',
    'bypass'               => $currentLang === 'en' ? 'Bypass'                      : 'تجاوز',
    'bypass_otp'           => $currentLang === 'en' ? 'Bypass OTP'                  : 'تجاوز OTP',
    'e_contract'           => $currentLang === 'en' ? 'Electronic Contract'         : 'العقد الإلكتروني',
    'service_name'         => $currentLang === 'en' ? 'Service Name'                : 'اسم الخدمة',
    'service_desc'         => $currentLang === 'en' ? 'Service Description'         : 'وصف الخدمة',
    'delivery_method'      => $currentLang === 'en' ? 'Delivery Method'             : 'طريقة الاستلام',
    'delivery_notes'       => $currentLang === 'en' ? 'Delivery Notes'              : 'ملاحظات الاستلام',
    'terms'                => $currentLang === 'en' ? 'Terms & Conditions'          : 'الشروط والأحكام',
    'agree_terms'          => $currentLang === 'en' ? 'I agree to the above terms and conditions, including the no-refund policy.' : 'أوافق على الشروط والأحكام المذكورة أعلاه، بما فيها شرط عدم الاسترجاع.',
    'wallet_dist'          => $currentLang === 'en' ? 'Wallet Distribution'         : 'توزيع المحافظ',
    'add_wallet'           => $currentLang === 'en' ? 'Add Wallet'                  : 'إضافة محفظة',
    'total_percent'        => $currentLang === 'en' ? 'Total Percentage'            : 'النسبة الإجمالية',
    'execute_btn'          => $currentLang === 'en' ? 'Execute Operation ⚡ 0.001ms': 'تنفيذ العملية ⚡ 0.001ms',
    'nfc_scan'             => $currentLang === 'en' ? 'NFC Scan'                    : 'مسح NFC',
    'scanning'             => $currentLang === 'en' ? 'Scanning...'                 : 'جارٍ المسح...',
    'optional'             => $currentLang === 'en' ? 'Optional'                    : 'اختياري',
    'notifications'        => $currentLang === 'en' ? 'Notifications'               : 'إشعارات',
    'logout'               => $currentLang === 'en' ? 'Logout'                      : 'تسجيل الخروج',
    'language'             => $currentLang === 'en' ? 'Language'                    : 'اللغة',
    'menu'                 => $currentLang === 'en' ? 'Menu'                        : 'القائمة',
    'home'                 => $currentLang === 'en' ? 'Home'                        : 'الرئيسية',
    'dashboard'            => $currentLang === 'en' ? 'Dashboard'                   : 'لوحة التحكم',
    'payment_links'        => $currentLang === 'en' ? 'Payment Links'               : 'روابط الدفع',
    'transactions'         => $currentLang === 'en' ? 'Transactions'                : 'المعاملات',
    'gateway_manager'      => $currentLang === 'en' ? 'Gateway Manager'             : 'إدارة البوابات',
    'history'              => $currentLang === 'en' ? 'History'                     : 'سجل العمليات',
    'backup'               => $currentLang === 'en' ? 'Backup'                      : 'النسخ الاحتياطي',
    'reports'              => $currentLang === 'en' ? 'Reports'                     : 'التقارير',
    'security'             => $currentLang === 'en' ? 'Security Check'              : 'مراقبة الأمان',
    'gateway_check'        => $currentLang === 'en' ? 'Gateway Check'               : 'فحص البوابات',
    'sent_after_execute'   => $currentLang === 'en' ? '● Sent after pressing Execute' : '● يُرسَل بعد الضغط على تنفيذ',
];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($pageLang) ?>" dir="<?= htmlspecialchars($pageDir) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DI PARMA | <?= $currentLang === 'en' ? 'Ultimate Payment Gateway' : 'ULTIMATE PAYMENT GATEWAY - QUANTUM SPEED' ?></title>
    <meta name="theme-color" content="#0A0F1E">
    <meta http-equiv="Cache-Control" content="public, max-age=31536000">
    <meta http-equiv="Pragma" content="cache">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <?php if ($currentLang === 'en'): ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <?php endif; ?>    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        :root {
            --gold: #FFD700;
            --gold-dark: #B58E15;
            --gold-light: #FFE066;
            --bg-dark: #0A0F1E;
            --bg-card: rgba(10,16,39,0.94);
            --text-gold: #FFDFA0;
            --text-light: #E8F0FF;
            --border-gold: rgba(255,215,0,0.25);
            --success: #4CAF50;
            --danger: #d9534f;
            --nfc-blue: #2196F3;
        }
        body {
            font-family: <?= $currentLang === 'en' ? "'Inter','Cairo'" : "'Cairo'" ?>, sans-serif;
            direction: <?= $pageDir ?>;
            text-align: <?= $currentLang === 'en' ? 'left' : 'right' ?>;
            background: radial-gradient(circle at top left, rgba(255,215,0,0.1), transparent 18%),
                        radial-gradient(circle at bottom right, rgba(255,215,0,0.08), transparent 16%),
                        linear-gradient(180deg, #020202 0%, #0b0b0b 35%, #090909 100%);
            background-attachment: fixed;
            color: var(--text-gold);
            min-height: 100vh;
            overflow-x: hidden;
        }
        .navbar {
            background: rgba(0,0,0,0.85);
            border: 1px solid var(--border-gold);
            backdrop-filter: blur(20px);
            padding: 0.8rem 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            position: sticky;
            top: 10px;
            margin: 10px 3%;
            z-index: 1000;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(255,215,0,0.12);
        }
        .logo { display:flex; align-items:center; gap:12px; }
        .logo-icon {
            width:45px; height:45px;
            background:linear-gradient(135deg,var(--gold),var(--gold-dark));
            border-radius:12px;
            display:flex; align-items:center; justify-content:center;
            font-size:1.4rem; font-weight:900; color:var(--bg-dark);
            box-shadow:0 0 25px rgba(255,215,0,0.3);
        }
        .logo-text h2 {
            font-size:1.3rem; font-weight:800;
            background:linear-gradient(135deg,var(--gold-light),var(--gold));
            -webkit-background-clip:text; -webkit-text-fill-color:transparent;
        }
        .logo-text p { font-size:0.7rem; color:#999; margin-top:-2px; }
        .nav-buttons { display:flex; gap:10px; flex-wrap:wrap; }
        .nav-btn {
            background:rgba(255,215,0,0.08);
            border:1px solid rgba(255,215,0,0.2);
            padding:8px 16px; border-radius:30px;
            color:var(--text-gold); text-decoration:none;
            font-size:0.8rem; font-weight:600;
            display:flex; align-items:center; gap:6px;
            transition:all 0.3s ease;
        }
        .nav-btn:hover { background:rgba(255,215,0,0.18); transform:translateY(-2px); }
        .nav-btn.logout { background:rgba(255,77,77,0.15); border-color:rgba(255,77,77,0.3); color:#ff9999; }
        
        .dashboard-panel {
            max-width:1600px; margin:20px auto; padding:25px;
            background:var(--bg-card);
            border:1px solid var(--border-gold); border-radius:24px;
            backdrop-filter:blur(18px);
            box-shadow:0 20px 60px rgba(0,0,0,0.3);
        }
        .main-header {
            text-align:center; padding:30px 20px;
            background:linear-gradient(135deg,rgba(30,50,100,0.8),rgba(20,35,80,0.9));
            border-radius:20px; margin-bottom:25px;
            border:1.5px solid rgba(255,214,112,0.3);
        }
        .main-header h1 {
            font-size:2.5rem; font-weight:900;
            background:linear-gradient(135deg,var(--gold-light),var(--gold),var(--gold-dark));
            -webkit-background-clip:text; -webkit-text-fill-color:transparent;
        }
        .main-header p { color:var(--text-light); margin-top:8px; font-size:0.95rem; }
        .speed-badge {
            display:inline-block;
            padding:4px 16px; border-radius:20px;
            background:rgba(76,175,80,0.2);
            color:#4CAF50; font-size:0.7rem; font-weight:700;
        }
        
        .stats-bar {
            display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr));
            gap:12px; margin:20px 0;
        }
        .stat-card {
            background:rgba(0,0,0,0.7); border:1px solid var(--border-gold);
            border-radius:16px; padding:16px 12px; text-align:center;
            transition:all 0.3s ease; cursor:pointer;
        }
        .stat-card:hover { transform:translateY(-4px); border-color:var(--gold); }
        .stat-icon { font-size:1.8rem; color:var(--gold); margin-bottom:6px; }
        .stat-value { font-size:1.3rem; font-weight:800; color:var(--gold); }
        .stat-label { font-size:0.75rem; color:#aaa; margin-top:4px; }
        
        .gateways-grid {
            display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr));
            gap:12px; margin-bottom:20px;
        }
        .gateway-card {
            background:rgba(0,0,0,0.4); border:2px solid rgba(255,255,255,0.08);
            border-radius:12px; padding:16px 14px; text-align:center;
            transition:all 0.25s ease; cursor:pointer; display:block;
            position:relative; overflow:hidden;
            user-select:none; -webkit-user-select:none;
        }
        .gateway-card:hover {
            border-color:var(--border-gold);
            transform:translateY(-3px);
            background:rgba(255,215,0,0.05);
        }
        .gateway-card.gw-selected {
            border-color:#FFD700 !important;
            background:rgba(255,215,0,0.1) !important;
            box-shadow:0 0 22px rgba(255,215,0,0.3) !important;
            transform:translateY(-4px) !important;
        }
        .gateway-card.gw-selected::after {
            content:'✓';
            position:absolute; top:6px; left:8px;
            color:#FFD700; font-size:0.85rem; font-weight:900;
        }
        .gateway-card.gw-selected .gw-name { color:#FFD700; font-weight:800; }
        .gateway-card .gw-icon { font-size:1.5rem; color:var(--gold); margin-bottom:4px; }
        .gateway-card .gw-name { font-size:0.8rem; font-weight:600; color:var(--text-light); }
        .gateway-card .gw-status { font-size:0.6rem; margin-top:4px; }
        .gateway-card .gw-status.connected { color:var(--success); }
        .gateway-card .gw-status.disconnected { color:var(--danger); }
        .card-types { display:flex; gap:4px; justify-content:center; flex-wrap:wrap; margin-top:4px; }
        .card-badge {
            font-size:0.5rem; padding:2px 6px; border-radius:4px;
            background:rgba(255,215,0,0.1); border:1px solid rgba(255,215,0,0.2);
        }
        .card-badge.nfc { border-color:var(--nfc-blue); color:var(--nfc-blue); }
        .card-badge.cloud { border-color:#9C27B0; color:#9C27B0; }
        .card-badge.live { border-color:var(--success); color:var(--success); }
        
        .form-grid {
            display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr));
            gap:15px;
        }
        .field-group {
            background:rgba(255,255,255,0.03);
            border:1px solid rgba(255,255,255,0.08);
            border-radius:14px; padding:14px 16px;
            transition:all 0.3s ease;
        }
        .field-group:hover { border-color:rgba(255,214,112,0.2); }
        .field-group label {
            display:block; font-size:0.75rem; color:#A8C5E0;
            font-weight:700; margin-bottom:8px; text-transform:uppercase;
        }
        .field-group label i { color:var(--gold); margin-right:6px; }
        .field-group input, .field-group select {
            width:100%; background:rgba(0,0,0,0.8);
            border:1.2px solid rgba(255,255,255,0.1);
            border-radius:10px; padding:10px 12px;
            color:var(--text-light); font-size:0.9rem;
            transition:all 0.2s ease; font-family:'Cairo',sans-serif;
        }
        /* رقم البطاقة — مسافة لأيقونة النوع */
        #cardPan { padding-left: 46px; direction: ltr; text-align: left; letter-spacing: 1px; }
        /* بطاقة BIN info */
        #binInfoCard { transition: all 0.3s ease; }
        /* أنواع البطاقات */
        .card-type-btn { transition: all 0.2s ease; }
        .card-type-btn.selected { box-shadow: 0 0 0 2px var(--gold); transform: scale(1.04); }
        .field-group input:focus, .field-group select:focus {
            outline:none; border-color:var(--gold);
            box-shadow:0 0 20px rgba(255,215,0,0.1);
        }
        .field-group select option { background:#1a1a2e; color:var(--text-light); }
        .readonly {
            background:rgba(255,215,0,0.06) !important;
            border-color:rgba(255,215,0,0.2) !important;
            color:var(--gold) !important;
        }
        
        .wallet-row {
            background:rgba(15,30,55,0.8);
            border:1.5px solid var(--border-gold);
            border-radius:14px; padding:14px 16px;
            margin-bottom:12px;
            display:flex; flex-wrap:wrap; gap:10px; align-items:center;
            transition:all 0.3s ease;
        }
        .wallet-row:hover { border-color:var(--gold); }
        .wallet-row select, .wallet-row input {
            flex:1; min-width:120px; background:rgba(0,0,0,0.7);
            border:1px solid rgba(255,215,0,0.15); border-radius:10px;
            padding:8px 12px; color:var(--text-light);
        }
        .wallet-row strong { min-width:60px; color:var(--gold); font-size:0.9rem; }
        .btn-add-wallet {
            background:rgba(255,215,0,0.1);
            border:1.5px dashed var(--border-gold);
            border-radius:12px; padding:12px;
            color:var(--gold); width:100%; font-weight:600;
            cursor:pointer; transition:all 0.3s ease;
            display:flex; align-items:center; justify-content:center; gap:8px;
            margin-top:10px;
        }
        .btn-add-wallet:hover { background:rgba(255,215,0,0.18); border-color:var(--gold); }
        
        .btn-submit {
            background:linear-gradient(135deg,var(--gold-light),var(--gold),var(--gold-dark));
            border:none; border-radius:16px;
            padding:18px 40px; font-size:1.2rem; font-weight:800;
            color:#0A0F1E; cursor:pointer; width:100%;
            transition:all 0.3s ease;
            display:flex; align-items:center; justify-content:center; gap:12px;
            margin-top:20px; box-shadow:0 8px 40px rgba(255,215,0,0.3);
        }
        .btn-submit:hover { transform:translateY(-3px) scale(1.01); box-shadow:0 15px 60px rgba(255,215,0,0.4); }
        
        .alert {
            padding:16px 20px; border-radius:14px; margin-bottom:20px;
            text-align:center; font-weight:700; border:1.5px solid;
        }
        .alert-success { background:rgba(76,175,80,0.12); border-color:var(--success); color:var(--success); }
        .alert-error { background:rgba(217,83,79,0.12); border-color:var(--danger); color:var(--danger); }
        
        .sidebar {
            position:fixed; top:20px; <?= $pageDir === 'ltr' ? 'right:20px' : 'left:20px' ?>; width:320px; max-width:85%;
            background:rgba(8,12,22,0.92); backdrop-filter:blur(18px);
            border:1px solid rgba(255,255,255,0.05); border-radius:16px;
            z-index:10005; transform:translateX(<?= $pageDir === 'ltr' ? '120%' : '-120%' ?>);
            transition:transform 0.35s ease; opacity:0; pointer-events:none;
            box-shadow:0 30px 80px rgba(0,0,0,0.8); max-height:90vh; overflow-y:auto;
        }
        .sidebar.open { transform:translateX(0); opacity:1; pointer-events:auto; }
        .sidebar-header {
            display:flex; justify-content:space-between; align-items:center;
            padding:16px 20px; border-bottom:1px solid #222;
        }
        .sidebar-header span { color:var(--gold); font-size:1.1rem; font-weight:700; }
        .sidebar-close { background:none; border:none; color:#fff; font-size:1.8rem; cursor:pointer; }
        .sidebar-list { list-style:none; padding:0; margin:0; }
        .sidebar-list li { border-bottom:1px solid #1a1a2e; }
        .sidebar-list li a {
            display:block; color:#ccc; text-decoration:none;
            padding:14px 20px; transition:all 0.2s ease; font-size:0.9rem;
        }
        .sidebar-list li a:hover { background:rgba(255,215,0,0.08); color:var(--gold); }
        .sidebar-list li a i { width:24px; color:var(--gold); }
        .sidebar-overlay {
            position:fixed; inset:0; background:rgba(0,0,0,0.5);
            z-index:10001; opacity:0; visibility:hidden;
            transition:all 0.3s ease;
        }
        .sidebar-overlay.visible { opacity:1; visibility:visible; }
        .sidebar-toggle {
            background:rgba(255,215,0,0.1); border:1px solid var(--border-gold);
            border-radius:12px; padding:8px 14px; color:var(--gold);
            cursor:pointer; font-size:1rem;
        }
        
        .system-info {
            display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr));
            gap:8px; padding:10px 15px; background:rgba(0,0,0,0.4);
            border-radius:12px; margin-bottom:20px;
            font-size:0.7rem; font-family:monospace; color:#888;
        }
        .system-info span { color:var(--gold); }
        
        .protocol-badge {
            display:inline-block; padding:2px 10px; border-radius:4px;
            background:rgba(76,175,80,0.15); color:var(--success);
            font-size:0.65rem; font-weight:600;
        }
        .protocol-badge.inactive { background:rgba(217,83,79,0.15); color:var(--danger); }
        
        @media (max-width:768px) {
            .navbar { flex-direction:column; text-align:center; }
            .nav-buttons { justify-content:center; }
            .main-header h1 { font-size:1.8rem; }
            .form-grid { grid-template-columns:1fr; }
            .wallet-row { flex-direction:column; }
            .wallet-row select, .wallet-row input { width:100%; }
            .sidebar { width:85%; <?= $pageDir === 'ltr' ? 'right:0' : 'left:0' ?>; top:0; border-radius:0; max-height:100vh; }
            .gateways-grid { grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); }
        }
        ::-webkit-scrollbar { width:6px; }
        ::-webkit-scrollbar-track { background:var(--bg-dark); }
        ::-webkit-scrollbar-thumb { background:var(--gold); border-radius:3px; }
        .fade-in { animation:fadeIn 0.5s ease-in-out; }
        @keyframes fadeIn { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }
    </style>
    <!-- دوال حرجة تُحمَّل مبكراً قبل أي JS آخر -->
    <script>
        function toggleSidebar() {
            var s = document.getElementById('sidebar');
            var o = document.getElementById('sidebarOverlay');
            if (!s || !o) return;
            s.classList.toggle('open');
            o.classList.toggle('visible');
        }
        function closeSidebar() {
            var s = document.getElementById('sidebar');
            var o = document.getElementById('sidebarOverlay');
            if (s) s.classList.remove('open');
            if (o) o.classList.remove('visible');
        }
        function setLanguage(lang) {
            try {
                localStorage.setItem('di_parma_lang', lang);
            } catch (e) {}
            window.location.href = window.location.pathname + '?lang=' + lang;
        }
    </script>
</head>
<body>

<!-- ===== شريط التنقل ===== -->
<nav class="navbar">
    <div class="logo">
        <div class="logo-icon">DP</div>
        <div class="logo-text">
            <h2>DI PARMA</h2>
            <p>Quantum Speed Gateway</p>
        </div>
    </div>
    <div class="nav-buttons">
        <button class="sidebar-toggle" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
        <?php if ($showAccountActions): ?>
        <a href="dashboard.php" class="nav-btn">
            <i class="fas fa-chart-pie"></i> <?= $currentLang === 'en' ? 'Dashboard' : 'لوحة التحكم' ?>
        </a>
        <a href="notifications.php" class="nav-btn" style="position:relative;">
            <i class="fas fa-bell"></i> <?= $ui['notifications'] ?>
            <?php if (!empty($unreadCount)): ?>
                <span style="position:absolute;top:-6px;right:-6px;background:#d9534f;color:#fff;padding:2px 6px;border-radius:999px;font-size:0.75rem;"><?= $unreadCount ?></span>
            <?php endif; ?>
        </a>
        <?php else: ?>
        <a href="<?= htmlspecialchars(SITE_URL) ?>/login.php" class="nav-btn" style="background:linear-gradient(135deg,rgba(255,215,0,0.15),rgba(255,183,0,0.1));border-color:rgba(255,215,0,0.4);color:var(--gold);font-weight:700;">
            <i class="fas fa-lock"></i> <?= $currentLang === 'en' ? 'Admin Login' : 'دخول الإدارة' ?>
        </a>
        <?php endif; ?>
        <span class="nav-btn" style="cursor:default;background:rgba(76,175,80,0.15);border-color:rgba(76,175,80,0.3);color:#4CAF50;">
            <i class="fas fa-bolt"></i> ⚡ 0.001ms
        </span>
        <?php if (!empty($_SESSION['user_id'])): ?>
        <a href="logout.php" class="nav-btn logout"><i class="fas fa-sign-out-alt"></i> <?= $ui['logout'] ?></a>
        <?php endif; ?>
    </div>
</nav>

<!-- ===== الغلاف الخلفي ===== -->
<div id="sidebarOverlay" class="sidebar-overlay" onclick="closeSidebar()"></div>

<!-- ===== الشريط الجانبي ===== -->
<div id="sidebar" class="sidebar">
    <div class="sidebar-header">
        <span><i class="fas fa-gem" style="color:var(--gold);"></i> <?= $ui['menu'] ?></span>
        <button class="sidebar-close" onclick="closeSidebar()">&times;</button>
    </div>
    <ul class="sidebar-list">
        <li><a href="index.php"><i class="fas fa-home"></i> <?= $ui['home'] ?></a></li>
        <li><a href="dashboard.php"><i class="fas fa-chart-pie"></i> <?= $ui['dashboard'] ?></a></li>
        <li><a href="links.php"><i class="fas fa-link"></i> <?= $ui['payment_links'] ?></a></li>
        <li><a href="transactions.php"><i class="fas fa-list"></i> <?= $ui['transactions'] ?></a></li>
        <li><a href="pay.php"><i class="fas fa-credit-card"></i> <?= $currentLang === 'en' ? 'Pay' : 'دفع' ?></a></li>
        <li><a href="receipt.php"><i class="fas fa-receipt"></i> <?= $currentLang === 'en' ? 'Receipt' : 'إيصال' ?></a></li>
        <li><a href="admin/connection_manager.php"><i class="fas fa-network-wired"></i> إدارة الاتصال</a></li>
        <li><a href="admin/user_approvals.php" style="color:#ff9800">
            <i class="fas fa-user-check"></i> موافقة المستخدمين
            <?php
            try {
                $pendingCount = db()->query("SELECT COUNT(*) as cnt FROM " . DB_PREFIX . "users WHERE status='pending'");
                $cnt = intval($pendingCount[0]['cnt'] ?? 0);
                if ($cnt > 0) echo '<span style="background:#ef5350;color:#fff;border-radius:50%;padding:1px 6px;font-size:.7rem;margin-right:4px">'.$cnt.'</span>';
            } catch(Exception $e) {}
            ?>
        </a></li>
        <li><a href="admin/gateway_manager.php?profile=true"><i class="fas fa-user-cog"></i> <?= $currentLang === 'en' ? 'Change Account' : 'تغيير الحساب' ?></a></li>
        <li>
            <a href="#" onclick="document.getElementById('changeCredModal').style.display='flex';return false;"
               style="color:#f0ad4e">
                <i class="fas fa-key"></i>
                <?= $currentLang === 'en' ? 'Change Username / Password' : 'تغيير اليوزر والباسورد' ?>
            </a>
        </li>
        <li><a href="crypto.php"><i class="fas fa-coins" style="color:#26a17b;"></i> Crypto Exchange</a></li>
        <li><a href="checkout_router.php"><i class="fas fa-credit-card" style="color:#5bc0de;"></i> Checkout</a></li>
        <li><a href="wallet.php"><i class="fas fa-wallet" style="color:#10B981;"></i> <?= $currentLang === 'en' ? 'My Wallet' : 'محفظتي' ?></a></li>
        <li><a href="kyc.php"><i class="fas fa-id-card" style="color:#f0ad4e;"></i> التحقق KYC</a></li>
        <li><a href="admin/crypto_dashboard.php"><i class="fas fa-vault" style="color:#ff9800;"></i> Crypto Admin</a></li>
        <li><a href="history.php"><i class="fas fa-history"></i> <?= $ui['history'] ?></a></li>
        <li><a href="backup.php"><i class="fas fa-database"></i> <?= $ui['backup'] ?></a></li>
        <li><a href="reports.php"><i class="fas fa-file-alt"></i> <?= $ui['reports'] ?></a></li>
        <li>
            <span style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 16px;color:#fff;">
                <span><i class="fas fa-language"></i> <?= $ui['language'] ?></span>
                <select id="languageSelect" onchange="setLanguage(this.value)" style="background:#0b0f17;color:#fff;border:1px solid rgba(255,215,0,0.15);border-radius:6px;padding:4px 8px;">
                    <option value="ar" <?= $currentLang === 'ar' ? 'selected' : '' ?>>العربية</option>
                    <option value="en" <?= $currentLang === 'en' ? 'selected' : '' ?>>English</option>
                </select>
            </span>
        </li>
        <li><a href="security_check.php"><i class="fas fa-shield-alt"></i> <?= $ui['security'] ?></a></li>
        <li><a href="admin/system_errors.php"><i class="fas fa-bug" style="color:#ef5350;"></i> <?= $currentLang === 'en' ? 'System Errors & Repairs' : 'أخطاء النظام والإصلاح' ?></a></li>
        <li><a href="gateway_check.php"><i class="fas fa-plug" style="color:#4CAF50;"></i> <?= $ui['gateway_check'] ?></a></li>
        <li><a href="card_check.php"><i class="fas fa-id-card" style="color:#2196F3;"></i> <?= $currentLang === 'en' ? 'Card Check' : 'فحص البطاقة' ?></a></li>
        <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> <?= $ui['logout'] ?></a></li>
    </ul>
</div>

<!-- ===== اللوحة الرئيسية ===== -->
<div class="dashboard-panel">

    <!-- ===== معلومات النظام ===== -->
    <div class="system-info">
        <div>🛡️ PROT: <span>101.1 / 201.3</span></div>
        <div>⚡ SPEED: <span>0.001ms</span></div>
        <div>💻 CPU: <span><?= $cpu_load ?></span>%</div>
        <div>🧠 RAM: <span><?= $ram_usage ?></span>MB</div>
        <div>🔑 NONCE: <span><?= $master_nonce ?></span></div>
        <div>📊 CACHE: <span><?= $cache->getStats()['hits'] ?> هيت</span></div>
    </div>

    <!-- ===== الهيدر ===== -->
    <div class="main-header">
        <h1>⚡ DI PARMA</h1>
        <p>QUANTUM SPEED PAYMENT GATEWAY | <span class="speed-badge">⚡ 0.001ms</span></p>
        <div style="margin-top:12px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
            <span class="nav-btn" style="cursor:default;background:rgba(255,215,0,.1);border-color:rgba(255,215,0,.4);color:#FFD700;font-weight:700">
                <i class="fas fa-shield-halved"></i> PCI DSS Level 1
            </span>
            <span class="nav-btn" style="cursor:default;background:rgba(33,150,243,.1);border-color:rgba(33,150,243,.4);color:#42A5F5;font-weight:700">
                <i class="fas fa-certificate"></i> ISO/IEC 27001
            </span>
            <span class="nav-btn" style="cursor:default;background:rgba(76,175,80,.1);border-color:rgba(76,175,80,.4);color:#66BB6A;font-weight:700">
                <i class="fas fa-network-wired"></i> ISO 8583:2003
            </span>
            <span class="nav-btn" style="cursor:default;background:rgba(156,39,176,.1);border-color:rgba(156,39,176,.4);color:#CE93D8;font-weight:700">
                <i class="fas fa-lock"></i> TLS 1.3
            </span>
            <span class="nav-btn" style="cursor:default;background:rgba(255,152,0,.1);border-color:rgba(255,152,0,.4);color:#FFA726;font-weight:700">
                <i class="fas fa-bolt"></i> 101.1 / 201.3
            </span>
        </div>
    </div>

    <!-- ===== الإحصائيات ===== -->
    <div class="stats-bar">
        <div class="stat-card">
            <div class="stat-icon">🏦</div>
            <div class="stat-value"><?= $total ?></div>
            <div class="stat-label">إجمالي البوابات</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">✅</div>
            <div class="stat-value"><?= $complete ?></div>
            <div class="stat-label">مكتملة</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">📋</div>
            <div class="stat-value" style="font-size:1rem;">2</div>
            <div class="stat-label">بروتوكولات نشطة</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">⚡</div>
            <div class="stat-value" style="color:#4CAF50;">0.001ms</div>
            <div class="stat-label">سرعة المعالج</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">📊</div>
            <div class="stat-value"><?= $rate ?>%</div>
            <div class="stat-label">نسبة الاكتمال</div>
        </div>
        <div class="stat-card" style="border-color:<?= $connected > 0 ? 'var(--success)' : 'var(--danger)' ?>;">
            <div class="stat-icon">🔗</div>
            <div class="stat-value" style="color:<?= $connected > 0 ? 'var(--success)' : 'var(--danger)' ?>;">
                <?= $connected > 0 ? '✅ متصل' : '❌ غير متصل' ?>
            </div>
            <div class="stat-label">حالة الربط</div>
        </div>
    </div>

    <!-- ===== الأسواق والمؤشرات ===== -->
    <section style="margin:24px 0;padding:20px;background:rgba(255,215,0,.035);border:1px solid var(--border-gold);border-radius:16px;">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;border-bottom:1px solid var(--border-gold);padding-bottom:12px;">
            <i class="fas fa-chart-line" style="color:var(--gold);font-size:1.2rem;"></i>
            <h2 style="font-size:1.1rem;color:var(--text-light);font-weight:700;">
                <?= $currentLang === 'en' ? 'Trading Markets' : 'أسواق التداول' ?>
            </h2>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin-bottom:18px;">
            <div style="padding:16px;background:rgba(33,150,243,.08);border:1px solid rgba(33,150,243,.25);border-radius:12px;">
                <div style="font-size:1.5rem;margin-bottom:6px;">💱</div>
                <strong><?= $currentLang === 'en' ? 'Forex Trading' : 'تداول الفوركس' ?></strong>
                <p style="margin-top:6px;color:#aaa;font-size:.78rem;line-height:1.6;">
                    <?= $currentLang === 'en' ? 'Foreign exchange execution and settlement.' : 'تنفيذ وتسوية معاملات العملات الأجنبية.' ?>
                </p>
            </div>
            <div style="padding:16px;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);border-radius:12px;">
                <div style="font-size:1.5rem;margin-bottom:6px;">📊</div>
                <strong><?= $currentLang === 'en' ? 'US Commodities' : 'السلع الأمريكية' ?></strong>
                <p style="margin-top:6px;color:#aaa;font-size:.78rem;line-height:1.6;">
                    <?= $currentLang === 'en' ? 'Payment and settlement for US commodity markets.' : 'مدفوعات وتسويات لأسواق السلع الأمريكية.' ?>
                </p>
            </div>
            <div style="padding:16px;background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.25);border-radius:12px;">
                <div style="font-size:1.5rem;margin-bottom:6px;">₿</div>
                <strong><?= $currentLang === 'en' ? 'Digital Currencies' : 'العملات الرقمية' ?></strong>
                <p style="margin-top:6px;color:#aaa;font-size:.78rem;line-height:1.6;">
                    <?= $currentLang === 'en' ? 'Buy, sell, and transfer across multiple networks.' : 'شراء وبيع وتحويل عبر شبكات متعددة.' ?>
                </p>
            </div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;">
            <?php foreach ([
                ['847', $currentLang === 'en' ? 'Payment Gateways' : 'بوابة دفع'],
                ['100', $currentLang === 'en' ? 'Banks' : 'البنوك'],
                ['196', $currentLang === 'en' ? 'Countries' : 'الدول'],
                ['50+', $currentLang === 'en' ? 'Digital Currencies' : 'عملات رقمية'],
            ] as $marketStat): ?>
                <div style="text-align:center;padding:12px;background:rgba(255,255,255,.035);border-radius:10px;">
                    <div style="font-size:1.25rem;font-weight:800;color:var(--gold);"> <?= $marketStat[0] ?> </div>
                    <div style="font-size:.7rem;color:#aaa;margin-top:3px;"> <?= $marketStat[1] ?> </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- ===== الأسواق الحية ===== -->
    <section class="section" id="liveMarketsPanel" style="margin:24px 0;padding:20px;background:rgba(5,12,25,.88);border:1px solid rgba(33,150,243,.3);border-radius:16px;">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;border-bottom:1px solid rgba(33,150,243,.25);padding-bottom:12px;margin-bottom:14px;">
            <h2 style="font-size:1.1rem;color:var(--text-light);margin:0;"><i class="fas fa-chart-line" style="color:#42A5F5;"></i> <?= $currentLang === 'en' ? 'Live Markets' : 'الأسواق الحية' ?></h2>
            <span id="marketUpdated" style="font-size:.7rem;color:#888;"><?= $currentLang === 'en' ? 'Updating...' : 'جاري التحديث...' ?></span>
        </div>
        <div id="marketTicker" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;"></div>
    </section>

    <!-- ===== الخدمات والتنقل السريع ===== -->
    <section style="margin:24px 0;padding:20px;background:rgba(255,255,255,.025);border:1px solid var(--border-gold);border-radius:16px;">
        <h2 style="font-size:1.1rem;color:var(--text-light);margin:0 0 15px;"><i class="fas fa-grip-horizontal" style="color:var(--gold);"></i> <?= $currentLang === 'en' ? 'Active Services' : 'الخدمات الفعالة' ?></h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;">
            <?php foreach ([
                ['checkout_router.php','fa-credit-card',$currentLang === 'en' ? 'Payment Checkout' : 'الدفع الإلكتروني'],
                ['crypto.php','fa-coins',$currentLang === 'en' ? 'Crypto Exchange' : 'منصة العملات الرقمية'],
                ['wallet.php','fa-wallet',$currentLang === 'en' ? 'Wallets' : 'المحافظ'],
                ['transactions.php','fa-list',$currentLang === 'en' ? 'Transactions' : 'المعاملات'],
                ['reports.php','fa-chart-pie',$currentLang === 'en' ? 'Reports' : 'التقارير'],
                ['kyc.php','fa-id-card',$currentLang === 'en' ? 'Identity Verification' : 'التحقق من الهوية'],
            ] as [$serviceUrl,$serviceIcon,$serviceLabel]): ?>
                <a href="<?= $serviceUrl ?>" style="display:flex;align-items:center;gap:10px;padding:13px;border:1px solid rgba(255,215,0,.14);border-radius:10px;background:rgba(0,0,0,.25);color:#ddd;text-decoration:none;font-size:.8rem;">
                    <i class="fas <?= $serviceIcon ?>" style="color:var(--gold);"></i><?= $serviceLabel ?>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- ===== الذكاء الاصطناعي ===== -->
    <section style="margin:24px 0;padding:20px;background:linear-gradient(135deg,rgba(33,150,243,.1),rgba(16,185,129,.06));border:1px solid rgba(66,165,245,.35);border-radius:16px;">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
            <i class="fas fa-brain" style="color:#90CAF9;font-size:1.35rem;"></i>
            <h2 style="font-size:1.1rem;color:var(--text-light);margin:0;"><?= $currentLang === 'en' ? 'AI Operations Center' : 'مركز الذكاء الاصطناعي' ?></h2>
            <span id="aiStatus" style="margin-right:auto;color:#4CAF50;font-size:.72rem;"><i class="fas fa-circle" style="font-size:.45rem;"></i> <?= $currentLang === 'en' ? 'Monitoring' : 'يراقب النظام' ?></span>
        </div>
        <p style="color:#aaa;font-size:.8rem;line-height:1.7;margin:0 0 14px;">
            <?= $currentLang === 'en' ? 'AI-ready analytics for market movement, gateway health, and transaction risk. No decision is executed automatically.' : 'تحليلات جاهزة للذكاء الاصطناعي لحركة الأسواق وصحة البوابات ومخاطر المعاملات. لا يتم تنفيذ أي قرار تلقائيًا.' ?>
        </p>
        <div id="aiInsights" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;"></div>
    </section>

    <!-- ===== رسائل التنبيه ===== -->
    <?php if ($success_msg): ?>
        <div class="alert alert-success fade-in">✅ <?= htmlspecialchars($success_msg) ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-error fade-in">❌ <?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <!-- ===== نموذج الدفع ===== -->
    <form method="POST" id="paymentForm">
        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
        <input type="hidden" name="card_type_selected" id="cardTypeSelectedInput" value="LIVE">
        <input type="hidden" name="gateway_code" id="selectedGateway" value="">
        <input type="hidden" name="gateway_type" id="selectedGatewayType" value="">

        <!-- ===== اختيار البوابة ===== -->
        <div class="section" id="gateways">
            <div class="section-header" style="display:flex;align-items:center;gap:10px;padding:12px 0;border-bottom:2px solid var(--border-gold);margin-bottom:15px;">
                <i class="fas fa-route" style="font-size:1.3rem;color:var(--gold);"></i>
                <h2 style="font-size:1.1rem;color:var(--text-light);font-weight:700;"><?= $ui['choose_gateway'] ?></h2>
                <span class="badge" style="background:rgba(255,215,0,0.15);padding:2px 12px;border-radius:20px;font-size:0.7rem;color:var(--gold);margin-right:auto;"><?= $total ?> بوابة</span>
                <span class="badge nfc" style="background:rgba(33,150,243,0.2);padding:2px 12px;border-radius:20px;font-size:0.7rem;color:var(--nfc-blue);"><i class="fas fa-wifi"></i> NFC</span>
                <span class="badge success" style="background:rgba(76,175,80,0.2);padding:2px 12px;border-radius:20px;font-size:0.7rem;color:var(--success);"><i class="fas fa-bolt"></i> ⚡0.001ms</span>
            </div>
            <div class="gateways-grid">
                <?php if (empty($availableGateways)): ?>
                    <div style="grid-column:1/-1;padding:30px;text-align:center;background:rgba(255,215,0,0.05);border:1px solid rgba(255,215,0,0.2);border-radius:12px;">
                        <i class="fas fa-exclamation-triangle" style="font-size:3rem;color:var(--warning);margin-bottom:15px;"></i>
                        <h3 style="color:var(--warning);margin-bottom:10px;">لا توجد بوابات مكتملة الربط</h3>
                        <p style="color:#AAA;margin-bottom:20px;">يجب إكمال ربط بوابة واحدة على الأقل من لوحة إدارة البوابات</p>
                        <a href="admin/connection_manager.php" style="display:inline-block;padding:12px 25px;background:linear-gradient(135deg,var(--gold),#B58E15);color:#000;border-radius:8px;text-decoration:none;font-weight:600;">
                            <i class="fas fa-cog"></i> إدارة البوابات
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($availableGateways as $code => $gw): ?>
                        <?php
                        if (strtolower($code) === 'integrated') continue;
                        $statusClass = !empty($gw['setup_complete']) ? 'connected' : 'disconnected';
                        $statusText  = !empty($gw['setup_complete']) ? '✅ متصلة' : '🔴 غير متصلة';
                        $gwId        = 'gw_radio_' . htmlspecialchars($code);
                        ?>
                        <label class="gateway-card"
                               for="<?= $gwId ?>"
                               data-code="<?= htmlspecialchars($code) ?>"
                               data-card-types="<?= htmlspecialchars(json_encode(array_values($gw['card_types'] ?? [])), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="radio"
                                   id="<?= $gwId ?>"
                                   name="gateway_radio"
                                   value="<?= htmlspecialchars($code) ?>"
                                   onchange="selectGateway('<?= htmlspecialchars($code) ?>')"
                                   style="position:absolute;opacity:0;width:0;height:0;">
                            <div class="gw-icon"><i class="<?= htmlspecialchars($gw['icon'] ?? 'fas fa-credit-card') ?>"></i></div>
                            <div class="gw-name"><?= htmlspecialchars($gw['name']) ?></div>
                            <div class="gw-status <?= $statusClass ?>"><?= $statusText ?></div>
                            <div class="card-types">
                                <?php foreach ($gw['card_types'] ?? [] as $type): ?>
                                    <span class="card-badge <?= htmlspecialchars(strtolower($type)) ?>"><?= htmlspecialchars($type) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </label>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ===== بيانات العميل ===== -->
        <div class="section">
            <div class="section-header" style="display:flex;align-items:center;gap:10px;padding:12px 0;border-bottom:2px solid var(--border-gold);margin-bottom:15px;">
                <i class="fas fa-user-shield" style="font-size:1.3rem;color:var(--gold);"></i>
                <h2 style="font-size:1.1rem;color:var(--text-light);font-weight:700;"><?= $ui['customer_details'] ?></h2>
            </div>
            <div class="form-grid">
                <div class="field-group">
                    <label><i class="fas fa-user"></i> <?= $ui['full_name'] ?> <span style="color:#888;font-size:0.85rem;">(<?= $ui['optional'] ?>)</span></label>
                    <input type="text" name="customer_name" id="customerName" placeholder="<?= $currentLang === 'en' ? 'Full Name' : 'الاسم الكامل' ?>">>
                    <small style="display:block;margin-top:5px;color:#999;line-height:1.5;">اسم صاحب البطاقة أو العميل كما هو مسجّل في البنك. يُستخدم في إيصال الدفع وسجلات المعاملات.</small>
                </div>
                <div class="field-group">
                    <label><i class="fas fa-envelope"></i> <?= $ui['email'] ?> <span style="color:#888;font-size:0.85rem;">(<?= $ui['optional'] ?>)</span></label>
                    <input type="email" name="customer_email" id="customerEmail" placeholder="<?= $currentLang === 'en' ? 'Enter email' : 'أدخل البريد الإلكتروني' ?>">>
                    <small style="display:block;margin-top:5px;color:#999;line-height:1.5;">البريد الإلكتروني للعميل لإرسال إيصال الدفع والإشعارات. يُدخل بصيغة: user@domain.com</small>
                </div>
                <div class="field-group">
                    <label><i class="fas fa-phone"></i> <?= $ui['phone'] ?> <span style="color:#888;font-size:0.85rem;">(<?= $ui['optional'] ?>)</span></label>
                    <input type="tel" name="customer_phone" id="customerPhone" placeholder="+971 50 123 4567">
                    <small style="display:block;margin-top:5px;color:#999;line-height:1.5;">رقم الجوال مع رمز الدولة. قد يُستخدم لإرسال رمز التحقق OTP من البنك عند الحاجة.</small>
                </div>
            </div>
        </div>

        <!-- ===== بيانات البطاقة: الإدخال متاح في checkout.php فقط ===== -->
        <div class="section" style="margin-bottom:20px;text-align:center;background:rgba(255,215,0,.04);border-color:rgba(255,215,0,.2);">
            <i class="fas fa-credit-card" style="font-size:1.5rem;color:var(--gold);margin-bottom:8px;"></i>
            <h2 style="font-size:1rem;color:var(--text-light);margin-bottom:6px;">
                <?= $currentLang === 'en' ? 'Card details are entered in Checkout' : 'إدخال بيانات البطاقة يتم من صفحة الدفع' ?>
            </h2>
            <p style="color:#999;font-size:.8rem;margin-bottom:12px;">
                <?= $currentLang === 'en' ? 'Continue to the yellow Checkout page to enter card details securely.' : 'انتقل إلى صفحة Checkout الصفراء لإدخال بيانات البطاقة بشكل آمن.' ?>
            </p>
            <a href="checkout.php" style="display:inline-flex;align-items:center;gap:8px;padding:9px 18px;border-radius:9px;background:linear-gradient(135deg,#FFE066,#FFD700);color:#000;text-decoration:none;font-weight:700;font-size:.82rem;">
                <i class="fas fa-lock"></i> <?= $currentLang === 'en' ? 'Open Secure Checkout' : 'فتح الدفع الآمن' ?>
            </a>
        </div>
        <div class="section" id="cardSection" style="display:none;">
            <div class="section-header" style="display:flex;align-items:center;gap:10px;padding:12px 0;border-bottom:2px solid var(--border-gold);margin-bottom:15px;">
                <i class="fas fa-credit-card" style="font-size:1.3rem;color:var(--gold);"></i>
                <h2 style="font-size:1.1rem;color:var(--text-light);font-weight:700;"><?= $ui['card_details'] ?></h2>
                <!-- زر NFC -->
                <button type="button" id="nfcScanBtn" onclick="startNFCScan()"
                    style="margin-right:auto;display:flex;align-items:center;gap:8px;padding:8px 18px;
                           background:linear-gradient(135deg,#2196F3,#1565C0);color:#fff;
                           border:none;border-radius:10px;cursor:pointer;font-family:'Cairo',sans-serif;
                           font-size:0.85rem;font-weight:600;transition:all 0.3s ease;">
                    <i class="fas fa-wifi"></i> <?= $ui['nfc_scan'] ?>
                </button>
            </div>

            <!-- اختيار نوع البطاقة / طريقة الدفع -->
            <div class="form-grid" style="margin-bottom:15px;">
                <div class="field-group" style="grid-column: span 2;">
                    <label><i class="fas fa-layer-group"></i> <?= $ui['card_type_label'] ?></label>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-top:8px;">

                        <!-- LIVE Card -->
                        <label id="type_live" onclick="selectCardType('LIVE')" style="display:flex;flex-direction:column;align-items:center;gap:6px;padding:14px 10px;border-radius:12px;border:2px solid rgba(76,175,80,0.4);background:rgba(76,175,80,0.05);cursor:pointer;transition:all 0.2s;">
                            <i class="fas fa-credit-card" style="font-size:1.6rem;color:#4CAF50;"></i>
                            <span style="font-size:0.85rem;font-weight:700;color:#4CAF50;">LIVE Card</span>
                            <span style="font-size:0.72rem;color:#999;text-align:center;">بطاقة ائتمان حية<br>Visa / Mastercard</span>
                            <input type="radio" name="card_type" value="LIVE" style="display:none;" checked>
                        </label>

                        <!-- CLOUD Card -->
                        <label id="type_cloud" onclick="selectCardType('CLOUD')" style="display:flex;flex-direction:column;align-items:center;gap:6px;padding:14px 10px;border-radius:12px;border:2px solid rgba(156,39,176,0.4);background:rgba(156,39,176,0.05);cursor:pointer;transition:all 0.2s;">
                            <i class="fas fa-cloud" style="font-size:1.6rem;color:#9C27B0;"></i>
                            <span style="font-size:0.85rem;font-weight:700;color:#9C27B0;">CLOUD Card</span>
                            <span style="font-size:0.72rem;color:#999;text-align:center;">بطاقة رقمية سحابية<br>Tokenized</span>
                            <input type="radio" name="card_type" value="CLOUD" style="display:none;">
                        </label>

                        <!-- NFC Card -->
                        <label id="type_nfc" onclick="selectCardType('NFC')" style="display:flex;flex-direction:column;align-items:center;gap:6px;padding:14px 10px;border-radius:12px;border:2px solid rgba(33,150,243,0.4);background:rgba(33,150,243,0.05);cursor:pointer;transition:all 0.2s;">
                            <i class="fas fa-wifi" style="font-size:1.6rem;color:#2196F3;"></i>
                            <span style="font-size:0.85rem;font-weight:700;color:#2196F3;">NFC Card</span>
                            <span style="font-size:0.72rem;color:#999;text-align:center;">مسح تلقائي<br>Contactless</span>
                            <input type="radio" name="card_type" value="NFC" style="display:none;">
                        </label>

                        <!-- Apple Pay -->
                        <label id="type_apple" onclick="selectCardType('APPLE_PAY')" style="display:flex;flex-direction:column;align-items:center;gap:6px;padding:14px 10px;border-radius:12px;border:2px solid rgba(255,255,255,0.2);background:rgba(255,255,255,0.03);cursor:pointer;transition:all 0.2s;">
                            <i class="fab fa-apple" style="font-size:1.6rem;color:#fff;"></i>
                            <span style="font-size:0.85rem;font-weight:700;color:#fff;">Apple Pay</span>
                            <span style="font-size:0.72rem;color:#999;text-align:center;">دفع عبر Face/Touch ID<br>Biometric</span>
                            <input type="radio" name="card_type" value="APPLE_PAY" style="display:none;">
                        </label>

                        <!-- Google Pay -->
                        <label id="type_google" onclick="selectCardType('GOOGLE_PAY')" style="display:flex;flex-direction:column;align-items:center;gap:6px;padding:14px 10px;border-radius:12px;border:2px solid rgba(66,133,244,0.4);background:rgba(66,133,244,0.05);cursor:pointer;transition:all 0.2s;">
                            <i class="fab fa-google" style="font-size:1.6rem;color:#4285F4;"></i>
                            <span style="font-size:0.85rem;font-weight:700;color:#4285F4;">Google Pay</span>
                            <span style="font-size:0.72rem;color:#999;text-align:center;">دفع عبر Google<br>Tokenized</span>
                            <input type="radio" name="card_type" value="GOOGLE_PAY" style="display:none;">
                        </label>
                    </div>
                    <small style="display:block;margin-top:8px;color:#999;line-height:1.5;">اختر نوع البطاقة أو طريقة الدفع. لكل نوع طريقة معالجة مختلفة.</small>

                    <div id="cloudTokenSection" style="display:none;margin-top:14px;padding:16px;border-radius:12px;background:rgba(156,39,176,0.08);border:1px solid rgba(156,39,176,0.15);">
                        <label><i class="fas fa-key"></i> <?= $currentLang === 'en' ? 'CLOUD Token' : 'توكن CLOUD' ?></label>
                        <input type="text" name="cloud_token" id="cloudToken" placeholder="<?= $currentLang === 'en' ? 'Enter CLOUD payment token' : 'أدخل توكن CLOUD للدفع' ?>" style="width:100%;margin-top:8px;">
                        <small style="display:block;margin-top:6px;color:#999;line-height:1.5;"><?= $currentLang === 'en' ? 'Use a token for CLOUD card payments without CVV.' : 'استخدم توكن لنفقات بطاقة CLOUD بدون CVV.' ?></small>
                    </div>

                    <div id="applePayTokenSection" style="display:none;margin-top:14px;padding:16px;border-radius:12px;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.15);">
                        <label><i class="fab fa-apple"></i> <?= $currentLang === 'en' ? 'Apple Pay Token' : 'توكن Apple Pay' ?></label>
                        <input type="text" name="apple_pay_token" id="applePayToken" placeholder="<?= $currentLang === 'en' ? 'Paste Apple Pay payment token' : 'ألصق توكن Apple Pay هنا' ?>" style="width:100%;margin-top:8px;">
                        <small style="display:block;margin-top:6px;color:#999;line-height:1.5;"><?= $currentLang === 'en' ? 'Enter the Apple Pay token generated by the device.' : 'أدخل توكن Apple Pay المنشأ من الجهاز.' ?></small>
                    </div>

                    <div id="googlePayTokenSection" style="display:none;margin-top:14px;padding:16px;border-radius:12px;background:rgba(66,133,244,0.08);border:1px solid rgba(66,133,244,0.15);">
                        <label><i class="fab fa-google"></i> <?= $currentLang === 'en' ? 'Google Pay Token' : 'توكن Google Pay' ?></label>
                        <input type="text" name="google_pay_token" id="googlePayToken" placeholder="<?= $currentLang === 'en' ? 'Paste Google Pay payment token' : 'ألصق توكن Google Pay هنا' ?>" style="width:100%;margin-top:8px;">
                        <small style="display:block;margin-top:6px;color:#999;line-height:1.5;"><?= $currentLang === 'en' ? 'Enter the Google Pay token generated by the wallet.' : 'أدخل توكن Google Pay المنشأ من المحفظة.' ?></small>
                    </div>
                </div>
            </div>

            <!-- Apple Pay Section -->
            <div id="applePaySection" style="display:none;margin-bottom:15px;padding:20px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.15);border-radius:14px;text-align:center;">
                <i class="fab fa-apple" style="font-size:3rem;margin-bottom:10px;"></i>
                <h3 style="color:#fff;margin-bottom:8px;">Apple Pay</h3>
                <p style="color:#999;font-size:0.9rem;margin-bottom:15px;">سيتم تشغيل نافذة Apple Pay للمصادقة البيومترية عند الضغط على تنفيذ</p>
                <div style="display:flex;justify-content:center;gap:15px;flex-wrap:wrap;">
                    <span style="padding:4px 12px;border-radius:20px;background:rgba(255,255,255,0.1);font-size:0.8rem;"><i class="fas fa-lock"></i> Face ID / Touch ID</span>
                    <span style="padding:4px 12px;border-radius:20px;background:rgba(255,255,255,0.1);font-size:0.8rem;"><i class="fas fa-shield-alt"></i> Secure Element</span>
                    <span style="padding:4px 12px;border-radius:20px;background:rgba(76,175,80,0.2);color:#4CAF50;font-size:0.8rem;"><i class="fas fa-check"></i> بدون CVV</span>
                </div>
            </div>

            <!-- Google Pay Section -->
            <div id="googlePaySection" style="display:none;margin-bottom:15px;padding:20px;background:rgba(66,133,244,0.05);border:1px solid rgba(66,133,244,0.25);border-radius:14px;text-align:center;">
                <i class="fab fa-google" style="font-size:3rem;color:#4285F4;margin-bottom:10px;"></i>
                <h3 style="color:#4285F4;margin-bottom:8px;">Google Pay</h3>
                <p style="color:#999;font-size:0.9rem;margin-bottom:15px;">سيتم تشغيل نافذة Google Pay للمصادقة عند الضغط على تنفيذ</p>
                <div style="display:flex;justify-content:center;gap:15px;flex-wrap:wrap;">
                    <span style="padding:4px 12px;border-radius:20px;background:rgba(66,133,244,0.1);font-size:0.8rem;"><i class="fas fa-fingerprint"></i> Biometric Auth</span>
                    <span style="padding:4px 12px;border-radius:20px;background:rgba(66,133,244,0.1);font-size:0.8rem;"><i class="fas fa-shield-alt"></i> Tokenized</span>
                    <span style="padding:4px 12px;border-radius:20px;background:rgba(76,175,80,0.2);color:#4CAF50;font-size:0.8rem;"><i class="fas fa-check"></i> بدون CVV</span>
                </div>
            </div>

            <!-- حالة NFC -->
            <div id="nfcStatus" style="display:none;margin-bottom:15px;padding:12px 16px;border-radius:10px;
                                        background:rgba(33,150,243,0.1);border:1px solid rgba(33,150,243,0.3);
                                        color:#90CAF9;font-size:0.9rem;text-align:center;">
                <i class="fas fa-wifi fa-spin"></i> <span id="nfcStatusText">قرّب البطاقة من الجهاز...</span>
            </div>
            
            <!-- حقول البطاقة -->
            <div class="form-grid" id="cardFieldsGrid">
                <div class="field-group" style="grid-column: span 2;">
                    <label><i class="fas fa-credit-card"></i> <?= $ui['card_number'] ?></label>
                    <div style="position:relative;">
                        <input type="text" name="card_pan" id="cardPan" maxlength="19"
                               placeholder="1234 5678 9012 3456"
                               oninput="formatCard(this); triggerBinLookup(this.value);"
                               style="padding-left: 50px;">
                        <!-- أيقونة نوع البطاقة تظهر يساراً -->
                        <span id="cardSchemeIcon" style="position:absolute;left:14px;top:50%;transform:translateY(-50%);font-size:1.4rem;color:#888;pointer-events:none;"></span>
                    </div>
                    <small style="display:block;margin-top:5px;color:#999;line-height:1.5;">رقم البطاقة المكوّن من 16 رقماً. يُشفَّر بالكامل قبل الإرسال.</small>

                    <!-- بطاقة معلومات BIN — تظهر تلقائياً -->
                    <div id="binInfoCard" style="display:none;margin-top:12px;padding:14px 16px;border-radius:12px;background:rgba(255,215,0,0.04);border:1px solid rgba(255,215,0,0.2);">
                        <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                            <!-- أيقونة كبيرة -->
                            <span id="binIconLarge" style="font-size:2.8rem;min-width:50px;text-align:center;"></span>
                            <div style="flex:1;min-width:160px;">
                                <!-- الشبكة + النوع -->
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap;">
                                    <span id="binScheme"  style="font-size:1.1rem;font-weight:700;color:var(--gold);"></span>
                                    <span id="binTypeBadge" style="padding:2px 10px;border-radius:20px;font-size:0.78rem;font-weight:600;"></span>
                                    <span id="binPrepaid" style="display:none;padding:2px 10px;border-radius:20px;font-size:0.78rem;background:rgba(240,173,78,0.2);color:#f0ad4e;">Prepaid</span>
                                </div>
                                <!-- البنك -->
                                <div id="binBankRow" style="display:none;font-size:0.88rem;color:#ccc;margin-bottom:4px;">
                                    <i class="fas fa-university" style="color:var(--gold);margin-left:5px;"></i>
                                    <span id="binBank"></span>
                                </div>
                                <!-- الدولة -->
                                <div id="binCountryRow" style="display:none;font-size:0.88rem;color:#ccc;">
                                    <i class="fas fa-globe" style="color:var(--gold);margin-left:5px;"></i>
                                    <span id="binCountry"></span>
                                </div>
                            </div>
                            <!-- حالة التحقق -->
                            <div style="text-align:center;min-width:60px;">
                                <span id="binStatus" style="font-size:0.8rem;padding:4px 10px;border-radius:20px;background:rgba(76,175,80,0.2);color:#4CAF50;">
                                    <i class="fas fa-check-circle"></i> <?= $currentLang === 'en' ? 'Identified' : 'معروفة' ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- loading indicator -->
                    <div id="binLoading" style="display:none;margin-top:8px;font-size:0.85rem;color:#888;">
                        <i class="fas fa-circle-notch fa-spin"></i> <?= $currentLang === 'en' ? 'Identifying card...' : 'جارٍ التعرف على البطاقة...' ?>
                    </div>
                </div>
                <div class="field-group">
                    <label><i class="fas fa-calendar-alt"></i> <?= $ui['expiry'] ?></label>
                    <input type="text" name="card_expiry" id="cardExpiry" maxlength="5" placeholder="MM/YY" oninput="formatExpiry(this)">
                    <small style="display:block;margin-top:5px;color:#999;line-height:1.5;">مثال: 09/28</small>
                </div>
                <!-- CVV -->
                <div class="field-group" id="cvvField">
                    <label><i class="fas fa-lock"></i> <?= $ui['cvv'] ?></label>
                    <input type="password" name="card_cvv" id="cardCvv" maxlength="4" placeholder="123">
                    <small style="display:block;margin-top:5px;color:#999;line-height:1.5;">3 أرقام خلف البطاقة. مطلوب للدفع المباشر.</small>
                </div>
            </div>
        </div>

        <!-- ===== المبلغ والعملة ===== -->
        <div class="section">
            <div class="section-header" style="display:flex;align-items:center;gap:10px;padding:12px 0;border-bottom:2px solid var(--border-gold);margin-bottom:15px;">
                <i class="fas fa-chart-line" style="font-size:1.3rem;color:var(--gold);"></i>
                <h2 style="font-size:1.1rem;color:var(--text-light);font-weight:700;"><?= $ui['financial_settlement'] ?></h2>
            </div>
            <div class="form-grid">
                <div class="field-group">
                    <label><i class="fas fa-dollar-sign"></i> <?= $ui['amount'] ?></label>
                    <input type="number" name="amount" id="amount" step="0.01" min="0.01" required placeholder="0.00">
                    <small style="display:block;margin-top:5px;color:#999;line-height:1.5;">المبلغ المراد تحصيله أو تحويله. يجب أن يكون أكبر من صفر. يُدعم الكسر العشري. تُطبَّق رسوم 2.5% تلقائياً على المبلغ الصافي.</small>
                </div>
                <div class="field-group">
                    <label><i class="fas fa-globe"></i> <?= $ui['currency'] ?></label>
                    <select name="currency" id="currency">
                        <option value="USD">USD - دولار أمريكي 🇺🇸</option>
                        <option value="EUR">EUR - يورو 🇪🇺</option>
                        <option value="GBP">GBP - جنيه إسترليني 🇬🇧</option>
                        <option value="AED" selected>AED - درهم إماراتي 🇦🇪</option>
                        <option value="SAR">SAR - ريال سعودي 🇸🇦</option>
                        <option value="KWD">KWD - دينار كويتي 🇰🇼</option>
                        <option value="BHD">BHD - دينار بحريني 🇧🇭</option>
                        <option value="QAR">QAR - ريال قطري 🇶🇦</option>
                        <option value="OMR">OMR - ريال عُماني 🇴🇲</option>
                        <option value="EGP">EGP - جنيه مصري 🇪🇬</option>
                        <option value="INR">INR - روبية هندية 🇮🇳</option>
                        <option value="CNY">CNY - يوان صيني 🇨🇳</option>
                        <option value="JPY">JPY - ين ياباني 🇯🇵</option>
                    </select>
                    <small style="display:block;margin-top:5px;color:#999;line-height:1.5;">عملة العملية. تأكد من أن البوابة المختارة تدعم هذه العملة قبل التنفيذ.</small>
                </div>
            </div>
        </div>

        <!-- ===== البروتوكول ===== -->
        <div class="section">
            <div class="section-header" style="display:flex;align-items:center;gap:10px;padding:12px 0;border-bottom:2px solid var(--border-gold);margin-bottom:15px;">
                <i class="fas fa-microchip" style="font-size:1.3rem;color:var(--gold);"></i>
                <h2 style="font-size:1.1rem;color:var(--text-light);font-weight:700;"><?= $ui['protocol_layer'] ?></h2>
                <span class="badge" style="background:rgba(76,175,80,0.2);padding:2px 12px;border-radius:20px;font-size:0.7rem;color:var(--success);margin-right:auto;">⚡ <?= $currentLang === 'en' ? '2 Protocols' : '2 بروتوكولات' ?></span>
            </div>
            
            <div class="form-grid">
                <!-- البروتوكول -->
                <div class="field-group" style="grid-column: span 2;">
                    <label><i class="fas fa-shield-alt"></i> <?= $ui['protocol_label'] ?></label>
                    <select name="protocol_layer" id="protocolLayer" onchange="const actionField = document.getElementById('actionField'); if (actionField) { actionField.style.display = (this.value === '101.1') ? 'block' : 'none'; }">
                        <option value="101.0" selected><?= $currentLang === 'en' ? '💳 Direct Withdrawal — Card Payment without Protocol' : '💳 سحب مباشر — دفع عبر البطاقة بدون بروتوكول' ?></option>
                        <option value="101.1"><?= $currentLang === 'en' ? '🔒 PROTOCOL 101.1 — Visa/Mastercard Hold then Settle' : '🔒 PROTOCOL 101.1 — Visa/Mastercard حجز ثم تسديد' ?></option>
                        <option value="201.3"><?= $currentLang === 'en' ? '🏢 PROTOCOL 201.3 — Corporate Direct Settlement' : '🏢 PROTOCOL 201.3 — Corporate Direct Settlement تسوية شركات' ?></option>
                    </select>
                </div>
                <div class="field-group" id="securityModeRow">
                    <label><i class="fas fa-shield-alt"></i> <?= $currentLang === 'en' ? 'Security Mode' : 'وضع الأمان' ?></label>
                    <select name="security_mode" id="securityMode">
                        <option value="2D" selected>2D - <?= $currentLang === 'en' ? 'Direct Payment' : 'دفع مباشر' ?></option>
                        <option value="3D">3D - <?= $currentLang === 'en' ? '3D Secure / OTP Authentication' : '3D سكيور / مصادقة OTP' ?></option>
                    </select>
                    <small style="display:block;margin-top:5px;color:#999;line-height:1.5;"><?= $currentLang === 'en' ? 'Select 2D or 3D mode for the gateway. 3D enables OTP-style authentication for compatible banks.' : 'اختر وضع الأمان 2D أو 3D للبوابة. 3D يُفعِّل مصادقة OTP للبنوك المتوافقة.' ?></small>
                </div>
                    <div id="protocolDesc" style="margin-top:10px;padding:12px;border-radius:10px;background:rgba(255,215,0,0.05);border:1px solid rgba(255,215,0,0.15);color:#ccc;font-size:0.92rem;line-height:1.6;"></div>
                </div>

                <!-- الإجراء — مخفي لـ 101.0، مرئي لـ 101.1 فقط -->
                <div class="field-group" id="actionField" style="display:none;">
                    <label><i class="fas fa-cogs"></i> <?= $ui['action_label'] ?></label>
                    <select name="action" id="protocolAction" onchange="updateProtocolUI()">
                        <option value="HOLD"><?= $currentLang === 'en' ? '🔒 HOLD — Reserve amount only, no capture' : '🔒 HOLD — حجز المبلغ فقط دون سحبه' ?></option>
                        <option value="SETTLEMENT"><?= $currentLang === 'en' ? '💰 SETTLEMENT — Capture/receive amount immediately' : '💰 SETTLEMENT — تسديد/استلام المبلغ فورياً' ?></option>
                    </select>
                    <small style="display:block;margin-top:5px;color:#999;line-height:1.5;"><strong>HOLD:</strong> يحجز المبلغ دون سحبه وتحصل على معرّد تفويض. <strong>SETTLEMENT:</strong> يسحب المبلغ فوراً مباشرةً أو عبر معرّد تفويض سابق.</small>
                </div>

                <!-- نوع الخدمة -->
                <div class="field-group">
                    <label><i class="fas fa-box"></i> <?= $ui['service_type'] ?></label>
                    <select name="service_type">
                        <option value="Consulting"><?= $currentLang === 'en' ? 'Consulting — Professional advisory services' : 'استشارات — خدمات استشارية مهنية' ?></option>
                        <option value="Books"><?= $currentLang === 'en' ? 'Books — Digital or printed products' : 'كتب — منتجات رقمية أو مطبوعة' ?></option>
                        <option value="Training"><?= $currentLang === 'en' ? 'Training — Online education & training' : 'دورات — تدريب وتعليم إلكتروني' ?></option>
                        <option value="Electronics"><?= $currentLang === 'en' ? 'Electronics — Devices & tech products' : 'إلكترونيات — أجهزة ومنتجات تقنية' ?></option>
                        <option value="Operational Services"><?= $currentLang === 'en' ? 'Operational Services — Maintenance & operations' : 'خدمات تشغيلية — صيانة وعمليات' ?></option>
                        <option value="AI"><?= $currentLang === 'en' ? 'AI — Artificial intelligence & data analytics' : 'ذكاء اصطناعي — حلول AI وتحليل بيانات' ?></option>
                        <option value="Software"><?= $currentLang === 'en' ? 'Software — Applications & systems' : 'برمجيات — تطبيقات وأنظمة' ?></option>
                    </select>
                    <small style="display:block;margin-top:5px;color:#999;line-height:1.5;">تصنيف الخدمة أو المنتج. يُستخدم في العقد الإلكتروني وسجلات المحاسبة.</small>
                </div>

                <!-- معرف التفويض — مخفي لـ 101.0، مرئي لـ 101.1 فقط -->
                <div class="field-group" id="authIdField" style="display:none; grid-column: span 2;">
                    <label><i class="fas fa-key"></i> <?= $ui['auth_id'] ?> <span style="color:#888;font-size:0.85rem;">(<?= $currentLang === 'en' ? 'for settlement on prior hold' : 'للتسديد على حجز سابق' ?>)</span></label>
                    <input type="text" name="authorization_id" id="authIdInput" placeholder="AUTH_xxxxxxxxxxxxxxxx">
                    <small style="display:block;margin-top:6px;color:#999;line-height:1.5;">إذا نفّذت <strong>HOLD</strong> سابقاً، أدخل معرّد التفويض (AUTH_xxx) هنا لتحويل الحجز إلى تسديد. اتركه فارغاً للتسديد المباشر. مثال: <code style="color:#FFD700;background:rgba(255,215,0,0.1);padding:1px 6px;border-radius:4px;">AUTH_3FA8B2C1D4E5F6A7</code></small>
                </div>

                <!-- OTP — للبروتوكول 101.0 فقط (بعد التنفيذ) -->
                <div class="field-group" id="otpSection" style="grid-column: span 2; display:<?= $otpPending ? 'block' : 'none' ?>;">
                    <div style="padding:14px;border-radius:12px;background:rgba(33,150,243,0.08);border:1px solid rgba(33,150,243,0.25);">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;color:#90CAF9;font-weight:700;">
                            <i class="fas fa-mobile-alt"></i>
                            <span>OTP Verification Required</span>
                        </div>
                        <p style="margin:0 0 10px 0;color:#c7d2fe;font-size:0.95rem;line-height:1.6;">
                            تم إرسال طلب التحقق إلى البنك. سيصل رمز التحقق إلى جوال العميل أو إلى نظام البنك، ويجب إدخاله هنا لإتمام المعالجة.
                        </p>
                                        <div style="display:flex;gap:8px;flex-wrap:wrap;">
                            <input type="text" name="otp_code" placeholder="<?= $currentLang === 'en' ? 'Enter 6-digit OTP code' : 'أدخل رمز OTP المكوّن من 6 أرقام' ?>" id="otpInput" maxlength="6" style="flex:1;min-width:220px;">
                            <button type="button" class="btn-secondary" onclick="bypassOTP()" style="min-width:110px;"><i class="fas fa-skip-forward"></i> <?= $ui['bypass_otp'] ?></button>
                            <button type="button" class="btn-secondary" onclick="resendOTP()" style="min-width:150px;"><i class="fas fa-paper-plane"></i> إعادة إرسال الرمز</button>
                        </div>
                        <input type="hidden" name="otp_action" id="otpActionInput" value="">
                        <input type="hidden" name="otp_challenge_id" id="otpChallengeId" value="<?= htmlspecialchars($otpChallengeIdValue) ?>">
                        <small style="display:block;margin-top:6px;color:#999;line-height:1.5;">الرمز الذي يُرسله البنك إلى جوال العميل (3D Secure). يظهر هذا الحقل بعد الضغط على تنفيذ إذا طلبه البنك. زر <strong>تجاوز OTP</strong> للمشغّلين المفوّضين فقط، وزر <strong>إعادة إرسال الرمز</strong> لإرسال الطلب مرة أخرى إلى جوال العميل.</small>
                        <?php if ($otpPending && $otpChallengeCode): ?>
                            <div style="margin-top:8px;padding:10px;border-radius:8px;background:rgba(255,215,0,0.12);color:#ffd966;font-size:0.95rem;line-height:1.4;">
                                <strong>رمز التحقق الفعلي:</strong> <?= htmlspecialchars($otpChallengeCode) ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($otpMessage): ?>
                            <div style="margin-top:8px;padding:10px;border-radius:8px;background:rgba(76,175,80,0.1);color:#90ee90;font-size:0.95rem;line-height:1.4;"><?= htmlspecialchars($otpMessage) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Approval Code — لبروتوكولات 101.1 و 201.3 (بعد التنفيذ) -->
                <div class="field-group" id="approvalCodeSection" style="grid-column: span 2; display:none;">
                    <label><i class="fas fa-certificate"></i> <?= $ui['approval_code'] ?> <span style="color:#f0ad4e;font-size:0.85rem;"><?= $ui['sent_after_execute'] ?></span></label>
                    <div style="display:flex;gap:8px;">
                        <input type="text" name="approval_code" id="approvalCodeInput"
                               placeholder="<?= $currentLang === 'en' ? 'Enter approval code (4 or 6 digits)' : 'أدخل كود الموافقة (4 أو 6 أرقام)' ?>"
                               maxlength="6"
                               style="flex:1;letter-spacing:4px;font-size:1.1rem;text-align:center;">
                        <button type="button" class="btn-secondary" onclick="bypassApproval()" style="min-width:110px;"><i class="fas fa-skip-forward"></i> <?= $ui['bypass'] ?></button>
                    </div>
                    <small style="display:block;margin-top:6px;color:#999;line-height:1.5;">الكود الصادر من البنك أو نظام التسوية بعد معالجة الطلب. مكوّن من <strong>4 أو 6 أرقام</strong>. يظهر هذا الحقل بعد الضغط على تنفيذ. زر <strong>تجاوز</strong> للمشغّلين المفوّضين فقط.</small>
                </div>

                <!-- Approval Code 101.1 القديم — مخفي للتوافق -->
                <input type="hidden" name="approval_code_101" id="approvalCode101Input">
                <!-- Approval Code 201.3 القديم — مخفي للتوافق -->
                <input type="hidden" name="approval_code_201" id="approvalCode201Input">
            </div>
                </div>
                <div class="form-grid">
                    <div class="field-group" style="grid-column: span 2;">
                        <label><i class="fas fa-tags"></i> <?= $ui['service_name'] ?></label>
                        <input type="text" name="contract_service_name" placeholder="<?= $currentLang === 'en' ? 'e.g. Financial Consulting / Training / Software' : 'مثال: استشارة مالية / دورة تدريبية / خدمة برمجية' ?>" value="<?= $currentLang === 'en' ? 'Digital Service' : 'خدمة رقمية' ?>">
                        <small style="display:block;margin-top:5px;color:#999;line-height:1.5;">الاسم الرسمي للخدمة أو المنتج المدفوع مقابله. يظهر في العقد والإيصال.</small>
                    </div>
                    <div class="field-group" style="grid-column: span 2;">
                        <label><i class="fas fa-align-left"></i> <?= $ui['service_desc'] ?></label>
                        <textarea name="contract_service_description" rows="3" placeholder="<?= $currentLang === 'en' ? 'Describe the service clearly' : 'اكتب وصفًا واضحًا للخدمة' ?>"><?= $currentLang === 'en' ? 'The client will be provided with the requested digital service as per the agreed terms.' : 'ستتم تزويد العميل بالخدمة الرقمية المطلوبة وفق الشروط المتفق عليها.' ?></textarea>
                        <small style="display:block;margin-top:5px;color:#999;line-height:1.5;">شرح تفصيلي للخدمة: ماذا ستُقدّم وكيف ومتى. يُحفظ ضمن العقد الإلكتروني المرتبط بالعملية.</small>
                    </div>
                    <div class="field-group">
                        <label><i class="fas fa-handshake"></i> <?= $ui['delivery_method'] ?></label>
                        <select name="contract_delivery_method">
                            <option value="إرسال إلكتروني"><?= $currentLang === 'en' ? '📧 Email — Direct email delivery' : '📧 إرسال إلكتروني — عبر البريد مباشرةً' ?></option>
                            <option value="منصة داخلية"><?= $currentLang === 'en' ? '🖥️ Internal Platform — System link' : '🖥️ منصة داخلية — رابط في النظام' ?></option>
                            <option value="تحميل مباشر"><?= $currentLang === 'en' ? '⬇️ Direct Download — Instant download link' : '⬇️ تحميل مباشر — رابط تنزيل فوري' ?></option>
                            <option value="إرسال عبر البريد"><?= $currentLang === 'en' ? '📮 Physical Mail — Physical shipping' : '📮 إرسال عبر البريد — شحن فيزيائي' ?></option>
                        </select>
                        <small style="display:block;margin-top:5px;color:#999;line-height:1.5;">الطريقة التي سيستلم بها العميل الخدمة بعد تأكيد الدفع.</small>
                    </div>
                    <div class="field-group">
                        <label><i class="fas fa-sticky-note"></i> <?= $ui['delivery_notes'] ?></label>
                        <input type="text" name="contract_delivery_notes" placeholder="<?= $currentLang === 'en' ? 'e.g. Will be sent within 24 hours' : 'مثال: سيتم الإرسال خلال 24 ساعة' ?>" value="<?= $currentLang === 'en' ? 'Service will be delivered after payment confirmation' : 'سيتم تسليم الخدمة بعد تأكيد الدفع' ?>">
                        <small style="display:block;margin-top:5px;color:#999;line-height:1.5;">تفاصيل إضافية عن موعد أو شروط التسليم. تظهر للعميل في إيصال الدفع.</small>
                    </div>
                </div>
                <div style="margin-top:10px;padding:10px;border-radius:10px;background:rgba(255,255,255,0.05);color:#cdbb6b;font-size:0.9rem;line-height:1.6;">
                    <strong><?= $currentLang === 'en' ? 'Notice:' : 'تنبيه:' ?></strong> <?= $currentLang === 'en' ? 'This contract will be linked to the financial transaction and included in the receipt.' : 'سيتم ربط هذا العقد مع العملية المالية وتوضيح الخدمة وطريقة الاستلام في الإيصال والصفحات المرتبطة.' ?>
                </div>
            </div>

            <div class="section" style="margin-top:15px;padding:15px;border:1px solid rgba(255,215,0,0.2);border-radius:14px;background:rgba(255,215,0,0.05);">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                    <i class="fas fa-file-contract" style="font-size:1.1rem;color:var(--gold);"></i>
                    <h3 style="font-size:1rem;color:var(--text-light);font-weight:700;margin:0;"><?= $ui['terms'] ?></h3>
                </div>
                <div style="padding:12px;border-radius:10px;background:rgba(255,255,255,0.05);color:#f5e1a3;font-size:0.92rem;line-height:1.8;">
                    <p style="margin:0 0 8px 0;"><strong>1.</strong> <?= $currentLang === 'en' ? 'The transaction is executed according to the service or product specified in the attached electronic contract.' : 'يتم تنفيذ العملية وفق الخدمة أو المنتج المحدد في العقد الإلكتروني المرفق.' ?></p>
                    <p style="margin:0 0 8px 0;"><strong>2.</strong> <?= $currentLang === 'en' ? 'The service or product is delivered according to the delivery method agreed upon in this contract.' : 'يتم تسليم الخدمة أو المنتج وفق طريقة الاستلام المتفق عليها في هذا العقد.' ?></p>
                    <p style="margin:0 0 8px 0;"><strong>3.</strong> <?= $currentLang === 'en' ? 'The customer may not request a refund or cancellation after payment confirmation, except in cases explicitly stated by the system or administration.' : 'لا يحق للعميل طلب استرجاع أو إلغاء بعد تأكيد الدفع، إلا في الحالات التي ينص عليها النظام أو الإدارة بشكل واضح.' ?></p>
                    <p style="margin:0;"><strong>4.</strong> <span style="color:#ff8c8c;font-weight:700;"><?= $currentLang === 'en' ? 'No Refund:' : 'عدم الاسترجاع:' ?></span> <?= $currentLang === 'en' ? 'Once payment is confirmed, the transaction is final and no refund or reversal will be accepted unless the administration approves a special exception.' : 'بمجرد تأكيد الدفع، تكون العملية نهائية ولا يتم قبول أي طلب استرجاع أو رد مبلغ، ما لم توافق الإدارة على استثناء خاص.' ?></p>
                </div>
                <label style="display:flex;align-items:center;gap:8px;margin-top:12px;color:#f5e1a3;font-size:0.92rem;">
                    <input type="checkbox" name="accept_terms" value="1" required style="accent-color:var(--gold);width:16px;height:16px;">
                    <?= $ui['agree_terms'] ?>
                </label>
                <small style="display:block;margin-top:6px;color:#999;line-height:1.5;">الموافقة على الشروط <strong>إلزامية</strong> قبل تنفيذ أي عملية. تُسجَّل الموافقة مع بيانات العملية لأغراض الامتثال القانوني.</small>
            </div>
            
            <!-- شرح ديناميكي للبروتوكول 101.1 -->
            <div id="protocol101Instructions" style="display:none;margin-top:15px;padding:15px;background:rgba(52,168,224,0.05);border:1px solid rgba(52,168,224,0.2);border-radius:12px;color:var(--text-light);">
                <h4 style="color:#34a8e0;margin-bottom:10px;"><i class="fas fa-info-circle"></i> شرح البروتوكول 101.1 — التفويض، الإشعار، التسوية</h4>
                <div style="font-size:0.9rem;line-height:1.7;">
                    <p>📌 <strong>الخطوة 1 — Authorization / HOLD:</strong> اختر HOLD ونفّذ. سيُحجز المبلغ ويُنشأ <strong>معرّف تفويض AUTH_xxx</strong>.</p>
                    <p style="margin-top:8px;">📌 <strong>الخطوة 2 — Advice / Approval:</strong> بعد الحجز، يتم إصدار إشعار الموافقة ويمكنك رؤية معرف التفويض أو كود الموافقة. تأكد من حفظها.</p>
                    <p style="margin-top:8px;">📌 <strong>الخطوة 3 — Settlement:</strong> اختر SETTLEMENT، أدخل AUTH_xxx في حقل "معرّف التفويض" إذا كان لديك، ثم نفّذ لسحب المبلغ المحجوز.</p>
                    <p style="margin-top:8px;color:#888;font-size:0.85rem;">💡 إذا لم يكن لديك معرف تفويض، يمكنك إجراء SETTLEMENT مباشر بدون AUTH، لكن الحجز السابق سيبقى منفصلاً.</p>
                </div>
            </div>

            <!-- حقول إضافية للبروتوكول 201.3 -->
            <div id="protocol2013Fields" style="display:none;margin-top:15px;padding:15px;background:rgba(76,175,80,0.05);border:1px solid rgba(76,175,80,0.2);border-radius:12px;">
                <h4 style="color:var(--success);margin-bottom:10px;"><i class="fas fa-building"></i> بيانات التسوية المؤسسية — Corporate Settlement</h4>
                <div style="margin-bottom:15px;padding:12px;background:rgba(76,175,80,0.1);border-radius:8px;border-left:3px solid var(--success);">
                    <label style="color:var(--success);font-weight:bold;"><i class="fas fa-certificate"></i> كود الموافقة النهائي (Approval Code)</label>
                    <input type="text" name="approval_code_201_corporate" placeholder="أدخل كود الموافقة لإتمام عملية التسوية" id="approvalCode201CorporateInput" style="margin-top:8px;">
                    <small style="display:block;margin-top:5px;color:#888;">يظهر بعد معالجة طلب التسوية من الطرف المقابل. مطلوب لإتمام العملية.</small>
                </div>
                <div class="form-grid">
                    <div class="field-group">
                        <label><i class="fas fa-coins"></i> Corporate Token</label>
                        <input type="text" name="corporate_token" placeholder="CORP_TOKEN_xxx">
                        <small style="display:block;margin-top:4px;color:#888;">رمز مصادقة الشركة الصادر من نظام التسوية المؤسسي.</small>
                    </div>
                    <div class="field-group">
                        <label><i class="fas fa-file-signature"></i> Billing Agreement ID</label>
                        <input type="text" name="billing_agreement_id" placeholder="BA-2026-xxx">
                        <small style="display:block;margin-top:4px;color:#888;">معرّف اتفاقية الفوترة المبرمة مسبقاً بين الشركتين.</small>
                    </div>
                    <div class="field-group">
                        <label><i class="fas fa-layer-group"></i> Settlement Batch ID</label>
                        <input type="text" name="settlement_batch_id" placeholder="BATCH-xxx">
                        <small style="display:block;margin-top:4px;color:#888;">معرّف دفعة التسوية لتجميع عدة عمليات في دفعة واحدة.</small>
                    </div>
                </div>
            </div>

            <div class="section" style="margin-top:15px;padding:15px;border:1px solid rgba(255,215,0,0.2);border-radius:14px;background:rgba(255,215,0,0.05);">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                    <i class="fas fa-file-contract" style="font-size:1.1rem;color:var(--gold);"></i>
                    <h3 style="font-size:1rem;color:var(--text-light);font-weight:700;margin:0;"><?= $ui['e_contract'] ?></h3>
        <!-- ===== المحافظ ===== -->
        <div class="section">
            <div class="section-header" style="display:flex;align-items:center;gap:10px;padding:12px 0;border-bottom:2px solid var(--border-gold);margin-bottom:15px;">
                <i class="fas fa-wallet" style="font-size:1.3rem;color:var(--gold);"></i>
                <h2 style="font-size:1.1rem;color:var(--text-light);font-weight:700;"><?= $ui['wallet_dist'] ?></h2>
            </div>
            <div style="margin-bottom:12px;padding:10px 12px;background:rgba(255,215,0,0.05);border:1px solid rgba(255,215,0,0.15);border-radius:10px;color:#ccc;font-size:0.9rem;line-height:1.6;">
                حدّد محافظ العملات الرقمية التي سيُحوَّل إليها المبلغ بعد إتمام العملية. يمكن توزيعه على أكثر من محفظة بنسب مئوية مجموعها 100%.
            </div>
            <div class="form-grid">
                <div class="field-group" style="grid-column: span 2;">
                    <div id="walletsContainer">
                        <?php for ($i = 1; $i <= 2; $i++): ?>
                            <div class="wallet-row">
                                <strong>المحفظة <?= $i ?></strong>
                                <select name="wallet_network_<?= $i ?>" title="شبكة الإرسال">
                                    <option value="TRC20">TRC20 (Tron) — رسوم منخفضة</option>
                                    <option value="ERC20">ERC20 (Ethereum)</option>
                                    <option value="BEP20">BEP20 (BSC)</option>
                                    <option value="SOL">SOL (Solana)</option>
                                    <option value="BTC">BTC (Bitcoin)</option>
                                    <option value="XRP">XRP (Ripple)</option>
                                </select>
                                <select name="wallet_currency_<?= $i ?>" title="نوع العملة الرقمية">
                                    <option value="USDT" <?= $i==1?'selected':'' ?>>USDT — دولار مستقر</option>
                                    <option value="BTC">BTC — بيتكوين</option>
                                    <option value="ETH">ETH — إيثريوم</option>
                                    <option value="USDC">USDC — دولار مستقر</option>
                                    <option value="BNB">BNB — باينانس</option>
                                    <option value="XRP">XRP — ريبل</option>
                                    <option value="SOL">SOL — سولانا</option>
                                </select>
                                <input type="number" name="wallet_percent_<?= $i ?>" value="<?= $i==1?100:0 ?>" min="0" max="100" placeholder="%" oninput="updatePercent()" title="النسبة المئوية من إجمالي المبلغ">
                                <input type="text" name="wallet_address_<?= $i ?>" placeholder="عنوان المحفظة الكامل" value="<?= $i==1?'TNN4739UE29epkKYNRLnRtviGfCWKsupTT':'' ?>" title="عنوان المحفظة الرقمية">
                            </div>
                        <?php endfor; ?>
                    </div>
                    <button type="button" class="btn-add-wallet" onclick="addWallet()"><i class="fas fa-plus"></i> <?= $ui['add_wallet'] ?></button>
                    <div style="margin-top:10px;"><?= $ui['total_percent'] ?>: <span id="totalPercent" style="color:var(--gold);font-weight:bold;">100</span>% <span style="color:#999;font-size:0.85rem;">(<?= $ui['must_100'] ?? ($currentLang === 'en' ? 'Must equal 100%' : 'يجب أن تساوي 100%') ?>)</span></div>
                </div>
            </div>
        </div>

        <button type="button" class="btn-submit" onclick="executeViaProtocol(this)">
            <i class="fas fa-play"></i> <?= $ui['execute_btn'] ?>
            <i class="fas fa-arrow-left"></i>
        </button>

    </form>

</div>

<!-- ===== JavaScript فائق السرعة ===== -->
<script>
// ============================================================
// تنفيذ العملية عبر البروتوكول — منطق الحقول الثلاثة
// ============================================================
function executeViaProtocol(btn) {
    const form = (btn && btn.closest && btn.closest('form')) || document.getElementById('paymentForm');
    if (!form) {
        if (typeof showToast === 'function') {
            showToast('⚠ لم يتم العثور على النموذج المطلوب، يرجى إعادة تحميل الصفحة', 'error');
        }
        return;
    }

    const protocol = document.getElementById('protocolLayer')?.value || '';
    const selectedGateway = document.getElementById('selectedGateway')?.value || '';
    const selectedCardType = _currentCardType || 'LIVE';
    const useAjax = protocol === '101.0' || protocol === 'SIMPLE_WITHDRAWAL';

    // ── تحقق من اختيار بوابة صالحة ومتوافقة مع نوع البطاقة ──
    if (!selectedGateway) {
        if (typeof showToast === 'function') {
            showToast('⚠ يرجى اختيار بوابة الدفع أولاً.', 'error');
        }
        return;
    }

    const LIVE_CARD_BRANDS = ['VISA', 'MASTERCARD', 'AMEX', 'AMERICAN EXPRESS', 'DISCOVER', 'JCB', 'UNIONPAY', 'MADA', 'MEEZA', 'VERVE'];
    const CARD_TYPE_ALIASES = {
        'LIVE': ['LIVE', ...LIVE_CARD_BRANDS, 'EFTPOS', 'PROXIMITY', 'TPDU'],
        'MASTERCARD': ['MASTERCARD', 'LIVE'],
        'VERVE': ['VERVE', 'LIVE'],
        'EFTPOS': ['EFTPOS', 'LIVE'],
        'PROXIMITY': ['PROXIMITY', 'NFC', 'CONTACTLESS', 'LIVE', ...LIVE_CARD_BRANDS],
        'TPDU': ['TPDU', 'ISO8583', 'LIVE', ...LIVE_CARD_BRANDS],
        'APPLE_PAY': ['APPLE_PAY', 'APPLE PAY', 'LIVE', ...LIVE_CARD_BRANDS],
        'GOOGLE_PAY': ['GOOGLE_PAY', 'GOOGLE PAY', 'LIVE', ...LIVE_CARD_BRANDS],
        'CLOUD': ['CLOUD', 'TOKENIZED', 'LIVE', ...LIVE_CARD_BRANDS]
    };

    const gatewayCardTypes = document.querySelector('.gateway-card[data-code="' + selectedGateway + '"]')?.dataset?.cardTypes || '[]';
    let allowedCardTypes = [];
    try {
        allowedCardTypes = JSON.parse(gatewayCardTypes);
    } catch (e) {
        allowedCardTypes = [];
    }
    allowedCardTypes = allowedCardTypes.map(type => String(type || '').trim().toUpperCase()).filter(Boolean);

    const aliasLookup = (CARD_TYPE_ALIASES[selectedCardType] || [selectedCardType]).map(type => String(type || '').trim().toUpperCase());
    const isCompatible = allowedCardTypes.length === 0 || aliasLookup.some(alias => allowedCardTypes.includes(alias));
    if (!isCompatible) {
        if (typeof showToast === 'function') {
            showToast('⚠ لا تدعم هذه البوابة نوع البطاقة المحدد. اختر بوابة أخرى أو نوع بطاقة مختلف.', 'error');
        }
        return;
    }

    // ── 101.1 و 201.3 : يطلبان Approval Code بعد أول ضغطة ──
    if (protocol === '101.1' || protocol === '201.3') {
        const approvalSection = document.getElementById('approvalCodeSection');
        const approvalInput   = document.getElementById('approvalCodeInput');
        if (approvalSection && approvalInput) {
            if (approvalSection.style.display === 'none') {
                // أول ضغطة — أظهر الحقل
                approvalSection.style.display = 'block';
                approvalSection.scrollIntoView({behavior:'smooth', block:'center'});
                approvalInput.focus();
                return;
            }
            const code = approvalInput.value.trim();
            if (code === '') {
                // الحقل ظاهر لكن فارغ — نبّه
                approvalInput.focus();
                approvalInput.style.borderColor = '#d9534f';
                approvalInput.placeholder = '⚠ أدخل الكود أو اضغط تجاوز';
                return;
            }
            // مزامنة مع الحقول الخفية للـ backend
            const h101 = document.getElementById('approvalCode101Input');
            const h201 = document.getElementById('approvalCode201Input');
            if (h101) h101.value = code;
            if (h201) h201.value = code;
        }
    }

    // ── 101.0 (بطاقة عادية) : يطلب OTP بعد أول ضغطة ──
    if (protocol === '101.0') {
        const otpSection = document.getElementById('otpSection');
        const otpInput   = document.getElementById('otpInput');
        if (otpSection && otpSection.style.display === 'none') {
            otpSection.style.display = 'block';
            otpSection.scrollIntoView({behavior:'smooth', block:'center'});
            otpInput?.focus();
            return;
        }
    }

    if (useAjax) {
        const fd = new FormData(form);
        fd.set('execute_operation', '1');
        fd.set('ajax_request', '1');

        submitAjaxForm(fd);
        return;
    }

    // ── إرسال الـ form إلى نفس الصفحة لعرض النتيجة مباشرة ──
    const tmpForm = document.createElement('form');
    tmpForm.method = 'POST';
    tmpForm.action = 'index.php';
    tmpForm.target = '_self';

    form.querySelectorAll('input, select, textarea').forEach(el => {
        if (!el.name) return;
        const inp = document.createElement('input');
        inp.type  = 'hidden';
        inp.name  = el.name;
        inp.value = el.type === 'checkbox' ? (el.checked ? '1' : '') : el.value;
        tmpForm.appendChild(inp);
    });

    // تمرير نوع البطاقة المختار
    const ctInp   = document.createElement('input');
    ctInp.type    = 'hidden';
    ctInp.name    = 'card_type_selected';
    ctInp.value   = _currentCardType || 'LIVE';
    tmpForm.appendChild(ctInp);

    // إشارة واضحة إلى أن العملية يجب معالجتها من قبل PHP
    const execInp = document.createElement('input');
    execInp.type = 'hidden';
    execInp.name = 'execute_operation';
    execInp.value = '1';
    tmpForm.appendChild(execInp);

    // تمرير.token CSRF
    const csrfInp = document.createElement('input');
    csrfInp.type = 'hidden';
    csrfInp.name = 'csrf_token';
    csrfInp.value = form.querySelector('input[name="csrf_token"]')?.value || '';
    tmpForm.appendChild(csrfInp);

    document.body.appendChild(tmpForm);
    tmpForm.submit();
    document.body.removeChild(tmpForm);
}

function submitAjaxForm(fd) {
    const otpSection = document.getElementById('otpSection');
    const otpInput = document.getElementById('otpInput');
    const otpChallengeId = document.getElementById('otpChallengeId');

    fetch(window.location.pathname, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
    }).then(r => r.json()).then(data => {
        if (!data) {
            showToast('خطأ في استجابة الخادم', 'error');
            return;
        }

        if (data.requires_otp) {
            if (otpSection) {
                otpSection.style.display = 'block';
                otpSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            if (otpChallengeId && data.otp_challenge_id) {
                otpChallengeId.value = data.otp_challenge_id;
            }
            if (otpInput) {
                otpInput.focus();
                otpInput.value = '';
            }
            showToast(data.message || 'يرجى إدخال رمز OTP المرسل لك من البنك', 'info');
            return;
        }

        if (data.success) {
            showToast(data.message || 'تم تنفيذ السحب بنجاح', 'success');
            if (otpSection) {
                otpSection.style.display = 'none';
            }
            return;
        }

        let msg = data.message || 'فشل تنفيذ السحب';
        if (data.available_balance !== undefined) {
            msg += ' — الرصيد المتاح: ' + parseFloat(data.available_balance).toFixed(2) + ' ' + (document.getElementById('currency')?.value || '');
        }
        showToast(msg, 'error');
    }).catch(err => {
        console.error(err);
        showToast('فشل الاتصال بالخادم', 'error');
    });
}

// ============================================================
// تجاوز Approval Code (للمشغّلين المفوّضين)
// ============================================================
function bypassApproval() {
    const input = document.getElementById('approvalCodeInput');
    if (input) {
        input.value = '000000';
        input.style.borderColor = 'var(--gold)';
    }
    // إعادة الضغط على الزر
    document.querySelector('.btn-submit')?.click();
}

// ============================================================
// شرح ديناميكي + إظهار/إخفاء الحقول عند تغيير البروتوكول
// ============================================================
const isProtocolEnglish = <?= json_encode($currentLang === 'en') ?>;
const PROTOCOL_DESCRIPTIONS = {
    '101.0': isProtocolEnglish ? '💳 <strong>Direct Withdrawal — Card Payment:</strong> Captures amount immediately via Visa/Mastercard <strong>without protocol</strong>. CVV required. After pressing Execute, the bank may send an <strong>OTP</strong> challenge to the customer phone as an authentication advice step.' : '💳 <strong>سحب مباشر — دفع عبر البطاقة:</strong> يسحب المبلغ فورياً عبر شبكة Visa/Mastercard <strong>بدون بروتوكول</strong>. يتطلب CVV. بعد الضغط على تنفيذ قد يرسل البنك <strong>OTP</strong> إلى جوال العميل كخطوة تحقق/إشعار.',
    '101.1': isProtocolEnglish ? '🔒 <strong>PROTOCOL 101.1 — Authorization, Advice, Settlement:</strong> First perform an authorization hold, then use the received authorization ID to settle the amount. An <strong>Approval Code</strong> or advice step may be required after execution.' : '🔒 <strong>PROTOCOL 101.1 — التفويض، الإشعار، التسوية:</strong> قم أولاً بعملية حجز Authorization، ثم استخدم معرف التفويض لتسوية المبلغ. قد يتطلب الأمر كود موافقة أو خطوة إشعار بعد التنفيذ.',
    '201.3': isProtocolEnglish ? '🏢 <strong>PROTOCOL 201.3 — Corporate Settlement Advice:</strong> Corporate settlement path where an approval code is used to complete the settlement after the initial request. Designed for business-to-business financial advice and settlement flows.' : '🏢 <strong>PROTOCOL 201.3 — تسوية مؤسسية وإشعار:</strong> مسار التسوية المؤسسية حيث يستخدم كود الموافقة لإتمام التسوية بعد الطلب الأولي. مصمم لتدفقات الإشعار والتسوية بين الشركات.',
    'SIMPLE_WITHDRAWAL': isProtocolEnglish ? '🔄 <strong>Simple Withdrawal:</strong> Direct withdrawal without a complex protocol. For testing environments only.' : '🔄 <strong>Simple Withdrawal — سحب مباشر:</strong> سحب بسيط بدون بروتوكول معقد. للاستخدام في بيئات الاختبار فقط.'
};

function updateProtocolUI() {
    const protocol = document.getElementById('protocolLayer')?.value || '';
    const descBox = document.getElementById('protocolDesc');
    if (descBox) {
        descBox.innerHTML = PROTOCOL_DESCRIPTIONS[protocol] || '';
    }

    const cvvField = document.getElementById('cvvField');
    if (cvvField) {
        const noProtocolCvv = (protocol === '101.1' || protocol === '201.3');
        const noCardTypeCvv = ['CLOUD', 'NFC', 'APPLE_PAY', 'GOOGLE_PAY'].includes(_currentCardType);
        cvvField.style.display = (noProtocolCvv || noCardTypeCvv) ? 'none' : 'block';
    }

    const actionField = document.getElementById('actionField');
    if (actionField) {
        actionField.style.display = (protocol === '101.1') ? 'block' : 'none';
    }

    const authIdField = document.getElementById('authIdField');
    const actionSelect = document.getElementById('protocolAction');
    const isSettlementAction = actionSelect ? actionSelect.value === 'SETTLEMENT' : false;
    if (authIdField) {
        authIdField.style.display = (protocol === '101.1' && isSettlementAction) ? 'block' : 'none';
    }

    const otpSection = document.getElementById('otpSection');
    if (otpSection && protocol !== '101.0') {
        otpSection.style.display = 'none';
    }

    const approvalSection = document.getElementById('approvalCodeSection');
    if (approvalSection) {
        approvalSection.style.display = (protocol === '101.1' || protocol === '201.3') ? 'block' : 'none';
        const inp = document.getElementById('approvalCodeInput');
        if (inp) {
            inp.value = '';
            inp.style.borderColor = '';
            inp.placeholder = 'أدخل كود الموافقة (4 أو 6 أرقام)';
        }
    }

    const box101 = document.getElementById('protocol101Instructions');
    if (box101) {
        box101.style.display = (protocol === '101.1') ? 'block' : 'none';
    }

    const box201 = document.getElementById('protocol2013Fields');
    if (box201) {
        box201.style.display = (protocol === '201.3') ? 'block' : 'none';
    }
}

// تشغيل عند تحميل الصفحة
document.addEventListener('DOMContentLoaded', updateProtocolUI);

// ============================================================
// اختيار نوع البطاقة — يُظهر/يُخفي الحقول المناسبة
// ============================================================
const CARD_TYPE_CONFIG = {
    LIVE: {
        showCardFields : true,
        showCvv        : true,
        showNfcBtn     : false,
        showApplePay   : false,
        showGooglePay  : false,
        selectedId     : 'type_live',
        borderColor    : '#4CAF50',
    },
    CLOUD: {
        showCardFields : true,
        showCvv        : false,       // CLOUD = بدون CVV
        showNfcBtn     : true,        // أظهر زر NFC للقراءة السحابية
        showApplePay   : false,
        showGooglePay  : false,
        selectedId     : 'type_cloud',
        borderColor    : '#9C27B0',
    },
    NFC: {
        showCardFields : true,
        showCvv        : false,       // NFC = بدون CVV
        showNfcBtn     : true,
        showApplePay   : false,
        showGooglePay  : false,
        selectedId     : 'type_nfc',
        borderColor    : '#2196F3',
    },
    APPLE_PAY: {
        showCardFields : false,       // بدون حقول بطاقة
        showCvv        : false,
        showNfcBtn     : false,
        showApplePay   : true,
        showGooglePay  : false,
        selectedId     : 'type_apple',
        borderColor    : '#ffffff',
    },
    GOOGLE_PAY: {
        showCardFields : false,
        showCvv        : false,
        showNfcBtn     : false,
        showApplePay   : false,
        showGooglePay  : true,
        selectedId     : 'type_google',
        borderColor    : '#4285F4',
    },
    MASTERCARD: {
        showCardFields : true,
        showCvv        : true,
        showNfcBtn     : false,
        showApplePay   : false,
        showGooglePay  : false,
        selectedId     : 'type_mastercard',
        borderColor    : '#eb001b',
    },
    VERVE: {
        showCardFields : true,
        showCvv        : true,
        showNfcBtn     : false,
        showApplePay   : false,
        showGooglePay  : false,
        selectedId     : 'type_verve',
        borderColor    : '#18802b',
    },
    EFTPOS: {
        showCardFields : true,
        showCvv        : true,
        showNfcBtn     : false,
        showApplePay   : false,
        showGooglePay  : false,
        selectedId     : 'type_eftpos',
        borderColor    : '#6a1b9a',
    },
    PROXIMITY: {
        showCardFields : true,
        showCvv        : false,
        showNfcBtn     : true,
        showApplePay   : false,
        showGooglePay  : false,
        selectedId     : 'type_proximity',
        borderColor    : '#00bfa5',
    },
    TPDU: {
        showCardFields : true,
        showCvv        : true,
        showNfcBtn     : false,
        showApplePay   : false,
        showGooglePay  : false,
        selectedId     : 'type_tpdu',
        borderColor    : '#ff9800',
    },
};

let _currentCardType = 'LIVE';

function selectCardType(type) {
    _currentCardType = type;
    const cfg = CARD_TYPE_CONFIG[type] || CARD_TYPE_CONFIG.LIVE;

    // ── تحديث hidden input ──
    document.querySelectorAll('input[name="card_type"]').forEach(r => {
        r.checked = (r.value === type);
    });

    // ── تمييز البطاقة المختارة ──
    Object.values(CARD_TYPE_CONFIG).forEach(c => {
        const el = document.getElementById(c.selectedId);
        if (!el) return;
        el.style.outline     = '';
        el.style.transform   = '';
        el.style.boxShadow   = '';
    });
    const sel = document.getElementById(cfg.selectedId);
    if (sel) {
        sel.style.outline   = `2px solid ${cfg.borderColor}`;
        sel.style.transform = 'scale(1.04)';
        sel.style.boxShadow = `0 0 18px ${cfg.borderColor}44`;
    }

    // ── حقول البطاقة ──
    const cardGrid   = document.getElementById('cardFieldsGrid');
    const cvvField   = document.getElementById('cvvField');
    const nfcBtn     = document.getElementById('nfcScanBtn');
    const appleBox   = document.getElementById('applePaySection');
    const googleBox  = document.getElementById('googlePaySection');
    const nfcStatus  = document.getElementById('nfcStatus');

    if (cardGrid)  cardGrid.style.display  = cfg.showCardFields  ? '' : 'none';
    if (cvvField)  cvvField.style.display  = cfg.showCvv         ? 'block' : 'none';
    if (nfcBtn)    nfcBtn.style.display    = cfg.showNfcBtn      ? 'flex'  : 'none';
    if (appleBox)  appleBox.style.display  = cfg.showApplePay    ? 'block' : 'none';
    if (googleBox) googleBox.style.display = cfg.showGooglePay   ? 'block' : 'none';
    if (nfcStatus && !cfg.showNfcBtn) nfcStatus.style.display    = 'none';

    const cardTypeInput = document.getElementById('cardTypeSelectedInput');
    if (cardTypeInput) cardTypeInput.value = type;

    // ── تحديث حقول التوكن حسب نوع البطاقة ──
    const cloudTokenSection = document.getElementById('cloudTokenSection');
    const applePayTokenSection = document.getElementById('applePayTokenSection');
    const googlePayTokenSection = document.getElementById('googlePayTokenSection');

    if (cloudTokenSection) cloudTokenSection.style.display = type === 'CLOUD' ? 'block' : 'none';
    if (applePayTokenSection) applePayTokenSection.style.display = type === 'APPLE_PAY' ? 'block' : 'none';
    if (googlePayTokenSection) googlePayTokenSection.style.display = type === 'GOOGLE_PAY' ? 'block' : 'none';

    // ── تحديث حقل CVV حسب البروتوكول أيضاً ──
    // (يُطبَّق منطق البروتوكول فوق منطق نوع البطاقة)
    const protocol = document.getElementById('protocolLayer')?.value || '';
    if (cvvField && (protocol === '101.1' || protocol === '201.3')) {
        cvvField.style.display = 'none';
    }
}

// تفعيل LIVE Card افتراضياً عند تحميل الصفحة
document.addEventListener('DOMContentLoaded', () => selectCardType('LIVE'));
let _binTimer = null;
let _lastBin  = '';

function triggerBinLookup(rawValue) {
    const digits = rawValue.replace(/\D/g, '');
    if (digits.length < 6) {
        hideBinInfo();
        return;
    }
    const bin6 = digits.substring(0, 6);
    if (bin6 === _lastBin) return;   // لا تعيد الاستعلام على نفس الـ BIN
    _lastBin = bin6;

    clearTimeout(_binTimer);
    _binTimer = setTimeout(() => fetchBin(bin6), 350);
}

function fetchBin(bin6) {
    document.getElementById('binLoading').style.display = 'block';
    document.getElementById('binInfoCard').style.display = 'none';

    fetch('<?= SITE_URL ?>/api/bin_lookup.php?bin=' + encodeURIComponent(bin6))
        .then(r => r.json())
        .then(data => {
            document.getElementById('binLoading').style.display = 'none';
            if (data.success) showBinInfo(data);
        })
        .catch(() => {
            document.getElementById('binLoading').style.display = 'none';
        });
}

function showBinInfo(d) {
    const card    = document.getElementById('binInfoCard');
    const icon    = document.getElementById('binSchemeIcon');
    const iconLg  = document.getElementById('binIconLarge');
    const scheme  = document.getElementById('binScheme');
    const typeBdg = document.getElementById('binTypeBadge');
    const prepaid = document.getElementById('binPrepaid');
    const bankRow = document.getElementById('binBankRow');
    const bankEl  = document.getElementById('binBank');
    const cntRow  = document.getElementById('binCountryRow');
    const cntEl   = document.getElementById('binCountry');

    // أيقونة صغيرة داخل حقل الإدخال
    if (icon) icon.innerHTML = `<i class="${d.icon}" style="color:${d.color}"></i>`;

    // أيقونة كبيرة
    if (iconLg) iconLg.innerHTML = `<i class="${d.icon}" style="color:${d.color}"></i>`;

    // الشبكة
    if (scheme) scheme.textContent = d.brand || d.scheme;
    scheme.style.color = d.color || 'var(--gold)';

    // نوع البطاقة
    const typeMap = {
        'credit':  { label: 'Credit', bg: 'rgba(76,175,80,0.2)',   color: '#4CAF50' },
        'debit':   { label: 'Debit',  bg: 'rgba(33,150,243,0.2)',  color: '#2196F3' },
        'prepaid': { label: 'Prepaid',bg: 'rgba(240,173,78,0.2)',  color: '#f0ad4e' },
    };
    const tm = typeMap[d.type?.toLowerCase()] || { label: d.type || '—', bg: 'rgba(255,255,255,0.1)', color: '#ccc' };
    if (typeBdg) {
        typeBdg.textContent = tm.label;
        typeBdg.style.background = tm.bg;
        typeBdg.style.color = tm.color;
    }

    // Prepaid badge
    if (prepaid) prepaid.style.display = d.prepaid ? 'inline-block' : 'none';

    // البنك
    if (d.bank) {
        bankEl.textContent = d.bank;
        bankRow.style.display = 'block';
    } else {
        bankRow.style.display = 'none';
    }

    // الدولة
    if (d.country || d.country_name) {
        const flag = d.country ? getFlagEmoji(d.country) : '';
        cntEl.textContent = flag + ' ' + (d.country_name || d.country);
        cntRow.style.display = 'block';
    } else {
        cntRow.style.display = 'none';
    }

    card.style.display = 'block';
    card.style.borderColor = d.color ? d.color + '55' : 'rgba(255,215,0,0.2)';
}

function hideBinInfo() {
    _lastBin = '';
    document.getElementById('binInfoCard').style.display  = 'none';
    document.getElementById('binLoading').style.display   = 'none';
    const icon = document.getElementById('cardSchemeIcon');
    if (icon) icon.innerHTML = '';
}

function getFlagEmoji(code) {
    if (!code || code.length !== 2) return '';
    const base = 0x1F1E6;
    return String.fromCodePoint(base + code.toUpperCase().charCodeAt(0) - 65)
         + String.fromCodePoint(base + code.toUpperCase().charCodeAt(1) - 65);
}
function startNFCScan() {
    const btn        = document.getElementById('nfcScanBtn');
    const statusBox  = document.getElementById('nfcStatus');
    const statusText = document.getElementById('nfcStatusText');

    // فحص دعم المتصفح
    if (!('NDEFReader' in window)) {
        statusBox.style.display  = 'block';
        statusBox.style.background = 'rgba(217,83,79,0.1)';
        statusBox.style.borderColor = 'rgba(217,83,79,0.3)';
        statusBox.style.color = '#EF9A9A';
        statusText.innerHTML = '❌ NFC غير مدعوم في هذا المتصفح. استخدم Chrome على Android مع HTTPS.';
        return;
    }

    // تفعيل حالة المسح
    statusBox.style.display   = 'block';
    statusBox.style.background  = 'rgba(33,150,243,0.1)';
    statusBox.style.borderColor = 'rgba(33,150,243,0.3)';
    statusBox.style.color = '#90CAF9';
    statusText.innerHTML  = '<i class="fas fa-wifi fa-spin"></i> قرّب البطاقة من الجهاز...';
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> جارٍ المسح...'; }

    // استخدام NFCSystem من main.js
    if (typeof NFCSystem !== 'undefined') {
        NFCSystem.startScan(
            function(data) {
                // نجاح — ملء حقول البطاقة
                NFCSystem.fillCardFields(data);
                // BIN lookup بعد القراءة
                if (data.pan) triggerBinLookup(data.pan);
                statusBox.style.background  = 'rgba(76,175,80,0.1)';
                statusBox.style.borderColor = 'rgba(76,175,80,0.3)';
                statusBox.style.color = '#A5D6A7';
                statusText.innerHTML  = '✅ تم قراءة البطاقة بنجاح! تحقق من البيانات أدناه.';
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-wifi"></i> مسح NFC'; }
            },
            function(error) {
                statusBox.style.background  = 'rgba(217,83,79,0.1)';
                statusBox.style.borderColor = 'rgba(217,83,79,0.3)';
                statusBox.style.color = '#EF9A9A';
                statusText.innerHTML  = '❌ فشل المسح: ' + error;
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-wifi"></i> مسح NFC'; }
            }
        );
    } else {
        // fallback مباشر بدون main.js
        (async () => {
            try {
                const reader = new NDEFReader();
                await reader.scan();
                reader.addEventListener('reading', ({ message, serialNumber }) => {
                    let pan = null, expiry = null;
                    for (const record of message.records) {
                        if (record.type === 'text') {
                            const text = new TextDecoder(record.encoding || 'utf-8').decode(record.data);
                            const m1 = text.match(/\b\d{16}\b/);    if (m1) pan    = m1[0];
                            const m2 = text.match(/\b(0[1-9]|1[0-2])\/\d{2}\b/); if (m2) expiry = m2[0];
                        }
                    }
                    if (pan) {
                        const p = document.getElementById('cardPan');
                        if (p) { p.value = pan.replace(/(.{4})/g,'$1 ').trim(); }
                    }
                    if (expiry) {
                        const e = document.getElementById('cardExpiry');
                        if (e) e.value = expiry;
                    }
                    statusBox.style.background  = 'rgba(76,175,80,0.1)';
                    statusBox.style.borderColor = 'rgba(76,175,80,0.3)';
                    statusBox.style.color = '#A5D6A7';
                    statusText.innerHTML  = '✅ تم قراءة البطاقة بنجاح!';
                    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-wifi"></i> مسح NFC'; }
                });
            } catch(e) {
                statusText.innerHTML = '❌ ' + e.message;
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-wifi"></i> مسح NFC'; }
            }
        })();
    }
}

function formatCard(el) {
    if (!el) return;
    let val = el.value.replace(/\D/g, '').slice(0, 16);
    el.value = val.replace(/(.{4})/g, '$1 ').trim();
}

function formatExpiry(el) {
    let val = el.value.replace(/\D/g, '').slice(0, 4);
    if (val.length >= 2) {
        let month = parseInt(val.slice(0, 2));
        if (month > 12) val = '12' + val.slice(2);
        if (val.length > 2) val = val.slice(0, 2) + '/' + val.slice(2);
    }
    el.value = val;
}

// ============================================================
// اختيار البوابة
// ============================================================
function selectGateway(code) {
    // ضبط الحقول المخفية
    const selectedGateway     = document.getElementById('selectedGateway');
    const selectedGatewayType = document.getElementById('selectedGatewayType');
    if (selectedGateway)     selectedGateway.value     = code;
    if (selectedGatewayType) selectedGatewayType.value = code;

    // ضبط radio المختار
    const radio = document.getElementById('gw_radio_' + code);
    if (radio) radio.checked = true;

    // إزالة التحديد البصري من جميع الكروت
    document.querySelectorAll('.gateway-card').forEach(card => {
        card.classList.remove('gw-selected');
    });

    // تحديد الكارت المختار
    const target = document.querySelector('.gateway-card[data-code="' + code + '"]');
    if (target) {
        target.classList.add('gw-selected');
        target.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    // بوابات المحافظ — لا تدعم 2D/3D
    const WALLETS = ['binance','coinbase_ex','kraken','bybit','okx','kucoin','gate_io','gemini',
                     'bitfinex','mexc','trust_wallet','metamask','phantom','ledger_live','exodus',
                     'electrum','coinbase_wallet','zengo','rabby','safepal'];
    // بوابات نظام مصادقة خاص — لا تظهر Security Level
    const NO_SEC  = ['paypal','wise','moonpay','transak','banxa','mercuryo','simplex','ramp'];
    // بوابات بطاقات مباشرة — تدعم 2D/3D
    const CARD_GW = ['stripe','checkout','paytabs','authorizenet','myfatoorah'];

    // إظهار/إخفاء Security Mode حسب البوابة
    const secRow = document.getElementById('securityModeRow') || document.getElementById('securityMode')?.closest('.field-group');
    if (secRow) {
        const showSec = CARD_GW.includes(code) && !NO_SEC.includes(code) && !WALLETS.includes(code);
        secRow.style.display = showSec ? '' : 'none';
    }

    // توست تأكيد
    const name = target?.querySelector('.gw-name')?.textContent?.trim() || code;
    showToast('✅ تم اختيار بوابة: ' + name, 'success');
}

// ============================================================
// دوال المحافظ
// ============================================================
let walletCount = 2;

function addWallet() {
    if (walletCount >= 6) return;
    walletCount++;
    const container = document.getElementById('walletsContainer');
    const row = document.createElement('div');
    row.className = 'wallet-row';
    row.innerHTML = `
        <strong>المحفظة ${walletCount}</strong>
        <select name="wallet_network_${walletCount}">
            <option value="TRC20">TRC20 (Tron)</option>
            <option value="ERC20">ERC20 (Ethereum)</option>
            <option value="BEP20">BEP20 (BSC)</option>
            <option value="SOL">SOL (Solana)</option>
            <option value="BTC">BTC (Bitcoin)</option>
            <option value="XRP">XRP (Ripple)</option>
        </select>
        <select name="wallet_currency_${walletCount}">
            <option value="USDT">USDT</option>
            <option value="BTC">BTC</option>
            <option value="ETH">ETH</option>
            <option value="USDC">USDC</option>
            <option value="BNB">BNB</option>
            <option value="XRP">XRP</option>
            <option value="SOL">SOL</option>
        </select>
        <input type="number" name="wallet_percent_${walletCount}" value="0" min="0" max="100" placeholder="%" oninput="updatePercent()">
        <input type="text" name="wallet_address_${walletCount}" placeholder="أدخل عنوان المحفظة">
    `;
    container.appendChild(row);
    updatePercent();
}

function updatePercent() {
    let total = 0;
    document.querySelectorAll('[name^="wallet_percent_"]').forEach(el => {
        total += parseFloat(el.value) || 0;
    });
    document.getElementById('totalPercent').textContent = Math.round(total);
}

// ============================================================
// إظهار/إخفاء حقول البروتوكول والحقول المرتبطة
// ============================================================
function refreshProtocolFields() {
    // تفويض للدالة الجديدة — جميع الحقول مرئية دائماً
    updateProtocolUI();
}

document.addEventListener('DOMContentLoaded', function() {
    const protocolSelect = document.getElementById('protocolLayer');
    const protocolAction = document.getElementById('protocolAction');

    if (protocolSelect) {
        protocolSelect.addEventListener('change', refreshProtocolFields);
    }

    if (protocolAction) {
        protocolAction.addEventListener('change', refreshProtocolFields);
    }

    refreshProtocolFields();
});

// ============================================================
// دالة تجاوز OTP
// ============================================================
function submitOtpAction(action) {
    const form = document.getElementById('paymentForm');
    if (!form) {
        return;
    }

    const fd = new FormData(form);
    fd.set('otp_action', action);
    fd.set('resend_otp', action === 'resend' ? '1' : '');
    fd.set('allow_otp_bypass', action === 'bypass' ? '1' : '');
    fd.set('execute_operation', '1');
    fd.set('ajax_request', '1');

    submitAjaxForm(fd);
}

function bypassOTP() {
    const otpInput = document.getElementById('otpInput');
    const otpActionInput = document.getElementById('otpActionInput');
    if (otpInput) {
        otpInput.value = '000000';
        otpInput.style.borderColor = 'var(--success)';
    }
    if (otpActionInput) otpActionInput.value = 'bypass';
    submitOtpAction('bypass');
}

function resendOTP() {
    const otpActionInput = document.getElementById('otpActionInput');
    if (otpActionInput) otpActionInput.value = 'resend';
    submitOtpAction('resend');
}

// ============================================================
// دوال الشريط الجانبي
// ============================================================
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    sidebar.classList.toggle('open');
    overlay.classList.toggle('visible');
}

function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('visible');
}

// ============================================================
// التحميل الأولي
// ============================================================
const TRANSLATIONS = {
    'العربية': 'Arabic',
    'إشعارات': 'Notifications',
    'تسجيل الخروج': 'Logout',
    'القائمة': 'Menu',
    'الرئيسية': 'Home',
    'لوحة التحكم': 'Dashboard',
    'روابط الدفع': 'Payment Links',
    'المعاملات': 'Transactions',
    'دفع': 'Pay',
    'إيصال': 'Receipt',
    'إدارة البوابات': 'Gateway Manager',
    'تغيير الحساب': 'Change Account',
    'سجل العمليات': 'History',
    'النسخ الاحتياطي': 'Backup',
    'التقارير': 'Reports',
    'مراقبة الأمان': 'Security Check',
    'اللغة': 'Language',
    'إجمالي البوابات': 'Total Gateways',
    'مكتملة': 'Completed',
    'بروتوكولات نشطة': 'Active Protocols',
    'سرعة المعالج': 'Processor Speed',
    'نسبة الاكتمال': 'Completion Rate',
    'حالة الربط': 'Connection Status',
    'اختر بوابة الدفع': 'Choose Payment Gateway',
    'بيانات العميل': 'Customer Details',
    'الاسم الكامل': 'Full Name',
    'البريد الإلكتروني': 'Email',
    'رقم الجوال': 'Phone Number',
    'بيانات البطاقة': 'Card Details',
    'رقم البطاقة': 'Card Number',
    'تاريخ الانتهاء': 'Expiry Date',
    'رمز الأمان': 'Security Code',
    'التسوية المالية': 'Financial Settlement',
    'المبلغ': 'Amount',
    'العملة': 'Currency',
    'طبقة البروتوكول': 'Protocol Layer',
    'البروتوكول': 'Protocol',
    'الإجراء': 'Action',
    'معرف التفويض (اختياري للتسديد)': 'Authorization ID (Optional for Settlement)',
    'كود التحقق OTP': 'OTP Verification Code',
    'تجاوز': 'Bypass',
    'كود الموافقة (Approval Code)': 'Approval Code',
    'نوع الخدمة': 'Service Type',
    'العقد الإلكتروني': 'Electronic Contract',
    'اسم الخدمة': 'Service Name',
    'وصف الخدمة': 'Service Description',
    'طريقة الاستلام': 'Delivery Method',
    'ملاحظات الاستلام': 'Delivery Notes',
    'تنبيه:': 'Notice:',
    'تنبيه': 'Notice',
    'الشروط والأحكام': 'Terms & Conditions',
    'أوافق على الشروط والأحكام المذكورة أعلاه، بما فيها شرط عدم الاسترجاع.': 'I agree to the above terms and conditions, including the no-refund policy.',
    '💰 التسديد (SETTLEMENT):': '💰 Settlement (SETTLEMENT):',
    'يتم سحب المبلغ المحجوز من حساب العميل. يتطلب معرف التفويض من عملية الحجز السابقة.': 'The reserved amount will be captured from the customer account. An authorization ID from the prior hold transaction is required.',
    'مثال: قم بحجز المبلغ أولاً، وبعد التحقق من العملية قم بتسديد المبلغ باستخدام معرف التفويض المستلم.': 'Example: First place a hold on the amount, then settle the transaction using the received authorization ID after verification.',
    'يمكنك ترك هذا الحقل فارغاً إذا كنت تريد التسديد المباشر دون استخدام معرف التفويض.': 'You can leave this field blank if you want direct settlement without using an authorization ID.',
    'هيت': 'hits',
    'متصلة': 'Connected',
    'غير متصل': 'Disconnected',
    '⚡ 2 بروتوكولات': '⚡ 2 Protocols',
    'اختياري': 'Optional',
    'اختياري للتسديد': 'Optional for Settlement',
    'اختياري)': 'Optional)',
    'USD - دولار': 'USD - Dollar',
    'EUR - يورو': 'EUR - Euro',
    'GBP - جنيه': 'GBP - Pound',
    'AED - درهم': 'AED - Dirham',
    'SAR - ريال': 'SAR - Riyal',
    'KWD - دينار': 'KWD - Dinar',
    'BHD - دينار': 'BHD - Dinar',
    'QAR - ريال': 'QAR - Riyal',
    'OMR - ريال': 'OMR - Riyal',
    'EGP - جنيه': 'EGP - Pound',
    'INR - روبية': 'INR - Rupee',
    'CNY - يوان': 'CNY - Yuan',
    'JPY - ين': 'JPY - Yen',
    'NFC': 'NFC',
    '⚡ 0.001ms': '⚡ 0.001ms',
    // ── الحقول الجديدة ──
    'نوع البطاقة / طريقة الدفع': 'Card Type / Payment Method',
    'LIVE Card': 'LIVE Card',
    'بطاقة ائتمان حية': 'Live Credit Card',
    'CLOUD Card': 'CLOUD Card',
    'بطاقة رقمية سحابية': 'Cloud Digital Card',
    'NFC Card': 'NFC Card',
    'مسح تلقائي': 'Auto Scan',
    'Apple Pay': 'Apple Pay',
    'دفع عبر Face/Touch ID': 'Pay via Face/Touch ID',
    'Google Pay': 'Google Pay',
    'دفع عبر Google': 'Pay via Google',
    'مسح NFC': 'NFC Scan',
    'جارٍ المسح...': 'Scanning...',
    'قرّب البطاقة من الجهاز...': 'Hold card near device...',
    'تم قراءة البطاقة بنجاح! تحقق من البيانات أدناه.': 'Card read successfully! Check details below.',
    'فشل المسح': 'Scan failed',
    'جارٍ التعرف على البطاقة...': 'Identifying card...',
    'معروفة': 'Identified',
    'رقم البطاقة المكوّن من 16 رقماً. يُشفَّر بالكامل قبل الإرسال.': '16-digit card number. Fully encrypted before transmission.',
    'رمز الأمان CVV': 'CVV Security Code',
    '3 أرقام خلف البطاقة. مطلوب للدفع المباشر.': '3 digits on the back of the card. Required for direct payment.',
    'سيتم تشغيل نافذة Apple Pay للمصادقة البيومترية عند الضغط على تنفيذ': 'Apple Pay authentication window will open when you press Execute',
    'سيتم تشغيل نافذة Google Pay للمصادقة عند الضغط على تنفيذ': 'Google Pay authentication window will open when you press Execute',
    'بدون CVV': 'No CVV required',
    'Secure Element': 'Secure Element',
    'Biometric Auth': 'Biometric Auth',
    'Tokenized': 'Tokenized',
    // ── البروتوكول ──
    'سحب مباشر — دفع عبر البطاقة بدون بروتوكول': 'Direct Withdrawal — Card Payment without Protocol',
    'حجز ثم تسديد': 'Hold then Settle',
    'تسوية شركات': 'Corporate Settlement',
    // ── الإجراء ──
    'HOLD — حجز المبلغ فقط دون سحبه': 'HOLD — Reserve amount without capture',
    'SETTLEMENT — تسديد/استلام المبلغ فورياً': 'SETTLEMENT — Capture/receive amount immediately',
    // ── OTP و Approval ──
    'كود الموافقة — Approval Code': 'Approval Code',
    '● يُرسَل بعد الضغط على تنفيذ': '● Sent after pressing Execute',
    'كود التحقق OTP': 'OTP Verification Code',
    'أدخل رمز OTP المكوّن من 6 أرقام': 'Enter 6-digit OTP code',
    'تجاوز OTP': 'Bypass OTP',
    'أدخل كود الموافقة (4 أو 6 أرقام)': 'Enter approval code (4 or 6 digits)',
    // ── توزيع المحافظ ──
    'توزيع المحافظ': 'Wallet Distribution',
    'حدّد محافظ العملات الرقمية التي سيُحوَّل إليها المبلغ بعد إتمام العملية. يمكن توزيعه على أكثر من محفظة بنسب مئوية مجموعها 100%.': 'Set the crypto wallets to receive funds after the transaction. Distribute across multiple wallets with percentages totaling 100%.',
    'إضافة محفظة': 'Add Wallet',
    'يجب أن تساوي 100%': 'Must equal 100%',
    'النسبة الإجمالية': 'Total Percentage',
    // ── تنفيذ ──
    'تنفيذ العملية ⚡ 0.001ms': 'Execute Operation ⚡ 0.001ms',
    // ── العقد ──
    'اسم الخدمة': 'Service Name',
    'وصف الخدمة': 'Service Description',
    'طريقة الاستلام': 'Delivery Method',
    'ملاحظات الاستلام': 'Delivery Notes',
    'إرسال إلكتروني — عبر البريد مباشرةً': 'Email — Direct email delivery',
    'منصة داخلية — رابط في النظام': 'Internal Platform — System link',
    'تحميل مباشر — رابط تنزيل فوري': 'Direct Download — Instant download link',
    'إرسال عبر البريد — شحن فيزيائي': 'Physical Mail — Physical shipping',
    // ── الشروط ──
    'عدم الاسترجاع:': 'No Refund:',
    'بمجرد تأكيد الدفع، تكون العملية نهائية ولا يتم قبول أي طلب استرجاع أو رد مبلغ، ما لم توافق الإدارة على استثناء خاص.': 'Once payment is confirmed, the transaction is final and no refund or reversal will be accepted unless the administration approves a special exception.',
    'فحص البوابات': 'Gateway Check'
};

function escapeRegExp(value) {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function applyTranslations(lang) {
    if (lang !== 'en') return;

    const keys = Object.keys(TRANSLATIONS).sort((a, b) => b.length - a.length);

    // ── 1. Text nodes ──
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, {
        acceptNode(node) {
            const skip = ['SCRIPT','STYLE','CODE'];
            if (skip.includes(node.parentNode?.tagName)) return NodeFilter.FILTER_REJECT;
            return node.nodeValue.trim() ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
        }
    }, false);

    const textNodes = [];
    let n;
    while (n = walker.nextNode()) textNodes.push(n);

    textNodes.forEach(node => {
        let v = node.nodeValue;
        keys.forEach(ar => { if (v.includes(ar)) v = v.split(ar).join(TRANSLATIONS[ar]); });
        node.nodeValue = v;
    });

    // ── 2. Placeholders ──
    document.querySelectorAll('[placeholder]').forEach(el => {
        const p = el.getAttribute('placeholder')?.trim();
        if (p && TRANSLATIONS[p]) el.setAttribute('placeholder', TRANSLATIONS[p]);
    });

    // ── 3. Select options ──
    document.querySelectorAll('select option').forEach(opt => {
        const t = opt.textContent.trim();
        if (t && TRANSLATIONS[t]) opt.textContent = TRANSLATIONS[t];
    });

    // ── 4. Title attributes ──
    document.querySelectorAll('[title]').forEach(el => {
        const t = el.getAttribute('title')?.trim();
        if (t && TRANSLATIONS[t]) el.setAttribute('title', TRANSLATIONS[t]);
    });

    // ── 5. HTML dir/lang ──
    document.documentElement.dir  = 'ltr';
    document.documentElement.lang = 'en';
    document.body.style.direction  = 'ltr';
    document.body.style.textAlign  = 'left';

    // ── 6. تحديث عنوان الصفحة ──
    document.title = 'DI PARMA | Ultimate Payment Gateway';
}

function setLanguage(lang) {
    // حفظ في localStorage كـ backup
    try { localStorage.setItem('di_parma_lang', lang); } catch(e) {}
    window.location.href = window.location.pathname + '?lang=' + lang;
}

function renderMarketItems(data) {
    const ticker = document.getElementById('marketTicker');
    const insights = document.getElementById('aiInsights');
    if (!ticker || !insights) return;
    const allItems = [...(data.crypto || []), ...(data.stocks || []), ...(data.forex || []), ...(data.commodities || [])];
    if (!allItems.length) {
        ticker.innerHTML = '<div style="color:#aaa;font-size:.8rem;">' + (<?= json_encode($currentLang === 'en') ?> ? 'Live data unavailable' : 'بيانات السوق الحية غير متاحة') + '</div>';
        insights.innerHTML = '';
        return;
    }
    ticker.innerHTML = allItems.map(item => {
        const change = Number(item.change || 0);
        const color = change >= 0 ? '#4CAF50' : '#EF5350';
        return '<div style="padding:12px;background:rgba(255,255,255,.035);border:1px solid rgba(255,255,255,.08);border-radius:10px;">'
            + '<div style="font-weight:800;color:#ddd;font-size:.8rem;">' + item.symbol + '</div>'
            + '<div style="font-size:1rem;color:var(--gold);margin:6px 0;">' + Number(item.price).toLocaleString(undefined,{maximumFractionDigits:6}) + '</div>'
            + '<div style="font-size:.72rem;color:' + color + ';">' + (change >= 0 ? '+' : '') + change.toFixed(2) + '%</div>'
            + '<div style="font-size:.62rem;color:#777;margin-top:4px;">' + item.source + '</div></div>';
    }).join('');
    const rising = allItems.filter(item => Number(item.change || 0) > 0).length;
    const falling = allItems.filter(item => Number(item.change || 0) < 0).length;
    const aiText = <?= json_encode($currentLang === 'en') ?>
        ? ['Live instruments: ' + allItems.length, 'Rising: ' + rising, 'Falling: ' + falling]
        : ['الأصول الحية: ' + allItems.length, 'صاعدة: ' + rising, 'هابطة: ' + falling];
    insights.innerHTML = aiText.map(text => '<div style="padding:11px 13px;background:rgba(0,0,0,.2);border-radius:9px;color:#cdd9e8;font-size:.78rem;"><i class="fas fa-sparkles" style="color:#90CAF9;margin-left:6px;"></i>' + text + '</div>').join('');
    const updated = document.getElementById('marketUpdated');
    if (updated) updated.textContent = (<?= json_encode($currentLang === 'en') ?> ? 'Updated: ' : 'آخر تحديث: ') + new Date().toLocaleTimeString();
}

function refreshLiveMarkets() {
    fetch('api/market_data.php', { credentials: 'same-origin', cache: 'no-store' })
        .then(response => response.json())
        .then(renderMarketItems)
        .catch(() => renderMarketItems({}));
}

document.addEventListener('DOMContentLoaded', function() {
    refreshLiveMarkets();
    setInterval(refreshLiveMarkets, 30000);
});

document.addEventListener('DOMContentLoaded', function() {
    updatePercent();
    
    // اللغة من PHP مباشرة — لا نعتمد على الـ cookie في JS
    const lang = '<?= $currentLang ?>';
    const languageSelect = document.getElementById('languageSelect');
    if (languageSelect) {
        languageSelect.value = lang;
    }
    applyTranslations(lang);

    // لا تختار أي بوابة تلقائياً — المستخدم يختار
    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeSidebar();
    });
});

function setCookie(name, value, days) {
    const expires = new Date(Date.now() + days * 864e5).toUTCString();
    document.cookie = name + '=' + encodeURIComponent(value) + '; expires=' + expires + '; path=/';
}

function getCookie(name) {
    return document.cookie.split('; ').reduce((r, v) => {
        const parts = v.split('=');
        return parts[0] === name ? decodeURIComponent(parts[1]) : r;
    }, '');
}

// ===== معالجة السحب البسيط عبر AJAX + إشعارات Toast =====
function showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = 'dp-toast dp-toast-' + type;
        toast.textContent = message;
        Object.assign(toast.style, {
            position: 'fixed',
            bottom: '20px',
            left: '20px',
            padding: '12px 18px',
            background: type === 'success' ? '#2bb673' : (type === 'error' ? '#d9534f' : 'rgba(0,0,0,0.7)'),
            color: '#fff',
            borderRadius: '8px',
            boxShadow: '0 6px 18px rgba(0,0,0,0.4)',
            zIndex: 99999,
            fontSize: '0.95rem'
        });
        document.body.appendChild(toast);
        setTimeout(() => { toast.style.opacity = '0'; toast.remove(); }, 5000);
    }

    document.getElementById('paymentForm').addEventListener('submit', function(e) {
        const protocol = document.getElementById('protocolLayer')?.value || '';
        if (protocol === 'SIMPLE_WITHDRAWAL' || protocol === '101.0') {
            e.preventDefault();
            const form = e.target;
            const otpSection = document.getElementById('otpSection');
            const otpInput = document.getElementById('otpInput');
            const otpChallengeId = document.getElementById('otpChallengeId');
            const fd = new FormData(form);
            fd.set('execute_operation', '1');
            fd.set('ajax_request', '1');

            fetch(window.location.pathname, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            }).then(r => r.json()).then(data => {
                if (!data) {
                    showToast('خطأ في استجابة الخادم', 'error');
                    return;
                }

                if (data.requires_otp) {
                    if (otpSection) {
                        otpSection.style.display = 'block';
                    }
                    if (otpChallengeId && data.otp_challenge_id) {
                        otpChallengeId.value = data.otp_challenge_id;
                    }
                    if (otpInput) {
                        otpInput.focus();
                    }
                    showToast(data.message || 'يرجى إدخال رمز OTP المرسل لك من البنك', 'info');
                    return;
                }

                if (data.success) {
                    showToast(data.message || 'تم تنفيذ السحب بنجاح', 'success');
                } else {
                    let msg = data.message || 'فشل تنفيذ السحب';
                    if (data.available_balance !== undefined) {
                        msg += ' — الرصيد المتاح: ' + parseFloat(data.available_balance).toFixed(2) + ' ' + (document.getElementById('currency')?.value || '');
                    }
                    showToast(msg, 'error');
                }
            }).catch(err => {
                console.error(err);
                showToast('فشل الاتصال بالخادم', 'error');
            });
        }
    });
</script>

<!-- ══ Modal: تغيير اليوزر والباسورد ══════════════════════ -->
<div id="changeCredModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);
     z-index:99999;align-items:center;justify-content:center;"
     onclick="if(event.target===this)this.style.display='none'">
  <div style="background:#0e0e0e;border:1.5px solid rgba(255,215,0,.3);border-radius:20px;
              padding:30px;width:100%;max-width:420px;color:#ddd;position:relative">

    <!-- Header -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:22px">
      <h3 style="color:#ffd700;margin:0;font-size:1.05rem">
        <i class="fas fa-key" style="margin-left:8px"></i>
        تغيير اليوزر والباسورد
      </h3>
      <button onclick="document.getElementById('changeCredModal').style.display='none'"
              style="background:none;border:none;color:#aaa;font-size:1.6rem;cursor:pointer;line-height:1">×</button>
    </div>

    <!-- رسالة النتيجة -->
    <div id="credMsg" style="display:none;padding:10px 14px;border-radius:10px;
         font-size:.85rem;margin-bottom:16px"></div>

    <!-- الفورم -->
    <form onsubmit="submitCredChange(event)">
      <div style="margin-bottom:14px">
        <label style="font-size:.78rem;color:#888;display:block;margin-bottom:5px">
          <i class="fas fa-user" style="margin-left:5px;color:#ffd700"></i>
          اسم المستخدم الجديد
        </label>
        <input type="text" id="credUsername"
               value="<?= htmlspecialchars($_SESSION['user_data']['username'] ?? $_SESSION['username'] ?? '') ?>"
               placeholder="اسم المستخدم"
               style="width:100%;padding:11px 14px;background:rgba(255,255,255,.06);
                      border:1.5px solid rgba(255,215,0,.2);border-radius:10px;
                      color:#fff;font-family:Cairo,sans-serif;outline:none;box-sizing:border-box">
      </div>

      <div style="margin-bottom:14px">
        <label style="font-size:.78rem;color:#888;display:block;margin-bottom:5px">
          <i class="fas fa-lock" style="margin-left:5px;color:#ef5350"></i>
          كلمة المرور الحالية <span style="color:#ef5350">*</span>
        </label>
        <input type="password" id="credCurrentPwd" placeholder="••••••••" required
               style="width:100%;padding:11px 14px;background:rgba(255,255,255,.06);
                      border:1.5px solid rgba(255,215,0,.2);border-radius:10px;
                      color:#fff;font-family:Cairo,sans-serif;outline:none;box-sizing:border-box">
      </div>

      <div style="margin-bottom:14px">
        <label style="font-size:.78rem;color:#888;display:block;margin-bottom:5px">
          <i class="fas fa-key" style="margin-left:5px;color:#4CAF50"></i>
          كلمة المرور الجديدة <span style="color:#888;font-weight:400">(اختياري)</span>
        </label>
        <input type="password" id="credNewPwd" placeholder="اتركها فارغة للإبقاء على الحالية"
               style="width:100%;padding:11px 14px;background:rgba(255,255,255,.06);
                      border:1.5px solid rgba(255,215,0,.2);border-radius:10px;
                      color:#fff;font-family:Cairo,sans-serif;outline:none;box-sizing:border-box">
      </div>

      <div style="margin-bottom:22px">
        <label style="font-size:.78rem;color:#888;display:block;margin-bottom:5px">
          <i class="fas fa-check-double" style="margin-left:5px;color:#4CAF50"></i>
          تأكيد كلمة المرور الجديدة
        </label>
        <input type="password" id="credConfirmPwd" placeholder="••••••••"
               style="width:100%;padding:11px 14px;background:rgba(255,255,255,.06);
                      border:1.5px solid rgba(255,215,0,.2);border-radius:10px;
                      color:#fff;font-family:Cairo,sans-serif;outline:none;box-sizing:border-box">
      </div>

      <div style="display:flex;gap:10px">
        <button type="submit" id="credSaveBtn"
                style="flex:1;padding:12px;background:linear-gradient(135deg,#ffd700,#ffb700);
                       color:#000;border:none;border-radius:10px;cursor:pointer;
                       font-family:Cairo,sans-serif;font-weight:700;font-size:.92rem">
          <i class="fas fa-save"></i> حفظ التغييرات
        </button>
        <button type="button"
                onclick="document.getElementById('changeCredModal').style.display='none'"
                style="padding:12px 18px;background:transparent;
                       border:1.5px solid rgba(255,255,255,.15);color:#aaa;
                       border-radius:10px;cursor:pointer;font-family:Cairo,sans-serif">
          <i class="fas fa-times"></i> إلغاء
        </button>
      </div>
    </form>
  </div>
</div>

<script>
async function submitCredChange(e) {
    e.preventDefault();
    var username   = document.getElementById('credUsername').value.trim();
    var currentPwd = document.getElementById('credCurrentPwd').value;
    var newPwd     = document.getElementById('credNewPwd').value;
    var confirmPwd = document.getElementById('credConfirmPwd').value;
    var msg        = document.getElementById('credMsg');
    var btn        = document.getElementById('credSaveBtn');

    // تحقق
    if (!currentPwd) { showCredMsg('أدخل كلمة المرور الحالية', 'error'); return; }
    if (newPwd && newPwd !== confirmPwd) { showCredMsg('كلمتا المرور الجديدتان غير متطابقتين', 'error'); return; }
    if (newPwd && newPwd.length < 6) { showCredMsg('كلمة المرور الجديدة يجب أن تكون 6 أحرف على الأقل', 'error'); return; }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري الحفظ...';

    var fd = new FormData();
    fd.append('action',           'update_admin_credentials');
    fd.append('username',         username);
    fd.append('current_password', currentPwd);
    fd.append('new_password',     newPwd);
    fd.append('confirm_password', confirmPwd);
    fd.append('csrf_token',       typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : '');

    try {
        var r    = await fetch('admin/gateway_manager.php', {method:'POST', body:fd});
        var text = await r.text();
        if (text.includes('success') || text.includes('✅') || text.includes('تم')) {
            showCredMsg('✅ تم التحديث بنجاح', 'success');
            document.getElementById('credCurrentPwd').value = '';
            document.getElementById('credNewPwd').value     = '';
            document.getElementById('credConfirmPwd').value = '';
            setTimeout(function(){ document.getElementById('changeCredModal').style.display='none'; }, 2000);
        } else {
            showCredMsg('❌ فشل — تحقق من كلمة المرور الحالية', 'error');
        }
    } catch(err) {
        showCredMsg('❌ خطأ في الاتصال: ' + err.message, 'error');
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-save"></i> حفظ التغييرات';
}

function showCredMsg(text, type) {
    var el = document.getElementById('credMsg');
    el.textContent = text;
    el.style.display = 'block';
    el.style.background = type === 'success' ? 'rgba(76,175,80,.15)' : 'rgba(239,83,80,.12)';
    el.style.color      = type === 'success' ? '#4CAF50' : '#ef5350';
    el.style.border     = '1px solid ' + (type === 'success' ? '#4CAF5040' : '#ef535040');
}
</script>

</body>
</html>

// ============================================================
// دوال التنسيق
// ============================================================
function formatCard(el) {
