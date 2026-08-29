<?php
/**
 * ============================================================
 * DI PARMA | GET /api/v1/transactions
 * ============================================================
 * 
 * جلب قائمة المعاملات للعميل المصادق
 * 
 * ============================================================
 * المعلمات (GET):
 *   limit   - عدد النتائج (1-100, default: 20)
 *   offset  - بداية الصفحة (default: 0)
 *   status  - تصفية حسب الحالة (pending, processing, completed, failed, etc.)
 *   type    - تصفية حسب نوع العملية (purchase_3d, purchase_2d, refund, etc.)
 *   from    - تاريخ البداية (Y-m-d)
 *   to      - تاريخ النهاية (Y-m-d)
 *   sort    - ترتيب (DESC/ASC, default: DESC)
 * 
 * ============================================================
 * مثال الاستخدام:
 *   GET /api/v1/transactions?limit=10&offset=0&status=completed
 * 
 * ============================================================
 * الرد:
 *   {
 *     "success": true,
 *     "total": 150,
 *     "limit": 10,
 *     "offset": 0,
 *     "transactions": [...]
 *   }
 * ============================================================
 */

// ============================================================
// 1. استيراد الملفات المطلوبة
// ============================================================

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/ApiAuth.php';

// ============================================================
// 2. إعدادات الرأس
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

// ============================================================
// 3. التحقق من المصادقة
// ============================================================

try {
    $client = ApiAuth::verify();
    $clientId = $client['id'] ?? 0;
    $apiKey = $client['api_key'] ?? '';
} catch (Exception $e) {
    // ApiAuth يوقف التنفيذ تلقائياً في حالة الفشل
    exit;
}

// ============================================================
// 4. استقبال المعلمات
// ============================================================

$limit = min(100, max(1, intval($_GET['limit'] ?? 20)));
$offset = max(0, intval($_GET['offset'] ?? 0));
$status = trim($_GET['status'] ?? '');
$type = trim($_GET['type'] ?? '');
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$sort = strtoupper(trim($_GET['sort'] ?? 'DESC'));
$search = trim($_GET['search'] ?? '');

// التحقق من صحة الترتيب
$sort = in_array($sort, ['ASC', 'DESC']) ? $sort : 'DESC';

// ============================================================
// 5. بناء الاستعلام
// ============================================================

$db = db();

// 5.1 استعلام أساسي
$sql = "SELECT 
            id,
            reference,
            gateway,
            gateway_type,
            transaction_type,
            transaction_label,
            amount,
            currency,
            card_last4,
            cardholder_name,
            security_mode,
            status,
            gateway_response,
            ledger_txid,
            ledger_transferred,
            ledger_amount,
            ledger_address,
            auth_code,
            rrn,
            approval_code,
            acquirer,
            original_reference,
            installment_count,
            recurring_frequency,
            moto_indicator,
            is_advice,
            is_offline,
            error_message,
            created_at
        FROM dp_transactions
        WHERE 1=1";

$params = [];

// 5.2 تصفية حسب العميل (إذا كان هناك user_id)
if (!empty($client['user_id'])) {
    $sql .= " AND user_id = ?";
    $params[] = $client['user_id'];
}

// 5.3 تصفية حسب البوابة (جميع البوابات)
// نأخذ جميع المعاملات من جميع البوابات لأن العميل يستخدم API Key واحد

// 5.4 تصفية حسب الحالة
if (!empty($status)) {
    $sql .= " AND status = ?";
    $params[] = $status;
}

// 5.5 تصفية حسب نوع العملية
if (!empty($type)) {
    $sql .= " AND transaction_type = ?";
    $params[] = $type;
}

// 5.6 تصفية حسب التاريخ
if (!empty($from)) {
    $sql .= " AND DATE(created_at) >= ?";
    $params[] = $from;
}
if (!empty($to)) {
    $sql .= " AND DATE(created_at) <= ?";
    $params[] = $to;
}

