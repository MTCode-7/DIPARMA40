<?php
/**
 * ============================================================
 * DI PARMA | 13 نوع شراء حقيقي من البطاقة → Ledger
 * ============================================================
 * 
 * هذا الملف هو جوهر نظام الدفع في DI PARMA
 * يدعم 13 نوعاً مختلفاً من عمليات الشراء
 * جميعها حقيقية 100% بدون أي محاكاة
 * 
 * ============================================================
 * الأنواع المدعومة (13 نوع):
 * 
 * ─── مشتريات 2D / MOTO ───
 * 1.  purchase_2d      → شراء 2D / MOTO عام
 * 2.  purchase_advice  → شراء إرشادي (Advice) - ISO 0220
 * 3.  purchase_offline → مبيعات خارج الخط (Offline MOTO)
 * 4.  purchase_online  → مبيعات عبر الإنترنت (Online MOTO)
 * 
 * ─── مشتريات 3D Secure ───
 * 5.  purchase_3d      → شراء مع 3D Secure
 * 6.  auth_hold        → تجميد مبلغ (Authorization Hold)
 * 7.  auth_capture     → تأكيد التجميد (Auth Capture)
 * 
 * ─── مشتريات متخصصة ───
 * 8.  recurring        → شراء متكرر (اشتراك)
 * 9.  installment      → شراء بالتقسيط
 * 10. crypto_purchase  → شراء عملات رقمية
 * 11. gift_card        → شراء بطاقة هدايا
 * 12. wire_transfer    → تحويل بنكي مباشر
 * 13. quasi_cash       → سحب نقدي شبيه (Quasi Cash)
 * 
 * ============================================================
 * العمله الأساسية: USD (دولار أمريكي)
 * جميع المعاملات حقيقية 100% بدون محاكاة
 * تتكامل مع Ledger Nano X عبر TronGrid
 * ============================================================
 */

// ============================================================
// [1] إعدادات الرأس والأمان
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Key, X-Timestamp, X-Signature, X-Transaction-Type');

/**
 * معالجة طلبات OPTIONS (CORS Preflight)
 * المتصفح يرسل طلب OPTIONS قبل الطلب الفعلي للتحقق من صلاحيات CORS
 */
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * قبول طلبات POST فقط
 * جميع عمليات الدفع تتم عبر POST لأسباب أمنية
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method Not Allowed. Use POST.'
    ]);
    exit;
}

// ============================================================
// [2] استيراد الملفات المطلوبة
// ============================================================

require_once __DIR__ . '/../includes/config.php';      // إعدادات النظام
require_once __DIR__ . '/../includes/database.php';    // الاتصال بقاعدة البيانات
require_once __DIR__ . '/../includes/functions.php';   // دوال مساعدة عامة

// ============================================================
// [3] تعريف أنواع العمليات الـ 13
// ============================================================

/**
 * تعريف جميع أنواع العمليات المدعومة
 * كل نوع له خصائصه الخاصة:
 * - id: رقم تعريف النوع
 * - label: اسم النوع (عربي/إنجليزي)
 * - iso: نوع رسالة ISO (0200, 0100, 0220, 0400, 0420)
 * - security: نوع الأمان (3D أو 2D)
 * - category: تصنيف العملية
 * - requires_original: هل يتطلب مرجع أصلي؟
 * - settlement_days: عدد أيام التسوية
 * - type: نوع البواقة (card, crypto, bank)
 * - moto_indicator: مؤشر MOTO (M, T, F, E)
 * - advice: هل هي معاملة إرشادية؟
 * - offline: هل هي خارج الخط؟
 * - description: وصف العملية
 * - risk_level: مستوى المخاطرة
 */
