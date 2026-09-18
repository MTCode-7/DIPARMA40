<?php
/**
 * ISO 8583 Advice for DI PARMA POS.
 *
 * 0220 / 0230 — Financial Transaction Advice / Response (stand-in, post-delivery, direct advice)
 * 0120 / 0130 — Authorization Advice / Response
 *
 * SUCCESS only from a 0230/0130 with DE 39 = 00. No local simulation.
 */
if (defined('DI_PARMA_POS_ADVICE_HANDLER')) {
    return;
}
define('DI_PARMA_POS_ADVICE_HANDLER', true);

if (!class_exists('ISO8583AdviceProcessor', false)) {
    require_once __DIR__ . '/ISO8583AdviceProcessor.php';
}
if (!class_exists('ISO8583SecurityHandler', false)) {
    require_once __DIR__ . '/ISO8583SecurityHandler.php';
}

class POSAdviceHandler
{
    public const MTI_FINANCIAL_ADVICE = '0220';
    public const MTI_FINANCIAL_ADVICE_RESPONSE = '0230';
    public const MTI_AUTH_ADVICE = '0120';
    public const MTI_AUTH_ADVICE_RESPONSE = '0130';

    /**
     * Build Advice request (MTI 0220 or 0120) from live POS data.
     *
     * @return array{mti:string,bitmap:string,fields:array<string,string>,json:string}
     */
    public static function buildAdviceRequest(array $transactionData, string $mti = self::MTI_FINANCIAL_ADVICE): array
    {
        $mti = self::normalizeMti($mti, true);
        $pan = preg_replace('/\D/', '', (string) ($transactionData['card_number'] ?? $transactionData['pan'] ?? '')) ?? '';
        $rrn = self::padRrn((string) ($transactionData['rrn'] ?? $transactionData['reference'] ?? $transactionData['reference_number'] ?? ''));
        $stan = self::padStan((string) ($transactionData['stan'] ?? ''));
        $amount = self::padAmount((float) ($transactionData['amount'] ?? 0));
        $proc = str_pad(substr(preg_replace('/\D/', '', (string) ($transactionData['processing_code'] ?? '000000')) ?? '000000', 0, 6), 6, '0');
        $now = date('mdHis');
        $auth = strtoupper(trim((string) ($transactionData['auth_code'] ?? $transactionData['approval_code'] ?? '')));
        $tid = strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', (string) ($transactionData['terminal_id'] ?? $transactionData['tid'] ?? '')) ?? '');
        $mid = strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', (string) ($transactionData['merchant_id'] ?? $transactionData['mid'] ?? '')) ?? '');

        $fields = [];
        if (strlen($pan) >= 13) {
            $fields['2'] = $pan;
        }
        $fields['3'] = $proc;
        $fields['4'] = $amount;
        $fields['11'] = $stan;
        $fields['12'] = substr($now, 4, 6);
        $fields['13'] = substr($now, 0, 4);
        if ($rrn !== '') {
            $fields['37'] = $rrn;
        }
        if ($auth !== '') {
            $fields['38'] = substr($auth, 0, 6);
        }
        if ($tid !== '') {
            $fields['41'] = str_pad(substr($tid, 0, 8), 8, ' ', STR_PAD_RIGHT);
        }
        if ($mid !== '') {
            $fields['42'] = str_pad(substr($mid, 0, 15), 15, ' ', STR_PAD_RIGHT);
        }

        $pin = preg_replace('/\D/', '', (string) ($transactionData['pin'] ?? $transactionData['card_pin'] ?? '')) ?? '';
        if ($pin !== '' && strlen($pan) >= 13) {
            $pinBlock = ISO8583SecurityHandler::generatePinBlock($pin, $pan);
            if ($pinBlock !== '') {
                $fields['52'] = $pinBlock;
            }
        }

        $isoFields = [];
        foreach ($fields as $bit => $val) {
            $isoFields[(int) $bit] = $val;
        }
        $rawFrame = ISO8583AdviceProcessor::buildISOFrame($mti, $isoFields);
        $macKey = ISO8583SecurityHandler::macKey();
        if ($macKey !== '') {
            $mac = ISO8583SecurityHandler::calculateMAC($rawFrame, $macKey);
            if ($mac !== '') {
                $isoFields[64] = $mac;
                $rawFrame = ISO8583AdviceProcessor::buildISOFrame($mti, $isoFields);
                $fields['64'] = $mac;
            }
        }
        $parsed = ISO8583AdviceProcessor::parseISOFrame($rawFrame);
        $bitmap = (string) ($parsed['bitmap_hex'] ?? '');
        $named = [
            'mti' => $mti,
            'bitmap' => $bitmap,
            '2_pan' => isset($fields['2']) ? self::maskPan($fields['2']) : '',
            '3_proc_code' => $fields['3'],
            '4_amount' => $fields['4'],
            '11_stan' => $fields['11'],
            '12_time' => $fields['12'],
            '13_date' => $fields['13'],
            '37_rrn' => $fields['37'] ?? '',
            '38_auth_code' => $fields['38'] ?? '',
            '41_terminal_id' => trim($fields['41'] ?? ''),
            '42_merchant_id' => trim($fields['42'] ?? ''),
            'has_pin_block' => isset($fields['52']),
            'has_mac' => isset($fields['64']),
        ];

        return [
            'mti' => $mti,
            'bitmap' => $bitmap,
            'fields' => $fields,
            'named' => $named,
            'iso_frame' => $rawFrame,
            'json' => json_encode($named, JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * Parse Advice response (MTI 0230 or 0130). DE 39 = 00 is the only success.
     *
     * @return array{success:bool,response_code:string,auth_code:?string,rrn:?string,mti:string,message:string,stan?:string}
     */
    public static function handleAdviceResponse(string $rawResponse): array
    {
        $rawResponse = trim($rawResponse);
        $response = json_decode($rawResponse, true);
        if (!is_array($response) && preg_match('/^(0120|0130|0220|0230)/', $rawResponse)) {
            $parsed = ISO8583AdviceProcessor::parseISOFrame($rawResponse);
            $response = [
                'mti' => $parsed['mti'] ?? '',
                '39' => $parsed[39] ?? '',
                '38' => $parsed[38] ?? '',
                '37' => $parsed[37] ?? '',
                '11' => $parsed[11] ?? '',
            ];
        }
        if (!is_array($response)) {
            return [
                'success' => false,
                'response_code' => '96',
                'auth_code' => null,
                'rrn' => null,
                'mti' => '',
                'message' => 'Invalid ISO 8583 Advice response',
            ];
        }

        $mti = self::normalizeMti((string) ($response['mti'] ?? $response['MTI'] ?? ''), false);
        if ($mti === '0000' || $mti === '') {
            $mti = self::MTI_FINANCIAL_ADVICE_RESPONSE;
        }
        $code = (string) (
            $response['39']
            ?? $response['39_response_code']
            ?? $response['DE39']
            ?? $response['response_code']
            ?? $response['ResponseCode']
            ?? ''
        );
        $code = str_pad(substr($code, 0, 2), 2, '0', STR_PAD_LEFT);

        if ($mti !== self::MTI_FINANCIAL_ADVICE_RESPONSE && $mti !== self::MTI_AUTH_ADVICE_RESPONSE) {
            return [
                'success' => false,
                'response_code' => $code !== '00' ? $code : '99',
                'auth_code' => $response['38'] ?? $response['38_auth_code'] ?? $response['auth_code'] ?? null,
                'rrn' => $response['37'] ?? $response['37_rrn'] ?? $response['rrn'] ?? null,
                'mti' => $mti,
                'message' => 'Unexpected MTI (expected 0230 or 0130): ' . ($mti !== '' ? $mti : 'missing'),
            ];
        }

        $ok = ($code === '00');
        return [
            'success' => $ok,
            'response_code' => $code,
            'auth_code' => $response['38'] ?? $response['38_auth_code'] ?? $response['auth_code'] ?? $response['approval_code'] ?? null,
            'rrn' => $response['37'] ?? $response['37_rrn'] ?? $response['rrn'] ?? null,
            'stan' => $response['11'] ?? $response['11_stan'] ?? $response['stan'] ?? null,
            'mti' => $mti,
            'message' => $ok
                ? 'Advice acknowledged (DE 39 = 00).'
                : 'Advice rejected by host, DE 39 = ' . $code,
        ];
    }

    public static function maskPan(string $pan): string
    {
        $pan = preg_replace('/\D/', '', $pan) ?? '';
        $len = strlen($pan);
        if ($len < 10) {
            return '';
        }
        return substr($pan, 0, 6) . str_repeat('*', $len - 10) . substr($pan, -4);
    }

    private static function normalizeMti(string $mti, bool $request): string
    {
        $mti = preg_replace('/\D/', '', $mti) ?? '';
        $mti = str_pad(substr($mti, 0, 4), 4, '0', STR_PAD_LEFT);
        if ($request) {
            return in_array($mti, [self::MTI_AUTH_ADVICE, self::MTI_FINANCIAL_ADVICE], true)
                ? $mti
                : self::MTI_FINANCIAL_ADVICE;
        }
        return $mti;
    }

    private static function padAmount(float $amount): string
    {
        $cents = (int) round(max(0, $amount) * 100);
        return str_pad((string) $cents, 12, '0', STR_PAD_LEFT);
    }

    private static function padStan(string $stan): string
    {
        $digits = preg_replace('/\D/', '', $stan) ?? '';
        if ($digits === '') {
            $digits = (string) (int) (microtime(true) * 1000000);
        }
        return str_pad(substr($digits, -6), 6, '0', STR_PAD_LEFT);
    }

    private static function padRrn(string $rrn): string
    {
        $rrn = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $rrn) ?? '');
        if ($rrn === '') {
            return '';
        }
        return str_pad(substr($rrn, -12), 12, '0', STR_PAD_LEFT);
    }
}
