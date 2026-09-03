<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';

$db = db();
$ref = trim($_GET['ref'] ?? '');
if (empty($ref)) {
    header('Location: transactions.php');
    exit();
}
$contract = $db->query('SELECT * FROM ' . DB_PREFIX . 'contracts WHERE reference = ? LIMIT 1', [$ref]);
$contract = $contract[0] ?? null;
if (!$contract) {
    header('Location: receipt.php?ref=' . urlencode($ref));
    exit();
}

// Build HTML for PDF
ob_start();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang ?? 'en') ?>" dir="<?= htmlspecialchars($pageDir ?? 'ltr') ?>">
<head>
<meta charset="utf-8">
<style>
body{font-family: DejaVu Sans, Cairo, sans-serif; color:#111}
.container{padding:20px}
h2{color:#444}
.detail{margin-bottom:12px}
.terms{background:#f8f8f8;padding:12px;border-radius:6px}
</style>
</head>
<body>
<div class="container">
  <h2>العقد الإلكتروني - <?= htmlspecialchars($contract['reference']) ?></h2>
  <?php if (!empty($contract['service_name'])): ?><div class="detail"><strong>اسم الخدمة:</strong> <?= htmlspecialchars($contract['service_name']) ?></div><?php endif; ?>
  <?php if (!empty($contract['service_description'])): ?><div class="detail"><strong>وصف الخدمة:</strong><div><?= nl2br(htmlspecialchars($contract['service_description'])) ?></div></div><?php endif; ?>
  <?php if (!empty($contract['delivery_method'])): ?><div class="detail"><strong>طريقة الاستلام:</strong> <?= htmlspecialchars($contract['delivery_method']) ?></div><?php endif; ?>
  <?php if (!empty($contract['delivery_notes'])): ?><div class="detail"><strong>ملاحظات الاستلام:</strong> <?= htmlspecialchars($contract['delivery_notes']) ?></div><?php endif; ?>
  <hr/>
  <div class="terms">
    <strong>الشروط والأحكام:</strong>
    <div style="margin-top:8px"><?= nl2br(htmlspecialchars($contract['terms_text'])) ?></div>
  </div>
  <div style="margin-top:12px;color:#d9534f;font-weight:700;">تمت الموافقة على الشروط بما في ذلك شرط عدم الاسترجاع.</div>
</div>
</body>
</html>
<?php
$html = ob_get_clean();

// If Dompdf is available, render to PDF
$autoloader = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloader)) {
    require $autoloader;
    if (class_exists('Dompdf\Dompdf')) {
        $dompdf = new Dompdf\Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $filename = 'contract_' . $ref . '.pdf';
        $dompdf->stream($filename, ['Attachment' => 1]);
        exit();
    }
}

// Fallback: force download HTML file
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="contract_' . $ref . '.html"');
echo $html;
exit();
