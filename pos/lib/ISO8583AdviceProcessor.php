<?php
/**
 * ISO 8583 Advice packer/parser — MTI 0220/0230 (financial) and 0120/0130 (auth).
 * Primary/secondary bitmap, fixed DE, LLVAR, LLLVAR.
 * No sample PAN/TID and no simulated 0230.
 */
if (defined('DI_PARMA_ISO8583_ADVICE')) {
    return;
}
define('DI_PARMA_ISO8583_ADVICE', true);

class ISO8583AdviceProcessor
{
    /** @var int[] */
    private static $llvarFields = [2, 32, 33, 35, 43, 48];

    /** @var int[] */
    private static $lllvarFields = [54, 55, 56, 59, 60, 61, 62, 63];

    /** @var array<int,int> */
    private static $fixedLengths = [
        3 => 6,
        4 => 12,
        7 => 10,
        11 => 6,
        12 => 6,
        13 => 4,
        14 => 4,
        18 => 4,
        22 => 3,
        25 => 2,
        37 => 12,
        38 => 6,
        39 => 2,
        41 => 8,
        42 => 15,
        49 => 3,
        52 => 16,
        64 => 16,
        128 => 16,
    ];

    public static function hexToBinaryBitmap(string $hexBitmap): string
    {
        $hexBitmap = strtoupper(preg_replace('/[^0-9A-F]/', '', $hexBitmap) ?? '');
        $binary = '';
        $len = strlen($hexBitmap);
        for ($i = 0; $i < $len; $i++) {
            $binary .= str_pad(decbin(hexdec($hexBitmap[$i])), 4, '0', STR_PAD_LEFT);
        }
        return $binary;
    }

    public static function binaryToHexBitmap(string $binaryBitmap): string
    {
        $hex = '';
        $pad = (int) (ceil(strlen($binaryBitmap) / 4) * 4);
        $paddedBinary = str_pad($binaryBitmap, $pad, '0', STR_PAD_RIGHT);
        $len = strlen($paddedBinary);
        for ($i = 0; $i < $len; $i += 4) {
            $hex .= dechex(bindec(substr($paddedBinary, $i, 4)));
        }
        return strtoupper($hex);
    }

    public static function buildISOFrame(string $mti, array $fields): string
    {
        $mti = str_pad(substr(preg_replace('/\D/', '', $mti) ?? '0220', 0, 4), 4, '0', STR_PAD_LEFT);
        ksort($fields, SORT_NUMERIC);

        $needSecondary = false;
        foreach ($fields as $bitNum => $value) {
            if ((int) $bitNum > 64) {
                $needSecondary = true;
                break;
            }
        }

        $primary = array_fill(1, 64, '0');
        $secondary = array_fill(1, 64, '0');
        if ($needSecondary) {
            $primary[1] = '1';
        }

        $fieldsDataString = '';
        foreach ($fields as $bitNum => $value) {
            $bitNum = (int) $bitNum;
            if ($bitNum < 2) {
                continue;
            }
            $value = (string) $value;
            if ($bitNum <= 64) {
                $primary[$bitNum] = '1';
            } elseif ($bitNum <= 128) {
                $secondary[$bitNum - 64] = '1';
            } else {
                continue;
            }
            $fieldsDataString .= self::formatFieldData($bitNum, $value);
        }

        $hexBitmap = self::binaryToHexBitmap(implode('', $primary));
        if ($needSecondary) {
            $hexBitmap .= self::binaryToHexBitmap(implode('', $secondary));
        }

        return $mti . $hexBitmap . $fieldsDataString;
    }

    public static function parseISOFrame(string $rawFrame): array
    {
        $rawFrame = trim($rawFrame);
        if (strlen($rawFrame) < 20) {
            return ['mti' => '', 'bitmap_hex' => '', 'error' => 'Frame too short'];
        }

        $mti = substr($rawFrame, 0, 4);
        $hexPrimary = substr($rawFrame, 4, 16);
        $binaryPrimary = self::hexToBinaryBitmap($hexPrimary);
        $offset = 20;
        $hexBitmap = $hexPrimary;
        $binaryFull = $binaryPrimary;

        if (isset($binaryPrimary[0]) && $binaryPrimary[0] === '1') {
            $hexSecondary = substr($rawFrame, 20, 16);
            $hexBitmap .= $hexSecondary;
            $binaryFull .= self::hexToBinaryBitmap($hexSecondary);
            $offset = 36;
        }

        $parsedFields = [
            'mti' => $mti,
            'bitmap_hex' => $hexBitmap,
        ];

        $bitCount = strlen($binaryFull);
        for ($i = 0; $i < $bitCount; $i++) {
            if ($binaryFull[$i] !== '1') {
                continue;
            }
            $bitNum = $i + 1;
            if ($bitNum === 1) {
                continue;
            }
            if (isset(self::$fixedLengths[$bitNum])) {
                $len = self::$fixedLengths[$bitNum];
                $parsedFields[$bitNum] = substr($rawFrame, $offset, $len);
                $offset += $len;
                continue;
            }
            if (in_array($bitNum, self::$llvarFields, true)) {
                $len = (int) substr($rawFrame, $offset, 2);
                $offset += 2;
                $parsedFields[$bitNum] = substr($rawFrame, $offset, $len);
                $offset += $len;
                continue;
            }
            if (in_array($bitNum, self::$lllvarFields, true)) {
                $len = (int) substr($rawFrame, $offset, 3);
                $offset += 3;
                $parsedFields[$bitNum] = substr($rawFrame, $offset, $len);
                $offset += $len;
            }
        }

        if (isset($parsedFields[2]) && $parsedFields[2] !== '') {
            $parsedFields['2_pan_masked'] = self::maskPan((string) $parsedFields[2]);
        }

        return $parsedFields;
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

    private static function formatFieldData(int $bitNum, string $value): string
    {
        if (isset(self::$fixedLengths[$bitNum])) {
            $len = self::$fixedLengths[$bitNum];
            if ($bitNum === 41 || $bitNum === 42 || $bitNum === 37 || $bitNum === 38) {
                return str_pad(substr($value, 0, $len), $len, ' ', STR_PAD_RIGHT);
            }
            return str_pad(substr($value, 0, $len), $len, '0', STR_PAD_LEFT);
        }
        if (in_array($bitNum, self::$llvarFields, true)) {
            $length = strlen($value);
            return str_pad((string) $length, 2, '0', STR_PAD_LEFT) . $value;
        }
        if (in_array($bitNum, self::$lllvarFields, true)) {
            $length = strlen($value);
            return str_pad((string) $length, 3, '0', STR_PAD_LEFT) . $value;
        }
        return $value;
    }
}
