<?php
/**
 * DI PARMA | Square 2 · Online — شركة 10
 * استلام وتوصيل Square Online. ليس Payments API (Square 1).
 */
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';

$ar = (isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'ar');
$dir = $ar ? 'rtl' : 'ltr';
$dash = 'https://app.squareup.com/dashboard/fulfillment/preferences/pickup-delivery';
$site = 'https://square.online/app/home/users/156309451/sites/295863802358935413/dashboard';
$loc = 'https://app.squareup.com/dashboard/locations/LHP5AXR2H55RF/details';
$merchantId = 'ML3PPV2CRN3SP';
$locationId = 'LHP5AXR2H55RF';
$siteId = '295863802358935413';
?>
<!DOCTYPE html>
<html lang="<?= $ar ? 'ar' : 'en' ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | Square 2 · Online</title>
<link rel="stylesheet" href="../assets/css/style.css">
<style>
body{background:#071018;color:#eaf2f7;font-family:Cairo,Arial,sans-serif;margin:0}
.wrap{max-width:720px;margin:40px auto;padding:0 20px}
.card{background:#101c27;border:1px solid #213545;border-radius:16px;padding:28px}
h1{margin:0 0 8px;color:#006AFF}
.tag{display:inline-block;background:rgba(0,106,255,.15);color:#6aa8ff;padding:4px 10px;border-radius:999px;font-size:.75rem;font-weight:800;margin-bottom:14px}
p{line-height:1.7;color:#8fa5b4}
.btn{display:inline-block;margin:8px 8px 0 0;padding:12px 20px;border-radius:10px;text-decoration:none;font-weight:800}
.primary{background:#006AFF;color:#fff}
.gold{background:linear-gradient(135deg,#FFE066,#FFD700);color:#000}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="tag">شركة 10 · Square 2</div>
    <h1>Square Online — Pickup &amp; delivery</h1>
    <p><?= $ar
      ? 'هذا مسار المتجر الإلكتروني لشركة 10: طلبات أونلاين مع استلام من الفرع أو توصيل. الخصم بالبطاقة يبقى على Square 1 (Payments API) ثم الصافي USDT → Ledger.'
      : 'This is company 10’s Square Online rail: web orders with in-store pickup or delivery. Card charges stay on Square 1 (Payments API), then net USDT → Ledger.' ?></p>
    <p><?= $ar
      ? 'أوفلاين Square يكون على جهاز Square POS بعد تفعيله من Dashboard، وليس بإدخال الرقم في DIPARMA.'
      : 'Square Offline is on Square POS hardware after you allow it in Dashboard — not keyed PAN in DIPARMA.' ?></p>
    <p style="font-family:monospace;font-size:.78rem;color:#6aa8ff">Merchant <?= htmlspecialchars($merchantId) ?><br>Location <?= htmlspecialchars($locationId) ?><br>Site <?= htmlspecialchars($siteId) ?></p>
    <a class="btn primary" href="<?= htmlspecialchars($dash) ?>" target="_blank" rel="noopener">Pickup &amp; delivery</a>
    <a class="btn primary" href="<?= htmlspecialchars($site) ?>" target="_blank" rel="noopener">Square Online site</a>
    <a class="btn primary" href="<?= htmlspecialchars($loc) ?>" target="_blank" rel="noopener">Location</a>
    <a class="btn gold" href="square.php"><?= $ar ? 'شحن البطاقة — Square 1' : 'Card charge — Square 1' ?></a>
    <a class="btn" href="../checkout_router.php" style="color:#ffd34e"><?= $ar ? 'رجوع' : 'Back' ?></a>
  </div>
</div>
</body>
</html>
