<?php
/**
 * ============================================================
 * DI PARMA | 13 نوع شراء حقيقي من البطاقة → Ledger
 * ============================================================
 * 
 * الأنواع المدعومة (13 نوع):
 * 
 * ─── مشتريات عادية ───
 * 1.  purchase_3d      → شراء عادي مع 3D Secure
 * 2.  purchase_2d      → شراء بدون 3D Secure
 * 3.  purchase_advice  → شراء إرشادي (Advice Purchase)
 * 4.  purchase_offline → شراء خارج الخط (Offline - MOTO)
 * 5.  purchase_online  → شراء عبر الإنترنت (Online - MOTO)
 * 
 * ─── مشتريات متخصصة ───
 * 6.  auth_hold        → تجميد مبلغ (Authorization Hold)
 * 7.  auth_capture     → تأكيد التجميد
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
 * ============================================================
 */

// ============================================================
// 1. إعدادات الرأس والأمان
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Key, X-Timestamp, X-Signature, X-Transaction-Type');

// معالجة طلبات OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// قبول طلبات POST فقط
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method Not Allowed. Use POST.'
    ]);
    exit;
}

// ============================================================
// 2. استيراد الملفات المطلوبة
// ============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../../lib/PayRamAdapter.php';

// ============================================================
// 3. تعريف أنواع العمليات (13 نوع)
// ============================================================