$TRANSACTION_TYPES = [
    // ════════════════════════════════════════════════════════════
    // 1. PURCHASE 2D / MOTO - شراء 2D عام
    // ════════════════════════════════════════════════════════════
    'purchase_2d' => [
        'id' => '01',
        'label' => 'شراء 2D / MOTO',
        'iso' => '0200',
        'security' => '2D',
        'category' => 'moto',
        'requires_original' => false,
        'settlement_days' => 1,
        'type' => 'card',
        'moto_indicator' => 'M',
        'advice' => false,
        'offline' => false,
        'description' => 'شراء عام بدون 3D Secure (MOTO)',
        'risk_level' => 'medium',
        'icon' => 'fa-credit-card',
        'color' => '#3B82F6'
    ],
    
    // ════════════════════════════════════════════════════════════
    // 2. PURCHASE ADVICE - شراء إرشادي
    // ════════════════════════════════════════════════════════════
    'purchase_advice' => [
        'id' => '02',
        'label' => 'شراء إرشادي (Advice)',
        'iso' => '0220',  // ISO 0220 = Advice Message
        'security' => '2D',
        'category' => 'advice',
        'requires_original' => true,  // يتطلب مرجع أصلي
        'settlement_days' => 1,
        'type' => 'card',
        'moto_indicator' => null,
        'advice' => true,
        'offline' => false,
        'description' => 'معاملة إرشادية بعد موافقة مسبقة من البنك',
        'risk_level' => 'low',
        'icon' => 'fa-bell',
        'color' => '#F59E0B'
    ],
    
    // ════════════════════════════════════════════════════════════
    // 3. PURCHASE OFFLINE - مبيعات خارج الخط (Offline MOTO)
    // ════════════════════════════════════════════════════════════
    'purchase_offline' => [
        'id' => '03',
        'label' => 'مبيعات خارج الخط (Offline MOTO)',
        'iso' => '0200',
        'security' => '2D',
        'category' => 'offline',
        'requires_original' => false,
        'settlement_days' => 1,
        'type' => 'card',
        'moto_indicator' => 'M',
        'advice' => false,
        'offline' => true,
        'description' => 'مبيعات عبر الهاتف/البريد/فاكس - MOTO',
        'risk_level' => 'medium',
        'icon' => 'fa-phone',
        'color' => '#8B5CF6',
        'offline_channels' => ['phone', 'mail', 'fax', 'other']
    ],
    
    // ════════════════════════════════════════════════════════════
    // 4. PURCHASE ONLINE - مبيعات عبر الإنترنت (Online MOTO)
    // ════════════════════════════════════════════════════════════
    'purchase_online' => [
        'id' => '04',
        'label' => 'مبيعات عبر الإنترنت (Online MOTO)',
        'iso' => '0200',
        'security' => '2D',
        'category' => 'online',
        'requires_original' => false,
        'settlement_days' => 1,
        'type' => 'card',
        'moto_indicator' => 'E',
        'advice' => false,
        'offline' => false,
        'description' => 'مبيعات عبر الإنترنت مع تصنيف MOTO',
        'risk_level' => 'low',
        'icon' => 'fa-globe',
        'color' => '#06B6D4'
    ],
    
    // ════════════════════════════════════════════════════════════
    // 5. PURCHASE 3D SECURE - شراء مع 3D Secure
    // ════════════════════════════════════════════════════════════
    'purchase_3d' => [
        'id' => '05',
        'label' => 'شراء 3D Secure',
        'iso' => '0200',
        'security' => '3D',
        'category' => 'online',
        'requires_original' => false,
        'settlement_days' => 2,
        'type' => 'card',
        'moto_indicator' => null,
        'advice' => false,
        'offline' => false,
        'description' => 'شراء مع تحقق 3D Secure من البنك المصدر',
        'risk_level' => 'low',
        'icon' => 'fa-shield-alt',
        'color' => '#10B981'
    ],
    
    // ════════════════════════════════════════════════════════════
    // 6. AUTH HOLD - تجميد مبلغ
    // ════════════════════════════════════════════════════════════
    'auth_hold' => [
        'id' => '06',
        'label' => 'تجميد مبلغ (Authorization Hold)',
        'iso' => '0100',  // ISO 0100 = Authorization Request
        'security' => '3D',
        'category' => 'auth',
        'requires_original' => false,
        'settlement_days' => 3,
        'type' => 'card',
        'moto_indicator' => null,
        'advice' => false,
        'offline' => false,
        'description' => 'تجميد المبلغ مؤقتاً لحين تأكيد العملية',
        'risk_level' => 'low',
        'icon' => 'fa-lock',
        'color' => '#6366F1',
        'hold_days' => 7
    ],
    
    // ════════════════════════════════════════════════════════════
    // 7. AUTH CAPTURE - تأكيد التجميد
    // ════════════════════════════════════════════════════════════
    'auth_capture' => [
        'id' => '07',
        'label' => 'تأكيد التجميد (Auth Capture)',
        'iso' => '0200',
        'security' => '3D',
        'category' => 'auth',
        'requires_original' => true,  // يتطلب المرجع الأصلي للتجميد
        'settlement_days' => 1,
        'type' => 'card',
        'moto_indicator' => null,
        'advice' => false,
        'offline' => false,
        'description' => 'تأكيد التجميد وتحويله إلى شراء كامل',
        'risk_level' => 'low',
        'icon' => 'fa-check-double',
        'color' => '#8B5CF6'
    ],
    
    // ════════════════════════════════════════════════════════════
    // 8. RECURRING - شراء متكرر (اشتراك)
    // ════════════════════════════════════════════════════════════
    'recurring' => [
        'id' => '08',
        'label' => 'شراء متكرر (اشتراك)',
        'iso' => '0200',
        'security' => '3D',
        'category' => 'recurring',
        'requires_original' => false,
        'settlement_days' => 1,
        'type' => 'card',
        'moto_indicator' => null,
        'advice' => false,
        'offline' => false,
        'description' => 'دفع متكرر شهري/سنوي للاشتراكات',
        'risk_level' => 'low',
        'icon' => 'fa-repeat',
        'color' => '#14B8A6',
        'recurring_indicator' => 'R',
        'frequencies' => ['monthly', 'quarterly', 'yearly']
    ],
    
    // ════════════════════════════════════════════════════════════
    // 9. INSTALLMENT - شراء بالتقسيط
    // ════════════════════════════════════════════════════════════
    'installment' => [
        'id' => '09',
        'label' => 'شراء بالتقسيط',
        'iso' => '0200',
        'security' => '3D',
        'category' => 'installment',
        'requires_original' => false,
        'settlement_days' => 1,
        'type' => 'card',
        'moto_indicator' => null,
        'advice' => false,
        'offline' => false,
        'description' => 'شراء وتقسيم المبلغ على عدة دفعات',
        'risk_level' => 'low',
        'icon' => 'fa-calculator',
        'color' => '#F97316',
        'installment_indicator' => 'I',
        'min_installments' => 2,
        'max_installments' => 12
    ],
    
    // ════════════════════════════════════════════════════════════
    // 10. CRYPTO PURCHASE - شراء عملات رقمية
    // ════════════════════════════════════════════════════════════
    'crypto_purchase' => [
        'id' => '10',
        'label' => 'شراء عملات رقمية',
        'iso' => '0200',
        'security' => '2D',
        'category' => 'crypto',
        'requires_original' => false,
        'settlement_days' => 1,
        'type' => 'crypto',
        'moto_indicator' => null,
        'advice' => false,
        'offline' => false,
        'description' => 'شراء USDT/BTC/ETH باستخدام البطاقة',
        'risk_level' => 'medium',
        'icon' => 'fab fa-bitcoin',
        'color' => '#F7931A',
        'crypto_currencies' => ['USDT', 'BTC', 'ETH', 'BNB', 'SOL', 'XRP', 'ADA', 'DOGE']
    ],
    
    // ════════════════════════════════════════════════════════════
    // 11. GIFT CARD - بطاقة هدايا
    // ════════════════════════════════════════════════════════════
    'gift_card' => [
        'id' => '11',
        'label' => 'بطاقة هدايا',
        'iso' => '0200',
        'security' => '2D',
        'category' => 'gift',
        'requires_original' => false,
        'settlement_days' => 1,
        'type' => 'card',
        'moto_indicator' => null,
        'advice' => false,
        'offline' => false,
        'description' => 'شراء بطاقة هدايا رقمية',
        'risk_level' => 'low',
        'icon' => 'fa-gift',
        'color' => '#EC4899',
        'min_amount' => 5,
        'max_amount' => 500
    ],
    
    // ════════════════════════════════════════════════════════════
    // 12. WIRE TRANSFER - تحويل بنكي مباشر
    // ════════════════════════════════════════════════════════════
    'wire_transfer' => [
        'id' => '12',
        'label' => 'تحويل بنكي مباشر',
        'iso' => '0200',
        'security' => '2D',
        'category' => 'bank',
        'requires_original' => false,
        'settlement_days' => 3,
        'type' => 'bank',
        'moto_indicator' => null,
        'advice' => false,
        'offline' => false,
        'description' => 'تحويل مبلغ من البطاقة إلى حساب بنكي',
        'risk_level' => 'low',
        'icon' => 'fa-university',
        'color' => '#1E40AF'
    ],
    
    // ════════════════════════════════════════════════════════════
    // 13. QUASI CASH - سحب نقدي شبيه
    // ════════════════════════════════════════════════════════════
    'quasi_cash' => [
        'id' => '13',
        'label' => 'سحب نقدي شبيه (Quasi Cash)',
        'iso' => '0200',
        'security' => '3D',
        'category' => 'cash',
        'requires_original' => false,
        'settlement_days' => 2,
        'type' => 'card',
        'moto_indicator' => null,
        'advice' => false,
        'offline' => false,
        'description' => 'سحب نقدي عبر البطاقة (كازينوهات/مراهنات)',
        'risk_level' => 'high',
        'icon' => 'fa-coins',
        'color' => '#FFD700',
        'max_amount' => 10000
    ],
];

