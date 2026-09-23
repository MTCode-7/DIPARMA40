<?php
// ============================================================
// الدوال المساعدة - DI PARMA
// ============================================================

/**
 * توليد CSRF Token
 */
if (!function_exists('generateCsrfToken')) {
    function generateCsrfToken() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        $token = (string) $_SESSION['csrf_token'];
        if (!headers_sent()) {
            $requestIsHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
                || strtolower($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on'
                || str_contains($_SERVER['HTTP_CF_VISITOR'] ?? '', '"scheme":"https"');

            setcookie('diparma_csrf_token', $token, [
                'expires' => time() + 3600,
                'path' => '/',
                'secure' => $requestIsHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        $_COOKIE['diparma_csrf_token'] = $token;
        return $token;
    }
}

/**
 * التحقق من CSRF Token
 */
if (!function_exists('verifyCsrfToken')) {
    function verifyCsrfToken($token) {
        $candidate = is_string($token) ? trim($token) : '';
        if ($candidate === '') {
            return false;
        }

        $sessionToken = $_SESSION['csrf_token'] ?? null;

        if ($sessionToken !== null && hash_equals((string) $sessionToken, $candidate)) {
            return true;
        }

        return false;
    }
}

if (!function_exists('validateCsrfToken')) {
    function validateCsrfToken($token): bool
    {
        return verifyCsrfToken($token);
    }
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/**
 * تنسيق المبلغ
 */
function formatCurrency($amount, $currency = 'USD') {
    $symbols = [
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'AED' => 'د.إ',
        'SAR' => 'ر.س',
        'KWD' => 'د.ك',
        'BHD' => 'د.ب',
        'OMR' => 'ر.ع',
        'QAR' => 'ر.ق'
    ];
    $symbol = $symbols[$currency] ?? $currency . ' ';
    return $symbol . number_format($amount, 2);
}

/**
 * الحصول على عنوان IP
 */
function getClientIP() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'HTTP_X_REAL_IP'];
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ips = explode(',', $_SERVER[$header]);
            $ip = trim($ips[0]);
            break;
        }
    }
    return $ip;
}

/**
 * توليد مرجع فريد
 */
function generateReference($prefix = 'DP') {
    return $prefix . date('YmdHis') . rand(1000, 9999);
}

/**
 * إخفاء رقم البطاقة
 */
function maskCardNumber($number) {
    $number = preg_replace('/[^0-9]/', '', $number);
    $length = strlen($number);
    if ($length < 10) return '****';
    return str_repeat('*', $length - 4) . substr($number, -4);
}

/**
 * التأكد من أن المجلد موجود
 */
function ensureDirectoryExists(string $path): bool {
    if (is_dir($path)) {
        return true;
    }
    return @mkdir($path, 0755, true);
}

/**
 * حفظ ملف KYC المرفوع بأمان
 */
function storeUploadedDocument(array $file, string $destinationFolder, array $allowedExtensions = ['jpg','jpeg','png','pdf']): string {
    if (empty($file['name']) || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('No uploaded file provided.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload error code: ' . $file['error']);
    }
    if (($file['size'] ?? 0) <= 0 || $file['size'] > 10 * 1024 * 1024) {
        throw new RuntimeException('Uploaded file is too large or empty.');
    }
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedExtensions, true)) {
        throw new RuntimeException('File type not supported.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $allowedMimeTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'pdf' => 'application/pdf',
    ];
    if (($allowedMimeTypes[$extension] ?? null) !== $mime) {
        throw new RuntimeException('Uploaded file content does not match its extension.');
    }

    ensureDirectoryExists($destinationFolder);
    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
    $targetPath = rtrim($destinationFolder, '/\\') . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new RuntimeException('Unable to move uploaded file.');
    }

    $relativePath = str_replace('\\', '/', substr($targetPath, strlen(ROOT_PATH) + 1));
    return $relativePath;
}

/**
 * تسجيل حدث
 */