$TRANSACTION_TYPES = [
    // ============================================================
    // 1. PURCHASE 3D SECURE - شراء مع 3D Secure
    // ============================================================
    'purchase_3d' => [
        'id' => '01',
        'label' => 'شراء مع 3D Secure',
        'iso' => '0200',
        'security' => '3D',
        'category' => 'online',
        'moto_type' => null,
        'description' => 'شراء عبر الإنترنت مع تحقق 3D Secure من البنك المصدر',
        'requires_original' => false,
        'settlement_days' => 2,
        'type' => 'card',
        'advice' => false,
        'offline' => false,
    ],
    
    // ============================================================
    // 2. PURCHASE 2D - شراء بدون 3D Secure
    // ============================================================
    'purchase_2d' => [
        'id' => '02',
        'label' => 'شراء بدون 3D Secure',
        'iso' => '0200',
        'security' => '2D',
        'category' => 'online',
        'moto_type' => null,
        'description' => 'شراء عبر الإنترنت بدون تحقق 3D Secure',
        'requires_original' => false,
        'settlement_days' => 1,
        'type' => 'card',
        'advice' => false,
        'offline' => false,
    ],
    
    // ============================================================
    // 3. ADVICE PURCHASE - شراء إرشادي
    // ============================================================
    'purchase_advice' => [
        'id' => '03',
        'label' => 'شراء إرشادي (Advice Purchase)',
        'iso' => '0220',
        'security' => '2D',
        'category' => 'advice',
        'moto_type' => 'advice',
        'description' => 'معاملة إرشادية تتم بعد موافقة مسبقة (ISO 0220)',
        'requires_original' => true,
        'settlement_days' => 1,
        'type' => 'card',
        'advice' => true,
        'offline' => false,
    ],
    
    // ============================================================
    // 4. OFFLINE SALES - MOTO (مبيعات خارج الخط)
    // ============================================================
    'purchase_offline' => [
        'id' => '04',
        'label' => 'مبيعات خارج الخط (Offline - MOTO)',
        'iso' => '0200',
        'security' => '2D',
        'category' => 'offline',
        'moto_type' => 'offline',
        'description' => 'معاملة تتم خارج الإنترنت (هاتف، بريد، فاكس) - MOTO',
        'requires_original' => false,
        'settlement_days' => 1,
        'type' => 'card',
        'advice' => false,
        'offline' => true,
        'moto_indicator' => 'M',
    ],
    
    // ============================================================
    // 5. ONLINE SALES - MOTO (مبيعات عبر الإنترنت - MOTO)
    // ============================================================
    'purchase_online' => [
        'id' => '05',
        'label' => 'مبيعات عبر الإنترنت (Online - MOTO)',
        'iso' => '0200',
        'security' => '2D',
        'category' => 'online',
        'moto_type' => 'online',
        'description' => 'معاملة عبر الإنترنت مع تصنيف MOTO (Mail Order/Telephone Order)',
        'requires_original' => false,
        'settlement_days' => 1,
        'type' => 'card',
        'advice' => false,
        'offline' => false,
        'moto_indicator' => 'M',
    ],
    
    // ============================================================
    // 6. AUTHORIZATION HOLD - تجميد مبلغ
    // ============================================================
    'auth_hold' => [
        'id' => '06',
        'label' => 'تجميد مبلغ (Authorization Hold)',
        'iso' => '0100',
        'security' => '3D',
        'category' => 'auth',
        'moto_type' => null,
        'description' => 'تجميد المبلغ مؤقتاً لحين تأكيد العملية (ISO 0100)',
        'requires_original' => false,
        'settlement_days' => 3,
        'type' => 'card',
        'advice' => false,
        'offline' => false,
    ],
    
    // ============================================================
    // 7. AUTHORIZATION CAPTURE - تأكيد التجميد
    // ============================================================
    'auth_capture' => [
        'id' => '07',
        'label' => 'تأكيد التجميد وتحويله إلى شراء',
        'iso' => '0200',
        'security' => '3D',
        'category' => 'auth',
        'moto_type' => null,
        'description' => 'تأكيد عملية التجميد وتحويلها إلى عملية شراء كاملة',
        'requires_original' => true,
        'settlement_days' => 1,
        'type' => 'card',
        'advice' => false,
        'offline' => false,
    ],
    
    // ============================================================
    // 8. RECURRING - شراء متكرر
    // ============================================================
    'recurring' => [
        'id' => '08',
        'label' => 'شراء متكرر (اشتراك)',
        'iso' => '0200',
        'security' => '3D',
        'category' => 'recurring',
        'moto_type' => null,
        'description' => 'دفع متكرر شهري أو سنوي للاشتراكات',
        'requires_original' => false,
        'settlement_days' => 1,
        'type' => 'card',
        'advice' => false,
        'offline' => false,
        'recurring_indicator' => 'R',
    ],
    
    // ============================================================
    // 9. INSTALLMENT - شراء بالتقسيط
    // ============================================================
    'installment' => [
        'id' => '09',
        'label' => 'شراء بالتقسيط',
        'iso' => '0200',
        'security' => '3D',
        'category' => 'installment',
        'moto_type' => null,
        'description' => 'شراء وتقسيم المبلغ على عدة دفعات شهرية',
        'requires_original' => false,
        'settlement_days' => 1,
        'type' => 'card',
        'advice' => false,
        'offline' => false,
        'installment_indicator' => 'I',
    ],
    
    // ============================================================
    // 10. CRYPTO PURCHASE - شراء عملات رقمية
    // ============================================================
    'crypto_purchase' => [
        'id' => '10',
        'label' => 'شراء عملات رقمية',
        'iso' => '0200',
        'security' => '2D',
        'category' => 'crypto',
        'moto_type' => null,
        'description' => 'شراء USDT/BTC/ETH باستخدام البطاقة',
        'requires_original' => false,
        'settlement_days' => 1,
        'type' => 'crypto',
        'advice' => false,
        'offline' => false,
    ],
    
    // ============================================================
    // 11. GIFT CARD - شراء بطاقة هدايا
    // ============================================================
    'gift_card' => [
        'id' => '11',
        'label' => 'شراء بطاقة هدايا',
        'iso' => '0200',
        'security' => '2D',
        'category' => 'gift',
        'moto_type' => null,
        'description' => 'شراء بطاقة هدايا رقمية',
        'requires_original' => false,
        'settlement_days' => 1,
        'type' => 'card',
        'advice' => false,
        'offline' => false,
    ],
    
    // ============================================================
    // 12. WIRE TRANSFER - تحويل بنكي مباشر
    // ============================================================
    'wire_transfer' => [
        'id' => '12',
        'label' => 'تحويل بنكي مباشر',
        'iso' => '0200',
        'security' => '2D',
        'category' => 'bank',
        'moto_type' => null,
        'description' => 'تحويل مبلغ من البطاقة إلى حساب بنكي',
        'requires_original' => false,
        'settlement_days' => 3,
        'type' => 'bank',
        'advice' => false,
        'offline' => false,
    ],
    
    // ============================================================
    // 13. QUASI CASH - سحب نقدي شبيه
    // ============================================================
    'quasi_cash' => [
        'id' => '13',
        'label' => 'سحب نقدي شبيه (Quasi Cash)',
        'iso' => '0200',
        'security' => '3D',
        'category' => 'cash',
        'moto_type' => null,
        'description' => 'سحب نقدي عبر البطاقة (كازينوهات، مراهنات، إلخ)',
        'requires_original' => false,
        'settlement_days' => 2,
        'type' => 'card',
        'advice' => false,
        'offline' => false,
    ],
];

