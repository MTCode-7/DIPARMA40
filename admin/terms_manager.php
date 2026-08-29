<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();
$db = db();
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['terms_text'])) {
    $text = trim($_POST['terms_text']);
    if (saveSiteTerms($text)) {
        $message = '✅ تم حفظ نص الشروط بنجاح.';
    } else {
        $message = '❌ حدث خطأ عند حفظ الشروط.';
    }
}
$current = getSiteTerms();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang ?? 'ar') ?>" dir="<?= htmlspecialchars($pageDir ?? 'rtl') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>إدارة الشروط والأحكام</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap" rel="stylesheet">
<style>body{font-family:'Cairo',sans-serif;background:#0b0f17;color:#f7d76b;padding:20px} .card{max-width:900px;margin:0 auto;background:rgba(10,16,39,0.95);border:1px solid rgba(255,215,0,0.12);padding:20px;border-radius:12px} textarea{width:100%;min-height:240px;padding:12px;border-radius:8px;background:#071021;color:#fff;border:1px solid rgba(255,255,255,0.04)} .btn{padding:10px 14px;border-radius:8px;background:#ffd700;color:#000;text-decoration:none;border:none;cursor:pointer}</style>
</head>
<body>
<div class="card">
  <h2>إدارة الشروط والأحكام الافتراضية</h2>
  <?php if ($message): ?><div style="margin:10px 0;padding:8px;background:rgba(255,255,255,0.03);border-radius:8px;"><?= htmlspecialchars($message) ?></div><?php endif; ?>
  <form method="POST">
    <label>نص الشروط والأحكام:</label>
    <textarea name="terms_text"><?= htmlspecialchars($current) ?></textarea>
    <div style="margin-top:12px;display:flex;gap:8px;justify-content:flex-end;">
      <a href="../index.php" class="btn" style="background:#1a2340;color:#FFD700;text-decoration:none;display:inline-block;padding:10px 12px;border-radius:8px">&#8962; الرئيسية</a>
      <a href="../dashboard.php" class="btn" style="background:#333;color:#fff;text-decoration:none;display:inline-block;padding:10px 12px;border-radius:8px">العودة</a>
      <button class="btn" type="submit">حفظ الشروط</button>
    </div>
  </form>
</div>
</body>
</html>