function logEvent($message, $level = 'info') {
    $logFile = LOGS_PATH . '/system.log';
    $timestamp = date('Y-m-d H:i:s');
    $ip = getClientIP();
    $user = $_SESSION['user_id'] ?? 'guest';
    $entry = json_encode([
        'timestamp' => $timestamp,
        'level' => $level,
        'message' => $message,
        'user' => $user,
        'ip' => $ip
    ]);
    file_put_contents($logFile, $entry . PHP_EOL, FILE_APPEND);
}

/**
 * إرسال OTP فعلي عبر SMTP/Email أو SMS/WhatsApp عند توفر الإعدادات
 */
function sendOtpDeliveryMessage(string $otpCode, array $context = []): array {
    $recipientEmail = trim((string)($context['customer_email'] ?? env('OTP_NOTIFICATION_EMAIL', '')));
    $recipientPhone = trim((string)($context['customer_phone'] ?? ''));
    $gateway = trim((string)($context['gateway'] ?? ''));
    $reference = trim((string)($context['reference'] ?? ''));
    $transport = strtolower((string)($context['transport'] ?? env('OTP_DELIVERY_TRANSPORT', 'sms')));

    $subject = 'DI PARMA OTP Verification';
    $body = "Your verification code is: {$otpCode}\nReference: {$reference}\nGateway: {$gateway}";
    $smsBody = "DI PARMA OTP: {$otpCode}. Reference: {$reference}";

    $result = [
        'success' => false,
        'method' => 'none',
        'message' => 'No delivery channel configured.',
        'recipient' => $recipientEmail !== '' ? $recipientEmail : $recipientPhone,
    ];

    $twilioSid = (string)env('TWILIO_SID', '');
    $twilioToken = (string)env('TWILIO_TOKEN', '');
    $twilioFrom = (string)env('TWILIO_FROM', '');
    $twilioWhatsAppFrom = (string)env('TWILIO_WHATSAPP_FROM', '');
    $textbeltKey = (string)env('OTP_TEXTBELL_KEY', 'textbelt');

    $smtpHost = (string)env('SMTP_HOST', '');
    $smtpPort = (string)env('SMTP_PORT', '587');
    $smtpUsername = (string)env('SMTP_USERNAME', '');
    $smtpPassword = (string)env('SMTP_PASSWORD', '');
    $smtpFromEmail = (string)env('SMTP_FROM_EMAIL', '');
    $smtpFromName = (string)env('SMTP_FROM_NAME', 'DI PARMA Gateway');

    $sendSms = false;
    $sendEmail = false;

    if (in_array($transport, ['twilio', 'sms', 'text', 'auto'], true)) {
        $sendSms = $recipientPhone !== '' && ($twilioSid !== '' && $twilioToken !== '' && $twilioFrom !== '' || $textbeltKey !== '');
    }

    if ($transport === 'whatsapp') {
        $sendSms = $recipientPhone !== '' && $twilioSid !== '' && $twilioToken !== '' && $twilioWhatsAppFrom !== '';
    }

    if (in_array($transport, ['smtp', 'email', 'auto'], true)) {
        $sendEmail = $recipientEmail !== '' || $smtpFromEmail !== '';
    }

    if ($sendSms) {
        $channel = $transport === 'whatsapp' ? 'whatsapp' : 'sms';
        $to = $recipientPhone;

        if ($twilioSid !== '' && $twilioToken !== '' && ($channel === 'whatsapp' ? $twilioWhatsAppFrom !== '' : $twilioFrom !== '')) {
            $from = $channel === 'whatsapp' ? $twilioWhatsAppFrom : $twilioFrom;
            $endpoint = 'https://api.twilio.com/2010-04-01/Accounts/' . urlencode($twilioSid) . '/Messages.json';
            $bodyData = http_build_query([
                'To' => $channel === 'whatsapp' ? 'whatsapp:' . $to : $to,
                'From' => $from,
                'Body' => $smsBody,
            ]);

            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyData);
            curl_setopt($ch, CURLOPT_USERPWD, $twilioSid . ':' . $twilioToken);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                $result = ['success' => true, 'method' => $channel, 'message' => 'OTP dispatched via ' . $channel, 'recipient' => $to];
            } else {
                $result = ['success' => false, 'method' => $channel, 'message' => 'Twilio delivery failed: ' . $response, 'recipient' => $to];
            }
            return $result;
        }

        $endpoint = 'https://textbelt.com/text';
        $bodyData = http_build_query([
            'phone' => $to,
            'message' => $smsBody,
            'key' => $textbeltKey,
        ]);

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            $result = ['success' => true, 'method' => 'sms', 'message' => 'OTP dispatched via SMS text message', 'recipient' => $to];
        } else {
            $result = ['success' => false, 'method' => 'sms', 'message' => 'SMS delivery failed: ' . $response, 'recipient' => $to];
        }
        return $result;
    }

    if ($sendEmail) {
        $to = $recipientEmail !== '' ? $recipientEmail : $smtpFromEmail;
        $from = $smtpFromEmail !== '' ? $smtpFromEmail : ($recipientEmail !== '' ? $recipientEmail : 'noreply@localhost');
        $headers = [
            'From: ' . $smtpFromName . ' <' . $from . '>',
            'Reply-To: ' . $from,
            'X-Mailer: DI PARMA OTP Gateway',
            'Content-Type: text/plain; charset=UTF-8',
        ];

        if ($smtpHost !== '') {
            $headers[] = 'MIME-Version: 1.0';
            $transportMethod = 'smtp';
            $sent = false;
            if (function_exists('fsockopen')) {
                $sent = @mail($to, $subject, $body, implode("\r\n", $headers));
            } else {
                $sent = @mail($to, $subject, $body, implode("\r\n", $headers));
            }
        } else {
            $transportMethod = 'mail';
            $sent = @mail($to, $subject, $body, implode("\r\n", $headers));
        }

        if ($sent) {
            $result = ['success' => true, 'method' => $transportMethod, 'message' => 'OTP dispatched via ' . $transportMethod, 'recipient' => $to];
        } else {
            $result = ['success' => false, 'method' => $transportMethod, 'message' => 'Email delivery failed.', 'recipient' => $to];
        }
        return $result;
    }

    logEvent('OTP delivery skipped: no valid transport configured for ' . ($recipientEmail !== '' ? $recipientEmail : $recipientPhone), 'warning');
    return $result;
}

