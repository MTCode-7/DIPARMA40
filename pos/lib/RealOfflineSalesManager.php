<?php
/**
 * إدارة عمليات البيع أوفلاين (Store and Forward - SAF)
 * نظام حقيقي لتخزين العمليات محلياً وإرسالها عند عودة الإنترنت
 *
 * من البنك: حد العملية الواحدة أوفلاين = 2,000,000
 * البوابة عند المزامنة = التي يختارها المستخدم (ليست ثابتة).
 */
if (defined('DI_PARMA_REAL_OFFLINE_SALES')) {
    return;
}
define('DI_PARMA_REAL_OFFLINE_SALES', true);

class RealOfflineSalesManager
{
    private $localDbFile = 'saf_storage.db';
    private $maxOfflineLimit = 2000000.00; // قيد البنك — رفض رفعه؛ الحد الأقصى 2,000,000 للعملية أوفلاين

    public function __construct(?string $storageFile = null)
    {
        if ($storageFile !== null && $storageFile !== '') {
            $this->localDbFile = $storageFile;
            return;
        }
        $dir = defined('CACHE_PATH')
            ? CACHE_PATH
            : (defined('POS_APP_ROOT') ? POS_APP_ROOT . '/cache' : dirname(__DIR__, 2) . '/cache');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $this->localDbFile = rtrim($dir, '/\\') . '/saf_storage.db';
    }

    public function maxOfflineLimit(): float
    {
        return 2000000.00; // bank-locked
    }

    public function storageFile(): string
    {
        return $this->localDbFile;
    }

    /**
     * معالجة عملية البيع عند انقطاع الاتصال
     */
    public function processOfflineSale($terminalId, $referenceNumber, $amount, $cardData)
    {
        // 1. التحقق من عدم تجاوز حد السحب الأقصى المسموح به في وضع الأوفلاين
        if ($amount > $this->maxOfflineLimit) {
            return [
                'status' => 'DECLINED',
                'reason' => 'EXCEEDS_OFFLINE_LIMIT',
                'message' => 'المبلغ يتجاوز حد البنك للأوفلاين (2,000,000) — البنك رفض رفع هذا الحد.',
            ];
        }

        if (trim((string) $terminalId) === '') {
            return [
                'status' => 'DECLINED',
                'reason' => 'MISSING_TID',
                'message' => 'رقم التيرمنل (TID) مطلوب لعملية الأوفلاين.',
            ];
        }

        if (trim((string) $referenceNumber) === '') {
            return [
                'status' => 'DECLINED',
                'reason' => 'MISSING_REF',
                'message' => 'الرقم المرجعي مطلوب لعملية الأوفلاين.',
            ];
        }

        // 2. تشفير بيانات البطاقة محلياً لحماية الأمان (PCI-DSS Compliance)
        // لا تُخزَّن PAN خام — توكن مشتق + حقول غير حساسة للمزامنة
        $cardRaw = is_array($cardData) ? json_encode($cardData, JSON_UNESCAPED_UNICODE) : (string) $cardData;
        $encryptedToken = hash('sha256', $cardRaw . time());

        $meta = [];
        if (is_array($cardData)) {
            $pan = preg_replace('/\D/', '', (string) ($cardData['card_number'] ?? ''));
            $meta = [
                'last4' => $pan !== '' ? substr($pan, -4) : '',
                'card_expiry' => (string) ($cardData['card_expiry'] ?? ''),
                'approval_code' => (string) ($cardData['auth_code'] ?? $cardData['approval_code'] ?? ''),
                'gateway' => (string) ($cardData['gateway'] ?? ''),
                'currency' => (string) ($cardData['currency'] ?? 'USD'),
                'merchant_id' => (string) ($cardData['merchant_id'] ?? ''),
            ];
        }

        // 3. بناء هيكل سجل العملية المخزنة
        $transactionRecord = [
            'tid' => $terminalId,
            'ref_number' => $referenceNumber,
            'amount' => $amount,
            'card_token' => $encryptedToken,
            'timestamp' => date('c'),
            'status' => 'STORED_PENDING_SYNC',
            'meta' => $meta,
        ];

        // 4. الحفظ الفعلي في التخزين المحلي (قاعدة بيانات الجهاز أو ملف السجلات المؤقت)
        $saveResult = file_put_contents(
            $this->localDbFile,
            json_encode($transactionRecord, JSON_UNESCAPED_UNICODE) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

        if ($saveResult === false) {
            return [
                'status' => 'ERROR',
                'message' => 'فشل في حفظ بيانات العملية محلياً في ذاكرة الجهاز.',
            ];
        }

        return [
            'status' => 'QUEUED_PENDING_HOST',
            'ref_number' => $referenceNumber,
            'amount' => $amount,
            'tid' => $terminalId,
            'message' => 'Stored for host retry only. Not approved. Charge the live gateway.',
            'success' => false,
            'offline' => true,
            'saf' => true,
            'transaction_id' => $referenceNumber,
            'reference' => $referenceNumber,
        ];
    }

    /**
     * مزامنة وإرسال العمليات المخزنة للبنك فور عودة الاتصال (Forward)
     * البوابة/المضيف من اختيار المستخدم عبر $gatewayEndpoint + $apiSecretKey
     */
    public function syncOfflineQueue($gatewayEndpoint, $apiSecretKey)
    {
        if (!file_exists($this->localDbFile)) {
            return ['synced_count' => 0, 'message' => 'لا توجد عمليات معلقة في وضع الأوفلاين.'];
        }

        $gatewayEndpoint = trim((string) $gatewayEndpoint);
        if ($gatewayEndpoint === '') {
            return [
                'status' => 'ERROR',
                'synced_count' => 0,
                'message' => 'عنوان مضيف المزامنة مطلوب (اختر البوابة / BANK_SAF_HOST).',
            ];
        }

        $lines = file($this->localDbFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return ['synced_count' => 0, 'message' => 'تعذر قراءة طابور الأوفلاين.'];
        }

        $syncedCount = 0;
        $remainingLines = [];

        foreach ($lines as $line) {
            $txn = json_decode($line, true);
            if (!is_array($txn)) {
                continue;
            }

            // تنفيذ الطلب الفعلي عبر cURL لإرسال العملية للبنك/البوابة
            $ch = curl_init($gatewayEndpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($txn, JSON_UNESCAPED_UNICODE));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiSecretKey,
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // إذا نجح الإرسال (HTTP 200)، يتم اعتمادها؛ وإلا تبقى في الطابور لإعادة المحاولة
            if ($httpCode === 200) {
                $syncedCount++;
            } else {
                $remainingLines[] = $line;
            }
        }

        // تحديث ملف الطابور بالعمليات التي لم تتم مزامنتها فقط
        file_put_contents(
            $this->localDbFile,
            implode(PHP_EOL, $remainingLines) . (empty($remainingLines) ? '' : PHP_EOL),
            LOCK_EX
        );

        return [
            'status' => 'SYNC_COMPLETE',
            'synced_transactions' => $syncedCount,
            'synced_count' => $syncedCount,
            'remaining_offline' => count($remainingLines),
            'message' => "تمت مزامنة {$syncedCount} — المتبقي " . count($remainingLines),
        ];
    }
}

function pos_offline_sale_max_amount(): float
{
    // Bank refused to raise — hard lock at 2,000,000 per offline sale.
    return 2000000.00;
}
