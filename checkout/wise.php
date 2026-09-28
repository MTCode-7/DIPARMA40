<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/gateways.php';
require_once __DIR__ . '/../includes/auth_check.php';

if (!dp_gateway_is_visible_on_channels('wise')) {
    header('Location: ../checkout_router.php?error=gateway_not_ready', true, 302);
    exit;
}

$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'ar' ? 'ar' : 'en';
$ar = $lang === 'ar';
$currencies = ['USD', 'EUR', 'GBP', 'AED'];
$sourceCurrency = strtoupper(trim((string) ($_GET['currency'] ?? 'USD')));
if (!in_array($sourceCurrency, $currencies, true)) {
    $sourceCurrency = 'USD';
}
$amount = trim((string) ($_GET['amount'] ?? ''));
if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $amount) || (float) $amount <= 0) {
    $amount = '';
}
$csrf = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="<?=$lang?>" dir="<?=$ar ? 'rtl' : 'ltr'?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | Wise Transfer</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{box-sizing:border-box}body{margin:0;background:#061014;color:#edf4f2;font-family:'Cairo',sans-serif;min-height:100vh}
header{height:62px;padding:0 24px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid rgba(159,232,112,.2);background:#08171a}
a{color:#9fe870;text-decoration:none}.brand{font-weight:900;color:#9fe870}.wrap{max-width:760px;margin:28px auto;padding:0 18px}
h1{font-size:1.35rem;margin:0 0 6px}.sub{color:#a9bbb7;font-size:.82rem;margin:0 0 18px;line-height:1.7}
.notice{padding:12px 14px;margin-bottom:18px;border:1px solid rgba(245,158,11,.35);background:rgba(245,158,11,.08);color:#f5ce83;border-radius:8px;font-size:.78rem;line-height:1.7}
form{display:grid;grid-template-columns:1fr 1fr;gap:12px}.field{min-width:0}.wide{grid-column:1/-1}label{display:block;font-size:.72rem;color:#a9bbb7;font-weight:700;margin-bottom:5px}
input,select{width:100%;height:44px;background:#0c2022;border:1px solid rgba(159,232,112,.2);border-radius:7px;color:#edf4f2;padding:0 12px;font:inherit;font-size:.84rem}
button{height:46px;border:0;border-radius:7px;background:#9fe870;color:#102012;font:inherit;font-weight:900;cursor:pointer}button:disabled{opacity:.5;cursor:wait}
#result{display:none;margin-top:16px;padding:12px;border-radius:7px;font-size:.82rem;line-height:1.6;overflow-wrap:anywhere}#result.show{display:block}.ok{border:1px solid #10b981;color:#a7f3d0;background:rgba(16,185,129,.08)}.err{border:1px solid #ef4444;color:#fecaca;background:rgba(239,68,68,.08)}
@media(max-width:560px){form{grid-template-columns:1fr}.wide{grid-column:auto}header{padding:0 14px}}
</style>
</head>
<body>
<header><div class="brand"><i class="fas fa-exchange-alt"></i> DI PARMA | Wise</div><a href="../checkout_router.php"><?=$ar ? 'رجوع للدفع' : 'Back to Checkout'?></a></header>
<main class="wrap">
  <h1><?=$ar ? 'تحويل Wise إلى مستفيد' : 'Wise transfer to recipient'?></h1>
  <p class="sub"><?=$ar ? 'هذه عملية تحويل من رصيد Wise وليست خصماً من بطاقة. راجع بيانات المستفيد والمبلغ قبل الإرسال.' : 'This sends a transfer from the Wise balance; it is not a card charge. Review the recipient and amount before sending.'?></p>
  <div class="notice"><i class="fas fa-exclamation-triangle"></i> <?=$ar ? 'عند الضغط على إرسال، سينشئ النظام التحويل ويموّله من حساب Wise المتصل.' : 'Submitting creates and funds a transfer from the connected Wise account.'?></div>
  <form id="wiseTransferForm">
    <div class="field"><label for="amount"><?=$ar ? 'المبلغ المصدر' : 'Source amount'?></label><input id="amount" name="amount" type="number" min="0.01" step="0.01" required value="<?=htmlspecialchars($amount, ENT_QUOTES, 'UTF-8')?>"></div>
    <div class="field"><label for="source_currency"><?=$ar ? 'عملة المصدر' : 'Source currency'?></label><select id="source_currency" name="source_currency"><?php foreach ($currencies as $currency): ?><option value="<?=$currency?>"<?=$sourceCurrency === $currency ? ' selected' : ''?>><?=$currency?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="target_currency"><?=$ar ? 'عملة المستفيد' : 'Recipient currency'?></label><select id="target_currency" name="target_currency"><?php foreach ($currencies as $currency): ?><option value="<?=$currency?>"<?=$sourceCurrency === $currency ? ' selected' : ''?>><?=$currency?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="recipient_name"><?=$ar ? 'اسم المستفيد' : 'Recipient name'?></label><input id="recipient_name" name="recipient_name" required maxlength="120" autocomplete="name"></div>
    <div class="field"><label for="recipient_email"><?=$ar ? 'بريد المستفيد (اختياري)' : 'Recipient email (optional)'?></label><input id="recipient_email" name="recipient_email" type="email" maxlength="190" autocomplete="email"></div>
    <div class="field"><label for="country"><?=$ar ? 'رمز دولة المستفيد' : 'Recipient country code'?></label><input id="country" name="country" value="AE" required maxlength="2" pattern="[A-Za-z]{2}"></div>
    <div class="field"><label for="iban">IBAN</label><input id="iban" name="iban" maxlength="34" autocomplete="off"></div>
    <div class="field"><label for="account_number"><?=$ar ? 'رقم الحساب' : 'Account number'?></label><input id="account_number" name="account_number" maxlength="34" autocomplete="off"></div>
    <div class="field"><label for="swift">SWIFT / BIC</label><input id="swift" name="swift" maxlength="11" autocomplete="off"></div>
    <div class="field"><label for="routing_number"><?=$ar ? 'رقم التوجيه ABA (إن وجد)' : 'ABA routing number (if applicable)'?></label><input id="routing_number" name="routing_number" maxlength="20" autocomplete="off"></div>
    <button class="wide" id="submitWise" type="submit"><i class="fas fa-paper-plane"></i> <?=$ar ? 'إنشاء وإرسال التحويل' : 'Create and send transfer'?></button>
  </form>
  <div id="result" role="status" aria-live="polite"></div>
</main>
<script>
const CSRF = <?=json_encode($csrf)?>;
const AR = <?=$ar ? 'true' : 'false'?>;
const form = document.getElementById('wiseTransferForm');
const button = document.getElementById('submitWise');
const resultBox = document.getElementById('result');
form.addEventListener('submit', async event => {
  event.preventDefault();
  const payload = Object.fromEntries(new FormData(form).entries());
  payload.action = 'transfer';
  payload.csrf_token = CSRF;
  payload.reference = 'WISE_' + Date.now();
  button.disabled = true;
  resultBox.className = 'show';
  resultBox.textContent = AR ? 'جارٍ تنفيذ التحويل...' : 'Submitting transfer...';
  try {
    const response = await fetch('../api/wise_payment.php?action=transfer', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(payload)
    });
    const data = await response.json();
    if (!response.ok || !data.success) throw new Error(data.message || 'Wise transfer failed');
    resultBox.className = 'show ok';
    resultBox.textContent = (AR ? 'تم إرسال التحويل. المرجع: ' : 'Transfer submitted. Reference: ') + (data.reference || payload.reference) + ' · ' + (data.status || 'PROCESSING');
  } catch (error) {
    resultBox.className = 'show err';
    resultBox.textContent = error.message || (AR ? 'فشل التحويل' : 'Transfer failed');
    button.disabled = false;
  }
});
</script>
</body>
</html>