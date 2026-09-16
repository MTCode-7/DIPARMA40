<?php
/**
 * نظام تنفيذ السحب المباشر بالرقم المرجعي عبر Purchase Advice
 * يدعم المبالغ الكبيرة (مثل حد 5,000,000) بناءً على تعليمات البنك
 *
 * حساب التاجر مقيّد من البنك بـ 5,000,000 فقط.
 * البوابة = اختيار المستخدم (ليست ثابتة على Nuvei).
 * ليس وهمياً: لا تُعاد SUCCESS إلا من المضيف/البوابة المختارة.
 */
if (defined('DI_PARMA_DIRECT_ADVICE_POS')) {
    return;
}
define('DI_PARMA_DIRECT_ADVICE_POS', true);

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

        if ($amount > $maxAllowedLimit) {
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

        if ($this->gateway === '') {
            return [
                'status' => 'DECLINED',
                'message' => 'خطأ: اختر بوابة الدفع أولاً.',
            ];
        }

        $this->cardToken = $cardToken;

        $advicePayload = [
            'mti' => '0220',
            'mid' => $this->mid,
            'tid' => $this->tid,
            'reference_number' => $referenceNumber,
            'amount' => $amount,
            'processing_code' => '000000',
            'auth_type' => 'DIRECT_ADVICE_NO_PRE_AUTH',
            'timestamp' => date('Y-m-d H:i:s'),
            'gateway' => $this->gateway,
        ];

        return $this->sendToBankHost($advicePayload);
    }

    /**
     * إرسال Advice — BANK_ADVICE_HOST إن وُجد، وإلا البوابة المختارة.
     * SUCCESS فقط من رد المضيف/البوابة (ليس محلياً).
     */
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
        if ($bankUrl !== '') {
            $body = array_merge($payload, [
                'card_token' => $tokenStr,
                'card_number' => $pan,
                'card_expiry' => $cardParams['card_expiry'] ?? '',
                'auth_code' => $approval,
                'currency' => $currency,
            ]);

            $ch = curl_init(rtrim($bankUrl, '/'));
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'X-MTI: 0220',
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

            $res = json_decode((string) $raw, true);
            if (!is_array($res)) {
                return [
                    'status' => 'DECLINED',
                    'response_code' => '96',
                    'reference_number' => $payload['reference_number'],
                    'charged_amount' => $payload['amount'],
                    'message' => 'استجابة مضيف البنك غير صالحة (HTTP ' . $http . ').',
                    'live' => true,
                    'gateway' => $this->gateway,
                ];
            }

            $code = (string) ($res['response_code'] ?? $res['ResponseCode'] ?? $res['code'] ?? '');
            $ok = ($code === '00');

            return [
                'status' => $ok ? 'SUCCESS' : 'DECLINED',
                'response_code' => $code !== '' ? $code : '05',
                'reference_number' => $res['reference_number'] ?? $payload['reference_number'],
                'charged_amount' => $res['charged_amount'] ?? $payload['amount'],
                'transaction_id' => $res['transaction_id'] ?? $res['transactionId'] ?? '',
                'approval_code' => $res['approval_code'] ?? $res['authCode'] ?? $approval,
                'message' => $res['message'] ?? ($ok
                    ? 'تم تنفيذ عملية السحب المباشر بالرقم المرجعي وإرسال الـ Advice بنجاح.'
                    : 'رفض مضيف البنك عملية الـ Advice.'),
                'success' => $ok,
                'live' => true,
                'gateway' => $this->gateway,
                'gateway_response' => $res,
            ];
        }

        if ($approval === '' && $tokenStr === '' && strlen($pan) < 13) {
            return [
                'status' => 'DECLINED',
                'response_code' => '14',
                'reference_number' => $payload['reference_number'],
                'charged_amount' => $payload['amount'],
                'message' => 'خطأ: يلزم بطاقة/توكن حقيقي ورمز موافقة البنك لإرسال Advice.',
                'gateway' => $this->gateway,
            ];
        }

        $params = array_merge($cardParams, [
            'amount' => $payload['amount'],
            'currency' => $currency,
            'merchant_id' => $payload['mid'],
            'terminal_id' => $payload['tid'],
            'reference' => $payload['reference_number'],
            'client_unique_id' => $payload['reference_number'],
            'rrn' => $payload['reference_number'],
            'orig_ref' => $payload['reference_number'],
            'auth_code' => $approval,
            'approval_code' => $approval,
            'card_cvv' => '',
            'is_moto' => false,
            'txn_type' => 'purchase_advice',
            'mti' => $payload['mti'],
            'processing_code' => $payload['processing_code'],
            'auth_type' => $payload['auth_type'],
            'direct_advice' => true,
            'gateway' => $this->gateway,
        ]);

        if (!function_exists('pos_dispatch_direct_advice_to_gateway')) {
            return [
                'status' => 'DECLINED',
                'response_code' => '96',
                'reference_number' => $payload['reference_number'],
                'charged_amount' => $payload['amount'],
                'message' => 'خطأ: مُرسل البوابة غير جاهز.',
                'gateway' => $this->gateway,
            ];
        }

        $gw = pos_dispatch_direct_advice_to_gateway($this->gateway, $params);
        $hostTxn = trim((string) ($gw['transaction_id'] ?? $gw['payment_id'] ?? ''));
        $ok = !empty($gw['success']) && $hostTxn !== '';

        return [
            'status' => $ok ? 'SUCCESS' : 'DECLINED',
            'response_code' => $ok ? '00' : (string) ($gw['response_code'] ?? $gw['decline_code'] ?? '05'),
            'reference_number' => $gw['reference'] ?? $payload['reference_number'],
            'charged_amount' => $gw['amount'] ?? $payload['amount'],
            'transaction_id' => $hostTxn,
            'approval_code' => $gw['approval_code'] ?? $approval,
            'message' => $gw['message'] ?? ($ok
                ? 'تم تنفيذ عملية السحب المباشر بالرقم المرجعي وإرسال الـ Advice بنجاح.'
                : 'رفضت البوابة المختارة عملية السحب المباشر بالرقم المرجعي.'),
            'success' => $ok,
            'live' => true,
            'gateway' => $this->gateway,
            'gateway_response' => $gw,
        ];
    }
}

function pos_direct_advice_max_amount(): float
{
    return 5000000.00;
}
