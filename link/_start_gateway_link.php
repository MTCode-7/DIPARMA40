<?php
/**
 * DI PARMA | LINK خاص ببوابة
 * إنشاء وإدارة روابط الدفع لهذه البوابة فقط.
 */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/gateways.php';
require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once dirname(__DIR__) . '/includes/activity_flow.php';
require_once dirname(__DIR__) . '/includes/gateway_channel_bar.php';

if (empty($gwCode)) {
    header('Location: ../checkout_router.php');
    exit;
}

$gwCode = strtolower(trim((string) $gwCode));
$isLedgerPage = ($gwCode === 'ledger');
$ledgerCheckout = $isLedgerPage || (isset($_GET['ledger_checkout']) && (string) $_GET['ledger_checkout'] === '1');

$GATEWAY_NAMES = [
    'payram' => 'PayRam', 'diparma' => 'DI PARMA', 'nuvei' => 'Nuvei',
    'stripe' => 'Stripe', 'square' => 'Square 1', 'square_online' => 'Square 2 · Online',
    'paypal' => 'PayPal', 'wise' => 'Wise', 'myfatoorah' => 'MyFatoorah',
    'binance' => 'Binance', 'gate_io' => 'Gate.io', 'mashreq' => 'Mashreq Bank',
    'hsbc_uae' => 'HSBC UAE', 'nbe_egypt' => 'NBE Egypt', 'jpmorgan' => 'JP Morgan Chase',
    'whop' => 'Whop', 'ledger' => 'Ledger CHECKOUT',
];
$gwName = $GATEWAY_NAMES[$gwCode] ?? strtoupper($gwCode);

$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'ar' ? 'ar' : 'en';
$ar = ($lang === 'ar');
$dir = $ar ? 'rtl' : 'ltr';
$csrf = generateCsrfToken();
$db = db();

$chargeGateway = $isLedgerPage
    ? strtolower(trim((string) ($_POST['charge_gateway'] ?? $_GET['charge_gateway'] ?? '')))
    : $gwCode;

$message = '';
$messageType = '';
$createdUrl = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_link'])) {
    if (!verifyCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        $message = $ar ? 'رمز الأمان غير صالح' : 'Invalid security token';
        $messageType = 'error';
    } else {
        $title = trim((string) ($_POST['title'] ?? ''));
        $amount = (float) ($_POST['amount'] ?? 0);
        $currency = strtoupper(trim((string) ($_POST['currency'] ?? 'USD'))) ?: 'USD';
        $storeGw = $isLedgerPage ? $chargeGateway : $gwCode;
        if ($title === '' || $amount <= 0 || $storeGw === '') {
            $message = $ar ? 'العنوان والمبلغ والبوابة مطلوبة' : 'Title, amount, and gateway are required';
            $messageType = 'error';
        } else {
            $linkId = strtoupper(substr($storeGw, 0, 3)) . date('Ymd') . bin2hex(random_bytes(4));
            $token = bin2hex(random_bytes(32));
            $slug = function_exists('generateSlug') ? generateSlug($title) : strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title));
            $protocol = $ledgerCheckout ? 'ledger.201.3' : trim((string) ($_POST['protocol'] ?? '201.3'));
            $id = $db->insert('payment_links', [
                'link_id' => $linkId,
                'token' => $token,
                'slug' => $slug,
                'title' => $title,
                'description' => trim((string) ($_POST['description'] ?? '')),
                'amount' => $amount,
                'currency' => $currency,
                'gateway' => $storeGw,
                'protocol' => $protocol,
                'payment_type' => 'one_time',
                'expiry_date' => date('Y-m-d H:i:s', strtotime('+7 days')),
                'max_uses' => 0,
                'uses_count' => 0,
                'status' => 'active',
                'user_id' => (int) ($_SESSION['user_id'] ?? 0),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            if ($id > 0) {
                $createdUrl = (defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '..') . '/pay.php?link=' . $linkId . '&token=' . $token;
                $message = $ar ? 'تم إنشاء الرابط' : 'Link created';
                $messageType = 'success';
            } else {
                $message = $ar ? 'فشل إنشاء الرابط' : 'Failed to create the link';
                $messageType = 'error';
            }
        }
    }
}

