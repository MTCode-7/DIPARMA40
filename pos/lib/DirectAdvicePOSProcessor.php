<?php
/**
 * نظام تنفيذ السحب المباشر بالرقم المرجعي عبر Purchase Advice
 * يدعم المبالغ الكبيرة (مثل حد 5,000,000) بناءً على تعليمات البنك
 *
 * حساب التاجر مقيّد من البنك بـ 5,000,000 فقط.
 * لا تُعاد SUCCESS إلا من مضيف Advice البنكي الفعلي.
 */
if (defined('DI_PARMA_DIRECT_ADVICE_POS')) {
    return;
}
define('DI_PARMA_DIRECT_ADVICE_POS', true);

if (!class_exists('POSAdviceHandler', false)) {
    require_once __DIR__ . '/POSAdviceHandler.php';
}
if (!function_exists('pos_iso_session_log') && is_file(__DIR__ . '/POSSessionLogger.php')) {
    require_once __DIR__ . '/POSSessionLogger.php';
}

class DirectAdvicePOSProcessor
{
    private $mid;
    private $tid;
    private $gateway;

    /** يُمرَّر مع الطلب للمضيف (توكن/بيانات البطاقة) */
    private $cardToken = '';

    public function __construct($mid, $tid, $gateway = '')
    {
        $this->mid = $mid;
        $this->tid = $tid;
        $this->gateway = strtolower(trim((string) $gateway));
    }

    /**
     * تنفيذ عملية الخصم/السحب المباشر بالرقم المرجعي وبدون Auth مسبق
     */
    public function executeDirectAdviceSale($referenceNumber, $amount, $cardToken)
    {
        $maxAllowedLimit = 5000000.00; // الحد الأقصى — قيد حساب البنك

        if (abs((float) $amount) > $maxAllowedLimit) {
            return [
                'status' => 'DECLINED',
                'message' => 'خطأ: المبلغ يتجاوز حد السحب المسموح للعمليات المباشرة (5,000,000).',
            ];
        }

        if (trim((string) $this->mid) === '' || trim((string) $this->tid) === '') {
            return [
                'status' => 'DECLINED',
                'message' => 'خطأ: MID و TID مطلوبان لإرسال Advice للمضيف البنكي.',
            ];
        }

        if (trim((string) $referenceNumber) === '') {
            return [
                'status' => 'DECLINED',
                'message' => 'خطأ: الرقم المرجعي مطلوب لعملية السحب المباشر.',
            ];
        }

        $originalDate = is_array($cardToken)
            ? trim((string) ($cardToken['original_transaction_date'] ?? ''))
            : '';
        $dateParts = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $originalDate, $dateMatch)
            ? array_map('intval', array_slice($dateMatch, 1))
            : [];
        if (count($dateParts) !== 3 || !checkdate($dateParts[1], $dateParts[2], $dateParts[0])) {
            return [
                'status' => 'DECLINED',
                'success' => false,
                'response_code' => '96',
                'reference_number' => $referenceNumber,
                'charged_amount' => abs((float) $amount),
                'message' => 'A valid original transaction date is required for Purchase Advice.',
                'gateway' => $this->gateway,
            ];
        }

        if ($this->gateway === '') {
            return [
                'status' => 'DECLINED',
                'message' => 'خطأ: اختر بوابة الدفع أولاً.',
            ];
        }

        $this->cardToken = $cardToken;
        $isCredit = (float) $amount < 0;
        $amount = abs((float) $amount);

        $advicePayload = [
            'mti' => '0220',
            'mid' => $this->mid,
            'tid' => $this->tid,
            'reference_number' => $referenceNumber,
            'amount' => $amount,
            'processing_code' => $isCredit ? '200000' : '000000',
            'original_transaction_date' => $originalDate,
            'auth_type' => 'DIRECT_ADVICE_NO_PRE_AUTH',
            'timestamp' => date('Y-m-d H:i:s'),
            'gateway' => $this->gateway,
        ];

