<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';

requireAdmin();

$db = db();

try {
    $db->execute("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "approval_requests` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT UNSIGNED NOT NULL,
        `type` VARCHAR(50) NOT NULL DEFAULT 'payment',
        `reference` VARCHAR(100) NOT NULL,
        `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `currency` VARCHAR(10) NOT NULL DEFAULT 'AED',
        `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
        `reason` TEXT DEFAULT NULL,
        `created_at` DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

$message = '';
$messageType = 'info';

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: approvals.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_request'])) {
    $request = $db->find('approval_requests', ['id' => $id]);
    if ($request && $request['status'] === 'pending') {
        $db->update('approval_requests', ['status' => 'approved'], ['id' => $id]);
        $db->update('invoices', ['status' => 'paid'], ['reference' => $request['reference']]);
        $db->update('transactions', ['status' => 'completed'], ['reference' => $request['reference']]);

        $existingWallet = $db->find('wallets', ['user_id' => $request['user_id'], 'currency' => $request['currency']]);
        if ($existingWallet) {
            $db->execute("UPDATE `" . DB_PREFIX . "wallets` SET `balance` = `balance` + ? WHERE `user_id` = ? AND `currency` = ?", [(float)$request['amount'], $request['user_id'], $request['currency']]);
        } else {
            $db->insert('wallets', [
                'user_id' => $request['user_id'],
                'currency' => $request['currency'],
                'balance' => (float)$request['amount'],
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }

        $db->insert('ledger', [
            'user_id' => $request['user_id'],
            'type' => 'credit',
            'amount' => (float)$request['amount'],
            'currency' => $request['currency'],
            'reference' => $request['reference'],
            'description' => 'Approved integrated payment',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        $message = '✅ تم قبول الطلب وتم إضافة الرصيد إلى المحفظة';
        $messageType = 'success';
    } else {
        $message = 'ℹ️ هذا الطلب لم يعد قابلًا للتعديل';
        $messageType = 'info';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_request'])) {
    $reason = trim($_POST['reason'] ?? '');
    $request = $db->find('approval_requests', ['id' => $id]);
    if ($request && $request['status'] === 'pending') {
        $db->update('approval_requests', ['status' => 'rejected', 'reason' => $reason], ['id' => $id]);
        $db->update('invoices', ['status' => 'cancelled'], ['reference' => $request['reference']]);
        $db->update('transactions', ['status' => 'rejected'], ['reference' => $request['reference']]);
        $message = '❌ تم رفض الطلب';
        $messageType = 'error';
    } else {
        $message = 'ℹ️ هذا الطلب لم يعد قابلًا للتعديل';
        $messageType = 'info';
    }
}

$requestRow = $db->query(
    'SELECT ar.*, u.username, u.email, u.first_name, u.last_name ' .
    'FROM ' . DB_PREFIX . 'approval_requests ar ' .
    'LEFT JOIN ' . DB_PREFIX . 'users u ON u.id = ar.user_id ' .
    'WHERE ar.id = ?',
    [$id]
);
$requestRow = $requestRow[0] ?? null;

$transactionRow = null;
$invoiceRow = null;
$ledgerRows = [];
if ($requestRow) {
    $transactionRow = $db->query(
        'SELECT * FROM ' . DB_PREFIX . 'transactions WHERE reference = ? LIMIT 1',
        [$requestRow['reference']]
    );
    $transactionRow = $transactionRow[0] ?? null;

    $invoiceRow = $db->query(
        'SELECT * FROM ' . DB_PREFIX . 'invoices WHERE reference = ? LIMIT 1',
        [$requestRow['reference']]
    );
    $invoiceRow = $invoiceRow[0] ?? null;

    $ledgerRows = $db->query(
        'SELECT * FROM ' . DB_PREFIX . 'ledger WHERE reference = ? ORDER BY created_at DESC, id DESC',
        [$requestRow['reference']]
    );
}

if (!$requestRow) {
    header('Location: approvals.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang ?? 'en') ?>" dir="<?= htmlspecialchars($pageDir ?? 'ltr') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DI PARMA | تفاصيل الطلب</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap" rel="stylesheet">
<style>
body{font-family:'Cairo',sans-serif;background:#0b0f17;color:#f7d76b;margin:0;padding:20px;}
.container{max-width:900px;margin:0 auto;}
.card{background:rgba(255,255,255,0.05);border:1px solid rgba(255,215,0,0.2);border-radius:16px;padding:20px;margin-bottom:20px;}
.nav{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;}
.nav a{color:#fff;text-decoration:none;padding:8px 12px;border:1px solid rgba(255,215,0,0.2);border-radius:999px;}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;}
.item{background:rgba(255,255,255,0.06);padding:12px;border-radius:12px;}
.label{font-size:0.85rem;color:#cdbb6b;margin-bottom:6px;}
.value{font-size:1rem;font-weight:700;}
.badge{display:inline-block;padding:4px 8px;border-radius:999px;background:rgba(255,215,0,0.25);}
.btn{padding:8px 12px;border:none;border-radius:8px;cursor:pointer;margin-left:6px;}
.btn-success{background:#2bb673;color:#fff;}
.btn-danger{background:#d9534f;color:#fff;}
textarea{width:100%;min-height:70px;border-radius:8px;padding:8px;margin-top:8px;}
.alert{padding:10px;border-radius:8px;background:rgba(255,255,255,0.08);margin-bottom:12px;}
</style>
</head>
<body>
<div class="container">
  <div class="nav">
    <a href="index.php">&#8962; الرئيسية</a>
    <a href="dashboard.php">لوحة التحكم</a>
    <a href="approvals.php">الموافقات</a>
    <a href="wallets.php">المحفظة</a>
    <a href="invoices.php">الفواتير</a>
  </div>

  <div class="card">
    <h2>تفاصيل الطلب</h2>
    <?php if ($message): ?><div class="alert" style="color:<?= $messageType === 'success' ? '#7cf2a0' : ($messageType === 'error' ? '#ff8c8c' : '#fff') ?>;"><?= $message ?></div><?php endif; ?>

    <div class="grid">
      <div class="item">
        <div class="label">المرجع</div>
        <div class="value"><?= htmlspecialchars($requestRow['reference']) ?></div>
      </div>
      <div class="item">
        <div class="label">النوع</div>
        <div class="value"><?= htmlspecialchars($requestRow['type']) ?></div>
      </div>
      <div class="item">
        <div class="label">المبلغ</div>
        <div class="value"><?= number_format((float)$requestRow['amount'], 2) ?> <?= htmlspecialchars($requestRow['currency']) ?></div>
      </div>
      <div class="item">
        <div class="label">الحالة</div>
        <div class="value"><span class="badge"><?= htmlspecialchars($requestRow['status']) ?></span></div>
      </div>
    </div>

    <div class="grid" style="margin-top:12px;">
      <div class="item">
        <div class="label">اسم المستخدم</div>
        <div class="value"><?= htmlspecialchars($requestRow['username'] ?? 'غير معروف') ?></div>
      </div>
      <div class="item">
        <div class="label">البريد</div>
        <div class="value"><?= htmlspecialchars($requestRow['email'] ?? '-') ?></div>
      </div>
      <div class="item">
        <div class="label">الاسم الكامل</div>
        <div class="value"><?= htmlspecialchars(trim(($requestRow['first_name'] ?? '') . ' ' . ($requestRow['last_name'] ?? ''))) ?></div>
      </div>
      <div class="item">
        <div class="label">تاريخ الإنشاء</div>
        <div class="value"><?= htmlspecialchars($requestRow['created_at']) ?></div>
      </div>
    </div>

    <div class="card" style="margin-top:16px;">
      <h3>البيانات المرتبطة بالمعاملة</h3>
      <div class="grid">
        <div class="item">
          <div class="label">الحالة العامة للمعاملة</div>
          <div class="value"><?= htmlspecialchars($transactionRow['status'] ?? '-') ?></div>
        </div>
        <div class="item">
          <div class="label">البوابة</div>
          <div class="value"><?= htmlspecialchars($transactionRow['gateway'] ?? '-') ?></div>
        </div>
        <div class="item">
          <div class="label">طريقة الدفع</div>
          <div class="value"><?= htmlspecialchars($transactionRow['payment_method'] ?? '-') ?></div>
        </div>
        <div class="item">
          <div class="label">رقم الفاتورة</div>
          <div class="value"><?= htmlspecialchars($invoiceRow['invoice_number'] ?? '-') ?></div>
        </div>
      </div>
      <div class="grid" style="margin-top:12px;">
        <div class="item">
          <div class="label">حالة الفاتورة</div>
          <div class="value"><?= htmlspecialchars($invoiceRow['status'] ?? '-') ?></div>
        </div>
        <div class="item">
          <div class="label">الوصف</div>
          <div class="value"><?= htmlspecialchars($transactionRow['description'] ?? $invoiceRow['description'] ?? '-') ?></div>
        </div>
        <div class="item">
          <div class="label">البريد العميل</div>
          <div class="value"><?= htmlspecialchars($transactionRow['customer_email'] ?? '-') ?></div>
        </div>
        <div class="item">
          <div class="label">رقم الهاتف</div>
          <div class="value"><?= htmlspecialchars($transactionRow['customer_phone'] ?? '-') ?></div>
        </div>
      </div>
      <?php if (!empty($transactionRow['contract_service_name']) || !empty($transactionRow['contract_service_description']) || !empty($transactionRow['contract_delivery_method']) || !empty($transactionRow['contract_delivery_notes'])): ?>
        <div class="card" style="margin-top:16px;padding:14px;background:rgba(255,215,0,0.06);border:1px solid rgba(255,215,0,0.2);">
          <h3>العقد الإلكتروني</h3>
          <?php if (!empty($transactionRow['contract_service_name'])): ?><div class="item" style="margin-bottom:8px;"><div class="label">اسم الخدمة</div><div class="value"><?= htmlspecialchars($transactionRow['contract_service_name']) ?></div></div><?php endif; ?>
          <?php if (!empty($transactionRow['contract_service_description'])): ?><div class="item" style="margin-bottom:8px;"><div class="label">وصف الخدمة</div><div class="value"><?= htmlspecialchars($transactionRow['contract_service_description']) ?></div></div><?php endif; ?>
          <?php if (!empty($transactionRow['contract_delivery_method'])): ?><div class="item" style="margin-bottom:8px;"><div class="label">طريقة الاستلام</div><div class="value"><?= htmlspecialchars($transactionRow['contract_delivery_method']) ?></div></div><?php endif; ?>
          <?php if (!empty($transactionRow['contract_delivery_notes'])): ?><div class="item"><div class="label">ملاحظات الاستلام</div><div class="value"><?= htmlspecialchars($transactionRow['contract_delivery_notes']) ?></div></div><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="card" style="margin-top:16px;">
      <h3>سجل الحركة (Ledger)</h3>
      <?php if (!empty($ledgerRows)): ?>
        <table style="width:100%;border-collapse:collapse;margin-top:8px;">
          <thead>
            <tr style="border-bottom:1px solid rgba(255,255,255,0.1);">
              <th style="text-align:right;padding:8px;">النوع</th>
              <th style="text-align:right;padding:8px;">المبلغ</th>
              <th style="text-align:right;padding:8px;">العملة</th>
              <th style="text-align:right;padding:8px;">الوصف</th>
              <th style="text-align:right;padding:8px;">التاريخ</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($ledgerRows as $entry): ?>
              <tr style="border-bottom:1px solid rgba(255,255,255,0.06);">
                <td style="padding:8px;"><?= htmlspecialchars($entry['type'] ?? '-') ?></td>
                <td style="padding:8px;"><?= number_format((float)($entry['amount'] ?? 0), 2) ?></td>
                <td style="padding:8px;"><?= htmlspecialchars($entry['currency'] ?? '-') ?></td>
                <td style="padding:8px;"><?= htmlspecialchars($entry['description'] ?? '-') ?></td>
                <td style="padding:8px;"><?= htmlspecialchars($entry['created_at'] ?? '-') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <div class="alert">لا توجد سجلات حركة لهذا الطلب بعد.</div>
      <?php endif; ?>
    </div>

    <?php if ($requestRow['status'] === 'pending'): ?>
      <div class="card" style="margin-top:16px;">
        <h3>الإجراءات المباشرة</h3>
        <form method="POST" style="margin-bottom:10px;">
          <input type="hidden" name="id" value="<?= (int)$requestRow['id'] ?>">
          <button class="btn btn-success" name="approve_request">قبول الطلب</button>
          <?php if (!empty($requestRow['reference'])): ?>
            <a href="receipt.php?ref=<?= urlencode($requestRow['reference']) ?>" class="btn btn-success" style="display:inline-block;text-decoration:none;">فتح صفحة المعاملة</a>
            <a href="receipt.php?ref=<?= urlencode($requestRow['reference']) ?>" class="btn btn-danger" style="display:inline-block;text-decoration:none;">فتح الإيصال</a>
          <?php endif; ?>
          <a href="wallets.php" class="btn btn-success" style="display:inline-block;text-decoration:none;">فتح المحفظة</a>
          <a href="invoices.php" class="btn btn-danger" style="display:inline-block;text-decoration:none;">فتح الفواتير</a>
          <a href="user_profile.php?id=<?= (int)$requestRow['user_id'] ?>" class="btn btn-success" style="display:inline-block;text-decoration:none;">فتح بيانات المستخدم</a>
          <a href="admin/users.php" class="btn btn-danger" style="display:inline-block;text-decoration:none;">فتح المستخدم في الإدارة</a>
        </form>
        <form method="POST">
          <input type="hidden" name="id" value="<?= (int)$requestRow['id'] ?>">
          <label>سبب الرفض</label>
          <textarea name="reason" placeholder="أدخل سبب الرفض..."></textarea>
          <button class="btn btn-danger" name="reject_request">رفض الطلب</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
