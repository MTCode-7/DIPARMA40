<?php
/**
 * ISO 8583 Advice security — PIN Block (ISO 9564 Format 0) and MAC (DE 64 / 128).
 * Keys come from the environment. No sample PIN, PAN, or hardcoded master key.
 */
if (defined('DI_PARMA_ISO8583_SECURITY')) {
    return;
}
define('DI_PARMA_ISO8583_SECURITY', true);

class ISO8583SecurityHandler
{
    /**
     * ISO 9564-1 Format 0 PIN block (16 hex digits).
     * Encrypt under TPK/ZPK when POS_ISO_PIN_KEY is a 32-hex 3DES key.
     */
    public static function generatePinBlock(string $pin, string $pan): string
    {
        $pin = preg_replace('/\D/', '', $pin) ?? '';
        $panClean = preg_replace('/\D/', '', $pan) ?? '';
        $pinLen = strlen($pin);
        if ($pinLen < 4 || $pinLen > 12 || strlen($panClean) < 13) {
            return '';
        }
        if (self::isWeakPin($pin)) {
            return '';
        }

        $pinBlockPart1 = '0' . strtoupper(dechex($pinLen)) . $pin . str_repeat('F', 14 - $pinLen);
        $panPart2 = '0000' . substr($panClean, -13, 12);
        $pinBlockHex = '';
        for ($i = 0; $i < 16; $i++) {
            $pinBlockHex .= dechex(hexdec($pinBlockPart1[$i]) ^ hexdec($panPart2[$i]));
        }
        $clear = strtoupper($pinBlockHex);

        $tpk = self::pinKey();
        if ($tpk !== '') {
            $enc = self::encryptPinBlock($clear, $tpk);
            if ($enc !== '') {
                return $enc;
            }
        }
        return $clear;
    }

    public static function calculateMAC(string $rawMessageWithoutMac, string $secretKey = ''): string
    {
        $secretKey = $secretKey !== '' ? $secretKey : self::macKey();
        if ($secretKey === '' || $rawMessageWithoutMac === '') {
            return '';
        }
        $hash = hash_hmac('sha256', $rawMessageWithoutMac, $secretKey, true);
        return strtoupper(substr(bin2hex($hash), 0, 16));
    }

    public static function macKey(): string
    {
        return trim((string) (getenv('POS_ISO_MAC_KEY') ?: getenv('TERMINAL_MAC_KEY') ?: ''));
    }

    public static function pinKey(): string
    {
        return trim((string) (getenv('POS_ISO_PIN_KEY') ?: getenv('TERMINAL_PIN_KEY') ?: ''));
    }

    public static function isWeakPin(string $pin): bool
    {
        $pin = preg_replace('/\D/', '', $pin) ?? '';
        if ($pin === '') {
            return true;
        }
        if (preg_match('/^(\d)\1+$/', $pin)) {
            return true;
        }
        return in_array($pin, ['1234', '123456', '2580', '1212', '1122'], true);
    }

    private static function encryptPinBlock(string $clearHex, string $keyMaterial): string
    {
        $keyBin = ctype_xdigit($keyMaterial) && (strlen($keyMaterial) === 32 || strlen($keyMaterial) === 48)
            ? hex2bin($keyMaterial)
            : '';
        if ($keyBin === '' || strlen($keyBin) < 16) {
            return '';
        }
        if (strlen($keyBin) === 16) {
            $keyBin .= substr($keyBin, 0, 8);
        }
        $block = hex2bin($clearHex);
        if ($block === false || !function_exists('openssl_encrypt')) {
            return '';
        }
        $enc = openssl_encrypt($block, 'DES-EDE3', $keyBin, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
        if ($enc === false) {
            return '';
        }
        return strtoupper(bin2hex($enc));
    }
}