// 5.7 بحث نصي
if (!empty($search)) {
    $sql .= " AND (reference LIKE ? OR cardholder_name LIKE ? OR auth_code LIKE ? OR rrn LIKE ?)";
    $searchTerm = '%' . $search . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

// 5.8 الترتيب
$sql .= " ORDER BY created_at " . $sort;

// 5.9 التحديد
$sql .= " LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

// ============================================================
// 6. تنفيذ الاستعلام
// ============================================================

try {
    $rows = $db->query($sql, $params);
} catch (Exception $e) {
    ApiAuth::abort(500, 'database_error', 'Failed to fetch transactions: ' . $e->getMessage());
}

// ============================================================
// 7. معالجة البيانات وإضافة معلومات إضافية
// ============================================================

$transactions = [];
foreach ($rows as $row) {
    // فك تشفير gateway_response
    $gr = json_decode($row['gateway_response'] ?? '{}', true) ?: [];
    
    // استخراج معلومات Ledger
    $ledgerInfo = [];
    if ($row['ledger_txid'] || $row['ledger_address']) {
        $ledgerInfo = [
            'address' => $row['ledger_address'],
            'txid' => $row['ledger_txid'],
            'transferred' => (bool)$row['ledger_transferred'],
            'amount' => $row['ledger_amount'],
            'explorer' => $row['ledger_txid'] 
                ? 'https://tronscan.org/#/transaction/' . $row['ledger_txid'] 
                : null,
        ];
    }
    
    // استخراج معلومات من stage_1_card إذا وجدت
    $cardInfo = [];
    if (isset($gr['stage_1_card'])) {
        $card = $gr['stage_1_card'];
        $cardInfo = [
            'auth_code' => $card['auth_code'] ?? $row['auth_code'],
            'rrn' => $card['rrn'] ?? $row['rrn'],
            'nuvei_txn_id' => $card['nuvei_txn'] ?? null,
            'card_last4' => $card['card_last4'] ?? $row['card_last4'],
            'sec_mode' => $card['sec_mode'] ?? $row['security_mode'],
        ];
    }
    
    // بناء المعاملة النهائية
    $transactions[] = [
        'reference' => $row['reference'],
        'gateway' => $row['gateway'],
        'gateway_type' => $row['gateway_type'],
        'transaction_type' => $row['transaction_type'],
        'transaction_label' => $row['transaction_label'],
        'amount' => (float)$row['amount'],
        'currency' => $row['currency'],
        'status' => $row['status'],
        'security_mode' => $row['security_mode'],
        'card_last4' => $row['card_last4'],
        'cardholder_name' => $row['cardholder_name'],
        'auth_code' => $row['auth_code'],
        'rrn' => $row['rrn'],
        'approval_code' => $row['approval_code'],
        'acquirer' => $row['acquirer'],
        'original_reference' => $row['original_reference'],
        'installment_count' => (int)$row['installment_count'],
        'recurring_frequency' => $row['recurring_frequency'],
        'moto_indicator' => $row['moto_indicator'],
        'is_advice' => (bool)$row['is_advice'],
        'is_offline' => (bool)$row['is_offline'],
        'ledger' => $ledgerInfo,
        'card_info' => $cardInfo,
        'error_message' => $row['error_message'],
        'created_at' => $row['created_at'],
        'timestamp' => strtotime($row['created_at']),
    ];
}

// ============================================================
// 8. حساب العدد الإجمالي (لصفحات التصفح)
// ============================================================

$countSql = "SELECT COUNT(*) as total FROM dp_transactions WHERE 1=1";
$countParams = [];

// نسخ نفس الشروط (بدون LIMIT و ORDER)
if (!empty($client['user_id'])) {
    $countSql .= " AND user_id = ?";
    $countParams[] = $client['user_id'];
}
if (!empty($status)) {
    $countSql .= " AND status = ?";
    $countParams[] = $status;
}
if (!empty($type)) {
    $countSql .= " AND transaction_type = ?";
    $countParams[] = $type;
}
if (!empty($from)) {
    $countSql .= " AND DATE(created_at) >= ?";
    $countParams[] = $from;
}
if (!empty($to)) {
    $countSql .= " AND DATE(created_at) <= ?";
    $countParams[] = $to;
}
if (!empty($search)) {
    $countSql .= " AND (reference LIKE ? OR cardholder_name LIKE ? OR auth_code LIKE ? OR rrn LIKE ?)";
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
}

try {
    $countRow = $db->query($countSql, $countParams);
    $total = (int)($countRow[0]['total'] ?? 0);
} catch (Exception $e) {
    $total = count($rows);
}

// ============================================================
// 9. الرد النهائي
// ============================================================

http_response_code(200);
echo json_encode([
    'success' => true,
    'total' => $total,
    'limit' => $limit,
    'offset' => $offset,
    'count' => count($transactions),
    'transactions' => $transactions,
    'timestamp' => date('c'),
    'client_id' => $clientId,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

// ============================================================
// 10. تسجيل السجل
// ============================================================

ApiAuth::log(
    $clientId,
    $apiKey,
    $_SERVER['REQUEST_URI'] ?? '',
    'GET',
    json_encode($_GET),
    200,
    json_encode(['success' => true, 'total' => $total]),
    null,
    null
);

// ============================================================
// نهاية الملف
// ============================================================
?>