// ============================================================
// 4. قراءة بيانات الطلب
// ============================================================

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON input'
    ]);
    exit;
}

// ============================================================
// 5. استخراج نوع العملية والبيانات
// ============================================================

$transactionType = trim($data['transaction_type'] ?? 'purchase_3d');
$cardNumber = preg_replace('/\D/', '', $data['card_number'] ?? '');
$cardExpiry = trim($data['card_expiry'] ?? '');
$cardCvv = trim($data['card_cvv'] ?? '');
$cardHolder = trim($data['card_holder'] ?? 'CARDHOLDER');
$amount = floatval($data['amount'] ?? 0);
$currency = strtoupper(trim($data['currency'] ?? 'USD'));
$email = trim($data['email'] ?? '');
$phone = trim($data['phone'] ?? '');
$reference = trim($data['reference'] ?? '');
$ledgerAddress = trim($data['ledger_address'] ?? '');
$originalReference = trim($data['original_reference'] ?? '');
$originalAuthCode = trim($data['original_auth_code'] ?? '');
$motoIndicator = strtoupper(trim($data['moto_indicator'] ?? 'M'));
$installmentCount = intval($data['installment_count'] ?? 0);
$recurringFrequency = trim($data['recurring_frequency'] ?? 'monthly');
$cryptoCurrency = strtoupper(trim($data['crypto_currency'] ?? 'USDT'));
$giftCardAmount = floatval($data['gift_card_amount'] ?? 0);
$bankAccount = $data['bank_account'] ?? [];
$billingAddress = $data['billing_address'] ?? [];
$returnUrl = trim($data['return_url'] ?? 'https://diparmas.com/receipt.php');
$adviceReason = trim($data['advice_reason'] ?? '');
$offlineChannel = trim($data['offline_channel'] ?? 'phone'); // phone, mail, fax

// توليد مرجع إذا لم يتم إرساله
if (empty($reference)) {
    $reference = 'DP_' . strtoupper(bin2hex(random_bytes(6)));
}

// ============================================================
// 6. التحقق من نوع العملية
// ============================================================

if (!isset($TRANSACTION_TYPES[$transactionType])) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'نوع العملية غير مدعوم',
        'supported_types' => array_keys($TRANSACTION_TYPES),
        'supported_types_labels' => array_column($TRANSACTION_TYPES, 'label'),
    ]);
    exit;
}

$txnDef = $TRANSACTION_TYPES[$transactionType];

// ============================================================
// 7. التحقق من صحة البيانات حسب نوع العملية
// ============================================================

$errors = [];

// التحقق الأساسي للبطاقة
if (in_array($txnDef['type'], ['card', 'crypto'])) {
    if (empty($cardNumber) || strlen($cardNumber) < 13 || strlen($cardNumber) > 19) {
        $errors[] = 'رقم البطاقة غير صالح (يجب أن يكون 13-19 رقم)';
    }
    
    $cardType = detectCardType($cardNumber);
    if ($cardType === 'Unknown') {
        $errors[] = 'نوع البطاقة غير مدعوم';
    }
    
    if (!preg_match('/^(0[1-9]|1[0-2])\/([0-9]{2})$/', $cardExpiry)) {
        $errors[] = 'تاريخ الانتهاء غير صالح (صيغة MM/YY)';
    } else {
        list($month, $year) = explode('/', $cardExpiry);
        $expiryTimestamp = mktime(0, 0, 0, intval($month), 1, intval($year) + 2000);
        if ($expiryTimestamp < time()) {
            $errors[] = 'البطاقة منتهية الصلاحية';
        }
    }
    
    if (empty($cardCvv) || strlen($cardCvv) < 3 || strlen($cardCvv) > 4) {
        $errors[] = 'رمز CVV غير صالح (3-4 أرقام)';
    }
}

// التحقق من المبلغ
if ($amount <= 0) {
    $errors[] = 'المبلغ يجب أن يكون أكبر من صفر';
}

