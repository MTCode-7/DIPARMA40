<?php
/**
 * DIPARMA ISO 8583:1987 path (route diparma_iso).
 *
 * Builds a packed message and a PCI-safe record for this processor only.
 * PayPal, Stripe, Square, and Nuvei never enter this class.
 * There is no ISO socket here, so a response code is never invented.
 */
if (defined('DI_PARMA_ISO_PROCESSOR')) {
    return;
}
define('DI_PARMA_ISO_PROCESSOR', true);

$dpIsoPacker = dirname(__DIR__, 2) . '/pos/lib/ISO8583AdviceProcessor.php';
if (!class_exists('ISO8583AdviceProcessor', false) && is_file($dpIsoPacker)) {
    require_once $dpIsoPacker;
}
$dpIsoPci = dirname(__DIR__, 2) . '/includes/pci.php';
if (!function_exists('dp_pci_boot') && is_file($dpIsoPci)) {
    require_once $dpIsoPci;
}
if (function_exists('dp_pci_boot')) {
    dp_pci_boot();
}

class DiparmaIsoProcessor
{
    public const ROUTE = 'diparma_iso';

    /** @var array<string,string> */
    private const CURRENCY_NUMERIC = [
        'USD' => '840',
        'AED' => '784',
        'EUR' => '978',
        'GBP' => '826',
        'SAR' => '682',
        'KWD' => '414',
        'QAR' => '634',
        'EGP' => '818',
        'CAD' => '124',
        'AUD' => '036',
        'JPY' => '392',
        'BHD' => '048',
        'OMR' => '512',
    ];

    /** @var array<string,int> */
    private const CURRENCY_EXPONENT = [
        'JPY' => 0,
        'KWD' => 3,
        'BHD' => 3,
        'OMR' => 3,
    ];

    public static function isRoute(string $gateway): bool
    {
        $g = strtolower(trim($gateway));
        return $g === self::ROUTE || $g === 'diparma-iso';
    }