/**
 * التحقق من البريد الإلكتروني
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * التحقق من المبلغ
 */
function isValidAmount($amount) {
    return is_numeric($amount) && $amount > 0;
}

/**
 * Transaction status label — English by default; Arabic only when lang=ar
 */
function getStatusLabel($status) {
    $labels = [
        'pending' => ['en' => 'Pending', 'ar' => 'قيد الانتظار'],
        'authorized' => ['en' => 'Authorized', 'ar' => 'تم التفويض'],
        'captured' => ['en' => 'Captured', 'ar' => 'تم الخصم'],
        'settled' => ['en' => 'Settled', 'ar' => 'تم التسوية'],
        'completed' => ['en' => 'Completed', 'ar' => 'مكتمل'],
        'failed' => ['en' => 'Failed', 'ar' => 'فشل'],
        'refunded' => ['en' => 'Refunded', 'ar' => 'مسترد'],
        'chargeback' => ['en' => 'Chargeback', 'ar' => 'إلغاء'],
        'cancelled' => ['en' => 'Cancelled', 'ar' => 'ملغى'],
        'active' => ['en' => 'Active', 'ar' => 'نشط'],
        'inactive' => ['en' => 'Inactive', 'ar' => 'غير نشط'],
        'expired' => ['en' => 'Expired', 'ar' => 'منتهي'],
        'deleted' => ['en' => 'Deleted', 'ar' => 'محذوف'],
    ];
    $lang = 'en';
    if (function_exists('dp_lang')) {
        $lang = dp_lang();
    } elseif (isset($GLOBALS['currentLang'])) {
        $lang = $GLOBALS['currentLang'] === 'ar' ? 'ar' : 'en';
    } elseif (isset($GLOBALS['dp_lang'])) {
        $lang = $GLOBALS['dp_lang'] === 'ar' ? 'ar' : 'en';
    } elseif ((isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'ar')) {
        $lang = 'ar';
    }
    if (!isset($labels[$status])) {
        return (string) $status;
    }
    return $labels[$status][$lang] ?? $labels[$status]['en'];
}