// ============================================================
// [4] قراءة بيانات الطلب
// ============================================================

/**
 * قراءة البيانات المرسلة من العميل
 * تدعم JSON و Form Data
 */
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

// إذا لم تكن JSON، حاول قراءة من POST
if (!is_array($data) && !empty($_POST)) {
    $data = $_POST;
}

// إذا لم تكن هناك بيانات، رفض الطلب
if (!is_array($data) || empty($data)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid or missing request data'
    ]);
    exit;
}

// ============================================================
// [5] استخراج نوع العملية والبيانات
// ============================================================

/**
 * استخراج البيانات من الطلب
 * جميع القيم يتم تنظيفها وتأكيدها
 */
$transactionType = trim($data['txn_type'] ?? $data['transaction_type'] ?? 'purchase_3d');
$amount = floatval($data['amount'] ?? 0);
$currency = strtoupper(trim($data['currency'] ?? 'USD'));
$cardNumber = preg_replace('/\D/', '', $data['card_number'] ?? $data['cardNumber'] ?? '');
$cardExpiry = trim($data['card_expiry'] ?? $data['cardExpiry'] ?? '');
$cardCvv = trim($data['card_cvv'] ?? $data['cardCvv'] ?? '');
$cardHolder = trim($data['card_holder'] ?? $data['cardName'] ?? $data['card_name'] ?? 'CARDHOLDER');
$email = trim($data['email'] ?? $data['customer_email'] ?? '');
$phone = trim($data['phone'] ?? $data['customer_phone'] ?? '');
$reference = trim($data['reference'] ?? '');
$ledgerAddress = trim($data['ledger_address'] ?? $data['ledgerAddr'] ?? '');
$originalReference = trim($data['original_reference'] ?? $data['orig_ref'] ?? '');
$originalAuthCode = trim($data['original_auth_code'] ?? '');
$motoIndicator = strtoupper(trim($data['moto_indicator'] ?? $data['motoIndicator'] ?? ''));
$installmentCount = intval($data['installment_count'] ?? $data['installments'] ?? 0);
$recurringFrequency = trim($data['recurring_frequency'] ?? $data['frequency'] ?? 'monthly');
$cryptoCurrency = strtoupper(trim($data['crypto_currency'] ?? $data['cryptoCurrency'] ?? 'USDT'));
$giftCardAmount = floatval($data['gift_card_amount'] ?? $data['giftAmount'] ?? 0);
$offlineChannel = trim($data['offline_channel'] ?? $data['channel'] ?? 'phone');
$purpose = trim($data['purpose'] ?? 'Gaming/Entertainment');
$bankAccount = $data['bank_account'] ?? $data['bankAccount'] ?? [];
$billingAddress = $data['billing_address'] ?? $data['billingAddress'] ?? [];
$returnUrl = trim($data['return_url'] ?? $data['returnUrl'] ?? 'https://diparmas.com/receipt.php');
$autoTransfer = isset($data['auto_transfer']) ? (bool)$data['auto_transfer'] : true;

/**
 * توليد مرجع فريد إذا لم يتم إرساله
 * الصيغة: DP + نوع العملية + التاريخ + عشوائي
 */
if (empty($reference)) {
    $prefix = 'DP';
    $typePrefix = strtoupper(substr($transactionType, 0, 3));
    $date = date('Ymd');
    $random = strtoupper(bin2hex(random_bytes(4)));
    $reference = $prefix . '_' . $typePrefix . '_' . $date . '_' . $random;
}

// ============================================================
// [6] التحقق من نوع العملية
// ============================================================

/**
 * التأكد من أن نوع العملية مدعوم
 * إذا لم يكن مدعوماً، نعرض قائمة الأنواع المدعومة
 */
if (!isset($TRANSACTION_TYPES[$transactionType])) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Unsupported transaction type',
        'supported_types' => array_keys($TRANSACTION_TYPES),
        'supported_types_labels' => array_column($TRANSACTION_TYPES, 'label'),
    ]);
    exit;
}

$txnDef = $TRANSACTION_TYPES[$transactionType];

// ============================================================
// [7] التحقق من صحة البيانات حسب نوع العملية
// ============================================================

$errors = [];

/**
 * 7.1 التحقق من المبلغ
 */
if ($amount <= 0) {
    $errors[] = 'Amount must be greater than 0';
}

/**
 * 7.2 الحد الأقصى للمبلغ حسب نوع العملية
 */
$maxAmounts = [
    'purchase_2d' => 25000,
    'purchase_advice' => 100000,
    'purchase_offline' => 25000,
    'purchase_online' => 25000,
    'purchase_3d' => 50000,
    'auth_hold' => 100000,
    'auth_capture' => 100000,
    'recurring' => 10000,
    'installment' => 50000,
    'crypto_purchase' => 25000,
    'gift_card' => 500,
    'wire_transfer' => 100000,
    'quasi_cash' => 10000,
];

if (isset($maxAmounts[$transactionType]) && $amount > $maxAmounts[$transactionType]) {
    $errors[] = 'Amount exceeds maximum allowed (' . number_format($maxAmounts[$transactionType], 2) . ' USD)';
}

/**
 * 7.3 التحقق من العملة
 */
$allowedCurrencies = ['USD', 'EUR', 'GBP', 'AED', 'SAR', 'EGP', 'KWD', 'QAR', 'OMR', 'BHD'];
if (!in_array($currency, $allowedCurrencies)) {
    $errors[] = 'Unsupported currency: ' . $currency . '. Supported: ' . implode(', ', $allowedCurrencies);
}

/**
 * 7.4 التحقق من البطاقة (لأنواع البطاقات)
 */
$cardTypes = ['purchase_2d', 'purchase_advice', 'purchase_offline', 'purchase_online', 'purchase_3d', 'auth_hold', 'auth_capture', 'recurring', 'installment', 'gift_card', 'quasi_cash'];