    /**
     * In-memory build. The packed string contains DE2 when a PAN was supplied.
     * Callers that store or return a result must use handle() or safeRecord().
     *
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    public static function buildIso8583(string $txnType, array $fields): array
    {
        $spec = self::spec($txnType, $fields);
        if (isset($spec['error'])) {
            return $spec['error'];
        }

        $iso = self::assignFields($spec, $fields);
        if (!class_exists('ISO8583AdviceProcessor', false)) {
            return self::fail('ISO 8583 packer is not available');
        }

        $packed = ISO8583AdviceProcessor::buildISOFrame($spec['mti'], $iso);
        $parsed = ISO8583AdviceProcessor::parseISOFrame($packed);
        $bitmap = (string) ($parsed['bitmap_hex'] ?? '');

        return [
            'route' => self::ROUTE,
            'txn_type' => $spec['txn_type'],
            'mti' => $spec['mti'],
            'mti_role' => $spec['role'],
            'bitmap' => $bitmap,
            'secondary_bitmap' => strlen($bitmap) > 16,
            'fields' => self::maskedFields($iso),
            'packed' => $packed,
            'record' => self::safeRecord($spec['mti'], $iso, null),
        ];
    }

    /**
     * Orchestrator entry. Returns the built message without PAN, CVV, track, or the raw frame.
     *
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    public static function handle(string $txnType, array $fields): array
    {
        $built = self::buildIso8583($txnType, $fields);
        if (empty($built['mti'])) {
            return self::scrub($built);
        }

        $record = is_array($built['record'] ?? null) ? $built['record'] : [];
        $public = [
            'success' => false,
            'approved' => false,
            'route' => self::ROUTE,
            'processor' => self::ROUTE,
            'txn_type' => (string) ($built['txn_type'] ?? ''),
            'mti' => (string) $built['mti'],
            'mti_role' => (string) ($built['mti_role'] ?? ''),
            'bitmap' => (string) ($built['bitmap'] ?? ''),
            'secondary_bitmap' => !empty($built['secondary_bitmap']),
            'fields' => is_array($built['fields'] ?? null) ? $built['fields'] : [],
            'wire_length' => strlen((string) ($built['packed'] ?? '')),
            'message' => 'ISO 8583 message built on diparma_iso. No acquirer transport, so DE39 is empty.',
            'response_code' => null,
            'rrn' => $record['rrn'] ?? null,
            'approval_code' => $record['approval_code'] ?? null,
            'card_last4' => $record['card_last4'] ?? null,
            'card_mask' => $record['card_mask'] ?? null,
            'record' => $record,
        ];
        unset($built);

        return self::scrub($public);
    }

    /**
     * @return array<string,mixed>
     */
    public static function parseResponse(string $rawFrame): array
    {
        if (!class_exists('ISO8583AdviceProcessor', false)) {
            return self::fail('ISO 8583 packer is not available');
        }
        $parsed = ISO8583AdviceProcessor::parseISOFrame($rawFrame);
        if (!empty($parsed['error'])) {
            return [
                'success' => false,
                'approved' => false,
                'mti' => '',
                'response_code' => null,
                'rrn' => null,
                'approval_code' => null,
                'message' => (string) $parsed['error'],
            ];
        }

        $mti = (string) ($parsed['mti'] ?? '');
        $accepted = ['0110', '0210', '0230', '0410', '0430'];
        $code = isset($parsed[39]) ? trim((string) $parsed[39]) : '';
        $rrn = isset($parsed[37]) ? trim((string) $parsed[37]) : '';
        $approval = isset($parsed[38]) ? trim((string) $parsed[38]) : '';
        $known = in_array($mti, $accepted, true);
        $approved = $known && $code === '00';

        $out = [
            'success' => $approved,
            'approved' => $approved,
            'mti' => $mti,
            'mti_accepted' => $known,
            'response_code' => $code !== '' ? $code : null,
            'rrn' => $rrn !== '' ? $rrn : null,
            'approval_code' => $approval !== '' ? $approval : null,
            'card_mask' => null,
            'card_last4' => null,
            'message' => $known
                ? ($code !== '' ? 'DE39 ' . $code : 'Response has no DE39')
                : 'Unexpected response MTI',
        ];
        if (!empty($parsed['2_pan_masked'])) {
            $digits = preg_replace('/\D/', '', (string) $parsed['2_pan_masked']) ?? '';
            if (strlen($digits) >= 4) {
                $out['card_last4'] = substr($digits, -4);
                $out['card_mask'] = '****' . $out['card_last4'];
            }
        }

        return self::scrub($out);
    }

    /**
     * @param array<string,string> $iso
     * @return array<string,mixed>
     */
    public static function safeRecord(string $mti, array $iso, ?string $responseCode): array
    {
        $last4 = '';
        if (!empty($iso[2])) {
            $digits = preg_replace('/\D/', '', (string) $iso[2]) ?? '';
            if (strlen($digits) >= 4) {
                $last4 = substr($digits, -4);
            }
        }
        $rrn = trim((string) ($iso[37] ?? ''));
        $approval = trim((string) ($iso[38] ?? ''));

        return [
            'card_mask' => $last4 !== '' ? '****' . $last4 : null,
            'card_last4' => $last4 !== '' ? $last4 : null,
            'rrn' => $rrn !== '' ? $rrn : null,
            'approval_code' => $approval !== '' ? $approval : null,
            'mti' => $mti,
            'response_code' => $responseCode !== null && $responseCode !== '' ? $responseCode : null,
        ];
    }

    /**
     * @param array<int|string,string> $iso
     * @return array<string,string>
     */
    private static function maskedFields(array $iso): array
    {
        $out = [];
        foreach ($iso as $bit => $value) {
            $bit = (int) $bit;
            if ($bit < 2) {
                continue;
            }
            $text = (string) $value;
            if ($bit === 2) {
                $digits = preg_replace('/\D/', '', $text) ?? '';
                $text = strlen($digits) >= 4 ? '****' . substr($digits, -4) : '';
            }
            if ($text === '') {
                continue;
            }
            $out[(string) $bit] = $text;
        }
        ksort($out, SORT_NUMERIC);
        return $out;
    }

