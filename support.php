<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$lang = isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'en' ? 'en' : 'ar';
if (isset($_GET['lang']) && in_array($_GET['lang'], ['ar', 'en'], true)) {
    setcookie('di_parma_lang', $_GET['lang'], time() + 31536000, '/');
    $lang = $_GET['lang'];
}
$ar = $lang === 'ar';
$dir = $ar ? 'rtl' : 'ltr';
$message = '';
$error = '';
$db = db();

try {
    $db->execute("CREATE TABLE IF NOT EXISTS " . DB_PREFIX . "support_tickets (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED DEFAULT NULL,
        name VARCHAR(150) NOT NULL,
        email VARCHAR(255) NOT NULL,
        subject VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT NULL,
        INDEX idx_support_user (user_id), INDEX idx_support_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->execute("CREATE TABLE IF NOT EXISTS " . DB_PREFIX . "support_documents (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ticket_id BIGINT UNSIGNED NOT NULL,
        user_id INT UNSIGNED DEFAULT NULL,
        original_name VARCHAR(255) NOT NULL,
        stored_path VARCHAR(500) NOT NULL,
        mime_type VARCHAR(100) NOT NULL,
        file_size INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_document_ticket (ticket_id), INDEX idx_document_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    $error = $ar ? 'تعذر تجهيز خدمة العملاء.' : 'Customer service is temporarily unavailable.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $body = trim($_POST['message'] ?? '');
    $file = $_FILES['document'] ?? null;
    $allowedMime = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $subject === '' || $body === '') {
        $error = $ar ? 'يرجى تعبئة جميع الحقول بشكل صحيح.' : 'Please complete all fields correctly.';
    } elseif ($file && $file['error'] !== UPLOAD_ERR_NO_FILE && ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 10 * 1024 * 1024)) {
        $error = $ar ? 'المستند غير صالح أو يتجاوز 10 ميجابايت.' : 'Invalid document or file exceeds 10 MB.';
    } else {
        try {
            $db->insert('support_tickets', [
                'user_id' => !empty($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null,
                'name' => $name,
                'email' => $email,
                'subject' => $subject,
                'message' => $body,
                'status' => 'open',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $ticketId = $db->getLastInsertId();

            if ($file && $file['error'] === UPLOAD_ERR_OK) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file['tmp_name']);
                if (!isset($allowedMime[$mime])) {
                    throw new RuntimeException($ar ? 'نوع المستند غير مسموح.' : 'Document type is not allowed.');
                }
                $folder = ROOT_PATH . '/private_uploads/support';
                if (!is_dir($folder) && !mkdir($folder, 0750, true)) {
                    throw new RuntimeException('Upload directory unavailable.');
                }
                $filename = bin2hex(random_bytes(20)) . '.' . $allowedMime[$mime];
                $path = $folder . DIRECTORY_SEPARATOR . $filename;
                if (!move_uploaded_file($file['tmp_name'], $path)) {
                    throw new RuntimeException('Document upload failed.');
                }
                $db->insert('support_documents', [
                    'ticket_id' => $ticketId,
                    'user_id' => !empty($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null,
                    'original_name' => basename($file['name']),
                    'stored_path' => 'private_uploads/support/' . $filename,
                    'mime_type' => $mime,
                    'file_size' => intval($file['size']),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
            $message = $ar ? 'تم استلام طلبك. سيتواصل معك فريق خدمة العملاء.' : 'Your request was received. Customer service will contact you.';
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DIPARMA ULTIMATE GATEWAY | <?= $ar ? 'خدمة العملاء' : 'Customer Service' ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#040810;color:#f0f0f0;font-family:Cairo,Arial,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}.card{width:100%;max-width:620px;background:#080d1a;border:1px solid rgba(255,215,0,.2);border-radius:18px;padding:32px}.brand{text-align:center;color:#ffd700;font-size:1.3rem;font-weight:900;margin-bottom:8px}.sub{text-align:center;color:#888;font-size:.8rem;margin-bottom:26px}h1{font-size:1.35rem;margin:0 0 8px;text-align:center}p{color:#aaa;font-size:.82rem;line-height:1.7;text-align:center}.notice{padding:12px;border-radius:10px;margin:16px 0;font-size:.82rem;text-align:center}.ok{background:rgba(16,185,129,.1);border:1px solid #10b981;color:#65e6b0}.bad{background:rgba(239,68,68,.1);border:1px solid #ef4444;color:#ff8d8d}label{display:block;color:#aaa;font-size:.78rem;margin:14px 0 6px}input,textarea{width:100%;padding:11px 13px;background:rgba(255,255,255,.04);border:1px solid rgba(255,215,0,.16);border-radius:10px;color:#fff;font:inherit;font-size:.85rem}textarea{min-height:120px;resize:vertical}button{width:100%;margin-top:20px;padding:13px;border:0;border-radius:11px;background:linear-gradient(135deg,#ffd700,#ffb700);font-weight:800;cursor:pointer}.back{display:block;text-align:center;color:#ffd700;margin-top:18px;text-decoration:none;font-size:.8rem}
</style>
</head>
<body><main class="card">
<div class="brand">DIPARMA ULTIMATE GATEWAY</div><div class="sub"><?= $ar ? 'خدمة العملاء والدعم الآمن' : 'Secure customer service and support' ?></div>
<h1><?= $ar ? 'تواصل مع خدمة العملاء' : 'Contact Customer Service' ?></h1>
<p><?= $ar ? 'يمكنك إرسال بيانات الطلب ورفع مستند داعم بصيغة PDF أو JPG أو PNG.' : 'Send your request and optionally attach a PDF, JPG, or PNG document.' ?></p>
<?php if ($message): ?><div class="notice ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice bad"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<form method="POST" enctype="multipart/form-data">
<label><?= $ar ? 'الاسم' : 'Name' ?></label><input name="name" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
<label><?= $ar ? 'البريد الإلكتروني' : 'Email' ?></label><input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
<label><?= $ar ? 'موضوع الطلب' : 'Subject' ?></label><input name="subject" required value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>">
<label><?= $ar ? 'الرسالة' : 'Message' ?></label><textarea name="message" required><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
<label><?= $ar ? 'المستند (اختياري، حتى 10 ميجابايت)' : 'Document (optional, up to 10 MB)' ?></label><input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png">
<button type="submit"><?= $ar ? 'إرسال إلى خدمة العملاء' : 'Send to Customer Service' ?></button>
</form><a class="back" href="<?= !empty($_SESSION['user_id']) ? 'index.php' : 'landing.php' ?>"><?= $ar ? 'العودة' : 'Back' ?></a>
</main></body></html>