/**
 * الحصول على لون الحالة
 */
function getStatusColor($status) {
    $colors = [
        'pending' => '#f0ad4e',
        'authorized' => '#5bc0de',
        'captured' => '#5bc0de',
        'settled' => '#5cb85c',
        'completed' => '#5cb85c',
        'failed' => '#d9534f',
        'refunded' => '#5bc0de',
        'chargeback' => '#d9534f',
        'active' => '#5cb85c',
        'inactive' => '#d9534f',
        'expired' => '#f0ad4e',
        'deleted' => '#777'
    ];
    return $colors[$status] ?? '#888';
}

/**
 * إنشاء Slug من النص
 */
function generateSlug($text) {
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9-]/', '-', $text);
    $text = preg_replace('/-+/', '-', $text);
    return trim($text, '-');
}

/**
 * التحقق من صلاحية الرابط
 */
function isLinkValid($link) {
    if ($link['status'] !== 'active') return false;
    if ($link['max_uses'] > 0 && $link['uses_count'] >= $link['max_uses']) return false;
    if (strtotime($link['expiry_date']) < time()) return false;
    return true;
}

/**
 * الحصول على قائمة البروتوكولات المتاحة
 * ملاحظة: الأكواد الداخلية (101/201) لا تُعرض للمستخدم — استخدم protocol_display_name()
 */
function getAvailableProtocols(): array {
    return [
        'SIMPLE_WITHDRAWAL' => [
            'code' => 'SIMPLE_WITHDRAWAL',
            'name' => 'السحب البسيط - Direct Simple Withdrawal',
            'description' => 'سحب مباشر بدون برتوكول معقد',
            'icon' => '🔄',
            'type' => 'withdrawal',
            'features' => ['direct', 'simple', 'no-verification']
        ],
        '101.0' => [
            'code' => '101.0',
            'name' => 'سحب مباشر بالبطاقة',
            'description' => 'تحصيل فوري بالبطاقة',
            'icon' => '💳',
            'type' => 'purchase',
            'features' => ['card', 'direct']
        ],
        '101.1' => [
            'code' => '101.1',
            'name' => 'تفويض وتسوية — كل الشبكات والمُصدرين',
            'description' => 'تفويض بطاقات الائتمان ثم التحصيل',
            'icon' => '💳',
            'type' => 'authorization',
            'features' => ['card', 'global', '3ds']
        ],
        '201.3' => [
            'code' => '201.3',
            'name' => 'تسوية شركات / MOTO',
            'description' => 'تسوية ومبيعات MOTO للشركات',
            'icon' => '🏢',
            'type' => 'settlement',
            'features' => ['corporate', 'batch', 'verification']
        ],
        '801.9' => [
            'code' => '801.9',
            'name' => 'الأمان الأساسي',
            'description' => 'بروتوكول أمان أساسي للعمليات البسيطة',
            'icon' => '🔒',
            'type' => 'security',
            'features' => ['secure', 'basic', 'fraud-check']
        ]
    ];
}

/**
 * اسم البروتوكول للعرض فقط — بدون أرقام 101 أو 201 أبداً.
 */