    /**
     * @param array<string,mixed> $fields
     * @return array{mti:string,role:string,txn_type:string,processing:string,entry:string,condition:string,amount:float}|array{error:array<string,mixed>}
     */
    private static function spec(string $txnType, array $fields): array
    {
        $type = strtolower(trim($txnType !== '' ? $txnType : (string) ($fields['txn_type'] ?? '')));
        $amount = self::amountOf($fields);
        $entry = self::entryMode($type, $fields);

        $auth = ['auth', 'hold', 'authorize', 'auth_hold', 'auth_moto'];
        $purchase = ['purchase', 'purchase_2d', 'purchase_3d', 'moto', 'purchase_online', 'online_sale_moto'];
        $offline = ['offline', 'offline_sale', 'offline_sale_moto', 'purchase_offline'];
        $capture = ['capture', 'auth_complete', 'auth_capture'];
        $reversal = ['void', 'avoid', 'reversal', 'cancel'];

        if (in_array($type, $auth, true)) {
            if ($amount <= 0) {
                return ['error' => self::fail('Auth amount must be greater than zero')];
            }
            return self::row($type, '0100', 'authorization', '000000', $entry, $amount);
        }
        if (in_array($type, $purchase, true)) {
            if ($amount <= 0) {
                return ['error' => self::fail('Purchase amount must be greater than zero')];
            }
            return self::row($type, '0200', 'purchase', '000000', $entry, $amount);
        }
        if (in_array($type, $offline, true)) {
            if ($amount <= 0) {
                return ['error' => self::fail('Offline sale amount must be greater than zero')];
            }
            return self::row($type, '0220', 'offline', '000000', $entry, $amount);
        }
        if ($type === 'purchase_advice') {
            if ($amount == 0.0) {
                return ['error' => self::fail('Purchase advice amount must not be zero')];
            }
            $processing = $amount < 0 ? '200000' : '000000';
            $role = $amount < 0 ? 'advice_credit' : 'advice_debit';
            return self::row($type, '0220', $role, $processing, $entry, $amount);
        }
        if (in_array($type, $capture, true)) {
            if ($amount <= 0) {
                return ['error' => self::fail('Capture amount must be greater than zero')];
            }
            return self::row($type, '0220', 'completion', '000000', $entry, $amount);
        }
        if ($type === 'refund') {
            if ($amount <= 0) {
                return ['error' => self::fail('Refund amount must be greater than zero')];
            }
            return self::row($type, '0200', 'refund', '200000', $entry, $amount);
        }
        if (in_array($type, $reversal, true)) {
            $processing = self::processingOverride($fields) ?? '000000';
            return self::row($type, '0400', 'reversal', $processing, $entry, $amount);
        }

        return ['error' => self::fail('Unsupported diparma_iso transaction type')];
    }

    /**
     * @param array{de22:string,de25:string} $entry
     * @return array{mti:string,role:string,txn_type:string,processing:string,entry:string,condition:string,amount:float}
     */
    private static function row(string $type, string $mti, string $role, string $processing, array $entry, float $amount): array
    {
        return [
            'txn_type' => $type,
            'mti' => $mti,
            'role' => $role,
            'processing' => $processing,
            'entry' => $entry['de22'],
            'condition' => $entry['de25'],
            'amount' => $amount,
        ];
    }

