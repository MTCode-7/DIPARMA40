<?php
/**
 * ============================================================
 * DI PARMA | Offline Approvals - إدارة المدفوعات اليدوية
 * ============================================================
 * 
 * هذا الملف هو لوحة تحكم المسؤول لإدارة المدفوعات اليدوية
 * 
 * ============================================================
 */

// ============================================================
// [1] استيراد الملفات والتحقق من الصلاحيات
// ============================================================

require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/transaction_types.php';
requireAdmin();

// ============================================================
// [2] إعدادات الأساسية
// ============================================================

$db = db();
$msg = '';
$msgType = '';
$csrfToken = generateCsrfToken();

// ============================================================
// [3] التأكد من وجود الجداول المطلوبة
// ============================================================

try {
    // التحقق من جدول user_crypto_wallets
    $db->query("SELECT 1 FROM user_crypto_wallets LIMIT 1");
} catch (Exception $e) {
    // إنشاء الجدول إذا لم يكن موجوداً
    $db->execute("
        CREATE TABLE IF NOT EXISTS user_crypto_wallets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            coin VARCHAR(10) NOT NULL,
            network VARCHAR(20) NOT NULL,
            balance DECIMAL(20,8) DEFAULT 0,
            locked DECIMAL(20,8) DEFAULT 0,
            unlock_at DATETIME NULL,
            status VARCHAR(20) DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user_coin_network (user_id, coin, network)
        )
    ");
}

try {
    // التحقق من جدول wallet_transactions
    $db->query("SELECT 1 FROM wallet_transactions LIMIT 1");
} catch (Exception $e) {
    // إنشاء الجدول إذا لم يكن موجوداً
    $db->execute("
        CREATE TABLE IF NOT EXISTS wallet_transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            reference VARCHAR(50) NOT NULL,
            user_id INT NOT NULL,
            type VARCHAR(20) NOT NULL,
            wallet_type VARCHAR(20) NOT NULL,
            coin VARCHAR(10) NOT NULL,
            network VARCHAR(20) NOT NULL,
            amount DECIMAL(20,8) NOT NULL,
            fee DECIMAL(20,8) DEFAULT 0,
            net_amount DECIMAL(20,8) NOT NULL,
            status VARCHAR(20) DEFAULT 'pending',
            note TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_reference (reference)
        )
    ");
}

try {
    // التحقق من جدول dp_audit_logs
    $db->query("SELECT 1 FROM dp_audit_logs LIMIT 1");
} catch (Exception $e) {
    // إنشاء الجدول إذا لم يكن موجوداً
    $db->execute("
        CREATE TABLE IF NOT EXISTS dp_audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            action VARCHAR(100) NOT NULL,
            resource VARCHAR(100),
            resource_id VARCHAR(100),
            details JSON,
            ip_address VARCHAR(45),
            user_agent TEXT,
            status VARCHAR(20) DEFAULT 'success',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_action (action)
        )
    ");
}