// الحد الأقصى للمبلغ حسب نوع العملية
$maxAmounts = [
    'purchase_3d' => 50000,
    'purchase_2d' => 25000,
    'purchase_advice' => 100000,
    'purchase_offline' => 25000,
    'purchase_online' => 25000,
    'auth_hold' => 100000,
    'auth_capture' => 100000,
    'recurring' => 10000,
    'installment' => 50000,
    'crypto_purchase' => 25000,
    'gift_card' => 5000,
    'wire_transfer' => 100000,
    'quasi_cash' => 10000,
];

if ($amount > ($maxAmounts[$transactionType] ?? 50000)) {
    $errors[] = 'المبلغ يتجاوز الحد الأقصى المسموح به (' . number_format($maxAmounts[$transactionType] ?? 50000, 2) . ' USD)';
}

// التحقق من Ledger Address
if (empty($ledgerAddress)) {
    $errors[] = 'عنوان Ledger مطلوب';
}

if (!empty($ledgerAddress) && !preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $ledgerAddress)) {
    $errors[] = 'عنوان Tron غير صالح (يجب أن يبدأ بـ T ويتكون من 34 حرف)';
}

// التحقق الخاص بـ Advice Purchase
if ($transactionType === 'purchase_advice') {
    if (empty($originalReference)) {
        $errors[] = 'المرجع الأصلي مطلوب لعملية شراء إرشادية';
    }
    if (empty($originalAuthCode)) {
        $errors[] = 'رمز الموافقة الأصلي مطلوب لعملية شراء إرشادية';
    }
    if (empty($adviceReason)) {
        $errors[] = 'سبب الإرشاد مطلوب (مثال: تعديل مبلغ، تأكيد متأخر)';
    }
}

// التحقق الخاص بـ Offline Sales - MOTO
if ($transactionType === 'purchase_offline') {
    if (!in_array($offlineChannel, ['phone', 'mail', 'fax', 'other'])) {
        $errors[] = 'قناة الاتصال غير صالحة (phone, mail, fax, other)';
    }
    if (!in_array($motoIndicator, ['M', 'T', 'F', 'O'])) {
        $errors[] = 'رمز MOTO غير صالح (M=Mail, T=Telephone, F=Fax, O=Other)';
    }
}

// التحقق الخاص بـ Online Sales - MOTO
if ($transactionType === 'purchase_online') {
    if (!in_array($motoIndicator, ['M', 'T', 'E'])) {
        $errors[] = 'رمز MOTO غير صالح (M=Mail, T=Telephone, E=E-commerce)';
    }
}

// التحقق الخاص بـ Auth Capture
if ($transactionType === 'auth_capture' && empty($originalReference)) {
    $errors[] = 'المرجع الأصلي مطلوب لعملية تأكيد التجميد';
}

// التحقق الخاص بالتقسيط
if ($transactionType === 'installment' && $installmentCount < 2) {
    $errors[] = 'عدد الدفعات يجب أن يكون 2 على الأقل للتقسيط';
}

// التحقق الخاص بالبطاقات الهدايا
if ($transactionType === 'gift_card' && $giftCardAmount <= 0) {
    $errors[] = 'مبلغ بطاقة الهدايا يجب أن يكون أكبر من صفر';
}

// التحقق من البريد الإلكتروني
if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'البريد الإلكتروني غير صالح';
}

// التحقق من رقم الهاتف
if (!empty($phone) && !preg_match('/^\+?[0-9]{10,15}$/', $phone)) {
    $errors[] = 'رقم الهاتف غير صالح';
}

// إذا كان هناك أخطاء، أعدها للمستخدم
if (!empty($errors)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'فشل التحقق من البيانات',
        'transaction_type' => $transactionType,
        'transaction_label' => $txnDef['label'],
        'errors' => $errors
    ]);
    exit;
}

// ============================================================
// 8. الاتصال بقاعدة البيانات
// ============================================================

$db = db();

// ============================================================
// 9. حساب سعر الصرف (USD → USDT)
// ============================================================

$exchangeRates = getExchangeRates();
$usdtAmount = round($amount * ($exchangeRates[$currency] ?? 1.0), 6);

