<?php
/**
 * ============================================================
 * DI PARMA | Master Wallet Setup
 * ============================================================
 * إعداد محفظة رئيسية Tron/Crypto
 * مطابقة لـ Payram Deposit Wallet
 */

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/lib/PayRamAdapter.php';

$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'ar' ? 'ar' : 'en';
$ar = ($lang === 'ar');
$dir = $ar ? 'rtl' : 'ltr';
$csrf = generateCsrfToken();

$db = db();
$payram = new PayRamAdapter();

// ──────────────────────────────────────────────────────
// [1] جلب بيانات المحفظة الرئيسية
// ──────────────────────────────────────────────────────
$masterWallet = null;
$walletBalance = null;
$balanceSource = null;
$walletTransactions = [];
$hotWallet = HOT_WALLET_TRC20_ADDRESS;
$coldWallet = COLD_WALLET_TRC20_ADDRESS;

try {
    $walletRow = $db->query(
        "SELECT * FROM " . DB_PREFIX . "wallets WHERE user_id = ? AND currency = 'USDT' LIMIT 1",
        [$_SESSION['user_id']]
    );
    if (!empty($walletRow[0])) {
        $masterWallet = $walletRow[0];
        // The local wallet balance is not an on-chain balance and must not be displayed as one.
    }

    // آخر 10 معاملات
    $walletTransactions = $db->query(
        "SELECT * FROM " . DB_PREFIX . "transactions 
         WHERE user_id = ? AND currency IN ('USDT', 'USD') 
         ORDER BY created_at DESC LIMIT 10",
        [$_SESSION['user_id']]
    );
} catch (Exception $e) {
    error_log('[master_wallet_setup] DB: ' . $e->getMessage());
}

// ──────────────────────────────────────────────────────
// [2] جلب أسعار العملات
// ──────────────────────────────────────────────────────
$tickers = [];
$usdtPrice = null;
$trxPrice = null;

try {
    $tickers = $payram->getTickers();
    foreach ($tickers as $t) {
        if ($t['blockchainCode'] === 'TRX' && $t['currencyCode'] === 'USDT') {
            $usdtPrice = floatval($t['price'] ?? 1);
        }
        if ($t['blockchainCode'] === 'TRX' && $t['currencyCode'] === 'TRX') {
            $trxPrice = floatval($t['price'] ?? 0.1);
        }
    }
} catch (Exception $e) {
    error_log('[master_wallet_setup] Tickers: ' . $e->getMessage());
}

// حساب القيمة بالعملات الأخرى
$balanceUSD = null;
$balanceTRX = null;

