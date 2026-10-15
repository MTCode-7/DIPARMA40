<?php
/**
 * DIPARMA ISO 8583:1987 path (route diparma_iso).
 *
 * Builds a packed message and a PCI-safe record for this processor only.
 * PayPal, Stripe, Square, and Nuvei never enter this class.
 *
 * Scheme marker: DE 48 (additional data, private, LLVAR). The packer in
 * pos/lib/ISO8583AdviceProcessor.php already emits DE 48. Visa is VISN.
 * Mastercard is MCRD. No other brand is accepted on this path.
 *
 * A response code is taken only from a DIPARMA host that already accepts an
 * ISO frame (DIPARMA_ISO_URL, DIPARMA_ISO_HOST, BANK_ADVICE_HOST, or
 * DIRECT_ADVICE_HOST). /api/v1 has no ISO field, so the frame is not posted
 * there. This class does not invent a Visa or Mastercard URL and does not
 * approve a message locally.
 *
 * Catalog purchases that ISO 8583:1987 does not name keep MTI 0200 and use a
 * distinct processing code: quasi-cash 110000, wire 400000, recurring 500000,
 * installment 510000, gift card 520000, crypto purchase 530000.
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

    /** @var array<string,string> */
    private const TYPE_ALIASES = [
        'purchase' => 'purchase_3d',
        'purchase_online' => 'online_sale_moto',
        'purchase_offline' => 'offline_sale_moto',
        'online_moto' => 'online_sale_moto',
        'offline_moto' => 'offline_sale_moto',
        'offline_sales_moto' => 'offline_sale_moto',
        'auth_complete' => 'capture',
        'auth_capture' => 'capture',
        'auth_hold' => 'auth',
        'auth_moto' => 'auth',
        'hold' => 'auth',
        'authorize' => 'auth',
        'purchase_moto' => 'purchase_2d',
        'moto' => 'purchase_2d',
        'offline_purchase' => 'offline_sale_moto',
        'online_purchase' => 'online_sale_moto',
        'direct2d' => 'purchase_2d',
        'direct3d' => 'purchase_3d',
        'withdrawal_physical' => 'withdrawal_pos',
        'withdrawal_manual' => 'offline_sale_moto',
        'cash_advance' => 'withdrawal_pos',
        'void' => 'avoid',
        'reversal' => 'avoid',
        'cancel' => 'avoid',
        'purchase_advice_offline' => 'purchase_advice',
        'purchase_advice_online' => 'purchase_advice',
    ];

    public static function isRoute(string $gateway): bool
    {
        $g = strtolower(trim($gateway));
        return in_array($g, [self::ROUTE, 'diparma-iso', 'diparma', 'di_parma', 'di-parma'], true);
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
        $pan = self::panOf($fields);
        $brand = self::identifyBrand($pan);
        if (isset($brand['error'])) {
            return $brand['error'];
        }

        $spec = self::spec($txnType, $fields);
        if (isset($spec['error'])) {
            return $spec['error'];
        }

        $iso = self::assignFields($spec, $fields, $brand);
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
            'brand' => $brand['brand'],
            'scheme' => $brand['scheme'],
            'bitmap' => $bitmap,
            'secondary_bitmap' => strlen($bitmap) > 16,
            'fields' => self::maskedFields($iso),
            'packed' => $packed,
            'record' => self::safeRecord($spec['mti'], $iso, null, $brand),
        ];
    }

    /**
     * Orchestrator entry. Sends on the configured DIPARMA host when one exists.
     * Returns the built message without PAN, CVV, track, or the raw frame.
     *
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    public static function configuredHostValue(): string
    {
        return self::configuredHost();
    }

    public static function handle(string $txnType, array $fields): array
    {
        if (self::configuredHost() === '') {
            return self::scrub(self::fail('DI PARMA ISO host is not configured. The charge was not sent.'));
        }

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
            'brand' => (string) ($built['brand'] ?? ''),
            'scheme' => (string) ($built['scheme'] ?? ''),
            'bitmap' => (string) ($built['bitmap'] ?? ''),
            'secondary_bitmap' => !empty($built['secondary_bitmap']),
            'fields' => is_array($built['fields'] ?? null) ? $built['fields'] : [],
            'wire_length' => strlen((string) ($built['packed'] ?? '')),
            'message' => 'ISO 8583 message built on diparma_iso. No DIPARMA host URL is configured, so it was not sent.',
            'response_code' => null,
            'rrn' => $record['rrn'] ?? null,
            'approval_code' => $record['approval_code'] ?? null,
            'card_last4' => $record['card_last4'] ?? null,
            'card_mask' => $record['card_mask'] ?? null,
            'transport' => 'not_configured',
            'record' => $record,
            'decline_stored' => false,
        ];

        $host = self::configuredHost();
        if ($host === '') {
            self::storeDecline($public, $fields);
            unset($built);
            return self::scrub($public);
        }

        $sent = self::transmit($host, (string) ($built['packed'] ?? ''), $public);
        unset($built);
        $public['transport'] = !empty($sent['reachable']) ? 'sent' : 'unreachable';
        $code = isset($sent['response_code']) && $sent['response_code'] !== ''
            ? (string) $sent['response_code']
            : null;
        if (empty($sent['reachable'])) {
            $code = '91';
            $public['message'] = 'DIPARMA host unreachable. Stored as a decline.';
        } else {
            $public['message'] = (string) ($sent['message'] ?? 'DIPARMA host responded');
            if (!empty($sent['rrn'])) {
                $public['rrn'] = (string) $sent['rrn'];
            }
            if (!empty($sent['approval_code'])) {
                $public['approval_code'] = (string) $sent['approval_code'];
            }
            if (!empty($sent['mti'])) {
                $public['response_mti'] = (string) $sent['mti'];
            }
        }
        $public['response_code'] = $code;
        $public['record']['response_code'] = $code;
        $public['record']['rrn'] = $public['rrn'];
        $public['record']['approval_code'] = $public['approval_code'];
        $approved = !empty($sent['reachable']) && $code === '00';
        $public['approved'] = $approved;
        $public['success'] = $approved;
        if (!$approved) {
            self::storeDecline($public, $fields);
        }

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
        $accepted = ['0110', '0210', '0230', '0410', '0430', '0510'];
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
        if (isset($parsed[48])) {
            $marker = strtoupper(trim((string) $parsed[48]));
            if ($marker === 'VISN' || $marker === 'MCRD') {
                $out['scheme'] = $marker;
                $out['brand'] = $marker === 'VISN' ? 'visa' : 'mastercard';
            }
        }

        return self::scrub($out);
    }

    /**
     * @param array<string,string> $iso
     * @param array{brand:string,scheme:string}|null $brand
     * @return array<string,mixed>
     */
    public static function safeRecord(string $mti, array $iso, ?string $responseCode, ?array $brand = null): array
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
        $scheme = strtoupper(trim((string) ($iso[48] ?? ($brand['scheme'] ?? ''))));

        return [
            'card_mask' => $last4 !== '' ? '****' . $last4 : null,
            'card_last4' => $last4 !== '' ? $last4 : null,
            'brand' => $scheme === 'VISN' ? 'visa' : ($scheme === 'MCRD' ? 'mastercard' : ($brand['brand'] ?? null)),
            'scheme' => $scheme !== '' ? $scheme : null,
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
            if ($bit < 2 || in_array($bit, [35, 45, 52, 55], true)) {
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
     * @return array{brand:string,scheme:string}|array{error:array<string,mixed>}
     */
    private static function identifyBrand(string $pan): array
    {
        if (strlen($pan) < 13 || strlen($pan) > 19) {
            return ['error' => self::fail('Visa or Mastercard PAN is required on diparma_iso')];
        }
        if (str_starts_with($pan, '4')) {
            return ['brand' => 'visa', 'scheme' => 'VISN'];
        }
        $two = (int) substr($pan, 0, 2);
        $four = (int) substr($pan, 0, 4);
        if (($two >= 51 && $two <= 55) || ($four >= 2221 && $four <= 2720)) {
            return ['brand' => 'mastercard', 'scheme' => 'MCRD'];
        }

        return ['error' => self::fail('Unsupported card brand on diparma_iso. Only Visa and Mastercard are accepted.')];
    }

    /**
     * @param array<string,mixed> $fields
     * @return array{mti:string,role:string,txn_type:string,processing:string,entry:string,condition:string,amount:float}|array{error:array<string,mixed>}
     */
    private static function spec(string $txnType, array &$fields): array
    {
        $raw = strtolower(trim($txnType !== '' ? $txnType : (string) ($fields['txn_type'] ?? '')));
        $type = self::TYPE_ALIASES[$raw] ?? $raw;
        self::applyAliasEntry($raw, $type, $fields);
        $amount = self::amountOf($fields);
        $entry = self::entryMode($type, $fields, $raw);

        $auth = ['auth'];
        $purchase = ['purchase_3d', 'purchase_2d', 'online_sale_moto'];
        $catalogPurchase = [
            'quasi_cash' => '110000',
            'wire_transfer' => '400000',
            'recurring' => '500000',
            'installment' => '510000',
            'gift_card' => '520000',
            'crypto_purchase' => '530000',
        ];
        $offline = ['offline_sale_moto'];
        $capture = ['capture'];
        $reversal = ['avoid'];
        $cash = ['withdrawal_pos', 'withdrawal_nfc'];

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
            if ($amount == 0.0) {
                return ['error' => self::fail('Offline sale amount must not be zero')];
            }
            return self::row($type, '0220', 'offline', '000000', $entry, abs($amount));
        }
        if ($type === 'purchase_advice') {
            if ($amount == 0.0) {
                return ['error' => self::fail('Purchase advice amount must not be zero')];
            }
            return self::row($type, '0220', 'advice_debit', '000000', $entry, abs($amount));
        }
        if (in_array($type, $capture, true)) {
            if ($amount == 0.0) {
                return ['error' => self::fail('Capture amount must not be zero')];
            }
            return self::row($type, '0220', 'completion', '000000', $entry, abs($amount));
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
        if (isset($catalogPurchase[$type])) {
            if ($amount <= 0) {
                return ['error' => self::fail('Amount must be greater than zero')];
            }
            return self::row($type, '0200', $type, $catalogPurchase[$type], $entry, $amount);
        }
        if (in_array($type, $cash, true)) {
            if ($amount <= 0) {
                return ['error' => self::fail('Cash advance amount must be greater than zero')];
            }
            return self::row($type, '0200', 'cash_advance', '010000', $entry, $amount);
        }
        if ($type === 'balance') {
            return self::row($type, '0100', 'balance', '300000', $entry, $amount);
        }
        if ($type === 'settlement') {
            return self::row($type, '0500', 'settlement', '920000', $entry, $amount);
        }

        return ['error' => self::fail('Unsupported diparma_iso transaction type')];
    }

    /**
     * @param array<string,mixed> $fields
     */
    private static function applyAliasEntry(string $raw, string $type, array &$fields): void
    {
        $hint = strtolower(trim((string) (
            $fields['entry_mode']
            ?? $fields['pos_entry_mode']
            ?? $fields['channel']
            ?? $fields['auth_channel']
            ?? $fields['moto_channel']
            ?? ''
        )));
        if ($hint !== '') {
            return;
        }
        if ($raw === 'purchase_advice_offline' || ($type === 'purchase_advice' && str_contains($raw, 'offline'))) {
            $fields['entry_mode'] = 'offline';
        } elseif ($raw === 'purchase_advice_online' || ($type === 'purchase_advice' && str_contains($raw, 'online'))) {
            $fields['entry_mode'] = 'manual';
        }
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
     * @param array{brand:string,scheme:string} $brand
     * @return array<int,string>
     */
    private static function assignFields(array $spec, array $fields, array $brand): array
    {
        $iso = [];
        $pan = self::panOf($fields);
        if (strlen($pan) >= 13 && strlen($pan) <= 19) {
            $iso[2] = $pan;
        }

        $processing = self::processingOverride($fields) ?? (string) $spec['processing'];
        $iso[3] = $processing;

        $currency = strtoupper(trim((string) ($fields['currency'] ?? 'USD')));
        if ($spec['role'] !== 'reversal' || self::amountOf($fields) != 0.0) {
            if ($spec['role'] !== 'balance' && $spec['role'] !== 'settlement' || self::amountOf($fields) != 0.0) {
                $iso[4] = self::minorUnits(abs((float) $spec['amount']), $currency);
            }
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
        $iso[48] = (string) $brand['scheme'];
        if (isset(self::CURRENCY_NUMERIC[$currency])) {
            $iso[49] = self::CURRENCY_NUMERIC[$currency];
        }
        $plan = (int) ($fields['installments'] ?? $fields['installment_count'] ?? 0);
        if ($spec['txn_type'] === 'installment' && $plan >= 2 && $plan <= 99) {
            $iso[63] = 'INST' . str_pad((string) $plan, 2, '0', STR_PAD_LEFT);
        }

        ksort($iso, SORT_NUMERIC);
        return $iso;
    }

    /**
     * @param array<string,mixed> $fields
     * @return array{de22:string,de25:string}
     */
    private static function entryMode(string $txnType, array $fields, string $raw = ''): array
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
        $motoTypes = [
            'purchase_2d', 'online_sale_moto', 'auth_moto', 'purchase_online', 'purchase_moto', 'moto',
            'recurring', 'installment', 'quasi_cash', 'gift_card', 'crypto_purchase',
        ];

        if (in_array($hint, $contactless, true) || $txnType === 'withdrawal_nfc' || $raw === 'withdrawal_nfc') {
            return ['de22' => '071', 'de25' => '00'];
        }
        if (in_array($hint, $ecom, true) || $txnType === 'purchase_3d' || $raw === 'purchase_3d' || $raw === 'direct3d' || $mode === '3D') {
            return ['de22' => '812', 'de25' => '59'];
        }
        if (
            in_array($hint, $offline, true)
            || $txnType === 'offline_sale_moto'
            || in_array($raw, ['offline_sale_moto', 'purchase_offline', 'offline_moto', 'purchase_advice_offline', 'withdrawal_manual'], true)
        ) {
            return ['de22' => '012', 'de25' => '01'];
        }
        if (in_array($hint, $chip, true) || $txnType === 'withdrawal_pos' || in_array($raw, ['withdrawal_pos', 'cash_advance', 'withdrawal_physical'], true)) {
            return ['de22' => '051', 'de25' => '00'];
        }
        if ($txnType === 'capture') {
            return ['de22' => $moto ? '012' : '051', 'de25' => '06'];
        }
        if (in_array($hint, $manual, true) || $moto || in_array($txnType, $motoTypes, true) || in_array($raw, $motoTypes, true)) {
            return ['de22' => '012', 'de25' => '08'];
        }
        if ($txnType === 'auth') {
            return ['de22' => '051', 'de25' => '00'];
        }
        if ($txnType === 'wire_transfer') {
            return ['de22' => '010', 'de25' => '00'];
        }

        return ['de22' => '012', 'de25' => '00'];
    }

    /**
     * @param array<string,mixed> $fields
     */
    private static function panOf(array $fields): string
    {
        return preg_replace('/\D/', '', (string) ($fields['pan'] ?? $fields['card_number'] ?? $fields['cc_number'] ?? '')) ?? '';
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
        $digits = preg_replace('/\D/', '', (string) ($fields['rrn'] ?? $fields['de37'] ?? $fields['orig_ref'] ?? $fields['bank_rrn'] ?? '')) ?? '';
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

    private static function configuredHost(): string
    {
        foreach (['DIPARMA_ISO_URL', 'DIPARMA_ISO_HOST', 'BANK_ADVICE_HOST', 'DIRECT_ADVICE_HOST'] as $key) {
            $url = trim((string) getenv($key));
            if ($url !== '' && !self::rejectedHost($url)) {
                return $url;
            }
        }
        return self::hostFromDatabase();
    }

    private static function rejectedHost(string $url): bool
    {
        $host = strtolower($url);
        if (str_contains($host, '/api/v1')) {
            return true;
        }
        foreach (['nuvei', 'stripe', 'paypal', 'square', 'adyen', 'klarna', 'wise.com'] as $name) {
            if (str_contains($host, $name)) {
                return true;
            }
        }
        return false;
    }

    private static function hostFromDatabase(): string
    {
        if (!function_exists('db')) {
            return '';
        }
        try {
            $row = db()->find('payment_gateways', ['code' => 'diparma']);
        } catch (Throwable $e) {
            return '';
        }
        if (!is_array($row)) {
            return '';
        }
        $bags = [];
        foreach (['settings', 'credentials', 'config'] as $key) {
            $value = $row[$key] ?? [];
            if (is_string($value)) {
                $value = json_decode($value, true);
            }
            if (is_array($value)) {
                $bags[] = $value;
            }
        }
        foreach ($bags as $bag) {
            foreach (['iso_url', 'iso_host', 'advice_host'] as $key) {
                $url = trim((string) ($bag[$key] ?? ''));
                if ($url !== '' && !self::rejectedHost($url)) {
                    return $url;
                }
            }
        }
        return '';
    }

    /**
     * @param array<string,mixed> $public
     * @return array<string,mixed>
     */
    private static function transmit(string $host, string $packed, array $public): array
    {
        if (preg_match('#^https?://#i', $host)) {
            return self::transmitHttp($host, $packed, $public);
        }
        if (preg_match('/^([A-Za-z0-9._-]+):(\d{2,5})$/', $host, $match)) {
            return self::transmitSocket($match[1], (int) $match[2], $packed);
        }

        return [
            'reachable' => false,
            'response_code' => '91',
            'message' => 'DIPARMA host is not a URL or host:port',
        ];
    }

    /**
     * @param array<string,mixed> $public
     * @return array<string,mixed>
     */
    private static function transmitHttp(string $host, string $packed, array $public): array
    {
        if (!function_exists('curl_init')) {
            return [
                'reachable' => false,
                'response_code' => '91',
                'message' => 'DIPARMA host unreachable. HTTP client is not available.',
            ];
        }
        $body = [
            'iso_frame' => $packed,
            'mti' => (string) ($public['mti'] ?? ''),
            'txn_type' => (string) ($public['txn_type'] ?? ''),
            'brand' => (string) ($public['brand'] ?? ''),
            'scheme' => (string) ($public['scheme'] ?? ''),
            'rrn' => $public['rrn'] ?? null,
            'approval_code' => $public['approval_code'] ?? null,
            'card_last4' => $public['card_last4'] ?? null,
        ];
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return [
                'reachable' => false,
                'response_code' => '96',
                'message' => 'DIPARMA host payload could not be encoded',
            ];
        }
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json, text/plain',
            'X-MTI: ' . (string) ($public['mti'] ?? ''),
        ];
        $apiKey = trim((string) getenv('DIPARMA_API_KEY'));
        $apiSecret = trim((string) getenv('DIPARMA_API_SECRET'));
        if ($apiKey !== '' && $apiSecret !== '') {
            $timestamp = (string) time();
            $reference = (string) ($public['rrn'] ?? $public['txn_type'] ?? '');
            $headers[] = 'X-Api-Key: ' . $apiKey;
            $headers[] = 'X-Timestamp: ' . $timestamp;
            $headers[] = 'X-Signature: ' . hash_hmac('sha256', $timestamp . '.' . $reference . '.' . (string) ($public['mti'] ?? ''), $apiSecret);
        }
        $ch = curl_init(rtrim($host, '/'));
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false || $curlError !== '' || $status === 0) {
            return [
                'reachable' => false,
                'response_code' => '91',
                'message' => 'DIPARMA host unreachable.',
            ];
        }

        return self::interpretHostBody((string) $response, $status);
    }

    /**
     * @return array<string,mixed>
     */
    private static function transmitSocket(string $host, int $port, string $packed): array
    {
        $errno = 0;
        $err = '';
        $socket = @fsockopen($host, $port, $errno, $err, 8);
        if (!is_resource($socket)) {
            return [
                'reachable' => false,
                'response_code' => '91',
                'message' => 'DIPARMA host unreachable.',
            ];
        }
        stream_set_timeout($socket, 12);
        $written = fwrite($socket, $packed);
        if ($written === false) {
            fclose($socket);
            return [
                'reachable' => false,
                'response_code' => '91',
                'message' => 'DIPARMA host unreachable.',
            ];
        }
        $raw = stream_get_contents($socket, 8192);
        fclose($socket);
        if (!is_string($raw) || $raw === '') {
            return [
                'reachable' => true,
                'response_code' => '91',
                'approved' => false,
                'message' => 'DIPARMA host returned an empty response',
            ];
        }

        return self::interpretHostBody($raw, 200);
    }

    /**
     * @return array<string,mixed>
     */
    private static function interpretHostBody(string $body, int $http): array
    {
        $body = trim($body);
        if ($body === '') {
            return [
                'reachable' => true,
                'approved' => false,
                'response_code' => '91',
                'message' => 'DIPARMA host returned an empty response',
            ];
        }
        if (preg_match('/^(0110|0210|0230|0410|0430|0510)/', $body)) {
            $parsed = self::parseResponse($body);
            $code = $parsed['response_code'] ?? null;
            $approved = $http >= 200 && $http < 300 && !empty($parsed['approved']) && $code === '00';
            return [
                'reachable' => true,
                'approved' => $approved,
                'response_code' => $approved ? '00' : ($code !== null && $code !== '00' ? $code : '05'),
                'rrn' => $parsed['rrn'] ?? null,
                'approval_code' => $parsed['approval_code'] ?? null,
                'mti' => $parsed['mti'] ?? '',
                'message' => (string) ($parsed['message'] ?? 'DIPARMA host responded'),
            ];
        }
        $json = json_decode($body, true);
        if (is_array($json)) {
            $frame = $json['iso_frame'] ?? $json['iso8583'] ?? '';
            if (is_string($frame) && preg_match('/^(0110|0210|0230|0410|0430|0510)/', $frame)) {
                return self::interpretHostBody($frame, $http);
            }
            $code = trim((string) ($json['response_code'] ?? $json['de39'] ?? ''));
            $approved = $http >= 200 && $http < 300 && $code === '00';
            if (!$approved && ($code === '' || $code === '00')) {
                $code = '05';
            }
            $message = trim((string) ($json['message'] ?? ''));
            return [
                'reachable' => true,
                'approved' => $approved,
                'response_code' => $code,
                'rrn' => trim((string) ($json['rrn'] ?? '')) ?: null,
                'approval_code' => trim((string) ($json['approval_code'] ?? $json['auth_code'] ?? '')) ?: null,
                'mti' => trim((string) ($json['mti'] ?? '')),
                'message' => $message !== '' ? $message : 'DIPARMA host declined the ISO message',
            ];
        }

        return [
            'reachable' => true,
            'approved' => false,
            'response_code' => '96',
            'message' => 'DIPARMA host returned an unreadable response',
        ];
    }

    /**
     * @param array<string,mixed> $public
     * @param array<string,mixed> $fields
     */
    private static function storeDecline(array &$public, array $fields): void
    {
        if (!function_exists('diparma_record_declined_attempt') || !function_exists('db')) {
            return;
        }
        try {
            $db = db();
            diparma_record_declined_attempt($db, [
                'reference' => trim((string) ($fields['reference'] ?? '')),
                'user_id' => $fields['user_id'] ?? null,
                'gateway' => self::ROUTE,
                'transaction_type' => (string) ($public['txn_type'] ?? ''),
                'amount' => self::amountOf($fields),
                'currency' => strtoupper(trim((string) ($fields['currency'] ?? 'USD'))),
                'reason' => (string) ($public['message'] ?? 'Declined'),
                'card_last4' => (string) ($public['card_last4'] ?? ''),
                'details' => [
                    'brand' => $public['brand'] ?? null,
                    'scheme' => $public['scheme'] ?? null,
                    'mti' => $public['mti'] ?? null,
                    'response_code' => $public['response_code'] ?? null,
                    'rrn' => $public['rrn'] ?? null,
                    'approval_code' => $public['approval_code'] ?? null,
                    'transport' => $public['transport'] ?? null,
                ],
            ]);
            $public['decline_stored'] = true;
        } catch (Throwable $e) {
            error_log('[diparma_iso] decline store: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function scrub(array $payload): array
    {
        $drop = [
            'packed', 'wire', 'iso_frame', 'iso8583', 'raw', 'raw_message', 'pan', 'card_number', 'cc_number',
            'card_cvv', 'cc_cvv', 'cvv', 'cvv2', 'cvc', 'cvc2', 'cid', 'security_code',
            'track', 'track1', 'track2', 'track_data', 'magstripe', 'pin', 'pin_block',
        ];
        foreach ($drop as $key) {
            unset($payload[$key]);
        }
        if (isset($payload['record']) && is_array($payload['record'])) {
            foreach ($drop as $key) {
                unset($payload['record'][$key]);
            }
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