// ============================================================
// [4] معالجة التنفيذ المباشر
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'direct_execute') {
    
    // 4.1 التحقق من CSRF Token
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $msg = '❌ فشل التحقق الأمني (CSRF). حاول مرة أخرى.';
        $msgType = 'error';
    } else {
        
        // 4.2 استقبال البيانات
        $userId = intval($_POST['user_id'] ?? 0);
        $amount = floatval($_POST['amount'] ?? 0);
        $currency = strtoupper(trim($_POST['currency'] ?? 'USD'));
        $coin = strtoupper(trim($_POST['coin'] ?? 'USDT'));
        $network = strtoupper(trim($_POST['network'] ?? 'TRC20'));
        $approval = trim($_POST['approval_code'] ?? '');
        $cardLast4 = trim($_POST['card_last4'] ?? '');
        $note = trim($_POST['note'] ?? '');
        $txnType = trim($_POST['transaction_type'] ?? 'admin_direct');
        $txnTypeInfo = getTransactionType($txnType);
        
        // 4.3 التحقق من صحة البيانات
        if ($userId <= 0) {
            $msg = '❌ يرجى اختيار مستخدم صالح.';
            $msgType = 'error';
        } elseif ($amount <= 0) {
            $msg = '❌ المبلغ يجب أن يكون أكبر من صفر.';
            $msgType = 'error';
        } elseif (empty($approval) || strlen($approval) < 4) {
            $msg = '❌ Approval Code مطلوب (4-6 أرقام).';
            $msgType = 'error';
        } else {
            
            try {
                // 4.4 حساب التوزيع
                $totalCrypto = $amount;
                $adminShare = round($totalCrypto * 0.75, 8);
                $clientShare = round($totalCrypto * 0.25, 8);
                $unlockAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
                $reference = 'ADMIN' . date('Ymd') . '_' . strtoupper(bin2hex(random_bytes(4)));
                
                // 4.5 بدء المعاملة
                $db->execute("START TRANSACTION");
                
                // 4.6 حفظ المعاملة في قاعدة البيانات
                $db->insert('dp_transactions', [
                    'reference' => $reference,
                    'user_id' => $userId,
                    'gateway' => 'offline',
                    'gateway_type' => 'manual',
                    'transaction_type' => $txnType,
                    'transaction_label' => ($txnTypeInfo['ar'] ?? 'إدارة يدوية') . ' - ' . $coin . '/' . $network,
                    'amount' => $amount,
                    'currency' => $currency,
                    'card_last4' => $cardLast4,
                    'cardholder_name' => $note ?: 'Admin Direct',
                    'security_mode' => '2D',
                    'status' => 'completed',
                    'gateway_response' => json_encode([
                        'protocol' => '201.3',
                        'payment_type' => strtoupper($txnType),
                        'transaction_type_code' => $txnType,
                        'transaction_type_ar' => $txnTypeInfo['ar'] ?? 'غير محدد',
                        'transaction_type_en' => $txnTypeInfo['en'] ?? 'Undefined',
                        'coin' => $coin,
                        'network' => $network,
                        'crypto_amount' => $totalCrypto,
                        'admin_share' => $adminShare,
                        'client_share' => $clientShare,
                        'approval_code' => $approval,
                        'card_last4' => $cardLast4,
                        'executed_by' => $_SESSION['username'] ?? 'admin',
                        'executed_at' => date('Y-m-d H:i:s'),
                    ]),
                    'auth_code' => $approval,
                    'rrn' => 'OFFLINE_' . substr($reference, -8),
                    'acquirer' => 'Offline Manual',
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                
                // 4.7 إضافة رصيد للعميل
                _addCryptoBalance($db, $userId, $coin, $network, $clientShare, $unlockAt, $reference);
                
                // 4.8 تسجيل في سجل التدقيق
                _logAudit($db, $userId, 'admin_direct', $reference, $amount, $currency);
                
                // 4.9 إتمام المعاملة
                $db->execute("COMMIT");
                
                $txnLabel = ($txnTypeInfo['ar'] ?? 'معاملة') . ' (' . ($txnTypeInfo['en'] ?? '') . ')';
                $msg = '✅ تم التنفيذ — ' . $txnLabel . ' | ' . number_format($clientShare, 8) . ' ' . $coin . ' أُضيفت لمحفظة العميل #' . $userId . ' | مرجع: ' . $reference;
                $msgType = 'success';
                
            } catch (Exception $e) {
                $db->execute("ROLLBACK");
                $msg = '❌ خطأ: ' . $e->getMessage();
                $msgType = 'error';
                error_log('[Offline Approvals] Error: ' . $e->getMessage());
            }
        }
    }
}

// ============================================================
// [5] جلب المستخدمين النشطين
// ============================================================

$users = [];
try {
    $users = $db->query(
        "SELECT id, username, email FROM dp_users WHERE status = 'active' ORDER BY username"
    ) ?: [];
} catch (Exception $e) {
    error_log('[Offline Approvals] Users query error: ' . $e->getMessage());
}

// ============================================================
// [6] دوال مساعدة
// ============================================================

/**
 * إضافة رصيد العملات الرقمية للمستخدم
 */
function _addCryptoBalance($db, $userId, $coin, $network, $amount, $unlockAt, $reference) {
    try {
        // 8.1 التحقق من وجود محفظة
        $existing = $db->query(
            "SELECT id FROM user_crypto_wallets WHERE user_id = ? AND coin = ? AND network = ?",
            [$userId, $coin, $network]
        );
        
        if (!empty($existing)) {
            // تحديث الرصيد
            $db->execute(
                "UPDATE user_crypto_wallets 
                 SET balance = balance + ?, 
                     unlock_at = ?,
                     updated_at = NOW()
                 WHERE user_id = ? AND coin = ? AND network = ?",
                [$amount, $unlockAt, $userId, $coin, $network]
            );
        } else {
            // إنشاء محفظة جديدة
            $db->execute(
                "INSERT INTO user_crypto_wallets (user_id, coin, network, balance, locked, unlock_at, status, created_at) 
                 VALUES (?, ?, ?, ?, 0, ?, 'active', NOW())",
                [$userId, $coin, $network, $amount, $unlockAt]
            );
        }
        
        // 8.2 تسجيل حركة المحفظة
        $db->execute(
            "INSERT INTO wallet_transactions 
             (reference, user_id, type, wallet_type, coin, network, amount, fee, net_amount, status, note, created_at)
             VALUES (?, ?, 'deposit', 'crypto', ?, ?, ?, 0, ?, 'completed', ?, NOW())",
            [
                $reference . '_W',
                $userId,
                $coin,
                $network,
                $amount,
                $amount,
                '25% Offline — مقفلة 24 ساعة | Reference: ' . $reference
            ]
        );
        
        return true;
        
    } catch (Exception $e) {
        error_log('[AddCryptoBalance] Error: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * تسجيل في سجل التدقيق
 */
function _logAudit($db, $userId, $action, $reference, $amount, $currency) {
    try {
        $db->insert('dp_audit_logs', [
            'user_id' => $userId,
            'action' => $action,
            'resource' => 'transaction',
            'resource_id' => $reference,
            'details' => json_encode([
                'amount' => $amount,
                'currency' => $currency,
                'reference' => $reference,
            ]),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'status' => 'success',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return true;
    } catch (Exception $e) {
        error_log('[Audit] Error: ' . $e->getMessage());
        return false;
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DI PARMA | Offline Approvals</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--gold:#FFD700;--gold2:#FFB700;--bg:#040810;--card:#080d1a;--card2:#0a1020;
  --border:rgba(255,215,0,.15);--text:#f0f0f0;--muted:#666;--green:#10B981;--red:#EF4444}
body{font-family:'Cairo',sans-serif;background:var(--bg);color:var(--text)}
.top-bar{background:rgba(4,8,16,.95);border-bottom:1px solid var(--border);padding:0 24px;
  height:60px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:99}
.top-brand{color:var(--gold);font-weight:900;font-size:1.1rem}
.top-brand i{color:var(--gold);margin-left:8px}
.top-nav a{color:var(--muted);font-size:.82rem;padding:6px 14px;border-radius:20px;text-decoration:none;transition:.2s}
.top-nav a:hover{color:var(--gold)}
.top-nav a.active{color:var(--gold);background:rgba(255,215,0,.05)}
.wrap{max-width:1300px;margin:0 auto;padding:28px 20px}
.pg-title{font-size:1.3rem;font-weight:900;margin-bottom:24px;display:flex;align-items:center;gap:10px}
.pg-title i{color:var(--gold)}
.msg{padding:14px 18px;border-radius:12px;margin-bottom:20px;font-size:.88rem;font-weight:700}
.msg.success{background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.3);color:#10B981}
.msg.error{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#EF4444}
.msg.info{background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.3);color:#3B82F6}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:32px}
.card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:24px}
.card-title{font-size:.95rem;font-weight:800;margin-bottom:18px;display:flex;align-items:center;gap:8px}
.card-title i{color:var(--gold)}
.fld{margin-bottom:14px}
.fld label{display:block;font-size:.75rem;color:var(--muted);margin-bottom:5px;font-weight:600}
.fld label .required{color:var(--red)}
.fld input,.fld select{width:100%;background:rgba(255,255,255,.04);border:1.5px solid var(--border);
  border-radius:10px;padding:10px 14px;color:var(--text);font-family:'Cairo',sans-serif;font-size:.88rem}
.fld input:focus,.fld select:focus{outline:none;border-color:var(--gold)}
.fld-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.btn{padding:10px 20px;border-radius:10px;border:none;cursor:pointer;font-family:'Cairo',sans-serif;font-size:.85rem;font-weight:700;transition:.2s;display:inline-flex;align-items:center;gap:8px}
.btn-gold{background:linear-gradient(135deg,var(--gold),var(--gold2));color:#000}
.btn-gold:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(255,215,0,.2)}
.btn-green{background:rgba(16,185,129,.15);border:1px solid rgba(16,185,129,.3);color:#10B981}
.btn-green:hover{background:rgba(16,185,129,.25)}
.btn-red{background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.3);color:#EF4444}
.btn-red:hover{background:rgba(239,68,68,.25)}
.btn-full{width:100%;padding:12px;margin-top:8px;justify-content:center}
.btn-sm{padding:6px 12px;font-size:.75rem}
.tbl-wrap{background:var(--card);border:1px solid var(--border);border-radius:18px;overflow:hidden}
.tbl-head{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px}
.tbl-head h3{font-size:.95rem;font-weight:800}
.tbl-head .badge{background:rgba(251,191,36,.15);color:#FBB724;padding:4px 12px;border-radius:8px;font-size:.75rem;font-weight:700}
table{width:100%;border-collapse:collapse}
th{font-size:.72rem;color:var(--muted);font-weight:600;padding:10px 14px;text-align:right;border-bottom:1px solid var(--border)}
td{padding:11px 14px;font-size:.82rem;border-bottom:1px solid rgba(255,255,255,.04)}
tr:hover td{background:rgba(255,255,255,.02)}
.badge-pen{background:rgba(251,191,36,.15);color:#FBB724;padding:3px 10px;border-radius:8px;font-size:.68rem;font-weight:700}
.badge-ok{background:rgba(16,185,129,.15);color:#10B981;padding:3px 10px;border-radius:8px;font-size:.68rem;font-weight:700}
.badge-fail{background:rgba(239,68,68,.15);color:#EF4444;padding:3px 10px;border-radius:8px;font-size:.68rem;font-weight:700}
.actions{display:flex;gap:6px;flex-wrap:wrap}
.empty-state{padding:40px;text-align:center;color:var(--muted)}
.empty-state i{font-size:2.5rem;display:block;margin-bottom:12px;opacity:.3}
@media(max-width:768px){.grid2{grid-template-columns:1fr}.top-nav{display:none}}
</style>
</head>
<body>

<nav class="top-bar">
  <div class="top-brand"><i class="fas fa-coins"></i> DI PARMA — Admin</div>
  <div class="top-nav">
    <a href="users.php"><i class="fas fa-users"></i> المستخدمون</a>
    <a href="offline_approvals.php" class="active"><i class="fas fa-file-invoice"></i> Offline</a>
    <a href="wallets.php"><i class="fas fa-wallet"></i> المحافظ</a>
    <a href="../dashboard.php"><i class="fas fa-th-large"></i> لوحة التحكم</a>
  </div>
</nav>

<div class="wrap">
  <div class="pg-title">
    <i class="fas fa-file-invoice-dollar"></i>
    إدارة المدفوعات اليدوية — Offline
  </div>

  <?php if ($msg): ?>
  <div class="msg <?=$msgType?>"><?=$msg?></div>
  <?php endif; ?>

  <div class="grid2">
    <!-- نموذج التنفيذ المباشر -->
    <div class="card">
      <div class="card-title">
        <i class="fas fa-bolt"></i>
        تنفيذ مباشر — العميل حاضر
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="direct_execute">
        <input type="hidden" name="csrf_token" value="<?=$csrfToken?>">
        
        <div class="fld">
          <label>المستخدم <span class="required">*</span></label>
          <select name="user_id" required>
            <option value="">اختر المستخدم</option>
            <?php foreach($users as $u): ?>
            <option value="<?=$u['id']?>"><?=htmlspecialchars($u['username'])?> — <?=htmlspecialchars($u['email'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div class="fld">
          <label>نوع المعاملة <span class="required">*</span></label>
          <select name="transaction_type" required>
            <option value="">اختر نوع المعاملة</option>
            <?php foreach(TRANSACTION_TYPES as $code => $type): ?>
            <option value="<?=$code?>">
              [<?=htmlspecialchars($code)?>] <?=htmlspecialchars($type['ar'])?> — <?=htmlspecialchars($type['description_ar'])?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div class="fld-row">
          <div class="fld">
            <label>المبلغ <span class="required">*</span></label>
            <input type="number" name="amount" min="0.01" step="0.01" placeholder="0.00" required>
          </div>
          <div class="fld">
            <label>العملة</label>
            <select name="currency">
              <option value="USD">USD</option>
              <option value="AED">AED</option>
              <option value="EUR">EUR</option>
              <option value="SAR">SAR</option>
            </select>
          </div>
        </div>
        
        <div class="fld-row">
          <div class="fld">
            <label>العملة الرقمية</label>
            <select name="coin">
              <option value="USDT">USDT</option>
              <option value="BTC">BTC</option>
              <option value="ETH">ETH</option>
              <option value="BNB">BNB</option>
            </select>
          </div>
          <div class="fld">
            <label>الشبكة</label>
            <select name="network">
              <option value="TRC20">TRC20</option>
              <option value="ERC20">ERC20</option>
              <option value="BEP20">BEP20</option>
              <option value="SOL">SOL</option>
            </select>
          </div>
        </div>
        
        <div class="fld-row">
          <div class="fld">
            <label>Approval Code <span class="required">*</span></label>
            <input type="text" name="approval_code" maxlength="6" 
              placeholder="4-6 أرقام" pattern="\d{4,6}" required
              style="letter-spacing:4px;font-size:1.1rem;text-align:center">
          </div>
          <div class="fld">
            <label>آخر 4 أرقام البطاقة</label>
            <input type="text" name="card_last4" maxlength="4" placeholder="XXXX"
              style="letter-spacing:4px;text-align:center" pattern="\d{4}">
          </div>
        </div>
        
        <div class="fld">
          <label>ملاحظة / اسم العميل</label>
          <input type="text" name="note" placeholder="اسم العميل أو ملاحظة">
        </div>
        
        <div style="background:rgba(255,215,0,.06);border:1px solid rgba(255,215,0,.15);border-radius:10px;padding:12px;margin-bottom:14px;font-size:.78rem;color:#ccc">
          <i class="fas fa-info-circle" style="color:var(--gold)"></i>
          75% → محفظة الشركة | 25% → محفظة العميل (مقفلة 24 ساعة)
        </div>
        
        <button type="submit" class="btn btn-gold btn-full">
          <i class="fas fa-bolt"></i> تنفيذ فوري
        </button>
      </form>
    </div>

    <!-- الإحصائيات والعمليات المعلقة تم حذفها -->
</div>

<script>
// ============================================================
// CSRF Token
// ============================================================
const CSRF = '<?=$csrfToken?>';
</script>
</body>
</html>