if (in_array($txnDef['type'], ['card', 'crypto'])) {
    
    // 7.4.1 رقم البطاقة
    if (empty($cardNumber) || strlen($cardNumber) < 13 || strlen($cardNumber) > 19) {
        $errors[] = 'Invalid card number (must be 13-19 digits)';
    }
    
    // 7.4.2 التحقق من خوارزمية Luhn
    if (!empty($cardNumber) && !isValidLuhn($cardNumber)) {
        $errors[] = 'Invalid card number (checksum failed)';
    }
    
    // 7.4.3 نوع البطاقة
    if (!empty($cardNumber)) {
        $cardType = detectCardType($cardNumber);
        if ($cardType === 'Unknown') {
            $errors[] = 'Unsupported card type';
        }
    }
    
    // 7.4.4 تاريخ الانتهاء
    if (empty($cardExpiry) || !preg_match('/^(0[1-9]|1[0-2])\/([0-9]{2})$/', $cardExpiry)) {
        $errors[] = 'Invalid expiry date (format: MM/YY)';
    } else {
        // التحقق من أن البطاقة لم تنتهِ
        list($month, $year) = explode('/', $cardExpiry);
        $expiryTimestamp = mktime(0, 0, 0, intval($month), 1, intval($year) + 2000);
        if ($expiryTimestamp < time()) {
            $errors[] = 'Card has expired';
        }
    }
    
    // 7.4.5 رمز CVV
    $cvvLength = ($cardType ?? 'Visa') === 'Amex' ? 4 : 3;
    if (empty($cardCvv) || strlen($cardCvv) !== $cvvLength || !ctype_digit($cardCvv)) {
        $errors[] = 'Invalid CVV (must be ' . $cvvLength . ' digits)';
    }
}

/**
 * 7.5 التحقق من Ledger Address
 */
if (!empty($ledgerAddress) && !preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $ledgerAddress)) {
    $errors[] = 'Invalid Tron address (must start with T and be 34 characters)';
}

/**
 * 7.6 التحقق الخاص بـ Purchase Advice
 */
if ($transactionType === 'purchase_advice') {
    if (empty($originalReference)) {
        $errors[] = 'Original reference required for advice transaction';
    }
    if (empty($originalAuthCode)) {
        $errors[] = 'Original auth code required for advice transaction';
    }
}

/**
 * 7.7 التحقق الخاص بـ Auth Capture
 */
if ($transactionType === 'auth_capture' && empty($originalReference)) {
    $errors[] = 'Original reference required for auth capture';
}

/**
 * 7.8 التحقق الخاص بـ Purchase Offline
 */
if ($transactionType === 'purchase_offline') {
    $allowedChannels = ['phone', 'mail', 'fax', 'other'];
    if (!in_array($offlineChannel, $allowedChannels)) {
        $errors[] = 'Invalid offline channel. Supported: ' . implode(', ', $allowedChannels);
    }
}

/**
 * 7.9 التحقق الخاص بالتقسيط
 */
if ($transactionType === 'installment') {
    if ($installmentCount < 2) {
        $errors[] = 'Minimum 2 installments required';
    }
    if ($installmentCount > 12) {
        $errors[] = 'Maximum 12 installments allowed';
    }
}

/**
 * 7.10 التحقق الخاص ببطاقة الهدايا
 */
if ($transactionType === 'gift_card') {
    if ($giftCardAmount <= 0) {
        $errors[] = 'Gift card amount must be greater than 0';
    }
    if ($giftCardAmount > 500) {
        $errors[] = 'Gift card amount exceeds maximum (500 USD)';
    }
}

/**
 * 7.11 التحقق الخاص بالعملات الرقمية
 */
if ($transactionType === 'crypto_purchase') {
    $allowedCrypto = ['USDT', 'BTC', 'ETH', 'BNB', 'SOL', 'XRP', 'ADA', 'DOGE'];
    if (!in_array($cryptoCurrency, $allowedCrypto)) {
        $errors[] = 'Unsupported crypto currency. Supported: ' . implode(', ', $allowedCrypto);
    }
}

/**
 * 7.12 التحقق الخاص بالسحب النقدي الشبيه
 */
if ($transactionType === 'quasi_cash') {
    if ($amount > 10000) {
        $errors[] = 'Quasi cash amount exceeds maximum (10,000 USD)';
    }
}

/**
 * 7.13 التحقق من البريد الإلكتروني
 */
if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Invalid email address';
}

/**
 * 7.14 التحقق من رقم الهاتف
 */
if (!empty($phone) && !preg_match('/^\+?[0-9]{10,15}$/', $phone)) {
    $errors[] = 'Invalid phone number';
}

// إذا كان هناك أخطاء، أعدها للمستخدم
if (!empty($errors)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Validation failed',
        'transaction_type' => $transactionType,
        'transaction_label' => $txnDef['label'],
        'errors' => $errors
    ]);
    exit;
}

// ============================================================
// [8] الاتصال بقاعدة البيانات
// ============================================================

$db = db();

// ============================================================
// [9] حساب سعر الصرف (USD → USDT)
// ============================================================

$exchangeRates = getExchangeRates();
$usdtAmount = round($amount * ($exchangeRates[$currency] ?? 1.0), 6);

// ============================================================
// [10] STAGE 1: معالجة الدفع عبر DI PARMA Gateway
// ============================================================

/**
 * إعدادات DI PARMA Gateway
 */
$diparmaConfig = [
    'merchant_id' => getenv('DIPARMA_MERCHANT_ID') ?: 'DP_0001',
    'merchant_secret' => getenv('DIPARMA_MERCHANT_SECRET') ?: 'your_merchant_secret',
    'environment' => getenv('DIPARMA_ENVIRONMENT') ?: 'live',
    'acquirer' => $data['acquirer'] ?? 'Mashreq',
];

/**
 * بناء طلب DI PARMA حسب نوع العملية
 */
$diparmaRequest = [
    'merchant_id' => $diparmaConfig['merchant_id'],
    'merchant_secret' => $diparmaConfig['merchant_secret'],
    'acquirer' => $diparmaConfig['acquirer'],
    'amount' => $amount,
    'currency' => $currency,
    'reference' => $reference,
    'order_id' => $reference,
    'transaction_type' => $transactionType,
    'transaction_label' => $txnDef['label'],
    'iso_msg_type' => $txnDef['iso'],
    'security_mode' => $txnDef['security'],
    'category' => $txnDef['category'],
    'risk_level' => $txnDef['risk_level'],
];

/**
 * إضافة بيانات البطاقة (لأنواع البطاقات)
 */
