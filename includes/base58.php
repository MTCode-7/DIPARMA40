<?php
/**
 * Base58 encode/decode without requiring the GMP extension (XAMPP often lacks it).
 */
if (!defined('DP_BASE58_ALPHABET')) {
    define('DP_BASE58_ALPHABET', '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz');
}

function dp_base58_encode(string $data): string
{
    $alphabet = DP_BASE58_ALPHABET;
    if ($data === '') {
        return '';
    }

    if (function_exists('gmp_import') && function_exists('gmp_div_qr')) {
        $num = gmp_import($data);
        $encoded = '';
        $base = gmp_init(58);
        while (gmp_cmp($num, 0) > 0) {
            [$num, $rem] = gmp_div_qr($num, $base);
            $encoded = $alphabet[gmp_intval($rem)] . $encoded;
        }
        foreach (str_split($data) as $byte) {
            if ($byte === "\x00") {
                $encoded = '1' . $encoded;
            } else {
                break;
            }
        }
        return $encoded;
    }

    $bytes = array_values(unpack('C*', $data) ?: []);
    $digits = [0];
    foreach ($bytes as $byte) {
        $carry = (int) $byte;
        for ($i = 0, $n = count($digits); $i < $n || $carry > 0; $i++) {
            $carry += ($digits[$i] ?? 0) * 256;
            $digits[$i] = $carry % 58;
            $carry = intdiv($carry, 58);
        }
    }

    $encoded = '';
    for ($i = count($digits) - 1; $i >= 0; $i--) {
        $encoded .= $alphabet[$digits[$i]];
    }
    foreach ($bytes as $byte) {
        if ($byte === 0) {
            $encoded = '1' . $encoded;
        } else {
            break;
        }
    }
    return $encoded;
}

function dp_base58_decode(string $input): string
{
    $alphabet = DP_BASE58_ALPHABET;
    if ($input === '') {
        return '';
    }

    $leadOnes = 0;
    $len = strlen($input);
    for ($i = 0; $i < $len && $input[$i] === '1'; $i++) {
        $leadOnes++;
    }

    if (function_exists('gmp_init') && function_exists('gmp_export')) {
        $num = gmp_init(0);
        $base = gmp_init(58);
        for ($i = 0; $i < $len; $i++) {
            $pos = strpos($alphabet, $input[$i]);
            if ($pos === false) {
                throw new InvalidArgumentException('Invalid Base58 character');
            }
            $num = gmp_add(gmp_mul($num, $base), gmp_init($pos));
        }
        $exported = gmp_cmp($num, 0) === 0 ? '' : gmp_export($num);
        return str_repeat("\x00", $leadOnes) . $exported;
    }

    $digits = [0];
    for ($i = 0; $i < $len; $i++) {
        $pos = strpos($alphabet, $input[$i]);
        if ($pos === false) {
            throw new InvalidArgumentException('Invalid Base58 character');
        }
        $carry = $pos;
        for ($j = 0, $n = count($digits); $j < $n || $carry > 0; $j++) {
            $carry += ($digits[$j] ?? 0) * 58;
            $digits[$j] = $carry % 256;
            $carry = intdiv($carry, 256);
        }
    }

    $bytes = '';
    for ($i = count($digits) - 1; $i >= 0; $i--) {
        $bytes .= chr($digits[$i]);
    }
    $bytes = ltrim($bytes, "\x00");
    return str_repeat("\x00", $leadOnes) . $bytes;
}

function dp_tron_address_payload(string $address): string
{
    $bin = dp_base58_decode($address);
    if (strlen($bin) !== 25) {
        throw new InvalidArgumentException('Invalid Tron address');
    }
    $payload = substr($bin, 0, 21);
    $checksum = substr($bin, 21, 4);
    $hash = hash('sha256', hash('sha256', $payload, true), true);
    if (!hash_equals($checksum, substr($hash, 0, 4))) {
        throw new InvalidArgumentException('Invalid Tron address checksum');
    }
    if ($payload[0] !== "\x41") {
        throw new InvalidArgumentException('Invalid Tron address version');
    }
    return $payload;
}

function dp_tron_address_hex(string $address): string
{
    return bin2hex(dp_tron_address_payload($address));
}

function dp_tron_address_abi(string $address): string
{
    return str_pad(bin2hex(substr(dp_tron_address_payload($address), 1)), 64, '0', STR_PAD_LEFT);
}

function dp_tron_base58_to_hex(string $address): string
{
    $bin = dp_base58_decode($address);
    $hex = bin2hex($bin);
    if (strlen($hex) < 10) {
        throw new InvalidArgumentException('Invalid Tron address');
    }
    $addressHex = substr($hex, 2, strlen($hex) - 10);
    return str_pad($addressHex, 64, '0', STR_PAD_LEFT);
}