function protocol_display_name(?string $code): string
{
    $code = trim((string)$code);
    if ($code === '') {
        return '—';
    }

    static $labels = [
        '101.0' => ['ar' => 'سحب مباشر بالبطاقة', 'en' => 'Direct Card Sale'],
        '101.1' => ['ar' => 'تفويض وتسوية', 'en' => 'Auth & Capture'],
        '201.3' => ['ar' => 'تسوية شركات / MOTO', 'en' => 'Corporate / MOTO Settlement'],
        '201.0' => ['ar' => 'تسوية مباشرة', 'en' => 'Direct Settlement'],
        '201.2' => ['ar' => 'تسوية مباشرة', 'en' => 'Direct Settlement'],
        '201.4' => ['ar' => 'تسوية مباشرة', 'en' => 'Direct Settlement'],
        '201.5' => ['ar' => 'تسوية مباشرة', 'en' => 'Direct Settlement'],
        '201.6' => ['ar' => 'تسوية مباشرة', 'en' => 'Direct Settlement'],
        '201.7' => ['ar' => 'تسوية مباشرة', 'en' => 'Direct Settlement'],
        '201.9' => ['ar' => 'تسوية مباشرة', 'en' => 'Direct Settlement'],
        '801.9' => ['ar' => 'الأمان الأساسي', 'en' => 'Basic Security'],
        'SIMPLE_WITHDRAWAL' => ['ar' => 'سحب بسيط', 'en' => 'Simple Withdrawal'],
        'DIRECT' => ['ar' => 'مباشر', 'en' => 'Direct'],
    ];

    $lang = (isset($_COOKIE['di_parma_lang']) && $_COOKIE['di_parma_lang'] === 'ar') ? 'ar' : 'en';
    if (isset($labels[$code])) {
        return $labels[$code][$lang] ?? $labels[$code]['en'];
    }

    // أي كود يبدأ بـ 101 أو 201 → اسم عام بدون أرقام
    if (preg_match('/^101(\.|$)/', $code)) {
        return $lang === 'ar' ? 'عملية بطاقة' : 'Card Operation';
    }
    if (preg_match('/^201(\.|$)/', $code)) {
        return $lang === 'ar' ? 'تسوية / MOTO' : 'Settlement / MOTO';
    }

    // تنظيف أي ظهور لـ 101 / 201 كنص بروتوكول في التسمية
    return redact_protocol_numbers($code);
}

/**
 * يحذف من النص المعروض رموز البروتوكول 101 و 201 (ومشتقاتها 101.x / 201.x)
 * لا يُستخدم على IBAN أو أرقام حسابات — فقط على حقول البروتوكول/الرسائل.
 */
function redact_protocol_numbers(string $text): string
{
    $text = preg_replace('/\b101(?:\.\d+)?\b/u', '', $text);
    $text = preg_replace('/\b201(?:\.\d+)?\b/u', '', $text);
    $text = preg_replace('/[ \t]{2,}/u', ' ', $text);
    $text = preg_replace('/\s([,.:;])/u', '$1', $text);
    $text = trim($text, " \t\n\r\0\x0B-–—");
    return $text === '' ? '—' : $text;
}

/**
 * الحصول على تفاصيل بروتوكول محدد
 */
function getProtocolDetails($code): ?array {
    $protocols = getAvailableProtocols();
    return $protocols[$code] ?? null;
}

/**
 * التحقق من وجود بروتوكول
 */
function protocolExists($code): bool {
    return isset(getAvailableProtocols()[$code]);
}

/**
 * استرجاع نص الشروط من الجدول (سطر واحد)
 */
