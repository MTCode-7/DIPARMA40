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
        return $_SESSION['csrf_token'];
    }
}

/**
 * التحقق من CSRF Token
 */
if (!function_exists('verifyCsrfToken')) {
    function verifyCsrfToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
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
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedExtensions, true)) {
        throw new RuntimeException('File type not supported.');
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
 * الحصول على حالة المعاملة بالعربي
 */
function getStatusLabel($status) {
    $labels = [
        'pending' => 'قيد الانتظار',
        'authorized' => 'تم التفويض',
        'captured' => 'تم الخصم',
        'settled' => 'تم التسوية',
        'completed' => 'مكتمل',
        'failed' => 'فشل',
        'refunded' => 'مسترد',
        'chargeback' => 'إلغاء',
        'active' => 'نشط',
        'inactive' => 'غير نشط',
        'expired' => 'منتهي',
        'deleted' => 'محذوف'
    ];
    return $labels[$status] ?? $status;
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
        '101.1' => [
            'code' => '101.1',
            'name' => 'تفويض عالمي - Standard Visa/Mastercard',
            'description' => 'تفويض بطاقات الائتمان العالمي',
            'icon' => '💳',
            'type' => 'authorization',
            'features' => ['card', 'global', '3ds']
        ],
        '201.3' => [
            'code' => '201.3',
            'name' => 'تسوية شركات - Corporate Settlement',
            'description' => 'تسوية الحسابات بين الشركات',
            'icon' => '🏢',
            'type' => 'settlement',
            'features' => ['corporate', 'batch', 'verification']
        ],
        '801.9' => [
            'code' => '801.9',
            'name' => 'الأمان الأساسي - Basic Security Protocol',
            'description' => 'بروتوكول أمان أساسي للعمليات البسيطة',
            'icon' => '🔒',
            'type' => 'security',
            'features' => ['secure', 'basic', 'fraud-check']
        ]
    ];
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

?>