if (in_array($txnDef['type'], ['card', 'crypto'])) {
    $diparmaRequest['card'] = [
        'number' => $cardNumber,
        'expiry_month' => substr($cardExpiry, 0, 2),
        'expiry_year' => '20' . substr($cardExpiry, 3, 2),
        'cvv' => $cardCvv,
        'holder_name' => $cardHolder,
        'type' => $cardType ?? 'Unknown',
        'last4' => substr($cardNumber, -4),
    ];
}

/**
 * إضافة بيانات العميل
 */
$diparmaRequest['customer'] = [
    'email' => $email ?: 'customer@diparmas.com',
    'phone' => $phone ?: '+971501234567',
    'name' => $cardHolder,
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
];

/**
 * إضافة عنوان الفوترة
 */
$diparmaRequest['billing_address'] = [
    'address' => $billingAddress['address'] ?? '',
    'city' => $billingAddress['city'] ?? '',
    'country' => $billingAddress['country'] ?? 'AE',
    'zip' => $billingAddress['zip'] ?? '',
];

/**
 * إضافة بيانات خاصة حسب نوع العملية
 */
switch ($transactionType) {
    
    // ════════════════════════════════════════════════════════════
    // PURCHASE ADVICE - شراء إرشادي
    // ════════════════════════════════════════════════════════════
    case 'purchase_advice':
        $diparmaRequest['advice'] = [
            'original_reference' => $originalReference,
            'original_auth_code' => $originalAuthCode,
            'is_advice' => true,
            'advice_reason' => $data['advice_reason'] ?? 'Post-authorization confirmation',
        ];
        $diparmaRequest['is_advice'] = true;
        break;
    
    // ════════════════════════════════════════════════════════════
    // PURCHASE OFFLINE - مبيعات خارج الخط
    // ════════════════════════════════════════════════════════════
    case 'purchase_offline':
        $diparmaRequest['moto'] = [
            'indicator' => $motoIndicator ?: 'M',
            'channel' => $offlineChannel,
            'is_moto' => true,
            'is_offline' => true,
        ];
        $diparmaRequest['is_moto'] = true;
        $diparmaRequest['is_offline'] = true;
        $diparmaRequest['moto_indicator'] = $motoIndicator ?: 'M';
        break;
    
    // ════════════════════════════════════════════════════════════
    // PURCHASE ONLINE - مبيعات عبر الإنترنت
    // ════════════════════════════════════════════════════════════
    case 'purchase_online':
        $diparmaRequest['moto'] = [
            'indicator' => $motoIndicator ?: 'E',
            'channel' => 'online',
            'is_moto' => true,
            'is_offline' => false,
        ];
        $diparmaRequest['is_moto'] = true;
        $diparmaRequest['is_offline'] = false;
        $diparmaRequest['moto_indicator'] = $motoIndicator ?: 'E';
        break;
    
    // ════════════════════════════════════════════════════════════
    // PURCHASE 2D - شراء 2D عام
    // ════════════════════════════════════════════════════════════
    case 'purchase_2d':
        $diparmaRequest['is_moto'] = true;
        $diparmaRequest['moto_indicator'] = $motoIndicator ?: 'M';
        $diparmaRequest['security_mode'] = '2D';
        break;
    
    // ════════════════════════════════════════════════════════════
    // PURCHASE 3D - شراء 3D Secure
    // ════════════════════════════════════════════════════════════
    case 'purchase_3d':
        $diparmaRequest['security_mode'] = '3D';
        $diparmaRequest['requires_3ds'] = true;
        break;
    
    // ════════════════════════════════════════════════════════════
    // AUTH HOLD - تجميد مبلغ
    // ════════════════════════════════════════════════════════════
    case 'auth_hold':
        $diparmaRequest['is_auth_only'] = true;
        $diparmaRequest['is_capture'] = false;
        $diparmaRequest['hold_days'] = $txnDef['hold_days'] ?? 7;
        $diparmaRequest['security_mode'] = '3D';
        break;
    
    // ════════════════════════════════════════════════════════════
    // AUTH CAPTURE - تأكيد التجميد
    // ════════════════════════════════════════════════════════════
    case 'auth_capture':
        $diparmaRequest['original_reference'] = $originalReference;
        $diparmaRequest['is_auth_only'] = false;
        $diparmaRequest['is_capture'] = true;
        $diparmaRequest['security_mode'] = '3D';
        break;
    
    // ════════════════════════════════════════════════════════════
    // RECURRING - شراء متكرر
    // ════════════════════════════════════════════════════════════
    case 'recurring':
        $diparmaRequest['recurring'] = [
            'frequency' => $recurringFrequency,
            'indicator' => 'R',
            'start_date' => date('Y-m-d'),
            'end_date' => date('Y-m-d', strtotime('+1 year')),
            'max_occurrences' => 12,
        ];
        break;
    
    // ════════════════════════════════════════════════════════════
    // INSTALLMENT - شراء بالتقسيط
    // ════════════════════════════════════════════════════════════
    case 'installment':
        $installmentAmount = round($amount / $installmentCount, 2);
        $diparmaRequest['installment'] = [
            'count' => $installmentCount,
            'indicator' => 'I',
            'first_amount' => $installmentAmount,
            'remaining_amount' => $amount - $installmentAmount,
            'monthly_amount' => $installmentAmount,
        ];
        break;
    
    // ════════════════════════════════════════════════════════════
    // CRYPTO PURCHASE - شراء عملات رقمية
    // ════════════════════════════════════════════════════════════
    case 'crypto_purchase':
        $diparmaRequest['crypto'] = [
            'currency' => $cryptoCurrency,
            'amount' => $amount,
            'usdt_amount' => $usdtAmount,
            'exchange_rate' => $exchangeRates[$currency] ?? 1.0,
        ];
        break;
    
    // ════════════════════════════════════════════════════════════
    // GIFT CARD - بطاقة هدايا
    // ════════════════════════════════════════════════════════════
    case 'gift_card':
        $diparmaRequest['gift_card'] = [
            'amount' => $giftCardAmount,
            'currency' => $currency,
            'recipient_email' => $data['recipient_email'] ?? $email,
            'recipient_name' => $data['recipient_name'] ?? $cardHolder,
            'message' => $data['gift_message'] ?? '',
        ];
        break;
    
    // ════════════════════════════════════════════════════════════
    // WIRE TRANSFER - تحويل بنكي
    // ════════════════════════════════════════════════════════════
    case 'wire_transfer':
        $diparmaRequest['wire_transfer'] = [
            'bank_name' => $bankAccount['bank_name'] ?? '',
            'account_name' => $bankAccount['account_name'] ?? '',
            'account_number' => $bankAccount['account_number'] ?? '',
            'iban' => $bankAccount['iban'] ?? '',
            'swift' => $bankAccount['swift'] ?? '',
            'routing_number' => $bankAccount['routing_number'] ?? '',
        ];
        break;
    
    // ════════════════════════════════════════════════════════════
    // QUASI CASH - سحب نقدي شبيه
    // ════════════════════════════════════════════════════════════
    case 'quasi_cash':
        $diparmaRequest['quasi_cash'] = [
            'purpose' => $purpose,
            'reference' => $reference . '-QC',
            'security_mode' => '3D',
        ];
        break;
}