    /**
     * @param array<string,mixed> $spec
     * @param array<string,mixed> $fields
     * @return array<int,string>
     */
    private static function assignFields(array $spec, array $fields): array
    {
        $iso = [];
        $pan = preg_replace('/\D/', '', (string) ($fields['pan'] ?? $fields['card_number'] ?? $fields['cc_number'] ?? '')) ?? '';
        if (strlen($pan) >= 13 && strlen($pan) <= 19) {
            $iso[2] = $pan;
        }

        $processing = self::processingOverride($fields) ?? (string) $spec['processing'];
        $iso[3] = $processing;

        $currency = strtoupper(trim((string) ($fields['currency'] ?? 'USD')));
        if ($spec['role'] !== 'reversal' || self::amountOf($fields) != 0.0) {
            $iso[4] = self::minorUnits(abs((float) $spec['amount']), $currency);
        }

        $iso[7] = self::transmission($fields);
        $iso[11] = self::stan($fields);

        $expiry = self::expiry($fields);
        if ($expiry !== '') {
            $iso[14] = $expiry;
        }
        $iso[22] = (string) $spec['entry'];
        $iso[25] = (string) $spec['condition'];

        $rrn = self::rrn($fields);
        if ($rrn !== '') {
            $iso[37] = $rrn;
        }
        $approval = self::approval($fields);
        if ($approval !== '') {
            $iso[38] = $approval;
        }

        $tid = self::limitedId((string) ($fields['terminal_id'] ?? $fields['tid'] ?? $fields['pos_id'] ?? ''), 8);
        if ($tid !== '') {
            $iso[41] = $tid;
        }
        $mid = self::limitedId((string) ($fields['merchant_id'] ?? $fields['mid'] ?? ''), 15);
        if ($mid !== '') {
            $iso[42] = $mid;
        }
        if (isset(self::CURRENCY_NUMERIC[$currency])) {
            $iso[49] = self::CURRENCY_NUMERIC[$currency];
        }

        ksort($iso, SORT_NUMERIC);
        return $iso;
    }

    /**
     * @param array<string,mixed> $fields
     * @return array{de22:string,de25:string}
     */
    private static function entryMode(string $txnType, array $fields): array
    {
        $hint = strtolower(trim((string) (
            $fields['entry_mode']
            ?? $fields['pos_entry_mode']
            ?? $fields['channel']
            ?? $fields['auth_channel']
            ?? $fields['moto_channel']
            ?? ''
        )));
        $mode = strtoupper(trim((string) ($fields['processing_mode'] ?? $fields['sec_mode'] ?? '')));
        $moto = !empty($fields['is_moto']) || !empty($fields['moto']) || $mode === '2D' || $mode === 'MOTO';

        $chip = ['chip', 'icc', 'pos_chip', 'pos', 'emv'];
        $contactless = ['contactless', 'nfc', 'nfc_contactless', 'ctl'];
        $ecom = ['ecom', 'ecommerce', 'e-commerce', '3d', 'purchase_3d'];
        $offline = ['offline', 'purchase_offline', 'offline_sale_moto'];
        $manual = ['manual', 'keyed', 'moto', 'purchase_2d'];

        if (in_array($hint, $chip, true)) {
            return ['de22' => '051', 'de25' => '00'];
        }
        if (in_array($hint, $contactless, true)) {
            return ['de22' => '071', 'de25' => '00'];
        }
        if (in_array($hint, $ecom, true) || $txnType === 'purchase_3d' || $mode === '3D') {
            return ['de22' => '812', 'de25' => '59'];
        }
        if (in_array($hint, $offline, true) || in_array($txnType, $offline, true)) {
            return ['de22' => '012', 'de25' => '08'];
        }
        if ($txnType === 'capture') {
            return ['de22' => $moto ? '012' : '051', 'de25' => '06'];
        }
        if (in_array($hint, $manual, true) || $moto || in_array($txnType, ['purchase_2d', 'auth_moto', 'online_sale_moto', 'moto'], true)) {
            return ['de22' => '012', 'de25' => '08'];
        }
        if (in_array($txnType, ['auth', 'hold', 'authorize', 'auth_hold'], true)) {
            return ['de22' => '051', 'de25' => '00'];
        }

        return ['de22' => '012', 'de25' => '00'];
    }

    /**
     * @param array<string,mixed> $fields
     */
    private static function amountOf(array $fields): float
    {
        if (!isset($fields['amount']) || $fields['amount'] === '' || $fields['amount'] === null) {
            return 0.0;
        }
        return (float) $fields['amount'];
    }

    private static function minorUnits(float $absolute, string $currency): string
    {
        $exp = self::CURRENCY_EXPONENT[$currency] ?? 2;
        $minor = (int) round($absolute * (10 ** $exp));
        if ($minor < 0) {
            $minor = 0;
        }
        return str_pad((string) $minor, 12, '0', STR_PAD_LEFT);
    }