// ============================================================
// 10. STAGE 1: سحب المبلغ حسب نوع العملية
// ============================================================

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
    'currency' => 'USD',
    'reference' => $reference,
    'order_id' => $reference,
    'transaction_type' => $transactionType,
    'transaction_label' => $txnDef['label'],
    'iso_msg_type' => $txnDef['iso'],
    'security_mode' => $txnDef['security'],
    'category' => $txnDef['category'],
];

// إضافة بيانات البطاقة
if (in_array($txnDef['type'], ['card', 'crypto'])) {
    $diparmaRequest['card'] = [
        'number' => $cardNumber,
        'expiry_month' => substr($cardExpiry, 0, 2),
        'expiry_year' => '20' . substr($cardExpiry, 3, 2),
        'cvv' => $cardCvv,
        'holder_name' => $cardHolder,
        'type' => $cardType ?? 'Unknown',
    ];
}

// إضافة بيانات العميل
$diparmaRequest['customer'] = [
    'email' => $email ?: 'customer@diparmas.com',
    'phone' => $phone ?: '+971501234567',
    'name' => $cardHolder,
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
];

// إضافة عنوان الفوترة
$diparmaRequest['billing_address'] = [
    'address' => $billingAddress['address'] ?? '',
    'city' => $billingAddress['city'] ?? '',
    'country' => $billingAddress['country'] ?? 'AE',
    'zip' => $billingAddress['zip'] ?? '',
];

// إضافة بيانات خاصة حسب نوع العملية
switch ($transactionType) {
    // ============================================================
    // ADVICE PURCHASE - شراء إرشادي
    // ============================================================
    case 'purchase_advice':
        $diparmaRequest['advice'] = [
            'original_reference' => $originalReference,
            'original_auth_code' => $originalAuthCode,
            'reason' => $adviceReason,
            'is_advice' => true,
        ];
        $diparmaRequest['is_advice'] = true;
        break;
    
    // ============================================================
    // OFFLINE SALES - MOTO
    // ============================================================
    case 'purchase_offline':
        $diparmaRequest['moto'] = [
            'indicator' => $motoIndicator,
            'channel' => $offlineChannel,
            'is_moto' => true,
            'is_offline' => true,
        ];
        $diparmaRequest['is_moto'] = true;
        $diparmaRequest['is_offline'] = true;
        $diparmaRequest['moto_indicator'] = $motoIndicator;
        break;
    
    // ============================================================
    // ONLINE SALES - MOTO
    // ============================================================
    case 'purchase_online':
        $diparmaRequest['moto'] = [
            'indicator' => $motoIndicator,
            'channel' => 'online',
            'is_moto' => true,
            'is_offline' => false,
        ];
        $diparmaRequest['is_moto'] = true;
        $diparmaRequest['is_offline'] = false;
        $diparmaRequest['moto_indicator'] = $motoIndicator;
        break;
    
    // ============================================================
    // AUTH HOLD - تجميد مبلغ
    // ============================================================
    case 'auth_hold':
        $diparmaRequest['is_auth_only'] = true;
        $diparmaRequest['is_capture'] = false;
        $diparmaRequest['hold_days'] = 7;
        break;
    
    // ============================================================
    // AUTH CAPTURE - تأكيد التجميد
    // ============================================================
    case 'auth_capture':
        $diparmaRequest['original_reference'] = $originalReference;
        $diparmaRequest['is_auth_only'] = false;
        $diparmaRequest['is_capture'] = true;
        break;
    
    // ============================================================
    // RECURRING - شراء متكرر
    // ============================================================
    case 'recurring':
        $diparmaRequest['recurring'] = [
            'frequency' => $recurringFrequency,
            'start_date' => date('Y-m-d'),
            'end_date' => date('Y-m-d', strtotime('+1 year')),
            'max_occurrences' => 12,
            'indicator' => 'R',
        ];
        break;
    
    // ============================================================
    // INSTALLMENT - شراء بالتقسيط
    // ============================================================
    case 'installment':
        $diparmaRequest['installment'] = [
            'count' => $installmentCount,
            'first_amount' => round($amount / $installmentCount, 2),
            'remaining_amount' => $amount - round($amount / $installmentCount, 2),
            'indicator' => 'I',
        ];
        break;
    
    // ============================================================
    // CRYPTO PURCHASE - شراء عملات رقمية
    // ============================================================
    case 'crypto_purchase':
        $diparmaRequest['crypto'] = [
            'currency' => $cryptoCurrency,
            'amount' => $amount,
            'usdt_amount' => $usdtAmount,
        ];
        break;
    
    // ============================================================
    // GIFT CARD - شراء بطاقة هدايا
    // ============================================================
    case 'gift_card':
        $diparmaRequest['gift_card'] = [
            'amount' => $giftCardAmount,
            'currency' => 'USD',
            'recipient_email' => $data['recipient_email'] ?? $email,
            'recipient_name' => $data['recipient_name'] ?? $cardHolder,
            'message' => $data['gift_message'] ?? '',
        ];
        break;
    
    // ============================================================
    // WIRE TRANSFER - تحويل بنكي
    // ============================================================
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
    
    // ============================================================
    // QUASI CASH - سحب نقدي شبيه
    // ============================================================
    case 'quasi_cash':
        $diparmaRequest['quasi_cash'] = [
            'purpose' => $data['purpose'] ?? 'Gaming/Entertainment',
            'reference' => $reference . '-QC',
        ];
        break;
}