// ──────────────────────────────────────────────────────
// [3] معالجة الطلبات (AJAX)
// ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $action = trim($_POST['action'] ?? '');

    // التحقق من CSRF
    if (!empty($_POST['csrf_token']) && !verifyCsrfToken($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'CSRF validation failed']);
        exit;
    }

    switch ($action) {
        // ──── إنشاء محفظة جديدة ────
        case 'create_wallet':
          echo json_encode(['success' => false, 'message' => 'إنشاء عنوان Tron الحقيقي يحتاج خدمة حفظ مفاتيح موثوقة، لذلك لم يتم إنشاء سجل وهمي']);
            exit;

        // ──── تحديث رصيد المحفظة ────
        case 'refresh_balance':
          echo json_encode(['success' => false, 'message' => 'الرصيد المباشر من TronGrid غير مفعّل بعد؛ لم يتم عرض رصيد محلي غير موثوق']);
            exit;

        // ──── إرسال أموال ────
        case 'send_funds':
            try {
                $amount = floatval($_POST['amount'] ?? 0);
                $destination = trim($_POST['destination'] ?? '');
                $description = trim($_POST['description'] ?? '');

                if ($amount <= 0) {
                    echo json_encode(['success' => false, 'message' => 'المبلغ يجب أن يكون أكبر من صفر']);
                    exit;
                }

                if (!preg_match('/^T[A-Za-z0-9]{33}$/', $destination)) {
                  echo json_encode(['success' => false, 'message' => 'عنوان Tron غير صالح']);
                    exit;
                }

                $ref = 'TXN-' . strtoupper(bin2hex(random_bytes(8)));
                $payout = $payram->createPayout([
                  'email' => $_SESSION['user_email'] ?? $_SESSION['email'] ?? 'client@diparmas.com',
                  'blockchain_code' => 'TRX',
                  'currency_code' => 'USDT',
                  'amount' => $amount,
                  'to_address' => $destination,
                  'customer_id' => 'dp_' . $_SESSION['user_id'],
                  'idempotency_key' => $ref,
                ]);

                if (!$payout['success']) {
                  echo json_encode(['success' => false, 'message' => $payout['raw']['message'] ?? 'Payram لم يقبل طلب التحويل']);
                  exit;
                }

                $db->insert(DB_PREFIX . 'transactions', [
                    'reference' => $ref,
                  'gateway' => 'payram',
                    'user_id' => $_SESSION['user_id'],
                    'amount' => $amount,
                    'currency' => 'USDT',
                    'status' => 'pending',
                    'payment_method' => 'wallet_transfer',
                    'description' => 'تحويل من المحفظة الرئيسية: ' . $description,
                    'gateway_response' => json_encode([
                        'from' => $hotWallet,
                        'to' => $destination,
                        'chain' => 'TRX',
                        'token' => 'USDT',
                      'payout_id' => $payout['payout_id'],
                      'tx_hash' => $payout['tx_hash'],
                      'status' => $payout['status'],
                    ]),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);

                echo json_encode([
                    'success' => true,
                    'message' => 'تم قبول طلب التحويل من Payram، وهو بانتظار تأكيد الشبكة',
                    'reference' => $ref,
                    'payout_id' => $payout['payout_id'],
                    'tx_hash' => $payout['tx_hash'],
                    'status' => $payout['status'],
                ]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            exit;

        // ──── استقبال أموال ────
        case 'request_deposit':
            try {
                $amount = floatval($_POST['amount'] ?? 0);
                $description = trim($_POST['description'] ?? '');

                if ($amount <= 0) {
                    echo json_encode(['success' => false, 'message' => 'المبلغ يجب أن يكون أكبر من صفر']);
                    exit;
                }

                // إنشاء طلب دفع عبر Payram
                $result = $payram->createPayment([
                    'amount' => $amount,
                    'email' => $_SESSION['user_email'] ?? 'user@diparmas.com',
                    'customer_id' => 'dp_' . $_SESSION['user_id'],
                ]);

                if (!$result['success']) {
                    echo json_encode(['success' => false, 'message' => 'فشل في إنشاء طلب الدفع']);
                    exit;
                }

                $ref = $result['reference_id'];
                $payramUrl = $result['url'];

                // تسجيل في DB
                $db->insert(DB_PREFIX . 'transactions', [
                    'reference' => $ref,
                    'gateway' => 'payram',
                    'user_id' => $_SESSION['user_id'],
                    'amount' => $amount,
                    'currency' => 'USD',
                    'status' => 'pending',
                    'payment_method' => 'crypto',
                    'description' => 'إيداع: ' . $description,
                    'gateway_response' => json_encode([
                        'payram_ref' => $ref,
                        'chain' => 'TRX',
                        'token' => 'USDT',
                        'invoice_id' => $result['invoice_id'] ?? null,
                    ]),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);

                echo json_encode([
                    'success' => true,
                    'message' => 'تم إنشاء طلب الإيداع',
                    'reference' => $ref,
                    'payram_url' => $payramUrl,
                    'amount' => $amount,
                ]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            exit;
    }
}

?><!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$dir?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | Master Wallet Setup</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }

:root {
  --primary: #FFD700;
  --dark: #0a0f1e;
  --card: #111827;
  --border: #1f2937;
  --text: #e5e7eb;
  --success: #10b981;
  --danger: #ef4444;
  --warning: #f59e0b;
}

body {
  font-family: 'Cairo', 'Inter', sans-serif;
  background: linear-gradient(135deg, var(--dark), #1a1f35);
  color: var(--text);
  min-height: 100vh;
  padding: 20px;
}

.container {
  max-width: 1200px;
  margin: 0 auto;
}

header {
  background: rgba(255, 215, 0, 0.05);
  border: 1px solid rgba(255, 215, 0, 0.2);
  border-radius: 12px;
  padding: 30px;
  margin-bottom: 30px;
  text-align: center;
}

header h1 {
  font-size: 2rem;
  margin-bottom: 10px;
  background: linear-gradient(135deg, var(--primary), #ffd700);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
}

header p {
  color: #9ca3af;
  font-size: 0.95rem;
}

.grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
  gap: 20px;
  margin-bottom: 30px;
}

.card {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 20px;
  transition: all 0.3s ease;
}

.card:hover {
  border-color: var(--primary);
  box-shadow: 0 0 20px rgba(255, 215, 0, 0.1);
}

.card-title {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 15px;
  font-size: 1.1rem;
  font-weight: 600;
  color: var(--primary);
}

.stat-value {
  font-size: 2rem;
  font-weight: 700;
  margin: 15px 0;
  color: var(--text);
}

.stat-label {
  font-size: 0.85rem;
  color: #6b7280;
  text-transform: uppercase;
  letter-spacing: 1px;
}

.wallet-info {
  background: rgba(16, 185, 129, 0.05);
  border-left: 3px solid var(--success);
  padding: 15px;
  border-radius: 8px;
  margin-top: 15px;
  font-size: 0.9rem;
}

.wallet-address {
  font-family: 'Courier New', monospace;
  background: rgba(0, 0, 0, 0.3);
  padding: 10px;
  border-radius: 6px;
  word-break: break-all;
  margin-top: 10px;
  font-size: 0.85rem;
  color: var(--primary);
}

.form-group {
  margin-bottom: 15px;
}

label {
  display: block;
  margin-bottom: 8px;
  font-size: 0.95rem;
  font-weight: 500;
  color: var(--text);
}

input, textarea, select {
  width: 100%;
  padding: 10px 12px;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: rgba(0, 0, 0, 0.3);
  color: var(--text);
  font-family: inherit;
  font-size: 0.95rem;
  transition: border 0.3s ease;
}

input:focus, textarea:focus, select:focus {
  outline: none;
  border-color: var(--primary);
  box-shadow: 0 0 10px rgba(255, 215, 0, 0.2);
}

.btn {
  padding: 10px 20px;
  border: none;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.3s ease;
  font-size: 0.95rem;
  display: inline-flex;
  align-items: center;
  gap: 8px;
}

.btn-primary {
  background: linear-gradient(135deg, var(--primary), #ffd700);
  color: #000;
}

.btn-primary:hover {
  transform: translateY(-2px);
  box-shadow: 0 5px 20px rgba(255, 215, 0, 0.3);
}

.btn-success {
  background: var(--success);
  color: #fff;
}

.btn-danger {
  background: var(--danger);
  color: #fff;
}

.btn-warning {
  background: var(--warning);
  color: #000;
}

.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.transaction-list {
  margin-top: 20px;
}

.transaction-item {
  background: rgba(0, 0, 0, 0.2);
  border-left: 3px solid var(--primary);
  padding: 12px;
  border-radius: 6px;
  margin-bottom: 10px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 0.9rem;
}

.transaction-ref {
  font-family: 'Courier New', monospace;
  color: var(--primary);
  font-weight: 600;
}

.transaction-amount {
  font-weight: 600;
  color: var(--success);
}

.transaction-status {
  font-size: 0.8rem;
  text-transform: uppercase;
  padding: 3px 8px;
  border-radius: 4px;
  background: rgba(16, 185, 129, 0.2);
  color: var(--success);
}

.alert {
  padding: 15px;
  border-radius: 8px;
  margin-bottom: 15px;
  display: none;
}

.alert.show { display: block; }

.alert-success {
  background: rgba(16, 185, 129, 0.1);
  border-left: 3px solid var(--success);
  color: var(--success);
}

.alert-danger {
  background: rgba(239, 68, 68, 0.1);
  border-left: 3px solid var(--danger);
  color: var(--danger);
}

.alert-info {
  background: rgba(59, 130, 246, 0.1);
  border-left: 3px solid #3b82f6;
  color: #93c5fd;
}

.loading {
  display: inline-block;
  width: 16px;
  height: 16px;
  border: 2px solid rgba(255, 255, 255, 0.3);
  border-top-color: var(--primary);
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

.tabs {
  display: flex;
  gap: 10px;
  margin-bottom: 20px;
  border-bottom: 1px solid var(--border);
  padding-bottom: 0;
}

.tab-btn {
  padding: 12px 20px;
  background: none;
  border: none;
  border-bottom: 3px solid transparent;
  color: var(--text);
  cursor: pointer;
  font-weight: 500;
  transition: all 0.3s ease;
}

.tab-btn.active {
  border-bottom-color: var(--primary);
  color: var(--primary);
}

.tab-content {
  display: none;
}

.tab-content.active {
  display: block;
}

@media (max-width: 768px) {
  header h1 { font-size: 1.5rem; }
  .grid { grid-template-columns: 1fr; }
  .tabs { flex-wrap: wrap; }
}
</style>
</head>
<body>
<div class="container">

<!-- HEADER -->
<header>
  <h1>💳 Master Wallet Setup</h1>
  <p><?=$ar ? 'إعداد محفظتك الرئيسية للعملات الرقمية' : 'Set up your master crypto wallet'?></p>
</header>

<!-- ALERTS -->
<div id="alertBox"></div>

<!-- WALLET OVERVIEW -->
<div class="grid">
  <div class="card">
    <div class="card-title">💰 USDT Balance</div>
    <div class="stat-value" id="balanceUSDT"><?=$walletBalance === null ? 'N/A' : number_format($walletBalance, 2)?></div>
    <div class="stat-label">On-chain USDT balance</div>
    <div class="wallet-info">
      <strong>📍 Hot Wallet:</strong>
      <div class="wallet-address"><?=$hotWallet?></div>
    </div>
  </div>

  <div class="card">
    <div class="card-title">🪙 TRX Value</div>
    <div class="stat-value" id="balanceTRX"><?=$balanceTRX === null ? 'N/A' : number_format($balanceTRX, 2)?></div>
    <div class="stat-label">Live conversion unavailable</div>
    <div class="wallet-info">
      <strong>🏦 Cold Wallet (Ledger):</strong>
      <div class="wallet-address"><?=$coldWallet?></div>
    </div>
  </div>

  <div class="card">
    <div class="card-title">📊 Market Price</div>
    <div class="stat-value" id="usdtPrice"><?=$usdtPrice ? '$' . number_format($usdtPrice, 4) : 'N/A'?></div>
    <div class="stat-label">USDT / USD</div>
    <button class="btn btn-warning" style="width: 100%; margin-top: 15px;" onclick="refreshBalance()">
      <span class="loading"></span> <?=$ar ? 'تحديث الرصيد' : 'Refresh Balance'?>
    </button>
  </div>
</div>

<!-- TABS -->
<div class="tabs">
  <button class="tab-btn active" onclick="switchTab('operations')">
    <?=$ar ? '📤 العمليات' : '📤 Operations'?>
  </button>
  <button class="tab-btn" onclick="switchTab('history')">
    <?=$ar ? '📋 السجل' : '📋 History'?>
  </button>
  <button class="tab-btn" onclick="switchTab('settings')">
    <?=$ar ? '⚙️ الإعدادات' : '⚙️ Settings'?>
  </button>
</div>

<!-- TAB 1: OPERATIONS -->
<div id="operations" class="tab-content active">
  <div class="grid">
    <!-- Send Funds -->
    <div class="card">
      <div class="card-title">📤 Send Funds</div>
      <form onsubmit="sendFunds(event)">
        <div class="form-group">
          <label><?=$ar ? 'المبلغ (USDT)' : 'Amount (USDT)'?></label>
          <input type="number" id="sendAmount" placeholder="100" min="0.01" step="0.01" required>
        </div>
        <div class="form-group">
          <label><?=$ar ? 'عنوان الاستقبال' : 'Recipient Address'?></label>
          <input type="text" id="sendDestination" placeholder="T..." required>
        </div>
        <div class="form-group">
          <label><?=$ar ? 'الوصف' : 'Description'?></label>
          <textarea id="sendDescription" placeholder="اختياري" rows="2"></textarea>
        </div>
        <button type="submit" class="btn btn-primary" style="width: 100%;">
          📤 <?=$ar ? 'إرسال الأموال' : 'Send'?>
        </button>
      </form>
    </div>

    <!-- Request Deposit -->
    <div class="card">
      <div class="card-title">📥 Request Deposit</div>
      <form onsubmit="requestDeposit(event)">
        <div class="form-group">
          <label><?=$ar ? 'المبلغ (USD)' : 'Amount (USD)'?></label>
          <input type="number" id="depositAmount" placeholder="100" min="1" step="0.01" required>
        </div>
        <div class="form-group">
          <label><?=$ar ? 'الوصف' : 'Description'?></label>
          <textarea id="depositDescription" placeholder="اختياري" rows="2"></textarea>
        </div>
        <button type="submit" class="btn btn-success" style="width: 100%;">
          📥 <?=$ar ? 'طلب إيداع' : 'Request Deposit'?>
        </button>
      </form>
    </div>

    <!-- Create Wallet -->
    <div class="card">
      <div class="card-title">✨ Create Wallet</div>
      <form onsubmit="createWallet(event)">
        <div class="form-group">
          <label><?=$ar ? 'العملة' : 'Currency'?></label>
          <select id="newCurrency" required>
            <option value="USDT">USDT (Tether)</option>
            <option value="USD">USD (US Dollar)</option>
            <option value="EUR">EUR (Euro)</option>
            <option value="AED">AED (UAE Dirham)</option>
          </select>
        </div>
        <button type="submit" class="btn btn-warning" style="width: 100%; margin-top: 15px;">
          ✨ <?=$ar ? 'إنشاء محفظة' : 'Create'?>
        </button>
      </form>
    </div>
  </div>
</div>

<!-- TAB 2: HISTORY -->
<div id="history" class="tab-content">
  <div class="card">
    <div class="card-title">📋 Recent Transactions</div>
    <div class="transaction-list">
      <?php if (empty($walletTransactions)): ?>
        <div style="text-align: center; color: #6b7280; padding: 20px;">
          <?=$ar ? 'لا توجد معاملات بعد' : 'No transactions yet'?>
        </div>
      <?php else: ?>
        <?php foreach ($walletTransactions as $txn): ?>
          <div class="transaction-item">
            <div>
              <div class="transaction-ref"><?=$txn['reference']?></div>
              <div style="color: #9ca3af; font-size: 0.85rem; margin-top: 5px;">
                <?=date('Y-m-d H:i', strtotime($txn['created_at']))?>
              </div>
            </div>
            <div style="text-align: right;">
              <div class="transaction-amount">
                <?=($txn['status'] === 'completed' ? '+' : '')?>
                <?=number_format($txn['amount'], 2)?>
                <?=$txn['currency']?>
              </div>
              <div class="transaction-status"><?=$txn['status']?></div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- TAB 3: SETTINGS -->
<div id="settings" class="tab-content">
  <div class="card">
    <div class="card-title">⚙️ Wallet Settings</div>
    <div style="color: #9ca3af; padding: 20px; background: rgba(0, 0, 0, 0.2); border-radius: 8px;">
      <p>✅ Hot Wallet: <code style="color: var(--primary);"><?=$hotWallet?></code></p>
      <p>✅ Ledger Wallet: <code style="color: var(--success);"><?=$ledgerWallet?></code></p>
      <p>✅ Connected: <span style="color: var(--success);">YES</span></p>
      <p>✅ Status: <span style="color: var(--success);">ACTIVE</span></p>
    </div>
  </div>
</div>

</div>

<script>
const csrf = '<?=$csrf?>';
const ar = <?=$ar ? 'true' : 'false'?>;

function showAlert(message, type = 'info') {
  const box = document.getElementById('alertBox');
  const alert = document.createElement('div');
  alert.className = `alert alert-${type} show`;
  alert.textContent = message;
  box.innerHTML = '';
  box.appendChild(alert);
  setTimeout(() => alert.remove(), 5000);
}

function switchTab(tabName) {
  document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
  
  document.getElementById(tabName).classList.add('active');
  event.target.classList.add('active');
}

function refreshBalance() {
  fetch('<?=$_SERVER['REQUEST_URI']?>', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=refresh_balance&csrf_token=' + csrf
  })
  .then(r => r.json())
  .then(d => {
    if (d.success) {
      document.getElementById('balanceUSDT').textContent = d.balance.toFixed(2);
      document.getElementById('balanceTRX').textContent = d.balance_trx.toFixed(2);
      showAlert(ar ? 'تم تحديث الرصيد' : 'Balance updated', 'success');
    } else {
      showAlert(d.message, 'danger');
    }
  })
  .catch(e => showAlert(e.message, 'danger'));
}

function sendFunds(e) {
  e.preventDefault();
  const data = new FormData();
  data.append('action', 'send_funds');
  data.append('csrf_token', csrf);
  data.append('amount', document.getElementById('sendAmount').value);
  data.append('destination', document.getElementById('sendDestination').value);
  data.append('description', document.getElementById('sendDescription').value);

  fetch('<?=$_SERVER['REQUEST_URI']?>', { method: 'POST', body: data })
    .then(r => r.json())
    .then(d => {
      showAlert(d.message, d.success ? 'success' : 'danger');
      if (d.success) e.target.reset();
    })
    .catch(e => showAlert(e.message, 'danger'));
}

function requestDeposit(e) {
  e.preventDefault();
  const data = new FormData();
  data.append('action', 'request_deposit');
  data.append('csrf_token', csrf);
  data.append('amount', document.getElementById('depositAmount').value);
  data.append('description', document.getElementById('depositDescription').value);

  fetch('<?=$_SERVER['REQUEST_URI']?>', { method: 'POST', body: data })
    .then(r => r.json())
    .then(d => {
      showAlert(d.message, d.success ? 'success' : 'danger');
      if (d.success) {
        window.open(d.payram_url, '_blank');
        e.target.reset();
      }
    })
    .catch(e => showAlert(e.message, 'danger'));
}

function createWallet(e) {
  e.preventDefault();
  const data = new FormData();
  data.append('action', 'create_wallet');
  data.append('csrf_token', csrf);
  data.append('currency', document.getElementById('newCurrency').value);

  fetch('<?=$_SERVER['REQUEST_URI']?>', { method: 'POST', body: data })
    .then(r => r.json())
    .then(d => {
      showAlert(d.message, d.success ? 'success' : 'danger');
      if (d.success) {
        setTimeout(() => location.reload(), 1500);
      }
    })
    .catch(e => showAlert(e.message, 'danger'));
}
</script>

</body>
</html>