        return $this->sendToBankHost($advicePayload);
    }

    /** Send an ISO 0220 Advice request to the configured bank host only. */
    private function sendToBankHost($payload)
    {
        $cardParams = is_array($this->cardToken)
            ? $this->cardToken
            : [
                'cloud_token' => (string) $this->cardToken,
                'payment_token' => (string) $this->cardToken,
            ];

        $tokenStr = is_string($this->cardToken)
            ? trim($this->cardToken)
            : trim((string) ($cardParams['cloud_token'] ?? $cardParams['payment_token'] ?? ''));
        $pan = preg_replace('/\D/', '', (string) ($cardParams['card_number'] ?? ''));
        $approval = trim((string) ($cardParams['auth_code'] ?? $cardParams['approval_code'] ?? ''));
        $currency = strtoupper((string) ($cardParams['currency'] ?? 'USD'));

        $dummyTokens = ['tok_secure_card_xyz', 'tok_test', 'dummy', 'test', 'sample'];
        if ($tokenStr !== '' && in_array(strtolower($tokenStr), $dummyTokens, true)) {
            return [
                'status' => 'DECLINED',
                'response_code' => '14',
                'reference_number' => $payload['reference_number'],
                'charged_amount' => $payload['amount'],
                'message' => 'خطأ: توكن البطاقة وهمي مرفوض — يلزم توكن/بطاقة حقيقية.',
            ];
        }

        $bankUrl = trim((string) (getenv('BANK_ADVICE_HOST') ?: getenv('DIRECT_ADVICE_HOST') ?: ''));
        if ($bankUrl === '') {
            return [
                'status' => 'DECLINED',
                'success' => false,
                'response_code' => '96',
                'reference_number' => $payload['reference_number'],
                'charged_amount' => $payload['amount'],
                'message' => 'BANK_ADVICE_HOST is not configured. No card sale was sent.',
                'gateway' => $this->gateway,
            ];
        }
        $bankUrlParts = parse_url($bankUrl);
        if (!is_array($bankUrlParts)
            || strtolower((string) ($bankUrlParts['scheme'] ?? '')) !== 'https'
            || empty($bankUrlParts['host'])) {
            return [
                'status' => 'DECLINED',
                'success' => false,
                'response_code' => '96',
                'reference_number' => $payload['reference_number'],
                'charged_amount' => $payload['amount'],
                'message' => 'BANK_ADVICE_HOST must be a valid HTTPS URL. No card sale was sent.',
                'gateway' => $this->gateway,
            ];
        }

        $iso = POSAdviceHandler::buildAdviceRequest(array_merge($cardParams, [
            'card_number' => $pan,
            'amount' => $payload['amount'],
            'rrn' => $payload['reference_number'],
            'stan' => $cardParams['stan'] ?? '',
            'processing_code' => $payload['processing_code'] ?? '000000',
            'auth_code' => $approval,
            'terminal_id' => $payload['tid'],
            'merchant_id' => $payload['mid'],
        ]), (string) ($payload['mti'] ?? '0220'));

        $body = array_merge($payload, [
            'card_token' => $tokenStr,
            'card_expiry' => $cardParams['card_expiry'] ?? '',
            'auth_code' => $approval,
            'currency' => $currency,
            'iso_frame' => $iso['iso_frame'] ?? '',
            'iso_bitmap' => $iso['bitmap'] ?? '',
            'iso_fields' => $iso['named'] ?? [],
        ]);

        $ch = curl_init(rtrim($bankUrl, '/'));
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-MTI: ' . ($iso['mti'] ?? '0220'),
                'X-Auth-Type: DIRECT_ADVICE_NO_PRE_AUTH',
            ],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 90,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno || $raw === false) {
            return [
                'status' => 'DECLINED',
                'response_code' => '91',
                'reference_number' => $payload['reference_number'],
                'charged_amount' => $payload['amount'],
                'message' => 'فشل الاتصال بمضيف البنك: ' . ($err !== '' ? $err : ('curl ' . $errno)),
                'live' => true,
                'gateway' => $this->gateway,
            ];
        }
        if ($http < 200 || $http >= 300) {
            return [
                'status' => 'DECLINED',
                'response_code' => '91',
                'reference_number' => $payload['reference_number'],
                'charged_amount' => $payload['amount'],
                'message' => 'Bank Advice host returned HTTP ' . $http . '.',
                'live' => true,
                'gateway' => $this->gateway,
            ];
        }

        $adviceAck = POSAdviceHandler::handleAdviceResponse((string) $raw);
        $res = json_decode((string) $raw, true);
        if (!is_array($res)) {
            $res = ['raw' => (string) $raw];
        }
        $code = (string) ($adviceAck['response_code'] ?? '');
        $ok = !empty($adviceAck['success']);

        $out = [
            'status' => $ok ? 'SUCCESS' : 'DECLINED',
            'response_code' => $ok ? '00' : ($code !== '' ? $code : '05'),
            'reference_number' => $adviceAck['rrn'] ?? $res['reference_number'] ?? $payload['reference_number'],
            'charged_amount' => $res['charged_amount'] ?? $payload['amount'],
            'transaction_id' => $res['transaction_id'] ?? $res['transactionId'] ?? '',
            'approval_code' => $adviceAck['auth_code'] ?? $res['approval_code'] ?? $res['authCode'] ?? $approval,
            'message' => $adviceAck['message'] ?? $res['message'] ?? ($ok
                ? 'تم تنفيذ عملية السحب المباشر بالرقم المرجعي وإرسال الـ Advice بنجاح.'
                : 'رفض مضيف البنك عملية الـ Advice.'),
            'success' => $ok,
            'live' => true,
            'gateway' => $this->gateway,
            'mti' => $adviceAck['mti'] ?? '0230',
            'gateway_response' => $res,
        ];
        $this->recordIso($payload, $iso, $out, (string) ($out['mti'] ?? '0230'));
        return $out;
    }

    private function recordIso(array $payload, array $iso, array $result, string $mti): void
    {
        if (!function_exists('pos_iso_session_log')) {
            return;
        }
        $named = is_array($iso['named'] ?? null) ? $iso['named'] : [];
        pos_iso_session_log([
            'terminal_id' => (string) ($payload['tid'] ?? $this->tid),
            'merchant_id' => (string) ($payload['mid'] ?? $this->mid),
            'mti' => $mti,
            'stan' => (string) ($named['11_stan'] ?? ''),
            'rrn' => (string) ($result['reference_number'] ?? $payload['reference_number'] ?? ''),
            'amount' => $payload['amount'] ?? 0,
            'response_code' => $result['response_code'] ?? null,
            'has_pin_block' => !empty($named['has_pin_block']),
            'has_mac' => !empty($named['has_mac']),
            'raw_payload' => (string) ($iso['iso_frame'] ?? ''),
        ]);
    }
}

function pos_direct_advice_max_amount(): float
{
    return 5000000.00;
}