// إضافة روابط الإرجاع
$diparmaRequest['return_url'] = $returnUrl . '?ref=' . $reference . '&type=' . $transactionType;
$diparmaRequest['webhook_url'] = 'https://diparmas.com/webhooks/diparma.php';
$diparmaRequest['expiry_minutes'] = 30;

// ============================================================
// إرسال الطلب إلى DI PARMA Gateway
// ============================================================

$diparmaResponse = sendToDIPARMA($diparmaRequest, $diparmaConfig);

// التحقق من استجابة DI PARMA
if (!$diparmaResponse['success']) {
    // تسجيل الفشل
    $db->execute(
        "INSERT INTO dp_transactions 
         (reference, gateway, gateway_type, transaction_type, transaction_label,
          amount, currency, card_last4, cardholder_name,
          security_mode, status, gateway_response, ledger_address,
          error_message, error_code, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'failed', ?, ?, ?, ?, NOW())",
        [
            $reference,
            'diparma',
            'direct_card',
            $transactionType,
            $txnDef['label'],
            $amount,
            'USD',
            substr($cardNumber, -4),
            $cardHolder,
            $txnDef['security'],
            json_encode($diparmaResponse),
            $ledgerAddress,
            $diparmaResponse['message'] ?? 'فشل المعاملة',
            $diparmaResponse['error_code'] ?? 'unknown',
        ]
    );

    http_response_code(402);
    echo json_encode([
        'success' => false,
        'reference' => $reference,
        'transaction_type' => $transactionType,
        'transaction_label' => $txnDef['label'],
        'stage' => 'card_charge',
        'message' => $diparmaResponse['message'] ?? 'فشل سحب المبلغ من البطاقة',
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
// 11. STAGE 2: إرسال USDT إلى Ledger
// ============================================================

$tronConfig = [
    'api_key' => getenv('TRONGRID_API_KEY') ?: 'YOUR_TRONGRID_API_KEY',
    'hot_wallet_address' => getenv('HOT_WALLET_TRC20_ADDRESS') ?: 'YOUR_HOT_WALLET_ADDRESS',
    'hot_wallet_private_key' => getenv('HOT_WALLET_TRC20_KEY') ?: 'YOUR_HOT_WALLET_PRIVATE_KEY',
    'usdt_contract' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
];

$ledgerTxid = null;
$ledgerStatus = 'not_applicable';

if ($transactionType !== 'crypto_purchase') {
    $ledgerStatus = 'not_applicable';
} elseif (empty($tronConfig['hot_wallet_private_key']) || empty($tronConfig['hot_wallet_address'])) {
    $ledgerStatus = 'queued';
    $db->execute(
        "INSERT INTO ledger_transfer_queue 
         (reference, ledger_address, usdt_amount, transaction_type, status, message, created_at)
         VALUES (?, ?, ?, ?, 'queued', ?, NOW())",
        [
            $reference,
            $ledgerAddress,
            $usdtAmount,
            $transactionType,
            'في انتظار معالجة يدوية - نقص بيانات TronGrid',
        ]
    );
} else {
    try {
        $tronResult = sendUSDTToLedger($ledgerAddress, $usdtAmount, $tronConfig);
        
        if ($tronResult['success']) {
            $ledgerTxid = $tronResult['txid'];
            $ledgerStatus = 'completed';
        } else {
            $ledgerStatus = 'failed';
            $db->execute(
                "INSERT INTO ledger_transfer_queue 
                 (reference, ledger_address, usdt_amount, transaction_type, status, message, created_at)
                 VALUES (?, ?, ?, ?, 'failed', ?, NOW())",
                [
                    $reference,
                    $ledgerAddress,
                    $usdtAmount,
                    $transactionType,
                    $tronResult['message'] ?? 'فشل إرسال USDT',
                ]
            );
        }
    } catch (Exception $e) {
        $ledgerStatus = 'failed';
        error_log('[DI PARMA] TronGrid Error: ' . $e->getMessage());
        $db->execute(
            "INSERT INTO ledger_transfer_queue 
             (reference, ledger_address, usdt_amount, transaction_type, status, message, created_at)
             VALUES (?, ?, ?, ?, 'failed', ?, NOW())",
            [
                $reference,
                $ledgerAddress,
                $usdtAmount,
                $transactionType,
                'خطأ في TronGrid: ' . $e->getMessage(),
            ]
        );
    }
}

// ============================================================
// 12. تسجيل المعاملة الكاملة
// ============================================================

$gatewayResponse = json_encode([
    'transaction' => [
        'type' => $transactionType,
        'label' => $txnDef['label'],
        'iso' => $txnDef['iso'],
        'security' => $txnDef['security'],
        'category' => $txnDef['category'],
        'settlement_days' => $txnDef['settlement_days'],
        'is_advice' => $txnDef['advice'] ?? false,
        'is_offline' => $txnDef['offline'] ?? false,
        'moto_indicator' => $txnDef['moto_indicator'] ?? null,
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
        'exchange_rate' => $exchangeRates['USD'] ?? 1.0,
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

$finalStatus = ($ledgerStatus === 'completed') ? 'completed' : 'pending_ledger';

$db->execute(
    "INSERT INTO dp_transactions 
     (reference, gateway, gateway_type, transaction_type, transaction_label,
      amount, currency, card_last4, cardholder_name,
      security_mode, status, gateway_response,
      ledger_txid, ledger_transferred, ledger_amount, ledger_address,
      auth_code, rrn, approval_code, acquirer,
      original_reference, original_auth_code,
      installment_count, recurring_frequency,
      moto_indicator, is_advice, is_offline,
      created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
    [
        $reference,
        'diparma',
        'direct_card',
        $transactionType,
        $txnDef['label'],
        $amount,
        'USD',
        substr($cardNumber, -4),
        $cardHolder,
        $txnDef['security'],
        $finalStatus,
        $gatewayResponse,
        $ledgerTxid,
        $ledgerStatus === 'completed' ? 1 : 0,
        $usdtAmount,
        $ledgerAddress,
        $authCode,
        $rrn,
        $approvalCode,
        $acquirerName,
        $originalReference ?? null,
        $originalAuthCode ?? null,
        $installmentCount ?? 0,
        $recurringFrequency ?? null,
        $txnDef['moto_indicator'] ?? null,
        $txnDef['advice'] ? 1 : 0,
        $txnDef['offline'] ? 1 : 0,
    ]
);

// ============================================================
// 13. إرسال Webhook
// ============================================================

$webhookUrl = $data['webhook_url'] ?? getenv('DEFAULT_WEBHOOK_URL') ?? '';

if (!empty($webhookUrl)) {
    $webhookData = [
        'event' => 'charge.completed',
        'gateway' => 'DI PARMA Direct',
        'transaction_type' => $transactionType,
        'transaction_label' => $txnDef['label'],
        'reference' => $reference,
        'amount' => $amount,
        'currency' => 'USD',
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
        'moto_indicator' => $txnDef['moto_indicator'] ?? null,
        'is_advice' => $txnDef['advice'] ?? false,
        'is_offline' => $txnDef['offline'] ?? false,
        'timestamp' => date('c'),
        'status' => $finalStatus,
    ];
    
    sendAsyncWebhook($webhookUrl, $webhookData);
}

// ============================================================
// 14. الرد النهائي
// ============================================================

http_response_code(200);
echo json_encode([
    'success' => true,
    'gateway' => 'DI PARMA Direct',
    'transaction_type' => $transactionType,
    'transaction_label' => $txnDef['label'],
    'transaction_description' => $txnDef['description'],
    'iso_msg_type' => $txnDef['iso'],
    'security_mode' => $txnDef['security'],
    'category' => $txnDef['category'],
    'reference' => $reference,
    'status' => $finalStatus,
    'amount' => $amount,
    'currency' => 'USD',
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
        'moto_indicator' => $txnDef['moto_indicator'] ?? null,
        'is_advice' => $txnDef['advice'] ?? false,
        'is_offline' => $txnDef['offline'] ?? false,
        'advice_reason' => $adviceReason ?? null,
        'offline_channel' => $offlineChannel ?? null,
    ],
    'message' => $ledgerStatus === 'completed' 
        ? '✅ ' . $txnDef['label'] . ' تم بنجاح وإرسال المبلغ إلى Ledger'
        : '✅ ' . $txnDef['label'] . ' تم، جاري معالجة الإرسال إلى Ledger',
    'timestamp' => date('c'),
]);

// ============================================================
// الوظائف المساعدة
// ============================================================

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
                'message' => 'تم سحب المبلغ بنجاح',
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
            $errorMessage = $errorCodes[$errorCode] ?? $result['message'] ?? 'فشل المعاملة';
            
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
            'message' => 'خطأ في الاتصال بالبنك: ' . $e->getMessage(),
            'error_code' => 'connection_error',
        ];
    }
}

function sendUSDTToLedger($toAddress, $amount, $config) {
    try {
        if (!preg_match('/^T[A-Za-z0-9]{33}$/', (string)$toAddress)) {
            return ['success' => false, 'message' => 'عنوان Tron غير صالح'];
        }
        if ((float)$amount <= 0) {
            return ['success' => false, 'message' => 'مبلغ USDT غير صالح'];
        }

        $reference = 'DP-' . strtoupper(bin2hex(random_bytes(8)));
        $payout = (new PayRamAdapter())->createPayout([
            'email' => 'client@diparmas.com',
            'blockchain_code' => 'TRX',
            'currency_code' => 'USDT',
            'amount' => (float)$amount,
            'to_address' => $toAddress,
            'customer_id' => $reference,
            'idempotency_key' => $reference,
        ]);

        if (!$payout['success']) {
            return ['success' => false, 'message' => $payout['raw']['message'] ?? 'Payram رفض تحويل USDT'];
        }

        return [
            'success' => true,
            'txid' => $payout['tx_hash'],
            'payout_id' => $payout['payout_id'],
            'status' => $payout['status'],
            'message' => 'تم قبول طلب تحويل USDT من Payram، بانتظار تأكيد الشبكة',
        ];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'خطأ في Payram: ' . $e->getMessage()];
    }
}

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

function detectCardType($number) {
    $patterns = [
        'Visa' => '/^4[0-9]{12}(?:[0-9]{3})?$/',
        'Mastercard' => '/^(5[1-5][0-9]{14}|2(22[1-9][0-9]{12}|2[3-9][0-9]{13}|[3-6][0-9]{14}|7[0-1][0-9]{13}|720[0-9]{12}))$/',
        'Amex' => '/^3[47][0-9]{13}$/',
        'Diners' => '/^3(?:0[0-5]|[68][0-9])[0-9]{11}$/',
        'Discover' => '/^6(?:011|5[0-9]{2})[0-9]{12}$/',
        'JCB' => '/^(?:2131|1800|35\d{3})\d{11}$/',
        'UnionPay' => '/^62[0-9]{14,17}$/',
    ];
    
    foreach ($patterns as $type => $pattern) {
        if (preg_match($pattern, $number)) {
            return $type;
        }
    }
    return 'Unknown';
}

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
                file_put_contents($cacheFile, json_encode($rates));
            }
        }
    } catch (Exception $e) {
        // استخدام الأسعار الافتراضية
    }
    
    return $rates;
}

function sendAsyncWebhook($url, $data) {
    if (empty($url)) return;
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-DI-PARMA-Signature: ' . hash_hmac('sha256', json_encode($data), getenv('WEBHOOK_SECRET') ?: 'default-secret'),
            'X-DI-PARMA-Event: charge.completed',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_FRESH_CONNECT => true,
        CURLOPT_FORBID_REUSE => true,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// ============================================================
// نهاية الملف
// ============================================================
?>