function getSiteTerms(): string {
    try {
        $db = db();
        $db->execute("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "site_terms` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `terms_text` TEXT DEFAULT NULL,
            `updated_at` DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $row = $db->query('SELECT * FROM ' . DB_PREFIX . 'site_terms ORDER BY id DESC LIMIT 1', []);
        return $row[0]['terms_text'] ?? '';
    } catch (Exception $e) {
        return '';
    }
}

/**
 * حفظ نص الشروط (سطر واحد)
 */
function saveSiteTerms(string $text) {
    $db = db();
    try {
        $db->execute("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "site_terms` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `terms_text` TEXT DEFAULT NULL,
            `updated_at` DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->insert('site_terms', [
            'terms_text' => $text,
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        return true;
    } catch (Exception $e) {
        logEvent('Failed to save site terms: ' . $e->getMessage(), 'error');
        return false;
    }
}

/** Statuses shown in DIPARMA lists; declined attempts are stored separately. */
function diparma_visible_transaction_sql(): string
{
    return "LOWER(COALESCE(status,'')) IN ('completed','authorized','captured','settled','approved','refunded','pending','processing','pending_ledger')";
}

function diparma_should_persist_charge(bool $success, bool $pending3ds = false): bool
{
    return $success || $pending3ds;
}

function diparma_record_declined_attempt($db, array $attempt): void
{
    if ($db === null) {
        return;
    }
    try {
        $db->execute("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "payment_attempts` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `reference` VARCHAR(80) NOT NULL,
            `user_id` BIGINT NULL,
            `gateway` VARCHAR(64) NOT NULL,
            `transaction_type` VARCHAR(64) NOT NULL,
            `amount` DECIMAL(20,8) NOT NULL DEFAULT 0,
            `currency` VARCHAR(12) NOT NULL DEFAULT 'USD',
            `status` VARCHAR(32) NOT NULL DEFAULT 'declined',
            `reason` TEXT NULL,
            `card_last4` VARCHAR(4) NULL,
            `details` LONGTEXT NULL,
            `created_at` DATETIME NOT NULL,
            INDEX `idx_payment_attempts_reference` (`reference`),
            INDEX `idx_payment_attempts_user_created` (`user_id`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $details = $attempt['details'] ?? [];
        if (function_exists('pos_redact_pci')) {
            $details = pos_redact_pci($details);
        }
        $db->insertAvailable('payment_attempts', [
            'reference' => trim((string) ($attempt['reference'] ?? '')),
            'user_id' => !empty($attempt['user_id']) ? (int) $attempt['user_id'] : null,
            'gateway' => trim((string) ($attempt['gateway'] ?? '')),
            'transaction_type' => trim((string) ($attempt['transaction_type'] ?? '')),
            'amount' => (float) ($attempt['amount'] ?? 0),
            'currency' => strtoupper(trim((string) ($attempt['currency'] ?? 'USD'))),
            'status' => 'declined',
            'reason' => trim((string) ($attempt['reason'] ?? 'Declined')),
            'card_last4' => preg_replace('/\D/', '', (string) ($attempt['card_last4'] ?? '')),
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        if (function_exists('logEvent')) {
            logEvent('record declined payment attempt: ' . $e->getMessage(), 'error');
        }
    }
}

function diparma_discard_unsuccessful_transaction($db, string $reference): void
{
    $reference = trim($reference);
    if ($reference === '' || $db === null) {
        return;
    }
    try {
        $row = $db->find('transactions', ['reference' => $reference]);
        if (!$row) {
            return;
        }
        $st = strtolower(trim((string) ($row['status'] ?? '')));
        if (in_array($st, ['completed', 'authorized', 'captured', 'settled', 'approved', 'refunded'], true)) {
            return;
        }
        $db->delete('transactions', ['id' => (int) $row['id']]);
    } catch (Throwable $e) {
        if (function_exists('logEvent')) {
            logEvent('discard unsuccessful txn: ' . $e->getMessage(), 'error');
        }
    }
}

/**
 * Extract host payment / capture / intent id from a stored transaction row.
 */
function diparma_host_payment_id_from_txn(array $txn): string
{
    $blob = json_decode((string) ($txn['gateway_response'] ?? ''), true);
    if (!is_array($blob)) {
        $blob = [];
    }
    $keys = [
        'capture_id', 'payment_id', 'charge_id', 'payment_intent_id', 'payment_intent',
        'transaction_id', 'provider_payment_id', 'nuvei_txn_id', 'id', 'order_id',
        'authorization_id',
    ];
    $nodes = [$blob];
    if (isset($blob['raw']) && is_array($blob['raw'])) {
        $nodes[] = $blob['raw'];
    }
    if (isset($blob['gateway_response']) && is_array($blob['gateway_response'])) {
        $nodes[] = $blob['gateway_response'];
    }
    foreach ($nodes as $node) {
        foreach ($keys as $key) {
            $value = trim((string) ($node[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
    }
    foreach (['rrn', 'gateway_txn_id', 'external_id', 'host_txn_id'] as $col) {
        $value = trim((string) ($txn[$col] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

/**
 * Admin refund / void for a stored transaction via ChargeHub → gateway adapter.
 */
function processRefundTransaction(string $reference, float $amount = 0, string $reason = ''): array
{
    $reference = trim($reference);
    $reason = trim($reason) !== '' ? trim($reason) : 'Refund requested by admin';
    if ($reference === '') {
        return ['success' => false, 'message' => 'المرجع مفقود'];
    }

    try {
        $db = db();
        $txn = $db->find('transactions', ['reference' => $reference]);
        if (!$txn) {
            return ['success' => false, 'message' => 'المعاملة غير موجودة'];
        }

        $status = strtolower(trim((string) ($txn['status'] ?? '')));
        $gateway = strtolower(trim((string) ($txn['gateway'] ?? '')));
        $currency = strtoupper(trim((string) ($txn['currency'] ?? 'USD'))) ?: 'USD';
        $txnAmount = round((float) ($txn['amount'] ?? 0), 2);
        $refundAmount = $amount > 0 ? round($amount, 2) : $txnAmount;

        if ($gateway === '') {
            return ['success' => false, 'message' => 'بوابة المعاملة غير معروفة'];
        }
        if (in_array($status, ['refunded', 'cancelled', 'canceled', 'voided'], true)) {
            return ['success' => false, 'message' => 'المعاملة مسترجعة أو ملغاة مسبقاً'];
        }
        if (!in_array($status, ['completed', 'captured', 'settled', 'approved', 'authorized', 'partially_refunded'], true)) {
            return ['success' => false, 'message' => 'لا يمكن استرجاع معاملة بحالة: ' . ($status !== '' ? $status : 'unknown')];
        }
        if ($refundAmount <= 0 || ($txnAmount > 0 && $refundAmount > $txnAmount + 0.009)) {
            return ['success' => false, 'message' => 'مبلغ الاسترجاع غير صالح'];
        }

        $hostId = diparma_host_payment_id_from_txn($txn);
        if ($hostId === '') {
            return ['success' => false, 'message' => 'معرّف عملية البوابة غير موجود في سجل المعاملة'];
        }

        $isAuthOnly = in_array($status, ['authorized'], true);
        $txnType = $isAuthOnly ? 'void' : 'refund';

        require_once __DIR__ . '/../lib/MySystem/ChargeHub.php';
        $result = DiParmaChargeHub::charge($gateway, $txnType, [
            'amount' => $refundAmount,
            'currency' => $currency,
            'reference' => $reference . ($isAuthOnly ? '-VOID' : '-RF'),
            'transaction_id' => $hostId,
            'related_transaction_id' => $hostId,
            'orig_ref' => $hostId,
            'payment_id' => $hostId,
            'reason' => $reason,
            'channel' => 'admin_refund',
            'txn_type' => $txnType,
        ]);

        if (empty($result['success'])) {
            return [
                'success' => false,
                'message' => (string) ($result['message'] ?? 'فشل الاسترجاع عبر البوابة'),
                'gateway' => $gateway,
                'raw' => $result,
            ];
        }

        $gatewayData = json_decode((string) ($txn['gateway_response'] ?? ''), true) ?: [];
        $gatewayData[$isAuthOnly ? 'void' : 'refund'] = [
            'host_id' => $hostId,
            'amount' => $refundAmount,
            'currency' => $currency,
            'reason' => $reason,
            'refund_id' => $result['refund_id'] ?? $result['transaction_id'] ?? '',
            'at' => date('Y-m-d H:i:s'),
            'result' => $result,
        ];

        $newStatus = $isAuthOnly
            ? 'cancelled'
            : (($txnAmount > 0 && round($refundAmount, 2) < round($txnAmount, 2)) ? 'partially_refunded' : 'refunded');

        $db->update('transactions', [
            'status' => $newStatus,
            'gateway_response' => json_encode($gatewayData, JSON_UNESCAPED_UNICODE),
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['reference' => $reference]);

        if (function_exists('logEvent')) {
            logEvent("Refund {$reference} via {$gateway}: {$refundAmount} {$currency} ({$newStatus})", 'info');
        }

        return [
            'success' => true,
            'message' => $isAuthOnly
                ? 'تم إلغاء التفويض بنجاح'
                : 'تم الاسترجاع بنجاح',
            'status' => $newStatus,
            'reference' => $reference,
            'amount' => $refundAmount,
            'currency' => $currency,
            'gateway' => $gateway,
            'refund_id' => $result['refund_id'] ?? $result['transaction_id'] ?? '',
        ];
    } catch (Throwable $e) {
        if (function_exists('logEvent')) {
            logEvent('processRefundTransaction: ' . $e->getMessage(), 'error');
        }
        return ['success' => false, 'message' => 'خطأ أثناء الاسترجاع: ' . $e->getMessage()];
    }
}

?>