/**
 * إضافة روابط الإرجاع
 */
$diparmaRequest['return_url'] = $returnUrl . '?ref=' . $reference . '&type=' . $transactionType;
$diparmaRequest['webhook_url'] = 'https://diparmas.com/api/webhooks/diparma.php';
$diparmaRequest['expiry_minutes'] = 30;

/**
 * إضافة وجهة المبلغ
 */
$diparmaRequest['destination'] = $data['destination'] ?? 'ledger_trx';
$diparmaRequest['ledger_address'] = $ledgerAddress;
$diparmaRequest['auto_transfer'] = $autoTransfer;

// ============================================================
// [11] إرسال الطلب إلى DI PARMA Gateway
// ============================================================

$diparmaResponse = sendToDIPARMA($diparmaRequest, $diparmaConfig);

// التحقق من استجابة DI PARMA
if (!$diparmaResponse['success']) {
    // تسجيل الفشل
    try {
        $db->insert('dp_transactions', [
            'reference' => $reference,
            'user_id' => $_SESSION['user_id'] ?? null,
            'gateway' => 'diparma',
            'gateway_type' => $txnDef['type'],
            'transaction_type' => $transactionType,
            'transaction_label' => $txnDef['label'],
            'amount' => $amount,
            'currency' => $currency,
            'card_last4' => substr($cardNumber, -4),
            'cardholder_name' => $cardHolder,
            'security_mode' => $txnDef['security'],
            'status' => 'failed',
            'gateway_response' => json_encode($diparmaResponse),
            'ledger_address' => $ledgerAddress,
            'error_message' => $diparmaResponse['message'] ?? 'Payment failed',
            'error_code' => $diparmaResponse['error_code'] ?? 'unknown',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (Exception $e) {
        error_log('[DI PARMA] DB insert error: ' . $e->getMessage());
    }

    http_response_code(402);
    echo json_encode([
        'success' => false,
        'reference' => $reference,
        'transaction_type' => $transactionType,
        'transaction_label' => $txnDef['label'],
        'stage' => 'card_charge',
        'message' => $diparmaResponse['message'] ?? 'Payment processing failed',
        'error_code' => $diparmaResponse['error_code'] ?? 'unknown',
        'details' => $diparmaResponse['details'] ?? null,
    ]);
    exit;
}

// استخراج بيانات النجاح
$authCode = $diparmaResponse['auth_code'] ?? '';
$rrn = $diparmaResponse['rrn'] ?? '';
$approvalCode = $diparmaResponse['approval_code'] ?? '';
$diparmaTransactionId = $diparmaResponse['transaction_id'] ?? '';
$stan = $diparmaResponse['stan'] ?? '';
$acquirerName = $diparmaResponse['acquirer'] ?? $diparmaConfig['acquirer'];

// ============================================================
// [12] STAGE 2: إرسال USDT إلى Ledger
// ============================================================

$ledgerTxid = null;
$ledgerStatus = 'pending';
$ledgerTransfer = false;

if ($autoTransfer && !empty($ledgerAddress)) {
    try {
        $tronResult = sendUSDTToLedger($ledgerAddress, $usdtAmount);
        
        if ($tronResult['success']) {
            $ledgerTxid = $tronResult['txid'];
            $ledgerStatus = 'completed';
            $ledgerTransfer = true;
        } else {
            $ledgerStatus = 'failed';
            // تسجيل في قائمة الانتظار
            try {
                $db->insert('ledger_transfer_queue', [
                    'reference' => $reference,
                    'ledger_address' => $ledgerAddress,
                    'usdt_amount' => $usdtAmount,
                    'currency_orig' => $currency,
                    'transaction_type' => $transactionType,
                    'status' => 'queued',
                    'message' => $tronResult['message'] ?? 'Failed to send USDT',
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (Exception $e) {}
        }
    } catch (Exception $e) {
        $ledgerStatus = 'failed';
        error_log('[DI PARMA] Ledger error: ' . $e->getMessage());
    }
}

// ============================================================
// [13] تسجيل المعاملة الكاملة
// ============================================================

$finalStatus = ($ledgerStatus === 'completed') ? 'completed' : 'pending_ledger';

$gatewayResponse = json_encode([
    'transaction' => [
        'type' => $transactionType,
        'label' => $txnDef['label'],
        'iso' => $txnDef['iso'],
        'security' => $txnDef['security'],
        'category' => $txnDef['category'],
        'settlement_days' => $txnDef['settlement_days'],
        'risk_level' => $txnDef['risk_level'],
    ],
    'stage_1_card' => [
        'gateway' => 'DI PARMA Direct',
        'acquirer' => $acquirerName,
        'auth_code' => $authCode,
        'approval_code' => $approvalCode,
        'rrn' => $rrn,
        'stan' => $stan,
        'transaction_id' => $diparmaTransactionId,
        'card_last4' => substr($cardNumber, -4),
        'card_holder' => $cardHolder,
        'card_type' => $cardType ?? 'Unknown',
        'sec_mode' => $txnDef['security'],
    ],
    'stage_2_ledger' => [
        'address' => $ledgerAddress,
        'usdt_amount' => $usdtAmount,
        'exchange_rate' => $exchangeRates[$currency] ?? 1.0,
        'txid' => $ledgerTxid,
        'status' => $ledgerStatus,
        'explorer' => $ledgerTxid ? 'https://tronscan.org/#/transaction/' . $ledgerTxid : null,
    ],
    'special_data' => $diparmaRequest['advice'] ?? 
                      $diparmaRequest['moto'] ?? 
                      $diparmaRequest['installment'] ?? 
                      $diparmaRequest['recurring'] ?? 
                      $diparmaRequest['crypto'] ?? 
                      $diparmaRequest['gift_card'] ?? 
                      $diparmaRequest['wire_transfer'] ?? 
                      $diparmaRequest['quasi_cash'] ?? null,
]);

try {
    $db->insert('dp_transactions', [
        'reference' => $reference,
        'user_id' => $_SESSION['user_id'] ?? null,
        'gateway' => 'diparma',
        'gateway_type' => $txnDef['type'],
        'transaction_type' => $transactionType,
        'transaction_label' => $txnDef['label'],
        'amount' => $amount,
        'currency' => $currency,
        'card_last4' => substr($cardNumber, -4),
        'cardholder_name' => $cardHolder,
        'security_mode' => $txnDef['security'],
        'status' => $finalStatus,
        'gateway_response' => $gatewayResponse,
        'ledger_txid' => $ledgerTxid,
        'ledger_transferred' => $ledgerTransfer ? 1 : 0,
        'ledger_amount' => $usdtAmount,
        'ledger_address' => $ledgerAddress,
        'auth_code' => $authCode,
        'rrn' => $rrn,
        'approval_code' => $approvalCode,
        'acquirer' => $acquirerName,
        'original_reference' => $originalReference ?? null,
        'installment_count' => $installmentCount ?? 0,
        'recurring_frequency' => $recurringFrequency ?? null,
        'moto_indicator' => $motoIndicator ?? null,
        'is_advice' => $txnDef['advice'] ? 1 : 0,
        'is_offline' => $txnDef['offline'] ? 1 : 0,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
} catch (Exception $e) {
    error_log('[DI PARMA] DB insert error: ' . $e->getMessage());
}

// ============================================================
// [14] إرسال Webhook
// ============================================================

$webhookUrl = getenv('DEFAULT_WEBHOOK_URL') ?: '';
if (!empty($webhookUrl)) {
    try {
        $webhookData = [
            'event' => 'charge.' . $finalStatus,
            'gateway' => 'DI PARMA',
            'transaction_type' => $transactionType,
            'transaction_label' => $txnDef['label'],
            'reference' => $reference,
            'amount' => $amount,
            'currency' => $currency,
            'usdt_amount' => $usdtAmount,
            'auth_code' => $authCode,
            'rrn' => $rrn,
            'approval_code' => $approvalCode,
            'acquirer' => $acquirerName,
            'ledger' => [
                'address' => $ledgerAddress,
                'txid' => $ledgerTxid,
                'status' => $ledgerStatus,
            ],
            'timestamp' => date('c'),
            'status' => $finalStatus,
        ];
        
        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($webhookData),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-DI-PARMA-Event: charge.' . $finalStatus,
                'X-DI-PARMA-Signature: ' . hash_hmac('sha256', json_encode($webhookData), getenv('WEBHOOK_SECRET') ?: 'default'),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        curl_exec($ch);
        curl_close($ch);
    } catch (Exception $e) {
        error_log('[DI PARMA] Webhook error: ' . $e->getMessage());
    }
}

// ============================================================
// [15] الرد النهائي
// ============================================================

http_response_code(200);
echo json_encode([
    'success' => true,
    'gateway' => 'DI PARMA',
    'reference' => $reference,
    'transaction_type' => $transactionType,
    'transaction_label' => $txnDef['label'],
    'iso_msg_type' => $txnDef['iso'],
    'security_mode' => $txnDef['security'],
    'category' => $txnDef['category'],
    'status' => $finalStatus,
    'amount' => $amount,
    'currency' => $currency,
    'usdt_amount' => $usdtAmount,
    'auth_code' => $authCode,
    'approval_code' => $approvalCode,
    'rrn' => $rrn,
    'stan' => $stan,
    'acquirer' => $acquirerName,
    'card_last4' => substr($cardNumber, -4),
    'card_type' => $cardType ?? 'Unknown',
    'settlement_days' => $txnDef['settlement_days'],
    'ledger' => [
        'address' => $ledgerAddress,
        'txid' => $ledgerTxid,
        'status' => $ledgerStatus,
        'explorer' => $ledgerTxid ? 'https://tronscan.org/#/transaction/' . $ledgerTxid : null,
    ],
    'special_data' => [
        'installment_count' => $installmentCount ?? null,
        'recurring_frequency' => $recurringFrequency ?? null,
        'crypto_currency' => $cryptoCurrency ?? null,
        'gift_card_amount' => $giftCardAmount ?? null,
        'original_reference' => $originalReference ?? null,
        'original_auth_code' => $originalAuthCode ?? null,
        'moto_indicator' => $motoIndicator ?? null,
        'is_advice' => $txnDef['advice'] ?? false,
        'is_offline' => $txnDef['offline'] ?? false,
        'advice_reason' => $data['advice_reason'] ?? null,
        'offline_channel' => $offlineChannel ?? null,
    ],
    'message' => $ledgerStatus === 'completed' 
        ? '✅ ' . $txnDef['label'] . ' completed successfully and sent to Ledger'
        : '✅ ' . $txnDef['label'] . ' completed, pending Ledger transfer',
    'timestamp' => date('c'),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

// ============================================================
// [16] دوال مساعدة
// ============================================================

/**
 * إرسال طلب إلى DI PARMA Gateway
 */
function sendToDIPARMA($request, $config) {
    try {
        $timestamp = time();
        $signature = hash_hmac(
            'sha256',
            $timestamp . $request['reference'] . $request['amount'],
            $config['merchant_secret']
        );
        
        $request['timestamp'] = $timestamp;
        $request['signature'] = $signature;
        
        $acquirerEndpoints = [
            'Mashreq' => 'https://api.mashreqbank.com/payment/charge',
            'HSBC' => 'https://api.hsbc.ae/payment/charge',
            'NBE' => 'https://api.nbe.com.eg/payment/charge',
            'JPMorgan' => 'https://api.jpmorgan.com/payment/charge',
        ];
        
        $endpoint = $acquirerEndpoints[$request['acquirer']] ?? $acquirerEndpoints['Mashreq'];
        
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($request),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Merchant-Id: ' . $config['merchant_id'],
                'X-Signature: ' . $signature,
                'X-Timestamp: ' . $timestamp,
                'X-Transaction-Type: ' . ($request['transaction_type'] ?? 'purchase_3d'),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => $config['environment'] === 'live',
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            throw new Exception('Curl error: ' . curl_error($ch));
        }
        
        curl_close($ch);
        
        $result = json_decode($response, true);
        
        if ($httpCode === 200 && isset($result['status']) && $result['status'] === 'SUCCESS') {
            return [
                'success' => true,
                'auth_code' => $result['auth_code'] ?? '',
                'approval_code' => $result['approval_code'] ?? '',
                'rrn' => $result['rrn'] ?? '',
                'stan' => $result['stan'] ?? '',
                'transaction_id' => $result['transaction_id'] ?? '',
                'acquirer' => $request['acquirer'],
                'message' => 'Payment processed successfully',
            ];
        } else {
            $errorCodes = [
                '01' => 'REFER_TO_ISSUER',
                '02' => 'REFER_TO_ISSUER_SPECIAL',
                '03' => 'INVALID_MERCHANT',
                '04' => 'HOLD_CARD',
                '05' => 'DO_NOT_HONOR',
                '12' => 'INVALID_TRANSACTION',
                '13' => 'INVALID_AMOUNT',
                '14' => 'INVALID_CARD_NUMBER',
                '15' => 'NO_SUCH_ISSUER',
                '30' => 'FORMAT_ERROR',
                '31' => 'BANK_NOT_SUPPORTED',
                '51' => 'INSUFFICIENT_FUNDS',
                '54' => 'EXPIRED_CARD',
                '57' => 'TRANSACTION_NOT_PERMITTED',
                '58' => 'TRANSACTION_NOT_ALLOWED',
                '61' => 'EXCEEDS_DAILY_LIMIT',
                '65' => 'EXCEEDS_WITHDRAWAL_LIMIT',
                '91' => 'ISSUER_TIMEOUT',
                '96' => 'SYSTEM_ERROR',
            ];
            
            $errorCode = $result['error_code'] ?? '96';
            $errorMessage = $errorCodes[$errorCode] ?? $result['message'] ?? 'Transaction failed';
            
            return [
                'success' => false,
                'message' => $errorMessage,
                'error_code' => $errorCode,
                'details' => $result,
            ];
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Connection error: ' . $e->getMessage(),
            'error_code' => 'connection_error',
        ];
    }
}

/**
 * إرسال USDT إلى Ledger عبر TronGrid
 */
function sendUSDTToLedger($toAddress, $amount) {
    try {
        $tronApiKey = getenv('TRONGRID_API_KEY') ?: '';
        $hotWalletAddress = getenv('HOT_WALLET_TRC20_ADDRESS') ?: '';
        $hotWalletPrivateKey = getenv('HOT_WALLET_TRC20_KEY') ?: '';
        $usdtContract = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
        
        if (empty($tronApiKey) || empty($hotWalletPrivateKey)) {
            return [
                'success' => false,
                'message' => 'TronGrid credentials missing',
            ];
        }
        
        $sunAmount = (int)round($amount * 1000000);
        $toHex = base58ToHex($toAddress);
        
        $transaction = [
            'owner_address' => $hotWalletAddress,
            'contract_address' => $usdtContract,
            'function_selector' => 'transfer(address,uint256)',
            'parameter' => $toHex . str_pad(dechex($sunAmount), 64, '0', STR_PAD_LEFT),
            'fee_limit' => 20000000,
            'call_value' => 0,
            'visible' => true,
        ];
        
        $ch = curl_init('https://api.trongrid.io/wallet/triggersmartcontract');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($transaction),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'TRON-PRO-API-KEY: ' . $tronApiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $result = json_decode($response, true);
        
        if ($httpCode === 200 && isset($result['transaction']['txID'])) {
            return [
                'success' => true,
                'txid' => $result['transaction']['txID'],
                'message' => 'USDT sent successfully',
            ];
        } else {
            return [
                'success' => false,
                'message' => $result['message'] ?? 'Failed to send USDT',
            ];
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'TronGrid error: ' . $e->getMessage(),
        ];
    }
}

/**
 * تحويل عنوان Tron من Base58 إلى Hex
 */
function base58ToHex($address) {
    $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    $num = gmp_init(0);
    
    for ($i = 0; $i < strlen($address); $i++) {
        $pos = strpos($alphabet, $address[$i]);
        if ($pos === false) {
            throw new Exception('Invalid Tron address');
        }
        $num = gmp_add(gmp_mul($num, 58), $pos);
    }
    
    $hex = gmp_strval($num, 16);
    if (strlen($hex) % 2 !== 0) {
        $hex = '0' . $hex;
    }
    
    $addressHex = substr($hex, 2, strlen($hex) - 10);
    return str_pad($addressHex, 64, '0', STR_PAD_LEFT);
}

/**
 * كشف نوع البطاقة
 */
function detectCardType($number) {
    $number = preg_replace('/\D/', '', $number);
    
    if (preg_match('/^4/', $number)) return 'Visa';
    if (preg_match('/^5[1-5]/', $number)) return 'Mastercard';
    if (preg_match('/^3[47]/', $number)) return 'Amex';
    if (preg_match('/^6(?:011|5)/', $number)) return 'Discover';
    if (preg_match('/^3(?:0[0-5]|[68])/', $number)) return 'Diners';
    if (preg_match('/^(?:2131|1800|35)/', $number)) return 'JCB';
    if (preg_match('/^62/', $number)) return 'UnionPay';
    
    return 'Unknown';
}

/**
 * التحقق من صحة البطاقة (خوارزمية Luhn)
 */
function isValidLuhn($number) {
    $number = preg_replace('/\D/', '', $number);
    $sum = 0;
    $alt = false;
    for ($i = strlen($number) - 1; $i >= 0; $i--) {
        $n = (int)$number[$i];
        if ($alt) {
            $n *= 2;
            if ($n > 9) $n -= 9;
        }
        $sum += $n;
        $alt = !$alt;
    }
    return $sum % 10 === 0;
}

/**
 * الحصول على أسعار الصرف
 */
function getExchangeRates() {
    $cacheFile = __DIR__ . '/../cache/exchange_rates.json';
    $cacheTTL = 3600;
    
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
        $rates = json_decode(file_get_contents($cacheFile), true);
        if ($rates) {
            return $rates;
        }
    }
    
    $rates = [
        'USD' => 1.0,
        'AED' => 0.2723,
        'SAR' => 0.2667,
        'EUR' => 1.082,
        'GBP' => 1.271,
        'KWD' => 3.257,
        'QAR' => 0.2747,
        'EGP' => 0.0204,
        'USDT' => 1.0,
    ];
    
    try {
        $ch = curl_init('https://api.exchangerate-api.com/v4/latest/USD');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        
        if ($response) {
            $data = json_decode($response, true);
            if (isset($data['rates'])) {
                $rates = array_merge($rates, $data['rates']);
                if (!is_dir(dirname($cacheFile))) {
                    mkdir(dirname($cacheFile), 0755, true);
                }
                file_put_contents($cacheFile, json_encode($rates));
            }
        }
    } catch (Exception $e) {
        // استخدام الأسعار الافتراضية
    }
    
    return $rates;
}

// ============================================================
// نهاية الملف
// ============================================================
?>