$links = [];
try {
    if ($isLedgerPage) {
        $links = $db->query(
            "SELECT * FROM " . DB_PREFIX . "payment_links WHERE user_id=? AND status!='deleted' AND protocol LIKE 'ledger.%' ORDER BY id DESC LIMIT 40",
            [(int) ($_SESSION['user_id'] ?? 0)]
        ) ?: [];
    } else {
        $links = $db->query(
            "SELECT * FROM " . DB_PREFIX . "payment_links WHERE user_id=? AND gateway=? AND status!='deleted' ORDER BY id DESC LIMIT 40",
            [(int) ($_SESSION['user_id'] ?? 0), $gwCode]
        ) ?: [];
    }
} catch (Throwable $e) {
    $links = [];
}

$attached = [];
if ($isLedgerPage) {
    foreach (activity_connected_gateways('link') as $code => $gw) {
        if ($code === 'ledger' || $code === 'diparma_gateway') {
            continue;
        }
        $attached[$code] = $gw['name'] ?? strtoupper($code);
    }
}

$extraQ = $ledgerCheckout && !$isLedgerPage ? ['ledger_checkout' => '1'] : [];
$openCheckout = '../' . activity_channel_route($isLedgerPage ? ($chargeGateway !== '' ? $chargeGateway : 'paypal') : $gwCode, 'checkout');
$openQs = ['channel' => 'link'];
if ($ledgerCheckout) {
    $openQs['ledger_checkout'] = '1';
}
?><!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$dir?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | <?=$gwName?> LINK</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Cairo',sans-serif;background:#030609;color:#edf0f7;min-height:100vh}
.topbar{background:rgba(3,6,9,.97);border-bottom:1px solid rgba(255,215,0,.12);height:60px;display:flex;align-items:center;justify-content:space-between;padding:0 28px}
.tb-brand{color:#FFD700;font-weight:900}
.wrap{max-width:920px;margin:0 auto;padding:28px 22px}
.card{background:#090f1e;border:1px solid rgba(255,215,0,.12);border-radius:16px;padding:20px;margin-bottom:16px}
label{display:block;font-size:.72rem;color:#718096;font-weight:700;margin-bottom:5px}
input,select,textarea{width:100%;background:rgba(255,255,255,.04);border:1.5px solid rgba(255,215,0,.12);border-radius:10px;padding:10px 12px;color:#edf0f7;font-family:inherit}
.row{display:grid;grid-template-columns:1fr 140px;gap:12px}
.btn{border:none;border-radius:12px;padding:12px 16px;font-weight:800;font-family:inherit;cursor:pointer;background:linear-gradient(135deg,#FFD700,#FFB700);color:#000}
.msg{padding:10px 14px;border-radius:10px;margin-bottom:14px;font-weight:700;font-size:.82rem}
.ok{background:rgba(16,185,129,.12);color:#10B981}
.err{background:rgba(239,68,68,.12);color:#EF4444}
.table{width:100%;border-collapse:collapse;font-size:.78rem}
.table th,.table td{padding:8px 6px;border-bottom:1px solid rgba(255,255,255,.06);text-align:<?=$ar?'right':'left'?>}
a{color:#FFD700}
@media(max-width:640px){.row{grid-template-columns:1fr}}
</style>
</head>
<body>
<header class="topbar">
  <div class="tb-brand"><i class="fas fa-link"></i> DI PARMA | <?=$gwName?> LINK</div>
  <a href="../checkout_router.php" style="color:#718096;text-decoration:none;font-size:.8rem"><?=$ar?'الدفع':'Checkout'?></a>
</header>
<div class="wrap">
  <?= diparma_gateway_channel_bar($gwCode, 'link', '../', $ar, $extraQ) ?>
  <div style="font-size:.82rem;color:#718096;margin-bottom:16px;line-height:1.6">
    <?=$ledgerCheckout
      ? ($ar ? 'رابط Ledger: الخصم على البوابة المختارة ثم الصافي يصل إلى Ledger.' : 'Ledger link: charge on the selected gateway, then the net arrives at Ledger.')
      : ($ar ? 'رابط هذه البوابة فقط. المبلغ يبقى على ' . $gwName . '.' : 'This gateway’s link only. Funds stay on ' . $gwName . '.')?>
  </div>

  <?php if ($message !== ''): ?>
  <div class="msg <?=$messageType==='success'?'ok':'err'?>"><?=htmlspecialchars($message)?></div>
  <?php endif; ?>
  <?php if ($createdUrl !== ''): ?>
  <div class="card"><div style="font-size:.72rem;color:#10B981;font-weight:800;margin-bottom:6px">LINK</div>
    <div style="word-break:break-all;font-family:monospace"><?=htmlspecialchars($createdUrl)?></div>
  </div>
  <?php endif; ?>

  <div class="card">
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
      <?php if ($isLedgerPage): ?>
      <div style="margin-bottom:12px">
        <label><?=$ar?'بوابة الخصم':'Charge gateway'?></label>
        <select name="charge_gateway" required>
          <option value=""><?=$ar?'— اختر بوابة —':'— Select gateway —'?></option>
          <?php foreach ($attached as $code => $name): ?>
          <option value="<?=htmlspecialchars($code)?>"<?=$chargeGateway===$code?' selected':''?>><?=htmlspecialchars($name)?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div style="margin-bottom:12px">
        <label><?=$ar?'العنوان':'Title'?></label>
        <input type="text" name="title" required value="<?=htmlspecialchars($gwName . ' LINK')?>">
      </div>
      <div class="row" style="margin-bottom:12px">
        <div>
          <label><?=$ar?'المبلغ':'Amount'?></label>
          <input type="number" name="amount" min="0.01" step="0.01" required>
        </div>
        <div>
          <label><?=$ar?'العملة':'Currency'?></label>
          <select name="currency">
            <?php foreach (['USD','EUR','GBP','AED','SAR','USDT'] as $cur): ?>
            <option value="<?=$cur?>"><?=$cur?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div style="margin-bottom:14px">
        <label><?=$ar?'وصف':'Description'?></label>
        <textarea name="description" rows="2"></textarea>
      </div>
      <button class="btn" type="submit" name="create_link" value="1"><i class="fas fa-plus"></i> <?=$ar?'إنشاء رابط':'Create link'?></button>
      <a class="btn" href="<?=htmlspecialchars($openCheckout . '?' . http_build_query($openQs))?>" style="display:inline-block;margin-<?=$ar?'right':'left'?>:8px;background:rgba(255,255,255,.08);color:#edf0f7;text-decoration:none"><?=$ar?'فتح CHECKOUT كرابط':'Open LINK checkout'?></a>
    </form>
  </div>

  <div class="card">
    <div style="font-weight:800;margin-bottom:10px"><?=$ar?'روابط هذه البوابة':'This gateway’s links'?></div>
    <?php if (empty($links)): ?>
    <div style="color:#718096;font-size:.8rem"><?=$ar?'لا توجد روابط بعد.':'No links yet.'?></div>
    <?php else: ?>
    <table class="table">
      <tr><th><?=$ar?'الرمز':'Code'?></th><th><?=$ar?'المبلغ':'Amount'?></th><th><?=$ar?'الحالة':'Status'?></th><th></th></tr>
      <?php foreach ($links as $row): ?>
      <tr>
        <td><?=htmlspecialchars((string) ($row['link_id'] ?? ''))?></td>
        <td><?=htmlspecialchars((string) ($row['amount'] ?? ''))?> <?=htmlspecialchars((string) ($row['currency'] ?? ''))?></td>
        <td><?=htmlspecialchars((string) ($row['status'] ?? ''))?></td>
        <td><a href="../pay.php?link=<?=urlencode((string) ($row['link_id'] ?? ''))?>&token=<?=urlencode((string) ($row['token'] ?? ''))?>"><?=$ar?'فتح':'Open'?></a></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