    /**
     * @param array<string,mixed> $fields
     */
    private static function transmission(array $fields): string
    {
        $given = preg_replace('/\D/', '', (string) ($fields['transmission'] ?? $fields['de7'] ?? '')) ?? '';
        if (strlen($given) >= 10) {
            return substr($given, 0, 10);
        }
        return gmdate('mdHis');
    }

    /**
     * @param array<string,mixed> $fields
     */
    private static function stan(array $fields): string
    {
        $given = preg_replace('/\D/', '', (string) ($fields['stan'] ?? $fields['de11'] ?? '')) ?? '';
        if ($given !== '') {
            return str_pad(substr($given, -6), 6, '0', STR_PAD_LEFT);
        }
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * @param array<string,mixed> $fields
     */
    private static function expiry(array $fields): string
    {
        $raw = strtoupper(trim((string) ($fields['expiry'] ?? $fields['card_expiry'] ?? $fields['cc_expiry'] ?? $fields['de14'] ?? '')));
        if ($raw === '') {
            return '';
        }
        if (preg_match('/^(\d{2})\s*\/\s*(\d{2,4})$/', $raw, $m)) {
            $year = substr($m[2], -2);
            return $year . $m[1];
        }
        $digits = preg_replace('/\D/', '', $raw) ?? '';
        if (strlen($digits) === 4) {
            $first = (int) substr($digits, 0, 2);
            if ($first >= 1 && $first <= 12) {
                return substr($digits, 2, 2) . substr($digits, 0, 2);
            }
            return $digits;
        }
        return '';
    }

    /**
     * @param array<string,mixed> $fields
     */
    private static function rrn(array $fields): string
    {
        $digits = preg_replace('/\D/', '', (string) ($fields['rrn'] ?? $fields['de37'] ?? $fields['orig_ref'] ?? '')) ?? '';
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) > 12) {
            $digits = substr($digits, -12);
        }
        return str_pad($digits, 12, '0', STR_PAD_LEFT);
    }

    /**
     * @param array<string,mixed> $fields
     */
    private static function approval(array $fields): string
    {
        $code = preg_replace('/\D/', '', (string) ($fields['approval_code'] ?? $fields['auth_code'] ?? $fields['de38'] ?? '')) ?? '';
        if ($code === '') {
            return '';
        }
        $len = strlen($code);
        if ($len !== 4 && $len !== 6) {
            return '';
        }
        return $code;
    }

    /**
     * @param array<string,mixed> $fields
     */
    private static function processingOverride(array $fields): ?string
    {
        $raw = preg_replace('/\D/', '', (string) ($fields['processing_code'] ?? '')) ?? '';
        if (strlen($raw) !== 6) {
            return null;
        }
        return $raw;
    }

    private static function limitedId(string $value, int $max): string
    {
        $clean = preg_replace('/[^A-Za-z0-9]/', '', $value) ?? '';
        if ($clean === '') {
            return '';
        }
        return strtoupper(substr($clean, 0, $max));
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function scrub(array $payload): array
    {
        $drop = [
            'packed', 'wire', 'iso_frame', 'raw', 'raw_message', 'pan', 'card_number', 'cc_number',
            'card_cvv', 'cc_cvv', 'cvv', 'cvv2', 'cvc', 'cvc2', 'cid', 'security_code',
            'track', 'track1', 'track2', 'track_data', 'magstripe', 'pin', 'pin_block',
        ];
        foreach ($drop as $key) {
            unset($payload[$key]);
        }
        if (function_exists('dp_pci_public')) {
            $clean = dp_pci_public($payload);
            if (is_array($clean)) {
                $payload = $clean;
            }
        }
        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private static function fail(string $message): array
    {
        return [
            'success' => false,
            'approved' => false,
            'route' => self::ROUTE,
            'processor' => self::ROUTE,
            'mti' => '',
            'response_code' => null,
            'rrn' => null,
            'approval_code' => null,
            'message' => $message,
        ];
    }
}
